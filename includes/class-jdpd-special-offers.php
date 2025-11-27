<?php
/**
 * Special Offers Handler (BOGO, Buy X Get Y, etc.)
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Special Offers class
 */
class JDPD_Special_Offers {

    /**
     * Applied offers
     *
     * @var array
     */
    private $applied_offers = array();

    /**
     * Constructor
     */
    public function __construct() {
        if ( 'yes' !== get_option( 'jdpd_enable_plugin', 'yes' ) ) {
            jdpd_log( 'Special offers disabled - plugin not enabled', 'debug' );
            return;
        }

        jdpd_log( 'Special offers initialized', 'debug' );

        // Apply special offers to cart
        add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'apply_special_offers' ), 99 );
        add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'apply_special_offers' ), 99 );
        add_action( 'woocommerce_cart_item_removed', array( $this, 'apply_special_offers' ), 99 );

        // Calculate cart totals
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_special_offer_discounts' ), 99 );
    }

    /**
     * Apply special offers to cart
     *
     * @param WC_Cart|null $cart Cart object.
     */
    public function apply_special_offers( $cart = null ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( ! WC()->cart ) {
            return;
        }

        $this->applied_offers = array();

        // Get active special offers
        $rules = jdpd_get_active_rules( 'special_offer' );

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

            // Apply the offer based on type
            $this->process_special_offer( $rule_obj );
        }
    }

    /**
     * Process a special offer
     *
     * @param JDPD_Rule $rule Rule object.
     */
    private function process_special_offer( $rule ) {
        $conditions = $rule->get_conditions();
        $offer_type = 'bogo'; // Default type

        // Get offer type from conditions
        foreach ( $conditions as $condition ) {
            if ( 'offer_type' === $condition['type'] ) {
                $offer_type = $condition['value'];
                break;
            }
        }

        switch ( $offer_type ) {
            case 'bogo':
                $this->apply_bogo( $rule );
                break;
            case 'buy_x_get_y':
                $this->apply_buy_x_get_y( $rule );
                break;
            case 'buy_x_for_y':
                $this->apply_buy_x_for_y( $rule );
                break;
            case 'x_for_price_of_y':
                $this->apply_x_for_price_of_y( $rule );
                break;
        }
    }

    /**
     * Apply BOGO offer (Buy One Get One)
     *
     * @param JDPD_Rule $rule Rule object.
     */
    private function apply_bogo( $rule ) {
        $cart = WC()->cart;
        $discount_value = $rule->get( 'discount_value' ); // Percentage off the second item

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];

            if ( ! $rule->applies_to_product( $product ) ) {
                continue;
            }

            $quantity = $cart_item['quantity'];

            // For every 2 items, discount one
            if ( $quantity >= 2 ) {
                $free_items = floor( $quantity / 2 );
                $item_price = $product->get_price();
                $discount = ( $item_price * $free_items ) * ( $discount_value / 100 );

                $this->applied_offers[ $cart_item_key ] = array(
                    'rule_id'       => $rule->get_id(),
                    'discount'      => $discount,
                    'type'          => 'bogo',
                    'free_quantity' => $free_items,
                );
            }
        }
    }

    /**
     * Apply Buy X Get Y offer
     *
     * @param JDPD_Rule $rule Rule object.
     */
    private function apply_buy_x_get_y( $rule ) {
        $cart = WC()->cart;
        $conditions = $rule->get_conditions();

        $buy_qty = 1;
        $get_qty = 1;
        $get_discount = 100; // Default 100% = free

        foreach ( $conditions as $condition ) {
            if ( 'buy_quantity' === $condition['type'] ) {
                $buy_qty = intval( $condition['value'] );
            }
            if ( 'get_quantity' === $condition['type'] ) {
                $get_qty = intval( $condition['value'] );
            }
            if ( 'get_discount' === $condition['type'] ) {
                $get_discount = floatval( $condition['value'] );
            }
        }

        $total_required = $buy_qty + $get_qty;

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];

            if ( ! $rule->applies_to_product( $product ) ) {
                continue;
            }

            $quantity = $cart_item['quantity'];

            if ( $quantity >= $total_required ) {
                // How many complete sets
                $sets = floor( $quantity / $total_required );
                $item_price = $product->get_price();
                $discount = ( $item_price * $get_qty * $sets ) * ( $get_discount / 100 );

                $this->applied_offers[ $cart_item_key ] = array(
                    'rule_id'       => $rule->get_id(),
                    'discount'      => $discount,
                    'type'          => 'buy_x_get_y',
                    'free_quantity' => $get_qty * $sets,
                );
            }
        }
    }

    /**
     * Apply Buy X for Y price offer (e.g., 3 for $10)
     *
     * @param JDPD_Rule $rule Rule object.
     */
    private function apply_buy_x_for_y( $rule ) {
        $cart = WC()->cart;
        $conditions = $rule->get_conditions();

        $buy_qty = 3; // Default
        $fixed_price = 0;

        foreach ( $conditions as $condition ) {
            if ( 'buy_quantity' === $condition['type'] ) {
                $buy_qty = intval( $condition['value'] );
            }
            if ( 'fixed_price' === $condition['type'] ) {
                $fixed_price = floatval( $condition['value'] );
            }
        }

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];

            if ( ! $rule->applies_to_product( $product ) ) {
                continue;
            }

            $quantity = $cart_item['quantity'];

            if ( $quantity >= $buy_qty ) {
                $sets = floor( $quantity / $buy_qty );
                $remaining = $quantity % $buy_qty;
                $item_price = $product->get_price();

                // Calculate what they would normally pay
                $normal_price = $item_price * ( $sets * $buy_qty );
                // Calculate what they pay with offer
                $offer_price = $fixed_price * $sets;
                $discount = $normal_price - $offer_price;

                $this->applied_offers[ $cart_item_key ] = array(
                    'rule_id'  => $rule->get_id(),
                    'discount' => max( 0, $discount ),
                    'type'     => 'buy_x_for_y',
                    'sets'     => $sets,
                );
            }
        }
    }

    /**
     * Apply X for price of Y offer (e.g., 3 for the price of 2)
     *
     * @param JDPD_Rule $rule Rule object.
     */
    private function apply_x_for_price_of_y( $rule ) {
        $cart = WC()->cart;
        $conditions = $rule->get_conditions();

        $get_qty = 3; // Get 3
        $pay_qty = 2; // Pay for 2

        foreach ( $conditions as $condition ) {
            if ( 'get_quantity' === $condition['type'] ) {
                $get_qty = intval( $condition['value'] );
            }
            if ( 'pay_quantity' === $condition['type'] ) {
                $pay_qty = intval( $condition['value'] );
            }
        }

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];

            if ( ! $rule->applies_to_product( $product ) ) {
                continue;
            }

            $quantity = $cart_item['quantity'];

            if ( $quantity >= $get_qty ) {
                $sets = floor( $quantity / $get_qty );
                $item_price = $product->get_price();

                // Free items per set
                $free_per_set = $get_qty - $pay_qty;
                $discount = ( $item_price * $free_per_set ) * $sets;

                $this->applied_offers[ $cart_item_key ] = array(
                    'rule_id'       => $rule->get_id(),
                    'discount'      => $discount,
                    'type'          => 'x_for_price_of_y',
                    'free_quantity' => $free_per_set * $sets,
                );
            }
        }
    }

    /**
     * Apply special offer discounts to cart
     *
     * @param WC_Cart $cart Cart object.
     */
    public function apply_special_offer_discounts( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( did_action( 'woocommerce_before_calculate_totals' ) > 1 ) {
            return;
        }

        // Recalculate offers if not done
        if ( empty( $this->applied_offers ) ) {
            $this->apply_special_offers();
        }

        // Apply discounts to cart items
        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset( $this->applied_offers[ $cart_item_key ] ) ) {
                $offer = $this->applied_offers[ $cart_item_key ];
                $product = $cart_item['data'];

                // Store original price
                if ( ! isset( $cart_item['jdpd_original_price'] ) ) {
                    WC()->cart->cart_contents[ $cart_item_key ]['jdpd_original_price'] = $product->get_price();
                }

                // Calculate new price per item
                $original_total = $product->get_price() * $cart_item['quantity'];
                $new_total = $original_total - $offer['discount'];
                $new_price = $new_total / $cart_item['quantity'];

                $product->set_price( $new_price );

                // Store offer info
                WC()->cart->cart_contents[ $cart_item_key ]['jdpd_offer'] = $offer;
            }
        }
    }

    /**
     * Get applied offers
     *
     * @return array
     */
    public function get_applied_offers() {
        return $this->applied_offers;
    }

    /**
     * Get offer message for product
     *
     * @param WC_Product $product Product object.
     * @return string|null
     */
    public function get_offer_message( $product ) {
        $rules = jdpd_get_active_rules( 'special_offer' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( ! $rule_obj->is_active() || ! $rule_obj->applies_to_product( $product ) ) {
                continue;
            }

            $conditions = $rule_obj->get_conditions();
            $offer_type = 'bogo';

            foreach ( $conditions as $condition ) {
                if ( 'offer_type' === $condition['type'] ) {
                    $offer_type = $condition['value'];
                    break;
                }
            }

            // Generate message based on offer type
            switch ( $offer_type ) {
                case 'bogo':
                    $discount = $rule_obj->get( 'discount_value' );
                    if ( $discount >= 100 ) {
                        return __( 'Buy One Get One Free!', 'jezweb-dynamic-pricing' );
                    }
                    return sprintf(
                        /* translators: %s: discount percentage */
                        __( 'Buy One Get One %s%% Off!', 'jezweb-dynamic-pricing' ),
                        $discount
                    );

                case 'buy_x_get_y':
                    $buy_qty = 1;
                    $get_qty = 1;
                    $get_discount = 100;

                    foreach ( $conditions as $cond ) {
                        if ( 'buy_quantity' === $cond['type'] ) $buy_qty = $cond['value'];
                        if ( 'get_quantity' === $cond['type'] ) $get_qty = $cond['value'];
                        if ( 'get_discount' === $cond['type'] ) $get_discount = $cond['value'];
                    }

                    if ( $get_discount >= 100 ) {
                        return sprintf(
                            /* translators: 1: buy quantity, 2: get quantity */
                            __( 'Buy %1$d Get %2$d Free!', 'jezweb-dynamic-pricing' ),
                            $buy_qty,
                            $get_qty
                        );
                    }
                    return sprintf(
                        /* translators: 1: buy quantity, 2: get quantity, 3: discount percentage */
                        __( 'Buy %1$d Get %2$d at %3$s%% Off!', 'jezweb-dynamic-pricing' ),
                        $buy_qty,
                        $get_qty,
                        $get_discount
                    );

                case 'buy_x_for_y':
                    $buy_qty = 3;
                    $fixed_price = 0;

                    foreach ( $conditions as $cond ) {
                        if ( 'buy_quantity' === $cond['type'] ) $buy_qty = $cond['value'];
                        if ( 'fixed_price' === $cond['type'] ) $fixed_price = $cond['value'];
                    }

                    return sprintf(
                        /* translators: 1: quantity, 2: price */
                        __( '%1$d for %2$s!', 'jezweb-dynamic-pricing' ),
                        $buy_qty,
                        wc_price( $fixed_price )
                    );

                case 'x_for_price_of_y':
                    $get_qty = 3;
                    $pay_qty = 2;

                    foreach ( $conditions as $cond ) {
                        if ( 'get_quantity' === $cond['type'] ) $get_qty = $cond['value'];
                        if ( 'pay_quantity' === $cond['type'] ) $pay_qty = $cond['value'];
                    }

                    return sprintf(
                        /* translators: 1: get quantity, 2: pay quantity */
                        __( 'Get %1$d for the price of %2$d!', 'jezweb-dynamic-pricing' ),
                        $get_qty,
                        $pay_qty
                    );
            }
        }

        return null;
    }
}
