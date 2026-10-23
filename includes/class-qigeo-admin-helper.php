<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Admin_Helper {

    /**
     * @var array<string,bool>
     */
    private static $table_exists_cache = array();

    /**
     * @param string $table table name
     * @return bool
     */
    public static function table_exists( $table ) {
        global $wpdb;

        $table = (string) $table;
        if ( $table === '' ) {
            return false;
        }

        if ( isset( self::$table_exists_cache[ $table ] ) ) {
            return self::$table_exists_cache[ $table ];
        }

        self::$table_exists_cache[ $table ] = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        return self::$table_exists_cache[ $table ];
    }

    /**
     * @param string              $title  title
     * @param array<int,string>   $badges badges
     * @param string              $for    field id
     * @return string
     */
    public static function get_label_html( $title, $badges = array(), $for = '' ) {
        $tag = $for !== '' ? 'label' : 'span';
        $attr = $for !== '' ? ' for="' . esc_attr( $for ) . '"' : '';
        $badges_html = self::get_badges_html( $badges );

        return sprintf(
            '<%1$s%2$s class="qigeo-field-label"><strong class="qigeo-field-label-text">%3$s</strong>%4$s</%1$s>',
            esc_html( $tag ),
            $attr,
            esc_html( $title ),
            $badges_html !== '' ? '<span class="qigeo-field-badges">' . $badges_html . '</span>' : ''
        );
    }

    /**
     * @param string            $title  title
     * @param array<int,string> $badges badges
     * @return string
     */
    public static function get_row_label_html( $title, $badges = array() ) {
        return self::get_label_html( $title, $badges );
    }

    /**
     * @param string $context helper text
     * @return string
     */
    public static function get_priority_legend_html( $context = '' ) {
        return '';
    }

    /**
     * @param array<int,string> $badges badges
     * @return string
     */
    private static function get_badges_html( $badges ) {
        $badge_map = array(
            'required' => array(
                'label' => '必填',
                'class' => 'qigeo-badge--required',
            ),
            'key' => array(
                'label' => '重点',
                'class' => 'qigeo-badge--key',
            ),
            'recommended' => array(
                'label' => '推荐',
                'class' => 'qigeo-badge--recommended',
            ),
        );

        $html = '';
        foreach ( (array) $badges as $badge_key ) {
            $badge_key = sanitize_key( (string) $badge_key );
            if ( ! isset( $badge_map[ $badge_key ] ) ) {
                continue;
            }

            $badge = $badge_map[ $badge_key ];
            $html .= sprintf(
                '<span class="qigeo-badge %1$s">%2$s</span>',
                esc_attr( $badge['class'] ),
                esc_html( $badge['label'] )
            );
        }

        return $html;
    }

    /**
     * 多字节安全的字符串长度计算
     *
     * @param string $text 文本
     * @return int
     */
    public static function mb_safe_len( $text ) {
        $text = (string) $text;
        if ( function_exists( 'mb_strlen' ) ) {
            return mb_strlen( $text, 'UTF-8' );
        }
        return strlen( $text );
    }

    /**
     * 多字节安全的字符串截断，绝不切断 UTF-8 字符
     *
     * @param string $text 待截断文本
     * @param int    $length 截断长度（字符数）
     * @param bool   $append_ellipsis 是否在超出时追加省略号
     * @return string
     */
    public static function mb_safe_limit( $text, $length = 80, $append_ellipsis = true ) {
        $text = (string) $text;
        $len = self::mb_safe_len( $text );
        if ( $len <= $length ) {
            return $text;
        }

        if ( function_exists( 'mb_substr' ) ) {
            $trimmed = mb_substr( $text, 0, $length, 'UTF-8' );
        } elseif ( function_exists( 'mb_strcut' ) ) {
            $trimmed = mb_strcut( $text, 0, $length * 3, 'UTF-8' );
        } else {
            $trimmed = substr( $text, 0, $length );
            $trimmed = preg_replace( '/[\x80-\xBF]+$/', '', $trimmed );
        }

        return $append_ellipsis ? rtrim( (string) $trimmed ) . '...' : rtrim( (string) $trimmed );
    }

    /**
     * 平台与别名标准映射表（Single Source of Truth 单一事实来源）
     *
     * @return array<string,array<int,string>>
     */
    public static function get_platform_alias_map() {
        return array(
            'doubao'   => array( 'doubao', '豆包', '火山', '方舟', 'volcengine', 'ark' ),
            'deepseek' => array( 'deepseek', 'deep seek', '深度求索', '深度搜索', '深度思考' ),
            'openai'   => array( 'openai', 'open ai', 'chatgpt', 'chat gpt', 'gpt' ),
            'qwen'     => array( 'qwen', '通义', '千问', '阿里云百炼', '百炼', 'dashscope' ),
        );
    }

    /**
     * 将平台名称或别名标准化为系统统一标识键
     *
     * @param string $target_platform 平台名称或别名
     * @return string 标准化键名 ('doubao', 'deepseek', 'openai', 'qwen' 等)，未识别则返回空字符串
     */
    public static function normalize_platform_key( $target_platform ) {
        $value = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( sanitize_text_field( (string) $target_platform ), 'UTF-8' ) : strtolower( sanitize_text_field( (string) $target_platform ) ) );
        if ( $value === '' ) {
            return '';
        }

        foreach ( self::get_platform_alias_map() as $key => $aliases ) {
            foreach ( $aliases as $alias ) {
                $alias_value = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $alias, 'UTF-8' ) : strtolower( (string) $alias );
                if ( $alias_value !== '' && strpos( $value, $alias_value ) !== false ) {
                    return $key;
                }
            }
        }

        return '';
    }

    /**
     * 校验模型配置是否匹配指定平台
     *
     * @param array  $provider_model 模型配置项
     * @param string $platform_key   标准平台键
     * @return bool
     */
    public static function provider_matches_platform( $provider_model, $platform_key ) {
        $provider_type = isset( $provider_model['provider_type'] ) ? sanitize_key( (string) $provider_model['provider_type'] ) : '';
        return $provider_type !== '' && $provider_type === sanitize_key( (string) $platform_key );
    }

    /**
     * 校验服务商是否已启用且配置完整
     *
     * @param array $provider 服务商配置
     * @return bool
     */
    public static function provider_usable( $provider ) {
        return ! empty( $provider['enabled'] ) && (string) $provider['enabled'] === '1' && ! empty( $provider['base_url'] ) && ! empty( $provider['api_key'] );
    }
}
