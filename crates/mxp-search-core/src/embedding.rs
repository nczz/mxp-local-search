use std::fs;
use std::path::{Component, Path, PathBuf};
#[cfg(feature = "embedding-onnx")]
use std::sync::OnceLock;

use base64::Engine;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};

use crate::error::{Error, IoContext, Result};

pub const DEFAULT_E5_QUERY_PREFIX: &str = "query: ";
pub const DEFAULT_E5_DOCUMENT_PREFIX: &str = "passage: ";

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct E5PrefixConfig {
    pub query: String,
    pub document: String,
}

impl Default for E5PrefixConfig {
    fn default() -> Self {
        Self {
            query: DEFAULT_E5_QUERY_PREFIX.to_string(),
            document: DEFAULT_E5_DOCUMENT_PREFIX.to_string(),
        }
    }
}

impl E5PrefixConfig {
    pub fn new(query: impl Into<String>, document: impl Into<String>) -> Result<Self> {
        let config = Self {
            query: query.into(),
            document: document.into(),
        };
        config.validate()?;
        Ok(config)
    }

    pub fn validate(&self) -> Result<()> {
        if self.query.is_empty() || self.document.is_empty() {
            return Err(Error::InvalidOption(
                "E5 query/document prefixes cannot be empty".to_string(),
            ));
        }
        if self.query.len() > 64 || self.document.len() > 64 {
            return Err(Error::InvalidOption(
                "E5 query/document prefixes cannot exceed 64 bytes".to_string(),
            ));
        }
        Ok(())
    }

    pub fn prefixed_query(&self, text: &str) -> String {
        format!("{}{}", self.query, text)
    }

