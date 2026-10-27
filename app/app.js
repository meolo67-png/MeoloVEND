'use strict';
// MeoloVEND – Oberfläche: Grundlagen (API, Navigation, Tabellen, Formulare). Die Bereiche liegen in js/ und melden sich mit route() an.

const store = {
  get(k) { try { return localStorage.getItem(k); } catch { return null; } },
  set(k, v) { try { v == null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch { /* privates Fenster */ } },
};

const S = { token: store.get('mv_token') || '', meta: null, auswahl: {} };

const $ = (sel, el = document) => el.querySelector(sel);
const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = (n, d = 2) => (Number(n) || 0).toLocaleString('de-DE', { minimumFractionDigits: d, maximumFractionDigits: d });
const money = (n) => fmt(n) + ' ' + (S.meta?.settings.waehrung || '€');
const int = (n) => (Number(n) || 0).toLocaleString('de-DE', { maximumFractionDigits: 0 });
const dateDe = (d) => d ? String(d).slice(0, 10).split('-').reverse().join('.') : '';
const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const isoOf = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const monthStart = (off = 0) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() + off); return isoOf(d); };
const monthEnd = (off = 0) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() + off + 1); d.setDate(0); return isoOf(d); };
const MONATE = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
const monatDe = (ym) => ym ? MONATE[Number(ym.slice(5, 7)) - 1] + ' ' + ym.slice(0, 4) : '';
const parseNum = (v) => { if (v === '' || v == null) return null; let s = String(v).trim(); if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.'); const n = Number(s); return Number.isFinite(n) ? n : null; };

function can(modul, write = false) {
  const u = S.meta?.user;
  if (!u) return false;
  if (u.admin) return true;
  const r = u.rechte[modul] || '';
  return write ? r === 'w' : r === 'r' || r === 'w';
}

// ---------- Server ----------

async function api(method, path, body) {
  const res = await fetch('api.php?r=' + path, {
    method, headers: { 'Content-Type': 'application/json', ...(S.token ? { Authorization: 'Bearer ' + S.token } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  let data = null;
  try { data = await res.json(); } catch { /* keine JSON-Antwort */ }
  if (res.status === 401 && path !== '/login') { logout(true); throw new Error('Bitte neu anmelden'); }
  if (!res.ok) throw new Error(data?.error || 'Fehler ' + res.status);
  return data;
}
const GET = (p) => api('GET', p);
const POST = (p, b) => api('POST', p, b || {});
const DEL = (p) => api('DELETE', p);

// Download mit Anmeldung (CSV, DATEV, Dokumente)
async function download(path, fallbackName) {
  const res = await fetch('api.php?r=' + path, { headers: { Authorization: 'Bearer ' + S.token } });
  if (!res.ok) { let e = 'Fehler ' + res.status; try { e = (await res.json()).error; } catch { /* */ } throw new Error(e); }
  const cd = res.headers.get('Content-Disposition') || '';
  const m = /filename\*=UTF-8''([^;]+)|filename="([^"]+)"/.exec(cd);
  const name = m ? decodeURIComponent(m[1] || m[2]) : fallbackName;
  const blob = await res.blob();
  saveBlob(blob, name);
}
function saveBlob(blob, name) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

// Auswahllisten für Verweise (z. B. alle Kunden), einmal je Sitzung geladen
async function auswahl(res, neu = false) {
  if (!S.auswahl[res] || neu) S.auswahl[res] = GET('/auswahl/' + res).catch(() => []);
  return S.auswahl[res];
}

// ---------- Meldungen und Dialoge ----------

let toastTimer = null;
function toast(msg, err = false) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = err ? 'err' : '';
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.hidden = true; }, err ? 6000 : 2500);
}
const fail = (e) => toast(e.message || String(e), true);

function modal(title, html, wide = false) {
  $('#modalTitle').textContent = title;
  const b = $('#modalBody');
  b.innerHTML = html;
  $('#modal').hidden = false;
  $('#modal').classList.toggle('wide', wide);
  const f = b.querySelector('input:not([type=hidden]):not([readonly]), select, textarea');
  if (f && window.innerWidth > 700) setTimeout(() => f.focus(), 30);
  return b;
}
function closeModal() { $('#modal').hidden = true; $('#modalBody').innerHTML = ''; }

