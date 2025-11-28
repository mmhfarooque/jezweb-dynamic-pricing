<?php
/**
 * Analytics Dashboard View
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rule_types = jdpd_get_rule_types();
?>

<div class="wrap jdpd-analytics-wrap">
    <!-- Jezweb Branded Header -->
    <div class="jdpd-page-header">
        <h1>
            <img src="https://www.jezweb.com.au/wp-content/uploads/2021/11/Jezweb-Logo-White-Transparent.svg" alt="Jezweb" class="jdpd-header-logo">
            <?php esc_html_e( 'Analytics Dashboard', 'jezweb-dynamic-pricing' ); ?>
            <span class="jdpd-version-badge">v<?php echo esc_html( JDPD_VERSION ); ?></span>
        </h1>
    </div>

    <!-- Date Range Filter -->
    <div class="jdpd-analytics-filters">
        <form method="get" class="jdpd-date-filter-form">
            <input type="hidden" name="page" value="jdpd-analytics">

            <div class="jdpd-filter-group">
                <label for="date_from"><?php esc_html_e( 'From:', 'jezweb-dynamic-pricing' ); ?></label>
                <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
            </div>

            <div class="jdpd-filter-group">
                <label for="date_to"><?php esc_html_e( 'To:', 'jezweb-dynamic-pricing' ); ?></label>
                <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
            </div>

            <div class="jdpd-filter-group jdpd-quick-ranges">
                <button type="button" class="button jdpd-quick-range" data-range="7"><?php esc_html_e( '7 Days', 'jezweb-dynamic-pricing' ); ?></button>
                <button type="button" class="button jdpd-quick-range" data-range="30"><?php esc_html_e( '30 Days', 'jezweb-dynamic-pricing' ); ?></button>
                <button type="button" class="button jdpd-quick-range" data-range="90"><?php esc_html_e( '90 Days', 'jezweb-dynamic-pricing' ); ?></button>
            </div>

            <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'jezweb-dynamic-pricing' ); ?></button>

            <div class="jdpd-export-buttons">
                <button type="button" class="button jdpd-export-btn" data-format="csv">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e( 'Export CSV', 'jezweb-dynamic-pricing' ); ?>
                </button>
                <button type="button" class="button jdpd-export-btn" data-format="json">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e( 'Export JSON', 'jezweb-dynamic-pricing' ); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="jdpd-analytics-summary">
        <div class="jdpd-summary-card jdpd-card-applications">
            <div class="jdpd-card-icon">
                <span class="dashicons dashicons-tag"></span>
            </div>
            <div class="jdpd-card-content">
                <span class="jdpd-card-value"><?php echo esc_html( number_format( $data['totals']->total_applications ?? 0 ) ); ?></span>
                <span class="jdpd-card-label"><?php esc_html_e( 'Discounts Applied', 'jezweb-dynamic-pricing' ); ?></span>
            </div>
        </div>

        <div class="jdpd-summary-card jdpd-card-conversions">
            <div class="jdpd-card-icon">
                <span class="dashicons dashicons-cart"></span>
            </div>
            <div class="jdpd-card-content">
                <span class="jdpd-card-value"><?php echo esc_html( number_format( $data['totals']->total_conversions ?? 0 ) ); ?></span>
                <span class="jdpd-card-label"><?php esc_html_e( 'Conversions', 'jezweb-dynamic-pricing' ); ?></span>
            </div>
        </div>

        <div class="jdpd-summary-card jdpd-card-discount">
            <div class="jdpd-card-icon">
                <span class="dashicons dashicons-money-alt"></span>
            </div>
            <div class="jdpd-card-content">
                <span class="jdpd-card-value"><?php echo wc_price( $data['totals']->total_discount ?? 0 ); ?></span>
                <span class="jdpd-card-label"><?php esc_html_e( 'Total Discounts Given', 'jezweb-dynamic-pricing' ); ?></span>
            </div>
        </div>

        <div class="jdpd-summary-card jdpd-card-revenue">
            <div class="jdpd-card-icon">
                <span class="dashicons dashicons-chart-area"></span>
            </div>
            <div class="jdpd-card-content">
                <span class="jdpd-card-value"><?php echo wc_price( $data['totals']->total_revenue ?? 0 ); ?></span>
                <span class="jdpd-card-label"><?php esc_html_e( 'Revenue from Discounts', 'jezweb-dynamic-pricing' ); ?></span>
            </div>
        </div>

        <div class="jdpd-summary-card jdpd-card-rate">
            <div class="jdpd-card-icon">
                <span class="dashicons dashicons-performance"></span>
            </div>
            <div class="jdpd-card-content">
                <?php
                $conversion_rate = 0;
                if ( ! empty( $data['totals']->total_applications ) && $data['totals']->total_applications > 0 ) {
                    $conversion_rate = ( $data['totals']->total_conversions / $data['totals']->total_applications ) * 100;
                }
                ?>
                <span class="jdpd-card-value"><?php echo esc_html( number_format( $conversion_rate, 1 ) ); ?>%</span>
                <span class="jdpd-card-label"><?php esc_html_e( 'Conversion Rate', 'jezweb-dynamic-pricing' ); ?></span>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="jdpd-charts-row">
        <!-- Main Chart -->
        <div class="jdpd-chart-container jdpd-chart-main">
            <div class="jdpd-chart-header">
                <h3><?php esc_html_e( 'Discount Activity Over Time', 'jezweb-dynamic-pricing' ); ?></h3>
                <div class="jdpd-chart-legend">
                    <span class="jdpd-legend-item jdpd-legend-applications">
                        <span class="jdpd-legend-color"></span>
                        <?php esc_html_e( 'Applications', 'jezweb-dynamic-pricing' ); ?>
                    </span>
                    <span class="jdpd-legend-item jdpd-legend-conversions">
                        <span class="jdpd-legend-color"></span>
                        <?php esc_html_e( 'Conversions', 'jezweb-dynamic-pricing' ); ?>
                    </span>
                </div>
            </div>
            <canvas id="jdpd-activity-chart" height="300"></canvas>
        </div>

        <!-- Pie Chart -->
        <div class="jdpd-chart-container jdpd-chart-pie">
            <div class="jdpd-chart-header">
                <h3><?php esc_html_e( 'Discounts by Rule Type', 'jezweb-dynamic-pricing' ); ?></h3>
            </div>
            <canvas id="jdpd-type-chart" height="250"></canvas>
        </div>
    </div>

    <!-- Top Rules Table -->
    <div class="jdpd-top-rules">
        <div class="jdpd-section-header">
            <h3><?php esc_html_e( 'Top Performing Rules', 'jezweb-dynamic-pricing' ); ?></h3>
        </div>

        <table class="jdpd-analytics-table widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Rule Name', 'jezweb-dynamic-pricing' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'jezweb-dynamic-pricing' ); ?></th>
                    <th><?php esc_html_e( 'Applications', 'jezweb-dynamic-pricing' ); ?></th>
                    <th><?php esc_html_e( 'Conversions', 'jezweb-dynamic-pricing' ); ?></th>
                    <th><?php esc_html_e( 'Conversion Rate', 'jezweb-dynamic-pricing' ); ?></th>
                    <th><?php esc_html_e( 'Total Discount', 'jezweb-dynamic-pricing' ); ?></th>
                    <th><?php esc_html_e( 'Revenue', 'jezweb-dynamic-pricing' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $data['top_rules'] ) ) : ?>
                    <?php foreach ( $data['top_rules'] as $rule ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=jdpd-add-rule&rule_id=' . $rule->rule_id ) ); ?>">
                                    <?php echo esc_html( $rule->rule_name ); ?>
                                </a>
                            </td>
                            <td>
                                <span class="jdpd-badge jdpd-badge-<?php echo esc_attr( $rule->rule_type ); ?>">
                                    <?php echo esc_html( $rule_types[ $rule->rule_type ] ?? $rule->rule_type ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( number_format( $rule->applications ) ); ?></td>
                            <td><?php echo esc_html( number_format( $rule->conversions ) ); ?></td>
                            <td>
                                <div class="jdpd-progress-bar">
                                    <div class="jdpd-progress-fill" style="width: <?php echo esc_attr( min( $rule->conversion_rate, 100 ) ); ?>%"></div>
                                    <span class="jdpd-progress-text"><?php echo esc_html( number_format( $rule->conversion_rate, 1 ) ); ?>%</span>
                                </div>
                            </td>
                            <td><?php echo wc_price( $rule->total_discount ); ?></td>
                            <td><?php echo wc_price( $rule->revenue ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="7" class="jdpd-no-data">
                            <?php esc_html_e( 'No analytics data available for the selected period.', 'jezweb-dynamic-pricing' ); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Jezweb Credits Footer -->
    <div class="jdpd-credits">
        <div class="jdpd-credits-logo">
            <img src="https://www.jezweb.com.au/wp-content/uploads/2023/05/Jezweb-logo-1.png" alt="Jezweb" style="height: 50px; width: auto;">
            <div class="jdpd-credits-text">
                <span class="jdpd-credits-title"><?php esc_html_e( 'Developed by Jezweb', 'jezweb-dynamic-pricing' ); ?></span>
                <span class="jdpd-credits-subtitle"><?php esc_html_e( 'Web Design & Digital Marketing Agency', 'jezweb-dynamic-pricing' ); ?></span>
            </div>
        </div>
        <div class="jdpd-credits-links">
            <a href="https://jezweb.com.au" target="_blank" class="jdpd-btn-primary">
                <span class="dashicons dashicons-admin-site-alt3"></span>
                <?php esc_html_e( 'Visit Jezweb', 'jezweb-dynamic-pricing' ); ?>
            </a>
            <a href="https://jezweb.com.au/contact/" target="_blank" class="jdpd-btn-secondary">
                <span class="dashicons dashicons-email-alt"></span>
                <?php esc_html_e( 'Contact Us', 'jezweb-dynamic-pricing' ); ?>
            </a>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Chart data from PHP
    var dailyData = <?php echo wp_json_encode( $data['daily_data'] ); ?>;
    var typeBreakdown = <?php echo wp_json_encode( $data['type_breakdown'] ); ?>;
    var ruleTypes = <?php echo wp_json_encode( $rule_types ); ?>;

    // Activity Chart
    var activityCtx = document.getElementById('jdpd-activity-chart');
    if (activityCtx && dailyData.length > 0) {
        new Chart(activityCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: dailyData.map(function(d) { return d.date; }),
                datasets: [
                    {
                        label: '<?php echo esc_js( __( 'Applications', 'jezweb-dynamic-pricing' ) ); ?>',
                        data: dailyData.map(function(d) { return parseInt(d.applications) || 0; }),
                        borderColor: '#22588d',
                        backgroundColor: 'rgba(34, 88, 141, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: '<?php echo esc_js( __( 'Conversions', 'jezweb-dynamic-pricing' ) ); ?>',
                        data: dailyData.map(function(d) { return parseInt(d.conversions) || 0; }),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }

    // Type Breakdown Pie Chart
    var typeCtx = document.getElementById('jdpd-type-chart');
    if (typeCtx && typeBreakdown.length > 0) {
        var colors = ['#22588d', '#d83a34', '#10b981', '#f59e0b'];
        new Chart(typeCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: typeBreakdown.map(function(d) { return ruleTypes[d.rule_type] || d.rule_type; }),
                datasets: [{
                    data: typeBreakdown.map(function(d) { return parseInt(d.applications) || 0; }),
                    backgroundColor: colors.slice(0, typeBreakdown.length),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    // Quick date range buttons
    document.querySelectorAll('.jdpd-quick-range').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var days = parseInt(this.dataset.range);
            var today = new Date();
            var fromDate = new Date(today);
            fromDate.setDate(today.getDate() - days);

            document.getElementById('date_from').value = fromDate.toISOString().split('T')[0];
            document.getElementById('date_to').value = today.toISOString().split('T')[0];
        });
    });

    // Export buttons
    document.querySelectorAll('.jdpd-export-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var format = this.dataset.format;
            var dateFrom = document.getElementById('date_from').value;
            var dateTo = document.getElementById('date_to').value;

            jQuery.post(ajaxurl, {
                action: 'jdpd_export_analytics',
                nonce: jdpd_admin.nonce,
                date_from: dateFrom,
                date_to: dateTo,
                format: format
            }, function(response) {
                if (response.success) {
                    var blob = new Blob([response.data.data], { type: format === 'json' ? 'application/json' : 'text/csv' });
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = response.data.filename;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    a.remove();
                }
            });
        });
    });
});
</script>

<style>
/* Analytics Dashboard Styles */
.jdpd-analytics-wrap {
    max-width: 1600px;
}

