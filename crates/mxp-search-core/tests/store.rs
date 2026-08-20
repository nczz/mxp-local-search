use std::{fs, path::PathBuf};

#[cfg(feature = "embedding-onnx")]
use mxp_search_core::OnnxEmbedder;
use mxp_search_core::{
    canonical_model_cache_key, clamp_score, l2_normalize, validate_model_file_checksum,
    weighted_score_fusion, Config, Document, E5PrefixConfig, Error, Filter, ModelFileManifest,
    ModelManifest, ScoreWeights, SearchMode, SearchOptions, Store, StoreOptions, UpdateOutcome,
    VectorSearchOptions, KB_MARKER, SCHEMA_VERSION,
};
use serde_json::json;
use tempfile::tempdir;

fn config(root: &std::path::Path) -> Config {
    Config::new(root)
}

fn public_doc(id: &str, content: &str) -> Document {
    Document {
        id: id.to_string(),
        title: format!("Title {id}"),
        content: content.to_string(),
        metadata: json!({
            "post_id": 42,
            "post_type": "post",
            "status": "publish",
            "visibility": "public",
            "password_protected": false,
            "locale": "zh_TW",
            "acl_hash": "public"
        }),
    }
}
#[test]
fn local_model_path_requires_allow_local_flag() {
    let root = tempdir().unwrap();
    let mut cfg = config(root.path());
    let model = "custom/model";

    assert!(mxp_search_core::model_requires_allowlist(model, &cfg).is_err());

    cfg.allow_local_model_path = true;
    assert_eq!(
        mxp_search_core::model_requires_allowlist(model, &cfg).unwrap(),
        false
    );
}

fn search_options(filters: Vec<Filter>) -> SearchOptions {
    SearchOptions {
        mode: SearchMode::Fast,
        limit: 10,
        candidate_limit: Some(100),
        filters,
        min_score: 0.0,
    }
}

#[test]
fn rejects_outside_root_path() {
    let root = tempdir().unwrap();
    let outside = tempdir().unwrap();
    let cfg = config(root.path());

    let err = Store::create(outside.path().join("kb"), &cfg, StoreOptions::default()).unwrap_err();
    assert!(matches!(err, Error::OutsideRoot { .. }));
}

#[test]
fn rejects_outside_root_without_creating_parent() {
    let root = tempdir().unwrap();
    let outside = tempdir().unwrap();
    let cfg = config(root.path());
    let parent = outside.path().join("missing-parent");

    let err = Store::open(parent.join("kb"), &cfg).unwrap_err();

    assert!(matches!(err, Error::OutsideRoot { .. }));
    assert!(!parent.exists());
}

#[test]
fn existing_path_checks_do_not_create_missing_parents() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let parent = root.path().join("missing-parent");
    let kb = parent.join("kb");

    let err = Store::open(&kb, &cfg).unwrap_err();
    let exists = Store::exists(&kb, &cfg);

    assert!(matches!(err, Error::NotFound { .. }));
    assert!(!exists);
    assert!(!parent.exists());
}

#[test]
fn existing_path_checks_do_not_create_missing_root() {
    let base = tempdir().unwrap();
    let root = base.path().join("missing-root");
    let cfg = config(&root);
    let kb = root.join("kb");

    let _ = Store::open(&kb, &cfg).unwrap_err();
    let exists = Store::exists(&kb, &cfg);

    assert!(!exists);
    assert!(!root.exists());
}

#[test]
fn outside_create_does_not_create_missing_root() {
    let base = tempdir().unwrap();
    let root = base.path().join("missing-root");
    let outside = tempdir().unwrap();
    let cfg = config(&root);

    let err = Store::create(outside.path().join("kb"), &cfg, StoreOptions::default()).unwrap_err();

    assert!(matches!(err, Error::OutsideRoot { .. }));
    assert!(!root.exists());
}

#[test]
fn outside_create_does_not_create_missing_relative_root() {
    let root = PathBuf::from(format!(
        ".mxp-test-missing-root-{}",
        uuid::Uuid::new_v4().as_simple()
    ));
    let outside = tempdir().unwrap();
    let cfg = config(&root);

    let err = Store::create(outside.path().join("kb"), &cfg, StoreOptions::default()).unwrap_err();

    assert!(matches!(err, Error::OutsideRoot { .. }));
    assert!(!root.exists());
}

