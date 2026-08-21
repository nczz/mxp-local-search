<?php


function mxp_wp_require(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function mxp_wp_error_message($value): string
{
    if (!is_wp_error($value)) {
        return '';
    }
    $data = $value->get_error_data();
    return $value->get_error_code() . ': ' . $value->get_error_message() . (null === $data ? '' : ' data=' . wp_json_encode($data));
}

function mxp_wp_require_not_error($value, string $message): void
{
    mxp_wp_require(!is_wp_error($value), $message . (is_wp_error($value) ? ' (' . mxp_wp_error_message($value) . ')' : ''));
}


function mxp_search_rest(string $query, string $mode = 'hybrid', int $limit = 10): array
{
    $request = new WP_REST_Request('GET', '/mxp-search/v1/search');
    $request->set_query_params(['q' => $query, 'mode' => $mode, 'limit' => $limit]);
    $response = rest_do_request($request);
    mxp_wp_require($response->get_status() === 200, "REST search {$mode} status {$response->get_status()} data=" . wp_json_encode($response->get_data()));
    $data = $response->get_data();
    return is_array($data['results'] ?? null) ? $data['results'] : [];
}

function mxp_titles(array $results): array
{
    return array_map(static fn($row) => (string) ($row['title'] ?? ''), $results);
}

function mxp_contains_title(array $results, string $needle): bool
{
    foreach (mxp_titles($results) as $title) {
        if (str_contains($title, $needle)) {
            return true;
        }
    }
    return false;
}

function mxp_clear_write_lock(): void
{
    delete_transient('mxp_search_write_lock');
    wp_cache_delete('mxp_search_write_lock', 'transient');
    delete_option('_transient_mxp_search_write_lock');
    delete_option('_transient_timeout_mxp_search_write_lock');
}

function mxp_index_fixture_post(int $post_id): array|WP_Error
{
    $result = null;
    for ($attempt = 0; $attempt < 5; ++$attempt) {
        mxp_clear_write_lock();
        $result = MXP_Local_Search_Plugin::instance()->index_manager->index_post($post_id, true);
        if (!is_wp_error($result) || 'mxp_search_write_locked' !== $result->get_error_code()) {
            return $result;
        }
        usleep(200000);
    }
    return $result;
}

function mxp_delete_fixture_chunks(int $post_id): int|WP_Error
{
    $result = null;
    for ($attempt = 0; $attempt < 5; ++$attempt) {
        mxp_clear_write_lock();
        $result = MXP_Local_Search_Plugin::instance()->index_manager->delete_post_chunks($post_id, get_post($post_id) ?: null);
        if (!is_wp_error($result) || 'mxp_search_write_locked' !== $result->get_error_code()) {
            return $result;
        }
        usleep(200000);
    }
    return $result;
}

function mxp_main_search_replaced(string $query_text): bool
{
    $query = new WP_Query();
    $GLOBALS['wp_query'] = $query;
    $GLOBALS['wp_the_query'] = $query;
    $query->query(['s' => $query_text, 'posts_per_page' => 5]);
    $replaced = (bool) $query->get('mxp_local_search_replaced');
    wp_reset_query();
    return $replaced;
}


function mxp_next_scheduled_hook(string $hook): int|false
{
    foreach (_get_cron_array() as $timestamp => $hooks) {
        if (isset($hooks[$hook])) {
            return (int) $timestamp;
        }
    }
    return false;
}
function mxp_hook_contains_method(string $hook, string $method): bool
{
    global $wp_filter;
    if (!isset($wp_filter[$hook])) {
        return false;
    }
    foreach ($wp_filter[$hook]->callbacks as $callbacks) {
        foreach ($callbacks as $callback) {
            $function = $callback['function'] ?? null;
            if (is_array($function) && ($function[1] ?? null) === $method) {
                return true;
            }
        }
    }
    return false;
}



function mxp_run_scheduled_config_reindex(): void
{
    $timestamp = mxp_next_scheduled_hook('mxp_search_config_reindex_event');
    mxp_wp_require(false !== $timestamp, 'config reindex scheduled');
    $events = _get_cron_array();
    $hook_events = $events[$timestamp]['mxp_search_config_reindex_event'] ?? [];
    wp_unschedule_hook('mxp_search_config_reindex_event');
    foreach ($hook_events as $event) {
        do_action_ref_array('mxp_search_config_reindex_event', $event['args']);
    }
}

function mxp_create_post(string $title, string $content, string $status = 'publish', string $type = 'post', string $password = ''): int
{
    $id = wp_insert_post([
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => $status,
        'post_type' => $type,
        'post_password' => $password,
    ], true);
    mxp_wp_require(!is_wp_error($id) && $id > 0, "create post {$title}");
    return (int) $id;
}

function mxp_delete_fixture_posts(): void
{
    $query = new WP_Query([
        'post_type' => ['post', 'page', 'mxp_smoke_item', 'product'],
        'post_status' => ['publish', 'draft', 'private', 'trash', 'pending'],
        'posts_per_page' => 200,
        's' => 'MXP Release Smoke',
        'fields' => 'ids',
    ]);
    foreach ($query->posts as $id) {
        wp_delete_post((int) $id, true);
    }
}

mxp_wp_require(function_exists('is_plugin_active'), 'WordPress admin plugin API loaded');
mxp_wp_require(is_plugin_active('mxp-local-search/mxp-local-search.php'), 'mxp-local-search plugin active');
if (!post_type_exists('mxp_smoke_item')) {
    register_post_type('mxp_smoke_item', [
        'label' => 'MXP Smoke Items',
        'public' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'excerpt', 'custom-fields'],
    ]);
}

