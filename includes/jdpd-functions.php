<?php
/**
 * Helper functions
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get all active rules
 *
 * @param string $type Rule type (price_rule, cart_rule, special_offer, gift).
 * @return array
 */
function jdpd_get_active_rules( $type = '' ) {
    global $wpdb;

    $table = $wpdb->prefix . 'jdpd_rules';

    $where = "WHERE status = 'active'";

    if ( ! empty( $type ) ) {
        $where .= $wpdb->prepare( ' AND rule_type = %s', $type );
    }

    // Add schedule check
    $where .= " AND (schedule_from IS NULL OR schedule_from <= NOW())";
    $where .= " AND (schedule_to IS NULL OR schedule_to >= NOW())";

    $rules = $wpdb->get_results(
        "SELECT * FROM $table $where ORDER BY priority ASC, id ASC"
    );

    return $rules ? $rules : array();
}

/**
 * Get a single rule by ID
 *
 * @param int $rule_id Rule ID.
 * @return object|null
 */
function jdpd_get_rule( $rule_id ) {
    global $wpdb;

    $table = $wpdb->prefix . 'jdpd_rules';

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $rule_id )
    );
}

/**
 * Get quantity ranges for a rule
 *
 * @param int $rule_id Rule ID.
 * @return array
 */
function jdpd_get_quantity_ranges( $rule_id ) {
    global $wpdb;

    $table = $wpdb->prefix . 'jdpd_quantity_ranges';

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table WHERE rule_id = %d ORDER BY min_quantity ASC",
            $rule_id
        )
    );
}

/**
 * Get rule items (products, categories, tags)
 *
 * @param int    $rule_id   Rule ID.
 * @param string $item_type Item type.
 * @return array
 */
function jdpd_get_rule_items( $rule_id, $item_type = '' ) {
    global $wpdb;

    $table = $wpdb->prefix . 'jdpd_rule_items';

    $where = $wpdb->prepare( 'WHERE rule_id = %d', $rule_id );

    if ( ! empty( $item_type ) ) {
        $where .= $wpdb->prepare( ' AND item_type = %s', $item_type );
    }

    return $wpdb->get_results( "SELECT * FROM $table $where" );
}

/**
 * Get rule exclusions
 *
 * @param int    $rule_id        Rule ID.
 * @param string $exclusion_type Exclusion type.
 * @return array
 */
function jdpd_get_rule_exclusions( $rule_id, $exclusion_type = '' ) {
    global $wpdb;

    $table = $wpdb->prefix . 'jdpd_exclusions';

    $where = $wpdb->prepare( 'WHERE rule_id = %d', $rule_id );

    if ( ! empty( $exclusion_type ) ) {
        $where .= $wpdb->prepare( ' AND exclusion_type = %s', $exclusion_type );
    }

    return $wpdb->get_results( "SELECT * FROM $table $where" );
}

/**
 * Get gift products for a rule
 *
 * @param int $rule_id Rule ID.
 * @return array
 */
function jdpd_get_gift_products( $rule_id ) {
    global $wpdb;

    $table = $wpdb->prefix . 'jdpd_gift_products';

    return $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM $table WHERE rule_id = %d", $rule_id )
    );
}

/**
 * Check if product is excluded from discounts
 *
 * @param int $product_id Product ID.
 * @param int $rule_id    Rule ID (optional, check all rules if not provided).
 * @return bool
 */
function jdpd_is_product_excluded( $product_id, $rule_id = 0 ) {
    global $wpdb;

    $table = $wpdb->prefix . 'jdpd_exclusions';

    $where = "WHERE exclusion_type = 'product' AND exclusion_id = %d";
    $args = array( $product_id );

    if ( $rule_id > 0 ) {
        $where .= ' AND rule_id = %d';
        $args[] = $rule_id;
    }

    $result = $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM $table $where", $args )
    );

    return $result > 0;
}