    pub fn prefixed_document(&self, text: &str) -> String {
        format!("{}{}", self.document, text)
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct ModelFileManifest {
    pub path: PathBuf,
    pub sha256: String,
    pub size_bytes: Option<u64>,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct ModelManifest {
    pub id: String,
    pub revision: Option<String>,
    pub dimensions: u32,
    pub distance: String,
    pub prefixes: E5PrefixConfig,
    pub files: Vec<ModelFileManifest>,
}

const MODEL_MANIFEST_FILE: &str = "manifest.json";
const ONNX_MODEL_FILE: &str = "model.onnx";
const TOKENIZER_FILE: &str = "tokenizer.json";
#[cfg(feature = "embedding-onnx")]
const E5_MAX_TOKENS: usize = 512;

impl ModelManifest {
    pub fn validate(&self, allowed_models: &[String]) -> Result<()> {
        self.validate_model_identity(Some(allowed_models))?;
        self.validate_body()
    }

    pub fn validate_trusted_local(&self) -> Result<()> {
        self.validate_model_identity(None)?;
        self.validate_body()
    }

    fn validate_model_identity(&self, allowed_models: Option<&[String]>) -> Result<()> {
        match allowed_models {
            Some(allowed_models) => validate_allowlisted_model_id(&self.id, allowed_models)?,
            None => validate_model_id_shape(&self.id)?,
        }
        Ok(())
    }

    fn validate_body(&self) -> Result<()> {
        if let Some(revision) = &self.revision {
            validate_cache_segment("model revision", revision)?;
        }
        if self.dimensions == 0 || self.dimensions > 65_536 {
            return Err(Error::InvalidOption(format!(
                "invalid embedding dimensions for {}: {}",
                self.id, self.dimensions
            )));
        }
        if self.distance != "cosine" {
            return Err(Error::InvalidOption(format!(
                "unsupported embedding distance for {}: {}",
                self.id, self.distance
            )));
        }
        self.prefixes.validate()?;
        if self.files.is_empty() {
            return Err(Error::InvalidOption(format!(
                "model manifest has no files: {}",
                self.id
            )));
        }
        for file in &self.files {
            validate_relative_model_file(&file.path)?;
            validate_sha256_hex(&file.sha256)?;
        }
        Ok(())
    }

    pub fn load_from_dir(
        model_dir: impl AsRef<Path>,
        allowed_models: &[String],
        require_allowlist: bool,
    ) -> Result<Self> {
        let model_dir = model_dir.as_ref();
        let manifest_path = model_dir.join(MODEL_MANIFEST_FILE);
        if !manifest_path.is_file() {
            return Err(Error::InvalidOption(format!(
                "missing model manifest: {}",
                manifest_path.display()
            )));
        }
        let manifest_json = fs::read_to_string(&manifest_path).at(&manifest_path)?;
        let manifest: Self = serde_json::from_str(&manifest_json).map_err(|err| {
            Error::InvalidOption(format!(
                "invalid model manifest {}: {err}",
                manifest_path.display()
            ))
        })?;
        if require_allowlist {
            manifest.validate(allowed_models)?;
        } else {
            manifest.validate_trusted_local()?;
        }
        manifest.require_file(ONNX_MODEL_FILE)?;
        manifest.require_file(TOKENIZER_FILE)?;
        manifest.verify_files(model_dir)?;
        Ok(manifest)
    }

    fn require_file(&self, path: &str) -> Result<()> {
        let required = Path::new(path);
        if self.files.iter().any(|file| file.path == required) {
            Ok(())
        } else {
            Err(Error::InvalidOption(format!(
                "model manifest missing required file: {path}"
            )))
        }
    }

    pub fn verify_files(&self, model_dir: impl AsRef<Path>) -> Result<()> {
        let model_dir = model_dir.as_ref();
        for file in &self.files {
            let path = model_dir.join(&file.path);
            if let Some(expected_size) = file.size_bytes {
                let actual_size = fs::metadata(&path).at(&path)?.len();
                if actual_size != expected_size {
                    return Err(Error::InvalidOption(format!(
                        "model file size mismatch for {}: expected {}, got {}",
                        path.display(),
                        expected_size,
                        actual_size
                    )));
                }
            }
            validate_model_file_checksum(&path, &file.sha256)?;
        }
        Ok(())
    }

    pub fn cache_key(&self) -> Result<String> {
        canonical_model_cache_key(
            &self.id,
            self.revision.as_deref(),
            self.dimensions,
            &self.distance,
            &self.prefixes,
        )
    }
}

#[cfg(feature = "embedding-onnx")]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum EmbeddingInputKind {
    Query,
    Document,
}

#[cfg(feature = "embedding-onnx")]
pub struct OnnxEmbedder {
    tokenizer: tokenizers::Tokenizer,
    session: std::sync::Mutex<ort::session::Session>,
    dimensions: Option<usize>,
    prefixes: E5PrefixConfig,
}

#[cfg(feature = "embedding-onnx")]
impl OnnxEmbedder {
    pub fn open(
        model_dir: impl AsRef<Path>,
        dimensions: Option<usize>,
        prefixes: E5PrefixConfig,
        allowed_models: &[String],
        require_allowlist: bool,
    ) -> Result<Self> {
        let model_dir = model_dir.as_ref();
        let manifest = ModelManifest::load_from_dir(model_dir, allowed_models, require_allowlist)?;
        let resolved_dimensions = dimensions.unwrap_or(manifest.dimensions as usize);
        if resolved_dimensions != manifest.dimensions as usize {
            return Err(Error::InvalidOption(format!(
                "model dimensions mismatch for {}: expected {}, manifest has {}",
                manifest.id, resolved_dimensions, manifest.dimensions
            )));
        }
        if prefixes != manifest.prefixes {
            return Err(Error::InvalidOption(format!(
                "model prefixes mismatch for {}",
                manifest.id
            )));
        }
        init_ort_runtime()?;
        let tokenizer_path = model_dir.join(TOKENIZER_FILE);
        let model_path = model_dir.join(ONNX_MODEL_FILE);
        let mut tokenizer = tokenizers::Tokenizer::from_file(&tokenizer_path)
            .map_err(|err| Error::InvalidOption(format!("failed to load tokenizer: {err}")))?;
        tokenizer
            .with_truncation(Some(tokenizers::TruncationParams {
                max_length: E5_MAX_TOKENS,
                ..Default::default()
            }))
            .map_err(|err| {
                Error::InvalidOption(format!("failed to configure tokenizer truncation: {err}"))
            })?;
        let session = ort::session::Session::builder()
            .map_err(onnx_error)?
            .commit_from_file(&model_path)
            .map_err(onnx_error)?;
        Ok(Self {
            tokenizer,
            session: std::sync::Mutex::new(session),
            dimensions: Some(resolved_dimensions),
            prefixes,
        })
    }

    pub fn dimensions(&self) -> Option<usize> {
        self.dimensions
    }

    pub fn embed(&self, text: &str, kind: EmbeddingInputKind) -> Result<Vec<f32>> {
        let prefix = match kind {
            EmbeddingInputKind::Query => &self.prefixes.query,
            EmbeddingInputKind::Document => &self.prefixes.document,
        };
        let mut input = String::with_capacity(prefix.len() + text.len());
        input.push_str(prefix);
        input.push_str(text);
        let encoding = self
            .tokenizer
            .encode(input, true)
            .map_err(|err| Error::InvalidOption(format!("failed to tokenize input: {err}")))?;
        if encoding.is_empty() {
            return Err(Error::InvalidOption(
                "tokenizer produced no tokens for embedding input".to_string(),
            ));
        }
        let input_ids: Vec<i64> = encoding.get_ids().iter().copied().map(i64::from).collect();
        let attention_mask: Vec<i64> = encoding
            .get_attention_mask()
            .iter()
            .copied()
            .map(i64::from)
            .collect();
        let token_count = input_ids.len();
        let ids =
            ort::value::Tensor::from_array(([1usize, token_count], input_ids.into_boxed_slice()))
                .map_err(onnx_error)?;
        let mask = ort::value::Tensor::from_array((
            [1usize, token_count],
            attention_mask.clone().into_boxed_slice(),
        ))
        .map_err(onnx_error)?;
        let type_ids = ort::value::Tensor::from_array((
            [1usize, token_count],
            vec![0_i64; token_count].into_boxed_slice(),
        ))
        .map_err(onnx_error)?;
        let mut session = self
            .session
            .lock()
            .map_err(|_| Error::InvalidOption("ONNX session lock is poisoned".to_string()))?;
        let mut inputs = ort::inputs! {
            "input_ids" => ids,
            "attention_mask" => mask,
        };
        if session
            .inputs()
            .iter()
            .any(|input| input.name() == "token_type_ids")
        {
            inputs.push((
                std::borrow::Cow::from("token_type_ids"),
                ort::session::SessionInputValue::from(type_ids),
            ));
        }
        let outputs = session.run(inputs).map_err(onnx_error)?;
        let hidden = outputs[0].try_extract_array::<f32>().map_err(onnx_error)?;
        let mut vector = mean_pool(hidden, &attention_mask, self.dimensions)?;
        crate::vector::l2_normalize(&mut vector)?;
        Ok(vector)
    }

    pub fn embed_batch(&self, texts: &[String], kind: EmbeddingInputKind) -> Result<Vec<Vec<f32>>> {
        texts.iter().map(|text| self.embed(text, kind)).collect()
    }
}

#[cfg(feature = "embedding-onnx")]
fn mean_pool(
    hidden: ndarray::ArrayViewD<'_, f32>,
    attention_mask: &[i64],
    expected_dimensions: Option<usize>,
) -> Result<Vec<f32>> {
    let shape = hidden.shape();
    let values = hidden
        .as_slice_memory_order()
        .ok_or_else(|| Error::InvalidOption("ONNX output tensor is not contiguous".to_string()))?;
    let pooled = match shape {
        [1, dimensions] => values[..*dimensions].to_vec(),
        [1, tokens, dimensions] => {
            if *tokens != attention_mask.len() {
                return Err(Error::InvalidOption(format!(
                    "ONNX output token count {} does not match attention mask {}",
                    tokens,
                    attention_mask.len()
                )));
            }
            let mut pooled = vec![0.0_f32; *dimensions];
            let mut active_tokens = 0usize;
            for (token_idx, mask) in attention_mask.iter().enumerate() {
                if *mask == 0 {
                    continue;
                }
                active_tokens += 1;
                let row = &values[token_idx * *dimensions..(token_idx + 1) * *dimensions];
                for (slot, value) in pooled.iter_mut().zip(row) {
                    *slot += *value;
                }
            }
            if active_tokens == 0 {
                return Err(Error::InvalidOption(
                    "attention mask has no active tokens".to_string(),
                ));
            }
            let divisor = active_tokens as f32;
            for value in &mut pooled {
                *value /= divisor;
            }
            pooled
        }
        _ => {
            return Err(Error::InvalidOption(format!(
                "unsupported ONNX embedding output shape: {shape:?}"
            )));
        }
    };
    if let Some(expected) = expected_dimensions {
        if pooled.len() != expected {
            return Err(Error::InvalidOption(format!(
                "embedding dimension mismatch: expected {expected}, got {}",
                pooled.len()
            )));
        }
    }
    Ok(pooled)
}

#[cfg(feature = "embedding-onnx")]
fn init_ort_runtime() -> Result<()> {
    static INIT: OnceLock<std::result::Result<(), String>> = OnceLock::new();
    INIT.get_or_init(|| {
        if let Ok(path) = std::env::var("MXP_SEARCH_ONNX_RUNTIME") {
            ort::init_from(path)
                .map_err(|err| err.to_string())
                .map(|builder| {
                    builder.commit();
                })
        } else {
            ort::init().commit();
            Ok(())
        }
    })
    .clone()
    .map_err(|err| Error::InvalidOption(format!("failed to initialize ONNX Runtime: {err}")))
}

#[cfg(feature = "embedding-onnx")]
fn onnx_error(error: ort::Error) -> Error {
    Error::InvalidOption(format!("ONNX Runtime error: {error}"))
}

pub fn validate_allowlisted_model_id(model_id: &str, allowed_models: &[String]) -> Result<()> {
    validate_model_id_shape(model_id)?;
    if allowed_models.iter().any(|allowed| allowed == model_id) {
        Ok(())
    } else {
        Err(Error::InvalidOption(format!(
            "model is not allowlisted: {model_id}"
        )))
    }
}

pub fn validate_model_id_shape(model_id: &str) -> Result<()> {
    if model_id.is_empty() || model_id.len() > 160 {
        return Err(Error::InvalidOption(format!(
            "invalid model id length: {model_id}"
        )));
    }
    if model_id.starts_with('/') || model_id.ends_with('/') || model_id.contains("//") {
        return Err(Error::InvalidOption(format!(
            "invalid model id: {model_id}"
        )));
    }
    for segment in model_id.split('/') {
        validate_cache_segment("model id segment", segment)?;
    }
    Ok(())
}

pub fn validate_model_file_checksum(path: impl AsRef<Path>, expected_sha256: &str) -> Result<()> {
    let path = path.as_ref();
    validate_sha256_hex(expected_sha256)?;
    let actual = sha256_file_hex(path)?;
    if !actual.eq_ignore_ascii_case(expected_sha256) {
        return Err(Error::ModelChecksumMismatch {
            path: path.to_path_buf(),
            expected: expected_sha256.to_ascii_lowercase(),
            actual,
        });
    }
    Ok(())
}

pub fn sha256_file_hex(path: impl AsRef<Path>) -> Result<String> {
    let path = path.as_ref();
    let mut file = fs::File::open(path).at(path)?;
    let mut hasher = Sha256::new();
    std::io::copy(&mut file, &mut hasher).at(path)?;
    Ok(hex_lower(&hasher.finalize()))
}

pub fn canonical_model_cache_key(
    model_id: &str,
    revision: Option<&str>,
    dimensions: u32,
    distance: &str,
    prefixes: &E5PrefixConfig,
) -> Result<String> {
    validate_model_id_shape(model_id)?;
    if let Some(revision) = revision {
        validate_cache_segment("model revision", revision)?;
    }
    if dimensions == 0 {
        return Err(Error::InvalidOption(
            "model cache key dimensions cannot be zero".to_string(),
        ));
    }
    if distance.is_empty() || !distance.bytes().all(|byte| byte.is_ascii_lowercase()) {
        return Err(Error::InvalidOption(format!(
            "invalid model cache key distance: {distance}"
        )));
    }
    prefixes.validate()?;

    let revision = revision.unwrap_or("default");
    let mut hasher = Sha256::new();
    for part in [
        model_id,
        revision,
        &dimensions.to_string(),
        distance,
        &prefixes.query,
        &prefixes.document,
    ] {
        hasher.update(part.as_bytes());
        hasher.update([0]);
    }
    let digest = base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(hasher.finalize());
    Ok(format!(
        "{}--{}--{}d--{}",
        model_id.replace('/', "__"),
        revision,
        dimensions,
        &digest[..16]
    ))
}

fn validate_cache_segment(label: &str, value: &str) -> Result<()> {
    if value.is_empty() || value == "." || value == ".." || value.starts_with('.') {
        return Err(Error::InvalidOption(format!("invalid {label}: {value}")));
    }
    if !value
        .bytes()
        .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'-' | b'.'))
    {
        return Err(Error::InvalidOption(format!("invalid {label}: {value}")));
    }
    Ok(())
}

fn validate_relative_model_file(path: &Path) -> Result<()> {
    if path.is_absolute() {
        return Err(Error::InvalidOption(format!(
            "model manifest file must be relative: {}",
            path.display()
        )));
    }
    for component in path.components() {
        match component {
            Component::Normal(segment) => {
                let Some(segment) = segment.to_str() else {
                    return Err(Error::InvalidOption(format!(
                        "model manifest file is not UTF-8: {}",
                        path.display()
                    )));
                };
                validate_cache_segment("model file segment", segment)?;
            }
            _ => {
                return Err(Error::InvalidOption(format!(
                    "model manifest file contains unsafe path component: {}",
                    path.display()
                )));
            }
        }
    }
    Ok(())
}

fn validate_sha256_hex(value: &str) -> Result<()> {
    if value.len() != 64 || !value.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return Err(Error::InvalidOption(format!(
            "invalid SHA-256 checksum: {value}"
        )));
    }
    Ok(())
}

fn hex_lower(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut out = String::with_capacity(bytes.len() * 2);
    for byte in bytes {
        out.push(HEX[(byte >> 4) as usize] as char);
        out.push(HEX[(byte & 0x0f) as usize] as char);
    }
    out
}
