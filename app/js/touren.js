'use strict';
// Touren: Tourenplan mit Ladeliste, Besuch erfassen (Zähler, Kassierung, Befüllung), Besuchsliste mit Korrekturen, EVA-DTS-Audits

route('/touren', async (_, app) => {
  setTitle('Tourenplan', S.meta.resources.touren?.write ? '<a class="btn" href="#/r/touren">Touren verwalten</a>' : '');
  const touren = await GET('/r/touren');
  if (!touren.length) { app.innerHTML = '<div class="panel"><p>Noch keine Touren angelegt. <a href="#/r/touren">Tour anlegen</a></p></div>'; return; }
  const sel = hashParams().get('tour') || touren[0].id;
  app.innerHTML = `<div class="panel"><div class="row"><label class="inline">Tour <select id="tour">${touren.map((t) => `<option value="${t.id}"${t.id === sel ? ' selected' : ''}>${esc(t.name)}${t.wochentage ? ' (' + esc(t.wochentage) + ')' : ''}</option>`).join('')}</select></label><button id="druck">Drucken</button></div></div><div id="plan"></div>`;
  $('#tour').onchange = () => { location.hash = '#/touren?tour=' + $('#tour').value; };
  $('#druck').onclick = () => window.print();
  const p = await GET(`/touren/${sel}/plan`);
  $('#plan').innerHTML = `<div class="panel"><h3>${esc(p.tour.name)} · Fahrer: ${esc(p.tour.mitarbeiter_id__label || '–')} · Fahrzeug: ${esc(p.tour.fahrzeug_id__label || '–')}</h3>
    ${table([{ key: 'nummer', label: 'Nr.' }, { key: 'bezeichnung', label: 'Automat' }, { key: 'standort', label: 'Standort', fmt: (r) => esc(r.standort || '') + (r.gebaeude ? '<div class="muted small">' + esc(r.gebaeude) + '</div>' : '') },
      { key: 'zugang', label: 'Zugang', cls: 'small' }, { key: 'letzter_besuch', label: 'Letzter Besuch', type: 'date' }, { key: 'tageSeit', label: 'Tage', type: 'int' },
      { key: 'bedarfGeschaetzt', label: 'Bedarf (geschätzt)', type: 'int', fmt: (r) => (r.bedarfGeschaetzt == null ? '' : int(r.bedarfGeschaetzt) + ' Stk.') },
      { key: 'stoerungen', label: 'Störungen', type: 'int', fmt: (r) => (r.stoerungen ? `<span class="pill warn">${r.stoerungen}</span>` : '') },
      { key: 'x', label: '', fmt: (r) => (can('touren', true) ? `<a class="btn small" href="#/besuch?automat=${r.id}">Besuch</a>` : '') }],
    p.automaten, { onRow: (r) => (location.hash = '#/automat/' + r.id), empty: 'Dieser Tour sind keine aktiven Automaten zugeordnet' })}</div>
    <div class="panel"><h3>Ladeliste: Bestand im Fahrzeug</h3>${table([{ key: 'artikelnr', label: 'Art.-Nr.' }, { key: 'bezeichnung', label: 'Artikel' }, { key: 'menge', label: 'Menge', type: 'num' }, { key: 'wert', label: 'Wert (EK)', type: 'money', sum: true }],
      p.fahrzeugBestand, { empty: p.tour.fahrzeug_id ? 'Fahrzeug ist leer' : 'Der Tour ist kein Fahrzeug zugeordnet' })}
      ${can('waren', true) && p.tour.fahrzeug_id ? `<a class="btn" href="#/bewegungen?neu=umlagerung&nach=${p.tour.fahrzeug_id}">Fahrzeug beladen</a>` : ''}</div>`;
});

// ---------- Besuch erfassen ----------

