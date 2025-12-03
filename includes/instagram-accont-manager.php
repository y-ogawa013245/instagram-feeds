<?php
/**
 * Plugin Name: Ascend IG Manager
 * Description: Instagram Graph API アカウント管理（OAuth, 長期トークン更新, フィード取得）
 * Version: 0.1.0
 */
// カスタム投稿タイプ「Instagramアカウント」の登録
function create_instagram_account_post_type() {
    $labels = array(
        'name' => 'Instagram Accounts',
        'singular_name' => 'Instagram Account',
        'menu_name' => 'Instagram Accounts',
        'name_admin_bar' => 'Instagram Account',
        'add_new' => 'Add New',
        'add_new_item' => 'Add New Instagram Account',
        'new_item' => 'New Instagram Account',
        'edit_item' => 'Edit Instagram Account',
        'view_item' => 'View Instagram Account',
        'all_items' => 'Instagramアカウント管理',
        'search_items' => 'Search Instagram Accounts',
        'not_found' => 'No Instagram Accounts found.',
        'not_found_in_trash' => 'No Instagram Accounts found in Trash.'
    );

    $args = array(
        'labels' => $labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'instagram-feeds',
        'has_archive' => false, 
        'supports' => array('instagram_account', array('title' => true,)),
    );

    register_post_type('instagram_account', $args);
}
add_action('init', 'create_instagram_account_post_type');

/** ---------------------------
 *  2) メタボックス（AppID/Secret と 認証ボタン）
 * -------------------------- */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'ig_credentials_box',
        'Instagram 認証・設定',
        'ig_render_meta_box',
        'instagram_account',
        'normal',
        'high'
    );
});

function ig_render_meta_box($post) {
    wp_nonce_field('ig_save_meta', 'ig_nonce');

    // post titleがアカウント名
    $post_title = $post->post_title;
    $app_id     = get_post_meta($post->ID, 'ig_app_id', true);
    $app_secret = get_post_meta($post->ID, 'ig_app_secret', true);

    $long_token = get_post_meta($post->ID, 'ig_user_token_long', true);
    $expires_at = get_post_meta($post->ID, 'ig_token_expires_at', true);
    $fb_page_id = get_post_meta($post->ID, 'fb_page_id', true);
    $ig_user_id = get_post_meta($post->ID, 'ig_user_id', true);
?>
    <table class="form-table">
        <tr>
            <th><label for="post_title">アカウント名</label></th>
            <td><input type="text" class="regular-text" name="post_title" id="post_title" value="<?php echo esc_attr($post_title); ?>"></td>
        </tr>
        <tr>
            <th><label for="ig_app_id">App ID</label></th>
            <td><input type="text" class="regular-text" name="ig_app_id" id="ig_app_id" value="<?php echo esc_attr($app_id); ?>"></td>
        </tr>
        <tr>
            <th><label for="ig_app_secret">App Secret</label></th>
            <td><input type="password" class="regular-text" name="ig_app_secret" id="ig_app_secret" value="<?php echo esc_attr($app_secret); ?>"></td>
        </tr>
    </table>

    <p>
        <?php if ($post_title && $app_id && $app_secret) {
            // OAuth用データとしてmetaデータに保存
            $state = base64_encode(wp_json_encode([
                'nonce'  => wp_create_nonce('ig_state'),
                'postId' => (int) $post->ID,
            ]));
            update_post_meta($post->ID, 'ig_oauth_state', $state);

            $redirect = admin_url('admin-post.php?action=ig_oauth_callback'); // 固定

            $auth_url = add_query_arg([
            'client_id'     => $app_id,
            'redirect_uri'  => $redirect,  // add_query_arg が値をURLエンコードしてくれる
            'state'         => $state,
            'response_type' => 'code',
            'scope'         => implode(',', ['pages_show_list','instagram_basic']),
            ], 'https://www.facebook.com/v20.0/dialog/oauth');

            ?>
            <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary">Facebookで認証</a>
        <?php } else { ?>
            <em>App ID と App Secret を保存すると認証ボタンが表示されます。</em>
        <?php } ?>
    </p>

    <hr>

    <h4>現在の状態</h4>
    <ul>
        <li><strong>Long-lived Token:</strong> <?php echo $long_token ? '保存済み' : '未取得'; ?></li>
        <li><strong>有効期限(目安):</strong> <?php echo $expires_at ? esc_html(date('Y-m-d H:i', (int)$expires_at)) : '-'; ?></li>
        <li><strong>Facebook Page ID:</strong> <?php echo $fb_page_id ? esc_html($fb_page_id) : '-'; ?></li>
        <li><strong>IG User ID:</strong> <?php echo $ig_user_id ? esc_html($ig_user_id) : '-'; ?></li>
    </ul>
    <?php
}

