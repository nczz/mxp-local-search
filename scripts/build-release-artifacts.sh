#!/usr/bin/env bash
set -euo pipefail

FEATURES="${MXP_SEARCH_FEATURES:-php-extension,embedding-onnx,vector-usearch}"
DIST_ROOT="${MXP_RELEASE_DIST_ROOT:-release/dist}"
MODEL_ID="${MXP_SEARCH_MODEL_ID:-multilingual-e5-small}"
MODEL_ROOT="${MXP_SEARCH_MODEL_ROOT:-/var/lib/mxp-local-search/models}"
RERANKER_ID="${MXP_SEARCH_RERANKER_ID:-onnx-community/bge-reranker-v2-m3-ONNX}"
ORT_VERSION="${ORT_VERSION:-1.17.3}"
ORT_SHA256_AARCH64="${ORT_SHA256_AARCH64:-9f801577bd99676d1d821022e52b1f4554f56339ae3606c7b5ff3155f443c921}"
ORT_SHA256_X64="${ORT_SHA256_X64:-f2f11f9da1e3e19b22a8b378b9af57a58433f40e3db6a803e75c0ec0eba97a20}"

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
if [ "$build_env" = "ddev" ] && ! command -v ddev >/dev/null 2>&1; then
  echo "ddev_missing_release_build_env" >&2
  exit 1
fi

run_env() {
  if [ "$build_env" = "ddev" ]; then
    ddev exec "$1"
  else
    bash -lc "$1"
  fi
}

capture_env() {
  run_env "$1" | tr -d '\r'
}

model_manifest_exists() {
  if [ "$build_env" = "ddev" ]; then
    ddev exec "test -f ${MODEL_ROOT}/${MODEL_ID}/manifest.json" >/dev/null 2>&1
  else
    test -f "${MODEL_ROOT}/${MODEL_ID}/manifest.json"
  fi
}

model_manifest_field() {
  local field="$1"
  if [ "$build_env" = "ddev" ]; then
    ddev exec "php -r '\$m=json_decode(file_get_contents(\"${MODEL_ROOT}/${MODEL_ID}/manifest.json\"), true); echo \$m[\"${field}\"] ?? \"unknown\";'" | tr -d '\r'
  else
    php -r '$m=json_decode(file_get_contents($argv[1]), true); echo $m[$argv[2]] ?? "unknown";' "${MODEL_ROOT}/${MODEL_ID}/manifest.json" "$field"
  fi
}

model_manifest_files_json() {
  if [ "$build_env" = "ddev" ]; then
    ddev exec "php -r '\$m=json_decode(file_get_contents(\"${MODEL_ROOT}/${MODEL_ID}/manifest.json\"), true); echo json_encode(\$m[\"files\"] ?? []);'" | tr -d '\r'
  else
    php -r '$m=json_decode(file_get_contents($argv[1]), true); echo json_encode($m["files"] ?? []);' "${MODEL_ROOT}/${MODEL_ID}/manifest.json"
  fi
}

reranker_manifest_exists() {
  if [ "$build_env" = "ddev" ]; then
    ddev exec "test -f ${MODEL_ROOT}/${RERANKER_ID}/manifest.json" >/dev/null 2>&1
  else
    test -f "${MODEL_ROOT}/${RERANKER_ID}/manifest.json"
  fi
}

reranker_manifest_field() {
  local field="$1"
  if [ "$build_env" = "ddev" ]; then
    ddev exec "php -r '\$m=json_decode(file_get_contents(\"${MODEL_ROOT}/${RERANKER_ID}/manifest.json\"), true); echo \$m[\"${field}\"] ?? \"unknown\";'" | tr -d '\r'
  else
    php -r '$m=json_decode(file_get_contents($argv[1]), true); echo $m[$argv[2]] ?? "unknown";' "${MODEL_ROOT}/${RERANKER_ID}/manifest.json" "$field"
  fi
}

reranker_manifest_files_json() {
  if [ "$build_env" = "ddev" ]; then
    ddev exec "php -r '\$m=json_decode(file_get_contents(\"${MODEL_ROOT}/${RERANKER_ID}/manifest.json\"), true); echo json_encode(\$m[\"files\"] ?? []);'" | tr -d '\r'
  else
    php -r '$m=json_decode(file_get_contents($argv[1]), true); echo json_encode($m["files"] ?? []);' "${MODEL_ROOT}/${RERANKER_ID}/manifest.json"
  fi
}

