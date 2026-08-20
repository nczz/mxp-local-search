#!/usr/bin/env bash
set -euo pipefail

ORT_VERSION="${ORT_VERSION:-1.17.3}"
ORT_SHA256_AARCH64="${ORT_SHA256_AARCH64:-9f801577bd99676d1d821022e52b1f4554f56339ae3606c7b5ff3155f443c921}"
ORT_SHA256_X64="${ORT_SHA256_X64:-f2f11f9da1e3e19b22a8b378b9af57a58433f40e3db6a803e75c0ec0eba97a20}"
ORT_LIB_SHA256_AARCH64="${ORT_LIB_SHA256_AARCH64:-7bc357f54069e1921dee307452b13112b4683dbf195c6d488a14386cf373abfd}"
ORT_LIB_SHA256_X64="${ORT_LIB_SHA256_X64:-}"

ddev exec 'set -euo pipefail
version='"${ORT_VERSION}"'
sha256_aarch64='"${ORT_SHA256_AARCH64}"'
sha256_x64='"${ORT_SHA256_X64}"'
lib_sha256_aarch64='"${ORT_LIB_SHA256_AARCH64}"'
lib_sha256_x64='"${ORT_LIB_SHA256_X64}"'
arch=$(uname -m)
case "$arch" in
  aarch64|arm64) ort_arch="aarch64"; expected_sha="$sha256_aarch64"; expected_lib_sha="$lib_sha256_aarch64" ;;
  x86_64|amd64) ort_arch="x64"; expected_sha="$sha256_x64"; expected_lib_sha="$lib_sha256_x64" ;;
  *) echo "unsupported architecture: $arch" >&2; exit 1 ;;
esac
if [ -n "$expected_lib_sha" ] && [ -f /usr/local/lib/libonnxruntime.so ]; then
  current_lib_sha=$(sha256sum /usr/local/lib/libonnxruntime.so | cut -d" " -f1)
  if [ "$current_lib_sha" = "$expected_lib_sha" ]; then
    echo "onnxruntime_already_installed sha256=$current_lib_sha"
    exit 0
  fi
fi
name="onnxruntime-linux-${ort_arch}-${version}"
url="https://github.com/microsoft/onnxruntime/releases/download/v${version}/${name}.tgz"
tmp="/tmp/${name}.tgz"
work="/tmp/${name}"
echo "Downloading ${url}"
curl -fL "$url" -o "$tmp"
actual_sha=$(sha256sum "$tmp" | cut -d" " -f1)
if [ "$actual_sha" != "$expected_sha" ]; then
  echo "ONNX Runtime checksum mismatch: expected $expected_sha, got $actual_sha" >&2
  exit 1
fi
rm -rf "$work"
tar -xzf "$tmp" -C /tmp
sudo cp "$work"/lib/libonnxruntime.so* /usr/local/lib/
sudo chmod 755 /usr/local/lib/libonnxruntime.so*
echo /usr/local/lib | sudo tee /etc/ld.so.conf.d/mxp-onnxruntime.conf >/dev/null
sudo ldconfig
actual_lib_sha=$(sha256sum /usr/local/lib/libonnxruntime.so | cut -d" " -f1)
if [ -n "$expected_lib_sha" ] && [ "$actual_lib_sha" != "$expected_lib_sha" ]; then
  echo "ONNX Runtime library checksum mismatch: expected $expected_lib_sha, got $actual_lib_sha" >&2
  exit 1
fi
php -r '\''$p="/usr/local/lib/libonnxruntime.so"; echo is_file($p) ? $p."\n" : "missing\n";'\''
echo "onnxruntime_library_sha256=$actual_lib_sha"
'
