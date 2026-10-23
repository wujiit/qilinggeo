<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Frontend {

    /**
     * @var array<int,array<string,mixed>>
     */
    private static $output_context_cache = array();

    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_filter( 'the_content', array( __CLASS__, 'append_summary_block' ) );
        add_action( 'wp_head', array( __CLASS__, 'output_structured_data' ), 99 );
    }

    public static function enqueue_assets() {
        if ( is_admin() || ! is_singular() ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id ) {
            return;
        }

        $context = self::get_output_context( $post_id );
        if ( empty( $context['output_allowed'] ) ) {
            return;
        }

        if ( empty( $context['show_summary'] ) && empty( $context['show_faq'] ) && empty( $context['show_key_entities'] ) ) {
            return;
        }

        wp_enqueue_style( 'qigeo-frontend', QIGEO_URL . 'assets/frontend.css', array(), QIGEO_VERSION );
    }

    public static function append_summary_block( $content ) {
        if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return $content;
        }

        $context = self::get_output_context( $post_id );
        if ( empty( $context['output_allowed'] ) ) {
            return $content;
        }

        $settings = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : array();
        $summary = isset( $context['summary'] ) ? (string) $context['summary'] : '';
        $faq = isset( $context['faq'] ) && is_array( $context['faq'] ) ? $context['faq'] : array();
        $key_points = isset( $context['key_points'] ) && is_array( $context['key_points'] ) ? $context['key_points'] : array();
        $entities = isset( $context['entities'] ) && is_array( $context['entities'] ) ? $context['entities'] : array();
        $show_summary = ! empty( $context['show_summary'] );
        $show_faq = ! empty( $context['show_faq'] );
        $show_key_entities = ! empty( $context['show_key_entities'] );

        if ( ! $show_summary && ! $show_faq && ! $show_key_entities ) {
            return $content;
        }

        $faq_max_items = isset( $settings['faq_max_items'] ) ? absint( $settings['faq_max_items'] ) : 5;
        if ( $faq_max_items < 1 ) {
            $faq_max_items = 1;
        } elseif ( $faq_max_items > 20 ) {
            $faq_max_items = 20;
        }

        $block = '<section class="qigeo-insights-block" aria-label="内容洞察">';
        $block .= '<header class="qigeo-insights-head">';
        $block .= '<h3 class="qigeo-insights-title">内容洞察</h3>';
        $block .= '<span class="qigeo-insights-badge">Qiling GEO</span>';
        $block .= '</header>';

        if ( $show_summary ) {
            $block .= '<div class="qigeo-summary-panel">';
            $block .= '<h4 class="qigeo-panel-title">内容摘要</h4>';
            $block .= '<p class="qigeo-summary-text">' . esc_html( $summary ) . '</p>';
            $block .= '</div>';
        }

        if ( $show_key_entities ) {
            $block .= '<div class="qigeo-key-panel">';
            if ( ! empty( $key_points ) ) {
                $block .= '<h4 class="qigeo-panel-title">核心要点</h4>';
                $block .= '<ul class="qigeo-key-points">';
                foreach ( $key_points as $point ) {
                    if ( ! is_scalar( $point ) ) {
                        continue;
                    }
                    $text = trim( (string) $point );
                    if ( $text === '' ) {
                        continue;
                    }
                    $block .= '<li>' . esc_html( $text ) . '</li>';
                }
                $block .= '</ul>';
            }

            if ( ! empty( $entities ) ) {
                $block .= '<h4 class="qigeo-panel-title">主题实体</h4>';
                $block .= '<div class="qigeo-entity-list">';
                foreach ( $entities as $entity ) {
                    if ( ! is_scalar( $entity ) ) {
                        continue;
                    }
                    $name = trim( (string) $entity );
                    if ( $name === '' ) {
                        continue;
                    }
                    $block .= '<span class="qigeo-entity-tag">' . esc_html( $name ) . '</span>';
                }
                $block .= '</div>';
            }
            $block .= '</div>';
        }

        if ( $show_faq ) {
            $block .= '<div class="qigeo-faq-panel">';
            $block .= '<h4 class="qigeo-panel-title">常见问题</h4>';
            $block .= '<div class="qigeo-faq-list">';

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

                $block .= '<details class="qigeo-faq-item">';
                $block .= '<summary class="qigeo-faq-question">' . esc_html( $q ) . '</summary>';
                $block .= '<div class="qigeo-faq-answer">' . esc_html( $a ) . '</div>';
                $block .= '</details>';

                $count++;
                if ( $count >= $faq_max_items ) {
                    break;
                }
            }

            $block .= '</div>';
            $block .= '</div>';
        }

        $block .= '</section>';

        return $content . $block;
    }

    public static function output_structured_data() {
        if ( is_admin() ) {
            return;
        }

        self::output_organization_schema();
        self::output_faq_schema();
    }

    /**
     * 全局输出 Organization + sameAs 品牌实体 Schema
     * 帮助 AI 搜索爬虫（如 ByteSpider、GPTBot）将站点与全网官方主页建立知识图谱实体绑定
     */
    public static function output_organization_schema() {
        $settings = QiGeo_Settings::get_settings();
        $enabled = ! isset( $settings['enable_org_schema'] ) || $settings['enable_org_schema'] === '1';
        if ( ! $enabled ) {
            return;
        }

        if ( ! class_exists( 'QiGeo_Brand_Entity_Library' ) ) {
            return;
        }

        $brand_context = QiGeo_Brand_Entity_Library::get_global_profile_context();
        if ( empty( $brand_context ) || empty( $brand_context['brand_name'] ) ) {
            return;
        }

        $brand_name = (string) $brand_context['brand_name'];
        $official_site = ! empty( $brand_context['official_site'] ) ? (string) $brand_context['official_site'] : home_url();
        $site_purpose = ! empty( $brand_context['site_purpose'] ) ? (string) $brand_context['site_purpose'] : get_bloginfo( 'description' );
        $same_as = isset( $brand_context['same_as'] ) && is_array( $brand_context['same_as'] ) ? $brand_context['same_as'] : array();
        $brand_aliases = isset( $brand_context['brand_aliases'] ) && is_array( $brand_context['brand_aliases'] ) ? $brand_context['brand_aliases'] : array();

        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            '@id'      => trailingslashit( home_url() ) . '#organization',
            'name'     => $brand_name,
            'url'      => esc_url( $official_site ),
        );

        if ( $site_purpose !== '' ) {
            $schema['description'] = $site_purpose;
        }

        if ( ! empty( $brand_aliases ) ) {
            $schema['alternateName'] = array_values( array_slice( $brand_aliases, 0, 10 ) );
        }

        if ( ! empty( $same_as ) ) {
            $schema['sameAs'] = array_values( array_map( 'esc_url', $same_as ) );
        }

        echo "\n<script type=\"application/ld+json\" id=\"qigeo-organization-schema\">" . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
    }

    /**
     * 在文章页输出 FAQPage Schema
     */
    public static function output_faq_schema() {
        if ( ! is_singular() ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id ) {
            return;
        }

        $context = self::get_output_context( $post_id );
        $settings = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : array();
        if ( self::should_skip_schema_for_noindex( $post_id, $settings ) ) {
            return;
        }

        if ( empty( $context['output_allowed'] ) ) {
            return;
        }

        if ( empty( $settings['enable_faq_schema'] ) || $settings['enable_faq_schema'] !== '1' ) {
            return;
        }

        $faq = isset( $context['faq'] ) && is_array( $context['faq'] ) ? $context['faq'] : array();
        if ( empty( $faq ) ) {
            return;
        }

        $faq_max_items = isset( $settings['faq_max_items'] ) ? absint( $settings['faq_max_items'] ) : 5;
        if ( $faq_max_items < 1 ) {
            $faq_max_items = 1;
        } elseif ( $faq_max_items > 20 ) {
            $faq_max_items = 20;
        }

        $entities = array();
        foreach ( $faq as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : '';
            $a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : '';
            if ( $q === '' || $a === '' ) {
                continue;
            }
            $entities[] = array(
                '@type' => 'Question',
                'name' => $q,
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text' => $a,
                ),
            );

            if ( count( $entities ) >= $faq_max_items ) {
                break;
            }
        }

        if ( empty( $entities ) ) {
            return;
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        );

        echo "\n<script type=\"application/ld+json\" id=\"qigeo-faq-schema\">" . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
    }

    /**
     * 单请求缓存前台输出所需数据，避免同页重复读取 GEO 快照和重复 JSON 解码。
     *
     * @param int $post_id post id
     * @return array<string,mixed>
     */
    private static function get_output_context( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return array();
        }

        if ( isset( self::$output_context_cache[ $post_id ] ) ) {
            return self::$output_context_cache[ $post_id ];
        }

        $settings = QiGeo_Settings::get_settings();
        $summary = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, QiGeo_Analyzer::META_SUMMARY );
        $faq = self::read_json_meta( $post_id, QiGeo_Analyzer::META_FAQ_JSON );
        $key_points = self::read_json_meta( $post_id, QiGeo_Analyzer::META_KEY_POINTS_JSON );
        $entities = self::read_json_meta( $post_id, QiGeo_Analyzer::META_ENTITIES_JSON );
        $output_allowed = QiGeo_Analyzer::is_frontend_output_allowed( $post_id, $settings, $summary, $faq );

        self::$output_context_cache[ $post_id ] = array(
            'settings' => $settings,
            'summary' => $summary,
            'faq' => $faq,
            'key_points' => $key_points,
            'entities' => $entities,
            'output_allowed' => $output_allowed,
            'show_summary' => ! empty( $settings['append_summary_block'] ) && $settings['append_summary_block'] === '1' && $summary !== '',
            'show_faq' => ! empty( $settings['append_faq_block'] ) && $settings['append_faq_block'] === '1' && ! empty( $faq ),
            'show_key_entities' => ! empty( $settings['append_key_entities_block'] ) && $settings['append_key_entities_block'] === '1'
                && ( ! empty( $key_points ) || ! empty( $entities ) ),
        );

        return self::$output_context_cache[ $post_id ];
    }

    /**
     * @param int                $post_id post_id
     * @param array<string,mixed> $settings settings
     * @return bool
     */
    private static function should_skip_schema_for_noindex( $post_id, $settings ) {
        if ( empty( $settings['disable_schema_on_noindex'] ) || $settings['disable_schema_on_noindex'] !== '1' ) {
            return false;
        }

        $robots = apply_filters( 'wp_robots', array() );
        if ( is_array( $robots ) && ! empty( $robots['noindex'] ) ) {
            return true;
        }

        $yoast_noindex = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
        if ( $yoast_noindex === '1' ) {
            return true;
        }

        $rankmath_robots = get_post_meta( $post_id, 'rank_math_robots', true );
        if ( is_array( $rankmath_robots ) && in_array( 'noindex', $rankmath_robots, true ) ) {
            return true;
        }

        $aioseo_noindex = (string) get_post_meta( $post_id, '_aioseo_robots_noindex', true );
        if ( $aioseo_noindex === '1' || $aioseo_noindex === 'on' ) {
            return true;
        }

        return false;
    }

    /**
     * @param int    $post_id post_id
     * @param string $meta_key meta_key
     * @return array<int,mixed>
     */
    private static function read_json_meta( $post_id, $meta_key ) {
        $raw = (string) QiGeo_Analyzer::get_snapshot_value( $post_id, $meta_key );
        if ( $raw === '' ) {
            return array();
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
