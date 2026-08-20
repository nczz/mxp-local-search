<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MXP_Local_Search_CLI {
    private MXP_Local_Search_Config $config;
    private MXP_Local_Search_KB_Manager $kb_manager;
    private MXP_Local_Search_Index_Manager $index_manager;
    private MXP_Local_Search_Search_Handler $search_handler;

    public function __construct( MXP_Local_Search_Config $config, MXP_Local_Search_KB_Manager $kb_manager, MXP_Local_Search_Index_Manager $index_manager, MXP_Local_Search_Search_Handler $search_handler ) {
        $this->config         = $config;
        $this->kb_manager     = $kb_manager;
        $this->index_manager  = $index_manager;
        $this->search_handler = $search_handler;
    }

    public function index( array $args, array $assoc_args ): void {
        if ( ! empty( $assoc_args['all'] ) ) {
            $result = $this->index_manager->index_all(
                array(
                    'post_type' => isset( $assoc_args['type'] ) ? sanitize_key( (string) $assoc_args['type'] ) : '',
                    'batch'     => isset( $assoc_args['batch'] ) ? absint( $assoc_args['batch'] ) : (int) $this->config->get( 'batch_size', 50 ),
                )
            );
            $this->finish_cli_result( $result, 'Indexed ' . (int) ( is_array( $result ) ? ( $result['indexed'] ?? 0 ) : 0 ) . ' posts; deleted ' . (int) ( is_array( $result ) ? ( $result['deleted'] ?? 0 ) : 0 ) . ' non-indexable posts.' );
            return;
        }

        if ( isset( $assoc_args['id'] ) ) {
            $result = $this->index_manager->index_post( absint( $assoc_args['id'] ) );
            $this->finish_cli_result( $result, 'Indexed post ' . absint( $assoc_args['id'] ) . '.' );
            return;
        }

        WP_CLI::error( 'Use wp mxp-search index --all or wp mxp-search index --id=<post_id>.' );
    }

    public function search( array $args, array $assoc_args ): void {
        $query = (string) ( $args[0] ?? '' );
        if ( '' === trim( $query ) ) {
            WP_CLI::error( 'Search query is required.' );
        }

        $result = $this->search_handler->search(
            $query,
            array(
                'mode'   => isset( $assoc_args['mode'] ) ? sanitize_key( (string) $assoc_args['mode'] ) : $this->config->get( 'search_mode', 'fast' ),
                'limit'  => isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 10,
                'public' => false,
            )
        );
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI\Utils\format_items( 'table', $result, array( 'id', 'score', 'title', 'permalink' ) );
    }

    public function stats( array $args, array $assoc_args ): void {
        $stats = $this->kb_manager->stats();
        if ( is_wp_error( $stats ) ) {
            WP_CLI::error( $stats->get_error_message() );
        }

        WP_CLI\Utils\format_items( 'table', array( $stats ), array_keys( $stats ) );
    }

    public function rebuild( array $args, array $assoc_args ): void {
        if ( empty( $assoc_args['confirm'] ) ) {
            WP_CLI::error( 'Rebuild requires --confirm.' );
        }

        $result = $this->kb_manager->rebuild( (string) $assoc_args['confirm'] );
        $this->finish_cli_result( $result, 'Rebuilt MXP Local Search index.' );
    }

    public function export( array $args, array $assoc_args ): void {
        if ( empty( $assoc_args['confirm'] ) || empty( $assoc_args['output'] ) ) {
            WP_CLI::error( 'Export requires --output=<path> --confirm.' );
        }

        $count = $this->kb_manager->export( (string) $assoc_args['output'], (string) $assoc_args['confirm'] );
        $this->finish_cli_result( $count, 'Exported ' . (int) ( is_wp_error( $count ) ? 0 : $count ) . ' records.' );
    }

    public function import( array $args, array $assoc_args ): void {
        if ( empty( $assoc_args['confirm'] ) || empty( $assoc_args['input'] ) ) {
            WP_CLI::error( 'Import requires --input=<path> --confirm.' );
        }

        $count = $this->kb_manager->import( (string) $assoc_args['input'], (string) $assoc_args['confirm'] );
        $this->finish_cli_result( $count, 'Imported ' . (int) ( is_wp_error( $count ) ? 0 : $count ) . ' records.' );
    }

    public function model( array $args, array $assoc_args ): void {
        $subcommand = (string) ( $args[0] ?? 'info' );
        if ( 'info' === $subcommand ) {
            WP_CLI::line( 'mxp_search extension: ' . ( $this->kb_manager->extension_available() ? 'loaded' : 'missing' ) );
            WP_CLI::line( 'MXP_SEARCH_VERSION: ' . ( defined( 'MXP_SEARCH_VERSION' ) ? MXP_SEARCH_VERSION : 'unavailable' ) );
            WP_CLI::line( 'MXP_SEARCH_ONNX: ' . ( defined( 'MXP_SEARCH_ONNX' ) && MXP_SEARCH_ONNX ? 'yes' : 'no' ) );
            WP_CLI::line( 'MXP_SEARCH_RERANKER: ' . ( defined( 'MXP_SEARCH_RERANKER' ) && MXP_SEARCH_RERANKER ? 'yes' : 'no' ) );
            WP_CLI::line( 'Allowed models: ' . implode( ', ', (array) $this->config->get( 'allowed_models', array() ) ) );
            return;
        }

        if ( 'download' === $subcommand ) {
            $model_id = (string) ( $args[1] ?? '' );
            if ( empty( $assoc_args['confirm'] ) || empty( $assoc_args['verify'] ) ) {
                WP_CLI::error( 'Model download requires --verify --confirm.' );
            }
            if ( ! $this->config->model_is_allowlisted( $model_id ) ) {
                WP_CLI::error( 'Model is not allowlisted for MXP Local Search.' );
            }
            WP_CLI::error( 'Verified model download is unsupported by this plugin build; install a pinned server-side model bundle instead.' );
        }

        WP_CLI::error( 'Unknown model subcommand. Use info or download.' );
    }

    private function finish_cli_result( $result, string $success ): void {
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::success( $success );
    }
}
