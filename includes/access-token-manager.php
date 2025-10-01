<?php
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

// カスタムメタボックスの追加
function instagram_account_add_meta_boxes() {
    add_meta_box(
        'instagram_account_meta_box', // HTML ID
        'Instagram API Details',      // 表示タイトル
        'instagram_account_meta_box_callback', // コールバック関数
        'instagram_account',           // 投稿タイプ
        'normal',                      // 表示する位置
        'default'                      // 表示の優先度
    );
}
add_action('add_meta_boxes', 'instagram_account_add_meta_boxes');

// メタボックスの内容
function instagram_account_meta_box_callback($post) {
    // 保存されているデータの取得
    $instagram_api_id = get_post_meta($post->ID, '_instagram_api_id', true);
    $instagram_app_secret = get_post_meta($post->ID, '_instagram_app_secret', true);
    $instagram_access_token = get_post_meta($post->ID, '_instagram_access_token', true);

    ?>
    <label for="instagram_api_id">Instagram API ID:</label>
    <input type="text" id="instagram_api_id" name="instagram_api_id" value="<?php echo esc_attr($instagram_api_id); ?>" style="width:100%;"><br><br>

    <label for="instagram_app_secret">Instagram APP SECRET:</label>
    <input type="text" id="instagram_app_secret" name="instagram_app_secret" value="<?php echo esc_attr($instagram_app_secret); ?>" style="width:100%;"><br><br>

    <label for="instagram_access_token">Instagram 長期 Access Token:</label>
    <input type="text" id="instagram_access_token" name="instagram_access_token" value="<?php echo esc_attr($instagram_access_token); ?>" style="width:100%;"><br>
    <?php
}

// メタデータの保存
function instagram_account_save_postdata($post_id) {
    // 自動保存時には何もしない
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // 権限がなければ何もしない
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    // 入力されてないフィールドがあっても何もしない
    // 何もするな....黄猿....
    if (empty($_POST['instagram_api_id']) 
     || empty($_POST['instagram_app_secret'])
     || empty($_POST['instagram_access_token'])) {
            return;
    }

    // データをサニタイズ
    $app_id = sanitize_text_field($_POST['instagram_api_id']);
    $secret = sanitize_text_field($_POST['instagram_app_secret']);
    $token  = sanitize_text_field($_POST['instagram_access_token']);

    // すでにそのデータがないか確認
    if (is_instagram_account_registered($post_id, $app_id, $secret, $token)) {
        return wp_die( new WP_Error('api_error', 'すでにあんねん。'), null, array('back_link' => true) );
    }

    // instagramAPIからプロフィールを取得するURL
    $api_url = "https://graph.facebook.com/v20.0/" . $app_id . "?fields=name&access_token=" . $token;

    // APIリクエストを送信
    $response = wp_remote_get($api_url);

    // エラーチェック
    if (is_wp_error($response)) {
        return wp_die( new WP_Error('api_error', 'Instagram APIからプロフィールを取得できませんでした。'), null, array('back_link' => true) );
    }

    // レスポンスの内容を取得
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // データが正しく取得できているか確認
    if (!isset($data['name'])) {
        return wp_die( new WP_Error('api_error', 'Instagram APIからデータを取得できませんでした。'), null, array('back_link' => true) );
    }

    // 取得したアカウント名を使用して新しい投稿を作成
    $post = array(
        'ID' => $post_id,
        'post_title' => sanitize_text_field($data['name']), // アカウント名を投稿タイトルに設定
        'post_content' => $data['name'] . 'Instagramアカウント', // 投稿のコンテンツを設定
        'post_status' => 'publish',
        'post_type' => 'instagram_account',
    );
    
    // 無限ループ対策でhook削除
    remove_action('save_post_instagram_account', 'instagram_account_save_postdata');
    
    // 投稿を更新
    $update_result = wp_update_post($post, true, false);

    if (is_wp_error($update_result) || $update_result === 0) {
        // 無限ループ対策で解除してたhookの再設定。キモイ
        add_action('save_post_instagram_account', 'instagram_account_save_postdata');

        return wp_die( new WP_Error('post_creation_failed', 'Instagramアカウントの投稿に失敗しました。'), null, array('back_link' => true) );
    }

    // カスタムフィールドにAPI IDとアクセストークンを保存
    update_post_meta($post_id, '_instagram_api_id', $app_id);
    update_post_meta($post_id, '_instagram_app_secret', $secret);
    update_post_meta($post_id, '_instagram_access_token', $token);

    // 無限ループ対策で解除してたhookの再設定。キモイ
    add_action('save_post_instagram_account', 'instagram_account_save_postdata');

    // feed取得のcronを5分後に一度だけ実行（OK）
    if ( ! wp_next_scheduled('fetch_instagram_feed_event') ) {
        wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'fetch_instagram_feed_event' );
    }
}
add_action('save_post_instagram_account', 'instagram_account_save_postdata');

