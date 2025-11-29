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
            event_type varchar(100) DEFAULT NULL,
            custom_event_name varchar(255) DEFAULT NULL,
            event_discount_type varchar(50) DEFAULT 'percentage',
            event_discount_value decimal(19,4) DEFAULT 0,
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

        // Analytics tracking table (v1.3.0)
        $table_analytics = $wpdb->prefix . 'jdpd_analytics';
        $sql_analytics = "CREATE TABLE IF NOT EXISTS $table_analytics (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id varchar(100) NOT NULL,
            rule_name varchar(255) DEFAULT NULL,
            rule_type varchar(50) DEFAULT NULL,
            discount_amount decimal(12,4) NOT NULL DEFAULT 0,
            original_total decimal(12,4) NOT NULL DEFAULT 0,
            discounted_total decimal(12,4) NOT NULL DEFAULT 0,
            product_id bigint(20) unsigned DEFAULT NULL,
            product_name varchar(255) DEFAULT NULL,
            quantity int(11) NOT NULL DEFAULT 1,
            user_id bigint(20) unsigned DEFAULT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            session_id varchar(100) DEFAULT NULL,
            converted tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY rule_id (rule_id),
            KEY product_id (product_id),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY created_at (created_at),
            KEY converted (converted)
        ) $charset_collate;";

        // Analytics daily summary table (v1.3.0)
        $table_analytics_daily = $wpdb->prefix . 'jdpd_analytics_daily';
        $sql_analytics_daily = "CREATE TABLE IF NOT EXISTS $table_analytics_daily (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            date date NOT NULL,
            rule_id varchar(100) NOT NULL,
            impressions int(11) NOT NULL DEFAULT 0,
            applications int(11) NOT NULL DEFAULT 0,
            conversions int(11) NOT NULL DEFAULT 0,
            total_discount decimal(12,4) NOT NULL DEFAULT 0,
            total_revenue decimal(12,4) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY date_rule (date, rule_id),
            KEY date (date),
            KEY rule_id (rule_id)
        ) $charset_collate;";

        // Customer segments table (v1.3.0)
        $table_segments = $wpdb->prefix . 'jdpd_customer_segments';
        $sql_segments = "CREATE TABLE IF NOT EXISTS $table_segments (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(100) NOT NULL,
            description text,
            type varchar(20) NOT NULL DEFAULT 'manual',
            conditions longtext,
            customer_count int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY type (type)
        ) $charset_collate;";

        // Customer segment assignments table (v1.3.0)
        $table_segment_customers = $wpdb->prefix . 'jdpd_segment_customers';
        $sql_segment_customers = "CREATE TABLE IF NOT EXISTS $table_segment_customers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            segment_id bigint(20) unsigned NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            assigned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY segment_customer (segment_id, customer_id),
            KEY segment_id (segment_id),
            KEY customer_id (customer_id)
        ) $charset_collate;";

        // A/B Tests table (v1.3.0)
        $table_ab_tests = $wpdb->prefix . 'jdpd_ab_tests';
        $sql_ab_tests = "CREATE TABLE IF NOT EXISTS $table_ab_tests (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text,
            status varchar(20) NOT NULL DEFAULT 'draft',
            control_rule_id varchar(100) NOT NULL,
            variant_rule_id varchar(100) NOT NULL,
            traffic_split int(3) NOT NULL DEFAULT 50,
            goal varchar(50) NOT NULL DEFAULT 'conversion',
            minimum_sample_size int(11) NOT NULL DEFAULT 100,
            confidence_level decimal(5,2) NOT NULL DEFAULT 95.00,
            start_date datetime DEFAULT NULL,
            end_date datetime DEFAULT NULL,
            winner varchar(20) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY control_rule_id (control_rule_id),
            KEY variant_rule_id (variant_rule_id)
        ) $charset_collate;";

        // A/B Test results table (v1.3.0)
        $table_ab_results = $wpdb->prefix . 'jdpd_ab_results';
        $sql_ab_results = "CREATE TABLE IF NOT EXISTS $table_ab_results (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            test_id bigint(20) unsigned NOT NULL,
            variant varchar(20) NOT NULL,
            visitor_id varchar(100) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            event_type varchar(50) NOT NULL,
            event_data longtext,
            order_id bigint(20) unsigned DEFAULT NULL,
            order_total decimal(12,4) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY test_id (test_id),
            KEY variant (variant),
            KEY visitor_id (visitor_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Price history table (v1.4.0)
        $table_price_history = $wpdb->prefix . 'jdpd_price_history';
        $sql_price_history = "CREATE TABLE IF NOT EXISTS $table_price_history (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            variation_id bigint(20) unsigned DEFAULT 0,
            regular_price decimal(19,4) DEFAULT NULL,
            sale_price decimal(19,4) DEFAULT NULL,
            effective_price decimal(19,4) NOT NULL,
            change_type varchar(50) DEFAULT 'update',
            recorded_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY variation_id (variation_id),
            KEY recorded_at (recorded_at),
            KEY product_date (product_id, recorded_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_rules );
        dbDelta( $sql_quantity );
        dbDelta( $sql_rule_items );
        dbDelta( $sql_exclusions );
        dbDelta( $sql_gifts );
        dbDelta( $sql_usage );
        dbDelta( $sql_analytics );
        dbDelta( $sql_analytics_daily );
        dbDelta( $sql_segments );
        dbDelta( $sql_segment_customers );
        dbDelta( $sql_ab_tests );
        dbDelta( $sql_ab_results );
        dbDelta( $sql_price_history );
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

        // Schedule v1.4.0 events
        if ( ! wp_next_scheduled( 'jdpd_birthday_check' ) ) {
            wp_schedule_event( strtotime( '06:00:00' ), 'daily', 'jdpd_birthday_check' );
        }

        if ( ! wp_next_scheduled( 'jdpd_anniversary_check' ) ) {
            wp_schedule_event( strtotime( '06:00:00' ), 'daily', 'jdpd_anniversary_check' );
        }

        if ( ! wp_next_scheduled( 'jdpd_wishlist_reminder' ) ) {
            wp_schedule_event( strtotime( '09:00:00' ), 'daily', 'jdpd_wishlist_reminder' );
        }

        if ( ! wp_next_scheduled( 'jdpd_price_drop_notifications' ) ) {
            wp_schedule_event( time(), 'hourly', 'jdpd_price_drop_notifications' );
        }

        if ( ! wp_next_scheduled( 'jdpd_price_history_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'jdpd_price_history_cleanup' );
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
        // v1.3.0 upgrade - Add new tables for analytics, segments, A/B testing
        if ( version_compare( $current_version, '1.3.0', '<' ) ) {
            self::update_130();
        }

        // v1.4.0 upgrade - Add price history table and new features
        if ( version_compare( $current_version, '1.4.0', '<' ) ) {
            self::update_140();
        }

        // v1.5.5 upgrade - Add event sale columns to rules table
        if ( version_compare( $current_version, '1.5.5', '<' ) ) {
            self::update_155();
        }
    }

    /**
     * Upgrade to version 1.3.0
     * Creates new tables for analytics, customer segments, and A/B testing
     */
    private static function update_130() {
        // Tables are created via create_tables() which is called when DB version changes
        // Add default options for new features
        $new_options = array(
            'jdpd_analytics_enabled'        => 'yes',
            'jdpd_analytics_retention_days' => 90,
            'jdpd_loyalty_tiers_enabled'    => 'no',
            'jdpd_ab_testing_enabled'       => 'no',
            'jdpd_email_notifications'      => 'yes',
            'jdpd_upsell_messages_enabled'  => 'yes',
            'jdpd_countdown_timers_enabled' => 'yes',
        );

        foreach ( $new_options as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }

        // Log the upgrade
        if ( function_exists( 'jdpd_log' ) ) {
            jdpd_log( 'Upgraded to version 1.3.0', 'info' );
        }
    }

    /**
     * Upgrade to version 1.4.0
     * Adds price history table and schedules new events
     */
    private static function update_140() {
        // Schedule new events
        self::schedule_events();

        // Add default options for new features
        $new_options = array(
            // Profit Protection
            'jdpd_profit_protection_enabled'   => 'no',
            'jdpd_default_min_margin'          => 20,

            // Bundle Builder
            'jdpd_bundle_builder_enabled'      => 'yes',

            // Geo Pricing
            'jdpd_geo_pricing_enabled'         => 'no',

            // Urgency & Scarcity
            'jdpd_urgency_enabled'             => 'yes',
            'jdpd_show_views_counter'          => 'yes',
            'jdpd_show_purchase_counter'       => 'yes',

            // Wholesale Pricing
            'jdpd_wholesale_enabled'           => 'no',

            // Coupon Stacking
            'jdpd_coupon_stacking_enabled'     => 'yes',
            'jdpd_max_coupons_per_order'       => 5,

            // Birthday Discounts
            'jdpd_birthday_enabled'            => 'no',
            'jdpd_birthday_discount'           => 10,

            // Wishlist Pricing
            'jdpd_wishlist_pricing_enabled'    => 'no',

            // Social Discounts
            'jdpd_social_discounts_enabled'    => 'no',
            'jdpd_social_discount_amount'      => 10,

            // Price History
            'jdpd_price_history_enabled'       => 'yes',
            'jdpd_show_price_graph'            => 'yes',
            'jdpd_omnibus_enabled'             => 'no',
        );

        foreach ( $new_options as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }

        // Log the upgrade
        if ( function_exists( 'jdpd_log' ) ) {
            jdpd_log( 'Upgraded to version 1.4.0', 'info' );
        }
    }

    /**
     * Upgrade to version 1.5.5
     * Adds event sale columns to the rules table
     */
    private static function update_155() {
        global $wpdb;

        $table = $wpdb->prefix . 'jdpd_rules';

        // Check if event_type column exists
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW COLUMNS FROM {$table} LIKE %s",
                'event_type'
            )
        );

        if ( empty( $column_exists ) ) {
            // Add event sale columns
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN event_type varchar(100) DEFAULT NULL AFTER badge_text" );
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN custom_event_name varchar(255) DEFAULT NULL AFTER event_type" );
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN event_discount_type varchar(50) DEFAULT 'percentage' AFTER custom_event_name" );
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN event_discount_value decimal(19,4) DEFAULT 0 AFTER event_discount_type" );
        }

        // Log the upgrade
        if ( function_exists( 'jdpd_log' ) ) {
            jdpd_log( 'Upgraded to version 1.5.5', 'info' );
        }
    }
}
