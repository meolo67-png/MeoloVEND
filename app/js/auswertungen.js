'use strict';
// Auswertungen: frei einstellbare Erfolgsrechnung (Pivot mit Ziehen und Ablegen, Drilldown, Export), Monatsübersicht, DATEV-Export

const ER_DIMS = { automat: 'Automat', kunde: 'Kunde', cluster: 'Cluster', standort: 'Standort', tour: 'Tour', art: 'Automatenart', kostenstelle: 'Kostenstelle', monat: 'Monat', jahr: 'Jahr' };
const ER_WERTE = {
  umsatz: 'Umsatz brutto', umsatz_netto: 'Umsatz netto', bargeldlos: 'davon bargeldlos', verkaeufe: 'Verkäufe', besuche: 'Besuche', wareneinsatz: 'Wareneinsatz', rohertrag: 'Rohertrag',
  fehlgeld: 'Fehlgeld', provision: 'Provision / Rabatte', kosten: 'Kosten', service: 'Servicekosten', vertraege: 'Verträge (±)', abschreibung: 'Abschreibung', deckungsbeitrag: 'Deckungsbeitrag',
  marge: 'Rohertrag in %', db_quote: 'DB in % vom Netto',
};
const ER_ABGELEITET = { marge: (s) => (s.umsatz_netto ? s.rohertrag / s.umsatz_netto * 100 : null), db_quote: (s) => (s.umsatz_netto ? s.deckungsbeitrag / s.umsatz_netto * 100 : null) };
const ER_VORLAGEN = {
  'Deckungsbeitrag je Automat': { zeilen: ['automat'], spalten: [], werte: ['umsatz_netto', 'wareneinsatz', 'provision', 'kosten', 'abschreibung', 'vertraege', 'fehlgeld', 'deckungsbeitrag', 'db_quote'] },
  'Umsatz je Kunde und Monat': { zeilen: ['kunde', 'automat'], spalten: ['monat'], werte: ['umsatz'] },
  'Fehlgeld je Tour': { zeilen: ['tour', 'automat'], spalten: ['monat'], werte: ['fehlgeld'] },
  'Cluster-Vergleich': { zeilen: ['cluster', 'kunde'], spalten: [], werte: ['umsatz', 'verkaeufe', 'rohertrag', 'marge', 'deckungsbeitrag'] },
};

function erLayout() {
  try { const l = JSON.parse(store.get('mv_er_layout')); if (l?.zeilen) return l; } catch { /* */ }
  return structuredClone(ER_VORLAGEN['Deckungsbeitrag je Automat']);
}

function erFmt(k, v) {
  if (v == null) return '';
  if (k === 'verkaeufe' || k === 'besuche') return int(v);
  if (k === 'marge' || k === 'db_quote') return fmt(v, 1) + ' %';
  return fmt(v);
}

function erSumme(fakten) {
  const s = {};
  for (const k of Object.keys(ER_WERTE)) if (!ER_ABGELEITET[k]) s[k] = fakten.reduce((a, f) => a + (Number(f[k]) || 0), 0);
  for (const [k, fn] of Object.entries(ER_ABGELEITET)) s[k] = fn(s);
  return s;
}

function groupBy(fakten, dim) {
  const m = new Map();
  for (const f of fakten) { const k = f[dim] ?? ''; if (!m.has(k)) m.set(k, []); m.get(k).push(f); }
  return [...m.entries()].sort((a, b) => String(a[0]).localeCompare(String(b[0]), 'de', { numeric: true }));
}

const dimLabel = (dim, v) => (dim === 'monat' ? monatDe(v) : v);

