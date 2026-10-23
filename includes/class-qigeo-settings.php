<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Settings {

    /**
     * @var array<string,mixed>|null
     */
    private static $settings_cache = null;

    /**
     * @var array<string,array<string,mixed>>|null
     */
    private static $provider_map_cache = null;

    /**
     * @var array<string,array<string,mixed>>|null
     */
    private static $model_map_cache = null;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_form_submit' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_qigeo_test_provider_connection', array( __CLASS__, 'ajax_test_provider_connection' ) );
    }

    public static function maybe_upgrade() {
        $db_version = (string) get_option( 'qigeo_db_version', '' );
        if ( version_compare( $db_version, QiGeo_Install::DB_VERSION, '<' ) ) {
            QiGeo_Install::create_tables();
        }

        if ( get_option( 'qigeo_settings_autoload_migrated', '' ) !== '1' ) {
            $stored = get_option( QIGEO_OPTION_KEY, null );
            if ( is_array( $stored ) ) {
                update_option( QIGEO_OPTION_KEY, $stored, false );
            }
            update_option( 'qigeo_settings_autoload_migrated', '1', false );
        }
    }

    public static function register_admin_menu() {
        add_menu_page(
            '启灵 GEO',
            '启灵GEO',
            'manage_options',
            'qigeo-settings',
            array( __CLASS__, 'render_page' ),
            'dashicons-admin-site-alt3',
            58
        );
    }

    public static function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_qigeo-settings' ) {
            return;
        }

        wp_enqueue_style( 'qigeo-admin', QIGEO_URL . 'assets/admin.css', array(), QIGEO_VERSION );
        wp_enqueue_script( 'qigeo-admin', QIGEO_URL . 'assets/admin.js', array(), QIGEO_VERSION, true );

        wp_localize_script( 'qigeo-admin', 'qigeoAdminData', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'testProviderNonce' => wp_create_nonce( 'qigeo_test_provider_connection_nonce' ),
            'providerTypes' => self::provider_type_options(),
            'providerEnabledLabel' => __( '启用', 'qilinggeo' ),
            'modelEnabledLabel' => __( '启用', 'qilinggeo' ),
            'testProviderText' => __( '测试', 'qilinggeo' ),
            'testingProviderText' => __( '测试中...', 'qilinggeo' ),
        ) );
    }

    public static function get_settings() {
        if ( is_array( self::$settings_cache ) ) {
            return self::$settings_cache;
        }

        $stored = get_option( QIGEO_OPTION_KEY, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        $defaults = self::get_defaults();
        $merged = wp_parse_args( $stored, $defaults );

        if ( ! isset( $merged['providers'] ) || ! is_array( $merged['providers'] ) ) {
            $merged['providers'] = $defaults['providers'];
        }

        if ( ! isset( $merged['models'] ) || ! is_array( $merged['models'] ) ) {
            $merged['models'] = $defaults['models'];
        }

        if ( isset( $merged['allowed_post_types'] ) && is_array( $merged['allowed_post_types'] ) ) {
            $merged['allowed_post_types'] = array_values( array_filter( array_map( 'sanitize_key', $merged['allowed_post_types'] ), function( $pt ) {
                return post_type_exists( $pt ) && $pt !== 'attachment';
            } ) );
            if ( empty( $merged['allowed_post_types'] ) ) {
                $merged['allowed_post_types'] = array( 'post' );
            }
        } else {
            $merged['allowed_post_types'] = array( 'post' );
        }

        $provider_types = self::provider_type_options();
        foreach ( $merged['providers'] as $i => $provider ) {
            $type = isset( $provider['type'] ) ? sanitize_key( (string) $provider['type'] ) : 'openai';
            if ( ! isset( $provider_types[ $type ] ) ) {
                $merged['providers'][ $i ]['type'] = 'openai';
            }
        }

        $merged['append_key_entities_block'] = ( isset( $merged['append_key_entities_block'] ) && $merged['append_key_entities_block'] === '1' ) ? '1' : '0';
        $merged['disable_schema_on_noindex'] = ( isset( $merged['disable_schema_on_noindex'] ) && $merged['disable_schema_on_noindex'] === '1' ) ? '1' : '0';
        $merged['enable_quality_gate'] = ( isset( $merged['enable_quality_gate'] ) && $merged['enable_quality_gate'] === '1' ) ? '1' : '0';
        $merged['quality_gate_min_score'] = isset( $merged['quality_gate_min_score'] ) ? absint( $merged['quality_gate_min_score'] ) : 60;
        if ( $merged['quality_gate_min_score'] > 100 ) {
            $merged['quality_gate_min_score'] = 100;
        }
        $merged['quality_gate_min_summary_len'] = isset( $merged['quality_gate_min_summary_len'] ) ? absint( $merged['quality_gate_min_summary_len'] ) : 80;
        if ( $merged['quality_gate_min_summary_len'] > 300 ) {
            $merged['quality_gate_min_summary_len'] = 300;
        }
        $merged['quality_gate_min_faq_items'] = isset( $merged['quality_gate_min_faq_items'] ) ? absint( $merged['quality_gate_min_faq_items'] ) : 2;
        if ( $merged['quality_gate_min_faq_items'] > 10 ) {
            $merged['quality_gate_min_faq_items'] = 10;
        }
        $merged['ai_request_timeout'] = isset( $merged['ai_request_timeout'] ) ? absint( $merged['ai_request_timeout'] ) : 60;
        if ( $merged['ai_request_timeout'] < 10 ) {
            $merged['ai_request_timeout'] = 10;
        } elseif ( $merged['ai_request_timeout'] > 120 ) {
            $merged['ai_request_timeout'] = 120;
        }
        $merged['ai_request_retries'] = isset( $merged['ai_request_retries'] ) ? absint( $merged['ai_request_retries'] ) : 0;
        if ( $merged['ai_request_retries'] > 1 ) {
            $merged['ai_request_retries'] = 1;
        }
        $merged['enable_strict_json_mode'] = ( isset( $merged['enable_strict_json_mode'] ) && $merged['enable_strict_json_mode'] === '1' ) ? '1' : '0';
        $merged['enable_json_repair_retry'] = ( isset( $merged['enable_json_repair_retry'] ) && $merged['enable_json_repair_retry'] === '1' ) ? '1' : '0';
        $merged['analysis_mode'] = isset( $merged['analysis_mode'] ) ? sanitize_key( (string) $merged['analysis_mode'] ) : 'fast';
        if ( ! in_array( $merged['analysis_mode'], array( 'fast', 'compare' ), true ) ) {
            $merged['analysis_mode'] = 'fast';
        }
        $merged['compare_winner_strategy'] = isset( $merged['compare_winner_strategy'] ) ? sanitize_key( (string) $merged['compare_winner_strategy'] ) : 'default';
        if ( ! in_array( $merged['compare_winner_strategy'], array( 'default', 'highest_score', 'latest_success' ), true ) ) {
            $merged['compare_winner_strategy'] = 'default';
        }
        $merged['enable_secondary_ai_push'] = ( isset( $merged['enable_secondary_ai_push'] ) && $merged['enable_secondary_ai_push'] === '1' ) ? '1' : '0';
        $merged['secondary_provider_id'] = isset( $merged['secondary_provider_id'] ) ? sanitize_key( (string) $merged['secondary_provider_id'] ) : '';
        $merged['secondary_model_id'] = isset( $merged['secondary_model_id'] ) ? sanitize_key( (string) $merged['secondary_model_id'] ) : '';
        $merged['secondary_prompt'] = isset( $merged['secondary_prompt'] ) ? trim( (string) $merged['secondary_prompt'] ) : '';
        if ( $merged['secondary_prompt'] === '' ) {
            $merged['secondary_prompt'] = QiGeo_Install::default_secondary_prompt_template();
        }

        self::prime_settings_cache( $merged );

        return self::$settings_cache;
    }

    /**
     * @param array<string,mixed> $settings settings
     * @return void
     */
    private static function prime_settings_cache( $settings ) {
        self::$settings_cache = is_array( $settings ) ? $settings : array();
        self::$provider_map_cache = array();
        self::$model_map_cache = array();

        if ( ! empty( self::$settings_cache['providers'] ) && is_array( self::$settings_cache['providers'] ) ) {
            foreach ( self::$settings_cache['providers'] as $provider ) {
                if ( ! is_array( $provider ) || empty( $provider['id'] ) ) {
                    continue;
                }

                self::$provider_map_cache[ (string) $provider['id'] ] = $provider;
            }
        }

        if ( ! empty( self::$settings_cache['models'] ) && is_array( self::$settings_cache['models'] ) ) {
            foreach ( self::$settings_cache['models'] as $model ) {
                if ( ! is_array( $model ) || empty( $model['id'] ) ) {
                    continue;
                }

                self::$model_map_cache[ (string) $model['id'] ] = $model;
            }
        }
    }

    public static function get_defaults() {
        $seed = array(
            'enable_auto_analyze'   => '1',
            'analysis_mode'         => 'fast',
            'compare_winner_strategy' => 'default',
            'enable_secondary_ai_push' => '0',
            'secondary_provider_id' => '',
            'secondary_model_id'    => '',
            'secondary_prompt'      => QiGeo_Install::default_secondary_prompt_template(),
            'allowed_post_types'    => array( 'post' ),
            'analysis_prompt'       => QiGeo_Install::default_prompt_template(),
            'default_provider_id'   => 'openai_default',
            'default_model_id'      => 'openai_gpt4o_mini',
            'append_summary_block'  => '0',
            'append_faq_block'      => '1',
            'append_key_entities_block' => '1',
            'enable_faq_schema'     => '1',
            'enable_org_schema'     => '1',
            'enable_llms_txt'       => '1',
            'enable_crawler_radar'  => '1',
            'crawler_radar_retention_days' => 30,
            'disable_schema_on_noindex' => '1',
            'faq_max_items'         => 5,
            'enable_quality_gate'   => '1',
            'quality_gate_min_score' => 60,
            'quality_gate_min_summary_len' => 80,
            'quality_gate_min_faq_items' => 2,
            'ai_request_timeout'    => 60,
            'ai_request_retries'    => 0,
            'enable_strict_json_mode' => '1',
            'enable_json_repair_retry' => '0',
            'history_keep_count'    => 20,
            'providers'             => array(
                array(
                    'id'       => 'openai_default',
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
            'models'                => array(
                array(
                    'id'          => 'openai_gpt4o_mini',
                    'provider_id' => 'openai_default',
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

        $current = get_option( QIGEO_OPTION_KEY );
        if ( is_array( $current ) ) {
            $seed = wp_parse_args( $current, $seed );
        }

        return $seed;
    }

    public static function provider_type_options() {
        return array(
            'openai'   => '通用兼容',
            'deepseek' => 'DeepSeek 兼容',
            'qwen'     => '通义兼容',
            'doubao'   => '豆包兼容',
        );
    }

    public static function supported_platforms() {
        return array(
            array(
                'name' => 'OpenAI',
                'type' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'model_hint' => 'gpt-4o-mini / gpt-4.1 等',
            ),
            array(
                'name' => 'DeepSeek',
                'type' => 'deepseek',
                'base_url' => 'https://api.deepseek.com/v1',
                'model_hint' => 'deepseek-chat / deepseek-reasoner',
            ),
            array(
                'name' => '通义千问（兼容模式）',
                'type' => 'qwen',
                'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                'model_hint' => 'qwen-turbo / qwen-plus',
            ),
            array(
                'name' => '豆包（火山方舟兼容模式）',
                'type' => 'doubao',
                'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
                'model_hint' => '通常填接入点 ID，如 ep-xxxx（支持填 /api/v3 或完整 /chat/completions）',
            ),
        );
    }

    public static function get_provider_by_id( $provider_id ) {
        self::get_settings();
        $provider_id = (string) $provider_id;

        return isset( self::$provider_map_cache[ $provider_id ] ) ? self::$provider_map_cache[ $provider_id ] : null;
    }

    public static function get_model_by_id( $model_id ) {
        self::get_settings();
        $model_id = (string) $model_id;

        return isset( self::$model_map_cache[ $model_id ] ) ? self::$model_map_cache[ $model_id ] : null;
    }

    public static function ajax_test_provider_connection() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ), 403 );
        }

        check_ajax_referer( 'qigeo_test_provider_connection_nonce', 'nonce' );

        $provider_id = isset( $_POST['provider_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider_id'] ) ) : '';
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
        $type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( (string) $_POST['type'] ) ) : 'openai';
        $base_url = isset( $_POST['base_url'] ) ? esc_url_raw( trim( wp_unslash( (string) $_POST['base_url'] ) ) ) : '';
        $api_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) ) : '';
        $model_name = isset( $_POST['model_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['model_name'] ) ) : '';

        $saved_provider = $provider_id !== '' ? self::get_provider_by_id( $provider_id ) : null;
        if ( is_array( $saved_provider ) ) {
            if ( $name === '' && ! empty( $saved_provider['name'] ) ) {
                $name = (string) $saved_provider['name'];
            }
            if ( $type === '' && ! empty( $saved_provider['type'] ) ) {
                $type = (string) $saved_provider['type'];
            }
            if ( $base_url === '' && ! empty( $saved_provider['base_url'] ) ) {
                $base_url = (string) $saved_provider['base_url'];
            }
            if ( $api_key === '' && ! empty( $saved_provider['api_key'] ) ) {
                $api_key = (string) $saved_provider['api_key'];
            }
        }

        if ( $model_name === '' && $provider_id !== '' ) {
            $model_name = self::get_first_model_name_for_provider( $provider_id );
        }

        if ( $provider_id === '' || $base_url === '' ) {
            wp_send_json_error( array( 'message' => '请先填写平台 ID 和访问地址。' ) );
        }
        if ( $api_key === '' ) {
            wp_send_json_error( array( 'message' => '请先填写访问密钥；已保存的密钥留空时可直接测试。' ) );
        }
        if ( $model_name === '' ) {
            wp_send_json_error( array( 'message' => '请先添加一个所属平台为 ' . $provider_id . ' 的模型，并填写模型标识。' ) );
        }

        if ( ! isset( self::provider_type_options()[ $type ] ) ) {
            $type = 'openai';
        }

        $result = QiGeo_AI_Client::test_connection(
            array(
                'id' => $provider_id,
                'name' => $name !== '' ? $name : $provider_id,
                'type' => $type,
                'base_url' => untrailingslashit( $base_url ),
                'api_key' => $api_key,
                'enabled' => '1',
            ),
            array(
                'id' => 'connection_test',
                'provider_id' => $provider_id,
                'label' => '连通性测试',
                'model' => $model_name,
                'enabled' => '1',
            )
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                    'code' => $result->get_error_code(),
                )
            );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    '连通成功：HTTP %d，模型 %s，耗时 %s 秒。',
                    isset( $result['http_code'] ) ? absint( $result['http_code'] ) : 200,
                    isset( $result['model'] ) ? (string) $result['model'] : $model_name,
                    isset( $result['elapsed'] ) ? (string) $result['elapsed'] : '-'
                ),
            )
        );
    }

    private static function get_first_model_name_for_provider( $provider_id ) {
        $provider_id = sanitize_key( (string) $provider_id );
        if ( $provider_id === '' ) {
            return '';
        }

        $settings = self::get_settings();
        $models = isset( $settings['models'] ) && is_array( $settings['models'] ) ? $settings['models'] : array();
        $fallback = '';
        foreach ( $models as $model ) {
            if ( ! is_array( $model ) || empty( $model['provider_id'] ) || (string) $model['provider_id'] !== $provider_id ) {
                continue;
            }
            $model_name = ! empty( $model['model'] ) ? sanitize_text_field( (string) $model['model'] ) : '';
            if ( $model_name === '' ) {
                continue;
            }
            if ( $fallback === '' ) {
                $fallback = $model_name;
            }
            if ( ! empty( $model['enabled'] ) && $model['enabled'] === '1' ) {
                return $model_name;
            }
        }

        return $fallback;
    }

    public static function handle_form_submit() {
        if ( ! is_admin() ) {
            return;
        }

        if ( ! isset( $_POST['qigeo_save_settings'] ) && ! isset( $_POST['qigeo_rotate_worker_token'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        check_admin_referer( 'qigeo_save_settings_action', 'qigeo_settings_nonce' );

        if ( isset( $_POST['qigeo_rotate_worker_token'] ) ) {
            QiGeo_Analyzer::rotate_worker_token();
            $redirect = add_query_arg(
                array(
                    'page'          => 'qigeo-settings',
                    'token_rotated' => '1',
                ),
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        $existing = self::get_settings();
        $settings = array();

        $settings['enable_auto_analyze'] = isset( $_POST['enable_auto_analyze'] ) ? '1' : '0';
        $settings['analysis_mode'] = 'fast';
        $settings['compare_winner_strategy'] = 'default';

        $secondary_enabled = isset( $_POST['enable_secondary_ai_push'] ) ? '1' : '0';
        $secondary_provider_id = isset( $_POST['secondary_provider_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['secondary_provider_id'] ) ) : '';
        $secondary_model_id = isset( $_POST['secondary_model_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['secondary_model_id'] ) ) : '';
        $secondary_prompt = isset( $_POST['secondary_prompt'] ) ? wp_unslash( $_POST['secondary_prompt'] ) : '';
        $secondary_prompt = trim( wp_kses_post( $secondary_prompt ) );
        if ( $secondary_prompt === '' ) {
            $secondary_prompt = QiGeo_Install::default_secondary_prompt_template();
        }

        $settings['append_summary_block'] = isset( $_POST['append_summary_block'] ) ? '1' : '0';
        $settings['append_faq_block'] = isset( $_POST['append_faq_block'] ) ? '1' : '0';
        $settings['append_key_entities_block'] = isset( $_POST['append_key_entities_block'] ) ? '1' : '0';
        $settings['enable_faq_schema'] = isset( $_POST['enable_faq_schema'] ) ? '1' : '0';
        $settings['enable_org_schema'] = isset( $_POST['enable_org_schema'] ) ? '1' : '0';
        $settings['enable_llms_txt'] = isset( $_POST['enable_llms_txt'] ) ? '1' : '0';
        $settings['enable_crawler_radar'] = isset( $_POST['enable_crawler_radar'] ) ? '1' : '0';
        $radar_days = isset( $_POST['crawler_radar_retention_days'] ) ? absint( wp_unslash( (string) $_POST['crawler_radar_retention_days'] ) ) : 30;
        if ( $radar_days < 1 ) {
            $radar_days = 7;
        } elseif ( $radar_days > 180 ) {
            $radar_days = 180;
        }
        $settings['crawler_radar_retention_days'] = $radar_days;
        $settings['disable_schema_on_noindex'] = isset( $_POST['disable_schema_on_noindex'] ) ? '1' : '0';
        $settings['enable_quality_gate'] = isset( $_POST['enable_quality_gate'] ) ? '1' : '0';

        $faq_max_items = isset( $_POST['faq_max_items'] ) ? absint( wp_unslash( (string) $_POST['faq_max_items'] ) ) : 5;
        if ( $faq_max_items < 1 ) {
            $faq_max_items = 1;
        }
        if ( $faq_max_items > 20 ) {
            $faq_max_items = 20;
        }
        $settings['faq_max_items'] = $faq_max_items;

        $min_score = isset( $_POST['quality_gate_min_score'] ) ? absint( wp_unslash( (string) $_POST['quality_gate_min_score'] ) ) : 60;
        if ( $min_score > 100 ) {
            $min_score = 100;
        }
        $settings['quality_gate_min_score'] = $min_score;

        $min_summary_len = isset( $_POST['quality_gate_min_summary_len'] ) ? absint( wp_unslash( (string) $_POST['quality_gate_min_summary_len'] ) ) : 80;
        if ( $min_summary_len > 300 ) {
            $min_summary_len = 300;
        }
        $settings['quality_gate_min_summary_len'] = $min_summary_len;

        $min_faq_items = isset( $_POST['quality_gate_min_faq_items'] ) ? absint( wp_unslash( (string) $_POST['quality_gate_min_faq_items'] ) ) : 2;
        if ( $min_faq_items > 10 ) {
            $min_faq_items = 10;
        }
        $settings['quality_gate_min_faq_items'] = $min_faq_items;
        $settings['enable_strict_json_mode'] = isset( $_POST['enable_strict_json_mode'] ) ? '1' : '0';
        $settings['enable_json_repair_retry'] = isset( $_POST['enable_json_repair_retry'] ) ? '1' : '0';

        $ai_request_timeout = isset( $_POST['ai_request_timeout'] ) ? absint( wp_unslash( (string) $_POST['ai_request_timeout'] ) ) : 60;
        if ( $ai_request_timeout < 10 ) {
            $ai_request_timeout = 10;
        } elseif ( $ai_request_timeout > 120 ) {
            $ai_request_timeout = 120;
        }
        $settings['ai_request_timeout'] = $ai_request_timeout;

        $ai_request_retries = isset( $_POST['ai_request_retries'] ) ? absint( wp_unslash( (string) $_POST['ai_request_retries'] ) ) : 0;
        if ( $ai_request_retries > 1 ) {
            $ai_request_retries = 1;
        }
        $settings['ai_request_retries'] = $ai_request_retries;

        $allowed = isset( $_POST['allowed_post_types'] ) ? (array) wp_unslash( $_POST['allowed_post_types'] ) : array( 'post' );
        $allowed = array_map( 'sanitize_key', $allowed );
        $allowed = array_values( array_filter( $allowed, function( $pt ) {
            return post_type_exists( $pt ) && $pt !== 'attachment';
        } ) );
        $settings['allowed_post_types'] = ! empty( $allowed ) ? $allowed : array( 'post' );

        $prompt = isset( $_POST['analysis_prompt'] ) ? wp_unslash( $_POST['analysis_prompt'] ) : '';
        $settings['analysis_prompt'] = trim( wp_kses_post( $prompt ) );
        if ( $settings['analysis_prompt'] === '' ) {
            $settings['analysis_prompt'] = QiGeo_Install::default_prompt_template();
        }

        $keep_count = isset( $_POST['history_keep_count'] ) ? absint( wp_unslash( (string) $_POST['history_keep_count'] ) ) : 20;
        if ( $keep_count < 1 ) {
            $keep_count = 1;
        }
        if ( $keep_count > 200 ) {
            $keep_count = 200;
        }
        $settings['history_keep_count'] = $keep_count;

        $existing_provider_keys = array();
        if ( ! empty( $existing['providers'] ) && is_array( $existing['providers'] ) ) {
            foreach ( $existing['providers'] as $row ) {
                if ( empty( $row['id'] ) ) {
                    continue;
                }
                $existing_provider_keys[ $row['id'] ] = isset( $row['api_key'] ) ? (string) $row['api_key'] : '';
            }
        }

        $providers_input = isset( $_POST['providers'] ) ? (array) wp_unslash( $_POST['providers'] ) : array();
        $providers = array();
        $provider_ids = array();
        $disabled_for_missing_key = array();

        foreach ( $providers_input as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
            if ( $id === '' ) {
                $id = 'provider_' . wp_generate_password( 8, false, false );
            }
            if ( isset( $provider_ids[ $id ] ) ) {
                continue;
            }

            $name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
            $type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : 'openai';
            $base_url = isset( $row['base_url'] ) ? esc_url_raw( trim( (string) $row['base_url'] ) ) : '';
            $api_key = isset( $row['api_key'] ) ? trim( (string) $row['api_key'] ) : '';
            $enabled = isset( $row['enabled'] ) ? '1' : '0';

            if ( $api_key === '' && isset( $existing_provider_keys[ $id ] ) ) {
                $api_key = $existing_provider_keys[ $id ];
            }

            if ( $name === '' || $base_url === '' ) {
                continue;
            }

            if ( ! isset( self::provider_type_options()[ $type ] ) ) {
                $type = 'openai';
            }

            if ( $enabled === '1' && $api_key === '' ) {
                $enabled = '0';
                $disabled_for_missing_key[] = $name !== '' ? $name : $id;
            }

            $providers[] = array(
                'id'       => $id,
                'name'     => $name,
                'type'     => $type,
                'base_url' => untrailingslashit( $base_url ),
                'api_key'  => $api_key,
                'enabled'  => $enabled,
            );
            $provider_ids[ $id ] = true;
        }

        if ( empty( $providers ) ) {
            $providers = $existing['providers'];
            foreach ( $providers as $p ) {
                if ( ! empty( $p['id'] ) ) {
                    $provider_ids[ $p['id'] ] = true;
                }
            }
        }

        $models_input = isset( $_POST['models'] ) ? (array) wp_unslash( $_POST['models'] ) : array();
        $models = array();
        $model_ids = array();

        foreach ( $models_input as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
            if ( $id === '' ) {
                $id = 'model_' . wp_generate_password( 8, false, false );
            }
            if ( isset( $model_ids[ $id ] ) ) {
                continue;
            }

            $provider_id = isset( $row['provider_id'] ) ? sanitize_key( (string) $row['provider_id'] ) : '';
            $label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
            $model = isset( $row['model'] ) ? sanitize_text_field( (string) $row['model'] ) : '';
            $enabled = isset( $row['enabled'] ) ? '1' : '0';

            if ( $provider_id === '' || ! isset( $provider_ids[ $provider_id ] ) ) {
                continue;
            }
            if ( $label === '' || $model === '' ) {
                continue;
            }

            $models[] = array(
                'id'          => $id,
                'provider_id' => $provider_id,
                'label'       => $label,
                'model'       => $model,
                'enabled'     => $enabled,
            );
            $model_ids[ $id ] = true;
        }

        if ( empty( $models ) ) {
            $models = $existing['models'];
            foreach ( $models as $m ) {
                if ( ! empty( $m['id'] ) ) {
                    $model_ids[ $m['id'] ] = true;
                }
            }
        }

        $default_provider_id = isset( $_POST['default_provider_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['default_provider_id'] ) ) : '';
        if ( ! isset( $provider_ids[ $default_provider_id ] ) ) {
            $default_provider_id = ! empty( $providers[0]['id'] ) ? $providers[0]['id'] : '';
        }

        $default_model_id = isset( $_POST['default_model_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['default_model_id'] ) ) : '';
        if ( ! isset( $model_ids[ $default_model_id ] ) ) {
            $default_model_id = ! empty( $models[0]['id'] ) ? $models[0]['id'] : '';
        }

        $default_model_provider_id = '';
        foreach ( $models as $model_row ) {
            if ( ! is_array( $model_row ) || empty( $model_row['id'] ) || (string) $model_row['id'] !== $default_model_id ) {
                continue;
            }
            $default_model_provider_id = isset( $model_row['provider_id'] ) ? (string) $model_row['provider_id'] : '';
            break;
        }
        if ( $default_provider_id !== '' && $default_model_provider_id !== $default_provider_id ) {
            $default_model_id = '';
            foreach ( $models as $model_row ) {
                if ( ! is_array( $model_row ) || empty( $model_row['id'] ) ) {
                    continue;
                }
                if ( isset( $model_row['provider_id'] ) && (string) $model_row['provider_id'] === $default_provider_id ) {
                    $default_model_id = (string) $model_row['id'];
                    break;
                }
            }
        }

        if ( $secondary_provider_id !== '' && ! isset( $provider_ids[ $secondary_provider_id ] ) ) {
            $secondary_provider_id = '';
        }

        $secondary_model_provider_id = '';
        if ( $secondary_model_id !== '' && isset( $model_ids[ $secondary_model_id ] ) ) {
            foreach ( $models as $model_row ) {
                if ( ! is_array( $model_row ) || empty( $model_row['id'] ) || (string) $model_row['id'] !== $secondary_model_id ) {
                    continue;
                }
                $secondary_model_provider_id = isset( $model_row['provider_id'] ) ? (string) $model_row['provider_id'] : '';
                break;
            }
        } else {
            $secondary_model_id = '';
        }

        if ( $secondary_provider_id !== '' && $secondary_model_provider_id !== '' && $secondary_model_provider_id !== $secondary_provider_id ) {
            $secondary_model_id = '';
        }

        if ( $secondary_enabled === '1' && ( $secondary_provider_id === '' || $secondary_model_id === '' ) ) {
            $secondary_enabled = '0';
        }

        $settings['default_provider_id'] = $default_provider_id;
        $settings['default_model_id'] = $default_model_id;
        $settings['enable_secondary_ai_push'] = $secondary_enabled;
        $settings['secondary_provider_id'] = $secondary_provider_id;
        $settings['secondary_model_id'] = $secondary_model_id;
        $settings['secondary_prompt'] = $secondary_prompt;
        $settings['providers'] = $providers;
        $settings['models'] = $models;

        update_option( QIGEO_OPTION_KEY, $settings, false );
        self::prime_settings_cache( $settings );
        if ( class_exists( 'QiGeo_LLMs_Txt' ) ) {
            QiGeo_LLMs_Txt::purge_cache();
        }

        $redirect_args = array(
            'page' => 'qigeo-settings',
            'updated' => '1',
        );
        if ( ! empty( $disabled_for_missing_key ) ) {
            $redirect_args['provider_key_warning'] = (string) count( $disabled_for_missing_key );
        }

        $redirect = add_query_arg( $redirect_args, admin_url( 'admin.php' ) );

        wp_safe_redirect( $redirect );
        exit;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = self::get_settings();
        $provider_types = self::provider_type_options();
        $supported_platforms = self::supported_platforms();
        $all_post_types = get_post_types( array( 'public' => true ), 'objects' );
        if ( isset( $all_post_types['attachment'] ) ) {
            unset( $all_post_types['attachment'] );
        }
        $provider_saved_key_count = 0;
        $provider_missing_key_count = 0;
        $provider_saved_key_names = array();
        $provider_missing_key_names = array();
        foreach ( $settings['providers'] as $provider_row ) {
            $provider_name = ! empty( $provider_row['name'] ) ? (string) $provider_row['name'] : ( ! empty( $provider_row['id'] ) ? (string) $provider_row['id'] : '未命名平台' );
            if ( ! empty( $provider_row['api_key'] ) ) {
                $provider_saved_key_count++;
                $provider_saved_key_names[] = $provider_name;
            } else {
                $provider_missing_key_count++;
                $provider_missing_key_names[] = $provider_name;
            }
        }
        $external_trigger_url = QiGeo_Analyzer::get_external_trigger_url( false );
        $external_trigger_url_compat = QiGeo_Analyzer::get_external_trigger_url( true );
        $external_trigger_token = QiGeo_Analyzer::get_worker_token_for_admin();
        $wp_cron_disabled = QiGeo_Analyzer::is_wp_cron_disabled_public();
        ?>
        <div class="wrap qigeo-wrap">
            <h1>启灵GEO</h1>
            <?php
            $updated_flag = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( (string) $_GET['updated'] ) ) : '';
            $token_rotated_flag = isset( $_GET['token_rotated'] ) ? sanitize_key( wp_unslash( (string) $_GET['token_rotated'] ) ) : '';
            ?>
            <?php if ( $updated_flag === '1' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( '设置已保存。', 'qilinggeo' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $token_rotated_flag === '1' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( '外部触发 Token 已重新生成，请同步更新你的监控/任务系统。', 'qilinggeo' ); ?></p></div>
            <?php endif; ?>
            <?php
            $provider_key_warning_count = isset( $_GET['provider_key_warning'] ) ? absint( wp_unslash( (string) $_GET['provider_key_warning'] ) ) : 0;
            if ( $provider_key_warning_count > 0 ) :
            ?>
                <div class="notice notice-warning is-dismissible"><p><?php echo esc_html( sprintf( '有 %d 个平台尚未填写访问密钥，当前已暂停使用。', $provider_key_warning_count ) ); ?></p></div>
            <?php endif; ?>
            <div class="qigeo-card">
                <h2>任务触发地址</h2>
                <p class="description">用于手动或定时处理待执行任务。</p>
                <?php if ( $wp_cron_disabled ) : ?>
                    <p class="description"><strong>当前站点未启用定时任务。</strong> 建议使用下方地址保持任务运行。</p>
                <?php endif; ?>
                <div class="qigeo-trigger-copy-row">
                    <input type="text" id="qigeo-external-trigger-url" readonly="readonly" class="large-text code" value="<?php echo esc_attr( $external_trigger_url ); ?>" onclick="this.select();" />
                    <button type="button" class="button qigeo-copy-btn" id="qigeo-copy-trigger-url" data-copy-target="qigeo-external-trigger-url" data-feedback-target="qigeo-copy-trigger-feedback">复制地址</button>
                </div>
                <p class="description qigeo-copy-feedback" id="qigeo-copy-trigger-feedback" aria-live="polite"></p>
                <div class="qigeo-trigger-copy-row">
                    <input type="text" id="qigeo-external-trigger-token" readonly="readonly" class="large-text code" value="<?php echo esc_attr( $external_trigger_token ); ?>" onclick="this.select();" />
                    <button type="button" class="button qigeo-copy-btn" id="qigeo-copy-trigger-token" data-copy-target="qigeo-external-trigger-token" data-feedback-target="qigeo-copy-trigger-token-feedback">复制密钥</button>
                </div>
                <p class="description qigeo-copy-feedback" id="qigeo-copy-trigger-token-feedback" aria-live="polite"></p>
                <p class="description">推荐方式：外部任务请求时把密钥放到 <code>X-QiGeo-Token</code> 请求头。</p>
                <details class="qigeo-trigger-compat">
                    <summary>兼容地址：平台不支持请求头时使用</summary>
                    <p class="description">这个地址会把密钥带在 URL 里，只建议用于只能填写链接的定时任务平台。链接可能出现在访问日志里，如怀疑泄露请立即重新生成密钥。</p>
                    <div class="qigeo-trigger-copy-row">
                        <input type="text" id="qigeo-external-trigger-url-compat" readonly="readonly" class="large-text code" value="<?php echo esc_attr( $external_trigger_url_compat ); ?>" onclick="this.select();" />
                        <button type="button" class="button qigeo-copy-btn" id="qigeo-copy-trigger-url-compat" data-copy-target="qigeo-external-trigger-url-compat" data-feedback-target="qigeo-copy-trigger-compat-feedback">复制兼容地址</button>
                    </div>
                    <p class="description qigeo-copy-feedback" id="qigeo-copy-trigger-compat-feedback" aria-live="polite"></p>
                </details>
                <form method="post" action="" class="qigeo-token-rotate-form">
                    <?php wp_nonce_field( 'qigeo_save_settings_action', 'qigeo_settings_nonce' ); ?>
                    <button type="submit" class="button button-secondary" name="qigeo_rotate_worker_token" value="1" onclick="return confirm('确认重新生成密钥？旧密钥会立即失效。');">重新生成密钥</button>
                </form>
                <p class="description">如怀疑密钥泄露，请立即重新生成。</p>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field( 'qigeo_save_settings_action', 'qigeo_settings_nonce' ); ?>

                <div class="qigeo-card">
                    <h2>基础设置</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '发布后自动分析', array( 'recommended' ) ) ); ?></th>
                            <td><label><input type="checkbox" name="enable_auto_analyze" value="1" <?php checked( $settings['enable_auto_analyze'], '1' ); ?> /> 启用</label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '第一阶段分析方式', array( 'key' ) ) ); ?></th>
                            <td>
                                <strong>单平台分析</strong>
                                <p class="description">文章编辑页只会把当前文章发送给下面选择的第一阶段分析平台/模型。第三阶段A多模型分析放在 FAQ 库里执行。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '后续阶段执行方式', array( 'key' ) ) ); ?></th>
                            <td>
                                <strong>FAQ 库手动选择</strong>
                                <p class="description">第二阶段生成并保存 FAQ 后，到 FAQ 库执行第三阶段A多模型分析、第三阶段B指定平台投喂，或第四阶段人工复制投喂。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '允许分析的文章类型', array( 'key' ) ) ); ?></th>
                            <td>
                                <?php foreach ( $all_post_types as $post_type ) : ?>
                                    <label class="qigeo-inline-check">
                                        <input type="checkbox" name="allowed_post_types[]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, (array) $settings['allowed_post_types'], true ) ); ?> />
                                        <?php echo esc_html( $post_type->labels->singular_name ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '第一阶段分析平台', array( 'recommended' ) ) ); ?></th>
                            <td>
                                <select name="default_provider_id" id="qigeo-default-provider">
                                    <?php foreach ( $settings['providers'] as $provider ) : ?>
                                        <option value="<?php echo esc_attr( $provider['id'] ); ?>" <?php selected( $settings['default_provider_id'], $provider['id'] ); ?>>
                                            <?php echo esc_html( $provider['name'] . ' (' . $provider['id'] . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">文章编辑页“开始 GEO 分析”只会使用这个平台。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '第一阶段分析模型', array( 'recommended' ) ) ); ?></th>
                            <td>
                                <select name="default_model_id" id="qigeo-default-model">
                                    <?php foreach ( $settings['models'] as $model ) : ?>
                                        <option value="<?php echo esc_attr( $model['id'] ); ?>" <?php selected( $settings['default_model_id'], $model['id'] ); ?>>
                                            <?php echo esc_html( $model['label'] . ' / ' . $model['model'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">建议选择上面平台对应的模型；保存时会自动修正不匹配的模型。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">保留分析历史条数</th>
                            <td><input type="number" name="history_keep_count" min="1" max="200" value="<?php echo esc_attr( (int) $settings['history_keep_count'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">显示摘要</th>
                            <td><label><input type="checkbox" name="append_summary_block" value="1" <?php checked( $settings['append_summary_block'], '1' ); ?> /> 在前台内容后显示摘要</label></td>
                        </tr>
                        <tr>
                            <th scope="row">显示要点</th>
                            <td><label><input type="checkbox" name="append_key_entities_block" value="1" <?php checked( isset( $settings['append_key_entities_block'] ) ? $settings['append_key_entities_block'] : '1', '1' ); ?> /> 在前台显示核心要点</label></td>
                        </tr>
                        <tr>
                            <th scope="row">输出问答结构</th>
                            <td><label><input type="checkbox" name="enable_faq_schema" value="1" <?php checked( $settings['enable_faq_schema'], '1' ); ?> /> 在文章页补充问答信息 (FAQPage Schema)</label></td>
                        </tr>
                        <tr>
                            <th scope="row">全局品牌实体 Schema</th>
                            <td><label><input type="checkbox" name="enable_org_schema" value="1" <?php checked( isset( $settings['enable_org_schema'] ) ? $settings['enable_org_schema'] : '1', '1' ); ?> /> 启用 Organization + sameAs 品牌实体结构化数据（供 AI 建立全网知识图谱）</label></td>
                        </tr>
                        <tr>
                            <th scope="row">启用 /llms.txt</th>
                            <td><label><input type="checkbox" name="enable_llms_txt" value="1" <?php checked( isset( $settings['enable_llms_txt'] ) ? $settings['enable_llms_txt'] : '1', '1' ); ?> /> 自动生成 /llms.txt 与 /llms-full.txt（AI 搜索爬虫专属纯文本 Markdown 知识索引，在 robots.txt 中自动指引）</label></td>
                        </tr>
                        <tr>
                            <th scope="row">AI 爬虫探测雷达</th>
                            <td>
                                <label><input type="checkbox" name="enable_crawler_radar" value="1" <?php checked( isset( $settings['enable_crawler_radar'] ) ? $settings['enable_crawler_radar'] : '1', '1' ); ?> /> 启用 AI 爬虫探测雷达（识别 ByteSpider、GPTBot、ClaudeBot、Perplexity 等来访足迹）</label>
                                <p class="description" style="margin-top:6px;">日志自动保留天数：<input type="number" name="crawler_radar_retention_days" min="7" max="180" style="width:70px;" value="<?php echo esc_attr( (string) ( isset( $settings['crawler_radar_retention_days'] ) ? absint( $settings['crawler_radar_retention_days'] ) : 30 ) ); ?>" /> 天（自动清理过期流水，防止占用数据库）。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">隐藏页不输出结构</th>
                            <td><label><input type="checkbox" name="disable_schema_on_noindex" value="1" <?php checked( isset( $settings['disable_schema_on_noindex'] ) ? $settings['disable_schema_on_noindex'] : '1', '1' ); ?> /> 隐藏页不补充问答和文章信息</label></td>
                        </tr>
                        <tr>
                            <th scope="row">显示 FAQ</th>
                            <td><label><input type="checkbox" name="append_faq_block" value="1" <?php checked( isset( $settings['append_faq_block'] ) ? $settings['append_faq_block'] : '1', '1' ); ?> /> 在文章正文后显示 FAQ</label></td>
                        </tr>
                        <tr>
                            <th scope="row">FAQ 最大条数</th>
                            <td>
                                <input type="number" name="faq_max_items" min="1" max="20" value="<?php echo esc_attr( (string) ( isset( $settings['faq_max_items'] ) ? absint( $settings['faq_max_items'] ) : 5 ) ); ?>" />
                                <p class="description">建议保留 3-8 条。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '启用内容筛选', array( 'recommended' ) ) ); ?></th>
                            <td>
                                <label><input type="checkbox" name="enable_quality_gate" value="1" <?php checked( isset( $settings['enable_quality_gate'] ) ? $settings['enable_quality_gate'] : '1', '1' ); ?> /> 未达标内容不在前台显示</label>
                                <p class="description">建议保持开启。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">质量闸门最低评分</th>
                            <td><input type="number" name="quality_gate_min_score" min="0" max="100" value="<?php echo esc_attr( (string) ( isset( $settings['quality_gate_min_score'] ) ? absint( $settings['quality_gate_min_score'] ) : 60 ) ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">质量闸门摘要最少字数</th>
                            <td><input type="number" name="quality_gate_min_summary_len" min="0" max="300" value="<?php echo esc_attr( (string) ( isset( $settings['quality_gate_min_summary_len'] ) ? absint( $settings['quality_gate_min_summary_len'] ) : 80 ) ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">质量闸门 FAQ 最少条数</th>
                            <td><input type="number" name="quality_gate_min_faq_items" min="0" max="10" value="<?php echo esc_attr( (string) ( isset( $settings['quality_gate_min_faq_items'] ) ? absint( $settings['quality_gate_min_faq_items'] ) : 2 ) ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '生成说明', array( 'key' ) ) ); ?></th>
                            <td>
                                <textarea name="analysis_prompt" rows="10" class="large-text code"><?php echo esc_textarea( $settings['analysis_prompt'] ); ?></textarea>
                                <p class="description">这里填写你的生成要求。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">第三阶段A多模型分析说明</th>
                            <td>
                                <textarea name="secondary_prompt" rows="8" class="large-text code"><?php echo esc_textarea( isset( $settings['secondary_prompt'] ) ? $settings['secondary_prompt'] : QiGeo_Install::default_secondary_prompt_template() ); ?></textarea>
                                <p class="description">用于 FAQ 库第三阶段A：把整理好的成品资料发给所选模型，查看各模型分别给出的分析和可引用性。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '请求超时（秒）', array( 'recommended' ) ) ); ?></th>
                            <td>
                                <input type="number" name="ai_request_timeout" min="10" max="120" value="<?php echo esc_attr( (string) ( isset( $settings['ai_request_timeout'] ) ? absint( $settings['ai_request_timeout'] ) : 60 ) ); ?>" />
                                <p class="description">建议 45-60 秒。为避免后台卡死，文章分析不再强制等待 180 秒；平台测试固定使用 20 秒。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">重试次数</th>
                            <td>
                                <input type="number" name="ai_request_retries" min="0" max="1" value="<?php echo esc_attr( (string) ( isset( $settings['ai_request_retries'] ) ? absint( $settings['ai_request_retries'] ) : 0 ) ); ?>" />
                                <p class="description">建议 0。重试会成倍拉长等待时间，仅在平台偶发 429/5xx 时开启 1 次。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">固定输出</th>
                            <td><label><input type="checkbox" name="enable_strict_json_mode" value="1" <?php checked( isset( $settings['enable_strict_json_mode'] ) ? $settings['enable_strict_json_mode'] : '1', '1' ); ?> /> 优先使用固定输出</label></td>
                        </tr>
                        <tr>
                            <th scope="row">自动重试修正</th>
                            <td><label><input type="checkbox" name="enable_json_repair_retry" value="1" <?php checked( isset( $settings['enable_json_repair_retry'] ) ? $settings['enable_json_repair_retry'] : '0', '1' ); ?> /> 首次失败后自动再试一次</label></td>
                        </tr>
                    </table>
                </div>

                <div class="qigeo-card">
                    <div class="qigeo-card-header">
                        <h2>平台设置</h2>
                        <button type="button" class="button" id="qigeo-add-provider">添加平台</button>
                    </div>
                    <p class="description">请填写平台名称、访问地址和访问密钥。</p>
                    <p class="qigeo-key-summary">
                        <span class="qigeo-key-pill qigeo-key-pill--saved">已设置密钥：<?php echo esc_html( (string) $provider_saved_key_count ); ?></span>
                        <span class="qigeo-key-pill qigeo-key-pill--missing">未设置密钥：<?php echo esc_html( (string) $provider_missing_key_count ); ?></span>
                    </p>
                    <p class="qigeo-key-summary-detail">
                        <?php if ( ! empty( $provider_saved_key_names ) ) : ?>
                            <span class="qigeo-key-summary-line qigeo-key-summary-line--saved">已设置：<?php echo esc_html( implode( '、', $provider_saved_key_names ) ); ?></span>
                        <?php else : ?>
                            <span class="qigeo-key-summary-line qigeo-key-summary-line--missing">已设置：无</span>
                        <?php endif; ?>
                        <?php if ( ! empty( $provider_missing_key_names ) ) : ?>
                            <span class="qigeo-key-summary-line qigeo-key-summary-line--missing">未设置：<?php echo esc_html( implode( '、', $provider_missing_key_names ) ); ?></span>
                        <?php endif; ?>
                    </p>
                    <table class="widefat striped" id="qigeo-provider-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>名称</th>
                                <th><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '平台类型', array( 'recommended' ) ) ); ?></th>
                                <th><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '访问地址', array( 'key' ) ) ); ?></th>
                                <th><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '访问密钥', array( 'required' ) ) ); ?></th>
                                <th>启用</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $settings['providers'] as $index => $provider ) : ?>
                            <?php
                            $provider_type_current = ( isset( $provider['type'] ) && isset( $provider_types[ $provider['type'] ] ) ) ? $provider['type'] : 'openai';
                            $api_placeholder = ! empty( $provider['api_key'] ) ? '已保存（留空不改）' : '请输入访问密钥';
                            $api_key_saved = ! empty( $provider['api_key'] );
                            $row_class = $api_key_saved ? 'qigeo-provider-row--saved' : 'qigeo-provider-row--missing';
                            ?>
                            <tr class="<?php echo esc_attr( $row_class ); ?>">
                                <td>
                                    <input type="text" name="providers[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $provider['id'] ); ?>" />
                                </td>
                                <td><input type="text" name="providers[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $provider['name'] ); ?>" /></td>
                                <td>
                                    <select name="providers[<?php echo esc_attr( $index ); ?>][type]">
                                        <?php foreach ( $provider_types as $type_key => $type_label ) : ?>
                                            <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $provider_type_current, $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="url" class="regular-text" name="providers[<?php echo esc_attr( $index ); ?>][base_url]" value="<?php echo esc_attr( $provider['base_url'] ); ?>" /></td>
                                <td>
                                    <input type="password" class="regular-text qigeo-api-key-input <?php echo $api_key_saved ? 'qigeo-api-key-input--saved' : 'qigeo-api-key-input--missing'; ?>" name="providers[<?php echo esc_attr( $index ); ?>][api_key]" value="" placeholder="<?php echo esc_attr( $api_placeholder ); ?>" autocomplete="off" />
                                    <?php if ( $api_key_saved ) : ?>
                                        <div class="qigeo-key-status qigeo-key-status--saved">已设置访问密钥（出于安全原因不显示）</div>
                                    <?php else : ?>
                                        <div class="qigeo-key-status qigeo-key-status--missing">未设置访问密钥（启用时必填）</div>
                                    <?php endif; ?>
                                </td>
                                <td class="qigeo-checkbox-cell"><input type="checkbox" name="providers[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $provider['enabled'], '1' ); ?> /></td>
                                <td>
                                    <button type="button" class="button qigeo-test-provider">测试</button>
                                    <button type="button" class="button-link-delete qigeo-remove-row" style="margin-left:8px;">删除</button>
                                    <div class="qigeo-provider-test-result" style="margin-top:6px;color:#646970;"></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="qigeo-card">
                    <div class="qigeo-card-header">
                        <h2>模型设置</h2>
                        <button type="button" class="button" id="qigeo-add-model">添加模型</button>
                    </div>
                    <table class="widefat striped" id="qigeo-model-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '所属平台', array( 'required' ) ) ); ?></th>
                                <th>名称</th>
                                <th><?php echo wp_kses_post( QiGeo_Admin_Helper::get_row_label_html( '模型标识', array( 'required' ) ) ); ?></th>
                                <th>启用</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $settings['models'] as $index => $model ) : ?>
                            <tr>
                                <td><input type="text" name="models[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $model['id'] ); ?>" /></td>
                                <td><input type="text" name="models[<?php echo esc_attr( $index ); ?>][provider_id]" value="<?php echo esc_attr( $model['provider_id'] ); ?>" /></td>
                                <td><input type="text" name="models[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $model['label'] ); ?>" /></td>
                                <td><input type="text" name="models[<?php echo esc_attr( $index ); ?>][model]" value="<?php echo esc_attr( $model['model'] ); ?>" /></td>
                                <td class="qigeo-checkbox-cell"><input type="checkbox" name="models[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $model['enabled'], '1' ); ?> /></td>
                                <td><button type="button" class="button-link-delete qigeo-remove-row">删除</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary" name="qigeo_save_settings" value="1">保存设置</button>
                </p>
            </form>

            <script type="text/template" id="qigeo-provider-row-template">
                <tr class="qigeo-provider-row--missing">
                    <td><input type="text" name="providers[__INDEX__][id]" value="" placeholder="例如：doubao" /></td>
                    <td><input type="text" name="providers[__INDEX__][name]" value="" /></td>
                    <td>
                        <select name="providers[__INDEX__][type]">
                            <?php foreach ( $provider_types as $type_key => $type_label ) : ?>
                                <option value="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="url" class="regular-text" name="providers[__INDEX__][base_url]" value="" /></td>
                    <td>
                        <input type="password" class="regular-text qigeo-api-key-input qigeo-api-key-input--missing" name="providers[__INDEX__][api_key]" value="" placeholder="请输入访问密钥" autocomplete="off" />
                        <div class="qigeo-key-status qigeo-key-status--missing">未设置访问密钥（启用时必填）</div>
                    </td>
                    <td class="qigeo-checkbox-cell"><input type="checkbox" name="providers[__INDEX__][enabled]" value="1" checked="checked" /></td>
                    <td>
                        <button type="button" class="button qigeo-test-provider">测试</button>
                        <button type="button" class="button-link-delete qigeo-remove-row" style="margin-left:8px;">删除</button>
                        <div class="qigeo-provider-test-result" style="margin-top:6px;color:#646970;"></div>
                    </td>
                </tr>
            </script>

            <script type="text/template" id="qigeo-model-row-template">
                <tr>
                    <td><input type="text" name="models[__INDEX__][id]" value="" placeholder="例如：doubao_chat" /></td>
                    <td><input type="text" name="models[__INDEX__][provider_id]" value="" /></td>
                    <td><input type="text" name="models[__INDEX__][label]" value="" /></td>
                    <td><input type="text" name="models[__INDEX__][model]" value="" /></td>
                    <td class="qigeo-checkbox-cell"><input type="checkbox" name="models[__INDEX__][enabled]" value="1" checked="checked" /></td>
                    <td><button type="button" class="button-link-delete qigeo-remove-row">删除</button></td>
                </tr>
            </script>
        </div>
        <?php
    }
}