add_action('save_post_instagram_account', function ($post_id) {
    if (!isset($_POST['ig_nonce']) || !wp_verify_nonce($_POST['ig_nonce'], 'ig_save_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    foreach (['ig_app_id','ig_app_secret'] as $key) {
        if (isset($_POST[$key])) {
            update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
        }
    }
});

/** ---------------------------
 *  3) OAuth コールバック
 *     短期 → 長期トークン交換
 *     ページ経由で ig_user_id 取得
 * -------------------------- */
add_action('admin_post_ig_oauth_callback', function () {
    if (!current_user_can('edit_posts')) {
        error_log('Permission denied.');
        return;
    }

    $state   = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
    $code    = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';

    $data = json_decode(base64_decode($state), true);
    if (!$data || empty($data['nonce']) || empty($data['postId'])) {
        error_log('Invalid OAuth state (malformed).');
        return;
    }

    $post_id = $data['postId'];

    $saved_state = get_post_meta($post_id, 'ig_oauth_state', true);
    if (!$post_id || !$code || !$saved_state || $saved_state !== $state) {
        error_log('Invalid OAuth state.');
        return;
    }

    $app_id     = get_post_meta($post_id, 'ig_app_id', true);
    $app_secret = get_post_meta($post_id, 'ig_app_secret', true);
    $redirect   = admin_url('admin-post.php?action=ig_oauth_callback&post_id=' . $post_id);

    // 3-1) code → 短期ユーザートークン
    $token_res = wp_remote_get(add_query_arg([
        'client_id'     => $app_id,
        'redirect_uri'  => $redirect,
        'client_secret' => $app_secret,
        'code'          => $code,
    ], 'https://graph.facebook.com/v20.0/oauth/access_token'));

    if (is_wp_error($token_res)) {
        error_log('Token request failed.');
        return;
    }
    $token_body = json_decode(wp_remote_retrieve_body($token_res), true);
    if (empty($token_body['access_token'])) {
        error_log('No access_token in response.');
        return;
    }
    $short_lived_token = $token_body['access_token'];

    // 3-2) 短期 → 長期（約60日）ユーザートークン
    $long_res = wp_remote_get(add_query_arg([
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $app_id,
        'client_secret'     => $app_secret,
        'fb_exchange_token' => $short_lived_token,
    ], 'https://graph.facebook.com/v20.0/oauth/access_token'));

    if (is_wp_error($long_res)) {
        error_log('Long-lived exchange failed.');
        return;
    }
    $long_body = json_decode(wp_remote_retrieve_body($long_res), true);
    if (empty($long_body['access_token'])) {
        error_log('No long-lived access_token.');
        return;
    }

    $long_token = $long_body['access_token'];
    // 期限（秒）は返らない場合があるため 60日を目安に保存
    $expires_at = time() + 60 * DAY_IN_SECONDS;

    update_post_meta($post_id, 'ig_user_token_long', $long_token);
    update_post_meta($post_id, 'ig_token_expires_at', $expires_at);

    // 3-3) ページ一覧 → instagram_business_account から ig_user_id を得る
    $pages_res = ig_graph_get(
        'me/accounts',
        ['fields' => 'id,name,access_token', 'limit' => 100],
        $long_token,      // ユーザー長期トークン
        $app_secret
    );

    if (!is_wp_error($pages_res)) {
        $pages = json_decode(wp_remote_retrieve_body($pages_res), true);
        if (!empty($pages['data'])) {
            $chosen_fb_page_id = null;
            $ig_user_id = null;

            // 最初にIG連携があるページを採用（必要ならUIで選択させる拡張を）
            foreach ($pages['data'] as $p) {
                $page_id = $p['id'];
                $page_access_token = $p['access_token'];

                $page_q = ig_graph_get(
                    $page_id,
                    ['fields' => 'instagram_business_account'],
                    $page_access_token,
                    $app_secret
                );

                if (!is_wp_error($page_q)) {
                    $page_info = json_decode(wp_remote_retrieve_body($page_q), true);
                    if (!empty($page_info['instagram_business_account']['id'])) {
                        $chosen_fb_page_id = $page_id;
                        $ig_user_id = $page_info['instagram_business_account']['id'];
                        break;
                    }
                }
            }

            if ($chosen_fb_page_id && $ig_user_id) {
                update_post_meta($post_id, 'fb_page_id', $chosen_fb_page_id);
                update_post_meta($post_id, 'ig_user_id', $ig_user_id);
            }
        }else{
            error_log('レスポンスボディにdataがありません。');
        }
    }else{
        error_log('ページ取得に失敗しました。');
    }

    wp_safe_redirect(get_edit_post_link($post_id, ''));
});

/** ---------------------------
 *  4) 長期トークンの月1リフレッシュ（再交換）
 *      - 既存の長期ユーザートークンを再度 fb_exchange_token にかける
 * -------------------------- */
add_action('ig_refresh_tokens_event', 'ig_refresh_tokens');
function ig_refresh_tokens() {
    error_log('長期トークン更新開始');

    $accounts = get_posts(['post_type' => 'instagram_account', 'posts_per_page' => -1, 'post_status' => 'any']);
    foreach ($accounts as $acc) {
        $app_id     = get_post_meta($acc->ID, 'ig_app_id', true);
        $app_secret = get_post_meta($acc->ID, 'ig_app_secret', true);
        $long_token = get_post_meta($acc->ID, 'ig_user_token_long', true);
        if (!$app_id || !$app_secret || !$long_token) continue;

        $res = ig_graph_get(
            'oauth/access_token',
            [
                'grant_type' => 'fb_exchange_token', 
                'client_id' => $app_id, 
                'client_secret' => $app_secret, 
                'fb_exchange_token' => $long_token
            ],
            $long_token, $app_secret
        );

        if (!is_wp_error($res)) {
            $body = json_decode(wp_remote_retrieve_body($res), true);
            if (!empty($body['access_token'])) {
                update_post_meta($acc->ID, 'ig_user_token_long', $body['access_token']);
                update_post_meta($acc->ID, 'ig_token_expires_at', time() + 60 * DAY_IN_SECONDS);
            }else{
                error_log('返り値にアクセストークンがありませんでした');
            }
        }else{
            error_log('長期トークン更新に失敗しました。');
        }
    }

    error_log('長期トークン更新完了');
};

/** ---------------------------
 *  5) フィード取得ユーティリティ（短期キャッシュ付き）
 * フィード取得ユーティリティ（短期キャッシュ付き）
 * limit=0 または負数 で "全部"（ページングで収集）。
 * IG Graphの1ページ最大はおおよそ100件なので、page_size=100で繰り返し取得します。
 * 安全のため上限（safety cap）を設けています（必要なら調整）。
 */
function ig_get_media($post_id, $limit = 0, $cache_minutes = 15) {
    $ig_user_id = get_post_meta($post_id, 'ig_user_id', true);
    $token      = get_post_meta($post_id, 'ig_user_token_long', true);
    $app_secret = get_post_meta($post_id, 'ig_app_secret', true);

    if (!$ig_user_id || !$token || !$app_secret) {
        error_log('credential nothing');
        return [];
    }

    // キャッシュキー（limit=0は "all" として扱う）
    $cache_key_suffix = ($limit && $limit > 0) ? $limit : 'all';
    $cache_key = "ig_media_{$post_id}_{$cache_key_suffix}";
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    // 取得フィールド
    $base_fields = 'id,caption,media_type,media_url,permalink,timestamp,thumbnail_url,like_count,comments_count,video_play_count';

    // 1ページのサイズ（最大100が目安）
    $page_size = 100;

    // “全部”取得の安全上限（必要に応じて変更）
    $safety_cap = 1000;

    $collected = [];
    $after = null;

    // 「limit > 0」の場合は、その件数に達したら終了。
    // 「limit <= 0」の場合は、nextが無くなる or safety_capに達したら終了。
    while (true) {
        // 今回ページで必要な件数
        $need = ($limit > 0) ? min($page_size, $limit - count($collected)) : $page_size;
        if ($need <= 0) break;

        $params = [
            'fields' => $base_fields,
            'limit'  => $need, // IG側のmaxに丸められる
        ];
        if ($after) $params['after'] = $after;

        // ★ ig_graph_get はレスポンス（WP HTTP APIの配列）を返す前提
        $res = ig_graph_get("{$ig_user_id}/media", $params, $token, $app_secret);
        if (is_wp_error($res)) {
            error_log('ig_get_media: request error: ' . $res->get_error_message());
            break;
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);

        if ($code !== 200) {
            error_log("ig_get_media: HTTP {$code} body={$body}");
            break;
        }
            error_log("ig_get_media: HTTP {$code} body={$body}");

        $json = json_decode($body, true);
        $data = isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
        $collected = array_merge($collected, $data);

        // 件数条件チェック
        if ($limit > 0 && count($collected) >= $limit) {
            $collected = array_slice($collected, 0, $limit);
            break;
        }
        if ($limit <= 0 && count($collected) >= $safety_cap) {
            // 取りすぎ防止の上限
            break;
        }

        // 次ページ判定
        $after = isset($json['paging']['cursors']['after']) ? $json['paging']['cursors']['after'] : null;
        $has_next = !empty($json['paging']['next']);

        if (!$after || !$has_next) {
            // もう次がない
            break;
        }
    }

    set_transient($cache_key, $collected, MINUTE_IN_SECONDS * $cache_minutes);
    return $collected;
}
