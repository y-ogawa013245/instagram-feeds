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

// Instagram APIからフィードを取得する関数（ig_get_media() 利用版）
function fetch_instagram_feed() {
    error_log('[IG] fetch start');

    // instagramアカウント全件（CPT: instagram_account）
    $accounts = get_posts([
        'post_type'      => 'instagram_account',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    if (empty($accounts)) {
        error_log('[IG] no instagram_account posts');
        return;
    }

    foreach ($accounts as $account_id) {
        error_log('[IG] Feed取得開始 account=' . $account_id);

        // ig_get_media() は内部で ig_user_id / ig_user_token_long / ig_app_secret を読む
        // limit=0 => 全件（安全上限は ig_get_media 側の safety_cap）
        $feeds = ig_get_media($account_id, 0, 15);

        if (empty($feeds)) {
            error_log("[IG] no media for account={$account_id}");
            continue;
        }

        foreach ($feeds as $feed_item) {
            // カルーセルはスキップ（必要なら拡張）
            if (!empty($feed_item['media_type']) && $feed_item['media_type'] === 'CAROUSEL_ALBUM') {
                continue;
            }

            $feed_id   = $feed_item['id']         ?? '';
            $caption   = $feed_item['caption']    ?? '';
            $permalink = $feed_item['permalink']  ?? '';
            $timestamp = $feed_item['timestamp']  ?? '';

            // 画像/動画の表示URL（動画はサムネイルを採用）
            $image_url = '';
            if (!empty($feed_item['media_type']) && $feed_item['media_type'] === 'IMAGE') {
                $image_url = $feed_item['media_url'] ?? '';
            } else {
                $image_url = $feed_item['thumbnail_url'] ?? ($feed_item['media_url'] ?? '');
            }

            // 既存ポスト有無チェック
            $existing = new WP_Query([
                'post_type'  => 'instagram_feed',
                'meta_key'   => '_instagram_feed_id',
                'meta_value' => $feed_id,
                'fields'     => 'ids',
                'posts_per_page' => 1,
            ]);

            if ($existing->have_posts()) {
                // 既存 → サムネイル生存チェックのみ（必要ならpublishへ戻す）
                $existing->the_post();
                $post_id = get_the_ID();

                // 既存 → サムネ生存チェック
                $existing_url = get_post_meta($post_id, '_instagram_feed_thumbnail_url', true);
                $alive = true;
                if (function_exists('check_image_exists_wp') && !empty($existing_url)) {
                    $alive = check_image_exists_wp($existing_url);
                }

                if (!$alive) {
                    // 期限切れ検知 → Graph から詳細取得
                    $media = ig_fetch_media_details($account_id, $feed_id);

                    if (is_wp_error($media)) {
                        // 代表的なケース:
                        // - code=100 sub=33（オブジェクト無し）= 削除/非公開の可能性大
                        // - code=10/200（許可なし）= 非公開や権限不足
                        $data = $media->get_error_data();
                        $code = is_array($data) ? ($data['code'] ?? 0) : 0;
                        $sub  = is_array($data) ? ($data['error_subcode'] ?? 0) : 0;

                        // ここは保守的に "非公開/不可視" 扱い
                        wp_update_post(['ID' => $post_id, 'post_status' => 'private']);
                        error_log("[IG feed] refresh failed, code={$code} sub={$sub} -> private: {$post_id}");
                    } else {
                        // Graphは取れた → Instagram上の公開可否を確認
                        $permalink = $media['permalink'] ?? '';
                        $is_public = ig_permalink_is_public($permalink);

                        if (!$is_public) {
                            // Instagram側で非公開になった
                            wp_update_post(['ID' => $post_id, 'post_status' => 'private']);
                            error_log("[IG feed] instagram not public -> private: {$post_id}");
                        } else {
                            // 公開されている → サムネURLを更新して publish
                            $new_url  = ig_pick_display_image_url($media);
                            $ts       = $media['timestamp'] ?? '';
                            $unix     = $ts ? (is_numeric($ts) ? intval($ts) : strtotime($ts)) : 0;

                            if (!empty($new_url)) {
                                update_post_meta($post_id, '_instagram_feed_thumbnail_url', $new_url);
                            }
                            if (!empty($permalink)) {
                                update_post_meta($post_id, '_instagram_feed_permalink', $permalink);
                            }
                            if (!empty($ts)) {
                                update_post_meta($post_id, '_instagram_feed_timestamp', $ts);
                            }
                            if ($unix) {
                                update_post_meta($post_id, '_instagram_feed_timestamp_unix', $unix);
                            }

                            // （任意）ローカルに取り込みたい場合
                            // $local = ig_sideload_image_and_attach($post_id, $new_url);
                            // if ($local) update_post_meta($post_id, '_instagram_feed_local_url', $local);

                            wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
                            error_log("[IG feed] thumb refreshed -> publish: {$post_id}");
                        }
                    }
                } else {
                    // サムネが生きてる → 公開維持
                    wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
                }
            } else {
                // 新規作成
                $post_id = wp_insert_post([
                    'post_title'   => wp_trim_words($caption, 10, '...'),
                    'post_content' => $caption,
                    'post_status'  => 'publish',
                    'post_type'    => 'instagram_feed',
                ]);

                if (is_wp_error($post_id) || !$post_id) {
                    error_log('[IG feed] insert failed: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown'));
                    continue;
                }
            }

            if (!empty($post_id)) {
                // captionからYouTube URL抽出
                $youtube_url = '';
                if (!empty($caption)) {
                    $youtube_regex = '/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/|v\/|user\/\S+|channel\/\S+|c\/\S+)|youtu\.be\/)([\w\-]{11})/';
                    if (preg_match($youtube_regex, $caption, $m)) {
                        $youtube_url = $m[0];
                    }
                }

                // メタ更新
                update_post_meta($post_id, '_instagram_api_id', get_post_meta($account_id, 'ig_user_id', true)); // 参考: アカウント側のIGユーザID
                update_post_meta($post_id, '_instagram_feed_id', $feed_id);
                update_post_meta($post_id, '_instagram_feed_permalink', $permalink);
                update_post_meta($post_id, '_instagram_feed_thumbnail_url', $image_url);
                update_post_meta($post_id, '_instagram_feed_timestamp', $timestamp);
                update_post_meta($post_id, '_youtube_url', $youtube_url);

                error_log("[IG feed] upserted post_id={$post_id} thumb={$image_url}");
            }
        }
    }

    error_log('[IG] fetch done');
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


/** instagramサムネイル生存確認 + 延命 */
// --- Graph API: メディア詳細取得（成功:配列 / 失敗:WP_Error）---
function ig_fetch_media_details($account_id, $media_id) {
    $token = get_post_meta($account_id, 'ig_user_token_long', true);
    if (!$token || !$media_id) {
        return new WP_Error('ig_param', 'missing token or media_id');
    }

    $endpoint = add_query_arg([
        'fields'       => 'id,media_type,media_url,thumbnail_url,permalink,timestamp',
        'access_token' => $token,
    ], "https://graph.instagram.com/{$media_id}");

    $res = wp_remote_get($endpoint, ['timeout' => 10]);
    if (is_wp_error($res)) return $res;

    $code = wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);

    if ($code >= 200 && $code < 300 && is_array($body) && !isset($body['error'])) {
        return $body; // 正常
    }
    // Graph 側のエラーはここへ
    $err = isset($body['error']) ? $body['error'] : ['message' => 'unknown', 'code' => 0, 'error_subcode' => 0];
    return new WP_Error('ig_graph', sprintf('Graph error: %s (code=%s sub=%s)',
        $err['message'] ?? 'unknown', $err['code'] ?? 'n/a', $err['error_subcode'] ?? 'n/a'
    ), $err);
}

// --- 公開可否の推定：permalink を叩いてログイン壁かどうかを見る ---
function ig_permalink_is_public($permalink) {
    if (empty($permalink)) return false;

    $res = wp_remote_get($permalink, [
        'timeout'      => 8,
        'redirection'  => 5,
        'user-agent'   => 'WordPress; instagram-check',
    ]);
    if (is_wp_error($res)) return false;

    $body = wp_remote_retrieve_body($res);
    $code = wp_remote_retrieve_response_code($res);

    if ($code >= 400) return false;
    if (!is_string($body) || $body === '') return false;

    // ログインページの典型文言（多言語も考慮してゆるめ判定）
    $login_markers = [
        'Login • Instagram',           // 英語
        'Log in • Instagram',
        'ログイン • Instagram',        // 日本語
        'Войти • Instagram',           // 露
        'Entrar • Instagram',          // 西/葡
        'Se connecter • Instagram',    // 仏
    ];
    foreach ($login_markers as $m) {
        if (strpos($body, $m) !== false) return false;
    }
    // og:site_name が Instagram で、タイトルがログイン系でなければ公開扱い
    if (strpos($body, 'property="og:site_name" content="Instagram"') !== false) {
        return true;
    }
    return true; // 最後は緩めに true（404 等は前段で弾く）
}

// --- media_typeに応じた見出し画像URLを決定 ---
function ig_pick_display_image_url($media) {
    $type = $media['media_type'] ?? '';
    if ($type === 'IMAGE') {
        return $media['media_url'] ?? '';
    }
    // VIDEO / CAROUSEL_ALBUM などは thumbnail_url 優先
    return $media['thumbnail_url'] ?? ($media['media_url'] ?? '');
}
