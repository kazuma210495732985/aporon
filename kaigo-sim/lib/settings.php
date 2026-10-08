<?php
declare(strict_types=1);

/** 管理画面で変更できる設定。data/settings.json に保存される */

function default_settings(): array
{
    return [
        'business_name' => '介護タクシー',

        // 料金方式: meter = 初乗り＋加算 / table = 距離帯ごとの固定料金
        'fare_mode' => 'meter',
        'base_km' => 2.0,        // 初乗り距離(km)
        'base_fare' => 800,      // 初乗り運賃(円)
        'add_km' => 0.4,         // 加算距離(km)
        'add_fare' => 100,       // 加算運賃(円)
        'fare_table' => [        // table方式: 「この距離まで」→ 料金
            ['up_to_km' => 5, 'fare' => 2000],
            ['up_to_km' => 10, 'fare' => 3500],
            ['up_to_km' => 20, 'fare' => 6000],
        ],

        'pickup_fee' => 0,           // 迎車料金(円) 0なら表示しない
        'rounding_unit' => 10,       // 端数処理の単位(円) 1なら処理なし
        'rounding_method' => 'ceil', // ceil=切り上げ / round=四捨五入 / floor=切り捨て
        'max_km' => 50,              // 片道この距離を超えたら「要相談」 0なら上限なし

        'round_trip_enabled' => true,
        'avoid_tolls' => true,       // 有料道路を使わないルートで距離を計算

        'options' => [
            ['name' => '車椅子貸出', 'price' => 0],
            ['name' => 'リクライニング車椅子貸出', 'price' => 1000],
            ['name' => 'ストレッチャー', 'price' => 3000],
            ['name' => '乗降介助', 'price' => 500],
        ],
        'conditions' => ['自力で歩ける', '車椅子', 'リクライニング車椅子', 'ストレッチャー（寝たまま）', 'その他'],
        'payment_methods' => ['現金', 'クレジットカード'],

        'line_id' => '',          // LINE公式アカウントのベーシックID（@xxxxxxx）
        'notify_email' => '',     // 申込があったときの通知先メール
        'privacy_url' => '',      // プライバシーポリシーのURL
        'notice_text' => '表示される料金は概算です。道路状況・待機時間・介助内容により変わる場合があります。',
    ];
}

function get_settings(): array
{
    // 設定項目はすべて1階層なので、保存値で既定値を上書きするだけでよい
    return array_merge(default_settings(), json_read(DATA_DIR . '/settings.json'));
}

function save_settings(array $settings): void
{
    json_write(DATA_DIR . '/settings.json', array_merge(default_settings(), $settings));
}

/** ウィジェットに渡してよい設定だけを返す */
function public_settings(): array
{
    $s = get_settings();
    return [
        'business_name' => $s['business_name'],
        'round_trip_enabled' => (bool)$s['round_trip_enabled'],
        'options' => $s['options'],
        'conditions' => $s['conditions'],
        'payment_methods' => $s['payment_methods'],
        'line_enabled' => $s['line_id'] !== '',
        'line_friend_url' => line_friend_url($s),
        'privacy_url' => $s['privacy_url'],
        'notice_text' => $s['notice_text'],
        'mock_distance' => (bool)app_config()['mock_distance'],
    ];
}

function line_friend_url(array $s): string
{
    return $s['line_id'] === '' ? '' : 'https://line.me/R/ti/p/' . rawurlencode($s['line_id']);
}
