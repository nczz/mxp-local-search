mod config;
mod embedding;
mod error;
mod hybrid;
mod path;
mod reranker;
mod search;
mod store;
mod vector;

pub use config::{Config, StoreOptions};
pub use embedding::{
    canonical_model_cache_key, validate_allowlisted_model_id, validate_model_file_checksum,
    E5PrefixConfig, ModelFileManifest, ModelManifest, DEFAULT_E5_DOCUMENT_PREFIX,
    DEFAULT_E5_QUERY_PREFIX,
};
#[cfg(feature = "embedding-onnx")]
pub use embedding::{EmbeddingInputKind, OnnxEmbedder};
pub use error::{Error, Result};
pub use hybrid::{
    clamp_score, confidence_gate, passes_confidence_gate, weighted_score_fusion, ConfidenceGate,
    ScoreWeights,
};
#[cfg(feature = "embedding-onnx")]
pub use reranker::OnnxReranker;
pub use reranker::{
    default_reranker_manifest, rerank_hits_with_scores, reranker_candidate_limit,
    RerankerFileManifest, RerankerManifest, DEFAULT_RERANKER_MODEL,
};
pub use search::{Filter, FilterOp, FilterValue, SearchMode, SearchOptions, SearchResult};
pub use store::{
    model_requires_allowlist, reranker_model_requires_allowlist, Document, DocumentChunk, Store,
    StoreEmbeddingConfig, StoreStats, UpdateOutcome, VectorGenerationState, KB_MARKER,
    SCHEMA_VERSION,
};
pub use vector::{l2_normalize, validate_query_vector, VectorSearchOptions};
