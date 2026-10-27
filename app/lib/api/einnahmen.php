<?php
// Einnahmen: Soll/Ist je Automat, Fehlgeld, Planumsatz-Vergleich, Kassen- und Monatsabschluss (sperrt den Zeitraum)
declare(strict_types=1);

function einnahmen_summen(string $von, string $bis, string $gruppe = 'automat'): array
{
    $g = match ($gruppe) {
        'tag' => ['b.datum', 'b.datum'],
        'monat' => ["DATE_FORMAT(b.datum, '%Y-%m')", "DATE_FORMAT(b.datum, '%Y-%m')"],
        'mitarbeiter' => ['b.mitarbeiter_id', "COALESCE(CONCAT_WS(' ', m.vorname, m.nachname), '(ohne)')"],
        'tour' => ['b.tour_id', "COALESCE(t.name, '(ohne Tour)')"],
        'kunde' => ['k.id', "COALESCE(k.firma, '(ohne Kunde)')"],
        default => ['b.automat_id', res_label_sql('automaten', 'a')],
    };
    $rows = all("SELECT {$g[0]} AS schluessel, MAX({$g[1]}) AS name, COUNT(*) AS besuche, SUM(b.verkaeufe) AS verkaeufe, SUM(b.soll_bar) AS soll_bar,
            SUM(b.ist_muenzen + b.ist_scheine + b.roehren) AS ist_bar, SUM(b.bargeldlos) AS bargeldlos, SUM(b.fehlgeld) AS fehlgeld, SUM(b.soll_bar + b.bargeldlos) AS umsatz
        FROM mv_besuche b JOIN mv_automaten a ON a.id = b.automat_id LEFT JOIN mv_mitarbeiter m ON m.id = b.mitarbeiter_id LEFT JOIN mv_touren t ON t.id = b.tour_id
        LEFT JOIN mv_standorte s ON s.id = a.standort_id LEFT JOIN mv_kunden k ON k.id = s.kunde_id
        WHERE b.datum BETWEEN ? AND ? GROUP BY {$g[0]} ORDER BY name", [$von, $bis]);
    foreach ($rows as &$r) {
        foreach (['soll_bar', 'ist_bar', 'bargeldlos', 'fehlgeld', 'umsatz'] as $k) $r[$k] = round((float)$r[$k], 2);
        $r['besuche'] = (int)$r['besuche']; $r['verkaeufe'] = (int)$r['verkaeufe'];
    }
    return $rows;
}

function summe(array $rows): array
{
    $s = ['besuche' => 0, 'verkaeufe' => 0, 'soll_bar' => 0.0, 'ist_bar' => 0.0, 'bargeldlos' => 0.0, 'fehlgeld' => 0.0, 'umsatz' => 0.0];
    foreach ($rows as $r) foreach ($s as $k => $_) $s[$k] += $r[$k];
    foreach (['soll_bar', 'ist_bar', 'bargeldlos', 'fehlgeld', 'umsatz'] as $k) $s[$k] = round($s[$k], 2);
    return $s;
}

route('GET', '/einnahmen', function () {
    need('einnahmen');
    $von = valid_date($_GET['von'] ?? date('Y-m-01'), 'Von');
    $bis = valid_date($_GET['bis'] ?? date('Y-m-d'), 'Bis');
    $rows = einnahmen_summen($von, $bis, (string)($_GET['gruppe'] ?? 'automat'));
    return ['zeilen' => $rows, 'summe' => summe($rows), 'gesperrtBis' => locked_until()];
});

// Planumsatz gegen tatsächlichen Umsatz je Automat und Monat eines Jahres
route('GET', '/sollist', function () {
    need('einnahmen');
    $jahr = (int)($_GET['jahr'] ?? date('Y'));
    $ist = [];
    foreach (all("SELECT automat_id, MONTH(datum) AS m, SUM(soll_bar + bargeldlos) AS u FROM mv_besuche WHERE YEAR(datum) = ? GROUP BY automat_id, m", [$jahr]) as $r) $ist[$r['automat_id']][(int)$r['m']] = round((float)$r['u'], 2);
    $out = [];
    foreach (all("SELECT id, nummer, bezeichnung, planumsatz, aufgestellt_am, status FROM mv_automaten ORDER BY nummer") as $a) {
        if (!isset($ist[$a['id']]) && $a['status'] !== 'aktiv') continue;
        $monate = [];
        for ($m = 1; $m <= 12; $m++) $monate[$m] = $ist[$a['id']][$m] ?? 0.0;
        $out[] = ['id' => $a['id'], 'nummer' => $a['nummer'], 'bezeichnung' => $a['bezeichnung'], 'plan' => (float)$a['planumsatz'], 'monate' => $monate, 'summe' => round(array_sum($monate), 2)];
    }
    return ['jahr' => $jahr, 'automaten' => $out];
});

route('GET', '/abschluesse', function () {
    need('einnahmen');
    $rows = all('SELECT * FROM mv_abschluesse ORDER BY bis DESC');
    foreach ($rows as &$r) $r['summen'] = json_decode($r['summen'], true);
    return ['abschluesse' => $rows, 'gesperrtBis' => locked_until(), 'naechsterVon' => abschluss_von()];
});

function abschluss_von(): string
{
    $l = locked_until();
    if ($l) return date('Y-m-d', strtotime($l . ' +1 day'));
    return (string)(val('SELECT MIN(datum) FROM mv_besuche') ?? date('Y-m-d'));
}

route('POST', '/abschluesse', function () {
    $u = need('einnahmen', true);
    $b = body();
    $art = (string)($b['art'] ?? 'kasse');
    if (!in_array($art, ['kasse', 'monat'], true)) throw new HttpError(400, 'Ungültige Art');
    $bis = valid_date($b['bis'] ?? '', 'Abschluss bis');
    if ($bis > date('Y-m-d')) throw new HttpError(400, 'Ein Abschluss in der Zukunft ist nicht möglich');
    if ($art === 'monat' && $bis !== date('Y-m-t', strtotime($bis))) throw new HttpError(400, 'Ein Monatsabschluss endet am letzten Tag des Monats');
    $von = abschluss_von();
    if ($bis < $von) throw new HttpError(400, 'Bis zu diesem Datum ist schon abgeschlossen');
    $GLOBALS['pdo']->beginTransaction();
    $GLOBALS['pdo']->query("SELECT GET_LOCK('mv_abschluss', 10)");
    $zeilen = einnahmen_summen($von, $bis, 'automat');
    $s = summe($zeilen);
    $s['wareneinsatz'] = round((float)val("SELECT COALESCE(SUM(CASE WHEN art = 'befuellung' THEN menge * ek WHEN art = 'ruecknahme' AND nach_lager_id IS NOT NULL THEN -menge * ek ELSE 0 END), 0) FROM mv_bewegungen WHERE datum BETWEEN ? AND ?", [$von, $bis]), 2);
    $s['automaten'] = $zeilen;
    $id = mv_uid();
    q('INSERT INTO mv_abschluesse (id, nummer, art, von, bis, summen, benutzer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, mv_next_number('abschluss', 1), $art, $von, $bis, json_encode($s, JSON_UNESCAPED_UNICODE), $u['name'], mv_now()]);
    $GLOBALS['pdo']->query("SELECT RELEASE_LOCK('mv_abschluss')");
    $GLOBALS['pdo']->commit();
    $r = one('SELECT * FROM mv_abschluesse WHERE id = ?', [$id]);
    $r['summen'] = json_decode($r['summen'], true);
    return $r;
});
