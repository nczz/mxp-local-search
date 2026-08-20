#[cfg(feature = "php-extension")]
mod php_extension {
    use std::{collections::HashMap, fs, path::PathBuf, sync::OnceLock};

    use ext_php_rs::{
        boxed::ZBox,
        builders::ClassBuilder,
        flags::IniEntryPermission,
        prelude::*,
        types::{ZendClassObject, ZendHashTable, Zval},
        zend::{ce, ClassEntry, ExecutorGlobals, IniEntryDef},
    };
    #[cfg(feature = "embedding-onnx")]
    use mxp_search_core::{
        model_requires_allowlist, E5PrefixConfig, EmbeddingInputKind, OnnxEmbedder,
    };
    use mxp_search_core::{
        Config, Document, Filter, FilterOp, FilterValue, SearchMode, SearchOptions,
        SearchResult as CoreSearchResult, Store as CoreStore, StoreOptions, UpdateOutcome,
    };
    use serde_json::{Map as JsonMap, Number as JsonNumber, Value as JsonValue};

    #[php_const]
    pub const MXP_SEARCH_VERSION: &str = env!("CARGO_PKG_VERSION");
    #[php_const]
    pub const MXP_SEARCH_ONNX: bool = cfg!(feature = "embedding-onnx");
    #[php_const]
    pub const MXP_SEARCH_RERANKER: bool = false;

    #[cfg(not(feature = "embedding-onnx"))]
    const UNSUPPORTED_EMBEDDINGS: &str =
        "MXP Local Search semantic/vector embedding support is not enabled in this build";
    type PhpOptions<'a> = HashMap<String, &'a Zval>;
    static SEARCH_EXCEPTION: OnceLock<usize> = OnceLock::new();

    #[php_class(name = "MXP\\Search\\Store")]
    #[derive(Debug, Clone)]
    pub struct PhpStore {
        inner: CoreStore,
        config: Config,
    }

    #[php_impl]
    impl PhpStore {
        #[optional(options)]
        pub fn create(path: String, options: Option<PhpOptions>) -> PhpResult<Self> {
            let options = options.as_ref();
            let config = config_from_options(&path, options)?;
            let store_options = store_options_from_php(options)?;
            let inner = CoreStore::create(&path, &config, store_options).map_err(php_core_error)?;
            Ok(Self { inner, config })
        }

        #[optional(options)]
        pub fn open(path: String, options: Option<PhpOptions>) -> PhpResult<Self> {
            let options = options.as_ref();
            let config = config_from_options(&path, options)?;
            let inner = CoreStore::open(&path, &config).map_err(php_core_error)?;
            Ok(Self { inner, config })
        }

        pub fn exists(path: String) -> bool {
            config_for_path(&path)
                .map(|config| CoreStore::exists(&path, &config))
                .unwrap_or(false)
        }

        pub fn destroy(path: String, confirm: String) -> PhpResult<bool> {
            let config = config_for_path(&path)?;
            CoreStore::destroy(&path, &config, &confirm).map_err(php_core_error)?;
            Ok(true)
        }

        pub fn list(root_dir: String) -> PhpResult<ZBox<ZendHashTable>> {
            let config = Config::new(configured_store_root());
            let root_dir = confined_existing_dir(&root_dir, &config)?;
            let mut rows = ZendHashTable::new();
            for entry in fs::read_dir(&root_dir).map_err(php_io_error)? {
                let entry = entry.map_err(php_io_error)?;
                let path = entry.path();
                if !path.is_dir() {
                    continue;
                }
                if !CoreStore::exists(&path, &config) {
                    continue;
                }
                let store = CoreStore::open(&path, &config).map_err(php_core_error)?;
                let stats = store.stats().map_err(php_core_error)?;
                rows.push(store_list_row(&store, &stats)?)
                    .map_err(php_zend_error)?;
            }
            Ok(rows)
        }

        #[optional(metadata)]
        pub fn index(
            &self,
            id: String,
            title: String,
            content: String,
            metadata: Option<PhpOptions>,
        ) -> PhpResult<()> {
            let metadata = metadata.as_ref();
            let document = document_from_php(id, title, content, metadata)?;
            index_document(&self.inner, &self.config, &document).map_err(php_core_error)?;
            Ok(())
        }

        #[optional(metadata)]
        pub fn update(
            &self,
            id: String,
            title: String,
            content: String,
            metadata: Option<PhpOptions>,
        ) -> PhpResult<String> {
            let metadata = metadata.as_ref();
            let document = document_from_php(id, title, content, metadata)?;
            let outcome =
                index_document(&self.inner, &self.config, &document).map_err(php_core_error)?;
            Ok(update_outcome_name(outcome).to_string())
        }

        #[rename("indexBatch")]
        pub fn index_batch(
            &self,
            documents: Vec<&ZendHashTable>,
        ) -> PhpResult<ZBox<ZendHashTable>> {
            let mut counts = BatchCounts::default();
            for row in documents {
                let document = document_row_from_php(row)?;
                let outcome =
                    index_document(&self.inner, &self.config, &document).map_err(php_core_error)?;
                counts.add(outcome);
            }
            counts.into_php()
        }

