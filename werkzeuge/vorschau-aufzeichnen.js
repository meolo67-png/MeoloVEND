// Zeichnet die Server-Antworten einer Installation mit Beispieldaten für die klickbare Vorschau auf.
// Aufruf: MV_URL=http://127.0.0.1:8091/ node werkzeuge/vorschau-aufzeichnen.js rec.json (Login admin / geheim123), danach vorschau-bauen.py
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1360, height: 860 } });
  await p.addInitScript(() => {
    window.__rec = {};
    const of = window.fetch;
    window.fetch = async (url, opt) => {
      const res = await of(url, opt);
      const m = String(url).match(/api\.php\?r=(.*)$/);
      if (m && (!opt || !opt.method || opt.method === 'GET') && res.ok && (res.headers.get('content-type') || '').includes('json')) {
        try { window.__rec[m[1]] = await res.clone().json(); } catch (e) {}
      }
      return res;
    };
  });
  const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(process.env.MV_URL || 'http://127.0.0.1:8091/');
  await p.fill('input[name=login]', 'admin'); await p.fill('input[name=passwort]', 'geheim123');
  await p.click('#loginForm button'); await p.waitForSelector('#nav a');
  const go = async (h, w = 700) => { await p.evaluate(x => location.hash = x, h); await p.waitForTimeout(w); };
  // gezielte Abrufe für Dialoge und Details
  await p.evaluate(async () => {
    const res = Object.keys(S.meta.resources);
    for (const r of res) {
      await GET('/auswahl/' + r);
      const rows = await GET('/r/' + r);
      for (const x of rows) { await GET(`/r/${r}/${x.id}`); if (S.meta.resources[r].docs) await GET(`/dok/${r}/${x.id}`); }
    }
    for (const k of await GET('/r/komponenten')) await GET(`/komponenten/${k.id}/historie`);
    const am = await GET('/r/automaten');
    for (const a of am) {
      for (const x of [`/automaten/${a.id}/uebersicht`, `/automaten/${a.id}/historie`, `/besuche-vorlage/${a.id}`, `/besuche&automat=${a.id}`, `/r/stoerungen&f_automat_id=${a.id}`, `/r/kosten&f_automat_id=${a.id}`, `/r/vertraege&f_automat_id=${a.id}`, `/r/faecher&f_automat_id=${a.id}`, `/verschleiss&automat=${a.id}`]) await GET(x);
    }
    for (const b of await GET('/besuche')) await GET('/besuche/' + b.id);
    for (const t of await GET('/r/touren')) await GET(`/touren/${t.id}/plan`);
    for (const l of await GET('/r/lager')) { await GET('/bestand&lager=' + l.id); }
    for (const g of ['automat', 'kunde', 'tour', 'mitarbeiter', 'tag', 'monat']) await GET(`/einnahmen&von=2026-09-01&bis=2026-11-30&gruppe=${g}`);
    await GET('/sollist&jahr=2026'); await GET('/sollist&jahr=2025');
    await GET('/rabattabgleich&von=2026-01-01&bis=2026-12-31&status=');
    await GET('/vertragsraten&von=2026-01-01&bis=2026-12-31');
    await GET('/erfolgsrechnung&von=2026-01-01&bis=2026-12-31');
    for (const m of ['2026-09', '2026-10', '2026-11']) { const e = new Date(+m.slice(0,4), +m.slice(5), 0); await GET(`/erfolgsrechnung&von=${m}-01&bis=${m}-${String(e.getDate()).padStart(2,'0')}`); }
    await GET('/besuche&von=2026-09-01&bis=2026-11-30');
  });
  // UI abfahren (zeichnet die Standardabfragen mit den Datumswerten von heute auf)
  const hrefs = await p.$$eval('#nav a', as => as.map(a => a.getAttribute('href')));
  for (const h of hrefs) await go(h, 900);
  const am = await p.evaluate(async () => (await GET('/r/automaten')).map(a => a.id));
  for (const a of am) for (const t of ['uebersicht','komponenten','faecher','besuche','stoerungen','kosten','vertraege','historie','dokumente']) await go(`#/automat/${a}?tab=${t}`, 400);
  for (const a of am) await go('#/besuch?automat=' + a, 600);
  await go('#/einnahmen', 600);
  for (const g of ['kunde','tour','mitarbeiter','tag','monat']) { await p.selectOption('#gr', g); await p.waitForTimeout(400); }
  const rec = await p.evaluate(() => window.__rec);
  fs.writeFileSync(process.argv[2] || 'rec.json', JSON.stringify(rec));
  console.log(Object.keys(rec).length, 'Antworten', JSON.stringify(rec).length, 'Bytes', errs.join('|'));
  await b.close();
})();
