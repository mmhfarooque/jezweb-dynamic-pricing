<?php
/**
 * Analytics & Reporting Class
 *
 * Tracks rule usage, discount amounts, and generates reports.
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JDPD_Analytics Class
 */
class JDPD_Analytics {

    /**
     * Instance
     *
     * @var JDPD_Analytics
     */
    private static $instance = null;

    /**
     * Analytics table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Get instance
     *
     * @return JDPD_Analytics
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'jdpd_analytics';
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Track discount applications
        add_action( 'jdpd_discount_applied', array( $this, 'track_discount' ), 10, 4 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'track_order_completion' ), 10, 1 );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'track_order_created' ), 10, 3 );

        // Cleanup old data
        add_action( 'jdpd_daily_cleanup', array( $this, 'cleanup_old_data' ) );
    }

    /**
     * Create analytics table
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'jdpd_analytics';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) unsigned NOT NULL,
            rule_name varchar(255) NOT NULL,
            rule_type varchar(50) NOT NULL,
            event_type varchar(50) NOT NULL DEFAULT 'discount_applied',
            discount_amount decimal(10,2) NOT NULL DEFAULT 0,
            discount_type varchar(20) NOT NULL DEFAULT 'percentage',
            original_price decimal(10,2) NOT NULL DEFAULT 0,
            discounted_price decimal(10,2) NOT NULL DEFAULT 0,
            product_id bigint(20) unsigned DEFAULT NULL,
            product_name varchar(255) DEFAULT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            customer_id bigint(20) unsigned DEFAULT NULL,
            session_id varchar(100) DEFAULT NULL,
            converted tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY rule_id (rule_id),
            KEY event_type (event_type),
            KEY order_id (order_id),
            KEY customer_id (customer_id),
            KEY created_at (created_at),
            KEY rule_type (rule_type)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Create daily stats summary table for faster queries
        $summary_table = $wpdb->prefix . 'jdpd_analytics_summary';
        $sql_summary = "CREATE TABLE IF NOT EXISTS $summary_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) unsigned NOT NULL,
            date date NOT NULL,
            applications int(11) NOT NULL DEFAULT 0,
            conversions int(11) NOT NULL DEFAULT 0,
            total_discount decimal(12,2) NOT NULL DEFAULT 0,
            total_revenue decimal(12,2) NOT NULL DEFAULT 0,
            unique_customers int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY rule_date (rule_id, date),
            KEY date (date),
            KEY rule_id (rule_id)
        ) $charset_collate;";

        dbDelta( $sql_summary );
    }

    /**
     * Track when a discount is applied
     *
     * @param int    $rule_id         Rule ID.
     * @param float  $discount_amount Discount amount.
     * @param array  $context         Additional context.
     * @param object $rule            Rule object.
     */
    public function track_discount( $rule_id, $discount_amount, $context = array(), $rule = null ) {
        global $wpdb;

        $rule_obj = $rule ?? new JDPD_Rule( $rule_id );

        $data = array(
            'rule_id'          => $rule_id,
            'rule_name'        => $rule_obj->get( 'name' ),
            'rule_type'        => $rule_obj->get( 'rule_type' ),
            'event_type'       => 'discount_applied',
            'discount_amount'  => $discount_amount,
            'discount_type'    => $rule_obj->get( 'discount_type' ),
            'original_price'   => isset( $context['original_price'] ) ? $context['original_price'] : 0,
            'discounted_price' => isset( $context['discounted_price'] ) ? $context['discounted_price'] : 0,
            'product_id'       => isset( $context['product_id'] ) ? $context['product_id'] : null,
            'product_name'     => isset( $context['product_name'] ) ? $context['product_name'] : null,
            'order_id'         => isset( $context['order_id'] ) ? $context['order_id'] : null,
            'customer_id'      => get_current_user_id() ?: null,
            'session_id'       => $this->get_session_id(),
            'converted'        => 0,
            'created_at'       => current_time( 'mysql' ),
        );

        $wpdb->insert(
            $this->table_name,
            $data,
            array( '%d', '%s', '%s', '%s', '%f', '%s', '%f', '%f', '%d', '%s', '%d', '%d', '%s', '%d', '%s' )
        );

        // Update daily summary
        $this->update_daily_summary( $rule_id, $discount_amount );
    }

    /**
     * Track order completion (conversion)
     *
     * @param int $order_id Order ID.
     */
    public function track_order_completion( $order_id ) {
        global $wpdb;

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Get discounts applied to this order
        $applied_rules = $order->get_meta( '_jdpd_applied_rules' );
        if ( empty( $applied_rules ) ) {
            return;
        }

        // Mark as converted
        foreach ( $applied_rules as $rule_data ) {
            $wpdb->update(
                $this->table_name,
                array( 'converted' => 1 ),
                array(
                    'rule_id'  => $rule_data['rule_id'],
                    'order_id' => $order_id,
                ),
                array( '%d' ),
                array( '%d', '%d' )
            );

            // Update conversion in daily summary
            $this->update_daily_summary( $rule_data['rule_id'], 0, true, $order->get_total() );
        }
    }

