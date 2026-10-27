<?php
// Artikel und Lager: Wareneingang, Umlagerung ins Fahrzeug, Schwund, Inventur, Bestände („vom Lager bis in den Becher“)
declare(strict_types=1);

const MV_BEWEGUNGSARTEN = [
    'wareneingang' => 'Wareneingang', 'umlagerung' => 'Umlagerung', 'befuellung' => 'Befüllung Automat', 'ruecknahme' => 'Rücknahme aus Automat',
    'schwund' => 'Schwund / Verderb', 'inventur' => 'Inventurdifferenz', 'entnahme' => 'Entnahme (Eigenverbrauch, Muster)',
];

function bewegung(array $x): string
{
    $id = mv_uid();
    q('INSERT INTO mv_bewegungen (id, datum, art, artikel_id, menge, von_lager_id, nach_lager_id, automat_id, besuch_id, fach, ek, beleg, text, mhd, benutzer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $id, $x['datum'], $x['art'], $x['artikel_id'], $x['menge'], $x['von'] ?? null, $x['nach'] ?? null, $x['automat'] ?? null, $x['besuch'] ?? null, (string)($x['fach'] ?? ''),
        $x['ek'] ?? (float)val('SELECT ek FROM mv_artikel WHERE id = ?', [$x['artikel_id']]), mb_substr((string)($x['beleg'] ?? ''), 0, 60), mb_substr((string)($x['text'] ?? ''), 0, 255),
        $x['mhd'] ?? null, $GLOBALS['USER']['name'] ?? 'System', mv_now(),
    ]);
    return $id;
}

