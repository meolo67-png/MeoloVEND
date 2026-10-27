<?php
// MeoloVEND – Ablauftest gegen eine laufende Installation mit Beispieldaten
// Aufruf: php tests/api-test.php http://127.0.0.1:8090 admin passwort
declare(strict_types=1);

[$_, $base, $login, $pass] = $argv + [null, 'http://127.0.0.1:8090', 'admin', 'geheim123'];
$token = '';
$fehler = 0;

function api(string $method, string $path, ?array $body = null, int $erwartet = 200)
{
    global $base, $token;
    $ch = curl_init($base . '/api.php?r=' . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', $token ? "Authorization: Bearer $token" : null])]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode((string)$res, true);
    if ($code !== $erwartet) throw new RuntimeException("$method $path: HTTP $code statt $erwartet: " . substr((string)$res, 0, 300));
    return $data;
}

function test(string $name, callable $fn): void
{
    global $fehler;
    try { $fn(); echo "ok   $name\n"; }
    catch (Throwable $e) { $fehler++; echo "FEHL $name: " . $e->getMessage() . "\n"; }
}

function gleich($ist, $soll, string $was): void
{
    if (is_float($soll) || is_float($ist)) { if (abs((float)$ist - (float)$soll) > 0.005) throw new RuntimeException("$was: $ist statt $soll"); return; }
    if ($ist !== $soll) throw new RuntimeException("$was: " . json_encode($ist) . ' statt ' . json_encode($soll));
}

test('Anmeldung falsch', fn() => api('POST', '/login', ['login' => $login, 'passwort' => 'falsch'], 401));
test('Anmeldung', function () use ($login, $pass) { global $token; $token = api('POST', '/login', ['login' => $login, 'passwort' => $pass])['token']; });

$am = null; $art = null; $fz = null;
test('Stammdaten lesen', function () use (&$am, &$art, &$fz) {
    $am = api('GET', '/r/automaten')[0];
    $art = api('GET', '/r/artikel');
    $fz = array_values(array_filter(api('GET', '/r/lager'), fn($l) => $l['art'] === 'Fahrzeug'))[0];
    gleich($am['standort_id__label'] !== null, true, 'Verweis-Anzeige');
});

test('Pflichtfeld fehlt', fn() => api('POST', '/r/kunden', ['ansprechpartner' => 'x'], 400));
$kid = null;
test('Kunde anlegen mit Nummer', function () use (&$kid) {
    $k = api('POST', '/r/kunden', ['firma' => 'Testkunde GmbH']);
    $kid = $k['id'];
    if (!preg_match('/^\d+$/', $k['kundennr'])) throw new RuntimeException('keine Kundennummer');
});
test('Kunde löschen', fn() => api('DELETE', "/r/kunden/$kid"));
test('Benutzter Kunde nicht löschbar', function () use ($am) {
    $k = api('GET', '/r/kunden')[0];
    api('DELETE', '/r/kunden/' . $k['id'], null, 409);
});

$b1 = null;
test('Besuch mit Zählern, Befüllung, Fehlgeld', function () use ($am, $art, $fz, &$b1) {
    $v = api('GET', '/besuche-vorlage/' . $am['id']);
    $prev = $v['vorher'];
    $f = $v['faecher'][0];
    $b1 = api('POST', '/besuche', ['datum' => date('Y-m-d'), 'automatId' => $am['id'], 'fahrzeugId' => $fz['id'],
        'zaehlerBar' => $prev['zaehler_bar'] + 100, 'zaehlerBargeldlos' => $prev['zaehler_bargeldlos'] + 30, 'zaehlerVerkaeufe' => $prev['zaehler_verkaeufe'] + 70,
        'istMuenzen' => 48.0, 'istScheine' => 50, 'roehren' => 0, 'befuellung' => [['fach' => $f['fach'], 'artikelId' => $f['artikel_id'], 'menge' => 20]]]);
    gleich($b1['soll_bar'], 100.0, 'Soll');
    gleich($b1['bargeldlos'], 30.0, 'bargeldlos');
    gleich($b1['verkaeufe'], 70, 'Verkäufe');
    gleich($b1['fehlgeld'], -2.0, 'Fehlgeld');
    gleich(count($b1['positionen']), 1, 'Befüllposition');
});
test('Zähler kleiner als vorher wird abgelehnt', function () use ($am) {
    api('POST', '/besuche', ['datum' => date('Y-m-d'), 'automatId' => $am['id'], 'zaehlerBar' => 1], 400);
});
test('Besuch ändern rechnet neu', function () use (&$b1) {
    $b = api('POST', '/besuche', ['id' => $b1['id'], 'datum' => $b1['datum'], 'automatId' => $b1['automat_id'], 'fahrzeugId' => $b1['fahrzeug_id'],
        'zaehlerBar' => $b1['zaehler_bar'], 'zaehlerBargeldlos' => $b1['zaehler_bargeldlos'], 'zaehlerVerkaeufe' => $b1['zaehler_verkaeufe'], 'istMuenzen' => 50, 'istScheine' => 50]);
    gleich($b['fehlgeld'], 0.0, 'Fehlgeld nach Änderung');
    gleich(count($b['positionen']), 0, 'Befüllung ersetzt');
    $b1 = $b;
});

test('Komponente ein- und ausbauen mit Historie', function () use ($am) {
    $k = api('POST', '/r/komponenten', ['typ' => 'banknotenleser', 'bezeichnung' => 'BNL 500', 'seriennr' => 'T-1', 'lebensdauer_tage' => 1000]);
    gleich($k['status'], 'im Lager', 'Status neu');
    $k = api('POST', "/komponenten/{$k['id']}/einbau", ['automatId' => $am['id'], 'datum' => date('Y-m-d')]);
    gleich($k['status'], 'eingebaut', 'Status eingebaut');
    api('POST', "/komponenten/{$k['id']}/einbau", ['automatId' => $am['id']], 409);
    api('DELETE', "/r/komponenten/{$k['id']}", null, 409);
    $k = api('POST', "/komponenten/{$k['id']}/ausbau", ['datum' => date('Y-m-d'), 'status' => 'defekt']);
    gleich($k['status'], 'defekt', 'Status nach Ausbau');
    gleich(count(api('GET', "/komponenten/{$k['id']}/historie")), 3, 'Historie');
    api('DELETE', "/r/komponenten/{$k['id']}");
});

test('Wareneingang, Umlagerung, Inventur', function () use ($art, $fz) {
    $lager = array_values(array_filter(api('GET', '/r/lager'), fn($l) => $l['art'] === 'Lager'))[0];
    $a = $art[0]['id'];
    $vor = array_sum(array_column(api('GET', "/bestand&lager={$lager['id']}&artikel=$a"), 'menge'));
    api('POST', '/bewegungen', ['art' => 'wareneingang', 'datum' => date('Y-m-d'), 'nachLager' => $lager['id'], 'positionen' => [['artikelId' => $a, 'menge' => 10]]]);
    api('POST', '/bewegungen', ['art' => 'umlagerung', 'datum' => date('Y-m-d'), 'vonLager' => $lager['id'], 'nachLager' => $fz['id'], 'positionen' => [['artikelId' => $a, 'menge' => 4]]]);
    gleich(array_sum(array_column(api('GET', "/bestand&lager={$lager['id']}&artikel=$a"), 'menge')), $vor + 6.0, 'Bestand nach Umlagerung');
    $r = api('POST', '/inventur', ['lager' => $lager['id'], 'datum' => date('Y-m-d'), 'zaehlung' => [['artikelId' => $a, 'menge' => $vor + 5]]]);
    gleich($r['differenzen'][0]['differenz'], -1.0, 'Inventurdifferenz');
    gleich(array_sum(array_column(api('GET', "/bestand&lager={$lager['id']}&artikel=$a"), 'menge')), $vor + 5.0, 'Bestand nach Inventur');
});

test('EVA-DTS-Audit einlesen', function () use ($am) {
    $dex = "DXS*1234567*VA*V0/6*1\nST*001*0001\nID1*SN-1001*SNACK6000*0001\nID4*2*49\nCA2*125050*830\nDA2*40000*210\nVA1*165050*1040\nPA1*A1*150\nPA2*500*75000\nPA1*A2*180\nPA2*540*90050\nG85*1234\nSE*10*0001\nDXE*1*1";
    $r = api('POST', '/audits', ['text' => $dex]);
    gleich($r['automatId'], $am['id'], 'Automat erkannt');
    gleich($r['bar'], 1250.50, 'Bar gesamt');
    gleich($r['bargeldlos'], 400.0, 'Bargeldlos gesamt');
    gleich($r['verkaeufe'], 1040, 'Verkäufe gesamt');
    gleich(count($r['faecher']), 2, 'Fächer');
    gleich($r['faecher'][1]['umsatz'], 900.50, 'Fachumsatz');
});

test('Rabatte berechnen und Endkontrolle', function () {
    $von = date('Y-01-01');
    $bis = date('Y-12-31');
    api('POST', '/rabatte/berechnen', ['von' => $von, 'bis' => $bis]);
    $rows = api('GET', "/rabattabgleich&von=$von&bis=$bis");
    if (!$rows) throw new RuntimeException('keine Abrechnungen');
    $st = array_values(array_filter($rows, fn($r) => $r['art'] === 'standplatz'))[0];
    gleich($st['betrag'], 5.0 * count($st['details']), 'Standplatzgebühr');
    api('POST', "/rabattabrechnungen/{$st['id']}/status", ['status' => 'freigegeben'], 409);
    api('POST', "/rabattabrechnungen/{$st['id']}/status", ['status' => 'geprueft']);
    api('POST', "/rabattabrechnungen/{$st['id']}/status", ['status' => 'freigegeben']);
});

test('Abschluss sperrt und Korrekturbuchung', function () use (&$b1) {
    $gestern = date('Y-m-d', strtotime('-1 day'));
    $alter = api('GET', '/besuche&bis=' . $gestern)[0];
    api('POST', '/abschluesse', ['art' => 'kasse', 'bis' => date('Y-m-d', strtotime('+1 day'))], 400);
    $a = api('POST', '/abschluesse', ['art' => 'kasse', 'bis' => $gestern]);
    gleich($a['bis'], $gestern, 'Abschluss bis');
    api('DELETE', '/besuche/' . $alter['id'], null, 409);
    api('POST', '/r/kosten', ['automat_id' => $alter['automat_id'], 'datum' => $gestern, 'art' => 'Strom', 'betrag' => 5], 409);
    $k = api('POST', '/besuche/' . $alter['id'] . '/korrektur', ['datum' => date('Y-m-d'), 'ist' => 2.5, 'grund' => 'Münzen im Automaten gefunden']);
    gleich(count($k['korrekturen']), 1, 'Korrektur angelegt');
    gleich($k['korrekturen'][0]['fehlgeld'], 2.5, 'Korrektur-Fehlgeld');
    gleich($k['gesperrt'], true, 'Besuch gesperrt');
});

test('Erfolgsrechnung', function () {
    $f = api('GET', '/erfolgsrechnung&von=' . date('Y-01-01') . '&bis=' . date('Y-12-31'))['fakten'];
    $u = array_sum(array_column($f, 'umsatz'));
    $e = api('GET', '/einnahmen&von=' . date('Y-01-01') . '&bis=' . date('Y-12-31'))['summe']['umsatz'];
    gleich(round($u, 2), round((float)$e, 2), 'Umsatz Erfolgsrechnung = Einnahmen');
});

test('Benutzer mit eingeschränkten Rechten', function () use ($base) {
    global $token;
    api('POST', '/benutzer', ['name' => 'Fahrer Test', 'login' => 'fahrer' . random_int(100, 999) , 'passwort' => 'fahrer123', 'rechte' => ['touren' => 'w', 'automaten' => 'r']]);
    $u = array_values(array_filter(api('GET', '/benutzer'), fn($x) => $x['name'] === 'Fahrer Test'));
    $admin = $token;
    $token = api('POST', '/login', ['login' => end($u)['login'], 'passwort' => 'fahrer123'])['token'];
    api('GET', '/r/automaten');
    api('GET', '/r/kunden', null, 403);
    api('POST', '/r/automaten', ['bezeichnung' => 'x'], 403);
    api('GET', '/erfolgsrechnung', null, 403);
    $meta = api('GET', '/meta');
    gleich(isset($meta['resources']['kunden']), false, 'Kunden in Meta versteckt');
    $token = $admin;
});

echo $fehler ? "\n$fehler Test(s) fehlgeschlagen\n" : "\nAlle Tests bestanden\n";
exit($fehler ? 1 : 0);
