<?php
/**
 * Wishlist-Based Pricing
 *
 * Offer discounts based on wishlist behavior to encourage conversions.
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Includes
 * @since      1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JDPD_Wishlist_Pricing class.
 *
 * Features:
 * - Built-in simple wishlist functionality
 * - Integration with YITH WooCommerce Wishlist
 * - Integration with TI WooCommerce Wishlist
 * - Time-based wishlist discounts (item on wishlist > X days)
 * - Wishlist reminder emails with special offers
 * - "Price drop" notifications for wishlisted items
 * - Wishlist conversion tracking
 *
 * @since 1.4.0
 */
class JDPD_Wishlist_Pricing {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Wishlist_Pricing
     */
    private static $instance = null;

    /**
     * Detected wishlist plugin.
     *
     * @var string
     */
    private $wishlist_plugin = 'native';

    /**
     * Get single instance.
     *
     * @return JDPD_Wishlist_Pricing
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
        $this->detect_wishlist_plugin();
        $this->init_hooks();
    }

    /**
     * Detect active wishlist plugin.
     */
    private function detect_wishlist_plugin() {
        if (defined('YITH_WCWL')) {
            $this->wishlist_plugin = 'yith';
        } elseif (defined('TINVWL_FVERSION')) {
            $this->wishlist_plugin = 'ti';
        } else {
            $this->wishlist_plugin = 'native';
        }
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'), 25);
        add_action('admin_init', array($this, 'register_settings'));

        // Native wishlist hooks (if no plugin detected)
        if ($this->wishlist_plugin === 'native') {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
            add_action('woocommerce_after_add_to_cart_button', array($this, 'add_wishlist_button'));
            add_shortcode('jdpd_wishlist', array($this, 'render_wishlist_shortcode'));

            // AJAX handlers for native wishlist
            add_action('wp_ajax_jdpd_add_to_wishlist', array($this, 'ajax_add_to_wishlist'));
            add_action('wp_ajax_nopriv_jdpd_add_to_wishlist', array($this, 'ajax_add_to_wishlist'));
            add_action('wp_ajax_jdpd_remove_from_wishlist', array($this, 'ajax_remove_from_wishlist'));
            add_action('wp_ajax_nopriv_jdpd_remove_from_wishlist', array($this, 'ajax_remove_from_wishlist'));
            add_action('wp_ajax_jdpd_get_wishlist', array($this, 'ajax_get_wishlist'));
            add_action('wp_ajax_nopriv_jdpd_get_wishlist', array($this, 'ajax_get_wishlist'));
        }

        // Price modification hooks
        add_filter('woocommerce_product_get_price', array($this, 'apply_wishlist_discount'), 98, 2);
        add_filter('woocommerce_product_get_sale_price', array($this, 'apply_wishlist_discount'), 98, 2);
        add_filter('woocommerce_product_variation_get_price', array($this, 'apply_wishlist_discount'), 98, 2);

        // Product page display
        add_action('woocommerce_single_product_summary', array($this, 'display_wishlist_discount_notice'), 15);

        // YITH Wishlist integration
        if ($this->wishlist_plugin === 'yith') {
            add_action('yith_wcwl_added_to_wishlist', array($this, 'on_yith_add_to_wishlist'), 10, 3);
            add_action('yith_wcwl_removed_from_wishlist', array($this, 'on_yith_remove_from_wishlist'), 10, 3);
        }

        // TI Wishlist integration
        if ($this->wishlist_plugin === 'ti') {
            add_action('tinvwl_after_add_to_wishlist', array($this, 'on_ti_add_to_wishlist'), 10, 2);
            add_action('tinvwl_after_remove_from_wishlist', array($this, 'on_ti_remove_from_wishlist'), 10, 2);
        }

        // Track price drops
        add_action('woocommerce_update_product', array($this, 'check_price_drop'), 10, 2);

        // Scheduled events
        add_action('jdpd_wishlist_reminder', array($this, 'send_wishlist_reminders'));
        add_action('jdpd_price_drop_notifications', array($this, 'send_price_drop_notifications'));

        // Admin AJAX
        add_action('wp_ajax_jdpd_get_wishlist_stats', array($this, 'ajax_get_stats'));
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'jezweb-dynamic-pricing',
            __('Wishlist Pricing', 'jezweb-dynamic-pricing'),
            __('Wishlist Pricing', 'jezweb-dynamic-pricing'),
            'manage_woocommerce',
            'jdpd-wishlist-pricing',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting('jdpd_wishlist_pricing', 'jdpd_wishlist_options');
    }

