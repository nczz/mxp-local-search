#!/usr/bin/env bash
set -euo pipefail

cargo_bin="${CARGO:-$HOME/.cargo/bin/cargo}"
metadata_file=$(mktemp)
trap 'rm -f "$metadata_file"' EXIT
"$cargo_bin" metadata --format-version 1 --locked > "$metadata_file"
python3 - "$metadata_file" <<'PY'
import json, sys
from pathlib import Path
metadata = json.loads(Path(sys.argv[1]).read_text())
reviewed = {
    'MIT', 'Apache-2.0', 'MIT OR Apache-2.0', 'Apache-2.0 OR MIT',
    'BSD-3-Clause', 'BSD-2-Clause', 'Unicode-3.0',
}
unknown_allowed = {'encoding_rs'}
for pkg in sorted(metadata['packages'], key=lambda p: (p['name'], p['version'])):
    lic = pkg.get('license') or 'UNKNOWN'
    if lic == 'UNKNOWN' and pkg['name'] in unknown_allowed:
        lic = 'UNKNOWN-reviewed'
    print(f"license {pkg['name']} {pkg['version']} {lic}")
    if lic == 'UNKNOWN' and pkg['name'] not in unknown_allowed:
        raise SystemExit(f'unreviewed missing license metadata for {pkg["name"]}')
    if lic not in reviewed and lic != 'UNKNOWN-reviewed' and 'MIT' not in lic and 'Apache-2.0' not in lic and 'BSD' not in lic and 'Unicode' not in lic:
        raise SystemExit(f'unreviewed license for {pkg["name"]}: {lic}')
print('license_audit_ok')
PY
