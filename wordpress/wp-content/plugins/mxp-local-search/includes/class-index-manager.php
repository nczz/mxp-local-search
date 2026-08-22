<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MXP_Local_Search_Index_Manager {
    private MXP_Local_Search_Config $config;
    private MXP_Local_Search_KB_Manager $kb_manager;
    private MXP_Local_Search_Content_Extractor $extractor;
    private MXP_Local_Search_Chunker $chunker;

    public function __construct( MXP_Local_Search_Config $config, MXP_Local_Search_KB_Manager $kb_manager, MXP_Local_Search_Content_Extractor $extractor, MXP_Local_Search_Chunker $chunker ) {
        $this->config     = $config;
        $this->kb_manager = $kb_manager;
        $this->extractor  = $extractor;
        $this->chunker    = $chunker;
    }

    public function index_post( int $post_id, bool $force = false ): array|WP_Error {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return array( 'status' => 'skipped', 'reason' => 'revision_or_autosave' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'mxp_search_post_missing', __( 'Post not found.', 'mxp-local-search' ), array( 'status' => 404 ) );
        }

        return $this->kb_manager->with_write_lock(
            function () use ( $post, $force ) {
                $store = $this->kb_manager->get_default_store();
                if ( is_wp_error( $store ) ) {
                    return $store;
                }

                return $this->index_post_unlocked( $store, $post, $force );
            }
        );
    }

    private function index_post_unlocked( $store, WP_Post $post, bool $force = false ): array|WP_Error {
        if ( ! $this->is_indexable( $post ) ) {
            $deleted = $this->delete_post_chunks_unlocked( $store, $post->ID, $post );
            if ( is_wp_error( $deleted ) ) {
                return $deleted;
            }

            return array( 'status' => 'deleted_non_indexable', 'deleted' => $deleted, 'deleted_details' => array( $this->status_post_entry( $post, array( 'reason' => 'deleted_non_indexable', 'chunks_deleted' => (int) $deleted ) ) ) );
        }

        $extracted = $this->extractor->extract( $post );
        $chunks    = $this->chunker->chunk( $post, $extracted );
        if ( empty( $chunks ) ) {
            $deleted = $this->delete_post_chunks_unlocked( $store, $post->ID, $post );
            return is_wp_error( $deleted ) ? $deleted : array( 'status' => 'deleted_empty', 'deleted' => $deleted, 'deleted_details' => array( $this->status_post_entry( $post, array( 'reason' => 'deleted_empty', 'chunks_deleted' => (int) $deleted ) ) ) );
        }

        $doc_id       = $this->document_id( $post );
        $prior_doc_id = (string) get_post_meta( $post->ID, '_mxp_search_doc_id', true );
        $prior_count  = max( 0, (int) get_post_meta( $post->ID, '_mxp_search_chunk_count', true ) );
        $summary      = array( 'new' => 0, 'full' => 0, 'metadata_fts_only' => 0, 'skipped' => 0, 'indexed' => 0, 'deleted_stale' => 0 );

        try {
            foreach ( $chunks as $idx => $chunk ) {
                $chunk_id = $this->chunk_id( $post, (int) $idx );
                $metadata = $this->metadata_for_chunk( $post, $doc_id, (int) $idx, $chunk, $extracted );
                $result   = $store->update( $chunk_id, (string) $extracted['title'], (string) $chunk['context'], $metadata );
                if ( isset( $summary[ $result ] ) ) {
                    ++$summary[ $result ];
                }
                ++$summary['indexed'];
            }

            if ( '' !== $prior_doc_id && $prior_doc_id !== $doc_id && $prior_count > 0 ) {
                $summary['deleted_stale'] = $this->delete_ids( $store, $this->chunk_ids_for_doc( $prior_doc_id, 0, $prior_count ) );
            } elseif ( $prior_count > count( $chunks ) ) {
                $summary['deleted_stale'] = $this->delete_ids(
                    $store,
                    $this->chunk_ids_for_doc( $doc_id, count( $chunks ), $prior_count )
                );
            }
        } catch ( Throwable $e ) {
            return $this->kb_manager->exception_to_error( $e, 'mxp_search_index_failed' );
        }

        update_post_meta( $post->ID, '_mxp_search_chunk_count', count( $chunks ) );
        update_post_meta( $post->ID, '_mxp_search_post_type', $post->post_type );
        update_post_meta( $post->ID, '_mxp_search_doc_id', $doc_id );
        update_post_meta( $post->ID, '_mxp_search_last_indexed', time() );

        $summary['status'] = 'indexed';
        return $summary;
    }

    public function delete_post_chunks( int $post_id, ?WP_Post $post = null ): int|WP_Error {
        return $this->kb_manager->with_write_lock(
            function () use ( $post_id, $post ) {
                $store = $this->kb_manager->get_default_store();
                if ( is_wp_error( $store ) ) {
                    return $store;
                }

                return $this->delete_post_chunks_unlocked( $store, $post_id, $post );
            }
        );
    }

    public function index_all( array $args = array() ): array|WP_Error {
        if ( ! $this->kb_manager->extension_available() ) {
            return $this->kb_manager->extension_missing_error();
        }

        return $this->kb_manager->with_write_lock(
            function () use ( $args ) {
                $store = $this->kb_manager->get_default_store();
                if ( is_wp_error( $store ) ) {
                    return $store;
                }

                $post_type = isset( $args['post_type'] ) && '' !== $args['post_type'] ? sanitize_key( (string) $args['post_type'] ) : '';
                $batch     = isset( $args['batch'] ) ? max( 1, min( 500, absint( $args['batch'] ) ) ) : (int) $this->config->get( 'batch_size', 50 );
                $types     = $post_type ? array( $post_type ) : (array) $this->config->get( 'post_types', array( 'post', 'page' ) );
                $summary   = array( 'indexed' => 0, 'deleted' => 0, 'errors' => array(), 'deleted_details' => array() );

                foreach ( $types as $type ) {
                    if ( ! in_array( $type, (array) $this->config->get( 'post_types', array() ), true ) ) {
                        continue;
                    }

                    $page = 1;
                    do {
                        $query = new WP_Query(
                            array(
                                'post_type'      => $type,
                                'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
                                'posts_per_page' => $batch,
                                'paged'          => $page,
                                'fields'         => 'ids',
                                'orderby'        => 'ID',
                                'order'          => 'ASC',
                            )
                        );

                        foreach ( $query->posts as $post_id ) {
                            if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
                                continue;
                            }

                            $post = get_post( $post_id );
                            if ( ! $post ) {
                                $summary['errors'][] = $this->status_error_entry( (int) $post_id, null, 'mxp_search_post_missing', __( 'Post not found.', 'mxp-local-search' ) );
                                continue;
                            }

                            $result = $this->index_post_unlocked( $store, $post );
                            if ( is_wp_error( $result ) ) {
                                $summary['errors'][] = $this->status_error_entry( (int) $post_id, $post, $result->get_error_code(), $result->get_error_message() );
                            } elseif ( isset( $result['status'] ) && str_starts_with( (string) $result['status'], 'deleted' ) ) {
                                ++$summary['deleted'];
                                $summary['deleted_details'] = array_merge( $summary['deleted_details'], (array) ( $result['deleted_details'] ?? array( $this->status_post_entry( $post, array( 'reason' => (string) $result['status'], 'chunks_deleted' => (int) ( $result['deleted'] ?? 0 ) ) ) ) ) );
                            } else {
                                ++$summary['indexed'];
                            }
                        }

                        ++$page;
                    } while ( $query->max_num_pages >= $page );
                }

                return $summary;
            }
        );
    }

    public function handle_transition( string $new_status, string $old_status, WP_Post $post ): void {
        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
            return;
        }

        $was_indexable = 'publish' === $old_status && '' === (string) $post->post_password && in_array( $post->post_type, (array) $this->config->get( 'post_types', array() ), true );
        $is_indexable  = $this->is_indexable( $post );

        if ( $is_indexable ) {
            $this->index_post( $post->ID );
            return;
        }

        if ( $was_indexable || get_post_meta( $post->ID, '_mxp_search_chunk_count', true ) ) {
            $this->delete_post_chunks( $post->ID, $post );
        }
    }

    public function operation_status(): array {
        $status = get_option( MXP_LOCAL_SEARCH_STATUS_OPTION, array() );

        return is_array( $status ) ? $status : array();
    }

    public function record_operation_status( string $operation, string $status, array $details = array() ): void {
        $summary         = isset( $details['summary'] ) && is_array( $details['summary'] ) ? $details['summary'] : array();
        $message         = isset( $details['message'] ) ? sanitize_text_field( (string) $details['message'] ) : '';
        $error_details   = $this->sanitize_status_details( $summary['errors'] ?? array() );
        $deleted_details = $this->sanitize_status_details( $summary['deleted_details'] ?? array() );
        $payload         = array(
            'operation'       => sanitize_key( $operation ),
            'status'          => sanitize_key( $status ),
            'message'         => $message,
            'indexed'         => isset( $summary['indexed'] ) ? (int) $summary['indexed'] : null,
            'deleted'         => isset( $summary['deleted'] ) ? (int) $summary['deleted'] : null,
            'errors'          => isset( $summary['errors'] ) && is_array( $summary['errors'] ) ? count( $summary['errors'] ) : 0,
            'error_details'   => $error_details,
            'deleted_details' => $deleted_details,
            'scheduled_for'   => isset( $details['scheduled_for'] ) ? absint( $details['scheduled_for'] ) : null,
            'started_at'      => isset( $details['started_at'] ) ? absint( $details['started_at'] ) : null,
            'completed_at'    => isset( $details['completed_at'] ) ? absint( $details['completed_at'] ) : null,
            'updated_at'      => time(),
        );

        update_option( MXP_LOCAL_SEARCH_STATUS_OPTION, array_filter( $payload, static fn( $value ) => null !== $value ), false );
    }

    private function status_error_entry( int $post_id, ?WP_Post $post, string $code, string $message ): array {
        $entry = array(
            'post_id' => $post_id,
            'code'    => $code,
            'message' => $message,
        );

        if ( $post instanceof WP_Post ) {
            $entry = array_merge( $entry, $this->status_post_entry( $post ) );
        }

        return $entry;
    }

    private function status_post_entry( WP_Post $post, array $extra = array() ): array {
        $title = get_the_title( $post );
        if ( '' === $title ) {
            $title = (string) $post->post_title;
        }

        return array_merge(
            array(
                'post_id'   => (int) $post->ID,
                'post_type' => (string) $post->post_type,
                'status'    => (string) $post->post_status,
                'title'     => $title,
            ),
            $extra
        );
    }

    private function sanitize_status_details( array $details, int $limit = 25 ): array {
        $sanitized = array();
        foreach ( $details as $detail ) {
            if ( count( $sanitized ) >= $limit ) {
                break;
            }

            if ( is_scalar( $detail ) ) {
                $sanitized[] = array( 'message' => sanitize_text_field( (string) $detail ) );
                continue;
            }

            if ( ! is_array( $detail ) ) {
                continue;
            }

            $entry = array();
            foreach ( $detail as $key => $value ) {
                $key = sanitize_key( (string) $key );
                if ( '' === $key || is_array( $value ) || is_object( $value ) ) {
                    continue;
                }
                $entry[ $key ] = is_int( $value ) ? $value : sanitize_text_field( (string) $value );
            }

            if ( ! empty( $entry ) ) {
                $sanitized[] = $entry;
            }
        }

        return $sanitized;
    }

    public function handle_config_changed( array $old_settings, array $new_settings ): bool|WP_Error {
        $old_settings = $this->config->normalize_settings( $old_settings );
        $new_settings = $this->config->normalize_settings( $new_settings );
        if ( ! $this->config_change_requires_reindex( $old_settings, $new_settings ) ) {
            $this->record_operation_status( 'settings_save', 'completed', array( 'message' => __( 'Settings saved; no index-affecting fields changed.', 'mxp-local-search' ) ) );
            return false;
        }

        $args      = array( $old_settings, $new_settings );
        $scheduled = wp_next_scheduled( 'mxp_search_config_reindex_event', $args );
        if ( $scheduled ) {
            $this->record_operation_status(
                'config_reindex',
                'scheduled',
                array(
                    'message'       => __( 'Settings saved; background reindex is already scheduled.', 'mxp-local-search' ),
                    'scheduled_for' => (int) $scheduled,
                )
            );
            return true;
        }

        $scheduled = wp_schedule_single_event( time() + 1, 'mxp_search_config_reindex_event', $args );
        if ( false === $scheduled ) {
            $error = new WP_Error( 'mxp_search_schedule_failed', __( 'Could not schedule MXP Local Search reindex.', 'mxp-local-search' ), array( 'status' => 500 ) );
            $this->record_operation_status( 'config_reindex', 'failed', array( 'message' => $error->get_error_message() ) );
            return $error;
        }

        $next = wp_next_scheduled( 'mxp_search_config_reindex_event', $args );
        $this->record_operation_status(
            'config_reindex',
            'scheduled',
            array(
                'message'       => __( 'Settings saved; background reindex has been scheduled.', 'mxp-local-search' ),
                'scheduled_for' => false === $next ? time() + 1 : (int) $next,
            )
        );
        return true;
    }

    public function handle_config_reindex( array $old_settings, array $new_settings ): array|WP_Error {
        $old_settings = $this->config->normalize_settings( $old_settings );
        $new_settings = $this->config->normalize_settings( $new_settings );
        $started_at = time();
        $this->record_operation_status( 'config_reindex', 'running', array( 'message' => __( 'Background settings reindex is running.', 'mxp-local-search' ), 'started_at' => $started_at ) );

        $old_types = array_map( 'sanitize_key', (array) ( $old_settings['post_types'] ?? array() ) );
        $new_types = array_map( 'sanitize_key', (array) ( $new_settings['post_types'] ?? array() ) );
        $disabled  = array_diff( $old_types, $new_types );
        $summary   = array( 'indexed' => 0, 'deleted' => 0, 'errors' => array(), 'deleted_details' => array() );

        foreach ( $disabled as $type ) {
            $ids = get_posts(
                array(
                    'post_type'      => $type,
                    'post_status'    => 'any',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                )
            );
            foreach ( $ids as $post_id ) {
                $post    = get_post( $post_id );
                $deleted = $this->delete_post_chunks( (int) $post_id, $post instanceof WP_Post ? $post : null );
                if ( is_wp_error( $deleted ) ) {
                    $summary['errors'][] = $this->status_error_entry( (int) $post_id, $post instanceof WP_Post ? $post : null, $deleted->get_error_code(), $deleted->get_error_message() );
                } else {
                    $summary['deleted'] += (int) $deleted;
                    if ( (int) $deleted > 0 ) {
                        if ( $post instanceof WP_Post ) {
                            $summary['deleted_details'][] = $this->status_post_entry( $post, array( 'reason' => 'post_type_disabled', 'chunks_deleted' => (int) $deleted ) );
                        } else {
                            $summary['deleted_details'][] = array( 'post_id' => (int) $post_id, 'reason' => 'post_type_disabled', 'chunks_deleted' => (int) $deleted );
                        }
                    }
                }
            }
        }

        $indexed = $this->index_all( array( 'batch' => (int) $this->config->get( 'batch_size', 50 ) ) );
        if ( is_wp_error( $indexed ) ) {
            $this->record_operation_status( 'config_reindex', 'failed', array( 'message' => $indexed->get_error_message(), 'summary' => $summary, 'started_at' => $started_at, 'completed_at' => time() ) );
            return $indexed;
        }

        $summary['indexed'] += (int) ( $indexed['indexed'] ?? 0 );
        $summary['deleted'] += (int) ( $indexed['deleted'] ?? 0 );
        $summary['errors']   = array_merge( $summary['errors'], (array) ( $indexed['errors'] ?? array() ) );
        $summary['deleted_details'] = array_merge( $summary['deleted_details'], (array) ( $indexed['deleted_details'] ?? array() ) );

        $this->record_operation_status(
            'config_reindex',
            empty( $summary['errors'] ) ? 'completed' : 'completed_with_errors',
            array(
                'message'      => empty( $summary['errors'] ) ? __( 'Background settings reindex completed.', 'mxp-local-search' ) : __( 'Background settings reindex completed with errors.', 'mxp-local-search' ),
                'summary'      => $summary,
                'started_at'   => $started_at,
                'completed_at' => time(),
            )
        );
        return $summary;
    }

    public function config_change_requires_reindex( array $old_settings, array $new_settings ): bool {
        return ( $old_settings['post_types'] ?? array() ) !== ( $new_settings['post_types'] ?? array() )
            || ( $old_settings['custom_fields'] ?? array() ) !== ( $new_settings['custom_fields'] ?? array() )
            || (bool) ( $old_settings['include_taxonomies'] ?? true ) !== (bool) ( $new_settings['include_taxonomies'] ?? true )
            || (bool) ( $old_settings['include_comments'] ?? false ) !== (bool) ( $new_settings['include_comments'] ?? false )
            || ( $old_settings['chunk_strategy'] ?? '' ) !== ( $new_settings['chunk_strategy'] ?? '' );
    }

    public function is_indexable( WP_Post $post ): bool {
        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
            return false;
        }
        if ( (bool) get_post_meta( $post->ID, '_mxp_search_exclude', true ) ) {
            return false;
        }

        if ( ! in_array( $post->post_type, (array) $this->config->get( 'post_types', array( 'post', 'page' ) ), true ) ) {
            return false;
        }

        if ( 'publish' !== $post->post_status ) {
            return false;
        }

        return '' === (string) $post->post_password && is_post_publicly_viewable( $post );
    }

    private function delete_post_chunks_unlocked( $store, int $post_id, ?WP_Post $post = null ): int|WP_Error {
        $count = max( 0, (int) get_post_meta( $post_id, '_mxp_search_chunk_count', true ) );
        if ( 0 === $count ) {
            return 0;
        }

        $doc_id = (string) get_post_meta( $post_id, '_mxp_search_doc_id', true );
        if ( '' === $doc_id ) {
            $post_type = (string) get_post_meta( $post_id, '_mxp_search_post_type', true );
            if ( '' === $post_type ) {
                $post_type = $post instanceof WP_Post ? $post->post_type : 'post';
            }
            $doc_id = $post_type . '_' . $post_id;
        }

        $ids = array();
        for ( $idx = 0; $idx < $count; ++$idx ) {
            $ids[] = $doc_id . '_chunk_' . $idx;
        }

        try {
            $deleted = $this->delete_ids( $store, $ids );
        } catch ( Throwable $e ) {
            return $this->kb_manager->exception_to_error( $e, 'mxp_search_delete_failed' );
        }

        delete_post_meta( $post_id, '_mxp_search_chunk_count' );
        delete_post_meta( $post_id, '_mxp_search_post_type' );
        delete_post_meta( $post_id, '_mxp_search_doc_id' );
        delete_post_meta( $post_id, '_mxp_search_last_indexed' );

        return $deleted;
    }

    private function chunk_ids_for_doc( string $doc_id, int $start, int $end ): array {
        $ids = array();
        for ( $idx = $start; $idx < $end; ++$idx ) {
            $ids[] = $doc_id . '_chunk_' . $idx;
        }

        return $ids;
    }

    private function delete_ids( $store, array $ids ): int {
        $ids = array_values( array_filter( array_map( 'strval', $ids ) ) );
        if ( empty( $ids ) ) {
            return 0;
        }

        if ( method_exists( $store, 'deleteBatch' ) ) {
            return (int) $store->deleteBatch( $ids );
        }

        $deleted = 0;
        foreach ( $ids as $id ) {
            if ( $store->delete( $id ) ) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    private function metadata_for_chunk( WP_Post $post, string $doc_id, int $idx, array $chunk, array $extracted ): array {
        return array(
            'doc_id'             => $doc_id,
            'post_id'            => $post->ID,
            'post_type'          => $post->post_type,
            'status'             => 'publish',
            'visibility'         => 'public',
            'password_protected' => false,
            'locale'             => (string) ( $extracted['metadata']['locale'] ?? get_locale() ),
            'language'           => (string) ( $extracted['metadata']['language'] ?? '' ),
            'acl_hash'           => (string) ( $extracted['metadata']['acl_hash'] ?? hash( 'sha256', 'public' ) ),
            'chunk_idx'          => $idx,
            'headings'           => (array) ( $chunk['headings'] ?? array() ),
        );
    }

    private function document_id( WP_Post $post ): string {
        return $post->post_type . '_' . $post->ID;
    }

    private function chunk_id( WP_Post $post, int $idx ): string {
        return $this->document_id( $post ) . '_chunk_' . $idx;
    }
}