route('/besuch', async (_, app, aktuell) => {
  const p = hashParams();
  const id = p.get('id');
  let besuch = id ? await GET('/besuche/' + id) : null;
  const automatId = besuch?.automat_id || p.get('automat') || '';
  setTitle(besuch ? 'Besuch bearbeiten' : 'Besuch erfassen');
  const [automaten, mitarbeiter, lager] = await Promise.all([auswahl('automaten'), auswahl('mitarbeiter'), GET('/r/lager')]);
  if (!aktuell()) return;
  app.innerHTML = `<div class="panel narrow"><label>Automat <select id="am"><option value="">Bitte wählen …</option>${automaten.map((a) => `<option value="${a.id}"${a.id === automatId ? ' selected' : ''}>${esc(a.label)}</option>`).join('')}</select></label></div><div id="bf"></div>`;
  $('#am').onchange = () => { location.hash = '#/besuch?automat=' + $('#am').value; };
  if (!automatId) return;
  const v = await GET('/besuche-vorlage/' + automatId);
  if (!aktuell()) return;
  const meinMa = S.meta.user.mitarbeiterId;
  const tour = v.tour;
  const vorher = besuch ? null : v.vorher;
  const b = besuch || { datum: today(), mitarbeiter_id: meinMa || tour?.mitarbeiter_id || '', fahrzeug_id: tour?.fahrzeug_id || '' };
  const fill = {};
  for (const x of besuch?.positionen || []) if (x.art === 'befuellung') fill[x.fach + '|' + x.artikel_id] = x.menge;
  const val = (x) => (x == null ? '' : String(x).replace('.', ','));
  $('#bf').innerHTML = `<form id="bform" class="stack">
    ${besuch?.gesperrt ? '<div class="panel warn-box">Dieser Besuch liegt in einem abgeschlossenen Zeitraum und kann nicht mehr geändert werden. Bitte eine Korrekturbuchung anlegen.</div>' : ''}
    <div class="panel"><div class="grid3">
      <label>Datum <input type="date" name="datum" value="${b.datum}" required></label>
      <label>Mitarbeiter <select name="ma"><option value=""></option>${mitarbeiter.map((m) => `<option value="${m.id}"${m.id === b.mitarbeiter_id ? ' selected' : ''}>${esc(m.label)}</option>`).join('')}</select></label>
      <label>Ware aus <select name="fz"><option value="">(keine Lagerbuchung)</option>${lager.map((l) => `<option value="${l.id}"${l.id === b.fahrzeug_id ? ' selected' : ''}>${esc(l.name)}</option>`).join('')}</select></label>
    </div>${v.stoerungen.length ? `<div class="warn-box"><strong>Offene Störungen</strong>${v.stoerungen.map((s) => `<label class="check"><input type="checkbox" name="st" value="${s.id}"> erledigt: ${dateDe(s.datum)} ${esc(s.beschreibung)}</label>`).join('')}</div>` : ''}</div>

    <div class="panel"><div class="row"><h3>Zählerstände</h3><span class="spacer"></span>${v.audit && !besuch ? `<button type="button" id="auditBtn">Audit vom ${dateDe(v.audit.datum)} übernehmen</button>` : ''}</div>
      ${v.vorher ? `<p class="muted small">Letzter Besuch ${dateDe(v.vorher.datum)}: Bargeld ${v.vorher.zaehler_bar == null ? '–' : money(v.vorher.zaehler_bar)}, bargeldlos ${v.vorher.zaehler_bargeldlos == null ? '–' : money(v.vorher.zaehler_bargeldlos)}, Verkäufe ${v.vorher.zaehler_verkaeufe == null ? '–' : int(v.vorher.zaehler_verkaeufe)}</p>` : '<p class="muted small">Erster Besuch: Soll wird erst ab dem nächsten Zählerstand berechnet.</p>'}
      <div class="grid3">
        <label>Bargeld-Zähler gesamt <input name="zb" inputmode="decimal" value="${val(b.zaehler_bar)}"></label>
        <label>Bargeldlos-Zähler gesamt <input name="zl" inputmode="decimal" value="${val(b.zaehler_bargeldlos)}"></label>
        <label>Verkaufszähler gesamt <input name="zv" inputmode="numeric" value="${val(b.zaehler_verkaeufe)}"></label>
      </div>
      <label class="check"><input type="checkbox" name="reset"> Zähler wurde zurückgesetzt (Soll von Hand eingeben)</label>
      <div class="grid3" id="manuell" hidden><label>Soll bar von Hand <input name="sollM" inputmode="decimal"></label><label>Bargeldlos von Hand <input name="blM" inputmode="decimal"></label></div>
    </div>

    <div class="panel"><h3>Kassierung</h3><div class="grid3">
      <label>Münzen gezählt <input name="mu" inputmode="decimal" value="${val(b.ist_muenzen)}"></label>
      <label>Scheine gezählt <input name="sc" inputmode="decimal" value="${val(b.ist_scheine)}"></label>
      <label>Röhren: Entnahme (+) / Auffüllung (−) <input name="ro" inputmode="decimal" value="${val(b.roehren)}"></label>
    </div><div class="calc" id="calc"></div></div>

    <div class="panel"><h3>Befüllung</h3>
      ${v.faecher.length ? `<table class="tbl fill"><thead><tr><th>Fach</th><th>Artikel</th><th class="num">Kapazität</th><th class="num">zuletzt</th><th class="num">Menge</th></tr></thead><tbody>
        ${v.faecher.map((f) => `<tr><td>${esc(f.fach)}</td><td>${esc(f.artikel_id__label)}</td><td class="num">${f.kapazitaet ?? ''}</td><td class="num">${f.letzteMenge == null ? '' : int(f.letzteMenge)}</td>
          <td class="num"><input class="qty" inputmode="numeric" data-fach="${esc(f.fach)}" data-art="${f.artikel_id}" value="${val(fill[f.fach + '|' + f.artikel_id])}"></td></tr>`).join('')}</tbody></table>`
        : `<p class="muted">Für diesen Automaten sind keine Fächer angelegt. <a href="#/automat/${automatId}?tab=faecher">Fächer anlegen</a></p>`}
      <details${(besuch?.positionen || []).some((x) => x.art === 'ruecknahme') ? ' open' : ''}><summary>Rücknahmen / abgelaufene Ware</summary><div id="rueck"></div><button type="button" id="rueckAdd" class="small">+ Position</button></details>
    </div>

    <div class="panel"><div class="grid2">
      <label class="check"><input type="checkbox" name="rein"${b.gereinigt ? ' checked' : ''}> Automat gereinigt (HACCP)</label>
      <label class="full">Bemerkung <textarea name="bem" rows="2">${esc(b.bemerkung || '')}</textarea></label>
      <label class="full">Neue Störung melden <input name="stNeu" placeholder="z. B. Münzer nimmt keine 2-Euro-Stücke"></label>
    </div></div>
    <div class="row end sticky-actions">${besuch && !besuch.gesperrt ? '<button type="button" class="danger-link" id="del">Löschen</button><span class="spacer"></span>' : ''}<a class="btn" href="#/besuche">Abbrechen</a><button class="primary"${besuch?.gesperrt ? ' disabled' : ''}>Speichern</button></div>
  </form>`;
  const f = $('#bform');
  const artikel = await auswahl('artikel');
  const rueckRow = (x = {}) => {
    const d = document.createElement('div');
    d.className = 'row rueck';
    d.innerHTML = `<select class="ra"><option value=""></option>${artikel.map((a) => `<option value="${a.id}"${a.id === x.artikel_id ? ' selected' : ''}>${esc(a.label)}</option>`).join('')}</select>
      <input class="rm" inputmode="numeric" placeholder="Menge" value="${val(x.menge)}" style="width:6em"><label class="check"><input type="checkbox" class="rv"${x.ruecknahme_verderb ? ' checked' : ''}> verdorben</label><button type="button" class="link rx">entfernen</button>`;
    $('.rx', d).onclick = () => d.remove();
    $('#rueck').appendChild(d);
  };
  for (const x of besuch?.positionen || []) if (x.art === 'ruecknahme') rueckRow(x);
  $('#rueckAdd').onclick = () => rueckRow();
  f.reset.onchange = () => { $('#manuell').hidden = !f.reset.checked; calc(); };
  const calc = () => {
    const n = (x) => parseNum(x) ?? 0;
    const ist = n(f.mu.value) + n(f.sc.value) + n(f.ro.value);
    let soll = null;
    if (f.reset.checked) soll = parseNum(f.sollM.value);
    else if (parseNum(f.zb.value) != null && v.vorher?.zaehler_bar != null && !besuch) soll = parseNum(f.zb.value) - v.vorher.zaehler_bar;
    else if (besuch) soll = besuch.soll_bar;
    const diff = soll == null ? null : Math.round((ist - soll) * 100) / 100;
    $('#calc').innerHTML = `<div><span class="muted">Soll bar</span><b>${soll == null ? '= gezählt' : money(soll)}</b></div><div><span class="muted">Ist gezählt</span><b>${money(ist)}</b></div>
      <div><span class="muted">Fehlgeld</span><b class="${diff < 0 ? 'neg' : diff > 0 ? 'pos' : ''}">${diff == null ? '–' : money(diff)}</b></div>`;
  };
  f.addEventListener('input', calc);
  calc();
  if ($('#auditBtn')) $('#auditBtn').onclick = () => {
    const a = v.audit;
    if (a.bar_gesamt != null) f.zb.value = val(a.bar_gesamt);
    if (a.bargeldlos_gesamt != null) f.zl.value = val(a.bargeldlos_gesamt);
    if (a.verkaeufe_gesamt != null) f.zv.value = val(a.verkaeufe_gesamt);
    f.dataset.audit = a.id;
    calc();
    toast('Zählerstände aus dem Audit übernommen');
  };
  if ($('#del')) $('#del').onclick = async () => {
    if (!(await confirmBox('Besuch mit Befüllung und Kassierung löschen?', 'Löschen'))) return;
    try { await DEL('/besuche/' + besuch.id); toast('Gelöscht'); location.hash = '#/besuche'; } catch (e) { fail(e); }
  };
  f.onsubmit = async (e) => {
    e.preventDefault();
    const data = {
      id: besuch?.id, automatId, datum: f.datum.value, mitarbeiterId: f.ma.value, fahrzeugId: f.fz.value,
      zaehlerBar: f.zb.value, zaehlerBargeldlos: f.zl.value, zaehlerVerkaeufe: f.zv.value, zaehlerZurueckgesetzt: f.reset.checked,
      sollBar: f.reset.checked ? f.sollM.value : null, bargeldlos: f.reset.checked ? f.blM.value : null,
      istMuenzen: f.mu.value, istScheine: f.sc.value, roehren: f.ro.value, gereinigt: f.rein.checked, bemerkung: f.bem.value, auditId: f.dataset.audit || besuch?.audit_id || '',
      befuellung: $$('.qty', f).filter((x) => x.value.trim()).map((x) => ({ fach: x.dataset.fach, artikelId: x.dataset.art, menge: x.value })),
      ruecknahme: $$('.rueck', f).filter((r) => $('.ra', r).value && $('.rm', r).value).map((r) => ({ artikelId: $('.ra', r).value, menge: $('.rm', r).value, verderb: $('.rv', r).checked })),
      stoerungenErledigt: $$('input[name=st]:checked', f).map((x) => x.value),
    };
    for (const k of ['zaehlerBar', 'zaehlerBargeldlos', 'istMuenzen', 'istScheine', 'roehren', 'sollBar', 'bargeldlos']) if (data[k] != null) data[k] = parseNum(data[k]) ?? (String(data[k]).trim() === '' ? null : data[k]);
    try {
      const r = await POST('/besuche', data);
      if (f.stNeu.value.trim()) await POST('/r/stoerungen', { automat_id: automatId, datum: f.datum.value, art: 'Störung', quelle: 'Fahrer', beschreibung: f.stNeu.value.trim(), prioritaet: 'normal', status: 'offen' });
      toast(`Gespeichert · Fehlgeld ${money(r.fehlgeld)}`);
      location.hash = '#/besuche?automat=' + automatId;
      setTimeout(() => besuchDialog(r.id), 300);
    } catch (e2) { fail(e2); }
  };
});

