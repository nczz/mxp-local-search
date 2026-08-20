#!/usr/bin/env bash
set -euo pipefail

WP_PATH=${WP_PATH:-wordpress}
BASE_URL=${BASE_URL:-http://127.0.0.1}
PUBLIC_HOST=${PUBLIC_HOST:-mxp-local-search.ddev.site}
QUERY=${QUERY:-ONNX PHP extension deployment}
export QUERY

wp_cmd() {
  wp --path="$WP_PATH" "$@"
}

wait_for_no_write_lock() {
  for _ in $(seq 1 60); do
    if [ "$(wp_cmd eval 'echo get_transient("mxp_search_write_lock") ? "locked" : "none";')" = "none" ]; then
      return 0
    fi
    sleep 1
  done
  echo "MXP Local Search write lock did not clear" >&2
  return 1
}

wp_cmd cron event run mxp_search_index_all_event --due-now >/dev/null 2>&1 || true
wait_for_no_write_lock


php -r 'echo extension_loaded("mxp_search") ? "extension_loaded\n" : "extension_missing\n"; exit(extension_loaded("mxp_search") ? 0 : 1);'
php scripts/php-semantic-smoke.php

wp_cmd plugin is-active mxp-local-search
wp_cmd option update mxp_local_search_settings '{"kb_mode":"single","post_types":["post","page"],"search_mode":"hybrid","chunk_strategy":"smart","include_taxonomies":true,"include_comments":false,"max_public_limit":10,"max_auth_limit":50,"batch_size":20,"store_root":"/var/lib/mxp-local-search/kb","export_root":"/var/lib/mxp-local-search/export"}' --format=json >/dev/null

wp_cmd mxp-search index --all
wp_cmd mxp-search search "$QUERY" --mode=fast --limit=3 >/dev/null
wp_cmd mxp-search search "$QUERY" --mode=semantic --limit=3 >/dev/null
wp_cmd mxp-search search "$QUERY" --mode=hybrid --limit=3 >/dev/null

for mode in fast semantic hybrid; do
  encoded_query=$(php -r 'echo rawurlencode(getenv("QUERY"));')
  curl -fsS -H "Host: ${PUBLIC_HOST}" "${BASE_URL}/index.php?rest_route=/mxp-search/v1/search&q=${encoded_query}&mode=${mode}&limit=5" \
    | php -r '$d=json_decode(stream_get_contents(STDIN), true); if (!is_array($d) || !array_key_exists("results", $d)) { fwrite(STDERR, "bad REST search payload\n"); exit(1); } echo "rest_" . $argv[1] . "_ok count=" . count($d["results"]) . "\n";' "$mode"
done

wp_cmd eval 'do_action("rest_api_init"); wp_set_current_user(1); $req = new WP_REST_Request("POST", "/mxp-search/v1/index-all"); $req->set_header("x-wp-nonce", wp_create_nonce("wp_rest")); $req->set_body_params(["post_type"=>"post", "batch"=>2]); $res = rest_do_request($req); $data = $res->get_data(); if ($res->get_status() !== 200 || empty($data["scheduled"])) { fwrite(STDERR, "index-all schedule failed\n"); exit(1); } echo "rest_index_all_ok batch=" . $data["batch"] . "\n";'
wp_cmd cron event run mxp_search_index_all_event --due-now >/dev/null

echo "wp_prod_smoke_ok"