package_reranker_artifact() {
  local out="$1"
  if [ "$build_env" = "ddev" ]; then
    ddev exec "tar -C ${MODEL_ROOT} --sort=name --owner=0 --group=0 --numeric-owner --mtime='UTC 1980-01-01' -cf - ${RERANKER_ID} | gzip -n > /var/www/html/${out}"
    ddev mutagen sync >/dev/null 2>&1 || true
  else
    tar -C "${MODEL_ROOT}" --sort=name --owner=0 --group=0 --numeric-owner --mtime='UTC 1980-01-01' -cf - "${RERANKER_ID}" | gzip -n > "$out"
  fi
}

package_model_artifact() {
  local out="$1"
  if [ "$build_env" = "ddev" ]; then
    ddev exec "tar -C ${MODEL_ROOT} --sort=name --owner=0 --group=0 --numeric-owner --mtime='UTC 1980-01-01' -cf - ${MODEL_ID} | gzip -n > /var/www/html/${out}"
    ddev mutagen sync >/dev/null 2>&1 || true
  else
    tar -C "${MODEL_ROOT}" --sort=name --owner=0 --group=0 --numeric-owner --mtime='UTC 1980-01-01' -cf - "${MODEL_ID}" | gzip -n > "$out"
  fi
}

wait_for_stable_file() {
  local path="$1"
  local last=""
  local current=""
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    current=$(python3 - "$path" <<'PY'
from pathlib import Path
import hashlib, sys
p = Path(sys.argv[1])
if not p.is_file():
    print('missing')
else:
    h = hashlib.sha256()
    with p.open('rb') as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b''):
            h.update(chunk)
    st = p.stat()
    print(f'{st.st_size}:{h.hexdigest()}')
PY
)
    if [ "$current" = "$last" ] && [ "$current" != "missing" ]; then
      return 0
    fi
    last="$current"
    sleep 1
  done
  echo "artifact did not become stable: $path" >&2
  return 1
}

cargo_pkg_version() {
  python3 - "$1" "$2" <<'PY'
from pathlib import Path
import re, sys
path, label = sys.argv[1:]
text = Path(path).read_text()
match = re.search(r'^version\s*=\s*"([^"]+)"', text, re.M)
if not match:
    raise SystemExit(f'missing {label} version')
print(match.group(1))
PY
}

mkdir -p "$DIST_ROOT"

extension_version=$(cargo_pkg_version 'crates/mxp-search-php/Cargo.toml' 'mxp_search')
core_version=$(cargo_pkg_version 'crates/mxp-search-core/Cargo.toml' 'mxp-search-core')
plugin_version=$(python3 - <<'PY'
from pathlib import Path
import re
text = Path('wordpress/wp-content/plugins/mxp-local-search/mxp-local-search.php').read_text()
header = re.search(r'\* Version:\s*([^\n]+)', text)
const = re.search(r"define\(\s*'MXP_LOCAL_SEARCH_VERSION'\s*,\s*'([^']+)'\s*\)", text)
if not header or not const:
    raise SystemExit('missing WordPress plugin version')
if header.group(1).strip() != const.group(1):
    raise SystemExit(f'plugin version mismatch: header={header.group(1).strip()} const={const.group(1)}')
print(const.group(1))
PY
)

php_api=$(capture_env 'php-config --phpapi')
php_version=$(capture_env "php -r 'echo PHP_VERSION;'")
arch=$(capture_env 'uname -m')
target=$(capture_env "rustc -Vv | sed -n 's/^host: //p'")
rustc_version=$(capture_env 'rustc --version')
cargo_version=$(capture_env 'cargo --version')
libc=$(capture_env "ldd --version | sed -n '1p'")
ort_lib_sha256=$(capture_env 'sha256sum /usr/local/lib/libonnxruntime.so | cut -d" " -f1')
ort_lib_bytes=$(capture_env 'stat -c %s /usr/local/lib/libonnxruntime.so')
case "$arch" in
  aarch64|arm64) normalized_arch="aarch64"; ort_arch="aarch64"; ort_sha256="$ORT_SHA256_AARCH64" ;;
  x86_64|amd64) normalized_arch="x86_64"; ort_arch="x64"; ort_sha256="$ORT_SHA256_X64" ;;
  *) echo "unsupported release architecture: $arch" >&2; exit 1 ;;
esac

release_dir="$DIST_ROOT/${extension_version}-phpapi${php_api}-linux-${normalized_arch}"
rm -rf "$release_dir"
mkdir -p "$release_dir"

if [ "$build_env" = "ddev" ]; then
  ddev mutagen sync >/dev/null 2>&1 || true
fi
echo "Building mxp_search release artifact: ext=$extension_version core=$core_version wp=$plugin_version php_api=$php_api arch=$normalized_arch env=$build_env features=$FEATURES"
run_env "cargo build --release -p mxp_search --features ${FEATURES}"
if [ "$build_env" = "ddev" ]; then
  ddev mutagen sync >/dev/null 2>&1 || true
fi