        pub fn delete(&self, id: String) -> PhpResult<bool> {
            self.inner.delete(&id).map_err(php_core_error)
        }

        #[rename("deleteBatch")]
        pub fn delete_batch(&self, ids: Vec<String>) -> PhpResult<i64> {
            let deleted = self.inner.delete_batch(&ids).map_err(php_core_error)?;
            Ok(i64::try_from(deleted).map_err(|_| php_exception("delete count overflow"))?)
        }

        #[optional(options)]
        pub fn search(
            &self,
            query: String,
            options: Option<PhpOptions>,
        ) -> PhpResult<ZBox<ZendHashTable>> {
            let options = options.as_ref();
            let search_options = search_options_from_php(options, &self.config)?;
            reject_unsupported_search_mode(search_options.mode)?;
            let results = self
                .inner
                .search(&query, &search_options, &self.config)
                .map_err(php_core_error)?;
            search_results_to_php(results)
        }

        pub fn count(&self) -> PhpResult<i64> {
            let count = self.inner.count().map_err(php_core_error)?;
            Ok(i64::try_from(count).map_err(|_| php_exception("chunk count overflow"))?)
        }

        pub fn stats(&self) -> PhpResult<ZBox<ZendHashTable>> {
            let stats = self.inner.stats().map_err(php_core_error)?;
            stats_to_php(&stats)
        }

        #[rename("kb_id")]
        pub fn kb_id(&self) -> String {
            self.inner.kb_id().to_string()
        }

        pub fn path(&self) -> String {
            self.inner.path().display().to_string()
        }

        #[rename("destroyConfirmationToken")]
        pub fn destroy_confirmation_token(&self) -> String {
            self.inner.destroy_confirmation_token()
        }

        pub fn export(&self, _path: String, _confirm: String) -> PhpResult<i64> {
            Err(php_exception(
                "MXP Local Search export is not supported by this extension build",
            ))
        }

        #[rename("import")]
        pub fn import_json(&self, _path: String, _confirm: String) -> PhpResult<i64> {
            Err(php_exception(
                "MXP Local Search import is not supported by this extension build",
            ))
        }

        pub fn rebuild(&self, _confirm: String) -> PhpResult<()> {
            Err(php_exception(
                "MXP Local Search vector rebuild is not supported by this extension build",
            ))
        }

        pub fn close(&self) {}
    }

    #[php_class(name = "MXP\\Search\\MultiSearch")]
    pub struct MultiSearch;

    #[php_impl]
    impl MultiSearch {
        #[optional(options)]
        pub fn across(
            stores: Vec<&ZendClassObject<PhpStore>>,
            query: String,
            options: Option<PhpOptions>,
        ) -> PhpResult<ZBox<ZendHashTable>> {
            let options = options.as_ref();
            let search_options = search_options_from_php(options, &Config::new("."))?;
            reject_unsupported_search_mode(search_options.mode)?;
            let weights = option_array(options, "weights");
            let mut merged = Vec::new();
            for store in stores {
                let weight = weights
                    .and_then(|table| table.get(store.inner.kb_id()))
                    .and_then(zval_to_f64)
                    .unwrap_or(1.0) as f32;
                let mut results = store
                    .inner
                    .search(&query, &search_options, &store.config)
                    .map_err(php_core_error)?;
                for result in &mut results {
                    result.score = (result.score * weight).clamp(0.0, 1.0);
                }
                merged.extend(results);
            }
            merged.sort_by(|a, b| {
                b.score
                    .partial_cmp(&a.score)
                    .unwrap_or(std::cmp::Ordering::Equal)
            });
            merged.truncate(search_options.limit);
            search_results_to_php(merged)
        }
    }

    #[php_class(name = "MXP\\Search\\Embedder")]
    pub struct Embedder {
        #[cfg(feature = "embedding-onnx")]
        inner: OnnxEmbedder,
    }

    #[php_impl]
    impl Embedder {
        #[optional(options)]
        pub fn __construct(
            model_id_or_path: String,
            options: Option<PhpOptions>,
        ) -> PhpResult<Self> {
            construct_embedder(model_id_or_path, options.as_ref())
        }

        pub fn embed(&self, text: String) -> PhpResult<Vec<f64>> {
            embed_text(self, &text, EmbeddingUse::Document)
        }

        #[rename("embedQuery")]
        pub fn embed_query(&self, text: String) -> PhpResult<Vec<f64>> {
            embed_text(self, &text, EmbeddingUse::Query)
        }

        #[rename("embedBatch")]
        pub fn embed_batch(&self, texts: Vec<String>) -> PhpResult<Vec<Vec<f64>>> {
            embed_batch_texts(self, &texts, EmbeddingUse::Document)
        }

        pub fn dimensions(&self) -> PhpResult<i64> {
            embedder_dimensions(self)
        }

        pub fn close(&self) {}
    }

    #[derive(Default)]
    struct BatchCounts {
        new: i64,
        full: i64,
        metadata_fts_only: i64,
        skipped: i64,
    }

