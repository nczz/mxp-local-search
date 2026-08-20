#!/usr/bin/env bash
set -euo pipefail

FEATURES="${MXP_SEARCH_FEATURES:-php-extension,embedding-onnx,vector-usearch}"

ddev exec "cargo build --release -p mxp_search --features ${FEATURES}"
