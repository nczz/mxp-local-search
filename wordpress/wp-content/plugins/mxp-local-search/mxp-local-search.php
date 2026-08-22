<?php
/**
 * Plugin Name: MXP Local Search
 * Description: Local semantic and full-text search for WordPress using the mxp_search PHP extension.
 * Plugin URI: https://github.com/nczz/mxp-local-search
 * Version: 0.1.1
 * Requires PHP: 8.1
 * Author: MXP
 * Author URI: https://github.com/nczz
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: mxp-local-search
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MXP_LOCAL_SEARCH_VERSION', '0.1.1' );
define( 'MXP_LOCAL_SEARCH_FILE', __FILE__ );
define( 'MXP_LOCAL_SEARCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'MXP_LOCAL_SEARCH_URL', plugin_dir_url( __FILE__ ) );
define( 'MXP_LOCAL_SEARCH_OPTION', 'mxp_local_search_settings' );
define( 'MXP_LOCAL_SEARCH_STATUS_OPTION', 'mxp_local_search_status' );
define( 'MXP_LOCAL_SEARCH_CAPABILITY', 'manage_mxp_search' );
define( 'MXP_LOCAL_SEARCH_REST_NAMESPACE', 'mxp-search/v1' );

require_once MXP_LOCAL_SEARCH_DIR . 'includes/class-config.php';
require_once MXP_LOCAL_SEARCH_DIR . 'includes/class-content-extractor.php';
require_once MXP_LOCAL_SEARCH_DIR . 'includes/class-chunker.php';
require_once MXP_LOCAL_SEARCH_DIR . 'includes/class-kb-manager.php';
require_once MXP_LOCAL_SEARCH_DIR . 'includes/class-index-manager.php';
require_once MXP_LOCAL_SEARCH_DIR . 'includes/class-search-handler.php';
require_once MXP_LOCAL_SEARCH_DIR . 'includes/class-hooks.php';
require_once MXP_LOCAL_SEARCH_DIR . 'includes/class-admin.php';
require_once MXP_LOCAL_SEARCH_DIR . 'includes/class-rest-api.php';
require_once MXP_LOCAL_SEARCH_DIR . 'includes/class-cli.php';

final class MXP_Local_Search_Plugin {
    private static ?MXP_Local_Search_Plugin $instance = null;

    public MXP_Local_Search_Config $config;
    public MXP_Local_Search_KB_Manager $kb_manager;
    public MXP_Local_Search_Index_Manager $index_manager;
    public MXP_Local_Search_Search_Handler $search_handler;
    public MXP_Local_Search_REST_API $rest_api;
    public MXP_Local_Search_Hooks $hooks;
    public ?MXP_Local_Search_Admin $admin = null;

    public static function instance(): MXP_Local_Search_Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->config         = new MXP_Local_Search_Config();
        $this->kb_manager     = new MXP_Local_Search_KB_Manager( $this->config );
        $extractor            = new MXP_Local_Search_Content_Extractor( $this->config );
        $chunker              = new MXP_Local_Search_Chunker( $this->config );
        $this->index_manager  = new MXP_Local_Search_Index_Manager( $this->config, $this->kb_manager, $extractor, $chunker );
        $this->search_handler = new MXP_Local_Search_Search_Handler( $this->config, $this->kb_manager );
        $this->hooks          = new MXP_Local_Search_Hooks( $this->config, $this->index_manager, $this->search_handler );
        $this->rest_api       = new MXP_Local_Search_REST_API( $this->config, $this->kb_manager, $this->index_manager, $this->search_handler );

        if ( is_admin() ) {
            $this->admin = new MXP_Local_Search_Admin( $this->config, $this->kb_manager, $this->index_manager, $this->search_handler );
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'mxp-search', new MXP_Local_Search_CLI( $this->config, $this->kb_manager, $this->index_manager, $this->search_handler ) );
        }

        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'init', array( $this, 'register_blocks' ) );

        add_action( 'admin_notices', array( $this, 'extension_missing_notice' ) );
        add_shortcode( 'mxp_search', array( $this, 'render_search_shortcode' ) );
        add_shortcode( 'mxp_related', array( $this, 'render_related_shortcode' ) );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'mxp-local-search', false, dirname( plugin_basename( MXP_LOCAL_SEARCH_FILE ) ) . '/languages' );
    }

    public function register_blocks(): void {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        wp_register_script(
            'mxp-local-search-related-block',
            MXP_LOCAL_SEARCH_URL . 'assets/related-block.js',
            array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render', 'wp-data' ),
            MXP_LOCAL_SEARCH_VERSION,
            true
        );
        wp_set_script_translations( 'mxp-local-search-related-block', 'mxp-local-search', MXP_LOCAL_SEARCH_DIR . 'languages' );

        register_block_type(
            'mxp-local-search/related-posts',
            array(
                'api_version'     => 2,
                'title'           => __( 'MXP Related Articles', 'mxp-local-search' ),
                'description'     => __( 'Show semantically related articles from the MXP Local Search index.', 'mxp-local-search' ),
                'category'        => 'widgets',
                'icon'            => 'networking',
                'editor_script'   => 'mxp-local-search-related-block',
                'render_callback' => array( $this, 'render_related_block' ),
                'uses_context'    => array( 'postId' ),
                'attributes'      => array(
                    'limit'  => array(
                        'type'    => 'number',
                        'default' => 5,
                    ),
                    'mode'   => array(
                        'type'    => 'string',
                        'default' => '',
                    ),
                    'postId' => array(
                        'type'    => 'number',
                        'default' => 0,
                    ),
                    'title'  => array(
                        'type'    => 'string',
                        'default' => __( 'Related articles', 'mxp-local-search' ),
                    ),
                ),
            )
        );
    }


    public function render_search_shortcode( array $atts = array() ): string {
        $atts = shortcode_atts(
            array(
                'limit' => 10,
                'mode'  => '',
            ),
            $atts,
            'mxp_search'
        );

        $query   = isset( $_GET['mxp_search_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mxp_search_q'] ) ) : '';
        $results = array();
        $error   = null;

        if ( '' !== trim( $query ) ) {
            $result = $this->search_handler->search(
                $query,
                array(
                    'mode'   => sanitize_key( (string) $atts['mode'] ),
                    'limit'  => absint( $atts['limit'] ),
                    'public' => true,
                )
            );
            if ( is_wp_error( $result ) ) {
                $error = $result->get_error_message();
            } else {
                $results = $result;
            }
        }

        ob_start();
        ?>
        <form class="mxp-local-search-form" method="get">
            <label>
                <span class="screen-reader-text"><?php esc_html_e( 'Search knowledge base', 'mxp-local-search' ); ?></span>
                <input type="search" name="mxp_search_q" value="<?php echo esc_attr( $query ); ?>" placeholder="<?php esc_attr_e( 'Search knowledge base', 'mxp-local-search' ); ?>" />
            </label>
            <button type="submit"><?php esc_html_e( 'Search', 'mxp-local-search' ); ?></button>
        </form>
        <?php if ( null !== $error ) : ?>
            <p class="mxp-local-search-error"><?php echo esc_html( $error ); ?></p>
        <?php elseif ( '' !== trim( $query ) ) : ?>
            <?php include MXP_LOCAL_SEARCH_DIR . 'templates/search-results.php'; ?>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    public function render_related_block( array $attributes = array(), string $content = '', ?WP_Block $block = null ): string {
        $post_id = absint( $attributes['postId'] ?? 0 );
        if ( ! $post_id && $block instanceof WP_Block ) {
            $post_id = absint( $block->context['postId'] ?? 0 );
        }

        return $this->render_related_shortcode(
            array(
                'limit'   => absint( $attributes['limit'] ?? 5 ),
                'mode'    => sanitize_key( (string) ( $attributes['mode'] ?? '' ) ),
                'post_id' => $post_id,
                'title'   => (string) ( $attributes['title'] ?? __( 'Related articles', 'mxp-local-search' ) ),
            )
        );
    }


    public function render_related_shortcode( array $atts = array() ): string {
        $atts = shortcode_atts(
            array(
                'limit'   => 5,
                'mode'    => '',
                'post_id' => 0,
                'title'   => __( 'Related articles', 'mxp-local-search' ),
            ),
            $atts,
            'mxp_related'
        );

        $post_id = absint( $atts['post_id'] );
        if ( ! $post_id ) {
            $post_id = get_the_ID();
        }
        if ( ! $post_id ) {
            return '';
        }

        $result = $this->search_handler->related_posts(
            $post_id,
            array(
                'limit' => absint( $atts['limit'] ),
                'mode'  => sanitize_key( (string) $atts['mode'] ),
            )
        );
        if ( is_wp_error( $result ) || empty( $result ) ) {
            return '';
        }

        ob_start();
        ?>
        <aside class="mxp-local-search-related">
            <?php if ( '' !== (string) $atts['title'] ) : ?>
                <h2><?php echo esc_html( (string) $atts['title'] ); ?></h2>
            <?php endif; ?>
            <ul>
                <?php foreach ( $result as $row ) : ?>
                    <li><a href="<?php echo esc_url( (string) ( $row['permalink'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $row['title'] ?? '' ) ); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </aside>
        <?php
        return (string) ob_get_clean();
    }

    public static function activate(): void {
        $role = get_role( 'administrator' );
        if ( $role && ! $role->has_cap( MXP_LOCAL_SEARCH_CAPABILITY ) ) {
            $role->add_cap( MXP_LOCAL_SEARCH_CAPABILITY );
        }
        if ( ! wp_next_scheduled( 'mxp_search_index_all_event', array( array( 'post_type' => '', 'batch' => 50 ) ) ) ) {
            wp_schedule_single_event( time() + 5, 'mxp_search_index_all_event', array( array( 'post_type' => '', 'batch' => 50 ) ) );
        }
    }

    public static function deactivate(): void {
        wp_unschedule_hook( 'mxp_search_index_all_event' );
        wp_unschedule_hook( 'mxp_search_config_reindex_event' );
        delete_transient( 'mxp_search_write_lock' );
    }

    public static function extension_available(): bool {
        return extension_loaded( 'mxp_search' ) && class_exists( '\\MXP\\Search\\Store' );
    }

    public function extension_missing_notice(): void {
        if ( ! current_user_can( MXP_LOCAL_SEARCH_CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( self::extension_available() ) {
            return;
        }

        echo '<div class="notice notice-error"><p>' . esc_html__( 'MXP Local Search requires the mxp_search PHP extension. Indexing, rebuilds, and search fail closed until the extension is installed and loaded.', 'mxp-local-search' ) . '</p></div>';
    }
}

register_activation_hook( __FILE__, array( 'MXP_Local_Search_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MXP_Local_Search_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'MXP_Local_Search_Plugin', 'instance' ) );
