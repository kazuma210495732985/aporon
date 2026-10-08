<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

session_name('kts_admin');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_password(string $input): bool
{
    $stored = (string)app_config()['admin_password'];
    if ($stored === '') {
        return false;
    }
    return str_starts_with($stored, '$2y$') ? password_verify($input, $stored) : hash_equals($stored, $input);
}

function redirect(string $query, string $flash = ''): never
{
    if ($flash !== '') {
        $_SESSION['flash'] = $flash;
    }
    header('Location: ?' . $query);
    exit;
}

/** 「名前,金額」形式の複数行テキストを配列にする */
function parse_pairs(string $text, string $k1, string $k2, bool $numericFirst): array
{
    $rows = [];
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim(mb_convert_kana($line, 'as'));
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode(',', $line));
        if (count($parts) !== 2 || !is_numeric($parts[1]) || ($numericFirst && !is_numeric($parts[0]))) {
            throw new UserError("書式が正しくない行があります: 「{$line}」");
        }
        $rows[] = [$k1 => $numericFirst ? (float)$parts[0] : $parts[0], $k2 => (int)$parts[1]];
    }
    return $rows;
}

function parse_lines(string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), fn($v) => $v !== ''));
}

$page = $_GET['page'] ?? 'applications';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$error = '';

// ---------- ログイン ----------
if ($page === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect('');
}

// パスワード未設定なら画面ログインは省略する（サーバー側の Basic 認証などで守る前提）
if (app_config()['admin_password'] === '') {
    $_SESSION['admin'] = true;
}

