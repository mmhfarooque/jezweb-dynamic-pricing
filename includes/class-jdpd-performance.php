<?php
/**
 * Performance Optimization System
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Jezweb_Dynamic_Pricing/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Performance Optimization System class.
 *
 * Provides caching, query optimization, and performance monitoring
 * for the dynamic pricing system.
 *
 * @since 1.3.0
 */
class JDPD_Performance {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Performance
     */
    private static $instance = null;

    /**
     * In-memory cache for computed prices.
     *
     * @var array
     */
    private $price_cache = array();

    /**
     * In-memory cache for rule evaluations.
     *
     * @var array
     */
    private $rule_cache = array();

    /**
     * Cache group for WordPress object cache.
     *
     * @var string
     */
    private $cache_group = 'jdpd_pricing';

    /**
     * Cache expiration time in seconds.
     *
     * @var int
     */
    private $cache_expiration = 3600; // 1 hour

    /**
     * Performance metrics.
     *
     * @var array
     */
    private $metrics = array();

    /**
     * Get single instance.
     *
     * @return JDPD_Performance
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
        $this->init_hooks();
        $this->init_metrics();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Cache invalidation
        add_action( 'jdpd_rule_updated', array( $this, 'invalidate_rule_cache' ) );
        add_action( 'jdpd_rule_deleted', array( $this, 'invalidate_rule_cache' ) );
        add_action( 'jdpd_settings_updated', array( $this, 'invalidate_all_cache' ) );

        // Product updates
        add_action( 'woocommerce_update_product', array( $this, 'invalidate_product_cache' ) );
        add_action( 'woocommerce_product_set_stock', array( $this, 'invalidate_product_cache' ) );

        // User changes
        add_action( 'woocommerce_customer_save_address', array( $this, 'invalidate_user_cache' ) );
        add_action( 'profile_update', array( $this, 'invalidate_user_cache' ) );

        // Preload rules on init
        add_action( 'init', array( $this, 'preload_rules' ), 5 );

        // Performance monitoring
        if ( $this->is_monitoring_enabled() ) {
            add_action( 'shutdown', array( $this, 'record_metrics' ) );
        }

        // Admin AJAX
        add_action( 'wp_ajax_jdpd_get_performance_stats', array( $this, 'ajax_get_performance_stats' ) );
        add_action( 'wp_ajax_jdpd_clear_cache', array( $this, 'ajax_clear_cache' ) );
        add_action( 'wp_ajax_jdpd_run_optimization', array( $this, 'ajax_run_optimization' ) );
    }

    /**
     * Initialize metrics tracking.
     */
    private function init_metrics() {
        $this->metrics = array(
            'start_time'        => microtime( true ),
            'rule_evaluations'  => 0,
            'cache_hits'        => 0,
            'cache_misses'      => 0,
            'db_queries'        => 0,
            'price_calculations' => 0,
        );
    }

    /**
     * Check if performance monitoring is enabled.
     *
     * @return bool Whether monitoring is enabled.
     */
    private function is_monitoring_enabled() {
        return defined( 'JDPD_PERFORMANCE_MONITORING' ) && JDPD_PERFORMANCE_MONITORING;
    }

    /**
     * Preload rules into memory.
     */
    public function preload_rules() {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        $cache_key = 'jdpd_preloaded_rules';
        $rules = wp_cache_get( $cache_key, $this->cache_group );

        if ( false === $rules ) {
            $rules = get_option( 'jdpd_rules', array() );

            // Filter to only enabled rules
            $rules = array_filter( $rules, function( $rule ) {
                return ! empty( $rule['enabled'] );
            } );

            // Sort by priority
            uasort( $rules, function( $a, $b ) {
                $priority_a = $a['priority'] ?? 10;
                $priority_b = $b['priority'] ?? 10;
                return $priority_a - $priority_b;
            } );

            wp_cache_set( $cache_key, $rules, $this->cache_group, $this->cache_expiration );
        }

        $this->rule_cache['preloaded'] = $rules;
    }

