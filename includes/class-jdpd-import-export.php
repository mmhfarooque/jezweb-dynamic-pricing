<?php
/**
 * Import/Export Class
 *
 * Handles importing and exporting rules.
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JDPD_Import_Export Class
 */
class JDPD_Import_Export {

    /**
     * Instance
     *
     * @var JDPD_Import_Export
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return JDPD_Import_Export
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Initialize hooks if needed
    }

    /**
     * Export rules to JSON
     *
     * @param array $rule_ids Optional. Specific rule IDs to export. Empty for all.
     * @return string JSON string.
     */
    public function export_rules( $rule_ids = array() ) {
        global $wpdb;

        $rules_table = $wpdb->prefix . 'jdpd_rules';
        $items_table = $wpdb->prefix . 'jdpd_rule_items';
        $ranges_table = $wpdb->prefix . 'jdpd_quantity_ranges';
        $exclusions_table = $wpdb->prefix . 'jdpd_exclusions';
        $gifts_table = $wpdb->prefix . 'jdpd_gift_products';

        // Build query
        if ( ! empty( $rule_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $rule_ids ), '%d' ) );
            $rules = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $rules_table WHERE id IN ($placeholders)",
                $rule_ids
            ) );
        } else {
            $rules = $wpdb->get_results( "SELECT * FROM $rules_table ORDER BY priority ASC" );
        }

        $export_data = array(
            'version'      => JDPD_VERSION,
            'export_date'  => current_time( 'mysql' ),
            'site_url'     => get_site_url(),
            'rules'        => array(),
        );

        foreach ( $rules as $rule ) {
            $rule_data = (array) $rule;

            // Get rule items (products, categories, tags)
            $rule_data['items'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT item_type, item_id FROM $items_table WHERE rule_id = %d",
                $rule->id
            ), ARRAY_A );

            // Get quantity ranges
            $rule_data['quantity_ranges'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT min_quantity, max_quantity, discount_type, discount_value FROM $ranges_table WHERE rule_id = %d ORDER BY min_quantity ASC",
                $rule->id
            ), ARRAY_A );

            // Get exclusions
            $rule_data['exclusions'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT exclusion_type, exclusion_id FROM $exclusions_table WHERE rule_id = %d",
                $rule->id
            ), ARRAY_A );

            // Get gift products
            $rule_data['gift_products'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT product_id, quantity, discount_type, discount_value FROM $gifts_table WHERE rule_id = %d",
                $rule->id
            ), ARRAY_A );

            // Remove ID for import
            unset( $rule_data['id'] );

            $export_data['rules'][] = $rule_data;
        }

        return wp_json_encode( $export_data, JSON_PRETTY_PRINT );
    }

    /**
     * Import rules from JSON
     *
     * @param string $json_data JSON string.
     * @param array  $options   Import options.
     * @return array Result with success count and errors.
     */
    public function import_rules( $json_data, $options = array() ) {
        $defaults = array(
            'overwrite'     => false,
            'preserve_ids'  => false,
            'skip_existing' => true,
        );

        $options = wp_parse_args( $options, $defaults );

        $data = json_decode( $json_data, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return array(
                'success' => false,
                'error'   => __( 'Invalid JSON format.', 'jezweb-dynamic-pricing' ),
            );
        }

        if ( empty( $data['rules'] ) ) {
            return array(
                'success' => false,
                'error'   => __( 'No rules found in import file.', 'jezweb-dynamic-pricing' ),
            );
        }

        $imported = 0;
        $skipped = 0;
        $errors = array();

        foreach ( $data['rules'] as $rule_data ) {
            try {
                $result = $this->import_single_rule( $rule_data, $options );
                if ( $result['success'] ) {
                    $imported++;
                } else {
                    $skipped++;
                    if ( isset( $result['error'] ) ) {
                        $errors[] = $result['error'];
                    }
                }
            } catch ( Exception $e ) {
                $errors[] = sprintf(
                    /* translators: 1: rule name, 2: error message */
                    __( 'Error importing rule "%1$s": %2$s', 'jezweb-dynamic-pricing' ),
                    $rule_data['name'] ?? 'Unknown',
                    $e->getMessage()
                );
            }
        }

        return array(
            'success'  => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        );
    }

    /**
     * Import a single rule
     *
     * @param array $rule_data Rule data.
     * @param array $options   Import options.
     * @return array
     */
    private function import_single_rule( $rule_data, $options ) {
        global $wpdb;

        $rules_table = $wpdb->prefix . 'jdpd_rules';

        // Check for existing rule with same name
        if ( $options['skip_existing'] ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $rules_table WHERE name = %s",
                $rule_data['name']
            ) );

            if ( $existing && ! $options['overwrite'] ) {
                return array(
                    'success' => false,
                    'error'   => sprintf( __( 'Rule "%s" already exists.', 'jezweb-dynamic-pricing' ), $rule_data['name'] ),
                );
            }

            if ( $existing && $options['overwrite'] ) {
                // Delete existing rule
                $rule_obj = new JDPD_Rule( $existing );
                $rule_obj->delete();
            }
        }

        // Extract related data
        $items = $rule_data['items'] ?? array();
        $quantity_ranges = $rule_data['quantity_ranges'] ?? array();
        $exclusions = $rule_data['exclusions'] ?? array();
        $gift_products = $rule_data['gift_products'] ?? array();
        $conditions = ! empty( $rule_data['conditions'] ) ? maybe_unserialize( $rule_data['conditions'] ) : array();

        unset(
            $rule_data['items'],
            $rule_data['quantity_ranges'],
            $rule_data['exclusions'],
            $rule_data['gift_products']
        );

        // Create new rule
        $rule = new JDPD_Rule();
        $rule->set_multiple( array(
            'name'           => $rule_data['name'],
            'rule_type'      => $rule_data['rule_type'],
            'status'         => $rule_data['status'] ?? 'inactive',
            'priority'       => $rule_data['priority'] ?? 10,
            'discount_type'  => $rule_data['discount_type'],
            'discount_value' => $rule_data['discount_value'],
            'apply_to'       => $rule_data['apply_to'] ?? 'all_products',
            'schedule_from'  => $rule_data['schedule_from'] ?? null,
            'schedule_to'    => $rule_data['schedule_to'] ?? null,
            'usage_limit'    => $rule_data['usage_limit'] ?? 0,
            'exclusive'      => $rule_data['exclusive'] ?? 0,
            'conditions'     => $conditions,
        ) );

        $rule->save();
        $rule_id = $rule->get_id();

        if ( ! $rule_id ) {
            return array(
                'success' => false,
                'error'   => sprintf( __( 'Failed to create rule "%s".', 'jezweb-dynamic-pricing' ), $rule_data['name'] ),
            );
        }

        // Import items
        $items_table = $wpdb->prefix . 'jdpd_rule_items';
        foreach ( $items as $item ) {
            $wpdb->insert(
                $items_table,
                array(
                    'rule_id'   => $rule_id,
                    'item_type' => $item['item_type'],
                    'item_id'   => $item['item_id'],
                ),
                array( '%d', '%s', '%d' )
            );
        }

        // Import quantity ranges
        $ranges_table = $wpdb->prefix . 'jdpd_quantity_ranges';
        foreach ( $quantity_ranges as $range ) {
            $wpdb->insert(
                $ranges_table,
                array(
                    'rule_id'        => $rule_id,
                    'min_quantity'   => $range['min_quantity'],
                    'max_quantity'   => $range['max_quantity'] ?: null,
                    'discount_type'  => $range['discount_type'],
                    'discount_value' => $range['discount_value'],
                ),
                array( '%d', '%d', '%d', '%s', '%f' )
            );
        }

        // Import exclusions
        $exclusions_table = $wpdb->prefix . 'jdpd_exclusions';
        foreach ( $exclusions as $exclusion ) {
            $wpdb->insert(
                $exclusions_table,
                array(
                    'rule_id'        => $rule_id,
                    'exclusion_type' => $exclusion['exclusion_type'],
                    'exclusion_id'   => $exclusion['exclusion_id'],
                ),
                array( '%d', '%s', '%d' )
            );
        }

        // Import gift products
        $gifts_table = $wpdb->prefix . 'jdpd_gift_products';
        foreach ( $gift_products as $gift ) {
            $wpdb->insert(
                $gifts_table,
                array(
                    'rule_id'        => $rule_id,
                    'product_id'     => $gift['product_id'],
                    'quantity'       => $gift['quantity'],
                    'discount_type'  => $gift['discount_type'],
                    'discount_value' => $gift['discount_value'],
                ),
                array( '%d', '%d', '%d', '%s', '%f' )
            );
        }

        return array(
            'success' => true,
            'rule_id' => $rule_id,
        );
    }

    /**
     * Get available templates
     *
     * @return array
     */
    public function get_templates() {
        return array(
            'bulk_discount' => array(
                'name'        => __( 'Bulk Quantity Discount', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Buy more, save more - tiered discounts based on quantity.', 'jezweb-dynamic-pricing' ),
                'icon'        => 'dashicons-chart-bar',
                'category'    => 'price_rule',
                'data'        => array(
                    'name'           => __( 'Bulk Quantity Discount', 'jezweb-dynamic-pricing' ),
                    'rule_type'      => 'price_rule',
                    'discount_type'  => 'percentage',
                    'discount_value' => 0,
                    'apply_to'       => 'all_products',
                    'quantity_ranges' => array(
                        array( 'min_quantity' => 2, 'max_quantity' => 4, 'discount_type' => 'percentage', 'discount_value' => 5 ),
                        array( 'min_quantity' => 5, 'max_quantity' => 9, 'discount_type' => 'percentage', 'discount_value' => 10 ),
                        array( 'min_quantity' => 10, 'max_quantity' => null, 'discount_type' => 'percentage', 'discount_value' => 15 ),
                    ),
                ),
            ),
            'bogo' => array(
                'name'        => __( 'Buy One Get One Free (BOGO)', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Classic BOGO offer - buy one, get another free.', 'jezweb-dynamic-pricing' ),
                'icon'        => 'dashicons-tickets-alt',
                'category'    => 'special_offer',
                'data'        => array(
                    'name'           => __( 'Buy One Get One Free', 'jezweb-dynamic-pricing' ),
                    'rule_type'      => 'special_offer',
                    'discount_type'  => 'percentage',
                    'discount_value' => 100,
                    'apply_to'       => 'all_products',
                    'conditions'     => array(
                        array( 'type' => 'cart_items', 'operator' => 'greater_equal', 'value' => 2 ),
                    ),
                ),
            ),
            'buy_x_get_y' => array(
                'name'        => __( 'Buy X Get Y Discount', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Buy 2 products, get 3rd at 50% off.', 'jezweb-dynamic-pricing' ),
                'icon'        => 'dashicons-plus-alt',
                'category'    => 'special_offer',
                'data'        => array(
                    'name'           => __( 'Buy 2 Get 3rd 50% Off', 'jezweb-dynamic-pricing' ),
                    'rule_type'      => 'special_offer',
                    'discount_type'  => 'percentage',
                    'discount_value' => 50,
                    'apply_to'       => 'all_products',
                    'conditions'     => array(
                        array( 'type' => 'cart_items', 'operator' => 'greater_equal', 'value' => 3 ),
                    ),
                ),
            ),
            'cart_total_discount' => array(
                'name'        => __( 'Cart Total Discount', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Discount when cart reaches certain amount.', 'jezweb-dynamic-pricing' ),
                'icon'        => 'dashicons-cart',
                'category'    => 'cart_rule',
                'data'        => array(
                    'name'           => __( 'Spend $100, Get 10% Off', 'jezweb-dynamic-pricing' ),
                    'rule_type'      => 'cart_rule',
                    'discount_type'  => 'percentage',
                    'discount_value' => 10,
                    'apply_to'       => 'all_products',
                    'conditions'     => array(
                        array( 'type' => 'cart_total', 'operator' => 'greater_equal', 'value' => 100 ),
                    ),
                ),
            ),
            'free_shipping' => array(
                'name'        => __( 'Free Shipping Threshold', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Free shipping when order exceeds amount.', 'jezweb-dynamic-pricing' ),
                'icon'        => 'dashicons-airplane',
                'category'    => 'cart_rule',
                'data'        => array(
                    'name'           => __( 'Free Shipping Over $50', 'jezweb-dynamic-pricing' ),
                    'rule_type'      => 'cart_rule',
                    'discount_type'  => 'free_shipping',
                    'discount_value' => 0,
                    'apply_to'       => 'all_products',
                    'conditions'     => array(
                        array( 'type' => 'cart_total', 'operator' => 'greater_equal', 'value' => 50 ),
                    ),
                ),
            ),
            'first_order' => array(
                'name'        => __( 'First Order Discount', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Special discount for first-time customers.', 'jezweb-dynamic-pricing' ),
                'icon'        => 'dashicons-star-filled',
                'category'    => 'cart_rule',
                'data'        => array(
                    'name'           => __( '15% Off First Order', 'jezweb-dynamic-pricing' ),
                    'rule_type'      => 'cart_rule',
                    'discount_type'  => 'percentage',
                    'discount_value' => 15,
                    'apply_to'       => 'all_products',
                    'conditions'     => array(
                        array( 'type' => 'order_count', 'operator' => 'equals', 'value' => 0 ),
                    ),
                ),
            ),
            'vip_discount' => array(
                'name'        => __( 'VIP Customer Discount', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Exclusive discount for loyal customers.', 'jezweb-dynamic-pricing' ),
                'icon'        => 'dashicons-awards',
                'category'    => 'cart_rule',
                'data'        => array(
                    'name'           => __( 'VIP 20% Discount', 'jezweb-dynamic-pricing' ),
                    'rule_type'      => 'cart_rule',
                    'discount_type'  => 'percentage',
                    'discount_value' => 20,
                    'apply_to'       => 'all_products',
                    'conditions'     => array(
                        array( 'type' => 'total_spent', 'operator' => 'greater_equal', 'value' => 500 ),
                    ),
                ),
            ),
            'free_gift' => array(
                'name'        => __( 'Free Gift with Purchase', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Add a free gift when customer buys specific product.', 'jezweb-dynamic-pricing' ),
                'icon'        => 'dashicons-heart',
                'category'    => 'gift_product',
                'data'        => array(
                    'name'           => __( 'Free Gift with Purchase', 'jezweb-dynamic-pricing' ),
                    'rule_type'      => 'gift_product',
                    'discount_type'  => 'percentage',
                    'discount_value' => 100,
                    'apply_to'       => 'all_products',
                ),
            ),
            'flash_sale' => array(
                'name'        => __( 'Flash Sale (Time-Limited)', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Time-limited sale with countdown timer.', 'jezweb-dynamic-pricing' ),
                'icon'        => 'dashicons-clock',
                'category'    => 'price_rule',
                'data'        => array(
                    'name'           => __( 'Flash Sale 25% Off', 'jezweb-dynamic-pricing' ),
                    'rule_type'      => 'price_rule',
                    'discount_type'  => 'percentage',
                    'discount_value' => 25,
                    'apply_to'       => 'all_products',
                    'schedule_from'  => date( 'Y-m-d H:i:s' ),
                    'schedule_to'    => date( 'Y-m-d H:i:s', strtotime( '+24 hours' ) ),
                ),
            ),
            'category_sale' => array(
                'name'        => __( 'Category Sale', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Apply discount to specific product category.', 'jezweb-dynamic-pricing' ),
                'icon'        => 'dashicons-category',
                'category'    => 'price_rule',
                'data'        => array(
                    'name'           => __( 'Category Sale 20% Off', 'jezweb-dynamic-pricing' ),
                    'rule_type'      => 'price_rule',
                    'discount_type'  => 'percentage',
                    'discount_value' => 20,
                    'apply_to'       => 'specific_categories',
                ),
            ),
        );
    }

    /**
     * Create rule from template
     *
     * @param string $template_key Template key.
     * @return array Result.
     */
    public function create_from_template( $template_key ) {
        $templates = $this->get_templates();

        if ( ! isset( $templates[ $template_key ] ) ) {
            return array(
                'success' => false,
                'error'   => __( 'Template not found.', 'jezweb-dynamic-pricing' ),
            );
        }

        $template = $templates[ $template_key ];
        $rule_data = $template['data'];
        $rule_data['status'] = 'inactive'; // Always create as inactive

        $result = $this->import_single_rule( $rule_data, array(
            'skip_existing' => false,
            'overwrite'     => false,
        ) );

        if ( $result['success'] ) {
            return array(
                'success' => true,
                'rule_id' => $result['rule_id'],
                'message' => sprintf(
                    /* translators: %s: template name */
                    __( 'Rule created from template "%s". Please configure and activate.', 'jezweb-dynamic-pricing' ),
                    $template['name']
                ),
            );
        }

        return $result;
    }
}

/**
 * Get import/export instance
 *
 * @return JDPD_Import_Export
 */
function jdpd_import_export() {
    static $instance = null;
    if ( null === $instance ) {
        $instance = new JDPD_Import_Export();
    }
    return $instance;
}
