<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_AI_Client {

    public static function analyze_post( $provider, $model, $prompt, $article_payload, $runtime_override = array() ) {
        $runtime = self::get_runtime_options( $runtime_override );
        $endpoint = self::build_endpoint( isset( $provider['base_url'] ) ? $provider['base_url'] : '' );
        $api_key = isset( $provider['api_key'] ) ? trim( (string) $provider['api_key'] ) : '';

        if ( $endpoint === '' || $api_key === '' ) {
            return new WP_Error( 'qigeo_provider_invalid', '平台设置不完整，请检查访问地址和访问密钥。' );
        }

        $model_name = isset( $model['model'] ) ? trim( (string) $model['model'] ) : '';
        if ( $model_name === '' ) {
            return new WP_Error( 'qigeo_model_invalid', '模型配置不完整' );
        }

        $messages = array(
            array(
                'role' => 'system',
                'content' => (string) $prompt,
            ),
            array(
                'role' => 'user',
                'content' => wp_json_encode( $article_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            ),
        );

        $request_body = array(
            'model'       => $model_name,
            'messages'    => $messages,
            'temperature' => 0.2,
        );
        if ( ! empty( $runtime['enable_strict_json_mode'] ) ) {
            $request_body['response_format'] = array( 'type' => 'json_object' );
        }

        $response_packet = self::request_with_retry( $endpoint, $api_key, $request_body, $runtime );
        if ( is_wp_error( $response_packet ) ) {
            return $response_packet;
        }

        $json = isset( $response_packet['json'] ) && is_array( $response_packet['json'] ) ? $response_packet['json'] : array();
        $content = self::extract_message_content( $json );
        if ( $content === '' && isset( $json['choices'][0]['message']['parsed'] ) && is_array( $json['choices'][0]['message']['parsed'] ) ) {
            return array(
                'raw'      => wp_json_encode( $json['choices'][0]['message']['parsed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'parsed'   => $json['choices'][0]['message']['parsed'],
                'response' => $json,
                'trace_id' => isset( $response_packet['trace_id'] ) ? (string) $response_packet['trace_id'] : '',
            );
        }

        if ( $content === '' ) {
            return new WP_Error(
                'qigeo_response_empty',
                '返回内容为空，请稍后重试。',
                array(
                    'body'     => $json,
                    'trace_id' => isset( $response_packet['trace_id'] ) ? (string) $response_packet['trace_id'] : '',
                )
            );
        }

        $parsed = self::extract_json( $content );
        if ( ! is_array( $parsed ) && ! empty( $runtime['enable_json_repair_retry'] ) ) {
            $parsed = self::repair_json_with_model( $endpoint, $api_key, $model_name, $content, $runtime );
        }

        if ( ! is_array( $parsed ) ) {
            return new WP_Error(
                'qigeo_json_parse_failed',
                '返回内容格式不正确，请稍后重试。',
                array(
                    'raw'      => $content,
                    'trace_id' => isset( $response_packet['trace_id'] ) ? (string) $response_packet['trace_id'] : '',
                )
            );
        }

        return array(
            'raw'      => $content,
            'parsed'   => $parsed,
            'response' => $json,
            'trace_id' => isset( $response_packet['trace_id'] ) ? (string) $response_packet['trace_id'] : '',
        );
    }

    /**
     * 测试 OpenAI 兼容平台连通性。
     *
     * @param array<string,mixed> $provider provider
     * @param array<string,mixed> $model model
     * @return array<string,mixed>|WP_Error
     */
    public static function test_connection( $provider, $model ) {
        $endpoint = self::build_endpoint( isset( $provider['base_url'] ) ? $provider['base_url'] : '' );
        $api_key = isset( $provider['api_key'] ) ? trim( (string) $provider['api_key'] ) : '';
        if ( $endpoint === '' || $api_key === '' ) {
            return new WP_Error( 'qigeo_provider_invalid', '平台设置不完整，请检查访问地址和访问密钥。' );
        }

        $model_name = isset( $model['model'] ) ? trim( (string) $model['model'] ) : '';
        if ( $model_name === '' ) {
            return new WP_Error( 'qigeo_model_invalid', '请先填写一个属于该平台的模型标识。' );
        }

        $runtime = self::get_runtime_options(
            array(
                'timeout' => 20,
                'retries' => 0,
                'enable_strict_json_mode' => false,
                'enable_json_repair_retry' => false,
            )
        );
        $started = microtime( true );
        $packet = self::request_with_retry(
            $endpoint,
            $api_key,
            array(
                'model' => $model_name,
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are a connection test endpoint. Reply briefly.',
                    ),
                    array(
                        'role' => 'user',
                        'content' => 'ping',
                    ),
                ),
                'temperature' => 0,
                'max_tokens' => 16,
            ),
            $runtime
        );
        if ( is_wp_error( $packet ) ) {
            return $packet;
        }

        return array(
            'success'   => true,
            'http_code' => isset( $packet['http_code'] ) ? absint( $packet['http_code'] ) : 0,
            'endpoint'  => $endpoint,
            'model'     => $model_name,
            'elapsed'   => round( microtime( true ) - $started, 2 ),
            'trace_id'  => isset( $packet['trace_id'] ) ? (string) $packet['trace_id'] : '',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_interactive_runtime( $settings = null ) {
        if ( ! is_array( $settings ) ) {
            $settings = QiGeo_Settings::get_settings();
        }

        $timeout = isset( $settings['ai_request_timeout'] ) ? absint( $settings['ai_request_timeout'] ) : 25;
        if ( $timeout < 10 ) {
            $timeout = 10;
        } elseif ( $timeout > 30 ) {
            $timeout = 30;
        }

        return array(
            'timeout' => $timeout,
            'retries' => 0,
            'enable_strict_json_mode' => ! empty( $settings['enable_strict_json_mode'] ) && $settings['enable_strict_json_mode'] === '1',
            'enable_json_repair_retry' => false,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_runtime_options( $runtime_override = array() ) {
        $settings = QiGeo_Settings::get_settings();
        $timeout = isset( $settings['ai_request_timeout'] ) ? absint( $settings['ai_request_timeout'] ) : 60;
        if ( $timeout < 10 ) {
            $timeout = 10;
        } elseif ( $timeout > 120 ) {
            $timeout = 120;
        }

        $retries = isset( $settings['ai_request_retries'] ) ? absint( $settings['ai_request_retries'] ) : 0;
        if ( $retries > 1 ) {
            $retries = 1;
        }

        $runtime = array(
            'timeout'                  => $timeout,
            'retries'                  => $retries,
            'enable_strict_json_mode'  => ! empty( $settings['enable_strict_json_mode'] ) && $settings['enable_strict_json_mode'] === '1',
            'enable_json_repair_retry' => ! empty( $settings['enable_json_repair_retry'] ) && $settings['enable_json_repair_retry'] === '1',
            'trace_id'                 => self::generate_trace_id(),
        );

        if ( is_array( $runtime_override ) && ! empty( $runtime_override ) ) {
            if ( isset( $runtime_override['trace_id'] ) && trim( (string) $runtime_override['trace_id'] ) !== '' ) {
                $runtime['trace_id'] = sanitize_text_field( (string) $runtime_override['trace_id'] );
            }
            if ( isset( $runtime_override['timeout'] ) ) {
                $override_timeout = absint( $runtime_override['timeout'] );
                if ( $override_timeout < 3 ) {
                    $override_timeout = 3;
                } elseif ( $override_timeout > 120 ) {
                    $override_timeout = 120;
                }
                $runtime['timeout'] = $override_timeout;
            }

            if ( isset( $runtime_override['retries'] ) ) {
                $override_retries = absint( $runtime_override['retries'] );
                if ( $override_retries > 1 ) {
                    $override_retries = 1;
                }
                $runtime['retries'] = $override_retries;
            }

            if ( isset( $runtime_override['enable_strict_json_mode'] ) ) {
                $runtime['enable_strict_json_mode'] = ! empty( $runtime_override['enable_strict_json_mode'] );
            }

            if ( isset( $runtime_override['enable_json_repair_retry'] ) ) {
                $runtime['enable_json_repair_retry'] = ! empty( $runtime_override['enable_json_repair_retry'] );
            }
        }

        return $runtime;
    }

    /**
     * 生成全链路唯一 Trace-ID
     *
     * @return string
     */
    public static function generate_trace_id() {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return 'qigeo_' . str_replace( '-', '', wp_generate_uuid4() );
        }
        return 'qigeo_' . bin2hex( random_bytes( 8 ) ) . '_' . time();
    }

    /**
     * @param string              $endpoint endpoint
     * @param string              $api_key api key
     * @param array<string,mixed> $request_body request body
     * @param array<string,mixed> $runtime runtime options
     * @return array<string,mixed>|WP_Error
     */
    private static function request_with_retry( $endpoint, $api_key, $request_body, $runtime ) {
        $trace_id = ! empty( $runtime['trace_id'] ) ? (string) $runtime['trace_id'] : self::generate_trace_id();
        $max_retries = isset( $runtime['retries'] ) ? absint( $runtime['retries'] ) : 2;
        $timeout = isset( $runtime['timeout'] ) ? absint( $runtime['timeout'] ) : 60;
        $request_body_local = $request_body;
        $response_format_fallback_used = false;

        for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
            $args = array(
                'timeout' => $timeout,
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'X-Request-Id'  => $trace_id,
                    'X-Trace-Id'    => $trace_id,
                ),
                'body' => wp_json_encode( $request_body_local, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            );

            $response = wp_remote_post( $endpoint, $args );
            if ( is_wp_error( $response ) ) {
                if ( $attempt < $max_retries && self::is_retryable_wp_error( $response ) ) {
                    self::sleep_for_retry( $attempt );
                    continue;
                }
                return self::format_transport_error( $response, $endpoint, $timeout, $attempt + 1, $trace_id );
            }

            $http_code = (int) wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $json = json_decode( $body, true );

            if ( $http_code >= 200 && $http_code < 300 ) {
                if ( ! is_array( $json ) ) {
                    return new WP_Error(
                        'qigeo_response_invalid',
                        '服务返回内容格式不正确，请稍后重试。',
                        array(
                            'body'     => $body,
                            'trace_id' => $trace_id,
                        )
                    );
                }
                return array(
                    'http_code' => $http_code,
                    'body'      => $body,
                    'json'      => $json,
                    'trace_id'  => $trace_id,
                );
            }

            $message = '请求失败，请稍后重试。';
            if ( is_array( $json ) && isset( $json['error']['message'] ) ) {
                $message = (string) $json['error']['message'];
            }

            if (
                ! $response_format_fallback_used
                && isset( $request_body_local['response_format'] )
                && $http_code >= 400
                && $http_code < 500
                && stripos( $message, 'response_format' ) !== false
            ) {
                unset( $request_body_local['response_format'] );
                $response_format_fallback_used = true;
                if ( $attempt > 0 ) {
                    $attempt--;
                }
                continue;
            }

            if ( $attempt < $max_retries && self::is_retryable_http_code( $http_code ) ) {
                self::sleep_for_retry( $attempt );
                continue;
            }

            return new WP_Error(
                'qigeo_http_error',
                $message,
                array(
                    'code'     => $http_code,
                    'body'     => $body,
                    'trace_id' => $trace_id,
                )
            );
        }

        return new WP_Error(
            'qigeo_http_error',
            '请求失败，请稍后重试。',
            array( 'trace_id' => $trace_id )
        );
    }

    /**
     * @param WP_Error $error error
     * @param string   $endpoint endpoint
     * @param int      $timeout timeout
     * @param int      $attempts attempts
     * @param string   $trace_id trace id
     * @return WP_Error
     */
    private static function format_transport_error( $error, $endpoint, $timeout, $attempts, $trace_id = '' ) {
        $message = $error instanceof WP_Error ? (string) $error->get_error_message() : '';
        $code = $error instanceof WP_Error ? (string) $error->get_error_code() : 'qigeo_http_transport_error';
        $is_timeout = self::is_timeout_message( $message ) || in_array( $code, array( 'http_request_timeout' ), true );

        if ( $is_timeout ) {
            $friendly = sprintf(
                'AI 平台请求超时：已等待 %d 秒，尝试 %d 次仍未返回。请先在“平台设置”测试连通性，或减少一次提交的模型数量后再分析。原始错误：%s',
                absint( $timeout ),
                absint( $attempts ),
                $message !== '' ? $message : $code
            );
            return new WP_Error(
                'qigeo_ai_timeout',
                $friendly,
                array(
                    'endpoint'  => esc_url_raw( (string) $endpoint ),
                    'timeout'   => absint( $timeout ),
                    'attempts'  => absint( $attempts ),
                    'raw_error' => $message,
                    'trace_id'  => $trace_id,
                )
            );
        }

        $friendly = sprintf(
            'AI 平台请求失败：%s。请检查平台访问地址、服务器网络和 API Key。',
            $message !== '' ? $message : $code
        );
        return new WP_Error(
            'qigeo_ai_transport_error',
            $friendly,
            array(
                'endpoint'  => esc_url_raw( (string) $endpoint ),
                'timeout'   => absint( $timeout ),
                'attempts'  => absint( $attempts ),
                'raw_error' => $message,
                'trace_id'  => $trace_id,
            )
        );
    }

    /**
     * @param string $message message
     * @return bool
     */
    private static function is_timeout_message( $message ) {
        $message = strtolower( (string) $message );
        foreach ( array( 'timed out', 'timeout', 'operation timed out', 'cURL error 28' ) as $needle ) {
            if ( strpos( $message, strtolower( $needle ) ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param WP_Error $error error
     * @return bool
     */
    private static function is_retryable_wp_error( $error ) {
        if ( ! $error instanceof WP_Error ) {
            return false;
        }

        $code = (string) $error->get_error_code();
        if ( in_array( $code, array( 'http_request_failed', 'http_request_timeout' ), true ) ) {
            return true;
        }

        $message = strtolower( (string) $error->get_error_message() );
        foreach ( array( 'timed out', 'timeout', 'connection reset', 'could not resolve host', 'operation timed out' ) as $needle ) {
            if ( strpos( $message, $needle ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int $http_code http code
     * @return bool
     */
    private static function is_retryable_http_code( $http_code ) {
        $http_code = absint( $http_code );
        return $http_code === 429 || $http_code >= 500;
    }

    /**
     * 具备随机抖动的指数退避重试 (Exponential Backoff with Full Jitter)
     *
     * @param int $attempt 当前重试轮次
     * @return void
     */
    private static function sleep_for_retry( $attempt ) {
        $base_seconds = pow( 2, min( 3, (int) $attempt ) ); // 1s, 2s, 4s, 8s
        $jitter_ms = function_exists( 'wp_rand' ) ? wp_rand( 100, 500 ) : rand( 100, 500 );
        $total_ms = min( 6000, (int) ( $base_seconds * 1000 ) + $jitter_ms );
        usleep( $total_ms * 1000 );
    }

    /**
     * @param array<string,mixed> $json response json
     * @return string
     */
    private static function extract_message_content( $json ) {
        if ( isset( $json['choices'][0]['message']['content'] ) ) {
            $content = $json['choices'][0]['message']['content'];
            if ( is_string( $content ) ) {
                return $content;
            }
            if ( is_array( $content ) ) {
                $parts = array();
                foreach ( $content as $item ) {
                    if ( is_array( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) ) {
                        $parts[] = $item['text'];
                    }
                }
                return implode( "\n", $parts );
            }
        }
        return '';
    }

    /**
     * @param string              $endpoint endpoint
     * @param string              $api_key api key
     * @param string              $model_name model
     * @param string              $broken_content broken content
     * @param array<string,mixed> $runtime runtime
     * @return array<string,mixed>|null
     */
    private static function repair_json_with_model( $endpoint, $api_key, $model_name, $broken_content, $runtime ) {
        $snippet = trim( (string) $broken_content );
        if ( $snippet === '' ) {
            return null;
        }

        if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
            if ( mb_strlen( $snippet ) > 6000 ) {
                $snippet = mb_substr( $snippet, 0, 6000 );
            }
        } elseif ( strlen( $snippet ) > 6000 ) {
            $snippet = substr( $snippet, 0, 6000 );
        }

        $request_body = array(
            'model' => $model_name,
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => '你是 JSON 修复器。请把输入修复为合法 JSON 对象，只输出 JSON，不要解释。',
                ),
                array(
                    'role' => 'user',
                    'content' => $snippet,
                ),
            ),
            'temperature' => 0,
        );
        if ( ! empty( $runtime['enable_strict_json_mode'] ) ) {
            $request_body['response_format'] = array( 'type' => 'json_object' );
        }

        $repair_runtime = is_array( $runtime ) ? $runtime : array();
        $repair_timeout = isset( $repair_runtime['timeout'] ) ? absint( $repair_runtime['timeout'] ) : 20;
        $repair_runtime['timeout'] = max( 5, min( 20, $repair_timeout ) );
        $repair_runtime['retries'] = 0;

        $packet = self::request_with_retry( $endpoint, $api_key, $request_body, $repair_runtime );
        if ( is_wp_error( $packet ) ) {
            return null;
        }

        $json = isset( $packet['json'] ) && is_array( $packet['json'] ) ? $packet['json'] : array();
        if ( isset( $json['choices'][0]['message']['parsed'] ) && is_array( $json['choices'][0]['message']['parsed'] ) ) {
            return $json['choices'][0]['message']['parsed'];
        }

        $content = self::extract_message_content( $json );
        $parsed = self::extract_json( $content );
        return is_array( $parsed ) ? $parsed : null;
    }

    private static function build_endpoint( $base_url ) {
        $base_url = trim( (string) $base_url );
        if ( $base_url === '' ) {
            return '';
        }

        $base_url = untrailingslashit( $base_url );

        if ( preg_match( '#/chat/completions$#', $base_url ) ) {
            return $base_url;
        }

        // 火山方舟（豆包）常用路径
        if ( preg_match( '#/api/v3$#', $base_url ) ) {
            return $base_url . '/chat/completions';
        }

        // 火山方舟 Bots 路径
        if ( preg_match( '#/api/v3/bots$#', $base_url ) ) {
            return $base_url . '/chat/completions';
        }

        if ( preg_match( '#/v1$#', $base_url ) ) {
            return $base_url . '/chat/completions';
        }

        return $base_url . '/v1/chat/completions';
    }

    private static function extract_json( $content ) {
        $content = trim( (string) $content );
        $decoded = json_decode( $content, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        if ( preg_match( '/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $content, $matches ) ) {
            $decoded = json_decode( trim( $matches[1] ), true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        $start = strpos( $content, '{' );
        $end = strrpos( $content, '}' );
        if ( $start !== false && $end !== false && $end > $start ) {
            $decoded = json_decode( substr( $content, $start, $end - $start + 1 ), true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        return null;
    }
}