// Bestand je Lager und Artikel
function bestand(?string $lager = null, ?string $artikel = null): array
{
    $p = [];
    $w = '';
    if ($lager) { $w .= ' AND x.lager_id = ?'; $p[] = $lager; }
    if ($artikel) { $w .= ' AND x.artikel_id = ?'; $p[] = $artikel; }
    $rows = all("SELECT x.lager_id, x.artikel_id, SUM(x.menge) AS menge, l.name AS lager, l.art AS lagerart, a.artikelnr, a.bezeichnung, a.ek, a.einheit
        FROM (SELECT nach_lager_id AS lager_id, artikel_id, menge FROM mv_bewegungen WHERE nach_lager_id IS NOT NULL
              UNION ALL SELECT von_lager_id, artikel_id, -menge FROM mv_bewegungen WHERE von_lager_id IS NOT NULL) x
        JOIN mv_lager l ON l.id = x.lager_id JOIN mv_artikel a ON a.id = x.artikel_id
        WHERE 1=1 $w GROUP BY x.lager_id, x.artikel_id, l.name, l.art, a.artikelnr, a.bezeichnung, a.ek, a.einheit HAVING ABS(SUM(x.menge)) > 0.0001 ORDER BY l.name, a.bezeichnung", $p);
    foreach ($rows as &$r) { $r['menge'] = (float)$r['menge']; $r['ek'] = (float)$r['ek']; $r['wert'] = round($r['menge'] * $r['ek'], 2); }
    return $rows;
}

function bestand_eins(string $lager, string $artikel): float
{
    return (float)val('SELECT COALESCE(SUM(CASE WHEN nach_lager_id = ? THEN menge ELSE 0 END) - SUM(CASE WHEN von_lager_id = ? THEN menge ELSE 0 END), 0) FROM mv_bewegungen WHERE artikel_id = ? AND (nach_lager_id = ? OR von_lager_id = ?)',
        [$lager, $lager, $artikel, $lager, $lager]);
}

// Fächer eines Automaten mit letzter Befüllung
function fach_bestand(string $aid): array
{
    $rows = res_list('faecher', ['automat_id' => $aid]);
    foreach ($rows as &$f) {
        $l = one("SELECT datum, menge FROM mv_bewegungen WHERE automat_id = ? AND fach = ? AND art = 'befuellung' ORDER BY datum DESC, created_at DESC LIMIT 1", [$aid, $f['fach']]);
        $f['letzteBefuellung'] = $l ? $l['datum'] : null;
        $f['letzteMenge'] = $l ? (float)$l['menge'] : null;
    }
    usort($rows, fn($a, $b) => strnatcmp((string)$a['fach'], (string)$b['fach']));
    return $rows;
}

route('GET', '/bestand', function () {
    need('waren');
    return bestand($_GET['lager'] ?? null, $_GET['artikel'] ?? null);
});

route('GET', '/bewegungen', function () {
    need('waren');
    $w = ['1=1'];
    $p = [];
    foreach (['artikel' => 'b.artikel_id = ?', 'automat' => 'b.automat_id = ?', 'art' => 'b.art = ?', 'von' => 'b.datum >= ?', 'bis' => 'b.datum <= ?'] as $k => $c) {
        if (!empty($_GET[$k])) { $w[] = $c; $p[] = $_GET[$k]; }
    }
    if (!empty($_GET['lager'])) { $w[] = '(b.von_lager_id = ? OR b.nach_lager_id = ?)'; $p[] = $_GET['lager']; $p[] = $_GET['lager']; }
    $rows = all('SELECT b.*, a.bezeichnung AS artikel, a.artikelnr, lv.name AS von_lager, ln.name AS nach_lager, ' . res_label_sql('automaten', 'am') . ' AS automat
        FROM mv_bewegungen b JOIN mv_artikel a ON a.id = b.artikel_id LEFT JOIN mv_lager lv ON lv.id = b.von_lager_id LEFT JOIN mv_lager ln ON ln.id = b.nach_lager_id
        LEFT JOIN mv_automaten am ON am.id = b.automat_id WHERE ' . implode(' AND ', $w) . ' ORDER BY b.datum DESC, b.created_at DESC LIMIT 1000', $p);
    foreach ($rows as &$r) { $r['menge'] = (float)$r['menge']; $r['ek'] = (float)$r['ek']; $r['artText'] = MV_BEWEGUNGSARTEN[$r['art']] ?? $r['art']; }
    return $rows;
});

// Buchung mit mehreren Positionen: Wareneingang, Umlagerung, Schwund, Entnahme
route('POST', '/bewegungen', function () {
    need('waren', true);
    $b = body();
    $art = (string)($b['art'] ?? '');
    if (!in_array($art, ['wareneingang', 'umlagerung', 'schwund', 'entnahme'], true)) throw new HttpError(400, 'Ungültige Buchungsart');
    $datum = valid_date($b['datum'] ?? '');
    check_lock($datum);
    $von = ($b['vonLager'] ?? '') ?: null;
    $nach = ($b['nachLager'] ?? '') ?: null;
    if ($art === 'wareneingang') $von = null;
    if ($art === 'schwund' || $art === 'entnahme') $nach = null;
    if (in_array($art, ['umlagerung', 'schwund', 'entnahme'], true) && !$von) throw new HttpError(400, 'Bitte das Lager angeben, aus dem die Ware kommt');
    if (in_array($art, ['wareneingang', 'umlagerung'], true) && !$nach) throw new HttpError(400, 'Bitte das Ziellager angeben');
    if ($von && $von === $nach) throw new HttpError(400, 'Quelle und Ziel sind gleich');
    foreach ([$von, $nach] as $l) if ($l && !one('SELECT id FROM mv_lager WHERE id = ?', [$l])) throw new HttpError(400, 'Lager nicht gefunden');
    $pos = array_filter((array)($b['positionen'] ?? []), fn($x) => is_array($x) && (num($x['menge'] ?? null) ?? 0) != 0);
    if (!$pos) throw new HttpError(400, 'Bitte mindestens eine Position mit Menge eingeben');
    $GLOBALS['pdo']->beginTransaction();
    $n = 0;
    foreach ($pos as $x) {
        $art_id = (string)($x['artikelId'] ?? '');
        $a = one('SELECT id, ek FROM mv_artikel WHERE id = ?', [$art_id]);
        if (!$a) throw new HttpError(400, 'Artikel nicht gefunden');
        $menge = num($x['menge']);
        if ($menge <= 0) throw new HttpError(400, 'Mengen bitte positiv eingeben');
        $ek = num($x['ek'] ?? null) ?? (float)$a['ek'];
        if ($art === 'wareneingang' && num($x['ek'] ?? null) !== null && !empty($b['ekUebernehmen'])) q('UPDATE mv_artikel SET ek = ?, updated_at = ? WHERE id = ?', [$ek, mv_now(), $a['id']]);
        bewegung(['datum' => $datum, 'art' => $art, 'artikel_id' => $a['id'], 'menge' => $menge, 'von' => $von, 'nach' => $nach, 'ek' => $ek, 'beleg' => $b['beleg'] ?? '', 'text' => $b['text'] ?? '', 'mhd' => ($x['mhd'] ?? '') ?: null]);
        $n++;
    }
    $GLOBALS['pdo']->commit();
    return ['ok' => true, 'positionen' => $n];
});

// Inventur: gezählte Mengen eines Lagers, die Differenz wird gebucht
route('POST', '/inventur', function () {
    need('waren', true);
    $b = body();
    $lager = (string)($b['lager'] ?? '');
    if (!one('SELECT id FROM mv_lager WHERE id = ?', [$lager])) throw new HttpError(400, 'Lager nicht gefunden');
    $datum = valid_date($b['datum'] ?? '');
    check_lock($datum);
    $GLOBALS['pdo']->beginTransaction();
    $diffs = [];
    foreach ((array)($b['zaehlung'] ?? []) as $z) {
        $ist = num($z['menge'] ?? null);
        if ($ist === null) continue;
        $aid = (string)($z['artikelId'] ?? '');
        if (!one('SELECT id FROM mv_artikel WHERE id = ?', [$aid])) continue;
        $soll = bestand_eins($lager, $aid);
        $d = round($ist - $soll, 3);
        if (abs($d) < 0.0005) continue;
        bewegung(['datum' => $datum, 'art' => 'inventur', 'artikel_id' => $aid, 'menge' => abs($d), 'von' => $d < 0 ? $lager : null, 'nach' => $d > 0 ? $lager : null, 'text' => "Inventur: Soll $soll, Ist $ist"]);
        $diffs[] = ['artikelId' => $aid, 'soll' => $soll, 'ist' => $ist, 'differenz' => $d];
    }
    $GLOBALS['pdo']->commit();
    return ['ok' => true, 'differenzen' => $diffs];
});

route('DELETE', '/bewegungen/{id}', function ($a) {
    need('waren', true);
    $x = one('SELECT * FROM mv_bewegungen WHERE id = ?', [$a['id']]);
    if (!$x) throw new HttpError(404, 'Buchung nicht gefunden');
    if ($x['besuch_id']) throw new HttpError(409, 'Die Buchung gehört zu einem Besuch und wird dort geändert');
    check_lock($x['datum']);
    q('DELETE FROM mv_bewegungen WHERE id = ?', [$a['id']]);
    return ['ok' => true];
});

// Einkaufsvorschlag: Artikel, deren Lagerbestand unter dem Verbrauch der letzten 4 Wochen liegt
route('GET', '/bestellvorschlag', function () {
    need('waren');
    $verbrauch = [];
    foreach (all("SELECT artikel_id, SUM(menge) AS m FROM mv_bewegungen WHERE art = 'befuellung' AND datum >= ? GROUP BY artikel_id", [date('Y-m-d', strtotime('-28 days'))]) as $r) $verbrauch[$r['artikel_id']] = (float)$r['m'];
    $bestand = [];
    foreach (bestand() as $r) $bestand[$r['artikel_id']] = ($bestand[$r['artikel_id']] ?? 0) + $r['menge'];
    $out = [];
    foreach (all('SELECT a.id, a.artikelnr, a.bezeichnung, a.ek, a.einheit, l.name AS lieferant FROM mv_artikel a LEFT JOIN mv_lieferanten l ON l.id = a.lieferant_id WHERE a.aktiv = 1 ORDER BY l.name, a.bezeichnung') as $a) {
        $v = $verbrauch[$a['id']] ?? 0;
        $b = $bestand[$a['id']] ?? 0;
        if ($v <= 0 || $b >= $v) continue;
        $out[] = $a + ['verbrauch4w' => $v, 'bestand' => $b, 'vorschlag' => ceil($v * 1.5 - $b), 'ek' => (float)$a['ek']];
    }
    return $out;
});
