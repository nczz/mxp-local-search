#!/usr/bin/env bash
set -euo pipefail

scope="${MXP_RELEASE_CI_SCOPE:-full}"
case "$scope" in
  full|artifacts) ;;
  *) echo "unsupported_release_ci_scope=$scope" >&2; exit 1 ;;
esac

build_env="${MXP_RELEASE_BUILD_ENV:-auto}"
if [ "$build_env" = "auto" ]; then
  if command -v ddev >/dev/null 2>&1; then
    build_env="ddev"
  else
    build_env="host"
  fi
fi
case "$build_env" in
  ddev|host) ;;
  *) echo "unsupported_release_build_env=$build_env" >&2; exit 1 ;;
esac
if [ "$scope" = "full" ] && [ "$build_env" != "ddev" ]; then
  echo "full_release_ci_requires_ddev" >&2
  exit 1
fi
if [ "$build_env" = "ddev" ] && ! command -v ddev >/dev/null 2>&1; then
  echo "ddev_missing_release_ci_blocker"
  exit 1
fi

cargo_bin="${CARGO:-cargo}"
if [ -x "$HOME/.cargo/bin/cargo" ]; then
  cargo_bin="$HOME/.cargo/bin/cargo"
fi

"$cargo_bin" fmt --all -- --check
"$cargo_bin" test --workspace
"$cargo_bin" test -p mxp-search-core --features embedding-onnx
"$cargo_bin" test -p mxp-search-core --features vector-usearch
"$cargo_bin" test -p mxp-search-core --features embedding-onnx,vector-usearch
scripts/audit-licenses.sh

if [ "$build_env" = "ddev" ]; then
  scripts/ddev-install-onnxruntime.sh
  if ! scripts/verify-model-bundle.sh >/dev/null 2>&1; then
    scripts/ddev-install-model-bundle.sh
  fi
  if ! scripts/verify-reranker-bundle.sh >/dev/null 2>&1; then
    scripts/ddev-install-reranker-bundle.sh
  fi
else
  scripts/install-onnxruntime.sh
  if ! MXP_RELEASE_BUILD_ENV=host scripts/verify-model-bundle.sh >/dev/null 2>&1; then
    scripts/install-model-bundle.sh
  fi
  if ! MXP_RELEASE_BUILD_ENV=host scripts/verify-reranker-bundle.sh >/dev/null 2>&1; then
    scripts/install-reranker-bundle.sh
  fi
fi

MXP_RELEASE_BUILD_ENV="$build_env" MXP_INCLUDE_MODEL_ARTIFACT="${MXP_INCLUDE_MODEL_ARTIFACT:-1}" scripts/build-release-artifacts.sh
MXP_RELEASE_BUILD_ENV="$build_env" scripts/verify-release-artifacts.sh
if [ "$scope" = "artifacts" ]; then
  echo "release_artifact_ci_ok"
  exit 0
fi

ddev exec 'set -euo pipefail
if ! wp --path=wordpress core is-installed >/dev/null 2>&1; then
  wp --path=wordpress core install \
    --url=https://mxp-local-search.ddev.site \
    --title="MXP Local Search Smoke" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@example.test \
    --skip-email >/dev/null
  echo wordpress_core_installed=1
else
  echo wordpress_core_installed=0
fi
scripts/install-release-artifacts.sh
php -l wordpress/wp-content/plugins/mxp-local-search/mxp-local-search.php wordpress/wp-content/plugins/mxp-local-search/includes/class-admin.php wordpress/wp-content/plugins/mxp-local-search/includes/class-kb-manager.php wordpress/wp-content/plugins/mxp-local-search/includes/class-cli.php wordpress/wp-content/plugins/mxp-local-search/includes/class-rest-api.php wordpress/wp-content/plugins/mxp-local-search/includes/class-hooks.php wordpress/wp-content/plugins/mxp-local-search/includes/class-chunker.php wordpress/wp-content/plugins/mxp-local-search/includes/class-content-extractor.php wordpress/wp-content/plugins/mxp-local-search/includes/class-config.php wordpress/wp-content/plugins/mxp-local-search/includes/class-search-handler.php wordpress/wp-content/plugins/mxp-local-search/includes/class-index-manager.php
wp --path=wordpress plugin activate mxp-local-search >/dev/null
wp --path=wordpress eval-file scripts/wp-regression-smoke.php
php scripts/php-extension-contract-smoke.php
'
scripts/wp-regression-smoke.sh
scripts/browser-release-smoke.sh
scripts/security-probes.sh
scripts/perf-baseline.sh
echo "release_ci_ok"
