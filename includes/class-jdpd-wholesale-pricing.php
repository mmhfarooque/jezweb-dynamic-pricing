<?php
/**
 * Wholesale/B2B Pricing Module
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Jezweb_Dynamic_Pricing/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Wholesale/B2B Pricing class.
 *
 * Provides tiered pricing for business customers, quote requests,
 * minimum order quantities, and tax exemption handling.
 *
 * @since 1.4.0
 */
class JDPD_Wholesale_Pricing {

    /**
     * Single instance.
     *
     * @var JDPD_Wholesale_Pricing
     */
    private static $instance = null;

    /**
     * Wholesale role name.
     *
     * @var string
     */
    private $wholesale_role = 'jdpd_wholesale';

    /**
     * Get single instance.
     *
     * @return JDPD_Wholesale_Pricing
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Register wholesale role
        add_action( 'init', array( $this, 'register_wholesale_role' ) );

        // Price filtering
        add_filter( 'woocommerce_product_get_price', array( $this, 'get_wholesale_price' ), 100, 2 );
        add_filter( 'woocommerce_product_get_regular_price', array( $this, 'get_wholesale_price' ), 100, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'get_wholesale_price' ), 100, 2 );
        add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'get_wholesale_price' ), 100, 2 );

        // Price display
        add_filter( 'woocommerce_get_price_html', array( $this, 'wholesale_price_html' ), 100, 2 );

        // Minimum order
        add_action( 'woocommerce_check_cart_items', array( $this, 'check_minimum_order' ) );
        add_filter( 'woocommerce_quantity_input_min', array( $this, 'set_minimum_quantity' ), 10, 2 );

        // Quote request system
        add_shortcode( 'jdpd_quote_form', array( $this, 'quote_form_shortcode' ) );
        add_action( 'wp_ajax_jdpd_submit_quote', array( $this, 'ajax_submit_quote' ) );
        add_action( 'wp_ajax_nopriv_jdpd_submit_quote', array( $this, 'ajax_submit_quote' ) );

        // Tax exemption
        add_filter( 'woocommerce_product_get_tax_class', array( $this, 'maybe_exempt_tax' ), 100, 2 );

        // Wholesale registration
        add_action( 'woocommerce_register_form', array( $this, 'wholesale_registration_fields' ) );
        add_action( 'woocommerce_created_customer', array( $this, 'save_wholesale_registration' ) );

        // Admin product fields
        add_action( 'woocommerce_product_options_pricing', array( $this, 'add_wholesale_price_field' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_wholesale_price_field' ) );

        // Variation wholesale price
        add_action( 'woocommerce_variation_options_pricing', array( $this, 'add_variation_wholesale_field' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_wholesale_field' ), 10, 2 );

        // Admin
        add_action( 'wp_ajax_jdpd_approve_wholesale', array( $this, 'ajax_approve_wholesale' ) );
        add_action( 'wp_ajax_jdpd_get_quote_requests', array( $this, 'ajax_get_quote_requests' ) );
        add_action( 'wp_ajax_jdpd_respond_quote', array( $this, 'ajax_respond_quote' ) );

        // Hide prices for non-logged-in wholesale products
        add_filter( 'woocommerce_is_purchasable', array( $this, 'wholesale_only_purchasable' ), 10, 2 );
    }

    /**
     * Register wholesale customer role.
     */
    public function register_wholesale_role() {
        if ( ! get_role( $this->wholesale_role ) ) {
            add_role(
                $this->wholesale_role,
                __( 'Wholesale Customer', 'jezweb-dynamic-pricing' ),
                array(
                    'read' => true,
                )
            );
        }

        // Also create tiered wholesale roles
        $tiers = $this->get_wholesale_tiers();

        foreach ( $tiers as $tier_id => $tier ) {
            $role_name = 'jdpd_wholesale_' . $tier_id;
            if ( ! get_role( $role_name ) ) {
                add_role(
                    $role_name,
                    $tier['name'],
                    array( 'read' => true )
                );
            }
        }
    }

