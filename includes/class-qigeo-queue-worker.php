<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 队列调度与异步工作者管理
 * 统一管理 wp_qigeo_jobs 表、定时任务、异步 Worker 与外部 REST 触发
 */
class QiGeo_Queue_Worker {

    const CRON_HOOK                = 'qigeo_process_queue';
    const AJAX_WORKER_ACTION       = 'qigeo_process_queue_async';
    const REST_NAMESPACE           = 'qigeo/v1';
    const REST_ROUTE_TRIGGER       = '/queue/trigger';
    const WORKER_TOKEN_OPTION      = 'qigeo_worker_token';
    const LOCK_TRANSIENT_KEY       = 'qigeo_queue_lock';
    const LOCK_TTL                 = 120;
    const PROCESSING_STALE_SECONDS = 900;

    /**
     * 初始化钩子与调度
     *
     * @return void
     */
    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue_worker' ) );
        add_action( 'wp_ajax_' . self::AJAX_WORKER_ACTION, array( __CLASS__, 'ajax_process_queue_worker' ) );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_WORKER_ACTION, array( __CLASS__, 'ajax_process_queue_worker' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

        if ( did_action( 'init' ) ) {
            self::bootstrap_worker();
        } else {
            add_action( 'init', array( __CLASS__, 'bootstrap_worker' ), 20 );
        }
    }

    /**
     * 队列表名
     *
     * @return string
     */
    public static function get_jobs_table() {
        global $wpdb;
        return $wpdb->prefix . 'qigeo_jobs';
    }

