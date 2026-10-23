<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI 爬虫专属 Markdown 知识索引协议 (/llms.txt 与 /llms-full.txt)
 *
 * 遵循 llmstxt.org 全球标准规范，为 ByteSpider、GPTBot、ClaudeBot、Perplexity 等
 * AI 搜索爬虫提供零噪音、高信息密度的站点实体和核心知识图谱。
 */
class QiGeo_LLMs_Txt {

    const CACHE_KEY_BRIEF = 'qigeo_llms_txt_brief';
    const CACHE_KEY_FULL  = 'qigeo_llms_txt_full';
    const CACHE_TTL       = 43200; // 12 小时

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle_request' ), 1 );
        add_action( 'wp_head', array( __CLASS__, 'output_head_link' ) );
        add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ) );
        add_action( 'save_post', array( __CLASS__, 'purge_cache' ) );
    }

    /**
     * 注册路由规则
     */
    public static function register_rewrite_rules() {
        add_rewrite_rule( '^llms\.txt$', 'index.php?qigeo_llms=brief', 'top' );
        add_rewrite_rule( '^llms-full\.txt$', 'index.php?qigeo_llms=full', 'top' );
        add_rewrite_tag( '%qigeo_llms%', '([^&]+)' );
    }

    /**
     * 响应 /llms.txt 与 /llms-full.txt
     */
    public static function handle_request() {
        if ( is_admin() ) {
            return;
        }

        $settings = class_exists( 'QiGeo_Settings' ) ? QiGeo_Settings::get_settings() : array();
        $enabled = ! isset( $settings['enable_llms_txt'] ) || $settings['enable_llms_txt'] === '1';
        if ( ! $enabled ) {
            return;
        }

        $type = get_query_var( 'qigeo_llms' );
        if ( ! $type ) {
            $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
            $home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
            $request_path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
            if ( $home_path !== '' && 0 === strpos( $request_path, $home_path ) ) {
                $request_path = trim( substr( $request_path, strlen( $home_path ) ), '/' );
            }

            if ( $request_path === 'llms.txt' || isset( $_GET['qigeo_llms_txt'] ) ) {
                $type = 'brief';
            } elseif ( $request_path === 'llms-full.txt' || isset( $_GET['qigeo_llms_full_txt'] ) ) {
                $type = 'full';
            }
        }

        if ( ! in_array( $type, array( 'brief', 'full' ), true ) ) {
            return;
        }

        $content = ( $type === 'full' ) ? self::get_full_content() : self::get_brief_content();

        // 输出纯文本 Markdown，供 AI 爬虫秒级读取
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Robots-Tag: index, follow' );
        header( 'Cache-Control: public, max-age=3600' );

        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * 获取标准版 /llms.txt 索引
     *
     * @return string
     */
    public static function get_brief_content() {
        $cached = get_transient( self::CACHE_KEY_BRIEF );
        if ( is_string( $cached ) && $cached !== '' ) {
            return $cached;
        }

        $markdown = self::generate_markdown( false );
        set_transient( self::CACHE_KEY_BRIEF, $markdown, self::CACHE_TTL );
        return $markdown;
    }

    /**
     * 获取完整版 /llms-full.txt 索引
     *
     * @return string
     */
    public static function get_full_content() {
        $cached = get_transient( self::CACHE_KEY_FULL );
        if ( is_string( $cached ) && $cached !== '' ) {
            return $cached;
        }

        $markdown = self::generate_markdown( true );
        set_transient( self::CACHE_KEY_FULL, $markdown, self::CACHE_TTL );
        return $markdown;
    }

    /**
     * 生成符合 llmstxt.org 规范的 Markdown 文本
     *
     * @param bool $is_full 是否输出包含全部 FAQ 与要点的详细版
     * @return string
     */
    private static function generate_markdown( $is_full = false ) {
        $brand_context = class_exists( 'QiGeo_Brand_Entity_Library' )
            ? QiGeo_Brand_Entity_Library::get_global_profile_context()
            : array();

        $brand_name = ! empty( $brand_context['brand_name'] ) ? (string) $brand_context['brand_name'] : get_bloginfo( 'name' );
        $official_site = ! empty( $brand_context['official_site'] ) ? (string) $brand_context['official_site'] : home_url();
        $site_purpose = ! empty( $brand_context['site_purpose'] ) ? (string) $brand_context['site_purpose'] : get_bloginfo( 'description' );

        $lines = array();

        // 标题与核心定位块
        $lines[] = '# ' . $brand_name;
        $lines[] = '';
        if ( $site_purpose !== '' ) {
            $lines[] = '> ' . str_replace( "\n", ' ', $site_purpose );
            $lines[] = '';
        }

        // 官方主页与全网实体
        $lines[] = '- 官网地址: ' . $official_site;
        if ( ! empty( $brand_context['target_keyword'] ) ) {
            $lines[] = '- 核心定位词: ' . $brand_context['target_keyword'];
        }
        if ( ! empty( $brand_context['same_as'] ) && is_array( $brand_context['same_as'] ) ) {
            $lines[] = '- 官方权威主页: ' . implode( ', ', $brand_context['same_as'] );
        }
        $lines[] = '';

        // 核心产品与业务
        if ( ! empty( $brand_context['core_products'] ) && is_array( $brand_context['core_products'] ) ) {
            $lines[] = '## 核心产品与解决方案 (Core Offerings)';
            $lines[] = '';
            foreach ( $brand_context['core_products'] as $product ) {
                $lines[] = '- ' . trim( (string) $product );
            }
            $lines[] = '';
        }

        // 适合与不适合推荐场景
        if ( ! empty( $brand_context['recommended_scenarios'] ) && is_array( $brand_context['recommended_scenarios'] ) ) {
            $lines[] = '## 适用场景与客群 (Target Scenarios)';
            $lines[] = '';
            foreach ( $brand_context['recommended_scenarios'] as $scene ) {
                $lines[] = '- ' . trim( (string) $scene );
            }
            $lines[] = '';
        }

        // 权威事实与证据证明
        if ( ! empty( $brand_context['authority_proofs'] ) && is_array( $brand_context['authority_proofs'] ) ) {
            $lines[] = '## 事实与可信度背书 (Verified Facts & Proofs)';
            $lines[] = '';
            foreach ( $brand_context['authority_proofs'] as $proof ) {
                $lines[] = '- ' . trim( (string) $proof );
            }
            $lines[] = '';
        }

        // 核心页面清单
        if ( ! empty( $brand_context['core_pages'] ) && is_array( $brand_context['core_pages'] ) ) {
            $lines[] = '## 核心架构页面 (Key Pages)';
            $lines[] = '';
            foreach ( $brand_context['core_pages'] as $page_line ) {
                $parts = preg_split( '/\s*(?:\|\||｜｜)\s*/u', trim( (string) $page_line ), 3 );
                if ( is_array( $parts ) && count( $parts ) >= 2 ) {
                    $title = trim( (string) $parts[0] );
                    $url = trim( (string) $parts[1] );
                    $desc = isset( $parts[2] ) ? trim( (string) $parts[2] ) : '';
                    $lines[] = '- [' . $title . '](' . esc_url( $url ) . ')' . ( $desc !== '' ? ': ' . $desc : '' );
                } else {
                    $lines[] = '- ' . trim( (string) $page_line );
                }
            }
            $lines[] = '';
        }

        // 优质知识库与高分文章索引
        $articles = self::get_high_quality_articles( $is_full ? 50 : 25 );
        if ( ! empty( $articles ) ) {
            $lines[] = '## 优质知识库与文章索引 (Curated Knowledge Base)';
            $lines[] = '';
            foreach ( $articles as $art ) {
                $title = get_the_title( $art['post_id'] );
                $url = get_permalink( $art['post_id'] );
                if ( ! $title || ! $url ) {
                    continue;
                }

                $summary = ! empty( $art['summary'] ) ? trim( (string) $art['summary'] ) : '';
                $lines[] = '### [' . $title . '](' . esc_url( $url ) . ')';
                if ( $summary !== '' ) {
                    $lines[] = $summary;
                }

                if ( $is_full ) {
                    // 详细版补充核心要点与 FAQ 问答
                    if ( ! empty( $art['key_points'] ) && is_array( $art['key_points'] ) ) {
                        $lines[] = '';
                        $lines[] = '**核心要点:**';
                        foreach ( array_slice( $art['key_points'], 0, 4 ) as $kp ) {
                            $lines[] = '- ' . trim( (string) $kp );
                        }
                    }

                    if ( ! empty( $art['faq'] ) && is_array( $art['faq'] ) ) {
                        $lines[] = '';
                        $lines[] = '**常见问答 (FAQ):**';
                        foreach ( array_slice( $art['faq'], 0, 3 ) as $f ) {
                            if ( is_array( $f ) && ! empty( $f['q'] ) && ! empty( $f['a'] ) ) {
                                $lines[] = '- **问:** ' . trim( (string) $f['q'] );
                                $lines[] = '  **答:** ' . trim( (string) $f['a'] );
                            }
                        }
                    }
                }

                $lines[] = '';
            }
        }

        // 结尾协议标注
        $lines[] = '---';
        $lines[] = 'Generated by QiLing GEO Plugin for WordPress (llmstxt.org standard).';

        return implode( "\n", $lines ) . "\n";
    }

    /**
     * 获取通过 GEO 质检的高质量已发布文章
     *
     * @param int $limit 数量上限
     * @return array<int,array<string,mixed>>
     */
    private static function get_high_quality_articles( $limit = 25 ) {
        global $wpdb;

        $snapshot_table = $wpdb->prefix . 'qigeo_post_snapshots';
        if ( class_exists( 'QiGeo_Admin_Helper' ) && ! QiGeo_Admin_Helper::table_exists( $snapshot_table ) ) {
            return array();
        }

        $sql = "SELECT s.post_id, s.score, s.summary, s.key_points_json, s.faq_json
                FROM {$snapshot_table} s
                INNER JOIN {$wpdb->posts} p ON s.post_id = p.ID
                WHERE p.post_status = 'publish'
                  AND p.post_type = 'post'
                  AND s.status = 'success'
                ORDER BY s.score DESC, s.post_id DESC
                LIMIT %d";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, absint( $limit ) ), ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $articles = array();
        foreach ( $rows as $row ) {
            $key_points = json_decode( (string) $row['key_points_json'], true );
            $faq = json_decode( (string) $row['faq_json'], true );

            $articles[] = array(
                'post_id'    => absint( $row['post_id'] ),
                'score'      => isset( $row['score'] ) ? (float) $row['score'] : 0,
                'summary'    => isset( $row['summary'] ) ? (string) $row['summary'] : '',
                'key_points' => is_array( $key_points ) ? $key_points : array(),
                'faq'        => is_array( $faq ) ? $faq : array(),
            );
        }

        return $articles;
    }

    /**
     * 在前台 <head> 中插入 LLMs.txt 发现链接（类似 RSS）
     */
    public static function output_head_link() {
        $settings = class_exists( 'QiGeo_Settings' ) ? QiGeo_Settings::get_settings() : array();
        $enabled = ! isset( $settings['enable_llms_txt'] ) || $settings['enable_llms_txt'] === '1';
        if ( ! $enabled ) {
            return;
        }

        $llms_url = home_url( '/llms.txt' );
        echo "\n" . '<link rel="alternate" type="text/markdown" href="' . esc_url( $llms_url ) . '" title="LLMs.txt" />' . "\n";
    }

    /**
     * 在 robots.txt 中注入 llms.txt 指引
     *
     * @param string $output robots.txt 内容
     * @return string
     */
    public static function filter_robots_txt( $output ) {
        $settings = class_exists( 'QiGeo_Settings' ) ? QiGeo_Settings::get_settings() : array();
        $enabled = ! isset( $settings['enable_llms_txt'] ) || $settings['enable_llms_txt'] === '1';
        if ( ! $enabled ) {
            return $output;
        }

        $llms_url = home_url( '/llms.txt' );
        $llms_full_url = home_url( '/llms-full.txt' );

        $extra = "\n# AI Agent & LLMs Knowledge Discovery\n";
        $extra .= "Allow: /llms.txt\n";
        $extra .= "Allow: /llms-full.txt\n";
        $extra .= "# LLMs-Txt: " . esc_url( $llms_url ) . "\n";
        $extra .= "# LLMs-Full-Txt: " . esc_url( $llms_full_url ) . "\n";

        return $output . $extra;
    }

    /**
     * 清理缓存
     */
    public static function purge_cache() {
        delete_transient( self::CACHE_KEY_BRIEF );
        delete_transient( self::CACHE_KEY_FULL );
    }
}
