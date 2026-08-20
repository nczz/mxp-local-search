# MXP Local Search Release Process

This project is release-ready only when the generated artifact manifest, checksums, install smoke, WordPress regression smoke, security probes, browser smoke, and reviewer closure all pass from the same source tree.

## Supported matrix

| Component | Supported release target |
|---|---|
| PHP | GitHub releases build separate artifacts for PHP 8.1, 8.2, 8.3, and 8.4. Each artifact pins the exact PHP API number, for example `phpapi20230831`; do not load it into another PHP API. |
| WordPress | 6.4+ |
| OS | Linux only for packaged native extensions |
| CPU | `aarch64` and `x86_64`; each GitHub release builds and smokes both architectures on native runners |
| libc | Recorded in the release manifest; use only on compatible glibc systems. If in doubt, run `scripts/verify-release-artifacts.sh` and `scripts/install-release-artifacts.sh` on the target host. |
| Extension | `mxp_search` PHP extension, namespace `MXP\\Search` |
| WordPress plugin | `mxp-local-search` |
| REST prefix | `/wp-json/mxp-search/v1` |
| WP-CLI | `wp mxp-search` |

macOS and non-Linux builds are source/development targets, not redistributable release artifacts unless their own artifact matrix entries and smoke evidence exist.

## Runtime dependencies

- ONNX Runtime shared library: installed by `scripts/ddev-install-onnxruntime.sh`, pinned by version and SHA256.
- Embedding model bundle: `multilingual-e5-small` with `model.onnx`, `tokenizer.json`, and `manifest.json`. The manifest records model id, revision/source, dimensions, file hashes, and sizes.
- The PHP extension must be loaded through normal PHP CLI/FPM configuration. Do not claim release readiness from `php -d extension=...` only.
- `mxp_search.store_root`, `mxp_search.export_root`, and `mxp_search.model_dir` must be outside the WordPress docroot. Model directory must be root-owned/read-only for the web user.

## Build artifacts

Run from the repository root:

```bash
scripts/build-release-artifacts.sh
```

The script builds the native PHP extension in DDEV and writes artifacts under `release/dist/<version>-phpapi<api>-linux-<arch>/`:

- `mxp_search-<version>-phpapi<api>-linux-<arch>.so`
- `mxp-local-search-wp-plugin-<version>.zip`
- optional `mxp-local-search-model-multilingual-e5-small-<revision>.tar.gz` when the verified model bundle is present
- `mxp-local-search-<version>-manifest.json`
- `SHA256SUMS`

The manifest is machine-readable and includes version, platform, PHP API, feature flags, runtime dependency versions and hashes, artifact hashes, license summary, limitations, and signing status.

## Signing status

Local builds are `unsigned-local`. They are checksum-verified but not signed.

If a release signing key is available, add detached signatures next to the artifacts and update the manifest signing fields before publishing. Do not describe an unsigned artifact as signed.

`scripts/verify-release-artifacts.sh` currently accepts only `unsigned-local`; signed release support must add cryptographic signature verification before `signing.status` can be changed to `signed`.

## Verification

```bash
scripts/verify-release-artifacts.sh release/dist/<version>-phpapi<api>-linux-<arch>
scripts/install-release-artifacts.sh release/dist/<version>-phpapi<api>-linux-<arch>
scripts/release-upgrade-smoke.sh release/dist/<version>-phpapi<api>-linux-<arch>
ddev exec 'php scripts/php-extension-contract-smoke.php'
scripts/wp-regression-smoke.sh
scripts/security-probes.sh
scripts/browser-release-smoke.sh
scripts/perf-baseline.sh
```

Expected proof includes:

- manifest and SHA256 verification passes;
- tampering any artifact listed in the manifest causes `scripts/verify-release-artifacts.sh` to fail;
- extension loads in CLI and FPM/Web SAPI through installed PHP INI config;
- model bundle manifest and embedding dimensions verify after DDEV restart;
- PHP API contract covers Store create/open/list/exists/destroy/index/update/delete/deleteBatch/count/stats/search and Embedder embed/embedQuery/embedBatch/dimensions;
- WordPress public search excludes draft/private/password/trash content in fast, semantic, and hybrid modes;
- REST admin mutation endpoints reject anonymous users and cookie sessions without nonce;
- WP-CLI and REST background indexing paths clear the write lock;
- browser smoke shows the admin status, public search result title, snippet, and score.

## Rollback

```bash
scripts/rollback-release.sh
```

Rollback disables the WordPress plugin and PHP extension INI, then restarts PHP-FPM. KB/model data is preserved by default.

Destructive data removal requires an explicit confirmation token:

```bash
scripts/rollback-release.sh --purge-data I_UNDERSTAND_DELETE_MXP_LOCAL_SEARCH_DATA
```

## Local CI gate

```bash
scripts/release-ci.sh
```

This local gate runs Rust formatting, Rust tests, artifact build/verify/install, PHP lint, PHP extension contract smoke, WordPress regression smoke, browser smoke, security probes, and the performance baseline. It must pass before a version bump or tag intended for publication. GitHub CI/release jobs run `MXP_RELEASE_CI_SCOPE=artifacts scripts/release-ci.sh`, which stops after building and verifying release artifacts for the target matrix.

## Current limitations

- `deep`/reranker mode is intentionally unsupported and must fail closed.
- HNSW/usearch is a feature-gated acceleration path. Do not claim production ANN performance until a larger benchmark proves recall/latency and rebuild recovery.
- Release artifacts are currently built and verified only in the DDEV Linux target used by the manifest.
