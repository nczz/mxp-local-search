#!/usr/bin/env bash
set -euo pipefail

inside_ddev_container() {
  [ "${IS_DDEV_PROJECT:-}" = "true" ]
}

container_exec() {
  if inside_ddev_container; then
    bash -lc "$1"
  else
    ddev exec "$1"
  fi
}

container_build_env() {
  if inside_ddev_container; then
    echo host
  else
    echo ddev
  fi
}

release_dir="${1:-}"
if [ -z "$release_dir" ]; then
  current_php_api=$(container_exec 'php-config --phpapi' 2>/dev/null | tr -d '\r')
  current_arch=$(container_exec 'uname -m' 2>/dev/null | tr -d '\r')
  case "$current_arch" in aarch64|arm64) current_arch=aarch64 ;; x86_64|amd64) current_arch=x86_64 ;; *) echo "unsupported architecture: $current_arch" >&2; exit 1 ;; esac
  release_dir=$(MXP_CURRENT_PHP_API="$current_php_api" MXP_CURRENT_ARCH="$current_arch" python3 - <<'PY'
from pathlib import Path
import json, os
candidates = sorted(Path('release/dist').glob('*/mxp-local-search-*-manifest.json'), key=lambda p: p.stat().st_mtime, reverse=True)
if not candidates:
    raise SystemExit('missing release manifest under release/dist')
php_api = os.environ['MXP_CURRENT_PHP_API']
arch = os.environ['MXP_CURRENT_ARCH']
compatible = []
for path in candidates:
    manifest = json.loads(path.read_text())
    build = manifest.get('build', {})
    if str(build.get('php_api')) == php_api and build.get('arch') == arch:
        compatible.append(path)
if not compatible:
    raise SystemExit(f'missing compatible release artifact for phpapi{php_api} linux-{arch}')
print(compatible[0].parent)
PY
)
fi

if inside_ddev_container; then
  scripts/install-onnxruntime.sh
  if ! MXP_RELEASE_BUILD_ENV=host scripts/verify-model-bundle.sh >/dev/null 2>&1; then
    scripts/install-model-bundle.sh
  fi
  if ! MXP_RELEASE_BUILD_ENV=host scripts/verify-reranker-bundle.sh >/dev/null 2>&1; then
    scripts/install-reranker-bundle.sh
  fi
else
  scripts/ddev-install-onnxruntime.sh
  if ! scripts/verify-model-bundle.sh >/dev/null 2>&1; then
    scripts/ddev-install-model-bundle.sh
  fi
  if ! scripts/verify-reranker-bundle.sh >/dev/null 2>&1; then
    scripts/ddev-install-reranker-bundle.sh
  fi
fi

MXP_RELEASE_BUILD_ENV="$(container_build_env)" scripts/verify-release-artifacts.sh "$release_dir"
eval "$(python3 - "$release_dir" <<'PY'
from pathlib import Path
import json, shlex, sys
release = Path(sys.argv[1])
manifest = json.loads(next(release.glob('mxp-local-search-*-manifest.json')).read_text())
by_kind = {a['kind']: a['file'] for a in manifest['artifacts']}
print('extension_file=' + shlex.quote(by_kind['php_extension']))
print('plugin_zip=' + shlex.quote(by_kind['wordpress_plugin']))
PY
)"

container_release_dir="$release_dir"
if ! inside_ddev_container; then
  container_release_dir="/var/www/html/${release_dir}"
fi

container_exec "test -f /var/lib/mxp-local-search/models/multilingual-e5-small/manifest.json"
container_exec "test -f /var/lib/mxp-local-search/models/onnx-community/bge-reranker-v2-m3-ONNX/manifest.json"

container_exec "set -euo pipefail
ext_dir=\$(php-config --extension-dir)
php_version=\$(php -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;')
sudo mkdir -p \"\$ext_dir\" /var/lib/mxp-local-search/kb /var/lib/mxp-local-search/export /var/lib/mxp-local-search/models
sudo cp ${container_release_dir}/${extension_file} \"\$ext_dir/mxp_search.so\"
sudo chown root:root \"\$ext_dir/mxp_search.so\"
sudo chmod 755 \"\$ext_dir/mxp_search.so\"
sudo chown -R \"\$USER\":www-data /var/lib/mxp-local-search/kb /var/lib/mxp-local-search/export
sudo chmod -R 775 /var/lib/mxp-local-search/kb /var/lib/mxp-local-search/export
sudo chmod 755 /var/lib/mxp-local-search /var/lib/mxp-local-search/models
sudo tee \"/etc/php/\${php_version}/mods-available/mxp_search.ini\" >/dev/null <<'INI'
extension=mxp_search.so
mxp_search.store_root=/var/lib/mxp-local-search/kb
mxp_search.export_root=/var/lib/mxp-local-search/export
mxp_search.model_dir=/var/lib/mxp-local-search/models
mxp_search.allowed_models=multilingual-e5-small
mxp_search.max_limit=50
mxp_search.max_candidate_limit=500
mxp_search.max_query_bytes=2048
mxp_search.min_hybrid_score=0.1
mxp_search.allowed_reranker_models=onnx-community/bge-reranker-v2-m3-ONNX
mxp_search.max_rerank_candidates=50
mxp_search.rerank_batch_size=4
INI
sudo ln -sf \"/etc/php/\${php_version}/mods-available/mxp_search.ini\" \"/etc/php/\${php_version}/cli/conf.d/99-mxp_search.ini\"
sudo ln -sf \"/etc/php/\${php_version}/mods-available/mxp_search.ini\" \"/etc/php/\${php_version}/fpm/conf.d/99-mxp_search.ini\"
sudo supervisorctl restart php-fpm >/dev/null
php -r 'echo extension_loaded(\"mxp_search\") ? \"installed_extension_loaded=1\\n\" : \"installed_extension_loaded=0\\n\"; exit(extension_loaded(\"mxp_search\") ? 0 : 1);'
"

python3 - "$release_dir/$plugin_zip" <<'PY'
from pathlib import Path
import sys, zipfile
zip_path = Path(sys.argv[1])
target = Path('wordpress/wp-content/plugins')
with zipfile.ZipFile(zip_path) as zf:
    bad = [name for name in zf.namelist() if not name.startswith('mxp-local-search/') or '..' in Path(name).parts]
    if bad:
        raise SystemExit(f'unsafe plugin zip entries: {bad[:3]}')
    zf.extractall(target)
PY

container_exec 'wp --path=wordpress plugin activate mxp-local-search >/dev/null && wp --path=wordpress plugin is-active mxp-local-search && echo plugin_active=1'
MXP_RELEASE_BUILD_ENV="$(container_build_env)" scripts/verify-model-bundle.sh
MXP_RELEASE_BUILD_ENV="$(container_build_env)" scripts/verify-reranker-bundle.sh
echo "release_install_ok release_dir=${release_dir}"
