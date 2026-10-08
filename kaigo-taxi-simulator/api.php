<?php
declare(strict_types=1);

/**
 * ウィジェットから呼ばれるAPI
 *   GET  api.php?action=config  … 画面表示に必要な設定
 *   POST api.php?action=quote   … 料金シミュレーション
 *   POST api.php?action=apply   … 申込の登録（LINE送信用URLを返す）
 */
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, app_config()['allowed_origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST');
    header('Vary: Origin');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input(): array
{
    $data = json_decode((string)file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function str_field(array $in, string $key, int $max, bool $required = true, string $label = ''): string
{
    $v = trim(preg_replace('/\s+/u', ' ', (string)($in[$key] ?? '')));
    if ($required && $v === '') {
        throw new UserError(($label ?: $key) . 'を入力してください。');
    }
    if (mb_strlen($v) > $max) {
        throw new UserError(($label ?: $key) . "は{$max}文字以内で入力してください。");
    }
    return $v;
}

/** 住所2つ・往復・オプションから見積もりを作る（quote と apply で共通） */
function quote_from_input(array $in, array $s): array
{
    $from = str_field($in, 'origin', 200, true, 'お迎え先');
    $to = str_field($in, 'destination', 200, true, '行き先');
    $options = array_values(array_filter((array)($in['options'] ?? []), 'is_string'));
    $route = route_distance($from, $to, $s);
    $quote = calc_quote($s, $route['meters'], !empty($in['round_trip']), $options);
    $quote['duration_min'] = (int)ceil($route['seconds'] / 60);
    $quote['map_url'] = $route['map_url'];
    return [$from, $to, $quote];
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($action === 'config' && $method === 'GET') {
        respond(['ok' => true, 'config' => public_settings()]);
    }

    if ($action === 'quote' && $method === 'POST') {
        if (!rate_limit('quote', client_ip(), (int)app_config()['quote_limit_per_hour'])) {
            throw new UserError('短時間に多くの計算が行われたため、しばらく時間をおいてお試しください。');
        }
        [, , $quote] = quote_from_input(input(), get_settings());
        respond(['ok' => true, 'quote' => $quote]);
    }

    if ($action === 'apply' && $method === 'POST') {
        $in = input();
        $s = get_settings();

        // ボット対策（画面に見えない項目に値が入っていたら破棄）
        if (!empty($in['website'])) {
            respond(['ok' => false, 'error' => '送信できませんでした。'], 400);
        }

        $date = (string)($in['date'] ?? '');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new UserError('ご利用日を選択してください。');
        }
        if ($date < date('Y-m-d')) {
            throw new UserError('ご利用日に過去の日付は選べません。');
        }
        $time = (string)($in['time'] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new UserError('ご利用時刻を選択してください。');
        }
        $name = str_field($in, 'name', 50, true, 'お名前');
        $phone = str_field($in, 'phone', 20, true, '電話番号');
        $digits = preg_replace('/\D/', '', mb_convert_kana($phone, 'n'));
        if (strlen($digits) < 10 || strlen($digits) > 11) {
            throw new UserError('電話番号を正しく入力してください。');
        }
        $condition = (string)($in['condition'] ?? '');
        if (!in_array($condition, $s['conditions'], true)) {
            throw new UserError('ご利用者の状態を選択してください。');
        }
        $companions = filter_var($in['companions'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 5]]);
        if ($companions === false) {
            throw new UserError('同乗者の人数を選択してください。');
        }
        $payment = (string)($in['payment'] ?? '');
        if (!in_array($payment, $s['payment_methods'], true)) {
            throw new UserError('お支払方法を選択してください。');
        }
        $note = str_field($in, 'note', 500, false, '備考');
        if (empty($in['agree'])) {
            throw new UserError('個人情報の取り扱いへの同意が必要です。');
        }

        if (!rate_limit('apply', client_ip(), (int)app_config()['apply_limit_per_hour'])) {
            throw new UserError('短時間に多くの申込が行われたため、しばらく時間をおいてお試しください。');
        }

        // 料金は画面から送られた値を使わず、サーバー側で計算し直す
        [$from, $to, $quote] = quote_from_input($in, $s);
        if ($quote['out_of_range']) {
            throw new UserError('対応エリア外のため、Webからは申し込めません。LINEまたはお電話でご相談ください。');
        }

        $app = [
            'id' => next_application_id(),
            'created_at' => date('Y-m-d H:i:s'),
            'status' => '新規',
            'memo' => '',
            'date' => $date,
            'time' => $time,
            'origin' => $from,
            'destination' => $to,
            'quote' => $quote,
            'name' => $name,
            'phone' => $phone,
            'condition' => $condition,
            'companions' => $companions,
            'payment' => $payment,
            'note' => $note,
        ];
        save_application($app);
        notify_new_application($app, $s);

        $message = build_line_message($app);
        respond([
            'ok' => true,
            'id' => $app['id'],
            'message' => $message,
            'line_url' => line_message_url($s, $message),
            'line_friend_url' => line_friend_url($s),
        ]);
    }

    respond(['ok' => false, 'error' => 'not found'], 404);
} catch (UserError $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[kaigo-taxi-simulator] ' . $e);
    respond(['ok' => false, 'error' => 'エラーが発生しました。時間をおいてお試しいただくか、お電話でお問い合わせください。'], 500);
}
