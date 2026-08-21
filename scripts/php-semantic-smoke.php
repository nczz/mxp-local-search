<?php

declare(strict_types=1);

use MXP\Search\Store;
use MXP\Search\Embedder;

function require_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = ini_get('mxp_search.store_root') ?: '/var/lib/mxp-local-search/kb';
$path = $root . '/php-semantic-smoke';

if (Store::exists($path)) {
    $existing = Store::open($path);
    Store::destroy($path, $existing->destroyConfirmationToken());
}

$store = Store::create($path, [
    'name' => 'PHP semantic smoke',
    'model' => 'multilingual-e5-small',
    'dimensions' => 384,
]);
require_true(Store::exists($path), 'store should exist after create');
require_true(Store::open($path)->kb_id() === $store->kb_id(), 'open should return same immutable kb_id');
echo "create_open_ok kb_id={$store->kb_id()}\n";

$store->index('doc-alpha', 'Rust extension deployment', 'Build a PHP native extension, install ONNX Runtime, and load it in PHP-FPM.', [
    'status' => 'publish',
    'visibility' => 'public',
    'post_type' => 'post',
]);
$store->index('doc-beta', 'Banana bread recipe', 'Mash ripe bananas with flour, eggs, and butter before baking.', [
    'status' => 'publish',
    'visibility' => 'public',
    'post_type' => 'post',
]);
$stats = $store->stats();
require_true((int) $stats['document_count'] === 2, 'two documents should be indexed');
require_true((int) $stats['vector_count'] === 2, 'two document vectors should be indexed');
echo "vector_index_ok documents={$stats['document_count']} vectors={$stats['vector_count']}\n";

$semantic = $store->search('PHP extension ONNX deployment', ['mode' => 'semantic', 'limit' => 2]);
require_true(count($semantic) >= 1, 'semantic search should return hits');
require_true($semantic[0]['doc_id'] === 'doc-alpha', 'semantic search should rank deployment document first');
echo "semantic_search_ok top={$semantic[0]['doc_id']} score={$semantic[0]['score']}\n";

$hybrid = $store->search('native PHP extension', ['mode' => 'hybrid', 'limit' => 2]);
require_true(count($hybrid) >= 1, 'hybrid search should return hits');
require_true($hybrid[0]['doc_id'] === 'doc-alpha', 'hybrid search should rank deployment document first');
echo "hybrid_search_ok top={$hybrid[0]['doc_id']} score={$hybrid[0]['score']}\n";

$outcome = $store->update('doc-alpha', 'Updated vector target', 'Chocolate ganache and banana dessert instructions replace deployment text.', [
    'status' => 'publish',
    'visibility' => 'public',
    'post_type' => 'post',
]);
require_true($outcome === 'full', 'content-changing update should fully reindex');
$dessert = $store->search('chocolate banana dessert', ['mode' => 'semantic', 'limit' => 1]);
require_true(count($dessert) === 1 && $dessert[0]['doc_id'] === 'doc-alpha', 'updated semantic content should be searchable');
$deep = $store->search('chocolate banana dessert', ['mode' => 'deep', 'limit' => 1]);
require_true(count($deep) === 1 && $deep[0]['doc_id'] === 'doc-alpha', 'deep search should rerank updated semantic content');
echo "deep_search_ok top={$deep[0]['doc_id']} score={$deep[0]['score']}\n";
require_true($store->delete('doc-alpha') === true, 'delete should report existing document deletion');
$afterDelete = $store->stats();
require_true((int) $afterDelete['document_count'] === 1, 'delete should remove document');
require_true((int) $afterDelete['vector_count'] === 1, 'delete should remove vector');
echo "update_delete_ok documents={$afterDelete['document_count']} vectors={$afterDelete['vector_count']}\n";

$sourceModel = (ini_get('mxp_search.model_dir') ?: '/var/lib/mxp-local-search/models') . '/multilingual-e5-small';
$tempModelRoot = sys_get_temp_dir() . '/mxp-smoke-relative-models';
$customModel = $tempModelRoot . '/custom/local';
exec('rm -rf ' . escapeshellarg($tempModelRoot));
mkdir($customModel, 0777, true);
$manifest = json_decode((string) file_get_contents($sourceModel . '/manifest.json'), true);
$manifest['id'] = 'custom/local';
file_put_contents($customModel . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
foreach (array('model.onnx', 'tokenizer.json') as $file) {
    if (!@symlink($sourceModel . '/' . $file, $customModel . '/' . $file)) {
        require_true(copy($sourceModel . '/' . $file, $customModel . '/' . $file), "copy {$file} into relative model fixture");
    }
}

try {
    new Embedder('custom/local', array('model_dir' => $tempModelRoot, 'dimensions' => 384));
    fwrite(STDERR, "FAIL: relative local model path should require allow_local_model_path\n");
    exit(1);
} catch (MXP\Search\Exception $e) {
    require_true(str_contains($e->getMessage(), 'allowed') || str_contains($e->getMessage(), 'allow'), 'relative model path should fail closed without opt-in');
}
$localEmbedder = new Embedder('custom/local', array('model_dir' => $tempModelRoot, 'dimensions' => 384, 'allow_local_model_path' => true));
require_true($localEmbedder->dimensions() === 384, 'relative local model should load with allow_local_model_path');
exec('rm -rf ' . escapeshellarg($tempModelRoot));
echo "relative_local_model_ok\n";

Store::destroy($path, $store->destroyConfirmationToken());
echo "php_semantic_smoke_ok\n";
