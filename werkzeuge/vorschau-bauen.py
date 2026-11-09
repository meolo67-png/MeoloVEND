#!/usr/bin/env python3
# Baut eine klickbare Vorschau als eine HTML-Datei: die echte Oberfläche aus app/, die Server-Antworten
# kommen aus einer Aufzeichnung (rec.json, siehe werkzeuge/vorschau-aufzeichnen.js). Speichern ist abgeschaltet.
# Aufruf: python3 werkzeuge/vorschau-bauen.py rec.json ausgabe.html
import base64, json, re, sys
from pathlib import Path

app = Path(__file__).resolve().parent.parent / 'app'
rec = json.loads(Path(sys.argv[1]).read_text())
symbol = 'data:image/svg+xml;base64,' + base64.b64encode((app / 'symbol.svg').read_bytes()).decode()

html = (app / 'index.html').read_text()
body = html[html.index('<body>') + 6:html.index('<script src="app.js">')]
body = body.replace('src="symbol.svg"', f'src="{symbol}"')
body = body.replace('<h1 id="title"></h1>', '<h1 id="title"></h1><span class="demo-pill">Vorschau · Beispieldaten</span>')
scripts = re.findall(r'<script src="([^"]+)"></script>', html[html.index('<body>'):])

def js(name):
    s = (app / name).read_text()
    s = s.replace("history.replaceState(null, '', ", 'DH.replace(')
    s = s.replace("window.addEventListener('hashchange'", "window.addEventListener('dh-change'")
    return s.replace('location.hash', 'DH.hash')

shim = r'''
// Vorschau: Navigation ohne Adresszeile und Server-Antworten aus der Aufzeichnung
const DH = {
  h: '#/',
  get hash() { return this.h; },
  set hash(v) { this.h = String(v).startsWith('#') ? String(v) : '#' + v; window.dispatchEvent(new Event('dh-change')); },
  replace(v) { this.h = String(v); },
};
document.addEventListener('click', (e) => {
  const a = e.target.closest('a[href^="#"]');
  if (!a) return;
  e.preventDefault();
  DH.hash = a.getAttribute('href');
}, true);
const REC = __REC__;
const recIndex = {};
for (const k of Object.keys(REC)) { const base = k.split('&')[0]; (recIndex[base] ||= []).push(k); }
function recFind(path) {
  if (path in REC) return REC[path];
  const [base, ...rest] = path.split('&');
  const params = rest.filter((x) => !x.startsWith('q='));
  const q = decodeURIComponent((rest.find((x) => x.startsWith('q=')) || 'q=').slice(2)).toLowerCase();
  let best = null, score = -1;
  for (const k of recIndex[base] || []) {
    const kp = k.split('&').slice(1);
    const s = params.filter((x) => kp.includes(x)).length * 2 - kp.filter((x) => !params.includes(x) && !/^(von|bis|jahr)=/.test(x)).length;
    if (s > score) { score = s; best = k; }
  }
  let data = best ? REC[best] : (/^\/(r|auswahl|dok|besuche|bewegungen|audits|bestand|verschleiss|vertragsraten|rabattabgleich|bestellvorschlag)/.test(base) ? [] : null);
  if (data && q && Array.isArray(data)) data = data.filter((r) => JSON.stringify(r).toLowerCase().includes(q));
  return data;
}
const echtesFetch = window.fetch;
window.fetch = async (url, opt = {}) => {
  const m = String(url).match(/api\.php\?r=(.*)$/);
  if (!m) return echtesFetch(url, opt);
  const path = m[1], method = opt.method || 'GET';
  const antwort = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
  if (method === 'POST' && path === '/login') return antwort({ token: 'vorschau', user: REC['/meta'].user });
  if (method === 'POST' && path === '/logout') return antwort({ ok: true });
  if (method !== 'GET') return antwort({ error: 'In der Vorschau wird nichts gespeichert. Alles andere lässt sich frei anklicken.' }, 409);
  if (path.startsWith('/datev') || path.startsWith('/datei/')) return antwort({ error: 'Downloads gibt es in der Vorschau nicht.' }, 409);
  const data = recFind(path);
  return data == null ? antwort({ error: 'Diese Ansicht ist in der Vorschau nicht enthalten.' }, 404) : antwort(data);
};
'''.replace('__REC__', json.dumps(rec, ensure_ascii=False).replace('</', '<\\/'))

theme = (app / 'js/theme.js').read_text()
css = (app / 'style.css').read_text() + '''
.demo-pill { font-size: .75rem; font-weight: 600; color: var(--primary); background: var(--primary-soft); border-radius: 99px; padding: 3px 10px; white-space: nowrap; }
#logoutBtn { display: none; }
'''
code = '\n'.join(js(s) if s != 'js/start.js' else "S.token = 'vorschau';\n" + js(s) for s in scripts)
out = f'''<title>MeoloVEND Vorschau</title>
<style>{css}</style>
<script>{theme}</script>
{body}
<script>{shim}
{code}</script>
'''
Path(sys.argv[2]).write_text(out)
print(len(out), 'Bytes')
