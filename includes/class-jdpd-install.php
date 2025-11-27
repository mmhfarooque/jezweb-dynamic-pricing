<?php
/**
 * Installation related functions and actions
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Install class
 */
class JDPD_Install {

    /**
     * Activate the plugin
     */
    public static function activate() {
        self::create_tables();
        self::create_options();
        self::schedule_events();

        // Set version
        update_option( 'jdpd_version', JDPD_VERSION );
        update_option( 'jdpd_db_version', JDPD_DB_VERSION );

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create required database tables
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Price Rules table
        $table_rules = $wpdb->prefix . 'jdpd_rules';
        $sql_rules = "CREATE TABLE IF NOT EXISTS $table_rules (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            rule_type varchar(50) NOT NULL DEFAULT 'price_rule',
            status varchar(20) NOT NULL DEFAULT 'active',
            priority int(11) NOT NULL DEFAULT 10,
            discount_type varchar(50) NOT NULL,
            discount_value decimal(19,4) NOT NULL DEFAULT 0,
            apply_to varchar(50) NOT NULL DEFAULT 'all_products',
            conditions longtext,
            schedule_from datetime DEFAULT NULL,
            schedule_to datetime DEFAULT NULL,
            usage_limit int(11) DEFAULT NULL,
            usage_count int(11) NOT NULL DEFAULT 0,
            exclusive tinyint(1) NOT NULL DEFAULT 0,
            show_badge tinyint(1) NOT NULL DEFAULT 1,
            badge_text varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            KEY rule_type (rule_type),
            KEY status (status),
            KEY priority (priority)
        ) $charset_collate;";

        // Quantity ranges table for bulk pricing
        $table_quantity = $wpdb->prefix . 'jdpd_quantity_ranges';
        $sql_quantity = "CREATE TABLE IF NOT EXISTS $table_quantity (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) unsigned NOT NULL,
            min_quantity int(11) NOT NULL DEFAULT 1,
            max_quantity int(11) DEFAULT NULL,
            discount_type varchar(50) NOT NULL DEFAULT 'percentage',
            discount_value decimal(19,4) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY rule_id (rule_id)
        ) $charset_collate;";

        // Rule products/categories mapping
        $table_rule_items = $wpdb->prefix . 'jdpd_rule_items';
        $sql_rule_items = "CREATE TABLE IF NOT EXISTS $table_rule_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) unsigned NOT NULL,
            item_type varchar(50) NOT NULL,
            item_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY (id),
            KEY rule_id (rule_id),
            KEY item_type (item_type),
            KEY item_id (item_id)
        ) $charset_collate;";

        // Exclusions table
        $table_exclusions = $wpdb->prefix . 'jdpd_exclusions';
        $sql_exclusions = "CREATE TABLE IF NOT EXISTS $table_exclusions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) unsigned NOT NULL,
            exclusion_type varchar(50) NOT NULL,
            exclusion_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY (id),
            KEY rule_id (rule_id)
        ) $charset_collate;";

        // Gift products table
        $table_gifts = $wpdb->prefix . 'jdpd_gift_products';
        $sql_gifts = "CREATE TABLE IF NOT EXISTS $table_gifts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            quantity int(11) NOT NULL DEFAULT 1,
            discount_type varchar(50) NOT NULL DEFAULT 'percentage',
            discount_value decimal(19,4) NOT NULL DEFAULT 100,
            PRIMARY KEY (id),
            KEY rule_id (rule_id)
        ) $charset_collate;";

        // Rule usage tracking
        $table_usage = $wpdb->prefix . 'jdpd_rule_usage';
        $sql_usage = "CREATE TABLE IF NOT EXISTS $table_usage (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            discount_amount decimal(19,4) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY rule_id (rule_id),
            KEY order_id (order_id),
            KEY user_id (user_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_rules );
        dbDelta( $sql_quantity );
        dbDelta( $sql_rule_items );
        dbDelta( $sql_exclusions );
        dbDelta( $sql_gifts );
        dbDelta( $sql_usage );
    }

    /**
     * Create default options
     */
    private static function create_options() {
        $default_options = array(
            // General settings
            'jdpd_enable_plugin'            => 'yes',
            'jdpd_apply_to_sale_products'   => 'no',
            'jdpd_show_original_price'      => 'yes',
            'jdpd_show_you_save'            => 'yes',

            // Quantity table settings
            'jdpd_show_quantity_table'      => 'yes',
            'jdpd_quantity_table_layout'    => 'horizontal',
            'jdpd_quantity_table_position'  => 'after_add_to_cart',

            // Cart settings
            'jdpd_show_cart_discount_label' => 'yes',
            'jdpd_cart_discount_label'      => __( 'Discount', 'jezweb-dynamic-pricing' ),
            'jdpd_show_cart_savings'        => 'yes',

            // Notices settings
            'jdpd_show_product_notices'     => 'yes',
            'jdpd_show_cart_notices'        => 'yes',
            'jdpd_notice_style'             => 'default',

            // Checkout deals
            'jdpd_enable_checkout_deals'    => 'yes',
            'jdpd_checkout_countdown'       => 'yes',
            'jdpd_checkout_countdown_time'  => 300,

            // Order settings
            'jdpd_show_in_order_email'      => 'yes',
            'jdpd_show_order_metabox'       => 'yes',

            // Permissions
            'jdpd_shop_manager_access'      => 'yes',

            // Display settings
            'jdpd_badge_style'              => 'default',
            'jdpd_badge_text'               => __( 'Sale', 'jezweb-dynamic-pricing' ),
        );

        foreach ( $default_options as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }

    /**
     * Schedule events
     */
    private static function schedule_events() {
        if ( ! wp_next_scheduled( 'jdpd_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'jdpd_daily_cleanup' );
        }
    }

    /**
     * Check for updates and run upgrade routines
     */
    public static function maybe_update() {
        $current_version = get_option( 'jdpd_version', '0' );

        if ( version_compare( $current_version, JDPD_VERSION, '<' ) ) {
            self::update( $current_version );
            update_option( 'jdpd_version', JDPD_VERSION );
        }

        $current_db_version = get_option( 'jdpd_db_version', '0' );

        if ( version_compare( $current_db_version, JDPD_DB_VERSION, '<' ) ) {
            self::create_tables();
            update_option( 'jdpd_db_version', JDPD_DB_VERSION );
        }
    }

    /**
     * Run upgrade routines
     *
     * @param string $current_version Current plugin version.
     */
    private static function update( $current_version ) {
        // Future upgrade routines will go here
        // Example:
        // if ( version_compare( $current_version, '1.1.0', '<' ) ) {
        //     self::update_110();
        // }
    }
}
