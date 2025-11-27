<?php
/**
 * Admin Order Meta
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin Order Meta class
 */
class JDPD_Admin_Order_Meta {

    /**
     * Constructor
     */
    public function __construct() {
        if ( 'yes' !== get_option( 'jdpd_show_order_metabox', 'yes' ) ) {
            return;
        }

        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
    }

    /**
     * Add meta box
     */
    public function add_meta_box() {
        $screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'jdpd-order-discounts',
            __( 'Applied Discount Rules', 'jezweb-dynamic-pricing' ),
            array( $this, 'render_meta_box' ),
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Render meta box
     *
     * @param WP_Post|WC_Order $post_or_order Post or Order object.
     */
    public function render_meta_box( $post_or_order ) {
        $order = $post_or_order instanceof WP_Post
            ? wc_get_order( $post_or_order->ID )
            : $post_or_order;

        if ( ! $order ) {
            return;
        }

        $applied_rules = $this->get_order_applied_rules( $order->get_id() );

        if ( empty( $applied_rules ) ) {
            echo '<p>' . esc_html__( 'No discount rules were applied to this order.', 'jezweb-dynamic-pricing' ) . '</p>';
            return;
        }

        echo '<ul class="jdpd-order-rules">';
        foreach ( $applied_rules as $usage ) {
            $rule = jdpd_get_rule( $usage->rule_id );
            $rule_name = $rule ? $rule->name : __( 'Deleted Rule', 'jezweb-dynamic-pricing' );

            echo '<li>';
            echo '<strong>' . esc_html( $rule_name ) . '</strong><br>';
            echo '<span class="jdpd-discount-amount">';
            /* translators: %s: discount amount */
            printf( esc_html__( 'Discount: %s', 'jezweb-dynamic-pricing' ), wc_price( $usage->discount_amount ) );
            echo '</span>';
            echo '</li>';
        }
        echo '</ul>';

        // Show total discount
        $total_discount = array_sum( wp_list_pluck( $applied_rules, 'discount_amount' ) );
        echo '<p class="jdpd-total-discount">';
        echo '<strong>' . esc_html__( 'Total Discount:', 'jezweb-dynamic-pricing' ) . '</strong> ';
        echo wc_price( $total_discount );
        echo '</p>';
    }

    /**
     * Get applied rules for an order
     *
     * @param int $order_id Order ID.
     * @return array
     */
    private function get_order_applied_rules( $order_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'jdpd_rule_usage';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE order_id = %d",
                $order_id
            )
        );
    }
}
