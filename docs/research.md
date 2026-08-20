# 研究記錄

日期：2026-08-19

---

## 1. PHP 向量處理現況

### 現有 WordPress 外掛（都依賴外部服務）
- AI Engine（jordymeow）：最成熟，v3.6.3 有 Internal vector（MySQL），Pro 付費牆
- AI Vector Search：需 Supabase
- VectorSeek：SaaS
- OC3 Semantic box：需 Pinecone + OpenAI

### PHP 向量 Library
- **PHPVector**（ezimuel）：純 PHP HNSW + BM25 hybrid，MIT，2026 新專案
- **mysql-vector**（allanpichardo）：MySQL JSON + 內建 BGE embedding（ONNX）
- **ext-memvector**（OpenSwoole）：C extension，HNSW + llama.cpp embedding + reranking

### PHP AI 推理
- **FerryAI**：PHP FFI，ONNX + llama.cpp，向量搜尋，全功能但需 FFI
- **onnxruntime-php**（ankane）：PHP FFI wrap ONNX Runtime

---

## 2. 模型選擇

### Embedding 模型（GGUF 格式）
- all-MiniLM-L6-v2：24 MB，384d，**英文為主，中文差**
- jina-embeddings-v2-base-zh：172 MB，768d，中英雙語，GGUF 有（gpustack）
- multilingual-e5-base：287 MB，768d，100+ 語言，GGUF 有（cstr）
- multilingual-e5-small：32 MB，384d，100+ 語言，**GGUF 未找到現成**

### Embedding 模型（ONNX 格式）
- multilingual-e5-small：32 MB quantized，384d，pi-knowledge 的選擇
- 所有 HuggingFace 模型都有 ONNX 版

### 決策
- GGUF 受限太大（模型少、需要 llama.cpp 支援特定架構）
- ONNX 生態完整（所有 HuggingFace 模型、所有架構）
- **選 ONNX 作為 MXP Local Search 的 embedding backend**

---

## 3. 搜尋品質研究（from pi-knowledge）

### Weighted Score Fusion > RRF
- pi-knowledge 從 RRF 遷移到 WSF
- 理由：RRF 壓分太嚴重，不利調參和診斷
- 權重：bm25=0.45, vector=0.55, overlap=0.15

### 中文搜尋
- FTS5 `unicode61` 不支援 CJK 分詞
- `trigram` tokenizer 可搜中文（SQLite 3.34.0+ 內建）
- 雙表策略：unicode61（英文）+ trigram（中文）
- 但在 hybrid 模式下，向量搜尋是主力，FTS5 是輔助

### 語意增量判斷（中文測試數據）
| 場景 | cosine distance | 閾值 0.08 判斷 |
|------|----------------|--------------|
| 配置→設定（同義） | 0.035 | skip ✅ |
| 防護→保護（同義） | 0.061 | skip ✅ |
| 需要→不需要（翻轉） | 0.091 | update ✅ |
| 可→不可（一字翻轉） | 0.137 | update ✅ |
| 已→未（一字翻轉） | 0.537 | update ✅ |
| 完全不同主題 | 0.553 | update ✅ |

---

## 4. 環境評估

### mxp2（開發/測試用）
- 12 核 / 48 GB / 454 GB 磁碟
- Docker 可用，適合跑 Ollama 或編譯 Rust
- PHP 8.2，ext-memvector 可裝

### mxp3（Production 驗證）
- 1 核 / 2 GB / 9 GB 剩餘
- 資源有限但 MXP Local Search mmap 模式可行
- CPU 效能好（Zen3 單核快）

### ddev（本機開發）
- ext-memvector 已成功編譯（ddev 官方 Dockerfile 格式）
- 關鍵：`-o Dpkg::Options::="--force-confnew"` + `SHELL ["/bin/bash", "-c"]`
- PHP 8.4（ddev 容器預設）

---

## 5. 技術決策：Rust vs C++ fork

### 為何選 Rust
1. pi-knowledge 搜尋品質是護城河，ext-memvector 沒有（只有 HNSW）
2. ext-php-rs 成熟（PHP extension binding）
3. ort + tokenizers + usearch crate 齊全
4. 一套核心多處複用（PHP ext / CLI / MCP / WASM）
5. 記憶體安全、跨平台 cargo build

### ext-memvector 的參考價值
- PHP extension 的 class 設計模式（construct / method / free_obj）
- config.m4 的 configure option 偵測邏輯
- model cache per-process 的做法

---

## 6. 關鍵依賴確認

| Crate | 版本 | 用途 | 狀態 |
|-------|------|------|------|
| ort | 3.x | ONNX Runtime | 穩定，HuggingFace TEI 在用 |
| tokenizers | 0.21 | HuggingFace tokenizers | 官方 Rust 實作 |
| usearch | 3.x | HNSW vector search | 穩定，10x faster than FAISS |
| rusqlite | 0.33 | SQLite + FTS5 | bundled mode 零外部依賴 |
| ext-php-rs | 0.14 | PHP extension binding | 支援 PHP 8.1-8.4 |
| rayon | 1.x | Parallel batch processing | 穩定 |
| memmap2 | 0.9 | Memory-mapped file | 穩定 |
