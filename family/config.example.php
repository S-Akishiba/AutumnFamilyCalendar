<?php
declare(strict_types=1);

// これを config.local.php という名前でコピーして値を入れる（config.local.php はコミットしない）

define('FAMILY_DB_DSN', 'mysql:dbname=backyard;host=127.0.0.1:3307;charset=utf8mb4');
define('FAMILY_DB_USER', 'webusr');
define('FAMILY_DB_PASSWORD', 'change-me');

// 家族共通のパスワード（そのままは書かない）。PowerShell で次を実行して出た値を貼る:
//   .\tools\make-keys.ps1
// PHP があれば php -r "echo password_hash('家族のパスワード', PASSWORD_DEFAULT), PHP_EOL;" の値でもよい
define('FAMILY_PASSWORD_HASH', 'sha256:change-me');

// 長いランダムな文字列（make-keys.ps1 が作る）
define('FAMILY_SECRET', 'change-me');

// テレビ用の鍵（make-keys.ps1 が作る）
// テレビでは https://aitakt.gardenas.net/family/tv.php?key=この値 を開く
define('FAMILY_TV_KEY', 'change-me');

// 天気の地域（気象庁の府県予報区コード。岡山県=330000、大阪府=270000）
define('FAMILY_WEATHER_AREA', '330000');

// 雨雲レーダーの地図の中心（自宅のだいたいの緯度・経度。Google マップで地点を長押しすると出る）と縮尺（7〜10）
define('FAMILY_RADAR_LAT', 34.66);
define('FAMILY_RADAR_LON', 133.92);
define('FAMILY_RADAR_ZOOM', 8);

// LINE（README の「LINE から登録」の手順で作る。使わないなら書かなくてよい）
define('FAMILY_LINE_CHANNEL_SECRET', '');    // Messaging API チャネル → チャネル基本設定 → チャネルシークレット
define('FAMILY_LINE_ACCESS_TOKEN', '');      // Messaging API チャネル → Messaging API 設定 → チャネルアクセストークン（長期）
define('FAMILY_LINE_LOGIN_CHANNEL_ID', '');  // LINE ログインチャネル → チャネル基本設定 → チャネル ID
define('FAMILY_LIFF_ID', '');                // LINE ログインチャネル → LIFF → LIFF ID（例 2001234567-AbCdEfGh）
