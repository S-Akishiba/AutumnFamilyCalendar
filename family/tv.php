<?php
declare(strict_types=1);

// 家族カレンダー：テレビ表示（時計・年月日と曜日・天気・今日/明日の予定・雨雲レーダー・2 週間のカレンダー）
// テレビでは tv.php?key=（config.local.php の FAMILY_TV_KEY）を開く。
// 鍵なしで開くと 6 桁の番号を出し、iPhone の ⚙ でその番号を入れるとつながる（鍵はテレビのブラウザに保存）

require_once __DIR__ . '/lib/family.php';

$key = (string)($_GET['key'] ?? '');
if (!fam_key_ok($key) && !fam_logged_in()) {
    $hadKey = $key !== '';
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<script>if (location.protocol === 'http:' && location.hostname !== '127.0.0.1' && location.hostname !== 'localhost') location.replace('https:' + location.href.slice(5));</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>家族カレンダー</title>
<style>
  html, body { margin: 0; height: 100%; background: #0d1117; color: #f0f3f6; font-family: "Noto Sans JP", sans-serif; cursor: none; }
  main { height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 3vh; }
  h1 { font-size: 3.4vw; margin: 0; }
  p { font-size: 2vw; color: #8b949e; margin: 0; line-height: 1.6; }
  #code { font-size: 11vw; font-weight: 700; letter-spacing: .15em; color: #f2cc60; font-variant-numeric: tabular-nums; min-height: 1.2em; }
  #msg { color: #ff7b72; min-height: 1.2em; }
</style>
</head>
<body>
<main>
  <h1>このテレビを家族カレンダーにつなぎます</h1>
  <div id="code">・・・・・・</div>
  <p>iPhone で家族カレンダーを開き、右上の ⚙ →「テレビをつなぐ」に<br>この 6 桁の番号を入れてください</p>
  <p id="msg"></p>
</main>
<script>
(() => {
  const STORE = 'fam_tv_key';
  const hadKey = <?= json_encode($hadKey) ?>;
  const go = (k) => location.replace(`tv.php?key=${encodeURIComponent(k)}`);
  try {
    const saved = localStorage.getItem(STORE);
    // 保存した鍵が使えなくなった（鍵を変えた）ときは消して、つなぎ直す
    if (saved && !hadKey) { go(saved); return; }
    if (hadKey) localStorage.removeItem(STORE);
  } catch (e) { /* 保存できない環境 */ }

  const post = (action, body) => fetch(`api.php?action=${action}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}),
  }).then((r) => r.json());

  let pair = null;
  async function start() {
    try {
      pair = await post('pair_start');
      if (!pair.code) throw new Error(pair.error);
      document.getElementById('code').textContent = pair.code;
      document.getElementById('msg').textContent = '';
      setTimeout(start, (pair.expires_in - 30) * 1000); // 期限が来る前に番号を作り直す
    } catch (e) {
      document.getElementById('msg').textContent = '番号を作れませんでした。しばらくして自動でやり直します';
      setTimeout(start, 30000);
    }
  }
  async function poll() {
    if (pair && pair.code) {
      try {
        const r = await post('pair_poll', { code: pair.code, token: pair.token });
        if (r.status === 'ok') {
          try { localStorage.setItem(STORE, r.key); } catch (e) { /* 次回もつなぎ直しになる */ }
          go(r.key);
          return;
        }
      } catch (e) { /* 通信が戻るまで待つ */ }
    }
    setTimeout(poll, 3000);
  }
  start();
  poll();
})();
</script>
</body>
</html>
    <?php
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
<script>
window.FAM_KEY = <?= json_encode($key) ?>;
// 鍵付きで開いたら覚えておく（アプリやブックマークは鍵なしの tv.php を開けばよい）
try { if (window.FAM_KEY) localStorage.setItem('fam_tv_key', window.FAM_KEY); } catch (e) { /* 保存できない環境 */ }
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="tv.js?v=2"></script>
</body>
</html>