    impl BatchCounts {
        fn add(&mut self, outcome: UpdateOutcome) {
            match outcome {
                UpdateOutcome::New => self.new += 1,
                UpdateOutcome::Full => self.full += 1,
                UpdateOutcome::MetadataFtsOnly => self.metadata_fts_only += 1,
                UpdateOutcome::Skipped => self.skipped += 1,
            }
        }

        fn into_php(self) -> PhpResult<ZBox<ZendHashTable>> {
            let mut row = ZendHashTable::new();
            row.insert("new", self.new).map_err(php_zend_error)?;
            row.insert("full", self.full).map_err(php_zend_error)?;
            row.insert("metadata_fts_only", self.metadata_fts_only)
                .map_err(php_zend_error)?;
            row.insert("skipped", self.skipped)
                .map_err(php_zend_error)?;
            Ok(row)
        }
    }

    #[derive(Clone, Copy)]
    enum EmbeddingUse {
        Query,
        Document,
    }

    #[cfg(feature = "embedding-onnx")]
    fn construct_embedder(
        model_id_or_path: String,
        options: Option<&PhpOptions>,
    ) -> PhpResult<Embedder> {
        let config = config_from_options("", options)?;
        let (model_dir, require_allowlist) = embedding_model_dir(&model_id_or_path, &config)?;
        let defaults = StoreOptions::default();
        let prefixes = mxp_search_core::E5PrefixConfig::new(
            option_string(options, "query_prefix").unwrap_or(defaults.query_prefix),
            option_string(options, "document_prefix").unwrap_or(defaults.document_prefix),
        )
        .map_err(php_core_error)?;
        let dimensions = option_usize(options, "dimensions")?;
        let inner = OnnxEmbedder::open(
            model_dir,
            dimensions,
            prefixes,
            &config.allowed_models,
            require_allowlist,
        )
        .map_err(php_core_error)?;
        Ok(Embedder { inner })
    }

    #[cfg(not(feature = "embedding-onnx"))]
    fn construct_embedder(
        _model_id_or_path: String,
        _options: Option<&PhpOptions>,
    ) -> PhpResult<Embedder> {
        Err(php_exception(UNSUPPORTED_EMBEDDINGS))
    }

    #[cfg(feature = "embedding-onnx")]
    fn embed_text(embedder: &Embedder, text: &str, use_case: EmbeddingUse) -> PhpResult<Vec<f64>> {
        let kind = match use_case {
            EmbeddingUse::Query => EmbeddingInputKind::Query,
            EmbeddingUse::Document => EmbeddingInputKind::Document,
        };
        embedder
            .inner
            .embed(text, kind)
            .map(|vector| vector.into_iter().map(f64::from).collect())
            .map_err(php_core_error)
    }

    #[cfg(not(feature = "embedding-onnx"))]
    fn embed_text(
        _embedder: &Embedder,
        _text: &str,
        _use_case: EmbeddingUse,
    ) -> PhpResult<Vec<f64>> {
        Err(php_exception(UNSUPPORTED_EMBEDDINGS))
    }

    #[cfg(feature = "embedding-onnx")]
    fn embed_batch_texts(
        embedder: &Embedder,
        texts: &[String],
        use_case: EmbeddingUse,
    ) -> PhpResult<Vec<Vec<f64>>> {
        texts
            .iter()
            .map(|text| embed_text(embedder, text, use_case))
            .collect()
    }

    #[cfg(not(feature = "embedding-onnx"))]
    fn embed_batch_texts(
        _embedder: &Embedder,
        _texts: &[String],
        _use_case: EmbeddingUse,
    ) -> PhpResult<Vec<Vec<f64>>> {
        Err(php_exception(UNSUPPORTED_EMBEDDINGS))
    }

    #[cfg(feature = "embedding-onnx")]
    fn embedder_dimensions(embedder: &Embedder) -> PhpResult<i64> {
        embedder
            .inner
            .dimensions()
            .map(|dimensions| dimensions as i64)
            .ok_or_else(|| php_exception("MXP Local Search embedder dimensions are unknown"))
    }

    #[cfg(not(feature = "embedding-onnx"))]
    fn embedder_dimensions(_embedder: &Embedder) -> PhpResult<i64> {
        Err(php_exception(UNSUPPORTED_EMBEDDINGS))
    }

    #[cfg(feature = "embedding-onnx")]
    fn embedding_model_dir(model_id_or_path: &str, config: &Config) -> PhpResult<(PathBuf, bool)> {
        let path = PathBuf::from(model_id_or_path);
        if path.is_absolute() {
            if !config.allow_local_model_path {
                return Err(php_exception(
                    "MXP Local Search local model paths require allow_local_model_path",
                ));
            }
            return Ok((fs::canonicalize(&path).map_err(php_io_error)?, false));
        }
        let require_allowlist =
            model_requires_allowlist(model_id_or_path, config).map_err(php_core_error)?;
        Ok((config.model_dir.join(model_id_or_path), require_allowlist))
    }

