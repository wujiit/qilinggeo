<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Platform_Backtest_Records {

    const MENU_SLUG = 'qigeo-platform-backtest-records';
    const AJAX_GENERATE_ACTION = 'qigeo_generate_platform_backtest_records';
    const AJAX_SAVE_ACTION = 'qigeo_save_platform_backtest_records';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '平台回测记录',
            '平台回测记录',
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
            'qigeo-platform-backtest-admin',
            QIGEO_URL . 'assets/platform-backtest-admin.js',
            array( 'qigeo-admin' ),
            QIGEO_VERSION,
            true
        );

        wp_localize_script(
            'qigeo-platform-backtest-admin',
            'qigeoPlatformBacktestRecordData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_platform_backtest_records_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_platform_backtest_records_nonce' ),
                'messages' => array(
                    'generating' => '正在分析平台回测记录...',
                    'saving' => '正在保存平台回测记录...',
                    'generateDone' => '平台回测诊断已生成，可继续微调后再保存。',
                    'saveDone' => '平台回测记录已保存。',
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
            <h1>平台回测记录</h1>
            <p class="description">记录不同平台的问答结果。</p>

            <?php if ( ! $table_exists ) : ?>
                <div class="notice notice-error"><p>当前页面数据暂未准备好，请刷新页面后重试。</p></div>
            <?php endif; ?>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2><?php echo is_array( $current ) ? '编辑平台回测记录' : '新建平台回测记录'; ?></h2>
                    <a class="button" href="<?php echo esc_url( $base_url ); ?>">新建空白记录</a>
                </div>

                <?php if ( is_array( $current ) ) : ?>
                    <p class="qigeo-platform-log-active-record">已加载记录 #<?php echo esc_html( (string) absint( $current['id'] ) ); ?>，你可以继续诊断、调整或覆盖保存。</p>
                <?php endif; ?>

                <?php if ( empty( $model_options ) ) : ?>
                    <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
                <?php endif; ?>

                <form id="qigeo-platform-log-form" class="qigeo-platform-log-form" action="#" method="post">
                    <input type="hidden" id="qigeo-platform-log-record-id" name="record_id" value="<?php echo esc_attr( (string) $form['record_id'] ); ?>" />

                    <div class="qigeo-platform-log-grid">
                        <div class="qigeo-platform-log-field">
                            <label for="qigeo-platform-log-target-platform"><strong>目标 AI 平台</strong></label>
                            <input type="text" class="regular-text" id="qigeo-platform-log-target-platform" name="target_platform" maxlength="120" value="<?php echo esc_attr( $form['target_platform'] ); ?>" placeholder="例如：豆包 / ChatGPT / DeepSeek" />
                        </div>
                        <div class="qigeo-platform-log-field">
                            <label for="qigeo-platform-log-target-keyword"><strong>目标推荐词</strong></label>
                            <input type="text" class="regular-text" id="qigeo-platform-log-target-keyword" name="target_keyword" maxlength="255" value="<?php echo esc_attr( $form['target_keyword'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-platform-log-field">
                            <label for="qigeo-platform-log-brand-name"><strong>品牌 / 产品名</strong></label>
                            <input type="text" class="regular-text" id="qigeo-platform-log-brand-name" name="brand_name" maxlength="190" value="<?php echo esc_attr( $form['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-platform-log-field">
                            <label for="qigeo-platform-log-audience"><strong>目标人群 / 场景</strong></label>
                            <input type="text" class="regular-text" id="qigeo-platform-log-audience" name="audience_persona" maxlength="190" value="<?php echo esc_attr( $form['audience_persona'] ); ?>" placeholder="例如：中文企业站、新手站长、建站服务商" />
                        </div>
                        <div class="qigeo-platform-log-field">
                            <label for="qigeo-platform-log-mention-status"><strong>本次观察状态</strong></label>
                            <select id="qigeo-platform-log-mention-status" name="mention_status">
                                <option value="">请选择状态</option>
                                <option value="recommended" <?php selected( $form['mention_status'], 'recommended' ); ?>>明确推荐</option>
                                <option value="mentioned" <?php selected( $form['mention_status'], 'mentioned' ); ?>>有提及但不强</option>
                                <option value="partial" <?php selected( $form['mention_status'], 'partial' ); ?>>弱相关擦边</option>
                                <option value="absent" <?php selected( $form['mention_status'], 'absent' ); ?>>完全未提及</option>
                                <option value="competitor_led" <?php selected( $form['mention_status'], 'competitor_led' ); ?>>被竞品主导</option>
                                <option value="hallucination_risk" <?php selected( $form['mention_status'], 'hallucination_risk' ); ?>>存在幻觉风险</option>
                            </select>
                        </div>
                    <div class="qigeo-platform-log-field">
                        <label for="qigeo-platform-log-model-id"><strong>优先诊断模型</strong></label>
                            <select id="qigeo-platform-log-model-id" name="model_id">
                                <option value="">请选择模型</option>
                                <?php foreach ( $model_options as $option ) : ?>
                                    <?php $label = ( isset( $option['provider_name'] ) ? (string) $option['provider_name'] : '-' ) . ' / ' . ( isset( $option['model_label'] ) ? (string) $option['model_label'] : (string) $option['model_id'] ) . ' (' . ( isset( $option['model_name'] ) ? (string) $option['model_name'] : '-' ) . ')'; ?>
                                    <option value="<?php echo esc_attr( isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>" data-provider-type="<?php echo esc_attr( isset( $option['provider_type'] ) ? (string) $option['provider_type'] : '' ); ?>" <?php selected( $form['model_id'], isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">不选也可以，系统会自动处理。</p>
                        </div>
                        <div class="qigeo-platform-log-field qigeo-platform-log-field--wide">
                            <label for="qigeo-platform-log-test-question"><strong>真实测试问题</strong></label>
                            <textarea id="qigeo-platform-log-test-question" name="test_question" rows="5" placeholder="粘贴你真实问平台的问题，例如：wordpress 最好的中文主题是什么？"><?php echo esc_textarea( $form['test_question'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-log-field qigeo-platform-log-field--wide">
                            <label for="qigeo-platform-log-expected-direction"><strong>你希望平台往哪个方向回答</strong></label>
                            <textarea id="qigeo-platform-log-expected-direction" name="expected_direction" rows="4" placeholder="例如：希望平台在‘中文 WordPress 主题’语境里优先提到启灵主题，并给出中文场景理由。"><?php echo esc_textarea( $form['expected_direction'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-log-field qigeo-platform-log-field--wide">
                            <label for="qigeo-platform-log-response-excerpt"><strong>平台真实回答</strong></label>
                            <textarea id="qigeo-platform-log-response-excerpt" name="response_excerpt" rows="10" placeholder="尽量粘贴完整回答，至少保留提及顺序、推荐理由、竞品名称和结论段。"><?php echo esc_textarea( $form['response_excerpt'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-log-field qigeo-platform-log-field--wide">
                            <label for="qigeo-platform-log-response-source"><strong>来源备注 / 链接 / 截图线索</strong></label>
                            <input type="text" class="regular-text" id="qigeo-platform-log-response-source" name="response_source" maxlength="400" value="<?php echo esc_attr( $form['response_source'] ); ?>" placeholder="例如：豆包对话链接、截图文件名、测试日期批次号等" />
                        </div>
                        <div class="qigeo-platform-log-field">
                            <label for="qigeo-platform-log-observed-competitors"><strong>回答中出现的竞品 / 替代项</strong></label>
                            <textarea id="qigeo-platform-log-observed-competitors" name="observed_competitors" rows="7" placeholder="每行一个，或直接贴回答里被优先推荐的竞品名称。"><?php echo esc_textarea( $form['observed_competitors'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-log-field">
                            <label for="qigeo-platform-log-asset-context"><strong>当前资产上下文</strong></label>
                            <textarea id="qigeo-platform-log-asset-context" name="asset_context" rows="7" placeholder="例如：本轮前已发哪些专题页、FAQ、对比页、案例页、更新日志、外部分发内容。"><?php echo esc_textarea( $form['asset_context'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-log-field">
                            <label for="qigeo-platform-log-adaptation-context"><strong>当前适配策略上下文</strong></label>
                            <textarea id="qigeo-platform-log-adaptation-context" name="adaptation_context" rows="7" placeholder="例如：平台适配中心当前建议的回答风格、证据偏好、实体表达和高适配问法。"><?php echo esc_textarea( $form['adaptation_context'] ); ?></textarea>
                        </div>
                        <div class="qigeo-platform-log-field qigeo-platform-log-field--wide">
                            <label for="qigeo-platform-log-notes"><strong>补充备注</strong></label>
                            <textarea id="qigeo-platform-log-notes" name="notes" rows="4" placeholder="例如：这是改版前第 3 次测试；这轮问题更偏购买建议；这次平台明显偏爱竞品榜单表达。"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
                        </div>
                    </div>

                    <div class="qigeo-platform-log-actions">
                        <button type="button" class="button button-secondary" id="qigeo-generate-platform-log-btn">分析回测记录</button>
                        <button type="button" class="button button-primary" id="qigeo-save-platform-log-btn">保存记录</button>
                        <span class="qigeo-platform-log-feedback" id="qigeo-platform-log-feedback" aria-live="polite"></span>
                    </div>

                    <div class="qigeo-platform-log-results">
                        <h3>回测诊断结果</h3>
                        <div class="qigeo-platform-log-grid">
                            <div class="qigeo-platform-log-field qigeo-platform-log-field--wide">
                                <label for="qigeo-platform-log-result-summary"><strong>诊断总览</strong></label>
                                <textarea id="qigeo-platform-log-result-summary" name="summary" rows="4"><?php echo esc_textarea( $form['summary'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-log-field qigeo-platform-log-field--wide">
                                <label for="qigeo-platform-log-result-mention"><strong>提及判断</strong></label>
                                <textarea id="qigeo-platform-log-result-mention" name="mention_judgement" rows="4"><?php echo esc_textarea( $form['mention_judgement'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-log-field">
                                <label for="qigeo-platform-log-result-winning"><strong>有效信号</strong></label>
                                <textarea id="qigeo-platform-log-result-winning" name="winning_fragments" rows="9"><?php echo esc_textarea( $form['winning_fragments'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-log-field">
                                <label for="qigeo-platform-log-result-missing"><strong>缺失信号</strong></label>
                                <textarea id="qigeo-platform-log-result-missing" name="missing_signals" rows="9"><?php echo esc_textarea( $form['missing_signals'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-log-field">
                                <label for="qigeo-platform-log-result-risk"><strong>风险信号</strong></label>
                                <textarea id="qigeo-platform-log-result-risk" name="risk_signals" rows="9"><?php echo esc_textarea( $form['risk_signals'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-log-field">
                                <label for="qigeo-platform-log-result-assets"><strong>应补资产</strong></label>
                                <textarea id="qigeo-platform-log-result-assets" name="asset_updates" rows="9"><?php echo esc_textarea( $form['asset_updates'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-log-field">
                                <label for="qigeo-platform-log-result-adaptation"><strong>应改适配策略</strong></label>
                                <textarea id="qigeo-platform-log-result-adaptation" name="adaptation_updates" rows="9"><?php echo esc_textarea( $form['adaptation_updates'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-log-field">
                                <label for="qigeo-platform-log-result-next-questions"><strong>下一轮问题</strong></label>
                                <textarea id="qigeo-platform-log-result-next-questions" name="next_questions" rows="9"><?php echo esc_textarea( $form['next_questions'] ); ?></textarea>
                            </div>
                            <div class="qigeo-platform-log-field qigeo-platform-log-field--wide">
                                <label for="qigeo-platform-log-result-steps"><strong>执行顺序</strong></label>
                                <textarea id="qigeo-platform-log-result-steps" name="execution_steps" rows="6"><?php echo esc_textarea( $form['execution_steps'] ); ?></textarea>
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
                <form method="get" class="qigeo-platform-log-search">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索平台 / 目标词 / 品牌 / 状态 / 摘要" />
                    <button type="submit" class="button">搜索</button>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">重置</a>
                </form>
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>平台 / 目标词</th><th>观察状态</th><th>诊断项数</th><th>摘要</th><th>模型</th><th>更新时间</th><th>操作</th></tr></thead>
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
                <div class="qigeo-platform-log-row-meta"><?php echo esc_html( implode( ' / ', array_filter( array( isset( $row['target_keyword'] ) ? (string) $row['target_keyword'] : '', isset( $row['brand_name'] ) ? (string) $row['brand_name'] : '' ) ) ) ); ?></div>
            </td>
            <td>
                <strong><?php echo esc_html( self::humanize_mention_status( isset( $row['mention_status'] ) ? (string) $row['mention_status'] : '' ) ); ?></strong>
                <div class="qigeo-platform-log-row-meta"><?php echo esc_html( isset( $row['audience_persona'] ) ? (string) $row['audience_persona'] : '' ); ?></div>
            </td>
            <td><?php echo esc_html( (string) self::count_diagnosis_items( $result ) ); ?></td>
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
        check_ajax_referer( 'qigeo_generate_platform_backtest_records_nonce', 'nonce' );

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
                'message' => '平台回测诊断已生成，可继续微调后再保存。',
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
        check_ajax_referer( 'qigeo_save_platform_backtest_records_nonce', 'nonce' );

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
        if ( $result['summary'] === '' && empty( $result['winning_fragments'] ) && empty( $result['missing_signals'] ) && empty( $result['execution_steps'] ) ) {
            wp_send_json_error( array( 'message' => '请先生成或填写平台回测诊断结果后再保存' ) );
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
            'test_question' => $form['test_question'],
            'expected_direction' => $form['expected_direction'],
            'response_excerpt' => $form['response_excerpt'],
            'response_source' => $form['response_source'],
            'mention_status' => $form['mention_status'],
            'observed_competitors' => $form['observed_competitors'],
            'asset_context' => $form['asset_context'],
            'adaptation_context' => $form['adaptation_context'],
            'notes' => $form['notes'],
            'provider_id' => isset( $provider_model['provider_id'] ) ? (string) $provider_model['provider_id'] : '',
            'model_id' => isset( $provider_model['model_id'] ) ? (string) $provider_model['model_id'] : '',
            'model' => isset( $provider_model['model_name'] ) ? (string) $provider_model['model_name'] : '',
            'result_summary' => $result['summary'],
            'result_json' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'updated_at' => $now,
        );
        $formats = array_fill( 0, 19, '%s' );

        if ( $record_id > 0 && self::get_item_by_id( $record_id ) ) {
            $updated = $wpdb->update( $table, $data, array( 'id' => $record_id ), $formats, array( '%d' ) );
            if ( $updated === false ) {
                wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
            }
            wp_send_json_success( array( 'message' => '平台回测记录已更新。', 'record_id' => $record_id ) );
        }

        $data['created_by'] = $current_user_id;
        $data['created_at'] = $now;
        $inserted = $wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%s' ) ) );
        if ( $inserted === false ) {
            wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
        }

        wp_send_json_success( array( 'message' => '平台回测记录已保存。', 'record_id' => absint( $wpdb->insert_id ) ) );
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
            'test_question' => $form['test_question'],
            'expected_direction' => $form['expected_direction'],
            'response_excerpt' => $form['response_excerpt'],
            'response_source' => $form['response_source'],
            'mention_status' => $form['mention_status'],
            'observed_competitors' => $form['observed_competitors'],
            'asset_context' => $form['asset_context'],
            'adaptation_context' => $form['adaptation_context'],
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
            'mention_status' => '',
            'test_question' => '',
            'expected_direction' => '',
            'response_excerpt' => '',
            'response_source' => '',
            'observed_competitors' => '',
            'asset_context' => '',
            'adaptation_context' => '',
            'notes' => '',
            'summary' => '',
            'mention_judgement' => '',
            'winning_fragments' => '',
            'missing_signals' => '',
            'risk_signals' => '',
            'asset_updates' => '',
            'adaptation_updates' => '',
            'next_questions' => '',
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
                'mention_status' => 'mention_status',
                'test_question' => 'test_question',
                'expected_direction' => 'expected_direction',
                'response_excerpt' => 'response_excerpt',
                'response_source' => 'response_source',
                'observed_competitors' => 'observed_competitors',
                'asset_context' => 'asset_context',
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
        $form['mention_judgement'] = isset( $result['mention_judgement'] ) ? sanitize_textarea_field( (string) $result['mention_judgement'] ) : '';
        foreach ( array( 'winning_fragments', 'missing_signals', 'risk_signals', 'asset_updates', 'adaptation_updates', 'next_questions', 'execution_steps' ) as $key ) {
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
            'mention_status' => isset( $_POST['mention_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['mention_status'] ) ) : '',
            'test_question' => isset( $_POST['test_question'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['test_question'] ) ) : '',
            'expected_direction' => isset( $_POST['expected_direction'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['expected_direction'] ) ) : '',
            'response_excerpt' => isset( $_POST['response_excerpt'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['response_excerpt'] ) ) : '',
            'response_source' => self::limit_text( isset( $_POST['response_source'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['response_source'] ) ) : '', 400 ),
            'observed_competitors' => isset( $_POST['observed_competitors'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['observed_competitors'] ) ) : '',
            'asset_context' => isset( $_POST['asset_context'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['asset_context'] ) ) : '',
            'adaptation_context' => isset( $_POST['adaptation_context'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['adaptation_context'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        );
    }

    private static function get_result_data_from_request() {
        return array(
            'summary' => self::limit_text( isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '', 800 ),
            'mention_judgement' => self::limit_text( isset( $_POST['mention_judgement'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['mention_judgement'] ) ) : '', 800 ),
            'winning_fragments' => self::parse_plain_lines( isset( $_POST['winning_fragments'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['winning_fragments'] ) ) : '', 20 ),
            'missing_signals' => self::parse_plain_lines( isset( $_POST['missing_signals'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['missing_signals'] ) ) : '', 20 ),
            'risk_signals' => self::parse_plain_lines( isset( $_POST['risk_signals'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['risk_signals'] ) ) : '', 20 ),
            'asset_updates' => self::parse_plain_lines( isset( $_POST['asset_updates'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['asset_updates'] ) ) : '', 20 ),
            'adaptation_updates' => self::parse_plain_lines( isset( $_POST['adaptation_updates'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['adaptation_updates'] ) ) : '', 20 ),
            'next_questions' => self::parse_plain_lines( isset( $_POST['next_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['next_questions'] ) ) : '', 20 ),
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
        if ( empty( self::parse_plain_lines( $form['test_question'], 1 ) ) ) {
            return new WP_Error( 'qigeo_platform_test_question_required', '请先填写真实测试问题' );
        }
        if ( empty( self::parse_plain_lines( $form['response_excerpt'], 1 ) ) ) {
            return new WP_Error( 'qigeo_platform_response_excerpt_required', '请先填写平台真实回答' );
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
            $where .= ' AND (target_platform LIKE %s OR target_keyword LIKE %s OR brand_name LIKE %s OR mention_status LIKE %s OR result_summary LIKE %s)';
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
        return $wpdb->prefix . 'qigeo_platform_backtest_records';
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
            'mention_judgement' => self::limit_text( isset( $parsed['mention_judgement'] ) ? sanitize_textarea_field( (string) $parsed['mention_judgement'] ) : ( isset( $parsed['mention_status_analysis'] ) ? sanitize_textarea_field( (string) $parsed['mention_status_analysis'] ) : '' ), 800 ),
            'winning_fragments' => self::normalize_string_list( isset( $parsed['winning_fragments'] ) ? $parsed['winning_fragments'] : ( isset( $parsed['effective_signals'] ) ? $parsed['effective_signals'] : array() ), 20 ),
            'missing_signals' => self::normalize_string_list( isset( $parsed['missing_signals'] ) ? $parsed['missing_signals'] : array(), 20 ),
            'risk_signals' => self::normalize_string_list( isset( $parsed['risk_signals'] ) ? $parsed['risk_signals'] : ( isset( $parsed['risk_flags'] ) ? $parsed['risk_flags'] : ( isset( $parsed['risks'] ) ? $parsed['risks'] : array() ) ), 20 ),
            'asset_updates' => self::normalize_string_list( isset( $parsed['asset_updates'] ) ? $parsed['asset_updates'] : ( isset( $parsed['content_asset_updates'] ) ? $parsed['content_asset_updates'] : array() ), 20 ),
            'adaptation_updates' => self::normalize_string_list( isset( $parsed['adaptation_updates'] ) ? $parsed['adaptation_updates'] : ( isset( $parsed['platform_adaptation_updates'] ) ? $parsed['platform_adaptation_updates'] : array() ), 20 ),
            'next_questions' => self::normalize_string_list( isset( $parsed['next_questions'] ) ? $parsed['next_questions'] : ( isset( $parsed['retest_prompts'] ) ? $parsed['retest_prompts'] : ( isset( $parsed['test_prompts'] ) ? $parsed['test_prompts'] : array() ) ), 15 ),
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

    private static function count_diagnosis_items( $result ) {
        $count = 0;
        foreach ( array( 'winning_fragments', 'missing_signals', 'risk_signals', 'asset_updates', 'adaptation_updates', 'next_questions' ) as $key ) {
            if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) ) {
                $count += count( $result[ $key ] );
            }
        }

        return $count;
    }

    private static function humanize_mention_status( $status ) {
        switch ( sanitize_key( (string) $status ) ) {
            case 'recommended':
                return '明确推荐';
            case 'mentioned':
                return '有提及但不强';
            case 'partial':
                return '弱相关擦边';
            case 'absent':
                return '完全未提及';
            case 'competitor_led':
                return '被竞品主导';
            case 'hallucination_risk':
                return '存在幻觉风险';
            default:
                return '未标注';
        }
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
        return "你是 GEO 平台回测诊断助手。现在要基于一次真实平台问答记录，判断目标推荐词/品牌在这次回答中的出现情况、推荐强度、缺失信号、风险信号，以及下一轮应补的公开资产、平台适配策略和回测问题。请严格输出 JSON，不要 markdown，不要解释。\n"
            . "{\n"
            . "\"summary\":\"80-220字，总结这次回测结果和最关键原因\",\n"
            . "\"mention_judgement\":\"明确推荐/弱提及/未提及/被竞品主导/存在幻觉风险，并说明原因\",\n"
            . "\"winning_fragments\":[\"回答里有效的片段或信号 || 为什么有用\"],\n"
            . "\"missing_signals\":[\"缺失的证据/实体/场景/对比信号 || 影响\"],\n"
            . "\"risk_signals\":[\"误解/竞品压制/幻觉/夸大风险 || 原因\"],\n"
            . "\"asset_updates\":[\"应该补的页面、FAQ、案例、对比页、文档或外部分发资产 || 目的\"],\n"
            . "\"adaptation_updates\":[\"平台适配中心应该修改的表达规则/证据偏好/问法方向\"],\n"
            . "\"next_questions\":[\"下一轮用于同平台回测的提示词\"],\n"
            . "\"execution_steps\":[\"下一步动作1\", \"动作2\"]\n"
            . "}\n"
            . "要求：1) 必须基于 target_platform、target_keyword、brand_name、audience_persona、test_question、expected_direction、response_excerpt、response_source、mention_status、observed_competitors、asset_context、adaptation_context、notes 输出。2) 如果 payload 包含 global_brand_profile，必须用它判断当前回答是否符合品牌定位、推荐场景、禁用边界、官方页面和权威证明。3) 不要虚构平台结果，不要承诺一定推荐，只能给出更可能提升推荐概率的诊断。4) 如果证据不足，要明确指出缺口和应补资产。5) winning_fragments、missing_signals、risk_signals、asset_updates、adaptation_updates、next_questions 必须具体可执行。6) 输出必须是合法 JSON。";
    }
}