mxp_wp_require(extension_loaded('mxp_search'), 'mxp_search extension loaded in WordPress PHP');
mxp_wp_require(post_type_exists('mxp_smoke_item'), 'smoke custom post type registered');
if (!post_type_exists('product')) {
    register_post_type('product', [
        'label' => 'Products',
        'public' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'excerpt', 'custom-fields'],
    ]);
}

do_action('rest_api_init');
wp_set_current_user(1);
mxp_wp_require(current_user_can('manage_mxp_search'), 'admin has manage_mxp_search capability');

$normalizedLatePostType = MXP_Local_Search_Plugin::instance()->config->normalize_settings([
    'post_types' => ['post', 'mxp_late_registered_type'],
]);
mxp_wp_require(in_array('mxp_late_registered_type', $normalizedLatePostType['post_types'], true), 'late-registered post type setting is preserved');
mxp_wp_require(MXP_Local_Search_Plugin::instance()->config->get('replace_native_search') === false, 'built-in search replacement defaults off');

delete_transient('mxp_search_write_lock');
mxp_clear_write_lock();
wp_unschedule_hook('mxp_search_config_reindex_event');


mxp_delete_fixture_posts();
MXP_Local_Search_Plugin::instance()->config->update([
    'kb_mode' => 'single',
    'post_types' => ['post', 'page', 'mxp_smoke_item', 'product'],
    'search_mode' => 'hybrid',
    'chunk_strategy' => 'smart',
    'custom_fields' => ['mxp_release_field'],
    'include_taxonomies' => true,
    'include_comments' => false,
    'max_public_limit' => 5,
    'max_auth_limit' => 10,
    'max_candidate_limit' => 100,
    'max_query_bytes' => 2048,
    'batch_size' => 10,
    'stale_delete_ceiling' => 1000,
    'default_model' => 'multilingual-e5-small',
    'allowed_models' => ['multilingual-e5-small'],
]);
wp_unschedule_hook('mxp_search_config_reindex_event');

$publicPost = mxp_create_post('MXP Release Smoke Public Post', 'MXP Release Smoke public semantic ONNX hybrid search content with visible snippet and score.', 'publish', 'post');
$publicPage = mxp_create_post('MXP Release Smoke Public Page', 'MXP Release Smoke page content validates page post type indexing.', 'publish', 'page');
$custom = mxp_create_post('MXP Release Smoke Custom Type', 'MXP Release Smoke custom post type content validates configured post type indexing.', 'publish', 'mxp_smoke_item');
$draft = mxp_create_post('MXP Release Smoke Draft Secret', 'MXP Release Smoke draft private hidden leakage token.', 'draft');
$private = mxp_create_post('MXP Release Smoke Private Secret', 'MXP Release Smoke private hidden leakage token.', 'private');
$password = mxp_create_post('MXP Release Smoke Password Secret', 'MXP Release Smoke password hidden leakage token.', 'publish', 'post', 'secret');
$trash = mxp_create_post('MXP Release Smoke Trash Secret', 'MXP Release Smoke trash hidden leakage token.', 'publish');
wp_trash_post($trash);
update_post_meta($publicPost, 'mxp_release_field', 'custom field indexed marker');
$product = mxp_create_post('MXP Release Smoke Product', 'MXP Release Smoke product catalog content.', 'publish', 'product');
update_post_meta($product, '_sku', 'MXP-SMOKE-SKU-001');
update_post_meta($product, '_price', '123.45');
update_post_meta($product, '_stock_status', 'instock');

