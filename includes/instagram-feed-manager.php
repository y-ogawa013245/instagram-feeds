<?php
// カスタム投稿タイプ 'instagram-feed' を登録
function create_instagram_feed_post_type() {
    $labels = array(
        'name' => 'Instagram Feeds',
        'singular_name' => 'Instagram Feed',
        'menu_name' => 'Instagram Feeds',
        'name_admin_bar' => 'Instagram Feed',
        'edit_item' => 'Edit Instagram Feed',
        'view_item' => 'View Instagram Feed',
        'all_items' => 'IntagramFeed管理',
        'search_items' => 'Search Instagram Feeds',
        'not_found' => 'No Instagram Feeds found.',
        'not_found_in_trash' => 'No Instagram Feeds found in Trash.'
    );

    $args = array(
        'labels' => $labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'instagram-feeds',
        'has_archive' => false, 
    );

    register_post_type('instagram_feed', $args);
}
add_action('init', 'create_instagram_feed_post_type');

// Instagram APIからフィードを取得する関数
function fetch_instagram_feed() {
	error_log('fetch start');
	
    // instagramアカウント全部取る
    $posts = get_all_instagram_account_posts();

    foreach($posts as $post) {
        // post_idってやつよ
        $account_id = $post->ID;

		error_log('account:: ' . $account_id);
		
        $api_id = get_post_meta($account_id, '_instagram_api_id', true);
        $access_token = get_post_meta($account_id, '_instagram_access_token', true);

        if (!$api_id || !$access_token) {
            return wp_die( new WP_Error('post_creation_failed', 'データ足りねぇゾォぉぉおお！！栗原ぁぁああああ！！'), null, array('back_link' => true) );
        }

        // instagram feedを取得するためのURL
        $api_url = 'https://graph.facebook.com/v20.0/' . $api_id . '/media?fields=id,caption,thumbnail_url,media_type,media_url,permalink,timestamp&limit=50&access_token=' . $access_token;
        $all_feeds = array();

		error_log('api_url:: ' . $api_url);
		
        // ページネーションで全てのフィードを取得
        while ($api_url) {
            // APIリクエストを送信
            $response = wp_remote_get($api_url);

            // エラーチェック
            if (is_wp_error($response)) {
				error_log('wp_remote_get error:: ' . $api_url);
                return wp_die( new WP_Error('post_creation_failed', $response->get_error_message()), null, array('back_link' => true) );
            }

            // レスポンスの内容を取得
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (!isset($data['data'])) {
                return wp_die( new WP_Error('post_creation_failed', '取れてないんですけお！'), null, array('back_link' => true) );
            }

            // 取得したフィードを追加
            $all_feeds = array_merge($all_feeds, $data['data']);

            // 次のページがあるか確認
            $api_url = isset($data['paging']['next']) ? $data['paging']['next'] : null;
        }

        // 取得したfeedをカスタム投稿タイプ「instagram-feed」として保存
        foreach ($all_feeds as $feed_item) {
            // カルーセルタイプだったらめんどいのでスキップ
            if ($feed_item['media_type'] == 'CAROUSEL_ALBUM') {
                continue; 
            }

            // Instagramのフィードがすでに保存されているか確認
            $existing_feed = new WP_Query(array(
                'post_type' => 'instagram_feed',
                'meta_key' => '_instagram_feed_id',
                'meta_value' => $feed_item['id'],
            ));

            // すでに存在する場合は登録はしない
            if (!$existing_feed->have_posts()) {
                // 新しい投稿を作成
                $post_id = wp_insert_post(array(
                    'post_title' => wp_trim_words($feed_item['caption'], 10, '...'),
                    'post_content' => $feed_item['caption'],
                    'post_status' => 'publish',
                    'post_type' => 'instagram_feed',
                ));
            }else {
                // 更新のためにpost_idとっとく
                while($existing_feed->have_posts()) {
                    $existing_feed->the_post();
                    $post_id = get_the_ID();

                    // サムネイルURL取得
                    $exsiting_url = get_post_meta( $post_id, '_instagram_feed_thumbnail_url', true );

                    // サムネイル画像の生存チェック
                    if(!check_image_exists_wp($exsiting_url)) {
                        // し、死んでる.....!!
                        wp_update_post([
                            'ID'          => $post_id,       // 対象の投稿ID
                            'post_status' => 'private',      // 非公開
                        ]);
                        
                        error_log("[instagram feed] noexisting thumbnail, private: {$post_id}");
                    }else {
						wp_update_post([ 'ID' => $post_id, 'post_status' => 'publish' ]);
                        error_log("[instagram feed] updated thumbnail, publish: {$post_id}");
					}
                }
            }

            // 動画サムネイルか画像かでパスが違う
            $image_url = $feed_item['media_type'] == 'IMAGE'
                     ? $feed_item['media_url']
                     : $feed_item['thumbnail_url'];

            // captionからyoutubeのurlを抽出する
            $youtube_url = "";
            $youtube_regex = '/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/|v\/|user\/\S+|channel\/\S+|c\/\S+)|youtu\.be\/)([\w\-]{11})/';
            if (preg_match($youtube_regex, $feed_item['caption'], $matches)) {
                $youtube_url = $matches[0];
            }

            // 念のためpost_idがある時しか更新しない
            if ($post_id) {
				error_log("new thumbnail:: " . $image_url . "\n");
                // カスタムフィールドにデータを保存
                update_post_meta($post_id, '_instagram_api_id', $api_id);
                update_post_meta($post_id, '_instagram_feed_id', $feed_item['id']);
                update_post_meta($post_id, '_instagram_feed_permalink', $feed_item['permalink']);
                update_post_meta($post_id, '_instagram_feed_thumbnail_url', $image_url);
                update_post_meta($post_id, '_instagram_feed_timestamp', $feed_item['timestamp']);
                update_post_meta($post_id, '_youtube_url', $youtube_url);
            }
        }
    }
}
// Cronジョブのイベントにfetch_instagram_feed関数を登録
add_action('fetch_instagram_feed_event', 'fetch_instagram_feed');

