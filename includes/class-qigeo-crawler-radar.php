<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI 爬虫探测雷达 (AI Crawler Radar)
 *
 * 专门捕获字节跳动/豆包 (Bytespider)、OpenAI (GPTBot / OAI-SearchBot)、
 * Anthropic (ClaudeBot)、Perplexity 等核心 AI 搜索爬虫的访问痕迹。
 * 仅对识别出的 AI 爬虫落库，对普通访客零负担，直观呈现 GEO 穿透成效。
 */
class QiGeo_Crawler_Radar {

    const MENU_SLUG = 'qigeo-crawler-radar';

    /**
     * 已知核心 AI 爬虫特征库
     *
     * @var array<string,array<string,string>>
     */
    private static $known_bots = array(
        'bytespider' => array(
            'name'  => 'Bytespider',
            'label' => '字节跳动 / 豆包',
            'color' => '#2563eb',
        ),
        'gptbot' => array(
            'name'  => 'GPTBot',
            'label' => 'OpenAI / ChatGPT 爬虫',
            'color' => '#10a37f',
        ),
        'oai-searchbot' => array(
            'name'  => 'OAI-SearchBot',
            'label' => 'OpenAI 搜索探针',
            'color' => '#059669',
        ),
        'chatgpt-user' => array(
            'name'  => 'ChatGPT-User',
            'label' => 'ChatGPT 实时检索',
            'color' => '#16a34a',
        ),
        'claudebot' => array(
            'name'  => 'ClaudeBot',
            'label' => 'Anthropic Claude',
            'color' => '#d97706',
        ),
        'claude-web' => array(
            'name'  => 'Claude-Web',
            'label' => 'Claude 联网检索',
            'color' => '#b45309',
        ),
        'perplexitybot' => array(
            'name'  => 'PerplexityBot',
            'label' => 'Perplexity AI 搜索',
            'color' => '#0891b2',
        ),
        'google-extended' => array(
            'name'  => 'Google-Extended',
            'label' => 'Google Gemini 检索',
            'color' => '#4338ca',
        ),
        'applebot-extended' => array(
            'name'  => 'Applebot-Extended',
            'label' => 'Apple 智能',
            'color' => '#4b5563',
        ),
        'meta-externalagent' => array(
            'name'  => 'Meta-ExternalAgent',
            'label' => 'Meta Llama 爬虫',
            'color' => '#1d4ed8',
        ),
        'cohere-ai' => array(
            'name'  => 'Cohere-AI',
            'label' => 'Cohere AI',
            'color' => '#374151',
        ),
    );

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 35 );
        add_action( 'template_redirect', array( __CLASS__, 'detect_and_log_crawler' ), 999 );
        add_action( 'admin_init', array( __CLASS__, 'handle_clear_logs' ) );
    }

    /**
     * 队列表名
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'qigeo_crawler_logs';
    }

    public static function register_submenu() {
        add_submenu_page(
            'qigeo-settings',
            'AI 爬虫探测雷达',
            '爬虫雷达',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * 前台侦测并记录 AI 爬虫来访
     */
    public static function detect_and_log_crawler() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        $settings = class_exists( 'QiGeo_Settings' ) ? QiGeo_Settings::get_settings() : array();
        $enabled = ! isset( $settings['enable_crawler_radar'] ) || $settings['enable_crawler_radar'] === '1';
        if ( ! $enabled ) {
            return;
        }

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        if ( $ua === '' ) {
            return;
        }

        // 快速正则过滤：仅对命中 AI 爬虫特征库的请求进行深度解析
        $pattern = '/(Bytespider|GPTBot|OAI-SearchBot|ChatGPT-User|ClaudeBot|Claude-Web|anthropic-ai|PerplexityBot|Google-Extended|Applebot-Extended|Meta-ExternalAgent|cohere-ai)/i';
        if ( ! preg_match( $pattern, $ua, $matches ) ) {
            return;
        }

        $matched_token = strtolower( (string) $matches[1] );
        $bot_info = null;

        if ( isset( self::$known_bots[ $matched_token ] ) ) {
            $bot_info = self::$known_bots[ $matched_token ];
        } elseif ( $matched_token === 'anthropic-ai' ) {
            $bot_info = self::$known_bots['claudebot'];
        } else {
            foreach ( self::$known_bots as $key => $info ) {
                if ( false !== stripos( $ua, $info['name'] ) ) {
                    $bot_info = $info;
                    break;
                }
            }
        }

        if ( ! $bot_info ) {
            $bot_info = array(
                'name'  => ucfirst( $matched_token ),
                'label' => ucfirst( $matched_token ) . ' (AI)',
                'color' => '#64748b',
            );
        }

        // 提取抓取上下文
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $post_id = is_singular() ? absint( get_queried_object_id() ) : 0;
        $status_code = http_response_code() ? absint( http_response_code() ) : 200;
        $ip = self::get_client_ip();

        self::insert_log( array(
            'bot_name'    => $bot_info['name'],
            'bot_label'   => $bot_info['label'],
            'request_uri' => mb_substr( sanitize_text_field( wp_unslash( $uri ) ), 0, 500, 'UTF-8' ),
            'post_id'     => $post_id,
            'status_code' => $status_code,
            'ip'          => mb_substr( $ip, 0, 95, 'UTF-8' ),
            'user_agent'  => mb_substr( sanitize_text_field( wp_unslash( $ua ) ), 0, 800, 'UTF-8' ),
        ) );

        self::maybe_cleanup_old_logs();
    }

    /**
     * 写入数据库日志
     *
     * @param array<string,mixed> $data 日志数据
     * @return bool
     */
    private static function insert_log( $data ) {
        global $wpdb;

        $table = self::get_table_name();
        if ( class_exists( 'QiGeo_Admin_Helper' ) && ! QiGeo_Admin_Helper::table_exists( $table ) ) {
            if ( class_exists( 'QiGeo_Install' ) ) {
                QiGeo_Install::create_tables();
            }
        }

        $now = current_time( 'mysql' );
        $inserted = $wpdb->insert(
            $table,
            array(
                'bot_name'    => $data['bot_name'],
                'bot_label'   => $data['bot_label'],
                'request_uri' => $data['request_uri'],
                'post_id'     => $data['post_id'],
                'status_code' => $data['status_code'],
                'ip'          => $data['ip'],
                'user_agent'  => $data['user_agent'],
                'created_at'  => $now,
            ),
            array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
        );

        return false !== $inserted;
    }

    /**
     * 定期自动轮转清理历史日志（每天最多触发一次）
     */
    private static function maybe_cleanup_old_logs() {
        if ( get_transient( 'qigeo_radar_cleanup_lock' ) ) {
            return;
        }

        set_transient( 'qigeo_radar_cleanup_lock', 1, 86400 );

        $settings = class_exists( 'QiGeo_Settings' ) ? QiGeo_Settings::get_settings() : array();
        $retention_days = isset( $settings['crawler_radar_retention_days'] ) ? absint( $settings['crawler_radar_retention_days'] ) : 30;
        if ( $retention_days < 7 ) {
            $retention_days = 7;
        }

        global $wpdb;
        $table = self::get_table_name();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $cutoff
            )
        );

        // 物理上限硬顶熔断：无论天数如何，总行数若超出 10,000 条，立即削减最旧的一半数据，彻底杜绝数据库体积膨胀
        $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $total_rows > 10000 ) {
            $excess = $total_rows - 5000;
            $old_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id ASC LIMIT %d", $excess ) );
            if ( ! empty( $old_ids ) ) {
                $placeholders = implode( ', ', array_fill( 0, count( $old_ids ), '%d' ) );
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $old_ids ) );
            }
        }
    }

    /**
     * 处理管理员手动清空日志
     */
    public static function handle_clear_logs() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_POST['qigeo_clear_crawler_logs'] ) ) {
            return;
        }

        check_admin_referer( 'qigeo_clear_crawler_logs_action', 'qigeo_clear_crawler_logs_nonce' );

        global $wpdb;
        $table = self::get_table_name();
        $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'cleared' => '1',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * 渲染后台【AI 爬虫探测雷达】看板
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table = self::get_table_name();

        if ( class_exists( 'QiGeo_Admin_Helper' ) && ! QiGeo_Admin_Helper::table_exists( $table ) ) {
            if ( class_exists( 'QiGeo_Install' ) ) {
                QiGeo_Install::create_tables();
            }
        }

        // 过滤参数
        $bot_filter = isset( $_GET['bot'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['bot'] ) ) : '';
        $days_filter = isset( $_GET['days'] ) ? absint( wp_unslash( (string) $_GET['days'] ) ) : 7;
        if ( ! in_array( $days_filter, array( 1, 7, 30, 90 ), true ) ) {
            $days_filter = 7;
        }

        $paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1;
        $per_page = 20;
        $offset = ( $paged - 1 ) * $per_page;

        // 核心统计指标
        $today_start = current_time( 'Y-m-d' ) . ' 00:00:00';
        $today_count = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $today_start )
        );

        $days_start = gmdate( 'Y-m-d H:i:s', time() - ( $days_filter * DAY_IN_SECONDS ) );
        $period_count = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $days_start )
        );

        $total_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        // 爬虫占比排行
        $bot_distribution = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT bot_name, COUNT(*) AS count_num FROM {$table} WHERE created_at >= %s GROUP BY bot_name ORDER BY count_num DESC LIMIT 8",
                $days_start
            ),
            ARRAY_A
        );

        // 最受 AI 关注的热门文章
        $top_pages = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT request_uri, post_id, COUNT(*) AS crawl_count FROM {$table} WHERE created_at >= %s GROUP BY request_uri ORDER BY crawl_count DESC LIMIT 5",
                $days_start
            ),
            ARRAY_A
        );

        // 分页日志查询
        $where_clauses = array( '1=1' );
        $params = array();

        if ( $bot_filter !== '' ) {
            $where_clauses[] = 'bot_name = %s';
            $params[] = $bot_filter;
        }

        if ( $days_filter > 0 ) {
            $where_clauses[] = 'created_at >= %s';
            $params[] = $days_start;
        }

        $where_sql = implode( ' AND ', $where_clauses );
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $filtered_total = empty( $params ) ? (int) $wpdb->get_var( $count_sql ) : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

        $log_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $log_params = array_merge( $params, array( $per_page, $offset ) );
        $logs = (array) $wpdb->get_results( $wpdb->prepare( $log_sql, $log_params ), ARRAY_A );

        $total_pages = ceil( $filtered_total / $per_page );
        ?>
        <div class="wrap qigeo-wrap">
            <h1>AI 爬虫探测雷达</h1>
            <p class="description">实时捕获字节跳动/豆包 (Bytespider)、OpenAI (GPTBot)、Claude、Perplexity 等主流 AI 搜索爬虫的访问痕迹，洞察 AI 对站点的收录与穿透偏好。</p>

            <?php if ( ! empty( $_GET['cleared'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>爬虫探测日志已全部清空。</p></div>
            <?php endif; ?>

            <!-- 统计指标卡片 -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0;">
                <div class="qigeo-card" style="padding:16px;text-align:center;">
                    <div style="font-size:13px;color:#64748b;">今日 AI 爬虫抓取</div>
                    <div style="font-size:32px;font-weight:700;color:#2563eb;margin-top:6px;"><?php echo esc_html( number_format_i18n( $today_count ) ); ?></div>
                    <div style="font-size:12px;color:#94a3b8;margin-top:4px;">次来访</div>
                </div>
                <div class="qigeo-card" style="padding:16px;text-align:center;">
                    <div style="font-size:13px;color:#64748b;">最近 <?php echo esc_html( (string) $days_filter ); ?> 天抓取</div>
                    <div style="font-size:32px;font-weight:700;color:#10a37f;margin-top:6px;"><?php echo esc_html( number_format_i18n( $period_count ) ); ?></div>
                    <div style="font-size:12px;color:#94a3b8;margin-top:4px;">次来访</div>
                </div>
                <div class="qigeo-card" style="padding:16px;text-align:center;">
                    <div style="font-size:13px;color:#64748b;">历史累计捕获</div>
                    <div style="font-size:32px;font-weight:700;color:#0f172a;margin-top:6px;"><?php echo esc_html( number_format_i18n( $total_count ) ); ?></div>
                    <div style="font-size:12px;color:#94a3b8;margin-top:4px;">条有效日志</div>
                </div>
                <div class="qigeo-card" style="padding:16px;text-align:center;">
                    <div style="font-size:13px;color:#64748b;">最活跃爬虫</div>
                    <div style="font-size:22px;font-weight:700;color:#d97706;margin-top:12px;">
                        <?php echo ! empty( $bot_distribution[0]['bot_name'] ) ? esc_html( $bot_distribution[0]['bot_name'] ) : '暂无数据'; ?>
                    </div>
                </div>
            </div>

            <!-- 分析看板网格 -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
                <!-- 爬虫活跃度占比 -->
                <div class="qigeo-card" style="padding:16px;">
                    <h3 style="margin-top:0;font-size:15px;border-bottom:1px solid #f1f5f9;padding-bottom:10px;">🤖 活跃 AI 爬虫排行 (近 <?php echo esc_html( (string) $days_filter ); ?> 天)</h3>
                    <?php if ( empty( $bot_distribution ) ) : ?>
                        <p style="color:#94a3b8;font-size:13px;margin:20px 0;">暂未捕获到 AI 爬虫抓取记录，请稍后刷新。</p>
                    <?php else : ?>
                        <div style="margin-top:12px;">
                            <?php foreach ( $bot_distribution as $b ) :
                                $percent = $period_count > 0 ? round( ( (int) $b['count_num'] / $period_count ) * 100, 1 ) : 0;
                                $color = self::get_bot_color( $b['bot_name'] );
                            ?>
                                <div style="margin-bottom:12px;">
                                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
                                        <span><strong><?php echo esc_html( $b['bot_name'] ); ?></strong> (<?php echo esc_html( self::get_bot_label( $b['bot_name'] ) ); ?>)</span>
                                        <span><?php echo esc_html( (string) $b['count_num'] ); ?> 次 (<?php echo esc_html( (string) $percent ); ?>%)</span>
                                    </div>
                                    <div style="background:#f1f5f9;height:8px;border-radius:4px;overflow:hidden;">
                                        <div style="background:<?php echo esc_attr( $color ); ?>;width:<?php echo esc_attr( (string) $percent ); ?>%;height:100%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 最受 AI 关注的热门内容 -->
                <div class="qigeo-card" style="padding:16px;">
                    <h3 style="margin-top:0;font-size:15px;border-bottom:1px solid #f1f5f9;padding-bottom:10px;">📑 最受 AI 关注的页面 TOP 5</h3>
                    <?php if ( empty( $top_pages ) ) : ?>
                        <p style="color:#94a3b8;font-size:13px;margin:20px 0;">暂无页面抓取统计。</p>
                    <?php else : ?>
                        <table style="width:100%;font-size:13px;border-collapse:collapse;margin-top:10px;">
                            <tbody>
                                <?php foreach ( $top_pages as $tp ) :
                                    $link_title = $tp['request_uri'];
                                    if ( ! empty( $tp['post_id'] ) ) {
                                        $post_obj = get_post( $tp['post_id'] );
                                        if ( $post_obj ) {
                                            $link_title = $post_obj->post_title;
                                        }
                                    }
                                ?>
                                    <tr style="border-bottom:1px solid #f8fafc;">
                                        <td style="padding:8px 0;">
                                            <a href="<?php echo esc_url( home_url( $tp['request_uri'] ) ); ?>" target="_blank" style="text-decoration:none;font-weight:600;">
                                                <?php echo esc_html( mb_strimwidth( $link_title, 0, 45, '...' ) ); ?>
                                            </a>
                                            <div style="color:#94a3b8;font-size:11px;font-family:monospace;"><?php echo esc_html( $tp['request_uri'] ); ?></div>
                                        </td>
                                        <td style="text-align:right;white-space:nowrap;padding:8px 0;color:#2563eb;font-weight:700;">
                                            <?php echo esc_html( (string) $tp['crawl_count'] ); ?> 次
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 日志流水表单与筛选 -->
            <div class="qigeo-card" style="padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                    <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:10px;align-items:center;">
                        <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                        
                        <label for="qigeo-filter-bot" class="screen-reader-text">按爬虫过滤</label>
                        <select id="qigeo-filter-bot" name="bot">
                            <option value="">全部 AI 爬虫</option>
                            <?php foreach ( self::$known_bots as $b_key => $b_val ) : ?>
                                <option value="<?php echo esc_attr( $b_val['name'] ); ?>" <?php selected( $bot_filter, $b_val['name'] ); ?>>
                                    <?php echo esc_html( $b_val['label'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="qigeo-filter-days" class="screen-reader-text">按周期过滤</label>
                        <select id="qigeo-filter-days" name="days">
                            <option value="1" <?php selected( $days_filter, 1 ); ?>>今天 (24小时)</option>
                            <option value="7" <?php selected( $days_filter, 7 ); ?>>最近 7 天</option>
                            <option value="30" <?php selected( $days_filter, 30 ); ?>>最近 30 天</option>
                            <option value="90" <?php selected( $days_filter, 90 ); ?>>最近 90 天</option>
                        </select>

                        <button type="submit" class="button button-secondary">筛选</button>
                    </form>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" onsubmit="return confirm('确定要清空全部 AI 爬虫探测流水吗？此操作不可逆。');">
                        <?php wp_nonce_field( 'qigeo_clear_crawler_logs_action', 'qigeo_clear_crawler_logs_nonce' ); ?>
                        <input type="hidden" name="qigeo_clear_crawler_logs" value="1" />
                        <button type="submit" class="button button-link-delete" style="color:#dc2626;">清空所有日志</button>
                    </form>
                </div>

                <!-- 日志流水列表 -->
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:160px;">抓取时间</th>
                            <th style="width:160px;">AI 爬虫</th>
                            <th>抓取页面 / 文章</th>
                            <th style="width:80px;text-align:center;">状态码</th>
                            <th style="width:130px;">来源 IP</th>
                            <th style="width:180px;">User-Agent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $logs ) ) : ?>
                            <tr><td colspan="6" style="text-align:center;padding:24px;color:#94a3b8;">暂未发现符合条件的 AI 爬虫访问记录。</td></tr>
                        <?php else : ?>
                            <?php foreach ( $logs as $log ) :
                                $bot_color = self::get_bot_color( $log['bot_name'] );
                                $status_badge_color = ( (int) $log['status_code'] >= 200 && (int) $log['status_code'] < 400 ) ? '#16a34a' : '#dc2626';
                                $title_display = $log['request_uri'];
                                if ( ! empty( $log['post_id'] ) ) {
                                    $p = get_post( $log['post_id'] );
                                    if ( $p ) {
                                        $title_display = $p->post_title . ' (' . $log['request_uri'] . ')';
                                    }
                                }
                            ?>
                                <tr>
                                    <td><?php echo esc_html( $log['created_at'] ); ?></td>
                                    <td>
                                        <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;color:#fff;background:<?php echo esc_attr( $bot_color ); ?>;">
                                            <?php echo esc_html( $log['bot_name'] ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url( home_url( $log['request_uri'] ) ); ?>" target="_blank" style="text-decoration:none;">
                                            <?php echo esc_html( $title_display ); ?>
                                        </a>
                                    </td>
                                    <td style="text-align:center;">
                                        <span style="font-weight:700;color:<?php echo esc_attr( $status_badge_color ); ?>;">
                                            <?php echo esc_html( (string) $log['status_code'] ); ?>
                                        </span>
                                    </td>
                                    <td style="font-family:monospace;font-size:12px;"><?php echo esc_html( $log['ip'] ); ?></td>
                                    <td>
                                        <details style="cursor:pointer;font-size:12px;color:#64748b;">
                                            <summary style="outline:none;">展开 UA 细节</summary>
                                            <div style="font-family:monospace;font-size:11px;background:#f8fafc;padding:6px;border-radius:4px;margin-top:4px;word-break:break-all;">
                                                <?php echo esc_html( $log['user_agent'] ); ?>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- 分页 -->
                <?php if ( $total_pages > 1 ) : ?>
                    <div class="tablenav" style="margin-top:16px;">
                        <div class="tablenav-pages">
                            <span class="displaying-num">共 <?php echo esc_html( (string) $filtered_total ); ?> 条</span>
                            <?php
                            echo paginate_links( array(
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'prev_text' => '&laquo; 上一页',
                                'next_text' => '下一页 &raquo;',
                                'total'     => $total_pages,
                                'current'   => $paged,
                            ) );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function get_bot_color( $bot_name ) {
        $key = strtolower( trim( (string) $bot_name ) );
        if ( isset( self::$known_bots[ $key ]['color'] ) ) {
            return self::$known_bots[ $key ]['color'];
        }
        return '#475569';
    }

    private static function get_bot_label( $bot_name ) {
        $key = strtolower( trim( (string) $bot_name ) );
        if ( isset( self::$known_bots[ $key ]['label'] ) ) {
            return self::$known_bots[ $key ]['label'];
        }
        return $bot_name;
    }

    private static function get_client_ip() {
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            return sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
        }
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
            return sanitize_text_field( trim( $parts[0] ) );
        }
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            return sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
        }
        return '';
    }
}