$indexRequest = new WP_REST_Request('POST', '/mxp-search/v1/index-all');
$indexRequest->set_header('x-wp-nonce', wp_create_nonce('wp_rest'));
$indexRequest->set_body_params(['post_type' => '', 'batch' => 25]);
$indexResponse = rest_do_request($indexRequest);
mxp_wp_require($indexResponse->get_status() === 200, 'REST index-all scheduling succeeds');
$indexData = $indexResponse->get_data();
do_action('mxp_search_index_all_event', ['post_type' => '', 'batch' => 25]);
wp_unschedule_hook('mxp_search_index_all_event');
echo 'rest_index_all_status=200 scheduled=' . ( ! empty($indexData['scheduled']) ? '1' : '0' ) . "\n";

foreach (['fast', 'semantic', 'hybrid'] as $mode) {
    $results = mxp_search_rest('MXP Release Smoke public semantic ONNX hybrid', $mode, 5);
    mxp_wp_require(count($results) >= 1, "{$mode} has results");
    mxp_wp_require(mxp_contains_title($results, 'Public'), "{$mode} includes public post/page");
    foreach (mxp_titles($results) as $title) {
        mxp_wp_require(!str_contains($title, 'Draft') && !str_contains($title, 'Private') && !str_contains($title, 'Password') && !str_contains($title, 'Trash'), "{$mode} excludes non-public title {$title}");
    }
    $top = $results[0];
    mxp_wp_require(isset($top['score'], $top['snippet'], $top['permalink']), "{$mode} response fields");
    echo "rest_search_{$mode}_ok count=" . count($results) . ' top="' . $top['title'] . '" score=' . $top['score'] . "\n";
}
$commentId = wp_insert_comment([
    'comment_post_ID' => $publicPost,
    'comment_content' => 'MXP Release Smoke comment toggle indexed token.',
    'comment_approved' => 1,
    'comment_author' => 'MXP Smoke',
    'comment_author_email' => 'mxp-smoke@example.test',
    'user_id' => 1,
]);
mxp_wp_require((int) $commentId > 0, 'approved comment fixture created');
clean_comment_cache((int) $commentId);
clean_post_cache($publicPost);
wp_update_comment_count_now($publicPost);
$commentIds = get_comments(['post_id' => $publicPost, 'status' => 'approve', 'fields' => 'ids']);
mxp_wp_require(in_array((int) $commentId, array_map('intval', $commentIds), true), 'approved comment fixture is queryable');
MXP_Local_Search_Plugin::instance()->config->update([
    'post_types' => ['post', 'page', 'mxp_smoke_item', 'product'],
    'custom_fields' => ['mxp_release_field'],
    'include_comments' => true,
    'include_taxonomies' => true,
    'search_mode' => 'hybrid',
    'batch_size' => 10,
]);
$timestamp = mxp_next_scheduled_hook('mxp_search_config_reindex_event');
mxp_wp_require(false !== $timestamp, 'first config update schedules reindex');
mxp_run_scheduled_config_reindex();
mxp_clear_write_lock();
$commentDeleted = mxp_delete_fixture_chunks($publicPost);
mxp_wp_require_not_error($commentDeleted, 'comment-enabled post cleanup completes');
$commentIndex = mxp_index_fixture_post($publicPost);
mxp_wp_require_not_error($commentIndex, 'comment-enabled post reindex completes');
$commentResults = mxp_search_rest('comment toggle indexed token', 'fast', 5);
mxp_wp_require(mxp_contains_title($commentResults, 'Public'), 'comment-enabled reindex indexes comments');
MXP_Local_Search_Plugin::instance()->config->update([
    'post_types' => ['post', 'page', 'mxp_smoke_item', 'product'],
    'custom_fields' => ['mxp_release_field'],
    'include_comments' => false,
    'include_taxonomies' => true,
    'search_mode' => 'hybrid',
    'batch_size' => 10,
]);
mxp_wp_require(false !== mxp_next_scheduled_hook('mxp_search_config_reindex_event'), 'second config update schedules reindex');
mxp_run_scheduled_config_reindex();
echo "config_update_reindex_ok\n";

