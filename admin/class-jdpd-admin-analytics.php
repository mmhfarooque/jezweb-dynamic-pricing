<?php
/**
 * Admin Analytics Dashboard
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JDPD_Admin_Analytics Class
 */
class JDPD_Admin_Analytics {

    /**
     * Instance
     *
     * @var JDPD_Admin_Analytics
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return JDPD_Admin_Analytics
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
        add_action( 'wp_ajax_jdpd_get_analytics', array( $this, 'ajax_get_analytics' ) );
        add_action( 'wp_ajax_jdpd_export_analytics', array( $this, 'ajax_export_analytics' ) );
    }

    /**
     * Render analytics page
     */
    public function render_page() {
        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : date( 'Y-m-d', strtotime( '-30 days' ) );
        $date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : date( 'Y-m-d' );

        $analytics = jdpd_analytics();
        $data = $analytics->get_dashboard_data( array(
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ) );

        include JDPD_PLUGIN_PATH . 'admin/views/analytics-dashboard.php';
    }

    /**
     * AJAX handler for getting analytics
     */
    public function ajax_get_analytics() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( $_POST['date_from'] ) : date( 'Y-m-d', strtotime( '-30 days' ) );
        $date_to = isset( $_POST['date_to'] ) ? sanitize_text_field( $_POST['date_to'] ) : date( 'Y-m-d' );
        $rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : null;

        $analytics = jdpd_analytics();
        $data = $analytics->get_dashboard_data( array(
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'rule_id'   => $rule_id,
        ) );

        wp_send_json_success( $data );
    }

    /**
     * AJAX handler for exporting analytics
     */
    public function ajax_export_analytics() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( $_POST['date_from'] ) : date( 'Y-m-d', strtotime( '-30 days' ) );
        $date_to = isset( $_POST['date_to'] ) ? sanitize_text_field( $_POST['date_to'] ) : date( 'Y-m-d' );
        $format = isset( $_POST['format'] ) ? sanitize_key( $_POST['format'] ) : 'csv';

        $analytics = jdpd_analytics();
        $export_data = $analytics->export_data( array(
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ), $format );

        wp_send_json_success( array(
            'data'     => $export_data,
            'filename' => 'jdpd-analytics-' . $date_from . '-to-' . $date_to . '.' . $format,
        ) );
    }
}
