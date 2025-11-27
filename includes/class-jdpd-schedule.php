<?php
/**
 * Schedule Handler
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Schedule class
 */
class JDPD_Schedule {

    /**
     * Constructor
     */
    public function __construct() {
        // Schedule daily cleanup
        add_action( 'jdpd_daily_cleanup', array( $this, 'daily_cleanup' ) );

        // Schedule rule status checks
        add_action( 'jdpd_check_scheduled_rules', array( $this, 'check_scheduled_rules' ) );

        // Schedule the hourly check if not already scheduled
        if ( ! wp_next_scheduled( 'jdpd_check_scheduled_rules' ) ) {
            wp_schedule_event( time(), 'hourly', 'jdpd_check_scheduled_rules' );
        }
    }

    /**
     * Daily cleanup tasks
     */
    public function daily_cleanup() {
        // Clean up expired rules (optional - just deactivate)
        $this->deactivate_expired_rules();

        // Clean up orphaned data
        $this->cleanup_orphaned_data();

        // Clear transients
        $this->clear_transients();
    }

    /**
     * Check scheduled rules and update their status
     */
    public function check_scheduled_rules() {
        global $wpdb;

        $table = $wpdb->prefix . 'jdpd_rules';
        $now = current_time( 'mysql' );

        // Activate rules that should start
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table
                SET status = 'active'
                WHERE status = 'scheduled'
                AND schedule_from IS NOT NULL
                AND schedule_from <= %s
                AND (schedule_to IS NULL OR schedule_to >= %s)",
                $now,
                $now
            )
        );

        // Deactivate rules that have ended
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table
                SET status = 'expired'
                WHERE status = 'active'
                AND schedule_to IS NOT NULL
                AND schedule_to < %s",
                $now
            )
        );
    }

    /**
     * Deactivate expired rules
     */
    private function deactivate_expired_rules() {
        global $wpdb;

        $table = $wpdb->prefix . 'jdpd_rules';
        $now = current_time( 'mysql' );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table
                SET status = 'expired'
                WHERE schedule_to IS NOT NULL
                AND schedule_to < %s
                AND status = 'active'",
                $now
            )
        );
    }

    /**
     * Clean up orphaned data
     */
    private function cleanup_orphaned_data() {
        global $wpdb;

        $rules_table = $wpdb->prefix . 'jdpd_rules';

        // Get all rule IDs
        $rule_ids = $wpdb->get_col( "SELECT id FROM $rules_table" );

        if ( empty( $rule_ids ) ) {
            // Delete all related data if no rules exist
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}jdpd_quantity_ranges" );
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}jdpd_rule_items" );
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}jdpd_exclusions" );
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}jdpd_gift_products" );
            return;
        }

        $ids_placeholder = implode( ',', array_fill( 0, count( $rule_ids ), '%d' ) );

        // Clean quantity ranges
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}jdpd_quantity_ranges WHERE rule_id NOT IN ($ids_placeholder)",
                $rule_ids
            )
        );

        // Clean rule items
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}jdpd_rule_items WHERE rule_id NOT IN ($ids_placeholder)",
                $rule_ids
            )
        );

        // Clean exclusions
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}jdpd_exclusions WHERE rule_id NOT IN ($ids_placeholder)",
                $rule_ids
            )
        );

        // Clean gift products
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}jdpd_gift_products WHERE rule_id NOT IN ($ids_placeholder)",
                $rule_ids
            )
        );
    }

    /**
     * Clear transients
     */
    private function clear_transients() {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_jdpd_%'
            OR option_name LIKE '_transient_timeout_jdpd_%'"
        );
    }

    /**
     * Check if a rule is within its schedule
     *
     * @param JDPD_Rule $rule Rule object.
     * @return bool
     */
    public function is_rule_scheduled_active( $rule ) {
        $now = current_time( 'mysql' );

        $from = $rule->get( 'schedule_from' );
        $to = $rule->get( 'schedule_to' );

        // No schedule set = always active
        if ( empty( $from ) && empty( $to ) ) {
            return true;
        }

        // Check start date
        if ( ! empty( $from ) && $now < $from ) {
            return false;
        }

        // Check end date
        if ( ! empty( $to ) && $now > $to ) {
            return false;
        }

        return true;
    }

    /**
     * Get time until rule starts
     *
     * @param JDPD_Rule $rule Rule object.
     * @return int|null Seconds until start, or null if already started/no schedule.
     */
    public function get_time_until_start( $rule ) {
        $from = $rule->get( 'schedule_from' );

        if ( empty( $from ) ) {
            return null;
        }

        $now = current_time( 'timestamp' );
        $start = strtotime( $from );

        if ( $start <= $now ) {
            return null;
        }

        return $start - $now;
    }

    /**
     * Get time until rule ends
     *
     * @param JDPD_Rule $rule Rule object.
     * @return int|null Seconds until end, or null if no end date.
     */
    public function get_time_until_end( $rule ) {
        $to = $rule->get( 'schedule_to' );

        if ( empty( $to ) ) {
            return null;
        }

        $now = current_time( 'timestamp' );
        $end = strtotime( $to );

        if ( $end <= $now ) {
            return 0;
        }

        return $end - $now;
    }

    /**
     * Get upcoming rules
     *
     * @param int $limit Number of rules to return.
     * @return array
     */
    public function get_upcoming_rules( $limit = 10 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'jdpd_rules';
        $now = current_time( 'mysql' );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table
                WHERE schedule_from IS NOT NULL
                AND schedule_from > %s
                ORDER BY schedule_from ASC
                LIMIT %d",
                $now,
                $limit
            )
        );
    }

    /**
     * Get expiring rules
     *
     * @param int $days Number of days to look ahead.
     * @return array
     */
    public function get_expiring_rules( $days = 7 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'jdpd_rules';
        $now = current_time( 'mysql' );
        $future = date( 'Y-m-d H:i:s', strtotime( "+$days days" ) );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table
                WHERE status = 'active'
                AND schedule_to IS NOT NULL
                AND schedule_to BETWEEN %s AND %s
                ORDER BY schedule_to ASC",
                $now,
                $future
            )
        );
    }
}
