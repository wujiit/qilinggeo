<?php
/**
 * Plugin Name: 启灵GEO
 * Plugin URI: https://www.jingxialai.com
 * Description: 启灵 GEO 插件，支持多平台多模型分析文章、外部关键词、全局品牌资料、引用来源、证据补强、分项评分与结构化输出。
 * Version: 2.0.1
 * Author: Summer
 * Author URI: https://www.jingxialai.com
 * Text Domain: qilinggeo
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.2.35
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'QIGEO_VERSION', '2.0.1' );
define( 'QIGEO_PATH', plugin_dir_path( __FILE__ ) );
define( 'QIGEO_URL', plugin_dir_url( __FILE__ ) );
define( 'QIGEO_BASENAME', plugin_basename( __FILE__ ) );
define( 'QIGEO_OPTION_KEY', 'qigeo_settings' );

if ( ! function_exists( 'qigeo_is_supported_php' ) ) {
    /**
     * PHP 核心版本与安全基线检查（针对 CVE-2026-109618 / CVE-2026-109619 CVSS 9.8 核心漏洞）
     *
     * 分支安全基线：
     * PHP 8.2.x 分支：必须 >= 8.2.35
     * PHP 8.3.x 分支：必须 >= 8.3.36
     * PHP 8.4.x 分支：必须 >= 8.4.27
     * PHP 8.5.x 分支：必须 >= 8.5.12
     *
     * @return bool
     */
    function qigeo_is_supported_php() {
        $current = PHP_VERSION;

        // 彻底不满足低于 8.2 的淘汰旧版本
        if ( version_compare( $current, '8.2.0', '<' ) ) {
            return false;
        }

        $patch_baselines = array(
            '8.2' => '8.2.35',
            '8.3' => '8.3.36',
            '8.4' => '8.4.27',
            '8.5' => '8.5.12',
        );

        $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if ( isset( $patch_baselines[ $branch ] ) ) {
            return version_compare( $current, $patch_baselines[ $branch ], '>=' );
        }

        // 高于 8.5 的未来版本只要大于基线即可
        return version_compare( $current, '8.5.12', '>=' );
    }
}

if ( ! function_exists( 'qigeo_php_unsupported_message' ) ) {
    /**
     * PHP 不满足核心安全基线提示
     *
     * @return string
     */
    function qigeo_php_unsupported_message() {
        $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $patch_baselines = array(
            '8.2' => '8.2.35',
            '8.3' => '8.3.36',
            '8.4' => '8.4.27',
            '8.5' => '8.5.12',
        );
        $target = isset( $patch_baselines[ $branch ] ) ? $patch_baselines[ $branch ] : '8.2.35+';

        return sprintf(
            '启灵GEO 运行失败：当前服务器 PHP 版本（%s）未达到最低核心安全补丁基线。为防范 CVSS 9.8 核心 RCE 严重漏洞（CVE-2026-109618/19），当前分支最低要求为 PHP %s（安全分支基线：8.2 >= 8.2.35，8.3 >= 8.3.36，8.4 >= 8.4.27，8.5 >= 8.5.12），请及时升级 PHP 环境。',
            esc_html( PHP_VERSION ),
            esc_html( $target )
        );
    }
}

if ( ! function_exists( 'qigeo_is_supported_theme' ) ) {
    /**
     * 仅支持启灵主题（允许子主题：template 为 qiling）
     *
     * @return bool
     */
    function qigeo_is_supported_theme() {
        $theme = wp_get_theme();
        if ( ! $theme ) {
            return false;
        }

        $template = sanitize_key( (string) $theme->get_template() );
        return $template === 'qiling';
    }
}

if ( ! function_exists( 'qigeo_theme_unsupported_message' ) ) {
    /**
     * 不支持主题提示
     *
     * @return string
     */
    function qigeo_theme_unsupported_message() {
        return '启灵GEO 仅支持启灵主题（qiling），当前主题不受支持。';
    }
}

$qigeo_classes = array(
    'includes/class-qigeo-install.php',
    'includes/class-qigeo-admin-helper.php',
    'includes/class-qigeo-settings.php',
    'includes/class-qigeo-ai-client.php',
    'includes/class-qigeo-post-snapshot.php',
    'includes/class-qigeo-queue-worker.php',
    'includes/class-qigeo-payload-builder.php',
    'includes/class-qigeo-analyzer.php',
    'includes/class-qigeo-admin-post.php',
    'includes/class-qigeo-admin-logs.php',
    'includes/class-qigeo-admin-report.php',
    'includes/class-qigeo-frontend.php',
    'includes/class-qigeo-faq-library.php',
    'includes/class-qigeo-external-keyword.php',
    'includes/class-qigeo-question-library.php',
    'includes/class-qigeo-evidence-library.php',
    'includes/class-qigeo-backtest-center.php',
    'includes/class-qigeo-content-asset-center.php',
    'includes/class-qigeo-publish-queue.php',
    'includes/class-qigeo-brand-entity-library.php',
    'includes/class-qigeo-platform-adaptation-center.php',
    'includes/class-qigeo-platform-backtest-records.php',
    'includes/class-qigeo-platform-trend-dashboard.php',
    'includes/class-qigeo-competitor-pressure-center.php',
    'includes/class-qigeo-competitor-evidence-library.php',
    'includes/class-qigeo-llms-txt.php',
    'includes/class-qigeo-crawler-radar.php',
    'includes/class-qigeo-offpage-pack.php',
);

