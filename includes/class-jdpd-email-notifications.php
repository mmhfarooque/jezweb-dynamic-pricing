<?php
/**
 * Email Notifications System
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Jezweb_Dynamic_Pricing/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Email Notifications System class.
 *
 * Handles all email notifications for the plugin including:
 * - Flash sale alerts to customers
 * - Admin notifications for rule events
 * - Price drop alerts
 * - Cart abandonment reminders with discounts
 *
 * @since 1.3.0
 */
class JDPD_Email_Notifications {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Email_Notifications
     */
    private static $instance = null;

    /**
     * Email settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Get single instance.
     *
     * @return JDPD_Email_Notifications
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->settings = $this->get_email_settings();
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // WooCommerce email hooks
        add_filter( 'woocommerce_email_classes', array( $this, 'register_email_classes' ) );

        // Rule event notifications
        add_action( 'jdpd_scheduled_rule_activated', array( $this, 'notify_rule_activated' ), 10, 2 );
        add_action( 'jdpd_scheduled_rule_deactivated', array( $this, 'notify_rule_deactivated' ), 10, 2 );

        // Flash sale notifications
        add_action( 'jdpd_flash_sale_starting', array( $this, 'send_flash_sale_notification' ), 10, 3 );

        // Price drop alerts
        add_action( 'jdpd_rule_created', array( $this, 'check_price_drop_alerts' ), 10, 2 );
        add_action( 'jdpd_rule_updated', array( $this, 'check_price_drop_alerts' ), 10, 2 );

        // Cart abandonment
        add_action( 'woocommerce_cart_updated', array( $this, 'track_cart_for_abandonment' ) );
        add_action( 'jdpd_send_abandonment_emails', array( $this, 'process_abandonment_emails' ) );

        // Customer subscription to alerts
        add_action( 'wp_ajax_jdpd_subscribe_price_alert', array( $this, 'ajax_subscribe_price_alert' ) );
        add_action( 'wp_ajax_nopriv_jdpd_subscribe_price_alert', array( $this, 'ajax_subscribe_price_alert' ) );

        // Admin settings
        add_action( 'wp_ajax_jdpd_save_email_settings', array( $this, 'ajax_save_email_settings' ) );
        add_action( 'wp_ajax_jdpd_send_test_email', array( $this, 'ajax_send_test_email' ) );

        // Schedule abandonment check
        if ( ! wp_next_scheduled( 'jdpd_send_abandonment_emails' ) ) {
            wp_schedule_event( time(), 'hourly', 'jdpd_send_abandonment_emails' );
        }
    }

    /**
     * Get email settings.
     *
     * @return array Email settings.
     */
    public function get_email_settings() {
        $defaults = array(
            'enabled'                   => true,
            'from_name'                 => get_bloginfo( 'name' ),
            'from_email'                => get_option( 'admin_email' ),
            'admin_notifications'       => true,
            'admin_email'               => get_option( 'admin_email' ),
            'flash_sale_enabled'        => true,
            'price_drop_enabled'        => true,
            'abandonment_enabled'       => true,
            'abandonment_delay_hours'   => 1,
            'abandonment_discount'      => 10,
            'email_template'            => 'default',
            'brand_color'               => '#22588d',
            'logo_url'                  => '',
        );

        return get_option( 'jdpd_email_settings', $defaults );
    }

    /**
     * Register custom WooCommerce email classes.
     *
     * @param array $email_classes Existing email classes.
     * @return array Modified email classes.
     */
    public function register_email_classes( $email_classes ) {
        // Only register if the email classes have been defined
        if ( class_exists( 'JDPD_Email_Flash_Sale' ) ) {
            $email_classes['JDPD_Email_Flash_Sale'] = new JDPD_Email_Flash_Sale();
        }
        if ( class_exists( 'JDPD_Email_Price_Drop' ) ) {
            $email_classes['JDPD_Email_Price_Drop'] = new JDPD_Email_Price_Drop();
        }
        if ( class_exists( 'JDPD_Email_Cart_Abandonment' ) ) {
            $email_classes['JDPD_Email_Cart_Abandonment'] = new JDPD_Email_Cart_Abandonment();
        }

        return $email_classes;
    }

