<?php
/**
 * Geo-Location Based Pricing
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Jezweb_Dynamic_Pricing/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Geo-Location Based Pricing class.
 *
 * Provides country/region-specific pricing rules
 * with automatic IP-based detection.
 *
 * @since 1.4.0
 */
class JDPD_Geo_Pricing {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Geo_Pricing
     */
    private static $instance = null;

    /**
     * Current customer location.
     *
     * @var array
     */
    private $customer_location;

    /**
     * Geo zones.
     *
     * @var array
     */
    private $zones;

    /**
     * Get single instance.
     *
     * @return JDPD_Geo_Pricing
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
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Detect location early
        add_action( 'wp_loaded', array( $this, 'detect_customer_location' ) );

        // Filter prices based on location
        add_filter( 'jdpd_calculated_price', array( $this, 'apply_geo_pricing' ), 50, 3 );
        add_filter( 'jdpd_rule_conditions', array( $this, 'add_geo_conditions' ) );
        add_filter( 'jdpd_evaluate_condition', array( $this, 'evaluate_geo_condition' ), 10, 3 );

        // Frontend location selector
        add_action( 'wp_footer', array( $this, 'render_location_selector' ) );
        add_action( 'wp_ajax_jdpd_set_location', array( $this, 'ajax_set_location' ) );
        add_action( 'wp_ajax_nopriv_jdpd_set_location', array( $this, 'ajax_set_location' ) );

        // Admin
        add_action( 'wp_ajax_jdpd_save_geo_zone', array( $this, 'ajax_save_geo_zone' ) );
        add_action( 'wp_ajax_jdpd_delete_geo_zone', array( $this, 'ajax_delete_geo_zone' ) );
        add_action( 'wp_ajax_jdpd_get_geo_zones', array( $this, 'ajax_get_geo_zones' ) );
        add_action( 'wp_ajax_jdpd_test_geo_location', array( $this, 'ajax_test_geo_location' ) );
    }

    /**
     * Detect customer location.
     */
    public function detect_customer_location() {
        // Check if manually set via cookie
        if ( isset( $_COOKIE['jdpd_geo_location'] ) ) {
            $this->customer_location = json_decode( stripslashes( $_COOKIE['jdpd_geo_location'] ), true );
            if ( is_array( $this->customer_location ) ) {
                return;
            }
        }

        // Check WooCommerce customer
        if ( is_user_logged_in() && function_exists( 'WC' ) && WC()->customer ) {
            $country = WC()->customer->get_billing_country();
            $state = WC()->customer->get_billing_state();
            $city = WC()->customer->get_billing_city();
            $postcode = WC()->customer->get_billing_postcode();

            if ( $country ) {
                $this->customer_location = array(
                    'country'  => $country,
                    'state'    => $state,
                    'city'     => $city,
                    'postcode' => $postcode,
                    'source'   => 'customer',
                );
                return;
            }
        }

        // Use geolocation
        $geo = $this->geolocate_ip();

        if ( $geo ) {
            $this->customer_location = array(
                'country'   => $geo['country'] ?? '',
                'state'     => $geo['state'] ?? '',
                'city'      => $geo['city'] ?? '',
                'postcode'  => '',
                'continent' => $geo['continent'] ?? '',
                'source'    => 'ip',
            );
        } else {
            // Fallback to store base country
            $this->customer_location = array(
                'country' => WC()->countries->get_base_country(),
                'state'   => WC()->countries->get_base_state(),
                'source'  => 'default',
            );
        }
    }

    /**
     * Geolocate IP address.
     *
     * @param string $ip IP address (optional, uses current if not provided).
     * @return array|false Location data or false.
     */
    public function geolocate_ip( $ip = '' ) {
        if ( empty( $ip ) ) {
            $ip = $this->get_client_ip();
        }

        if ( empty( $ip ) || $this->is_local_ip( $ip ) ) {
            return false;
        }

        // Try WooCommerce geolocation first
        if ( class_exists( 'WC_Geolocation' ) ) {
            $geo = WC_Geolocation::geolocate_ip( $ip );
            if ( ! empty( $geo['country'] ) ) {
                return $geo;
            }
        }

        // Try external service
        $geo = $this->external_geolocate( $ip );

        return $geo;
    }

