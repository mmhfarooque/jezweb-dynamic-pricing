<?php
/**
 * Admin functionality
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin class
 */
class JDPD_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_init', array( $this, 'check_updates' ) );
    }

    /**
     * Add menu pages
     */
    public function add_menu_pages() {
        // Main menu
        add_menu_page(
            __( 'Dynamic Pricing', 'jezweb-dynamic-pricing' ),
            __( 'Dynamic Pricing', 'jezweb-dynamic-pricing' ),
            'manage_woocommerce',
            'jdpd-rules',
            array( $this, 'render_rules_page' ),
            'dashicons-tag',
            56
        );

        // Rules submenu
        add_submenu_page(
            'jdpd-rules',
            __( 'All Rules', 'jezweb-dynamic-pricing' ),
            __( 'All Rules', 'jezweb-dynamic-pricing' ),
            'manage_woocommerce',
            'jdpd-rules',
            array( $this, 'render_rules_page' )
        );

        // Add New Rule submenu
        add_submenu_page(
            'jdpd-rules',
            __( 'Add New Rule', 'jezweb-dynamic-pricing' ),
            __( 'Add New Rule', 'jezweb-dynamic-pricing' ),
            'manage_woocommerce',
            'jdpd-add-rule',
            array( $this, 'render_add_rule_page' )
        );

        // Settings submenu
        add_submenu_page(
            'jdpd-rules',
            __( 'Settings', 'jezweb-dynamic-pricing' ),
            __( 'Settings', 'jezweb-dynamic-pricing' ),
            'manage_woocommerce',
            'jdpd-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_scripts( $hook ) {
        $screen = get_current_screen();

        // Only load on our plugin pages
        if ( strpos( $hook, 'jdpd' ) === false && ( ! $screen || strpos( $screen->id, 'jdpd' ) === false ) ) {
            return;
        }

        // Enqueue WooCommerce admin styles
        wp_enqueue_style( 'woocommerce_admin_styles' );

        // Select2
        wp_enqueue_script( 'select2' );
        wp_enqueue_style( 'select2' );

        // jQuery UI
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_script( 'jquery-ui-sortable' );

        // Admin CSS
        wp_enqueue_style(
            'jdpd-admin',
            JDPD_PLUGIN_URL . 'admin/assets/css/admin.css',
            array(),
            JDPD_VERSION
        );

        // Admin JS
        wp_enqueue_script(
            'jdpd-admin',
            JDPD_PLUGIN_URL . 'admin/assets/js/admin.js',
            array( 'jquery', 'select2', 'jquery-ui-datepicker', 'jquery-ui-sortable', 'wp-util' ),
            JDPD_VERSION,
            true
        );

        // Localize script
        wp_localize_script(
            'jdpd-admin',
            'jdpd_admin',
            array(
                'ajax_url'           => admin_url( 'admin-ajax.php' ),
                'nonce'              => wp_create_nonce( 'jdpd_admin_nonce' ),
                'i18n'               => array(
                    'confirm_delete'     => __( 'Are you sure you want to delete this rule?', 'jezweb-dynamic-pricing' ),
                    'confirm_bulk_delete' => __( 'Are you sure you want to delete the selected rules?', 'jezweb-dynamic-pricing' ),
                    'search_products'    => __( 'Search for a product...', 'jezweb-dynamic-pricing' ),
                    'search_categories'  => __( 'Search for a category...', 'jezweb-dynamic-pricing' ),
                    'search_tags'        => __( 'Search for a tag...', 'jezweb-dynamic-pricing' ),
                    'search_users'       => __( 'Search for a user...', 'jezweb-dynamic-pricing' ),
                    'add_range'          => __( 'Add Range', 'jezweb-dynamic-pricing' ),
                    'remove'             => __( 'Remove', 'jezweb-dynamic-pricing' ),
                    'saving'             => __( 'Saving...', 'jezweb-dynamic-pricing' ),
                    'saved'              => __( 'Saved!', 'jezweb-dynamic-pricing' ),
                    'error'              => __( 'Error', 'jezweb-dynamic-pricing' ),
                ),
                'currency_symbol'    => get_woocommerce_currency_symbol(),
                'currency_position'  => get_option( 'woocommerce_currency_pos' ),
            )
        );
    }

    /**
     * Check for plugin updates
     */
    public function check_updates() {
        JDPD_Install::maybe_update();
    }

    /**
     * Render rules page
     */
    public function render_rules_page() {
        // Handle actions
        $this->handle_rule_actions();

        include JDPD_PLUGIN_PATH . 'admin/views/rules-list.php';
    }

    /**
     * Render add/edit rule page
     */
    public function render_add_rule_page() {
        $rule_id = isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0;
        $rule = $rule_id > 0 ? new JDPD_Rule( $rule_id ) : null;

        // Handle form submission - verify nonce AND capability
        if ( isset( $_POST['jdpd_save_rule'] ) && wp_verify_nonce( $_POST['jdpd_rule_nonce'], 'jdpd_save_rule' ) ) {
            // Security: Verify user has permission to manage WooCommerce
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'You do not have permission to perform this action.', 'jezweb-dynamic-pricing' ) );
            }
            $this->save_rule( $rule );
        }

        include JDPD_PLUGIN_PATH . 'admin/views/rule-edit.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        include JDPD_PLUGIN_PATH . 'admin/views/settings.php';
    }

    /**
     * Handle rule actions (delete, duplicate, etc.)
     */
    private function handle_rule_actions() {
        if ( ! isset( $_GET['action'] ) ) {
            return;
        }

        $action = sanitize_key( $_GET['action'] );
        $rule_id = isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0;

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'jdpd_rule_action' ) ) {
            return;
        }

        // Security: Verify user has permission to manage WooCommerce
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'jezweb-dynamic-pricing' ) );
        }

        switch ( $action ) {
            case 'delete':
                if ( $rule_id > 0 ) {
                    $rule = new JDPD_Rule( $rule_id );
                    $rule->delete();
                    wp_safe_redirect( admin_url( 'admin.php?page=jdpd-rules&deleted=1' ) );
                    exit;
                }
                break;

            case 'duplicate':
                if ( $rule_id > 0 ) {
                    $rule = new JDPD_Rule( $rule_id );
                    $new_rule = $rule->duplicate();
                    if ( $new_rule ) {
                        wp_safe_redirect( admin_url( 'admin.php?page=jdpd-add-rule&rule_id=' . $new_rule->get_id() . '&duplicated=1' ) );
                        exit;
                    }
                }
                break;

            case 'activate':
                if ( $rule_id > 0 ) {
                    $this->update_rule_status( $rule_id, 'active' );
                    wp_safe_redirect( admin_url( 'admin.php?page=jdpd-rules&activated=1' ) );
                    exit;
                }
                break;

            case 'deactivate':
                if ( $rule_id > 0 ) {
                    $this->update_rule_status( $rule_id, 'inactive' );
                    wp_safe_redirect( admin_url( 'admin.php?page=jdpd-rules&deactivated=1' ) );
                    exit;
                }
                break;
        }
    }

    /**
     * Update rule status
     *
     * @param int    $rule_id Rule ID.
     * @param string $status  New status.
     */
    private function update_rule_status( $rule_id, $status ) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'jdpd_rules',
            array( 'status' => $status ),
            array( 'id' => $rule_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Save rule from form submission
     *
     * @param JDPD_Rule|null $existing_rule Existing rule if editing.
     */
    private function save_rule( $existing_rule = null ) {
        global $wpdb;

        $rule = $existing_rule ? $existing_rule : new JDPD_Rule();

        // Basic data
        $rule->set( 'name', sanitize_text_field( $_POST['rule_name'] ) );
        $rule->set( 'rule_type', sanitize_key( $_POST['rule_type'] ) );
        $rule->set( 'status', isset( $_POST['rule_status'] ) ? 'active' : 'inactive' );
        $rule->set( 'priority', absint( $_POST['rule_priority'] ) );
        $rule->set( 'discount_type', sanitize_key( $_POST['discount_type'] ) );
        $rule->set( 'discount_value', floatval( $_POST['discount_value'] ) );
        $rule->set( 'apply_to', sanitize_key( $_POST['apply_to'] ) );
        $rule->set( 'exclusive', isset( $_POST['exclusive'] ) ? 1 : 0 );
        $rule->set( 'show_badge', isset( $_POST['show_badge'] ) ? 1 : 0 );
        $rule->set( 'badge_text', sanitize_text_field( $_POST['badge_text'] ?? '' ) );

        // Schedule - convert from dd-mm-yyyy to MySQL datetime format
        $schedule_from = ! empty( $_POST['schedule_from'] ) ? sanitize_text_field( $_POST['schedule_from'] ) : null;
        $schedule_to = ! empty( $_POST['schedule_to'] ) ? sanitize_text_field( $_POST['schedule_to'] ) : null;

        // Parse dd-mm-yyyy format to MySQL datetime
        if ( $schedule_from ) {
            $parsed = DateTime::createFromFormat( 'd-m-Y H:i', $schedule_from );
            if ( ! $parsed ) {
                $parsed = DateTime::createFromFormat( 'd-m-Y', $schedule_from );
            }
            $schedule_from = $parsed ? $parsed->format( 'Y-m-d H:i:s' ) : null;
        }
        if ( $schedule_to ) {
            $parsed = DateTime::createFromFormat( 'd-m-Y H:i', $schedule_to );
            if ( ! $parsed ) {
                $parsed = DateTime::createFromFormat( 'd-m-Y', $schedule_to );
            }
            $schedule_to = $parsed ? $parsed->format( 'Y-m-d H:i:s' ) : null;
        }

        $rule->set( 'schedule_from', $schedule_from );
        $rule->set( 'schedule_to', $schedule_to );

        // Usage limit
        $rule->set( 'usage_limit', ! empty( $_POST['usage_limit'] ) ? absint( $_POST['usage_limit'] ) : null );

        // Special Offer Type - MUST be saved to restore the event_sale settings panel on edit
        $rule->set( 'special_offer_type', ! empty( $_POST['special_offer_type'] ) ? sanitize_key( $_POST['special_offer_type'] ) : '' );

        // Event Sale settings
        $event_type_sanitized = ! empty( $_POST['event_type'] ) ? sanitize_key( $_POST['event_type'] ) : '';
        $rule->set( 'event_type', $event_type_sanitized );
        $rule->set( 'custom_event_name', ! empty( $_POST['custom_event_name'] ) ? sanitize_text_field( $_POST['custom_event_name'] ) : '' );
        $rule->set( 'event_discount_type', ! empty( $_POST['event_discount_type'] ) ? sanitize_key( $_POST['event_discount_type'] ) : 'percentage' );
        $rule->set( 'event_discount_value', ! empty( $_POST['event_discount_value'] ) ? floatval( $_POST['event_discount_value'] ) : 0 );

        // Badge customization colors
        $rule->set( 'badge_bg_color', ! empty( $_POST['badge_bg_color'] ) ? sanitize_hex_color( $_POST['badge_bg_color'] ) : '' );
        $rule->set( 'badge_text_color', ! empty( $_POST['badge_text_color'] ) ? sanitize_hex_color( $_POST['badge_text_color'] ) : '' );

        // Verify the values were set
        if ( function_exists( 'jdpd_log' ) ) {
            jdpd_log( 'Event Sale Debug - After set, rule event_type: ' . $rule->get( 'event_type' ), 'debug' );
        }

        // Conditions
        $conditions = array();
        if ( ! empty( $_POST['conditions'] ) && is_array( $_POST['conditions'] ) ) {
            foreach ( $_POST['conditions'] as $condition ) {
                $conditions[] = array(
                    'type'     => sanitize_key( $condition['type'] ),
                    'operator' => sanitize_key( $condition['operator'] ),
                    'value'    => sanitize_text_field( $condition['value'] ),
                );
            }
        }
        $rule->set( 'conditions', $conditions );

        // Save rule
        $rule_id = $rule->save();

        if ( ! $rule_id ) {
            return;
        }

        // Save rule items (products, categories, tags)
        $wpdb->delete( $wpdb->prefix . 'jdpd_rule_items', array( 'rule_id' => $rule_id ), array( '%d' ) );

        $apply_to = $rule->get( 'apply_to' );
        if ( 'specific_products' === $apply_to && ! empty( $_POST['products'] ) ) {
            foreach ( (array) $_POST['products'] as $product_id ) {
                $wpdb->insert(
                    $wpdb->prefix . 'jdpd_rule_items',
                    array(
                        'rule_id'   => $rule_id,
                        'item_type' => 'product',
                        'item_id'   => absint( $product_id ),
                    ),
                    array( '%d', '%s', '%d' )
                );
            }
        } elseif ( 'categories' === $apply_to && ! empty( $_POST['categories'] ) ) {
            foreach ( (array) $_POST['categories'] as $cat_id ) {
                $wpdb->insert(
                    $wpdb->prefix . 'jdpd_rule_items',
                    array(
                        'rule_id'   => $rule_id,
                        'item_type' => 'category',
                        'item_id'   => absint( $cat_id ),
                    ),
                    array( '%d', '%s', '%d' )
                );
            }
        } elseif ( 'tags' === $apply_to && ! empty( $_POST['tags'] ) ) {
            foreach ( (array) $_POST['tags'] as $tag_id ) {
                $wpdb->insert(
                    $wpdb->prefix . 'jdpd_rule_items',
                    array(
                        'rule_id'   => $rule_id,
                        'item_type' => 'tag',
                        'item_id'   => absint( $tag_id ),
                    ),
                    array( '%d', '%s', '%d' )
                );
            }
        }

        // Save exclusions
        $wpdb->delete( $wpdb->prefix . 'jdpd_exclusions', array( 'rule_id' => $rule_id ), array( '%d' ) );

        if ( ! empty( $_POST['exclude_products'] ) ) {
            foreach ( (array) $_POST['exclude_products'] as $product_id ) {
                $wpdb->insert(
                    $wpdb->prefix . 'jdpd_exclusions',
                    array(
                        'rule_id'        => $rule_id,
                        'exclusion_type' => 'product',
                        'exclusion_id'   => absint( $product_id ),
                    ),
                    array( '%d', '%s', '%d' )
                );
            }
        }

        if ( ! empty( $_POST['exclude_categories'] ) ) {
            foreach ( (array) $_POST['exclude_categories'] as $cat_id ) {
                $wpdb->insert(
                    $wpdb->prefix . 'jdpd_exclusions',
                    array(
                        'rule_id'        => $rule_id,
                        'exclusion_type' => 'category',
                        'exclusion_id'   => absint( $cat_id ),
                    ),
                    array( '%d', '%s', '%d' )
                );
            }
        }

        // Save quantity ranges for bulk pricing
        $wpdb->delete( $wpdb->prefix . 'jdpd_quantity_ranges', array( 'rule_id' => $rule_id ), array( '%d' ) );

        if ( ! empty( $_POST['quantity_ranges'] ) && is_array( $_POST['quantity_ranges'] ) ) {
            foreach ( $_POST['quantity_ranges'] as $range ) {
                if ( empty( $range['min'] ) ) {
                    continue;
                }
                $wpdb->insert(
                    $wpdb->prefix . 'jdpd_quantity_ranges',
                    array(
                        'rule_id'        => $rule_id,
                        'min_quantity'   => absint( $range['min'] ),
                        'max_quantity'   => ! empty( $range['max'] ) ? absint( $range['max'] ) : null,
                        'discount_type'  => sanitize_key( $range['type'] ),
                        'discount_value' => floatval( $range['value'] ),
                    ),
                    array( '%d', '%d', '%d', '%s', '%f' )
                );
            }
        }

        // Save gift products
        $wpdb->delete( $wpdb->prefix . 'jdpd_gift_products', array( 'rule_id' => $rule_id ), array( '%d' ) );

        if ( ! empty( $_POST['gift_products'] ) && is_array( $_POST['gift_products'] ) ) {
            foreach ( $_POST['gift_products'] as $gift ) {
                if ( empty( $gift['product_id'] ) ) {
                    continue;
                }
                $wpdb->insert(
                    $wpdb->prefix . 'jdpd_gift_products',
                    array(
                        'rule_id'        => $rule_id,
                        'product_id'     => absint( $gift['product_id'] ),
                        'quantity'       => absint( $gift['quantity'] ?? 1 ),
                        'discount_type'  => sanitize_key( $gift['discount_type'] ?? 'percentage' ),
                        'discount_value' => floatval( $gift['discount_value'] ?? 100 ),
                    ),
                    array( '%d', '%d', '%d', '%s', '%f' )
                );
            }
        }

        // Redirect with success message
        wp_safe_redirect(
            admin_url( 'admin.php?page=jdpd-add-rule&rule_id=' . $rule_id . '&saved=1' )
        );
        exit;
    }
}
