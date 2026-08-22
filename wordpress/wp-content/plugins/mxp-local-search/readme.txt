=== MXP Local Search ===
Contributors: mxp
Tags: search, semantic search, local search, full-text search
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 0.1.1
License: MIT
License URI: https://opensource.org/licenses/MIT

MXP Local Search integrates WordPress with the mxp_search PHP extension. It fails closed when the extension is unavailable.

== Settings ==

Open Tools > MXP Local Search.

= Post types =
Select which public post types are indexed. WooCommerce products appear when WooCommerce is active and registers the product post type. Public products can index SKU, price, stock status, attributes, taxonomy terms, and explicitly allowlisted custom fields. Customer and order data are not indexed.

= Search mode =
fast uses SQLite full-text search. semantic uses vectors. hybrid combines full-text and vectors. deep adds reranking only when the native extension supports it.

= Chunk strategy =
Controls how posts are split before indexing: smart, paragraph, heading, or fixed. Changing this schedules a background reindex.

= Custom fields allowlist =
Comma-separated post meta keys to index. Keep this explicit; sensitive-looking keys such as secret, token, password, email, and phone are skipped.

= Taxonomies =
Indexes categories, tags, product attributes, and other taxonomy terms for selected post types.

= Comments =
Indexes approved comments. Disabled by default because commenter content may appear in search.

= Built-in WordPress search =
Disabled by default. When enabled, the public WordPress search results page is replaced with MXP Local Search results. Leave disabled while configuring, testing, or temporarily using normal WordPress search. Shortcodes, REST search, and WP-CLI search still work while this is disabled.
If WP-Cron is disabled or unreliable, use Run Scheduled MXP Jobs Now on the settings page. It manually runs pending MXP index and settings-reindex jobs without running unrelated WordPress cron jobs.

The post, page, and product list tables include an MXP index column showing whether each item is indexed, excluded, not indexed yet, or not indexable. The same column includes a direct Reindex now button for one-off external reindexing; when another MXP write job is running, the reindex is queued and shown as an informational notice instead of a blocking error.


= Per-post indexing =
Administrators get an MXP Local Search Indexing box in the post editor. Use it to exclude one post from the index or reindex one post immediately. Global post type settings, publish status, password protection, and public visibility still apply.

= Related articles block =
Use the MXP Related Articles block in the block editor instead of hand-writing the mxp_related shortcode. The block sidebar controls the heading, number of articles, search mode, and optional source post ID while the front end renders dynamically from the MXP Local Search index.

== Internationalization ==

The plugin loads the mxp-local-search text domain from languages/. Traditional Chinese is bundled as mxp-local-search-zh_TW.po and mxp-local-search-zh_TW.mo.
