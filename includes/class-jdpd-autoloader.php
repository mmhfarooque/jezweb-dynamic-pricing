<?php
/**
 * Autoloader for JDPD classes
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Autoloader class
 */
class JDPD_Autoloader {

    /**
     * Path to includes directory
     *
     * @var string
     */
    private $include_path;

    /**
     * Constructor
     */
    public function __construct() {
        $this->include_path = JDPD_PLUGIN_PATH . 'includes/';
        spl_autoload_register( array( $this, 'autoload' ) );
    }

    /**
     * Autoload classes
     *
     * @param string $class Class name.
     */
    public function autoload( $class ) {
        // Only handle JDPD classes
        if ( 0 !== strpos( $class, 'JDPD_' ) ) {
            return;
        }

        $file = $this->get_file_name_from_class( $class );
        $path = '';

        // Check in includes directory
        if ( file_exists( $this->include_path . $file ) ) {
            $path = $this->include_path . $file;
        }

        // Check in admin directory
        if ( empty( $path ) && file_exists( JDPD_PLUGIN_PATH . 'admin/' . $file ) ) {
            $path = JDPD_PLUGIN_PATH . 'admin/' . $file;
        }

        // Check in public directory
        if ( empty( $path ) && file_exists( JDPD_PLUGIN_PATH . 'public/' . $file ) ) {
            $path = JDPD_PLUGIN_PATH . 'public/' . $file;
        }

        if ( ! empty( $path ) && file_exists( $path ) ) {
            include_once $path;
        }
    }

    /**
     * Convert class name to file name
     *
     * @param string $class Class name.
     * @return string
     */
    private function get_file_name_from_class( $class ) {
        return 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
    }
}

// Initialize autoloader
new JDPD_Autoloader();
