<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QiGeo_Platform_Trend_Dashboard {

    const MENU_SLUG = 'qigeo-platform-trend-dashboard';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'qigeo-settings',
            '回测趋势看板',
            '回测趋势看板',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function enqueue_assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
        if ( $page !== self::MENU_SLUG ) {
            return;
        }

        wp_enqueue_style( 'qigeo-admin', QIGEO_URL . 'assets/admin.css', array(), QIGEO_VERSION );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! self::table_exists() ) {
            QiGeo_Install::create_tables();
        }

        $table_exists = self::table_exists();
        $filters = self::get_filters();
        $base_url = add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) );

        $overview = $table_exists ? self::get_overview( $filters ) : self::get_empty_overview();
        $status_breakdown = $table_exists ? self::get_status_breakdown( $filters, (int) $overview['total_runs'] ) : self::get_empty_status_breakdown();
        $daily_trend = $table_exists ? self::get_daily_trend( $filters ) : array();
        $leaderboard = $table_exists ? self::get_group_leaderboard( $filters, 15 ) : array();
        $focus_rows = $table_exists ? self::get_focus_rows( $filters, 10 ) : array();
        $filter_options = $table_exists ? self::get_filter_options( $filters ) : array(
            'platforms' => array(),
            'keywords'  => array(),
            'brands'    => array(),
        );
        ?>
        <div class="wrap qigeo-wrap">
            <h1>平台回测趋势看板</h1>
            <p class="description">查看关键词近期变化。</p>

            <?php if ( ! $table_exists ) : ?>
                <div class="notice notice-error"><p>当前页面数据暂未准备好，请刷新页面后重试。</p></div>
            <?php endif; ?>

            <div class="qigeo-card">
                <div class="qigeo-card-header">
                    <h2>筛选范围</h2>
                    <a class="button button-secondary" href="<?php echo esc_url( $base_url ); ?>">重置筛选</a>
                </div>

                <form method="get" class="qigeo-trend-filter">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />

                    <div class="qigeo-trend-filter-grid">
                        <div class="qigeo-trend-filter-field">
                            <label for="qigeo-trend-days"><strong>时间范围</strong></label>
                            <select id="qigeo-trend-days" name="days">
                                <?php foreach ( self::get_supported_days() as $days ) : ?>
                                    <option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( $filters['days'], $days ); ?>>最近 <?php echo esc_html( (string) $days ); ?> 天</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="qigeo-trend-filter-field">
                            <label for="qigeo-trend-platform"><strong>目标平台</strong></label>
                            <select id="qigeo-trend-platform" name="target_platform">
                                <option value="">全部平台</option>
                                <?php foreach ( $filter_options['platforms'] as $option ) : ?>
                                    <option value="<?php echo esc_attr( $option ); ?>" <?php selected( $filters['target_platform'], $option ); ?>><?php echo esc_html( $option ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="qigeo-trend-filter-field">
                            <label for="qigeo-trend-keyword"><strong>目标推荐词</strong></label>
                            <select id="qigeo-trend-keyword" name="target_keyword">
                                <option value="">全部推荐词</option>
                                <?php foreach ( $filter_options['keywords'] as $option ) : ?>
                                    <option value="<?php echo esc_attr( $option ); ?>" <?php selected( $filters['target_keyword'], $option ); ?>><?php echo esc_html( $option ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="qigeo-trend-filter-field">
                            <label for="qigeo-trend-brand"><strong>品牌 / 产品名</strong></label>
                            <select id="qigeo-trend-brand" name="brand_name">
                                <option value="">全部品牌</option>
                                <?php foreach ( $filter_options['brands'] as $option ) : ?>
                                    <option value="<?php echo esc_attr( $option ); ?>" <?php selected( $filters['brand_name'], $option ); ?>><?php echo esc_html( $option ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="qigeo-trend-filter-actions">
                        <button type="submit" class="button button-primary">应用筛选</button>
                        <span class="description">统计口径基于“平台回测记录”里最近更新的数据。</span>
                    </div>
                </form>
            </div>

            <div class="qigeo-trend-grid">
                <div class="qigeo-trend-card">
                    <div class="qigeo-trend-k">回测总数</div>
                    <div class="qigeo-trend-v"><?php echo esc_html( (string) $overview['total_runs'] ); ?></div>
                    <div class="qigeo-trend-sub">当前筛选范围内的真实回测记录</div>
                </div>

                <div class="qigeo-trend-card">
                    <div class="qigeo-trend-k">明确推荐率</div>
                    <div class="qigeo-trend-v"><?php echo esc_html( $overview['recommended_rate'] ); ?>%</div>
                    <div class="qigeo-trend-sub">明确推荐 <?php echo esc_html( (string) $overview['recommended_count'] ); ?> 次</div>
                </div>

                <div class="qigeo-trend-card">
                    <div class="qigeo-trend-k">正向提及率</div>
                    <div class="qigeo-trend-v"><?php echo esc_html( $overview['positive_rate'] ); ?>%</div>
                    <div class="qigeo-trend-sub">明确推荐 + 有提及但不强</div>
                </div>

                <div class="qigeo-trend-card">
                    <div class="qigeo-trend-k">完全未提及率</div>
                    <div class="qigeo-trend-v"><?php echo esc_html( $overview['absent_rate'] ); ?>%</div>
                    <div class="qigeo-trend-sub">完全未提及 <?php echo esc_html( (string) $overview['absent_count'] ); ?> 次</div>
                </div>

                <div class="qigeo-trend-card">
                    <div class="qigeo-trend-k">风险暴露率</div>
                    <div class="qigeo-trend-v"><?php echo esc_html( $overview['risk_rate'] ); ?>%</div>
                    <div class="qigeo-trend-sub">竞品主导 + 幻觉风险</div>
                </div>

                <div class="qigeo-trend-card">
                    <div class="qigeo-trend-k">活跃组合数</div>
                    <div class="qigeo-trend-v"><?php echo esc_html( (string) $overview['distinct_groups'] ); ?></div>
                    <div class="qigeo-trend-sub"><?php echo esc_html( (string) $overview['distinct_platforms'] ); ?> 个平台 / <?php echo esc_html( (string) $overview['distinct_keywords'] ); ?> 个词</div>
                </div>
            </div>

            <div class="qigeo-card qigeo-trend-section">
                <div class="qigeo-card-header">
                    <h2>状态分布</h2>
                    <span class="description">看这段时间你的回测结构是不是正在往“明确推荐”移动。</span>
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>状态</th>
                            <th>次数</th>
                            <th>占比</th>
                            <th>说明</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $status_breakdown ) ) : ?>
                        <tr><td colspan="4">暂无数据。</td></tr>
                    <?php else : ?>
                        <?php foreach ( $status_breakdown as $row ) : ?>
                            <tr>
                                <td><span class="qigeo-trend-pill <?php echo esc_attr( self::get_status_class( $row['status'] ) ); ?>"><?php echo esc_html( self::humanize_status( $row['status'] ) ); ?></span></td>
                                <td><?php echo esc_html( (string) $row['count'] ); ?></td>
                                <td><?php echo esc_html( $row['percent'] ); ?>%</td>
                                <td><?php echo esc_html( self::get_status_hint( $row['status'] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="qigeo-card qigeo-trend-section">
                <div class="qigeo-card-header">
                    <h2>近段时间回测轨迹</h2>
                    <span class="description">默认展示最近最多 30 个自然日，方便观察推荐率有没有往上走。</span>
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>日期</th>
                            <th>总回测</th>
                            <th>明确推荐</th>
                            <th>正向提及</th>
                            <th>风险暴露</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $daily_trend ) ) : ?>
                        <tr><td colspan="5">暂无数据。</td></tr>
                    <?php else : ?>
                        <?php foreach ( $daily_trend as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) $row['day'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['total_runs'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['recommended_count'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['positive_count'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['risk_count'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="qigeo-card qigeo-trend-section">
                <div class="qigeo-card-header">
                    <h2>关键词 / 平台组合榜</h2>
                    <span class="description">优先盯“量大但推荐弱”的组合，它们最值得继续打磨。</span>
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>平台 / 目标词</th>
                            <th>品牌</th>
                            <th>总回测</th>
                            <th>明确推荐率</th>
                            <th>正向提及率</th>
                            <th>风险率</th>
                            <th>最近趋势</th>
                            <th>最近更新时间</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $leaderboard ) ) : ?>
                        <tr><td colspan="8">暂无数据。</td></tr>
                    <?php else : ?>
                        <?php foreach ( $leaderboard as $row ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $row['target_platform'] ); ?></strong>
                                    <div class="qigeo-trend-row-meta"><?php echo esc_html( $row['target_keyword'] ); ?></div>
                                </td>
                                <td><?php echo esc_html( $row['brand_name'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['total_runs'] ); ?></td>
                                <td><?php echo esc_html( $row['recommended_rate'] ); ?>%</td>
                                <td><?php echo esc_html( $row['positive_rate'] ); ?>%</td>
                                <td><?php echo esc_html( $row['risk_rate'] ); ?>%</td>
                                <td><span class="qigeo-trend-pill <?php echo esc_attr( self::get_trend_class( $row['trend_signal'] ) ); ?>"><?php echo esc_html( self::humanize_trend_signal( $row['trend_signal'] ) ); ?></span></td>
                                <td><?php echo esc_html( $row['latest_updated'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="qigeo-card qigeo-trend-section">
                <div class="qigeo-card-header">
                    <h2>重点抢救队列</h2>
                    <span class="description">优先处理“被竞品主导、存在幻觉风险、长期未提及”的组合。</span>
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>平台 / 目标词</th>
                            <th>最新状态</th>
                            <th>主要问题</th>
                            <th>动作建议</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $focus_rows ) ) : ?>
                        <tr><td colspan="4">暂无重点项。</td></tr>
                    <?php else : ?>
                        <?php foreach ( $focus_rows as $row ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $row['target_platform'] ); ?></strong>
                                    <div class="qigeo-trend-row-meta"><?php echo esc_html( $row['target_keyword'] . ' / ' . $row['brand_name'] ); ?></div>
                                    <div class="qigeo-trend-row-meta">总回测 <?php echo esc_html( (string) $row['total_runs'] ); ?> 次，最近趋势：<?php echo esc_html( self::humanize_trend_signal( $row['trend_signal'] ) ); ?></div>
                                </td>
                                <td><span class="qigeo-trend-pill <?php echo esc_attr( self::get_status_class( $row['latest_status'] ) ); ?>"><?php echo esc_html( self::humanize_status( $row['latest_status'] ) ); ?></span></td>
                                <td><?php echo esc_html( self::get_focus_issue_label( $row ) ); ?></td>
                                <td><?php echo esc_html( self::build_focus_action( $row ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private static function get_filters() {
        return array(
            'days'            => self::normalize_days( isset( $_GET['days'] ) ? absint( wp_unslash( (string) $_GET['days'] ) ) : 30 ),
            'target_platform' => self::limit_text( isset( $_GET['target_platform'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['target_platform'] ) ) : '', 120 ),
            'target_keyword'  => self::limit_text( isset( $_GET['target_keyword'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['target_keyword'] ) ) : '', 255 ),
            'brand_name'      => self::limit_text( isset( $_GET['brand_name'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['brand_name'] ) ) : '', 190 ),
        );
    }

    private static function get_filter_options( $filters ) {
        return array(
            'platforms' => self::get_distinct_values( 'target_platform', $filters['days'], $filters['target_platform'] ),
            'keywords'  => self::get_distinct_values( 'target_keyword', $filters['days'], $filters['target_keyword'] ),
            'brands'    => self::get_distinct_values( 'brand_name', $filters['days'], $filters['brand_name'] ),
        );
    }

    private static function get_distinct_values( $field, $days, $selected = '' ) {
        global $wpdb;

        $allowed_fields = array(
            'target_platform' => 'target_platform',
            'target_keyword'  => 'target_keyword',
            'brand_name'      => 'brand_name',
        );

        if ( ! isset( $allowed_fields[ $field ] ) ) {
            return array();
        }

        $column = $allowed_fields[ $field ];
        $scope = self::build_scope_where(
            array(
                'days'            => $days,
                'target_platform' => '',
                'target_keyword'  => '',
                'brand_name'      => '',
            )
        );

        $sql = "SELECT {$column} AS option_value, COUNT(*) AS total_runs FROM " . self::get_table_name() . " WHERE {$scope['sql']} AND {$column} <> '' GROUP BY {$column} ORDER BY total_runs DESC, {$column} ASC LIMIT %d";
        $params = $scope['params'];
        $params[] = 40;

        $rows = (array) $wpdb->get_results( self::prepare_sql( $sql, $params ), ARRAY_A );
        $options = array();
        foreach ( $rows as $row ) {
            $value = isset( $row['option_value'] ) ? trim( (string) $row['option_value'] ) : '';
            if ( $value !== '' ) {
                $options[] = $value;
            }
        }

        if ( $selected !== '' && ! in_array( $selected, $options, true ) ) {
            array_unshift( $options, $selected );
        }

        return $options;
    }

    private static function get_overview( $filters ) {
        global $wpdb;

        $scope = self::build_scope_where( $filters );
        $sql = 'SELECT COUNT(*) AS total_runs,
            COUNT(DISTINCT target_platform) AS distinct_platforms,
            COUNT(DISTINCT target_keyword) AS distinct_keywords,
            COUNT(DISTINCT CONCAT(target_platform, \'||\', target_keyword, \'||\', brand_name)) AS distinct_groups,
            SUM(CASE WHEN mention_status = \'recommended\' THEN 1 ELSE 0 END) AS recommended_count,
            SUM(CASE WHEN mention_status IN (\'recommended\', \'mentioned\') THEN 1 ELSE 0 END) AS positive_count,
            SUM(CASE WHEN mention_status = \'absent\' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN mention_status IN (\'competitor_led\', \'hallucination_risk\') THEN 1 ELSE 0 END) AS risk_count
            FROM ' . self::get_table_name() . " WHERE {$scope['sql']}";

        $row = $wpdb->get_row( self::prepare_sql( $sql, $scope['params'] ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            return self::get_empty_overview();
        }

        $total_runs = isset( $row['total_runs'] ) ? (int) $row['total_runs'] : 0;
        $recommended_count = isset( $row['recommended_count'] ) ? (int) $row['recommended_count'] : 0;
        $positive_count = isset( $row['positive_count'] ) ? (int) $row['positive_count'] : 0;
        $absent_count = isset( $row['absent_count'] ) ? (int) $row['absent_count'] : 0;
        $risk_count = isset( $row['risk_count'] ) ? (int) $row['risk_count'] : 0;

        return array(
            'total_runs'         => $total_runs,
            'distinct_platforms' => isset( $row['distinct_platforms'] ) ? (int) $row['distinct_platforms'] : 0,
            'distinct_keywords'  => isset( $row['distinct_keywords'] ) ? (int) $row['distinct_keywords'] : 0,
            'distinct_groups'    => isset( $row['distinct_groups'] ) ? (int) $row['distinct_groups'] : 0,
            'recommended_count'  => $recommended_count,
            'positive_count'     => $positive_count,
            'absent_count'       => $absent_count,
            'risk_count'         => $risk_count,
            'recommended_rate'   => self::format_rate( $recommended_count, $total_runs ),
            'positive_rate'      => self::format_rate( $positive_count, $total_runs ),
            'absent_rate'        => self::format_rate( $absent_count, $total_runs ),
            'risk_rate'          => self::format_rate( $risk_count, $total_runs ),
        );
    }

    private static function get_status_breakdown( $filters, $total_runs ) {
        global $wpdb;

        $scope = self::build_scope_where( $filters );
        $sql = 'SELECT mention_status, COUNT(*) AS total_runs
            FROM ' . self::get_table_name() . " WHERE {$scope['sql']} GROUP BY mention_status";

        $rows = (array) $wpdb->get_results( self::prepare_sql( $sql, $scope['params'] ), ARRAY_A );
        $map = array();
        foreach ( $rows as $row ) {
            $status = isset( $row['mention_status'] ) ? sanitize_key( (string) $row['mention_status'] ) : '';
            $map[ $status ] = isset( $row['total_runs'] ) ? (int) $row['total_runs'] : 0;
        }

        $result = array();
        foreach ( self::get_status_order() as $status ) {
            $count = isset( $map[ $status ] ) ? $map[ $status ] : 0;
            $result[] = array(
                'status'  => $status,
                'count'   => $count,
                'percent' => self::format_rate( $count, $total_runs ),
            );
        }

        return $result;
    }

    private static function get_daily_trend( $filters ) {
        global $wpdb;

        $scope = self::build_scope_where( $filters );
        $limit = min( 30, max( 7, (int) $filters['days'] ) );
        $sql = 'SELECT DATE(updated_at) AS day,
            COUNT(*) AS total_runs,
            SUM(CASE WHEN mention_status = \'recommended\' THEN 1 ELSE 0 END) AS recommended_count,
            SUM(CASE WHEN mention_status IN (\'recommended\', \'mentioned\') THEN 1 ELSE 0 END) AS positive_count,
            SUM(CASE WHEN mention_status IN (\'competitor_led\', \'hallucination_risk\') THEN 1 ELSE 0 END) AS risk_count
            FROM ' . self::get_table_name() . " WHERE {$scope['sql']} GROUP BY DATE(updated_at) ORDER BY day DESC LIMIT %d";

        $params = $scope['params'];
        $params[] = $limit;

        return (array) $wpdb->get_results( self::prepare_sql( $sql, $params ), ARRAY_A );
    }

    private static function get_group_leaderboard( $filters, $limit = 15 ) {
        global $wpdb;

        $scope = self::build_scope_where( $filters );
        $sql = 'SELECT target_platform, target_keyword, brand_name,
            COUNT(*) AS total_runs,
            SUM(CASE WHEN mention_status = \'recommended\' THEN 1 ELSE 0 END) AS recommended_count,
            SUM(CASE WHEN mention_status IN (\'recommended\', \'mentioned\') THEN 1 ELSE 0 END) AS positive_count,
            SUM(CASE WHEN mention_status IN (\'competitor_led\', \'hallucination_risk\') THEN 1 ELSE 0 END) AS risk_count,
            MAX(updated_at) AS latest_updated
            FROM ' . self::get_table_name() . " WHERE {$scope['sql']} GROUP BY target_platform, target_keyword, brand_name ORDER BY total_runs DESC, latest_updated DESC LIMIT %d";

        $params = $scope['params'];
        $params[] = max( 1, absint( $limit ) );

        $rows = (array) $wpdb->get_results( self::prepare_sql( $sql, $params ), ARRAY_A );
        foreach ( $rows as &$row ) {
            $total_runs = isset( $row['total_runs'] ) ? (int) $row['total_runs'] : 0;
            $recommended_count = isset( $row['recommended_count'] ) ? (int) $row['recommended_count'] : 0;
            $positive_count = isset( $row['positive_count'] ) ? (int) $row['positive_count'] : 0;
            $risk_count = isset( $row['risk_count'] ) ? (int) $row['risk_count'] : 0;

            $row['recommended_rate'] = self::format_rate( $recommended_count, $total_runs );
            $row['positive_rate'] = self::format_rate( $positive_count, $total_runs );
            $row['risk_rate'] = self::format_rate( $risk_count, $total_runs );
            $row['trend_signal'] = self::get_group_trend_signal(
                isset( $row['target_platform'] ) ? (string) $row['target_platform'] : '',
                isset( $row['target_keyword'] ) ? (string) $row['target_keyword'] : '',
                isset( $row['brand_name'] ) ? (string) $row['brand_name'] : '',
                $filters['days']
            );
        }
        unset( $row );

        return $rows;
    }

    private static function get_focus_rows( $filters, $limit = 10 ) {
        global $wpdb;

        $scope = self::build_scope_where( $filters );
        $sql = "SELECT target_platform, target_keyword, brand_name,
            COUNT(*) AS total_runs,
            SUM(CASE WHEN mention_status = 'recommended' THEN 1 ELSE 0 END) AS recommended_count,
            SUM(CASE WHEN mention_status IN ('recommended', 'mentioned') THEN 1 ELSE 0 END) AS positive_count,
            SUM(CASE WHEN mention_status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN mention_status = 'competitor_led' THEN 1 ELSE 0 END) AS competitor_led_count,
            SUM(CASE WHEN mention_status = 'hallucination_risk' THEN 1 ELSE 0 END) AS hallucination_count,
            MAX(updated_at) AS latest_updated
            FROM " . self::get_table_name() . " WHERE {$scope['sql']} GROUP BY target_platform, target_keyword, brand_name HAVING COUNT(*) >= 2 ORDER BY (SUM(CASE WHEN mention_status = 'hallucination_risk' THEN 1 ELSE 0 END) * 4 + SUM(CASE WHEN mention_status = 'competitor_led' THEN 1 ELSE 0 END) * 3 + SUM(CASE WHEN mention_status = 'absent' THEN 1 ELSE 0 END) * 2 - SUM(CASE WHEN mention_status IN ('recommended', 'mentioned') THEN 1 ELSE 0 END)) DESC, latest_updated DESC LIMIT %d";

        $params = $scope['params'];
        $params[] = max( 1, absint( $limit ) );

        $rows = (array) $wpdb->get_results( self::prepare_sql( $sql, $params ), ARRAY_A );
        foreach ( $rows as &$row ) {
            $row['latest_status'] = self::get_latest_status_for_group(
                isset( $row['target_platform'] ) ? (string) $row['target_platform'] : '',
                isset( $row['target_keyword'] ) ? (string) $row['target_keyword'] : '',
                isset( $row['brand_name'] ) ? (string) $row['brand_name'] : '',
                $filters['days']
            );
            $row['trend_signal'] = self::get_group_trend_signal(
                isset( $row['target_platform'] ) ? (string) $row['target_platform'] : '',
                isset( $row['target_keyword'] ) ? (string) $row['target_keyword'] : '',
                isset( $row['brand_name'] ) ? (string) $row['brand_name'] : '',
                $filters['days']
            );
        }
        unset( $row );

        return $rows;
    }

    private static function get_group_trend_signal( $target_platform, $target_keyword, $brand_name, $days ) {
        $statuses = self::get_recent_statuses_for_group( $target_platform, $target_keyword, $brand_name, $days, 6 );
        if ( count( $statuses ) < 2 ) {
            return 'new';
        }

        $recent_scores = array_slice( $statuses, 0, min( 3, count( $statuses ) ) );
        $previous_scores = array_slice( $statuses, 3, 3 );

        if ( empty( $previous_scores ) ) {
            return 'flat';
        }

        $recent_avg = array_sum( $recent_scores ) / count( $recent_scores );
        $previous_avg = array_sum( $previous_scores ) / count( $previous_scores );
        $delta = $recent_avg - $previous_avg;

        if ( $delta >= 0.6 ) {
            return 'up';
        }
        if ( $delta <= -0.6 ) {
            return 'down';
        }

        return 'flat';
    }

    private static function get_recent_statuses_for_group( $target_platform, $target_keyword, $brand_name, $days, $limit = 6 ) {
        global $wpdb;

        $threshold = self::get_threshold_datetime( $days );
        $sql = 'SELECT mention_status FROM ' . self::get_table_name() . ' WHERE updated_at >= %s AND target_platform = %s AND target_keyword = %s AND brand_name = %s ORDER BY updated_at DESC, id DESC LIMIT %d';
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                $sql,
                $threshold,
                $target_platform,
                $target_keyword,
                $brand_name,
                max( 1, absint( $limit ) )
            ),
            ARRAY_A
        );

        $scores = array();
        foreach ( $rows as $row ) {
            $scores[] = self::status_score( isset( $row['mention_status'] ) ? (string) $row['mention_status'] : '' );
        }

        return $scores;
    }

    private static function get_latest_status_for_group( $target_platform, $target_keyword, $brand_name, $days ) {
        global $wpdb;

        $threshold = self::get_threshold_datetime( $days );
        $sql = 'SELECT mention_status FROM ' . self::get_table_name() . ' WHERE updated_at >= %s AND target_platform = %s AND target_keyword = %s AND brand_name = %s ORDER BY updated_at DESC, id DESC LIMIT 1';
        $status = $wpdb->get_var(
            $wpdb->prepare(
                $sql,
                $threshold,
                $target_platform,
                $target_keyword,
                $brand_name
            )
        );

        return sanitize_key( (string) $status );
    }

    private static function get_focus_issue_label( $row ) {
        $hallucination_count = isset( $row['hallucination_count'] ) ? (int) $row['hallucination_count'] : 0;
        $competitor_led_count = isset( $row['competitor_led_count'] ) ? (int) $row['competitor_led_count'] : 0;
        $absent_count = isset( $row['absent_count'] ) ? (int) $row['absent_count'] : 0;
        $positive_count = isset( $row['positive_count'] ) ? (int) $row['positive_count'] : 0;
        $recommended_count = isset( $row['recommended_count'] ) ? (int) $row['recommended_count'] : 0;

        if ( $hallucination_count > 0 ) {
            return '平台已经出现幻觉或错误归因，品牌口径需要先收紧。';
        }
        if ( $competitor_led_count > $recommended_count ) {
            return '回答更容易被竞品带走，说明比较证据和优势表达不够强。';
        }
        if ( $absent_count >= 2 ) {
            return '连续多次完全未提及，说明当前公开资产和问法还没进入平台偏好区。';
        }
        if ( $positive_count > 0 && $recommended_count === 0 ) {
            return '已经能被弱提及，但还没有形成明确推荐。';
        }

        return '需要继续观察，但暂时还没有形成稳定推荐。';
    }

    private static function build_focus_action( $row ) {
        $actions = array();

        if ( ! empty( $row['hallucination_count'] ) ) {
            $actions[] = '先回品牌实体库修正标准叫法、事实边界和禁用表述。';
        }
        if ( ! empty( $row['competitor_led_count'] ) && (int) $row['competitor_led_count'] >= (int) $row['recommended_count'] ) {
            $actions[] = '补对比页、证据页，并在平台适配中心强化“为什么选你而不是竞品”的问法。';
        }
        if ( ! empty( $row['absent_count'] ) && (int) $row['absent_count'] >= 2 ) {
            $actions[] = '优先补 FAQ、中文场景页或案例页，然后用同问题再次回测。';
        }
        if ( ! empty( $row['positive_count'] ) && empty( $row['recommended_count'] ) ) {
            $actions[] = '把已经出现的弱提及转成明确推荐，重点补理由句、证据句和落地场景。';
        }
        if ( empty( $actions ) ) {
            $actions[] = '继续沉淀回测样本，观察趋势是否开始上升。';
        }

        return implode( ' ', array_slice( $actions, 0, 2 ) );
    }

    private static function build_scope_where( $filters ) {
        $clauses = array( '1=1' );
        $params = array();

        $threshold = self::get_threshold_datetime( isset( $filters['days'] ) ? (int) $filters['days'] : 30 );
        $clauses[] = 'updated_at >= %s';
        $params[] = $threshold;

        if ( ! empty( $filters['target_platform'] ) ) {
            $clauses[] = 'target_platform = %s';
            $params[] = (string) $filters['target_platform'];
        }
        if ( ! empty( $filters['target_keyword'] ) ) {
            $clauses[] = 'target_keyword = %s';
            $params[] = (string) $filters['target_keyword'];
        }
        if ( ! empty( $filters['brand_name'] ) ) {
            $clauses[] = 'brand_name = %s';
            $params[] = (string) $filters['brand_name'];
        }

        return array(
            'sql'    => implode( ' AND ', $clauses ),
            'params' => $params,
        );
    }

    private static function prepare_sql( $sql, $params ) {
        global $wpdb;

        if ( empty( $params ) ) {
            return $sql;
        }

        return $wpdb->prepare( $sql, $params );
    }

    private static function get_threshold_datetime( $days ) {
        $days = self::normalize_days( $days );
        return wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS * $days, wp_timezone() );
    }

    private static function get_supported_days() {
        return array( 7, 14, 30, 60, 90 );
    }

    private static function normalize_days( $days ) {
        $days = absint( $days );
        return in_array( $days, self::get_supported_days(), true ) ? $days : 30;
    }

    private static function status_score( $status ) {
        switch ( sanitize_key( (string) $status ) ) {
            case 'recommended':
                return 3;
            case 'mentioned':
                return 2;
            case 'partial':
                return 1;
            case 'absent':
                return 0;
            case 'competitor_led':
                return -1;
            case 'hallucination_risk':
                return -2;
            default:
                return 0;
        }
    }

    private static function humanize_status( $status ) {
        switch ( sanitize_key( (string) $status ) ) {
            case 'recommended':
                return '明确推荐';
            case 'mentioned':
                return '有提及但不强';
            case 'partial':
                return '弱相关擦边';
            case 'absent':
                return '完全未提及';
            case 'competitor_led':
                return '被竞品主导';
            case 'hallucination_risk':
                return '存在幻觉风险';
            default:
                return '未标注';
        }
    }

    private static function get_status_hint( $status ) {
        switch ( sanitize_key( (string) $status ) ) {
            case 'recommended':
                return '这是最理想的状态，说明平台已经愿意把你当作明确答案。';
            case 'mentioned':
                return '已经进入回答视野，但还没拿到足够强的推荐权重。';
            case 'partial':
                return '只是在边缘语境出现，离核心推荐还差一层证据或问法。';
            case 'absent':
                return '平台完全没有提到你，说明当前资料还没有被吸纳进去。';
            case 'competitor_led':
                return '回答被竞品占据主导位，需要补对比理由和优势证据。';
            case 'hallucination_risk':
                return '平台已出现错误归因或夸大，需要先修口径。';
            default:
                return '样本已记录，但还没做明确标注。';
        }
    }

    private static function get_status_order() {
        return array( 'recommended', 'mentioned', 'partial', 'absent', 'competitor_led', 'hallucination_risk', '' );
    }

    private static function get_status_class( $status ) {
        $status = sanitize_key( (string) $status );
        return $status === '' ? 'qigeo-trend-pill--neutral' : 'qigeo-trend-pill--' . $status;
    }

    private static function humanize_trend_signal( $signal ) {
        switch ( sanitize_key( (string) $signal ) ) {
            case 'up':
                return '趋势上升';
            case 'down':
                return '趋势下降';
            case 'new':
                return '样本偏少';
            default:
                return '基本持平';
        }
    }

    private static function get_trend_class( $signal ) {
        switch ( sanitize_key( (string) $signal ) ) {
            case 'up':
                return 'qigeo-trend-pill--up';
            case 'down':
                return 'qigeo-trend-pill--down';
            case 'new':
                return 'qigeo-trend-pill--new';
            default:
                return 'qigeo-trend-pill--flat';
        }
    }

    private static function format_rate( $part, $whole ) {
        $whole = (int) $whole;
        if ( $whole < 1 ) {
            return '0.0';
        }

        return number_format( ( (int) $part / $whole ) * 100, 1, '.', '' );
    }

    private static function get_empty_overview() {
        return array(
            'total_runs'         => 0,
            'distinct_platforms' => 0,
            'distinct_keywords'  => 0,
            'distinct_groups'    => 0,
            'recommended_count'  => 0,
            'positive_count'     => 0,
            'absent_count'       => 0,
            'risk_count'         => 0,
            'recommended_rate'   => '0.0',
            'positive_rate'      => '0.0',
            'absent_rate'        => '0.0',
            'risk_rate'          => '0.0',
        );
    }

    private static function get_empty_status_breakdown() {
        $rows = array();
        foreach ( self::get_status_order() as $status ) {
            $rows[] = array(
                'status'  => $status,
                'count'   => 0,
                'percent' => '0.0',
            );
        }

        return $rows;
    }

    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'qigeo_platform_backtest_records';
    }

    private static function table_exists() {
        global $wpdb;
        $table = self::get_table_name();
        return QiGeo_Admin_Helper::table_exists( $table );
    }

    private static function limit_text( $text, $length = 255 ) {
        $text = trim( (string) $text );
        if ( $text === '' ) {
            return '';
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            return mb_strlen( $text, 'UTF-8' ) <= $length ? $text : trim( mb_substr( $text, 0, $length, 'UTF-8' ) );
        }

        return strlen( $text ) <= $length ? $text : trim( substr( $text, 0, $length ) );
    }
}
