<?php
/**
 * Discount Notices
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Notices class
 */
class JDPD_Notices {

    /**
     * Constructor
     */
    public function __construct() {
        if ( 'yes' !== get_option( 'jdpd_enable_plugin', 'yes' ) ) {
            return;
        }

        // Product page notices
        if ( 'yes' === get_option( 'jdpd_show_product_notices', 'yes' ) ) {
            add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'display_product_notices' ) );
        }

        // Cart notices
        if ( 'yes' === get_option( 'jdpd_show_cart_notices', 'yes' ) ) {
            add_action( 'woocommerce_before_cart', array( $this, 'display_cart_notices' ) );
            add_action( 'woocommerce_before_checkout_form', array( $this, 'display_cart_notices' ), 5 );
        }

        // Modal for cart add
        add_action( 'woocommerce_add_to_cart', array( $this, 'maybe_show_modal' ), 10, 6 );
        add_action( 'wp_footer', array( $this, 'render_modal_template' ) );
    }

    /**
     * Display product page notices
     */
    public function display_product_notices() {
        global $product;

        if ( ! $product ) {
            return;
        }

        $notices = $this->get_product_notices( $product );

        if ( empty( $notices ) ) {
            return;
        }

        $style = get_option( 'jdpd_notice_style', 'default' );

        foreach ( $notices as $notice ) {
            $classes = array(
                'jdpd-notice',
                'jdpd-notice-' . $style,
                'jdpd-notice-' . $notice['type'],
            );
            ?>
            <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
                <?php if ( ! empty( $notice['icon'] ) ) : ?>
                    <span class="jdpd-notice-icon"><?php echo $notice['icon']; ?></span>
                <?php endif; ?>
                <span class="jdpd-notice-text"><?php echo wp_kses_post( $notice['message'] ); ?></span>
            </div>
            <?php
        }
    }

    /**
     * Get notices for a product
     *
     * @param WC_Product $product Product object.
     * @return array
     */
    private function get_product_notices( $product ) {
        $notices = array();

        // Get applicable rules
        $rules = jdpd_get_active_rules();

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( ! $rule_obj->is_active() || ! $rule_obj->applies_to_product( $product ) ) {
                continue;
            }

            // Generate notice based on rule type
            $notice = $this->generate_rule_notice( $rule_obj, $product );

            if ( $notice ) {
                $notices[] = $notice;
            }
        }

        return $notices;
    }

    /**
     * Generate notice for a rule
     *
     * @param JDPD_Rule  $rule    Rule object.
     * @param WC_Product $product Product object.
     * @return array|null
     */
    private function generate_rule_notice( $rule, $product ) {
        $rule_type = $rule->get( 'rule_type' );
        $discount_type = $rule->get( 'discount_type' );
        $discount_value = $rule->get( 'discount_value' );

        switch ( $rule_type ) {
            case 'price_rule':
                $ranges = $rule->get_quantity_ranges();
                if ( ! empty( $ranges ) ) {
                    $first_range = reset( $ranges );
                    return array(
                        'type'    => 'bulk',
                        'icon'    => '&#x1F4B0;',
                        'message' => sprintf(
                            /* translators: 1: quantity, 2: discount */
                            __( 'Buy %1$d+ and save up to %2$s!', 'jezweb-dynamic-pricing' ),
                            $first_range->min_quantity,
                            'percentage' === $first_range->discount_type
                                ? $first_range->discount_value . '%'
                                : wc_price( $first_range->discount_value )
                        ),
                    );
                }

                if ( $discount_value > 0 ) {
                    return array(
                        'type'    => 'discount',
                        'icon'    => '&#x1F389;',
                        'message' => sprintf(
                            /* translators: %s: discount value */
                            __( '%s off this product!', 'jezweb-dynamic-pricing' ),
                            'percentage' === $discount_type
                                ? $discount_value . '%'
                                : wc_price( $discount_value )
                        ),
                    );
                }
                break;

            case 'special_offer':
                $special_offers = new JDPD_Special_Offers();
                $message = $special_offers->get_offer_message( $product );
                if ( $message ) {
                    return array(
                        'type'    => 'special',
                        'icon'    => '&#x2B50;',
                        'message' => $message,
                    );
                }
                break;

            case 'gift':
                $gifts = $rule->get_gift_products();
                if ( ! empty( $gifts ) ) {
                    return array(
                        'type'    => 'gift',
                        'icon'    => '&#x1F381;',
                        'message' => __( 'Buy this product and receive a FREE gift!', 'jezweb-dynamic-pricing' ),
                    );
                }
                break;
        }

        return null;
    }

    /**
     * Display cart notices
     */
    public function display_cart_notices() {
        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }

        $notices = $this->get_cart_notices();

        foreach ( $notices as $notice ) {
            wc_print_notice( $notice['message'], $notice['type'] );
        }
    }

    /**
     * Get notices for cart
     *
     * @return array
     */
    private function get_cart_notices() {
        $notices = array();

        // Get next tier message
        $cart_rules = new JDPD_Cart_Rules();
        $next_tier = $cart_rules->get_next_tier_message();

        if ( $next_tier ) {
            $notices[] = array(
                'type'    => 'notice',
                'message' => $next_tier,
            );
        }

        // Show total savings
        if ( 'yes' === get_option( 'jdpd_show_cart_savings', 'yes' ) ) {
            $calculator = new JDPD_Discount_Calculator();
            $savings = $calculator->get_total_savings();

            if ( $savings > 0 ) {
                $notices[] = array(
                    'type'    => 'success',
                    'message' => sprintf(
                        /* translators: %s: savings amount */
                        __( 'You are saving %s on this order!', 'jezweb-dynamic-pricing' ),
                        wc_price( $savings )
                    ),
                );
            }
        }

        return $notices;
    }

    /**
     * Maybe show modal when product added to cart
     *
     * @param string $cart_item_key Cart item key.
     * @param int    $product_id    Product ID.
     * @param int    $quantity      Quantity.
     * @param int    $variation_id  Variation ID.
     * @param array  $variation     Variation data.
     * @param array  $cart_item_data Cart item data.
     */
    public function maybe_show_modal( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        // Check if product triggers any special offers
        $product_id = $variation_id ? $variation_id : $product_id;
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return;
        }

        // Get special offers for this product
        $special_offers = new JDPD_Special_Offers();
        $offer_message = $special_offers->get_offer_message( $product );

        if ( $offer_message ) {
            WC()->session->set( 'jdpd_show_offer_modal', array(
                'product_id' => $product_id,
                'message'    => $offer_message,
            ) );
        }
    }

    /**
     * Render modal template
     */
    public function render_modal_template() {
        if ( ! WC()->session ) {
            return;
        }

        $modal_data = WC()->session->get( 'jdpd_show_offer_modal' );

        if ( ! $modal_data ) {
            return;
        }

        // Clear the session
        WC()->session->set( 'jdpd_show_offer_modal', null );
        ?>
        <div class="jdpd-modal-overlay" id="jdpd-offer-modal">
            <div class="jdpd-modal">
                <button class="jdpd-modal-close">&times;</button>
                <div class="jdpd-modal-content">
                    <div class="jdpd-modal-icon">&#x1F389;</div>
                    <h3><?php esc_html_e( 'Special Offer!', 'jezweb-dynamic-pricing' ); ?></h3>
                    <p><?php echo esc_html( $modal_data['message'] ); ?></p>
                    <div class="jdpd-modal-actions">
                        <a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="button">
                            <?php esc_html_e( 'View Cart', 'jezweb-dynamic-pricing' ); ?>
                        </a>
                        <button class="button button-primary jdpd-modal-close">
                            <?php esc_html_e( 'Continue Shopping', 'jezweb-dynamic-pricing' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#jdpd-offer-modal').fadeIn();
            $('.jdpd-modal-close, .jdpd-modal-overlay').on('click', function(e) {
                if (e.target === this) {
                    $('#jdpd-offer-modal').fadeOut();
                }
            });
        });
        </script>
        <?php
    }
}
