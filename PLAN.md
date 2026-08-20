# MXP Local Search — 計畫文件

> 本地優先的多知識庫搜尋引擎。Rust 核心 + PHP Extension binding。
> pi-knowledge 搜尋 pipeline 的 Rust port，設計為可嵌入任何 PHP 應用（首要目標：WordPress）。

---

## 專案定位

MXP Local Search 是一個**本地運行、零雲端依賴**的語意 + 全文混合搜尋引擎：

- 文字 → 向量（ONNX Runtime，支援所有 HuggingFace embedding 模型）
- 向量儲存 + HNSW 近似搜尋（usearch）
- 全文搜尋（SQLite FTS5 + trigram for CJK）
- Hybrid search（Weighted Score Fusion + query-aware ranking + confidence gate + diversity）
- Cross-encoder reranking（deep mode）
- 多知識庫架構 + 跨 KB 搜尋
- PHP extension（ext-php-rs）作為第一個 binding

---

## 來源與參考

- **pi-knowledge**（`/Users/chun/Projects/pi-knowledge`）：TypeScript 實作，本專案是其 Rust port
- **ext-memvector**（`github.com/memvector/ext-memvector`）：PHP extension 的 API 設計參考
- **研究記錄**：見 `docs/research.md`

---

## 架構決策

| 決策 | 選擇 | 理由 |
|------|------|------|
| 核心語言 | Rust | 記憶體安全、效能、跨平台編譯、PHP binding 生態成熟 |
| Embedding backend | ONNX Runtime（`ort` crate） | 支援所有 HuggingFace 模型，不限 GGUF |
| Tokenizer | `tokenizers` crate（HuggingFace 官方） | BPE/SentencePiece/Unigram 全支援 |
| 向量索引 | `usearch` crate | 10x faster than FAISS，C++ 核心 + Rust binding |
| 全文搜尋 | SQLite FTS5（`rusqlite` bundled） | 零外部依賴，trigram 支援 CJK |
| Fusion | Weighted Score Fusion | pi-knowledge 已驗證優於 RRF |
| PHP binding | `ext-php-rs` | Rust 寫 PHP extension 的標準做法 |
| 預設模型 | multilingual-e5-small（ONNX quantized, 32 MB） | 384d，100+ 語言含中文，MIT |
| 部署模型 | Bundled runtime by default；dynamic ONNX build 必須明確附帶 `libonnxruntime` | 避免「一個 .so」與 dynamic link contract 矛盾 |
| 授權 | MIT | |

---

## 核心功能

### 1. Embedding
- 載入 ONNX 模型 + HuggingFace tokenizer
- 支援 query/passage prefix（E5 系列）
- Mean pooling + L2 normalize
- Model cache（per-process 只載入一次）
- Batch embedding（rayon 平行）

### 2. Storage
- 每個知識庫一個受信任 root 下的目錄（SQLite + vector index）
- SQLite：chunk content, document_id, metadata, ACL/status fields, FTS5 index, content hash, vector generation
- Vector index：usearch HNSW（mmap-backed，持久化）
- 寫入以 per-KB exclusive lock 包住 SQLite transaction + HNSW mutation；rebuild 使用 shadow file + atomic rename
- JSONL export/import 僅允許 canonicalized safe path，且必須驗證 KB marker

### 3. Search Pipeline
- **fast**：FTS5 only（< 5ms）
- **semantic**：vector only（< 10ms）
- **hybrid**：metadata/ACL filters → over-fetch candidates → FTS5 + vector → weighted fusion → ranking → confidence gate → diversity（< 50ms）
- **deep**：hybrid + cross-encoder rerank（< 200ms；匿名/public search 預設不可用）
- Score contract：BM25/vector 先 normalize 到 0-1；overlap 是 bonus，final score clamp 到 0-1

### 4. Indexing
- 語意增量：cosine distance threshold（0.08）
- Indexed payload hash 快篩（title/content/context/metadata/ACL/status/password fields canonicalized 後 hash；只有完整 payload unchanged 才 skip）
- 內容不同但語意距離低於 threshold 時，只 skip HNSW vector upsert；仍更新 chunk/title/metadata/ACL/payload_hash/FTS
- Stale chunk 清理：文章縮短、狀態/權限/密碼改變、post type 設定改變都要 delete 或 reindex
- 每個 Store instance = 一個知識庫，具備 immutable `kb_id` 與 mutable display name
- 跨 KB 搜尋（共用 model，各自搜尋，合併排序）
- KB 權重以 stable `kb_id` 為 key，不使用 display name

---

## PHP API 規格

```php
// 知識庫管理
$kb = MXP\Search\Store::create($path, $options);
$kb = MXP\Search\Store::open($path);
MXP\Search\Store::destroy($path, $confirm);
$list = MXP\Search\Store::list($root_dir);

// 文件操作
$kb->index($id, $title, $content, $metadata);
$kb->update($id, $title, $content, $metadata); // → 'skipped'|'metadata_fts_only'|'full'|'new'
$kb->delete($id);
$kb->stats(); // documents/chunks/vectors/generation

// 搜尋
$results = $kb->search($query, $options);
// options: mode, limit, filters, candidate_limit

// 跨 KB 搜尋
$results = MXP\Search\MultiSearch::across($stores, $query, $options);
// options: mode, limit, weights keyed by kb_id

// Feature detection
defined('MXP_SEARCH_VERSION');    // e.g. '0.1.0'
defined('MXP_SEARCH_ONNX');       // ONNX Runtime 可用
defined('MXP_SEARCH_RERANKER');   // Reranker 可用
```

---

## 開發 Phases

