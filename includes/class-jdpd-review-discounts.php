<?php
/**
 * Review/Rating Based Discounts
 *
 * Reward customers for leaving product reviews with discounts.
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JDPD_Review_Discounts class.
 */
class JDPD_Review_Discounts {

    /**
     * Instance
     *
     * @var JDPD_Review_Discounts
     */
    private static $instance = null;

    /**
     * Settings
     *
     * @var array
     */
    private $settings;

    /**
     * Get instance
     *
     * @return JDPD_Review_Discounts
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
        $this->settings = $this->get_settings();
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Process approved review
        add_action( 'comment_post', array( $this, 'process_new_review' ), 10, 3 );
        add_action( 'wp_set_comment_status', array( $this, 'process_approved_review' ), 10, 2 );

        // Apply rating-based discounts
        add_filter( 'woocommerce_product_get_price', array( $this, 'apply_rating_discount' ), 20, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'apply_rating_discount' ), 20, 2 );

        // Display review incentive
        add_action( 'woocommerce_after_single_product_summary', array( $this, 'display_review_incentive' ), 5 );
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_review_reminder' ) );

        // My Account
        add_action( 'woocommerce_account_dashboard', array( $this, 'display_pending_reviews' ) );

        // AJAX
        add_action( 'wp_ajax_jdpd_save_review_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_jdpd_get_review_stats', array( $this, 'ajax_get_stats' ) );

        // Email
        add_action( 'jdpd_send_review_reminder_emails', array( $this, 'send_review_reminders' ) );
        if ( ! wp_next_scheduled( 'jdpd_send_review_reminder_emails' ) ) {
            wp_schedule_event( time(), 'daily', 'jdpd_send_review_reminder_emails' );
        }

        // Shortcode
        add_shortcode( 'jdpd_review_discount_info', array( $this, 'shortcode_review_info' ) );
    }

    /**
     * Get settings
     *
     * @return array
     */
    private function get_settings() {
        return get_option( 'jdpd_review_discount_settings', array(
            'enabled'                => 'yes',
            'reward_type'            => 'coupon', // coupon, points, immediate
            'reward_amount'          => 10,
            'reward_discount_type'   => 'percentage', // percentage, fixed
            'require_photo'          => 'no',
            'require_verified'       => 'yes',
            'min_rating'             => 1,
            'min_review_length'      => 20,
            'max_rewards_per_user'   => 0, // 0 = unlimited
            'coupon_expiry_days'     => 30,
            'rating_discount_enabled'=> 'no',
            'rating_tiers'           => array(
                array( 'min_rating' => 4.5, 'discount' => 10 ),
                array( 'min_rating' => 4.0, 'discount' => 5 ),
                array( 'min_rating' => 3.5, 'discount' => 2 ),
            ),
            'email_reminder_enabled' => 'yes',
            'email_reminder_days'    => 7,
        ) );
    }

    /**
     * Process new review
     *
     * @param int        $comment_id       Comment ID.
     * @param int|string $comment_approved Approval status.
     * @param array      $comment_data     Comment data.
     */
    public function process_new_review( $comment_id, $comment_approved, $comment_data ) {
        if ( $comment_data['comment_type'] !== 'review' ) {
            return;
        }

        // If immediately approved
        if ( 1 === $comment_approved ) {
            $this->award_review_reward( $comment_id );
        }
    }

    /**
     * Process when review is approved
     *
     * @param int    $comment_id     Comment ID.
     * @param string $comment_status New status.
     */
    public function process_approved_review( $comment_id, $comment_status ) {
        if ( $comment_status !== 'approve' ) {
            return;
        }

        $comment = get_comment( $comment_id );
        if ( ! $comment || $comment->comment_type !== 'review' ) {
            return;
        }

        $this->award_review_reward( $comment_id );
    }

    /**
     * Award reward for approved review
     *
     * @param int $comment_id Comment ID.
     */
    private function award_review_reward( $comment_id ) {
        if ( $this->settings['enabled'] !== 'yes' ) {
            return;
        }

        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            return;
        }

        // Check if already rewarded
        if ( get_comment_meta( $comment_id, '_jdpd_review_rewarded', true ) ) {
            return;
        }

        $user_id = $comment->user_id;
        $product_id = $comment->comment_post_ID;

        // Check verified purchase requirement
        if ( $this->settings['require_verified'] === 'yes' ) {
            if ( ! wc_customer_bought_product( $comment->comment_author_email, $user_id, $product_id ) ) {
                return;
            }
        }