    /**
     * 注册定时任务频率（每分钟）
     *
     * @param array<string,array<string,mixed>> $schedules schedules
     * @return array<string,array<string,mixed>>
     */
    public static function register_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['qigeo_every_minute'] ) ) {
            $schedules['qigeo_every_minute'] = array(
                'interval' => 60,
                'display'  => '每分钟（启灵GEO）',
            );
        }
        return $schedules;
    }

    /**
     * 任务入队
     *
     * @param int    $post_id 文章ID
     * @param string $trigger_type 触发类型 auto|manual
     * @param bool   $force_run 是否忽略内容 hash 强制重新分析
     * @return array<string,mixed>|WP_Error
     */
    public static function enqueue_job( $post_id, $trigger_type = 'auto', $force_run = false ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return new WP_Error( 'qigeo_invalid_post', '文章 ID 无效' );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return new WP_Error( 'qigeo_post_missing', '文章不存在' );
        }

        $jobs_table = self::get_jobs_table();
        $trigger_type = in_array( $trigger_type, array( 'auto', 'manual' ), true ) ? $trigger_type : 'auto';
        $force_run = (bool) $force_run;
        $dedupe_key = md5( $post_id . '|' . ( $force_run ? '1' : '0' ) );

        $existing_job_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$jobs_table} WHERE post_id = %d AND status IN ('pending','processing') ORDER BY id DESC LIMIT 1",
                $post_id
            )
        );
        if ( $existing_job_id > 0 ) {
            self::schedule_worker( true );
            return array(
                'success' => true,
                'queued'  => false,
                'job_id'  => $existing_job_id,
            );
        }

        $ok = $wpdb->insert(
            $jobs_table,
            array(
                'post_id'      => $post_id,
                'trigger_type' => $trigger_type,
                'force_run'    => $force_run ? 1 : 0,
                'status'       => 'pending',
                'attempts'     => 0,
                'last_error'   => '',
                'dedupe_key'   => $dedupe_key,
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
        );

        if ( false === $ok ) {
            return new WP_Error( 'qigeo_job_insert_failed', '任务入队失败，请稍后重试' );
        }

        $job_id = (int) $wpdb->insert_id;
        self::schedule_worker( true );

        return array(
            'success' => true,
            'queued'  => true,
            'job_id'  => $job_id,
        );
    }

    /**
     * Worker：每次只消费一个任务（串行安全保证）
     *
     * @return void
     */
    public static function process_queue_worker() {
        global $wpdb;

        if ( ! self::acquire_lock() ) {
            return;
        }

        try {
            $jobs_table = self::get_jobs_table();
            self::recover_stale_processing_jobs();

            // 串行保护：只要存在 processing 任务，就不再领取新任务
            $has_processing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'processing'" );
            if ( $has_processing > 0 ) {
                return;
            }

            $job_id = (int) $wpdb->get_var( "SELECT id FROM {$jobs_table} WHERE status = 'pending' ORDER BY id ASC LIMIT 1" );
            if ( $job_id <= 0 ) {
                return;
            }

            $claimed = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$jobs_table}
                    SET status = 'processing', attempts = attempts + 1, started_at = %s
                    WHERE id = %d
                    AND status = 'pending'",
                    current_time( 'mysql' ),
                    $job_id
                )
            );
            if ( ! $claimed ) {
                return;
            }

            $job = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$jobs_table} WHERE id = %d LIMIT 1",
                    $job_id
                ),
                ARRAY_A
            );
            if ( ! is_array( $job ) ) {
                return;
            }

            $post_id = isset( $job['post_id'] ) ? absint( $job['post_id'] ) : 0;
            $force_run = ! empty( $job['force_run'] );
            $result = QiGeo_Analyzer::run_for_post( $post_id, $force_run );

            $status = 'done';
            $error = '';
            if ( is_wp_error( $result ) ) {
                $status = 'failed';
                $error = $result->get_error_message();
            } elseif ( is_array( $result ) && ! empty( $result['skipped'] ) ) {
                $status = 'skipped';
            }

            $wpdb->update(
                $jobs_table,
                array(
                    'status'      => $status,
                    'last_error'  => $error,
                    'finished_at' => current_time( 'mysql' ),
                ),
                array( 'id' => $job_id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
        } finally {
            self::release_lock();
        }

        if ( self::has_pending_jobs() ) {
            self::schedule_worker( true );
        }
    }

    /**
     * 是否还有待处理任务
     *
     * @return bool
     */
    public static function has_pending_jobs() {
        global $wpdb;
        $jobs_table = self::get_jobs_table();
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'pending'" );
        return $count > 0;
    }

    /**
     * 获取待处理任务数量（可按文章过滤）
     *
     * @param int $post_id 文章ID
     * @return int
     */
    public static function get_pending_jobs_count( $post_id = 0 ) {
        global $wpdb;
        $jobs_table = self::get_jobs_table();
        $post_id = absint( $post_id );

        if ( $post_id > 0 ) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$jobs_table} WHERE status IN ('pending','processing') AND post_id = %d",
                    $post_id
                )
            );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE status IN ('pending','processing')" );
    }

    /**
     * 调度 worker
     *
     * @param bool $immediate 是否尽快执行
     * @return void
     */
    public static function schedule_worker( $immediate = false ) {
        if ( self::is_wp_cron_disabled() ) {
            if ( $immediate ) {
                self::dispatch_async_worker();
            }
            return;
        }

        if ( $immediate ) {
            $existing = wp_next_scheduled( self::CRON_HOOK );
            if ( ! $existing ) {
                wp_schedule_single_event( time() + 2, self::CRON_HOOK );
            }
            return;
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'qigeo_every_minute', self::CRON_HOOK );
        }
    }

    /**
     * 启动 worker（兼容禁用 WP-Cron 的场景）
     *
     * @return void
     */
    public static function bootstrap_worker() {
        if ( self::is_wp_cron_disabled() ) {
            if ( ! self::has_pending_jobs() ) {
                return;
            }

            // 避免每个请求都触发 loopback
            if ( get_transient( 'qigeo_bootstrap_tick' ) ) {
                return;
            }
            set_transient( 'qigeo_bootstrap_tick', 1, 15 );
            self::dispatch_async_worker();
            return;
        }

        self::schedule_worker( false );
    }

    /**
     * 无 WP-Cron 时的异步 worker 入口
     *
     * @return void
     */
    public static function ajax_process_queue_worker() {
        $token = isset( $_SERVER['HTTP_X_QIGEO_TOKEN'] )
            ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_QIGEO_TOKEN'] ) )
            : '';
        if ( ! self::is_valid_worker_token( $token ) ) {
            wp_die( 'forbidden', 403 );
        }

        self::process_queue_worker();
        wp_die( 'ok', 200 );
    }

    /**
     * 注册 REST 路由（外部触发队列）
     *
     * @return void
     */
    public static function register_rest_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE_TRIGGER,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_external_trigger_queue' ),
                    'permission_callback' => array( __CLASS__, 'rest_external_trigger_permission' ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( __CLASS__, 'rest_external_trigger_queue' ),
                    'permission_callback' => array( __CLASS__, 'rest_external_trigger_permission' ),
                ),
            )
        );
    }

    /**
     * REST 权限校验（token）
     *
     * @param WP_REST_Request $request request
     * @return bool|WP_Error
     */
    public static function rest_external_trigger_permission( $request ) {
        $token = self::extract_token_from_rest_request( $request );
        if ( self::is_valid_worker_token( $token ) ) {
            return true;
        }

        return new WP_Error(
            'qigeo_invalid_token',
            'invalid token',
            array( 'status' => 403 )
        );
    }

    /**
     * REST 外部触发入口
     *
     * @param WP_REST_Request $request request
     * @return WP_REST_Response
     */
    public static function rest_external_trigger_queue( $request ) {
        $force_recover = false;
        if ( $request instanceof WP_REST_Request && strtoupper( (string) $request->get_method() ) === 'POST' && self::has_valid_header_token( $request ) ) {
            $force_recover = rest_sanitize_boolean( $request->get_param( 'force' ) );
        }

        $payload = self::build_external_trigger_payload( $force_recover );

        return rest_ensure_response(
            array(
                'success' => true,
                'data'    => $payload,
            )
        );
    }

    /**
     * 外部触发结果载荷
     *
     * @param bool $force_recover 是否强制回收超时任务
     * @return array<string,mixed>
     */
    public static function build_external_trigger_payload( $force_recover = false ) {
        if ( $force_recover ) {
            self::recover_stale_processing_jobs();
        }

        $pending_before = self::get_pending_jobs_count();
        $processing_before = self::get_processing_jobs_count();

        if ( $processing_before <= 0 && $pending_before > 0 ) {
            self::process_queue_worker();
        }

        $pending_after = self::get_pending_jobs_count();
        $processing_after = self::get_processing_jobs_count();

        $status = 'idle';
        if ( $processing_after > 0 ) {
            $status = 'busy';
        } elseif ( $pending_after > 0 ) {
            $status = 'queued';
        }

        return array(
            'status'         => $status,
            'pending_before' => $pending_before,
            'pending_after'  => $pending_after,
            'processing'     => $processing_after,
            'message'        => 'queue trigger accepted',
        );
    }

    /**
     * 从 REST 请求获取 token
     *
     * @param WP_REST_Request $request request
     * @return string
     */
    public static function extract_token_from_rest_request( $request ) {
        $token = '';
        if ( $request instanceof WP_REST_Request ) {
            $token = (string) $request->get_header( 'X-QiGeo-Token' );
            if ( $token === '' ) {
                $token = (string) $request->get_param( 'token' );
            }
        }

        return sanitize_text_field( $token );
    }

    /**
     * 校验请求头中的 Token
     *
     * @param WP_REST_Request $request request
     * @return bool
     */
    public static function has_valid_header_token( $request ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return false;
        }

        $token = sanitize_text_field( (string) $request->get_header( 'X-QiGeo-Token' ) );
        return self::is_valid_worker_token( $token );
    }

    /**
     * 判断是否禁用了 WP-Cron
     *
     * @return bool
     */
    public static function is_wp_cron_disabled() {
        return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    }

    /**
     * 触发异步 worker（不阻塞当前请求）
     *
     * @return void
     */
    public static function dispatch_async_worker() {
        $url = add_query_arg(
            array(
                'action' => self::AJAX_WORKER_ACTION,
                '_'      => time(),
            ),
            admin_url( 'admin-ajax.php' )
        );

        $args = array(
            'timeout'  => 0.01,
            'blocking' => false,
            'headers'  => array(
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma'        => 'no-cache',
                'X-QiGeo-Token' => self::get_worker_token(),
            ),
        );

        wp_remote_post( $url, $args );
    }

    /**
     * 获取 worker token（不存在则自动生成）
     *
     * @return string
     */
    public static function get_worker_token() {
        $token = (string) get_option( self::WORKER_TOKEN_OPTION, '' );
        if ( strlen( $token ) >= 24 ) {
            return $token;
        }

        $token = wp_generate_password( 48, false, false );
        update_option( self::WORKER_TOKEN_OPTION, $token, false );
        return $token;
    }

    /**
     * 后台展示用：获取当前 worker token
     *
     * @return string
     */
    public static function get_worker_token_for_admin() {
        return self::get_worker_token();
    }

    /**
     * 重置 worker token（安全轮换）
     *
     * @return string
     */
    public static function rotate_worker_token() {
        $token = wp_generate_password( 48, false, false );
        update_option( self::WORKER_TOKEN_OPTION, $token, false );
        return $token;
    }

    /**
     * 外部触发地址
     *
     * @param bool $include_token 是否生成兼容地址，把 token 拼进 URL
     * @return string
     */
    public static function get_external_trigger_url( $include_token = false ) {
        $url = rest_url( self::REST_NAMESPACE . self::REST_ROUTE_TRIGGER );
        if ( ! $include_token ) {
            return (string) $url;
        }

        return (string) add_query_arg(
            array(
                'token' => self::get_worker_token(),
            ),
            $url
        );
    }

    /**
     * 校验 worker token
     *
     * @param string $token token
     * @return bool
     */
    public static function is_valid_worker_token( $token ) {
        $token = (string) $token;
        if ( $token === '' ) {
            return false;
        }

        return hash_equals( self::get_worker_token(), $token );
    }

    /**
     * processing 数量
     *
     * @return int
     */
    public static function get_processing_jobs_count() {
        global $wpdb;
        $jobs_table = self::get_jobs_table();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'processing'" );
    }

    /**
     * 回收超时 processing 任务，避免队列永久阻塞
     *
     * @param int|null $stale_seconds 超时阈值
     * @return void
     */
    public static function recover_stale_processing_jobs( $stale_seconds = null ) {
        global $wpdb;
        $jobs_table = self::get_jobs_table();

        $timeout = is_numeric( $stale_seconds ) ? max( 0, (int) $stale_seconds ) : self::PROCESSING_STALE_SECONDS;
        $cutoff_ts = current_time( 'timestamp' ) - $timeout;
        $cutoff = wp_date( 'Y-m-d H:i:s', $cutoff_ts, wp_timezone() );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$jobs_table}
                SET status = 'failed', last_error = %s, finished_at = %s
                WHERE status = 'processing'
                AND (started_at IS NULL OR started_at < %s)",
                'worker timeout recovered',
                current_time( 'mysql' ),
                $cutoff
            )
        );
    }

    /**
     * 获取最近任务
     *
     * @param int $post_id 文章ID
     * @return array<string,mixed>|null
     */
    public static function get_latest_job( $post_id ) {
        global $wpdb;
        $jobs_table = self::get_jobs_table();
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return null;
        }

        $job = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$jobs_table} WHERE post_id = %d ORDER BY id DESC LIMIT 1",
                $post_id
            ),
            ARRAY_A
        );
        return is_array( $job ) ? $job : null;
    }

    /**
     * 获取全局并发锁
     *
     * @return bool
     */
    public static function acquire_lock() {
        $existing = get_transient( self::LOCK_TRANSIENT_KEY );
        if ( $existing ) {
            return false;
        }
        set_transient( self::LOCK_TRANSIENT_KEY, 1, self::LOCK_TTL );
        return true;
    }

    /**
     * 释放全局并发锁
     *
     * @return void
     */
    public static function release_lock() {
        delete_transient( self::LOCK_TRANSIENT_KEY );
    }
}
