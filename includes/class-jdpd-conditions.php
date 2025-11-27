<?php
/**
 * Conditions Handler
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conditions class
 */
class JDPD_Conditions {

    /**
     * Available condition types
     *
     * @var array
     */
    private $condition_types = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->condition_types = array(
            'user_role'        => __( 'User Role', 'jezweb-dynamic-pricing' ),
            'user_logged_in'   => __( 'User Logged In', 'jezweb-dynamic-pricing' ),
            'specific_user'    => __( 'Specific User', 'jezweb-dynamic-pricing' ),
            'cart_total'       => __( 'Cart Total', 'jezweb-dynamic-pricing' ),
            'cart_items'       => __( 'Cart Items Count', 'jezweb-dynamic-pricing' ),
            'cart_quantity'    => __( 'Cart Quantity', 'jezweb-dynamic-pricing' ),
            'total_spent'      => __( 'Customer Total Spent', 'jezweb-dynamic-pricing' ),
            'order_count'      => __( 'Customer Order Count', 'jezweb-dynamic-pricing' ),
            'product_in_cart'  => __( 'Product in Cart', 'jezweb-dynamic-pricing' ),
            'category_in_cart' => __( 'Category in Cart', 'jezweb-dynamic-pricing' ),
            'coupon_applied'   => __( 'Coupon Applied', 'jezweb-dynamic-pricing' ),
            'weekday'          => __( 'Day of Week', 'jezweb-dynamic-pricing' ),
            'time_range'       => __( 'Time Range', 'jezweb-dynamic-pricing' ),
        );
    }

    /**
     * Get condition types
     *
     * @return array
     */
    public function get_condition_types() {
        return apply_filters( 'jdpd_condition_types', $this->condition_types );
    }

    /**
     * Check all conditions for a rule
     *
     * @param JDPD_Rule $rule Rule object.
     * @return bool
     */
    public function check_rule_conditions( $rule ) {
        $conditions = $rule->get_conditions();

        if ( empty( $conditions ) ) {
            return true;
        }

        foreach ( $conditions as $condition ) {
            if ( ! $this->check_condition( $condition ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check a single condition
     *
     * @param array $condition Condition data.
     * @return bool
     */
    public function check_condition( $condition ) {
        if ( empty( $condition['type'] ) ) {
            return true;
        }

        $type = $condition['type'];
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? '';

        switch ( $type ) {
            case 'user_role':
                return $this->check_user_role( $value, $operator );

            case 'user_logged_in':
                return $this->check_user_logged_in( $value, $operator );

            case 'specific_user':
                return $this->check_specific_user( $value, $operator );

            case 'cart_total':
                return $this->check_cart_total( $value, $operator );

            case 'cart_items':
                return $this->check_cart_items( $value, $operator );

            case 'cart_quantity':
                return $this->check_cart_quantity( $value, $operator );

            case 'total_spent':
                return $this->check_total_spent( $value, $operator );

            case 'order_count':
                return $this->check_order_count( $value, $operator );

            case 'product_in_cart':
                return $this->check_product_in_cart( $value, $operator );

            case 'category_in_cart':
                return $this->check_category_in_cart( $value, $operator );

            case 'coupon_applied':
                return $this->check_coupon_applied( $value, $operator );

            case 'weekday':
                return $this->check_weekday( $value, $operator );

            case 'time_range':
                return $this->check_time_range( $value, $operator );

            default:
                return apply_filters( 'jdpd_check_condition_' . $type, true, $condition );
        }
    }

    /**
     * Check user role condition
     *
     * @param string $value    Expected role.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_user_role( $value, $operator ) {
        if ( ! is_user_logged_in() ) {
            return 'not_equals' === $operator;
        }

        $user = wp_get_current_user();
        $has_role = in_array( $value, $user->roles, true );

        return 'not_equals' === $operator ? ! $has_role : $has_role;
    }

    /**
     * Check user logged in condition
     *
     * @param string $value    Expected value (yes/no).
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_user_logged_in( $value, $operator ) {
        $is_logged_in = is_user_logged_in();
        $expected = 'yes' === $value;

        return 'not_equals' === $operator ? $is_logged_in !== $expected : $is_logged_in === $expected;
    }

    /**
     * Check specific user condition
     *
     * @param string $value    User ID or email.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_specific_user( $value, $operator ) {
        if ( ! is_user_logged_in() ) {
            return 'not_equals' === $operator;
        }

        $current_user = wp_get_current_user();

        // Check if value is user ID or email
        if ( is_numeric( $value ) ) {
            $matches = $current_user->ID == $value;
        } else {
            $matches = $current_user->user_email === $value;
        }

        return 'not_equals' === $operator ? ! $matches : $matches;
    }

    /**
     * Check cart total condition
     *
     * @param float  $value    Expected total.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_cart_total( $value, $operator ) {
        if ( ! WC()->cart ) {
            return false;
        }

        $cart_total = WC()->cart->get_subtotal();
        return $this->compare_values( $cart_total, floatval( $value ), $operator );
    }

    /**
     * Check cart items count condition
     *
     * @param int    $value    Expected count.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_cart_items( $value, $operator ) {
        if ( ! WC()->cart ) {
            return false;
        }

        $items_count = count( WC()->cart->get_cart() );
        return $this->compare_values( $items_count, intval( $value ), $operator );
    }

    /**
     * Check cart quantity condition
     *
     * @param int    $value    Expected quantity.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_cart_quantity( $value, $operator ) {
        if ( ! WC()->cart ) {
            return false;
        }

        $quantity = WC()->cart->get_cart_contents_count();
        return $this->compare_values( $quantity, intval( $value ), $operator );
    }

    /**
     * Check customer total spent condition
     *
     * @param float  $value    Expected total.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_total_spent( $value, $operator ) {
        if ( ! is_user_logged_in() ) {
            return 'less' === $operator || 'less_equal' === $operator;
        }

        $total_spent = jdpd_get_user_total_spent( get_current_user_id() );
        return $this->compare_values( $total_spent, floatval( $value ), $operator );
    }

    /**
     * Check customer order count condition
     *
     * @param int    $value    Expected count.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_order_count( $value, $operator ) {
        if ( ! is_user_logged_in() ) {
            return 'less' === $operator || 'less_equal' === $operator || 'equals' === $operator && $value == 0;
        }

        $order_count = jdpd_get_user_order_count( get_current_user_id() );
        return $this->compare_values( $order_count, intval( $value ), $operator );
    }

    /**
     * Check product in cart condition
     *
     * @param int    $value    Product ID.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_product_in_cart( $value, $operator ) {
        if ( ! WC()->cart ) {
            return 'not_equals' === $operator;
        }

        $product_ids = array_map(
            function( $item ) {
                return $item['product_id'];
            },
            WC()->cart->get_cart()
        );

        $in_cart = in_array( intval( $value ), $product_ids, true );
        return 'not_equals' === $operator ? ! $in_cart : $in_cart;
    }

    /**
     * Check category in cart condition
     *
     * @param int    $value    Category ID.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_category_in_cart( $value, $operator ) {
        if ( ! WC()->cart ) {
            return 'not_equals' === $operator;
        }

        $category_found = false;

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            if ( has_term( intval( $value ), 'product_cat', $product->get_id() ) ) {
                $category_found = true;
                break;
            }
        }

        return 'not_equals' === $operator ? ! $category_found : $category_found;
    }

    /**
     * Check coupon applied condition
     *
     * @param string $value    Coupon code.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_coupon_applied( $value, $operator ) {
        if ( ! WC()->cart ) {
            return 'not_equals' === $operator;
        }

        $coupons = WC()->cart->get_applied_coupons();
        $applied = in_array( strtolower( $value ), array_map( 'strtolower', $coupons ), true );

        return 'not_equals' === $operator ? ! $applied : $applied;
    }

    /**
     * Check weekday condition
     *
     * @param string $value    Expected day (1-7, Monday=1).
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_weekday( $value, $operator ) {
        $current_day = date( 'N' ); // 1 (Monday) to 7 (Sunday)
        return $this->compare_values( intval( $current_day ), intval( $value ), $operator );
    }

    /**
     * Check time range condition
     *
     * @param string $value    Time range (format: HH:MM-HH:MM).
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function check_time_range( $value, $operator ) {
        $parts = explode( '-', $value );
        if ( count( $parts ) !== 2 ) {
            return true;
        }

        $current_time = current_time( 'H:i' );
        $start_time = trim( $parts[0] );
        $end_time = trim( $parts[1] );

        $in_range = $current_time >= $start_time && $current_time <= $end_time;

        return 'not_equals' === $operator ? ! $in_range : $in_range;
    }

    /**
     * Compare two values based on operator
     *
     * @param mixed  $value1   First value.
     * @param mixed  $value2   Second value.
     * @param string $operator Comparison operator.
     * @return bool
     */
    private function compare_values( $value1, $value2, $operator ) {
        switch ( $operator ) {
            case 'equals':
                return $value1 == $value2;
            case 'not_equals':
                return $value1 != $value2;
            case 'greater':
                return $value1 > $value2;
            case 'less':
                return $value1 < $value2;
            case 'greater_equal':
                return $value1 >= $value2;
            case 'less_equal':
                return $value1 <= $value2;
            default:
                return true;
        }
    }

    /**
     * Get user roles for select
     *
     * @return array
     */
    public function get_user_roles() {
        global $wp_roles;

        $roles = array();
        foreach ( $wp_roles->roles as $key => $role ) {
            $roles[ $key ] = $role['name'];
        }

        return $roles;
    }

    /**
     * Get weekdays for select
     *
     * @return array
     */
    public function get_weekdays() {
        return array(
            '1' => __( 'Monday', 'jezweb-dynamic-pricing' ),
            '2' => __( 'Tuesday', 'jezweb-dynamic-pricing' ),
            '3' => __( 'Wednesday', 'jezweb-dynamic-pricing' ),
            '4' => __( 'Thursday', 'jezweb-dynamic-pricing' ),
            '5' => __( 'Friday', 'jezweb-dynamic-pricing' ),
            '6' => __( 'Saturday', 'jezweb-dynamic-pricing' ),
            '7' => __( 'Sunday', 'jezweb-dynamic-pricing' ),
        );
    }
}
