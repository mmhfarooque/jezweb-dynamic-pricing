<?php
/**
 * Debug Logger for Jezweb Dynamic Pricing
 *
 * Handles all logging operations and prevents site crashes from critical errors.
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logger class
 */
class JDPD_Logger {

    /**
     * Log levels
     */
    const EMERGENCY = 'emergency';
    const ALERT     = 'alert';
    const CRITICAL  = 'critical';
    const ERROR     = 'error';
    const WARNING   = 'warning';
    const NOTICE    = 'notice';
    const INFO      = 'info';
    const DEBUG     = 'debug';

    /**
     * Single instance
     *
     * @var JDPD_Logger
     */
    private static $instance = null;

    /**
     * Log file path
     *
     * @var string
     */
    private $log_file;

    /**
     * Log directory path
     *
     * @var string
     */
    private $log_dir;

    /**
     * Whether debug mode is enabled
     *
     * @var bool
     */
    private $debug_enabled;

    /**
     * Maximum log file size in bytes (5MB)
     *
     * @var int
     */
    private $max_file_size = 5242880;

    /**
     * Get single instance
     *
     * @return JDPD_Logger
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
        $this->debug_enabled = get_option( 'jdpd_enable_debug_log', 'yes' ) === 'yes';
        $this->setup_log_directory();
        $this->register_error_handlers();
    }

    /**
     * Setup log directory
     */
    private function setup_log_directory() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = trailingslashit( $upload_dir['basedir'] ) . 'jdpd-logs';
        $this->log_file = $this->log_dir . '/debug-' . wp_hash( 'jdpd-log' ) . '.log';

        // Create directory if it doesn't exist
        if ( ! file_exists( $this->log_dir ) ) {
            wp_mkdir_p( $this->log_dir );
        }