.jdpd-analytics-filters {
    background: var(--jdpd-card-bg);
    padding: 20px;
    border-radius: var(--jdpd-radius-lg);
    margin-bottom: 24px;
    box-shadow: var(--jdpd-shadow);
    border: 1px solid var(--jdpd-border);
}

.jdpd-date-filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: center;
}

.jdpd-filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.jdpd-filter-group label {
    font-weight: 600;
    color: var(--jdpd-text);
}

.jdpd-filter-group input[type="date"] {
    padding: 8px 12px;
    border: 1px solid var(--jdpd-border);
    border-radius: var(--jdpd-radius);
}

.jdpd-quick-ranges {
    display: flex;
    gap: 8px;
}

.jdpd-export-buttons {
    margin-left: auto;
    display: flex;
    gap: 8px;
}

/* Summary Cards */
.jdpd-analytics-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.jdpd-summary-card {
    background: var(--jdpd-card-bg);
    border-radius: var(--jdpd-radius-lg);
    padding: 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: var(--jdpd-shadow);
    border: 1px solid var(--jdpd-border);
    transition: var(--jdpd-transition);
}

.jdpd-summary-card:hover {
    box-shadow: var(--jdpd-shadow-md);
    transform: translateY(-2px);
}

.jdpd-card-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.jdpd-card-icon .dashicons {
    font-size: 28px;
    width: 28px;
    height: 28px;
    color: white;
}

