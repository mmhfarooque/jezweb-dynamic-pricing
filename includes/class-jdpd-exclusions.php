<?php
/**
 * Exclusions Handler
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Exclusions class
 */
class JDPD_Exclusions {

    /**
     * Exclusion cache
     *
     * @var array
     */
    private $cache = array();

    /**
     * Constructor
     */
    public function __construct() {
        // Clear cache when products or rules are updated
        add_action( 'woocommerce_update_product', array( $this, 'clear_cache' ) );
        add_action( 'jdpd_rule_saved', array( $this, 'clear_cache' ) );
        add_action( 'jdpd_rule_deleted', array( $this, 'clear_cache' ) );
    }

    /**
     * Check if product is excluded from a specific rule
     *
     * @param int $product_id Product ID.
     * @param int $rule_id    Rule ID.
     * @return bool
     */
    public function is_product_excluded( $product_id, $rule_id ) {
        $cache_key = "product_{$product_id}_rule_{$rule_id}";

        if ( isset( $this->cache[ $cache_key ] ) ) {
            return $this->cache[ $cache_key ];
        }

        global $wpdb;

        // Check product exclusion
        $excluded = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}jdpd_exclusions
                WHERE rule_id = %d AND exclusion_type = 'product' AND exclusion_id = %d",
                $rule_id,
                $product_id
            )
        );

        if ( $excluded > 0 ) {
            $this->cache[ $cache_key ] = true;
            return true;
        }

        // Check category exclusion
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $category_ids = $product->get_category_ids();

            if ( ! empty( $category_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
                $args = array_merge( array( $rule_id ), $category_ids );

                $category_excluded = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}jdpd_exclusions
                        WHERE rule_id = %d AND exclusion_type = 'category' AND exclusion_id IN ($placeholders)",
                        $args
                    )
                );

                if ( $category_excluded > 0 ) {
                    $this->cache[ $cache_key ] = true;
                    return true;
                }
            }
        }

        $this->cache[ $cache_key ] = false;
        return false;
    }

    /**
     * Check if product is globally excluded from all rules
     *
     * @param int $product_id Product ID.
     * @return bool
     */
    public function is_product_globally_excluded( $product_id ) {
        // Check product meta for global exclusion flag
        $excluded = get_post_meta( $product_id, '_jdpd_exclude_from_discounts', true );
        return 'yes' === $excluded;
    }

    /**
     * Get all exclusions for a rule
     *
     * @param int $rule_id Rule ID.
     * @return array
     */
    public function get_rule_exclusions( $rule_id ) {
        global $wpdb;

        $exclusions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}jdpd_exclusions WHERE rule_id = %d",
                $rule_id
            )
        );

        $result = array(
            'products'   => array(),
            'categories' => array(),
        );

        foreach ( $exclusions as $exclusion ) {
            if ( 'product' === $exclusion->exclusion_type ) {
                $product = wc_get_product( $exclusion->exclusion_id );
                if ( $product ) {
                    $result['products'][] = array(
                        'id'   => $exclusion->exclusion_id,
                        'name' => $product->get_formatted_name(),
                    );
                }
            } elseif ( 'category' === $exclusion->exclusion_type ) {
                $term = get_term( $exclusion->exclusion_id, 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $result['categories'][] = array(
                        'id'   => $exclusion->exclusion_id,
                        'name' => $term->name,
                    );
                }
            }
        }

        return $result;
    }

    /**
     * Add exclusion
     *
     * @param int    $rule_id        Rule ID.
     * @param string $exclusion_type Exclusion type (product, category).
     * @param int    $exclusion_id   Exclusion ID.
     * @return bool
     */
    public function add_exclusion( $rule_id, $exclusion_type, $exclusion_id ) {
        global $wpdb;

        // Check if already exists
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}jdpd_exclusions
                WHERE rule_id = %d AND exclusion_type = %s AND exclusion_id = %d",
                $rule_id,
                $exclusion_type,
                $exclusion_id
            )
        );

        if ( $exists > 0 ) {
            return false;
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'jdpd_exclusions',
            array(
                'rule_id'        => $rule_id,
                'exclusion_type' => $exclusion_type,
                'exclusion_id'   => $exclusion_id,
            ),
            array( '%d', '%s', '%d' )
        );

        $this->clear_cache();

        return $result !== false;
    }

    /**
     * Remove exclusion
     *
     * @param int    $rule_id        Rule ID.
     * @param string $exclusion_type Exclusion type.
     * @param int    $exclusion_id   Exclusion ID.
     * @return bool
     */
    public function remove_exclusion( $rule_id, $exclusion_type, $exclusion_id ) {
        global $wpdb;

        $result = $wpdb->delete(
            $wpdb->prefix . 'jdpd_exclusions',
            array(
                'rule_id'        => $rule_id,
                'exclusion_type' => $exclusion_type,
                'exclusion_id'   => $exclusion_id,
            ),
            array( '%d', '%s', '%d' )
        );

        $this->clear_cache();

        return $result !== false;
    }

    /**
     * Set global exclusion for product
     *
     * @param int  $product_id Product ID.
     * @param bool $exclude    Whether to exclude.
     */
    public function set_product_global_exclusion( $product_id, $exclude = true ) {
        update_post_meta( $product_id, '_jdpd_exclude_from_discounts', $exclude ? 'yes' : 'no' );
        $this->clear_cache();
    }

    /**
     * Clear exclusion cache
     */
    public function clear_cache() {
        $this->cache = array();
    }

    /**
     * Get products excluded from discounts
     *
     * @param int $limit Limit results.
     * @return array
     */
    public function get_globally_excluded_products( $limit = -1 ) {
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => $limit,
            'meta_query'     => array(
                array(
                    'key'   => '_jdpd_exclude_from_discounts',
                    'value' => 'yes',
                ),
            ),
            'fields'         => 'ids',
        );

        return get_posts( $args );
    }
}