        // Check rating
        $rating = get_comment_meta( $comment_id, 'rating', true );
        if ( $rating < $this->settings['min_rating'] ) {
            return;
        }

        // Check review length
        if ( strlen( $comment->comment_content ) < $this->settings['min_review_length'] ) {
            return;
        }

        // Check photo requirement
        if ( $this->settings['require_photo'] === 'yes' ) {
            $has_photo = get_comment_meta( $comment_id, '_review_photo', true );
            if ( ! $has_photo ) {
                return;
            }
        }

        // Check max rewards per user
        if ( $user_id && $this->settings['max_rewards_per_user'] > 0 ) {
            $reward_count = get_user_meta( $user_id, '_jdpd_review_reward_count', true ) ?: 0;
            if ( $reward_count >= $this->settings['max_rewards_per_user'] ) {
                return;
            }
        }

        // Award based on type
        $reward_type = $this->settings['reward_type'];
        $reward_amount = floatval( $this->settings['reward_amount'] );
        $discount_type = $this->settings['reward_discount_type'];

        if ( $reward_type === 'points' && class_exists( 'JDPD_Loyalty_Points' ) ) {
            JDPD_Loyalty_Points::get_instance()->add_points( $user_id, $reward_amount, 'review', $product_id );
            $reward_info = sprintf( __( '%d loyalty points', 'jezweb-dynamic-pricing' ), $reward_amount );
        } elseif ( $reward_type === 'coupon' ) {
            $coupon_code = $this->create_review_coupon( $user_id ?: $comment->comment_author_email, $reward_amount, $discount_type );
            $reward_info = $coupon_code;
            update_comment_meta( $comment_id, '_jdpd_review_coupon', $coupon_code );
        } else {
            // Immediate discount - store for next order
            if ( $user_id ) {
                $pending = get_user_meta( $user_id, '_jdpd_pending_review_discount', true ) ?: 0;
                update_user_meta( $user_id, '_jdpd_pending_review_discount', $pending + $reward_amount );
            }
            $reward_info = sprintf( __( '%s off next order', 'jezweb-dynamic-pricing' ),
                $discount_type === 'percentage' ? $reward_amount . '%' : wc_price( $reward_amount ) );
        }

        // Mark as rewarded
        update_comment_meta( $comment_id, '_jdpd_review_rewarded', current_time( 'mysql' ) );
        update_comment_meta( $comment_id, '_jdpd_review_reward_info', $reward_info );

        // Update user stats
        if ( $user_id ) {
            $count = get_user_meta( $user_id, '_jdpd_review_reward_count', true ) ?: 0;
            update_user_meta( $user_id, '_jdpd_review_reward_count', $count + 1 );
        }

