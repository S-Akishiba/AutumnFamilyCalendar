<?php
declare(strict_types=1);

// 家族カレンダー：テレビ表示（時計・年月日と曜日・天気・今日/明日の予定・雨雲レーダー・2 週間のカレンダー）
// テレビでは tv.php?key=（config.local.php の FAMILY_TV_KEY）を開く

require_once __DIR__ . '/lib/family.php';

$key = (string)($_GET['key'] ?? '');
if (!fam_key_ok($key) && !fam_logged_in()) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="background:#000;color:#fff;font:4vw sans-serif;padding:5vw">'
        . 'テレビ用の鍵が違います。tv.php?key=… の URL を確認してください。</body>';
    exit;
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<script>if (location.protocol === 'http:' && location.hostname !== '127.0.0.1' && location.hostname !== 'localhost') location.replace('https:' + location.href.slice(5));</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>家族カレンダー</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="stylesheet" href="tv.css?v=2">
</head>
<body>
<div id="stage">
  <section class="left">
    <div class="date"><span id="ymd"></span> <span id="wday"></span></div>
    <div class="holiday" id="holiday"></div>
    <div class="clock"><span id="hm"></span><span class="sec" id="sec"></span></div>
    <div class="weather" id="weather"></div>
  </section>
  <section class="right">
    <div class="block"><h2>今日の予定</h2><div id="today" class="evlist"></div></div>
    <div class="block"><h2 id="tomorrowTitle">明日の予定</h2><div id="tomorrow" class="evlist"></div></div>
  </section>
  <section class="radar">
    <div id="map"></div>
    <div class="radarLabel"><span id="radarTime"></span><span class="credit">気象庁・国土地理院</span></div>
    <div class="radarBar" id="radarBar"></div>
  </section>
  <section class="weeks" id="weeks"></section>
  <div class="status" id="status"></div>
</div>
<script>window.FAM_KEY = <?= json_encode($key) ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="tv.js?v=2"></script>
</body>
</html>
