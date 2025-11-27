<?php
/**
 * Plugin Name: Jezweb Dynamic Pricing & Discounts for WooCommerce
 * Plugin URI: https://github.com/mmhfarooque/jezweb-dynamic-pricing
 * Description: Powerful dynamic pricing and discount rules for WooCommerce. Create quantity discounts, cart rules, BOGO offers, gift products, and special promotions.
 * Version: 1.0.7
 * Author: Mahmmud Farooque
 * Author URI: https://jezweb.com.au
 * Text Domain: jezweb-dynamic-pricing
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * GitHub Plugin URI: https://github.com/mmhfarooque/jezweb-dynamic-pricing
 * GitHub Branch: main
 *
 * @package Jezweb_Dynamic_Pricing
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Define plugin constants
 */
define( 'JDPD_VERSION', '1.0.7' );
define( 'JDPD_PLUGIN_FILE', __FILE__ );
define( 'JDPD_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'JDPD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JDPD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'JDPD_DB_VERSION', '1.0.0' );

/**
 * Initialize logger and error handler
 * Note: Logger initializes on 'plugins_loaded' hook to ensure WP functions are available
 */
require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-logger.php';
require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-error-handler.php';

// Create logger instance (defers initialization until plugins_loaded)
jdpd_logger();

/**
 * Main plugin class
 */
final class Jezweb_Dynamic_Pricing {

    /**
     * Single instance of the class
     *
     * @var Jezweb_Dynamic_Pricing
     */
    private static $instance = null;

    /**
     * Price rules instance
     *
     * @var JDPD_Price_Rules
     */
    public $price_rules;

    /**
     * Cart rules instance
     *
     * @var JDPD_Cart_Rules
     */
    public $cart_rules;

    /**
     * Gift products instance
     *
     * @var JDPD_Gift_Products
     */
    public $gift_products;

    /**
     * Conditions instance
     *
     * @var JDPD_Conditions
     */
    public $conditions;

    /**
     * Main instance
     *
     * @return Jezweb_Dynamic_Pricing
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->check_requirements();
    }

    /**
     * Check plugin requirements
     */
    private function check_requirements() {
        add_action( 'plugins_loaded', array( $this, 'init' ), 11 );
        register_activation_hook( JDPD_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( JDPD_PLUGIN_FILE, array( $this, 'deactivate' ) );
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Allow reset via URL parameter (admin only)
        if ( is_admin() && isset( $_GET['jdpd_reset_errors'] ) && current_user_can( 'manage_options' ) ) {
            // Use the proper reset method which also clears the disabled flag
            jdpd_error_handler()->reset_critical_errors();
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-success"><p>Jezweb Dynamic Pricing errors have been reset. The plugin is now active.</p></div>';
            });
        }

        // Check if plugin is disabled due to critical errors
        if ( jdpd_error_handler()->is_disabled() ) {
            jdpd_log( 'Plugin disabled due to critical errors', 'warning' );
            // Still show admin notice with reset link
            add_action( 'admin_notices', array( $this, 'show_disabled_notice' ) );
            return;
        }