    /**
     * Notify admin when a rule is activated.
     *
     * @param string $rule_id Rule ID.
     * @param array  $rule Rule data.
     */
    public function notify_rule_activated( $rule_id, $rule ) {
        if ( empty( $this->settings['admin_notifications'] ) ) {
            return;
        }

        $subject = sprintf(
            /* translators: %s: Rule name */
            __( '[%s] Pricing Rule Activated: %s', 'jezweb-dynamic-pricing' ),
            get_bloginfo( 'name' ),
            $rule['name'] ?? $rule_id
        );

        $message = $this->get_admin_notification_template( 'rule_activated', array(
            'rule_id'   => $rule_id,
            'rule'      => $rule,
            'timestamp' => current_time( 'mysql' ),
        ) );

        $this->send_admin_email( $subject, $message );
    }

    /**
     * Notify admin when a rule is deactivated.
     *
     * @param string $rule_id Rule ID.
     * @param array  $rule Rule data.
     */
    public function notify_rule_deactivated( $rule_id, $rule ) {
        if ( empty( $this->settings['admin_notifications'] ) ) {
            return;
        }

        $subject = sprintf(
            /* translators: %s: Rule name */
            __( '[%s] Pricing Rule Deactivated: %s', 'jezweb-dynamic-pricing' ),
            get_bloginfo( 'name' ),
            $rule['name'] ?? $rule_id
        );

        $message = $this->get_admin_notification_template( 'rule_deactivated', array(
            'rule_id'   => $rule_id,
            'rule'      => $rule,
            'timestamp' => current_time( 'mysql' ),
        ) );

        $this->send_admin_email( $subject, $message );
    }

    /**
     * Send flash sale notification to subscribed customers.
     *
     * @param string $rule_id Rule ID.
     * @param array  $rule Rule data.
     * @param string $type Notification type (start, ending).
     */
    public function send_flash_sale_notification( $rule_id, $rule, $type = 'start' ) {
        if ( empty( $this->settings['flash_sale_enabled'] ) ) {
            return;
        }

        // Get subscribed customers
        $subscribers = $this->get_flash_sale_subscribers();

        if ( empty( $subscribers ) ) {
            return;
        }

        foreach ( $subscribers as $subscriber ) {
            $this->send_flash_sale_email( $subscriber, $rule, $type );
        }

        // Log the notification
        if ( class_exists( 'JDPD_Analytics' ) ) {
            JDPD_Analytics::get_instance()->log_event( 'flash_sale_notification', array(
                'rule_id'     => $rule_id,
                'type'        => $type,
                'subscribers' => count( $subscribers ),
            ) );
        }
    }

    /**
     * Send flash sale email to a subscriber.
     *
     * @param array  $subscriber Subscriber data.
     * @param array  $rule Rule data.
     * @param string $type Notification type.
     */
    private function send_flash_sale_email( $subscriber, $rule, $type ) {
        $email = $subscriber['email'];
        $name = $subscriber['name'] ?? '';

        if ( 'start' === $type ) {
            $subject = sprintf(
                /* translators: %s: Sale name */
                __( '🔥 Flash Sale Started: %s', 'jezweb-dynamic-pricing' ),
                $rule['name'] ?? __( 'Special Offer', 'jezweb-dynamic-pricing' )
            );
        } else {
            $subject = sprintf(
                /* translators: %s: Sale name */
                __( '⏰ Last Chance: %s Ending Soon!', 'jezweb-dynamic-pricing' ),
                $rule['name'] ?? __( 'Flash Sale', 'jezweb-dynamic-pricing' )
            );
        }

        $message = $this->get_email_template( 'flash_sale', array(
            'subscriber'  => $subscriber,
            'rule'        => $rule,
            'type'        => $type,
            'shop_url'    => wc_get_page_permalink( 'shop' ),
            'unsubscribe' => $this->get_unsubscribe_url( $email, 'flash_sale' ),
        ) );

        $this->send_email( $email, $subject, $message );
    }

    /**
     * Check and send price drop alerts.
     *
     * @param string $rule_id Rule ID.
     * @param array  $rule Rule data.
     */
    public function check_price_drop_alerts( $rule_id, $rule ) {
        if ( empty( $this->settings['price_drop_enabled'] ) ) {
            return;
        }

        // Only for product-specific rules
        if ( empty( $rule['products'] ) ) {
            return;
        }

        foreach ( $rule['products'] as $product_id ) {
            $subscribers = $this->get_price_alert_subscribers( $product_id );

            foreach ( $subscribers as $subscriber ) {
                $this->send_price_drop_email( $subscriber, $product_id, $rule );
            }

            // Clear subscriptions after notification
            $this->clear_price_alert_subscribers( $product_id );
        }
    }

