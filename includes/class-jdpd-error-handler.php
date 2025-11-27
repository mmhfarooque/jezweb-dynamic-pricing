<?php
/**
 * Error Handler for Jezweb Dynamic Pricing
 *
 * Provides safe execution wrapper to prevent site crashes from plugin errors.
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Error Handler class
 */
class JDPD_Error_Handler {

    /**
     * Single instance
     *
     * @var JDPD_Error_Handler
     */
    private static $instance = null;

    /**
     * Error count in current request
     *
     * @var int
     */
    private $error_count = 0;

    /**
     * Maximum errors before disabling plugin for request
     *
     * @var int
     */
    private $max_errors = 10;

    /**
     * Whether plugin is disabled for current request
     *
     * @var bool
     */
    private $disabled = false;

    /**
     * Whether handler is initialized
     *
     * @var bool
     */
    private $initialized = false;

    /**
     * Get single instance
     *
     * @return JDPD_Error_Handler
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Defer initialization until WordPress is ready
        if ( did_action( 'plugins_loaded' ) ) {
            $this->init();
        } else {
            add_action( 'plugins_loaded', array( $this, 'init' ), 2 );
        }
    }

    /**
     * Initialize the error handler
     */
    public function init() {
        if ( $this->initialized ) {
            return;
        }
        $this->initialized = true;
        $this->check_critical_errors();
    }

    /**
     * Check for critical errors from previous requests
     */
    private function check_critical_errors() {
        if ( ! function_exists( 'get_transient' ) ) {
            return;
        }

        // Check for force enable flag (set by reset)
        if ( get_option( 'jdpd_force_enable' ) ) {
            delete_option( 'jdpd_force_enable' );
            return;
        }

        $critical_errors = get_transient( 'jdpd_critical_errors' );

        if ( $critical_errors && $critical_errors >= 5 ) {
            $this->disabled = true;

            // Show admin notice
            add_action( 'admin_notices', array( $this, 'show_critical_error_notice' ) );

            // Log the disabling
            if ( function_exists( 'jdpd_log' ) ) {
                jdpd_log( 'Plugin temporarily disabled due to repeated critical errors', 'critical' );
            }
        }
    }

