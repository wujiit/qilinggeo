<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Admin_Report {

    const MENU_SLUG = 'qigeo-report';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ) );
    }

    public static function register_submenu() {
        add_submenu_page(
            'qigeo-settings',
            '聚合报表',
            '聚合报表',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $overview = self::get_overview();
        $job_counts = self::get_job_status_counts();
        $trend = self::get_daily_trend( 14 );
        $provider_model_stats = self::get_provider_model_stats( 30 );
        $quality_gate = self::get_quality_gate_summary();
        ?>
        <div class="wrap qigeo-wrap">
            <h1>启灵GEO 聚合报表</h1>
            <p class="description">查看近期处理情况和整体结果。</p>

            <style>
                .qigeo-report-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin:14px 0 16px; }
                .qigeo-report-card { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:14px; }
                .qigeo-report-k { color:#50575e; font-size:12px; margin-bottom:6px; }
                .qigeo-report-v { font-size:24px; font-weight:700; line-height:1.2; color:#1d2327; }
                .qigeo-report-sub { color:#646970; font-size:12px; margin-top:6px; }
                .qigeo-report-section { margin-top:16px; }
                .qigeo-report-section h2 { margin-bottom:8px; }
                .qigeo-report-muted { color:#646970; }
            </style>

            <div class="qigeo-report-grid">
                <div class="qigeo-report-card">
                    <div class="qigeo-report-k">已分析文章覆盖</div>
                    <div class="qigeo-report-v"><?php echo esc_html( (string) $overview['coverage_percent'] ); ?>%</div>
                    <div class="qigeo-report-sub"><?php echo esc_html( (string) $overview['success_posts'] ); ?> / <?php echo esc_html( (string) $overview['published_posts'] ); ?>（发布文章）</div>
                </div>
                <div class="qigeo-report-card">
                    <div class="qigeo-report-k">分析记录成功率</div>
                    <div class="qigeo-report-v"><?php echo esc_html( (string) $overview['record_success_percent'] ); ?>%</div>
                    <div class="qigeo-report-sub">成功 <?php echo esc_html( (string) $overview['success_records'] ); ?> / 总计 <?php echo esc_html( (string) $overview['total_records'] ); ?></div>
                </div>
                <div class="qigeo-report-card">
                    <div class="qigeo-report-k">平均质量评分</div>
                    <div class="qigeo-report-v"><?php echo esc_html( (string) $overview['avg_score'] ); ?></div>
                    <div class="qigeo-report-sub">仅统计 success 记录</div>
                </div>
                <div class="qigeo-report-card">
                    <div class="qigeo-report-k">队列当前状态</div>
                    <div class="qigeo-report-v"><?php echo esc_html( (string) $job_counts['pending'] ); ?>/<?php echo esc_html( (string) $job_counts['processing'] ); ?></div>
                    <div class="qigeo-report-sub">pending / processing</div>
                </div>
                <div class="qigeo-report-card">
                    <div class="qigeo-report-k">质量闸门通过率</div>
                    <div class="qigeo-report-v"><?php echo esc_html( (string) $quality_gate['pass_percent'] ); ?>%</div>
                    <div class="qigeo-report-sub">通过 <?php echo esc_html( (string) $quality_gate['pass'] ); ?> / 总计 <?php echo esc_html( (string) $quality_gate['total'] ); ?></div>
                </div>
            </div>

            <div class="qigeo-report-section">
                <h2>队列累计状态</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>pending</th>
                            <th>processing</th>
                            <th>done</th>
                            <th>failed</th>
                            <th>skipped</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo esc_html( (string) $job_counts['pending'] ); ?></td>
                            <td><?php echo esc_html( (string) $job_counts['processing'] ); ?></td>
                            <td><?php echo esc_html( (string) $job_counts['done'] ); ?></td>
                            <td><?php echo esc_html( (string) $job_counts['failed'] ); ?></td>
                            <td><?php echo esc_html( (string) $job_counts['skipped'] ); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="qigeo-report-section">
                <h2>近 14 天分析趋势</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>日期</th>
                            <th>成功</th>
                            <th>失败</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $trend ) ) : ?>
                        <tr><td colspan="3" class="qigeo-report-muted">暂无数据</td></tr>
                    <?php else : ?>
                        <?php foreach ( $trend as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) $row['day'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['success_count'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['failed_count'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="qigeo-report-section">
                <h2>平台/模型表现（Top 30）</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>平台 ID</th>
                            <th>模型</th>
                            <th>总调用</th>
                            <th>成功</th>
                            <th>失败</th>
                            <th>成功率</th>
                            <th>平均分</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $provider_model_stats ) ) : ?>
                        <tr><td colspan="7" class="qigeo-report-muted">暂无数据</td></tr>
                    <?php else : ?>
                        <?php foreach ( $provider_model_stats as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) $row['provider_id'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['model'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['total_runs'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['success_runs'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['failed_runs'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['success_percent'] ); ?>%</td>
                                <td><?php echo esc_html( (string) $row['avg_score'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="qigeo-report-section">
                <h2>质量闸门拦截原因（Top 10）</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>原因</th>
                            <th>文章数</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $quality_gate['reasons'] ) ) : ?>
                        <tr><td colspan="2" class="qigeo-report-muted">暂无拦截原因</td></tr>
                    <?php else : ?>
                        <?php foreach ( $quality_gate['reasons'] as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) $row['reason'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['count'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_overview() {
        global $wpdb;

        $records_table = $wpdb->prefix . 'qigeo_records';
        if ( ! self::table_exists( $records_table ) ) {
            return array(
                'published_posts' => 0,
                'success_posts' => 0,
                'coverage_percent' => '0.0',
                'total_records' => 0,
                'success_records' => 0,
                'record_success_percent' => '0.0',
                'avg_score' => '0.0',
            );
        }

        $total_records = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$records_table}" );
        $success_records = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$records_table} WHERE status = 'success'" );
        $success_posts = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$records_table} WHERE status = 'success'" );
        $avg_score_raw = $wpdb->get_var( "SELECT AVG(score) FROM {$records_table} WHERE status = 'success' AND score IS NOT NULL" );
        $avg_score = $avg_score_raw !== null ? number_format( (float) $avg_score_raw, 1, '.', '' ) : '0.0';

        $settings = QiGeo_Settings::get_settings();
        $allowed_types = isset( $settings['allowed_post_types'] ) && is_array( $settings['allowed_post_types'] ) ? $settings['allowed_post_types'] : array( 'post' );
        $allowed_types = array_values( array_filter( array_map( 'sanitize_key', $allowed_types ) ) );
        if ( empty( $allowed_types ) ) {
            $allowed_types = array( 'post' );
        }
        $published_posts = self::count_published_posts( $allowed_types );

        return array(
            'published_posts' => $published_posts,
            'success_posts' => $success_posts,
            'coverage_percent' => self::percent( $success_posts, $published_posts ),
            'total_records' => $total_records,
            'success_records' => $success_records,
            'record_success_percent' => self::percent( $success_records, $total_records ),
            'avg_score' => $avg_score,
        );
    }

    /**
     * @return array<string,int>
     */
    private static function get_job_status_counts() {
        global $wpdb;

        $jobs_table = $wpdb->prefix . 'qigeo_jobs';
        $result = array(
            'pending' => 0,
            'processing' => 0,
            'done' => 0,
            'failed' => 0,
            'skipped' => 0,
        );

        if ( ! self::table_exists( $jobs_table ) ) {
            return $result;
        }

        $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$jobs_table} GROUP BY status", ARRAY_A );
        foreach ( (array) $rows as $row ) {
            $status = isset( $row['status'] ) ? (string) $row['status'] : '';
            $count = isset( $row['c'] ) ? (int) $row['c'] : 0;
            if ( isset( $result[ $status ] ) ) {
                $result[ $status ] = $count;
            }
        }

        return $result;
    }

    /**
     * @param int $days days
     * @return array<int,array<string,mixed>>
     */
    private static function get_daily_trend( $days = 14 ) {
        global $wpdb;

        $records_table = $wpdb->prefix . 'qigeo_records';
        if ( ! self::table_exists( $records_table ) ) {
            return array();
        }

        $days = max( 1, absint( $days ) );
        $start_ts = current_time( 'timestamp' ) - ( $days - 1 ) * DAY_IN_SECONDS;
        $start = wp_date( 'Y-m-d 00:00:00', $start_ts, wp_timezone() );

        $sql = "SELECT DATE(created_at) AS day,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
                FROM {$records_table}
                WHERE created_at >= %s
                GROUP BY DATE(created_at)
                ORDER BY day DESC";
        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $start ), ARRAY_A );
    }

    /**
     * @param int $limit limit
     * @return array<int,array<string,mixed>>
     */
    private static function get_provider_model_stats( $limit = 30 ) {
        global $wpdb;

        $records_table = $wpdb->prefix . 'qigeo_records';
        if ( ! self::table_exists( $records_table ) ) {
            return array();
        }

        $limit = max( 1, absint( $limit ) );
        $sql = "SELECT provider_id, model,
                COUNT(*) AS total_runs,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_runs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_runs,
                AVG(CASE WHEN status = 'success' THEN score ELSE NULL END) AS avg_score
                FROM {$records_table}
                GROUP BY provider_id, model
                ORDER BY total_runs DESC
                LIMIT %d";
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );

        foreach ( $rows as $i => $row ) {
            $total = isset( $row['total_runs'] ) ? (int) $row['total_runs'] : 0;
            $success = isset( $row['success_runs'] ) ? (int) $row['success_runs'] : 0;
            $rows[ $i ]['success_percent'] = self::percent( $success, $total );
            $rows[ $i ]['avg_score'] = ( isset( $row['avg_score'] ) && $row['avg_score'] !== null )
                ? number_format( (float) $row['avg_score'], 1, '.', '' )
                : '0.0';
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_quality_gate_summary() {
        global $wpdb;

        $snapshot_table = $wpdb->prefix . 'qigeo_post_snapshots';
        if ( ! self::table_exists( $snapshot_table ) ) {
            return array(
                'pass' => 0,
                'blocked' => 0,
                'total' => 0,
                'pass_percent' => '0.0',
                'reasons' => array(),
            );
        }

        $rows = (array) $wpdb->get_results(
            "SELECT frontend_eligible, COUNT(*) AS c
            FROM {$snapshot_table}
            WHERE frontend_eligible IS NOT NULL
            GROUP BY frontend_eligible",
            ARRAY_A
        );

        $pass = 0;
        $blocked = 0;
        foreach ( $rows as $row ) {
            $value = isset( $row['frontend_eligible'] ) ? (string) $row['frontend_eligible'] : '';
            $count = isset( $row['c'] ) ? (int) $row['c'] : 0;
            if ( $value === '1' ) {
                $pass += $count;
            } elseif ( $value === '0' ) {
                $blocked += $count;
            }
        }

        $reasons = (array) $wpdb->get_results(
            "SELECT quality_gate_reason AS reason, COUNT(*) AS count
            FROM {$snapshot_table}
            WHERE quality_gate_reason IS NOT NULL
            AND quality_gate_reason <> ''
            GROUP BY quality_gate_reason
            ORDER BY count DESC
            LIMIT 10",
            ARRAY_A
        );

        $total = $pass + $blocked;
        return array(
            'pass' => $pass,
            'blocked' => $blocked,
            'total' => $total,
            'pass_percent' => self::percent( $pass, $total ),
            'reasons' => $reasons,
        );
    }

    /**
     * @param array<int,string> $post_types post_types
     * @return int
     */
    private static function count_published_posts( $post_types ) {
        global $wpdb;

        if ( empty( $post_types ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})";

        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $post_types ) );
    }

    /**
     * @param int $num num
     * @param int $den den
     * @return string
     */
    private static function percent( $num, $den ) {
        $num = (int) $num;
        $den = (int) $den;
        if ( $den <= 0 ) {
            return '0.0';
        }
        return number_format( ( $num * 100 ) / $den, 1, '.', '' );
    }

    /**
     * @param string $table table
     * @return bool
     */
    private static function table_exists( $table ) {
        return QiGeo_Admin_Helper::table_exists( $table );
    }
}
