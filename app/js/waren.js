'use strict';
// Waren: Bestand je Lager und Fahrzeug, Buchungen (Wareneingang, Umlagerung, Schwund), Inventur, Bestellvorschlag

const BW_ART = { wareneingang: 'Wareneingang', umlagerung: 'Umlagerung', schwund: 'Schwund / Verderb', entnahme: 'Entnahme (Eigenverbrauch, Muster)' };

route('/bestand', async (_, app) => {
  setTitle('Bestand', '<button id="csvBtn">CSV</button>');
  const lager = await GET('/r/lager');
  const sel = hashParams().get('lager') || '';
  app.innerHTML = `<div class="panel"><div class="row"><select id="lg"><option value="">alle Lager und Fahrzeuge</option>${lager.map((l) => `<option value="${l.id}"${l.id === sel ? ' selected' : ''}>${esc(l.name)}</option>`).join('')}</select>
    ${can('waren', true) ? '<a class="btn" href="#/bewegungen?neu=wareneingang">+ Wareneingang</a><a class="btn" href="#/bewegungen?neu=umlagerung">Umlagern</a>' : ''}</div><div id="list"></div></div>`;
  $('#lg').onchange = () => { location.hash = '#/bestand?lager=' + $('#lg').value; };
  const rows = await GET('/bestand' + (sel ? '&lager=' + sel : ''));
  const cols = [{ key: 'lager', label: 'Lager' }, { key: 'artikelnr', label: 'Art.-Nr.' }, { key: 'bezeichnung', label: 'Artikel' }, { key: 'menge', label: 'Menge', type: 'num', sum: true, fmt: (r) => `<span class="${r.menge < 0 ? 'neg' : ''}">${fmt(r.menge, r.menge % 1 ? 2 : 0)}</span>` },
    { key: 'einheit', label: 'Einheit' }, { key: 'ek', label: 'EK', type: 'money' }, { key: 'wert', label: 'Wert', type: 'money', sum: true }];
  $('#list').innerHTML = table(cols, rows, { onRow: (r) => (location.hash = `#/bewegungen?artikel=${r.artikel_id}&lager=${r.lager_id}`), empty: 'Kein Bestand' });
  $('#csvBtn').onclick = () => csv(cols, rows, 'Bestand');
});

route('/bewegungen', async (_, app) => {
  const p = hashParams();
  setTitle('Warenbuchungen', `${can('waren', true) ? '<button class="primary" id="neuBtn">+ Buchung</button>' : ''}<button id="csvBtn">CSV</button>`);
  const [lager, artikel] = await Promise.all([GET('/r/lager'), auswahl('artikel')]);
  app.innerHTML = `<div class="panel"><div class="row filters">
    <label class="inline">von <input type="date" id="von" value="${p.get('von') || monthStart(-2)}"></label><label class="inline">bis <input type="date" id="bis" value="${p.get('bis') || today()}"></label>
    <select id="lg"><option value="">alle Lager</option>${lager.map((l) => `<option value="${l.id}"${p.get('lager') === l.id ? ' selected' : ''}>${esc(l.name)}</option>`).join('')}</select>
    <select id="ar"><option value="">alle Artikel</option>${artikel.map((a) => `<option value="${a.id}"${p.get('artikel') === a.id ? ' selected' : ''}>${esc(a.label)}</option>`).join('')}</select>
    <select id="art"><option value="">alle Arten</option>${Object.entries({ ...BW_ART, befuellung: 'Befüllung Automat', ruecknahme: 'Rücknahme aus Automat', inventur: 'Inventurdifferenz' }).map(([k, t]) => `<option value="${k}"${p.get('art') === k ? ' selected' : ''}>${t}</option>`).join('')}</select></div><div id="list"></div></div>`;
  const cols = [{ key: 'datum', label: 'Datum', type: 'date' }, { key: 'artText', label: 'Art' }, { key: 'artikel', label: 'Artikel' }, { key: 'menge', label: 'Menge', type: 'num' },
    { key: 'von_lager', label: 'von', fmt: (r) => esc(r.von_lager || (r.art === 'ruecknahme' ? r.automat : '') || '') }, { key: 'nach_lager', label: 'nach', fmt: (r) => esc(r.nach_lager || (r.art === 'befuellung' ? r.automat + (r.fach ? ' · ' + r.fach : '') : '') || '') },
    { key: 'ek', label: 'EK', type: 'money' }, { key: 'beleg', label: 'Beleg' }, { key: 'text', label: 'Text' }, { key: 'benutzer', label: 'von' }];
  const load = async () => {
    const q = { von: $('#von').value, bis: $('#bis').value, lager: $('#lg').value, artikel: $('#ar').value, art: $('#art').value };
    const rows = await GET('/bewegungen&' + new URLSearchParams(q));
    $('#list').innerHTML = table(cols, rows, { onRow: (r) => bewegungDialog(r, load) });
    $('#csvBtn').onclick = () => csv(cols.map((c) => ({ ...c, csv: (r) => r[c.key] })), rows, 'Warenbuchungen');
  };
  $$('.filters input, .filters select', app).forEach((x) => { x.onchange = () => load().catch(fail); });
  if ($('#neuBtn')) $('#neuBtn').onclick = () => buchungDialog({}, lager, load);
  await load();
  if (p.get('neu') && can('waren', true)) buchungDialog({ art: p.get('neu'), nach: p.get('nach') }, lager, load);
});

