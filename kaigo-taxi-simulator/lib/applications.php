<?php
declare(strict_types=1);

/** 申込データ。data/applications/{申込番号}.json に1件ずつ保存 */

const APPLICATION_STATUSES = ['新規', '対応中', '受付確定', 'お断り', 'キャンセル'];

function next_application_id(): string
{
    // 日付ごとの連番: K261008-001
    $path = DATA_DIR . '/counter.json';
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    $fp = fopen($path, 'c+');
    flock($fp, LOCK_EX);
    $counter = json_decode((string)stream_get_contents($fp), true) ?: [];
    $today = date('ymd');
    $n = ($counter['date'] ?? '') === $today ? (int)$counter['n'] + 1 : 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(['date' => $today, 'n' => $n]));
    flock($fp, LOCK_UN);
    fclose($fp);
    return sprintf('K%s-%03d', $today, $n);
}

function application_path(string $id): string
{
    if (!preg_match('/^K\d{6}-\d{3,}$/', $id)) {
        throw new InvalidArgumentException('不正な申込番号');
    }
    return DATA_DIR . '/applications/' . $id . '.json';
}

function save_application(array $app): void
{
    json_write(application_path($app['id']), $app);
}

function get_application(string $id): ?array
{
    try {
        $path = application_path($id);
    } catch (InvalidArgumentException) {
        return null;
    }
    return is_file($path) ? json_read($path) : null;
}

function list_applications(): array
{
    $apps = [];
    foreach (glob(DATA_DIR . '/applications/*.json') ?: [] as $file) {
        $app = json_read($file);
        if ($app) {
            $apps[] = $app;
        }
    }
    usort($apps, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $apps;
}

function format_ride_datetime(array $app): string
{
    $w = ['日', '月', '火', '水', '木', '金', '土'][(int)date('w', strtotime($app['date']))];
    return date('Y/m/d', strtotime($app['date'])) . "($w) " . $app['time'];
}

/** お客様がLINEで送るメッセージ本文 */
function build_line_message(array $app): string
{
    $q = $app['quote'];
    $lines = [
        '【介護タクシー 申込】',
        '申込番号：' . $app['id'],
        'ご利用日時：' . format_ride_datetime($app),
        'お迎え先：' . $app['origin'],
        '行き先：' . $app['destination'],
        '片道/往復：' . ($q['round_trip'] ? '往復' : '片道'),
        '距離：約' . $q['distance_km'] . 'km（片道）',
        'オプション：' . ($q['options'] ? implode('、', $q['options']) : 'なし'),
        '概算料金：' . number_format($q['total']) . '円',
        'お名前：' . $app['name'],
        '電話番号：' . $app['phone'],
        'ご利用者の状態：' . $app['condition'],
        '同乗者：' . $app['companions'] . '名',
        'お支払方法：' . $app['payment'],
    ];
    if ($app['note'] !== '') {
        $lines[] = '備考：' . $app['note'];
    }
    return implode("\n", $lines);
}

function line_message_url(array $s, string $text): string
{
    return $s['line_id'] === ''
        ? ''
        : 'https://line.me/R/oaMessage/' . rawurlencode($s['line_id']) . '/?' . rawurlencode($text);
}

function notify_new_application(array $app, array $s): void
{
    if ($s['notify_email'] === '') {
        return;
    }
    $from = app_config()['mail_from'];
    $headers = $from !== '' ? 'From: ' . $from : '';
    $body = "新しい申込がありました。\n"
        . "お客様からLINEでも同じ内容が届きます（届かない場合は電話で確認してください）。\n\n"
        . build_line_message($app) . "\n";
    if (!mb_send_mail($s['notify_email'], '【介護タクシー】新規申込 ' . $app['id'], $body, $headers)) {
        error_log('申込通知メールの送信に失敗: ' . $app['id']);
    }
}
