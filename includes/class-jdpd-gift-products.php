<?php
/**
 * Gift Products Handler
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gift Products class
 */
class JDPD_Gift_Products {

    /**
     * Gift cart items
     *
     * @var array
     */
    private $gift_items = array();

    /**
     * Constructor
     */
    public function __construct() {
        if ( 'yes' !== get_option( 'jdpd_enable_plugin', 'yes' ) ) {
            return;
        }

        // Add gift products to cart
        add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'maybe_add_gift_products' ), 30 );
        add_action( 'woocommerce_add_to_cart', array( $this, 'maybe_add_gift_products' ), 30 );
        add_action( 'woocommerce_cart_item_removed', array( $this, 'maybe_remove_gift_products' ), 30 );
        add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'maybe_add_gift_products' ), 30 );

        // Apply gift price
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_gift_prices' ), 10 );

        // Display gift badge in cart
        add_filter( 'woocommerce_cart_item_name', array( $this, 'add_gift_badge' ), 10, 3 );

        // Prevent quantity change for gift items
        add_filter( 'woocommerce_cart_item_quantity', array( $this, 'disable_gift_quantity' ), 10, 3 );

        // Prevent removal of gift items directly
        add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'disable_gift_removal' ), 10, 2 );
    }

    /**
     * Check and add gift products to cart
     */
    public function maybe_add_gift_products() {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }

        // Get active gift rules
        $rules = jdpd_get_active_rules( 'gift' );

        if ( empty( $rules ) ) {
            return;
        }

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( ! $rule_obj->is_active() ) {
                continue;
            }

            // Check conditions
            $conditions = new JDPD_Conditions();
            if ( ! $conditions->check_rule_conditions( $rule_obj ) ) {
                // Remove gifts if conditions no longer met
                $this->remove_rule_gifts( $rule_obj->get_id() );
                continue;
            }

            // Check if qualifying products are in cart
            if ( ! $this->has_qualifying_products( $rule_obj ) ) {
                $this->remove_rule_gifts( $rule_obj->get_id() );
                continue;
            }

            // Add gift products
            $this->add_rule_gifts( $rule_obj );
        }
    }

    /**
     * Check if cart has qualifying products for rule
     *
     * @param JDPD_Rule $rule Rule object.
     * @return bool
     */
    private function has_qualifying_products( $rule ) {
        $apply_to = $rule->get( 'apply_to' );

        if ( 'all_products' === $apply_to ) {
            return true;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            // Skip gift items
            if ( isset( $cart_item['jdpd_gift'] ) ) {
                continue;
            }

            $product = $cart_item['data'];
            if ( $rule->applies_to_product( $product ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add gifts for a rule
     *
     * @param JDPD_Rule $rule Rule object.
     */
    private function add_rule_gifts( $rule ) {
        $gifts = $rule->get_gift_products();

        if ( empty( $gifts ) ) {
            return;
        }

        foreach ( $gifts as $gift ) {
            $product_id = $gift->product_id;
            $quantity = $gift->quantity;

            // Check if gift already in cart
            $gift_key = $this->get_gift_cart_key( $rule->get_id(), $product_id );
            if ( $gift_key ) {
                continue;
            }

            // Add gift to cart
            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_in_stock() ) {
                continue;
            }

            $cart_item_data = array(
                'jdpd_gift'           => true,
                'jdpd_gift_rule_id'   => $rule->get_id(),
                'jdpd_gift_discount'  => array(
                    'type'  => $gift->discount_type,
                    'value' => $gift->discount_value,
                ),
                'jdpd_original_price' => $product->get_price(),
            );

            WC()->cart->add_to_cart( $product_id, $quantity, 0, array(), $cart_item_data );
        }
    }

    /**
     * Remove gifts for a rule
     *
     * @param int $rule_id Rule ID.
     */
    private function remove_rule_gifts( $rule_id ) {
        if ( ! WC()->cart ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset( $cart_item['jdpd_gift_rule_id'] ) && $cart_item['jdpd_gift_rule_id'] == $rule_id ) {
                WC()->cart->remove_cart_item( $cart_item_key );
            }
        }
    }

    /**
     * Get cart item key for a gift
     *
     * @param int $rule_id    Rule ID.
     * @param int $product_id Product ID.
     * @return string|false
     */
    private function get_gift_cart_key( $rule_id, $product_id ) {
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset( $cart_item['jdpd_gift_rule_id'] ) &&
                 $cart_item['jdpd_gift_rule_id'] == $rule_id &&
                 $cart_item['product_id'] == $product_id ) {
                return $cart_item_key;
            }
        }
        return false;
    }

    /**
     * Remove gift products when qualifying products are removed
     *
     * @param string $cart_item_key Removed cart item key.
     */
    public function maybe_remove_gift_products( $cart_item_key ) {
        // Re-check all gift rules
        $this->maybe_add_gift_products();
    }

    /**
     * Apply gift prices
     *
     * @param WC_Cart $cart Cart object.
     */
    public function apply_gift_prices( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( ! isset( $cart_item['jdpd_gift'] ) || ! $cart_item['jdpd_gift'] ) {
                continue;
            }

            $product = $cart_item['data'];
            $original_price = $cart_item['jdpd_original_price'] ?? $product->get_price();
            $discount = $cart_item['jdpd_gift_discount'] ?? array( 'type' => 'percentage', 'value' => 100 );

            // Calculate gift price
            if ( 'percentage' === $discount['type'] ) {
                $new_price = $original_price * ( 1 - ( $discount['value'] / 100 ) );
            } else {
                $new_price = max( 0, $original_price - $discount['value'] );
            }

            $product->set_price( $new_price );
        }
    }

    /**
     * Add gift badge to cart item name
     *
     * @param string $name          Product name.
     * @param array  $cart_item     Cart item data.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function add_gift_badge( $name, $cart_item, $cart_item_key ) {
        if ( isset( $cart_item['jdpd_gift'] ) && $cart_item['jdpd_gift'] ) {
            $discount = $cart_item['jdpd_gift_discount'] ?? array( 'type' => 'percentage', 'value' => 100 );

            if ( 100 == $discount['value'] && 'percentage' === $discount['type'] ) {
                $badge = '<span class="jdpd-gift-badge">' . esc_html__( 'FREE GIFT', 'jezweb-dynamic-pricing' ) . '</span>';
            } else {
                $badge = '<span class="jdpd-gift-badge">' . esc_html__( 'GIFT', 'jezweb-dynamic-pricing' ) . '</span>';
            }

            $name = $badge . ' ' . $name;
        }

        return $name;
    }

    /**
     * Disable quantity input for gift items
     *
     * @param string $quantity_html Quantity HTML.
     * @param string $cart_item_key Cart item key.
     * @param array  $cart_item     Cart item data.
     * @return string
     */
    public function disable_gift_quantity( $quantity_html, $cart_item_key, $cart_item ) {
        if ( isset( $cart_item['jdpd_gift'] ) && $cart_item['jdpd_gift'] ) {
            return $cart_item['quantity'];
        }
        return $quantity_html;
    }

    /**
     * Disable removal link for gift items
     *
     * @param string $link          Remove link HTML.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function disable_gift_removal( $link, $cart_item_key ) {
        $cart_item = WC()->cart->get_cart_item( $cart_item_key );

        if ( $cart_item && isset( $cart_item['jdpd_gift'] ) && $cart_item['jdpd_gift'] ) {
            return '';
        }

        return $link;
    }

    /**
     * Get available gifts for product
     *
     * @param WC_Product $product Product object.
     * @return array
     */
    public function get_product_gifts( $product ) {
        $gifts = array();
        $rules = jdpd_get_active_rules( 'gift' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( ! $rule_obj->is_active() || ! $rule_obj->applies_to_product( $product ) ) {
                continue;
            }

            $rule_gifts = $rule_obj->get_gift_products();
            foreach ( $rule_gifts as $gift ) {
                $gift_product = wc_get_product( $gift->product_id );
                if ( $gift_product ) {
                    $gifts[] = array(
                        'product'   => $gift_product,
                        'quantity'  => $gift->quantity,
                        'discount'  => array(
                            'type'  => $gift->discount_type,
                            'value' => $gift->discount_value,
                        ),
                        'rule_name' => $rule_obj->get( 'name' ),
                    );
                }
            }
        }

        return $gifts;
    }
}
