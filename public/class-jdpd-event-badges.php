<?php
/**
 * Event Badges Display
 *
 * Displays special event badges next to product prices
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JDPD Event Badges Class
 */
class JDPD_Event_Badges {

    /**
     * Single instance
     *
     * @var JDPD_Event_Badges
     */
    private static $instance = null;

    /**
     * Cache for product event badges
     *
     * @var array
     */
    private $product_badges = array();

    /**
     * Get single instance
     *
     * @return JDPD_Event_Badges
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
        // Only run if badges are enabled
        if ( 'yes' !== get_option( 'jdpd_enable_event_badges', 'yes' ) ) {
            return;
        }

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Product price hooks
        add_filter( 'woocommerce_get_price_html', array( $this, 'add_event_badge_to_price' ), 100, 2 );

        // Cart price hooks
        add_filter( 'woocommerce_cart_item_price', array( $this, 'add_event_badge_to_cart_price' ), 100, 3 );
        add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'add_event_badge_to_cart_subtotal' ), 100, 3 );

        // Mini cart
        add_filter( 'woocommerce_widget_cart_item_quantity', array( $this, 'add_event_badge_to_mini_cart' ), 100, 3 );

        // Checkout order review - product name and price
        add_filter( 'woocommerce_checkout_cart_item_quantity', array( $this, 'add_event_badge_to_checkout_item' ), 100, 3 );

        // Order emails and invoices - show price breakdown
        add_filter( 'woocommerce_order_item_name', array( $this, 'add_badge_to_order_item_name' ), 100, 3 );
        add_action( 'woocommerce_order_item_meta_end', array( $this, 'add_price_breakdown_to_order_item' ), 10, 4 );

        // Enqueue styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );

        // Output inline CSS for customized colors
        add_action( 'wp_head', array( $this, 'output_custom_css' ) );
    }

    /**
     * Enqueue frontend styles
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            'jdpd-event-badges',
            JDPD_PLUGIN_URL . 'public/assets/css/event-badges.css',
            array(),
            JDPD_VERSION
        );
    }

    /**
     * Output custom CSS for badge colors
     */
    public function output_custom_css() {
        $bg_color = get_option( 'jdpd_event_badge_bg_color', '#d83a34' );
        $text_color = get_option( 'jdpd_event_badge_text_color', '#ffffff' );
        ?>
        <style type="text/css">
            .jdpd-event-badge {
                background-color: <?php echo esc_attr( $bg_color ); ?>;
                color: <?php echo esc_attr( $text_color ); ?>;
            }
        </style>
        <?php
    }

    /**
     * Add event badge to product price
     *
     * @param string     $price_html Price HTML.
     * @param WC_Product $product    Product object.
     * @return string
     */
    public function add_event_badge_to_price( $price_html, $product ) {
        $badge_data = $this->get_product_event_badge( $product );

        if ( ! empty( $badge_data ) ) {
            $price_html .= ' ' . $this->render_badge( $badge_data['text'], 'default', $badge_data );
        }

        return $price_html;
    }

    /**
     * Add event badge to cart item price
     *
     * @param string $price_html Price HTML.
     * @param array  $cart_item  Cart item data.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function add_event_badge_to_cart_price( $price_html, $cart_item, $cart_item_key ) {
        $product = $cart_item['data'];
        $badge_data = $this->get_product_event_badge( $product );

        if ( ! empty( $badge_data ) ) {
            $price_html .= ' ' . $this->render_badge( $badge_data['text'], 'default', $badge_data );
        }

        return $price_html;
    }

    /**
     * Add price breakdown to cart/checkout item subtotal
     *
     * @param string $subtotal   Subtotal HTML.
     * @param array  $cart_item  Cart item data.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function add_event_badge_to_cart_subtotal( $subtotal, $cart_item, $cart_item_key ) {
        // Only on checkout page - show full price breakdown
        if ( ! is_checkout() ) {
            return $subtotal;
        }

        $product = $cart_item['data'];
        $badge_data = $this->get_product_event_badge( $product );

        if ( empty( $badge_data ) ) {
            return $subtotal;
        }

        // Get original and discounted prices
        $original_price = $product->get_regular_price();
        $discounted_price = $product->get_price();
        $quantity = $cart_item['quantity'];

        if ( $original_price && $discounted_price && floatval( $original_price ) > floatval( $discounted_price ) ) {
            $original_subtotal = floatval( $original_price ) * $quantity;
            $discounted_subtotal = floatval( $discounted_price ) * $quantity;

            $price_html = '<span class="jdpd-checkout-subtotal-breakdown">';
            $price_html .= '<del>' . wc_price( $original_subtotal ) . '</del><br>';
            $price_html .= '<ins>' . wc_price( $discounted_subtotal ) . '</ins>';
            $price_html .= '</span>';

            return $price_html;
        }

        return $subtotal;
    }

    /**
     * Add event badge to mini cart
     *
     * @param string $quantity   Quantity HTML.
     * @param array  $cart_item  Cart item data.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function add_event_badge_to_mini_cart( $quantity, $cart_item, $cart_item_key ) {
        $product = $cart_item['data'];
        $badge_data = $this->get_product_event_badge( $product );

        if ( ! empty( $badge_data ) ) {
            $quantity .= ' ' . $this->render_badge( $badge_data['text'], 'small', $badge_data );
        }

        return $quantity;
    }

    /**
     * Add event badge to checkout order review
     *
     * @param string $quantity   Quantity HTML.
     * @param array  $cart_item  Cart item data.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function add_event_badge_to_checkout_item( $quantity, $cart_item, $cart_item_key ) {
        $product = $cart_item['data'];
        $badge_data = $this->get_product_event_badge( $product );

        if ( ! empty( $badge_data ) ) {
            $quantity .= ' ' . $this->render_badge( $badge_data['text'], 'small', $badge_data );
        }

        return $quantity;
    }

    /**
     * Add badge to order item name (for emails and order details)
     *
     * @param string $name     Product name.
     * @param object $item     Order item.
     * @param bool   $visible  Is item visible.
     * @return string
     */
    public function add_badge_to_order_item_name( $name, $item, $visible ) {
        // Get product from order item
        if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
            return $name;
        }

