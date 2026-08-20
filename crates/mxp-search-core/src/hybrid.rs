use crate::error::{Error, Result};

#[derive(Debug, Clone, Copy, PartialEq)]
pub struct ScoreWeights {
    pub lexical: f32,
    pub vector: f32,
}

impl Default for ScoreWeights {
    fn default() -> Self {
        Self {
            lexical: 0.5,
            vector: 0.5,
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq)]
pub struct ConfidenceGate {
    pub min_score: f32,
    pub min_lexical_score: Option<f32>,
    pub min_vector_score: Option<f32>,
}

impl Default for ConfidenceGate {
    fn default() -> Self {
        Self {
            min_score: 0.0,
            min_lexical_score: None,
            min_vector_score: None,
        }
    }
}

pub fn clamp_score(score: f32) -> f32 {
    if score.is_finite() {
        score.clamp(0.0, 1.0)
    } else {
        0.0
    }
}

pub fn weighted_score_fusion(
    lexical_score: Option<f32>,
    vector_score: Option<f32>,
    weights: ScoreWeights,
) -> Result<f32> {
    validate_weight("lexical", weights.lexical)?;
    validate_weight("vector", weights.vector)?;

    let mut weighted_sum = 0.0f32;
    let mut total_weight = 0.0f32;
    if let Some(score) = lexical_score {
        weighted_sum += clamp_score(score) * weights.lexical;
        total_weight += weights.lexical;
    }
    if let Some(score) = vector_score {
        weighted_sum += clamp_score(score) * weights.vector;
        total_weight += weights.vector;
    }
    if total_weight == 0.0 {
        return Ok(0.0);
    }
    Ok(clamp_score(weighted_sum / total_weight))
}

pub fn passes_confidence_gate(
    fused_score: f32,
    lexical_score: Option<f32>,
    vector_score: Option<f32>,
    gate: ConfidenceGate,
) -> bool {
    if clamp_score(fused_score) < clamp_score(gate.min_score) {
        return false;
    }
    if let Some(min_lexical) = gate.min_lexical_score {
        if lexical_score.map(clamp_score).unwrap_or(0.0) < clamp_score(min_lexical) {
            return false;
        }
    }
    if let Some(min_vector) = gate.min_vector_score {
        if vector_score.map(clamp_score).unwrap_or(0.0) < clamp_score(min_vector) {
            return false;
        }
    }
    true
}

pub fn confidence_gate(score: f32, min_score: f32) -> bool {
    clamp_score(score) >= clamp_score(min_score)
}

fn validate_weight(label: &str, weight: f32) -> Result<()> {
    if !weight.is_finite() || weight < 0.0 {
        return Err(Error::InvalidOption(format!(
            "{label} score weight must be finite and non-negative"
        )));
    }
    Ok(())
}