    /**
     * Show critical error admin notice
     */
    public function show_critical_error_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $reset_url = wp_nonce_url(
            admin_url( 'admin.php?page=jdpd-settings&action=reset_errors' ),
            'jdpd_reset_errors'
        );
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'Jezweb Dynamic Pricing', 'jezweb-dynamic-pricing' ); ?>:</strong>
                <?php esc_html_e( 'The plugin has been temporarily disabled due to repeated critical errors. Please check the debug log for details.', 'jezweb-dynamic-pricing' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( $reset_url ); ?>" class="button">
                    <?php esc_html_e( 'Reset & Re-enable Plugin', 'jezweb-dynamic-pricing' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=jdpd-settings&tab=debug' ) ); ?>" class="button">
                    <?php esc_html_e( 'View Debug Log', 'jezweb-dynamic-pricing' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Safely execute a callback with error handling
     *
     * @param callable $callback    Callback to execute.
     * @param mixed    $default     Default value to return on error.
     * @param string   $context     Context description for logging.
     * @param array    $args        Arguments to pass to callback.
     * @return mixed
     */
    public function safe_execute( $callback, $default = null, $context = '', $args = array() ) {
        // If plugin is disabled, return default
        if ( $this->disabled ) {
            return $default;
        }

        // If too many errors in this request, return default
        if ( $this->error_count >= $this->max_errors ) {
            return $default;
        }

        try {
            $result = call_user_func_array( $callback, $args );

            // Log successful execution in debug mode
            if ( function_exists( 'jdpd_logger' ) && jdpd_logger()->is_debug_enabled() && $context ) {
                jdpd_log( 'Executed: ' . $context, 'debug' );
            }

            return $result;

        } catch ( Throwable $e ) {
            $this->handle_error( $e, $context );
            return $default;

        } catch ( Exception $e ) {
            $this->handle_error( $e, $context );
            return $default;
        }
    }

    /**
     * Handle an error/exception
     *
     * @param Throwable|Exception $e       The exception.
     * @param string              $context Context description.
     */
    private function handle_error( $e, $context = '' ) {
        $this->error_count++;

        // Log the error
        if ( function_exists( 'jdpd_log' ) ) {
            $message = sprintf(
                'Error in %s: %s in %s on line %d',
                $context ?: 'unknown context',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );

            jdpd_log( $message, 'error', array(
                'trace' => $e->getTraceAsString(),
            ) );
        }

        // Track critical errors
        if ( $e instanceof Error || $this->is_critical_exception( $e ) ) {
            $this->record_critical_error();
        }
    }

    /**
     * Check if exception is critical
     *
     * @param Exception $e The exception.
     * @return bool
     */
    private function is_critical_exception( $e ) {
        $critical_classes = array(
            'ParseError',
            'TypeError',
            'ArgumentCountError',
            'DivisionByZeroError',
        );

        foreach ( $critical_classes as $class ) {
            if ( $e instanceof $class ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a critical error
     */
    private function record_critical_error() {
        if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
            return;
        }

        $critical_errors = get_transient( 'jdpd_critical_errors' );
        $critical_errors = $critical_errors ? (int) $critical_errors + 1 : 1;

        // Store for 1 hour
        set_transient( 'jdpd_critical_errors', $critical_errors, HOUR_IN_SECONDS );
    }

    /**
     * Reset critical errors counter
     */
    public function reset_critical_errors() {
        global $wpdb;

        // Delete transient the normal way
        delete_transient( 'jdpd_critical_errors' );

        // Also delete directly from database (bypasses object cache)
        $wpdb->delete(
            $wpdb->options,
            array( 'option_name' => '_transient_jdpd_critical_errors' )
        );
        $wpdb->delete(
            $wpdb->options,
            array( 'option_name' => '_transient_timeout_jdpd_critical_errors' )
        );

        // Clear object cache if available
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( 'jdpd_critical_errors', 'transient' );
            wp_cache_flush();
        }

        // Set force enable flag for next page load
        update_option( 'jdpd_force_enable', true, false );

        $this->disabled = false;
        $this->error_count = 0;

        if ( function_exists( 'jdpd_log' ) ) {
            jdpd_log( 'Critical errors reset, plugin re-enabled', 'info' );
        }
    }

    /**
     * Check if plugin is disabled
     *
     * @return bool
     */
    public function is_disabled() {
        return $this->disabled;
    }

    /**
     * Get error count for current request
     *
     * @return int
     */
    public function get_error_count() {
        return $this->error_count;
    }

    /**
     * Wrap a filter callback safely
     *
     * @param callable $callback Callback to wrap.
     * @param string   $context  Context description.
     * @return callable
     */
    public function wrap_filter( $callback, $context = '' ) {
        return function( ...$args ) use ( $callback, $context ) {
            $default = isset( $args[0] ) ? $args[0] : null;
            return $this->safe_execute( $callback, $default, $context, $args );
        };
    }

    /**
     * Wrap an action callback safely
     *
     * @param callable $callback Callback to wrap.
     * @param string   $context  Context description.
     * @return callable
     */
    public function wrap_action( $callback, $context = '' ) {
        return function( ...$args ) use ( $callback, $context ) {
            $this->safe_execute( $callback, null, $context, $args );
        };
    }
}

/**
 * Get error handler instance
 *
 * @return JDPD_Error_Handler
 */
function jdpd_error_handler() {
    return JDPD_Error_Handler::instance();
}

/**
 * Safely execute a callback
 *
 * @param callable $callback Callback to execute.
 * @param mixed    $default  Default value on error.
 * @param string   $context  Context for logging.
 * @param array    $args     Arguments for callback.
 * @return mixed
 */
function jdpd_safe_execute( $callback, $default = null, $context = '', $args = array() ) {
    return jdpd_error_handler()->safe_execute( $callback, $default, $context, $args );
}
