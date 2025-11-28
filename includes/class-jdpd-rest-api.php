<?php
/**
 * REST API Class
 *
 * Provides REST API endpoints for managing rules and discounts.
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JDPD_REST_API Class
 */
class JDPD_REST_API {

    /**
     * API namespace
     *
     * @var string
     */
    private $namespace = 'jdpd/v1';

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST routes
     */
    public function register_routes() {
        // Rules endpoints
        register_rest_route( $this->namespace, '/rules', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_rules' ),
                'permission_callback' => array( $this, 'check_read_permission' ),
                'args'                => $this->get_collection_params(),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_rule' ),
                'permission_callback' => array( $this, 'check_write_permission' ),
                'args'                => $this->get_rule_schema(),
            ),
        ) );

        register_rest_route( $this->namespace, '/rules/(?P<id>[\d]+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_rule' ),
                'permission_callback' => array( $this, 'check_read_permission' ),
                'args'                => array(
                    'id' => array(
                        'validate_callback' => function( $param ) {
                            return is_numeric( $param );
                        },
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array( $this, 'update_rule' ),
                'permission_callback' => array( $this, 'check_write_permission' ),
                'args'                => $this->get_rule_schema(),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_rule' ),
                'permission_callback' => array( $this, 'check_write_permission' ),
            ),
        ) );

        // Calculate discount endpoint
        register_rest_route( $this->namespace, '/calculate', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'calculate_discount' ),
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => array(
                'product_id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && $param > 0;
                    },
                ),
                'quantity' => array(
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 1,
                ),
                'user_id' => array(
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 0,
                ),
            ),
        ) );

        // Analytics endpoint
        register_rest_route( $this->namespace, '/analytics', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_analytics' ),
            'permission_callback' => array( $this, 'check_read_permission' ),
            'args'                => array(
                'date_from' => array(
                    'required' => false,
                    'type'     => 'string',
                    'default'  => date( 'Y-m-d', strtotime( '-30 days' ) ),
                ),
                'date_to' => array(
                    'required' => false,
                    'type'     => 'string',
                    'default'  => date( 'Y-m-d' ),
                ),
                'rule_id' => array(
                    'required' => false,
                    'type'     => 'integer',
                ),
            ),
        ) );

        // Import/Export endpoints
        register_rest_route( $this->namespace, '/export', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'export_rules' ),
            'permission_callback' => array( $this, 'check_read_permission' ),
            'args'                => array(
                'rule_ids' => array(
                    'required' => false,
                    'type'     => 'array',
                    'items'    => array( 'type' => 'integer' ),
                ),
            ),
        ) );

        register_rest_route( $this->namespace, '/import', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'import_rules' ),
            'permission_callback' => array( $this, 'check_write_permission' ),
            'args'                => array(
                'rules' => array(
                    'required' => true,
                    'type'     => 'string',
                ),
                'overwrite' => array(
                    'required' => false,
                    'type'     => 'boolean',
                    'default'  => false,
                ),
            ),
        ) );

        // Templates endpoint
        register_rest_route( $this->namespace, '/templates', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_templates' ),
            'permission_callback' => array( $this, 'check_read_permission' ),
        ) );

        register_rest_route( $this->namespace, '/templates/(?P<key>[a-z_]+)/create', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'create_from_template' ),
            'permission_callback' => array( $this, 'check_write_permission' ),
        ) );

        // Batch operations
        register_rest_route( $this->namespace, '/batch', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'batch_operations' ),
            'permission_callback' => array( $this, 'check_write_permission' ),
            'args'                => array(
                'create' => array(
                    'required' => false,
                    'type'     => 'array',
                ),
                'update' => array(
                    'required' => false,
                    'type'     => 'array',
                ),
                'delete' => array(
                    'required' => false,
                    'type'     => 'array',
                ),
            ),
        ) );
    }

    /**
     * Check read permission
     *
     * @return bool
     */
    public function check_read_permission() {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Check write permission
     *
     * @return bool
     */
    public function check_write_permission() {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Get collection params
     *
     * @return array
     */
    private function get_collection_params() {
        return array(
            'page' => array(
                'default'           => 1,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
            'per_page' => array(
                'default'           => 20,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
            'status' => array(
                'default' => '',
                'type'    => 'string',
                'enum'    => array( '', 'active', 'inactive' ),
            ),
            'rule_type' => array(
                'default' => '',
                'type'    => 'string',
            ),
            'search' => array(
                'default' => '',
                'type'    => 'string',
            ),
        );
    }

    /**
     * Get rule schema
     *
     * @return array
     */
    private function get_rule_schema() {
        return array(
            'name' => array(
                'required' => true,
                'type'     => 'string',
            ),
            'rule_type' => array(
                'required' => true,
                'type'     => 'string',
                'enum'     => array_keys( jdpd_get_rule_types() ),
            ),
            'status' => array(
                'required' => false,
                'type'     => 'string',
                'default'  => 'active',
                'enum'     => array( 'active', 'inactive' ),
            ),
            'priority' => array(
                'required' => false,
                'type'     => 'integer',
                'default'  => 10,
            ),
            'discount_type' => array(
                'required' => true,
                'type'     => 'string',
                'enum'     => array_keys( jdpd_get_discount_types() ),
            ),
            'discount_value' => array(
                'required' => true,
                'type'     => 'number',
            ),
            'apply_to' => array(
                'required' => false,
                'type'     => 'string',
                'default'  => 'all_products',
            ),
            'conditions' => array(
                'required' => false,
                'type'     => 'array',
                'default'  => array(),
            ),
            'quantity_ranges' => array(
                'required' => false,
                'type'     => 'array',
                'default'  => array(),
            ),
        );
    }

    /**
     * Get rules
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_rules( $request ) {
        $result = JDPD_Admin_Rules::get_rules( array(
            'page'      => $request->get_param( 'page' ),
            'per_page'  => $request->get_param( 'per_page' ),
            'status'    => $request->get_param( 'status' ),
            'rule_type' => $request->get_param( 'rule_type' ),
            'search'    => $request->get_param( 'search' ),
        ) );

        $rules_data = array();
        foreach ( $result['rules'] as $rule ) {
            $rules_data[] = $this->prepare_rule_response( $rule );
        }

        $response = rest_ensure_response( $rules_data );
        $response->header( 'X-WP-Total', $result['total'] );
        $response->header( 'X-WP-TotalPages', $result['total_pages'] );

        return $response;
    }

    /**
     * Get single rule
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_rule( $request ) {
        $rule_id = $request->get_param( 'id' );
        $rule = new JDPD_Rule( $rule_id );

        if ( ! $rule->get_id() ) {
            return new WP_Error( 'not_found', __( 'Rule not found.', 'jezweb-dynamic-pricing' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $this->prepare_rule_response( $rule ) );
    }

    /**
     * Create rule
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function create_rule( $request ) {
        $rule = new JDPD_Rule();

        $rule->set_multiple( array(
            'name'           => sanitize_text_field( $request->get_param( 'name' ) ),
            'rule_type'      => sanitize_key( $request->get_param( 'rule_type' ) ),
            'status'         => sanitize_key( $request->get_param( 'status' ) ),
            'priority'       => absint( $request->get_param( 'priority' ) ),
            'discount_type'  => sanitize_key( $request->get_param( 'discount_type' ) ),
            'discount_value' => floatval( $request->get_param( 'discount_value' ) ),
            'apply_to'       => sanitize_key( $request->get_param( 'apply_to' ) ),
            'conditions'     => $request->get_param( 'conditions' ),
        ) );

        $rule->save();

        if ( ! $rule->get_id() ) {
            return new WP_Error( 'create_failed', __( 'Failed to create rule.', 'jezweb-dynamic-pricing' ), array( 'status' => 500 ) );
        }

        // Handle quantity ranges
        $ranges = $request->get_param( 'quantity_ranges' );
        if ( ! empty( $ranges ) ) {
            $rule->save_quantity_ranges( $ranges );
        }

        /**
         * Fires after rule is created via REST API.
         *
         * @param JDPD_Rule       $rule    The created rule.
         * @param WP_REST_Request $request The request object.
         */
        do_action( 'jdpd_rest_rule_created', $rule, $request );

        $response = rest_ensure_response( $this->prepare_rule_response( $rule ) );
        $response->set_status( 201 );

        return $response;
    }

    /**
     * Update rule
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function update_rule( $request ) {
        $rule_id = $request->get_param( 'id' );
        $rule = new JDPD_Rule( $rule_id );

        if ( ! $rule->get_id() ) {
            return new WP_Error( 'not_found', __( 'Rule not found.', 'jezweb-dynamic-pricing' ), array( 'status' => 404 ) );
        }

        $params = $request->get_params();

        foreach ( $params as $key => $value ) {
            if ( in_array( $key, array( 'name', 'rule_type', 'status', 'priority', 'discount_type', 'discount_value', 'apply_to', 'conditions' ), true ) ) {
                $rule->set( $key, $value );
            }
        }

        $rule->save();

        // Handle quantity ranges
        if ( isset( $params['quantity_ranges'] ) ) {
            $rule->save_quantity_ranges( $params['quantity_ranges'] );
        }

        /**
         * Fires after rule is updated via REST API.
         *
         * @param JDPD_Rule       $rule    The updated rule.
         * @param WP_REST_Request $request The request object.
         */
        do_action( 'jdpd_rest_rule_updated', $rule, $request );

        return rest_ensure_response( $this->prepare_rule_response( $rule ) );
    }

    /**
     * Delete rule
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function delete_rule( $request ) {
        $rule_id = $request->get_param( 'id' );
        $rule = new JDPD_Rule( $rule_id );

        if ( ! $rule->get_id() ) {
            return new WP_Error( 'not_found', __( 'Rule not found.', 'jezweb-dynamic-pricing' ), array( 'status' => 404 ) );
        }

        /**
         * Fires before rule is deleted via REST API.
         *
         * @param JDPD_Rule       $rule    The rule being deleted.
         * @param WP_REST_Request $request The request object.
         */
        do_action( 'jdpd_rest_before_rule_delete', $rule, $request );

        $rule->delete();

        return rest_ensure_response( array(
            'deleted' => true,
            'id'      => $rule_id,
        ) );
    }

    /**
     * Calculate discount
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function calculate_discount( $request ) {
        $product_id = $request->get_param( 'product_id' );
        $quantity = $request->get_param( 'quantity' );
        $user_id = $request->get_param( 'user_id' );

        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return new WP_Error( 'invalid_product', __( 'Product not found.', 'jezweb-dynamic-pricing' ), array( 'status' => 404 ) );
        }

        $calculator = new JDPD_Discount_Calculator();
        $discount = $calculator->calculate_product_discount( $product, $quantity, $user_id );

        return rest_ensure_response( array(
            'product_id'       => $product_id,
            'product_name'     => $product->get_name(),
            'original_price'   => $product->get_price(),
            'quantity'         => $quantity,
            'discount'         => $discount,
            'discounted_price' => $discount['final_price'] ?? $product->get_price(),
            'savings'          => $discount['savings'] ?? 0,
            'applied_rules'    => $discount['applied_rules'] ?? array(),
        ) );
    }

    /**
     * Get analytics
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_analytics( $request ) {
        $analytics = jdpd_analytics();
        $data = $analytics->get_dashboard_data( array(
            'date_from' => $request->get_param( 'date_from' ),
            'date_to'   => $request->get_param( 'date_to' ),
            'rule_id'   => $request->get_param( 'rule_id' ),
        ) );

        return rest_ensure_response( $data );
    }

    /**
     * Export rules
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function export_rules( $request ) {
        $rule_ids = $request->get_param( 'rule_ids' ) ?: array();
        $export = jdpd_import_export();
        $json = $export->export_rules( $rule_ids );

        return rest_ensure_response( json_decode( $json, true ) );
    }

    /**
     * Import rules
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function import_rules( $request ) {
        $rules_json = $request->get_param( 'rules' );
        $overwrite = $request->get_param( 'overwrite' );

        $import = jdpd_import_export();
        $result = $import->import_rules( $rules_json, array( 'overwrite' => $overwrite ) );

        return rest_ensure_response( $result );
    }

    /**
     * Get templates
     *
     * @return WP_REST_Response
     */
    public function get_templates() {
        $import_export = jdpd_import_export();
        $templates = $import_export->get_templates();

        return rest_ensure_response( $templates );
    }

    /**
     * Create from template
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function create_from_template( $request ) {
        $key = $request->get_param( 'key' );
        $import_export = jdpd_import_export();
        $result = $import_export->create_from_template( $key );

        if ( ! $result['success'] ) {
            return new WP_Error( 'template_error', $result['error'], array( 'status' => 400 ) );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Batch operations
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function batch_operations( $request ) {
        $results = array(
            'create' => array(),
            'update' => array(),
            'delete' => array(),
        );

        // Create
        $create = $request->get_param( 'create' );
        if ( ! empty( $create ) ) {
            foreach ( $create as $rule_data ) {
                $sub_request = new WP_REST_Request( 'POST' );
                $sub_request->set_body_params( $rule_data );
                $response = $this->create_rule( $sub_request );
                $results['create'][] = $response->get_data();
            }
        }

        // Update
        $update = $request->get_param( 'update' );
        if ( ! empty( $update ) ) {
            foreach ( $update as $rule_data ) {
                if ( empty( $rule_data['id'] ) ) {
                    continue;
                }
                $sub_request = new WP_REST_Request( 'PUT' );
                $sub_request->set_url_params( array( 'id' => $rule_data['id'] ) );
                $sub_request->set_body_params( $rule_data );
                $response = $this->update_rule( $sub_request );
                $results['update'][] = is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : $response->get_data();
            }
        }

        // Delete
        $delete = $request->get_param( 'delete' );
        if ( ! empty( $delete ) ) {
            foreach ( $delete as $rule_id ) {
                $sub_request = new WP_REST_Request( 'DELETE' );
                $sub_request->set_url_params( array( 'id' => $rule_id ) );
                $response = $this->delete_rule( $sub_request );
                $results['delete'][] = is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : $response->get_data();
            }
        }

        return rest_ensure_response( $results );
    }

    /**
     * Prepare rule response
     *
     * @param JDPD_Rule|object $rule Rule object.
     * @return array
     */
    private function prepare_rule_response( $rule ) {
        if ( $rule instanceof JDPD_Rule ) {
            return array(
                'id'              => $rule->get_id(),
                'name'            => $rule->get( 'name' ),
                'rule_type'       => $rule->get( 'rule_type' ),
                'status'          => $rule->get( 'status' ),
                'priority'        => $rule->get( 'priority' ),
                'discount_type'   => $rule->get( 'discount_type' ),
                'discount_value'  => $rule->get( 'discount_value' ),
                'apply_to'        => $rule->get( 'apply_to' ),
                'schedule_from'   => $rule->get( 'schedule_from' ),
                'schedule_to'     => $rule->get( 'schedule_to' ),
                'usage_count'     => $rule->get( 'usage_count' ),
                'usage_limit'     => $rule->get( 'usage_limit' ),
                'conditions'      => $rule->get( 'conditions' ),
                'quantity_ranges' => $rule->get_quantity_ranges(),
                '_links'          => array(
                    'self' => array(
                        'href' => rest_url( $this->namespace . '/rules/' . $rule->get_id() ),
                    ),
                ),
            );
        }

        // Handle stdClass from database
        return array(
            'id'             => $rule->id,
            'name'           => $rule->name,
            'rule_type'      => $rule->rule_type,
            'status'         => $rule->status,
            'priority'       => $rule->priority,
            'discount_type'  => $rule->discount_type,
            'discount_value' => $rule->discount_value,
            'apply_to'       => $rule->apply_to,
            'schedule_from'  => $rule->schedule_from,
            'schedule_to'    => $rule->schedule_to,
            'usage_count'    => $rule->usage_count,
            'usage_limit'    => $rule->usage_limit,
            '_links'         => array(
                'self' => array(
                    'href' => rest_url( $this->namespace . '/rules/' . $rule->id ),
                ),
            ),
        );
    }
}

// Initialize REST API
new JDPD_REST_API();