.jdpd-card-applications .jdpd-card-icon { background: linear-gradient(135deg, #22588d, #005590); }
.jdpd-card-conversions .jdpd-card-icon { background: linear-gradient(135deg, #10b981, #059669); }
.jdpd-card-discount .jdpd-card-icon { background: linear-gradient(135deg, #d83a34, #b52e29); }
.jdpd-card-revenue .jdpd-card-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
.jdpd-card-rate .jdpd-card-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }

.jdpd-card-content {
    display: flex;
    flex-direction: column;
}

.jdpd-card-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--jdpd-text);
    line-height: 1.2;
}

.jdpd-card-label {
    font-size: 13px;
    color: var(--jdpd-text-muted);
    margin-top: 4px;
}

/* Charts */
.jdpd-charts-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

@media (max-width: 1200px) {
    .jdpd-charts-row {
        grid-template-columns: 1fr;
    }
}

.jdpd-chart-container {
    background: var(--jdpd-card-bg);
    border-radius: var(--jdpd-radius-lg);
    padding: 24px;
    box-shadow: var(--jdpd-shadow);
    border: 1px solid var(--jdpd-border);
}

.jdpd-chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.jdpd-chart-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--jdpd-text);
}

.jdpd-chart-legend {
    display: flex;
    gap: 16px;
}

.jdpd-legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--jdpd-text-muted);
}

