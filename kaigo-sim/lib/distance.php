<?php
declare(strict_types=1);

/**
 * 2地点間の車での走行距離を求める（Google Routes API）。
 * 戻り値: ['meters' => int, 'seconds' => int, 'map_url' => string]
 * 同じ経路は1日キャッシュし、API呼び出し回数（＝費用）を抑える。
 */
function route_distance(string $origin, string $destination, array $s): array
{
    $config = app_config();
    $avoidTolls = (bool)$s['avoid_tolls'];
    $mapUrl = 'https://www.google.com/maps/dir/?api=1&travelmode=driving'
        . '&origin=' . rawurlencode($origin) . '&destination=' . rawurlencode($destination);

    if ($config['mock_distance']) {
        // テストモード: 住所文字列から決まるダミー距離（2〜30km）
        $meters = 2000 + crc32($origin . '|' . $destination) % 28000;
        return ['meters' => $meters, 'seconds' => (int)($meters / 1000 * 150), 'map_url' => $mapUrl];
    }

    $key = $config['google_maps_api_key'];
    if ($key === '') {
        throw new RuntimeException('google_maps_api_key が未設定です');
    }

    $cacheFile = DATA_DIR . '/cache/' . hash('sha256', $origin . "\n" . $destination . "\n" . (int)$avoidTolls) . '.json';
    $cached = json_read($cacheFile);
    if ($cached && $cached['expires'] > time()) {
        return $cached['result'] + ['map_url' => $mapUrl];
    }

    $body = [
        'origin' => ['address' => $origin],
        'destination' => ['address' => $destination],
        'travelMode' => 'DRIVE',
        'routingPreference' => 'TRAFFIC_UNAWARE',
        'routeModifiers' => ['avoidTolls' => $avoidTolls],
        'languageCode' => 'ja',
        'regionCode' => 'JP',
        'units' => 'METRIC',
    ];
    $ch = curl_init('https://routes.googleapis.com/directions/v2:computeRoutes');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $key,
            'X-Goog-FieldMask: routes.distanceMeters,routes.duration',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Routes API 通信エラー: ' . $curlError);
    }
    $res = json_decode((string)$raw, true);
    if ($status === 400 || $status === 404) {
        // 住所が見つからない・経路がない場合はこちら
        error_log('Routes API ' . $status . ': ' . $raw);
        throw new UserError('住所から経路を見つけられませんでした。住所を詳しく入力してください。');
    }
    if ($status !== 200) {
        throw new RuntimeException('Routes API エラー ' . $status . ': ' . $raw);
    }
    $route = $res['routes'][0] ?? null;
    if (!$route || !isset($route['distanceMeters'])) {
        throw new UserError('車で移動できる経路が見つかりませんでした。住所をご確認ください。');
    }

    $result = [
        'meters' => (int)$route['distanceMeters'],
        'seconds' => (int)rtrim((string)($route['duration'] ?? '0s'), 's'),
    ];
    json_write($cacheFile, ['expires' => time() + 86400, 'result' => $result]);
    return $result + ['map_url' => $mapUrl];
}
