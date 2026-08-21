#!/usr/bin/env bash
set -euo pipefail

RERANKER_ID="${MXP_SEARCH_RERANKER_ID:-onnx-community/bge-reranker-v2-m3-ONNX}"
HF_REPO="${MXP_SEARCH_RERANKER_HF_REPO:-onnx-community/bge-reranker-v2-m3-ONNX}"
REVISION="${MXP_SEARCH_RERANKER_REVISION:-6f5ff65298512715a1e669753bc754d2bc8f367b}"
RERANKER_DIR="${MXP_SEARCH_RERANKER_DIR:-/var/lib/mxp-local-search/models/${RERANKER_ID}}"
MAX_TOKENS="${MXP_SEARCH_RERANKER_MAX_TOKENS:-512}"
EXPECTED_MODEL_SHA256="${MXP_SEARCH_RERANKER_MODEL_SHA256:-912fc1215c2dbff6499700534bd8d31253af01573861abbfc43afd1fab6cce5d}"
EXPECTED_TOKENIZER_SHA256="${MXP_SEARCH_RERANKER_TOKENIZER_SHA256:-8bf8afbfd11306bd872018c53bfdf2e160a56f8edbcf49933324404791c148d3}"
EXPECTED_MODEL_SIZE="${MXP_SEARCH_RERANKER_MODEL_SIZE:-570727094}"
EXPECTED_TOKENIZER_SIZE="${MXP_SEARCH_RERANKER_TOKENIZER_SIZE:-17082900}"

verify_php='
$rerankerId = $argv[1];
$repo = $argv[2];
$revision = $argv[3];
$dir = $argv[4];
$maxTokens = (int) $argv[5];
$expected = [
    "model.onnx" => ["sha256" => $argv[6], "size" => (int) $argv[8]],
    "tokenizer.json" => ["sha256" => $argv[7], "size" => (int) $argv[9]],
];
$manifestPath = $dir . "/manifest.json";
if (!is_file($manifestPath)) { fwrite(STDERR, "missing reranker manifest: $manifestPath\n"); exit(1); }
$manifest = json_decode(file_get_contents($manifestPath), true);
if (!is_array($manifest)) { fwrite(STDERR, "reranker manifest is invalid JSON\n"); exit(1); }
if (($manifest["id"] ?? "") !== $rerankerId) { fwrite(STDERR, "reranker id mismatch\n"); exit(1); }
if (($manifest["revision"] ?? "") !== $revision) { fwrite(STDERR, "reranker revision mismatch\n"); exit(1); }
if (($manifest["task"] ?? "") !== "rerank") { fwrite(STDERR, "reranker task mismatch\n"); exit(1); }
if (($manifest["source"] ?? "") !== "https://huggingface.co/" . $repo) { fwrite(STDERR, "reranker source mismatch\n"); exit(1); }
if ((int)($manifest["max_tokens"] ?? 0) !== $maxTokens) { fwrite(STDERR, "reranker max_tokens mismatch\n"); exit(1); }
if (($manifest["score"] ?? "") !== "sigmoid") { fwrite(STDERR, "reranker score transform mismatch\n"); exit(1); }
$manifestFiles = [];
foreach (($manifest["files"] ?? []) as $file) {
    if (isset($file["path"])) { $manifestFiles[(string) $file["path"]] = $file; }
}
foreach ($expected as $name => $pin) {
    $path = $dir . "/" . $name;
    if (!is_file($path)) { fwrite(STDERR, "missing reranker file: $path\n"); exit(1); }
    $actual = hash_file("sha256", $path);
    if (!hash_equals($pin["sha256"], $actual)) { fwrite(STDERR, "reranker sha256 mismatch: $path\n"); exit(1); }
    if (filesize($path) !== $pin["size"]) { fwrite(STDERR, "reranker size mismatch: $path\n"); exit(1); }
    if (!isset($manifestFiles[$name])) { fwrite(STDERR, "reranker manifest missing file pin: $name\n"); exit(1); }
    if (!hash_equals((string) ($manifestFiles[$name]["sha256"] ?? ""), $pin["sha256"])) { fwrite(STDERR, "reranker manifest sha256 pin mismatch: $name\n"); exit(1); }
    if ((int) ($manifestFiles[$name]["size_bytes"] ?? 0) !== $pin["size"]) { fwrite(STDERR, "reranker manifest size pin mismatch: $name\n"); exit(1); }
}
echo "reranker_manifest_ok ", $manifest["id"], " revision=", $manifest["revision"], " max_tokens=", $manifest["max_tokens"], " files=", count($expected), "\n";
if (extension_loaded("mxp_search") && class_exists("MXP\\Search\\Reranker")) {
    $reranker = new MXP\Search\Reranker($rerankerId);
    $good = $reranker->score("MXP release reranker check", "MXP release reranker check relevant document");
    $bad = $reranker->score("MXP release reranker check", "unrelated cooking recipe");
    if (!is_float($good) || !is_float($bad) || $good < 0 || $good > 1 || $bad < 0 || $bad > 1) {
        fwrite(STDERR, "reranker scores outside 0..1\n"); exit(1);
    }
    echo "reranker_score_ok good=", $good, " bad=", $bad, "\n";
}
'

build_env="${MXP_RELEASE_BUILD_ENV:-auto}"
if [ "$build_env" = "auto" ] && command -v ddev >/dev/null 2>&1; then
  build_env="ddev"
elif [ "$build_env" = "auto" ]; then
  build_env="host"
fi

if [ "$build_env" = "ddev" ]; then
  args=$(python3 -c 'import shlex, sys; print(" ".join(shlex.quote(arg) for arg in sys.argv[1:]))' \
    "$RERANKER_ID" "$HF_REPO" "$REVISION" "$RERANKER_DIR" "$MAX_TOKENS" \
    "$EXPECTED_MODEL_SHA256" "$EXPECTED_TOKENIZER_SHA256" "$EXPECTED_MODEL_SIZE" "$EXPECTED_TOKENIZER_SIZE")
  ddev exec "php -r $(printf %q "$verify_php") ${args}"
else
  php -r "$verify_php" \
    "$RERANKER_ID" "$HF_REPO" "$REVISION" "$RERANKER_DIR" "$MAX_TOKENS" \
    "$EXPECTED_MODEL_SHA256" "$EXPECTED_TOKENIZER_SHA256" "$EXPECTED_MODEL_SIZE" "$EXPECTED_TOKENIZER_SIZE"
fi
