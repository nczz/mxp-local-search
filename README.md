# MXP Local Search

Open-source local search engine for WordPress and PHP applications. Rust core + PHP extension.

## What is this?

A semantic search engine that runs entirely on your server — no cloud APIs, no external databases, no per-token costs. Designed as a PHP extension for WordPress and other PHP applications.

## Key Features

- **Local embedding path**: optional `embedding-onnx` feature loads local `model.onnx` + HuggingFace `tokenizer.json` bundles through ONNX Runtime
- **Hybrid search foundation**: SQLite FTS5 plus persisted exact vector search and weighted score fusion; HNSW/usearch remains a planned acceleration layer
- **Multi-KB**: Multiple knowledge bases, cross-KB search with stable KB IDs and per-KB weights
- **Semantic incremental update**: Reuse vectors when meaning is unchanged; always refresh content, metadata, ACL, and FTS rows
- **Controlled deployment**: Native PHP extension plus a verified model/runtime bundle; dynamic ONNX Runtime builds must ship and check the matching shared library explicitly
- **WordPress content integration**: built-in search replacement, related-content shortcode, public WooCommerce product metadata, and locale-aware multilingual hooks

## Architecture

See [docs/architecture.md](docs/architecture.md)

## Status

MVP implementation is present:

- Rust workspace with `mxp-search-core` and `mxp_search` PHP extension crate
- SQLite-backed KB create/open/destroy/stats/index/update/delete/search
- Safe FTS5 full-text search with typed metadata filters and public-content filtering support
- WordPress plugin under `wordpress/wp-content/plugins/mxp-local-search`, including `[mxp_search]`, `[mxp_related]`, product metadata extraction, and Polylang/WPML-compatible locale metadata hooks
- DDEV WordPress environment for local smoke testing
- Semantic/hybrid modes are available only in builds compiled with `embedding-onnx`; default MVP builds fail closed for those modes

## Verified DDEV runtime

The local WordPress smoke environment is DDEV-backed and expects the PHP extension to be installed inside the web container, not loaded from the project tree:

```bash
scripts/ddev-install-onnxruntime.sh
scripts/ddev-install-model-bundle.sh
scripts/ddev-install-extension.sh
ddev restart
scripts/verify-model-bundle.sh
ddev exec 'php scripts/php-semantic-smoke.php'
```

The DDEV post-start hook mirrors the install contract:

- `mxp_search.so` is copied to PHP's extension directory as root-owned `0755`
- `/var/lib/mxp-local-search/kb` and `/var/lib/mxp-local-search/export` are writable by the web user
- `/var/lib/mxp-local-search/models` is root-owned/read-only for the web user
- `.ddev/docker-compose.mxp-local-search.yaml` mounts `/var/lib/mxp-local-search` on a named Docker volume so installed models and KB state survive container replacement
- PHP INI pins `mxp_search.store_root`, `mxp_search.export_root`, `mxp_search.model_dir`, `mxp_search.allowed_models`, and public query limits

## Model bundle workflow

The supported local model bundle is `multilingual-e5-small` from `intfloat/multilingual-e5-small`, pinned by revision and verified by `manifest.json` file hashes before embedder startup.