    /**
     * Get preloaded rules.
     *
     * @return array Rules.
     */
    public function get_preloaded_rules() {
        if ( ! isset( $this->rule_cache['preloaded'] ) ) {
            $this->preload_rules();
        }

        return $this->rule_cache['preloaded'] ?? array();
    }

    /**
     * Get cached price for a product.
     *
     * @param int   $product_id Product ID.
     * @param array $context Pricing context (user_id, quantity, etc.).
     * @return float|false Cached price or false.
     */
    public function get_cached_price( $product_id, $context = array() ) {
        $cache_key = $this->generate_price_cache_key( $product_id, $context );

        // Check in-memory cache first
        if ( isset( $this->price_cache[ $cache_key ] ) ) {
            $this->metrics['cache_hits']++;
            return $this->price_cache[ $cache_key ];
        }

        // Check object cache
        $cached = wp_cache_get( $cache_key, $this->cache_group );

        if ( false !== $cached ) {
            $this->metrics['cache_hits']++;
            $this->price_cache[ $cache_key ] = $cached;
            return $cached;
        }

        $this->metrics['cache_misses']++;
        return false;
    }

    /**
     * Set cached price for a product.
     *
     * @param int   $product_id Product ID.
     * @param float $price Calculated price.
     * @param array $context Pricing context.
     */
    public function set_cached_price( $product_id, $price, $context = array() ) {
        $cache_key = $this->generate_price_cache_key( $product_id, $context );

        // Store in in-memory cache
        $this->price_cache[ $cache_key ] = $price;

        // Store in object cache
        wp_cache_set( $cache_key, $price, $this->cache_group, $this->cache_expiration );
    }

    /**
     * Generate a cache key for price caching.
     *
     * @param int   $product_id Product ID.
     * @param array $context Pricing context.
     * @return string Cache key.
     */
    private function generate_price_cache_key( $product_id, $context = array() ) {
        $key_parts = array(
            'product_' . $product_id,
            'user_' . ( is_user_logged_in() ? get_current_user_id() : 0 ),
            'qty_' . ( $context['quantity'] ?? 1 ),
        );

        // Add role if user is logged in
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $key_parts[] = 'role_' . implode( '_', $user->roles );
        }

        // Add cart hash if in cart context
        if ( ! empty( $context['in_cart'] ) && WC()->cart ) {
            $key_parts[] = 'cart_' . WC()->cart->get_cart_hash();
        }