    /**
     * Render admin page.
     */
    public function render_admin_page() {
        $options = get_option('jdpd_wishlist_options', $this->get_default_options());
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wishlist-Based Pricing', 'jezweb-dynamic-pricing'); ?></h1>

            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e('Detected Wishlist System:', 'jezweb-dynamic-pricing'); ?></strong>
                    <?php
                    switch ($this->wishlist_plugin) {
                        case 'yith':
                            esc_html_e('YITH WooCommerce Wishlist', 'jezweb-dynamic-pricing');
                            break;
                        case 'ti':
                            esc_html_e('TI WooCommerce Wishlist', 'jezweb-dynamic-pricing');
                            break;
                        default:
                            esc_html_e('Native (Built-in)', 'jezweb-dynamic-pricing');
                    }
                    ?>
                </p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('jdpd_wishlist_pricing'); ?>

                <h2><?php esc_html_e('Wishlist Discount Settings', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Wishlist Discounts', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_wishlist_options[enabled]" value="1"
                                    <?php checked(!empty($options['enabled'])); ?>>
                                <?php esc_html_e('Offer discounts on wishlisted items', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Time-Based Discount', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_wishlist_options[time_based]" value="1"
                                    <?php checked(!empty($options['time_based'])); ?>>
                                <?php esc_html_e('Increase discount the longer item is on wishlist', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e('Discount Tiers', 'jezweb-dynamic-pricing'); ?></h3>
                <table class="form-table">
                    <?php
                    $tiers = $options['tiers'] ?? array(
                        array('days' => 7, 'discount' => 5),
                        array('days' => 14, 'discount' => 10),
                        array('days' => 30, 'discount' => 15),
                        array('days' => 60, 'discount' => 20),
                    );
                    foreach ($tiers as $index => $tier):
                    ?>
                        <tr>
                            <th scope="row">
                                <?php printf(esc_html__('Tier %d', 'jezweb-dynamic-pricing'), $index + 1); ?>
                            </th>
                            <td>
                                <?php esc_html_e('After', 'jezweb-dynamic-pricing'); ?>
                                <input type="number" name="jdpd_wishlist_options[tiers][<?php echo $index; ?>][days]"
                                    value="<?php echo esc_attr($tier['days']); ?>" min="1" style="width:60px;">
                                <?php esc_html_e('days:', 'jezweb-dynamic-pricing'); ?>
                                <input type="number" name="jdpd_wishlist_options[tiers][<?php echo $index; ?>][discount]"
                                    value="<?php echo esc_attr($tier['discount']); ?>" min="0" max="100" step="0.5" style="width:60px;">%
                                <?php esc_html_e('discount', 'jezweb-dynamic-pricing'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h3><?php esc_html_e('Discount Limits', 'jezweb-dynamic-pricing'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Maximum Discount', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_wishlist_options[max_discount]"
                                value="<?php echo esc_attr($options['max_discount'] ?? 25); ?>" min="0" max="100">%
                            <p class="description"><?php esc_html_e('Maximum discount percentage for wishlist items.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Require Login', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_wishlist_options[require_login]" value="1"
                                    <?php checked(!empty($options['require_login'])); ?>>
                                <?php esc_html_e('Only logged-in users can receive wishlist discounts', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Email Notifications', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Wishlist Reminder Emails', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_wishlist_options[reminder_emails]" value="1"
                                    <?php checked(!empty($options['reminder_emails'])); ?>>
                                <?php esc_html_e('Send reminder emails about wishlisted items', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Reminder After Days', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_wishlist_options[reminder_days]"
                                value="<?php echo esc_attr($options['reminder_days'] ?? 7); ?>" min="1" max="90">
                            <p class="description"><?php esc_html_e('Send reminder this many days after item added to wishlist.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Price Drop Notifications', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_wishlist_options[price_drop_emails]" value="1"
                                    <?php checked(!empty($options['price_drop_emails'])); ?>>
                                <?php esc_html_e('Notify customers when wishlisted items go on sale', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Low Stock Notifications', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_wishlist_options[low_stock_emails]" value="1"
                                    <?php checked(!empty($options['low_stock_emails'])); ?>>
                                <?php esc_html_e('Notify customers when wishlisted items are running low', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Low Stock Threshold', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_wishlist_options[low_stock_threshold]"
                                value="<?php echo esc_attr($options['low_stock_threshold'] ?? 5); ?>" min="1">
                            <p class="description"><?php esc_html_e('Send notification when stock falls below this number.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php if ($this->wishlist_plugin === 'native'): ?>
                    <h2><?php esc_html_e('Native Wishlist Settings', 'jezweb-dynamic-pricing'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Wishlist Page', 'jezweb-dynamic-pricing'); ?></th>
                            <td>
                                <?php
                                wp_dropdown_pages(array(
                                    'name' => 'jdpd_wishlist_options[wishlist_page]',
                                    'selected' => $options['wishlist_page'] ?? 0,
                                    'show_option_none' => __('Select a page', 'jezweb-dynamic-pricing'),
                                ));
                                ?>
                                <p class="description">
                                    <?php esc_html_e('Select a page with the [jdpd_wishlist] shortcode.', 'jezweb-dynamic-pricing'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Button Text', 'jezweb-dynamic-pricing'); ?></th>
                            <td>
                                <input type="text" name="jdpd_wishlist_options[button_text]"
                                    value="<?php echo esc_attr($options['button_text'] ?? 'Add to Wishlist'); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Button Text (Added)', 'jezweb-dynamic-pricing'); ?></th>
                            <td>
                                <input type="text" name="jdpd_wishlist_options[button_text_added]"
                                    value="<?php echo esc_attr($options['button_text_added'] ?? 'View Wishlist'); ?>" class="regular-text">
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Wishlist Statistics', 'jezweb-dynamic-pricing'); ?></h2>
            <?php $this->render_statistics(); ?>
        </div>
        <?php
    }

    /**
     * Get default options.
     *
     * @return array
     */
    private function get_default_options() {
        return array(
            'enabled' => true,
            'time_based' => true,
            'tiers' => array(
                array('days' => 7, 'discount' => 5),
                array('days' => 14, 'discount' => 10),
                array('days' => 30, 'discount' => 15),
                array('days' => 60, 'discount' => 20),
            ),
            'max_discount' => 25,
            'require_login' => false,
            'reminder_emails' => true,
            'reminder_days' => 7,
            'price_drop_emails' => true,
            'low_stock_emails' => true,
            'low_stock_threshold' => 5,
            'wishlist_page' => 0,
            'button_text' => __('Add to Wishlist', 'jezweb-dynamic-pricing'),
            'button_text_added' => __('View Wishlist', 'jezweb-dynamic-pricing'),
        );
    }

    /**
     * Enqueue scripts for native wishlist.
     */
    public function enqueue_scripts() {
        if (!is_product() && !is_shop() && !is_product_category()) {
            return;
        }

        wp_enqueue_script(
            'jdpd-wishlist',
            JDPD_PLUGIN_URL . 'public/assets/js/wishlist.js',
            array('jquery'),
            JDPD_VERSION,
            true
        );

        wp_localize_script('jdpd-wishlist', 'jdpdWishlist', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jdpd_wishlist_nonce'),
            'i18n' => array(
                'added' => __('Added to wishlist!', 'jezweb-dynamic-pricing'),
                'removed' => __('Removed from wishlist', 'jezweb-dynamic-pricing'),
                'error' => __('An error occurred', 'jezweb-dynamic-pricing'),
            ),
        ));

        wp_enqueue_style(
            'jdpd-wishlist',
            JDPD_PLUGIN_URL . 'public/assets/css/wishlist.css',
            array(),
            JDPD_VERSION
        );
    }

    /**
     * Add wishlist button on product page.
     */
    public function add_wishlist_button() {
        global $product;

        if (!$product) {
            return;
        }

        $options = get_option('jdpd_wishlist_options', $this->get_default_options());
        $product_id = $product->get_id();
        $in_wishlist = $this->is_in_wishlist($product_id);

        $button_text = $in_wishlist
            ? ($options['button_text_added'] ?? __('View Wishlist', 'jezweb-dynamic-pricing'))
            : ($options['button_text'] ?? __('Add to Wishlist', 'jezweb-dynamic-pricing'));

        $wishlist_url = $this->get_wishlist_url();
        ?>
        <div class="jdpd-wishlist-button-wrap">
            <button type="button" class="jdpd-wishlist-button <?php echo $in_wishlist ? 'in-wishlist' : ''; ?>"
                data-product-id="<?php echo esc_attr($product_id); ?>"
                data-wishlist-url="<?php echo esc_url($wishlist_url); ?>">
                <span class="jdpd-wishlist-icon">
                    <?php echo $in_wishlist ? '&#9829;' : '&#9825;'; ?>
                </span>
                <span class="jdpd-wishlist-text"><?php echo esc_html($button_text); ?></span>
            </button>
        </div>
        <?php
    }

    /**
     * Get wishlist URL.
     *
     * @return string
     */
    private function get_wishlist_url() {
        $options = get_option('jdpd_wishlist_options', $this->get_default_options());

        if (!empty($options['wishlist_page'])) {
            return get_permalink($options['wishlist_page']);
        }

        return wc_get_account_endpoint_url('wishlist');
    }

    /**
     * Check if product is in wishlist.
     *
     * @param int $product_id Product ID.
     * @param int $user_id    User ID (optional).
     * @return bool
     */
    public function is_in_wishlist($product_id, $user_id = null) {
        switch ($this->wishlist_plugin) {
            case 'yith':
                return function_exists('yith_wcwl_is_product_in_wishlist')
                    ? yith_wcwl_is_product_in_wishlist($product_id)
                    : false;

            case 'ti':
                return class_exists('TInvWL_Public_Wishlist_View')
                    ? TInvWL_Public_Wishlist_View::wi()->in_wishlist($product_id)
                    : false;

            default:
                return $this->is_in_native_wishlist($product_id, $user_id);
        }
    }

    /**
     * Check if product is in native wishlist.
     *
     * @param int $product_id Product ID.
     * @param int $user_id    User ID (optional).
     * @return bool
     */
    private function is_in_native_wishlist($product_id, $user_id = null) {
        $wishlist = $this->get_native_wishlist($user_id);
        return isset($wishlist[$product_id]);
    }

    /**
     * Get native wishlist.
     *
     * @param int $user_id User ID (optional).
     * @return array
     */
    private function get_native_wishlist($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if ($user_id) {
            return get_user_meta($user_id, '_jdpd_wishlist', true) ?: array();
        } else {
            // Guest wishlist from session
            if (WC()->session) {
                return WC()->session->get('jdpd_wishlist', array());
            }
            return array();
        }
    }

    /**
     * Save native wishlist.
     *
     * @param array $wishlist Wishlist data.
     * @param int   $user_id  User ID (optional).
     */
    private function save_native_wishlist($wishlist, $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if ($user_id) {
            update_user_meta($user_id, '_jdpd_wishlist', $wishlist);
        } else {
            if (WC()->session) {
                WC()->session->set('jdpd_wishlist', $wishlist);
            }
        }
    }

    /**
     * Get date product was added to wishlist.
     *
     * @param int $product_id Product ID.
     * @param int $user_id    User ID (optional).
     * @return string|null Date in Y-m-d format or null.
     */
    public function get_wishlist_date($product_id, $user_id = null) {
        switch ($this->wishlist_plugin) {
            case 'yith':
                if (function_exists('YITH_WCWL')) {
                    $wishlist = YITH_WCWL()->get_wishlist();
                    if ($wishlist) {
                        foreach ($wishlist->get_items() as $item) {
                            if ($item->get_product_id() == $product_id) {
                                return $item->get_date_added()->format('Y-m-d');
                            }
                        }
                    }
                }
                return null;

            case 'ti':
                // TI WooCommerce Wishlist date tracking
                global $wpdb;
                $table = $wpdb->prefix . 'tinvwl_items';
                $date = $wpdb->get_var($wpdb->prepare(
                    "SELECT DATE(date) FROM $table WHERE product_id = %d AND author = %d ORDER BY ID DESC LIMIT 1",
                    $product_id,
                    $user_id ?: get_current_user_id()
                ));
                return $date;

            default:
                $wishlist = $this->get_native_wishlist($user_id);
                return $wishlist[$product_id]['date'] ?? null;
        }
    }

    /**
     * AJAX: Add to wishlist.
     */
    public function ajax_add_to_wishlist() {
        check_ajax_referer('jdpd_wishlist_nonce', 'nonce');

        $product_id = intval($_POST['product_id'] ?? 0);

        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product.', 'jezweb-dynamic-pricing')));
        }

        $wishlist = $this->get_native_wishlist();
        $wishlist[$product_id] = array(
            'date' => date('Y-m-d'),
            'price' => wc_get_product($product_id)->get_price(),
        );

        $this->save_native_wishlist($wishlist);

        wp_send_json_success(array(
            'message' => __('Added to wishlist!', 'jezweb-dynamic-pricing'),
            'wishlist_url' => $this->get_wishlist_url(),
        ));
    }

    /**
     * AJAX: Remove from wishlist.
     */
    public function ajax_remove_from_wishlist() {
        check_ajax_referer('jdpd_wishlist_nonce', 'nonce');

        $product_id = intval($_POST['product_id'] ?? 0);

        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product.', 'jezweb-dynamic-pricing')));
        }

        $wishlist = $this->get_native_wishlist();
        unset($wishlist[$product_id]);

        $this->save_native_wishlist($wishlist);

        wp_send_json_success(array(
            'message' => __('Removed from wishlist.', 'jezweb-dynamic-pricing'),
        ));
    }

    /**
     * AJAX: Get wishlist.
     */
    public function ajax_get_wishlist() {
        check_ajax_referer('jdpd_wishlist_nonce', 'nonce');

        $wishlist = $this->get_native_wishlist();
        $products = array();

        foreach ($wishlist as $product_id => $data) {
            $product = wc_get_product($product_id);
            if ($product) {
                $products[] = array(
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'price' => $product->get_price(),
                    'price_html' => $product->get_price_html(),
                    'image' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'),
                    'url' => $product->get_permalink(),
                    'added_date' => $data['date'],
                );
            }
        }

        wp_send_json_success(array('products' => $products));
    }

    /**
     * Render wishlist shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_wishlist_shortcode($atts) {
        $options = get_option('jdpd_wishlist_options', $this->get_default_options());
        $wishlist = $this->get_native_wishlist();

        ob_start();
        ?>
        <div class="jdpd-wishlist-page">
            <h2><?php esc_html_e('My Wishlist', 'jezweb-dynamic-pricing'); ?></h2>

            <?php if (empty($wishlist)): ?>
                <p><?php esc_html_e('Your wishlist is empty.', 'jezweb-dynamic-pricing'); ?></p>
                <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="button">
                    <?php esc_html_e('Continue Shopping', 'jezweb-dynamic-pricing'); ?>
                </a>
            <?php else: ?>
                <table class="shop_table shop_table_responsive jdpd-wishlist-table">
                    <thead>
                        <tr>
                            <th class="product-remove">&nbsp;</th>
                            <th class="product-thumbnail">&nbsp;</th>
                            <th class="product-name"><?php esc_html_e('Product', 'jezweb-dynamic-pricing'); ?></th>
                            <th class="product-price"><?php esc_html_e('Price', 'jezweb-dynamic-pricing'); ?></th>
                            <th class="product-discount"><?php esc_html_e('Your Discount', 'jezweb-dynamic-pricing'); ?></th>
                            <th class="product-add">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($wishlist as $product_id => $data):
                            $product = wc_get_product($product_id);
                            if (!$product) continue;

                            $discount = $this->calculate_wishlist_discount($product_id);
                        ?>
                            <tr data-product-id="<?php echo esc_attr($product_id); ?>">
                                <td class="product-remove">
                                    <a href="#" class="remove jdpd-remove-wishlist" data-product-id="<?php echo esc_attr($product_id); ?>">&times;</a>
                                </td>
                                <td class="product-thumbnail">
                                    <a href="<?php echo esc_url($product->get_permalink()); ?>">
                                        <?php echo $product->get_image('thumbnail'); ?>
                                    </a>
                                </td>
                                <td class="product-name">
                                    <a href="<?php echo esc_url($product->get_permalink()); ?>">
                                        <?php echo esc_html($product->get_name()); ?>
                                    </a>
                                </td>
                                <td class="product-price">
                                    <?php echo $product->get_price_html(); ?>
                                </td>
                                <td class="product-discount">
                                    <?php if ($discount > 0): ?>
                                        <span class="jdpd-wishlist-discount-badge">
                                            <?php echo esc_html($discount); ?>% <?php esc_html_e('off', 'jezweb-dynamic-pricing'); ?>
                                        </span>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td class="product-add">
                                    <a href="<?php echo esc_url($product->add_to_cart_url()); ?>" class="button add_to_cart_button">
                                        <?php esc_html_e('Add to Cart', 'jezweb-dynamic-pricing'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Calculate wishlist discount for product.
     *
     * @param int $product_id Product ID.
     * @param int $user_id    User ID (optional).
     * @return float Discount percentage.
     */
    public function calculate_wishlist_discount($product_id, $user_id = null) {
        $options = get_option('jdpd_wishlist_options', $this->get_default_options());

        if (empty($options['enabled'])) {
            return 0;
        }

        if (!$this->is_in_wishlist($product_id, $user_id)) {
            return 0;
        }

        $added_date = $this->get_wishlist_date($product_id, $user_id);

        if (!$added_date) {
            return 0;
        }

        if (empty($options['time_based'])) {
            // Return first tier discount if time-based is disabled
            $tiers = $options['tiers'] ?? array();
            return $tiers[0]['discount'] ?? 0;
        }

        $days_in_wishlist = floor((time() - strtotime($added_date)) / (60 * 60 * 24));

        $tiers = $options['tiers'] ?? array();
        $discount = 0;

        foreach ($tiers as $tier) {
            if ($days_in_wishlist >= $tier['days']) {
                $discount = $tier['discount'];
            }
        }

        // Apply max discount cap
        $max_discount = floatval($options['max_discount'] ?? 25);
        return min($discount, $max_discount);
    }

    /**
     * Apply wishlist discount to product price.
     *
     * @param float      $price   Price.
     * @param WC_Product $product Product.
     * @return float
     */
    public function apply_wishlist_discount($price, $product) {
        // Don't apply in admin
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }

        $options = get_option('jdpd_wishlist_options', $this->get_default_options());

        if (!empty($options['require_login']) && !is_user_logged_in()) {
            return $price;
        }

        $product_id = $product->get_id();
        $discount = $this->calculate_wishlist_discount($product_id);

        if ($discount <= 0) {
            return $price;
        }

        $discount_amount = ($price * $discount) / 100;

        return max(0, $price - $discount_amount);
    }

    /**
     * Display wishlist discount notice on product page.
     */
    public function display_wishlist_discount_notice() {
        global $product;

        if (!$product) {
            return;
        }

        $discount = $this->calculate_wishlist_discount($product->get_id());

        if ($discount > 0) {
            ?>
            <div class="jdpd-wishlist-discount-notice">
                <strong>
                    <?php printf(
                        esc_html__('You\'re getting %d%% off this item because it\'s on your wishlist!', 'jezweb-dynamic-pricing'),
                        $discount
                    ); ?>
                </strong>
            </div>
            <?php
        } elseif (!$this->is_in_wishlist($product->get_id()) && is_user_logged_in()) {
            $options = get_option('jdpd_wishlist_options', $this->get_default_options());
            $first_tier = $options['tiers'][0] ?? array('days' => 7, 'discount' => 5);
            ?>
            <div class="jdpd-wishlist-promo-notice">
                <?php printf(
                    esc_html__('Add to your wishlist and get up to %d%% off!', 'jezweb-dynamic-pricing'),
                    floatval($options['max_discount'] ?? 25)
                ); ?>
            </div>
            <?php
        }
    }

    /**
     * YITH: On add to wishlist.
     *
     * @param int    $product_id Product ID.
     * @param int    $wishlist_id Wishlist ID.
     * @param int    $user_id User ID.
     */
    public function on_yith_add_to_wishlist($product_id, $wishlist_id, $user_id) {
        $this->track_wishlist_add($product_id, $user_id);
    }

    /**
     * YITH: On remove from wishlist.
     *
     * @param int    $product_id Product ID.
     * @param int    $wishlist_id Wishlist ID.
     * @param int    $user_id User ID.
     */
    public function on_yith_remove_from_wishlist($product_id, $wishlist_id, $user_id) {
        $this->track_wishlist_remove($product_id, $user_id);
    }

    /**
     * TI: On add to wishlist.
     *
     * @param array $data Item data.
     * @param array $wishlist Wishlist data.
     */
    public function on_ti_add_to_wishlist($data, $wishlist) {
        if (isset($data['product_id'])) {
            $this->track_wishlist_add($data['product_id']);
        }
    }

    /**
     * TI: On remove from wishlist.
     *
     * @param int   $product_id Product ID.
     * @param array $wishlist Wishlist data.
     */
    public function on_ti_remove_from_wishlist($product_id, $wishlist) {
        $this->track_wishlist_remove($product_id);
    }

    /**
     * Track wishlist addition.
     *
     * @param int $product_id Product ID.
     * @param int $user_id    User ID (optional).
     */
    private function track_wishlist_add($product_id, $user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        // Track for analytics
        $stats = get_option('jdpd_wishlist_stats', array());
        $stats['total_adds'] = ($stats['total_adds'] ?? 0) + 1;

        if (!isset($stats['products'][$product_id])) {
            $stats['products'][$product_id] = array('adds' => 0, 'conversions' => 0);
        }
        $stats['products'][$product_id]['adds']++;

        update_option('jdpd_wishlist_stats', $stats);
    }

    /**
     * Track wishlist removal.
     *
     * @param int $product_id Product ID.
     * @param int $user_id    User ID (optional).
     */
    private function track_wishlist_remove($product_id, $user_id = null) {
        // Optional analytics tracking for removals
    }

    /**
     * Check for price drops on product update.
     *
     * @param int        $product_id Product ID.
     * @param WC_Product $product    Product object.
     */
    public function check_price_drop($product_id, $product) {
        $options = get_option('jdpd_wishlist_options', $this->get_default_options());

        if (empty($options['price_drop_emails'])) {
            return;
        }

        // Get previous price
        $previous_price = get_post_meta($product_id, '_jdpd_previous_price', true);
        $current_price = $product->get_price();

        if ($previous_price && floatval($current_price) < floatval($previous_price)) {
            // Price dropped - queue notifications
            $this->queue_price_drop_notification($product_id, $previous_price, $current_price);
        }

        // Update stored price
        update_post_meta($product_id, '_jdpd_previous_price', $current_price);
    }

    /**
     * Queue price drop notification.
     *
     * @param int   $product_id     Product ID.
     * @param float $previous_price Previous price.
     * @param float $current_price  Current price.
     */
    private function queue_price_drop_notification($product_id, $previous_price, $current_price) {
        $queue = get_option('jdpd_price_drop_queue', array());

        $queue[] = array(
            'product_id' => $product_id,
            'previous_price' => $previous_price,
            'current_price' => $current_price,
            'timestamp' => current_time('mysql'),
        );

        update_option('jdpd_price_drop_queue', $queue);
    }

    /**
     * Send price drop notifications (scheduled).
     */
    public function send_price_drop_notifications() {
        $queue = get_option('jdpd_price_drop_queue', array());

        if (empty($queue)) {
            return;
        }

        foreach ($queue as $index => $item) {
            $this->notify_wishlist_users_of_price_drop($item);
            unset($queue[$index]);
        }

        update_option('jdpd_price_drop_queue', array_values($queue));
    }

    /**
     * Notify users who have product on wishlist about price drop.
     *
     * @param array $item Price drop data.
     */
    private function notify_wishlist_users_of_price_drop($item) {
        $product = wc_get_product($item['product_id']);

        if (!$product) {
            return;
        }

        // Find users with this product on wishlist
        $users = $this->get_users_with_product_on_wishlist($item['product_id']);

        foreach ($users as $user_id) {
            $user = get_userdata($user_id);
            if (!$user) continue;

            $discount_percent = round((($item['previous_price'] - $item['current_price']) / $item['previous_price']) * 100);

            $subject = sprintf(
                __('Price Drop Alert: %s is now %s%% off!', 'jezweb-dynamic-pricing'),
                $product->get_name(),
                $discount_percent
            );

            $message = sprintf(
                __('Good news, %s!

An item on your wishlist just got a price drop!

%s
Was: %s
Now: %s
You save: %s%%

This is a great time to grab it before it sells out!

%s

%s', 'jezweb-dynamic-pricing'),
                $user->display_name,
                $product->get_name(),
                wc_price($item['previous_price']),
                wc_price($item['current_price']),
                $discount_percent,
                $product->get_permalink(),
                get_bloginfo('name')
            );

            wp_mail($user->user_email, $subject, $message);
        }
    }

    /**
     * Get users who have product on wishlist.
     *
     * @param int $product_id Product ID.
     * @return array User IDs.
     */
    private function get_users_with_product_on_wishlist($product_id) {
        global $wpdb;

        switch ($this->wishlist_plugin) {
            case 'yith':
                $table = $wpdb->prefix . 'yith_wcwl';
                return $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT user_id FROM $table WHERE prod_id = %d",
                    $product_id
                ));

            case 'ti':
                $table = $wpdb->prefix . 'tinvwl_items';
                return $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT author FROM $table WHERE product_id = %d",
                    $product_id
                ));

            default:
                // Native wishlist - search user meta
                return $wpdb->get_col($wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->usermeta}
                    WHERE meta_key = '_jdpd_wishlist'
                    AND meta_value LIKE %s",
                    '%"' . $product_id . '"%'
                ));
        }
    }

    /**
     * Send wishlist reminders (scheduled).
     */
    public function send_wishlist_reminders() {
        $options = get_option('jdpd_wishlist_options', $this->get_default_options());

        if (empty($options['reminder_emails'])) {
            return;
        }

        $reminder_days = intval($options['reminder_days'] ?? 7);
        $target_date = date('Y-m-d', strtotime("-$reminder_days days"));

        // Get users with items added on target date
        $this->send_reminder_emails_for_date($target_date);
    }

    /**
     * Send reminder emails for specific date.
     *
     * @param string $date Date in Y-m-d format.
     */
    private function send_reminder_emails_for_date($date) {
        global $wpdb;

        switch ($this->wishlist_plugin) {
            case 'yith':
                $table = $wpdb->prefix . 'yith_wcwl';
                $users = $wpdb->get_results($wpdb->prepare(
                    "SELECT user_id, prod_id FROM $table WHERE DATE(dateadded) = %s",
                    $date
                ));
                break;

            case 'ti':
                $table = $wpdb->prefix . 'tinvwl_items';
                $users = $wpdb->get_results($wpdb->prepare(
                    "SELECT author as user_id, product_id as prod_id FROM $table WHERE DATE(date) = %s",
                    $date
                ));
                break;

            default:
                // Native - handled differently
                $users = array();
                $all_wishlists = $wpdb->get_results(
                    "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_jdpd_wishlist'"
                );

                foreach ($all_wishlists as $row) {
                    $wishlist = maybe_unserialize($row->meta_value);
                    foreach ($wishlist as $product_id => $data) {
                        if (($data['date'] ?? '') === $date) {
                            $users[] = (object) array(
                                'user_id' => $row->user_id,
                                'prod_id' => $product_id,
                            );
                        }
                    }
                }
        }

        // Group by user
        $user_products = array();
        foreach ($users as $row) {
            $user_products[$row->user_id][] = $row->prod_id;
        }

        foreach ($user_products as $user_id => $products) {
            $this->send_wishlist_reminder_email($user_id, $products);
        }
    }

    /**
     * Send wishlist reminder email to user.
     *
     * @param int   $user_id  User ID.
     * @param array $products Product IDs.
     */
    private function send_wishlist_reminder_email($user_id, $products) {
        $user = get_userdata($user_id);
        if (!$user) return;

        // Check if already sent recently
        $last_sent = get_user_meta($user_id, '_jdpd_wishlist_reminder_sent', true);
        if ($last_sent && strtotime($last_sent) > strtotime('-7 days')) {
            return;
        }

        $product_list = '';
        foreach ($products as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $discount = $this->calculate_wishlist_discount($product_id, $user_id);
                $product_list .= sprintf(
                    "- %s (%s)%s\n",
                    $product->get_name(),
                    $product->get_price_html(),
                    $discount > 0 ? " - {$discount}% off for you!" : ''
                );
            }
        }

        $subject = __('Don\'t forget about your wishlist items!', 'jezweb-dynamic-pricing');

        $message = sprintf(
            __('Hi %s,

We noticed you added some items to your wishlist a while back. They\'re still waiting for you!

%s

Visit your wishlist to grab them before they\'re gone:
%s

%s', 'jezweb-dynamic-pricing'),
            $user->display_name,
            $product_list,
            $this->get_wishlist_url(),
            get_bloginfo('name')
        );

        wp_mail($user->user_email, $subject, $message);

        update_user_meta($user_id, '_jdpd_wishlist_reminder_sent', current_time('mysql'));
    }

    /**
     * Render statistics.
     */
    private function render_statistics() {
        $stats = get_option('jdpd_wishlist_stats', array());
        ?>
        <table class="widefat" style="max-width: 600px;">
            <tr>
                <td><?php esc_html_e('Total Wishlist Additions', 'jezweb-dynamic-pricing'); ?></td>
                <td><strong><?php echo esc_html($stats['total_adds'] ?? 0); ?></strong></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Wishlist Conversions', 'jezweb-dynamic-pricing'); ?></td>
                <td><strong><?php echo esc_html($stats['conversions'] ?? 0); ?></strong></td>
            </tr>
        </table>

        <h3><?php esc_html_e('Most Wishlisted Products', 'jezweb-dynamic-pricing'); ?></h3>
        <?php if (empty($stats['products'])): ?>
            <p><?php esc_html_e('No data yet.', 'jezweb-dynamic-pricing'); ?></p>
        <?php else:
            // Sort by adds
            uasort($stats['products'], function($a, $b) {
                return ($b['adds'] ?? 0) - ($a['adds'] ?? 0);
            });

            $top_products = array_slice($stats['products'], 0, 10, true);
        ?>
            <table class="wp-list-table widefat fixed striped" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Product', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('Wishlist Adds', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('Conversions', 'jezweb-dynamic-pricing'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_products as $product_id => $data):
                        $product = wc_get_product($product_id);
                        if (!$product) continue;
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>">
                                    <?php echo esc_html($product->get_name()); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($data['adds'] ?? 0); ?></td>
                            <td><?php echo esc_html($data['conversions'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    /**
     * AJAX: Get stats.
     */
    public function ajax_get_stats() {
        check_ajax_referer('jdpd_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'jezweb-dynamic-pricing')));
        }

        $stats = get_option('jdpd_wishlist_stats', array());
        wp_send_json_success($stats);
    }

    /**
     * Schedule events.
     */
    public static function schedule_events() {
        if (!wp_next_scheduled('jdpd_wishlist_reminder')) {
            wp_schedule_event(strtotime('09:00:00'), 'daily', 'jdpd_wishlist_reminder');
        }

        if (!wp_next_scheduled('jdpd_price_drop_notifications')) {
            wp_schedule_event(time(), 'hourly', 'jdpd_price_drop_notifications');
        }
    }

    /**
     * Clear scheduled events.
     */
    public static function clear_events() {
        wp_clear_scheduled_hook('jdpd_wishlist_reminder');
        wp_clear_scheduled_hook('jdpd_price_drop_notifications');
    }
}

// Initialize the class
JDPD_Wishlist_Pricing::get_instance();