if (empty($_SESSION['admin'])) {
    if ($isPost) {
        if (!rate_limit('login', client_ip(), 10, 900)) {
            $error = 'ログイン試行が多すぎます。15分ほど待ってからお試しください。';
        } elseif (check_password((string)($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            redirect('');
        } else {
            $error = 'パスワードが違います。';
        }
    }
    if (app_config()['admin_password'] === 'change-me') {
        $error .= ' ※config.php の admin_password が初期値のままです。必ず変更してください。';
    }
    layout('ログイン', function () use ($error) { ?>
        <form method="post" class="card narrow">
            <h1>管理画面ログイン</h1>
            <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif ?>
            <label>パスワード<input type="password" name="password" required autofocus></label>
            <button>ログイン</button>
        </form>
    <?php }, false);
    exit;
}

if ($isPost && !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('画面の有効期限が切れました。戻って再読み込みしてください。');
}

$settings = get_settings();

// ---------- 保存処理 ----------
if ($isPost && $page === 'settings') {
    try {
        $num = fn(string $k) => (float)mb_convert_kana((string)($_POST[$k] ?? '0'), 'n');
        $new = [
            'business_name' => trim($_POST['business_name'] ?? ''),
            'fare_mode' => ($_POST['fare_mode'] ?? '') === 'table' ? 'table' : 'meter',
            'base_km' => $num('base_km'),
            'base_fare' => (int)$num('base_fare'),
            'add_km' => $num('add_km'),
            'add_fare' => (int)$num('add_fare'),
            'fare_table' => parse_pairs($_POST['fare_table'] ?? '', 'up_to_km', 'fare', true),
            'pickup_fee' => (int)$num('pickup_fee'),
            'rounding_unit' => max(1, (int)$num('rounding_unit')),
            'rounding_method' => in_array($_POST['rounding_method'] ?? '', ['ceil', 'round', 'floor'], true) ? $_POST['rounding_method'] : 'ceil',
            'max_km' => $num('max_km'),
            'round_trip_enabled' => !empty($_POST['round_trip_enabled']),
            'avoid_tolls' => !empty($_POST['avoid_tolls']),
            'options' => parse_pairs($_POST['options'] ?? '', 'name', 'price', false),
            'conditions' => parse_lines($_POST['conditions'] ?? ''),
            'payment_methods' => parse_lines($_POST['payment_methods'] ?? ''),
            'line_id' => trim($_POST['line_id'] ?? ''),
            'notify_email' => trim($_POST['notify_email'] ?? ''),
            'privacy_url' => trim($_POST['privacy_url'] ?? ''),
            'notice_text' => trim($_POST['notice_text'] ?? ''),
        ];
        if ($new['fare_mode'] === 'meter' && ($new['base_km'] <= 0 || $new['add_km'] <= 0)) {
            throw new UserError('初乗り距離・加算距離は0より大きい値にしてください。');
        }
        if ($new['fare_mode'] === 'table' && !$new['fare_table']) {
            throw new UserError('距離帯の料金表を1行以上入力してください。');
        }
        if (!$new['conditions'] || !$new['payment_methods']) {
            throw new UserError('「ご利用者の状態」「お支払方法」は1つ以上必要です。');
        }
        if ($new['line_id'] !== '' && !preg_match('/^@[0-9a-z._-]+$/i', $new['line_id'])) {
            throw new UserError('LINEのベーシックIDは「@」から始まる形式で入力してください（例: @123abcde）。');
        }
        if ($new['notify_email'] !== '' && !filter_var($new['notify_email'], FILTER_VALIDATE_EMAIL)) {
            throw new UserError('通知先メールアドレスの形式が正しくありません。');
        }
        if ($new['privacy_url'] !== '' && !preg_match('#^https?://#', $new['privacy_url'])) {
            throw new UserError('プライバシーポリシーのURLは http(s):// から入力してください。');
        }
        save_settings($new);
        redirect('page=settings', '設定を保存しました。');
    } catch (UserError $e) {
        $error = $e->getMessage();
        $settings = array_merge($settings, $_POST); // 入力内容を残す
    }
}

if ($isPost && $page === 'detail') {
    $app = get_application((string)($_GET['id'] ?? ''));
    if ($app) {
        $status = (string)($_POST['status'] ?? '');
        $app['status'] = in_array($status, APPLICATION_STATUSES, true) ? $status : $app['status'];
        $app['memo'] = mb_substr(trim((string)($_POST['memo'] ?? '')), 0, 2000);
        $app['updated_at'] = date('Y-m-d H:i:s');
        save_application($app);
        redirect('page=detail&id=' . rawurlencode($app['id']), '保存しました。');
    }
}

// ---------- 画面 ----------
function layout(string $title, callable $body, bool $nav = true): void
{
    global $page;
    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> | 介護タクシー管理</title>
<link rel="stylesheet" href="admin.css">
</head>
<body>
<?php if ($nav): ?>
<nav class="nav">
    <a href="?page=applications" class="<?= $page === 'applications' || $page === 'detail' ? 'on' : '' ?>">申込一覧</a>
    <a href="?page=settings" class="<?= $page === 'settings' ? 'on' : '' ?>">料金・設定</a>
    <a href="?page=test" class="<?= $page === 'test' ? 'on' : '' ?>">料金テスト</a>
    <a href="../" target="_blank">シミュレーター表示</a>
    <?php if (app_config()['admin_password'] !== ''): ?><a href="?page=logout" class="right">ログアウト</a><?php endif ?>
</nav>
<?php endif ?>
<main>
<?php if ($flash): ?><p class="flash"><?= h($flash) ?></p><?php endif ?>
<?php $body() ?>
</main>
</body>
</html>
    <?php
}

if ($page === 'detail') {
    $app = get_application((string)($_GET['id'] ?? ''));
    if (!$app) {
        redirect('page=applications', '申込が見つかりません。');
    }
    layout('申込 ' . $app['id'], function () use ($app) {
        $q = $app['quote']; ?>
        <p><a href="?page=applications">← 申込一覧へ</a></p>
        <div class="card">
            <h1>申込 <?= h($app['id']) ?> <span class="status s-<?= h($app['status']) ?>"><?= h($app['status']) ?></span></h1>
            <table class="kv">
                <tr><th>受付日時</th><td><?= h($app['created_at']) ?></td></tr>
                <tr><th>ご利用日時</th><td><?= h(format_ride_datetime($app)) ?></td></tr>
                <tr><th>お迎え先</th><td><?= h($app['origin']) ?></td></tr>
                <tr><th>行き先</th><td><?= h($app['destination']) ?></td></tr>
                <tr><th>経路</th><td>片道 約<?= h($q['distance_km']) ?>km / 約<?= h($q['duration_min']) ?>分 <?= $q['round_trip'] ? '（往復）' : '（片道）' ?>
                    <a href="<?= h($q['map_url']) ?>" target="_blank" rel="noopener">地図</a></td></tr>
                <tr><th>料金内訳</th><td>
                    <?php foreach ($q['items'] as $it): ?><?= h($it['label']) ?>：<?= number_format($it['amount']) ?>円<br><?php endforeach ?>
                    <strong>概算合計：<?= number_format($q['total']) ?>円</strong></td></tr>
                <tr><th>お名前</th><td><?= h($app['name']) ?></td></tr>
                <tr><th>電話番号</th><td><a href="tel:<?= h(preg_replace('/[^\d+]/', '', $app['phone'])) ?>"><?= h($app['phone']) ?></a></td></tr>
                <tr><th>ご利用者の状態</th><td><?= h($app['condition']) ?></td></tr>
                <tr><th>同乗者</th><td><?= h($app['companions']) ?>名</td></tr>
                <tr><th>お支払方法</th><td><?= h($app['payment']) ?></td></tr>
                <tr><th>備考</th><td><?= nl2br(h($app['note'])) ?></td></tr>
            </table>
        </div>
        <form method="post" class="card">
            <?= csrf_field() ?>
            <h2>対応状況</h2>
            <label>ステータス
                <select name="status">
                    <?php foreach (APPLICATION_STATUSES as $st): ?>
                        <option <?= $st === $app['status'] ? 'selected' : '' ?>><?= h($st) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
            <label>メモ（社内用。確定金額・Square決済リンク送付済みなど）
                <textarea name="memo" rows="4"><?= h($app['memo']) ?></textarea>
            </label>
            <button>保存</button>
        </form>
        <div class="card">
            <h2>お客様が送るLINEメッセージ</h2>
            <p class="hint">LINE公式アカウントのチャットに、下の内容がお客様から届きます。申込番号で照合してください（お客様が文面を書き換えても、こちらの料金はサーバーで計算した値です）。</p>
            <pre><?= h(build_line_message($app)) ?></pre>
        </div>
    <?php });
    exit;
}

if ($page === 'settings') {
    layout('料金・設定', function () use ($settings, $error) {
        $s = $settings;
        $tableText = is_array($s['fare_table'])
            ? implode("\n", array_map(fn($r) => $r['up_to_km'] . ',' . $r['fare'], $s['fare_table']))
            : $s['fare_table'];
        $optText = is_array($s['options'])
            ? implode("\n", array_map(fn($r) => $r['name'] . ',' . $r['price'], $s['options']))
            : $s['options'];
        $lines = fn($v) => is_array($v) ? implode("\n", $v) : $v;
        ?>
        <form method="post">
            <?= csrf_field() ?>
            <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif ?>

            <div class="card">
                <h2>運賃（距離）</h2>
                <label class="inline"><input type="radio" name="fare_mode" value="meter" <?= $s['fare_mode'] !== 'table' ? 'checked' : '' ?>> 初乗り＋加算方式（タクシーメーター型）</label>
                <label class="inline"><input type="radio" name="fare_mode" value="table" <?= $s['fare_mode'] === 'table' ? 'checked' : '' ?>> 距離帯ごとの固定料金</label>

                <fieldset>
                    <legend>初乗り＋加算方式</legend>
                    <div class="grid">
                        <label>初乗り距離 (km)<input type="text" inputmode="decimal" name="base_km" value="<?= h($s['base_km']) ?>"></label>
                        <label>初乗り運賃 (円)<input type="text" inputmode="numeric" name="base_fare" value="<?= h($s['base_fare']) ?>"></label>
                        <label>加算距離 (km)<input type="text" inputmode="decimal" name="add_km" value="<?= h($s['add_km']) ?>"></label>
                        <label>加算運賃 (円)<input type="text" inputmode="numeric" name="add_fare" value="<?= h($s['add_fare']) ?>"></label>
                    </div>
                    <p class="hint">例: 初乗り 2km 800円、以降 0.4km ごとに 100円 → 3.0km なら 800 + 100×3 = 1,100円</p>
                </fieldset>

                <fieldset>
                    <legend>距離帯ごとの固定料金</legend>
                    <label>1行に「この距離(km)まで,料金(円)」
                        <textarea name="fare_table" rows="5" placeholder="5,2000&#10;10,3500&#10;20,6000"><?= h($tableText) ?></textarea>
                    </label>
                    <p class="hint">最後の行の距離を超えた場合は「要相談」と表示されます。</p>
                </fieldset>

                <div class="grid">
                    <label>迎車料金 (円・0で非表示)<input type="text" inputmode="numeric" name="pickup_fee" value="<?= h($s['pickup_fee']) ?>"></label>
                    <label>対応する上限距離 (片道km・0で無制限)<input type="text" inputmode="decimal" name="max_km" value="<?= h($s['max_km']) ?>"></label>
                    <label>端数処理の単位 (円)<input type="text" inputmode="numeric" name="rounding_unit" value="<?= h($s['rounding_unit']) ?>"></label>
                    <label>端数処理の方法
                        <select name="rounding_method">
                            <?php foreach (['ceil' => '切り上げ', 'round' => '四捨五入', 'floor' => '切り捨て'] as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $s['rounding_method'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach ?>
                        </select>
                    </label>
                </div>
                <label class="inline"><input type="checkbox" name="round_trip_enabled" value="1" <?= $s['round_trip_enabled'] ? 'checked' : '' ?>> 往復を選べるようにする（往復は片道運賃×2）</label>
                <label class="inline"><input type="checkbox" name="avoid_tolls" value="1" <?= $s['avoid_tolls'] ? 'checked' : '' ?>> 有料道路を使わないルートで距離を計算する</label>
            </div>

            <div class="card">
                <h2>オプション・選択肢</h2>
                <label>オプション（1行に「名前,料金(円)」。0円なら無料と表示）
                    <textarea name="options" rows="5"><?= h($optText) ?></textarea>
                </label>
                <div class="grid">
                    <label>ご利用者の状態（1行に1つ）
                        <textarea name="conditions" rows="5"><?= h($lines($s['conditions'])) ?></textarea>
                    </label>
                    <label>お支払方法（1行に1つ）
                        <textarea name="payment_methods" rows="5"><?= h($lines($s['payment_methods'])) ?></textarea>
                    </label>
                </div>
            </div>

            <div class="card">
                <h2>LINE・通知・表示</h2>
                <label>事業者名<input type="text" name="business_name" value="<?= h($s['business_name']) ?>"></label>
                <label>LINE公式アカウントのベーシックID（例: @123abcde）
                    <input type="text" name="line_id" value="<?= h($s['line_id']) ?>" placeholder="@123abcde">
                </label>
                <p class="hint">LINE Official Account Manager の「アカウント設定」に表示される @ から始まるIDです。空だとLINE送信ボタンが出ません。</p>
                <label>申込通知メールの送信先（空なら送らない）<input type="email" name="notify_email" value="<?= h($s['notify_email']) ?>"></label>
                <label>プライバシーポリシーのURL<input type="url" name="privacy_url" value="<?= h($s['privacy_url']) ?>"></label>
                <label>料金の下に表示する注意書き<textarea name="notice_text" rows="2"><?= h($s['notice_text']) ?></textarea></label>
            </div>

            <button class="big">設定を保存</button>
        </form>
    <?php });
    exit;
}

if ($page === 'test') {
    $result = null;
    $testError = '';
    $in = [
        'km' => $_GET['km'] ?? '',
        'origin' => $_GET['origin'] ?? '',
        'destination' => $_GET['destination'] ?? '',
        'round_trip' => !empty($_GET['round_trip']),
        'options' => (array)($_GET['options'] ?? []),
    ];
    try {
        if ($in['origin'] !== '' && $in['destination'] !== '') {
            $route = route_distance($in['origin'], $in['destination'], $settings);
            $result = calc_quote($settings, $route['meters'], $in['round_trip'], $in['options']);
        } elseif ($in['km'] !== '') {
            $result = calc_quote($settings, (int)round((float)$in['km'] * 1000), $in['round_trip'], $in['options']);
        }
    } catch (Throwable $e) {
        $testError = $e->getMessage();
    }
    layout('料金テスト', function () use ($settings, $in, $result, $testError) { ?>
        <form method="get" class="card">
            <input type="hidden" name="page" value="test">
            <h2>料金テスト</h2>
            <p class="hint">保存済みの料金設定で計算します。距離(km)を直接入れるとGoogle APIを使わずに確認できます。住所を入れた場合は実際に距離を取得します。</p>
            <div class="grid">
                <label>片道距離 (km)<input type="text" inputmode="decimal" name="km" value="<?= h($in['km']) ?>"></label>
            </div>
            <p class="hint">または住所で：</p>
            <div class="grid">
                <label>お迎え先<input type="text" name="origin" value="<?= h($in['origin']) ?>"></label>
                <label>行き先<input type="text" name="destination" value="<?= h($in['destination']) ?>"></label>
            </div>
            <label class="inline"><input type="checkbox" name="round_trip" value="1" <?= $in['round_trip'] ? 'checked' : '' ?>> 往復</label>
            <?php foreach ($settings['options'] as $opt): ?>
                <label class="inline"><input type="checkbox" name="options[]" value="<?= h($opt['name']) ?>" <?= in_array($opt['name'], $in['options'], true) ? 'checked' : '' ?>> <?= h($opt['name']) ?></label>
            <?php endforeach ?>
            <button>計算</button>
        </form>
        <?php if ($testError): ?><p class="error"><?= h($testError) ?></p><?php endif ?>
        <?php if ($result): ?>
            <div class="card">
                <?php if ($result['out_of_range']): ?>
                    <p class="error">片道 <?= h($result['distance_km']) ?>km：対応範囲外（要相談と表示されます）</p>
                <?php else: ?>
                    <table class="kv">
                        <?php foreach ($result['items'] as $it): ?>
                            <tr><th><?= h($it['label']) ?></th><td class="num"><?= number_format($it['amount']) ?>円</td></tr>
                        <?php endforeach ?>
                        <tr><th><strong>概算合計</strong></th><td class="num"><strong><?= number_format($result['total']) ?>円</strong></td></tr>
                    </table>
                <?php endif ?>
            </div>
        <?php endif ?>
    <?php });
    exit;
}

// 申込一覧
$filter = $_GET['status'] ?? '';
$apps = array_values(array_filter(list_applications(), fn($a) => $filter === '' || $a['status'] === $filter));
layout('申込一覧', function () use ($apps, $filter) { ?>
    <div class="card">
        <h1>申込一覧</h1>
        <p class="filters">
            <a href="?page=applications" class="<?= $filter === '' ? 'on' : '' ?>">すべて</a>
            <?php foreach (APPLICATION_STATUSES as $st): ?>
                <a href="?page=applications&status=<?= rawurlencode($st) ?>" class="<?= $filter === $st ? 'on' : '' ?>"><?= h($st) ?></a>
            <?php endforeach ?>
        </p>
        <?php if (!$apps): ?>
            <p>申込はまだありません。</p>
        <?php else: ?>
            <div class="scroll">
            <table class="list">
                <thead><tr><th>申込番号</th><th>状況</th><th>ご利用日時</th><th>お名前</th><th>区間</th><th class="num">概算</th><th>支払</th><th>受付</th></tr></thead>
                <tbody>
                <?php foreach ($apps as $a): ?>
                    <tr>
                        <td><a href="?page=detail&id=<?= rawurlencode($a['id']) ?>"><?= h($a['id']) ?></a></td>
                        <td><span class="status s-<?= h($a['status']) ?>"><?= h($a['status']) ?></span></td>
                        <td><?= h(format_ride_datetime($a)) ?></td>
                        <td><?= h($a['name']) ?></td>
                        <td class="route"><?= h(mb_strimwidth($a['origin'], 0, 24, '…')) ?> → <?= h(mb_strimwidth($a['destination'], 0, 24, '…')) ?><?= $a['quote']['round_trip'] ? '（往復）' : '' ?></td>
                        <td class="num"><?= number_format($a['quote']['total']) ?>円</td>
                        <td><?= h($a['payment']) ?></td>
                        <td><?= h(substr($a['created_at'], 5, 11)) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
            </div>
        <?php endif ?>
    </div>
<?php });
