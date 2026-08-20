<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MXP_Local_Search_Content_Extractor {
    private MXP_Local_Search_Config $config;

    public function __construct( MXP_Local_Search_Config $config ) {
        $this->config = $config;
    }

    public function extract( WP_Post $post ): array {
        $parts = array();

        $title = $this->clean_text( get_the_title( $post ) );
        if ( '' !== $title ) {
            $parts[] = $title;
        }

        $excerpt = $this->clean_text( $post->post_excerpt );
        if ( '' !== $excerpt ) {
            $parts[] = $excerpt;
        }

        $content = $this->post_content_text( $post->post_content );
        if ( '' !== $content ) {
            $parts[] = $content;
        }

        $product_text = $this->product_text( $post );
        if ( '' !== $product_text ) {
            $parts[] = $product_text;
        }

        foreach ( $this->allowed_custom_field_text( $post->ID ) as $field => $value ) {
            $parts[] = $field . ': ' . $value;
        }

        $taxonomy_breadcrumb = '';
        if ( $this->config->get( 'include_taxonomies', true ) ) {
            $taxonomy_breadcrumb = $this->taxonomy_breadcrumb( $post );
            if ( '' !== $taxonomy_breadcrumb ) {
                $parts[] = $taxonomy_breadcrumb;
            }
        }

        if ( $this->config->get( 'include_comments', false ) ) {
            $comments = $this->approved_comment_text( $post->ID );
            if ( '' !== $comments ) {
                $parts[] = $comments;
            }
        }

        $parts = apply_filters( 'mxp_local_search_extracted_parts', $parts, $post, $this->config );
        if ( ! is_array( $parts ) ) {
            $parts = array();
        }

        $metadata = array(
            'locale'     => $this->post_locale( $post ),
            'language'   => $this->post_language_code( $post ),
            'taxonomies' => $taxonomy_breadcrumb,
            'acl_hash'   => $this->public_acl_hash(),
        );
        $metadata = apply_filters( 'mxp_local_search_extracted_metadata', $metadata, $post, $this->config );
        if ( ! is_array( $metadata ) ) {
            $metadata = array();
        }

        return array(
            'title'    => $title,
            'content'  => trim( implode( "\n\n", array_filter( array_map( 'strval', $parts ) ) ) ),
            'metadata' => $metadata,
        );
    }

    private function post_content_text( string $content ): string {
        if ( function_exists( 'has_blocks' ) && has_blocks( $content ) && function_exists( 'parse_blocks' ) ) {
            return $this->clean_text( $this->blocks_to_text( parse_blocks( $content ) ) );
        }

        return $this->clean_text( strip_shortcodes( $content ) );
    }

    private function product_text( WP_Post $post ): string {
        if ( ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
            return '';
        }

        $parts = array();
        if ( function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $sku = method_exists( $product, 'get_sku' ) ? $this->clean_text( (string) $product->get_sku() ) : '';
                if ( '' !== $sku ) {
                    $parts[] = 'SKU: ' . $sku;
                }

                $price = method_exists( $product, 'get_price' ) ? $this->clean_text( (string) $product->get_price() ) : '';
                if ( '' !== $price ) {
                    $parts[] = 'Price: ' . $price;
                }

                $stock = method_exists( $product, 'get_stock_status' ) ? $this->clean_text( (string) $product->get_stock_status() ) : '';
                if ( '' !== $stock ) {
                    $parts[] = 'Stock: ' . $stock;
                }

                if ( method_exists( $product, 'get_attributes' ) ) {
                    foreach ( (array) $product->get_attributes() as $attribute ) {
                        $attribute_text = $this->product_attribute_text( $attribute );
                        if ( '' !== $attribute_text ) {
                            $parts[] = $attribute_text;
                        }
                    }
                }
            }
        }

        foreach ( array( '_sku' => 'SKU', '_price' => 'Price', '_stock_status' => 'Stock' ) as $meta_key => $label ) {
            $value = $this->scalar_or_flat_text( get_post_meta( $post->ID, $meta_key, true ) );
            if ( '' !== $value ) {
                $parts[] = $label . ': ' . $value;
            }
        }

        $attributes = get_post_meta( $post->ID, '_product_attributes', true );
        if ( is_array( $attributes ) ) {
            foreach ( $attributes as $name => $attribute ) {
                $value = is_array( $attribute ) && isset( $attribute['value'] ) ? $this->scalar_or_flat_text( $attribute['value'] ) : '';
                if ( '' !== $value ) {
                    $parts[] = $this->clean_text( (string) $name ) . ': ' . $value;
                }
            }
        }

        $parts = apply_filters( 'mxp_local_search_product_text_parts', array_values( array_unique( array_filter( $parts ) ) ), $post, $this->config );
        if ( ! is_array( $parts ) ) {
            return '';
        }

        return $this->clean_text( implode( "\n", array_map( 'strval', $parts ) ) );
    }

    private function product_attribute_text( $attribute ): string {
        if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) || ! method_exists( $attribute, 'get_options' ) ) {
            return '';
        }

        $name    = (string) $attribute->get_name();
        $label   = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $name ) : $name;
        $options = array();
        foreach ( (array) $attribute->get_options() as $option ) {
            if ( is_numeric( $option ) ) {
                $term = get_term( (int) $option );
                if ( $term && ! is_wp_error( $term ) ) {
                    $options[] = $term->name;
                }
            } else {
                $options[] = (string) $option;
            }
        }

        $value = $this->scalar_or_flat_text( $options );
        if ( '' === $value ) {
            return '';
        }

        return $this->clean_text( $label ) . ': ' . $value;
    }

    private function blocks_to_text( array $blocks ): string {
        $parts = array();

        foreach ( $blocks as $block ) {
            if ( ! empty( $block['innerHTML'] ) ) {
                $parts[] = wp_strip_all_tags( (string) $block['innerHTML'], true );
            }

            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $parts[] = $this->blocks_to_text( $block['innerBlocks'] );
            }
        }

        return implode( "\n\n", array_filter( $parts ) );
    }

    private function allowed_custom_field_text( int $post_id ): array {
        $allowlist = (array) $this->config->get( 'custom_fields', array() );
        if ( empty( $allowlist ) ) {
            return array();
        }

        $out = array();
        foreach ( $allowlist as $key ) {
            if ( $this->is_sensitive_field_key( $key ) ) {
                continue;
            }

            $value = get_post_meta( $post_id, $key, true );
            $text  = $this->scalar_or_flat_text( $value );
            if ( '' !== $text ) {
                $out[ $key ] = $text;
            }
        }

        return $out;
    }

    private function taxonomy_breadcrumb( WP_Post $post ): string {
        $taxonomies = get_object_taxonomies( $post->post_type, 'names' );
        $names      = array();

        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_the_terms( $post, $taxonomy );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                $names[] = $this->clean_text( $term->name );
            }
        }

        return implode( ' > ', array_values( array_unique( array_filter( $names ) ) ) );
    }

    private function approved_comment_text( int $post_id ): string {
        $comments = get_comments(
            array(
                'post_id' => $post_id,
                'status'  => 'approve',
                'number'  => 100,
                'fields'  => 'ids',
            )
        );

        $parts = array();
        foreach ( $comments as $comment_id ) {
            $comment = get_comment( $comment_id );
            if ( $comment ) {
                $parts[] = $this->clean_text( $comment->comment_content );
            }
        }

        return implode( "\n\n", array_filter( $parts ) );
    }

    private function scalar_or_flat_text( $value ): string {
        if ( is_scalar( $value ) ) {
            return $this->clean_text( (string) $value );
        }

        if ( is_array( $value ) ) {
            $flat = array();
            array_walk_recursive(
                $value,
                static function ( $item ) use ( &$flat ): void {
                    if ( is_scalar( $item ) ) {
                        $flat[] = (string) $item;
                    }
                }
            );

            return $this->clean_text( implode( ' ', $flat ) );
        }

        return '';
    }

    private function is_sensitive_field_key( string $key ): bool {
        return (bool) preg_match( '/(secret|token|password|pass|email|phone|mobile|address|ssn|credit|card|key)/i', $key );
    }

    private function clean_text( string $text ): string {
        $text = wp_strip_all_tags( $text, true );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
        $text = preg_replace( "/[ \t\r]+/u", ' ', $text ) ?? $text;
        $text = preg_replace( "/\n{3,}/u", "\n\n", $text ) ?? $text;

        return trim( $text );
    }


    private function post_locale( WP_Post $post ): string {
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        if ( function_exists( 'pll_get_post_language' ) ) {
            $pll_locale = pll_get_post_language( $post->ID, 'locale' );
            if ( is_string( $pll_locale ) && '' !== $pll_locale ) {
                $locale = $pll_locale;
            }
        }
        if ( function_exists( 'has_filter' ) && has_filter( 'wpml_post_language_details' ) ) {
            $details = apply_filters( 'wpml_post_language_details', null, $post->ID );
            if ( is_array( $details ) && ! empty( $details['locale'] ) ) {
                $locale = (string) $details['locale'];
            }
        }

        return sanitize_text_field( (string) apply_filters( 'mxp_local_search_post_locale', $locale, $post ) );
    }

    private function post_language_code( WP_Post $post ): string {
        $language = '';
        if ( function_exists( 'pll_get_post_language' ) ) {
            $pll_language = pll_get_post_language( $post->ID );
            if ( is_string( $pll_language ) ) {
                $language = $pll_language;
            }
        }
        if ( '' === $language && function_exists( 'has_filter' ) && has_filter( 'wpml_post_language_details' ) ) {
            $details = apply_filters( 'wpml_post_language_details', null, $post->ID );
            if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
                $language = (string) $details['language_code'];
            }
        }
        if ( '' === $language ) {
            $locale   = $this->post_locale( $post );
            $language = strtolower( strtok( $locale, '_' ) ?: $locale );
        }

        return sanitize_key( (string) apply_filters( 'mxp_local_search_post_language', $language, $post ) );
    }
    private function public_acl_hash(): string {
        return hash( 'sha256', 'public' );
    }
}
