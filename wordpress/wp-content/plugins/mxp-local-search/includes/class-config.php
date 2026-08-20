<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MXP_Local_Search_Config {
    private array $settings;

    public function __construct() {
        $saved          = get_option( MXP_LOCAL_SEARCH_OPTION, array() );
        $this->settings = $this->sanitize_settings( is_array( $saved ) ? $saved : array() );
    }

    public function all(): array {
        return $this->settings;
    }

    public function default_settings(): array {
        return $this->sanitize_settings( array() );
    }

    public function normalize_settings( array $settings ): array {
        return $this->sanitize_settings( $settings );
    }

    public function get( string $key, $default = null ) {
        return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $default;
    }

    public function update( array $settings ): array {
        $this->settings = $this->sanitize_settings( array_merge( $this->settings, $settings ) );
        update_option( MXP_LOCAL_SEARCH_OPTION, $this->settings, false );

        return $this->settings;
    }

    public function capability(): string {
        return MXP_LOCAL_SEARCH_CAPABILITY;
    }

    public function user_can_manage(): bool {
        return current_user_can( MXP_LOCAL_SEARCH_CAPABILITY ) || current_user_can( 'manage_options' );
    }

    public function store_root(): string {
        $root = (string) $this->get( 'store_root', '' );
        if ( '' === $root && function_exists( 'ini_get' ) ) {
            $root = (string) ini_get( 'mxp_search.store_root' );
        }
        if ( '' === $root ) {
            $root = trailingslashit( WP_CONTENT_DIR ) . 'mxp-local-search/kb';
        }

        return untrailingslashit( wp_normalize_path( $root ) );
    }

    public function export_root(): string {
        $root = (string) $this->get( 'export_root', '' );
        if ( '' === $root && function_exists( 'ini_get' ) ) {
            $root = (string) ini_get( 'mxp_search.export_root' );
        }
        if ( '' === $root ) {
            $root = trailingslashit( WP_CONTENT_DIR ) . 'mxp-local-search/export';
        }

        return untrailingslashit( wp_normalize_path( $root ) );
    }

    public function default_kb_path(): string {
        return $this->store_root() . '/default';
    }

    public function public_limit( int $requested ): int {
        return max( 1, min( $requested, (int) $this->get( 'max_public_limit', 20 ) ) );
    }

    public function authenticated_limit( int $requested ): int {
        return max( 1, min( $requested, (int) $this->get( 'max_auth_limit', 50 ) ) );
    }

    public function candidate_limit( int $limit, ?int $requested = null ): int {
        $candidate = null === $requested ? max( $limit * 10, 100 ) : max( $requested, $limit );

        return max( $limit, min( $candidate, (int) $this->get( 'max_candidate_limit', 500 ) ) );
    }

    public function model_is_allowlisted( string $model_id ): bool {
        return in_array( $model_id, $this->get( 'allowed_models', array( 'multilingual-e5-small' ) ), true );
    }

    public function safe_path_in_root( string $path, string $root, bool $path_must_exist ): string|WP_Error {
        $root = wp_normalize_path( $root );
        if ( ! file_exists( $root ) && ! wp_mkdir_p( $root ) ) {
            return new WP_Error( 'mxp_search_root_unwritable', __( 'MXP Local Search storage root is not writable.', 'mxp-local-search' ) );
        }

        $real_root = realpath( $root );
        if ( false === $real_root ) {
            return new WP_Error( 'mxp_search_root_invalid', __( 'MXP Local Search storage root is invalid.', 'mxp-local-search' ) );
        }

        if ( $path_must_exist ) {
            $real_path = realpath( $path );
            if ( false === $real_path ) {
                return new WP_Error( 'mxp_search_path_missing', __( 'The requested MXP Local Search path does not exist.', 'mxp-local-search' ) );
            }
        } else {
            $parent = realpath( dirname( $path ) );
            if ( false === $parent ) {
                return new WP_Error( 'mxp_search_path_parent_missing', __( 'The requested MXP Local Search path parent does not exist.', 'mxp-local-search' ) );
            }
            $real_path = $parent . DIRECTORY_SEPARATOR . basename( $path );
        }

        $real_root = untrailingslashit( wp_normalize_path( $real_root ) );
        $real_path = wp_normalize_path( $real_path );
        if ( $real_path !== $real_root && ! str_starts_with( $real_path, trailingslashit( $real_root ) ) ) {
            return new WP_Error( 'mxp_search_path_outside_root', __( 'MXP Local Search path must stay inside the configured root.', 'mxp-local-search' ) );
        }

        return $real_path;
    }

    private function defaults(): array {
        return array(
            'kb_mode'               => 'single',
            'post_types'            => array( 'post', 'page' ),
            'search_mode'           => 'fast',
            'chunk_strategy'        => 'smart',
            'custom_fields'         => array(),
            'include_taxonomies'    => true,
            'include_comments'      => false,
            'replace_native_search' => false,
            'max_public_limit'      => 20,
            'max_auth_limit'        => 50,
            'max_candidate_limit'   => 500,
            'max_query_bytes'       => 2048,
            'batch_size'            => 50,
            'stale_delete_ceiling'  => 1000,
            'default_model'         => 'multilingual-e5-small',
            'allowed_models'        => array( 'multilingual-e5-small' ),
            'store_root'            => '',
            'export_root'           => '',
        );
    }

    private function sanitize_settings( array $settings ): array {
        $settings = array_merge( $this->defaults(), $settings );

        $kb_mode = in_array( $settings['kb_mode'], array( 'single', 'per_type' ), true ) ? $settings['kb_mode'] : 'single';
        $mode    = in_array( $settings['search_mode'], array( 'fast', 'semantic', 'hybrid', 'deep' ), true ) ? $settings['search_mode'] : 'fast';
        if ( 'fast' !== $mode && ! ( defined( 'MXP_SEARCH_ONNX' ) && MXP_SEARCH_ONNX ) ) {
            $mode = 'fast';
        }
        if ( 'deep' === $mode && ! ( defined( 'MXP_SEARCH_RERANKER' ) && MXP_SEARCH_RERANKER ) ) {
            $mode = 'hybrid';
        }
        $chunk   = in_array( $settings['chunk_strategy'], array( 'paragraph', 'heading', 'fixed', 'smart' ), true ) ? $settings['chunk_strategy'] : 'smart';

        $post_types = array_values(
            array_unique(
                array_filter(
                    array_map( 'sanitize_key', (array) $settings['post_types'] )
                )
            )
        );
        if ( empty( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        $custom_fields = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $settings['custom_fields'] ) ) ) );
        $models        = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $settings['allowed_models'] ) ) ) );
        if ( empty( $models ) ) {
            $models = array( 'multilingual-e5-small' );
        }

        return array(
            'kb_mode'               => $kb_mode,
            'post_types'            => $post_types,
            'search_mode'           => $mode,
            'chunk_strategy'        => $chunk,
            'custom_fields'         => $custom_fields,
            'include_taxonomies'    => (bool) $settings['include_taxonomies'],
            'include_comments'      => (bool) $settings['include_comments'],
            'replace_native_search' => (bool) $settings['replace_native_search'],
            'max_public_limit'      => max( 1, min( 100, absint( $settings['max_public_limit'] ) ) ),
            'max_auth_limit'        => max( 1, min( 100, absint( $settings['max_auth_limit'] ) ) ),
            'max_candidate_limit'   => max( 10, min( 5000, absint( $settings['max_candidate_limit'] ) ) ),
            'max_query_bytes'       => max( 1, min( 8192, absint( $settings['max_query_bytes'] ) ) ),
            'batch_size'            => max( 1, min( 500, absint( $settings['batch_size'] ) ) ),
            'stale_delete_ceiling'  => max( 1, min( 10000, absint( $settings['stale_delete_ceiling'] ) ) ),
            'default_model'         => sanitize_text_field( (string) $settings['default_model'] ),
            'allowed_models'        => $models,
            'store_root'            => sanitize_text_field( (string) $settings['store_root'] ),
            'export_root'           => sanitize_text_field( (string) $settings['export_root'] ),
        );
    }
}