        // Add .htaccess to protect logs
        $htaccess_file = $this->log_dir . '/.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            file_put_contents( $htaccess_file, 'deny from all' );
        }

        // Add index.php for extra protection
        $index_file = $this->log_dir . '/index.php';
        if ( ! file_exists( $index_file ) ) {
            file_put_contents( $index_file, '<?php // Silence is golden' );
        }
    }

    /**
     * Register error handlers to prevent site crashes
     */
    private function register_error_handlers() {
        // Set custom error handler for JDPD errors
        set_error_handler( array( $this, 'error_handler' ), E_ALL );

        // Register shutdown function to catch fatal errors
        register_shutdown_function( array( $this, 'shutdown_handler' ) );
    }

    /**
     * Custom error handler
     *
     * @param int    $errno   Error number.
     * @param string $errstr  Error string.
     * @param string $errfile Error file.
     * @param int    $errline Error line.
     * @return bool
     */
    public function error_handler( $errno, $errstr, $errfile, $errline ) {
        // Only handle errors from our plugin
        if ( strpos( $errfile, 'jezweb-dynamic-pricing' ) === false ) {
            return false; // Let PHP handle other errors
        }

        $error_types = array(
            E_ERROR             => 'Error',
            E_WARNING           => 'Warning',
            E_PARSE             => 'Parse Error',
            E_NOTICE            => 'Notice',
            E_CORE_ERROR        => 'Core Error',
            E_CORE_WARNING      => 'Core Warning',
            E_COMPILE_ERROR     => 'Compile Error',
            E_COMPILE_WARNING   => 'Compile Warning',
            E_USER_ERROR        => 'User Error',
            E_USER_WARNING      => 'User Warning',
            E_USER_NOTICE       => 'User Notice',
            E_STRICT            => 'Strict',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED        => 'Deprecated',
            E_USER_DEPRECATED   => 'User Deprecated',
        );

        $type = isset( $error_types[ $errno ] ) ? $error_types[ $errno ] : 'Unknown';
        $level = in_array( $errno, array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ) )
            ? self::ERROR
            : self::WARNING;

        $this->log(
            sprintf( '[PHP %s] %s in %s on line %d', $type, $errstr, $errfile, $errline ),
            $level
        );

        // Don't execute PHP internal error handler for non-fatal errors
        return ! in_array( $errno, array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ) );
    }

    /**
     * Shutdown handler to catch fatal errors
     */
    public function shutdown_handler() {
        $error = error_get_last();

        if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ) ) ) {
            // Only log if it's from our plugin
            if ( strpos( $error['file'], 'jezweb-dynamic-pricing' ) !== false ) {
                $this->log(
                    sprintf(
                        '[FATAL] %s in %s on line %d',
                        $error['message'],
                        $error['file'],
                        $error['line']
                    ),
                    self::CRITICAL
                );
            }
        }
    }

    /**
     * Log a message
     *
     * @param string $message Message to log.
     * @param string $level   Log level.
     * @param array  $context Additional context.
     */
    public function log( $message, $level = self::INFO, $context = array() ) {
        if ( ! $this->debug_enabled && ! in_array( $level, array( self::EMERGENCY, self::ALERT, self::CRITICAL, self::ERROR ) ) ) {
            return;
        }

        try {
            $this->rotate_log_if_needed();

            $timestamp = current_time( 'Y-m-d H:i:s' );
            $level_upper = strtoupper( $level );

            // Format context if provided
            $context_string = '';
            if ( ! empty( $context ) ) {
                $context_string = ' | Context: ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
            }

            // Get caller info
            $backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 );
            $caller = isset( $backtrace[1] ) ? $backtrace[1] : array();
            $caller_info = '';
            if ( isset( $caller['class'] ) ) {
                $caller_info = $caller['class'] . '::' . $caller['function'];
            } elseif ( isset( $caller['function'] ) ) {
                $caller_info = $caller['function'];
            }

            $log_entry = sprintf(
                "[%s] [%s] [%s] %s%s\n",
                $timestamp,
                $level_upper,
                $caller_info,
                $message,
                $context_string
            );

            // Write to log file
            file_put_contents( $this->log_file, $log_entry, FILE_APPEND | LOCK_EX );

            // Also log to WooCommerce if available and level is error or higher
            if ( in_array( $level, array( self::EMERGENCY, self::ALERT, self::CRITICAL, self::ERROR ) ) ) {
                if ( function_exists( 'wc_get_logger' ) ) {
                    $wc_logger = wc_get_logger();
                    $wc_logger->log( $level, $message, array( 'source' => 'jezweb-dynamic-pricing' ) );
                }
            }

        } catch ( Exception $e ) {
            // Silently fail - don't crash the site because of logging
            error_log( 'JDPD Logger Error: ' . $e->getMessage() );
        }
    }

    /**
     * Rotate log file if it exceeds max size
     */
    private function rotate_log_if_needed() {
        if ( ! file_exists( $this->log_file ) ) {
            return;
        }

        if ( filesize( $this->log_file ) > $this->max_file_size ) {
            $archive_file = $this->log_dir . '/debug-' . wp_hash( 'jdpd-log' ) . '-' . date( 'Y-m-d-His' ) . '.log';
            rename( $this->log_file, $archive_file );

            // Keep only last 5 archive files
            $this->cleanup_old_logs();
        }
    }

    /**
     * Cleanup old log files
     */
    private function cleanup_old_logs() {
        $files = glob( $this->log_dir . '/debug-*.log' );
        if ( count( $files ) > 5 ) {
            usort( $files, function( $a, $b ) {
                return filemtime( $a ) - filemtime( $b );
            });

            $files_to_delete = array_slice( $files, 0, count( $files ) - 5 );
            foreach ( $files_to_delete as $file ) {
                unlink( $file );
            }
        }
    }

    /**
     * Log convenience methods
     */
    public function emergency( $message, $context = array() ) {
        $this->log( $message, self::EMERGENCY, $context );
    }

    public function alert( $message, $context = array() ) {
        $this->log( $message, self::ALERT, $context );
    }

    public function critical( $message, $context = array() ) {
        $this->log( $message, self::CRITICAL, $context );
    }

    public function error( $message, $context = array() ) {
        $this->log( $message, self::ERROR, $context );
    }

    public function warning( $message, $context = array() ) {
        $this->log( $message, self::WARNING, $context );
    }

    public function notice( $message, $context = array() ) {
        $this->log( $message, self::NOTICE, $context );
    }

    public function info( $message, $context = array() ) {
        $this->log( $message, self::INFO, $context );
    }

    public function debug( $message, $context = array() ) {
        $this->log( $message, self::DEBUG, $context );
    }

    /**
     * Get log file path
     *
     * @return string
     */
    public function get_log_file() {
        return $this->log_file;
    }

    /**
     * Get log directory path
     *
     * @return string
     */
    public function get_log_dir() {
        return $this->log_dir;
    }

    /**
     * Get log contents
     *
     * @param int $lines Number of lines to retrieve.
     * @return string
     */
    public function get_log_contents( $lines = 100 ) {
        if ( ! file_exists( $this->log_file ) ) {
            return '';
        }

        $file = new SplFileObject( $this->log_file, 'r' );
        $file->seek( PHP_INT_MAX );
        $total_lines = $file->key();

        $start = max( 0, $total_lines - $lines );
        $output = array();

        $file->seek( $start );
        while ( ! $file->eof() ) {
            $line = $file->fgets();
            if ( trim( $line ) !== '' ) {
                $output[] = $line;
            }
        }

        return implode( '', $output );
    }

    /**
     * Clear log file
     */
    public function clear_log() {
        if ( file_exists( $this->log_file ) ) {
            file_put_contents( $this->log_file, '' );
        }
        $this->log( 'Log file cleared', self::INFO );
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function is_debug_enabled() {
        return $this->debug_enabled;
    }
}

/**
 * Get logger instance
 *
 * @return JDPD_Logger
 */
function jdpd_logger() {
    return JDPD_Logger::instance();
}

/**
 * Log a message (shorthand function)
 *
 * @param string $message Message to log.
 * @param string $level   Log level.
 * @param array  $context Additional context.
 */
function jdpd_log( $message, $level = 'info', $context = array() ) {
    jdpd_logger()->log( $message, $level, $context );
}
