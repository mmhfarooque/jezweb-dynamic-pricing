<?php
/**
 * Admin Settings
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin Settings class
 */
class JDPD_Admin_Settings {

    /**
     * Settings tabs
     *
     * @var array
     */
    private $tabs = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->tabs = array(
            'general'  => __( 'General', 'jezweb-dynamic-pricing' ),
            'display'  => __( 'Display', 'jezweb-dynamic-pricing' ),
            'cart'     => __( 'Cart', 'jezweb-dynamic-pricing' ),
            'checkout' => __( 'Checkout Deals', 'jezweb-dynamic-pricing' ),
            'advanced' => __( 'Advanced', 'jezweb-dynamic-pricing' ),
            'debug'    => __( 'Debug Log', 'jezweb-dynamic-pricing' ),
        );

        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_debug_actions' ) );
        add_action( 'wp_ajax_jdpd_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_jdpd_clear_log', array( $this, 'ajax_clear_log' ) );
        add_action( 'wp_ajax_jdpd_download_log', array( $this, 'ajax_download_log' ) );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // General settings
        register_setting( 'jdpd_general_settings', 'jdpd_enable_plugin' );
        register_setting( 'jdpd_general_settings', 'jdpd_apply_to_sale_products' );
        register_setting( 'jdpd_general_settings', 'jdpd_show_original_price' );
        register_setting( 'jdpd_general_settings', 'jdpd_show_you_save' );
        register_setting( 'jdpd_general_settings', 'jdpd_shop_manager_access' );

        // Display settings
        register_setting( 'jdpd_display_settings', 'jdpd_show_quantity_table' );
        register_setting( 'jdpd_display_settings', 'jdpd_quantity_table_layout' );
        register_setting( 'jdpd_display_settings', 'jdpd_quantity_table_position' );
        register_setting( 'jdpd_display_settings', 'jdpd_show_product_notices' );
        register_setting( 'jdpd_display_settings', 'jdpd_notice_style' );
        register_setting( 'jdpd_display_settings', 'jdpd_badge_style' );
        register_setting( 'jdpd_display_settings', 'jdpd_badge_text' );

        // Cart settings
        register_setting( 'jdpd_cart_settings', 'jdpd_show_cart_discount_label' );
        register_setting( 'jdpd_cart_settings', 'jdpd_cart_discount_label' );
        register_setting( 'jdpd_cart_settings', 'jdpd_show_cart_notices' );
        register_setting( 'jdpd_cart_settings', 'jdpd_show_cart_savings' );

        // Checkout settings
        register_setting( 'jdpd_checkout_settings', 'jdpd_enable_checkout_deals' );
        register_setting( 'jdpd_checkout_settings', 'jdpd_checkout_countdown' );
        register_setting( 'jdpd_checkout_settings', 'jdpd_checkout_countdown_time' );

        // Advanced settings
        register_setting( 'jdpd_advanced_settings', 'jdpd_show_in_order_email' );
        register_setting( 'jdpd_advanced_settings', 'jdpd_show_order_metabox' );