    /**
     * Track order created
     *
     * @param int      $order_id Order ID.
     * @param array    $posted_data Posted data.
     * @param WC_Order $order Order object.
     */
    public function track_order_created( $order_id, $posted_data, $order ) {
        // Store applied rules in order meta for later tracking
        $applied_rules = WC()->session->get( 'jdpd_applied_rules', array() );
        if ( ! empty( $applied_rules ) ) {
            $order->update_meta_data( '_jdpd_applied_rules', $applied_rules );
            $order->save();
        }
    }

    /**
     * Update daily summary
     *
     * @param int   $rule_id         Rule ID.
     * @param float $discount_amount Discount amount.
     * @param bool  $is_conversion   Is this a conversion.
     * @param float $revenue         Revenue amount.
     */
    private function update_daily_summary( $rule_id, $discount_amount = 0, $is_conversion = false, $revenue = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'jdpd_analytics_summary';
        $date = current_time( 'Y-m-d' );

        // Check if record exists
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE rule_id = %d AND date = %s",
            $rule_id,
            $date
        ) );

        if ( $existing ) {
            $update_data = array();
            $update_format = array();

            if ( ! $is_conversion ) {
                $update_data['applications'] = $existing->applications + 1;
                $update_data['total_discount'] = $existing->total_discount + $discount_amount;
                $update_format[] = '%d';
                $update_format[] = '%f';
            } else {
                $update_data['conversions'] = $existing->conversions + 1;
                $update_data['total_revenue'] = $existing->total_revenue + $revenue;
                $update_format[] = '%d';
                $update_format[] = '%f';
            }

            $wpdb->update(
                $table,
                $update_data,
                array( 'id' => $existing->id ),
                $update_format,
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'rule_id'          => $rule_id,
                    'date'             => $date,
                    'applications'     => $is_conversion ? 0 : 1,
                    'conversions'      => $is_conversion ? 1 : 0,
                    'total_discount'   => $discount_amount,
                    'total_revenue'    => $revenue,
                    'unique_customers' => get_current_user_id() ? 1 : 0,
                ),
                array( '%d', '%s', '%d', '%d', '%f', '%f', '%d' )
            );
        }
    }

    /**
     * Get session ID
     *
     * @return string
     */
    private function get_session_id() {
        if ( WC()->session ) {
            return WC()->session->get_customer_id();
        }
        return wp_generate_uuid4();
    }

    /**
     * Get analytics data for dashboard
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_dashboard_data( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'date_from' => date( 'Y-m-d', strtotime( '-30 days' ) ),
            'date_to'   => date( 'Y-m-d' ),
            'rule_id'   => null,
            'rule_type' => null,
        );

        $args = wp_parse_args( $args, $defaults );
        $summary_table = $wpdb->prefix . 'jdpd_analytics_summary';

        $where = array( '1=1' );
        $values = array();

        $where[] = 'date >= %s';
        $values[] = $args['date_from'];

        $where[] = 'date <= %s';
        $values[] = $args['date_to'];

        if ( $args['rule_id'] ) {
            $where[] = 'rule_id = %d';
            $values[] = $args['rule_id'];
        }

        $where_clause = implode( ' AND ', $where );

        // Get totals
        $totals = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(applications) as total_applications,
                SUM(conversions) as total_conversions,
                SUM(total_discount) as total_discount,
                SUM(total_revenue) as total_revenue,
                COUNT(DISTINCT rule_id) as active_rules
            FROM $summary_table
            WHERE $where_clause",
            $values
        ) );

        // Get daily data for chart
        $daily_data = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                date,
                SUM(applications) as applications,
                SUM(conversions) as conversions,
                SUM(total_discount) as discount,
                SUM(total_revenue) as revenue
            FROM $summary_table
            WHERE $where_clause
            GROUP BY date
            ORDER BY date ASC",
            $values
        ) );

        // Get top performing rules
        $top_rules = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                rule_id,
                SUM(applications) as applications,
                SUM(conversions) as conversions,
                SUM(total_discount) as total_discount,
                SUM(total_revenue) as revenue,
                CASE WHEN SUM(applications) > 0
                    THEN (SUM(conversions) / SUM(applications)) * 100
                    ELSE 0
                END as conversion_rate
            FROM $summary_table
            WHERE $where_clause
            GROUP BY rule_id
            ORDER BY applications DESC
            LIMIT 10",
            $values
        ) );

        // Add rule names
        foreach ( $top_rules as &$rule_data ) {
            $rule = new JDPD_Rule( $rule_data->rule_id );
            $rule_data->rule_name = $rule->get( 'name' );
            $rule_data->rule_type = $rule->get( 'rule_type' );
        }

        // Get rule type breakdown
        $type_breakdown = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                r.rule_type,
                SUM(s.applications) as applications,
                SUM(s.conversions) as conversions,
                SUM(s.total_discount) as total_discount
            FROM $summary_table s
            JOIN {$wpdb->prefix}jdpd_rules r ON s.rule_id = r.id
            WHERE s.date >= %s AND s.date <= %s
            GROUP BY r.rule_type",
            $args['date_from'],
            $args['date_to']
        ) );

        return array(
            'totals'         => $totals,
            'daily_data'     => $daily_data,
            'top_rules'      => $top_rules,
            'type_breakdown' => $type_breakdown,
            'date_range'     => array(
                'from' => $args['date_from'],
                'to'   => $args['date_to'],
            ),
        );
    }

    /**
     * Get rule statistics
     *
     * @param int $rule_id Rule ID.
     * @return array
     */
    public function get_rule_stats( $rule_id ) {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'jdpd_analytics_summary';

        // All time stats
        $all_time = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(applications) as total_applications,
                SUM(conversions) as total_conversions,
                SUM(total_discount) as total_discount,
                SUM(total_revenue) as total_revenue,
                MIN(date) as first_used,
                MAX(date) as last_used
            FROM $summary_table
            WHERE rule_id = %d",
            $rule_id
        ) );

        // Last 7 days
        $last_7_days = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(applications) as applications,
                SUM(conversions) as conversions,
                SUM(total_discount) as discount
            FROM $summary_table
            WHERE rule_id = %d AND date >= %s",
            $rule_id,
            date( 'Y-m-d', strtotime( '-7 days' ) )
        ) );

        // Last 30 days
        $last_30_days = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(applications) as applications,
                SUM(conversions) as conversions,
                SUM(total_discount) as discount
            FROM $summary_table
            WHERE rule_id = %d AND date >= %s",
            $rule_id,
            date( 'Y-m-d', strtotime( '-30 days' ) )
        ) );

        // Calculate conversion rate
        $conversion_rate = 0;
        if ( $all_time && $all_time->total_applications > 0 ) {
            $conversion_rate = ( $all_time->total_conversions / $all_time->total_applications ) * 100;
        }

        return array(
            'all_time'        => $all_time,
            'last_7_days'     => $last_7_days,
            'last_30_days'    => $last_30_days,
            'conversion_rate' => round( $conversion_rate, 2 ),
        );
    }

    /**
     * Export analytics data
     *
     * @param array  $args   Query arguments.
     * @param string $format Export format (csv, json).
     * @return string
     */
    public function export_data( $args = array(), $format = 'csv' ) {
        global $wpdb;

        $defaults = array(
            'date_from' => date( 'Y-m-d', strtotime( '-30 days' ) ),
            'date_to'   => date( 'Y-m-d' ),
        );

        $args = wp_parse_args( $args, $defaults );

        $data = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                a.*,
                r.name as rule_name,
                r.rule_type
            FROM {$this->table_name} a
            LEFT JOIN {$wpdb->prefix}jdpd_rules r ON a.rule_id = r.id
            WHERE a.created_at >= %s AND a.created_at <= %s
            ORDER BY a.created_at DESC",
            $args['date_from'] . ' 00:00:00',
            $args['date_to'] . ' 23:59:59'
        ), ARRAY_A );

        if ( 'json' === $format ) {
            return wp_json_encode( $data, JSON_PRETTY_PRINT );
        }

        // CSV format
        if ( empty( $data ) ) {
            return '';
        }

        $csv = array();
        $csv[] = implode( ',', array_keys( $data[0] ) );

        foreach ( $data as $row ) {
            $csv[] = implode( ',', array_map( function( $value ) {
                return '"' . str_replace( '"', '""', $value ) . '"';
            }, $row ) );
        }

        return implode( "\n", $csv );
    }

    /**
     * Cleanup old data
     */
    public function cleanup_old_data() {
        global $wpdb;

        // Keep detailed analytics for 90 days
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE created_at < %s",
            date( 'Y-m-d', strtotime( '-90 days' ) )
        ) );

        // Keep summary data for 365 days
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}jdpd_analytics_summary WHERE date < %s",
            date( 'Y-m-d', strtotime( '-365 days' ) )
        ) );
    }
}

/**
 * Get analytics instance
 *
 * @return JDPD_Analytics
 */
function jdpd_analytics() {
    static $instance = null;
    if ( null === $instance ) {
        $instance = new JDPD_Analytics();
    }
    return $instance;
}