    /**
     * Send price drop email.
     *
     * @param array $subscriber Subscriber data.
     * @param int   $product_id Product ID.
     * @param array $rule Rule data.
     */
    private function send_price_drop_email( $subscriber, $product_id, $rule ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        $email = $subscriber['email'];

        $subject = sprintf(
            /* translators: %s: Product name */
            __( 'Price Drop Alert: %s is now on sale!', 'jezweb-dynamic-pricing' ),
            $product->get_name()
        );

        $message = $this->get_email_template( 'price_drop', array(
            'subscriber'  => $subscriber,
            'product'     => $product,
            'rule'        => $rule,
            'product_url' => $product->get_permalink(),
            'unsubscribe' => $this->get_unsubscribe_url( $email, 'price_drop' ),
        ) );

        $this->send_email( $email, $subject, $message );
    }

    /**
     * Track cart for abandonment reminders.
     */
    public function track_cart_for_abandonment() {
        if ( empty( $this->settings['abandonment_enabled'] ) ) {
            return;
        }

        if ( ! is_user_logged_in() && ! isset( $_COOKIE['jdpd_guest_email'] ) ) {
            return;
        }

        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            return;
        }

        $email = '';
        $user_id = 0;

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $email = $user->user_email;
            $user_id = $user->ID;
        } elseif ( isset( $_COOKIE['jdpd_guest_email'] ) ) {
            $email = sanitize_email( $_COOKIE['jdpd_guest_email'] );
        }

        if ( empty( $email ) ) {
            return;
        }

        // Store cart data for abandonment tracking
        $cart_data = array(
            'email'      => $email,
            'user_id'    => $user_id,
            'cart_hash'  => $cart->get_cart_hash(),
            'cart_total' => $cart->get_total( 'edit' ),
            'items'      => array(),
            'updated_at' => current_time( 'mysql' ),
        );

        foreach ( $cart->get_cart() as $item ) {
            $product = $item['data'];
            $cart_data['items'][] = array(
                'product_id' => $item['product_id'],
                'name'       => $product->get_name(),
                'quantity'   => $item['quantity'],
                'price'      => $product->get_price(),
            );
        }

        // Store with 48-hour expiration
        set_transient( 'jdpd_abandoned_cart_' . md5( $email ), $cart_data, 48 * HOUR_IN_SECONDS );
    }

    /**
     * Process abandonment emails via cron.
     */
    public function process_abandonment_emails() {
        global $wpdb;

        if ( empty( $this->settings['abandonment_enabled'] ) ) {
            return;
        }

        $delay_hours = absint( $this->settings['abandonment_delay_hours'] ?? 1 );
        $cutoff_time = date( 'Y-m-d H:i:s', strtotime( "-{$delay_hours} hours" ) );

        // Get all transients for abandoned carts
        $transients = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_jdpd_abandoned_cart_%'"
        );

        foreach ( $transients as $transient ) {
            $cart_data = maybe_unserialize( $transient->option_value );

            if ( ! is_array( $cart_data ) || empty( $cart_data['email'] ) ) {
                continue;
            }

            // Check if enough time has passed
            if ( $cart_data['updated_at'] > $cutoff_time ) {
                continue;
            }

            // Check if user has since completed an order
            if ( $this->has_recent_order( $cart_data['email'] ) ) {
                // Delete transient
                $key = str_replace( '_transient_', '', $transient->option_name );
                delete_transient( $key );
                continue;
            }

            // Check if already sent
            $sent_key = 'jdpd_abandonment_sent_' . md5( $cart_data['email'] . $cart_data['cart_hash'] );
            if ( get_transient( $sent_key ) ) {
                continue;
            }

            // Send abandonment email
            $this->send_abandonment_email( $cart_data );

            // Mark as sent for 7 days
            set_transient( $sent_key, true, 7 * DAY_IN_SECONDS );
        }
    }

    /**
     * Send cart abandonment email.
     *
     * @param array $cart_data Cart data.
     */
    private function send_abandonment_email( $cart_data ) {
        $email = $cart_data['email'];
        $discount = absint( $this->settings['abandonment_discount'] ?? 10 );

        // Create a unique coupon for this customer
        $coupon_code = $this->create_abandonment_coupon( $email, $discount );

        $subject = sprintf(
            /* translators: %s: Site name */
            __( 'You left something behind at %s', 'jezweb-dynamic-pricing' ),
            get_bloginfo( 'name' )
        );

        $message = $this->get_email_template( 'cart_abandonment', array(
            'cart_data'   => $cart_data,
            'coupon_code' => $coupon_code,
            'discount'    => $discount,
            'cart_url'    => wc_get_cart_url(),
            'unsubscribe' => $this->get_unsubscribe_url( $email, 'abandonment' ),
        ) );

        $this->send_email( $email, $subject, $message );

        // Log the email
        if ( class_exists( 'JDPD_Analytics' ) ) {
            JDPD_Analytics::get_instance()->log_event( 'abandonment_email_sent', array(
                'email'      => $email,
                'cart_total' => $cart_data['cart_total'],
                'discount'   => $discount,
            ) );
        }
    }

    /**
     * Create an abandonment recovery coupon.
     *
     * @param string $email Customer email.
     * @param int    $discount Discount percentage.
     * @return string Coupon code.
     */
    private function create_abandonment_coupon( $email, $discount ) {
        $code = 'COMEBACK' . strtoupper( wp_generate_password( 6, false ) );

        $coupon = new WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount_type( 'percent' );
        $coupon->set_amount( $discount );
        $coupon->set_individual_use( true );
        $coupon->set_usage_limit( 1 );
        $coupon->set_email_restrictions( array( $email ) );
        $coupon->set_date_expires( strtotime( '+7 days' ) );
        $coupon->set_description(
            sprintf(
                /* translators: %s: Email */
                __( 'Cart abandonment coupon for %s', 'jezweb-dynamic-pricing' ),
                $email
            )
        );

        // Add meta to track it
        $coupon->add_meta_data( '_jdpd_abandonment_coupon', true );
        $coupon->save();

        return $code;
    }

    /**
     * Check if user has placed an order recently.
     *
     * @param string $email Customer email.
     * @return bool Whether has recent order.
     */
    private function has_recent_order( $email ) {
        $orders = wc_get_orders( array(
            'billing_email' => $email,
            'date_created'  => '>' . ( time() - DAY_IN_SECONDS ),
            'limit'         => 1,
        ) );

        return ! empty( $orders );
    }

    /**
     * Subscribe to price alert.
     */
    public function ajax_subscribe_price_alert() {
        check_ajax_referer( 'jdpd_frontend_nonce', 'nonce' );

        $email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'jezweb-dynamic-pricing' ) ) );
        }

        if ( empty( $product_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid product.', 'jezweb-dynamic-pricing' ) ) );
        }

        $subscribers = get_option( 'jdpd_price_alert_subscribers', array() );

        if ( ! isset( $subscribers[ $product_id ] ) ) {
            $subscribers[ $product_id ] = array();
        }

        // Check if already subscribed
        foreach ( $subscribers[ $product_id ] as $sub ) {
            if ( $sub['email'] === $email ) {
                wp_send_json_success( array( 'message' => __( 'You are already subscribed to price alerts for this product.', 'jezweb-dynamic-pricing' ) ) );
            }
        }

        $subscribers[ $product_id ][] = array(
            'email'         => $email,
            'name'          => is_user_logged_in() ? wp_get_current_user()->display_name : '',
            'subscribed_at' => current_time( 'mysql' ),
        );

        update_option( 'jdpd_price_alert_subscribers', $subscribers );

        wp_send_json_success( array( 'message' => __( 'You will be notified when the price drops!', 'jezweb-dynamic-pricing' ) ) );
    }

    /**
     * Get price alert subscribers for a product.
     *
     * @param int $product_id Product ID.
     * @return array Subscribers.
     */
    private function get_price_alert_subscribers( $product_id ) {
        $subscribers = get_option( 'jdpd_price_alert_subscribers', array() );
        return $subscribers[ $product_id ] ?? array();
    }

    /**
     * Clear price alert subscribers for a product.
     *
     * @param int $product_id Product ID.
     */
    private function clear_price_alert_subscribers( $product_id ) {
        $subscribers = get_option( 'jdpd_price_alert_subscribers', array() );
        unset( $subscribers[ $product_id ] );
        update_option( 'jdpd_price_alert_subscribers', $subscribers );
    }

    /**
     * Get flash sale subscribers.
     *
     * @return array Subscribers.
     */
    private function get_flash_sale_subscribers() {
        return get_option( 'jdpd_flash_sale_subscribers', array() );
    }

    /**
     * Get unsubscribe URL.
     *
     * @param string $email Email address.
     * @param string $type Subscription type.
     * @return string Unsubscribe URL.
     */
    private function get_unsubscribe_url( $email, $type ) {
        $token = wp_hash( $email . $type . wp_salt() );

        return add_query_arg( array(
            'jdpd_unsubscribe' => $type,
            'email'            => urlencode( $email ),
            'token'            => $token,
        ), home_url() );
    }

    /**
     * Send an email.
     *
     * @param string $to Recipient email.
     * @param string $subject Email subject.
     * @param string $message Email message.
     * @return bool Whether sent successfully.
     */
    public function send_email( $to, $subject, $message ) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $this->settings['from_name'], $this->settings['from_email'] ),
        );

        return wp_mail( $to, $subject, $message, $headers );
    }

    /**
     * Send admin email.
     *
     * @param string $subject Email subject.
     * @param string $message Email message.
     * @return bool Whether sent successfully.
     */
    private function send_admin_email( $subject, $message ) {
        $to = $this->settings['admin_email'] ?? get_option( 'admin_email' );
        return $this->send_email( $to, $subject, $message );
    }

    /**
     * Get email template.
     *
     * @param string $template Template name.
     * @param array  $args Template arguments.
     * @return string Email HTML.
     */
    public function get_email_template( $template, $args = array() ) {
        ob_start();

        // Header
        $this->email_header();

        // Content based on template
        switch ( $template ) {
            case 'flash_sale':
                $this->flash_sale_content( $args );
                break;

            case 'price_drop':
                $this->price_drop_content( $args );
                break;

            case 'cart_abandonment':
                $this->cart_abandonment_content( $args );
                break;
        }

        // Footer
        $this->email_footer( $args['unsubscribe'] ?? '' );

        return ob_get_clean();
    }

    /**
     * Get admin notification template.
     *
     * @param string $type Notification type.
     * @param array  $args Template arguments.
     * @return string Email HTML.
     */
    private function get_admin_notification_template( $type, $args ) {
        ob_start();

        $this->email_header();

        $rule = $args['rule'];
        $rule_name = $rule['name'] ?? $args['rule_id'];

        if ( 'rule_activated' === $type ) {
            echo '<h2 style="color: #22588d;">' . esc_html__( 'Pricing Rule Activated', 'jezweb-dynamic-pricing' ) . '</h2>';
            echo '<p>' . sprintf(
                /* translators: %s: Rule name */
                esc_html__( 'The pricing rule "%s" has been automatically activated.', 'jezweb-dynamic-pricing' ),
                esc_html( $rule_name )
            ) . '</p>';
        } else {
            echo '<h2 style="color: #d83a34;">' . esc_html__( 'Pricing Rule Deactivated', 'jezweb-dynamic-pricing' ) . '</h2>';
            echo '<p>' . sprintf(
                /* translators: %s: Rule name */
                esc_html__( 'The pricing rule "%s" has been automatically deactivated.', 'jezweb-dynamic-pricing' ),
                esc_html( $rule_name )
            ) . '</p>';
        }

        echo '<p><strong>' . esc_html__( 'Time:', 'jezweb-dynamic-pricing' ) . '</strong> ' . esc_html( $args['timestamp'] ) . '</p>';

        $admin_url = admin_url( 'admin.php?page=jdpd-pricing-rules' );
        echo '<p><a href="' . esc_url( $admin_url ) . '" style="display: inline-block; padding: 10px 20px; background: #22588d; color: #fff; text-decoration: none; border-radius: 4px;">' . esc_html__( 'View Rules', 'jezweb-dynamic-pricing' ) . '</a></p>';

        $this->email_footer();

        return ob_get_clean();
    }

    /**
     * Email header.
     */
    private function email_header() {
        $brand_color = $this->settings['brand_color'] ?? '#22588d';
        $logo_url = $this->settings['logo_url'] ?? '';
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; background-color: #f5f5f5;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f5f5f5; padding: 20px;">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <!-- Header -->
                            <tr>
                                <td style="background-color: <?php echo esc_attr( $brand_color ); ?>; padding: 30px; text-align: center;">
                                    <?php if ( $logo_url ) : ?>
                                        <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" style="max-width: 200px; height: auto;">
                                    <?php else : ?>
                                        <h1 style="color: #ffffff; margin: 0; font-size: 24px;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <!-- Content -->
                            <tr>
                                <td style="padding: 40px 30px;">
        <?php
    }

    /**
     * Email footer.
     *
     * @param string $unsubscribe_url Unsubscribe URL.
     */
    private function email_footer( $unsubscribe_url = '' ) {
        ?>
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style="background-color: #f8f9fa; padding: 20px 30px; text-align: center; font-size: 12px; color: #6c757d;">
                                    <p style="margin: 0 0 10px 0;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
                                    <?php if ( $unsubscribe_url ) : ?>
                                        <p style="margin: 0;">
                                            <a href="<?php echo esc_url( $unsubscribe_url ); ?>" style="color: #6c757d;">
                                                <?php esc_html_e( 'Unsubscribe from these emails', 'jezweb-dynamic-pricing' ); ?>
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
    }

    /**
     * Flash sale email content.
     *
     * @param array $args Template arguments.
     */
    private function flash_sale_content( $args ) {
        $rule = $args['rule'];
        $type = $args['type'];
        $shop_url = $args['shop_url'];

        if ( 'start' === $type ) : ?>
            <h2 style="color: #d83a34; margin: 0 0 20px 0;">🔥 <?php esc_html_e( 'Flash Sale Now Live!', 'jezweb-dynamic-pricing' ); ?></h2>
            <p style="font-size: 16px; color: #333; line-height: 1.6;">
                <?php
                printf(
                    /* translators: %s: Sale name */
                    esc_html__( 'Our %s has just started! Don\'t miss out on these incredible deals.', 'jezweb-dynamic-pricing' ),
                    '<strong>' . esc_html( $rule['name'] ?? __( 'Flash Sale', 'jezweb-dynamic-pricing' ) ) . '</strong>'
                );
                ?>
            </p>
        <?php else : ?>
            <h2 style="color: #d83a34; margin: 0 0 20px 0;">⏰ <?php esc_html_e( 'Last Chance!', 'jezweb-dynamic-pricing' ); ?></h2>
            <p style="font-size: 16px; color: #333; line-height: 1.6;">
                <?php
                printf(
                    /* translators: %s: Sale name */
                    esc_html__( 'Our %s is ending soon! This is your last chance to grab these amazing deals.', 'jezweb-dynamic-pricing' ),
                    '<strong>' . esc_html( $rule['name'] ?? __( 'Flash Sale', 'jezweb-dynamic-pricing' ) ) . '</strong>'
                );
                ?>
            </p>
        <?php endif; ?>

        <p style="text-align: center; margin: 30px 0;">
            <a href="<?php echo esc_url( $shop_url ); ?>" style="display: inline-block; padding: 15px 40px; background-color: #d83a34; color: #ffffff; text-decoration: none; font-size: 18px; font-weight: bold; border-radius: 4px;">
                <?php esc_html_e( 'Shop Now', 'jezweb-dynamic-pricing' ); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Price drop email content.
     *
     * @param array $args Template arguments.
     */
    private function price_drop_content( $args ) {
        $product = $args['product'];
        $product_url = $args['product_url'];
        ?>
        <h2 style="color: #22588d; margin: 0 0 20px 0;">💰 <?php esc_html_e( 'Price Drop Alert!', 'jezweb-dynamic-pricing' ); ?></h2>

        <p style="font-size: 16px; color: #333; line-height: 1.6;">
            <?php esc_html_e( 'Great news! A product you were watching just dropped in price.', 'jezweb-dynamic-pricing' ); ?>
        </p>

        <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border: 1px solid #e5e5e5; border-radius: 4px;">
            <tr>
                <td style="padding: 20px;">
                    <table width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <?php if ( $product->get_image_id() ) : ?>
                                <td width="100" style="padding-right: 20px;">
                                    <img src="<?php echo esc_url( wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" style="width: 100px; height: 100px; object-fit: cover; border-radius: 4px;">
                                </td>
                            <?php endif; ?>
                            <td>
                                <h3 style="margin: 0 0 10px 0; color: #333;"><?php echo esc_html( $product->get_name() ); ?></h3>
                                <p style="margin: 0; font-size: 24px; color: #d83a34; font-weight: bold;">
                                    <?php echo wp_kses_post( $product->get_price_html() ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <p style="text-align: center; margin: 30px 0;">
            <a href="<?php echo esc_url( $product_url ); ?>" style="display: inline-block; padding: 15px 40px; background-color: #22588d; color: #ffffff; text-decoration: none; font-size: 18px; font-weight: bold; border-radius: 4px;">
                <?php esc_html_e( 'View Product', 'jezweb-dynamic-pricing' ); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Cart abandonment email content.
     *
     * @param array $args Template arguments.
     */
    private function cart_abandonment_content( $args ) {
        $cart_data = $args['cart_data'];
        $coupon_code = $args['coupon_code'];
        $discount = $args['discount'];
        $cart_url = $args['cart_url'];
        ?>
        <h2 style="color: #22588d; margin: 0 0 20px 0;"><?php esc_html_e( 'You left something behind!', 'jezweb-dynamic-pricing' ); ?></h2>

        <p style="font-size: 16px; color: #333; line-height: 1.6;">
            <?php esc_html_e( 'We noticed you didn\'t complete your purchase. Your items are still waiting for you!', 'jezweb-dynamic-pricing' ); ?>
        </p>

        <?php if ( ! empty( $cart_data['items'] ) ) : ?>
            <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border: 1px solid #e5e5e5; border-radius: 4px;">
                <?php foreach ( $cart_data['items'] as $item ) : ?>
                    <tr>
                        <td style="padding: 15px; border-bottom: 1px solid #e5e5e5;">
                            <strong><?php echo esc_html( $item['name'] ); ?></strong>
                            <span style="color: #6c757d;"> x <?php echo esc_html( $item['quantity'] ); ?></span>
                        </td>
                        <td style="padding: 15px; border-bottom: 1px solid #e5e5e5; text-align: right;">
                            <?php echo wp_kses_post( wc_price( $item['price'] * $item['quantity'] ) ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <?php if ( $coupon_code && $discount > 0 ) : ?>
            <div style="background-color: #fff3cd; border: 2px dashed #ffc107; border-radius: 4px; padding: 20px; text-align: center; margin: 20px 0;">
                <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404;">
                    <?php
                    printf(
                        /* translators: %d: Discount percentage */
                        esc_html__( 'Use code below for %d%% off your order:', 'jezweb-dynamic-pricing' ),
                        $discount
                    );
                    ?>
                </p>
                <p style="margin: 0; font-size: 24px; font-weight: bold; color: #856404; letter-spacing: 2px;">
                    <?php echo esc_html( $coupon_code ); ?>
                </p>
            </div>
        <?php endif; ?>

        <p style="text-align: center; margin: 30px 0;">
            <a href="<?php echo esc_url( $cart_url ); ?>" style="display: inline-block; padding: 15px 40px; background-color: #22588d; color: #ffffff; text-decoration: none; font-size: 18px; font-weight: bold; border-radius: 4px;">
                <?php esc_html_e( 'Complete Your Order', 'jezweb-dynamic-pricing' ); ?>
            </a>
        </p>

        <p style="font-size: 12px; color: #6c757d; text-align: center;">
            <?php esc_html_e( 'This offer expires in 7 days.', 'jezweb-dynamic-pricing' ); ?>
        </p>
        <?php
    }

    /**
     * AJAX: Save email settings.
     */
    public function ajax_save_email_settings() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

        $sanitized = array(
            'enabled'                 => ! empty( $settings['enabled'] ),
            'from_name'               => sanitize_text_field( $settings['from_name'] ?? '' ),
            'from_email'              => sanitize_email( $settings['from_email'] ?? '' ),
            'admin_notifications'     => ! empty( $settings['admin_notifications'] ),
            'admin_email'             => sanitize_email( $settings['admin_email'] ?? '' ),
            'flash_sale_enabled'      => ! empty( $settings['flash_sale_enabled'] ),
            'price_drop_enabled'      => ! empty( $settings['price_drop_enabled'] ),
            'abandonment_enabled'     => ! empty( $settings['abandonment_enabled'] ),
            'abandonment_delay_hours' => absint( $settings['abandonment_delay_hours'] ?? 1 ),
            'abandonment_discount'    => absint( $settings['abandonment_discount'] ?? 10 ),
            'brand_color'             => sanitize_hex_color( $settings['brand_color'] ?? '#22588d' ),
            'logo_url'                => esc_url_raw( $settings['logo_url'] ?? '' ),
        );

        update_option( 'jdpd_email_settings', $sanitized );

        $this->settings = $sanitized;

        wp_send_json_success( array( 'message' => __( 'Email settings saved.', 'jezweb-dynamic-pricing' ) ) );
    }

    /**
     * AJAX: Send test email.
     */
    public function ajax_send_test_email() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
        $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'flash_sale';

        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'jezweb-dynamic-pricing' ) ) );
        }

        // Create test content
        $test_args = array(
            'subscriber'  => array( 'email' => $email, 'name' => 'Test User' ),
            'rule'        => array( 'name' => 'Test Flash Sale' ),
            'type'        => 'start',
            'shop_url'    => wc_get_page_permalink( 'shop' ),
            'unsubscribe' => '#',
            'product'     => null,
            'product_url' => '#',
            'cart_data'   => array(
                'items' => array(
                    array( 'name' => 'Test Product 1', 'quantity' => 2, 'price' => 29.99 ),
                    array( 'name' => 'Test Product 2', 'quantity' => 1, 'price' => 49.99 ),
                ),
                'cart_total' => 109.97,
            ),
            'coupon_code' => 'TESTCODE123',
            'discount'    => 10,
            'cart_url'    => wc_get_cart_url(),
        );

        $message = $this->get_email_template( $type, $test_args );
        $subject = sprintf(
            /* translators: %s: Email type */
            __( '[TEST] %s Email', 'jezweb-dynamic-pricing' ),
            ucfirst( str_replace( '_', ' ', $type ) )
        );

        $result = $this->send_email( $email, $subject, $message );

        if ( $result ) {
            wp_send_json_success( array( 'message' => __( 'Test email sent successfully.', 'jezweb-dynamic-pricing' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to send test email.', 'jezweb-dynamic-pricing' ) ) );
        }
    }
}

/**
 * Register email classes after WooCommerce is loaded.
 * These classes extend WC_Email which requires WooCommerce to be fully loaded.
 */
add_action( 'woocommerce_loaded', 'jdpd_register_email_classes' );

/**
 * Register JDPD email classes.
 */
function jdpd_register_email_classes() {
    if ( ! class_exists( 'WC_Email' ) ) {
        return;
    }

    /**
     * Flash Sale Email
     */
    class JDPD_Email_Flash_Sale extends WC_Email {
        public function __construct() {
            $this->id = 'jdpd_flash_sale';
            $this->title = __( 'Flash Sale Notification', 'jezweb-dynamic-pricing' );
            $this->description = __( 'Flash sale notifications sent to customers.', 'jezweb-dynamic-pricing' );
            $this->customer_email = true;

            parent::__construct();
        }
    }

    /**
     * Price Drop Email
     */
    class JDPD_Email_Price_Drop extends WC_Email {
        public function __construct() {
            $this->id = 'jdpd_price_drop';
            $this->title = __( 'Price Drop Alert', 'jezweb-dynamic-pricing' );
            $this->description = __( 'Price drop alerts sent to subscribed customers.', 'jezweb-dynamic-pricing' );
            $this->customer_email = true;

            parent::__construct();
        }
    }

    /**
     * Cart Abandonment Email
     */
    class JDPD_Email_Cart_Abandonment extends WC_Email {
        public function __construct() {
            $this->id = 'jdpd_cart_abandonment';
            $this->title = __( 'Cart Abandonment Recovery', 'jezweb-dynamic-pricing' );
            $this->description = __( 'Cart abandonment emails with discount coupons.', 'jezweb-dynamic-pricing' );
            $this->customer_email = true;

            parent::__construct();
        }
    }
}
