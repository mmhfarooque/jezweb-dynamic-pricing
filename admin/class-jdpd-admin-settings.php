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
        );

        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_jdpd_save_settings', array( $this, 'ajax_save_settings' ) );
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
        return isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
    }

    /**
     * Render settings tabs
     */
    public function render_tabs() {
        $current_tab = $this->get_current_tab();
        ?>
        <nav class="nav-tab-wrapper woo-nav-tab-wrapper">
            <?php foreach ( $this->tabs as $tab_key => $tab_label ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=jdpd-settings&tab=' . $tab_key ) ); ?>"
                   class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html( $tab_label ); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Render settings fields
     */
    public function render_settings_fields() {
        $current_tab = $this->get_current_tab();

        switch ( $current_tab ) {
            case 'general':
                $this->render_general_settings();
                break;
            case 'display':
                $this->render_display_settings();
                break;
            case 'cart':
                $this->render_cart_settings();
                break;
            case 'checkout':
                $this->render_checkout_settings();
                break;
            case 'advanced':
                $this->render_advanced_settings();
                break;
        }
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
}