/**
 * Calculate discount amount
 *
 * @param float  $price         Original price.
 * @param string $discount_type Discount type (percentage, fixed, fixed_price).
 * @param float  $discount_value Discount value.
 * @return float
 */
function jdpd_calculate_discount( $price, $discount_type, $discount_value ) {
    $discount = 0;

    switch ( $discount_type ) {
        case 'percentage':
            $discount = $price * ( $discount_value / 100 );
            break;

        case 'fixed':
            $discount = $discount_value;
            break;

        case 'fixed_price':
            $discount = $price - $discount_value;
            break;
    }

    // Ensure discount doesn't exceed price
    return min( $discount, $price );
}

/**
 * Get discounted price
 *
 * @param float  $price         Original price.
 * @param string $discount_type Discount type.
 * @param float  $discount_value Discount value.
 * @return float
 */
function jdpd_get_discounted_price( $price, $discount_type, $discount_value ) {
    $discount = jdpd_calculate_discount( $price, $discount_type, $discount_value );
    return max( $price - $discount, 0 );
}

/**
 * Format price with currency
 *
 * @param float $price Price to format.
 * @return string
 */
function jdpd_format_price( $price ) {
    return wc_price( $price );
}

/**
 * Get discount types
 *
 * @return array
 */
function jdpd_get_discount_types() {
    return array(
        'percentage'  => __( 'Percentage discount', 'jezweb-dynamic-pricing' ),
        'fixed'       => __( 'Fixed discount per item', 'jezweb-dynamic-pricing' ),
        'fixed_price' => __( 'Fixed price per item', 'jezweb-dynamic-pricing' ),
    );
}

/**
 * Get rule types
 *
 * @return array
 */
function jdpd_get_rule_types() {
    return array(
        'price_rule'    => __( 'Price Rule', 'jezweb-dynamic-pricing' ),
        'cart_rule'     => __( 'Cart Rule', 'jezweb-dynamic-pricing' ),
        'special_offer' => __( 'Special Offer', 'jezweb-dynamic-pricing' ),
        'gift'          => __( 'Gift Product', 'jezweb-dynamic-pricing' ),
    );
}

/**
 * Get apply to options
 *
 * @return array
 */
function jdpd_get_apply_to_options() {
    return array(
        'all_products'     => __( 'All products', 'jezweb-dynamic-pricing' ),
        'specific_products' => __( 'Specific products', 'jezweb-dynamic-pricing' ),
        'categories'       => __( 'Product categories', 'jezweb-dynamic-pricing' ),
        'tags'             => __( 'Product tags', 'jezweb-dynamic-pricing' ),
    );
}

/**
 * Get special offer types
 *
 * @return array
 */
function jdpd_get_special_offer_types() {
    return array(
        'buy_x_get_y'      => __( 'Buy X Get Y', 'jezweb-dynamic-pricing' ),
        'bogo'             => __( 'Buy One Get One (BOGO)', 'jezweb-dynamic-pricing' ),
        'buy_x_for_y'      => __( 'Buy X for Y price', 'jezweb-dynamic-pricing' ),
        'x_for_price_of_y' => __( 'X for the price of Y', 'jezweb-dynamic-pricing' ),
        'event_sale'       => __( 'Special Event Sale', 'jezweb-dynamic-pricing' ),
    );
}

/**
 * Get special retail events (Australian calendar)
 *
 * @return array
 */
