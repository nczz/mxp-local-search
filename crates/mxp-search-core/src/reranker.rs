use std::fs;
use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

use crate::embedding::{
    validate_allowlisted_model_id, validate_cache_segment, validate_model_file_checksum,
    validate_relative_model_file, validate_sha256_hex,
};
use crate::error::{Error, IoContext, Result};
use crate::search::SearchResult;

pub const DEFAULT_RERANKER_MODEL: &str = "onnx-community/bge-reranker-v2-m3-ONNX";
const RERANKER_MANIFEST_FILE: &str = "manifest.json";
const DEFAULT_RERANKER_MODEL_FILE: &str = "model.onnx";
const DEFAULT_RERANKER_TOKENIZER_FILE: &str = "tokenizer.json";
const DEFAULT_RERANKER_MAX_TOKENS: usize = 512;

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct RerankerManifest {
    pub id: String,
    pub revision: Option<String>,
    pub task: String,
    pub model_file: PathBuf,
    pub tokenizer_file: PathBuf,
    pub max_tokens: usize,
    pub score: String,
    pub files: Vec<RerankerFileManifest>,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct RerankerFileManifest {
    pub path: PathBuf,
    pub sha256: String,
    pub size_bytes: Option<u64>,
}

impl RerankerManifest {
    pub fn load_from_dir(
        model_dir: impl AsRef<Path>,
        allowed_models: &[String],
        require_allowlist: bool,
    ) -> Result<Self> {
        let model_dir = model_dir.as_ref();
        let manifest_path = model_dir.join(RERANKER_MANIFEST_FILE);
        if !manifest_path.is_file() {
            return Err(Error::InvalidOption(format!(
                "missing reranker manifest: {}",
                manifest_path.display()
            )));
        }
        let manifest_json = fs::read_to_string(&manifest_path).at(&manifest_path)?;
        let manifest: Self = serde_json::from_str(&manifest_json).map_err(|err| {
            Error::InvalidOption(format!(
                "invalid reranker manifest {}: {err}",
                manifest_path.display()
            ))
        })?;
        manifest.validate(allowed_models, require_allowlist)?;
        manifest.require_file(&manifest.model_file)?;
        manifest.require_file(&manifest.tokenizer_file)?;
        manifest.verify_files(model_dir)?;
        Ok(manifest)
    }

    pub fn validate(&self, allowed_models: &[String], require_allowlist: bool) -> Result<()> {
        if require_allowlist {
            validate_allowlisted_model_id(&self.id, allowed_models)?;
        } else {
            crate::embedding::validate_model_id_shape(&self.id)?;
        }
        if let Some(revision) = &self.revision {
            validate_cache_segment("reranker revision", revision)?;
        }
        if self.task != "rerank" {
            return Err(Error::InvalidOption(format!(
                "unsupported reranker task for {}: {}",
                self.id, self.task
            )));
        }
        validate_relative_model_file(&self.model_file)?;
        validate_relative_model_file(&self.tokenizer_file)?;
        if self.max_tokens == 0 || self.max_tokens > 8192 {
            return Err(Error::InvalidOption(format!(
                "invalid reranker max_tokens for {}: {}",
                self.id, self.max_tokens
            )));
        }
        if self.score != "sigmoid" {
            return Err(Error::InvalidOption(format!(
                "unsupported reranker score transform for {}: {}",
                self.id, self.score
            )));
        }
        if self.files.is_empty() {
            return Err(Error::InvalidOption(format!(
                "reranker manifest has no files: {}",
                self.id
            )));
        }
        for file in &self.files {
            validate_relative_model_file(&file.path)?;
            validate_sha256_hex(&file.sha256)?;
        }
        Ok(())
    }

    fn require_file(&self, path: &Path) -> Result<()> {
        if self.files.iter().any(|file| file.path == path) {
            Ok(())
        } else {
            Err(Error::InvalidOption(format!(
                "reranker manifest missing required file: {}",
                path.display()
            )))
        }
    }

    fn verify_files(&self, model_dir: impl AsRef<Path>) -> Result<()> {
        let model_dir = model_dir.as_ref();
        for file in &self.files {
            let path = model_dir.join(&file.path);
            if let Some(expected_size) = file.size_bytes {
                let actual_size = fs::metadata(&path).at(&path)?.len();
                if actual_size != expected_size {
                    return Err(Error::InvalidOption(format!(
                        "reranker file size mismatch for {}: expected {}, got {}",
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
}

#[cfg(feature = "embedding-onnx")]
pub struct OnnxReranker {
    tokenizer: tokenizers::Tokenizer,
    session: std::sync::Mutex<ort::session::Session>,
}

#[cfg(feature = "embedding-onnx")]
impl OnnxReranker {
    pub fn open(
        model_dir: impl AsRef<Path>,
        allowed_models: &[String],
        require_allowlist: bool,
    ) -> Result<Self> {
        let model_dir = model_dir.as_ref();
        let manifest =
            RerankerManifest::load_from_dir(model_dir, allowed_models, require_allowlist)?;
        crate::embedding::init_ort_runtime()?;
        let tokenizer_path = model_dir.join(&manifest.tokenizer_file);
        let model_path = model_dir.join(&manifest.model_file);
        let mut tokenizer = tokenizers::Tokenizer::from_file(&tokenizer_path).map_err(|err| {
            Error::InvalidOption(format!("failed to load reranker tokenizer: {err}"))
        })?;
        tokenizer
            .with_truncation(Some(tokenizers::TruncationParams {
                max_length: manifest.max_tokens,
                strategy: tokenizers::TruncationStrategy::LongestFirst,
                ..Default::default()
            }))
            .map_err(|err| {
                Error::InvalidOption(format!(
                    "failed to configure reranker tokenizer truncation: {err}"
                ))
            })?;
        let session = ort::session::Session::builder()
            .map_err(crate::embedding::onnx_error)?
            .commit_from_file(&model_path)
            .map_err(crate::embedding::onnx_error)?;
        Ok(Self {
            tokenizer,
            session: std::sync::Mutex::new(session),
        })
    }

    pub fn score(&self, query: &str, document: &str) -> Result<f32> {
        let encoding = self
            .tokenizer
            .encode((query, document), true)
            .map_err(|err| {
                Error::InvalidOption(format!("failed to tokenize reranker pair: {err}"))
            })?;
        if encoding.is_empty() {
            return Err(Error::InvalidOption(
                "tokenizer produced no tokens for reranker input".to_string(),
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
                .map_err(crate::embedding::onnx_error)?;
        let mask = ort::value::Tensor::from_array((
            [1usize, token_count],
            attention_mask.into_boxed_slice(),
        ))
        .map_err(crate::embedding::onnx_error)?;
        let type_ids = ort::value::Tensor::from_array((
            [1usize, token_count],
            vec![0_i64; token_count].into_boxed_slice(),
        ))
        .map_err(crate::embedding::onnx_error)?;
        let mut session = self.session.lock().map_err(|_| {
            Error::InvalidOption("ONNX reranker session lock is poisoned".to_string())
        })?;
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
        let outputs = session.run(inputs).map_err(crate::embedding::onnx_error)?;
        let logits = outputs[0]
            .try_extract_array::<f32>()
            .map_err(crate::embedding::onnx_error)?;
        let logit = logits
            .as_slice_memory_order()
            .and_then(|values| values.first().copied())
            .ok_or_else(|| Error::InvalidOption("reranker output tensor is empty".to_string()))?;
        Ok(sigmoid(logit))
    }

    pub fn score_batch(&self, query: &str, documents: &[String]) -> Result<Vec<f32>> {
        documents
            .iter()
            .map(|document| self.score(query, document))
            .collect()
    }
}

pub fn rerank_hits_with_scores(
    mut hits: Vec<SearchResult>,
    scores: &[f32],
    limit: usize,
    min_score: f32,
) -> Result<Vec<SearchResult>> {
    if hits.len() != scores.len() {
        return Err(Error::InvalidOption(format!(
            "reranker score count mismatch: hits={}, scores={}",
            hits.len(),
            scores.len()
        )));
    }
    for (hit, score) in hits.iter_mut().zip(scores.iter().copied()) {
        if !score.is_finite() {
            return Err(Error::InvalidOption(
                "reranker score is not finite".to_string(),
            ));
        }
        hit.score = score.clamp(0.0, 1.0);
        if !hit.sources.iter().any(|source| source == "reranker") {
            hit.sources.push("reranker".to_string());
        }
        hit.sources.sort();
        hit.sources.dedup();
    }
    hits.retain(|hit| hit.score >= min_score);
    hits.sort_by(|a, b| {
        b.score
            .partial_cmp(&a.score)
            .unwrap_or(std::cmp::Ordering::Equal)
            .then_with(|| a.chunk_id.cmp(&b.chunk_id))
    });
    hits.truncate(limit);
    Ok(hits)
}

pub fn reranker_candidate_limit(limit: usize, requested: Option<usize>, max: usize) -> usize {
    let limit = limit.max(1);
    let max = max.max(limit);
    requested
        .unwrap_or_else(|| limit.saturating_mul(5).max(20))
        .clamp(limit, max)
}

#[cfg(feature = "embedding-onnx")]
fn sigmoid(value: f32) -> f32 {
    if value >= 0.0 {
        let z = (-value).exp();
        1.0 / (1.0 + z)
    } else {
        let z = value.exp();
        z / (1.0 + z)
    }
}

pub fn default_reranker_manifest(
    revision: impl Into<Option<String>>,
    model_sha256: impl Into<String>,
    model_size: u64,
    tokenizer_sha256: impl Into<String>,
    tokenizer_size: u64,
) -> RerankerManifest {
    RerankerManifest {
        id: DEFAULT_RERANKER_MODEL.to_string(),
        revision: revision.into(),
        task: "rerank".to_string(),
        model_file: PathBuf::from(DEFAULT_RERANKER_MODEL_FILE),
        tokenizer_file: PathBuf::from(DEFAULT_RERANKER_TOKENIZER_FILE),
        max_tokens: DEFAULT_RERANKER_MAX_TOKENS,
        score: "sigmoid".to_string(),
        files: vec![
            RerankerFileManifest {
                path: PathBuf::from(DEFAULT_RERANKER_MODEL_FILE),
                sha256: model_sha256.into(),
                size_bytes: Some(model_size),
            },
            RerankerFileManifest {
                path: PathBuf::from(DEFAULT_RERANKER_TOKENIZER_FILE),
                sha256: tokenizer_sha256.into(),
                size_bytes: Some(tokenizer_size),
            },
        ],
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn hit(id: &str, score: f32) -> SearchResult {
        SearchResult {
            id: id.to_string(),
            chunk_id: id.to_string(),
            doc_id: id.to_string(),
            kb_id: "kb".to_string(),
            score,
            title: id.to_string(),
            snippet: id.to_string(),
            metadata: json!({}),
            sources: vec!["fts".to_string()],
        }
    }

    #[test]
    fn reranker_candidate_limit_is_capped() {
        assert_eq!(reranker_candidate_limit(10, None, 50), 50);
        assert_eq!(reranker_candidate_limit(10, Some(12), 50), 12);
        assert_eq!(reranker_candidate_limit(10, Some(500), 50), 50);
        assert_eq!(reranker_candidate_limit(10, Some(1), 50), 10);
    }

    #[test]
    fn rerank_hits_replaces_scores_and_orders() {
        let hits = vec![hit("a", 0.9), hit("b", 0.1), hit("c", 0.2)];
        let out = rerank_hits_with_scores(hits, &[0.2, 0.95, 0.6], 2, 0.0).unwrap();
        assert_eq!(
            out.iter()
                .map(|hit| hit.chunk_id.as_str())
                .collect::<Vec<_>>(),
            vec!["b", "c"]
        );
        assert_eq!(out[0].score, 0.95);
        assert!(out[0].sources.iter().any(|source| source == "reranker"));
    }

    #[test]
    fn rerank_hits_applies_min_score() {
        let hits = vec![hit("a", 0.9), hit("b", 0.1)];
        let out = rerank_hits_with_scores(hits, &[0.2, 0.95], 10, 0.5).unwrap();
        assert_eq!(out.len(), 1);
        assert_eq!(out[0].chunk_id, "b");
    }
}
