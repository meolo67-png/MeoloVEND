'use strict';
// Automaten: Liste, Automatenakte mit Komponenten (Baukasten), Fächern, Besuchen, Störungen, Kosten, Verträgen; Komponenten und Verschleißteile

const KT = () => S.meta.komponententypen;

route('/automaten', async (_, app) => {
  const m = S.meta.resources.automaten;
  setTitle('Automaten', `${m.write ? '<button class="primary" id="neuBtn">+ Neuer Automat</button>' : ''}<button id="csvBtn">CSV</button>`);
  const p = hashParams();
  app.innerHTML = `<div class="panel"><div class="row"><input type="search" id="q" placeholder="Nummer, Bezeichnung …" value="${esc(p.get('q') || '')}">
    <select id="st"><option value="">alle Status</option>${Object.keys(m.fields.find((f) => f.name === 'status').opts).map((s) => `<option${p.get('status') === s ? ' selected' : ''}>${esc(s)}</option>`).join('')}</select><span class="muted" id="cnt"></span></div><div id="list"></div></div>`;
  const fields = Object.fromEntries(m.fields.map((f) => [f.name, f]));
  const cols = m.list.map((k) => fieldCol(fields[k]));
  const load = async () => {
    const q = $('#q').value.trim(), st = $('#st').value;
    setParams({ q, status: st });
    const rows = await GET('/r/automaten' + (q ? '&q=' + encodeURIComponent(q) : '') + (st ? '&f_status=' + encodeURIComponent(st) : ''));
    $('#cnt').textContent = rows.length + ' Automaten';
    $('#list').innerHTML = table(cols, rows, { onRow: (r) => (location.hash = '#/automat/' + r.id) });
    $('#csvBtn').onclick = () => csv(cols.map((c) => ({ ...c, csv: (r) => r[c.key + '__label'] ?? r[c.key] })), rows, 'Automaten');
  };
  let t;
  $('#q').oninput = () => { clearTimeout(t); t = setTimeout(() => load().catch(fail), 250); };
  $('#st').onchange = () => load().catch(fail);
  if ($('#neuBtn')) $('#neuBtn').onclick = () => editRecord('automaten', null, { defaults: { status: 'aktiv', mwst: S.meta.settings.mwst }, onSaved: (a) => { location.hash = '#/automat/' + a.id; } });
  await load();
});