function confirmBox(text, okLabel = 'Ja') {
  return new Promise((resolve) => {
    const b = modal('Bitte bestätigen', `<p>${esc(text)}</p><div class="row end"><button type="button" data-x="0">Abbrechen</button><button type="button" class="danger" data-x="1">${esc(okLabel)}</button></div>`);
    b.addEventListener('click', (e) => { const x = e.target.closest('[data-x]'); if (x) { closeModal(); resolve(x.dataset.x === '1'); } });
  });
}

// ---------- Tabellen ----------

// cols: [{key, label, type: 'money'|'num'|'int'|'date'|'text', fmt(row), sum: true, cls}]
function table(cols, rows, opts = {}) {
  const id = 't' + Math.random().toString(36).slice(2, 8);
  const st = { key: opts.sortKey || null, dir: 1 };
  const cell = (c, r) => {
    if (c.fmt) return c.fmt(r);
    const v = r[c.key];
    if (c.type === 'money') return v == null || v === '' ? '' : money(v);
    if (c.type === 'num') return v == null || v === '' ? '' : fmt(v);
    if (c.type === 'int') return v == null || v === '' ? '' : int(v);
    if (c.type === 'date') return dateDe(v);
    if (c.type === 'bool') return v ? '✓' : '';
    return esc(v);
  };
  const numeric = (c) => ['money', 'num', 'int'].includes(c.type);
  const render = () => {
    let data = rows;
    if (st.key) {
      const c = cols.find((x) => x.key === st.key);
      const val = (r) => (c?.sortVal ? c.sortVal(r) : r[st.key]);
      data = [...rows].sort((a, b) => { const x = val(a), y = val(b); return (numeric(c) ? (Number(x) || 0) - (Number(y) || 0) : String(x ?? '').localeCompare(String(y ?? ''), 'de', { numeric: true })) * st.dir; });
    }
    const sums = cols.some((c) => c.sum) ? `<tfoot><tr>${cols.map((c, i) => `<td class="${numeric(c) ? 'num' : ''}">${c.sum ? (c.type === 'money' ? money(rows.reduce((s, r) => s + (Number(r[c.key]) || 0), 0)) : c.type === 'int' ? int(rows.reduce((s, r) => s + (Number(r[c.key]) || 0), 0)) : fmt(rows.reduce((s, r) => s + (Number(r[c.key]) || 0), 0))) : i === 0 ? 'Summe' : ''}</td>`).join('')}</tr></tfoot>` : '';
    return `<table class="tbl${opts.onRow ? ' click' : ''}"><thead><tr>${cols.map((c) => `<th data-k="${esc(c.key)}" class="${numeric(c) ? 'num' : ''}">${esc(c.label)}${st.key === c.key ? (st.dir > 0 ? ' ▲' : ' ▼') : ''}</th>`).join('')}</tr></thead>
      <tbody>${data.length ? data.map((r) => `<tr data-i="${rows.indexOf(r)}" class="${opts.rowCls ? opts.rowCls(r) : ''}">${cols.map((c) => `<td class="${numeric(c) ? 'num' : ''} ${c.cls || ''}">${cell(c, r)}</td>`).join('')}</tr>`).join('')
        : `<tr><td colspan="${cols.length}" class="muted empty">${esc(opts.empty || 'Keine Einträge')}</td></tr>`}</tbody>${sums}</table>`;
  };
  const html = `<div class="tbl-wrap" id="${id}">${render()}</div>`;
  setTimeout(() => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', (e) => {
      const th = e.target.closest('th');
      if (th) { st.dir = st.key === th.dataset.k ? -st.dir : 1; st.key = th.dataset.k; el.innerHTML = render(); return; }
      const tr = e.target.closest('tbody tr[data-i]');
      if (tr && opts.onRow && !e.target.closest('button, a, input')) opts.onRow(rows[Number(tr.dataset.i)], e);
    });
  });
  return html;
}

