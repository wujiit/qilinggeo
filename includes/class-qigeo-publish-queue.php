<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Publish_Queue {

    const MENU_SLUG = 'qigeo-publish-queue';
    const AJAX_GENERATE_ACTION = 'qigeo_generate_publish_queue';
    const AJAX_SAVE_ACTION = 'qigeo_save_publish_queue';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '发布执行队列',
            '发布执行队列',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function enqueue_assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
        if ( $page !== self::MENU_SLUG ) {
            return;
        }

        wp_enqueue_style( 'qigeo-admin', QIGEO_URL . 'assets/admin.css', array(), QIGEO_VERSION );
        wp_enqueue_script(
            'qigeo-publish-queue-admin',
            QIGEO_URL . 'assets/publish-queue-admin.js',
            array( 'qigeo-admin' ),
            QIGEO_VERSION,
            true
        );

        wp_localize_script(
            'qigeo-publish-queue-admin',
            'qigeoPublishQueueData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_publish_queue_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_publish_queue_nonce' ),
                'messages' => array(
                    'generating' => '正在整理发布执行队列...',
                    'saving' => '正在保存发布队列记录...',
                    'generateDone' => '发布执行队列已生成，可继续调整后保存。',
                    'saveDone' => '发布执行队列记录已保存。',
                ),
            )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $table_exists = self::table_exists();
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
        $edit_id = isset( $_GET['id'] ) ? absint( wp_unslash( (string) $_GET['id'] ) ) : 0;
        $current = ( $table_exists && $edit_id > 0 && $action === 'edit' ) ? self::get_item_by_id( $edit_id ) : null;
        $settings = QiGeo_Settings::get_settings();
        $model_options = self::get_enabled_model_options();
        $form = is_array( $current ) ? self::record_to_form_data( $current ) : self::get_empty_form_data();
        if ( ! is_array( $current ) ) {
            $form['model_id'] = isset( $settings['default_model_id'] ) ? sanitize_key( (string) $settings['default_model_id'] ) : '';
            if ( $form['model_id'] === '' ) {
                $form['model_id'] = self::get_first_usable_model_id();
            }
        }

        $rows = $table_exists ? self::get_records( $search, 20 ) : array();
        $base_url = add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) );
        ?>
        <div class="wrap qigeo-wrap">
            <h1>发布执行队列</h1>
            <p class="description">整理待处理内容事项。</p>

            <?php if ( ! $table_exists ) : ?>
                <div class="notice notice-error"><p>当前页面数据暂未准备好，请刷新页面后重试。</p></div>
            <?php endif; ?>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2><?php echo is_array( $current ) ? '编辑发布队列记录' : '新建发布队列记录'; ?></h2>
                    <a class="button" href="<?php echo esc_url( $base_url ); ?>">新建空白记录</a>
                </div>

                <?php if ( is_array( $current ) ) : ?>
                    <p class="qigeo-publish-active-record">已加载记录 #<?php echo esc_html( (string) absint( $current['id'] ) ); ?>，你可以继续生成、调整或覆盖保存。</p>
                <?php endif; ?>

                <?php if ( empty( $model_options ) ) : ?>
                    <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
                <?php endif; ?>

                <form id="qigeo-publish-form" class="qigeo-publish-form" action="#" method="post">
                    <input type="hidden" id="qigeo-publish-record-id" name="record_id" value="<?php echo esc_attr( (string) $form['record_id'] ); ?>" />

                    <div class="qigeo-publish-grid">
                        <div class="qigeo-publish-field qigeo-publish-field--wide">
                            <label for="qigeo-publish-seed-keyword"><strong>核心主题词 / 种子词</strong></label>
                            <input type="text" class="regular-text" id="qigeo-publish-seed-keyword" name="seed_keyword" maxlength="255" value="<?php echo esc_attr( $form['seed_keyword'] ); ?>" placeholder="例如：wordpress 最好的中文主题" />
                        </div>
                        <div class="qigeo-publish-field">
                            <label for="qigeo-publish-target-platform"><strong>目标 AI 平台</strong></label>
                            <input type="text" class="regular-text" id="qigeo-publish-target-platform" name="target_platform" maxlength="120" value="<?php echo esc_attr( $form['target_platform'] ); ?>" placeholder="例如：豆包 / ChatGPT / DeepSeek" />
                        </div>
                        <div class="qigeo-publish-field">
                            <label for="qigeo-publish-target-keyword"><strong>希望被推荐的词</strong></label>
                            <input type="text" class="regular-text" id="qigeo-publish-target-keyword" name="target_keyword" maxlength="255" value="<?php echo esc_attr( $form['target_keyword'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-publish-field">
                            <label for="qigeo-publish-brand-name"><strong>品牌 / 产品名</strong></label>
                            <input type="text" class="regular-text" id="qigeo-publish-brand-name" name="brand_name" maxlength="190" value="<?php echo esc_attr( $form['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-publish-field">
                            <label for="qigeo-publish-audience"><strong>目标人群 / 使用场景</strong></label>
                            <input type="text" class="regular-text" id="qigeo-publish-audience" name="audience_persona" maxlength="190" value="<?php echo esc_attr( $form['audience_persona'] ); ?>" placeholder="例如：中文企业站、新手站长、建站服务商" />
                        </div>
                        <div class="qigeo-publish-field">
                            <label for="qigeo-publish-model-id"><strong>优先起草模型</strong></label>
                            <select id="qigeo-publish-model-id" name="model_id">
                                <option value="">请选择模型</option>
                                <?php foreach ( $model_options as $option ) : ?>
                                    <?php $label = ( isset( $option['provider_name'] ) ? (string) $option['provider_name'] : '-' ) . ' / ' . ( isset( $option['model_label'] ) ? (string) $option['model_label'] : (string) $option['model_id'] ) . ' (' . ( isset( $option['model_name'] ) ? (string) $option['model_name'] : '-' ) . ')'; ?>
                                    <option value="<?php echo esc_attr( isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>" data-provider-type="<?php echo esc_attr( isset( $option['provider_type'] ) ? (string) $option['provider_type'] : '' ); ?>" <?php selected( $form['model_id'], isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="qigeo-publish-field qigeo-publish-field--wide">
                            <label for="qigeo-publish-asset-summary"><strong>内容资产计划 / 待发布页面</strong></label>
                            <textarea id="qigeo-publish-asset-summary" name="asset_summary" rows="7" placeholder="每行一条，例如：启灵主题 vs Astra 对比页 / 中文企业站 FAQ / 安装文档页 / 案例页。"><?php echo esc_textarea( $form['asset_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-publish-field">
                            <label for="qigeo-publish-priority-questions"><strong>重点问法</strong></label>
                            <textarea id="qigeo-publish-priority-questions" name="priority_questions" rows="8" placeholder="每行一条高价值问法或对比问法。"><?php echo esc_textarea( $form['priority_questions'] ); ?></textarea>
                        </div>
                        <div class="qigeo-publish-field">
                            <label for="qigeo-publish-backtest-summary"><strong>回测弱项 / 未命中问题</strong></label>
                            <textarea id="qigeo-publish-backtest-summary" name="backtest_summary" rows="8" placeholder="每行一条当前还打不出来、提及不稳或证据不足的问题。"><?php echo esc_textarea( $form['backtest_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-publish-field qigeo-publish-field--wide">
                            <label for="qigeo-publish-notes"><strong>补充限制 / 备注</strong></label>
                            <textarea id="qigeo-publish-notes" name="notes" rows="4" placeholder="例如：优先公开页面；先做快赢项；不要同时铺太多低优先级页面。"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
                        </div>
                    </div>

                    <div class="qigeo-publish-actions">
                        <button type="button" class="button button-secondary" id="qigeo-generate-publish-btn">生成发布队列</button>
                        <button type="button" class="button button-primary" id="qigeo-save-publish-btn">保存记录</button>
                        <span class="qigeo-publish-feedback" id="qigeo-publish-feedback" aria-live="polite"></span>
                    </div>

                    <div class="qigeo-publish-results">
                        <h3>发布执行输出结果</h3>
                        <div class="qigeo-publish-grid">
                            <div class="qigeo-publish-field qigeo-publish-field--wide">
                                <label for="qigeo-publish-result-summary"><strong>执行总览</strong></label>
                                <textarea id="qigeo-publish-result-summary" name="summary" rows="4"><?php echo esc_textarea( $form['summary'] ); ?></textarea>
                            </div>
                            <div class="qigeo-publish-field qigeo-publish-field--wide">
                                <label for="qigeo-publish-result-strategy"><strong>执行策略</strong></label>
                                <textarea id="qigeo-publish-result-strategy" name="execution_strategy" rows="4"><?php echo esc_textarea( $form['execution_strategy'] ); ?></textarea>
                            </div>
                            <div class="qigeo-publish-field qigeo-publish-field--wide">
                                <label for="qigeo-publish-result-backlog"><strong>任务队列</strong></label>
                                <textarea id="qigeo-publish-result-backlog" name="task_backlog" rows="10"><?php echo esc_textarea( $form['task_backlog'] ); ?></textarea>
                            </div>
                            <div class="qigeo-publish-field">
                                <label for="qigeo-publish-result-quickwins"><strong>快赢项</strong></label>
                                <textarea id="qigeo-publish-result-quickwins" name="quick_wins" rows="8"><?php echo esc_textarea( $form['quick_wins'] ); ?></textarea>
                            </div>
                            <div class="qigeo-publish-field">
                                <label for="qigeo-publish-result-blocked"><strong>阻塞项 / 依赖项</strong></label>
                                <textarea id="qigeo-publish-result-blocked" name="blocked_items" rows="8"><?php echo esc_textarea( $form['blocked_items'] ); ?></textarea>
                            </div>
                            <div class="qigeo-publish-field">
                                <label for="qigeo-publish-result-checks"><strong>发布前检查项</strong></label>
                                <textarea id="qigeo-publish-result-checks" name="publish_checks" rows="8"><?php echo esc_textarea( $form['publish_checks'] ); ?></textarea>
                            </div>
                            <div class="qigeo-publish-field">
                                <label for="qigeo-publish-result-retest"><strong>发布后回测计划</strong></label>
                                <textarea id="qigeo-publish-result-retest" name="retest_plan" rows="8"><?php echo esc_textarea( $form['retest_plan'] ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2>最近记录</h2>
                    <span class="description">最多显示最近 20 条，可搜索主题词、目标词、平台或摘要。</span>
                </div>
                <form method="get" class="qigeo-publish-search">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索主题词 / 平台 / 目标词 / 品牌 / 摘要" />
                    <button type="submit" class="button">搜索</button>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">重置</a>
                </form>
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>主题词 / 目标词</th><th>平台 / 人群</th><th>任务数</th><th>摘要</th><th>模型</th><th>更新时间</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="8">暂无记录。</td></tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $row ) : ?>
                            <?php self::render_record_row( $row ); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private static function render_record_row( $row ) {
        $row_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
        $edit_url = add_query_arg( array( 'page' => self::MENU_SLUG, 'action' => 'edit', 'id' => $row_id ), admin_url( 'admin.php' ) );
        $result = self::decode_result_json( isset( $row['result_json'] ) ? (string) $row['result_json'] : '' );
        $target = isset( $row['target_keyword'] ) ? (string) $row['target_keyword'] : '';
        $platform = isset( $row['target_platform'] ) ? (string) $row['target_platform'] : '';
        $brand = isset( $row['brand_name'] ) ? (string) $row['brand_name'] : '';
        $audience = isset( $row['audience_persona'] ) ? (string) $row['audience_persona'] : '';
        ?>
        <tr>
            <td><?php echo esc_html( (string) $row_id ); ?></td>
            <td><strong><?php echo esc_html( isset( $row['seed_keyword'] ) ? (string) $row['seed_keyword'] : '' ); ?></strong><div class="qigeo-publish-row-meta"><?php echo esc_html( implode( ' / ', array_filter( array( $target, $brand ) ) ) ); ?></div></td>
            <td><strong><?php echo esc_html( $platform ); ?></strong><div class="qigeo-publish-row-meta"><?php echo esc_html( $audience ); ?></div></td>
            <td><?php echo esc_html( (string) self::count_queue_items( $result ) ); ?></td>
            <td><?php echo esc_html( self::excerpt_text( isset( $row['result_summary'] ) ? (string) $row['result_summary'] : '' ) ); ?></td>
            <td><?php echo esc_html( isset( $row['model'] ) ? (string) $row['model'] : '' ); ?></td>
            <td><?php echo esc_html( isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '' ); ?></td>
            <td><a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">编辑</a></td>
        </tr>
        <?php
    }

    public static function ajax_generate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        check_ajax_referer( 'qigeo_generate_publish_queue_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $form = self::get_request_form_data();
        $validation = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
        }

        $provider_model = self::resolve_draft_model( $form['model_id'], $form['target_platform'] );
        if ( is_wp_error( $provider_model ) ) {
            wp_send_json_error( array( 'message' => $provider_model->get_error_message() ) );
        }

        $result = self::run_generation( $provider_model, $form );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success(
            array(
                'message' => '发布执行队列已生成，可继续调整后再保存。',
                'result' => $result,
                'provider_id' => isset( $provider_model['provider_id'] ) ? (string) $provider_model['provider_id'] : '',
                'model_id' => isset( $provider_model['model_id'] ) ? (string) $provider_model['model_id'] : '',
                'model_name' => isset( $provider_model['model_name'] ) ? (string) $provider_model['model_name'] : '',
            )
        );
    }

    public static function ajax_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        check_ajax_referer( 'qigeo_save_publish_queue_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $form = self::get_request_form_data();
        $validation = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
        }

        $provider_model = self::resolve_draft_model( $form['model_id'], $form['target_platform'] );
        if ( is_wp_error( $provider_model ) ) {
            wp_send_json_error( array( 'message' => $provider_model->get_error_message() ) );
        }

        $result = self::get_result_data_from_request();
        if ( $result['summary'] === '' && empty( $result['task_backlog'] ) && empty( $result['quick_wins'] ) && empty( $result['retest_plan'] ) ) {
            wp_send_json_error( array( 'message' => '请先生成或填写发布执行结果后再保存' ) );
        }

        global $wpdb;
        $table = self::get_table_name();
        $now = current_time( 'mysql' );
        $record_id = absint( $form['record_id'] );
        $current_user_id = get_current_user_id();
        $data = array(
            'seed_keyword' => $form['seed_keyword'],
            'target_platform' => $form['target_platform'],
            'target_keyword' => $form['target_keyword'],
            'brand_name' => $form['brand_name'],
            'audience_persona' => $form['audience_persona'],
            'asset_summary' => $form['asset_summary'],
            'priority_questions' => $form['priority_questions'],
            'backtest_summary' => $form['backtest_summary'],
            'notes' => $form['notes'],
            'provider_id' => isset( $provider_model['provider_id'] ) ? (string) $provider_model['provider_id'] : '',
            'model_id' => isset( $provider_model['model_id'] ) ? (string) $provider_model['model_id'] : '',
            'model' => isset( $provider_model['model_name'] ) ? (string) $provider_model['model_name'] : '',
            'result_summary' => $result['summary'],
            'result_json' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'updated_at' => $now,
        );
        $formats = array_fill( 0, 15, '%s' );

        if ( $record_id > 0 && self::get_item_by_id( $record_id ) ) {
            $updated = $wpdb->update( $table, $data, array( 'id' => $record_id ), $formats, array( '%d' ) );
            if ( $updated === false ) {
                wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
            }
            wp_send_json_success( array( 'message' => '发布执行队列记录已更新。', 'record_id' => $record_id ) );
        }

        $data['created_by'] = $current_user_id;
        $data['created_at'] = $now;
        $inserted = $wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%s' ) ) );
        if ( $inserted === false ) {
            wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
        }
        wp_send_json_success( array( 'message' => '发布执行队列记录已保存。', 'record_id' => absint( $wpdb->insert_id ) ) );
    }

    private static function run_generation( $provider_model, $form ) {
        $payload = self::build_generation_payload( $form );
        $result = QiGeo_AI_Client::analyze_post( $provider_model['provider'], $provider_model['model'], self::default_prompt_template(), $payload, QiGeo_AI_Client::get_interactive_runtime() );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return self::normalize_generated_result( $result );
    }

    private static function build_generation_payload( $form ) {
        return array(
            'seed_keyword' => $form['seed_keyword'],
            'target_ai_platform' => $form['target_platform'],
            'target_keyword' => $form['target_keyword'],
            'brand_name' => $form['brand_name'],
            'global_brand_profile' => class_exists( 'QiGeo_Brand_Entity_Library' ) ? QiGeo_Brand_Entity_Library::get_global_profile_context() : array(),
            'audience_persona' => $form['audience_persona'],
            'asset_summary' => self::parse_plain_lines( $form['asset_summary'], 20 ),
            'priority_questions' => self::parse_plain_lines( $form['priority_questions'], 15 ),
            'backtest_summary' => self::parse_plain_lines( $form['backtest_summary'], 15 ),
            'notes' => self::parse_plain_lines( $form['notes'], 15 ),
        );
    }

    // data
    private static function get_empty_form_data() {
        return array(
            'record_id' => 0,
            'model_id' => '',
            'seed_keyword' => '',
            'target_platform' => '',
            'target_keyword' => '',
            'brand_name' => '',
            'audience_persona' => '',
            'asset_summary' => '',
            'priority_questions' => '',
            'backtest_summary' => '',
            'notes' => '',
            'summary' => '',
            'execution_strategy' => '',
            'task_backlog' => '',
            'quick_wins' => '',
            'blocked_items' => '',
            'publish_checks' => '',
            'retest_plan' => '',
        );
    }

    private static function record_to_form_data( $record ) {
        $form = self::get_empty_form_data();
        foreach ( array( 'id' => 'record_id', 'model_id' => 'model_id', 'seed_keyword' => 'seed_keyword', 'target_platform' => 'target_platform', 'target_keyword' => 'target_keyword', 'brand_name' => 'brand_name', 'audience_persona' => 'audience_persona', 'asset_summary' => 'asset_summary', 'priority_questions' => 'priority_questions', 'backtest_summary' => 'backtest_summary', 'notes' => 'notes' ) as $source => $target ) {
            if ( ! isset( $record[ $source ] ) ) {
                continue;
            }
            if ( 'record_id' === $target ) {
                $form[ $target ] = absint( $record[ $source ] );
            } elseif ( 'model_id' === $target ) {
                $form[ $target ] = sanitize_key( (string) $record[ $source ] );
            } else {
                $form[ $target ] = sanitize_textarea_field( (string) $record[ $source ] );
            }
        }
        $result = self::decode_result_json( isset( $record['result_json'] ) ? (string) $record['result_json'] : '' );
        $form['summary'] = isset( $result['summary'] ) ? sanitize_textarea_field( (string) $result['summary'] ) : ( isset( $record['result_summary'] ) ? sanitize_textarea_field( (string) $record['result_summary'] ) : '' );
        $form['execution_strategy'] = isset( $result['execution_strategy'] ) ? sanitize_textarea_field( (string) $result['execution_strategy'] ) : '';
        foreach ( array( 'task_backlog', 'quick_wins', 'blocked_items', 'publish_checks', 'retest_plan' ) as $key ) {
            $form[ $key ] = self::lines_from_array( isset( $result[ $key ] ) ? $result[ $key ] : array() );
        }
        return $form;
    }

    private static function get_request_form_data() {
        return array(
            'record_id' => isset( $_POST['record_id'] ) ? absint( wp_unslash( (string) $_POST['record_id'] ) ) : 0,
            'model_id' => isset( $_POST['model_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['model_id'] ) ) : '',
            'seed_keyword' => self::limit_text( isset( $_POST['seed_keyword'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['seed_keyword'] ) ) : '', 255 ),
            'target_platform' => self::limit_text( isset( $_POST['target_platform'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_platform'] ) ) : '', 120 ),
            'target_keyword' => self::limit_text( isset( $_POST['target_keyword'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_keyword'] ) ) : '', 255 ),
            'brand_name' => self::limit_text( isset( $_POST['brand_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['brand_name'] ) ) : '', 190 ),
            'audience_persona' => self::limit_text( isset( $_POST['audience_persona'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['audience_persona'] ) ) : '', 190 ),
            'asset_summary' => isset( $_POST['asset_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['asset_summary'] ) ) : '',
            'priority_questions' => isset( $_POST['priority_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['priority_questions'] ) ) : '',
            'backtest_summary' => isset( $_POST['backtest_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['backtest_summary'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        );
    }

    private static function get_result_data_from_request() {
        return array(
            'summary' => self::limit_text( isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '', 800 ),
            'execution_strategy' => self::limit_text( isset( $_POST['execution_strategy'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['execution_strategy'] ) ) : '', 800 ),
            'task_backlog' => self::parse_plain_lines( isset( $_POST['task_backlog'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['task_backlog'] ) ) : '', 30 ),
            'quick_wins' => self::parse_plain_lines( isset( $_POST['quick_wins'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['quick_wins'] ) ) : '', 20 ),
            'blocked_items' => self::parse_plain_lines( isset( $_POST['blocked_items'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['blocked_items'] ) ) : '', 20 ),
            'publish_checks' => self::parse_plain_lines( isset( $_POST['publish_checks'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['publish_checks'] ) ) : '', 20 ),
            'retest_plan' => self::parse_plain_lines( isset( $_POST['retest_plan'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['retest_plan'] ) ) : '', 20 ),
        );
    }

    private static function validate_base_form_data( $form ) {
        if ( empty( $form['seed_keyword'] ) ) {
            return new WP_Error( 'qigeo_publish_seed_required', '请先填写核心主题词 / 种子词' );
        }
        if ( empty( $form['target_platform'] ) ) {
            return new WP_Error( 'qigeo_publish_platform_required', '请先填写目标 AI 平台' );
        }
        if ( empty( $form['target_keyword'] ) ) {
            return new WP_Error( 'qigeo_publish_target_required', '请先填写希望被推荐的词' );
        }
        if ( empty( self::parse_plain_lines( $form['asset_summary'], 20 ) ) ) {
            return new WP_Error( 'qigeo_publish_assets_required', '请先填写至少一条待发布内容资产' );
        }
        return true;
    }

    private static function get_records( $search = '', $limit = 20 ) {
        global $wpdb;
        $limit = max( 1, absint( $limit ) );
        $where = '1=1';
        $params = array();
        if ( $search !== '' ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND (seed_keyword LIKE %s OR target_platform LIKE %s OR target_keyword LIKE %s OR brand_name LIKE %s OR result_summary LIKE %s)';
            $params = array( $like, $like, $like, $like, $like );
        }
        $sql = 'SELECT * FROM ' . self::get_table_name() . " WHERE {$where} ORDER BY updated_at DESC, id DESC LIMIT %d";
        $params[] = $limit;
        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    private static function get_item_by_id( $record_id ) {
        global $wpdb;
        $record_id = absint( $record_id );
        if ( $record_id < 1 ) {
            return null;
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::get_table_name() . ' WHERE id = %d LIMIT 1', $record_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'qigeo_publish_queue';
    }

    private static function table_exists() {
        global $wpdb;
        $table = self::get_table_name();
        return QiGeo_Admin_Helper::table_exists( $table );
    }

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
            if ( empty( $model['provider_id'] ) || empty( $model['id'] ) || ! self::model_usable( $model ) ) {
                continue;
            }
            $provider_id = (string) $model['provider_id'];
            if ( ! isset( $provider_map[ $provider_id ] ) ) {
                continue;
            }
            $provider = $provider_map[ $provider_id ];
            $options[] = array(
                'provider_id' => $provider_id,
                'provider_type' => isset( $provider['type'] ) ? sanitize_key( (string) $provider['type'] ) : '',
                'provider_name' => ! empty( $provider['name'] ) ? (string) $provider['name'] : $provider_id,
                'model_id' => (string) $model['id'],
                'model_label' => ! empty( $model['label'] ) ? (string) $model['label'] : (string) $model['id'],
                'model_name' => ! empty( $model['model'] ) ? (string) $model['model'] : '',
            );
        }
        return $options;
    }

    private static function resolve_draft_model( $preferred_model_id, $target_platform = '' ) {
        $packets = self::get_all_usable_model_packets();
        if ( empty( $packets ) ) {
            return new WP_Error( 'qigeo_publish_model_missing', '请先在启灵GEO配置并启用至少一个可用模型' );
        }
        $platform_key = self::normalize_platform_key( $target_platform );
        $preferred_packet = null;
        foreach ( $packets as $packet ) {
            if ( $preferred_model_id !== '' && sanitize_key( (string) $packet['model_id'] ) === sanitize_key( (string) $preferred_model_id ) ) {
                $preferred_packet = $packet;
                break;
            }
        }
        if ( $preferred_packet && ( $platform_key === '' || ! self::provider_matches_platform( $preferred_packet, $platform_key ) ) ) {
            return $preferred_packet;
        }
        foreach ( $packets as $packet ) {
            if ( $platform_key !== '' && self::provider_matches_platform( $packet, $platform_key ) ) {
                continue;
            }
            return $packet;
        }
        return $preferred_packet ? $preferred_packet : $packets[0];
    }

    private static function get_all_usable_model_packets() {
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
        $packets = array();
        foreach ( $models as $model ) {
            if ( empty( $model['id'] ) || empty( $model['provider_id'] ) || ! self::model_usable( $model ) ) {
                continue;
            }
            $provider_id = (string) $model['provider_id'];
            if ( ! isset( $provider_map[ $provider_id ] ) ) {
                continue;
            }
            $provider = $provider_map[ $provider_id ];
            $packets[] = array(
                'provider_id' => $provider_id,
                'provider_type' => isset( $provider['type'] ) ? sanitize_key( (string) $provider['type'] ) : '',
                'provider_name' => ! empty( $provider['name'] ) ? (string) $provider['name'] : $provider_id,
                'model_id' => (string) $model['id'],
                'model_label' => ! empty( $model['label'] ) ? (string) $model['label'] : (string) $model['id'],
                'model_name' => ! empty( $model['model'] ) ? (string) $model['model'] : '',
                'provider' => $provider,
                'model' => $model,
            );
        }
        return $packets;
    }

    private static function get_first_usable_model_id() {
        $options = self::get_enabled_model_options();
        return ! empty( $options[0]['model_id'] ) ? sanitize_key( (string) $options[0]['model_id'] ) : '';
    }

    private static function normalize_platform_key( $target_platform ) {
        return QiGeo_Admin_Helper::normalize_platform_key( $target_platform );
    }

    private static function provider_matches_platform( $provider_model, $platform_key ) {
        return QiGeo_Admin_Helper::provider_matches_platform( $provider_model, $platform_key );
    }

    private static function provider_usable( $provider ) {
        return QiGeo_Admin_Helper::provider_usable( $provider );
    }

    private static function model_usable( $model ) {
        return ! empty( $model['enabled'] ) && $model['enabled'] === '1' && ! empty( $model['model'] );
    }

    private static function normalize_generated_result( $result ) {
        $parsed = isset( $result['parsed'] ) && is_array( $result['parsed'] ) ? $result['parsed'] : array();
        return array(
            'summary' => self::limit_text( isset( $parsed['summary'] ) ? sanitize_textarea_field( (string) $parsed['summary'] ) : '', 800 ),
            'execution_strategy' => self::limit_text( isset( $parsed['execution_strategy'] ) ? sanitize_textarea_field( (string) $parsed['execution_strategy'] ) : ( isset( $parsed['strategy_summary'] ) ? sanitize_textarea_field( (string) $parsed['strategy_summary'] ) : '' ), 800 ),
            'task_backlog' => self::normalize_string_list( isset( $parsed['task_backlog'] ) ? $parsed['task_backlog'] : ( isset( $parsed['task_queue'] ) ? $parsed['task_queue'] : array() ), 30 ),
            'quick_wins' => self::normalize_string_list( isset( $parsed['quick_wins'] ) ? $parsed['quick_wins'] : array(), 20 ),
            'blocked_items' => self::normalize_string_list( isset( $parsed['blocked_items'] ) ? $parsed['blocked_items'] : ( isset( $parsed['dependencies'] ) ? $parsed['dependencies'] : array() ), 20 ),
            'publish_checks' => self::normalize_string_list( isset( $parsed['publish_checks'] ) ? $parsed['publish_checks'] : array(), 20 ),
            'retest_plan' => self::normalize_string_list( isset( $parsed['retest_plan'] ) ? $parsed['retest_plan'] : ( isset( $parsed['execution_steps'] ) ? $parsed['execution_steps'] : array() ), 20 ),
        );
    }

    private static function normalize_string_list( $items, $limit = 10 ) {
        $normalized = array();
        foreach ( (array) $items as $item ) {
            if ( ! is_scalar( $item ) ) {
                continue;
            }
            $text = trim( sanitize_textarea_field( (string) $item ) );
            if ( $text === '' || in_array( $text, $normalized, true ) ) {
                continue;
            }
            $normalized[] = self::limit_text( $text, 320 );
            if ( count( $normalized ) >= $limit ) {
                break;
            }
        }
        return $normalized;
    }

    private static function parse_plain_lines( $text, $limit = 10 ) {
        $result = array();
        foreach ( preg_split( "/\r\n|\r|\n/", (string) $text ) as $line ) {
            $value = trim( sanitize_textarea_field( (string) $line ) );
            if ( $value === '' || in_array( $value, $result, true ) ) {
                continue;
            }
            $result[] = self::limit_text( $value, 320 );
            if ( count( $result ) >= $limit ) {
                break;
            }
        }
        return $result;
    }

    private static function lines_from_array( $items ) {
        $lines = array();
        foreach ( (array) $items as $item ) {
            if ( is_scalar( $item ) ) {
                $value = trim( sanitize_textarea_field( (string) $item ) );
                if ( $value !== '' ) {
                    $lines[] = $value;
                }
            }
        }
        return implode( "\n", $lines );
    }

    private static function decode_result_json( $result_json ) {
        $decoded = json_decode( (string) $result_json, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    private static function count_queue_items( $result ) {
        return isset( $result['task_backlog'] ) && is_array( $result['task_backlog'] ) ? count( $result['task_backlog'] ) : 0;
    }

    private static function excerpt_text( $text, $length = 120 ) {
        $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
        if ( $text === '' ) {
            return '';
        }
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            return mb_strlen( $text, 'UTF-8' ) <= $length ? $text : mb_substr( $text, 0, $length, 'UTF-8' ) . '...';
        }
        return strlen( $text ) <= $length ? $text : substr( $text, 0, $length ) . '...';
    }

    private static function limit_text( $text, $length = 300 ) {
        $text = trim( (string) $text );
        if ( $text === '' ) {
            return '';
        }
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            return mb_strlen( $text, 'UTF-8' ) <= $length ? $text : trim( mb_substr( $text, 0, $length, 'UTF-8' ) );
        }
        return strlen( $text ) <= $length ? $text : trim( substr( $text, 0, $length ) );
    }

    private static function default_prompt_template() {
        return "你是 GEO 发布执行规划助手。现在要把一组待发布内容资产、重点问法和回测弱项，整理成一份可以真正执行的发布任务队列。请严格输出 JSON，不要 markdown，不要解释。\n"
            . "{\n"
            . "\"summary\":\"80-220字，总结这一轮发布最该先做什么\",\n"
            . "\"execution_strategy\":\"80-220字，说明执行顺序和节奏\",\n"
            . "\"task_backlog\":[\"任务标题 || 类型：专题页/对比页/FAQ/教程/文档/案例/日志 || 状态：待做/进行中/已发布/已回测 || 优先级：高/中/低 || 目标问法：... || 完成标准：...\"],\n"
            . "\"quick_wins\":[\"优先可以快速完成并能带来明显改进的动作1\"],\n"
            . "\"blocked_items\":[\"当前阻塞项或依赖项1 || 原因：...\"],\n"
            . "\"publish_checks\":[\"发布前检查项1\"],\n"
            . "\"retest_plan\":[\"发布后回测动作1\"]\n"
            . "}\n"
            . "要求：1) task_backlog 要尽量具体，优先把最影响 AI 推荐的问题和页面排前面。2) 必须围绕 asset_summary、priority_questions、backtest_summary 输出，不要空泛。3) 如果 payload 包含 global_brand_profile，发布顺序、完成标准和检查项必须遵守其中的品牌定位、核心页面、权威证明、适合/不适合推荐场景。4) 状态默认以待做为主，但允许少量进行中/已发布描述，如果输入明显提到这些状态。5) publish_checks 要包括上线前最关键的检查动作。6) retest_plan 要说明发完后如何回测。7) 不要虚构外链、背书、媒体报道、奖项或测试数据。8) 输出必须是合法 JSON。";
    }
}