route('/automat/:id', async ({ id }, app) => {
  const d = await GET(`/automaten/${id}/uebersicht`);
  const a = d.automat;
  const w = S.meta.resources.automaten.write;
  const tab = hashParams().get('tab') || 'uebersicht';
  setTitle(`Automat ${a.nummer} · ${a.bezeichnung}`, `${can('touren', true) ? `<a class="btn primary" href="#/besuch?automat=${a.id}">+ Besuch</a>` : ''}${w ? '<button id="editBtn">Stammdaten</button>' : ''}`);
  const tabs = [['uebersicht', 'Übersicht'], ['komponenten', 'Baukasten'], ['faecher', 'Fächer'], ['besuche', 'Besuche'], ['stoerungen', 'Störungen'], ['kosten', 'Kosten'], ['vertraege', 'Verträge / Rabatte'], ['historie', 'Historie'], ['dokumente', 'Dokumente']];
  app.innerHTML = `<div class="tabs">${tabs.map(([k, t]) => `<a href="#/automat/${a.id}?tab=${k}" class="${k === tab ? 'active' : ''}">${t}</a>`).join('')}</div><div id="tabBody"></div>`;
  if ($('#editBtn')) $('#editBtn').onclick = () => editRecord('automaten', a);
  const body = $('#tabBody');
  const reload = () => rerender();

  if (tab === 'uebersicht') {
    const m0 = d.monate.find((x) => x.monat === today().slice(0, 7));
    const st = d.letzterBesuch;
    body.innerHTML = `<div class="kpis">
        ${kpi('Umsatz diesen Monat', money(m0?.umsatz || 0), a.planumsatz ? 'Plan ' + money(a.planumsatz) : '')}
        ${kpi('Letzter Besuch', st ? dateDe(st.datum) : '–', st ? 'Soll ' + money(st.soll_bar) + ' · Fehlgeld ' + money(st.fehlgeld) : '')}
        ${kpi('Offene Störungen', int(d.offeneStoerungen), '', d.offeneStoerungen ? 'warn' : '')}
        ${kpi('Investition (Komponenten)', money(d.investition))}
      </div>
      <div class="cols2">
        <div class="panel"><h3>Umsatz je Monat</h3>${barChart(d.monate, { valueKey: 'umsatz', labelFmt: (r) => monatDe(r.monat) })}</div>
        <div class="panel"><h3>Stammdaten</h3><dl class="dl">
          <dt>Status</dt><dd>${esc(a.status)}</dd><dt>Art</dt><dd>${esc(a.art)}</dd>
          <dt>Kunde</dt><dd>${d.kunde ? esc(d.kunde.kundennr + ' ' + d.kunde.firma) : '–'}</dd><dt>Standort</dt><dd>${esc(a.standort_id__label || '–')}</dd>
          <dt>Tour</dt><dd>${esc(a.tour_id__label || '–')}</dd><dt>Aufgestellt</dt><dd>${dateDe(a.aufgestellt_am) || '–'}</dd>
          <dt>Telemetrie-ID</dt><dd>${esc(a.telemetrie_id || '–')}</dd><dt>MwSt.</dt><dd>${a.mwst ?? ''} %</dd>
          ${a.bemerkung ? `<dt>Bemerkung</dt><dd>${esc(a.bemerkung)}</dd>` : ''}</dl>
          ${d.verschleiss.length ? `<h3>Verschleiß</h3>${d.verschleiss.map((v) => `<div>${esc(v.bezeichnung)} ${verbrauchBar(v.verbraucht)}</div>`).join('')}` : ''}
        </div>
      </div>`;
    chartTips(body);
  }

  if (tab === 'komponenten') {
    const wk = S.meta.resources.komponenten?.write;
    const ordnung = Object.keys(KT());
    const rows = [...d.komponenten].sort((x, y) => ordnung.indexOf(x.typ) - ordnung.indexOf(y.typ));
    body.innerHTML = `<div class="panel"><div class="row"><h3>Eingebaute Komponenten</h3><span class="spacer"></span>${wk ? '<button class="primary" id="einBtn">+ Komponente einbauen</button>' : ''}</div>
      ${table([{ key: 'typ', label: 'Typ', fmt: (r) => esc(KT()[r.typ] || r.typ) }, { key: 'bezeichnung', label: 'Bezeichnung' }, { key: 'seriennr', label: 'Seriennr.' },
        { key: 'hersteller_id', label: 'Hersteller', fmt: (r) => esc(r.hersteller_id__label || '') }, { key: 'eingebaut_am', label: 'Eingebaut', type: 'date' }, { key: 'kaufpreis', label: 'Kaufpreis', type: 'money', sum: true }],
        rows, { onRow: (r) => komponenteDialog(r.id, reload), empty: 'Noch keine Komponenten eingebaut' })}</div>`;
    if ($('#einBtn')) $('#einBtn').onclick = () => einbauDialog(a.id, reload);
  }

  if (tab === 'faecher') {
    const wf = S.meta.resources.faecher?.write;
    body.innerHTML = `<div class="panel"><div class="row"><h3>Fachbelegung (Planogramm)</h3><span class="spacer"></span>${wf ? '<button class="primary" id="fachBtn">+ Fach</button>' : ''}</div>
      ${table([{ key: 'fach', label: 'Fach' }, { key: 'artikel_id', label: 'Artikel', fmt: (r) => esc(r.artikel_id__label) }, { key: 'preis', label: 'Preis', type: 'money' },
        { key: 'kapazitaet', label: 'Kapazität', type: 'int' }, { key: 'meldebestand', label: 'Meldebestand', type: 'int' }, { key: 'letzteBefuellung', label: 'Zuletzt befüllt', type: 'date' }, { key: 'letzteMenge', label: 'Menge', type: 'int' }],
        d.faecher, { onRow: (r) => editRecord('faecher', r, { fixed: { automat_id: a.id }, onSaved: reload }), empty: 'Noch keine Fächer angelegt' })}</div>`;
    if ($('#fachBtn')) $('#fachBtn').onclick = () => editRecord('faecher', null, { fixed: { automat_id: a.id }, onSaved: reload });
  }

  if (tab === 'besuche') {
    const rows = await GET('/besuche&automat=' + a.id);
    body.innerHTML = `<div class="panel">${besucheTable(rows)}</div>`;
  }

  if (tab === 'stoerungen' || tab === 'kosten') {
    const res = tab;
    const rows = await GET(`/r/${res}&f_automat_id=${a.id}`);
    const m = S.meta.resources[res];
    const fields = Object.fromEntries(m.fields.map((f) => [f.name, f]));
    body.innerHTML = `<div class="panel"><div class="row"><h3>${esc(m.title)}</h3><span class="spacer"></span>${m.write ? '<button class="primary" id="neuBtn">+ Neu</button>' : ''}</div>
      ${table(m.list.filter((k) => k !== 'automat_id').map((k) => ({ ...fieldCol(fields[k]), sum: res === 'kosten' && k === 'betrag' })), rows, { onRow: (r) => editRecord(res, r, { fixed: { automat_id: a.id }, onSaved: reload }) })}</div>`;
    if ($('#neuBtn')) $('#neuBtn').onclick = () => editRecord(res, null, { fixed: { automat_id: a.id }, defaults: { datum: today(), status: 'offen', prioritaet: 'normal' }, onSaved: reload });
  }

  if (tab === 'vertraege') {
    const [vt, rb] = await Promise.all([can('rabatte') ? GET('/r/vertraege&f_automat_id=' + a.id) : [], can('rabatte') ? GET('/r/rabatte') : []]);
    const rbA = rb.filter((r) => r.automat_id === a.id || (!r.automat_id && d.kunde && r.kunde_id === d.kunde.id));
    const wv = S.meta.resources.vertraege?.write;
    body.innerHTML = `<div class="panel"><div class="row"><h3>Verträge</h3><span class="spacer"></span>${wv ? '<button class="primary" id="vtBtn">+ Vertrag</button>' : ''}</div>
      ${table([{ key: 'vertragsnr', label: 'Nr.' }, { key: 'art', label: 'Art' }, { key: 'kunde_id', label: 'Partner', fmt: (r) => esc(r.kunde_id__label || r.lieferant_id__label || '') }, { key: 'beginn', label: 'Beginn', type: 'date' }, { key: 'ende', label: 'Ende', type: 'date' }, { key: 'rate', label: 'Rate', type: 'money' }],
        vt, { onRow: (r) => editRecord('vertraege', r, { onSaved: reload }) })}</div>
      <div class="panel"><h3>Rabatte und Provisionen für diesen Automaten</h3>
      ${table([{ key: 'bezeichnung', label: 'Bezeichnung' }, { key: 'kunde_id', label: 'Empfänger', fmt: (r) => esc(r.kunde_id__label) }, { key: 'art', label: 'Art', fmt: (r) => esc(S.meta.resources.rabatte.fields.find((f) => f.name === 'art').opts[r.art]) },
        { key: 'wert', label: 'Wert', type: 'num', fmt: (r) => (r.art === 'staffel' ? esc(r.staffel) : fmt(r.wert)) }, { key: 'automat_id', label: 'gilt für', fmt: (r) => (r.automat_id ? 'diesen Automaten' : 'alle Automaten des Kunden') }],
        rbA, { onRow: (r) => editRecord('rabatte', r, { onSaved: reload }), empty: 'Keine Rabatte' })}</div>`;
    if ($('#vtBtn')) $('#vtBtn').onclick = () => editRecord('vertraege', null, { defaults: { automat_id: a.id, kunde_id: d.kunde?.id, beginn: today() }, onSaved: reload });
  }

  if (tab === 'historie') {
    const rows = await GET(`/automaten/${a.id}/historie`);
    body.innerHTML = `<div class="panel"><h3>Lebenslauf der Komponenten in diesem Automaten</h3>${table([{ key: 'datum', label: 'Datum', type: 'date' }, { key: 'typ', label: 'Typ', fmt: (r) => esc(KT()[r.typ] || r.typ) },
      { key: 'bezeichnung', label: 'Komponente', fmt: (r) => esc(r.bezeichnung + (r.seriennr ? ' (' + r.seriennr + ')' : '')) }, { key: 'aktion', label: 'Vorgang' }, { key: 'text', label: 'Text' }, { key: 'benutzer', label: 'von' }],
    rows, { onRow: (r) => komponenteDialog(r.komponente_id, reload) })}</div>`;
  }

  if (tab === 'dokumente') {
    body.innerHTML = '<div class="panel" id="docBox"></div>';
    docsBox($('#docBox'), 'automaten', a.id, w);
  }
});

