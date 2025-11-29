<?php
/**
 * Price History Graph
 *
 * Track and display product price history over time.
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Includes
 * @since      1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JDPD_Price_History class.
 *
 * Features:
 * - Automatic price tracking on product updates
 * - Interactive price history graph on product pages
 * - Historical high/low price display
 * - Price drop alerts
 * - EU Omnibus Directive compliance (30-day lowest price)
 * - Admin price history viewer
 *
 * @since 1.4.0
 */
class JDPD_Price_History {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Price_History
     */
    private static $instance = null;

    /**
     * Database table name.
     *
     * @var string
     */
    private $table_name;

    /**
     * Get single instance.
     *
     * @return JDPD_Price_History
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'jdpd_price_history';
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'), 25);
        add_action('admin_init', array($this, 'register_settings'));

        // Price tracking hooks
        add_action('woocommerce_update_product', array($this, 'track_price_change'), 10, 2);
        add_action('woocommerce_product_set_price', array($this, 'on_price_set'), 10, 2);
        add_action('woocommerce_variation_set_price', array($this, 'on_price_set'), 10, 2);

        // Frontend display
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('woocommerce_single_product_summary', array($this, 'display_price_history'), 25);

        // Shortcode
        add_shortcode('jdpd_price_history', array($this, 'price_history_shortcode'));

        // Product meta box
        add_action('add_meta_boxes', array($this, 'add_product_meta_box'));

        // AJAX handlers
        add_action('wp_ajax_jdpd_get_price_history', array($this, 'ajax_get_price_history'));
        add_action('wp_ajax_nopriv_jdpd_get_price_history', array($this, 'ajax_get_price_history'));

        // Scheduled cleanup
        add_action('jdpd_price_history_cleanup', array($this, 'cleanup_old_records'));

        // EU Omnibus Directive - show lowest price in last 30 days
        add_filter('woocommerce_get_price_html', array($this, 'add_omnibus_price'), 99, 2);
    }

    /**
     * Create database table.
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'jdpd_price_history';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id bigint(20) UNSIGNED NOT NULL,
            variation_id bigint(20) UNSIGNED DEFAULT 0,
            regular_price decimal(19,4) DEFAULT NULL,
            sale_price decimal(19,4) DEFAULT NULL,
            effective_price decimal(19,4) NOT NULL,
            change_type varchar(50) DEFAULT 'update',
            recorded_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY variation_id (variation_id),
            KEY recorded_at (recorded_at),
            KEY product_date (product_id, recorded_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'jezweb-dynamic-pricing',
            __('Price History', 'jezweb-dynamic-pricing'),
            __('Price History', 'jezweb-dynamic-pricing'),
            'manage_woocommerce',
            'jdpd-price-history',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting('jdpd_price_history', 'jdpd_price_history_options');
    }

    /**
     * Render admin page.
     */
    public function render_admin_page() {
        $options = get_option('jdpd_price_history_options', $this->get_default_options());
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Price History Settings', 'jezweb-dynamic-pricing'); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields('jdpd_price_history'); ?>

                <h2><?php esc_html_e('Display Settings', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Price History', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_price_history_options[enabled]" value="1"
                                    <?php checked(!empty($options['enabled'])); ?>>
                                <?php esc_html_e('Track and display product price history', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Show Graph on Product Pages', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_price_history_options[show_graph]" value="1"
                                    <?php checked(!empty($options['show_graph'])); ?>>
                                <?php esc_html_e('Display interactive price history graph', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Graph Period', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <select name="jdpd_price_history_options[graph_period]">
                                <option value="30" <?php selected($options['graph_period'] ?? 90, 30); ?>>
                                    <?php esc_html_e('Last 30 days', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="60" <?php selected($options['graph_period'] ?? 90, 60); ?>>
                                    <?php esc_html_e('Last 60 days', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="90" <?php selected($options['graph_period'] ?? 90, 90); ?>>
                                    <?php esc_html_e('Last 90 days', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="180" <?php selected($options['graph_period'] ?? 90, 180); ?>>
                                    <?php esc_html_e('Last 6 months', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="365" <?php selected($options['graph_period'] ?? 90, 365); ?>>
                                    <?php esc_html_e('Last year', 'jezweb-dynamic-pricing'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Show High/Low Prices', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_price_history_options[show_high_low]" value="1"
                                    <?php checked(!empty($options['show_high_low'])); ?>>
                                <?php esc_html_e('Display historical high and low prices', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Position', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <select name="jdpd_price_history_options[position]">
                                <option value="after_price" <?php selected($options['position'] ?? 'after_price', 'after_price'); ?>>
                                    <?php esc_html_e('After price', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="after_add_to_cart" <?php selected($options['position'] ?? 'after_price', 'after_add_to_cart'); ?>>
                                    <?php esc_html_e('After add to cart', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="after_description" <?php selected($options['position'] ?? 'after_price', 'after_description'); ?>>
                                    <?php esc_html_e('After short description', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="shortcode_only" <?php selected($options['position'] ?? 'after_price', 'shortcode_only'); ?>>
                                    <?php esc_html_e('Via shortcode only', 'jezweb-dynamic-pricing'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('EU Omnibus Directive Compliance', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Omnibus Price', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_price_history_options[omnibus_enabled]" value="1"
                                    <?php checked(!empty($options['omnibus_enabled'])); ?>>
                                <?php esc_html_e('Show lowest price in last 30 days (EU Omnibus Directive)', 'jezweb-dynamic-pricing'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Required for EU stores when showing sale prices.', 'jezweb-dynamic-pricing'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Omnibus Text', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="text" name="jdpd_price_history_options[omnibus_text]"
                                value="<?php echo esc_attr($options['omnibus_text'] ?? 'Lowest price in last 30 days: {price}'); ?>"
                                class="large-text">
                            <p class="description">
                                <?php esc_html_e('Use {price} as placeholder for the lowest price.', 'jezweb-dynamic-pricing'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Data Retention', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Keep History For', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <select name="jdpd_price_history_options[retention_days]">
                                <option value="90" <?php selected($options['retention_days'] ?? 365, 90); ?>>
                                    <?php esc_html_e('90 days', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="180" <?php selected($options['retention_days'] ?? 365, 180); ?>>
                                    <?php esc_html_e('6 months', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="365" <?php selected($options['retention_days'] ?? 365, 365); ?>>
                                    <?php esc_html_e('1 year', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="730" <?php selected($options['retention_days'] ?? 365, 730); ?>>
                                    <?php esc_html_e('2 years', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="0" <?php selected($options['retention_days'] ?? 365, 0); ?>>
                                    <?php esc_html_e('Forever', 'jezweb-dynamic-pricing'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Graph Appearance', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Line Color', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="color" name="jdpd_price_history_options[line_color]"
                                value="<?php echo esc_attr($options['line_color'] ?? '#2271b1'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Fill Color', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="color" name="jdpd_price_history_options[fill_color]"
                                value="<?php echo esc_attr($options['fill_color'] ?? '#e8f4fc'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Graph Height', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_price_history_options[graph_height]"
                                value="<?php echo esc_attr($options['graph_height'] ?? 200); ?>" min="100" max="400">
                            px
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Price History Statistics', 'jezweb-dynamic-pricing'); ?></h2>
            <?php $this->render_statistics(); ?>
        </div>
        <?php
    }

    /**
     * Get default options.
     *
     * @return array
     */
    private function get_default_options() {
        return array(
            'enabled' => true,
            'show_graph' => true,
            'graph_period' => 90,
            'show_high_low' => true,
            'position' => 'after_price',
            'omnibus_enabled' => false,
            'omnibus_text' => 'Lowest price in last 30 days: {price}',
            'retention_days' => 365,
            'line_color' => '#2271b1',
            'fill_color' => '#e8f4fc',
            'graph_height' => 200,
        );
    }

    /**
     * Track price change on product update.
     *
     * @param int        $product_id Product ID.
     * @param WC_Product $product    Product object.
     */
    public function track_price_change($product_id, $product) {
        $options = get_option('jdpd_price_history_options', $this->get_default_options());

        if (empty($options['enabled'])) {
            return;
        }

        $this->record_price($product);

        // Handle variations
        if ($product->is_type('variable')) {
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $this->record_price($variation, $product_id);
                }
            }
        }
    }

    /**
     * On price set directly.
     *
     * @param float      $price   New price.
     * @param WC_Product $product Product object.
     */
    public function on_price_set($price, $product) {
        // Will be tracked via woocommerce_update_product hook
    }

    /**
     * Record price in database.
     *
     * @param WC_Product $product    Product object.
     * @param int        $parent_id  Parent product ID for variations.
     */
    private function record_price($product, $parent_id = 0) {
        global $wpdb;

        $product_id = $parent_id ?: $product->get_id();
        $variation_id = $parent_id ? $product->get_id() : 0;

        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        $effective_price = $product->get_price();

        // Check if price actually changed
        $last_record = $this->get_last_price_record($product_id, $variation_id);

        if ($last_record) {
            // Compare prices - only record if changed
            if (
                floatval($last_record->regular_price) == floatval($regular_price) &&
                floatval($last_record->sale_price) == floatval($sale_price) &&
                floatval($last_record->effective_price) == floatval($effective_price)
            ) {
                return; // No change
            }
        }

        // Determine change type
        $change_type = 'update';
        if ($last_record) {
            if (floatval($effective_price) < floatval($last_record->effective_price)) {
                $change_type = 'decrease';
            } elseif (floatval($effective_price) > floatval($last_record->effective_price)) {
                $change_type = 'increase';
            }
        } else {
            $change_type = 'initial';
        }

        $wpdb->insert(
            $this->table_name,
            array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'regular_price' => $regular_price ?: null,
                'sale_price' => $sale_price ?: null,
                'effective_price' => $effective_price,
                'change_type' => $change_type,
                'recorded_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%f', '%f', '%f', '%s', '%s')
        );
    }

    /**
     * Get last price record.
     *
     * @param int $product_id   Product ID.
     * @param int $variation_id Variation ID.
     * @return object|null
     */
    private function get_last_price_record($product_id, $variation_id = 0) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
            WHERE product_id = %d AND variation_id = %d
            ORDER BY recorded_at DESC
            LIMIT 1",
            $product_id,
            $variation_id
        ));
    }

    /**
     * Get price history for product.
     *
     * @param int $product_id   Product ID.
     * @param int $variation_id Variation ID (optional).
     * @param int $days         Number of days to look back.
     * @return array
     */
    public function get_price_history($product_id, $variation_id = 0, $days = 90) {
        global $wpdb;

        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
            WHERE product_id = %d AND variation_id = %d
            AND recorded_at >= %s
            ORDER BY recorded_at ASC",
            $product_id,
            $variation_id,
            $since
        ));
    }

    /**
     * Get lowest price in period.
     *
     * @param int $product_id   Product ID.
     * @param int $variation_id Variation ID (optional).
     * @param int $days         Number of days to look back.
     * @return float|null
     */
    public function get_lowest_price($product_id, $variation_id = 0, $days = 30) {
        global $wpdb;

        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(effective_price) FROM {$this->table_name}
            WHERE product_id = %d AND variation_id = %d
            AND recorded_at >= %s",
            $product_id,
            $variation_id,
            $since
        ));
    }

    /**
     * Get highest price in period.
     *
     * @param int $product_id   Product ID.
     * @param int $variation_id Variation ID (optional).
     * @param int $days         Number of days to look back.
     * @return float|null
     */
    public function get_highest_price($product_id, $variation_id = 0, $days = 30) {
        global $wpdb;

        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(effective_price) FROM {$this->table_name}
            WHERE product_id = %d AND variation_id = %d
            AND recorded_at >= %s",
            $product_id,
            $variation_id,
            $since
        ));
    }

    /**
     * Enqueue frontend scripts.
     */
    public function enqueue_scripts() {
        if (!is_product()) {
            return;
        }

        $options = get_option('jdpd_price_history_options', $this->get_default_options());

        if (empty($options['enabled']) || empty($options['show_graph'])) {
            return;
        }

        // Chart.js library
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            array(),
            '4.4.1',
            true
        );

        wp_enqueue_script(
            'jdpd-price-history',
            JDPD_PLUGIN_URL . 'public/assets/js/price-history.js',
            array('jquery', 'chartjs'),
            JDPD_VERSION,
            true
        );

        wp_localize_script('jdpd-price-history', 'jdpdPriceHistory', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jdpd_price_history_nonce'),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'line_color' => $options['line_color'] ?? '#2271b1',
            'fill_color' => $options['fill_color'] ?? '#e8f4fc',
            'i18n' => array(
                'price' => __('Price', 'jezweb-dynamic-pricing'),
                'date' => __('Date', 'jezweb-dynamic-pricing'),
                'no_data' => __('No price history available', 'jezweb-dynamic-pricing'),
            ),
        ));

        wp_enqueue_style(
            'jdpd-price-history',
            JDPD_PLUGIN_URL . 'public/assets/css/price-history.css',
            array(),
            JDPD_VERSION
        );
    }

    /**
     * Display price history on product page.
     */
    public function display_price_history() {
        global $product;

        if (!$product) {
            return;
        }

        $options = get_option('jdpd_price_history_options', $this->get_default_options());

        if (empty($options['enabled']) || empty($options['show_graph'])) {
            return;
        }

        if (($options['position'] ?? 'after_price') === 'shortcode_only') {
            return;
        }

        echo $this->render_price_history($product->get_id());
    }

    /**
     * Price history shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function price_history_shortcode($atts) {
        $atts = shortcode_atts(array(
            'product_id' => 0,
            'days' => 0,
        ), $atts);

        $product_id = intval($atts['product_id']);

        if (!$product_id) {
            global $product;
            if ($product) {
                $product_id = $product->get_id();
            }
        }

        if (!$product_id) {
            return '';
        }

        return $this->render_price_history($product_id, intval($atts['days']));
    }

    /**
     * Render price history HTML.
     *
     * @param int $product_id Product ID.
     * @param int $days       Optional days override.
     * @return string
     */
    private function render_price_history($product_id, $days = 0) {
        $options = get_option('jdpd_price_history_options', $this->get_default_options());

        if (!$days) {
            $days = intval($options['graph_period'] ?? 90);
        }

        $history = $this->get_price_history($product_id, 0, $days);

        if (empty($history)) {
            return '';
        }

        $lowest = $this->get_lowest_price($product_id, 0, $days);
        $highest = $this->get_highest_price($product_id, 0, $days);
        $height = intval($options['graph_height'] ?? 200);

        ob_start();
        ?>
        <div class="jdpd-price-history-wrap" data-product-id="<?php echo esc_attr($product_id); ?>" data-days="<?php echo esc_attr($days); ?>">
            <h4 class="jdpd-price-history-title">
                <?php esc_html_e('Price History', 'jezweb-dynamic-pricing'); ?>
            </h4>

            <?php if (!empty($options['show_high_low'])): ?>
                <div class="jdpd-price-stats">
                    <span class="jdpd-price-low">
                        <strong><?php esc_html_e('Lowest:', 'jezweb-dynamic-pricing'); ?></strong>
                        <?php echo wc_price($lowest); ?>
                    </span>
                    <span class="jdpd-price-high">
                        <strong><?php esc_html_e('Highest:', 'jezweb-dynamic-pricing'); ?></strong>
                        <?php echo wc_price($highest); ?>
                    </span>
                </div>
            <?php endif; ?>

            <div class="jdpd-price-chart-container" style="height: <?php echo esc_attr($height); ?>px;">
                <canvas id="jdpd-price-chart-<?php echo esc_attr($product_id); ?>"></canvas>
            </div>

            <p class="jdpd-price-history-period">
                <?php printf(
                    esc_html__('Showing price changes over the last %d days', 'jezweb-dynamic-pricing'),
                    $days
                ); ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Add EU Omnibus price display.
     *
     * @param string     $price_html Price HTML.
     * @param WC_Product $product    Product object.
     * @return string
     */
    public function add_omnibus_price($price_html, $product) {
        $options = get_option('jdpd_price_history_options', $this->get_default_options());

        if (empty($options['omnibus_enabled'])) {
            return $price_html;
        }

        // Only show on sale products
        if (!$product->is_on_sale()) {
            return $price_html;
        }

        // Only show on single product page or in cart
        if (!is_product() && !is_cart()) {
            return $price_html;
        }

        $product_id = $product->get_id();
        $variation_id = $product->is_type('variation') ? $product_id : 0;

        if ($variation_id) {
            $product_id = $product->get_parent_id();
        }

        $lowest_price = $this->get_lowest_price($product_id, $variation_id, 30);

        if (!$lowest_price) {
            return $price_html;
        }

        $omnibus_text = $options['omnibus_text'] ?? 'Lowest price in last 30 days: {price}';
        $omnibus_text = str_replace('{price}', wc_price($lowest_price), $omnibus_text);

        $price_html .= '<p class="jdpd-omnibus-price">' . esc_html($omnibus_text) . '</p>';

        return $price_html;
    }

    /**
     * Add product meta box.
     */
    public function add_product_meta_box() {
        add_meta_box(
            'jdpd-price-history',
            __('Price History', 'jezweb-dynamic-pricing'),
            array($this, 'render_product_meta_box'),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render product meta box.
     *
     * @param WP_Post $post Post object.
     */
    public function render_product_meta_box($post) {
        $history = $this->get_price_history($post->ID, 0, 30);
        ?>
        <div class="jdpd-price-history-metabox">
            <p>
                <strong><?php esc_html_e('Last 30 days:', 'jezweb-dynamic-pricing'); ?></strong>
            </p>

            <?php if (empty($history)): ?>
                <p><?php esc_html_e('No price history recorded yet.', 'jezweb-dynamic-pricing'); ?></p>
            <?php else: ?>
                <table class="widefat" style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Price', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Change', 'jezweb-dynamic-pricing'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $recent = array_slice(array_reverse($history), 0, 10);
                        foreach ($recent as $record):
                        ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n('M j', strtotime($record->recorded_at))); ?></td>
                                <td><?php echo wc_price($record->effective_price); ?></td>
                                <td>
                                    <?php
                                    switch ($record->change_type) {
                                        case 'increase':
                                            echo '<span style="color:red;">&#9650;</span>';
                                            break;
                                        case 'decrease':
                                            echo '<span style="color:green;">&#9660;</span>';
                                            break;
                                        default:
                                            echo '&mdash;';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top: 10px;">
                    <strong><?php esc_html_e('Lowest:', 'jezweb-dynamic-pricing'); ?></strong>
                    <?php echo wc_price($this->get_lowest_price($post->ID, 0, 30)); ?>
                    <br>
                    <strong><?php esc_html_e('Highest:', 'jezweb-dynamic-pricing'); ?></strong>
                    <?php echo wc_price($this->get_highest_price($post->ID, 0, 30)); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: Get price history data.
     */
    public function ajax_get_price_history() {
        check_ajax_referer('jdpd_price_history_nonce', 'nonce');

        $product_id = intval($_POST['product_id'] ?? 0);
        $variation_id = intval($_POST['variation_id'] ?? 0);
        $days = intval($_POST['days'] ?? 90);

        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product.', 'jezweb-dynamic-pricing')));
        }

        $history = $this->get_price_history($product_id, $variation_id, $days);

        // Format for Chart.js
        $labels = array();
        $prices = array();

        foreach ($history as $record) {
            $labels[] = date_i18n('M j', strtotime($record->recorded_at));
            $prices[] = floatval($record->effective_price);
        }

        wp_send_json_success(array(
            'labels' => $labels,
            'prices' => $prices,
            'lowest' => $this->get_lowest_price($product_id, $variation_id, $days),
            'highest' => $this->get_highest_price($product_id, $variation_id, $days),
        ));
    }

    /**
     * Cleanup old records (scheduled task).
     */
    public function cleanup_old_records() {
        global $wpdb;

        $options = get_option('jdpd_price_history_options', $this->get_default_options());
        $retention_days = intval($options['retention_days'] ?? 365);

        if ($retention_days <= 0) {
            return; // Keep forever
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE recorded_at < %s",
            $cutoff
        ));
    }

    /**
     * Render statistics.
     */
    private function render_statistics() {
        global $wpdb;

        $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $products_tracked = $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM {$this->table_name}");
        $oldest_record = $wpdb->get_var("SELECT MIN(recorded_at) FROM {$this->table_name}");
        ?>
        <table class="widefat" style="max-width: 400px;">
            <tr>
                <td><?php esc_html_e('Total Price Records', 'jezweb-dynamic-pricing'); ?></td>
                <td><strong><?php echo esc_html(number_format($total_records)); ?></strong></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Products Tracked', 'jezweb-dynamic-pricing'); ?></td>
                <td><strong><?php echo esc_html(number_format($products_tracked)); ?></strong></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Oldest Record', 'jezweb-dynamic-pricing'); ?></td>
                <td>
                    <strong>
                        <?php echo $oldest_record ? esc_html(date_i18n(get_option('date_format'), strtotime($oldest_record))) : '-'; ?>
                    </strong>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Schedule cleanup event.
     */
    public static function schedule_events() {
        if (!wp_next_scheduled('jdpd_price_history_cleanup')) {
            wp_schedule_event(time(), 'daily', 'jdpd_price_history_cleanup');
        }
    }

    /**
     * Clear scheduled events.
     */
    public static function clear_events() {
        wp_clear_scheduled_hook('jdpd_price_history_cleanup');
    }
}

// Initialize the class
JDPD_Price_History::get_instance();
