<?php
/**
 * Base Rule class
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Base Rule class
 */
class JDPD_Rule {

    /**
     * Rule ID
     *
     * @var int
     */
    protected $id = 0;

    /**
     * Rule data
     *
     * @var array
     */
    protected $data = array(
        'name'                 => '',
        'rule_type'            => 'price_rule',
        'status'               => 'active',
        'priority'             => 10,
        'discount_type'        => 'percentage',
        'discount_value'       => 0,
        'apply_to'             => 'all_products',
        'conditions'           => array(),
        'schedule_from'        => null,
        'schedule_to'          => null,
        'usage_limit'          => null,
        'usage_count'          => 0,
        'exclusive'            => false,
        'show_badge'           => true,
        'badge_text'           => '',
        'special_offer_type'   => '',
        'event_type'           => '',
        'custom_event_name'    => '',
        'event_discount_type'  => 'percentage',
        'event_discount_value' => 0,
        'created_at'           => '',
        'updated_at'           => '',
        'created_by'           => 0,
    );

    /**
     * Constructor
     *
     * @param int|object $rule Rule ID or object.
     */
    public function __construct( $rule = 0 ) {
        if ( is_numeric( $rule ) && $rule > 0 ) {
            $this->id = $rule;
            $this->load();
        } elseif ( is_object( $rule ) ) {
            $this->set_data_from_object( $rule );
        }
    }