// CSV für Excel (Semikolon, Dezimalkomma, UTF-8 mit BOM)
function csv(cols, rows, name) {
  const val = (c, r) => {
    let v = c.csv ? c.csv(r) : r[c.key];
    if (v == null) return '';
    if (typeof v === 'number') return String(v).replace('.', ',');
    if (typeof v === 'boolean') return v ? 'ja' : 'nein';
    v = String(v);
    return /[;"\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
  };
  const lines = [cols.map((c) => c.label).join(';'), ...rows.map((r) => cols.map((c) => val(c, r)).join(';'))];
  saveBlob(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }), name.endsWith('.csv') ? name : name + '.csv');
}

// ---------- Formulare ----------

function fieldInput(f, v, opts) {
  const name = `name="${esc(f.name)}"`;
  const ro = f.ro ? ' disabled' : '';
  const req = f.req ? ' required' : '';
  switch (f.type) {
    case 'textarea': return `<textarea ${name} rows="3"${ro}>${esc(v)}</textarea>`;
    case 'bool': return `<input type="checkbox" ${name}${v ? ' checked' : ''}${ro}>`;
    case 'date': return `<input type="date" ${name} value="${esc(v || '')}"${ro}${req}>`;
    case 'int': return `<input type="number" step="1" ${name} value="${esc(v ?? '')}"${ro}${req}>`;
    case 'dec': return `<input inputmode="decimal" ${name} value="${v == null || v === '' ? '' : esc(String(v).replace('.', ','))}"${ro}${req}>`;
    case 'select': return `<select ${name}${ro}${req}><option value=""></option>${Object.entries(f.opts).map(([k, t]) => `<option value="${esc(k)}"${String(v ?? '') === k ? ' selected' : ''}>${esc(t)}</option>`).join('')}</select>`;
    case 'ref': return `<select ${name}${ro}${req} data-ref="${esc(f.opts)}" data-val="${esc(v || '')}"><option value="">…</option></select>${opts.write && !f.ro && S.meta.resources[f.opts]?.write ? `<button type="button" class="link small" data-neu="${esc(f.opts)}" data-for="${esc(f.name)}">+ neu</button>` : ''}`;
    case 'email': return `<input type="email" ${name} value="${esc(v)}"${ro}${req}>`;
    case 'tel': return `<input type="tel" ${name} value="${esc(v)}"${ro}${req}>`;
    default: return `<input ${name} value="${esc(v)}"${ro}${req}>`;
  }
}

async function fillRefs(el) {
  for (const s of $$('select[data-ref]', el)) {
    const list = await auswahl(s.dataset.ref);
    const cur = s.dataset.val;
    s.innerHTML = `<option value=""></option>` + list.map((o) => `<option value="${esc(o.id)}"${o.id === cur ? ' selected' : ''}>${esc(o.label)}</option>`).join('');
  }
}

function formData(form, fields) {
  const out = {};
  for (const f of fields) {
    const el = form.elements[f.name];
    if (!el || el.disabled) continue;
    if (f.type === 'bool') out[f.name] = el.checked;
    else if (f.type === 'dec') out[f.name] = el.value.trim() === '' ? null : parseNum(el.value) ?? el.value;
    else out[f.name] = el.value;
  }
  return out;
}

