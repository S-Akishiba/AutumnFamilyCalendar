// 家族カレンダー：iPhone 画面（月のカレンダー＋その月の予定一覧、追加・修正・削除、メンバー設定）
(() => {
  'use strict';
  const WD = ['日', '月', '火', '水', '木', '金', '土'];
  const $ = (id) => document.getElementById(id);
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const parse = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  let month = new Date(); month.setDate(1);
  let selected = null;
  let data = { members: [], events: [], holidays: {} };
  let editing = null;

  async function api(action, body, params = '') {
    const opt = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
    const res = await fetch(`api.php?action=${action}${params}`, opt);
    const json = await res.json().catch(() => ({}));
    if (res.status === 401) { location.reload(); throw new Error('ログインしてください'); }
    if (!res.ok) throw new Error(json.error || '通信できませんでした');
    return json;
  }

  // 表示する範囲（その月のカレンダーに出る 6 週間）
  function range() {
    const first = new Date(month);
    const start = new Date(first); start.setDate(1 - first.getDay());
    const end = new Date(start); end.setDate(start.getDate() + 41);
    return [start, end];
  }

  async function load() {
    const [s, e] = range();
    data = await api('calendar', null, `&from=${ymd(s)}&to=${ymd(e)}`);
    render();
  }

  const memberById = (id) => data.members.find((m) => Number(m.id) === id);
  function whoTags(ev) {
    if (!ev.member_ids.length) return '<span class="tag all">家族</span>';
    return ev.member_ids.map(memberById).filter(Boolean)
      .map((m) => `<span class="tag" style="background:${esc(m.color)}">${esc(m.name)}</span>`).join('');
  }
  const evColors = (ev) => ev.member_ids.length ? ev.member_ids.map(memberById).filter(Boolean).map((m) => m.color) : ['#888'];

  // 複数日の予定は、かかる日すべてに出す
  function eventsOn(day) {
    return data.events.filter((ev) => ev.date <= day && ev.end_date >= day);
  }

  function timeLabel(ev, day) {
    if (ev.date !== ev.end_date) {
      if (day === ev.date && ev.start_time) return `${ev.start_time}〜`;
      return day === ev.date ? '終日' : '（続き）';
    }
    if (!ev.start_time) return '終日';
    return ev.end_time ? `${ev.start_time}<br>〜${ev.end_time}` : ev.start_time;
  }

  function render() {
    $('monthTitle').textContent = `${month.getFullYear()}年${month.getMonth() + 1}月`;
    const today = ymd(new Date());
    const [s] = range();
    let html = WD.map((w) => `<div class="wd">${w}</div>`).join('');
    for (let i = 0; i < 42; i++) {
      const d = new Date(s); d.setDate(s.getDate() + i);
      const key = ymd(d);
      const cls = ['day'];
      if (d.getMonth() !== month.getMonth()) cls.push('other');
      if (d.getDay() === 0) cls.push('sun');
      if (d.getDay() === 6) cls.push('sat');
      if (data.holidays[key]) cls.push('hol');
      if (key === today) cls.push('today');
      if (key === selected) cls.push('sel');
      const dots = eventsOn(key).flatMap(evColors).slice(0, 6).map((c) => `<i class="dot" style="background:${esc(c)}"></i>`).join('');
      html += `<div class="${cls.join(' ')}" data-day="${key}"><span class="num">${d.getDate()}</span><span class="dots">${dots}</span></div>`;
    }
    $('grid').innerHTML = html;

    // 一覧：日付を選んだらその日、選ばなければ今月（今月なら今日以降）
    let days = [];
    if (selected) {
      days = [selected];
    } else {
      const last = new Date(month.getFullYear(), month.getMonth() + 1, 0);
      for (let d = new Date(month); d <= last; d.setDate(d.getDate() + 1)) {
        const k = ymd(d);
        if (k >= today || month.getMonth() !== new Date().getMonth() || month.getFullYear() !== new Date().getFullYear()) days.push(k);
      }
    }
    let list = '';
    for (const day of days) {
      const evs = eventsOn(day);
      if (!evs.length && !selected) continue;
      const d = parse(day);
      const hol = data.holidays[day] ? `<span class="hol">${esc(data.holidays[day])}</span>` : '';
      list += `<h3 class="${day === today ? 'today' : ''}">${d.getMonth() + 1}月${d.getDate()}日（${WD[d.getDay()]}）${hol}</h3>`;
      list += evs.map((ev) => `<div class="ev" data-id="${ev.id}" data-day="${day}">
          <div class="time">${timeLabel(ev, day)}</div>
          <div class="body"><div class="title">${esc(ev.title)}${ev.repeat_rule ? ' 🔁' : ''}</div>
          ${ev.note ? `<div class="note">${esc(ev.note)}</div>` : ''}</div>
          <div class="who">${whoTags(ev)}</div></div>`).join('') || '<p class="empty">予定はありません</p>';
    }
    $('list').innerHTML = list || '<p class="empty">この月の予定はまだありません</p>';
  }

  // ── 予定の編集 ──
  // LINE から開いたときは、その人を「だれの予定」の最初の選択にする（liff.php が覚える）
  function defaultMember() {
    try {
      const id = Number(localStorage.getItem('fam_default_member'));
      return id && data.members.some((m) => Number(m.id) === id) ? [id] : [];
    } catch (e) { return []; }
  }

  function chips(selectedIds) {
    $('memberChips').innerHTML = data.members.map((m) =>
      `<button type="button" data-id="${m.id}" class="${selectedIds.includes(Number(m.id)) ? 'on' : ''}" style="--c:${esc(m.color)}">${esc(m.name)}</button>`
    ).join('') || '<span class="hint">⚙ の設定でメンバーを登録すると色分けできます</span>';
  }

  function syncForm() {
    const f = $('eventForm');
    f.querySelector('.times').hidden = f.all_day.checked;
    f.querySelector('.until').hidden = !f.repeat_rule.value;
  }

  function openEditor(ev, day) {
    const f = $('eventForm');
    f.reset();
    editing = ev;
    $('editorTitle').textContent = ev ? '予定を修正' : '予定を追加';
    $('deleteBtn').hidden = !ev;
    $('repeatHint').hidden = !(ev && ev.repeat_rule);
    $('formErr').textContent = '';
    if (ev) {
      f.title.value = ev.title;
      f.start_date.value = ev.base_start_date;
      f.end_date.value = ev.base_end_date !== ev.base_start_date ? ev.base_end_date : '';
      f.all_day.checked = !ev.start_time;
      f.start_time.value = ev.start_time || '';
      f.end_time.value = ev.end_time || '';
      f.repeat_rule.value = ev.repeat_rule;
      f.repeat_until.value = ev.repeat_until || '';
      f.note.value = ev.note;
    } else {
      f.start_date.value = day || selected || ymd(new Date());
      f.all_day.checked = true;
    }
    chips(ev ? ev.member_ids : defaultMember());
    syncForm();
    $('editor').showModal();
  }

  $('eventForm').addEventListener('change', syncForm);
  $('memberChips').addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (b) b.classList.toggle('on');
  });
  $('cancelBtn').addEventListener('click', () => $('editor').close());

  $('eventForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const body = {
      id: editing ? editing.id : 0,
      title: f.title.value,
      start_date: f.start_date.value,
      end_date: f.end_date.value,
      all_day: f.all_day.checked,
      start_time: f.start_time.value,
      end_time: f.end_time.value,
      member_ids: [...$('memberChips').querySelectorAll('button.on')].map((b) => Number(b.dataset.id)),
      repeat_rule: f.repeat_rule.value,
      repeat_until: f.repeat_until.value,
      note: f.note.value,
    };
    try {
      await api('save', body);
      $('editor').close();
      const d = parse(body.start_date);
      if (!editing && (d.getMonth() !== month.getMonth() || d.getFullYear() !== month.getFullYear())) {
        month = new Date(d.getFullYear(), d.getMonth(), 1);
        selected = null;
      }
      await load();
    } catch (err) {
      $('formErr').textContent = err.message;
    }
  });

  $('deleteBtn').addEventListener('click', async () => {
    if (!editing) return;
    const msg = editing.repeat_rule ? `「${editing.title}」をすべての回から削除しますか？` : `「${editing.title}」を削除しますか？`;
    if (!confirm(msg)) return;
    try {
      await api('delete', { id: editing.id });
      $('editor').close();
      await load();
    } catch (err) {
      $('formErr').textContent = err.message;
    }
  });

  // ── メンバー設定 ──
  function memberRow(m, i) {
    const color = m.color || window.FAM_COLORS[i % window.FAM_COLORS.length];
    return `<div class="memberRow" data-id="${m.id || 0}">
      <input type="color" value="${esc(color)}"><input type="text" value="${esc(m.name || '')}" placeholder="名前" maxlength="50">
      <button type="button" class="danger del" aria-label="削除">✕</button></div>`;
  }
  $('settingsBtn').addEventListener('click', () => {
    $('memberRows').innerHTML = data.members.map(memberRow).join('');
    if (!data.members.length) $('memberRows').innerHTML = memberRow({}, 0);
    $('settingsErr').textContent = '';
    $('pairMsg').textContent = '';
    $('settings').showModal();
  });
  $('addMember').addEventListener('click', () => {
    $('memberRows').insertAdjacentHTML('beforeend', memberRow({}, $('memberRows').children.length));
  });
  $('memberRows').addEventListener('click', (e) => {
    if (e.target.classList.contains('del')) e.target.closest('.memberRow').remove();
  });
  $('settingsCancel').addEventListener('click', () => $('settings').close());
  $('settingsForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const members = [...$('memberRows').children].map((r) => ({
      id: Number(r.dataset.id),
      color: r.querySelector('input[type=color]').value,
      name: r.querySelector('input[type=text]').value,
    }));
    try {
      await api('members_save', { members });
      $('settings').close();
      await load();
    } catch (err) {
      $('settingsErr').textContent = err.message;
    }
  });
  $('pairBtn').addEventListener('click', async () => {
    const code = $('pairCode').value.replace(/\D/g, '');
    if (code.length !== 6) { $('pairMsg').textContent = '6 桁の番号を入れてください'; return; }
    $('pairMsg').textContent = 'つないでいます…';
    try {
      await api('pair_approve', { code });
      $('pairMsg').textContent = 'つなぎました。テレビに家族カレンダーが出ます';
      $('pairCode').value = '';
    } catch (err) {
      $('pairMsg').textContent = err.message;
    }
  });
  $('logoutBtn').addEventListener('click', async () => {
    if (!confirm('この端末でログアウトしますか？')) return;
    await api('logout', {});
    location.reload();
  });

  // ── カレンダー操作 ──
  $('grid').addEventListener('click', (e) => {
    const cell = e.target.closest('.day');
    if (!cell) return;
    const day = cell.dataset.day;
    if (selected === day) { openEditor(null, day); return; } // 同じ日をもう一度押したら追加
    selected = day;
    const d = parse(day);
    if (d.getMonth() !== month.getMonth()) { month = new Date(d.getFullYear(), d.getMonth(), 1); load(); return; }
    render();
  });
  $('list').addEventListener('click', (e) => {
    const row = e.target.closest('.ev');
    if (!row) return;
    openEditor(data.events.find((ev) => ev.id === Number(row.dataset.id)), row.dataset.day);
  });
  $('addBtn').addEventListener('click', () => openEditor(null));
  $('prev').addEventListener('click', () => { month.setMonth(month.getMonth() - 1); selected = null; load(); });
  $('next').addEventListener('click', () => { month.setMonth(month.getMonth() + 1); selected = null; load(); });
  $('todayBtn').addEventListener('click', () => { month = new Date(); month.setDate(1); selected = null; load(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });

  load().catch((err) => { $('list').innerHTML = `<p class="err">${esc(err.message)}</p>`; });
})();
