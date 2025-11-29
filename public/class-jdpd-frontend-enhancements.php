<?php
/**
 * Frontend Enhancements Class
 *
 * Adds upsell messages, progress bars, countdown timers, and social proof.
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JDPD_Frontend_Enhancements Class
 */
class JDPD_Frontend_Enhancements {

    /**
     * Instance
     *
     * @var JDPD_Frontend_Enhancements
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return JDPD_Frontend_Enhancements
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
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Product page enhancements
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_upsell_message' ), 25 );
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_tier_progress' ), 26 );

        // Cart page enhancements
        add_action( 'woocommerce_before_cart', array( $this, 'display_cart_upsell' ), 10 );
        add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'display_savings_summary' ), 10 );

        // Checkout enhancements
        add_action( 'woocommerce_review_order_before_payment', array( $this, 'display_checkout_savings' ), 10 );

        // Countdown timer for time-limited offers
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_countdown_timer' ), 15 );

        // Social proof
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_social_proof' ), 27 );

        // AJAX handlers
        add_action( 'wp_ajax_jdpd_get_upsell_message', array( $this, 'ajax_get_upsell_message' ) );
        add_action( 'wp_ajax_nopriv_jdpd_get_upsell_message', array( $this, 'ajax_get_upsell_message' ) );
    }

    /**
     * Display upsell message (Buy X more to save Y%)
     */
    public function display_upsell_message() {
        if ( 'yes' !== get_option( 'jdpd_show_upsell_message', 'yes' ) ) {
            return;
        }

        global $product;

        if ( ! $product ) {
            return;
        }

        $upsell = $this->get_upsell_opportunity( $product );

        if ( ! $upsell ) {
            return;
        }

        ?>
        <div class="jdpd-upsell-message">
            <span class="jdpd-upsell-icon">💡</span>
            <span class="jdpd-upsell-text">
                <?php
                printf(
                    /* translators: 1: quantity needed, 2: discount percentage */
                    esc_html__( 'Buy %1$d more to save %2$s!', 'jezweb-dynamic-pricing' ),
                    esc_html( $upsell['quantity_needed'] ),
                    esc_html( $upsell['discount_label'] )
                );
                ?>
            </span>
        </div>
        <?php
    }

    /**
     * Get upsell opportunity for product
     *
     * @param WC_Product $product Product object.
     * @return array|null
     */
    private function get_upsell_opportunity( $product ) {
        $rules = jdpd_get_active_rules( 'price_rule' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( ! $rule_obj->applies_to_product( $product->get_id() ) ) {
                continue;
            }

            $ranges = $rule_obj->get_quantity_ranges();

            if ( empty( $ranges ) ) {
                continue;
            }

            // Find the next tier
            $current_qty = 1;

            // Get cart quantity if in cart
            if ( WC()->cart ) {
                foreach ( WC()->cart->get_cart() as $cart_item ) {
                    if ( $cart_item['product_id'] === $product->get_id() || $cart_item['variation_id'] === $product->get_id() ) {
                        $current_qty += $cart_item['quantity'];
                    }
                }
            }

            foreach ( $ranges as $range ) {
                if ( $range->min_quantity > $current_qty ) {
                    $quantity_needed = $range->min_quantity - $current_qty;

                    $discount_label = 'percentage' === $range->discount_type
                        ? $range->discount_value . '%'
                        : wc_price( $range->discount_value );

                    return array(
                        'quantity_needed' => $quantity_needed,
                        'discount_value'  => $range->discount_value,
                        'discount_type'   => $range->discount_type,
                        'discount_label'  => $discount_label,
                        'rule_name'       => $rule_obj->get( 'name' ),
                    );
                }
            }
        }

        return null;
    }