// Eingabemaske für einen Datensatz in einem Dialog. fixed: vorbelegte, ausgeblendete Felder (z. B. automat_id)
async function editRecord(res, row = null, opts = {}) {
  const m = S.meta.resources[res];
  if (!m) return;
  if (row?.id) row = await GET(`/r/${res}/${row.id}`);
  row = { ...(opts.defaults || {}), ...(row || {}), ...(opts.fixed || {}) };
  const write = m.write;
  const fields = m.fields.filter((f) => !(opts.fixed && f.name in opts.fixed));
  const b = modal((row.id ? m.single + ' bearbeiten' : m.single + ' anlegen'), `
    <form class="stack" id="recForm">
      <div class="grid2">${fields.map((f) => `<label class="${f.type === 'textarea' ? 'full' : ''}${f.type === 'bool' ? ' check' : ''}">${f.type === 'bool' ? fieldInput(f, row[f.name], { write }) + ' ' + esc(f.label) : esc(f.label) + (f.req ? ' *' : '') + fieldInput(f, row[f.name], { write })}</label>`).join('')}</div>
      ${m.docs && row.id ? `<div id="docBox"></div>` : ''}
      <div class="row end">${row.id && write ? `<button type="button" class="danger-link" id="recDel">Löschen</button><span class="spacer"></span>` : ''}<button type="button" id="recCancel">${write ? 'Abbrechen' : 'Schließen'}</button>${write ? '<button class="primary">Speichern</button>' : ''}</div>
    </form>`, true);
  if (!write) $$('input, select, textarea', b).forEach((x) => { x.disabled = true; });
  await fillRefs(b);
  if (m.docs && row.id) docsBox($('#docBox', b), res, row.id, write);
  $('#recCancel', b).onclick = closeModal;
  b.addEventListener('click', async (e) => {
    const n = e.target.closest('[data-neu]');
    if (!n) return;
    // Verweis direkt neu anlegen (z. B. neuen Ort beim Kunden)
    const zielRes = n.dataset.neu, feld = n.dataset.for;
    const keep = formData($('#recForm', b), fields);
    await editRecord(zielRes, null, { onSaved: async (neu) => { await auswahl(zielRes, true); await editRecord(res, null, { ...opts, defaults: { ...row, ...keep, [feld]: neu.id } }); } });
  });
  const del = $('#recDel', b);
  if (del) del.onclick = async () => {
    if (!(await confirmBox(m.single + ' wirklich löschen?', 'Löschen'))) return editRecord(res, row, opts);
    try { await DEL(`/r/${res}/${row.id}`); S.auswahl[res] = null; toast('Gelöscht'); opts.onSaved?.(null); rerender(); } catch (e2) { fail(e2); editRecord(res, row, opts); }
  };
  $('#recForm', b).onsubmit = async (e) => {
    e.preventDefault();
    const data = { ...formData(e.target, fields), ...(opts.fixed || {}) };
    if (row.id) data.id = row.id;
    try {
      const saved = await POST('/r/' + res, data);
      S.auswahl[res] = null;
      closeModal();
      toast('Gespeichert');
      if (opts.onSaved) opts.onSaved(saved); else rerender();
    } catch (e2) { fail(e2); }
  };
}

// Dokumente zu einem Datensatz (Verträge, Rechnungen, Fotos, Gesundheitszeugnis …)
async function docsBox(el, res, id, write) {
  const load = async () => {
    const docs = await GET(`/dok/${res}/${id}`);
    el.innerHTML = `<h3>Dokumente</h3>${docs.length ? `<ul class="docs">${docs.map((d) => `<li><a href="#" data-dl="${d.id}" data-name="${esc(d.name)}">${esc(d.name)}</a> <span class="muted small">${fmt(d.groesse / 1024, 0)} KB · ${esc(d.benutzer)} · ${dateDe(d.created_at)}</span>${write ? ` <button type="button" class="link small" data-rm="${d.id}">entfernen</button>` : ''}</li>`).join('')}</ul>` : '<p class="muted">Noch keine Dokumente.</p>'}
      ${write ? `<label class="btn small">Datei hinzufügen<input type="file" hidden multiple></label>` : ''}`;
  };
  await load();
  el.addEventListener('click', async (e) => {
    const dl = e.target.closest('[data-dl]');
    if (dl) { e.preventDefault(); download('/datei/' + dl.dataset.dl, dl.dataset.name).catch(fail); }
    const rm = e.target.closest('[data-rm]');
    if (rm) { try { await DEL('/datei/' + rm.dataset.rm); await load(); } catch (e2) { fail(e2); } }
  });
  el.addEventListener('change', async (e) => {
    if (e.target.type !== 'file') return;
    for (const file of e.target.files) {
      const daten = await new Promise((resolve) => { const r = new FileReader(); r.onload = () => resolve(String(r.result).split(',')[1]); r.readAsDataURL(file); });
      try { await POST(`/dok/${res}/${id}`, { name: file.name, daten }); } catch (e2) { fail(e2); }
    }
    await load();
  });
}

// ---------- Navigation ----------

const ROUTES = [];
function route(pattern, fn) { ROUTES.push([new RegExp('^' + pattern.replace(/:(\w+)/g, '(?<$1>[^/]+)') + '$'), fn]); }

