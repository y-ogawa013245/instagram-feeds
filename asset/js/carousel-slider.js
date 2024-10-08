jQuery(document).ready(function($) {
    $(".instagram-feeds").slick({
        slidesToShow: 3, // 横に3つのスライドを表示
        arrows: true, // 前へ・次へのページ送りボタンを表示
        dots: false, // ナビゲーションドットを非表示
        autoplay: true, // 自動再生を有効化
        autoplaySpeed: 2000, // 2秒ごとにスライド切替
        speed: 800, // 切り替えアニメーションの時間を800ms
        cssEase: 'ease', // イージングをリニアに設定
        centerMode: true, // 中央寄せ表示
        centerPadding: '100px', // 前後のスライドの見切れ幅
        adaptiveHeight: true // スライドの高さ調整
    });
});
