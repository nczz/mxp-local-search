use rusqlite::types::Value;

use crate::error::{Error, Result};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SearchMode {
    Fast,
    Semantic,
    Hybrid,
    Deep,
}

impl SearchMode {
    pub fn parse(value: &str) -> Result<Self> {
        match value {
            "fast" => Ok(Self::Fast),
            "semantic" => Ok(Self::Semantic),
            "hybrid" => Ok(Self::Hybrid),
            "deep" => Ok(Self::Deep),
            other => Err(Error::InvalidOption(format!(
                "unknown search mode: {other}"
            ))),
        }
    }
}

#[derive(Debug, Clone)]
pub struct SearchOptions {
    pub mode: SearchMode,
    pub limit: usize,
    pub candidate_limit: Option<usize>,
    pub filters: Vec<Filter>,
    pub min_score: f32,
}

impl Default for SearchOptions {
    fn default() -> Self {
        Self {
            mode: SearchMode::Fast,
            limit: 10,
            candidate_limit: None,
            filters: Vec::new(),
            min_score: 0.0,
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum FilterOp {
    Eq,
    In,
    Range,
}

#[derive(Debug, Clone, PartialEq)]
pub enum FilterValue {
    Text(String),
    Integer(i64),
    Bool(bool),
    TextList(Vec<String>),
    IntegerList(Vec<i64>),
    IntegerRange { min: Option<i64>, max: Option<i64> },
}

#[derive(Debug, Clone, PartialEq)]
pub struct Filter {
    pub key: String,
    pub op: FilterOp,
    pub value: FilterValue,
}

impl Filter {
    pub fn eq_text(key: impl Into<String>, value: impl Into<String>) -> Self {
        Self {
            key: key.into(),
            op: FilterOp::Eq,
            value: FilterValue::Text(value.into()),
        }
    }

    pub fn eq_bool(key: impl Into<String>, value: bool) -> Self {
        Self {
            key: key.into(),
            op: FilterOp::Eq,
            value: FilterValue::Bool(value),
        }
    }

    pub fn eq_i64(key: impl Into<String>, value: i64) -> Self {
        Self {
            key: key.into(),
            op: FilterOp::Eq,
            value: FilterValue::Integer(value),
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct SearchResult {
    pub id: String,
    pub chunk_id: String,
    pub doc_id: String,
    pub kb_id: String,
    pub score: f32,
    pub title: String,
    pub snippet: String,
    pub metadata: serde_json::Value,
    pub sources: Vec<String>,
}

pub(crate) struct FilterSql {
    pub clause: String,
    pub values: Vec<Value>,
}

pub(crate) fn build_filter_sql(filters: &[Filter]) -> Result<FilterSql> {
    let mut clauses = Vec::with_capacity(filters.len());
    let mut values = Vec::new();

    for filter in filters {
        let column = allowed_column(&filter.key)?;
        match (&filter.op, &filter.value) {
            (FilterOp::Eq, FilterValue::Text(value)) => {
                clauses.push(format!("{column} = ?"));
                values.push(Value::Text(value.clone()));
            }
            (FilterOp::Eq, FilterValue::Integer(value)) => {
                clauses.push(format!("{column} = ?"));
                values.push(Value::Integer(*value));
            }
            (FilterOp::Eq, FilterValue::Bool(value)) => {
                clauses.push(format!("{column} = ?"));
                values.push(Value::Integer(i64::from(*value)));
            }
            (FilterOp::In, FilterValue::TextList(list)) if !list.is_empty() && list.len() <= 64 => {
                clauses.push(format!(
                    "{column} IN ({})",
                    std::iter::repeat("?")
                        .take(list.len())
                        .collect::<Vec<_>>()
                        .join(",")
                ));
                values.extend(list.iter().cloned().map(Value::Text));
            }
            (FilterOp::In, FilterValue::IntegerList(list))
                if !list.is_empty() && list.len() <= 64 =>
            {
                clauses.push(format!(
                    "{column} IN ({})",
                    std::iter::repeat("?")
                        .take(list.len())
                        .collect::<Vec<_>>()
                        .join(",")
                ));
                values.extend(list.iter().copied().map(Value::Integer));
            }
            (FilterOp::Range, FilterValue::IntegerRange { min, max }) => {
                if let Some(min) = min {
                    clauses.push(format!("{column} >= ?"));
                    values.push(Value::Integer(*min));
                }
                if let Some(max) = max {
                    clauses.push(format!("{column} <= ?"));
                    values.push(Value::Integer(*max));
                }
                if min.is_none() && max.is_none() {
                    return Err(Error::InvalidFilter(format!(
                        "range filter for {} needs min or max",
                        filter.key
                    )));
                }
            }
            _ => {
                return Err(Error::InvalidFilter(format!(
                    "operator/value mismatch for {}",
                    filter.key
                )));
            }
        }
    }

    Ok(FilterSql {
        clause: if clauses.is_empty() {
            String::new()
        } else {
            format!(" AND {}", clauses.join(" AND "))
        },
        values,
    })
}

fn allowed_column(key: &str) -> Result<&'static str> {
    match key {
        "doc_id" => Ok("chunks.doc_id"),
        "post_id" => Ok("chunks.post_id"),
        "post_type" => Ok("chunks.post_type"),
        "status" => Ok("chunks.status"),
        "visibility" => Ok("chunks.visibility"),
        "password_protected" => Ok("chunks.password_protected"),
        "locale" => Ok("chunks.locale"),
        "tenant_id" => Ok("chunks.tenant_id"),
        "acl_hash" => Ok("chunks.acl_hash"),
        other => Err(Error::InvalidFilter(format!("unsupported key: {other}"))),
    }
}

pub(crate) fn safe_fts_query(query: &str, max_query_bytes: usize) -> Result<Option<String>> {
    if query.len() > max_query_bytes {
        return Err(Error::InvalidOption(format!(
            "query exceeds {max_query_bytes} bytes"
        )));
    }

    let mut out = String::with_capacity(query.len() + 2);
    for ch in query.chars() {
        if ch.is_control() {
            out.push(' ');
        } else if ch == '"' {
            out.push_str("\"\"");
        } else {
            out.push(ch);
        }
    }
    let trimmed = out.trim();
    if trimmed.is_empty() {
        Ok(None)
    } else {
        Ok(Some(format!("\"{trimmed}\"")))
    }
}
