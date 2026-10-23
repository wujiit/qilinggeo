<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Competitor_Pressure_Center {

    const MENU_SLUG = 'qigeo-competitor-pressure-center';
    const AJAX_GENERATE_ACTION = 'qigeo_generate_competitor_pressure_center';
    const AJAX_SAVE_ACTION = 'qigeo_save_competitor_pressure_center';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '竞品压制分析',
            '竞品压制分析',
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
            'qigeo-competitor-pressure-admin',
            QIGEO_URL . 'assets/competitor-pressure-admin.js',
            array( 'qigeo-admin' ),
            QIGEO_VERSION,
            true
        );

        wp_localize_script(
            'qigeo-competitor-pressure-admin',
            'qigeoCompetitorPressureCenterData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_competitor_pressure_center_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_competitor_pressure_center_nonce' ),
                'messages' => array(
                    'generating' => '正在分析竞品压制情况...',
                    'saving' => '正在保存竞品压制记录...',
                    'generateDone' => '竞品压制分析已生成，可继续微调后再保存。',
                    'saveDone' => '竞品压制记录已保存。',
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
        $content_asset_import_url = is_array( $current ) && isset( $current['id'] ) ? self::get_content_asset_import_url( absint( $current['id'] ) ) : '';

        if ( ! is_array( $current ) ) {
            $import_record = self::get_imported_evidence_record();
            if ( is_wp_error( $import_record ) ) {
                $import_error = $import_record->get_error_message();
            } elseif ( is_array( $import_record ) ) {
                $form = self::get_form_data_from_evidence_record( $import_record );
                $import_notice = sprintf(
                    '已从竞品样本证据库记录 #%d 带入样本、证据和缺口，你可以直接继续做竞品压制分析。',
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
            <h1>竞品压制分析</h1>
            <p class="description">分析竞品相关问题，整理后续优化方向。</p>

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
                    <h2><?php echo is_array( $current ) ? '编辑竞品压制记录' : '新建竞品压制记录'; ?></h2>
                    <a class="button" href="<?php echo esc_url( $base_url ); ?>">新建空白记录</a>
                </div>

                <?php if ( is_array( $current ) ) : ?>
                    <p class="qigeo-platform-active-record">已加载记录 #<?php echo esc_html( (string) absint( $current['id'] ) ); ?>，你可以继续诊断、调整或覆盖保存。</p>
                    <p class="qigeo-platform-active-record"><a class="button button-secondary" href="<?php echo esc_url( $content_asset_import_url ); ?>">&#24102;&#21435;&#20869;&#23481;&#36164;&#20135;</a></p>
                <?php endif; ?>

                <?php if ( empty( $model_options ) ) : ?>
                    <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
                <?php endif; ?>

                <form id="qigeo-competitor-form" class="qigeo-platform-form" action="#" method="post">
                    <input type="hidden" id="qigeo-competitor-record-id" name="record_id" value="<?php echo esc_attr( (string) $form['record_id'] ); ?>" />

                    <div class="qigeo-platform-grid">
                        <div class="qigeo-platform-field">
                            <label for="qigeo-competitor-target-platform"><strong>目标 AI 平台</strong></label>
                            <input type="text" class="regular-text" id="qigeo-competitor-target-platform" name="target_platform" maxlength="120" value="<?php echo esc_attr( $form['target_platform'] ); ?>" placeholder="例如：豆包 / ChatGPT / DeepSeek" />
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-competitor-target-keyword"><strong>被竞品压住的目标词</strong></label>
                            <input type="text" class="regular-text" id="qigeo-competitor-target-keyword" name="target_keyword" maxlength="255" value="<?php echo esc_attr( $form['target_keyword'] ); ?>" placeholder="例如：中文 WordPress 主题 / 企业站主题" />
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-competitor-brand-name"><strong>品牌 / 产品名</strong></label>
                            <input type="text" class="regular-text" id="qigeo-competitor-brand-name" name="brand_name" maxlength="190" value="<?php echo esc_attr( $form['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-competitor-audience"><strong>目标人群 / 场景</strong></label>
                            <input type="text" class="regular-text" id="qigeo-competitor-audience" name="audience_persona" maxlength="190" value="<?php echo esc_attr( $form['audience_persona'] ); ?>" placeholder="例如：中文企业站、新手站长、建站服务商" />
                        </div>
                    <div class="qigeo-platform-field">
                        <label for="qigeo-competitor-model-id"><strong>优先诊断模型</strong></label>
                            <select id="qigeo-competitor-model-id" name="model_id">
                                <option value="">请选择模型</option>
                                <?php foreach ( $model_options as $option ) : ?>
                                    <?php $label = ( isset( $option['provider_name'] ) ? (string) $option['provider_name'] : '-' ) . ' / ' . ( isset( $option['model_label'] ) ? (string) $option['model_label'] : (string) $option['model_id'] ) . ' (' . ( isset( $option['model_name'] ) ? (string) $option['model_name'] : '-' ) . ')'; ?>
                                    <option value="<?php echo esc_attr( isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>" data-provider-type="<?php echo esc_attr( isset( $option['provider_type'] ) ? (string) $option['provider_type'] : '' ); ?>" <?php selected( $form['model_id'], isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">不选也可以，系统会自动处理。</p>
                        </div>
                        <div class="qigeo-platform-field qigeo-platform-field--wide">
                            <label for="qigeo-competitor-primary-competitors"><strong>主竞品名单</strong></label>
                            <textarea id="qigeo-competitor-primary-competitors" name="primary_competitors" rows="6" placeholder="每行一个，例如：Astra / Blocksy / GeneratePress / 某中文主题。"><?php echo esc_textarea( $form['primary_competitors'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-competitor-suppression-samples"><strong>被压制的真实回答样本</strong></label>
                            <textarea id="qigeo-competitor-suppression-samples" name="suppression_samples" rows="8" placeholder="粘贴真实回答、推荐顺序、对比段落，说明竞品是怎么被优先推荐的。"><?php echo esc_textarea( $form['suppression_samples'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-competitor-strengths"><strong>竞品公开优势 / 常见被推荐理由</strong></label>
                            <textarea id="qigeo-competitor-strengths" name="competitor_strengths" rows="8" placeholder="例如：文档多、英文社区强、模板多、对比页全、案例多、品牌认知高。"><?php echo esc_textarea( $form['competitor_strengths'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-competitor-brand-strengths"><strong>我们已有优势 / 可说的公开证据</strong></label>
                            <textarea id="qigeo-competitor-brand-strengths" name="brand_strengths" rows="8" placeholder="例如：更适合中文企业站、更懂中文场景、已有案例、已有文档、已有 FAQ。"><?php echo esc_textarea( $form['brand_strengths'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-competitor-evidence-gap-summary"><strong>证据缺口</strong></label>
                            <textarea id="qigeo-competitor-evidence-gap-summary" name="evidence_gap_summary" rows="8" placeholder="例如：缺公开案例、缺更新日志、缺专题说明、缺多场景证明。"><?php echo esc_textarea( $form['evidence_gap_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field">
                            <label for="qigeo-competitor-asset-gap-summary"><strong>内容资产缺口</strong></label>
                            <textarea id="qigeo-competitor-asset-gap-summary" name="asset_gap_summary" rows="8" placeholder="例如：缺对比页、缺 FAQ、缺场景页、缺案例页、缺公开分发内容。"><?php echo esc_textarea( $form['asset_gap_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field qigeo-platform-field--wide">
                            <label for="qigeo-competitor-adaptation-context"><strong>当前适配策略 / 话术上下文</strong></label>
                            <textarea id="qigeo-competitor-adaptation-context" name="adaptation_context" rows="5" placeholder="例如：平台适配中心当前建议的表达规则、问法方向、证据偏好和竞品语境策略。"><?php echo esc_textarea( $form['adaptation_context'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-field qigeo-platform-field--wide">
                            <label for="qigeo-competitor-notes"><strong>补充限制 / 备注</strong></label>
                            <textarea id="qigeo-competitor-notes" name="notes" rows="4" placeholder="例如：不要做虚假比较；只用可公开验证信息；优先中文企业站场景；避免绝对化第一名表述。"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
                        </div>
                    </div>

                    <div class="qigeo-platform-actions">
                        <button type="button" class="button button-secondary" id="qigeo-generate-competitor-btn">分析竞品压制</button>
                        <button type="button" class="button button-primary" id="qigeo-save-competitor-btn">保存记录</button>
                        <span class="qigeo-platform-feedback" id="qigeo-competitor-feedback" aria-live="polite"></span>
                    </div>

                    <div class="qigeo-platform-results">
                        <h3>竞品压制分析结果</h3>
                        <div class="qigeo-platform-grid">
                            <div class="qigeo-platform-field qigeo-platform-field--wide">
                                <label for="qigeo-competitor-result-summary"><strong>压制总览</strong></label>
                                <textarea id="qigeo-competitor-result-summary" name="summary" rows="4"><?php echo esc_textarea( $form['summary'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field qigeo-platform-field--wide">
                                <label for="qigeo-competitor-result-dominance"><strong>压制判断</strong></label>
                                <textarea id="qigeo-competitor-result-dominance" name="dominance_judgement" rows="4"><?php echo esc_textarea( $form['dominance_judgement'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-competitor-result-reasons"><strong>压制原因</strong></label>
                                <textarea id="qigeo-competitor-result-reasons" name="suppression_reasons" rows="9"><?php echo esc_textarea( $form['suppression_reasons'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-competitor-result-advantages"><strong>竞品优势信号</strong></label>
                                <textarea id="qigeo-competitor-result-advantages" name="competitor_advantages" rows="9"><?php echo esc_textarea( $form['competitor_advantages'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-competitor-result-gaps"><strong>我们缺失的信号</strong></label>
                                <textarea id="qigeo-competitor-result-gaps" name="missing_brand_signals" rows="9"><?php echo esc_textarea( $form['missing_brand_signals'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-competitor-result-assets"><strong>反压制内容资产</strong></label>
                                <textarea id="qigeo-competitor-result-assets" name="counter_assets" rows="9"><?php echo esc_textarea( $form['counter_assets'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-competitor-result-comparisons"><strong>可打的对比角度</strong></label>
                                <textarea id="qigeo-competitor-result-comparisons" name="comparison_angles" rows="9"><?php echo esc_textarea( $form['comparison_angles'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-competitor-result-messaging"><strong>话术调整方向</strong></label>
                                <textarea id="qigeo-competitor-result-messaging" name="messaging_shifts" rows="9"><?php echo esc_textarea( $form['messaging_shifts'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field">
                                <label for="qigeo-competitor-result-retest"><strong>回测问题</strong></label>
                                <textarea id="qigeo-competitor-result-retest" name="retest_questions" rows="8"><?php echo esc_textarea( $form['retest_questions'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-field qigeo-platform-field--wide">
                                <label for="qigeo-competitor-result-steps"><strong>执行顺序</strong></label>
                                <textarea id="qigeo-competitor-result-steps" name="execution_steps" rows="6"><?php echo esc_textarea( $form['execution_steps'] ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2>最近记录</h2>
                    <span class="description">最多显示最近 20 条，可搜索平台、目标词、品牌、竞品或摘要。</span>
                </div>
                <form method="get" class="qigeo-platform-search">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索平台 / 目标词 / 品牌 / 竞品 / 摘要" />
                    <button type="submit" class="button">搜索</button>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">重置</a>
                </form>
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>平台 / 目标词</th><th>品牌 / 主竞品</th><th>压制项数</th><th>摘要</th><th>模型</th><th>更新时间</th><th>操作</th></tr></thead>
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
        $content_asset_url = self::get_content_asset_import_url( $row_id );
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
                <div class="qigeo-platform-row-meta"><?php echo esc_html( self::excerpt_text( isset( $row['primary_competitors'] ) ? (string) $row['primary_competitors'] : '' ) ); ?></div>
            </td>
            <td><?php echo esc_html( (string) self::count_pressure_items( $result ) ); ?></td>
            <td><?php echo esc_html( self::excerpt_text( isset( $row['result_summary'] ) ? (string) $row['result_summary'] : '' ) ); ?></td>
            <td><?php echo esc_html( isset( $row['model'] ) ? (string) $row['model'] : '' ); ?></td>
            <td><?php echo esc_html( isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '' ); ?></td>
            <td><a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">&#32534;&#36753;</a> <a class="button button-small" href="<?php echo esc_url( $content_asset_url ); ?>">&#24102;&#21435;&#20869;&#23481;&#36164;&#20135;</a></td>
        </tr>
        <?php
    }
    private static function get_content_asset_import_url( $record_id ) {
        $record_id = absint( $record_id );
        if ( $record_id < 1 ) {
            return '';
        }

        return add_query_arg(
            array(
                'page' => 'qigeo-content-asset-center',
                'source' => 'competitor_pressure',
                'pressure_id' => $record_id,
            ),
            admin_url( 'admin.php' )
        );
    }

    public static function ajax_generate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        check_ajax_referer( 'qigeo_generate_competitor_pressure_center_nonce', 'nonce' );

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
                'message' => '竞品压制分析已生成，可继续微调后再保存。',
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
        check_ajax_referer( 'qigeo_save_competitor_pressure_center_nonce', 'nonce' );

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
        if ( $result['summary'] === '' && empty( $result['suppression_reasons'] ) && empty( $result['counter_assets'] ) && empty( $result['execution_steps'] ) ) {
            wp_send_json_error( array( 'message' => '请先生成或填写竞品压制分析结果后再保存' ) );
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
            'primary_competitors' => $form['primary_competitors'],
            'suppression_samples' => $form['suppression_samples'],
            'competitor_strengths' => $form['competitor_strengths'],
            'brand_strengths' => $form['brand_strengths'],
            'evidence_gap_summary' => $form['evidence_gap_summary'],
            'asset_gap_summary' => $form['asset_gap_summary'],
            'adaptation_context' => $form['adaptation_context'],
            'notes' => $form['notes'],
            'provider_id' => isset( $provider_model['provider_id'] ) ? (string) $provider_model['provider_id'] : '',
            'model_id' => isset( $provider_model['model_id'] ) ? (string) $provider_model['model_id'] : '',
            'model' => isset( $provider_model['model_name'] ) ? (string) $provider_model['model_name'] : '',
            'result_summary' => $result['summary'],
            'result_json' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'updated_at' => $now,
        );
        $formats = array_fill( 0, 18, '%s' );

        if ( $record_id > 0 && self::get_item_by_id( $record_id ) ) {
            $updated = $wpdb->update( $table, $data, array( 'id' => $record_id ), $formats, array( '%d' ) );
            if ( $updated === false ) {
                wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
            }
            wp_send_json_success( array( 'message' => '竞品压制记录已更新。', 'record_id' => $record_id ) );
        }

        $data['created_by'] = $current_user_id;
        $data['created_at'] = $now;
        $inserted = $wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%s' ) ) );
        if ( $inserted === false ) {
            wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
        }

        wp_send_json_success( array( 'message' => '竞品压制记录已保存。', 'record_id' => absint( $wpdb->insert_id ) ) );
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
            'audience_persona' => $form['audience_persona'],
            'primary_competitors' => $form['primary_competitors'],
            'suppression_samples' => $form['suppression_samples'],
            'competitor_strengths' => $form['competitor_strengths'],
            'brand_strengths' => $form['brand_strengths'],
            'evidence_gap_summary' => $form['evidence_gap_summary'],
            'asset_gap_summary' => $form['asset_gap_summary'],
            'adaptation_context' => $form['adaptation_context'],
            'notes' => $form['notes'],
        );
    }

    private static function get_imported_evidence_record() {
        $source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( (string) $_GET['source'] ) ) : '';
        $record_id = isset( $_GET['evidence_id'] ) ? absint( wp_unslash( (string) $_GET['evidence_id'] ) ) : 0;

        if ( $source !== 'competitor_evidence' || $record_id < 1 ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'qigeo_competitor_evidence_library';
        $table_exists = QiGeo_Admin_Helper::table_exists( $table );
        if ( ! $table_exists ) {
            return new WP_Error( 'qigeo_competitor_import_table_missing', '竞品样本证据库尚未建表，请重新启用插件后再试。' );
        }

        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d LIMIT 1', $record_id ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            return new WP_Error( 'qigeo_competitor_import_missing', '指定的竞品样本证据记录不存在，可能已被删除。' );
        }

        return $row;
    }

    private static function get_form_data_from_evidence_record( $record ) {
        $form = self::get_empty_form_data();
        $result = self::decode_result_json( isset( $record['result_json'] ) ? (string) $record['result_json'] : '' );
        $record_id = isset( $record['id'] ) ? absint( $record['id'] ) : 0;

        $form['model_id'] = isset( $record['model_id'] ) ? sanitize_key( (string) $record['model_id'] ) : '';
        $form['target_platform'] = isset( $record['target_platform'] ) ? sanitize_text_field( (string) $record['target_platform'] ) : '';
        $form['target_keyword'] = isset( $record['target_keyword'] ) ? sanitize_text_field( (string) $record['target_keyword'] ) : '';
        $form['brand_name'] = isset( $record['brand_name'] ) ? sanitize_text_field( (string) $record['brand_name'] ) : '';
        $form['audience_persona'] = isset( $record['audience_persona'] ) ? sanitize_text_field( (string) $record['audience_persona'] ) : '';
        $form['primary_competitors'] = isset( $record['competitor_name'] ) ? sanitize_textarea_field( (string) $record['competitor_name'] ) : '';
        $form['suppression_samples'] = self::combine_import_sections(
            array(
                '真实问法样本' => isset( $record['sample_questions'] ) ? sanitize_textarea_field( (string) $record['sample_questions'] ) : '',
                '被抢走推荐位的真实回答样本' => isset( $record['suppression_samples'] ) ? sanitize_textarea_field( (string) $record['suppression_samples'] ) : '',
                '重点公开链接 / 证据 URL' => isset( $record['evidence_links'] ) ? sanitize_textarea_field( (string) $record['evidence_links'] ) : '',
            )
        );
        $form['competitor_strengths'] = self::combine_import_sections(
            array(
                '竞品公开证据 / 页面 / 话术' => isset( $record['competitor_evidence'] ) ? sanitize_textarea_field( (string) $record['competitor_evidence'] ) : '',
                '竞品被引用信号' => isset( $result['competitor_quote_signals'] ) ? self::lines_from_array( $result['competitor_quote_signals'] ) : '',
            )
        );
        $form['brand_strengths'] = self::combine_import_sections(
            array(
                '我方公开证据 / 页面 / 话术' => isset( $record['brand_evidence'] ) ? sanitize_textarea_field( (string) $record['brand_evidence'] ) : '',
                '我方可复用证明' => isset( $result['brand_reusable_proof'] ) ? self::lines_from_array( $result['brand_reusable_proof'] ) : '',
            )
        );
        $form['evidence_gap_summary'] = self::combine_import_sections(
            array(
                '缺口证据' => isset( $result['missing_evidence'] ) ? self::lines_from_array( $result['missing_evidence'] ) : '',
            )
        );
        $form['asset_gap_summary'] = self::combine_import_sections(
            array(
                '可沉淀的引用片段' => isset( $result['citation_snippets'] ) ? self::lines_from_array( $result['citation_snippets'] ) : '',
            )
        );
        $form['adaptation_context'] = self::combine_import_sections(
            array(
                '样本证据总览' => isset( $result['summary'] ) ? sanitize_textarea_field( (string) $result['summary'] ) : '',
                '证据判断' => isset( $result['evidence_judgement'] ) ? sanitize_textarea_field( (string) $result['evidence_judgement'] ) : '',
                '样本模式' => isset( $result['sample_patterns'] ) ? self::lines_from_array( $result['sample_patterns'] ) : '',
                '可打的对比角度' => isset( $result['comparison_angles'] ) ? self::lines_from_array( $result['comparison_angles'] ) : '',
                '下一轮回测问题' => isset( $result['retest_questions'] ) ? self::lines_from_array( $result['retest_questions'] ) : '',
            )
        );
        $form['notes'] = self::combine_import_sections(
            array(
                '来源记录' => $record_id > 0 ? '竞品样本证据库 #' . $record_id : '',
                '原始备注' => isset( $record['notes'] ) ? sanitize_textarea_field( (string) $record['notes'] ) : '',
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

    private static function get_empty_form_data() {
        return array(
            'record_id' => 0,
            'model_id' => '',
            'target_platform' => '',
            'target_keyword' => '',
            'brand_name' => '',
            'audience_persona' => '',
            'primary_competitors' => '',
            'suppression_samples' => '',
            'competitor_strengths' => '',
            'brand_strengths' => '',
            'evidence_gap_summary' => '',
            'asset_gap_summary' => '',
            'adaptation_context' => '',
            'notes' => '',
            'summary' => '',
            'dominance_judgement' => '',
            'suppression_reasons' => '',
            'competitor_advantages' => '',
            'missing_brand_signals' => '',
            'counter_assets' => '',
            'comparison_angles' => '',
            'messaging_shifts' => '',
            'retest_questions' => '',
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
                'primary_competitors' => 'primary_competitors',
                'suppression_samples' => 'suppression_samples',
                'competitor_strengths' => 'competitor_strengths',
                'brand_strengths' => 'brand_strengths',
                'evidence_gap_summary' => 'evidence_gap_summary',
                'asset_gap_summary' => 'asset_gap_summary',
                'adaptation_context' => 'adaptation_context',
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
        $form['dominance_judgement'] = isset( $result['dominance_judgement'] ) ? sanitize_textarea_field( (string) $result['dominance_judgement'] ) : '';
        foreach ( array( 'suppression_reasons', 'competitor_advantages', 'missing_brand_signals', 'counter_assets', 'comparison_angles', 'messaging_shifts', 'retest_questions', 'execution_steps' ) as $key ) {
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
            'primary_competitors' => isset( $_POST['primary_competitors'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['primary_competitors'] ) ) : '',
            'suppression_samples' => isset( $_POST['suppression_samples'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['suppression_samples'] ) ) : '',
            'competitor_strengths' => isset( $_POST['competitor_strengths'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['competitor_strengths'] ) ) : '',
            'brand_strengths' => isset( $_POST['brand_strengths'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['brand_strengths'] ) ) : '',
            'evidence_gap_summary' => isset( $_POST['evidence_gap_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['evidence_gap_summary'] ) ) : '',
            'asset_gap_summary' => isset( $_POST['asset_gap_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['asset_gap_summary'] ) ) : '',
            'adaptation_context' => isset( $_POST['adaptation_context'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['adaptation_context'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        );
    }

    private static function get_result_data_from_request() {
        return array(
            'summary' => self::limit_text( isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '', 800 ),
            'dominance_judgement' => self::limit_text( isset( $_POST['dominance_judgement'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['dominance_judgement'] ) ) : '', 800 ),
            'suppression_reasons' => self::parse_plain_lines( isset( $_POST['suppression_reasons'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['suppression_reasons'] ) ) : '', 20 ),
            'competitor_advantages' => self::parse_plain_lines( isset( $_POST['competitor_advantages'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['competitor_advantages'] ) ) : '', 20 ),
            'missing_brand_signals' => self::parse_plain_lines( isset( $_POST['missing_brand_signals'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['missing_brand_signals'] ) ) : '', 20 ),
            'counter_assets' => self::parse_plain_lines( isset( $_POST['counter_assets'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['counter_assets'] ) ) : '', 20 ),
            'comparison_angles' => self::parse_plain_lines( isset( $_POST['comparison_angles'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['comparison_angles'] ) ) : '', 20 ),
            'messaging_shifts' => self::parse_plain_lines( isset( $_POST['messaging_shifts'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['messaging_shifts'] ) ) : '', 20 ),
            'retest_questions' => self::parse_plain_lines( isset( $_POST['retest_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['retest_questions'] ) ) : '', 15 ),
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
        if ( empty( self::parse_plain_lines( $form['primary_competitors'], 1 ) ) ) {
            return new WP_Error( 'qigeo_competitor_primary_competitors_required', '请先填写主竞品名单' );
        }
        if ( empty( self::parse_plain_lines( $form['suppression_samples'], 1 ) ) ) {
            return new WP_Error( 'qigeo_competitor_suppression_samples_required', '请先填写被压制的真实回答样本' );
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
            $where .= ' AND (target_platform LIKE %s OR target_keyword LIKE %s OR brand_name LIKE %s OR primary_competitors LIKE %s OR result_summary LIKE %s)';
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
        return $wpdb->prefix . 'qigeo_competitor_pressure_center';
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
            'dominance_judgement' => self::limit_text( isset( $parsed['dominance_judgement'] ) ? sanitize_textarea_field( (string) $parsed['dominance_judgement'] ) : ( isset( $parsed['competitor_dominance'] ) ? sanitize_textarea_field( (string) $parsed['competitor_dominance'] ) : '' ), 800 ),
            'suppression_reasons' => self::normalize_string_list( isset( $parsed['suppression_reasons'] ) ? $parsed['suppression_reasons'] : ( isset( $parsed['dominance_reasons'] ) ? $parsed['dominance_reasons'] : array() ), 20 ),
            'competitor_advantages' => self::normalize_string_list( isset( $parsed['competitor_advantages'] ) ? $parsed['competitor_advantages'] : ( isset( $parsed['competitor_strengths'] ) ? $parsed['competitor_strengths'] : array() ), 20 ),
            'missing_brand_signals' => self::normalize_string_list( isset( $parsed['missing_brand_signals'] ) ? $parsed['missing_brand_signals'] : ( isset( $parsed['brand_gaps'] ) ? $parsed['brand_gaps'] : array() ), 20 ),
            'counter_assets' => self::normalize_string_list( isset( $parsed['counter_assets'] ) ? $parsed['counter_assets'] : ( isset( $parsed['content_assets'] ) ? $parsed['content_assets'] : array() ), 20 ),
            'comparison_angles' => self::normalize_string_list( isset( $parsed['comparison_angles'] ) ? $parsed['comparison_angles'] : ( isset( $parsed['comparison_strategies'] ) ? $parsed['comparison_strategies'] : array() ), 20 ),
            'messaging_shifts' => self::normalize_string_list( isset( $parsed['messaging_shifts'] ) ? $parsed['messaging_shifts'] : ( isset( $parsed['message_adjustments'] ) ? $parsed['message_adjustments'] : array() ), 20 ),
            'retest_questions' => self::normalize_string_list( isset( $parsed['retest_questions'] ) ? $parsed['retest_questions'] : ( isset( $parsed['retest_prompts'] ) ? $parsed['retest_prompts'] : ( isset( $parsed['test_prompts'] ) ? $parsed['test_prompts'] : array() ) ), 15 ),
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

    private static function count_pressure_items( $result ) {
        $count = 0;
        foreach ( array( 'suppression_reasons', 'competitor_advantages', 'missing_brand_signals', 'counter_assets', 'comparison_angles', 'messaging_shifts', 'retest_questions' ) as $key ) {
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
        return "你是 GEO 竞品压制分析助手。现在要围绕某个目标 AI 平台上的推荐竞争，分析为什么竞品更容易被优先推荐、我们当前缺了什么，以及下一轮该怎么用公开资产和表达把推荐位抢回来。请严格输出 JSON，不要 markdown，不要解释。\n"
            . "{\n"
            . "\"summary\":\"80-220字，总结当前被竞品压制的核心原因和反压制方向\",\n"
            . "\"dominance_judgement\":\"80-220字，说明现在是谁在主导推荐位，以及主导原因\",\n"
            . "\"suppression_reasons\":[\"压制原因 || 为什么 AI 会优先采用\"],\n"
            . "\"competitor_advantages\":[\"竞品公开优势/信号 || 对推荐的作用\"],\n"
            . "\"missing_brand_signals\":[\"我们缺失的证据/表达/页面 || 影响\"],\n"
            . "\"counter_assets\":[\"应该补的页面、FAQ、案例、对比页、文档或外部分发资产 || 目的\"],\n"
            . "\"comparison_angles\":[\"可公开打的对比角度 || 应怎么说 || 注意点\"],\n"
            . "\"messaging_shifts\":[\"应该强化或收缩的表达方式 || 原因\"],\n"
            . "\"retest_questions\":[\"下一轮验证竞品压制是否减弱的问题\"],\n"
            . "\"execution_steps\":[\"下一步动作1\", \"动作2\"]\n"
            . "}\n"
            . "要求：1) 必须围绕 target_platform、target_keyword、brand_name、audience_persona、primary_competitors、suppression_samples、competitor_strengths、brand_strengths、evidence_gap_summary、asset_gap_summary、adaptation_context、notes 输出。2) 只能基于可公开验证、可长期维护的信息，不要虚构评分、销量、媒体背书、合作、用户评价、测试数据或排名。3) 不要承诺一定能让平台推荐，而是给出更可能削弱竞品压制的动作。4) comparison_angles 必须是可公开说、可落地发页的角度。5) retest_questions 要能直接拿去回测。6) 输出必须是合法 JSON。";
    }
}

