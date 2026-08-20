#!/usr/bin/env bash
set -euo pipefail

MODEL_ID="${MXP_SEARCH_MODEL_ID:-multilingual-e5-small}"
HF_REPO="${MXP_SEARCH_HF_REPO:-intfloat/multilingual-e5-small}"
REVISION="${MXP_SEARCH_MODEL_REVISION:-614241f622f53c4eeff9890bdc4f31cfecc418b3}"
MODEL_PATH="${MXP_SEARCH_MODEL_ONNX_PATH:-onnx/model.onnx}"
TOKENIZER_PATH="${MXP_SEARCH_TOKENIZER_PATH:-onnx/tokenizer.json}"
DEST="${MXP_SEARCH_MODEL_DEST:-/var/lib/mxp-local-search/models/${MODEL_ID}}"
DIMENSIONS="${MXP_SEARCH_MODEL_DIMENSIONS:-384}"
EXPECTED_MODEL_SHA256="${MXP_SEARCH_MODEL_SHA256:-ca456c06b3a9505ddfd9131408916dd79290368331e7d76bb621f1cba6bc8665}"
EXPECTED_TOKENIZER_SHA256="${MXP_SEARCH_TOKENIZER_SHA256:-0b44a9d7b51c3c62626640cda0e2c2f70fdacdc25bbbd68038369d14ebdf4c39}"
EXPECTED_MODEL_SIZE="${MXP_SEARCH_MODEL_SIZE:-470268510}"
EXPECTED_TOKENIZER_SIZE="${MXP_SEARCH_TOKENIZER_SIZE:-17082730}"

sudo mkdir -p "$DEST"
sudo chown -R "$USER":"$USER" "$DEST"
base="https://huggingface.co/${HF_REPO}/resolve/${REVISION}"
echo "Downloading ${HF_REPO}@${REVISION} into ${DEST}"
curl -fL "${base}/${MODEL_PATH}" -o "${DEST}/model.onnx"
curl -fL "${base}/${TOKENIZER_PATH}" -o "${DEST}/tokenizer.json"
model_sha=$(sha256sum "${DEST}/model.onnx" | cut -d" " -f1)
tokenizer_sha=$(sha256sum "${DEST}/tokenizer.json" | cut -d" " -f1)
model_size=$(stat -c %s "${DEST}/model.onnx")
tokenizer_size=$(stat -c %s "${DEST}/tokenizer.json")
if [ "$model_sha" != "$EXPECTED_MODEL_SHA256" ]; then
  echo "model checksum mismatch: expected $EXPECTED_MODEL_SHA256, got $model_sha" >&2
  exit 1
fi
if [ "$tokenizer_sha" != "$EXPECTED_TOKENIZER_SHA256" ]; then
  echo "tokenizer checksum mismatch: expected $EXPECTED_TOKENIZER_SHA256, got $tokenizer_sha" >&2
  exit 1
fi
if [ "$model_size" != "$EXPECTED_MODEL_SIZE" ] || [ "$tokenizer_size" != "$EXPECTED_TOKENIZER_SIZE" ]; then
  echo "model bundle size mismatch" >&2
  exit 1
fi
export MODEL_ID HF_REPO REVISION MODEL_PATH TOKENIZER_PATH DEST DIMENSIONS model_sha tokenizer_sha model_size tokenizer_size
php -r '
$manifest = [
    "id" => getenv("MODEL_ID"),
    "revision" => getenv("REVISION"),
    "dimensions" => (int) getenv("DIMENSIONS"),
    "distance" => "cosine",
    "source" => "https://huggingface.co/" . getenv("HF_REPO"),
    "source_files" => [getenv("MODEL_PATH"), getenv("TOKENIZER_PATH")],
    "format" => "ONNX Runtime model plus HuggingFace tokenizer.json",
    "prefixes" => ["query" => "query: ", "document" => "passage: "],
    "files" => [
        ["path" => "model.onnx", "sha256" => getenv("model_sha"), "size_bytes" => (int) getenv("model_size")],
        ["path" => "tokenizer.json", "sha256" => getenv("tokenizer_sha"), "size_bytes" => (int) getenv("tokenizer_size")],
    ],
];
file_put_contents(getenv("DEST") . "/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'
sudo chown -R root:root "$DEST"
sudo chmod -R a+rX "$DEST"
php -r 'foreach (["model.onnx", "tokenizer.json", "manifest.json"] as $f) { $p=getenv("DEST")."/".$f; echo $p, " ", is_file($p) ? filesize($p) : "missing", "\n"; }'
