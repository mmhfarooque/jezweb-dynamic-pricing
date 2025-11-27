<?php
/**
 * Discount Calculator
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Discount Calculator class
 */
class JDPD_Discount_Calculator {

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into cart totals calculation
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'calculate_total_discounts' ), 99 );
    }

    /**
     * Calculate total discounts for cart
     *
     * @param WC_Cart $cart Cart object.
     */
    public function calculate_total_discounts( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        // Store discount summary for display
        $this->generate_discount_summary();
    }

    /**
     * Generate discount summary
     *
     * @return array
     */
    public function generate_discount_summary() {
        if ( ! WC()->cart ) {
            return array();
        }

        $summary = array(
            'product_discounts' => 0,
            'cart_discounts'    => 0,
            'gift_savings'      => 0,
            'total_savings'     => 0,
            'rules_applied'     => array(),
        );

        // Calculate product-level savings
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['jdpd_original_price'] ) ) {
                $original = $cart_item['jdpd_original_price'] * $cart_item['quantity'];
                $current = $cart_item['data']->get_price() * $cart_item['quantity'];
                $summary['product_discounts'] += max( 0, $original - $current );
            }

            // Gift savings
            if ( isset( $cart_item['jdpd_gift'] ) && $cart_item['jdpd_gift'] ) {
                $original = $cart_item['jdpd_original_price'] ?? 0;
                $current = $cart_item['data']->get_price();
                $summary['gift_savings'] += ( $original - $current ) * $cart_item['quantity'];
            }
        }

        // Calculate cart-level discounts from fees
        foreach ( WC()->cart->get_fees() as $fee ) {
            if ( $fee->amount < 0 ) {
                $summary['cart_discounts'] += abs( $fee->amount );
            }
        }

        $summary['total_savings'] = $summary['product_discounts'] + $summary['cart_discounts'] + $summary['gift_savings'];

        return $summary;
    }

    /**
     * Get total savings for current cart
     *
     * @return float
     */
    public function get_total_savings() {
        $summary = $this->generate_discount_summary();
        return $summary['total_savings'];
    }

    /**
     * Calculate best discount for product
     *
     * @param WC_Product $product  Product object.
     * @param int        $quantity Quantity.
     * @return array
     */
    public function get_best_discount_for_product( $product, $quantity = 1 ) {
        $best_discount = array(
            'type'       => '',
            'value'      => 0,
            'amount'     => 0,
            'final_price' => $product->get_price(),
            'rule_id'    => 0,
            'rule_name'  => '',
        );

        $original_price = $product->get_price();
        $rules = jdpd_get_active_rules( 'price_rule' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( ! $rule_obj->is_active() || ! $rule_obj->applies_to_product( $product ) ) {
                continue;
            }

            // Check conditions
            $conditions = new JDPD_Conditions();
            if ( ! $conditions->check_rule_conditions( $rule_obj ) ) {
                continue;
            }

            // Calculate discount
            $discount_amount = $this->calculate_rule_discount( $rule_obj, $original_price, $quantity );

            if ( $discount_amount > $best_discount['amount'] ) {
                $best_discount = array(
                    'type'        => $rule_obj->get( 'discount_type' ),
                    'value'       => $rule_obj->get( 'discount_value' ),
                    'amount'      => $discount_amount,
                    'final_price' => max( 0, $original_price - $discount_amount ),
                    'rule_id'     => $rule_obj->get_id(),
                    'rule_name'   => $rule_obj->get( 'name' ),
                );

                // If exclusive rule, stop here
                if ( $rule_obj->is_exclusive() ) {
                    break;
                }
            }
        }

        return $best_discount;
    }

    /**
     * Calculate discount from a rule
     *
     * @param JDPD_Rule $rule     Rule object.
     * @param float     $price    Original price.
     * @param int       $quantity Quantity.
     * @return float
     */
    private function calculate_rule_discount( $rule, $price, $quantity = 1 ) {
        $discount_type = $rule->get( 'discount_type' );
        $discount_value = $rule->get( 'discount_value' );

        // Check for quantity-based pricing
        $quantity_ranges = $rule->get_quantity_ranges();

        if ( ! empty( $quantity_ranges ) ) {
            foreach ( $quantity_ranges as $range ) {
                $min = (int) $range->min_quantity;
                $max = $range->max_quantity ? (int) $range->max_quantity : PHP_INT_MAX;

                if ( $quantity >= $min && $quantity <= $max ) {
                    $discount_type = $range->discount_type;
                    $discount_value = $range->discount_value;
                    break;
                }
            }
        }

        return jdpd_calculate_discount( $price, $discount_type, $discount_value );
    }

    /**
     * Get all applicable discounts for product
     *
     * @param WC_Product $product Product object.
     * @return array
     */
    public function get_all_discounts_for_product( $product ) {
        $discounts = array();
        $rules = jdpd_get_active_rules( 'price_rule' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( ! $rule_obj->is_active() || ! $rule_obj->applies_to_product( $product ) ) {
                continue;
            }

            $conditions = new JDPD_Conditions();
            if ( ! $conditions->check_rule_conditions( $rule_obj ) ) {
                continue;
            }

            $discounts[] = array(
                'rule_id'        => $rule_obj->get_id(),
                'rule_name'      => $rule_obj->get( 'name' ),
                'discount_type'  => $rule_obj->get( 'discount_type' ),
                'discount_value' => $rule_obj->get( 'discount_value' ),
                'quantity_ranges' => $rule_obj->get_quantity_ranges(),
                'exclusive'      => $rule_obj->is_exclusive(),
            );
        }

        return $discounts;
    }

    /**
     * Calculate price for specific quantity
     *
     * @param WC_Product $product  Product object.
     * @param int        $quantity Quantity.
     * @return float
     */
    public function get_price_for_quantity( $product, $quantity ) {
        $original_price = $product->get_price();
        $discount = $this->get_best_discount_for_product( $product, $quantity );

        return $discount['final_price'];
    }

    /**
     * Get quantity price table data
     *
     * @param WC_Product $product Product object.
     * @return array
     */
    public function get_quantity_price_table( $product ) {
        $table = array();
        $original_price = $product->get_price();
        $rules = jdpd_get_active_rules( 'price_rule' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( ! $rule_obj->is_active() || ! $rule_obj->applies_to_product( $product ) ) {
                continue;
            }

            $quantity_ranges = $rule_obj->get_quantity_ranges();

            if ( empty( $quantity_ranges ) ) {
                continue;
            }

            foreach ( $quantity_ranges as $range ) {
                $discount = jdpd_calculate_discount( $original_price, $range->discount_type, $range->discount_value );
                $discounted_price = max( 0, $original_price - $discount );

                $table[] = array(
                    'min_qty'          => $range->min_quantity,
                    'max_qty'          => $range->max_quantity,
                    'discount_type'    => $range->discount_type,
                    'discount_value'   => $range->discount_value,
                    'original_price'   => $original_price,
                    'discounted_price' => $discounted_price,
                    'savings'          => $discount,
                    'savings_percent'  => $original_price > 0 ? round( ( $discount / $original_price ) * 100 ) : 0,
                );
            }

            // Only show from first applicable rule
            if ( ! empty( $table ) ) {
                break;
            }
        }

        return $table;
    }
}
