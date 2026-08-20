#!/usr/bin/env bash
set -euo pipefail

FEATURES="${MXP_SEARCH_FEATURES:-php-extension,embedding-onnx,vector-usearch}"

printf 'Building release extension with features: %s\n' "${FEATURES}"
ddev exec "cargo build --release -p mxp_search --features ${FEATURES}"

ddev exec 'set -euo pipefail
ext_dir=$(php-config --extension-dir)
php_version=$(php -r '\''echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'\'')
sudo mkdir -p "$ext_dir" /var/lib/mxp-local-search/kb /var/lib/mxp-local-search/export /var/lib/mxp-local-search/models
sudo cp /var/www/html/target/release/libmxp_search.so "$ext_dir/mxp_search.so"
sudo chown root:root "$ext_dir/mxp_search.so"
sudo chmod 755 "$ext_dir/mxp_search.so"
sudo chown -R "$USER":www-data /var/lib/mxp-local-search/kb /var/lib/mxp-local-search/export
sudo chmod -R 775 /var/lib/mxp-local-search/kb /var/lib/mxp-local-search/export
sudo chmod 755 /var/lib/mxp-local-search /var/lib/mxp-local-search/models
sudo tee "/etc/php/${php_version}/mods-available/mxp_search.ini" >/dev/null <<'\''INI'\''
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
sudo ln -sf "/etc/php/${php_version}/mods-available/mxp_search.ini" "/etc/php/${php_version}/cli/conf.d/99-mxp_search.ini"
sudo ln -sf "/etc/php/${php_version}/mods-available/mxp_search.ini" "/etc/php/${php_version}/fpm/conf.d/99-mxp_search.ini"
sudo supervisorctl restart php-fpm >/dev/null
php -r '\''echo extension_loaded("mxp_search") ? "mxp_search loaded\n" : "mxp_search missing\n";'\''
'
