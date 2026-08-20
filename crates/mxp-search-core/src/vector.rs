use crate::error::{Error, Result};

#[derive(Debug, Clone, PartialEq)]
pub struct VectorSearchOptions {
    pub limit: usize,
    pub candidate_limit: Option<usize>,
    pub min_score: f32,
}

impl Default for VectorSearchOptions {
    fn default() -> Self {
        Self {
            limit: 10,
            candidate_limit: None,
            min_score: 0.0,
        }
    }
}

pub fn l2_normalize(vector: &mut [f32]) -> Result<bool> {
    if vector.iter().any(|value| !value.is_finite()) {
        return Err(Error::InvalidOption(
            "embedding vector contains a non-finite value".to_string(),
        ));
    }

    let squared_norm = vector
        .iter()
        .map(|value| f64::from(*value) * f64::from(*value))
        .sum::<f64>();
    if squared_norm == 0.0 {
        return Ok(false);
    }

    let norm = squared_norm.sqrt() as f32;
    for value in vector {
        *value /= norm;
    }
    Ok(true)
}

pub fn validate_query_vector(vector: &[f32]) -> Result<()> {
    if vector.is_empty() {
        return Err(Error::InvalidOption(
            "query embedding vector cannot be empty".to_string(),
        ));
    }
    if vector.iter().any(|value| !value.is_finite()) {
        return Err(Error::InvalidOption(
            "query embedding vector contains a non-finite value".to_string(),
        ));
    }
    Ok(())
}

pub fn encode_f32_vector(vector: &[f32]) -> Result<Vec<u8>> {
    validate_query_vector(vector)?;
    let mut bytes = Vec::with_capacity(vector.len() * std::mem::size_of::<f32>());
    for value in vector {
        bytes.extend_from_slice(&value.to_le_bytes());
    }
    Ok(bytes)
}

pub fn decode_f32_vector(bytes: &[u8]) -> Result<Vec<f32>> {
    if bytes.is_empty() || bytes.len() % std::mem::size_of::<f32>() != 0 {
        return Err(Error::InvalidOption(format!(
            "stored vector has invalid byte length: {}",
            bytes.len()
        )));
    }
    let mut vector = Vec::with_capacity(bytes.len() / std::mem::size_of::<f32>());
    for chunk in bytes.chunks_exact(std::mem::size_of::<f32>()) {
        vector.push(f32::from_le_bytes([chunk[0], chunk[1], chunk[2], chunk[3]]));
    }
    validate_query_vector(&vector)?;
    Ok(vector)
}

pub fn cosine_similarity(left: &[f32], right: &[f32]) -> Result<f32> {
    if left.len() != right.len() {
        return Err(Error::InvalidOption(format!(
            "vector dimension mismatch: left {}, right {}",
            left.len(),
            right.len()
        )));
    }
    validate_query_vector(left)?;
    validate_query_vector(right)?;
    let mut dot = 0.0_f64;
    let mut left_norm = 0.0_f64;
    let mut right_norm = 0.0_f64;
    for (left, right) in left.iter().zip(right) {
        let left = f64::from(*left);
        let right = f64::from(*right);
        dot += left * right;
        left_norm += left * left;
        right_norm += right * right;
    }
    if left_norm == 0.0 || right_norm == 0.0 {
        return Ok(0.0);
    }
    Ok(((dot / left_norm.sqrt() / right_norm.sqrt()) as f32).clamp(0.0, 1.0))
}

#[cfg(not(feature = "embedding-onnx"))]
pub fn unsupported_semantic_backend() -> Error {
    Error::UnsupportedFeature {
        feature: "semantic embeddings",
        reason: semantic_backend_reason(),
    }
}

pub fn unsupported_hybrid_backend() -> Error {
    Error::UnsupportedFeature {
        feature: "hybrid search",
        reason: hybrid_backend_reason(),
    }
}

#[cfg(not(feature = "embedding-onnx"))]
fn semantic_backend_reason() -> &'static str {
    "embedding-onnx feature is disabled"
}

fn hybrid_backend_reason() -> &'static str {
    if cfg!(feature = "embedding-onnx") {
        "embedding-onnx feature is enabled but ONNX embedding runtime is unavailable"
    } else {
        "embedding-onnx feature is disabled"
    }
}
