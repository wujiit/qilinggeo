<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Content_Asset_Center {

    const MENU_SLUG = 'qigeo-content-asset-center';
    const AJAX_GENERATE_ACTION = 'qigeo_generate_content_asset_center';
    const AJAX_SAVE_ACTION = 'qigeo_save_content_asset_center';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '内容资产',
            '内容资产',
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
            'qigeo-content-asset-admin',
            QIGEO_URL . 'assets/content-asset-admin.js',
            array( 'qigeo-admin' ),
            QIGEO_VERSION,
            true
        );

        wp_localize_script(
            'qigeo-content-asset-admin',
            'qigeoContentAssetCenterData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_content_asset_center_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_content_asset_center_nonce' ),
                'messages' => array(
                    'generating' => '正在整理内容资产...',
                    'saving' => '正在保存内容资产记录...',
                    'generateDone' => '内容资产方案已生成，可继续微调后保存。',
                    'saveDone' => '内容资产记录已保存。',
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
        $import_notice = '';
        $import_error = '';

        if ( ! is_array( $current ) ) {
            $import_record = self::get_imported_pressure_record();
            if ( is_wp_error( $import_record ) ) {
                $import_error = $import_record->get_error_message();
            } elseif ( is_array( $import_record ) ) {
                $form = self::get_form_data_from_pressure_record( $import_record );
                $import_notice = sprintf(
                    '已从竞品压制分析记录 #%d 带入压制原因、反压制内容资产和回测问题，你可以直接生成内容资产方案。',
                    isset( $import_record['id'] ) ? absint( $import_record['id'] ) : 0
                );
            }
        }

        if ( ! is_array( $current ) ) {
            if ( $form['model_id'] === '' ) {
                $form['model_id'] = isset( $settings['default_model_id'] ) ? sanitize_key( (string) $settings['default_model_id'] ) : '';
            }
            if ( $form['model_id'] === '' ) {
                $form['model_id'] = self::get_first_usable_model_id();
            }
        }

        $rows = $table_exists ? self::get_records( $search, 20 ) : array();
        $base_url = add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) );
        ?>
        <div class="wrap qigeo-wrap">
            <h1>内容资产</h1>
            <p class="description">整理页面和内容计划。</p>

            <?php if ( ! $table_exists ) : ?>
                <div class="notice notice-error"><p>当前页面数据暂未准备好，请刷新页面后重试。</p></div>
            <?php endif; ?>

            <?php if ( $import_notice !== '' ) : ?>
                <div class="notice notice-success inline"><p><?php echo esc_html( $import_notice ); ?></p></div>
            <?php elseif ( $import_error !== '' ) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html( $import_error ); ?></p></div>
            <?php endif; ?>
            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2><?php echo is_array( $current ) ? '编辑内容资产记录' : '新建内容资产记录'; ?></h2>
                    <a class="button" href="<?php echo esc_url( $base_url ); ?>">新建空白记录</a>
                </div>

                <?php if ( is_array( $current ) ) : ?>
                    <?php $current_record_id = absint( $current['id'] ); ?>
                    <p class="qigeo-content-active-record">已加载记录 #<?php echo esc_html( (string) absint( $current['id'] ) ); ?>，你可以继续生成、调整或覆盖保存。</p>
                    <p class="qigeo-content-active-record">
                        <a class="button button-secondary" href="<?php echo esc_url( self::get_brand_entity_import_url( $current_record_id ) ); ?>">带去品牌实体</a>
                        <a class="button button-secondary" href="<?php echo esc_url( self::get_evidence_library_import_url( $current_record_id ) ); ?>">带去证据库</a>
                    </p>
                <?php endif; ?>

                <?php if ( empty( $model_options ) ) : ?>
                    <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
                <?php endif; ?>

                <form id="qigeo-content-form" class="qigeo-content-form" action="#" method="post">
                    <input type="hidden" id="qigeo-content-record-id" name="record_id" value="<?php echo esc_attr( (string) $form['record_id'] ); ?>" />

                    <div class="qigeo-content-grid">
                        <div class="qigeo-content-field qigeo-content-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '核心主题词 / 种子词', array( 'required', 'key' ), 'qigeo-content-seed-keyword' ) ); ?>
                            <input type="text" class="regular-text" id="qigeo-content-seed-keyword" name="seed_keyword" maxlength="255" value="<?php echo esc_attr( $form['seed_keyword'] ); ?>" placeholder="例如：wordpress 最好的中文主题" />
                        </div>
                        <div class="qigeo-content-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '目标 AI 平台', array( 'required', 'key' ), 'qigeo-content-target-platform' ) ); ?>
                            <input type="text" class="regular-text" id="qigeo-content-target-platform" name="target_platform" maxlength="120" value="<?php echo esc_attr( $form['target_platform'] ); ?>" placeholder="例如：豆包 / ChatGPT / DeepSeek" />
                        </div>
                        <div class="qigeo-content-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '希望被推荐的词', array( 'required', 'key' ), 'qigeo-content-target-keyword' ) ); ?>
                            <input type="text" class="regular-text" id="qigeo-content-target-keyword" name="target_keyword" maxlength="255" value="<?php echo esc_attr( $form['target_keyword'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-content-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '品牌 / 产品名', array( 'recommended' ), 'qigeo-content-brand-name' ) ); ?>
                            <input type="text" class="regular-text" id="qigeo-content-brand-name" name="brand_name" maxlength="190" value="<?php echo esc_attr( $form['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-content-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '目标人群 / 使用场景', array( 'recommended' ), 'qigeo-content-audience' ) ); ?>
                            <input type="text" class="regular-text" id="qigeo-content-audience" name="audience_persona" maxlength="190" value="<?php echo esc_attr( $form['audience_persona'] ); ?>" placeholder="例如：中文企业站、新手站长、建站服务商" />
                        </div>
                        <div class="qigeo-content-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '优先起草模型', array( 'recommended' ), 'qigeo-content-model-id' ) ); ?>
                            <select id="qigeo-content-model-id" name="model_id">
                                <option value="">请选择模型</option>
                                <?php foreach ( $model_options as $option ) : ?>
                                    <?php $label = ( isset( $option['provider_name'] ) ? (string) $option['provider_name'] : '-' ) . ' / ' . ( isset( $option['model_label'] ) ? (string) $option['model_label'] : (string) $option['model_id'] ) . ' (' . ( isset( $option['model_name'] ) ? (string) $option['model_name'] : '-' ) . ')'; ?>
                                    <option value="<?php echo esc_attr( isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>" data-provider-type="<?php echo esc_attr( isset( $option['provider_type'] ) ? (string) $option['provider_type'] : '' ); ?>" <?php selected( $form['model_id'], isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="qigeo-content-field qigeo-content-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '重点问法 / 关键问题', array( 'key' ), 'qigeo-content-priority-questions' ) ); ?>
                            <textarea id="qigeo-content-priority-questions" name="priority_questions" rows="7" placeholder="每行一个问题，建议从问法库里贴高价值问法、对比问法和场景问法。"><?php echo esc_textarea( $form['priority_questions'] ); ?></textarea>
                        </div>
                        <div class="qigeo-content-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '关键主张 / 核心卖点', array( 'key' ), 'qigeo-content-key-claims' ) ); ?>
                            <textarea id="qigeo-content-key-claims" name="key_claims" rows="8" placeholder="每行一条主张，例如：更适合中文企业站 / 上手更快 / 文档更完整。"><?php echo esc_textarea( $form['key_claims'] ); ?></textarea>
                        </div>
                        <div class="qigeo-content-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '公开证据 / 现有内容摘要', array( 'key' ), 'qigeo-content-evidence-summary' ) ); ?>
                            <textarea id="qigeo-content-evidence-summary" name="evidence_summary" rows="8" placeholder="每行一条，例如：中文演示站已上线 / FAQ页已补齐 / 企业站案例页已发布。"><?php echo esc_textarea( $form['evidence_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-content-field qigeo-content-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '回测结论 / 当前打不出来的问题', array( 'recommended' ), 'qigeo-content-backtest-summary' ) ); ?>
                            <textarea id="qigeo-content-backtest-summary" name="backtest_summary" rows="5" placeholder="每行一条，例如：对比 Astra 的问法仍然打不出来 / FAQ 型问题命中弱 / 文档页引用不足。"><?php echo esc_textarea( $form['backtest_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-content-field qigeo-content-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '补充限制 / 备注', array( 'recommended' ), 'qigeo-content-notes' ) ); ?>
                            <textarea id="qigeo-content-notes" name="notes" rows="4" placeholder="例如：优先做公开可抓取页面；不要虚构测评；先围绕中文企业站场景发第一批内容。"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
                        </div>
                    </div>

                    <div class="qigeo-content-actions">
                        <button type="button" class="button button-secondary" id="qigeo-generate-content-btn">生成内容资产</button>
                        <button type="button" class="button button-primary" id="qigeo-save-content-btn">保存记录</button>
                        <span class="qigeo-content-feedback" id="qigeo-content-feedback" aria-live="polite"></span>
                    </div>

                    <div class="qigeo-content-results">
                        <h3>内容资产输出结果</h3>
                        <div class="qigeo-content-grid">
                            <div class="qigeo-content-field qigeo-content-field--wide">
                                <label for="qigeo-content-result-summary"><strong>内容资产总览</strong></label>
                                <textarea id="qigeo-content-result-summary" name="summary" rows="4"><?php echo esc_textarea( $form['summary'] ); ?></textarea>
                            </div>
                            <div class="qigeo-content-field qigeo-content-field--wide">
                                <label for="qigeo-content-result-strategy"><strong>发布策略</strong></label>
                                <textarea id="qigeo-content-result-strategy" name="strategy_summary" rows="4"><?php echo esc_textarea( $form['strategy_summary'] ); ?></textarea>
                            </div>
                            <div class="qigeo-content-field">
                                <label for="qigeo-content-result-cornerstone"><strong>核心专题页 / 柱状页</strong></label>
                                <textarea id="qigeo-content-result-cornerstone" name="cornerstone_pages" rows="9"><?php echo esc_textarea( $form['cornerstone_pages'] ); ?></textarea>
                            </div>
                            <div class="qigeo-content-field">
                                <label for="qigeo-content-result-comparison"><strong>对比页</strong></label>
                                <textarea id="qigeo-content-result-comparison" name="comparison_pages" rows="9"><?php echo esc_textarea( $form['comparison_pages'] ); ?></textarea>
                            </div>
                            <div class="qigeo-content-field">
                                <label for="qigeo-content-result-scenario"><strong>场景页</strong></label>
                                <textarea id="qigeo-content-result-scenario" name="scenario_pages" rows="9"><?php echo esc_textarea( $form['scenario_pages'] ); ?></textarea>
                            </div>
                            <div class="qigeo-content-field">
                                <label for="qigeo-content-result-trust"><strong>信任型资产</strong></label>
                                <textarea id="qigeo-content-result-trust" name="trust_assets" rows="9"><?php echo esc_textarea( $form['trust_assets'] ); ?></textarea>
                            </div>
                            <div class="qigeo-content-field">
                                <label for="qigeo-content-result-distribution"><strong>分发型资产</strong></label>
                                <textarea id="qigeo-content-result-distribution" name="distribution_assets" rows="8"><?php echo esc_textarea( $form['distribution_assets'] ); ?></textarea>
                            </div>
                            <div class="qigeo-content-field">
                                <label for="qigeo-content-result-schema"><strong>结构化与引用辅助</strong></label>
                                <textarea id="qigeo-content-result-schema" name="schema_plan" rows="8"><?php echo esc_textarea( $form['schema_plan'] ); ?></textarea>
                            </div>
                            <div class="qigeo-content-field">
                                <label for="qigeo-content-result-internal"><strong>内链与锚文本规划</strong></label>
                                <textarea id="qigeo-content-result-internal" name="internal_link_plan" rows="8"><?php echo esc_textarea( $form['internal_link_plan'] ); ?></textarea>
                            </div>
                            <div class="qigeo-content-field qigeo-content-field--wide">
                                <label for="qigeo-content-result-steps"><strong>执行顺序</strong></label>
                                <textarea id="qigeo-content-result-steps" name="execution_steps" rows="6"><?php echo esc_textarea( $form['execution_steps'] ); ?></textarea>
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
                <form method="get" class="qigeo-content-search">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索主题词 / 平台 / 目标词 / 品牌 / 摘要" />
                    <button type="submit" class="button">搜索</button>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">重置</a>
                </form>
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>主题词 / 目标词</th><th>平台 / 人群</th><th>资产数</th><th>摘要</th><th>模型</th><th>更新时间</th><th>操作</th></tr></thead>
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
        $brand_entity_url = self::get_brand_entity_import_url( $row_id );
        $evidence_library_url = self::get_evidence_library_import_url( $row_id );
        $result = self::decode_result_json( isset( $row['result_json'] ) ? (string) $row['result_json'] : '' );
        $target = isset( $row['target_keyword'] ) ? (string) $row['target_keyword'] : '';
        $platform = isset( $row['target_platform'] ) ? (string) $row['target_platform'] : '';
        $brand = isset( $row['brand_name'] ) ? (string) $row['brand_name'] : '';
        $audience = isset( $row['audience_persona'] ) ? (string) $row['audience_persona'] : '';
        ?>
        <tr>
            <td><?php echo esc_html( (string) $row_id ); ?></td>
            <td><strong><?php echo esc_html( isset( $row['seed_keyword'] ) ? (string) $row['seed_keyword'] : '' ); ?></strong><div class="qigeo-content-row-meta"><?php echo esc_html( implode( ' / ', array_filter( array( $target, $brand ) ) ) ); ?></div></td>
            <td><strong><?php echo esc_html( $platform ); ?></strong><div class="qigeo-content-row-meta"><?php echo esc_html( $audience ); ?></div></td>
            <td><?php echo esc_html( (string) self::count_asset_items( $result ) ); ?></td>
            <td><?php echo esc_html( self::excerpt_text( isset( $row['result_summary'] ) ? (string) $row['result_summary'] : '' ) ); ?></td>
            <td><?php echo esc_html( isset( $row['model'] ) ? (string) $row['model'] : '' ); ?></td>
            <td><?php echo esc_html( isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '' ); ?></td>
            <td>
                <a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">编辑</a>
                <a class="button button-small" href="<?php echo esc_url( $brand_entity_url ); ?>">品牌实体</a>
                <a class="button button-small" href="<?php echo esc_url( $evidence_library_url ); ?>">证据库</a>
            </td>
        </tr>
        <?php
    }

    private static function get_brand_entity_import_url( $record_id ) {
        $record_id = absint( $record_id );
        if ( $record_id < 1 ) {
            return '';
        }

        return add_query_arg(
            array(
                'page' => 'qigeo-brand-entity-library',
                'source' => 'content_asset',
                'content_asset_id' => $record_id,
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function get_evidence_library_import_url( $record_id ) {
        $record_id = absint( $record_id );
        if ( $record_id < 1 ) {
            return '';
        }

        return add_query_arg(
            array(
                'page' => 'qigeo-evidence-library',
                'source' => 'content_asset',
                'content_asset_id' => $record_id,
            ),
            admin_url( 'admin.php' )
        );
    }

    public static function ajax_generate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        check_ajax_referer( 'qigeo_generate_content_asset_center_nonce', 'nonce' );

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
                'message' => '内容资产方案已生成，可继续微调后再保存。',
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
        check_ajax_referer( 'qigeo_save_content_asset_center_nonce', 'nonce' );

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
        if ( $result['summary'] === '' && empty( $result['cornerstone_pages'] ) && empty( $result['comparison_pages'] ) && empty( $result['execution_steps'] ) ) {
            wp_send_json_error( array( 'message' => '请先生成或填写内容资产结果后再保存' ) );
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
            'priority_questions' => $form['priority_questions'],
            'key_claims' => $form['key_claims'],
            'evidence_summary' => $form['evidence_summary'],
            'backtest_summary' => $form['backtest_summary'],
            'notes' => $form['notes'],
            'provider_id' => isset( $provider_model['provider_id'] ) ? (string) $provider_model['provider_id'] : '',
            'model_id' => isset( $provider_model['model_id'] ) ? (string) $provider_model['model_id'] : '',
            'model' => isset( $provider_model['model_name'] ) ? (string) $provider_model['model_name'] : '',
            'result_summary' => $result['summary'],
            'result_json' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'updated_at' => $now,
        );
        $formats = array_fill( 0, 16, '%s' );

        if ( $record_id > 0 && self::get_item_by_id( $record_id ) ) {
            $updated = $wpdb->update( $table, $data, array( 'id' => $record_id ), $formats, array( '%d' ) );
            if ( $updated === false ) {
                wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
            }
            wp_send_json_success( array( 'message' => '内容资产记录已更新。', 'record_id' => $record_id ) );
        }

        $data['created_by'] = $current_user_id;
        $data['created_at'] = $now;
        $inserted = $wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%s' ) ) );
        if ( $inserted === false ) {
            wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
        }

        wp_send_json_success( array( 'message' => '内容资产记录已保存。', 'record_id' => absint( $wpdb->insert_id ) ) );
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
            'priority_questions' => self::parse_plain_lines( $form['priority_questions'], 15 ),
            'key_claims' => self::parse_plain_lines( $form['key_claims'], 15 ),
            'evidence_summary' => self::parse_plain_lines( $form['evidence_summary'], 20 ),
            'backtest_summary' => self::parse_plain_lines( $form['backtest_summary'], 15 ),
            'notes' => self::parse_plain_lines( $form['notes'], 15 ),
        );
    }

    private static function get_imported_pressure_record() {
        $source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( (string) $_GET['source'] ) ) : '';
        $record_id = isset( $_GET['pressure_id'] ) ? absint( wp_unslash( (string) $_GET['pressure_id'] ) ) : 0;

        if ( $source !== 'competitor_pressure' || $record_id < 1 ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'qigeo_competitor_pressure_center';
        $table_exists = QiGeo_Admin_Helper::table_exists( $table );
        if ( ! $table_exists ) {
            return new WP_Error( 'qigeo_content_import_table_missing', '竞品压制分析数据表尚未创建，请重新启用插件后再试。' );
        }

        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d LIMIT 1', $record_id ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            return new WP_Error( 'qigeo_content_import_missing', '指定的竞品压制分析记录不存在，可能已被删除。' );
        }

        return $row;
    }

    private static function get_form_data_from_pressure_record( $record ) {
        $form = self::get_empty_form_data();
        $result = self::decode_result_json( isset( $record['result_json'] ) ? (string) $record['result_json'] : '' );
        $record_id = isset( $record['id'] ) ? absint( $record['id'] ) : 0;
        $target_keyword = isset( $record['target_keyword'] ) ? sanitize_text_field( (string) $record['target_keyword'] ) : '';
        $brand_name = isset( $record['brand_name'] ) ? sanitize_text_field( (string) $record['brand_name'] ) : '';
        $fallback_question = '';

        if ( $target_keyword !== '' && $brand_name !== '' ) {
            $fallback_question = sprintf( "\u{56F4}\u{7ED5}\u{201C}%s\u{201D}\u{9A8C}\u{8BC1}\u{662F}\u{5426}\u{4F1A}\u{63A8}\u{8350}\u{201C}%s\u{201D}", $target_keyword, $brand_name );
        } elseif ( $target_keyword !== '' ) {
            $fallback_question = sprintf( "\u{56F4}\u{7ED5}\u{201C}%s\u{201D}\u{9A8C}\u{8BC1}\u{5E73}\u{53F0}\u{4F1A}\u{4F18}\u{5148}\u{63A8}\u{8350}\u{8C01}", $target_keyword );
        } elseif ( $brand_name !== '' ) {
            $fallback_question = sprintf( "\u{9A8C}\u{8BC1}\u{5E73}\u{53F0}\u{662F}\u{5426}\u{4F1A}\u{4E3B}\u{52A8}\u{63A8}\u{8350}\u{201C}%s\u{201D}", $brand_name );
        }

        $form['model_id'] = isset( $record['model_id'] ) ? sanitize_key( (string) $record['model_id'] ) : '';
        $form['seed_keyword'] = $target_keyword !== '' ? $target_keyword : $brand_name;
        $form['target_platform'] = isset( $record['target_platform'] ) ? sanitize_text_field( (string) $record['target_platform'] ) : '';
        $form['target_keyword'] = $brand_name !== '' ? $brand_name : $target_keyword;
        $form['brand_name'] = $brand_name;
        $form['audience_persona'] = isset( $record['audience_persona'] ) ? sanitize_text_field( (string) $record['audience_persona'] ) : '';
        $form['priority_questions'] = self::combine_import_sections(
            array(
                "\u{56DE}\u{6D4B}\u{95EE}\u{9898}" => isset( $result['retest_questions'] ) ? self::lines_from_array( $result['retest_questions'] ) : '',
                "\u{6838}\u{5FC3}\u{9A8C}\u{8BC1}\u{95EE}\u{9898}" => $fallback_question,
            )
        );
        $form['key_claims'] = self::combine_import_sections(
            array(
                "\u{6211}\u{4EEC}\u{5DF2}\u{6709}\u{4F18}\u{52BF} / \u{53EF}\u{8BF4}\u{8BC1}\u{636E}" => isset( $record['brand_strengths'] ) ? sanitize_textarea_field( (string) $record['brand_strengths'] ) : '',
                "\u{53EF}\u{6253}\u{7684}\u{5BF9}\u{6BD4}\u{89D2}\u{5EA6}" => isset( $result['comparison_angles'] ) ? self::lines_from_array( $result['comparison_angles'] ) : '',
                "\u{8BDD}\u{672F}\u{8C03}\u{6574}\u{65B9}\u{5411}" => isset( $result['messaging_shifts'] ) ? self::lines_from_array( $result['messaging_shifts'] ) : '',
            )
        );
        $form['evidence_summary'] = self::combine_import_sections(
            array(
                "\u{5DF2}\u{6709}\u{516C}\u{5F00}\u{8BC1}\u{636E}" => isset( $record['brand_strengths'] ) ? sanitize_textarea_field( (string) $record['brand_strengths'] ) : '',
                "\u{8BC1}\u{636E}\u{7F3A}\u{53E3}" => isset( $record['evidence_gap_summary'] ) ? sanitize_textarea_field( (string) $record['evidence_gap_summary'] ) : '',
                "\u{6211}\u{4EEC}\u{7F3A}\u{5931}\u{7684}\u{4FE1}\u{53F7}" => isset( $result['missing_brand_signals'] ) ? self::lines_from_array( $result['missing_brand_signals'] ) : '',
            )
        );
        $form['backtest_summary'] = self::combine_import_sections(
            array(
                "\u{538B}\u{5236}\u{603B}\u{89C8}" => isset( $result['summary'] ) ? sanitize_textarea_field( (string) $result['summary'] ) : ( isset( $record['result_summary'] ) ? sanitize_textarea_field( (string) $record['result_summary'] ) : '' ),
                "\u{538B}\u{5236}\u{5224}\u{65AD}" => isset( $result['dominance_judgement'] ) ? sanitize_textarea_field( (string) $result['dominance_judgement'] ) : '',
                "\u{538B}\u{5236}\u{539F}\u{56E0}" => isset( $result['suppression_reasons'] ) ? self::lines_from_array( $result['suppression_reasons'] ) : '',
                "\u{7ADE}\u{54C1}\u{4F18}\u{52BF}\u{4FE1}\u{53F7}" => isset( $result['competitor_advantages'] ) ? self::lines_from_array( $result['competitor_advantages'] ) : '',
                "\u{53CD}\u{538B}\u{5236}\u{5185}\u{5BB9}\u{8D44}\u{4EA7}" => isset( $result['counter_assets'] ) ? self::lines_from_array( $result['counter_assets'] ) : '',
                "\u{6267}\u{884C}\u{987A}\u{5E8F}" => isset( $result['execution_steps'] ) ? self::lines_from_array( $result['execution_steps'] ) : '',
            )
        );
        $form['notes'] = self::combine_import_sections(
            array(
                "\u{6765}\u{6E90}\u{8BB0}\u{5F55}" => $record_id > 0 ? "\u{7ADE}\u{54C1}\u{538B}\u{5236}\u{5206}\u{6790} #" . $record_id : '',
                "\u{539F}\u{59CB}\u{5907}\u{6CE8}" => isset( $record['notes'] ) ? sanitize_textarea_field( (string) $record['notes'] ) : '',
            )
        );

        return $form;
    }

    private static function combine_import_sections( $sections ) {
        $blocks = array();

        foreach ( $sections as $label => $content ) {
            $value = trim( sanitize_textarea_field( (string) $content ) );
            if ( $value === '' ) {
                continue;
            }

            $blocks[] = $label . ":\n" . $value;
        }

        return implode( "\n\n", $blocks );
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
            'priority_questions' => '',
            'key_claims' => '',
            'evidence_summary' => '',
            'backtest_summary' => '',
            'notes' => '',
            'summary' => '',
            'strategy_summary' => '',
            'cornerstone_pages' => '',
            'comparison_pages' => '',
            'scenario_pages' => '',
            'trust_assets' => '',
            'distribution_assets' => '',
            'schema_plan' => '',
            'internal_link_plan' => '',
            'execution_steps' => '',
        );
    }

    private static function record_to_form_data( $record ) {
        $form = self::get_empty_form_data();
        foreach ( array( 'id' => 'record_id', 'model_id' => 'model_id', 'seed_keyword' => 'seed_keyword', 'target_platform' => 'target_platform', 'target_keyword' => 'target_keyword', 'brand_name' => 'brand_name', 'audience_persona' => 'audience_persona', 'priority_questions' => 'priority_questions', 'key_claims' => 'key_claims', 'evidence_summary' => 'evidence_summary', 'backtest_summary' => 'backtest_summary', 'notes' => 'notes' ) as $source => $target ) {
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
        $form['strategy_summary'] = isset( $result['strategy_summary'] ) ? sanitize_textarea_field( (string) $result['strategy_summary'] ) : '';
        foreach ( array( 'cornerstone_pages', 'comparison_pages', 'scenario_pages', 'trust_assets', 'distribution_assets', 'schema_plan', 'internal_link_plan', 'execution_steps' ) as $key ) {
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
            'priority_questions' => isset( $_POST['priority_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['priority_questions'] ) ) : '',
            'key_claims' => isset( $_POST['key_claims'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['key_claims'] ) ) : '',
            'evidence_summary' => isset( $_POST['evidence_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['evidence_summary'] ) ) : '',
            'backtest_summary' => isset( $_POST['backtest_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['backtest_summary'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        );
    }

    private static function get_result_data_from_request() {
        return array(
            'summary' => self::limit_text( isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '', 800 ),
            'strategy_summary' => self::limit_text( isset( $_POST['strategy_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['strategy_summary'] ) ) : '', 800 ),
            'cornerstone_pages' => self::parse_plain_lines( isset( $_POST['cornerstone_pages'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['cornerstone_pages'] ) ) : '', 20 ),
            'comparison_pages' => self::parse_plain_lines( isset( $_POST['comparison_pages'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['comparison_pages'] ) ) : '', 20 ),
            'scenario_pages' => self::parse_plain_lines( isset( $_POST['scenario_pages'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['scenario_pages'] ) ) : '', 20 ),
            'trust_assets' => self::parse_plain_lines( isset( $_POST['trust_assets'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['trust_assets'] ) ) : '', 20 ),
            'distribution_assets' => self::parse_plain_lines( isset( $_POST['distribution_assets'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['distribution_assets'] ) ) : '', 20 ),
            'schema_plan' => self::parse_plain_lines( isset( $_POST['schema_plan'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['schema_plan'] ) ) : '', 15 ),
            'internal_link_plan' => self::parse_plain_lines( isset( $_POST['internal_link_plan'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['internal_link_plan'] ) ) : '', 15 ),
            'execution_steps' => self::parse_plain_lines( isset( $_POST['execution_steps'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['execution_steps'] ) ) : '', 15 ),
        );
    }

    private static function validate_base_form_data( $form ) {
        if ( empty( $form['seed_keyword'] ) ) {
            return new WP_Error( 'qigeo_content_seed_required', '请先填写核心主题词 / 种子词' );
        }
        if ( empty( $form['target_platform'] ) ) {
            return new WP_Error( 'qigeo_content_platform_required', '请先填写目标 AI 平台' );
        }
        if ( empty( $form['target_keyword'] ) ) {
            return new WP_Error( 'qigeo_content_target_required', '请先填写希望被推荐的词' );
        }
        if ( empty( self::parse_plain_lines( $form['priority_questions'], 15 ) ) ) {
            return new WP_Error( 'qigeo_content_questions_required', '请先填写至少一条重点问法' );
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
        return $wpdb->prefix . 'qigeo_content_asset_center';
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
            return new WP_Error( 'qigeo_content_model_missing', '请先在启灵GEO配置并启用至少一个可用模型' );
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
            'strategy_summary' => self::limit_text( isset( $parsed['strategy_summary'] ) ? sanitize_textarea_field( (string) $parsed['strategy_summary'] ) : ( isset( $parsed['content_strategy'] ) ? sanitize_textarea_field( (string) $parsed['content_strategy'] ) : '' ), 800 ),
            'cornerstone_pages' => self::normalize_string_list( isset( $parsed['cornerstone_pages'] ) ? $parsed['cornerstone_pages'] : ( isset( $parsed['pillar_pages'] ) ? $parsed['pillar_pages'] : array() ), 20 ),
            'comparison_pages' => self::normalize_string_list( isset( $parsed['comparison_pages'] ) ? $parsed['comparison_pages'] : array(), 20 ),
            'scenario_pages' => self::normalize_string_list( isset( $parsed['scenario_pages'] ) ? $parsed['scenario_pages'] : array(), 20 ),
            'trust_assets' => self::normalize_string_list( isset( $parsed['trust_assets'] ) ? $parsed['trust_assets'] : ( isset( $parsed['support_assets'] ) ? $parsed['support_assets'] : array() ), 20 ),
            'distribution_assets' => self::normalize_string_list( isset( $parsed['distribution_assets'] ) ? $parsed['distribution_assets'] : array(), 20 ),
            'schema_plan' => self::normalize_string_list( isset( $parsed['schema_plan'] ) ? $parsed['schema_plan'] : array(), 15 ),
            'internal_link_plan' => self::normalize_string_list( isset( $parsed['internal_link_plan'] ) ? $parsed['internal_link_plan'] : ( isset( $parsed['internal_links'] ) ? $parsed['internal_links'] : array() ), 15 ),
            'execution_steps' => self::normalize_string_list( isset( $parsed['execution_steps'] ) ? $parsed['execution_steps'] : array(), 15 ),
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

    private static function count_asset_items( $result ) {
        $count = 0;
        foreach ( array( 'cornerstone_pages', 'comparison_pages', 'scenario_pages', 'trust_assets', 'distribution_assets' ) as $key ) {
            if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) ) {
                $count += count( $result[ $key ] );
            }
        }
        return $count;
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
        return "你是 GEO 内容资产策划助手。现在要基于一组重点问法、关键主张、公开证据和回测结论，规划下一轮最值得发布的公开页面与公开资产。请严格输出 JSON，不要 markdown，不要解释。\n"
            . "{\n"
            . "\"summary\":\"80-220字，总结当前最该先发哪类资产\",\n"
            . "\"strategy_summary\":\"80-220字，说明应该按什么顺序发布这些页面和资产\",\n"
            . "\"cornerstone_pages\":[\"页面类型/标题方向 || 解决问题 || 支撑主张 || 优先级：高/中\"],\n"
            . "\"comparison_pages\":[\"对比页标题方向 || 目标竞品/对比对象 || 解决问题 || 优先级：高/中\"],\n"
            . "\"scenario_pages\":[\"场景页标题方向 || 目标人群/场景 || 要解决的问题\"],\n"
            . "\"trust_assets\":[\"FAQ/案例/教程/文档/更新日志 等资产 || 作用 || 为什么现在要补\"],\n"
            . "\"distribution_assets\":[\"适合做外部分发或辅助引用的公开资产 || 作用\"],\n"
            . "\"schema_plan\":[\"适合做 FAQ / HowTo / Product / Organization 等结构化点\"],\n"
            . "\"internal_link_plan\":[\"内链来源页 -> 目标页 || 锚文本方向 || 目的\"],\n"
            . "\"execution_steps\":[\"下一步动作1\", \"动作2\"]\n"
            . "}\n"
            . "要求：1) 重点围绕可公开抓取、可被引用、可被验证的内容资产，不要规划私域材料。2) 如果 payload 包含 global_brand_profile，必须优先基于其中的网站定位、核心产品/服务、适合/不适合推荐场景、核心页面和权威证明来规划资产。3) 优先输出专题页、对比页、场景页、FAQ、案例、教程、文档、更新日志等真正能公开发布的资产。4) 结果必须和 priority_questions、key_claims、evidence_summary、backtest_summary 对得上，不要空泛。5) 不要虚构销量、奖项、媒体背书、用户评价、官方合作或测试数据。6) execution_steps 要有先后顺序。7) 输出必须是合法 JSON。";
    }
}
