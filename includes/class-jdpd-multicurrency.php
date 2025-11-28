<?php
/**
 * Multi-language and Multi-currency Support
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Jezweb_Dynamic_Pricing/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Multi-language and Multi-currency Support class.
 *
 * Provides integration with popular multi-language and multi-currency
 * plugins like WPML, Polylang, WooCommerce Multicurrency, etc.
 *
 * @since 1.3.0
 */
class JDPD_Multicurrency {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Multicurrency
     */
    private static $instance = null;

    /**
     * Current currency code.
     *
     * @var string
     */
    private $current_currency;

    /**
     * Base currency code.
     *
     * @var string
     */
    private $base_currency;

    /**
     * Exchange rates.
     *
     * @var array
     */
    private $exchange_rates = array();

    /**
     * Detected multi-currency plugin.
     *
     * @var string
     */
    private $multicurrency_plugin;

    /**
     * Detected multi-language plugin.
     *
     * @var string
     */
    private $multilang_plugin;

    /**
     * Get single instance.
     *
     * @return JDPD_Multicurrency
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
        $this->detect_plugins();
        $this->init_hooks();
        $this->load_exchange_rates();
    }

    /**
     * Detect active multi-currency and multi-language plugins.
     */
    private function detect_plugins() {
        // Multi-currency plugins
        if ( class_exists( 'WOOMULTI_CURRENCY' ) ) {
            $this->multicurrency_plugin = 'woocommerce-multi-currency';
        } elseif ( class_exists( 'WCML_Multi_Currency' ) ) {
            $this->multicurrency_plugin = 'wcml';
        } elseif ( class_exists( 'WC_Aelia_CurrencySwitcher' ) ) {
            $this->multicurrency_plugin = 'aelia';
        } elseif ( function_exists( 'woocs_get_actual_currency' ) ) {
            $this->multicurrency_plugin = 'woocs';
        } elseif ( class_exists( 'WC_Product_Price_Based_Country' ) ) {
            $this->multicurrency_plugin = 'price-based-country';
        } else {
            $this->multicurrency_plugin = 'native';
        }

        // Multi-language plugins
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $this->multilang_plugin = 'wpml';
        } elseif ( function_exists( 'pll_current_language' ) ) {
            $this->multilang_plugin = 'polylang';
        } elseif ( function_exists( 'trp_translate' ) ) {
            $this->multilang_plugin = 'translatepress';
        } elseif ( class_exists( 'Weglot' ) ) {
            $this->multilang_plugin = 'weglot';
        } else {
            $this->multilang_plugin = 'none';
        }
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Currency conversion for prices
        add_filter( 'jdpd_convert_price', array( $this, 'convert_price' ), 10, 3 );
        add_filter( 'jdpd_get_display_price', array( $this, 'get_display_price' ), 10, 2 );

        // Rule conditions for currency/language
        add_filter( 'jdpd_rule_conditions', array( $this, 'add_currency_conditions' ) );
        add_filter( 'jdpd_evaluate_condition', array( $this, 'evaluate_currency_condition' ), 10, 3 );

        // Translatable rule fields
        add_filter( 'jdpd_translatable_fields', array( $this, 'get_translatable_fields' ) );

        // Currency-specific rules
        add_filter( 'jdpd_apply_rule', array( $this, 'filter_rule_by_currency' ), 10, 3 );

        // Admin hooks
        add_action( 'wp_ajax_jdpd_get_currencies', array( $this, 'ajax_get_currencies' ) );
        add_action( 'wp_ajax_jdpd_update_exchange_rates', array( $this, 'ajax_update_exchange_rates' ) );
        add_action( 'wp_ajax_jdpd_translate_rule', array( $this, 'ajax_translate_rule' ) );

        // WPML integration
        if ( 'wpml' === $this->multilang_plugin ) {
            add_action( 'wpml_register_single_string', array( $this, 'register_rule_strings' ) );
            add_filter( 'jdpd_get_rule_name', array( $this, 'translate_rule_name' ), 10, 2 );
            add_filter( 'jdpd_get_rule_description', array( $this, 'translate_rule_description' ), 10, 2 );
        }

        // Polylang integration
        if ( 'polylang' === $this->multilang_plugin ) {
            add_action( 'pll_init', array( $this, 'register_polylang_strings' ) );
        }

        // Exchange rate updates
        add_action( 'jdpd_update_exchange_rates', array( $this, 'fetch_exchange_rates' ) );