.jdpd-legend-color {
    width: 12px;
    height: 12px;
    border-radius: 3px;
}

.jdpd-legend-applications .jdpd-legend-color { background: #22588d; }
.jdpd-legend-conversions .jdpd-legend-color { background: #10b981; }

/* Top Rules Table */
.jdpd-top-rules {
    background: var(--jdpd-card-bg);
    border-radius: var(--jdpd-radius-lg);
    padding: 24px;
    box-shadow: var(--jdpd-shadow);
    border: 1px solid var(--jdpd-border);
    margin-bottom: 24px;
}

.jdpd-section-header h3 {
    margin: 0 0 20px;
    font-size: 16px;
    font-weight: 600;
    color: var(--jdpd-text);
}

.jdpd-analytics-table {
    border: none;
    box-shadow: none;
}

.jdpd-analytics-table th {
    background: var(--jdpd-bg);
    font-weight: 600;
    color: var(--jdpd-text);
    padding: 12px 16px;
    border-bottom: 2px solid var(--jdpd-border);
}

.jdpd-analytics-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--jdpd-border-light);
    vertical-align: middle;
}

.jdpd-analytics-table tr:hover td {
    background: var(--jdpd-bg);
}

.jdpd-analytics-table a {
    color: var(--jdpd-primary);
    font-weight: 500;
    text-decoration: none;
}

.jdpd-analytics-table a:hover {
    color: var(--jdpd-primary-dark);
}

/* Progress Bar */
.jdpd-progress-bar {
    position: relative;
    width: 100px;
    height: 24px;
    background: var(--jdpd-bg);
    border-radius: 12px;
    overflow: hidden;
}

.jdpd-progress-fill {
    position: absolute;
    top: 0;
    left: 0;
    height: 100%;
    background: linear-gradient(90deg, #22588d, #3a7ab8);
    border-radius: 12px;
    transition: width 0.3s ease;
}

.jdpd-progress-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 11px;
    font-weight: 600;
    color: var(--jdpd-text);
    z-index: 1;
}

.jdpd-no-data {
    text-align: center;
    color: var(--jdpd-text-muted);
    padding: 40px !important;
}
</style>