    fn reject_unsupported_search_mode(mode: SearchMode) -> PhpResult<()> {
        #[cfg(not(feature = "embedding-onnx"))]
        if !matches!(mode, SearchMode::Fast) {
            return Err(php_exception(UNSUPPORTED_EMBEDDINGS));
        }
        let _ = mode;
        Ok(())
    }

    #[cfg(feature = "embedding-onnx")]
    fn store_embedder(
        store: &CoreStore,
        config: &Config,
    ) -> Result<OnnxEmbedder, mxp_search_core::Error> {
        let embedding = store.embedding_config();
        let model_path = PathBuf::from(&embedding.model);
        let require_allowlist = if model_path.is_absolute() {
            false
        } else {
            model_requires_allowlist(&embedding.model, config)?
        };
        let model_dir = if model_path.is_absolute() {
            if !config.allow_local_model_path {
                return Err(mxp_search_core::Error::InvalidOption(
                    "MXP Local Search local model paths require allow_local_model_path".to_string(),
                ));
            }
            fs::canonicalize(&model_path).map_err(|source| mxp_search_core::Error::Io {
                path: model_path.clone(),
                source,
            })?
        } else {
            config.model_dir.join(&embedding.model)
        };
        OnnxEmbedder::open(
            model_dir,
            embedding.dimensions.map(|dimensions| dimensions as usize),
            E5PrefixConfig::new(embedding.query_prefix, embedding.document_prefix)?,
            &config.allowed_models,
            require_allowlist,
        )
    }

    #[cfg(feature = "embedding-onnx")]
    fn index_document(
        store: &CoreStore,
        config: &Config,
        document: &Document,
    ) -> Result<UpdateOutcome, mxp_search_core::Error> {
        let embedder = store_embedder(store, config)?;
        let vector = embedder.embed(&document.content, EmbeddingInputKind::Document)?;
        store.index_with_vector(document, &vector)
    }

    #[cfg(not(feature = "embedding-onnx"))]
    fn index_document(
        store: &CoreStore,
        _config: &Config,
        document: &Document,
    ) -> Result<UpdateOutcome, mxp_search_core::Error> {
        store.index(document)
    }

    fn config_for_path(_path: &str) -> PhpResult<Config> {
        let mut config = Config::new(configured_store_root());
        apply_ini_config(&mut config)?;
        validate_config_caps(&config)?;
        Ok(config)
    }

    fn config_from_options(_path: &str, options: Option<&PhpOptions>) -> PhpResult<Config> {
        let mut config = Config::new(configured_store_root());
        apply_ini_config(&mut config)?;
        if let Some(export_root) = option_string(options, "export_root") {
            config.export_root = export_root.into();
        }
        if let Some(model_dir) = option_string(options, "model_dir") {
            config.model_dir = model_dir.into();
        }
        if let Some(allowed_models) = option_string_list(options, "allowed_models")? {
            config.allowed_models = allowed_models;
        }
        if let Some(allow_local_model_path) = option_bool(options, "allow_local_model_path") {
            config.allow_local_model_path = allow_local_model_path;
        }
        if let Some(default_mode) = option_string(options, "default_mode") {
            config.default_mode = default_mode;
        }
        if let Some(max_limit) = option_usize(options, "max_limit")? {
            config.max_limit = max_limit;
        }
        if let Some(max_candidate_limit) = option_usize(options, "max_candidate_limit")? {
            config.max_candidate_limit = max_candidate_limit;
        }
        if let Some(max_query_bytes) = option_usize(options, "max_query_bytes")? {
            config.max_query_bytes = max_query_bytes;
        }
        if let Some(min_hybrid_score) = option_f32(options, "min_hybrid_score")? {
            config.min_hybrid_score = min_hybrid_score;
        }
        validate_config_caps(&config)?;
        Ok(config)
    }

    fn validate_config_caps(config: &Config) -> PhpResult<()> {
        if config.max_limit == 0 {
            return Err(php_exception(
                "MXP Local Search max_limit must be greater than zero",
            ));
        }
        if config.max_candidate_limit < config.max_limit {
            return Err(php_exception(
                "MXP Local Search max_candidate_limit must be greater than or equal to max_limit",
            ));
        }
        Ok(())
    }

    fn configured_store_root() -> PathBuf {
        std::env::var_os("MXP_SEARCH_STORE_ROOT")
            .map(PathBuf::from)
            .or_else(|| ini_string("mxp_search.store_root").map(PathBuf::from))
            .unwrap_or_else(|| PathBuf::from("/var/lib/mxp-local-search/kb"))
    }

