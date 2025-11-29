<?php
/**
 * Exit Intent Offers
 *
 * Display popup offers when users attempt to leave the site.
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JDPD_Exit_Intent class.
 */
class JDPD_Exit_Intent {

    /**
     * Instance
     *
     * @var JDPD_Exit_Intent
     */
    private static $instance = null;

    /**
     * Table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Settings
     *
     * @var array
     */
    private $settings;

    /**
     * Get instance
     *
     * @return JDPD_Exit_Intent
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
        $this->table_name = $wpdb->prefix . 'jdpd_exit_offers';
        $this->settings = $this->get_settings();
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Create table
        add_action( 'admin_init', array( $this, 'maybe_create_table' ) );

        // Display popup
        add_action( 'wp_footer', array( $this, 'render_popup' ) );

        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // AJAX
        add_action( 'wp_ajax_jdpd_get_exit_offer', array( $this, 'ajax_get_offer' ) );
        add_action( 'wp_ajax_nopriv_jdpd_get_exit_offer', array( $this, 'ajax_get_offer' ) );
        add_action( 'wp_ajax_jdpd_track_exit_conversion', array( $this, 'ajax_track_conversion' ) );
        add_action( 'wp_ajax_nopriv_jdpd_track_exit_conversion', array( $this, 'ajax_track_conversion' ) );
        add_action( 'wp_ajax_jdpd_dismiss_exit_offer', array( $this, 'ajax_dismiss_offer' ) );
        add_action( 'wp_ajax_nopriv_jdpd_dismiss_exit_offer', array( $this, 'ajax_dismiss_offer' ) );

        // Admin AJAX
        add_action( 'wp_ajax_jdpd_save_exit_offer', array( $this, 'ajax_save_offer' ) );
        add_action( 'wp_ajax_jdpd_get_exit_offers', array( $this, 'ajax_get_offers' ) );
        add_action( 'wp_ajax_jdpd_delete_exit_offer', array( $this, 'ajax_delete_offer' ) );
        add_action( 'wp_ajax_jdpd_save_exit_settings', array( $this, 'ajax_save_settings' ) );
    }

    /**
     * Get settings
     *
     * @return array
     */
    private function get_settings() {
        return get_option( 'jdpd_exit_intent_settings', array(
            'enabled'           => 'yes',
            'trigger_sensitivity' => 20, // pixels from top
            'trigger_delay'     => 3000, // ms before enabling
            'show_once_per'     => 'session', // session, day, week
            'mobile_enabled'    => 'yes',
            'mobile_trigger'    => 'scroll', // scroll, time
            'mobile_scroll_pct' => 50,
            'mobile_time_sec'   => 30,
            'exclude_pages'     => array( 'checkout', 'cart', 'my-account' ),
        ) );
    }

