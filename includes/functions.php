<?php

function render_template( $template_name, $data = array() ) {
    // path作って
    $dir_path = plugin_dir_path( __FILE__ ) . "../asset/templates";
    $template_path = $dir_path . '/' . $template_name . '.php';

    // 存在チェックして
    if(file_exists( $template_path )) {
        // 配列を変数にして
        extract($data);

        // テンプレート読み込む
        include($template_path);
    }
}

/**
 * 画像URLの生存チェック（file_get_contents版）
 * - HEAD で確認 → ダメなら GET + Range: bytes=0-0 で最小確認
 * - 2xx/3xx を「生存」とみなす
 */
function check_image_exists_wp(string $url, int $timeout = 8) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['alive'=>false,'code'=>0,'error'=>'invalid_url','headers'=>[], 'checked'=>current_time('mysql')];
    }

    $args = [
        'timeout'       => $timeout,
        'redirection'   => 5,
        'sslverify'     => true,
        'headers'       => [
            'User-Agent' => 'Mozilla/5.0 (WP HTTP API checker)',
            'Accept'     => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ],
    ];

    // 1) HEAD
    $res  = wp_remote_head($url, $args);
    $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);

    // 2) フォールバック（HEAD拒否/CDN対策）
    if ($code === 0 || $code === 405 || $code === 403 || $code === 429 || ($code >= 500 && $code <= 599)) {
        return false;
    }

    return true;
}

/** レスポンスヘッダ配列からHTTPステータスコードを抽出 */
function iulc_parse_status_code(array $headers): int {
    foreach ($headers as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
            return intval($m[1]);
        }
    }
    return 0;
}

/** ヘッダ配列を連想配列化（キーは小文字） */
function iulc_headers_to_assoc(array $headers): array {
    $out = [];
    foreach ($headers as $h) {
        $p = strpos($h, ':');
        if ($p !== false) {
            $k = strtolower(trim(substr($h, 0, $p)));
            $v = trim(substr($h, $p + 1));
            if (!isset($out[$k])) $out[$k] = $v; else {
                // 同じキーが複数ある場合はカンマで結合
                $out[$k] .= ', ' . $v;
            }
        }
    }
    return $out;
}
