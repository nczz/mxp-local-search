<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MXP_Local_Search_Hooks {
    private MXP_Local_Search_Config $config;
    private MXP_Local_Search_Index_Manager $index_manager;
    private MXP_Local_Search_Search_Handler $search_handler;

    public function __construct( MXP_Local_Search_Config $config, MXP_Local_Search_Index_Manager $index_manager, MXP_Local_Search_Search_Handler $search_handler ) {
        $this->config         = $config;
        $this->index_manager  = $index_manager;
        $this->search_handler = $search_handler;

        add_action( 'save_post', array( $this, 'save_post' ), 20, 3 );
        add_action( 'transition_post_status', array( $this, 'transition_post_status' ), 20, 3 );
        add_action( 'before_delete_post', array( $this, 'before_delete_post' ), 20, 2 );
        add_action( 'deleted_post', array( $this, 'deleted_post' ), 20, 2 );
        add_action( 'mxp_search_index_all_event', array( $this, 'cron_index_all' ), 10, 1 );
        add_action( 'mxp_search_config_reindex_event', array( $this, 'cron_config_reindex' ), 10, 2 );
        add_action( 'mxp_search_reindex_post_event', array( $this, 'cron_reindex_post' ), 10, 1 );
        add_action( 'updated_option', array( $this, 'updated_option' ), 20, 3 );
        add_action( 'added_option', array( $this, 'added_option' ), 20, 2 );
        add_action( 'pre_get_posts', array( $this, 'replace_main_search' ), 20, 1 );
        add_filter( 'posts_search', array( $this, 'suppress_native_search_sql' ), 20, 2 );
    }

    public function save_post( int $post_id, WP_Post $post, bool $update ): void {
        $this->index_manager->index_post( $post_id );
    }

    public function transition_post_status( string $new_status, string $old_status, WP_Post $post ): void {
        if ( $new_status === $old_status ) {
            return;
        }

        $this->index_manager->handle_transition( $new_status, $old_status, $post );
    }

    public function before_delete_post( int $post_id, WP_Post $post ): void {
        $this->index_manager->delete_post_chunks( $post_id, $post );
    }

    public function deleted_post( int $post_id, ?WP_Post $post = null ): void {
        $this->index_manager->delete_post_chunks( $post_id, $post );
    }

    public function cron_index_all( array $args = array() ): void {
        $started_at = time();
        $this->index_manager->record_operation_status( 'index_all', 'running', array( 'message' => __( 'Background indexing is running.', 'mxp-local-search' ), 'started_at' => $started_at ) );
        $result = $this->index_manager->index_all( $args );
        if ( is_wp_error( $result ) ) {
            $this->index_manager->record_operation_status( 'index_all', 'failed', array( 'message' => $result->get_error_message(), 'started_at' => $started_at, 'completed_at' => time() ) );
            return;
        }

        $this->index_manager->record_operation_status(
            'index_all',
            empty( $result['errors'] ) ? 'completed' : 'completed_with_errors',
            array(
                'message'      => __( 'Background indexing completed.', 'mxp-local-search' ),
                'summary'      => is_array( $result ) ? $result : array(),
                'started_at'   => $started_at,
                'completed_at' => time(),
            )
        );
    }

    public function cron_config_reindex( array $old_settings, array $new_settings ): void {
        $this->index_manager->handle_config_reindex( $old_settings, $new_settings );
    }

    public function cron_reindex_post( int $post_id ): void {
        $started_at = time();
        $this->index_manager->record_operation_status( 'single_post', 'running', array( 'message' => __( 'Single post reindex is running.', 'mxp-local-search' ), 'started_at' => $started_at ) );

        $result = $this->index_manager->index_post( $post_id, true );
        if ( is_wp_error( $result ) ) {
            if ( 'mxp_search_write_locked' === $result->get_error_code() ) {
                if ( false === wp_next_scheduled( 'mxp_search_reindex_post_event', array( $post_id ) ) ) {
                    wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'mxp_search_reindex_post_event', array( $post_id ) );
                }
                $this->index_manager->record_operation_status( 'single_post', 'scheduled', array( 'message' => __( 'Post reindex is waiting for the current MXP write job to finish.', 'mxp-local-search' ), 'started_at' => $started_at ) );
                return;
            }

            $this->index_manager->record_operation_status( 'single_post', 'failed', array( 'message' => $result->get_error_message(), 'summary' => array( 'indexed' => 0, 'deleted' => 0, 'errors' => array( $this->post_status_error_detail( $post_id, $result ) ) ), 'started_at' => $started_at, 'completed_at' => time() ) );
            return;
        }

        $status = (string) ( $result['status'] ?? 'indexed' );
        $this->index_manager->record_operation_status( 'single_post', 'completed', array( 'message' => __( 'Single post indexing completed.', 'mxp-local-search' ), 'summary' => array( 'indexed' => ( 'indexed' === $status ? 1 : 0 ), 'deleted' => (int) ( $result['deleted'] ?? 0 ), 'errors' => array(), 'deleted_details' => (array) ( $result['deleted_details'] ?? array() ) ), 'started_at' => $started_at, 'completed_at' => time() ) );
    }

    private function post_status_error_detail( int $post_id, WP_Error $error ): array {
        $detail = array(
            'post_id' => $post_id,
            'code'    => $error->get_error_code(),
            'message' => $error->get_error_message(),
        );
        $post   = get_post( $post_id );
        if ( $post instanceof WP_Post ) {
            $title               = get_the_title( $post );
            $detail['title']     = '' !== $title ? $title : (string) $post->post_title;
            $detail['post_type'] = (string) $post->post_type;
            $detail['status']    = (string) $post->post_status;
        }

        return $detail;
    }

    public function updated_option( string $option, $old_value, $value ): void {
        if ( MXP_LOCAL_SEARCH_OPTION !== $option || ! is_array( $value ) ) {
            return;
        }

        $old_settings = is_array( $old_value ) ? $old_value : $this->config->default_settings();
        $this->index_manager->handle_config_changed( $old_settings, $value );
    }

    public function added_option( string $option, $value ): void {
        if ( MXP_LOCAL_SEARCH_OPTION !== $option || ! is_array( $value ) ) {
            return;
        }

        $this->index_manager->handle_config_changed( $this->config->default_settings(), $value );
    }

    public function replace_main_search( WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
            return;
        }
        if ( ! $this->config->get( 'replace_native_search', false ) ) {
            return;
        }

        $term = trim( (string) $query->get( 's' ) );
        if ( '' === $term ) {
            return;
        }

        $results = $this->search_handler->search(
            $term,
            array(
                'mode'   => (string) $this->config->get( 'search_mode', 'hybrid' ),
                'limit'  => (int) $this->config->get( 'max_public_limit', 20 ),
                'public' => true,
            )
        );
        if ( is_wp_error( $results ) ) {
            return;
        }

        $ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $results, 'id' ) ) ) );
        $query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );
        $query->set( 'orderby', 'post__in' );
        $query->set( 'ignore_sticky_posts', true );
        $query->set( 'mxp_local_search_replaced', true );
    }

    public function suppress_native_search_sql( string $search, WP_Query $query ): string {
        if ( $query->get( 'mxp_local_search_replaced' ) ) {
            return '';
        }

        return $search;
    }
}