foreach ( $qigeo_classes as $qigeo_rel_file ) {
    $qigeo_file_path = QIGEO_PATH . $qigeo_rel_file;
    if ( file_exists( $qigeo_file_path ) ) {
        require_once $qigeo_file_path;
    }
}

register_activation_hook( __FILE__, function() {
    if ( ! qigeo_is_supported_php() ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins( QIGEO_BASENAME );

        wp_die(
            esc_html( qigeo_php_unsupported_message() ),
            '启灵GEO 激活失败（PHP 版本未达安全基线）',
            array(
                'response'  => 403,
                'back_link' => true,
            )
        );
    }

    if ( ! qigeo_is_supported_theme() ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins( QIGEO_BASENAME );

        wp_die(
            esc_html( qigeo_theme_unsupported_message() ),
            '启灵GEO 激活失败',
            array(
                'response'  => 403,
                'back_link' => true,
            )
        );
    }

    QiGeo_Install::activate();
} );
register_deactivation_hook( __FILE__, array( 'QiGeo_Install', 'deactivate' ) );

add_action( 'init', function() {
    load_plugin_textdomain( 'qilinggeo', false, dirname( QIGEO_BASENAME ) . '/languages' );
} );

add_action( 'admin_init', function() {
    if ( ! is_admin() || ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    if ( ! qigeo_is_supported_php() ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ( is_plugin_active( QIGEO_BASENAME ) ) {
            deactivate_plugins( QIGEO_BASENAME );
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'qigeo_php_error' => '1',
                    ),
                    admin_url( 'plugins.php' )
                )
            );
            exit;
        }
    }

    if ( ! qigeo_is_supported_theme() ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ( is_plugin_active( QIGEO_BASENAME ) ) {
            deactivate_plugins( QIGEO_BASENAME );
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'qigeo_theme_error' => '1',
                    ),
                    admin_url( 'plugins.php' )
                )
            );
            exit;
        }
    }
} );

add_action( 'admin_notices', function() {
    if ( ! is_admin() || ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    if ( ! empty( $_GET['qigeo_php_error'] ) && $_GET['qigeo_php_error'] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( qigeo_php_unsupported_message() ) . '</p></div>';
    }

    if ( ! empty( $_GET['qigeo_theme_error'] ) && $_GET['qigeo_theme_error'] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( qigeo_theme_unsupported_message() ) . '</p></div>';
    }
} );

add_action( 'plugins_loaded', function() {
    if ( ! qigeo_is_supported_php() || ! qigeo_is_supported_theme() ) {
        return;
    }

    $qigeo_init_classes = array(
        'QiGeo_Settings',
        'QiGeo_Queue_Worker',
        'QiGeo_Analyzer',
        'QiGeo_Admin_Post',
        'QiGeo_Admin_Logs',
        'QiGeo_Admin_Report',
        'QiGeo_Frontend',
        'QiGeo_FAQ_Library',
        'QiGeo_External_Keyword',
        'QiGeo_Question_Library',
        'QiGeo_Evidence_Library',
        'QiGeo_Backtest_Center',
        'QiGeo_Content_Asset_Center',
        'QiGeo_Publish_Queue',
        'QiGeo_Brand_Entity_Library',
        'QiGeo_Platform_Adaptation_Center',
        'QiGeo_Platform_Backtest_Records',
        'QiGeo_Platform_Trend_Dashboard',
        'QiGeo_Competitor_Pressure_Center',
        'QiGeo_Competitor_Evidence_Library',
        'QiGeo_LLMs_Txt',
        'QiGeo_Crawler_Radar',
        'QiGeo_Offpage_Pack',
    );

    foreach ( $qigeo_init_classes as $class_name ) {
        if ( class_exists( $class_name ) && method_exists( $class_name, 'init' ) ) {
            call_user_func( array( $class_name, 'init' ) );
        }
    }
} );

add_action( 'admin_enqueue_scripts', function() {
    wp_register_script( 'qigeo-admin', QIGEO_URL . 'assets/admin.js', array(), QIGEO_VERSION, true );
} );

add_filter( 'plugin_action_links_' . QIGEO_BASENAME, function( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=qigeo-settings' ) ) . '">' . esc_html__( '设置', 'qilinggeo' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
} );

function qigeo_register_rest_isolation_default_rule($rules) {
    $rules = is_array($rules) ? $rules : array();
    $default_rules = array(
        '/qigeo/v1/ => ' . QIGEO_BASENAME,
    );

    foreach ($default_rules as $rule) {
        if (!in_array($rule, $rules, true)) {
            $rules[] = $rule;
        }
    }

    return $rules;
}
add_filter('qs_rest_plugin_isolation_default_rule_lines', 'qigeo_register_rest_isolation_default_rule');
