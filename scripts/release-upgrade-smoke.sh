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

scripts/install-release-artifacts.sh "$release_dir"
ddev exec 'php -r '\''
use MXP\Search\Store;
$root = ini_get("mxp_search.store_root") ?: "/var/lib/mxp-local-search/kb";
$path = $root . "/release-upgrade-smoke";
if (Store::exists($path)) { $old = Store::open($path); Store::destroy($path, $old->destroyConfirmationToken()); }
$store = Store::create($path, ["name"=>"Release upgrade smoke", "model"=>"multilingual-e5-small", "dimensions"=>384]);
$store->index("upgrade-doc", "Upgrade preserved KB", "Release artifact reinstall should preserve existing KB marker metadata and vector search.", ["status"=>"publish", "visibility"=>"public"]);
$stats = $store->stats();
if ((int)$stats["vector_count"] !== 1) { fwrite(STDERR, "pre-upgrade vector_count failed\n"); exit(1); }
echo "upgrade_pre_vector_count=".$stats["vector_count"]." kb_id=".$store->kb_id()."\n";
'\'''
scripts/install-release-artifacts.sh "$release_dir"
ddev exec 'php -r '\''
use MXP\Search\Store;
$path = (ini_get("mxp_search.store_root") ?: "/var/lib/mxp-local-search/kb") . "/release-upgrade-smoke";
$store = Store::open($path);
$hits = $store->search("artifact reinstall preserve vector", ["mode"=>"hybrid", "limit"=>1]);
if (count($hits) !== 1 || $hits[0]["doc_id"] !== "upgrade-doc") { fwrite(STDERR, "post-upgrade search failed\n"); exit(1); }
echo "upgrade_post_search_ok top=".$hits[0]["doc_id"]."\n";
Store::destroy($path, $store->destroyConfirmationToken());
'\'''
echo "release_upgrade_smoke_ok"
