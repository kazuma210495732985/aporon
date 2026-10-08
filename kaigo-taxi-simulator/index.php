<?php
// 動作確認用ページ。本番ではサイト側に埋め込みタグを貼って使います（README参照）。
require __DIR__ . '/lib/bootstrap.php';
$name = get_settings()['business_name'];
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($name, ENT_QUOTES) ?> 料金シミュレーター</title>
<style>body { margin: 0; padding: 24px 16px; background: #fafafa; font-family: system-ui, "Hiragino Sans", "Noto Sans JP", sans-serif; }</style>
</head>
<body>
<!-- ▼ ここからサイトに貼るタグ（src は設置先のURLに合わせる） -->
<div id="kaigo-taxi-sim"></div>
<script src="assets/widget.js" defer></script>
<!-- ▲ ここまで -->
</body>
</html>
