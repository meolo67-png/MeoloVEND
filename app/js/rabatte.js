'use strict';
// Rabatte und Verträge: Rabattabgleich mit Endkontrolle (berechnet → geprüft → freigegeben → abgerechnet), Vertragsraten

const RB_STATUS = { berechnet: 'berechnet', geprueft: 'geprüft', freigegeben: 'freigegeben', abgerechnet: 'abgerechnet' };
const RB_NEXT = { berechnet: ['geprueft', 'Prüfen'], geprueft: ['freigegeben', 'Freigeben'], freigegeben: ['abgerechnet', 'Als ausgezahlt markieren'] };

route('/rabattabgleich', async (_, app) => {
  const p = hashParams();
  const w = can('rabatte', true);
  setTitle('Rabattabgleich', `${w ? '<button class="primary" id="calcBtn">Neu berechnen</button>' : ''}<button id="csvBtn">CSV / Auszahlungsliste</button>`);
  app.innerHTML = `<div class="panel"><div class="row filters">
    <label class="inline">von <input type="date" id="von" value="${p.get('von') || new Date().getFullYear() + '-01-01'}"></label><label class="inline">bis <input type="date" id="bis" value="${p.get('bis') || monthEnd(-1)}"></label>
    <select id="st"><option value="">alle Status</option>${Object.entries(RB_STATUS).map(([k, t]) => `<option value="${k}"${p.get('status') === k ? ' selected' : ''}>${t}</option>`).join('')}</select></div>
    <p class="muted small">Alle Rabatte, Provisionen und Standplatzgebühren werden je Abrechnungszeitraum berechnet. Erst nach der Endkontrolle (prüfen, dann freigeben durch eine zweite Person) gehen sie in die Abrechnung und den DATEV-Export. Freigegebene Beträge ändern sich beim Neuberechnen nicht mehr.</p>
    <div class="kpis" id="k"></div><div id="list"></div></div>`;
  const cols = [{ key: 'firma', label: 'Kunde', fmt: (r) => esc(r.kundennr + ' ' + r.firma) }, { key: 'bezeichnung', label: 'Rabatt' }, { key: 'automat', label: 'Automat', fmt: (r) => esc(r.automat || 'alle') },
    { key: 'von', label: 'Zeitraum', fmt: (r) => dateDe(r.von) + ' – ' + dateDe(r.bis) }, { key: 'umsatz', label: 'Umsatz', type: 'money', sum: true }, { key: 'betrag', label: 'Betrag', type: 'money', sum: true },
    { key: 'status', label: 'Status', fmt: (r) => `<span class="pill ${r.status}">${RB_STATUS[r.status]}</span>${r.geprueft_von ? `<div class="muted small">geprüft: ${esc(r.geprueft_von)}</div>` : ''}` },
    { key: 'x', label: '', fmt: (r) => (w && RB_NEXT[r.status] ? `<button class="small" data-st="${RB_NEXT[r.status][0]}" data-id="${r.id}">${RB_NEXT[r.status][1]}</button>` : '') }];
  const load = async () => {
    const q = { von: $('#von').value, bis: $('#bis').value, status: $('#st').value };
    setParams(q);
    const rows = await GET('/rabattabgleich&' + new URLSearchParams(q));
    const sum = (st) => rows.filter((r) => !st || st.includes(r.status)).reduce((s, r) => s + r.betrag, 0);
    $('#k').innerHTML = kpi('Gesamt', money(sum())) + kpi('zu prüfen', money(sum(['berechnet']))) + kpi('geprüft, nicht freigegeben', money(sum(['geprueft']))) + kpi('freigegeben / abgerechnet', money(sum(['freigegeben', 'abgerechnet'])));
    $('#list').innerHTML = table(cols, rows, { onRow: rabattDetail, empty: 'Keine Abrechnungen. „Neu berechnen“ legt sie für den Zeitraum an.' });
    $('#csvBtn').onclick = () => csv([{ key: 'kundennr', label: 'Kundennr.' }, { key: 'firma', label: 'Kunde' }, { key: 'iban', label: 'IBAN' }, { key: 'bezeichnung', label: 'Rabatt' }, { key: 'von', label: 'von' }, { key: 'bis', label: 'bis' },
      { key: 'umsatz', label: 'Umsatz' }, { key: 'betrag', label: 'Betrag' }, { key: 'status', label: 'Status' }, { key: 'geprueft_von', label: 'geprüft von' }], rows, 'Rabattabgleich');
  };
  $$('.filters input, .filters select', app).forEach((x) => { x.onchange = () => load().catch(fail); });
  $('#list').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-st]');
    if (!b) return;
    try { await POST(`/rabattabrechnungen/${b.dataset.id}/status`, { status: b.dataset.st }); toast('Status geändert'); load(); } catch (e2) { fail(e2); }
  });
  if ($('#calcBtn')) $('#calcBtn').onclick = async () => {
    try { const r = await POST('/rabatte/berechnen', { von: $('#von').value, bis: $('#bis').value }); toast(r.berechnet + ' Abrechnung(en) berechnet'); load(); } catch (e) { fail(e); }
  };
  await load();
});

