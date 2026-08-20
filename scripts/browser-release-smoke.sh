#!/usr/bin/env bash
set -euo pipefail

cleanup_files=()
post_id=""
page_id=""
cleanup() {
  for file in "${cleanup_files[@]:-}"; do
    rm -f "$file"
  done
  if [ -n "$post_id" ] || [ -n "$page_id" ]; then
    ddev exec "wp --path=wordpress post delete ${post_id:-0} ${page_id:-0} --force" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

post_id=$(ddev exec "wp --path=wordpress post create --post_type=post --post_status=publish --post_title='MXP Browser Smoke Public Post' --post_content='MXP browser smoke public semantic hybrid visible snippet and score.' --porcelain" | tr -d '\r')
page_id=$(ddev exec "wp --path=wordpress post create --post_type=page --post_status=publish --post_title='MXP Browser Smoke Search Page' --post_content='[mxp_search mode=\"fast\" limit=\"5\"]' --porcelain" | tr -d '\r')

cleanup_files+=(".tmp-mxp-browser-admin-smoke.php")
cat > .tmp-mxp-browser-admin-smoke.php <<'PHP'
<?php
wp_set_current_user(1);
$plugin = MXP_Local_Search_Plugin::instance();
$plugin->index_manager->record_operation_status('browser_smoke', 'completed', [
    'message' => 'Browser smoke operation status visible.',
    'summary' => ['indexed' => 1, 'deleted' => 0, 'errors' => []],
    'completed_at' => time(),
]);
$admin = new MXP_Local_Search_Admin($plugin->config, $plugin->kb_manager, $plugin->index_manager, $plugin->search_handler);
ob_start();
$admin->render_dashboard();
$html = ob_get_clean();
$checks = [
    ['Plugin version', '外掛版本'],
    ['Knowledge base', '知識庫'],
    ['Search mode', '搜尋模式'],
    ['Built-in search replacement', '內建搜尋接管'],
    ['Built-in WordPress search', 'WordPress 內建搜尋'],
    ['Extension', '擴充套件'],
    ['Model', '模型'],
    ['Last operation', '最後操作'],
    ['Run Scheduled MXP Jobs Now', '立即執行已排程的 MXP 工作'],
    ['smart:', 'smart：'],
    ['Browser smoke operation status visible.'],
];
foreach ($checks as $needles) {
    $found = false;
    foreach ($needles as $needle) {
        if (strpos($html, $needle) !== false) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        fwrite(STDERR, 'admin surface missing: ' . implode(' / ', $needles) . "\n");
        exit(1);
    }
}
echo "browser_admin_surface_ok\n";
PHP

ddev exec 'wp --path=wordpress transient delete mxp_search_write_lock >/dev/null 2>&1 || true'
ddev exec 'wp --path=wordpress eval '\''wp_clear_scheduled_hook("mxp_search_index_all_event", [["post_type"=>"post", "batch"=>25]]);'\''' >/dev/null
ddev exec "wp --path=wordpress mxp-search index --id=${post_id}" >/dev/null
admin_out=$(ddev exec 'wp --path=wordpress eval-file .tmp-mxp-browser-admin-smoke.php' | tr -d '\r')
echo "$admin_out"

home=$(ddev exec 'wp --path=wordpress option get home' | tr -d '\r')
permalink=$(ddev exec "wp --path=wordpress post url ${page_id}" | tr -d '\r')
separator="?"
case "$permalink" in *"?"*) separator="&" ;; esac
query_url="${permalink}${separator}mxp_search_q=visible%20snippet"
html=$(curl -fsSL "$query_url")

case "$html" in
  *"MXP Browser Smoke Public Post"* ) ;;
  * ) echo "public browser surface missing result title" >&2; exit 1 ;;
esac
case "$html" in
  *"mxp-local-search-result"* ) ;;
  * ) echo "public browser surface missing result wrapper" >&2; exit 1 ;;
esac
case "$html" in
  *"Score:"* | *"分數："* ) ;;
  * ) echo "public browser surface missing score" >&2; exit 1 ;;
esac
case "$html" in
  *"visible snippet"* ) ;;
  * ) echo "public browser surface missing snippet" >&2; exit 1 ;;
esac

ddev exec "wp --path=wordpress post delete ${post_id} ${page_id} --force" >/dev/null

echo "browser_public_surface_ok url=${home}"
echo "browser_release_smoke_ok"