// カスタム投稿編集画面にyoutubeなどの外部リンク用カスタムフィールドを表示する
// カスタムメタボックスを登録
function add_instagram_feed_meta_box() {
    add_meta_box(
        'youtube_url_box',            // メタボックスID
        'Youtbe URL',            // メタボックスタイトル
        'display_youtube_url_box',    // コールバック関数
        'instagram_feed',       // カスタム投稿タイプ
        'side',                      // 表示場所 (normal, side, etc.)
        'high'                         // 表示優先度 (high, low)
    );
    add_meta_box(
        'note_url_box',            // メタボックスID
        'note URL',            // メタボックスタイトル
        'display_note_url_box',    // コールバック関数
        'instagram_feed',       // カスタム投稿タイプ
        'side',                      // 表示場所 (normal, side, etc.)
        'high'                         // 表示優先度 (high, low)
    );
    add_meta_box(
        'menu_id_box',            // メタボックスID
        'menu page',            // メタボックスタイトル
        'display_menu_id_box',    // コールバック関数
        'instagram_feed',       // カスタム投稿タイプ
        'side',                      // 表示場所 (normal, side, etc.)
        'high'                         // 表示優先度 (high, low)
    );
}
add_action('add_meta_boxes', 'add_instagram_feed_meta_box');

// メタボックスの内容を表示するコールバック関数
function display_youtube_url_box() {
    // 保存時の非表示フィールド (セキュリティ)
    wp_nonce_field('save_custom_field_data', 'custom_field_nonce');

    // 現在の値を取得
    $post_id = get_the_ID();
    $value = get_post_meta($post_id, '_youtube_url', true);
    $value = esc_attr($value);

    // 入力フォームを表示
    echo '<label for="_youtube_url">Youtube URL:</label>';
    echo '<input type="text" id="_youtube_url" name="_youtube_url" value="' . $value . '" />';
}
function display_note_url_box() {
    // 保存時の非表示フィールド (セキュリティ)
    wp_nonce_field('save_custom_field_data', 'custom_field_nonce');

    // 現在の値を取得
    $post_id = get_the_ID();
    $value = get_post_meta($post_id, '_note_url', true);
    $value = esc_attr($value);

    // 入力フォームを表示
    echo '<label for="_note_url">Note URL:</label>';
    echo '<input type="text" id="_note_url" name="_note_url" value="' . $value . '" />';
}
function display_menu_id_box() {
    // 保存時の非表示フィールド (セキュリティ)
    wp_nonce_field('save_custom_field_data', 'custom_field_nonce');

    // 現在の値を取得
    $post_id = get_the_ID();
    $value = get_post_meta($post_id, '_menu_id', true);
    $value = esc_attr($value);

    // クエリでカスタム投稿タイプ 'instagram-feed' の投稿を取得
    $args = array(
        'post_type' => 'post',
        'posts_per_page' => -1, // 表示する投稿数
        'post_status' => 'publish', // 表示する投稿数
        'category_name' => 'menu',          // カテゴリースラッグを指定（複数の場合は「,」で区切る）
        'orderby'       => 'date',
        'order'         => 'DESC',
    );

    $query = new WP_Query($args);
    
    // 入力フォームを表示
    echo '<label for="_menu_id">翻訳済みメニューページ:</label>';
    echo '<select name="_menu_id" id="_menu_id">';
    echo '<option value="">メニューなし</option>';
    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $title = get_the_title();

        if($post_id == $value) {
            echo '<option selected value="' . $post_id . '">' . $title . '</option>';
        }else {
            echo '<option value="' . $post_id . '">' . $title . '</option>';
        }
    }
    echo '</select>';
}

function save_instagram_feed_meta($post_id) {
    // 自動保存時には何もしない
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    // ユーザーが編集権限を持っているかを確認
    if (!current_user_can('edit_post', $post_id)) return;

    // nonceをチェック
    if (!isset($_POST['custom_field_nonce']) || !wp_verify_nonce($_POST['custom_field_nonce'], 'save_custom_field_data')) return;

    // カスタムフィールドの値を取得
    if (isset($_POST['_youtube_url'])) {
        $_youtube_url = sanitize_text_field($_POST['_youtube_url']);
        update_post_meta($post_id, '_youtube_url', $_youtube_url);
    }

    if (isset($_POST['_note_url'])) {
        $_note_url = sanitize_text_field($_POST['_note_url']);
        update_post_meta($post_id, '_note_url', $_note_url);
    }

    if (isset($_POST['_menu_id'])) {
        $_menu_id = sanitize_text_field($_POST['_menu_id']);
        update_post_meta($post_id, '_menu_id', $_menu_id);
    }
}
add_action('save_post_instagram_feed', 'save_instagram_feed_meta');

// カスタムスケジュールの追加
// 最初は2ヶ月ごとだったけど、2ヶ月で切れるんだから余裕もって1ヶ月じゃないとダメじゃね？
function add_custom_cron_schedule($schedules) {
    $schedules['bi_monthly'] = array(
        'interval' => 60 * 60 * 24 * 30, // 2ヶ月 (60日)
        'display' => __('Every 1 Months')
    );
    return $schedules;
}
add_filter('cron_schedules', 'add_custom_cron_schedule');
