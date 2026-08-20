# WordPress 外掛整合規格

## 外掛名稱

`mxp-local-search`

## 定位

WordPress 業務邏輯層。不做任何向量運算，只做 WP 特有的事：
- 決定索引什麼內容
- 從 WP 資料結構萃取文字
- 分片策略
- 搜尋結果從 chunk → post 的聚合
- 將 post status、visibility、password、role/user ACL 轉成不可繞過的 metadata filters
- 後台 UI / REST API / WP-CLI

---

## 外掛結構

```
mxp-local-search/
├── mxp-local-search.php                ← Boot + feature detect
├── includes/
│   ├── class-config.php               ← 設定管理
│   ├── class-content-extractor.php    ← WP 內容萃取
│   ├── class-chunker.php              ← 分片策略
│   ├── class-kb-manager.php           ← 知識庫生命週期
│   ├── class-index-manager.php        ← 索引管理 + save_post hook
│   ├── class-search-handler.php       ← 搜尋 + 結果聚合
│   ├── class-hooks.php                ← WP hook 整合
│   ├── class-admin.php                ← 後台 UI
│   ├── class-rest-api.php             ← REST endpoints
│   └── class-cli.php                  ← WP-CLI 指令
├── templates/
│   ├── admin-dashboard.php
│   ├── admin-settings.php
│   └── search-results.php
├── assets/
│   ├── admin.css
│   └── admin.js
└── readme.txt
```

---

## Content Extractor 規格

從 WP_Post 萃取可索引文字。需處理：

### 必須處理
- Gutenberg blocks → 純文字（保留段落結構）
- Classic editor HTML → strip tags
- post_title
- post_excerpt

### 可選（設定決定）
- ACF / custom fields（必須 allowlist 欄位；預設不索引 secret/token/password/email/phone 等敏感 key）
- Taxonomy terms（categories, tags）
- WooCommerce product data（public products only；SKU, price, stock status, attributes；customer/order data 不索引）
- Comments（留言內容；預設不索引，開啟時需明確標示 privacy impact）

### 不索引
- Revisions
- Autosaves
- Draft / Private / Pending / Trash（預設永不索引；若明確開啟 private indexing，必須使用 ACL-partitioned KB 或不可繞過的 role/user filters）
- Password-protected posts（除非 private indexing policy 明確支援）
- Attachment posts（image metadata 例外）

---

## Chunking 規格

### 策略選項

| Strategy | 切分方式 | 適合 |
|----------|---------|------|
| `paragraph` | 空行分隔 | 短文（< 1000 字） |
| `heading` | H2/H3 邊界 | 結構化長文 |
| `fixed` | 固定字數 + overlap | 通用 |
| `smart` | 根據文章長度自動選 | 預設 |

### 每個 chunk 包含

```php
[
    'text'      => '原始文字內容',
    'context'   => '[post] [分類 > 子分類] 文章標題',  // index-time context prefix
    'position'  => 2,                                  // chunk 在原文中的位置
    'headings'  => ['一級標題', '二級標題'],             // 麵包屑
]
```

### Context Prefix（復刻 pi-knowledge 的 contextual retrieval）

```
[{post_type}] [{taxonomy breadcrumb}] {post_title}
{chunk_text}
```

例：
```
[product] [3C > 手機配件] iPhone 保護殼推薦
這款保護殼使用軍規級防摔材質...
```

---

## Index Manager 規格

### 觸發時機

預設 indexable 狀態只有 `publish` 且 `post_password = ''`。任何從 indexable → non-indexable 的轉換都必須刪除該 document 的全部 chunks；不能只 skip。

| Event | 動作 |
|-------|------|
| `save_post` with indexable public post | index/update |
| `save_post` with non-indexable status/visibility/password | delete existing chunks for post |
| `transition_post_status` indexable → non-indexable | delete existing chunks for post |
| `transition_post_status` non-indexable → indexable | index/update |
| `post_password` added/removed/changed | delete or reindex according to indexable policy |
| `deleted_post` / trash purge | delete |
| Post type / custom-field / taxonomy indexing config changed | enqueue affected posts for delete/reindex |
| WP-CLI `mxp-search index --all` | batch index under per-KB writer queue |
| Admin「重建索引」按鈕 | enqueue force rebuild; never run concurrently with normal writes |

