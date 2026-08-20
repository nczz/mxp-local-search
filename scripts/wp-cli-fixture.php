<?php
$id = wp_insert_post([
    'post_title' => 'MXP Release Smoke CLI',
    'post_content' => 'MXP Release Smoke CLI fast semantic hybrid command coverage.',
    'post_status' => 'publish',
    'post_type' => 'post',
]);
if (is_wp_error($id) || ! $id) {
    fwrite(STDERR, "could not create CLI smoke post\n");
    exit(1);
}
$result = MXP_Local_Search_Plugin::instance()->index_manager->index_post((int) $id, true);
if (is_wp_error($result)) {
    fwrite(STDERR, $result->get_error_message() . "\n");
    exit(1);
}
echo (int) $id . "\n";