async function einbauDialog(automatId, done) {
  const lager = (await GET('/r/komponenten')).filter((k) => k.status === 'im Lager');
  const b = modal('Komponente einbauen', `<form class="stack" id="f">
    <label>Komponente aus dem Lager <select name="k" required><option value=""></option>${lager.map((k) => `<option value="${k.id}">${esc((KT()[k.typ] || k.typ) + ': ' + k.bezeichnung + (k.seriennr ? ' · ' + k.seriennr : ''))}</option>`).join('')}</select></label>
    <p class="muted small">Nicht dabei? <a href="#" id="neuK">Neue Komponente anlegen</a></p>
    <label>Datum <input type="date" name="datum" value="${today()}" required></label>
    <label>Notiz <input name="text"></label>
    <div class="row end"><button type="button" id="abbr">Abbrechen</button><button class="primary">Einbauen</button></div></form>`);
  $('#abbr', b).onclick = closeModal;
  $('#neuK', b).onclick = (e) => { e.preventDefault(); editRecord('komponenten', null, { onSaved: () => einbauDialog(automatId, done) }); };
  $('#f', b).onsubmit = async (e) => {
    e.preventDefault();
    const f = e.target;
    try { await POST(`/komponenten/${f.k.value}/einbau`, { automatId, datum: f.datum.value, text: f.text.value }); closeModal(); toast('Eingebaut'); done(); } catch (e2) { fail(e2); }
  };
}