route('/erfolgsrechnung', async (_, app) => {
  const p = hashParams();
  setTitle('Erfolgsrechnung', '<button id="csvBtn">CSV / Excel</button><button id="druck">Drucken</button>');
  const L = erLayout();
  app.innerHTML = `<div class="panel"><div class="row filters">
      <label class="inline">von <input type="date" id="von" value="${p.get('von') || new Date().getFullYear() + '-01-01'}"></label><label class="inline">bis <input type="date" id="bis" value="${p.get('bis') || monthEnd()}"></label>
      <select id="vorlage"><option value="">Vorlage laden …</option>${Object.keys(ER_VORLAGEN).map((k) => `<option>${esc(k)}</option>`).join('')}</select></div>
    <div class="pivot-cfg">
      <div class="zone" data-zone="frei"><div class="zone-t">Verfügbar (ziehen oder antippen)</div><div class="chips"></div></div>
      <div class="zone" data-zone="zeilen"><div class="zone-t">Zeilen (Reihenfolge = Drilldown)</div><div class="chips"></div></div>
      <div class="zone" data-zone="spalten"><div class="zone-t">Spalten</div><div class="chips"></div></div>
      <div class="zone" data-zone="werte"><div class="zone-t">Werte</div><div class="chips"></div></div>
    </div></div><div class="panel"><div id="pivot" class="tbl-wrap"></div></div>`;
  $('#druck').onclick = () => window.print();
  let fakten = [];
  const offen = new Set();

  const chip = (id, label, typ) => `<span class="chip ${typ}" draggable="true" data-id="${id}" data-typ="${typ}">${esc(label)}<button type="button" class="x" aria-label="entfernen">×</button></span>`;
  const drawCfg = () => {
    const benutzt = new Set([...L.zeilen, ...L.spalten, ...L.werte]);
    $('[data-zone=frei] .chips').innerHTML = Object.entries(ER_DIMS).filter(([k]) => !benutzt.has(k)).map(([k, t]) => chip(k, t, 'dim')).join('') + Object.entries(ER_WERTE).filter(([k]) => !benutzt.has(k)).map(([k, t]) => chip(k, t, 'wert')).join('');
    $('[data-zone=zeilen] .chips').innerHTML = L.zeilen.map((k) => chip(k, ER_DIMS[k], 'dim')).join('');
    $('[data-zone=spalten] .chips').innerHTML = L.spalten.map((k) => chip(k, ER_DIMS[k], 'dim')).join('');
    $('[data-zone=werte] .chips').innerHTML = L.werte.map((k) => chip(k, ER_WERTE[k], 'wert')).join('');
    store.set('mv_er_layout', JSON.stringify(L));
  };
  const move = (id, typ, zone, vor = null) => {
    for (const z of ['zeilen', 'spalten', 'werte']) L[z] = L[z].filter((x) => x !== id);
    if (zone !== 'frei') {
      if (typ === 'wert' && zone !== 'werte') zone = 'werte';
      if (typ === 'dim' && zone === 'werte') zone = 'zeilen';
      if (zone === 'spalten') L.spalten = [id];
      else if (zone === 'zeilen' && L.zeilen.length >= 3) L.zeilen.pop();
      if (zone !== 'spalten') { const i = vor ? L[zone].indexOf(vor) : -1; i >= 0 ? L[zone].splice(i, 0, id) : L[zone].push(id); }
    }
    if (!L.werte.length) L.werte = ['umsatz'];
    if (!L.zeilen.length) L.zeilen = ['automat'];
    drawCfg(); draw();
  };
  const cfg = $('.pivot-cfg', app);
  cfg.addEventListener('dragstart', (e) => { const c = e.target.closest('.chip'); if (c) e.dataTransfer.setData('text/plain', c.dataset.id + '|' + c.dataset.typ); });
  cfg.addEventListener('dragover', (e) => { if (e.target.closest('.zone')) { e.preventDefault(); e.target.closest('.zone').classList.add('over'); } });
  cfg.addEventListener('dragleave', (e) => e.target.closest('.zone')?.classList.remove('over'));
  cfg.addEventListener('drop', (e) => {
    const z = e.target.closest('.zone');
    if (!z) return;
    e.preventDefault(); z.classList.remove('over');
    const [id, typ] = e.dataTransfer.getData('text/plain').split('|');
    move(id, typ, z.dataset.zone, e.target.closest('.chip')?.dataset.id);
  });
  cfg.addEventListener('click', (e) => {
    const c = e.target.closest('.chip');
    if (!c) return;
    const zone = c.closest('.zone').dataset.zone;
    if (e.target.closest('.x') || zone !== 'frei') move(c.dataset.id, c.dataset.typ, 'frei');
    else move(c.dataset.id, c.dataset.typ, c.dataset.typ === 'wert' ? 'werte' : 'zeilen');
  });

  // Tabelle: Zeilen hierarchisch (aufklappbar), Spalten optional; bei Spalten wird je Wert eine Spaltengruppe gezeigt
  const exportRows = [];
  const draw = () => {
    exportRows.length = 0;
    const sp = L.spalten[0];
    const spWerte = sp ? groupBy(fakten, sp).map(([k]) => k) : [null];
    const head = `<tr><th>${L.zeilen.map((d) => esc(ER_DIMS[d])).join(' / ')}</th>${sp ? spWerte.map((v) => L.werte.map((w) => `<th class="num">${esc(dimLabel(sp, v))}${L.werte.length > 1 ? '<div class="small muted">' + esc(ER_WERTE[w]) + '</div>' : ''}</th>`).join('')).join('') + L.werte.map((w) => `<th class="num">Summe${L.werte.length > 1 ? '<div class="small muted">' + esc(ER_WERTE[w]) + '</div>' : ''}</th>`).join('') : L.werte.map((w) => `<th class="num">${esc(ER_WERTE[w])}</th>`).join('')}</tr>`;
    const cells = (fs) => {
      const out = [];
      if (sp) for (const v of spWerte) { const s = erSumme(fs.filter((f) => f[sp] === v)); for (const w of L.werte) out.push([w, s[w]]); }
      const s = erSumme(fs);
      for (const w of L.werte) out.push([w, s[w]]);
      return out;
    };
    const rowsHtml = [];
    const walk = (fs, level, pfad) => {
      const dim = L.zeilen[level];
      for (const [key, sub] of groupBy(fs, dim)) {
        const id = pfad + '›' + key;
        const kinder = level < L.zeilen.length - 1;
        const auf = offen.has(id);
        const c = cells(sub);
        exportRows.push([' '.repeat(level * 2) + dimLabel(dim, key), ...c.map(([, v]) => v)]);
        rowsHtml.push(`<tr class="lvl${level}${kinder ? ' toggle' : ''}" data-id="${esc(id)}"><td style="padding-left:${0.6 + level * 1.4}em">${kinder ? (auf ? '▾ ' : '▸ ') : ''}${esc(dimLabel(dim, key))}</td>${c.map(([w, v]) => `<td class="num ${v < 0 ? 'neg' : ''}">${erFmt(w, v)}</td>`).join('')}</tr>`);
        if (kinder && auf) walk(sub, level + 1, id);
      }
    };
    walk(fakten, 0, '');
    const tot = cells(fakten);
    exportRows.push(['Summe', ...tot.map(([, v]) => v)]);
    $('#pivot').innerHTML = fakten.length ? `<table class="tbl pivot"><thead>${head}</thead><tbody>${rowsHtml.join('')}</tbody><tfoot><tr><td>Summe</td>${tot.map(([w, v]) => `<td class="num">${erFmt(w, v)}</td>`).join('')}</tr></tfoot></table>
      <p class="muted small">Deckungsbeitrag = Umsatz netto − Wareneinsatz + Fehlgeld − Provision − Kosten − Servicekosten ± Verträge − Abschreibung. Zeilen mit ▸ aufklappen für Details.</p>` : '<p class="muted">Keine Daten im Zeitraum.</p>';
    $('#csvBtn').onclick = () => {
      const kopf = [L.zeilen.map((d) => ER_DIMS[d]).join(' / '), ...(sp ? [...spWerte.flatMap((v) => L.werte.map((w) => dimLabel(sp, v) + (L.werte.length > 1 ? ' ' + ER_WERTE[w] : ''))), ...L.werte.map((w) => 'Summe ' + ER_WERTE[w])] : L.werte.map((w) => ER_WERTE[w]))];
      csv(kopf.map((label, i) => ({ label, key: i, csv: (r) => (typeof r[i] === 'number' ? Math.round(r[i] * 100) / 100 : r[i]) })), exportRows, 'Erfolgsrechnung');
    };
  };
  $('#pivot').addEventListener('click', (e) => {
    const tr = e.target.closest('tr.toggle');
    if (!tr) return;
    offen.has(tr.dataset.id) ? offen.delete(tr.dataset.id) : offen.add(tr.dataset.id);
    draw();
  });
  $('#vorlage').onchange = () => { const v = ER_VORLAGEN[$('#vorlage').value]; if (v) { Object.assign(L, structuredClone(v)); drawCfg(); draw(); } $('#vorlage').value = ''; };
  const load = async () => {
    const q = { von: $('#von').value, bis: $('#bis').value };
    setParams(q);
    $('#pivot').innerHTML = '<p class="muted">Rechnet …</p>';
    fakten = (await GET('/erfolgsrechnung&' + new URLSearchParams(q))).fakten;
    draw();
  };
  $('#von').onchange = $('#bis').onchange = () => load().catch(fail);
  drawCfg();
  await load();
});