每個 indexed chunk 必須包含 metadata：`doc_id`, `post_id`, `post_type`, `status`, `visibility`, `password_protected`, `locale`, `language`, `acl_hash`, `chunk_idx`。Public search 必須要求 `status=publish`, `visibility=public`, `password_protected=false`，並依目前語系套用 `locale` filter。

### Chunk ID 格式

```
{post_type}_{post_id}_chunk_{index}

例：post_42_chunk_0, post_42_chunk_1, product_100_chunk_0
```

Chunk ID 是 external/backward-compatible identifier；storage 仍需獨立保存 `doc_id = {post_type}_{post_id}`、`post_id`、`chunk_idx`，不可只從 string parse 權限或刪除範圍。

### Stale Chunk 清理

文章縮短時（原本 5 chunks 變 3 chunks），刪除 chunk_3 和 chunk_4。文章離開 indexable 狀態、密碼保護打開、post type 被停用、custom field allowlist 改變時，也必須刪除或重建受影響 chunks。

---

## Search Handler 規格

### 搜尋流程

```
1. 使用者搜尋 query
2. 根據 caller 建立不可繞過的 filters：
   - public search: status=publish, visibility=public, password_protected=false
   - logged-in/private search: 加入 role/user ACL filter 或使用 ACL-partitioned KB
3. Clamp mode/limit；匿名使用者預設不可使用 deep mode
4. 呼叫 `MXP\Search\Store::search()` 或 `MXP\Search\MultiSearch::across()`，帶 filters 與 candidate_limit=max(limit*10,100)
5. 得到已過濾的 chunk-level 結果
6. 聚合到 post-level：
   - 同 post 的多個 chunk → 取最高分
   - 保留最佳 chunk 作為 snippet
7. 若聚合後未滿 limit，繼續 over-fetch 下一批 candidates，直到填滿或候選耗盡
8. 最後仍執行 current_user_can('read_post', $id) 作為 defense-in-depth；不可把它當唯一權限控制
9. 格式化回傳
```

### Post Aggregation 邏輯

```php
// 多個 chunk 屬於同一篇文章
// post_42_chunk_0: score 0.85
// post_42_chunk_2: score 0.91
// post_42_chunk_1: score 0.72
//
// 結果：post_42, score=0.91, snippet=chunk_2 的內容
```


### Related Content

`[mxp_related]` 以當前文章作為 query source，呼叫同一條 public-safe search pipeline，排除自身 post ID 後輸出 related article list。可用 attributes：

```php
[mxp_related limit="5" mode="hybrid" title="Related articles"]
```

實作不得建立第二套 ranking 或繞過 public filters；WooCommerce products/page/post/custom post types 都以同一個 indexed metadata contract 聚合。

### Multilingual Plugins

索引時優先取 Polylang `pll_get_post_language($post_id, 'locale')` 或 WPML `wpml_post_language_details` 的 locale/language code，並提供 `mxp_local_search_post_locale`、`mxp_local_search_post_language` filters 給其他多語外掛接入。Public search 使用目前語系 locale filter；Polylang 可自動解析，WPML 可用 `mxp_local_search_wpml_language_locale` 補語系碼到 locale 的對照。
---

## KB 管理策略

### 預設模式（簡單）

所有 post type 放同一個 KB，靠 typed metadata filter 區分；filter 必須由 allowlisted key/operator 產生，不可直接拼 SQL/FTS：

```php
$kb = KB_Manager::get_default();
$kb->search('退貨', ['filters' => [
    ['key' => 'post_type', 'op' => 'eq', 'value' => 'product'],
    ['key' => 'status', 'op' => 'eq', 'value' => 'publish'],
    ['key' => 'visibility', 'op' => 'eq', 'value' => 'public'],
    ['key' => 'password_protected', 'op' => 'eq', 'value' => false],
]]);
```

### 進階模式（分離）

每個 post type 一個 KB：

```php
$blog_kb    = KB_Manager::get_store('post');
$product_kb = KB_Manager::get_store('product');
$page_kb    = KB_Manager::get_store('page');

// 跨 KB 搜尋
MXP\Search\MultiSearch::across([$blog_kb, $product_kb], $query);
```

