<?php
declare(strict_types=1);

/** data/ 配下に JSON ファイルで保存する（DB不要） */

function json_read(string $path, array $default = []): array
{
    if (!is_file($path)) {
        return $default;
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

function json_write(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("ディレクトリを作成できません: $dir");
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    // 書き込み途中のファイルを読まれないよう、一時ファイルに書いてから置き換える
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException("書き込みに失敗しました: $path");
    }
    rename($tmp, $path);
}

/**
 * 簡易レート制限。許可なら true。
 * $key ごとに直近 $window 秒の回数を数える。
 */
function rate_limit(string $bucket, string $key, int $max, int $window = 3600): bool
{
    if ($max <= 0) {
        return true;
    }
    $path = DATA_DIR . '/ratelimit/' . $bucket . '_' . hash('sha256', $key) . '.json';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return true;
    }
    flock($fp, LOCK_EX);
    $hits = json_decode((string)stream_get_contents($fp), true);
    $now = time();
    $hits = array_values(array_filter(is_array($hits) ? $hits : [], fn($t) => is_int($t) && $t > $now - $window));
    $allowed = count($hits) < $max;
    if ($allowed) {
        $hits[] = $now;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($hits));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $allowed;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
