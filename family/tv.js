// 家族カレンダー：テレビ表示。時計は毎秒、予定（14 日分）は 1 分ごと、天気は 30 分ごと、雨雲レーダーは 5 分ごと、ニュースは 10 分ごとに更新する。
// 色は時間帯で切り替える（朝＝空色・昼＝白・夕方〜夜＝生成り）
(() => {
  'use strict';
  const WD = ['日', '月', '火', '水', '木', '金', '土'];
  const $ = (id) => document.getElementById(id);
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const key = window.FAM_KEY ? `&key=${encodeURIComponent(window.FAM_KEY)}` : '';

  let data = { members: [], events: [], holidays: {} };
  let weather = null;
  let lastDay = '';
  const bootedAt = Date.now();

  async function get(action, params = '') {
    const res = await fetch(`api.php?action=${action}${params}${key}`, { cache: 'no-store' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  }

  const DAYS = 14;
  const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
  const startOfToday = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d; };

  // 色の時間帯（URL に ?theme=warm などを付けるとその色に固定）
  const fixedTheme = new URLSearchParams(location.search).get('theme');
  function themeFor(h) {
    if (h >= 5 && h < 10) return 'sky';
    if (h >= 10 && h < 17) return 'white';
    return 'warm';
  }

  // 今月のはじめから 12 か月分（真ん中の 14 日・左の 3 か月・右の年間行事を 1 回で読む）
  async function loadCalendar() {
    const t = startOfToday();
    const s = new Date(t.getFullYear(), t.getMonth(), 1);
    const e = new Date(t.getFullYear(), t.getMonth() + 12, 0);
    try {
      data = await get('calendar', `&from=${ymd(s)}&to=${ymd(e)}`);
      $('status').textContent = '';
    } catch (err) {
      $('status').textContent = '● 接続を待っています（表示は前回のまま）';
    }
    renderEvents();
  }

  async function loadWeather() {
    try {
      weather = (await get('weather')).weather;
    } catch (err) { /* 前回の天気のまま */ }
    renderWeather();
  }

  // ── 時計 ──
  // 左の欄に収まる大きさにする（文字の形はテレビによって違うので、実際に測って縮める）
  const CLOCK_MAX_VW = 15.5;
  function fitClock() {
    const el = document.querySelector('.clock');
    let vw = CLOCK_MAX_VW;
    el.style.fontSize = `${vw}vw`;
    // 時計の箱は左の欄いっぱいの幅。中身（数字）がその幅を超えたら縮める
    while (vw > 6 && el.scrollWidth > el.clientWidth + 1) {
      vw -= 0.25;
      el.style.fontSize = `${vw}vw`;
    }
  }
  let lastHm = '';
  function tick() {
    const now = new Date();
    const hm = `${now.getHours()}:${pad(now.getMinutes())}`;
    $('hm').textContent = hm;
    if (hm.length !== lastHm.length) { lastHm = hm; fitClock(); } // 桁が変わったら測り直す
    $('sec').textContent = pad(now.getSeconds());
    const day = ymd(now);
    if (day !== lastDay) {
      lastDay = day;
      const w = now.getDay();
      $('ymd').textContent = `${now.getFullYear()}年${now.getMonth() + 1}月${now.getDate()}日`;
      $('wday').textContent = `${WD[w]}曜日`;
      $('wday').className = `wd ${w === 0 ? 'sun' : w === 6 ? 'sat' : ''}`;
      loadCalendar();
      renderWeather();
    }
    // 時間帯で色を変える。夜（23 時〜5 時）は暗く
    const h = now.getHours();
    const theme = fixedTheme || themeFor(h);
    if (document.documentElement.dataset.theme !== theme) document.documentElement.dataset.theme = theme;
    document.body.classList.toggle('night', !fixedTheme && (h >= 23 || h < 5));
    // 焼き付き防止：5 分ごとに少しずらす
    if (now.getSeconds() === 0 && now.getMinutes() % 5 === 0) {
      const x = Math.round((Math.random() - 0.5) * 12);
      const y = Math.round((Math.random() - 0.5) * 12);
      $('stage').style.transform = `translate(${x}px, ${y}px)`;
    }
    // 毎日 3 時台に 1 回読み直す（サーバーの更新を取り込む・長時間動かしたときのため）
    if (h === 3 && now.getMinutes() === 0 && now.getSeconds() === 0 && Date.now() - bootedAt > 3600e3) {
      location.reload();
    }
  }

  // ── 予定 ──
  const memberById = (id) => data.members.find((m) => Number(m.id) === id);
  const membersOf = (ev) => ev.member_ids.map(memberById).filter(Boolean);
  function whoTags(ev) {
    const ms = membersOf(ev);
    if (!ms.length) return '<span class="tag all">家族</span>';
    return ms.map((m) => `<span class="tag" style="background:${esc(m.color)}">${esc(m.name)}</span>`).join('');
  }
  function whoDots(ev) {
    const ms = membersOf(ev);
    return (ms.length ? ms.map((m) => m.color) : ['var(--all)']).map((c) => `<i style="background:${esc(c)}"></i>`).join('');
  }
  // 1 人の予定はその人の色、ほかは「家族」の色
  const colorOf = (ev) => {
    const ms = membersOf(ev);
    return ms.length === 1 ? ms[0].color : 'var(--all)';
  };
  const eventsOn = (day) => data.events.filter((ev) => ev.date <= day && ev.end_date >= day);

  function timeLabel(ev, day, short) {
    if (ev.date !== ev.end_date && day !== ev.date) return short ? '〃' : '（続き）';
    if (!ev.start_time) return '終日';
    return ev.end_time && !short ? `${ev.start_time}<small>–${ev.end_time}</small>` : ev.start_time;
  }

  function dayClass(d, k) {
    const cls = ['day'];
    if (d.getDay() === 0) cls.push('sun');
    if (d.getDay() === 6) cls.push('sat');
    if (data.holidays[k]) cls.push('hol');
    return cls;
  }

  // 「前の日にテレビで知らせる」の予定：夕方 15 時からは明日の分、朝 10 時までは今日の分を目立たせる
  function noticeHtml() {
    const now = new Date();
    const h = now.getHours();
    let label = '';
    let day = null;
    if (h >= 15) { label = '明日は'; day = addDays(startOfToday(), 1); }
    else if (h < 10) { label = '今日は'; day = startOfToday(); }
    if (!day) return '';
    const evs = eventsOn(ymd(day)).filter((ev) => ev.remind);
    if (!evs.length) return '';
    const isGarbage = evs.every((ev) => /ゴミ|ごみ|資源|プラ|ペットボトル|びん|缶/.test(ev.title));
    return `<span class="notice">${isGarbage ? '🗑️' : '🔔'} ${label} ${evs.map((ev) => esc(ev.title)).join('・')}</span>`;
  }

  // 今日・明日（大きく、時刻と名札つき）
  function bigDay(el, d, rel, max) {
    const k = ymd(d);
    const cls = dayClass(d, k);
    if (rel === '今日') cls.push('today');
    el.className = cls.join(' ');
    const evs = eventsOn(k);
    const rows = evs.slice(0, max).map((ev) => `<div class="ev"><span class="t">${timeLabel(ev, k, false)}</span>
      <span class="ti">${esc(ev.title)}</span><span class="who">${whoTags(ev)}</span></div>`).join('');
    el.innerHTML = `<div class="d"><span class="rel">${rel}</span><span class="n">${d.getDate()}</span><span class="w">${WD[d.getDay()]}曜日</span></div>
      <div class="evs">${data.holidays[k] ? `<div class="hn">${esc(data.holidays[k])}</div>` : ''}${rows || '<div class="none">予定はありません</div>'}${
        evs.length > max ? `<div class="more">ほか ${evs.length - max} 件</div>` : ''}</div>`;
  }

  // 3 日目から先（小さく、色の点で誰の予定か）
  function smallDay(d) {
    const k = ymd(d);
    const evs = eventsOn(k);
    const max = data.holidays[k] ? 1 : 2;
    const rows = evs.slice(0, max).map((ev) => `<div class="ev"><span class="t">${timeLabel(ev, k, true)}</span>
      <span class="ti">${esc(ev.title)}</span><span class="dots">${whoDots(ev)}</span></div>`).join('');
    const month = d.getDate() === 1 ? `<span class="m">${d.getMonth() + 1}月</span>` : '';
    return `<div class="${dayClass(d, k).join(' ')}"><div class="d">${month}<span class="n">${d.getDate()}</span><span class="w">${WD[d.getDay()]}</span></div>
      <div class="evs">${data.holidays[k] ? `<div class="hn">${esc(data.holidays[k])}</div>` : ''}${rows || '<div class="none">—</div>'}${
        evs.length > max ? `<div class="more">ほか ${evs.length - max} 件</div>` : ''}</div></div>`;
  }

  function renderEvents() {
    const s = startOfToday();
    const hol = data.holidays[ymd(s)];
    $('holiday').innerHTML = (hol ? `<span>${esc(hol)}</span>` : '') + noticeHtml();
    bigDay($('day0'), s, '今日', 4);
    bigDay($('day1'), addDays(s, 1), '明日', 3);
    $('day0').dataset.day = ymd(s);
    $('day1').dataset.day = ymd(addDays(s, 1));
    let html = '';
    for (let i = 2; i < DAYS; i++) html += smallDay(addDays(s, i));
    $('later').innerHTML = html;
    [...$('later').children].forEach((el, i) => { el.dataset.day = ymd(addDays(s, i + 2)); });
    renderMainDetail();
    renderMonths();
    renderYear();
  }

  // ── 選んだ日・月の予定を、省略せずに出す ──
  const dateOf = (key) => new Date(`${key}T00:00:00`);
  function fullTime(ev, key) {
    if (ev.date !== ev.end_date) {
      const s = dateOf(ev.date);
      const e = dateOf(ev.end_date);
      return `${s.getMonth() + 1}/${s.getDate()}〜${e.getMonth() + 1}/${e.getDate()}`;
    }
    if (!ev.start_time) return '終日';
    return ev.end_time ? `${ev.start_time}〜${ev.end_time}` : ev.start_time;
  }
  function detailRows(evs, key) {
    return evs.map((ev) => `<div class="dr"><div class="dt">${fullTime(ev, key)}</div>
      <div class="db"><div class="dti">${esc(ev.title)}</div><div class="dwho">${whoTags(ev)}</div>
      ${ev.note ? `<div class="dnote">${esc(ev.note)}</div>` : ''}</div></div>`).join('');
  }
  function dayDetailHtml(key) {
    const d = dateOf(key);
    const w = d.getDay();
    const evs = eventsOn(key);
    return `<h3><span class="${w === 0 || data.holidays[key] ? 'sun' : w === 6 ? 'sat' : ''}">${d.getMonth() + 1}月${d.getDate()}日（${WD[w]}）</span>
      ${data.holidays[key] ? `<small class="sun">${esc(data.holidays[key])}</small>` : ''}</h3>
      <div class="dlist">${detailRows(evs, key) || '<div class="none">予定はありません</div>'}</div>`;
  }

  // ── 選んでいる日・月（リモコンの ↑↓←→ で動かす）──
  let mainSel = null;      // 真ん中：14 日のうち何日目（null＝選んでいない）
  let monthSel = null;     // 3 か月：選んでいる日 'YYYY-MM-DD'
  let yearSel = 0;         // 年間行事：今月から何か月目

  function renderMainDetail() {
    const el = $('mainDetail');
    document.querySelectorAll('.view.main .sel').forEach((x) => x.classList.remove('sel'));
    if (mainSel === null) { el.hidden = true; return; }
    const key = ymd(addDays(startOfToday(), mainSel));
    const box = document.querySelector(`.view.main [data-day="${key}"]`);
    if (box) box.classList.add('sel');
    el.innerHTML = dayDetailHtml(key) + '<div class="dhint">↑↓ 日を選ぶ ・ 戻る で閉じる</div>';
    el.hidden = false;
  }

  // ── 左の画面：今月から 3 か月分のカレンダー＋選んだ日の予定 ──
  const monthsRange = () => {
    const t = startOfToday();
    return [ymd(new Date(t.getFullYear(), t.getMonth(), 1)), ymd(new Date(t.getFullYear(), t.getMonth() + 3, 0))];
  };
  function renderMonths() {
    const today = ymd(new Date());
    const t = startOfToday();
    if (!monthSel) monthSel = today;
    let html = '';
    for (let k = 0; k < 3; k++) {
      const first = new Date(t.getFullYear(), t.getMonth() + k, 1);
      const days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
      const weeks = Math.ceil((first.getDay() + days) / 7);
      let cells = '';
      for (let i = 0; i < weeks * 7; i++) {
        const d = new Date(first.getFullYear(), first.getMonth(), 1 - first.getDay() + i);
        const key = ymd(d);
        const cls = ['c'];
        if (d.getMonth() !== first.getMonth()) { cells += '<div class="c out"></div>'; continue; }
        if (d.getDay() === 0) cls.push('sun');
        if (d.getDay() === 6) cls.push('sat');
        if (data.holidays[key]) cls.push('hol');
        if (key === today) cls.push('today');
        if (key < today) cls.push('past');
        if (key === monthSel) cls.push('sel');
        const evs = eventsOn(key);
        const max = data.holidays[key] ? 3 : 4;
        const items = evs.slice(0, max).map((ev) => `<div class="e" style="border-color:${esc(colorOf(ev))}">${esc(ev.title)}</div>`).join('');
        const more = evs.length > max ? `<div class="more">ほか${evs.length - max}件</div>` : '';
        cells += `<div class="${cls.join(' ')}"><div class="n">${d.getDate()}</div>${data.holidays[key] ? `<div class="hn">${esc(data.holidays[key])}</div>` : ''}${items}${more}</div>`;
      }
      html += `<div class="mcal"><h2>${first.getMonth() + 1}月<small>${first.getFullYear()}年</small></h2>
        <div class="wds">${WD.map((w) => `<span>${w}</span>`).join('')}</div>
        <div class="cells" style="grid-template-rows:repeat(${weeks},minmax(0,1fr))">${cells}</div></div>`;
    }
    html += `<aside class="detail">${dayDetailHtml(monthSel)}<div class="dhint">↑↓←→ 日を選ぶ ・ 戻る で今日の画面へ</div></aside>`;
    $('viewMonths').innerHTML = html;
  }

  // ── 右の画面：年間行事予定（今月から 12 か月。「年間行事予定にのせる」にした予定と祝日）＋選んだ月の一覧 ──
  const YEAR_ROWS = 5;
  function yearRows(k) {
    const today = ymd(new Date());
    const t = startOfToday();
    const first = new Date(t.getFullYear(), t.getMonth() + k, 1);
    const from = ymd(first);
    const to = ymd(new Date(first.getFullYear(), first.getMonth() + 1, 0));
    const rows = [];
    for (const ev of data.events.filter((x) => x.annual)) {
      if (ev.date > to || ev.end_date < from) continue;
      const d = dateOf(ev.date < from ? from : ev.date);
      const end = ev.end_date !== ev.date ? `〜${Number(ev.end_date.slice(8))}` : '';
      rows.push({ key: ev.date < from ? from : ev.date, hol: false, past: ev.end_date < today,
        d: `${d.getDate()}(${WD[d.getDay()]})${end}`, t: ev.title, ev });
    }
    for (const [key, name] of Object.entries(data.holidays)) {
      if (key < from || key > to) continue;
      const d = dateOf(key);
      rows.push({ key, hol: true, past: key < today, d: `${d.getDate()}(${WD[d.getDay()]})`, t: name });
    }
    rows.sort((a, b) => (a.key < b.key ? -1 : a.key > b.key ? 1 : a.hol - b.hol));
    return { first, rows };
  }
  function renderYear() {
    let html = '';
    for (let k = 0; k < 12; k++) {
      const { first, rows } = yearRows(k);
      const shown = rows.slice();
      // 入りきらないときは祝日から減らす
      while (shown.length > YEAR_ROWS && shown.some((r) => r.hol)) shown.splice(shown.map((r) => r.hol).lastIndexOf(true), 1);
      const body = shown.slice(0, YEAR_ROWS).map((r) => `<div class="r ${r.hol ? 'hol' : ''} ${r.past ? 'past' : ''}"><span class="d">${r.d}</span>
        <span class="t">${esc(r.t)}</span>${r.ev ? `<span class="w">${whoDots(r.ev)}</span>` : ''}</div>`).join('');
      const more = rows.length > YEAR_ROWS ? `<div class="more">ほか ${rows.length - Math.min(YEAR_ROWS, shown.length)} 件</div>` : '';
      html += `<div class="ybox ${k === 0 ? 'now' : ''} ${k === yearSel ? 'sel' : ''}"><h3>${first.getMonth() + 1}月${first.getMonth() === 0 || k === 0 ? `<small>${first.getFullYear()}年</small>` : ''}</h3>
        ${body || '<div class="none">—</div>'}${more}</div>`;
    }
    // 選んだ月：行事と祝日を全部
    const { first, rows } = yearRows(yearSel);
    const list = rows.map((r) => `<div class="dr ${r.hol ? 'hol' : ''} ${r.past ? 'past' : ''}"><div class="dt">${r.d}</div>
      <div class="db"><div class="dti">${esc(r.t)}</div>${r.ev ? `<div class="dwho">${whoTags(r.ev)}</div>` : ''}
      ${r.ev && r.ev.note ? `<div class="dnote">${esc(r.ev.note)}</div>` : ''}</div></div>`).join('');
    html = `<aside class="detail"><h3>${first.getFullYear()}年${first.getMonth() + 1}月の行事</h3>
      <div class="dlist">${list || '<div class="none">行事はありません</div>'}</div>
      <div class="dhint">↑↓←→ 月を選ぶ ・ 戻る で今日の画面へ</div></aside>` + html;
    $('viewYear').innerHTML = html;
  }

  // ── リモコン：真ん中で ← → は画面の切り替え、↑↓ で日を選ぶ。左右の画面では ↑↓←→ で日・月を選び、戻るで真ん中へ。
  //    2 分さわらなければ真ん中に戻る ──
  const PAGES = ['months', 'main', 'year'];
  const fixedView = new URLSearchParams(location.search).get('view');
  let page = Math.max(0, PAGES.indexOf(fixedView || 'main'));
  let idleTimer = null;
  function resetIdle() {
    clearTimeout(idleTimer);
    if (fixedView) return;
    idleTimer = setTimeout(() => { mainSel = null; renderMainDetail(); setPage(1); }, 120e3);
  }
  function setPage(i) {
    page = Math.max(0, Math.min(PAGES.length - 1, i));
    $('views').style.setProperty('--cur', page);
    [...$('pager').children].forEach((el, j) => el.classList.toggle('on', j === page));
    if (page === 0) { monthSel = ymd(new Date()); renderMonths(); }
    if (page === 2) { yearSel = 0; renderYear(); }
    if (page !== 1) { mainSel = null; renderMainDetail(); }
    if (page === 1 && map) setTimeout(() => map.invalidateSize(), 650);
    resetIdle();
  }

  /** リモコンのボタン（left / right / up / down / center / back）。処理したら true（アプリはそのボタンを使わない） */
  function famKey(k) {
    resetIdle();
    if (k === 'back') {
      if (page !== 1) { setPage(1); return true; }
      if (mainSel !== null) { mainSel = null; renderMainDetail(); return true; }
      return false;
    }
    if (page === 1) {
      if (k === 'left' || k === 'right') { setPage(page + (k === 'left' ? -1 : 1)); return true; }
      if (k === 'down') { mainSel = mainSel === null ? 0 : Math.min(DAYS - 1, mainSel + 1); renderMainDetail(); return true; }
      if (k === 'up') { if (mainSel !== null) { mainSel = mainSel === 0 ? null : mainSel - 1; renderMainDetail(); } return true; }
      if (k === 'center') {
        if (mainSel !== null) { mainSel = null; renderMainDetail(); return true; }
        return false; // アプリが読み直す
      }
      return false;
    }
    if (page === 0) {
      const step = { left: -1, right: 1, up: -7, down: 7 }[k];
      if (step) {
        const [lo, hi] = monthsRange();
        const next = ymd(addDays(dateOf(monthSel), step));
        if (next >= lo && next <= hi) { monthSel = next; renderMonths(); }
      }
      return true;
    }
    const step = { left: -1, right: 1, up: -3, down: 3 }[k];
    if (step) {
      const next = yearSel + step;
      if (next >= 0 && next < 12) { yearSel = next; renderYear(); }
    }
    return true;
  }
  window.famKey = famKey;
  // アプリ 1.2 は ← → だけをここに渡す（画面の切り替えだけにする）
  window.famNav = (dir) => setPage(page + dir);
  // パソコンのブラウザで確かめるとき用（矢印・Enter・Esc）
  document.addEventListener('keydown', (e) => {
    const k = { ArrowLeft: 'left', ArrowRight: 'right', ArrowUp: 'up', ArrowDown: 'down', Enter: 'center', Escape: 'back' }[e.key];
    if (k && famKey(k)) e.preventDefault();
  });

  // ── 天気（気象庁の天気コード：1xx 晴・2xx 曇・3xx 雨・4xx 雪）──
  function icon(code) {
    const c = String(code || '');
    if (c === '100') return '☀️';
    if (c[0] === '1') return '🌤️';
    if (c === '200') return '☁️';
    if (c[0] === '2') return Number(c) >= 202 && Number(c) <= 209 ? '🌦️' : '⛅';
    if (c[0] === '3') return '☔';
    if (c[0] === '4') return '⛄';
    return '';
  }
  function renderWeather() {
    if (!weather || !weather.days) { $('weather').innerHTML = ''; return; }
    const today = ymd(new Date());
    const days = weather.days.filter((d) => d.date >= today).slice(0, 2);
    $('weather').innerHTML = days.map((d, i) => `<div class="w">
        <span class="icon">${icon(d.code)}</span>
        <span><span class="lbl">${i === 0 ? '今日' : '明日'}</span><br>
        ${d.max !== null ? `<span class="max">${d.max}°</span>` : ''}${d.min !== null ? ` / <span class="min">${d.min}°</span>` : ''}
        ${d.pop !== null ? ` <span class="pop">☂${d.pop}%</span>` : ''}</span></div>`).join('');
  }

  // ── ニュース（上の帯に右から左へ流す。新しい見出しは 1 周流れ終わってから入れ替える）──
  let newsPending = null;
  let newsShown = '';
  async function loadNews() {
    try {
      const n = (await get('news')).news;
      if (n && n.items && n.items.length) {
        newsPending = n;
        if (!newsShown) showNews();
      }
    } catch (err) { /* 前回のまま */ }
  }
  function showNews() {
    if (!newsPending) return;
    const n = newsPending;
    newsPending = null;
    const html = n.items.map((it) => {
      const d = new Date(it.time * 1000);
      return `<span><b class="t">${d.getHours()}:${pad(d.getMinutes())}</b>${esc(it.title)}</span>`;
    }).join('');
    if (html === newsShown) return;
    newsShown = html;
    $('newsSrc').textContent = n.source || 'ニュース';
    const run = $('newsRun');
    run.innerHTML = html;
    // 帯の右端から出て、最後の見出しが左端に消えるまで。速さは画面の幅の 6% / 秒
    const trackW = run.parentElement.clientWidth;
    const runW = run.scrollWidth;
    run.style.setProperty('--from', `${trackW}px`);
    run.style.setProperty('--to', `${-runW}px`);
    run.style.animationDuration = `${Math.max(20, (trackW + runW) / (window.innerWidth * 0.06))}s`;
    run.style.animationName = 'none';
    void run.offsetWidth; // アニメーションを最初から
    run.style.animationName = '';
    $('ticker').classList.add('on');
  }
  $('newsRun').addEventListener('animationiteration', showNews);

  // ── 雨雲レーダー（過去 1 時間〜1 時間先は 10 分ごと、その先 3 時間先までは 1 時間ごと。コマ送りで繰り返す）──
  const JMA = 'https://www.jma.go.jp/bosai/jmatile/data/';
  const tileUrl = (f) => f.kind === 'rasrf'
    ? `${JMA}rasrf/${f.base}/immed/${f.valid}/surf/rasrf/{z}/{x}/{y}.png`   // 降水短時間予報（今後の雨）
    : `${JMA}nowc/${f.base}/none/${f.valid}/surf/hrpns/{z}/{x}/{y}.png`;   // 降水ナウキャスト
  let map = null;
  let radarLayers = [];
  let radarFrames = [];
  let radarIdx = 0;

  function initMap(r) {
    map = L.map('map', {
      zoomControl: false, attributionControl: false, dragging: false, keyboard: false,
      scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false, touchZoom: false,
    }).setView([r.lat, r.lon], r.zoom);
    L.tileLayer('https://cyberjapandata.gsi.go.jp/xyz/pale/{z}/{x}/{y}.png', { maxZoom: 18 }).addTo(map);
    setTimeout(() => map.invalidateSize(), 500); // 文字の読み込みで大きさが変わったときのため
    map.createPane('radar').style.zIndex = 450;
    L.marker([r.lat, r.lon], { icon: L.divIcon({ className: 'home', iconSize: null }), interactive: false, keyboard: false }).addTo(map);
  }

  async function loadRadar() {
    if (!window.L) return;
    let r;
    try { r = await get('radar'); } catch (err) { return; }
    if (!r.frames || !r.frames.length) return;
    if (!map) initMap(r);
    const key = (f) => `${f.kind}/${f.base}/${f.valid}`;
    if (r.frames.map(key).join() === radarFrames.map(key).join()) return;
    radarLayers.forEach((l) => map.removeLayer(l));
    radarFrames = r.frames;
    // 全部のコマを先に読み込んでおき、見せるコマだけ不透明にする
    radarLayers = radarFrames.map((f) => L.tileLayer(tileUrl(f),
      { pane: 'radar', opacity: 0, maxNativeZoom: 10, maxZoom: 18 }).addTo(map));
    $('radarBar').innerHTML = radarFrames.map((f) => `<i class="${f.forecast ? 'fc' : ''} ${f.kind === 'rasrf' ? 'sr' : ''}"></i>`).join('');
    radarIdx = radarFrames.findIndex((f) => f.forecast) - 1;
    if (radarIdx < 0) radarIdx = radarFrames.length - 1;
    showRadar();
  }

  function showRadar() {
    if (!radarFrames.length) return;
    radarLayers.forEach((l, i) => l.setOpacity(i === radarIdx ? 0.8 : 0));
    [...$('radarBar').children].forEach((el, i) => el.classList.toggle('on', i === radarIdx));
    const f = radarFrames[radarIdx];
    const d = new Date(f.time * 1000);
    const hm = `${d.getHours()}:${pad(d.getMinutes())}`;
    const ahead = Math.round((f.time * 1000 - Date.now()) / 60e3);
    const rel = ahead >= 90 ? `（約${Math.round(ahead / 60)}時間後）` : ahead >= 15 ? `（${ahead}分後）` : '';
    $('radarTime').innerHTML = f.forecast ? `雨雲 <span class="fc">${hm} 予想${rel}</span>` : `雨雲 ${hm}`;
  }

  // 1 コマ 0.7 秒、最新の実況と最後のコマでは少し止める
  let radarWait = 0;
  function stepRadar() {
    if (!radarFrames.length) return;
    if (radarWait > 0) { radarWait--; return; }
    radarIdx = (radarIdx + 1) % radarFrames.length;
    const f = radarFrames[radarIdx];
    const next = radarFrames[radarIdx + 1];
    // 最新の実況と最後のコマで少し止め、1 時間ごとの予想は 1 コマを長めに見せる
    if (radarIdx === radarFrames.length - 1) radarWait = 5;
    else if (!f.forecast && next && next.forecast) radarWait = 4;
    else if (f.kind === 'rasrf') radarWait = 2;
    showRadar();
  }

  setPage(page);
  tick();
  setInterval(tick, 1000);
  // 文字の読み込みや画面の大きさが変わったら、時計と地図を合わせ直す
  const refit = () => { fitClock(); if (map) map.invalidateSize(); };
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(refit);
  window.addEventListener('resize', refit);
  setTimeout(refit, 3000);
  loadRadar();
  setInterval(loadRadar, 5 * 60e3);
  setInterval(stepRadar, 700);
  setInterval(loadCalendar, 60e3);
  loadWeather();
  setInterval(loadWeather, 30 * 60e3);
  loadNews();
  setInterval(loadNews, 10 * 60e3);
})();