async function bewegungDialog(r, done) {
  const el = modal('Buchung', `<dl class="dl"><dt>Datum</dt><dd>${dateDe(r.datum)}</dd><dt>Art</dt><dd>${esc(r.artText)}</dd><dt>Artikel</dt><dd>${esc(r.artikel)}</dd><dt>Menge</dt><dd>${fmt(r.menge, 0)}</dd>
    <dt>von / nach</dt><dd>${esc(r.von_lager || '–')} → ${esc(r.nach_lager || r.automat || '–')}</dd><dt>Text</dt><dd>${esc(r.text || '–')}</dd><dt>Erfasst</dt><dd>${esc(r.benutzer)}</dd></dl>
    <div class="row end">${r.besuch_id ? '<span class="muted small">gehört zu einem Besuch</span>' : can('waren', true) ? '<button class="danger-link" id="del">Stornieren</button>' : ''}<button id="cl">Schließen</button></div>`);
  $('#cl', el).onclick = closeModal;
  if ($('#del', el)) $('#del', el).onclick = async () => { try { await DEL('/bewegungen/' + r.id); closeModal(); toast('Storniert'); done(); } catch (e) { fail(e); } };
}

async function buchungDialog(v, lager, done) {
  const artikel = (await GET('/r/artikel')).filter((a) => a.aktiv);
  const opt = (sel) => `<option value=""></option>${lager.map((l) => `<option value="${l.id}"${l.id === sel ? ' selected' : ''}>${esc(l.name)}</option>`).join('')}`;
  const b = modal('Warenbuchung', `<form class="stack" id="bf">
    <div class="grid3"><label>Art <select name="art">${Object.entries(BW_ART).map(([k, t]) => `<option value="${k}"${k === v.art ? ' selected' : ''}>${t}</option>`).join('')}</select></label>
      <label>Datum <input type="date" name="datum" value="${today()}" required></label><label>Beleg / Lieferschein <input name="beleg"></label>
      <label id="vonL">aus Lager <select name="von">${opt(v.von)}</select></label><label id="nachL">in Lager <select name="nach">${opt(v.nach || lager.find((l) => l.art === 'Lager')?.id)}</select></label><label>Text <input name="text"></label></div>
    <table class="tbl"><thead><tr><th>Artikel</th><th class="num">Menge</th><th class="num ekc">EK netto</th><th class="ekc">MHD</th></tr></thead><tbody id="pos"></tbody></table>
    <button type="button" class="small" id="add">+ Zeile</button>
    <label class="check ekc"><input type="checkbox" name="ekU" checked> neuen EK in den Artikel übernehmen</label>
    <div class="row end"><button type="button" id="cancel">Abbrechen</button><button class="primary">Buchen</button></div></form>`, true);
  const f = $('#bf', b);
  const row = () => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><select class="pa"><option value=""></option>${artikel.map((a) => `<option value="${a.id}" data-ek="${a.ek ?? ''}">${esc(a.artikelnr + ' ' + a.bezeichnung)}</option>`).join('')}</select></td>
      <td class="num"><input class="pm" inputmode="decimal" style="width:6em"></td><td class="num ekc"><input class="pe" inputmode="decimal" style="width:6em"></td><td class="ekc"><input type="date" class="pd"></td>`;
    $('.pa', tr).onchange = (e) => { const ek = e.target.selectedOptions[0]?.dataset.ek; if (ek && !$('.pe', tr).value) $('.pe', tr).value = String(ek).replace('.', ','); if (tr === $('#pos', b).lastElementChild) row(); };
    $('#pos', b).appendChild(tr);
    $$('.ekc', tr).forEach((x) => { x.hidden = f.art.value !== 'wareneingang'; });
  };
  row();
  $('#add', b).onclick = row;
  $('#cancel', b).onclick = closeModal;
  const sync = () => {
    const a = f.art.value;
    $('#vonL', b).hidden = a === 'wareneingang';
    $('#nachL', b).hidden = a === 'schwund' || a === 'entnahme';
    $$('.ekc', b).forEach((x) => { x.hidden = a !== 'wareneingang'; });
  };
  f.art.onchange = sync;
  sync();
  f.onsubmit = async (e) => {
    e.preventDefault();
    const positionen = $$('#pos tr', b).filter((tr) => $('.pa', tr).value && $('.pm', tr).value).map((tr) => ({ artikelId: $('.pa', tr).value, menge: parseNum($('.pm', tr).value), ek: parseNum($('.pe', tr).value), mhd: $('.pd', tr).value }));
    try {
      const r = await POST('/bewegungen', { art: f.art.value, datum: f.datum.value, vonLager: f.von.value, nachLager: f.nach.value, beleg: f.beleg.value, text: f.text.value, positionen, ekUebernehmen: f.ekU.checked });
      closeModal(); toast(r.positionen + ' Position(en) gebucht'); done();
    } catch (e2) { fail(e2); }
  };
}

route('/inventur', async (_, app) => {
  setTitle('Inventur');
  const lager = await GET('/r/lager');
  const sel = hashParams().get('lager') || lager[0]?.id || '';
  app.innerHTML = `<div class="panel"><div class="row"><label class="inline">Lager <select id="lg">${lager.map((l) => `<option value="${l.id}"${l.id === sel ? ' selected' : ''}>${esc(l.name)}</option>`).join('')}</select></label>
    <label class="inline">Stichtag <input type="date" id="dt" value="${today()}"></label><button id="druck">Zählliste drucken</button></div><div id="list"></div></div>`;
  $('#lg').onchange = () => { location.hash = '#/inventur?lager=' + $('#lg').value; };
  $('#druck').onclick = () => window.print();
  if (!sel) return;
  const [artikel, bestand] = await Promise.all([GET('/r/artikel'), GET('/bestand&lager=' + sel)]);
  const soll = Object.fromEntries(bestand.map((x) => [x.artikel_id, x.menge]));
  const rows = artikel.filter((a) => a.aktiv || soll[a.id]);
  $('#list').innerHTML = `<form id="inv"><table class="tbl"><thead><tr><th>Art.-Nr.</th><th>Artikel</th><th class="num">Soll</th><th class="num">Gezählt</th><th class="num">Differenz</th></tr></thead><tbody>
    ${rows.map((a) => `<tr><td>${esc(a.artikelnr)}</td><td>${esc(a.bezeichnung)}</td><td class="num">${fmt(soll[a.id] || 0, 0)}</td><td class="num"><input class="cnt" data-id="${a.id}" data-soll="${soll[a.id] || 0}" inputmode="decimal" style="width:6em"></td><td class="num diff"></td></tr>`).join('')}
    </tbody></table><p class="muted small">Leere Felder bleiben unverändert. Die Differenz wird als Inventurbuchung zum Stichtag gebucht.</p>
    ${can('waren', true) ? '<div class="row end"><button class="primary">Inventur buchen</button></div>' : ''}</form>`;
  $('#inv').addEventListener('input', (e) => {
    const i = e.target.closest('.cnt');
    if (!i) return;
    const n = parseNum(i.value);
    const d = n == null ? '' : n - Number(i.dataset.soll);
    i.closest('tr').querySelector('.diff').innerHTML = d === '' ? '' : `<span class="${d < 0 ? 'neg' : d > 0 ? 'pos' : ''}">${fmt(d, 0)}</span>`;
  });
  $('#inv').onsubmit = async (e) => {
    e.preventDefault();
    const zaehlung = $$('.cnt').filter((i) => i.value.trim() !== '').map((i) => ({ artikelId: i.dataset.id, menge: parseNum(i.value) }));
    if (!zaehlung.length) return toast('Keine Mengen eingegeben', true);
    if (!(await confirmBox(`${zaehlung.length} gezählte Artikel buchen?`, 'Buchen'))) return;
    try { const r = await POST('/inventur', { lager: sel, datum: $('#dt').value, zaehlung }); toast(r.differenzen.length + ' Differenz(en) gebucht'); rerender(); } catch (e2) { fail(e2); }
  };
});

route('/bestellvorschlag', async (_, app) => {
  setTitle('Bestellvorschlag', '<button id="csvBtn">CSV</button>');
  const rows = await GET('/bestellvorschlag');
  const cols = [{ key: 'lieferant', label: 'Lieferant' }, { key: 'artikelnr', label: 'Art.-Nr.' }, { key: 'bezeichnung', label: 'Artikel' }, { key: 'verbrauch4w', label: 'Verbrauch 4 Wochen', type: 'int' },
    { key: 'bestand', label: 'Bestand gesamt', type: 'int' }, { key: 'vorschlag', label: 'Vorschlag', type: 'int' }, { key: 'wert', label: 'Wert (EK)', type: 'money', sum: true, fmt: (r) => money(r.vorschlag * r.ek) }];
  rows.forEach((r) => { r.wert = r.vorschlag * r.ek; });
  app.innerHTML = `<div class="panel"><p class="muted">Artikel, deren Bestand in Lager und Fahrzeugen unter dem Verbrauch der letzten vier Wochen liegt. Vorschlag: Verbrauch × 1,5 minus Bestand.</p>${table(cols, rows, { empty: 'Alles ausreichend auf Lager' })}</div>`;
  $('#csvBtn').onclick = () => csv(cols, rows, 'Bestellvorschlag');
});
