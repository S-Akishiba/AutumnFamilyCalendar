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

  async function loadCalendar() {
    const s = startOfToday();
    const e = addDays(s, DAYS - 1);
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
    $('holiday').textContent = data.holidays[ymd(s)] || '';
    bigDay($('day0'), s, '今日', 4);
    bigDay($('day1'), addDays(s, 1), '明日', 3);
    let html = '';
    for (let i = 2; i < DAYS; i++) html += smallDay(addDays(s, i));
    $('later').innerHTML = html;
  }

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
