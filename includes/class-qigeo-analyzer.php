<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Analyzer {

    const CRON_HOOK = 'qigeo_process_queue';
    const AJAX_WORKER_ACTION = 'qigeo_process_queue_async';
    const REST_NAMESPACE = 'qigeo/v1';
    const REST_ROUTE_TRIGGER = '/queue/trigger';
    const WORKER_TOKEN_OPTION = 'qigeo_worker_token';
    const LOCK_TRANSIENT_KEY = 'qigeo_queue_lock';
    const LOCK_TTL = 120;
    const PROCESSING_STALE_SECONDS = 900;
    const POST_ACTION_LOCK_TTL = 180;
    const MAX_SYNC_TARGET_MODELS = 2;

    const META_STATUS = 'qigeo_status';
    const META_SCORE = 'qigeo_score';
    const META_SOURCE_URL = 'qigeo_source_url';
    const META_LAST_RUN = 'qigeo_last_run_at';
    const META_LATEST_RECORD_ID = 'qigeo_latest_record_id';
    const META_PROVIDER_ID = 'qigeo_provider_id';
    const META_MODEL = 'qigeo_model';
    const META_SUMMARY = 'qigeo_summary';
    const META_OPTIMIZED_CONTENT = 'qigeo_optimized_content';
    const META_FAQ_JSON = 'qigeo_faq_json';
    const META_KEY_POINTS_JSON = 'qigeo_key_points_json';
    const META_ENTITIES_JSON = 'qigeo_entities_json';
    const META_KEYWORDS_JSON = 'qigeo_keywords_json';
    const META_REWRITE_SUGGESTIONS = 'qigeo_rewrite_suggestions_json';
    const META_CITATION_SOURCES_JSON = 'qigeo_citation_sources_json';
    const META_EVIDENCE_CLAIMS_JSON = 'qigeo_evidence_claims_json';
    const META_SOURCE_GAPS_JSON = 'qigeo_source_gaps_json';
    const META_SCHEMA_RECOMMENDATIONS_JSON = 'qigeo_schema_recommendations_json';
    const META_INTERNAL_LINK_SUGGESTIONS_JSON = 'qigeo_internal_link_suggestions_json';
    const META_CRAWLABILITY_CHECKS_JSON = 'qigeo_crawlability_checks_json';
    const META_ENTITY_PROFILE_JSON = 'qigeo_entity_profile_json';
    const META_GEO_SCORE_BREAKDOWN_JSON = 'qigeo_geo_score_breakdown_json';
    const META_FRONTEND_ELIGIBLE = 'qigeo_frontend_eligible';
    const META_QUALITY_GATE_REASON = 'qigeo_quality_gate_reason';
    const META_RESULT_JSON = 'qigeo_result_json';
    const META_CONTENT_HASH = 'qigeo_content_hash';
    const META_GEO_PACKAGE_JSON = 'qigeo_geo_package_json';
    const META_GEO_PACKAGE_TEXT = 'qigeo_geo_package_text';
    const META_SECONDARY_STATUS = 'qigeo_secondary_status';
    const META_SECONDARY_LAST_RUN = 'qigeo_secondary_last_run_at';
    const META_SECONDARY_PROVIDER_ID = 'qigeo_secondary_provider_id';
    const META_SECONDARY_MODEL = 'qigeo_secondary_model';
    const META_SECONDARY_SUMMARY = 'qigeo_secondary_summary';
    const META_SECONDARY_RESULT_JSON = 'qigeo_secondary_result_json';
    const META_SECONDARY_ERROR = 'qigeo_secondary_error';
    const META_AI_FEED_STATUS = 'qigeo_ai_feed_status';
    const META_AI_FEED_LAST_RUN = 'qigeo_ai_feed_last_run_at';
    const META_AI_FEED_PROVIDER_ID = 'qigeo_ai_feed_provider_id';
    const META_AI_FEED_MODEL = 'qigeo_ai_feed_model';
    const META_AI_FEED_SUMMARY = 'qigeo_ai_feed_summary';
    const META_AI_FEED_RESULT_JSON = 'qigeo_ai_feed_result_json';
    const META_AI_FEED_ERROR = 'qigeo_ai_feed_error';
    const META_USE_AI_SEO = '_qigeo_use_ai_seo';
    const META_LEGACY_SEO_BACKUP_JSON = '_qigeo_backup_theme_seo';
    const META_SEO_BACKUP_JSON = '_qigeo_seo_backup_json';

    public static function init() {
        add_action( 'save_post', array( __CLASS__, 'maybe_auto_analyze' ), 20, 3 );
    }

    public static function get_geo_score_dimensions() {
        return self::geo_score_dimensions();
    }

    public static function get_article_source_url( $post_id, $generate_if_missing = true ) {
        return QiGeo_Post_Snapshot::get_article_source_url( $post_id, $generate_if_missing );
    }

    public static function update_article_source_url( $post_id, $source_url ) {
        return QiGeo_Post_Snapshot::update_article_source_url( $post_id, $source_url );
    }

    public static function get_snapshot_value( $post_id, $meta_key, $default = '' ) {
        return QiGeo_Post_Snapshot::get_value( $post_id, $meta_key, $default );
    }

    public static function update_snapshot_value( $post_id, $meta_key, $value ) {
        return QiGeo_Post_Snapshot::update_value( $post_id, $meta_key, $value );
    }

    public static function update_snapshot_values( $post_id, $values ) {
        return QiGeo_Post_Snapshot::update_values( $post_id, $values );
    }

    public static function delete_snapshot_value( $post_id, $meta_key ) {
        return QiGeo_Post_Snapshot::delete_value( $post_id, $meta_key );
    }

    public static function maybe_auto_analyze( $post_id, $post, $update ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! $post instanceof WP_Post ) {
            return;
        }

        if ( $post->post_status !== 'publish' ) {
            return;
        }

        $settings = QiGeo_Settings::get_settings();
        if ( empty( $settings['enable_auto_analyze'] ) || $settings['enable_auto_analyze'] !== '1' ) {
            return;
        }

        $allowed_types = isset( $settings['allowed_post_types'] ) && is_array( $settings['allowed_post_types'] )
            ? $settings['allowed_post_types']
            : array( 'post' );

        if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
            return;
        }

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        self::enqueue_job( $post_id, 'auto', false );
    }

    public static function register_cron_schedules( $schedules ) {
        return QiGeo_Queue_Worker::register_cron_schedules( $schedules );
    }

    public static function enqueue_job( $post_id, $trigger_type = 'auto', $force_run = false ) {
        return QiGeo_Queue_Worker::enqueue_job( $post_id, $trigger_type, $force_run );
    }

    public static function process_queue_worker() {
        QiGeo_Queue_Worker::process_queue_worker();
    }

    public static function run_for_post( $post_id, $force = false ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return new WP_Error( 'qigeo_invalid_post', '无效文章 ID' );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return new WP_Error( 'qigeo_post_missing', '文章不存在' );
        }

        if ( $post->post_status !== 'publish' ) {
            return new WP_Error( 'qigeo_post_not_published', '仅支持已发布文章分析' );
        }

        $settings = QiGeo_Settings::get_settings();

        $candidates = self::resolve_provider_model_candidates( $settings );
        if ( empty( $candidates ) ) {
            return self::save_failed_result( $post_id, '第一阶段分析平台或模型不可用，请检查默认平台、默认模型、访问密钥和启用状态。' );
        }

        $payload = self::build_article_payload( $post );
        $content_hash = md5( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

        if ( ! $force ) {
            $last_hash = (string) self::get_snapshot_value( $post_id, self::META_CONTENT_HASH );
            if ( $last_hash !== '' && hash_equals( $last_hash, $content_hash ) ) {
                return array(
                    'success' => true,
                    'skipped' => true,
                    'message' => '文章内容未变化，已跳过分析',
                );
            }
        }

        $prompt = self::build_runtime_analysis_prompt( $settings );
        $analysis_mode = 'single';

        $success_results = array();
        $errors = array();

        foreach ( $candidates as $candidate ) {
            $provider_try = $candidate['provider'];
            $model_try = $candidate['model'];
            $attempt = QiGeo_AI_Client::analyze_post( $provider_try, $model_try, $prompt, $payload, self::get_article_analysis_runtime( $settings ) );
            if ( is_wp_error( $attempt ) ) {
                $error_message = $attempt->get_error_message();
                $errors[] = sprintf(
                    '[%s/%s] %s',
                    isset( $provider_try['id'] ) ? (string) $provider_try['id'] : 'provider',
                    isset( $model_try['model'] ) ? (string) $model_try['model'] : 'model',
                    $error_message
                );
                continue;
            }

            $normalized = self::normalize_ai_success_result( $attempt, $settings );
            $normalized['provider'] = $provider_try;
            $normalized['model'] = $model_try;

            $record_id = self::insert_record( array(
                'post_id'       => $post_id,
                'provider_id'   => isset( $provider_try['id'] ) ? (string) $provider_try['id'] : '',
                'provider_type' => isset( $provider_try['type'] ) ? (string) $provider_try['type'] : '',
                'model'         => isset( $model_try['model'] ) ? (string) $model_try['model'] : '',
                'status'        => 'success',
                'score'         => $normalized['score'],
                'summary'       => $normalized['summary'],
                'result_json'   => $normalized['result_json'],
                'error_message' => '',
            ) );

            if ( ! $record_id ) {
                return new WP_Error( 'qigeo_db_insert_failed', '分析完成，但保存记录失败' );
            }

            $normalized['record_id'] = $record_id;
            $success_results[] = $normalized;
            break;
        }

        if ( empty( $success_results ) ) {
            $error_message = '第一阶段分析平台/模型调用失败';
            if ( ! empty( $errors ) ) {
                $error_message .= '：' . implode( ' | ', array_slice( $errors, 0, 5 ) );
            }

            return self::save_failed_result( $post_id, $error_message );
        }

        $winner = $success_results[0];
        if ( ! is_array( $winner ) || empty( $winner ) ) {
            $winner = $success_results[0];
        }

        self::persist_selected_result_to_post( $post_id, $winner, $content_hash, $settings );

        self::cleanup_history( $post_id, $settings );

        return array(
            'success' => true,
            'analysis_mode' => $analysis_mode,
            'successful_candidates' => count( $success_results ),
            'record_id' => isset( $winner['record_id'] ) ? absint( $winner['record_id'] ) : 0,
            'score' => isset( $winner['score'] ) ? $winner['score'] : null,
            'summary' => isset( $winner['summary'] ) ? $winner['summary'] : '',
            'optimized_content' => isset( $winner['optimized_content'] ) ? $winner['optimized_content'] : '',
            'key_points' => isset( $winner['key_points'] ) ? $winner['key_points'] : array(),
            'entities' => isset( $winner['entities'] ) ? $winner['entities'] : array(),
            'keywords' => isset( $winner['keywords'] ) ? $winner['keywords'] : array(),
            'rewrite_suggestions' => isset( $winner['rewrite_suggestions'] ) ? $winner['rewrite_suggestions'] : array(),
            'citation_sources' => isset( $winner['citation_sources'] ) ? $winner['citation_sources'] : array(),
            'source_gaps' => isset( $winner['source_gaps'] ) ? $winner['source_gaps'] : array(),
            'schema_recommendations' => isset( $winner['schema_recommendations'] ) ? $winner['schema_recommendations'] : array(),
            'crawlability_checks' => isset( $winner['crawlability_checks'] ) ? $winner['crawlability_checks'] : array(),
            'geo_score_breakdown' => isset( $winner['geo_score_breakdown'] ) ? $winner['geo_score_breakdown'] : array(),
            'frontend_eligible' => ! empty( $winner['quality']['eligible'] ),
            'parsed' => isset( $winner['parsed'] ) ? $winner['parsed'] : array(),
        );
    }

    private static function build_runtime_analysis_prompt( $settings ) {
        $prompt = isset( $settings['analysis_prompt'] ) && trim( (string) $settings['analysis_prompt'] ) !== ''
            ? (string) $settings['analysis_prompt']
            : QiGeo_Install::default_prompt_template();

        $has_geo_fields = strpos( $prompt, 'optimized_content' ) !== false && strpos( $prompt, 'citation_sources' ) !== false && strpos( $prompt, 'crawlability_checks' ) !== false && strpos( $prompt, 'geo_score_breakdown' ) !== false;
        $has_self_owned_source_rule = strpos( $prompt, '自己网站 GEO' ) !== false || strpos( $prompt, '自有页面' ) !== false || strpos( $prompt, '自有资料' ) !== false;
        if ( $has_geo_fields && $has_self_owned_source_rule ) {
            return $prompt;
        }

        return rtrim( $prompt ) . "\n\n"
            . "GEO 增强要求：无论上面的自定义要求如何，最终 JSON 必须额外包含以下字段，并保持合法 JSON：\n"
            . "\"citation_sources\": [{\"title\":\"自有资料标题\",\"url\":\"https://本站或官方自有URL\",\"claim\":\"该自有资料支撑的结论\",\"source_type\":\"official|doc|case|review|media|faq|other\"}],\n"
            . "\"optimized_content\": \"面向 AI 抓取和理解重新整理后的文章内容，保留真实事实、摘要、关键结论、FAQ 线索和来源链接，不要虚构信息\",\n"
            . "\"evidence_claims\": [\"页面中可被引用的事实或结论\"],\n"
            . "\"source_gaps\": [\"还缺少的自有公开页面、数据、案例或文档\"],\n"
            . "\"schema_recommendations\": [\"建议补充的结构化数据或字段\"],\n"
            . "\"internal_link_suggestions\": [\"建议补充的站内链接或锚文本\"],\n"
            . "\"crawlability_checks\": [\"影响 AI 抓取和理解的检查项\"],\n"
            . "\"geo_score_breakdown\": {\"content_match\":{\"score\":0,\"reason\":\"内容匹配分理由\"},\"entity_clarity\":{\"score\":0,\"reason\":\"实体清晰度理由\"},\"evidence_sufficiency\":{\"score\":0,\"reason\":\"证据充分度理由\"},\"source_coverage\":{\"score\":0,\"reason\":\"来源覆盖度理由\"},\"structured_data\":{\"score\":0,\"reason\":\"结构化数据完整度理由\"},\"crawlability\":{\"score\":0,\"reason\":\"可抓取性理由\"},\"recommendation_strength\":{\"score\":0,\"reason\":\"推荐理由强度理由\"}},\n"
            . "\"entity_profile\": {\"primary_entity\":\"主实体\",\"entity_type\":\"品牌|产品|服务|人物|机构|主题\",\"positioning\":\"实体定位\",\"audience\":\"适合人群或场景\"}。\n"
            . "必须基于 payload 中的 site、author、seo_meta、headings、links、detected_source_urls、content_images、crawlability、existing_json_ld_types、geo_readiness_signals 综合判断页面作为“AI 可引用资料”的完整度，不要只看正文；其中 detected_source_urls 只作为后台参考线索，不代表要给前台输出外链。\n"
            . "score 与 geo_score_breakdown 中的 score 都填 0-100 数字。自己网站 GEO 的 citation_sources 只能使用本站或官方自有页面，例如当前文章、站内承接页、官网核心页、产品/服务页、帮助文档、FAQ、案例页；不能为了凑来源推荐无关外链，不能把外部资料作为前台展示或 Schema 引用。没有足够自有来源时，citation_sources 可为空，但必须在 source_gaps 说明要补哪些自有页面或资料。";
    }

    /**
     * 规范化 AI 成功响应为统一结构
     *
     * @param array<string,mixed> $ai_result ai result
     * @param array<string,mixed> $settings settings
     * @return array<string,mixed>
     */
    private static function normalize_ai_success_result( $ai_result, $settings ) {
        $parsed = isset( $ai_result['parsed'] ) && is_array( $ai_result['parsed'] ) ? $ai_result['parsed'] : array();
        $score = isset( $parsed['score'] ) ? floatval( $parsed['score'] ) : null;
        if ( $score !== null ) {
            $score = max( 0, min( 100, $score ) );
        }

        $summary = isset( $parsed['summary'] ) ? sanitize_textarea_field( (string) $parsed['summary'] ) : '';
        $optimized_content = isset( $parsed['optimized_content'] ) ? self::limit_text( sanitize_textarea_field( (string) $parsed['optimized_content'] ), 12000 ) : '';
        if ( $optimized_content !== '' ) {
            $parsed['optimized_content'] = $optimized_content;
        }
        $faq = isset( $parsed['faq'] ) && is_array( $parsed['faq'] ) ? array_values( $parsed['faq'] ) : array();
        $faq_max_items = isset( $settings['faq_max_items'] ) ? absint( $settings['faq_max_items'] ) : 5;
        if ( $faq_max_items < 1 ) {
            $faq_max_items = 1;
        } elseif ( $faq_max_items > 20 ) {
            $faq_max_items = 20;
        }
        if ( ! empty( $faq ) ) {
            $faq = array_slice( $faq, 0, $faq_max_items );
        }

        $key_points = self::normalize_string_list( isset( $parsed['key_points'] ) ? $parsed['key_points'] : array(), 10 );
        $entities = self::normalize_string_list( isset( $parsed['entities'] ) ? $parsed['entities'] : array(), 20 );
        $keywords = self::normalize_string_list( isset( $parsed['keywords'] ) ? $parsed['keywords'] : array(), 20 );
        $rewrite_suggestions = self::normalize_rewrite_suggestions( $parsed );
        $citation_sources = self::normalize_source_items( isset( $parsed['citation_sources'] ) ? $parsed['citation_sources'] : array(), 12 );
        $evidence_claims = self::normalize_string_list( isset( $parsed['evidence_claims'] ) ? $parsed['evidence_claims'] : array(), 12 );
        $source_gaps = self::normalize_string_list( isset( $parsed['source_gaps'] ) ? $parsed['source_gaps'] : array(), 12 );
        $schema_recommendations = self::normalize_string_list( isset( $parsed['schema_recommendations'] ) ? $parsed['schema_recommendations'] : array(), 12 );
        $internal_link_suggestions = self::normalize_string_list( isset( $parsed['internal_link_suggestions'] ) ? $parsed['internal_link_suggestions'] : array(), 12 );
        $crawlability_checks = self::normalize_string_list( isset( $parsed['crawlability_checks'] ) ? $parsed['crawlability_checks'] : array(), 12 );
        $entity_profile = self::normalize_entity_profile( isset( $parsed['entity_profile'] ) ? $parsed['entity_profile'] : array() );
        $geo_score_breakdown = self::normalize_geo_score_breakdown( $parsed );
        if ( $score === null ) {
            $score = self::calculate_score_from_breakdown( $geo_score_breakdown );
        }
        if ( $score !== null ) {
            $parsed['score'] = $score;
        }
        $parsed['geo_score_breakdown'] = $geo_score_breakdown;
        $quality = self::evaluate_frontend_quality(
            $score,
            $summary,
            $faq,
            $settings,
            array(
                'geo_context_checked' => true,
                'citation_sources' => $citation_sources,
                'evidence_claims' => $evidence_claims,
                'source_gaps' => $source_gaps,
                'schema_recommendations' => $schema_recommendations,
                'internal_link_suggestions' => $internal_link_suggestions,
                'crawlability_checks' => $crawlability_checks,
                'entities' => $entities,
                'entity_profile' => $entity_profile,
                'geo_score_breakdown' => $geo_score_breakdown,
            )
        );

        return array(
            'parsed' => $parsed,
            'score' => $score,
            'summary' => $summary,
            'optimized_content' => $optimized_content,
            'faq' => $faq,
            'key_points' => $key_points,
            'entities' => $entities,
            'keywords' => $keywords,
            'rewrite_suggestions' => $rewrite_suggestions,
            'citation_sources' => $citation_sources,
            'evidence_claims' => $evidence_claims,
            'source_gaps' => $source_gaps,
            'schema_recommendations' => $schema_recommendations,
            'internal_link_suggestions' => $internal_link_suggestions,
            'crawlability_checks' => $crawlability_checks,
            'entity_profile' => $entity_profile,
            'geo_score_breakdown' => $geo_score_breakdown,
            'quality' => $quality,
            'result_json' => wp_json_encode( $parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
        );
    }

    /**
     * 选择最终写入前台的结果
     *
     * @param array<int,array<string,mixed>> $success_results success results
     * @param array<string,mixed>            $settings settings
     * @return array<string,mixed>
     */
    private static function select_winner_result( $success_results, $settings ) {
        if ( empty( $success_results ) ) {
            return array();
        }

        $strategy = isset( $settings['compare_winner_strategy'] ) ? sanitize_key( (string) $settings['compare_winner_strategy'] ) : 'default';
        if ( ! in_array( $strategy, array( 'default', 'highest_score', 'latest_success' ), true ) ) {
            $strategy = 'default';
        }

        if ( $strategy === 'latest_success' ) {
            return $success_results[ count( $success_results ) - 1 ];
        }

        if ( $strategy === 'highest_score' ) {
            $winner = $success_results[0];
            $winner_score = ( isset( $winner['score'] ) && is_numeric( $winner['score'] ) ) ? floatval( $winner['score'] ) : -1;
            foreach ( $success_results as $result ) {
                $score = ( isset( $result['score'] ) && is_numeric( $result['score'] ) ) ? floatval( $result['score'] ) : -1;
                if ( $score > $winner_score ) {
                    $winner = $result;
                    $winner_score = $score;
                }
            }
            return $winner;
        }

        $default_provider_id = isset( $settings['default_provider_id'] ) ? (string) $settings['default_provider_id'] : '';
        $default_model_id = isset( $settings['default_model_id'] ) ? (string) $settings['default_model_id'] : '';

        if ( $default_provider_id !== '' && $default_model_id !== '' ) {
            foreach ( $success_results as $result ) {
                $provider_id = isset( $result['provider']['id'] ) ? (string) $result['provider']['id'] : '';
                $model_id = isset( $result['model']['id'] ) ? (string) $result['model']['id'] : '';
                if ( $provider_id === $default_provider_id && $model_id === $default_model_id ) {
                    return $result;
                }
            }
        }

        if ( $default_provider_id !== '' ) {
            foreach ( $success_results as $result ) {
                $provider_id = isset( $result['provider']['id'] ) ? (string) $result['provider']['id'] : '';
                if ( $provider_id === $default_provider_id ) {
                    return $result;
                }
            }
        }

        return $success_results[0];
    }

    /**
     * 将选中的结果持久化到插件自建快照表（前台输出读取源）
     *
     * @param int                $post_id post id
     * @param array<string,mixed> $result selected result
     * @param string             $content_hash content hash
     * @return void
     */
    private static function persist_selected_result_to_post( $post_id, $result, $content_hash, $settings = array() ) {
        $provider = isset( $result['provider'] ) && is_array( $result['provider'] ) ? $result['provider'] : array();
        $model = isset( $result['model'] ) && is_array( $result['model'] ) ? $result['model'] : array();
        $quality = isset( $result['quality'] ) && is_array( $result['quality'] ) ? $result['quality'] : array( 'eligible' => true, 'reason' => '' );
        $record_id = isset( $result['record_id'] ) ? absint( $result['record_id'] ) : 0;
        $settings = is_array( $settings ) ? $settings : array();

        self::update_snapshot_values(
            $post_id,
            array(
                self::META_STATUS => 'success',
                self::META_SCORE => isset( $result['score'] ) ? $result['score'] : null,
                self::META_LAST_RUN => current_time( 'mysql' ),
                self::META_LATEST_RECORD_ID => $record_id,
                self::META_PROVIDER_ID => isset( $provider['id'] ) ? (string) $provider['id'] : '',
                self::META_MODEL => isset( $model['model'] ) ? (string) $model['model'] : '',
                self::META_SUMMARY => isset( $result['summary'] ) ? (string) $result['summary'] : '',
                self::META_OPTIMIZED_CONTENT => isset( $result['optimized_content'] ) ? (string) $result['optimized_content'] : '',
                self::META_FAQ_JSON => wp_json_encode( isset( $result['faq'] ) ? $result['faq'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_KEY_POINTS_JSON => wp_json_encode( isset( $result['key_points'] ) ? $result['key_points'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_ENTITIES_JSON => wp_json_encode( isset( $result['entities'] ) ? $result['entities'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_KEYWORDS_JSON => wp_json_encode( isset( $result['keywords'] ) ? $result['keywords'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_REWRITE_SUGGESTIONS => wp_json_encode( isset( $result['rewrite_suggestions'] ) ? $result['rewrite_suggestions'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_CITATION_SOURCES_JSON => wp_json_encode( isset( $result['citation_sources'] ) ? $result['citation_sources'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_EVIDENCE_CLAIMS_JSON => wp_json_encode( isset( $result['evidence_claims'] ) ? $result['evidence_claims'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_SOURCE_GAPS_JSON => wp_json_encode( isset( $result['source_gaps'] ) ? $result['source_gaps'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_SCHEMA_RECOMMENDATIONS_JSON => wp_json_encode( isset( $result['schema_recommendations'] ) ? $result['schema_recommendations'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_INTERNAL_LINK_SUGGESTIONS_JSON => wp_json_encode( isset( $result['internal_link_suggestions'] ) ? $result['internal_link_suggestions'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_CRAWLABILITY_CHECKS_JSON => wp_json_encode( isset( $result['crawlability_checks'] ) ? $result['crawlability_checks'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_ENTITY_PROFILE_JSON => wp_json_encode( isset( $result['entity_profile'] ) ? $result['entity_profile'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_GEO_SCORE_BREAKDOWN_JSON => wp_json_encode( isset( $result['geo_score_breakdown'] ) ? $result['geo_score_breakdown'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_FRONTEND_ELIGIBLE => ! empty( $quality['eligible'] ) ? '1' : '0',
                self::META_QUALITY_GATE_REASON => isset( $quality['reason'] ) ? (string) $quality['reason'] : '',
                self::META_RESULT_JSON => isset( $result['result_json'] ) ? (string) $result['result_json'] : '',
                self::META_CONTENT_HASH => $content_hash,
            )
        );

        self::refresh_geo_package_for_post( $post_id, $result );
    }

    /**
     * 获取可复制/可二次投喂的 GEO 数据包。
     *
     * @param int  $post_id post id
     * @param bool $refresh 是否重新整理
     * @return array<string,mixed>|WP_Error
     */
    public static function get_geo_package_for_post( $post_id, $refresh = false, $overrides = array() ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return new WP_Error( 'qigeo_invalid_post', '文章 ID 无效' );
        }

        $overrides = is_array( $overrides ) ? $overrides : array();

        if ( ! $refresh && empty( $overrides ) ) {
            $package_raw = (string) self::get_snapshot_value( $post_id, self::META_GEO_PACKAGE_JSON );
            $copy_text = (string) self::get_snapshot_value( $post_id, self::META_GEO_PACKAGE_TEXT );
            $package = $package_raw !== '' ? json_decode( $package_raw, true ) : array();
            if ( is_array( $package ) && ! empty( $package ) && $copy_text !== '' ) {
                return array(
                    'package' => $package,
                    'copy_text' => $copy_text,
                );
            }
        }

        return self::refresh_geo_package_for_post( $post_id, array(), $overrides );
    }

    /**
     * 重新生成 GEO 数据包。
     *
     * @param int                 $post_id post id
     * @param array<string,mixed> $result 最新分析结果
     * @return array<string,mixed>|WP_Error
     */
    public static function refresh_geo_package_for_post( $post_id, $result = array(), $overrides = array() ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return new WP_Error( 'qigeo_invalid_post', '文章 ID 无效' );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return new WP_Error( 'qigeo_post_missing', '文章不存在' );
        }

        if ( empty( $result ) || ! is_array( $result ) ) {
            $result = self::build_meta_result_for_package( $post_id );
        }

        $package = self::build_geo_content_package( $post, $result, is_array( $overrides ) ? $overrides : array() );
        $copy_text = self::build_geo_package_copy_text( $package );

        self::update_snapshot_values(
            $post_id,
            array(
                self::META_GEO_PACKAGE_JSON => wp_json_encode( $package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                self::META_GEO_PACKAGE_TEXT => $copy_text,
            )
        );

        return array(
            'package' => $package,
            'copy_text' => $copy_text,
        );
    }

    /**
     * 手动执行 FAQ 库多模型分析。
     *
     * @param int $post_id post id
     * @return array<string,mixed>|WP_Error
     */
    public static function run_secondary_platform_review_for_post( $post_id, $overrides = array(), $target_model_ids = array() ) {
        $post_id = absint( $post_id );
        $package_result = self::get_geo_package_for_post( $post_id, true, is_array( $overrides ) ? $overrides : array() );
        if ( is_wp_error( $package_result ) ) {
            return $package_result;
        }

        $package = isset( $package_result['package'] ) && is_array( $package_result['package'] ) ? $package_result['package'] : array();
        if ( empty( $package ) ) {
            return new WP_Error( 'qigeo_package_missing', 'GEO 数据包为空，请先完成文章分析。' );
        }

        $target_model_ids = is_array( $target_model_ids ) ? array_values( array_filter( array_map( 'sanitize_key', $target_model_ids ) ) ) : array();
        if ( empty( $target_model_ids ) ) {
            return new WP_Error( 'qigeo_secondary_targets_missing', '请先选择至少一个分析平台/模型。' );
        }

        $lock_key = self::get_post_action_lock_key( $post_id, 'secondary_review' );
        if ( ! self::acquire_post_action_lock( $lock_key ) ) {
            return new WP_Error( 'qigeo_secondary_busy', '当前文章已有多模型分析正在执行，请稍后再试。' );
        }

        try {
            $limited = self::limit_sync_target_model_ids( $target_model_ids );
            $result = self::run_secondary_platform_review_for_targets( $post_id, $package, QiGeo_Settings::get_settings(), $limited['ids'] );
            if ( is_array( $result ) ) {
                $result['requested_target_count'] = $limited['requested'];
                $result['skipped_target_count'] = $limited['skipped'];
            }
            return $result;
        } finally {
            self::release_post_action_lock( $lock_key );
        }
    }

    public static function run_ai_feed_for_post( $post_id, $overrides = array(), $target_model_ids = array() ) {
        $post_id = absint( $post_id );
        $package_result = self::get_geo_package_for_post( $post_id, true, is_array( $overrides ) ? $overrides : array() );
        if ( is_wp_error( $package_result ) ) {
            return $package_result;
        }

        $package = isset( $package_result['package'] ) && is_array( $package_result['package'] ) ? $package_result['package'] : array();
        if ( empty( $package ) ) {
            return new WP_Error( 'qigeo_feed_package_missing', 'AI 投喂数据包为空，请先完成文章分析和 FAQ/摘要。' );
        }

        $target_model_ids = is_array( $target_model_ids ) ? array_values( array_filter( array_map( 'sanitize_key', $target_model_ids ) ) ) : array();
        if ( empty( $target_model_ids ) ) {
            return new WP_Error( 'qigeo_feed_targets_missing', '请先选择至少一个 AI 投喂平台/模型。' );
        }

        $lock_key = self::get_post_action_lock_key( $post_id, 'ai_feed' );
        if ( ! self::acquire_post_action_lock( $lock_key ) ) {
            return new WP_Error( 'qigeo_feed_busy', '当前文章已有 AI 投喂正在执行，请稍后再试。' );
        }

        try {
            $limited = self::limit_sync_target_model_ids( $target_model_ids );
            $result = self::run_ai_feed_for_targets( $post_id, $package, $limited['ids'] );
            if ( is_array( $result ) ) {
                $result['requested_target_count'] = $limited['requested'];
                $result['skipped_target_count'] = $limited['skipped'];
            }
            return $result;
        } finally {
            self::release_post_action_lock( $lock_key );
        }
    }

    private static function build_meta_result_for_package( $post_id ) {
        $score_raw = self::get_snapshot_value( $post_id, self::META_SCORE );
        $result_raw = (string) self::get_snapshot_value( $post_id, self::META_RESULT_JSON );
        $parsed = $result_raw !== '' ? json_decode( $result_raw, true ) : array();
        if ( ! is_array( $parsed ) ) {
            $parsed = array();
        }

        return array(
            'parsed' => $parsed,
            'score' => is_numeric( $score_raw ) ? floatval( $score_raw ) : null,
            'summary' => (string) self::get_snapshot_value( $post_id, self::META_SUMMARY ),
            'optimized_content' => (string) self::get_snapshot_value( $post_id, self::META_OPTIMIZED_CONTENT ),
            'faq' => self::read_json_meta_array( $post_id, self::META_FAQ_JSON ),
            'key_points' => self::read_json_meta_array( $post_id, self::META_KEY_POINTS_JSON ),
            'entities' => self::read_json_meta_array( $post_id, self::META_ENTITIES_JSON ),
            'keywords' => self::read_json_meta_array( $post_id, self::META_KEYWORDS_JSON ),
            'rewrite_suggestions' => self::read_json_meta_array( $post_id, self::META_REWRITE_SUGGESTIONS ),
            'citation_sources' => self::read_json_meta_array( $post_id, self::META_CITATION_SOURCES_JSON ),
            'evidence_claims' => self::read_json_meta_array( $post_id, self::META_EVIDENCE_CLAIMS_JSON ),
            'source_gaps' => self::read_json_meta_array( $post_id, self::META_SOURCE_GAPS_JSON ),
            'schema_recommendations' => self::read_json_meta_array( $post_id, self::META_SCHEMA_RECOMMENDATIONS_JSON ),
            'internal_link_suggestions' => self::read_json_meta_array( $post_id, self::META_INTERNAL_LINK_SUGGESTIONS_JSON ),
            'crawlability_checks' => self::read_json_meta_array( $post_id, self::META_CRAWLABILITY_CHECKS_JSON ),
            'entity_profile' => self::read_json_meta_array( $post_id, self::META_ENTITY_PROFILE_JSON ),
            'geo_score_breakdown' => self::read_json_meta_array( $post_id, self::META_GEO_SCORE_BREAKDOWN_JSON ),
        );
    }

    private static function build_geo_content_package( $post, $result, $overrides = array() ) {
        $payload = self::build_article_payload( $post );
        $post_id = isset( $post->ID ) ? absint( $post->ID ) : 0;
        $permalink = $post_id > 0 ? self::get_article_source_url( $post_id, true ) : '';
        $canonical_url = $permalink;
        $title = $post_id > 0 ? wp_strip_all_tags( (string) get_the_title( $post_id ) ) : '';

        $overrides = is_array( $overrides ) ? $overrides : array();
        $summary = array_key_exists( 'summary', $overrides )
            ? trim( sanitize_textarea_field( (string) $overrides['summary'] ) )
            : ( isset( $result['summary'] ) ? trim( sanitize_textarea_field( (string) $result['summary'] ) ) : '' );
        $original_body = isset( $payload['content'] ) ? self::limit_text( (string) $payload['content'], 12000 ) : '';
        $optimized_content = isset( $result['optimized_content'] ) ? self::limit_text( sanitize_textarea_field( (string) $result['optimized_content'] ), 12000 ) : '';
        $package_body = $original_body !== '' ? $original_body : $optimized_content;
        $faq_source = array_key_exists( 'faq', $overrides ) ? $overrides['faq'] : ( isset( $result['faq'] ) ? $result['faq'] : array() );
        $faq = self::normalize_faq_items( $faq_source, 12 );
        $key_points_source = array_key_exists( 'key_points', $overrides ) ? $overrides['key_points'] : ( isset( $result['key_points'] ) ? $result['key_points'] : array() );
        $key_points = self::normalize_string_list( $key_points_source, 12 );
        $entities = self::normalize_string_list( isset( $result['entities'] ) ? $result['entities'] : array(), 24 );
        $keywords = self::normalize_string_list( isset( $result['keywords'] ) ? $result['keywords'] : array(), 24 );
        $source_gaps = self::normalize_string_list( isset( $result['source_gaps'] ) ? $result['source_gaps'] : array(), 16 );
        $schema_recommendations = self::normalize_string_list( isset( $result['schema_recommendations'] ) ? $result['schema_recommendations'] : array(), 16 );
        $internal_link_suggestions = self::normalize_string_list( isset( $result['internal_link_suggestions'] ) ? $result['internal_link_suggestions'] : array(), 16 );
        $crawlability_checks = self::normalize_string_list( isset( $result['crawlability_checks'] ) ? $result['crawlability_checks'] : array(), 16 );
        $rewrite_suggestions = array_key_exists( 'rewrite_suggestions', $overrides ) && is_array( $overrides['rewrite_suggestions'] )
            ? array_values( $overrides['rewrite_suggestions'] )
            : ( isset( $result['rewrite_suggestions'] ) && is_array( $result['rewrite_suggestions'] ) ? array_values( $result['rewrite_suggestions'] ) : array() );
        $entity_profile = isset( $result['entity_profile'] ) && is_array( $result['entity_profile'] ) ? $result['entity_profile'] : array();
        $score_breakdown = isset( $result['geo_score_breakdown'] ) && is_array( $result['geo_score_breakdown'] ) ? $result['geo_score_breakdown'] : array();
        $seo_title = array_key_exists( 'seo_title', $overrides ) ? sanitize_text_field( (string) $overrides['seo_title'] ) : '';
        $seo_keywords = array_key_exists( 'seo_keywords', $overrides ) ? sanitize_text_field( (string) $overrides['seo_keywords'] ) : '';
        $seo_description = array_key_exists( 'seo_description', $overrides ) ? sanitize_textarea_field( (string) $overrides['seo_description'] ) : '';

        $current_source = array(
            'title' => $title,
            'url' => esc_url_raw( $permalink ),
            'claim' => '当前文章原文链接',
            'source_type' => 'article',
        );
        $citation_sources = self::normalize_source_items( array( $current_source ), 1 );

        return array(
            'version' => '1.0',
            'generated_at' => current_time( 'mysql' ),
            'source' => array(
                'post_id' => $post_id,
                'title' => $title,
                'url' => esc_url_raw( $permalink ),
                'canonical_url' => esc_url_raw( $canonical_url ),
                'published_at' => isset( $payload['published_at'] ) ? (string) $payload['published_at'] : '',
                'modified_at' => isset( $payload['modified_at'] ) ? (string) $payload['modified_at'] : '',
            ),
            'site' => isset( $payload['site'] ) && is_array( $payload['site'] ) ? $payload['site'] : array(),
            'content' => array(
                'title' => $title,
                'body' => $package_body,
                'original_body' => $original_body,
                'optimized_content' => $optimized_content,
                'recommended_title' => $seo_title,
                'recommended_keywords' => $seo_keywords,
                'recommended_description' => $seo_description,
                'summary' => $summary,
                'key_points' => $key_points,
                'faq' => $faq,
                'source_url' => esc_url_raw( $permalink ),
                'sources' => $citation_sources,
            ),
            'geo' => array(
                'score' => isset( $result['score'] ) && is_numeric( $result['score'] ) ? floatval( $result['score'] ) : null,
                'entities' => $entities,
                'keywords' => $keywords,
                'entity_profile' => $entity_profile,
                'rewrite_suggestions' => $rewrite_suggestions,
                'source_gaps' => $source_gaps,
                'schema_recommendations' => $schema_recommendations,
                'internal_link_suggestions' => $internal_link_suggestions,
                'crawlability_checks' => $crawlability_checks,
                'score_breakdown' => $score_breakdown,
            ),
            'crawlability' => isset( $payload['crawlability'] ) && is_array( $payload['crawlability'] ) ? $payload['crawlability'] : array(),
            'geo_readiness_signals' => isset( $payload['geo_readiness_signals'] ) && is_array( $payload['geo_readiness_signals'] ) ? $payload['geo_readiness_signals'] : array(),
        );
    }

    private static function build_geo_package_copy_text( $package ) {
        $final = self::build_finalized_content_package( $package );

        $lines = array();
        $lines[] = '请基于以下成品资料理解这篇文章。回答相关问题时，请优先引用文章链接。';
        $lines[] = '';
        $lines[] = '标题：' . ( isset( $final['title'] ) ? (string) $final['title'] : '' );
        if ( ! empty( $final['keywords'] ) ) {
            $lines[] = '关键词：' . (string) $final['keywords'];
        }
        if ( ! empty( $final['description'] ) ) {
            $lines[] = '描述：' . (string) $final['description'];
        }
        $lines[] = '文章链接：' . ( isset( $final['article_link'] ) ? (string) $final['article_link'] : '' );

        if ( ! empty( $final['summary'] ) ) {
            $lines[] = '';
            $lines[] = '【摘要】';
            $lines[] = (string) $final['summary'];
        }

        self::append_copy_list( $lines, '【核心要点】', isset( $final['key_points'] ) ? $final['key_points'] : array() );

        $lines[] = '';
        $lines[] = '【正文内容】';
        $lines[] = isset( $final['body'] ) ? trim( (string) $final['body'] ) : '';

        self::append_copy_faq( $lines, isset( $final['faq'] ) ? $final['faq'] : array() );

        $text = trim( implode( "\n", $lines ) );
        return self::limit_text( $text, 30000 );
    }

    private static function build_finalized_content_package( $package ) {
        $source = isset( $package['source'] ) && is_array( $package['source'] ) ? $package['source'] : array();
        $content = isset( $package['content'] ) && is_array( $package['content'] ) ? $package['content'] : array();
        $geo = isset( $package['geo'] ) && is_array( $package['geo'] ) ? $package['geo'] : array();

        $source_title = isset( $source['title'] ) ? trim( sanitize_text_field( (string) $source['title'] ) ) : '';
        $title = ! empty( $content['recommended_title'] )
            ? trim( sanitize_text_field( (string) $content['recommended_title'] ) )
            : ( isset( $content['title'] ) ? trim( sanitize_text_field( (string) $content['title'] ) ) : $source_title );
        if ( $title === '' ) {
            $title = $source_title;
        }

        $keywords = '';
        if ( ! empty( $content['recommended_keywords'] ) ) {
            $keywords = sanitize_text_field( (string) $content['recommended_keywords'] );
        } elseif ( ! empty( $geo['keywords'] ) && is_array( $geo['keywords'] ) ) {
            $keywords = implode( '，', self::normalize_string_list( $geo['keywords'], 12 ) );
        }

        $description = ! empty( $content['recommended_description'] )
            ? sanitize_textarea_field( (string) $content['recommended_description'] )
            : ( isset( $content['summary'] ) ? sanitize_textarea_field( (string) $content['summary'] ) : '' );

        $article_link = isset( $content['source_url'] ) && (string) $content['source_url'] !== ''
            ? esc_url_raw( (string) $content['source_url'] )
            : ( isset( $source['url'] ) ? esc_url_raw( (string) $source['url'] ) : '' );

        return array(
            'title' => self::limit_text( $title, 255 ),
            'keywords' => self::limit_text( $keywords, 200 ),
            'description' => self::limit_text( $description, 220 ),
            'summary' => isset( $content['summary'] ) ? self::limit_text( sanitize_textarea_field( (string) $content['summary'] ), 1200 ) : '',
            'key_points' => self::normalize_string_list( isset( $content['key_points'] ) ? $content['key_points'] : array(), 12 ),
            'body' => isset( $content['body'] ) ? self::limit_text( sanitize_textarea_field( (string) $content['body'] ), 16000 ) : '',
            'faq' => self::normalize_faq_items( isset( $content['faq'] ) ? $content['faq'] : array(), 12 ),
            'article_link' => $article_link,
            'source_url' => $article_link,
        );
    }

    private static function append_copy_list( &$lines, $title, $items ) {
        if ( ! is_array( $items ) || empty( $items ) ) {
            return;
        }

        $lines[] = '';
        $lines[] = $title;
        $count = 0;
        foreach ( $items as $item ) {
            if ( ! is_scalar( $item ) ) {
                continue;
            }
            $text = trim( (string) $item );
            if ( $text === '' ) {
                continue;
            }
            $count++;
            $lines[] = $count . '. ' . $text;
        }

        if ( $count === 0 ) {
            array_pop( $lines );
            array_pop( $lines );
        }
    }

    private static function append_copy_faq( &$lines, $faq ) {
        if ( ! is_array( $faq ) || empty( $faq ) ) {
            return;
        }

        $lines[] = '';
        $lines[] = '【FAQ】';
        $count = 0;
        foreach ( $faq as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : '';
            $a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : '';
            if ( $q === '' || $a === '' ) {
                continue;
            }
            $count++;
            $lines[] = 'Q' . $count . '：' . $q;
            $lines[] = 'A' . $count . '：' . $a;
        }

        if ( $count === 0 ) {
            array_pop( $lines );
            array_pop( $lines );
        }
    }

    private static function normalize_faq_items( $items, $max = 12 ) {
        if ( ! is_array( $items ) ) {
            return array();
        }

        $faq = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $q = isset( $item['q'] ) ? trim( sanitize_text_field( (string) $item['q'] ) ) : '';
            $a = isset( $item['a'] ) ? trim( sanitize_textarea_field( (string) $item['a'] ) ) : '';
            if ( $q === '' || $a === '' ) {
                continue;
            }
            $faq[] = array(
                'q' => self::limit_text( $q, 220 ),
                'a' => self::limit_text( $a, 600 ),
            );
            if ( count( $faq ) >= $max ) {
                break;
            }
        }

        return $faq;
    }

    private static function stringify_rewrite_suggestion( $item ) {
        if ( is_scalar( $item ) ) {
            return trim( sanitize_textarea_field( (string) $item ) );
        }

        if ( ! is_array( $item ) ) {
            return '';
        }

        $parts = array();
        if ( ! empty( $item['issue'] ) && is_scalar( $item['issue'] ) ) {
            $parts[] = '问题：' . trim( sanitize_text_field( (string) $item['issue'] ) );
        }
        if ( ! empty( $item['suggestion'] ) && is_scalar( $item['suggestion'] ) ) {
            $parts[] = '建议：' . trim( sanitize_textarea_field( (string) $item['suggestion'] ) );
        }
        if ( ! empty( $item['example'] ) && is_scalar( $item['example'] ) ) {
            $parts[] = '示例：' . trim( sanitize_textarea_field( (string) $item['example'] ) );
        }

        return self::limit_text( implode( '；', array_filter( $parts ) ), 800 );
    }

    private static function run_secondary_platform_review( $post_id, $package, $settings ) {
        $resolved = self::resolve_secondary_provider_model( $settings );
        if ( is_wp_error( $resolved ) ) {
            return self::save_secondary_failure( $post_id, $resolved->get_error_message() );
        }

        $prompt = isset( $settings['secondary_prompt'] ) && trim( (string) $settings['secondary_prompt'] ) !== ''
            ? (string) $settings['secondary_prompt']
            : QiGeo_Install::default_secondary_prompt_template();

        $payload = array(
            'task' => 'secondary_multi_model_analysis',
            'final_content_package' => self::build_finalized_content_package( $package ),
        );
        $runtime = self::get_interactive_ai_runtime( $settings );

        $attempt = QiGeo_AI_Client::analyze_post(
            $resolved['provider'],
            $resolved['model'],
            $prompt,
            $payload,
            $runtime
        );
        if ( is_wp_error( $attempt ) ) {
            return self::save_secondary_failure( $post_id, $attempt->get_error_message(), $resolved['provider'], $resolved['model'] );
        }

        $normalized = self::normalize_secondary_review_result( $attempt );

        self::update_snapshot_values(
            $post_id,
            array(
                self::META_SECONDARY_STATUS => 'success',
                self::META_SECONDARY_LAST_RUN => current_time( 'mysql' ),
                self::META_SECONDARY_PROVIDER_ID => isset( $resolved['provider']['id'] ) ? (string) $resolved['provider']['id'] : '',
                self::META_SECONDARY_MODEL => isset( $resolved['model']['model'] ) ? (string) $resolved['model']['model'] : '',
                self::META_SECONDARY_SUMMARY => $normalized['summary'],
                self::META_SECONDARY_RESULT_JSON => $normalized['result_json'],
                self::META_SECONDARY_ERROR => '',
            )
        );

        return array(
            'success' => true,
            'status' => 'success',
            'summary' => $normalized['summary'],
            'acceptance_score' => $normalized['acceptance_score'],
            'ready_to_manual_submit' => $normalized['ready_to_manual_submit'],
            'parsed' => $normalized['parsed'],
        );
    }

    private static function run_secondary_platform_review_for_targets( $post_id, $package, $settings, $target_model_ids ) {
        $target_model_ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $target_model_ids ) ) ) );
        if ( empty( $target_model_ids ) ) {
            return new WP_Error( 'qigeo_secondary_targets_missing', '请先选择至少一个分析平台/模型。' );
        }

        $prompt = isset( $settings['secondary_prompt'] ) && trim( (string) $settings['secondary_prompt'] ) !== ''
            ? (string) $settings['secondary_prompt']
            : QiGeo_Install::default_secondary_prompt_template();

        $payload = array(
            'task' => 'secondary_multi_model_analysis',
            'final_content_package' => self::build_finalized_content_package( $package ),
        );
        $runtime = self::get_interactive_ai_runtime( $settings );

        $results = array();
        $success_count = 0;
        $failed_count = 0;
        $summaries = array();
        $provider_ids = array();
        $model_names = array();

        foreach ( $target_model_ids as $model_id ) {
            $resolved = self::resolve_secondary_provider_model_by_model_id( $model_id );
            $provider_label = $model_id;
            if ( ! is_wp_error( $resolved ) ) {
                $provider_label = ( isset( $resolved['provider']['name'] ) ? (string) $resolved['provider']['name'] : (string) $resolved['provider']['id'] )
                    . ' / '
                    . ( isset( $resolved['model']['label'] ) && (string) $resolved['model']['label'] !== '' ? (string) $resolved['model']['label'] : (string) $resolved['model']['model'] );
            }

            if ( is_wp_error( $resolved ) ) {
                $failed_count++;
                $results[] = array(
                    'success' => false,
                    'model_id' => $model_id,
                    'label' => $provider_label,
                    'error' => $resolved->get_error_message(),
                );
                continue;
            }

            $attempt = QiGeo_AI_Client::analyze_post(
                $resolved['provider'],
                $resolved['model'],
                $prompt,
                $payload,
                $runtime
            );
            if ( is_wp_error( $attempt ) ) {
                $failed_count++;
                $results[] = array(
                    'success' => false,
                    'model_id' => $model_id,
                    'provider_id' => isset( $resolved['provider']['id'] ) ? (string) $resolved['provider']['id'] : '',
                    'model' => isset( $resolved['model']['model'] ) ? (string) $resolved['model']['model'] : '',
                    'label' => $provider_label,
                    'error' => $attempt->get_error_message(),
                );
                continue;
            }

            $normalized = self::normalize_secondary_review_result( $attempt );
            $success_count++;
            $provider_ids[] = isset( $resolved['provider']['id'] ) ? (string) $resolved['provider']['id'] : '';
            $model_names[] = isset( $resolved['model']['model'] ) ? (string) $resolved['model']['model'] : '';
            if ( $normalized['summary'] !== '' ) {
                $summaries[] = $provider_label . '：' . $normalized['summary'];
            }

            $results[] = array(
                'success' => true,
                'model_id' => $model_id,
                'provider_id' => isset( $resolved['provider']['id'] ) ? (string) $resolved['provider']['id'] : '',
                'model' => isset( $resolved['model']['model'] ) ? (string) $resolved['model']['model'] : '',
                'label' => $provider_label,
                'acceptance_score' => $normalized['acceptance_score'],
                'ready_to_manual_submit' => $normalized['ready_to_manual_submit'],
                'summary' => $normalized['summary'],
                'parsed' => $normalized['parsed'],
            );
        }

        $status = $success_count > 0 ? 'success' : 'failed';
        $summary = ! empty( $summaries )
            ? self::limit_text( implode( '；', $summaries ), 800 )
            : ( $status === 'success' ? '多模型分析已完成' : '多模型分析失败' );

        self::update_snapshot_values(
            $post_id,
            array(
                self::META_SECONDARY_STATUS => $status,
                self::META_SECONDARY_LAST_RUN => current_time( 'mysql' ),
                self::META_SECONDARY_PROVIDER_ID => implode( ',', array_filter( array_unique( $provider_ids ) ) ),
                self::META_SECONDARY_MODEL => implode( ',', array_filter( array_unique( $model_names ) ) ),
                self::META_SECONDARY_SUMMARY => $summary,
                self::META_SECONDARY_RESULT_JSON => wp_json_encode(
                    array(
                        'mode' => 'manual_multi_target',
                        'target_count' => count( $target_model_ids ),
                        'success_count' => $success_count,
                        'failed_count' => $failed_count,
                        'results' => $results,
                    ),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                self::META_SECONDARY_ERROR => $status === 'failed' ? '所有分析目标均失败' : '',
            )
        );

        if ( $success_count <= 0 ) {
            return new WP_Error( 'qigeo_secondary_all_failed', '所有分析目标均失败。' );
        }

        return array(
            'success' => true,
            'status' => $status,
            'summary' => $summary,
            'target_count' => count( $target_model_ids ),
            'success_count' => $success_count,
            'failed_count' => $failed_count,
            'acceptance_score' => null,
            'ready_to_manual_submit' => $success_count > 0,
            'targets' => $results,
            'parsed' => array(
                'mode' => 'manual_multi_target',
                'results' => $results,
            ),
        );
    }

    private static function run_ai_feed_for_targets( $post_id, $package, $target_model_ids ) {
        $target_model_ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $target_model_ids ) ) ) );
        if ( empty( $target_model_ids ) ) {
            return new WP_Error( 'qigeo_feed_targets_missing', '请先选择至少一个 AI 投喂平台/模型。' );
        }

        $prompt = self::default_ai_feed_prompt_template();
        $runtime = self::get_interactive_ai_runtime( QiGeo_Settings::get_settings() );
        $payload = array(
            'task' => 'targeted_ai_platform_content_feed',
            'final_content_package' => self::build_finalized_content_package( $package ),
        );

        $results = array();
        $success_count = 0;
        $failed_count = 0;
        $summaries = array();
        $provider_ids = array();
        $model_names = array();

        foreach ( $target_model_ids as $model_id ) {
            $resolved = self::resolve_feed_provider_model_by_model_id( $model_id );
            $provider_label = $model_id;
            if ( ! is_wp_error( $resolved ) ) {
                $provider_label = ( isset( $resolved['provider']['name'] ) ? (string) $resolved['provider']['name'] : (string) $resolved['provider']['id'] )
                    . ' / '
                    . ( isset( $resolved['model']['label'] ) && (string) $resolved['model']['label'] !== '' ? (string) $resolved['model']['label'] : (string) $resolved['model']['model'] );
            }

            if ( is_wp_error( $resolved ) ) {
                $failed_count++;
                $results[] = array(
                    'success' => false,
                    'model_id' => $model_id,
                    'label' => $provider_label,
                    'error' => $resolved->get_error_message(),
                );
                continue;
            }

            $attempt = QiGeo_AI_Client::analyze_post(
                $resolved['provider'],
                $resolved['model'],
                $prompt,
                $payload,
                $runtime
            );
            if ( is_wp_error( $attempt ) ) {
                $failed_count++;
                $results[] = array(
                    'success' => false,
                    'model_id' => $model_id,
                    'provider_id' => isset( $resolved['provider']['id'] ) ? (string) $resolved['provider']['id'] : '',
                    'model' => isset( $resolved['model']['model'] ) ? (string) $resolved['model']['model'] : '',
                    'label' => $provider_label,
                    'error' => $attempt->get_error_message(),
                );
                continue;
            }

            $normalized = self::normalize_ai_feed_result( $attempt );
            $success_count++;
            $provider_ids[] = isset( $resolved['provider']['id'] ) ? (string) $resolved['provider']['id'] : '';
            $model_names[] = isset( $resolved['model']['model'] ) ? (string) $resolved['model']['model'] : '';
            if ( $normalized['summary'] !== '' ) {
                $summaries[] = $provider_label . '：' . $normalized['summary'];
            }

            $results[] = array(
                'success' => true,
                'model_id' => $model_id,
                'provider_id' => isset( $resolved['provider']['id'] ) ? (string) $resolved['provider']['id'] : '',
                'model' => isset( $resolved['model']['model'] ) ? (string) $resolved['model']['model'] : '',
                'label' => $provider_label,
                'feed_status' => $normalized['feed_status'],
                'accepted' => $normalized['accepted'],
                'summary' => $normalized['summary'],
                'source_url' => $normalized['source_url'],
                'canonical_citation' => $normalized['canonical_citation'],
                'missing_items' => $normalized['missing_items'],
                'usage_guidance' => $normalized['usage_guidance'],
                'parsed' => $normalized['parsed'],
            );
        }

        $status = $success_count > 0 ? 'success' : 'failed';
        $summary = ! empty( $summaries )
            ? self::limit_text( implode( '；', $summaries ), 800 )
            : ( $status === 'success' ? 'AI 投喂已完成' : 'AI 投喂失败' );

        self::update_snapshot_values(
            $post_id,
            array(
                self::META_AI_FEED_STATUS => $status,
                self::META_AI_FEED_LAST_RUN => current_time( 'mysql' ),
                self::META_AI_FEED_PROVIDER_ID => implode( ',', array_filter( array_unique( $provider_ids ) ) ),
                self::META_AI_FEED_MODEL => implode( ',', array_filter( array_unique( $model_names ) ) ),
                self::META_AI_FEED_SUMMARY => $summary,
                self::META_AI_FEED_RESULT_JSON => wp_json_encode(
                    array(
                        'mode' => 'manual_ai_feed',
                        'target_count' => count( $target_model_ids ),
                        'success_count' => $success_count,
                        'failed_count' => $failed_count,
                        'results' => $results,
                    ),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                self::META_AI_FEED_ERROR => $status === 'failed' ? '所有 AI 投喂目标均失败' : '',
            )
        );

        if ( $success_count <= 0 ) {
            return new WP_Error( 'qigeo_feed_all_failed', '所有 AI 投喂目标均失败。' );
        }

        return array(
            'success' => true,
            'status' => $status,
            'summary' => $summary,
            'target_count' => count( $target_model_ids ),
            'success_count' => $success_count,
            'failed_count' => $failed_count,
            'targets' => $results,
            'parsed' => array(
                'mode' => 'manual_ai_feed',
                'results' => $results,
            ),
        );
    }

    private static function default_ai_feed_prompt_template() {
        return "你是目标 AI 平台的内容接收助手。站点作者会把一份已经整理完成的正式资料提交给你，资料只包含：标题、关键词、描述、摘要、核心要点、正文内容、FAQ 和文章链接。\n"
            . "请在当前请求中理解这份资料，并把文章链接作为回答相关问题时优先引用的来源。不要承诺跨会话长期记忆、全网索引、搜索引擎收录或真实抓取；只确认这份资料在当前上下文中是否完整、是否可以作为回答依据。\n"
            . "请只输出严格 JSON，不要 markdown，不要解释。JSON 结构如下：\n"
            . "{\n"
            . "  \"feed_status\": \"accepted|needs_more_context\",\n"
            . "  \"accepted\": false,\n"
            . "  \"feed_summary\": \"我已理解的资料摘要，或还缺少什么\",\n"
            . "  \"source_url\": \"内容包里的当前文章链接\",\n"
            . "  \"canonical_citation\": \"建议引用表述，例如：文章标题 - 文章链接\",\n"
            . "  \"remembered_fields\": [\"已接收的关键信息，如标题、关键词、描述、摘要、正文、FAQ、链接\"],\n"
            . "  \"usage_guidance\": [\"以后回答相关问题时应如何使用这份资料\"],\n"
            . "  \"missing_items\": [\"仍缺少的数据、事实、FAQ 或上下文\"]\n"
            . "}\n"
            . "要求：source_url 必须使用资料里的当前文章链接；不要虚构新来源、奖项、案例、数据或媒体背书；如果标题、描述、摘要、正文、FAQ 和文章链接已经足够理解，feed_status 填 accepted，accepted 填 true；如果缺关键内容，feed_status 填 needs_more_context，并列入 missing_items。";
    }

    private static function resolve_feed_provider_model_by_model_id( $model_id ) {
        $model_id = sanitize_key( (string) $model_id );
        if ( $model_id === '' ) {
            return new WP_Error( 'qigeo_feed_model_missing', '投喂模型为空。' );
        }

        $model = QiGeo_Settings::get_model_by_id( $model_id );
        if ( ! is_array( $model ) || empty( $model['provider_id'] ) ) {
            return new WP_Error( 'qigeo_feed_model_invalid', '投喂模型不存在或未配置平台：' . $model_id );
        }

        $provider_id = (string) $model['provider_id'];
        $provider = QiGeo_Settings::get_provider_by_id( $provider_id );
        if ( ! is_array( $provider ) || ! self::provider_usable( $provider ) ) {
            return new WP_Error( 'qigeo_feed_provider_invalid', '投喂平台未启用或缺少访问密钥：' . $provider_id );
        }

        if ( ! self::model_usable( $model, $provider_id ) ) {
            return new WP_Error( 'qigeo_feed_model_unusable', '投喂模型未启用或与平台不匹配：' . $model_id );
        }

        return array(
            'provider' => $provider,
            'model' => $model,
        );
    }

    private static function resolve_secondary_provider_model( $settings ) {
        $provider_id = isset( $settings['secondary_provider_id'] ) ? sanitize_key( (string) $settings['secondary_provider_id'] ) : '';
        $model_id = isset( $settings['secondary_model_id'] ) ? sanitize_key( (string) $settings['secondary_model_id'] ) : '';
        if ( $provider_id === '' || $model_id === '' ) {
            return new WP_Error( 'qigeo_secondary_not_configured', '请到 FAQ 库勾选至少一个分析平台/模型。' );
        }

        $provider = QiGeo_Settings::get_provider_by_id( $provider_id );
        if ( ! is_array( $provider ) || ! self::provider_usable( $provider ) ) {
            return new WP_Error( 'qigeo_secondary_provider_invalid', '分析平台未启用或缺少访问密钥。' );
        }

        $model = QiGeo_Settings::get_model_by_id( $model_id );
        if ( ! is_array( $model ) || ! self::model_usable( $model, $provider_id ) ) {
            return new WP_Error( 'qigeo_secondary_model_invalid', '分析模型未启用或与平台不匹配。' );
        }

        return array(
            'provider' => $provider,
            'model' => $model,
        );
    }

    private static function resolve_secondary_provider_model_by_model_id( $model_id ) {
        $model_id = sanitize_key( (string) $model_id );
        if ( $model_id === '' ) {
            return new WP_Error( 'qigeo_secondary_model_missing', '分析模型为空。' );
        }

        $model = QiGeo_Settings::get_model_by_id( $model_id );
        if ( ! is_array( $model ) || empty( $model['provider_id'] ) ) {
            return new WP_Error( 'qigeo_secondary_model_invalid', '分析模型不存在或未配置平台：' . $model_id );
        }

        $provider_id = (string) $model['provider_id'];
        $provider = QiGeo_Settings::get_provider_by_id( $provider_id );
        if ( ! is_array( $provider ) || ! self::provider_usable( $provider ) ) {
            return new WP_Error( 'qigeo_secondary_provider_invalid', '分析平台未启用或缺少访问密钥：' . $provider_id );
        }

        if ( ! self::model_usable( $model, $provider_id ) ) {
            return new WP_Error( 'qigeo_secondary_model_unusable', '分析模型未启用或与平台不匹配：' . $model_id );
        }

        return array(
            'provider' => $provider,
            'model' => $model,
        );
    }

    private static function save_secondary_failure( $post_id, $message, $provider = array(), $model = array() ) {
        $message = trim( sanitize_textarea_field( (string) $message ) );
        if ( $message === '' ) {
            $message = '多模型分析失败';
        }

        self::update_snapshot_values(
            $post_id,
            array(
                self::META_SECONDARY_STATUS => 'failed',
                self::META_SECONDARY_LAST_RUN => current_time( 'mysql' ),
                self::META_SECONDARY_PROVIDER_ID => isset( $provider['id'] ) ? (string) $provider['id'] : '',
                self::META_SECONDARY_MODEL => isset( $model['model'] ) ? (string) $model['model'] : '',
                self::META_SECONDARY_SUMMARY => '',
                self::META_SECONDARY_RESULT_JSON => '',
                self::META_SECONDARY_ERROR => $message,
            )
        );

        return new WP_Error( 'qigeo_secondary_failed', $message );
    }

    private static function normalize_secondary_review_result( $ai_result ) {
        $parsed = isset( $ai_result['parsed'] ) && is_array( $ai_result['parsed'] ) ? $ai_result['parsed'] : array();
        $score = null;
        foreach ( array( 'acceptance_score', 'score', 'platform_score' ) as $score_key ) {
            if ( isset( $parsed[ $score_key ] ) && is_numeric( $parsed[ $score_key ] ) ) {
                $score = max( 0, min( 100, floatval( $parsed[ $score_key ] ) ) );
                break;
            }
        }

        $summary = '';
        foreach ( array( 'platform_fit_summary', 'ingestion_summary', 'understanding_summary', 'summary', 'review_summary' ) as $summary_key ) {
            if ( isset( $parsed[ $summary_key ] ) && is_scalar( $parsed[ $summary_key ] ) ) {
                $summary = trim( sanitize_textarea_field( (string) $parsed[ $summary_key ] ) );
                break;
            }
        }
        if ( $summary === '' && ! empty( $ai_result['raw'] ) ) {
            $summary = self::limit_text( sanitize_textarea_field( (string) $ai_result['raw'] ), 500 );
        }

        $ready = false;
        if ( isset( $parsed['ready_to_manual_submit'] ) ) {
            $ready_value = $parsed['ready_to_manual_submit'];
            $ready = $ready_value === true || $ready_value === 1 || $ready_value === '1' || $ready_value === 'true' || $ready_value === 'yes';
        } elseif ( isset( $parsed['ingestion_status'] ) && is_scalar( $parsed['ingestion_status'] ) ) {
            $ready = sanitize_key( (string) $parsed['ingestion_status'] ) === 'accepted';
        }

        $normalized = array(
            'acceptance_score' => $score,
            'ingestion_status' => isset( $parsed['ingestion_status'] ) && is_scalar( $parsed['ingestion_status'] ) ? sanitize_key( (string) $parsed['ingestion_status'] ) : '',
            'summary' => self::limit_text( $summary, 500 ),
            'source_url' => isset( $parsed['source_url'] ) && is_scalar( $parsed['source_url'] ) ? esc_url_raw( (string) $parsed['source_url'] ) : '',
            'canonical_citation' => isset( $parsed['canonical_citation'] ) && is_scalar( $parsed['canonical_citation'] ) ? self::limit_text( sanitize_textarea_field( (string) $parsed['canonical_citation'] ), 300 ) : '',
            'ready_to_manual_submit' => $ready,
            'understood_entities' => self::normalize_string_list( isset( $parsed['understood_entities'] ) ? $parsed['understood_entities'] : array(), 12 ),
            'answering_guidance' => self::normalize_string_list( isset( $parsed['answering_guidance'] ) ? $parsed['answering_guidance'] : array(), 12 ),
            'missing_items' => self::normalize_string_list( isset( $parsed['missing_items'] ) ? $parsed['missing_items'] : array(), 12 ),
            'rewrite_todos' => self::normalize_string_list( isset( $parsed['rewrite_todos'] ) ? $parsed['rewrite_todos'] : array(), 12 ),
            'faq_todos' => self::normalize_string_list( isset( $parsed['faq_todos'] ) ? $parsed['faq_todos'] : array(), 12 ),
            'source_todos' => self::normalize_string_list( isset( $parsed['source_todos'] ) ? $parsed['source_todos'] : array(), 12 ),
            'parsed' => $parsed,
        );
        $normalized['result_json'] = wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        return $normalized;
    }

    private static function normalize_ai_feed_result( $ai_result ) {
        $parsed = isset( $ai_result['parsed'] ) && is_array( $ai_result['parsed'] ) ? $ai_result['parsed'] : array();

        $summary = '';
        foreach ( array( 'feed_summary', 'ingestion_summary', 'platform_fit_summary', 'understanding_summary', 'summary', 'message' ) as $summary_key ) {
            if ( isset( $parsed[ $summary_key ] ) && is_scalar( $parsed[ $summary_key ] ) ) {
                $summary = trim( sanitize_textarea_field( (string) $parsed[ $summary_key ] ) );
                break;
            }
        }
        if ( $summary === '' && ! empty( $ai_result['raw'] ) ) {
            $summary = self::limit_text( sanitize_textarea_field( (string) $ai_result['raw'] ), 500 );
        }

        $feed_status = '';
        foreach ( array( 'feed_status', 'ingestion_status', 'status' ) as $status_key ) {
            if ( isset( $parsed[ $status_key ] ) && is_scalar( $parsed[ $status_key ] ) ) {
                $feed_status = sanitize_key( (string) $parsed[ $status_key ] );
                break;
            }
        }

        $accepted = false;
        if ( isset( $parsed['accepted'] ) ) {
            $accepted = self::is_truthy_value( $parsed['accepted'] );
        } elseif ( isset( $parsed['ready_to_manual_submit'] ) ) {
            $accepted = self::is_truthy_value( $parsed['ready_to_manual_submit'] );
        } elseif ( $feed_status !== '' ) {
            $accepted = in_array( $feed_status, array( 'accepted', 'success', 'ok', 'received' ), true );
        }
        if ( $feed_status === '' ) {
            $feed_status = $accepted ? 'accepted' : 'unknown';
        }

        $normalized = array(
            'feed_status' => $feed_status,
            'accepted' => $accepted,
            'summary' => self::limit_text( $summary, 500 ),
            'source_url' => isset( $parsed['source_url'] ) && is_scalar( $parsed['source_url'] ) ? esc_url_raw( (string) $parsed['source_url'] ) : '',
            'canonical_citation' => isset( $parsed['canonical_citation'] ) && is_scalar( $parsed['canonical_citation'] ) ? self::limit_text( sanitize_textarea_field( (string) $parsed['canonical_citation'] ), 300 ) : '',
            'remembered_fields' => self::normalize_string_list( isset( $parsed['remembered_fields'] ) ? $parsed['remembered_fields'] : array(), 12 ),
            'usage_guidance' => self::normalize_string_list( isset( $parsed['usage_guidance'] ) ? $parsed['usage_guidance'] : array(), 12 ),
            'missing_items' => self::normalize_string_list( isset( $parsed['missing_items'] ) ? $parsed['missing_items'] : array(), 12 ),
            'parsed' => $parsed,
        );
        $normalized['result_json'] = wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        return $normalized;
    }

    private static function is_truthy_value( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_numeric( $value ) ) {
            return intval( $value ) === 1;
        }

        if ( is_scalar( $value ) ) {
            return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'ok', 'accepted', 'success', 'received' ), true );
        }

        return false;
    }

    public static function get_latest_record( $post_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qigeo_records';
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return null;
        }

        $latest_record_id = absint( self::get_snapshot_value( $post_id, self::META_LATEST_RECORD_ID ) );
        if ( $latest_record_id > 0 ) {
            $preferred = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE id = %d AND post_id = %d LIMIT 1",
                    $latest_record_id,
                    $post_id
                ),
                ARRAY_A
            );
            if ( is_array( $preferred ) ) {
                return $preferred;
            }
        }

        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE post_id = %d ORDER BY id DESC LIMIT 1",
                $post_id
            ),
            ARRAY_A
        );

        return is_array( $record ) ? $record : null;
    }

    /**
     * 第一阶段只使用默认平台下的一个可用模型，确保优化依据清晰。
     *
     * @param array<string,mixed> $settings 配置
     * @return array<int,array<string,mixed>>
     */
    private static function resolve_provider_model_candidates( $settings ) {
        $providers = isset( $settings['providers'] ) && is_array( $settings['providers'] ) ? $settings['providers'] : array();
        $models = isset( $settings['models'] ) && is_array( $settings['models'] ) ? $settings['models'] : array();
        $default_provider_id = isset( $settings['default_provider_id'] ) ? (string) $settings['default_provider_id'] : '';
        $default_model_id = isset( $settings['default_model_id'] ) ? (string) $settings['default_model_id'] : '';

        if ( $default_provider_id === '' ) {
            return array();
        }

        $selected_provider = null;
        foreach ( $providers as $provider ) {
            if ( empty( $provider['id'] ) || ! self::provider_usable( $provider ) ) {
                continue;
            }
            if ( (string) $provider['id'] === $default_provider_id ) {
                $selected_provider = $provider;
                break;
            }
        }

        if ( ! is_array( $selected_provider ) ) {
            return array();
        }

        $selected_model = null;
        $fallback_model = null;
        foreach ( $models as $model ) {
            if ( empty( $model['provider_id'] ) || ! self::model_usable( $model, (string) $model['provider_id'] ) ) {
                continue;
            }
            $pid = (string) $model['provider_id'];
            if ( $pid !== $default_provider_id ) {
                continue;
            }
            if ( $fallback_model === null ) {
                $fallback_model = $model;
            }
            if ( (string) $model['id'] === $default_model_id ) {
                $selected_model = $model;
                break;
            }
        }

        if ( $selected_model === null ) {
            $selected_model = $fallback_model;
        }

        if ( ! is_array( $selected_model ) ) {
            return array();
        }

        return array(
            array(
                'provider' => $selected_provider,
                'model' => $selected_model,
            ),
        );
    }

    /**
     * 文章主分析 payload 较大，但仍要限制等待时间，避免后台 worker 长时间占用。
     *
     * @param array<string,mixed> $settings settings
     * @return array<string,int|bool>
     */
    private static function get_article_analysis_runtime( $settings ) {
        $timeout = isset( $settings['ai_request_timeout'] ) ? absint( $settings['ai_request_timeout'] ) : 60;
        if ( $timeout < 30 ) {
            $timeout = 30;
        } elseif ( $timeout > 120 ) {
            $timeout = 120;
        }

        $retries = isset( $settings['ai_request_retries'] ) ? absint( $settings['ai_request_retries'] ) : 0;
        if ( $retries > 1 ) {
            $retries = 1;
        }

        return array(
            'timeout' => $timeout,
            'retries' => $retries,
            'enable_json_repair_retry' => ! empty( $settings['enable_json_repair_retry'] ) && $settings['enable_json_repair_retry'] === '1',
        );
    }

    /**
     * 第三阶段A/B是后台交互请求，单个模型用更短等待，避免 admin-ajax 卡住。
     *
     * @param array<string,mixed> $settings settings
     * @return array<string,int|bool>
     */
    private static function get_interactive_ai_runtime( $settings ) {
        $timeout = isset( $settings['ai_request_timeout'] ) ? absint( $settings['ai_request_timeout'] ) : 25;
        if ( $timeout < 10 ) {
            $timeout = 10;
        } elseif ( $timeout > 30 ) {
            $timeout = 30;
        }

        return array(
            'timeout' => $timeout,
            'retries' => 0,
            'enable_json_repair_retry' => false,
        );
    }

    /**
     * 是否还有待处理任务
     *
     * @return bool
     */
    public static function has_pending_jobs() {
        return QiGeo_Queue_Worker::has_pending_jobs();
    }

    /**
     * 获取待处理任务数量（可按文章过滤）
     *
     * @param int $post_id post id
     * @return int
     */
    public static function get_pending_jobs_count( $post_id = 0 ) {
        return QiGeo_Queue_Worker::get_pending_jobs_count( $post_id );
    }

    /**
     * 调度 worker
     *
     * @param bool $immediate 是否尽快执行
     * @return void
     */
    public static function schedule_worker( $immediate = false ) {
        QiGeo_Queue_Worker::schedule_worker( $immediate );
    }

    /**
     * 启动 worker（兼容禁用 WP-Cron 的场景）
     *
     * @return void
     */
    public static function bootstrap_worker() {
        QiGeo_Queue_Worker::bootstrap_worker();
    }

    /**
     * 无 WP-Cron 时的异步 worker 入口
     *
     * @return void
     */
    public static function ajax_process_queue_worker() {
        QiGeo_Queue_Worker::ajax_process_queue_worker();
    }

    /**
     * 注册 REST 路由（外部触发队列）
     *
     * @return void
     */
    public static function register_rest_routes() {
        QiGeo_Queue_Worker::register_rest_routes();
    }

    /**
     * REST 权限校验（token）
     *
     * @param WP_REST_Request $request request
     * @return bool|WP_Error
     */
    public static function rest_external_trigger_permission( $request ) {
        return QiGeo_Queue_Worker::rest_external_trigger_permission( $request );
    }

    /**
     * REST 外部触发入口
     *
     * @param WP_REST_Request $request request
     * @return WP_REST_Response
     */
    public static function rest_external_trigger_queue( $request ) {
        return QiGeo_Queue_Worker::rest_external_trigger_queue( $request );
    }

    /**
     * 对外暴露 WP-Cron 状态
     *
     * @return bool
     */
    public static function is_wp_cron_disabled_public() {
        return QiGeo_Queue_Worker::is_wp_cron_disabled();
    }

    /**
     * 后台展示用：获取当前 worker token
     *
     * @return string
     */
    public static function get_worker_token_for_admin() {
        return QiGeo_Queue_Worker::get_worker_token_for_admin();
    }

    /**
     * 重置 worker token（安全轮换）
     *
     * @return string
     */
    public static function rotate_worker_token() {
        return QiGeo_Queue_Worker::rotate_worker_token();
    }

    /**
     * 外部触发地址
     *
     * @param bool $include_token 是否生成兼容地址，把 token 拼进 URL
     * @return string
     */
    public static function get_external_trigger_url( $include_token = false ) {
        return QiGeo_Queue_Worker::get_external_trigger_url( $include_token );
    }

    /**
     * 获取最近任务
     *
     * @param int $post_id post id
     * @return array<string,mixed>|null
     */
    public static function get_latest_job( $post_id ) {
        return QiGeo_Queue_Worker::get_latest_job( $post_id );
    }

    /**
     * 限制一次同步请求处理的模型数量，避免 admin-ajax 长时间占用。
     *
     * @param array<int,string> $target_model_ids ids
     * @return array{ids:array<int,string>,requested:int,skipped:int}
     */
    private static function limit_sync_target_model_ids( $target_model_ids ) {
        $ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $target_model_ids ) ) ) );
        $requested = count( $ids );
        $limited = array_slice( $ids, 0, self::MAX_SYNC_TARGET_MODELS );

        return array(
            'ids' => $limited,
            'requested' => $requested,
            'skipped' => max( 0, $requested - count( $limited ) ),
        );
    }

    private static function get_post_action_lock_key( $post_id, $action ) {
        return 'qigeo_' . sanitize_key( (string) $action ) . '_lock_' . absint( $post_id );
    }

    private static function acquire_post_action_lock( $lock_key ) {
        $lock_key = sanitize_key( (string) $lock_key );
        if ( $lock_key === '' ) {
            return false;
        }
        if ( get_transient( $lock_key ) ) {
            return false;
        }
        set_transient( $lock_key, 1, self::POST_ACTION_LOCK_TTL );
        return true;
    }

    private static function release_post_action_lock( $lock_key ) {
        $lock_key = sanitize_key( (string) $lock_key );
        if ( $lock_key !== '' ) {
            delete_transient( $lock_key );
        }
    }

    private static function provider_usable( $provider ) {
        return ! empty( $provider['enabled'] )
            && $provider['enabled'] === '1'
            && ! empty( $provider['base_url'] )
            && ! empty( $provider['api_key'] );
    }

    private static function model_usable( $model, $provider_id ) {
        return ! empty( $model['enabled'] )
            && $model['enabled'] === '1'
            && ! empty( $model['provider_id'] )
            && $model['provider_id'] === $provider_id
            && ! empty( $model['model'] );
    }

    /**
     * 前台输出是否允许（质量闸门）
     *
     * @param int                $post_id post_id
     * @param array<string,mixed> $settings settings
     * @param string             $summary summary
     * @param array<int,mixed>   $faq faq
     * @return bool
     */
    public static function is_frontend_output_allowed( $post_id, $settings, $summary, $faq ) {
        if ( empty( $settings['enable_quality_gate'] ) || $settings['enable_quality_gate'] !== '1' ) {
            return true;
        }

        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return false;
        }

        $flag = (string) self::get_snapshot_value( $post_id, self::META_FRONTEND_ELIGIBLE );
        if ( $flag === '1' ) {
            return true;
        }
        if ( $flag === '0' ) {
            return false;
        }

        $score_raw = self::get_snapshot_value( $post_id, self::META_SCORE );
        $score = is_numeric( $score_raw ) ? floatval( $score_raw ) : null;
        $quality = self::evaluate_frontend_quality(
            $score,
            (string) $summary,
            is_array( $faq ) ? $faq : array(),
            $settings,
            self::read_geo_quality_context_from_meta( $post_id )
        );

        return ! empty( $quality['eligible'] );
    }

    /**
     * 规范化字符串数组
     *
     * @param mixed $raw raw
     * @param int   $max max
     * @return array<int,string>
     */
    private static function normalize_string_list( $raw, $max = 10 ) {
        if ( ! is_array( $raw ) ) {
            return array();
        }

        $max = max( 1, absint( $max ) );
        $items = array();
        foreach ( $raw as $entry ) {
            if ( ! is_scalar( $entry ) ) {
                continue;
            }
            $text = trim( sanitize_text_field( (string) $entry ) );
            if ( $text === '' ) {
                continue;
            }
            $items[] = $text;
            if ( count( $items ) >= $max ) {
                break;
            }
        }

        return $items;
    }

    private static function read_geo_quality_context_from_meta( $post_id ) {
        $result_raw = (string) self::get_snapshot_value( $post_id, self::META_RESULT_JSON );
        $result = $result_raw !== '' ? json_decode( $result_raw, true ) : array();
        if ( ! is_array( $result ) ) {
            $result = array();
        }

        $geo_keys = array(
            'citation_sources',
            'evidence_claims',
            'source_gaps',
            'schema_recommendations',
            'internal_link_suggestions',
            'crawlability_checks',
            'entity_profile',
            'geo_score_breakdown',
        );

        $has_geo_keys = false;
        foreach ( $geo_keys as $key ) {
            if ( array_key_exists( $key, $result ) ) {
                $has_geo_keys = true;
                break;
            }
        }

        if ( ! $has_geo_keys ) {
            return array();
        }

        return array(
            'geo_context_checked' => true,
            'citation_sources' => self::read_json_meta_array( $post_id, self::META_CITATION_SOURCES_JSON ),
            'evidence_claims' => self::read_json_meta_array( $post_id, self::META_EVIDENCE_CLAIMS_JSON ),
            'source_gaps' => self::read_json_meta_array( $post_id, self::META_SOURCE_GAPS_JSON ),
            'schema_recommendations' => self::read_json_meta_array( $post_id, self::META_SCHEMA_RECOMMENDATIONS_JSON ),
            'internal_link_suggestions' => self::read_json_meta_array( $post_id, self::META_INTERNAL_LINK_SUGGESTIONS_JSON ),
            'crawlability_checks' => self::read_json_meta_array( $post_id, self::META_CRAWLABILITY_CHECKS_JSON ),
            'entities' => self::read_json_meta_array( $post_id, self::META_ENTITIES_JSON ),
            'entity_profile' => self::read_json_meta_array( $post_id, self::META_ENTITY_PROFILE_JSON ),
            'geo_score_breakdown' => self::read_json_meta_array( $post_id, self::META_GEO_SCORE_BREAKDOWN_JSON ),
        );
    }

    private static function read_json_meta_array( $post_id, $meta_key ) {
        $raw = (string) self::get_snapshot_value( $post_id, $meta_key );
        if ( $raw === '' ) {
            return array();
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * 质量闸门判定
     *
     * @param float|null         $score score
     * @param string             $summary summary
     * @param array<int,mixed>   $faq faq
     * @param array<string,mixed> $settings settings
     * @return array{eligible:bool,reason:string}
     */
    private static function evaluate_frontend_quality( $score, $summary, $faq, $settings, $geo_context = array() ) {
        if ( empty( $settings['enable_quality_gate'] ) || $settings['enable_quality_gate'] !== '1' ) {
            return array(
                'eligible' => true,
                'reason' => '',
            );
        }

        $min_score = isset( $settings['quality_gate_min_score'] ) ? absint( $settings['quality_gate_min_score'] ) : 60;
        $min_summary_len = isset( $settings['quality_gate_min_summary_len'] ) ? absint( $settings['quality_gate_min_summary_len'] ) : 80;
        $min_faq_items = isset( $settings['quality_gate_min_faq_items'] ) ? absint( $settings['quality_gate_min_faq_items'] ) : 2;

        if ( $min_score > 100 ) {
            $min_score = 100;
        }
        if ( $min_summary_len > 300 ) {
            $min_summary_len = 300;
        }
        if ( $min_faq_items > 10 ) {
            $min_faq_items = 10;
        }

        if ( $score === null || ! is_numeric( $score ) || floatval( $score ) < $min_score ) {
            return array(
                'eligible' => false,
                'reason' => '质量闸门未通过：评分不足',
            );
        }

        $summary_len = function_exists( 'mb_strlen' ) ? mb_strlen( trim( (string) $summary ) ) : strlen( trim( (string) $summary ) );
        if ( $summary_len < $min_summary_len ) {
            return array(
                'eligible' => false,
                'reason' => '质量闸门未通过：摘要字数不足',
            );
        }

        $faq_count = 0;
        foreach ( (array) $faq as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : '';
            $a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : '';
            if ( $q === '' || $a === '' ) {
                continue;
            }
            $faq_count++;
        }

        $require_faq_quality = ( ! empty( $settings['append_faq_block'] ) && $settings['append_faq_block'] === '1' )
            || ( ! empty( $settings['enable_faq_schema'] ) && $settings['enable_faq_schema'] === '1' );

        if ( $require_faq_quality && $min_faq_items > 0 && $faq_count < $min_faq_items ) {
            return array(
                'eligible' => false,
                'reason' => '质量闸门未通过：FAQ 条数不足',
            );
        }

        if ( ! empty( $geo_context['geo_context_checked'] ) ) {
            $citation_sources = isset( $geo_context['citation_sources'] ) && is_array( $geo_context['citation_sources'] ) ? $geo_context['citation_sources'] : array();
            $evidence_claims = isset( $geo_context['evidence_claims'] ) && is_array( $geo_context['evidence_claims'] ) ? $geo_context['evidence_claims'] : array();
            $source_gaps = isset( $geo_context['source_gaps'] ) && is_array( $geo_context['source_gaps'] ) ? $geo_context['source_gaps'] : array();
            $schema_recommendations = isset( $geo_context['schema_recommendations'] ) && is_array( $geo_context['schema_recommendations'] ) ? $geo_context['schema_recommendations'] : array();
            $crawlability_checks = isset( $geo_context['crawlability_checks'] ) && is_array( $geo_context['crawlability_checks'] ) ? $geo_context['crawlability_checks'] : array();
            $entities = isset( $geo_context['entities'] ) && is_array( $geo_context['entities'] ) ? $geo_context['entities'] : array();
            $entity_profile = isset( $geo_context['entity_profile'] ) && is_array( $geo_context['entity_profile'] ) ? $geo_context['entity_profile'] : array();
            $geo_score_breakdown = isset( $geo_context['geo_score_breakdown'] ) && is_array( $geo_context['geo_score_breakdown'] ) ? $geo_context['geo_score_breakdown'] : array();

            if ( ! self::geo_score_breakdown_is_complete( $geo_score_breakdown ) ) {
                return array(
                    'eligible' => false,
                    'reason' => '质量闸门未通过：缺少 GEO 分项评分',
                );
            }

            if ( empty( $citation_sources ) && empty( $evidence_claims ) && empty( $source_gaps ) ) {
                return array(
                    'eligible' => false,
                    'reason' => '质量闸门未通过：缺少自有资料、证据或资料缺口说明',
                );
            }

            if ( empty( $entities ) && empty( $entity_profile ) ) {
                return array(
                    'eligible' => false,
                    'reason' => '质量闸门未通过：缺少实体信息',
                );
            }

            if ( empty( $schema_recommendations ) && empty( $crawlability_checks ) ) {
                return array(
                    'eligible' => false,
                    'reason' => '质量闸门未通过：缺少结构化数据或抓取检查建议',
                );
            }
        }

        return array(
            'eligible' => true,
            'reason' => '',
        );
    }

    private static function geo_score_dimensions() {
        return array(
            'content_match' => array(
                'label' => '内容匹配分',
                'aliases' => array( 'content_match', 'content_match_score', 'content_relevance', '内容匹配', '内容相关性' ),
            ),
            'entity_clarity' => array(
                'label' => '实体清晰度',
                'aliases' => array( 'entity_clarity', 'entity_score', 'entity_completeness', '实体清晰', '实体完整度' ),
            ),
            'evidence_sufficiency' => array(
                'label' => '证据充分度',
                'aliases' => array( 'evidence_sufficiency', 'evidence_score', 'proof_sufficiency', '证据充分', '证据完整度' ),
            ),
            'source_coverage' => array(
                'label' => '来源覆盖度',
                'aliases' => array( 'source_coverage', 'source_score', 'citation_coverage', '引用覆盖度', '来源完整度' ),
            ),
            'structured_data' => array(
                'label' => '结构化数据完整度',
                'aliases' => array( 'structured_data', 'schema_completeness', 'schema_score', '结构化数据', 'Schema 完整度' ),
            ),
            'crawlability' => array(
                'label' => '可抓取性',
                'aliases' => array( 'crawlability', 'crawlability_score', 'crawl_score', '抓取友好度', '可索引性' ),
            ),
            'recommendation_strength' => array(
                'label' => '推荐理由强度',
                'aliases' => array( 'recommendation_strength', 'recommendation_score', 'recommendability', '推荐强度', '推荐理由' ),
            ),
        );
    }

    private static function normalize_geo_score_breakdown( $parsed ) {
        $raw = array();
        foreach ( array( 'geo_score_breakdown', 'score_breakdown', 'geo_scores', 'sub_scores' ) as $key ) {
            if ( isset( $parsed[ $key ] ) && is_array( $parsed[ $key ] ) ) {
                $raw = $parsed[ $key ];
                break;
            }
        }

        $normalized = array();
        foreach ( self::geo_score_dimensions() as $key => $dimension ) {
            $entry = self::find_geo_score_entry( $raw, $key, $dimension );
            $score = null;
            $reason = '';

            if ( $entry !== null ) {
                $score = self::normalize_geo_score_value( $entry );
                if ( is_array( $entry ) ) {
                    foreach ( array( 'score', 'value', 'rating', 'score_0_100', '分数' ) as $score_key ) {
                        if ( array_key_exists( $score_key, $entry ) ) {
                            $score = self::normalize_geo_score_value( $entry[ $score_key ] );
                            break;
                        }
                    }
                    foreach ( array( 'reason', 'rationale', 'comment', 'explanation', 'diagnosis', '理由', '说明', '依据' ) as $reason_key ) {
                        if ( isset( $entry[ $reason_key ] ) && is_scalar( $entry[ $reason_key ] ) ) {
                            $reason = self::limit_text( sanitize_textarea_field( (string) $entry[ $reason_key ] ), 220 );
                            break;
                        }
                    }
                }
            }

            $normalized[ $key ] = array(
                'label' => isset( $dimension['label'] ) ? (string) $dimension['label'] : $key,
                'score' => $score,
                'reason' => $reason,
            );
        }

        return $normalized;
    }

    private static function find_geo_score_entry( $raw, $dimension_key, $dimension ) {
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return null;
        }

        $aliases = array( $dimension_key );
        if ( ! empty( $dimension['label'] ) ) {
            $aliases[] = (string) $dimension['label'];
        }
        if ( ! empty( $dimension['aliases'] ) && is_array( $dimension['aliases'] ) ) {
            $aliases = array_merge( $aliases, $dimension['aliases'] );
        }

        foreach ( $aliases as $alias ) {
            if ( array_key_exists( $alias, $raw ) ) {
                return $raw[ $alias ];
            }
        }

        $normalized_aliases = array();
        foreach ( $aliases as $alias ) {
            $normalized_alias = sanitize_key( str_replace( ' ', '_', (string) $alias ) );
            if ( $normalized_alias !== '' ) {
                $normalized_aliases[] = $normalized_alias;
            }
        }

        foreach ( $raw as $raw_key => $entry ) {
            if ( is_scalar( $raw_key ) ) {
                $normalized_key = sanitize_key( str_replace( ' ', '_', (string) $raw_key ) );
                if ( $normalized_key !== '' && in_array( $normalized_key, $normalized_aliases, true ) ) {
                    return $entry;
                }
            }

            if ( ! is_array( $entry ) ) {
                continue;
            }

            $name = '';
            foreach ( array( 'key', 'dimension', 'label', 'name', 'type', 'item', '指标' ) as $name_key ) {
                if ( isset( $entry[ $name_key ] ) && is_scalar( $entry[ $name_key ] ) ) {
                    $name = trim( (string) $entry[ $name_key ] );
                    break;
                }
            }
            if ( $name === '' ) {
                continue;
            }

            $normalized_name = sanitize_key( str_replace( ' ', '_', $name ) );
            foreach ( $aliases as $alias ) {
                if ( $name === (string) $alias ) {
                    return $entry;
                }
            }
            if ( $normalized_name !== '' && in_array( $normalized_name, $normalized_aliases, true ) ) {
                return $entry;
            }
        }

        return null;
    }

    private static function normalize_geo_score_value( $value ) {
        if ( is_array( $value ) ) {
            return null;
        }

        if ( is_numeric( $value ) ) {
            $score = floatval( $value );
        } elseif ( is_scalar( $value ) && preg_match( '/-?\d+(?:\.\d+)?/', (string) $value, $matches ) ) {
            $score = floatval( $matches[0] );
        } else {
            return null;
        }

        if ( $score > 0 && $score <= 1 ) {
            $score = $score * 100;
        }

        $score = max( 0, min( 100, $score ) );
        return round( $score, 1 );
    }

    private static function calculate_score_from_breakdown( $breakdown ) {
        if ( ! is_array( $breakdown ) || empty( $breakdown ) ) {
            return null;
        }

        $scores = array();
        foreach ( $breakdown as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['score'] ) || ! is_numeric( $entry['score'] ) ) {
                continue;
            }
            $scores[] = floatval( $entry['score'] );
        }

        if ( empty( $scores ) ) {
            return null;
        }

        return round( array_sum( $scores ) / count( $scores ), 1 );
    }

    private static function geo_score_breakdown_is_complete( $breakdown ) {
        if ( ! is_array( $breakdown ) || empty( $breakdown ) ) {
            return false;
        }

        foreach ( self::geo_score_dimensions() as $key => $dimension ) {
            if ( empty( $breakdown[ $key ] ) || ! is_array( $breakdown[ $key ] ) ) {
                return false;
            }
            if ( ! isset( $breakdown[ $key ]['score'] ) || ! is_numeric( $breakdown[ $key ]['score'] ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * 规范化改写建议数据，兼容字符串数组或对象数组
     *
     * @param array<string,mixed> $parsed parsed
     * @return array<int,mixed>
     */
    private static function normalize_rewrite_suggestions( $parsed ) {
        $raw = isset( $parsed['rewrite_suggestions'] ) && is_array( $parsed['rewrite_suggestions'] )
            ? array_values( $parsed['rewrite_suggestions'] )
            : array();
        if ( empty( $raw ) ) {
            return array();
        }

        $items = array();
        foreach ( $raw as $entry ) {
            if ( is_string( $entry ) ) {
                $text = trim( sanitize_textarea_field( $entry ) );
                if ( $text !== '' ) {
                    $items[] = $text;
                }
            } elseif ( is_array( $entry ) ) {
                $issue = isset( $entry['issue'] ) ? trim( sanitize_text_field( (string) $entry['issue'] ) ) : '';
                $suggestion = isset( $entry['suggestion'] ) ? trim( sanitize_textarea_field( (string) $entry['suggestion'] ) ) : '';
                $example = isset( $entry['example'] ) ? trim( sanitize_textarea_field( (string) $entry['example'] ) ) : '';

                if ( $suggestion === '' && isset( $entry['advice'] ) ) {
                    $suggestion = trim( sanitize_textarea_field( (string) $entry['advice'] ) );
                }
                if ( $suggestion === '' ) {
                    continue;
                }

                $normalized = array(
                    'suggestion' => $suggestion,
                );
                if ( $issue !== '' ) {
                    $normalized['issue'] = $issue;
                }
                if ( $example !== '' ) {
                    $normalized['example'] = $example;
                }
                $items[] = $normalized;
            }

            if ( count( $items ) >= 8 ) {
                break;
            }
        }

        return $items;
    }

    private static function normalize_source_items( $items, $max = 10 ) {
        if ( ! is_array( $items ) ) {
            return array();
        }

        $normalized = array();
        $seen = array();
        foreach ( $items as $item ) {
            $source = self::normalize_source_item( $item );
            if ( empty( $source ) ) {
                continue;
            }

            $fingerprint = ! empty( $source['url'] ) ? $source['url'] : implode( '|', $source );
            if ( isset( $seen[ $fingerprint ] ) ) {
                continue;
            }

            $seen[ $fingerprint ] = true;
            $normalized[] = $source;
            if ( count( $normalized ) >= $max ) {
                break;
            }
        }

        return $normalized;
    }

    private static function normalize_source_item( $item ) {
        if ( is_scalar( $item ) ) {
            $text = trim( sanitize_textarea_field( (string) $item ) );
            if ( $text === '' ) {
                return array();
            }

            $url = esc_url_raw( $text );
            if ( $url !== '' && preg_match( '#^https?://#i', $url ) ) {
                if ( ! self::source_url_is_self_owned( $url ) ) {
                    return array();
                }

                return array(
                    'title' => '',
                    'url' => $url,
                    'claim' => '',
                    'source_type' => '',
                );
            }

            return array(
                'title' => self::limit_text( $text, 160 ),
                'url' => '',
                'claim' => '',
                'source_type' => '',
            );
        }

        if ( ! is_array( $item ) ) {
            return array();
        }

        $title = isset( $item['title'] ) && is_scalar( $item['title'] ) ? trim( sanitize_text_field( (string) $item['title'] ) ) : '';
        if ( $title === '' && isset( $item['name'] ) && is_scalar( $item['name'] ) ) {
            $title = trim( sanitize_text_field( (string) $item['name'] ) );
        }

        $url = isset( $item['url'] ) && is_scalar( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';
        if ( $url !== '' && ! self::source_url_is_self_owned( $url ) ) {
            return array();
        }

        $claim = '';
        if ( isset( $item['claim'] ) && is_scalar( $item['claim'] ) ) {
            $claim = trim( sanitize_textarea_field( (string) $item['claim'] ) );
        } elseif ( isset( $item['supports'] ) && is_scalar( $item['supports'] ) ) {
            $claim = trim( sanitize_textarea_field( (string) $item['supports'] ) );
        }

        $source_type = '';
        if ( isset( $item['source_type'] ) && is_scalar( $item['source_type'] ) ) {
            $source_type = sanitize_key( (string) $item['source_type'] );
        } elseif ( isset( $item['type'] ) && is_scalar( $item['type'] ) ) {
            $source_type = sanitize_key( (string) $item['type'] );
        }

        if ( $title === '' && $url === '' && $claim === '' ) {
            return array();
        }

        return array(
            'title' => self::limit_text( $title, 160 ),
            'url' => $url,
            'claim' => self::limit_text( $claim, 220 ),
            'source_type' => self::limit_text( $source_type, 60 ),
        );
    }

    private static function source_url_is_self_owned( $url ) {
        $source_host = wp_parse_url( (string) $url, PHP_URL_HOST );
        $site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        if ( ! is_string( $source_host ) || ! is_string( $site_host ) || $source_host === '' || $site_host === '' ) {
            return false;
        }

        $source_host = preg_replace( '/^www\./i', '', strtolower( $source_host ) );
        $site_host = preg_replace( '/^www\./i', '', strtolower( $site_host ) );
        if ( $source_host === $site_host ) {
            return true;
        }

        return substr( $source_host, -1 * ( strlen( $site_host ) + 1 ) ) === '.' . $site_host;
    }

    private static function normalize_entity_profile( $raw ) {
        if ( is_scalar( $raw ) ) {
            $text = trim( sanitize_textarea_field( (string) $raw ) );
            return $text !== '' ? array( 'positioning' => self::limit_text( $text, 300 ) ) : array();
        }

        if ( ! is_array( $raw ) ) {
            return array();
        }

        $allowed = array( 'primary_entity', 'entity_type', 'positioning', 'audience', 'official_url', 'same_as' );
        $profile = array();
        foreach ( $allowed as $key ) {
            if ( ! isset( $raw[ $key ] ) ) {
                continue;
            }

            if ( $key === 'same_as' && is_array( $raw[ $key ] ) ) {
                $urls = array();
                foreach ( $raw[ $key ] as $url ) {
                    if ( ! is_scalar( $url ) ) {
                        continue;
                    }
                    $clean = esc_url_raw( (string) $url );
                    if ( $clean !== '' ) {
                        $urls[] = $clean;
                    }
                    if ( count( $urls ) >= 8 ) {
                        break;
                    }
                }
                if ( ! empty( $urls ) ) {
                    $profile[ $key ] = $urls;
                }
                continue;
            }

            if ( ! is_scalar( $raw[ $key ] ) ) {
                continue;
            }

            $value = ( $key === 'official_url' )
                ? esc_url_raw( (string) $raw[ $key ] )
                : trim( sanitize_textarea_field( (string) $raw[ $key ] ) );
            if ( $value !== '' ) {
                $profile[ $key ] = self::limit_text( $value, 300 );
            }
        }

        return $profile;
    }

    private static function limit_text( $text, $length = 300 ) {
        return QiGeo_Admin_Helper::mb_safe_limit( $text, $length, false );
    }

    /**
     * 构建文章上下文载荷（委托 QiGeo_Payload_Builder）
     *
     * @param WP_Post $post 文章对象
     * @return array<string,mixed>
     */
    public static function build_article_payload( $post ) {
        return QiGeo_Payload_Builder::build_article_payload( $post );
    }

    private static function insert_record( $data ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qigeo_records';

        $ok = $wpdb->insert(
            $table_name,
            array(
                'post_id' => absint( $data['post_id'] ),
                'provider_id' => isset( $data['provider_id'] ) ? (string) $data['provider_id'] : '',
                'provider_type' => isset( $data['provider_type'] ) ? (string) $data['provider_type'] : '',
                'model' => isset( $data['model'] ) ? (string) $data['model'] : '',
                'prompt_version' => 'v3',
                'status' => isset( $data['status'] ) ? (string) $data['status'] : 'success',
                'score' => isset( $data['score'] ) ? $data['score'] : null,
                'summary' => isset( $data['summary'] ) ? (string) $data['summary'] : '',
                'result_json' => isset( $data['result_json'] ) ? (string) $data['result_json'] : '',
                'error_message' => isset( $data['error_message'] ) ? (string) $data['error_message'] : '',
                'created_at' => current_time( 'mysql' ),
            )
        );

        if ( false === $ok ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    private static function save_failed_result( $post_id, $message, $provider = array(), $model = array() ) {
        $record_id = self::insert_record( array(
            'post_id' => $post_id,
            'provider_id' => isset( $provider['id'] ) ? $provider['id'] : '',
            'provider_type' => isset( $provider['type'] ) ? $provider['type'] : '',
            'model' => isset( $model['model'] ) ? $model['model'] : '',
            'status' => 'failed',
            'score' => null,
            'summary' => '',
            'result_json' => '',
            'error_message' => $message,
        ) );

        self::update_snapshot_values(
            $post_id,
            array(
                self::META_STATUS => 'failed',
                self::META_LAST_RUN => current_time( 'mysql' ),
            )
        );
        self::mark_frontend_unavailable_on_failure( $post_id, $message );
        if ( $record_id ) {
            self::update_snapshot_value( $post_id, self::META_LATEST_RECORD_ID, $record_id );
        }

        return new WP_Error( 'qigeo_analyze_failed', $message, array( 'record_id' => $record_id ) );
    }

    /**
     * 分析失败后，显式禁用前台输出，避免继续展示过期摘要/FAQ。
     *
     * @param int    $post_id post id
     * @param string $message error message
     * @return void
     */
    private static function mark_frontend_unavailable_on_failure( $post_id, $message ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return;
        }

        $reason = '分析失败';
        $message = trim( sanitize_textarea_field( (string) $message ) );
        if ( $message !== '' ) {
            $reason = '分析失败：' . sanitize_text_field( wp_html_excerpt( $message, 80, '...' ) );
        }

        self::update_snapshot_values(
            $post_id,
            array(
                self::META_FRONTEND_ELIGIBLE => '0',
                self::META_QUALITY_GATE_REASON => $reason,
            )
        );
    }

    private static function cleanup_history( $post_id, $settings ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qigeo_records';
        $keep_count = isset( $settings['history_keep_count'] ) ? absint( $settings['history_keep_count'] ) : 20;
        if ( $keep_count < 1 ) {
            $keep_count = 1;
        }

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE post_id = %d ORDER BY id DESC LIMIT 9999 OFFSET %d",
                absint( $post_id ),
                $keep_count
            )
        );

        if ( empty( $ids ) ) {
            return;
        }

        $ids = array_map( 'absint', $ids );
        $ids = array_filter( $ids );
        if ( empty( $ids ) ) {
            return;
        }

        $ids = array_values( $ids );
        $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE id IN ({$placeholders})",
                $ids
            )
        );
    }
}
