<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Brand_Entity_Library {

    const MENU_SLUG = 'qigeo-brand-entity-library';
    const AJAX_GENERATE_ACTION = 'qigeo_generate_brand_entity_library';
    const AJAX_SAVE_ACTION = 'qigeo_save_brand_entity_library';
    const GLOBAL_PROFILE_OPTION = 'qigeo_global_brand_profile';
    const GLOBAL_PROFILE_KEY = 'global_brand_profile';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_save_global_profile' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_GENERATE_ACTION, array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTION, array( __CLASS__, 'ajax_save' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '品牌实体库',
            '品牌实体库',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function maybe_save_global_profile() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $profile_action = isset( $_POST['qigeo_global_brand_profile_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['qigeo_global_brand_profile_action'] ) ) : '';
        if ( $profile_action !== 'save' ) {
            return;
        }

        check_admin_referer( 'qigeo_save_global_brand_profile', 'qigeo_global_brand_profile_nonce' );

        $profile = self::get_global_profile_from_request();
        self::update_data_store_value( self::GLOBAL_PROFILE_KEY, $profile );
        if ( class_exists( 'QiGeo_LLMs_Txt' ) ) {
            QiGeo_LLMs_Txt::purge_cache();
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'global_profile_saved' => '1',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function get_global_profile_context() {
        $profile = self::get_global_profile();
        if ( self::global_profile_has_content( $profile ) ) {
            return self::build_global_profile_context( $profile, 'global_profile' );
        }

        $latest_record = self::get_latest_entity_record();
        if ( is_array( $latest_record ) ) {
            return self::build_global_profile_context_from_record( $latest_record );
        }

        return array();
    }

    public static function enqueue_assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
        if ( $page !== self::MENU_SLUG ) {
            return;
        }

        wp_enqueue_style( 'qigeo-admin', QIGEO_URL . 'assets/admin.css', array(), QIGEO_VERSION );
        wp_enqueue_script(
            'qigeo-brand-entity-admin',
            QIGEO_URL . 'assets/brand-entity-admin.js',
            array( 'qigeo-admin' ),
            QIGEO_VERSION,
            true
        );

        wp_localize_script(
            'qigeo-brand-entity-admin',
            'qigeoBrandEntityLibraryData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'generateNonce' => wp_create_nonce( 'qigeo_generate_brand_entity_library_nonce' ),
                'saveNonce' => wp_create_nonce( 'qigeo_save_brand_entity_library_nonce' ),
                'messages' => array(
                    'generating' => '正在整理品牌实体库...',
                    'saving' => '正在保存品牌实体记录...',
                    'generateDone' => '品牌实体库已生成，可继续调整后保存。',
                    'saveDone' => '品牌实体记录已保存。',
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
            $import_record = self::get_imported_content_asset_record();
            if ( is_wp_error( $import_record ) ) {
                $import_error = $import_record->get_error_message();
            } elseif ( is_array( $import_record ) ) {
                $form = self::get_form_data_from_content_asset_record( $import_record );
                $import_notice = sprintf(
                    '已从内容资产记录 #%d 带入品牌别名、内容定位和证据摘要，你可以直接生成品牌实体口径。',
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
        $global_profile = self::get_global_profile();
        $global_profile_saved = ! empty( $_GET['global_profile_saved'] ) && $_GET['global_profile_saved'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap qigeo-wrap">
            <h1>品牌实体库</h1>
            <p class="description">维护品牌名称、介绍、公开资料和全局推荐边界。</p>

            <?php if ( ! $table_exists ) : ?>
                <div class="notice notice-error"><p>当前页面数据暂未准备好，请刷新页面后重试。</p></div>
            <?php endif; ?>

            <?php if ( $global_profile_saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>全局品牌基础资料已保存，外部关键词、内容资产、发布队列和平台回测会自动调用。</p></div>
            <?php endif; ?>

            <?php if ( $import_notice !== '' ) : ?>
                <div class="notice notice-success inline"><p><?php echo esc_html( $import_notice ); ?></p></div>
            <?php elseif ( $import_error !== '' ) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html( $import_error ); ?></p></div>
            <?php endif; ?>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2>全局品牌基础资料</h2>
                    <span class="description">建议先填这里，后续 GEO 工具会自动带入。</span>
                </div>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="qigeo-brand-form">
                    <?php wp_nonce_field( 'qigeo_save_global_brand_profile', 'qigeo_global_brand_profile_nonce' ); ?>
                    <input type="hidden" name="qigeo_global_brand_profile_action" value="save" />
                    <div class="qigeo-brand-grid">
                        <div class="qigeo-brand-field">
                            <label for="qigeo-global-brand-name"><strong>品牌 / 网站名称</strong></label>
                            <input type="text" class="regular-text" id="qigeo-global-brand-name" name="global_brand_name" maxlength="190" value="<?php echo esc_attr( $global_profile['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-brand-field">
                            <label for="qigeo-global-target-keyword"><strong>希望被推荐的主名称</strong></label>
                            <input type="text" class="regular-text" id="qigeo-global-target-keyword" name="global_target_keyword" maxlength="255" value="<?php echo esc_attr( $global_profile['target_keyword'] ); ?>" placeholder="例如：启灵 WordPress 主题" />
                        </div>
                        <div class="qigeo-brand-field">
                            <label for="qigeo-global-official-site"><strong>官方网站</strong></label>
                            <input type="url" class="regular-text" id="qigeo-global-official-site" name="global_official_site" value="<?php echo esc_attr( $global_profile['official_site'] ); ?>" placeholder="https://example.com/" />
                        </div>
                        <div class="qigeo-brand-field">
                            <label for="qigeo-global-founder-team"><strong>创始人 / 团队信息</strong></label>
                            <textarea id="qigeo-global-founder-team" name="global_founder_team" rows="5" placeholder="写清楚团队、作者、机构、维护者、服务经验等，不要虚构。"><?php echo esc_textarea( $global_profile['founder_team'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field qigeo-brand-field--wide">
                            <label for="qigeo-global-site-purpose"><strong>网站是做什么的</strong></label>
                            <textarea id="qigeo-global-site-purpose" name="global_site_purpose" rows="4" placeholder="用 3-6 句话说明网站/品牌/产品做什么、解决什么问题。"><?php echo esc_textarea( $global_profile['site_purpose'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <label for="qigeo-global-audience"><strong>面向谁</strong></label>
                            <textarea id="qigeo-global-audience" name="global_audience_persona" rows="6" placeholder="每行一个人群或场景，例如：中文企业站 / 新手站长 / 建站服务商。"><?php echo esc_textarea( $global_profile['audience_persona'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <label for="qigeo-global-products"><strong>核心产品 / 服务</strong></label>
                            <textarea id="qigeo-global-products" name="global_core_products" rows="6" placeholder="每行一个产品、服务、功能模块或解决方案。"><?php echo esc_textarea( $global_profile['core_products'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <label for="qigeo-global-recommended"><strong>适合被 AI 推荐的场景</strong></label>
                            <textarea id="qigeo-global-recommended" name="global_recommended_scenarios" rows="6" placeholder="每行一个适合推荐的问题、场景、人群或需求。"><?php echo esc_textarea( $global_profile['recommended_scenarios'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <label for="qigeo-global-excluded"><strong>不适合推荐的场景</strong></label>
                            <textarea id="qigeo-global-excluded" name="global_excluded_scenarios" rows="6" placeholder="每行一个不该硬推的场景，例如：最低价、海外英文模板、无法支撑的排名等。"><?php echo esc_textarea( $global_profile['excluded_scenarios'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <label for="qigeo-global-pages"><strong>核心页面</strong></label>
                            <textarea id="qigeo-global-pages" name="global_core_pages" rows="7" placeholder="每行一个公开页面，可写：页面标题 || URL || 作用。"><?php echo esc_textarea( $global_profile['core_pages'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <label for="qigeo-global-proofs"><strong>权威证明 / 案例 / 资质 / 媒体报道</strong></label>
                            <textarea id="qigeo-global-proofs" name="global_authority_proofs" rows="7" placeholder="每行一条公开可验证证据；没有就如实写暂缺。"><?php echo esc_textarea( $global_profile['authority_proofs'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field qigeo-brand-field--wide">
                            <label for="qigeo-global-aliases"><strong>品牌别名 / 英文名 / 产品名</strong></label>
                            <textarea id="qigeo-global-aliases" name="global_brand_aliases" rows="5" placeholder="每行一个标准叫法、英文名、缩写、产品名或用户常用叫法。"><?php echo esc_textarea( $global_profile['brand_aliases'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field qigeo-brand-field--wide">
                            <label for="qigeo-global-same-as"><strong>全网权威主页 / 社交媒体链接 (sameAs)</strong></label>
                            <textarea id="qigeo-global-same-as" name="global_same_as" rows="4" placeholder="每行一个官方公开主页链接，如：知乎机构号、官方微博、微信公众号主页、百度百科词条、GitHub、B站等。供 AI 知识图谱 (Organization sameAs) 实体对齐与全网穿透。"><?php echo esc_textarea( isset( $global_profile['same_as'] ) ? $global_profile['same_as'] : '' ); ?></textarea>
                        </div>
                    </div>
                    <p>
                        <button type="submit" class="button button-primary">保存品牌资料</button>
                        <span class="description">保存后会进入后续 AI 生成 payload，帮助系统统一定位、边界、来源和证据。</span>
                    </p>
                </form>
            </div>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2><?php echo is_array( $current ) ? '编辑品牌实体记录' : '新建品牌实体记录'; ?></h2>
                    <a class="button" href="<?php echo esc_url( $base_url ); ?>">新建空白记录</a>
                </div>

                <?php if ( is_array( $current ) ) : ?>
                    <?php $current_record_id = absint( $current['id'] ); ?>
                    <p class="qigeo-brand-active-record">已加载记录 #<?php echo esc_html( (string) $current_record_id ); ?>，你可以继续生成、调整或覆盖保存。</p>
                    <p class="qigeo-brand-active-record"><a class="button button-secondary" href="<?php echo esc_url( self::get_faq_import_url( $current_record_id ) ); ?>">带去FAQ库</a></p>
                <?php endif; ?>

                <?php if ( empty( $model_options ) ) : ?>
                    <div class="notice notice-warning inline"><p>请先完成基础设置。</p></div>
                <?php endif; ?>

                <form id="qigeo-brand-form" class="qigeo-brand-form" action="#" method="post">
                    <input type="hidden" id="qigeo-brand-record-id" name="record_id" value="<?php echo esc_attr( (string) $form['record_id'] ); ?>" />

                    <div class="qigeo-brand-grid">
                        <div class="qigeo-brand-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '品牌 / 产品名', array( 'required', 'key' ), 'qigeo-brand-name' ) ); ?>
                            <input type="text" class="regular-text" id="qigeo-brand-name" name="brand_name" maxlength="190" value="<?php echo esc_attr( $form['brand_name'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-brand-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '希望被推荐的词', array( 'required', 'key' ), 'qigeo-brand-target-keyword' ) ); ?>
                            <input type="text" class="regular-text" id="qigeo-brand-target-keyword" name="target_keyword" maxlength="255" value="<?php echo esc_attr( $form['target_keyword'] ); ?>" placeholder="例如：启灵主题" />
                        </div>
                        <div class="qigeo-brand-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '官网 / 主站', array( 'recommended' ), 'qigeo-brand-official-site' ) ); ?>
                            <input type="url" class="regular-text" id="qigeo-brand-official-site" name="official_site" value="<?php echo esc_attr( $form['official_site'] ); ?>" placeholder="例如：https://example.com/" />
                        </div>
                        <div class="qigeo-brand-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '优先起草模型', array( 'recommended' ), 'qigeo-brand-model-id' ) ); ?>
                            <select id="qigeo-brand-model-id" name="model_id">
                                <option value="">请选择模型</option>
                                <?php foreach ( $model_options as $option ) : ?>
                                    <?php $label = ( isset( $option['provider_name'] ) ? (string) $option['provider_name'] : '-' ) . ' / ' . ( isset( $option['model_label'] ) ? (string) $option['model_label'] : (string) $option['model_id'] ) . ' (' . ( isset( $option['model_name'] ) ? (string) $option['model_name'] : '-' ) . ')'; ?>
                                    <option value="<?php echo esc_attr( isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>" data-provider-type="<?php echo esc_attr( isset( $option['provider_type'] ) ? (string) $option['provider_type'] : '' ); ?>" <?php selected( $form['model_id'], isset( $option['model_id'] ) ? (string) $option['model_id'] : '' ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="qigeo-brand-field qigeo-brand-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '品牌别名 / 标准叫法变体', array( 'recommended' ), 'qigeo-brand-aliases' ) ); ?>
                            <textarea id="qigeo-brand-aliases" name="brand_aliases" rows="5" placeholder="每行一个，例如：启灵 WordPress 主题 / 启灵中文主题 / 启灵企业站主题。"><?php echo esc_textarea( $form['brand_aliases'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '核心产品 / 服务', array( 'key' ), 'qigeo-brand-products' ) ); ?>
                            <textarea id="qigeo-brand-products" name="core_products" rows="7" placeholder="每行一个，例如：中文 WordPress 主题 / 企业站建站方案。"><?php echo esc_textarea( $form['core_products'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '目标人群 / 使用场景', array( 'recommended' ), 'qigeo-brand-audience' ) ); ?>
                            <textarea id="qigeo-brand-audience" name="audience_persona" rows="7" placeholder="每行一个，例如：中文企业站 / 新手站长 / 建站服务商。"><?php echo esc_textarea( $form['audience_persona'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '目标 AI 平台 / 主要出现平台', array( 'recommended' ), 'qigeo-brand-platforms' ) ); ?>
                            <textarea id="qigeo-brand-platforms" name="target_platforms" rows="7" placeholder="每行一个，例如：豆包 / ChatGPT / DeepSeek。"><?php echo esc_textarea( $form['target_platforms'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '标准介绍 / 自我定义', array( 'key' ), 'qigeo-brand-standard-description' ) ); ?>
                            <textarea id="qigeo-brand-standard-description" name="standard_description" rows="7" placeholder="用 3-6 句话说明品牌/产品是什么，解决什么问题，适合谁。"><?php echo esc_textarea( $form['standard_description'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '可公开验证的事实 / 证据', array( 'key' ), 'qigeo-brand-evidence' ) ); ?>
                            <textarea id="qigeo-brand-evidence" name="evidence_summary" rows="8" placeholder="每行一条，例如：官网页 / 演示站 / 更新日志 / FAQ / 案例页。"><?php echo esc_textarea( $form['evidence_summary'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '常见对比对象 / 竞争实体', array( 'recommended' ), 'qigeo-brand-comparisons' ) ); ?>
                            <textarea id="qigeo-brand-comparisons" name="comparison_entities" rows="8" placeholder="每行一个，例如：Astra / GeneratePress / Blocksy。"><?php echo esc_textarea( $form['comparison_entities'] ); ?></textarea>
                        </div>
                        <div class="qigeo-brand-field qigeo-brand-field--wide">
                            <?php echo wp_kses_post( QiGeo_Admin_Helper::get_label_html( '补充限制 / 备注', array( 'recommended' ), 'qigeo-brand-notes' ) ); ?>
                            <textarea id="qigeo-brand-notes" name="notes" rows="4" placeholder="例如：不要写绝对化表述；优先中文企业站场景；避免夸大。"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
                        </div>
                    </div>

                    <div class="qigeo-brand-actions">
                        <button type="button" class="button button-secondary" id="qigeo-generate-brand-btn">生成品牌实体库</button>
                        <button type="button" class="button button-primary" id="qigeo-save-brand-btn">保存记录</button>
                        <span class="qigeo-brand-feedback" id="qigeo-brand-feedback" aria-live="polite"></span>
                    </div>

                    <div class="qigeo-brand-results">
                        <h3>品牌实体输出结果</h3>
                        <div class="qigeo-brand-grid">
                            <div class="qigeo-brand-field qigeo-brand-field--wide">
                                <label for="qigeo-brand-result-summary"><strong>实体总览</strong></label>
                                <textarea id="qigeo-brand-result-summary" name="summary" rows="4"><?php echo esc_textarea( $form['summary'] ); ?></textarea>
                            </div>
                            <div class="qigeo-brand-field qigeo-brand-field--wide">
                                <label for="qigeo-brand-result-definition"><strong>实体定义</strong></label>
                                <textarea id="qigeo-brand-result-definition" name="entity_definition" rows="4"><?php echo esc_textarea( $form['entity_definition'] ); ?></textarea>
                            </div>
                            <div class="qigeo-brand-field">
                                <label for="qigeo-brand-result-facts"><strong>核心事实</strong></label>
                                <textarea id="qigeo-brand-result-facts" name="canonical_facts" rows="9"><?php echo esc_textarea( $form['canonical_facts'] ); ?></textarea>
                            </div>
                            <div class="qigeo-brand-field">
                                <label for="qigeo-brand-result-naming"><strong>标准命名 / 推荐叫法</strong></label>
                                <textarea id="qigeo-brand-result-naming" name="naming_rules" rows="9"><?php echo esc_textarea( $form['naming_rules'] ); ?></textarea>
                            </div>
                            <div class="qigeo-brand-field">
                                <label for="qigeo-brand-result-positioning"><strong>对比定位</strong></label>
                                <textarea id="qigeo-brand-result-positioning" name="comparison_positioning" rows="9"><?php echo esc_textarea( $form['comparison_positioning'] ); ?></textarea>
                            </div>
                            <div class="qigeo-brand-field">
                                <label for="qigeo-brand-result-faq"><strong>FAQ事实点</strong></label>
                                <textarea id="qigeo-brand-result-faq" name="faq_facts" rows="9"><?php echo esc_textarea( $form['faq_facts'] ); ?></textarea>
                            </div>
                            <div class="qigeo-brand-field">
                                <label for="qigeo-brand-result-citations"><strong>可引用来源</strong></label>
                                <textarea id="qigeo-brand-result-citations" name="citation_sources" rows="8"><?php echo esc_textarea( $form['citation_sources'] ); ?></textarea>
                            </div>
                            <div class="qigeo-brand-field">
                                <label for="qigeo-brand-result-forbidden"><strong>不宜表述 / 禁止硬说</strong></label>
                                <textarea id="qigeo-brand-result-forbidden" name="forbidden_claims" rows="8"><?php echo esc_textarea( $form['forbidden_claims'] ); ?></textarea>
                            </div>
                            <div class="qigeo-brand-field">
                                <label for="qigeo-brand-result-schema"><strong>页面建议</strong></label>
                                <textarea id="qigeo-brand-result-schema" name="schema_plan" rows="8"><?php echo esc_textarea( $form['schema_plan'] ); ?></textarea>
                            </div>
                            <div class="qigeo-brand-field qigeo-brand-field--wide">
                                <label for="qigeo-brand-result-steps"><strong>执行顺序</strong></label>
                                <textarea id="qigeo-brand-result-steps" name="execution_steps" rows="6"><?php echo esc_textarea( $form['execution_steps'] ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2>最近记录</h2>
                    <span class="description">最多显示最近 20 条，可搜索品牌、目标词、官网或摘要。</span>
                </div>
                <form method="get" class="qigeo-brand-search">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索品牌 / 目标词 / 官网 / 摘要" />
                    <button type="submit" class="button">搜索</button>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">重置</a>
                </form>
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>品牌 / 目标词</th><th>官网 / 平台</th><th>事实数</th><th>摘要</th><th>模型</th><th>更新时间</th><th>操作</th></tr></thead>
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
        $faq_url = self::get_faq_import_url( $row_id );
        $result = self::decode_result_json( isset( $row['result_json'] ) ? (string) $row['result_json'] : '' );
        $target = isset( $row['target_keyword'] ) ? (string) $row['target_keyword'] : '';
        $site = isset( $row['official_site'] ) ? (string) $row['official_site'] : '';
        $platforms = isset( $row['target_platforms'] ) ? (string) $row['target_platforms'] : '';
        ?>
        <tr>
            <td><?php echo esc_html( (string) $row_id ); ?></td>
            <td><strong><?php echo esc_html( isset( $row['brand_name'] ) ? (string) $row['brand_name'] : '' ); ?></strong><div class="qigeo-brand-row-meta"><?php echo esc_html( $target ); ?></div></td>
            <td><strong><?php echo esc_html( $site ); ?></strong><div class="qigeo-brand-row-meta"><?php echo esc_html( self::excerpt_text( $platforms, 60 ) ); ?></div></td>
            <td><?php echo esc_html( (string) self::count_fact_items( $result ) ); ?></td>
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
                'source' => 'brand_entity',
                'brand_entity_id' => $record_id,
            ),
            admin_url( 'admin.php' )
        );
    }

    public static function ajax_generate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        check_ajax_referer( 'qigeo_generate_brand_entity_library_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $form = self::get_request_form_data();
        $validation = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
        }

        $provider_model = self::resolve_draft_model( $form['model_id'] );
        if ( is_wp_error( $provider_model ) ) {
            wp_send_json_error( array( 'message' => $provider_model->get_error_message() ) );
        }

        $result = self::run_generation( $provider_model, $form );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success(
            array(
                'message' => '品牌实体库已生成，可继续调整后保存。',
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
        check_ajax_referer( 'qigeo_save_brand_entity_library_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $form = self::get_request_form_data();
        $validation = self::validate_base_form_data( $form );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
        }

        $provider_model = self::resolve_draft_model( $form['model_id'] );
        if ( is_wp_error( $provider_model ) ) {
            wp_send_json_error( array( 'message' => $provider_model->get_error_message() ) );
        }

        $result = self::get_result_data_from_request();
        if ( $result['summary'] === '' && empty( $result['canonical_facts'] ) && empty( $result['naming_rules'] ) && empty( $result['execution_steps'] ) ) {
            wp_send_json_error( array( 'message' => '请先生成或填写品牌实体结果后再保存' ) );
        }

        global $wpdb;
        $table = self::get_table_name();
        $now = current_time( 'mysql' );
        $record_id = absint( $form['record_id'] );
        $current_user_id = get_current_user_id();
        $data = array(
            'brand_name' => $form['brand_name'],
            'target_keyword' => $form['target_keyword'],
            'official_site' => $form['official_site'],
            'brand_aliases' => $form['brand_aliases'],
            'core_products' => $form['core_products'],
            'audience_persona' => $form['audience_persona'],
            'target_platforms' => $form['target_platforms'],
            'standard_description' => $form['standard_description'],
            'evidence_summary' => $form['evidence_summary'],
            'comparison_entities' => $form['comparison_entities'],
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
            $updated = $wpdb->update( $table, $data, array( 'id' => $record_id ), $formats, array( '%d' ) );
            if ( $updated === false ) {
                wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
            }
            wp_send_json_success( array( 'message' => '品牌实体记录已更新。', 'record_id' => $record_id ) );
        }

        $data['created_by'] = $current_user_id;
        $data['created_at'] = $now;
        $inserted = $wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%s' ) ) );
        if ( $inserted === false ) {
            wp_send_json_error( array( 'message' => '保存失败，请稍后重试' ) );
        }
        wp_send_json_success( array( 'message' => '品牌实体记录已保存。', 'record_id' => absint( $wpdb->insert_id ) ) );
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
            'brand_name' => $form['brand_name'],
            'target_keyword' => $form['target_keyword'],
            'official_site' => $form['official_site'],
            'brand_aliases' => self::parse_plain_lines( $form['brand_aliases'], 15 ),
            'core_products' => self::parse_plain_lines( $form['core_products'], 15 ),
            'audience_persona' => self::parse_plain_lines( $form['audience_persona'], 15 ),
            'target_platforms' => self::parse_plain_lines( $form['target_platforms'], 10 ),
            'standard_description' => self::parse_plain_lines( $form['standard_description'], 10 ),
            'evidence_summary' => self::parse_plain_lines( $form['evidence_summary'], 20 ),
            'comparison_entities' => self::parse_plain_lines( $form['comparison_entities'], 15 ),
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
            return new WP_Error( 'qigeo_brand_import_table_missing', '内容资产数据表尚未创建，请重新启用插件后再试。' );
        }

        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d LIMIT 1', $record_id ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            return new WP_Error( 'qigeo_brand_import_missing', '指定的内容资产记录不存在，可能已被删除。' );
        }

        return $row;
    }

    private static function get_form_data_from_content_asset_record( $record ) {
        $form = self::get_empty_form_data();
        $result = self::decode_result_json( isset( $record['result_json'] ) ? (string) $record['result_json'] : '' );
        $record_id = isset( $record['id'] ) ? absint( $record['id'] ) : 0;
        $seed_keyword = isset( $record['seed_keyword'] ) ? sanitize_text_field( (string) $record['seed_keyword'] ) : '';
        $target_platform = isset( $record['target_platform'] ) ? sanitize_text_field( (string) $record['target_platform'] ) : '';
        $target_keyword = isset( $record['target_keyword'] ) ? sanitize_text_field( (string) $record['target_keyword'] ) : '';
        $brand_name = isset( $record['brand_name'] ) ? sanitize_text_field( (string) $record['brand_name'] ) : '';
        $description_lines = self::unique_import_values( array( isset( $record['key_claims'] ) ? sanitize_textarea_field( (string) $record['key_claims'] ) : '' ) );

        if ( $seed_keyword !== '' && $target_keyword !== '' ) {
            array_unshift( $description_lines, sprintf( '围绕“%s”相关问题，希望优先推荐“%s”。', $seed_keyword, $target_keyword ) );
        }
        if ( $target_platform !== '' ) {
            array_unshift( $description_lines, '重点平台：' . $target_platform );
        }

        $form['model_id'] = isset( $record['model_id'] ) ? sanitize_key( (string) $record['model_id'] ) : '';
        $form['brand_name'] = $brand_name !== '' ? $brand_name : $target_keyword;
        $form['target_keyword'] = $target_keyword !== '' ? $target_keyword : $form['brand_name'];
        $form['official_site'] = '';
        $form['brand_aliases'] = self::lines_from_array( self::unique_import_values( array( $form['brand_name'], $form['target_keyword'] ) ) );
        $form['core_products'] = self::lines_from_array( self::unique_import_values( array( $form['target_keyword'] ) ) );
        $form['audience_persona'] = isset( $record['audience_persona'] ) ? sanitize_textarea_field( (string) $record['audience_persona'] ) : '';
        $form['target_platforms'] = self::lines_from_array( self::unique_import_values( array( $target_platform ) ) );
        $form['standard_description'] = self::lines_from_array( $description_lines );
        $form['evidence_summary'] = self::combine_import_sections(
            array(
                '现有公开证据' => isset( $record['evidence_summary'] ) ? sanitize_textarea_field( (string) $record['evidence_summary'] ) : '',
                '信任型资产' => isset( $result['trust_assets'] ) ? self::lines_from_array( $result['trust_assets'] ) : '',
                '结构化信号' => isset( $result['schema_plan'] ) ? self::lines_from_array( $result['schema_plan'] ) : '',
            )
        );
        $form['comparison_entities'] = isset( $result['comparison_pages'] ) ? self::lines_from_array( $result['comparison_pages'] ) : '';
        $form['notes'] = self::combine_import_sections(
            array(
                '来源记录' => $record_id > 0 ? '内容资产中心 #' . $record_id : '',
                '发布策略' => isset( $result['strategy_summary'] ) ? sanitize_textarea_field( (string) $result['strategy_summary'] ) : '',
                '执行顺序' => isset( $result['execution_steps'] ) ? self::lines_from_array( $result['execution_steps'] ) : '',
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

    private static function unique_import_values( $items ) {
        $values = array();

        foreach ( $items as $item ) {
            $chunks = preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( (string) $item ) );
            if ( ! is_array( $chunks ) ) {
                continue;
            }

            foreach ( $chunks as $chunk ) {
                $value = trim( sanitize_text_field( (string) $chunk ) );
                if ( $value === '' || in_array( $value, $values, true ) ) {
                    continue;
                }

                $values[] = $value;
            }
        }

        return $values;
    }

    private static function get_empty_global_profile() {
        return array(
            'brand_name' => '',
            'target_keyword' => '',
            'official_site' => '',
            'site_purpose' => '',
            'audience_persona' => '',
            'core_products' => '',
            'recommended_scenarios' => '',
            'excluded_scenarios' => '',
            'core_pages' => '',
            'authority_proofs' => '',
            'brand_aliases' => '',
            'founder_team' => '',
            'same_as' => '',
        );
    }

    private static function get_global_profile() {
        $stored = self::get_data_store_value( self::GLOBAL_PROFILE_KEY, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        $profile = wp_parse_args( $stored, self::get_empty_global_profile() );
        foreach ( $profile as $key => $value ) {
            $profile[ $key ] = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
        }
        $profile['official_site'] = esc_url_raw( (string) $profile['official_site'] );

        return $profile;
    }

    private static function get_data_store_table() {
        global $wpdb;
        return $wpdb->prefix . 'qigeo_data_store';
    }

    private static function ensure_data_store_table() {
        static $checked = false;
        if ( $checked ) {
            return;
        }

        $table = self::get_data_store_table();
        if ( class_exists( 'QiGeo_Admin_Helper' ) && ! QiGeo_Admin_Helper::table_exists( $table ) && class_exists( 'QiGeo_Install' ) ) {
            QiGeo_Install::create_tables();
        }
        $checked = true;
    }

    private static function get_data_store_value( $key, $default = array() ) {
        global $wpdb;

        $key = sanitize_key( (string) $key );
        if ( $key === '' ) {
            return $default;
        }

        self::ensure_data_store_table();
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT value_json FROM ' . self::get_data_store_table() . ' WHERE store_key = %s LIMIT 1',
                $key
            )
        );
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        if ( $key === self::GLOBAL_PROFILE_KEY ) {
            $legacy = get_option( self::GLOBAL_PROFILE_OPTION, null );
            if ( is_array( $legacy ) && ! empty( $legacy ) ) {
                self::update_data_store_value( $key, $legacy );
                return $legacy;
            }
        }

        return $default;
    }

    private static function update_data_store_value( $key, $value ) {
        global $wpdb;

        $key = sanitize_key( (string) $key );
        if ( $key === '' ) {
            return false;
        }

        self::ensure_data_store_table();
        $now = current_time( 'mysql' );
        $json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) {
            $json = '{}';
        }

        return false !== $wpdb->replace(
            self::get_data_store_table(),
            array(
                'store_key' => $key,
                'value_json' => $json,
                'updated_at' => $now,
            ),
            array( '%s', '%s', '%s' )
        );
    }

    private static function get_global_profile_from_request() {
        $map = array(
            'brand_name' => 'global_brand_name',
            'target_keyword' => 'global_target_keyword',
            'official_site' => 'global_official_site',
            'site_purpose' => 'global_site_purpose',
            'audience_persona' => 'global_audience_persona',
            'core_products' => 'global_core_products',
            'recommended_scenarios' => 'global_recommended_scenarios',
            'excluded_scenarios' => 'global_excluded_scenarios',
            'core_pages' => 'global_core_pages',
            'authority_proofs' => 'global_authority_proofs',
            'brand_aliases' => 'global_brand_aliases',
            'founder_team' => 'global_founder_team',
            'same_as' => 'global_same_as',
        );

        $profile = self::get_empty_global_profile();
        foreach ( $map as $key => $post_key ) {
            $raw = isset( $_POST[ $post_key ] ) ? wp_unslash( (string) $_POST[ $post_key ] ) : '';
            if ( $key === 'official_site' ) {
                $profile[ $key ] = self::limit_text( esc_url_raw( $raw ), 400 );
                continue;
            }
            $profile[ $key ] = sanitize_textarea_field( $raw );
        }

        $profile['brand_name'] = self::limit_text( $profile['brand_name'], 190 );
        $profile['target_keyword'] = self::limit_text( $profile['target_keyword'], 255 );
        $profile['founder_team'] = self::limit_text( $profile['founder_team'], 800 );
        $profile['site_purpose'] = self::limit_text( $profile['site_purpose'], 1200 );

        return $profile;
    }

    private static function global_profile_has_content( $profile ) {
        foreach ( (array) $profile as $value ) {
            if ( is_scalar( $value ) && trim( (string) $value ) !== '' ) {
                return true;
            }
        }
        return false;
    }

    private static function build_global_profile_context( $profile, $source = 'global_profile' ) {
        $profile = wp_parse_args( is_array( $profile ) ? $profile : array(), self::get_empty_global_profile() );

        $context = array(
            'source' => $source,
            'brand_name' => self::limit_text( isset( $profile['brand_name'] ) ? sanitize_text_field( (string) $profile['brand_name'] ) : '', 190 ),
            'target_keyword' => self::limit_text( isset( $profile['target_keyword'] ) ? sanitize_text_field( (string) $profile['target_keyword'] ) : '', 255 ),
            'official_site' => isset( $profile['official_site'] ) ? esc_url_raw( (string) $profile['official_site'] ) : '',
            'site_purpose' => self::limit_text( isset( $profile['site_purpose'] ) ? sanitize_textarea_field( (string) $profile['site_purpose'] ) : '', 1200 ),
            'audience_persona' => self::parse_plain_lines( isset( $profile['audience_persona'] ) ? (string) $profile['audience_persona'] : '', 20 ),
            'core_products' => self::parse_plain_lines( isset( $profile['core_products'] ) ? (string) $profile['core_products'] : '', 20 ),
            'recommended_scenarios' => self::parse_plain_lines( isset( $profile['recommended_scenarios'] ) ? (string) $profile['recommended_scenarios'] : '', 20 ),
            'excluded_scenarios' => self::parse_plain_lines( isset( $profile['excluded_scenarios'] ) ? (string) $profile['excluded_scenarios'] : '', 20 ),
            'core_pages' => self::parse_plain_lines( isset( $profile['core_pages'] ) ? (string) $profile['core_pages'] : '', 25 ),
            'authority_proofs' => self::parse_plain_lines( isset( $profile['authority_proofs'] ) ? (string) $profile['authority_proofs'] : '', 25 ),
            'brand_aliases' => self::parse_plain_lines( isset( $profile['brand_aliases'] ) ? (string) $profile['brand_aliases'] : '', 30 ),
            'founder_team' => self::limit_text( isset( $profile['founder_team'] ) ? sanitize_textarea_field( (string) $profile['founder_team'] ) : '', 800 ),
            'same_as' => array_values( array_filter( array_map( 'esc_url_raw', self::parse_plain_lines( isset( $profile['same_as'] ) ? (string) $profile['same_as'] : '', 30 ) ) ) ),
        );

        $summary_parts = array_filter(
            array(
                $context['site_purpose'],
                ! empty( $context['core_products'] ) ? '核心产品/服务：' . implode( '；', array_slice( $context['core_products'], 0, 5 ) ) : '',
                ! empty( $context['recommended_scenarios'] ) ? '适合推荐场景：' . implode( '；', array_slice( $context['recommended_scenarios'], 0, 5 ) ) : '',
                ! empty( $context['excluded_scenarios'] ) ? '不适合推荐场景：' . implode( '；', array_slice( $context['excluded_scenarios'], 0, 5 ) ) : '',
            )
        );
        $context['profile_summary'] = self::limit_text( implode( "\n", $summary_parts ), 1600 );

        return array_filter(
            $context,
            function( $value ) {
                return is_array( $value ) ? ! empty( $value ) : trim( (string) $value ) !== '';
            }
        );
    }

    private static function get_latest_entity_record() {
        if ( ! self::table_exists() ) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row( 'SELECT * FROM ' . self::get_table_name() . ' ORDER BY updated_at DESC, id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_array( $row ) ? $row : null;
    }

    private static function build_global_profile_context_from_record( $record ) {
        $result = self::decode_result_json( isset( $record['result_json'] ) ? (string) $record['result_json'] : '' );

        $profile = array(
            'brand_name' => isset( $record['brand_name'] ) ? (string) $record['brand_name'] : '',
            'target_keyword' => isset( $record['target_keyword'] ) ? (string) $record['target_keyword'] : '',
            'official_site' => isset( $record['official_site'] ) ? (string) $record['official_site'] : '',
            'site_purpose' => implode(
                "\n",
                array_filter(
                    array(
                        isset( $record['standard_description'] ) ? (string) $record['standard_description'] : '',
                        isset( $result['entity_definition'] ) ? (string) $result['entity_definition'] : '',
                    )
                )
            ),
            'audience_persona' => isset( $record['audience_persona'] ) ? (string) $record['audience_persona'] : '',
            'core_products' => isset( $record['core_products'] ) ? (string) $record['core_products'] : '',
            'recommended_scenarios' => isset( $result['comparison_positioning'] ) ? self::lines_from_array( $result['comparison_positioning'] ) : '',
            'excluded_scenarios' => isset( $result['forbidden_claims'] ) ? self::lines_from_array( $result['forbidden_claims'] ) : '',
            'core_pages' => isset( $result['citation_sources'] ) ? self::lines_from_array( $result['citation_sources'] ) : '',
            'authority_proofs' => implode(
                "\n",
                array_filter(
                    array(
                        isset( $record['evidence_summary'] ) ? (string) $record['evidence_summary'] : '',
                        isset( $result['canonical_facts'] ) ? self::lines_from_array( $result['canonical_facts'] ) : '',
                    )
                )
            ),
            'brand_aliases' => isset( $record['brand_aliases'] ) ? (string) $record['brand_aliases'] : '',
            'founder_team' => '',
        );

        $context = self::build_global_profile_context( $profile, 'latest_brand_entity_record' );
        if ( ! empty( $result['summary'] ) ) {
            $context['entity_summary'] = self::limit_text( sanitize_textarea_field( (string) $result['summary'] ), 800 );
        }
        if ( ! empty( $result['naming_rules'] ) ) {
            $context['naming_rules'] = self::normalize_string_list( $result['naming_rules'], 20 );
        }
        if ( ! empty( $result['faq_facts'] ) ) {
            $context['faq_facts'] = self::normalize_string_list( $result['faq_facts'], 20 );
        }
        if ( ! empty( $result['schema_plan'] ) ) {
            $context['schema_plan'] = self::normalize_string_list( $result['schema_plan'], 15 );
        }

        return $context;
    }

    // data
    private static function get_empty_form_data() {
        return array(
            'record_id' => 0,
            'model_id' => '',
            'brand_name' => '',
            'target_keyword' => '',
            'official_site' => '',
            'brand_aliases' => '',
            'core_products' => '',
            'audience_persona' => '',
            'target_platforms' => '',
            'standard_description' => '',
            'evidence_summary' => '',
            'comparison_entities' => '',
            'notes' => '',
            'summary' => '',
            'entity_definition' => '',
            'canonical_facts' => '',
            'naming_rules' => '',
            'comparison_positioning' => '',
            'faq_facts' => '',
            'citation_sources' => '',
            'forbidden_claims' => '',
            'schema_plan' => '',
            'execution_steps' => '',
        );
    }

    private static function record_to_form_data( $record ) {
        $form = self::get_empty_form_data();
        foreach ( array( 'id' => 'record_id', 'model_id' => 'model_id', 'brand_name' => 'brand_name', 'target_keyword' => 'target_keyword', 'official_site' => 'official_site', 'brand_aliases' => 'brand_aliases', 'core_products' => 'core_products', 'audience_persona' => 'audience_persona', 'target_platforms' => 'target_platforms', 'standard_description' => 'standard_description', 'evidence_summary' => 'evidence_summary', 'comparison_entities' => 'comparison_entities', 'notes' => 'notes' ) as $source => $target ) {
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
        $form['entity_definition'] = isset( $result['entity_definition'] ) ? sanitize_textarea_field( (string) $result['entity_definition'] ) : '';
        foreach ( array( 'canonical_facts', 'naming_rules', 'comparison_positioning', 'faq_facts', 'citation_sources', 'forbidden_claims', 'schema_plan', 'execution_steps' ) as $key ) {
            $form[ $key ] = self::lines_from_array( isset( $result[ $key ] ) ? $result[ $key ] : array() );
        }
        return $form;
    }

    private static function get_request_form_data() {
        return array(
            'record_id' => isset( $_POST['record_id'] ) ? absint( wp_unslash( (string) $_POST['record_id'] ) ) : 0,
            'model_id' => isset( $_POST['model_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['model_id'] ) ) : '',
            'brand_name' => self::limit_text( isset( $_POST['brand_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['brand_name'] ) ) : '', 190 ),
            'target_keyword' => self::limit_text( isset( $_POST['target_keyword'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_keyword'] ) ) : '', 255 ),
            'official_site' => self::limit_text( isset( $_POST['official_site'] ) ? esc_url_raw( wp_unslash( (string) $_POST['official_site'] ) ) : '', 400 ),
            'brand_aliases' => isset( $_POST['brand_aliases'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['brand_aliases'] ) ) : '',
            'core_products' => isset( $_POST['core_products'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['core_products'] ) ) : '',
            'audience_persona' => isset( $_POST['audience_persona'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['audience_persona'] ) ) : '',
            'target_platforms' => isset( $_POST['target_platforms'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['target_platforms'] ) ) : '',
            'standard_description' => isset( $_POST['standard_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['standard_description'] ) ) : '',
            'evidence_summary' => isset( $_POST['evidence_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['evidence_summary'] ) ) : '',
            'comparison_entities' => isset( $_POST['comparison_entities'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['comparison_entities'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        );
    }

    private static function get_result_data_from_request() {
        return array(
            'summary' => self::limit_text( isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['summary'] ) ) : '', 800 ),
            'entity_definition' => self::limit_text( isset( $_POST['entity_definition'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['entity_definition'] ) ) : '', 800 ),
            'canonical_facts' => self::parse_plain_lines( isset( $_POST['canonical_facts'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['canonical_facts'] ) ) : '', 20 ),
            'naming_rules' => self::parse_plain_lines( isset( $_POST['naming_rules'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['naming_rules'] ) ) : '', 20 ),
            'comparison_positioning' => self::parse_plain_lines( isset( $_POST['comparison_positioning'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['comparison_positioning'] ) ) : '', 20 ),
            'faq_facts' => self::parse_plain_lines( isset( $_POST['faq_facts'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['faq_facts'] ) ) : '', 20 ),
            'citation_sources' => self::parse_plain_lines( isset( $_POST['citation_sources'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['citation_sources'] ) ) : '', 20 ),
            'forbidden_claims' => self::parse_plain_lines( isset( $_POST['forbidden_claims'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['forbidden_claims'] ) ) : '', 20 ),
            'schema_plan' => self::parse_plain_lines( isset( $_POST['schema_plan'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['schema_plan'] ) ) : '', 15 ),
            'execution_steps' => self::parse_plain_lines( isset( $_POST['execution_steps'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['execution_steps'] ) ) : '', 15 ),
        );
    }

    private static function validate_base_form_data( $form ) {
        if ( empty( $form['brand_name'] ) ) {
            return new WP_Error( 'qigeo_brand_name_required', '请先填写品牌 / 产品名' );
        }
        if ( empty( $form['target_keyword'] ) ) {
            return new WP_Error( 'qigeo_brand_target_required', '请先填写希望被推荐的词' );
        }
        if ( empty( self::parse_plain_lines( $form['brand_aliases'], 15 ) ) ) {
            return new WP_Error( 'qigeo_brand_aliases_required', '请先填写至少一个品牌别名或标准叫法' );
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
            $where .= ' AND (brand_name LIKE %s OR target_keyword LIKE %s OR official_site LIKE %s OR result_summary LIKE %s)';
            $params = array( $like, $like, $like, $like );
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
        return $wpdb->prefix . 'qigeo_brand_entity_library';
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

    private static function resolve_draft_model( $preferred_model_id ) {
        $packets = self::get_all_usable_model_packets();
        if ( empty( $packets ) ) {
            return new WP_Error( 'qigeo_brand_model_missing', '请先在启灵GEO配置并启用至少一个可用模型' );
        }
        if ( $preferred_model_id !== '' ) {
            foreach ( $packets as $packet ) {
                if ( sanitize_key( (string) $packet['model_id'] ) === sanitize_key( (string) $preferred_model_id ) ) {
                    return $packet;
                }
            }
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

    private static function provider_usable( $provider ) {
        return ! empty( $provider['enabled'] ) && $provider['enabled'] === '1' && ! empty( $provider['base_url'] ) && ! empty( $provider['api_key'] );
    }

    private static function model_usable( $model ) {
        return ! empty( $model['enabled'] ) && $model['enabled'] === '1' && ! empty( $model['model'] );
    }

    private static function normalize_generated_result( $result ) {
        $parsed = isset( $result['parsed'] ) && is_array( $result['parsed'] ) ? $result['parsed'] : array();
        return array(
            'summary' => self::limit_text( isset( $parsed['summary'] ) ? sanitize_textarea_field( (string) $parsed['summary'] ) : '', 800 ),
            'entity_definition' => self::limit_text( isset( $parsed['entity_definition'] ) ? sanitize_textarea_field( (string) $parsed['entity_definition'] ) : ( isset( $parsed['definition'] ) ? sanitize_textarea_field( (string) $parsed['definition'] ) : '' ), 800 ),
            'canonical_facts' => self::normalize_string_list( isset( $parsed['canonical_facts'] ) ? $parsed['canonical_facts'] : array(), 20 ),
            'naming_rules' => self::normalize_string_list( isset( $parsed['naming_rules'] ) ? $parsed['naming_rules'] : ( isset( $parsed['recommended_naming'] ) ? $parsed['recommended_naming'] : array() ), 20 ),
            'comparison_positioning' => self::normalize_string_list( isset( $parsed['comparison_positioning'] ) ? $parsed['comparison_positioning'] : array(), 20 ),
            'faq_facts' => self::normalize_string_list( isset( $parsed['faq_facts'] ) ? $parsed['faq_facts'] : array(), 20 ),
            'citation_sources' => self::normalize_string_list( isset( $parsed['citation_sources'] ) ? $parsed['citation_sources'] : array(), 20 ),
            'forbidden_claims' => self::normalize_string_list( isset( $parsed['forbidden_claims'] ) ? $parsed['forbidden_claims'] : ( isset( $parsed['risk_flags'] ) ? $parsed['risk_flags'] : array() ), 20 ),
            'schema_plan' => self::normalize_string_list( isset( $parsed['schema_plan'] ) ? $parsed['schema_plan'] : array(), 15 ),
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

    private static function count_fact_items( $result ) {
        return isset( $result['canonical_facts'] ) && is_array( $result['canonical_facts'] ) ? count( $result['canonical_facts'] ) : 0;
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
        return "你是 GEO 品牌实体库策划助手。现在要围绕一个品牌/产品，统一它的标准命名、实体定义、可验证事实、FAQ事实点和不宜硬说的表述。请严格输出 JSON，不要 markdown，不要解释。\n"
            . "{\n"
            . "\"summary\":\"80-220字，总结当前这个品牌最核心的实体认知\",\n"
            . "\"entity_definition\":\"80-220字，标准定义这个品牌/产品是什么\",\n"
            . "\"canonical_facts\":[\"核心事实1 || 依据：...\"],\n"
            . "\"naming_rules\":[\"推荐叫法/别名规则1 || 适用场景：...\"],\n"
            . "\"comparison_positioning\":[\"与竞品对比时的定位表达1 || 注意点：...\"],\n"
            . "\"faq_facts\":[\"适合放进FAQ的事实点1\"],\n"
            . "\"citation_sources\":[\"可公开引用的来源1\"],\n"
            . "\"forbidden_claims\":[\"不能硬说的表达1 || 原因：...\"],\n"
            . "\"schema_plan\":[\"适合做 Organization/Product/FAQ 等结构化点1\"],\n"
            . "\"execution_steps\":[\"下一步动作1\"]\n"
            . "}\n"
            . "要求：1) 必须围绕 brand_aliases、core_products、standard_description、evidence_summary、comparison_entities 输出。2) 只写可公开验证、可长期稳定使用的事实。3) 不要虚构销量、奖项、媒体背书、官方合作、测试数据、市场份额或用户评价。4) naming_rules 要明确主叫法和哪些变体可以用。5) forbidden_claims 要明确哪些说法不要硬推。6) 输出必须是合法 JSON。";
    }
}