// Menü: [Modul, Titel, Ziel]
const NAV = [
  [null, 'Übersicht', '#/'],
  ['stammdaten', 'Stammdaten', null],
  ['stammdaten', 'Kunden', '#/r/kunden'], ['stammdaten', 'Standorte', '#/r/standorte'], ['stammdaten', 'Kunden-Cluster', '#/r/cluster'],
  ['stammdaten', 'Mitarbeiter', '#/r/mitarbeiter'], ['stammdaten', 'Lieferanten / Hersteller', '#/r/lieferanten'], ['stammdaten', 'Orte', '#/r/orte'],
  ['automaten', 'Automaten', null],
  ['automaten', 'Automaten', '#/automaten'], ['automaten', 'Komponenten', '#/komponenten'], ['automaten', 'Verschleißteile', '#/verschleiss'],
  ['automaten', 'Störungen / Service', '#/r/stoerungen'], ['automaten', 'Kosten', '#/r/kosten'],
  ['touren', 'Touren', null],
  ['touren', 'Tourenplan', '#/touren'], ['touren', 'Besuch erfassen', '#/besuch'], ['touren', 'Besuche', '#/besuche'], ['touren', 'Audits (EVA-DTS)', '#/audits'],
  ['waren', 'Waren', null],
  ['waren', 'Artikel', '#/r/artikel'], ['waren', 'Warengruppen', '#/r/warengruppen'], ['waren', 'Lager / Fahrzeuge', '#/r/lager'], ['waren', 'Bestand', '#/bestand'],
  ['waren', 'Buchungen', '#/bewegungen'], ['waren', 'Inventur', '#/inventur'], ['waren', 'Bestellvorschlag', '#/bestellvorschlag'],
  ['einnahmen', 'Geld', null],
  ['einnahmen', 'Einnahmen', '#/einnahmen'], ['einnahmen', 'Soll / Ist', '#/sollist'], ['einnahmen', 'Abschlüsse', '#/abschluesse'],
  ['rabatte', 'Rabatte / Verträge', null],
  ['rabatte', 'Rabatte / Provisionen', '#/r/rabatte'], ['rabatte', 'Rabattabgleich', '#/rabattabgleich'], ['rabatte', 'Verträge', '#/r/vertraege'], ['rabatte', 'Vertragsraten', '#/vertragsraten'],
  ['auswertungen', 'Auswertungen', null],
  ['auswertungen', 'Erfolgsrechnung', '#/erfolgsrechnung'], ['auswertungen', 'Monatsübersicht', '#/monatsuebersicht'], ['auswertungen', 'DATEV-Export', '#/datev'],
  ['system', 'System', null],
  ['system', 'Benutzer', '#/benutzer'], ['system', 'Einstellungen', '#/einstellungen'],
];

function renderNav() {
  const html = [];
  let sec = null;
  for (const [mod, title, href] of NAV) {
    if (mod && !can(mod)) continue;
    if (mod === 'system' && !S.meta.user.admin && href) continue;
    if (!href) { sec = title; continue; }
    if (sec) { html.push(`<div class="nav-sec">${esc(sec)}</div>`); sec = null; }
    html.push(`<a href="${href}">${esc(title)}</a>`);
  }
  $('#nav').innerHTML = html.join('');
}

function markNav() {
  const h = location.hash || '#/';
  let best = null;
  for (const a of $$('#nav a')) { const href = a.getAttribute('href'); if ((h === href || h.startsWith(href + '/') || h.startsWith(href + '?')) && (!best || href.length > best.getAttribute('href').length)) best = a; a.classList.remove('active'); }
  best?.classList.add('active');
}

function setTitle(t, actions = '') {
  $('#title').textContent = t;
  document.title = t + ' – MeoloVEND';
  $('#actions').innerHTML = actions;
}

// Parameter aus dem Hash (#/besuche?automat=…)
function hashParams() { const i = location.hash.indexOf('?'); return new URLSearchParams(i >= 0 ? location.hash.slice(i + 1) : ''); }
function setParams(p) { const base = location.hash.split('?')[0]; history.replaceState(null, '', base + '?' + new URLSearchParams(p).toString()); }

