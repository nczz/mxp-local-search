#!/usr/bin/env bash
set -euo pipefail

build_env="${MXP_RELEASE_BUILD_ENV:-auto}"
if [ "$build_env" = "auto" ]; then
  if command -v ddev >/dev/null 2>&1; then
    build_env="ddev"
  else
    build_env="host"
  fi
fi
case "$build_env" in
  ddev|host) ;;
  *) echo "unsupported MXP_RELEASE_BUILD_ENV=$build_env" >&2; exit 1 ;;
esac

capture_env() {
  if [ "$build_env" = "ddev" ]; then
    ddev exec "$1" 2>/dev/null | tr -d '\r' || true
  else
    bash -lc "$1" 2>/dev/null | tr -d '\r' || true
  fi
}

release_dir="${1:-}"
if [ -z "$release_dir" ]; then
  current_php_api=$(capture_env 'php-config --phpapi')
  current_arch=$(capture_env 'uname -m')
  case "$current_arch" in aarch64|arm64) current_arch=aarch64 ;; x86_64|amd64) current_arch=x86_64 ;; esac
  release_dir=$(MXP_CURRENT_PHP_API="$current_php_api" MXP_CURRENT_ARCH="$current_arch" python3 - <<'PY'
from pathlib import Path
import json, os
candidates = sorted(Path('release/dist').glob('*/mxp-local-search-*-manifest.json'), key=lambda p: p.stat().st_mtime, reverse=True)
if not candidates:
    raise SystemExit('missing release manifest under release/dist')
php_api = os.environ.get('MXP_CURRENT_PHP_API')
arch = os.environ.get('MXP_CURRENT_ARCH')
if php_api and arch:
    compatible = []
    for path in candidates:
        manifest = json.loads(path.read_text())
        build = manifest.get('build', {})
        if str(build.get('php_api')) == php_api and build.get('arch') == arch:
            compatible.append(path)
    if not compatible:
        raise SystemExit(f'missing compatible release artifact for phpapi{php_api} linux-{arch}')
    candidates = compatible
print(candidates[0].parent)
PY
)
fi
manifest=$(python3 - "$release_dir" <<'PY'
from pathlib import Path
import sys
candidates = sorted(Path(sys.argv[1]).glob('mxp-local-search-*-manifest.json'))
if len(candidates) != 1:
    raise SystemExit(f'expected one manifest in {sys.argv[1]}, found {len(candidates)}')
print(candidates[0])
PY
)

python3 - "$manifest" <<'PY'
from pathlib import Path
import hashlib, json, os, sys
manifest_path = Path(sys.argv[1])
release_dir = manifest_path.parent
manifest = json.loads(manifest_path.read_text())
required = ['schema', 'product', 'package', 'version', 'components', 'build', 'artifacts', 'signing', 'runtime_dependencies', 'requirements']
missing = [key for key in required if key not in manifest]
if missing:
    raise SystemExit(f'manifest missing keys: {missing}')
if manifest['schema'] not in {'mxp-local-search-release-manifest-v2'}:
    raise SystemExit('unsupported manifest schema')
if manifest['product'] != 'MXP Local Search' or manifest['package'] != 'mxp-local-search':
    raise SystemExit('manifest product/package mismatch')
components = manifest['components']
for key in ['php_extension', 'rust_core', 'wordpress_plugin']:
    if key not in components or not components[key].get('version'):
        raise SystemExit(f'manifest missing component version: {key}')
runtime = manifest['runtime_dependencies']
onnx = runtime.get('onnxruntime') or {}
if not onnx.get('library_sha256') or not onnx.get('library_bytes'):
    raise SystemExit('manifest missing ONNX Runtime library pins')
expected_model_id = os.environ.get('MXP_SEARCH_MODEL_ID', 'multilingual-e5-small')
expected_model_revision = os.environ.get('MXP_SEARCH_MODEL_REVISION', '614241f622f53c4eeff9890bdc4f31cfecc418b3')
expected_model_files = {
    'model.onnx': (
        os.environ.get('MXP_SEARCH_MODEL_SHA256', 'ca456c06b3a9505ddfd9131408916dd79290368331e7d76bb621f1cba6bc8665'),
        int(os.environ.get('MXP_SEARCH_MODEL_SIZE', '470268510')),
    ),
    'tokenizer.json': (
        os.environ.get('MXP_SEARCH_TOKENIZER_SHA256', '0b44a9d7b51c3c62626640cda0e2c2f70fdacdc25bbbd68038369d14ebdf4c39'),
        int(os.environ.get('MXP_SEARCH_TOKENIZER_SIZE', '17082730')),
    ),
}
model = runtime.get('model') or {}
if model.get('id') != expected_model_id or model.get('revision') != expected_model_revision:
    raise SystemExit('manifest model id/revision mismatch')
