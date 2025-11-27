<?php
/**
 * GitHub Updater for automatic plugin updates
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub Updater class
 */
class JDPD_GitHub_Updater {

    /**
     * GitHub repository details
     */
    private $github_username = 'mmhfarooque';
    private $github_repo = 'jezweb-dynamic-pricing';
    private $github_branch = 'main';

    /**
     * Plugin details
     */
    private $plugin_file;
    private $plugin_basename;
    private $plugin_slug;
    private $current_version;

    /**
     * GitHub API response
     */
    private $github_response;

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_file = JDPD_PLUGIN_FILE;
        $this->plugin_basename = JDPD_PLUGIN_BASENAME;
        $this->plugin_slug = dirname( $this->plugin_basename );
        $this->current_version = JDPD_VERSION;

        // Hook into update system
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );

        // Clear transient on plugin update
        add_action( 'upgrader_process_complete', array( $this, 'clear_transients' ), 10, 2 );
    }

    /**
     * Get GitHub API URL
     *
     * @return string
     */
    private function get_api_url() {
        return sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_username,
            $this->github_repo
        );
    }

    /**
     * Get GitHub repository information
     *
     * @return object|false
     */
    private function get_github_info() {
        if ( ! empty( $this->github_response ) ) {
            return $this->github_response;
        }

        // Check transient first
        $transient_key = 'jdpd_github_response';
        $cached = get_transient( $transient_key );

        if ( false !== $cached ) {
            $this->github_response = $cached;
            return $this->github_response;
        }

        // Fetch from GitHub API
        $response = wp_remote_get(
            $this->get_api_url(),
            array(
                'headers' => array(
                    'Accept' => 'application/vnd.github.v3+json',
                ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body );

        if ( empty( $data ) || isset( $data->message ) ) {
            return false;
        }

        // Cache for 6 hours
        set_transient( $transient_key, $data, 6 * HOUR_IN_SECONDS );

        $this->github_response = $data;
        return $this->github_response;
    }

    /**
     * Check for plugin updates
     *
     * @param object $transient Update transient.
     * @return object
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $github_info = $this->get_github_info();

        if ( false === $github_info ) {
            return $transient;
        }

        // Get version from tag name (remove 'v' prefix if present)
        $github_version = ltrim( $github_info->tag_name, 'v' );

        // Compare versions
        if ( version_compare( $github_version, $this->current_version, '>' ) ) {
            // Get download URL
            $download_url = '';
            if ( ! empty( $github_info->zipball_url ) ) {
                $download_url = $github_info->zipball_url;
            } elseif ( ! empty( $github_info->assets ) && ! empty( $github_info->assets[0]->browser_download_url ) ) {
                $download_url = $github_info->assets[0]->browser_download_url;
            }

            $transient->response[ $this->plugin_basename ] = (object) array(
                'id'          => $this->plugin_basename,
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $github_version,
                'url'         => sprintf( 'https://github.com/%s/%s', $this->github_username, $this->github_repo ),
                'package'     => $download_url,
                'icons'       => array(),
                'banners'     => array(),
                'tested'      => '',
                'requires_php' => '8.0',
            );
        }

        return $transient;
    }

    /**
     * Provide plugin information for the update details popup
     *
     * @param false|object|array $result Plugin API result.
     * @param string             $action API action.
     * @param object             $args   API arguments.
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $github_info = $this->get_github_info();

        if ( false === $github_info ) {
            return $result;
        }

        $github_version = ltrim( $github_info->tag_name, 'v' );

        $plugin_info = (object) array(
            'name'              => 'Jezweb Dynamic Pricing & Discounts for WooCommerce',
            'slug'              => $this->plugin_slug,
            'version'           => $github_version,
            'author'            => '<a href="https://jezweb.com.au">Mahmmud Farooque</a>',
            'author_profile'    => 'https://jezweb.com.au',
            'homepage'          => sprintf( 'https://github.com/%s/%s', $this->github_username, $this->github_repo ),
            'short_description' => 'Powerful dynamic pricing and discount rules for WooCommerce.',
            'sections'          => array(
                'description'  => $this->get_description(),
                'installation' => $this->get_installation_instructions(),
                'changelog'    => $this->get_changelog( $github_info ),
            ),
            'download_link'     => ! empty( $github_info->zipball_url ) ? $github_info->zipball_url : '',
            'requires'          => '6.0',
            'tested'            => '6.7',
            'requires_php'      => '8.0',
            'last_updated'      => ! empty( $github_info->published_at ) ? $github_info->published_at : '',
            'banners'           => array(),
        );

        return $plugin_info;
    }

    /**
     * Get plugin description
     *
     * @return string
     */
    private function get_description() {
        return '
            <h4>Jezweb Dynamic Pricing & Discounts for WooCommerce</h4>
            <p>A powerful dynamic pricing and discount rules plugin for WooCommerce that allows you to create:</p>
            <ul>
                <li>Quantity-based bulk discounts</li>
                <li>Cart total discounts</li>
                <li>BOGO and special offers (Buy X Get Y)</li>
                <li>Gift products</li>
                <li>Category and tag-based pricing</li>
                <li>User role-based pricing</li>
                <li>Scheduled promotions</li>
            </ul>
            <p>Built by <a href="https://jezweb.com.au">Jezweb</a> - Australian Web Development Agency</p>
        ';
    }

    /**
     * Get installation instructions
     *
     * @return string
     */
    private function get_installation_instructions() {
        return '
            <h4>Installation</h4>
            <ol>
                <li>Upload the plugin files to the <code>/wp-content/plugins/jezweb-dynamic-pricing</code> directory</li>
                <li>Activate the plugin through the "Plugins" screen in WordPress</li>
                <li>Go to Dynamic Pricing menu to configure your discount rules</li>
            </ol>
            <h4>Requirements</h4>
            <ul>
                <li>WordPress 6.0 or higher</li>
                <li>WooCommerce 8.0 or higher</li>
                <li>PHP 8.0 or higher</li>
            </ul>
        ';
    }

    /**
     * Get changelog from GitHub release
     *
     * @param object $github_info GitHub release info.
     * @return string
     */
    private function get_changelog( $github_info ) {
        $changelog = '<h4>Changelog</h4>';

        if ( ! empty( $github_info->body ) ) {
            $changelog .= '<h5>' . esc_html( $github_info->tag_name ) . '</h5>';
            $changelog .= wp_kses_post( wpautop( $github_info->body ) );
        }

        return $changelog;
    }

    /**
     * After installation, rename the folder to match plugin slug
     *
     * @param bool  $response   Installation response.
     * @param array $hook_extra Extra hook data.
     * @param array $result     Installation result.
     * @return array
     */
    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        // Only process our plugin
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
            return $result;
        }

        // Get the installed directory
        $install_directory = $result['destination'];
        $plugin_directory = WP_PLUGIN_DIR . '/' . $this->plugin_slug;

        // Move to correct location
        $wp_filesystem->move( $install_directory, $plugin_directory );
        $result['destination'] = $plugin_directory;

        // Activate the plugin
        $activate = activate_plugin( $this->plugin_basename );

        return $result;
    }

    /**
     * Clear transients after update
     *
     * @param WP_Upgrader $upgrader Upgrader object.
     * @param array       $options  Update options.
     */
    public function clear_transients( $upgrader, $options ) {
        if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
            delete_transient( 'jdpd_github_response' );
        }
    }

    /**
     * Force check for updates
     */
    public static function force_check() {
        delete_transient( 'jdpd_github_response' );
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();
    }
}