        // Debug settings
        register_setting( 'jdpd_debug_settings', 'jdpd_enable_debug_log' );
    }

    /**
     * Get settings tabs
     *
     * @return array
     */
    public function get_tabs() {
        return $this->tabs;
    }

    /**
     * Get current tab
     *
     * @return string
     */
    public function get_current_tab() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
        // Only allow 'settings' or 'debug' tabs now
        return in_array( $tab, array( 'settings', 'debug' ), true ) ? $tab : 'settings';
    }

    /**
     * Render settings tabs (kept for backward compatibility)
     */
    public function render_tabs() {
        // Tabs are now rendered directly in the view
    }

    /**
     * Render settings fields (kept for backward compatibility)
     */
    public function render_settings_fields() {
        // Settings are now rendered directly in the view as sections
    }

    /**
     * Render general settings
     */
    private function render_general_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="jdpd_enable_plugin"><?php esc_html_e( 'Enable Plugin', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_enable_plugin" id="jdpd_enable_plugin" value="yes"
                        <?php checked( get_option( 'jdpd_enable_plugin', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Enable or disable all dynamic pricing rules.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_apply_to_sale_products"><?php esc_html_e( 'Apply to Sale Products', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_apply_to_sale_products" id="jdpd_apply_to_sale_products" value="yes"
                        <?php checked( get_option( 'jdpd_apply_to_sale_products', 'no' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Apply discount rules to products that are already on sale.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_show_original_price"><?php esc_html_e( 'Show Original Price', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_show_original_price" id="jdpd_show_original_price" value="yes"
                        <?php checked( get_option( 'jdpd_show_original_price', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Show crossed-out original price when discount is applied.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_show_you_save"><?php esc_html_e( 'Show "You Save"', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_show_you_save" id="jdpd_show_you_save" value="yes"
                        <?php checked( get_option( 'jdpd_show_you_save', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Show the amount the customer saves when discount is applied.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_shop_manager_access"><?php esc_html_e( 'Shop Manager Access', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_shop_manager_access" id="jdpd_shop_manager_access" value="yes"
                        <?php checked( get_option( 'jdpd_shop_manager_access', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Allow shop managers to create and manage discount rules.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render display settings
     */
    private function render_display_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="jdpd_show_quantity_table"><?php esc_html_e( 'Show Quantity Table', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_show_quantity_table" id="jdpd_show_quantity_table" value="yes"
                        <?php checked( get_option( 'jdpd_show_quantity_table', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Display quantity discount table on product pages.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_quantity_table_layout"><?php esc_html_e( 'Table Layout', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <select name="jdpd_quantity_table_layout" id="jdpd_quantity_table_layout">
                        <option value="horizontal" <?php selected( get_option( 'jdpd_quantity_table_layout', 'horizontal' ), 'horizontal' ); ?>>
                            <?php esc_html_e( 'Horizontal', 'jezweb-dynamic-pricing' ); ?>
                        </option>
                        <option value="vertical" <?php selected( get_option( 'jdpd_quantity_table_layout', 'horizontal' ), 'vertical' ); ?>>
                            <?php esc_html_e( 'Vertical', 'jezweb-dynamic-pricing' ); ?>
                        </option>
                    </select>
                    <p class="description"><?php esc_html_e( 'Choose the layout for the quantity discount table.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_quantity_table_position"><?php esc_html_e( 'Table Position', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <select name="jdpd_quantity_table_position" id="jdpd_quantity_table_position">
                        <option value="before_add_to_cart" <?php selected( get_option( 'jdpd_quantity_table_position', 'after_add_to_cart' ), 'before_add_to_cart' ); ?>>
                            <?php esc_html_e( 'Before Add to Cart', 'jezweb-dynamic-pricing' ); ?>
                        </option>
                        <option value="after_add_to_cart" <?php selected( get_option( 'jdpd_quantity_table_position', 'after_add_to_cart' ), 'after_add_to_cart' ); ?>>
                            <?php esc_html_e( 'After Add to Cart', 'jezweb-dynamic-pricing' ); ?>
                        </option>
                        <option value="after_summary" <?php selected( get_option( 'jdpd_quantity_table_position', 'after_add_to_cart' ), 'after_summary' ); ?>>
                            <?php esc_html_e( 'After Product Summary', 'jezweb-dynamic-pricing' ); ?>
                        </option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_show_product_notices"><?php esc_html_e( 'Show Product Notices', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_show_product_notices" id="jdpd_show_product_notices" value="yes"
                        <?php checked( get_option( 'jdpd_show_product_notices', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Display discount notices on product pages.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_notice_style"><?php esc_html_e( 'Notice Style', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <select name="jdpd_notice_style" id="jdpd_notice_style">
                        <option value="default" <?php selected( get_option( 'jdpd_notice_style', 'default' ), 'default' ); ?>>
                            <?php esc_html_e( 'Default', 'jezweb-dynamic-pricing' ); ?>
                        </option>
                        <option value="info" <?php selected( get_option( 'jdpd_notice_style', 'default' ), 'info' ); ?>>
                            <?php esc_html_e( 'Info', 'jezweb-dynamic-pricing' ); ?>
                        </option>
                        <option value="success" <?php selected( get_option( 'jdpd_notice_style', 'default' ), 'success' ); ?>>
                            <?php esc_html_e( 'Success', 'jezweb-dynamic-pricing' ); ?>
                        </option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_badge_style"><?php esc_html_e( 'Badge Style', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <select name="jdpd_badge_style" id="jdpd_badge_style">
                        <option value="default" <?php selected( get_option( 'jdpd_badge_style', 'default' ), 'default' ); ?>>
                            <?php esc_html_e( 'Default', 'jezweb-dynamic-pricing' ); ?>
                        </option>
                        <option value="rounded" <?php selected( get_option( 'jdpd_badge_style', 'default' ), 'rounded' ); ?>>
                            <?php esc_html_e( 'Rounded', 'jezweb-dynamic-pricing' ); ?>
                        </option>
                        <option value="ribbon" <?php selected( get_option( 'jdpd_badge_style', 'default' ), 'ribbon' ); ?>>
                            <?php esc_html_e( 'Ribbon', 'jezweb-dynamic-pricing' ); ?>
                        </option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_badge_text"><?php esc_html_e( 'Badge Text', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="text" name="jdpd_badge_text" id="jdpd_badge_text"
                           value="<?php echo esc_attr( get_option( 'jdpd_badge_text', __( 'Sale', 'jezweb-dynamic-pricing' ) ) ); ?>"
                           class="regular-text">
                    <p class="description"><?php esc_html_e( 'Text to display on the sale badge. Use {discount} for discount percentage.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render cart settings
     */
    private function render_cart_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="jdpd_show_cart_discount_label"><?php esc_html_e( 'Show Discount Label', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_show_cart_discount_label" id="jdpd_show_cart_discount_label" value="yes"
                        <?php checked( get_option( 'jdpd_show_cart_discount_label', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Show discount label in cart.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_cart_discount_label"><?php esc_html_e( 'Discount Label', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="text" name="jdpd_cart_discount_label" id="jdpd_cart_discount_label"
                           value="<?php echo esc_attr( get_option( 'jdpd_cart_discount_label', __( 'Discount', 'jezweb-dynamic-pricing' ) ) ); ?>"
                           class="regular-text">
                    <p class="description"><?php esc_html_e( 'Label shown for discounts in cart.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_show_cart_notices"><?php esc_html_e( 'Show Cart Notices', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_show_cart_notices" id="jdpd_show_cart_notices" value="yes"
                        <?php checked( get_option( 'jdpd_show_cart_notices', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Display notices about available discounts in cart.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_show_cart_savings"><?php esc_html_e( 'Show Total Savings', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_show_cart_savings" id="jdpd_show_cart_savings" value="yes"
                        <?php checked( get_option( 'jdpd_show_cart_savings', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Display total savings in cart and checkout.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render checkout settings
     */
    private function render_checkout_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="jdpd_enable_checkout_deals"><?php esc_html_e( 'Enable Checkout Deals', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_enable_checkout_deals" id="jdpd_enable_checkout_deals" value="yes"
                        <?php checked( get_option( 'jdpd_enable_checkout_deals', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Show special deal offers at checkout.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_checkout_countdown"><?php esc_html_e( 'Show Countdown Timer', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_checkout_countdown" id="jdpd_checkout_countdown" value="yes"
                        <?php checked( get_option( 'jdpd_checkout_countdown', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Display countdown timer for limited-time checkout deals.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_checkout_countdown_time"><?php esc_html_e( 'Countdown Duration', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="number" name="jdpd_checkout_countdown_time" id="jdpd_checkout_countdown_time"
                           value="<?php echo esc_attr( get_option( 'jdpd_checkout_countdown_time', 300 ) ); ?>"
                           min="60" max="3600" class="small-text">
                    <span class="description"><?php esc_html_e( 'seconds', 'jezweb-dynamic-pricing' ); ?></span>
                    <p class="description"><?php esc_html_e( 'Duration of checkout deal countdown timer.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render advanced settings
     */
    private function render_advanced_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="jdpd_show_in_order_email"><?php esc_html_e( 'Show in Order Emails', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_show_in_order_email" id="jdpd_show_in_order_email" value="yes"
                        <?php checked( get_option( 'jdpd_show_in_order_email', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Include discount information in order emails.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jdpd_show_order_metabox"><?php esc_html_e( 'Show Order Metabox', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_show_order_metabox" id="jdpd_show_order_metabox" value="yes"
                        <?php checked( get_option( 'jdpd_show_order_metabox', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Display applied discount rules in order admin page.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * AJAX save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $tab = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'general';
        $settings = isset( $_POST['settings'] ) ? $_POST['settings'] : array();

        foreach ( $settings as $key => $value ) {
            if ( strpos( $key, 'jdpd_' ) === 0 ) {
                update_option( sanitize_key( $key ), sanitize_text_field( $value ) );
            }
        }

        wp_send_json_success( array( 'message' => __( 'Settings saved.', 'jezweb-dynamic-pricing' ) ) );
    }

    /**
     * Render debug settings
     */
    public function render_debug_settings() {
        $logger = function_exists( 'jdpd_logger' ) ? jdpd_logger() : null;
        $error_handler = function_exists( 'jdpd_error_handler' ) ? jdpd_error_handler() : null;
        $log_contents = $logger ? $logger->get_log_contents( 200 ) : '';
        $is_disabled = $error_handler ? $error_handler->is_disabled() : false;
        ?>
        <?php if ( $is_disabled ) : ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e( 'Plugin is currently disabled due to critical errors.', 'jezweb-dynamic-pricing' ); ?></strong>
                </p>
            </div>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="jdpd_enable_debug_log"><?php esc_html_e( 'Enable Debug Logging', 'jezweb-dynamic-pricing' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="jdpd_enable_debug_log" id="jdpd_enable_debug_log" value="yes"
                        <?php checked( get_option( 'jdpd_enable_debug_log', 'yes' ), 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Enable detailed debug logging. Errors and critical issues are always logged regardless of this setting.', 'jezweb-dynamic-pricing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <?php esc_html_e( 'Log File Location', 'jezweb-dynamic-pricing' ); ?>
                </th>
                <td>
                    <code><?php echo $logger ? esc_html( $logger->get_log_file() ) : esc_html__( 'Not available', 'jezweb-dynamic-pricing' ); ?></code>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <?php esc_html_e( 'Actions', 'jezweb-dynamic-pricing' ); ?>
                </th>
                <td>
                    <p>
                        <?php
                        $clear_url = wp_nonce_url(
                            admin_url( 'admin.php?page=jdpd-settings&tab=debug&action=clear_log' ),
                            'jdpd_clear_log'
                        );
                        $download_url = wp_nonce_url(
                            admin_url( 'admin.php?page=jdpd-settings&tab=debug&action=download_log' ),
                            'jdpd_download_log'
                        );
                        $reset_url = wp_nonce_url(
                            admin_url( 'admin.php?page=jdpd-settings&tab=debug&action=reset_errors' ),
                            'jdpd_reset_errors'
                        );
                        ?>
                        <a href="<?php echo esc_url( $download_url ); ?>" class="button">
                            <?php esc_html_e( 'Download Log', 'jezweb-dynamic-pricing' ); ?>
                        </a>
                        <a href="<?php echo esc_url( $clear_url ); ?>" class="button" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to clear the log file?', 'jezweb-dynamic-pricing' ); ?>');">
                            <?php esc_html_e( 'Clear Log', 'jezweb-dynamic-pricing' ); ?>
                        </a>
                        <?php if ( $is_disabled ) : ?>
                            <a href="<?php echo esc_url( $reset_url ); ?>" class="button button-primary">
                                <?php esc_html_e( 'Reset Errors & Re-enable Plugin', 'jezweb-dynamic-pricing' ); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Debug Log', 'jezweb-dynamic-pricing' ); ?></h3>
        <p class="description"><?php esc_html_e( 'Showing the last 200 lines of the debug log:', 'jezweb-dynamic-pricing' ); ?></p>
        <textarea readonly class="large-text code" rows="20" style="font-family: monospace; white-space: pre; overflow-x: auto;"><?php echo esc_textarea( $log_contents ); ?></textarea>

        <h3><?php esc_html_e( 'System Information', 'jezweb-dynamic-pricing' ); ?></h3>
        <table class="widefat" style="max-width: 600px;">
            <tbody>
                <tr>
                    <td><strong><?php esc_html_e( 'Plugin Version', 'jezweb-dynamic-pricing' ); ?></strong></td>
                    <td><?php echo esc_html( defined( 'JDPD_VERSION' ) ? JDPD_VERSION : 'Unknown' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'WordPress Version', 'jezweb-dynamic-pricing' ); ?></strong></td>
                    <td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'WooCommerce Version', 'jezweb-dynamic-pricing' ); ?></strong></td>
                    <td><?php echo esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : 'Not installed' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'PHP Version', 'jezweb-dynamic-pricing' ); ?></strong></td>
                    <td><?php echo esc_html( PHP_VERSION ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Debug Mode', 'jezweb-dynamic-pricing' ); ?></strong></td>
                    <td><?php echo $logger && $logger->is_debug_enabled() ? esc_html__( 'Enabled', 'jezweb-dynamic-pricing' ) : esc_html__( 'Disabled', 'jezweb-dynamic-pricing' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Plugin Status', 'jezweb-dynamic-pricing' ); ?></strong></td>
                    <td><?php echo $is_disabled ? '<span style="color: #d63638;">' . esc_html__( 'Disabled (Critical Errors)', 'jezweb-dynamic-pricing' ) . '</span>' : '<span style="color: #00a32a;">' . esc_html__( 'Active', 'jezweb-dynamic-pricing' ) . '</span>'; ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Handle debug actions
     */
    public function handle_debug_actions() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'jdpd-settings' ) {
            return;
        }

        if ( ! isset( $_GET['action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $action = sanitize_key( $_GET['action'] );

        switch ( $action ) {
            case 'clear_log':
                if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'jdpd_clear_log' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'jezweb-dynamic-pricing' ) );
                }
                if ( function_exists( 'jdpd_logger' ) ) {
                    jdpd_logger()->clear_log();
                }
                wp_redirect( admin_url( 'admin.php?page=jdpd-settings&tab=debug&message=log_cleared' ) );
                exit;

            case 'download_log':
                if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'jdpd_download_log' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'jezweb-dynamic-pricing' ) );
                }
                $this->download_log_file();
                exit;

            case 'reset_errors':
                if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'jdpd_reset_errors' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'jezweb-dynamic-pricing' ) );
                }
                if ( function_exists( 'jdpd_error_handler' ) ) {
                    jdpd_error_handler()->reset_critical_errors();
                }
                wp_redirect( admin_url( 'admin.php?page=jdpd-settings&tab=debug&message=errors_reset' ) );
                exit;
        }
    }

    /**
     * Download log file
     */
    private function download_log_file() {
        if ( ! function_exists( 'jdpd_logger' ) ) {
            wp_die( esc_html__( 'Logger not available.', 'jezweb-dynamic-pricing' ) );
        }

        $logger = jdpd_logger();
        $log_file = $logger->get_log_file();

        if ( ! file_exists( $log_file ) ) {
            wp_die( esc_html__( 'Log file not found.', 'jezweb-dynamic-pricing' ) );
        }

        $filename = 'jdpd-debug-log-' . date( 'Y-m-d-His' ) . '.log';

        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $log_file ) );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        readfile( $log_file );
        exit;
    }

    /**
     * AJAX clear log
     */
    public function ajax_clear_log() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        if ( function_exists( 'jdpd_logger' ) ) {
            jdpd_logger()->clear_log();
            wp_send_json_success( array( 'message' => __( 'Log cleared.', 'jezweb-dynamic-pricing' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Logger not available.', 'jezweb-dynamic-pricing' ) ) );
        }
    }

    /**
     * AJAX download log
     */
    public function ajax_download_log() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        if ( function_exists( 'jdpd_logger' ) ) {
            $log_contents = jdpd_logger()->get_log_contents( 1000 );
            wp_send_json_success( array(
                'content' => $log_contents,
                'filename' => 'jdpd-debug-log-' . date( 'Y-m-d-His' ) . '.log',
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Logger not available.', 'jezweb-dynamic-pricing' ) ) );
        }
    }
}
