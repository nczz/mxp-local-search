#!/usr/bin/env bash
set -euo pipefail

DOCS="${MXP_PERF_DOCS:-16}"
QUERIES="${MXP_PERF_QUERIES:-5}"

ddev exec "MXP_PERF_DOCS=$DOCS MXP_PERF_QUERIES=$QUERIES php -d memory_limit=1024M -r '
use MXP\\Search\\Store;
\$docs = max(1, (int) getenv(\"MXP_PERF_DOCS\"));
\$queries = max(1, (int) getenv(\"MXP_PERF_QUERIES\"));
\$root = ini_get(\"mxp_search.store_root\") ?: \"/var/lib/mxp-local-search/kb\";
\$path = \$root . \"/perf-baseline\";
if (Store::exists(\$path)) { \$old = Store::open(\$path); Store::destroy(\$path, \$old->destroyConfirmationToken()); }
\$store = Store::create(\$path, [\"name\"=>\"Performance baseline\", \"model\"=>\"multilingual-e5-small\", \"dimensions\"=>384]);
\$start = hrtime(true);
for (\$i = 0; \$i < \$docs; ++\$i) {
    \$topic = \$i % 2 === 0 ? \"ONNX PHP extension release artifact search\" : \"WordPress local semantic knowledge base\";
    \$content = str_repeat(\$topic . \" deterministic corpus chunk \" . \$i . \". \", 8);
    \$store->index(\"perf-\" . \$i, \"Perf Doc \" . \$i, \$content, [\"status\"=>\"publish\", \"visibility\"=>\"public\", \"post_type\"=>\"post\"]);
}
\$indexMs = (hrtime(true) - \$start) / 1e6;
\$stats = \$store->stats();
foreach ([\"fast\", \"semantic\", \"hybrid\"] as \$mode) {
    \$times = [];
    for (\$i = 0; \$i < \$queries; ++\$i) {
        \$qstart = hrtime(true);
        \$hits = \$store->search(\"ONNX PHP extension\", [\"mode\"=>\$mode, \"limit\"=>5]);
        \$times[] = (hrtime(true) - \$qstart) / 1e6;
        if (count(\$hits) === 0) { fwrite(STDERR, \"no hits for \$mode\\n\"); exit(1); }
    }
    sort(\$times);
    \$p50 = \$times[(int) floor((count(\$times) - 1) * 0.50)];
    \$p95 = \$times[(int) floor((count(\$times) - 1) * 0.95)];
    echo \"perf_query mode=\$mode p50_ms=\" . round(\$p50, 2) . \" p95_ms=\" . round(\$p95, 2) . \" runs=\" . count(\$times) . \"\\n\";
}
for (\$i = 0; \$i < 20; ++\$i) { \$store->search(\"WordPress semantic knowledge\", [\"mode\"=>\"hybrid\", \"limit\"=>3]); }
\$long = str_repeat(\"ONNX PHP extension release artifact search \", 900);
try {
    \$store->search(\$long, [\"mode\"=>\"semantic\", \"limit\"=>1]);
    fwrite(STDERR, \"long input limit failed\\n\");
    exit(1);
} catch (\\MXP\\Search\\Exception \$e) {
    if (strpos(\$e->getMessage(), \"query exceeds\") === false) { throw \$e; }
}
echo \"perf_index docs=\$docs documents=\" . \$stats[\"document_count\"] . \" chunks=\" . \$stats[\"chunk_count\"] . \" vectors=\" . \$stats[\"vector_count\"] . \" total_ms=\" . round(\$indexMs, 2) . \" docs_per_sec=\" . round(\$docs / max(0.001, \$indexMs / 1000), 2) . \"\\n\";
echo \"perf_memory_peak_bytes=\" . memory_get_peak_usage(true) . \"\\n\";
echo \"long_input_limit_ok\\n\";
Store::destroy(\$path, \$store->destroyConfirmationToken());
echo \"perf_baseline_ok\\n\";
'" 