        $product = $item->get_product();
        if ( ! $product ) {
            return $name;
        }

        $badge_data = $this->get_product_event_badge( $product );

        if ( ! empty( $badge_data ) ) {
            // For emails, use inline styles
            $badge_style = sprintf(
                'display: inline-block; background-color: %s; color: %s; padding: 2px 6px; font-size: 10px; font-weight: bold; text-transform: uppercase; border-radius: 3px; margin-left: 5px;',
                esc_attr( $badge_data['bg_color'] ),
                esc_attr( $badge_data['text_color'] )
            );
            $name .= ' <span style="' . $badge_style . '">' . esc_html( $badge_data['text'] ) . '</span>';
        }

        return $name;
    }

    /**
     * Add price breakdown to order item meta (for emails and invoices)
     *
     * @param int    $item_id   Item ID.
     * @param object $item      Order item.
     * @param object $order     Order object.
     * @param bool   $plain_text Is plain text email.
     */
    public function add_price_breakdown_to_order_item( $item_id, $item, $order, $plain_text = false ) {
        if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
            return;
        }

        $product = $item->get_product();
        if ( ! $product ) {
            return;
        }

        $badge_data = $this->get_product_event_badge( $product );

        if ( empty( $badge_data ) ) {
            return;
        }

        // Get original and discounted prices
        $original_price = $product->get_regular_price();
        $discounted_price = $product->get_price();
        $quantity = $item->get_quantity();

        if ( $original_price && $discounted_price && floatval( $original_price ) > floatval( $discounted_price ) ) {
            $original_subtotal = floatval( $original_price ) * $quantity;
            $discounted_subtotal = floatval( $discounted_price ) * $quantity;
            $savings = $original_subtotal - $discounted_subtotal;

            if ( $plain_text ) {
                echo "\n" . sprintf(
                    __( 'Original: %s | Discounted: %s | You Save: %s', 'jezweb-dynamic-pricing' ),
                    strip_tags( wc_price( $original_subtotal ) ),
                    strip_tags( wc_price( $discounted_subtotal ) ),
                    strip_tags( wc_price( $savings ) )
                );
            } else {
                echo '<div class="jdpd-order-item-discount" style="font-size: 12px; color: #666; margin-top: 5px;">';
                echo '<span style="text-decoration: line-through; color: #999;">' . wc_price( $original_subtotal ) . '</span>';
                echo ' <span style="color: #77a464; font-weight: bold;">' . wc_price( $discounted_subtotal ) . '</span>';
                echo ' <span style="color: #d83a34; font-size: 11px;">(' . sprintf( __( 'Save %s', 'jezweb-dynamic-pricing' ), wc_price( $savings ) ) . ')</span>';
                echo '</div>';
            }
        }
    }

    /**
     * Get event badge for a product
     *
     * @param WC_Product $product Product object.
     * @return array|null Badge data array or null if no badge.
     */
    private function get_product_event_badge( $product ) {
        if ( ! $product ) {
            return null;
        }

        $product_id = $product->get_id();

        // Check cache first
        if ( isset( $this->product_badges[ $product_id ] ) ) {
            return $this->product_badges[ $product_id ];
        }

        // Get active event sale rules for this product
        $badge_data = $this->get_active_event_badge_for_product( $product );

        // Cache the result
        $this->product_badges[ $product_id ] = $badge_data;

        return $badge_data;
    }

    /**
     * Get active event badge for a product
     *
     * @param WC_Product $product Product object.
     * @return array|null Badge data array with text, bg_color, text_color.
     */
    private function get_active_event_badge_for_product( $product ) {
        $product_id = $product->get_id();

        // Get all active special offer rules
        $rules = jdpd_get_active_rules( 'special_offer' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            // Check if this is an event sale (using the new column structure)
            if ( 'event_sale' !== $rule_obj->get( 'special_offer_type' ) ) {
                continue;
            }

            // Check if product matches this rule
            if ( ! $rule_obj->applies_to_product( $product ) ) {
                continue;
            }

            // Get event name from the rule
            $event_type = $rule_obj->get( 'event_type' );
            $badge_text = null;

            if ( 'custom' === $event_type ) {
                $custom_name = $rule_obj->get( 'custom_event_name' );
                if ( ! empty( $custom_name ) ) {
                    $badge_text = sanitize_text_field( $custom_name );
                }
            } elseif ( ! empty( $event_type ) ) {
                $event = jdpd_get_special_event( $event_type );
                if ( $event ) {
                    $badge_text = $event['name'];
                }
            }

            if ( $badge_text ) {
                // Get custom colors from rule (with fallback to global settings)
                $bg_color = $rule_obj->get( 'badge_bg_color' );
                $text_color = $rule_obj->get( 'badge_text_color' );

                return array(
                    'text'       => $badge_text,
                    'bg_color'   => ! empty( $bg_color ) ? $bg_color : get_option( 'jdpd_event_badge_bg_color', '#d83a34' ),
                    'text_color' => ! empty( $text_color ) ? $text_color : get_option( 'jdpd_event_badge_text_color', '#ffffff' ),
                    'rule_id'    => $rule_obj->get_id(),
                );
            }
        }

        return null;
    }

    /**
     * Check if product matches a rule
     *
     * @param int   $product_id         Product ID.
     * @param array $product_categories Product category IDs.
     * @param array $product_tags       Product tag IDs.
     * @param object $rule              Rule object.
     * @return bool
     */
    private function product_matches_rule( $product_id, $product_categories, $product_tags, $rule ) {
        $apply_to = $rule->apply_to;

        // All products
        if ( 'all_products' === $apply_to ) {
            // Check exclusions
            if ( jdpd_is_product_excluded( $product_id, $rule->id ) ) {
                return false;
            }
            return true;
        }

        // Specific products
        if ( 'specific_products' === $apply_to ) {
            $rule_items = jdpd_get_rule_items( $rule->id, 'product' );
            $product_ids = wp_list_pluck( $rule_items, 'item_id' );
            if ( in_array( $product_id, $product_ids ) ) {
                return true;
            }
        }

        // Categories
        if ( 'categories' === $apply_to ) {
            $rule_items = jdpd_get_rule_items( $rule->id, 'category' );
            $category_ids = wp_list_pluck( $rule_items, 'item_id' );
            if ( array_intersect( $product_categories, $category_ids ) ) {
                // Check exclusions
                if ( jdpd_is_product_excluded( $product_id, $rule->id ) ) {
                    return false;
                }
                return true;
            }
        }

        // Tags
        if ( 'tags' === $apply_to ) {
            $rule_items = jdpd_get_rule_items( $rule->id, 'tag' );
            $tag_ids = wp_list_pluck( $rule_items, 'item_id' );
            if ( array_intersect( $product_tags, $tag_ids ) ) {
                // Check exclusions
                if ( jdpd_is_product_excluded( $product_id, $rule->id ) ) {
                    return false;
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Render badge HTML
     *
     * @param string $text       Badge text.
     * @param string $size       Badge size (default, small).
     * @param array  $badge_data Optional badge data with custom colors.
     * @return string
     */
    private function render_badge( $text, $size = 'default', $badge_data = array() ) {
        $class = 'jdpd-event-badge';
        if ( 'small' === $size ) {
            $class .= ' jdpd-event-badge-small';
        }

        // Build inline style for custom colors
        $style = '';
        if ( ! empty( $badge_data['bg_color'] ) ) {
            $style .= 'background-color: ' . esc_attr( $badge_data['bg_color'] ) . ';';
        }
        if ( ! empty( $badge_data['text_color'] ) ) {
            $style .= 'color: ' . esc_attr( $badge_data['text_color'] ) . ';';
        }

        $style_attr = ! empty( $style ) ? ' style="' . esc_attr( $style ) . '"' : '';

        return sprintf(
            '<span class="%s"%s>%s</span>',
            esc_attr( $class ),
            $style_attr,
            esc_html( $text )
        );
    }
}
