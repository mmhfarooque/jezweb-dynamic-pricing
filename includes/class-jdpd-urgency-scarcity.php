<?php
/**
 * Urgency & Scarcity Features
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Jezweb_Dynamic_Pricing/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Urgency & Scarcity class.
 *
 * Stock-based pricing, limited quantity deals, urgency messaging.
 *
 * @since 1.4.0
 */
class JDPD_Urgency_Scarcity {

    /**
     * Single instance.
     *
     * @var JDPD_Urgency_Scarcity
     */
    private static $instance = null;

    /**
     * Get single instance.
     *
     * @return JDPD_Urgency_Scarcity
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
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Stock-based pricing
        add_filter( 'jdpd_calculated_price', array( $this, 'apply_stock_based_pricing' ), 60, 3 );

        // Urgency displays
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_stock_urgency' ), 25 );
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_views_counter' ), 26 );
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_purchase_counter' ), 27 );

        // Limited quantity deals
        add_filter( 'jdpd_apply_rule', array( $this, 'check_quantity_limit' ), 10, 3 );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'track_deal_usage' ) );

        // Track product views
        add_action( 'woocommerce_before_single_product', array( $this, 'track_product_view' ) );

        // AJAX
        add_action( 'wp_ajax_jdpd_get_urgency_data', array( $this, 'ajax_get_urgency_data' ) );
        add_action( 'wp_ajax_nopriv_jdpd_get_urgency_data', array( $this, 'ajax_get_urgency_data' ) );

        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Apply stock-based pricing.
     *
     * @param float $price Current price.
     * @param int   $product_id Product ID.
     * @param array $context Context.
     * @return float Modified price.
     */
    public function apply_stock_based_pricing( $price, $product_id, $context = array() ) {
        $settings = $this->get_stock_pricing_settings( $product_id );

        if ( empty( $settings['enabled'] ) ) {
            return $price;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->managing_stock() ) {
            return $price;
        }

        $stock = $product->get_stock_quantity();
        if ( $stock <= 0 ) {
            return $price;
        }

        $tiers = $settings['tiers'] ?? array();

        // Sort tiers by stock threshold descending
        usort( $tiers, function( $a, $b ) {
            return $b['stock'] - $a['stock'];
        } );

        foreach ( $tiers as $tier ) {
            if ( $stock <= $tier['stock'] ) {
                $adjustment = floatval( $tier['adjustment'] ?? 0 );
                $type = $tier['type'] ?? 'percentage';

                if ( 'percentage' === $type ) {
                    // Positive = price increase
                    $price = $price * ( 1 + ( $adjustment / 100 ) );
                } else {
                    $price = $price + $adjustment;
                }

                break;
            }
        }

        return max( 0, round( $price, wc_get_price_decimals() ) );
    }

    /**
     * Get stock pricing settings for a product.
     *
     * @param int $product_id Product ID.
     * @return array Settings.
     */
    public function get_stock_pricing_settings( $product_id ) {
        // Check product-specific settings
        $settings = get_post_meta( $product_id, '_jdpd_stock_pricing', true );

        if ( ! empty( $settings ) ) {
            return $settings;
        }

        // Return global settings
        return get_option( 'jdpd_stock_pricing_settings', array(
            'enabled' => false,
            'tiers'   => array(
                array( 'stock' => 10, 'adjustment' => 5, 'type' => 'percentage' ),  // +5% when 10 or less
                array( 'stock' => 5, 'adjustment' => 10, 'type' => 'percentage' ),  // +10% when 5 or less
                array( 'stock' => 2, 'adjustment' => 15, 'type' => 'percentage' ),  // +15% when 2 or less
            ),
        ) );
    }

