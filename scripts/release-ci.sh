#!/usr/bin/env bash
set -euo pipefail

if ! command -v ddev >/dev/null 2>&1; then
  echo "ddev_missing_release_ci_blocker"
  exit 1
fi

scope="${MXP_RELEASE_CI_SCOPE:-full}"
case "$scope" in
  full|artifacts) ;;
  *) echo "unsupported_release_ci_scope=$scope" >&2; exit 1 ;;
esac


$HOME/.cargo/bin/cargo fmt --all -- --check
$HOME/.cargo/bin/cargo test --workspace
$HOME/.cargo/bin/cargo test -p mxp-search-core --features embedding-onnx
$HOME/.cargo/bin/cargo test -p mxp-search-core --features vector-usearch
scripts/audit-licenses.sh
scripts/ddev-install-onnxruntime.sh
if ! scripts/verify-model-bundle.sh >/dev/null 2>&1; then
  scripts/ddev-install-model-bundle.sh
fi
scripts/build-release-artifacts.sh
scripts/verify-release-artifacts.sh
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
'
scripts/install-release-artifacts.sh
ddev exec 'set -euo pipefail
for file in wordpress/wp-content/plugins/mxp-local-search/mxp-local-search.php wordpress/wp-content/plugins/mxp-local-search/includes/class-config.php wordpress/wp-content/plugins/mxp-local-search/includes/class-search-handler.php wordpress/wp-content/plugins/mxp-local-search/includes/class-index-manager.php wordpress/wp-content/plugins/mxp-local-search/includes/class-admin.php wordpress/wp-content/plugins/mxp-local-search/includes/class-kb-manager.php wordpress/wp-content/plugins/mxp-local-search/includes/class-cli.php wordpress/wp-content/plugins/mxp-local-search/includes/class-rest-api.php wordpress/wp-content/plugins/mxp-local-search/includes/class-hooks.php wordpress/wp-content/plugins/mxp-local-search/includes/class-chunker.php wordpress/wp-content/plugins/mxp-local-search/includes/class-content-extractor.php wordpress/wp-content/plugins/mxp-local-search/templates/admin-dashboard.php wordpress/wp-content/plugins/mxp-local-search/templates/admin-settings.php wordpress/wp-content/plugins/mxp-local-search/templates/search-results.php scripts/php-semantic-smoke.php scripts/php-extension-contract-smoke.php scripts/wp-regression-smoke.php; do php -l "$file" >/dev/null; done
echo php_lint_ok
php scripts/php-extension-contract-smoke.php
'
scripts/wp-regression-smoke.sh
scripts/browser-release-smoke.sh
scripts/security-probes.sh
scripts/perf-baseline.sh
echo "release_ci_ok"
