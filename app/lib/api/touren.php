<?php
// Touren und Besuche: Zählerstände, Befüllung, Kassierung mit Soll/Ist und Fehlgeld, Korrekturbuchungen
declare(strict_types=1);

// Letzter regulärer Besuch vor einem Datum (Korrekturen haben keine Zählerstände)
function besuch_vorher(string $aid, string $datum, string $createdAt, ?string $ohne = null): ?array
{
    return one('SELECT * FROM mv_besuche WHERE automat_id = ? AND korrektur_von IS NULL AND id <> ? AND (datum < ? OR (datum = ? AND created_at < ?)) ORDER BY datum DESC, created_at DESC LIMIT 1',
        [$aid, $ohne ?? '', $datum, $datum, $createdAt]);
}

function besuch_nachher(array $b): ?array
{
    return one('SELECT * FROM mv_besuche WHERE automat_id = ? AND korrektur_von IS NULL AND id <> ? AND (datum > ? OR (datum = ? AND created_at > ?)) ORDER BY datum, created_at LIMIT 1',
        [$b['automat_id'], $b['id'], $b['datum'], $b['datum'], $b['created_at']]);
}

// Soll, Verkäufe, bargeldlos und Fehlgeld aus den Zählerständen berechnen
function besuch_rechnen(array $b, ?array $prev, ?float $sollManuell = null, ?float $bargeldlosManuell = null): array
{
    $ist = round((float)$b['ist_muenzen'] + (float)$b['ist_scheine'] + (float)$b['roehren'], 2);
    if ($b['zaehler_bar'] !== null && $prev && $prev['zaehler_bar'] !== null) $b['soll_bar'] = round((float)$b['zaehler_bar'] - (float)$prev['zaehler_bar'], 2);
    elseif ($sollManuell !== null) $b['soll_bar'] = $sollManuell;
    else $b['soll_bar'] = $b['soll_bar'] ?? $ist; // ohne Zähler: Soll = gezählt (kein Fehlgeld erkennbar)
    if ($b['zaehler_bargeldlos'] !== null && $prev && $prev['zaehler_bargeldlos'] !== null) $b['bargeldlos'] = round((float)$b['zaehler_bargeldlos'] - (float)$prev['zaehler_bargeldlos'], 2);
    elseif ($bargeldlosManuell !== null) $b['bargeldlos'] = $bargeldlosManuell;
    if ($b['zaehler_verkaeufe'] !== null && $prev && $prev['zaehler_verkaeufe'] !== null) $b['verkaeufe'] = (int)$b['zaehler_verkaeufe'] - (int)$prev['zaehler_verkaeufe'];
    $b['fehlgeld'] = round($ist - (float)$b['soll_bar'], 2);
    return $b;
}

// Nach einer Änderung den folgenden Besuch neu rechnen (sein Soll hängt am Zählerstand davor)
function besuch_folge_neu(array $b): void
{
    $n = besuch_nachher($b);
    if (!$n) return;
    $l = locked_until();
    if ($l && $n['datum'] <= $l) return; // abgeschlossene Besuche bleiben unverändert
    $prev = besuch_vorher($n['automat_id'], $n['datum'], $n['created_at'], $n['id']);
    if ($n['zaehler_bar'] === null && $n['zaehler_bargeldlos'] === null && $n['zaehler_verkaeufe'] === null) return;
    $neu = besuch_rechnen($n, $prev);
    q('UPDATE mv_besuche SET soll_bar = ?, bargeldlos = ?, verkaeufe = ?, fehlgeld = ? WHERE id = ?', [$neu['soll_bar'], $neu['bargeldlos'], $neu['verkaeufe'], $neu['fehlgeld'], $n['id']]);
}