function jdpd_get_special_events() {
    return array(
        'new_year_sale' => array(
            'name'       => __( 'New Year\'s Day Sale', 'jezweb-dynamic-pricing' ),
            'month'      => 1,
            'categories' => __( 'Fitness, Home, General Retail', 'jezweb-dynamic-pricing' ),
        ),
        'back_to_school' => array(
            'name'       => __( 'Back to School Sales', 'jezweb-dynamic-pricing' ),
            'month'      => 1,
            'categories' => __( 'Laptops, Stationery, Kids\' Shoes', 'jezweb-dynamic-pricing' ),
        ),
        'afterpay_day' => array(
            'name'       => __( 'Afterpay Day', 'jezweb-dynamic-pricing' ),
            'month'      => 3,
            'categories' => __( 'Fashion, Beauty, General Retail', 'jezweb-dynamic-pricing' ),
        ),
        'easter_sales' => array(
            'name'       => __( 'Easter Sales', 'jezweb-dynamic-pricing' ),
            'month'      => 4,
            'categories' => __( 'Chocolate, Camping Gear, DIY Tools', 'jezweb-dynamic-pricing' ),
        ),
        'click_frenzy_mayhem' => array(
            'name'       => __( 'Click Frenzy Mayhem', 'jezweb-dynamic-pricing' ),
            'month'      => 5,
            'categories' => __( 'Tech, Travel, General Retail', 'jezweb-dynamic-pricing' ),
        ),
        'vosn' => array(
            'name'       => __( 'Vogue Online Shopping Night (VOSN)', 'jezweb-dynamic-pricing' ),
            'month'      => 9,
            'categories' => __( 'Designer Fashion, Beauty, Luxury', 'jezweb-dynamic-pricing' ),
        ),
        'eofy_sales' => array(
            'name'       => __( 'EOFY Sales (End of Financial Year)', 'jezweb-dynamic-pricing' ),
            'month'      => 6,
            'categories' => __( 'Office Tech, Work Vehicles, Furniture', 'jezweb-dynamic-pricing' ),
        ),
        'amazon_prime_day' => array(
            'name'       => __( 'Amazon Prime Day', 'jezweb-dynamic-pricing' ),
            'month'      => 7,
            'categories' => __( 'Smart Home, Electronics, Consumables', 'jezweb-dynamic-pricing' ),
        ),
        'click_frenzy_main' => array(
            'name'       => __( 'Click Frenzy (The Main Event)', 'jezweb-dynamic-pricing' ),
            'month'      => 11,
            'categories' => __( 'All Categories, Pre-Black Friday', 'jezweb-dynamic-pricing' ),
        ),
        'singles_day' => array(
            'name'       => __( 'Singles\' Day (11.11)', 'jezweb-dynamic-pricing' ),
            'month'      => 11,
            'categories' => __( 'Fashion, Electronics, General Retail', 'jezweb-dynamic-pricing' ),
        ),
        'black_friday' => array(
            'name'       => __( 'Black Friday', 'jezweb-dynamic-pricing' ),
            'month'      => 11,
            'categories' => __( 'Electronics, Gifts, Everything', 'jezweb-dynamic-pricing' ),
        ),
        'cyber_monday' => array(
            'name'       => __( 'Cyber Monday', 'jezweb-dynamic-pricing' ),
            'month'      => 11,
            'categories' => __( 'Online Deals, Tech, Fashion', 'jezweb-dynamic-pricing' ),
        ),
        'green_monday' => array(
            'name'       => __( 'Green Monday', 'jezweb-dynamic-pricing' ),
            'month'      => 12,
            'categories' => __( 'Last-Minute Gifts, Electronics', 'jezweb-dynamic-pricing' ),
        ),
        'boxing_day' => array(
            'name'       => __( 'Boxing Day Sales', 'jezweb-dynamic-pricing' ),
            'month'      => 12,
            'categories' => __( 'Summer Clothes, Large Appliances', 'jezweb-dynamic-pricing' ),
        ),
        'custom' => array(
            'name'       => __( 'Custom Event', 'jezweb-dynamic-pricing' ),
            'month'      => 0,
            'categories' => __( 'Define your own special event', 'jezweb-dynamic-pricing' ),
        ),
    );
}

/**
 * Get special event by key
 *
 * @param string $event_key Event key.
 * @return array|null
 */
function jdpd_get_special_event( $event_key ) {
    $events = jdpd_get_special_events();
    return isset( $events[ $event_key ] ) ? $events[ $event_key ] : null;
}

