<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Competitor_Evidence_Library {

    const MENU_SLUG = 'qigeo-competitor-evidence-library';
    const AJAX_GENERATE_ACTION = 'qigeo_generate_competitor_evidence_library';
    const AJAX_SAVE_ACTION = 'qigeo_save_competitor_evidence_library';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '竞品样本证据库',
            '竞品样本证据库',
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
            'qigeo-competitor-evidence-library-admin',
            QIGEO_URL . 'assets/competitor-evidence-admin.js',
            array( 'qigeo-admin' ),
            QIGEO_VERSION,
            true
        );

        wp_localize_script(
            'qigeo-competitor-evidence-library-admin',
            'qigeoCompetitorEvidenceLibraryData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_competitor_evidence_library_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_competitor_evidence_library_nonce' ),
                'messages' => array(
                    'generating' => '正在整理竞品样本与证据...',
                    'saving' => '正在保存竞品样本证据记录...',
                    'generateDone' => '竞品样本证据已生成，可继续微调后保存。',
                    'saveDone' => '竞品样本证据记录已保存。',
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
        if ( is_array( $current ) ) {
            $form = self::record_to_form_data( $current );
        } else {
            $form['model_id'] = isset( $settings['default_model_id'] ) ? sanitize_key( (string) $settings['default_model_id'] ) : '';
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
            <h1>竞品样本证据库</h1>
            <p class="description">整理竞品相关样本和公开资料。</p>

            <?php if ( ! $table_exists ) : ?>
                <div class="notice notice-error"><p>当前页面数据暂未准备好，请刷新页面后重试。</p></div>
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
                <h2><?php echo is_array( $current ) ? '编辑竞品样本证据记录' : '新建竞品样本证据记录'; ?></h2>
                <div>
                    <?php if ( is_array( $current ) && ! empty( $current['id'] ) ) : ?>
                        <a class="button button-secondary" href="<?php echo esc_url( self::get_pressure_import_url( absint( $current['id'] ) ) ); ?>">带去竞品压制分析</a>
                    <?php endif; ?>
                    <a class="button" href="<?php echo esc_url( $base_url ); ?>">新建空白记录</a>
                </div>
            </div>

            <?php if ( is_array( $current ) ) : ?>
                <p class="qigeo-platform-active-record">已加载记录 #<?php echo esc_html( (string) absint( $current['id'] ) ); ?>，你可以继续补样本、调整结果或覆盖保存。</p>
            <?php endif; ?>

            <?php if ( empty( $model_options ) ) : ?>
                <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
            <?php endif; ?>

            <form id="qigeo-competitor-evidence-form" class="qigeo-platform-form" action="#" method="post">
                <input type="hidden" id="qigeo-competitor-evidence-record-id" name="record_id" value="<?php echo esc_attr( (string) $form['record_id'] ); ?>" />

                <div class="qigeo-platform-grid">
                    <div class="qigeo-platform-field">
                        <label for="qigeo-competitor-evidence-target-platform"><strong>目标 AI 平台</strong></label>
                        <input type="text" class="regular-text" id="qigeo-competitor-evidence-target-platform" name="target_platform" maxlength="120" value="<?php echo esc_attr( $form['target_platform'] ); ?>" placeholder="例如：豆包 / ChatGPT / DeepSeek" />
                    </div>

                    <div class="qigeo-platform-field">
                        <label for="qigeo-competitor-evidence-target-keyword"><strong>被抢推荐位的目标词</strong></label>
                        <input type="text" class="regular-text" id="qigeo-competitor-evidence-target-keyword" name="target_keyword" maxlength="255" value="<?php echo esc_attr( $form['target_keyword'] ); ?>" placeholder="例如：中文 WordPress 主题 / 企业站主题" />
                    </div>

                    <div class="qigeo-platform-field">
                        <label for="qigeo-competitor-evidence-brand-name"><strong>品牌 / 产品名</strong></label>
                        <input type="text" class="regular-text" id="qigeo-competitor-evidence-brand-name" name="brand_name" maxlength="190" value="<?php echo esc_attr( $form['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                    </div>

                    <div class="qigeo-platform-field">
                        <label for="qigeo-competitor-evidence-competitor-name"><strong>当前主压制竞品</strong></label>
                        <input type="text" class="regular-text" id="qigeo-competitor-evidence-competitor-name" name="competitor_name" maxlength="190" value="<?php echo esc_attr( $form['competitor_name'] ); ?>" placeholder="例如：Astra / Blocksy / GeneratePress" />
                    </div>

                    <div class="qigeo-platform-field">
                        <label for="qigeo-competitor-evidence-audience"><strong>目标人群 / 场景</strong></label>
                        <input type="text" class="regular-text" id="qigeo-competitor-evidence-audience" name="audience_persona" maxlength="190" value="<?php echo esc_attr( $form['audience_persona'] ); ?>" placeholder="例如：中文企业站、新手站长、建站服务商" />
                    </div>

                    <div class="qigeo-platform-field">
                        <label for="qigeo-competitor-evidence-model-id"><strong>优先整理模型</strong></label>
                        <select id="qigeo-competitor-evidence-model-id" name="model_id">
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

                    <div class="qigeo-platform-field qigeo-platform-field--wide">
                        <label for="qigeo-competitor-evidence-sample-questions"><strong>真实问法样本</strong></label>
                        <textarea id="qigeo-competitor-evidence-sample-questions" name="sample_questions" rows="6" placeholder="每行一个真实问法，例如：&#10;wordpress 最好的中文主题是什么&#10;适合中文企业站的 wordpress 主题有哪些"><?php echo esc_textarea( $form['sample_questions'] ); ?></textarea>
                    </div>

                    <div class="qigeo-platform-field">
                        <label for="qigeo-competitor-evidence-suppression-samples"><strong>被抢走推荐位的真实回答样本</strong></label>
                        <textarea id="qigeo-competitor-evidence-suppression-samples" name="suppression_samples" rows="9" placeholder="粘贴原始回答、推荐顺序、对比描述，越接近原文越好。"><?php echo esc_textarea( $form['suppression_samples'] ); ?></textarea>
                    </div>

                    <div class="qigeo-platform-field">
                        <label for="qigeo-competitor-evidence-competitor-evidence"><strong>竞品公开证据 / 页面 / 话术</strong></label>
                        <textarea id="qigeo-competitor-evidence-competitor-evidence" name="competitor_evidence" rows="9" placeholder="每行一条，例如：官方文档页 / 主题对比页 / 中文教程页 / 公开案例页 / 常见被引用表述。"><?php echo esc_textarea( $form['competitor_evidence'] ); ?></textarea>
                    </div>

                    <div class="qigeo-platform-field">
                        <label for="qigeo-competitor-evidence-brand-evidence"><strong>我方公开证据 / 页面 / 话术</strong></label>
                        <textarea id="qigeo-competitor-evidence-brand-evidence" name="brand_evidence" rows="9" placeholder="每行一条，例如：官网页 / 主题介绍页 / 案例页 / FAQ / 更新日志 / 教程页。"><?php echo esc_textarea( $form['brand_evidence'] ); ?></textarea>
                    </div>

                    <div class="qigeo-platform-field">
                        <label for="qigeo-competitor-evidence-links"><strong>重点公开链接 / 证据 URL</strong></label>
                        <textarea id="qigeo-competitor-evidence-links" name="evidence_links" rows="9" placeholder="每行一个链接或页面标题，便于后续沉淀成可引用来源。"><?php echo esc_textarea( $form['evidence_links'] ); ?></textarea>
                    </div>

                    <div class="qigeo-platform-field qigeo-platform-field--wide">
                        <label for="qigeo-competitor-evidence-notes"><strong>补充限制 / 备注</strong></label>
                        <textarea id="qigeo-competitor-evidence-notes" name="notes" rows="4" placeholder="例如：不要做虚假比较；只用可公开验证信息；优先中文企业站场景；避免绝对化第一名表述。"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
                    </div>
                </div>

                <div class="qigeo-platform-actions">
                    <button type="button" class="button button-secondary" id="qigeo-generate-competitor-evidence-btn">整理样本证据</button>
                    <button type="button" class="button button-primary" id="qigeo-save-competitor-evidence-btn">保存记录</button>
                    <span class="qigeo-platform-feedback" id="qigeo-competitor-evidence-feedback" aria-live="polite"></span>
                </div>

                <?php self::render_result_fields( $form ); ?>
            </form>
        </div>
        <?php
    }

    private static function render_result_fields( $form ) {
        ?>
        <div class="qigeo-platform-results">
            <h3>竞品样本证据输出结果</h3>
            <div class="qigeo-platform-grid">
                <div class="qigeo-platform-field qigeo-platform-field--wide">
                    <label for="qigeo-competitor-evidence-result-summary"><strong>样本证据总览</strong></label>
                    <textarea id="qigeo-competitor-evidence-result-summary" name="summary" rows="4"><?php echo esc_textarea( $form['summary'] ); ?></textarea>
                </div>

                <div class="qigeo-platform-field qigeo-platform-field--wide">
                    <label for="qigeo-competitor-evidence-result-judgement"><strong>证据判断</strong></label>
                    <textarea id="qigeo-competitor-evidence-result-judgement" name="evidence_judgement" rows="4"><?php echo esc_textarea( $form['evidence_judgement'] ); ?></textarea>
                </div>

                <div class="qigeo-platform-field">
                    <label for="qigeo-competitor-evidence-result-patterns"><strong>样本模式</strong></label>
                    <textarea id="qigeo-competitor-evidence-result-patterns" name="sample_patterns" rows="9"><?php echo esc_textarea( $form['sample_patterns'] ); ?></textarea>
                </div>

                <div class="qigeo-platform-field">
                    <label for="qigeo-competitor-evidence-result-signals"><strong>竞品被引用信号</strong></label>
                    <textarea id="qigeo-competitor-evidence-result-signals" name="competitor_quote_signals" rows="9"><?php echo esc_textarea( $form['competitor_quote_signals'] ); ?></textarea>
                </div>

                <div class="qigeo-platform-field">
                    <label for="qigeo-competitor-evidence-result-proof"><strong>我方可复用证明</strong></label>
                    <textarea id="qigeo-competitor-evidence-result-proof" name="brand_reusable_proof" rows="9"><?php echo esc_textarea( $form['brand_reusable_proof'] ); ?></textarea>
                </div>

                <div class="qigeo-platform-field">
                    <label for="qigeo-competitor-evidence-result-missing"><strong>缺口证据</strong></label>
                    <textarea id="qigeo-competitor-evidence-result-missing" name="missing_evidence" rows="9"><?php echo esc_textarea( $form['missing_evidence'] ); ?></textarea>
                </div>

                <div class="qigeo-platform-field">
                    <label for="qigeo-competitor-evidence-result-comparisons"><strong>可打的对比角度</strong></label>
                    <textarea id="qigeo-competitor-evidence-result-comparisons" name="comparison_angles" rows="9"><?php echo esc_textarea( $form['comparison_angles'] ); ?></textarea>
                </div>

                <div class="qigeo-platform-field">
                    <label for="qigeo-competitor-evidence-result-snippets"><strong>可沉淀的引用片段</strong></label>
                    <textarea id="qigeo-competitor-evidence-result-snippets" name="citation_snippets" rows="9"><?php echo esc_textarea( $form['citation_snippets'] ); ?></textarea>
                </div>

                <div class="qigeo-platform-field">
                    <label for="qigeo-competitor-evidence-result-retest"><strong>下一轮回测问题</strong></label>
                    <textarea id="qigeo-competitor-evidence-result-retest" name="retest_questions" rows="8"><?php echo esc_textarea( $form['retest_questions'] ); ?></textarea>
                </div>

                <div class="qigeo-platform-field qigeo-platform-field--wide">
                    <label for="qigeo-competitor-evidence-result-steps"><strong>执行顺序</strong></label>
                    <textarea id="qigeo-competitor-evidence-result-steps" name="execution_steps" rows="6"><?php echo esc_textarea( $form['execution_steps'] ); ?></textarea>
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
                <span class="description">最多显示最近 20 条，可搜索平台、目标词、品牌、竞品或摘要。</span>
            </div>

            <form method="get" class="qigeo-platform-search">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索平台 / 目标词 / 品牌 / 竞品 / 摘要" />
                <button type="submit" class="button">搜索</button>
                <a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">重置</a>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:72px;">ID</th>
                        <th style="min-width:220px;">平台 / 目标词</th>
                        <th style="min-width:180px;">品牌 / 竞品</th>
                        <th style="width:90px;">证据项</th>
                        <th>摘要</th>
                        <th style="min-width:170px;">模型</th>
                        <th style="width:150px;">更新时间</th>
                        <th style="width:180px;">操作</th>
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
        $edit_url = add_query_arg( array( 'page' => self::MENU_SLUG, 'action' => 'edit', 'id' => $row_id ), admin_url( 'admin.php' ) );
        $pressure_url = self::get_pressure_import_url( $row_id );
        $row_target = isset( $row['target_keyword'] ) ? sanitize_text_field( (string) $row['target_keyword'] ) : '';
        $row_platform = isset( $row['target_platform'] ) ? sanitize_text_field( (string) $row['target_platform'] ) : '';
        $row_brand = isset( $row['brand_name'] ) ? sanitize_text_field( (string) $row['brand_name'] ) : '';
        $row_competitor = isset( $row['competitor_name'] ) ? sanitize_text_field( (string) $row['competitor_name'] ) : '';
        $result = self::decode_result_json( isset( $row['result_json'] ) ? (string) $row['result_json'] : '' );
        ?>
        <tr>
            <td><?php echo esc_html( (string) $row_id ); ?></td>
            <td>
                <strong><?php echo esc_html( $row_platform ); ?></strong>
                <div class="qigeo-platform-row-meta"><?php echo esc_html( $row_target ); ?></div>
            </td>
            <td>
                <strong><?php echo esc_html( $row_brand ); ?></strong>
                <div class="qigeo-platform-row-meta"><?php echo esc_html( $row_competitor ); ?></div>
            </td>
            <td><?php echo esc_html( (string) self::count_evidence_items( $result ) ); ?></td>
            <td><?php echo esc_html( self::excerpt_text( isset( $row['result_summary'] ) ? (string) $row['result_summary'] : '' ) ); ?></td>
            <td><?php echo esc_html( isset( $row['model'] ) ? (string) $row['model'] : '' ); ?></td>
            <td><?php echo esc_html( isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '' ); ?></td>
            <td>
                <a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">编辑</a>
                <a class="button button-small" href="<?php echo esc_url( $pressure_url ); ?>">去压制分析</a>
            </td>
        </tr>
        <?php
    }

    private static function get_pressure_import_url( $record_id ) {
        return add_query_arg(
            array(
                'page' => 'qigeo-competitor-pressure-center',
                'source' => 'competitor_evidence',
                'evidence_id' => absint( $record_id ),
            ),
            admin_url( 'admin.php' )
        );
    }

    public static function ajax_generate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'qigeo_generate_competitor_evidence_library_nonce', 'nonce' );

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
                'message' => '竞品样本证据已整理完成，可继续微调后再保存。',
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

        check_ajax_referer( 'qigeo_save_competitor_evidence_library_nonce', 'nonce' );

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
            && empty( $result['sample_patterns'] )
            && empty( $result['competitor_quote_signals'] )
            && empty( $result['brand_reusable_proof'] )
            && empty( $result['missing_evidence'] )
            && empty( $result['execution_steps'] )
        ) {
            wp_send_json_error( array( 'message' => '请先生成或填写竞品样本证据结果后再保存' ) );
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
            'competitor_name' => $form['competitor_name'],
            'audience_persona' => $form['audience_persona'],
            'sample_questions' => $form['sample_questions'],
            'suppression_samples' => $form['suppression_samples'],
            'competitor_evidence' => $form['competitor_evidence'],
            'brand_evidence' => $form['brand_evidence'],
            'evidence_links' => $form['evidence_links'],
            'notes' => $form['notes'],
            'provider_id' => isset( $provider_model['provider_id'] ) ? (string) $provider_model['provider_id'] : '',
            'model_id' => isset( $provider_model['model_id'] ) ? (string) $provider_model['model_id'] : '',
            'model' => isset( $provider_model['model_name'] ) ? (string) $provider_model['model_name'] : '',
            'result_summary' => $result['summary'],
            'result_json' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'updated_at' => $now,
        );
        $formats = array_fill( 0, 17, '%s' );

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
                    'message' => '竞品样本证据记录已更新。',
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
                'message' => '竞品样本证据记录已保存。',
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
            'target_platform' => $form['target_platform'],
            'target_keyword' => $form['target_keyword'],
            'brand_name' => $form['brand_name'],
            'competitor_name' => $form['competitor_name'],
            'audience_persona' => $form['audience_persona'],
            'sample_questions' => self::parse_plain_lines( $form['sample_questions'], 15 ),
            'suppression_samples' => $form['suppression_samples'],
            'competitor_evidence' => self::parse_plain_lines( $form['competitor_evidence'], 20 ),
            'brand_evidence' => self::parse_plain_lines( $form['brand_evidence'], 20 ),
            'evidence_links' => self::parse_plain_lines( $form['evidence_links'], 20 ),
            'notes' => self::parse_plain_lines( $form['notes'], 15 ),
        );
    }

    private static function get_empty_form_data() {
        return array(
            'record_id' => 0,
            'model_id' => '',
            'target_platform' => '',
            'target_keyword' => '',
            'brand_name' => '',
            'competitor_name' => '',
            'audience_persona' => '',
            'sample_questions' => '',
            'suppression_samples' => '',
            'competitor_evidence' => '',
            'brand_evidence' => '',
            'evidence_links' => '',
            'notes' => '',
            'summary' => '',
            'evidence_judgement' => '',
            'sample_patterns' => '',
            'competitor_quote_signals' => '',
            'brand_reusable_proof' => '',
            'missing_evidence' => '',
            'comparison_angles' => '',
            'citation_snippets' => '',
            'retest_questions' => '',
            'execution_steps' => '',
        );
    }

    private static function record_to_form_data( $record ) {
        $form = self::get_empty_form_data();
        foreach ( array( 'id' => 'record_id', 'model_id' => 'model_id', 'target_platform' => 'target_platform', 'target_keyword' => 'target_keyword', 'brand_name' => 'brand_name', 'competitor_name' => 'competitor_name', 'audience_persona' => 'audience_persona', 'sample_questions' => 'sample_questions', 'suppression_samples' => 'suppression_samples', 'competitor_evidence' => 'competitor_evidence', 'brand_evidence' => 'brand_evidence', 'evidence_links' => 'evidence_links', 'notes' => 'notes' ) as $source => $target ) {
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
        $form['evidence_judgement'] = isset( $result['evidence_judgement'] ) ? sanitize_textarea_field( (string) $result['evidence_judgement'] ) : '';
        foreach ( array( 'sample_patterns', 'competitor_quote_signals', 'brand_reusable_proof', 'missing_evidence', 'comparison_angles', 'citation_snippets', 'retest_questions', 'execution_steps' ) as $key ) {
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
            'competitor_name' => self::limit_text( isset( $_POST['competitor_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['competitor_name'] ) ) : '', 190 ),
            'audience_persona' => self::limit_text( isset( $_POST['audience_persona'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['audience_persona'] ) ) : '', 190 ),
            'sample_questions' => isset( $_POST['sample_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['sample_questions'] ) ) : '',
            'suppression_samples' => isset( $_POST['suppression_samples'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['suppression_samples'] ) ) : '',
            'competitor_evidence' => isset( $_POST['competitor_evidence'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['competitor_evidence'] ) ) : '',
            'brand_evidence' => isset( $_POST['brand_evidence'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['brand_evidence'] ) ) : '',
            'evidence_links' => isset( $_POST['evidence_links'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['evidence_links'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        );
    }

    private static function get_result_data_from_request() {
        return array(
            'summary' => self::limit_text( isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '', 800 ),
            'evidence_judgement' => self::limit_text( isset( $_POST['evidence_judgement'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['evidence_judgement'] ) ) : '', 800 ),
            'sample_patterns' => self::parse_plain_lines( isset( $_POST['sample_patterns'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['sample_patterns'] ) ) : '', 15 ),
            'competitor_quote_signals' => self::parse_plain_lines( isset( $_POST['competitor_quote_signals'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['competitor_quote_signals'] ) ) : '', 20 ),
            'brand_reusable_proof' => self::parse_plain_lines( isset( $_POST['brand_reusable_proof'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['brand_reusable_proof'] ) ) : '', 20 ),
            'missing_evidence' => self::parse_plain_lines( isset( $_POST['missing_evidence'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['missing_evidence'] ) ) : '', 20 ),
            'comparison_angles' => self::parse_plain_lines( isset( $_POST['comparison_angles'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['comparison_angles'] ) ) : '', 20 ),
            'citation_snippets' => self::parse_plain_lines( isset( $_POST['citation_snippets'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['citation_snippets'] ) ) : '', 20 ),
            'retest_questions' => self::parse_plain_lines( isset( $_POST['retest_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['retest_questions'] ) ) : '', 15 ),
            'execution_steps' => self::parse_plain_lines( isset( $_POST['execution_steps'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['execution_steps'] ) ) : '', 15 ),
        );
    }

    private static function validate_base_form_data( $form ) {
        if ( empty( $form['target_platform'] ) ) {
            return new WP_Error( 'qigeo_competitor_evidence_platform_required', '请先填写目标 AI 平台' );
        }
        if ( empty( $form['target_keyword'] ) ) {
            return new WP_Error( 'qigeo_competitor_evidence_target_required', '请先填写被抢推荐位的目标词' );
        }
        if ( empty( $form['brand_name'] ) ) {
            return new WP_Error( 'qigeo_competitor_evidence_brand_required', '请先填写品牌 / 产品名' );
        }
        if ( empty( $form['competitor_name'] ) ) {
            return new WP_Error( 'qigeo_competitor_evidence_competitor_required', '请先填写当前主压制竞品' );
        }
        if ( empty( self::parse_plain_lines( $form['sample_questions'], 1 ) ) && $form['suppression_samples'] === '' ) {
            return new WP_Error( 'qigeo_competitor_evidence_samples_required', '请至少填写一条真实问法样本或真实回答样本' );
        }
        if ( empty( self::parse_plain_lines( $form['competitor_evidence'], 1 ) ) && empty( self::parse_plain_lines( $form['brand_evidence'], 1 ) ) && empty( self::parse_plain_lines( $form['evidence_links'], 1 ) ) ) {
            return new WP_Error( 'qigeo_competitor_evidence_public_proof_required', '请至少填写一条竞品证据、我方证据或重点公开链接' );
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
            $where .= ' AND (target_platform LIKE %s OR target_keyword LIKE %s OR brand_name LIKE %s OR competitor_name LIKE %s OR result_summary LIKE %s)';
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
        return $wpdb->prefix . 'qigeo_competitor_evidence_library';
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
            return new WP_Error( 'qigeo_competitor_evidence_model_missing', '请先在启灵GEO配置并启用至少一个可用模型' );
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
            'evidence_judgement' => self::limit_text(
                isset( $parsed['evidence_judgement'] ) ? sanitize_textarea_field( (string) $parsed['evidence_judgement'] ) : ( isset( $parsed['strategy_summary'] ) ? sanitize_textarea_field( (string) $parsed['strategy_summary'] ) : '' ),
                800
            ),
            'sample_patterns' => self::normalize_string_list( isset( $parsed['sample_patterns'] ) ? $parsed['sample_patterns'] : ( isset( $parsed['pattern_summary'] ) ? $parsed['pattern_summary'] : array() ), 15 ),
            'competitor_quote_signals' => self::normalize_string_list( isset( $parsed['competitor_quote_signals'] ) ? $parsed['competitor_quote_signals'] : ( isset( $parsed['competitor_advantages'] ) ? $parsed['competitor_advantages'] : array() ), 20 ),
            'brand_reusable_proof' => self::normalize_string_list( isset( $parsed['brand_reusable_proof'] ) ? $parsed['brand_reusable_proof'] : ( isset( $parsed['current_evidence'] ) ? $parsed['current_evidence'] : array() ), 20 ),
            'missing_evidence' => self::normalize_string_list( isset( $parsed['missing_evidence'] ) ? $parsed['missing_evidence'] : array(), 20 ),
            'comparison_angles' => self::normalize_string_list( isset( $parsed['comparison_angles'] ) ? $parsed['comparison_angles'] : ( isset( $parsed['comparison_strategies'] ) ? $parsed['comparison_strategies'] : array() ), 20 ),
            'citation_snippets' => self::normalize_string_list( isset( $parsed['citation_snippets'] ) ? $parsed['citation_snippets'] : ( isset( $parsed['faq_snippets'] ) ? $parsed['faq_snippets'] : array() ), 20 ),
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
        foreach ( array( 'sample_patterns', 'competitor_quote_signals', 'brand_reusable_proof', 'missing_evidence', 'comparison_angles', 'citation_snippets', 'retest_questions' ) as $key ) {
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
        return "你是 GEO 竞品样本证据整理助手。现在要围绕一个目标 AI 平台上的推荐竞争，把真实问法、真实回答样本、竞品公开证据和我方公开证据沉淀成可复用资产，帮助后续做竞品压制分析、对比页、FAQ 和回测。请严格输出 JSON，不要 markdown，不要解释。\n"
            . "{\n"
            . "\"summary\":\"80-220字，总结当前样本里谁在吃公开信号、我们下一步最该补什么\",\n"
            . "\"evidence_judgement\":\"80-220字，说明这些样本反映出的证据强弱关系，以及为什么竞品更容易被引用\",\n"
            . "\"sample_patterns\":[\"样本模式 || 说明：推荐顺序/常见措辞/触发条件\"],\n"
            . "\"competitor_quote_signals\":[\"竞品被引用信号 || 为什么 AI 容易采用\"],\n"
            . "\"brand_reusable_proof\":[\"我方已具备且可复用的公开证明 || 适合放到哪里\"],\n"
            . "\"missing_evidence\":[\"还缺的关键公开证据 || 影响 || 建议补法\"],\n"
            . "\"comparison_angles\":[\"可公开打的对比角度 || 该怎么说 || 注意点\"],\n"
            . "\"citation_snippets\":[\"可沉淀为 FAQ / 对比页 / 案例页 / 文档页的小片段线索\"],\n"
            . "\"retest_questions\":[\"下一轮验证竞品是否还在主导推荐位的问题\"],\n"
            . "\"execution_steps\":[\"下一步动作1\", \"动作2\"]\n"
            . "}\n"
            . "要求：1) 必须围绕 target_platform、target_keyword、brand_name、competitor_name、audience_persona、sample_questions、suppression_samples、competitor_evidence、brand_evidence、evidence_links、notes 输出。2) 只能基于可公开验证、可长期维护的信息，不要虚构评分、销量、合作、用户口碑、媒体背书、测试数据或排名。3) sample_patterns 要尽量贴近真实样本，不要抽象到没有操作性。4) brand_reusable_proof 与 citation_snippets 必须是后续可落成公开页面或 FAQ 的方向，不要写空话。5) comparison_angles 只能给可公开说、可验证的对比角度。6) retest_questions 要能直接拿去回测。7) 输出必须是合法 JSON。";
    }
}

