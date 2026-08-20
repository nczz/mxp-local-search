# MXP Local Search Architecture

## System Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│ Consumers                                                           │
│                                                                     │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │ WP 外掛      │  │ CLI Tool     │  │ MCP Server   │   ...        │
│  │ (PHP)        │  │ (Rust)       │  │ (Rust/PHP)   │              │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘              │
│         │                  │                  │                      │
└─────────┼──────────────────┼──────────────────┼─────────────────────┘
          │                  │                  │
          ▼                  ▼                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│ mxp-search-php (ext-php-rs)  OR  mxp-search-core (direct Rust API)  │
│                                                                     │
│  PHP Classes:                  Rust API:                             │
│  MXP\Search\Store             Store::create/open/index/search       │
│  MXP\Search\MultiSearch       MultiSearch::search()                 │
│                                                                     │
└─────────────────────────────────┬───────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│ mxp-search-core                                                    │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │ Embedding Layer                                             │    │
│  │  ┌──────────────┐  ┌──────────────────┐                    │    │
│  │  │ ONNX Runtime │  │ HuggingFace      │                    │    │
│  │  │ (ort crate)  │  │ Tokenizers       │                    │    │
│  │  └──────────────┘  └──────────────────┘                    │    │
│  │  • Load model once, reuse across requests                   │    │
│  │  • query/passage prefix handling                            │    │
│  │  • mean pooling + L2 normalize                              │    │
│  │  • batch embedding (rayon parallel)                         │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │ Search Layer                                                │    │
│  │                                                             │    │
│  │  ┌─────────────┐   ┌─────────────┐   ┌──────────────┐     │    │
│  │  │ Vector HNSW │   │ FTS5 BM25   │   │ Cross-Encoder│     │    │
│  │  │ (usearch)   │   │ (rusqlite)  │   │ Reranker     │     │    │
│  │  └──────┬──────┘   └──────┬──────┘   └──────┬───────┘     │    │
│  │         │                  │                  │             │    │
│  │         ▼                  ▼                  ▼             │    │
│  │  ┌─────────────────────────────────────────────────┐       │    │
│  │  │ Fusion + Ranking                                │       │    │
│  │  │ • Weighted Score Fusion (0.45/0.55/0.15)        │       │    │
│  │  │ • Query-aware boosts                            │       │    │
│  │  │ • Confidence gate                              │       │    │
│  │  │ • Diversity dedup                              │       │    │
│  │  └─────────────────────────────────────────────────┘       │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │ Storage Layer                                               │    │
│  │                                                             │    │
│  │  ┌──────────────────────┐  ┌──────────────────────┐        │    │
│  │  │ SQLite               │  │ Vector File          │        │    │
│  │  │ • chunks (content)   │  │ • usearch HNSW index │        │    │
│  │  │ • FTS5 (trigram+uni) │  │ • mmap-backed        │        │    │
│  │  │ • metadata           │  │ • persistent         │        │    │
│  │  │ • content_hash       │  │                      │        │    │
│  │  └──────────────────────┘  └──────────────────────┘        │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │ Indexer                                                     │    │
│  │ • Content hash quick-skip                                   │    │
│  │ • Semantic distance threshold (cosine 0.08)                 │    │
│  │ • FTS5 always-update                                        │    │
│  │ • Stale chunk cleanup                                       │    │
│  └─────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────┘
```

## Data Flow

### Indexing

```
Indexed payload (title + text + context + metadata + ACL/status/password fields)
    │
    ▼
[Acquire per-KB exclusive writer lock]
    │
    ▼
[Canonical payload hash check] ──same hash──→ SKIP (no writes)
    │ different
    ▼
[Embed new text] (5-10ms)
    │
    ▼
[Compare with stored vector]
    │
    ├─ cosine distance < 0.08
    │     └─ SQLite transaction: update chunk/title/metadata/ACL/payload_hash/FTS only
    │        (skip HNSW vector upsert; return metadata_fts_only)
    │
    └─ cosine distance ≥ 0.08
          └─ SQLite transaction + HNSW upsert under same lock
             ├─ SQLite INSERT/UPDATE (document_id + chunk + metadata + ACL + FTS + payload_hash + generation)
             └─ usearch upsert (HNSW graph update) + generation checksum
```

Crash invariant：SQLite `vector_generation` and `vectors.usearch` generation/checksum must match before search. Mismatch fails closed and requires rebuild. Rebuild writes `vectors.usearch.tmp`, validates counts/checksum, then atomically renames.

### Search (hybrid mode)

```
Query text + trusted caller context
    │
    ▼