function besucheTable(rows) {
  return table([
    { key: 'datum', label: 'Datum', type: 'date', fmt: (r) => dateDe(r.datum) + (r.korrektur_von ? ' <span class="pill">Korrektur</span>' : '') },
    { key: 'automat', label: 'Automat' }, { key: 'mitarbeiter', label: 'Mitarbeiter' }, { key: 'verkaeufe', label: 'Verkäufe', type: 'int', sum: true },
    { key: 'soll_bar', label: 'Soll bar', type: 'money', sum: true }, { key: 'ist', label: 'Ist bar', type: 'money', sum: true }, { key: 'bargeldlos', label: 'Bargeldlos', type: 'money', sum: true },
    { key: 'fehlgeld', label: 'Fehlgeld', type: 'money', sum: true, fmt: (r) => `<span class="${r.fehlgeld < 0 ? 'neg' : r.fehlgeld > 0 ? 'pos' : ''}">${money(r.fehlgeld)}</span>` },
    { key: 'umsatz', label: 'Umsatz', type: 'money', sum: true },
  ], rows, { onRow: (r) => besuchDialog(r.korrektur_von || r.id), empty: 'Keine Besuche im Zeitraum' });
}

async function besuchDialog(id) {
  const b = await GET('/besuche/' + id);
  const w = can('touren', true);
  const el = modal(`Besuch ${dateDe(b.datum)} · ${b.automat}`, `
    <div class="calc"><div><span class="muted">Soll bar</span><b>${money(b.soll_bar)}</b></div><div><span class="muted">Ist gezählt</span><b>${money(b.ist)}</b></div><div><span class="muted">Bargeldlos</span><b>${money(b.bargeldlos)}</b></div><div><span class="muted">Fehlgeld</span><b class="${b.fehlgeld < 0 ? 'neg' : ''}">${money(b.fehlgeld)}</b></div></div>
    <dl class="dl"><dt>Mitarbeiter</dt><dd>${esc(b.mitarbeiter || '–')}</dd><dt>Tour</dt><dd>${esc(b.tour || '–')}</dd>
      <dt>Zähler</dt><dd>Bargeld ${b.zaehler_bar == null ? '–' : money(b.zaehler_bar)} · bargeldlos ${b.zaehler_bargeldlos == null ? '–' : money(b.zaehler_bargeldlos)} · Verkäufe ${b.zaehler_verkaeufe == null ? '–' : int(b.zaehler_verkaeufe)}</dd>
      <dt>Kasse</dt><dd>Münzen ${money(b.ist_muenzen)} · Scheine ${money(b.ist_scheine)} · Röhren ${money(b.roehren)}</dd>
      <dt>Gereinigt</dt><dd>${b.gereinigt ? 'ja' : 'nein'}</dd>${b.bemerkung ? `<dt>Bemerkung</dt><dd>${esc(b.bemerkung)}</dd>` : ''}<dt>Erfasst von</dt><dd>${esc(b.benutzer)}</dd></dl>
    ${b.positionen.length ? `<h3>Ware</h3>${table([{ key: 'art', label: 'Art', fmt: (r) => (r.art === 'befuellung' ? 'Befüllung' : 'Rücknahme') }, { key: 'fach', label: 'Fach' }, { key: 'artikel', label: 'Artikel' }, { key: 'menge', label: 'Menge', type: 'num' }], b.positionen)}` : ''}
    ${b.korrekturen.length ? `<h3>Korrekturen</h3>${table([{ key: 'datum', label: 'Datum', type: 'date' }, { key: 'soll_bar', label: 'Soll', type: 'money' }, { key: 'ist', label: 'Ist', type: 'money' }, { key: 'bargeldlos', label: 'Bargeldlos', type: 'money' }, { key: 'bemerkung', label: 'Grund' }, { key: 'benutzer', label: 'von' }], b.korrekturen)}` : ''}
    <div class="row end">${b.gesperrt ? `<span class="muted small">abgeschlossen</span><span class="spacer"></span>${can('einnahmen', true) ? '<button id="korrBtn">Korrekturbuchung</button>' : ''}` : w ? `<a class="btn" href="#/besuch?id=${b.id}">Bearbeiten</a>` : ''}<button id="closeB">Schließen</button></div>`, true);
  $('#closeB', el).onclick = closeModal;
  if ($('#korrBtn', el)) $('#korrBtn', el).onclick = () => {
    const k = modal('Korrekturbuchung', `<form class="stack" id="kf"><p class="muted">Beträge als Differenz eingeben (z. B. Ist +2,50 für nachträglich gefundenes Geld). Die Korrektur wird mit heutigem Datum im offenen Zeitraum gebucht.</p>
      <div class="grid3"><label>Soll bar ± <input name="soll" inputmode="decimal"></label><label>Ist bar ± <input name="ist" inputmode="decimal"></label><label>Bargeldlos ± <input name="bl" inputmode="decimal"></label></div>
      <label>Datum <input type="date" name="datum" value="${today()}"></label><label>Grund * <input name="grund" required></label>
      <div class="row end"><button type="button" id="kb">Zurück</button><button class="primary">Buchen</button></div></form>`);
    $('#kb', k).onclick = () => besuchDialog(id);
    $('#kf', k).onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      try { await POST(`/besuche/${id}/korrektur`, { sollBar: parseNum(f.soll.value), ist: parseNum(f.ist.value), bargeldlos: parseNum(f.bl.value), datum: f.datum.value, grund: f.grund.value }); toast('Korrektur gebucht'); besuchDialog(id); rerender(); } catch (e2) { fail(e2); }
    };
  };
}