    fn apply_ini_config(config: &mut Config) -> PhpResult<()> {
        if let Some(export_root) = ini_string("mxp_search.export_root") {
            config.export_root = export_root.into();
        }
        if let Some(model_dir) = ini_string("mxp_search.model_dir") {
            config.model_dir = model_dir.into();
        }
        if let Some(allowed_models) = ini_string("mxp_search.allowed_models") {
            config.allowed_models = allowed_models
                .split(',')
                .map(str::trim)
                .filter(|model| !model.is_empty())
                .map(ToOwned::to_owned)
                .collect();
        }
        if let Some(default_mode) = ini_string("mxp_search.default_mode") {
            config.default_mode = default_mode;
        }
        if let Some(max_limit) = ini_usize("mxp_search.max_limit")? {
            config.max_limit = max_limit;
        }
        if let Some(max_candidate_limit) = ini_usize("mxp_search.max_candidate_limit")? {
            config.max_candidate_limit = max_candidate_limit;
        }
        if let Some(max_query_bytes) = ini_usize("mxp_search.max_query_bytes")? {
            config.max_query_bytes = max_query_bytes;
        }
        if let Some(min_hybrid_score) = ini_f32("mxp_search.min_hybrid_score")? {
            config.min_hybrid_score = min_hybrid_score;
        }
        Ok(())
    }

    fn ini_string(name: &str) -> Option<String> {
        ExecutorGlobals::get().ini_values().remove(name).flatten()
    }

    fn ini_usize(name: &str) -> PhpResult<Option<usize>> {
        ini_string(name)
            .map(|value| {
                value.parse::<usize>().map_err(|_| {
                    php_exception(format!(
                        "MXP Local Search INI setting must be an integer: {name}"
                    ))
                })
            })
            .transpose()
    }

    fn ini_f32(name: &str) -> PhpResult<Option<f32>> {
        ini_string(name)
            .map(|value| {
                value.parse::<f32>().map_err(|_| {
                    php_exception(format!(
                        "MXP Local Search INI setting must be numeric: {name}"
                    ))
                })
            })
            .transpose()
    }

    fn confined_existing_dir(path: &str, config: &Config) -> PhpResult<PathBuf> {
        let root = fs::canonicalize(&config.store_root).map_err(php_io_error)?;
        let requested = fs::canonicalize(path).map_err(php_io_error)?;
        if !requested.is_dir() || (requested != root && !requested.starts_with(&root)) {
            return Err(php_exception(
                "MXP Local Search path is outside the configured store root",
            ));
        }
        Ok(requested)
    }

    fn store_options_from_php(options: Option<&PhpOptions>) -> PhpResult<StoreOptions> {
        let mut store_options = StoreOptions::default();
        if let Some(name) = option_string(options, "name") {
            store_options.name = Some(name);
        }
        if let Some(kb_id) = option_string(options, "kb_id") {
            store_options.kb_id = Some(kb_id);
        }
        if let Some(model) = option_string(options, "model") {
            store_options.model = model;
        }
        if let Some(dimensions) = option_u32(options, "dimensions")? {
            store_options.dimensions = Some(dimensions);
        }
        if let Some(distance) = option_string(options, "distance") {
            store_options.distance = distance;
        }
        if let Some(query_prefix) = option_string(options, "query_prefix") {
            store_options.query_prefix = query_prefix;
        }
        if let Some(document_prefix) = option_string(options, "document_prefix") {
            store_options.document_prefix = document_prefix;
        }
        Ok(store_options)
    }

    fn search_options_from_php(
        options: Option<&PhpOptions>,
        config: &Config,
    ) -> PhpResult<SearchOptions> {
        let mut search_options = SearchOptions::default();
        let mode = option_string(options, "mode").unwrap_or_else(|| config.default_mode.clone());
        search_options.mode = SearchMode::parse(&mode).map_err(php_core_error)?;
        if let Some(limit) = option_usize(options, "limit")? {
            search_options.limit = limit;
        }
        if let Some(candidate_limit) = option_usize(options, "candidate_limit")? {
            search_options.candidate_limit = Some(candidate_limit);
        }
        if let Some(min_score) = option_f32(options, "min_score")? {
            search_options.min_score = min_score;
        } else {
            search_options.min_score = config.min_hybrid_score;
        }
        if let Some(filters) = option_array(options, "filters") {
            search_options.filters = filters_from_php(filters)?;
        }
        Ok(search_options)
    }

    fn filters_from_php(filters: &ZendHashTable) -> PhpResult<Vec<Filter>> {
        let mut parsed = Vec::new();
        for (_, value) in filters {
            let row = value
                .array()
                .ok_or_else(|| php_exception("MXP Local Search filters must be arrays"))?;
            let key = required_string(row, "key")?;
            let op = table_option_string(row, "op").unwrap_or_else(|| "eq".to_string());
            let value = row
                .get("value")
                .ok_or_else(|| php_exception("MXP Local Search filter value is required"))?;
            let (op, value) = match op.as_str() {
                "eq" => (FilterOp::Eq, filter_scalar(value)?),
                "in" => (FilterOp::In, filter_list(value)?),
                "range" => (FilterOp::Range, filter_range(value)?),
                other => {
                    return Err(php_exception(format!(
                        "MXP Local Search unsupported filter operator: {other}"
                    )))
                }
            };
            parsed.push(Filter { key, op, value });
        }
        Ok(parsed)
    }