### 設定

```php
// 後台設定
[
    'kb_mode'        => 'single',  // single | per_type
    'post_types'     => ['post', 'page', 'product'],
    'search_mode'    => 'fast', // semantic/hybrid/deep require MXP_SEARCH_ONNX=true
    'chunk_strategy' => 'smart',
    'custom_fields'  => ['subtitle', 'faq_content'], // allowlist only
    'capability'     => 'manage_mxp_search',
    'max_public_limit' => 20,
]
```

---

## REST API

| Route | Auth / capability | Limits |
|-------|-------------------|--------|
| `GET /wp-json/mxp-search/v1/search?q=...&mode=...&limit=...` | Public allowed only for public indexed content; logged-in results still require ACL filters | `q` length ≤ 2048 bytes, `limit` ≤ 20 public / 50 auth, anonymous `deep` disabled |
| `POST /wp-json/mxp-search/v1/index` | `permission_callback` requires `manage_mxp_search` (or `manage_options` fallback) + valid `X-WP-Nonce` for cookie auth | request schema requires integer post ID |
| `POST /wp-json/mxp-search/v1/index-all` | same admin capability + nonce | enqueue async job; batch size clamp |
| `GET /wp-json/mxp-search/v1/stats` | same admin capability + nonce/application password | no raw filesystem paths |
| `POST /wp-json/mxp-search/v1/rebuild` | same admin capability + nonce + explicit confirm field | enqueue rebuild; mutually exclusive with writes |
| `GET /wp-json/mxp-search/v1/kb` | same admin capability + nonce/application password | returns `kb_id`, display name, counts; no arbitrary root listing |

All POST routes must define JSON schema and reject unknown fields. Public search must rate-limit by IP/user and must not expose raw chunk metadata beyond the fields required to render results.

---

## WP-CLI

```bash
wp mxp-search index --all [--type=post] [--batch=50] [--progress]
wp mxp-search index --id=42
wp mxp-search search "query" [--mode=fast] [--limit=10] [--kb-id=01J...]
wp mxp-search stats [--kb=all]
wp mxp-search rebuild [--kb-id=01J...] --confirm
wp mxp-search export --kb-id=01J... --output=./backup.jsonl --confirm
wp mxp-search import --kb-id=01J... --input=./backup.jsonl --confirm
wp mxp-search model info
wp mxp-search model download multilingual-e5-small --verify --confirm
```

WP-CLI path arguments must be canonicalized under configured export/import roots unless an explicitly privileged server-side config allows a different root. Destructive/import/download/rebuild commands require `--confirm`; model download requires allowlisted model ID and pinned hash/signature verification.

---

## 後台 UI

### Dashboard Page（工具 > Local Search）

```
┌─────────────────────────────────────────────────┐
│ MXP Local Search                                │
├─────────────────────────────────────────────────┤
│                                                 │
│ 狀態                                            │
│ ┌─────────────────────┬───────────────────────┐ │
│ │ Extension           │ ✅ mxp_search 0.1.0   │ │
│ │ Model               │ multilingual-e5-small │ │
│ │ Indexed documents   │ 1,234                 │ │
│ │ Knowledge bases     │ 1 (default)           │ │
│ │ Search mode         │ fast                  │ │
│ │ Last indexed        │ 2 minutes ago         │ │
│ └─────────────────────┴───────────────────────┘ │
│                                                 │
│ Actions                                         │
│ [Index All Posts]  [Rebuild Index]  [Export]     │
│                                                 │
│ Settings                                        │
│ • Post types to index: ☑ post ☑ page ☐ product │
│ • Search mode: [fast ▾]                         │
│ • Chunk strategy: [smart ▾]                     │
│ • Custom fields: [_______________]              │
│                                                 │
│ Test Search                                     │
│ [________________________] [Search]             │
│                                                 │
│ Results:                                        │
│ 1. [0.92] PHP 效能優化 (vector+fts)             │
│ 2. [0.85] Docker 部署實戰 (vector)              │
│ 3. [0.78] JavaScript 非同步 (fts)               │
│                                                 │
└─────────────────────────────────────────────────┘
```