extension_file="mxp_search-${extension_version}-phpapi${php_api}-linux-${normalized_arch}.so"
cp target/release/libmxp_search.so "$release_dir/$extension_file"

plugin_zip="mxp-local-search-wp-plugin-${plugin_version}.zip"
python3 - "$release_dir/$plugin_zip" <<'PY'
from pathlib import Path
import stat, sys, zipfile
out = Path(sys.argv[1])
root = Path('wordpress/wp-content/plugins/mxp-local-search')
with zipfile.ZipFile(out, 'w') as zf:
    written = set()
    for path in sorted(root.rglob('*')):
        if path.is_file():
            rel = Path('mxp-local-search') / path.relative_to(root)

            name = rel.as_posix()
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (stat.S_IMODE(path.stat().st_mode) or 0o644) << 16
            zf.writestr(info, path.read_bytes())
            written.add(name)
    license_path = Path('LICENSE')
    if not license_path.is_file():
        raise SystemExit('missing root LICENSE')
    if 'mxp-local-search/LICENSE' not in written:
        info = zipfile.ZipInfo('mxp-local-search/LICENSE', date_time=(1980, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o644 << 16
        zf.writestr(info, license_path.read_bytes())
PY

model_artifact=""
model_revision=""
model_source=""
model_files_json="[]"
model_license="MIT"
if model_manifest_exists; then
  model_revision=$(model_manifest_field revision)
  model_source=$(model_manifest_field source)
  model_files_json=$(model_manifest_files_json)
  if [ "${MXP_INCLUDE_MODEL_ARTIFACT:-0}" = "1" ]; then
    model_artifact="mxp-local-search-model-${MODEL_ID}-${model_revision}.tar.gz"
    package_model_artifact "$release_dir/$model_artifact"
    wait_for_stable_file "$release_dir/$model_artifact"
  else
    echo "Model artifact omitted. Set MXP_INCLUDE_MODEL_ARTIFACT=1 to package the verified local model bundle." >&2
  fi
else
  echo "Model manifest missing under ${MODEL_ROOT}/${MODEL_ID}; manifest will contain dependency metadata without file pins." >&2
fi

reranker_artifact=""
reranker_revision=""
reranker_source=""
reranker_files_json="[]"
reranker_license="MIT"
if reranker_manifest_exists; then
  reranker_revision=$(reranker_manifest_field revision)
  reranker_source=$(reranker_manifest_field source)
  reranker_files_json=$(reranker_manifest_files_json)
  if [ "${MXP_INCLUDE_RERANKER_ARTIFACT:-1}" = "1" ]; then
    safe_reranker_id=${RERANKER_ID//\//-}
    reranker_artifact="mxp-local-search-reranker-${safe_reranker_id}-${reranker_revision}.tar.gz"
    package_reranker_artifact "$release_dir/$reranker_artifact"
    wait_for_stable_file "$release_dir/$reranker_artifact"
  else
    echo "Reranker artifact omitted. Set MXP_INCLUDE_RERANKER_ARTIFACT=1 to package the verified local reranker bundle." >&2
  fi
else
  echo "Reranker manifest missing under ${MODEL_ROOT}/${RERANKER_ID}; manifest will contain dependency metadata without file pins." >&2
fi

(
  cd "$release_dir"
  : > SHA256SUMS
  sha256sum "$extension_file" "$plugin_zip" ${model_artifact:+"$model_artifact"} ${reranker_artifact:+"$reranker_artifact"} > SHA256SUMS
)

python3 - "$release_dir" "$extension_version" "$core_version" "$plugin_version" "$php_api" "$php_version" "$normalized_arch" "$target" "$rustc_version" "$cargo_version" "$libc" "$FEATURES" "$extension_file" "$plugin_zip" "$model_artifact" "$MODEL_ID" "$model_revision" "$model_source" "$model_license" "$model_files_json" "$reranker_artifact" "$RERANKER_ID" "$reranker_revision" "$reranker_source" "$reranker_license" "$reranker_files_json" "$ORT_VERSION" "$ort_arch" "$ort_sha256" "$ort_lib_sha256" "$ort_lib_bytes" <<'PY'
from pathlib import Path
import hashlib, json, os, sys
(
    release_dir, extension_version, core_version, plugin_version, php_api, php_version,
    arch, target, rustc_version, cargo_version, libc, features, extension_file,
    plugin_zip, model_artifact, model_id, model_revision, model_source, model_license,
    model_files_json, reranker_artifact, reranker_id, reranker_revision, reranker_source,
    reranker_license, reranker_files_json, ort_version, ort_arch, ort_sha256,
    ort_lib_sha256, ort_lib_bytes,
) = sys.argv[1:]
release = Path(release_dir)
def sha256(name):
    h = hashlib.sha256()
    with (release / name).open('rb') as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()
model_files = json.loads(model_files_json) if model_files_json else []
reranker_files = json.loads(reranker_files_json) if reranker_files_json else []
artifacts = []
for name, kind, component in [
    (extension_file, 'php_extension', 'php_extension'),
    (plugin_zip, 'wordpress_plugin', 'wordpress_plugin'),
]:
    artifacts.append({'kind': kind, 'component': component, 'file': name, 'sha256': sha256(name), 'bytes': (release / name).stat().st_size})
if model_artifact:
    artifacts.append({'kind': 'model_bundle', 'component': 'model', 'file': model_artifact, 'sha256': sha256(model_artifact), 'bytes': (release / model_artifact).stat().st_size})
if reranker_artifact:
    artifacts.append({'kind': 'reranker_bundle', 'component': 'reranker', 'file': reranker_artifact, 'sha256': sha256(reranker_artifact), 'bytes': (release / reranker_artifact).stat().st_size})
manifest = {
    'schema': 'mxp-local-search-release-manifest-v2',
    'product': 'MXP Local Search',
    'package': 'mxp-local-search',
    'version': extension_version,
    'components': {
        'php_extension': {'name': 'mxp_search', 'version': extension_version},
        'rust_core': {'name': 'mxp-search-core', 'version': core_version},
        'wordpress_plugin': {'name': 'mxp-local-search', 'version': plugin_version},
    },
    'git_commit': os.environ.get('MXP_RELEASE_GIT_COMMIT', 'unknown-local'),
    'build': {
        'target': target,
        'os': 'linux',
        'arch': arch,
        'libc': libc,
        'php_version': php_version,
        'php_api': php_api,
        'rustc': rustc_version,
        'cargo': cargo_version,
        'features': [part.strip() for part in features.split(',') if part.strip()],
        'environment': os.environ.get('MXP_RELEASE_BUILD_ENV', 'auto'),
    },
    'compatibility': {
        'direct_load': {
            'os': 'linux',
            'arch': arch,
            'php_api': php_api,
            'php_version_built_against': php_version,
            'libc': libc,
            'requires_onnxruntime_shared_library': True,
            'onnxruntime_library_sha256': ort_lib_sha256,
        },
    },
    'requirements': {
        'php': '>=8.1',
        'wordpress': '>=6.4',
        'rust': '>=1.82 for source builds',
        'onnxruntime': ort_version,
    },
    'runtime_dependencies': {
        'onnxruntime': {
            'version': ort_version,
            'source': f'https://github.com/microsoft/onnxruntime/releases/download/v{ort_version}/onnxruntime-linux-{ort_arch}-{ort_version}.tgz',
            'sha256': ort_sha256,
            'library_sha256': ort_lib_sha256,
            'library_bytes': int(ort_lib_bytes),
            'license': 'MIT',
        },
        'model': {
            'id': model_id,
            'revision': model_revision or None,
            'source': model_source or 'https://huggingface.co/intfloat/multilingual-e5-small',
            'license': model_license,
            'artifact': model_artifact or None,
            'files': model_files,
        },
        'reranker': {
            'id': reranker_id,
            'revision': reranker_revision or None,
            'source': reranker_source or 'https://huggingface.co/onnx-community/bge-reranker-v2-m3-ONNX',
            'license': reranker_license,
            'artifact': reranker_artifact or None,
            'files': reranker_files,
        },
    },
    'artifacts': artifacts,
    'signing': {
        'status': 'unsigned-local',
        'fingerprint': None,
        'note': 'No signing key was configured. SHA256 checksums are generated; do not claim this as a signed release.',
    },
    'limitations': [
        'HNSW/usearch is a feature-gated acceleration path; production ANN claims require separate benchmark evidence',
        'The PHP extension is not universal: install only on matching Linux architecture, PHP API number, libc-compatible systems, and the recorded ONNX Runtime shared library',
    ],
    'licenses': {
        'project': 'MIT',
        'wordpress_plugin': 'MIT',
        'onnxruntime': 'MIT',
        'multilingual-e5-small': model_license,
        'bge-reranker-v2-m3-ONNX': reranker_license,
    },
}
(release / f'mxp-local-search-{extension_version}-manifest.json').write_text(json.dumps(manifest, indent=2, sort_keys=True) + '\n')
print(f'release_dir={release}')
for artifact in artifacts:
    print(f'artifact={artifact["file"]} sha256={artifact["sha256"]} bytes={artifact["bytes"]}')
print('signing_status=unsigned-local')
PY

echo "checksums=$release_dir/SHA256SUMS"
echo "manifest=$release_dir/mxp-local-search-${extension_version}-manifest.json"
