<?php
/**
 * 設定ファイルのひな型。
 * このファイルを「config.php」という名前でコピーして値を書き換えてください。
 * （config.php はWebから中身を見られない PHP ファイルなので、キーやパスワードを置いて大丈夫です）
 */
return [
    // Google Maps Platform の APIキー（Routes API を有効化したもの）
    // 空のままだと料金計算でエラーになります。テスト時は下の mock_distance を true に。
    'google_maps_api_key' => '',

    // true にすると Google を呼ばず、住所から作ったダミー距離で計算します（動作確認用）
    'mock_distance' => true,

    // 管理画面のパスワード（必ず変更してください）
    // password_hash() で作ったハッシュ（$2y$...）を入れることもできます
    'admin_password' => 'change-me',

    // 別ドメインのサイトに埋め込む場合のみ、そのサイトのURLを列挙（同じドメインなら空でOK）
    // 例: ['https://www.example.com']
    'allowed_origins' => [],

    // 1つのIPアドレスから1時間に受け付ける料金計算・申込の回数（APIの使いすぎ防止）
    'quote_limit_per_hour' => 40,
    'apply_limit_per_hour' => 5,

    // 申込通知メールの送信元アドレス（空ならサーバーの既定値）
    'mail_from' => '',
];
