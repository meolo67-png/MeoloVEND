'use strict';
// Übersicht: Kennzahlen des Monats, Umsatzverlauf, offene Störungen, fällige Verschleißteile, überfällige Automaten

// Säulendiagramm für eine Reihe (z. B. Umsatz je Monat), mit Hinweis beim Darüberfahren
function barChart(rows, { valueKey, labelFmt, valueFmt = money, height = 180 }) {
  if (!rows.length) return '<p class="muted">Noch keine Daten.</p>';
  const max = Math.max(...rows.map((r) => Number(r[valueKey]) || 0), 1);
  const w = 640, padL = 8, padB = 22, padT = 18, h = height;
  const bw = (w - padL * 2) / rows.length;
  const bar = Math.min(28, bw * 0.6);
  const y = (v) => padT + (h - padT - padB) * (1 - v / max);
  const ticks = [0.5, 1].map((t) => max * t);
  return `<div class="chart" data-chart>
    <svg viewBox="0 0 ${w} ${h}" role="img" aria-label="Säulendiagramm">
      ${ticks.map((t) => `<line class="grid" x1="${padL}" x2="${w - padL}" y1="${y(t)}" y2="${y(t)}"/><text class="axis" x="${w - padL}" y="${y(t) - 3}" text-anchor="end">${esc(valueFmt(t))}</text>`).join('')}
      <line class="base" x1="${padL}" x2="${w - padL}" y1="${y(0)}" y2="${y(0)}"/>
      ${rows.map((r, i) => {
        const v = Math.max(0, Number(r[valueKey]) || 0);
        const x = padL + i * bw + (bw - bar) / 2;
        const top = y(v), hh = y(0) - top;
        const rr = Math.min(4, hh);
        const path = hh <= 0 ? '' : `M${x},${y(0)} V${top + rr} Q${x},${top} ${x + rr},${top} H${x + bar - rr} Q${x + bar},${top} ${x + bar},${top + rr} V${y(0)} Z`;
        return `<g data-tip="${esc(labelFmt(r))}: ${esc(valueFmt(v))}"><rect class="hit" x="${padL + i * bw}" y="${padT}" width="${bw}" height="${h - padT - padB}"/><path class="bar" d="${path}"/>
          <text class="axis" x="${x + bar / 2}" y="${h - 6}" text-anchor="middle">${esc(labelFmt(r).split(' ')[0])}</text></g>`;
      }).join('')}
    </svg><div class="chart-tip" hidden></div></div>`;
}

// Hinweise für alle Diagramme einer Ansicht
function chartTips(el) {
  for (const c of $$('[data-chart]', el)) {
    const tip = $('.chart-tip', c);
    c.addEventListener('mousemove', (e) => {
      const g = e.target.closest('[data-tip]');
      if (!g) { tip.hidden = true; return; }
      tip.textContent = g.dataset.tip;
      tip.hidden = false;
      const r = c.getBoundingClientRect();
      tip.style.left = Math.min(e.clientX - r.left + 12, r.width - 160) + 'px';
      tip.style.top = (e.clientY - r.top - 34) + 'px';
      $$('.bar', c).forEach((b) => b.classList.toggle('dim', b.parentNode !== g));
    });
    c.addEventListener('mouseleave', () => { tip.hidden = true; $$('.bar', c).forEach((b) => b.classList.remove('dim')); });
  }
}

function kpi(label, value, sub = '', cls = '') {
  return `<div class="kpi ${cls}"><div class="kpi-label">${esc(label)}</div><div class="kpi-value">${value}</div>${sub ? `<div class="kpi-sub">${sub}</div>` : ''}</div>`;
}

function trend(ist, vor) {
  if (!vor) return '';
  const p = Math.round((ist / vor - 1) * 100);
  return `<span class="${p >= 0 ? 'pos' : 'neg'}">${p >= 0 ? '▲' : '▼'} ${Math.abs(p)} %</span> zum Vormonat (gleicher Zeitraum)`;
}

