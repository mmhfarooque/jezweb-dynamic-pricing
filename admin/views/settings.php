<?php
/**
 * Admin Settings View
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = new JDPD_Admin_Settings();

// Handle form submission
if ( isset( $_POST['jdpd_save_settings'] ) && wp_verify_nonce( $_POST['jdpd_settings_nonce'], 'jdpd_save_settings' ) ) {
    $tab = $settings->get_current_tab();

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
    <h1><?php esc_html_e( 'Dynamic Pricing Settings', 'jezweb-dynamic-pricing' ); ?></h1>

    <?php $settings->render_tabs(); ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'jdpd_save_settings', 'jdpd_settings_nonce' ); ?>
        <input type="hidden" name="jdpd_save_settings" value="1">

        <?php $settings->render_settings_fields(); ?>

        <p class="submit">
            <button type="submit" class="button button-primary">
                <?php esc_html_e( 'Save Settings', 'jezweb-dynamic-pricing' ); ?>
            </button>
        </p>
    </form>

    <!-- Plugin Info -->
    <div class="jdpd-plugin-info">
        <h2><?php esc_html_e( 'Plugin Information', 'jezweb-dynamic-pricing' ); ?></h2>
        <div class="jdpd-info-list">
            <div class="jdpd-info-row">
                <div class="jdpd-info-label"><?php esc_html_e( 'Version', 'jezweb-dynamic-pricing' ); ?></div>
                <div class="jdpd-info-value"><?php echo esc_html( JDPD_VERSION ); ?></div>
            </div>
            <div class="jdpd-info-row">
                <div class="jdpd-info-label"><?php esc_html_e( 'PHP Version', 'jezweb-dynamic-pricing' ); ?></div>
                <div class="jdpd-info-value"><?php echo esc_html( PHP_VERSION ); ?> (<?php esc_html_e( 'Required: 8.0+', 'jezweb-dynamic-pricing' ); ?>)</div>
            </div>
            <div class="jdpd-info-row">
                <div class="jdpd-info-label"><?php esc_html_e( 'WordPress Version', 'jezweb-dynamic-pricing' ); ?></div>
                <div class="jdpd-info-value"><?php echo esc_html( get_bloginfo( 'version' ) ); ?> (<?php esc_html_e( 'Required: 6.0+', 'jezweb-dynamic-pricing' ); ?>)</div>
            </div>
            <div class="jdpd-info-row">
                <div class="jdpd-info-label"><?php esc_html_e( 'WooCommerce Version', 'jezweb-dynamic-pricing' ); ?></div>
                <div class="jdpd-info-value">
                    <?php
                    if ( defined( 'WC_VERSION' ) ) {
                        echo esc_html( WC_VERSION );
                    } else {
                        esc_html_e( 'Not installed', 'jezweb-dynamic-pricing' );
                    }
                    ?>
                    (<?php esc_html_e( 'Required: 8.0+', 'jezweb-dynamic-pricing' ); ?>)
                </div>
            </div>
            <div class="jdpd-info-row">
                <div class="jdpd-info-label"><?php esc_html_e( 'Author', 'jezweb-dynamic-pricing' ); ?></div>
                <div class="jdpd-info-value">Mahmmud Farooque - <a href="https://jezweb.com.au" target="_blank">Jezweb</a></div>
            </div>
            <div class="jdpd-info-row">
                <div class="jdpd-info-label"><?php esc_html_e( 'Documentation', 'jezweb-dynamic-pricing' ); ?></div>
                <div class="jdpd-info-value"><a href="https://jezweb.com.au/docs/dynamic-pricing" target="_blank"><?php esc_html_e( 'View Documentation', 'jezweb-dynamic-pricing' ); ?></a></div>
            </div>
            <div class="jdpd-info-row">
                <div class="jdpd-info-label"><?php esc_html_e( 'Support', 'jezweb-dynamic-pricing' ); ?></div>
                <div class="jdpd-info-value"><a href="https://github.com/mmhfarooque/jezweb-dynamic-pricing/issues" target="_blank"><?php esc_html_e( 'Get Support', 'jezweb-dynamic-pricing' ); ?></a></div>
            </div>
        </div>
    </div>
</div>
