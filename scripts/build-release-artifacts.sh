#!/usr/bin/env bash
set -euo pipefail

FEATURES="${MXP_SEARCH_FEATURES:-php-extension,embedding-onnx,vector-usearch}"
DIST_ROOT="${MXP_RELEASE_DIST_ROOT:-release/dist}"
MODEL_ID="${MXP_SEARCH_MODEL_ID:-multilingual-e5-small}"
ORT_VERSION="${ORT_VERSION:-1.17.3}"
ORT_SHA256_AARCH64="${ORT_SHA256_AARCH64:-9f801577bd99676d1d821022e52b1f4554f56339ae3606c7b5ff3155f443c921}"
ORT_SHA256_X64="${ORT_SHA256_X64:-f2f11f9da1e3e19b22a8b378b9af57a58433f40e3db6a803e75c0ec0eba97a20}"

wait_for_stable_file() {
  path="$1"
  last=""
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
    print(f'{st.st_size}:{st.st_mtime_ns}:{h.hexdigest()}')
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

mkdir -p "$DIST_ROOT"

version=$(python3 - <<'PY'
from pathlib import Path
import re
text = Path('crates/mxp-search-php/Cargo.toml').read_text()
match = re.search(r'^version\s*=\s*"([^"]+)"', text, re.M)
if not match:
    raise SystemExit('missing mxp_search version')
print(match.group(1))
PY
)
core_version=$(python3 - <<'PY'
from pathlib import Path
import re
text = Path('crates/mxp-search-core/Cargo.toml').read_text()
match = re.search(r'^version\s*=\s*"([^"]+)"', text, re.M)
if not match:
    raise SystemExit('missing mxp-search-core version')
print(match.group(1))
PY
)
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
if [ "$version" != "$core_version" ] || [ "$version" != "$plugin_version" ]; then
  echo "version mismatch: php=$version core=$core_version plugin=$plugin_version" >&2
  exit 1
fi

php_api=$(ddev exec 'php-config --phpapi' | tr -d '\r')
php_version=$(ddev exec "php -r 'echo PHP_VERSION;'" | tr -d '\r')
arch=$(ddev exec 'uname -m' | tr -d '\r')
target=$(ddev exec "rustc -Vv | sed -n 's/^host: //p'" | tr -d '\r')
rustc_version=$(ddev exec 'rustc --version' | tr -d '\r')
cargo_version=$(ddev exec 'cargo --version' | tr -d '\r')
libc=$(ddev exec "ldd --version | sed -n '1p'" | tr -d '\r')
ort_lib_sha256=$(ddev exec 'sha256sum /usr/local/lib/libonnxruntime.so | cut -d" " -f1' | tr -d '\r')
ort_lib_bytes=$(ddev exec 'stat -c %s /usr/local/lib/libonnxruntime.so' | tr -d '\r')
case "$arch" in
  aarch64|arm64) normalized_arch="aarch64"; ort_arch="aarch64"; ort_sha256="$ORT_SHA256_AARCH64" ;;
  x86_64|amd64) normalized_arch="x86_64"; ort_arch="x64"; ort_sha256="$ORT_SHA256_X64" ;;
  *) echo "unsupported release architecture: $arch" >&2; exit 1 ;;
esac

release_dir="$DIST_ROOT/$version-phpapi${php_api}-linux-${normalized_arch}"
rm -rf "$release_dir"
mkdir -p "$release_dir"

ddev mutagen sync >/dev/null 2>&1 || true
echo "Building mxp_search release artifact: version=$version php_api=$php_api arch=$normalized_arch features=$FEATURES"
ddev exec "cargo build --release -p mxp_search --features ${FEATURES}"
ddev mutagen sync >/dev/null 2>&1 || true


extension_file="mxp_search-${version}-phpapi${php_api}-linux-${normalized_arch}.so"
cp target/release/libmxp_search.so "$release_dir/$extension_file"

plugin_zip="mxp-local-search-wp-plugin-${version}.zip"
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
if [ "${MXP_INCLUDE_MODEL_ARTIFACT:-0}" = "1" ] && ddev exec "test -f /var/lib/mxp-local-search/models/${MODEL_ID}/manifest.json" >/dev/null 2>&1; then
  model_revision=$(ddev exec "php -r '\$m=json_decode(file_get_contents(\"/var/lib/mxp-local-search/models/${MODEL_ID}/manifest.json\"), true); echo \$m[\"revision\"] ?? \"unknown\";'" | tr -d '\r')
  model_source=$(ddev exec "php -r '\$m=json_decode(file_get_contents(\"/var/lib/mxp-local-search/models/${MODEL_ID}/manifest.json\"), true); echo \$m[\"source\"] ?? \"unknown\";'" | tr -d '\r')
  model_files_json=$(ddev exec "php -r '\$m=json_decode(file_get_contents(\"/var/lib/mxp-local-search/models/${MODEL_ID}/manifest.json\"), true); echo json_encode(\$m[\"files\"] ?? []);'" | tr -d '\r')
  model_artifact="mxp-local-search-model-${MODEL_ID}-${model_revision}.tar.gz"
  ddev exec "tar -C /var/lib/mxp-local-search/models -czf /var/www/html/${release_dir}/${model_artifact} ${MODEL_ID}"
  wait_for_stable_file "$release_dir/$model_artifact"