        jdpd_log( 'Plugin init started', 'debug' );

        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            jdpd_log( 'WooCommerce not active', 'warning' );
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        // Check PHP version
        if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
            jdpd_log( 'PHP version too low: ' . PHP_VERSION, 'warning' );
            add_action( 'admin_notices', array( $this, 'php_version_notice' ) );
            return;
        }

        // Load textdomain
        $this->load_textdomain();

        // Include required files
        $this->includes();

        // Initialize components with error handling
        jdpd_safe_execute(
            array( $this, 'init_hooks' ),
            null,
            'Initialize plugin hooks'
        );

        jdpd_log( 'Plugin init completed', 'info' );
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'jezweb-dynamic-pricing',
            false,
            dirname( JDPD_PLUGIN_BASENAME ) . '/languages/'
        );
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core includes
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-autoloader.php';
        require_once JDPD_PLUGIN_PATH . 'includes/jdpd-functions.php';
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-install.php';
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-rule.php';
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-price-rules.php';
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-cart-rules.php';
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-gift-products.php';
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-special-offers.php';
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-conditions.php';
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-discount-calculator.php';
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-schedule.php';
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-exclusions.php';

        // Admin includes
        if ( is_admin() ) {
            require_once JDPD_PLUGIN_PATH . 'admin/class-jdpd-admin.php';
            require_once JDPD_PLUGIN_PATH . 'admin/class-jdpd-admin-rules.php';
            require_once JDPD_PLUGIN_PATH . 'admin/class-jdpd-admin-settings.php';
            require_once JDPD_PLUGIN_PATH . 'admin/class-jdpd-admin-order-meta.php';
        }

        // Frontend includes
        require_once JDPD_PLUGIN_PATH . 'public/class-jdpd-frontend.php';
        require_once JDPD_PLUGIN_PATH . 'public/class-jdpd-quantity-table.php';
        require_once JDPD_PLUGIN_PATH . 'public/class-jdpd-notices.php';
        require_once JDPD_PLUGIN_PATH . 'public/class-jdpd-checkout-deals.php';

        // Auto-updater
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-github-updater.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Initialize components
        $this->price_rules = new JDPD_Price_Rules();
        $this->cart_rules = new JDPD_Cart_Rules();
        $this->gift_products = new JDPD_Gift_Products();
        $this->conditions = new JDPD_Conditions();

        // Initialize admin
        if ( is_admin() ) {
            new JDPD_Admin();
            new JDPD_Admin_Rules();
            new JDPD_Admin_Settings();
            new JDPD_Admin_Order_Meta();
        }

        // Initialize frontend
        new JDPD_Frontend();
        new JDPD_Quantity_Table();
        new JDPD_Notices();
        new JDPD_Checkout_Deals();

        // Initialize special offers
        new JDPD_Special_Offers();

        // Initialize scheduler
        new JDPD_Schedule();

        // Initialize exclusions
        new JDPD_Exclusions();

        // Initialize discount calculator
        new JDPD_Discount_Calculator();

        // Initialize GitHub updater
        new JDPD_GitHub_Updater();

        // Declare HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

        // Add settings link
        add_filter( 'plugin_action_links_' . JDPD_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                JDPD_PLUGIN_FILE,
                true
            );
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        require_once JDPD_PLUGIN_PATH . 'includes/class-jdpd-install.php';
        JDPD_Install::activate();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up scheduled events if any
        wp_clear_scheduled_hook( 'jdpd_daily_cleanup' );
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p>
                <?php
                printf(
                    /* translators: %s: WooCommerce link */
                    esc_html__( 'Jezweb Dynamic Pricing & Discounts requires %s to be installed and active.', 'jezweb-dynamic-pricing' ),
                    '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * PHP version notice
     */
    public function php_version_notice() {
        ?>
        <div class="error">
            <p>
                <?php
                printf(
                    /* translators: %s: PHP version */
                    esc_html__( 'Jezweb Dynamic Pricing & Discounts requires PHP %s or higher.', 'jezweb-dynamic-pricing' ),
                    '8.0'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Show disabled notice with reset link
     */
    public function show_disabled_notice() {
        $reset_url = admin_url( 'plugins.php?jdpd_reset_errors=1' );
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'Jezweb Dynamic Pricing & Discounts', 'jezweb-dynamic-pricing' ); ?>:</strong>
                <?php esc_html_e( 'Plugin is temporarily disabled due to previous critical errors.', 'jezweb-dynamic-pricing' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( $reset_url ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Reset Errors & Enable Plugin', 'jezweb-dynamic-pricing' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Add plugin action links
     *
     * @param array $links Plugin action links.
     * @return array
     */
    public function plugin_action_links( $links ) {
        $plugin_links = array(
            '<a href="' . admin_url( 'admin.php?page=jdpd-settings' ) . '">' . esc_html__( 'Settings', 'jezweb-dynamic-pricing' ) . '</a>',
            '<a href="' . admin_url( 'admin.php?page=jdpd-rules' ) . '">' . esc_html__( 'Rules', 'jezweb-dynamic-pricing' ) . '</a>',
        );
        return array_merge( $plugin_links, $links );
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize singleton' );
    }
}

/**
 * Main instance of Jezweb_Dynamic_Pricing
 *
 * @return Jezweb_Dynamic_Pricing
 */
function JDPD() {
    return Jezweb_Dynamic_Pricing::instance();
}

// Initialize plugin
JDPD();