let renderSeq = 0;
async function rerender() {
  const seq = ++renderSeq;
  const path = (location.hash.replace(/^#/, '') || '/').split('?')[0];
  document.body.classList.remove('nav-open');
  markNav();
  for (const [re, fn] of ROUTES) {
    const m = re.exec(path);
    if (!m) continue;
    const app = $('#app');
    if (!app.firstChild) app.innerHTML = '<p class="muted">Lädt …</p>';
    try {
      await fn(m.groups || {}, app, () => seq === renderSeq);
    } catch (e) {
      if (seq === renderSeq) app.innerHTML = `<div class="panel"><p class="err-text">${esc(e.message)}</p></div>`;
    }
    return;
  }
  $('#app').innerHTML = '<div class="panel"><p>Seite nicht gefunden.</p></div>';
}

// Allgemeine Liste für jede Tabelle aus meta.php
route('/r/:res', async ({ res }, app) => {
  const m = S.meta.resources[res];
  if (!m) throw new Error('Kein Zugriff');
  const p = hashParams();
  setTitle(m.title, `${m.write ? '<button class="primary" id="neuBtn">+ Neu</button>' : ''}<button id="csvBtn">CSV</button>`);
  const fields = Object.fromEntries(m.fields.map((f) => [f.name, f]));
  const cols = m.list.map((k) => fieldCol(fields[k]));
  app.innerHTML = `<div class="panel"><div class="row"><input type="search" id="q" placeholder="Suchen …" value="${esc(p.get('q') || '')}"><span class="muted" id="cnt"></span></div><div id="list"></div></div>`;
  const load = async () => {
    const q = $('#q').value.trim();
    const rows = await GET(`/r/${res}${q ? '&q=' + encodeURIComponent(q) : ''}`);
    $('#cnt').textContent = rows.length + ' Einträge';
    $('#list').innerHTML = table(cols, rows, { onRow: (r) => (res === 'automaten' ? (location.hash = '#/automat/' + r.id) : editRecord(res, r)) });
    $('#csvBtn').onclick = () => csv(m.fields.map((f) => ({ ...fieldCol(f), csv: (r) => (f.type === 'ref' ? r[f.name + '__label'] : f.type === 'select' ? (f.opts[r[f.name]] ?? r[f.name]) : r[f.name]) })), rows, m.title);
  };
  let t = null;
  $('#q').oninput = () => { clearTimeout(t); t = setTimeout(() => { setParams({ q: $('#q').value }); load().catch(fail); }, 250); };
  if ($('#neuBtn')) $('#neuBtn').onclick = () => editRecord(res);
  await load();
});

function fieldCol(f) {
  const c = { key: f.name, label: f.label };
  if (f.type === 'ref') { c.fmt = (r) => esc(r[f.name + '__label'] || ''); c.sortVal = (r) => r[f.name + '__label']; }
  else if (f.type === 'select') { c.fmt = (r) => esc(f.opts[r[f.name]] ?? r[f.name]); }
  else if (f.type === 'dec') c.type = 'num';
  else if (f.type === 'int') c.type = 'int';
  else if (f.type === 'date') c.type = 'date';
  else if (f.type === 'bool') c.type = 'bool';
  return c;
}

// ---------- Anmeldung ----------

function showLogin() {
  $('#shell').hidden = true;
  $('#login').hidden = false;
  $('#loginForm').onsubmit = async (e) => {
    e.preventDefault();
    const f = e.target;
    $('#loginErr').hidden = true;
    try {
      const r = await POST('/login', { login: f.login.value.trim(), passwort: f.passwort.value });
      S.token = r.token;
      store.set('mv_token', r.token);
      f.passwort.value = '';
      await boot();
    } catch (e2) { $('#loginErr').textContent = e2.message; $('#loginErr').hidden = false; }
  };
}

async function logout(local = false) {
  if (!local) { try { await POST('/logout'); } catch { /* egal */ } }
  S.token = ''; S.meta = null; S.auswahl = {};
  store.set('mv_token', null);
  showLogin();
}

async function boot() {
  if (!S.token) return showLogin();
  try { S.meta = await GET('/meta'); } catch { return; }
  $('#login').hidden = true;
  $('#shell').hidden = false;
  $('#who').textContent = S.meta.user.name;
  $('#ver').textContent = (S.meta.settings.firma ? S.meta.settings.firma + ' · ' : '') + 'Version ' + S.meta.version;
  renderNav();
  rerender();
}
