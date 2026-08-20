#!/usr/bin/env bash
set -euo pipefail

release_dir="${1:-}"
if [ -z "$release_dir" ]; then
  release_dir=$(python3 - <<'PY'
from pathlib import Path
candidates = sorted(Path('release/dist').glob('*/mxp-local-search-*-manifest.json'), key=lambda p: p.stat().st_mtime, reverse=True)
if not candidates:
    raise SystemExit('missing release manifest under release/dist')
print(candidates[0].parent)
PY
)
fi

scripts/ddev-install-onnxruntime.sh
if ! scripts/verify-model-bundle.sh >/dev/null 2>&1; then
  scripts/ddev-install-model-bundle.sh
fi

scripts/verify-release-artifacts.sh "$release_dir"
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

if ! ddev exec 'test -f /var/lib/mxp-local-search/models/multilingual-e5-small/manifest.json' >/dev/null 2>&1; then
  scripts/ddev-install-model-bundle.sh
fi

ddev exec "set -euo pipefail
ext_dir=\$(php-config --extension-dir)
php_version=\$(php -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;')
sudo mkdir -p \"\$ext_dir\" /var/lib/mxp-local-search/kb /var/lib/mxp-local-search/export /var/lib/mxp-local-search/models
sudo cp /var/www/html/${release_dir}/${extension_file} \"\$ext_dir/mxp_search.so\"
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

ddev exec 'wp --path=wordpress plugin activate mxp-local-search >/dev/null && wp --path=wordpress plugin is-active mxp-local-search && echo plugin_active=1'
scripts/verify-model-bundle.sh
echo "release_install_ok release_dir=${release_dir}"