route('/besuche', async (_, app) => {
  const p = hashParams();
  setTitle('Besuche', `${can('touren', true) ? '<a class="btn primary" href="#/besuch">+ Besuch</a>' : ''}<button id="csvBtn">CSV</button>`);
  const [automaten, touren] = await Promise.all([auswahl('automaten'), auswahl('touren')]);
  app.innerHTML = `<div class="panel"><div class="row filters">
    <label class="inline">von <input type="date" id="von" value="${p.get('von') || monthStart(-1)}"></label><label class="inline">bis <input type="date" id="bis" value="${p.get('bis') || today()}"></label>
    <select id="am"><option value="">alle Automaten</option>${automaten.map((a) => `<option value="${a.id}"${p.get('automat') === a.id ? ' selected' : ''}>${esc(a.label)}</option>`).join('')}</select>
    <select id="tr"><option value="">alle Touren</option>${touren.map((a) => `<option value="${a.id}"${p.get('tour') === a.id ? ' selected' : ''}>${esc(a.label)}</option>`).join('')}</select>
    <label class="check"><input type="checkbox" id="fg"${p.get('fehlgeld') ? ' checked' : ''}> nur mit Fehlgeld</label></div><div id="list"></div></div>`;
  const load = async () => {
    const q = { von: $('#von').value, bis: $('#bis').value, automat: $('#am').value, tour: $('#tr').value, fehlgeld: $('#fg').checked ? '1' : '' };
    setParams(q);
    const rows = await GET('/besuche&' + new URLSearchParams(q));
    $('#list').innerHTML = besucheTable(rows);
    $('#csvBtn').onclick = () => csv([{ key: 'datum', label: 'Datum' }, { key: 'automat', label: 'Automat' }, { key: 'mitarbeiter', label: 'Mitarbeiter' }, { key: 'tour', label: 'Tour' }, { key: 'zaehler_bar', label: 'Zähler bar' }, { key: 'zaehler_bargeldlos', label: 'Zähler bargeldlos' },
      { key: 'verkaeufe', label: 'Verkäufe' }, { key: 'soll_bar', label: 'Soll bar' }, { key: 'ist_muenzen', label: 'Münzen' }, { key: 'ist_scheine', label: 'Scheine' }, { key: 'roehren', label: 'Röhren' }, { key: 'bargeldlos', label: 'Bargeldlos' }, { key: 'fehlgeld', label: 'Fehlgeld' }, { key: 'umsatz', label: 'Umsatz' }, { key: 'bemerkung', label: 'Bemerkung' }], rows, 'Besuche');
  };
  $$('.filters input, .filters select', app).forEach((x) => { x.onchange = () => load().catch(fail); });
  await load();
});