        return 'price_' . md5( implode( '|', $key_parts ) );
    }

    /**
     * Get cached rule evaluation result.
     *
     * @param string $rule_id Rule ID.
     * @param array  $context Evaluation context.
     * @return array|false Cached result or false.
     */
    public function get_cached_rule_result( $rule_id, $context = array() ) {
        $cache_key = $this->generate_rule_cache_key( $rule_id, $context );

        if ( isset( $this->rule_cache[ $cache_key ] ) ) {
            $this->metrics['cache_hits']++;
            return $this->rule_cache[ $cache_key ];
        }

        $this->metrics['cache_misses']++;
        return false;
    }

    /**
     * Set cached rule evaluation result.
     *
     * @param string $rule_id Rule ID.
     * @param array  $result Evaluation result.
     * @param array  $context Evaluation context.
     */
    public function set_cached_rule_result( $rule_id, $result, $context = array() ) {
        $cache_key = $this->generate_rule_cache_key( $rule_id, $context );
        $this->rule_cache[ $cache_key ] = $result;
    }

    /**
     * Generate a cache key for rule caching.
     *
     * @param string $rule_id Rule ID.
     * @param array  $context Evaluation context.
     * @return string Cache key.
     */
    private function generate_rule_cache_key( $rule_id, $context = array() ) {
        $key_parts = array(
            'rule_' . $rule_id,
            'product_' . ( $context['product_id'] ?? 0 ),
            'user_' . ( is_user_logged_in() ? get_current_user_id() : 0 ),
        );

        return 'rule_' . md5( implode( '|', $key_parts ) );
    }

    /**
     * Invalidate cache for a specific rule.
     *
     * @param string $rule_id Rule ID.
     */
    public function invalidate_rule_cache( $rule_id = null ) {
        // Clear preloaded rules
        wp_cache_delete( 'jdpd_preloaded_rules', $this->cache_group );
        unset( $this->rule_cache['preloaded'] );

        // Clear all rule-specific caches
        $this->rule_cache = array();

        // Clear price caches that might be affected
        $this->price_cache = array();

        // Flush the entire cache group
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( $this->cache_group );
        }
    }

    /**
     * Invalidate cache for a specific product.
     *
     * @param int $product_id Product ID.
     */
    public function invalidate_product_cache( $product_id ) {
        // Clear from in-memory cache
        foreach ( array_keys( $this->price_cache ) as $key ) {
            if ( strpos( $key, "product_{$product_id}" ) !== false ) {
                unset( $this->price_cache[ $key ] );
            }
        }

        // Delete from object cache
        $patterns = array(
            "price_*product_{$product_id}*",
        );

        // Clear related transients
        delete_transient( "jdpd_product_prices_{$product_id}" );
    }

    /**
     * Invalidate cache for a specific user.
     *
     * @param int $user_id User ID.
     */
    public function invalidate_user_cache( $user_id = null ) {
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        // Clear from in-memory cache
        foreach ( array_keys( $this->price_cache ) as $key ) {
            if ( strpos( $key, "user_{$user_id}" ) !== false ) {
                unset( $this->price_cache[ $key ] );
            }
        }
    }

    /**
     * Invalidate all caches.
     */
    public function invalidate_all_cache() {
        $this->price_cache = array();
        $this->rule_cache = array();

        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( $this->cache_group );
        } else {
            wp_cache_flush();
        }

        // Clear transients
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_jdpd_%'"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_jdpd_%'"
        );
    }

    /**
     * Track a metric.
     *
     * @param string $metric Metric name.
     * @param int    $increment Increment value.
     */
    public function track_metric( $metric, $increment = 1 ) {
        if ( isset( $this->metrics[ $metric ] ) ) {
            $this->metrics[ $metric ] += $increment;
        }
    }

    /**
     * Record metrics at shutdown.
     */
    public function record_metrics() {
        if ( ! $this->is_monitoring_enabled() ) {
            return;
        }

        $this->metrics['end_time'] = microtime( true );
        $this->metrics['execution_time'] = $this->metrics['end_time'] - $this->metrics['start_time'];
        $this->metrics['memory_usage'] = memory_get_peak_usage( true );
        $this->metrics['timestamp'] = current_time( 'mysql' );
        $this->metrics['request_uri'] = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( $_SERVER['REQUEST_URI'] ) : '';

        // Store in transient for later analysis
        $stored_metrics = get_transient( 'jdpd_performance_metrics' ) ?: array();

        // Keep only last 100 records
        if ( count( $stored_metrics ) >= 100 ) {
            array_shift( $stored_metrics );
        }

        $stored_metrics[] = $this->metrics;
        set_transient( 'jdpd_performance_metrics', $stored_metrics, DAY_IN_SECONDS );

        // Log slow requests
        if ( $this->metrics['execution_time'] > 1 ) {
            $this->log_slow_request();
        }
    }

    /**
     * Log slow requests.
     */
    private function log_slow_request() {
        $slow_requests = get_transient( 'jdpd_slow_requests' ) ?: array();

        if ( count( $slow_requests ) >= 50 ) {
            array_shift( $slow_requests );
        }

        $slow_requests[] = array(
            'uri'           => $this->metrics['request_uri'],
            'time'          => $this->metrics['execution_time'],
            'evaluations'   => $this->metrics['rule_evaluations'],
            'cache_hits'    => $this->metrics['cache_hits'],
            'cache_misses'  => $this->metrics['cache_misses'],
            'timestamp'     => $this->metrics['timestamp'],
        );

        set_transient( 'jdpd_slow_requests', $slow_requests, WEEK_IN_SECONDS );
    }

    /**
     * Get performance statistics.
     *
     * @return array Performance stats.
     */
    public function get_performance_stats() {
        $metrics = get_transient( 'jdpd_performance_metrics' ) ?: array();
        $slow_requests = get_transient( 'jdpd_slow_requests' ) ?: array();

        if ( empty( $metrics ) ) {
            return array(
                'summary'        => array(),
                'slow_requests'  => array(),
                'cache_stats'    => $this->get_cache_stats(),
                'recommendations' => $this->get_recommendations(),
            );
        }

        // Calculate averages
        $total_time = 0;
        $total_evaluations = 0;
        $total_hits = 0;
        $total_misses = 0;
        $count = count( $metrics );

        foreach ( $metrics as $metric ) {
            $total_time += $metric['execution_time'] ?? 0;
            $total_evaluations += $metric['rule_evaluations'] ?? 0;
            $total_hits += $metric['cache_hits'] ?? 0;
            $total_misses += $metric['cache_misses'] ?? 0;
        }

        return array(
            'summary' => array(
                'avg_execution_time'  => $count > 0 ? round( $total_time / $count, 4 ) : 0,
                'avg_rule_evaluations' => $count > 0 ? round( $total_evaluations / $count, 2 ) : 0,
                'cache_hit_rate'      => ( $total_hits + $total_misses ) > 0
                    ? round( ( $total_hits / ( $total_hits + $total_misses ) ) * 100, 2 )
                    : 0,
                'total_requests'      => $count,
            ),
            'slow_requests'   => $slow_requests,
            'cache_stats'     => $this->get_cache_stats(),
            'recommendations' => $this->get_recommendations(),
        );
    }

    /**
     * Get cache statistics.
     *
     * @return array Cache stats.
     */
    private function get_cache_stats() {
        global $wpdb;

        $stats = array(
            'object_cache_available' => wp_using_ext_object_cache(),
            'transient_count'        => 0,
            'price_cache_size'       => count( $this->price_cache ),
            'rule_cache_size'        => count( $this->rule_cache ),
        );

        // Count transients
        $stats['transient_count'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_jdpd_%'"
        );

        return $stats;
    }

    /**
     * Get performance recommendations.
     *
     * @return array Recommendations.
     */
    public function get_recommendations() {
        $recommendations = array();
        $stats = $this->get_performance_stats();

        // Check object cache
        if ( ! wp_using_ext_object_cache() ) {
            $recommendations[] = array(
                'type'        => 'warning',
                'title'       => __( 'Enable Object Caching', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Install Redis or Memcached for better caching performance. This can significantly improve pricing calculation speed.', 'jezweb-dynamic-pricing' ),
            );
        }

        // Check cache hit rate
        if ( isset( $stats['summary']['cache_hit_rate'] ) && $stats['summary']['cache_hit_rate'] < 50 ) {
            $recommendations[] = array(
                'type'        => 'info',
                'title'       => __( 'Low Cache Hit Rate', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Your cache hit rate is below 50%. This may be normal for sites with many unique visitors or highly dynamic pricing.', 'jezweb-dynamic-pricing' ),
            );
        }

        // Check slow requests
        if ( count( $stats['slow_requests'] ?? array() ) > 10 ) {
            $recommendations[] = array(
                'type'        => 'warning',
                'title'       => __( 'Multiple Slow Requests Detected', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Consider reducing the number of active pricing rules or simplifying complex conditions.', 'jezweb-dynamic-pricing' ),
            );
        }

        // Check rule count
        $rules = get_option( 'jdpd_rules', array() );
        $active_rules = array_filter( $rules, function( $rule ) {
            return ! empty( $rule['enabled'] );
        } );

        if ( count( $active_rules ) > 50 ) {
            $recommendations[] = array(
                'type'        => 'warning',
                'title'       => __( 'Many Active Rules', 'jezweb-dynamic-pricing' ),
                'description' => sprintf(
                    /* translators: %d: Number of rules */
                    __( 'You have %d active pricing rules. Consider consolidating rules or using customer segments for better performance.', 'jezweb-dynamic-pricing' ),
                    count( $active_rules )
                ),
            );
        }

        // Check transient count
        if ( ( $stats['cache_stats']['transient_count'] ?? 0 ) > 1000 ) {
            $recommendations[] = array(
                'type'        => 'info',
                'title'       => __( 'High Transient Count', 'jezweb-dynamic-pricing' ),
                'description' => __( 'Consider clearing old transients to free up database space.', 'jezweb-dynamic-pricing' ),
            );
        }

        if ( empty( $recommendations ) ) {
            $recommendations[] = array(
                'type'        => 'success',
                'title'       => __( 'Performance Looks Good!', 'jezweb-dynamic-pricing' ),
                'description' => __( 'No significant performance issues detected.', 'jezweb-dynamic-pricing' ),
            );
        }

        return $recommendations;
    }

    /**
     * Run optimization routines.
     *
     * @return array Optimization results.
     */
    public function run_optimization() {
        $results = array();

        // Clear old transients
        global $wpdb;
        $deleted_transients = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_timeout_jdpd_%'
             AND option_value < " . time()
        );
        $wpdb->query(
            "DELETE a FROM {$wpdb->options} a
             LEFT JOIN {$wpdb->options} b ON b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12))
             WHERE a.option_name LIKE '_transient_jdpd_%'
             AND b.option_name IS NULL"
        );

        $results['transients_cleared'] = $deleted_transients;

        // Optimize rules storage
        $rules = get_option( 'jdpd_rules', array() );
        $original_size = strlen( maybe_serialize( $rules ) );

        // Remove any orphaned data
        foreach ( $rules as $id => &$rule ) {
            // Remove empty arrays
            foreach ( $rule as $key => $value ) {
                if ( is_array( $value ) && empty( $value ) ) {
                    unset( $rule[ $key ] );
                }
            }
        }

        update_option( 'jdpd_rules', $rules );
        $new_size = strlen( maybe_serialize( $rules ) );

        $results['rules_optimized'] = array(
            'original_size' => $original_size,
            'new_size'      => $new_size,
            'saved_bytes'   => $original_size - $new_size,
        );

        // Clear analytics old data (keep last 90 days)
        if ( class_exists( 'JDPD_Analytics' ) ) {
            $analytics = JDPD_Analytics::get_instance();
            if ( method_exists( $analytics, 'cleanup_old_data' ) ) {
                $results['analytics_cleaned'] = $analytics->cleanup_old_data( 90 );
            }
        }

        // Preload rules
        $this->invalidate_all_cache();
        $this->preload_rules();

        $results['cache_rebuilt'] = true;

        return $results;
    }

    /**
     * Batch process products for price calculation.
     *
     * @param array $product_ids Product IDs.
     * @param array $context Pricing context.
     * @return array Calculated prices.
     */
    public function batch_calculate_prices( $product_ids, $context = array() ) {
        $prices = array();
        $uncached = array();

        // Check cache first
        foreach ( $product_ids as $product_id ) {
            $cached = $this->get_cached_price( $product_id, $context );
            if ( false !== $cached ) {
                $prices[ $product_id ] = $cached;
            } else {
                $uncached[] = $product_id;
            }
        }

        // Calculate uncached prices
        if ( ! empty( $uncached ) ) {
            // Get products in batch
            $products = wc_get_products( array(
                'include' => $uncached,
                'limit'   => -1,
            ) );

            foreach ( $products as $product ) {
                $price = $this->calculate_product_price( $product, $context );
                $prices[ $product->get_id() ] = $price;
                $this->set_cached_price( $product->get_id(), $price, $context );
            }
        }

        return $prices;
    }

    /**
     * Calculate product price with caching.
     *
     * @param WC_Product $product Product object.
     * @param array      $context Pricing context.
     * @return float Calculated price.
     */
    private function calculate_product_price( $product, $context = array() ) {
        // Get applicable rules
        $rules = $this->get_preloaded_rules();
        $price = $product->get_regular_price();

        foreach ( $rules as $rule_id => $rule ) {
            // Check if rule applies to this product
            if ( $this->rule_applies_to_product( $rule, $product ) ) {
                $price = $this->apply_rule_to_price( $price, $rule, $product, $context );
            }
        }

        $this->metrics['price_calculations']++;

        return $price;
    }

    /**
     * Check if a rule applies to a product.
     *
     * @param array      $rule Rule data.
     * @param WC_Product $product Product object.
     * @return bool Whether rule applies.
     */
    private function rule_applies_to_product( $rule, $product ) {
        $this->metrics['rule_evaluations']++;

        // Check product-specific rules
        if ( ! empty( $rule['products'] ) ) {
            if ( ! in_array( $product->get_id(), $rule['products'], true ) ) {
                return false;
            }
        }

        // Check category rules
        if ( ! empty( $rule['categories'] ) ) {
            $product_categories = $product->get_category_ids();
            if ( empty( array_intersect( $product_categories, $rule['categories'] ) ) ) {
                return false;
            }
        }

        // Check exclusions
        if ( ! empty( $rule['exclude_products'] ) ) {
            if ( in_array( $product->get_id(), $rule['exclude_products'], true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply a rule to a price.
     *
     * @param float      $price Current price.
     * @param array      $rule Rule data.
     * @param WC_Product $product Product object.
     * @param array      $context Pricing context.
     * @return float Modified price.
     */
    private function apply_rule_to_price( $price, $rule, $product, $context = array() ) {
        $discount_type = $rule['discount_type'] ?? 'percentage';
        $discount_value = floatval( $rule['discount_value'] ?? 0 );

        switch ( $discount_type ) {
            case 'percentage':
                $price = $price * ( 1 - ( $discount_value / 100 ) );
                break;

            case 'fixed':
                $price = max( 0, $price - $discount_value );
                break;

            case 'fixed_price':
                $price = $discount_value;
                break;
        }

        return round( $price, wc_get_price_decimals() );
    }

    /**
     * AJAX: Get performance stats.
     */
    public function ajax_get_performance_stats() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        wp_send_json_success( $this->get_performance_stats() );
    }

    /**
     * AJAX: Clear cache.
     */
    public function ajax_clear_cache() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $this->invalidate_all_cache();

        wp_send_json_success( array(
            'message' => __( 'Cache cleared successfully.', 'jezweb-dynamic-pricing' ),
        ) );
    }

    /**
     * AJAX: Run optimization.
     */
    public function ajax_run_optimization() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $results = $this->run_optimization();

        wp_send_json_success( array(
            'message' => __( 'Optimization completed.', 'jezweb-dynamic-pricing' ),
            'results' => $results,
        ) );
    }

    /**
     * Get current memory usage.
     *
     * @return string Formatted memory usage.
     */
    public function get_memory_usage() {
        return size_format( memory_get_usage( true ) );
    }

    /**
     * Get peak memory usage.
     *
     * @return string Formatted peak memory usage.
     */
    public function get_peak_memory_usage() {
        return size_format( memory_get_peak_usage( true ) );
    }

    /**
     * Debug output for development.
     *
     * @return array Debug info.
     */
    public function get_debug_info() {
        return array(
            'price_cache_count' => count( $this->price_cache ),
            'rule_cache_count'  => count( $this->rule_cache ),
            'memory_usage'      => $this->get_memory_usage(),
            'peak_memory'       => $this->get_peak_memory_usage(),
            'metrics'           => $this->metrics,
            'object_cache'      => wp_using_ext_object_cache() ? 'enabled' : 'disabled',
        );
    }
}
