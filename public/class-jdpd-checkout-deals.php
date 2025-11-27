<?php
/**
 * Checkout Deals Handler
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checkout Deals class
 */
class JDPD_Checkout_Deals {

    /**
     * Constructor
     */
    public function __construct() {
        if ( 'yes' !== get_option( 'jdpd_enable_plugin', 'yes' ) ) {
            return;
        }

        if ( 'yes' !== get_option( 'jdpd_enable_checkout_deals', 'yes' ) ) {
            return;
        }

        // Display checkout deals
        add_action( 'woocommerce_review_order_before_submit', array( $this, 'display_checkout_deals' ) );

        // AJAX to add checkout deal
        add_action( 'wp_ajax_jdpd_add_checkout_deal', array( $this, 'ajax_add_checkout_deal' ) );
        add_action( 'wp_ajax_nopriv_jdpd_add_checkout_deal', array( $this, 'ajax_add_checkout_deal' ) );
    }

    /**
     * Display checkout deals
     */
    public function display_checkout_deals() {
        $deals = $this->get_available_deals();

        if ( empty( $deals ) ) {
            return;
        }

        $show_countdown = 'yes' === get_option( 'jdpd_checkout_countdown', 'yes' );
        $countdown_time = absint( get_option( 'jdpd_checkout_countdown_time', 300 ) );
        ?>

        <div class="jdpd-checkout-deals">
            <h3><?php esc_html_e( 'Last Chance Offers!', 'jezweb-dynamic-pricing' ); ?></h3>

            <?php if ( $show_countdown ) : ?>
                <div class="jdpd-countdown" data-seconds="<?php echo esc_attr( $countdown_time ); ?>">
                    <span class="jdpd-countdown-label"><?php esc_html_e( 'Offers expire in:', 'jezweb-dynamic-pricing' ); ?></span>
                    <span class="jdpd-countdown-timer">
                        <span class="jdpd-minutes">00</span>:<span class="jdpd-seconds">00</span>
                    </span>
                </div>
            <?php endif; ?>

            <div class="jdpd-deals-list">
                <?php foreach ( $deals as $deal ) : ?>
                    <div class="jdpd-deal-item" data-deal-id="<?php echo esc_attr( $deal['id'] ); ?>">
                        <?php if ( ! empty( $deal['image'] ) ) : ?>
                            <div class="jdpd-deal-image">
                                <img src="<?php echo esc_url( $deal['image'] ); ?>" alt="<?php echo esc_attr( $deal['name'] ); ?>">
                            </div>
                        <?php endif; ?>

                        <div class="jdpd-deal-info">
                            <h4><?php echo esc_html( $deal['name'] ); ?></h4>
                            <p class="jdpd-deal-description"><?php echo esc_html( $deal['description'] ); ?></p>
                            <div class="jdpd-deal-price">
                                <?php if ( $deal['original_price'] != $deal['price'] ) : ?>
                                    <del><?php echo wc_price( $deal['original_price'] ); ?></del>
                                <?php endif; ?>
                                <ins><?php echo wc_price( $deal['price'] ); ?></ins>

                                <?php if ( $deal['savings'] > 0 ) : ?>
                                    <span class="jdpd-deal-savings">
                                        <?php
                                        printf(
                                            /* translators: %s: savings amount */
                                            esc_html__( 'Save %s', 'jezweb-dynamic-pricing' ),
                                            wc_price( $deal['savings'] )
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <button type="button" class="button jdpd-add-deal"
                                data-product-id="<?php echo esc_attr( $deal['product_id'] ); ?>"
                                data-deal-id="<?php echo esc_attr( $deal['id'] ); ?>">
                            <?php esc_html_e( 'Add to Order', 'jezweb-dynamic-pricing' ); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Countdown timer
            <?php if ( $show_countdown ) : ?>
            var seconds = <?php echo $countdown_time; ?>;
            var $timer = $('.jdpd-countdown-timer');
            var $deals = $('.jdpd-checkout-deals');

            var countdown = setInterval(function() {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(countdown);
                    $deals.slideUp();
                    return;
                }

                var mins = Math.floor(seconds / 60);
                var secs = seconds % 60;
                $timer.find('.jdpd-minutes').text(String(mins).padStart(2, '0'));
                $timer.find('.jdpd-seconds').text(String(secs).padStart(2, '0'));
            }, 1000);
            <?php endif; ?>

            // Add deal to cart
            $('.jdpd-add-deal').on('click', function() {
                var $btn = $(this);
                var productId = $btn.data('product-id');
                var dealId = $btn.data('deal-id');

                $btn.prop('disabled', true).text('<?php esc_html_e( 'Adding...', 'jezweb-dynamic-pricing' ); ?>');

                $.ajax({
                    url: jdpd_frontend.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'jdpd_add_checkout_deal',
                        nonce: jdpd_frontend.nonce,
                        product_id: productId,
                        deal_id: dealId
                    },
                    success: function(response) {
                        if (response.success) {
                            $btn.closest('.jdpd-deal-item').fadeOut(function() {
                                $(this).remove();
                                if ($('.jdpd-deal-item').length === 0) {
                                    $deals.slideUp();
                                }
                            });
                            $(document.body).trigger('update_checkout');
                        } else {
                            alert(response.data.message || '<?php esc_html_e( 'Error adding deal.', 'jezweb-dynamic-pricing' ); ?>');
                            $btn.prop('disabled', false).text('<?php esc_html_e( 'Add to Order', 'jezweb-dynamic-pricing' ); ?>');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('<?php esc_html_e( 'Add to Order', 'jezweb-dynamic-pricing' ); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get available checkout deals
     *
     * @return array
     */
    private function get_available_deals() {
        $deals = array();

        // Get checkout deal rules
        $rules = jdpd_get_active_rules( 'checkout_deal' );

        // If no specific checkout deal rules, create deals from products not in cart
        if ( empty( $rules ) ) {
            $rules = $this->get_suggested_deals();
        }

        foreach ( $rules as $rule ) {
            if ( is_object( $rule ) ) {
                $rule_obj = new JDPD_Rule( $rule );

                if ( ! $rule_obj->is_active() ) {
                    continue;
                }

                // Check conditions
                $conditions = new JDPD_Conditions();
                if ( ! $conditions->check_rule_conditions( $rule_obj ) ) {
                    continue;
                }

                // Get deal products
                $deal_products = jdpd_get_rule_items( $rule_obj->get_id(), 'product' );

                foreach ( $deal_products as $item ) {
                    $product = wc_get_product( $item->item_id );
                    if ( $product && ! $this->is_product_in_cart( $product->get_id() ) ) {
                        $original_price = $product->get_regular_price();
                        $discount = jdpd_calculate_discount(
                            $original_price,
                            $rule_obj->get( 'discount_type' ),
                            $rule_obj->get( 'discount_value' )
                        );

                        $deals[] = array(
                            'id'             => $rule_obj->get_id() . '_' . $product->get_id(),
                            'product_id'     => $product->get_id(),
                            'name'           => $product->get_name(),
                            'description'    => $rule_obj->get( 'name' ),
                            'image'          => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
                            'original_price' => $original_price,
                            'price'          => max( 0, $original_price - $discount ),
                            'savings'        => $discount,
                            'rule_id'        => $rule_obj->get_id(),
                        );
                    }
                }
            } elseif ( is_array( $rule ) ) {
                // Suggested deal from array
                $deals[] = $rule;
            }
        }

        return array_slice( $deals, 0, 3 ); // Limit to 3 deals
    }

    /**
     * Get suggested deals based on cart contents
     *
     * @return array
     */
    private function get_suggested_deals() {
        if ( ! WC()->cart ) {
            return array();
        }

        $deals = array();
        $cart_product_ids = array();
        $cart_category_ids = array();

        // Get cart product and category IDs
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $cart_product_ids[] = $cart_item['product_id'];
            $product = $cart_item['data'];
            $cart_category_ids = array_merge( $cart_category_ids, $product->get_category_ids() );
        }

        // Get related products that have active price rules
        $rules = jdpd_get_active_rules( 'price_rule' );

        foreach ( $rules as $rule ) {
            $rule_obj = new JDPD_Rule( $rule );

            if ( $rule_obj->get( 'discount_value' ) < 10 ) {
                continue; // Only show deals with significant discounts
            }

            $items = jdpd_get_rule_items( $rule_obj->get_id(), 'product' );

            foreach ( $items as $item ) {
                if ( in_array( $item->item_id, $cart_product_ids ) ) {
                    continue; // Skip products already in cart
                }

                $product = wc_get_product( $item->item_id );
                if ( ! $product || ! $product->is_purchasable() ) {
                    continue;
                }

                $original_price = $product->get_regular_price();
                $discount = jdpd_calculate_discount(
                    $original_price,
                    $rule_obj->get( 'discount_type' ),
                    $rule_obj->get( 'discount_value' )
                );

                $deals[] = array(
                    'id'             => 'suggested_' . $product->get_id(),
                    'product_id'     => $product->get_id(),
                    'name'           => $product->get_name(),
                    'description'    => sprintf(
                        /* translators: %s: discount */
                        __( 'Special checkout offer - %s off!', 'jezweb-dynamic-pricing' ),
                        'percentage' === $rule_obj->get( 'discount_type' )
                            ? $rule_obj->get( 'discount_value' ) . '%'
                            : wc_price( $rule_obj->get( 'discount_value' ) )
                    ),
                    'image'          => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
                    'original_price' => $original_price,
                    'price'          => max( 0, $original_price - $discount ),
                    'savings'        => $discount,
                    'rule_id'        => $rule_obj->get_id(),
                );

                if ( count( $deals ) >= 5 ) {
                    break 2;
                }
            }
        }

        return $deals;
    }

    /**
     * Check if product is in cart
     *
     * @param int $product_id Product ID.
     * @return bool
     */
    private function is_product_in_cart( $product_id ) {
        if ( ! WC()->cart ) {
            return false;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( $cart_item['product_id'] == $product_id ) {
                return true;
            }
        }

        return false;
    }

    /**
     * AJAX add checkout deal
     */
    public function ajax_add_checkout_deal() {
        check_ajax_referer( 'jdpd_frontend_nonce', 'nonce' );

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $deal_id = isset( $_POST['deal_id'] ) ? sanitize_text_field( $_POST['deal_id'] ) : '';

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid product.', 'jezweb-dynamic-pricing' ) ) );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'jezweb-dynamic-pricing' ) ) );
        }

        // Add to cart with deal flag
        $cart_item_data = array(
            'jdpd_checkout_deal' => true,
            'jdpd_deal_id'       => $deal_id,
        );

        $added = WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_item_data );

        if ( $added ) {
            wp_send_json_success( array( 'message' => __( 'Deal added to order!', 'jezweb-dynamic-pricing' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Could not add deal to order.', 'jezweb-dynamic-pricing' ) ) );
        }
    }
}
