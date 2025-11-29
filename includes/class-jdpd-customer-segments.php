<?php
/**
 * Customer Segments & Tiers Class
 *
 * Manages customer groups and loyalty tiers for targeted discounts.
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JDPD_Customer_Segments Class
 */
class JDPD_Customer_Segments {

    /**
     * Instance
     *
     * @var JDPD_Customer_Segments
     */
    private static $instance = null;

    /**
     * Segments table name
     *
     * @var string
     */
    private $segments_table;

    /**
     * Customer segments table name
     *
     * @var string
     */
    private $customer_segments_table;

    /**
     * Get instance
     *
     * @return JDPD_Customer_Segments
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
        $this->segments_table = $wpdb->prefix . 'jdpd_segments';
        $this->customer_segments_table = $wpdb->prefix . 'jdpd_customer_segments';

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Update customer tier on order complete
        add_action( 'woocommerce_order_status_completed', array( $this, 'update_customer_tier' ), 20, 1 );

        // Add customer tier condition to conditions
        add_filter( 'jdpd_condition_types', array( $this, 'add_condition_types' ) );
        add_filter( 'jdpd_check_condition_customer_tier', array( $this, 'check_tier_condition' ), 10, 3 );
        add_filter( 'jdpd_check_condition_customer_segment', array( $this, 'check_segment_condition' ), 10, 3 );

        // AJAX handlers for admin
        add_action( 'wp_ajax_jdpd_search_segments', array( $this, 'ajax_search_segments' ) );
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Segments table
        $segments_table = $wpdb->prefix . 'jdpd_segments';
        $sql1 = "CREATE TABLE IF NOT EXISTS $segments_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(100) NOT NULL,
            type enum('manual','automatic') NOT NULL DEFAULT 'manual',
            description text,
            conditions longtext,
            priority int(11) NOT NULL DEFAULT 10,
            status enum('active','inactive') NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY type (type),
            KEY status (status)
        ) $charset_collate;";

        // Customer segments relationship table
        $customer_segments_table = $wpdb->prefix . 'jdpd_customer_segments';
        $sql2 = "CREATE TABLE IF NOT EXISTS $customer_segments_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned NOT NULL,
            segment_id bigint(20) unsigned NOT NULL,
            added_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY customer_segment (customer_id, segment_id),
            KEY customer_id (customer_id),
            KEY segment_id (segment_id)
        ) $charset_collate;";

        // Customer tiers table
        $tiers_table = $wpdb->prefix . 'jdpd_customer_tiers';
        $sql3 = "CREATE TABLE IF NOT EXISTS $tiers_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(100) NOT NULL,
            min_spent decimal(12,2) NOT NULL DEFAULT 0,
            min_orders int(11) NOT NULL DEFAULT 0,
            discount_percentage decimal(5,2) NOT NULL DEFAULT 0,
            benefits longtext,
            badge_color varchar(20) DEFAULT '#22588d',
            priority int(11) NOT NULL DEFAULT 10,
            status enum('active','inactive') NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY min_spent (min_spent),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql1 );
        dbDelta( $sql2 );
        dbDelta( $sql3 );

        // Insert default tiers
        self::insert_default_tiers();
    }

    /**
     * Insert default loyalty tiers
     */
    private static function insert_default_tiers() {
        global $wpdb;

        $tiers_table = $wpdb->prefix . 'jdpd_customer_tiers';

        // Check if tiers already exist
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM $tiers_table" );
        if ( $count > 0 ) {
            return;
        }

        $default_tiers = array(
            array(
                'name'                => __( 'Bronze', 'jezweb-dynamic-pricing' ),
                'slug'                => 'bronze',
                'min_spent'           => 0,
                'min_orders'          => 1,
                'discount_percentage' => 0,
                'badge_color'         => '#cd7f32',
                'priority'            => 10,
            ),
            array(
                'name'                => __( 'Silver', 'jezweb-dynamic-pricing' ),
                'slug'                => 'silver',
                'min_spent'           => 200,
                'min_orders'          => 3,
                'discount_percentage' => 5,
                'badge_color'         => '#c0c0c0',
                'priority'            => 20,
            ),
            array(
                'name'                => __( 'Gold', 'jezweb-dynamic-pricing' ),
                'slug'                => 'gold',
                'min_spent'           => 500,
                'min_orders'          => 5,
                'discount_percentage' => 10,
                'badge_color'         => '#ffd700',
                'priority'            => 30,
            ),
            array(
                'name'                => __( 'Platinum', 'jezweb-dynamic-pricing' ),
                'slug'                => 'platinum',
                'min_spent'           => 1000,
                'min_orders'          => 10,
                'discount_percentage' => 15,
                'badge_color'         => '#e5e4e2',
                'priority'            => 40,
            ),
            array(
                'name'                => __( 'VIP', 'jezweb-dynamic-pricing' ),
                'slug'                => 'vip',
                'min_spent'           => 2500,
                'min_orders'          => 20,
                'discount_percentage' => 20,
                'badge_color'         => '#22588d',
                'priority'            => 50,
            ),
        );

        foreach ( $default_tiers as $tier ) {
            $wpdb->insert(
                $tiers_table,
                $tier,
                array( '%s', '%s', '%f', '%d', '%f', '%s', '%d' )
            );
        }
    }