else
  if ddev exec "test -f /var/lib/mxp-local-search/models/${MODEL_ID}/manifest.json" >/dev/null 2>&1; then
    model_revision=$(ddev exec "php -r '\$m=json_decode(file_get_contents(\"/var/lib/mxp-local-search/models/${MODEL_ID}/manifest.json\"), true); echo \$m[\"revision\"] ?? \"unknown\";'" | tr -d '\r')
    model_source=$(ddev exec "php -r '\$m=json_decode(file_get_contents(\"/var/lib/mxp-local-search/models/${MODEL_ID}/manifest.json\"), true); echo \$m[\"source\"] ?? \"unknown\";'" | tr -d '\r')
    model_files_json=$(ddev exec "php -r '\$m=json_decode(file_get_contents(\"/var/lib/mxp-local-search/models/${MODEL_ID}/manifest.json\"), true); echo json_encode(\$m[\"files\"] ?? []);'" | tr -d '\r')
  fi
  echo "Model artifact omitted. Set MXP_INCLUDE_MODEL_ARTIFACT=1 to package the verified local model bundle." >&2
fi

(
  cd "$release_dir"
  : > SHA256SUMS
  sha256sum "$extension_file" "$plugin_zip" ${model_artifact:+"$model_artifact"} > SHA256SUMS
)

python3 - "$release_dir" "$version" "$php_api" "$php_version" "$normalized_arch" "$target" "$rustc_version" "$cargo_version" "$libc" "$FEATURES" "$extension_file" "$plugin_zip" "$model_artifact" "$MODEL_ID" "$model_revision" "$model_source" "$model_license" "$model_files_json" "$ORT_VERSION" "$ort_arch" "$ort_sha256" "$ort_lib_sha256" "$ort_lib_bytes" <<'PY'
from pathlib import Path
import hashlib, json, os, sys
(
    release_dir, version, php_api, php_version, arch, target, rustc_version, cargo_version,
    libc, features, extension_file, plugin_zip, model_artifact, model_id, model_revision,
    model_source, model_license, model_files_json, ort_version, ort_arch, ort_sha256,
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
artifacts = []
for name, kind in [(extension_file, 'php_extension'), (plugin_zip, 'wordpress_plugin')]:
    artifacts.append({'kind': kind, 'file': name, 'sha256': sha256(name), 'bytes': (release / name).stat().st_size})
if model_artifact:
    artifacts.append({'kind': 'model_bundle', 'file': model_artifact, 'sha256': sha256(model_artifact), 'bytes': (release / model_artifact).stat().st_size})
manifest = {
    'schema': 'mxp-local-search-release-manifest-v1',
    'product': 'MXP Local Search',
    'package': 'mxp-local-search',
    'version': version,
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
    },
    'artifacts': artifacts,
    'signing': {
        'status': 'unsigned-local',
        'fingerprint': None,
        'note': 'No signing key was configured. SHA256 checksums are generated; do not claim this as a signed release.',
    },
    'limitations': [
        'deep/reranker mode is not implemented and must fail closed',
        'HNSW/usearch is not claimed as production ANN unless separately benchmarked and verified',
        'The PHP extension is not universal: install only on matching Linux architecture, PHP API number, libc-compatible systems, and the recorded ONNX Runtime shared library',
    ],
    'licenses': {
        'project': 'MIT',
        'wordpress_plugin': 'MIT',
        'onnxruntime': 'MIT',
        'multilingual-e5-small': model_license,
    },
}
(release / f'mxp-local-search-{version}-manifest.json').write_text(json.dumps(manifest, indent=2, sort_keys=True) + '\n')
print(f'release_dir={release}')
for artifact in artifacts:
    print(f'artifact={artifact["file"]} sha256={artifact["sha256"]} bytes={artifact["bytes"]}')
print('signing_status=unsigned-local')
PY

echo "checksums=$release_dir/SHA256SUMS"
echo "manifest=$release_dir/mxp-local-search-${version}-manifest.json"
