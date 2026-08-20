use std::fs;
use std::path::{Path, PathBuf};

use base64::Engine;
use rusqlite::{params, params_from_iter, Connection, OptionalExtension};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use time::OffsetDateTime;
use uuid::Uuid;

use crate::config::{Config, StoreOptions};
use crate::embedding::{validate_allowlisted_model_id, E5PrefixConfig};
use crate::error::{Error, IoContext, Result};
#[cfg(feature = "embedding-onnx")]
use crate::hybrid::{passes_confidence_gate, weighted_score_fusion, ConfidenceGate, ScoreWeights};
use crate::path::{atomic_write, resolve_existing_kb_path, resolve_new_kb_path};
use crate::search::{build_filter_sql, safe_fts_query, SearchMode, SearchOptions, SearchResult};
#[cfg(not(feature = "embedding-onnx"))]
use crate::vector::unsupported_semantic_backend;
use crate::vector::{
    cosine_similarity, decode_f32_vector, encode_f32_vector, unsupported_hybrid_backend,
    validate_query_vector, VectorSearchOptions,
};

pub const SCHEMA_VERSION: u32 = 1;
pub const KB_MARKER: &str = ".mxp-search-kb";
const META_FILE: &str = "meta.json";
const DB_FILE: &str = "chunks.db";