    /**
     * Display tier progress bar
     */
    public function display_tier_progress() {
        if ( 'yes' !== get_option( 'jdpd_show_tier_progress', 'yes' ) ) {
            return;
        }

        global $product;

        if ( ! $product ) {
            return;
        }

        $progress = $this->get_tier_progress( $product );

        if ( ! $progress ) {
            return;
        }

        ?>
        <div class="jdpd-tier-progress">
            <div class="jdpd-tier-progress-header">
                <span class="jdpd-tier-current"><?php echo esc_html( $progress['current_tier_label'] ); ?></span>
                <span class="jdpd-tier-next"><?php echo esc_html( $progress['next_tier_label'] ); ?></span>
            </div>
            <div class="jdpd-tier-progress-bar">
                <div class="jdpd-tier-progress-fill" style="width: <?php echo esc_attr( $progress['percentage'] ); ?>%"></div>
            </div>
            <div class="jdpd-tier-progress-text">
                <?php
                printf(
                    /* translators: 1: quantity needed, 2: discount */
                    esc_html__( 'Add %1$d more for %2$s discount', 'jezweb-dynamic-pricing' ),
                    esc_html( $progress['quantity_to_next'] ),
                    esc_html( $progress['next_tier_discount'] )
                );
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Get tier progress for product
     *
     * @param WC_Product $product Product object.
     * @return array|null
     */
    private function get_tier_progress( $product ) {
        $rules = jdpd_get_active_rules( 'price_rule' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( ! $rule_obj->applies_to_product( $product->get_id() ) ) {
                continue;
            }

            $ranges = $rule_obj->get_quantity_ranges();

            if ( count( $ranges ) < 2 ) {
                continue;
            }

            // Get current cart quantity
            $current_qty = 0;
            if ( WC()->cart ) {
                foreach ( WC()->cart->get_cart() as $cart_item ) {
                    if ( $cart_item['product_id'] === $product->get_id() || $cart_item['variation_id'] === $product->get_id() ) {
                        $current_qty += $cart_item['quantity'];
                    }
                }
            }

            // Find current and next tier
            $current_tier = null;
            $next_tier = null;

            foreach ( $ranges as $i => $range ) {
                if ( $current_qty >= $range->min_quantity ) {
                    $current_tier = $range;
                    if ( isset( $ranges[ $i + 1 ] ) ) {
                        $next_tier = $ranges[ $i + 1 ];
                    }
                } elseif ( ! $next_tier ) {
                    $next_tier = $range;
                    break;
                }
            }

            if ( ! $next_tier ) {
                return null; // Already at max tier
            }

            $quantity_to_next = $next_tier->min_quantity - $current_qty;
            $percentage = $current_tier
                ? min( 100, ( ( $current_qty - $current_tier->min_quantity ) / ( $next_tier->min_quantity - $current_tier->min_quantity ) ) * 100 )
                : min( 100, ( $current_qty / $next_tier->min_quantity ) * 100 );

            return array(
                'current_qty'        => $current_qty,
                'current_tier_label' => $current_tier ? sprintf( '%d+ items: %s%% off', $current_tier->min_quantity, $current_tier->discount_value ) : __( 'No discount', 'jezweb-dynamic-pricing' ),
                'next_tier_label'    => sprintf( '%d+ items: %s%% off', $next_tier->min_quantity, $next_tier->discount_value ),
                'quantity_to_next'   => $quantity_to_next,
                'next_tier_discount' => $next_tier->discount_value . '%',
                'percentage'         => $percentage,
            );
        }

        return null;
    }

    /**
     * Display cart upsell message
     */
    public function display_cart_upsell() {
        if ( 'yes' !== get_option( 'jdpd_show_cart_upsell', 'yes' ) ) {
            return;
        }

        $upsell = $this->get_cart_upsell();

        if ( ! $upsell ) {
            return;
        }

        ?>
        <div class="jdpd-cart-upsell woocommerce-info">
            <span class="jdpd-cart-upsell-icon">🎁</span>
            <?php echo wp_kses_post( $upsell['message'] ); ?>
            <?php if ( ! empty( $upsell['progress'] ) ) : ?>
                <div class="jdpd-cart-upsell-progress">
                    <div class="jdpd-progress-bar-container">
                        <div class="jdpd-progress-bar-fill" style="width: <?php echo esc_attr( $upsell['progress'] ); ?>%"></div>
                    </div>
                    <span class="jdpd-progress-text"><?php echo esc_html( round( $upsell['progress'] ) ); ?>%</span>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get cart upsell opportunity
     *
     * @return array|null
     */
    private function get_cart_upsell() {
        if ( ! WC()->cart ) {
            return null;
        }

        $cart_total = WC()->cart->get_subtotal();
        $rules = jdpd_get_active_rules( 'cart_rule' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );
            $conditions = $rule_obj->get( 'conditions' );

            foreach ( $conditions as $condition ) {
                if ( 'cart_total' === $condition['type'] && in_array( $condition['operator'], array( 'greater', 'greater_equal' ), true ) ) {
                    $threshold = floatval( $condition['value'] );

                    if ( $cart_total < $threshold ) {
                        $amount_needed = $threshold - $cart_total;
                        $progress = ( $cart_total / $threshold ) * 100;

                        $discount_text = 'percentage' === $rule_obj->get( 'discount_type' )
                            ? $rule_obj->get( 'discount_value' ) . '% off'
                            : wc_price( $rule_obj->get( 'discount_value' ) ) . ' off';

                        return array(
                            'message'  => sprintf(
                                /* translators: 1: amount needed, 2: discount */
                                __( 'Spend %1$s more to get <strong>%2$s</strong> your order!', 'jezweb-dynamic-pricing' ),
                                wc_price( $amount_needed ),
                                $discount_text
                            ),
                            'progress' => $progress,
                            'amount'   => $amount_needed,
                        );
                    }
                }
            }
        }

        // Check for free shipping threshold
        $free_shipping_threshold = get_option( 'jdpd_free_shipping_threshold', 0 );
        if ( $free_shipping_threshold > 0 && $cart_total < $free_shipping_threshold ) {
            $amount_needed = $free_shipping_threshold - $cart_total;
            $progress = ( $cart_total / $free_shipping_threshold ) * 100;

            return array(
                'message'  => sprintf(
                    /* translators: %s: amount needed */
                    __( 'Spend %s more for <strong>FREE shipping</strong>!', 'jezweb-dynamic-pricing' ),
                    wc_price( $amount_needed )
                ),
                'progress' => $progress,
                'amount'   => $amount_needed,
            );
        }

        return null;
    }

    /**
     * Display savings summary
     */
    public function display_savings_summary() {
        if ( 'yes' !== get_option( 'jdpd_show_cart_savings', 'yes' ) ) {
            return;
        }

        $savings = WC()->session->get( 'jdpd_total_savings', 0 );

        if ( $savings <= 0 ) {
            return;
        }

        ?>
        <tr class="jdpd-savings-row">
            <th><?php esc_html_e( 'Your Savings', 'jezweb-dynamic-pricing' ); ?></th>
            <td data-title="<?php esc_attr_e( 'Your Savings', 'jezweb-dynamic-pricing' ); ?>">
                <span class="jdpd-savings-amount">-<?php echo wc_price( $savings ); ?></span>
            </td>
        </tr>
        <?php
    }

    /**
     * Display checkout savings
     */
    public function display_checkout_savings() {
        $savings = WC()->session->get( 'jdpd_total_savings', 0 );

        if ( $savings <= 0 ) {
            return;
        }

        ?>
        <div class="jdpd-checkout-savings">
            <span class="jdpd-checkout-savings-icon">🎉</span>
            <span class="jdpd-checkout-savings-text">
                <?php
                printf(
                    /* translators: %s: savings amount */
                    esc_html__( 'Great news! You\'re saving %s on this order!', 'jezweb-dynamic-pricing' ),
                    wc_price( $savings )
                );
                ?>
            </span>
        </div>
        <?php
    }

    /**
     * Display countdown timer for time-limited offers
     */
    public function display_countdown_timer() {
        if ( 'yes' !== get_option( 'jdpd_show_countdown', 'yes' ) ) {
            return;
        }

        global $product;

        if ( ! $product ) {
            return;
        }

        $timer_data = $this->get_countdown_data( $product );

        if ( ! $timer_data ) {
            return;
        }

        ?>
        <div class="jdpd-countdown-timer" data-end-time="<?php echo esc_attr( $timer_data['end_time'] ); ?>">
            <div class="jdpd-countdown-header">
                <span class="jdpd-countdown-icon">⏰</span>
                <span class="jdpd-countdown-label"><?php echo esc_html( $timer_data['label'] ); ?></span>
            </div>
            <div class="jdpd-countdown-display">
                <div class="jdpd-countdown-unit">
                    <span class="jdpd-countdown-value jdpd-days">00</span>
                    <span class="jdpd-countdown-unit-label"><?php esc_html_e( 'Days', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
                <div class="jdpd-countdown-separator">:</div>
                <div class="jdpd-countdown-unit">
                    <span class="jdpd-countdown-value jdpd-hours">00</span>
                    <span class="jdpd-countdown-unit-label"><?php esc_html_e( 'Hours', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
                <div class="jdpd-countdown-separator">:</div>
                <div class="jdpd-countdown-unit">
                    <span class="jdpd-countdown-value jdpd-minutes">00</span>
                    <span class="jdpd-countdown-unit-label"><?php esc_html_e( 'Mins', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
                <div class="jdpd-countdown-separator">:</div>
                <div class="jdpd-countdown-unit">
                    <span class="jdpd-countdown-value jdpd-seconds">00</span>
                    <span class="jdpd-countdown-unit-label"><?php esc_html_e( 'Secs', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get countdown data for product
     *
     * @param WC_Product $product Product object.
     * @return array|null
     */
    private function get_countdown_data( $product ) {
        $rules = jdpd_get_active_rules();

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( ! $rule_obj->applies_to_product( $product->get_id() ) ) {
                continue;
            }

            $schedule_to = $rule_obj->get( 'schedule_to' );

            if ( ! $schedule_to ) {
                continue;
            }

            $end_timestamp = strtotime( $schedule_to );

            if ( $end_timestamp <= current_time( 'timestamp' ) ) {
                continue;
            }

            // Only show countdown if ending within 7 days
            if ( $end_timestamp > ( current_time( 'timestamp' ) + ( 7 * DAY_IN_SECONDS ) ) ) {
                continue;
            }

            return array(
                'end_time' => $end_timestamp * 1000, // JavaScript timestamp
                'label'    => sprintf(
                    /* translators: %s: discount percentage */
                    __( 'Sale ends soon! %s off', 'jezweb-dynamic-pricing' ),
                    $rule_obj->get( 'discount_value' ) . '%'
                ),
                'rule_name' => $rule_obj->get( 'name' ),
            );
        }

        return null;
    }

    /**
     * Display social proof
     */
    public function display_social_proof() {
        if ( 'yes' !== get_option( 'jdpd_show_social_proof', 'no' ) ) {
            return;
        }

        global $product;

        if ( ! $product ) {
            return;
        }

        $proof_data = $this->get_social_proof_data( $product );

        if ( ! $proof_data ) {
            return;
        }

        ?>
        <div class="jdpd-social-proof">
            <span class="jdpd-social-proof-icon">🔥</span>
            <span class="jdpd-social-proof-text"><?php echo esc_html( $proof_data['message'] ); ?></span>
        </div>
        <?php
    }

    /**
     * Get social proof data
     *
     * @param WC_Product $product Product object.
     * @return array|null
     */
    private function get_social_proof_data( $product ) {
        global $wpdb;

        // Get recent orders with this product (last 24 hours)
        $recent_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT oi.order_id) FROM {$wpdb->prefix}woocommerce_order_items oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
            WHERE oim.meta_key = '_product_id' AND oim.meta_value = %d
            AND p.post_date > %s
            AND p.post_status IN ('wc-completed', 'wc-processing')",
            $product->get_id(),
            date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
        ) );

        if ( $recent_count > 0 ) {
            return array(
                'message' => sprintf(
                    /* translators: %d: number of people */
                    _n(
                        '%d person bought this with a discount today!',
                        '%d people bought this with a discount today!',
                        $recent_count,
                        'jezweb-dynamic-pricing'
                    ),
                    $recent_count
                ),
                'count'   => $recent_count,
            );
        }

        return null;
    }

    /**
     * AJAX get upsell message
     */
    public function ajax_get_upsell_message() {
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $quantity = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;

        if ( ! $product_id ) {
            wp_send_json_error();
        }

        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            wp_send_json_error();
        }

        $upsell = $this->get_upsell_opportunity( $product );

        if ( ! $upsell ) {
            wp_send_json_success( array( 'message' => '' ) );
        }

        $message = sprintf(
            /* translators: 1: quantity needed, 2: discount */
            __( 'Buy %1$d more to save %2$s!', 'jezweb-dynamic-pricing' ),
            $upsell['quantity_needed'],
            $upsell['discount_label']
        );

        wp_send_json_success( array( 'message' => $message ) );
    }
}