function besuch_get(string $id): array
{
    $b = one('SELECT b.*, ' . res_label_sql('automaten', 'a') . ' AS automat, CONCAT_WS(\' \', m.vorname, m.nachname) AS mitarbeiter, t.name AS tour, l.name AS fahrzeug
        FROM mv_besuche b JOIN mv_automaten a ON a.id = b.automat_id LEFT JOIN mv_mitarbeiter m ON m.id = b.mitarbeiter_id LEFT JOIN mv_touren t ON t.id = b.tour_id LEFT JOIN mv_lager l ON l.id = b.fahrzeug_id WHERE b.id = ?', [$id]);
    if (!$b) throw new HttpError(404, 'Besuch nicht gefunden');
    $b = besuch_zahlen($b);
    $b['positionen'] = all('SELECT x.id, x.art, x.artikel_id, x.menge, x.fach, x.ek, a.bezeichnung AS artikel FROM mv_bewegungen x JOIN mv_artikel a ON a.id = x.artikel_id WHERE x.besuch_id = ? ORDER BY x.fach, a.bezeichnung', [$id]);
    foreach ($b['positionen'] as &$p) { $p['menge'] = (float)$p['menge']; $p['ek'] = (float)$p['ek']; }
    $b['korrekturen'] = array_map('besuch_zahlen', all('SELECT * FROM mv_besuche WHERE korrektur_von = ? ORDER BY created_at', [$id]));
    $l = locked_until();
    $b['gesperrt'] = $l && $b['datum'] <= $l;
    return $b;
}

function besuch_zahlen(array $b): array
{
    foreach (['zaehler_bar', 'zaehler_bargeldlos', 'soll_bar', 'ist_muenzen', 'ist_scheine', 'roehren', 'bargeldlos', 'fehlgeld'] as $k) if ($b[$k] !== null) $b[$k] = (float)$b[$k];
    foreach (['zaehler_verkaeufe', 'verkaeufe'] as $k) if ($b[$k] !== null) $b[$k] = (int)$b[$k];
    $b['gereinigt'] = (bool)$b['gereinigt'];
    $b['umsatz'] = round((float)$b['soll_bar'] + (float)$b['bargeldlos'], 2);
    $b['ist'] = round((float)$b['ist_muenzen'] + (float)$b['ist_scheine'] + (float)$b['roehren'], 2);
    return $b;
}

route('GET', '/besuche', function () {
    need('touren');
    $w = ['1=1'];
    $p = [];
    foreach (['automat' => 'b.automat_id = ?', 'tour' => 'b.tour_id = ?', 'mitarbeiter' => 'b.mitarbeiter_id = ?', 'von' => 'b.datum >= ?', 'bis' => 'b.datum <= ?'] as $k => $c) {
        if (!empty($_GET[$k])) { $w[] = $c; $p[] = $_GET[$k]; }
    }
    if (!empty($_GET['fehlgeld'])) $w[] = 'b.fehlgeld <> 0';
    $rows = all('SELECT b.*, ' . res_label_sql('automaten', 'a') . ' AS automat, CONCAT_WS(\' \', m.vorname, m.nachname) AS mitarbeiter, t.name AS tour
        FROM mv_besuche b JOIN mv_automaten a ON a.id = b.automat_id LEFT JOIN mv_mitarbeiter m ON m.id = b.mitarbeiter_id LEFT JOIN mv_touren t ON t.id = b.tour_id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY b.datum DESC, b.created_at DESC LIMIT 2000', $p);
    return array_map('besuch_zahlen', $rows);
});

route('GET', '/besuche/{id}', function ($a) {
    need('touren');
    return besuch_get($a['id']);
});

// Vorschlag für einen neuen Besuch: letzte Zählerstände, Fächer, letztes Audit
route('GET', '/besuche-vorlage/{automat}', function ($a) {
    need('touren');
    $am = res_get('automaten', $a['automat']);
    $prev = one('SELECT * FROM mv_besuche WHERE automat_id = ? AND korrektur_von IS NULL ORDER BY datum DESC, created_at DESC LIMIT 1', [$a['automat']]);
    $tour = $am['tour_id'] ? one('SELECT * FROM mv_touren WHERE id = ?', [$am['tour_id']]) : null;
    $audit = one('SELECT * FROM mv_audits WHERE automat_id = ? AND besuch_id IS NULL ORDER BY datum DESC LIMIT 1', [$a['automat']]);
    if ($audit) $audit['faecher'] = json_decode((string)$audit['faecher'], true) ?: [];
    unset($audit['rohdaten']);
    return ['automat' => $am, 'vorher' => $prev ? besuch_zahlen($prev) : null, 'faecher' => fach_bestand($a['automat']), 'tour' => $tour, 'audit' => $audit,
        'stoerungen' => all("SELECT id, datum, art, beschreibung, prioritaet FROM mv_stoerungen WHERE automat_id = ? AND status <> 'erledigt' ORDER BY datum", [$a['automat']])];
});

route('POST', '/besuche', function () {
    $u = need('touren', true);
    $in = body();
    $id = (string)($in['id'] ?? '');
    $alt = $id ? one('SELECT * FROM mv_besuche WHERE id = ?', [$id]) : null;
    if ($id && !$alt) throw new HttpError(404, 'Besuch nicht gefunden');
    if ($alt && $alt['korrektur_von']) throw new HttpError(409, 'Korrekturbuchungen können nicht geändert werden');
    if ($alt) check_lock($alt['datum'], 'Der Besuch');
    $datum = valid_date($in['datum'] ?? '');
    check_lock($datum, 'Der Besuch');
    $aid = (string)($in['automatId'] ?? '');
    $am = one('SELECT * FROM mv_automaten WHERE id = ?', [$aid]);
    if (!$am) throw new HttpError(400, 'Bitte einen Automaten wählen');
    $ref = function ($k, $t) use ($in) { $v = ($in[$k] ?? '') ?: null; if ($v && !one("SELECT id FROM $t WHERE id = ?", [$v])) throw new HttpError(400, "$k nicht gefunden"); return $v; };
    $b = [
        'id' => $id ?: mv_uid(), 'datum' => $datum, 'automat_id' => $aid, 'tour_id' => $ref('tourId', 'mv_touren') ?? $am['tour_id'], 'mitarbeiter_id' => $ref('mitarbeiterId', 'mv_mitarbeiter'),
        'fahrzeug_id' => $ref('fahrzeugId', 'mv_lager'), 'zaehler_verkaeufe' => ($in['zaehlerVerkaeufe'] ?? '') === '' ? null : (int)$in['zaehlerVerkaeufe'],
        'zaehler_bar' => num($in['zaehlerBar'] ?? null), 'zaehler_bargeldlos' => num($in['zaehlerBargeldlos'] ?? null), 'verkaeufe' => null, 'soll_bar' => null,
        'ist_muenzen' => num($in['istMuenzen'] ?? null) ?? 0, 'ist_scheine' => num($in['istScheine'] ?? null) ?? 0, 'roehren' => num($in['roehren'] ?? null) ?? 0, 'bargeldlos' => 0,
        'gereinigt' => !empty($in['gereinigt']) ? 1 : 0, 'audit_id' => ($in['auditId'] ?? '') ?: null, 'bemerkung' => mb_substr(trim((string)($in['bemerkung'] ?? '')), 0, 2000),
        'created_at' => $alt['created_at'] ?? mv_now(),
    ];
    $prev = besuch_vorher($aid, $datum, $b['created_at'], $alt['id'] ?? null);
    foreach (['zaehler_bar' => 'Bargeld-Zähler', 'zaehler_bargeldlos' => 'Bargeldlos-Zähler', 'zaehler_verkaeufe' => 'Verkaufszähler'] as $k => $t) {
        if ($b[$k] !== null && $prev && $prev[$k] !== null && $b[$k] < (float)$prev[$k] && empty($in['zaehlerZurueckgesetzt'])) {
            throw new HttpError(400, "$t ist kleiner als beim letzten Besuch (" . $prev[$k] . '). Wurde der Zähler zurückgesetzt? Dann bitte „Zähler zurückgesetzt“ ankreuzen und Soll von Hand eingeben.');
        }
    }
    $resetPrev = !empty($in['zaehlerZurueckgesetzt']) ? null : $prev;
    $b = besuch_rechnen($b, $resetPrev, num($in['sollBar'] ?? null), num($in['bargeldlos'] ?? null));

    // Befüllung aus dem Fahrzeug (oder Lager) in die Fächer, Rücknahmen zurück ins Fahrzeug
    $pos = [];
    foreach ((array)($in['befuellung'] ?? []) as $x) {
        $m = num($x['menge'] ?? null);
        if (!$m) continue;
        if ($m < 0) throw new HttpError(400, 'Befüllmengen bitte positiv eingeben');
        $art = one('SELECT id, ek FROM mv_artikel WHERE id = ?', [(string)($x['artikelId'] ?? '')]);
        if (!$art) throw new HttpError(400, 'Artikel in der Befüllung nicht gefunden');
        $pos[] = ['art' => 'befuellung', 'artikel_id' => $art['id'], 'menge' => $m, 'von' => $b['fahrzeug_id'], 'fach' => (string)($x['fach'] ?? ''), 'ek' => (float)$art['ek']];
    }
    foreach ((array)($in['ruecknahme'] ?? []) as $x) {
        $m = num($x['menge'] ?? null);
        if (!$m) continue;
        $art = one('SELECT id, ek FROM mv_artikel WHERE id = ?', [(string)($x['artikelId'] ?? '')]);
        if (!$art) throw new HttpError(400, 'Artikel in der Rücknahme nicht gefunden');
        $verderb = !empty($x['verderb']);
        $pos[] = ['art' => 'ruecknahme', 'artikel_id' => $art['id'], 'menge' => abs($m), 'nach' => $verderb ? null : $b['fahrzeug_id'], 'fach' => (string)($x['fach'] ?? ''), 'ek' => (float)$art['ek'],
            'text' => $verderb ? 'Verderb / MHD' : 'zurück ins Fahrzeug'];
    }
    if ($b['verkaeufe'] === null && $pos) $b['verkaeufe'] = (int)array_sum(array_map(fn($p) => $p['art'] === 'befuellung' ? $p['menge'] : 0, $pos));

    $GLOBALS['pdo']->beginTransaction();
    if ($alt) {
        q('DELETE FROM mv_bewegungen WHERE besuch_id = ?', [$id]);
        q('UPDATE mv_audits SET besuch_id = NULL WHERE besuch_id = ?', [$id]);
        q('DELETE FROM mv_besuche WHERE id = ?', [$id]);
    }
    $cols = array_keys($b);
    $b['benutzer'] = $u['name'];
    $cols[] = 'benutzer';
    q('INSERT INTO mv_besuche (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($b));
    foreach ($pos as $p) bewegung($p + ['datum' => $datum, 'automat' => $aid, 'besuch' => $b['id']]);
    if ($b['audit_id']) q('UPDATE mv_audits SET besuch_id = ?, automat_id = COALESCE(automat_id, ?) WHERE id = ?', [$b['id'], $aid, $b['audit_id']]);
    // erledigte Störungen beim Besuch abhaken
    foreach ((array)($in['stoerungenErledigt'] ?? []) as $sid) q("UPDATE mv_stoerungen SET status = 'erledigt', erledigt_am = ?, updated_at = ? WHERE id = ? AND automat_id = ?", [$datum, mv_now(), (string)$sid, $aid]);
    if ($alt && ($alt['datum'] !== $datum)) besuch_folge_neu($alt + ['id' => $alt['id']]);
    besuch_folge_neu($b);
    $GLOBALS['pdo']->commit();
    return besuch_get($b['id']);
});

route('DELETE', '/besuche/{id}', function ($a) {
    need('touren', true);
    $b = one('SELECT * FROM mv_besuche WHERE id = ?', [$a['id']]);
    if (!$b) throw new HttpError(404, 'Besuch nicht gefunden');
    check_lock($b['datum'], 'Der Besuch');
    if (one('SELECT id FROM mv_besuche WHERE korrektur_von = ?', [$a['id']])) throw new HttpError(409, 'Zu diesem Besuch gibt es Korrekturbuchungen');
    $GLOBALS['pdo']->beginTransaction();
    q('DELETE FROM mv_bewegungen WHERE besuch_id = ?', [$a['id']]);
    q('UPDATE mv_audits SET besuch_id = NULL WHERE besuch_id = ?', [$a['id']]);
    q('DELETE FROM mv_besuche WHERE id = ?', [$a['id']]);
    besuch_folge_neu($b);
    $GLOBALS['pdo']->commit();
    return ['ok' => true];
});

// Korrekturbuchung zu einem (meist schon abgeschlossenen) Besuch: Beträge als Differenz, Datum im offenen Zeitraum
route('POST', '/besuche/{id}/korrektur', function ($a) {
    $u = need('einnahmen', true);
    $o = one('SELECT * FROM mv_besuche WHERE id = ?', [$a['id']]);
    if (!$o) throw new HttpError(404, 'Besuch nicht gefunden');
    $in = body();
    $datum = valid_date($in['datum'] ?? date('Y-m-d'));
    check_lock($datum, 'Die Korrektur');
    $grund = trim((string)($in['grund'] ?? ''));
    if ($grund === '') throw new HttpError(400, 'Bitte einen Grund für die Korrektur angeben');
    $k = ['soll_bar' => num($in['sollBar'] ?? null) ?? 0, 'ist_muenzen' => num($in['ist'] ?? null) ?? 0, 'bargeldlos' => num($in['bargeldlos'] ?? null) ?? 0];
    if (!array_filter($k)) throw new HttpError(400, 'Bitte mindestens einen Betrag eingeben');
    $id = mv_uid();
    q('INSERT INTO mv_besuche (id, datum, automat_id, tour_id, mitarbeiter_id, soll_bar, ist_muenzen, bargeldlos, fehlgeld, korrektur_von, bemerkung, benutzer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $datum, $o['automat_id'], $o['tour_id'], $o['mitarbeiter_id'], $k['soll_bar'], $k['ist_muenzen'], $k['bargeldlos'], round($k['ist_muenzen'] - $k['soll_bar'], 2), $o['id'],
            'Korrektur zum Besuch vom ' . date('d.m.Y', strtotime($o['datum'])) . ': ' . mb_substr($grund, 0, 500), $u['name'], mv_now()]);
    return besuch_get($o['id']);
});

// Tourenplan: Automaten einer Tour mit letztem Besuch, offenen Störungen und Tagen seit dem Besuch
route('GET', '/touren/{id}/plan', function ($a) {
    need('touren');
    $t = res_get('touren', $a['id']);
    $rows = all('SELECT a.id, a.nummer, a.bezeichnung, a.art, s.name AS standort, s.zugang, s.gebaeude,
            (SELECT MAX(datum) FROM mv_besuche b WHERE b.automat_id = a.id AND b.korrektur_von IS NULL) AS letzter_besuch,
            (SELECT COUNT(*) FROM mv_stoerungen x WHERE x.automat_id = a.id AND x.status <> \'erledigt\') AS stoerungen
        FROM mv_automaten a LEFT JOIN mv_standorte s ON s.id = a.standort_id WHERE a.tour_id = ? AND a.status = \'aktiv\' ORDER BY s.name, a.nummer', [$a['id']]);
    foreach ($rows as &$r) {
        $r['tageSeit'] = $r['letzter_besuch'] ? (int)((strtotime(date('Y-m-d')) - strtotime($r['letzter_besuch'])) / 86400) : null;
        $r['stoerungen'] = (int)$r['stoerungen'];
        // Durchschnittlicher Tagesabsatz der letzten 8 Wochen, daraus der Füllbedarf seit dem letzten Besuch
        $v = one('SELECT SUM(verkaeufe) AS v, MIN(datum) AS von FROM mv_besuche WHERE automat_id = ? AND datum >= ? AND korrektur_von IS NULL', [$r['id'], date('Y-m-d', strtotime('-56 days'))]);
        $tage = $v && $v['von'] ? max(7, (strtotime(date('Y-m-d')) - strtotime($v['von'])) / 86400) : 0;
        $r['proTag'] = $tage ? round((float)$v['v'] / $tage, 1) : null;
        $r['bedarfGeschaetzt'] = $r['proTag'] !== null && $r['tageSeit'] !== null ? (int)round($r['proTag'] * $r['tageSeit']) : null;
    }
    // Ladeliste: was im Fahrzeug liegt
    $ladung = $t['fahrzeug_id'] ? bestand($t['fahrzeug_id']) : [];
    return ['tour' => $t, 'automaten' => $rows, 'fahrzeugBestand' => $ladung];
});