MXP_Local_Search_Plugin::instance()->config->update(['replace_native_search' => false]);
mxp_wp_require(!mxp_main_search_replaced('MXP Release Smoke Public Post'), 'built-in search replacement remains off until explicitly enabled');
MXP_Local_Search_Plugin::instance()->config->update(['replace_native_search' => true]);
mxp_wp_require(mxp_main_search_replaced('MXP Release Smoke Public Post'), 'built-in search replacement can be enabled explicitly');
MXP_Local_Search_Plugin::instance()->config->update(['replace_native_search' => false]);
echo "native_search_takeover_toggle_ok\n";

$productResults = mxp_search_rest('MXP-SMOKE-SKU-001', 'fast', 5);
mxp_wp_require(mxp_contains_title($productResults, 'Product'), 'WooCommerce product SKU metadata is searchable');
echo "woocommerce_product_support_ok\n";

$singlePost = mxp_create_post('MXP Release Smoke Single Post Control', 'MXP Release Smoke single post include exclude reindex token.', 'publish', 'post');
$singleIndexed = mxp_index_fixture_post($singlePost);
mxp_wp_require(!is_wp_error($singleIndexed), 'single post initial index succeeds');
$singleResults = mxp_search_rest('single post include exclude reindex token', 'fast', 5);
mxp_wp_require(mxp_contains_title($singleResults, 'Single Post Control'), 'single post appears after manual reindex');
update_post_meta($singlePost, '_mxp_search_exclude', '1');
$excludedResult = mxp_index_fixture_post($singlePost);
mxp_wp_require(!is_wp_error($excludedResult) && ($excludedResult['status'] ?? '') === 'deleted_non_indexable', 'single post exclude removes index chunks');
$excludedResults = mxp_search_rest('single post include exclude reindex token', 'fast', 5);
mxp_wp_require(!mxp_contains_title($excludedResults, 'Single Post Control'), 'excluded single post disappears from search');
delete_post_meta($singlePost, '_mxp_search_exclude');
$reindexedResult = mxp_index_fixture_post($singlePost);
mxp_wp_require(!is_wp_error($reindexedResult) && ($reindexedResult['status'] ?? '') === 'indexed', 'single post can be reindexed after exclusion removed');
$pluginForMetaBox = MXP_Local_Search_Plugin::instance();
$adminForMetaBox = new MXP_Local_Search_Admin($pluginForMetaBox->config, $pluginForMetaBox->kb_manager, $pluginForMetaBox->index_manager, $pluginForMetaBox->search_handler);
ob_start();
$adminForMetaBox->render_index_meta_box(get_post($singlePost));
$metaBoxHtml = ob_get_clean();
mxp_wp_require((str_contains($metaBoxHtml, 'Exclude this post from MXP Local Search') || str_contains($metaBoxHtml, '從 MXP 本機搜尋排除此文章')) && (str_contains($metaBoxHtml, 'Reindex this post now') || str_contains($metaBoxHtml, '立即重新索引此文章')), 'single post editor controls render');
$columns = $adminForMetaBox->add_index_status_column(['cb' => '<input />', 'title' => 'Title']);
mxp_wp_require(isset($columns['mxp_search_index']), 'MXP index column is registered');
ob_start();
$adminForMetaBox->render_index_status_column('mxp_search_index', $singlePost);
$statusColumnHtml = ob_get_clean();
mxp_wp_require(str_contains($statusColumnHtml, 'Indexed') || str_contains($statusColumnHtml, '已索引'), 'single post list table status renders indexed state');
mxp_wp_require((substr_count($statusColumnHtml, 'Indexed') + substr_count($statusColumnHtml, '已索引')) === 1, 'single post list table status renders once');
mxp_wp_require(str_contains($statusColumnHtml, 'mxp_search_reindex_post') && str_contains($statusColumnHtml, 'redirect_to='), 'single post list table reindex button renders');
mxp_wp_require(!mxp_hook_contains_method('manage_posts_custom_column', 'render_index_status_column') && !mxp_hook_contains_method('manage_pages_custom_column', 'render_index_status_column'), 'generic list table hooks do not duplicate MXP status output');
update_post_meta($singlePost, '_mxp_search_exclude', '1');
ob_start();
$adminForMetaBox->render_index_status_column('mxp_search_index', $singlePost);
$excludedColumnHtml = ob_get_clean();
mxp_wp_require(str_contains($excludedColumnHtml, 'Excluded') || str_contains($excludedColumnHtml, '已排除'), 'single post list table status renders excluded state');
delete_post_meta($singlePost, '_mxp_search_exclude');
ob_start();
$adminForMetaBox->render_dashboard();
$dashboardHtml = ob_get_clean();
mxp_wp_require(str_contains($dashboardHtml, 'mxp_search_run_scheduled') && !preg_match("/<input[^>]+type=[\"']submit[\"'][^>]+disabled/i", $dashboardHtml), 'manual scheduled jobs action is visible and clickable');
wp_schedule_single_event(time() + DAY_IN_SECONDS, 'mxp_search_index_all_event', [['post_type' => 'post', 'batch' => 10]]);
$manualSummary = $adminForMetaBox->run_scheduled_jobs();
mxp_wp_require(($manualSummary['index_all'] ?? 0) >= 1 && false === mxp_next_scheduled_hook('mxp_search_index_all_event'), 'manual scheduled jobs runner executes and clears MXP index event');
echo "single_post_index_controls_ok\n";

