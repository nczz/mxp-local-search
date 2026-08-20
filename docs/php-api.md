# MXP Local Search PHP Extension API 規格

## Extension Info

- Extension name: `mxp_search`
- PHP requirement: 8.1+
- Feature constants: `MXP_SEARCH_VERSION`, `MXP_SEARCH_ONNX`, `MXP_SEARCH_RERANKER`

---

## Class: `MXP\Search\Store`

一個 instance = 一個知識庫。每個 Store 都有 immutable `kb_id`（權重與 provenance 使用）與 mutable `name`（顯示用）。

### Safety invariants

- 所有 KB path 必須落在 extension configured store root 底下；extension 先讀 `MXP_SEARCH_STORE_ROOT` 環境變數，否則讀 `mxp_search.store_root` INI；implementation 必須 canonicalize path、拒絕 symlink、拒絕 root 外路徑。
- `create/open/destroy/import/export/rebuild` 必須驗證 KB marker（例如 `.mxp-search-kb`）與 schema version。
- `destroy/import/rebuild` 是破壞性操作；PHP API 需提供顯式 confirmation token 或由上層受信任 CLI/admin flow 包裝。
- 每個 KB 同時間只能有一個 writer；SQLite transaction、FTS 更新、HNSW mutation 必須共享 per-KB exclusive lock。
- Model path 預設只接受 allowlisted model ID；任意 local model path 必須由 privileged configuration 顯式允許。

### Constructor

```php
// 建立新知識庫
$kb = MXP\Search\Store::create(string $path, array $options = []): MXP\Search\Store

// 開啟現有知識庫；可傳 options 覆蓋 per-process caps/defaults
$kb = MXP\Search\Store::open(string $path, array $options = []): MXP\Search\Store
```

**Options for create/open:**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `name` | string | directory name | 知識庫顯示名稱；不可作為 stable ID |
| `kb_id` | string | generated UUID/ULID | immutable stable ID；weights/provenance key |
| `model` | string | `'multilingual-e5-small'` | Allowlisted embedding model ID；local path 僅限 privileged config |
| `model_dir` | string | `mxp_search.model_dir` | 模型快取根目錄，必須非 web-writable |
| `dimensions` | int | auto-detect from verified model | 向量維度；載入後需與 model manifest 比對 |
| `distance` | string | `'cosine'` | cosine / dot / euclidean |
| `query_prefix` | string | `'query: '` | Query embedding prefix |
| `document_prefix` | string | `'passage: '` | Document embedding prefix |

### Static Methods

```php
// 列出 configured root 下所有知識庫；root_dir 必須在 extension configured store root 內
MXP\Search\Store::list(string $root_dir): array
// → [['kb_id' => '...', 'path' => '...', 'name' => '...', 'document_count' => 500, 'chunk_count' => 1200, 'model' => '...'], ...]

// 檢查路徑是否為有效的知識庫；必須 canonicalize + marker check
MXP\Search\Store::exists(string $path): bool

// 刪除知識庫（不可逆）；只允許刪除 marker-validated KB directory，不跟隨 symlink
MXP\Search\Store::destroy(string $path, string $confirm): bool
```

### Indexing

```php
// 索引一份文件（自動 embedding + FTS5）
$kb->index(string $id, string $title, string $content, array $metadata = []): void

// 更新文件（語意增量判斷）
$kb->update(string $id, string $title, string $content, array $metadata = []): string
// 回傳: 'skipped' | 'metadata_fts_only' | 'full' | 'new'
// 'skipped' 只代表 title/content/context/metadata/ACL/status/password canonical payload 完全不變。
// 'metadata_fts_only' 代表 content/title/metadata/ACL/payload_hash/FTS 已更新，只跳過 HNSW vector upsert。

// 批次索引
$kb->indexBatch(array $documents): array
// $documents = [['id' => '...', 'title' => '...', 'content' => '...', 'metadata' => []], ...]
// 回傳: ['new' => 5, 'full' => 2, 'metadata_fts_only' => 1, 'skipped' => 92]

// 刪除文件
$kb->delete(string $id): bool

// 刪除多份文件
$kb->deleteBatch(array $ids): int
```

### Search

```php
$results = $kb->search(string $query, array $options = []): array
```

**Options:**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `mode` | string | `mxp_search.default_mode` | fast / semantic / hybrid / deep；deep 可由部署或上層 capability 禁用 |
| `limit` | int | 10 | 回傳筆數上限；must clamp to `mxp_search.max_limit` |
| `candidate_limit` | int | `max(limit * 10, 100)` | filter/aggregation 前的 over-fetch 上限；must clamp to `mxp_search.max_candidate_limit` |
| `filters` | array | `[]` | Typed metadata filter；只允許 allowlisted keys/operators |
| `min_score` | float | `mxp_search.min_hybrid_score` | 最低分數閾值（confidence gate） |

**Filter contract:**

