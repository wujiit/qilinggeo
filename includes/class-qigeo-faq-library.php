<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_FAQ_Library {

    const AJAX_GENERATE_ACTION = 'qigeo_generate_faq_library';
    const AJAX_SAVE_ACTION = 'qigeo_save_faq_library';

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            'FAQ 库',
            'FAQ 库',
            'manage_options',
            'qigeo-faq-library',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function register_meta_box() {
        $post_types = self::allowed_post_types();
        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'qigeo_faq_library_meta_box',
                '启灵 FAQ 库',
                array( __CLASS__, 'render_meta_box' ),
                $post_type,
                'normal',
                'default'
            );
        }
    }

    public static function enqueue_assets( $hook ) {
        $is_post_editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true );
        $is_faq_library_page = strpos( (string) $hook, 'qigeo-faq-library' ) !== false;
        if ( ! $is_post_editor && ! $is_faq_library_page ) {
            return;
        }

        if ( $is_post_editor ) {
            $screen = get_current_screen();
            if ( ! $screen || empty( $screen->post_type ) ) {
                return;
            }

            $post_types = self::allowed_post_types();
            if ( ! in_array( $screen->post_type, $post_types, true ) ) {
                return;
            }
        }

        wp_enqueue_script( 'qigeo-post-admin', QIGEO_URL . 'assets/post-admin.js', array(), QIGEO_VERSION, true );
        wp_localize_script(
            'qigeo-post-admin',
            'qigeoFaqLibraryData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_faq_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_faq_nonce' ),
                'packageNonce' => wp_create_nonce( 'qigeo_geo_package_nonce' ),
                'secondaryNonce' => wp_create_nonce( 'qigeo_secondary_review_nonce' ),
                'aiFeedNonce' => wp_create_nonce( 'qigeo_ai_feed_nonce' ),
                'generateText' => '生成 FAQ/摘要',
                'generatingText' => 'AI 生成中...',
                'saveText' => '保存到 FAQ 库',
                'savingText' => '保存中...',
                'copyingText' => '整理中...',
                'copyPackageText' => '复制人工投喂数据',
                'secondaryRunningText' => '分析中...',
                'secondaryIdleText' => '开始多模型分析',
                'aiFeedRunningText' => '投喂中...',
                'aiFeedIdleText' => '发送给指定 AI 平台',
                'lineDelimiterHint' => '每行一个 FAQ，格式：问题 || 回答',
            )
        );
    }

    public static function render_meta_box( $post ) {
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $latest = self::get_latest_item( $post->ID );
        $model_options = self::get_enabled_model_options();
        $settings = QiGeo_Settings::get_settings();
        $default_model_id = isset( $settings['default_model_id'] ) ? (string) $settings['default_model_id'] : '';

        $record_id = is_array( $latest ) && ! empty( $latest['id'] ) ? absint( $latest['id'] ) : 0;
        $summary = is_array( $latest ) && isset( $latest['summary'] ) ? (string) $latest['summary'] : '';
        $key_points_lines = self::get_key_points_lines_for_post( $post->ID, $latest );
        $seo_title = is_array( $latest ) && isset( $latest['seo_title'] ) ? sanitize_text_field( (string) $latest['seo_title'] ) : '';
        $seo_keywords = is_array( $latest ) && isset( $latest['seo_keywords'] ) ? self::normalize_keywords_input( $latest['seo_keywords'] ) : '';
        $seo_description = is_array( $latest ) && isset( $latest['seo_description'] ) ? sanitize_textarea_field( (string) $latest['seo_description'] ) : '';
        $use_ai_seo = (string) QiGeo_Analyzer::get_snapshot_value( $post->ID, QiGeo_Analyzer::META_USE_AI_SEO ) === '1';
        $source_url = self::get_article_source_url( $post->ID );
        if ( $source_url === '' && is_array( $latest ) && ! empty( $latest['post_url'] ) ) {
            $source_url = (string) $latest['post_url'];
        }

        $faq_lines = '';
        if ( is_array( $latest ) && ! empty( $latest['faq_json'] ) ) {
            $faq_lines = self::faq_to_lines( self::decode_json_array( (string) $latest['faq_json'] ) );
        }

        $suggestion_lines = '';
        if ( is_array( $latest ) && ! empty( $latest['suggestions_json'] ) ) {
            $suggestions = self::decode_json_array( (string) $latest['suggestions_json'] );
            $suggestion_lines = implode( "\n", self::normalize_string_list( $suggestions, 20 ) );
        }

        $selected_model_id = $default_model_id;
        if ( is_array( $latest ) && ! empty( $latest['model_id'] ) ) {
            $selected_model_id = (string) $latest['model_id'];
        }

        echo '<div class="qigeo-faq-library-box">';
        wp_nonce_field( 'qigeo_save_source_url_' . absint( $post->ID ), 'qigeo_source_url_nonce' );
        echo '<p class="description">文章编辑器下面只处理第一阶段和第二阶段：先做 AI 分析并修改保存正文，再生成 FAQ、摘要和推荐 SEO。</p>';

        echo '<p><label><strong>文章链接（来源）</strong></label></p>';
        echo '<input type="url" class="widefat qigeo-faq-source-url qigeo-source-url-input" name="qigeo_source_url" value="' . esc_attr( $source_url ) . '" />';
        echo '<p class="description">这里的链接会作为当前文章来源，供 GEO 分析、FAQ 库和后续投喂使用，避免后台每次临时解析文章地址。</p>';

        if ( class_exists( 'QiGeo_Admin_Post' ) ) {
            QiGeo_Admin_Post::render_analysis_panel( $post );
        }

        echo '<hr style="margin:14px 0;" />';
        echo '<p style="margin-top:0;"><strong>第一阶段补充：修改并保存文章</strong></p>';
        echo '<p class="description">根据上方分析结果补充事实、来源、结构和用户会问的问题。正文优化完成并保存后，再继续生成 FAQ 草稿。</p>';

        echo '<hr style="margin:14px 0;" />';
        echo '<p style="margin-top:0;"><strong>第二阶段：生成 FAQ、摘要和推荐 SEO</strong></p>';

        if ( empty( $model_options ) ) {
            echo '<p style="color:#b32d2e;"><strong>暂时无法使用：</strong>请先完成基础设置。</p>';
        }

        echo '<input type="hidden" class="qigeo-faq-record-id" value="' . esc_attr( (string) $record_id ) . '" />';

        echo '<p><label><strong>选择生成工具</strong></label><br />';
        echo '<select class="widefat qigeo-faq-model-id">';
        foreach ( $model_options as $option ) {
            $label = $option['provider_name'] . ' / ' . $option['model_label'] . ' (' . $option['model_name'] . ')';
            echo '<option value="' . esc_attr( $option['model_id'] ) . '" ' . selected( $selected_model_id, $option['model_id'], false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></p>';

        echo '<p><button type="button" class="button button-secondary qigeo-faq-generate-btn" data-post-id="' . esc_attr( (string) $post->ID ) . '">生成 FAQ/摘要</button></p>';
        echo '<p class="description">会读取当前已保存的文章内容，生成后可继续手动修改。</p>';
        echo '<p class="description qigeo-faq-generate-message"></p>';

        echo '<hr style="margin:12px 0;" />';

        echo '<p><label><strong>文章摘要（可修改）</strong></label></p>';
        echo '<textarea class="widefat qigeo-faq-summary" rows="4" placeholder="AI 生成后可手动调整摘要。">' . esc_textarea( $summary ) . '</textarea>';

        echo '<p><label><strong>核心要点（可修改）</strong></label></p>';
        echo '<textarea class="widefat qigeo-faq-key-points" rows="5" placeholder="每行一个核心要点，会进入 AI 投喂和人工复制内容。">' . esc_textarea( $key_points_lines ) . '</textarea>';

        echo '<p><label><strong>FAQ（可修改）</strong></label></p>';
        echo '<textarea class="widefat qigeo-faq-lines" rows="8" placeholder="每行一个 FAQ，格式：问题 || 回答">' . esc_textarea( $faq_lines ) . '</textarea>';

        echo '<p><label><strong>改进建议（可修改）</strong></label></p>';
        echo '<textarea class="widefat qigeo-faq-suggestions" rows="5" placeholder="每行一条建议。">' . esc_textarea( $suggestion_lines ) . '</textarea>';

        echo '<p><label><strong>推荐标题</strong></label></p>';
        echo '<div style="display:flex;gap:8px;align-items:center;">';
        echo '<input type="text" class="widefat qigeo-faq-seo-title" readonly maxlength="255" value="' . esc_attr( $seo_title ) . '" />';
        echo '<button type="button" class="button qigeo-copy-seo-btn" data-target=".qigeo-faq-seo-title">复制</button>';
        echo '</div>';

        echo '<p><label><strong>推荐关键词</strong></label></p>';
        echo '<div style="display:flex;gap:8px;align-items:center;">';
        echo '<input type="text" class="widefat qigeo-faq-seo-keywords" readonly value="' . esc_attr( $seo_keywords ) . '" />';
        echo '<button type="button" class="button qigeo-copy-seo-btn" data-target=".qigeo-faq-seo-keywords">复制</button>';
        echo '</div>';
        echo '<p class="description">最多 5 个关键词。</p>';

        echo '<p><label><strong>推荐描述</strong></label></p>';
        echo '<div style="display:flex;gap:8px;align-items:flex-start;">';
        echo '<textarea class="widefat qigeo-faq-seo-description" rows="3" readonly placeholder="建议 70-160 字。">' . esc_textarea( $seo_description ) . '</textarea>';
        echo '<button type="button" class="button qigeo-copy-seo-btn" data-target=".qigeo-faq-seo-description" style="margin-top:2px;">复制</button>';
        echo '</div>';

        echo '<p style="margin-top:10px;"><label><input type="checkbox" class="qigeo-faq-use-ai-seo" value="1" ' . checked( $use_ai_seo, true, false ) . ' /> 使用推荐 SEO</label></p>';
        echo '<p class="description">开启后会同步到当前文章。</p>';

        echo '<p><button type="button" class="button button-primary qigeo-faq-save-btn" data-post-id="' . esc_attr( (string) $post->ID ) . '">保存到 FAQ 库</button></p>';
        echo '<p class="description qigeo-faq-save-message"></p>';

        if ( is_array( $latest ) && ! empty( $latest['updated_at'] ) ) {
            echo '<p class="description">最近保存：#' . esc_html( (string) absint( $latest['id'] ) ) . '，更新时间 ' . esc_html( (string) $latest['updated_at'] ) . '</p>';
        }

        echo '<hr style="margin:16px 0;" />';
        echo '<p style="margin-top:0;"><strong>第三阶段：生成全网 GEO 引证分发包</strong></p>';
        echo '<p class="description">一键将文章论据改写为知乎答主版、小红书图文版与行业快讯，分发至外部高权重公域平台，形成全网实体锚定与 AI 环形召回。</p>';
        if ( class_exists( 'QiGeo_Offpage_Pack' ) ) {
            QiGeo_Offpage_Pack::render_post_box_section( $post );
        }

        echo '</div>';
    }

    public static function ajax_generate() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'qigeo_generate_faq_nonce', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( (string) $_POST['post_id'] ) ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '文章 ID 无效' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '无该文章编辑权限' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        if ( ! in_array( $post->post_type, self::allowed_post_types(), true ) ) {
            wp_send_json_error( array( 'message' => '当前文章类型未启用 FAQ 库' ) );
        }

        $model_id = isset( $_POST['model_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['model_id'] ) ) : '';
        $provider_model = self::resolve_provider_model( $model_id );
        if ( is_wp_error( $provider_model ) ) {
            wp_send_json_error( array( 'message' => $provider_model->get_error_message() ) );
        }

        $source_content = self::build_default_source_content( $post );
        $source_content = self::limit_text( $source_content, 12000 );
        $source_url = self::get_article_source_url( $post_id );

        $payload = array(
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_title' => wp_strip_all_tags( (string) get_the_title( $post_id ) ),
            'post_url' => $source_url,
            'post_excerpt' => wp_strip_all_tags( (string) $post->post_excerpt ),
            'selected_content' => $source_content,
        );

        $result = QiGeo_AI_Client::analyze_post(
            $provider_model['provider'],
            $provider_model['model'],
            self::default_prompt_template(),
            $payload,
            QiGeo_AI_Client::get_interactive_runtime()
        );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $normalized = self::normalize_generated_result( $result );
        $faq_lines = self::faq_to_lines( $normalized['faq'] );
        $key_points_lines = implode( "\n", $normalized['key_points'] );
        $suggestions_lines = implode( "\n", $normalized['suggestions'] );

        wp_send_json_success(
            array(
                'message' => 'AI 已完成整理，可继续手动修改后保存',
                'summary' => $normalized['summary'],
                'key_points_lines' => $key_points_lines,
                'faq_lines' => $faq_lines,
                'suggestions_lines' => $suggestions_lines,
                'seo_title' => $normalized['seo_title'],
                'seo_keywords' => $normalized['seo_keywords'],
                'seo_description' => $normalized['seo_description'],
                'source_content' => $source_content,
                'source_url' => $source_url,
                'model_id' => $provider_model['model_id'],
                'provider_id' => $provider_model['provider_id'],
            )
        );
    }

    public static function ajax_save() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'qigeo_save_faq_nonce', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( (string) $_POST['post_id'] ) ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => '文章 ID 无效' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '无该文章编辑权限' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        $record_id = isset( $_POST['record_id'] ) ? absint( wp_unslash( (string) $_POST['record_id'] ) ) : 0;
        $summary = isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '';
        $key_points_lines = isset( $_POST['key_points_lines'] ) ? wp_unslash( (string) $_POST['key_points_lines'] ) : '';
        $faq_lines = isset( $_POST['faq_lines'] ) ? wp_unslash( (string) $_POST['faq_lines'] ) : '';
        $suggestions_lines = isset( $_POST['suggestions_lines'] ) ? wp_unslash( (string) $_POST['suggestions_lines'] ) : '';
        $seo_title = isset( $_POST['seo_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['seo_title'] ) ) : '';
        $seo_keywords = isset( $_POST['seo_keywords'] ) ? self::normalize_keywords_input( wp_unslash( $_POST['seo_keywords'] ) ) : '';
        $seo_description = isset( $_POST['seo_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['seo_description'] ) ) : '';
        $use_ai_seo = isset( $_POST['use_ai_seo'] ) && (string) wp_unslash( $_POST['use_ai_seo'] ) === '1' ? '1' : '0';
        $model_id = isset( $_POST['model_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['model_id'] ) ) : '';

        $provider_model = self::resolve_provider_model( $model_id );
        if ( is_wp_error( $provider_model ) ) {
            wp_send_json_error( array( 'message' => $provider_model->get_error_message() ) );
        }

        $source_url = self::get_article_source_url( $post_id );

        $faq = self::parse_faq_lines( $faq_lines );
        $key_points = self::parse_plain_lines( $key_points_lines, 20 );
        $suggestions = self::parse_plain_lines( $suggestions_lines, 20 );

        if ( $summary === '' && empty( $faq ) ) {
            wp_send_json_error( array( 'message' => '请至少填写摘要或 FAQ 后再保存' ) );
        }

        global $wpdb;
        $table = self::get_table_name();
        $now = current_time( 'mysql' );

        $data = array(
            'post_id' => $post_id,
            'post_url' => $source_url,
            'provider_id' => $provider_model['provider_id'],
            'model_id' => $provider_model['model_id'],
            'model' => $provider_model['model_name'],
            'summary' => $summary,
            'key_points_json' => wp_json_encode( $key_points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'faq_json' => wp_json_encode( $faq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'suggestions_json' => wp_json_encode( $suggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'seo_title' => self::limit_text( $seo_title, 255 ),
            'seo_keywords' => self::limit_text( $seo_keywords, 200 ),
            'seo_description' => self::limit_text( $seo_description, 160 ),
            'updated_at' => $now,
        );
        $formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

        $saved_id = 0;
        if ( $record_id > 0 ) {
            $existing_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE id = %d AND post_id = %d LIMIT 1",
                    $record_id,
                    $post_id
                )
            );
            if ( $existing_id > 0 ) {
                $updated = $wpdb->update(
                    $table,
                    $data,
                    array( 'id' => $record_id ),
                    $formats,
                    array( '%d' )
                );
                if ( false !== $updated ) {
                    $saved_id = $record_id;
                }
            }
        }

        if ( $saved_id <= 0 ) {
            $data['created_by'] = get_current_user_id();
            $data['created_at'] = $now;
            $insert_formats = array_merge( $formats, array( '%d', '%s' ) );
            $inserted = $wpdb->insert( $table, $data, $insert_formats );
            if ( false === $inserted ) {
                wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
            }
            $saved_id = (int) $wpdb->insert_id;
        }

        self::sync_theme_seo_for_post(
            $post_id,
            $use_ai_seo === '1',
            array(
                'title' => $data['seo_title'],
                'keywords' => $data['seo_keywords'],
                'description' => $data['seo_description'],
            )
        );
        self::sync_snapshot_for_post(
            $post_id,
            $summary,
            $key_points,
            $faq,
            $suggestions,
            array(
                'seo_title' => $data['seo_title'],
                'seo_keywords' => $data['seo_keywords'],
                'seo_description' => $data['seo_description'],
            )
        );

        wp_send_json_success(
            array(
                'message' => 'FAQ 库保存成功',
                'record_id' => $saved_id,
                'updated_at' => $now,
            )
        );
    }

    public static function get_latest_item( $post_id ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return null;
        }

        $table = self::get_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT 1",
                $post_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    /**
     * 给微信插件调用：根据用户问题从 FAQ 库返回卡片回复信息
     *
     * @param string              $query 用户问题
     * @param array<string,mixed> $context 上下文（可选：model_id）
     * @return array<string,mixed>|WP_Error
     */
    public static function wechat_build_reply( $query, $context = array() ) {
        $query = sanitize_text_field( (string) $query );
        if ( $query === '' ) {
            return new WP_Error( 'qigeo_wechat_query_empty', '用户问题为空' );
        }

        $candidates = self::search_wechat_candidates( $query, 12 );
        if ( empty( $candidates ) ) {
            return new WP_Error( 'qigeo_wechat_no_candidates', 'FAQ 库暂无可用候选' );
        }

        $model_id = '';
        if ( isset( $context['model_id'] ) ) {
            $model_id = sanitize_key( (string) $context['model_id'] );
        }
        $provider_model = self::resolve_provider_model( $model_id );
        if ( is_wp_error( $provider_model ) ) {
            return $provider_model;
        }

        $payload_candidates = array();
        foreach ( $candidates as $item ) {
            $payload_candidates[] = array(
                'id' => isset( $item['id'] ) ? absint( $item['id'] ) : 0,
                'post_id' => isset( $item['post_id'] ) ? absint( $item['post_id'] ) : 0,
                'title' => isset( $item['post_title'] ) ? (string) $item['post_title'] : '',
                'url' => isset( $item['post_url'] ) ? (string) $item['post_url'] : '',
                'summary' => isset( $item['summary'] ) ? self::truncate_text( (string) $item['summary'], 220 ) : '',
                'faq_preview' => isset( $item['faq_preview'] ) ? (string) $item['faq_preview'] : '',
                'faq_items' => self::extract_faq_items( isset( $item['faq_json'] ) ? (string) $item['faq_json'] : '', 6 ),
            );
        }

        $payload = array(
            'user_query' => $query,
            'candidates' => $payload_candidates,
        );

        $runtime_options = array(
            'timeout' => 5,
            'retries' => 0,
            'enable_strict_json_mode' => true,
            'enable_json_repair_retry' => false,
        );

        $result = QiGeo_AI_Client::analyze_post(
            $provider_model['provider'],
            $provider_model['model'],
            self::wechat_prompt_template(),
            $payload,
            $runtime_options
        );
        if ( is_wp_error( $result ) ) {
            $fallback_model_ids = self::get_runtime_fallback_model_ids(
                isset( $provider_model['model_id'] ) ? (string) $provider_model['model_id'] : '',
                2
            );
            foreach ( $fallback_model_ids as $fallback_model_id ) {
                $fallback_packet = self::build_provider_model_packet( $fallback_model_id );
                if ( is_wp_error( $fallback_packet ) ) {
                    continue;
                }

                $fallback_result = QiGeo_AI_Client::analyze_post(
                    $fallback_packet['provider'],
                    $fallback_packet['model'],
                    self::wechat_prompt_template(),
                    $payload,
                    $runtime_options
                );
                if ( ! is_wp_error( $fallback_result ) ) {
                    $provider_model = $fallback_packet;
                    $result = $fallback_result;
                    break;
                }
                $result = $fallback_result;
            }
        }
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $parsed = isset( $result['parsed'] ) && is_array( $result['parsed'] ) ? $result['parsed'] : array();
        $match = false;
        if ( isset( $parsed['match'] ) ) {
            $match_raw = $parsed['match'];
            if ( is_bool( $match_raw ) ) {
                $match = $match_raw;
            } elseif ( is_numeric( $match_raw ) ) {
                $match = intval( $match_raw ) === 1;
            } else {
                $match = in_array(
                    strtolower( trim( (string) $match_raw ) ),
                    array( '1', 'true', 'yes', 'matched' ),
                    true
                );
            }
        }

        if ( ! $match ) {
            return new WP_Error( 'qigeo_wechat_no_match', 'FAQ 库未找到合适内容' );
        }

        $selected = self::select_candidate_from_ai( $parsed, $candidates );
        if ( ! is_array( $selected ) ) {
            return new WP_Error( 'qigeo_wechat_no_match', 'FAQ 库未找到合适内容' );
        }

        $url = isset( $selected['post_url'] ) ? esc_url_raw( (string) $selected['post_url'] ) : '';
        if ( $url === '' && ! empty( $selected['post_id'] ) ) {
            $url = self::get_article_source_url( absint( $selected['post_id'] ) );
        }
        if ( $url === '' ) {
            return new WP_Error( 'qigeo_wechat_missing_url', 'FAQ 记录缺少链接地址' );
        }

        $selected_faq_items = self::extract_faq_items( isset( $selected['faq_json'] ) ? (string) $selected['faq_json'] : '', 6 );
        $first_faq = self::extract_first_faq_item( $selected_faq_items );

        $faq_q = isset( $parsed['faq_q'] ) ? sanitize_text_field( (string) $parsed['faq_q'] ) : '';
        $faq_a = isset( $parsed['faq_a'] ) ? sanitize_textarea_field( (string) $parsed['faq_a'] ) : '';
        if ( $faq_q === '' && isset( $first_faq['q'] ) ) {
            $faq_q = sanitize_text_field( (string) $first_faq['q'] );
        }
        if ( $faq_a === '' && isset( $first_faq['a'] ) ) {
            $faq_a = sanitize_textarea_field( (string) $first_faq['a'] );
        }

        $summary_title = isset( $parsed['title'] ) ? sanitize_text_field( (string) $parsed['title'] ) : '';
        if ( $summary_title === '' ) {
            $summary_title = isset( $selected['post_title'] ) ? sanitize_text_field( (string) $selected['post_title'] ) : '相关推荐';
        }
        $summary_title = self::truncate_text( $summary_title, 28 );

        $summary_description = isset( $parsed['description'] ) ? sanitize_textarea_field( (string) $parsed['description'] ) : '';
        if ( $summary_description === '' ) {
            $summary_description = isset( $selected['summary'] ) ? sanitize_textarea_field( (string) $selected['summary'] ) : '';
        }
        if ( $summary_description === '' && ! empty( $selected['faq_preview'] ) ) {
            $summary_description = sanitize_textarea_field( (string) $selected['faq_preview'] );
        }
        if ( $summary_description === '' ) {
            $summary_description = $faq_a;
        }
        $summary_description = self::truncate_text( $summary_description, 110 );

        $answer_text = $faq_a;
        if ( $answer_text === '' ) {
            $answer_text = isset( $parsed['answer'] ) ? sanitize_textarea_field( (string) $parsed['answer'] ) : '';
        }
        if ( $answer_text === '' ) {
            $answer_text = $summary_description;
        }
        $answer_text = self::truncate_text( $answer_text, 200 );

        $faq_text = '';
        if ( $faq_q !== '' && $faq_a !== '' ) {
            $faq_text = "问题：{$faq_q}\n回答：{$faq_a}";
        } else {
            $faq_text = $answer_text;
        }
        $faq_text = self::truncate_text( $faq_text, 280 );

        $picurl = '';
        if ( ! empty( $selected['post_id'] ) ) {
            $thumb = get_the_post_thumbnail_url( absint( $selected['post_id'] ), 'medium' );
            if ( is_string( $thumb ) ) {
                $picurl = esc_url_raw( $thumb );
            }
        }

        return array(
            'record_id' => isset( $selected['id'] ) ? absint( $selected['id'] ) : 0,
            'card' => array(
                'title' => $summary_title,
                'description' => $summary_description,
                'url' => $url,
                'picurl' => $picurl,
            ),
            'summary_card' => array(
                'title' => $summary_title,
                'description' => $summary_description,
                'url' => $url,
                'picurl' => $picurl,
            ),
            'faq' => array(
                'q' => $faq_q,
                'a' => $faq_a,
            ),
            'faq_text' => $faq_text,
            'text' => $answer_text,
            'provider_id' => isset( $provider_model['provider_id'] ) ? (string) $provider_model['provider_id'] : '',
            'model_id' => isset( $provider_model['model_id'] ) ? (string) $provider_model['model_id'] : '',
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table = self::get_table_name();
        $table_exists = QiGeo_Admin_Helper::table_exists( $table );

        $admin_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
        $edit_id = isset( $_GET['id'] ) ? absint( wp_unslash( (string) $_GET['id'] ) ) : 0;
        self::handle_admin_post_actions( $table, $table_exists );
        $import_state = self::get_import_form_state( $table_exists );

        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
        $paged = isset( $_GET['paged'] ) ? absint( wp_unslash( (string) $_GET['paged'] ) ) : 1;
        if ( $paged < 1 ) {
            $paged = 1;
        }
        $per_page = 20;
        $offset = ( $paged - 1 ) * $per_page;

        $total = 0;
        $rows = array();
        if ( $table_exists ) {
            $query_parts = self::build_list_where( $search );
            $posts_table = $wpdb->posts;
            $count_sql = "SELECT COUNT(*) FROM {$table} f LEFT JOIN {$posts_table} p ON p.ID = f.post_id WHERE {$query_parts['where']}";
            if ( empty( $query_parts['params'] ) ) {
                $total = (int) $wpdb->get_var( $count_sql );
            } else {
                $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $query_parts['params'] ) );
            }

            $list_sql = "SELECT f.*, p.post_title AS live_post_title FROM {$table} f LEFT JOIN {$posts_table} p ON p.ID = f.post_id WHERE {$query_parts['where']} ORDER BY f.updated_at DESC, f.id DESC LIMIT %d OFFSET %d";
            $list_params = array_merge( $query_parts['params'], array( $per_page, $offset ) );
            $rows = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;
        $base_url = add_query_arg(
            array(
                'page' => 'qigeo-faq-library',
            ),
            admin_url( 'admin.php' )
        );
        $edit_item = null;
        if ( $table_exists && $admin_action === 'edit' && $edit_id > 0 ) {
            $edit_item = self::get_item_by_id( $edit_id );
        }
        ?>
        <div class="wrap qigeo-wrap">
            <h1>启灵 FAQ 库</h1>
            <p class="description">集中管理 FAQ 内容。</p>

            <?php if ( ! $table_exists ) : ?>
                <div class="notice notice-error"><p>当前页面数据暂未准备好，请刷新页面后重试。</p></div>
            <?php endif; ?>
            <?php
            $updated_flag = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( (string) $_GET['updated'] ) ) : '';
            $deleted_flag = isset( $_GET['deleted'] ) ? sanitize_key( wp_unslash( (string) $_GET['deleted'] ) ) : '';
            ?>
            <?php if ( $updated_flag === '1' ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p>FAQ 记录已更新。</p></div>
            <?php endif; ?>
            <?php if ( $deleted_flag === '1' ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p>FAQ 记录已删除。</p></div>
            <?php endif; ?>

            <?php if ( is_array( $import_state ) && ! empty( $import_state['page_notice'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( (string) $import_state['page_notice'] ); ?></p></div>
            <?php endif; ?>
            <?php if ( is_array( $edit_item ) ) : ?>
                <?php
                $edit_post_id = isset( $edit_item['post_id'] ) ? absint( $edit_item['post_id'] ) : 0;
                $edit_source_url = $edit_post_id > 0 ? self::get_article_source_url( $edit_post_id ) : '';
                $edit_source_url = $edit_source_url !== ''
                    ? $edit_source_url
                    : ( isset( $edit_item['post_url'] ) ? (string) $edit_item['post_url'] : '' );
                $edit_post_title = self::get_post_title_by_id( $edit_post_id );
                if ( $edit_post_title === '' ) {
                    $edit_post_title = isset( $edit_item['post_title'] ) ? sanitize_text_field( (string) $edit_item['post_title'] ) : '';
                }
                $edit_summary = isset( $edit_item['summary'] ) ? (string) $edit_item['summary'] : '';
                $edit_key_points_lines = self::get_key_points_lines_for_post( $edit_post_id, $edit_item );
                $edit_faq_lines = self::faq_to_lines( self::decode_json_array( isset( $edit_item['faq_json'] ) ? (string) $edit_item['faq_json'] : '' ) );
                $edit_suggestion_lines = implode( "\n", self::normalize_string_list( self::decode_json_array( isset( $edit_item['suggestions_json'] ) ? (string) $edit_item['suggestions_json'] : '' ), 50 ) );
                $edit_seo_title = isset( $edit_item['seo_title'] ) ? sanitize_text_field( (string) $edit_item['seo_title'] ) : '';
                $edit_seo_keywords = isset( $edit_item['seo_keywords'] ) ? self::normalize_keywords_input( $edit_item['seo_keywords'] ) : '';
                $edit_seo_description = isset( $edit_item['seo_description'] ) ? sanitize_textarea_field( (string) $edit_item['seo_description'] ) : '';
                $geo_source_label = $edit_post_id > 0 ? self::get_first_stage_source_label( $edit_post_id, true ) : '暂无第一阶段分析记录';
                $target_model_options = self::get_enabled_model_options();
                ?>
                <div class="postbox" style="max-width:100%;margin:14px 0;">
                    <div class="postbox-header"><h2 class="hndle">编辑 FAQ 记录 #<?php echo esc_html( (string) absint( $edit_item['id'] ) ); ?></h2></div>
                    <div class="inside">
                        <form method="post" class="qigeo-faq-library-box">
                            <?php wp_nonce_field( 'qigeo_faq_admin_update_' . absint( $edit_item['id'] ), 'qigeo_faq_admin_nonce' ); ?>
                            <input type="hidden" name="qigeo_faq_admin_action" value="update" />
                            <input type="hidden" name="id" value="<?php echo esc_attr( (string) absint( $edit_item['id'] ) ); ?>" />
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '文章标题', array( 'key' ) ) ); ?></th>
                                    <td><?php echo esc_html( $edit_post_title !== '' ? $edit_post_title : '文章 #' . $edit_post_id ); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">第一阶段分析来源</th>
                                    <td><?php echo esc_html( $geo_source_label ); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '文章链接', array( 'recommended' ) ) ); ?></th>
                                    <td>
                                        <input type="url" class="regular-text qigeo-source-url-input" name="qigeo_source_url" value="<?php echo esc_attr( $edit_source_url ); ?>" />
                                        <p class="description">第三阶段和第四阶段会优先使用这个链接。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '摘要', array( 'key' ) ) ); ?></th>
                                    <td><textarea name="summary" rows="4" class="large-text qigeo-faq-summary"><?php echo esc_textarea( $edit_summary ); ?></textarea></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '核心要点', array( 'key' ) ) ); ?></th>
                                    <td>
                                        <textarea name="key_points_lines" rows="5" class="large-text qigeo-faq-key-points" placeholder="每行一个核心要点"><?php echo esc_textarea( $edit_key_points_lines ); ?></textarea>
                                        <p class="description">这些要点会进入第三阶段A、第三阶段B和第四阶段人工复制的成品资料。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( 'FAQ', array( 'required', 'key' ) ) ); ?></th>
                                    <td><textarea name="faq_lines" rows="8" class="large-text qigeo-faq-lines" placeholder="每行：问题 || 回答"><?php echo esc_textarea( $edit_faq_lines ); ?></textarea></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '建议', array( 'recommended' ) ) ); ?></th>
                                    <td><textarea name="suggestions_lines" rows="6" class="large-text qigeo-faq-suggestions"><?php echo esc_textarea( $edit_suggestion_lines ); ?></textarea></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '推荐标题', array( 'recommended' ) ) ); ?></th>
                                    <td><input type="text" name="seo_title" maxlength="255" class="large-text qigeo-faq-seo-title" value="<?php echo esc_attr( $edit_seo_title ); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '推荐关键词', array( 'recommended' ) ) ); ?></th>
                                    <td><input type="text" name="seo_keywords" class="large-text qigeo-faq-seo-keywords" value="<?php echo esc_attr( $edit_seo_keywords ); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '推荐描述', array( 'recommended' ) ) ); ?></th>
                                    <td><textarea name="seo_description" rows="3" class="large-text qigeo-faq-seo-description"><?php echo esc_textarea( $edit_seo_description ); ?></textarea></td>
                                </tr>
                            </table>
                            <?php if ( $edit_post_id > 0 ) : ?>
                                <div class="qigeo-faq-geo-actions" style="border:1px solid #dcdcde;background:#fff;padding:12px;border-radius:4px;margin:14px 0;">
                                    <p style="margin-top:0;"><strong>第三、第四阶段：多模型分析、AI 投喂与人工复制</strong></p>
                                    <p class="description">这里读取上面的标题、关键词、描述、摘要、FAQ、文章链接，以及当前已保存的文章正文，组成已经整理好的成品资料。第三阶段A和第三阶段B互不替代，可以只做其中一个。</p>
                                    <?php if ( empty( $target_model_options ) ) : ?>
                                        <p style="color:#b32d2e;">暂无可用平台/模型，请先到启灵GEO设置里启用平台、填写 API Key 并启用模型。</p>
                                    <?php else : ?>
                                        <div style="border:1px solid #dcdcde;background:#f6f7f7;padding:10px;border-radius:4px;margin:10px 0;">
                                            <p style="margin-top:0;"><strong>第三阶段A：多模型分析（可选）</strong></p>
                                            <p class="description">把整理好的标题、关键词、描述、摘要、正文、FAQ 和文章链接发给多个模型，看它们分别给出什么分析结果。</p>
                                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px;margin:8px 0 12px;">
                                                <?php foreach ( $target_model_options as $option ) : ?>
                                                    <label style="display:block;border:1px solid #dcdcde;background:#fff;padding:8px;border-radius:4px;">
                                                        <input type="checkbox" class="qigeo-secondary-target-model" value="<?php echo esc_attr( $option['model_id'] ); ?>" />
                                                        <?php echo esc_html( $option['provider_name'] . ' / ' . $option['model_label'] . ' (' . $option['model_name'] . ')' ); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <p><button type="button" class="button button-secondary qigeo-secondary-review-btn" data-post-id="<?php echo esc_attr( (string) $edit_post_id ); ?>">多模型分析</button></p>
                                            <p class="description qigeo-secondary-review-message"></p>
                                        </div>

                                        <div style="border:1px solid #dcdcde;background:#f6f7f7;padding:10px;border-radius:4px;margin:10px 0;">
                                            <p style="margin-top:0;"><strong>第三阶段B：AI 投喂到指定平台</strong></p>
                                            <p class="description">把同一份成品资料发送给你勾选的平台。它和多模型分析是两个独立动作，可以单选，也可以多选。</p>
                                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px;margin:8px 0 12px;">
                                                <?php foreach ( $target_model_options as $option ) : ?>
                                                    <label style="display:block;border:1px solid #dcdcde;background:#fff;padding:8px;border-radius:4px;">
                                                        <input type="checkbox" class="qigeo-feed-target-model" value="<?php echo esc_attr( $option['model_id'] ); ?>" />
                                                        <?php echo esc_html( $option['provider_name'] . ' / ' . $option['model_label'] . ' (' . $option['model_name'] . ')' ); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <p><button type="button" class="button button-primary qigeo-ai-feed-btn" data-post-id="<?php echo esc_attr( (string) $edit_post_id ); ?>">推送指定AI</button></p>
                                            <p class="description qigeo-ai-feed-message"></p>
                                        </div>
                                    <?php endif; ?>

                                    <div style="border:1px solid #dcdcde;background:#f6f7f7;padding:10px;border-radius:4px;margin:10px 0;">
                                        <p style="margin-top:0;"><strong>第四阶段：人工复制投喂</strong></p>
                                        <p class="description">把同一份内容重新清理成人工对话更适合的格式，复制后手动发给豆包、通义、DeepSeek、OpenAI 等对话平台。</p>
                                        <p><button type="button" class="button qigeo-copy-package-btn" data-post-id="<?php echo esc_attr( (string) $edit_post_id ); ?>">复制投喂数据</button></p>
                                        <p class="description qigeo-copy-package-message"></p>
                                    </div>
                                    <?php self::render_secondary_review_results( $edit_post_id ); ?>
                                    <?php self::render_ai_feed_results( $edit_post_id ); ?>
                                </div>
                            <?php else : ?>
                                <div class="notice notice-warning inline"><p>这条 FAQ 记录没有关联文章，暂不能执行第三阶段或第四阶段。</p></div>
                            <?php endif; ?>
                            <p>
                                <button type="submit" class="button button-primary">保存修改</button>
                                <a href="<?php echo esc_url( $base_url ); ?>" class="button">返回列表</a>
                            </p>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( ! is_array( $edit_item ) && is_array( $import_state ) ) : ?>
                <?php self::render_import_form( $import_state, $base_url ); ?>
            <?php endif; ?>

            <form method="get" style="margin:12px 0;">
                <input type="hidden" name="page" value="qigeo-faq-library" />
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索标题/摘要/模型/SEO/文章ID" style="min-width:280px;" />
                <button type="submit" class="button">搜索</button>
                <a class="button button-secondary" href="<?php echo esc_url( $base_url ); ?>">重置</a>
            </form>

            <p>共 <strong><?php echo esc_html( (string) $total ); ?></strong> 条记录</p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:72px;">ID</th>
                        <th style="min-width:220px;">文章</th>
                        <th>摘要</th>
                        <th style="width:84px;">FAQ数</th>
                        <th style="min-width:180px;">AI建议</th>
                        <th style="min-width:170px;">AI模型</th>
                        <th style="min-width:140px;">更新时间</th>
                        <th style="width:170px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr>
                        <td colspan="8">暂无记录</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php
                        $row_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
                        $post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
                        $post_title = isset( $row['live_post_title'] ) ? sanitize_text_field( (string) $row['live_post_title'] ) : '';
                        if ( $post_title === '' ) {
                            $post_title = isset( $row['post_title'] ) ? sanitize_text_field( (string) $row['post_title'] ) : '';
                        }
                        if ( $post_title === '' && $post_id <= 0 ) {
                            $post_title = isset( $row['seo_title'] ) ? sanitize_text_field( (string) $row['seo_title'] ) : '';
                        }
                        $post_url = isset( $row['post_url'] ) ? (string) $row['post_url'] : '';
                        $summary = isset( $row['summary'] ) ? (string) $row['summary'] : '';
                        if ( $post_title === '' && $post_id <= 0 ) {
                            $post_title = self::excerpt_text( $summary, 36 );
                        }
                        if ( $post_title === '' && $post_id <= 0 ) {
                            $post_title = '独立 FAQ 资产';
                        }
                        $model = isset( $row['model'] ) ? (string) $row['model'] : '';
                        $model_id = isset( $row['model_id'] ) ? (string) $row['model_id'] : '';
                        $provider_id = isset( $row['provider_id'] ) ? (string) $row['provider_id'] : '';
                        $first_stage_source = $post_id > 0 ? self::get_first_stage_source_label( $post_id, false ) : '';
                        $updated_at = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '';
                        $faq_count = self::count_json_items( isset( $row['faq_json'] ) ? (string) $row['faq_json'] : '' );
                        $suggestion_lines = self::normalize_string_list( self::decode_json_array( isset( $row['suggestions_json'] ) ? (string) $row['suggestions_json'] : '' ), 50 );
                        $suggestion_excerpt = self::excerpt_text( implode( '；', $suggestion_lines ), 80 );
                        $summary_excerpt = self::excerpt_text( $summary, 120 );
                        $edit_url = $post_id > 0 ? get_edit_post_link( $post_id ) : '';
                        $record_edit_url = add_query_arg(
                            array(
                                'page' => 'qigeo-faq-library',
                                'action' => 'edit',
                                'id' => $row_id,
                            ),
                            admin_url( 'admin.php' )
                        );
                        ?>
                        <tr>
                            <td>#<?php echo esc_html( (string) $row_id ); ?></td>
                            <td>
                                <strong><?php echo esc_html( $post_title !== '' ? $post_title : ( '文章 #' . $post_id ) ); ?></strong>
                                <div style="margin-top:4px;color:#646970;"><?php echo esc_html( $post_id > 0 ? '文章编号：' . $post_id : '来源：独立 FAQ 内容' ); ?></div>
                                <?php if ( $first_stage_source !== '' ) : ?>
                                    <div style="margin-top:4px;color:#646970;">第一阶段：<?php echo esc_html( $first_stage_source ); ?></div>
                                <?php endif; ?>
                                <div style="margin-top:6px;">
                                    <?php if ( $post_url !== '' ) : ?>
                                        <a href="<?php echo esc_url( $post_url ); ?>" target="_blank" rel="noopener noreferrer">查看链接</a>
                                    <?php endif; ?>
                                    <?php if ( is_string( $edit_url ) && $edit_url !== '' ) : ?>
                                        <?php if ( $post_url !== '' ) : ?> | <?php endif; ?>
                                        <a href="<?php echo esc_url( $edit_url ); ?>">编辑文章</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo esc_html( $summary_excerpt ); ?></td>
                            <td><?php echo esc_html( (string) $faq_count ); ?></td>
                            <td><?php echo esc_html( $suggestion_excerpt ); ?></td>
                            <td>
                                <div><?php echo esc_html( $model !== '' ? $model : '-' ); ?></div>
                                <div style="margin-top:4px;color:#646970;">provider: <?php echo esc_html( $provider_id !== '' ? $provider_id : '-' ); ?></div>
                                <div style="color:#646970;">model_id: <?php echo esc_html( $model_id !== '' ? $model_id : '-' ); ?></div>
                            </td>
                            <td><?php echo esc_html( $updated_at !== '' ? $updated_at : '-' ); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url( $record_edit_url ); ?>">编辑/投喂</a>
                                <form method="post" style="display:inline-block;margin-left:6px;" onsubmit="return confirm('确认删除这条 FAQ 记录吗？');">
                                    <?php wp_nonce_field( 'qigeo_faq_admin_delete_' . $row_id, 'qigeo_faq_admin_nonce' ); ?>
                                    <input type="hidden" name="qigeo_faq_admin_action" value="delete" />
                                    <input type="hidden" name="id" value="<?php echo esc_attr( (string) $row_id ); ?>" />
                                    <button type="submit" class="button button-small">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav" style="margin-top:10px;">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post(
                            paginate_links(
                                array(
                                    'base' => add_query_arg(
                                        array(
                                            'page' => 'qigeo-faq-library',
                                            'paged' => '%#%',
                                            's' => $search !== '' ? $search : false,
                                        ),
                                        admin_url( 'admin.php' )
                                    ),
                                    'format' => '',
                                    'current' => $paged,
                                    'total' => $total_pages,
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                    'type' => 'plain',
                                )
                            )
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param string $table table
     * @param bool   $table_exists exists
     * @return void
     */
    private static function handle_admin_post_actions( $table, $table_exists ) {
        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
            : '';
        if ( ! $table_exists || $request_method !== 'POST' ) {
            return;
        }

        $action = isset( $_POST['qigeo_faq_admin_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['qigeo_faq_admin_action'] ) ) : '';
        $id = isset( $_POST['id'] ) ? absint( wp_unslash( (string) $_POST['id'] ) ) : 0;
        if ( $id <= 0 || ! in_array( $action, array( 'update', 'delete' ), true ) ) {
            return;
        }

        $nonce = isset( $_POST['qigeo_faq_admin_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['qigeo_faq_admin_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'qigeo_faq_admin_' . $action . '_' . $id ) ) {
            return;
        }

        global $wpdb;
        $item = self::get_item_by_id( $id );
        if ( ! is_array( $item ) ) {
            return;
        }

        if ( $action === 'delete' ) {
            $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
            $redirect = add_query_arg(
                array(
                    'page' => 'qigeo-faq-library',
                    'deleted' => '1',
                ),
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        $post_id = isset( $item['post_id'] ) ? absint( $item['post_id'] ) : 0;
        $source_url = isset( $_POST['qigeo_source_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['qigeo_source_url'] ) ) : '';
        if ( $source_url === '' && $post_id > 0 ) {
            $source_url = self::get_article_source_url( $post_id );
        }
        if ( $source_url === '' ) {
            $source_url = isset( $item['post_url'] ) ? (string) $item['post_url'] : '';
        }
        if ( $post_id > 0 && $source_url !== '' && class_exists( 'QiGeo_Analyzer' ) ) {
            QiGeo_Analyzer::update_article_source_url( $post_id, $source_url );
        }

        $summary = isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '';
        $key_points_lines = isset( $_POST['key_points_lines'] ) ? wp_unslash( (string) $_POST['key_points_lines'] ) : '';
        $faq_lines = isset( $_POST['faq_lines'] ) ? wp_unslash( (string) $_POST['faq_lines'] ) : '';
        $suggestions_lines = isset( $_POST['suggestions_lines'] ) ? wp_unslash( (string) $_POST['suggestions_lines'] ) : '';
        $seo_title = isset( $_POST['seo_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['seo_title'] ) ) : '';
        $seo_keywords = isset( $_POST['seo_keywords'] ) ? self::normalize_keywords_input( wp_unslash( $_POST['seo_keywords'] ) ) : '';
        $seo_description = isset( $_POST['seo_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['seo_description'] ) ) : '';

        $faq = self::parse_faq_lines( $faq_lines );
        $key_points = self::parse_plain_lines( $key_points_lines, 20 );
        $suggestions = self::parse_plain_lines( $suggestions_lines, 50 );

        $wpdb->update(
            $table,
            array(
                'post_url' => $source_url,
                'summary' => $summary,
                'key_points_json' => wp_json_encode( $key_points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'faq_json' => wp_json_encode( $faq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'suggestions_json' => wp_json_encode( $suggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'seo_title' => self::limit_text( $seo_title, 255 ),
                'seo_keywords' => self::limit_text( $seo_keywords, 200 ),
                'seo_description' => self::limit_text( $seo_description, 160 ),
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
        self::sync_snapshot_for_post(
            $post_id,
            $summary,
            $key_points,
            $faq,
            $suggestions,
            array(
                'seo_title' => self::limit_text( $seo_title, 255 ),
                'seo_keywords' => self::limit_text( $seo_keywords, 200 ),
                'seo_description' => self::limit_text( $seo_description, 160 ),
            )
        );

        $redirect = add_query_arg(
            array(
                'page' => 'qigeo-faq-library',
                'action' => 'edit',
                'id' => $id,
                'updated' => '1',
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * @param bool $table_exists table exists
     * @return array<string,mixed>|null
     */
    private static function get_import_form_state( $table_exists ) {
        $import_context = self::get_import_source_context();
        if ( is_wp_error( $import_context ) ) {
            return array(
                'source_type' => '',
                'source_record_id' => 0,
                'source_label' => '外部 FAQ 草稿',
                'source_title' => '',
                'source_url' => '',
                'source_content' => '',
                'model_id' => self::get_first_usable_model_id(),
                'summary' => '',
                'key_points_lines' => '',
                'faq_lines' => '',
                'suggestions_lines' => '',
                'seo_title' => '',
                'seo_keywords' => '',
                'seo_description' => '',
                'notice' => '',
                'error' => $import_context->get_error_message(),
                'page_notice' => '',
            );
        }

        if ( ! is_array( $import_context ) ) {
            return null;
        }

        $state = array_merge(
            array(
                'source_type' => '',
                'source_record_id' => 0,
                'source_label' => '',
                'source_title' => '',
                'source_url' => '',
                'source_content' => '',
                'model_id' => self::get_first_usable_model_id(),
                'summary' => '',
                'faq_lines' => '',
                'suggestions_lines' => '',
                'seo_title' => '',
                'seo_keywords' => '',
                'seo_description' => '',
                'notice' => '',
                'error' => '',
                'page_notice' => '',
            ),
            $import_context
        );

        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
            : '';
        $action = isset( $_POST['qigeo_faq_admin_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['qigeo_faq_admin_action'] ) ) : '';
        if ( $request_method !== 'POST' || ! in_array( $action, array( 'generate_import', 'create_import' ), true ) ) {
            return $state;
        }

        $nonce = isset( $_POST['qigeo_faq_import_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['qigeo_faq_import_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'qigeo_faq_import_action' ) ) {
            $state['error'] = '请求已过期，请刷新页面后重试。';
            return $state;
        }

        $posted_source_type = isset( $_POST['source_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['source_type'] ) ) : '';
        $posted_source_record_id = isset( $_POST['source_record_id'] ) ? absint( wp_unslash( (string) $_POST['source_record_id'] ) ) : 0;
        if ( $posted_source_type !== (string) $state['source_type'] || $posted_source_record_id !== (int) $state['source_record_id'] ) {
            $state['error'] = '来源记录已变化，请重新打开导入入口后再试。';
            return $state;
        }

        $state['model_id'] = isset( $_POST['model_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['model_id'] ) ) : (string) $state['model_id'];
        $state['summary'] = isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : (string) $state['summary'];
        $state['key_points_lines'] = isset( $_POST['key_points_lines'] ) ? wp_unslash( (string) $_POST['key_points_lines'] ) : (string) $state['key_points_lines'];
        $state['faq_lines'] = isset( $_POST['faq_lines'] ) ? wp_unslash( (string) $_POST['faq_lines'] ) : (string) $state['faq_lines'];
        $state['suggestions_lines'] = isset( $_POST['suggestions_lines'] ) ? wp_unslash( (string) $_POST['suggestions_lines'] ) : (string) $state['suggestions_lines'];
        $state['seo_title'] = isset( $_POST['seo_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['seo_title'] ) ) : (string) $state['seo_title'];
        $state['seo_keywords'] = isset( $_POST['seo_keywords'] ) ? self::normalize_keywords_input( wp_unslash( $_POST['seo_keywords'] ) ) : (string) $state['seo_keywords'];
        $state['seo_description'] = isset( $_POST['seo_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['seo_description'] ) ) : (string) $state['seo_description'];

        if ( $action === 'generate_import' ) {
            $provider_model = self::resolve_provider_model( (string) $state['model_id'] );
            if ( is_wp_error( $provider_model ) ) {
                $state['error'] = $provider_model->get_error_message();
                return $state;
            }

            $payload = array(
                'post_id' => 0,
                'post_type' => (string) $state['source_type'],
                'post_title' => (string) $state['source_title'],
                'post_url' => (string) $state['source_url'],
                'post_excerpt' => '',
                'selected_content' => self::limit_text( (string) $state['source_content'], 12000 ),
            );

            $result = QiGeo_AI_Client::analyze_post(
                $provider_model['provider'],
                $provider_model['model'],
                self::default_prompt_template(),
                $payload,
                QiGeo_AI_Client::get_interactive_runtime()
            );
            if ( is_wp_error( $result ) ) {
                $state['error'] = $result->get_error_message();
                return $state;
            }

            $normalized = self::normalize_generated_result( $result );
            $state['summary'] = isset( $normalized['summary'] ) ? (string) $normalized['summary'] : '';
            $state['key_points_lines'] = implode( "\n", isset( $normalized['key_points'] ) && is_array( $normalized['key_points'] ) ? $normalized['key_points'] : array() );
            $state['faq_lines'] = self::faq_to_lines( isset( $normalized['faq'] ) ? $normalized['faq'] : array() );
            $state['suggestions_lines'] = implode( "\n", isset( $normalized['suggestions'] ) && is_array( $normalized['suggestions'] ) ? $normalized['suggestions'] : array() );
            $state['seo_title'] = isset( $normalized['seo_title'] ) && (string) $normalized['seo_title'] !== '' ? (string) $normalized['seo_title'] : (string) $state['source_title'];
            $state['seo_keywords'] = isset( $normalized['seo_keywords'] ) ? (string) $normalized['seo_keywords'] : (string) $state['seo_keywords'];
            $state['seo_description'] = isset( $normalized['seo_description'] ) ? (string) $normalized['seo_description'] : (string) $state['seo_description'];
            $state['notice'] = '已根据来源素材生成 FAQ 草稿，你可以微调后再保存。';
            return $state;
        }

        if ( ! $table_exists ) {
            $state['error'] = 'FAQ 库数据表尚未创建，请先重新启用插件或进入设置页触发升级。';
            return $state;
        }

        $provider_model = self::resolve_provider_model( (string) $state['model_id'] );
        if ( is_wp_error( $provider_model ) ) {
            $state['error'] = $provider_model->get_error_message();
            return $state;
        }

        $faq = self::parse_faq_lines( (string) $state['faq_lines'] );
        $key_points = self::parse_plain_lines( (string) $state['key_points_lines'], 20 );
        $suggestions = self::parse_plain_lines( (string) $state['suggestions_lines'], 50 );
        if ( $state['summary'] === '' && empty( $faq ) ) {
            $state['error'] = '请先生成或填写摘要 / FAQ 后再保存。';
            return $state;
        }

        global $wpdb;
        $table = self::get_table_name();
        $now = current_time( 'mysql' );
        $inserted = $wpdb->insert(
            $table,
            array(
                'post_id' => 0,
                'post_url' => (string) $state['source_url'],
                'provider_id' => isset( $provider_model['provider_id'] ) ? (string) $provider_model['provider_id'] : '',
                'model_id' => isset( $provider_model['model_id'] ) ? (string) $provider_model['model_id'] : '',
                'model' => isset( $provider_model['model_name'] ) ? (string) $provider_model['model_name'] : '',
                'summary' => (string) $state['summary'],
                'key_points_json' => wp_json_encode( $key_points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'faq_json' => wp_json_encode( $faq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'suggestions_json' => wp_json_encode( $suggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'seo_title' => self::limit_text( (string) $state['seo_title'], 255 ),
                'seo_keywords' => self::limit_text( (string) $state['seo_keywords'], 200 ),
                'seo_description' => self::limit_text( (string) $state['seo_description'], 160 ),
                'created_by' => get_current_user_id(),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        if ( false === $inserted ) {
            $state['error'] = 'FAQ 草稿保存失败，请稍后重试。';
            return $state;
        }

        $redirect = add_query_arg(
            array(
                'page' => 'qigeo-faq-library',
                'action' => 'edit',
                'id' => absint( $wpdb->insert_id ),
                'updated' => '1',
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * @return array<string,mixed>|WP_Error|null
     */
    private static function get_import_source_context() {
        $source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( (string) $_GET['source'] ) ) : '';
        if ( $source === '' ) {
            return null;
        }

        if ( $source === 'brand_entity' ) {
            $record_id = isset( $_GET['brand_entity_id'] ) ? absint( wp_unslash( (string) $_GET['brand_entity_id'] ) ) : 0;
            if ( $record_id < 1 ) {
                return new WP_Error( 'qigeo_faq_import_brand_missing', '品牌实体记录 ID 无效。' );
            }
            return self::get_brand_entity_import_context( $record_id );
        }

        if ( $source === 'evidence_library' ) {
            $record_id = isset( $_GET['evidence_id'] ) ? absint( wp_unslash( (string) $_GET['evidence_id'] ) ) : 0;
            if ( $record_id < 1 ) {
                return new WP_Error( 'qigeo_faq_import_evidence_missing', '证据库记录 ID 无效。' );
            }
            return self::get_evidence_library_import_context( $record_id );
        }

        return new WP_Error( 'qigeo_faq_import_source_invalid', '暂不支持当前来源。' );
    }

    /**
     * @param int $record_id record id
     * @return array<string,mixed>|WP_Error
     */
    private static function get_brand_entity_import_context( $record_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'qigeo_brand_entity_library';
        $table_exists = QiGeo_Admin_Helper::table_exists( $table );
        if ( ! $table_exists ) {
            return new WP_Error( 'qigeo_faq_import_brand_table_missing', '品牌实体库数据表尚未创建。' );
        }

        $record = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d LIMIT 1', $record_id ), ARRAY_A );
        if ( ! is_array( $record ) ) {
            return new WP_Error( 'qigeo_faq_import_brand_not_found', '指定的品牌实体记录不存在。' );
        }

        $result = json_decode( isset( $record['result_json'] ) ? (string) $record['result_json'] : '', true );
        if ( ! is_array( $result ) ) {
            $result = array();
        }

        $brand_name = isset( $record['brand_name'] ) ? sanitize_text_field( (string) $record['brand_name'] ) : '';
        $target_keyword = isset( $record['target_keyword'] ) ? sanitize_text_field( (string) $record['target_keyword'] ) : '';
        $source_title = $brand_name !== '' ? $brand_name . ' FAQ 草稿' : ( $target_keyword !== '' ? $target_keyword . ' FAQ 草稿' : '品牌实体 FAQ 草稿' );
        $summary = isset( $record['result_summary'] ) ? sanitize_textarea_field( (string) $record['result_summary'] ) : '';
        if ( $summary === '' && isset( $result['entity_definition'] ) ) {
            $summary = sanitize_textarea_field( (string) $result['entity_definition'] );
        }

        $suggestions = self::normalize_string_list(
            array_merge(
                isset( $result['naming_rules'] ) && is_array( $result['naming_rules'] ) ? $result['naming_rules'] : array(),
                isset( $result['forbidden_claims'] ) && is_array( $result['forbidden_claims'] ) ? $result['forbidden_claims'] : array()
            ),
            10
        );

        return array(
            'source_type' => 'brand_entity',
            'source_record_id' => $record_id,
            'source_label' => '品牌实体记录 #' . $record_id,
            'source_title' => $source_title,
            'source_url' => self::build_import_source_url(
                'qigeo-brand-entity-library',
                $record_id,
                isset( $record['official_site'] ) ? esc_url_raw( (string) $record['official_site'] ) : ''
            ),
            'source_content' => self::compose_import_source_content(
                array(
                    '来源' => '品牌实体库 #' . $record_id,
                    '品牌 / 产品名' => $brand_name,
                    '希望被推荐的词' => $target_keyword,
                    '官网 / 主站' => isset( $record['official_site'] ) ? (string) $record['official_site'] : '',
                    '品牌别名' => isset( $record['brand_aliases'] ) ? (string) $record['brand_aliases'] : '',
                    '核心产品 / 服务' => isset( $record['core_products'] ) ? (string) $record['core_products'] : '',
                    '目标人群 / 使用场景' => isset( $record['audience_persona'] ) ? (string) $record['audience_persona'] : '',
                    '目标 AI 平台' => isset( $record['target_platforms'] ) ? (string) $record['target_platforms'] : '',
                    '标准介绍 / 自我定义' => isset( $record['standard_description'] ) ? (string) $record['standard_description'] : '',
                    '公开证据 / 现有素材' => isset( $record['evidence_summary'] ) ? (string) $record['evidence_summary'] : '',
                    '常见对比对象' => isset( $record['comparison_entities'] ) ? (string) $record['comparison_entities'] : '',
                    '实体定义' => isset( $result['entity_definition'] ) ? (string) $result['entity_definition'] : '',
                    '核心事实' => isset( $result['canonical_facts'] ) ? $result['canonical_facts'] : array(),
                    '推荐命名规则' => isset( $result['naming_rules'] ) ? $result['naming_rules'] : array(),
                    'FAQ 事实点' => isset( $result['faq_facts'] ) ? $result['faq_facts'] : array(),
                    '引用来源' => isset( $result['citation_sources'] ) ? $result['citation_sources'] : array(),
                    '不宜表述' => isset( $result['forbidden_claims'] ) ? $result['forbidden_claims'] : array(),
                    '备注' => isset( $record['notes'] ) ? (string) $record['notes'] : '',
                )
            ),
            'summary' => $summary,
            'faq_lines' => '',
            'suggestions_lines' => implode( "\n", $suggestions ),
            'seo_title' => self::limit_text( $source_title, 255 ),
            'seo_keywords' => self::normalize_keywords_input(
                array(
                    $brand_name,
                    $target_keyword,
                    isset( $record['core_products'] ) ? (string) $record['core_products'] : '',
                )
            ),
            'seo_description' => self::limit_text( $summary, 160 ),
            'notice' => '已从品牌实体记录带入口径与事实点，可直接生成 FAQ 草稿。',
            'error' => '',
            'page_notice' => '',
        );
    }

    /**
     * @param int $record_id record id
     * @return array<string,mixed>|WP_Error
     */
    private static function get_evidence_library_import_context( $record_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'qigeo_evidence_library';
        $table_exists = QiGeo_Admin_Helper::table_exists( $table );
        if ( ! $table_exists ) {
            return new WP_Error( 'qigeo_faq_import_evidence_table_missing', '证据库数据表尚未创建。' );
        }

        $record = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d LIMIT 1', $record_id ), ARRAY_A );
        if ( ! is_array( $record ) ) {
            return new WP_Error( 'qigeo_faq_import_evidence_not_found', '指定的证据库记录不存在。' );
        }

        $result = json_decode( isset( $record['result_json'] ) ? (string) $record['result_json'] : '', true );
        if ( ! is_array( $result ) ) {
            $result = array();
        }

        $seed_keyword = isset( $record['seed_keyword'] ) ? sanitize_text_field( (string) $record['seed_keyword'] ) : '';
        $target_keyword = isset( $record['target_keyword'] ) ? sanitize_text_field( (string) $record['target_keyword'] ) : '';
        $brand_name = isset( $record['brand_name'] ) ? sanitize_text_field( (string) $record['brand_name'] ) : '';
        $source_title = $target_keyword !== '' ? $target_keyword . ' FAQ 草稿' : ( $brand_name !== '' ? $brand_name . ' FAQ 草稿' : '证据库 FAQ 草稿' );
        $summary = isset( $record['result_summary'] ) ? sanitize_textarea_field( (string) $record['result_summary'] ) : '';
        if ( $summary === '' && isset( $result['strategy_summary'] ) ) {
            $summary = sanitize_textarea_field( (string) $result['strategy_summary'] );
        }

        $suggestions = self::normalize_string_list(
            array_merge(
                isset( $result['public_asset_plan'] ) && is_array( $result['public_asset_plan'] ) ? $result['public_asset_plan'] : array(),
                isset( $result['risk_flags'] ) && is_array( $result['risk_flags'] ) ? $result['risk_flags'] : array()
            ),
            10
        );

        return array(
            'source_type' => 'evidence_library',
            'source_record_id' => $record_id,
            'source_label' => '证据库记录 #' . $record_id,
            'source_title' => $source_title,
            'source_url' => self::build_import_source_url( 'qigeo-evidence-library', $record_id ),
            'source_content' => self::compose_import_source_content(
                array(
                    '来源' => '证据库 #' . $record_id,
                    '核心主题词 / 种子词' => $seed_keyword,
                    '目标 AI 平台' => isset( $record['target_platform'] ) ? (string) $record['target_platform'] : '',
                    '希望被推荐的词' => $target_keyword,
                    '品牌 / 产品名' => $brand_name,
                    '目标人群 / 使用场景' => isset( $record['audience_persona'] ) ? (string) $record['audience_persona'] : '',
                    '重点问法 / 关键问题' => isset( $record['priority_questions'] ) ? (string) $record['priority_questions'] : '',
                    '核心卖点 / 待证明主张' => isset( $record['value_claims'] ) ? (string) $record['value_claims'] : '',
                    '已有公开证据 / 公开资产' => isset( $record['existing_evidence'] ) ? (string) $record['existing_evidence'] : '',
                    '用户顾虑 / 竞品压力点' => isset( $record['concern_points'] ) ? (string) $record['concern_points'] : '',
                    '证据策略' => isset( $result['strategy_summary'] ) ? (string) $result['strategy_summary'] : '',
                    '关键主张' => isset( $result['key_claims'] ) ? $result['key_claims'] : array(),
                    '已有可用证据' => isset( $result['current_evidence'] ) ? $result['current_evidence'] : array(),
                    '缺口证据' => isset( $result['missing_evidence'] ) ? $result['missing_evidence'] : array(),
                    '公开资产计划' => isset( $result['public_asset_plan'] ) ? $result['public_asset_plan'] : array(),
                    '结构化与引用辅助' => isset( $result['structured_data_plan'] ) ? $result['structured_data_plan'] : array(),
                    '高风险表达' => isset( $result['risk_flags'] ) ? $result['risk_flags'] : array(),
                    '执行顺序' => isset( $result['execution_steps'] ) ? $result['execution_steps'] : array(),
                    '备注' => isset( $record['notes'] ) ? (string) $record['notes'] : '',
                )
            ),
            'summary' => $summary,
            'faq_lines' => '',
            'suggestions_lines' => implode( "\n", $suggestions ),
            'seo_title' => self::limit_text( $source_title, 255 ),
            'seo_keywords' => self::normalize_keywords_input(
                array(
                    $seed_keyword,
                    $target_keyword,
                    $brand_name,
                )
            ),
            'seo_description' => self::limit_text( $summary, 160 ),
            'notice' => '已从证据库记录带入主张、证据与风险点，可直接生成 FAQ 草稿。',
            'error' => '',
            'page_notice' => '',
        );
    }

    /**
     * @param string $page page
     * @param int    $record_id id
     * @param string $fallback fallback
     * @return string
     */
    private static function build_import_source_url( $page, $record_id, $fallback = '' ) {
        $fallback = esc_url_raw( (string) $fallback );
        if ( $fallback !== '' ) {
            return $fallback;
        }

        $record_id = absint( $record_id );
        if ( $record_id < 1 ) {
            return '';
        }

        return add_query_arg(
            array(
                'page' => sanitize_key( $page ),
                'action' => 'edit',
                'id' => $record_id,
            ),
            admin_url( 'admin.php' )
        );
    }

    /**
     * @param array<string,mixed> $sections sections
     * @return string
     */
    private static function compose_import_source_content( $sections ) {
        $blocks = array();

        foreach ( $sections as $label => $content ) {
            if ( is_array( $content ) ) {
                $value = implode( "\n", self::normalize_string_list( $content, 20 ) );
            } else {
                $value = sanitize_textarea_field( (string) $content );
            }

            $value = trim( $value );
            if ( $value === '' ) {
                continue;
            }

            $blocks[] = sanitize_text_field( (string) $label ) . ":\n" . $value;
        }

        return self::limit_text( implode( "\n\n", $blocks ), 12000 );
    }

    /**
     * @param array<string,mixed> $state state
     * @param string              $base_url base url
     * @return void
     */
    private static function render_import_form( $state, $base_url ) {
        $model_options = self::get_enabled_model_options();
        $source_label = isset( $state['source_label'] ) ? (string) $state['source_label'] : '外部 FAQ 草稿';
        $source_url = isset( $state['source_url'] ) ? (string) $state['source_url'] : '';
        ?>
        <div class="postbox" style="max-width:100%;margin:14px 0;">
            <div class="postbox-header"><h2 class="hndle">新建 FAQ 草稿</h2></div>
            <div class="inside">
                <p class="description">根据现有内容生成 FAQ 草稿。</p>

                <?php if ( ! empty( $state['notice'] ) ) : ?>
                    <div class="notice notice-success inline"><p><?php echo esc_html( (string) $state['notice'] ); ?></p></div>
                <?php endif; ?>
                <?php if ( ! empty( $state['error'] ) ) : ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html( (string) $state['error'] ); ?></p></div>
                <?php endif; ?>
                <?php if ( empty( $model_options ) ) : ?>
                    <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field( 'qigeo_faq_import_action', 'qigeo_faq_import_nonce' ); ?>
                    <input type="hidden" name="source_type" value="<?php echo esc_attr( isset( $state['source_type'] ) ? (string) $state['source_type'] : '' ); ?>" />
                    <input type="hidden" name="source_record_id" value="<?php echo esc_attr( (string) ( isset( $state['source_record_id'] ) ? absint( $state['source_record_id'] ) : 0 ) ); ?>" />
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '来源', array( 'key' ) ) ); ?></th>
                            <td>
                                <strong><?php echo esc_html( $source_label ); ?></strong>
                                <?php if ( $source_url !== '' ) : ?>
                                    <div style="margin-top:6px;"><a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer">查看来源</a></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '生成工具', array( 'recommended' ) ) ); ?></th>
                            <td>
                                <select name="model_id" class="regular-text">
                                    <?php foreach ( $model_options as $option ) : ?>
                                        <?php
                                        $option_model_id = isset( $option['model_id'] ) ? (string) $option['model_id'] : '';
                                        $option_label = ( isset( $option['provider_name'] ) ? (string) $option['provider_name'] : '-' )
                                            . ' / '
                                            . ( isset( $option['model_label'] ) ? (string) $option['model_label'] : $option_model_id )
                                            . ' ('
                                            . ( isset( $option['model_name'] ) ? (string) $option['model_name'] : '-' )
                                            . ')';
                                        ?>
                                        <option value="<?php echo esc_attr( $option_model_id ); ?>" <?php selected( isset( $state['model_id'] ) ? (string) $state['model_id'] : '', $option_model_id ); ?>><?php echo esc_html( $option_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( 'FAQ 标题', array( 'key' ) ) ); ?></th>
                            <td><input type="text" class="large-text" value="<?php echo esc_attr( isset( $state['source_title'] ) ? (string) $state['source_title'] : '' ); ?>" readonly /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '素材预览', array( 'key' ) ) ); ?></th>
                            <td><textarea rows="10" class="large-text" readonly><?php echo esc_textarea( isset( $state['source_content'] ) ? (string) $state['source_content'] : '' ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '摘要', array( 'key' ) ) ); ?></th>
                            <td><textarea name="summary" rows="4" class="large-text"><?php echo esc_textarea( isset( $state['summary'] ) ? (string) $state['summary'] : '' ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '核心要点', array( 'key' ) ) ); ?></th>
                            <td>
                                <textarea name="key_points_lines" rows="5" class="large-text" placeholder="每行一个核心要点"><?php echo esc_textarea( isset( $state['key_points_lines'] ) ? (string) $state['key_points_lines'] : '' ); ?></textarea>
                                <p class="description">这些要点会保存到 FAQ 库，并进入后续多模型分析、AI 投喂和人工复制内容。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( 'FAQ', array( 'required', 'key' ) ) ); ?></th>
                            <td><textarea name="faq_lines" rows="8" class="large-text" placeholder="每行：问题 || 回答"><?php echo esc_textarea( isset( $state['faq_lines'] ) ? (string) $state['faq_lines'] : '' ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '建议', array( 'recommended' ) ) ); ?></th>
                            <td><textarea name="suggestions_lines" rows="6" class="large-text"><?php echo esc_textarea( isset( $state['suggestions_lines'] ) ? (string) $state['suggestions_lines'] : '' ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '推荐标题', array( 'recommended' ) ) ); ?></th>
                            <td><input type="text" name="seo_title" maxlength="255" class="large-text" value="<?php echo esc_attr( isset( $state['seo_title'] ) ? (string) $state['seo_title'] : '' ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '推荐关键词', array( 'recommended' ) ) ); ?></th>
                            <td><input type="text" name="seo_keywords" class="large-text" value="<?php echo esc_attr( isset( $state['seo_keywords'] ) ? (string) $state['seo_keywords'] : '' ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '推荐描述', array( 'recommended' ) ) ); ?></th>
                            <td><textarea name="seo_description" rows="3" class="large-text"><?php echo esc_textarea( isset( $state['seo_description'] ) ? (string) $state['seo_description'] : '' ); ?></textarea></td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" class="button button-secondary" name="qigeo_faq_admin_action" value="generate_import">生成 FAQ 草稿</button>
                        <button type="submit" class="button button-primary" name="qigeo_faq_admin_action" value="create_import">保存到 FAQ 库</button>
                        <a href="<?php echo esc_url( $base_url ); ?>" class="button">取消</a>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * @param int $id id
     * @return array<string,mixed>|null
     */
    private static function get_item_by_id( $id ) {
        global $wpdb;
        $id = absint( $id );
        if ( $id <= 0 ) {
            return null;
        }

        $table = self::get_table_name();
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        return is_array( $item ) ? $item : null;
    }

    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'qigeo_faq_library';
    }

    private static function get_article_source_url( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return '';
        }

        if ( class_exists( 'QiGeo_Analyzer' ) ) {
            return QiGeo_Analyzer::get_article_source_url( $post_id, true );
        }

        $permalink = get_permalink( $post_id );
        return is_string( $permalink ) ? esc_url_raw( $permalink ) : '';
    }

    private static function get_key_points_lines_for_post( $post_id, $item = null ) {
        $points = array();
        if ( is_array( $item ) && ! empty( $item['key_points_json'] ) ) {
            $points = self::normalize_string_list( self::decode_json_array( (string) $item['key_points_json'] ), 20 );
        }

        $post_id = absint( $post_id );
        if ( empty( $points ) && $post_id > 0 && class_exists( 'QiGeo_Analyzer' ) ) {
            $raw = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_KEY_POINTS_JSON );
            if ( $raw !== '' ) {
                $points = self::normalize_string_list( self::decode_json_array( $raw ), 20 );
            }
        }

        return implode( "\n", $points );
    }

    private static function get_first_stage_source_label( $post_id, $include_status = false ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 || ! class_exists( 'QiGeo_Analyzer' ) ) {
            return '暂无第一阶段分析记录';
        }

        $record = $include_status ? QiGeo_Analyzer::get_latest_record( $post_id ) : null;
        $provider_id = is_array( $record ) && isset( $record['provider_id'] ) ? (string) $record['provider_id'] : '';
        $model = is_array( $record ) && isset( $record['model'] ) ? (string) $record['model'] : '';
        $status = is_array( $record ) && isset( $record['status'] ) ? (string) $record['status'] : '';
        $created = is_array( $record ) && isset( $record['created_at'] ) ? (string) $record['created_at'] : '';

        if ( $provider_id === '' ) {
            $provider_id = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_PROVIDER_ID );
        }
        if ( $model === '' ) {
            $model = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_MODEL );
        }

        $provider = $provider_id !== '' ? QiGeo_Settings::get_provider_by_id( $provider_id ) : null;
        $provider_name = is_array( $provider ) && ! empty( $provider['name'] ) ? (string) $provider['name'] : $provider_id;
        $label = trim( $provider_name . ( $model !== '' ? ' / ' . $model : '' ) );
        if ( $label === '' ) {
            $label = '暂无第一阶段分析记录';
        }

        if ( $include_status && ( $status !== '' || $created !== '' ) ) {
            $label .= '（' . trim( $status . ' ' . $created ) . '）';
        }

        return $label;
    }

    private static function render_secondary_review_results( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 || ! class_exists( 'QiGeo_Analyzer' ) ) {
            return;
        }

        $status = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_SECONDARY_STATUS );
        $last_run = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_SECONDARY_LAST_RUN );
        $summary = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_SECONDARY_SUMMARY );
        $error = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_SECONDARY_ERROR );
        $result_json = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_SECONDARY_RESULT_JSON );
        $decoded = $result_json !== '' ? json_decode( $result_json, true ) : array();
        if ( ! is_array( $decoded ) ) {
            $decoded = array();
        }
        $results = isset( $decoded['results'] ) && is_array( $decoded['results'] ) ? $decoded['results'] : array();

        if ( $status === '' && empty( $results ) && $summary === '' && $error === '' ) {
            return;
        }

        $status_label = $status === 'success' ? '成功' : ( $status === 'failed' ? '失败' : $status );

        echo '<div style="border-top:1px solid #dcdcde;margin-top:14px;padding-top:12px;">';
        echo '<p style="margin-top:0;"><strong>最近第三阶段A多模型分析结果</strong></p>';
        if ( $status_label !== '' || $last_run !== '' ) {
            echo '<p class="description">状态：' . esc_html( $status_label !== '' ? $status_label : '未知' );
            if ( $last_run !== '' ) {
                echo '，时间：' . esc_html( $last_run );
            }
            echo '</p>';
        }
        if ( $summary !== '' ) {
            echo '<div style="max-height:110px;overflow:auto;background:#f6f7f7;padding:8px;border-radius:4px;margin-bottom:8px;">' . esc_html( $summary ) . '</div>';
        }
        if ( $error !== '' ) {
            echo '<p style="color:#b32d2e;"><strong>错误：</strong>' . esc_html( $error ) . '</p>';
        }
        if ( ! empty( $results ) ) {
            echo '<table class="widefat striped" style="margin-top:8px;">';
            echo '<thead><tr><th>模型</th><th style="width:80px;">状态</th><th style="width:80px;">评分</th><th style="width:100px;">建议投喂</th><th>分析反馈</th></tr></thead><tbody>';
            foreach ( $results as $result ) {
                if ( ! is_array( $result ) ) {
                    continue;
                }
                $ok = ! empty( $result['success'] );
                $label = isset( $result['label'] ) ? (string) $result['label'] : ( isset( $result['model_id'] ) ? (string) $result['model_id'] : '模型' );
                $score = isset( $result['acceptance_score'] ) && $result['acceptance_score'] !== null ? (string) $result['acceptance_score'] : '-';
                $ready = isset( $result['ready_to_manual_submit'] ) ? ( ! empty( $result['ready_to_manual_submit'] ) ? '是' : '否' ) : '-';
                $feedback = $ok
                    ? ( isset( $result['summary'] ) ? (string) $result['summary'] : '' )
                    : ( isset( $result['error'] ) ? (string) $result['error'] : '分析失败' );
                echo '<tr>';
                echo '<td>' . esc_html( $label ) . '</td>';
                echo '<td>' . ( $ok ? '<span style="color:#0f5132;">成功</span>' : '<span style="color:#b32d2e;">失败</span>' ) . '</td>';
                echo '<td>' . esc_html( $score ) . '</td>';
                echo '<td>' . esc_html( $ready ) . '</td>';
                echo '<td>' . esc_html( $feedback ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    private static function render_ai_feed_results( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 || ! class_exists( 'QiGeo_Analyzer' ) ) {
            return;
        }

        $status = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_AI_FEED_STATUS );
        $last_run = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_AI_FEED_LAST_RUN );
        $summary = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_AI_FEED_SUMMARY );
        $error = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_AI_FEED_ERROR );
        $result_json = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_AI_FEED_RESULT_JSON );
        $decoded = $result_json !== '' ? json_decode( $result_json, true ) : array();
        if ( ! is_array( $decoded ) ) {
            $decoded = array();
        }
        $results = isset( $decoded['results'] ) && is_array( $decoded['results'] ) ? $decoded['results'] : array();

        if ( $status === '' && empty( $results ) && $summary === '' && $error === '' ) {
            return;
        }

        $status_label = $status === 'success' ? '成功' : ( $status === 'failed' ? '失败' : $status );

        echo '<div style="border-top:1px solid #dcdcde;margin-top:14px;padding-top:12px;">';
        echo '<p style="margin-top:0;"><strong>最近第三阶段B AI 投喂结果</strong></p>';
        if ( $status_label !== '' || $last_run !== '' ) {
            echo '<p class="description">状态：' . esc_html( $status_label !== '' ? $status_label : '未知' );
            if ( $last_run !== '' ) {
                echo '，时间：' . esc_html( $last_run );
            }
            echo '</p>';
        }
        if ( $summary !== '' ) {
            echo '<div style="max-height:110px;overflow:auto;background:#f6f7f7;padding:8px;border-radius:4px;margin-bottom:8px;">' . esc_html( $summary ) . '</div>';
        }
        if ( $error !== '' ) {
            echo '<p style="color:#b32d2e;"><strong>错误：</strong>' . esc_html( $error ) . '</p>';
        }
        if ( ! empty( $results ) ) {
            echo '<table class="widefat striped" style="margin-top:8px;">';
            echo '<thead><tr><th>平台/模型</th><th style="width:80px;">状态</th><th style="width:100px;">已接收</th><th>投喂反馈</th></tr></thead><tbody>';
            foreach ( $results as $result ) {
                if ( ! is_array( $result ) ) {
                    continue;
                }
                $ok = ! empty( $result['success'] );
                $label = isset( $result['label'] ) ? (string) $result['label'] : ( isset( $result['model_id'] ) ? (string) $result['model_id'] : '模型' );
                $feed_status = isset( $result['feed_status'] ) ? (string) $result['feed_status'] : ( $ok ? 'success' : 'failed' );
                $accepted = isset( $result['accepted'] ) ? ( ! empty( $result['accepted'] ) ? '是' : '否' ) : '-';
                $feedback = $ok
                    ? ( isset( $result['summary'] ) ? (string) $result['summary'] : '' )
                    : ( isset( $result['error'] ) ? (string) $result['error'] : '投喂失败' );
                echo '<tr>';
                echo '<td>' . esc_html( $label ) . '</td>';
                echo '<td>' . ( $ok ? '<span style="color:#0f5132;">' . esc_html( $feed_status ) . '</span>' : '<span style="color:#b32d2e;">失败</span>' ) . '</td>';
                echo '<td>' . esc_html( $accepted ) . '</td>';
                echo '<td>' . esc_html( $feedback ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    /**
     * @return array<int,string>
     */
    private static function allowed_post_types() {
        $settings = QiGeo_Settings::get_settings();
        $post_types = isset( $settings['allowed_post_types'] ) && is_array( $settings['allowed_post_types'] )
            ? $settings['allowed_post_types']
            : array( 'post' );
        return array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
    }

    /**
     * @return array<int,array<string,string>>
     */
    private static function get_enabled_model_options() {
        $settings = QiGeo_Settings::get_settings();
        $providers = isset( $settings['providers'] ) && is_array( $settings['providers'] ) ? $settings['providers'] : array();
        $models = isset( $settings['models'] ) && is_array( $settings['models'] ) ? $settings['models'] : array();

        $provider_map = array();
        foreach ( $providers as $provider ) {
            if ( empty( $provider['id'] ) || ! self::provider_usable( $provider ) ) {
                continue;
            }
            $provider_map[ (string) $provider['id'] ] = $provider;
        }

        $options = array();
        foreach ( $models as $model ) {
            if ( empty( $model['provider_id'] ) || empty( $model['id'] ) ) {
                continue;
            }
            $provider_id = (string) $model['provider_id'];
            if ( ! isset( $provider_map[ $provider_id ] ) || ! self::model_usable( $model ) ) {
                continue;
            }

            $provider = $provider_map[ $provider_id ];
            $options[] = array(
                'provider_id' => $provider_id,
                'provider_name' => ! empty( $provider['name'] ) ? (string) $provider['name'] : $provider_id,
                'model_id' => (string) $model['id'],
                'model_label' => ! empty( $model['label'] ) ? (string) $model['label'] : (string) $model['id'],
                'model_name' => ! empty( $model['model'] ) ? (string) $model['model'] : '',
            );
        }

        return $options;
    }

    /**
     * @param string $model_id model id
     * @return array<string,mixed>|WP_Error
     */
    private static function resolve_provider_model( $model_id ) {
        $settings = QiGeo_Settings::get_settings();
        $requested_model_id = sanitize_key( (string) $model_id );
        $candidate_model_id = $requested_model_id;

        if ( $candidate_model_id === '' ) {
            $candidate_model_id = isset( $settings['default_model_id'] ) ? sanitize_key( (string) $settings['default_model_id'] ) : '';
        }
        if ( $candidate_model_id === '' ) {
            $candidate_model_id = self::get_first_usable_model_id();
        }
        if ( $candidate_model_id === '' ) {
            return new WP_Error( 'qigeo_faq_model_missing', '请先在启灵GEO配置并启用至少一个可用模型' );
        }

        $resolved = self::build_provider_model_packet( $candidate_model_id );
        if ( ! is_wp_error( $resolved ) ) {
            return $resolved;
        }

        $fallback_model_id = self::get_first_usable_model_id();
        if ( $fallback_model_id !== '' && $fallback_model_id !== $candidate_model_id ) {
            $fallback_resolved = self::build_provider_model_packet( $fallback_model_id );
            if ( ! is_wp_error( $fallback_resolved ) ) {
                return $fallback_resolved;
            }
        }

        if ( $requested_model_id !== '' ) {
            return new WP_Error( 'qigeo_faq_model_fallback_failed', '指定模型不可用，且没有可自动切换的备用模型' );
        }

        return $resolved;
    }

    /**
     * @param string $model_id model id
     * @return array<string,mixed>|WP_Error
     */
    private static function build_provider_model_packet( $model_id ) {
        $model_id = sanitize_key( (string) $model_id );
        if ( $model_id === '' ) {
            return new WP_Error( 'qigeo_faq_model_missing', '请先选择 AI 模型' );
        }

        $model = QiGeo_Settings::get_model_by_id( $model_id );
        if ( ! is_array( $model ) || ! self::model_usable( $model ) ) {
            return new WP_Error( 'qigeo_faq_model_invalid', '所选模型不可用，请在设置页确认已启用' );
        }

        $provider_id = isset( $model['provider_id'] ) ? sanitize_key( (string) $model['provider_id'] ) : '';
        if ( $provider_id === '' ) {
            return new WP_Error( 'qigeo_faq_provider_missing', '模型未绑定平台，请重新配置' );
        }

        $provider = QiGeo_Settings::get_provider_by_id( $provider_id );
        if ( ! is_array( $provider ) || ! self::provider_usable( $provider ) ) {
            return new WP_Error( 'qigeo_faq_provider_invalid', '所选生成工具暂时不可用，请检查访问密钥和启用状态。' );
        }

        return array(
            'provider_id' => $provider_id,
            'model_id' => $model_id,
            'model_name' => isset( $model['model'] ) ? (string) $model['model'] : '',
            'provider' => $provider,
            'model' => $model,
        );
    }

    /**
     * @return string
     */
    private static function get_first_usable_model_id() {
        $options = self::get_enabled_model_options();
        if ( empty( $options ) || ! is_array( $options ) ) {
            return '';
        }

        foreach ( $options as $option ) {
            if ( ! is_array( $option ) ) {
                continue;
            }
            $model_id = isset( $option['model_id'] ) ? sanitize_key( (string) $option['model_id'] ) : '';
            if ( $model_id !== '' ) {
                return $model_id;
            }
        }

        return '';
    }

    /**
     * @param string $primary_model_id primary
     * @param int    $max_count max count
     * @return array<int,string>
     */
    private static function get_runtime_fallback_model_ids( $primary_model_id, $max_count = 2 ) {
        $primary_model_id = sanitize_key( (string) $primary_model_id );
        $max_count = absint( $max_count );
        if ( $max_count < 1 ) {
            $max_count = 1;
        }

        $settings = QiGeo_Settings::get_settings();
        $fallback_ids = array();

        $default_model_id = isset( $settings['default_model_id'] ) ? sanitize_key( (string) $settings['default_model_id'] ) : '';
        if ( $default_model_id !== '' && $default_model_id !== $primary_model_id ) {
            $fallback_ids[] = $default_model_id;
        }

        $options = self::get_enabled_model_options();
        foreach ( $options as $option ) {
            if ( ! is_array( $option ) ) {
                continue;
            }
            $model_id = isset( $option['model_id'] ) ? sanitize_key( (string) $option['model_id'] ) : '';
            if ( $model_id === '' || $model_id === $primary_model_id || in_array( $model_id, $fallback_ids, true ) ) {
                continue;
            }
            $fallback_ids[] = $model_id;
            if ( count( $fallback_ids ) >= $max_count ) {
                break;
            }
        }

        return $fallback_ids;
    }

    /**
     * @param array<string,mixed> $provider provider
     * @return bool
     */
    private static function provider_usable( $provider ) {
        return ! empty( $provider['enabled'] )
            && $provider['enabled'] === '1'
            && ! empty( $provider['base_url'] )
            && ! empty( $provider['api_key'] );
    }

    /**
     * @param array<string,mixed> $model model
     * @return bool
     */
    private static function model_usable( $model ) {
        return ! empty( $model['enabled'] )
            && $model['enabled'] === '1'
            && ! empty( $model['model'] );
    }

    /**
     * @param array<string,mixed> $result ai result
     * @return array<string,mixed>
     */
    private static function normalize_generated_result( $result ) {
        $parsed = isset( $result['parsed'] ) && is_array( $result['parsed'] ) ? $result['parsed'] : array();
        $score_raw = '';
        if ( isset( $parsed['score'] ) ) {
            $score_raw = $parsed['score'];
        } elseif ( isset( $parsed['analysis_score'] ) ) {
            $score_raw = $parsed['analysis_score'];
        }
        $score = self::normalize_score_input( $score_raw );

        $summary = isset( $parsed['summary'] ) ? sanitize_textarea_field( (string) $parsed['summary'] ) : '';
        $key_points_raw = array();
        if ( isset( $parsed['key_points'] ) && is_array( $parsed['key_points'] ) ) {
            $key_points_raw = $parsed['key_points'];
        } elseif ( isset( $parsed['core_points'] ) && is_array( $parsed['core_points'] ) ) {
            $key_points_raw = $parsed['core_points'];
        } elseif ( isset( $parsed['highlights'] ) && is_array( $parsed['highlights'] ) ) {
            $key_points_raw = $parsed['highlights'];
        }
        $key_points = self::normalize_string_list( $key_points_raw, 12 );

        $faq = array();
        if ( isset( $parsed['faq'] ) && is_array( $parsed['faq'] ) ) {
            foreach ( $parsed['faq'] as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $q = '';
                $a = '';
                if ( isset( $item['q'] ) ) {
                    $q = trim( (string) $item['q'] );
                } elseif ( isset( $item['question'] ) ) {
                    $q = trim( (string) $item['question'] );
                }

                if ( isset( $item['a'] ) ) {
                    $a = trim( (string) $item['a'] );
                } elseif ( isset( $item['answer'] ) ) {
                    $a = trim( (string) $item['answer'] );
                }

                if ( $q === '' || $a === '' ) {
                    continue;
                }
                $faq[] = array(
                    'q' => sanitize_text_field( $q ),
                    'a' => sanitize_textarea_field( $a ),
                );
                if ( count( $faq ) >= 20 ) {
                    break;
                }
            }
        }

        $suggestions_raw = array();
        if ( isset( $parsed['rewrite_suggestions'] ) && is_array( $parsed['rewrite_suggestions'] ) ) {
            $suggestions_raw = $parsed['rewrite_suggestions'];
        } elseif ( isset( $parsed['suggestions'] ) && is_array( $parsed['suggestions'] ) ) {
            $suggestions_raw = $parsed['suggestions'];
        } elseif ( isset( $parsed['advice'] ) && is_array( $parsed['advice'] ) ) {
            $suggestions_raw = $parsed['advice'];
        }
        $suggestions = self::normalize_string_list( $suggestions_raw, 20 );

        $seo_title_raw = '';
        if ( isset( $parsed['seo_title'] ) ) {
            $seo_title_raw = $parsed['seo_title'];
        } elseif ( isset( $parsed['meta_title'] ) ) {
            $seo_title_raw = $parsed['meta_title'];
        }
        $seo_title = self::limit_text( sanitize_text_field( (string) $seo_title_raw ), 255 );

        $seo_keywords_raw = '';
        if ( isset( $parsed['seo_keywords'] ) ) {
            $seo_keywords_raw = $parsed['seo_keywords'];
        } elseif ( isset( $parsed['keywords'] ) ) {
            $seo_keywords_raw = $parsed['keywords'];
        }
        $seo_keywords = self::normalize_keywords_input( $seo_keywords_raw );

        $seo_description_raw = '';
        if ( isset( $parsed['seo_description'] ) ) {
            $seo_description_raw = $parsed['seo_description'];
        } elseif ( isset( $parsed['meta_description'] ) ) {
            $seo_description_raw = $parsed['meta_description'];
        } elseif ( isset( $parsed['description'] ) ) {
            $seo_description_raw = $parsed['description'];
        }
        $seo_description = self::limit_text( sanitize_textarea_field( (string) $seo_description_raw ), 160 );

        return array(
            'score' => $score,
            'summary' => $summary,
            'key_points' => $key_points,
            'faq' => $faq,
            'suggestions' => $suggestions,
            'seo_title' => $seo_title,
            'seo_keywords' => $seo_keywords,
            'seo_description' => $seo_description,
        );
    }

    /**
     * @param mixed $score_value score
     * @return string
     */
    private static function normalize_score_input( $score_value ) {
        $score_raw = trim( (string) $score_value );
        if ( $score_raw === '' ) {
            return '';
        }

        $score_raw = str_replace( array( '分', '%', '％' ), '', $score_raw );
        if ( ! is_numeric( $score_raw ) ) {
            return '';
        }

        $score = floatval( $score_raw );
        if ( $score < 0 ) {
            $score = 0;
        } elseif ( $score > 100 ) {
            $score = 100;
        }

        $formatted = number_format( $score, 2, '.', '' );
        $formatted = rtrim( $formatted, '0' );
        $formatted = rtrim( $formatted, '.' );
        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @param mixed $keywords_value keywords
     * @return string
     */
    private static function normalize_keywords_input( $keywords_value ) {
        $keywords_raw = array();
        if ( is_array( $keywords_value ) ) {
            $keywords_raw = $keywords_value;
        } else {
            $keywords_text = trim( (string) $keywords_value );
            if ( $keywords_text !== '' ) {
                $keywords_raw = preg_split( '/[，,;；、\n\r\t]+/u', $keywords_text );
                if ( ! is_array( $keywords_raw ) ) {
                    $keywords_raw = array( $keywords_text );
                }
            }
        }

        $keywords = self::normalize_string_list( $keywords_raw, 5 );
        if ( empty( $keywords ) ) {
            return '';
        }
        return self::limit_text( implode( '，', $keywords ), 200 );
    }

    /**
     * FAQ 库保存的是第二阶段成品，需要同步到文章 GEO 快照，供前台输出、AI 投喂和人工复制复用。
     *
     * @param int                 $post_id post id
     * @param string              $summary summary
     * @param array<int,string>   $key_points key points
     * @param array<int,array<string,string>> $faq faq
     * @param array<int,string>   $suggestions suggestions
     * @param array<string,mixed> $seo_data seo data
     * @return void
     */
    private static function sync_snapshot_for_post( $post_id, $summary, $key_points, $faq, $suggestions, $seo_data = array() ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 || ! class_exists( 'QiGeo_Analyzer' ) ) {
            return;
        }

        $summary = sanitize_textarea_field( (string) $summary );
        $key_points = self::normalize_string_list( is_array( $key_points ) ? $key_points : array(), 20 );
        $suggestions = self::normalize_string_list( is_array( $suggestions ) ? $suggestions : array(), 50 );
        $faq = is_array( $faq ) ? $faq : array();

        QiGeo_Analyzer::update_snapshot_values(
            $post_id,
            array(
                QiGeo_Analyzer::META_SUMMARY => $summary,
                QiGeo_Analyzer::META_KEY_POINTS_JSON => wp_json_encode( $key_points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                QiGeo_Analyzer::META_FAQ_JSON => wp_json_encode( $faq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                QiGeo_Analyzer::META_REWRITE_SUGGESTIONS => wp_json_encode( $suggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            )
        );

        QiGeo_Analyzer::refresh_geo_package_for_post(
            $post_id,
            array(),
            array(
                'summary' => $summary,
                'key_points' => $key_points,
                'faq' => $faq,
                'rewrite_suggestions' => $suggestions,
                'seo_title' => isset( $seo_data['seo_title'] ) ? self::limit_text( sanitize_text_field( (string) $seo_data['seo_title'] ), 255 ) : '',
                'seo_keywords' => isset( $seo_data['seo_keywords'] ) ? self::limit_text( self::normalize_keywords_input( $seo_data['seo_keywords'] ), 200 ) : '',
                'seo_description' => isset( $seo_data['seo_description'] ) ? self::limit_text( sanitize_textarea_field( (string) $seo_data['seo_description'] ), 160 ) : '',
            )
        );
    }

    /**
     * @param int                $post_id post id
     * @param bool               $enabled enabled
     * @param array<string,mixed> $seo_data seo data
     * @return void
     */
    private static function sync_theme_seo_for_post( $post_id, $enabled, $seo_data ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return;
        }

        $theme_meta = self::get_theme_seo_meta_keys();
        if ( empty( $theme_meta ) ) {
            return;
        }

        $toggle_key = QiGeo_Analyzer::META_USE_AI_SEO;
        $backup_key = QiGeo_Analyzer::META_SEO_BACKUP_JSON;

        if ( ! $enabled ) {
            QiGeo_Analyzer::update_snapshot_value( $post_id, $toggle_key, '0' );

            $backup = self::decode_json_array( (string) QiGeo_Analyzer::get_snapshot_value( $post_id, $backup_key ) );
            if ( is_array( $backup ) ) {
                foreach ( $theme_meta as $field => $meta_key ) {
                    $item = isset( $backup[ $field ] ) && is_array( $backup[ $field ] ) ? $backup[ $field ] : array();
                    $exists = ! empty( $item['exists'] );
                    $value = isset( $item['value'] ) ? (string) $item['value'] : '';
                    if ( $exists ) {
                        update_post_meta( $post_id, $meta_key, $value );
                    } else {
                        delete_post_meta( $post_id, $meta_key );
                    }
                }
                QiGeo_Analyzer::delete_snapshot_value( $post_id, $backup_key );
            }
            return;
        }

        QiGeo_Analyzer::update_snapshot_value( $post_id, $toggle_key, '1' );

        $backup = self::decode_json_array( (string) QiGeo_Analyzer::get_snapshot_value( $post_id, $backup_key ) );
        if ( empty( $backup ) ) {
            $backup = array();
            foreach ( $theme_meta as $field => $meta_key ) {
                $backup[ $field ] = array(
                    'exists' => metadata_exists( 'post', $post_id, $meta_key ),
                    'value' => (string) get_post_meta( $post_id, $meta_key, true ),
                );
            }
            QiGeo_Analyzer::update_snapshot_value( $post_id, $backup_key, $backup );
        }

        $title = isset( $seo_data['title'] ) ? sanitize_text_field( (string) $seo_data['title'] ) : '';
        $keywords = isset( $seo_data['keywords'] ) ? self::normalize_keywords_input( $seo_data['keywords'] ) : '';
        $description = isset( $seo_data['description'] ) ? sanitize_textarea_field( (string) $seo_data['description'] ) : '';

        self::set_or_delete_post_meta( $post_id, $theme_meta['title'], self::limit_text( $title, 255 ) );
        self::set_or_delete_post_meta( $post_id, $theme_meta['keywords'], self::limit_text( $keywords, 200 ) );
        self::set_or_delete_post_meta( $post_id, $theme_meta['description'], self::limit_text( $description, 160 ) );
    }

    /**
     * @return array<string,string>
     */
    private static function get_theme_seo_meta_keys() {
        return array(
            'title' => '_developer_starter_seo_title',
            'keywords' => '_developer_starter_seo_keywords',
            'description' => '_developer_starter_seo_description',
        );
    }

    /**
     * @param int    $post_id post id
     * @param string $meta_key meta key
     * @param string $value value
     * @return void
     */
    private static function set_or_delete_post_meta( $post_id, $meta_key, $value ) {
        $post_id = absint( $post_id );
        $meta_key = sanitize_key( (string) $meta_key );
        $value = trim( (string) $value );
        if ( $post_id <= 0 || $meta_key === '' ) {
            return;
        }

        if ( $value === '' ) {
            delete_post_meta( $post_id, $meta_key );
            return;
        }

        update_post_meta( $post_id, $meta_key, $value );
    }

    private static function build_default_source_content( $post ) {
        if ( ! $post instanceof WP_Post ) {
            return '';
        }

        $title = wp_strip_all_tags( (string) $post->post_title );
        $plain = wp_strip_all_tags( (string) $post->post_content );
        $plain = preg_replace( '/\s+/u', ' ', $plain );
        $plain = is_string( $plain ) ? trim( $plain ) : '';

        $combined = trim( $title . "\n\n" . $plain );
        return self::limit_text( $combined, 12000 );
    }

    /**
     * @param int $post_id post id
     * @return string
     */
    private static function get_post_title_by_id( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return '';
        }

        $title = get_the_title( $post_id );
        if ( ! is_string( $title ) ) {
            return '';
        }

        return sanitize_text_field( wp_strip_all_tags( $title ) );
    }

    /**
     * @param array<int,int> $post_ids post ids
     * @return array<int,array<string,string>>
     */
    private static function build_post_context_map( $post_ids ) {
        global $wpdb;

        $ids = array_values(
            array_unique(
                array_filter(
                    array_map( 'absint', is_array( $post_ids ) ? $post_ids : array() )
                )
            )
        );
        if ( empty( $ids ) ) {
            return array();
        }

        $map = array();
        $chunks = array_chunk( $ids, 200 );
        foreach ( $chunks as $chunk ) {
            if ( empty( $chunk ) ) {
                continue;
            }

            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $sql = "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE ID IN ({$placeholders})";
            $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A );
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }

                $post_id = isset( $row['ID'] ) ? absint( $row['ID'] ) : 0;
                if ( $post_id <= 0 ) {
                    continue;
                }

                $title = isset( $row['post_title'] ) ? wp_strip_all_tags( (string) $row['post_title'] ) : '';
                $plain = isset( $row['post_content'] ) ? wp_strip_all_tags( (string) $row['post_content'] ) : '';
                $plain = preg_replace( '/\s+/u', ' ', $plain );
                $plain = is_string( $plain ) ? trim( $plain ) : '';
                $combined = trim( (string) $title . "\n\n" . $plain );

                $map[ $post_id ] = array(
                    'title' => sanitize_text_field( (string) $title ),
                    'source_content' => self::limit_text( $combined, 12000 ),
                );
            }
        }

        return $map;
    }

    /**
     * @param string $text text
     * @param int    $max_len max length
     * @return string
     */
    private static function limit_text( $text, $max_len ) {
        $text = trim( (string) $text );
        $max_len = absint( $max_len );
        if ( $max_len <= 0 ) {
            return $text;
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $text ) > $max_len ) {
                return trim( mb_substr( $text, 0, $max_len ) );
            }
            return $text;
        }

        if ( strlen( $text ) > $max_len ) {
            return trim( substr( $text, 0, $max_len ) );
        }

        return $text;
    }

    /**
     * @param string $lines text lines
     * @return array<int,array<string,string>>
     */
    private static function parse_faq_lines( $lines ) {
        $lines = trim( (string) $lines );
        if ( $lines === '' ) {
            return array();
        }

        if ( strpos( $lines, '[' ) === 0 ) {
            $decoded = json_decode( $lines, true );
            if ( is_array( $decoded ) ) {
                $normalized = array();
                foreach ( $decoded as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : ( isset( $item['question'] ) ? trim( (string) $item['question'] ) : '' );
                    $a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : ( isset( $item['answer'] ) ? trim( (string) $item['answer'] ) : '' );
                    if ( $q === '' || $a === '' ) {
                        continue;
                    }
                    $normalized[] = array(
                        'q' => sanitize_text_field( $q ),
                        'a' => sanitize_textarea_field( $a ),
                    );
                    if ( count( $normalized ) >= 30 ) {
                        break;
                    }
                }
                return $normalized;
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

    /**
     * @param string $lines text lines
     * @param int    $limit limit
     * @return array<int,string>
     */
    private static function parse_plain_lines( $lines, $limit ) {
        $rows = preg_split( "/\r\n|\r|\n/u", (string) $lines );
        return self::normalize_string_list( is_array( $rows ) ? $rows : array(), $limit );
    }

    /**
     * @param array<int,mixed> $faq faq
     * @return string
     */
    private static function faq_to_lines( $faq ) {
        $lines = array();
        foreach ( (array) $faq as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : '';
            $a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : '';
            if ( $q === '' || $a === '' ) {
                continue;
            }
            $lines[] = $q . ' || ' . $a;
        }

        return implode( "\n", $lines );
    }

    /**
     * @param array<int,mixed> $items items
     * @param int              $limit limit
     * @return array<int,string>
     */
    private static function normalize_string_list( $items, $limit = 20 ) {
        $limit = absint( $limit );
        if ( $limit <= 0 ) {
            $limit = 20;
        }

        $normalized = array();
        foreach ( (array) $items as $item ) {
            if ( ! is_scalar( $item ) ) {
                continue;
            }
            $line = trim( (string) $item );
            if ( $line === '' ) {
                continue;
            }
            $normalized[] = sanitize_textarea_field( $line );
            if ( count( $normalized ) >= $limit ) {
                break;
            }
        }
        return $normalized;
    }

    /**
     * @param string $raw json
     * @return array<int,mixed>
     */
    private static function decode_json_array( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) {
            return array();
        }

        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * @param string $query query
     * @param int    $limit limit
     * @return array<int,array<string,mixed>>
     */
    private static function search_wechat_candidates( $query, $limit = 12 ) {
        global $wpdb;
        $table = self::get_table_name();
        $table_exists = QiGeo_Admin_Helper::table_exists( $table );
        if ( ! $table_exists ) {
            return array();
        }
        $limit = absint( $limit );
        if ( $limit < 1 ) {
            $limit = 12;
        }
        if ( $limit > 30 ) {
            $limit = 30;
        }

        $query = trim( (string) $query );
        $pool_size = max( 60, $limit * 25 );
        if ( $pool_size > 300 ) {
            $pool_size = 300;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id, post_url, summary, faq_json, suggestions_json, updated_at
                FROM {$table}
                ORDER BY updated_at DESC, id DESC
                LIMIT %d",
                $pool_size
            ),
            ARRAY_A
        );
        if ( empty( $rows ) ) {
            return array();
        }

        $post_ids = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
            if ( $post_id > 0 ) {
                $post_ids[] = $post_id;
            }
        }
        $post_context_map = self::build_post_context_map( $post_ids );

        $recent = array();
        $scored = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
            $post_context = ( $post_id > 0 && isset( $post_context_map[ $post_id ] ) && is_array( $post_context_map[ $post_id ] ) )
                ? $post_context_map[ $post_id ]
                : array();
            $row['post_title'] = isset( $post_context['title'] ) ? (string) $post_context['title'] : '';
            $row['source_excerpt'] = isset( $post_context['source_content'] ) ? (string) $post_context['source_content'] : '';
            $faq_preview = self::build_faq_preview( isset( $row['faq_json'] ) ? (string) $row['faq_json'] : '' );
            $row['faq_preview'] = $faq_preview;
            $recent[] = $row;

            if ( $query === '' ) {
                continue;
            }

            $score = self::score_wechat_candidate( $query, $row );
            if ( $score <= 0 ) {
                continue;
            }
            $row['_score'] = $score;
            $scored[] = $row;
        }

        if ( $query === '' ) {
            return array_slice( $recent, 0, $limit );
        }

        if ( empty( $scored ) ) {
            return array_slice( $recent, 0, $limit );
        }

        usort( $scored, function( $a, $b ) {
            $score_a = isset( $a['_score'] ) ? intval( $a['_score'] ) : 0;
            $score_b = isset( $b['_score'] ) ? intval( $b['_score'] ) : 0;
            if ( $score_a !== $score_b ) {
                return ( $score_a < $score_b ) ? 1 : -1;
            }

            $updated_a = isset( $a['updated_at'] ) ? (string) $a['updated_at'] : '';
            $updated_b = isset( $b['updated_at'] ) ? (string) $b['updated_at'] : '';
            if ( $updated_a !== $updated_b ) {
                return ( $updated_a < $updated_b ) ? 1 : -1;
            }

            $id_a = isset( $a['id'] ) ? absint( $a['id'] ) : 0;
            $id_b = isset( $b['id'] ) ? absint( $b['id'] ) : 0;
            if ( $id_a === $id_b ) {
                return 0;
            }
            return ( $id_a < $id_b ) ? 1 : -1;
        } );

        $out = array_slice( $scored, 0, $limit );
        if ( count( $out ) < $limit ) {
            $picked = array();
            foreach ( $out as $item ) {
                $picked_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
                if ( $picked_id > 0 ) {
                    $picked[ $picked_id ] = true;
                }
            }

            foreach ( $recent as $row ) {
                $row_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
                if ( $row_id > 0 && isset( $picked[ $row_id ] ) ) {
                    continue;
                }
                $out[] = $row;
                if ( count( $out ) >= $limit ) {
                    break;
                }
            }
        }

        foreach ( $out as $index => $item ) {
            if ( isset( $item['_score'] ) ) {
                unset( $item['_score'] );
                $out[ $index ] = $item;
            }
        }

        return $out;
    }

    /**
     * @param string              $query query
     * @param array<string,mixed> $row row
     * @return int
     */
    private static function score_wechat_candidate( $query, $row ) {
        $query = trim( (string) $query );
        if ( $query === '' ) {
            return 0;
        }

        $title = isset( $row['post_title'] ) ? (string) $row['post_title'] : '';
        $summary = isset( $row['summary'] ) ? (string) $row['summary'] : '';
        $faq_preview = isset( $row['faq_preview'] ) ? (string) $row['faq_preview'] : '';
        $faq_json = isset( $row['faq_json'] ) ? (string) $row['faq_json'] : '';
        $source_excerpt = isset( $row['source_excerpt'] ) ? (string) $row['source_excerpt'] : '';
        $suggestions = isset( $row['suggestions_json'] ) ? (string) $row['suggestions_json'] : '';

        $score = 0;
        if ( self::contains_text_ci( $title, $query ) ) {
            $score += 18;
        }
        if ( self::contains_text_ci( $faq_preview, $query ) ) {
            $score += 14;
        }
        if ( self::contains_text_ci( $summary, $query ) ) {
            $score += 10;
        }
        if ( self::contains_text_ci( $faq_json, $query ) ) {
            $score += 8;
        }
        if ( self::contains_text_ci( $source_excerpt, $query ) ) {
            $score += 6;
        }
        if ( self::contains_text_ci( $suggestions, $query ) ) {
            $score += 4;
        }

        $tokens = preg_split( '/[\s,，。；;、\/\|]+/u', $query );
        if ( ! is_array( $tokens ) ) {
            $tokens = array();
        }

        $checked = 0;
        foreach ( $tokens as $token ) {
            $token = trim( (string) $token );
            if ( $token === '' ) {
                continue;
            }
            if ( function_exists( 'mb_strlen' ) && mb_strlen( $token ) < 2 ) {
                continue;
            }

            if ( self::contains_text_ci( $title, $token ) ) {
                $score += 4;
            }
            if ( self::contains_text_ci( $faq_preview, $token ) ) {
                $score += 4;
            }
            if ( self::contains_text_ci( $summary, $token ) ) {
                $score += 2;
            }
            if ( self::contains_text_ci( $source_excerpt, $token ) ) {
                $score += 1;
            }

            $checked++;
            if ( $checked >= 6 ) {
                break;
            }
        }

        return $score;
    }

    /**
     * @param string $haystack haystack
     * @param string $needle needle
     * @return bool
     */
    private static function contains_text_ci( $haystack, $needle ) {
        $haystack = (string) $haystack;
        $needle = trim( (string) $needle );
        if ( $haystack === '' || $needle === '' ) {
            return false;
        }

        if ( function_exists( 'mb_stripos' ) ) {
            return mb_stripos( $haystack, $needle ) !== false;
        }

        return stripos( $haystack, $needle ) !== false;
    }

    /**
     * @param string $faq_json faq json
     * @return string
     */
    private static function build_faq_preview( $faq_json ) {
        $faq = self::decode_json_array( (string) $faq_json );
        if ( empty( $faq ) ) {
            return '';
        }

        $parts = array();
        foreach ( $faq as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : '';
            $a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : '';
            if ( $q === '' || $a === '' ) {
                continue;
            }
            $parts[] = 'Q:' . $q . ' A:' . self::truncate_text( $a, 36 );
            if ( count( $parts ) >= 3 ) {
                break;
            }
        }

        return implode( ' | ', $parts );
    }

    /**
     * @param string $faq_json faq json
     * @param int    $limit limit
     * @return array<int,array<string,string>>
     */
    private static function extract_faq_items( $faq_json, $limit = 6 ) {
        $limit = absint( $limit );
        if ( $limit <= 0 ) {
            $limit = 6;
        }

        $faq = self::decode_json_array( (string) $faq_json );
        if ( empty( $faq ) ) {
            return array();
        }

        $out = array();
        foreach ( $faq as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : '';
            $a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : '';
            if ( $q === '' || $a === '' ) {
                continue;
            }
            $out[] = array(
                'q' => self::truncate_text( sanitize_text_field( $q ), 60 ),
                'a' => self::truncate_text( sanitize_textarea_field( $a ), 180 ),
            );
            if ( count( $out ) >= $limit ) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<int,array<string,string>> $faq_items faq items
     * @return array<string,string>
     */
    private static function extract_first_faq_item( $faq_items ) {
        if ( ! is_array( $faq_items ) || empty( $faq_items ) ) {
            return array();
        }

        foreach ( $faq_items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : '';
            $a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : '';
            if ( $q === '' || $a === '' ) {
                continue;
            }
            return array(
                'q' => $q,
                'a' => $a,
            );
        }

        return array();
    }

    /**
     * @param array<string,mixed>         $parsed parsed
     * @param array<int,array<string,mixed>> $candidates candidates
     * @return array<string,mixed>|null
     */
    private static function select_candidate_from_ai( $parsed, $candidates ) {
        if ( empty( $candidates ) ) {
            return null;
        }

        $candidate_map = array();
        foreach ( $candidates as $item ) {
            $id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
            if ( $id > 0 ) {
                $candidate_map[ $id ] = $item;
            }
        }

        $selected_id = isset( $parsed['selected_id'] ) ? absint( $parsed['selected_id'] ) : 0;
        if ( $selected_id > 0 && isset( $candidate_map[ $selected_id ] ) ) {
            return $candidate_map[ $selected_id ];
        }

        $selected_url = isset( $parsed['url'] ) ? esc_url_raw( (string) $parsed['url'] ) : '';
        if ( $selected_url !== '' ) {
            foreach ( $candidates as $item ) {
                $item_url = isset( $item['post_url'] ) ? esc_url_raw( (string) $item['post_url'] ) : '';
                if ( $item_url !== '' && $item_url === $selected_url ) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * @param string $text text
     * @param int    $max_len max_len
     * @return string
     */
    private static function truncate_text( $text, $max_len ) {
        $text = trim( (string) $text );
        $max_len = absint( $max_len );
        if ( $max_len < 1 ) {
            return $text;
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $text ) <= $max_len ) {
                return $text;
            }
            return trim( mb_substr( $text, 0, $max_len ) );
        }

        if ( strlen( $text ) <= $max_len ) {
            return $text;
        }

        return trim( substr( $text, 0, $max_len ) );
    }

    /**
     * @return string
     */
    private static function wechat_prompt_template() {
        return "你是微信公众号 FAQ 路由助手。请根据用户问题和候选 FAQ，只能从候选里选择最合适的一条并输出 JSON。\n"
            . "输出结构：\n"
            . "{\n"
            . "  \"match\": true/false,\n"
            . "  \"selected_id\": 123,\n"
            . "  \"faq_q\": \"选中FAQ里的问题（不超过28字）\",\n"
            . "  \"faq_a\": \"选中FAQ里的答案（不超过110字）\",\n"
            . "  \"title\": \"可选，摘要卡片标题（不超过28字）\",\n"
            . "  \"description\": \"可选，摘要卡片描述（不超过110字）\",\n"
            . "  \"answer\": \"给用户的人话回复（不超过200字）\"\n"
            . "}\n"
            . "规则：\n"
            . "1) 不能编造候选以外的信息；\n"
            . "2) 若候选都不匹配，输出 match=false；\n"
            . "3) 输出必须是合法 JSON。";
    }

    /**
     * @param string $search search text
     * @return array<string,mixed>
     */
    private static function build_list_where( $search ) {
        global $wpdb;
        $search = trim( (string) $search );
        $where = '1=1';
        $params = array();

        if ( $search !== '' ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND (p.post_title LIKE %s OR f.summary LIKE %s OR f.model LIKE %s OR f.seo_title LIKE %s OR f.seo_keywords LIKE %s OR f.seo_description LIKE %s';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            if ( ctype_digit( $search ) ) {
                $where .= ' OR f.post_id = %d';
                $params[] = absint( $search );
            }
            $where .= ')';
        }

        return array(
            'where' => $where,
            'params' => $params,
        );
    }

    /**
     * @param string $json json
     * @return int
     */
    private static function count_json_items( $json ) {
        $items = self::decode_json_array( $json );
        return count( $items );
    }

    /**
     * @param string $text text
     * @param int    $max_len length
     * @return string
     */
    private static function excerpt_text( $text, $max_len ) {
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
        if ( $text === '' ) {
            return '-';
        }

        $max_len = absint( $max_len );
        if ( $max_len < 10 ) {
            $max_len = 10;
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $text ) <= $max_len ) {
                return $text;
            }
            return mb_substr( $text, 0, $max_len ) . '...';
        }

        if ( strlen( $text ) <= $max_len ) {
            return $text;
        }

        return substr( $text, 0, $max_len ) . '...';
    }

    private static function default_prompt_template() {
        return "你是内容结构化助手。请根据给定文章内容输出严格 JSON（不要 markdown，不要解释）。\n"
            . "JSON 结构：\n"
            . "{\n"
            . "  \"score\": 0-100 数字,\n"
            . "  \"summary\": \"80-220字中文摘要\",\n"
            . "  \"key_points\": [\"核心要点1\",\"核心要点2\"],\n"
            . "  \"faq\": [{\"q\":\"问题\",\"a\":\"回答\"}],\n"
            . "  \"rewrite_suggestions\": [\"建议1\",\"建议2\"],\n"
            . "  \"seo_title\": \"SEO标题\",\n"
            . "  \"seo_keywords\": [\"关键词1\",\"关键词2\"],\n"
            . "  \"seo_description\": \"SEO描述\"\n"
            . "}\n"
            . "要求：\n"
            . "1) key_points 建议 3-6 条，提炼文章最适合被 AI 理解和引用的核心结论。\n"
            . "2) faq 建议 3-8 条，问题要贴近用户检索意图，回答要明确可执行。\n"
            . "3) 如果信息不足，不要编造，使用保守表述并减少 FAQ 条目。\n"
            . "4) SEO关键词最多 5 个，按内容相关性输出。\n"
            . "5) SEO标题建议 24-34 字，SEO描述建议 70-160 字。\n"
            . "6) 输出必须是合法 JSON。";
    }
}
