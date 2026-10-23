<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 文章分析载荷与 GEO 准备度信号提取器
 * 负责解析 HTML、链接、标题、结构化数据与 SEO 元数据
 */
class QiGeo_Payload_Builder {

    /**
     * 构建送入大模型分析的完整文章上下文 Payload
     *
     * @param WP_Post $post 文章对象
     * @return array<string,mixed>
     */
    public static function build_article_payload( $post ) {
        if ( ! $post instanceof WP_Post ) {
            return array();
        }

        $tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
        $categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
        $content_html = (string) $post->post_content;
        $content_plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content_html ) ) );
        $links = self::extract_links_from_html( $content_html );
        $site_url = home_url( '/' );
        $permalink = QiGeo_Post_Snapshot::get_article_source_url( $post->ID, true );
        $canonical_url = $permalink;
        $headings = self::extract_headings_from_html( $content_html );
        $detected_source_urls = self::filter_external_source_links( $links, $site_url );
        $content_images = self::extract_images_from_html( $content_html );
        $author = self::build_author_payload( $post );
        $featured_image = self::build_featured_image_payload( $post->ID );
        $seo_meta = self::build_seo_meta_payload( $post->ID );
        $crawlability = self::build_crawlability_payload( $post->ID, $post );
        $json_ld_types = self::extract_json_ld_types_from_html( $content_html );

        $plain_len = QiGeo_Admin_Helper::mb_safe_len( $content_plain );

        return array(
            'post_id'                => (int) $post->ID,
            'url'                    => $permalink,
            'canonical_url'          => $canonical_url,
            'title'                  => get_the_title( $post->ID ),
            'excerpt'                => wp_strip_all_tags( get_the_excerpt( $post->ID ) ),
            'content'                => QiGeo_Admin_Helper::mb_safe_limit( $content_plain, 12000, false ),
            'content_truncated'      => $plain_len > 12000,
            'headings'               => $headings,
            'links'                  => $links,
            'detected_source_urls'   => $detected_source_urls,
            'categories'             => is_array( $categories ) ? array_values( $categories ) : array(),
            'tags'                   => is_array( $tags ) ? array_values( $tags ) : array(),
            'modified_at'            => (string) get_post_modified_time( 'c', true, $post->ID ),
            'published_at'           => (string) get_post_time( 'c', true, $post->ID ),
            'language'               => (string) get_locale(),
            'site'                   => array(
                'name'        => (string) get_bloginfo( 'name' ),
                'url'         => $site_url,
                'description' => (string) get_bloginfo( 'description' ),
                'language'    => (string) get_locale(),
            ),
            'site_name'              => (string) get_bloginfo( 'name' ),
            'author'                 => $author,
            'featured_image'         => $featured_image,
            'content_images'         => $content_images,
            'seo_meta'               => $seo_meta,
            'crawlability'           => $crawlability,
            'existing_json_ld_types' => $json_ld_types,
            'geo_readiness_signals'  => self::build_geo_readiness_signals(
                $content_plain,
                $headings,
                $links,
                $detected_source_urls,
                $content_images,
                $author,
                $featured_image,
                $seo_meta,
                $crawlability,
                $json_ld_types,
                $permalink,
                $canonical_url
            ),
        );
    }

    /**
     * 提取 HTML 中的 H1~H6 标题层级
     *
     * @param string $html HTML 内容
     * @return array<int,array<string,mixed>>
     */
    public static function extract_headings_from_html( $html ) {
        $items = array();
        if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', (string) $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $match[2] ) ) );
                if ( $text === '' ) {
                    continue;
                }
                $items[] = array(
                    'level' => absint( $match[1] ),
                    'text'  => QiGeo_Admin_Helper::mb_safe_limit( $text, 180, false ),
                );
                if ( count( $items ) >= 30 ) {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * 提取 HTML 中的链接与锚文本
     *
     * @param string $html HTML 内容
     * @return array<int,array<string,mixed>>
     */
    public static function extract_links_from_html( $html ) {
        $items = array();
        if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', (string) $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $url = esc_url_raw( html_entity_decode( (string) $match[1], ENT_QUOTES ) );
                if ( $url === '' ) {
                    continue;
                }
                $anchor = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $match[2] ) ) );
                $items[] = array(
                    'url'         => $url,
                    'anchor'      => QiGeo_Admin_Helper::mb_safe_limit( $anchor, 160, false ),
                    'is_internal' => self::is_internal_url( $url ),
                );
                if ( count( $items ) >= 80 ) {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * 提取 HTML 中的图片及 alt 属性
     *
     * @param string $html HTML 内容
     * @return array<int,array<string,string>>
     */
    public static function extract_images_from_html( $html ) {
        $items = array();
        if ( preg_match_all( '/<img\b[^>]*>/is', (string) $html, $matches ) ) {
            foreach ( (array) $matches[0] as $tag ) {
                $src = esc_url_raw( html_entity_decode( self::extract_html_attr( $tag, 'src' ), ENT_QUOTES ) );
                if ( $src === '' ) {
                    continue;
                }
                $alt = trim( (string) self::extract_html_attr( $tag, 'alt' ) );
                $items[] = array(
                    'src' => $src,
                    'alt' => QiGeo_Admin_Helper::mb_safe_limit( $alt, 160, false ),
                );
                if ( count( $items ) >= 40 ) {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * 提取 HTML 标签的指定属性
     *
     * @param string $tag HTML 标签字符串
     * @param string $attr 属性名
     * @return string
     */
    public static function extract_html_attr( $tag, $attr ) {
        if ( preg_match( '/\s' . preg_quote( (string) $attr, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', (string) $tag, $match ) ) {
            return (string) $match[2];
        }

        return '';
    }

    /**
     * 过滤外部来源链接
     *
     * @param array<int,array<string,mixed>> $links 链接列表
     * @param string                         $site_url 本站URL
     * @return array<int,array<string,string>>
     */
    public static function filter_external_source_links( $links, $site_url ) {
        $items = array();
        $site_host = wp_parse_url( $site_url, PHP_URL_HOST );
        foreach ( (array) $links as $link ) {
            if ( ! is_array( $link ) || empty( $link['url'] ) ) {
                continue;
            }
            $url = (string) $link['url'];
            $host = wp_parse_url( $url, PHP_URL_HOST );
            if ( ! $host || ( $site_host && strtolower( (string) $host ) === strtolower( (string) $site_host ) ) ) {
                continue;
            }
            $items[] = array(
                'title'       => isset( $link['anchor'] ) ? (string) $link['anchor'] : '',
                'url'         => $url,
                'claim'       => '',
                'source_type' => 'external',
            );
            if ( count( $items ) >= 20 ) {
                break;
            }
        }

        return $items;
    }

    /**
     * 判断是否属于站内 URL
     *
     * @param string $url 目标URL
     * @return bool
     */
    public static function is_internal_url( $url ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) {
            return true;
        }

        $site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        return $site_host && strtolower( (string) $host ) === strtolower( (string) $site_host );
    }

    /**
     * 构建作者上下文载荷
     *
     * @param WP_Post $post 文章对象
     * @return array<string,mixed>
     */
    public static function build_author_payload( $post ) {
        $author_id = isset( $post->post_author ) ? (int) $post->post_author : 0;
        if ( $author_id <= 0 ) {
            return array();
        }

        return array(
            'id'          => $author_id,
            'name'        => (string) get_the_author_meta( 'display_name', $author_id ),
            'url'         => get_author_posts_url( $author_id ),
            'description' => QiGeo_Admin_Helper::mb_safe_limit( (string) get_the_author_meta( 'description', $author_id ), 500, false ),
        );
    }

    /**
     * 构建特色图片上下文载荷
     *
     * @param int $post_id 文章ID
     * @return array<string,string>
     */
    public static function build_featured_image_payload( $post_id ) {
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) {
            return array();
        }

        $url = wp_get_attachment_image_url( $thumb_id, 'full' );
        if ( ! is_string( $url ) || $url === '' ) {
            return array();
        }

        return array(
            'url' => $url,
            'alt' => QiGeo_Admin_Helper::mb_safe_limit( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ), 200, false ),
        );
    }

    /**
     * 构建主流 SEO 插件元数据载荷
     *
     * @param int $post_id 文章ID
     * @return array<string,string>
     */
    public static function build_seo_meta_payload( $post_id ) {
        $meta_title = (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
        if ( $meta_title === '' ) {
            $meta_title = (string) get_post_meta( $post_id, 'rank_math_title', true );
        }
        if ( $meta_title === '' ) {
            $meta_title = (string) get_post_meta( $post_id, '_aioseo_title', true );
        }

        $meta_description = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        if ( $meta_description === '' ) {
            $meta_description = (string) get_post_meta( $post_id, 'rank_math_description', true );
        }
        if ( $meta_description === '' ) {
            $meta_description = (string) get_post_meta( $post_id, '_aioseo_description', true );
        }

        return array(
            'title'       => QiGeo_Admin_Helper::mb_safe_limit( $meta_title, 255, false ),
            'description' => QiGeo_Admin_Helper::mb_safe_limit( $meta_description, 500, false ),
        );
    }

    /**
     * 构建可抓取性与 NoIndex 信号载荷
     *
     * @param int          $post_id 文章ID
     * @param WP_Post|null $post 文章对象
     * @return array<string,mixed>
     */
    public static function build_crawlability_payload( $post_id, $post = null ) {
        $rankmath_robots = get_post_meta( $post_id, 'rank_math_robots', true );
        $rankmath_noindex = is_array( $rankmath_robots ) && in_array( 'noindex', $rankmath_robots, true );

        return array(
            'post_status'      => isset( $post->post_status ) ? (string) $post->post_status : '',
            'post_type'        => isset( $post->post_type ) ? (string) $post->post_type : '',
            'yoast_noindex'    => (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) === '1',
            'rankmath_noindex' => $rankmath_noindex,
            'aioseo_noindex'   => in_array( (string) get_post_meta( $post_id, '_aioseo_robots_noindex', true ), array( '1', 'on' ), true ),
        );
    }

    /**
     * 计算并组装文章的 GEO 准备度信号矩阵
     *
     * @param string                         $content_plain 纯文本内容
     * @param array<int,array<string,mixed>> $headings 标题列表
     * @param array<int,array<string,mixed>> $links 链接列表
     * @param array<int,array<string,mixed>> $detected_source_urls 外部引用源列表
     * @param array<int,array<string,string>> $content_images 图片列表
     * @param array<string,mixed>            $author 作者信息
     * @param array<string,string>           $featured_image 特色图
     * @param array<string,string>           $seo_meta SEO 元数据
     * @param array<string,mixed>            $crawlability 抓取信号
     * @param array<int,string>              $json_ld_types 结构化数据类型
     * @param string                         $permalink 固定链接
     * @param string                         $canonical_url 标准链接
     * @return array<string,mixed>
     */
    public static function build_geo_readiness_signals(
        $content_plain,
        $headings,
        $links,
        $detected_source_urls,
        $content_images,
        $author,
        $featured_image,
        $seo_meta,
        $crawlability,
        $json_ld_types,
        $permalink,
        $canonical_url
    ) {
        $internal_link_count = 0;
        $external_link_count = 0;
        foreach ( (array) $links as $link ) {
            if ( ! is_array( $link ) ) {
                continue;
            }
            if ( ! empty( $link['is_internal'] ) ) {
                $internal_link_count++;
            } else {
                $external_link_count++;
            }
        }

        $heading_levels = array(
            'h1' => 0,
            'h2' => 0,
            'h3' => 0,
            'h4' => 0,
            'h5' => 0,
            'h6' => 0,
        );
        foreach ( (array) $headings as $heading ) {
            if ( ! is_array( $heading ) || empty( $heading['level'] ) ) {
                continue;
            }
            $level = 'h' . absint( $heading['level'] );
            if ( isset( $heading_levels[ $level ] ) ) {
                $heading_levels[ $level ]++;
            }
        }

        $image_count = count( (array) $content_images );
        $image_alt_count = 0;
        foreach ( (array) $content_images as $image ) {
            if ( is_array( $image ) && ! empty( $image['alt'] ) ) {
                $image_alt_count++;
            }
        }
        if ( ! empty( $featured_image ) ) {
            $image_count++;
            if ( ! empty( $featured_image['alt'] ) ) {
                $image_alt_count++;
            }
        }

        $is_noindex = ! empty( $crawlability['yoast_noindex'] ) || ! empty( $crawlability['rankmath_noindex'] ) || ! empty( $crawlability['aioseo_noindex'] );
        $canonical_matches_permalink = trim( (string) $canonical_url ) !== ''
            && rtrim( (string) $canonical_url, '/' ) === rtrim( (string) $permalink, '/' );

        $audit_hints = array();
        if ( empty( $detected_source_urls ) ) {
            $audit_hints[] = '缺少可公开引用的外部来源链接，证据和来源覆盖度需要重点检查';
        }
        if ( empty( $author['name'] ) || empty( $author['description'] ) ) {
            $audit_hints[] = '作者或机构可信度信息不足，实体清晰度和可引用性会受影响';
        }
        if ( empty( $seo_meta['title'] ) || empty( $seo_meta['description'] ) ) {
            $audit_hints[] = 'SEO 标题或描述不完整，AI 抓取摘要时缺少稳定定位信号';
        }
        if ( empty( $json_ld_types ) ) {
            $audit_hints[] = '页面正文中未检测到 JSON-LD 类型，需要检查结构化数据完整度';
        }
        if ( $image_count > $image_alt_count ) {
            $audit_hints[] = '部分图片缺少 alt，图片证据和实体解释信号不足';
        }
        if ( $is_noindex ) {
            $audit_hints[] = '检测到 noindex 信号，可能影响 AI 与搜索引擎抓取';
        }

        return array(
            'plain_text_length'           => QiGeo_Admin_Helper::mb_safe_len( $content_plain ),
            'heading_count'               => count( (array) $headings ),
            'heading_levels'              => $heading_levels,
            'internal_link_count'         => $internal_link_count,
            'external_link_count'         => $external_link_count,
            'detected_source_url_count'   => count( (array) $detected_source_urls ),
            'has_author_name'             => ! empty( $author['name'] ),
            'has_author_description'      => ! empty( $author['description'] ),
            'has_featured_image'          => ! empty( $featured_image ),
            'image_count'                 => $image_count,
            'image_alt_count'             => $image_alt_count,
            'has_seo_title'               => ! empty( $seo_meta['title'] ),
            'has_seo_description'         => ! empty( $seo_meta['description'] ),
            'has_canonical'               => trim( (string) $canonical_url ) !== '',
            'canonical_matches_permalink' => $canonical_matches_permalink,
            'is_noindex'                  => $is_noindex,
            'json_ld_type_count'          => count( (array) $json_ld_types ),
            'audit_hints'                 => $audit_hints,
        );
    }

    /**
     * 从页面 HTML 中提取声明的 JSON-LD 类型
     *
     * @param string $html HTML 内容
     * @return array<int,string>
     */
    public static function extract_json_ld_types_from_html( $html ) {
        $types = array();
        if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', (string) $html, $matches ) ) {
            foreach ( (array) $matches[1] as $json ) {
                $decoded = json_decode( html_entity_decode( trim( (string) $json ), ENT_QUOTES ), true );
                foreach ( self::collect_schema_types( $decoded ) as $type ) {
                    if ( ! in_array( $type, $types, true ) ) {
                        $types[] = $type;
                    }
                    if ( count( $types ) >= 20 ) {
                        break 2;
                    }
                }
            }
        }

        return $types;
    }

    /**
     * 递归收集嵌套结构体中的 @type 字段
     *
     * @param mixed $decoded 解码后的 JSON
     * @return array<int,string>
     */
    public static function collect_schema_types( $decoded ) {
        $types = array();
        if ( is_array( $decoded ) ) {
            if ( isset( $decoded['@type'] ) ) {
                foreach ( (array) $decoded['@type'] as $type ) {
                    if ( is_scalar( $type ) ) {
                        $types[] = sanitize_text_field( (string) $type );
                    }
                }
            }
            foreach ( $decoded as $value ) {
                if ( is_array( $value ) ) {
                    $types = array_merge( $types, self::collect_schema_types( $value ) );
                }
            }
        }

        return array_values( array_filter( array_unique( $types ) ) );
    }
}
