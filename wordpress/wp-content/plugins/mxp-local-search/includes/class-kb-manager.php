<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MXP_Local_Search_KB_Manager {
    private MXP_Local_Search_Config $config;
    private $default_store = null;

    public function __construct( MXP_Local_Search_Config $config ) {
        $this->config = $config;
    }

    public function extension_available(): bool {
        return class_exists( 'MXP_Local_Search_Plugin' ) && MXP_Local_Search_Plugin::extension_available();
    }

    public function get_default_store() {
        if ( ! $this->extension_available() ) {
            return $this->extension_missing_error();
        }

        if ( null !== $this->default_store ) {
            return $this->default_store;
        }

        $this->configure_extension_environment();

        $path = $this->config->default_kb_path();
        if ( ! file_exists( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
            return new WP_Error( 'mxp_search_store_root_unwritable', __( 'MXP Local Search store root is not writable.', 'mxp-local-search' ) );
        }

        try {
            $store_class = '\\MXP\\Search\\Store';
            if ( is_dir( $path ) && $store_class::exists( $path ) ) {
                $this->default_store = $store_class::open( $path, $this->store_options() );
            } else {
                $this->default_store = $store_class::create( $path, $this->store_options() );
            }
        } catch ( Throwable $e ) {
            return $this->exception_to_error( $e, 'mxp_search_kb_open_failed' );
        }

        return $this->default_store;
    }

    public function stats(): array|WP_Error {
        $store = $this->get_default_store();
        if ( is_wp_error( $store ) ) {
            return $store;
        }

        try {
            $stats = method_exists( $store, 'stats' ) ? $store->stats() : array( 'chunk_count' => $store->count() );
            return $this->public_kb_fields( is_array( $stats ) ? $stats : array() );
        } catch ( Throwable $e ) {
            return $this->exception_to_error( $e, 'mxp_search_stats_failed' );
        }
    }

    public function kb_info(): array|WP_Error {
        $stats = $this->stats();
        if ( is_wp_error( $stats ) ) {
            return $stats;
        }

        return array(
            'kb_id'          => $stats['kb_id'] ?? null,
            'name'           => 'Default',
            'document_count' => (int) ( $stats['document_count'] ?? 0 ),
            'chunk_count'    => (int) ( $stats['chunk_count'] ?? 0 ),
            'vector_count'   => (int) ( $stats['vector_count'] ?? 0 ),
            'generation'     => $stats['generation'] ?? null,
        );
    }

    public function rebuild( string $confirm ): true|WP_Error {
        if ( '' === $confirm ) {
            return new WP_Error( 'mxp_search_confirm_required', __( 'Rebuild requires an explicit confirmation token.', 'mxp-local-search' ), array( 'status' => 400 ) );
        }

        return $this->with_write_lock(
            static function () use ( $confirm ) {
                $manager = MXP_Local_Search_Plugin::instance()->kb_manager;
                $store   = $manager->get_default_store();
                if ( is_wp_error( $store ) ) {
                    return $store;
                }
                try {
                    $store->rebuild( $confirm );
                    return true;
                } catch ( Throwable $e ) {
                    return $manager->exception_to_error( $e, 'mxp_search_rebuild_failed' );
                }
            }
        );
    }

    public function export( string $output_path, string $confirm ): int|WP_Error {
        if ( '' === $confirm ) {
            return new WP_Error( 'mxp_search_confirm_required', __( 'Export requires an explicit confirmation token.', 'mxp-local-search' ) );
        }

        $safe = $this->config->safe_path_in_root( $this->export_root_path( $output_path ), $this->config->export_root(), false );
        if ( is_wp_error( $safe ) ) {
            return $safe;
        }

        $store = $this->get_default_store();
        if ( is_wp_error( $store ) ) {
            return $store;
        }

        try {
            return (int) $store->export( $safe, $confirm );
        } catch ( Throwable $e ) {
            return $this->exception_to_error( $e, 'mxp_search_export_failed' );
        }
    }

    public function import( string $input_path, string $confirm ): int|WP_Error {
        if ( '' === $confirm ) {
            return new WP_Error( 'mxp_search_confirm_required', __( 'Import requires an explicit confirmation token.', 'mxp-local-search' ) );
        }

        $safe = $this->config->safe_path_in_root( $this->export_root_path( $input_path ), $this->config->export_root(), true );
        if ( is_wp_error( $safe ) ) {
            return $safe;
        }

        return $this->with_write_lock(
            function () use ( $safe, $confirm ) {
                $store = $this->get_default_store();
                if ( is_wp_error( $store ) ) {
                    return $store;
                }
                try {
                    return (int) $store->import( $safe, $confirm );
                } catch ( Throwable $e ) {
                    return $this->exception_to_error( $e, 'mxp_search_import_failed' );
                }
            }
        );
    }

    public function with_write_lock( callable $callback ) {
        $lock_key = 'mxp_search_write_lock';
        $owner    = wp_generate_uuid4();

        if ( get_transient( $lock_key ) ) {
            return new WP_Error( 'mxp_search_write_locked', __( 'MXP Local Search is already running a write or rebuild operation.', 'mxp-local-search' ), array( 'status' => 409 ) );
        }

        set_transient( $lock_key, $owner, 10 * MINUTE_IN_SECONDS );

        try {
            return $callback();
        } finally {
            if ( get_transient( $lock_key ) === $owner ) {
                delete_transient( $lock_key );
            }
        }
    }

    private function configure_extension_environment(): void {
        putenv( 'MXP_SEARCH_STORE_ROOT=' . $this->config->store_root() );
    }

    private function export_root_path( string $path ): string {
        $path = wp_normalize_path( $path );
        if ( preg_match( '#^([A-Za-z]:)?/#', $path ) || str_starts_with( $path, '//' ) ) {
            return $path;
        }

        return trailingslashit( $this->config->export_root() ) . ltrim( $path, '/\\' );
    }

    public function extension_missing_error(): WP_Error {
        return new WP_Error( 'extension_missing', __( 'The mxp_search PHP extension is required for MXP Local Search.', 'mxp-local-search' ), array( 'status' => 503 ) );
    }

    public function exception_to_error( Throwable $e, string $fallback_code ): WP_Error {
        $code = $e->getCode();
        if ( ! is_string( $code ) || '' === $code ) {
            $code = $fallback_code;
        }

        return new WP_Error( $code, $e->getMessage(), array( 'status' => 500 ) );
    }

    private function store_options(): array {
        $max_candidate_limit = max(
            (int) $this->config->get( 'max_candidate_limit', 500 ),
            (int) $this->config->get( 'max_auth_limit', 50 ),
            (int) $this->config->get( 'max_public_limit', 20 )
        );

        return array(
            'name'                => 'Default',
            'model'               => $this->config->get( 'default_model', 'multilingual-e5-small' ),
            'max_limit'           => $max_candidate_limit,
            'max_candidate_limit' => $max_candidate_limit,
            'max_query_bytes'     => (int) $this->config->get( 'max_query_bytes', 2048 ),
        );
    }

    private function public_kb_fields( array $stats ): array {
        $allowed = array( 'kb_id', 'document_count', 'chunk_count', 'vector_count', 'generation', 'model' );

        return array_intersect_key( $stats, array_flip( $allowed ) );
    }
}
