#!/usr/bin/env bash
set -euo pipefail

ORT_VERSION="${ORT_VERSION:-1.17.3}"
ORT_SHA256_AARCH64="${ORT_SHA256_AARCH64:-9f801577bd99676d1d821022e52b1f4554f56339ae3606c7b5ff3155f443c921}"
ORT_SHA256_X64="${ORT_SHA256_X64:-f2f11f9da1e3e19b22a8b378b9af57a58433f40e3db6a803e75c0ec0eba97a20}"
ORT_LIB_SHA256_AARCH64="${ORT_LIB_SHA256_AARCH64:-}"
ORT_LIB_SHA256_X64="${ORT_LIB_SHA256_X64:-}"

arch=$(uname -m)
case "$arch" in
  aarch64|arm64) ort_arch="aarch64"; expected_sha="$ORT_SHA256_AARCH64"; expected_lib_sha="$ORT_LIB_SHA256_AARCH64" ;;
  x86_64|amd64) ort_arch="x64"; expected_sha="$ORT_SHA256_X64"; expected_lib_sha="$ORT_LIB_SHA256_X64" ;;
  *) echo "unsupported architecture: $arch" >&2; exit 1 ;;
esac

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
archive="$tmp/onnxruntime.tgz"
url="https://github.com/microsoft/onnxruntime/releases/download/v${ORT_VERSION}/onnxruntime-linux-${ort_arch}-${ORT_VERSION}.tgz"

curl -fL "$url" -o "$archive"
actual_sha=$(sha256sum "$archive" | cut -d" " -f1)
if [ "$actual_sha" != "$expected_sha" ]; then
  echo "ONNX Runtime archive checksum mismatch: expected $expected_sha got $actual_sha" >&2
  exit 1
fi

tar -xzf "$archive" -C "$tmp"
sudo cp "$tmp"/onnxruntime-linux-"$ort_arch"-"$ORT_VERSION"/lib/libonnxruntime.so* /usr/local/lib/
sudo chmod 755 /usr/local/lib/libonnxruntime.so*
if command -v ldconfig >/dev/null 2>&1; then
  echo /usr/local/lib | sudo tee /etc/ld.so.conf.d/mxp-onnxruntime.conf >/dev/null
  sudo ldconfig
fi
actual_lib_sha=$(sha256sum /usr/local/lib/libonnxruntime.so | cut -d" " -f1)
if [ -n "$expected_lib_sha" ] && [ "$actual_lib_sha" != "$expected_lib_sha" ]; then
  echo "ONNX Runtime library checksum mismatch: expected $expected_lib_sha got $actual_lib_sha" >&2
  exit 1
fi
echo "onnxruntime_library_sha256=$actual_lib_sha"
