<?php
/**
 * Fired when the plugin is uninstalled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = array(
    $wpdb->prefix . 'qigeo_records',
    $wpdb->prefix . 'qigeo_jobs',
    $wpdb->prefix . 'qigeo_post_snapshots',
    $wpdb->prefix . 'qigeo_data_store',
    $wpdb->prefix . 'qigeo_faq_library',
    $wpdb->prefix . 'qigeo_external_keywords',
    $wpdb->prefix . 'qigeo_question_library',
    $wpdb->prefix . 'qigeo_evidence_library',
    $wpdb->prefix . 'qigeo_backtest_center',
    $wpdb->prefix . 'qigeo_content_asset_center',
    $wpdb->prefix . 'qigeo_publish_queue',
    $wpdb->prefix . 'qigeo_brand_entity_library',
    $wpdb->prefix . 'qigeo_platform_adaptation',
    $wpdb->prefix . 'qigeo_platform_backtest_records',
    $wpdb->prefix . 'qigeo_competitor_pressure_center',
    $wpdb->prefix . 'qigeo_competitor_evidence_library',
    $wpdb->prefix . 'qigeo_crawler_logs',
);

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$option_prefix = $wpdb->esc_like( 'qigeo_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $option_prefix ) );

$transient_prefix = $wpdb->esc_like( '_transient_qigeo_' ) . '%';
$transient_timeout_prefix = $wpdb->esc_like( '_transient_timeout_qigeo_' ) . '%';

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_prefix ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_timeout_prefix ) );
