<?php
// Automat als Baukasten: Komponenten ein- und ausbauen, Historie, Verschleißteile, Automaten-Übersicht, EVA-DTS-Audits
declare(strict_types=1);

function komp_hist(string $kid, ?string $aid, string $datum, string $aktion, string $text, ?int $verkaeufe = null): void
{
    q('INSERT INTO mv_komponenten_historie (id, komponente_id, automat_id, datum, aktion, text, verkaeufe, benutzer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [mv_uid(), $kid, $aid, $datum, $aktion, mb_substr($text, 0, 255), $verkaeufe, $GLOBALS['USER']['name'] ?? 'System', mv_now()]);
}

// Verkäufe eines Automaten seit einem Datum (aus den Besuchen)
function verkaeufe_seit(string $aid, string $seit): int
{
    return (int)val('SELECT COALESCE(SUM(verkaeufe), 0) FROM mv_besuche WHERE automat_id = ? AND datum >= ?', [$aid, $seit]);
}

route('POST', '/komponenten/{id}/einbau', function ($a) {
    need('automaten', true);
    $b = body();
    $k = one('SELECT * FROM mv_komponenten WHERE id = ?', [$a['id']]);
    if (!$k) throw new HttpError(404, 'Komponente nicht gefunden');
    if ($k['status'] === 'eingebaut') throw new HttpError(409, 'Die Komponente ist schon eingebaut. Bitte zuerst ausbauen.');
    if ($k['status'] === 'ausgemustert') throw new HttpError(409, 'Die Komponente ist ausgemustert');
    $aid = (string)($b['automatId'] ?? '');
    if (!one('SELECT id FROM mv_automaten WHERE id = ?', [$aid])) throw new HttpError(400, 'Automat nicht gefunden');
    $datum = valid_date($b['datum'] ?? date('Y-m-d'));
    if (in_array($k['typ'], ['grundautomat', 'muenzer', 'banknotenleser'], true)) {
        $da = one("SELECT id, bezeichnung FROM mv_komponenten WHERE automat_id = ? AND typ = ? AND status = 'eingebaut'", [$aid, $k['typ']]);
        if ($da && empty($b['ersetzen'])) throw new HttpError(409, 'In diesem Automaten ist schon ' . MV_KOMPONENTENTYPEN[$k['typ']] . ' „' . $da['bezeichnung'] . '“ eingebaut. Bitte zuerst ausbauen.');
    }
    $GLOBALS['pdo']->beginTransaction();
    q("UPDATE mv_komponenten SET status = 'eingebaut', automat_id = ?, eingebaut_am = ?, updated_at = ? WHERE id = ?", [$aid, $datum, mv_now(), $k['id']]);
    komp_hist($k['id'], $aid, $datum, 'eingebaut', (string)($b['text'] ?? ''));
    $GLOBALS['pdo']->commit();
    return res_get('komponenten', $k['id']);
});

route('POST', '/komponenten/{id}/ausbau', function ($a) {
    need('automaten', true);
    $b = body();
    $k = one('SELECT * FROM mv_komponenten WHERE id = ?', [$a['id']]);
    if (!$k) throw new HttpError(404, 'Komponente nicht gefunden');
    if ($k['status'] !== 'eingebaut') throw new HttpError(409, 'Die Komponente ist nicht eingebaut');
    $datum = valid_date($b['datum'] ?? date('Y-m-d'));
    if ($datum < $k['eingebaut_am']) throw new HttpError(400, 'Ausbau kann nicht vor dem Einbau liegen');
    $neu = (string)($b['status'] ?? 'im Lager');
    if (!in_array($neu, ['im Lager', 'defekt', 'in Reparatur', 'ausgemustert'], true)) throw new HttpError(400, 'Ungültiger Status');
    $verk = verkaeufe_seit($k['automat_id'], $k['eingebaut_am']);
    $GLOBALS['pdo']->beginTransaction();
    q('UPDATE mv_komponenten SET status = ?, automat_id = NULL, eingebaut_am = NULL, updated_at = ? WHERE id = ?', [$neu, mv_now(), $k['id']]);
    $tage = (int)((strtotime($datum) - strtotime($k['eingebaut_am'])) / 86400);
    komp_hist($k['id'], $k['automat_id'], $datum, 'ausgebaut', trim("$neu, $tage Tage im Einsatz. " . ($b['text'] ?? '')), $verk);
    $GLOBALS['pdo']->commit();
    return res_get('komponenten', $k['id']);
});

route('POST', '/komponenten/{id}/status', function ($a) {
    need('automaten', true);
    $b = body();
    $k = one('SELECT * FROM mv_komponenten WHERE id = ?', [$a['id']]);
    if (!$k) throw new HttpError(404, 'Komponente nicht gefunden');
    $neu = (string)($b['status'] ?? '');
    if ($k['status'] === 'eingebaut' || !in_array($neu, ['im Lager', 'defekt', 'in Reparatur', 'ausgemustert'], true)) throw new HttpError(400, 'Statuswechsel nicht möglich (eingebaute Komponenten zuerst ausbauen)');
    q('UPDATE mv_komponenten SET status = ?, updated_at = ? WHERE id = ?', [$neu, mv_now(), $k['id']]);
    komp_hist($k['id'], null, date('Y-m-d'), $neu, (string)($b['text'] ?? ''));
    return res_get('komponenten', $k['id']);
});

// Eintrag in die Historie ohne Ortswechsel (Wartung, Reparatur, Tausch eines Teils …)
route('POST', '/komponenten/{id}/notiz', function ($a) {
    need('automaten', true);
    $b = body();
    $k = one('SELECT * FROM mv_komponenten WHERE id = ?', [$a['id']]);
    if (!$k) throw new HttpError(404, 'Komponente nicht gefunden');
    $text = trim((string)($b['text'] ?? ''));
    if ($text === '') throw new HttpError(400, 'Bitte einen Text eingeben');
    komp_hist($k['id'], $k['automat_id'], valid_date($b['datum'] ?? date('Y-m-d')), mb_substr((string)($b['aktion'] ?? 'Wartung'), 0, 40), $text);
    return ['ok' => true];
});

route('GET', '/komponenten/{id}/historie', function ($a) {
    need('automaten');
    return all('SELECT h.*, ' . res_label_sql('automaten', 'a') . ' AS automat FROM mv_komponenten_historie h LEFT JOIN mv_automaten a ON a.id = h.automat_id WHERE h.komponente_id = ? ORDER BY h.datum DESC, h.created_at DESC', [$a['id']]);
});

route('GET', '/automaten/{id}/historie', function ($a) {
    need('automaten');
    return all('SELECT h.*, k.typ, k.bezeichnung, k.seriennr FROM mv_komponenten_historie h JOIN mv_komponenten k ON k.id = h.komponente_id WHERE h.automat_id = ? ORDER BY h.datum DESC, h.created_at DESC', [$a['id']]);
});

// Verschleißteile und Komponenten mit Lebensdauer: wie weit verbraucht?
function verschleiss_liste(?string $aid = null): array
{
    $p = [];
    $w = "k.status = 'eingebaut' AND (k.lebensdauer_tage > 0 OR k.lebensdauer_verkaeufe > 0)";
    if ($aid) { $w .= ' AND k.automat_id = ?'; $p[] = $aid; }
    $out = [];
    foreach (all("SELECT k.*, " . res_label_sql('automaten', 'a') . " AS automat FROM mv_komponenten k JOIN mv_automaten a ON a.id = k.automat_id WHERE $w", $p) as $k) {
        $tage = (int)((time() - strtotime($k['eingebaut_am'])) / 86400);
        $verk = verkaeufe_seit($k['automat_id'], $k['eingebaut_am']);
        $pt = $k['lebensdauer_tage'] ? $tage / $k['lebensdauer_tage'] : 0;
        $pv = $k['lebensdauer_verkaeufe'] ? $verk / $k['lebensdauer_verkaeufe'] : 0;
        $pz = max($pt, $pv);
        $faellig = null;
        if ($k['lebensdauer_tage']) $faellig = date('Y-m-d', strtotime($k['eingebaut_am'] . ' +' . (int)$k['lebensdauer_tage'] . ' days'));
        $out[] = ['id' => $k['id'], 'typ' => $k['typ'], 'bezeichnung' => $k['bezeichnung'], 'seriennr' => $k['seriennr'], 'automatId' => $k['automat_id'], 'automat' => $k['automat'],
            'eingebautAm' => $k['eingebaut_am'], 'tage' => $tage, 'lebensdauerTage' => $k['lebensdauer_tage'] ? (int)$k['lebensdauer_tage'] : null,
            'verkaeufe' => $verk, 'lebensdauerVerkaeufe' => $k['lebensdauer_verkaeufe'] ? (int)$k['lebensdauer_verkaeufe'] : null,
            'verbraucht' => round($pz * 100), 'faelligAm' => $faellig];
    }
    usort($out, fn($x, $y) => $y['verbraucht'] <=> $x['verbraucht']);
    return $out;
}

route('GET', '/verschleiss', function () {
    need('automaten');
    return verschleiss_liste($_GET['automat'] ?? null);
});

// Alles zu einem Automaten auf einen Blick
route('GET', '/automaten/{id}/uebersicht', function ($a) {
    need('automaten');
    $am = res_get('automaten', $a['id']);
    $letzter = one('SELECT * FROM mv_besuche WHERE automat_id = ? ORDER BY datum DESC, created_at DESC LIMIT 1', [$a['id']]);
    $monate = all("SELECT DATE_FORMAT(datum, '%Y-%m') AS monat, SUM(soll_bar + bargeldlos) AS umsatz, SUM(fehlgeld) AS fehlgeld, SUM(verkaeufe) AS verkaeufe, COUNT(*) AS besuche
        FROM mv_besuche WHERE automat_id = ? AND datum >= ? GROUP BY monat ORDER BY monat", [$a['id'], date('Y-m-01', strtotime('-11 months'))]);
    foreach ($monate as &$m) { $m['umsatz'] = (float)$m['umsatz']; $m['fehlgeld'] = (float)$m['fehlgeld']; $m['verkaeufe'] = (int)$m['verkaeufe']; $m['besuche'] = (int)$m['besuche']; }
    $kunde = null;
    if ($am['standort_id']) $kunde = one('SELECT k.id, k.firma, k.kundennr FROM mv_standorte s JOIN mv_kunden k ON k.id = s.kunde_id WHERE s.id = ?', [$am['standort_id']]);
    return [
        'automat' => $am, 'kunde' => $kunde,
        'komponenten' => res_list('komponenten', ['automat_id' => $a['id']]),
        'faecher' => fach_bestand($a['id']),
        'letzterBesuch' => $letzter,
        'monate' => $monate,
        'offeneStoerungen' => (int)val("SELECT COUNT(*) FROM mv_stoerungen WHERE automat_id = ? AND status <> 'erledigt'", [$a['id']]),
        'verschleiss' => verschleiss_liste($a['id']),
        'investition' => (float)val("SELECT COALESCE(SUM(kaufpreis), 0) FROM mv_komponenten WHERE automat_id = ? AND status = 'eingebaut'", [$a['id']]),
    ];
});

// ---------- EVA-DTS (DEX/UCS) Audits ----------

// Liest die wichtigsten Werte aus einer EVA-DTS-Datei: Seriennummer, Gesamtzähler, Fächer
function evadts_parse(string $txt): array
{
    $r = ['geraetId' => '', 'modell' => '', 'verkaeufe' => null, 'umsatz' => null, 'bar' => null, 'bargeldlos' => null, 'faecher' => []];
    $dez = 2;
    $fach = null;
    foreach (preg_split('/\r\n|\r|\n/', $txt) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $f = explode('*', $line);
        $tag = strtoupper($f[0]);
        $n = fn($i) => isset($f[$i]) && $f[$i] !== '' && is_numeric($f[$i]) ? (float)$f[$i] : null;
        $geld = fn($i) => $n($i) === null ? null : round($n($i) / (10 ** $dez), 2);
        switch ($tag) {
            case 'ID1': $r['geraetId'] = trim($f[1] ?? ''); $r['modell'] = trim($f[2] ?? ''); break;
            case 'ID4': if ($n(1) !== null) $dez = (int)$n(1); break;
            case 'VA1': $r['umsatz'] = $geld(1); $r['verkaeufe'] = $n(2) === null ? null : (int)$n(2); break;
            case 'CA2': $r['bar'] = $geld(1); break;
            case 'DA2': $r['bargeldlos'] = $geld(1); break;
            case 'PA1': $fach = trim($f[1] ?? ''); $r['faecher'][$fach] = ['fach' => $fach, 'preis' => $geld(2), 'verkaeufe' => null, 'umsatz' => null]; break;
            case 'PA2': if ($fach !== null) { $r['faecher'][$fach]['verkaeufe'] = $n(1) === null ? null : (int)$n(1); $r['faecher'][$fach]['umsatz'] = $geld(2); } break;
        }
    }
    if ($r['bar'] === null && $r['umsatz'] !== null && $r['bargeldlos'] !== null) $r['bar'] = round($r['umsatz'] - $r['bargeldlos'], 2);
    if ($r['bar'] === null && $r['umsatz'] !== null && $r['bargeldlos'] === null) $r['bar'] = $r['umsatz'];
    $r['faecher'] = array_values($r['faecher']);
    if ($r['geraetId'] === '' && $r['umsatz'] === null) throw new HttpError(400, 'Das sieht nicht nach einer EVA-DTS-Datei aus (ID1 / VA1 fehlen)');
    return $r;
}

route('POST', '/audits', function () {
    $u = need('touren', true);
    $b = body();
    $p = evadts_parse((string)($b['text'] ?? ''));
    $aid = ($b['automatId'] ?? '') ?: null;
    if (!$aid && $p['geraetId'] !== '') $aid = val('SELECT id FROM mv_automaten WHERE telemetrie_id = ? OR nummer = ? LIMIT 1', [$p['geraetId'], $p['geraetId']]);
    $id = mv_uid();
    q('INSERT INTO mv_audits (id, automat_id, geraet_id, datum, quelle, verkaeufe_gesamt, umsatz_gesamt, bar_gesamt, bargeldlos_gesamt, faecher, rohdaten, benutzer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $aid, $p['geraetId'], mv_now(), mb_substr((string)($b['quelle'] ?? 'Datei'), 0, 30), $p['verkaeufe'], $p['umsatz'], $p['bar'], $p['bargeldlos'], json_encode($p['faecher']), mb_substr((string)$b['text'], 0, 200000), $u['name'], mv_now()]);
    return ['id' => $id, 'automatId' => $aid] + $p;
});

route('GET', '/audits', function () {
    need('touren');
    $p = [];
    $w = '1=1';
    if (!empty($_GET['automat'])) { $w = 'x.automat_id = ?'; $p[] = $_GET['automat']; }
    return all('SELECT x.id, x.automat_id, x.geraet_id, x.datum, x.quelle, x.verkaeufe_gesamt, x.umsatz_gesamt, x.bar_gesamt, x.bargeldlos_gesamt, x.besuch_id, x.benutzer, '
        . res_label_sql('automaten', 'a') . " AS automat FROM mv_audits x LEFT JOIN mv_automaten a ON a.id = x.automat_id WHERE $w ORDER BY x.datum DESC LIMIT 500", $p);
});
