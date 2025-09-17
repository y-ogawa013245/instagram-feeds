<?php
// YouTube Account Manager
// Instagramアカウント管理の構造を参考に、YouTubeチャンネルIDとAPIキーを管理するカスタム投稿タイプを作成

function create_youtube_account_post_type() {
    $labels = array(
        'name' => 'YouTube Accounts',
        'singular_name' => 'YouTube Account',
        'menu_name' => 'YouTube Accounts',
        'name_admin_bar' => 'YouTube Account',
        'add_new' => 'Add New',
        'add_new_item' => 'Add New YouTube Account',
        'new_item' => 'New YouTube Account',
        'edit_item' => 'Edit YouTube Account',
        'view_item' => 'View YouTube Account',
        'all_items' => 'YouTubeアカウント管理',
        'search_items' => 'Search YouTube Accounts',
        'not_found' => 'No YouTube Accounts found.',
        'not_found_in_trash' => 'No YouTube Accounts found in Trash.'
    );

    $args = array(
        'labels' => $labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'instagram-feeds',
        'has_archive' => false,
        'supports' => array('title'),
    );

    register_post_type('youtube_account', $args);
}
add_action('init', 'create_youtube_account_post_type');

// メタボックス追加
function youtube_account_add_meta_boxes() {
    add_meta_box(
        'youtube_account_meta_box',
        'YouTube API Details',
        'youtube_account_meta_box_callback',
        'youtube_account',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes', 'youtube_account_add_meta_boxes');

// メタボックス内容
function youtube_account_meta_box_callback($post) {
    $channel_id = get_post_meta($post->ID, '_youtube_channel_id', true);
    $api_key = get_post_meta($post->ID, '_youtube_api_key', true);
    ?>
    <label for="youtube_channel_id">YouTube Channel ID:</label>
    <input type="text" id="youtube_channel_id" name="youtube_channel_id" value="<?php echo esc_attr($channel_id); ?>" style="width:100%;"><br><br>

    <label for="youtube_api_key">YouTube API Key:</label>
    <input type="text" id="youtube_api_key" name="youtube_api_key" value="<?php echo esc_attr($api_key); ?>" style="width:100%;"><br>
    <?php
}

// 保存処理
function youtube_account_save_postdata($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $channel_id = isset($_POST['youtube_channel_id']) ? sanitize_text_field($_POST['youtube_channel_id']) : '';
    $api_key = isset($_POST['youtube_api_key']) ? sanitize_text_field($_POST['youtube_api_key']) : '';

    update_post_meta($post_id, '_youtube_channel_id', $channel_id);
    update_post_meta($post_id, '_youtube_api_key', $api_key);
}
add_action('save_post_youtube_account', 'youtube_account_save_postdata');
?>
