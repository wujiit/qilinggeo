<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 文章 GEO 快照仓储层
 * 统一管理 wp_qigeo_post_snapshots 表的读写与缓存
 */
class QiGeo_Post_Snapshot {

    /**
     * @var array<int,array<string,mixed>|null>
     */
    private static $snapshot_cache = array();

    /**
     * 获取数据表名
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'qigeo_post_snapshots';
    }

    /**
     * 获取文章规范来源 URL
     *
     * @param int  $post_id 文章ID
     * @param bool $generate_if_missing 是否在缺失时自动生成
     * @return string
     */
    public static function get_article_source_url( $post_id, $generate_if_missing = true ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return '';
        }

        $saved_url = esc_url_raw( (string) self::get_value( $post_id, QiGeo_Analyzer::META_SOURCE_URL ) );
        if ( $saved_url !== '' ) {
            return $saved_url;
        }

        if ( ! $generate_if_missing ) {
            return '';
        }

        $permalink = get_permalink( $post_id );
        $source_url = is_string( $permalink ) ? esc_url_raw( $permalink ) : '';
        if ( $source_url !== '' ) {
            self::update_value( $post_id, QiGeo_Analyzer::META_SOURCE_URL, $source_url );
        }

        return $source_url;
    }

    /**
     * 更新文章规范来源 URL
     *
     * @param int    $post_id 文章ID
     * @param string $source_url 来源URL
     * @return string
     */
    public static function update_article_source_url( $post_id, $source_url ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return '';
        }

        $source_url = esc_url_raw( trim( (string) $source_url ) );
        if ( $source_url === '' ) {
            self::delete_value( $post_id, QiGeo_Analyzer::META_SOURCE_URL );
            return '';
        }

        self::update_value( $post_id, QiGeo_Analyzer::META_SOURCE_URL, $source_url );
        return $source_url;
    }

    /**
     * 读取快照中的单个属性值
     *
     * @param int    $post_id 文章ID
     * @param string $meta_key 键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public static function get_value( $post_id, $meta_key, $default = '' ) {
        $post_id = absint( $post_id );
        $column = self::get_column_for_key( $meta_key );
        if ( $post_id <= 0 || $column === '' ) {
            return $default;
        }

        $row = self::get_row( $post_id );
        if ( ! is_array( $row ) || ! array_key_exists( $column, $row ) || $row[ $column ] === null ) {
            return $default;
        }

        return $row[ $column ];
    }

    /**
     * 更新快照中的单个属性值
     *
     * @param int    $post_id 文章ID
     * @param string $meta_key 键名
     * @param mixed  $value 值
     * @return bool
     */
    public static function update_value( $post_id, $meta_key, $value ) {
        return self::update_values( $post_id, array( $meta_key => $value ) );
    }

    /**
     * 批量更新快照属性值
     *
     * @param int                  $post_id 文章ID
     * @param array<string,mixed>  $values 键值数组
     * @return bool
     */
    public static function update_values( $post_id, $values ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( $post_id <= 0 || ! is_array( $values ) || empty( $values ) ) {
            return false;
        }

        $data = array();
        foreach ( $values as $meta_key => $value ) {
            $column = self::get_column_for_key( $meta_key );
            if ( $column === '' ) {
                continue;
            }
            $data[ $column ] = self::normalize_value( $column, $value );
        }

        if ( empty( $data ) ) {
            return false;
        }

        $table = self::get_table_name();
        $now = current_time( 'mysql' );
        $existing = self::get_row( $post_id );

        if ( is_array( $existing ) ) {
            $data['updated_at'] = $now;
            $updated = $wpdb->update(
                $table,
                $data,
                array( 'post_id' => $post_id ),
                self::build_formats( $data ),
                array( '%d' )
            );
            if ( false === $updated ) {
                return false;
            }
            self::$snapshot_cache[ $post_id ] = array_merge( $existing, $data );
            return true;
        }

        $data = array_merge(
            array(
                'post_id'    => $post_id,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            $data
        );
        $inserted = $wpdb->insert( $table, $data, self::build_formats( $data ) );
        if ( false === $inserted ) {
            return false;
        }

        self::$snapshot_cache[ $post_id ] = self::get_row_from_db( $post_id );
        return true;
    }

    /**
     * 删除快照中的单个属性值（设为 null）
     *
     * @param int    $post_id 文章ID
     * @param string $meta_key 键名
     * @return bool
     */
    public static function delete_value( $post_id, $meta_key ) {
        $column = self::get_column_for_key( $meta_key );
        if ( $column === '' ) {
            return false;
        }
        return self::update_values( $post_id, array( $meta_key => null ) );
    }

    /**
     * 清理运行时快照缓存
     *
     * @param int $post_id 文章ID，0 为清空全部
     * @return void
     */
    public static function clear_cache( $post_id = 0 ) {
        $post_id = absint( $post_id );
        if ( $post_id > 0 ) {
            unset( self::$snapshot_cache[ $post_id ] );
        } else {
            self::$snapshot_cache = array();
        }
    }

    /**
     * 获取快照单行数据（含运行时缓存）
     *
     * @param int $post_id 文章ID
     * @return array<string,mixed>|null
     */
    public static function get_row( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return null;
        }

        if ( array_key_exists( $post_id, self::$snapshot_cache ) ) {
            return self::$snapshot_cache[ $post_id ];
        }

        $row = self::get_row_from_db( $post_id );
        if ( is_array( $row ) ) {
            self::$snapshot_cache[ $post_id ] = $row;
            return $row;
        }

        self::$snapshot_cache[ $post_id ] = null;
        return null;
    }

    /**
     * 直接从数据库读取快照行
     *
     * @param int $post_id 文章ID
     * @return array<string,mixed>|null
     */
    public static function get_row_from_db( $post_id ) {
        global $wpdb;
        $table = self::get_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1",
                absint( $post_id )
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    /**
     * 将 Meta 键名映射为数据表字段名
     *
     * @param string $meta_key 键名
     * @return string
     */
    public static function get_column_for_key( $meta_key ) {
        $map = self::get_column_map();
        $meta_key = (string) $meta_key;
        return isset( $map[ $meta_key ] ) ? (string) $map[ $meta_key ] : '';
    }

    /**
     * 获取快照字段映射字典
     *
     * @return array<string,string>
     */
    public static function get_column_map() {
        return array(
            QiGeo_Analyzer::META_SOURCE_URL                      => 'source_url',
            QiGeo_Analyzer::META_STATUS                          => 'status',
            QiGeo_Analyzer::META_SCORE                           => 'score',
            QiGeo_Analyzer::META_LAST_RUN                        => 'last_run_at',
            QiGeo_Analyzer::META_LATEST_RECORD_ID                => 'latest_record_id',
            QiGeo_Analyzer::META_PROVIDER_ID                     => 'provider_id',
            QiGeo_Analyzer::META_MODEL                           => 'model',
            QiGeo_Analyzer::META_SUMMARY                         => 'summary',
            QiGeo_Analyzer::META_OPTIMIZED_CONTENT               => 'optimized_content',
            QiGeo_Analyzer::META_FAQ_JSON                        => 'faq_json',
            QiGeo_Analyzer::META_KEY_POINTS_JSON                 => 'key_points_json',
            QiGeo_Analyzer::META_ENTITIES_JSON                   => 'entities_json',
            QiGeo_Analyzer::META_KEYWORDS_JSON                   => 'keywords_json',
            QiGeo_Analyzer::META_REWRITE_SUGGESTIONS             => 'rewrite_suggestions_json',
            QiGeo_Analyzer::META_CITATION_SOURCES_JSON           => 'citation_sources_json',
            QiGeo_Analyzer::META_EVIDENCE_CLAIMS_JSON            => 'evidence_claims_json',
            QiGeo_Analyzer::META_SOURCE_GAPS_JSON                => 'source_gaps_json',
            QiGeo_Analyzer::META_SCHEMA_RECOMMENDATIONS_JSON     => 'schema_recommendations_json',
            QiGeo_Analyzer::META_INTERNAL_LINK_SUGGESTIONS_JSON => 'internal_link_suggestions_json',
            QiGeo_Analyzer::META_CRAWLABILITY_CHECKS_JSON        => 'crawlability_checks_json',
            QiGeo_Analyzer::META_ENTITY_PROFILE_JSON             => 'entity_profile_json',
            QiGeo_Analyzer::META_GEO_SCORE_BREAKDOWN_JSON        => 'geo_score_breakdown_json',
            QiGeo_Analyzer::META_FRONTEND_ELIGIBLE               => 'frontend_eligible',
            QiGeo_Analyzer::META_QUALITY_GATE_REASON             => 'quality_gate_reason',
            QiGeo_Analyzer::META_RESULT_JSON                     => 'result_json',
            QiGeo_Analyzer::META_CONTENT_HASH                    => 'content_hash',
            QiGeo_Analyzer::META_GEO_PACKAGE_JSON                => 'geo_package_json',
            QiGeo_Analyzer::META_GEO_PACKAGE_TEXT                => 'geo_package_text',
            QiGeo_Analyzer::META_SECONDARY_STATUS                => 'secondary_status',
            QiGeo_Analyzer::META_SECONDARY_LAST_RUN              => 'secondary_last_run_at',
            QiGeo_Analyzer::META_SECONDARY_PROVIDER_ID          => 'secondary_provider_id',
            QiGeo_Analyzer::META_SECONDARY_MODEL                 => 'secondary_model',
            QiGeo_Analyzer::META_SECONDARY_SUMMARY               => 'secondary_summary',
            QiGeo_Analyzer::META_SECONDARY_RESULT_JSON           => 'secondary_result_json',
            QiGeo_Analyzer::META_SECONDARY_ERROR                 => 'secondary_error',
            QiGeo_Analyzer::META_AI_FEED_STATUS                  => 'ai_feed_status',
            QiGeo_Analyzer::META_AI_FEED_LAST_RUN                => 'ai_feed_last_run_at',
            QiGeo_Analyzer::META_AI_FEED_PROVIDER_ID             => 'ai_feed_provider_id',
            QiGeo_Analyzer::META_AI_FEED_MODEL                  => 'ai_feed_model',
            QiGeo_Analyzer::META_AI_FEED_SUMMARY                 => 'ai_feed_summary',
            QiGeo_Analyzer::META_AI_FEED_RESULT_JSON             => 'ai_feed_result_json',
            QiGeo_Analyzer::META_AI_FEED_ERROR                   => 'ai_feed_error',
            QiGeo_Analyzer::META_USE_AI_SEO                      => 'use_ai_seo',
            QiGeo_Analyzer::META_LEGACY_SEO_BACKUP_JSON          => 'seo_backup_json',
            QiGeo_Analyzer::META_SEO_BACKUP_JSON                 => 'seo_backup_json',
        );
    }

    /**
     * 规范化写入数据库的属性值
     *
     * @param string $column 字段名
     * @param mixed  $value 待规范化值
     * @return mixed
     */
    public static function normalize_value( $column, $value ) {
        if ( $value === null ) {
            return null;
        }

        if ( in_array( $column, array( 'latest_record_id', 'frontend_eligible', 'use_ai_seo' ), true ) ) {
            return absint( $value );
        }

        if ( $column === 'score' ) {
            return is_numeric( $value ) ? floatval( $value ) : null;
        }

        return is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    /**
     * 构建预编译参数格式占位符
     *
     * @param array<string,mixed> $data 数据数组
     * @return array<int,string>
     */
    public static function build_formats( $data ) {
        $formats = array();
        foreach ( $data as $column => $value ) {
            if ( in_array( $column, array( 'post_id', 'latest_record_id', 'frontend_eligible', 'use_ai_seo' ), true ) ) {
                $formats[] = '%d';
            } elseif ( $column === 'score' ) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }
        return $formats;
    }
}
