use std::path::PathBuf;

use crate::embedding::{DEFAULT_E5_DOCUMENT_PREFIX, DEFAULT_E5_QUERY_PREFIX};

#[derive(Debug, Clone)]
pub struct Config {
    pub store_root: PathBuf,
    pub export_root: PathBuf,
    pub model_dir: PathBuf,
    pub allowed_models: Vec<String>,
    pub allow_local_model_path: bool,
    pub default_mode: String,
    pub max_limit: usize,
    pub max_candidate_limit: usize,
    pub max_query_bytes: usize,
    pub min_hybrid_score: f32,
}

impl Config {
    pub fn new(store_root: impl Into<PathBuf>) -> Self {
        let store_root = store_root.into();
        Self {
            export_root: store_root.join("exports"),
            model_dir: store_root.join("models"),
            store_root,
            allowed_models: vec!["multilingual-e5-small".to_string()],
            allow_local_model_path: false,
            default_mode: "fast".to_string(),
            max_limit: 50,
            max_candidate_limit: 500,
            max_query_bytes: 2048,
            min_hybrid_score: 0.1,
        }
    }
}

#[derive(Debug, Clone)]
pub struct StoreOptions {
    pub name: Option<String>,
    pub kb_id: Option<String>,
    pub model: String,
    pub dimensions: Option<u32>,
    pub distance: String,
    pub query_prefix: String,
    pub document_prefix: String,
}

impl Default for StoreOptions {
    fn default() -> Self {
        Self {
            name: None,
            kb_id: None,
            model: "multilingual-e5-small".to_string(),
            dimensions: None,
            distance: "cosine".to_string(),
            query_prefix: DEFAULT_E5_QUERY_PREFIX.to_string(),
            document_prefix: DEFAULT_E5_DOCUMENT_PREFIX.to_string(),
        }
    }
}
