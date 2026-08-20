#!/usr/bin/env bash
set -euo pipefail

PURGE_DATA=0
if [ "${1:-}" = "--purge-data" ]; then
  if [ "${2:-}" != "I_UNDERSTAND_DELETE_MXP_LOCAL_SEARCH_DATA" ]; then
    echo "purge requires confirmation token: I_UNDERSTAND_DELETE_MXP_LOCAL_SEARCH_DATA" >&2
    exit 1
  fi
  PURGE_DATA=1
fi

ddev exec "set -euo pipefail
wp --path=wordpress plugin deactivate mxp-local-search >/dev/null 2>&1 || true
php_version=\$(php -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;')
sudo rm -f \"/etc/php/\${php_version}/cli/conf.d/99-mxp_search.ini\" \"/etc/php/\${php_version}/fpm/conf.d/99-mxp_search.ini\"
sudo supervisorctl restart php-fpm >/dev/null
if [ ${PURGE_DATA} -eq 1 ]; then
  sudo rm -rf /var/lib/mxp-local-search
  echo data_purged=1
else
  echo data_preserved=1
fi
php -n -r 'echo extension_loaded(\"mxp_search\") ? \"rollback_extension_loaded=1\\n\" : \"rollback_extension_loaded=0\\n\";'
"
echo "release_rollback_ok"
