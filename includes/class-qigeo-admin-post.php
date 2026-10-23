<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Admin_Post {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'remove_legacy_side_meta_boxes' ), 999 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'save_post', array( __CLASS__, 'save_source_url_meta' ), 10, 2 );
        add_action( 'wp_ajax_qigeo_run_analysis', array( __CLASS__, 'ajax_run_analysis' ) );
        add_action( 'wp_ajax_qigeo_get_geo_package', array( __CLASS__, 'ajax_get_geo_package' ) );
        add_action( 'wp_ajax_qigeo_run_secondary_review', array( __CLASS__, 'ajax_run_secondary_review' ) );
        add_action( 'wp_ajax_qigeo_run_ai_feed', array( __CLASS__, 'ajax_run_ai_feed' ) );
        add_action( 'wp_ajax_qigeo_generate_offpage_pack', array( 'QiGeo_Offpage_Pack', 'ajax_generate' ) );
    }

    public static function remove_legacy_side_meta_boxes() {
        $settings = QiGeo_Settings::get_settings();
        $post_types = isset( $settings['allowed_post_types'] ) ? (array) $settings['allowed_post_types'] : array( 'post' );
        $legacy_ids = array( 'qigeo_meta_box', 'qigeo_geo_meta_box', 'qigeo_analysis_meta_box' );
        $contexts = array( 'side', 'normal', 'advanced' );

        foreach ( $post_types as $post_type ) {
            foreach ( $legacy_ids as $legacy_id ) {
                foreach ( $contexts as $context ) {
                    remove_meta_box( $legacy_id, $post_type, $context );
                }
            }
        }
    }

    public static function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || empty( $screen->post_type ) ) {
            return;
        }

        $settings = QiGeo_Settings::get_settings();
        $post_types = isset( $settings['allowed_post_types'] ) ? (array) $settings['allowed_post_types'] : array( 'post' );
        if ( ! in_array( $screen->post_type, $post_types, true ) ) {
            return;
        }

        wp_enqueue_script( 'qigeo-post-admin', QIGEO_URL . 'assets/post-admin.js', array(), QIGEO_VERSION, true );
        wp_register_style( 'qigeo-post-admin-guard', false, array(), QIGEO_VERSION );
        wp_enqueue_style( 'qigeo-post-admin-guard' );
        wp_add_inline_style(
            'qigeo-post-admin-guard',
            '#side-sortables #qigeo_meta_box,#side-sortables #qigeo_geo_meta_box,#side-sortables #qigeo_analysis_meta_box,#side-sortables .qigeo-analysis-panel{display:none!important;}'
        );
        wp_localize_script( 'qigeo-post-admin', 'qigeoPostAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'qigeo_run_analysis_nonce' ),
            'packageNonce' => wp_create_nonce( 'qigeo_geo_package_nonce' ),
            'secondaryNonce' => wp_create_nonce( 'qigeo_secondary_review_nonce' ),
            'aiFeedNonce' => wp_create_nonce( 'qigeo_ai_feed_nonce' ),
            'runningText' => '分析中...',
            'idleText' => '开始 GEO 分析',
            'copyingText' => '整理中...',
            'copyPackageText' => '复制投喂数据',
            'secondaryRunningText' => '分析中...',
            'secondaryIdleText' => '多模型分析',
            'aiFeedRunningText' => '投喂中...',
            'aiFeedIdleText' => '推送指定AI',
            'offpageNonce' => wp_create_nonce( 'qigeo_offpage_pack_nonce' ),
            'offpageRunningText' => '生成中...',
            'offpageIdleText' => '一键生成全网引证包 (知乎 / 小红书 / 资讯)',
        ) );
    }

    public static function render_analysis_panel( $post ) {
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $status = (string) QiGeo_Analyzer::get_snapshot_value( $post->ID, QiGeo_Analyzer::META_STATUS );
        $score = QiGeo_Analyzer::get_snapshot_value( $post->ID, QiGeo_Analyzer::META_SCORE );
        $score_breakdown_json = (string) QiGeo_Analyzer::get_snapshot_value( $post->ID, QiGeo_Analyzer::META_GEO_SCORE_BREAKDOWN_JSON );
        $score_breakdown = json_decode( $score_breakdown_json, true );
        if ( ! is_array( $score_breakdown ) ) {
            $score_breakdown = array();
        }
        $summary = (string) QiGeo_Analyzer::get_snapshot_value( $post->ID, QiGeo_Analyzer::META_SUMMARY );
        $rewrite_json = (string) QiGeo_Analyzer::get_snapshot_value( $post->ID, QiGeo_Analyzer::META_REWRITE_SUGGESTIONS );
        $rewrite_suggestions = json_decode( $rewrite_json, true );
        if ( ! is_array( $rewrite_suggestions ) ) {
            $rewrite_suggestions = array();
        }
        $frontend_eligible = (string) QiGeo_Analyzer::get_snapshot_value( $post->ID, QiGeo_Analyzer::META_FRONTEND_ELIGIBLE );
        $quality_reason = (string) QiGeo_Analyzer::get_snapshot_value( $post->ID, QiGeo_Analyzer::META_QUALITY_GATE_REASON );
        $last_run = (string) QiGeo_Analyzer::get_snapshot_value( $post->ID, QiGeo_Analyzer::META_LAST_RUN );
        $latest = QiGeo_Analyzer::get_latest_record( $post->ID );
        $latest_job = QiGeo_Analyzer::get_latest_job( $post->ID );
        $pending_jobs = QiGeo_Analyzer::get_pending_jobs_count( $post->ID );

        echo '<div class="qigeo-analysis-panel" style="border:1px solid #dcdcde;background:#fff;padding:12px;margin:12px 0;">';
        echo '<p style="margin-top:0;"><strong>第一阶段：AI 分析当前文章</strong></p>';
        echo '<p class="description">先让默认分析平台找出当前文章的问题、缺口和优化建议。根据建议修改并保存正文后，再在下方进入第二阶段生成 FAQ、摘要和推荐 SEO。</p>';
        echo '<p><strong>状态：</strong>' . esc_html( $status ? $status : '未分析' ) . '</p>';
        if ( $score !== '' ) {
            echo '<p><strong>评分：</strong>' . esc_html( (string) $score ) . '</p>';
        }
        if ( ! empty( $score_breakdown ) ) {
            echo '<p><strong>GEO 分项评分：</strong></p>';
            echo '<ul style="margin:6px 0 8px 18px;">';
            foreach ( QiGeo_Analyzer::get_geo_score_dimensions() as $key => $dimension ) {
                if ( empty( $score_breakdown[ $key ] ) || ! is_array( $score_breakdown[ $key ] ) ) {
                    continue;
                }
                $label = ! empty( $score_breakdown[ $key ]['label'] ) ? (string) $score_breakdown[ $key ]['label'] : ( isset( $dimension['label'] ) ? (string) $dimension['label'] : $key );
                $item_score = isset( $score_breakdown[ $key ]['score'] ) && is_numeric( $score_breakdown[ $key ]['score'] ) ? (string) $score_breakdown[ $key ]['score'] : '-';
                $reason = ! empty( $score_breakdown[ $key ]['reason'] ) ? (string) $score_breakdown[ $key ]['reason'] : '';
                echo '<li style="margin-bottom:4px;"><strong>' . esc_html( $label ) . '：</strong>' . esc_html( $item_score );
                if ( $reason !== '' ) {
                    echo '<br><span style="color:#646970;">' . esc_html( $reason ) . '</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        if ( $frontend_eligible === '0' ) {
            $reason_text = $quality_reason !== '' ? $quality_reason : '质量闸门未通过';
            echo '<p style="color:#b32d2e;"><strong>前台输出：</strong>已拦截（' . esc_html( $reason_text ) . '）</p>';
        } elseif ( $frontend_eligible === '1' ) {
            echo '<p style="color:#0f5132;"><strong>前台输出：</strong>允许</p>';
        }
        if ( $last_run !== '' ) {
            echo '<p><strong>最近分析：</strong>' . esc_html( $last_run ) . '</p>';
        }
        if ( ! empty( $summary ) ) {
            echo '<p><strong>摘要：</strong></p>';
            echo '<div style="max-height:120px;overflow:auto;background:#f6f7f7;padding:8px;border-radius:4px;">' . esc_html( $summary ) . '</div>';
        }
        if ( ! empty( $rewrite_suggestions ) ) {
            echo '<p><strong>改写建议：</strong></p>';
            echo '<ol style="margin:6px 0 8px 18px;">';
            $count = 0;
            foreach ( $rewrite_suggestions as $item ) {
                $line = '';
                if ( is_string( $item ) ) {
                    $line = trim( $item );
                } elseif ( is_array( $item ) ) {
                    $parts = array();
                    if ( ! empty( $item['issue'] ) ) {
                        $parts[] = '问题：' . (string) $item['issue'];
                    }
                    if ( ! empty( $item['suggestion'] ) ) {
                        $parts[] = '建议：' . (string) $item['suggestion'];
                    }
                    if ( ! empty( $item['example'] ) ) {
                        $parts[] = '示例：' . (string) $item['example'];
                    }
                    $line = implode( '；', $parts );
                }
                if ( $line === '' ) {
                    continue;
                }

                echo '<li style="margin-bottom:6px;">' . esc_html( $line ) . '</li>';
                $count++;
                if ( $count >= 5 ) {
                    break;
                }
            }
            echo '</ol>';
        }
        if ( is_array( $latest ) && ! empty( $latest['error_message'] ) ) {
            echo '<p style="color:#b32d2e;"><strong>最近错误：</strong>' . esc_html( $latest['error_message'] ) . '</p>';
        }
        if ( $pending_jobs > 0 ) {
            echo '<p><strong>队列中：</strong>' . esc_html( (string) $pending_jobs ) . ' 个任务</p>';
        }
        if ( is_array( $latest_job ) && ! empty( $latest_job['status'] ) ) {
            echo '<p><strong>最近任务：</strong>' . esc_html( (string) $latest_job['status'] ) . '</p>';
        }
        echo '<p><button type="button" class="button button-secondary qigeo-run-analysis-btn" data-post-id="' . esc_attr( $post->ID ) . '">开始 GEO 分析</button></p>';
        echo '<p class="description qigeo-run-analysis-message"></p>';
        echo '</div>';
    }

    public static function save_source_url_meta( $post_id, $post ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! $post instanceof WP_Post ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['qigeo_source_url_nonce'] ) ) {
            return;
        }

        $nonce = sanitize_text_field( wp_unslash( (string) $_POST['qigeo_source_url_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'qigeo_save_source_url_' . $post_id ) ) {
            return;
        }

        $settings = QiGeo_Settings::get_settings();
        $post_types = isset( $settings['allowed_post_types'] ) && is_array( $settings['allowed_post_types'] )
            ? $settings['allowed_post_types']
            : array( 'post' );
        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            return;
        }

        $source_url = isset( $_POST['qigeo_source_url'] ) ? wp_unslash( (string) $_POST['qigeo_source_url'] ) : '';
        QiGeo_Analyzer::update_article_source_url( $post_id, $source_url );
    }

    private static function maybe_update_source_url_from_request( $post_id ) {
        if ( ! isset( $_POST['source_url'] ) ) {
            return;
        }

        $source_url = wp_unslash( (string) $_POST['source_url'] );
        QiGeo_Analyzer::update_article_source_url( $post_id, $source_url );
    }

    private static function collect_package_overrides_from_request() {
        $overrides = array();

        if ( isset( $_POST['summary'] ) ) {
            $overrides['summary'] = sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) );
        }

        if ( isset( $_POST['key_points_lines'] ) ) {
            $overrides['key_points'] = self::parse_plain_lines( wp_unslash( (string) $_POST['key_points_lines'] ), 20 );
        }

        if ( isset( $_POST['faq_lines'] ) ) {
            $overrides['faq'] = self::parse_faq_lines( wp_unslash( (string) $_POST['faq_lines'] ) );
        }

        if ( isset( $_POST['suggestions_lines'] ) ) {
            $overrides['rewrite_suggestions'] = self::parse_plain_lines( wp_unslash( (string) $_POST['suggestions_lines'] ), 50 );
        }

        if ( isset( $_POST['seo_title'] ) ) {
            $overrides['seo_title'] = sanitize_text_field( wp_unslash( (string) $_POST['seo_title'] ) );
        }

        if ( isset( $_POST['seo_keywords'] ) ) {
            $overrides['seo_keywords'] = sanitize_text_field( wp_unslash( (string) $_POST['seo_keywords'] ) );
        }

        if ( isset( $_POST['seo_description'] ) ) {
            $overrides['seo_description'] = sanitize_textarea_field( wp_unslash( (string) $_POST['seo_description'] ) );
        }

        return $overrides;
    }

    private static function collect_secondary_model_ids_from_request() {
        if ( ! isset( $_POST['secondary_model_ids'] ) ) {
            return array();
        }

        $raw = wp_unslash( $_POST['secondary_model_ids'] );
        $raw_items = is_array( $raw ) ? $raw : array( $raw );
        $model_ids = array();
        foreach ( $raw_items as $item ) {
            if ( ! is_scalar( $item ) ) {
                continue;
            }
            $model_id = sanitize_key( (string) $item );
            if ( $model_id === '' || in_array( $model_id, $model_ids, true ) ) {
                continue;
            }
            $model_ids[] = $model_id;
        }

        return $model_ids;
    }

    private static function collect_feed_model_ids_from_request() {
        if ( ! isset( $_POST['feed_model_ids'] ) ) {
            return array();
        }

        $raw = wp_unslash( $_POST['feed_model_ids'] );
        $raw_items = is_array( $raw ) ? $raw : array( $raw );
        $model_ids = array();
        foreach ( $raw_items as $item ) {
            if ( ! is_scalar( $item ) ) {
                continue;
            }
            $model_id = sanitize_key( (string) $item );
            if ( $model_id === '' || in_array( $model_id, $model_ids, true ) ) {
                continue;
            }
            $model_ids[] = $model_id;
        }

        return $model_ids;
    }

    private static function parse_plain_lines( $lines, $limit = 50 ) {
        $rows = preg_split( "/\r\n|\r|\n/u", (string) $lines );
        $items = array();
        foreach ( (array) $rows as $row ) {
            $line = trim( (string) $row );
            if ( $line === '' ) {
                continue;
            }
            $items[] = sanitize_textarea_field( $line );
            if ( count( $items ) >= $limit ) {
                break;
            }
        }
        return $items;
    }

    private static function parse_faq_lines( $lines ) {
        $lines = trim( (string) $lines );
        if ( $lines === '' ) {
            return array();
        }

        if ( strpos( $lines, '[' ) === 0 ) {
            $decoded = json_decode( $lines, true );
            if ( is_array( $decoded ) ) {
                $items = array();
                foreach ( $decoded as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : ( isset( $item['question'] ) ? trim( (string) $item['question'] ) : '' );
                    $a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : ( isset( $item['answer'] ) ? trim( (string) $item['answer'] ) : '' );
                    if ( $q === '' || $a === '' ) {
                        continue;
                    }
                    $items[] = array(
                        'q' => sanitize_text_field( $q ),
                        'a' => sanitize_textarea_field( $a ),
                    );
                    if ( count( $items ) >= 30 ) {
                        break;
                    }
                }
                return $items;
            }
        }

        $rows = preg_split( "/\r\n|\r|\n/u", $lines );
        $faq = array();
        foreach ( (array) $rows as $row ) {
            $line = trim( (string) $row );
            if ( $line === '' ) {
                continue;
            }
            $line = preg_replace( '/^\d+\s*[\.\)、]\s*/u', '', $line );
            if ( ! is_string( $line ) || $line === '' ) {
                continue;
            }

            $parts = preg_split( '/\s*(?:\|\||｜｜)\s*/u', $line, 2 );
            if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
                continue;
            }
            $q = trim( (string) $parts[0] );
            $a = trim( (string) $parts[1] );
            if ( $q === '' || $a === '' ) {
                continue;
            }

            $faq[] = array(
                'q' => sanitize_text_field( $q ),
                'a' => sanitize_textarea_field( $a ),
            );
            if ( count( $faq ) >= 30 ) {
                break;
            }
        }

        return $faq;
    }

    public static function ajax_run_analysis() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'qigeo_run_analysis_nonce', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( (string) $_POST['post_id'] ) ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '文章 ID 无效' ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '无该文章编辑权限' ) );
        }
        self::maybe_update_source_url_from_request( $post_id );

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        $settings = QiGeo_Settings::get_settings();
        $post_types = isset( $settings['allowed_post_types'] ) && is_array( $settings['allowed_post_types'] )
            ? $settings['allowed_post_types']
            : array( 'post' );
        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            wp_send_json_error( array( 'message' => '当前文章类型未启用 GEO 分析' ) );
        }

        $result = QiGeo_Analyzer::enqueue_job( $post_id, 'manual', true );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        if ( ! empty( $result['queued'] ) ) {
            $message = '已加入 GEO 分析队列';
            if ( ! empty( $result['job_id'] ) ) {
                $message .= '（任务 #' . absint( $result['job_id'] ) . '）';
            }
        } else {
            $message = '已存在队列任务，复用任务';
            if ( ! empty( $result['job_id'] ) ) {
                $message .= ' #' . absint( $result['job_id'] );
            }
        }

        wp_send_json_success( array(
            'message' => $message,
            'queued' => ! empty( $result['queued'] ),
        ) );
    }

    public static function ajax_get_geo_package() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'qigeo_geo_package_nonce', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( (string) $_POST['post_id'] ) ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '文章 ID 无效' ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '无该文章编辑权限' ) );
        }
        self::maybe_update_source_url_from_request( $post_id );

        $result = QiGeo_Analyzer::get_geo_package_for_post( $post_id, true, self::collect_package_overrides_from_request() );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => '人工投喂数据已复制',
            'copy_text' => isset( $result['copy_text'] ) ? (string) $result['copy_text'] : '',
        ) );
    }

    public static function ajax_run_secondary_review() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'qigeo_secondary_review_nonce', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( (string) $_POST['post_id'] ) ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '文章 ID 无效' ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '无该文章编辑权限' ) );
        }
        self::maybe_update_source_url_from_request( $post_id );

        $result = QiGeo_Analyzer::run_secondary_platform_review_for_post( $post_id, self::collect_package_overrides_from_request(), self::collect_secondary_model_ids_from_request() );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $message = '多模型分析完成';
        if ( isset( $result['success_count'], $result['target_count'] ) ) {
            $message = '多模型分析完成：成功 ' . absint( $result['success_count'] ) . '/' . absint( $result['target_count'] );
        }
        if ( ! empty( $result['skipped_target_count'] ) ) {
            $message .= '，为防止后台卡死，本次仅处理前 ' . absint( $result['target_count'] ) . ' 个模型，剩余 ' . absint( $result['skipped_target_count'] ) . ' 个请分批执行';
        }
        if ( isset( $result['acceptance_score'] ) && $result['acceptance_score'] !== null ) {
            $message .= '，评分 ' . (string) $result['acceptance_score'];
        }
        if ( isset( $result['ready_to_manual_submit'] ) ) {
            $message .= ! empty( $result['ready_to_manual_submit'] ) ? '，已确认接收' : '，建议继续补强';
        }

        wp_send_json_success( array(
            'message' => $message,
            'summary' => isset( $result['summary'] ) ? (string) $result['summary'] : '',
        ) );
    }

    public static function ajax_run_ai_feed() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'qigeo_ai_feed_nonce', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( (string) $_POST['post_id'] ) ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '文章 ID 无效' ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '无该文章编辑权限' ) );
        }
        self::maybe_update_source_url_from_request( $post_id );

        $result = QiGeo_Analyzer::run_ai_feed_for_post( $post_id, self::collect_package_overrides_from_request(), self::collect_feed_model_ids_from_request() );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $message = 'AI 投喂完成';
        if ( isset( $result['success_count'], $result['target_count'] ) ) {
            $message = 'AI 投喂完成：成功 ' . absint( $result['success_count'] ) . '/' . absint( $result['target_count'] );
        }
        if ( ! empty( $result['skipped_target_count'] ) ) {
            $message .= '，为防止后台卡死，本次仅处理前 ' . absint( $result['target_count'] ) . ' 个模型，剩余 ' . absint( $result['skipped_target_count'] ) . ' 个请分批执行';
        }

        wp_send_json_success( array(
            'message' => $message,
            'summary' => isset( $result['summary'] ) ? (string) $result['summary'] : '',
        ) );
    }
}
