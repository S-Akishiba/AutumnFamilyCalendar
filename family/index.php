<?php
declare(strict_types=1);

// 家族カレンダー：iPhone から予定を登録・確認する画面（Safari の「ホーム画面に追加」で使う）

require_once __DIR__ . '/lib/family.php';

$configured = fam_is_configured();
$loggedIn = $configured && fam_logged_in();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<script>if (location.protocol === 'http:' && location.hostname !== '127.0.0.1' && location.hostname !== 'localhost') location.replace('https:' + location.href.slice(5));</script>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="家族カレンダー">
<meta name="theme-color" content="#ffffff">
<link rel="apple-touch-icon" href="icon-180.png">
<link rel="manifest" href="manifest.json">
<title>家族カレンダー</title>
<link rel="stylesheet" href="app.css?v=4">
</head>
<body>
<?php if (!$configured): ?>
  <main class="center">
    <h1>家族カレンダー</h1>
    <p>サーバーの設定がまだです。<code>family/config.example.php</code> を <code>config.local.php</code> にコピーして、パスワードと鍵を入れてください。</p>
  </main>
<?php elseif (!$loggedIn): ?>
  <main class="center">
    <h1>家族カレンダー</h1>
    <form id="login" class="card">
      <label>家族のパスワード<input type="password" name="password" autocomplete="current-password" required></label>
      <button type="submit" class="primary">ひらく</button>
      <p class="err" id="loginErr"></p>
    </form>
  </main>
  <script>
  document.getElementById('login').addEventListener('submit', async (e) => {
    e.preventDefault();
    const res = await fetch('api.php?action=login', {method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({password: e.target.password.value})});
    if (res.ok) { location.reload(); return; }
    document.getElementById('loginErr').textContent = (await res.json()).error || 'ひらけませんでした';
  });
  </script>
<?php else: ?>
  <header class="bar">
    <button id="prev" aria-label="前の月">‹</button>
    <h1 id="monthTitle"></h1>
    <button id="next" aria-label="次の月">›</button>
    <button id="todayBtn" class="small">今日</button>
    <button id="settingsBtn" class="small" aria-label="設定">⚙</button>
  </header>

  <main>
    <div id="grid" class="grid"></div>
    <section id="list" class="list"></section>
  </main>

  <button id="addBtn" class="fab" aria-label="予定を追加">＋</button>

  <dialog id="editor">
    <form id="eventForm" method="dialog">
      <h2 id="editorTitle">予定を追加</h2>
      <label>内容<input name="title" required maxlength="200" placeholder="例：歯医者"></label>
      <div class="row">
        <label>日付<input type="date" name="start_date" required></label>
        <label>最終日<input type="date" name="end_date"></label>
      </div>
      <label class="check"><input type="checkbox" name="all_day"> 終日</label>
      <div class="row times">
        <label>開始<input type="time" name="start_time" step="300"></label>
        <label>終了<input type="time" name="end_time" step="300"></label>
      </div>
      <fieldset>
        <legend>だれの予定？（選ばなければ家族全員）</legend>
        <div id="memberChips" class="chips"></div>
      </fieldset>
      <div class="row">
        <label>繰り返し
          <select name="repeat_rule">
            <?php foreach (FAM_REPEATS as $v => $label): ?>
              <option value="<?= h($v) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="until">いつまで<input type="date" name="repeat_until"></label>
      </div>
      <fieldset class="weeksField" hidden>
        <legend>第何週？（いくつでも）</legend>
        <div class="chips pick" id="weekChips">
          <?php for ($n = 1; $n <= 5; $n++): ?><button type="button" data-v="<?= $n ?>">第<?= $n ?></button><?php endfor; ?>
        </div>
      </fieldset>
      <fieldset class="daysField" hidden>
        <legend>何曜日？（いくつでも）</legend>
        <div class="chips pick" id="dayChips">
          <?php foreach (FAM_WEEKDAYS as $i => $w): ?><button type="button" data-v="<?= $i ?>"><?= h($w) ?></button><?php endforeach; ?>
        </div>
      </fieldset>
      <label class="check"><input type="checkbox" name="remind"> 前の日にテレビで知らせる（ゴミの日など）</label>
      <label class="check"><input type="checkbox" name="annual"> 年間行事予定にのせる（テレビの右の画面に出ます）</label>
      <label>メモ<textarea name="note" rows="2" maxlength="2000"></textarea></label>
      <p class="hint" id="repeatHint" hidden>繰り返しの予定は、変更・削除するとすべての回に反映されます。</p>
      <p class="err" id="formErr"></p>
      <div class="actions">
        <button type="button" id="deleteBtn" class="danger" hidden>削除</button>
        <span class="spacer"></span>
        <button type="button" id="cancelBtn">やめる</button>
        <button type="submit" class="primary">保存</button>
      </div>
    </form>
  </dialog>

  <dialog id="settings">
    <form id="settingsForm" method="dialog">
      <h2>家族のメンバー</h2>
      <div id="memberRows"></div>
      <button type="button" id="addMember">＋ メンバーを追加</button>
      <h2 class="sub">テレビをつなぐ</h2>
      <p class="hint">テレビの家族カレンダーに出ている 6 桁の番号を入れてください。</p>
      <div class="pairRow">
        <input id="pairCode" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="123456" autocomplete="off">
        <button type="button" id="pairBtn">つなぐ</button>
      </div>
      <p class="hint" id="pairMsg"></p>
      <p class="err" id="settingsErr"></p>
      <div class="actions">
        <button type="button" id="logoutBtn" class="danger">ログアウト</button>
        <span class="spacer"></span>
        <button type="button" id="settingsCancel">やめる</button>
        <button type="submit" class="primary">保存</button>
      </div>
    </form>
  </dialog>

  <script>window.FAM_COLORS = <?= json_encode(FAM_COLORS) ?>;</script>
  <script src="app.js?v=5"></script>
<?php endif; ?>
</body>
</html>