#[cfg(unix)]
#[test]
fn rejects_symlink_path() {
    use std::os::unix::fs::symlink;

    let root = tempdir().unwrap();
    let outside = tempdir().unwrap();
    let link = root.path().join("linked-kb");
    symlink(outside.path(), &link).unwrap();
    let cfg = config(root.path());

    let err = Store::create(&link, &cfg, StoreOptions::default()).unwrap_err();
    assert!(matches!(err, Error::SymlinkRejected { .. }));
}

#[test]
fn create_writes_marker_and_meta() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());

    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();

    assert!(store.path().join(KB_MARKER).is_file());
    assert!(store.path().join("meta.json").is_file());
    assert_eq!(store.stats().unwrap().schema_version, SCHEMA_VERSION);
    assert!(!store.kb_id().is_empty());
}

#[test]
fn open_validates_marker_schema_and_kb_id() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let created = Store::create("kb", &cfg, StoreOptions::default()).unwrap();
    let kb_id = created.kb_id().to_string();

    let opened = Store::open("kb", &cfg).unwrap();
    assert_eq!(opened.kb_id(), kb_id);

    fs::write(
        opened.path().join(KB_MARKER),
        r#"{"schema_version":999,"kb_id":"bad"}"#,
    )
    .unwrap();
    let err = Store::open("kb", &cfg).unwrap_err();
    assert!(matches!(err, Error::UnsupportedSchema { .. }));
}

#[test]
fn destroy_without_confirm_fails() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();

    let err = Store::destroy("kb", &cfg, "wrong").unwrap_err();
    assert!(matches!(err, Error::InvalidConfirmation));
    assert!(store.path().exists());
}

#[test]
fn destroy_only_removes_marker_validated_kb() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    fs::create_dir_all(root.path().join("plain-dir")).unwrap();
    fs::write(root.path().join("plain-dir/file.txt"), "keep").unwrap();

    let err = Store::destroy("plain-dir", &cfg, "destroy:anything").unwrap_err();
    assert!(matches!(err, Error::MissingMarker { .. }));
    assert!(root.path().join("plain-dir/file.txt").exists());

    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();
    let token = store.destroy_confirmation_token();
    Store::destroy("kb", &cfg, &token).unwrap();
    assert!(!root.path().join("kb").exists());
}

#[test]
fn stats_returns_stable_skeleton() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();

    let stats = store.stats().unwrap();
    assert_eq!(stats.kb_id, store.kb_id());
    assert_eq!(stats.schema_version, SCHEMA_VERSION);
    assert_eq!(stats.document_count, 0);
    assert_eq!(stats.chunk_count, 0);
    assert_eq!(stats.vector_count, 0);
    assert_eq!(stats.generation, 0);
}

#[test]
fn filters_apply_before_results() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();
    store
        .index(&public_doc("public", "退貨政策 可以退貨"))
        .unwrap();
    let mut private = public_doc("private", "退貨政策 私人退貨");
    private.metadata["visibility"] = json!("private");
    store.index(&private).unwrap();

    let hits = store
        .search(
            "退貨政策",
            &search_options(vec![
                Filter::eq_text("status", "publish"),
                Filter::eq_text("visibility", "public"),
                Filter::eq_bool("password_protected", false),
            ]),
            &cfg,
        )
        .unwrap();

    assert_eq!(hits.len(), 1);
    assert_eq!(hits[0].doc_id, "public");
}

#[test]
fn public_search_excludes_private_and_password_protected_content() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();
    store
        .index(&public_doc("published", "保固政策 公開內容"))
        .unwrap();

    let mut draft = public_doc("draft", "保固政策 草稿內容");
    draft.metadata["status"] = json!("draft");
    store.index(&draft).unwrap();

    let mut private = public_doc("private", "保固政策 私人內容");
    private.metadata["visibility"] = json!("private");
    store.index(&private).unwrap();

    let mut password = public_doc("password", "保固政策 密碼內容");
    password.metadata["password_protected"] = json!(true);
    store.index(&password).unwrap();

    let hits = store
        .search(
            "保固政策",
            &search_options(vec![
                Filter::eq_text("status", "publish"),
                Filter::eq_text("visibility", "public"),
                Filter::eq_bool("password_protected", false),
            ]),
            &cfg,
        )
        .unwrap();

    assert_eq!(
        hits.iter()
            .map(|hit| hit.doc_id.as_str())
            .collect::<Vec<_>>(),
        vec!["published"]
    );
}