// Komponentenakte: Daten, Lebenslauf und Aktionen
async function komponenteDialog(id, done = rerender) {
  const [k, hist] = await Promise.all([GET('/r/komponenten/' + id), GET(`/komponenten/${id}/historie`)]);
  const w = S.meta.resources.komponenten.write;
  const b = modal(`${KT()[k.typ] || k.typ}: ${k.bezeichnung}`, `
    <dl class="dl"><dt>Seriennr.</dt><dd>${esc(k.seriennr || '–')}</dd><dt>Status</dt><dd>${esc(k.status)}${k.automat_id ? ' in <a href="#/automat/' + k.automat_id + '">' + esc(k.automat_id__label) + '</a> seit ' + dateDe(k.eingebaut_am) : ''}</dd>
      <dt>Hersteller</dt><dd>${esc(k.hersteller_id__label || '–')}</dd><dt>Kauf</dt><dd>${dateDe(k.kaufdatum) || '–'} ${k.kaufpreis ? money(k.kaufpreis) : ''}</dd>
      <dt>Lebensdauer</dt><dd>${k.lebensdauer_tage ? k.lebensdauer_tage + ' Tage ' : ''}${k.lebensdauer_verkaeufe ? int(k.lebensdauer_verkaeufe) + ' Verkäufe' : ''}${!k.lebensdauer_tage && !k.lebensdauer_verkaeufe ? '–' : ''}</dd></dl>
    ${w ? `<div class="row">${k.status === 'eingebaut' ? '<button id="ausBtn">Ausbauen</button>' : k.status !== 'ausgemustert' ? '<button id="einBtn">Einbauen</button><button id="stBtn">Status ändern</button>' : ''}<button id="notizBtn">Wartung / Notiz</button><button id="editK">Daten bearbeiten</button></div>` : ''}
    <h3>Lebenslauf</h3>${table([{ key: 'datum', label: 'Datum', type: 'date' }, { key: 'aktion', label: 'Vorgang' }, { key: 'automat', label: 'Automat' }, { key: 'text', label: 'Text' }, { key: 'verkaeufe', label: 'Verkäufe', type: 'int' }, { key: 'benutzer', label: 'von' }], hist)}
    <div id="docBox"></div>`, true);
  docsBox($('#docBox', b), 'komponenten', k.id, w);
  const small = (title, html, send) => {
    const bb = modal(title, `<form class="stack" id="f2">${html}<div class="row end"><button type="button" id="back">Zurück</button><button class="primary">Speichern</button></div></form>`);
    $('#back', bb).onclick = () => komponenteDialog(id, done);
    $('#f2', bb).onsubmit = async (e) => { e.preventDefault(); try { await send(e.target); toast('Gespeichert'); await komponenteDialog(id, done); done === rerender || done(); } catch (e2) { fail(e2); } };
  };
  const stOpts = (sel) => ['im Lager', 'defekt', 'in Reparatur', 'ausgemustert'].map((s) => `<option${s === sel ? ' selected' : ''}>${s}</option>`).join('');
  if ($('#ausBtn', b)) $('#ausBtn', b).onclick = () => small('Ausbauen', `<label>Datum <input type="date" name="datum" value="${today()}"></label><label>Danach <select name="status">${stOpts('im Lager')}</select></label><label>Grund <input name="text"></label>`,
    (f) => POST(`/komponenten/${id}/ausbau`, { datum: f.datum.value, status: f.status.value, text: f.text.value }));
  if ($('#einBtn', b)) $('#einBtn', b).onclick = async () => {
    const am = await auswahl('automaten');
    small('Einbauen', `<label>Automat <select name="a" required><option value=""></option>${am.map((x) => `<option value="${x.id}">${esc(x.label)}</option>`).join('')}</select></label><label>Datum <input type="date" name="datum" value="${today()}"></label><label>Notiz <input name="text"></label>`,
      (f) => POST(`/komponenten/${id}/einbau`, { automatId: f.a.value, datum: f.datum.value, text: f.text.value }));
  };
  if ($('#stBtn', b)) $('#stBtn', b).onclick = () => small('Status ändern', `<label>Status <select name="status">${stOpts(k.status)}</select></label><label>Notiz <input name="text"></label>`, (f) => POST(`/komponenten/${id}/status`, { status: f.status.value, text: f.text.value }));
  if ($('#notizBtn', b)) $('#notizBtn', b).onclick = () => small('Wartung / Notiz', `<label>Vorgang <select name="aktion"><option>Wartung</option><option>Reparatur</option><option>Reinigung</option><option>Prüfung</option><option>Notiz</option></select></label><label>Datum <input type="date" name="datum" value="${today()}"></label><label>Text <input name="text" required></label>`,
    (f) => POST(`/komponenten/${id}/notiz`, { aktion: f.aktion.value, datum: f.datum.value, text: f.text.value }));
  if ($('#editK', b)) $('#editK', b).onclick = () => editRecord('komponenten', k, { onSaved: () => komponenteDialog(id, done) });
}