$relatedHtml = do_shortcode('[mxp_related post_id="' . $publicPost . '" limit="5"]');
mxp_wp_require(str_contains($relatedHtml, 'MXP Release Smoke Public Page'), 'related shortcode renders indexed related page');
mxp_wp_require(WP_Block_Type_Registry::get_instance()->is_registered('mxp-local-search/related-posts'), 'related articles block is registered');
$relatedBlockHtml = render_block([
    'blockName' => 'mxp-local-search/related-posts',
    'attrs' => [
        'postId' => $publicPost,
        'limit' => 5,
        'mode' => '',
        'title' => 'Related block smoke',
    ],
]);
mxp_wp_require(str_contains($relatedBlockHtml, 'Related block smoke') && str_contains($relatedBlockHtml, 'MXP Release Smoke Public Page'), 'related articles block renders indexed related page');
echo "related_block_ok\n";
echo "related_shortcode_ok\n";

mxp_clear_write_lock();
$englishLocale = mxp_create_post('MXP Release Smoke English Locale', 'MXP Release Smoke shared locale token.', 'publish', 'post');
$japaneseLocale = mxp_create_post('MXP Release Smoke Japanese Locale', 'MXP Release Smoke shared locale token.', 'publish', 'post');
add_filter('mxp_local_search_post_locale', static function ($locale, WP_Post $post) use ($englishLocale, $japaneseLocale) {
    if ($post->ID === $englishLocale) {
        return 'en_US';
    }
    if ($post->ID === $japaneseLocale) {
        return 'ja_JP';
    }
    return $locale;
}, 10, 2);
$englishIndexed = mxp_index_fixture_post($englishLocale);
$japaneseIndexed = mxp_index_fixture_post($japaneseLocale);
if (is_wp_error($englishIndexed)) {
    fwrite(STDERR, 'English locale index failed: ' . $englishIndexed->get_error_code() . ' ' . $englishIndexed->get_error_message() . "\n");
}
if (is_wp_error($japaneseIndexed)) {
    fwrite(STDERR, 'Japanese locale index failed: ' . $japaneseIndexed->get_error_code() . ' ' . $japaneseIndexed->get_error_message() . "\n");
}
mxp_wp_require(!is_wp_error($englishIndexed), 'English locale post indexed');
mxp_wp_require(!is_wp_error($japaneseIndexed), 'Japanese locale post indexed');
add_filter('mxp_local_search_public_locale', static fn() => 'ja_JP');
$localeResults = mxp_search_rest('shared locale token', 'fast', 5);
mxp_wp_require(mxp_contains_title($localeResults, 'Japanese Locale'), 'multilingual locale filter includes current language');
mxp_wp_require(!mxp_contains_title($localeResults, 'English Locale'), 'multilingual locale filter excludes other language');
remove_all_filters('mxp_local_search_public_locale');
remove_all_filters('mxp_local_search_post_locale');
echo "multilingual_locale_support_ok\n";