/**
 * Check if current user can manage rules
 *
 * @return bool
 */
function jdpd_current_user_can_manage() {
    if ( current_user_can( 'manage_woocommerce' ) ) {
        return true;
    }

    if ( current_user_can( 'shop_manager' ) && 'yes' === get_option( 'jdpd_shop_manager_access', 'yes' ) ) {
        return true;
    }

    return false;
}

/**
 * Get user's total spent
 *
 * @param int $user_id User ID.
 * @return float
 */
function jdpd_get_user_total_spent( $user_id ) {
    $customer = new WC_Customer( $user_id );
    return $customer->get_total_spent();
}

/**
 * Get user's order count
 *
 * @param int $user_id User ID.
 * @return int
 */
function jdpd_get_user_order_count( $user_id ) {
    $customer = new WC_Customer( $user_id );
    return $customer->get_order_count();
}
/**
 * Get cart subtotal
 *
 * @param bool $include_tax Include tax in calculation.
 * @return float
 */
function jdpd_get_cart_subtotal( $include_tax = true ) {
    if ( ! WC()->cart ) {
        return 0;
    }

    if ( $include_tax ) {
        return WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();
    }

    return WC()->cart->get_subtotal();
}

/**
 * Get cart item count
 *
 * @return int
 */
function jdpd_get_cart_item_count() {
    if ( ! WC()->cart ) {
        return 0;
    }

    return WC()->cart->get_cart_contents_count();
}

/**
 * Check if a product is on sale (WooCommerce native)
 *
 * @param WC_Product $product Product object.
 * @return bool
 */
function jdpd_product_is_on_sale( $product ) {
    return $product->is_on_sale();
}

/**
 * Sanitize rule data
 *
 * @param array $data Raw rule data.
 * @return array
 */
function jdpd_sanitize_rule_data( $data ) {
    $sanitized = array();

    if ( isset( $data['name'] ) ) {
        $sanitized['name'] = sanitize_text_field( $data['name'] );
    }

    if ( isset( $data['rule_type'] ) ) {
        $sanitized['rule_type'] = sanitize_key( $data['rule_type'] );
    }

    if ( isset( $data['status'] ) ) {
        $sanitized['status'] = sanitize_key( $data['status'] );
    }

    if ( isset( $data['priority'] ) ) {
        $sanitized['priority'] = absint( $data['priority'] );
    }

    if ( isset( $data['discount_type'] ) ) {
        $sanitized['discount_type'] = sanitize_key( $data['discount_type'] );
    }

    if ( isset( $data['discount_value'] ) ) {
        $sanitized['discount_value'] = floatval( $data['discount_value'] );
    }

    if ( isset( $data['apply_to'] ) ) {
        $sanitized['apply_to'] = sanitize_key( $data['apply_to'] );
    }

    if ( isset( $data['conditions'] ) ) {
        $sanitized['conditions'] = wp_json_encode( $data['conditions'] );
    }

    if ( isset( $data['schedule_from'] ) && ! empty( $data['schedule_from'] ) ) {
        $sanitized['schedule_from'] = sanitize_text_field( $data['schedule_from'] );
    }

    if ( isset( $data['schedule_to'] ) && ! empty( $data['schedule_to'] ) ) {
        $sanitized['schedule_to'] = sanitize_text_field( $data['schedule_to'] );
    }

    if ( isset( $data['usage_limit'] ) ) {
        $sanitized['usage_limit'] = absint( $data['usage_limit'] );
    }

    if ( isset( $data['exclusive'] ) ) {
        $sanitized['exclusive'] = absint( $data['exclusive'] );
    }

    if ( isset( $data['show_badge'] ) ) {
        $sanitized['show_badge'] = absint( $data['show_badge'] );
    }

    if ( isset( $data['badge_text'] ) ) {
        $sanitized['badge_text'] = sanitize_text_field( $data['badge_text'] );
    }

    return $sanitized;
}