function rabattDetail(r) {
  const w = can('rabatte', true);
  const el = modal(`${r.bezeichnung} · ${r.firma}`, `<p>${dateDe(r.von)} – ${dateDe(r.bis)} · <span class="pill ${r.status}">${RB_STATUS[r.status]}</span>${r.geprueft_von ? ' · geprüft von ' + esc(r.geprueft_von) + ' am ' + dateDe(r.geprueft_am) : ''}</p>
    <p class="muted">${esc(r.art === 'staffel' ? 'Staffel: ' + r.staffel + ' (je Monatsumsatz)' : r.art === 'provision' ? fmt(r.wert) + ' % vom Umsatz' : money(r.wert) + ' je ' + (r.art === 'standplatz' ? 'Monat' : 'Zeitraum'))}</p>
    ${table([{ key: 'monat', label: 'Monat', fmt: (d) => monatDe(d.monat) }, { key: 'umsatz', label: 'Umsatz', type: 'money', sum: true }, { key: 'betrag', label: 'Betrag', type: 'money', sum: true }], r.details || [])}
    <div class="row end">${w && r.status === 'geprueft' ? '<button id="back">Zurück auf berechnet</button>' : ''}${w && r.status === 'freigegeben' ? '<button id="back2">Freigabe zurücknehmen</button>' : ''}<button id="cl">Schließen</button></div>`);
  $('#cl', el).onclick = closeModal;
  const back = async (st) => { try { await POST(`/rabattabrechnungen/${r.id}/status`, { status: st }); closeModal(); rerender(); } catch (e) { fail(e); } };
  if ($('#back', el)) $('#back', el).onclick = () => back('berechnet');
  if ($('#back2', el)) $('#back2', el).onclick = () => back('geprueft');
}

route('/vertragsraten', async (_, app) => {
  const p = hashParams();
  setTitle('Vertragsraten', '<button id="csvBtn">CSV</button>');
  app.innerHTML = `<div class="panel"><div class="row filters"><label class="inline">von <input type="date" id="von" value="${p.get('von') || monthStart()}"></label><label class="inline">bis <input type="date" id="bis" value="${p.get('bis') || monthEnd(2)}"></label></div>
    <div class="kpis" id="k"></div><div id="list"></div></div>`;
  const cols = [{ key: 'datum', label: 'Fällig', type: 'date' }, { key: 'vertragsnr', label: 'Vertrag' }, { key: 'art', label: 'Art' }, { key: 'partner', label: 'Partner' }, { key: 'nr', label: 'Rate Nr.', type: 'int' },
    { key: 'betrag', label: 'Betrag', type: 'money', sum: true, fmt: (r) => `<span class="${r.betrag < 0 ? 'neg' : 'pos'}">${money(r.betrag)}</span>` }];
  const load = async () => {
    const q = { von: $('#von').value, bis: $('#bis').value };
    setParams(q);
    const rows = await GET('/vertragsraten&' + new URLSearchParams(q));
    const ein = rows.filter((r) => r.betrag > 0).reduce((s, r) => s + r.betrag, 0), aus = rows.filter((r) => r.betrag < 0).reduce((s, r) => s + r.betrag, 0);
    $('#k').innerHTML = kpi('Einnahmen aus Verträgen', money(ein)) + kpi('Ausgaben (Miete, Leasing, Raten)', money(aus)) + kpi('Saldo', money(ein + aus));
    $('#list').innerHTML = table(cols, rows, { onRow: (r) => editRecord('vertraege', { id: r.vertragId }, { onSaved: load }), empty: 'Keine fälligen Raten' });
    $('#csvBtn').onclick = () => csv(cols, rows, 'Vertragsraten');
  };
  $$('.filters input', app).forEach((x) => { x.onchange = () => load().catch(fail); });
  await load();
});