    /**
     * Display stock urgency message.
     */
    public function display_stock_urgency() {
        global $product;

        if ( ! $product || ! $product->managing_stock() ) {
            return;
        }

        $stock = $product->get_stock_quantity();
        $settings = get_option( 'jdpd_urgency_settings', array() );
        $threshold = $settings['low_stock_threshold'] ?? 10;

        if ( $stock <= 0 || $stock > $threshold ) {
            return;
        }

        $messages = array(
            1 => __( 'Last one! Order now before it\'s gone!', 'jezweb-dynamic-pricing' ),
            2 => __( 'Only 2 left in stock!', 'jezweb-dynamic-pricing' ),
            3 => __( 'Only 3 left - order soon!', 'jezweb-dynamic-pricing' ),
            5 => __( 'Low stock - only %d left!', 'jezweb-dynamic-pricing' ),
        );

        $message = '';
        if ( $stock === 1 ) {
            $message = $messages[1];
        } elseif ( $stock === 2 ) {
            $message = $messages[2];
        } elseif ( $stock === 3 ) {
            $message = $messages[3];
        } else {
            $message = sprintf( $messages[5], $stock );
        }

        ?>
        <div class="jdpd-urgency-stock">
            <span class="jdpd-urgency-icon">🔥</span>
            <span class="jdpd-urgency-text"><?php echo esc_html( $message ); ?></span>
        </div>
        <style>
            .jdpd-urgency-stock {
                background: linear-gradient(135deg, #ff6b6b, #d83a34);
                color: #fff;
                padding: 10px 15px;
                border-radius: 6px;
                margin: 10px 0;
                display: flex;
                align-items: center;
                gap: 8px;
                font-weight: 500;
                animation: jdpd-pulse 2s infinite;
            }
            @keyframes jdpd-pulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.02); }
            }
        </style>
        <?php
    }

    /**
     * Display live views counter.
     */
    public function display_views_counter() {
        global $product;

        if ( ! $product ) {
            return;
        }

        $settings = get_option( 'jdpd_urgency_settings', array() );

        if ( empty( $settings['show_views'] ) ) {
            return;
        }

        $views = $this->get_recent_views( $product->get_id() );
        $min_views = $settings['min_views_display'] ?? 5;

        if ( $views < $min_views ) {
            return;
        }

        ?>
        <div class="jdpd-views-counter">
            <span class="jdpd-views-icon">👁️</span>
            <span class="jdpd-views-text">
                <?php
                printf(
                    /* translators: %d: number of views */
                    esc_html__( '%d people are viewing this right now', 'jezweb-dynamic-pricing' ),
                    $views
                );
                ?>
            </span>
        </div>
        <style>
            .jdpd-views-counter {
                background: #f0f7ff;
                color: #22588d;
                padding: 8px 12px;
                border-radius: 4px;
                margin: 8px 0;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 0.9em;
            }
        </style>
        <?php
    }

    /**
     * Display recent purchases counter.
     */
    public function display_purchase_counter() {
        global $product;

        if ( ! $product ) {
            return;
        }

        $settings = get_option( 'jdpd_urgency_settings', array() );

        if ( empty( $settings['show_purchases'] ) ) {
            return;
        }

        $purchases = $this->get_recent_purchases( $product->get_id() );
        $min_purchases = $settings['min_purchases_display'] ?? 3;
        $hours = $settings['purchase_hours'] ?? 24;

        if ( $purchases < $min_purchases ) {
            return;
        }

        ?>
        <div class="jdpd-purchases-counter">
            <span class="jdpd-purchases-icon">🛒</span>
            <span class="jdpd-purchases-text">
                <?php
                printf(
                    /* translators: 1: number of purchases, 2: hours */
                    esc_html__( '%1$d sold in the last %2$d hours', 'jezweb-dynamic-pricing' ),
                    $purchases,
                    $hours
                );
                ?>
            </span>
        </div>
        <style>
            .jdpd-purchases-counter {
                background: #e8f5e9;
                color: #2e7d32;
                padding: 8px 12px;
                border-radius: 4px;
                margin: 8px 0;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 0.9em;
            }
        </style>
        <?php
    }

    /**
     * Track product view.
     */
    public function track_product_view() {
        global $product;

        if ( ! $product ) {
            return;
        }

        $product_id = $product->get_id();
        $views_key = 'jdpd_product_views_' . $product_id;

        $views = get_transient( $views_key );

        if ( false === $views ) {
            $views = array();
        }

        // Add current visitor (use session ID or IP hash)
        $visitor_id = $this->get_visitor_id();
        $views[ $visitor_id ] = time();

        // Remove views older than 15 minutes
        $cutoff = time() - ( 15 * MINUTE_IN_SECONDS );
        $views = array_filter( $views, function( $timestamp ) use ( $cutoff ) {
            return $timestamp > $cutoff;
        } );

        set_transient( $views_key, $views, 30 * MINUTE_IN_SECONDS );
    }

    /**
     * Get recent views count.
     *
     * @param int $product_id Product ID.
     * @return int Views count.
     */
    public function get_recent_views( $product_id ) {
        $views = get_transient( 'jdpd_product_views_' . $product_id );

        if ( ! is_array( $views ) ) {
            return 0;
        }

        // Count views from last 15 minutes
        $cutoff = time() - ( 15 * MINUTE_IN_SECONDS );
        $recent = array_filter( $views, function( $timestamp ) use ( $cutoff ) {
            return $timestamp > $cutoff;
        } );

        return count( $recent );
    }

    /**
     * Get recent purchases count.
     *
     * @param int $product_id Product ID.
     * @param int $hours Hours to look back.
     * @return int Purchase count.
     */
    public function get_recent_purchases( $product_id, $hours = 24 ) {
        global $wpdb;

        $date_from = date( 'Y-m-d H:i:s', strtotime( "-{$hours} hours" ) );

        // Check cache
        $cache_key = 'jdpd_purchases_' . $product_id . '_' . $hours;
        $count = get_transient( $cache_key );

        if ( false !== $count ) {
            return (int) $count;
        }

        // Query order items
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(oim.meta_value) FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_qty'
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi.order_item_id = oim2.order_item_id AND oim2.meta_key = '_product_id' AND oim2.meta_value = %d
             INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing')
             AND p.post_date > %s",
            $product_id,
            $date_from
        ) );

        $count = (int) $count;

        // Cache for 15 minutes
        set_transient( $cache_key, $count, 15 * MINUTE_IN_SECONDS );

        return $count;
    }

    /**
     * Get visitor ID.
     *
     * @return string Visitor ID.
     */
    private function get_visitor_id() {
        if ( isset( $_COOKIE['jdpd_visitor_id'] ) ) {
            return sanitize_text_field( $_COOKIE['jdpd_visitor_id'] );
        }

        // Use IP hash as fallback
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
        return md5( $ip . ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
    }

    /**
     * Check quantity limit for deals.
     *
     * @param bool   $apply Whether to apply.
     * @param array  $rule Rule data.
     * @param string $rule_id Rule ID.
     * @return bool Whether to apply.
     */
    public function check_quantity_limit( $apply, $rule, $rule_id ) {
        if ( ! $apply ) {
            return false;
        }

        // Check if rule has quantity limit
        if ( empty( $rule['quantity_limit'] ) ) {
            return $apply;
        }

        $limit = absint( $rule['quantity_limit'] );
        $used = $this->get_deal_usage( $rule_id );

        if ( $used >= $limit ) {
            return false; // Limit reached
        }

        return $apply;
    }

    /**
     * Get deal usage count.
     *
     * @param string $rule_id Rule ID.
     * @return int Usage count.
     */
    public function get_deal_usage( $rule_id ) {
        return (int) get_option( 'jdpd_deal_usage_' . $rule_id, 0 );
    }

    /**
     * Track deal usage on order completion.
     *
     * @param int $order_id Order ID.
     */
    public function track_deal_usage( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Check for applied rules
        $applied_rules = $order->get_meta( '_jdpd_applied_rules' );

        if ( empty( $applied_rules ) || ! is_array( $applied_rules ) ) {
            return;
        }

        foreach ( $applied_rules as $rule_id ) {
            $rules = get_option( 'jdpd_rules', array() );

            if ( isset( $rules[ $rule_id ] ) && ! empty( $rules[ $rule_id ]['quantity_limit'] ) ) {
                $current = $this->get_deal_usage( $rule_id );
                update_option( 'jdpd_deal_usage_' . $rule_id, $current + 1 );
            }
        }
    }

    /**
     * Enqueue scripts.
     */
    public function enqueue_scripts() {
        if ( ! is_product() ) {
            return;
        }

        wp_enqueue_script(
            'jdpd-urgency',
            JDPD_PLUGIN_URL . 'public/assets/js/urgency.js',
            array( 'jquery' ),
            JDPD_VERSION,
            true
        );

        wp_localize_script( 'jdpd-urgency', 'jdpdUrgency', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'jdpd_urgency_nonce' ),
            'refresh_interval' => 30000, // 30 seconds
        ) );
    }

    /**
     * AJAX: Get urgency data.
     */
    public function ajax_get_urgency_data() {
        check_ajax_referer( 'jdpd_urgency_nonce', 'nonce' );

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

        if ( ! $product_id ) {
            wp_send_json_error();
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error();
        }

        $settings = get_option( 'jdpd_urgency_settings', array() );

        wp_send_json_success( array(
            'views'     => $this->get_recent_views( $product_id ),
            'purchases' => $this->get_recent_purchases( $product_id, $settings['purchase_hours'] ?? 24 ),
            'stock'     => $product->managing_stock() ? $product->get_stock_quantity() : null,
        ) );
    }
}
