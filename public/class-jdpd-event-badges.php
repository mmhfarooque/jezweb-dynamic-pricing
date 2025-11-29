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
        $badge = $this->get_product_event_badge( $product );

        if ( ! empty( $badge ) ) {
            $price_html .= ' ' . $this->render_badge( $badge );
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
        $badge = $this->get_product_event_badge( $product );

        if ( ! empty( $badge ) ) {
            $price_html .= ' ' . $this->render_badge( $badge );
        }

        return $price_html;
    }

    /**
     * Add event badge to cart item subtotal
     *
     * @param string $subtotal   Subtotal HTML.
     * @param array  $cart_item  Cart item data.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function add_event_badge_to_cart_subtotal( $subtotal, $cart_item, $cart_item_key ) {
        // Only show badge on price, not subtotal to avoid duplication
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
        $badge = $this->get_product_event_badge( $product );

        if ( ! empty( $badge ) ) {
            $quantity .= ' ' . $this->render_badge( $badge, 'small' );
        }

        return $quantity;
    }

    /**
     * Get event badge for a product
     *
     * @param WC_Product $product Product object.
     * @return string|null Badge text or null if no badge.
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
        $badge_text = $this->get_active_event_badge_for_product( $product );

        // Cache the result
        $this->product_badges[ $product_id ] = $badge_text;

        return $badge_text;
    }

    /**
     * Get active event badge for a product
     *
     * @param WC_Product $product Product object.
     * @return string|null
     */
    private function get_active_event_badge_for_product( $product ) {
        $product_id = $product->get_id();
        $product_categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
        $product_tags = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'ids' ) );

        // Get all active special offer rules
        $rules = jdpd_get_active_rules( 'special_offer' );

        foreach ( $rules as $rule ) {
            // Check if rule has settings
            if ( empty( $rule->settings ) ) {
                continue;
            }

            $settings = maybe_unserialize( $rule->settings );

            // Check if this is an event sale
            if ( empty( $settings['special_offer_type'] ) || 'event_sale' !== $settings['special_offer_type'] ) {
                continue;
            }

            // Check if product matches this rule
            if ( ! $this->product_matches_rule( $product_id, $product_categories, $product_tags, $rule ) ) {
                continue;
            }

            // Get event name
            $event_type = ! empty( $settings['event_type'] ) ? $settings['event_type'] : '';

            if ( 'custom' === $event_type && ! empty( $settings['custom_event_name'] ) ) {
                return sanitize_text_field( $settings['custom_event_name'] );
            } elseif ( ! empty( $event_type ) ) {
                $event = jdpd_get_special_event( $event_type );
                if ( $event ) {
                    return $event['name'];
                }
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
     * @param string $text Badge text.
     * @param string $size Badge size (default, small).
     * @return string
     */
    private function render_badge( $text, $size = 'default' ) {
        $class = 'jdpd-event-badge';
        if ( 'small' === $size ) {
            $class .= ' jdpd-event-badge-small';
        }

        return sprintf(
            '<span class="%s">%s</span>',
            esc_attr( $class ),
            esc_html( $text )
        );
    }
}