### Phase 1：Core Embedding（1 週）
- [ ] Cargo workspace setup
- [ ] `ort` crate 整合：載入 ONNX model
- [ ] `tokenizers` crate 整合：tokenize → input_ids + attention_mask
- [ ] mean pooling + L2 normalize
- [ ] embed() / embed_batch() API
- [ ] 驗收：`embed("你好世界")` → Float32[384]，跟 pi-knowledge 輸出一致

### Phase 2：Storage + Vector Search（1 週）
- [ ] SQLite schema（documents/chunks table + FTS5 + metadata + ACL/status fields + vector_generation）
- [ ] Safe path root、KB marker、symlink rejection、atomic import/export/destroy
- [ ] usearch HNSW index（建立/查詢/持久化/mmap）
- [ ] Per-KB write lock、SQLite transaction、shadow rebuild + atomic swap
- [ ] Store struct：create / open / index / delete / search
- [ ] Content hash + 增量判斷（cosine distance；semantic-skip 只跳過 vector upsert）
- [ ] 驗收：1000 筆中文 → filtered search top-10 < 5ms，delete/rebuild crash recovery 可驗證

### Phase 3：Hybrid Search + Ranking（3 天）
- [ ] FTS5 trigram 支援 CJK
- [ ] Safe FTS query builder + typed metadata filter grammar（allowlisted keys/operators）
- [ ] BM25 score normalization
- [ ] Weighted Score Fusion（bm25:0.45 + vector:0.55 + overlap_bonus:0.15，final score clamp 0-1）
- [ ] Domain-aware ranking boosts（title/taxonomy/post_type/configured fields）
- [ ] Confidence gate（最低分閾值）與 candidate over-fetch
- [ ] Diversity dedup
- [ ] 驗收：中文 hybrid search 品質 ≥ pi-knowledge，malformed query/filter fuzz 不 panic、不 bypass filters

### Phase 4：PHP Extension Binding（1 週）
- [ ] ext-php-rs 骨架
- [ ] `MXP\Search\Store` class binding
- [ ] `MXP\Search\MultiSearch::across()` binding（stable `kb_id` weights/provenance）
- [ ] INI settings（store root, model path, default options, hard caps）
- [ ] Model trust policy（allowlist、hash/signature、dimension/schema validation）
- [ ] Error handling（PHP exceptions）
- [ ] 驗收：ddev 環境 `php -m | grep mxp_search` + safe create/index/search/delete 跑通

### Phase 5：WP 外掛整合（3 天）
- [ ] Content Extractor（Gutenberg blocks → text）
- [ ] Chunker（paragraph / heading / smart）
- [ ] Index Manager + complete status/visibility/password transition hooks
- [ ] Search Handler + WP_Query override（pre-search ACL/status filters + over-fetch）
- [ ] Admin UI（nonce + custom capability）
- [ ] WP-CLI（confirm destructive ops、safe paths）
- [ ] 驗收：WP 安裝外掛 → 建索引 → 公開/私人/草稿/刪除狀態搜尋正確

### Phase 6：Reranker + Deep Mode（3 天）
- [ ] Cross-encoder ONNX 模型載入
- [ ] score() API
- [ ] deep search mode 整合
- [ ] 驗收：deep mode 品質驗證

### Phase 7：Multi-KB + 跨系統整合（3 天）
- [ ] MultiSearch API
- [ ] Stable `kb_id` + display name 分離
- [ ] KB 權重以 `kb_id` key
- [ ] JSONL export/import（safe path + marker + atomic write）
- [ ] MCP tool 暴露（optional）
- [ ] 驗收：多 KB 跨搜尋，rename display name 不影響 weights/provenance

---

## 風險與緩解

| 風險 | 程度 | 緩解 |
|------|------|------|
| ext-php-rs 對 PHP 8.4 的相容性 | 低 | GitHub 顯示已支援，且持續更新中 |
| ONNX Runtime static link 體積大 | 中 | 預設採 bundled/static 或同包發行；dynamic build 必須附帶版本鎖定的 `libonnxruntime`、rpath/installer check、ABI fail-closed |
| usearch mmap index 與 SQLite 不一致 | 中 | per-KB write lock、generation table、shadow rebuild + atomic swap、mismatch 時 fail closed + rebuild |
| Raw path API 誤刪/越界 | 高 | 所有 path 限制在 configured root；canonicalize；拒絕 symlink；destroy/import/rebuild 需 KB marker 與 confirmation |
| Model supply-chain / untrusted ONNX | 高 | 預設 allowlisted model IDs；下載 hash/signature；cache 非 web-writable；限制檔案大小、dimension、schema、opset/provider |
| Rust 編譯時間長 | 中 | workspace 分 crate 編譯，incremental build |
| 跨平台（Linux x86/ARM, macOS ARM） | 低 | 發行矩陣明確標記 PHP ABI、libc、arch、ONNX Runtime 版本 |
| Tokenizer 與 pi-knowledge 不一致 | 低 | 同一個 `tokenizers` crate / 同一個 tokenizer.json，並以 hash pin 住 |

---

## 成功標準

1. **功能對等**：MXP Local Search hybrid search 品質 ≥ pi-knowledge
2. **效能**：single query < 50ms（hybrid mode, 10K docs, filters enabled）
3. **可驗證部署**：native extension + verified model/runtime bundle；static build 可達成單一 `.so`，dynamic build 必須顯式檢查相依 shared library
4. **中文品質**：一字翻轉語意（可→不可）能被正確偵測和搜尋
5. **WP 整合**：安裝外掛 → 按一個按鈕 → 搜尋升級為語意搜尋，且 draft/private/trash/password-protected 內容不會被未授權搜尋
