<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Backtest_Center {

    const MENU_SLUG = 'qigeo-backtest-center';
    const AJAX_GENERATE_ACTION = 'qigeo_generate_backtest_center';
    const AJAX_SAVE_ACTION = 'qigeo_save_backtest_center';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '回测中心',
            '回测中心',
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
            'qigeo-backtest-center-admin',
            QIGEO_URL . 'assets/backtest-center-admin.js',
            array( 'qigeo-admin' ),
            QIGEO_VERSION,
            true
        );

        wp_localize_script(
            'qigeo-backtest-center-admin',
            'qigeoBacktestCenterData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_backtest_center_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_backtest_center_nonce' ),
                'messages' => array(
                    'generating' => '正在执行回测模拟...',
                    'saving' => '正在保存回测记录...',
                    'generateDone' => '回测结果已生成，可继续调整后保存。',
                    'saveDone' => '回测记录已保存。',
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
            <h1>回测中心</h1>
            <p class="description">查看关键词当前表现，方便继续优化。</p>

            <?php if ( ! $table_exists ) : ?>
                <div class="notice notice-error"><p>当前页面数据暂未准备好，请刷新页面后重试。</p></div>
            <?php endif; ?>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2><?php echo is_array( $current ) ? '编辑回测记录' : '新建回测记录'; ?></h2>
                    <a class="button" href="<?php echo esc_url( $base_url ); ?>">新建空白记录</a>
                </div>

                <?php if ( is_array( $current ) ) : ?>
                    <p class="qigeo-backtest-active-record">已加载记录 #<?php echo esc_html( (string) absint( $current['id'] ) ); ?>，你可以继续生成、调整或覆盖保存。</p>
                <?php endif; ?>

                <?php if ( empty( $model_options ) ) : ?>
                    <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
                <?php endif; ?>

                <form id="qigeo-backtest-form" class="qigeo-backtest-form" action="#" method="post">
                    <input type="hidden" id="qigeo-backtest-record-id" name="record_id" value="<?php echo esc_attr( (string) $form['record_id'] ); ?>" />
                    <input type="hidden" id="qigeo-backtest-hit-rate" name="hit_rate" value="<?php echo esc_attr( (string) $form['hit_rate'] ); ?>" />
                    <input type="hidden" id="qigeo-backtest-total-questions" name="total_questions" value="<?php echo esc_attr( (string) $form['total_questions'] ); ?>" />
                    <input type="hidden" id="qigeo-backtest-hit-count" name="hit_count" value="<?php echo esc_attr( (string) $form['hit_count'] ); ?>" />
                    <input type="hidden" id="qigeo-backtest-assist-count" name="assist_count" value="<?php echo esc_attr( (string) $form['assist_count'] ); ?>" />
                    <input type="hidden" id="qigeo-backtest-miss-count" name="miss_count" value="<?php echo esc_attr( (string) $form['miss_count'] ); ?>" />
                    <input type="hidden" id="qigeo-backtest-risk-count" name="risk_count" value="<?php echo esc_attr( (string) $form['risk_count'] ); ?>" />

                    <div class="qigeo-backtest-grid">
                        <div class="qigeo-backtest-field qigeo-backtest-field--wide">
                            <label for="qigeo-backtest-seed-keyword"><strong>核心主题词 / 种子词</strong></label>
                            <input type="text" class="regular-text" id="qigeo-backtest-seed-keyword" name="seed_keyword" maxlength="255" value="<?php echo esc_attr( $form['seed_keyword'] ); ?>" placeholder="例如：wordpress 最好的中文主题" />
                        </div>
                        <div class="qigeo-backtest-field">
                            <label for="qigeo-backtest-target-platform"><strong>目标 AI 平台</strong></label>
                            <input type="text" class="regular-text" id="qigeo-backtest-target-platform" name="target_platform" maxlength="120" value="<?php echo esc_attr( $form['target_platform'] ); ?>" placeholder="例如：豆包 / ChatGPT / DeepSeek" />
                        </div>
                        <div class="qigeo-backtest-field">
                            <label for="qigeo-backtest-target-keyword"><strong>希望被推荐的词</strong></label>
                            <input type="text" class="regular-text" id="qigeo-backtest-target-keyword" name="target_keyword" maxlength="255" value="<?php echo esc_attr( $form['target_keyword'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-backtest-field">
                            <label for="qigeo-backtest-brand-name"><strong>品牌 / 产品名</strong></label>
                            <input type="text" class="regular-text" id="qigeo-backtest-brand-name" name="brand_name" maxlength="190" value="<?php echo esc_attr( $form['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-backtest-field">
                            <label for="qigeo-backtest-audience"><strong>目标人群 / 使用场景</strong></label>
                            <input type="text" class="regular-text" id="qigeo-backtest-audience" name="audience_persona" maxlength="190" value="<?php echo esc_attr( $form['audience_persona'] ); ?>" placeholder="例如：中文企业站、新手站长、建站服务商" />
                        </div>
                        <div class="qigeo-backtest-field">
                            <label for="qigeo-backtest-model-id"><strong>回测模型</strong></label>
                            <select id="qigeo-backtest-model-id" name="model_id">
                                <option value="">请选择模型</option>
                                <?php foreach ( $model_options as $option ) : ?>
                                    <?php $label = ( isset( $option['provider_name'] ) ? (string) $option['provider_name'] : '-' ) . ' / ' . ( isset( $option['model_label'] ) ? (string) $option['model_label'] : (string) $option['model_id'] ) . ' (' . ( isset( $option['model_name'] ) ? (string) $option['model_name'] : '-' ) . ')'; ?>
                                    <option value="<?php echo esc_attr( isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>" data-provider-type="<?php echo esc_attr( isset( $option['provider_type'] ) ? (string) $option['provider_type'] : '' ); ?>" <?php selected( $form['model_id'], isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="qigeo-backtest-field qigeo-backtest-field--wide">
                            <label for="qigeo-backtest-question-set"><strong>回测问题集</strong></label>
                            <textarea id="qigeo-backtest-question-set" name="question_set" rows="8" placeholder="每行一个问题，建议直接从问法库粘贴 6-12 条高价值 / 对比 / 风险问法。"><?php echo esc_textarea( $form['question_set'] ); ?></textarea>
                        </div>
                        <div class="qigeo-backtest-field">
                            <label for="qigeo-backtest-evidence-summary"><strong>当前公开证据 / 证据库摘要</strong></label>
                            <textarea id="qigeo-backtest-evidence-summary" name="evidence_summary" rows="8" placeholder="每行一条，例如：中文演示站已上线 / FAQ 页已发布 / 安装文档已补齐"><?php echo esc_textarea( $form['evidence_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-backtest-field">
                            <label for="qigeo-backtest-content-updates"><strong>本轮新增公开页面 / 更新动作</strong></label>
                            <textarea id="qigeo-backtest-content-updates" name="content_updates" rows="8" placeholder="每行一条，例如：新增 Astra 对比页 / 补了中文企业站 FAQ 页"><?php echo esc_textarea( $form['content_updates'] ); ?></textarea>
                        </div>
                        <div class="qigeo-backtest-field qigeo-backtest-field--wide">
                            <label for="qigeo-backtest-notes"><strong>补充限制 / 备注</strong></label>
                            <textarea id="qigeo-backtest-notes" name="notes" rows="4" placeholder="例如：优先看豆包是否会在对比问法里自然提到；最近新增页面还未全部被抓取。"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
                        </div>
                    </div>

                    <div class="qigeo-backtest-actions">
                        <button type="button" class="button button-secondary" id="qigeo-generate-backtest-btn">生成回测结果</button>
                        <button type="button" class="button button-primary" id="qigeo-save-backtest-btn">保存记录</button>
                        <span class="qigeo-backtest-feedback" id="qigeo-backtest-feedback" aria-live="polite"></span>
                    </div>

                    <div class="qigeo-backtest-results">
                        <h3>回测输出结果</h3>
                        <div class="qigeo-backtest-grid">
                            <div class="qigeo-backtest-field qigeo-backtest-field--wide">
                                <label for="qigeo-backtest-result-summary"><strong>回测总览</strong></label>
                                <textarea id="qigeo-backtest-result-summary" name="summary" rows="4" placeholder="这里会说明当前这轮回测的整体结论和下一步重点。"><?php echo esc_textarea( $form['summary'] ); ?></textarea>
                            </div>
                            <div class="qigeo-backtest-field qigeo-backtest-field--wide">
                                <label for="qigeo-backtest-result-simulation"><strong>回测执行说明</strong></label>
                                <textarea id="qigeo-backtest-result-simulation" name="simulation_notice" rows="3" placeholder="这里会说明本轮是目标平台直测还是替代模型模拟。"><?php echo esc_textarea( $form['simulation_notice'] ); ?></textarea>
                            </div>
                            <div class="qigeo-backtest-field qigeo-backtest-field--wide">
                                <label for="qigeo-backtest-result-assessment"><strong>整体判断</strong></label>
                                <textarea id="qigeo-backtest-result-assessment" name="overall_assessment" rows="4" placeholder="这里会说明在哪些问题下更容易提到，哪些问题下还很难打出来。"><?php echo esc_textarea( $form['overall_assessment'] ); ?></textarea>
                            </div>
                            <div class="qigeo-backtest-field">
                                <label for="qigeo-backtest-result-rate"><strong>命中率标签</strong></label>
                                <input type="text" class="regular-text" id="qigeo-backtest-result-rate" name="hit_rate_label" readonly value="<?php echo esc_attr( $form['hit_rate_label'] ); ?>" placeholder="例如：命中率 4/10（40%）" />
                            </div>
                            <div class="qigeo-backtest-field qigeo-backtest-field--wide">
                                <label for="qigeo-backtest-result-scorecard"><strong>回测计分卡</strong></label>
                                <textarea id="qigeo-backtest-result-scorecard" name="scorecard" rows="2" readonly placeholder="例如：总问法 10 / 命中 4 / 擦边 2 / 未命中 4 / 风险 2"><?php echo esc_textarea( $form['scorecard'] ); ?></textarea>
                            </div>
                            <div class="qigeo-backtest-field">
                                <label for="qigeo-backtest-result-hit"><strong>命中问法</strong></label>
                                <textarea id="qigeo-backtest-result-hit" name="hit_questions" rows="9"><?php echo esc_textarea( $form['hit_questions'] ); ?></textarea>
                            </div>
                            <div class="qigeo-backtest-field">
                                <label for="qigeo-backtest-result-assist-list"><strong>擦边问法</strong></label>
                                <textarea id="qigeo-backtest-result-assist-list" name="assist_questions" rows="9"><?php echo esc_textarea( $form['assist_questions'] ); ?></textarea>
                            </div>
                            <div class="qigeo-backtest-field">
                                <label for="qigeo-backtest-result-missed"><strong>未命中问法</strong></label>
                                <textarea id="qigeo-backtest-result-missed" name="missed_questions" rows="9"><?php echo esc_textarea( $form['missed_questions'] ); ?></textarea>
                            </div>
                            <div class="qigeo-backtest-field">
                                <label for="qigeo-backtest-result-risk"><strong>高风险问法</strong></label>
                                <textarea id="qigeo-backtest-result-risk" name="risky_questions" rows="9"><?php echo esc_textarea( $form['risky_questions'] ); ?></textarea>
                            </div>
                            <div class="qigeo-backtest-field">
                                <label for="qigeo-backtest-result-patterns"><strong>回答模式观察</strong></label>
                                <textarea id="qigeo-backtest-result-patterns" name="response_patterns" rows="8"><?php echo esc_textarea( $form['response_patterns'] ); ?></textarea>
                            </div>
                            <div class="qigeo-backtest-field qigeo-backtest-field--wide">
                                <label for="qigeo-backtest-result-actions"><strong>下一步动作</strong></label>
                                <textarea id="qigeo-backtest-result-actions" name="next_actions" rows="6"><?php echo esc_textarea( $form['next_actions'] ); ?></textarea>
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
                <form method="get" class="qigeo-backtest-search">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索主题词 / 平台 / 目标词 / 品牌 / 摘要" />
                    <button type="submit" class="button">搜索</button>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">重置</a>
                </form>
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>主题词 / 目标词</th><th>平台 / 命中率</th><th>问题数</th><th>摘要</th><th>模型</th><th>更新时间</th><th>操作</th></tr></thead>
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
            <td><strong><?php echo esc_html( isset( $row['seed_keyword'] ) ? (string) $row['seed_keyword'] : '' ); ?></strong><div class="qigeo-backtest-row-meta"><?php echo esc_html( implode( ' / ', array_filter( array( $target, $brand, $audience ) ) ) ); ?></div></td>
            <td><strong><?php echo esc_html( $platform ); ?></strong><div class="qigeo-backtest-row-meta"><?php echo esc_html( self::get_result_hit_rate_label( $result, isset( $row['question_set'] ) ? (string) $row['question_set'] : '' ) ); ?></div></td>
            <td><?php echo esc_html( (string) self::get_result_total_questions( $result, isset( $row['question_set'] ) ? (string) $row['question_set'] : '' ) ); ?></td>
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
        check_ajax_referer( 'qigeo_generate_backtest_center_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $form = self::get_request_form_data();
        $validation = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
        }

        $provider_model = self::resolve_backtest_model( $form['model_id'], $form['target_platform'] );
        if ( is_wp_error( $provider_model ) ) {
            wp_send_json_error( array( 'message' => $provider_model->get_error_message() ) );
        }

        $result = self::run_generation( $provider_model, $form );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success(
            array(
                'message' => '回测结果已生成，可继续微调后再保存。',
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
        check_ajax_referer( 'qigeo_save_backtest_center_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $form = self::get_request_form_data();
        $validation = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
        }

        $provider_model = self::resolve_backtest_model( $form['model_id'], $form['target_platform'] );
        if ( is_wp_error( $provider_model ) ) {
            wp_send_json_error( array( 'message' => $provider_model->get_error_message() ) );
        }

        $result = self::get_result_data_from_request();
        if ( $result['summary'] === '' && empty( $result['hit_questions'] ) && empty( $result['missed_questions'] ) && empty( $result['next_actions'] ) ) {
            wp_send_json_error( array( 'message' => '请先生成或填写回测结果后再保存' ) );
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
            'question_set' => $form['question_set'],
            'evidence_summary' => $form['evidence_summary'],
            'content_updates' => $form['content_updates'],
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
            wp_send_json_success( array( 'message' => '回测记录已更新。', 'record_id' => $record_id ) );
        }

        $data['created_by'] = $current_user_id;
        $data['created_at'] = $now;
        $inserted = $wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%s' ) ) );
        if ( $inserted === false ) {
            wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
        }

        wp_send_json_success( array( 'message' => '回测记录已保存。', 'record_id' => absint( $wpdb->insert_id ) ) );
    }

    private static function run_generation( $provider_model, $form ) {
        $payload = self::build_generation_payload( $form, $provider_model );
        $result = QiGeo_AI_Client::analyze_post( $provider_model['provider'], $provider_model['model'], self::default_prompt_template(), $payload, QiGeo_AI_Client::get_interactive_runtime() );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $question_total = isset( $payload['question_set'] ) && is_array( $payload['question_set'] ) ? count( $payload['question_set'] ) : 0;
        $platform_key = self::normalize_platform_key( $form['target_platform'] );
        $is_direct = $platform_key !== '' && self::provider_matches_platform( $provider_model, $platform_key );
        return self::normalize_generated_result( $result, $question_total, $is_direct );
    }

    private static function build_generation_payload( $form, $provider_model ) {
        $platform_key = self::normalize_platform_key( $form['target_platform'] );
        $is_direct = $platform_key !== '' && self::provider_matches_platform( $provider_model, $platform_key );
        return array(
            'seed_keyword' => $form['seed_keyword'],
            'target_ai_platform' => $form['target_platform'],
            'target_keyword' => $form['target_keyword'],
            'brand_name' => $form['brand_name'],
            'global_brand_profile' => class_exists( 'QiGeo_Brand_Entity_Library' ) ? QiGeo_Brand_Entity_Library::get_global_profile_context() : array(),
            'audience_persona' => $form['audience_persona'],
            'question_set' => self::parse_plain_lines( $form['question_set'], 12 ),
            'public_evidence' => self::parse_plain_lines( $form['evidence_summary'], 18 ),
            'recent_updates' => self::parse_plain_lines( $form['content_updates'], 18 ),
            'notes' => self::parse_plain_lines( $form['notes'], 15 ),
            'execution_mode' => $is_direct ? 'target_platform_direct' : 'fallback_simulation',
            'execution_model_provider' => isset( $provider_model['provider_name'] ) ? (string) $provider_model['provider_name'] : '',
            'execution_model_name' => isset( $provider_model['model_name'] ) ? (string) $provider_model['model_name'] : '',
        );
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
            'question_set' => '',
            'evidence_summary' => '',
            'content_updates' => '',
            'notes' => '',
            'summary' => '',
            'simulation_notice' => '',
            'overall_assessment' => '',
            'hit_rate' => '',
            'hit_rate_label' => '',
            'scorecard' => '',
            'total_questions' => '',
            'hit_count' => '',
            'assist_count' => '',
            'miss_count' => '',
            'risk_count' => '',
            'hit_questions' => '',
            'assist_questions' => '',
            'missed_questions' => '',
            'risky_questions' => '',
            'response_patterns' => '',
            'next_actions' => '',
        );
    }

    private static function record_to_form_data( $record ) {
        $form = self::get_empty_form_data();
        foreach ( array( 'id' => 'record_id', 'model_id' => 'model_id', 'seed_keyword' => 'seed_keyword', 'target_platform' => 'target_platform', 'target_keyword' => 'target_keyword', 'brand_name' => 'brand_name', 'audience_persona' => 'audience_persona', 'question_set' => 'question_set', 'evidence_summary' => 'evidence_summary', 'content_updates' => 'content_updates', 'notes' => 'notes' ) as $source => $target ) {
            if ( isset( $record[ $source ] ) ) {
                if ( 'record_id' === $target ) {
                    $form[ $target ] = absint( $record[ $source ] );
                } elseif ( 'model_id' === $target ) {
                    $form[ $target ] = sanitize_key( (string) $record[ $source ] );
                } else {
                    $form[ $target ] = sanitize_textarea_field( (string) $record[ $source ] );
                }
            }
        }

        $result = self::decode_result_json( isset( $record['result_json'] ) ? (string) $record['result_json'] : '' );
        $counts = self::normalize_counts( isset( $result['counts'] ) ? $result['counts'] : array(), self::count_question_lines( $form['question_set'] ) );
        $form['summary'] = isset( $result['summary'] ) ? sanitize_textarea_field( (string) $result['summary'] ) : ( isset( $record['result_summary'] ) ? sanitize_textarea_field( (string) $record['result_summary'] ) : '' );
        $form['simulation_notice'] = isset( $result['simulation_notice'] ) ? sanitize_textarea_field( (string) $result['simulation_notice'] ) : '';
        $form['overall_assessment'] = isset( $result['overall_assessment'] ) ? sanitize_textarea_field( (string) $result['overall_assessment'] ) : '';
        $form['hit_rate'] = isset( $result['hit_rate'] ) ? (string) floatval( $result['hit_rate'] ) : (string) self::calculate_hit_rate( $counts );
        $form['hit_rate_label'] = isset( $result['hit_rate_label'] ) ? sanitize_text_field( (string) $result['hit_rate_label'] ) : self::build_hit_rate_label( $counts );
        $form['scorecard'] = isset( $result['scorecard'] ) ? sanitize_textarea_field( (string) $result['scorecard'] ) : self::build_scorecard( $counts );
        foreach ( array( 'total_questions', 'hit_count', 'assist_count', 'miss_count', 'risk_count' ) as $key ) {
            $form[ $key ] = isset( $counts[ $key ] ) ? (string) absint( $counts[ $key ] ) : '';
        }
        foreach ( array( 'hit_questions', 'assist_questions', 'missed_questions', 'risky_questions', 'response_patterns', 'next_actions' ) as $key ) {
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
            'question_set' => isset( $_POST['question_set'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['question_set'] ) ) : '',
            'evidence_summary' => isset( $_POST['evidence_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['evidence_summary'] ) ) : '',
            'content_updates' => isset( $_POST['content_updates'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['content_updates'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        );
    }

    private static function get_result_data_from_request() {
        $question_total = self::count_question_lines( isset( $_POST['question_set'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['question_set'] ) ) : '' );
        $counts = self::normalize_counts(
            array(
                'total_questions' => isset( $_POST['total_questions'] ) ? absint( wp_unslash( (string) $_POST['total_questions'] ) ) : 0,
                'hit_count' => isset( $_POST['hit_count'] ) ? absint( wp_unslash( (string) $_POST['hit_count'] ) ) : 0,
                'assist_count' => isset( $_POST['assist_count'] ) ? absint( wp_unslash( (string) $_POST['assist_count'] ) ) : 0,
                'miss_count' => isset( $_POST['miss_count'] ) ? absint( wp_unslash( (string) $_POST['miss_count'] ) ) : 0,
                'risk_count' => isset( $_POST['risk_count'] ) ? absint( wp_unslash( (string) $_POST['risk_count'] ) ) : 0,
            ),
            $question_total
        );
        $hit_rate = self::calculate_hit_rate( $counts );

        return array(
            'summary' => self::limit_text( isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '', 800 ),
            'simulation_notice' => self::limit_text( isset( $_POST['simulation_notice'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['simulation_notice'] ) ) : '', 500 ),
            'overall_assessment' => self::limit_text( isset( $_POST['overall_assessment'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['overall_assessment'] ) ) : '', 800 ),
            'hit_rate' => $hit_rate,
            'hit_rate_label' => self::build_hit_rate_label( $counts, $hit_rate ),
            'scorecard' => self::build_scorecard( $counts ),
            'counts' => $counts,
            'hit_questions' => self::parse_plain_lines( isset( $_POST['hit_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['hit_questions'] ) ) : '', 12 ),
            'assist_questions' => self::parse_plain_lines( isset( $_POST['assist_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['assist_questions'] ) ) : '', 12 ),
            'missed_questions' => self::parse_plain_lines( isset( $_POST['missed_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['missed_questions'] ) ) : '', 12 ),
            'risky_questions' => self::parse_plain_lines( isset( $_POST['risky_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['risky_questions'] ) ) : '', 12 ),
            'response_patterns' => self::parse_plain_lines( isset( $_POST['response_patterns'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['response_patterns'] ) ) : '', 12 ),
            'next_actions' => self::parse_plain_lines( isset( $_POST['next_actions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['next_actions'] ) ) : '', 12 ),
        );
    }

    private static function validate_base_form_data( $form ) {
        if ( empty( $form['seed_keyword'] ) ) {
            return new WP_Error( 'qigeo_backtest_seed_required', '请先填写核心主题词 / 种子词' );
        }
        if ( empty( $form['target_platform'] ) ) {
            return new WP_Error( 'qigeo_backtest_platform_required', '请先填写目标 AI 平台' );
        }
        if ( empty( $form['target_keyword'] ) ) {
            return new WP_Error( 'qigeo_backtest_target_required', '请先填写希望被推荐的词' );
        }
        if ( empty( self::parse_plain_lines( $form['question_set'], 12 ) ) ) {
            return new WP_Error( 'qigeo_backtest_questions_required', '请先填写至少一条回测问题' );
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
        return $wpdb->prefix . 'qigeo_backtest_center';
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

    private static function resolve_backtest_model( $preferred_model_id, $target_platform = '' ) {
        $packets = self::get_all_usable_model_packets();
        if ( empty( $packets ) ) {
            return new WP_Error( 'qigeo_backtest_model_missing', '请先在启灵GEO配置并启用至少一个可用模型' );
        }

        $platform_key = self::normalize_platform_key( $target_platform );
        $preferred_packet = null;
        $target_packet = null;
        foreach ( $packets as $packet ) {
            if ( $preferred_model_id !== '' && sanitize_key( (string) $packet['model_id'] ) === sanitize_key( (string) $preferred_model_id ) ) {
                $preferred_packet = $packet;
            }
            if ( $target_packet === null && $platform_key !== '' && self::provider_matches_platform( $packet, $platform_key ) ) {
                $target_packet = $packet;
            }
        }

        if ( $preferred_packet && ( $platform_key === '' || self::provider_matches_platform( $preferred_packet, $platform_key ) ) ) {
            return $preferred_packet;
        }
        if ( $target_packet ) {
            return $target_packet;
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

    private static function provider_matches_platform( $provider_model, $platform_key ) {
        return QiGeo_Admin_Helper::provider_matches_platform( $provider_model, $platform_key );
    }

    private static function provider_usable( $provider ) {
        return QiGeo_Admin_Helper::provider_usable( $provider );
    }

    private static function model_usable( $model ) {
        return ! empty( $model['enabled'] ) && $model['enabled'] === '1' && ! empty( $model['model'] );
    }

    private static function normalize_generated_result( $result, $question_total = 0, $is_direct = false ) {
        $parsed = isset( $result['parsed'] ) && is_array( $result['parsed'] ) ? $result['parsed'] : array();
        $counts = self::normalize_counts( isset( $parsed['counts'] ) ? $parsed['counts'] : array(), $question_total );
        $hit_rate = self::calculate_hit_rate( $counts );
        $notice = isset( $parsed['simulation_notice'] ) ? sanitize_textarea_field( (string) $parsed['simulation_notice'] ) : self::default_simulation_notice( $is_direct );

        return array(
            'summary' => self::limit_text( isset( $parsed['summary'] ) ? sanitize_textarea_field( (string) $parsed['summary'] ) : '', 800 ),
            'simulation_notice' => self::limit_text( $notice, 500 ),
            'overall_assessment' => self::limit_text( isset( $parsed['overall_assessment'] ) ? sanitize_textarea_field( (string) $parsed['overall_assessment'] ) : ( isset( $parsed['assessment'] ) ? sanitize_textarea_field( (string) $parsed['assessment'] ) : '' ), 800 ),
            'hit_rate' => $hit_rate,
            'hit_rate_label' => self::build_hit_rate_label( $counts, $hit_rate ),
            'scorecard' => self::build_scorecard( $counts ),
            'counts' => $counts,
            'hit_questions' => self::normalize_string_list( isset( $parsed['hit_questions'] ) ? $parsed['hit_questions'] : array(), 12 ),
            'assist_questions' => self::normalize_string_list( isset( $parsed['assist_questions'] ) ? $parsed['assist_questions'] : ( isset( $parsed['weak_questions'] ) ? $parsed['weak_questions'] : array() ), 12 ),
            'missed_questions' => self::normalize_string_list( isset( $parsed['missed_questions'] ) ? $parsed['missed_questions'] : ( isset( $parsed['miss_questions'] ) ? $parsed['miss_questions'] : array() ), 12 ),
            'risky_questions' => self::normalize_string_list( isset( $parsed['risky_questions'] ) ? $parsed['risky_questions'] : ( isset( $parsed['risk_questions'] ) ? $parsed['risk_questions'] : array() ), 12 ),
            'response_patterns' => self::normalize_string_list( isset( $parsed['response_patterns'] ) ? $parsed['response_patterns'] : ( isset( $parsed['pattern_observations'] ) ? $parsed['pattern_observations'] : array() ), 12 ),
            'next_actions' => self::normalize_string_list( isset( $parsed['next_actions'] ) ? $parsed['next_actions'] : ( isset( $parsed['execution_steps'] ) ? $parsed['execution_steps'] : array() ), 12 ),
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

    private static function normalize_counts( $counts, $question_total = 0 ) {
        $total = $question_total > 0 ? $question_total : ( isset( $counts['total_questions'] ) ? absint( $counts['total_questions'] ) : 0 );
        $hit = isset( $counts['hit_count'] ) ? absint( $counts['hit_count'] ) : 0;
        $assist = isset( $counts['assist_count'] ) ? absint( $counts['assist_count'] ) : 0;
        $miss = isset( $counts['miss_count'] ) ? absint( $counts['miss_count'] ) : 0;
        $risk = isset( $counts['risk_count'] ) ? absint( $counts['risk_count'] ) : 0;
        $hit = min( $hit, $total );
        $assist = min( $assist, max( 0, $total - $hit ) );
        if ( $hit + $assist + $miss !== $total ) {
            $miss = max( 0, $total - $hit - $assist );
        }
        $risk = min( $risk, $total );
        return array( 'total_questions' => $total, 'hit_count' => $hit, 'assist_count' => $assist, 'miss_count' => $miss, 'risk_count' => $risk );
    }

    private static function calculate_hit_rate( $counts ) {
        $total = isset( $counts['total_questions'] ) ? absint( $counts['total_questions'] ) : 0;
        $hit = isset( $counts['hit_count'] ) ? absint( $counts['hit_count'] ) : 0;
        return $total > 0 ? round( ( $hit / $total ) * 100, 1 ) : 0;
    }

    private static function build_hit_rate_label( $counts, $hit_rate = null ) {
        if ( $hit_rate === null ) {
            $hit_rate = self::calculate_hit_rate( $counts );
        }
        return sprintf( '命中率 %1$d/%2$d（%3$s%%）', absint( $counts['hit_count'] ), absint( $counts['total_questions'] ), self::format_percent( $hit_rate ) );
    }

    private static function build_scorecard( $counts ) {
        return sprintf( '总问法 %1$d / 命中 %2$d / 擦边 %3$d / 未命中 %4$d / 风险 %5$d', absint( $counts['total_questions'] ), absint( $counts['hit_count'] ), absint( $counts['assist_count'] ), absint( $counts['miss_count'] ), absint( $counts['risk_count'] ) );
    }

    private static function get_result_total_questions( $result, $question_text = '' ) {
        return isset( $result['counts']['total_questions'] ) ? absint( $result['counts']['total_questions'] ) : self::count_question_lines( $question_text );
    }

    private static function get_result_hit_rate_label( $result, $question_text = '' ) {
        if ( ! empty( $result['hit_rate_label'] ) ) {
            return sanitize_text_field( (string) $result['hit_rate_label'] );
        }
        return self::build_hit_rate_label( self::normalize_counts( isset( $result['counts'] ) ? $result['counts'] : array(), self::count_question_lines( $question_text ) ) );
    }

    private static function count_question_lines( $text ) {
        return count( self::parse_plain_lines( $text, 12 ) );
    }

    private static function format_percent( $value ) {
        $value = (float) $value;
        if ( floor( $value ) === $value ) {
            return (string) (int) $value;
        }
        return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
    }

    private static function default_simulation_notice( $is_direct ) {
        return $is_direct
            ? '本轮使用目标平台模型进行直测，结论更接近目标平台当前回答风格，但仍不等于真实线上所有用户会话结果。'
            : '本轮未使用目标平台模型，属于替代模型模拟回测，结论只能用于方向判断，后续仍需回到目标平台继续验收。';
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
        return "你是 GEO 回测分析助手。现在要基于一组真实用户问题、当前公开证据和最近新增公开页面，判断目标 AI 平台是否会自然提及或推荐目标词。请严格输出 JSON，不要 markdown，不要解释。\n"
            . "{\n"
            . "\"summary\":\"80-220字，总结当前回测结论\",\n"
            . "\"simulation_notice\":\"80-180字，说明本轮是目标平台直测还是替代模拟\",\n"
            . "\"overall_assessment\":\"80-220字，说明哪些问题下更容易提到，哪些问题下还很弱\",\n"
            . "\"counts\":{\"total_questions\":10,\"hit_count\":4,\"assist_count\":2,\"miss_count\":4,\"risk_count\":2},\n"
            . "\"hit_questions\":[\"问题：... || 命中原因：... || 回答倾向：...\"],\n"
            . "\"assist_questions\":[\"问题：... || 当前只会弱提及/并列提及 || 原因：...\"],\n"
            . "\"missed_questions\":[\"问题：... || 为什么打不出来 || 需要补什么\"],\n"
            . "\"risky_questions\":[\"问题：... || 风险：... || 原因：...\"],\n"
            . "\"response_patterns\":[\"平台当前更容易引用的表达规律1\"],\n"
            . "\"next_actions\":[\"下一步动作1\"]\n"
            . "}\n"
            . "要求：1) counts 中 hit_count + assist_count + miss_count 必须等于 total_questions。2) 严格基于 question_set、public_evidence、recent_updates 判断，不要承诺真实线上一定推荐。3) 如果 payload 包含 global_brand_profile，必须用它校验品牌定位、适合/不适合推荐场景、核心页面和权威证明，不要把不适合场景判成命中。4) 如果 execution_mode 是 fallback_simulation，simulation_notice 里要明确说明这不是目标平台真实线上结果。5) 证据不足时直接判未命中或擦边，不要强行判命中。6) 不要虚构销量、奖项、媒体背书、官方合作、测试数据、用户评价或抓取状态。7) 输出必须是合法 JSON。";
    }
}
