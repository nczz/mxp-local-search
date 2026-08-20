#!/usr/bin/env bash
set -euo pipefail

ddev exec 'set -euo pipefail
php -r '\''
foreach (["store_root", "export_root", "model_dir"] as $key) {
    $path = ini_get("mxp_search.$key");
    $real = realpath($path) ?: $path;
    $docroot = realpath("/var/www/html/wordpress") ?: "/var/www/html/wordpress";
    if (str_starts_with($real, $docroot)) { fwrite(STDERR, "$key inside docroot\n"); exit(1); }
    echo "path_confined_$key=$real\n";
}
if (is_writable(ini_get("mxp_search.model_dir"))) { fwrite(STDERR, "model_dir must not be web writable\n"); exit(1); }
echo "model_dir_not_writable=1\n";
'\''
php -r '\''
use MXP\Search\Store;
$root = ini_get("mxp_search.store_root");
foreach (["/tmp/mxp-outside", "../mxp-traversal"] as $path) {
    try { Store::create($path, ["model"=>"multilingual-e5-small", "dimensions"=>384]); fwrite(STDERR, "unsafe path accepted: $path\n"); exit(1); }
    catch (MXP\Search\Exception $e) { echo "unsafe_path_rejected=$path\n"; }
}
$link = $root . "/mxp-symlink-probe";
@unlink($link);
symlink("/tmp", $link);
try { Store::create($link, ["model"=>"multilingual-e5-small", "dimensions"=>384]); fwrite(STDERR, "symlink path accepted\n"); exit(1); }
catch (MXP\Search\Exception $e) { echo "symlink_path_rejected\n"; }
@unlink($link);
$path = $root . "/mxp-security-confirm";
if (Store::exists($path)) { $old = Store::open($path); Store::destroy($path, $old->destroyConfirmationToken()); }
$store = Store::create($path, ["model"=>"multilingual-e5-small", "dimensions"=>384]);
try { Store::destroy($path, "wrong"); fwrite(STDERR, "destroy accepted bad confirm\n"); exit(1); }
catch (MXP\Search\Exception $e) { echo "destroy_bad_confirm_rejected\n"; }
Store::destroy($path, $store->destroyConfirmationToken());
'\''
php -r '\''try { new MXP\Search\Embedder("unsupported-model"); fwrite(STDERR, "unsupported model accepted\n"); exit(1); } catch (MXP\Search\Exception $e) { echo "unsupported_model_rejected\n"; }'\''
php -r '\''try { new MXP\Search\Embedder("custom/local", ["model_dir"=>sys_get_temp_dir()]); fwrite(STDERR, "local model accepted without flag\n"); exit(1); } catch (MXP\Search\Exception $e) { echo "local_model_without_flag_rejected\n"; }'\''
php -r '\''$model=ini_get("mxp_search.model_dir")."/multilingual-e5-small"; $tmp=sys_get_temp_dir()."/mxp-security-checksum-fail"; exec("rm -rf ".escapeshellarg($tmp)); mkdir($tmp,0777,true); foreach (["manifest.json","tokenizer.json"] as $f) copy("$model/$f", "$tmp/$f"); file_put_contents("$tmp/model.onnx", "bad"); try { new MXP\Search\Embedder($tmp, ["allow_local_model_path"=>true]); fwrite(STDERR,"checksum accepted\n"); exit(1); } catch (MXP\Search\Exception $e) { echo "checksum_mismatch_rejected\n"; } exec("rm -rf ".escapeshellarg($tmp));'\''
wp --path=wordpress eval '\''
do_action("rest_api_init");
delete_transient("mxp_search_write_lock");
wp_clear_scheduled_hook("mxp_search_index_all_event", [["post_type"=>"post", "batch"=>2]]);
$anon = new WP_REST_Request("POST", "/mxp-search/v1/index-all");
$anon->set_body_params(["post_type"=>"post", "batch"=>2]);
$res = rest_do_request($anon); echo "rest_anon_index_all=".$res->get_status()."\n"; if ($res->get_status() !== 403) exit(1);
wp_set_current_user(1);
$bad = new WP_REST_Request("POST", "/mxp-search/v1/index-all");
$bad->set_header("authorization", "Bearer attacker-controlled");
$bad->set_body_params(["post_type"=>"post", "batch"=>2]);
$res = rest_do_request($bad); echo "rest_cookie_auth_header_no_nonce=".$res->get_status()."\n"; if ($res->get_status() !== 403) exit(1);
$ok = new WP_REST_Request("POST", "/mxp-search/v1/index-all");
$ok->set_header("x-wp-nonce", wp_create_nonce("wp_rest"));
$ok->set_body_params(["post_type"=>"post", "batch"=>2]);
$res = rest_do_request($ok); echo "rest_nonce_index_all=".$res->get_status()."\n"; if ($res->get_status() !== 200) exit(1);
$unknown = new WP_REST_Request("POST", "/mxp-search/v1/index-all");
$unknown->set_header("x-wp-nonce", wp_create_nonce("wp_rest"));
$unknown->set_body_params(["post_type"=>"post", "batch"=>2, "evil"=>"field"]);
$res = rest_do_request($unknown); echo "rest_unknown_field=".$res->get_status()."\n"; if ($res->get_status() !== 400) exit(1);
$malformed = new WP_REST_Request("GET", "/mxp-search/v1/search");
$malformed->set_query_params(["q"=>"\" broken (((", "mode"=>"hybrid", "limit"=>999]);
$res = rest_do_request($malformed); $data = $res->get_data(); echo "rest_malformed_search=".$res->get_status()." results=".count($data["results"] ?? [])."\n"; if ($res->get_status() !== 200) exit(1);
'\''
'

echo "security_probes_ok"
