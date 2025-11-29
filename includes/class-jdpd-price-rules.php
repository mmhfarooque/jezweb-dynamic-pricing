<?php
/**
 * Price Rules Handler
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Price Rules class
 */
class JDPD_Price_Rules {

    /**
     * Cached rules
     *
     * @var array
     */
    private $rules_cache = array();

    /**
     * Constructor
     */
    public function __construct() {
        if ( 'yes' !== get_option( 'jdpd_enable_plugin', 'yes' ) ) {
            jdpd_log( 'Price rules disabled - plugin not enabled', 'debug' );
            return;
        }

        jdpd_log( 'Price rules initialized', 'debug' );

        // Hook into WooCommerce price filters
        add_filter( 'woocommerce_product_get_price', array( $this, 'get_discounted_price' ), 99, 2 );
        add_filter( 'woocommerce_product_get_sale_price', array( $this, 'get_discounted_price' ), 99, 2 );
        add_filter( 'woocommerce_product_get_regular_price', array( $this, 'get_regular_price' ), 99, 2 );

        // Variation prices
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'get_discounted_price' ), 99, 2 );
        add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'get_discounted_price' ), 99, 2 );
        add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'get_regular_price' ), 99, 2 );

        // Variable product price range
        add_filter( 'woocommerce_variation_prices_price', array( $this, 'get_variation_price' ), 99, 3 );
        add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'get_variation_price' ), 99, 3 );

        // Price HTML
        add_filter( 'woocommerce_get_price_html', array( $this, 'get_price_html' ), 99, 2 );

        // Cart item price
        add_filter( 'woocommerce_cart_item_price', array( $this, 'cart_item_price' ), 99, 3 );

        // Clear cache on product save
        add_action( 'woocommerce_update_product', array( $this, 'clear_product_cache' ) );
    }

    /**
     * Get discounted price
     *
     * @param float      $price   Original price.
     * @param WC_Product $product Product object.
     * @return float
     */
    public function get_discounted_price( $price, $product ) {
        // Prevent recursion
        static $processing = array();
        $product_id = $product->get_id();

        if ( isset( $processing[ $product_id ] ) ) {
            return $price;
        }

        $processing[ $product_id ] = true;

        $discounted_price = $this->calculate_price( $product, $price );

        unset( $processing[ $product_id ] );

        return $discounted_price;
    }

    /**
     * Get regular price (always return original)
     *
     * @param float      $price   Price.
     * @param WC_Product $product Product object.
     * @return float
     */
    public function get_regular_price( $price, $product ) {
        return $price;
    }

    /**
     * Get variation price
     *
     * @param float               $price     Price.
     * @param WC_Product_Variation $variation Variation object.
     * @param WC_Product_Variable  $product   Parent product.
     * @return float
     */
    public function get_variation_price( $price, $variation, $product ) {
        return $this->calculate_price( $variation, $price );
    }

    /**
     * Calculate the final price after applying rules
     *
     * @param WC_Product $product Product object.
     * @param float      $price   Original price.
     * @param int        $quantity Quantity (optional).
     * @return float
     */
    public function calculate_price( $product, $price, $quantity = 1 ) {
        if ( ! $product || empty( $price ) ) {
            return $price;
        }

        // Check if we should apply to sale products
        if ( 'no' === get_option( 'jdpd_apply_to_sale_products', 'no' ) ) {
            // Get raw sale price without our filters
            remove_filter( 'woocommerce_product_get_sale_price', array( $this, 'get_discounted_price' ), 99 );
            remove_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'get_discounted_price' ), 99 );

            $sale_price = $product->get_sale_price( 'edit' );

            add_filter( 'woocommerce_product_get_sale_price', array( $this, 'get_discounted_price' ), 99, 2 );
            add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'get_discounted_price' ), 99, 2 );

            if ( ! empty( $sale_price ) ) {
                return $price;
            }
        }

        // Get applicable rules
        $rules = $this->get_applicable_rules( $product );

        if ( empty( $rules ) ) {
            return $price;
        }

        $final_price = $price;
        $applied_exclusive = false;

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            // Check conditions
            $conditions = new JDPD_Conditions();
            if ( ! $conditions->check_rule_conditions( $rule_obj ) ) {
                jdpd_log( sprintf( 'Rule %d conditions not met for product %d', $rule_obj->get( 'id' ), $product->get_id() ), 'debug' );
                continue;
            }

            // Check if already applied an exclusive rule
            if ( $applied_exclusive ) {
                jdpd_log( sprintf( 'Skipping rule %d - exclusive rule already applied', $rule_obj->get( 'id' ) ), 'debug' );
                break;
            }

            // Calculate discount based on rule type
            $discount = $this->get_rule_discount( $rule_obj, $product, $price, $quantity );

            if ( $discount > 0 ) {
                $final_price = max( 0, $final_price - $discount );
                jdpd_log( sprintf( 'Applied rule %d: discount %.2f on product %d (price: %.2f -> %.2f)', $rule_obj->get( 'id' ), $discount, $product->get_id(), $price, $final_price ), 'debug' );

                if ( $rule_obj->is_exclusive() ) {
                    $applied_exclusive = true;
                }
            }
        }

        return $final_price;
    }

    /**
     * Get discount amount from a rule
     *
     * @param JDPD_Rule  $rule     Rule object.
     * @param WC_Product $product  Product object.
     * @param float      $price    Original price.
     * @param int        $quantity Quantity.
     * @return float
     */
    private function get_rule_discount( $rule, $product, $price, $quantity = 1 ) {
        // Check if this is an event sale (special_offer with event_sale type)
        if ( 'event_sale' === $rule->get( 'special_offer_type' ) ) {
            $discount_type = $rule->get( 'event_discount_type' ) ?: 'percentage';
            $discount_value = floatval( $rule->get( 'event_discount_value' ) );

            if ( $discount_value > 0 ) {
                return jdpd_calculate_discount( $price, $discount_type, $discount_value );
            }
            return 0;
        }

        // Standard price rule discount
        $discount_type = $rule->get( 'discount_type' );
        $discount_value = $rule->get( 'discount_value' );

        // Check for quantity-based pricing
        $quantity_ranges = $rule->get_quantity_ranges();

        if ( ! empty( $quantity_ranges ) ) {
            // Get quantity from cart if available
            if ( WC()->cart ) {
                foreach ( WC()->cart->get_cart() as $cart_item ) {
                    if ( $cart_item['product_id'] == $product->get_id() ||
                         ( isset( $cart_item['variation_id'] ) && $cart_item['variation_id'] == $product->get_id() ) ) {
                        $quantity = $cart_item['quantity'];
                        break;
                    }
                }
            }

            // Find applicable range
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
     * Get applicable rules for a product
     *
     * @param WC_Product $product Product object.
     * @return array
     */
    public function get_applicable_rules( $product ) {
        $product_id = $product->get_id();

        // Check cache
        if ( isset( $this->rules_cache[ $product_id ] ) ) {
            return $this->rules_cache[ $product_id ];
        }

        // Get all active price rules
        $all_rules = jdpd_get_active_rules( 'price_rule' );
        $applicable = array();

        foreach ( $all_rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( $rule_obj->applies_to_product( $product ) ) {
                $applicable[] = $rule;
            }
        }

        // Also get event sale special offers and apply them as price discounts
        $special_offers = jdpd_get_active_rules( 'special_offer' );

        foreach ( $special_offers as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );
            $offer_type = $rule_obj->get( 'special_offer_type' );

            // Only process event_sale type special offers
            if ( 'event_sale' !== $offer_type ) {
                continue;
            }

            if ( $rule_obj->applies_to_product( $product ) ) {
                $applicable[] = $rule;
            }
        }

        // Cache results
        $this->rules_cache[ $product_id ] = $applicable;

        return $applicable;
    }

    /**
     * Get price HTML with original price crossed out
     *
     * @param string     $price_html Price HTML.
     * @param WC_Product $product    Product object.
     * @return string
     */
    public function get_price_html( $price_html, $product ) {
        if ( 'yes' !== get_option( 'jdpd_show_original_price', 'yes' ) ) {
            return $price_html;
        }

        // Get original price without our filters
        remove_filter( 'woocommerce_product_get_price', array( $this, 'get_discounted_price' ), 99 );
        remove_filter( 'woocommerce_product_variation_get_price', array( $this, 'get_discounted_price' ), 99 );

        $original_price = $product->get_price( 'edit' );

        add_filter( 'woocommerce_product_get_price', array( $this, 'get_discounted_price' ), 99, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'get_discounted_price' ), 99, 2 );

        // Get discounted price
        $discounted_price = $this->calculate_price( $product, $original_price );

        if ( $discounted_price < $original_price ) {
            $price_html = '<del>' . wc_price( $original_price ) . '</del> <ins>' . wc_price( $discounted_price ) . '</ins>';

            // Add "You Save" text
            if ( 'yes' === get_option( 'jdpd_show_you_save', 'yes' ) ) {
                $savings = $original_price - $discounted_price;
                $savings_percent = round( ( $savings / $original_price ) * 100 );
                $price_html .= '<span class="jdpd-you-save">' .
                    sprintf(
                        /* translators: 1: amount saved, 2: percentage saved */
                        __( 'You save %1$s (%2$s%%)', 'jezweb-dynamic-pricing' ),
                        wc_price( $savings ),
                        $savings_percent
                    ) . '</span>';
            }
        }

        return $price_html;
    }

    /**
     * Cart item price
     *
     * @param string $price     Price HTML.
     * @param array  $cart_item Cart item data.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function cart_item_price( $price, $cart_item, $cart_item_key ) {
        $product = $cart_item['data'];
        $quantity = $cart_item['quantity'];

        // Get original price
        remove_filter( 'woocommerce_product_get_price', array( $this, 'get_discounted_price' ), 99 );
        remove_filter( 'woocommerce_product_variation_get_price', array( $this, 'get_discounted_price' ), 99 );

        $original_price = $product->get_price( 'edit' );

        add_filter( 'woocommerce_product_get_price', array( $this, 'get_discounted_price' ), 99, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'get_discounted_price' ), 99, 2 );

        // Calculate discounted price with quantity consideration
        $discounted_price = $this->calculate_price( $product, $original_price, $quantity );

        if ( $discounted_price < $original_price ) {
            return '<del>' . wc_price( $original_price ) . '</del> <ins>' . wc_price( $discounted_price ) . '</ins>';
        }

        return $price;
    }

    /**
     * Clear product cache
     *
     * @param int $product_id Product ID.
     */
    public function clear_product_cache( $product_id ) {
        unset( $this->rules_cache[ $product_id ] );

        // Clear variation caches
        $product = wc_get_product( $product_id );
        if ( $product && $product->is_type( 'variable' ) ) {
            foreach ( $product->get_children() as $child_id ) {
                unset( $this->rules_cache[ $child_id ] );
            }
        }
    }

    /**
     * Get all applicable rules for display
     *
     * @param WC_Product $product Product object.
     * @return array
     */
    public function get_rules_for_display( $product ) {
        return $this->get_applicable_rules( $product );
    }
}
