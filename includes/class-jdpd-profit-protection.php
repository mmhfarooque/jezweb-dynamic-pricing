<?php
/**
 * Profit Margin Protection System
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Jezweb_Dynamic_Pricing/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Profit Margin Protection class.
 *
 * Prevents discounts from going below cost price or minimum profit margins.
 * Supports cost-based pricing and markup rules.
 *
 * @since 1.4.0
 */
class JDPD_Profit_Protection {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Profit_Protection
     */
    private static $instance = null;

    /**
     * Settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Cost price meta key.
     *
     * @var string
     */
    private $cost_meta_key = '_jdpd_cost_price';

    /**
     * Get single instance.
     *
     * @return JDPD_Profit_Protection
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
        $this->settings = $this->get_settings();
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Filter discounted prices to ensure profit margin
        add_filter( 'jdpd_calculated_price', array( $this, 'enforce_minimum_price' ), 999, 3 );
        add_filter( 'jdpd_apply_discount', array( $this, 'validate_discount' ), 10, 4 );

        // Admin product fields for cost price
        add_action( 'woocommerce_product_options_pricing', array( $this, 'add_cost_price_field' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_cost_price_field' ) );

        // Variation cost price
        add_action( 'woocommerce_variation_options_pricing', array( $this, 'add_variation_cost_price_field' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_cost_price_field' ), 10, 2 );

        // Bulk edit support
        add_action( 'woocommerce_product_bulk_edit_end', array( $this, 'add_bulk_edit_fields' ) );
        add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'save_bulk_edit_fields' ) );

        // Quick edit support
        add_action( 'woocommerce_product_quick_edit_end', array( $this, 'add_quick_edit_fields' ) );
        add_action( 'woocommerce_product_quick_edit_save', array( $this, 'save_quick_edit_fields' ) );

        // Admin columns
        add_filter( 'manage_edit-product_columns', array( $this, 'add_profit_column' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'render_profit_column' ), 10, 2 );

        // Import/Export support
        add_filter( 'woocommerce_product_export_column_names', array( $this, 'add_export_column' ) );
        add_filter( 'woocommerce_product_export_product_default_columns', array( $this, 'add_export_column' ) );
        add_filter( 'woocommerce_product_export_product_column_cost_price', array( $this, 'export_cost_price' ), 10, 2 );
        add_filter( 'woocommerce_csv_product_import_mapping_options', array( $this, 'add_import_mapping' ) );
        add_filter( 'woocommerce_product_import_pre_insert_product_object', array( $this, 'import_cost_price' ), 10, 2 );

        // Admin notices for protected discounts
        add_action( 'admin_notices', array( $this, 'show_protection_notices' ) );

        // AJAX handlers
        add_action( 'wp_ajax_jdpd_calculate_profit_margin', array( $this, 'ajax_calculate_margin' ) );
        add_action( 'wp_ajax_jdpd_bulk_set_cost_prices', array( $this, 'ajax_bulk_set_cost_prices' ) );
        add_action( 'wp_ajax_jdpd_get_profit_report', array( $this, 'ajax_get_profit_report' ) );
    }

    /**
     * Get settings.
     *
     * @return array Settings.
     */
    public function get_settings() {
        return get_option( 'jdpd_profit_protection_settings', array(
            'enabled'              => true,
            'minimum_margin'       => 10, // 10% minimum profit margin
            'margin_type'          => 'percentage', // percentage or fixed
            'fallback_margin'      => 20, // Use this if no cost price set
            'alert_threshold'      => 15, // Alert when margin drops below this
            'block_negative'       => true, // Block discounts that would cause loss
            'log_protected'        => true, // Log when protection kicks in
            'show_admin_warnings'  => true,
            'cost_includes_tax'    => false,
        ) );
    }

    /**
     * Get product cost price.
     *
     * @param int $product_id Product ID.
     * @return float|null Cost price or null if not set.
     */
    public function get_cost_price( $product_id ) {
        $cost = get_post_meta( $product_id, $this->cost_meta_key, true );

        if ( '' === $cost || false === $cost ) {
            // Check parent product for variations
            $product = wc_get_product( $product_id );
            if ( $product && $product->is_type( 'variation' ) ) {
                $parent_id = $product->get_parent_id();
                $cost = get_post_meta( $parent_id, $this->cost_meta_key, true );
            }
        }

        return '' !== $cost && false !== $cost ? floatval( $cost ) : null;
    }