#[derive(Debug, Clone, Serialize, Deserialize)]
struct Marker {
    schema_version: u32,
    kb_id: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
struct Meta {
    schema_version: u32,
    kb_id: String,
    name: String,
    model: String,
    dimensions: Option<u32>,
    distance: String,
    query_prefix: String,
    document_prefix: String,
    created_at: String,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum UpdateOutcome {
    Skipped,
    MetadataFtsOnly,
    Full,
    New,
}

#[derive(Debug, Clone)]
pub struct Document {
    pub id: String,
    pub title: String,
    pub content: String,
    pub metadata: serde_json::Value,
}

#[derive(Debug, Clone)]
pub struct DocumentChunk {
    pub id: String,
    pub doc_id: String,
    pub chunk_idx: i64,
    pub title: String,
    pub content: String,
    pub metadata: serde_json::Value,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StoreStats {
    pub kb_id: String,
    pub schema_version: u32,
    pub document_count: u64,
    pub chunk_count: u64,
    pub vector_count: u64,
    pub generation: u64,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct VectorGenerationState {
    pub generation: u64,
    pub vector_count: u64,
    pub checksum: String,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StoreEmbeddingConfig {
    pub model: String,
    pub dimensions: Option<u32>,
    pub query_prefix: String,
    pub document_prefix: String,
}

#[derive(Debug, Clone)]
pub struct Store {
    root: PathBuf,
    path: PathBuf,
    meta: Meta,
}

impl Store {
    pub fn create(path: impl AsRef<Path>, config: &Config, options: StoreOptions) -> Result<Self> {
        validate_model(&options, config)?;
        let path = resolve_new_kb_path(&config.store_root, path.as_ref())?;
        if path.exists() {
            return Err(Error::AlreadyExists { path });
        }
        let parent = path
            .parent()
            .ok_or_else(|| Error::InvalidOption("KB path has no parent".to_string()))?;
        let tmp = parent.join(format!(".mxp-search-create-{}", Uuid::new_v4().as_simple()));
        fs::create_dir(&tmp).at(&tmp)?;

        let result = (|| {
            let kb_id = options
                .kb_id
                .clone()
                .unwrap_or_else(|| Uuid::new_v4().to_string());
            if kb_id.trim().is_empty() {
                return Err(Error::InvalidOption("kb_id cannot be empty".to_string()));
            }
            let name = options.name.clone().unwrap_or_else(|| {
                path.file_name()
                    .and_then(|name| name.to_str())
                    .unwrap_or("mxp-local-search")
                    .to_string()
            });
            let created_at = OffsetDateTime::now_utc()
                .format(&time::format_description::well_known::Rfc3339)
                .map_err(|err| Error::InvalidOption(err.to_string()))?;
            let meta = Meta {
                schema_version: SCHEMA_VERSION,
                kb_id: kb_id.clone(),
                name,
                model: options.model.clone(),
                dimensions: options.dimensions,
                distance: options.distance.clone(),
                query_prefix: options.query_prefix.clone(),
                document_prefix: options.document_prefix.clone(),
                created_at,
            };
            let marker = Marker {
                schema_version: SCHEMA_VERSION,
                kb_id,
            };
            atomic_write(
                &tmp.join(KB_MARKER),
                serde_json::to_vec_pretty(&marker)?.as_slice(),
            )?;
            atomic_write(
                &tmp.join(META_FILE),
                serde_json::to_vec_pretty(&meta)?.as_slice(),
            )?;
            init_database(&tmp.join(DB_FILE))?;
            fs::rename(&tmp, &path).at(&path)?;
            Ok(Store {
                root: config.store_root.clone(),
                path,
                meta,
            })
        })();

        if result.is_err() {
            let _ = fs::remove_dir_all(&tmp);
        }
        result
    }

    pub fn open(path: impl AsRef<Path>, config: &Config) -> Result<Self> {
        let path = resolve_existing_kb_path(&config.store_root, path.as_ref())?;
        if !path.exists() {
            return Err(Error::NotFound { path });
        }
        let meta = read_validated_meta(&path)?;
        init_database(&path.join(DB_FILE))?;
        Ok(Self {
            root: config.store_root.clone(),
            path,
            meta,
        })
    }

    pub fn embedding_config(&self) -> StoreEmbeddingConfig {
        StoreEmbeddingConfig {
            model: self.meta.model.clone(),
            dimensions: self.meta.dimensions,
            query_prefix: self.meta.query_prefix.clone(),
            document_prefix: self.meta.document_prefix.clone(),
        }
    }

    pub fn exists(path: impl AsRef<Path>, config: &Config) -> bool {
        let Ok(path) = resolve_existing_kb_path(&config.store_root, path.as_ref()) else {
            return false;
        };
        read_validated_meta(&path).is_ok()
    }

    pub fn destroy(path: impl AsRef<Path>, config: &Config, confirm: &str) -> Result<()> {
        let path = resolve_existing_kb_path(&config.store_root, path.as_ref())?;
        let meta = read_validated_meta(&path)?;
        if confirm != destroy_confirmation(&meta.kb_id) {
            return Err(Error::InvalidConfirmation);
        }
        fs::remove_dir_all(&path).at(&path)
    }

    pub fn destroy_confirmation_token(&self) -> String {
        destroy_confirmation(&self.meta.kb_id)
    }

    pub fn kb_id(&self) -> &str {
        &self.meta.kb_id
    }

    pub fn path(&self) -> &Path {
        &self.path
    }

    pub fn root(&self) -> &Path {
        &self.root
    }

    pub fn stats(&self) -> Result<StoreStats> {
        let db = self.connection()?;
        let document_count = count_table(&db, "documents")?;
        let chunk_count = count_table(&db, "chunks")?;
        let vector_generation = read_vector_generation(&db)?;
        Ok(StoreStats {
            kb_id: self.meta.kb_id.clone(),
            schema_version: self.meta.schema_version,
            document_count,
            chunk_count,
            vector_count: vector_generation.vector_count,
            generation: vector_generation.generation,
        })
    }

    pub fn vector_generation_state(&self) -> Result<VectorGenerationState> {
        let db = self.connection()?;
        read_vector_generation(&db)
    }

    pub fn begin_shadow_vector_rebuild(&self) -> Result<VectorGenerationState> {
        self.vector_generation_state()
    }

    pub fn vector_generation_mismatch(&self, expected: &VectorGenerationState) -> Result<bool> {
        let current = self.vector_generation_state()?;
        Ok(current.generation != expected.generation || current.checksum != expected.checksum)
    }

    pub fn finish_shadow_vector_rebuild(
        &self,
        expected_generation: u64,
        vector_count: u64,
        checksum: &str,
    ) -> Result<VectorGenerationState> {
        validate_generation_checksum(checksum)?;
        let db = self.connection()?;
        let tx = db.unchecked_transaction()?;
        let changed = tx.execute(
            "UPDATE vector_generation
             SET generation = generation + 1, vector_count = ?, checksum = ?
             WHERE id = 1 AND generation = ?",
            params![vector_count, checksum, expected_generation],
        )?;
        if changed == 0 {
            let actual = read_vector_generation_tx(&tx)?.generation;
            return Err(Error::VectorGenerationMismatch {
                expected: expected_generation,
                actual,
            });
        }
        tx.commit()?;
        self.vector_generation_state()
    }

    pub fn count(&self) -> Result<u64> {
        Ok(self.stats()?.chunk_count)
    }

    pub fn index(&self, document: &Document) -> Result<UpdateOutcome> {
        self.write_document(document, VectorDecision::Upsert, None)
    }

    pub fn update(&self, document: &Document) -> Result<UpdateOutcome> {
        self.write_document(document, VectorDecision::Upsert, None)
    }

    pub fn index_with_vector(&self, document: &Document, vector: &[f32]) -> Result<UpdateOutcome> {
        self.write_document(document, VectorDecision::Upsert, Some(vector))
    }

    pub fn update_with_vector(&self, document: &Document, vector: &[f32]) -> Result<UpdateOutcome> {
        self.write_document(document, VectorDecision::Upsert, Some(vector))
    }

    pub fn update_reusing_existing_vector(&self, document: &Document) -> Result<UpdateOutcome> {
        self.write_document(document, VectorDecision::ReuseExisting, None)
    }

    pub fn delete(&self, id: &str) -> Result<bool> {
        let db = self.connection()?;
        let tx = db.unchecked_transaction()?;
        let changed = delete_document_tx(&tx, id)?;
        if changed {
            bump_generation_tx(&tx)?;
        }
        tx.commit()?;
        Ok(changed)
    }

    pub fn delete_batch(&self, ids: &[String]) -> Result<usize> {
        let db = self.connection()?;
        let tx = db.unchecked_transaction()?;
        let mut changed = 0;
        for id in ids {
            if delete_document_tx(&tx, id)? {
                changed += 1;
            }
        }
        if changed > 0 {
            bump_generation_tx(&tx)?;
        }
        tx.commit()?;
        Ok(changed)
    }

    pub fn search(
        &self,
        query: &str,
        options: &SearchOptions,
        config: &Config,
    ) -> Result<Vec<SearchResult>> {
        match options.mode {
            SearchMode::Fast => {}
            SearchMode::Semantic => return self.semantic_search(query, options, config),
            SearchMode::Hybrid => return self.hybrid_search(query, options, config),
            SearchMode::Deep => return Err(unsupported_hybrid_backend()),
        }
        let Some(fts_query) = safe_fts_query(query, config.max_query_bytes)? else {
            return Ok(Vec::new());
        };
        let max_limit = config.max_limit.max(1);
        let limit = options.limit.clamp(1, max_limit);
        let max_candidate_limit = config.max_candidate_limit.max(limit);
        let candidate_limit = options
            .candidate_limit
            .unwrap_or_else(|| limit.saturating_mul(10).max(100))
            .clamp(limit, max_candidate_limit);
        let filters = build_filter_sql(&options.filters)?;

        let mut values = Vec::with_capacity(filters.values.len() + 2);
        values.push(rusqlite::types::Value::Text(fts_query));
        values.extend(filters.values);
        values.push(rusqlite::types::Value::Integer(candidate_limit as i64));
        let params = params_from_iter(values.iter());

        let sql = format!(
            "SELECT chunks.id, chunks.doc_id, chunks.title, chunks.content, chunks.metadata_json, bm25(chunks_fts) AS rank \
             FROM chunks_fts JOIN chunks ON chunks_fts.chunk_id = chunks.id \
             WHERE chunks_fts MATCH ?{} \
             ORDER BY rank LIMIT ?",
            filters.clause
        );
        let db = self.connection()?;
        let mut stmt = db.prepare(&sql)?;
        let rows = stmt.query_map(params, |row| {
            let chunk_id: String = row.get(0)?;
            let doc_id: String = row.get(1)?;
            let title: String = row.get(2)?;
            let content: String = row.get(3)?;
            let metadata_json: String = row.get(4)?;
            let rank: f64 = row.get(5)?;
            Ok((chunk_id, doc_id, title, content, metadata_json, rank))
        })?;

        let mut candidates = Vec::new();
        for row in rows {
            candidates.push(row?);
        }
        let best_relevance = candidates
            .iter()
            .map(|(_, _, _, _, _, rank)| fts_relevance(*rank))
            .fold(0.0_f32, f32::max);

        let mut hits = Vec::new();
        for (chunk_id, doc_id, title, content, metadata_json, rank) in candidates {
            let relevance = fts_relevance(rank);
            let score = if best_relevance > 0.0 {
                (relevance / best_relevance).clamp(0.0, 1.0)
            } else {
                0.0
            };
            if score < options.min_score {
                continue;
            }
            hits.push(SearchResult {
                id: chunk_id.clone(),
                chunk_id,
                doc_id,
                kb_id: self.meta.kb_id.clone(),
                score,
                title,
                snippet: snippet(&content),
                metadata: serde_json::from_str(&metadata_json).unwrap_or(serde_json::Value::Null),
                sources: vec!["fts".to_string()],
            });
            if hits.len() >= limit {
                break;
            }
        }
        Ok(hits)
    }

    pub fn semantic_search(
        &self,
        query: &str,
        options: &SearchOptions,
        config: &Config,
    ) -> Result<Vec<SearchResult>> {
        semantic_search_impl(self, query, options, config)
    }

    pub fn vector_search(
        &self,
        query_vector: &[f32],
        options: &VectorSearchOptions,
        config: &Config,
    ) -> Result<Vec<SearchResult>> {
        validate_query_vector(query_vector)?;
        let limit = options.limit.clamp(1, config.max_limit.max(1));
        let filters = build_filter_sql(&[])?;
        self.search_vectors(query_vector, limit, options.min_score, &filters)
    }

    pub fn hybrid_search(
        &self,
        query: &str,
        options: &SearchOptions,
        config: &Config,
    ) -> Result<Vec<SearchResult>> {
        hybrid_search_impl(self, query, options, config)
    }

    fn search_vectors(
        &self,
        query_vector: &[f32],
        limit: usize,
        min_score: f32,
        filters: &crate::search::FilterSql,
    ) -> Result<Vec<SearchResult>> {
        let mut values = filters.values.clone();
        values.push(rusqlite::types::Value::Integer(query_vector.len() as i64));
        let params = params_from_iter(values.iter());
        let sql = format!(
            "SELECT chunks.id, chunks.doc_id, chunks.title, chunks.content, chunks.metadata_json, vectors.vector \
             FROM vectors JOIN chunks ON vectors.chunk_id = chunks.id \
             WHERE 1 = 1{} AND vectors.dimensions = ?",
            filters.clause
        );
        let db = self.connection()?;
        let mut stmt = db.prepare(&sql)?;
        let rows = stmt.query_map(params, |row| {
            let chunk_id: String = row.get(0)?;
            let doc_id: String = row.get(1)?;
            let title: String = row.get(2)?;
            let content: String = row.get(3)?;
            let metadata_json: String = row.get(4)?;
            let vector_blob: Vec<u8> = row.get(5)?;
            Ok((chunk_id, doc_id, title, content, metadata_json, vector_blob))
        })?;

        let mut hits = Vec::new();
        for row in rows {
            let (chunk_id, doc_id, title, content, metadata_json, vector_blob) = row?;
            let vector = decode_f32_vector(&vector_blob)?;
            let score = cosine_similarity(query_vector, &vector)?;
            if score < min_score {
                continue;
            }
            hits.push(SearchResult {
                id: chunk_id.clone(),
                chunk_id,
                doc_id,
                kb_id: self.meta.kb_id.clone(),
                score,
                title,
                snippet: snippet(&content),
                metadata: serde_json::from_str(&metadata_json).unwrap_or(serde_json::Value::Null),
                sources: vec!["vector".to_string()],
            });
        }
        hits.sort_by(|a, b| {
            b.score
                .partial_cmp(&a.score)
                .unwrap_or(std::cmp::Ordering::Equal)
        });
        hits.truncate(limit);
        Ok(hits)
    }

    fn write_document(
        &self,
        document: &Document,
        vector_decision: VectorDecision,
        vector: Option<&[f32]>,
    ) -> Result<UpdateOutcome> {
        validate_id(&document.id)?;
        let payload_hash = payload_hash(&document.title, &document.content, &document.metadata)?;
        let metadata_json = serde_json::to_string(&document.metadata)?;
        let status =
            metadata_text(&document.metadata, "status").unwrap_or_else(|| "publish".to_string());
        let visibility =
            metadata_text(&document.metadata, "visibility").unwrap_or_else(|| "public".to_string());
        let password_protected =
            metadata_bool(&document.metadata, "password_protected").unwrap_or(false);
        let post_id = metadata_i64(&document.metadata, "post_id");
        let post_type = metadata_text(&document.metadata, "post_type");
        let locale = metadata_text(&document.metadata, "locale");
        let tenant_id = metadata_text(&document.metadata, "tenant_id");
        let acl_hash = metadata_text(&document.metadata, "acl_hash");

        let chunk_id = format!("{}_chunk_0", document.id);
        let encoded_vector = vector
            .map(|vector| encode_f32_vector(vector).map(|bytes| (vector.len() as i64, bytes)))
            .transpose()?;

        let db = self.connection()?;
        let tx = db.unchecked_transaction()?;
        let existing_row = tx
            .query_row(
                "SELECT payload_hash, metadata_json FROM documents WHERE id = ?",
                params![document.id],
                |row| Ok((row.get::<_, String>(0)?, row.get::<_, String>(1)?)),
            )
            .optional()?;
        let existing_hash = existing_row.as_ref().map(|(hash, _)| hash.as_str());
        let mut vector_changed = true;
        if existing_hash == Some(payload_hash.as_str()) {
            vector_changed = if let Some((_, bytes)) = &encoded_vector {
                let existing_vector = tx
                    .query_row(
                        "SELECT vector FROM vectors WHERE chunk_id = ?",
                        params![chunk_id],
                        |row| row.get::<_, Vec<u8>>(0),
                    )
                    .optional()?;
                existing_vector.as_deref() != Some(bytes.as_slice())
            } else if matches!(vector_decision, VectorDecision::Upsert) {
                tx.query_row(
                    "SELECT NOT EXISTS(SELECT 1 FROM vectors WHERE chunk_id = ?)",
                    params![chunk_id],
                    |row| row.get::<_, bool>(0),
                )?
            } else {
                false
            };
            if !vector_changed
                && existing_row
                    .as_ref()
                    .is_some_and(|(_, existing_metadata)| existing_metadata == &metadata_json)
            {
                tx.commit()?;
                return Ok(UpdateOutcome::Skipped);
            }
        }
        let existed = existing_hash.is_some();

        tx.execute(
            "INSERT INTO documents (id, title, metadata_json, status, visibility, password_protected, acl_hash, payload_hash, updated_at) \
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, unixepoch()) \
             ON CONFLICT(id) DO UPDATE SET \
               title = excluded.title, metadata_json = excluded.metadata_json, status = excluded.status, \
               visibility = excluded.visibility, password_protected = excluded.password_protected, \
               acl_hash = excluded.acl_hash, payload_hash = excluded.payload_hash, updated_at = excluded.updated_at",
            params![
                document.id,
                document.title,
                metadata_json,
                status,
                visibility,
                i64::from(password_protected),
                acl_hash,
                payload_hash,
            ],
        )?;
        insert_chunk_tx(
            &tx,
            &DocumentChunk {
                id: chunk_id.clone(),
                doc_id: document.id.clone(),
                chunk_idx: 0,
                title: document.title.clone(),
                content: document.content.clone(),
                metadata: document.metadata.clone(),
            },
            &payload_hash,
            post_id,
            post_type.as_deref(),
            &status,
            &visibility,
            password_protected,
            locale.as_deref(),
            tenant_id.as_deref(),
            acl_hash.as_deref(),
        )?;
        match encoded_vector {
            Some((dimensions, bytes)) if vector_changed => {
                upsert_vector_bytes_tx(&tx, &chunk_id, dimensions, bytes)?;
            }
            Some(_) => {}
            None if matches!(vector_decision, VectorDecision::Upsert) && vector_changed => {
                tx.execute("DELETE FROM vectors WHERE chunk_id = ?", params![chunk_id])?;
            }
            None => {}
        }
        if matches!(vector_decision, VectorDecision::Upsert) && vector_changed {
            bump_generation_tx(&tx)?;
        }
        tx.commit()?;

        Ok(match (existed, vector_decision, vector_changed) {
            (false, _, _) => UpdateOutcome::New,
            (true, _, false) | (true, VectorDecision::ReuseExisting, _) => {
                UpdateOutcome::MetadataFtsOnly
            }
            (true, VectorDecision::Upsert, true) => UpdateOutcome::Full,
        })
    }

    fn connection(&self) -> Result<Connection> {
        read_validated_meta(&self.path)?;
        let db_path = self.path.join(DB_FILE);
        let conn = Connection::open(&db_path)?;
        conn.pragma_update(None, "foreign_keys", "ON")?;
        Ok(conn)
    }
}

#[derive(Debug, Clone, Copy)]
enum VectorDecision {
    ReuseExisting,
    Upsert,
}

#[cfg(feature = "embedding-onnx")]
fn semantic_search_impl(
    store: &Store,
    query: &str,
    options: &SearchOptions,
    config: &Config,
) -> Result<Vec<SearchResult>> {
    use crate::embedding::{EmbeddingInputKind, OnnxEmbedder};

    if safe_fts_query(query, config.max_query_bytes)?.is_none() {
        return Ok(Vec::new());
    }
    let require_allowlist = model_requires_allowlist(&store.meta.model, config)?;
    let model_dir = config.model_dir.join(&store.meta.model);
    let embedder = OnnxEmbedder::open(
        &model_dir,
        store.meta.dimensions.map(|dimensions| dimensions as usize),
        E5PrefixConfig::new(
            store.meta.query_prefix.clone(),
            store.meta.document_prefix.clone(),
        )?,
        &config.allowed_models,
        require_allowlist,
    )?;
    let query_vector = embedder.embed(query, EmbeddingInputKind::Query)?;
    let filters = build_filter_sql(&options.filters)?;
    let limit = options.limit.clamp(1, config.max_limit.max(1));
    store.search_vectors(&query_vector, limit, options.min_score, &filters)
}

#[cfg(not(feature = "embedding-onnx"))]
fn semantic_search_impl(
    _store: &Store,
    _query: &str,
    _options: &SearchOptions,
    _config: &Config,
) -> Result<Vec<SearchResult>> {
    Err(unsupported_semantic_backend())
}

#[cfg(feature = "embedding-onnx")]
fn hybrid_search_impl(
    store: &Store,
    query: &str,
    options: &SearchOptions,
    config: &Config,
) -> Result<Vec<SearchResult>> {
    let max_limit = config.max_limit.max(1);
    let limit = options.limit.clamp(1, max_limit);
    let candidate_limit = options
        .candidate_limit
        .unwrap_or_else(|| limit.saturating_mul(10).max(100))
        .clamp(limit, config.max_candidate_limit.max(limit));

    let mut fast_options = options.clone();
    fast_options.mode = SearchMode::Fast;
    fast_options.limit = candidate_limit;
    fast_options.min_score = 0.0;
    let lexical_hits = store.search(query, &fast_options, config)?;

    let mut semantic_options = options.clone();
    semantic_options.mode = SearchMode::Semantic;
    semantic_options.limit = candidate_limit;
    semantic_options.min_score = 0.0;
    let vector_hits = semantic_search_impl(store, query, &semantic_options, config)?;

    let mut merged: std::collections::HashMap<String, (SearchResult, Option<f32>, Option<f32>)> =
        std::collections::HashMap::with_capacity(lexical_hits.len() + vector_hits.len());
    for hit in lexical_hits {
        merged.insert(hit.chunk_id.clone(), (hit.clone(), Some(hit.score), None));
    }
    for hit in vector_hits {
        merged
            .entry(hit.chunk_id.clone())
            .and_modify(|(existing, _, vector_score)| {
                existing.sources.push("vector".to_string());
                *vector_score = Some(hit.score);
            })
            .or_insert_with(|| (hit.clone(), None, Some(hit.score)));
    }

    let gate = ConfidenceGate {
        min_score: options.min_score.max(config.min_hybrid_score),
        min_lexical_score: None,
        min_vector_score: None,
    };
    let weights = ScoreWeights {
        lexical: 0.45,
        vector: 0.55,
    };
    let mut hits = Vec::with_capacity(merged.len());
    for (_, (mut hit, lexical_score, vector_score)) in merged {
        let score = weighted_score_fusion(lexical_score, vector_score, weights)?;
        if !passes_confidence_gate(score, lexical_score, vector_score, gate) {
            continue;
        }
        hit.score = score;
        hit.sources.sort();
        hit.sources.dedup();
        hits.push(hit);
    }
    hits.sort_by(|a, b| {
        b.score
            .partial_cmp(&a.score)
            .unwrap_or(std::cmp::Ordering::Equal)
    });
    hits.truncate(limit);
    Ok(hits)
}

#[cfg(not(feature = "embedding-onnx"))]
fn hybrid_search_impl(
    _store: &Store,
    _query: &str,
    _options: &SearchOptions,
    _config: &Config,
) -> Result<Vec<SearchResult>> {
    Err(unsupported_hybrid_backend())
}

fn init_database(path: &Path) -> Result<()> {
    let conn = Connection::open(path)?;
    conn.pragma_update(None, "journal_mode", "WAL")?;
    conn.pragma_update(None, "foreign_keys", "ON")?;
    conn.execute_batch(
        "CREATE TABLE IF NOT EXISTS documents (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            metadata_json TEXT NOT NULL,
            status TEXT NOT NULL,
            visibility TEXT NOT NULL,
            password_protected INTEGER NOT NULL CHECK(password_protected IN (0, 1)),
            acl_hash TEXT,
            payload_hash TEXT NOT NULL,
            updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS chunks (
            id TEXT PRIMARY KEY,
            doc_id TEXT NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
            chunk_idx INTEGER NOT NULL,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            metadata_json TEXT NOT NULL,
            post_id INTEGER,
            post_type TEXT,
            status TEXT NOT NULL,
            visibility TEXT NOT NULL,
            password_protected INTEGER NOT NULL CHECK(password_protected IN (0, 1)),
            locale TEXT,
            tenant_id TEXT,
            acl_hash TEXT,
            payload_hash TEXT NOT NULL,
            UNIQUE(doc_id, chunk_idx)
        );
        CREATE TABLE IF NOT EXISTS vectors (
            chunk_id TEXT PRIMARY KEY REFERENCES chunks(id) ON DELETE CASCADE,
            dimensions INTEGER NOT NULL,
            vector BLOB NOT NULL,
            updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS vector_generation (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            generation INTEGER NOT NULL,
            vector_count INTEGER NOT NULL,
            checksum TEXT NOT NULL
        );
        INSERT OR IGNORE INTO vector_generation (id, generation, vector_count, checksum)
        VALUES (1, 0, 0, 'empty');
        CREATE VIRTUAL TABLE IF NOT EXISTS chunks_fts USING fts5(
            chunk_id UNINDEXED,
            title,
            content,
            tokenize='trigram'
        );
        CREATE TRIGGER IF NOT EXISTS chunks_ai AFTER INSERT ON chunks BEGIN
            INSERT INTO chunks_fts (chunk_id, title, content) VALUES (new.id, new.title, new.content);
        END;
        CREATE TRIGGER IF NOT EXISTS chunks_ad AFTER DELETE ON chunks BEGIN
            DELETE FROM chunks_fts WHERE chunk_id = old.id;
        END;
        CREATE TRIGGER IF NOT EXISTS chunks_au AFTER UPDATE ON chunks BEGIN
            DELETE FROM chunks_fts WHERE chunk_id = old.id;
            INSERT INTO chunks_fts (chunk_id, title, content) VALUES (new.id, new.title, new.content);
        END;",
    )?;
    Ok(())
}

fn read_validated_meta(path: &Path) -> Result<Meta> {
    if !path.join(KB_MARKER).is_file() {
        return Err(Error::MissingMarker {
            path: path.to_path_buf(),
        });
    }
    let marker_text = fs::read_to_string(path.join(KB_MARKER)).at(path.join(KB_MARKER))?;
    let marker: Marker = serde_json::from_str(&marker_text)?;
    if marker.schema_version != SCHEMA_VERSION {
        return Err(Error::UnsupportedSchema {
            found: marker.schema_version,
            expected: SCHEMA_VERSION,
        });
    }
    let meta_text = fs::read_to_string(path.join(META_FILE)).at(path.join(META_FILE))?;
    let meta: Meta = serde_json::from_str(&meta_text)?;
    if meta.schema_version != SCHEMA_VERSION {
        return Err(Error::UnsupportedSchema {
            found: meta.schema_version,
            expected: SCHEMA_VERSION,
        });
    }
    if meta.kb_id.trim().is_empty() || marker.kb_id != meta.kb_id {
        return Err(Error::MissingMarker {
            path: path.to_path_buf(),
        });
    }
    Ok(meta)
}

pub fn model_requires_allowlist(model: &str, config: &Config) -> Result<bool> {
    if config.allow_local_model_path
        && (model.contains('/') || model.contains('\\'))
        && !config.allowed_models.iter().any(|allowed| allowed == model)
    {
        validate_local_model_path(model)?;
        return Ok(false);
    }
    validate_allowlisted_model_id(model, &config.allowed_models)?;
    Ok(true)
}

fn validate_model(options: &StoreOptions, config: &Config) -> Result<()> {
    model_requires_allowlist(&options.model, config)?;
    let prefixes = E5PrefixConfig::new(&options.query_prefix, &options.document_prefix)?;
    prefixes.validate()?;
    if let Some(dimensions) = options.dimensions {
        if dimensions == 0 || dimensions > 65_536 {
            return Err(Error::InvalidOption(format!(
                "invalid embedding dimensions: {dimensions}"
            )));
        }
    }
    if options.distance != "cosine" {
        return Err(Error::InvalidOption(format!(
            "unsupported distance: {}",
            options.distance
        )));
    }
    Ok(())
}

fn validate_local_model_path(model: &str) -> Result<()> {
    let path = Path::new(model);
    if model.trim().is_empty()
        || path.is_absolute()
        || path
            .components()
            .any(|component| !matches!(component, std::path::Component::Normal(_)))
    {
        return Err(Error::InvalidOption(format!(
            "invalid local model path: {model}"
        )));
    }
    Ok(())
}

fn validate_id(id: &str) -> Result<()> {
    if id.is_empty()
        || id.len() > 256
        || !id
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'-' | b'.'))
    {
        return Err(Error::InvalidOption(format!("invalid document id: {id}")));
    }
    Ok(())
}

fn destroy_confirmation(kb_id: &str) -> String {
    format!("destroy:{kb_id}")
}

fn count_table(db: &Connection, table: &str) -> Result<u64> {
    let sql = format!("SELECT COUNT(*) FROM {table}");
    Ok(db.query_row(&sql, [], |row| row.get::<_, u64>(0))?)
}

fn read_vector_generation(db: &Connection) -> Result<VectorGenerationState> {
    db.query_row(
        "SELECT generation, vector_count, checksum FROM vector_generation WHERE id = 1",
        [],
        |row| {
            Ok(VectorGenerationState {
                generation: row.get(0)?,
                vector_count: row.get(1)?,
                checksum: row.get(2)?,
            })
        },
    )
    .map_err(Into::into)
}

fn read_vector_generation_tx(tx: &rusqlite::Transaction<'_>) -> Result<VectorGenerationState> {
    tx.query_row(
        "SELECT generation, vector_count, checksum FROM vector_generation WHERE id = 1",
        [],
        |row| {
            Ok(VectorGenerationState {
                generation: row.get(0)?,
                vector_count: row.get(1)?,
                checksum: row.get(2)?,
            })
        },
    )
    .map_err(Into::into)
}

fn validate_generation_checksum(checksum: &str) -> Result<()> {
    if checksum.is_empty()
        || checksum.len() > 128
        || !checksum
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'-' | b'.'))
    {
        return Err(Error::InvalidOption(format!(
            "invalid vector generation checksum: {checksum}"
        )));
    }
    Ok(())
}

fn delete_document_tx(tx: &rusqlite::Transaction<'_>, id: &str) -> Result<bool> {
    delete_chunks_tx(tx, id)?;
    let changed = tx.execute("DELETE FROM documents WHERE id = ?", params![id])?;
    if changed > 0 {
        bump_generation_tx(tx)?;
    }
    Ok(changed > 0)
}

fn delete_chunks_tx(tx: &rusqlite::Transaction<'_>, doc_id: &str) -> Result<()> {
    tx.execute("DELETE FROM chunks WHERE doc_id = ?", params![doc_id])?;
    Ok(())
}

#[allow(clippy::too_many_arguments)]
fn insert_chunk_tx(
    tx: &rusqlite::Transaction<'_>,
    chunk: &DocumentChunk,
    payload_hash: &str,
    post_id: Option<i64>,
    post_type: Option<&str>,
    status: &str,
    visibility: &str,
    password_protected: bool,
    locale: Option<&str>,
    tenant_id: Option<&str>,
    acl_hash: Option<&str>,
) -> Result<()> {
    let metadata_json = serde_json::to_string(&chunk.metadata)?;
    tx.execute(
        "INSERT INTO chunks (
            id, doc_id, chunk_idx, title, content, metadata_json, post_id, post_type, status,
            visibility, password_protected, locale, tenant_id, acl_hash, payload_hash
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(id) DO UPDATE SET
          doc_id = excluded.doc_id,
          chunk_idx = excluded.chunk_idx,
          title = excluded.title,
          content = excluded.content,
          metadata_json = excluded.metadata_json,
          post_id = excluded.post_id,
          post_type = excluded.post_type,
          status = excluded.status,
          visibility = excluded.visibility,
          password_protected = excluded.password_protected,
          locale = excluded.locale,
          tenant_id = excluded.tenant_id,
          acl_hash = excluded.acl_hash,
          payload_hash = excluded.payload_hash",
        params![
            chunk.id,
            chunk.doc_id,
            chunk.chunk_idx,
            chunk.title,
            chunk.content,
            metadata_json,
            post_id,
            post_type,
            status,
            visibility,
            i64::from(password_protected),
            locale,
            tenant_id,
            acl_hash,
            payload_hash,
        ],
    )?;
    Ok(())
}

fn upsert_vector_bytes_tx(
    tx: &rusqlite::Transaction<'_>,
    chunk_id: &str,
    dimensions: i64,
    bytes: Vec<u8>,
) -> Result<()> {
    tx.execute(
        "INSERT INTO vectors (chunk_id, dimensions, vector, updated_at)
         VALUES (?, ?, ?, unixepoch())
         ON CONFLICT(chunk_id) DO UPDATE SET
           dimensions = excluded.dimensions,
           vector = excluded.vector,
           updated_at = excluded.updated_at",
        params![chunk_id, dimensions, bytes],
    )?;
    Ok(())
}

fn bump_generation_tx(tx: &rusqlite::Transaction<'_>) -> Result<()> {
    tx.execute(
        "UPDATE vector_generation
         SET generation = generation + 1,
            vector_count = (SELECT COUNT(*) FROM vectors),
            checksum = vector_generation.generation || ':' || (SELECT COUNT(*) FROM vectors) || ':' || lower(hex(randomblob(16)))
         WHERE id = 1",
        [],
    )?;
    Ok(())
}
fn payload_hash(title: &str, content: &str, _metadata: &serde_json::Value) -> Result<String> {
    let mut hasher = Sha256::new();
    hasher.update(title.as_bytes());
    hasher.update([0]);
    hasher.update(content.as_bytes());
    Ok(base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(hasher.finalize()))
}

fn metadata_text(metadata: &serde_json::Value, key: &str) -> Option<String> {
    metadata.get(key)?.as_str().map(ToOwned::to_owned)
}

fn metadata_bool(metadata: &serde_json::Value, key: &str) -> Option<bool> {
    metadata.get(key)?.as_bool()
}

fn metadata_i64(metadata: &serde_json::Value, key: &str) -> Option<i64> {
    metadata.get(key)?.as_i64()
}

fn fts_relevance(rank: f64) -> f32 {
    if rank.is_finite() {
        (-(rank as f32)).max(0.0)
    } else {
        0.0
    }
}

fn snippet(content: &str) -> String {
    const MAX_CHARS: usize = 200;
    let mut end = content.len();
    for (count, (idx, _)) in content.char_indices().enumerate() {
        if count == MAX_CHARS {
            end = idx;
            break;
        }
    }
    content[..end].to_string()
}
