use std::path::PathBuf;

#[derive(Debug, thiserror::Error)]
pub enum Error {
    #[error("path is outside configured root: {path}")]
    OutsideRoot { path: PathBuf },
    #[error("path contains or resolves through a symlink: {path}")]
    SymlinkRejected { path: PathBuf },
    #[error("path does not contain a valid MXP Local Search KB marker: {path}")]
    MissingMarker { path: PathBuf },
    #[error("unsupported schema version {found}; expected {expected}")]
    UnsupportedSchema { found: u32, expected: u32 },
    #[error("invalid confirmation token")]
    InvalidConfirmation,
    #[error("knowledge base already exists: {path}")]
    AlreadyExists { path: PathBuf },
    #[error("knowledge base does not exist: {path}")]
    NotFound { path: PathBuf },
    #[error("invalid option: {0}")]
    InvalidOption(String),
    #[error("invalid filter: {0}")]
    InvalidFilter(String),
    #[error("unsupported search mode before vector backend is enabled: {0}")]
    UnsupportedSearchMode(String),
    #[error("unsupported feature {feature}: {reason}")]
    UnsupportedFeature {
        feature: &'static str,
        reason: &'static str,
    },
    #[error("model checksum mismatch at {path}: expected {expected}, got {actual}")]
    ModelChecksumMismatch {
        path: PathBuf,
        expected: String,
        actual: String,
    },
    #[error("vector generation mismatch: expected {expected}, got {actual}")]
    VectorGenerationMismatch { expected: u64, actual: u64 },
    #[error("I/O error at {path}: {source}")]
    Io {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("SQLite error: {0}")]
    Sqlite(#[from] rusqlite::Error),
    #[error("JSON error: {0}")]
    Json(#[from] serde_json::Error),
}

pub type Result<T, E = Error> = std::result::Result<T, E>;

pub(crate) trait IoContext<T> {
    fn at(self, path: impl Into<PathBuf>) -> Result<T>;
}

impl<T> IoContext<T> for std::io::Result<T> {
    fn at(self, path: impl Into<PathBuf>) -> Result<T> {
        self.map_err(|source| Error::Io {
            path: path.into(),
            source,
        })
    }
}