        // Send notification email
        $this->send_reward_notification( $comment, $reward_info, $reward_type );
    }

    /**
     * Create coupon for review reward
     *
     * @param int|string $user_id_or_email User ID or email.
     * @param float      $amount           Discount amount.
     * @param string     $discount_type    Discount type.
     * @return string
     */
    private function create_review_coupon( $user_id_or_email, $amount, $discount_type ) {
        $code = 'REVIEW-' . strtoupper( wp_generate_password( 8, false ) );

        $email = is_numeric( $user_id_or_email )
            ? get_userdata( $user_id_or_email )->user_email
            : $user_id_or_email;

        $coupon = new WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_description( __( 'Review reward coupon', 'jezweb-dynamic-pricing' ) );
        $coupon->set_discount_type( $discount_type === 'percentage' ? 'percent' : 'fixed_cart' );
        $coupon->set_amount( $amount );
        $coupon->set_usage_limit( 1 );
        $coupon->set_usage_limit_per_user( 1 );
        $coupon->set_email_restrictions( array( $email ) );

        $expiry_days = absint( $this->settings['coupon_expiry_days'] );
        if ( $expiry_days > 0 ) {
            $coupon->set_date_expires( strtotime( "+{$expiry_days} days" ) );
        }

        $coupon->save();

        return $code;
    }

    /**
     * Apply rating-based discount to products
     *
     * @param float      $price   Price.
     * @param WC_Product $product Product.
     * @return float
     */
    public function apply_rating_discount( $price, $product ) {
        if ( $this->settings['rating_discount_enabled'] !== 'yes' ) {
            return $price;
        }

        if ( is_admin() && ! wp_doing_ajax() ) {
            return $price;
        }

        $rating = $product->get_average_rating();
        if ( ! $rating ) {
            return $price;
        }

        $tiers = $this->settings['rating_tiers'] ?? array();
        foreach ( $tiers as $tier ) {
            if ( $rating >= floatval( $tier['min_rating'] ) ) {
                $discount = floatval( $tier['discount'] );
                return $price * ( 1 - ( $discount / 100 ) );
            }
        }

        return $price;
    }

    /**
     * Display review incentive on product page
     */
    public function display_review_incentive() {
        if ( $this->settings['enabled'] !== 'yes' ) {
            return;
        }

        global $product;
        if ( ! $product ) {
            return;
        }

        $reward_amount = $this->settings['reward_amount'];
        $discount_type = $this->settings['reward_discount_type'];
        $reward_type = $this->settings['reward_type'];

        if ( $reward_type === 'points' ) {
            $reward_text = sprintf( __( 'Earn %d loyalty points', 'jezweb-dynamic-pricing' ), $reward_amount );
        } else {
            $reward_text = sprintf(
                __( 'Get %s off', 'jezweb-dynamic-pricing' ),
                $discount_type === 'percentage' ? $reward_amount . '%' : wc_price( $reward_amount )
            );
        }

        ?>
        <div class="jdpd-review-incentive">
            <span class="incentive-icon">⭐</span>
            <span class="incentive-text">
                <?php printf(
                    esc_html__( '%s when you leave a review for this product!', 'jezweb-dynamic-pricing' ),
                    '<strong>' . esc_html( $reward_text ) . '</strong>'
                ); ?>
            </span>
        </div>
        <style>
            .jdpd-review-incentive {
                background: linear-gradient(135deg, #fff9e6, #fff3cd);
                border: 1px solid #ffc107;
                border-radius: 8px;
                padding: 15px;
                margin: 20px 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .incentive-icon { font-size: 24px; }
            .incentive-text { flex: 1; }
        </style>
        <?php
    }

    /**
     * Display review reminder on order details
     *
     * @param WC_Order $order Order object.
     */
    public function display_review_reminder( $order ) {
        if ( $this->settings['enabled'] !== 'yes' ) {
            return;
        }

        if ( $order->get_status() !== 'completed' ) {
            return;
        }

        // Get products without reviews
        $products_to_review = array();
        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();

            // Check if user already reviewed
            $existing = get_comments( array(
                'post_id' => $product_id,
                'user_id' => $order->get_customer_id(),
                'type'    => 'review',
                'count'   => true,
            ) );

            if ( ! $existing ) {
                $products_to_review[] = wc_get_product( $product_id );
            }
        }

        if ( empty( $products_to_review ) ) {
            return;
        }

        $reward_amount = $this->settings['reward_amount'];
        $discount_type = $this->settings['reward_discount_type'];
        ?>
        <div class="jdpd-review-reminder">
            <h3><?php esc_html_e( 'Review Your Products & Earn Rewards!', 'jezweb-dynamic-pricing' ); ?></h3>
            <p>
                <?php printf(
                    esc_html__( 'Leave a review and get %s off your next order.', 'jezweb-dynamic-pricing' ),
                    $discount_type === 'percentage' ? $reward_amount . '%' : wc_price( $reward_amount )
                ); ?>
            </p>
            <ul>
                <?php foreach ( $products_to_review as $product ) : ?>
                <li>
                    <a href="<?php echo esc_url( $product->get_permalink() ); ?>#reviews">
                        <?php echo esc_html( $product->get_name() ); ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <style>
            .jdpd-review-reminder {
                background: #f0f8ff;
                border: 1px solid #2271b1;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
            }
            .jdpd-review-reminder h3 { margin-top: 0; color: #2271b1; }
            .jdpd-review-reminder ul { margin-bottom: 0; }
        </style>
        <?php
    }

    /**
     * Display pending reviews in My Account
     */
    public function display_pending_reviews() {
        if ( $this->settings['enabled'] !== 'yes' ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();

        // Get completed orders
        $orders = wc_get_orders( array(
            'customer_id' => $user_id,
            'status'      => 'completed',
            'limit'       => 10,
        ) );

        $products_to_review = array();
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();

                // Check if already reviewed
                $existing = get_comments( array(
                    'post_id' => $product_id,
                    'user_id' => $user_id,
                    'type'    => 'review',
                    'count'   => true,
                ) );

                if ( ! $existing && ! isset( $products_to_review[ $product_id ] ) ) {
                    $products_to_review[ $product_id ] = wc_get_product( $product_id );
                }
            }
        }

        if ( empty( $products_to_review ) ) {
            return;
        }

        $reward_text = $this->settings['reward_discount_type'] === 'percentage'
            ? $this->settings['reward_amount'] . '%'
            : wc_price( $this->settings['reward_amount'] );
        ?>
        <div class="jdpd-pending-reviews">
            <h3><?php esc_html_e( 'Products Awaiting Your Review', 'jezweb-dynamic-pricing' ); ?></h3>
            <p><?php printf( esc_html__( 'Review these products and earn %s off each!', 'jezweb-dynamic-pricing' ), $reward_text ); ?></p>
            <div class="review-products-grid">
                <?php foreach ( array_slice( $products_to_review, 0, 4 ) as $product ) : ?>
                <a href="<?php echo esc_url( $product->get_permalink() ); ?>#reviews" class="review-product-card">
                    <?php echo $product->get_image( 'thumbnail' ); ?>
                    <span><?php echo esc_html( $product->get_name() ); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            .jdpd-pending-reviews { margin-bottom: 30px; }
            .review-products-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
            .review-product-card { text-align: center; text-decoration: none; color: inherit; }
            .review-product-card img { max-width: 100%; border-radius: 4px; }
            .review-product-card span { display: block; font-size: 12px; margin-top: 5px; }
        </style>
        <?php
    }

    /**
     * Send reward notification email
     *
     * @param WP_Comment $comment     Comment object.
     * @param string     $reward_info Reward info.
     * @param string     $reward_type Reward type.
     */
    private function send_reward_notification( $comment, $reward_info, $reward_type ) {
        $subject = sprintf(
            __( 'Thank you for your review on %s!', 'jezweb-dynamic-pricing' ),
            get_bloginfo( 'name' )
        );

        if ( $reward_type === 'coupon' ) {
            $message = sprintf(
                __( "Hi %s,\n\nThank you for leaving a review! Here's your reward:\n\nCoupon Code: %s\n\nUse this code on your next purchase.\n\nThanks,\n%s", 'jezweb-dynamic-pricing' ),
                $comment->comment_author,
                $reward_info,
                get_bloginfo( 'name' )
            );
        } elseif ( $reward_type === 'points' ) {
            $message = sprintf(
                __( "Hi %s,\n\nThank you for leaving a review! You've earned %s.\n\nYour points have been added to your account.\n\nThanks,\n%s", 'jezweb-dynamic-pricing' ),
                $comment->comment_author,
                $reward_info,
                get_bloginfo( 'name' )
            );
        } else {
            $message = sprintf(
                __( "Hi %s,\n\nThank you for leaving a review! You've earned %s.\n\nThis discount will be automatically applied to your next order.\n\nThanks,\n%s", 'jezweb-dynamic-pricing' ),
                $comment->comment_author,
                $reward_info,
                get_bloginfo( 'name' )
            );
        }

        wp_mail( $comment->comment_author_email, $subject, $message );
    }

    /**
     * Send review reminder emails
     */
    public function send_review_reminders() {
        if ( $this->settings['email_reminder_enabled'] !== 'yes' ) {
            return;
        }

        $days_ago = absint( $this->settings['email_reminder_days'] );
        $date_threshold = date( 'Y-m-d', strtotime( "-{$days_ago} days" ) );

        // Get orders completed X days ago
        $orders = wc_get_orders( array(
            'status'       => 'completed',
            'date_completed'=> $date_threshold . '...' . $date_threshold . ' 23:59:59',
            'limit'        => 50,
        ) );

        foreach ( $orders as $order ) {
            // Check if reminder already sent
            if ( $order->get_meta( '_jdpd_review_reminder_sent' ) ) {
                continue;
            }

            $products_to_review = array();
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();

                // Check if already reviewed
                $existing = get_comments( array(
                    'post_id'      => $product_id,
                    'author_email' => $order->get_billing_email(),
                    'type'         => 'review',
                    'count'        => true,
                ) );

                if ( ! $existing ) {
                    $products_to_review[] = wc_get_product( $product_id );
                }
            }

            if ( empty( $products_to_review ) ) {
                continue;
            }

            // Send reminder
            $this->send_reminder_email( $order, $products_to_review );
            $order->update_meta_data( '_jdpd_review_reminder_sent', current_time( 'mysql' ) );
            $order->save();
        }
    }

    /**
     * Send reminder email
     *
     * @param WC_Order $order    Order object.
     * @param array    $products Products to review.
     */
    private function send_reminder_email( $order, $products ) {
        $reward_text = $this->settings['reward_discount_type'] === 'percentage'
            ? $this->settings['reward_amount'] . '%'
            : wc_price( $this->settings['reward_amount'] );

        $product_list = '';
        foreach ( $products as $product ) {
            $product_list .= "- " . $product->get_name() . ": " . $product->get_permalink() . "#reviews\n";
        }

        $subject = sprintf(
            __( 'Share your thoughts and get %s off!', 'jezweb-dynamic-pricing' ),
            $reward_text
        );

        $message = sprintf(
            __( "Hi %s,\n\nWe hope you're enjoying your recent purchase! We'd love to hear your thoughts.\n\nLeave a review and get %s off your next order!\n\nProducts to review:\n%s\n\nThanks,\n%s", 'jezweb-dynamic-pricing' ),
            $order->get_billing_first_name(),
            $reward_text,
            $product_list,
            get_bloginfo( 'name' )
        );

        wp_mail( $order->get_billing_email(), $subject, $message );
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
            'enabled'                => sanitize_text_field( $_POST['enabled'] ?? 'yes' ),
            'reward_type'            => sanitize_text_field( $_POST['reward_type'] ?? 'coupon' ),
            'reward_amount'          => floatval( $_POST['reward_amount'] ?? 10 ),
            'reward_discount_type'   => sanitize_text_field( $_POST['reward_discount_type'] ?? 'percentage' ),
            'require_photo'          => sanitize_text_field( $_POST['require_photo'] ?? 'no' ),
            'require_verified'       => sanitize_text_field( $_POST['require_verified'] ?? 'yes' ),
            'min_rating'             => absint( $_POST['min_rating'] ?? 1 ),
            'min_review_length'      => absint( $_POST['min_review_length'] ?? 20 ),
            'max_rewards_per_user'   => absint( $_POST['max_rewards_per_user'] ?? 0 ),
            'coupon_expiry_days'     => absint( $_POST['coupon_expiry_days'] ?? 30 ),
            'rating_discount_enabled'=> sanitize_text_field( $_POST['rating_discount_enabled'] ?? 'no' ),
            'rating_tiers'           => isset( $_POST['rating_tiers'] ) ? $this->sanitize_tiers( $_POST['rating_tiers'] ) : array(),
            'email_reminder_enabled' => sanitize_text_field( $_POST['email_reminder_enabled'] ?? 'yes' ),
            'email_reminder_days'    => absint( $_POST['email_reminder_days'] ?? 7 ),
        );

        update_option( 'jdpd_review_discount_settings', $settings );
        $this->settings = $settings;

        wp_send_json_success();
    }

    /**
     * Sanitize rating tiers
     *
     * @param array $tiers Tiers data.
     * @return array
     */
    private function sanitize_tiers( $tiers ) {
        $sanitized = array();
        foreach ( (array) $tiers as $tier ) {
            $sanitized[] = array(
                'min_rating' => floatval( $tier['min_rating'] ?? 0 ),
                'discount'   => floatval( $tier['discount'] ?? 0 ),
            );
        }
        return $sanitized;
    }

    /**
     * AJAX get stats
     */
    public function ajax_get_stats() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        global $wpdb;

        $total_reviews = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'review' AND comment_approved = 1"
        );

        $rewarded_reviews = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key = '_jdpd_review_rewarded'"
        );

        wp_send_json_success( array(
            'total_reviews'    => absint( $total_reviews ),
            'rewarded_reviews' => absint( $rewarded_reviews ),
        ) );
    }

    /**
     * Shortcode: Review discount info
     *
     * @param array $atts Attributes.
     * @return string
     */
    public function shortcode_review_info( $atts ) {
        if ( $this->settings['enabled'] !== 'yes' ) {
            return '';
        }

        $reward_text = $this->settings['reward_discount_type'] === 'percentage'
            ? $this->settings['reward_amount'] . '%'
            : wc_price( $this->settings['reward_amount'] );

        ob_start();
        ?>
        <div class="jdpd-review-discount-info">
            <strong>⭐ <?php esc_html_e( 'Review & Earn!', 'jezweb-dynamic-pricing' ); ?></strong>
            <span><?php printf( esc_html__( 'Get %s off when you review products you\'ve purchased.', 'jezweb-dynamic-pricing' ), $reward_text ); ?></span>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize
JDPD_Review_Discounts::get_instance();