[Validate q length/mode/limit; build typed filters]
    │
    ▼
[Apply metadata/ACL filters before retrieval]
    │
    ├──────────────────────────────┐
    ▼                              ▼
[Embed query]                 [Safe FTS MATCH builder]
    │                              │
    ▼                              ▼
[HNSW vector search]        [FTS5 BM25 search]
    over-fetch candidates       over-fetch candidates
    │                              │
    ▼                              ▼
[Normalize scores independently: min-max to 0-1]
    │                              │
    └──────────────┬───────────────┘
                   ▼
[Weighted Score Fusion]
    raw = bm25 * 0.45 + vector * 0.55 + overlap_bonus * 0.15
    final_score = clamp(raw, 0.0, 1.0)
                   │
                   ▼
[Domain-aware ranking boosts]
    • title match bonus
    • taxonomy/category breadcrumb match bonus
    • post_type / configured field boosts
    • locale/status/visibility constraints from metadata
                   │
                   ▼
[Confidence gate]
    • drop candidates below min_score
    • lexical evidence requirement is configurable per domain/mode, never path-specific
                   │
                   ▼
[Diversity dedup]
    • same-document proximity penalty
    • vector redundancy check
                   │
                   ▼
Final ranked results
```

Candidate sizing：`candidate_limit` defaults to `max(limit * 10, 100)` and is hard-clamped by configuration. Permission/status filters must be applied before both vector and FTS candidate limits; post-level aggregation may continue fetching until authorized results reach `limit` or candidates are exhausted.

## Multi-KB Architecture

```
┌─────────────────────────────────────────────┐
│ MXP\Search\MultiSearch::across()        │
│                                             │
│  Query ──→ Embed ONCE (shared model)        │
│              │                              │
│    ┌─────────┼─────────┐                    │
│    ▼         ▼         ▼                    │
│  ┌────┐   ┌────┐   ┌────┐                  │
│  │KB-A│   │KB-B│   │KB-C│  (parallel)      │
│  └──┬─┘   └──┬─┘   └──┬─┘                  │
│     │         │         │                    │
│     ▼         ▼         ▼                    │
│  results   results   results                │
│     │         │         │                    │
│     └─────────┼─────────┘                    │
│               ▼                              │
│  [Merge + weight + re-sort + annotate kb_id]│
│               │                              │
│               ▼                              │
│         Final results                        │
└─────────────────────────────────────────────┘
```

`kb_id` is immutable and is the only supported key for weights and result provenance. `name` is display-only and may be duplicated or renamed without changing weights.

## File Layout per KB

```
/path/to/kb/
├── .mxp-search-kb        ← marker with schema_version and kb_id
├── meta.json              ← {"kb_id":"01J...","name":"...","model":"multilingual-e5-small","dimensions":384,"created_at":"..."}
├── chunks.db              ← SQLite (documents + chunks + FTS5 + metadata + ACL/status + payload_hash + persisted vectors + vector_generation)
├── vectors.usearch        ← planned HNSW acceleration layer for `vector-usearch` builds
├── vectors.usearch.tmp    ← planned shadow rebuild target only
└── write.lock             ← per-KB writer lock
```

## Model Management

```
/var/lib/mxp-local-search/models/        ← configured `mxp_search.model_dir`; must be non-web-writable
├── multilingual-e5-small/
│   ├── manifest.json                   ← model_id, dimensions, files, hashes, source, opset/provider constraints
│   ├── model.onnx                      ← quantized, ~32 MB
│   └── tokenizer.json                  ← HuggingFace tokenizers format, ~1 MB
└── ms-marco-MiniLM-L-4-v2/             ← reranker (optional)
    ├── manifest.json
    ├── model.onnx
    └── tokenizer.json
```

Models 跨 KB 共享。同一 process 內只載入一次（Arc<Embedder>）。Cache key 必須包含 canonical model path + verified manifest hash；model 檔案變更或 hash 不符時 fail closed。

Trust rules：
- WordPress mode 預設只允許 allowlisted model IDs，不接受任意 local path。
- 下載模型必須驗證 pinned hash/signature、expected dimensions、tokenizer schema、ONNX opset/provider、最大檔案大小。
- `model_dir` 不得在 web root 或可由 WordPress uploads/plugin editor 寫入的位置。
- Dynamic ONNX Runtime build 必須 pin `libonnxruntime` version/ABI/provider，載入時檢查不符即停止 extension 初始化。
