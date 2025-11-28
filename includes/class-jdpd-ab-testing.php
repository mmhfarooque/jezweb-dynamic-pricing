<?php
/**
 * A/B Testing System
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Jezweb_Dynamic_Pricing/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A/B Testing System class.
 *
 * Allows creating A/B tests for pricing rules to measure effectiveness.
 *
 * @since 1.3.0
 */
class JDPD_AB_Testing {

    /**
     * Single instance of the class.
     *
     * @var JDPD_AB_Testing
     */
    private static $instance = null;

    /**
     * Database table name.
     *
     * @var string
     */
    private $table_name;

    /**
     * Results table name.
     *
     * @var string
     */
    private $results_table;

    /**
     * Get single instance.
     *
     * @return JDPD_AB_Testing
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'jdpd_ab_tests';
        $this->results_table = $wpdb->prefix . 'jdpd_ab_results';

        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Variant assignment
        add_action( 'wp_loaded', array( $this, 'assign_visitor_variants' ) );

        // Track conversions
        add_action( 'woocommerce_thankyou', array( $this, 'track_conversion' ) );
        add_action( 'woocommerce_add_to_cart', array( $this, 'track_add_to_cart' ), 10, 6 );

        // Filter pricing rules based on variant
        add_filter( 'jdpd_apply_rule', array( $this, 'filter_rule_by_variant' ), 10, 3 );

        // Admin AJAX handlers
        add_action( 'wp_ajax_jdpd_create_ab_test', array( $this, 'ajax_create_test' ) );
        add_action( 'wp_ajax_jdpd_update_ab_test', array( $this, 'ajax_update_test' ) );
        add_action( 'wp_ajax_jdpd_get_ab_test_results', array( $this, 'ajax_get_results' ) );
        add_action( 'wp_ajax_jdpd_end_ab_test', array( $this, 'ajax_end_test' ) );
        add_action( 'wp_ajax_jdpd_get_ab_tests', array( $this, 'ajax_get_tests' ) );
    }

    /**
     * Create database tables.
     */
    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text,
            status varchar(20) NOT NULL DEFAULT 'draft',
            control_rule_id varchar(100) NOT NULL,
            variant_rule_id varchar(100) NOT NULL,
            traffic_split int(3) NOT NULL DEFAULT 50,
            goal varchar(50) NOT NULL DEFAULT 'conversion',
            minimum_sample_size int(11) NOT NULL DEFAULT 100,
            confidence_level decimal(5,2) NOT NULL DEFAULT 95.00,
            start_date datetime DEFAULT NULL,
            end_date datetime DEFAULT NULL,
            winner varchar(20) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY control_rule_id (control_rule_id),
            KEY variant_rule_id (variant_rule_id)
        ) $charset_collate;

        CREATE TABLE IF NOT EXISTS {$this->results_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            test_id bigint(20) unsigned NOT NULL,
            variant varchar(20) NOT NULL,
            visitor_id varchar(100) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            event_type varchar(50) NOT NULL,
            event_data longtext,
            order_id bigint(20) unsigned DEFAULT NULL,
            order_total decimal(12,4) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY test_id (test_id),
            KEY variant (variant),
            KEY visitor_id (visitor_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Create a new A/B test.
     *
     * @param array $data Test data.
     * @return int|WP_Error Test ID or error.
     */
    public function create_test( $data ) {
        global $wpdb;

        $defaults = array(
            'name'               => '',
            'description'        => '',
            'status'             => 'draft',
            'control_rule_id'    => '',
            'variant_rule_id'    => '',
            'traffic_split'      => 50,
            'goal'               => 'conversion',
            'minimum_sample_size' => 100,
            'confidence_level'   => 95.00,
            'start_date'         => null,
            'end_date'           => null,
        );

        $data = wp_parse_args( $data, $defaults );

        // Validation
        if ( empty( $data['name'] ) ) {
            return new WP_Error( 'missing_name', __( 'Test name is required.', 'jezweb-dynamic-pricing' ) );
        }

        if ( empty( $data['control_rule_id'] ) || empty( $data['variant_rule_id'] ) ) {
            return new WP_Error( 'missing_rules', __( 'Both control and variant rules are required.', 'jezweb-dynamic-pricing' ) );
        }

        $result = $wpdb->insert(
            $this->table_name,
            array(
                'name'               => sanitize_text_field( $data['name'] ),
                'description'        => sanitize_textarea_field( $data['description'] ),
                'status'             => sanitize_key( $data['status'] ),
                'control_rule_id'    => sanitize_text_field( $data['control_rule_id'] ),
                'variant_rule_id'    => sanitize_text_field( $data['variant_rule_id'] ),
                'traffic_split'      => absint( $data['traffic_split'] ),
                'goal'               => sanitize_key( $data['goal'] ),
                'minimum_sample_size' => absint( $data['minimum_sample_size'] ),
                'confidence_level'   => floatval( $data['confidence_level'] ),
                'start_date'         => $data['start_date'] ? sanitize_text_field( $data['start_date'] ) : null,
                'end_date'           => $data['end_date'] ? sanitize_text_field( $data['end_date'] ) : null,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%f', '%s', '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'db_error', __( 'Failed to create test.', 'jezweb-dynamic-pricing' ) );
        }

        $test_id = $wpdb->insert_id;

        do_action( 'jdpd_ab_test_created', $test_id, $data );

        return $test_id;
    }

    /**
     * Update an A/B test.
     *
     * @param int   $test_id Test ID.
     * @param array $data Test data.
     * @return bool|WP_Error True on success or error.
     */
    public function update_test( $test_id, $data ) {
        global $wpdb;

        $test = $this->get_test( $test_id );
        if ( ! $test ) {
            return new WP_Error( 'not_found', __( 'Test not found.', 'jezweb-dynamic-pricing' ) );
        }

        // Cannot update running tests (except status)
        if ( 'running' === $test->status && ! isset( $data['status'] ) ) {
            return new WP_Error( 'test_running', __( 'Cannot modify a running test.', 'jezweb-dynamic-pricing' ) );
        }

        $update_data = array();
        $formats = array();

        $allowed_fields = array(
            'name'               => '%s',
            'description'        => '%s',
            'status'             => '%s',
            'control_rule_id'    => '%s',
            'variant_rule_id'    => '%s',
            'traffic_split'      => '%d',
            'goal'               => '%s',
            'minimum_sample_size' => '%d',
            'confidence_level'   => '%f',
            'start_date'         => '%s',
            'end_date'           => '%s',
            'winner'             => '%s',
        );

        foreach ( $allowed_fields as $field => $format ) {
            if ( isset( $data[ $field ] ) ) {
                $update_data[ $field ] = $data[ $field ];
                $formats[] = $format;
            }
        }

        if ( empty( $update_data ) ) {
            return true;
        }

        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            array( 'id' => $test_id ),
            $formats,
            array( '%d' )
        );

        if ( false === $result ) {
            return new WP_Error( 'db_error', __( 'Failed to update test.', 'jezweb-dynamic-pricing' ) );
        }

        // Handle status changes
        if ( isset( $data['status'] ) ) {
            if ( 'running' === $data['status'] && 'running' !== $test->status ) {
                do_action( 'jdpd_ab_test_started', $test_id );
            } elseif ( 'completed' === $data['status'] && 'completed' !== $test->status ) {
                do_action( 'jdpd_ab_test_completed', $test_id );
            }
        }

        return true;
    }

    /**
     * Get a test by ID.
     *
     * @param int $test_id Test ID.
     * @return object|null Test object or null.
     */
    public function get_test( $test_id ) {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $test_id
        ) );
    }

    /**
     * Get all tests.
     *
     * @param array $args Query arguments.
     * @return array Tests.
     */
    public function get_tests( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'  => '',
            'orderby' => 'created_at',
            'order'   => 'DESC',
            'limit'   => 50,
            'offset'  => 0,
        );

        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';

        if ( ! empty( $args['status'] ) ) {
            $where .= $wpdb->prepare( ' AND status = %s', $args['status'] );
        }

        $orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
        if ( ! $orderby ) {
            $orderby = 'created_at DESC';
        }

        $query = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE {$where} ORDER BY {$orderby} LIMIT %d OFFSET %d",
            $args['limit'],
            $args['offset']
        );

        return $wpdb->get_results( $query );
    }

    /**
     * Get active tests.
     *
     * @return array Active tests.
     */
    public function get_active_tests() {
        return $this->get_tests( array( 'status' => 'running' ) );
    }

    /**
     * Assign visitor to test variants.
     */
    public function assign_visitor_variants() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        $active_tests = $this->get_active_tests();
        if ( empty( $active_tests ) ) {
            return;
        }

        $visitor_id = $this->get_visitor_id();

        foreach ( $active_tests as $test ) {
            $this->assign_variant( $test, $visitor_id );
        }
    }

    /**
     * Assign a variant to a visitor for a test.
     *
     * @param object $test Test object.
     * @param string $visitor_id Visitor ID.
     * @return string Variant (control or variant).
     */
    public function assign_variant( $test, $visitor_id = null ) {
        if ( null === $visitor_id ) {
            $visitor_id = $this->get_visitor_id();
        }

        $cookie_name = 'jdpd_ab_' . $test->id;

        // Check if already assigned
        if ( isset( $_COOKIE[ $cookie_name ] ) ) {
            return sanitize_key( $_COOKIE[ $cookie_name ] );
        }

        // Deterministic assignment based on visitor ID
        $hash = crc32( $visitor_id . '_' . $test->id );
        $threshold = ( $test->traffic_split / 100 ) * 4294967295; // Max unsigned 32-bit int

        $variant = $hash < $threshold ? 'control' : 'variant';

        // Set cookie for 30 days
        setcookie( $cookie_name, $variant, time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        $_COOKIE[ $cookie_name ] = $variant; // Make available immediately

        // Track impression
        $this->track_event( $test->id, $variant, 'impression', $visitor_id );

        return $variant;
    }

    /**
     * Get variant for a visitor and test.
     *
     * @param int    $test_id Test ID.
     * @param string $visitor_id Visitor ID.
     * @return string|null Variant or null.
     */
    public function get_visitor_variant( $test_id, $visitor_id = null ) {
        if ( null === $visitor_id ) {
            $visitor_id = $this->get_visitor_id();
        }

        $cookie_name = 'jdpd_ab_' . $test_id;

        if ( isset( $_COOKIE[ $cookie_name ] ) ) {
            return sanitize_key( $_COOKIE[ $cookie_name ] );
        }

        return null;
    }

    /**
     * Get unique visitor ID.
     *
     * @return string Visitor ID.
     */
    public function get_visitor_id() {
        if ( isset( $_COOKIE['jdpd_visitor_id'] ) ) {
            return sanitize_text_field( $_COOKIE['jdpd_visitor_id'] );
        }

        // Check if user is logged in
        if ( is_user_logged_in() ) {
            $visitor_id = 'user_' . get_current_user_id();
        } else {
            $visitor_id = 'visitor_' . wp_generate_uuid4();
        }

        // Set cookie for 1 year
        setcookie( 'jdpd_visitor_id', $visitor_id, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        $_COOKIE['jdpd_visitor_id'] = $visitor_id;

        return $visitor_id;
    }

    /**
     * Filter rule application based on A/B test variant.
     *
     * @param bool   $apply Whether to apply the rule.
     * @param array  $rule Rule data.
     * @param string $rule_id Rule ID.
     * @return bool Whether to apply the rule.
     */
    public function filter_rule_by_variant( $apply, $rule, $rule_id ) {
        if ( ! $apply ) {
            return false;
        }

        $active_tests = $this->get_active_tests();

        foreach ( $active_tests as $test ) {
            // Check if this rule is part of the test
            if ( $rule_id !== $test->control_rule_id && $rule_id !== $test->variant_rule_id ) {
                continue;
            }

            $variant = $this->get_visitor_variant( $test->id );

            if ( null === $variant ) {
                // Not yet assigned, assign now
                $variant = $this->assign_variant( $test );
            }

            // Determine if this rule should be applied based on variant
            if ( 'control' === $variant && $rule_id === $test->variant_rule_id ) {
                return false; // Don't apply variant rule to control group
            }

            if ( 'variant' === $variant && $rule_id === $test->control_rule_id ) {
                return false; // Don't apply control rule to variant group
            }
        }

        return $apply;
    }

    /**
     * Track an event.
     *
     * @param int    $test_id Test ID.
     * @param string $variant Variant.
     * @param string $event_type Event type.
     * @param string $visitor_id Visitor ID.
     * @param array  $event_data Additional event data.
     * @return bool Success.
     */
    public function track_event( $test_id, $variant, $event_type, $visitor_id = null, $event_data = array() ) {
        global $wpdb;

        if ( null === $visitor_id ) {
            $visitor_id = $this->get_visitor_id();
        }

        $result = $wpdb->insert(
            $this->results_table,
            array(
                'test_id'     => absint( $test_id ),
                'variant'     => sanitize_key( $variant ),
                'visitor_id'  => sanitize_text_field( $visitor_id ),
                'user_id'     => is_user_logged_in() ? get_current_user_id() : null,
                'event_type'  => sanitize_key( $event_type ),
                'event_data'  => ! empty( $event_data ) ? wp_json_encode( $event_data ) : null,
                'order_id'    => isset( $event_data['order_id'] ) ? absint( $event_data['order_id'] ) : null,
                'order_total' => isset( $event_data['order_total'] ) ? floatval( $event_data['order_total'] ) : null,
            ),
            array( '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%f' )
        );

        return false !== $result;
    }

    /**
     * Track add to cart event.
     *
     * @param string $cart_item_key Cart item key.
     * @param int    $product_id Product ID.
     * @param int    $quantity Quantity.
     * @param int    $variation_id Variation ID.
     * @param array  $variation Variation data.
     * @param array  $cart_item_data Cart item data.
     */
    public function track_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        $active_tests = $this->get_active_tests();
        $visitor_id = $this->get_visitor_id();

        foreach ( $active_tests as $test ) {
            $variant = $this->get_visitor_variant( $test->id, $visitor_id );

            if ( $variant ) {
                $this->track_event( $test->id, $variant, 'add_to_cart', $visitor_id, array(
                    'product_id'   => $product_id,
                    'quantity'     => $quantity,
                    'variation_id' => $variation_id,
                ) );
            }
        }
    }

    /**
     * Track order conversion.
     *
     * @param int $order_id Order ID.
     */
    public function track_conversion( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Prevent duplicate tracking
        if ( $order->get_meta( '_jdpd_ab_tracked' ) ) {
            return;
        }

        $active_tests = $this->get_active_tests();
        $visitor_id = $this->get_visitor_id();

        foreach ( $active_tests as $test ) {
            $variant = $this->get_visitor_variant( $test->id, $visitor_id );

            if ( $variant ) {
                $this->track_event( $test->id, $variant, 'conversion', $visitor_id, array(
                    'order_id'    => $order_id,
                    'order_total' => $order->get_total(),
                ) );
            }
        }

        $order->update_meta_data( '_jdpd_ab_tracked', time() );
        $order->save();
    }

    /**
     * Get test results.
     *
     * @param int $test_id Test ID.
     * @return array Test results.
     */
    public function get_results( $test_id ) {
        global $wpdb;

        $test = $this->get_test( $test_id );
        if ( ! $test ) {
            return array();
        }

        $results = array(
            'test'    => $test,
            'control' => $this->get_variant_stats( $test_id, 'control' ),
            'variant' => $this->get_variant_stats( $test_id, 'variant' ),
        );

        // Calculate statistical significance
        $results['statistics'] = $this->calculate_statistics(
            $results['control'],
            $results['variant'],
            $test->goal
        );

        // Determine winner if test is completed or significance is reached
        $results['winner'] = $this->determine_winner( $results );

        return $results;
    }

    /**
     * Get statistics for a variant.
     *
     * @param int    $test_id Test ID.
     * @param string $variant Variant.
     * @return array Variant statistics.
     */
    public function get_variant_stats( $test_id, $variant ) {
        global $wpdb;

        // Get unique visitors (impressions)
        $impressions = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT visitor_id)
             FROM {$this->results_table}
             WHERE test_id = %d AND variant = %s AND event_type = 'impression'",
            $test_id,
            $variant
        ) );

        // Get add to cart events
        $add_to_carts = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT visitor_id)
             FROM {$this->results_table}
             WHERE test_id = %d AND variant = %s AND event_type = 'add_to_cart'",
            $test_id,
            $variant
        ) );

        // Get conversions
        $conversions = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT visitor_id)
             FROM {$this->results_table}
             WHERE test_id = %d AND variant = %s AND event_type = 'conversion'",
            $test_id,
            $variant
        ) );

        // Get revenue
        $revenue = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(order_total)
             FROM {$this->results_table}
             WHERE test_id = %d AND variant = %s AND event_type = 'conversion'",
            $test_id,
            $variant
        ) );

        // Get unique orders
        $orders = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT order_id)
             FROM {$this->results_table}
             WHERE test_id = %d AND variant = %s AND event_type = 'conversion' AND order_id IS NOT NULL",
            $test_id,
            $variant
        ) );

        $impressions = absint( $impressions );
        $conversions = absint( $conversions );
        $revenue = floatval( $revenue );

        return array(
            'impressions'      => $impressions,
            'add_to_carts'     => absint( $add_to_carts ),
            'conversions'      => $conversions,
            'orders'           => absint( $orders ),
            'revenue'          => $revenue,
            'conversion_rate'  => $impressions > 0 ? ( $conversions / $impressions ) * 100 : 0,
            'add_to_cart_rate' => $impressions > 0 ? ( $add_to_carts / $impressions ) * 100 : 0,
            'avg_order_value'  => $orders > 0 ? $revenue / $orders : 0,
            'revenue_per_visitor' => $impressions > 0 ? $revenue / $impressions : 0,
        );
    }

    /**
     * Calculate statistical significance.
     *
     * @param array  $control Control stats.
     * @param array  $variant Variant stats.
     * @param string $goal Test goal.
     * @return array Statistics.
     */
    public function calculate_statistics( $control, $variant, $goal ) {
        $stats = array(
            'is_significant'   => false,
            'confidence'       => 0,
            'improvement'      => 0,
            'recommended_action' => '',
        );

        // Need minimum sample size
        if ( $control['impressions'] < 30 || $variant['impressions'] < 30 ) {
            $stats['recommended_action'] = __( 'Need more data. Minimum 30 visitors per variant.', 'jezweb-dynamic-pricing' );
            return $stats;
        }

        // Calculate based on goal
        switch ( $goal ) {
            case 'conversion':
                $p1 = $control['conversion_rate'] / 100;
                $p2 = $variant['conversion_rate'] / 100;
                $n1 = $control['impressions'];
                $n2 = $variant['impressions'];
                break;

            case 'revenue':
                // Use revenue per visitor
                $p1 = $control['revenue_per_visitor'];
                $p2 = $variant['revenue_per_visitor'];
                $n1 = $control['impressions'];
                $n2 = $variant['impressions'];
                break;

            case 'add_to_cart':
                $p1 = $control['add_to_cart_rate'] / 100;
                $p2 = $variant['add_to_cart_rate'] / 100;
                $n1 = $control['impressions'];
                $n2 = $variant['impressions'];
                break;

            default:
                return $stats;
        }

        // Calculate improvement
        if ( $p1 > 0 ) {
            $stats['improvement'] = ( ( $p2 - $p1 ) / $p1 ) * 100;
        }

        // Calculate z-score for proportions
        if ( $goal === 'conversion' || $goal === 'add_to_cart' ) {
            $pooled = ( ( $p1 * $n1 ) + ( $p2 * $n2 ) ) / ( $n1 + $n2 );
            if ( $pooled > 0 && $pooled < 1 ) {
                $se = sqrt( $pooled * ( 1 - $pooled ) * ( ( 1 / $n1 ) + ( 1 / $n2 ) ) );
                if ( $se > 0 ) {
                    $z = abs( $p2 - $p1 ) / $se;
                    $stats['confidence'] = $this->z_to_confidence( $z );
                    $stats['is_significant'] = $stats['confidence'] >= 95;
                }
            }
        } else {
            // For revenue, use a simplified comparison (would need full t-test for accuracy)
            $diff_percent = abs( $stats['improvement'] );
            if ( $diff_percent > 10 && $n1 >= 100 && $n2 >= 100 ) {
                $stats['is_significant'] = true;
                $stats['confidence'] = min( 95, 80 + ( $diff_percent / 2 ) );
            }
        }

        // Set recommended action
        if ( $stats['is_significant'] ) {
            if ( $stats['improvement'] > 0 ) {
                $stats['recommended_action'] = __( 'Variant is performing better. Consider implementing it.', 'jezweb-dynamic-pricing' );
            } else {
                $stats['recommended_action'] = __( 'Control is performing better. Keep the original.', 'jezweb-dynamic-pricing' );
            }
        } else {
            $stats['recommended_action'] = __( 'No significant difference yet. Continue the test.', 'jezweb-dynamic-pricing' );
        }

        return $stats;
    }

    /**
     * Convert z-score to confidence percentage.
     *
     * @param float $z Z-score.
     * @return float Confidence percentage.
     */
    private function z_to_confidence( $z ) {
        // Approximation using error function
        $cdf = 0.5 * ( 1 + $this->erf( $z / sqrt( 2 ) ) );
        return ( 2 * $cdf - 1 ) * 100;
    }

    /**
     * Error function approximation.
     *
     * @param float $x Input value.
     * @return float Error function result.
     */
    private function erf( $x ) {
        // Approximation constants
        $a1 =  0.254829592;
        $a2 = -0.284496736;
        $a3 =  1.421413741;
        $a4 = -1.453152027;
        $a5 =  1.061405429;
        $p  =  0.3275911;

        $sign = $x < 0 ? -1 : 1;
        $x = abs( $x );

        $t = 1.0 / ( 1.0 + $p * $x );
        $y = 1.0 - ( ( ( ( ( $a5 * $t + $a4 ) * $t ) + $a3 ) * $t + $a2 ) * $t + $a1 ) * $t * exp( -$x * $x );

        return $sign * $y;
    }

    /**
     * Determine test winner.
     *
     * @param array $results Test results.
     * @return string|null Winner (control, variant) or null.
     */
    public function determine_winner( $results ) {
        if ( ! $results['statistics']['is_significant'] ) {
            return null;
        }

        return $results['statistics']['improvement'] > 0 ? 'variant' : 'control';
    }

    /**
     * End a test and optionally apply winner.
     *
     * @param int  $test_id Test ID.
     * @param bool $apply_winner Whether to apply the winning rule.
     * @return bool|WP_Error Success or error.
     */
    public function end_test( $test_id, $apply_winner = false ) {
        $results = $this->get_results( $test_id );

        if ( empty( $results ) ) {
            return new WP_Error( 'not_found', __( 'Test not found.', 'jezweb-dynamic-pricing' ) );
        }

        $winner = $results['winner'];

        $update_result = $this->update_test( $test_id, array(
            'status' => 'completed',
            'end_date' => current_time( 'mysql' ),
            'winner' => $winner,
        ) );

        if ( is_wp_error( $update_result ) ) {
            return $update_result;
        }

        // Apply winner if requested
        if ( $apply_winner && $winner ) {
            $test = $results['test'];

            if ( 'variant' === $winner ) {
                // Disable control, ensure variant is enabled
                $this->set_rule_status( $test->control_rule_id, false );
                $this->set_rule_status( $test->variant_rule_id, true );
            } else {
                // Disable variant, ensure control is enabled
                $this->set_rule_status( $test->variant_rule_id, false );
                $this->set_rule_status( $test->control_rule_id, true );
            }
        }

        do_action( 'jdpd_ab_test_ended', $test_id, $winner, $apply_winner );

        return true;
    }

    /**
     * Set rule enabled/disabled status.
     *
     * @param string $rule_id Rule ID.
     * @param bool   $enabled Whether to enable.
     */
    private function set_rule_status( $rule_id, $enabled ) {
        $rules = get_option( 'jdpd_rules', array() );

        if ( isset( $rules[ $rule_id ] ) ) {
            $rules[ $rule_id ]['enabled'] = $enabled;
            update_option( 'jdpd_rules', $rules );
        }
    }

    /**
     * AJAX: Create A/B test.
     */
    public function ajax_create_test() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $data = isset( $_POST['test'] ) ? wp_unslash( $_POST['test'] ) : array();

        $result = $this->create_test( $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'test_id' => $result,
            'message' => __( 'A/B test created successfully.', 'jezweb-dynamic-pricing' ),
        ) );
    }

    /**
     * AJAX: Update A/B test.
     */
    public function ajax_update_test() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;
        $data = isset( $_POST['test'] ) ? wp_unslash( $_POST['test'] ) : array();

        $result = $this->update_test( $test_id, $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'A/B test updated successfully.', 'jezweb-dynamic-pricing' ),
        ) );
    }

    /**
     * AJAX: Get A/B test results.
     */
    public function ajax_get_results() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;

        $results = $this->get_results( $test_id );

        if ( empty( $results ) ) {
            wp_send_json_error( array( 'message' => __( 'Test not found.', 'jezweb-dynamic-pricing' ) ) );
        }

        wp_send_json_success( $results );
    }

    /**
     * AJAX: End A/B test.
     */
    public function ajax_end_test() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;
        $apply_winner = isset( $_POST['apply_winner'] ) && 'true' === $_POST['apply_winner'];

        $result = $this->end_test( $test_id, $apply_winner );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'A/B test ended successfully.', 'jezweb-dynamic-pricing' ),
        ) );
    }

    /**
     * AJAX: Get all A/B tests.
     */
    public function ajax_get_tests() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';

        $tests = $this->get_tests( array( 'status' => $status ) );

        // Add stats to each test
        foreach ( $tests as &$test ) {
            $results = $this->get_results( $test->id );
            $test->control_stats = $results['control'] ?? array();
            $test->variant_stats = $results['variant'] ?? array();
            $test->statistics = $results['statistics'] ?? array();
        }

        wp_send_json_success( array( 'tests' => $tests ) );
    }
}
