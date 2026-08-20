# Third Party Notices

MXP Local Search is distributed under the MIT license. Release redistributors must keep this notice with binary artifacts and model/runtime bundles.

## Runtime dependencies

| Component | Purpose | License | Source |
|---|---|---|---|
| ONNX Runtime | Local model inference shared library | MIT | https://github.com/microsoft/onnxruntime |
| `intfloat/multilingual-e5-small` | Default embedding model | MIT | https://huggingface.co/intfloat/multilingual-e5-small |
| Hugging Face `tokenizer.json` format/data | Tokenization metadata for the model bundle | Model repository license | https://huggingface.co/intfloat/multilingual-e5-small |

## Rust crates used by the release build

| Crate | Purpose | License |
|---|---|---|
| `anyhow` | Error handling | MIT OR Apache-2.0 |
| `base64` | Encoding helpers | MIT OR Apache-2.0 |
| `parking_lot` | Synchronization primitives | MIT OR Apache-2.0 |
| `rusqlite` | SQLite storage and FTS integration | MIT |
| `serde` | Serialization | MIT OR Apache-2.0 |
| `serde_json` | JSON serialization | MIT OR Apache-2.0 |
| `sha2` | SHA256 hashing | MIT OR Apache-2.0 |
| `tempfile` | Temporary file handling in tests/build tooling | MIT OR Apache-2.0 |
| `time` | Date/time handling | MIT OR Apache-2.0 |
| `uuid` | KB id generation | MIT OR Apache-2.0 |
| `ndarray` | Embedding tensor handling | MIT OR Apache-2.0 |
| `ort` | ONNX Runtime Rust binding | MIT OR Apache-2.0 |
| `tokenizers` | Hugging Face tokenizer runtime | Apache-2.0 |
| `usearch` | Vector index integration | Apache-2.0 |
| `ext-php-rs` | PHP extension binding | MIT |
| `ext-php-rs-derive` | PHP extension macro support | MIT |

Transitive dependencies are resolved by Cargo for each release. If the dependency graph changes, regenerate and review license metadata before publishing.

## WordPress integration

The WordPress plugin uses WordPress core APIs and ships no vendored PHP packages or JavaScript packages.

## Redistribution policy

- Do not redistribute a model bundle unless its `manifest.json` contains source, revision, dimensions, file hashes, and sizes.
- Do not redistribute ONNX Runtime unless its release archive source and SHA256 match the release manifest.
- If a model license cannot be confirmed, omit the model artifact and require user-supplied model installation through `scripts/ddev-install-model-bundle.sh` or an equivalent verified installer.
