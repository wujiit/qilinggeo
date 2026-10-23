<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Evidence_Library {

    const MENU_SLUG = 'qigeo-evidence-library';
    const AJAX_GENERATE_ACTION = 'qigeo_generate_evidence_library';
    const AJAX_SAVE_ACTION = 'qigeo_save_evidence_library';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '证据库',
            '证据库',
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
            'qigeo-evidence-library-admin',
            QIGEO_URL . 'assets/evidence-library-admin.js',
            array( 'qigeo-admin' ),
            QIGEO_VERSION,
            true
        );

        wp_localize_script(
            'qigeo-evidence-library-admin',
            'qigeoEvidenceLibraryData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_evidence_library_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_evidence_library_nonce' ),
                'messages' => array(
                    'generating' => '正在整理证据库...',
                    'saving' => '正在保存证据库记录...',
                    'generateDone' => '证据库已生成，可继续微调后保存。',
                    'saveDone' => '证据库记录已保存。',
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

        $current = null;
        if ( $table_exists && $edit_id > 0 && $action === 'edit' ) {
            $current = self::get_item_by_id( $edit_id );
        }

        $settings = QiGeo_Settings::get_settings();
        $model_options = self::get_enabled_model_options();
        $form = self::get_empty_form_data();
        $import_notice = '';
        $import_error = '';
        if ( is_array( $current ) ) {
            $form = self::record_to_form_data( $current );
        } else {
            $import_record = self::get_imported_content_asset_record();
            if ( is_wp_error( $import_record ) ) {
                $import_error = $import_record->get_error_message();
            } elseif ( is_array( $import_record ) ) {
                $form = self::get_form_data_from_content_asset_record( $import_record );
                $import_notice = sprintf(
                    '已从内容资产记录 #%d 带入主张、证据摘要和回测问题，你可以直接整理证据库。',
                    isset( $import_record['id'] ) ? absint( $import_record['id'] ) : 0
                );
            }
            if ( $form['model_id'] === '' ) {
                $form['model_id'] = isset( $settings['default_model_id'] ) ? sanitize_key( (string) $settings['default_model_id'] ) : '';
            }
            if ( $form['model_id'] === '' ) {
                $form['model_id'] = self::get_first_usable_model_id();
            }
        }

        $rows = $table_exists ? self::get_records( $search, 20 ) : array();
        $base_url = add_query_arg(
            array(
                'page' => self::MENU_SLUG,
            ),
            admin_url( 'admin.php' )
        );
        ?>
        <div class="wrap qigeo-wrap">
            <h1>证据库</h1>
            <p class="description">整理卖点、证据和待补资料。</p>

            <?php if ( ! $table_exists ) : ?>
                <div class="notice notice-error"><p>当前页面数据暂未准备好，请刷新页面后重试。</p></div>
            <?php endif; ?>

            <?php if ( $import_notice !== '' ) : ?>
                <div class="notice notice-success inline"><p><?php echo esc_html( $import_notice ); ?></p></div>
            <?php elseif ( $import_error !== '' ) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html( $import_error ); ?></p></div>
            <?php endif; ?>
            <?php self::render_form_card( $current, $form, $model_options, $base_url ); ?>
            <?php self::render_records_card( $rows, $search, $base_url ); ?>
        </div>
        <?php
    }

    private static function render_intro_card() {}

    private static function render_form_card( $current, $form, $model_options, $base_url ) {
        ?>
        <div class="qigeo-card">
            <div class="qigeo-card-header">
                <h2><?php echo is_array( $current ) ? '编辑证据库记录' : '新建证据库记录'; ?></h2>
                <a class="button" href="<?php echo esc_url( $base_url ); ?>">新建空白记录</a>
            </div>

            <?php if ( is_array( $current ) ) : ?>
                <?php $current_record_id = absint( $current['id'] ); ?>
                <p class="qigeo-evidence-active-record">已加载记录 #<?php echo esc_html( (string) $current_record_id ); ?>，你可以继续生成、调整或覆盖保存。</p>
                <p class="qigeo-evidence-active-record"><a class="button button-secondary" href="<?php echo esc_url( self::get_faq_import_url( $current_record_id ) ); ?>">带去FAQ库</a></p>
            <?php endif; ?>

            <?php if ( empty( $model_options ) ) : ?>
                <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
            <?php endif; ?>

            <form id="qigeo-evidence-form" class="qigeo-evidence-form" action="#" method="post">
                <input type="hidden" id="qigeo-evidence-record-id" name="record_id" value="<?php echo esc_attr( (string) $form['record_id'] ); ?>" />

                <div class="qigeo-evidence-grid">
                    <div class="qigeo-evidence-field qigeo-evidence-field--wide">
                        <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '核心主题词 / 种子词', array( 'required', 'key' ), 'qigeo-evidence-seed-keyword' ) ); ?>
                        <input type="text" class="regular-text" id="qigeo-evidence-seed-keyword" name="seed_keyword" maxlength="255" value="<?php echo esc_attr( $form['seed_keyword'] ); ?>" placeholder="例如：wordpress 最好的中文主题" />
                        <p class="description">填写本次要整理的主题。</p>
                    </div>

                    <div class="qigeo-evidence-field">
                        <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '目标 AI 平台', array( 'required', 'key' ), 'qigeo-evidence-target-platform' ) ); ?>
                        <input type="text" class="regular-text" id="qigeo-evidence-target-platform" name="target_platform" maxlength="120" value="<?php echo esc_attr( $form['target_platform'] ); ?>" placeholder="例如：豆包 / ChatGPT / DeepSeek" />
                    </div>

                    <div class="qigeo-evidence-field">
                        <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '希望被推荐的词', array( 'required', 'key' ), 'qigeo-evidence-target-keyword' ) ); ?>
                        <input type="text" class="regular-text" id="qigeo-evidence-target-keyword" name="target_keyword" maxlength="255" value="<?php echo esc_attr( $form['target_keyword'] ); ?>" placeholder="例如：启灵主题" />
                    </div>

                    <div class="qigeo-evidence-field">
                        <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '品牌 / 产品名', array( 'recommended' ), 'qigeo-evidence-brand-name' ) ); ?>
                        <input type="text" class="regular-text" id="qigeo-evidence-brand-name" name="brand_name" maxlength="190" value="<?php echo esc_attr( $form['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                    </div>

                    <div class="qigeo-evidence-field">
                        <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '目标人群 / 使用场景', array( 'recommended' ), 'qigeo-evidence-audience' ) ); ?>
                        <input type="text" class="regular-text" id="qigeo-evidence-audience" name="audience_persona" maxlength="190" value="<?php echo esc_attr( $form['audience_persona'] ); ?>" placeholder="例如：中文企业站、新手站长、建站服务商" />
                    </div>

                    <div class="qigeo-evidence-field">
                        <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '优先起草模型', array( 'recommended' ), 'qigeo-evidence-model-id' ) ); ?>
                        <select id="qigeo-evidence-model-id" name="model_id">
                            <option value="">请选择模型</option>
                            <?php foreach ( $model_options as $option ) : ?>
                                <?php
                                $option_model_id = isset( $option['model_id'] ) ? (string) $option['model_id'] : '';
                                $option_provider_type = isset( $option['provider_type'] ) ? (string) $option['provider_type'] : '';
                                $option_label = ( isset( $option['provider_name'] ) ? (string) $option['provider_name'] : '-' )
                                    . ' / '
                                    . ( isset( $option['model_label'] ) ? (string) $option['model_label'] : $option_model_id )
                                    . ' ('
                                    . ( isset( $option['model_name'] ) ? (string) $option['model_name'] : '-' )
                                    . ')';
                                ?>
                                <option value="<?php echo esc_attr( $option_model_id ); ?>" data-provider-type="<?php echo esc_attr( $option_provider_type ); ?>" <?php selected( $form['model_id'], $option_model_id ); ?>>
                                    <?php echo esc_html( $option_label ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">不选也可以，系统会自动处理。</p>
                        </div>

                    <div class="qigeo-evidence-field qigeo-evidence-field--wide">
                        <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '重点问法 / 关键问题', array( 'key' ), 'qigeo-evidence-priority-questions' ) ); ?>
                        <textarea id="qigeo-evidence-priority-questions" name="priority_questions" rows="5" placeholder="每行一个关键问题，可直接从问法库里挑高价值问法贴进来。"><?php echo esc_textarea( $form['priority_questions'] ); ?></textarea>
                    </div>

                    <div class="qigeo-evidence-field">
                        <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '核心卖点 / 待证明主张', array( 'key' ), 'qigeo-evidence-value-claims' ) ); ?>
                        <textarea id="qigeo-evidence-value-claims" name="value_claims" rows="8" placeholder="每行一个主张，例如：&#10;更适合中文企业站&#10;上手更快&#10;更适合新手中文用户"><?php echo esc_textarea( $form['value_claims'] ); ?></textarea>
                    </div>

                    <div class="qigeo-evidence-field">
                        <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '已有公开证据 / 公开资产', array( 'key' ), 'qigeo-evidence-current-assets' ) ); ?>
                        <textarea id="qigeo-evidence-current-assets" name="existing_evidence" rows="8" placeholder="每行一个现有证据，例如：&#10;中文演示站&#10;安装文档页&#10;企业站案例页&#10;更新日志页"><?php echo esc_textarea( $form['existing_evidence'] ); ?></textarea>
                    </div>

                    <div class="qigeo-evidence-field">
                        <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '用户顾虑 / 竞品压力点', array( 'recommended' ), 'qigeo-evidence-concerns' ) ); ?>
                        <textarea id="qigeo-evidence-concerns" name="concern_points" rows="8" placeholder="每行一个顾虑，例如：&#10;会不会不如 Astra 轻量&#10;是否真的适合中文站&#10;有没有案例和文档"><?php echo esc_textarea( $form['concern_points'] ); ?></textarea>
                    </div>

                    <div class="qigeo-evidence-field qigeo-evidence-field--wide">
                        <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '补充限制 / 备注', array( 'recommended' ), 'qigeo-evidence-notes' ) ); ?>
                        <textarea id="qigeo-evidence-notes" name="notes" rows="4" placeholder="例如：不要写绝对化表述；优先补公开页面，不要只补私域材料。"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
                    </div>
                </div>

                <div class="qigeo-evidence-actions">
                    <button type="button" class="button button-secondary" id="qigeo-generate-evidence-btn">生成证据库</button>
                    <button type="button" class="button button-primary" id="qigeo-save-evidence-btn">保存记录</button>
                    <span class="qigeo-evidence-feedback" id="qigeo-evidence-feedback" aria-live="polite"></span>
                </div>

                <?php self::render_result_fields( $form ); ?>
            </form>
        </div>
        <?php
    }

    private static function render_result_fields( $form ) {
        ?>
        <div class="qigeo-evidence-results">
            <h3>证据库输出结果</h3>
            <div class="qigeo-evidence-grid">
                <div class="qigeo-evidence-field qigeo-evidence-field--wide">
                    <label for="qigeo-evidence-result-summary"><strong>证据库总览</strong></label>
                    <textarea id="qigeo-evidence-result-summary" name="summary" rows="4" placeholder="这里会说明当前这组主张最缺什么，先该补什么。"><?php echo esc_textarea( $form['summary'] ); ?></textarea>
                </div>

                <div class="qigeo-evidence-field qigeo-evidence-field--wide">
                    <label for="qigeo-evidence-result-strategy"><strong>证据策略</strong></label>
                    <textarea id="qigeo-evidence-result-strategy" name="strategy_summary" rows="4" placeholder="这里会说明应该先补哪类证据，再补哪类公开资产。"><?php echo esc_textarea( $form['strategy_summary'] ); ?></textarea>
                </div>

                <div class="qigeo-evidence-field">
                    <label for="qigeo-evidence-result-claims"><strong>必须证明的关键主张</strong></label>
                    <textarea id="qigeo-evidence-result-claims" name="key_claims" rows="9" placeholder="每行一个关键主张，建议带上原因或优先级。"><?php echo esc_textarea( $form['key_claims'] ); ?></textarea>
                </div>

                <div class="qigeo-evidence-field">
                    <label for="qigeo-evidence-result-current"><strong>已有可用证据</strong></label>
                    <textarea id="qigeo-evidence-result-current" name="current_evidence" rows="9" placeholder="每行一个当前已有的公开证据。"><?php echo esc_textarea( $form['current_evidence'] ); ?></textarea>
                </div>

                <div class="qigeo-evidence-field">
                    <label for="qigeo-evidence-result-missing"><strong>缺口证据</strong></label>
                    <textarea id="qigeo-evidence-result-missing" name="missing_evidence" rows="9" placeholder="每行一个最该补齐的证据缺口。"><?php echo esc_textarea( $form['missing_evidence'] ); ?></textarea>
                </div>

                <div class="qigeo-evidence-field">
                    <label for="qigeo-evidence-result-assets"><strong>公开资产计划</strong></label>
                    <textarea id="qigeo-evidence-result-assets" name="public_asset_plan" rows="9" placeholder="每行一个应发布的公开页面或公开内容。"><?php echo esc_textarea( $form['public_asset_plan'] ); ?></textarea>
                </div>

                <div class="qigeo-evidence-field">
                    <label for="qigeo-evidence-result-schema"><strong>结构化与引用辅助</strong></label>
                    <textarea id="qigeo-evidence-result-schema" name="structured_data_plan" rows="8" placeholder="每行一个适合做 FAQ / Schema / 实体统一的点。"><?php echo esc_textarea( $form['structured_data_plan'] ); ?></textarea>
                </div>

                <div class="qigeo-evidence-field">
                    <label for="qigeo-evidence-result-risk"><strong>高风险表达 / 不宜硬说</strong></label>
                    <textarea id="qigeo-evidence-result-risk" name="risk_flags" rows="8" placeholder="每行一个不宜直接说、需要证据后再说或不该说的表达。"><?php echo esc_textarea( $form['risk_flags'] ); ?></textarea>
                </div>

                <div class="qigeo-evidence-field qigeo-evidence-field--wide">
                    <label for="qigeo-evidence-result-steps"><strong>执行顺序</strong></label>
                    <textarea id="qigeo-evidence-result-steps" name="execution_steps" rows="6" placeholder="每行一个动作，说明该先补哪个证据，再发哪个页面。"><?php echo esc_textarea( $form['execution_steps'] ); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_records_card( $rows, $search, $base_url ) {
        ?>
        <div class="qigeo-card">
            <div class="qigeo-card-header">
                <h2>最近记录</h2>
                <span class="description">最多显示最近 20 条，可搜索种子词、目标词、品牌或摘要。</span>
            </div>

            <form method="get" class="qigeo-evidence-search">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索种子词 / 平台 / 目标词 / 品牌 / 摘要" />
                <button type="submit" class="button">搜索</button>
                <a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">重置</a>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:72px;">ID</th>
                        <th style="min-width:220px;">核心词</th>
                        <th style="min-width:180px;">目标推荐词</th>
                        <th style="width:90px;">证据项</th>
                        <th>摘要</th>
                        <th style="min-width:170px;">模型</th>
                        <th style="width:150px;">更新时间</th>
                        <th style="width:90px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="8">暂无记录</td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php self::render_record_row( $row ); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_record_row( $row ) {
        $row_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
        $edit_url = add_query_arg(
            array(
                'page' => self::MENU_SLUG,
                'action' => 'edit',
                'id' => $row_id,
            ),
            admin_url( 'admin.php' )
        );
        $faq_url = self::get_faq_import_url( $row_id );
        $row_target = isset( $row['target_keyword'] ) ? sanitize_text_field( (string) $row['target_keyword'] ) : '';
        $row_platform = isset( $row['target_platform'] ) ? sanitize_text_field( (string) $row['target_platform'] ) : '';
        $row_brand = isset( $row['brand_name'] ) ? sanitize_text_field( (string) $row['brand_name'] ) : '';
        $row_audience = isset( $row['audience_persona'] ) ? sanitize_text_field( (string) $row['audience_persona'] ) : '';
        $result = self::decode_result_json( isset( $row['result_json'] ) ? (string) $row['result_json'] : '' );
        ?>
        <tr>
            <td><?php echo esc_html( (string) $row_id ); ?></td>
            <td>
                <strong><?php echo esc_html( isset( $row['seed_keyword'] ) ? (string) $row['seed_keyword'] : '' ); ?></strong>
                <div class="qigeo-evidence-row-meta"><?php echo esc_html( implode( ' / ', array_filter( array( $row_brand, $row_audience ) ) ) ); ?></div>
            </td>
            <td>
                <strong><?php echo esc_html( $row_target ); ?></strong>
                <div class="qigeo-evidence-row-meta"><?php echo esc_html( $row_platform ); ?></div>
            </td>
            <td><?php echo esc_html( (string) self::count_evidence_items( $result ) ); ?></td>
            <td><?php echo esc_html( self::excerpt_text( isset( $row['result_summary'] ) ? (string) $row['result_summary'] : '' ) ); ?></td>
            <td><?php echo esc_html( isset( $row['model'] ) ? (string) $row['model'] : '' ); ?></td>
            <td><?php echo esc_html( isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '' ); ?></td>
            <td><a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">编辑</a> <a class="button button-small" href="<?php echo esc_url( $faq_url ); ?>">FAQ库</a></td>
        </tr>
        <?php
    }

    private static function get_faq_import_url( $record_id ) {
        $record_id = absint( $record_id );
        if ( $record_id < 1 ) {
            return '';
        }

        return add_query_arg(
            array(
                'page' => 'qigeo-faq-library',
                'source' => 'evidence_library',
                'evidence_id' => $record_id,
            ),
            admin_url( 'admin.php' )
        );
    }

    public static function ajax_generate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'qigeo_generate_evidence_library_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $form = self::get_request_form_data();
        $validation_error = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation_error ) ) {
            wp_send_json_error( array( 'message' => $validation_error->get_error_message() ) );
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
                'message' => '证据库已整理完成，可继续微调后再保存。',
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

        check_ajax_referer( 'qigeo_save_evidence_library_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $form = self::get_request_form_data();
        $validation_error = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation_error ) ) {
            wp_send_json_error( array( 'message' => $validation_error->get_error_message() ) );
        }

        $provider_model = self::resolve_draft_model( $form['model_id'], $form['target_platform'] );
        if ( is_wp_error( $provider_model ) ) {
            wp_send_json_error( array( 'message' => $provider_model->get_error_message() ) );
        }

        $result = self::get_result_data_from_request();
        if (
            $result['summary'] === ''
            && empty( $result['key_claims'] )
            && empty( $result['missing_evidence'] )
            && empty( $result['public_asset_plan'] )
        ) {
            wp_send_json_error( array( 'message' => '请先生成或填写证据库结果后再保存' ) );
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
            'value_claims' => $form['value_claims'],
            'existing_evidence' => $form['existing_evidence'],
            'concern_points' => $form['concern_points'],
            'notes' => $form['notes'],
            'provider_id' => isset( $provider_model['provider_id'] ) ? (string) $provider_model['provider_id'] : '',
            'model_id' => isset( $provider_model['model_id'] ) ? (string) $provider_model['model_id'] : '',
            'model' => isset( $provider_model['model_name'] ) ? (string) $provider_model['model_name'] : '',
            'result_summary' => $result['summary'],
            'result_json' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'updated_at' => $now,
        );

        $formats = array(
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        );

        if ( $record_id > 0 && self::get_item_by_id( $record_id ) ) {
            $updated = $wpdb->update(
                $table,
                $data,
                array( 'id' => $record_id ),
                $formats,
                array( '%d' )
            );
            if ( $updated === false ) {
                wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
            }

            wp_send_json_success(
                array(
                    'message' => '证据库记录已更新。',
                    'record_id' => $record_id,
                )
            );
        }

        $data['created_by'] = $current_user_id;
        $data['created_at'] = $now;

        $inserted = $wpdb->insert(
            $table,
            $data,
            array_merge( $formats, array( '%d', '%s' ) )
        );

        if ( $inserted === false ) {
            wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
        }

        wp_send_json_success(
            array(
                'message' => '证据库记录已保存。',
                'record_id' => absint( $wpdb->insert_id ),
            )
        );
    }

    private static function run_generation( $provider_model, $form ) {
        $payload = self::build_generation_payload( $form );
        $result = QiGeo_AI_Client::analyze_post(
            $provider_model['provider'],
            $provider_model['model'],
            self::default_prompt_template(),
            $payload,
            QiGeo_AI_Client::get_interactive_runtime()
        );

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
            'audience_persona' => $form['audience_persona'],
            'priority_questions' => self::parse_plain_lines( $form['priority_questions'], 12 ),
            'value_claims' => self::parse_plain_lines( $form['value_claims'], 15 ),
            'existing_evidence' => self::parse_plain_lines( $form['existing_evidence'], 20 ),
            'concern_points' => self::parse_plain_lines( $form['concern_points'], 15 ),
            'notes' => self::parse_plain_lines( $form['notes'], 15 ),
        );
    }

    private static function get_imported_content_asset_record() {
        $source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( (string) $_GET['source'] ) ) : '';
        $record_id = isset( $_GET['content_asset_id'] ) ? absint( wp_unslash( (string) $_GET['content_asset_id'] ) ) : 0;

        if ( $source !== 'content_asset' || $record_id < 1 ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'qigeo_content_asset_center';
        $table_exists = QiGeo_Admin_Helper::table_exists( $table );
        if ( ! $table_exists ) {
            return new WP_Error( 'qigeo_evidence_import_table_missing', '内容资产数据表尚未创建，请重新启用插件后再试。' );
        }

        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d LIMIT 1', $record_id ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            return new WP_Error( 'qigeo_evidence_import_missing', '指定的内容资产记录不存在，可能已被删除。' );
        }

        return $row;
    }

    private static function get_form_data_from_content_asset_record( $record ) {
        $form = self::get_empty_form_data();
        $result = self::decode_result_json( isset( $record['result_json'] ) ? (string) $record['result_json'] : '' );
        $record_id = isset( $record['id'] ) ? absint( $record['id'] ) : 0;
        $seed_keyword = isset( $record['seed_keyword'] ) ? sanitize_text_field( (string) $record['seed_keyword'] ) : '';
        $target_keyword = isset( $record['target_keyword'] ) ? sanitize_text_field( (string) $record['target_keyword'] ) : '';
        $brand_name = isset( $record['brand_name'] ) ? sanitize_text_field( (string) $record['brand_name'] ) : '';

        $form['model_id'] = isset( $record['model_id'] ) ? sanitize_key( (string) $record['model_id'] ) : '';
        $form['seed_keyword'] = $seed_keyword !== '' ? $seed_keyword : ( $target_keyword !== '' ? $target_keyword : $brand_name );
        $form['target_platform'] = isset( $record['target_platform'] ) ? sanitize_text_field( (string) $record['target_platform'] ) : '';
        $form['target_keyword'] = $target_keyword !== '' ? $target_keyword : $brand_name;
        $form['brand_name'] = $brand_name !== '' ? $brand_name : $form['target_keyword'];
        $form['audience_persona'] = isset( $record['audience_persona'] ) ? sanitize_text_field( (string) $record['audience_persona'] ) : '';
        $form['priority_questions'] = isset( $record['priority_questions'] ) ? sanitize_textarea_field( (string) $record['priority_questions'] ) : '';
        $form['value_claims'] = isset( $record['key_claims'] ) ? sanitize_textarea_field( (string) $record['key_claims'] ) : '';
        $form['existing_evidence'] = self::combine_import_sections(
            array(
                '现有公开证据' => isset( $record['evidence_summary'] ) ? sanitize_textarea_field( (string) $record['evidence_summary'] ) : '',
                '信任型资产' => isset( $result['trust_assets'] ) ? self::lines_from_array( $result['trust_assets'] ) : '',
                '结构化信号' => isset( $result['schema_plan'] ) ? self::lines_from_array( $result['schema_plan'] ) : '',
            )
        );
        $form['concern_points'] = self::combine_import_sections(
            array(
                '当前回测结论' => isset( $record['backtest_summary'] ) ? sanitize_textarea_field( (string) $record['backtest_summary'] ) : '',
                '待补对比资产' => isset( $result['comparison_pages'] ) ? self::lines_from_array( $result['comparison_pages'] ) : '',
                '待补场景资产' => isset( $result['scenario_pages'] ) ? self::lines_from_array( $result['scenario_pages'] ) : '',
            )
        );
        $form['notes'] = self::combine_import_sections(
            array(
                '来源记录' => $record_id > 0 ? '内容资产中心 #' . $record_id : '',
                '发布策略' => isset( $result['strategy_summary'] ) ? sanitize_textarea_field( (string) $result['strategy_summary'] ) : '',
                '发布执行顺序' => isset( $result['execution_steps'] ) ? self::lines_from_array( $result['execution_steps'] ) : '',
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
            'seed_keyword' => '',
            'target_platform' => '',
            'target_keyword' => '',
            'brand_name' => '',
            'audience_persona' => '',
            'priority_questions' => '',
            'value_claims' => '',
            'existing_evidence' => '',
            'concern_points' => '',
            'notes' => '',
            'summary' => '',
            'strategy_summary' => '',
            'key_claims' => '',
            'current_evidence' => '',
            'missing_evidence' => '',
            'public_asset_plan' => '',
            'structured_data_plan' => '',
            'risk_flags' => '',
            'execution_steps' => '',
        );
    }

    private static function record_to_form_data( $record ) {
        $form = self::get_empty_form_data();
        $form['record_id'] = isset( $record['id'] ) ? absint( $record['id'] ) : 0;
        $form['model_id'] = isset( $record['model_id'] ) ? sanitize_key( (string) $record['model_id'] ) : '';
        $form['seed_keyword'] = isset( $record['seed_keyword'] ) ? sanitize_text_field( (string) $record['seed_keyword'] ) : '';
        $form['target_platform'] = isset( $record['target_platform'] ) ? sanitize_text_field( (string) $record['target_platform'] ) : '';
        $form['target_keyword'] = isset( $record['target_keyword'] ) ? sanitize_text_field( (string) $record['target_keyword'] ) : '';
        $form['brand_name'] = isset( $record['brand_name'] ) ? sanitize_text_field( (string) $record['brand_name'] ) : '';
        $form['audience_persona'] = isset( $record['audience_persona'] ) ? sanitize_text_field( (string) $record['audience_persona'] ) : '';
        $form['priority_questions'] = isset( $record['priority_questions'] ) ? sanitize_textarea_field( (string) $record['priority_questions'] ) : '';
        $form['value_claims'] = isset( $record['value_claims'] ) ? sanitize_textarea_field( (string) $record['value_claims'] ) : '';
        $form['existing_evidence'] = isset( $record['existing_evidence'] ) ? sanitize_textarea_field( (string) $record['existing_evidence'] ) : '';
        $form['concern_points'] = isset( $record['concern_points'] ) ? sanitize_textarea_field( (string) $record['concern_points'] ) : '';
        $form['notes'] = isset( $record['notes'] ) ? sanitize_textarea_field( (string) $record['notes'] ) : '';

        $result = self::decode_result_json( isset( $record['result_json'] ) ? (string) $record['result_json'] : '' );
        $form['summary'] = isset( $result['summary'] ) ? sanitize_textarea_field( (string) $result['summary'] ) : '';
        if ( $form['summary'] === '' && ! empty( $record['result_summary'] ) ) {
            $form['summary'] = sanitize_textarea_field( (string) $record['result_summary'] );
        }
        $form['strategy_summary'] = isset( $result['strategy_summary'] ) ? sanitize_textarea_field( (string) $result['strategy_summary'] ) : '';
        $form['key_claims'] = self::lines_from_array( isset( $result['key_claims'] ) ? $result['key_claims'] : array() );
        $form['current_evidence'] = self::lines_from_array( isset( $result['current_evidence'] ) ? $result['current_evidence'] : array() );
        $form['missing_evidence'] = self::lines_from_array( isset( $result['missing_evidence'] ) ? $result['missing_evidence'] : array() );
        $form['public_asset_plan'] = self::lines_from_array( isset( $result['public_asset_plan'] ) ? $result['public_asset_plan'] : array() );
        $form['structured_data_plan'] = self::lines_from_array( isset( $result['structured_data_plan'] ) ? $result['structured_data_plan'] : array() );
        $form['risk_flags'] = self::lines_from_array( isset( $result['risk_flags'] ) ? $result['risk_flags'] : array() );
        $form['execution_steps'] = self::lines_from_array( isset( $result['execution_steps'] ) ? $result['execution_steps'] : array() );

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
            'value_claims' => isset( $_POST['value_claims'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['value_claims'] ) ) : '',
            'existing_evidence' => isset( $_POST['existing_evidence'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['existing_evidence'] ) ) : '',
            'concern_points' => isset( $_POST['concern_points'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['concern_points'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        );
    }

    private static function get_result_data_from_request() {
        return array(
            'summary' => self::limit_text( isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '', 800 ),
            'strategy_summary' => self::limit_text( isset( $_POST['strategy_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['strategy_summary'] ) ) : '', 800 ),
            'key_claims' => self::parse_plain_lines( isset( $_POST['key_claims'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['key_claims'] ) ) : '', 15 ),
            'current_evidence' => self::parse_plain_lines( isset( $_POST['current_evidence'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['current_evidence'] ) ) : '', 20 ),
            'missing_evidence' => self::parse_plain_lines( isset( $_POST['missing_evidence'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['missing_evidence'] ) ) : '', 20 ),
            'public_asset_plan' => self::parse_plain_lines( isset( $_POST['public_asset_plan'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['public_asset_plan'] ) ) : '', 20 ),
            'structured_data_plan' => self::parse_plain_lines( isset( $_POST['structured_data_plan'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['structured_data_plan'] ) ) : '', 15 ),
            'risk_flags' => self::parse_plain_lines( isset( $_POST['risk_flags'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['risk_flags'] ) ) : '', 15 ),
            'execution_steps' => self::parse_plain_lines( isset( $_POST['execution_steps'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['execution_steps'] ) ) : '', 12 ),
        );
    }

    private static function validate_base_form_data( $form ) {
        if ( empty( $form['seed_keyword'] ) ) {
            return new WP_Error( 'qigeo_evidence_seed_required', '请先填写核心主题词 / 种子词' );
        }
        if ( empty( $form['target_platform'] ) ) {
            return new WP_Error( 'qigeo_evidence_platform_required', '请先填写目标 AI 平台' );
        }
        if ( empty( $form['target_keyword'] ) ) {
            return new WP_Error( 'qigeo_evidence_target_required', '请先填写希望被推荐的词' );
        }

        return true;
    }

    private static function get_records( $search = '', $limit = 20 ) {
        global $wpdb;

        $limit = absint( $limit );
        if ( $limit < 1 ) {
            $limit = 20;
        }

        $table = self::get_table_name();
        $where = '1=1';
        $params = array();

        if ( $search !== '' ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND (seed_keyword LIKE %s OR target_platform LIKE %s OR target_keyword LIKE %s OR brand_name LIKE %s OR result_summary LIKE %s)';
            $params = array( $like, $like, $like, $like, $like );
        }

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC, id DESC LIMIT %d";
        $params[] = $limit;

        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    private static function get_item_by_id( $record_id ) {
        global $wpdb;

        $record_id = absint( $record_id );
        if ( $record_id < 1 ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::get_table_name() . ' WHERE id = %d LIMIT 1',
                $record_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'qigeo_evidence_library';
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
            return new WP_Error( 'qigeo_evidence_model_missing', '请先在启灵GEO配置并启用至少一个可用模型' );
        }

        $platform_key = self::normalize_platform_key( $target_platform );
        $preferred_packet = null;
        if ( $preferred_model_id !== '' ) {
            foreach ( $packets as $packet ) {
                if ( isset( $packet['model_id'] ) && sanitize_key( (string) $packet['model_id'] ) === sanitize_key( (string) $preferred_model_id ) ) {
                    $preferred_packet = $packet;
                    break;
                }
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
        foreach ( $options as $option ) {
            if ( ! empty( $option['model_id'] ) ) {
                return sanitize_key( (string) $option['model_id'] );
            }
        }

        return '';
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
        return ! empty( $model['enabled'] )
            && $model['enabled'] === '1'
            && ! empty( $model['model'] );
    }

    private static function normalize_generated_result( $result ) {
        $parsed = isset( $result['parsed'] ) && is_array( $result['parsed'] ) ? $result['parsed'] : array();

        return array(
            'summary' => self::limit_text( isset( $parsed['summary'] ) ? sanitize_textarea_field( (string) $parsed['summary'] ) : '', 800 ),
            'strategy_summary' => self::limit_text(
                isset( $parsed['strategy_summary'] ) ? sanitize_textarea_field( (string) $parsed['strategy_summary'] ) : ( isset( $parsed['evidence_strategy'] ) ? sanitize_textarea_field( (string) $parsed['evidence_strategy'] ) : '' ),
                800
            ),
            'key_claims' => self::normalize_string_list( isset( $parsed['key_claims'] ) ? $parsed['key_claims'] : array(), 15 ),
            'current_evidence' => self::normalize_string_list( isset( $parsed['current_evidence'] ) ? $parsed['current_evidence'] : array(), 20 ),
            'missing_evidence' => self::normalize_string_list( isset( $parsed['missing_evidence'] ) ? $parsed['missing_evidence'] : array(), 20 ),
            'public_asset_plan' => self::normalize_string_list( isset( $parsed['public_asset_plan'] ) ? $parsed['public_asset_plan'] : array(), 20 ),
            'structured_data_plan' => self::normalize_string_list( isset( $parsed['structured_data_plan'] ) ? $parsed['structured_data_plan'] : array(), 15 ),
            'risk_flags' => self::normalize_string_list( isset( $parsed['risk_flags'] ) ? $parsed['risk_flags'] : array(), 15 ),
            'execution_steps' => self::normalize_string_list( isset( $parsed['execution_steps'] ) ? $parsed['execution_steps'] : array(), 12 ),
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

            $normalized[] = self::limit_text( $text, 300 );
            if ( count( $normalized ) >= $limit ) {
                break;
            }
        }

        return $normalized;
    }

    private static function parse_plain_lines( $text, $limit = 10 ) {
        $lines = preg_split( "/\r\n|\r|\n/", (string) $text );
        $result = array();
        foreach ( (array) $lines as $line ) {
            $value = trim( sanitize_textarea_field( (string) $line ) );
            if ( $value === '' || in_array( $value, $result, true ) ) {
                continue;
            }

            $result[] = self::limit_text( $value, 300 );
            if ( count( $result ) >= $limit ) {
                break;
            }
        }

        return $result;
    }

    private static function lines_from_array( $items ) {
        $lines = array();
        foreach ( (array) $items as $item ) {
            if ( ! is_scalar( $item ) ) {
                continue;
            }

            $value = trim( sanitize_textarea_field( (string) $item ) );
            if ( $value === '' ) {
                continue;
            }

            $lines[] = $value;
        }

        return implode( "\n", $lines );
    }

    private static function decode_result_json( $result_json ) {
        $decoded = json_decode( (string) $result_json, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    private static function count_evidence_items( $result ) {
        $count = 0;
        foreach ( array( 'key_claims', 'current_evidence', 'missing_evidence', 'public_asset_plan', 'structured_data_plan', 'risk_flags' ) as $key ) {
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
            if ( mb_strlen( $text, 'UTF-8' ) <= $length ) {
                return $text;
            }

            return mb_substr( $text, 0, $length, 'UTF-8' ) . '...';
        }

        if ( strlen( $text ) <= $length ) {
            return $text;
        }

        return substr( $text, 0, $length ) . '...';
    }

    private static function limit_text( $text, $length = 300 ) {
        $text = trim( (string) $text );
        if ( $text === '' ) {
            return '';
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $text, 'UTF-8' ) <= $length ) {
                return $text;
            }

            return trim( mb_substr( $text, 0, $length, 'UTF-8' ) );
        }

        if ( strlen( $text ) <= $length ) {
            return $text;
        }

        return trim( substr( $text, 0, $length ) );
    }

    private static function default_prompt_template() {
        return "你是 GEO 证据库策划助手。现在要围绕一个核心主题词和目标推荐词，梳理哪些主张必须被公开证据支撑，哪些公开资产必须补齐，帮助后续提升 AI 推荐的可信度。请严格输出 JSON，不要 markdown，不要解释。\n"
            . "JSON 结构：\n"
            . "{\n"
            . "  \"summary\": \"80-220字，概括当前最缺的证据和最该优先补的方向\",\n"
            . "  \"strategy_summary\": \"80-220字，说明应该先补哪些证据，再发哪些公开资产\",\n"
            . "  \"key_claims\": [\"必须证明的主张 || 优先级：高/中 || 原因：...\"],\n"
            . "  \"current_evidence\": [\"当前已有且可公开引用的证据1\", \"证据2\"],\n"
            . "  \"missing_evidence\": [\"还缺的关键证据1 || 影响：... || 建议补法：...\"],\n"
            . "  \"public_asset_plan\": [\"应新增或补强的公开页面/公开内容1\", \"内容2\"],\n"
            . "  \"structured_data_plan\": [\"适合做 FAQ / Schema / 实体统一的点1\", \"点2\"],\n"
            . "  \"risk_flags\": [\"不宜直接说的表达1 || 原因：...\", \"表达2\"],\n"
            . "  \"execution_steps\": [\"下一步动作1\", \"动作2\"]\n"
            . "}\n"
            . "要求：\n"
            . "1) 重点围绕可公开抓取、可被引用、可被验证的证据，不要把私域聊天记录、口头承诺或后台资料当成主要证据。\n"
            . "2) key_claims 建议 6-10 条，优先聚焦最影响 AI 推荐的主张。\n"
            . "3) current_evidence 和 missing_evidence 要尽量具体，避免空泛句子。\n"
            . "4) public_asset_plan 要优先给出专题页、对比页、FAQ 页、教程页、案例页、文档页、更新日志页之类公开资产。\n"
            . "5) risk_flags 要明确哪些表达没有证据前不能硬说，或根本不适合说。\n"
            . "6) 不要虚构销量、奖项、媒体背书、用户评价、官方合作、测试数据或市场份额。\n"
            . "7) 输出必须是合法 JSON。";
    }
}
