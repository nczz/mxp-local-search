#!/usr/bin/env bash
set -euo pipefail

RERANKER_ID="${MXP_SEARCH_RERANKER_ID:-onnx-community/bge-reranker-v2-m3-ONNX}"
HF_REPO="${MXP_SEARCH_RERANKER_HF_REPO:-onnx-community/bge-reranker-v2-m3-ONNX}"
REVISION="${MXP_SEARCH_RERANKER_REVISION:-6f5ff65298512715a1e669753bc754d2bc8f367b}"
MODEL_PATH="${MXP_SEARCH_RERANKER_ONNX_PATH:-onnx/model_quantized.onnx}"
TOKENIZER_PATH="${MXP_SEARCH_RERANKER_TOKENIZER_PATH:-tokenizer.json}"
DEST="${MXP_SEARCH_RERANKER_DEST:-/var/lib/mxp-local-search/models/${RERANKER_ID}}"
MAX_TOKENS="${MXP_SEARCH_RERANKER_MAX_TOKENS:-512}"
EXPECTED_MODEL_SHA256="${MXP_SEARCH_RERANKER_MODEL_SHA256:-912fc1215c2dbff6499700534bd8d31253af01573861abbfc43afd1fab6cce5d}"
EXPECTED_TOKENIZER_SHA256="${MXP_SEARCH_RERANKER_TOKENIZER_SHA256:-8bf8afbfd11306bd872018c53bfdf2e160a56f8edbcf49933324404791c148d3}"
EXPECTED_MODEL_SIZE="${MXP_SEARCH_RERANKER_MODEL_SIZE:-570727094}"
EXPECTED_TOKENIZER_SIZE="${MXP_SEARCH_RERANKER_TOKENIZER_SIZE:-17082900}"

sudo mkdir -p "$DEST"
sudo chown -R "$USER":"$USER" "$DEST"
base="https://huggingface.co/${HF_REPO}/resolve/${REVISION}"
echo "Downloading reranker ${HF_REPO}@${REVISION} into ${DEST}"
curl -fL "${base}/${MODEL_PATH}" -o "${DEST}/model.onnx"
curl -fL "${base}/${TOKENIZER_PATH}" -o "${DEST}/tokenizer.json"
model_sha=$(sha256sum "${DEST}/model.onnx" | cut -d" " -f1)
tokenizer_sha=$(sha256sum "${DEST}/tokenizer.json" | cut -d" " -f1)
model_size=$(stat -c %s "${DEST}/model.onnx")
tokenizer_size=$(stat -c %s "${DEST}/tokenizer.json")
if [ "$model_sha" != "$EXPECTED_MODEL_SHA256" ]; then
  echo "reranker model checksum mismatch: expected $EXPECTED_MODEL_SHA256, got $model_sha" >&2
  exit 1
fi
if [ "$tokenizer_sha" != "$EXPECTED_TOKENIZER_SHA256" ]; then
  echo "reranker tokenizer checksum mismatch: expected $EXPECTED_TOKENIZER_SHA256, got $tokenizer_sha" >&2
  exit 1
fi
if [ "$model_size" != "$EXPECTED_MODEL_SIZE" ] || [ "$tokenizer_size" != "$EXPECTED_TOKENIZER_SIZE" ]; then
  echo "reranker bundle size mismatch" >&2
  exit 1
fi
export RERANKER_ID HF_REPO REVISION MODEL_PATH TOKENIZER_PATH DEST MAX_TOKENS model_sha tokenizer_sha model_size tokenizer_size
php -r '
$manifest = [
    "id" => getenv("RERANKER_ID"),
    "revision" => getenv("REVISION"),
    "task" => "rerank",
    "model_file" => "model.onnx",
    "tokenizer_file" => "tokenizer.json",
    "max_tokens" => (int) getenv("MAX_TOKENS"),
    "score" => "sigmoid",
    "source" => "https://huggingface.co/" . getenv("HF_REPO"),
    "source_files" => [getenv("MODEL_PATH"), getenv("TOKENIZER_PATH")],
    "format" => "ONNX Runtime sequence-classification reranker plus HuggingFace tokenizer.json",
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