    fn filter_scalar(value: &Zval) -> PhpResult<FilterValue> {
        if let Some(text) = value.string() {
            Ok(FilterValue::Text(text))
        } else if let Some(integer) = zval_to_i64(value) {
            Ok(FilterValue::Integer(integer))
        } else if let Some(flag) = value.bool() {
            Ok(FilterValue::Bool(flag))
        } else {
            Err(php_exception(
                "MXP Local Search filter value must be string, integer, or bool",
            ))
        }
    }

    fn filter_list(value: &Zval) -> PhpResult<FilterValue> {
        let array = value
            .array()
            .ok_or_else(|| php_exception("MXP Local Search in-filter value must be an array"))?;
        let mut strings = Vec::new();
        let mut integers = Vec::new();
        for (_, item) in array {
            if let Some(text) = item.string() {
                if !integers.is_empty() {
                    return Err(php_exception(
                        "MXP Local Search in-filter values must not mix strings and integers",
                    ));
                }
                strings.push(text);
            } else if let Some(integer) = zval_to_i64(item) {
                if !strings.is_empty() {
                    return Err(php_exception(
                        "MXP Local Search in-filter values must not mix strings and integers",
                    ));
                }
                integers.push(integer);
            } else {
                return Err(php_exception(
                    "MXP Local Search in-filter values must be strings or integers",
                ));
            }
        }
        if !strings.is_empty() {
            Ok(FilterValue::TextList(strings))
        } else {
            Ok(FilterValue::IntegerList(integers))
        }
    }

    fn filter_range(value: &Zval) -> PhpResult<FilterValue> {
        let array = value
            .array()
            .ok_or_else(|| php_exception("MXP Local Search range-filter value must be an array"))?;
        let min = array.get("min").and_then(zval_to_i64);
        let max = array.get("max").and_then(zval_to_i64);
        if min.is_none() && max.is_none() {
            return Err(php_exception(
                "MXP Local Search range-filter requires min or max",
            ));
        }
        Ok(FilterValue::IntegerRange { min, max })
    }

    fn document_row_from_php(row: &ZendHashTable) -> PhpResult<Document> {
        let id = required_string(row, "id")?;
        let title = required_string(row, "title")?;
        let content = required_string(row, "content")?;
        let metadata = row
            .get("metadata")
            .and_then(|value| value.array())
            .map(json_from_array)
            .transpose()?
            .unwrap_or_else(|| JsonValue::Object(JsonMap::new()));
        Ok(Document {
            id,
            title,
            content,
            metadata,
        })
    }

    fn document_from_php(
        id: String,
        title: String,
        content: String,
        metadata: Option<&PhpOptions>,
    ) -> PhpResult<Document> {
        Ok(Document {
            id,
            title,
            content,
            metadata: metadata
                .map(json_from_options)
                .transpose()?
                .unwrap_or_else(|| JsonValue::Object(JsonMap::new())),
        })
    }

    fn search_results_to_php(results: Vec<CoreSearchResult>) -> PhpResult<ZBox<ZendHashTable>> {
        let mut rows = ZendHashTable::new();
        for result in results {
            rows.push(search_result_to_php(result)?)
                .map_err(php_zend_error)?;
        }
        Ok(rows)
    }

    fn search_result_to_php(result: CoreSearchResult) -> PhpResult<ZBox<ZendHashTable>> {
        let mut row = ZendHashTable::new();
        row.insert("id", result.id).map_err(php_zend_error)?;
        row.insert("chunk_id", result.chunk_id)
            .map_err(php_zend_error)?;
        row.insert("doc_id", result.doc_id)
            .map_err(php_zend_error)?;
        row.insert("kb_id", result.kb_id).map_err(php_zend_error)?;
        row.insert("score", f64::from(result.score))
            .map_err(php_zend_error)?;
        row.insert("title", result.title).map_err(php_zend_error)?;
        row.insert("snippet", result.snippet)
            .map_err(php_zend_error)?;
        row.insert("metadata", json_to_zval(&result.metadata)?)
            .map_err(php_zend_error)?;
        row.insert("sources", result.sources)
            .map_err(php_zend_error)?;
        Ok(row)
    }

    fn store_list_row(
        store: &CoreStore,
        stats: &mxp_search_core::StoreStats,
    ) -> PhpResult<ZBox<ZendHashTable>> {
        let mut row = ZendHashTable::new();
        row.insert("kb_id", stats.kb_id.clone())
            .map_err(php_zend_error)?;
        row.insert("path", store.path().display().to_string())
            .map_err(php_zend_error)?;
        row.insert(
            "name",
            store
                .path()
                .file_name()
                .and_then(|name| name.to_str())
                .unwrap_or("")
                .to_string(),
        )
        .map_err(php_zend_error)?;
        row.insert(
            "document_count",
            i64::try_from(stats.document_count).unwrap_or(i64::MAX),
        )
        .map_err(php_zend_error)?;
        row.insert(
            "chunk_count",
            i64::try_from(stats.chunk_count).unwrap_or(i64::MAX),
        )
        .map_err(php_zend_error)?;
        row.insert("model", "").map_err(php_zend_error)?;
        Ok(row)
    }