        // Schedule exchange rate updates
        if ( ! wp_next_scheduled( 'jdpd_update_exchange_rates' ) ) {
            wp_schedule_event( time(), 'daily', 'jdpd_update_exchange_rates' );
        }
    }

    /**
     * Load exchange rates.
     */
    private function load_exchange_rates() {
        $this->base_currency = get_woocommerce_currency();
        $this->current_currency = $this->get_current_currency();

        $saved_rates = get_option( 'jdpd_exchange_rates', array() );

        if ( ! empty( $saved_rates ) ) {
            $this->exchange_rates = $saved_rates;
        } else {
            // Default rates (should be updated via API)
            $this->exchange_rates = array(
                'USD' => 1.0,
                'EUR' => 0.85,
                'GBP' => 0.73,
                'AUD' => 1.35,
                'CAD' => 1.25,
                'JPY' => 110.0,
                'NZD' => 1.42,
                'CHF' => 0.92,
                'CNY' => 6.45,
                'INR' => 74.5,
            );
        }
    }

    /**
     * Get current currency.
     *
     * @return string Currency code.
     */
    public function get_current_currency() {
        switch ( $this->multicurrency_plugin ) {
            case 'woocommerce-multi-currency':
                if ( class_exists( 'WOOMULTI_CURRENCY_F_Data' ) ) {
                    $data = WOOMULTI_CURRENCY_F_Data::get_instance();
                    return $data->get_current_currency();
                }
                break;

            case 'wcml':
                global $woocommerce_wpml;
                if ( $woocommerce_wpml && isset( $woocommerce_wpml->multi_currency ) ) {
                    return $woocommerce_wpml->multi_currency->get_client_currency();
                }
                break;

            case 'aelia':
                if ( class_exists( 'WC_Aelia_CurrencySwitcher' ) ) {
                    return WC_Aelia_CurrencySwitcher::instance()->get_selected_currency();
                }
                break;

            case 'woocs':
                if ( function_exists( 'woocs_get_actual_currency' ) ) {
                    return woocs_get_actual_currency();
                }
                break;

            case 'price-based-country':
                if ( function_exists( 'wcpbc_the_zone' ) ) {
                    $zone = wcpbc_the_zone();
                    if ( $zone ) {
                        return $zone->get_currency();
                    }
                }
                break;
        }

        return get_woocommerce_currency();
    }

    /**
     * Get current language.
     *
     * @return string Language code.
     */
    public function get_current_language() {
        switch ( $this->multilang_plugin ) {
            case 'wpml':
                return apply_filters( 'wpml_current_language', null );

            case 'polylang':
                return pll_current_language();

            case 'translatepress':
                global $TRP_LANGUAGE;
                return $TRP_LANGUAGE ?? get_locale();

            case 'weglot':
                if ( function_exists( 'weglot_get_current_language' ) ) {
                    return weglot_get_current_language();
                }
                break;
        }

        return get_locale();
    }

    /**
     * Convert price to current currency.
     *
     * @param float  $price Price in base currency.
     * @param string $from_currency Source currency (optional).
     * @param string $to_currency Target currency (optional).
     * @return float Converted price.
     */
    public function convert_price( $price, $from_currency = null, $to_currency = null ) {
        if ( null === $from_currency ) {
            $from_currency = $this->base_currency;
        }

        if ( null === $to_currency ) {
            $to_currency = $this->current_currency;
        }

        if ( $from_currency === $to_currency ) {
            return $price;
        }

        // Try plugin-specific conversion first
        $converted = $this->plugin_convert_price( $price, $from_currency, $to_currency );
        if ( false !== $converted ) {
            return $converted;
        }

        // Use our exchange rates
        $rate = $this->get_exchange_rate( $from_currency, $to_currency );

        return round( $price * $rate, wc_get_price_decimals() );
    }

    /**
     * Use plugin-specific price conversion.
     *
     * @param float  $price Price.
     * @param string $from_currency Source currency.
     * @param string $to_currency Target currency.
     * @return float|false Converted price or false.
     */
    private function plugin_convert_price( $price, $from_currency, $to_currency ) {
        switch ( $this->multicurrency_plugin ) {
            case 'woocommerce-multi-currency':
                if ( class_exists( 'WOOMULTI_CURRENCY_F_Data' ) ) {
                    $data = WOOMULTI_CURRENCY_F_Data::get_instance();
                    return $data->convert_price( $price, $from_currency, $to_currency );
                }
                break;

            case 'wcml':
                global $woocommerce_wpml;
                if ( $woocommerce_wpml && isset( $woocommerce_wpml->multi_currency ) ) {
                    return $woocommerce_wpml->multi_currency->prices->convert_price_amount( $price, $to_currency );
                }
                break;

            case 'aelia':
                if ( class_exists( 'WC_Aelia_CurrencySwitcher' ) ) {
                    return WC_Aelia_CurrencySwitcher::instance()->convert( $price, $from_currency, $to_currency );
                }
                break;

            case 'woocs':
                global $WOOCS;
                if ( $WOOCS ) {
                    return $WOOCS->woocs_exchange_value( $price );
                }
                break;
        }

        return false;
    }

    /**
     * Get exchange rate between two currencies.
     *
     * @param string $from_currency Source currency.
     * @param string $to_currency Target currency.
     * @return float Exchange rate.
     */
    public function get_exchange_rate( $from_currency, $to_currency ) {
        if ( $from_currency === $to_currency ) {
            return 1.0;
        }

        // Convert through USD as base
        $from_rate = $this->exchange_rates[ $from_currency ] ?? 1.0;
        $to_rate = $this->exchange_rates[ $to_currency ] ?? 1.0;

        if ( $from_rate > 0 ) {
            return $to_rate / $from_rate;
        }

        return 1.0;
    }

    /**
     * Get display price with currency symbol.
     *
     * @param float  $price Price.
     * @param string $currency Currency code (optional).
     * @return string Formatted price.
     */
    public function get_display_price( $price, $currency = null ) {
        if ( null === $currency ) {
            $currency = $this->current_currency;
        }

        // Let WooCommerce handle the formatting
        add_filter( 'woocommerce_currency', function() use ( $currency ) {
            return $currency;
        } );

        $formatted = wc_price( $price );

        remove_all_filters( 'woocommerce_currency' );

        return $formatted;
    }

    /**
     * Add currency/language conditions to rules.
     *
     * @param array $conditions Existing conditions.
     * @return array Modified conditions.
     */
    public function add_currency_conditions( $conditions ) {
        $conditions['currency'] = array(
            'label'       => __( 'Currency', 'jezweb-dynamic-pricing' ),
            'type'        => 'select',
            'options'     => $this->get_available_currencies(),
            'description' => __( 'Apply rule only for specific currencies.', 'jezweb-dynamic-pricing' ),
        );

        $conditions['language'] = array(
            'label'       => __( 'Language', 'jezweb-dynamic-pricing' ),
            'type'        => 'select',
            'options'     => $this->get_available_languages(),
            'description' => __( 'Apply rule only for specific languages.', 'jezweb-dynamic-pricing' ),
        );

        $conditions['country'] = array(
            'label'       => __( 'Billing Country', 'jezweb-dynamic-pricing' ),
            'type'        => 'select',
            'options'     => WC()->countries->get_countries(),
            'description' => __( 'Apply rule only for customers from specific countries.', 'jezweb-dynamic-pricing' ),
        );

        return $conditions;
    }

    /**
     * Evaluate currency/language conditions.
     *
     * @param bool   $result Current result.
     * @param string $type Condition type.
     * @param array  $condition Condition data.
     * @return bool Evaluation result.
     */
    public function evaluate_currency_condition( $result, $type, $condition ) {
        switch ( $type ) {
            case 'currency':
                $current = $this->get_current_currency();
                $target = $condition['value'] ?? '';
                $operator = $condition['operator'] ?? 'equals';

                if ( 'equals' === $operator ) {
                    return $current === $target;
                } elseif ( 'not_equals' === $operator ) {
                    return $current !== $target;
                } elseif ( 'in' === $operator && is_array( $target ) ) {
                    return in_array( $current, $target, true );
                }
                break;

            case 'language':
                $current = $this->get_current_language();
                $target = $condition['value'] ?? '';
                $operator = $condition['operator'] ?? 'equals';

                if ( 'equals' === $operator ) {
                    return $current === $target;
                } elseif ( 'not_equals' === $operator ) {
                    return $current !== $target;
                } elseif ( 'in' === $operator && is_array( $target ) ) {
                    return in_array( $current, $target, true );
                }
                break;

            case 'country':
                $customer_country = $this->get_customer_country();
                $target = $condition['value'] ?? '';
                $operator = $condition['operator'] ?? 'equals';

                if ( 'equals' === $operator ) {
                    return $customer_country === $target;
                } elseif ( 'not_equals' === $operator ) {
                    return $customer_country !== $target;
                } elseif ( 'in' === $operator && is_array( $target ) ) {
                    return in_array( $customer_country, $target, true );
                }
                break;
        }

        return $result;
    }

    /**
     * Get customer's country.
     *
     * @return string Country code.
     */
    private function get_customer_country() {
        if ( is_user_logged_in() ) {
            $customer = new WC_Customer( get_current_user_id() );
            $country = $customer->get_billing_country();

            if ( $country ) {
                return $country;
            }
        }

        // Try geolocation
        if ( class_exists( 'WC_Geolocation' ) ) {
            $geo = WC_Geolocation::geolocate_ip();
            if ( ! empty( $geo['country'] ) ) {
                return $geo['country'];
            }
        }

        return WC()->countries->get_base_country();
    }

    /**
     * Filter rules by currency.
     *
     * @param bool   $apply Whether to apply the rule.
     * @param array  $rule Rule data.
     * @param string $rule_id Rule ID.
     * @return bool Whether to apply the rule.
     */
    public function filter_rule_by_currency( $apply, $rule, $rule_id ) {
        if ( ! $apply ) {
            return false;
        }

        // Check currency restriction
        if ( ! empty( $rule['currencies'] ) ) {
            $current = $this->get_current_currency();
            if ( ! in_array( $current, $rule['currencies'], true ) ) {
                return false;
            }
        }

        // Check language restriction
        if ( ! empty( $rule['languages'] ) ) {
            $current = $this->get_current_language();
            if ( ! in_array( $current, $rule['languages'], true ) ) {
                return false;
            }
        }

        return $apply;
    }

    /**
     * Get available currencies.
     *
     * @return array Currencies.
     */
    public function get_available_currencies() {
        $currencies = array();

        switch ( $this->multicurrency_plugin ) {
            case 'woocommerce-multi-currency':
                if ( class_exists( 'WOOMULTI_CURRENCY_F_Data' ) ) {
                    $data = WOOMULTI_CURRENCY_F_Data::get_instance();
                    $settings = $data->get_list_currencies();
                    foreach ( $settings as $code => $currency ) {
                        $currencies[ $code ] = $code . ' - ' . get_woocommerce_currency_symbol( $code );
                    }
                }
                break;

            case 'wcml':
                global $woocommerce_wpml;
                if ( $woocommerce_wpml && method_exists( $woocommerce_wpml, 'get_currencies' ) ) {
                    $wcml_currencies = $woocommerce_wpml->get_currencies();
                    foreach ( $wcml_currencies as $code => $currency ) {
                        $currencies[ $code ] = $code . ' - ' . get_woocommerce_currency_symbol( $code );
                    }
                }
                break;

            default:
                // Return all WooCommerce currencies
                foreach ( get_woocommerce_currencies() as $code => $name ) {
                    $currencies[ $code ] = $code . ' - ' . $name;
                }
        }

        if ( empty( $currencies ) ) {
            $currencies[ get_woocommerce_currency() ] = get_woocommerce_currency() . ' - ' . get_woocommerce_currency_symbol();
        }

        return $currencies;
    }

    /**
     * Get available languages.
     *
     * @return array Languages.
     */
    public function get_available_languages() {
        $languages = array();

        switch ( $this->multilang_plugin ) {
            case 'wpml':
                $wpml_languages = apply_filters( 'wpml_active_languages', null );
                if ( is_array( $wpml_languages ) ) {
                    foreach ( $wpml_languages as $lang ) {
                        $languages[ $lang['language_code'] ] = $lang['native_name'];
                    }
                }
                break;

            case 'polylang':
                if ( function_exists( 'pll_languages_list' ) ) {
                    $pll_languages = pll_languages_list( array( 'fields' => array() ) );
                    foreach ( $pll_languages as $lang ) {
                        $languages[ $lang->slug ] = $lang->name;
                    }
                }
                break;

            case 'translatepress':
                $settings = get_option( 'trp_settings' );
                if ( ! empty( $settings['translation-languages'] ) ) {
                    foreach ( $settings['translation-languages'] as $lang_code ) {
                        $languages[ $lang_code ] = locale_get_display_language( $lang_code );
                    }
                }
                break;

            default:
                $languages[ get_locale() ] = locale_get_display_language( get_locale() );
        }

        return $languages;
    }

    /**
     * Get translatable fields for rules.
     *
     * @return array Translatable field names.
     */
    public function get_translatable_fields() {
        return array(
            'name',
            'description',
            'badge_text',
            'message',
            'upsell_message',
        );
    }

    /**
     * Register rule strings for WPML.
     */
    public function register_rule_strings() {
        $rules = get_option( 'jdpd_rules', array() );
        $translatable = $this->get_translatable_fields();

        foreach ( $rules as $rule_id => $rule ) {
            foreach ( $translatable as $field ) {
                if ( ! empty( $rule[ $field ] ) ) {
                    do_action(
                        'wpml_register_single_string',
                        'jdpd-pricing-rules',
                        "rule_{$rule_id}_{$field}",
                        $rule[ $field ]
                    );
                }
            }
        }
    }

    /**
     * Translate rule name via WPML.
     *
     * @param string $name Rule name.
     * @param string $rule_id Rule ID.
     * @return string Translated name.
     */
    public function translate_rule_name( $name, $rule_id ) {
        if ( 'wpml' === $this->multilang_plugin ) {
            return apply_filters(
                'wpml_translate_single_string',
                $name,
                'jdpd-pricing-rules',
                "rule_{$rule_id}_name"
            );
        }

        return $name;
    }

    /**
     * Translate rule description via WPML.
     *
     * @param string $description Rule description.
     * @param string $rule_id Rule ID.
     * @return string Translated description.
     */
    public function translate_rule_description( $description, $rule_id ) {
        if ( 'wpml' === $this->multilang_plugin ) {
            return apply_filters(
                'wpml_translate_single_string',
                $description,
                'jdpd-pricing-rules',
                "rule_{$rule_id}_description"
            );
        }

        return $description;
    }

    /**
     * Register strings for Polylang.
     */
    public function register_polylang_strings() {
        if ( ! function_exists( 'pll_register_string' ) ) {
            return;
        }

        $rules = get_option( 'jdpd_rules', array() );
        $translatable = $this->get_translatable_fields();

        foreach ( $rules as $rule_id => $rule ) {
            foreach ( $translatable as $field ) {
                if ( ! empty( $rule[ $field ] ) ) {
                    pll_register_string(
                        "rule_{$rule_id}_{$field}",
                        $rule[ $field ],
                        'JDPD Pricing Rules'
                    );
                }
            }
        }
    }

    /**
     * Fetch exchange rates from API.
     */
    public function fetch_exchange_rates() {
        $api_key = get_option( 'jdpd_exchange_rate_api_key', '' );
        $api_source = get_option( 'jdpd_exchange_rate_source', 'exchangerate-api' );

        $base = get_woocommerce_currency();
        $rates = array();

        switch ( $api_source ) {
            case 'exchangerate-api':
                $url = "https://api.exchangerate-api.com/v4/latest/{$base}";
                break;

            case 'openexchangerates':
                if ( empty( $api_key ) ) {
                    return;
                }
                $url = "https://openexchangerates.org/api/latest.json?app_id={$api_key}&base={$base}";
                break;

            case 'fixer':
                if ( empty( $api_key ) ) {
                    return;
                }
                $url = "http://data.fixer.io/api/latest?access_key={$api_key}&base={$base}";
                break;

            default:
                return;
        }

        $response = wp_remote_get( $url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $response ) ) {
            return;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! empty( $data['rates'] ) ) {
            $rates = $data['rates'];
        } elseif ( ! empty( $data['conversion_rates'] ) ) {
            $rates = $data['conversion_rates'];
        }

        if ( ! empty( $rates ) ) {
            $this->exchange_rates = $rates;
            update_option( 'jdpd_exchange_rates', $rates );
            update_option( 'jdpd_exchange_rates_updated', current_time( 'mysql' ) );
        }
    }

    /**
     * Get currency symbol.
     *
     * @param string $currency Currency code.
     * @return string Currency symbol.
     */
    public function get_currency_symbol( $currency = null ) {
        if ( null === $currency ) {
            $currency = $this->current_currency;
        }

        return get_woocommerce_currency_symbol( $currency );
    }

    /**
     * Format price for specific currency.
     *
     * @param float  $price Price.
     * @param string $currency Currency code.
     * @return string Formatted price.
     */
    public function format_price( $price, $currency = null ) {
        if ( null === $currency ) {
            $currency = $this->current_currency;
        }

        $symbol = $this->get_currency_symbol( $currency );
        $decimals = wc_get_price_decimals();
        $decimal_sep = wc_get_price_decimal_separator();
        $thousand_sep = wc_get_price_thousand_separator();

        $formatted = number_format( $price, $decimals, $decimal_sep, $thousand_sep );

        $format = get_option( 'woocommerce_currency_pos', 'left' );

        switch ( $format ) {
            case 'left':
                return $symbol . $formatted;
            case 'right':
                return $formatted . $symbol;
            case 'left_space':
                return $symbol . ' ' . $formatted;
            case 'right_space':
                return $formatted . ' ' . $symbol;
        }

        return $symbol . $formatted;
    }

    /**
     * AJAX: Get currencies.
     */
    public function ajax_get_currencies() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        wp_send_json_success( array(
            'currencies'      => $this->get_available_currencies(),
            'current'         => $this->get_current_currency(),
            'base'            => $this->base_currency,
            'exchange_rates'  => $this->exchange_rates,
            'last_updated'    => get_option( 'jdpd_exchange_rates_updated', '' ),
            'plugin_detected' => $this->multicurrency_plugin,
        ) );
    }

    /**
     * AJAX: Update exchange rates.
     */
    public function ajax_update_exchange_rates() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $this->fetch_exchange_rates();

        wp_send_json_success( array(
            'message'        => __( 'Exchange rates updated.', 'jezweb-dynamic-pricing' ),
            'exchange_rates' => $this->exchange_rates,
            'last_updated'   => get_option( 'jdpd_exchange_rates_updated', '' ),
        ) );
    }

    /**
     * AJAX: Translate rule.
     */
    public function ajax_translate_rule() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $rule_id = isset( $_POST['rule_id'] ) ? sanitize_text_field( $_POST['rule_id'] ) : '';
        $language = isset( $_POST['language'] ) ? sanitize_text_field( $_POST['language'] ) : '';
        $translations = isset( $_POST['translations'] ) ? wp_unslash( $_POST['translations'] ) : array();

        if ( empty( $rule_id ) || empty( $language ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'jezweb-dynamic-pricing' ) ) );
        }

        // Store translations
        $all_translations = get_option( 'jdpd_rule_translations', array() );

        if ( ! isset( $all_translations[ $rule_id ] ) ) {
            $all_translations[ $rule_id ] = array();
        }

        $all_translations[ $rule_id ][ $language ] = array_map( 'sanitize_text_field', $translations );

        update_option( 'jdpd_rule_translations', $all_translations );

        wp_send_json_success( array(
            'message' => __( 'Translations saved.', 'jezweb-dynamic-pricing' ),
        ) );
    }

    /**
     * Get rule translation.
     *
     * @param string $rule_id Rule ID.
     * @param string $field Field name.
     * @param string $default Default value.
     * @return string Translated value.
     */
    public function get_rule_translation( $rule_id, $field, $default = '' ) {
        $language = $this->get_current_language();
        $translations = get_option( 'jdpd_rule_translations', array() );

        if ( isset( $translations[ $rule_id ][ $language ][ $field ] ) ) {
            return $translations[ $rule_id ][ $language ][ $field ];
        }

        return $default;
    }

    /**
     * Get detected plugins info.
     *
     * @return array Plugin info.
     */
    public function get_detected_plugins() {
        return array(
            'multicurrency' => array(
                'slug' => $this->multicurrency_plugin,
                'name' => $this->get_plugin_name( $this->multicurrency_plugin, 'currency' ),
            ),
            'multilanguage' => array(
                'slug' => $this->multilang_plugin,
                'name' => $this->get_plugin_name( $this->multilang_plugin, 'language' ),
            ),
        );
    }

    /**
     * Get plugin display name.
     *
     * @param string $slug Plugin slug.
     * @param string $type Plugin type.
     * @return string Plugin name.
     */
    private function get_plugin_name( $slug, $type ) {
        $names = array(
            'currency' => array(
                'woocommerce-multi-currency' => 'WooCommerce Multi Currency',
                'wcml'                       => 'WPML WooCommerce Multilingual',
                'aelia'                      => 'Aelia Currency Switcher',
                'woocs'                      => 'WOOCS - WooCommerce Currency Switcher',
                'price-based-country'        => 'Price Based on Country',
                'native'                     => __( 'Native WooCommerce', 'jezweb-dynamic-pricing' ),
            ),
            'language' => array(
                'wpml'         => 'WPML',
                'polylang'     => 'Polylang',
                'translatepress' => 'TranslatePress',
                'weglot'       => 'Weglot',
                'none'         => __( 'None detected', 'jezweb-dynamic-pricing' ),
            ),
        );

        return $names[ $type ][ $slug ] ?? $slug;
    }
}
