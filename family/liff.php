<?php
declare(strict_types=1);

// 家族カレンダー：LINE の中で開く入口（LIFF のエンドポイント URL に
// https://aitakt.gardenas.net/family/liff.php を入れる）。
// LINE のアカウントでログインして、いつもの登録画面（index.php）へ進む。最初の 1 回だけ家族のパスワードを聞く

require_once __DIR__ . '/lib/line.php';
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>家族カレンダー</title>
<link rel="stylesheet" href="app.css?v=3">
<style>
  .who { display: flex; flex-wrap: wrap; gap: 6px; }
  .who button { border-radius: 20px; padding: 8px 16px; border: 2px solid var(--c); color: var(--c); background: transparent; }
  .who button.on { background: var(--c); color: #fff; }
  #msg { color: var(--muted); }
</style>
</head>
<body>
<main class="center">
  <h1>家族カレンダー</h1>
  <p id="msg">LINE でログインしています…</p>
  <form id="link" class="card" hidden>
    <p class="hint">はじめて LINE から使うときだけ、家族のパスワードを入れてください。次からはそのまま開きます。</p>
    <label>家族のパスワード<input type="password" name="password" autocomplete="current-password" required></label>
    <fieldset>
      <legend>あなたはだれですか？（予定の「だれの」の最初の選択になります）</legend>
      <div class="who" id="who"></div>
    </fieldset>
    <button type="submit" class="primary">はじめる</button>
    <p class="err" id="err"></p>
  </form>
</main>
<script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
<script>
(() => {
  const LIFF_ID = <?= json_encode(FAMILY_LIFF_ID) ?>;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let idToken = '';

  const post = async (action, body) => {
    const res = await fetch(`api.php?action=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || '通信できませんでした');
    return json;
  };
  const done = (memberId) => {
    try {
      if (memberId) localStorage.setItem('fam_default_member', String(memberId)); else localStorage.removeItem('fam_default_member');
    } catch (e) { /* 保存できない環境 */ }
    location.replace('./');
  };

  async function start() {
    if (!LIFF_ID) { $('msg').textContent = 'LINE の設定（FAMILY_LIFF_ID）がまだです'; return; }
    await liff.init({ liffId: LIFF_ID });
    if (!liff.isLoggedIn()) { liff.login({ redirectUri: location.href }); return; }
    idToken = liff.getIDToken() || '';
    const r = await post('line_login', { id_token: idToken });
    if (r.ok) { done(r.member_id); return; }
    // はじめての LINE アカウント
    $('msg').textContent = r.name ? `${r.name} さん、ようこそ` : 'ようこそ';
    $('who').innerHTML = r.members.map((m) =>
      `<button type="button" data-id="${m.id}" style="--c:${esc(m.color)}">${esc(m.name)}</button>`).join('') +
      '<button type="button" data-id="0" style="--c:#888">メンバー以外</button>';
    $('link').hidden = false;
  }

  $('who').addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    [...$('who').children].forEach((x) => x.classList.toggle('on', x === b));
  });
  $('link').addEventListener('submit', async (e) => {
    e.preventDefault();
    const on = $('who').querySelector('button.on');
    try {
      const r = await post('line_link', { id_token: idToken, password: e.target.password.value, member_id: on ? Number(on.dataset.id) : 0 });
      done(r.member_id);
    } catch (err) {
      $('err').textContent = err.message;
    }
  });

  start().catch((err) => { $('msg').textContent = `開けませんでした：${err.message}`; });
})();
</script>
</body>
</html>