route('/komponenten', async (_, app) => {
  const m = S.meta.resources.komponenten;
  setTitle('Komponenten', `${m.write ? '<button class="primary" id="neuBtn">+ Neue Komponente</button>' : ''}<button id="csvBtn">CSV</button>`);
  app.innerHTML = `<div class="panel"><div class="row"><input type="search" id="q" placeholder="Bezeichnung, Seriennummer …">
    <select id="typ"><option value="">alle Typen</option>${Object.entries(KT()).map(([k, t]) => `<option value="${k}">${esc(t)}</option>`).join('')}</select>
    <select id="st"><option value="">alle Status</option>${['im Lager', 'eingebaut', 'defekt', 'in Reparatur', 'ausgemustert'].map((s) => `<option>${s}</option>`).join('')}</select><span class="muted" id="cnt"></span></div><div id="list"></div></div>`;
  const fields = Object.fromEntries(m.fields.map((f) => [f.name, f]));
  const cols = [...m.list.map((k) => fieldCol(fields[k])), { key: 'kaufpreis', label: 'Kaufpreis', type: 'money', sum: true }];
  const load = async () => {
    const q = $('#q').value.trim();
    let rows = await GET('/r/komponenten' + (q ? '&q=' + encodeURIComponent(q) : '') + ($('#typ').value ? '&f_typ=' + $('#typ').value : '') + ($('#st').value ? '&f_status=' + encodeURIComponent($('#st').value) : ''));
    $('#cnt').textContent = rows.length + ' Komponenten';
    $('#list').innerHTML = table(cols, rows, { onRow: (r) => komponenteDialog(r.id, load) });
    $('#csvBtn').onclick = () => csv(cols.map((c) => ({ ...c, csv: (r) => r[c.key + '__label'] ?? r[c.key] })), rows, 'Komponenten');
  };
  let t;
  $('#q').oninput = () => { clearTimeout(t); t = setTimeout(() => load().catch(fail), 250); };
  $('#typ').onchange = $('#st').onchange = () => load().catch(fail);
  if ($('#neuBtn')) $('#neuBtn').onclick = () => editRecord('komponenten', null, { onSaved: (k) => komponenteDialog(k.id, load) });
  await load();
});

