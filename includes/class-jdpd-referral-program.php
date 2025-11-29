<?php
/**
 * Referral Program functionality
 *
 * Provides referral discounts for customers who refer new buyers.
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JDPD_Referral_Program class.
 */
class JDPD_Referral_Program {

    /**
     * Instance
     *
     * @var JDPD_Referral_Program
     */
    private static $instance = null;

    /**
     * Table name for referrals
     *
     * @var string
     */
    private $table_name;

    /**
     * Table name for referral rewards
     *
     * @var string
     */
    private $rewards_table;

    /**
     * Settings
     *
     * @var array
     */
    private $settings;

    /**
     * Get instance
     *
     * @return JDPD_Referral_Program
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
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'jdpd_referrals';
        $this->rewards_table = $wpdb->prefix . 'jdpd_referral_rewards';
        $this->settings = $this->get_settings();

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Create tables on activation
        add_action( 'admin_init', array( $this, 'maybe_create_tables' ) );

        // Track referral clicks
        add_action( 'init', array( $this, 'track_referral_click' ) );

        // Save referral on order
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_referral_on_order' ), 10, 3 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'award_referral_reward' ) );

        // Apply referral discount
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_referee_discount' ) );

        // My Account page
        add_action( 'woocommerce_account_menu_items', array( $this, 'add_referral_menu_item' ) );
        add_action( 'init', array( $this, 'add_referral_endpoint' ) );
        add_action( 'woocommerce_account_referrals_endpoint', array( $this, 'display_referrals_page' ) );

        // Admin
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_referral_in_order' ) );

        // AJAX
        add_action( 'wp_ajax_jdpd_get_referral_link', array( $this, 'ajax_get_referral_link' ) );
        add_action( 'wp_ajax_jdpd_save_referral_settings', array( $this, 'ajax_save_settings' ) );

        // Shortcodes
        add_shortcode( 'jdpd_referral_link', array( $this, 'shortcode_referral_link' ) );
        add_shortcode( 'jdpd_referral_stats', array( $this, 'shortcode_referral_stats' ) );

        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Get settings
     *
     * @return array
     */
    private function get_settings() {
        return get_option( 'jdpd_referral_settings', array(
            'enabled'              => 'yes',
            'referrer_reward_type' => 'percentage', // percentage, fixed, points
            'referrer_reward'      => 10,
            'referee_discount_type'=> 'percentage', // percentage, fixed
            'referee_discount'     => 10,
            'min_order_total'      => 0,
            'cookie_days'          => 30,
            'require_new_customer' => 'yes',
            'max_referrals'        => 0, // 0 = unlimited
            'referral_param'       => 'ref',
            'email_notifications'  => 'yes',
        ) );
    }