scripts/install-model-bundle.sh            # Ubuntu/CI host
scripts/ddev-install-model-bundle.sh       # local DDEV WordPress gate
scripts/verify-model-bundle.sh
```

Verification checks:

- required files: `model.onnx`, `tokenizer.json`, `manifest.json`
- manifest `id`, positive `dimensions`, per-file `sha256`, and optional `size_bytes`
- PHP extension can load the bundle and returns 384-dimensional query/document embeddings


## Release artifact workflow

Release packages are machine-verifiable and platform-specific. GitHub releases build separate Linux `x86_64` and `aarch64` PHP extension artifacts for PHP 8.1, 8.2, 8.3, and 8.4; each native `.so` is valid only for the artifact's recorded PHP API, CPU architecture, libc-compatible Linux runtime, and ONNX Runtime shared library.

```bash
scripts/build-release-artifacts.sh
scripts/verify-release-artifacts.sh
scripts/install-release-artifacts.sh
scripts/release-upgrade-smoke.sh
```

The build writes `release/dist/<extension-version>-phpapi<api>-linux-<arch>/` with independently versioned components:

- `mxp_search-<extension-version>-phpapi<api>-linux-<arch>.so`
- `mxp-local-search-wp-plugin-<plugin-version>.zip`
- `mxp-local-search-<extension-version>-manifest.json`
- `SHA256SUMS`
- optional checksum-pinned `mxp-local-search-model-multilingual-e5-small-<revision>.tar.gz`

The PHP extension, `mxp-search-core`, and WordPress plugin each keep their own version. The release manifest records all three under `components`; only the WordPress plugin header and `MXP_LOCAL_SEARCH_VERSION` constant must match each other.

Local builds are marked `unsigned-local`; checksums are not signatures. Formal stable releases need detached signatures or an equivalent signing/attestation step before distribution.

Use `scripts/release-ci.sh` for the local release gate before version bumps or publishable tags: Rust fmt/tests, release artifact build/verify/install, PHP lint, PHP extension contract smoke, WordPress regression smoke, security probes, and performance baseline. GitHub CI/release jobs use `MXP_RELEASE_BUILD_ENV=host MXP_RELEASE_CI_SCOPE=artifacts` to build and verify distributable PHP extension artifacts directly on the target Ubuntu/PHP matrix without DDEV.

### Ubuntu install paths

There are two supported paths today:

1. **Use GitHub release artifacts** for production-like installs. Pick the `.so` whose filename matches the target host's `php-config --phpapi` and CPU architecture (`x86_64` or `aarch64`). The `.so` is not universal across PHP minor/API versions.
2. **Build on the target Ubuntu host** when no matching artifact exists. Install native build dependencies plus `php-config`, then run the host artifact gate. DDEV remains the required local WordPress collaboration gate before version bumps, not the GitHub/native extension build environment.

For direct installs, install the matching ONNX Runtime shared library and `multilingual-e5-small` model bundle first, then copy the release `.so` into `php-config --extension-dir`, create an `mxp_search.ini`, enable it for CLI/FPM, install the `mxp-local-search` WordPress plugin zip, and verify with:

```bash
php -r 'var_dump(extension_loaded("mxp_search"));'
wp plugin activate mxp-local-search
```

Detailed server commands live in [docs/release.md](docs/release.md#ubuntu-server-install-or-build).


Rollback preserves KB/model data by default:

```bash
scripts/rollback-release.sh
```

Destructive cleanup requires `--purge-data I_UNDERSTAND_DELETE_MXP_LOCAL_SEARCH_DATA`.


## WordPress plugin settings

The WordPress admin page is **Tools → MXP Local Search**.

| Setting | Default | Meaning | Reindex needed |
|---|---:|---|---|
| Post types | `post`, `page` | Public post types to index. WooCommerce `product` appears when WooCommerce registers it. Product indexing is limited to public products and includes SKU, price, stock status, attributes, taxonomies, and allowlisted custom fields. | Yes |
| Search mode | `fast` | `fast` = SQLite FTS; `semantic` = vector search; `hybrid` = FTS + vector fusion; `deep` = reranker when the native extension supports it. Builds without ONNX/reranker fail closed or downgrade unsupported modes. | No |
| Chunk strategy | `smart` | How content is split before indexing: `smart` keeps headings and nearby paragraphs together, `paragraph` splits on paragraph boundaries, `heading` starts chunks at headings, and `fixed` uses fixed-size text windows for unstructured content. | Yes |
| Custom fields allowlist | empty | Comma-separated post meta keys to index. Sensitive-looking keys such as `secret`, `token`, `password`, `email`, and `phone` are skipped even if listed. | Yes |
| Taxonomies | on | Index categories, tags, product attributes, and other taxonomy terms for selected post types. | Yes |
| Comments | off | Index approved comments. Leave off unless commenter content is expected to be searchable. | Yes |
| Built-in WordPress search | off | Replaces the public `/?s=...` search results page with MXP Local Search results. Off by default so activation and indexing do not take over native WordPress search until an admin explicitly enables it. Shortcodes, REST, and WP-CLI search still work while this is off. | No |
| Public limit | `20` | Maximum anonymous REST/search-result count. Configured in option/INI; not exposed in the basic UI. | No |
| Authenticated limit | `50` | Maximum logged-in/API search-result count. Configured in option/INI; not exposed in the basic UI. | No |
| Candidate limit | `500` | Maximum over-fetch pool before filtering/fusion. Higher values can improve recall and increase CPU/latency. | No |
| Query byte limit | `2048` | Maximum query length in bytes. | No |
| Batch size | `50` | Posts processed per background indexing batch. | No |
| Stale delete ceiling | `1000` | Safety cap for deleting stale chunks after config changes. | No |
| Default model | `multilingual-e5-small` | Embedding model ID. Must match an allowlisted, installed model bundle. | Reindex if changed by privileged config |
| Allowed models | `multilingual-e5-small` | Model allowlist for trusted deployments. | No |
| Store root | INI/default | Filesystem root for KB data. DDEV pins this under `/var/lib/mxp-local-search/kb`; production should keep it outside the docroot. | No |
| Export root | INI/default | Filesystem root for import/export paths. Keep outside the docroot. | No |
| Manual scheduled jobs | button | Runs pending MXP WP-Cron jobs immediately from the admin screen. Use when WP-Cron is disabled, delayed, or blocked by the host. | Runs already scheduled index/reindex jobs |

Per-post controls appear in the editor sidebar and list tables for administrators:

- **MXP index** column appears in post/page/product list tables so editors can see whether each item is indexed, excluded, not indexed yet, or not indexable. It also includes a direct **Reindex now** button for one-off external reindexing without opening the editor; if another MXP write job is running, the post is queued and the notice explains that it will run later instead of showing a blocking error.
- **Exclude this post from MXP Local Search** stores `_mxp_search_exclude=1`; saving or reindexing removes existing chunks.
- **Reindex this post now** in the editor sidebar refreshes one post immediately and shows an admin notice. Global post type settings, publish status, password protection, and public visibility still apply.
- **Run Scheduled MXP Jobs Now** on the settings page manually executes pending MXP scheduled jobs when WP-Cron is unavailable. WP-CLI equivalent: `wp cron event run mxp_search_index_all_event mxp_search_config_reindex_event` for hosts that allow WP-CLI cron execution; the admin button is safer for non-technical operators because it only runs MXP jobs.

Content authors can insert related articles either as `[mxp_related]` shortcode or as the **MXP Related Articles** block in the block editor. The block exposes heading, result count, search mode, and optional source post ID controls in the sidebar, then renders dynamically from the MXP Local Search index on the front end.

## Internationalization

The plugin loads the `mxp-local-search` text domain from `languages/`. A bundled Traditional Chinese translation is included as `mxp-local-search-zh_TW.po` and `mxp-local-search-zh_TW.mo`.

## Documentation

- [PLAN.md](PLAN.md) — Project plan and development phases
- [docs/architecture.md](docs/architecture.md) — System architecture
- [docs/php-api.md](docs/php-api.md) — PHP extension API specification
- [docs/wp-plugin-spec.md](docs/wp-plugin-spec.md) — WordPress plugin integration spec
- [docs/research.md](docs/research.md) — Research notes and decisions
- [docs/release.md](docs/release.md) — Release packaging, verification, install, and rollback
- [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) — Runtime/model/dependency license notices

## License

MXP Local Search is released under the MIT License. See [LICENSE](LICENSE).
