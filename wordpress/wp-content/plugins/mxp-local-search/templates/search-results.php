<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$results = isset( $results ) && is_array( $results ) ? $results : array();
?>
<div class="mxp-local-search-results">
    <?php foreach ( $results as $result ) : ?>
        <article class="mxp-local-search-result">
            <h3><a href="<?php echo esc_url( $result['permalink'] ?? '' ); ?>"><?php echo esc_html( $result['title'] ?? '' ); ?></a></h3>
            <?php if ( isset( $result['score'] ) ) : ?>
                <p class="mxp-local-search-score"><?php echo esc_html( sprintf( __( 'Score: %.3f', 'mxp-local-search' ), (float) $result['score'] ) ); ?></p>
            <?php endif; ?>
            <p><?php echo esc_html( $result['snippet'] ?? '' ); ?></p>
        </article>
    <?php endforeach; ?>
</div>
