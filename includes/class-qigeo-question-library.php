<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Question_Library {

    const MENU_SLUG = 'qigeo-question-library';
    const AJAX_GENERATE_ACTION = 'qigeo_generate_question_library';
    const AJAX_SAVE_ACTION = 'qigeo_save_question_library';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '问法库',
            '问法库',
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
            'qigeo-question-library-admin',
            QIGEO_URL . 'assets/question-library-admin.js',
            array( 'qigeo-admin' ),
            QIGEO_VERSION,
            true
        );

        wp_localize_script(
            'qigeo-question-library-admin',
            'qigeoQuestionLibraryData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_question_library_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_question_library_nonce' ),
                'messages' => array(
                    'generating' => '正在拆解问法库...',
                    'saving' => '正在保存问法库记录...',
                    'generateDone' => '问法库已生成，可继续微调后保存。',
                    'saveDone' => '问法库记录已保存。',
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
            <h1>问法库</h1>
            <p class="description">整理用户常见提问。</p>

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
                <h2><?php echo is_array( $current ) ? '编辑问法库记录' : '新建问法库记录'; ?></h2>
                <a class="button" href="<?php echo esc_url( $base_url ); ?>">新建空白记录</a>
            </div>

            <?php if ( is_array( $current ) ) : ?>
                <p class="qigeo-question-active-record">已加载记录 #<?php echo esc_html( (string) absint( $current['id'] ) ); ?>，你可以继续生成、调整或覆盖保存。</p>
            <?php endif; ?>

            <?php if ( empty( $model_options ) ) : ?>
                <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
            <?php endif; ?>

            <form id="qigeo-question-form" class="qigeo-question-form" action="#" method="post">
                <input type="hidden" id="qigeo-question-record-id" name="record_id" value="<?php echo esc_attr( (string) $form['record_id'] ); ?>" />

                <div class="qigeo-question-grid">
                    <div class="qigeo-question-field qigeo-question-field--wide">
                        <label for="qigeo-question-seed-keyword"><strong>核心主题词 / 种子词</strong></label>
                        <input type="text" class="regular-text" id="qigeo-question-seed-keyword" name="seed_keyword" maxlength="255" value="<?php echo esc_attr( $form['seed_keyword'] ); ?>" placeholder="例如：wordpress 最好的中文主题" />
                        <p class="description">填写本次要整理的主题。</p>
                    </div>

                    <div class="qigeo-question-field">
                        <label for="qigeo-question-target-platform"><strong>目标 AI 平台</strong></label>
                        <input type="text" class="regular-text" id="qigeo-question-target-platform" name="target_platform" maxlength="120" value="<?php echo esc_attr( $form['target_platform'] ); ?>" placeholder="例如：豆包 / ChatGPT / DeepSeek" />
                        <p class="description">填写本次要观察的平台名称。</p>
                    </div>

                    <div class="qigeo-question-field">
                        <label for="qigeo-question-target-keyword"><strong>希望被推荐的词</strong></label>
                        <input type="text" class="regular-text" id="qigeo-question-target-keyword" name="target_keyword" maxlength="255" value="<?php echo esc_attr( $form['target_keyword'] ); ?>" placeholder="例如：启灵主题" />
                    </div>

                    <div class="qigeo-question-field">
                        <label for="qigeo-question-brand-name"><strong>品牌 / 产品名</strong></label>
                        <input type="text" class="regular-text" id="qigeo-question-brand-name" name="brand_name" maxlength="190" value="<?php echo esc_attr( $form['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                    </div>

                    <div class="qigeo-question-field">
                        <label for="qigeo-question-audience"><strong>目标人群 / 使用场景</strong></label>
                        <input type="text" class="regular-text" id="qigeo-question-audience" name="audience_persona" maxlength="190" value="<?php echo esc_attr( $form['audience_persona'] ); ?>" placeholder="例如：中文企业站、新手站长、建站服务商" />
                    </div>

                    <div class="qigeo-question-field">
                        <label for="qigeo-question-model-id"><strong>优先起草模型</strong></label>
                        <select id="qigeo-question-model-id" name="model_id">
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

                    <div class="qigeo-question-field qigeo-question-field--wide">
                        <label for="qigeo-question-business-summary"><strong>业务 / 产品简介</strong></label>
                        <textarea id="qigeo-question-business-summary" name="business_summary" rows="4" placeholder="用 3-6 句话说明你们做什么、适合谁、跟这个核心词的关系。"><?php echo esc_textarea( $form['business_summary'] ); ?></textarea>
                    </div>

                    <div class="qigeo-question-field">
                        <label for="qigeo-question-competitors"><strong>重点竞品 / 对比对象</strong></label>
                        <textarea id="qigeo-question-competitors" name="competitor_terms" rows="7" placeholder="每行一个竞品，例如：&#10;Astra&#10;GeneratePress&#10;Blocksy"><?php echo esc_textarea( $form['competitor_terms'] ); ?></textarea>
                    </div>

                    <div class="qigeo-question-field">
                        <label for="qigeo-question-notes"><strong>补充限制 / 语境备注</strong></label>
                        <textarea id="qigeo-question-notes" name="notes" rows="7" placeholder="例如：不要走低价路线；用户更看重中文适配、上手快、企业站交付。"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
                    </div>
                </div>

                <div class="qigeo-question-actions">
                    <button type="button" class="button button-secondary" id="qigeo-generate-question-btn">生成问法库</button>
                    <button type="button" class="button button-primary" id="qigeo-save-question-btn">保存记录</button>
                    <span class="qigeo-question-feedback" id="qigeo-question-feedback" aria-live="polite"></span>
                </div>

                <?php self::render_result_fields( $form ); ?>
            </form>
        </div>
        <?php
    }

    private static function render_result_fields( $form ) {
        ?>
        <div class="qigeo-question-results">
            <h3>问法库输出结果</h3>
            <div class="qigeo-question-grid">
                <div class="qigeo-question-field qigeo-question-field--wide">
                    <label for="qigeo-question-result-summary"><strong>问法库总览</strong></label>
                    <textarea id="qigeo-question-result-summary" name="summary" rows="4" placeholder="这里会说明这个主题词下最值得打的一组问题方向。"><?php echo esc_textarea( $form['summary'] ); ?></textarea>
                </div>

                <div class="qigeo-question-field qigeo-question-field--wide">
                    <label for="qigeo-question-result-strategy"><strong>问法覆盖策略</strong></label>
                    <textarea id="qigeo-question-result-strategy" name="strategy_summary" rows="4" placeholder="这里会说明应该先覆盖哪些问题，再补哪些问题。"><?php echo esc_textarea( $form['strategy_summary'] ); ?></textarea>
                </div>

                <div class="qigeo-question-field">
                    <label for="qigeo-question-result-primary"><strong>一级高价值问法</strong></label>
                    <textarea id="qigeo-question-result-primary" name="primary_questions" rows="10" placeholder="每行一个问题，建议格式：问题 || 意图：... || 阶段：... || 优先级：高 || 原因：..."><?php echo esc_textarea( $form['primary_questions'] ); ?></textarea>
                </div>

                <div class="qigeo-question-field">
                    <label for="qigeo-question-result-supporting"><strong>二级长尾问法</strong></label>
                    <textarea id="qigeo-question-result-supporting" name="supporting_questions" rows="10" placeholder="每行一个更细分、更多样的长尾问法。"><?php echo esc_textarea( $form['supporting_questions'] ); ?></textarea>
                </div>

                <div class="qigeo-question-field">
                    <label for="qigeo-question-result-comparison"><strong>对比决策问法</strong></label>
                    <textarea id="qigeo-question-result-comparison" name="comparison_questions" rows="10" placeholder="每行一个竞品对比或选择类问法。"><?php echo esc_textarea( $form['comparison_questions'] ); ?></textarea>
                </div>

                <div class="qigeo-question-field">
                    <label for="qigeo-question-result-scenario"><strong>场景细分问法</strong></label>
                    <textarea id="qigeo-question-result-scenario" name="scenario_questions" rows="10" placeholder="每行一个人群、行业、使用场景相关问法。"><?php echo esc_textarea( $form['scenario_questions'] ); ?></textarea>
                </div>

                <div class="qigeo-question-field">
                    <label for="qigeo-question-result-risk"><strong>风险 / 不宜硬推问法</strong></label>
                    <textarea id="qigeo-question-result-risk" name="risk_questions" rows="8" placeholder="每行一个风险问法，说明什么时候不该强推。"><?php echo esc_textarea( $form['risk_questions'] ); ?></textarea>
                </div>

                <div class="qigeo-question-field">
                    <label for="qigeo-question-result-gaps"><strong>内容补位主题</strong></label>
                    <textarea id="qigeo-question-result-gaps" name="content_gap_topics" rows="8" placeholder="每行一个还需要公开补上的内容主题或证据主题。"><?php echo esc_textarea( $form['content_gap_topics'] ); ?></textarea>
                </div>

                <div class="qigeo-question-field qigeo-question-field--wide">
                    <label for="qigeo-question-result-steps"><strong>执行顺序</strong></label>
                    <textarea id="qigeo-question-result-steps" name="execution_steps" rows="6" placeholder="每行一个动作，说明应该先做什么，再做什么。"><?php echo esc_textarea( $form['execution_steps'] ); ?></textarea>
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

            <form method="get" class="qigeo-question-search">
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
                        <th style="width:90px;">问法数</th>
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
                <div class="qigeo-question-row-meta"><?php echo esc_html( implode( ' / ', array_filter( array( $row_brand, $row_audience ) ) ) ); ?></div>
            </td>
            <td>
                <strong><?php echo esc_html( $row_target ); ?></strong>
                <div class="qigeo-question-row-meta"><?php echo esc_html( $row_platform ); ?></div>
            </td>
            <td><?php echo esc_html( (string) self::count_question_items( $result ) ); ?></td>
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

        check_ajax_referer( 'qigeo_generate_question_library_nonce', 'nonce' );

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
                'message' => '问法库已拆解完成，可继续微调后再保存。',
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

        check_ajax_referer( 'qigeo_save_question_library_nonce', 'nonce' );

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
            && empty( $result['primary_questions'] )
            && empty( $result['comparison_questions'] )
            && empty( $result['scenario_questions'] )
        ) {
            wp_send_json_error( array( 'message' => '请先生成或填写问法库结果后再保存' ) );
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
            'business_summary' => $form['business_summary'],
            'competitor_terms' => $form['competitor_terms'],
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
                    'message' => '问法库记录已更新。',
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
                'message' => '问法库记录已保存。',
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
            'business_summary' => $form['business_summary'],
            'competitor_terms' => self::parse_plain_lines( $form['competitor_terms'], 12 ),
            'notes' => self::parse_plain_lines( $form['notes'], 15 ),
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
            'business_summary' => '',
            'competitor_terms' => '',
            'notes' => '',
            'summary' => '',
            'strategy_summary' => '',
            'primary_questions' => '',
            'supporting_questions' => '',
            'comparison_questions' => '',
            'scenario_questions' => '',
            'risk_questions' => '',
            'content_gap_topics' => '',
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
        $form['business_summary'] = isset( $record['business_summary'] ) ? sanitize_textarea_field( (string) $record['business_summary'] ) : '';
        $form['competitor_terms'] = isset( $record['competitor_terms'] ) ? sanitize_textarea_field( (string) $record['competitor_terms'] ) : '';
        $form['notes'] = isset( $record['notes'] ) ? sanitize_textarea_field( (string) $record['notes'] ) : '';

        $result = self::decode_result_json( isset( $record['result_json'] ) ? (string) $record['result_json'] : '' );
        $form['summary'] = isset( $result['summary'] ) ? sanitize_textarea_field( (string) $result['summary'] ) : '';
        if ( $form['summary'] === '' && ! empty( $record['result_summary'] ) ) {
            $form['summary'] = sanitize_textarea_field( (string) $record['result_summary'] );
        }
        $form['strategy_summary'] = isset( $result['strategy_summary'] ) ? sanitize_textarea_field( (string) $result['strategy_summary'] ) : '';
        $form['primary_questions'] = self::lines_from_array( isset( $result['primary_questions'] ) ? $result['primary_questions'] : array() );
        $form['supporting_questions'] = self::lines_from_array( isset( $result['supporting_questions'] ) ? $result['supporting_questions'] : array() );
        $form['comparison_questions'] = self::lines_from_array( isset( $result['comparison_questions'] ) ? $result['comparison_questions'] : array() );
        $form['scenario_questions'] = self::lines_from_array( isset( $result['scenario_questions'] ) ? $result['scenario_questions'] : array() );
        $form['risk_questions'] = self::lines_from_array( isset( $result['risk_questions'] ) ? $result['risk_questions'] : array() );
        $form['content_gap_topics'] = self::lines_from_array( isset( $result['content_gap_topics'] ) ? $result['content_gap_topics'] : array() );
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
            'business_summary' => isset( $_POST['business_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['business_summary'] ) ) : '',
            'competitor_terms' => isset( $_POST['competitor_terms'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['competitor_terms'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        );
    }

    private static function get_result_data_from_request() {
        return array(
            'summary' => self::limit_text( isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '', 800 ),
            'strategy_summary' => self::limit_text( isset( $_POST['strategy_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['strategy_summary'] ) ) : '', 800 ),
            'primary_questions' => self::parse_plain_lines( isset( $_POST['primary_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['primary_questions'] ) ) : '', 15 ),
            'supporting_questions' => self::parse_plain_lines( isset( $_POST['supporting_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['supporting_questions'] ) ) : '', 20 ),
            'comparison_questions' => self::parse_plain_lines( isset( $_POST['comparison_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['comparison_questions'] ) ) : '', 15 ),
            'scenario_questions' => self::parse_plain_lines( isset( $_POST['scenario_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['scenario_questions'] ) ) : '', 15 ),
            'risk_questions' => self::parse_plain_lines( isset( $_POST['risk_questions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['risk_questions'] ) ) : '', 12 ),
            'content_gap_topics' => self::parse_plain_lines( isset( $_POST['content_gap_topics'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['content_gap_topics'] ) ) : '', 15 ),
            'execution_steps' => self::parse_plain_lines( isset( $_POST['execution_steps'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['execution_steps'] ) ) : '', 12 ),
        );
    }

    private static function validate_base_form_data( $form ) {
        if ( empty( $form['seed_keyword'] ) ) {
            return new WP_Error( 'qigeo_question_seed_required', '请先填写核心主题词 / 种子词' );
        }
        if ( empty( $form['target_platform'] ) ) {
            return new WP_Error( 'qigeo_question_platform_required', '请先填写目标 AI 平台' );
        }
        if ( empty( $form['target_keyword'] ) ) {
            return new WP_Error( 'qigeo_question_target_required', '请先填写希望被推荐的词' );
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
        return $wpdb->prefix . 'qigeo_question_library';
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
            return new WP_Error( 'qigeo_question_model_missing', '请先在启灵GEO配置并启用至少一个可用模型' );
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
                isset( $parsed['strategy_summary'] ) ? sanitize_textarea_field( (string) $parsed['strategy_summary'] ) : ( isset( $parsed['question_strategy'] ) ? sanitize_textarea_field( (string) $parsed['question_strategy'] ) : '' ),
                800
            ),
            'primary_questions' => self::normalize_string_list( isset( $parsed['primary_questions'] ) ? $parsed['primary_questions'] : array(), 15 ),
            'supporting_questions' => self::normalize_string_list( isset( $parsed['supporting_questions'] ) ? $parsed['supporting_questions'] : array(), 20 ),
            'comparison_questions' => self::normalize_string_list( isset( $parsed['comparison_questions'] ) ? $parsed['comparison_questions'] : array(), 15 ),
            'scenario_questions' => self::normalize_string_list( isset( $parsed['scenario_questions'] ) ? $parsed['scenario_questions'] : array(), 15 ),
            'risk_questions' => self::normalize_string_list( isset( $parsed['risk_questions'] ) ? $parsed['risk_questions'] : array(), 12 ),
            'content_gap_topics' => self::normalize_string_list(
                isset( $parsed['content_gap_topics'] ) ? $parsed['content_gap_topics'] : ( isset( $parsed['evidence_gaps'] ) ? $parsed['evidence_gaps'] : array() ),
                15
            ),
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

    private static function count_question_items( $result ) {
        $count = 0;
        foreach ( array( 'primary_questions', 'supporting_questions', 'comparison_questions', 'scenario_questions', 'risk_questions' ) as $key ) {
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
        return "你是 GEO 问法库策划助手。现在要围绕一个核心主题词，把真实用户可能会问的问题拆出来，帮助后续做内容布局、证据补强和 AI 回测。请严格输出 JSON，不要 markdown，不要解释。\n"
            . "JSON 结构：\n"
            . "{\n"
            . "  \"summary\": \"80-220字，概括这个主题词最值得打的问法方向\",\n"
            . "  \"strategy_summary\": \"80-220字，说明应该先覆盖哪些问题，再补哪些问题\",\n"
            . "  \"primary_questions\": [\"问题 || 意图：... || 阶段：认知/比较/决策 || 优先级：高 || 原因：...\"],\n"
            . "  \"supporting_questions\": [\"问题 || 意图：... || 阶段：... || 优先级：中/低 || 原因：...\"],\n"
            . "  \"comparison_questions\": [\"问题 || 意图：对比选择 || 阶段：比较/决策 || 优先级：高/中 || 原因：...\"],\n"
            . "  \"scenario_questions\": [\"问题 || 意图：场景适配 || 阶段：认知/比较 || 优先级：中/高 || 原因：...\"],\n"
            . "  \"risk_questions\": [\"问题 || 风险：... || 建议：...\"],\n"
            . "  \"content_gap_topics\": [\"还缺的公开内容或证据主题1\", \"主题2\"],\n"
            . "  \"execution_steps\": [\"下一步动作1\", \"动作2\"]\n"
            . "}\n"
            . "要求：\n"
            . "1) 所有问题都要像真实用户会问的话，避免营销口号、品牌自嗨和硬塞推荐。\n"
            . "2) primary_questions 聚焦最可能影响 AI 推荐的核心问法，建议 8-12 条。\n"
            . "3) supporting_questions 建议 10-16 条，尽量覆盖长尾、教程、适配、避坑、成本、上手难度等维度。\n"
            . "4) comparison_questions 建议 6-10 条，优先围绕用户真正会比较的竞品和替代方案。\n"
            . "5) scenario_questions 建议 6-10 条，要覆盖行业、人群、站点类型或使用阶段。\n"
            . "6) risk_questions 建议 4-8 条，明确哪些问题下不应该硬推目标词，或至少要补证据后再推。\n"
            . "7) content_gap_topics 要直接指出还缺哪些公开内容、案例、FAQ、对比页、教程页或证据页。\n"
            . "8) 不要虚构销量、奖项、媒体背书、用户评价、官方合作、测试数据或市场份额。\n"
            . "9) 输出必须是合法 JSON。";
    }
}
