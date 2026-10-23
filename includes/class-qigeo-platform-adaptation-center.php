<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Platform_Adaptation_Center {

    const MENU_SLUG = 'qigeo-platform-adaptation-center';
    const AJAX_GENERATE_ACTION = 'qigeo_generate_platform_adaptation_center';
    const AJAX_SAVE_ACTION = 'qigeo_save_platform_adaptation_center';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '平台适配中心',
            '平台适配中心',
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
            'qigeo-platform-adaptation-admin',
            QIGEO_URL . 'assets/platform-adaptation-admin.js',
            array( 'qigeo-admin' ),
            QIGEO_VERSION,
            true
        );

        wp_localize_script(
            'qigeo-platform-adaptation-admin',
            'qigeoPlatformAdaptationCenterData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_platform_adaptation_center_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_platform_adaptation_center_nonce' ),
                'messages' => array(
                    'generating' => '正在生成平台适配方案...',
                    'saving' => '正在保存平台适配记录...',
                    'generateDone' => '平台适配方案已生成，可继续微调后再保存。',
                    'saveDone' => '平台适配记录已保存。',
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
            <h1>平台适配中心</h1>
            <p class="description">整理不同平台的表达方向。</p>

            <?php if ( ! $table_exists ) : ?>
                <div class="notice notice-error"><p>当前页面数据暂未准备好，请刷新页面后重试。</p></div>
            <?php endif; ?>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2><?php echo is_array( $current ) ? '编辑平台适配记录' : '新建平台适配记录'; ?></h2>
                    <a class="button" href="<?php echo esc_url( $base_url ); ?>">新建空白记录</a>
                </div>

                <?php if ( is_array( $current ) ) : ?>
                    <p class="qigeo-platform-active-record">已加载记录 #<?php echo esc_html( (string) absint( $current['id'] ) ); ?>，你可以继续生成、调整或覆盖保存。</p>
                <?php endif; ?>

                <?php if ( empty( $model_options ) ) : ?>
                    <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
                <?php endif; ?>

                <form id="qigeo-platform-form" class="qigeo-platform-form" action="#" method="post">
                    <input type="hidden" id="qigeo-platform-record-id" name="record_id" value="<?php echo esc_attr( (string) $form['record_id'] ); ?>" />

                    <div class="qigeo-platform-grid">
                        <div class="qigeo-platform-field">
                            <label for="qigeo-platform-target-platform"><strong>目标 AI 平台</strong></label>
                            <input type="text" class="regular-text" id="qigeo-platform-target-platform" name="target_platform" maxlength="120" value="<?php echo esc_attr( $form['target_platform'] ); ?>" placeholder="例如：豆包 / ChatGPT / DeepSeek" />
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-platform-target-keyword"><strong>目标推荐词</strong></label>
                            <input type="text" class="regular-text" id="qigeo-platform-target-keyword" name="target_keyword" maxlength="255" value="<?php echo esc_attr( $form['target_keyword'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-platform-brand-name"><strong>品牌 / 产品名</strong></label>
                            <input type="text" class="regular-text" id="qigeo-platform-brand-name" name="brand_name" maxlength="190" value="<?php echo esc_attr( $form['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-platform-audience"><strong>目标人群 / 场景</strong></label>
                            <input type="text" class="regular-text" id="qigeo-platform-audience" name="audience_persona" maxlength="190" value="<?php echo esc_attr( $form['audience_persona'] ); ?>" placeholder="例如：中文企业站、新手站长、建站服务商" />
                        </div>
                    <div class="qigeo-platform-field">
                        <label for="qigeo-platform-model-id"><strong>优先生成模型</strong></label>
                            <select id="qigeo-platform-model-id" name="model_id">
                                <option value="">请选择模型</option>
                                <?php foreach ( $model_options as $option ) : ?>
                                    <?php $label = ( isset( $option['provider_name'] ) ? (string) $option['provider_name'] : '-' ) . ' / ' . ( isset( $option['model_label'] ) ? (string) $option['model_label'] : (string) $option['model_id'] ) . ' (' . ( isset( $option['model_name'] ) ? (string) $option['model_name'] : '-' ) . ')'; ?>
                                    <option value="<?php echo esc_attr( isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>" data-provider-type="<?php echo esc_attr( isset( $option['provider_type'] ) ? (string) $option['provider_type'] : '' ); ?>" <?php selected( $form['model_id'], isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">不选也可以，系统会自动处理。</p>
                        </div>
                        <div class="qigeo-platform-field qigeo-platform-field--wide">
                            <label for="qigeo-platform-brand-entity-summary"><strong>品牌实体摘要 / 标准口径</strong></label>
                            <textarea id="qigeo-platform-brand-entity-summary" name="brand_entity_summary" rows="7" placeholder="可直接粘贴品牌实体库中的实体定义、标准命名、核心事实、FAQ 事实点等。"><?php echo esc_textarea( $form['brand_entity_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-platform-evidence-summary"><strong>公开证据摘要</strong></label>
                            <textarea id="qigeo-platform-evidence-summary" name="evidence_summary" rows="8" placeholder="例如：官网页面、FAQ、案例页、更新日志、教程文档、演示站等。"><?php echo esc_textarea( $form['evidence_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-platform-backtest-summary"><strong>回测结论 / 当前问题</strong></label>
                            <textarea id="qigeo-platform-backtest-summary" name="backtest_summary" rows="8" placeholder="例如：某类问题仍打不出目标词、竞品问法不稳定、平台更愿意说泛类目。"><?php echo esc_textarea( $form['backtest_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-platform-content-asset-summary"><strong>内容资产摘要</strong></label>
                            <textarea id="qigeo-platform-content-asset-summary" name="content_asset_summary" rows="8" placeholder="例如：专题页、对比页、场景页、FAQ、教程、案例、外部分发资产等。"><?php echo esc_textarea( $form['content_asset_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-platform-competitor-context"><strong>竞品语境 / 对比场景</strong></label>
                            <textarea id="qigeo-platform-competitor-context" name="competitor_context" rows="8" placeholder="例如：常被一起比较的主题、常见对比句式、平台里常出现的竞品名称。"><?php echo esc_textarea( $form['competitor_context'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field qigeo-platform-field--wide">
                            <label for="qigeo-platform-notes"><strong>补充限制 / 备注</strong></label>
                            <textarea id="qigeo-platform-notes" name="notes" rows="4" placeholder="例如：避免绝对化表述；优先中文企业站语境；只用可公开验证信息；不要虚构测评与评价。"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
                        </div>
                    </div>

                    <div class="qigeo-platform-actions">
                        <button type="button" class="button button-secondary" id="qigeo-generate-platform-btn">生成适配方案</button>
                        <button type="button" class="button button-primary" id="qigeo-save-platform-btn">保存记录</button>
                        <span class="qigeo-platform-feedback" id="qigeo-platform-feedback" aria-live="polite"></span>
                    </div>

                    <div class="qigeo-platform-results">
                        <h3>平台适配输出结果</h3>
                        <div class="qigeo-platform-grid">
                            <div class="qigeo-platform-field qigeo-platform-field--wide">
                                <label for="qigeo-platform-result-summary"><strong>适配总览</strong></label>
                                <textarea id="qigeo-platform-result-summary" name="summary" rows="4"><?php echo esc_textarea( $form['summary'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field qigeo-platform-field--wide">
                                <label for="qigeo-platform-result-profile"><strong>平台画像 / 当前判断</strong></label>
                                <textarea id="qigeo-platform-result-profile" name="platform_profile" rows="4"><?php echo esc_textarea( $form['platform_profile'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-platform-result-answer-style"><strong>回答风格规则</strong></label>
                                <textarea id="qigeo-platform-result-answer-style" name="answer_style_rules" rows="9"><?php echo esc_textarea( $form['answer_style_rules'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-platform-result-evidence"><strong>证据偏好</strong></label>
                                <textarea id="qigeo-platform-result-evidence" name="evidence_preferences" rows="9"><?php echo esc_textarea( $form['evidence_preferences'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-platform-result-question-patterns"><strong>高适配问法类型</strong></label>
                                <textarea id="qigeo-platform-result-question-patterns" name="question_patterns" rows="9"><?php echo esc_textarea( $form['question_patterns'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-platform-result-content-tweaks"><strong>内容资产改法</strong></label>
                                <textarea id="qigeo-platform-result-content-tweaks" name="content_asset_tweaks" rows="9"><?php echo esc_textarea( $form['content_asset_tweaks'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-platform-result-entity-rules"><strong>实体表达规则</strong></label>
                                <textarea id="qigeo-platform-result-entity-rules" name="entity_expression_rules" rows="9"><?php echo esc_textarea( $form['entity_expression_rules'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-platform-result-competitor"><strong>竞品语境处理</strong></label>
                                <textarea id="qigeo-platform-result-competitor" name="competitor_handling" rows="9"><?php echo esc_textarea( $form['competitor_handling'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-platform-result-risks"><strong>风险提醒</strong></label>
                                <textarea id="qigeo-platform-result-risks" name="risk_flags" rows="8"><?php echo esc_textarea( $form['risk_flags'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-platform-result-retest"><strong>回测提示词</strong></label>
                                <textarea id="qigeo-platform-result-retest" name="retest_prompts" rows="8"><?php echo esc_textarea( $form['retest_prompts'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field qigeo-platform-field--wide">
                                <label for="qigeo-platform-result-steps"><strong>执行顺序</strong></label>
                                <textarea id="qigeo-platform-result-steps" name="execution_steps" rows="6"><?php echo esc_textarea( $form['execution_steps'] ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2>最近记录</h2>
                    <span class="description">最多显示最近 20 条，可搜索平台、目标词、品牌、人群或摘要。</span>
                </div>
                <form method="get" class="qigeo-platform-search">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索平台 / 目标词 / 品牌 / 人群 / 摘要" />
                    <button type="submit" class="button">搜索</button>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">重置</a>
                </form>
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>平台 / 目标词</th><th>品牌 / 人群</th><th>适配项数</th><th>摘要</th><th>模型</th><th>更新时间</th><th>操作</th></tr></thead>
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
        ?>
        <tr>
            <td><?php echo esc_html( (string) $row_id ); ?></td>
            <td>
                <strong><?php echo esc_html( isset( $row['target_platform'] ) ? (string) $row['target_platform'] : '' ); ?></strong>
                <div class="qigeo-platform-row-meta"><?php echo esc_html( isset( $row['target_keyword'] ) ? (string) $row['target_keyword'] : '' ); ?></div>
            </td>
            <td>
                <strong><?php echo esc_html( isset( $row['brand_name'] ) ? (string) $row['brand_name'] : '' ); ?></strong>
                <div class="qigeo-platform-row-meta"><?php echo esc_html( isset( $row['audience_persona'] ) ? (string) $row['audience_persona'] : '' ); ?></div>
            </td>
            <td><?php echo esc_html( (string) self::count_adaptation_items( $result ) ); ?></td>
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
        check_ajax_referer( 'qigeo_generate_platform_adaptation_center_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $form = self::get_request_form_data();
        $validation = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
        }

        $provider_model = self::resolve_platform_model( $form['model_id'], $form['target_platform'] );
        if ( is_wp_error( $provider_model ) ) {
            wp_send_json_error( array( 'message' => $provider_model->get_error_message() ) );
        }

        $result = self::run_generation( $provider_model, $form );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success(
            array(
                'message' => '平台适配方案已生成，可继续微调后再保存。',
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
        check_ajax_referer( 'qigeo_save_platform_adaptation_center_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $form = self::get_request_form_data();
        $validation = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
        }

        $provider_model = self::resolve_platform_model( $form['model_id'], $form['target_platform'] );
        if ( is_wp_error( $provider_model ) ) {
            wp_send_json_error( array( 'message' => $provider_model->get_error_message() ) );
        }

        $result = self::get_result_data_from_request();
        if ( $result['summary'] === '' && empty( $result['answer_style_rules'] ) && empty( $result['question_patterns'] ) && empty( $result['execution_steps'] ) ) {
            wp_send_json_error( array( 'message' => '请先生成或填写平台适配结果后再保存' ) );
        }

        global $wpdb;
        $table = self::get_table_name();
        $now = current_time( 'mysql' );
        $record_id = absint( $form['record_id'] );
        $current_user_id = get_current_user_id();
        $data = array(
            'target_platform' => $form['target_platform'],
            'target_keyword' => $form['target_keyword'],
            'brand_name' => $form['brand_name'],
            'audience_persona' => $form['audience_persona'],
            'brand_entity_summary' => $form['brand_entity_summary'],
            'evidence_summary' => $form['evidence_summary'],
            'backtest_summary' => $form['backtest_summary'],
            'content_asset_summary' => $form['content_asset_summary'],
            'competitor_context' => $form['competitor_context'],
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
            wp_send_json_success( array( 'message' => '平台适配记录已更新。', 'record_id' => $record_id ) );
        }

        $data['created_by'] = $current_user_id;
        $data['created_at'] = $now;
        $inserted = $wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%s' ) ) );
        if ( $inserted === false ) {
            wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
        }

        wp_send_json_success( array( 'message' => '平台适配记录已保存。', 'record_id' => absint( $wpdb->insert_id ) ) );
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
            'target_platform' => $form['target_platform'],
            'target_keyword' => $form['target_keyword'],
            'brand_name' => $form['brand_name'],
            'global_brand_profile' => class_exists( 'QiGeo_Brand_Entity_Library' ) ? QiGeo_Brand_Entity_Library::get_global_profile_context() : array(),
            'audience_persona' => $form['audience_persona'],
            'brand_entity_summary' => $form['brand_entity_summary'],
            'evidence_summary' => $form['evidence_summary'],
            'backtest_summary' => $form['backtest_summary'],
            'content_asset_summary' => $form['content_asset_summary'],
            'competitor_context' => $form['competitor_context'],
            'notes' => $form['notes'],
        );
    }

    private static function get_empty_form_data() {
        return array(
            'record_id' => 0,
            'model_id' => '',
            'target_platform' => '',
            'target_keyword' => '',
            'brand_name' => '',
            'audience_persona' => '',
            'brand_entity_summary' => '',
            'evidence_summary' => '',
            'backtest_summary' => '',
            'content_asset_summary' => '',
            'competitor_context' => '',
            'notes' => '',
            'summary' => '',
            'platform_profile' => '',
            'answer_style_rules' => '',
            'evidence_preferences' => '',
            'question_patterns' => '',
            'content_asset_tweaks' => '',
            'entity_expression_rules' => '',
            'competitor_handling' => '',
            'risk_flags' => '',
            'retest_prompts' => '',
            'execution_steps' => '',
        );
    }
    private static function record_to_form_data( $record ) {
        $form = self::get_empty_form_data();
        foreach (
            array(
                'id' => 'record_id',
                'model_id' => 'model_id',
                'target_platform' => 'target_platform',
                'target_keyword' => 'target_keyword',
                'brand_name' => 'brand_name',
                'audience_persona' => 'audience_persona',
                'brand_entity_summary' => 'brand_entity_summary',
                'evidence_summary' => 'evidence_summary',
                'backtest_summary' => 'backtest_summary',
                'content_asset_summary' => 'content_asset_summary',
                'competitor_context' => 'competitor_context',
                'notes' => 'notes',
            ) as $source => $target
        ) {
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
        $form['platform_profile'] = isset( $result['platform_profile'] ) ? sanitize_textarea_field( (string) $result['platform_profile'] ) : '';
        foreach ( array( 'answer_style_rules', 'evidence_preferences', 'question_patterns', 'content_asset_tweaks', 'entity_expression_rules', 'competitor_handling', 'risk_flags', 'retest_prompts', 'execution_steps' ) as $key ) {
            $form[ $key ] = self::lines_from_array( isset( $result[ $key ] ) ? $result[ $key ] : array() );
        }

        return $form;
    }

    private static function get_request_form_data() {
        return array(
            'record_id' => isset( $_POST['record_id'] ) ? absint( wp_unslash( (string) $_POST['record_id'] ) ) : 0,
            'model_id' => isset( $_POST['model_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['model_id'] ) ) : '',
            'target_platform' => self::limit_text( isset( $_POST['target_platform'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_platform'] ) ) : '', 120 ),
            'target_keyword' => self::limit_text( isset( $_POST['target_keyword'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_keyword'] ) ) : '', 255 ),
            'brand_name' => self::limit_text( isset( $_POST['brand_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['brand_name'] ) ) : '', 190 ),
            'audience_persona' => self::limit_text( isset( $_POST['audience_persona'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['audience_persona'] ) ) : '', 190 ),
            'brand_entity_summary' => isset( $_POST['brand_entity_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['brand_entity_summary'] ) ) : '',
            'evidence_summary' => isset( $_POST['evidence_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['evidence_summary'] ) ) : '',
            'backtest_summary' => isset( $_POST['backtest_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['backtest_summary'] ) ) : '',
            'content_asset_summary' => isset( $_POST['content_asset_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['content_asset_summary'] ) ) : '',
            'competitor_context' => isset( $_POST['competitor_context'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['competitor_context'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        );
    }

    private static function get_result_data_from_request() {
        return array(
            'summary' => self::limit_text( isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '', 800 ),
            'platform_profile' => self::limit_text( isset( $_POST['platform_profile'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['platform_profile'] ) ) : '', 800 ),
            'answer_style_rules' => self::parse_plain_lines( isset( $_POST['answer_style_rules'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['answer_style_rules'] ) ) : '', 20 ),
            'evidence_preferences' => self::parse_plain_lines( isset( $_POST['evidence_preferences'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['evidence_preferences'] ) ) : '', 20 ),
            'question_patterns' => self::parse_plain_lines( isset( $_POST['question_patterns'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['question_patterns'] ) ) : '', 20 ),
            'content_asset_tweaks' => self::parse_plain_lines( isset( $_POST['content_asset_tweaks'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['content_asset_tweaks'] ) ) : '', 20 ),
            'entity_expression_rules' => self::parse_plain_lines( isset( $_POST['entity_expression_rules'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['entity_expression_rules'] ) ) : '', 20 ),
            'competitor_handling' => self::parse_plain_lines( isset( $_POST['competitor_handling'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['competitor_handling'] ) ) : '', 20 ),
            'risk_flags' => self::parse_plain_lines( isset( $_POST['risk_flags'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['risk_flags'] ) ) : '', 20 ),
            'retest_prompts' => self::parse_plain_lines( isset( $_POST['retest_prompts'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['retest_prompts'] ) ) : '', 15 ),
            'execution_steps' => self::parse_plain_lines( isset( $_POST['execution_steps'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['execution_steps'] ) ) : '', 15 ),
        );
    }

    private static function validate_base_form_data( $form ) {
        if ( empty( $form['target_platform'] ) ) {
            return new WP_Error( 'qigeo_platform_target_platform_required', '请先填写目标 AI 平台' );
        }
        if ( empty( $form['target_keyword'] ) ) {
            return new WP_Error( 'qigeo_platform_target_keyword_required', '请先填写目标推荐词' );
        }
        if ( empty( $form['brand_name'] ) ) {
            return new WP_Error( 'qigeo_platform_brand_name_required', '请先填写品牌 / 产品名' );
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
            $where .= ' AND (target_platform LIKE %s OR target_keyword LIKE %s OR brand_name LIKE %s OR audience_persona LIKE %s OR result_summary LIKE %s)';
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
        return $wpdb->prefix . 'qigeo_platform_adaptation';
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

    private static function resolve_platform_model( $preferred_model_id, $target_platform ) {
        $packets = self::get_all_usable_model_packets();
        if ( empty( $packets ) ) {
            return new WP_Error( 'qigeo_platform_model_missing', '请先在启灵 GEO 配置并启用至少一个可用模型' );
        }

        $platform_key = self::normalize_platform_key( $target_platform );
        $preferred_packet = null;
        foreach ( $packets as $packet ) {
            if ( $preferred_model_id !== '' && sanitize_key( (string) $packet['model_id'] ) === sanitize_key( (string) $preferred_model_id ) ) {
                $preferred_packet = $packet;
                break;
            }
        }

        if ( $preferred_packet && $platform_key !== '' && self::provider_matches_platform( $preferred_packet, $platform_key ) ) {
            return $preferred_packet;
        }

        $platform_packet = self::find_matching_platform_packet( $packets, $platform_key );
        if ( $platform_packet ) {
            return $platform_packet;
        }

        if ( $preferred_packet ) {
            return $preferred_packet;
        }

        return $packets[0];
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

    private static function find_matching_platform_packet( $packets, $platform_key ) {
        if ( $platform_key === '' ) {
            return null;
        }

        foreach ( (array) $packets as $packet ) {
            if ( self::provider_matches_platform( $packet, $platform_key ) ) {
                return $packet;
            }
        }

        return null;
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
            'platform_profile' => self::limit_text( isset( $parsed['platform_profile'] ) ? sanitize_textarea_field( (string) $parsed['platform_profile'] ) : ( isset( $parsed['platform_analysis'] ) ? sanitize_textarea_field( (string) $parsed['platform_analysis'] ) : '' ), 800 ),
            'answer_style_rules' => self::normalize_string_list( isset( $parsed['answer_style_rules'] ) ? $parsed['answer_style_rules'] : ( isset( $parsed['answer_style'] ) ? $parsed['answer_style'] : array() ), 20 ),
            'evidence_preferences' => self::normalize_string_list( isset( $parsed['evidence_preferences'] ) ? $parsed['evidence_preferences'] : ( isset( $parsed['evidence_rules'] ) ? $parsed['evidence_rules'] : array() ), 20 ),
            'question_patterns' => self::normalize_string_list( isset( $parsed['question_patterns'] ) ? $parsed['question_patterns'] : ( isset( $parsed['question_types'] ) ? $parsed['question_types'] : array() ), 20 ),
            'content_asset_tweaks' => self::normalize_string_list( isset( $parsed['content_asset_tweaks'] ) ? $parsed['content_asset_tweaks'] : ( isset( $parsed['content_asset_adjustments'] ) ? $parsed['content_asset_adjustments'] : array() ), 20 ),
            'entity_expression_rules' => self::normalize_string_list( isset( $parsed['entity_expression_rules'] ) ? $parsed['entity_expression_rules'] : ( isset( $parsed['entity_rules'] ) ? $parsed['entity_rules'] : array() ), 20 ),
            'competitor_handling' => self::normalize_string_list( isset( $parsed['competitor_handling'] ) ? $parsed['competitor_handling'] : ( isset( $parsed['competitor_rules'] ) ? $parsed['competitor_rules'] : array() ), 20 ),
            'risk_flags' => self::normalize_string_list( isset( $parsed['risk_flags'] ) ? $parsed['risk_flags'] : ( isset( $parsed['risks'] ) ? $parsed['risks'] : array() ), 20 ),
            'retest_prompts' => self::normalize_string_list( isset( $parsed['retest_prompts'] ) ? $parsed['retest_prompts'] : ( isset( $parsed['test_prompts'] ) ? $parsed['test_prompts'] : array() ), 15 ),
            'execution_steps' => self::normalize_string_list( isset( $parsed['execution_steps'] ) ? $parsed['execution_steps'] : ( isset( $parsed['next_steps'] ) ? $parsed['next_steps'] : array() ), 15 ),
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

    private static function count_adaptation_items( $result ) {
        $count = 0;
        foreach ( array( 'answer_style_rules', 'evidence_preferences', 'question_patterns', 'content_asset_tweaks', 'entity_expression_rules', 'competitor_handling', 'risk_flags', 'retest_prompts' ) as $key ) {
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
        return "你是 GEO 平台适配分析助手。现在要围绕一个目标 AI 平台，拆解这个平台更容易采纳的回答风格、证据形态、问法类型、实体表达、竞品处理和回测提示词。请严格输出 JSON，不要 markdown，不要解释。\n"
            . "{\n"
            . "\"summary\":\"80-220字，总结当前这组资料在目标平台上的适配方向\",\n"
            . "\"platform_profile\":\"80-220字，说明该平台当前更偏好的回复结构、证据风格或推荐语境\",\n"
            . "\"answer_style_rules\":[\"规则 || 为什么适合该平台 || 示例表达方向\"],\n"
            . "\"evidence_preferences\":[\"证据类型 || 适配原因 || 应补页面/资产\"],\n"
            . "\"question_patterns\":[\"高适配问法类型 || 示例问法 || 目标回复倾向\"],\n"
            . "\"content_asset_tweaks\":[\"已有/计划资产 || 针对该平台的改法 || 目的\"],\n"
            . "\"entity_expression_rules\":[\"品牌实体/目标词表达规则 || 注意点\"],\n"
            . "\"competitor_handling\":[\"竞品语境 || 推荐处理方式 || 风险\"],\n"
            . "\"risk_flags\":[\"不适合该平台的表达或动作 || 原因\"],\n"
            . "\"retest_prompts\":[\"发布后用于该平台回测的提示词\"],\n"
            . "\"execution_steps\":[\"下一步动作1\", \"动作2\"]\n"
            . "}\n"
            . "要求：1) 必须围绕 target_platform、target_keyword、brand_name、brand_entity_summary、evidence_summary、backtest_summary、content_asset_summary、competitor_context 输出。2) 如果 payload 包含 global_brand_profile，平台适配规则必须优先遵守全局品牌定位、品牌别名、适合/不适合推荐场景、核心页面和权威证明。3) 只能基于可公开验证、可长期维护的表达给建议，不要虚构销量、媒体背书、官方合作、用户评价、测试数据或排名。4) 不要承诺‘平台一定会推荐’，而是给出更容易被采纳的表达与素材方向。5) 如果证据不足，要明确指出缺口和该补的内容资产。6) answer_style_rules、question_patterns、entity_expression_rules 必须具体，不要空泛。7) retest_prompts 要能直接拿去回测。8) 输出必须是合法 JSON。";
    }
}

