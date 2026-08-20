<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MXP_Local_Search_Chunker {
    private MXP_Local_Search_Config $config;

    public function __construct( MXP_Local_Search_Config $config ) {
        $this->config = $config;
    }

    public function chunk( WP_Post $post, array $extracted ): array {
        $strategy = (string) $this->config->get( 'chunk_strategy', 'smart' );
        $text     = trim( (string) ( $extracted['content'] ?? '' ) );
        if ( '' === $text ) {
            return array();
        }

        if ( 'smart' === $strategy ) {
            $strategy = $this->length( $text ) < 1000 ? 'paragraph' : 'heading';
            if ( 'heading' === $strategy && ! preg_match( '/^#{2,3}\s+/m', $text ) ) {
                $strategy = 'fixed';
            }
        }

        $texts = match ( $strategy ) {
            'paragraph' => $this->paragraph_chunks( $text ),
            'heading'   => $this->heading_chunks( $text ),
            'fixed'     => $this->fixed_chunks( $text, 1200, 160 ),
            default     => $this->paragraph_chunks( $text ),
        };

        $context = $this->context_prefix( $post, (string) ( $extracted['metadata']['taxonomies'] ?? '' ) );
        $chunks  = array();

        foreach ( $texts as $position => $chunk_text ) {
            $chunk_text = trim( $chunk_text );
            if ( '' === $chunk_text ) {
                continue;
            }

            $chunks[] = array(
                'text'     => $chunk_text,
                'context'  => trim( $context . "\n" . $chunk_text ),
                'position' => count( $chunks ),
                'headings' => $this->headings_for_chunk( $chunk_text ),
            );
        }

        return $chunks;
    }

    private function paragraph_chunks( string $text ): array {
        $paragraphs = preg_split( "/\n{2,}/u", $text ) ?: array( $text );
        $chunks     = array();
        $buffer     = '';

        foreach ( $paragraphs as $paragraph ) {
            $paragraph = trim( $paragraph );
            if ( '' === $paragraph ) {
                continue;
            }

            $candidate = '' === $buffer ? $paragraph : $buffer . "\n\n" . $paragraph;
            if ( $this->length( $candidate ) > 1200 && '' !== $buffer ) {
                $chunks[] = $buffer;
                $buffer   = $paragraph;
            } else {
                $buffer = $candidate;
            }
        }

        if ( '' !== $buffer ) {
            $chunks[] = $buffer;
        }

        return empty( $chunks ) ? array( $text ) : $chunks;
    }

    private function heading_chunks( string $text ): array {
        $lines  = preg_split( "/\R/u", $text ) ?: array( $text );
        $chunks = array();
        $buffer = '';

        foreach ( $lines as $line ) {
            $is_heading = (bool) preg_match( '/^#{2,3}\s+/', $line );
            if ( $is_heading && '' !== trim( $buffer ) ) {
                $chunks[] = trim( $buffer );
                $buffer   = '';
            }
            $buffer .= ( '' === $buffer ? '' : "\n" ) . $line;
        }

        if ( '' !== trim( $buffer ) ) {
            $chunks[] = trim( $buffer );
        }

        if ( count( $chunks ) <= 1 ) {
            return $this->fixed_chunks( $text, 1200, 160 );
        }

        return $chunks;
    }

    private function fixed_chunks( string $text, int $size, int $overlap ): array {
        $chunks = array();
        $length = $this->length( $text );
        $start  = 0;

        while ( $start < $length ) {
            $chunks[] = $this->slice( $text, $start, $size );
            $next     = $start + $size - $overlap;
            if ( $next <= $start ) {
                break;
            }
            $start = $next;
        }

        return $chunks;
    }

    private function context_prefix( WP_Post $post, string $taxonomy_breadcrumb ): string {
        $segments = array( '[' . $post->post_type . ']' );
        if ( '' !== $taxonomy_breadcrumb ) {
            $segments[] = '[' . $taxonomy_breadcrumb . ']';
        }
        $segments[] = get_the_title( $post );

        return trim( implode( ' ', array_filter( $segments ) ) );
    }

    private function headings_for_chunk( string $chunk ): array {
        preg_match_all( '/^#{1,3}\s+(.+)$/m', $chunk, $matches );

        return array_map( 'trim', $matches[1] ?? array() );
    }

    private function length( string $text ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
    }

    private function slice( string $text, int $start, int $length ): string {
        return function_exists( 'mb_substr' ) ? mb_substr( $text, $start, $length, 'UTF-8' ) : substr( $text, $start, $length );
    }
}
