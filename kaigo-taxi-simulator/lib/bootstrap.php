<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');

/** 利用者向けに表示してよいエラー */
class UserError extends RuntimeException {}

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        // config.php が無くても動く（Google のキーが無いのでテストモードになる）
        $file = APP_ROOT . '/config.php';
        $config = array_merge([
            'google_maps_api_key' => '',
            'mock_distance' => true,
            'admin_password' => '',
            'allowed_origins' => [],
            'quote_limit_per_hour' => 40,
            'apply_limit_per_hour' => 5,
            'mail_from' => '',
        ], is_file($file) ? require $file : []);
    }
    return $config;
}

require __DIR__ . '/storage.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/fare.php';
require __DIR__ . '/distance.php';
require __DIR__ . '/applications.php';
