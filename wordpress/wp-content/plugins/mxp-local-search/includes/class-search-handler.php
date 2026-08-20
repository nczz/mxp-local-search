<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MXP_Local_Search_Search_Handler {
    private MXP_Local_Search_Config $config;
    private MXP_Local_Search_KB_Manager $kb_manager;

    public function __construct( MXP_Local_Search_Config $config, MXP_Local_Search_KB_Manager $kb_manager ) {
        $this->config     = $config;
        $this->kb_manager = $kb_manager;
    }

    public function search( string $query, array $args = array() ): array|WP_Error {
        $query = trim( $query );
        if ( '' === $query ) {
            return new WP_Error( 'mxp_search_empty_query', __( 'Search query cannot be empty.', 'mxp-local-search' ), array( 'status' => 400 ) );
        }
        if ( strlen( $query ) > (int) $this->config->get( 'max_query_bytes', 2048 ) ) {
            return new WP_Error( 'mxp_search_query_too_long', __( 'Search query is too long.', 'mxp-local-search' ), array( 'status' => 400 ) );
        }

        $public_context = $this->is_public_context( $args );
        $limit          = $public_context ? $this->config->public_limit( absint( $args['limit'] ?? 10 ) ) : $this->config->authenticated_limit( absint( $args['limit'] ?? 10 ) );
        $requested_mode = isset( $args['mode'] ) && '' !== (string) $args['mode'];
        $mode_input     = $requested_mode ? (string) $args['mode'] : (string) $this->config->get( 'search_mode', 'hybrid' );
        $mode           = $this->normalize_mode( $mode_input, $public_context, $requested_mode );
        if ( is_wp_error( $mode ) ) {
            return $mode;
        }

        $store = $this->kb_manager->get_default_store();
        if ( is_wp_error( $store ) ) {
            return $store;
        }

        $candidate_limit = $this->config->candidate_limit( $limit, isset( $args['candidate_limit'] ) ? absint( $args['candidate_limit'] ) : null );
        $max_candidate   = $this->config->candidate_limit( $limit, (int) $this->config->get( 'max_candidate_limit', 500 ) );
        $aggregated      = array();

        while ( $candidate_limit <= $max_candidate ) {
            $options = array(
                'mode'            => $mode,
                'limit'           => $candidate_limit,
                'candidate_limit' => $candidate_limit,
                'filters'         => $this->public_filters(),
            );

            try {
                $chunk_results = $store->search( $query, $options );
            } catch ( Throwable $e ) {
                return $this->kb_manager->exception_to_error( $e, 'mxp_search_failed' );
            }

            $aggregated = $this->aggregate_posts( is_array( $chunk_results ) ? $chunk_results : array(), $limit );
            if ( count( $aggregated ) >= $limit || $candidate_limit >= $max_candidate ) {
                break;
            }
            $candidate_limit = min( $candidate_limit * 2, $max_candidate );
        }

        return array_values( array_slice( $aggregated, 0, $limit ) );
    }

    public function related_posts( int $post_id, array $args = array() ): array|WP_Error {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'mxp_search_post_missing', __( 'Post not found.', 'mxp-local-search' ), array( 'status' => 404 ) );
        }
        if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password || ! is_post_publicly_viewable( $post ) ) {
            return array();
        }

        $limit = max( 1, absint( $args['limit'] ?? 5 ) );
        $query = trim( (string) apply_filters( 'mxp_local_search_related_query', $this->related_query_for_post( $post ), $post, $args ) );
        if ( '' === $query ) {
            return array();
        }

        $max_bytes = (int) $this->config->get( 'max_query_bytes', 2048 );
        if ( strlen( $query ) > $max_bytes ) {
            $query = function_exists( 'mb_strcut' ) ? mb_strcut( $query, 0, $max_bytes, 'UTF-8' ) : substr( $query, 0, $max_bytes );
        }

        $results = $this->search(
            $query,
            array(
                'mode'   => sanitize_key( (string) ( $args['mode'] ?? $this->config->get( 'search_mode', 'hybrid' ) ) ),
                'limit'  => $limit + 1,
                'public' => true,
            )
        );
        if ( is_wp_error( $results ) ) {
            return $results;
        }

        $related = array_values(
            array_filter(
                $results,
                static fn( array $result ): bool => (int) ( $result['id'] ?? 0 ) !== $post_id
            )
        );

        return array_slice( $related, 0, $limit );
    }


    public function public_filters(): array {
        $filters = array(
            array( 'key' => 'status', 'op' => 'eq', 'value' => 'publish' ),
            array( 'key' => 'visibility', 'op' => 'eq', 'value' => 'public' ),
            array( 'key' => 'password_protected', 'op' => 'eq', 'value' => false ),
            array( 'key' => 'post_type', 'op' => 'in', 'value' => array_values( (array) $this->config->get( 'post_types', array( 'post', 'page' ) ) ) ),
        );

        $locale = $this->current_public_locale();
        if ( '' !== $locale ) {
            $filters[] = array( 'key' => 'locale', 'op' => 'eq', 'value' => $locale );
        }

        $filtered = apply_filters( 'mxp_local_search_public_filters', $filters, $this->config );
        if ( ! is_array( $filtered ) ) {
            return $filters;
        }

        return array_values( array_filter( $filtered, 'is_array' ) );
    }

    private function aggregate_posts( array $chunk_results, int $limit ): array {
        $by_post = array();

        foreach ( $chunk_results as $result ) {
            if ( ! is_array( $result ) ) {
                continue;
            }

            $metadata = isset( $result['metadata'] ) && is_array( $result['metadata'] ) ? $result['metadata'] : array();
            $post_id  = absint( $metadata['post_id'] ?? 0 );
            if ( ! $post_id ) {
                continue;
            }

            $post = get_post( $post_id );
            if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_publicly_viewable( $post ) ) {
                continue;
            }

            if ( ! in_array( $post->post_type, (array) $this->config->get( 'post_types', array( 'post', 'page' ) ), true ) ) {
                continue;
            }

            if ( is_user_logged_in() && ! current_user_can( 'read_post', $post_id ) ) {
                continue;
            }

            $score = isset( $result['score'] ) ? (float) $result['score'] : 0.0;
            if ( isset( $by_post[ $post_id ] ) && $by_post[ $post_id ]['score'] >= $score ) {
                continue;
            }

            $row = array(
                'id'        => $post_id,
                'title'     => get_the_title( $post ),
                'permalink' => get_permalink( $post ),
                'snippet'   => wp_trim_words( wp_strip_all_tags( (string) ( $result['snippet'] ?? $result['content'] ?? '' ) ), 40 ),
                'score'     => max( 0.0, min( 1.0, $score ) ),
                'post_type' => $post->post_type,
                'sources'   => array_values( array_filter( array_map( 'sanitize_key', (array) ( $result['sources'] ?? array() ) ) ) ),
            );

            $filtered_row = apply_filters( 'mxp_local_search_result', $row, $result, $post );
            $by_post[ $post_id ] = is_array( $filtered_row ) ? $filtered_row : $row;
        }

        uasort(
            $by_post,
            static function ( array $a, array $b ): int {
                return $b['score'] <=> $a['score'];
            }
        );

        return array_slice( $by_post, 0, $limit, true );
    }

    private function related_query_for_post( WP_Post $post ): string {
        $parts = array(
            get_the_title( $post ),
            $post->post_excerpt,
            wp_strip_all_tags( strip_shortcodes( $post->post_content ), true ),
        );

        return trim( implode( "\n\n", array_filter( array_map( 'strval', $parts ) ) ) );
    }

    private function current_public_locale(): string {
        $locale = '';
        if ( function_exists( 'pll_current_language' ) ) {
            $pll_locale = pll_current_language( 'locale' );
            if ( is_string( $pll_locale ) ) {
                $locale = $pll_locale;
            }
        }
        if ( '' === $locale && function_exists( 'has_filter' ) && has_filter( 'wpml_current_language' ) ) {
            $language = apply_filters( 'wpml_current_language', null );
            if ( is_string( $language ) && '' !== $language ) {
                $locale = (string) apply_filters( 'mxp_local_search_wpml_language_locale', '', $language );
            }
        }
        if ( '' === $locale ) {
            $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        }

        return sanitize_text_field( (string) apply_filters( 'mxp_local_search_public_locale', $locale ) );
    }

    private function normalize_mode( string $mode, bool $public_context, bool $requested_mode ): string|WP_Error {
        $mode = in_array( $mode, array( 'fast', 'semantic', 'hybrid', 'deep' ), true ) ? $mode : (string) $this->config->get( 'search_mode', 'hybrid' );
        if ( 'deep' === $mode && $public_context && $requested_mode ) {
            return new WP_Error( 'mxp_search_deep_forbidden', __( 'Anonymous public search cannot use deep mode.', 'mxp-local-search' ), array( 'status' => 403 ) );
        }

        $semantic_available = defined( 'MXP_SEARCH_ONNX' ) && MXP_SEARCH_ONNX;
        if ( ! $semantic_available && in_array( $mode, array( 'semantic', 'hybrid', 'deep' ), true ) ) {
            return new WP_Error( 'mxp_search_semantic_unavailable', __( 'Semantic search requires the mxp_search ONNX feature.', 'mxp-local-search' ), array( 'status' => 503 ) );
        }

        if ( 'deep' === $mode ) {
            if ( $public_context ) {
                return 'hybrid';
            }
            if ( ! ( defined( 'MXP_SEARCH_RERANKER' ) && MXP_SEARCH_RERANKER ) ) {
                return new WP_Error( 'mxp_search_deep_unavailable', __( 'Deep mode requires the mxp_search reranker feature.', 'mxp-local-search' ), array( 'status' => 503 ) );
            }
        }

        return $mode;
    }

    private function is_public_context( array $args ): bool {
        if ( array_key_exists( 'public', $args ) ) {
            return (bool) $args['public'];
        }

        return ! is_user_logged_in() && ! ( defined( 'WP_CLI' ) && WP_CLI );
    }
}