    /**
     * Set product cost price.
     *
     * @param int   $product_id Product ID.
     * @param float $cost Cost price.
     */
    public function set_cost_price( $product_id, $cost ) {
        update_post_meta( $product_id, $this->cost_meta_key, floatval( $cost ) );
    }

    /**
     * Calculate profit margin for a product at a given price.
     *
     * @param int   $product_id Product ID.
     * @param float $selling_price Selling price.
     * @return array Margin data.
     */
    public function calculate_margin( $product_id, $selling_price ) {
        $cost = $this->get_cost_price( $product_id );

        if ( null === $cost || $cost <= 0 ) {
            return array(
                'has_cost'         => false,
                'cost'             => 0,
                'selling_price'    => $selling_price,
                'profit'           => null,
                'margin_percent'   => null,
                'margin_amount'    => null,
                'is_profitable'    => null,
                'meets_minimum'    => null,
            );
        }

        $profit = $selling_price - $cost;
        $margin_percent = $selling_price > 0 ? ( $profit / $selling_price ) * 100 : 0;
        $margin_amount = $profit;

        $min_margin = $this->settings['minimum_margin'] ?? 10;

        return array(
            'has_cost'         => true,
            'cost'             => $cost,
            'selling_price'    => $selling_price,
            'profit'           => $profit,
            'margin_percent'   => round( $margin_percent, 2 ),
            'margin_amount'    => round( $margin_amount, 2 ),
            'is_profitable'    => $profit > 0,
            'meets_minimum'    => $margin_percent >= $min_margin,
        );
    }

    /**
     * Calculate minimum allowed price for a product.
     *
     * @param int $product_id Product ID.
     * @return float|null Minimum price or null if no cost set.
     */
    public function get_minimum_price( $product_id ) {
        $cost = $this->get_cost_price( $product_id );

        if ( null === $cost || $cost <= 0 ) {
            return null;
        }

        $min_margin = $this->settings['minimum_margin'] ?? 10;
        $margin_type = $this->settings['margin_type'] ?? 'percentage';

        if ( 'percentage' === $margin_type ) {
            // Price = Cost / (1 - margin%)
            // e.g., Cost $80, 10% margin: Price = 80 / 0.9 = $88.89
            $min_price = $cost / ( 1 - ( $min_margin / 100 ) );
        } else {
            // Fixed margin
            $min_price = $cost + $min_margin;
        }

        return round( $min_price, wc_get_price_decimals() );
    }

    /**
     * Enforce minimum price on calculated prices.
     *
     * @param float $price Calculated price.
     * @param int   $product_id Product ID.
     * @param array $context Pricing context.
     * @return float Enforced price.
     */
    public function enforce_minimum_price( $price, $product_id, $context = array() ) {
        if ( empty( $this->settings['enabled'] ) ) {
            return $price;
        }

        $min_price = $this->get_minimum_price( $product_id );

        if ( null === $min_price ) {
            return $price;
        }

        if ( $price < $min_price ) {
            // Log the protection
            if ( ! empty( $this->settings['log_protected'] ) ) {
                $this->log_protection( $product_id, $price, $min_price, $context );
            }

            return $min_price;
        }

        return $price;
    }

    /**
     * Validate a discount before applying.
     *
     * @param bool   $apply Whether to apply the discount.
     * @param float  $discount_amount Discount amount.
     * @param int    $product_id Product ID.
     * @param float  $original_price Original price.
     * @return bool Whether to apply.
     */
    public function validate_discount( $apply, $discount_amount, $product_id, $original_price ) {
        if ( ! $apply || empty( $this->settings['enabled'] ) ) {
            return $apply;
        }

        $discounted_price = $original_price - $discount_amount;
        $min_price = $this->get_minimum_price( $product_id );

        if ( null === $min_price ) {
            return $apply;
        }

        // Block if would go below minimum
        if ( $discounted_price < $min_price ) {
            if ( ! empty( $this->settings['block_negative'] ) ) {
                $cost = $this->get_cost_price( $product_id );
                if ( $cost && $discounted_price < $cost ) {
                    // Would cause a loss - block completely
                    return false;
                }
            }
        }

        return $apply;
    }

