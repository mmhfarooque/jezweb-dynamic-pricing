<?php
/**
 * Quantity Table Display
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Quantity Table class
 */
class JDPD_Quantity_Table {

    /**
     * Constructor
     */
    public function __construct() {
        if ( 'yes' !== get_option( 'jdpd_enable_plugin', 'yes' ) ) {
            return;
        }

        if ( 'yes' !== get_option( 'jdpd_show_quantity_table', 'yes' ) ) {
            return;
        }

        $position = get_option( 'jdpd_quantity_table_position', 'after_add_to_cart' );

        switch ( $position ) {
            case 'before_add_to_cart':
                add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'display_table' ), 20 );
                break;
            case 'after_add_to_cart':
                add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'display_table' ), 20 );
                break;
            case 'after_summary':
                add_action( 'woocommerce_single_product_summary', array( $this, 'display_table' ), 60 );
                break;
        }

        // AJAX price update
        add_action( 'wp_ajax_jdpd_get_price_for_quantity', array( $this, 'ajax_get_price_for_quantity' ) );
        add_action( 'wp_ajax_nopriv_jdpd_get_price_for_quantity', array( $this, 'ajax_get_price_for_quantity' ) );
    }

    /**
     * Display quantity table on product page
     */
    public function display_table() {
        global $product;

        if ( ! $product ) {
            return;
        }

        $table_data = $this->get_table_data( $product );

        if ( empty( $table_data ) ) {
            return;
        }

        $layout = get_option( 'jdpd_quantity_table_layout', 'horizontal' );
        echo $this->render_table( $product, $layout, $table_data );
    }

    /**
     * Get table data for product
     *
     * @param WC_Product $product Product object.
     * @return array
     */
    public function get_table_data( $product ) {
        $calculator = new JDPD_Discount_Calculator();
        return $calculator->get_quantity_price_table( $product );
    }

    /**
     * Render quantity table
     *
     * @param WC_Product $product    Product object.
     * @param string     $layout     Table layout (horizontal/vertical).
     * @param array      $table_data Table data (optional).
     * @return string
     */
    public function render_table( $product, $layout = 'horizontal', $table_data = null ) {
        if ( null === $table_data ) {
            $table_data = $this->get_table_data( $product );
        }

        if ( empty( $table_data ) ) {
            return '';
        }

        ob_start();

        $classes = array(
            'jdpd-quantity-table',
            'jdpd-table-' . $layout,
        );
        ?>

        <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
            <h4 class="jdpd-table-title"><?php esc_html_e( 'Bulk Pricing', 'jezweb-dynamic-pricing' ); ?></h4>

            <?php if ( 'horizontal' === $layout ) : ?>
                <table class="jdpd-pricing-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Quantity', 'jezweb-dynamic-pricing' ); ?></th>
                            <?php foreach ( $table_data as $row ) : ?>
                                <th>
                                    <?php
                                    if ( $row['max_qty'] ) {
                                        printf( '%d - %d', $row['min_qty'], $row['max_qty'] );
                                    } else {
                                        printf( '%d+', $row['min_qty'] );
                                    }
                                    ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th><?php esc_html_e( 'Price', 'jezweb-dynamic-pricing' ); ?></th>
                            <?php foreach ( $table_data as $row ) : ?>
                                <td><?php echo wc_price( $row['discounted_price'] ); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'You Save', 'jezweb-dynamic-pricing' ); ?></th>
                            <?php foreach ( $table_data as $row ) : ?>
                                <td class="jdpd-savings"><?php echo $row['savings_percent']; ?>%</td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>

            <?php else : ?>
                <table class="jdpd-pricing-table jdpd-vertical">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Quantity', 'jezweb-dynamic-pricing' ); ?></th>
                            <th><?php esc_html_e( 'Price', 'jezweb-dynamic-pricing' ); ?></th>
                            <th><?php esc_html_e( 'You Save', 'jezweb-dynamic-pricing' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $table_data as $row ) : ?>
                            <tr>
                                <td>
                                    <?php
                                    if ( $row['max_qty'] ) {
                                        printf( '%d - %d', $row['min_qty'], $row['max_qty'] );
                                    } else {
                                        printf( '%d+', $row['min_qty'] );
                                    }
                                    ?>
                                </td>
                                <td>
                                    <del><?php echo wc_price( $row['original_price'] ); ?></del>
                                    <ins><?php echo wc_price( $row['discounted_price'] ); ?></ins>
                                </td>
                                <td class="jdpd-savings"><?php echo $row['savings_percent']; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php
        return ob_get_clean();
    }

    /**
     * AJAX get price for quantity
     */
    public function ajax_get_price_for_quantity() {
        check_ajax_referer( 'jdpd_frontend_nonce', 'nonce' );

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $quantity = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid product.', 'jezweb-dynamic-pricing' ) ) );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'jezweb-dynamic-pricing' ) ) );
        }

        $calculator = new JDPD_Discount_Calculator();
        $discount = $calculator->get_best_discount_for_product( $product, $quantity );
        $original_price = $product->get_regular_price();

        wp_send_json_success(
            array(
                'original_price'   => $original_price,
                'discounted_price' => $discount['final_price'],
                'savings'          => $discount['amount'],
                'savings_percent'  => $original_price > 0 ? round( ( $discount['amount'] / $original_price ) * 100 ) : 0,
                'price_html'       => $discount['amount'] > 0
                    ? '<del>' . wc_price( $original_price ) . '</del> <ins>' . wc_price( $discount['final_price'] ) . '</ins>'
                    : wc_price( $original_price ),
                'total_price'      => $discount['final_price'] * $quantity,
                'total_price_html' => wc_price( $discount['final_price'] * $quantity ),
            )
        );
    }

    /**
     * Get table HTML for specific product
     *
     * @param int    $product_id Product ID.
     * @param string $layout     Layout type.
     * @return string
     */
    public static function get_table_html( $product_id, $layout = 'horizontal' ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        $instance = new self();
        return $instance->render_table( $product, $layout );
    }
}