- Filters 必須在 vector candidate lookup 與 FTS lookup 前套用；不可只在 final results 後過濾。
- Allowed keys 預設包含：`doc_id`, `post_id`, `post_type`, `status`, `visibility`, `password_protected`, `locale`, `tenant_id`, `acl_hash`。
- Allowed operators：`eq`, `in`, `range`；key、operator、JSON path 必須 allowlist，values 必須 SQL bind。
- User query 必須透過 safe FTS MATCH builder 轉義；malformed query/filter 回傳 empty result 或 `\Exception`，不可拼接 SQL。
- Score contract：BM25/vector normalize 到 0-1；overlap bonus 加權後 final `score` clamp 到 0-1。
**Result format:**

```php
[
    [
        'id'        => 'post_42_chunk_0',      // chunk_id，backward-compatible alias
        'chunk_id'  => 'post_42_chunk_0',
        'doc_id'    => 'post_42',
        'kb_id'     => '01J...',
        'score'     => 0.87,
        'title'     => '文章標題',
        'snippet'   => '相關段落前 200 字...',
        'metadata'  => ['post_id' => 42, 'post_type' => 'post', 'status' => 'publish', 'visibility' => 'public', 'chunk_idx' => 0],
        'sources'   => ['vector', 'fts'],  // 命中來源
    ],
    // ...
]
```

### Stats & Management

```php
$kb->count(): int                         // chunk_count；保留相容性
$kb->stats(): array                       // ['document_count'=>..., 'chunk_count'=>..., 'vector_count'=>..., 'generation'=>..., 'kb_id'=>...]
$kb->export(string $path, string $confirm): int // JSONL 匯出，safe path + atomic write，回傳筆數
$kb->import(string $path, string $confirm): int // JSONL 匯入，safe path + marker/schema validation，回傳筆數
$kb->rebuild(string $confirm): void       // shadow rebuild + atomic swap；generation mismatch 才可修復
$kb->close(): void                        // 釋放資源
```

---

## Class: `MXP\Search\MultiSearch`

跨知識庫搜尋。

```php
$results = MXP\Search\MultiSearch::across(array $stores, string $query, array $options = []): array
```

**Options（同 `MXP\Search\Store::search` + 額外）：**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `weights` | array | `[]` | 每個 KB 的權重 `['kb_id' => 1.5]`；不得使用 display name |

**Result format（同 search + 額外 `kb_name` 欄位）：**

```php
[
    [
        'id'       => 'doc_12_chunk_0',
        'kb_id'    => '01J...',          // stable provenance key
        'kb_name'  => 'support-docs',    // display only
        'score'    => 0.92,
        // ... 其餘同上
    ],
]
```

---

## Class: `MXP\Search\Embedder`

低階 API，直接操作 embedding（通常不需要直接用）。在 WordPress/plugin mode 預設不可用任意 local path；必須使用 allowlisted model ID 或 privileged configuration 顯式開啟 local path。

```php
$emb = new MXP\Search\Embedder(string $model_id_or_path, array $options = []);
$vector = $emb->embed(string $text): array;           // document prefix → float[]
$vector = $emb->embedQuery(string $text): array;      // query prefix → float[]
$vectors = $emb->embedBatch(array $texts): array;     // document prefix → float[][]
$dim = $emb->dimensions(): int;
$emb->close(): void;
```

---

## Configuration

extension 註冊下列 PHP INI entries；直接 PHP / CLI deployment 可用 `MXP_SEARCH_STORE_ROOT` 覆蓋 store root，其餘 runtime caps 可由 INI 或 per-call options 覆蓋：

```ini
mxp_search.store_root = /var/lib/mxp-local-search/kb
mxp_search.export_root = /var/lib/mxp-local-search/export
mxp_search.model_dir = /var/lib/mxp-local-search/models
mxp_search.allowed_models = multilingual-e5-small
mxp_search.default_mode = fast
mxp_search.max_limit = 50
mxp_search.max_candidate_limit = 500
mxp_search.max_query_bytes = 2048
mxp_search.min_hybrid_score = 0.1
```

---

## Error Handling

所有錯誤拋出 `\MXP\Search\Exception`（extends `\Exception`）。

```php
try {
    $kb->search('test');
} catch (\MXP\Search\Exception $e) {
    echo $e->getMessage();
    echo $e->getCode(); // error code
}
```

---

## Feature Detection Pattern

```php
if ( ! extension_loaded('mxp_search') ) {
    // Extension 未安裝
    die('MXP Local Search extension required');
}

if ( ! ( defined('MXP_SEARCH_ONNX') && MXP_SEARCH_ONNX ) ) {
    // Semantic / hybrid mode 不可用；MVP build 會 fail closed
}

if ( defined('MXP_SEARCH_RERANKER') && MXP_SEARCH_RERANKER ) {
    // Deep mode 可用
    $results = $kb->search($q, ['mode' => 'deep']);
}
```
