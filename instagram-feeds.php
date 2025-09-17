<?php
/**
 * Plugin Name: Instagram Feeds
 * Description: Manage Instagram feeds and display them via shortcodes.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// プラグインのディレクトリパスを定義
define( 'INSTAGRAM_FEEDS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// アクセストークンの管理をまとめたファイル
require_once INSTAGRAM_FEEDS_PLUGIN_DIR . 'includes/access-token-manager.php';
// インスタグラムのfeed管理をまとめたファイル
require_once INSTAGRAM_FEEDS_PLUGIN_DIR . 'includes/instagram-feed-manager.php';

// 管理画面メニューとサブメニューの追加
function instagram_feeds_add_admin_menu() {
    // メインメニュー「Instagram Feeds」
    add_menu_page(
        'Instagram Feeds',                    // ページタイトル
        'Instagram Feeds',                    // メニュータイトル
        'manage_options',                     // 権限
        'instagram-feeds',                    // メニューのスラッグ
        'instagram_feeds_overview_page',      // 表示する関数
        'dashicons-instagram',                // アイコン
        20                                    // メニューの位置
    );
}
add_action( 'admin_menu', 'instagram_feeds_add_admin_menu' );

// 「Instagram Feeds」のメインページの表示関数
function instagram_feeds_overview_page() {
    ?>
    <div class="wrap">
        <h1>Instagram Feeds 概要</h1>
        <p>
            instagramのアプリIDと長期access tokenを設定するし、<br />
            投稿記事、固定ページでショートコードを入力するだけで<br />
            instagramのfeedがカルーセル表示されます！<br />
            <br />
            長期アクセストークンの取得方法は<a href="https://www.google.com/">こちら！</a>
        </p>
    </div>
    <?php
}

/**
 * cronの設定を登録するのと無効化時に外すの
 */
// プラグインが有効化された時に実行される関数
function instagram_token_refresher_activate() {
    // アクセストークン更新用cron設定(2ヶ月)
    if (!wp_next_scheduled('refresh_instagram_access_token_event')) {
        wp_schedule_event(time(), 'bi_monthly', 'refresh_instagram_access_token_event');
    }
}
//register_activation_hook(__FILE__, 'instagram_token_refresher_activate');

// 1時間ごとにInstagramのフィードを取得するCronジョブをスケジュール
function instagram_feed_schedule_cron() {
    // instagram Feedの自動取得、保存用cron設定(1時間)
    if (!wp_next_scheduled('fetch_instagram_feed_event')) {
        wp_schedule_event(time(), 'hourly', 'fetch_instagram_feed_event');
    }
}
add_action('wp', 'instagram_feed_schedule_cron');

// プラグインが無効化された時に実行される関数
function instagram_token_refresher_deactivate() {
    // アクセストークン更新cronの削除
    $ac_timestamp = wp_next_scheduled('refresh_instagram_access_token_event');
    if ($ac_timestamp) {
        wp_unschedule_event($ac_timestamp, 'refresh_instagram_access_token_event');
    }

    // feed取得cronの削除
    $if_timestamp = wp_next_scheduled('fetch_instagram_feed_event');
    if ($if_timestamp) {
        wp_unschedule_event($if_timestamp, 'fetch_instagram_feed_event');
    }
}
register_deactivation_hook(__FILE__, 'instagram_token_refresher_deactivate');



// --- YouTube追加機能 ---
require_once plugin_dir_path(__FILE__) . 'includes/youtube-account-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/youtube-feed-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/short-code-manager.php';