    /**
     * Create database table
     */
    public function maybe_create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            offer_type varchar(50) DEFAULT 'discount',
            discount_type varchar(20) DEFAULT 'percentage',
            discount_value decimal(10,2) DEFAULT 0,
            headline varchar(255) DEFAULT '',
            description text,
            button_text varchar(100) DEFAULT 'Get Discount',
            image_url varchar(500) DEFAULT '',
            coupon_code varchar(50) DEFAULT '',
            auto_apply varchar(5) DEFAULT 'yes',
            show_on varchar(50) DEFAULT 'all',
            show_on_pages text,
            show_on_products text,
            show_on_categories text,
            min_cart_value decimal(10,2) DEFAULT 0,
            target_customers varchar(50) DEFAULT 'all',
            start_date datetime DEFAULT NULL,
            end_date datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            priority int(11) DEFAULT 10,
            impressions int(11) DEFAULT 0,
            conversions int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY priority (priority)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Get active offers for current page
     *
     * @return object|null
     */
    public function get_active_offer() {
        if ( $this->settings['enabled'] !== 'yes' ) {
            return null;
        }

        // Check exclusions
        if ( $this->is_excluded_page() ) {
            return null;
        }

        // Check if already shown
        if ( $this->was_recently_shown() ) {
            return null;
        }

        global $wpdb;

        $now = current_time( 'mysql' );

        $offers = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE status = 'active'
             AND (start_date IS NULL OR start_date <= %s)
             AND (end_date IS NULL OR end_date >= %s)
             ORDER BY priority ASC",
            $now, $now
        ) );

        foreach ( $offers as $offer ) {
            if ( $this->offer_matches_conditions( $offer ) ) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * Check if current page is excluded
     *
     * @return bool
     */
    private function is_excluded_page() {
        $excluded = $this->settings['exclude_pages'] ?? array();

        if ( in_array( 'checkout', $excluded ) && is_checkout() ) {
            return true;
        }
        if ( in_array( 'cart', $excluded ) && is_cart() ) {
            return true;
        }
        if ( in_array( 'my-account', $excluded ) && is_account_page() ) {
            return true;
        }

        return false;
    }

    /**
     * Check if offer was recently shown
     *
     * @return bool
     */
    private function was_recently_shown() {
        $show_once = $this->settings['show_once_per'] ?? 'session';

        if ( $show_once === 'session' ) {
            return isset( $_COOKIE['jdpd_exit_shown'] );
        }

        $last_shown = isset( $_COOKIE['jdpd_exit_timestamp'] ) ? absint( $_COOKIE['jdpd_exit_timestamp'] ) : 0;

        if ( ! $last_shown ) {
            return false;
        }

        $interval = $show_once === 'day' ? DAY_IN_SECONDS : WEEK_IN_SECONDS;

        return ( time() - $last_shown ) < $interval;
    }

    /**
     * Check if offer matches current conditions
     *
     * @param object $offer Offer object.
     * @return bool
     */
    private function offer_matches_conditions( $offer ) {
        // Check page conditions
        if ( $offer->show_on !== 'all' ) {
            if ( $offer->show_on === 'products' && ! is_product() ) {
                return false;
            }
            if ( $offer->show_on === 'categories' && ! is_product_category() ) {
                return false;
            }
            if ( $offer->show_on === 'cart' && ! is_cart() ) {
                return false;
            }
            if ( $offer->show_on === 'specific_pages' ) {
                $pages = ! empty( $offer->show_on_pages ) ? explode( ',', $offer->show_on_pages ) : array();
                if ( ! in_array( get_the_ID(), array_map( 'absint', $pages ) ) ) {
                    return false;
                }
            }
            if ( $offer->show_on === 'specific_products' && is_product() ) {
                global $product;
                $products = ! empty( $offer->show_on_products ) ? explode( ',', $offer->show_on_products ) : array();
                if ( ! in_array( $product->get_id(), array_map( 'absint', $products ) ) ) {
                    return false;
                }
            }
            if ( $offer->show_on === 'specific_categories' && is_product() ) {
                global $product;
                $categories = ! empty( $offer->show_on_categories ) ? explode( ',', $offer->show_on_categories ) : array();
                if ( ! array_intersect( $product->get_category_ids(), array_map( 'absint', $categories ) ) ) {
                    return false;
                }
            }
        }

        // Check cart value
        if ( $offer->min_cart_value > 0 && WC()->cart ) {
            if ( WC()->cart->get_subtotal() < $offer->min_cart_value ) {
                return false;
            }
        }

        // Check customer targeting
        if ( $offer->target_customers !== 'all' ) {
            $is_logged_in = is_user_logged_in();

            if ( $offer->target_customers === 'logged_in' && ! $is_logged_in ) {
                return false;
            }
            if ( $offer->target_customers === 'guests' && $is_logged_in ) {
                return false;
            }
            if ( $offer->target_customers === 'new_customers' && $is_logged_in ) {
                $order_count = wc_get_customer_order_count( get_current_user_id() );
                if ( $order_count > 0 ) {
                    return false;
                }
            }
            if ( $offer->target_customers === 'returning' && $is_logged_in ) {
                $order_count = wc_get_customer_order_count( get_current_user_id() );
                if ( $order_count === 0 ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Render popup HTML
     */
    public function render_popup() {
        if ( $this->settings['enabled'] !== 'yes' ) {
            return;
        }

        if ( is_admin() || $this->is_excluded_page() ) {
            return;
        }

        $offer = $this->get_active_offer();
        if ( ! $offer ) {
            return;
        }

        // Track impression
        $this->track_impression( $offer->id );
        ?>
        <div id="jdpd-exit-popup" class="jdpd-exit-popup" data-offer-id="<?php echo esc_attr( $offer->id ); ?>" style="display:none;">
            <div class="exit-popup-overlay"></div>
            <div class="exit-popup-content">
                <button type="button" class="exit-popup-close">&times;</button>

                <?php if ( $offer->image_url ) : ?>
                <div class="exit-popup-image">
                    <img src="<?php echo esc_url( $offer->image_url ); ?>" alt="">
                </div>
                <?php endif; ?>

                <div class="exit-popup-body">
                    <h2 class="exit-popup-headline"><?php echo esc_html( $offer->headline ); ?></h2>

                    <?php if ( $offer->description ) : ?>
                    <p class="exit-popup-description"><?php echo wp_kses_post( $offer->description ); ?></p>
                    <?php endif; ?>

                    <?php if ( $offer->offer_type === 'discount' && $offer->coupon_code ) : ?>
                    <div class="exit-popup-coupon">
                        <span class="coupon-label"><?php esc_html_e( 'Your code:', 'jezweb-dynamic-pricing' ); ?></span>
                        <code class="coupon-code"><?php echo esc_html( $offer->coupon_code ); ?></code>
                        <button type="button" class="copy-coupon" onclick="navigator.clipboard.writeText('<?php echo esc_js( $offer->coupon_code ); ?>'); this.textContent='<?php esc_attr_e( 'Copied!', 'jezweb-dynamic-pricing' ); ?>';">
                            <?php esc_html_e( 'Copy', 'jezweb-dynamic-pricing' ); ?>
                        </button>
                    </div>
                    <?php endif; ?>

                    <a href="<?php echo esc_url( $this->get_offer_url( $offer ) ); ?>" class="exit-popup-button">
                        <?php echo esc_html( $offer->button_text ?: __( 'Get Discount', 'jezweb-dynamic-pricing' ) ); ?>
                    </a>

                    <button type="button" class="exit-popup-dismiss">
                        <?php esc_html_e( 'No thanks, I\'ll pay full price', 'jezweb-dynamic-pricing' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get offer action URL
     *
     * @param object $offer Offer object.
     * @return string
     */
    private function get_offer_url( $offer ) {
        $url = wc_get_cart_url();

        if ( $offer->auto_apply === 'yes' && $offer->coupon_code ) {
            $url = add_query_arg( 'apply_coupon', $offer->coupon_code, $url );
        }

        return $url;
    }

    /**
     * Track impression
     *
     * @param int $offer_id Offer ID.
     */
    private function track_impression( $offer_id ) {
        global $wpdb;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_name} SET impressions = impressions + 1 WHERE id = %d",
            $offer_id
        ) );
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        if ( is_admin() || $this->is_excluded_page() ) {
            return;
        }

        if ( $this->settings['enabled'] !== 'yes' ) {
            return;
        }

        wp_enqueue_style( 'jdpd-exit-intent', JDPD_PLUGIN_URL . 'public/assets/css/exit-intent.css', array(), JDPD_VERSION );
        wp_enqueue_script( 'jdpd-exit-intent', JDPD_PLUGIN_URL . 'public/assets/js/exit-intent.js', array( 'jquery' ), JDPD_VERSION, true );

        wp_localize_script( 'jdpd-exit-intent', 'jdpdExitIntent', array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'jdpd_exit_nonce' ),
            'sensitivity'      => absint( $this->settings['trigger_sensitivity'] ),
            'delay'            => absint( $this->settings['trigger_delay'] ),
            'mobileEnabled'    => $this->settings['mobile_enabled'] === 'yes',
            'mobileTrigger'    => $this->settings['mobile_trigger'],
            'mobileScrollPct'  => absint( $this->settings['mobile_scroll_pct'] ),
            'mobileTimeSec'    => absint( $this->settings['mobile_time_sec'] ),
        ) );
    }

    /**
     * AJAX get offer
     */
    public function ajax_get_offer() {
        check_ajax_referer( 'jdpd_exit_nonce', 'nonce' );

        $offer = $this->get_active_offer();

        if ( ! $offer ) {
            wp_send_json_error( 'No offer available' );
        }

        wp_send_json_success( array(
            'id'           => $offer->id,
            'headline'     => $offer->headline,
            'description'  => $offer->description,
            'coupon_code'  => $offer->coupon_code,
            'button_text'  => $offer->button_text,
            'image_url'    => $offer->image_url,
            'auto_apply'   => $offer->auto_apply,
            'discount_type'=> $offer->discount_type,
            'discount_value'=>$offer->discount_value,
        ) );
    }

    /**
     * AJAX track conversion
     */
    public function ajax_track_conversion() {
        check_ajax_referer( 'jdpd_exit_nonce', 'nonce' );

        $offer_id = absint( $_POST['offer_id'] ?? 0 );

        if ( ! $offer_id ) {
            wp_send_json_error( 'Invalid offer ID' );
        }

        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_name} SET conversions = conversions + 1 WHERE id = %d",
            $offer_id
        ) );

        wp_send_json_success();
    }

    /**
     * AJAX dismiss offer
     */
    public function ajax_dismiss_offer() {
        check_ajax_referer( 'jdpd_exit_nonce', 'nonce' );

        // Set cookie to prevent showing again
        $show_once = $this->settings['show_once_per'] ?? 'session';

        if ( $show_once === 'session' ) {
            setcookie( 'jdpd_exit_shown', '1', 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        } else {
            $expiry = $show_once === 'day' ? time() + DAY_IN_SECONDS : time() + WEEK_IN_SECONDS;
            setcookie( 'jdpd_exit_timestamp', time(), $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        }

        wp_send_json_success();
    }

    /**
     * AJAX save offer
     */
    public function ajax_save_offer() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        global $wpdb;

        $id = absint( $_POST['id'] ?? 0 );

        $data = array(
            'name'               => sanitize_text_field( $_POST['name'] ?? '' ),
            'offer_type'         => sanitize_text_field( $_POST['offer_type'] ?? 'discount' ),
            'discount_type'      => sanitize_text_field( $_POST['discount_type'] ?? 'percentage' ),
            'discount_value'     => floatval( $_POST['discount_value'] ?? 0 ),
            'headline'           => sanitize_text_field( $_POST['headline'] ?? '' ),
            'description'        => wp_kses_post( $_POST['description'] ?? '' ),
            'button_text'        => sanitize_text_field( $_POST['button_text'] ?? 'Get Discount' ),
            'image_url'          => esc_url_raw( $_POST['image_url'] ?? '' ),
            'coupon_code'        => sanitize_text_field( $_POST['coupon_code'] ?? '' ),
            'auto_apply'         => sanitize_text_field( $_POST['auto_apply'] ?? 'yes' ),
            'show_on'            => sanitize_text_field( $_POST['show_on'] ?? 'all' ),
            'show_on_pages'      => isset( $_POST['show_on_pages'] ) ? implode( ',', array_map( 'absint', (array) $_POST['show_on_pages'] ) ) : '',
            'show_on_products'   => isset( $_POST['show_on_products'] ) ? implode( ',', array_map( 'absint', (array) $_POST['show_on_products'] ) ) : '',
            'show_on_categories' => isset( $_POST['show_on_categories'] ) ? implode( ',', array_map( 'absint', (array) $_POST['show_on_categories'] ) ) : '',
            'min_cart_value'     => floatval( $_POST['min_cart_value'] ?? 0 ),
            'target_customers'   => sanitize_text_field( $_POST['target_customers'] ?? 'all' ),
            'start_date'         => ! empty( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : null,
            'end_date'           => ! empty( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : null,
            'status'             => sanitize_text_field( $_POST['status'] ?? 'active' ),
            'priority'           => absint( $_POST['priority'] ?? 10 ),
        );

        $format = array( '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d' );

        if ( $id ) {
            $wpdb->update( $this->table_name, $data, array( 'id' => $id ), $format, array( '%d' ) );
        } else {
            $wpdb->insert( $this->table_name, $data, $format );
            $id = $wpdb->insert_id;
        }

        wp_send_json_success( array( 'id' => $id ) );
    }

    /**
     * AJAX get offers
     */
    public function ajax_get_offers() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        global $wpdb;

        $offers = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY priority ASC, created_at DESC"
        );

        wp_send_json_success( $offers );
    }

    /**
     * AJAX delete offer
     */
    public function ajax_delete_offer() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $id = absint( $_POST['id'] ?? 0 );

        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID' );
        }

        global $wpdb;
        $wpdb->delete( $this->table_name, array( 'id' => $id ), array( '%d' ) );

        wp_send_json_success();
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
            'enabled'            => sanitize_text_field( $_POST['enabled'] ?? 'yes' ),
            'trigger_sensitivity'=> absint( $_POST['trigger_sensitivity'] ?? 20 ),
            'trigger_delay'      => absint( $_POST['trigger_delay'] ?? 3000 ),
            'show_once_per'      => sanitize_text_field( $_POST['show_once_per'] ?? 'session' ),
            'mobile_enabled'     => sanitize_text_field( $_POST['mobile_enabled'] ?? 'yes' ),
            'mobile_trigger'     => sanitize_text_field( $_POST['mobile_trigger'] ?? 'scroll' ),
            'mobile_scroll_pct'  => absint( $_POST['mobile_scroll_pct'] ?? 50 ),
            'mobile_time_sec'    => absint( $_POST['mobile_time_sec'] ?? 30 ),
            'exclude_pages'      => isset( $_POST['exclude_pages'] ) ? array_map( 'sanitize_text_field', (array) $_POST['exclude_pages'] ) : array(),
        );

        update_option( 'jdpd_exit_intent_settings', $settings );
        $this->settings = $settings;

        wp_send_json_success();
    }

    /**
     * Get offer stats
     *
     * @param int $offer_id Offer ID.
     * @return array
     */
    public function get_offer_stats( $offer_id ) {
        global $wpdb;

        $offer = $wpdb->get_row( $wpdb->prepare(
            "SELECT impressions, conversions FROM {$this->table_name} WHERE id = %d",
            $offer_id
        ) );

        if ( ! $offer ) {
            return array(
                'impressions'     => 0,
                'conversions'     => 0,
                'conversion_rate' => 0,
            );
        }

        return array(
            'impressions'     => absint( $offer->impressions ),
            'conversions'     => absint( $offer->conversions ),
            'conversion_rate' => $offer->impressions > 0
                ? round( ( $offer->conversions / $offer->impressions ) * 100, 2 )
                : 0,
        );
    }
}

// Initialize
JDPD_Exit_Intent::get_instance();
