#!/usr/bin/env bash
set -euo pipefail

MU_DIR="wordpress/wp-content/mu-plugins"
MU_FILE="$MU_DIR/zzz-mxp-release-smoke-cpt.php"
cleanup() {
  rm -f "$MU_FILE"
}
trap cleanup EXIT
mkdir -p "$MU_DIR"
cat > "$MU_FILE" <<'PHP'
<?php
add_action('init', static function (): void {
    register_post_type('mxp_smoke_item', [
        'label' => 'MXP Smoke Item',
        'public' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'custom-fields'],
    ]);
});
PHP
ddev exec 'wp --path=wordpress eval '\''
update_option("mxp_local_search_settings", [
    "kb_mode" => "single",
    "post_types" => ["post", "page", "mxp_smoke_item"],
    "search_mode" => "hybrid",
    "chunk_strategy" => "smart",
    "custom_fields" => ["mxp_release_field"],
    "include_taxonomies" => true,
    "include_comments" => false,
    "max_public_limit" => 5,
    "max_auth_limit" => 10,
    "max_candidate_limit" => 100,
    "max_query_bytes" => 2048,
    "batch_size" => 10,
    "stale_delete_ceiling" => 1000,
    "default_model" => "multilingual-e5-small",
    "allowed_models" => ["multilingual-e5-small"],
]);
'\'''

ddev exec 'wp --path=wordpress eval-file scripts/wp-regression-smoke.php'
cli_post=$(ddev exec 'wp --path=wordpress eval-file scripts/wp-cli-fixture.php' | tr -d '\r' | sed -n '$p')
for mode in fast semantic hybrid; do
  out=$(ddev exec "wp --path=wordpress mxp-search search 'MXP Release Smoke CLI fast semantic hybrid command coverage' --mode=${mode} --limit=3")
  case "$out" in
    *"MXP Release Smoke CLI"*) echo "wp_cli_search_${mode}_ok" ;;
    *) echo "wp_cli_search_${mode}_missing" >&2; echo "$out" >&2; exit 1 ;;
  esac
done
ddev exec "wp --path=wordpress post delete ${cli_post} --force" >/dev/null
echo "wp_regression_wrapper_ok"