    fn stats_to_php(stats: &mxp_search_core::StoreStats) -> PhpResult<ZBox<ZendHashTable>> {
        let mut row = ZendHashTable::new();
        row.insert("kb_id", stats.kb_id.clone())
            .map_err(php_zend_error)?;
        row.insert("schema_version", i64::from(stats.schema_version))
            .map_err(php_zend_error)?;
        row.insert(
            "document_count",
            i64::try_from(stats.document_count).unwrap_or(i64::MAX),
        )
        .map_err(php_zend_error)?;
        row.insert(
            "chunk_count",
            i64::try_from(stats.chunk_count).unwrap_or(i64::MAX),
        )
        .map_err(php_zend_error)?;
        row.insert(
            "vector_count",
            i64::try_from(stats.vector_count).unwrap_or(i64::MAX),
        )
        .map_err(php_zend_error)?;
        row.insert(
            "generation",
            i64::try_from(stats.generation).unwrap_or(i64::MAX),
        )
        .map_err(php_zend_error)?;
        Ok(row)
    }

    fn json_from_array(array: &ZendHashTable) -> PhpResult<JsonValue> {
        let mut object = JsonMap::new();
        for (key, value) in array {
            object.insert(key.to_string(), json_from_zval(value)?);
        }
        Ok(JsonValue::Object(object))
    }

    fn json_from_options(options: &PhpOptions) -> PhpResult<JsonValue> {
        let mut object = JsonMap::new();
        for (key, value) in options {
            object.insert(key.clone(), json_from_zval(value)?);
        }
        Ok(JsonValue::Object(object))
    }

    fn json_from_zval(value: &Zval) -> PhpResult<JsonValue> {
        if value.is_null() {
            Ok(JsonValue::Null)
        } else if let Some(flag) = value.bool() {
            Ok(JsonValue::Bool(flag))
        } else if let Some(integer) = zval_to_i64(value) {
            Ok(JsonValue::Number(JsonNumber::from(integer)))
        } else if let Some(float) = value.double() {
            JsonNumber::from_f64(float)
                .map(JsonValue::Number)
                .ok_or_else(|| php_exception("MXP Local Search metadata float must be finite"))
        } else if let Some(text) = value.string() {
            Ok(JsonValue::String(text))
        } else if let Some(array) = value.array() {
            json_from_array(array)
        } else {
            Err(php_exception(
                "MXP Local Search metadata values must be scalars or arrays",
            ))
        }
    }

    fn json_to_zval(value: &JsonValue) -> PhpResult<Zval> {
        let mut zval = Zval::new();
        match value {
            JsonValue::Null => zval.set_null(),
            JsonValue::Bool(flag) => zval.set_bool(*flag),
            JsonValue::Number(number) => {
                if let Some(integer) = number.as_i64() {
                    zval.set_long(integer);
                } else if let Some(float) = number.as_f64() {
                    zval.set_double(float);
                } else {
                    zval.set_null();
                }
            }
            JsonValue::String(text) => zval.set_string(text, false).map_err(php_zend_error)?,
            JsonValue::Array(values) => {
                let mut array = ZendHashTable::new();
                for value in values {
                    array.push(json_to_zval(value)?).map_err(php_zend_error)?;
                }
                zval.set_hashtable(array);
            }
            JsonValue::Object(values) => {
                let mut array = ZendHashTable::new();
                for (key, value) in values {
                    array
                        .insert(key, json_to_zval(value)?)
                        .map_err(php_zend_error)?;
                }
                zval.set_hashtable(array);
            }
        }
        Ok(zval)
    }

    fn required_string(table: &ZendHashTable, key: &str) -> PhpResult<String> {
        table
            .get(key)
            .and_then(|value| value.string())
            .ok_or_else(|| php_exception(format!("MXP Local Search missing string field: {key}")))
    }

    fn table_option_string(table: &ZendHashTable, key: &str) -> Option<String> {
        table.get(key)?.string()
    }

