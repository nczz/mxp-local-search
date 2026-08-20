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

- ONNX Runtime shared library: installed by `scripts/install-onnxruntime.sh` on Ubuntu/CI hosts or `scripts/ddev-install-onnxruntime.sh` inside the local DDEV WordPress gate, pinned by version and SHA256.
- Embedding model bundle: `multilingual-e5-small` with `model.onnx`, `tokenizer.json`, and `manifest.json`. The manifest records model id, revision/source, dimensions, file hashes, and sizes.
- The PHP extension must be loaded through normal PHP CLI/FPM configuration. Do not claim release readiness from `php -d extension=...` only.
- `mxp_search.store_root`, `mxp_search.export_root`, and `mxp_search.model_dir` must be outside the WordPress docroot. Model directory must be root-owned/read-only for the web user.
- The PHP extension, Rust core crate, and WordPress plugin are separately versioned components. Release manifests record them under `components`; a release tag is a packaging event, not proof that all component versions must be equal.

## Ubuntu server install or build

### Direct install from a GitHub release

Use this path when the release contains a native extension for the target PHP API and CPU architecture.

1. Inspect the target host:

```bash
php-config --phpapi
uname -m
php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, "\n";'
```

2. Download the matching release directory assets:

- `mxp_search-<extension-version>-phpapi<api>-linux-<x86_64|aarch64>.so`
- `mxp-local-search-wp-plugin-<plugin-version>.zip`
- `mxp-local-search-<extension-version>-manifest.json`
- `SHA256SUMS`
- optional `mxp-local-search-model-multilingual-e5-small-<revision>.tar.gz`

The `.so` is valid only for the manifest's recorded PHP API, CPU architecture, libc-compatible Linux runtime, and ONNX Runtime shared library. Do not reuse a PHP 8.3 artifact on PHP 8.1/8.2/8.4 unless `php-config --phpapi` is identical.

3. Verify checksums:

```bash
sha256sum -c SHA256SUMS
```

4. Install runtime directories and the extension:

```bash
PHP_MM="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
EXT_DIR="$(php-config --extension-dir)"

sudo install -d -m 775 -o www-data -g www-data /var/lib/mxp-local-search/kb /var/lib/mxp-local-search/export
sudo install -d -m 755 -o root -g root /var/lib/mxp-local-search/models
sudo install -m 755 -o root -g root mxp_search-<extension-version>-phpapi<api>-linux-<arch>.so "$EXT_DIR/mxp_search.so"

sudo tee "/etc/php/${PHP_MM}/mods-available/mxp_search.ini" >/dev/null <<'INI'
extension=mxp_search.so
mxp_search.store_root=/var/lib/mxp-local-search/kb
mxp_search.export_root=/var/lib/mxp-local-search/export
mxp_search.model_dir=/var/lib/mxp-local-search/models
mxp_search.allowed_models=multilingual-e5-small
mxp_search.max_limit=50
mxp_search.max_candidate_limit=500
mxp_search.max_query_bytes=2048
mxp_search.min_hybrid_score=0.1
INI

sudo phpenmod mxp_search
sudo systemctl restart "php${PHP_MM}-fpm" || sudo systemctl restart apache2
php -r 'echo extension_loaded("mxp_search") ? "mxp_search loaded\n" : "mxp_search missing\n";'
```

5. Install ONNX Runtime and the model bundle pinned by the manifest. If the release includes the optional model tarball, extract it under `/var/lib/mxp-local-search/models/multilingual-e5-small` as root-owned/read-only. Otherwise download `model.onnx`, `tokenizer.json`, and `manifest.json` from the pinned source and hashes in the manifest before enabling semantic/hybrid search.

6. Install the WordPress plugin:

```bash
unzip mxp-local-search-wp-plugin-<plugin-version>.zip -d /path/to/wordpress/wp-content/plugins/
wp --path=/path/to/wordpress plugin activate mxp-local-search
```

### Build on an Ubuntu host

Use this path when there is no matching release artifact. This is also the GitHub release build path for native PHP extension artifacts; it does not use DDEV.

```bash
git clone https://github.com/nczz/mxp-local-search.git
cd mxp-local-search
sudo apt-get update
sudo apt-get install -y build-essential clang libclang-dev pkg-config php-dev
scripts/install-onnxruntime.sh
scripts/install-model-bundle.sh
MXP_RELEASE_BUILD_ENV=host MXP_RELEASE_CI_SCOPE=artifacts scripts/release-ci.sh
```

The generated artifact lands under `release/dist/<extension-version>-phpapi<api>-linux-<arch>/`. Install that directory with the direct-install steps above.

### Local DDEV WordPress collaboration gate

Use this path before bumping any component version or publishing a tag. It proves the WordPress plugin integration contract against the local DDEV stack; it is not the GitHub native-extension build environment.

```bash
git clone https://github.com/nczz/mxp-local-search.git
cd mxp-local-search
ddev start -y
scripts/ddev-install-onnxruntime.sh
scripts/ddev-install-model-bundle.sh
scripts/release-ci.sh
```

The DDEV gate runs the full WordPress operational smoke suite after artifact build/verify/install.

## Build artifacts

Run from the repository root:

```bash
scripts/build-release-artifacts.sh
```

The script builds the native PHP extension on the selected build environment and writes artifacts under `release/dist/<extension-version>-phpapi<api>-linux-<arch>/`:

- `mxp_search-<extension-version>-phpapi<api>-linux-<arch>.so`
- `mxp-local-search-wp-plugin-<plugin-version>.zip`
- optional `mxp-local-search-model-multilingual-e5-small-<revision>.tar.gz` when the verified model bundle is present
- `mxp-local-search-<extension-version>-manifest.json`
- `SHA256SUMS`

The manifest is machine-readable and includes platform, PHP API, feature flags, runtime dependency versions and hashes, component versions, artifact hashes, license summary, limitations, and signing status.

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

This local gate runs Rust formatting, Rust tests, artifact build/verify/install, PHP lint, PHP extension contract smoke, WordPress regression smoke, browser smoke, security probes, and the performance baseline. It must pass before a version bump or tag intended for publication. GitHub CI/release jobs run `MXP_RELEASE_BUILD_ENV=host MXP_RELEASE_CI_SCOPE=artifacts scripts/release-ci.sh`, which stops after building and verifying release artifacts for the target matrix without DDEV.

## Current limitations

- `deep`/reranker mode is intentionally unsupported and must fail closed. Do not expose it as a stable feature until the reranker model, API contract, tests, and CPU/latency guardrails exist.
- HNSW/usearch is a feature-gated acceleration path. Do not claim production ANN performance until a larger benchmark proves recall/latency and rebuild recovery.
- Published stable artifacts still need a signing/attestation implementation. `unsigned-local` artifacts are acceptable for internal/pre-release validation only.
