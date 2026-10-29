<?php
declare(strict_types=1);

// これを config.local.php という名前でコピーして値を入れる（config.local.php はコミットしない）

define('FAMILY_DB_DSN', 'mysql:dbname=backyard;host=127.0.0.1:3307;charset=utf8mb4');
define('FAMILY_DB_USER', 'webusr');
define('FAMILY_DB_PASSWORD', 'change-me');

// 家族共通のパスワード。次のコマンドで作った値を貼る:
//   php -r "echo password_hash('家族のパスワード', PASSWORD_DEFAULT), PHP_EOL;"
define('FAMILY_PASSWORD_HASH', '$2y$10$change-me');

// 長いランダムな文字列（php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"）
define('FAMILY_SECRET', 'change-me');

// テレビ用の鍵（php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"）
// テレビでは https://aitakt.gardenas.net/family/tv.php?key=この値 を開く
define('FAMILY_TV_KEY', 'change-me');

// 天気の地域（気象庁の府県予報区コード。岡山県=330000、大阪府=270000）
define('FAMILY_WEATHER_AREA', '330000');

// 雨雲レーダーの地図の中心（自宅のだいたいの緯度・経度。Google マップで地点を長押しすると出る）と縮尺（7〜10）
define('FAMILY_RADAR_LAT', 34.66);
define('FAMILY_RADAR_LON', 133.92);
define('FAMILY_RADAR_ZOOM', 8);
