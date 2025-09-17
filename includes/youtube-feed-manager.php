<?php
// YouTube Feed Manager - Shorts Detection with Pagination

/**
 * YouTube Shorts 判定（canonicalタグを利用）
 */
function is_youtube_short($video_id) {
    $url = "https://youtube.com/shorts/{$video_id}";
    $response = wp_remote_get($url, [
        'redirection' => 5,
        'timeout'     => 10,
        'sslverify'   => false,
        'headers'     => ['User-Agent' => 'Mozilla/5.0']
    ]);

    if (is_wp_error($response)) {
        error_log("[YouTube Fetch] GET check failed for: {$video_id} - " . $response->get_error_message());
        return false;
    }

    $html = wp_remote_retrieve_body($response);

    // canonicalタグから判定
    if (preg_match('/<link[^>]+rel="canonical"[^>]+href="([^"]+)"/i', $html, $m)) {
        $canonical_url = $m[1];
        error_log("[YouTube Fetch] Canonical URL: {$canonical_url}");
        return strpos($canonical_url, '/shorts/') !== false;
    }

    error_log("[YouTube Fetch] No canonical found for: {$video_id}");
    return false;
}

/**
 * YouTube Shorts フィード取得（ページネーション対応）
 */
function fetch_youtube_shorts_feed($account_post_id) {
    $channel_id = get_post_meta($account_post_id, '_youtube_channel_id', true);
    $api_key    = get_post_meta($account_post_id, '_youtube_api_key', true);

    error_log("[YouTube Fetch] Start - Account Post ID: {$account_post_id}");

    if (empty($channel_id) || empty($api_key)) {
        error_log("[YouTube Fetch] Missing channel_id or api_key");
        return;
    }

    $page_token = '';
    $fetched_count = 0;
    $max_fetch = 500; // 最大取得数（必要なら増減可）

    do {
        $api_url = add_query_arg([
            'key'            => $api_key,
            'channelId'      => $channel_id,
            'part'           => 'snippet',
            'order'          => 'date',
            'maxResults'     => 50,
            'type'           => 'video',
            'videoDuration'  => 'short',
            'pageToken'      => $page_token
        ], 'https://www.googleapis.com/youtube/v3/search');

        error_log("[YouTube Fetch] Request URL: {$api_url}");

        $response = wp_remote_get($api_url);

        if (is_wp_error($response)) {
            error_log("[YouTube Fetch] API request failed: " . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['items'])) {
            error_log("[YouTube Fetch] No items returned from API.");
            break;
        }

        foreach ($data['items'] as $item) {
            if (!isset($item['id']['videoId'])) {
                continue;
            }

            $video_id     = $item['id']['videoId'];
            $title        = $item['snippet']['title'];
            $thumbnail    = $item['snippet']['thumbnails']['high']['url'] ?? '';
            $published_at = $item['snippet']['publishedAt'] ?? '';
            $short_url    = esc_url_raw('https://youtube.com/shorts/' . $video_id);

            error_log("[YouTube Fetch] Found short candidate: {$video_id} - {$title}");

            // Shorts判定
            if (!is_youtube_short($video_id)) {
                error_log("[YouTube Fetch] Not a shorts video, skipping: {$title}");
                continue;
            }

            // すでに登録済みか確認
            $existing = get_posts([
                'post_type'      => 'instagram_feed',
                'meta_key'       => '_youtube_url',
                'meta_value'     => $short_url,
                'fields'         => 'ids',
                'posts_per_page' => 1,
            ]);
            if (!empty($existing)) {
                error_log("[YouTube Fetch] Duplicate found, skipping: {$video_id}");
                continue;
            }

            // 投稿作成
            $post_id = wp_insert_post([
                'post_type'   => 'instagram_feed',
                'post_status' => 'publish',
                'post_title'  => sanitize_text_field($title),
                'post_content'=> sanitize_text_field($title) . '（YouTube Shorts）',
            ]);

            if (is_wp_error($post_id) || $post_id === 0) {
                error_log("[YouTube Fetch] Failed to insert post for video: {$video_id}");
                continue;
            }

            update_post_meta($post_id, '_instagram_feed_thumbnail_url', esc_url_raw($thumbnail));
            update_post_meta($post_id, '_youtube_url', $short_url);
            update_post_meta($post_id, '_instagram_feed_timestamp', strtotime($published_at));

            error_log("[YouTube Fetch] Saved new post for video: {$video_id}");
            $fetched_count++;

            if ($fetched_count >= $max_fetch) {
                break 2; // 500件取得で終了
            }
        }

        $page_token = $data['nextPageToken'] ?? '';
    } while (!empty($page_token));

    error_log("[YouTube Fetch] Fetch complete. Total saved: {$fetched_count}");
}

// 手動取得用
add_action('fetch_youtube_feed_event', function() {
    error_log("[YouTube Fetch] Running manual fetch event.");
    $accounts = get_posts([
        'post_type' => 'youtube_account',
        'numberposts' => -1
    ]);
    foreach ($accounts as $account) {
        fetch_youtube_shorts_feed($account->ID);
    }
});

// 保存時に自動実行
add_action('save_post_youtube_account', function($post_id) {
    error_log("[YouTube Fetch] save_post_youtube_account triggered for: {$post_id}");
    fetch_youtube_shorts_feed($post_id);
});