#[test]
fn vector_reuse_path_updates_metadata_payload_and_fts() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();
    store
        .index(&public_doc("doc", "原本內容 退貨政策"))
        .unwrap();

    let mut changed = public_doc("doc", "更新內容 退貨政策");
    changed.title = "Changed title".to_string();
    changed.metadata["post_type"] = json!("page");
    let outcome = store.update_reusing_existing_vector(&changed).unwrap();
    assert_eq!(outcome, UpdateOutcome::MetadataFtsOnly);

    let hits = store
        .search(
            "更新內容",
            &search_options(vec![Filter::eq_text("post_type", "page")]),
            &cfg,
        )
        .unwrap();
    assert_eq!(hits.len(), 1);
    assert_eq!(hits[0].title, "Changed title");
}

#[test]
fn updating_document_removes_stale_text_from_fts() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();
    store
        .index(&public_doc("doc", "舊關鍵字 obsolete 退貨政策"))
        .unwrap();
    store
        .update(&public_doc("doc", "新關鍵字 fresh 退貨政策"))
        .unwrap();

    let old_hits = store
        .search("obsolete", &search_options(Vec::new()), &cfg)
        .unwrap();
    assert!(old_hits.is_empty());
    let new_hits = store
        .search("fresh", &search_options(Vec::new()), &cfg)
        .unwrap();
    assert_eq!(new_hits.len(), 1);
}

#[test]
fn malformed_fts_query_is_escaped_and_safe() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();
    store
        .index(&public_doc("doc", "正常內容 退貨政策"))
        .unwrap();

    let hits = store
        .search("\" OR * NEAR(foo", &search_options(Vec::new()), &cfg)
        .unwrap();
    assert!(hits.is_empty());
}

#[test]
fn unsupported_filter_key_is_rejected() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();

    let err = store
        .search(
            "退貨",
            &search_options(vec![Filter::eq_text("1=1 --", "x")]),
            &cfg,
        )
        .unwrap_err();
    assert!(matches!(err, Error::InvalidFilter(_)));
}

#[test]
fn model_manifest_checksum_and_cache_key_are_validated() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let model_path = root.path().join("model.onnx");
    fs::write(&model_path, b"abc").unwrap();
    let checksum = "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad";
    let prefixes = E5PrefixConfig::default();
    let manifest = ModelManifest {
        id: "multilingual-e5-small".to_string(),
        revision: Some("rev-1".to_string()),
        dimensions: 384,
        distance: "cosine".to_string(),
        prefixes: prefixes.clone(),
        files: vec![ModelFileManifest {
            path: "model.onnx".into(),
            sha256: checksum.to_string(),
            size_bytes: Some(3),
        }],
    };

    manifest.validate(&cfg.allowed_models).unwrap();
    manifest.verify_files(root.path()).unwrap();
    validate_model_file_checksum(&model_path, checksum).unwrap();
    assert_eq!(
        manifest.cache_key().unwrap(),
        canonical_model_cache_key(
            "multilingual-e5-small",
            Some("rev-1"),
            384,
            "cosine",
            &prefixes
        )
        .unwrap()
    );

    let err = validate_model_file_checksum(
        &model_path,
        "0000000000000000000000000000000000000000000000000000000000000000",
    )
    .unwrap_err();
    assert!(matches!(err, Error::ModelChecksumMismatch { .. }));
}

#[test]
fn l2_normalize_handles_unit_zero_and_non_finite_vectors() {
    let mut vector = [3.0, 4.0];
    assert!(l2_normalize(&mut vector).unwrap());
    assert!((vector[0] - 0.6).abs() < 0.0001);
    assert!((vector[1] - 0.8).abs() < 0.0001);

    let mut zero = [0.0, 0.0];
    assert!(!l2_normalize(&mut zero).unwrap());
    assert_eq!(zero, [0.0, 0.0]);

    let mut bad = [f32::NAN];
    assert!(matches!(
        l2_normalize(&mut bad),
        Err(Error::InvalidOption(_))
    ));
}

