<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MXP_Local_Search_REST_API {
    private MXP_Local_Search_Config $config;
    private MXP_Local_Search_KB_Manager $kb_manager;
    private MXP_Local_Search_Index_Manager $index_manager;
    private MXP_Local_Search_Search_Handler $search_handler;

    public function __construct( MXP_Local_Search_Config $config, MXP_Local_Search_KB_Manager $kb_manager, MXP_Local_Search_Index_Manager $index_manager, MXP_Local_Search_Search_Handler $search_handler ) {
        $this->config         = $config;
        $this->kb_manager     = $kb_manager;
        $this->index_manager  = $index_manager;
        $this->search_handler = $search_handler;

        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            MXP_LOCAL_SEARCH_REST_NAMESPACE,
            '/search',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'search' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'q'     => array( 'type' => 'string', 'required' => true ),
                    'mode'  => array( 'type' => 'string', 'enum' => array( 'fast', 'semantic', 'hybrid', 'deep' ), 'required' => false ),
                    'limit' => array( 'type' => 'integer', 'minimum' => 1, 'required' => false ),
                ),
            )
        );

        register_rest_route(
            MXP_LOCAL_SEARCH_REST_NAMESPACE,
            '/index',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'index' ),
                'permission_callback' => array( $this, 'admin_permission' ),
                'args'                => array(
                    'post_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
                    'force'   => array( 'type' => 'boolean', 'required' => false ),
                ),
            )
        );

        register_rest_route(
            MXP_LOCAL_SEARCH_REST_NAMESPACE,
            '/index-all',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'index_all' ),
                'permission_callback' => array( $this, 'admin_permission' ),
                'args'                => array(
                    'post_type' => array( 'type' => 'string', 'required' => false ),
                    'batch'     => array( 'type' => 'integer', 'required' => false, 'minimum' => 1, 'maximum' => 500 ),
                ),
            )
        );

        register_rest_route(
            MXP_LOCAL_SEARCH_REST_NAMESPACE,
            '/stats',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'stats' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            )
        );

        register_rest_route(
            MXP_LOCAL_SEARCH_REST_NAMESPACE,
            '/rebuild',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rebuild' ),
                'permission_callback' => array( $this, 'admin_permission' ),
                'args'                => array(
                    'confirm' => array( 'type' => 'string', 'required' => true ),
                ),
            )
        );

        register_rest_route(
            MXP_LOCAL_SEARCH_REST_NAMESPACE,
            '/kb',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'kb' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            )
        );
    }

    public function search( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $limited = $this->rate_limit_public_search( $request );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $result = $this->search_handler->search(
            (string) $request->get_param( 'q' ),
            array(
                'mode'   => (string) $request->get_param( 'mode' ),
                'limit'  => absint( $request->get_param( 'limit' ) ?: 10 ),
                'public' => true,
            )
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array( 'results' => $result ) );
    }

    public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $unknown = $this->reject_unknown_json_fields( $request, array( 'post_id', 'force' ) );
        if ( is_wp_error( $unknown ) ) {
            return $unknown;
        }

        $result = $this->index_manager->index_post( absint( $request->get_param( 'post_id' ) ), (bool) $request->get_param( 'force' ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function index_all( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $unknown = $this->reject_unknown_json_fields( $request, array( 'post_type', 'batch' ) );
        if ( is_wp_error( $unknown ) ) {
            return $unknown;
        }
        if ( ! $this->kb_manager->extension_available() ) {
            return $this->kb_manager->extension_missing_error();
        }

        $args = array(
            'post_type' => sanitize_key( (string) $request->get_param( 'post_type' ) ),
            'batch'     => max( 1, min( 500, absint( $request->get_param( 'batch' ) ?: $this->config->get( 'batch_size', 50 ) ) ) ),
        );
        $scheduled = wp_schedule_single_event( time() + 1, 'mxp_search_index_all_event', array( $args ) );
        if ( false === $scheduled ) {
            $error = new WP_Error( 'mxp_search_schedule_failed', __( 'Could not schedule MXP Local Search indexing.', 'mxp-local-search' ), array( 'status' => 500 ) );
            $this->index_manager->record_operation_status( 'index_all', 'failed', array( 'message' => $error->get_error_message() ) );
            return $error;
        }

        $next = wp_next_scheduled( 'mxp_search_index_all_event', array( $args ) );
        $this->index_manager->record_operation_status(
            'index_all',
            'scheduled',
            array(
                'message'       => __( 'Background indexing has been scheduled.', 'mxp-local-search' ),
                'scheduled_for' => false === $next ? time() + 1 : (int) $next,
            )
        );
        return rest_ensure_response( array( 'scheduled' => true, 'batch' => $args['batch'], 'post_type' => $args['post_type'] ?: null ) );
    }

    public function stats( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $stats = $this->kb_manager->stats();
        if ( is_wp_error( $stats ) ) {
            return $stats;
        }

        return rest_ensure_response( $stats );
    }

    public function rebuild( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $unknown = $this->reject_unknown_json_fields( $request, array( 'confirm' ) );
        if ( is_wp_error( $unknown ) ) {
            return $unknown;
        }
        if ( 'rebuild' !== (string) $request->get_param( 'confirm' ) ) {
            return new WP_Error( 'mxp_search_confirm_invalid', __( 'Rebuild requires confirm=rebuild.', 'mxp-local-search' ), array( 'status' => 400 ) );
        }

        $result = $this->kb_manager->rebuild( (string) $request->get_param( 'confirm' ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array( 'rebuilt' => true ) );
    }

    public function kb( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $info = $this->kb_manager->kb_info();
        if ( is_wp_error( $info ) ) {
            return $info;
        }

        return rest_ensure_response( $info );
    }

    public function admin_permission( WP_REST_Request $request ): bool|WP_Error {
        if ( ! $this->config->user_can_manage() ) {
            return new WP_Error( 'rest_forbidden', __( 'You cannot manage MXP Local Search.', 'mxp-local-search' ), array( 'status' => 403 ) );
        }

        if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
            return true;
        }

        $nonce = (string) $request->get_header( 'x_wp_nonce' );
        if ( '' === $nonce ) {
            $nonce = (string) $request->get_header( 'x-wp-nonce' );
        }

        if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'rest_cookie_invalid_nonce', __( 'MXP Local Search management requests require a valid REST nonce or application password.', 'mxp-local-search' ), array( 'status' => 403 ) );
        }

        return true;
    }

    private function reject_unknown_json_fields( WP_REST_Request $request, array $allowed ): true|WP_Error {
        $body = $request->get_json_params();
        if ( null === $body ) {
            $body = $request->get_body_params();
        }
        if ( ! is_array( $body ) ) {
            $body = array();
        }

        $unknown = array_diff( array_keys( $body ), $allowed );
        if ( ! empty( $unknown ) ) {
            return new WP_Error( 'mxp_search_unknown_fields', sprintf( __( 'Unknown MXP Local Search fields: %s', 'mxp-local-search' ), implode( ', ', array_map( 'sanitize_key', $unknown ) ) ), array( 'status' => 400 ) );
        }

        return true;
    }

    private function rate_limit_public_search( WP_REST_Request $request ): true|WP_Error {
        if ( is_user_logged_in() ) {
            return true;
        }

        $ip  = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $key = 'mxp_search_rate_' . md5( $ip );
        $hit = (int) get_transient( $key );
        if ( $hit >= 60 ) {
            return new WP_Error( 'mxp_search_rate_limited', __( 'Too many MXP Local Search requests.', 'mxp-local-search' ), array( 'status' => 429 ) );
        }
        set_transient( $key, $hit + 1, MINUTE_IN_SECONDS );

        return true;
    }
}
