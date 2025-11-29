<?php
/**
 * Flash Sales Manager
 *
 * Create and manage time-limited flash sales with automatic scheduling.
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JDPD_Flash_Sales class.
 */
class JDPD_Flash_Sales {

    /**
     * Instance
     *
     * @var JDPD_Flash_Sales
     */
    private static $instance = null;

    /**
     * Table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Get instance
     *
     * @return JDPD_Flash_Sales
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
        $this->table_name = $wpdb->prefix . 'jdpd_flash_sales';
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Create table
        add_action( 'admin_init', array( $this, 'maybe_create_table' ) );

        // Apply flash sale prices
        add_filter( 'woocommerce_product_get_price', array( $this, 'apply_flash_price' ), 15, 2 );
        add_filter( 'woocommerce_product_get_sale_price', array( $this, 'apply_flash_price' ), 15, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'apply_flash_price' ), 15, 2 );
        add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'apply_flash_price' ), 15, 2 );

        // Mark as on sale
        add_filter( 'woocommerce_product_is_on_sale', array( $this, 'mark_on_sale' ), 15, 2 );

        // Display flash sale badge
        add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'display_flash_badge' ), 9 );
        add_action( 'woocommerce_before_single_product_summary', array( $this, 'display_flash_badge' ), 9 );

        // Display countdown timer
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_countdown' ), 25 );

        // AJAX endpoints
        add_action( 'wp_ajax_jdpd_create_flash_sale', array( $this, 'ajax_create_flash_sale' ) );
        add_action( 'wp_ajax_jdpd_get_flash_sales', array( $this, 'ajax_get_flash_sales' ) );
        add_action( 'wp_ajax_jdpd_delete_flash_sale', array( $this, 'ajax_delete_flash_sale' ) );
        add_action( 'wp_ajax_jdpd_toggle_flash_sale', array( $this, 'ajax_toggle_flash_sale' ) );

        // Cron for expired sales
        add_action( 'jdpd_check_expired_flash_sales', array( $this, 'check_expired_sales' ) );
        if ( ! wp_next_scheduled( 'jdpd_check_expired_flash_sales' ) ) {
            wp_schedule_event( time(), 'hourly', 'jdpd_check_expired_flash_sales' );
        }

        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Shortcodes
        add_shortcode( 'jdpd_flash_sale_products', array( $this, 'shortcode_flash_products' ) );
        add_shortcode( 'jdpd_flash_sale_countdown', array( $this, 'shortcode_countdown' ) );
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
            product_ids text,
            category_ids text,
            discount_type varchar(20) DEFAULT 'percentage',
            discount_value decimal(10,2) NOT NULL,
            start_time datetime NOT NULL,
            end_time datetime NOT NULL,
            max_quantity int(11) DEFAULT 0,
            sold_quantity int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'scheduled',
            priority int(11) DEFAULT 10,
            show_countdown varchar(5) DEFAULT 'yes',
            show_stock varchar(5) DEFAULT 'yes',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY start_time (start_time),
            KEY end_time (end_time)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Get active flash sales
     *
     * @return array
     */
    public function get_active_sales() {
        global $wpdb;

        $now = current_time( 'mysql' );

        $sales = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE status = 'active'
             AND start_time <= %s
             AND end_time >= %s
             ORDER BY priority ASC",
            $now, $now
        ) );

        return $sales ?: array();
    }

    /**
     * Get flash sale for product
     *
     * @param int $product_id Product ID.
     * @return object|null
     */
    public function get_sale_for_product( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return null;
        }

        // Check cache
        $cache_key = 'jdpd_flash_sale_' . $product_id;
        $cached = wp_cache_get( $cache_key );
        if ( false !== $cached ) {
            return $cached ?: null;
        }

        $active_sales = $this->get_active_sales();
        $parent_id = $product->get_parent_id() ?: $product_id;
        $category_ids = $product->get_category_ids();

        foreach ( $active_sales as $sale ) {
            // Check max quantity
            if ( $sale->max_quantity > 0 && $sale->sold_quantity >= $sale->max_quantity ) {
                continue;
            }

            // Check product IDs
            $sale_product_ids = ! empty( $sale->product_ids ) ? array_map( 'absint', explode( ',', $sale->product_ids ) ) : array();
            if ( ! empty( $sale_product_ids ) ) {
                if ( in_array( $product_id, $sale_product_ids, true ) || in_array( $parent_id, $sale_product_ids, true ) ) {
                    wp_cache_set( $cache_key, $sale, '', 300 );
                    return $sale;
                }
            }

            // Check category IDs
            $sale_category_ids = ! empty( $sale->category_ids ) ? array_map( 'absint', explode( ',', $sale->category_ids ) ) : array();
            if ( ! empty( $sale_category_ids ) && ! empty( $category_ids ) ) {
                if ( array_intersect( $category_ids, $sale_category_ids ) ) {
                    wp_cache_set( $cache_key, $sale, '', 300 );
                    return $sale;
                }
            }
        }

        wp_cache_set( $cache_key, '', '', 300 );
        return null;
    }

    /**
     * Apply flash sale price
     *
     * @param float      $price   Price.
     * @param WC_Product $product Product.
     * @return float
     */
    public function apply_flash_price( $price, $product ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $price;
        }

        if ( empty( $price ) ) {
            return $price;
        }

        $sale = $this->get_sale_for_product( $product->get_id() );
        if ( ! $sale ) {
            return $price;
        }

        // Get regular price for calculation
        $regular_price = $product->get_regular_price();
        if ( empty( $regular_price ) ) {
            $regular_price = $price;
        }

        if ( $sale->discount_type === 'percentage' ) {
            $flash_price = $regular_price * ( 1 - ( $sale->discount_value / 100 ) );
        } elseif ( $sale->discount_type === 'fixed' ) {
            $flash_price = max( 0, $regular_price - $sale->discount_value );
        } else {
            $flash_price = $sale->discount_value; // Fixed price
        }

        return $flash_price;
    }

    /**
     * Mark product as on sale
     *
     * @param bool       $on_sale On sale status.
     * @param WC_Product $product Product.
     * @return bool
     */
    public function mark_on_sale( $on_sale, $product ) {
        if ( $on_sale ) {
            return $on_sale;
        }

        $sale = $this->get_sale_for_product( $product->get_id() );
        return $sale ? true : $on_sale;
    }

    /**
     * Display flash sale badge
     */
    public function display_flash_badge() {
        global $product;

        if ( ! $product ) {
            return;
        }

        $sale = $this->get_sale_for_product( $product->get_id() );
        if ( ! $sale ) {
            return;
        }

        $discount_text = $sale->discount_type === 'percentage'
            ? '-' . intval( $sale->discount_value ) . '%'
            : '-' . wc_price( $sale->discount_value );

        ?>
        <span class="jdpd-flash-badge">
            <span class="flash-icon">⚡</span>
            <span class="flash-text"><?php esc_html_e( 'Flash Sale', 'jezweb-dynamic-pricing' ); ?></span>
            <span class="flash-discount"><?php echo esc_html( $discount_text ); ?></span>
        </span>
        <style>
            .jdpd-flash-badge {
                position: absolute;
                top: 10px;
                left: 10px;
                background: linear-gradient(135deg, #ff6b6b, #ee5a5a);
                color: #fff;
                padding: 5px 10px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: bold;
                z-index: 10;
                animation: flash-pulse 1.5s infinite;
            }
            .flash-icon { margin-right: 3px; }
            @keyframes flash-pulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.05); }
            }
        </style>
        <?php
    }

    /**
     * Display countdown timer on product page
     */
    public function display_countdown() {
        global $product;

        if ( ! $product ) {
            return;
        }

        $sale = $this->get_sale_for_product( $product->get_id() );
        if ( ! $sale || $sale->show_countdown !== 'yes' ) {
            return;
        }

        $end_timestamp = strtotime( $sale->end_time );
        $remaining = max( 0, $end_timestamp - time() );

        if ( $remaining <= 0 ) {
            return;
        }

        ?>
        <div class="jdpd-flash-countdown" data-end="<?php echo esc_attr( $end_timestamp ); ?>">
            <div class="countdown-label">⚡ <?php esc_html_e( 'Flash Sale ends in:', 'jezweb-dynamic-pricing' ); ?></div>
            <div class="countdown-timer">
                <div class="countdown-item">
                    <span class="countdown-value" id="jdpd-days">00</span>
                    <span class="countdown-unit"><?php esc_html_e( 'Days', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
                <div class="countdown-item">
                    <span class="countdown-value" id="jdpd-hours">00</span>
                    <span class="countdown-unit"><?php esc_html_e( 'Hours', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
                <div class="countdown-item">
                    <span class="countdown-value" id="jdpd-minutes">00</span>
                    <span class="countdown-unit"><?php esc_html_e( 'Mins', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
                <div class="countdown-item">
                    <span class="countdown-value" id="jdpd-seconds">00</span>
                    <span class="countdown-unit"><?php esc_html_e( 'Secs', 'jezweb-dynamic-pricing' ); ?></span>
                </div>
            </div>
            <?php if ( $sale->show_stock === 'yes' && $sale->max_quantity > 0 ) : ?>
            <div class="countdown-stock">
                <?php
                $remaining_qty = max( 0, $sale->max_quantity - $sale->sold_quantity );
                $percent_sold = ( $sale->sold_quantity / $sale->max_quantity ) * 100;
                ?>
                <div class="stock-bar">
                    <div class="stock-fill" style="width: <?php echo esc_attr( $percent_sold ); ?>%"></div>
                </div>
                <span class="stock-text">
                    <?php printf( esc_html__( 'Only %d left!', 'jezweb-dynamic-pricing' ), $remaining_qty ); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Create a flash sale
     *
     * @param array $data Sale data.
     * @return int|WP_Error
     */
    public function create_sale( $data ) {
        global $wpdb;

        $defaults = array(
            'name'           => '',
            'product_ids'    => '',
            'category_ids'   => '',
            'discount_type'  => 'percentage',
            'discount_value' => 10,
            'start_time'     => current_time( 'mysql' ),
            'end_time'       => date( 'Y-m-d H:i:s', strtotime( '+24 hours' ) ),
            'max_quantity'   => 0,
            'priority'       => 10,
            'show_countdown' => 'yes',
            'show_stock'     => 'yes',
        );

        $data = wp_parse_args( $data, $defaults );

        // Determine initial status
        $now = current_time( 'mysql' );
        if ( $data['start_time'] <= $now && $data['end_time'] >= $now ) {
            $status = 'active';
        } elseif ( $data['start_time'] > $now ) {
            $status = 'scheduled';
        } else {
            $status = 'expired';
        }

        $result = $wpdb->insert(
            $this->table_name,
            array(
                'name'           => sanitize_text_field( $data['name'] ),
                'product_ids'    => is_array( $data['product_ids'] ) ? implode( ',', array_map( 'absint', $data['product_ids'] ) ) : $data['product_ids'],
                'category_ids'   => is_array( $data['category_ids'] ) ? implode( ',', array_map( 'absint', $data['category_ids'] ) ) : $data['category_ids'],
                'discount_type'  => sanitize_text_field( $data['discount_type'] ),
                'discount_value' => floatval( $data['discount_value'] ),
                'start_time'     => $data['start_time'],
                'end_time'       => $data['end_time'],
                'max_quantity'   => absint( $data['max_quantity'] ),
                'priority'       => absint( $data['priority'] ),
                'show_countdown' => $data['show_countdown'],
                'show_stock'     => $data['show_stock'],
                'status'         => $status,
            ),
            array( '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
        );

        if ( ! $result ) {
            return new WP_Error( 'db_error', __( 'Failed to create flash sale', 'jezweb-dynamic-pricing' ) );
        }

        // Clear caches
        wp_cache_flush();

        return $wpdb->insert_id;
    }

    /**
     * Update sold quantity
     *
     * @param int $sale_id  Sale ID.
     * @param int $quantity Quantity sold.
     */
    public function update_sold_quantity( $sale_id, $quantity = 1 ) {
        global $wpdb;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_name} SET sold_quantity = sold_quantity + %d WHERE id = %d",
            $quantity, $sale_id
        ) );
    }

    /**
     * Check and update expired sales
     */
    public function check_expired_sales() {
        global $wpdb;

        $now = current_time( 'mysql' );

        // Mark expired
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_name} SET status = 'expired' WHERE status = 'active' AND end_time < %s",
            $now
        ) );

        // Activate scheduled
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_name} SET status = 'active' WHERE status = 'scheduled' AND start_time <= %s AND end_time >= %s",
            $now, $now
        ) );

        // Clear caches
        wp_cache_flush();
    }

    /**
     * AJAX create flash sale
     */
    public function ajax_create_flash_sale() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $data = array(
            'name'           => sanitize_text_field( $_POST['name'] ?? '' ),
            'product_ids'    => isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array(),
            'category_ids'   => isset( $_POST['category_ids'] ) ? array_map( 'absint', (array) $_POST['category_ids'] ) : array(),
            'discount_type'  => sanitize_text_field( $_POST['discount_type'] ?? 'percentage' ),
            'discount_value' => floatval( $_POST['discount_value'] ?? 10 ),
            'start_time'     => sanitize_text_field( $_POST['start_time'] ?? '' ),
            'end_time'       => sanitize_text_field( $_POST['end_time'] ?? '' ),
            'max_quantity'   => absint( $_POST['max_quantity'] ?? 0 ),
            'show_countdown' => sanitize_text_field( $_POST['show_countdown'] ?? 'yes' ),
            'show_stock'     => sanitize_text_field( $_POST['show_stock'] ?? 'yes' ),
        );

        $result = $this->create_sale( $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array( 'id' => $result ) );
    }

    /**
     * AJAX get flash sales
     */
    public function ajax_get_flash_sales() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        global $wpdb;

        $status = sanitize_text_field( $_POST['status'] ?? '' );
        $where = $status ? $wpdb->prepare( "WHERE status = %s", $status ) : '';

        $sales = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} {$where} ORDER BY created_at DESC"
        );

        wp_send_json_success( $sales );
    }

    /**
     * AJAX delete flash sale
     */
    public function ajax_delete_flash_sale() {
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

        wp_cache_flush();
        wp_send_json_success();
    }

    /**
     * AJAX toggle flash sale status
     */
    public function ajax_toggle_flash_sale() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $id = absint( $_POST['id'] ?? 0 );
        $status = sanitize_text_field( $_POST['status'] ?? 'active' );

        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID' );
        }

        global $wpdb;
        $wpdb->update(
            $this->table_name,
            array( 'status' => $status ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );

        wp_cache_flush();
        wp_send_json_success();
    }

    /**
     * Shortcode: Flash sale products grid
     *
     * @param array $atts Attributes.
     * @return string
     */
    public function shortcode_flash_products( $atts ) {
        $atts = shortcode_atts( array(
            'columns' => 4,
            'limit'   => 8,
        ), $atts );

        $active_sales = $this->get_active_sales();
        if ( empty( $active_sales ) ) {
            return '';
        }

        $product_ids = array();
        foreach ( $active_sales as $sale ) {
            if ( ! empty( $sale->product_ids ) ) {
                $product_ids = array_merge( $product_ids, array_map( 'absint', explode( ',', $sale->product_ids ) ) );
            }
        }

        if ( empty( $product_ids ) ) {
            return '';
        }

        $product_ids = array_unique( array_slice( $product_ids, 0, absint( $atts['limit'] ) ) );

        return do_shortcode( '[products ids="' . implode( ',', $product_ids ) . '" columns="' . absint( $atts['columns'] ) . '"]' );
    }

    /**
     * Shortcode: Countdown for specific sale
     *
     * @param array $atts Attributes.
     * @return string
     */
    public function shortcode_countdown( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts );

        if ( ! $atts['id'] ) {
            $sales = $this->get_active_sales();
            if ( empty( $sales ) ) {
                return '';
            }
            $sale = $sales[0];
        } else {
            global $wpdb;
            $sale = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d AND status = 'active'",
                $atts['id']
            ) );
        }

        if ( ! $sale ) {
            return '';
        }

        $end_timestamp = strtotime( $sale->end_time );

        ob_start();
        ?>
        <div class="jdpd-flash-countdown-widget" data-end="<?php echo esc_attr( $end_timestamp ); ?>">
            <h3><?php echo esc_html( $sale->name ); ?></h3>
            <div class="countdown-timer" id="jdpd-countdown-<?php echo esc_attr( $sale->id ); ?>">
                <span class="time-block"><span class="days">00</span> Days</span>
                <span class="time-block"><span class="hours">00</span> Hours</span>
                <span class="time-block"><span class="minutes">00</span> Mins</span>
                <span class="time-block"><span class="seconds">00</span> Secs</span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        if ( ! is_product() && ! is_shop() && ! is_product_category() ) {
            return;
        }

        wp_enqueue_style( 'jdpd-flash-sales', JDPD_PLUGIN_URL . 'public/assets/css/flash-sales.css', array(), JDPD_VERSION );
        wp_enqueue_script( 'jdpd-flash-sales', JDPD_PLUGIN_URL . 'public/assets/js/flash-sales.js', array( 'jquery' ), JDPD_VERSION, true );
    }
}

// Initialize
JDPD_Flash_Sales::get_instance();
