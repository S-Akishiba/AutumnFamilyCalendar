// 家族カレンダー：テレビ表示。時計は毎秒、予定は 1 分ごと、天気は 30 分ごと、雨雲レーダーは 5 分ごとに更新する
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

  // 今週の日曜から 2 週間（＋明日が来週になる土曜のため 1 日余分に）
  function weekStart() {
    const d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() - d.getDay());
    return d;
  }

  async function loadCalendar() {
    const s = weekStart();
    const e = new Date(s); e.setDate(s.getDate() + 14);
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
  function tick() {
    const now = new Date();
    $('hm').textContent = `${now.getHours()}:${pad(now.getMinutes())}`;
    $('sec').textContent = pad(now.getSeconds());
    const day = ymd(now);
    if (day !== lastDay) {
      lastDay = day;
      const w = now.getDay();
      $('ymd').textContent = `${now.getFullYear()}年${now.getMonth() + 1}月${now.getDate()}日`;
      $('wday').innerHTML = `<span class="${w === 0 ? 'sun' : w === 6 ? 'sat' : ''}">（${WD[w]}）</span>`;
      loadCalendar();
      renderWeather();
    }
    // 夜（23 時〜6 時）は暗く
    const h = now.getHours();
    $('stage').classList.toggle('night', h >= 23 || h < 6);
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
  const colorOf = (ev) => {
    const m = ev.member_ids.length === 1 ? memberById(ev.member_ids[0]) : null;
    return m ? m.color : '#6e7681';
  };
  function whoTags(ev) {
    if (!ev.member_ids.length) return '<span class="tag all">家族</span>';
    return ev.member_ids.map(memberById).filter(Boolean)
      .map((m) => `<span class="tag" style="background:${esc(m.color)}">${esc(m.name)}</span>`).join('');
  }
  const eventsOn = (day) => data.events.filter((ev) => ev.date <= day && ev.end_date >= day);

  function timeLabel(ev, day) {
    if (ev.date !== ev.end_date && day !== ev.date) return '（続き）';
    if (!ev.start_time) return '終日';
    return ev.end_time ? `${ev.start_time}–${ev.end_time}` : ev.start_time;
  }

  function list(day, max) {
    const evs = eventsOn(day);
    if (!evs.length) return '<div class="empty">予定はありません</div>';
    const rows = evs.slice(0, max).map((ev) => `<div class="ev">
      <span class="time">${timeLabel(ev, day)}</span><span class="title">${esc(ev.title)}</span>
      <span class="who">${whoTags(ev)}</span></div>`).join('');
    return rows + (evs.length > max ? `<div class="more">ほか ${evs.length - max} 件</div>` : '');
  }

  function renderEvents() {
    const now = new Date();
    const today = ymd(now);
    const t = new Date(now); t.setDate(now.getDate() + 1);
    const tomorrow = ymd(t);
    const hol = data.holidays[today];
    $('holiday').textContent = hol || '';
    $('today').innerHTML = list(today, 4);
    $('tomorrowTitle').textContent = `明日 ${t.getMonth() + 1}/${t.getDate()}（${WD[t.getDay()]}）の予定`;
    $('tomorrow').innerHTML = list(tomorrow, 4);

    const s = weekStart();
    let html = WD.map((w, i) => `<div class="wd ${i === 0 ? 'sun' : i === 6 ? 'sat' : ''}">${w}</div>`).join('');
    for (let i = 0; i < 14; i++) {
      const d = new Date(s); d.setDate(s.getDate() + i);
      const k = ymd(d);
      const cls = ['cell'];
      if (d.getDay() === 0) cls.push('sun');
      if (d.getDay() === 6) cls.push('sat');
      if (data.holidays[k]) cls.push('hol');
      if (k === today) cls.push('today');
      if (k < today) cls.push('past');
      const label = d.getDate() === 1 || i === 0 ? `${d.getMonth() + 1}/${d.getDate()}` : d.getDate();
      const holName = data.holidays[k] ? `<small>${esc(data.holidays[k])}</small>` : '';
      const evs = eventsOn(k);
      const items = evs.slice(0, 3).map((ev) =>
        `<div class="e" style="border-color:${esc(colorOf(ev))}">${ev.start_time && ev.date === k ? ev.start_time + ' ' : ''}${esc(ev.title)}</div>`).join('');
      const more = evs.length > 3 ? `<div class="e more">ほか${evs.length - 3}件</div>` : '';
      html += `<div class="${cls.join(' ')}"><div class="num">${label}${holName}</div>${items}${more}</div>`;
    }
    $('weeks').innerHTML = html;
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

  // ── 雨雲レーダー（過去 1 時間〜1 時間先を 10 分ごとに、コマ送りで繰り返す）──
  const JMA = 'https://www.jma.go.jp/bosai/jmatile/data/nowc/';
  let map = null;
  let radarLayers = [];
  let radarFrames = [];
  let radarIdx = 0;

  function initMap(r) {
    map = L.map('map', {
      zoomControl: false, attributionControl: false, dragging: false, keyboard: false,
      scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false, touchZoom: false,
    }).setView([r.lat, r.lon], r.zoom);
    L.tileLayer('https://cyberjapandata.gsi.go.jp/xyz/pale/{z}/{x}/{y}.png', { maxZoom: 18, className: 'base' }).addTo(map);
    map.createPane('radar').style.zIndex = 450;
    L.marker([r.lat, r.lon], { icon: L.divIcon({ className: 'home', iconSize: null }), interactive: false, keyboard: false }).addTo(map);
  }

  async function loadRadar() {
    if (!window.L) return;
    let r;
    try { r = await get('radar'); } catch (err) { return; }
    if (!r.frames || !r.frames.length) return;
    if (!map) initMap(r);
    const key = (f) => `${f.base}/${f.valid}`;
    if (r.frames.map(key).join() === radarFrames.map(key).join()) return;
    radarLayers.forEach((l) => map.removeLayer(l));
    radarFrames = r.frames;
    // 全部のコマを先に読み込んでおき、見せるコマだけ不透明にする
    radarLayers = radarFrames.map((f) => L.tileLayer(`${JMA}${f.base}/none/${f.valid}/surf/hrpns/{z}/{x}/{y}.png`,
      { pane: 'radar', opacity: 0, maxNativeZoom: 10, maxZoom: 18 }).addTo(map));
    $('radarBar').innerHTML = radarFrames.map((f) => `<i class="${f.forecast ? 'fc' : ''}"></i>`).join('');
    radarIdx = radarFrames.findIndex((f) => f.forecast) - 1;
    if (radarIdx < 0) radarIdx = radarFrames.length - 1;
    showRadar();
  }

  function showRadar() {
    if (!radarFrames.length) return;
    radarLayers.forEach((l, i) => l.setOpacity(i === radarIdx ? 0.75 : 0));
    [...$('radarBar').children].forEach((el, i) => el.classList.toggle('on', i === radarIdx));
    const f = radarFrames[radarIdx];
    const d = new Date(f.time * 1000);
    const hm = `${d.getHours()}:${pad(d.getMinutes())}`;
    $('radarTime').innerHTML = f.forecast ? `雨雲 <span class="fc">${hm} 予想</span>` : `雨雲 ${hm}`;
  }

  // 1 コマ 0.7 秒、最新の実況と最後のコマでは少し止める
  let radarWait = 0;
  function stepRadar() {
    if (!radarFrames.length) return;
    if (radarWait > 0) { radarWait--; return; }
    radarIdx = (radarIdx + 1) % radarFrames.length;
    const f = radarFrames[radarIdx];
    const next = radarFrames[radarIdx + 1];
    if (radarIdx === radarFrames.length - 1 || (!f.forecast && next && next.forecast)) radarWait = 4;
    showRadar();
  }

  tick();
  setInterval(tick, 1000);
  loadRadar();
  setInterval(loadRadar, 5 * 60e3);
  setInterval(stepRadar, 700);
  setInterval(loadCalendar, 60e3);
  loadWeather();
  setInterval(loadWeather, 30 * 60e3);
})();