    /**
     * Get wholesale tiers.
     *
     * @return array Tiers.
     */
    public function get_wholesale_tiers() {
        return get_option( 'jdpd_wholesale_tiers', array(
            'bronze' => array(
                'name'           => __( 'Wholesale Bronze', 'jezweb-dynamic-pricing' ),
                'discount'       => 10,
                'min_order'      => 100,
                'min_quantity'   => 5,
            ),
            'silver' => array(
                'name'           => __( 'Wholesale Silver', 'jezweb-dynamic-pricing' ),
                'discount'       => 15,
                'min_order'      => 250,
                'min_quantity'   => 10,
            ),
            'gold' => array(
                'name'           => __( 'Wholesale Gold', 'jezweb-dynamic-pricing' ),
                'discount'       => 20,
                'min_order'      => 500,
                'min_quantity'   => 25,
            ),
            'platinum' => array(
                'name'           => __( 'Wholesale Platinum', 'jezweb-dynamic-pricing' ),
                'discount'       => 25,
                'min_order'      => 1000,
                'min_quantity'   => 50,
            ),
        ) );
    }

    /**
     * Check if current user is wholesale.
     *
     * @return bool Whether wholesale.
     */
    public function is_wholesale_customer() {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $user = wp_get_current_user();

        // Check main wholesale role
        if ( in_array( $this->wholesale_role, $user->roles, true ) ) {
            return true;
        }

        // Check tiered roles
        foreach ( array_keys( $this->get_wholesale_tiers() ) as $tier_id ) {
            if ( in_array( 'jdpd_wholesale_' . $tier_id, $user->roles, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user's wholesale tier.
     *
     * @param int $user_id User ID (optional).
     * @return array|null Tier data or null.
     */
    public function get_user_tier( $user_id = null ) {
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        if ( ! $user_id ) {
            return null;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return null;
        }

        $tiers = $this->get_wholesale_tiers();

        foreach ( $tiers as $tier_id => $tier ) {
            if ( in_array( 'jdpd_wholesale_' . $tier_id, $user->roles, true ) ) {
                $tier['id'] = $tier_id;
                return $tier;
            }
        }

        // Check basic wholesale role
        if ( in_array( $this->wholesale_role, $user->roles, true ) ) {
            return array(
                'id'       => 'basic',
                'name'     => __( 'Wholesale', 'jezweb-dynamic-pricing' ),
                'discount' => 10,
            );
        }

        return null;
    }

    /**
     * Get wholesale price for a product.
     *
     * @param float      $price Current price.
     * @param WC_Product $product Product object.
     * @return float Wholesale price.
     */
    public function get_wholesale_price( $price, $product ) {
        if ( ! $this->is_wholesale_customer() ) {
            return $price;
        }

        // Check for product-specific wholesale price
        $wholesale_price = get_post_meta( $product->get_id(), '_jdpd_wholesale_price', true );

        if ( '' !== $wholesale_price && false !== $wholesale_price ) {
            return floatval( $wholesale_price );
        }

        // Apply tier discount
        $tier = $this->get_user_tier();

        if ( $tier && ! empty( $tier['discount'] ) ) {
            $discount = floatval( $tier['discount'] );
            $price = $price * ( 1 - ( $discount / 100 ) );
        }

        return round( $price, wc_get_price_decimals() );
    }

    /**
     * Modify price HTML for wholesale customers.
     *
     * @param string     $price_html Price HTML.
     * @param WC_Product $product Product object.
     * @return string Modified HTML.
     */
    public function wholesale_price_html( $price_html, $product ) {
        if ( ! $this->is_wholesale_customer() ) {
            return $price_html;
        }

        $tier = $this->get_user_tier();
        $regular_price = $product->get_regular_price();
        $wholesale_price = $this->get_wholesale_price( $regular_price, $product );

        if ( $wholesale_price < $regular_price ) {
            $price_html = '<del>' . wc_price( $regular_price ) . '</del> ';
            $price_html .= '<ins>' . wc_price( $wholesale_price ) . '</ins>';

            if ( $tier ) {
                $price_html .= ' <span class="jdpd-wholesale-badge">' . esc_html( $tier['name'] ) . '</span>';
            }
        }

        return $price_html;
    }

    /**
     * Check minimum order requirement.
     */
    public function check_minimum_order() {
        if ( ! $this->is_wholesale_customer() ) {
            return;
        }

        $tier = $this->get_user_tier();

        if ( ! $tier || empty( $tier['min_order'] ) ) {
            return;
        }

        $min_order = floatval( $tier['min_order'] );
        $cart_total = WC()->cart->get_subtotal();

        if ( $cart_total < $min_order ) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: minimum order amount, 2: current cart total */
                    __( 'Wholesale customers require a minimum order of %1$s. Your current cart total is %2$s.', 'jezweb-dynamic-pricing' ),
                    wc_price( $min_order ),
                    wc_price( $cart_total )
                ),
                'error'
            );
        }
    }

    /**
     * Set minimum quantity for wholesale products.
     *
     * @param int        $min_qty Minimum quantity.
     * @param WC_Product $product Product object.
     * @return int Modified minimum.
     */
    public function set_minimum_quantity( $min_qty, $product ) {
        if ( ! $this->is_wholesale_customer() ) {
            return $min_qty;
        }

        // Check product-specific minimum
        $product_min = get_post_meta( $product->get_id(), '_jdpd_wholesale_min_qty', true );

        if ( '' !== $product_min && false !== $product_min ) {
            return absint( $product_min );
        }

        // Use tier minimum
        $tier = $this->get_user_tier();

        if ( $tier && ! empty( $tier['min_quantity'] ) ) {
            return absint( $tier['min_quantity'] );
        }

        return $min_qty;
    }

    /**
     * Handle tax exemption for wholesale.
     *
     * @param string     $tax_class Tax class.
     * @param WC_Product $product Product object.
     * @return string Tax class.
     */
    public function maybe_exempt_tax( $tax_class, $product ) {
        if ( ! $this->is_wholesale_customer() ) {
            return $tax_class;
        }

        $user_id = get_current_user_id();
        $tax_exempt = get_user_meta( $user_id, '_jdpd_tax_exempt', true );

        if ( 'yes' === $tax_exempt ) {
            return 'zero-rate';
        }

        return $tax_class;
    }

    /**
     * Add wholesale price field to product.
     */
    public function add_wholesale_price_field() {
        woocommerce_wp_text_input( array(
            'id'          => '_jdpd_wholesale_price',
            'label'       => __( 'Wholesale price', 'jezweb-dynamic-pricing' ) . ' (' . get_woocommerce_currency_symbol() . ')',
            'desc_tip'    => true,
            'description' => __( 'Fixed wholesale price for this product. Leave empty to use tier discounts.', 'jezweb-dynamic-pricing' ),
            'type'        => 'text',
            'data_type'   => 'price',
            'class'       => 'wc_input_price short',
        ) );

        woocommerce_wp_text_input( array(
            'id'          => '_jdpd_wholesale_min_qty',
            'label'       => __( 'Wholesale min quantity', 'jezweb-dynamic-pricing' ),
            'desc_tip'    => true,
            'description' => __( 'Minimum purchase quantity for wholesale customers.', 'jezweb-dynamic-pricing' ),
            'type'        => 'number',
            'custom_attributes' => array( 'min' => 1 ),
        ) );
    }

    /**
     * Save wholesale price field.
     *
     * @param int $post_id Product ID.
     */
    public function save_wholesale_price_field( $post_id ) {
        if ( isset( $_POST['_jdpd_wholesale_price'] ) ) {
            $price = '' !== $_POST['_jdpd_wholesale_price']
                ? wc_format_decimal( sanitize_text_field( $_POST['_jdpd_wholesale_price'] ) )
                : '';
            update_post_meta( $post_id, '_jdpd_wholesale_price', $price );
        }

        if ( isset( $_POST['_jdpd_wholesale_min_qty'] ) ) {
            $qty = '' !== $_POST['_jdpd_wholesale_min_qty']
                ? absint( $_POST['_jdpd_wholesale_min_qty'] )
                : '';
            update_post_meta( $post_id, '_jdpd_wholesale_min_qty', $qty );
        }
    }

    /**
     * Add variation wholesale field.
     *
     * @param int    $loop Loop index.
     * @param array  $variation_data Variation data.
     * @param object $variation Variation post.
     */
    public function add_variation_wholesale_field( $loop, $variation_data, $variation ) {
        $wholesale_price = get_post_meta( $variation->ID, '_jdpd_wholesale_price', true );
        ?>
        <p class="form-row form-row-first">
            <label><?php esc_html_e( 'Wholesale price', 'jezweb-dynamic-pricing' ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)</label>
            <input type="text"
                   name="variable_wholesale_price[<?php echo esc_attr( $loop ); ?>]"
                   value="<?php echo esc_attr( wc_format_localized_price( $wholesale_price ) ); ?>"
                   class="wc_input_price" />
        </p>
        <?php
    }

    /**
     * Save variation wholesale field.
     *
     * @param int $variation_id Variation ID.
     * @param int $i Loop index.
     */
    public function save_variation_wholesale_field( $variation_id, $i ) {
        if ( isset( $_POST['variable_wholesale_price'][ $i ] ) ) {
            $price = '' !== $_POST['variable_wholesale_price'][ $i ]
                ? wc_format_decimal( sanitize_text_field( $_POST['variable_wholesale_price'][ $i ] ) )
                : '';
            update_post_meta( $variation_id, '_jdpd_wholesale_price', $price );
        }
    }

    /**
     * Wholesale registration form fields.
     */
    public function wholesale_registration_fields() {
        $settings = get_option( 'jdpd_wholesale_settings', array() );

        if ( empty( $settings['allow_registration'] ) ) {
            return;
        }

        ?>
        <h3><?php esc_html_e( 'Wholesale Account', 'jezweb-dynamic-pricing' ); ?></h3>

        <p class="form-row">
            <label>
                <input type="checkbox" name="jdpd_apply_wholesale" value="yes" />
                <?php esc_html_e( 'Apply for a wholesale account', 'jezweb-dynamic-pricing' ); ?>
            </label>
        </p>

        <div class="jdpd-wholesale-fields" style="display:none;">
            <p class="form-row form-row-wide">
                <label><?php esc_html_e( 'Business Name', 'jezweb-dynamic-pricing' ); ?> <span class="required">*</span></label>
                <input type="text" name="jdpd_business_name" class="input-text" />
            </p>

            <p class="form-row form-row-wide">
                <label><?php esc_html_e( 'ABN/Tax ID', 'jezweb-dynamic-pricing' ); ?></label>
                <input type="text" name="jdpd_tax_id" class="input-text" />
            </p>

            <p class="form-row form-row-wide">
                <label><?php esc_html_e( 'Industry/Business Type', 'jezweb-dynamic-pricing' ); ?></label>
                <input type="text" name="jdpd_industry" class="input-text" />
            </p>
        </div>

        <script>
        jQuery(function($) {
            $('input[name="jdpd_apply_wholesale"]').on('change', function() {
                $('.jdpd-wholesale-fields').toggle(this.checked);
            });
        });
        </script>
        <?php
    }

    /**
     * Save wholesale registration data.
     *
     * @param int $customer_id Customer ID.
     */
    public function save_wholesale_registration( $customer_id ) {
        if ( empty( $_POST['jdpd_apply_wholesale'] ) || 'yes' !== $_POST['jdpd_apply_wholesale'] ) {
            return;
        }

        $business_name = isset( $_POST['jdpd_business_name'] ) ? sanitize_text_field( $_POST['jdpd_business_name'] ) : '';
        $tax_id = isset( $_POST['jdpd_tax_id'] ) ? sanitize_text_field( $_POST['jdpd_tax_id'] ) : '';
        $industry = isset( $_POST['jdpd_industry'] ) ? sanitize_text_field( $_POST['jdpd_industry'] ) : '';

        update_user_meta( $customer_id, '_jdpd_wholesale_application', array(
            'business_name' => $business_name,
            'tax_id'        => $tax_id,
            'industry'      => $industry,
            'status'        => 'pending',
            'applied_at'    => current_time( 'mysql' ),
        ) );

        // Notify admin
        $this->notify_wholesale_application( $customer_id );
    }

    /**
     * Notify admin of wholesale application.
     *
     * @param int $customer_id Customer ID.
     */
    private function notify_wholesale_application( $customer_id ) {
        $user = get_userdata( $customer_id );
        $admin_email = get_option( 'admin_email' );

        $subject = sprintf(
            /* translators: %s: Site name */
            __( '[%s] New Wholesale Application', 'jezweb-dynamic-pricing' ),
            get_bloginfo( 'name' )
        );

        $message = sprintf(
            /* translators: 1: User email, 2: Admin URL */
            __( "A new wholesale application has been submitted.\n\nCustomer: %1\$s\n\nReview the application: %2\$s", 'jezweb-dynamic-pricing' ),
            $user->user_email,
            admin_url( 'admin.php?page=jdpd-wholesale' )
        );

        wp_mail( $admin_email, $subject, $message );
    }

    /**
     * Quote form shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML.
     */
    public function quote_form_shortcode( $atts ) {
        ob_start();
        ?>
        <div class="jdpd-quote-form">
            <h3><?php esc_html_e( 'Request a Quote', 'jezweb-dynamic-pricing' ); ?></h3>

            <form id="jdpd-quote-request-form">
                <?php wp_nonce_field( 'jdpd_quote_nonce', 'jdpd_quote_nonce' ); ?>

                <p class="form-row">
                    <label><?php esc_html_e( 'Your Name', 'jezweb-dynamic-pricing' ); ?> <span class="required">*</span></label>
                    <input type="text" name="name" required />
                </p>

                <p class="form-row">
                    <label><?php esc_html_e( 'Email', 'jezweb-dynamic-pricing' ); ?> <span class="required">*</span></label>
                    <input type="email" name="email" required />
                </p>

                <p class="form-row">
                    <label><?php esc_html_e( 'Company', 'jezweb-dynamic-pricing' ); ?></label>
                    <input type="text" name="company" />
                </p>

                <p class="form-row">
                    <label><?php esc_html_e( 'Phone', 'jezweb-dynamic-pricing' ); ?></label>
                    <input type="tel" name="phone" />
                </p>

                <p class="form-row">
                    <label><?php esc_html_e( 'Products & Quantities', 'jezweb-dynamic-pricing' ); ?> <span class="required">*</span></label>
                    <textarea name="products" rows="4" required placeholder="<?php esc_attr_e( 'Please list the products and quantities you need...', 'jezweb-dynamic-pricing' ); ?>"></textarea>
                </p>

                <p class="form-row">
                    <label><?php esc_html_e( 'Additional Notes', 'jezweb-dynamic-pricing' ); ?></label>
                    <textarea name="notes" rows="3"></textarea>
                </p>

                <p class="form-row">
                    <button type="submit" class="button"><?php esc_html_e( 'Submit Quote Request', 'jezweb-dynamic-pricing' ); ?></button>
                </p>

                <div class="jdpd-quote-message"></div>
            </form>
        </div>

        <script>
        jQuery(function($) {
            $('#jdpd-quote-request-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $message = $form.find('.jdpd-quote-message');

                $.ajax({
                    url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                    type: 'POST',
                    data: $form.serialize() + '&action=jdpd_submit_quote',
                    success: function(response) {
                        if (response.success) {
                            $message.html('<p class="success">' + response.data.message + '</p>');
                            $form[0].reset();
                        } else {
                            $message.html('<p class="error">' + response.data.message + '</p>');
                        }
                    }
                });
            });
        });
        </script>

        <style>
            .jdpd-quote-form { max-width: 600px; }
            .jdpd-quote-form .form-row { margin-bottom: 15px; }
            .jdpd-quote-form label { display: block; margin-bottom: 5px; font-weight: 500; }
            .jdpd-quote-form input, .jdpd-quote-form textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
            .jdpd-quote-message .success { color: #28a745; }
            .jdpd-quote-message .error { color: #dc3545; }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Submit quote request.
     */
    public function ajax_submit_quote() {
        check_ajax_referer( 'jdpd_quote_nonce', 'jdpd_quote_nonce' );

        $name = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
        $company = isset( $_POST['company'] ) ? sanitize_text_field( $_POST['company'] ) : '';
        $phone = isset( $_POST['phone'] ) ? sanitize_text_field( $_POST['phone'] ) : '';
        $products = isset( $_POST['products'] ) ? sanitize_textarea_field( $_POST['products'] ) : '';
        $notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : '';

        if ( empty( $name ) || empty( $email ) || empty( $products ) ) {
            wp_send_json_error( array( 'message' => __( 'Please fill in all required fields.', 'jezweb-dynamic-pricing' ) ) );
        }

        // Save quote request
        $quotes = get_option( 'jdpd_quote_requests', array() );
        $quote_id = 'quote_' . uniqid();

        $quotes[ $quote_id ] = array(
            'id'         => $quote_id,
            'name'       => $name,
            'email'      => $email,
            'company'    => $company,
            'phone'      => $phone,
            'products'   => $products,
            'notes'      => $notes,
            'status'     => 'pending',
            'created_at' => current_time( 'mysql' ),
        );

        update_option( 'jdpd_quote_requests', $quotes );

        // Notify admin
        $admin_email = get_option( 'admin_email' );
        $subject = sprintf(
            /* translators: %s: Site name */
            __( '[%s] New Quote Request', 'jezweb-dynamic-pricing' ),
            get_bloginfo( 'name' )
        );

        $message = sprintf(
            "New quote request received:\n\nName: %s\nEmail: %s\nCompany: %s\nPhone: %s\n\nProducts:\n%s\n\nNotes:\n%s",
            $name, $email, $company, $phone, $products, $notes
        );

        wp_mail( $admin_email, $subject, $message );

        wp_send_json_success( array(
            'message' => __( 'Thank you! Your quote request has been submitted. We will get back to you shortly.', 'jezweb-dynamic-pricing' ),
        ) );
    }

    /**
     * Check if wholesale-only product is purchasable.
     *
     * @param bool       $purchasable Whether purchasable.
     * @param WC_Product $product Product object.
     * @return bool Whether purchasable.
     */
    public function wholesale_only_purchasable( $purchasable, $product ) {
        $wholesale_only = get_post_meta( $product->get_id(), '_jdpd_wholesale_only', true );

        if ( 'yes' === $wholesale_only && ! $this->is_wholesale_customer() ) {
            return false;
        }

        return $purchasable;
    }

    /**
     * AJAX: Approve wholesale application.
     */
    public function ajax_approve_wholesale() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $tier = isset( $_POST['tier'] ) ? sanitize_key( $_POST['tier'] ) : 'basic';

        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid user.', 'jezweb-dynamic-pricing' ) ) );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            wp_send_json_error( array( 'message' => __( 'User not found.', 'jezweb-dynamic-pricing' ) ) );
        }

        // Add wholesale role
        if ( 'basic' === $tier ) {
            $user->add_role( $this->wholesale_role );
        } else {
            $user->add_role( 'jdpd_wholesale_' . $tier );
        }

        // Update application status
        $application = get_user_meta( $user_id, '_jdpd_wholesale_application', true );
        if ( $application ) {
            $application['status'] = 'approved';
            $application['approved_at'] = current_time( 'mysql' );
            $application['approved_tier'] = $tier;
            update_user_meta( $user_id, '_jdpd_wholesale_application', $application );
        }

        // Notify customer
        $subject = __( 'Your Wholesale Application Has Been Approved!', 'jezweb-dynamic-pricing' );
        $message = sprintf(
            /* translators: 1: Site name, 2: Login URL */
            __( "Great news! Your wholesale application at %1\$s has been approved.\n\nYou can now log in and enjoy wholesale pricing: %2\$s", 'jezweb-dynamic-pricing' ),
            get_bloginfo( 'name' ),
            wc_get_page_permalink( 'myaccount' )
        );

        wp_mail( $user->user_email, $subject, $message );

        wp_send_json_success( array( 'message' => __( 'Wholesale application approved.', 'jezweb-dynamic-pricing' ) ) );
    }

    /**
     * AJAX: Get quote requests.
     */
    public function ajax_get_quote_requests() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $quotes = get_option( 'jdpd_quote_requests', array() );

        wp_send_json_success( array( 'quotes' => array_values( $quotes ) ) );
    }

