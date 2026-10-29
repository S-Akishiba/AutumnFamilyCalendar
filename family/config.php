<?php
declare(strict_types=1);

// 家族カレンダー（https://aitakt.gardenas.net/family/）の設定。
// パスワードなどの値は同じフォルダの config.local.php（コミットしない）に書く。
// 書き方は config.example.php を参照。

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

function fam_config(string $name, mixed $default = null): mixed
{
    if (defined($name)) {
        return constant($name);
    }
    $value = getenv($name);
    return $value === false ? $default : $value;
}

// DB（既定は BackYard と同じ MySQL。テーブルは fam_ で始まる名前で作る）
// FAMILY_DB_DSN に 'sqlite:/path/to/family.db' を書くと SQLite で動く（手元での確認用）
defined('FAMILY_DB_DSN') || define('FAMILY_DB_DSN', fam_config('FAMILY_DB_DSN', 'mysql:dbname=backyard;host=127.0.0.1:3307;charset=utf8mb4'));
defined('FAMILY_DB_USER') || define('FAMILY_DB_USER', fam_config('FAMILY_DB_USER', 'webusr'));
defined('FAMILY_DB_PASSWORD') || define('FAMILY_DB_PASSWORD', fam_config('FAMILY_DB_PASSWORD', ''));

// 家族共通のパスワード（password_hash() の値）と、ログイン状態の署名に使う秘密の文字列
defined('FAMILY_PASSWORD_HASH') || define('FAMILY_PASSWORD_HASH', fam_config('FAMILY_PASSWORD_HASH', ''));
defined('FAMILY_SECRET') || define('FAMILY_SECRET', fam_config('FAMILY_SECRET', ''));
// テレビ・iPhone のカレンダー購読用の鍵（URL に付ける。?key=...）
defined('FAMILY_TV_KEY') || define('FAMILY_TV_KEY', fam_config('FAMILY_TV_KEY', ''));

// 天気（気象庁の予報区コード。岡山県南部=330000。表示しないなら空に）
defined('FAMILY_WEATHER_AREA') || define('FAMILY_WEATHER_AREA', fam_config('FAMILY_WEATHER_AREA', '330000'));

// 雨雲レーダー（気象庁の降水ナウキャスト）の地図の中心（自宅のだいたいの位置）と縮尺（7＝広い〜10＝狭い）
defined('FAMILY_RADAR_LAT') || define('FAMILY_RADAR_LAT', (float)fam_config('FAMILY_RADAR_LAT', 34.66));
defined('FAMILY_RADAR_LON') || define('FAMILY_RADAR_LON', (float)fam_config('FAMILY_RADAR_LON', 133.92));
defined('FAMILY_RADAR_ZOOM') || define('FAMILY_RADAR_ZOOM', (int)fam_config('FAMILY_RADAR_ZOOM', 8));
