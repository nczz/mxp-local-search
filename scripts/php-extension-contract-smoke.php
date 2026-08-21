<?php

declare(strict_types=1);

use MXP\Search\Embedder;
use MXP\Search\Reranker;
use MXP\Search\Store;

function mxp_require(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

mxp_require(extension_loaded('mxp_search'), 'mxp_search extension must be loaded through normal PHP config');
mxp_require(defined('MXP_SEARCH_VERSION'), 'MXP_SEARCH_VERSION constant');
mxp_require(defined('MXP_SEARCH_ONNX') && MXP_SEARCH_ONNX === true, 'MXP_SEARCH_ONNX true');
mxp_require(defined('MXP_SEARCH_RERANKER') && MXP_SEARCH_RERANKER === true, 'MXP_SEARCH_RERANKER true');
mxp_require(class_exists(Store::class), 'Store class exists');
mxp_require(class_exists(Embedder::class), 'Embedder class exists');
mxp_require(class_exists(Reranker::class), 'Reranker class exists');
mxp_require(class_exists('MXP\\Search\\Exception'), 'Exception class exists');
echo "constants_ok version=" . MXP_SEARCH_VERSION . " onnx=" . (MXP_SEARCH_ONNX ? '1' : '0') . " reranker=" . (MXP_SEARCH_RERANKER ? '1' : '0') . "\n";

$root = ini_get('mxp_search.store_root') ?: '/var/lib/mxp-local-search/kb';
$path = $root . '/php-contract-smoke';
if (Store::exists($path)) {
    $old = Store::open($path);
    Store::destroy($path, $old->destroyConfirmationToken());
}

$store = Store::create($path, ['name' => 'PHP contract smoke', 'model' => 'multilingual-e5-small', 'dimensions' => 384]);
mxp_require(Store::exists($path), 'Store exists after create');
mxp_require(Store::open($path)->kb_id() === $store->kb_id(), 'Store open preserves kb_id');
mxp_require($store->path() === $path, 'Store path method');

$batch = $store->indexBatch([
    ['id' => 'contract-alpha', 'title' => 'Contract Alpha', 'content' => 'PHP extension release contract for ONNX vectors and hybrid search.', 'metadata' => ['status' => 'publish', 'visibility' => 'public']],
    ['id' => 'contract-beta', 'title' => 'Contract Beta', 'content' => 'Banana recipe unrelated control document for ranking.', 'metadata' => ['status' => 'publish', 'visibility' => 'public']],
]);
mxp_require((int) ($batch['new'] ?? 0) === 2 || (int) ($batch['full'] ?? 0) === 2, 'indexBatch records two documents');
mxp_require($store->count() === 2, 'count after indexBatch');
$stats = $store->stats();
mxp_require((int) $stats['document_count'] === 2, 'stats document_count');
mxp_require((int) $stats['vector_count'] === 2, 'stats vector_count');
$listed = Store::list($root);
mxp_require(count($listed) >= 1, 'Store::list returns stores');
echo "store_contract_ok documents={$stats['document_count']} vectors={$stats['vector_count']}\n";

foreach (['fast', 'semantic', 'hybrid', 'deep'] as $mode) {
    $hits = $store->search('PHP extension', ['mode' => $mode, 'limit' => 2]);
    mxp_require(count($hits) >= 1, "{$mode} search returns hits");
    mxp_require($hits[0]['doc_id'] === 'contract-alpha', "{$mode} ranks alpha first");
    echo "search_{$mode}_ok top={$hits[0]['doc_id']} score={$hits[0]['score']}\n";
}

$outcome = $store->update('contract-alpha', 'Contract Alpha Updated', 'Updated semantic payload about release artifact verification and rollback.', ['status' => 'publish', 'visibility' => 'public']);
mxp_require($outcome === 'full', 'content update returns full');
$outcome = $store->update('contract-alpha', 'Contract Alpha Updated', 'Updated semantic payload about release artifact verification and rollback.', ['status' => 'publish', 'visibility' => 'public', 'tag' => 'metadata-only']);
mxp_require(in_array($outcome, ['metadata_fts_only', 'skipped'], true), 'unchanged content avoids vector re-embedding');
$deleted = $store->deleteBatch(['contract-beta']);
mxp_require($deleted === 1, 'deleteBatch deletes one');
mxp_require($store->delete('contract-missing') === false, 'delete missing returns false');
$stats = $store->stats();
mxp_require((int) $stats['document_count'] === 1 && (int) $stats['vector_count'] === 1, 'delete removes document and vector');
echo "update_delete_contract_ok documents={$stats['document_count']} vectors={$stats['vector_count']}\n";

$embedder = new Embedder('multilingual-e5-small');
mxp_require($embedder->dimensions() === 384, 'Embedder dimensions');
$query = $embedder->embedQuery('release verification query');
$doc = $embedder->embed('release verification document');
$batchVectors = $embedder->embedBatch(['first release document', 'second release document']);
mxp_require(count($query) === 384 && count($doc) === 384, 'single embeddings dimension');
mxp_require(count($batchVectors) === 2 && count($batchVectors[0]) === 384 && count($batchVectors[1]) === 384, 'batch embeddings dimension');
echo "embedder_contract_ok query_dims=" . count($query) . " batch=" . count($batchVectors) . "\n";
$reranker = new Reranker('onnx-community/bge-reranker-v2-m3-ONNX');
$good = $reranker->score('release verification query', 'release verification document');
$bad = $reranker->score('release verification query', 'banana recipe');
mxp_require(is_float($good) && is_float($bad) && $good >= 0.0 && $good <= 1.0 && $bad >= 0.0 && $bad <= 1.0, 'Reranker scores are normalized');
$batchScores = $reranker->scoreBatch('release verification query', ['release verification document', 'banana recipe']);
mxp_require(count($batchScores) === 2 && is_float($batchScores[0]) && is_float($batchScores[1]), 'Reranker scoreBatch returns scores');
echo "reranker_contract_ok good={$good} bad={$bad}\n";


$negativeCases = 0;
try { new Reranker('not-allowlisted-reranker'); } catch (MXP\Search\Exception $e) { ++$negativeCases; echo "unsupported_reranker_rejected\n"; }
try { new Embedder('not-allowlisted-model'); } catch (MXP\Search\Exception $e) { ++$negativeCases; echo "unsupported_model_rejected\n"; }
try { new Embedder('multilingual-e5-small', ['dimensions' => 13]); } catch (MXP\Search\Exception $e) { ++$negativeCases; echo "dimension_mismatch_rejected\n"; }
try { new Embedder('multilingual-e5-small', ['query_prefix' => 'bad: ']); } catch (MXP\Search\Exception $e) { ++$negativeCases; echo "prefix_mismatch_rejected\n"; }
try { new Embedder('custom/local', ['model_dir' => sys_get_temp_dir()]); } catch (MXP\Search\Exception $e) { ++$negativeCases; echo "local_model_without_flag_rejected\n"; }
try { $store->export('/tmp/out.json', 'confirm'); } catch (MXP\Search\Exception $e) { ++$negativeCases; echo "export_fail_closed_ok\n"; }
try { $store->import('/tmp/in.json', 'confirm'); } catch (MXP\Search\Exception $e) { ++$negativeCases; echo "import_fail_closed_ok\n"; }
try { $store->rebuild('rebuild'); } catch (MXP\Search\Exception $e) { ++$negativeCases; echo "rebuild_fail_closed_ok\n"; }
mxp_require($negativeCases === 8, 'all fail-closed negative cases executed');

$modelDir = (ini_get('mxp_search.model_dir') ?: '/var/lib/mxp-local-search/models') . '/multilingual-e5-small';
$tmp = sys_get_temp_dir() . '/mxp-contract-checksum-fail';
exec('rm -rf ' . escapeshellarg($tmp));
mkdir($tmp, 0777, true);
copy($modelDir . '/manifest.json', $tmp . '/manifest.json');
copy($modelDir . '/tokenizer.json', $tmp . '/tokenizer.json');
file_put_contents($tmp . '/model.onnx', 'tampered');
try {
    new Embedder($tmp, ['allow_local_model_path' => true]);
    fwrite(STDERR, "FAIL: checksum mismatch should fail closed\n");
    exit(1);
} catch (MXP\Search\Exception $e) {
    echo "checksum_mismatch_rejected\n";
}
exec('rm -rf ' . escapeshellarg($tmp));

Store::destroy($path, $store->destroyConfirmationToken());
echo "php_extension_contract_smoke_ok\n";
