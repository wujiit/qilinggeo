<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_External_Keyword {

    const MENU_SLUG = 'qigeo-external-keyword';
    const AJAX_GENERATE_ACTION = 'qigeo_generate_external_keyword';
    const AJAX_SAVE_ACTION = 'qigeo_save_external_keyword';
    const REVIEW_PASS_SCORE = 78;
    const REVIEW_MODEL_LIMIT = 2;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '外部关键词 GEO',
            '外部关键词GEO',
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
            'qigeo-external-keyword-admin',
            QIGEO_URL . 'assets/external-keyword-admin.js',
            array( 'qigeo-admin' ),
            QIGEO_VERSION,
            true
        );

        wp_localize_script(
            'qigeo-external-keyword-admin',
            'qigeoExternalKeywordData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_external_keyword_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_external_keyword_nonce' ),
                'messages' => array(
                    'generating' => '正在执行四阶段 GEO 工作流...',
                    'saving' => '正在保存记录...',
                    'generateDone' => '四阶段 GEO 工作流已完成，可继续微调后保存。',
                    'saveDone' => '记录已保存。',
                ),
            )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $table_exists = self::table_exists();
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
        $edit_id = isset( $_GET['id'] ) ? absint( wp_unslash( (string) $_GET['id'] ) ) : 0;

        $current = null;
        if ( $table_exists && $action === 'edit' && $edit_id > 0 ) {
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
            <h1>外部关键词 GEO</h1>
            <p class="description">整理站外关键词和推荐目标。</p>

            <?php if ( ! $table_exists ) : ?>
                <div class="notice notice-error"><p>当前页面数据暂未准备好，请刷新页面后重试。</p></div>
            <?php endif; ?>
            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2><?php echo is_array( $current ) ? '编辑 GEO 记录' : '新建 GEO 记录'; ?></h2>
                    <a class="button" href="<?php echo esc_url( $base_url ); ?>">新建空白记录</a>
                </div>

                <?php if ( is_array( $current ) ) : ?>
                    <p class="qigeo-external-active-record">已载入记录 #<?php echo esc_html( (string) absint( $current['id'] ) ); ?>，你可以继续生成、调整或覆盖保存。</p>
                <?php endif; ?>

                <?php if ( empty( $model_options ) ) : ?>
                    <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
                <?php endif; ?>

                <form id="qigeo-external-form" class="qigeo-external-form" action="#" method="post">
                    <input type="hidden" id="qigeo-external-record-id" name="record_id" value="<?php echo esc_attr( (string) $form['record_id'] ); ?>" />
                    <input type="hidden" id="qigeo-workflow-meta-json" name="workflow_meta_json" value="<?php echo esc_attr( $form['workflow_meta_json'] ); ?>" />

                    <div class="qigeo-external-grid">
                        <div class="qigeo-external-field qigeo-external-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '用户提问词 / 外部关键词', array( 'required', 'key' ), 'qigeo-query-keyword' ) ); ?>
                            <input type="text" class="regular-text" id="qigeo-query-keyword" name="query_keyword" maxlength="255" value="<?php echo esc_attr( $form['query_keyword'] ); ?>" placeholder="例如：最好的中文 WordPress 主题" />
                            <p class="description">填写用户常见提问。</p>
                        </div>

                        <div class="qigeo-external-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '目标 AI 平台', array( 'required', 'key' ), 'qigeo-target-platform' ) ); ?>
                            <input type="text" class="regular-text" id="qigeo-target-platform" name="target_platform" maxlength="120" value="<?php echo esc_attr( $form['target_platform'] ); ?>" placeholder="例如：ChatGPT / DeepSeek / Claude / 豆包" />
                            <p class="description">填写本次要观察的平台名称。</p>
                        </div>

                        <div class="qigeo-external-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '希望被推荐的词', array( 'required', 'key' ), 'qigeo-target-keyword' ) ); ?>
                            <input type="text" class="regular-text" id="qigeo-target-keyword" name="target_keyword" maxlength="255" value="<?php echo esc_attr( $form['target_keyword'] ); ?>" placeholder="例如：启灵主题" />
                        </div>

                        <div class="qigeo-external-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '品牌 / 产品名称', array( 'recommended' ), 'qigeo-brand-name' ) ); ?>
                            <input type="text" class="regular-text" id="qigeo-brand-name" name="brand_name" maxlength="190" value="<?php echo esc_attr( $form['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                        </div>

                        <div class="qigeo-external-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '优先起草模型', array( 'recommended' ), 'qigeo-model-id' ) ); ?>
                            <select id="qigeo-model-id" name="model_id">
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

                        <div class="qigeo-external-field qigeo-external-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '承接链接', array( 'recommended' ), 'qigeo-landing-url' ) ); ?>
                            <input type="url" class="regular-text" id="qigeo-landing-url" name="landing_url" value="<?php echo esc_attr( $form['landing_url'] ); ?>" placeholder="例如：https://example.com/qiling-theme/" />
                            <p class="description">可填写想让用户看到的页面地址。</p>
                        </div>

                        <div class="qigeo-external-field qigeo-external-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '网站定位 / 推荐边界', array( 'key' ), 'qigeo-site-positioning' ) ); ?>
                            <textarea id="qigeo-site-positioning" name="site_positioning" rows="4" placeholder="说明网站或品牌在 AI 回答里应该如何被定位，例如：服务对象、核心场景、适合/不适合被推荐的情况。"><?php echo esc_textarea( $form['site_positioning'] ); ?></textarea>
                            <p class="description">让模型知道你们是谁、适合谁，以及哪些场景不要硬推。</p>
                        </div>

                        <div class="qigeo-external-field qigeo-external-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '业务 / 产品简介', array( 'key' ), 'qigeo-business-summary' ) ); ?>
                            <textarea id="qigeo-business-summary" name="business_summary" rows="4" placeholder="用 3-6 句话说明你们是做什么的、解决什么问题、适合什么人群。"><?php echo esc_textarea( $form['business_summary'] ); ?></textarea>
                        </div>

                        <div class="qigeo-external-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '核心卖点', array( 'key' ), 'qigeo-selling-points' ) ); ?>
                            <textarea id="qigeo-selling-points" name="selling_points" rows="7" placeholder="每行一个卖点，例如：&#10;适合中文站点&#10;内置企业展示模块&#10;对新手更友好"><?php echo esc_textarea( $form['selling_points'] ); ?></textarea>
                        </div>

                        <div class="qigeo-external-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '可信佐证 / 已有素材', array( 'recommended' ), 'qigeo-proof-points' ) ); ?>
                            <textarea id="qigeo-proof-points" name="proof_points" rows="7" placeholder="每行一个证据，例如：&#10;已有中文演示站&#10;已整理安装文档&#10;有真实案例页"><?php echo esc_textarea( $form['proof_points'] ); ?></textarea>
                        </div>

                        <div class="qigeo-external-field qigeo-external-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '引用来源地址', array( 'recommended', 'key' ), 'qigeo-source-links' ) ); ?>
                            <textarea id="qigeo-source-links" name="source_links" rows="8" placeholder="每行一个来源，推荐格式：&#10;来源标题 || https://example.com/page/ || 官网页面 || 支撑的结论 || 2026-04-16 || 是 || 高&#10;也可以只填 URL，系统会按来源处理。"><?php echo esc_textarea( $form['source_links'] ); ?></textarea>
                            <p class="description">格式：来源标题 || 引用来源 URL || 来源类型 || 支撑的结论 || 发布/更新时间 || 是否官方来源 || 优先级。来源类型可填：官网页面、帮助文档、案例、研究报告、媒体报道、用户评价、产品页、价格页、FAQ。</p>
                        </div>

                        <div class="qigeo-external-field qigeo-external-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '补充限制 / 竞品语境 / 备注', array( 'recommended' ), 'qigeo-notes' ) ); ?>
                            <textarea id="qigeo-notes" name="notes" rows="4" placeholder="例如：不要强调低价；用户更看重速度和中文适配；常见竞品是 Astra / GeneratePress。"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
                        </div>
                    </div>

                    <div class="qigeo-external-actions">
                        <button type="button" class="button button-secondary" id="qigeo-generate-external-btn">生成 GEO 方案</button>
                        <button type="button" class="button button-primary" id="qigeo-save-external-btn">保存记录</button>
                        <span class="qigeo-external-feedback" id="qigeo-external-feedback" aria-live="polite"></span>
                    </div>

                    <div class="qigeo-external-results">
                        <h3>四阶段 GEO 输出结果</h3>
                        <div class="qigeo-external-grid">
                            <div class="qigeo-external-field qigeo-external-field--wide">
                                <label for="qigeo-result-summary"><strong>工作流总览</strong></label>
                                <textarea id="qigeo-result-summary" name="summary" rows="4" placeholder="这里会说明起草模型、评分结果、目标平台验收状态和后续发布节奏。"><?php echo esc_textarea( $form['summary'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field qigeo-external-field--wide">
                                <label for="qigeo-result-positioning"><strong>阶段3 目标平台验收结论</strong></label>
                                <textarea id="qigeo-result-positioning" name="recommendation_positioning" rows="4" placeholder="这里会说明豆包这类目标平台是否建议进入验收，以及当前结论。"><?php echo esc_textarea( $form['recommendation_positioning'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field">
                                <label for="qigeo-result-intent"><strong>阶段1 用户意图与素材方向</strong></label>
                                <textarea id="qigeo-result-intent" name="intent_breakdown" rows="7" placeholder="每行一个用户真实意图"><?php echo esc_textarea( $form['intent_breakdown'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field">
                                <label for="qigeo-result-angles"><strong>阶段1 推荐切入角度</strong></label>
                                <textarea id="qigeo-result-angles" name="answer_angles" rows="7" placeholder="每行一个适合公开内容的切入角度"><?php echo esc_textarea( $form['answer_angles'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field">
                                <label for="qigeo-result-phrases"><strong>阶段2 多模型高分理由</strong></label>
                                <textarea id="qigeo-result-phrases" name="mention_phrases" rows="7" placeholder="这里会放平均评分和主要高分原因"><?php echo esc_textarea( $form['mention_phrases'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field">
                                <label for="qigeo-result-examples"><strong>阶段3 目标平台模拟回答 / 自然提及</strong></label>
                                <textarea id="qigeo-result-examples" name="natural_mention_examples" rows="7" placeholder="每行一条更像目标平台真实会说的话"><?php echo esc_textarea( $form['natural_mention_examples'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field qigeo-external-field--wide">
                                <label for="qigeo-result-faq"><strong>阶段1 FAQ 素材</strong></label>
                                <textarea id="qigeo-result-faq" name="faq_lines" rows="8" placeholder="每行一个 FAQ，格式：问题 || 回答"><?php echo esc_textarea( $form['faq_lines'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field">
                                <label for="qigeo-result-assets"><strong>阶段4 发布资产清单</strong></label>
                                <textarea id="qigeo-result-assets" name="content_assets" rows="7" placeholder="每行一个需要公开发布或补强的资产"><?php echo esc_textarea( $form['content_assets'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field">
                                <label for="qigeo-result-trust"><strong>阶段2/4 可信佐证与待补证据</strong></label>
                                <textarea id="qigeo-result-trust" name="trust_signals" rows="7" placeholder="每行一个可信佐证或需要补充的证据"><?php echo esc_textarea( $form['trust_signals'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field">
                                <label for="qigeo-result-citations"><strong>推荐引用来源</strong></label>
                                <textarea id="qigeo-result-citations" name="citation_sources" rows="7" placeholder="每行一个来源，格式：标题 || URL || 来源类型 || 支撑结论 || 发布/更新时间 || 是否官方 || 优先级"><?php echo esc_textarea( $form['citation_sources'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field">
                                <label for="qigeo-result-source-gaps"><strong>来源缺口</strong></label>
                                <textarea id="qigeo-result-source-gaps" name="source_gaps" rows="7" placeholder="每行一个还需要补充的来源或证据"><?php echo esc_textarea( $form['source_gaps'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field">
                                <label for="qigeo-result-source-assets"><strong>可发布来源资产</strong></label>
                                <textarea id="qigeo-result-source-assets" name="publishable_source_assets" rows="7" placeholder="每行一个建议新建或补强的可引用页面"><?php echo esc_textarea( $form['publishable_source_assets'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field qigeo-external-field--wide">
                                <label for="qigeo-result-claim-map"><strong>结论 - 来源映射</strong></label>
                                <textarea id="qigeo-result-claim-map" name="claim_to_source_map" rows="7" placeholder="每行一个结论对应的来源，例如：适合中文站点 || 安装文档 URL / 案例页 URL"><?php echo esc_textarea( $form['claim_to_source_map'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field qigeo-external-field--wide">
                                <label for="qigeo-result-risk-claims"><strong>无证据风险主张</strong></label>
                                <textarea id="qigeo-result-risk-claims" name="risk_claims" rows="7" placeholder="每行一个暂时没有来源支撑、不建议写死的说法"><?php echo esc_textarea( $form['risk_claims'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field">
                                <label for="qigeo-result-risks"><strong>阶段2/3 风险与阻塞项</strong></label>
                                <textarea id="qigeo-result-risks" name="risk_notes" rows="7" placeholder="每行一个会阻碍推荐或验收的风险"><?php echo esc_textarea( $form['risk_notes'] ); ?></textarea>
                            </div>

                            <div class="qigeo-external-field">
                                <label for="qigeo-result-steps"><strong>阶段4 执行与回测顺序</strong></label>
                                <textarea id="qigeo-result-steps" name="execution_steps" rows="7" placeholder="每行一个执行或回测步骤"><?php echo esc_textarea( $form['execution_steps'] ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2>最近记录</h2>
                    <span class="description">最多显示最近 20 条，可搜索问法、平台、推荐词和摘要。</span>
                </div>

                <form method="get" class="qigeo-external-search">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索问法 / 平台 / 推荐词 / 摘要" />
                    <button type="submit" class="button">搜索</button>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">重置</a>
                </form>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:72px;">ID</th>
                            <th style="min-width:220px;">问题场景</th>
                            <th style="min-width:180px;">目标推荐词</th>
                            <th>策略摘要</th>
                            <th style="min-width:170px;">模型</th>
                            <th style="width:150px;">更新时间</th>
                            <th style="width:90px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr>
                            <td colspan="7">暂无记录</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $row ) : ?>
                            <?php
                            $row_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
                            $edit_url = add_query_arg(
                                array(
                                    'page' => self::MENU_SLUG,
                                    'action' => 'edit',
                                    'id' => $row_id,
                                ),
                                admin_url( 'admin.php' )
                            );
                            $query_keyword = isset( $row['query_keyword'] ) ? sanitize_text_field( (string) $row['query_keyword'] ) : '';
                            $target_platform = isset( $row['target_platform'] ) ? sanitize_text_field( (string) $row['target_platform'] ) : '';
                            $target_keyword = isset( $row['target_keyword'] ) ? sanitize_text_field( (string) $row['target_keyword'] ) : '';
                            $brand_name = isset( $row['brand_name'] ) ? sanitize_text_field( (string) $row['brand_name'] ) : '';
                            $summary = isset( $row['result_summary'] ) ? (string) $row['result_summary'] : '';
                            $summary_excerpt = self::excerpt_text( $summary, 110 );
                            $model = isset( $row['model'] ) ? sanitize_text_field( (string) $row['model'] ) : '';
                            $provider_id = isset( $row['provider_id'] ) ? sanitize_text_field( (string) $row['provider_id'] ) : '';
                            $updated_at = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '';
                            ?>
                            <tr>
                                <td>#<?php echo esc_html( (string) $row_id ); ?></td>
                                <td>
                                    <strong><?php echo esc_html( $query_keyword !== '' ? $query_keyword : '-' ); ?></strong>
                                    <div class="qigeo-external-row-meta">平台：<?php echo esc_html( $target_platform !== '' ? $target_platform : '-' ); ?></div>
                                </td>
                                <td>
                                    <strong><?php echo esc_html( $target_keyword !== '' ? $target_keyword : '-' ); ?></strong>
                                    <div class="qigeo-external-row-meta">品牌：<?php echo esc_html( $brand_name !== '' ? $brand_name : '-' ); ?></div>
                                </td>
                                <td><?php echo esc_html( $summary_excerpt !== '' ? $summary_excerpt : '-' ); ?></td>
                                <td>
                                    <div><?php echo esc_html( $model !== '' ? $model : '-' ); ?></div>
                                    <div class="qigeo-external-row-meta">provider：<?php echo esc_html( $provider_id !== '' ? $provider_id : '-' ); ?></div>
                                </td>
                                <td><?php echo esc_html( $updated_at !== '' ? $updated_at : '-' ); ?></td>
                                <td><a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">载入</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public static function ajax_generate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'qigeo_generate_external_keyword_nonce', 'nonce' );

        $form = self::get_request_form_data();
        $validation_error = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation_error ) ) {
            wp_send_json_error( array( 'message' => $validation_error->get_error_message() ) );
        }

        $normalized = self::run_generation_workflow( $form );
        if ( is_wp_error( $normalized ) ) {
            wp_send_json_error( array( 'message' => $normalized->get_error_message() ) );
        }

        wp_send_json_success(
            array(
                'message' => '四阶段 GEO 工作流已执行完成，可继续调整后再保存。',
                'result' => $normalized,
            )
        );
    }

    public static function ajax_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        check_ajax_referer( 'qigeo_save_external_keyword_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $form = self::get_request_form_data();
        $validation_error = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation_error ) ) {
            wp_send_json_error( array( 'message' => $validation_error->get_error_message() ) );
        }

        $workflow_models = self::resolve_workflow_models( $form['model_id'], $form['target_platform'] );
        if ( is_wp_error( $workflow_models ) ) {
            wp_send_json_error( array( 'message' => $workflow_models->get_error_message() ) );
        }

        $result = self::get_result_data_from_request();
        if (
            $result['summary'] === ''
            && empty( $result['execution_steps'] )
            && empty( $result['faq'] )
            && empty( $result['citation_sources'] )
            && empty( $result['publishable_source_assets'] )
            && empty( $result['risk_claims'] )
        ) {
            wp_send_json_error( array( 'message' => '请先生成或填写 GEO 输出结果后再保存' ) );
        }

        $storage_model = self::resolve_storage_model_packet( $workflow_models, $result );

        global $wpdb;
        $table = self::get_table_name();
        $now = current_time( 'mysql' );
        $record_id = absint( $form['record_id'] );
        $current_user_id = get_current_user_id();

        $data = array(
            'query_keyword' => $form['query_keyword'],
            'target_platform' => $form['target_platform'],
            'target_keyword' => $form['target_keyword'],
            'brand_name' => $form['brand_name'],
            'landing_url' => $form['landing_url'],
            'site_positioning' => $form['site_positioning'],
            'source_links' => $form['source_links'],
            'business_summary' => $form['business_summary'],
            'selling_points' => $form['selling_points'],
            'proof_points' => $form['proof_points'],
            'notes' => $form['notes'],
            'provider_id' => isset( $storage_model['provider_id'] ) ? (string) $storage_model['provider_id'] : '',
            'model_id' => isset( $storage_model['model_id'] ) ? (string) $storage_model['model_id'] : '',
            'model' => isset( $storage_model['model_name'] ) ? (string) $storage_model['model_name'] : '',
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
            '%s',
        );

        if ( $record_id > 0 ) {
            $existing_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE id = %d LIMIT 1",
                    $record_id
                )
            );

            if ( $existing_id > 0 ) {
                $saved = $wpdb->update(
                    $table,
                    $data,
                    array( 'id' => $record_id ),
                    $formats,
                    array( '%d' )
                );

                if ( $saved === false ) {
                    wp_send_json_error( array( 'message' => '更新记录失败，请稍后重试' ) );
                }

                wp_send_json_success(
                    array(
                        'message' => '记录已更新。',
                        'record_id' => $record_id,
                    )
                );
            }
        }

        $data['created_by'] = $current_user_id;
        $data['created_at'] = $now;
        $insert_formats = array_merge( $formats, array( '%d', '%s' ) );
        $saved = $wpdb->insert( $table, $data, $insert_formats );

        if ( ! $saved ) {
            wp_send_json_error( array( 'message' => '保存记录失败，请稍后重试' ) );
        }

        wp_send_json_success(
            array(
                'message' => '记录已保存。',
                'record_id' => absint( $wpdb->insert_id ),
            )
        );
    }

    private static function run_generation_workflow( $form ) {
        $payload = self::build_generation_payload( $form );
        $workflow_models = self::resolve_workflow_models( $form['model_id'], $form['target_platform'] );
        if ( is_wp_error( $workflow_models ) ) {
            return $workflow_models;
        }

        $draft_result = self::run_ai_request(
            $workflow_models['draft'],
            self::draft_prompt_template(),
            $payload
        );
        if ( is_wp_error( $draft_result ) ) {
            return $draft_result;
        }

        $draft = self::normalize_generated_result( $draft_result );
        $review = self::run_review_stage( $workflow_models['reviewers'], $payload, $draft );
        if ( is_wp_error( $review ) ) {
            return $review;
        }

        $validation = self::run_validation_stage(
            $workflow_models['target_validation'],
            $workflow_models['platform_key'],
            $payload,
            $draft,
            $review
        );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        return self::compose_workflow_result( $payload, $workflow_models, $draft, $review, $validation );
    }

    private static function build_generation_payload( $form ) {
        return array(
            'user_query' => $form['query_keyword'],
            'target_ai_platform' => $form['target_platform'],
            'target_keyword' => $form['target_keyword'],
            'brand_name' => $form['brand_name'],
            'landing_url' => $form['landing_url'],
            'site_positioning' => $form['site_positioning'],
            'source_links' => self::parse_source_lines( $form['source_links'], 20 ),
            'global_brand_profile' => class_exists( 'QiGeo_Brand_Entity_Library' ) ? QiGeo_Brand_Entity_Library::get_global_profile_context() : array(),
            'business_summary' => $form['business_summary'],
            'selling_points' => self::parse_plain_lines( $form['selling_points'], 20 ),
            'proof_points' => self::parse_plain_lines( $form['proof_points'], 20 ),
            'notes' => self::parse_plain_lines( $form['notes'], 20 ),
        );
    }

    private static function run_ai_request( $provider_model, $prompt, $payload, $runtime_override = array() ) {
        if ( ! is_array( $provider_model ) || empty( $provider_model['provider'] ) || empty( $provider_model['model'] ) ) {
            return new WP_Error( 'qigeo_external_model_packet_invalid', '模型路由失败，请检查平台和模型配置。' );
        }

        if ( ! is_array( $runtime_override ) || empty( $runtime_override ) ) {
            $runtime_override = QiGeo_AI_Client::get_interactive_runtime();
        }

        return QiGeo_AI_Client::analyze_post(
            $provider_model['provider'],
            $provider_model['model'],
            $prompt,
            $payload,
            $runtime_override
        );
    }

    private static function run_review_stage( $review_packets, $payload, $draft ) {
        $successful = array();
        $last_error = null;

        foreach ( (array) $review_packets as $review_packet ) {
            $review_payload = array(
                'query_context' => $payload,
                'draft_material' => $draft,
            );

            $review_result = self::run_ai_request(
                $review_packet,
                self::review_prompt_template(),
                $review_payload
            );

            if ( is_wp_error( $review_result ) ) {
                $last_error = $review_result;
                continue;
            }

            $normalized = self::normalize_review_result( $review_result );
            $normalized['provider_id'] = isset( $review_packet['provider_id'] ) ? (string) $review_packet['provider_id'] : '';
            $normalized['provider_type'] = isset( $review_packet['provider_type'] ) ? (string) $review_packet['provider_type'] : '';
            $normalized['model_id'] = isset( $review_packet['model_id'] ) ? (string) $review_packet['model_id'] : '';
            $normalized['model_name'] = isset( $review_packet['model_name'] ) ? (string) $review_packet['model_name'] : '';
            $successful[] = $normalized;
        }

        if ( empty( $successful ) ) {
            if ( $last_error instanceof WP_Error ) {
                return $last_error;
            }
            return new WP_Error( 'qigeo_external_review_failed', '多模型评分失败，请检查模型配置后重试。' );
        }

        return self::aggregate_review_results( $successful );
    }

    private static function run_validation_stage( $target_packet, $platform_key, $payload, $draft, $review ) {
        $review_avg = isset( $review['average_score'] ) ? absint( $review['average_score'] ) : 0;
        $threshold = isset( $review['pass_threshold'] ) ? absint( $review['pass_threshold'] ) : self::REVIEW_PASS_SCORE;

        if ( empty( $target_packet ) || ! is_array( $target_packet ) ) {
            return array(
                'status' => 'skipped',
                'reason' => '当前目标平台还没有可直连的验收模型，本次只完成了素材整理和多模型评分。',
                'recommended_answer_angles' => array(),
                'natural_mention_examples' => array(),
                'blocking_issues' => array(),
                'retest_queries' => self::build_default_retest_queries( $payload ),
                'next_step' => '先把高质量公开内容发布出来，再补上目标平台模型做验收。',
            );
        }

        if ( $platform_key !== '' && $review_avg < $threshold ) {
            return array(
                'status' => 'skipped',
                'reason' => '多模型平均评分为 ' . $review_avg . '/100，尚未达到 ' . $threshold . ' 分验收线，暂不建议直接进入目标平台验收。',
                'recommended_answer_angles' => array(),
                'natural_mention_examples' => array(),
                'blocking_issues' => isset( $review['gaps'] ) ? (array) $review['gaps'] : array(),
                'retest_queries' => self::build_default_retest_queries( $payload ),
                'next_step' => '先补齐短板和公开素材，再回到目标平台做模拟问答验收。',
            );
        }

        $validation_payload = array(
            'query_context' => $payload,
            'draft_material' => $draft,
            'review_summary' => $review,
        );

        $validation_result = self::run_ai_request(
            $target_packet,
            self::target_validation_prompt_template(),
            $validation_payload
        );
        if ( is_wp_error( $validation_result ) ) {
            return $validation_result;
        }

        $normalized = self::normalize_validation_result( $validation_result );
        $normalized['provider_id'] = isset( $target_packet['provider_id'] ) ? (string) $target_packet['provider_id'] : '';
        $normalized['provider_type'] = isset( $target_packet['provider_type'] ) ? (string) $target_packet['provider_type'] : '';
        $normalized['model_id'] = isset( $target_packet['model_id'] ) ? (string) $target_packet['model_id'] : '';
        $normalized['model_name'] = isset( $target_packet['model_name'] ) ? (string) $target_packet['model_name'] : '';

        return $normalized;
    }

    private static function compose_workflow_result( $payload, $workflow_models, $draft, $review, $validation ) {
        $draft_packet = isset( $workflow_models['draft'] ) && is_array( $workflow_models['draft'] ) ? $workflow_models['draft'] : array();
        $target_packet = isset( $workflow_models['target_validation'] ) && is_array( $workflow_models['target_validation'] ) ? $workflow_models['target_validation'] : array();
        $display_model = ! empty( $target_packet ) ? $target_packet : $draft_packet;

        $validation_status = isset( $validation['status'] ) ? sanitize_key( (string) $validation['status'] ) : 'skipped';
        $average_score = isset( $review['average_score'] ) ? absint( $review['average_score'] ) : 0;
        $threshold = isset( $review['pass_threshold'] ) ? absint( $review['pass_threshold'] ) : self::REVIEW_PASS_SCORE;

        $summary_lines = array(
            '阶段1 起草模型：' . self::format_model_label( $draft_packet ),
            '阶段2 多模型评分：平均 ' . $average_score . '/100（门槛 ' . $threshold . '/100）',
        );
        if ( ! empty( $target_packet ) ) {
            $summary_lines[] = '阶段3 目标平台验收：' . self::format_model_label( $target_packet ) . ' / ' . self::translate_validation_status( $validation_status );
        } else {
            $summary_lines[] = '阶段3 目标平台验收：暂未执行';
        }
        $summary_lines[] = '阶段4 发布与回测：先发布公开素材，再用目标问题持续复测';
        if ( ! empty( $validation['reason'] ) ) {
            $summary_lines[] = '当前结论：' . self::limit_text( (string) $validation['reason'], 220 );
        }

        $review_strength_lines = array_merge(
            array(
                '平均评分：' . $average_score . '/100',
                '评分结论：' . ( ! empty( $review['ready_for_validation'] ) ? '已达到目标平台验收线' : '还没达到目标平台验收线' ),
            ),
            isset( $review['strengths'] ) ? (array) $review['strengths'] : array()
        );

        $trust_lines = array_merge(
            isset( $draft['trust_signals'] ) ? (array) $draft['trust_signals'] : array(),
            isset( $review['gaps'] ) ? (array) $review['gaps'] : array()
        );
        $citation_sources = self::merge_source_items(
            isset( $draft['citation_sources'] ) ? (array) $draft['citation_sources'] : array(),
            isset( $payload['source_links'] ) ? (array) $payload['source_links'] : array()
        );
        if ( isset( $validation['citation_sources_used'] ) ) {
            $citation_sources = self::merge_source_items( $citation_sources, (array) $validation['citation_sources_used'] );
        }
        $source_gaps = self::merge_unique_lists(
            isset( $draft['source_gaps'] ) ? (array) $draft['source_gaps'] : array(),
            isset( $review['source_gaps'] ) ? (array) $review['source_gaps'] : array()
        );
        if ( isset( $review['unsupported_claims'] ) ) {
            foreach ( (array) $review['unsupported_claims'] as $unsupported_claim ) {
                $source_gaps[] = '缺少来源支撑：' . $unsupported_claim;
            }
        }
        $claim_to_source_map = isset( $draft['claim_to_source_map'] ) ? (array) $draft['claim_to_source_map'] : array();
        $publishable_source_assets = self::merge_unique_lists(
            isset( $draft['publishable_source_assets'] ) ? (array) $draft['publishable_source_assets'] : array(),
            self::build_publishable_source_assets( $payload, $review, $validation )
        );
        $risk_claims = self::merge_unique_lists(
            isset( $draft['risk_claims'] ) ? (array) $draft['risk_claims'] : array(),
            isset( $review['unsupported_claims'] ) ? (array) $review['unsupported_claims'] : array()
        );
        $risk_claims = self::merge_unique_lists(
            $risk_claims,
            isset( $review['risk_claims'] ) ? (array) $review['risk_claims'] : array()
        );

        $risk_lines = array_merge(
            isset( $draft['risk_notes'] ) ? (array) $draft['risk_notes'] : array(),
            isset( $review['risk_notes'] ) ? (array) $review['risk_notes'] : array(),
            isset( $validation['blocking_issues'] ) ? (array) $validation['blocking_issues'] : array()
        );

        $execution_steps = self::build_execution_steps( $payload, $review, $validation, $draft );
        $content_assets = self::merge_unique_lists(
            isset( $draft['content_assets'] ) ? (array) $draft['content_assets'] : array(),
            self::build_publish_plan( $payload, $review, $validation )
        );

        $recommendation_positioning = array();
        $recommendation_positioning[] = '目标平台：' . ( isset( $payload['target_ai_platform'] ) ? (string) $payload['target_ai_platform'] : '-' );
        $recommendation_positioning[] = '验收状态：' . self::translate_validation_status( $validation_status );
        if ( ! empty( $validation['reason'] ) ) {
            $recommendation_positioning[] = '验收结论：' . (string) $validation['reason'];
        }
        if ( ! empty( $validation['next_step'] ) ) {
            $recommendation_positioning[] = '下一步：' . (string) $validation['next_step'];
        }

        $workflow_meta = array(
            'platform_key' => isset( $workflow_models['platform_key'] ) ? (string) $workflow_models['platform_key'] : '',
            'draft_model' => self::pack_model_meta( $draft_packet ),
            'review_models' => self::pack_reviewers_meta( isset( $review['reviewers'] ) ? (array) $review['reviewers'] : array() ),
            'target_validation_model' => self::pack_model_meta( $target_packet ),
            'review_average_score' => $average_score,
            'review_threshold' => $threshold,
            'review_ready' => ! empty( $review['ready_for_validation'] ),
            'validation_status' => $validation_status,
            'source_count' => count( $citation_sources ),
            'source_gap_count' => count( $source_gaps ),
            'risk_claim_count' => count( $risk_claims ),
            'source_coverage_score' => isset( $review['source_coverage_score'] ) ? absint( $review['source_coverage_score'] ) : 0,
        );

        return array(
            'summary' => implode( "\n", $summary_lines ),
            'recommendation_positioning' => implode( "\n", array_filter( $recommendation_positioning ) ),
            'intent_breakdown' => isset( $draft['intent_breakdown'] ) ? (array) $draft['intent_breakdown'] : array(),
            'answer_angles' => isset( $draft['answer_angles'] ) ? (array) $draft['answer_angles'] : array(),
            'mention_phrases' => self::normalize_string_list( $review_strength_lines, 15 ),
            'natural_mention_examples' => self::normalize_string_list(
                ! empty( $validation['natural_mention_examples'] ) ? $validation['natural_mention_examples'] : $draft['natural_mention_examples'],
                12
            ),
            'faq' => isset( $draft['faq'] ) ? (array) $draft['faq'] : array(),
            'content_assets' => self::normalize_string_list( $content_assets, 15 ),
            'trust_signals' => self::normalize_string_list( $trust_lines, 15 ),
            'citation_sources' => self::normalize_source_items( $citation_sources, 15 ),
            'source_gaps' => self::normalize_string_list( $source_gaps, 15 ),
            'publishable_source_assets' => self::normalize_string_list( $publishable_source_assets, 15 ),
            'claim_to_source_map' => self::normalize_string_list( $claim_to_source_map, 20 ),
            'risk_claims' => self::normalize_string_list( $risk_claims, 20 ),
            'risk_notes' => self::normalize_string_list( $risk_lines, 15 ),
            'execution_steps' => self::normalize_string_list( $execution_steps, 15 ),
            'workflow' => $workflow_meta,
            'provider_id' => isset( $display_model['provider_id'] ) ? (string) $display_model['provider_id'] : '',
            'model_id' => isset( $display_model['model_id'] ) ? (string) $display_model['model_id'] : '',
            'model_name' => isset( $display_model['model_name'] ) ? (string) $display_model['model_name'] : '',
        );
    }

    private static function build_execution_steps( $payload, $review, $validation, $draft ) {
        $steps = array();
        $query = isset( $payload['user_query'] ) ? (string) $payload['user_query'] : '目标问题';
        $target_platform = isset( $payload['target_ai_platform'] ) ? (string) $payload['target_ai_platform'] : '目标平台';

        foreach ( (array) ( isset( $review['improvement_actions'] ) ? $review['improvement_actions'] : array() ) as $item ) {
            $steps[] = '先补强：' . $item;
        }

        foreach ( (array) ( isset( $draft['content_assets'] ) ? $draft['content_assets'] : array() ) as $asset ) {
            $steps[] = '发布内容：' . $asset;
        }

        if ( ! empty( $validation['retest_queries'] ) ) {
            foreach ( (array) $validation['retest_queries'] as $query_item ) {
                $steps[] = '回测问题：在 ' . $target_platform . ' 里测试“' . $query_item . '”';
            }
        } else {
            $steps[] = '回测问题：在 ' . $target_platform . ' 里测试“' . $query . '”';
        }

        if ( ! empty( $validation['next_step'] ) ) {
            $steps[] = '验收后动作：' . $validation['next_step'];
        }

        return self::normalize_string_list( $steps, 15 );
    }

    private static function build_publish_plan( $payload, $review, $validation ) {
        $assets = array();
        $target_keyword = isset( $payload['target_keyword'] ) ? (string) $payload['target_keyword'] : '目标词';
        $query = isset( $payload['user_query'] ) ? (string) $payload['user_query'] : '目标问题';

        $assets[] = '围绕“' . $query . '”做一页可公开抓取的专题内容';
        $assets[] = '补一页“' . $target_keyword . '”的对比 / 适用人群 / 不适用人群说明';

        if ( ! empty( $review['gaps'] ) ) {
            foreach ( (array) $review['gaps'] as $gap ) {
                $assets[] = '针对短板补内容：' . $gap;
            }
        }

        if ( ! empty( $review['source_gaps'] ) ) {
            foreach ( (array) $review['source_gaps'] as $gap ) {
                $assets[] = '补充可引用来源：' . $gap;
            }
        }

        if ( ! empty( $validation['recommended_answer_angles'] ) ) {
            foreach ( (array) $validation['recommended_answer_angles'] as $angle ) {
                $assets[] = '按目标平台偏好补充角度：' . $angle;
            }
        }

        return self::normalize_string_list( $assets, 15 );
    }

    private static function build_publishable_source_assets( $payload, $review, $validation ) {
        $assets = array();
        $target_keyword = isset( $payload['target_keyword'] ) ? (string) $payload['target_keyword'] : '目标词';
        $query = isset( $payload['user_query'] ) ? (string) $payload['user_query'] : '目标问题';

        $assets[] = '官网定位页：说明“' . $target_keyword . '”适合哪些人、哪些场景、不适合哪些场景';
        $assets[] = 'FAQ 来源页：围绕“' . $query . '”整理可公开引用的问答';
        $assets[] = '证据页：集中放置文档、案例、更新日志或可验证截图';

        if ( ! empty( $review['source_gaps'] ) ) {
            foreach ( (array) $review['source_gaps'] as $gap ) {
                $assets[] = '补来源资产：' . $gap;
            }
        }

        if ( ! empty( $validation['blocking_issues'] ) ) {
            foreach ( (array) $validation['blocking_issues'] as $issue ) {
                $assets[] = '补验收阻塞来源：' . $issue;
            }
        }

        return self::normalize_string_list( $assets, 15 );
    }

    private static function resolve_storage_model_packet( $workflow_models, $result ) {
        $workflow = isset( $result['workflow'] ) && is_array( $result['workflow'] ) ? $result['workflow'] : array();
        if ( isset( $workflow['target_validation_model'] ) && is_array( $workflow['target_validation_model'] ) && ! empty( $workflow['target_validation_model']['model_id'] ) ) {
            return $workflow['target_validation_model'];
        }
        if ( isset( $workflow['draft_model'] ) && is_array( $workflow['draft_model'] ) && ! empty( $workflow['draft_model']['model_id'] ) ) {
            return $workflow['draft_model'];
        }
        if ( isset( $workflow_models['target_validation'] ) && is_array( $workflow_models['target_validation'] ) && ! empty( $workflow_models['target_validation']['model_id'] ) ) {
            return $workflow_models['target_validation'];
        }
        if ( isset( $workflow_models['draft'] ) && is_array( $workflow_models['draft'] ) ) {
            return $workflow_models['draft'];
        }

        return array(
            'provider_id' => '',
            'model_id' => '',
            'model_name' => '',
        );
    }

    private static function get_empty_form_data() {
        return array(
            'record_id' => 0,
            'model_id' => '',
            'query_keyword' => '',
            'target_platform' => '',
            'target_keyword' => '',
            'brand_name' => '',
            'landing_url' => '',
            'site_positioning' => '',
            'source_links' => '',
            'business_summary' => '',
            'selling_points' => '',
            'proof_points' => '',
            'notes' => '',
            'workflow_meta_json' => '',
            'summary' => '',
            'recommendation_positioning' => '',
            'intent_breakdown' => '',
            'answer_angles' => '',
            'mention_phrases' => '',
            'natural_mention_examples' => '',
            'faq_lines' => '',
            'content_assets' => '',
            'trust_signals' => '',
            'citation_sources' => '',
            'source_gaps' => '',
            'publishable_source_assets' => '',
            'claim_to_source_map' => '',
            'risk_claims' => '',
            'risk_notes' => '',
            'execution_steps' => '',
        );
    }

    private static function record_to_form_data( $record ) {
        $form = self::get_empty_form_data();
        $form['record_id'] = isset( $record['id'] ) ? absint( $record['id'] ) : 0;
        $form['model_id'] = isset( $record['model_id'] ) ? sanitize_key( (string) $record['model_id'] ) : '';
        $form['query_keyword'] = isset( $record['query_keyword'] ) ? sanitize_text_field( (string) $record['query_keyword'] ) : '';
        $form['target_platform'] = isset( $record['target_platform'] ) ? sanitize_text_field( (string) $record['target_platform'] ) : '';
        $form['target_keyword'] = isset( $record['target_keyword'] ) ? sanitize_text_field( (string) $record['target_keyword'] ) : '';
        $form['brand_name'] = isset( $record['brand_name'] ) ? sanitize_text_field( (string) $record['brand_name'] ) : '';
        $form['landing_url'] = isset( $record['landing_url'] ) ? esc_url_raw( (string) $record['landing_url'] ) : '';
        $form['site_positioning'] = isset( $record['site_positioning'] ) ? sanitize_textarea_field( (string) $record['site_positioning'] ) : '';
        $form['source_links'] = isset( $record['source_links'] ) ? sanitize_textarea_field( (string) $record['source_links'] ) : '';
        $form['business_summary'] = isset( $record['business_summary'] ) ? sanitize_textarea_field( (string) $record['business_summary'] ) : '';
        $form['selling_points'] = isset( $record['selling_points'] ) ? sanitize_textarea_field( (string) $record['selling_points'] ) : '';
        $form['proof_points'] = isset( $record['proof_points'] ) ? sanitize_textarea_field( (string) $record['proof_points'] ) : '';
        $form['notes'] = isset( $record['notes'] ) ? sanitize_textarea_field( (string) $record['notes'] ) : '';

        $result = array();
        if ( ! empty( $record['result_json'] ) ) {
            $decoded = json_decode( (string) $record['result_json'], true );
            if ( is_array( $decoded ) ) {
                $result = $decoded;
            }
        }

        $form['summary'] = isset( $result['summary'] ) ? sanitize_textarea_field( (string) $result['summary'] ) : '';
        if ( $form['summary'] === '' && ! empty( $record['result_summary'] ) ) {
            $form['summary'] = sanitize_textarea_field( (string) $record['result_summary'] );
        }
        $form['recommendation_positioning'] = isset( $result['recommendation_positioning'] ) ? sanitize_textarea_field( (string) $result['recommendation_positioning'] ) : '';
        $form['intent_breakdown'] = self::lines_from_array( isset( $result['intent_breakdown'] ) ? $result['intent_breakdown'] : array() );
        $form['answer_angles'] = self::lines_from_array( isset( $result['answer_angles'] ) ? $result['answer_angles'] : array() );
        $form['mention_phrases'] = self::lines_from_array( isset( $result['mention_phrases'] ) ? $result['mention_phrases'] : array() );
        $form['natural_mention_examples'] = self::lines_from_array( isset( $result['natural_mention_examples'] ) ? $result['natural_mention_examples'] : array() );
        $form['faq_lines'] = self::faq_to_lines( isset( $result['faq'] ) ? $result['faq'] : array() );
        $form['content_assets'] = self::lines_from_array( isset( $result['content_assets'] ) ? $result['content_assets'] : array() );
        $form['trust_signals'] = self::lines_from_array( isset( $result['trust_signals'] ) ? $result['trust_signals'] : array() );
        $form['citation_sources'] = self::source_items_to_lines( isset( $result['citation_sources'] ) ? $result['citation_sources'] : array() );
        $form['source_gaps'] = self::lines_from_array( isset( $result['source_gaps'] ) ? $result['source_gaps'] : array() );
        $form['publishable_source_assets'] = self::lines_from_array( isset( $result['publishable_source_assets'] ) ? $result['publishable_source_assets'] : array() );
        $form['claim_to_source_map'] = self::lines_from_array( isset( $result['claim_to_source_map'] ) ? $result['claim_to_source_map'] : array() );
        $form['risk_claims'] = self::lines_from_array( isset( $result['risk_claims'] ) ? $result['risk_claims'] : array() );
        $form['risk_notes'] = self::lines_from_array( isset( $result['risk_notes'] ) ? $result['risk_notes'] : array() );
        $form['execution_steps'] = self::lines_from_array( isset( $result['execution_steps'] ) ? $result['execution_steps'] : array() );
        $workflow_meta = isset( $result['workflow'] ) && is_array( $result['workflow'] ) ? $result['workflow'] : array();
        if ( isset( $workflow_meta['draft_model']['model_id'] ) && is_scalar( $workflow_meta['draft_model']['model_id'] ) ) {
            $form['model_id'] = sanitize_key( (string) $workflow_meta['draft_model']['model_id'] );
        }
        $form['workflow_meta_json'] = ! empty( $workflow_meta )
            ? wp_json_encode( $workflow_meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            : '';

        return $form;
    }

    private static function get_request_form_data() {
        return array(
            'record_id' => isset( $_POST['record_id'] ) ? absint( wp_unslash( (string) $_POST['record_id'] ) ) : 0,
            'model_id' => isset( $_POST['model_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['model_id'] ) ) : '',
            'query_keyword' => self::limit_text(
                isset( $_POST['query_keyword'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['query_keyword'] ) ) : '',
                255
            ),
            'target_platform' => self::limit_text(
                isset( $_POST['target_platform'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_platform'] ) ) : '',
                120
            ),
            'target_keyword' => self::limit_text(
                isset( $_POST['target_keyword'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_keyword'] ) ) : '',
                255
            ),
            'brand_name' => self::limit_text(
                isset( $_POST['brand_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['brand_name'] ) ) : '',
                190
            ),
            'landing_url' => isset( $_POST['landing_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['landing_url'] ) ) : '',
            'site_positioning' => isset( $_POST['site_positioning'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['site_positioning'] ) ) : '',
            'source_links' => isset( $_POST['source_links'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['source_links'] ) ) : '',
            'business_summary' => isset( $_POST['business_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['business_summary'] ) ) : '',
            'selling_points' => isset( $_POST['selling_points'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['selling_points'] ) ) : '',
            'proof_points' => isset( $_POST['proof_points'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['proof_points'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        );
    }

    private static function get_result_data_from_request() {
        $workflow = array();
        if ( isset( $_POST['workflow_meta_json'] ) ) {
            $workflow_json = wp_unslash( (string) $_POST['workflow_meta_json'] );
            $decoded_workflow = json_decode( $workflow_json, true );
            if ( is_array( $decoded_workflow ) ) {
                $workflow = $decoded_workflow;
            }
        }

        return array(
            'summary' => self::limit_text(
                isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '',
                800
            ),
            'recommendation_positioning' => self::limit_text(
                isset( $_POST['recommendation_positioning'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['recommendation_positioning'] ) ) : '',
                800
            ),
            'intent_breakdown' => self::parse_plain_lines(
                isset( $_POST['intent_breakdown'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['intent_breakdown'] ) ) : '',
                12
            ),
            'answer_angles' => self::parse_plain_lines(
                isset( $_POST['answer_angles'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['answer_angles'] ) ) : '',
                12
            ),
            'mention_phrases' => self::parse_plain_lines(
                isset( $_POST['mention_phrases'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['mention_phrases'] ) ) : '',
                15
            ),
            'natural_mention_examples' => self::parse_plain_lines(
                isset( $_POST['natural_mention_examples'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['natural_mention_examples'] ) ) : '',
                12
            ),
            'faq' => self::parse_faq_lines(
                isset( $_POST['faq_lines'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['faq_lines'] ) ) : '',
                12
            ),
            'content_assets' => self::parse_plain_lines(
                isset( $_POST['content_assets'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['content_assets'] ) ) : '',
                15
            ),
            'trust_signals' => self::parse_plain_lines(
                isset( $_POST['trust_signals'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['trust_signals'] ) ) : '',
                15
            ),
            'citation_sources' => self::parse_source_lines(
                isset( $_POST['citation_sources'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['citation_sources'] ) ) : '',
                15
            ),
            'source_gaps' => self::parse_plain_lines(
                isset( $_POST['source_gaps'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['source_gaps'] ) ) : '',
                15
            ),
            'publishable_source_assets' => self::parse_plain_lines(
                isset( $_POST['publishable_source_assets'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['publishable_source_assets'] ) ) : '',
                15
            ),
            'claim_to_source_map' => self::parse_plain_lines(
                isset( $_POST['claim_to_source_map'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['claim_to_source_map'] ) ) : '',
                20
            ),
            'risk_claims' => self::parse_plain_lines(
                isset( $_POST['risk_claims'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['risk_claims'] ) ) : '',
                20
            ),
            'risk_notes' => self::parse_plain_lines(
                isset( $_POST['risk_notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['risk_notes'] ) ) : '',
                15
            ),
            'execution_steps' => self::parse_plain_lines(
                isset( $_POST['execution_steps'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['execution_steps'] ) ) : '',
                15
            ),
            'workflow' => $workflow,
        );
    }

    private static function validate_base_form_data( $form ) {
        if ( empty( $form['query_keyword'] ) ) {
            return new WP_Error( 'qigeo_external_query_required', '请先填写用户提问词 / 外部关键词' );
        }

        if ( empty( $form['target_platform'] ) ) {
            return new WP_Error( 'qigeo_external_platform_required', '请先填写目标 AI 平台' );
        }

        if ( empty( $form['target_keyword'] ) ) {
            return new WP_Error( 'qigeo_external_target_required', '请先填写希望被推荐的词' );
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
            $where .= ' AND (query_keyword LIKE %s OR target_platform LIKE %s OR target_keyword LIKE %s OR brand_name LIKE %s OR result_summary LIKE %s)';
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

        $table = self::get_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                $record_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'qigeo_external_keywords';
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

    private static function resolve_workflow_models( $preferred_model_id, $target_platform = '' ) {
        $platform_key = self::normalize_platform_key( $target_platform );
        $packets = self::get_all_usable_model_packets();
        if ( empty( $packets ) ) {
            return new WP_Error( 'qigeo_external_model_missing', '请先在启灵GEO配置并启用至少一个可用模型' );
        }

        $preferred_packet = null;
        if ( $preferred_model_id !== '' ) {
            foreach ( $packets as $packet ) {
                if ( isset( $packet['model_id'] ) && sanitize_key( (string) $packet['model_id'] ) === sanitize_key( (string) $preferred_model_id ) ) {
                    $preferred_packet = $packet;
                    break;
                }
            }
        }

        $target_packet = null;
        if ( $platform_key !== '' ) {
            foreach ( $packets as $packet ) {
                if ( self::provider_matches_platform( $packet, $platform_key ) ) {
                    $target_packet = $packet;
                    break;
                }
            }
        }

        $draft_packet = null;
        if ( $preferred_packet && ( $platform_key === '' || ! self::provider_matches_platform( $preferred_packet, $platform_key ) ) ) {
            $draft_packet = $preferred_packet;
        }

        if ( ! $draft_packet ) {
            foreach ( $packets as $packet ) {
                if ( $platform_key !== '' && self::provider_matches_platform( $packet, $platform_key ) ) {
                    continue;
                }
                $draft_packet = $packet;
                break;
            }
        }

        if ( ! $draft_packet ) {
            $draft_packet = $preferred_packet ? $preferred_packet : $packets[0];
        }

        $review_packets = array();
        foreach ( $packets as $packet ) {
            if ( $platform_key !== '' && self::provider_matches_platform( $packet, $platform_key ) ) {
                continue;
            }
            if ( isset( $draft_packet['model_id'] ) && isset( $packet['model_id'] ) && $packet['model_id'] === $draft_packet['model_id'] ) {
                continue;
            }
            $review_packets[] = $packet;
            if ( count( $review_packets ) >= self::REVIEW_MODEL_LIMIT ) {
                break;
            }
        }

        if ( empty( $review_packets ) && $draft_packet ) {
            $review_packets[] = $draft_packet;
        }

        return array(
            'platform_key' => $platform_key,
            'preferred' => $preferred_packet,
            'draft' => $draft_packet,
            'reviewers' => $review_packets,
            'target_validation' => $target_packet,
        );
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

    private static function build_provider_model_packet( $model_id ) {
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

        foreach ( $models as $model ) {
            if ( empty( $model['id'] ) || sanitize_key( (string) $model['id'] ) !== $model_id || ! self::model_usable( $model ) ) {
                continue;
            }

            $provider_id = isset( $model['provider_id'] ) ? (string) $model['provider_id'] : '';
            if ( $provider_id === '' || ! isset( $provider_map[ $provider_id ] ) ) {
                return new WP_Error( 'qigeo_external_provider_invalid', '模型已启用，但绑定的平台不可用' );
            }

            return array(
                'provider_id' => $provider_id,
                'provider_type' => isset( $provider_map[ $provider_id ]['type'] ) ? sanitize_key( (string) $provider_map[ $provider_id ]['type'] ) : '',
                'model_id' => (string) $model['id'],
                'model_name' => ! empty( $model['model'] ) ? (string) $model['model'] : '',
                'provider' => $provider_map[ $provider_id ],
                'model' => $model,
            );
        }

        return new WP_Error( 'qigeo_external_model_missing', '未找到可用模型' );
    }

    private static function get_first_usable_model_id( $platform_key = '' ) {
        $options = self::get_enabled_model_options();
        if ( empty( $options ) ) {
            return '';
        }

        foreach ( $options as $option ) {
            if ( $platform_key !== '' ) {
                $option_provider_type = isset( $option['provider_type'] ) ? sanitize_key( (string) $option['provider_type'] ) : '';
                if ( $option_provider_type !== $platform_key ) {
                    continue;
                }
            }

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

    private static function normalize_review_result( $result ) {
        $parsed = isset( $result['parsed'] ) && is_array( $result['parsed'] ) ? $result['parsed'] : array();

        return array(
            'score' => self::normalize_score( isset( $parsed['score'] ) ? $parsed['score'] : 0 ),
            'readiness' => isset( $parsed['readiness'] ) ? sanitize_key( (string) $parsed['readiness'] ) : '',
            'strengths' => self::normalize_string_list( isset( $parsed['strengths'] ) ? $parsed['strengths'] : array(), 10 ),
            'gaps' => self::normalize_string_list(
                isset( $parsed['gaps'] ) ? $parsed['gaps'] : ( isset( $parsed['weaknesses'] ) ? $parsed['weaknesses'] : array() ),
                10
            ),
            'risk_notes' => self::normalize_string_list( isset( $parsed['risk_notes'] ) ? $parsed['risk_notes'] : array(), 10 ),
            'improvement_actions' => self::normalize_string_list( isset( $parsed['improvement_actions'] ) ? $parsed['improvement_actions'] : array(), 10 ),
            'source_coverage_score' => self::normalize_score( isset( $parsed['source_coverage_score'] ) ? $parsed['source_coverage_score'] : 0 ),
            'source_gaps' => self::normalize_string_list( isset( $parsed['source_gaps'] ) ? $parsed['source_gaps'] : array(), 10 ),
            'unsupported_claims' => self::normalize_string_list( isset( $parsed['unsupported_claims'] ) ? $parsed['unsupported_claims'] : array(), 10 ),
            'risk_claims' => self::normalize_string_list( isset( $parsed['risk_claims'] ) ? $parsed['risk_claims'] : array(), 10 ),
        );
    }

    private static function aggregate_review_results( $reviews ) {
        $scores = array();
        $strengths = array();
        $gaps = array();
        $risk_notes = array();
        $actions = array();
        $source_gaps = array();
        $unsupported_claims = array();
        $risk_claims = array();
        $source_scores = array();
        $reviewers = array();

        foreach ( (array) $reviews as $review ) {
            $scores[] = isset( $review['score'] ) ? absint( $review['score'] ) : 0;
            if ( isset( $review['source_coverage_score'] ) ) {
                $source_scores[] = absint( $review['source_coverage_score'] );
            }
            $strengths = self::merge_unique_lists( $strengths, isset( $review['strengths'] ) ? (array) $review['strengths'] : array() );
            $gaps = self::merge_unique_lists( $gaps, isset( $review['gaps'] ) ? (array) $review['gaps'] : array() );
            $risk_notes = self::merge_unique_lists( $risk_notes, isset( $review['risk_notes'] ) ? (array) $review['risk_notes'] : array() );
            $actions = self::merge_unique_lists( $actions, isset( $review['improvement_actions'] ) ? (array) $review['improvement_actions'] : array() );
            $source_gaps = self::merge_unique_lists( $source_gaps, isset( $review['source_gaps'] ) ? (array) $review['source_gaps'] : array() );
            $unsupported_claims = self::merge_unique_lists( $unsupported_claims, isset( $review['unsupported_claims'] ) ? (array) $review['unsupported_claims'] : array() );
            $risk_claims = self::merge_unique_lists( $risk_claims, isset( $review['risk_claims'] ) ? (array) $review['risk_claims'] : array() );
            $reviewers[] = array(
                'provider_id' => isset( $review['provider_id'] ) ? (string) $review['provider_id'] : '',
                'provider_type' => isset( $review['provider_type'] ) ? (string) $review['provider_type'] : '',
                'model_id' => isset( $review['model_id'] ) ? (string) $review['model_id'] : '',
                'model_name' => isset( $review['model_name'] ) ? (string) $review['model_name'] : '',
                'score' => isset( $review['score'] ) ? absint( $review['score'] ) : 0,
                'readiness' => isset( $review['readiness'] ) ? (string) $review['readiness'] : '',
            );
        }

        $average_score = ! empty( $scores ) ? (int) round( array_sum( $scores ) / count( $scores ) ) : 0;
        $source_coverage_score = ! empty( $source_scores ) ? (int) round( array_sum( $source_scores ) / count( $source_scores ) ) : 0;
        $pass_threshold = self::REVIEW_PASS_SCORE;

        return array(
            'average_score' => $average_score,
            'pass_threshold' => $pass_threshold,
            'ready_for_validation' => $average_score >= $pass_threshold,
            'strengths' => self::normalize_string_list( $strengths, 12 ),
            'gaps' => self::normalize_string_list( $gaps, 12 ),
            'risk_notes' => self::normalize_string_list( $risk_notes, 12 ),
            'improvement_actions' => self::normalize_string_list( $actions, 12 ),
            'source_coverage_score' => $source_coverage_score,
            'source_gaps' => self::normalize_string_list( $source_gaps, 12 ),
            'unsupported_claims' => self::normalize_string_list( $unsupported_claims, 12 ),
            'risk_claims' => self::normalize_string_list( $risk_claims, 12 ),
            'reviewers' => $reviewers,
        );
    }

    private static function normalize_validation_result( $result ) {
        $parsed = isset( $result['parsed'] ) && is_array( $result['parsed'] ) ? $result['parsed'] : array();
        $status = isset( $parsed['validation_status'] ) ? sanitize_key( (string) $parsed['validation_status'] ) : 'caution';
        if ( ! in_array( $status, array( 'pass', 'caution', 'fail', 'skipped' ), true ) ) {
            $status = 'caution';
        }

        return array(
            'status' => $status,
            'reason' => self::limit_text(
                isset( $parsed['reason'] ) ? sanitize_textarea_field( (string) $parsed['reason'] ) : '',
                500
            ),
            'recommended_answer_angles' => self::normalize_string_list(
                isset( $parsed['recommended_answer_angles'] ) ? $parsed['recommended_answer_angles'] : array(),
                10
            ),
            'natural_mention_examples' => self::normalize_string_list(
                isset( $parsed['natural_mention_examples'] ) ? $parsed['natural_mention_examples'] : array(),
                10
            ),
            'blocking_issues' => self::normalize_string_list(
                isset( $parsed['blocking_issues'] ) ? $parsed['blocking_issues'] : array(),
                10
            ),
            'retest_queries' => self::normalize_string_list(
                isset( $parsed['retest_queries'] ) ? $parsed['retest_queries'] : array(),
                8
            ),
            'next_step' => self::limit_text(
                isset( $parsed['recommended_next_step'] ) ? sanitize_textarea_field( (string) $parsed['recommended_next_step'] ) : '',
                300
            ),
            'citation_sources_used' => self::normalize_source_items(
                isset( $parsed['citation_sources_used'] ) ? $parsed['citation_sources_used'] : array(),
                10
            ),
        );
    }

    private static function normalize_generated_result( $result ) {
        $parsed = isset( $result['parsed'] ) && is_array( $result['parsed'] ) ? $result['parsed'] : array();

        return array(
            'summary' => self::limit_text(
                isset( $parsed['summary'] ) ? sanitize_textarea_field( (string) $parsed['summary'] ) : '',
                800
            ),
            'recommendation_positioning' => self::limit_text(
                isset( $parsed['recommendation_positioning'] )
                    ? sanitize_textarea_field( (string) $parsed['recommendation_positioning'] )
                    : ( isset( $parsed['positioning'] ) ? sanitize_textarea_field( (string) $parsed['positioning'] ) : '' ),
                800
            ),
            'intent_breakdown' => self::normalize_string_list(
                isset( $parsed['intent_breakdown'] ) ? $parsed['intent_breakdown'] : array(),
                12
            ),
            'answer_angles' => self::normalize_string_list(
                isset( $parsed['answer_angles'] ) ? $parsed['answer_angles'] : array(),
                12
            ),
            'mention_phrases' => self::normalize_string_list(
                isset( $parsed['mention_phrases'] ) ? $parsed['mention_phrases'] : array(),
                15
            ),
            'natural_mention_examples' => self::normalize_string_list(
                isset( $parsed['natural_mention_examples'] )
                    ? $parsed['natural_mention_examples']
                    : ( isset( $parsed['sample_recommendations'] ) ? $parsed['sample_recommendations'] : array() ),
                12
            ),
            'faq' => self::normalize_faq_items(
                isset( $parsed['faq'] ) ? $parsed['faq'] : array(),
                12
            ),
            'content_assets' => self::normalize_string_list(
                isset( $parsed['content_assets'] ) ? $parsed['content_assets'] : array(),
                15
            ),
            'trust_signals' => self::normalize_string_list(
                isset( $parsed['trust_signals'] ) ? $parsed['trust_signals'] : array(),
                15
            ),
            'citation_sources' => self::normalize_source_items(
                isset( $parsed['citation_sources'] ) ? $parsed['citation_sources'] : array(),
                15
            ),
            'source_gaps' => self::normalize_string_list(
                isset( $parsed['source_gaps'] ) ? $parsed['source_gaps'] : array(),
                15
            ),
            'publishable_source_assets' => self::normalize_string_list(
                isset( $parsed['publishable_source_assets'] ) ? $parsed['publishable_source_assets'] : array(),
                15
            ),
            'claim_to_source_map' => self::normalize_string_list(
                isset( $parsed['claim_to_source_map'] ) ? $parsed['claim_to_source_map'] : array(),
                20
            ),
            'risk_claims' => self::normalize_string_list(
                isset( $parsed['risk_claims'] ) ? $parsed['risk_claims'] : array(),
                20
            ),
            'risk_notes' => self::normalize_string_list(
                isset( $parsed['risk_notes'] ) ? $parsed['risk_notes'] : array(),
                15
            ),
            'execution_steps' => self::normalize_string_list(
                isset( $parsed['execution_steps'] ) ? $parsed['execution_steps'] : array(),
                15
            ),
        );
    }

    private static function normalize_score( $value ) {
        if ( is_numeric( $value ) ) {
            $score = (int) round( (float) $value );
        } else {
            $text = trim( (string) $value );
            $score = preg_match( '/(\d{1,3})/', $text, $matches ) ? (int) $matches[1] : 0;
        }

        if ( $score < 0 ) {
            $score = 0;
        }
        if ( $score > 100 ) {
            $score = 100;
        }

        return $score;
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

    private static function normalize_faq_items( $items, $limit = 10 ) {
        $normalized = array();
        foreach ( (array) $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $question = '';
            $answer = '';

            if ( isset( $item['q'] ) ) {
                $question = trim( sanitize_text_field( (string) $item['q'] ) );
            } elseif ( isset( $item['question'] ) ) {
                $question = trim( sanitize_text_field( (string) $item['question'] ) );
            }

            if ( isset( $item['a'] ) ) {
                $answer = trim( sanitize_textarea_field( (string) $item['a'] ) );
            } elseif ( isset( $item['answer'] ) ) {
                $answer = trim( sanitize_textarea_field( (string) $item['answer'] ) );
            }

            if ( $question === '' || $answer === '' ) {
                continue;
            }

            $normalized[] = array(
                'q' => self::limit_text( $question, 200 ),
                'a' => self::limit_text( $answer, 500 ),
            );

            if ( count( $normalized ) >= $limit ) {
                break;
            }
        }

        return $normalized;
    }

    private static function normalize_source_items( $items, $limit = 10 ) {
        $normalized = array();
        $seen = array();

        foreach ( (array) $items as $item ) {
            $source = self::normalize_source_item( $item );
            if ( empty( $source ) ) {
                continue;
            }

            $fingerprint = ! empty( $source['url'] ) ? $source['url'] : self::source_item_to_line( $source );
            if ( isset( $seen[ $fingerprint ] ) ) {
                continue;
            }

            $seen[ $fingerprint ] = true;
            $normalized[] = $source;
            if ( count( $normalized ) >= $limit ) {
                break;
            }
        }

        return $normalized;
    }

    private static function normalize_source_item( $item ) {
        if ( is_scalar( $item ) ) {
            $line = trim( sanitize_textarea_field( (string) $item ) );
            if ( $line === '' ) {
                return array();
            }
            $parsed = self::parse_source_line( $line );
            return ! empty( $parsed ) ? $parsed : array(
                'title' => self::limit_text( $line, 160 ),
                'url' => '',
                'claim' => '',
                'source_type' => '',
                'published_at' => '',
                'is_official' => false,
                'priority' => '',
            );
        }

        if ( ! is_array( $item ) ) {
            return array();
        }

        $title = '';
        if ( isset( $item['title'] ) && is_scalar( $item['title'] ) ) {
            $title = trim( sanitize_text_field( (string) $item['title'] ) );
        } elseif ( isset( $item['name'] ) && is_scalar( $item['name'] ) ) {
            $title = trim( sanitize_text_field( (string) $item['name'] ) );
        }

        $url = '';
        if ( isset( $item['url'] ) && is_scalar( $item['url'] ) ) {
            $url = esc_url_raw( (string) $item['url'] );
        }

        $claim = '';
        if ( isset( $item['claim'] ) && is_scalar( $item['claim'] ) ) {
            $claim = trim( sanitize_textarea_field( (string) $item['claim'] ) );
        } elseif ( isset( $item['supports'] ) && is_scalar( $item['supports'] ) ) {
            $claim = trim( sanitize_textarea_field( (string) $item['supports'] ) );
        } elseif ( isset( $item['evidence'] ) && is_scalar( $item['evidence'] ) ) {
            $claim = trim( sanitize_textarea_field( (string) $item['evidence'] ) );
        }

        $source_type = '';
        if ( isset( $item['source_type'] ) && is_scalar( $item['source_type'] ) ) {
            $source_type = self::normalize_source_type( (string) $item['source_type'] );
        } elseif ( isset( $item['type'] ) && is_scalar( $item['type'] ) ) {
            $source_type = self::normalize_source_type( (string) $item['type'] );
        }

        $published_at = '';
        if ( isset( $item['published_at'] ) && is_scalar( $item['published_at'] ) ) {
            $published_at = self::normalize_source_date( (string) $item['published_at'] );
        } elseif ( isset( $item['updated_at'] ) && is_scalar( $item['updated_at'] ) ) {
            $published_at = self::normalize_source_date( (string) $item['updated_at'] );
        } elseif ( isset( $item['date'] ) && is_scalar( $item['date'] ) ) {
            $published_at = self::normalize_source_date( (string) $item['date'] );
        }

        $is_official = false;
        if ( isset( $item['is_official'] ) ) {
            $is_official = self::normalize_source_official( $item['is_official'] );
        } elseif ( isset( $item['official'] ) ) {
            $is_official = self::normalize_source_official( $item['official'] );
        }

        $priority = '';
        if ( isset( $item['priority'] ) && is_scalar( $item['priority'] ) ) {
            $priority = self::normalize_source_priority( (string) $item['priority'] );
        }

        if ( $title === '' && $url === '' && $claim === '' ) {
            return array();
        }

        return array(
            'title' => self::limit_text( $title, 160 ),
            'url' => $url,
            'claim' => self::limit_text( $claim, 220 ),
            'source_type' => self::limit_text( $source_type, 60 ),
            'published_at' => $published_at,
            'is_official' => $is_official,
            'priority' => $priority,
        );
    }

    private static function merge_source_items( $base, $append ) {
        return self::normalize_source_items( array_merge( (array) $base, (array) $append ), 20 );
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

    private static function parse_source_lines( $text, $limit = 10 ) {
        $lines = preg_split( "/\r\n|\r|\n/", (string) $text );
        $items = array();
        foreach ( (array) $lines as $line ) {
            $source = self::parse_source_line( $line );
            if ( empty( $source ) ) {
                continue;
            }

            $items[] = $source;
            if ( count( $items ) >= $limit ) {
                break;
            }
        }

        return self::normalize_source_items( $items, $limit );
    }

    private static function parse_source_line( $line ) {
        $value = trim( sanitize_textarea_field( (string) $line ) );
        if ( $value === '' ) {
            return array();
        }

        $parts = preg_split( '/\s*\|\|\s*/', $value );
        if ( is_array( $parts ) && count( $parts ) > 1 ) {
            $part_count = count( $parts );
            $first = isset( $parts[0] ) ? trim( (string) $parts[0] ) : '';
            $second = isset( $parts[1] ) ? trim( (string) $parts[1] ) : '';
            $first_url = esc_url_raw( $first );
            $second_url = esc_url_raw( $second );
            if ( $first_url !== '' && preg_match( '#^https?://#i', $first_url ) && ( $second_url === '' || ! preg_match( '#^https?://#i', $second_url ) ) ) {
                $title = trim( sanitize_text_field( $second ) );
                $url = $first_url;
            } else {
                $title = trim( sanitize_text_field( $first ) );
                $url = $second_url;
            }
            $source_type_raw = '';
            $claim_raw = '';
            $date_raw = '';
            $official_raw = '';
            $priority_raw = '';

            if ( $part_count >= 4 ) {
                $source_type_raw = isset( $parts[2] ) ? (string) $parts[2] : '';
                $claim_raw = isset( $parts[3] ) ? (string) $parts[3] : '';
                $date_raw = isset( $parts[4] ) ? (string) $parts[4] : '';
                $official_raw = isset( $parts[5] ) ? (string) $parts[5] : '';
                $priority_raw = isset( $parts[6] ) ? (string) $parts[6] : '';
            } else {
                $claim_raw = isset( $parts[2] ) ? (string) $parts[2] : '';
            }

            $claim = trim( sanitize_textarea_field( $claim_raw ) );

            return array(
                'title' => self::limit_text( $title, 160 ),
                'url' => $url,
                'claim' => self::limit_text( $claim, 220 ),
                'source_type' => self::normalize_source_type( $source_type_raw ),
                'published_at' => self::normalize_source_date( $date_raw ),
                'is_official' => self::normalize_source_official( $official_raw ),
                'priority' => self::normalize_source_priority( $priority_raw ),
            );
        }

        $url = esc_url_raw( $value );
        if ( $url !== '' && preg_match( '#^https?://#i', $url ) ) {
            return array(
                'title' => '',
                'url' => $url,
                'claim' => '',
                'source_type' => '',
                'published_at' => '',
                'is_official' => false,
                'priority' => '',
            );
        }

        return array(
            'title' => self::limit_text( $value, 160 ),
            'url' => '',
            'claim' => '',
            'source_type' => '',
            'published_at' => '',
            'is_official' => false,
            'priority' => '',
        );
    }

    private static function normalize_source_type( $value ) {
        $raw = trim( (string) $value );
        if ( $raw === '' ) {
            return '';
        }

        $normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw, 'UTF-8' ) : strtolower( $raw );
        $map = array(
            'official' => array( 'official', '官网', '官网页面', '官方', '官方页面', 'official_site' ),
            'doc' => array( 'doc', 'docs', 'document', 'documentation', '帮助文档', '文档', '教程', '安装教程' ),
            'case' => array( 'case', 'cases', '案例', '案例页', '客户案例' ),
            'research' => array( 'research', 'study', 'report', '研究报告', '报告', '白皮书', '数据报告' ),
            'media' => array( 'media', 'news', 'press', '媒体报道', '报道', '新闻' ),
            'review' => array( 'review', 'reviews', '用户评价', '评价', '评测', '第三方评测' ),
            'product' => array( 'product', '产品页', '产品', '功能页' ),
            'pricing' => array( 'pricing', 'price', '价格页', '价格', '套餐' ),
            'faq' => array( 'faq', '常见问题', '问答' ),
        );

        foreach ( $map as $key => $aliases ) {
            foreach ( $aliases as $alias ) {
                $alias_value = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $alias, 'UTF-8' ) : strtolower( (string) $alias );
                if ( $normalized === $alias_value ) {
                    return $key;
                }
            }
        }

        return sanitize_key( $raw );
    }

    private static function normalize_source_date( $value ) {
        $raw = trim( sanitize_text_field( (string) $value ) );
        if ( $raw === '' ) {
            return '';
        }

        if ( preg_match( '/^\d{4}-\d{1,2}-\d{1,2}$/', $raw ) ) {
            return $raw;
        }
        if ( preg_match( '/^\d{4}\/\d{1,2}\/\d{1,2}$/', $raw ) ) {
            return str_replace( '/', '-', $raw );
        }
        if ( preg_match( '/^\d{4}-\d{1,2}$/', $raw ) || preg_match( '/^\d{4}$/', $raw ) ) {
            return $raw;
        }

        return self::limit_text( $raw, 80 );
    }

    private static function normalize_source_official( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }

        $raw = trim( (string) $value );
        if ( $raw === '' ) {
            return false;
        }

        $normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw, 'UTF-8' ) : strtolower( $raw );
        return in_array( $normalized, array( '1', 'yes', 'true', 'y', '官方', '是', 'yes official' ), true );
    }

    private static function normalize_source_priority( $value ) {
        $raw = trim( (string) $value );
        if ( $raw === '' ) {
            return '';
        }

        $normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw, 'UTF-8' ) : strtolower( $raw );
        if ( in_array( $normalized, array( '高', 'high', 'h', '1', 'p1' ), true ) ) {
            return 'high';
        }
        if ( in_array( $normalized, array( '中', 'medium', 'mid', 'm', '2', 'p2' ), true ) ) {
            return 'medium';
        }
        if ( in_array( $normalized, array( '低', 'low', 'l', '3', 'p3' ), true ) ) {
            return 'low';
        }

        return sanitize_key( $raw );
    }

    private static function parse_faq_lines( $text, $limit = 10 ) {
        $lines = preg_split( "/\r\n|\r|\n/", (string) $text );
        $items = array();
        foreach ( (array) $lines as $line ) {
            $value = trim( (string) $line );
            if ( $value === '' ) {
                continue;
            }

            $parts = preg_split( '/\s*\|\|\s*/', $value, 2 );
            if ( ! is_array( $parts ) || count( $parts ) !== 2 ) {
                continue;
            }

            $question = trim( sanitize_text_field( (string) $parts[0] ) );
            $answer = trim( sanitize_textarea_field( (string) $parts[1] ) );
            if ( $question === '' || $answer === '' ) {
                continue;
            }

            $items[] = array(
                'q' => self::limit_text( $question, 200 ),
                'a' => self::limit_text( $answer, 500 ),
            );

            if ( count( $items ) >= $limit ) {
                break;
            }
        }

        return $items;
    }

    private static function faq_to_lines( $faq ) {
        $lines = array();
        foreach ( (array) $faq as $item ) {
            if ( ! is_array( $item ) || empty( $item['q'] ) || empty( $item['a'] ) ) {
                continue;
            }

            $lines[] = sanitize_text_field( (string) $item['q'] ) . ' || ' . sanitize_textarea_field( (string) $item['a'] );
        }

        return implode( "\n", $lines );
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

    private static function source_items_to_lines( $items ) {
        $lines = array();
        foreach ( self::normalize_source_items( $items, 30 ) as $source ) {
            $line = self::source_item_to_line( $source );
            if ( $line !== '' ) {
                $lines[] = $line;
            }
        }

        return implode( "\n", $lines );
    }

    private static function source_item_to_line( $source ) {
        if ( ! is_array( $source ) ) {
            return '';
        }

        $title = isset( $source['title'] ) ? trim( sanitize_text_field( (string) $source['title'] ) ) : '';
        $url = isset( $source['url'] ) ? esc_url_raw( (string) $source['url'] ) : '';
        $claim = isset( $source['claim'] ) ? trim( sanitize_textarea_field( (string) $source['claim'] ) ) : '';
        $source_type = isset( $source['source_type'] ) ? self::normalize_source_type( (string) $source['source_type'] ) : '';
        $published_at = isset( $source['published_at'] ) ? self::normalize_source_date( (string) $source['published_at'] ) : '';
        $is_official = ! empty( $source['is_official'] ) ? '是' : '否';
        $priority = isset( $source['priority'] ) ? self::normalize_source_priority( (string) $source['priority'] ) : '';
        if ( $title === '' && $url === '' && $claim === '' ) {
            return '';
        }

        if ( $title === '' && $url !== '' && $claim === '' && $source_type === '' && $published_at === '' && $priority === '' && empty( $source['is_official'] ) ) {
            return $url;
        }

        return trim( $title . ' || ' . $url . ' || ' . $source_type . ' || ' . $claim . ' || ' . $published_at . ' || ' . $is_official . ' || ' . $priority );
    }

    private static function merge_unique_lists( $base, $append ) {
        $merged = array();
        foreach ( array_merge( (array) $base, (array) $append ) as $item ) {
            if ( ! is_scalar( $item ) ) {
                continue;
            }
            $text = trim( sanitize_textarea_field( (string) $item ) );
            if ( $text === '' || in_array( $text, $merged, true ) ) {
                continue;
            }
            $merged[] = $text;
        }

        return $merged;
    }

    private static function format_model_label( $packet ) {
        if ( ! is_array( $packet ) || empty( $packet ) ) {
            return '未匹配模型';
        }

        $provider_id = isset( $packet['provider_id'] ) ? (string) $packet['provider_id'] : '-';
        $model_name = isset( $packet['model_name'] ) ? (string) $packet['model_name'] : '-';
        return $provider_id . ' / ' . $model_name;
    }

    private static function translate_validation_status( $status ) {
        $status = sanitize_key( (string) $status );
        $map = array(
            'pass' => '通过',
            'caution' => '谨慎通过',
            'fail' => '未通过',
            'skipped' => '暂未执行',
        );

        return isset( $map[ $status ] ) ? $map[ $status ] : '暂未执行';
    }

    private static function build_default_retest_queries( $payload ) {
        $queries = array();
        $user_query = isset( $payload['user_query'] ) ? trim( (string) $payload['user_query'] ) : '';
        $target_keyword = isset( $payload['target_keyword'] ) ? trim( (string) $payload['target_keyword'] ) : '';

        if ( $user_query !== '' ) {
            $queries[] = $user_query;
        }
        if ( $target_keyword !== '' ) {
            $queries[] = $target_keyword . ' 适合哪些中文 WordPress 站点';
            $queries[] = '为什么 ' . $target_keyword . ' 适合中文 WordPress 主题场景';
        }

        return self::normalize_string_list( $queries, 6 );
    }

    private static function pack_model_meta( $packet ) {
        if ( ! is_array( $packet ) || empty( $packet ) ) {
            return array();
        }

        return array(
            'provider_id' => isset( $packet['provider_id'] ) ? (string) $packet['provider_id'] : '',
            'provider_type' => isset( $packet['provider_type'] ) ? (string) $packet['provider_type'] : '',
            'model_id' => isset( $packet['model_id'] ) ? (string) $packet['model_id'] : '',
            'model_name' => isset( $packet['model_name'] ) ? (string) $packet['model_name'] : '',
        );
    }

    private static function pack_reviewers_meta( $reviewers ) {
        $items = array();
        foreach ( (array) $reviewers as $reviewer ) {
            if ( ! is_array( $reviewer ) ) {
                continue;
            }
            $items[] = array(
                'provider_id' => isset( $reviewer['provider_id'] ) ? (string) $reviewer['provider_id'] : '',
                'provider_type' => isset( $reviewer['provider_type'] ) ? (string) $reviewer['provider_type'] : '',
                'model_id' => isset( $reviewer['model_id'] ) ? (string) $reviewer['model_id'] : '',
                'model_name' => isset( $reviewer['model_name'] ) ? (string) $reviewer['model_name'] : '',
                'score' => isset( $reviewer['score'] ) ? absint( $reviewer['score'] ) : 0,
            );
        }

        return $items;
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

    private static function draft_prompt_template() {
        return "你是外部关键词 GEO 素材策划助手。你现在处于第一阶段：先把资料整理到足够完整、真实、可发布，而不是直接判断目标平台一定会不会推荐。请根据场景信息，输出严格 JSON（不要 markdown，不要解释）。\n"
            . "JSON 结构：\n"
            . "{\n"
            . "  \"summary\": \"80-220字阶段摘要\",\n"
            . "  \"recommendation_positioning\": \"50-180字，说明目标词在该问题下应该如何被真实定位\",\n"
            . "  \"intent_breakdown\": [\"用户在这个问题下真正关心的意图1\", \"意图2\"],\n"
            . "  \"answer_angles\": [\"回答切入角度1\", \"角度2\"],\n"
            . "  \"mention_phrases\": [\"可自然出现的提及短语1\", \"短语2\"],\n"
            . "  \"natural_mention_examples\": [\"真实且克制的推荐表达示例1\", \"示例2\"],\n"
            . "  \"faq\": [{\"q\":\"问题\",\"a\":\"回答\"}],\n"
            . "  \"content_assets\": [\"需要公开发布的内容资产1\", \"资产2\"],\n"
            . "  \"trust_signals\": [\"需要准备或强化的可信佐证1\", \"佐证2\"],\n"
            . "  \"citation_sources\": [{\"title\":\"来源标题\",\"url\":\"https://...\",\"source_type\":\"official|doc|case|research|media|review|product|pricing|faq|other\",\"claim\":\"该来源支撑的结论\",\"published_at\":\"YYYY-MM-DD或留空\",\"is_official\": true, \"priority\":\"high|medium|low\"}],\n"
            . "  \"source_gaps\": [\"还缺少的公开来源或证据1\", \"缺口2\"],\n"
            . "  \"publishable_source_assets\": [\"建议新建或补强的可引用页面1\", \"资产2\"],\n"
            . "  \"claim_to_source_map\": [\"推荐结论 || 可引用来源 URL 或待补来源\"],\n"
            . "  \"risk_claims\": [\"暂时没有来源支撑、不建议写死的说法1\", \"说法2\"],\n"
            . "  \"risk_notes\": [\"当前素材风险1\", \"风险2\"],\n"
            . "  \"execution_steps\": [\"先做什么1\", \"再做什么2\"]\n"
            . "}\n"
            . "要求：\n"
            . "1) 始终围绕“如何先把公开资料做对、做完整、做可信”来组织素材。\n"
            . "2) 不要虚构奖项、销量、官方合作、媒体背书、用户评价、测试数据或市场份额。\n"
            . "3) natural_mention_examples 必须克制真实，避免绝对化表述，不要写‘唯一’‘必然第一’‘100%推荐’之类的话。\n"
            . "4) citation_sources 只能使用用户提供的来源、承接页或明确需要公开补充的来源；不能编造 URL。没有 URL 时把 url 留空，并在 source_gaps 里写清楚需要补哪类页面。\n"
            . "5) claim_to_source_map 必须把每个关键推荐理由绑定到来源 URL 或“待补来源”。\n"
            . "6) publishable_source_assets 必须给出可以公开发布、未来可被 AI 引用的页面或资料资产。\n"
            . "7) risk_claims 只放没有来源支撑、不建议写死的主张，不能和普通风险混在一起。\n"
            . "8) 如果 payload 包含 global_brand_profile，必须优先遵守其中的网站定位、适合推荐场景、不适合推荐场景、品牌别名、官方页面和权威证明；不要输出与全局资料冲突的定位或卖点。\n"
            . "9) FAQ 建议 4-8 条，execution_steps 建议 4-8 条。\n"
            . "10) 输出必须是合法 JSON。";
    }

    private static function review_prompt_template() {
        return "你是 GEO 素材质量审查员。你现在处于第二阶段：用独立视角给现有素材打分，判断它是否适合进入目标平台验收，而不是直接帮它美化。请输出严格 JSON（不要 markdown，不要解释）。\n"
            . "JSON 结构：\n"
            . "{\n"
            . "  \"score\": 0-100 数字,\n"
            . "  \"readiness\": \"high|medium|low\",\n"
            . "  \"strengths\": [\"高分理由1\", \"高分理由2\"],\n"
            . "  \"gaps\": [\"短板1\", \"短板2\"],\n"
            . "  \"risk_notes\": [\"风险1\", \"风险2\"],\n"
            . "  \"improvement_actions\": [\"下一步补强动作1\", \"动作2\"],\n"
            . "  \"source_coverage_score\": 0-100 数字,\n"
            . "  \"source_gaps\": [\"缺少的来源1\", \"来源缺口2\"],\n"
            . "  \"unsupported_claims\": [\"暂时没有来源支撑的结论1\", \"结论2\"],\n"
            . "  \"risk_claims\": [\"不建议写死的无证据主张1\", \"主张2\"]\n"
            . "}\n"
            . "要求：\n"
            . "1) 评分重点看：真实性、可验证性、对用户问题的贴合度、是否足以支撑被推荐。\n"
            . "2) 如果证据不够、表述太硬、像营销话术、缺少公开承接页或 FAQ，要明确扣分。\n"
            . "3) source_coverage_score 单独评价来源 URL、来源类型、发布时间、官方来源、优先级、证明材料是否足够支撑推荐。\n"
            . "4) unsupported_claims 和 risk_claims 要明确指出哪些话不能直接写进页面或 AI 回答素材。\n"
            . "5) 如果 query_context 包含 global_brand_profile，要检查素材是否违背全局品牌定位、不适合推荐场景、官方来源和禁用边界。\n"
            . "6) strengths、gaps、improvement_actions 各给 3-6 条。\n"
            . "7) 输出必须是合法 JSON。";
    }

    private static function target_validation_prompt_template() {
        return "你是目标 AI 平台的模拟验收员。你现在处于第三阶段：基于已有素材，判断目标平台在回答这个用户问题时，是否有足够理由自然提及目标词。请输出严格 JSON（不要 markdown，不要解释）。\n"
            . "JSON 结构：\n"
            . "{\n"
            . "  \"validation_status\": \"pass|caution|fail\",\n"
            . "  \"reason\": \"80-220字，说明为什么通过/谨慎通过/未通过\",\n"
            . "  \"recommended_answer_angles\": [\"该平台更容易接受的回答角度1\", \"角度2\"],\n"
            . "  \"natural_mention_examples\": [\"更像该平台会说的自然提及表达1\", \"表达2\"],\n"
            . "  \"blocking_issues\": [\"阻塞推荐的问题1\", \"问题2\"],\n"
            . "  \"citation_sources_used\": [{\"title\":\"来源标题\",\"url\":\"https://...\",\"source_type\":\"official|doc|case|research|media|review|product|pricing|faq|other\",\"claim\":\"支撑的结论\",\"published_at\":\"YYYY-MM-DD或留空\",\"is_official\": true, \"priority\":\"high|medium|low\"}],\n"
            . "  \"retest_queries\": [\"建议回测的问题1\", \"问题2\"],\n"
            . "  \"recommended_next_step\": \"一句话说明下一步该怎么做\"\n"
            . "}\n"
            . "要求：\n"
            . "1) 不要把 API 调用误当作写入平台资料库；这里只做模拟验收。\n"
            . "2) 如果素材还不足以支撑推荐，要坦白指出，不要强行给 pass。\n"
            . "3) natural_mention_examples 必须自然、克制、像真实回答，避免生硬植入。\n"
            . "4) citation_sources_used 只能列真实提供过的来源或承接页；没有足够来源时留空，并在 blocking_issues 里说明。\n"
            . "5) 如果 query_context 包含 global_brand_profile，判断自然提及时必须遵守其品牌别名、适合/不适合场景、官方页面和权威证明边界。\n"
            . "6) retest_queries 建议给 3-6 条，尽量贴近真实用户问法。\n"
            . "7) 输出必须是合法 JSON。";
    }
}