// アカウントのIDと投稿名取得する
function get_all_instagram_account_posts() {
    // クエリを作成して 'instagram_account' の全投稿を取得
    $query = new WP_Query(array(
        'post_type' => 'instagram_account',
        'posts_per_page' => -1, // すべての投稿を取得
    ));

    // 投稿IDと投稿名のリストを取得
    if ($query->have_posts()) {
        return $query->posts; // すべての投稿オブジェクトを配列として返す
    } else {
        return array(); // 投稿がない場合は空の配列を返す
    }
}

// 登録済みかチェック
function is_instagram_account_registered($post_id, $instagram_api_id, $instagram_app_secret, $instagram_access_token) {
    // クエリの引数を設定
    $args = array(
        'post_type'  => 'instagram_account',
        'post__not_in' => array( $post_id, ),
        'meta_query' => array(
            'relation' => 'AND', // AND 条件で両方のメタデータが一致するかを確認
            array(
                'key'   => '_instagram_api_id',
                'value' => $instagram_api_id,
                'compare' => '='
            ),
            array(
                'key'   => '_instagram_app_secret',
                'value' => $instagram_app_secret,
                'compare' => '='
            ),
            array(
                'key'   => '_instagram_access_token',
                'value' => $instagram_access_token,
                'compare' => '='
            ),
        ),
    );

    // クエリを実行
    $existing_accounts = get_posts($args);

    // 結果をチェック
    if (!empty($existing_accounts)) {
        return true;  // 既に登録されている
    }

    return false;  // 未登録
}

/**
 * EAA（Graph）専用：各アカウントの meta から app_id / app_secret を取り、
 * fb_exchange_token で延長する
 */
function refresh_instagram_access_token() {
    $accounts = get_all_instagram_account_posts();
    if (empty($accounts)) return;

    foreach ($accounts as $acc) {
        $token = trim((string) get_post_meta($acc->ID, '_instagram_access_token', true));
        if (!$token || strpos($token, 'EAA') !== 0) {
            error_log("{$acc->post_title}: skip (token empty or not EAA)");
            continue;
        }

        // ★ 各アカウントごとの App ID / Secret をメタから取得
        $app_id     = trim((string) get_post_meta($acc->ID, '_instagram_api_id', true));
        $app_secret = trim((string) get_post_meta($acc->ID, '_instagram_app_secret', true));

        if (empty($app_id) || empty($app_secret)) {
            error_log("{$acc->post_title}: skip (missing app_id or app_secret in meta)");
            continue;
        }

        // 残存有効期限メタがあれば、必要なときだけ更新（任意）
        $expires_in = (int) get_post_meta($acc->ID, '_instagram_access_token_expires_in', true);
        $refreshed  = (int) get_post_meta($acc->ID, '_instagram_access_token_refreshed_at', true);
        if ($expires_in && $refreshed) {
            $expires_at = $refreshed + $expires_in;
            $remain     = $expires_at - current_time('timestamp');
            // 残り7日切ったら更新（好みで調整）
            if ($remain > 7 * DAY_IN_SECONDS) {
                error_log("{$acc->post_title}: skip (still valid >7d)");
                continue;
            }
        }

        // EAA 延長：/oauth/access_token?grant_type=fb_exchange_token
        $url = add_query_arg(array(
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $app_id,
            'client_secret'     => $app_secret,
            'fb_exchange_token' => $token,
        ), 'https://graph.facebook.com/v20.0/oauth/access_token');

        error_log("refresh_url:: " . $url);
        
        $res  = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($res)) {
            error_log("{$acc->post_title}: http " . $res->get_error_message());
            continue;
        }

        $code = wp_remote_retrieve_response_code($res);
        $body_raw = wp_remote_retrieve_body($res);
        $body = json_decode($body_raw, true);

        if ($code !== 200 || empty($body['access_token'])) {
            error_log("{$acc->post_title}: refresh fail code={$code} body={$body_raw}");
            continue;
        }

        // トークン更新＆メタ保存（壊さない：trimのみ）
        $new_token = trim($body['access_token']);
        update_post_meta($acc->ID, '_instagram_access_token', $new_token);

        if (!empty($body['expires_in'])) {
            update_post_meta($acc->ID, '_instagram_access_token_expires_in', (int) $body['expires_in']);
            update_post_meta($acc->ID, '_instagram_access_token_refreshed_at', current_time('timestamp'));
        }

        error_log("{$acc->post_title}: token refreshed (code={$code})");
    }
}
add_action('refresh_instagram_access_token_event', 'refresh_instagram_access_token');