    fn option_array<'a>(
        options: Option<&'a PhpOptions<'a>>,
        key: &str,
    ) -> Option<&'a ZendHashTable> {
        options?.get(key).copied()?.array()
    }

    fn option_string(options: Option<&PhpOptions>, key: &str) -> Option<String> {
        options?.get(key).copied()?.string()
    }

    fn option_bool(options: Option<&PhpOptions>, key: &str) -> Option<bool> {
        options?.get(key).copied()?.bool()
    }

    fn option_string_list(
        options: Option<&PhpOptions>,
        key: &str,
    ) -> PhpResult<Option<Vec<String>>> {
        let Some(value) = options.and_then(|options| options.get(key)) else {
            return Ok(None);
        };
        if let Some(text) = value.string() {
            return Ok(Some(
                text.split(',')
                    .map(str::trim)
                    .filter(|item| !item.is_empty())
                    .map(ToOwned::to_owned)
                    .collect(),
            ));
        }
        let array = value.array().ok_or_else(|| {
            php_exception("MXP Local Search allowed_models must be a string or string array")
        })?;
        let mut values = Vec::new();
        for (_, item) in array {
            values.push(item.string().ok_or_else(|| {
                php_exception("MXP Local Search allowed_models item must be a string")
            })?);
        }
        Ok(Some(values))
    }

    fn option_usize(options: Option<&PhpOptions>, key: &str) -> PhpResult<Option<usize>> {
        let Some(value) = options.and_then(|options| options.get(key)) else {
            return Ok(None);
        };
        let integer = zval_to_i64(value).ok_or_else(|| {
            php_exception(format!("MXP Local Search option must be an integer: {key}"))
        })?;
        usize::try_from(integer).map(Some).map_err(|_| {
            php_exception(format!(
                "MXP Local Search option must be non-negative: {key}"
            ))
        })
    }

    fn option_u32(options: Option<&PhpOptions>, key: &str) -> PhpResult<Option<u32>> {
        let Some(value) = options.and_then(|options| options.get(key)) else {
            return Ok(None);
        };
        let integer = zval_to_i64(value).ok_or_else(|| {
            php_exception(format!("MXP Local Search option must be an integer: {key}"))
        })?;
        u32::try_from(integer)
            .map(Some)
            .map_err(|_| php_exception(format!("MXP Local Search option is out of range: {key}")))
    }

    fn option_f32(options: Option<&PhpOptions>, key: &str) -> PhpResult<Option<f32>> {
        let Some(value) = options.and_then(|options| options.get(key)) else {
            return Ok(None);
        };
        zval_to_f64(value)
            .map(|value| Some(value as f32))
            .ok_or_else(|| php_exception(format!("MXP Local Search option must be numeric: {key}")))
    }

    fn zval_to_i64(value: &Zval) -> Option<i64> {
        value.long().map(|value| value as i64)
    }

    fn zval_to_f64(value: &Zval) -> Option<f64> {
        value
            .double()
            .or_else(|| zval_to_i64(value).map(|value| value as f64))
    }

    fn update_outcome_name(outcome: UpdateOutcome) -> &'static str {
        match outcome {
            UpdateOutcome::Skipped => "skipped",
            UpdateOutcome::MetadataFtsOnly => "metadata_fts_only",
            UpdateOutcome::Full => "full",
            UpdateOutcome::New => "new",
        }
    }

    fn php_core_error(error: mxp_search_core::Error) -> PhpException {
        php_exception(error.to_string())
    }

    fn php_io_error(error: std::io::Error) -> PhpException {
        php_exception(error.to_string())
    }

    fn php_zend_error(error: ext_php_rs::error::Error) -> PhpException {
        php_exception(error.to_string())
    }

    fn php_exception(message: impl Into<String>) -> PhpException {
        PhpException::new(message.into(), 0, search_exception_class())
    }

    fn search_exception_class() -> &'static ClassEntry {
        SEARCH_EXCEPTION
            .get()
            .map(|ptr| unsafe { &*(*ptr as *const ClassEntry) })
            .unwrap_or_else(ce::exception)
    }

    #[php_startup]
    pub fn startup(_ty: i32, module_number: i32) {
        if SEARCH_EXCEPTION.get().is_none() {
            let class = ClassBuilder::new("MXP\\Search\\Exception")
                .extends(ce::exception())
                .build()
                .expect("failed to register MXP\\Search\\Exception");
            let _ = SEARCH_EXCEPTION.set(class as *const ClassEntry as usize);
        }
        IniEntryDef::register(
            vec![
                IniEntryDef::new(
                    "mxp_search.store_root".into(),
                    "/var/lib/mxp-local-search/kb".into(),
                    IniEntryPermission::System,
                ),
                IniEntryDef::new(
                    "mxp_search.export_root".into(),
                    "/var/lib/mxp-local-search/export".into(),
                    IniEntryPermission::System,
                ),
                IniEntryDef::new(
                    "mxp_search.model_dir".into(),
                    "/var/lib/mxp-local-search/models".into(),
                    IniEntryPermission::System,
                ),
                IniEntryDef::new(
                    "mxp_search.allowed_models".into(),
                    "multilingual-e5-small".into(),
                    IniEntryPermission::System,
                ),
                IniEntryDef::new(
                    "mxp_search.default_mode".into(),
                    "fast".into(),
                    IniEntryPermission::All,
                ),
                IniEntryDef::new(
                    "mxp_search.max_limit".into(),
                    "50".into(),
                    IniEntryPermission::All,
                ),
                IniEntryDef::new(
                    "mxp_search.max_candidate_limit".into(),
                    "500".into(),
                    IniEntryPermission::All,
                ),
                IniEntryDef::new(
                    "mxp_search.max_query_bytes".into(),
                    "2048".into(),
                    IniEntryPermission::All,
                ),
                IniEntryDef::new(
                    "mxp_search.min_hybrid_score".into(),
                    "0.1".into(),
                    IniEntryPermission::All,
                ),
            ],
            module_number,
        );
    }
    #[php_module]
    pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
        module
    }
}

pub fn extension_name() -> &'static str {
    "mxp_search"
}

pub fn version() -> &'static str {
    env!("CARGO_PKG_VERSION")
}
