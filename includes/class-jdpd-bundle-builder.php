<?php
/**
 * Bundle Builder System
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Jezweb_Dynamic_Pricing/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bundle Builder class.
 *
 * Allows customers to create custom bundles with discounts.
 * Supports "Pick X for $Y" and mix-and-match deals.
 *
 * @since 1.4.0
 */
class JDPD_Bundle_Builder {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Bundle_Builder
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
     * @return JDPD_Bundle_Builder
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
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'jdpd_bundles';

        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Register custom post type for bundles
        add_action( 'init', array( $this, 'register_bundle_post_type' ) );

        // Frontend display
        add_action( 'woocommerce_after_single_product_summary', array( $this, 'display_bundle_builder' ), 15 );
        add_shortcode( 'jdpd_bundle_builder', array( $this, 'bundle_builder_shortcode' ) );

        // Cart handling
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_bundle_cart_data' ), 10, 3 );
        add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'get_bundle_cart_item_from_session' ), 10, 3 );
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_bundle_pricing' ), 20 );
        add_filter( 'woocommerce_cart_item_name', array( $this, 'bundle_cart_item_name' ), 10, 3 );
        add_filter( 'woocommerce_cart_item_quantity', array( $this, 'bundle_cart_item_quantity' ), 10, 3 );

        // Order handling
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_bundle_order_item_meta' ), 10, 4 );

        // AJAX handlers
        add_action( 'wp_ajax_jdpd_add_bundle_to_cart', array( $this, 'ajax_add_bundle_to_cart' ) );
        add_action( 'wp_ajax_nopriv_jdpd_add_bundle_to_cart', array( $this, 'ajax_add_bundle_to_cart' ) );
        add_action( 'wp_ajax_jdpd_calculate_bundle_price', array( $this, 'ajax_calculate_bundle_price' ) );
        add_action( 'wp_ajax_nopriv_jdpd_calculate_bundle_price', array( $this, 'ajax_calculate_bundle_price' ) );
        add_action( 'wp_ajax_jdpd_get_bundle_products', array( $this, 'ajax_get_bundle_products' ) );
        add_action( 'wp_ajax_nopriv_jdpd_get_bundle_products', array( $this, 'ajax_get_bundle_products' ) );

        // Admin
        add_action( 'wp_ajax_jdpd_save_bundle', array( $this, 'ajax_save_bundle' ) );
        add_action( 'wp_ajax_jdpd_delete_bundle', array( $this, 'ajax_delete_bundle' ) );
        add_action( 'wp_ajax_jdpd_get_bundles', array( $this, 'ajax_get_bundles' ) );

        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Register bundle post type.
     */
    public function register_bundle_post_type() {
        register_post_type( 'jdpd_bundle', array(
            'labels'       => array(
                'name'          => __( 'Product Bundles', 'jezweb-dynamic-pricing' ),
                'singular_name' => __( 'Product Bundle', 'jezweb-dynamic-pricing' ),
            ),
            'public'       => false,
            'show_ui'      => false,
            'supports'     => array( 'title' ),
            'capabilities' => array(
                'create_posts' => 'manage_woocommerce',
            ),
        ) );
    }

    /**
     * Create a new bundle.
     *
     * @param array $data Bundle data.
     * @return int|WP_Error Bundle ID or error.
     */
    public function create_bundle( $data ) {
        $defaults = array(
            'name'           => '',
            'description'    => '',
            'type'           => 'pick_x', // pick_x, mix_match, fixed
            'min_items'      => 1,
            'max_items'      => 10,
            'fixed_quantity' => 3, // For "Pick 3 for $X"
            'pricing_type'   => 'fixed_total', // fixed_total, percentage_off, fixed_per_item
            'pricing_value'  => 0,
            'products'       => array(), // Product IDs or category IDs
            'product_source' => 'products', // products, categories, tags, all
            'categories'     => array(),
            'tags'           => array(),
            'enabled'        => true,
            'show_on_product' => true,
            'show_savings'   => true,
            'display_style'  => 'grid', // grid, list, compact
        );

        $data = wp_parse_args( $data, $defaults );

        // Validation
        if ( empty( $data['name'] ) ) {
            return new WP_Error( 'missing_name', __( 'Bundle name is required.', 'jezweb-dynamic-pricing' ) );
        }

        $bundle_id = wp_insert_post( array(
            'post_type'   => 'jdpd_bundle',
            'post_title'  => sanitize_text_field( $data['name'] ),
            'post_status' => $data['enabled'] ? 'publish' : 'draft',
        ) );

        if ( is_wp_error( $bundle_id ) ) {
            return $bundle_id;
        }

        // Save meta
        update_post_meta( $bundle_id, '_jdpd_bundle_data', $data );

        return $bundle_id;
    }

    /**
     * Update a bundle.
     *
     * @param int   $bundle_id Bundle ID.
     * @param array $data Bundle data.
     * @return bool|WP_Error True on success or error.
     */
    public function update_bundle( $bundle_id, $data ) {
        $bundle = get_post( $bundle_id );

        if ( ! $bundle || 'jdpd_bundle' !== $bundle->post_type ) {
            return new WP_Error( 'not_found', __( 'Bundle not found.', 'jezweb-dynamic-pricing' ) );
        }

        wp_update_post( array(
            'ID'          => $bundle_id,
            'post_title'  => sanitize_text_field( $data['name'] ),
            'post_status' => ! empty( $data['enabled'] ) ? 'publish' : 'draft',
        ) );

        update_post_meta( $bundle_id, '_jdpd_bundle_data', $data );

        return true;
    }

    /**
     * Get a bundle by ID.
     *
     * @param int $bundle_id Bundle ID.
     * @return array|null Bundle data or null.
     */
    public function get_bundle( $bundle_id ) {
        $bundle = get_post( $bundle_id );

        if ( ! $bundle || 'jdpd_bundle' !== $bundle->post_type ) {
            return null;
        }

        $data = get_post_meta( $bundle_id, '_jdpd_bundle_data', true );

        if ( ! is_array( $data ) ) {
            $data = array();
        }

        $data['id'] = $bundle_id;
        $data['name'] = $bundle->post_title;
        $data['enabled'] = 'publish' === $bundle->post_status;

        return $data;
    }

    /**
     * Get all bundles.
     *
     * @param array $args Query args.
     * @return array Bundles.
     */
    public function get_bundles( $args = array() ) {
        $defaults = array(
            'post_type'      => 'jdpd_bundle',
            'posts_per_page' => -1,
            'post_status'    => array( 'publish', 'draft' ),
            'orderby'        => 'title',
            'order'          => 'ASC',
        );

        $query_args = wp_parse_args( $args, $defaults );

        if ( isset( $args['enabled'] ) ) {
            $query_args['post_status'] = $args['enabled'] ? 'publish' : 'draft';
        }

        $posts = get_posts( $query_args );
        $bundles = array();

        foreach ( $posts as $post ) {
            $bundles[] = $this->get_bundle( $post->ID );
        }

        return $bundles;
    }

    /**
     * Delete a bundle.
     *
     * @param int $bundle_id Bundle ID.
     * @return bool Whether deleted.
     */
    public function delete_bundle( $bundle_id ) {
        $result = wp_delete_post( $bundle_id, true );
        return false !== $result;
    }

    /**
     * Get products available for a bundle.
     *
     * @param array $bundle Bundle data.
     * @return array Products.
     */
    public function get_bundle_products( $bundle ) {
        $products = array();

        switch ( $bundle['product_source'] ?? 'products' ) {
            case 'products':
                if ( ! empty( $bundle['products'] ) ) {
                    foreach ( $bundle['products'] as $product_id ) {
                        $product = wc_get_product( $product_id );
                        if ( $product && $product->is_purchasable() && $product->is_in_stock() ) {
                            $products[] = $product;
                        }
                    }
                }
                break;

            case 'categories':
                if ( ! empty( $bundle['categories'] ) ) {
                    $query_products = wc_get_products( array(
                        'category' => $bundle['categories'],
                        'status'   => 'publish',
                        'limit'    => 100,
                    ) );
                    foreach ( $query_products as $product ) {
                        if ( $product->is_purchasable() && $product->is_in_stock() ) {
                            $products[] = $product;
                        }
                    }
                }
                break;

            case 'tags':
                if ( ! empty( $bundle['tags'] ) ) {
                    $query_products = wc_get_products( array(
                        'tag'    => $bundle['tags'],
                        'status' => 'publish',
                        'limit'  => 100,
                    ) );
                    foreach ( $query_products as $product ) {
                        if ( $product->is_purchasable() && $product->is_in_stock() ) {
                            $products[] = $product;
                        }
                    }
                }
                break;

            case 'all':
                $query_products = wc_get_products( array(
                    'status' => 'publish',
                    'limit'  => 100,
                ) );
                foreach ( $query_products as $product ) {
                    if ( $product->is_purchasable() && $product->is_in_stock() ) {
                        $products[] = $product;
                    }
                }
                break;
        }

        return $products;
    }

    /**
     * Calculate bundle price.
     *
     * @param array $bundle Bundle data.
     * @param array $selected_items Selected items array of [product_id => quantity].
     * @return array Price calculation result.
     */
    public function calculate_bundle_price( $bundle, $selected_items ) {
        $total_quantity = 0;
        $original_total = 0;
        $bundle_price = 0;
        $items = array();

        foreach ( $selected_items as $product_id => $quantity ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $quantity = absint( $quantity );
            $item_price = floatval( $product->get_price() );

            $total_quantity += $quantity;
            $original_total += $item_price * $quantity;

            $items[] = array(
                'product_id' => $product_id,
                'name'       => $product->get_name(),
                'quantity'   => $quantity,
                'price'      => $item_price,
                'subtotal'   => $item_price * $quantity,
            );
        }

        // Validate quantity requirements
        $type = $bundle['type'] ?? 'pick_x';
        $min_items = absint( $bundle['min_items'] ?? 1 );
        $max_items = absint( $bundle['max_items'] ?? 10 );
        $fixed_quantity = absint( $bundle['fixed_quantity'] ?? 3 );

        $is_valid = true;
        $error_message = '';

        if ( 'pick_x' === $type || 'fixed' === $type ) {
            if ( $total_quantity !== $fixed_quantity ) {
                $is_valid = false;
                $error_message = sprintf(
                    /* translators: %d: required quantity */
                    __( 'Please select exactly %d items for this bundle.', 'jezweb-dynamic-pricing' ),
                    $fixed_quantity
                );
            }
        } else {
            if ( $total_quantity < $min_items ) {
                $is_valid = false;
                $error_message = sprintf(
                    /* translators: %d: minimum items */
                    __( 'Please select at least %d items.', 'jezweb-dynamic-pricing' ),
                    $min_items
                );
            } elseif ( $total_quantity > $max_items ) {
                $is_valid = false;
                $error_message = sprintf(
                    /* translators: %d: maximum items */
                    __( 'Maximum %d items allowed in this bundle.', 'jezweb-dynamic-pricing' ),
                    $max_items
                );
            }
        }

        // Calculate discounted price
        $pricing_type = $bundle['pricing_type'] ?? 'fixed_total';
        $pricing_value = floatval( $bundle['pricing_value'] ?? 0 );

        switch ( $pricing_type ) {
            case 'fixed_total':
                // Fixed price for the bundle (e.g., "Pick 3 for $50")
                $bundle_price = $pricing_value;
                break;

            case 'percentage_off':
                // Percentage discount on total
                $bundle_price = $original_total * ( 1 - ( $pricing_value / 100 ) );
                break;

            case 'fixed_per_item':
                // Fixed price per item (e.g., "Any 3 items for $15 each")
                $bundle_price = $pricing_value * $total_quantity;
                break;

            case 'cheapest_free':
                // Cheapest item free
                $prices = array_column( $items, 'price' );
                $cheapest = min( $prices );
                $bundle_price = $original_total - $cheapest;
                break;

            case 'percentage_cheapest':
                // Percentage off cheapest item
                $prices = array_column( $items, 'price' );
                $cheapest = min( $prices );
                $discount = $cheapest * ( $pricing_value / 100 );
                $bundle_price = $original_total - $discount;
                break;
        }

        $bundle_price = round( $bundle_price, wc_get_price_decimals() );
        $savings = $original_total - $bundle_price;
        $savings_percent = $original_total > 0 ? round( ( $savings / $original_total ) * 100, 1 ) : 0;

        return array(
            'is_valid'        => $is_valid,
            'error_message'   => $error_message,
            'items'           => $items,
            'total_quantity'  => $total_quantity,
            'original_total'  => $original_total,
            'bundle_price'    => $bundle_price,
            'savings'         => $savings,
            'savings_percent' => $savings_percent,
        );
    }

    /**
     * Display bundle builder on product page.
     */
    public function display_bundle_builder() {
        global $product;

        if ( ! $product ) {
            return;
        }

        // Get bundles that include this product
        $bundles = $this->get_bundles( array( 'enabled' => true ) );
        $applicable_bundles = array();

        foreach ( $bundles as $bundle ) {
            if ( empty( $bundle['show_on_product'] ) ) {
                continue;
            }

            $bundle_products = $this->get_bundle_products( $bundle );
            $product_ids = wp_list_pluck( $bundle_products, 'id' );

            if ( in_array( $product->get_id(), $product_ids, true ) ) {
                $applicable_bundles[] = $bundle;
            }
        }

        if ( empty( $applicable_bundles ) ) {
            return;
        }

        foreach ( $applicable_bundles as $bundle ) {
            $this->render_bundle_builder( $bundle );
        }
    }

    /**
     * Bundle builder shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function bundle_builder_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id'    => 0,
            'style' => 'grid',
        ), $atts );

        $bundle_id = absint( $atts['id'] );

        if ( ! $bundle_id ) {
            return '';
        }

        $bundle = $this->get_bundle( $bundle_id );

        if ( ! $bundle || empty( $bundle['enabled'] ) ) {
            return '';
        }

        $bundle['display_style'] = $atts['style'];

        ob_start();
        $this->render_bundle_builder( $bundle );
        return ob_get_clean();
    }

    /**
     * Render bundle builder HTML.
     *
     * @param array $bundle Bundle data.
     */
    public function render_bundle_builder( $bundle ) {
        $products = $this->get_bundle_products( $bundle );

        if ( empty( $products ) ) {
            return;
        }

        $bundle_id = $bundle['id'];
        $type = $bundle['type'] ?? 'pick_x';
        $fixed_quantity = absint( $bundle['fixed_quantity'] ?? 3 );
        $min_items = absint( $bundle['min_items'] ?? 1 );
        $max_items = absint( $bundle['max_items'] ?? 10 );
        $pricing_type = $bundle['pricing_type'] ?? 'fixed_total';
        $pricing_value = floatval( $bundle['pricing_value'] ?? 0 );
        $display_style = $bundle['display_style'] ?? 'grid';

        // Generate title
        if ( 'pick_x' === $type && 'fixed_total' === $pricing_type ) {
            $title = sprintf(
                /* translators: 1: quantity, 2: price */
                __( 'Pick any %1$d for %2$s', 'jezweb-dynamic-pricing' ),
                $fixed_quantity,
                wc_price( $pricing_value )
            );
        } elseif ( 'percentage_off' === $pricing_type ) {
            $title = sprintf(
                /* translators: %d: percentage */
                __( 'Bundle & Save %d%%', 'jezweb-dynamic-pricing' ),
                $pricing_value
            );
        } else {
            $title = $bundle['name'];
        }

        ?>
        <div class="jdpd-bundle-builder" data-bundle-id="<?php echo esc_attr( $bundle_id ); ?>" data-bundle-type="<?php echo esc_attr( $type ); ?>">
            <h3 class="jdpd-bundle-title"><?php echo esc_html( $title ); ?></h3>

            <?php if ( ! empty( $bundle['description'] ) ) : ?>
                <p class="jdpd-bundle-description"><?php echo esc_html( $bundle['description'] ); ?></p>
            <?php endif; ?>

            <div class="jdpd-bundle-requirements">
                <?php if ( 'pick_x' === $type || 'fixed' === $type ) : ?>
                    <span class="jdpd-bundle-requirement">
                        <?php
                        printf(
                            /* translators: %d: quantity */
                            esc_html__( 'Select %d items', 'jezweb-dynamic-pricing' ),
                            $fixed_quantity
                        );
                        ?>
                    </span>
                <?php else : ?>
                    <span class="jdpd-bundle-requirement">
                        <?php
                        printf(
                            /* translators: 1: min items, 2: max items */
                            esc_html__( 'Select %1$d - %2$d items', 'jezweb-dynamic-pricing' ),
                            $min_items,
                            $max_items
                        );
                        ?>
                    </span>
                <?php endif; ?>

                <span class="jdpd-bundle-selected">
                    <?php esc_html_e( 'Selected:', 'jezweb-dynamic-pricing' ); ?>
                    <strong class="jdpd-selected-count">0</strong>
                </span>
            </div>

            <div class="jdpd-bundle-products jdpd-bundle-<?php echo esc_attr( $display_style ); ?>">
                <?php foreach ( $products as $product ) : ?>
                    <div class="jdpd-bundle-product" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
                        <div class="jdpd-bundle-product-image">
                            <?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
                        </div>
                        <div class="jdpd-bundle-product-info">
                            <h4 class="jdpd-bundle-product-title"><?php echo esc_html( $product->get_name() ); ?></h4>
                            <span class="jdpd-bundle-product-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
                        </div>
                        <div class="jdpd-bundle-product-quantity">
                            <button type="button" class="jdpd-qty-minus">-</button>
                            <input type="number"
                                   class="jdpd-bundle-qty"
                                   name="bundle_qty[<?php echo esc_attr( $product->get_id() ); ?>]"
                                   value="0"
                                   min="0"
                                   max="<?php echo esc_attr( $product->get_max_purchase_quantity() > 0 ? $product->get_max_purchase_quantity() : 99 ); ?>" />
                            <button type="button" class="jdpd-qty-plus">+</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="jdpd-bundle-summary">
                <div class="jdpd-bundle-pricing">
                    <?php if ( ! empty( $bundle['show_savings'] ) ) : ?>
                        <div class="jdpd-bundle-original-price">
                            <span class="label"><?php esc_html_e( 'Original:', 'jezweb-dynamic-pricing' ); ?></span>
                            <span class="value">—</span>
                        </div>
                    <?php endif; ?>

                    <div class="jdpd-bundle-total-price">
                        <span class="label"><?php esc_html_e( 'Bundle Price:', 'jezweb-dynamic-pricing' ); ?></span>
                        <span class="value">—</span>
                    </div>

                    <?php if ( ! empty( $bundle['show_savings'] ) ) : ?>
                        <div class="jdpd-bundle-savings">
                            <span class="label"><?php esc_html_e( 'You Save:', 'jezweb-dynamic-pricing' ); ?></span>
                            <span class="value">—</span>
                        </div>
                    <?php endif; ?>
                </div>

                <button type="button" class="jdpd-add-bundle-to-cart button alt" disabled>
                    <?php esc_html_e( 'Add Bundle to Cart', 'jezweb-dynamic-pricing' ); ?>
                </button>

                <p class="jdpd-bundle-message"></p>
            </div>
        </div>

        <style>
            .jdpd-bundle-builder {
                background: #f8f9fa;
                border: 2px solid #22588d;
                border-radius: 8px;
                padding: 25px;
                margin: 20px 0;
            }
            .jdpd-bundle-title {
                color: #22588d;
                margin: 0 0 10px 0;
                font-size: 1.4em;
            }
            .jdpd-bundle-requirements {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 0;
                border-bottom: 1px solid #ddd;
                margin-bottom: 15px;
            }
            .jdpd-bundle-products.jdpd-bundle-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 15px;
            }
            .jdpd-bundle-product {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 10px;
                text-align: center;
                transition: border-color 0.2s;
            }
            .jdpd-bundle-product.selected {
                border-color: #22588d;
                box-shadow: 0 0 0 2px rgba(34, 88, 141, 0.2);
            }
            .jdpd-bundle-product-image img {
                max-width: 100%;
                height: auto;
                border-radius: 4px;
            }
            .jdpd-bundle-product-title {
                font-size: 0.9em;
                margin: 10px 0 5px;
            }
            .jdpd-bundle-product-price {
                color: #666;
                font-size: 0.85em;
            }
            .jdpd-bundle-product-quantity {
                display: flex;
                justify-content: center;
                align-items: center;
                margin-top: 10px;
                gap: 5px;
            }
            .jdpd-qty-minus,
            .jdpd-qty-plus {
                width: 28px;
                height: 28px;
                border: 1px solid #ddd;
                background: #f5f5f5;
                border-radius: 4px;
                cursor: pointer;
                font-size: 16px;
            }
            .jdpd-bundle-qty {
                width: 50px;
                text-align: center;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 5px;
            }
            .jdpd-bundle-summary {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 2px solid #22588d;
            }
            .jdpd-bundle-pricing {
                display: flex;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 15px;
                margin-bottom: 15px;
            }
            .jdpd-bundle-pricing > div {
                text-align: center;
            }
            .jdpd-bundle-pricing .label {
                display: block;
                color: #666;
                font-size: 0.85em;
            }
            .jdpd-bundle-pricing .value {
                display: block;
                font-size: 1.2em;
                font-weight: bold;
            }
            .jdpd-bundle-original-price .value {
                text-decoration: line-through;
                color: #999;
            }
            .jdpd-bundle-total-price .value {
                color: #22588d;
            }
            .jdpd-bundle-savings .value {
                color: #d83a34;
            }
            .jdpd-add-bundle-to-cart {
                width: 100%;
                padding: 15px;
                font-size: 1.1em;
                background: #22588d !important;
            }
            .jdpd-add-bundle-to-cart:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            .jdpd-bundle-message {
                text-align: center;
                margin: 10px 0 0;
                font-size: 0.9em;
            }
            .jdpd-bundle-message.error {
                color: #d83a34;
            }
            .jdpd-bundle-message.success {
                color: #28a745;
            }
        </style>
        <?php
    }

    /**
     * Enqueue frontend scripts.
     */
    public function enqueue_scripts() {
        if ( ! is_product() && ! has_shortcode( get_post()->post_content ?? '', 'jdpd_bundle_builder' ) ) {
            return;
        }

        wp_enqueue_script(
            'jdpd-bundle-builder',
            JDPD_PLUGIN_URL . 'public/assets/js/bundle-builder.js',
            array( 'jquery' ),
            JDPD_VERSION,
            true
        );

        wp_localize_script( 'jdpd-bundle-builder', 'jdpdBundle', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'jdpd_bundle_nonce' ),
            'currency' => get_woocommerce_currency_symbol(),
            'i18n'     => array(
                'adding'        => __( 'Adding to cart...', 'jezweb-dynamic-pricing' ),
                'added'         => __( 'Bundle added to cart!', 'jezweb-dynamic-pricing' ),
                'error'         => __( 'Error adding bundle to cart.', 'jezweb-dynamic-pricing' ),
                'select_more'   => __( 'Select %d more item(s)', 'jezweb-dynamic-pricing' ),
                'select_less'   => __( 'Remove %d item(s)', 'jezweb-dynamic-pricing' ),
            ),
        ) );
    }

    /**
     * Add bundle data to cart item.
     *
     * @param array $cart_item_data Cart item data.
     * @param int   $product_id Product ID.
     * @param int   $variation_id Variation ID.
     * @return array Modified cart item data.
     */
    public function add_bundle_cart_data( $cart_item_data, $product_id, $variation_id ) {
        if ( isset( $_POST['jdpd_bundle_id'] ) ) {
            $cart_item_data['jdpd_bundle_id'] = absint( $_POST['jdpd_bundle_id'] );
            $cart_item_data['jdpd_bundle_parent'] = isset( $_POST['jdpd_bundle_parent'] );
            $cart_item_data['jdpd_bundle_items'] = isset( $_POST['jdpd_bundle_items'] ) ? wp_unslash( $_POST['jdpd_bundle_items'] ) : array();
            $cart_item_data['jdpd_bundle_price'] = isset( $_POST['jdpd_bundle_price'] ) ? floatval( $_POST['jdpd_bundle_price'] ) : 0;
        }

        return $cart_item_data;
    }

    /**
     * Get bundle cart item from session.
     *
     * @param array  $cart_item Cart item data.
     * @param array  $values Session values.
     * @param string $key Cart item key.
     * @return array Modified cart item.
     */
    public function get_bundle_cart_item_from_session( $cart_item, $values, $key ) {
        if ( isset( $values['jdpd_bundle_id'] ) ) {
            $cart_item['jdpd_bundle_id'] = $values['jdpd_bundle_id'];
            $cart_item['jdpd_bundle_parent'] = $values['jdpd_bundle_parent'] ?? false;
            $cart_item['jdpd_bundle_items'] = $values['jdpd_bundle_items'] ?? array();
            $cart_item['jdpd_bundle_price'] = $values['jdpd_bundle_price'] ?? 0;
        }

        return $cart_item;
    }

    /**
     * Apply bundle pricing in cart.
     *
     * @param WC_Cart $cart Cart object.
     */
    public function apply_bundle_pricing( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset( $cart_item['jdpd_bundle_parent'] ) && $cart_item['jdpd_bundle_parent'] ) {
                $bundle_price = floatval( $cart_item['jdpd_bundle_price'] ?? 0 );
                if ( $bundle_price > 0 ) {
                    $cart_item['data']->set_price( $bundle_price );
                }
            }
        }
    }

    /**
     * Modify bundle item name in cart.
     *
     * @param string $name Item name.
     * @param array  $cart_item Cart item.
     * @param string $cart_item_key Cart item key.
     * @return string Modified name.
     */
    public function bundle_cart_item_name( $name, $cart_item, $cart_item_key ) {
        if ( isset( $cart_item['jdpd_bundle_id'] ) && ! empty( $cart_item['jdpd_bundle_parent'] ) ) {
            $bundle = $this->get_bundle( $cart_item['jdpd_bundle_id'] );
            if ( $bundle ) {
                $name = '<strong>' . esc_html( $bundle['name'] ) . '</strong>';

                if ( ! empty( $cart_item['jdpd_bundle_items'] ) ) {
                    $name .= '<ul class="jdpd-bundle-cart-items">';
                    foreach ( $cart_item['jdpd_bundle_items'] as $item ) {
                        $name .= sprintf(
                            '<li>%s × %d</li>',
                            esc_html( $item['name'] ),
                            absint( $item['quantity'] )
                        );
                    }
                    $name .= '</ul>';
                }
            }
        }

        return $name;
    }

    /**
     * Modify bundle quantity display in cart.
     *
     * @param string $quantity Quantity HTML.
     * @param string $cart_item_key Cart item key.
     * @param array  $cart_item Cart item.
     * @return string Modified quantity.
     */
    public function bundle_cart_item_quantity( $quantity, $cart_item_key, $cart_item ) {
        if ( isset( $cart_item['jdpd_bundle_id'] ) && ! empty( $cart_item['jdpd_bundle_parent'] ) ) {
            // Make bundle quantity non-editable
            return sprintf( '<span class="jdpd-bundle-quantity">%d</span>', $cart_item['quantity'] );
        }

        return $quantity;
    }

    /**
     * Add bundle meta to order items.
     *
     * @param WC_Order_Item_Product $item Order item.
     * @param string                $cart_item_key Cart item key.
     * @param array                 $values Cart item values.
     * @param WC_Order              $order Order object.
     */
    public function add_bundle_order_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['jdpd_bundle_id'] ) ) {
            $bundle = $this->get_bundle( $values['jdpd_bundle_id'] );
            if ( $bundle ) {
                $item->add_meta_data( '_jdpd_bundle_name', $bundle['name'] );
            }

            if ( ! empty( $values['jdpd_bundle_items'] ) ) {
                $items_string = array();
                foreach ( $values['jdpd_bundle_items'] as $bundle_item ) {
                    $items_string[] = $bundle_item['name'] . ' × ' . $bundle_item['quantity'];
                }
                $item->add_meta_data( __( 'Bundle Items', 'jezweb-dynamic-pricing' ), implode( ', ', $items_string ) );
            }
        }
    }

    /**
     * AJAX: Add bundle to cart.
     */
    public function ajax_add_bundle_to_cart() {
        check_ajax_referer( 'jdpd_bundle_nonce', 'nonce' );

        $bundle_id = isset( $_POST['bundle_id'] ) ? absint( $_POST['bundle_id'] ) : 0;
        $items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();

        if ( ! $bundle_id || empty( $items ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid bundle data.', 'jezweb-dynamic-pricing' ) ) );
        }

        $bundle = $this->get_bundle( $bundle_id );
        if ( ! $bundle ) {
            wp_send_json_error( array( 'message' => __( 'Bundle not found.', 'jezweb-dynamic-pricing' ) ) );
        }

        // Parse items
        $selected_items = array();
        foreach ( $items as $item ) {
            $product_id = absint( $item['product_id'] );
            $quantity = absint( $item['quantity'] );
            if ( $product_id && $quantity > 0 ) {
                $selected_items[ $product_id ] = $quantity;
            }
        }

        // Calculate and validate
        $calculation = $this->calculate_bundle_price( $bundle, $selected_items );

        if ( ! $calculation['is_valid'] ) {
            wp_send_json_error( array( 'message' => $calculation['error_message'] ) );
        }

        // Create a virtual bundle product in cart
        // We'll use the first product as the "container" and set bundle price
        $first_product_id = array_key_first( $selected_items );
        $first_product = wc_get_product( $first_product_id );

        if ( ! $first_product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'jezweb-dynamic-pricing' ) ) );
        }

        // Add bundle container to cart
        $cart_item_data = array(
            'jdpd_bundle_id'     => $bundle_id,
            'jdpd_bundle_parent' => true,
            'jdpd_bundle_items'  => $calculation['items'],
            'jdpd_bundle_price'  => $calculation['bundle_price'],
        );

        $cart_item_key = WC()->cart->add_to_cart(
            $first_product_id,
            1,
            0,
            array(),
            $cart_item_data
        );

        if ( ! $cart_item_key ) {
            wp_send_json_error( array( 'message' => __( 'Could not add bundle to cart.', 'jezweb-dynamic-pricing' ) ) );
        }

        wp_send_json_success( array(
            'message'   => __( 'Bundle added to cart!', 'jezweb-dynamic-pricing' ),
            'cart_url'  => wc_get_cart_url(),
            'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
        ) );
    }

    /**
     * AJAX: Calculate bundle price.
     */
    public function ajax_calculate_bundle_price() {
        check_ajax_referer( 'jdpd_bundle_nonce', 'nonce' );

        $bundle_id = isset( $_POST['bundle_id'] ) ? absint( $_POST['bundle_id'] ) : 0;
        $items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();

        if ( ! $bundle_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid bundle.', 'jezweb-dynamic-pricing' ) ) );
        }

        $bundle = $this->get_bundle( $bundle_id );
        if ( ! $bundle ) {
            wp_send_json_error( array( 'message' => __( 'Bundle not found.', 'jezweb-dynamic-pricing' ) ) );
        }

        // Parse items
        $selected_items = array();
        foreach ( $items as $item ) {
            $product_id = absint( $item['product_id'] );
            $quantity = absint( $item['quantity'] );
            if ( $product_id && $quantity > 0 ) {
                $selected_items[ $product_id ] = $quantity;
            }
        }

        $calculation = $this->calculate_bundle_price( $bundle, $selected_items );

        // Format prices for display
        $calculation['original_total_formatted'] = wc_price( $calculation['original_total'] );
        $calculation['bundle_price_formatted'] = wc_price( $calculation['bundle_price'] );
        $calculation['savings_formatted'] = wc_price( $calculation['savings'] );

        wp_send_json_success( $calculation );
    }

    /**
     * AJAX: Get bundle products.
     */
    public function ajax_get_bundle_products() {
        check_ajax_referer( 'jdpd_bundle_nonce', 'nonce' );

        $bundle_id = isset( $_POST['bundle_id'] ) ? absint( $_POST['bundle_id'] ) : 0;

        $bundle = $this->get_bundle( $bundle_id );
        if ( ! $bundle ) {
            wp_send_json_error( array( 'message' => __( 'Bundle not found.', 'jezweb-dynamic-pricing' ) ) );
        }

        $products = $this->get_bundle_products( $bundle );
        $product_data = array();

        foreach ( $products as $product ) {
            $product_data[] = array(
                'id'    => $product->get_id(),
                'name'  => $product->get_name(),
                'price' => $product->get_price(),
                'price_html' => $product->get_price_html(),
                'image' => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
            );
        }

        wp_send_json_success( array( 'products' => $product_data ) );
    }

    /**
     * AJAX: Save bundle (admin).
     */
    public function ajax_save_bundle() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $bundle_id = isset( $_POST['bundle_id'] ) ? absint( $_POST['bundle_id'] ) : 0;
        $data = isset( $_POST['bundle'] ) ? wp_unslash( $_POST['bundle'] ) : array();

        if ( $bundle_id ) {
            $result = $this->update_bundle( $bundle_id, $data );
        } else {
            $result = $this->create_bundle( $data );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'bundle_id' => is_int( $result ) ? $result : $bundle_id,
            'message'   => __( 'Bundle saved successfully.', 'jezweb-dynamic-pricing' ),
        ) );
    }

    /**
     * AJAX: Delete bundle (admin).
     */
    public function ajax_delete_bundle() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $bundle_id = isset( $_POST['bundle_id'] ) ? absint( $_POST['bundle_id'] ) : 0;

        if ( ! $bundle_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid bundle ID.', 'jezweb-dynamic-pricing' ) ) );
        }

        $this->delete_bundle( $bundle_id );

        wp_send_json_success( array( 'message' => __( 'Bundle deleted.', 'jezweb-dynamic-pricing' ) ) );
    }

    /**
     * AJAX: Get all bundles (admin).
     */
    public function ajax_get_bundles() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $bundles = $this->get_bundles();

        wp_send_json_success( array( 'bundles' => $bundles ) );
    }
}