    /**
     * AJAX: Respond to quote.
     */
    public function ajax_respond_quote() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $quote_id = isset( $_POST['quote_id'] ) ? sanitize_text_field( $_POST['quote_id'] ) : '';
        $response = isset( $_POST['response'] ) ? sanitize_textarea_field( $_POST['response'] ) : '';
        $total = isset( $_POST['total'] ) ? floatval( $_POST['total'] ) : 0;

        $quotes = get_option( 'jdpd_quote_requests', array() );

        if ( ! isset( $quotes[ $quote_id ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Quote not found.', 'jezweb-dynamic-pricing' ) ) );
        }

        $quote = $quotes[ $quote_id ];

        // Send response email
        $subject = sprintf(
            /* translators: %s: Site name */
            __( 'Your Quote from %s', 'jezweb-dynamic-pricing' ),
            get_bloginfo( 'name' )
        );

        $message = sprintf(
            "Dear %s,\n\nThank you for your quote request. Here is our response:\n\n%s\n\nQuoted Total: %s\n\nPlease let us know if you have any questions.",
            $quote['name'],
            $response,
            wc_price( $total )
        );

        wp_mail( $quote['email'], $subject, $message );

        // Update quote status
        $quotes[ $quote_id ]['status'] = 'responded';
        $quotes[ $quote_id ]['response'] = $response;
        $quotes[ $quote_id ]['quoted_total'] = $total;
        $quotes[ $quote_id ]['responded_at'] = current_time( 'mysql' );

        update_option( 'jdpd_quote_requests', $quotes );

        wp_send_json_success( array( 'message' => __( 'Quote response sent.', 'jezweb-dynamic-pricing' ) ) );
    }
}