#[test]
fn weighted_score_fusion_clamps_scores_and_rejects_bad_weights() {
    assert_eq!(clamp_score(-0.25), 0.0);
    assert_eq!(clamp_score(1.25), 1.0);
    assert_eq!(clamp_score(f32::NAN), 0.0);

    let fused = weighted_score_fusion(
        Some(2.0),
        Some(0.5),
        ScoreWeights {
            lexical: 1.0,
            vector: 3.0,
        },
    )
    .unwrap();
    assert!((fused - 0.625).abs() < 0.0001);

    let err = weighted_score_fusion(
        Some(0.5),
        Some(0.5),
        ScoreWeights {
            lexical: -1.0,
            vector: 1.0,
        },
    )
    .unwrap_err();
    assert!(matches!(err, Error::InvalidOption(_)));
}

#[test]
fn semantic_and_hybrid_modes_fail_closed_without_available_embedder() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();

    let mut semantic_options = search_options(Vec::new());
    semantic_options.mode = SearchMode::Semantic;
    let err = store
        .search("退貨政策", &semantic_options, &cfg)
        .unwrap_err();
    #[cfg(not(feature = "embedding-onnx"))]
    assert!(matches!(
        err,
        Error::UnsupportedFeature {
            feature: "semantic embeddings",
            ..
        }
    ));
    #[cfg(feature = "embedding-onnx")]
    assert!(matches!(err, Error::InvalidOption(_)));

    let vector_hits = store
        .vector_search(&[0.1, 0.2], &VectorSearchOptions::default(), &cfg)
        .unwrap();
    assert!(vector_hits.is_empty());

    let mut hybrid_options = search_options(Vec::new());
    hybrid_options.mode = SearchMode::Hybrid;
    let err = store.search("退貨政策", &hybrid_options, &cfg).unwrap_err();
    #[cfg(not(feature = "embedding-onnx"))]
    assert!(matches!(
        err,
        Error::UnsupportedFeature {
            feature: "hybrid search",
            ..
        }
    ));
    #[cfg(feature = "embedding-onnx")]
    assert!(matches!(err, Error::InvalidOption(_)));
}

#[test]
fn vector_search_returns_indexed_vectors_by_cosine_similarity() {
    let root = tempdir().unwrap();
    let cfg = config(root.path());
    let store = Store::create("kb", &cfg, StoreOptions::default()).unwrap();
    let first = public_doc("first", "alpha document");
    let second = public_doc("second", "beta document");
    store.index_with_vector(&first, &[1.0, 0.0]).unwrap();
    store.index_with_vector(&second, &[0.0, 1.0]).unwrap();

    let hits = store
        .vector_search(
            &[0.9, 0.1],
            &VectorSearchOptions {
                limit: 10,
                candidate_limit: None,
                min_score: 0.0,
            },
            &cfg,
        )
        .unwrap();
    assert_eq!(hits.len(), 2);
    assert_eq!(hits[0].doc_id, "first");
    assert!(hits[0].score > hits[1].score);
    assert_eq!(store.stats().unwrap().vector_count, 2);
    let mut changed_meta = first.clone();
    changed_meta.metadata["locale"] = json!("en_US");
    assert_eq!(
        store.update_reusing_existing_vector(&changed_meta).unwrap(),
        UpdateOutcome::MetadataFtsOnly
    );
    assert_eq!(store.stats().unwrap().vector_count, 2);
    store
        .index_with_vector(&changed_meta, &[0.0, -1.0])
        .unwrap();
    let hits = store
        .vector_search(&[0.9, 0.1], &VectorSearchOptions::default(), &cfg)
        .unwrap();
    assert_eq!(hits[0].doc_id, "second");
    assert_eq!(store.update(&changed_meta).unwrap(), UpdateOutcome::Skipped);
    assert_eq!(store.stats().unwrap().vector_count, 2);
    assert!(store.delete("first").unwrap());
    let hits = store
        .vector_search(&[0.9, 0.1], &VectorSearchOptions::default(), &cfg)
        .unwrap();
    assert_eq!(hits.len(), 1);
    assert_eq!(hits[0].doc_id, "second");
    assert_eq!(store.stats().unwrap().vector_count, 1);
}

#[cfg(feature = "embedding-onnx")]
#[test]
fn onnx_embedder_requires_local_model_bundle_files() {
    let root = tempdir().unwrap();
    match OnnxEmbedder::open(
        root.path(),
        Some(384),
        E5PrefixConfig::default(),
        &["multilingual-e5-small".to_string()],
        true,
    ) {
        Ok(_) => panic!("missing ONNX model bundle should fail"),
        Err(err) => assert!(matches!(err, Error::InvalidOption(_))),
    }
}