    /**
     * Maybe create database tables
     */
    public function maybe_create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Referrals table
        $sql1 = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            referrer_id bigint(20) NOT NULL,
            referee_id bigint(20) DEFAULT NULL,
            referee_email varchar(255) DEFAULT NULL,
            order_id bigint(20) DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            referral_code varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            converted_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY referrer_id (referrer_id),
            KEY referee_id (referee_id),
            KEY referral_code (referral_code),
            KEY status (status)
        ) $charset_collate;";

        // Rewards table
        $sql2 = "CREATE TABLE IF NOT EXISTS {$this->rewards_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            referral_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            reward_type varchar(20) NOT NULL,
            reward_amount decimal(10,2) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            coupon_code varchar(50) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            claimed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY referral_id (referral_id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql1 );
        dbDelta( $sql2 );
    }

    /**
     * Get or generate referral code for user
     *
     * @param int $user_id User ID.
     * @return string
     */
    public function get_referral_code( $user_id ) {
        $code = get_user_meta( $user_id, '_jdpd_referral_code', true );

        if ( empty( $code ) ) {
            $code = $this->generate_referral_code( $user_id );
            update_user_meta( $user_id, '_jdpd_referral_code', $code );
        }

        return $code;
    }

    /**
     * Generate unique referral code
     *
     * @param int $user_id User ID.
     * @return string
     */
    private function generate_referral_code( $user_id ) {
        $user = get_user_by( 'id', $user_id );
        $base = $user ? sanitize_title( $user->display_name ) : 'ref';
        $code = strtoupper( substr( $base, 0, 4 ) ) . $user_id . strtoupper( wp_generate_password( 4, false ) );
        return $code;
    }

    /**
     * Get referral URL
     *
     * @param int $user_id User ID.
     * @return string
     */
    public function get_referral_url( $user_id ) {
        $code = $this->get_referral_code( $user_id );
        $param = $this->settings['referral_param'] ?? 'ref';
        return add_query_arg( $param, $code, home_url() );
    }

    /**
     * Track referral click from URL
     */
    public function track_referral_click() {
        $param = $this->settings['referral_param'] ?? 'ref';

        if ( ! isset( $_GET[ $param ] ) ) {
            return;
        }

        $code = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
        $referrer_id = $this->get_user_by_referral_code( $code );

        if ( ! $referrer_id ) {
            return;
        }

        // Don't track if same user
        if ( is_user_logged_in() && get_current_user_id() === $referrer_id ) {
            return;
        }

        // Set cookie
        $cookie_days = absint( $this->settings['cookie_days'] ?? 30 );
        $expiry = time() + ( $cookie_days * DAY_IN_SECONDS );
        setcookie( 'jdpd_referral_code', $code, $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

        // Track click
        $this->record_click( $referrer_id, $code );
    }

    /**
     * Get user by referral code
     *
     * @param string $code Referral code.
     * @return int|false
     */
    private function get_user_by_referral_code( $code ) {
        global $wpdb;

        $user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_jdpd_referral_code' AND meta_value = %s",
            $code
        ) );

        return $user_id ? absint( $user_id ) : false;
    }

    /**
     * Record referral click
     *
     * @param int    $referrer_id Referrer ID.
     * @param string $code        Referral code.
     */
    private function record_click( $referrer_id, $code ) {
        $clicks = get_user_meta( $referrer_id, '_jdpd_referral_clicks', true );
        $clicks = $clicks ? absint( $clicks ) + 1 : 1;
        update_user_meta( $referrer_id, '_jdpd_referral_clicks', $clicks );
    }

    /**
     * Save referral on order
     *
     * @param int   $order_id Order ID.
     * @param array $posted   Posted data.
     * @param object $order   Order object.
     */
    public function save_referral_on_order( $order_id, $posted, $order ) {
        if ( $this->settings['enabled'] !== 'yes' ) {
            return;
        }

        $code = isset( $_COOKIE['jdpd_referral_code'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['jdpd_referral_code'] ) ) : '';

        if ( empty( $code ) ) {
            return;
        }

        $referrer_id = $this->get_user_by_referral_code( $code );

        if ( ! $referrer_id ) {
            return;
        }

        // Don't allow self-referral
        $customer_id = $order->get_customer_id();
        if ( $customer_id && $customer_id === $referrer_id ) {
            return;
        }

        // Check if new customer required
        if ( $this->settings['require_new_customer'] === 'yes' && $customer_id ) {
            $order_count = wc_get_customer_order_count( $customer_id );
            if ( $order_count > 1 ) {
                return;
            }
        }

        // Check minimum order total
        $min_total = floatval( $this->settings['min_order_total'] ?? 0 );
        if ( $min_total > 0 && $order->get_total() < $min_total ) {
            return;
        }

        // Check max referrals limit
        $max_referrals = absint( $this->settings['max_referrals'] ?? 0 );
        if ( $max_referrals > 0 ) {
            $current_count = $this->get_referral_count( $referrer_id );
            if ( $current_count >= $max_referrals ) {
                return;
            }
        }

        // Save referral
        global $wpdb;
        $wpdb->insert(
            $this->table_name,
            array(
                'referrer_id'   => $referrer_id,
                'referee_id'    => $customer_id ?: null,
                'referee_email' => $order->get_billing_email(),
                'order_id'      => $order_id,
                'status'        => 'pending',
                'referral_code' => $code,
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s' )
        );

        $referral_id = $wpdb->insert_id;

        // Save to order meta
        $order->update_meta_data( '_jdpd_referral_id', $referral_id );
        $order->update_meta_data( '_jdpd_referrer_id', $referrer_id );
        $order->save();

        // Clear cookie
        setcookie( 'jdpd_referral_code', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
    }

    /**
     * Award referral reward when order is completed
     *
     * @param int $order_id Order ID.
     */
    public function award_referral_reward( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $referral_id = $order->get_meta( '_jdpd_referral_id' );
        $referrer_id = $order->get_meta( '_jdpd_referrer_id' );

        if ( ! $referral_id || ! $referrer_id ) {
            return;
        }

        // Check if already rewarded
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->rewards_table} WHERE referral_id = %d",
            $referral_id
        ) );

        if ( $existing ) {
            return;
        }

        // Update referral status
        $wpdb->update(
            $this->table_name,
            array(
                'status'       => 'completed',
                'converted_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $referral_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // Calculate reward
        $reward_type = $this->settings['referrer_reward_type'] ?? 'percentage';
        $reward_value = floatval( $this->settings['referrer_reward'] ?? 10 );

        if ( $reward_type === 'percentage' ) {
            $reward_amount = $order->get_subtotal() * ( $reward_value / 100 );
        } elseif ( $reward_type === 'points' ) {
            // Add to loyalty points if available
            if ( class_exists( 'JDPD_Loyalty_Points' ) ) {
                JDPD_Loyalty_Points::get_instance()->add_points( $referrer_id, $reward_value, 'referral', $order_id );
            }
            $reward_amount = $reward_value;
        } else {
            $reward_amount = $reward_value;
        }

        // Create reward
        $coupon_code = null;
        if ( $reward_type !== 'points' ) {
            $coupon_code = $this->create_reward_coupon( $referrer_id, $reward_amount, $reward_type );
        }

        $wpdb->insert(
            $this->rewards_table,
            array(
                'referral_id'   => $referral_id,
                'user_id'       => $referrer_id,
                'reward_type'   => $reward_type,
                'reward_amount' => $reward_amount,
                'status'        => $coupon_code ? 'available' : 'credited',
                'coupon_code'   => $coupon_code,
            ),
            array( '%d', '%d', '%s', '%f', '%s', '%s' )
        );

        // Send notification
        if ( $this->settings['email_notifications'] === 'yes' ) {
            $this->send_reward_notification( $referrer_id, $reward_amount, $reward_type, $coupon_code );
        }

        // Update stats
        $total_earnings = get_user_meta( $referrer_id, '_jdpd_referral_earnings', true );
        $total_earnings = $total_earnings ? floatval( $total_earnings ) : 0;
        update_user_meta( $referrer_id, '_jdpd_referral_earnings', $total_earnings + $reward_amount );
    }

    /**
     * Create reward coupon for referrer
     *
     * @param int    $user_id       User ID.
     * @param float  $amount        Amount.
     * @param string $discount_type Discount type.
     * @return string
     */
    private function create_reward_coupon( $user_id, $amount, $discount_type ) {
        $code = 'REFREW-' . strtoupper( wp_generate_password( 8, false ) );

        $coupon = new WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_description( sprintf( 'Referral reward for user #%d', $user_id ) );
        $coupon->set_discount_type( $discount_type === 'percentage' ? 'percent' : 'fixed_cart' );
        $coupon->set_amount( $amount );
        $coupon->set_usage_limit( 1 );
        $coupon->set_usage_limit_per_user( 1 );
        $coupon->set_email_restrictions( array( get_userdata( $user_id )->user_email ) );
        $coupon->set_date_expires( strtotime( '+90 days' ) );
        $coupon->save();

        return $code;
    }

    /**
     * Apply referee discount to cart
     *
     * @param WC_Cart $cart Cart object.
     */
    public function apply_referee_discount( $cart ) {
        if ( $this->settings['enabled'] !== 'yes' ) {
            return;
        }

        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $code = isset( $_COOKIE['jdpd_referral_code'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['jdpd_referral_code'] ) ) : '';

        if ( empty( $code ) ) {
            return;
        }

        $referrer_id = $this->get_user_by_referral_code( $code );
        if ( ! $referrer_id ) {
            return;
        }

        // Check if user is logged in and is the referrer
        if ( is_user_logged_in() && get_current_user_id() === $referrer_id ) {
            return;
        }

        // Check minimum order total
        $min_total = floatval( $this->settings['min_order_total'] ?? 0 );
        if ( $min_total > 0 && $cart->get_subtotal() < $min_total ) {
            return;
        }

        $discount_type = $this->settings['referee_discount_type'] ?? 'percentage';
        $discount_value = floatval( $this->settings['referee_discount'] ?? 10 );

        if ( $discount_type === 'percentage' ) {
            $discount = $cart->get_subtotal() * ( $discount_value / 100 );
            $label = sprintf( __( 'Referral Discount (%s%% off)', 'jezweb-dynamic-pricing' ), $discount_value );
        } else {
            $discount = min( $discount_value, $cart->get_subtotal() );
            $label = __( 'Referral Discount', 'jezweb-dynamic-pricing' );
        }

        if ( $discount > 0 ) {
            $cart->add_fee( $label, -$discount );
        }
    }

    /**
     * Get referral count for user
     *
     * @param int $user_id User ID.
     * @return int
     */
    public function get_referral_count( $user_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE referrer_id = %d AND status = 'completed'",
            $user_id
        ) );
    }

    /**
     * Get user referrals
     *
     * @param int $user_id User ID.
     * @return array
     */
    public function get_user_referrals( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, rw.reward_amount, rw.coupon_code, rw.status as reward_status
             FROM {$this->table_name} r
             LEFT JOIN {$this->rewards_table} rw ON r.id = rw.referral_id
             WHERE r.referrer_id = %d
             ORDER BY r.created_at DESC",
            $user_id
        ) );
    }

    /**
     * Get user stats
     *
     * @param int $user_id User ID.
     * @return array
     */
    public function get_user_stats( $user_id ) {
        global $wpdb;

        $clicks = get_user_meta( $user_id, '_jdpd_referral_clicks', true ) ?: 0;
        $earnings = get_user_meta( $user_id, '_jdpd_referral_earnings', true ) ?: 0;

        $pending = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE referrer_id = %d AND status = 'pending'",
            $user_id
        ) );

        $completed = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE referrer_id = %d AND status = 'completed'",
            $user_id
        ) );

        return array(
            'clicks'         => absint( $clicks ),
            'pending'        => absint( $pending ),
            'completed'      => absint( $completed ),
            'total_earnings' => floatval( $earnings ),
            'conversion_rate'=> $clicks > 0 ? round( ( $completed / $clicks ) * 100, 1 ) : 0,
        );
    }

    /**
     * Add referral menu item to My Account
     *
     * @param array $items Menu items.
     * @return array
     */
    public function add_referral_menu_item( $items ) {
        if ( $this->settings['enabled'] !== 'yes' ) {
            return $items;
        }

        $new_items = array();
        foreach ( $items as $key => $value ) {
            $new_items[ $key ] = $value;
            if ( $key === 'orders' ) {
                $new_items['referrals'] = __( 'Referrals', 'jezweb-dynamic-pricing' );
            }
        }
        return $new_items;
    }

    /**
     * Add referral endpoint
     */
    public function add_referral_endpoint() {
        add_rewrite_endpoint( 'referrals', EP_ROOT | EP_PAGES );
    }

    /**
     * Display referrals page in My Account
     */
    public function display_referrals_page() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $referral_url = $this->get_referral_url( $user_id );
        $stats = $this->get_user_stats( $user_id );
        $referrals = $this->get_user_referrals( $user_id );
        ?>
        <div class="jdpd-referral-dashboard">
            <h3><?php esc_html_e( 'Your Referral Link', 'jezweb-dynamic-pricing' ); ?></h3>
            <div class="jdpd-referral-link-box">
                <input type="text" readonly value="<?php echo esc_url( $referral_url ); ?>" id="jdpd-referral-url" />
                <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('jdpd-referral-url').value); this.textContent='Copied!';">
                    <?php esc_html_e( 'Copy', 'jezweb-dynamic-pricing' ); ?>
                </button>
            </div>

            <h3><?php esc_html_e( 'Your Stats', 'jezweb-dynamic-pricing' ); ?></h3>
            <div class="jdpd-referral-stats">
                <div class="stat-box">
                    <span class="stat-value"><?php echo esc_html( $stats['clicks'] ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Clicks', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-value"><?php echo esc_html( $stats['completed'] ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Successful Referrals', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-value"><?php echo esc_html( $stats['conversion_rate'] ); ?>%</span>
                    <span class="stat-label"><?php esc_html_e( 'Conversion Rate', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-value"><?php echo wc_price( $stats['total_earnings'] ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Total Earnings', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
            </div>

            <?php if ( ! empty( $referrals ) ) : ?>
            <h3><?php esc_html_e( 'Referral History', 'jezweb-dynamic-pricing' ); ?></h3>
            <table class="woocommerce-orders-table shop_table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'jezweb-dynamic-pricing' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'jezweb-dynamic-pricing' ); ?></th>
                        <th><?php esc_html_e( 'Reward', 'jezweb-dynamic-pricing' ); ?></th>
                        <th><?php esc_html_e( 'Coupon', 'jezweb-dynamic-pricing' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $referrals as $referral ) : ?>
                    <tr>
                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $referral->created_at ) ) ); ?></td>
                        <td>
                            <span class="referral-status status-<?php echo esc_attr( $referral->status ); ?>">
                                <?php echo esc_html( ucfirst( $referral->status ) ); ?>
                            </span>
                        </td>
                        <td><?php echo $referral->reward_amount ? wc_price( $referral->reward_amount ) : '-'; ?></td>
                        <td><?php echo $referral->coupon_code ? '<code>' . esc_html( $referral->coupon_code ) . '</code>' : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <style>
            .jdpd-referral-link-box { display: flex; gap: 10px; margin-bottom: 20px; }
            .jdpd-referral-link-box input { flex: 1; padding: 10px; }
            .jdpd-referral-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 30px; }
            .stat-box { background: #f8f9fa; padding: 20px; text-align: center; border-radius: 8px; }
            .stat-value { display: block; font-size: 24px; font-weight: bold; color: #2271b1; }
            .stat-label { display: block; font-size: 12px; color: #666; margin-top: 5px; }
            .referral-status { padding: 3px 8px; border-radius: 3px; font-size: 12px; }
            .status-pending { background: #fff3cd; color: #856404; }
            .status-completed { background: #d4edda; color: #155724; }
        </style>
        <?php
    }

    /**
     * Display referral info in admin order
     *
     * @param WC_Order $order Order object.
     */
    public function display_referral_in_order( $order ) {
        $referrer_id = $order->get_meta( '_jdpd_referrer_id' );
        if ( ! $referrer_id ) {
            return;
        }

        $referrer = get_user_by( 'id', $referrer_id );
        if ( ! $referrer ) {
            return;
        }

        echo '<p><strong>' . esc_html__( 'Referred by:', 'jezweb-dynamic-pricing' ) . '</strong> ';
        echo '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $referrer_id ) ) . '">';
        echo esc_html( $referrer->display_name ) . ' (#' . $referrer_id . ')';
        echo '</a></p>';
    }

    /**
     * Send reward notification email
     *
     * @param int    $user_id     User ID.
     * @param float  $amount      Reward amount.
     * @param string $type        Reward type.
     * @param string $coupon_code Coupon code.
     */
    private function send_reward_notification( $user_id, $amount, $type, $coupon_code ) {
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return;
        }

        $subject = sprintf( __( 'You earned a referral reward from %s!', 'jezweb-dynamic-pricing' ), get_bloginfo( 'name' ) );

        if ( $type === 'points' ) {
            $message = sprintf(
                __( "Hi %s,\n\nGreat news! Someone you referred just made a purchase, and you've earned %d loyalty points!\n\nKeep sharing your referral link to earn more rewards.\n\nThanks,\n%s", 'jezweb-dynamic-pricing' ),
                $user->display_name,
                $amount,
                get_bloginfo( 'name' )
            );
        } else {
            $message = sprintf(
                __( "Hi %s,\n\nGreat news! Someone you referred just made a purchase, and you've earned a reward!\n\nYour reward: %s\nCoupon code: %s\n\nUse this code on your next purchase. It's valid for 90 days.\n\nKeep sharing your referral link to earn more rewards.\n\nThanks,\n%s", 'jezweb-dynamic-pricing' ),
                $user->display_name,
                wc_price( $amount ),
                $coupon_code,
                get_bloginfo( 'name' )
            );
        }

        wp_mail( $user->user_email, $subject, $message );
    }

    /**
     * AJAX get referral link
     */
    public function ajax_get_referral_link() {
        check_ajax_referer( 'jdpd_referral_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $url = $this->get_referral_url( get_current_user_id() );
        wp_send_json_success( array( 'url' => $url ) );
    }

    /**
     * AJAX save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $settings = array(
            'enabled'              => sanitize_text_field( $_POST['enabled'] ?? 'yes' ),
            'referrer_reward_type' => sanitize_text_field( $_POST['referrer_reward_type'] ?? 'percentage' ),
            'referrer_reward'      => floatval( $_POST['referrer_reward'] ?? 10 ),
            'referee_discount_type'=> sanitize_text_field( $_POST['referee_discount_type'] ?? 'percentage' ),
            'referee_discount'     => floatval( $_POST['referee_discount'] ?? 10 ),
            'min_order_total'      => floatval( $_POST['min_order_total'] ?? 0 ),
            'cookie_days'          => absint( $_POST['cookie_days'] ?? 30 ),
            'require_new_customer' => sanitize_text_field( $_POST['require_new_customer'] ?? 'yes' ),
            'max_referrals'        => absint( $_POST['max_referrals'] ?? 0 ),
            'referral_param'       => sanitize_key( $_POST['referral_param'] ?? 'ref' ),
            'email_notifications'  => sanitize_text_field( $_POST['email_notifications'] ?? 'yes' ),
        );

        update_option( 'jdpd_referral_settings', $settings );
        $this->settings = $settings;

        wp_send_json_success();
    }

    /**
     * Shortcode: Referral link
     *
     * @param array $atts Attributes.
     * @return string
     */
    public function shortcode_referral_link( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to get your referral link.', 'jezweb-dynamic-pricing' ) . '</p>';
        }

        $url = $this->get_referral_url( get_current_user_id() );

        ob_start();
        ?>
        <div class="jdpd-referral-link-widget">
            <input type="text" readonly value="<?php echo esc_url( $url ); ?>" id="jdpd-ref-link" />
            <button type="button" onclick="navigator.clipboard.writeText('<?php echo esc_url( $url ); ?>'); this.textContent='Copied!';">
                <?php esc_html_e( 'Copy Link', 'jezweb-dynamic-pricing' ); ?>
            </button>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: Referral stats
     *
     * @param array $atts Attributes.
     * @return string
     */
    public function shortcode_referral_stats( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $stats = $this->get_user_stats( get_current_user_id() );

        ob_start();
        ?>
        <div class="jdpd-referral-stats-widget">
            <span><?php printf( esc_html__( '%d Referrals', 'jezweb-dynamic-pricing' ), $stats['completed'] ); ?></span>
            <span><?php printf( esc_html__( '%s Earned', 'jezweb-dynamic-pricing' ), wc_price( $stats['total_earnings'] ) ); ?></span>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        if ( ! is_account_page() ) {
            return;
        }

        wp_add_inline_style( 'woocommerce-general', '
            .jdpd-referral-link-widget { display: flex; gap: 10px; }
            .jdpd-referral-link-widget input { flex: 1; padding: 8px; }
            .jdpd-referral-stats-widget { display: flex; gap: 20px; }
        ' );
    }
}

// Initialize
JDPD_Referral_Program::get_instance();