model_files = {item.get('path'): item for item in model.get('files') or []}
for name, (expected_hash, expected_size) in expected_model_files.items():
    item = model_files.get(name)
    if not item:
        raise SystemExit(f'manifest missing model file pin: {name}')
    if item.get('sha256') != expected_hash or int(item.get('size_bytes') or 0) != expected_size:
        raise SystemExit(f'manifest model file pin mismatch: {name}')
for artifact in manifest['artifacts']:
    path = release_dir / artifact['file']
    if not path.is_file():
        raise SystemExit(f'missing artifact file: {path.name}')
    actual = hashlib.sha256(path.read_bytes()).hexdigest()
    if actual != artifact['sha256']:
        raise SystemExit(f'sha256 mismatch: {path.name}')
    if path.stat().st_size != artifact['bytes']:
        raise SystemExit(f'size mismatch: {path.name}')
checksums = release_dir / 'SHA256SUMS'
if not checksums.is_file():
    raise SystemExit('missing SHA256SUMS')
for line in checksums.read_text().splitlines():
    if not line.strip():
        continue
    expected, name = line.split(maxsplit=1)
    name = name.strip().lstrip('*')
    path = release_dir / name
    if not path.is_file():
        raise SystemExit(f'checksums references missing file: {name}')
    actual = hashlib.sha256(path.read_bytes()).hexdigest()
    if actual != expected:
        raise SystemExit(f'SHA256SUMS mismatch: {name}')
status = manifest['signing'].get('status')
if status != 'unsigned-local':
    raise SystemExit('signed releases require signature verification support; only unsigned-local is accepted')
print(f'manifest_ok version={manifest["version"]} components={components} artifacts={len(manifest["artifacts"])} signing={status}')
for artifact in manifest['artifacts']:
    print(f'artifact_ok {artifact["kind"]} {artifact["file"]}')
PY

expected_php_api=$(python3 - "$manifest" <<'PY'
import json, sys
print(json.load(open(sys.argv[1]))['build']['php_api'])
PY
)
expected_arch=$(python3 - "$manifest" <<'PY'
import json, sys
print(json.load(open(sys.argv[1]))['build']['arch'])
PY
)
expected_ort_lib_sha=$(python3 - "$manifest" <<'PY'
import json, sys
print(json.load(open(sys.argv[1]))['runtime_dependencies']['onnxruntime']['library_sha256'])
PY
)
expected_ort_lib_bytes=$(python3 - "$manifest" <<'PY'
import json, sys
print(json.load(open(sys.argv[1]))['runtime_dependencies']['onnxruntime']['library_bytes'])
PY
)
current_php_api=$(capture_env 'php-config --phpapi')
current_arch=$(capture_env 'uname -m')
case "$current_arch" in aarch64|arm64) current_arch=aarch64 ;; x86_64|amd64) current_arch=x86_64 ;; esac
if [ -n "$current_php_api" ] && [ "$expected_php_api" != "$current_php_api" ]; then
  echo "php api mismatch: manifest=$expected_php_api current=$current_php_api" >&2
  exit 1
fi
if [ -n "$current_arch" ] && [ "$expected_arch" != "$current_arch" ]; then
  echo "architecture mismatch: manifest=$expected_arch current=$current_arch" >&2
  exit 1
fi
current_ort_lib_sha=$(capture_env 'sha256sum /usr/local/lib/libonnxruntime.so | cut -d" " -f1')
current_ort_lib_bytes=$(capture_env 'stat -c %s /usr/local/lib/libonnxruntime.so')
if [ -n "$current_ort_lib_sha" ] && { [ "$expected_ort_lib_sha" != "$current_ort_lib_sha" ] || [ "$expected_ort_lib_bytes" != "$current_ort_lib_bytes" ]; }; then
  echo "ONNX Runtime library mismatch: manifest=${expected_ort_lib_sha}/${expected_ort_lib_bytes} current=${current_ort_lib_sha}/${current_ort_lib_bytes}" >&2
  exit 1
fi
if [ -n "$current_ort_lib_sha" ]; then
  echo "onnxruntime_shared_library=1 sha256=$current_ort_lib_sha"
fi
MXP_RELEASE_BUILD_ENV="$build_env" scripts/verify-model-bundle.sh

echo "release_artifacts_verify_ok"
