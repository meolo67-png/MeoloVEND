'use strict';
// Geld: Einnahmen mit Soll/Ist und Fehlgeld, Planumsatz-Vergleich, Kassen- und Monatsabschluss

const GRUPPEN = { automat: 'Automat', kunde: 'Kunde', tour: 'Tour', mitarbeiter: 'Mitarbeiter', tag: 'Tag', monat: 'Monat' };

function einnahmenCols(gruppe) {
  return [
    { key: 'name', label: GRUPPEN[gruppe], fmt: (r) => esc(gruppe === 'tag' ? dateDe(r.name) : gruppe === 'monat' ? monatDe(r.name) : r.name) },
    { key: 'besuche', label: 'Besuche', type: 'int', sum: true }, { key: 'verkaeufe', label: 'Verkäufe', type: 'int', sum: true },
    { key: 'soll_bar', label: 'Soll bar', type: 'money', sum: true }, { key: 'ist_bar', label: 'Ist bar', type: 'money', sum: true },
    { key: 'fehlgeld', label: 'Fehlgeld', type: 'money', sum: true, fmt: (r) => `<span class="${r.fehlgeld < 0 ? 'neg' : r.fehlgeld > 0 ? 'pos' : ''}">${money(r.fehlgeld)}</span>` },
    { key: 'bargeldlos', label: 'Bargeldlos', type: 'money', sum: true }, { key: 'umsatz', label: 'Umsatz brutto', type: 'money', sum: true },
  ];
}

route('/einnahmen', async (_, app) => {
  const p = hashParams();
  setTitle('Einnahmen', '<button id="csvBtn">CSV</button><button id="druck">Drucken</button>');
  app.innerHTML = `<div class="panel"><div class="row filters">
    <label class="inline">von <input type="date" id="von" value="${p.get('von') || monthStart()}"></label><label class="inline">bis <input type="date" id="bis" value="${p.get('bis') || today()}"></label>
    <label class="inline">je <select id="gr">${Object.entries(GRUPPEN).map(([k, t]) => `<option value="${k}"${(p.get('gruppe') || 'automat') === k ? ' selected' : ''}>${t}</option>`).join('')}</select></label>
    <span class="muted" id="lock"></span></div><div class="kpis" id="k"></div><div id="list"></div></div>`;
  $('#druck').onclick = () => window.print();
  const load = async () => {
    const q = { von: $('#von').value, bis: $('#bis').value, gruppe: $('#gr').value };
    setParams(q);
    const r = await GET('/einnahmen&' + new URLSearchParams(q));
    const s = r.summe;
    $('#k').innerHTML = kpi('Umsatz', money(s.umsatz)) + kpi('Soll bar', money(s.soll_bar)) + kpi('Ist bar', money(s.ist_bar)) + kpi('Fehlgeld', money(s.fehlgeld), s.soll_bar ? fmt(s.fehlgeld / s.soll_bar * 100, 2) + ' % vom Soll' : '', s.fehlgeld < 0 ? 'warn' : '');
    $('#lock').textContent = r.gesperrtBis ? 'abgeschlossen bis ' + dateDe(r.gesperrtBis) : '';
    const cols = einnahmenCols(q.gruppe);
    $('#list').innerHTML = table(cols, r.zeilen, { onRow: (z) => { if (q.gruppe === 'automat') location.hash = `#/besuche?automat=${z.schluessel}&von=${q.von}&bis=${q.bis}`; } });
    $('#csvBtn').onclick = () => csv(cols.map((c) => ({ ...c, csv: (x) => x[c.key] })), r.zeilen, 'Einnahmen');
  };
  $$('.filters input, .filters select', app).forEach((x) => { x.onchange = () => load().catch(fail); });
  await load();
});

route('/sollist', async (_, app) => {
  const jahr = Number(hashParams().get('jahr')) || new Date().getFullYear();
  setTitle('Soll / Ist je Automat', '<button id="csvBtn">CSV</button>');
  const r = await GET('/sollist&jahr=' + jahr);
  const jetzt = new Date();
  const bisMonat = jahr < jetzt.getFullYear() ? 12 : jahr > jetzt.getFullYear() ? 0 : jetzt.getMonth() + 1;
  const cols = [{ key: 'name', label: 'Automat', fmt: (a) => esc(a.nummer + ' ' + a.bezeichnung) }, { key: 'plan', label: 'Plan / Monat', type: 'money' },
    ...MONATE.map((m, i) => ({ key: 'm' + (i + 1), label: m, type: 'money', sum: true, fmt: (a) => {
      const v = a.monate[i + 1];
      if (!v && i + 1 > bisMonat) return '';
      const q = a.plan ? v / a.plan : null;
      return `<span class="${q == null ? '' : q < 0.8 ? 'neg' : q >= 1 ? 'pos' : ''}" title="${q == null ? '' : Math.round(q * 100) + ' % vom Plan'}">${fmt(v, 0)}</span>`;
    } })),
    { key: 'summe', label: 'Summe', type: 'money', sum: true }, { key: 'quote', label: 'Quote', type: 'int', fmt: (a) => (a.plan && bisMonat ? Math.round(a.summe / (a.plan * bisMonat) * 100) + ' %' : '') }];
  r.automaten.forEach((a) => { MONATE.forEach((_, i) => { a['m' + (i + 1)] = a.monate[i + 1]; }); });
  app.innerHTML = `<div class="panel"><div class="row"><button id="prev">◀</button><strong>${jahr}</strong><button id="next">▶</button><span class="muted small">Rot: unter 80 % vom Planumsatz, grün: Plan erreicht. Zahlen in ${esc(S.meta.settings.waehrung)} brutto.</span></div>
    ${table(cols, r.automaten, { onRow: (a) => (location.hash = '#/automat/' + a.id) })}</div>`;
  $('#prev').onclick = () => { location.hash = '#/sollist?jahr=' + (jahr - 1); };
  $('#next').onclick = () => { location.hash = '#/sollist?jahr=' + (jahr + 1); };
  $('#csvBtn').onclick = () => csv(cols.map((c) => ({ ...c, csv: (a) => (c.key === 'name' ? a.nummer + ' ' + a.bezeichnung : a[c.key]) })), r.automaten, 'SollIst-' + jahr);
});