    /**
     * Log a protection event.
     *
     * @param int   $product_id Product ID.
     * @param float $attempted_price Attempted price.
     * @param float $enforced_price Enforced minimum price.
     * @param array $context Context data.
     */
    private function log_protection( $product_id, $attempted_price, $enforced_price, $context = array() ) {
        $log = get_option( 'jdpd_profit_protection_log', array() );

        // Keep only last 100 entries
        if ( count( $log ) >= 100 ) {
            array_shift( $log );
        }

        $product = wc_get_product( $product_id );

        $log[] = array(
            'product_id'      => $product_id,
            'product_name'    => $product ? $product->get_name() : 'Unknown',
            'attempted_price' => $attempted_price,
            'enforced_price'  => $enforced_price,
            'difference'      => $enforced_price - $attempted_price,
            'context'         => $context,
            'timestamp'       => current_time( 'mysql' ),
        );

        update_option( 'jdpd_profit_protection_log', $log );
    }

    /**
     * Add cost price field to product data.
     */
    public function add_cost_price_field() {
        woocommerce_wp_text_input( array(
            'id'          => $this->cost_meta_key,
            'label'       => __( 'Cost price', 'jezweb-dynamic-pricing' ) . ' (' . get_woocommerce_currency_symbol() . ')',
            'desc_tip'    => true,
            'description' => __( 'The cost price is used to calculate profit margins and protect against unprofitable discounts.', 'jezweb-dynamic-pricing' ),
            'type'        => 'text',
            'data_type'   => 'price',
            'class'       => 'wc_input_price short',
        ) );

        // Show calculated margin
        global $post;
        if ( $post ) {
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $cost = $this->get_cost_price( $post->ID );
                $price = $product->get_regular_price();

                if ( $cost && $price ) {
                    $margin = $this->calculate_margin( $post->ID, $price );
                    $margin_class = $margin['meets_minimum'] ? 'jdpd-margin-good' : 'jdpd-margin-warning';
                    ?>
                    <p class="form-field <?php echo esc_attr( $margin_class ); ?>">
                        <label><?php esc_html_e( 'Current Margin', 'jezweb-dynamic-pricing' ); ?></label>
                        <span class="jdpd-margin-display">
                            <?php
                            printf(
                                /* translators: 1: margin percentage, 2: profit amount */
                                esc_html__( '%1$s%% (%2$s profit)', 'jezweb-dynamic-pricing' ),
                                esc_html( $margin['margin_percent'] ),
                                wp_kses_post( wc_price( $margin['profit'] ) )
                            );
                            ?>
                        </span>
                    </p>
                    <?php
                }
            }
        }
    }

    /**
     * Save cost price field.
     *
     * @param int $post_id Product ID.
     */
    public function save_cost_price_field( $post_id ) {
        if ( isset( $_POST[ $this->cost_meta_key ] ) ) {
            $cost = wc_format_decimal( sanitize_text_field( $_POST[ $this->cost_meta_key ] ) );
            $this->set_cost_price( $post_id, $cost );
        }
    }

    /**
     * Add variation cost price field.
     *
     * @param int   $loop Variation loop index.
     * @param array $variation_data Variation data.
     * @param object $variation Variation post object.
     */
    public function add_variation_cost_price_field( $loop, $variation_data, $variation ) {
        $cost = get_post_meta( $variation->ID, $this->cost_meta_key, true );
        ?>
        <p class="form-row form-row-first">
            <label><?php esc_html_e( 'Cost price', 'jezweb-dynamic-pricing' ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)</label>
            <input type="text"
                   name="variable_cost_price[<?php echo esc_attr( $loop ); ?>]"
                   value="<?php echo esc_attr( wc_format_localized_price( $cost ) ); ?>"
                   class="wc_input_price"
                   placeholder="<?php esc_attr_e( 'Cost price', 'jezweb-dynamic-pricing' ); ?>" />
        </p>
        <?php
    }

    /**
     * Save variation cost price field.
     *
     * @param int $variation_id Variation ID.
     * @param int $i Loop index.
     */
    public function save_variation_cost_price_field( $variation_id, $i ) {
        if ( isset( $_POST['variable_cost_price'][ $i ] ) ) {
            $cost = wc_format_decimal( sanitize_text_field( $_POST['variable_cost_price'][ $i ] ) );
            $this->set_cost_price( $variation_id, $cost );
        }
    }

    /**
     * Add bulk edit fields.
     */
    public function add_bulk_edit_fields() {
        ?>
        <div class="inline-edit-group">
            <label class="alignleft">
                <span class="title"><?php esc_html_e( 'Cost price', 'jezweb-dynamic-pricing' ); ?></span>
                <span class="input-text-wrap">
                    <select class="change_cost_price change_to" name="change_cost_price">
                        <option value=""><?php esc_html_e( '— No change —', 'jezweb-dynamic-pricing' ); ?></option>
                        <option value="1"><?php esc_html_e( 'Change to:', 'jezweb-dynamic-pricing' ); ?></option>
                        <option value="2"><?php esc_html_e( 'Increase by (fixed amount):', 'jezweb-dynamic-pricing' ); ?></option>
                        <option value="3"><?php esc_html_e( 'Decrease by (fixed amount):', 'jezweb-dynamic-pricing' ); ?></option>
                        <option value="4"><?php esc_html_e( 'Increase by (%):', 'jezweb-dynamic-pricing' ); ?></option>
                        <option value="5"><?php esc_html_e( 'Decrease by (%):', 'jezweb-dynamic-pricing' ); ?></option>
                    </select>
                </span>
            </label>
            <label class="change-input">
                <input type="text" name="_cost_price" class="text cost_price" placeholder="<?php esc_attr_e( 'Enter cost price', 'jezweb-dynamic-pricing' ); ?>" value="" />
            </label>
        </div>
        <?php
    }

    /**
     * Save bulk edit fields.
     *
     * @param WC_Product $product Product object.
     */
    public function save_bulk_edit_fields( $product ) {
        if ( ! empty( $_REQUEST['change_cost_price'] ) && isset( $_REQUEST['_cost_price'] ) ) {
            $change_type = absint( $_REQUEST['change_cost_price'] );
            $value = wc_format_decimal( sanitize_text_field( $_REQUEST['_cost_price'] ) );
            $current_cost = $this->get_cost_price( $product->get_id() ) ?: 0;

            switch ( $change_type ) {
                case 1: // Set to
                    $new_cost = $value;
                    break;
                case 2: // Increase by fixed
                    $new_cost = $current_cost + $value;
                    break;
                case 3: // Decrease by fixed
                    $new_cost = max( 0, $current_cost - $value );
                    break;
                case 4: // Increase by %
                    $new_cost = $current_cost * ( 1 + ( $value / 100 ) );
                    break;
                case 5: // Decrease by %
                    $new_cost = $current_cost * ( 1 - ( $value / 100 ) );
                    break;
                default:
                    return;
            }

            $this->set_cost_price( $product->get_id(), $new_cost );
        }
    }

    /**
     * Add quick edit fields.
     */
    public function add_quick_edit_fields() {
        ?>
        <br class="clear" />
        <label class="alignleft">
            <span class="title"><?php esc_html_e( 'Cost price', 'jezweb-dynamic-pricing' ); ?></span>
            <span class="input-text-wrap">
                <input type="text" name="_cost_price" class="text wc_input_price" value="" />
            </span>
        </label>
        <?php
    }

    /**
     * Save quick edit fields.
     *
     * @param WC_Product $product Product object.
     */
    public function save_quick_edit_fields( $product ) {
        if ( isset( $_REQUEST['_cost_price'] ) && '' !== $_REQUEST['_cost_price'] ) {
            $cost = wc_format_decimal( sanitize_text_field( $_REQUEST['_cost_price'] ) );
            $this->set_cost_price( $product->get_id(), $cost );
        }
    }

    /**
     * Add profit margin column to products list.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public function add_profit_column( $columns ) {
        $new_columns = array();

        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            if ( 'price' === $key ) {
                $new_columns['profit_margin'] = __( 'Margin', 'jezweb-dynamic-pricing' );
            }
        }

        return $new_columns;
    }

    /**
     * Render profit margin column.
     *
     * @param string $column Column name.
     * @param int    $post_id Post ID.
     */
    public function render_profit_column( $column, $post_id ) {
        if ( 'profit_margin' !== $column ) {
            return;
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            echo '—';
            return;
        }

        $cost = $this->get_cost_price( $post_id );
        if ( ! $cost ) {
            echo '<span class="jdpd-no-cost">—</span>';
            return;
        }

        $price = $product->get_price();
        $margin = $this->calculate_margin( $post_id, $price );

        if ( ! $margin['has_cost'] ) {
            echo '—';
            return;
        }

        $class = 'jdpd-margin';
        if ( ! $margin['is_profitable'] ) {
            $class .= ' jdpd-margin-loss';
        } elseif ( ! $margin['meets_minimum'] ) {
            $class .= ' jdpd-margin-warning';
        } else {
            $class .= ' jdpd-margin-good';
        }

        printf(
            '<span class="%s">%s%%</span>',
            esc_attr( $class ),
            esc_html( $margin['margin_percent'] )
        );
    }

    /**
     * Add export column.
     *
     * @param array $columns Export columns.
     * @return array Modified columns.
     */
    public function add_export_column( $columns ) {
        $columns['cost_price'] = __( 'Cost price', 'jezweb-dynamic-pricing' );
        return $columns;
    }

    /**
     * Export cost price value.
     *
     * @param string     $value Current value.
     * @param WC_Product $product Product object.
     * @return string Cost price.
     */
    public function export_cost_price( $value, $product ) {
        return $this->get_cost_price( $product->get_id() ) ?: '';
    }

    /**
     * Add import mapping option.
     *
     * @param array $options Import options.
     * @return array Modified options.
     */
    public function add_import_mapping( $options ) {
        $options['cost_price'] = __( 'Cost price', 'jezweb-dynamic-pricing' );
        return $options;
    }

    /**
     * Import cost price from CSV.
     *
     * @param WC_Product $product Product object.
     * @param array      $data Import data.
     * @return WC_Product Modified product.
     */
    public function import_cost_price( $product, $data ) {
        if ( ! empty( $data['cost_price'] ) ) {
            $this->set_cost_price( $product->get_id(), wc_format_decimal( $data['cost_price'] ) );
        }
        return $product;
    }

    /**
     * Show admin notices for protection events.
     */
    public function show_protection_notices() {
        if ( empty( $this->settings['show_admin_warnings'] ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) {
            return;
        }

        // Check for products with low margins
        $low_margin_products = $this->get_low_margin_products();

        if ( ! empty( $low_margin_products ) ) {
            $count = count( $low_margin_products );
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Jezweb Dynamic Pricing:', 'jezweb-dynamic-pricing' ); ?></strong>
                    <?php
                    printf(
                        /* translators: %d: number of products */
                        esc_html( _n(
                            '%d product has a profit margin below the minimum threshold.',
                            '%d products have profit margins below the minimum threshold.',
                            $count,
                            'jezweb-dynamic-pricing'
                        ) ),
                        $count
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Get products with margins below threshold.
     *
     * @return array Low margin products.
     */
    public function get_low_margin_products() {
        global $wpdb;

        $threshold = $this->settings['alert_threshold'] ?? 15;

        $products = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, pm_cost.meta_value as cost, pm_price.meta_value as price
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_cost ON p.ID = pm_cost.post_id AND pm_cost.meta_key = %s
             INNER JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
             WHERE p.post_type = 'product'
             AND p.post_status = 'publish'
             AND pm_cost.meta_value > 0
             AND pm_price.meta_value > 0",
            $this->cost_meta_key
        ) );

        $low_margin = array();

        foreach ( $products as $product ) {
            $cost = floatval( $product->cost );
            $price = floatval( $product->price );

            if ( $cost > 0 && $price > 0 ) {
                $margin = ( ( $price - $cost ) / $price ) * 100;
                if ( $margin < $threshold ) {
                    $low_margin[] = array(
                        'id'     => $product->ID,
                        'name'   => $product->post_title,
                        'margin' => round( $margin, 2 ),
                        'cost'   => $cost,
                        'price'  => $price,
                    );
                }
            }
        }

        return $low_margin;
    }

    /**
     * Generate profit report.
     *
     * @return array Profit report data.
     */
    public function get_profit_report() {
        global $wpdb;

        $products = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title,
                    pm_cost.meta_value as cost,
                    pm_price.meta_value as price,
                    pm_stock.meta_value as stock
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_cost ON p.ID = pm_cost.post_id AND pm_cost.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
             LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock'
             WHERE p.post_type = 'product'
             AND p.post_status = 'publish'",
            $this->cost_meta_key
        ) );

        $report = array(
            'total_products'     => 0,
            'products_with_cost' => 0,
            'total_stock_value'  => 0,
            'total_potential_profit' => 0,
            'avg_margin'         => 0,
            'margin_distribution' => array(
                'loss'     => 0,
                'low'      => 0, // 0-10%
                'medium'   => 0, // 10-25%
                'good'     => 0, // 25-50%
                'high'     => 0, // 50%+
            ),
            'products'           => array(),
        );

        $total_margin = 0;

        foreach ( $products as $product ) {
            $report['total_products']++;

            $cost = floatval( $product->cost );
            $price = floatval( $product->price );
            $stock = intval( $product->stock );

            if ( $cost > 0 ) {
                $report['products_with_cost']++;

                $profit = $price - $cost;
                $margin = $price > 0 ? ( $profit / $price ) * 100 : 0;

                $total_margin += $margin;

                $report['total_stock_value'] += $cost * max( 0, $stock );
                $report['total_potential_profit'] += $profit * max( 0, $stock );

                // Distribution
                if ( $margin < 0 ) {
                    $report['margin_distribution']['loss']++;
                } elseif ( $margin < 10 ) {
                    $report['margin_distribution']['low']++;
                } elseif ( $margin < 25 ) {
                    $report['margin_distribution']['medium']++;
                } elseif ( $margin < 50 ) {
                    $report['margin_distribution']['good']++;
                } else {
                    $report['margin_distribution']['high']++;
                }

                $report['products'][] = array(
                    'id'     => $product->ID,
                    'name'   => $product->post_title,
                    'cost'   => $cost,
                    'price'  => $price,
                    'profit' => $profit,
                    'margin' => round( $margin, 2 ),
                    'stock'  => $stock,
                );
            }
        }

        if ( $report['products_with_cost'] > 0 ) {
            $report['avg_margin'] = round( $total_margin / $report['products_with_cost'], 2 );
        }

        // Sort by margin ascending (lowest first)
        usort( $report['products'], function( $a, $b ) {
            return $a['margin'] <=> $b['margin'];
        } );

        return $report;
    }

    /**
     * AJAX: Calculate profit margin.
     */
    public function ajax_calculate_margin() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $price = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0;

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid product.', 'jezweb-dynamic-pricing' ) ) );
        }

        $margin = $this->calculate_margin( $product_id, $price );
        $min_price = $this->get_minimum_price( $product_id );

        wp_send_json_success( array(
            'margin'    => $margin,
            'min_price' => $min_price,
        ) );
    }

    /**
     * AJAX: Bulk set cost prices.
     */
    public function ajax_bulk_set_cost_prices() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $method = isset( $_POST['method'] ) ? sanitize_key( $_POST['method'] ) : '';
        $value = isset( $_POST['value'] ) ? floatval( $_POST['value'] ) : 0;
        $product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', $_POST['product_ids'] ) : array();

        if ( empty( $method ) || empty( $product_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'jezweb-dynamic-pricing' ) ) );
        }

        $updated = 0;

        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $price = $product->get_regular_price();

            switch ( $method ) {
                case 'fixed':
                    $cost = $value;
                    break;
                case 'percentage':
                    // Cost = Price * (percentage / 100)
                    $cost = $price * ( $value / 100 );
                    break;
                case 'markup':
                    // Cost = Price / (1 + markup%)
                    $cost = $price / ( 1 + ( $value / 100 ) );
                    break;
                default:
                    continue 2;
            }

            $this->set_cost_price( $product_id, $cost );
            $updated++;
        }

        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %d: number of products */
                __( 'Cost price updated for %d products.', 'jezweb-dynamic-pricing' ),
                $updated
            ),
            'updated' => $updated,
        ) );
    }

    /**
     * AJAX: Get profit report.
     */
    public function ajax_get_profit_report() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $report = $this->get_profit_report();

        wp_send_json_success( $report );
    }
}