$deep = new WP_REST_Request('GET', '/mxp-search/v1/search');
$deep->set_query_params(['q' => 'MXP Release Smoke public', 'mode' => 'deep']);
$deepResponse = rest_do_request($deep);
echo 'rest_public_deep_status=' . $deepResponse->get_status() . "\n";
mxp_wp_require($deepResponse->get_status() === 403, 'public deep mode rejected');

$limit = new WP_REST_Request('GET', '/mxp-search/v1/search');
$limit->set_query_params(['q' => 'MXP Release Smoke public', 'mode' => 'hybrid', 'limit' => 500]);
$limitResponse = rest_do_request($limit);
$limitData = $limitResponse->get_data();
mxp_wp_require($limitResponse->get_status() === 200 && count($limitData['results'] ?? []) <= 20, 'public limit capped at configured/default max');
echo 'rest_public_limit_capped=' . count($limitData['results'] ?? []) . "\n";

$unknown = new WP_REST_Request('GET', '/mxp-search/v1/search');
$unknown->set_query_params(['q' => 'MXP Release Smoke public', 'mode' => 'hybrid', 'limit' => 5, 'evil' => 'x']);
$unknownResponse = rest_do_request($unknown);
mxp_wp_require($unknownResponse->get_status() === 200, 'public search ignores unrelated query arg safely');

$transition = mxp_create_post('MXP Release Smoke Transition', 'MXP Release Smoke transition lifecycle content.', 'publish');
MXP_Local_Search_Plugin::instance()->index_manager->index_post($transition, true);
mxp_wp_require(mxp_contains_title(mxp_search_rest('transition lifecycle content', 'hybrid', 5), 'Transition'), 'transition publish indexed');
wp_update_post(['ID' => $transition, 'post_status' => 'draft']);
mxp_wp_require(!mxp_contains_title(mxp_search_rest('transition lifecycle content', 'hybrid', 5), 'Transition'), 'publish to draft deletes chunks');
wp_update_post(['ID' => $transition, 'post_status' => 'publish']);
mxp_wp_require(mxp_contains_title(mxp_search_rest('transition lifecycle content', 'hybrid', 5), 'Transition'), 'draft to publish indexes chunks');
wp_update_post(['ID' => $transition, 'post_password' => 'hidden']);
mxp_wp_require(!mxp_contains_title(mxp_search_rest('transition lifecycle content', 'hybrid', 5), 'Transition'), 'password protected deletes chunks');
wp_update_post(['ID' => $transition, 'post_password' => '']);
mxp_wp_require(mxp_contains_title(mxp_search_rest('transition lifecycle content', 'hybrid', 5), 'Transition'), 'password removal reindexes chunks');
wp_trash_post($transition);
mxp_wp_require(!mxp_contains_title(mxp_search_rest('transition lifecycle content', 'hybrid', 5), 'Transition'), 'trash deletes chunks');
echo "transition_cleanup_ok\n";

$batchRequest = new WP_REST_Request('POST', '/mxp-search/v1/index-all');
$batchRequest->set_header('x-wp-nonce', wp_create_nonce('wp_rest'));
$cronArgs = ['post_type' => 'post', 'batch' => 2];
$batchRequest->set_body_params($cronArgs);
$batchResponse = rest_do_request($batchRequest);
$batchData = $batchResponse->get_data();
mxp_wp_require($batchResponse->get_status() === 200 && !empty($batchData['scheduled']), 'REST index-all schedules cron');
$timestamp = wp_next_scheduled('mxp_search_index_all_event', [$cronArgs]);
mxp_wp_require($timestamp !== false, 'cron event scheduled');
do_action('mxp_search_index_all_event', $cronArgs);
mxp_wp_require(get_transient('mxp_search_write_lock') === false, 'write lock cleared after cron');
echo 'background_index_ok scheduled=' . (int) $timestamp . ' write_lock=none' . "\n";

$stats = MXP_Local_Search_Plugin::instance()->kb_manager->stats();
mxp_wp_require(!is_wp_error($stats), 'stats available');
echo 'wp_regression_stats documents=' . (int) ($stats['document_count'] ?? 0) . ' chunks=' . (int) ($stats['chunk_count'] ?? 0) . ' vectors=' . (int) ($stats['vector_count'] ?? 0) . "\n";
mxp_delete_fixture_posts();
echo "wp_regression_smoke_ok\n";
