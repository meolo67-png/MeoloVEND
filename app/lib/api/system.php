<?php
// Anmeldung, Benutzer und Rechte, Einstellungen, Beschreibung der Tabellen für die Oberfläche
declare(strict_types=1);

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function user_public(array $u): array
{
    return ['id' => $u['id'], 'name' => $u['name'], 'login' => $u['login'], 'admin' => (bool)$u['admin'], 'rechte' => is_array($u['rechte']) ? $u['rechte'] : (json_decode((string)$u['rechte'], true) ?: []),
        'mitarbeiterId' => $u['mitarbeiter_id'], 'aktiv' => (bool)$u['aktiv']];
}

route('POST', '/login', function () {
    $b = body();
    $ip = client_ip();
    q('DELETE FROM mv_login_fehler WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 900)]);
    if ((int)val('SELECT COUNT(*) FROM mv_login_fehler WHERE ip = ?', [$ip]) >= 10) throw new HttpError(429, 'Zu viele Fehlversuche. Bitte in 15 Minuten noch einmal versuchen.');
    $u = one('SELECT * FROM mv_benutzer WHERE login = ? AND aktiv = 1', [trim((string)($b['login'] ?? ''))]);
    if (!$u || !password_verify((string)($b['passwort'] ?? ''), $u['pass_hash'])) {
        q('INSERT INTO mv_login_fehler (ip, created_at) VALUES (?, ?)', [$ip, mv_now()]);
        throw new HttpError(401, 'Benutzername oder Passwort falsch');
    }
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO mv_sessions (token, benutzer_id, created_at, last_at) VALUES (?, ?, ?, ?)', [$token, $u['id'], mv_now(), mv_now()]);
    return ['token' => $token, 'user' => user_public($u)];
});

route('POST', '/logout', function () {
    $u = user();
    q('DELETE FROM mv_sessions WHERE token = ?', [$u['token']]);
    return ['ok' => true];
});

route('GET', '/version', fn() => ['version' => MV_VERSION]);

// Alles, was die Oberfläche zum Start braucht
route('GET', '/meta', function () {
    $u = user();
    $res = [];
    foreach (mv_resources() as $k => $d) {
        if (!can($d['modul'])) continue;
        $res[$k] = ['title' => $d['title'], 'single' => $d['single'], 'modul' => $d['modul'], 'docs' => !empty($d['docs']), 'list' => $d['list'] ?? array_column($d['fields'], 0),
            'write' => can($d['modul'], true), 'fields' => array_values(mv_fields($k))];
    }
    return [
        'user' => user_public($u), 'version' => MV_VERSION, 'module' => MV_MODULE, 'resources' => $res, 'komponententypen' => MV_KOMPONENTENTYPEN,
        'settings' => ['firma' => setting('firma', ''), 'waehrung' => setting('waehrung', '€'), 'mwst' => setting('mwst', 19)],
        'gesperrtBis' => locked_until(),
    ];
});

route('POST', '/me/passwort', function () {
    $u = user();
    $b = body();
    if (!password_verify((string)($b['alt'] ?? ''), $u['pass_hash'])) throw new HttpError(400, 'Das bisherige Passwort stimmt nicht');
    $neu = (string)($b['neu'] ?? '');
    if (strlen($neu) < 8) throw new HttpError(400, 'Das neue Passwort braucht mindestens 8 Zeichen');
    q('UPDATE mv_benutzer SET pass_hash = ? WHERE id = ?', [mv_hash($neu), $u['id']]);
    q('DELETE FROM mv_sessions WHERE benutzer_id = ? AND token <> ?', [$u['id'], $u['token']]);
    return ['ok' => true];
});

// ---------- Benutzer ----------

route('GET', '/benutzer', function () {
    need_admin();
    return array_map('user_public', all('SELECT * FROM mv_benutzer ORDER BY name'));
});

route('POST', '/benutzer', function () {
    $me = need_admin();
    $b = body();
    $name = trim((string)($b['name'] ?? ''));
    $login = trim((string)($b['login'] ?? ''));
    if ($name === '' || !preg_match('/^[\w.@-]{3,60}$/u', $login)) throw new HttpError(400, 'Name und Benutzername (mind. 3 Zeichen, ohne Leerzeichen) angeben');
    $rechte = [];
    foreach ((array)($b['rechte'] ?? []) as $m => $r) if (isset(MV_MODULE[$m]) && in_array($r, ['r', 'w'], true)) $rechte[$m] = $r;
    $pass = (string)($b['passwort'] ?? '');
    $id = (string)($b['id'] ?? '');
    $admin = !empty($b['admin']) ? 1 : 0;
    $aktiv = array_key_exists('aktiv', $b) ? (!empty($b['aktiv']) ? 1 : 0) : 1;
    if ($id === $me['id'] && (!$admin || !$aktiv)) throw new HttpError(400, 'Das eigene Admin-Recht kann man sich nicht selbst nehmen');
    if ($pass !== '' && strlen($pass) < 8) throw new HttpError(400, 'Das Passwort braucht mindestens 8 Zeichen');
    $ma = ($b['mitarbeiterId'] ?? '') ?: null;
    if ($id) {
        if (!one('SELECT id FROM mv_benutzer WHERE id = ?', [$id])) throw new HttpError(404, 'Benutzer nicht gefunden');
        q('UPDATE mv_benutzer SET name = ?, login = ?, admin = ?, rechte = ?, mitarbeiter_id = ?, aktiv = ? WHERE id = ?', [$name, $login, $admin, json_encode($rechte), $ma, $aktiv, $id]);
        if ($pass !== '') { q('UPDATE mv_benutzer SET pass_hash = ? WHERE id = ?', [mv_hash($pass), $id]); q('DELETE FROM mv_sessions WHERE benutzer_id = ?', [$id]); }
        if (!$aktiv) q('DELETE FROM mv_sessions WHERE benutzer_id = ?', [$id]);
    } else {
        if ($pass === '') throw new HttpError(400, 'Für neue Benutzer ein Passwort vergeben');
        $id = mv_uid();
        q('INSERT INTO mv_benutzer (id, name, login, pass_hash, admin, rechte, mitarbeiter_id, aktiv, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $name, $login, mv_hash($pass), $admin, json_encode($rechte), $ma, $aktiv, mv_now()]);
    }
    return user_public(one('SELECT * FROM mv_benutzer WHERE id = ?', [$id]));
});

// ---------- Einstellungen ----------

route('GET', '/einstellungen', function () {
    need('system');
    return ['firma' => setting('firma', ''), 'waehrung' => setting('waehrung', '€'), 'mwst' => setting('mwst', 19), 'datev' => setting('datev', [])];
});

route('POST', '/einstellungen', function () {
    need_admin();
    $b = body();
    if (isset($b['firma'])) setting_set('firma', mb_substr(trim((string)$b['firma']), 0, 120));
    if (isset($b['waehrung'])) setting_set('waehrung', mb_substr(trim((string)$b['waehrung']), 0, 5));
    if (isset($b['mwst'])) setting_set('mwst', num($b['mwst']));
    if (isset($b['datev']) && is_array($b['datev'])) {
        $d = [];
        foreach (['berater', 'mandant', 'sachkontenlaenge', 'kasse', 'erloese19', 'erloese7', 'provision', 'fehlgeld'] as $k) $d[$k] = preg_replace('/[^0-9]/', '', (string)($b['datev'][$k] ?? ''));
        setting_set('datev', $d);
    }
    return ['ok' => true];
});
