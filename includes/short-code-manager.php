<?php
// カスタム投稿タイプ 'instagram-feed' からアイキャッチを取得しカルーセルを表示するショートコード
function instagram_feed_carousel_shortcode( $attr ) {
    // 初期化
    $word = isset($attr['word']) ? $attr['word'] : "";
    $exclude_word = isset($attr['exclude_word']) ? $attr['exclude_word'] : "";
    $per_page = isset($attr['count']) ? $attr['count'] : 20;
    $per_page = $per_page > 50 ? 50 : $per_page;
    $output = "";

    // クエリでカスタム投稿タイプ 'instagram-feed' の投稿を取得
    $args = array(
        'post_type' => 'instagram_feed',
        'posts_per_page' => $per_page, // 表示する投稿数
        'post_status' => 'publish', // 表示する投稿数
        'orderby'       => 'rand',
        's'         => $word,
        'exclude_word' => $exclude_word,
    );

    $query = new WP_Query($args);
    
    // 投稿が存在しない場合、何も表示しない
    if (!$query->have_posts()) {
        return '<p>このワードでの投稿はありますん。</p>';
    }

    // カルーセル用のHTML開始
    if($query->found_posts >= 5) {
        $output .= '<div class="instagram-feeds swiper swiper-container">';
    }else {
        $output .= '<div class="instagram-feeds swiper swiper-container few-feeds">';
    }
    
        $output .= '<div class="swiper-wrapper">';

        // 投稿をループして、アイキャッチ画像を表示
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            // 投稿本文を取得して、20文字に制限
            $content = get_the_content();  // 本文を取得
            $content = wp_strip_all_tags($content);
            $content = removeGreeting($content);
            $trimmed_content = mb_substr($content, 0, 20);  // 15文字に制限

            // feedの情報を取得
            $thumbnail_url = get_post_meta( $post_id, '_instagram_feed_thumbnail_url', true );
            $permalink     = get_post_meta( $post_id, '_instagram_feed_permalink', true );
            $youtube_url   = get_post_meta( $post_id, '_youtube_url', true );
            $note_url      = get_post_meta( $post_id, '_note_url', true );
            $menu_id       = get_post_meta( $post_id, '_menu_id', true );

            // サムネイル生存チェック(死んでたら飛ばす)
            if(!check_image_exists_wp($thumbnail_url)) {
                continue;
            }
            
            // キモイけどインデント整えないとhtml読みづらくてしゃーない
            $output .= '<div class="instagram-feed swiper-slide thumb-9-16">';
                $output .= '<img src="' . esc_url($thumbnail_url) . '" alt="' . esc_attr(get_the_title()) . '" />';
                
                $output .= '<div class="buttons-area">';
                    $output .= '<p class="captions">' . $trimmed_content . '</p>';

                    $output .= '<div class="icon-container">';
                        if($permalink) {
                            $output .= '<a target="_blank" href="' . $permalink . '">';
                                $output .= '<i class="fab fa-instagram"></i>';
                            $output .= '</a>';
                        }

                        if($youtube_url) {
                            $output .= '<a target="_blank" href="' . $youtube_url . '">';
                                $output .= '<i class="fab fa-youtube"></i>';
                            $output .= '</a>';
                        }
                        if($note_url) {
                            $output .= '<a target="_blank" href="' . $note_url . '">';
                                $output .= '<i class="fas fa-sticky-note"></i>';
                            $output .= '</a>';
                        }
                        if($menu_id) {
                            $output .= '<a target="_blank" href="' . get_permalink($menu_id) . '">';
                                $output .= '<i class="fa fa-shopping-cart"></i>';
                            $output .= '</a>';
                        }
                    // end icon-container
                    $output .= '</div>';
                // end buttons-area
                $output .= '</div>';
            // end instagram-feed
            $output .= '</div>';
        }
    
        // end swiper-wrapper
        $output .= '</div>';
    
    // end instagram-feeds
    $output .= '</div>';
    
    // クエリをリセット
    wp_reset_postdata();
    
    return $output;
}
add_shortcode('instagram_feed_carousel', 'instagram_feed_carousel_shortcode');

// instagram_feedを検索する場合スペース区切りをAND検索にする
function custom_search_where_for_instagram_feed($where, $wp_query) {
    // WHERE句をカスタマイズしてAND検索を実行
    $where = '';

    // 検索ワード
    if ($wp_query->is_search && !empty($wp_query->query_vars['s']) && $wp_query->get('post_type') === 'instagram_feed') {
        $search_terms = explode(' ', $wp_query->query_vars['s']);
        if ($search_terms) {
            foreach ($search_terms as $term) {
                $where .= " AND (post_title LIKE '%$term%' OR post_content LIKE '%$term%')";
            }
        }
    }
    
    // 除外ワード
    $exclude_terms = array( 'あらたつ先生の個別指導', );
    $exclude_terms = !empty($wp_query->query_vars['exclude_word'])
                 ? array_merge($exclude_terms, explode(' ', $wp_query->query_vars['exclude_word']))
                 : $exclude_terms;
    if ($wp_query->is_search && $wp_query->get('post_type') === 'instagram_feed') {
        foreach ($exclude_terms as $term) {
            $where .= " AND post_title NOT LIKE '%$term%'";
            $where .= " AND post_content NOT LIKE '%$term%'";
        }
    }

    return $where;
}
add_filter('posts_search', 'custom_search_where_for_instagram_feed', 10, 2);

// プラグインのCSSを読み込む関数
function my_plugin_enqueue_styles() {
    $version = "0.4.6";
    
    // Swiperの読み込み
    wp_enqueue_style('swiper-css', 'https://unpkg.com/swiper/swiper-bundle.min.css', $version, true);
    wp_enqueue_script('swiper-js', 'https://unpkg.com/swiper/swiper-bundle.min.js', array(), $version, true);

    // プラグインディレクトリからCSSを読み込む
    wp_enqueue_style(
        'instagram-feeds-style', // CSSハンドル名
        plugin_dir_url(__FILE__) . '../asset/css/style.css', // CSSのパス
        array(), // 依存関係（なければ空の配列）
        filemtime(plugin_dir_url(__FILE__) . '../asset/css/style.css'), // バージョン
        'all' // メディア（全ての画面向け）
    );

    // プラグインディレクトリからJSを読み込む
    wp_enqueue_script(
        'instagram-feeds-script', // JSハンドル名
        plugin_dir_url(__FILE__) . '../asset/js/carousel-slider.js', // パス
        array(), // 依存関係（なければ空の配列）
        filemtime(plugin_dir_url(__FILE__) . '../asset/js/carousel-slider.js'), // バージョン
        true, // 読み込み位置指定
    );
}
add_action('wp_enqueue_scripts', 'my_plugin_enqueue_styles');

function removeGreeting($text) {
    // 削除したい挨拶のパターンを正規表現で指定
    $pattern = '/^(こんにちわ|こんばんわ|こんばんは|こんにちは|おはようございます)[!！?？]*/u';
    
    // 挨拶部分を削除
    $result = preg_replace($pattern, '', $text);

    // 定型文を削除
    $result = preg_replace('/今回紹介するのは/u', '', $result);

    // スペースとか削除
    $result = preg_replace('/\s|　|\r|\n/u', '', $result);

    return $result;
}
