#!/usr/bin/env bash
set -euo pipefail

MODEL_ID="${MXP_SEARCH_MODEL_ID:-multilingual-e5-small}"
HF_REPO="${MXP_SEARCH_HF_REPO:-intfloat/multilingual-e5-small}"
REVISION="${MXP_SEARCH_MODEL_REVISION:-614241f622f53c4eeff9890bdc4f31cfecc418b3}"
MODEL_DIR="${MXP_SEARCH_MODEL_DIR:-/var/lib/mxp-local-search/models/${MODEL_ID}}"
DIMENSIONS="${MXP_SEARCH_MODEL_DIMENSIONS:-384}"
EXPECTED_MODEL_SHA256="${MXP_SEARCH_MODEL_SHA256:-ca456c06b3a9505ddfd9131408916dd79290368331e7d76bb621f1cba6bc8665}"
EXPECTED_TOKENIZER_SHA256="${MXP_SEARCH_TOKENIZER_SHA256:-0b44a9d7b51c3c62626640cda0e2c2f70fdacdc25bbbd68038369d14ebdf4c39}"
EXPECTED_MODEL_SIZE="${MXP_SEARCH_MODEL_SIZE:-470268510}"
EXPECTED_TOKENIZER_SIZE="${MXP_SEARCH_TOKENIZER_SIZE:-17082730}"

args=$(python3 -c 'import shlex, sys; print(" ".join(shlex.quote(arg) for arg in sys.argv[1:]))' \
  "$MODEL_ID" "$HF_REPO" "$REVISION" "$MODEL_DIR" "$DIMENSIONS" \
  "$EXPECTED_MODEL_SHA256" "$EXPECTED_TOKENIZER_SHA256" "$EXPECTED_MODEL_SIZE" "$EXPECTED_TOKENIZER_SIZE")

ddev exec "php -r '
\$modelId = \$argv[1];
\$repo = \$argv[2];
\$revision = \$argv[3];
\$dir = \$argv[4];
\$dimensions = (int) \$argv[5];
\$expected = [
    \"model.onnx\" => [\"sha256\" => \$argv[6], \"size\" => (int) \$argv[8]],
    \"tokenizer.json\" => [\"sha256\" => \$argv[7], \"size\" => (int) \$argv[9]],
];
\$manifestPath = \$dir . \"/manifest.json\";
if (!is_file(\$manifestPath)) { fwrite(STDERR, \"missing manifest: \$manifestPath\\n\"); exit(1); }
\$manifest = json_decode(file_get_contents(\$manifestPath), true);
if (!is_array(\$manifest)) { fwrite(STDERR, \"manifest is invalid JSON\\n\"); exit(1); }
if ((\$manifest[\"id\"] ?? \"\") !== \$modelId) { fwrite(STDERR, \"model id mismatch\\n\"); exit(1); }
if ((\$manifest[\"revision\"] ?? \"\") !== \$revision) { fwrite(STDERR, \"model revision mismatch\\n\"); exit(1); }
if ((\$manifest[\"source\"] ?? \"\") !== \"https://huggingface.co/\" . \$repo) { fwrite(STDERR, \"model source mismatch\\n\"); exit(1); }
if ((int)(\$manifest[\"dimensions\"] ?? 0) !== \$dimensions) { fwrite(STDERR, \"model dimensions mismatch\\n\"); exit(1); }
\$manifestFiles = [];
foreach ((\$manifest[\"files\"] ?? []) as \$file) {
    if (isset(\$file[\"path\"])) { \$manifestFiles[(string) \$file[\"path\"]] = \$file; }
}
foreach (\$expected as \$name => \$pin) {
    \$path = \$dir . \"/\" . \$name;
    if (!is_file(\$path)) { fwrite(STDERR, \"missing file: \$path\\n\"); exit(1); }
    \$actual = hash_file(\"sha256\", \$path);
    if (!hash_equals(\$pin[\"sha256\"], \$actual)) { fwrite(STDERR, \"sha256 mismatch: \$path\\n\"); exit(1); }
    if (filesize(\$path) !== \$pin[\"size\"]) { fwrite(STDERR, \"size mismatch: \$path\\n\"); exit(1); }
    if (!isset(\$manifestFiles[\$name])) { fwrite(STDERR, \"manifest missing file pin: \$name\\n\"); exit(1); }
    if (!hash_equals((string) (\$manifestFiles[\$name][\"sha256\"] ?? \"\"), \$pin[\"sha256\"])) { fwrite(STDERR, \"manifest sha256 pin mismatch: \$name\\n\"); exit(1); }
    if ((int) (\$manifestFiles[\$name][\"size_bytes\"] ?? 0) !== \$pin[\"size\"]) { fwrite(STDERR, \"manifest size pin mismatch: \$name\\n\"); exit(1); }
}
echo \"manifest_ok \", \$manifest[\"id\"], \" revision=\", \$manifest[\"revision\"], \" dims=\", \$manifest[\"dimensions\"], \" files=\", count(\$expected), \"\\n\";
if (extension_loaded(\"mxp_search\")) {
    \$embedder = new MXP\\Search\\Embedder(\$modelId);
    \$query = \$embedder->embedQuery(\"production readiness check\");
    \$doc = \$embedder->embed(\"production readiness document\");
    echo \"embed_ok query_dims=\", count(\$query), \" doc_dims=\", count(\$doc), \"\\n\";
}
' ${args}"