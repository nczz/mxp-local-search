<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MXP_Local_Search_Admin {
    private MXP_Local_Search_Config $config;
    private MXP_Local_Search_KB_Manager $kb_manager;
    private MXP_Local_Search_Index_Manager $index_manager;
    private MXP_Local_Search_Search_Handler $search_handler;

    public function __construct( MXP_Local_Search_Config $config, MXP_Local_Search_KB_Manager $kb_manager, MXP_Local_Search_Index_Manager $index_manager, MXP_Local_Search_Search_Handler $search_handler ) {
        $this->config         = $config;
        $this->kb_manager     = $kb_manager;
        $this->index_manager  = $index_manager;
        $this->search_handler = $search_handler;

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'admin_post_mxp_search_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_post_mxp_search_index_all', array( $this, 'index_all' ) );
        add_action( 'admin_post_mxp_search_run_scheduled', array( $this, 'run_scheduled' ) );
        add_action( 'init', array( $this, 'register_index_columns' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_index_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_post_indexing_options' ), 10, 2 );
        add_action( 'admin_post_mxp_search_reindex_post', array( $this, 'reindex_post' ) );
        add_action( 'admin_notices', array( $this, 'post_index_notice' ) );
    }

    public function admin_menu(): void {
        add_management_page(
            __( 'MXP Local Search', 'mxp-local-search' ),
            __( 'MXP Local Search', 'mxp-local-search' ),
            MXP_LOCAL_SEARCH_CAPABILITY,
            'mxp-local-search',
            array( $this, 'render_dashboard' )
        );
    }

    public function enqueue( string $hook ): void {
        if ( 'tools_page_mxp-local-search' !== $hook ) {
            return;
        }

        wp_enqueue_style( 'mxp-local-search-admin', MXP_LOCAL_SEARCH_URL . 'assets/admin.css', array(), MXP_LOCAL_SEARCH_VERSION );
        wp_enqueue_script( 'mxp-local-search-admin', MXP_LOCAL_SEARCH_URL . 'assets/admin.js', array(), MXP_LOCAL_SEARCH_VERSION, true );
    }

    public function render_dashboard(): void {
        if ( ! $this->config->user_can_manage() ) {
            wp_die( esc_html__( 'You cannot manage MXP Local Search.', 'mxp-local-search' ) );
        }

        $settings          = $this->config->all();
        $stats             = $this->kb_manager->extension_available() ? $this->kb_manager->stats() : $this->kb_manager->extension_missing_error();
        $posttypes         = array_filter( get_post_types( array( 'public' => true ), 'objects' ), static fn( $post_type ) => is_post_type_viewable( $post_type ) );
        $reindex_scheduled = $this->has_scheduled_hook( 'mxp_search_config_reindex_event' ) || $this->has_scheduled_hook( 'mxp_search_index_all_event' );
        $operation_status  = $this->index_manager->operation_status();
        include MXP_LOCAL_SEARCH_DIR . 'templates/admin-dashboard.php';
    }

    public function save_settings(): void {
        if ( ! $this->config->user_can_manage() ) {
            wp_die( esc_html__( 'You cannot manage MXP Local Search.', 'mxp-local-search' ) );
        }
        check_admin_referer( 'mxp_search_save_settings' );

        $old_settings = $this->config->all();
        $new_settings = $this->config->update(
            array(
                'post_types'            => array_map( 'sanitize_key', (array) ( $_POST['post_types'] ?? array() ) ),
                'search_mode'           => sanitize_key( (string) ( $_POST['search_mode'] ?? 'fast' ) ),
                'chunk_strategy'        => sanitize_key( (string) ( $_POST['chunk_strategy'] ?? 'smart' ) ),
                'custom_fields'         => array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( (string) ( $_POST['custom_fields'] ?? '' ) ) ) ) ) ),
                'include_taxonomies'    => ! empty( $_POST['include_taxonomies'] ),
                'include_comments'      => ! empty( $_POST['include_comments'] ),
                'replace_native_search' => ! empty( $_POST['replace_native_search'] ),
            )
        );

        $schedule_result = $this->index_manager->handle_config_changed( $old_settings, $new_settings );
        $args            = array( 'page' => 'mxp-local-search', 'updated' => '1' );
        if ( is_wp_error( $schedule_result ) ) {
            $args['mxp_error'] = rawurlencode( $schedule_result->get_error_message() );
        } elseif ( true === $schedule_result ) {
            $args['reindex_scheduled'] = '1';
        } else {
            $args['no_reindex'] = '1';
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
        exit;
    }

    public function index_all(): void {
        if ( ! $this->config->user_can_manage() ) {
            wp_die( esc_html__( 'You cannot manage MXP Local Search.', 'mxp-local-search' ) );
        }
        check_admin_referer( 'mxp_search_index_all' );

        $result = $this->index_manager->index_all( array( 'batch' => (int) $this->config->get( 'batch_size', 50 ) ) );
        $args   = array( 'page' => 'mxp-local-search' );
        if ( is_wp_error( $result ) ) {
            $this->index_manager->record_operation_status( 'index_all', 'failed', array( 'message' => $result->get_error_message() ) );
            $args['mxp_error'] = rawurlencode( $result->get_error_message() );
        } else {
            $this->index_manager->record_operation_status( 'index_all', empty( $result['errors'] ) ? 'completed' : 'completed_with_errors', array( 'message' => __( 'Manual indexing completed.', 'mxp-local-search' ), 'summary' => $result, 'completed_at' => time() ) );
            $args['indexed'] = (int) ( $result['indexed'] ?? 0 );
            $args['deleted'] = (int) ( $result['deleted'] ?? 0 );
            $args['errors']  = is_array( $result['errors'] ?? null ) ? count( $result['errors'] ) : 0;
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
        exit;
    }

    public function run_scheduled(): void {
        if ( ! $this->config->user_can_manage() ) {
            wp_die( esc_html__( 'You cannot manage MXP Local Search.', 'mxp-local-search' ) );
        }
        check_admin_referer( 'mxp_search_run_scheduled' );
        $summary = $this->run_scheduled_jobs();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'            => 'mxp-local-search',
                    'manual_cron_run' => '1',
                    'index_jobs'      => (int) $summary['index_all'],
                    'reindex_jobs'    => (int) $summary['config_reindex'],
                    'single_jobs'     => (int) $summary['single_post'],
                    'errors'          => count( $summary['errors'] ),
                ),
                admin_url( 'tools.php' )
            )
        );
        exit;
    }

    public function run_scheduled_jobs(): array {
        $summary = array( 'index_all' => 0, 'config_reindex' => 0, 'single_post' => 0, 'errors' => array() );
        $job_map = array(
            'mxp_search_index_all_event'      => 'index_all',
            'mxp_search_config_reindex_event' => 'config_reindex',
            'mxp_search_reindex_post_event'   => 'single_post',
        );
        $events  = _get_cron_array();
        if ( is_array( $events ) ) {
            foreach ( $events as $timestamp => $hooks ) {
                foreach ( $job_map as $hook => $summary_key ) {
                    foreach ( (array) ( $hooks[ $hook ] ?? array() ) as $event ) {
                        $args = is_array( $event['args'] ?? null ) ? $event['args'] : array();
                        try {
                            do_action_ref_array( $hook, $args );
                            ++$summary[ $summary_key ];
                            wp_unschedule_event( (int) $timestamp, $hook, $args );
                        } catch ( Throwable $e ) {
                            $summary['errors'][] = $e->getMessage();
                        }
                    }
                }
            }
        }

        $this->index_manager->record_operation_status(
            'manual_cron',
            empty( $summary['errors'] ) ? 'completed' : 'completed_with_errors',
            array(
                'message'      => sprintf(
                    __( 'Manual run completed: %1$d indexing jobs, %2$d settings reindex jobs, %3$d single-post reindex jobs.', 'mxp-local-search' ),
                    (int) $summary['index_all'],
                    (int) $summary['config_reindex'],
                    (int) $summary['single_post']
                ),
                'summary'      => array( 'indexed' => (int) $summary['index_all'], 'deleted' => 0, 'errors' => $summary['errors'] ),
                'completed_at' => time(),
            )
        );

        return $summary;
    }


    public function add_index_meta_boxes(): void {
        if ( ! $this->config->user_can_manage() ) {
            return;
        }

        foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
            add_meta_box(
                'mxp-local-search-indexing',
                __( 'MXP Local Search Indexing', 'mxp-local-search' ),
                array( $this, 'render_index_meta_box' ),
                $post_type,
                'side',
                'default'
            );
        }
    }

    public function register_index_columns(): void {
        foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
            add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_index_status_column' ) );
            add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_index_status_column' ), 10, 2 );
        }

    }

    public function add_index_status_column( array $columns ): array {
        $next = array();
        foreach ( $columns as $key => $label ) {
            $next[ $key ] = $label;
            if ( 'title' === $key ) {
                $next['mxp_search_index'] = __( 'MXP index', 'mxp-local-search' );
            }
        }

        if ( ! isset( $next['mxp_search_index'] ) ) {
            $next['mxp_search_index'] = __( 'MXP index', 'mxp-local-search' );
        }

        return $next;
    }

    public function render_index_status_column( string $column, int $post_id ): void {
        if ( 'mxp_search_index' !== $column ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            echo '&mdash;';
            return;
        }

        echo esc_html( $this->index_status_label( $post ) );
        $last_indexed = (int) get_post_meta( $post_id, '_mxp_search_last_indexed', true );
        if ( $last_indexed > 0 ) {
            echo '<br /><small>' . esc_html( sprintf( __( 'Last indexed: %s', 'mxp-local-search' ), wp_date( 'Y-m-d H:i:s', $last_indexed ) ) ) . '</small>';
        }
        if ( $this->config->user_can_manage() && current_user_can( 'edit_post', $post_id ) ) {
            echo '<br /><a class="button button-small" href="' . esc_url( $this->reindex_post_url( $post_id, $this->current_admin_url() ) ) . '">' . esc_html__( 'Reindex now', 'mxp-local-search' ) . '</a>';
        }
    }

    private function reindex_post_url( int $post_id, string $redirect_to = '' ): string {
        $args = array(
            'action'  => 'mxp_search_reindex_post',
            'post_id' => $post_id,
        );
        if ( '' !== $redirect_to ) {
            $args['redirect_to'] = $redirect_to;
        }

        return wp_nonce_url(
            add_query_arg( $args, admin_url( 'admin-post.php' ) ),
            'mxp_search_reindex_post_' . $post_id
        );
    }

    private function current_admin_url(): string {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
        if ( '' === $request_uri ) {
            return admin_url( 'edit.php' );
        }

        return home_url( $request_uri );
    }

    private function index_status_label( WP_Post $post ): string {
        if ( (bool) get_post_meta( $post->ID, '_mxp_search_exclude', true ) ) {
            return __( 'Excluded', 'mxp-local-search' );
        }

        $chunk_count = (int) get_post_meta( $post->ID, '_mxp_search_chunk_count', true );
        if ( $chunk_count > 0 ) {
            return sprintf( __( 'Indexed (%d chunks)', 'mxp-local-search' ), $chunk_count );
        }

        if ( ! $this->index_manager->is_indexable( $post ) ) {
            return __( 'Not indexable', 'mxp-local-search' );
        }

        return __( 'Not indexed', 'mxp-local-search' );
    }


    public function render_index_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'mxp_search_post_indexing_' . $post->ID, 'mxp_search_post_indexing_nonce' );

        $excluded     = (bool) get_post_meta( $post->ID, '_mxp_search_exclude', true );
        $chunk_count  = (int) get_post_meta( $post->ID, '_mxp_search_chunk_count', true );
        $last_indexed = (int) get_post_meta( $post->ID, '_mxp_search_last_indexed', true );
        $reindex_url  = $this->reindex_post_url( $post->ID );
        ?>
        <p>
            <label>
                <input type="checkbox" name="mxp_search_exclude" value="1" <?php checked( $excluded ); ?> />
                <?php esc_html_e( 'Exclude this post from MXP Local Search', 'mxp-local-search' ); ?>
            </label>
        </p>
        <p>
            <?php if ( $excluded ) : ?>
                <?php esc_html_e( 'Status: excluded. Saving or reindexing removes existing chunks.', 'mxp-local-search' ); ?>
            <?php elseif ( $chunk_count > 0 ) : ?>
                <?php echo esc_html( sprintf( __( 'Status: indexed (%d chunks).', 'mxp-local-search' ), $chunk_count ) ); ?>
            <?php else : ?>
                <?php esc_html_e( 'Status: not indexed yet, or not currently indexable by global settings.', 'mxp-local-search' ); ?>
            <?php endif; ?>
            <?php if ( $last_indexed > 0 ) : ?>
                <br /><?php echo esc_html( sprintf( __( 'Last indexed: %s', 'mxp-local-search' ), wp_date( 'Y-m-d H:i:s', $last_indexed ) ) ); ?>
            <?php endif; ?>
        </p>
        <p><a class="button" href="<?php echo esc_url( $reindex_url ); ?>"><?php esc_html_e( 'Reindex this post now', 'mxp-local-search' ); ?></a></p>
        <p class="description"><?php esc_html_e( 'Use this for one-off fixes after editing important content. Global settings and public visibility still apply.', 'mxp-local-search' ); ?></p>
        <?php
    }

    public function save_post_indexing_options( int $post_id, WP_Post $post ): void {
        if ( ! isset( $_POST['mxp_search_post_indexing_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['mxp_search_post_indexing_nonce'] ) ), 'mxp_search_post_indexing_' . $post_id ) ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $this->config->user_can_manage() || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( ! empty( $_POST['mxp_search_exclude'] ) ) {
            update_post_meta( $post_id, '_mxp_search_exclude', '1' );
        } else {
            delete_post_meta( $post_id, '_mxp_search_exclude' );
        }
    }

    public function reindex_post(): void {
        $post_id = absint( $_GET['post_id'] ?? 0 );
        if ( $post_id <= 0 || ! $this->config->user_can_manage() || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'You cannot manage MXP Local Search.', 'mxp-local-search' ) );
        }
        check_admin_referer( 'mxp_search_reindex_post_' . $post_id );

        $result = $this->index_manager->index_post( $post_id, true );
        $args   = array();
        if ( is_wp_error( $result ) ) {
            if ( 'mxp_search_write_locked' === $result->get_error_code() ) {
                $scheduled = $this->schedule_post_reindex( $post_id );
                if ( is_wp_error( $scheduled ) ) {
                    $this->index_manager->record_operation_status( 'single_post', 'failed', array( 'message' => $scheduled->get_error_message(), 'summary' => array( 'indexed' => 0, 'deleted' => 0, 'errors' => array( $this->post_status_error_detail( $post_id, $scheduled ) ) ), 'completed_at' => time() ) );
                    $args['mxp_error'] = rawurlencode( $scheduled->get_error_message() );
                } else {
                    $this->index_manager->record_operation_status( 'single_post', 'scheduled', array( 'message' => __( 'Post reindex is queued because MXP is currently running another write job.', 'mxp-local-search' ), 'completed_at' => time() ) );
                    $args['mxp_post_scheduled'] = '1';
                }
            } else {
                $this->index_manager->record_operation_status( 'single_post', 'failed', array( 'message' => $result->get_error_message(), 'summary' => array( 'indexed' => 0, 'deleted' => 0, 'errors' => array( $this->post_status_error_detail( $post_id, $result ) ) ), 'completed_at' => time() ) );
                $args['mxp_error'] = rawurlencode( $result->get_error_message() );
            }
        } else {
            $status = (string) ( $result['status'] ?? 'indexed' );
            $this->index_manager->record_operation_status( 'single_post', 'completed', array( 'message' => __( 'Single post indexing completed.', 'mxp-local-search' ), 'summary' => array( 'indexed' => ( 'indexed' === $status ? 1 : 0 ), 'deleted' => (int) ( $result['deleted'] ?? 0 ), 'errors' => array(), 'deleted_details' => (array) ( $result['deleted_details'] ?? array() ) ), 'completed_at' => time() ) );
            if ( in_array( $status, array( 'deleted_non_indexable', 'deleted_empty' ), true ) ) {
                $args['mxp_post_excluded'] = '1';
            } else {
                $args['mxp_post_indexed'] = '1';
            }
        }

        $redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ) : get_edit_post_link( $post_id, 'url' );
        $redirect_to = wp_validate_redirect( $redirect_to, get_edit_post_link( $post_id, 'url' ) );
        wp_safe_redirect( add_query_arg( $args, $redirect_to ) );
        exit;
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

    private function schedule_post_reindex( int $post_id ): bool|WP_Error {
        $args = array( $post_id );
        if ( false !== wp_next_scheduled( 'mxp_search_reindex_post_event', $args ) ) {
            return true;
        }

        $scheduled = wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'mxp_search_reindex_post_event', $args, true );
        if ( is_wp_error( $scheduled ) ) {
            return $scheduled;
        }
        if ( false === $scheduled ) {
            return new WP_Error( 'mxp_search_schedule_failed', __( 'Could not schedule MXP Local Search reindex.', 'mxp-local-search' ) );
        }

        return true;
    }

    public function post_index_notice(): void {
        global $pagenow;
        if ( ! in_array( (string) $pagenow, array( 'edit.php', 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        if ( ! isset( $_GET['mxp_post_indexed'] ) && ! isset( $_GET['mxp_post_excluded'] ) && ! isset( $_GET['mxp_post_scheduled'] ) && ! isset( $_GET['mxp_error'] ) ) {
            return;
        }

        if ( isset( $_GET['mxp_error'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( wp_unslash( $_GET['mxp_error'] ) ) . '</p></div>';
            return;
        }
        if ( isset( $_GET['mxp_post_excluded'] ) ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'MXP Local Search removed this post from the index.', 'mxp-local-search' ) . '</p></div>';
            return;
        }
        if ( isset( $_GET['mxp_post_scheduled'] ) ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'MXP Local Search queued this post for reindexing because another write or rebuild job is running.', 'mxp-local-search' ) . '</p></div>';
            return;
        }
        if ( isset( $_GET['mxp_post_indexed'] ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'MXP Local Search reindexed this post.', 'mxp-local-search' ) . '</p></div>';
        }
    }


    private function has_scheduled_hook( string $hook ): bool {
        $events = _get_cron_array();
        if ( ! is_array( $events ) ) {
            return false;
        }

        foreach ( $events as $event ) {
            if ( isset( $event[ $hook ] ) ) {
                return true;
            }
        }

        return false;
    }
}