route('/verschleiss', async (_, app) => {
  setTitle('Verschleißteile und Lebensdauer');
  const rows = await GET('/verschleiss');
  app.innerHTML = `<div class="panel"><p class="muted">Eingebaute Komponenten mit Lebensdauer (Tage oder Verkäufe). Ab 80 % rechtzeitig tauschen: als Aufsteller agieren statt reagieren.</p>
    ${table([{ key: 'verbraucht', label: 'Verbraucht', type: 'int', fmt: (r) => verbrauchBar(r.verbraucht) }, { key: 'bezeichnung', label: 'Teil' }, { key: 'typ', label: 'Typ', fmt: (r) => esc(KT()[r.typ]) }, { key: 'automat', label: 'Automat' },
      { key: 'eingebautAm', label: 'Eingebaut', type: 'date' }, { key: 'tage', label: 'Tage', type: 'int', fmt: (r) => int(r.tage) + (r.lebensdauerTage ? ' / ' + int(r.lebensdauerTage) : '') },
      { key: 'verkaeufe', label: 'Verkäufe', type: 'int', fmt: (r) => int(r.verkaeufe) + (r.lebensdauerVerkaeufe ? ' / ' + int(r.lebensdauerVerkaeufe) : '') }, { key: 'faelligAm', label: 'Fällig am', type: 'date' }],
    rows, { onRow: (r) => komponenteDialog(r.id), empty: 'Keine eingebauten Komponenten mit Lebensdauer. Lebensdauer in den Komponentendaten eintragen.' })}</div>`;
});