    /**
     * Get all segments
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_segments( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status' => '',
            'type'   => '',
        );

        $args = wp_parse_args( $args, $defaults );

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['type'] ) ) {
            $where[] = 'type = %s';
            $values[] = $args['type'];
        }

        $where_clause = implode( ' AND ', $where );

        if ( ! empty( $values ) ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$this->segments_table} WHERE $where_clause ORDER BY priority ASC",
                $values
            ) );
        }

        return $wpdb->get_results(
            "SELECT * FROM {$this->segments_table} WHERE $where_clause ORDER BY priority ASC"
        );
    }

    /**
     * Get segment by ID
     *
     * @param int $segment_id Segment ID.
     * @return object|null
     */
    public function get_segment( $segment_id ) {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->segments_table} WHERE id = %d",
            $segment_id
        ) );
    }

    /**
     * Create segment
     *
     * @param array $data Segment data.
     * @return int|false Segment ID or false on failure.
     */
    public function create_segment( $data ) {
        global $wpdb;

        $defaults = array(
            'name'        => '',
            'slug'        => '',
            'type'        => 'manual',
            'description' => '',
            'conditions'  => array(),
            'priority'    => 10,
            'status'      => 'active',
        );

        $data = wp_parse_args( $data, $defaults );

        // Generate slug if not provided
        if ( empty( $data['slug'] ) ) {
            $data['slug'] = sanitize_title( $data['name'] );
        }

        $result = $wpdb->insert(
            $this->segments_table,
            array(
                'name'        => $data['name'],
                'slug'        => $data['slug'],
                'type'        => $data['type'],
                'description' => $data['description'],
                'conditions'  => maybe_serialize( $data['conditions'] ),
                'priority'    => $data['priority'],
                'status'      => $data['status'],
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update segment
     *
     * @param int   $segment_id Segment ID.
     * @param array $data       Segment data.
     * @return bool
     */
    public function update_segment( $segment_id, $data ) {
        global $wpdb;

        $update_data = array();
        $format = array();

        if ( isset( $data['name'] ) ) {
            $update_data['name'] = $data['name'];
            $format[] = '%s';
        }

        if ( isset( $data['description'] ) ) {
            $update_data['description'] = $data['description'];
            $format[] = '%s';
        }

        if ( isset( $data['conditions'] ) ) {
            $update_data['conditions'] = maybe_serialize( $data['conditions'] );
            $format[] = '%s';
        }

        if ( isset( $data['priority'] ) ) {
            $update_data['priority'] = $data['priority'];
            $format[] = '%d';
        }

        if ( isset( $data['status'] ) ) {
            $update_data['status'] = $data['status'];
            $format[] = '%s';
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        return (bool) $wpdb->update(
            $this->segments_table,
            $update_data,
            array( 'id' => $segment_id ),
            $format,
            array( '%d' )
        );
    }

    /**
     * Delete segment
     *
     * @param int $segment_id Segment ID.
     * @return bool
     */
    public function delete_segment( $segment_id ) {
        global $wpdb;

        // Remove all customer associations
        $wpdb->delete(
            $this->customer_segments_table,
            array( 'segment_id' => $segment_id ),
            array( '%d' )
        );

        return (bool) $wpdb->delete(
            $this->segments_table,
            array( 'id' => $segment_id ),
            array( '%d' )
        );
    }

    /**
     * Add customer to segment
     *
     * @param int         $customer_id Customer ID.
     * @param int         $segment_id  Segment ID.
     * @param string|null $expires_at  Expiration date.
     * @return bool
     */
    public function add_customer_to_segment( $customer_id, $segment_id, $expires_at = null ) {
        global $wpdb;

        // Check if already exists
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->customer_segments_table} WHERE customer_id = %d AND segment_id = %d",
            $customer_id,
            $segment_id
        ) );

        if ( $exists ) {
            return true;
        }

        return (bool) $wpdb->insert(
            $this->customer_segments_table,
            array(
                'customer_id' => $customer_id,
                'segment_id'  => $segment_id,
                'expires_at'  => $expires_at,
            ),
            array( '%d', '%d', '%s' )
        );
    }

    /**
     * Remove customer from segment
     *
     * @param int $customer_id Customer ID.
     * @param int $segment_id  Segment ID.
     * @return bool
     */
    public function remove_customer_from_segment( $customer_id, $segment_id ) {
        global $wpdb;

        return (bool) $wpdb->delete(
            $this->customer_segments_table,
            array(
                'customer_id' => $customer_id,
                'segment_id'  => $segment_id,
            ),
            array( '%d', '%d' )
        );
    }

    /**
     * Get customer segments
     *
     * @param int $customer_id Customer ID.
     * @return array
     */
    public function get_customer_segments( $customer_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT s.* FROM {$this->segments_table} s
            INNER JOIN {$this->customer_segments_table} cs ON s.id = cs.segment_id
            WHERE cs.customer_id = %d
            AND s.status = 'active'
            AND (cs.expires_at IS NULL OR cs.expires_at > NOW())
            ORDER BY s.priority ASC",
            $customer_id
        ) );
    }

    /**
     * Check if customer is in segment
     *
     * @param int        $customer_id Customer ID.
     * @param int|string $segment     Segment ID or slug.
     * @return bool
     */
    public function customer_in_segment( $customer_id, $segment ) {
        global $wpdb;

        if ( is_numeric( $segment ) ) {
            $where = 's.id = %d';
        } else {
            $where = 's.slug = %s';
        }

        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->segments_table} s
            INNER JOIN {$this->customer_segments_table} cs ON s.id = cs.segment_id
            WHERE cs.customer_id = %d AND $where
            AND s.status = 'active'
            AND (cs.expires_at IS NULL OR cs.expires_at > NOW())",
            $customer_id,
            $segment
        ) );

        return $result > 0;
    }

    /**
     * Get all tiers
     *
     * @return array
     */
    public function get_tiers() {
        global $wpdb;

        $tiers_table = $wpdb->prefix . 'jdpd_customer_tiers';

        return $wpdb->get_results(
            "SELECT * FROM $tiers_table WHERE status = 'active' ORDER BY priority ASC"
        );
    }

    /**
     * Get customer tier
     *
     * @param int $customer_id Customer ID.
     * @return object|null
     */
    public function get_customer_tier( $customer_id ) {
        global $wpdb;

        $tiers_table = $wpdb->prefix . 'jdpd_customer_tiers';

        // Get customer stats
        $stats = $this->get_customer_stats( $customer_id );

        // Find matching tier (highest priority that matches)
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $tiers_table
            WHERE status = 'active'
            AND min_spent <= %f
            AND min_orders <= %d
            ORDER BY priority DESC
            LIMIT 1",
            $stats['total_spent'],
            $stats['order_count']
        ) );
    }

    /**
     * Get customer stats
     *
     * @param int $customer_id Customer ID.
     * @return array
     */
    public function get_customer_stats( $customer_id ) {
        global $wpdb;

        // Get from WooCommerce
        $total_spent = (float) wc_get_customer_total_spent( $customer_id );
        $order_count = (int) wc_get_customer_order_count( $customer_id );

        // Get first order date
        $first_order = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(p.post_date) FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND pm.meta_key = '_customer_user'
            AND pm.meta_value = %d",
            $customer_id
        ) );

        return array(
            'total_spent'     => $total_spent,
            'order_count'     => $order_count,
            'first_order'     => $first_order,
            'customer_since'  => $first_order ? human_time_diff( strtotime( $first_order ) ) . ' ago' : null,
            'average_order'   => $order_count > 0 ? $total_spent / $order_count : 0,
        );
    }

    /**
     * Update customer tier after order
     *
     * @param int $order_id Order ID.
     */
    public function update_customer_tier( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $customer_id = $order->get_customer_id();
        if ( ! $customer_id ) {
            return;
        }

        // Get new tier
        $new_tier = $this->get_customer_tier( $customer_id );

        if ( $new_tier ) {
            // Save tier to user meta
            $current_tier = get_user_meta( $customer_id, '_jdpd_customer_tier', true );

            if ( $current_tier !== $new_tier->slug ) {
                update_user_meta( $customer_id, '_jdpd_customer_tier', $new_tier->slug );
                update_user_meta( $customer_id, '_jdpd_tier_updated', current_time( 'mysql' ) );

                /**
                 * Fires when customer tier changes.
                 *
                 * @param int    $customer_id  Customer ID.
                 * @param object $new_tier     New tier object.
                 * @param string $current_tier Previous tier slug.
                 */
                do_action( 'jdpd_customer_tier_changed', $customer_id, $new_tier, $current_tier );
            }
        }
    }

    /**
     * Add condition types
     *
     * @param array $types Condition types.
     * @return array
     */
    public function add_condition_types( $types ) {
        $types['customer_tier'] = __( 'Customer Tier', 'jezweb-dynamic-pricing' );
        $types['customer_segment'] = __( 'Customer Segment', 'jezweb-dynamic-pricing' );
        return $types;
    }

    /**
     * Check tier condition
     *
     * @param bool  $result    Current result.
     * @param array $condition Condition data.
     * @param int   $user_id   User ID.
     * @return bool
     */
    public function check_tier_condition( $result, $condition, $user_id ) {
        if ( ! $user_id ) {
            return false;
        }

        $customer_tier = $this->get_customer_tier( $user_id );
        if ( ! $customer_tier ) {
            return false;
        }

        $condition_value = $condition['value'];

        switch ( $condition['operator'] ) {
            case 'equals':
                return $customer_tier->slug === $condition_value;
            case 'not_equals':
                return $customer_tier->slug !== $condition_value;
            case 'in':
                $values = is_array( $condition_value ) ? $condition_value : explode( ',', $condition_value );
                return in_array( $customer_tier->slug, $values, true );
            default:
                return false;
        }
    }

    /**
     * Check segment condition
     *
     * @param bool  $result    Current result.
     * @param array $condition Condition data.
     * @param int   $user_id   User ID.
     * @return bool
     */
    public function check_segment_condition( $result, $condition, $user_id ) {
        if ( ! $user_id ) {
            return false;
        }

        $segment_id = (int) $condition['value'];

        switch ( $condition['operator'] ) {
            case 'equals':
            case 'in':
                return $this->customer_in_segment( $user_id, $segment_id );
            case 'not_equals':
            case 'not_in':
                return ! $this->customer_in_segment( $user_id, $segment_id );
            default:
                return false;
        }
    }

    /**
     * AJAX search segments
     */
    public function ajax_search_segments() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $search = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : '';

        global $wpdb;

        $segments = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$this->segments_table}
            WHERE status = 'active' AND name LIKE %s
            ORDER BY name ASC
            LIMIT 20",
            '%' . $wpdb->esc_like( $search ) . '%'
        ) );

        $results = array();
        foreach ( $segments as $segment ) {
            $results[] = array(
                'id'   => $segment->id,
                'text' => $segment->name,
            );
        }

        wp_send_json( array( 'results' => $results ) );
    }
}

/**
 * Get customer segments instance
 *
 * @return JDPD_Customer_Segments
 */
function jdpd_customer_segments() {
    static $instance = null;
    if ( null === $instance ) {
        $instance = new JDPD_Customer_Segments();
    }
    return $instance;
}