// ---------- EVA-DTS-Audits ----------

route('/audits', async (_, app) => {
  setTitle('Audits (EVA-DTS)');
  const rows = await GET('/audits');
  app.innerHTML = `${can('touren', true) ? `<div class="panel"><h3>Audit einlesen</h3><p class="muted">EVA-DTS-Dateien (DEX/DDCMP) vom Handheld, Kartenleser oder Telemetrie-Export. Der Automat wird über die Seriennummer (ID1) und die Telemetrie-ID erkannt. Beim nächsten Besuch lassen sich die Zählerstände übernehmen.</p>
    <div class="row"><label class="btn">Dateien wählen<input type="file" id="files" multiple hidden accept=".txt,.dex,.eva,.dat,text/plain"></label><span class="muted">oder Text einfügen:</span></div>
    <textarea id="txt" rows="5" placeholder="DXS*…&#10;ID1*…&#10;VA1*…"></textarea><div class="row end"><button class="primary" id="go">Einlesen</button></div><div id="res"></div></div>` : ''}
    <div class="panel"><h3>Eingelesene Audits</h3>${table([{ key: 'datum', label: 'Eingelesen', fmt: (r) => dateDe(r.datum) }, { key: 'geraet_id', label: 'Geräte-ID' }, { key: 'automat', label: 'Automat', fmt: (r) => (r.automat ? esc(r.automat) : '<span class="neg">nicht zugeordnet</span>') },
      { key: 'verkaeufe_gesamt', label: 'Verkäufe gesamt', type: 'int' }, { key: 'bar_gesamt', label: 'Bar gesamt', type: 'money' }, { key: 'bargeldlos_gesamt', label: 'Bargeldlos gesamt', type: 'money' }, { key: 'besuch_id', label: 'Besuch', fmt: (r) => (r.besuch_id ? 'übernommen' : '') }], rows)}</div>`;
  if (!$('#go')) return;
  const send = async (text, quelle) => {
    const r = await POST('/audits', { text, quelle });
    return `<li>${esc(quelle)}: ${esc(r.geraetId || '?')} → ${r.automatId ? 'zugeordnet' : '<span class="neg">kein Automat mit dieser ID</span>'} · Bar ${money(r.bar)} · bargeldlos ${money(r.bargeldlos)} · ${int(r.verkaeufe)} Verkäufe · ${r.faecher.length} Fächer</li>`;
  };
  $('#go').onclick = async () => {
    try { $('#res').innerHTML = `<ul>${await send($('#txt').value, 'Eingabe')}</ul>`; toast('Eingelesen'); setTimeout(rerender, 1200); } catch (e) { fail(e); }
  };
  $('#files').onchange = async (e) => {
    const out = [];
    for (const file of e.target.files) { try { out.push(await send(await file.text(), file.name)); } catch (e2) { out.push(`<li class="neg">${esc(file.name)}: ${esc(e2.message)}</li>`); } }
    $('#res').innerHTML = `<ul>${out.join('')}</ul>`;
    setTimeout(rerender, 1500);
  };
});
