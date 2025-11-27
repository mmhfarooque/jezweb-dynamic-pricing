<?php
/**
 * Cart Rules Handler
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cart Rules class
 */
class JDPD_Cart_Rules {

    /**
     * Applied rules
     *
     * @var array
     */
    private $applied_rules = array();

    /**
     * Constructor
     */
    public function __construct() {
        if ( 'yes' !== get_option( 'jdpd_enable_plugin', 'yes' ) ) {
            jdpd_log( 'Cart rules disabled - plugin not enabled', 'debug' );
            return;
        }

        jdpd_log( 'Cart rules initialized', 'debug' );

        // Apply cart discounts
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_cart_discounts' ), 20 );

        // Free shipping
        add_filter( 'woocommerce_shipping_free_shipping_is_available', array( $this, 'check_free_shipping' ), 99, 3 );

        // Track applied rules on checkout
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'record_applied_rules' ), 10, 3 );

        // Display applied discounts
        add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'display_cart_savings' ) );
        add_action( 'woocommerce_review_order_before_order_total', array( $this, 'display_cart_savings' ) );
    }

    /**
     * Apply cart discounts
     *
     * @param WC_Cart $cart Cart object.
     */
    public function apply_cart_discounts( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( did_action( 'woocommerce_cart_calculate_fees' ) > 1 ) {
            return;
        }

        $this->applied_rules = array();

        // Get all active cart rules
        $rules = jdpd_get_active_rules( 'cart_rule' );

        if ( empty( $rules ) ) {
            return;
        }

        $cart_subtotal = $cart->get_subtotal();
        $cart_items = $cart->get_cart_contents_count();
        $applied_exclusive = false;

        foreach ( $rules as $rule ) {
            if ( $applied_exclusive ) {
                break;
            }

            $rule_obj = new JDPD_Rule( $rule );

            // Check if rule is active
            if ( ! $rule_obj->is_active() ) {
                continue;
            }

            // Check conditions
            $conditions = new JDPD_Conditions();
            if ( ! $conditions->check_rule_conditions( $rule_obj ) ) {
                continue;
            }

            // Calculate discount
            $discount = $this->calculate_cart_discount( $rule_obj, $cart );

            if ( $discount > 0 ) {
                // Get discount label
                $label = get_option( 'jdpd_cart_discount_label', __( 'Discount', 'jezweb-dynamic-pricing' ) );
                $rule_name = $rule_obj->get( 'name' );

                if ( 'yes' === get_option( 'jdpd_show_cart_discount_label', 'yes' ) ) {
                    $label = $rule_name;
                }

                // Add negative fee (discount)
                $cart->add_fee( $label, -$discount, true );
                jdpd_log( sprintf( 'Applied cart rule "%s" (ID: %d): discount %.2f', $rule_name, $rule_obj->get_id(), $discount ), 'info' );

                // Track applied rule
                $this->applied_rules[] = array(
                    'rule_id' => $rule_obj->get_id(),
                    'name'    => $rule_name,
                    'amount'  => $discount,
                );

                if ( $rule_obj->is_exclusive() ) {
                    $applied_exclusive = true;
                }
            }
        }
    }

    /**
     * Calculate cart discount for a rule
     *
     * @param JDPD_Rule $rule Rule object.
     * @param WC_Cart   $cart Cart object.
     * @return float
     */
    private function calculate_cart_discount( $rule, $cart ) {
        $discount_type = $rule->get( 'discount_type' );
        $discount_value = $rule->get( 'discount_value' );
        $cart_subtotal = $cart->get_subtotal();

        // Check cart conditions from rule conditions
        $conditions = $rule->get_conditions();
        foreach ( $conditions as $condition ) {
            if ( 'cart_total' === $condition['type'] ) {
                $required_total = floatval( $condition['value'] );
                $operator = $condition['operator'];

                if ( ! $this->compare_values( $cart_subtotal, $required_total, $operator ) ) {
                    return 0;
                }
            }

            if ( 'cart_items' === $condition['type'] ) {
                $required_items = intval( $condition['value'] );
                $operator = $condition['operator'];
                $cart_items = $cart->get_cart_contents_count();

                if ( ! $this->compare_values( $cart_items, $required_items, $operator ) ) {
                    return 0;
                }
            }
        }

        // Check which products the rule applies to
        $apply_to = $rule->get( 'apply_to' );
        $discount_base = 0;

        if ( 'all_products' === $apply_to ) {
            $discount_base = $cart_subtotal;
        } else {
            // Calculate subtotal for applicable items only
            foreach ( $cart->get_cart() as $cart_item ) {
                $product = $cart_item['data'];
                if ( $rule->applies_to_product( $product ) ) {
                    $discount_base += $cart_item['line_subtotal'];
                }
            }
        }

        if ( $discount_base <= 0 ) {
            return 0;
        }

        return jdpd_calculate_discount( $discount_base, $discount_type, $discount_value );
    }

    /**
     * Compare values based on operator
     *
     * @param mixed  $value1   First value.
     * @param mixed  $value2   Second value.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function compare_values( $value1, $value2, $operator ) {
        switch ( $operator ) {
            case 'equals':
                return $value1 == $value2;
            case 'not_equals':
                return $value1 != $value2;
            case 'greater':
                return $value1 > $value2;
            case 'less':
                return $value1 < $value2;
            case 'greater_equal':
                return $value1 >= $value2;
            case 'less_equal':
                return $value1 <= $value2;
            default:
                return false;
        }
    }

    /**
     * Check if free shipping should be enabled
     *
     * @param bool          $is_available Whether free shipping is available.
     * @param array         $package      Shipping package.
     * @param WC_Shipping_Free_Shipping $instance Shipping method instance.
     * @return bool
     */
    public function check_free_shipping( $is_available, $package, $instance ) {
        // Check cart rules for free shipping
        $rules = jdpd_get_active_rules( 'cart_rule' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( ! $rule_obj->is_active() ) {
                continue;
            }

            // Check conditions
            $conditions = new JDPD_Conditions();
            if ( ! $conditions->check_rule_conditions( $rule_obj ) ) {
                continue;
            }

            // Check if this rule enables free shipping
            $rule_conditions = $rule_obj->get_conditions();
            foreach ( $rule_conditions as $condition ) {
                if ( 'free_shipping' === $condition['type'] && 'yes' === $condition['value'] ) {
                    // Check if cart meets minimum
                    $cart_subtotal = WC()->cart->get_subtotal();
                    foreach ( $rule_conditions as $cond ) {
                        if ( 'cart_total' === $cond['type'] ) {
                            $required = floatval( $cond['value'] );
                            if ( $this->compare_values( $cart_subtotal, $required, $cond['operator'] ) ) {
                                return true;
                            }
                        }
                    }
                }
            }
        }

        return $is_available;
    }

    /**
     * Record applied rules when order is placed
     *
     * @param int      $order_id    Order ID.
     * @param array    $posted_data Posted data.
     * @param WC_Order $order       Order object.
     */
    public function record_applied_rules( $order_id, $posted_data, $order ) {
        if ( empty( $this->applied_rules ) ) {
            return;
        }

        foreach ( $this->applied_rules as $applied ) {
            $rule = new JDPD_Rule( $applied['rule_id'] );
            $rule->record_usage( $order_id, $applied['amount'] );
        }

        // Store applied rules in order meta
        $order->update_meta_data( '_jdpd_applied_rules', $this->applied_rules );
        $order->save();
    }

    /**
     * Display cart savings
     */
    public function display_cart_savings() {
        if ( 'yes' !== get_option( 'jdpd_show_cart_savings', 'yes' ) ) {
            return;
        }

        if ( empty( $this->applied_rules ) ) {
            return;
        }

        $total_savings = array_sum( wp_list_pluck( $this->applied_rules, 'amount' ) );

        if ( $total_savings > 0 ) {
            ?>
            <tr class="jdpd-cart-savings">
                <th><?php esc_html_e( 'You Save', 'jezweb-dynamic-pricing' ); ?></th>
                <td data-title="<?php esc_attr_e( 'You Save', 'jezweb-dynamic-pricing' ); ?>">
                    <?php echo wc_price( $total_savings ); ?>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Get applied rules
     *
     * @return array
     */
    public function get_applied_rules() {
        return $this->applied_rules;
    }

    /**
     * Check if any cart rules apply
     *
     * @return bool
     */
    public function has_applicable_rules() {
        $rules = jdpd_get_active_rules( 'cart_rule' );
        return ! empty( $rules );
    }

    /**
     * Get next tier message for cart upsell
     *
     * @return string|null
     */
    public function get_next_tier_message() {
        if ( ! WC()->cart ) {
            return null;
        }

        $cart_subtotal = WC()->cart->get_subtotal();
        $rules = jdpd_get_active_rules( 'cart_rule' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );
            $conditions = $rule_obj->get_conditions();

            foreach ( $conditions as $condition ) {
                if ( 'cart_total' === $condition['type'] && 'greater_equal' === $condition['operator'] ) {
                    $required = floatval( $condition['value'] );
                    if ( $cart_subtotal < $required ) {
                        $remaining = $required - $cart_subtotal;
                        $discount_value = $rule_obj->get( 'discount_value' );
                        $discount_type = $rule_obj->get( 'discount_type' );

                        $discount_text = 'percentage' === $discount_type
                            ? $discount_value . '%'
                            : wc_price( $discount_value );

                        return sprintf(
                            /* translators: 1: amount needed, 2: discount amount */
                            __( 'Add %1$s more to get %2$s off!', 'jezweb-dynamic-pricing' ),
                            wc_price( $remaining ),
                            $discount_text
                        );
                    }
                }
            }
        }

        return null;
    }
}
