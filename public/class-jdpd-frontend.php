<?php
/**
 * Frontend Handler
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Frontend class
 */
class JDPD_Frontend {

    /**
     * Constructor
     */
    public function __construct() {
        if ( 'yes' !== get_option( 'jdpd_enable_plugin', 'yes' ) ) {
            return;
        }

        // Enqueue scripts and styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Add sale badge
        add_filter( 'woocommerce_sale_flash', array( $this, 'custom_sale_badge' ), 10, 3 );

        // Add discount info to product page
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_discount_info' ), 25 );

        // Register shortcodes
        $this->register_shortcodes();
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        // CSS
        wp_enqueue_style(
            'jdpd-frontend',
            JDPD_PLUGIN_URL . 'public/assets/css/frontend.css',
            array(),
            JDPD_VERSION
        );

        // JS
        wp_enqueue_script(
            'jdpd-frontend',
            JDPD_PLUGIN_URL . 'public/assets/js/frontend.js',
            array( 'jquery' ),
            JDPD_VERSION,
            true
        );

        wp_localize_script(
            'jdpd-frontend',
            'jdpd_frontend',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'jdpd_frontend_nonce' ),
                'i18n'     => array(
                    'you_save'       => __( 'You save', 'jezweb-dynamic-pricing' ),
                    'add_more'       => __( 'Add more to save!', 'jezweb-dynamic-pricing' ),
                    'free_shipping'  => __( 'Free shipping!', 'jezweb-dynamic-pricing' ),
                    'countdown_ends' => __( 'Offer ends in:', 'jezweb-dynamic-pricing' ),
                ),
            )
        );
    }

    /**
     * Custom sale badge
     *
     * @param string     $html    Sale badge HTML.
     * @param WP_Post    $post    Post object.
     * @param WC_Product $product Product object.
     * @return string
     */
    public function custom_sale_badge( $html, $post, $product ) {
        // Check if product has dynamic discount
        $calculator = new JDPD_Discount_Calculator();
        $discount = $calculator->get_best_discount_for_product( $product );

        if ( empty( $discount['amount'] ) || $discount['amount'] <= 0 ) {
            return $html;
        }

        // Get badge settings - sanitize text from database
        $badge_style = get_option( 'jdpd_badge_style', 'default' );
        $badge_text = sanitize_text_field( get_option( 'jdpd_badge_text', __( 'Sale', 'jezweb-dynamic-pricing' ) ) );

        // Replace placeholders with properly escaped values
        if ( 'percentage' === $discount['type'] ) {
            $badge_text = str_replace( '{discount}', esc_html( $discount['value'] ) . '%', $badge_text );
        } else {
            // wc_price() returns safe HTML, use wp_kses_post for output
            $badge_text = str_replace( '{discount}', wc_price( $discount['value'] ), $badge_text );
        }

        $classes = array( 'jdpd-sale-badge', 'jdpd-badge-' . sanitize_html_class( $badge_style ) );

        return sprintf(
            '<span class="%s">%s</span>',
            esc_attr( implode( ' ', $classes ) ),
            wp_kses_post( $badge_text )
        );
    }

    /**
     * Display discount info on product page
     */
    public function display_discount_info() {
        global $product;

        if ( ! $product ) {
            return;
        }

        // Get special offer message
        $special_offers = new JDPD_Special_Offers();
        $offer_message = $special_offers->get_offer_message( $product );

        if ( $offer_message ) {
            echo '<div class="jdpd-offer-message">' . esc_html( $offer_message ) . '</div>';
        }

        // Get gift products info
        $gift_products = new JDPD_Gift_Products();
        $gifts = $gift_products->get_product_gifts( $product );

        if ( ! empty( $gifts ) ) {
            echo '<div class="jdpd-gift-notice">';
            echo '<strong>' . esc_html__( 'Buy this product and get:', 'jezweb-dynamic-pricing' ) . '</strong>';
            echo '<ul>';
            foreach ( $gifts as $gift ) {
                // Security: Escape product name to prevent XSS
                $gift_text = esc_html( $gift['product']->get_name() );
                if ( 100 == $gift['discount']['value'] && 'percentage' === $gift['discount']['type'] ) {
                    $gift_text .= ' <span class="jdpd-free-tag">' . esc_html__( 'FREE', 'jezweb-dynamic-pricing' ) . '</span>';
                } else {
                    $gift_text .= ' (' . esc_html( $gift['discount']['value'] ) . '% ' . esc_html__( 'off', 'jezweb-dynamic-pricing' ) . ')';
                }
                echo '<li>' . wp_kses_post( $gift_text ) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
    }

    /**
     * Register shortcodes
     */
    private function register_shortcodes() {
        add_shortcode( 'jdpd_quantity_table', array( $this, 'shortcode_quantity_table' ) );
        add_shortcode( 'jdpd_savings', array( $this, 'shortcode_savings' ) );
        add_shortcode( 'jdpd_discount_message', array( $this, 'shortcode_discount_message' ) );
    }

    /**
     * Quantity table shortcode
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function shortcode_quantity_table( $atts ) {
        $atts = shortcode_atts(
            array(
                'product_id' => 0,
                'layout'     => 'horizontal',
            ),
            $atts
        );

        $product_id = absint( $atts['product_id'] );

        if ( ! $product_id ) {
            global $product;
            $product_id = $product ? $product->get_id() : 0;
        }

        if ( ! $product_id ) {
            return '';
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        $quantity_table = new JDPD_Quantity_Table();
        return $quantity_table->render_table( $product, $atts['layout'] );
    }

    /**
     * Savings shortcode
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function shortcode_savings( $atts ) {
        $atts = shortcode_atts(
            array(
                'product_id' => 0,
            ),
            $atts
        );

        $product_id = absint( $atts['product_id'] );

        if ( ! $product_id ) {
            global $product;
            $product_id = $product ? $product->get_id() : 0;
        }

        if ( ! $product_id ) {
            return '';
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        $calculator = new JDPD_Discount_Calculator();
        $discount = $calculator->get_best_discount_for_product( $product );

        if ( empty( $discount['amount'] ) || $discount['amount'] <= 0 ) {
            return '';
        }

        return sprintf(
            '<span class="jdpd-savings">%s %s</span>',
            esc_html__( 'You save:', 'jezweb-dynamic-pricing' ),
            wc_price( $discount['amount'] )
        );
    }

    /**
     * Discount message shortcode
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function shortcode_discount_message( $atts ) {
        $atts = shortcode_atts(
            array(
                'product_id' => 0,
            ),
            $atts
        );

        $product_id = absint( $atts['product_id'] );

        if ( ! $product_id ) {
            global $product;
            $product_id = $product ? $product->get_id() : 0;
        }

        if ( ! $product_id ) {
            return '';
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        $special_offers = new JDPD_Special_Offers();
        $message = $special_offers->get_offer_message( $product );

        if ( ! $message ) {
            return '';
        }

        return '<div class="jdpd-discount-message">' . esc_html( $message ) . '</div>';
    }
}
