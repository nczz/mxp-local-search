<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$extension_loaded = $this->kb_manager->extension_available();
$custom_fields    = implode( ', ', (array) ( $settings['custom_fields'] ?? array() ) );
$operation_status = is_array( $operation_status ?? null ) ? $operation_status : array();
$operation_labels = array(
    'settings_save'   => __( 'Settings save', 'mxp-local-search' ),
    'config_reindex'  => __( 'Settings reindex', 'mxp-local-search' ),
    'index_all'       => __( 'Index all posts', 'mxp-local-search' ),
    'single_post'     => __( 'Single post indexing', 'mxp-local-search' ),
    'manual_cron'     => __( 'Manual scheduled jobs run', 'mxp-local-search' ),
    'browser_smoke'   => __( 'Browser smoke check', 'mxp-local-search' ),
);
$status_labels    = array(
    'scheduled'             => __( 'scheduled', 'mxp-local-search' ),
    'running'               => __( 'running', 'mxp-local-search' ),
    'completed'             => __( 'completed', 'mxp-local-search' ),
    'completed_with_errors' => __( 'completed with errors', 'mxp-local-search' ),
    'failed'                => __( 'failed', 'mxp-local-search' ),
);
$chunk_strategy_descriptions = array(
    'smart'     => __( 'smart: keeps headings and nearby paragraphs together; best default for mixed article/product content.', 'mxp-local-search' ),
    'paragraph' => __( 'paragraph: splits on paragraph boundaries; predictable for long prose with clear blank lines.', 'mxp-local-search' ),
    'heading'   => __( 'heading: starts chunks at headings; useful for documentation pages with structured sections.', 'mxp-local-search' ),
    'fixed'     => __( 'fixed: uses fixed-size text windows; fallback for unstructured content, but can cut through sentences.', 'mxp-local-search' ),
);
?>
<div class="wrap mxp-local-search-admin">
    <h1><?php esc_html_e( 'MXP Local Search', 'mxp-local-search' ); ?></h1>

    <?php if ( isset( $_GET['mxp_error'] ) ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( wp_unslash( $_GET['mxp_error'] ) ); ?></p></div>
    <?php elseif ( isset( $_GET['reindex_scheduled'] ) ) : ?>
        <div class="notice notice-info"><p><?php esc_html_e( 'Search settings saved. A background reindex has been scheduled so this page does not wait for embedding work.', 'mxp-local-search' ); ?></p></div>
    <?php elseif ( isset( $_GET['no_reindex'] ) ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'MXP Local Search settings saved. No index rebuild was needed.', 'mxp-local-search' ); ?></p></div>
    <?php elseif ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'MXP Local Search settings saved.', 'mxp-local-search' ); ?></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['indexed'] ) ) : ?>
        <div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Indexed %1$d posts; deleted %2$d non-indexable posts; errors %3$d.', 'mxp-local-search' ), absint( $_GET['indexed'] ), absint( $_GET['deleted'] ?? 0 ), absint( $_GET['errors'] ?? 0 ) ) ); ?></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['manual_cron_run'] ) ) : ?>
        <div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Manual scheduled jobs run completed: %1$d indexing jobs, %2$d settings reindex jobs, %3$d single-post reindex jobs, %4$d errors.', 'mxp-local-search' ), absint( $_GET['index_jobs'] ?? 0 ), absint( $_GET['reindex_jobs'] ?? 0 ), absint( $_GET['single_jobs'] ?? 0 ), absint( $_GET['errors'] ?? 0 ) ) ); ?></p></div>
    <?php endif; ?>

    <section class="mxp-card">
        <h2><?php esc_html_e( 'Status', 'mxp-local-search' ); ?></h2>
        <table class="widefat striped">
            <tbody>
                <tr>
                    <th><?php esc_html_e( 'Extension', 'mxp-local-search' ); ?></th>
                    <td><?php echo esc_html( $extension_loaded ? ( defined( 'MXP_SEARCH_VERSION' ) ? 'mxp_search ' . MXP_SEARCH_VERSION : __( 'mxp_search loaded', 'mxp-local-search' ) ) : __( 'missing', 'mxp-local-search' ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Plugin version', 'mxp-local-search' ); ?></th>
                    <td><?php echo esc_html( MXP_LOCAL_SEARCH_VERSION ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Model', 'mxp-local-search' ); ?></th>
                    <td><?php echo esc_html( (string) ( $settings['default_model'] ?? 'multilingual-e5-small' ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Knowledge base', 'mxp-local-search' ); ?></th>
                    <td>
                        <?php if ( is_wp_error( $stats ) ) : ?>
                            <?php echo esc_html( $stats->get_error_message() ); ?>
                        <?php else : ?>
                            <?php echo esc_html( sprintf( __( '%d documents / %d chunks', 'mxp-local-search' ), (int) ( $stats['document_count'] ?? 0 ), (int) ( $stats['chunk_count'] ?? 0 ) ) ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Search mode', 'mxp-local-search' ); ?></th>
                    <td><?php echo esc_html( (string) ( $settings['search_mode'] ?? 'fast' ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Built-in search replacement', 'mxp-local-search' ); ?></th>
                    <td><?php echo esc_html( ! empty( $settings['replace_native_search'] ) ? __( 'enabled', 'mxp-local-search' ) : __( 'disabled', 'mxp-local-search' ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Background indexing', 'mxp-local-search' ); ?></th>
                    <td><?php echo esc_html( $reindex_scheduled ? __( 'scheduled', 'mxp-local-search' ) : __( 'idle', 'mxp-local-search' ) ); ?></td>
                </tr>
                <?php if ( ! empty( $operation_status ) ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Last operation', 'mxp-local-search' ); ?></th>
                    <td>
                        <?php
                        $operation_key = (string) ( $operation_status['operation'] ?? 'unknown' );
                        $status_key    = (string) ( $operation_status['status'] ?? 'unknown' );
                        echo esc_html(
                            sprintf(
                                '%1$s: %2$s',
                                $operation_labels[ $operation_key ] ?? $operation_key,
                                $status_labels[ $status_key ] ?? $status_key
                            )
                        );
                        ?>
                        <?php if ( ! empty( $operation_status['message'] ) ) : ?>
                            <br /><?php echo esc_html( (string) $operation_status['message'] ); ?>
                        <?php endif; ?>
                        <?php if ( isset( $operation_status['indexed'] ) || isset( $operation_status['deleted'] ) || isset( $operation_status['errors'] ) ) : ?>
                            <br /><?php echo esc_html( sprintf( __( 'Indexed %1$d / Deleted %2$d / Errors %3$d', 'mxp-local-search' ), (int) ( $operation_status['indexed'] ?? 0 ), (int) ( $operation_status['deleted'] ?? 0 ), (int) ( $operation_status['errors'] ?? 0 ) ) ); ?>
                        <?php endif; ?>
                        <?php if ( ! empty( $operation_status['scheduled_for'] ) ) : ?>
                            <br /><?php echo esc_html( sprintf( __( 'Scheduled for %s', 'mxp-local-search' ), wp_date( 'Y-m-d H:i:s', (int) $operation_status['scheduled_for'] ) ) ); ?>
                        <?php endif; ?>
                        <?php if ( ! empty( $operation_status['completed_at'] ) ) : ?>
                            <br /><?php echo esc_html( sprintf( __( 'Completed at %s', 'mxp-local-search' ), wp_date( 'Y-m-d H:i:s', (int) $operation_status['completed_at'] ) ) ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="mxp-card">
        <h2><?php esc_html_e( 'Actions', 'mxp-local-search' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'mxp_search_index_all' ); ?>
            <input type="hidden" name="action" value="mxp_search_index_all" />
            <?php submit_button( __( 'Index All Posts Now', 'mxp-local-search' ), 'primary', 'submit', false ); ?>
        </form>
        <p class="description"><?php esc_html_e( 'Builds or refreshes the local search index for the selected post types. This does not enable built-in search replacement by itself.', 'mxp-local-search' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'mxp_search_run_scheduled' ); ?>
            <input type="hidden" name="action" value="mxp_search_run_scheduled" />
            <?php submit_button( __( 'Run Scheduled MXP Jobs Now', 'mxp-local-search' ), 'secondary', 'submit', false ); ?>
        </form>
        <p class="description"><?php esc_html_e( 'Use this when WP-Cron is disabled or unreliable. It immediately runs pending MXP indexing and settings-reindex jobs that WordPress has scheduled.', 'mxp-local-search' ); ?></p>
    </section>

    <section class="mxp-card">
        <h2><?php esc_html_e( 'Settings', 'mxp-local-search' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'mxp_search_save_settings' ); ?>
            <input type="hidden" name="action" value="mxp_search_save_settings" />

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Post types', 'mxp-local-search' ); ?></th>
                    <td>
                        <?php foreach ( $posttypes as $type => $object ) : ?>
                            <label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $settings['post_types'], true ) ); ?> /> <?php echo esc_html( $object->labels->singular_name ); ?></label><br />
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e( 'Select the public content types to index. WooCommerce products appear here when WooCommerce registers the product post type; public products include SKU, price, stock status, attributes, taxonomies, and configured custom fields.', 'mxp-local-search' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mxp-search-mode"><?php esc_html_e( 'Search mode', 'mxp-local-search' ); ?></label></th>
                    <td>
                        <select id="mxp-search-mode" name="search_mode">
                            <?php foreach ( array( 'fast', 'semantic', 'hybrid', 'deep' ) as $mode ) : ?>
                                <option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $settings['search_mode'], $mode ); ?>><?php echo esc_html( $mode ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'fast uses SQLite full-text search; semantic uses vectors; hybrid combines both; deep adds reranking when the native extension supports it.', 'mxp-local-search' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mxp-chunk-strategy"><?php esc_html_e( 'Chunk strategy', 'mxp-local-search' ); ?></label></th>
                    <td>
                        <select id="mxp-chunk-strategy" name="chunk_strategy">
                            <?php foreach ( array( 'smart', 'paragraph', 'heading', 'fixed' ) as $strategy ) : ?>
                                <option value="<?php echo esc_attr( $strategy ); ?>" <?php selected( $settings['chunk_strategy'], $strategy ); ?>><?php echo esc_html( $strategy ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Controls how posts are split before indexing. Changing this schedules a background reindex.', 'mxp-local-search' ); ?></p>
                        <ul class="description">
                            <?php foreach ( $chunk_strategy_descriptions as $strategy_description ) : ?>
                                <li><?php echo esc_html( $strategy_description ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mxp-custom-fields"><?php esc_html_e( 'Custom fields allowlist', 'mxp-local-search' ); ?></label></th>
                    <td>
                        <input class="regular-text" id="mxp-custom-fields" name="custom_fields" value="<?php echo esc_attr( $custom_fields ); ?>" />
                        <p class="description"><?php esc_html_e( 'Comma-separated post meta keys to index. Keep this explicit; sensitive-looking keys such as secret, token, password, email, and phone are skipped.', 'mxp-local-search' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Taxonomies', 'mxp-local-search' ); ?></th>
                    <td><label><input type="checkbox" name="include_taxonomies" value="1" <?php checked( ! empty( $settings['include_taxonomies'] ) ); ?> /> <?php esc_html_e( 'Index categories, tags, product attributes, and other taxonomy terms for selected post types.', 'mxp-local-search' ); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Comments', 'mxp-local-search' ); ?></th>
                    <td><label><input type="checkbox" name="include_comments" value="1" <?php checked( ! empty( $settings['include_comments'] ) ); ?> /> <?php esc_html_e( 'Index approved comments. This can expose commenter content in search.', 'mxp-local-search' ); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Built-in WordPress search', 'mxp-local-search' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="replace_native_search" value="1" <?php checked( ! empty( $settings['replace_native_search'] ) ); ?> /> <?php esc_html_e( 'Replace the public WordPress search results page with MXP Local Search results.', 'mxp-local-search' ); ?></label>
                        <p class="description"><?php esc_html_e( 'Off by default. Leave this disabled while configuring, testing, or temporarily using the normal WordPress search. Shortcodes and REST search still work when this is off.', 'mxp-local-search' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Settings', 'mxp-local-search' ) ); ?>
        </form>
    </section>
</div>