route('/abschluesse', async (_, app) => {
  const r = await GET('/abschluesse');
  setTitle('Kassen- und Monatsabschlüsse', can('einnahmen', true) ? '<button class="primary" id="neuBtn">+ Abschluss</button>' : '');
  app.innerHTML = `<div class="panel"><p class="muted">Ein Abschluss sperrt alle Besuche, Warenbuchungen und Kosten bis zum Abschlussdatum. Spätere Änderungen gehen nur noch als Korrekturbuchung. ${r.gesperrtBis ? '<strong>Abgeschlossen bis ' + dateDe(r.gesperrtBis) + '.</strong>' : 'Noch nichts abgeschlossen.'}</p>
    ${table([{ key: 'nummer', label: 'Nr.', type: 'int' }, { key: 'art', label: 'Art', fmt: (a) => (a.art === 'monat' ? 'Monatsabschluss' : 'Kassenabschluss') }, { key: 'von', label: 'von', type: 'date' }, { key: 'bis', label: 'bis', type: 'date' },
      { key: 'umsatz', label: 'Umsatz', type: 'money', fmt: (a) => money(a.summen.umsatz) }, { key: 'ist', label: 'Ist bar', type: 'money', fmt: (a) => money(a.summen.ist_bar) }, { key: 'fehlgeld', label: 'Fehlgeld', type: 'money', fmt: (a) => money(a.summen.fehlgeld) },
      { key: 'benutzer', label: 'von' }, { key: 'created_at', label: 'am', type: 'date' }], r.abschluesse, { onRow: abschlussDialog, empty: 'Noch keine Abschlüsse' })}</div>`;
  if ($('#neuBtn')) $('#neuBtn').onclick = async () => {
    const b = modal('Neuer Abschluss', `<form class="stack" id="af"><p>Abschluss ab <strong>${dateDe(r.naechsterVon)}</strong></p>
      <div class="grid2"><label>Art <select name="art"><option value="kasse">Kassenabschluss (beliebiges Datum)</option><option value="monat">Monatsabschluss</option></select></label><label>bis einschließlich <input type="date" name="bis" value="${monthEnd(-1) >= r.naechsterVon ? monthEnd(-1) : today()}" max="${today()}" required></label></div>
      <div id="vorschau"></div><p class="warn-box">Danach lässt sich im Zeitraum nichts mehr ändern.</p>
      <div class="row end"><button type="button" id="ab">Abbrechen</button><button class="primary">Abschließen</button></div></form>`, true);
    const f = $('#af', b);
    const preview = async () => {
      if (!f.bis.value || f.bis.value < r.naechsterVon) { $('#vorschau', b).innerHTML = ''; return; }
      const e = await GET(`/einnahmen&von=${r.naechsterVon}&bis=${f.bis.value}`);
      $('#vorschau', b).innerHTML = `<div class="kpis">${kpi('Umsatz', money(e.summe.umsatz))}${kpi('Ist bar', money(e.summe.ist_bar))}${kpi('Fehlgeld', money(e.summe.fehlgeld))}${kpi('Besuche', int(e.summe.besuche))}</div>`;
    };
    f.bis.onchange = () => preview().catch(fail);
    f.art.onchange = () => { if (f.art.value === 'monat') { const d = new Date(f.bis.value || today()); d.setDate(1); d.setMonth(d.getMonth() + 1); d.setDate(0); if (isoOf(d) > today()) d.setDate(0); f.bis.value = isoOf(d); preview(); } };
    $('#ab', b).onclick = closeModal;
    await preview();
    f.onsubmit = async (e) => { e.preventDefault(); try { await POST('/abschluesse', { art: f.art.value, bis: f.bis.value }); closeModal(); toast('Abschluss gespeichert'); S.meta.gesperrtBis = f.bis.value; rerender(); } catch (e2) { fail(e2); } };
  };
});

function abschlussDialog(a) {
  const el = modal(`${a.art === 'monat' ? 'Monatsabschluss' : 'Kassenabschluss'} Nr. ${a.nummer}`, `<p>${dateDe(a.von)} bis ${dateDe(a.bis)} · erstellt von ${esc(a.benutzer)} am ${dateDe(a.created_at)}</p>
    <div class="kpis">${kpi('Umsatz', money(a.summen.umsatz))}${kpi('Ist bar', money(a.summen.ist_bar))}${kpi('Bargeldlos', money(a.summen.bargeldlos))}${kpi('Fehlgeld', money(a.summen.fehlgeld))}${kpi('Wareneinsatz', money(a.summen.wareneinsatz))}</div>
    ${table(einnahmenCols('automat'), a.summen.automaten || [])}<div class="row end"><button id="pr">Drucken</button><button id="cl">Schließen</button></div>`, true);
  $('#cl', el).onclick = closeModal;
  $('#pr', el).onclick = () => window.print();
}