    /**
     * Load rule from database
     */
    protected function load() {
        global $wpdb;

        $table = $wpdb->prefix . 'jdpd_rules';
        $rule = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $this->id )
        );

        // Debug logging for load
        error_log( 'JDPD v1.6.0 - LOAD rule ID: ' . $this->id );
        if ( $rule ) {
            error_log( 'JDPD v1.6.0 - LOAD found - event_type from DB: ' . var_export( $rule->event_type ?? 'NOT SET', true ) );
            $this->set_data_from_object( $rule );
        } else {
            error_log( 'JDPD v1.6.0 - LOAD failed - rule not found in database' );
        }
    }

    /**
     * Set data from database object
     *
     * @param object $rule Database row object.
     */
    protected function set_data_from_object( $rule ) {
        $this->id = absint( $rule->id );

        foreach ( $this->data as $key => $default ) {
            if ( isset( $rule->$key ) ) {
                if ( 'conditions' === $key ) {
                    $this->data[ $key ] = json_decode( $rule->$key, true ) ?: array();
                } elseif ( in_array( $key, array( 'exclusive', 'show_badge' ), true ) ) {
                    $this->data[ $key ] = (bool) $rule->$key;
                } else {
                    $this->data[ $key ] = $rule->$key;
                }
            }
        }
    }

    /**
     * Get rule ID
     *
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get rule data
     *
     * @param string $key Data key.
     * @return mixed
     */
    public function get( $key ) {
        return isset( $this->data[ $key ] ) ? $this->data[ $key ] : null;
    }

    /**
     * Set rule data
     *
     * @param string $key   Data key.
     * @param mixed  $value Data value.
     */
    public function set( $key, $value ) {
        if ( array_key_exists( $key, $this->data ) ) {
            $this->data[ $key ] = $value;
        }
    }

    /**
     * Get all data
     *
     * @return array
     */
    public function get_data() {
        return $this->data;
    }

    /**
     * Check if rule is active
     *
     * @return bool
     */
    public function is_active() {
        if ( 'active' !== $this->get( 'status' ) ) {
            return false;
        }

        // Check schedule
        if ( ! $this->is_within_schedule() ) {
            return false;
        }

        // Check usage limit
        if ( $this->has_exceeded_usage_limit() ) {
            return false;
        }

        return true;
    }

    /**
     * Check if rule is within schedule
     *
     * @return bool
     */
    public function is_within_schedule() {
        $now = current_time( 'mysql' );

        $from = $this->get( 'schedule_from' );
        $to = $this->get( 'schedule_to' );

        if ( ! empty( $from ) && $now < $from ) {
            return false;
        }

        if ( ! empty( $to ) && $now > $to ) {
            return false;
        }

        return true;
    }

    /**
     * Check if usage limit has been exceeded
     *
     * @return bool
     */
    public function has_exceeded_usage_limit() {
        $limit = $this->get( 'usage_limit' );

        if ( empty( $limit ) ) {
            return false;
        }

        return $this->get( 'usage_count' ) >= $limit;
    }

    /**
     * Check if rule is exclusive
     *
     * @return bool
     */
    public function is_exclusive() {
        return (bool) $this->get( 'exclusive' );
    }

    /**
     * Get products this rule applies to
     *
     * @return array
     */
    public function get_applicable_products() {
        $apply_to = $this->get( 'apply_to' );

        if ( 'all_products' === $apply_to ) {
            return array( 'all' );
        }

        return jdpd_get_rule_items( $this->id, $apply_to );
    }

    /**
     * Get excluded items
     *
     * @return array
     */
    public function get_exclusions() {
        return jdpd_get_rule_exclusions( $this->id );
    }

    /**
     * Get quantity ranges
     *
     * @return array
     */
    public function get_quantity_ranges() {
        return jdpd_get_quantity_ranges( $this->id );
    }

    /**
     * Get gift products
     *
     * @return array
     */
    public function get_gift_products() {
        return jdpd_get_gift_products( $this->id );
    }

    /**
     * Get conditions
     *
     * @return array
     */
    public function get_conditions() {
        return $this->get( 'conditions' );
    }

    /**
     * Check if product applies to this rule
     *
     * @param WC_Product|int $product Product object or ID.
     * @return bool
     */
    public function applies_to_product( $product ) {
        if ( is_numeric( $product ) ) {
            $product = wc_get_product( $product );
        }

        if ( ! $product ) {
            return false;
        }

        $product_id = $product->get_id();

        // Get parent ID if this is a variation
        $parent_id = 0;
        if ( $product->is_type( 'variation' ) ) {
            $parent_id = $product->get_parent_id();
        }

        // Check if product is excluded
        if ( jdpd_is_product_excluded( $product_id, $this->id ) ) {
            return false;
        }

        // Also check if parent is excluded (for variations)
        if ( $parent_id > 0 && jdpd_is_product_excluded( $parent_id, $this->id ) ) {
            return false;
        }

        // Check if we should apply to sale products
        if ( 'no' === get_option( 'jdpd_apply_to_sale_products', 'no' ) && $product->is_on_sale() ) {
            return false;
        }

        $apply_to = $this->get( 'apply_to' );

        switch ( $apply_to ) {
            case 'all_products':
                return true;

            case 'specific_products':
                $items = jdpd_get_rule_items( $this->id, 'product' );
                $product_ids = wp_list_pluck( $items, 'item_id' );

                // Convert to integers for comparison (database returns strings)
                $product_ids = array_map( 'intval', $product_ids );

                // Debug logging
                error_log( 'JDPD v1.6.4 - Rule ' . $this->id . ' specific_products: ' . implode( ', ', $product_ids ) );
                error_log( 'JDPD v1.6.4 - Checking product_id: ' . $product_id . ', parent_id: ' . $parent_id );

                // Check if product ID matches
                if ( in_array( $product_id, $product_ids, true ) ) {
                    error_log( 'JDPD v1.6.4 - MATCH: product_id ' . $product_id . ' found in rule products' );
                    return true;
                }

                // For variations, also check if parent product ID matches
                if ( $parent_id > 0 && in_array( $parent_id, $product_ids, true ) ) {
                    error_log( 'JDPD v1.6.4 - MATCH: parent_id ' . $parent_id . ' found in rule products' );
                    return true;
                }

                error_log( 'JDPD v1.6.4 - NO MATCH for product ' . $product_id );

                return false;

            case 'categories':
                $items = jdpd_get_rule_items( $this->id, 'category' );
                $category_ids = wp_list_pluck( $items, 'item_id' );
                $category_ids = array_map( 'intval', $category_ids );

                // For variations, get categories from parent
                if ( $parent_id > 0 ) {
                    $parent_product = wc_get_product( $parent_id );
                    if ( $parent_product ) {
                        $product_cats = $parent_product->get_category_ids();
                    } else {
                        $product_cats = array();
                    }
                } else {
                    $product_cats = $product->get_category_ids();
                }

                return ! empty( array_intersect( $category_ids, $product_cats ) );

            case 'tags':
                $items = jdpd_get_rule_items( $this->id, 'tag' );
                $tag_ids = wp_list_pluck( $items, 'item_id' );
                $tag_ids = array_map( 'intval', $tag_ids );

                // For variations, get tags from parent
                if ( $parent_id > 0 ) {
                    $parent_product = wc_get_product( $parent_id );
                    if ( $parent_product ) {
                        $product_tags = $parent_product->get_tag_ids();
                    } else {
                        $product_tags = array();
                    }
                } else {
                    $product_tags = $product->get_tag_ids();
                }

                return ! empty( array_intersect( $tag_ids, $product_tags ) );
        }

        return false;
    }

    /**
     * Save rule to database
     *
     * @return int|false Rule ID on success, false on failure.
     */
    public function save() {
        global $wpdb;

        $table = $wpdb->prefix . 'jdpd_rules';

        $data = array(
            'name'                 => $this->get( 'name' ),
            'rule_type'            => $this->get( 'rule_type' ),
            'status'               => $this->get( 'status' ),
            'priority'             => $this->get( 'priority' ),
            'discount_type'        => $this->get( 'discount_type' ),
            'discount_value'       => $this->get( 'discount_value' ),
            'apply_to'             => $this->get( 'apply_to' ),
            'conditions'           => wp_json_encode( $this->get( 'conditions' ) ),
            'schedule_from'        => $this->get( 'schedule_from' ) ?: null,
            'schedule_to'          => $this->get( 'schedule_to' ) ?: null,
            'usage_limit'          => $this->get( 'usage_limit' ),
            'exclusive'            => $this->get( 'exclusive' ) ? 1 : 0,
            'show_badge'           => $this->get( 'show_badge' ) ? 1 : 0,
            'badge_text'           => $this->get( 'badge_text' ),
            'special_offer_type'   => $this->get( 'special_offer_type' ),
            'event_type'           => $this->get( 'event_type' ),
            'custom_event_name'    => $this->get( 'custom_event_name' ),
            'event_discount_type'  => $this->get( 'event_discount_type' ),
            'event_discount_value' => $this->get( 'event_discount_value' ),
        );

        $format = array(
            '%s', '%s', '%s', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f',
        );

        // Debug logging
        error_log( 'JDPD v1.6.0 - Rule Save - special_offer_type: ' . ( $data['special_offer_type'] ?? 'NULL' ) . ', event_type: ' . ( $data['event_type'] ?? 'NULL' ) . ', Rule ID: ' . $this->id );

        if ( $this->id > 0 ) {
            // Update existing rule
            $data['updated_at'] = current_time( 'mysql' );
            $format[] = '%s';

            error_log( 'JDPD v1.6.0 - About to UPDATE rule ID: ' . $this->id . ' with event_type: ' . $data['event_type'] );

            $result = $wpdb->update(
                $table,
                $data,
                array( 'id' => $this->id ),
                $format,
                array( '%d' )
            );

            // Log result to PHP error log
            if ( $result === false ) {
                error_log( 'JDPD v1.6.0 - UPDATE FAILED! Error: ' . $wpdb->last_error );
                error_log( 'JDPD v1.6.0 - Failed query: ' . $wpdb->last_query );
            } else {
                error_log( 'JDPD v1.6.0 - UPDATE SUCCESS! Rows affected: ' . $result );
                // Verify the save
                $verify = $wpdb->get_var( $wpdb->prepare( "SELECT event_type FROM {$table} WHERE id = %d", $this->id ) );
                error_log( 'JDPD v1.6.0 - Verified event_type in DB: ' . var_export( $verify, true ) );
            }

            return $result !== false ? $this->id : false;
        } else {
            // Insert new rule
            $data['created_at'] = current_time( 'mysql' );
            $data['updated_at'] = current_time( 'mysql' );
            $data['created_by'] = get_current_user_id();
            $format[] = '%s';
            $format[] = '%s';
            $format[] = '%d';

            error_log( 'JDPD v1.6.0 - About to INSERT new rule with event_type: ' . $data['event_type'] );

            $result = $wpdb->insert( $table, $data, $format );

            if ( $result ) {
                $this->id = $wpdb->insert_id;
                error_log( 'JDPD v1.6.0 - INSERT SUCCESS! New rule ID: ' . $this->id );
                // Verify the insert
                $verify = $wpdb->get_var( $wpdb->prepare( "SELECT event_type FROM {$table} WHERE id = %d", $this->id ) );
                error_log( 'JDPD v1.6.0 - Verified event_type in DB after INSERT: ' . var_export( $verify, true ) );
                return $this->id;
            } else {
                error_log( 'JDPD v1.6.0 - INSERT FAILED! Error: ' . $wpdb->last_error );
                error_log( 'JDPD v1.6.0 - Failed INSERT query: ' . $wpdb->last_query );
            }

            return false;
        }
    }

    /**
     * Delete rule from database
     *
     * @return bool
     */
    public function delete() {
        global $wpdb;

        if ( ! $this->id ) {
            return false;
        }

        // Delete related data
        $wpdb->delete( $wpdb->prefix . 'jdpd_quantity_ranges', array( 'rule_id' => $this->id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'jdpd_rule_items', array( 'rule_id' => $this->id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'jdpd_exclusions', array( 'rule_id' => $this->id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'jdpd_gift_products', array( 'rule_id' => $this->id ), array( '%d' ) );

        // Delete the rule
        $result = $wpdb->delete(
            $wpdb->prefix . 'jdpd_rules',
            array( 'id' => $this->id ),
            array( '%d' )
        );

        return $result !== false;
    }

    /**
     * Duplicate the rule
     *
     * @return JDPD_Rule|false New rule object on success, false on failure.
     */
    public function duplicate() {
        global $wpdb;

        $new_rule = new self();
        $new_rule->data = $this->data;
        $new_rule->set( 'name', $this->get( 'name' ) . ' ' . __( '(Copy)', 'jezweb-dynamic-pricing' ) );
        $new_rule->set( 'status', 'inactive' );
        $new_rule->set( 'usage_count', 0 );
        $new_rule->set( 'created_at', '' );
        $new_rule->set( 'updated_at', '' );

        $new_id = $new_rule->save();

        if ( ! $new_id ) {
            return false;
        }

        // Duplicate quantity ranges
        $ranges = $this->get_quantity_ranges();
        foreach ( $ranges as $range ) {
            $wpdb->insert(
                $wpdb->prefix . 'jdpd_quantity_ranges',
                array(
                    'rule_id'        => $new_id,
                    'min_quantity'   => $range->min_quantity,
                    'max_quantity'   => $range->max_quantity,
                    'discount_type'  => $range->discount_type,
                    'discount_value' => $range->discount_value,
                ),
                array( '%d', '%d', '%d', '%s', '%f' )
            );
        }

        // Duplicate rule items
        $items = jdpd_get_rule_items( $this->id );
        foreach ( $items as $item ) {
            $wpdb->insert(
                $wpdb->prefix . 'jdpd_rule_items',
                array(
                    'rule_id'   => $new_id,
                    'item_type' => $item->item_type,
                    'item_id'   => $item->item_id,
                ),
                array( '%d', '%s', '%d' )
            );
        }

        // Duplicate exclusions
        $exclusions = $this->get_exclusions();
        foreach ( $exclusions as $exclusion ) {
            $wpdb->insert(
                $wpdb->prefix . 'jdpd_exclusions',
                array(
                    'rule_id'        => $new_id,
                    'exclusion_type' => $exclusion->exclusion_type,
                    'exclusion_id'   => $exclusion->exclusion_id,
                ),
                array( '%d', '%s', '%d' )
            );
        }

        // Duplicate gift products
        $gifts = $this->get_gift_products();
        foreach ( $gifts as $gift ) {
            $wpdb->insert(
                $wpdb->prefix . 'jdpd_gift_products',
                array(
                    'rule_id'        => $new_id,
                    'product_id'     => $gift->product_id,
                    'quantity'       => $gift->quantity,
                    'discount_type'  => $gift->discount_type,
                    'discount_value' => $gift->discount_value,
                ),
                array( '%d', '%d', '%d', '%s', '%f' )
            );
        }

        return new self( $new_id );
    }

    /**
     * Increment usage count
     */
    public function increment_usage() {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}jdpd_rules SET usage_count = usage_count + 1 WHERE id = %d",
                $this->id
            )
        );

        $this->data['usage_count']++;
    }

    /**
     * Record rule usage for an order
     *
     * @param int   $order_id        Order ID.
     * @param float $discount_amount Discount amount applied.
     */
    public function record_usage( $order_id, $discount_amount ) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'jdpd_rule_usage',
            array(
                'rule_id'         => $this->id,
                'order_id'        => $order_id,
                'user_id'         => get_current_user_id(),
                'discount_amount' => $discount_amount,
            ),
            array( '%d', '%d', '%d', '%f' )
        );

        $this->increment_usage();
    }
}