    /**
     * Get client IP address.
     *
     * @return string IP address.
     */
    private function get_client_ip() {
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        );

        foreach ( $ip_headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( $_SERVER[ $header ] );
                // Handle comma-separated IPs
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '';
    }

    /**
     * Check if IP is local/private.
     *
     * @param string $ip IP address.
     * @return bool Whether local.
     */
    private function is_local_ip( $ip ) {
        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * External geolocation service.
     *
     * @param string $ip IP address.
     * @return array|false Location data.
     */
    private function external_geolocate( $ip ) {
        // Check cache
        $cache_key = 'jdpd_geo_' . md5( $ip );
        $cached = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        // Try ip-api.com (free, no key needed)
        $response = wp_remote_get( "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,region,regionName,city,continent,continentCode", array(
            'timeout' => 5,
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data ) || 'success' !== ( $data['status'] ?? '' ) ) {
            return false;
        }

        $geo = array(
            'country'        => $data['countryCode'] ?? '',
            'country_name'   => $data['country'] ?? '',
            'state'          => $data['region'] ?? '',
            'state_name'     => $data['regionName'] ?? '',
            'city'           => $data['city'] ?? '',
            'continent'      => $data['continentCode'] ?? '',
            'continent_name' => $data['continent'] ?? '',
        );

        // Cache for 24 hours
        set_transient( $cache_key, $geo, DAY_IN_SECONDS );

        return $geo;
    }

    /**
     * Get customer location.
     *
     * @return array Location data.
     */
    public function get_customer_location() {
        if ( empty( $this->customer_location ) ) {
            $this->detect_customer_location();
        }

        return $this->customer_location ?? array();
    }

    /**
     * Get geo pricing zones.
     *
     * @return array Zones.
     */
    public function get_zones() {
        if ( null === $this->zones ) {
            $this->zones = get_option( 'jdpd_geo_zones', array() );
        }

        return $this->zones;
    }

    /**
     * Create a geo zone.
     *
     * @param array $data Zone data.
     * @return string Zone ID.
     */
    public function create_zone( $data ) {
        $zones = $this->get_zones();

        $zone_id = 'zone_' . uniqid();

        $zones[ $zone_id ] = array(
            'id'          => $zone_id,
            'name'        => sanitize_text_field( $data['name'] ?? '' ),
            'countries'   => array_map( 'sanitize_text_field', $data['countries'] ?? array() ),
            'states'      => array_map( 'sanitize_text_field', $data['states'] ?? array() ),
            'cities'      => array_map( 'sanitize_text_field', $data['cities'] ?? array() ),
            'postcodes'   => $this->parse_postcodes( $data['postcodes'] ?? '' ),
            'continents'  => array_map( 'sanitize_text_field', $data['continents'] ?? array() ),
            'pricing_adjustment' => array(
                'type'  => sanitize_key( $data['adjustment_type'] ?? 'percentage' ),
                'value' => floatval( $data['adjustment_value'] ?? 0 ),
            ),
            'enabled'     => ! empty( $data['enabled'] ),
            'priority'    => absint( $data['priority'] ?? 10 ),
            'created_at'  => current_time( 'mysql' ),
        );

        update_option( 'jdpd_geo_zones', $zones );
        $this->zones = $zones;

        return $zone_id;
    }

    /**
     * Update a geo zone.
     *
     * @param string $zone_id Zone ID.
     * @param array  $data Zone data.
     * @return bool Whether updated.
     */
    public function update_zone( $zone_id, $data ) {
        $zones = $this->get_zones();

        if ( ! isset( $zones[ $zone_id ] ) ) {
            return false;
        }

        $zones[ $zone_id ] = array_merge( $zones[ $zone_id ], array(
            'name'        => sanitize_text_field( $data['name'] ?? $zones[ $zone_id ]['name'] ),
            'countries'   => array_map( 'sanitize_text_field', $data['countries'] ?? array() ),
            'states'      => array_map( 'sanitize_text_field', $data['states'] ?? array() ),
            'cities'      => array_map( 'sanitize_text_field', $data['cities'] ?? array() ),
            'postcodes'   => $this->parse_postcodes( $data['postcodes'] ?? '' ),
            'continents'  => array_map( 'sanitize_text_field', $data['continents'] ?? array() ),
            'pricing_adjustment' => array(
                'type'  => sanitize_key( $data['adjustment_type'] ?? 'percentage' ),
                'value' => floatval( $data['adjustment_value'] ?? 0 ),
            ),
            'enabled'     => ! empty( $data['enabled'] ),
            'priority'    => absint( $data['priority'] ?? 10 ),
            'updated_at'  => current_time( 'mysql' ),
        ) );

        update_option( 'jdpd_geo_zones', $zones );
        $this->zones = $zones;

        return true;
    }

    /**
     * Delete a geo zone.
     *
     * @param string $zone_id Zone ID.
     * @return bool Whether deleted.
     */
    public function delete_zone( $zone_id ) {
        $zones = $this->get_zones();

        if ( ! isset( $zones[ $zone_id ] ) ) {
            return false;
        }

        unset( $zones[ $zone_id ] );
        update_option( 'jdpd_geo_zones', $zones );
        $this->zones = $zones;

        return true;
    }

    /**
     * Parse postcodes string to array.
     *
     * @param string $postcodes Postcodes string.
     * @return array Postcodes.
     */
    private function parse_postcodes( $postcodes ) {
        if ( empty( $postcodes ) ) {
            return array();
        }

        // Support ranges like "2000-2999" and wildcards like "20*"
        $codes = array_map( 'trim', explode( "\n", $postcodes ) );
        return array_filter( $codes );
    }

    /**
     * Check if location matches a zone.
     *
     * @param array $location Location data.
     * @param array $zone Zone data.
     * @return bool Whether matches.
     */
    public function location_matches_zone( $location, $zone ) {
        // Check continent
        if ( ! empty( $zone['continents'] ) ) {
            $continent = $location['continent'] ?? '';
            if ( ! in_array( $continent, $zone['continents'], true ) ) {
                return false;
            }
        }

        // Check country
        if ( ! empty( $zone['countries'] ) ) {
            $country = $location['country'] ?? '';
            if ( ! in_array( $country, $zone['countries'], true ) ) {
                return false;
            }
        }

        // Check state
        if ( ! empty( $zone['states'] ) ) {
            $state = $location['state'] ?? '';
            if ( ! in_array( $state, $zone['states'], true ) ) {
                return false;
            }
        }

        // Check city
        if ( ! empty( $zone['cities'] ) ) {
            $city = strtolower( $location['city'] ?? '' );
            $zone_cities = array_map( 'strtolower', $zone['cities'] );
            if ( ! in_array( $city, $zone_cities, true ) ) {
                return false;
            }
        }

        // Check postcode
        if ( ! empty( $zone['postcodes'] ) ) {
            $postcode = $location['postcode'] ?? '';
            if ( ! $this->postcode_matches( $postcode, $zone['postcodes'] ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if postcode matches zone postcodes.
     *
     * @param string $postcode Customer postcode.
     * @param array  $zone_postcodes Zone postcodes.
     * @return bool Whether matches.
     */
    private function postcode_matches( $postcode, $zone_postcodes ) {
        $postcode = strtoupper( trim( $postcode ) );

        foreach ( $zone_postcodes as $zone_postcode ) {
            $zone_postcode = strtoupper( trim( $zone_postcode ) );

            // Exact match
            if ( $postcode === $zone_postcode ) {
                return true;
            }

            // Wildcard match (e.g., "20*")
            if ( strpos( $zone_postcode, '*' ) !== false ) {
                $pattern = '/^' . str_replace( '*', '.*', preg_quote( $zone_postcode, '/' ) ) . '$/';
                if ( preg_match( $pattern, $postcode ) ) {
                    return true;
                }
            }

            // Range match (e.g., "2000-2999" or "2000...2999")
            if ( preg_match( '/^(\d+)\s*[-\.]{1,3}\s*(\d+)$/', $zone_postcode, $matches ) ) {
                $min = intval( $matches[1] );
                $max = intval( $matches[2] );
                $num = intval( preg_replace( '/[^\d]/', '', $postcode ) );

                if ( $num >= $min && $num <= $max ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get matching zone for customer location.
     *
     * @param array $location Location data (optional).
     * @return array|null Matching zone or null.
     */
    public function get_matching_zone( $location = null ) {
        if ( null === $location ) {
            $location = $this->get_customer_location();
        }

        $zones = $this->get_zones();

        // Sort by priority
        uasort( $zones, function( $a, $b ) {
            return ( $a['priority'] ?? 10 ) - ( $b['priority'] ?? 10 );
        } );

        foreach ( $zones as $zone ) {
            if ( empty( $zone['enabled'] ) ) {
                continue;
            }

            if ( $this->location_matches_zone( $location, $zone ) ) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Apply geo pricing to product price.
     *
     * @param float $price Calculated price.
     * @param int   $product_id Product ID.
     * @param array $context Pricing context.
     * @return float Adjusted price.
     */
    public function apply_geo_pricing( $price, $product_id, $context = array() ) {
        $zone = $this->get_matching_zone();

        if ( ! $zone ) {
            return $price;
        }

        $adjustment = $zone['pricing_adjustment'] ?? array();
        $type = $adjustment['type'] ?? 'percentage';
        $value = floatval( $adjustment['value'] ?? 0 );

        if ( 0 === $value ) {
            return $price;
        }

        switch ( $type ) {
            case 'percentage':
                // Positive = increase, Negative = decrease
                $price = $price * ( 1 + ( $value / 100 ) );
                break;

            case 'fixed':
                $price = $price + $value;
                break;

            case 'fixed_price':
                // Override with fixed price
                $price = $value;
                break;

            case 'multiply':
                // Multiply by factor (e.g., 1.2 for 20% increase)
                $price = $price * $value;
                break;
        }

        return max( 0, round( $price, wc_get_price_decimals() ) );
    }

    /**
     * Add geo conditions to rules.
     *
     * @param array $conditions Existing conditions.
     * @return array Modified conditions.
     */
    public function add_geo_conditions( $conditions ) {
        $conditions['geo_country'] = array(
            'label'       => __( 'Customer Country', 'jezweb-dynamic-pricing' ),
            'type'        => 'select',
            'options'     => WC()->countries->get_countries(),
            'multiple'    => true,
            'description' => __( 'Apply rule only for customers from specific countries.', 'jezweb-dynamic-pricing' ),
        );

        $conditions['geo_continent'] = array(
            'label'   => __( 'Customer Continent', 'jezweb-dynamic-pricing' ),
            'type'    => 'select',
            'options' => $this->get_continents(),
            'multiple' => true,
        );

        $conditions['geo_zone'] = array(
            'label'   => __( 'Geo Pricing Zone', 'jezweb-dynamic-pricing' ),
            'type'    => 'select',
            'options' => $this->get_zones_for_select(),
            'multiple' => true,
        );

        return $conditions;
    }

    /**
     * Evaluate geo conditions.
     *
     * @param bool   $result Current result.
     * @param string $type Condition type.
     * @param array  $condition Condition data.
     * @return bool Evaluation result.
     */
    public function evaluate_geo_condition( $result, $type, $condition ) {
        $location = $this->get_customer_location();

        switch ( $type ) {
            case 'geo_country':
                $customer_country = $location['country'] ?? '';
                $target_countries = (array) ( $condition['value'] ?? array() );
                $operator = $condition['operator'] ?? 'in';

                if ( 'in' === $operator ) {
                    return in_array( $customer_country, $target_countries, true );
                } elseif ( 'not_in' === $operator ) {
                    return ! in_array( $customer_country, $target_countries, true );
                }
                break;

            case 'geo_continent':
                $customer_continent = $location['continent'] ?? '';
                $target_continents = (array) ( $condition['value'] ?? array() );
                $operator = $condition['operator'] ?? 'in';

                if ( 'in' === $operator ) {
                    return in_array( $customer_continent, $target_continents, true );
                } elseif ( 'not_in' === $operator ) {
                    return ! in_array( $customer_continent, $target_continents, true );
                }
                break;

            case 'geo_zone':
                $target_zones = (array) ( $condition['value'] ?? array() );
                $matching_zone = $this->get_matching_zone();
                $operator = $condition['operator'] ?? 'in';

                if ( ! $matching_zone ) {
                    return 'not_in' === $operator;
                }

                if ( 'in' === $operator ) {
                    return in_array( $matching_zone['id'], $target_zones, true );
                } elseif ( 'not_in' === $operator ) {
                    return ! in_array( $matching_zone['id'], $target_zones, true );
                }
                break;
        }

        return $result;
    }

    /**
     * Get continents list.
     *
     * @return array Continents.
     */
    public function get_continents() {
        return array(
            'AF' => __( 'Africa', 'jezweb-dynamic-pricing' ),
            'AN' => __( 'Antarctica', 'jezweb-dynamic-pricing' ),
            'AS' => __( 'Asia', 'jezweb-dynamic-pricing' ),
            'EU' => __( 'Europe', 'jezweb-dynamic-pricing' ),
            'NA' => __( 'North America', 'jezweb-dynamic-pricing' ),
            'OC' => __( 'Oceania', 'jezweb-dynamic-pricing' ),
            'SA' => __( 'South America', 'jezweb-dynamic-pricing' ),
        );
    }

    /**
     * Get zones for select dropdown.
     *
     * @return array Zones.
     */
    private function get_zones_for_select() {
        $zones = $this->get_zones();
        $options = array();

        foreach ( $zones as $id => $zone ) {
            $options[ $id ] = $zone['name'];
        }

        return $options;
    }

    /**
     * Render location selector widget.
     */
    public function render_location_selector() {
        if ( ! get_option( 'jdpd_show_location_selector', false ) ) {
            return;
        }

        $location = $this->get_customer_location();
        $countries = WC()->countries->get_countries();
        $current_country = $location['country'] ?? '';

        ?>
        <div id="jdpd-location-selector" class="jdpd-location-selector" style="display:none;">
            <div class="jdpd-location-current">
                <span class="jdpd-location-icon">📍</span>
                <span class="jdpd-location-text">
                    <?php
                    if ( $current_country && isset( $countries[ $current_country ] ) ) {
                        echo esc_html( $countries[ $current_country ] );
                    } else {
                        esc_html_e( 'Select your location', 'jezweb-dynamic-pricing' );
                    }
                    ?>
                </span>
                <span class="jdpd-location-change"><?php esc_html_e( 'Change', 'jezweb-dynamic-pricing' ); ?></span>
            </div>
            <div class="jdpd-location-dropdown" style="display:none;">
                <select id="jdpd-country-select">
                    <option value=""><?php esc_html_e( 'Select country...', 'jezweb-dynamic-pricing' ); ?></option>
                    <?php foreach ( $countries as $code => $name ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_country, $code ); ?>>
                            <?php echo esc_html( $name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var $selector = $('#jdpd-location-selector');

            $selector.show().on('click', '.jdpd-location-change', function() {
                $selector.find('.jdpd-location-dropdown').toggle();
            });

            $selector.on('change', '#jdpd-country-select', function() {
                var country = $(this).val();
                if (!country) return;

                $.ajax({
                    url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                    type: 'POST',
                    data: {
                        action: 'jdpd_set_location',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'jdpd_geo_nonce' ) ); ?>',
                        country: country
                    },
                    success: function() {
                        location.reload();
                    }
                });
            });
        });
        </script>

        <style>
        .jdpd-location-selector {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: #fff;
            padding: 10px 15px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 9999;
            font-size: 14px;
        }
        .jdpd-location-current {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .jdpd-location-change {
            color: #22588d;
            cursor: pointer;
            text-decoration: underline;
        }
        .jdpd-location-dropdown {
            margin-top: 10px;
        }
        .jdpd-location-dropdown select {
            width: 100%;
            padding: 8px;
        }
        </style>
        <?php
    }

    /**
     * AJAX: Set customer location.
     */
    public function ajax_set_location() {
        check_ajax_referer( 'jdpd_geo_nonce', 'nonce' );

        $country = isset( $_POST['country'] ) ? sanitize_text_field( $_POST['country'] ) : '';
        $state = isset( $_POST['state'] ) ? sanitize_text_field( $_POST['state'] ) : '';

        if ( empty( $country ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid country.', 'jezweb-dynamic-pricing' ) ) );
        }

        $location = array(
            'country' => $country,
            'state'   => $state,
            'source'  => 'manual',
        );

        // Set cookie for 30 days
        setcookie(
            'jdpd_geo_location',
            wp_json_encode( $location ),
            time() + ( 30 * DAY_IN_SECONDS ),
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );

        // Update WooCommerce customer if logged in
        if ( is_user_logged_in() && WC()->customer ) {
            WC()->customer->set_billing_country( $country );
            if ( $state ) {
                WC()->customer->set_billing_state( $state );
            }
        }

        wp_send_json_success( array( 'message' => __( 'Location updated.', 'jezweb-dynamic-pricing' ) ) );
    }

    /**
     * AJAX: Save geo zone.
     */
    public function ajax_save_geo_zone() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $zone_id = isset( $_POST['zone_id'] ) ? sanitize_text_field( $_POST['zone_id'] ) : '';
        $data = isset( $_POST['zone'] ) ? wp_unslash( $_POST['zone'] ) : array();

        if ( $zone_id ) {
            $result = $this->update_zone( $zone_id, $data );
            if ( ! $result ) {
                wp_send_json_error( array( 'message' => __( 'Zone not found.', 'jezweb-dynamic-pricing' ) ) );
            }
        } else {
            $zone_id = $this->create_zone( $data );
        }

        wp_send_json_success( array(
            'zone_id' => $zone_id,
            'message' => __( 'Zone saved successfully.', 'jezweb-dynamic-pricing' ),
        ) );
    }

    /**
     * AJAX: Delete geo zone.
     */
    public function ajax_delete_geo_zone() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $zone_id = isset( $_POST['zone_id'] ) ? sanitize_text_field( $_POST['zone_id'] ) : '';

        if ( ! $zone_id || ! $this->delete_zone( $zone_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not delete zone.', 'jezweb-dynamic-pricing' ) ) );
        }

        wp_send_json_success( array( 'message' => __( 'Zone deleted.', 'jezweb-dynamic-pricing' ) ) );
    }

    /**
     * AJAX: Get all geo zones.
     */
    public function ajax_get_geo_zones() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        wp_send_json_success( array( 'zones' => $this->get_zones() ) );
    }

    /**
     * AJAX: Test geo location for an IP.
     */
    public function ajax_test_geo_location() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $ip = isset( $_POST['ip'] ) ? sanitize_text_field( $_POST['ip'] ) : '';

        if ( empty( $ip ) ) {
            $ip = $this->get_client_ip();
        }

        $geo = $this->geolocate_ip( $ip );
        $matching_zone = $geo ? $this->get_matching_zone( $geo ) : null;

        wp_send_json_success( array(
            'ip'            => $ip,
            'location'      => $geo,
            'matching_zone' => $matching_zone,
        ) );
    }
}
