<?php
/**
 * Admin Settings View - Modern Single Page Layout
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = new JDPD_Admin_Settings();
$current_tab = $settings->get_current_tab();

// Handle form submission - verify nonce AND capability
if ( isset( $_POST['jdpd_save_settings'] ) && wp_verify_nonce( $_POST['jdpd_settings_nonce'], 'jdpd_save_settings' ) ) {
    // Security: Verify user has permission to manage WooCommerce settings
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'jezweb-dynamic-pricing' ) );
    }

    // Get all checkbox fields
    $checkbox_fields = array(
        'jdpd_enable_plugin',
        'jdpd_apply_to_sale_products',
        'jdpd_show_original_price',
        'jdpd_show_you_save',
        'jdpd_shop_manager_access',
        'jdpd_show_quantity_table',
        'jdpd_show_product_notices',
        'jdpd_show_cart_discount_label',
        'jdpd_show_cart_notices',
        'jdpd_show_cart_savings',
        'jdpd_enable_checkout_deals',
        'jdpd_checkout_countdown',
        'jdpd_show_in_order_email',
        'jdpd_show_order_metabox',
        'jdpd_enable_debug_log',
    );

    foreach ( $_POST as $key => $value ) {
        if ( strpos( $key, 'jdpd_' ) === 0 && $key !== 'jdpd_save_settings' && $key !== 'jdpd_settings_nonce' ) {
            update_option( sanitize_key( $key ), sanitize_text_field( $value ) );
        }
    }

    // Handle unchecked checkboxes
    foreach ( $checkbox_fields as $field ) {
        if ( ! isset( $_POST[ $field ] ) ) {
            update_option( $field, 'no' );
        }
    }

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'jezweb-dynamic-pricing' ) . '</p></div>';
}
?>

<div class="wrap jdpd-settings-wrap">
    <!-- Jezweb Branded Header -->
    <div class="jdpd-page-header">
        <h1>
            <?php esc_html_e( 'Dynamic Pricing Settings', 'jezweb-dynamic-pricing' ); ?>
            <span class="jdpd-version-badge">v<?php echo esc_html( JDPD_VERSION ); ?></span>
        </h1>
    </div>

    <!-- Tab Navigation (Settings vs Debug Log) -->
    <nav class="nav-tab-wrapper woo-nav-tab-wrapper jdpd-main-tabs">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=jdpd-settings&tab=settings' ) ); ?>"
           class="nav-tab <?php echo ( $current_tab !== 'debug' ) ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-settings"></span>
            <?php esc_html_e( 'Settings', 'jezweb-dynamic-pricing' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=jdpd-settings&tab=debug' ) ); ?>"
           class="nav-tab <?php echo ( $current_tab === 'debug' ) ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-code-standards"></span>
            <?php esc_html_e( 'Debug Log', 'jezweb-dynamic-pricing' ); ?>
        </a>
    </nav>

    <?php if ( $current_tab === 'debug' ) : ?>
        <!-- Debug Tab Content -->
        <form method="post" action="">
            <?php wp_nonce_field( 'jdpd_save_settings', 'jdpd_settings_nonce' ); ?>
            <input type="hidden" name="jdpd_save_settings" value="1">
            <?php $settings->render_debug_settings(); ?>
        </form>
    <?php else : ?>
        <!-- Settings Tab Content - All Sections -->
        <form method="post" action="">
            <?php wp_nonce_field( 'jdpd_save_settings', 'jdpd_settings_nonce' ); ?>
            <input type="hidden" name="jdpd_save_settings" value="1">

            <!-- General Settings Section -->
            <div class="jdpd-settings-section">
                <div class="jdpd-section-header">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <h2><?php esc_html_e( 'General Settings', 'jezweb-dynamic-pricing' ); ?></h2>
                </div>
                <div class="jdpd-section-content">
                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_enable_plugin"><?php esc_html_e( 'Enable Plugin', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Enable or disable all dynamic pricing rules.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_enable_plugin" id="jdpd_enable_plugin" value="yes"
                                    <?php checked( get_option( 'jdpd_enable_plugin', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_apply_to_sale_products"><?php esc_html_e( 'Apply to Sale Products', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Apply discount rules to products that are already on sale.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_apply_to_sale_products" id="jdpd_apply_to_sale_products" value="yes"
                                    <?php checked( get_option( 'jdpd_apply_to_sale_products', 'no' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_show_original_price"><?php esc_html_e( 'Show Original Price', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Show crossed-out original price when discount is applied.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_show_original_price" id="jdpd_show_original_price" value="yes"
                                    <?php checked( get_option( 'jdpd_show_original_price', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_show_you_save"><?php esc_html_e( 'Show "You Save"', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Show the amount the customer saves when discount is applied.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_show_you_save" id="jdpd_show_you_save" value="yes"
                                    <?php checked( get_option( 'jdpd_show_you_save', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_shop_manager_access"><?php esc_html_e( 'Shop Manager Access', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Allow shop managers to create and manage discount rules.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_shop_manager_access" id="jdpd_shop_manager_access" value="yes"
                                    <?php checked( get_option( 'jdpd_shop_manager_access', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Display Settings Section -->
            <div class="jdpd-settings-section">
                <div class="jdpd-section-header">
                    <span class="dashicons dashicons-visibility"></span>
                    <h2><?php esc_html_e( 'Display Settings', 'jezweb-dynamic-pricing' ); ?></h2>
                </div>
                <div class="jdpd-section-content">
                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_show_quantity_table"><?php esc_html_e( 'Show Quantity Table', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Display quantity discount table on product pages.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_show_quantity_table" id="jdpd_show_quantity_table" value="yes"
                                    <?php checked( get_option( 'jdpd_show_quantity_table', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_quantity_table_layout"><?php esc_html_e( 'Table Layout', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Choose the layout for the quantity discount table.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <select name="jdpd_quantity_table_layout" id="jdpd_quantity_table_layout">
                                <option value="horizontal" <?php selected( get_option( 'jdpd_quantity_table_layout', 'horizontal' ), 'horizontal' ); ?>>
                                    <?php esc_html_e( 'Horizontal', 'jezweb-dynamic-pricing' ); ?>
                                </option>
                                <option value="vertical" <?php selected( get_option( 'jdpd_quantity_table_layout', 'horizontal' ), 'vertical' ); ?>>
                                    <?php esc_html_e( 'Vertical', 'jezweb-dynamic-pricing' ); ?>
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_quantity_table_position"><?php esc_html_e( 'Table Position', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Where to display the quantity discount table.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
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
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_show_product_notices"><?php esc_html_e( 'Show Product Notices', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Display discount notices on product pages.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_show_product_notices" id="jdpd_show_product_notices" value="yes"
                                    <?php checked( get_option( 'jdpd_show_product_notices', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_notice_style"><?php esc_html_e( 'Notice Style', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Visual style for discount notices.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
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
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_badge_style"><?php esc_html_e( 'Badge Style', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Visual style for sale badges.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
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
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_badge_text"><?php esc_html_e( 'Badge Text', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Text to display on the sale badge. Use {discount} for discount percentage.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <input type="text" name="jdpd_badge_text" id="jdpd_badge_text"
                                   value="<?php echo esc_attr( get_option( 'jdpd_badge_text', __( 'Sale', 'jezweb-dynamic-pricing' ) ) ); ?>"
                                   class="regular-text">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cart Settings Section -->
            <div class="jdpd-settings-section">
                <div class="jdpd-section-header">
                    <span class="dashicons dashicons-cart"></span>
                    <h2><?php esc_html_e( 'Cart Settings', 'jezweb-dynamic-pricing' ); ?></h2>
                </div>
                <div class="jdpd-section-content">
                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_show_cart_discount_label"><?php esc_html_e( 'Show Discount Label', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Show discount label in cart.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_show_cart_discount_label" id="jdpd_show_cart_discount_label" value="yes"
                                    <?php checked( get_option( 'jdpd_show_cart_discount_label', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_cart_discount_label"><?php esc_html_e( 'Discount Label', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Label shown for discounts in cart.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <input type="text" name="jdpd_cart_discount_label" id="jdpd_cart_discount_label"
                                   value="<?php echo esc_attr( get_option( 'jdpd_cart_discount_label', __( 'Discount', 'jezweb-dynamic-pricing' ) ) ); ?>"
                                   class="regular-text">
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_show_cart_notices"><?php esc_html_e( 'Show Cart Notices', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Display notices about available discounts in cart.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_show_cart_notices" id="jdpd_show_cart_notices" value="yes"
                                    <?php checked( get_option( 'jdpd_show_cart_notices', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_show_cart_savings"><?php esc_html_e( 'Show Total Savings', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Display total savings in cart and checkout.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_show_cart_savings" id="jdpd_show_cart_savings" value="yes"
                                    <?php checked( get_option( 'jdpd_show_cart_savings', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Checkout Deals Settings Section -->
            <div class="jdpd-settings-section">
                <div class="jdpd-section-header">
                    <span class="dashicons dashicons-money-alt"></span>
                    <h2><?php esc_html_e( 'Checkout Deals', 'jezweb-dynamic-pricing' ); ?></h2>
                </div>
                <div class="jdpd-section-content">
                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_enable_checkout_deals"><?php esc_html_e( 'Enable Checkout Deals', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Show special deal offers at checkout.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_enable_checkout_deals" id="jdpd_enable_checkout_deals" value="yes"
                                    <?php checked( get_option( 'jdpd_enable_checkout_deals', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_checkout_countdown"><?php esc_html_e( 'Show Countdown Timer', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Display countdown timer for limited-time checkout deals.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_checkout_countdown" id="jdpd_checkout_countdown" value="yes"
                                    <?php checked( get_option( 'jdpd_checkout_countdown', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_checkout_countdown_time"><?php esc_html_e( 'Countdown Duration', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Duration of checkout deal countdown timer in seconds.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <input type="number" name="jdpd_checkout_countdown_time" id="jdpd_checkout_countdown_time"
                                   value="<?php echo esc_attr( get_option( 'jdpd_checkout_countdown_time', 300 ) ); ?>"
                                   min="60" max="3600" class="small-text">
                            <span class="description"><?php esc_html_e( 'seconds', 'jezweb-dynamic-pricing' ); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Advanced Settings Section -->
            <div class="jdpd-settings-section">
                <div class="jdpd-section-header">
                    <span class="dashicons dashicons-admin-tools"></span>
                    <h2><?php esc_html_e( 'Advanced Settings', 'jezweb-dynamic-pricing' ); ?></h2>
                </div>
                <div class="jdpd-section-content">
                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_show_in_order_email"><?php esc_html_e( 'Show in Order Emails', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Include discount information in order emails.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_show_in_order_email" id="jdpd_show_in_order_email" value="yes"
                                    <?php checked( get_option( 'jdpd_show_in_order_email', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>

                    <div class="jdpd-setting-row">
                        <div class="jdpd-setting-label">
                            <label for="jdpd_show_order_metabox"><?php esc_html_e( 'Show Order Metabox', 'jezweb-dynamic-pricing' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Display applied discount rules in order admin page.', 'jezweb-dynamic-pricing' ); ?></p>
                        </div>
                        <div class="jdpd-setting-field">
                            <label class="jdpd-toggle">
                                <input type="checkbox" name="jdpd_show_order_metabox" id="jdpd_show_order_metabox" value="yes"
                                    <?php checked( get_option( 'jdpd_show_order_metabox', 'yes' ), 'yes' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                                <span class="jdpd-toggle-label" data-on="<?php esc_attr_e( 'Yes', 'jezweb-dynamic-pricing' ); ?>" data-off="<?php esc_attr_e( 'No', 'jezweb-dynamic-pricing' ); ?>"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary button-large">
                    <span class="dashicons dashicons-saved"></span>
                    <?php esc_html_e( 'Save Settings', 'jezweb-dynamic-pricing' ); ?>
                </button>
            </p>
        </form>
    <?php endif; ?>

    <!-- Plugin Info & Credits - Combined Section -->
    <div class="jdpd-plugin-footer">
        <div class="jdpd-footer-info">
            <div class="jdpd-footer-brand">
                <span class="jdpd-footer-title"><?php esc_html_e( 'Jezweb Dynamic Pricing', 'jezweb-dynamic-pricing' ); ?></span>
                <span class="jdpd-footer-version">v<?php echo esc_html( JDPD_VERSION ); ?></span>
            </div>
            <div class="jdpd-footer-meta">
                <span class="jdpd-meta-item">
                    <span class="dashicons dashicons-wordpress"></span>
                    <?php echo esc_html( get_bloginfo( 'version' ) ); ?>
                </span>
                <span class="jdpd-meta-item">
                    <span class="dashicons dashicons-admin-plugins"></span>
                    <?php echo defined( 'WC_VERSION' ) ? esc_html( 'WC ' . WC_VERSION ) : esc_html__( 'WC N/A', 'jezweb-dynamic-pricing' ); ?>
                </span>
                <span class="jdpd-meta-item">
                    <span class="dashicons dashicons-editor-code"></span>
                    <?php echo esc_html( 'PHP ' . PHP_VERSION ); ?>
                </span>
            </div>
        </div>
        <div class="jdpd-footer-credits">
            <div class="jdpd-credits-text">
                <span><?php esc_html_e( 'Developed by', 'jezweb-dynamic-pricing' ); ?></span>
                <strong>Mahmmud Farooque</strong>
                <span class="jdpd-credits-separator">|</span>
                <a href="https://jezweb.com.au" target="_blank" rel="noopener">Jezweb</a>
            </div>
            <div class="jdpd-footer-links">
                <a href="https://jezweb.com.au" target="_blank" rel="noopener" class="jdpd-footer-btn">
                    <span class="dashicons dashicons-admin-site-alt3"></span>
                    <?php esc_html_e( 'Website', 'jezweb-dynamic-pricing' ); ?>
                </a>
                <a href="https://github.com/mmhfarooque/jezweb-dynamic-pricing" target="_blank" rel="noopener" class="jdpd-footer-btn">
                    <span class="dashicons dashicons-editor-help"></span>
                    <?php esc_html_e( 'Support', 'jezweb-dynamic-pricing' ); ?>
                </a>
                <a href="https://jezweb.com.au/contact/" target="_blank" rel="noopener" class="jdpd-footer-btn jdpd-footer-btn-primary">
                    <span class="dashicons dashicons-email-alt"></span>
                    <?php esc_html_e( 'Contact', 'jezweb-dynamic-pricing' ); ?>
                </a>
            </div>
        </div>
    </div>
</div>
