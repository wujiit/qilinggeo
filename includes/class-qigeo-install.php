<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Install {

    const DB_VERSION = '1.6.0';

    public static function activate() {
        self::create_tables();
        self::maybe_seed_options();
    }

    public static function create_tables() {
        global $wpdb;

        $record_table = $wpdb->prefix . 'qigeo_records';
        $job_table = $wpdb->prefix . 'qigeo_jobs';
        $post_snapshot_table = $wpdb->prefix . 'qigeo_post_snapshots';
        $data_store_table = $wpdb->prefix . 'qigeo_data_store';
        $faq_table = $wpdb->prefix . 'qigeo_faq_library';
        $external_keyword_table = $wpdb->prefix . 'qigeo_external_keywords';
        $question_library_table = $wpdb->prefix . 'qigeo_question_library';
        $evidence_library_table = $wpdb->prefix . 'qigeo_evidence_library';
        $backtest_center_table = $wpdb->prefix . 'qigeo_backtest_center';
        $content_asset_center_table = $wpdb->prefix . 'qigeo_content_asset_center';
        $publish_queue_table = $wpdb->prefix . 'qigeo_publish_queue';
        $brand_entity_library_table = $wpdb->prefix . 'qigeo_brand_entity_library';
        $platform_adaptation_table = $wpdb->prefix . 'qigeo_platform_adaptation';
        $platform_backtest_records_table = $wpdb->prefix . 'qigeo_platform_backtest_records';
        $competitor_pressure_center_table = $wpdb->prefix . 'qigeo_competitor_pressure_center';
        $competitor_evidence_library_table = $wpdb->prefix . 'qigeo_competitor_evidence_library';
        $crawler_logs_table = $wpdb->prefix . 'qigeo_crawler_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql_records = "CREATE TABLE {$record_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            provider_type VARCHAR(60) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            prompt_version VARCHAR(40) NOT NULL DEFAULT 'v3',
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            score DECIMAL(5,2) DEFAULT NULL,
            summary TEXT NULL,
            result_json LONGTEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY status (status),
            KEY created_at (created_at),
            KEY post_created (post_id, created_at),
            KEY post_id_id (post_id, id)
        ) {$charset_collate};";

        $sql_jobs = "CREATE TABLE {$job_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            trigger_type VARCHAR(20) NOT NULL DEFAULT 'auto',
            force_run TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            dedupe_key VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY status_created (status, created_at),
            KEY status_started (status, started_at),
            KEY post_status (post_id, status),
            KEY post_id (post_id),
            KEY dedupe_key (dedupe_key)
        ) {$charset_collate};";

        $sql_post_snapshots = "CREATE TABLE {$post_snapshot_table} (
            post_id BIGINT(20) UNSIGNED NOT NULL,
            source_url TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT '',
            score DECIMAL(5,2) DEFAULT NULL,
            last_run_at DATETIME NULL,
            latest_record_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            summary LONGTEXT NULL,
            optimized_content LONGTEXT NULL,
            faq_json LONGTEXT NULL,
            key_points_json LONGTEXT NULL,
            entities_json LONGTEXT NULL,
            keywords_json LONGTEXT NULL,
            rewrite_suggestions_json LONGTEXT NULL,
            citation_sources_json LONGTEXT NULL,
            evidence_claims_json LONGTEXT NULL,
            source_gaps_json LONGTEXT NULL,
            schema_recommendations_json LONGTEXT NULL,
            internal_link_suggestions_json LONGTEXT NULL,
            crawlability_checks_json LONGTEXT NULL,
            entity_profile_json LONGTEXT NULL,
            geo_score_breakdown_json LONGTEXT NULL,
            frontend_eligible TINYINT(1) NULL,
            quality_gate_reason TEXT NULL,
            result_json LONGTEXT NULL,
            content_hash VARCHAR(64) NOT NULL DEFAULT '',
            geo_package_json LONGTEXT NULL,
            geo_package_text LONGTEXT NULL,
            secondary_status VARCHAR(20) NOT NULL DEFAULT '',
            secondary_last_run_at DATETIME NULL,
            secondary_provider_id VARCHAR(100) NOT NULL DEFAULT '',
            secondary_model VARCHAR(255) NOT NULL DEFAULT '',
            secondary_summary LONGTEXT NULL,
            secondary_result_json LONGTEXT NULL,
            secondary_error TEXT NULL,
            ai_feed_status VARCHAR(20) NOT NULL DEFAULT '',
            ai_feed_last_run_at DATETIME NULL,
            ai_feed_provider_id VARCHAR(255) NOT NULL DEFAULT '',
            ai_feed_model VARCHAR(255) NOT NULL DEFAULT '',
            ai_feed_summary LONGTEXT NULL,
            ai_feed_result_json LONGTEXT NULL,
            ai_feed_error TEXT NULL,
            use_ai_seo TINYINT(1) NOT NULL DEFAULT 0,
            seo_backup_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (post_id),
            KEY status (status),
            KEY score (score),
            KEY last_run_at (last_run_at),
            KEY frontend_eligible (frontend_eligible),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_data_store = "CREATE TABLE {$data_store_table} (
            store_key VARCHAR(100) NOT NULL,
            value_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (store_key),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_faq = "CREATE TABLE {$faq_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            post_url TEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            score DECIMAL(5,2) DEFAULT NULL,
            summary LONGTEXT NULL,
            key_points_json LONGTEXT NULL,
            faq_json LONGTEXT NULL,
            suggestions_json LONGTEXT NULL,
            seo_title VARCHAR(255) NOT NULL DEFAULT '',
            seo_keywords TEXT NULL,
            seo_description TEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY model_id (model_id),
            KEY created_at (created_at),
            KEY updated_at (updated_at),
            KEY post_updated (post_id, updated_at)
        ) {$charset_collate};";

        $sql_external_keyword = "CREATE TABLE {$external_keyword_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            query_keyword VARCHAR(255) NOT NULL DEFAULT '',
            target_platform VARCHAR(120) NOT NULL DEFAULT '',
            target_keyword VARCHAR(255) NOT NULL DEFAULT '',
            brand_name VARCHAR(190) NOT NULL DEFAULT '',
            landing_url TEXT NULL,
            site_positioning LONGTEXT NULL,
            source_links LONGTEXT NULL,
            business_summary LONGTEXT NULL,
            selling_points LONGTEXT NULL,
            proof_points LONGTEXT NULL,
            notes LONGTEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            result_summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY query_keyword (query_keyword(191)),
            KEY target_platform (target_platform),
            KEY target_keyword (target_keyword(191)),
            KEY model_id (model_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_question_library = "CREATE TABLE {$question_library_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            seed_keyword VARCHAR(255) NOT NULL DEFAULT '',
            target_platform VARCHAR(120) NOT NULL DEFAULT '',
            target_keyword VARCHAR(255) NOT NULL DEFAULT '',
            brand_name VARCHAR(190) NOT NULL DEFAULT '',
            audience_persona VARCHAR(190) NOT NULL DEFAULT '',
            business_summary LONGTEXT NULL,
            competitor_terms LONGTEXT NULL,
            notes LONGTEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            result_summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY seed_keyword (seed_keyword(191)),
            KEY target_platform (target_platform),
            KEY target_keyword (target_keyword(191)),
            KEY model_id (model_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_evidence_library = "CREATE TABLE {$evidence_library_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            seed_keyword VARCHAR(255) NOT NULL DEFAULT '',
            target_platform VARCHAR(120) NOT NULL DEFAULT '',
            target_keyword VARCHAR(255) NOT NULL DEFAULT '',
            brand_name VARCHAR(190) NOT NULL DEFAULT '',
            audience_persona VARCHAR(190) NOT NULL DEFAULT '',
            priority_questions LONGTEXT NULL,
            value_claims LONGTEXT NULL,
            existing_evidence LONGTEXT NULL,
            concern_points LONGTEXT NULL,
            notes LONGTEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            result_summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY seed_keyword (seed_keyword(191)),
            KEY target_platform (target_platform),
            KEY target_keyword (target_keyword(191)),
            KEY model_id (model_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_backtest_center = "CREATE TABLE {$backtest_center_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            seed_keyword VARCHAR(255) NOT NULL DEFAULT '',
            target_platform VARCHAR(120) NOT NULL DEFAULT '',
            target_keyword VARCHAR(255) NOT NULL DEFAULT '',
            brand_name VARCHAR(190) NOT NULL DEFAULT '',
            audience_persona VARCHAR(190) NOT NULL DEFAULT '',
            question_set LONGTEXT NULL,
            evidence_summary LONGTEXT NULL,
            content_updates LONGTEXT NULL,
            notes LONGTEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            result_summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY seed_keyword (seed_keyword(191)),
            KEY target_platform (target_platform),
            KEY target_keyword (target_keyword(191)),
            KEY model_id (model_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_content_asset_center = "CREATE TABLE {$content_asset_center_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            seed_keyword VARCHAR(255) NOT NULL DEFAULT '',
            target_platform VARCHAR(120) NOT NULL DEFAULT '',
            target_keyword VARCHAR(255) NOT NULL DEFAULT '',
            brand_name VARCHAR(190) NOT NULL DEFAULT '',
            audience_persona VARCHAR(190) NOT NULL DEFAULT '',
            priority_questions LONGTEXT NULL,
            key_claims LONGTEXT NULL,
            evidence_summary LONGTEXT NULL,
            backtest_summary LONGTEXT NULL,
            notes LONGTEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            result_summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY seed_keyword (seed_keyword(191)),
            KEY target_platform (target_platform),
            KEY target_keyword (target_keyword(191)),
            KEY model_id (model_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_publish_queue = "CREATE TABLE {$publish_queue_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            seed_keyword VARCHAR(255) NOT NULL DEFAULT '',
            target_platform VARCHAR(120) NOT NULL DEFAULT '',
            target_keyword VARCHAR(255) NOT NULL DEFAULT '',
            brand_name VARCHAR(190) NOT NULL DEFAULT '',
            audience_persona VARCHAR(190) NOT NULL DEFAULT '',
            asset_summary LONGTEXT NULL,
            priority_questions LONGTEXT NULL,
            backtest_summary LONGTEXT NULL,
            notes LONGTEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            result_summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY seed_keyword (seed_keyword(191)),
            KEY target_platform (target_platform),
            KEY target_keyword (target_keyword(191)),
            KEY model_id (model_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_brand_entity_library = "CREATE TABLE {$brand_entity_library_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            brand_name VARCHAR(190) NOT NULL DEFAULT '',
            target_keyword VARCHAR(255) NOT NULL DEFAULT '',
            official_site TEXT NULL,
            brand_aliases LONGTEXT NULL,
            core_products LONGTEXT NULL,
            audience_persona LONGTEXT NULL,
            target_platforms LONGTEXT NULL,
            standard_description LONGTEXT NULL,
            evidence_summary LONGTEXT NULL,
            comparison_entities LONGTEXT NULL,
            notes LONGTEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            result_summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY brand_name (brand_name(190)),
            KEY target_keyword (target_keyword(191)),
            KEY model_id (model_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_platform_adaptation = "CREATE TABLE {$platform_adaptation_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            target_platform VARCHAR(120) NOT NULL DEFAULT '',
            target_keyword VARCHAR(255) NOT NULL DEFAULT '',
            brand_name VARCHAR(190) NOT NULL DEFAULT '',
            audience_persona VARCHAR(190) NOT NULL DEFAULT '',
            brand_entity_summary LONGTEXT NULL,
            evidence_summary LONGTEXT NULL,
            backtest_summary LONGTEXT NULL,
            content_asset_summary LONGTEXT NULL,
            competitor_context LONGTEXT NULL,
            notes LONGTEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            result_summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY target_platform (target_platform),
            KEY target_keyword (target_keyword(191)),
            KEY brand_name (brand_name(190)),
            KEY model_id (model_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_platform_backtest_records = "CREATE TABLE {$platform_backtest_records_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            target_platform VARCHAR(120) NOT NULL DEFAULT '',
            target_keyword VARCHAR(255) NOT NULL DEFAULT '',
            brand_name VARCHAR(190) NOT NULL DEFAULT '',
            audience_persona VARCHAR(190) NOT NULL DEFAULT '',
            test_question LONGTEXT NULL,
            expected_direction LONGTEXT NULL,
            response_excerpt LONGTEXT NULL,
            response_source TEXT NULL,
            mention_status VARCHAR(60) NOT NULL DEFAULT '',
            observed_competitors LONGTEXT NULL,
            asset_context LONGTEXT NULL,
            adaptation_context LONGTEXT NULL,
            notes LONGTEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            result_summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY target_platform (target_platform),
            KEY target_keyword (target_keyword(191)),
            KEY brand_name (brand_name(190)),
            KEY mention_status (mention_status),
            KEY model_id (model_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_competitor_pressure_center = "CREATE TABLE {$competitor_pressure_center_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            target_platform VARCHAR(120) NOT NULL DEFAULT '',
            target_keyword VARCHAR(255) NOT NULL DEFAULT '',
            brand_name VARCHAR(190) NOT NULL DEFAULT '',
            audience_persona VARCHAR(190) NOT NULL DEFAULT '',
            primary_competitors LONGTEXT NULL,
            suppression_samples LONGTEXT NULL,
            competitor_strengths LONGTEXT NULL,
            brand_strengths LONGTEXT NULL,
            evidence_gap_summary LONGTEXT NULL,
            asset_gap_summary LONGTEXT NULL,
            adaptation_context LONGTEXT NULL,
            notes LONGTEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            result_summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY target_platform (target_platform),
            KEY target_keyword (target_keyword(191)),
            KEY brand_name (brand_name(190)),
            KEY model_id (model_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_competitor_evidence_library = "CREATE TABLE {$competitor_evidence_library_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            target_platform VARCHAR(120) NOT NULL DEFAULT '',
            target_keyword VARCHAR(255) NOT NULL DEFAULT '',
            brand_name VARCHAR(190) NOT NULL DEFAULT '',
            competitor_name VARCHAR(190) NOT NULL DEFAULT '',
            audience_persona VARCHAR(190) NOT NULL DEFAULT '',
            sample_questions LONGTEXT NULL,
            suppression_samples LONGTEXT NULL,
            competitor_evidence LONGTEXT NULL,
            brand_evidence LONGTEXT NULL,
            evidence_links LONGTEXT NULL,
            notes LONGTEXT NULL,
            provider_id VARCHAR(100) NOT NULL DEFAULT '',
            model_id VARCHAR(120) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            result_summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY target_platform (target_platform),
            KEY target_keyword (target_keyword(191)),
            KEY brand_name (brand_name(190)),
            KEY competitor_name (competitor_name(190)),
            KEY model_id (model_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_crawler_logs = "CREATE TABLE {$crawler_logs_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            bot_name VARCHAR(50) NOT NULL DEFAULT '',
            bot_label VARCHAR(100) NOT NULL DEFAULT '',
            request_uri TEXT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 200,
            ip VARCHAR(100) NOT NULL DEFAULT '',
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY bot_name (bot_name),
            KEY post_id (post_id),
            KEY status_code (status_code),
            KEY created_at (created_at),
            KEY bot_created (bot_name, created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_records );
        dbDelta( $sql_jobs );
        dbDelta( $sql_post_snapshots );
        dbDelta( $sql_data_store );
        dbDelta( $sql_faq );
        dbDelta( $sql_external_keyword );
        dbDelta( $sql_question_library );
        dbDelta( $sql_evidence_library );
        dbDelta( $sql_backtest_center );
        dbDelta( $sql_content_asset_center );
        dbDelta( $sql_publish_queue );
        dbDelta( $sql_brand_entity_library );
        dbDelta( $sql_platform_adaptation );
        dbDelta( $sql_platform_backtest_records );
        dbDelta( $sql_competitor_pressure_center );
        dbDelta( $sql_competitor_evidence_library );
        dbDelta( $sql_crawler_logs );

        update_option( 'qigeo_db_version', self::DB_VERSION, false );
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( QiGeo_Analyzer::CRON_HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, QiGeo_Analyzer::CRON_HOOK );
            $timestamp = wp_next_scheduled( QiGeo_Analyzer::CRON_HOOK );
        }
    }

    private static function maybe_seed_options() {
        $current = get_option( QIGEO_OPTION_KEY );
        if ( is_array( $current ) ) {
            return;
        }

        $default_provider_id = 'openai_default';
        $default_model_id = 'openai_gpt4o_mini';

        $defaults = array(
            'enable_auto_analyze' => '1',
            'analysis_mode' => 'fast',
            'compare_winner_strategy' => 'default',
            'enable_secondary_ai_push' => '0',
            'secondary_provider_id' => '',
            'secondary_model_id' => '',
            'secondary_prompt' => self::default_secondary_prompt_template(),
            'allowed_post_types'  => array( 'post' ),
            'analysis_prompt'     => self::default_prompt_template(),
            'default_provider_id' => $default_provider_id,
            'default_model_id'    => $default_model_id,
            'append_summary_block' => '0',
            'append_faq_block'    => '1',
            'append_key_entities_block' => '1',
            'enable_faq_schema'   => '1',
            'enable_org_schema'   => '1',
            'enable_llms_txt'     => '1',
            'enable_crawler_radar' => '1',
            'crawler_radar_retention_days' => 30,
            'disable_schema_on_noindex' => '1',
            'faq_max_items'       => 5,
            'enable_quality_gate' => '1',
            'quality_gate_min_score' => 60,
            'quality_gate_min_summary_len' => 80,
            'quality_gate_min_faq_items' => 2,
            'ai_request_timeout' => 60,
            'ai_request_retries' => 0,
            'enable_strict_json_mode' => '1',
            'enable_json_repair_retry' => '0',
            'history_keep_count'  => 20,
            'providers'           => array(
                array(
                    'id'       => $default_provider_id,
                    'name'     => 'OpenAI',
                    'type'     => 'openai',
                    'base_url' => 'https://api.openai.com/v1',
                    'api_key'  => '',
                    'enabled'  => '1',
                ),
                array(
                    'id'       => 'deepseek_default',
                    'name'     => 'DeepSeek',
                    'type'     => 'deepseek',
                    'base_url' => 'https://api.deepseek.com/v1',
                    'api_key'  => '',
                    'enabled'  => '0',
                ),
                array(
                    'id'       => 'qwen_default',
                    'name'     => '通义千问',
                    'type'     => 'qwen',
                    'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                    'api_key'  => '',
                    'enabled'  => '0',
                ),
                array(
                    'id'       => 'doubao_default',
                    'name'     => '豆包（火山方舟）',
                    'type'     => 'doubao',
                    'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
                    'api_key'  => '',
                    'enabled'  => '0',
                ),
            ),
            'models'              => array(
                array(
                    'id'          => $default_model_id,
                    'provider_id' => $default_provider_id,
                    'label'       => 'gpt-4o-mini',
                    'model'       => 'gpt-4o-mini',
                    'enabled'     => '1',
                ),
                array(
                    'id'          => 'deepseek_chat_default',
                    'provider_id' => 'deepseek_default',
                    'label'       => 'deepseek-chat',
                    'model'       => 'deepseek-chat',
                    'enabled'     => '1',
                ),
                array(
                    'id'          => 'qwen_turbo_default',
                    'provider_id' => 'qwen_default',
                    'label'       => 'qwen-turbo',
                    'model'       => 'qwen-turbo',
                    'enabled'     => '1',
                ),
                array(
                    'id'          => 'doubao_default_model',
                    'provider_id' => 'doubao_default',
                    'label'       => '豆包接入点（示例）',
                    'model'       => 'ep-xxxxxxxx',
                    'enabled'     => '0',
                ),
            ),
        );

        add_option( QIGEO_OPTION_KEY, $defaults, '', false );
    }

    public static function default_prompt_template() {
        return "你是网站 GEO 与内容优化助手。请基于我提供的文章，输出严格 JSON（不要 markdown，不要解释）。\n"
            . "JSON 结构如下：\n"
            . "{\n"
            . "  \"score\": 0,\n"
            . "  \"summary\": \"100-220字中文摘要\",\n"
            . "  \"optimized_content\": \"面向 AI 抓取和理解重新整理后的文章内容，保留真实事实、关键结论、FAQ 线索和来源链接\",\n"
            . "  \"key_points\": [\"要点1\",\"要点2\"],\n"
            . "  \"faq\": [{\"q\":\"问题\",\"a\":\"回答\"}],\n"
            . "  \"entities\": [\"实体1\",\"实体2\"],\n"
            . "  \"keywords\": [\"关键词1\",\"关键词2\"],\n"
            . "  \"rewrite_suggestions\": [\"建议1\",\"建议2\"],\n"
            . "  \"citation_sources\": [{\"title\":\"自有资料标题\",\"url\":\"https://本站或官方自有URL\",\"claim\":\"该自有资料支撑的结论\",\"source_type\":\"official|doc|case|review|media|faq|other\"}],\n"
            . "  \"evidence_claims\": [\"页面中可以被 AI 引用的事实或结论\"],\n"
            . "  \"source_gaps\": [\"还缺少的自有公开页面、数据、案例或文档\"],\n"
            . "  \"schema_recommendations\": [\"建议补充的 Schema 类型或字段\"],\n"
            . "  \"internal_link_suggestions\": [\"建议补充的站内链接或锚文本\"],\n"
            . "  \"crawlability_checks\": [\"影响 AI 抓取、理解、推荐的检查项\"],\n"
            . "  \"geo_score_breakdown\": {\n"
            . "    \"content_match\": {\"score\": 0, \"reason\": \"内容匹配分理由\"},\n"
            . "    \"entity_clarity\": {\"score\": 0, \"reason\": \"实体清晰度理由\"},\n"
            . "    \"evidence_sufficiency\": {\"score\": 0, \"reason\": \"证据充分度理由\"},\n"
            . "    \"source_coverage\": {\"score\": 0, \"reason\": \"来源覆盖度理由\"},\n"
            . "    \"structured_data\": {\"score\": 0, \"reason\": \"结构化数据完整度理由\"},\n"
            . "    \"crawlability\": {\"score\": 0, \"reason\": \"可抓取性理由\"},\n"
            . "    \"recommendation_strength\": {\"score\": 0, \"reason\": \"推荐理由强度理由\"}\n"
            . "  },\n"
            . "  \"entity_profile\": {\"primary_entity\":\"主实体\",\"entity_type\":\"品牌|产品|服务|人物|机构|主题\",\"positioning\":\"实体定位\",\"audience\":\"适合人群或场景\"}\n"
            . "}\n"
            . "要求：score 和 geo_score_breakdown 中所有 score 都填 0-100 数字；faq 最多 6 条；answer 简洁可执行；optimized_content 要像可直接投喂给 AI 平台的资料卡，包含内容、摘要、关键结论、FAQ 线索和来源链接，但不要改写事实；不要虚构来源 URL、数据、奖项、案例或媒体背书；自己网站 GEO 的 citation_sources 只能使用本站或官方自有页面，例如当前文章、站内承接页、官网核心页、产品/服务页、帮助文档、FAQ、案例页；不能为了凑来源推荐无关外链，不能把外部资料作为前台展示或 Schema 引用；没有足够自有来源时，citation_sources 给空数组，并在 source_gaps 说明要补哪些自有页面或资料；schema_recommendations 和 crawlability_checks 要给可执行建议；必须基于 payload 中的 site、author、seo_meta、headings、links、detected_source_urls、content_images、crawlability、existing_json_ld_types、geo_readiness_signals 判断页面作为 AI 可引用资料的完整度，其中 detected_source_urls 只作为后台参考线索；geo_score_breakdown 必须给满 7 个分项，理由要指出具体缺口；如果信息不足，保持字段存在但给空数组或保守描述。";
    }

    public static function default_secondary_prompt_template() {
        return "你是目标 AI 平台的多模型分析助手。你会收到一份已经整理完成的正式资料，资料只包含标题、关键词、描述、摘要、核心要点、正文内容、FAQ 和文章链接。请分析文章主题、正文内容、FAQ 和原文链接，并判断这份资料是否适合被后续回答引用。\n"
            . "不要承诺跨会话长期记忆、全网索引或真实搜索收录；你只需要给出当前模型对这份资料的分析结论、可引用性和仍缺少的内容。\n"
            . "请只输出严格 JSON，不要 markdown，不要解释。JSON 结构如下：\n"
            . "{\n"
            . "  \"acceptance_score\": 0,\n"
            . "  \"ingestion_status\": \"accepted|needs_more_context\",\n"
            . "  \"platform_fit_summary\": \"我已理解这篇资料的主题、内容和原文链接，或说明还缺少什么\",\n"
            . "  \"source_url\": \"内容包里的当前文章原文链接\",\n"
            . "  \"canonical_citation\": \"建议引用表述，例如：文章标题 - 原文链接\",\n"
            . "  \"understood_entities\": [\"你已识别的品牌、产品、服务、主题实体\"],\n"
            . "  \"answering_guidance\": [\"以后回答相关问题时应如何使用这份资料\"],\n"
            . "  \"ready_to_manual_submit\": false,\n"
            . "  \"missing_items\": [\"缺少的数据、来源或上下文\"],\n"
            . "  \"rewrite_todos\": [\"还需要补充或调整的内容\"],\n"
            . "  \"faq_todos\": [\"FAQ 需要补充的问题\"],\n"
            . "  \"source_todos\": [\"原文链接或内容出处需要补强的点\"]\n"
            . "}\n"
            . "要求：acceptance_score 填 0-100 数字；source_url 必须使用内容包里的当前文章原文链接；不要虚构新来源、奖项、案例、数据或媒体背书；如果资料足够你在当前上下文中理解并引用，ingestion_status 填 accepted，ready_to_manual_submit 填 true；如果还缺关键上下文，列入 missing_items/source_todos。";
    }
}