route('/', async (_, app) => {
  setTitle('Übersicht', can('touren', true) ? '<a class="btn primary" href="#/besuch">+ Besuch erfassen</a>' : '');
  const d = await GET('/dashboard');
  const parts = [];
  if (d.monat) {
    parts.push(`<div class="kpis">
      ${kpi('Umsatz ' + monatDe(today().slice(0, 7)), money(d.monat.umsatz), trend(d.monat.umsatz, d.vormonatBisHeute.umsatz))}
      ${kpi('davon bargeldlos', money(d.monat.bargeldlos), d.monat.umsatz ? Math.round(d.monat.bargeldlos / d.monat.umsatz * 100) + ' % vom Umsatz' : '')}
      ${kpi('Fehlgeld', money(d.monat.fehlgeld), d.monat.fehlgeld < 0 ? 'fehlt in der Kasse' : d.monat.fehlgeld > 0 ? 'Überschuss' : 'keine Abweichung', d.monat.fehlgeld < 0 ? 'warn' : '')}
      ${kpi('Besuche', int(d.monat.besuche), int(d.monat.verkaeufe) + ' Verkäufe')}
    </div>`);
    parts.push(`<div class="cols2">
      <div class="panel"><h3>Umsatz der letzten 12 Monate</h3>${barChart(d.verlauf, { valueKey: 'umsatz', labelFmt: (r) => monatDe(r.monat) })}</div>
      <div class="panel"><h3>Unter Plan (hochgerechnet auf den Monat)</h3>${table([
        { key: 'name', label: 'Automat' }, { key: 'plan', label: 'Plan / Monat', type: 'money' }, { key: 'ist', label: 'Ist bisher', type: 'money' },
        { key: 'quote', label: 'Quote', type: 'int', fmt: (r) => (r.quote == null ? '' : `<span class="${r.quote < 80 ? 'neg' : 'pos'}">${r.quote} %</span>`) },
      ], d.planabweichung, { onRow: (r) => (location.hash = '#/automat/' + r.id), empty: 'Kein Automat mit Planumsatz' })}</div>
    </div>`);
  }
  const lists = [];
  if (d.stoerungen) lists.push(`<div class="panel"><h3>Offene Störungen <a class="small" href="#/r/stoerungen">alle</a></h3>${table([
    { key: 'datum', label: 'Datum', type: 'date' }, { key: 'automat', label: 'Automat' }, { key: 'beschreibung', label: 'Beschreibung' },
    { key: 'prioritaet', label: 'Priorität', fmt: (r) => `<span class="pill ${r.prioritaet === 'normal' ? '' : 'warn'}">${esc(r.prioritaet || 'normal')}</span>` },
  ], d.stoerungen, { onRow: (r) => editRecord('stoerungen', r), empty: 'Keine offenen Störungen' })}</div>`);
  if (d.verschleiss) lists.push(`<div class="panel"><h3>Verschleißteile fällig <a class="small" href="#/verschleiss">alle</a></h3>${table([
    { key: 'bezeichnung', label: 'Teil' }, { key: 'automat', label: 'Automat' }, { key: 'verbraucht', label: 'Verbraucht', type: 'int', fmt: (r) => verbrauchBar(r.verbraucht) },
  ], d.verschleiss, { onRow: (r) => (location.hash = '#/automat/' + r.automatId), empty: 'Nichts fällig' })}</div>`);
  if (d.ueberfaellig) lists.push(`<div class="panel"><h3>Länger als 14 Tage nicht besucht</h3>${table([
    { key: 'nummer', label: 'Nr.' }, { key: 'bezeichnung', label: 'Automat' }, { key: 'letzter', label: 'Letzter Besuch', type: 'date', fmt: (r) => (r.letzter ? dateDe(r.letzter) : 'noch nie') },
  ], d.ueberfaellig, { onRow: (r) => (location.hash = '#/automat/' + r.id), empty: 'Alle Automaten sind aktuell' })}</div>`);
  const hinweise = [];
  if (d.rabatteOffen) hinweise.push(`<li><a href="#/rabattabgleich">${d.rabatteOffen} Rabattabrechnung(en) warten auf Prüfung oder Freigabe</a></li>`);
  for (const v of d.vertraegeAuslaufend || []) hinweise.push(`<li>Vertrag ${esc(v.vertragsnr || v.art)} endet am ${dateDe(v.ende)}</li>`);
  for (const m of d.haccp || []) hinweise.push(`<li>HACCP-Schulung fällig: ${esc(m.vorname + ' ' + m.nachname)}${m.haccp_schulung ? ' (zuletzt ' + dateDe(m.haccp_schulung) + ')' : ''}</li>`);
  if (d.gesperrtBis) hinweise.push(`<li>Abgeschlossen bis ${dateDe(d.gesperrtBis)}</li>`);
  if (hinweise.length) lists.push(`<div class="panel"><h3>Hinweise</h3><ul class="hints">${hinweise.join('')}</ul></div>`);
  parts.push(`<div class="cols2">${lists.join('')}</div>`);
  app.innerHTML = parts.join('');
  chartTips(app);
});

function verbrauchBar(p) {
  const cls = p >= 100 ? 'crit' : p >= 80 ? 'warn' : 'ok';
  return `<span class="meter ${cls}"><span style="width:${Math.min(100, p)}%"></span></span> ${p} %`;
}
