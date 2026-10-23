<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Admin_Logs {

    const MENU_SLUG = 'qigeo-logs';
    const PER_PAGE = 20;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_job_action' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_bulk_action' ) );
    }

    public static function register_submenu() {
        add_submenu_page(
            'qigeo-settings',
            '任务/记录',
            '任务记录',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $notice = isset( $_GET['qigeo_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['qigeo_notice'] ) ) : '';
        $notice_type = isset( $_GET['qigeo_notice_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['qigeo_notice_type'] ) ) : 'success';
        if ( ! in_array( $notice_type, array( 'success', 'warning', 'error' ), true ) ) {
            $notice_type = 'success';
        }

        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'jobs';
        if ( ! in_array( $view, array( 'jobs', 'records' ), true ) ) {
            $view = 'jobs';
        }

        $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
        $post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( (string) $_GET['post_id'] ) ) : 0;
        $paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1;

        if ( $view === 'jobs' ) {
            $allowed_statuses = array( 'pending', 'processing', 'done', 'failed', 'skipped' );
            if ( $status !== '' && ! in_array( $status, $allowed_statuses, true ) ) {
                $status = '';
            }
            $result = self::query_jobs( $status, $post_id, $paged );
        } else {
            $allowed_statuses = array( 'success', 'failed' );
            if ( $status !== '' && ! in_array( $status, $allowed_statuses, true ) ) {
                $status = '';
            }
            $result = self::query_records( $status, $post_id, $paged );
        }

        $rows = isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array();
        $total = isset( $result['total'] ) ? (int) $result['total'] : 0;
        $total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $status_options = $view === 'jobs'
            ? array(
                '' => '全部状态',
                'pending' => 'pending',
                'processing' => 'processing',
                'done' => 'done',
                'failed' => 'failed',
                'skipped' => 'skipped',
            )
            : array(
                '' => '全部状态',
                'success' => 'success',
                'failed' => 'failed',
            );

        ?>
        <div class="wrap qigeo-wrap">
            <h1>启灵GEO 任务记录</h1>
            <?php if ( $notice !== '' ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a class="nav-tab <?php echo $view === 'jobs' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::build_page_url( array( 'view' => 'jobs', 'paged' => 1 ) ) ); ?>">任务队列</a>
                <a class="nav-tab <?php echo $view === 'records' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::build_page_url( array( 'view' => 'records', 'paged' => 1 ) ) ); ?>">分析记录</a>
            </h2>

            <form method="get" style="margin:12px 0 16px;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />

                <label for="qigeo-filter-status"><strong>状态：</strong></label>
                <select id="qigeo-filter-status" name="status">
                    <?php foreach ( $status_options as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="qigeo-filter-post-id" style="margin-left:8px;"><strong>文章ID：</strong></label>
                <input id="qigeo-filter-post-id" type="number" min="1" name="post_id" value="<?php echo $post_id > 0 ? esc_attr( (string) $post_id ) : ''; ?>" />

                <button type="submit" class="button button-secondary">筛选</button>
                <a class="button" href="<?php echo esc_url( self::build_page_url( array( 'view' => $view, 'status' => '', 'post_id' => '', 'paged' => 1 ) ) ); ?>">重置</a>
            </form>

            <p>共 <?php echo esc_html( (string) $total ); ?> 条，当前第 <?php echo esc_html( (string) $paged ); ?>/<?php echo esc_html( (string) $total_pages ); ?> 页。</p>

            <?php self::render_bulk_actions( $view, $status, $post_id ); ?>

            <?php if ( $view === 'jobs' ) : ?>
                <?php self::render_jobs_table( $rows ); ?>
            <?php else : ?>
                <?php self::render_records_table( $rows ); ?>
            <?php endif; ?>

            <?php
            $pagination = paginate_links( array(
                'base' => add_query_arg( 'paged', '%#%', self::build_page_url( array( 'view' => $view, 'status' => $status, 'post_id' => $post_id > 0 ? $post_id : '' ) ) ),
                'format' => '',
                'current' => $paged,
                'total' => $total_pages,
                'type' => 'list',
            ) );
            if ( is_string( $pagination ) && $pagination !== '' ) {
                echo wp_kses_post( $pagination );
            }
            ?>
        </div>
        <?php
    }

    private static function render_bulk_actions( $view, $status, $post_id ) {
        ?>
        <form method="post" style="margin:10px 0 14px;padding:10px;background:#fff;border:1px solid #dcdcde;">
            <?php wp_nonce_field( 'qigeo_logs_bulk_action', 'qigeo_logs_bulk_nonce' ); ?>
            <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
            <input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
            <input type="hidden" name="post_id" value="<?php echo $post_id > 0 ? esc_attr( (string) $post_id ) : ''; ?>" />
            <?php if ( $view === 'jobs' ) : ?>
                <button type="submit" class="button" name="qigeo_logs_bulk_action" value="clear_filtered_jobs" onclick="return confirm('确认清空当前筛选任务吗？processing 任务不会被删除。');">清空筛选任务</button>
                <button type="submit" class="button" name="qigeo_logs_bulk_action" value="clear_finished_jobs" onclick="return confirm('确认清空所有已结束任务吗？');">清空已结束任务</button>
                <button type="submit" class="button button-link-delete" name="qigeo_logs_bulk_action" value="clear_all_jobs" onclick="return confirm('确认清空全部任务队列记录吗？processing 任务不会被删除。');">清空全部任务</button>
            <?php else : ?>
                <button type="submit" class="button" name="qigeo_logs_bulk_action" value="clear_filtered_records" onclick="return confirm('确认清空当前筛选分析记录吗？');">清空筛选记录</button>
                <button type="submit" class="button button-link-delete" name="qigeo_logs_bulk_action" value="clear_all_records" onclick="return confirm('确认清空全部分析记录吗？文章 GEO 快照不会被删除。');">清空全部记录</button>
            <?php endif; ?>
            <button type="submit" class="button button-link-delete" name="qigeo_logs_bulk_action" value="clear_all_history" onclick="return confirm('确认清空任务记录和分析记录吗？processing 任务不会被删除。');">清空全部历史</button>
            <p class="description" style="margin:6px 0 0;">清空只处理后台历史记录；不会删除文章、FAQ库记录或文章已写入的 GEO 内容。</p>
        </form>
        <?php
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    private static function query_jobs( $status, $post_id, $paged ) {
        global $wpdb;
        $jobs_table = $wpdb->prefix . 'qigeo_jobs';
        $posts_table = $wpdb->posts;
        $offset = ( max( 1, (int) $paged ) - 1 ) * self::PER_PAGE;

        $where_sql = 'WHERE 1=1';
        $params = array();

        if ( $status !== '' ) {
            $where_sql .= ' AND j.status = %s';
            $params[] = $status;
        }
        if ( $post_id > 0 ) {
            $where_sql .= ' AND j.post_id = %d';
            $params[] = $post_id;
        }

        $total_sql = "SELECT COUNT(*) FROM {$jobs_table} j {$where_sql}";
        $total = empty( $params )
            ? (int) $wpdb->get_var( $total_sql )
            : (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) );

        $rows_sql = "SELECT j.*, p.post_title
            FROM {$jobs_table} j
            LEFT JOIN {$posts_table} p ON p.ID = j.post_id
            {$where_sql}
            ORDER BY j.id DESC
            LIMIT %d OFFSET %d";
        $rows_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A );

        return array(
            'rows' => is_array( $rows ) ? $rows : array(),
            'total' => $total,
        );
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    private static function query_records( $status, $post_id, $paged ) {
        global $wpdb;
        $records_table = $wpdb->prefix . 'qigeo_records';
        $posts_table = $wpdb->posts;
        $offset = ( max( 1, (int) $paged ) - 1 ) * self::PER_PAGE;

        $where_sql = 'WHERE 1=1';
        $params = array();

        if ( $status !== '' ) {
            $where_sql .= ' AND r.status = %s';
            $params[] = $status;
        }
        if ( $post_id > 0 ) {
            $where_sql .= ' AND r.post_id = %d';
            $params[] = $post_id;
        }

        $total_sql = "SELECT COUNT(*) FROM {$records_table} r {$where_sql}";
        $total = empty( $params )
            ? (int) $wpdb->get_var( $total_sql )
            : (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) );

        $rows_sql = "SELECT r.*, p.post_title
            FROM {$records_table} r
            LEFT JOIN {$posts_table} p ON p.ID = r.post_id
            {$where_sql}
            ORDER BY r.id DESC
            LIMIT %d OFFSET %d";
        $rows_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A );

        return array(
            'rows' => is_array( $rows ) ? $rows : array(),
            'total' => $total,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows rows
     * @return void
     */
    private static function render_jobs_table( $rows ) {
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>文章</th>
                    <th>触发</th>
                    <th>状态</th>
                    <th>尝试次数</th>
                    <th>创建时间</th>
                    <th>开始时间</th>
                    <th>完成时间</th>
                    <th>错误</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="10">暂无数据</td></tr>
            <?php else : ?>
                <?php foreach ( $rows as $row ) : ?>
                    <?php
                    $job_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
                    $job_status = isset( $row['status'] ) ? (string) $row['status'] : '';
                    $actions = array();

                    if ( $job_id > 0 && $job_status === 'pending' ) {
                        $actions[] = '<a href="' . esc_url( self::build_job_action_url( 'cancel_pending', $job_id ) ) . '" onclick="return confirm(\'确认取消这个 pending 任务吗？\');">取消</a>';
                    }
                    if ( $job_id > 0 && $job_status === 'processing' ) {
                        $actions[] = '<a href="' . esc_url( self::build_job_action_url( 'cancel_processing', $job_id ) ) . '" onclick="return confirm(\'确认终止这个 processing 任务吗？\');">终止</a>';
                    }
                    if ( $job_id > 0 && in_array( $job_status, array( 'failed', 'skipped' ), true ) ) {
                        $actions[] = '<a href="' . esc_url( self::build_job_action_url( 'retry', $job_id ) ) . '" onclick="return confirm(\'确认重试该任务所属文章吗？\');">重试</a>';
                    }
                    if ( $job_id > 0 && $job_status !== 'processing' ) {
                        $actions[] = '<a href="' . esc_url( self::build_job_action_url( 'delete', $job_id ) ) . '" onclick="return confirm(\'确认删除该任务记录吗？删除后不可恢复。\');" style="color:#b32d2e;">删除</a>';
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html( (string) $row['id'] ); ?></td>
                        <td><?php echo wp_kses_post( self::format_post_link( $row ) ); ?></td>
                        <td><?php echo esc_html( isset( $row['trigger_type'] ) ? (string) $row['trigger_type'] : '' ); ?></td>
                        <td><?php echo esc_html( isset( $row['status'] ) ? (string) $row['status'] : '' ); ?></td>
                        <td><?php echo esc_html( isset( $row['attempts'] ) ? (string) $row['attempts'] : '' ); ?></td>
                        <td><?php echo esc_html( isset( $row['created_at'] ) ? (string) $row['created_at'] : '' ); ?></td>
                        <td><?php echo esc_html( isset( $row['started_at'] ) ? (string) $row['started_at'] : '' ); ?></td>
                        <td><?php echo esc_html( isset( $row['finished_at'] ) ? (string) $row['finished_at'] : '' ); ?></td>
                        <td><?php echo self::render_error_preview( isset( $row['last_error'] ) ? (string) $row['last_error'] : '' ); ?></td>
                        <td><?php echo ! empty( $actions ) ? implode( ' | ', $actions ) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $rows rows
     * @return void
     */
    private static function render_records_table( $rows ) {
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>文章</th>
                    <th>平台/模型</th>
                    <th>状态</th>
                    <th>评分</th>
                    <th>摘要</th>
                    <th>改写建议</th>
                    <th>详情</th>
                    <th>创建时间</th>
                    <th>错误</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="10">暂无数据</td></tr>
            <?php else : ?>
                <?php foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $row['id'] ); ?></td>
                        <td><?php echo wp_kses_post( self::format_post_link( $row ) ); ?></td>
                        <td><?php echo esc_html( self::format_provider_model( $row ) ); ?></td>
                        <td><?php echo esc_html( isset( $row['status'] ) ? (string) $row['status'] : '' ); ?></td>
                        <td><?php echo esc_html( isset( $row['score'] ) ? (string) $row['score'] : '' ); ?></td>
                        <td><?php echo esc_html( self::short_text( isset( $row['summary'] ) ? (string) $row['summary'] : '', 80 ) ); ?></td>
                        <td><?php echo self::render_rewrite_preview( isset( $row['result_json'] ) ? (string) $row['result_json'] : '' ); ?></td>
                        <td><?php echo self::render_json_preview( isset( $row['result_json'] ) ? (string) $row['result_json'] : '' ); ?></td>
                        <td><?php echo esc_html( isset( $row['created_at'] ) ? (string) $row['created_at'] : '' ); ?></td>
                        <td><?php echo self::render_error_preview( isset( $row['error_message'] ) ? (string) $row['error_message'] : '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_error_preview( $text ) {
        $text = trim( wp_strip_all_tags( (string) $text ) );
        if ( $text === '' ) {
            return '-';
        }

        $short = self::short_text( $text, 80 );
        if ( $short === $text ) {
            return esc_html( $short );
        }

        return '<details><summary>' . esc_html( $short ) . '</summary><pre style="max-width:360px;max-height:180px;overflow:auto;white-space:pre-wrap;background:#f6f7f7;padding:8px;border-radius:4px;">' . esc_html( $text ) . '</pre></details>';
    }

    public static function handle_bulk_action() {
        if ( ! is_admin() || ! isset( $_POST['qigeo_logs_bulk_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'forbidden', 403 );
        }

        check_admin_referer( 'qigeo_logs_bulk_action', 'qigeo_logs_bulk_nonce' );

        $action = sanitize_key( wp_unslash( (string) $_POST['qigeo_logs_bulk_action'] ) );
        $view = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( (string) $_POST['view'] ) ) : 'jobs';
        if ( ! in_array( $view, array( 'jobs', 'records' ), true ) ) {
            $view = 'jobs';
        }
        $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( (string) $_POST['post_id'] ) ) : 0;

        $result = self::run_bulk_action( $action, $status, $post_id );
        $redirect_args = array(
            'view' => $view,
            'paged' => 1,
            'qigeo_notice' => $result['message'],
            'qigeo_notice_type' => $result['success'] ? 'success' : 'error',
        );
        if ( $status !== '' ) {
            $redirect_args['status'] = $status;
        }
        if ( $post_id > 0 ) {
            $redirect_args['post_id'] = $post_id;
        }

        wp_safe_redirect( self::build_page_url( $redirect_args ) );
        exit;
    }

    /**
     * @return array{success:bool,message:string}
     */
    private static function run_bulk_action( $action, $status, $post_id ) {
        global $wpdb;

        $jobs_table = $wpdb->prefix . 'qigeo_jobs';
        $records_table = $wpdb->prefix . 'qigeo_records';
        $action = sanitize_key( (string) $action );
        $status = sanitize_key( (string) $status );
        $post_id = absint( $post_id );

        if ( $action === 'clear_finished_jobs' ) {
            $result = self::delete_rows_in_chunks( $jobs_table, "status IN ('done','failed','skipped')" );
            return self::bulk_delete_result( $result, '已清空已结束任务' );
        }

        if ( $action === 'clear_all_jobs' ) {
            $result = self::delete_rows_in_chunks( $jobs_table, "status <> 'processing'" );
            return self::bulk_delete_result( $result, '已清空全部非 processing 任务记录' );
        }

        if ( $action === 'clear_filtered_jobs' ) {
            if ( $status === 'processing' ) {
                return array(
                    'success' => false,
                    'message' => 'processing 任务不能批量删除，请先终止对应任务。',
                );
            }

            $where = "status <> 'processing'";
            $params = array();
            if ( $status !== '' ) {
                $where .= ' AND status = %s';
                $params[] = $status;
            }
            if ( $post_id > 0 ) {
                $where .= ' AND post_id = %d';
                $params[] = $post_id;
            }

            $result = self::delete_rows_in_chunks( $jobs_table, $where, $params );
            return self::bulk_delete_result( $result, '已清空当前筛选任务' );
        }

        if ( $action === 'clear_all_records' ) {
            $result = self::delete_rows_in_chunks( $records_table, '1=1' );
            return self::bulk_delete_result( $result, '已清空全部分析记录' );
        }

        if ( $action === 'clear_filtered_records' ) {
            $where = '1=1';
            $params = array();
            if ( $status !== '' ) {
                $where .= ' AND status = %s';
                $params[] = $status;
            }
            if ( $post_id > 0 ) {
                $where .= ' AND post_id = %d';
                $params[] = $post_id;
            }

            $result = self::delete_rows_in_chunks( $records_table, $where, $params );
            return self::bulk_delete_result( $result, '已清空当前筛选分析记录' );
        }

        if ( $action === 'clear_all_history' ) {
            $jobs_result = self::delete_rows_in_chunks( $jobs_table, "status <> 'processing'" );
            $records_result = self::delete_rows_in_chunks( $records_table, '1=1' );
            if ( empty( $jobs_result['success'] ) || empty( $records_result['success'] ) ) {
                return array(
                    'success' => false,
                    'message' => '清空失败，数据库写入错误',
                );
            }

            $deleted_jobs = isset( $jobs_result['deleted'] ) ? (int) $jobs_result['deleted'] : 0;
            $deleted_records = isset( $records_result['deleted'] ) ? (int) $records_result['deleted'] : 0;
            $complete = ! empty( $jobs_result['complete'] ) && ! empty( $records_result['complete'] );
            $suffix = $complete ? '' : '，仍有大量记录未清完，可再点一次继续清理';

            return array(
                'success' => true,
                'message' => '已清空任务记录和分析记录（processing 任务已保留），共删除 ' . ( $deleted_jobs + $deleted_records ) . ' 条' . $suffix . '。',
            );
        }

        return array(
            'success' => false,
            'message' => '未知批量操作',
        );
    }

    /**
     * @param array<string,mixed> $result result
     * @param string              $message message
     * @return array{success:bool,message:string}
     */
    private static function bulk_delete_result( $result, $message ) {
        if ( empty( $result['success'] ) ) {
            return array(
                'success' => false,
                'message' => '清空失败，数据库写入错误',
            );
        }

        $suffix = ! empty( $result['complete'] ) ? '' : '，仍有大量记录未清完，可再点一次继续清理';

        return array(
            'success' => true,
            'message' => $message . '，共删除 ' . (int) $result['deleted'] . ' 条' . $suffix . '。',
        );
    }

    /**
     * 大表删除分批执行，避免一次 DELETE 长时间锁表。
     *
     * @param string           $table table name
     * @param string           $where_sql where without WHERE
     * @param array<int,mixed> $params params
     * @return array{success:bool,deleted:int,complete:bool}
     */
    private static function delete_rows_in_chunks( $table, $where_sql, $params = array() ) {
        global $wpdb;

        $table = (string) $table;
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
            return array(
                'success' => false,
                'deleted' => 0,
                'complete' => false,
            );
        }

        $where_sql = trim( (string) $where_sql );
        if ( $where_sql === '' ) {
            $where_sql = '1=1';
        }

        $chunk_size = 2000;
        $max_chunks = 20;
        $deleted_total = 0;

        for ( $i = 0; $i < $max_chunks; $i++ ) {
            $sql = "DELETE FROM {$table} WHERE {$where_sql} LIMIT %d";
            $query_params = array_merge( (array) $params, array( $chunk_size ) );
            $deleted = $wpdb->query( $wpdb->prepare( $sql, $query_params ) );
            if ( false === $deleted ) {
                return array(
                    'success' => false,
                    'deleted' => $deleted_total,
                    'complete' => false,
                );
            }

            $deleted_total += (int) $deleted;
            if ( (int) $deleted < $chunk_size ) {
                return array(
                    'success' => true,
                    'deleted' => $deleted_total,
                    'complete' => true,
                );
            }
        }

        return array(
            'success' => true,
            'deleted' => $deleted_total,
            'complete' => false,
        );
    }

    /**
     * @param int|false $deleted deleted
     * @param string    $message message
     * @return array{success:bool,message:string}
     */
    private static function bulk_result( $deleted, $message ) {
        if ( false === $deleted ) {
            return array(
                'success' => false,
                'message' => '清空失败，数据库写入错误',
            );
        }

        return array(
            'success' => true,
            'message' => $message . '，共删除 ' . (int) $deleted . ' 条。',
        );
    }

    /**
     * @param array<string,mixed> $row row
     * @return string
     */
    private static function format_post_link( $row ) {
        $post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
        $title = isset( $row['post_title'] ) ? (string) $row['post_title'] : '';
        if ( $post_id <= 0 ) {
            return '-';
        }

        if ( $title === '' ) {
            $title = '(无标题)';
        }

        $edit_link = get_edit_post_link( $post_id );
        if ( ! $edit_link ) {
            return '#' . $post_id . ' ' . esc_html( $title );
        }

        return '<a href="' . esc_url( $edit_link ) . '">#' . esc_html( (string) $post_id ) . ' ' . esc_html( $title ) . '</a>';
    }

    /**
     * @param array<string,mixed> $row row
     * @return string
     */
    private static function format_provider_model( $row ) {
        $provider = isset( $row['provider_id'] ) ? (string) $row['provider_id'] : '';
        $model = isset( $row['model'] ) ? (string) $row['model'] : '';
        if ( $provider === '' && $model === '' ) {
            return '-';
        }
        return $provider . ' / ' . $model;
    }

    /**
     * @param string $text text
     * @param int    $max  max
     * @return string
     */
    private static function short_text( $text, $max = 80 ) {
        $text = trim( wp_strip_all_tags( (string) $text ) );
        return QiGeo_Admin_Helper::mb_safe_limit( $text, $max, true );
    }

    /**
     * @param string $json json
     * @return string
     */
    private static function render_json_preview( $json ) {
        $json = trim( (string) $json );
        if ( $json === '' ) {
            return '-';
        }

        $decoded = json_decode( $json, true );
        if ( is_array( $decoded ) ) {
            $json = (string) wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        }

        if ( QiGeo_Admin_Helper::mb_safe_len( $json ) > 4000 ) {
            $json = QiGeo_Admin_Helper::mb_safe_limit( $json, 4000, false ) . "\n... (truncated)";
        }

        return '<details><summary>查看</summary><pre style="max-width:460px;max-height:220px;overflow:auto;white-space:pre-wrap;background:#f6f7f7;padding:8px;border-radius:4px;">' . esc_html( $json ) . '</pre></details>';
    }

    /**
     * @param string $result_json result_json
     * @return string
     */
    private static function render_rewrite_preview( $result_json ) {
        $result_json = trim( (string) $result_json );
        if ( $result_json === '' ) {
            return '-';
        }

        $decoded = json_decode( $result_json, true );
        if ( ! is_array( $decoded ) || empty( $decoded['rewrite_suggestions'] ) || ! is_array( $decoded['rewrite_suggestions'] ) ) {
            return '-';
        }

        $lines = array();
        foreach ( $decoded['rewrite_suggestions'] as $entry ) {
            $line = '';
            if ( is_string( $entry ) ) {
                $line = trim( wp_strip_all_tags( $entry ) );
            } elseif ( is_array( $entry ) ) {
                $parts = array();
                if ( ! empty( $entry['issue'] ) ) {
                    $parts[] = '问题：' . trim( wp_strip_all_tags( (string) $entry['issue'] ) );
                }
                if ( ! empty( $entry['suggestion'] ) ) {
                    $parts[] = '建议：' . trim( wp_strip_all_tags( (string) $entry['suggestion'] ) );
                } elseif ( ! empty( $entry['advice'] ) ) {
                    $parts[] = '建议：' . trim( wp_strip_all_tags( (string) $entry['advice'] ) );
                }
                if ( ! empty( $entry['example'] ) ) {
                    $parts[] = '示例：' . trim( wp_strip_all_tags( (string) $entry['example'] ) );
                }
                $line = implode( '；', array_filter( $parts ) );
            }

            if ( $line !== '' ) {
                $lines[] = $line;
            }
            if ( count( $lines ) >= 5 ) {
                break;
            }
        }

        if ( empty( $lines ) ) {
            return '-';
        }

        $html = '<details><summary>查看</summary><ol style="margin:6px 0 0 18px;">';
        foreach ( $lines as $line ) {
            $html .= '<li style="margin-bottom:6px;">' . esc_html( $line ) . '</li>';
        }
        $html .= '</ol></details>';

        return $html;
    }

    /**
     * @param array<string,mixed> $args args
     * @return string
     */
    private static function build_page_url( $args = array() ) {
        $base = admin_url( 'admin.php?page=' . self::MENU_SLUG );
        if ( empty( $args ) ) {
            return $base;
        }

        return (string) add_query_arg( $args, $base );
    }

    /**
     * @return void
     */
    public static function handle_job_action() {
        if ( ! is_admin() ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
        $action = isset( $_GET['qigeo_job_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['qigeo_job_action'] ) ) : '';
        $job_id = isset( $_GET['job_id'] ) ? absint( wp_unslash( (string) $_GET['job_id'] ) ) : 0;

        if ( $page !== self::MENU_SLUG || $action === '' || $job_id <= 0 ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'forbidden', 403 );
        }

        check_admin_referer( self::build_action_nonce( $action, $job_id ) );

        $result = self::run_job_action( $action, $job_id );
        $redirect_args = array(
            'view' => isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'jobs',
            'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '',
            'post_id' => isset( $_GET['post_id'] ) ? absint( wp_unslash( (string) $_GET['post_id'] ) ) : '',
            'paged' => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1,
            'qigeo_notice' => $result['message'],
            'qigeo_notice_type' => $result['success'] ? 'success' : 'error',
        );
        if ( empty( $redirect_args['status'] ) ) {
            unset( $redirect_args['status'] );
        }
        if ( empty( $redirect_args['post_id'] ) ) {
            unset( $redirect_args['post_id'] );
        }
        if ( empty( $redirect_args['paged'] ) ) {
            unset( $redirect_args['paged'] );
        }

        wp_safe_redirect( self::build_page_url( $redirect_args ) );
        exit;
    }

    /**
     * @param string $action action
     * @param int    $job_id  job_id
     * @return array{success:bool,message:string}
     */
    private static function run_job_action( $action, $job_id ) {
        global $wpdb;

        $jobs_table = $wpdb->prefix . 'qigeo_jobs';
        $action = sanitize_key( (string) $action );
        $job_id = absint( $job_id );
        if ( $job_id <= 0 ) {
            return array(
                'success' => false,
                'message' => '任务 ID 无效',
            );
        }

        if ( $action === 'cancel_pending' || $action === 'cancel_processing' ) {
            $from_status = $action === 'cancel_pending' ? 'pending' : 'processing';
            $updated = $wpdb->update(
                $jobs_table,
                array(
                    'status'      => 'failed',
                    'last_error'  => 'manually cancelled by admin',
                    'finished_at' => current_time( 'mysql' ),
                ),
                array(
                    'id' => $job_id,
                    'status' => $from_status,
                ),
                array( '%s', '%s', '%s' ),
                array( '%d', '%s' )
            );

            if ( false === $updated ) {
                return array(
                    'success' => false,
                    'message' => '操作失败，数据库写入错误',
                );
            }

            if ( 0 === (int) $updated ) {
                return array(
                    'success' => false,
                    'message' => '任务状态已变化，未执行取消',
                );
            }

            QiGeo_Analyzer::schedule_worker( true );

            return array(
                'success' => true,
                'message' => $action === 'cancel_pending' ? '已取消 pending 任务' : '已终止 processing 任务',
            );
        }

        if ( $action === 'retry' ) {
            $job = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$jobs_table} WHERE id = %d LIMIT 1",
                    $job_id
                ),
                ARRAY_A
            );
            if ( ! is_array( $job ) || empty( $job['post_id'] ) ) {
                return array(
                    'success' => false,
                    'message' => '任务不存在或文章 ID 无效',
                );
            }

            $result = QiGeo_Analyzer::enqueue_job( absint( $job['post_id'] ), 'manual', true );
            if ( is_wp_error( $result ) ) {
                return array(
                    'success' => false,
                    'message' => '重试入队失败：' . $result->get_error_message(),
                );
            }

            if ( ! empty( $result['queued'] ) ) {
                return array(
                    'success' => true,
                    'message' => '已重新加入队列（任务 #' . absint( $result['job_id'] ) . '）',
                );
            }

            return array(
                'success' => true,
                'message' => '已有同文章任务在队列中（任务 #' . absint( $result['job_id'] ) . '）',
            );
        }

        if ( $action === 'delete' ) {
            $job = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, status FROM {$jobs_table} WHERE id = %d LIMIT 1",
                    $job_id
                ),
                ARRAY_A
            );

            if ( ! is_array( $job ) ) {
                return array(
                    'success' => false,
                    'message' => '任务不存在或已被删除',
                );
            }

            $job_status = isset( $job['status'] ) ? (string) $job['status'] : '';
            if ( $job_status === 'processing' ) {
                return array(
                    'success' => false,
                    'message' => 'processing 任务不能直接删除，请先终止后再删除',
                );
            }

            $deleted = $wpdb->delete(
                $jobs_table,
                array( 'id' => $job_id ),
                array( '%d' )
            );

            if ( false === $deleted ) {
                return array(
                    'success' => false,
                    'message' => '删除失败，数据库写入错误',
                );
            }

            if ( 0 === (int) $deleted ) {
                return array(
                    'success' => false,
                    'message' => '任务不存在或已被删除',
                );
            }

            return array(
                'success' => true,
                'message' => '任务记录已删除',
            );
        }

        return array(
            'success' => false,
            'message' => '未知操作',
        );
    }

    /**
     * @param string $action action
     * @param int    $job_id job_id
     * @return string
     */
    private static function build_job_action_url( $action, $job_id ) {
        $args = array(
            'page' => self::MENU_SLUG,
            'qigeo_job_action' => sanitize_key( (string) $action ),
            'job_id' => absint( $job_id ),
            'view' => isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'jobs',
            'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '',
            'post_id' => isset( $_GET['post_id'] ) ? absint( wp_unslash( (string) $_GET['post_id'] ) ) : '',
            'paged' => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1,
        );
        if ( empty( $args['status'] ) ) {
            unset( $args['status'] );
        }
        if ( empty( $args['post_id'] ) ) {
            unset( $args['post_id'] );
        }
        if ( empty( $args['paged'] ) ) {
            unset( $args['paged'] );
        }

        $url = add_query_arg( $args, admin_url( 'admin.php' ) );
        return (string) wp_nonce_url( $url, self::build_action_nonce( $action, $job_id ) );
    }

    /**
     * @param string $action action
     * @param int    $job_id job id
     * @return string
     */
    private static function build_action_nonce( $action, $job_id ) {
        return 'qigeo_job_action_' . sanitize_key( (string) $action ) . '_' . absint( $job_id );
    }
}
