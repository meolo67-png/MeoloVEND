'use strict';
// System: Benutzer mit Rechten je Bereich, Einstellungen (Firma, DATEV-Konten), eigenes Konto

route('/benutzer', async (_, app) => {
  setTitle('Benutzer und Rechte', '<button class="primary" id="neuBtn">+ Benutzer</button>');
  const rows = await GET('/benutzer');
  const mod = S.meta.module;
  app.innerHTML = `<div class="panel"><p class="muted">Jeder Benutzer sieht nur die Bereiche, für die er Rechte hat (lesen oder ändern). Administratoren dürfen alles.</p>
    ${table([{ key: 'name', label: 'Name' }, { key: 'login', label: 'Benutzername' }, { key: 'admin', label: 'Rechte', fmt: (u) => (u.admin ? '<span class="pill">Administrator</span>' : Object.entries(u.rechte).map(([m, r]) => `<span class="pill ${r === 'w' ? '' : 'muted'}">${esc(mod[m])}${r === 'r' ? ' (lesen)' : ''}</span>`).join(' ')) },
      { key: 'aktiv', label: 'Aktiv', type: 'bool' }], rows, { onRow: benutzerDialog })}</div>`;
  $('#neuBtn').onclick = () => benutzerDialog({ aktiv: true, rechte: {} });
});

async function benutzerDialog(u) {
  const ma = await auswahl('mitarbeiter');
  const mod = S.meta.module;
  const b = modal(u.id ? 'Benutzer bearbeiten' : 'Neuer Benutzer', `<form class="stack" id="uf"><div class="grid2">
      <label>Name * <input name="name" value="${esc(u.name || '')}" required></label><label>Benutzername * <input name="login" value="${esc(u.login || '')}" required autocomplete="off"></label>
      <label>${u.id ? 'Neues Passwort (leer = unverändert)' : 'Passwort * (mind. 8 Zeichen)'} <input name="pw" type="password" autocomplete="new-password" ${u.id ? '' : 'required'} minlength="8"></label>
      <label>Mitarbeiter (für Touren) <select name="ma"><option value=""></option>${ma.map((m) => `<option value="${m.id}"${m.id === u.mitarbeiterId ? ' selected' : ''}>${esc(m.label)}</option>`).join('')}</select></label>
      <label class="check"><input type="checkbox" name="admin"${u.admin ? ' checked' : ''}> Administrator (alle Rechte)</label><label class="check"><input type="checkbox" name="aktiv"${u.aktiv ? ' checked' : ''}> aktiv</label></div>
    <table class="tbl rechte"><thead><tr><th>Bereich</th><th>kein Zugriff</th><th>lesen</th><th>ändern</th></tr></thead><tbody>
      ${Object.entries(mod).map(([k, t]) => `<tr><td>${esc(t)}</td>${['', 'r', 'w'].map((r) => `<td><input type="radio" name="r_${k}" value="${r}"${(u.rechte[k] || '') === r ? ' checked' : ''}></td>`).join('')}</tr>`).join('')}</tbody></table>
    <div class="row end"><button type="button" id="ab">Abbrechen</button><button class="primary">Speichern</button></div></form>`, true);
  const f = $('#uf', b);
  const sync = () => { $('.rechte', b).hidden = f.admin.checked; };
  f.admin.onchange = sync; sync();
  $('#ab', b).onclick = closeModal;
  f.onsubmit = async (e) => {
    e.preventDefault();
    const rechte = {};
    for (const k of Object.keys(mod)) { const v = f['r_' + k].value; if (v) rechte[k] = v; }
    try { await POST('/benutzer', { id: u.id, name: f.name.value, login: f.login.value, passwort: f.pw.value, mitarbeiterId: f.ma.value, admin: f.admin.checked, aktiv: f.aktiv.checked, rechte }); closeModal(); toast('Gespeichert'); rerender(); } catch (e2) { fail(e2); }
  };
}

route('/einstellungen', async (_, app) => {
  setTitle('Einstellungen');
  const s = await GET('/einstellungen');
  const d = s.datev || {};
  const adm = S.meta.user.admin;
  app.innerHTML = `<form class="panel narrow stack" id="ef"><h3>Betrieb</h3><div class="grid3">
      <label>Firma <input name="firma" value="${esc(s.firma)}"></label><label>Währung <input name="waehrung" value="${esc(s.waehrung)}"></label><label>MwSt. Standard % <input name="mwst" value="${esc(s.mwst)}"></label></div>
    <h3>DATEV</h3><div class="grid3">
      <label>Beraternummer <input name="berater" value="${esc(d.berater || '')}"></label><label>Mandantennummer <input name="mandant" value="${esc(d.mandant || '')}"></label><label>Sachkontenlänge <input name="sachkontenlaenge" value="${esc(d.sachkontenlaenge || 4)}"></label>
      <label>Kasse <input name="kasse" value="${esc(d.kasse || '1000')}"></label><label>Erlöse 19 % <input name="erloese19" value="${esc(d.erloese19 || '8400')}"></label><label>Erlöse 7 % <input name="erloese7" value="${esc(d.erloese7 || '8300')}"></label>
      <label>Provisionen <input name="provision" value="${esc(d.provision || '4760')}"></label><label>Kassendifferenzen <input name="fehlgeld" value="${esc(d.fehlgeld || '6969')}"></label></div>
    <p class="muted small">Vorgabe: SKR03. Für SKR04 die Konten mit dem Steuerberater abstimmen.</p>
    ${adm ? '<div class="row end"><button class="primary">Speichern</button></div>' : ''}</form>`;
  $('#ef').onsubmit = async (e) => {
    e.preventDefault();
    const f = e.target;
    const datev = {};
    for (const k of ['berater', 'mandant', 'sachkontenlaenge', 'kasse', 'erloese19', 'erloese7', 'provision', 'fehlgeld']) datev[k] = f[k].value;
    try { await POST('/einstellungen', { firma: f.firma.value, waehrung: f.waehrung.value, mwst: f.mwst.value, datev }); toast('Gespeichert'); S.meta = await GET('/meta'); } catch (e2) { fail(e2); }
  };
});

route('/ich', async (_, app) => {
  setTitle('Mein Konto');
  app.innerHTML = `<form class="panel narrow stack" id="pf"><p>Angemeldet als <strong>${esc(S.meta.user.name)}</strong> (${esc(S.meta.user.login)})</p><h3>Passwort ändern</h3>
    <label>Bisheriges Passwort <input type="password" name="alt" required autocomplete="current-password"></label>
    <label>Neues Passwort (mind. 8 Zeichen) <input type="password" name="neu" minlength="8" required autocomplete="new-password"></label>
    <div class="row end"><button class="primary">Ändern</button></div></form>`;
  $('#pf').onsubmit = async (e) => { e.preventDefault(); try { await POST('/me/passwort', { alt: e.target.alt.value, neu: e.target.neu.value }); toast('Passwort geändert'); e.target.reset(); } catch (e2) { fail(e2); } };
});
