<?php
/**
 * Birthday & Anniversary Discounts
 *
 * Provide special discounts to customers on their birthday or order anniversary.
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Includes
 * @since      1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JDPD_Birthday_Discounts class.
 *
 * Features:
 * - Birthday field collection on registration/checkout
 * - Automatic birthday discount application
 * - Order anniversary discounts (first order, yearly)
 * - Customer milestone rewards (10th order, 50th, 100th)
 * - Automated email notifications
 * - Birthday discount coupons
 *
 * @since 1.4.0
 */
class JDPD_Birthday_Discounts {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Birthday_Discounts
     */
    private static $instance = null;

    /**
     * Get single instance.
     *
     * @return JDPD_Birthday_Discounts
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
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
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'), 25);
        add_action('admin_init', array($this, 'register_settings'));

        // Birthday field on registration
        add_action('woocommerce_register_form', array($this, 'add_birthday_field_registration'));
        add_action('woocommerce_created_customer', array($this, 'save_birthday_field_registration'));
        add_filter('woocommerce_registration_errors', array($this, 'validate_birthday_field'), 10, 3);

        // Birthday field on checkout
        add_action('woocommerce_after_checkout_billing_form', array($this, 'add_birthday_field_checkout'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_birthday_field_checkout'));

        // Birthday field in account details
        add_action('woocommerce_edit_account_form', array($this, 'add_birthday_field_account'));
        add_action('woocommerce_save_account_details', array($this, 'save_birthday_field_account'));

        // Admin user profile
        add_action('show_user_profile', array($this, 'add_birthday_field_admin'));
        add_action('edit_user_profile', array($this, 'add_birthday_field_admin'));
        add_action('personal_options_update', array($this, 'save_birthday_field_admin'));
        add_action('edit_user_profile_update', array($this, 'save_birthday_field_admin'));

        // Price modification
        add_filter('woocommerce_product_get_price', array($this, 'apply_birthday_discount'), 99, 2);
        add_filter('woocommerce_product_get_sale_price', array($this, 'apply_birthday_discount'), 99, 2);
        add_filter('woocommerce_product_variation_get_price', array($this, 'apply_birthday_discount'), 99, 2);

        // Cart and checkout
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_birthday_cart_discount'));
        add_action('woocommerce_before_cart', array($this, 'display_birthday_message'));
        add_action('woocommerce_before_checkout_form', array($this, 'display_birthday_message'));

        // Order milestone tracking
        add_action('woocommerce_order_status_completed', array($this, 'check_order_milestones'));
        add_action('woocommerce_order_status_completed', array($this, 'update_first_order_date'));

        // Scheduled events
        add_action('jdpd_birthday_check', array($this, 'send_birthday_emails'));
        add_action('jdpd_anniversary_check', array($this, 'send_anniversary_emails'));

        // AJAX handlers
        add_action('wp_ajax_jdpd_get_birthday_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_jdpd_generate_birthday_coupons', array($this, 'ajax_generate_coupons'));
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'jezweb-dynamic-pricing',
            __('Birthday Discounts', 'jezweb-dynamic-pricing'),
            __('Birthday Discounts', 'jezweb-dynamic-pricing'),
            'manage_woocommerce',
            'jdpd-birthday-discounts',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting('jdpd_birthday_discounts', 'jdpd_birthday_options');
    }

    /**
     * Render admin page.
     */
    public function render_admin_page() {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Birthday & Anniversary Discounts', 'jezweb-dynamic-pricing'); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields('jdpd_birthday_discounts'); ?>

                <h2><?php esc_html_e('Birthday Discount Settings', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Birthday Discounts', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_birthday_options[birthday_enabled]" value="1"
                                    <?php checked(!empty($options['birthday_enabled'])); ?>>
                                <?php esc_html_e('Offer discounts on customer birthdays', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Birthday Discount Type', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <select name="jdpd_birthday_options[birthday_type]">
                                <option value="percentage" <?php selected($options['birthday_type'] ?? 'percentage', 'percentage'); ?>>
                                    <?php esc_html_e('Percentage discount', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="fixed_cart" <?php selected($options['birthday_type'] ?? 'percentage', 'fixed_cart'); ?>>
                                    <?php esc_html_e('Fixed cart discount', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="free_shipping" <?php selected($options['birthday_type'] ?? 'percentage', 'free_shipping'); ?>>
                                    <?php esc_html_e('Free shipping', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="coupon" <?php selected($options['birthday_type'] ?? 'percentage', 'coupon'); ?>>
                                    <?php esc_html_e('Generate unique coupon', 'jezweb-dynamic-pricing'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Birthday Discount Amount', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_birthday_options[birthday_amount]"
                                value="<?php echo esc_attr($options['birthday_amount'] ?? 10); ?>" min="0" step="0.01">
                            <p class="description"><?php esc_html_e('Percentage or fixed amount depending on type.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Birthday Window (Days)', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_birthday_options[birthday_window]"
                                value="<?php echo esc_attr($options['birthday_window'] ?? 7); ?>" min="1" max="30">
                            <p class="description"><?php esc_html_e('How many days the birthday discount is valid (e.g., 7 = birthday week).', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Require Birthday Field', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_birthday_options[require_birthday]" value="1"
                                    <?php checked(!empty($options['require_birthday'])); ?>>
                                <?php esc_html_e('Make birthday field required on registration', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Show on Checkout', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_birthday_options[show_on_checkout]" value="1"
                                    <?php checked(!empty($options['show_on_checkout'])); ?>>
                                <?php esc_html_e('Show birthday field on checkout for guests', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Anniversary Discount Settings', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Anniversary Discounts', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_birthday_options[anniversary_enabled]" value="1"
                                    <?php checked(!empty($options['anniversary_enabled'])); ?>>
                                <?php esc_html_e('Offer discounts on customer order anniversaries', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('First Order Anniversary', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_birthday_options[anniversary_1_discount]"
                                value="<?php echo esc_attr($options['anniversary_1_discount'] ?? 15); ?>" min="0" max="100" step="0.1">%
                            <p class="description"><?php esc_html_e('Discount on 1st anniversary of first order.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Anniversary Window (Days)', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_birthday_options[anniversary_window]"
                                value="<?php echo esc_attr($options['anniversary_window'] ?? 7); ?>" min="1" max="30">
                            <p class="description"><?php esc_html_e('How many days the anniversary discount is valid.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Order Milestone Rewards', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Milestone Rewards', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_birthday_options[milestones_enabled]" value="1"
                                    <?php checked(!empty($options['milestones_enabled'])); ?>>
                                <?php esc_html_e('Reward customers at order milestones', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Milestone Orders', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <div class="jdpd-milestone-settings">
                                <?php
                                $milestones = $options['milestones'] ?? array(
                                    array('orders' => 5, 'discount' => 10),
                                    array('orders' => 10, 'discount' => 15),
                                    array('orders' => 25, 'discount' => 20),
                                    array('orders' => 50, 'discount' => 25),
                                    array('orders' => 100, 'discount' => 30),
                                );
                                foreach ($milestones as $index => $milestone):
                                ?>
                                    <div class="milestone-row">
                                        <label>
                                            <?php esc_html_e('After', 'jezweb-dynamic-pricing'); ?>
                                            <input type="number" name="jdpd_birthday_options[milestones][<?php echo $index; ?>][orders]"
                                                value="<?php echo esc_attr($milestone['orders']); ?>" min="1" style="width:60px;">
                                            <?php esc_html_e('orders:', 'jezweb-dynamic-pricing'); ?>
                                        </label>
                                        <input type="number" name="jdpd_birthday_options[milestones][<?php echo $index; ?>][discount]"
                                            value="<?php echo esc_attr($milestone['discount']); ?>" min="0" max="100" step="0.1" style="width:60px;">%
                                        <?php esc_html_e('discount', 'jezweb-dynamic-pricing'); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="description"><?php esc_html_e('Discount given when customer reaches each order milestone.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Email Notifications', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Birthday Email', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_birthday_options[birthday_email]" value="1"
                                    <?php checked(!empty($options['birthday_email'])); ?>>
                                <?php esc_html_e('Send birthday discount email', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Days Before Birthday', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_birthday_options[birthday_email_days]"
                                value="<?php echo esc_attr($options['birthday_email_days'] ?? 0); ?>" min="0" max="30">
                            <p class="description"><?php esc_html_e('Send email this many days before birthday. 0 = on birthday.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Birthday Email Subject', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="text" name="jdpd_birthday_options[birthday_email_subject]"
                                value="<?php echo esc_attr($options['birthday_email_subject'] ?? 'Happy Birthday! Here\'s a special gift for you'); ?>"
                                class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Birthday Email Content', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <?php
                            wp_editor(
                                $options['birthday_email_content'] ?? $this->get_default_birthday_email(),
                                'birthday_email_content',
                                array(
                                    'textarea_name' => 'jdpd_birthday_options[birthday_email_content]',
                                    'textarea_rows' => 10,
                                    'media_buttons' => false,
                                )
                            );
                            ?>
                            <p class="description">
                                <?php esc_html_e('Available placeholders: {customer_name}, {discount_amount}, {discount_type}, {coupon_code}, {expiry_date}, {site_name}', 'jezweb-dynamic-pricing'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Anniversary Email', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_birthday_options[anniversary_email]" value="1"
                                    <?php checked(!empty($options['anniversary_email'])); ?>>
                                <?php esc_html_e('Send anniversary discount email', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Statistics & Tools', 'jezweb-dynamic-pricing'); ?></h2>

            <?php $this->render_statistics(); ?>

            <h3><?php esc_html_e('Generate Birthday Coupons', 'jezweb-dynamic-pricing'); ?></h3>
            <p><?php esc_html_e('Generate birthday coupons for all customers with upcoming birthdays.', 'jezweb-dynamic-pricing'); ?></p>
            <button type="button" class="button" id="generate-birthday-coupons">
                <?php esc_html_e('Generate Coupons for This Month', 'jezweb-dynamic-pricing'); ?>
            </button>
            <span id="coupon-generation-result"></span>

            <h3><?php esc_html_e('Upcoming Birthdays', 'jezweb-dynamic-pricing'); ?></h3>
            <?php $this->render_upcoming_birthdays(); ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#generate-birthday-coupons').on('click', function() {
                var $btn = $(this);
                var $result = $('#coupon-generation-result');

                $btn.prop('disabled', true);
                $result.text('Generating...');

                $.post(ajaxurl, {
                    action: 'jdpd_generate_birthday_coupons',
                    nonce: '<?php echo wp_create_nonce('jdpd_admin_nonce'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.text(response.data.message);
                    } else {
                        $result.text('Error: ' + response.data.message);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get default options.
     *
     * @return array
     */
    private function get_default_options() {
        return array(
            'birthday_enabled' => false,
            'birthday_type' => 'percentage',
            'birthday_amount' => 10,
            'birthday_window' => 7,
            'require_birthday' => false,
            'show_on_checkout' => true,
            'anniversary_enabled' => false,
            'anniversary_1_discount' => 15,
            'anniversary_window' => 7,
            'milestones_enabled' => false,
            'milestones' => array(
                array('orders' => 5, 'discount' => 10),
                array('orders' => 10, 'discount' => 15),
                array('orders' => 25, 'discount' => 20),
                array('orders' => 50, 'discount' => 25),
                array('orders' => 100, 'discount' => 30),
            ),
            'birthday_email' => true,
            'birthday_email_days' => 0,
            'birthday_email_subject' => 'Happy Birthday! Here\'s a special gift for you',
            'birthday_email_content' => $this->get_default_birthday_email(),
            'anniversary_email' => true,
        );
    }

    /**
     * Get default birthday email content.
     *
     * @return string
     */
    private function get_default_birthday_email() {
        return '<h2>Happy Birthday, {customer_name}!</h2>
<p>We wanted to wish you a wonderful birthday and thank you for being a valued customer.</p>
<p>As a birthday gift, we\'re giving you <strong>{discount_amount}% off</strong> your next purchase!</p>
<p>Use code: <strong>{coupon_code}</strong></p>
<p>This offer expires on {expiry_date}.</p>
<p>Have a great day!</p>
<p>- The {site_name} Team</p>';
    }

    /**
     * Add birthday field to registration form.
     */
    public function add_birthday_field_registration() {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());
        $required = !empty($options['require_birthday']);
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="reg_billing_birthday">
                <?php esc_html_e('Birthday', 'jezweb-dynamic-pricing'); ?>
                <?php if ($required): ?><span class="required">*</span><?php endif; ?>
            </label>
            <input type="date" class="woocommerce-Input input-text" name="billing_birthday" id="reg_billing_birthday"
                <?php echo $required ? 'required' : ''; ?>
                max="<?php echo esc_attr(date('Y-m-d', strtotime('-13 years'))); ?>">
            <span class="description"><?php esc_html_e('We\'ll send you a special birthday discount!', 'jezweb-dynamic-pricing'); ?></span>
        </p>
        <?php
    }

    /**
     * Validate birthday field on registration.
     *
     * @param WP_Error $errors   Validation errors.
     * @param string   $username Username.
     * @param string   $email    Email.
     * @return WP_Error
     */
    public function validate_birthday_field($errors, $username, $email) {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());

        if (!empty($options['require_birthday']) && empty($_POST['billing_birthday'])) {
            $errors->add('billing_birthday_error', __('Please enter your birthday.', 'jezweb-dynamic-pricing'));
        }

        if (!empty($_POST['billing_birthday'])) {
            $birthday = sanitize_text_field($_POST['billing_birthday']);
            $birthday_time = strtotime($birthday);

            // Must be at least 13 years old
            if ($birthday_time > strtotime('-13 years')) {
                $errors->add('billing_birthday_error', __('You must be at least 13 years old to register.', 'jezweb-dynamic-pricing'));
            }

            // Can't be in the future or more than 120 years ago
            if ($birthday_time > time() || $birthday_time < strtotime('-120 years')) {
                $errors->add('billing_birthday_error', __('Please enter a valid birthday.', 'jezweb-dynamic-pricing'));
            }
        }

        return $errors;
    }

    /**
     * Save birthday field on registration.
     *
     * @param int $customer_id Customer ID.
     */
    public function save_birthday_field_registration($customer_id) {
        if (!empty($_POST['billing_birthday'])) {
            $birthday = sanitize_text_field($_POST['billing_birthday']);
            update_user_meta($customer_id, 'billing_birthday', $birthday);
        }
    }

    /**
     * Add birthday field to checkout.
     *
     * @param WC_Checkout $checkout Checkout object.
     */
    public function add_birthday_field_checkout($checkout) {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());

        if (empty($options['show_on_checkout'])) {
            return;
        }

        // Don't show if user already has birthday set
        if (is_user_logged_in()) {
            $birthday = get_user_meta(get_current_user_id(), 'billing_birthday', true);
            if (!empty($birthday)) {
                return;
            }
        }

        echo '<div class="jdpd-birthday-field">';

        woocommerce_form_field('billing_birthday', array(
            'type' => 'date',
            'class' => array('form-row-wide'),
            'label' => __('Birthday', 'jezweb-dynamic-pricing'),
            'description' => __('Enter your birthday to receive a special birthday discount!', 'jezweb-dynamic-pricing'),
            'custom_attributes' => array(
                'max' => date('Y-m-d', strtotime('-13 years')),
            ),
        ), $checkout->get_value('billing_birthday'));

        echo '</div>';
    }

    /**
     * Save birthday field on checkout.
     *
     * @param int $order_id Order ID.
     */
    public function save_birthday_field_checkout($order_id) {
        if (!empty($_POST['billing_birthday'])) {
            $birthday = sanitize_text_field($_POST['billing_birthday']);

            // Save to order
            update_post_meta($order_id, '_billing_birthday', $birthday);

            // Save to user if logged in
            $order = wc_get_order($order_id);
            if ($order && $order->get_customer_id()) {
                update_user_meta($order->get_customer_id(), 'billing_birthday', $birthday);
            }
        }
    }

    /**
     * Add birthday field to account details.
     */
    public function add_birthday_field_account() {
        $user_id = get_current_user_id();
        $birthday = get_user_meta($user_id, 'billing_birthday', true);
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="billing_birthday"><?php esc_html_e('Birthday', 'jezweb-dynamic-pricing'); ?></label>
            <input type="date" class="woocommerce-Input input-text" name="billing_birthday" id="billing_birthday"
                value="<?php echo esc_attr($birthday); ?>"
                max="<?php echo esc_attr(date('Y-m-d', strtotime('-13 years'))); ?>">
            <span class="description"><?php esc_html_e('Add your birthday to receive a special birthday discount!', 'jezweb-dynamic-pricing'); ?></span>
        </p>
        <?php
    }

    /**
     * Save birthday field from account details.
     *
     * @param int $user_id User ID.
     */
    public function save_birthday_field_account($user_id) {
        if (isset($_POST['billing_birthday'])) {
            $birthday = sanitize_text_field($_POST['billing_birthday']);
            update_user_meta($user_id, 'billing_birthday', $birthday);
        }
    }

    /**
     * Add birthday field to admin user profile.
     *
     * @param WP_User $user User object.
     */
    public function add_birthday_field_admin($user) {
        $birthday = get_user_meta($user->ID, 'billing_birthday', true);
        $first_order_date = get_user_meta($user->ID, '_jdpd_first_order_date', true);
        $order_count = $this->get_customer_order_count($user->ID);
        ?>
        <h3><?php esc_html_e('Birthday & Loyalty Information', 'jezweb-dynamic-pricing'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="billing_birthday"><?php esc_html_e('Birthday', 'jezweb-dynamic-pricing'); ?></label></th>
                <td>
                    <input type="date" name="billing_birthday" id="billing_birthday"
                        value="<?php echo esc_attr($birthday); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('First Order Date', 'jezweb-dynamic-pricing'); ?></th>
                <td>
                    <?php
                    if ($first_order_date) {
                        echo esc_html(date_i18n(get_option('date_format'), strtotime($first_order_date)));
                    } else {
                        esc_html_e('No orders yet', 'jezweb-dynamic-pricing');
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Total Orders', 'jezweb-dynamic-pricing'); ?></th>
                <td><?php echo esc_html($order_count); ?></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save birthday field from admin.
     *
     * @param int $user_id User ID.
     */
    public function save_birthday_field_admin($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        if (isset($_POST['billing_birthday'])) {
            $birthday = sanitize_text_field($_POST['billing_birthday']);
            update_user_meta($user_id, 'billing_birthday', $birthday);
        }
    }

    /**
     * Check if customer has birthday discount.
     *
     * @param int $user_id User ID.
     * @return bool
     */
    public function is_customer_birthday($user_id = null) {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());

        if (empty($options['birthday_enabled'])) {
            return false;
        }

        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return false;
        }

        $birthday = get_user_meta($user_id, 'billing_birthday', true);

        if (empty($birthday)) {
            return false;
        }

        $window = intval($options['birthday_window'] ?? 7);

        return $this->is_within_date_window($birthday, $window);
    }

    /**
     * Check if date is within window of current date.
     *
     * @param string $date   Date in Y-m-d format.
     * @param int    $window Window in days.
     * @return bool
     */
    private function is_within_date_window($date, $window) {
        $date_parts = explode('-', $date);

        if (count($date_parts) < 3) {
            return false;
        }

        $month = intval($date_parts[1]);
        $day = intval($date_parts[2]);

        // Create this year's birthday
        $this_year = date('Y');
        $birthday_this_year = "$this_year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-" . str_pad($day, 2, '0', STR_PAD_LEFT);

        $birthday_time = strtotime($birthday_this_year);
        $now = time();

        // Check if within window days before or after
        $window_start = strtotime("-$window days", $birthday_time);
        $window_end = strtotime("+$window days", $birthday_time);

        return ($now >= $window_start && $now <= $window_end);
    }

    /**
     * Get birthday discount amount.
     *
     * @return array Discount info.
     */
    public function get_birthday_discount() {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());

        return array(
            'type' => $options['birthday_type'] ?? 'percentage',
            'amount' => floatval($options['birthday_amount'] ?? 10),
        );
    }

    /**
     * Apply birthday discount to product price.
     *
     * @param float      $price   Price.
     * @param WC_Product $product Product.
     * @return float
     */
    public function apply_birthday_discount($price, $product) {
        // Don't apply in admin
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }

        if (!$this->is_customer_birthday()) {
            return $price;
        }

        $discount = $this->get_birthday_discount();

        // Only apply percentage discount at product level
        if ($discount['type'] !== 'percentage') {
            return $price;
        }

        $discount_amount = ($price * $discount['amount']) / 100;

        return max(0, $price - $discount_amount);
    }

    /**
     * Apply birthday discount to cart.
     *
     * @param WC_Cart $cart Cart object.
     */
    public function apply_birthday_cart_discount($cart) {
        if (!$this->is_customer_birthday()) {
            return;
        }

        $discount = $this->get_birthday_discount();

        switch ($discount['type']) {
            case 'fixed_cart':
                $cart->add_fee(
                    __('Birthday Discount', 'jezweb-dynamic-pricing'),
                    -$discount['amount'],
                    false
                );
                break;

            case 'free_shipping':
                // Free shipping handled via filter
                add_filter('woocommerce_shipping_free_shipping_is_available', '__return_true', 999);
                break;
        }
    }

    /**
     * Display birthday message in cart/checkout.
     */
    public function display_birthday_message() {
        if (!$this->is_customer_birthday()) {
            return;
        }

        $discount = $this->get_birthday_discount();
        $message = '';

        switch ($discount['type']) {
            case 'percentage':
                $message = sprintf(
                    __('Happy Birthday! Enjoy %s%% off your entire order!', 'jezweb-dynamic-pricing'),
                    $discount['amount']
                );
                break;

            case 'fixed_cart':
                $message = sprintf(
                    __('Happy Birthday! Enjoy %s off your order!', 'jezweb-dynamic-pricing'),
                    wc_price($discount['amount'])
                );
                break;

            case 'free_shipping':
                $message = __('Happy Birthday! Enjoy free shipping on your order!', 'jezweb-dynamic-pricing');
                break;
        }

        if (!empty($message)) {
            wc_print_notice($message, 'notice');
        }
    }

    /**
     * Check if customer has anniversary discount.
     *
     * @param int $user_id User ID.
     * @return array|false Anniversary info or false.
     */
    public function get_anniversary_discount($user_id = null) {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());

        if (empty($options['anniversary_enabled'])) {
            return false;
        }

        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return false;
        }

        $first_order_date = get_user_meta($user_id, '_jdpd_first_order_date', true);

        if (empty($first_order_date)) {
            return false;
        }

        $window = intval($options['anniversary_window'] ?? 7);

        if (!$this->is_within_date_window($first_order_date, $window)) {
            return false;
        }

        // Calculate which anniversary
        $years = floor((time() - strtotime($first_order_date)) / (365 * 24 * 60 * 60));

        if ($years < 1) {
            return false;
        }

        return array(
            'years' => $years,
            'discount' => floatval($options['anniversary_1_discount'] ?? 15),
        );
    }

    /**
     * Update first order date for customer.
     *
     * @param int $order_id Order ID.
     */
    public function update_first_order_date($order_id) {
        $order = wc_get_order($order_id);

        if (!$order || !$order->get_customer_id()) {
            return;
        }

        $customer_id = $order->get_customer_id();
        $existing = get_user_meta($customer_id, '_jdpd_first_order_date', true);

        if (empty($existing)) {
            update_user_meta($customer_id, '_jdpd_first_order_date', $order->get_date_created()->format('Y-m-d'));
        }
    }

    /**
     * Check order milestones.
     *
     * @param int $order_id Order ID.
     */
    public function check_order_milestones($order_id) {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());

        if (empty($options['milestones_enabled'])) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order || !$order->get_customer_id()) {
            return;
        }

        $customer_id = $order->get_customer_id();
        $order_count = $this->get_customer_order_count($customer_id);
        $milestones = $options['milestones'] ?? array();

        foreach ($milestones as $milestone) {
            if ($order_count == $milestone['orders']) {
                $this->award_milestone_discount($customer_id, $milestone);
                break;
            }
        }
    }

    /**
     * Award milestone discount.
     *
     * @param int   $customer_id Customer ID.
     * @param array $milestone   Milestone data.
     */
    private function award_milestone_discount($customer_id, $milestone) {
        // Generate unique coupon
        $coupon_code = 'MILESTONE' . $milestone['orders'] . '-' . strtoupper(wp_generate_password(6, false));

        $coupon = new WC_Coupon();
        $coupon->set_code($coupon_code);
        $coupon->set_discount_type('percent');
        $coupon->set_amount($milestone['discount']);
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_email_restrictions(array(get_userdata($customer_id)->user_email));
        $coupon->set_date_expires(strtotime('+30 days'));
        $coupon->save();

        // Send email
        $this->send_milestone_email($customer_id, $milestone, $coupon_code);

        // Log
        update_user_meta($customer_id, '_jdpd_last_milestone', array(
            'orders' => $milestone['orders'],
            'coupon' => $coupon_code,
            'date' => current_time('mysql'),
        ));
    }

    /**
     * Send milestone email.
     *
     * @param int    $customer_id Customer ID.
     * @param array  $milestone   Milestone data.
     * @param string $coupon_code Coupon code.
     */
    private function send_milestone_email($customer_id, $milestone, $coupon_code) {
        $user = get_userdata($customer_id);

        if (!$user) {
            return;
        }

        $subject = sprintf(
            __('Congratulations on your %d order! Here\'s a special reward', 'jezweb-dynamic-pricing'),
            $milestone['orders']
        );

        $message = sprintf(
            __('Dear %s,

Thank you for being such a loyal customer! You\'ve just completed your %d order with us.

As a thank you, here\'s a special %d%% discount on your next purchase:

Coupon Code: %s

This coupon is valid for 30 days.

Thank you for your continued support!

%s', 'jezweb-dynamic-pricing'),
            $user->display_name,
            $milestone['orders'],
            $milestone['discount'],
            $coupon_code,
            get_bloginfo('name')
        );

        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Get customer order count.
     *
     * @param int $customer_id Customer ID.
     * @return int
     */
    private function get_customer_order_count($customer_id) {
        return wc_get_customer_order_count($customer_id);
    }

    /**
     * Send birthday emails (scheduled task).
     */
    public function send_birthday_emails() {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());

        if (empty($options['birthday_email'])) {
            return;
        }

        $days_before = intval($options['birthday_email_days'] ?? 0);
        $target_date = date('m-d', strtotime("+$days_before days"));

        // Get all users with birthdays on target date
        $users = get_users(array(
            'meta_key' => 'billing_birthday',
            'meta_value' => $target_date,
            'meta_compare' => 'LIKE',
        ));

        foreach ($users as $user) {
            // Check if already sent this year
            $last_sent = get_user_meta($user->ID, '_jdpd_birthday_email_sent', true);

            if ($last_sent === date('Y')) {
                continue;
            }

            $this->send_birthday_email_to_user($user);

            update_user_meta($user->ID, '_jdpd_birthday_email_sent', date('Y'));
        }
    }

    /**
     * Send birthday email to specific user.
     *
     * @param WP_User $user User object.
     */
    private function send_birthday_email_to_user($user) {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());
        $discount = $this->get_birthday_discount();

        // Generate coupon if needed
        $coupon_code = '';
        if ($discount['type'] === 'coupon') {
            $coupon_code = $this->generate_birthday_coupon($user->ID);
        }

        $subject = $options['birthday_email_subject'] ?? 'Happy Birthday!';
        $content = $options['birthday_email_content'] ?? $this->get_default_birthday_email();

        // Replace placeholders
        $expiry_date = date_i18n(get_option('date_format'), strtotime('+' . ($options['birthday_window'] ?? 7) . ' days'));

        $replacements = array(
            '{customer_name}' => $user->display_name,
            '{discount_amount}' => $discount['amount'],
            '{discount_type}' => $discount['type'],
            '{coupon_code}' => $coupon_code,
            '{expiry_date}' => $expiry_date,
            '{site_name}' => get_bloginfo('name'),
        );

        $content = str_replace(array_keys($replacements), array_values($replacements), $content);

        // Send HTML email
        add_filter('wp_mail_content_type', function() { return 'text/html'; });

        wp_mail($user->user_email, $subject, $content);

        remove_filter('wp_mail_content_type', function() { return 'text/html'; });
    }

    /**
     * Generate birthday coupon for user.
     *
     * @param int $user_id User ID.
     * @return string Coupon code.
     */
    public function generate_birthday_coupon($user_id) {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());
        $user = get_userdata($user_id);

        $coupon_code = 'BDAY-' . strtoupper(wp_generate_password(8, false));

        $coupon = new WC_Coupon();
        $coupon->set_code($coupon_code);
        $coupon->set_discount_type('percent');
        $coupon->set_amount($options['birthday_amount'] ?? 10);
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_email_restrictions(array($user->user_email));
        $coupon->set_date_expires(strtotime('+' . ($options['birthday_window'] ?? 7) . ' days'));
        $coupon->save();

        update_user_meta($user_id, '_jdpd_birthday_coupon_' . date('Y'), $coupon_code);

        return $coupon_code;
    }

    /**
     * Send anniversary emails (scheduled task).
     */
    public function send_anniversary_emails() {
        $options = get_option('jdpd_birthday_options', $this->get_default_options());

        if (empty($options['anniversary_email']) || empty($options['anniversary_enabled'])) {
            return;
        }

        $target_date = date('m-d');

        // Get users with first order on this date in previous years
        global $wpdb;

        $users = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
            WHERE meta_key = '_jdpd_first_order_date'
            AND meta_value LIKE %s",
            '%-' . $target_date
        ));

        foreach ($users as $user_id) {
            $anniversary = $this->get_anniversary_discount($user_id);

            if (!$anniversary) {
                continue;
            }

            // Check if already sent this year
            $last_sent = get_user_meta($user_id, '_jdpd_anniversary_email_sent', true);

            if ($last_sent === date('Y')) {
                continue;
            }

            $this->send_anniversary_email_to_user($user_id, $anniversary);

            update_user_meta($user_id, '_jdpd_anniversary_email_sent', date('Y'));
        }
    }

    /**
     * Send anniversary email.
     *
     * @param int   $user_id     User ID.
     * @param array $anniversary Anniversary data.
     */
    private function send_anniversary_email_to_user($user_id, $anniversary) {
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        // Generate coupon
        $coupon_code = 'ANNIV' . $anniversary['years'] . '-' . strtoupper(wp_generate_password(6, false));

        $coupon = new WC_Coupon();
        $coupon->set_code($coupon_code);
        $coupon->set_discount_type('percent');
        $coupon->set_amount($anniversary['discount']);
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_email_restrictions(array($user->user_email));
        $coupon->set_date_expires(strtotime('+7 days'));
        $coupon->save();

        $subject = sprintf(
            __('Happy %d Year Anniversary! Here\'s a special thank you', 'jezweb-dynamic-pricing'),
            $anniversary['years']
        );

        $message = sprintf(
            __('Dear %s,

Can you believe it\'s been %d year(s) since your first order with us?

To celebrate this special anniversary, we\'re giving you %d%% off your next purchase!

Use code: %s

This offer expires in 7 days.

Thank you for being part of our journey!

%s', 'jezweb-dynamic-pricing'),
            $user->display_name,
            $anniversary['years'],
            $anniversary['discount'],
            $coupon_code,
            get_bloginfo('name')
        );

        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Render statistics.
     */
    private function render_statistics() {
        global $wpdb;

        // Count users with birthdays
        $users_with_birthday = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'billing_birthday' AND meta_value != ''"
        );

        // Count birthday coupons used
        $birthday_coupons_used = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'shop_coupon'
            AND post_title LIKE 'BDAY-%'
            AND post_status = 'publish'"
        );

        ?>
        <table class="widefat" style="max-width: 600px;">
            <tr>
                <td><?php esc_html_e('Customers with Birthday on File', 'jezweb-dynamic-pricing'); ?></td>
                <td><strong><?php echo esc_html($users_with_birthday); ?></strong></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Birthday Coupons Generated', 'jezweb-dynamic-pricing'); ?></td>
                <td><strong><?php echo esc_html($birthday_coupons_used); ?></strong></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render upcoming birthdays.
     */
    private function render_upcoming_birthdays() {
        $current_month = date('m');
        $current_day = date('d');

        global $wpdb;

        // Get birthdays in next 30 days
        $users = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value as birthday FROM {$wpdb->usermeta}
            WHERE meta_key = 'billing_birthday'
            AND meta_value != ''
            ORDER BY SUBSTRING(meta_value, 6) ASC
            LIMIT 20"
        ));

        if (empty($users)) {
            echo '<p>' . esc_html__('No customers with birthdays on file.', 'jezweb-dynamic-pricing') . '</p>';
            return;
        }

        ?>
        <table class="wp-list-table widefat fixed striped" style="max-width: 600px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Customer', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Birthday', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Days Until', 'jezweb-dynamic-pricing'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($users as $user_data):
                    $user = get_userdata($user_data->user_id);
                    if (!$user) continue;

                    $birthday_parts = explode('-', $user_data->birthday);
                    if (count($birthday_parts) < 3) continue;

                    $this_year_birthday = date('Y') . '-' . $birthday_parts[1] . '-' . $birthday_parts[2];
                    $birthday_time = strtotime($this_year_birthday);

                    if ($birthday_time < time()) {
                        $birthday_time = strtotime('+1 year', $birthday_time);
                    }

                    $days_until = ceil(($birthday_time - time()) / (60 * 60 * 24));
                ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>">
                                <?php echo esc_html($user->display_name); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html(date_i18n('F j', strtotime($user_data->birthday))); ?></td>
                        <td>
                            <?php
                            if ($days_until == 0) {
                                echo '<strong>' . esc_html__('Today!', 'jezweb-dynamic-pricing') . '</strong>';
                            } elseif ($days_until == 1) {
                                echo esc_html__('Tomorrow', 'jezweb-dynamic-pricing');
                            } else {
                                echo esc_html(sprintf(__('%d days', 'jezweb-dynamic-pricing'), $days_until));
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * AJAX: Get birthday stats.
     */
    public function ajax_get_stats() {
        check_ajax_referer('jdpd_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'jezweb-dynamic-pricing')));
        }

        global $wpdb;

        // Stats
        $stats = array(
            'total_with_birthday' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'billing_birthday' AND meta_value != ''"
            ),
            'birthdays_this_month' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta}
                WHERE meta_key = 'billing_birthday'
                AND meta_value LIKE %s",
                '%-' . date('m') . '-%'
            )),
        );

        wp_send_json_success($stats);
    }

    /**
     * AJAX: Generate birthday coupons.
     */
    public function ajax_generate_coupons() {
        check_ajax_referer('jdpd_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'jezweb-dynamic-pricing')));
        }

        global $wpdb;

        // Get users with birthdays this month
        $users = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
            WHERE meta_key = 'billing_birthday'
            AND meta_value LIKE %s",
            '%-' . date('m') . '-%'
        ));

        $generated = 0;

        foreach ($users as $user_id) {
            // Check if already has coupon for this year
            $existing = get_user_meta($user_id, '_jdpd_birthday_coupon_' . date('Y'), true);

            if (empty($existing)) {
                $this->generate_birthday_coupon($user_id);
                $generated++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Generated %d birthday coupons for %d customers with birthdays this month.', 'jezweb-dynamic-pricing'),
                $generated,
                count($users)
            ),
        ));
    }

    /**
     * Schedule daily checks.
     */
    public static function schedule_events() {
        if (!wp_next_scheduled('jdpd_birthday_check')) {
            wp_schedule_event(strtotime('06:00:00'), 'daily', 'jdpd_birthday_check');
        }

        if (!wp_next_scheduled('jdpd_anniversary_check')) {
            wp_schedule_event(strtotime('06:00:00'), 'daily', 'jdpd_anniversary_check');
        }
    }

    /**
     * Clear scheduled events.
     */
    public static function clear_events() {
        wp_clear_scheduled_hook('jdpd_birthday_check');
        wp_clear_scheduled_hook('jdpd_anniversary_check');
    }
}

// Initialize the class
JDPD_Birthday_Discounts::get_instance();