route('/monatsuebersicht', async (_, app) => {
  const monat = hashParams().get('monat') || today().slice(0, 7);
  const von = monat + '-01';
  const bis = isoOf(new Date(Number(monat.slice(0, 4)), Number(monat.slice(5, 7)), 0));
  setTitle('Monatsübersicht ' + monatDe(monat), '<button id="csvBtn">CSV</button><button id="druck">Drucken</button>');
  const r = await GET(`/erfolgsrechnung&von=${von}&bis=${bis}`);
  const verschiebe = (n) => { const d = new Date(von); d.setMonth(d.getMonth() + n); return isoOf(d).slice(0, 7); };
  const rows = r.fakten.map((f) => ({ ...f, marge: f.umsatz_netto ? f.rohertrag / f.umsatz_netto * 100 : null }));
  const cols = [{ key: 'automat', label: 'Automat' }, { key: 'kunde', label: 'Kunde' }, { key: 'besuche', label: 'Besuche', type: 'int', sum: true }, { key: 'verkaeufe', label: 'Verkäufe', type: 'int', sum: true },
    { key: 'umsatz', label: 'Umsatz brutto', type: 'money', sum: true }, { key: 'umsatz_netto', label: 'netto', type: 'money', sum: true }, { key: 'wareneinsatz', label: 'Wareneinsatz', type: 'money', sum: true },
    { key: 'marge', label: 'Rohertrag %', type: 'num', fmt: (x) => (x.marge == null ? '' : fmt(x.marge, 1) + ' %') }, { key: 'fehlgeld', label: 'Fehlgeld', type: 'money', sum: true },
    { key: 'provision', label: 'Provision', type: 'money', sum: true }, { key: 'deckungsbeitrag', label: 'DB', type: 'money', sum: true, fmt: (x) => `<span class="${x.deckungsbeitrag < 0 ? 'neg' : ''}">${money(x.deckungsbeitrag)}</span>` }];
  app.innerHTML = `<div class="panel"><div class="row"><a class="btn" href="#/monatsuebersicht?monat=${verschiebe(-1)}">◀</a><strong>${monatDe(monat)}</strong><a class="btn" href="#/monatsuebersicht?monat=${verschiebe(1)}">▶</a>
    <span class="muted small">Zeile antippen für die Monatsdetails (Besuche des Automaten).</span></div>${table(cols, rows, { onRow: (x) => (location.hash = `#/besuche?automat=${x.automat_id}&von=${von}&bis=${bis}`), empty: 'Keine Daten in diesem Monat' })}</div>`;
  $('#druck').onclick = () => window.print();
  $('#csvBtn').onclick = () => csv(cols, rows, 'Monatsuebersicht-' + monat);
});

route('/datev', async (_, app) => {
  setTitle('DATEV-Export');
  app.innerHTML = `<div class="panel narrow stack"><p>Buchungsstapel im DATEV-Format (EXTF 7.0) für den Steuerberater: Barumsätze je Automat (Kasse an Erlöse, Kostenstelle = Automat), bargeldlose Umsätze (Geldtransit 1360), Kassendifferenzen und freigegebene Provisionen.</p>
    <div class="grid2"><label>von <input type="date" id="von" value="${monthStart(-1)}"></label><label>bis <input type="date" id="bis" value="${monthEnd(-1)}"></label></div>
    <p class="muted small">Konten und Berater-/Mandantennummer unter <a href="#/einstellungen">Einstellungen</a>. Bitte vor der ersten Übergabe mit dem Steuerberater abstimmen.</p>
    <div class="row end"><button class="primary" id="dl">Herunterladen</button></div></div>`;
  $('#dl').onclick = () => download(`/datev&von=${$('#von').value}&bis=${$('#bis').value}`, 'EXTF_MeoloVEND.csv').catch(fail);
});
