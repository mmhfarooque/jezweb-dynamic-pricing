<?php
/**
 * Admin Rules Management
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin Rules class
 */
class JDPD_Admin_Rules {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'wp_ajax_jdpd_search_products', array( $this, 'ajax_search_products' ) );
        add_action( 'wp_ajax_jdpd_search_categories', array( $this, 'ajax_search_categories' ) );
        add_action( 'wp_ajax_jdpd_search_tags', array( $this, 'ajax_search_tags' ) );
        add_action( 'wp_ajax_jdpd_search_users', array( $this, 'ajax_search_users' ) );
        add_action( 'wp_ajax_jdpd_get_product', array( $this, 'ajax_get_product' ) );
        add_action( 'wp_ajax_jdpd_toggle_rule_status', array( $this, 'ajax_toggle_rule_status' ) );
        add_action( 'wp_ajax_jdpd_bulk_action', array( $this, 'ajax_bulk_action' ) );
        add_action( 'wp_ajax_jdpd_reorder_rules', array( $this, 'ajax_reorder_rules' ) );
    }

    /**
     * Get all rules with filtering and pagination
     *
     * @param array $args Query arguments.
     * @return array
     */
    public static function get_rules( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'    => '',
            'rule_type' => '',
            'search'    => '',
            'orderby'   => 'priority',
            'order'     => 'ASC',
            'per_page'  => 20,
            'page'      => 1,
        );

        $args = wp_parse_args( $args, $defaults );
        $table = $wpdb->prefix . 'jdpd_rules';

        // Build WHERE clause
        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['rule_type'] ) ) {
            $where[] = 'rule_type = %s';
            $values[] = $args['rule_type'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where[] = 'name LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        }

        $where_clause = implode( ' AND ', $where );

        // Count total
        if ( ! empty( $values ) ) {
            $total = $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where_clause", $values )
            );
        } else {
            $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where_clause" );
        }

        // Build ORDER BY clause
        $allowed_orderby = array( 'id', 'name', 'rule_type', 'status', 'priority', 'created_at' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'priority';
        $order = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

        // Calculate offset
        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        // Build query
        $sql = "SELECT * FROM $table WHERE $where_clause ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $offset;

        $rules = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

        return array(
            'rules'       => $rules,
            'total'       => (int) $total,
            'total_pages' => ceil( $total / $args['per_page'] ),
        );
    }

    /**
     * AJAX search products
     */
    public function ajax_search_products() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $search = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
        $exclude = isset( $_GET['exclude'] ) ? array_map( 'absint', (array) $_GET['exclude'] ) : array();

        $args = array(
            'post_type'      => array( 'product', 'product_variation' ),
            'posts_per_page' => 20,
            's'              => $search,
            'post__not_in'   => $exclude,
            'post_status'    => 'publish',
        );

        $products = get_posts( $args );
        $results = array();

        foreach ( $products as $product ) {
            $wc_product = wc_get_product( $product->ID );
            if ( $wc_product ) {
                $results[] = array(
                    'id'   => $product->ID,
                    'text' => $wc_product->get_formatted_name(),
                );
            }
        }

        wp_send_json( array( 'results' => $results ) );
    }

    /**
     * AJAX search categories
     */
    public function ajax_search_categories() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $search = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';

        $terms = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'search'     => $search,
                'number'     => 20,
            )
        );

        $results = array();

        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $results[] = array(
                    'id'   => $term->term_id,
                    'text' => $term->name,
                );
            }
        }

        wp_send_json( array( 'results' => $results ) );
    }

    /**
     * AJAX search tags
     */
    public function ajax_search_tags() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $search = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';

        $terms = get_terms(
            array(
                'taxonomy'   => 'product_tag',
                'hide_empty' => false,
                'search'     => $search,
                'number'     => 20,
            )
        );

        $results = array();

        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $results[] = array(
                    'id'   => $term->term_id,
                    'text' => $term->name,
                );
            }
        }

        wp_send_json( array( 'results' => $results ) );
    }

    /**
     * AJAX search users
     */
    public function ajax_search_users() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $search = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';

        $users = get_users(
            array(
                'search'         => '*' . $search . '*',
                'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
                'number'         => 20,
            )
        );

        $results = array();

        foreach ( $users as $user ) {
            $results[] = array(
                'id'   => $user->ID,
                'text' => sprintf( '%s (%s)', $user->display_name, $user->user_email ),
            );
        }

        wp_send_json( array( 'results' => $results ) );
    }

    /**
     * AJAX get product info
     */
    public function ajax_get_product() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'jezweb-dynamic-pricing' ) ) );
        }

        wp_send_json_success(
            array(
                'id'        => $product->get_id(),
                'name'      => $product->get_name(),
                'price'     => $product->get_price(),
                'image'     => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
                'permalink' => $product->get_permalink(),
            )
        );
    }

    /**
     * AJAX toggle rule status
     */
    public function ajax_toggle_rule_status() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;

        if ( ! $rule_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid rule ID.', 'jezweb-dynamic-pricing' ) ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'jdpd_rules';

        $current_status = $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM $table WHERE id = %d", $rule_id )
        );

        $new_status = 'active' === $current_status ? 'inactive' : 'active';

        $wpdb->update(
            $table,
            array( 'status' => $new_status ),
            array( 'id' => $rule_id ),
            array( '%s' ),
            array( '%d' )
        );

        wp_send_json_success(
            array(
                'status'  => $new_status,
                'message' => 'active' === $new_status
                    ? __( 'Rule activated.', 'jezweb-dynamic-pricing' )
                    : __( 'Rule deactivated.', 'jezweb-dynamic-pricing' ),
            )
        );
    }

    /**
     * AJAX bulk action
     */
    public function ajax_bulk_action() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
        $rule_ids = isset( $_POST['rule_ids'] ) ? array_map( 'absint', (array) $_POST['rule_ids'] ) : array();

        if ( empty( $rule_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No rules selected.', 'jezweb-dynamic-pricing' ) ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'jdpd_rules';

        switch ( $action ) {
            case 'activate':
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE $table SET status = 'active' WHERE id IN (" . implode( ',', array_fill( 0, count( $rule_ids ), '%d' ) ) . ')',
                        $rule_ids
                    )
                );
                wp_send_json_success( array( 'message' => __( 'Rules activated.', 'jezweb-dynamic-pricing' ) ) );
                break;

            case 'deactivate':
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE $table SET status = 'inactive' WHERE id IN (" . implode( ',', array_fill( 0, count( $rule_ids ), '%d' ) ) . ')',
                        $rule_ids
                    )
                );
                wp_send_json_success( array( 'message' => __( 'Rules deactivated.', 'jezweb-dynamic-pricing' ) ) );
                break;

            case 'delete':
                foreach ( $rule_ids as $rule_id ) {
                    $rule = new JDPD_Rule( $rule_id );
                    $rule->delete();
                }
                wp_send_json_success( array( 'message' => __( 'Rules deleted.', 'jezweb-dynamic-pricing' ) ) );
                break;

            default:
                wp_send_json_error( array( 'message' => __( 'Invalid action.', 'jezweb-dynamic-pricing' ) ) );
        }
    }

    /**
     * AJAX reorder rules (update priorities)
     */
    public function ajax_reorder_rules() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $order = isset( $_POST['order'] ) ? array_map( 'absint', (array) $_POST['order'] ) : array();

        if ( empty( $order ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order data.', 'jezweb-dynamic-pricing' ) ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'jdpd_rules';

        foreach ( $order as $priority => $rule_id ) {
            $wpdb->update(
                $table,
                array( 'priority' => $priority ),
                array( 'id' => $rule_id ),
                array( '%d' ),
                array( '%d' )
            );
        }

        wp_send_json_success( array( 'message' => __( 'Rule order saved.', 'jezweb-dynamic-pricing' ) ) );
    }
}
