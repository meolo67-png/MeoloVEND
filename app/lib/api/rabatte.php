<?php
// Rabatte und Provisionen: Berechnung je Zeitraum, Rabattabgleich mit Endkontrolle und Freigabe; Vertragsraten
declare(strict_types=1);

// „0:5; 400:10“ → [[0, 5], [400, 10]] (ab Monatsumsatz : Prozent)
function rabatt_staffel(string $s): array
{
    $out = [];
    foreach (preg_split('/[;\n]+/', $s) as $teil) {
        $teil = trim($teil);
        if ($teil === '') continue;
        if (!preg_match('/^(\d+(?:[.,]\d+)?)\s*[:=]\s*(\d+(?:[.,]\d+)?)\s*%?$/', $teil, $m)) throw new HttpError(400, "Staffel „{$teil}“ nicht verstanden. Format: ab-Umsatz:Prozent, getrennt mit Semikolon, z. B. 0:5; 400:10");
        $out[] = [(float)str_replace(',', '.', $m[1]), (float)str_replace(',', '.', $m[2])];
    }
    if (!$out) throw new HttpError(400, 'Bitte eine Staffel eingeben, z. B. 0:5; 400:10');
    usort($out, fn($a, $b) => $a[0] <=> $b[0]);
    return $out;
}

const MV_INTERVALL_MONATE = ['monat' => 1, 'quartal' => 3, 'halbjahr' => 6, 'jahr' => 12];

// Abrechnungszeiträume eines Intervalls, die ganz in [von, bis] liegen
function perioden(string $intervall, string $von, string $bis): array
{
    $n = MV_INTERVALL_MONATE[$intervall] ?? 1;
    $out = [];
    $y = (int)substr($von, 0, 4);
    $m = (int)substr($von, 5, 2);
    $m = $m - (($m - 1) % $n); // auf Periodenanfang (Jan, Apr, Jul, Okt …) zurück
    while (true) {
        $start = sprintf('%04d-%02d-01', $y, $m);
        $ende = date('Y-m-t', strtotime($start . ' +' . ($n - 1) . ' months'));
        if ($start > $bis) break;
        if ($start >= $von && $ende <= $bis) $out[] = [$start, $ende];
        $m += $n;
        if ($m > 12) { $m -= 12; $y++; }
    }
    return $out;
}

// Umsatz je Monat für die Automaten eines Rabatts (brutto oder netto)
function rabatt_umsatz_monat(array $r, string $monatStart): float
{
    $ende = date('Y-m-t', strtotime($monatStart));
    $p = [$monatStart, $ende];
    if ($r['automat_id']) { $w = 'a.id = ?'; $p[] = $r['automat_id']; }
    else { $w = 's.kunde_id = ?'; $p[] = $r['kunde_id']; }
    $expr = $r['basis'] === 'netto' ? '(b.soll_bar + b.bargeldlos) / (1 + COALESCE(a.mwst, 19) / 100)' : '(b.soll_bar + b.bargeldlos)';
    return round((float)val("SELECT COALESCE(SUM($expr), 0) FROM mv_besuche b JOIN mv_automaten a ON a.id = b.automat_id LEFT JOIN mv_standorte s ON s.id = a.standort_id
        WHERE b.datum BETWEEN ? AND ? AND $w", $p), 2);
}

function rabatt_berechnen(array $r, string $von, string $bis): array
{
    $details = [];
    $umsatz = 0.0;
    $betrag = 0.0;
    $staffel = $r['art'] === 'staffel' ? rabatt_staffel((string)$r['staffel']) : [];
    for ($mon = $von; $mon <= $bis; $mon = date('Y-m-01', strtotime($mon . ' +1 month'))) {
        $mEnde = date('Y-m-t', strtotime($mon));
        if ($r['gueltig_ab'] && $mEnde < $r['gueltig_ab']) continue;
        if ($r['gueltig_bis'] && $mon > $r['gueltig_bis']) continue;
        $u = rabatt_umsatz_monat($r, $mon);
        $umsatz += $u;
        $wert = (float)$r['wert'];
        $b = match ($r['art']) {
            'provision' => $u * $wert / 100,
            'staffel' => (function () use ($u, $staffel) { $p = 0; foreach ($staffel as [$ab, $proz]) if ($u >= $ab) $p = $proz; return $u * $p / 100; })(),
            'standplatz' => $wert,
            'zuschuss' => -$wert / (MV_INTERVALL_MONATE[$r['intervall']] ?? 1),
            'pauschale' => $wert / (MV_INTERVALL_MONATE[$r['intervall']] ?? 1),
            'einmalig' => ($r['gueltig_ab'] && substr($r['gueltig_ab'], 0, 7) === substr($mon, 0, 7)) ? $wert : 0,
            default => 0,
        };
        $details[] = ['monat' => substr($mon, 0, 7), 'umsatz' => $u, 'betrag' => round($b, 2)];
        $betrag += $b;
    }
    return ['umsatz' => round($umsatz, 2), 'betrag' => round($betrag, 2), 'details' => $details];
}

route('POST', '/rabatte/berechnen', function () {
    need('rabatte', true);
    $b = body();
    $von = valid_date($b['von'] ?? '', 'Von');
    $bis = valid_date($b['bis'] ?? '', 'Bis');
    if ($bis < $von) throw new HttpError(400, 'Bis liegt vor Von');
    $n = 0;
    $GLOBALS['pdo']->beginTransaction();
    foreach (all('SELECT * FROM mv_rabatte') as $r) {
        foreach (perioden($r['intervall'] ?: 'monat', $von, $bis) as [$pv, $pb]) {
            if ($r['gueltig_ab'] && $pb < $r['gueltig_ab']) continue;
            if ($r['gueltig_bis'] && $pv > $r['gueltig_bis']) continue;
            $alt = one('SELECT id, status FROM mv_rabattabrechnungen WHERE rabatt_id = ? AND von = ? AND bis = ?', [$r['id'], $pv, $pb]);
            if ($alt && in_array($alt['status'], ['freigegeben', 'abgerechnet'], true)) continue; // freigegebene bleiben
            $x = rabatt_berechnen($r, $pv, $pb);
            if ($alt) q("UPDATE mv_rabattabrechnungen SET kunde_id = ?, umsatz = ?, betrag = ?, details = ?, status = 'berechnet', geprueft_von = NULL, geprueft_am = NULL WHERE id = ?",
                [$r['kunde_id'], $x['umsatz'], $x['betrag'], json_encode($x['details']), $alt['id']]);
            else q('INSERT INTO mv_rabattabrechnungen (id, rabatt_id, kunde_id, von, bis, umsatz, betrag, details, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [mv_uid(), $r['id'], $r['kunde_id'], $pv, $pb, $x['umsatz'], $x['betrag'], json_encode($x['details']), 'berechnet', mv_now()]);
            $n++;
        }
    }
    $GLOBALS['pdo']->commit();
    return ['ok' => true, 'berechnet' => $n];
});

route('GET', '/rabattabgleich', function () {
    need('rabatte');
    $p = [];
    $w = ['1=1'];
    if (!empty($_GET['von'])) { $w[] = 'x.bis >= ?'; $p[] = $_GET['von']; }
    if (!empty($_GET['bis'])) { $w[] = 'x.von <= ?'; $p[] = $_GET['bis']; }
    if (!empty($_GET['status'])) { $w[] = 'x.status = ?'; $p[] = $_GET['status']; }
    $rows = all('SELECT x.*, r.bezeichnung, r.art, r.wert, r.staffel, r.intervall, k.firma, k.kundennr, k.iban, ' . res_label_sql('automaten', 'a') . ' AS automat
        FROM mv_rabattabrechnungen x JOIN mv_rabatte r ON r.id = x.rabatt_id JOIN mv_kunden k ON k.id = x.kunde_id LEFT JOIN mv_automaten a ON a.id = r.automat_id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY k.firma, x.von', $p);
    foreach ($rows as &$r) { $r['umsatz'] = (float)$r['umsatz']; $r['betrag'] = (float)$r['betrag']; $r['details'] = json_decode((string)$r['details'], true); }
    return $rows;
});

// Endkontrolle: berechnet → geprüft → freigegeben → abgerechnet
route('POST', '/rabattabrechnungen/{id}/status', function ($a) {
    $u = need('rabatte', true);
    $x = one('SELECT * FROM mv_rabattabrechnungen WHERE id = ?', [$a['id']]);
    if (!$x) throw new HttpError(404, 'Abrechnung nicht gefunden');
    $neu = (string)(body()['status'] ?? '');
    $erlaubt = ['berechnet' => ['geprueft'], 'geprueft' => ['freigegeben', 'berechnet'], 'freigegeben' => ['abgerechnet', 'geprueft'], 'abgerechnet' => []];
    if (!in_array($neu, $erlaubt[$x['status']] ?? [], true)) throw new HttpError(409, "Von „{$x['status']}“ nach „{$neu}“ geht nicht");
    if ($neu === 'freigegeben' && $x['geprueft_von'] === $u['name'] && !$u['admin']) throw new HttpError(409, 'Freigabe bitte durch eine zweite Person (Vier-Augen-Prinzip)');
    if ($neu === 'geprueft') q('UPDATE mv_rabattabrechnungen SET status = ?, geprueft_von = ?, geprueft_am = ? WHERE id = ?', [$neu, $u['name'], mv_now(), $a['id']]);
    else q('UPDATE mv_rabattabrechnungen SET status = ? WHERE id = ?', [$neu, $a['id']]);
    return ['ok' => true];
});

// Fällige Vertragsraten in einem Zeitraum (Einnahmen und Ausgaben)
function vertragsraten(string $von, string $bis, ?string $automat = null): array
{
    $out = [];
    $p = [$bis, $von];
    $w = '';
    if ($automat) { $w = ' AND v.automat_id = ?'; $p[] = $automat; }
    foreach (all("SELECT v.*, k.firma, l.name AS lieferant FROM mv_vertraege v LEFT JOIN mv_kunden k ON k.id = v.kunde_id LEFT JOIN mv_lieferanten l ON l.id = v.lieferant_id
        WHERE v.rate > 0 AND v.beginn <= ? AND (v.ende IS NULL OR v.ende >= ?) $w", $p) as $v) {
        $n = MV_INTERVALL_MONATE[$v['intervall']] ?? 1;
        $richtung = mv_vertrag_richtung($v['art']);
        for ($i = 0, $d = $v['beginn']; $d <= $bis; $i++, $d = date('Y-m-d', strtotime($v['beginn'] . ' +' . ($i * $n) . ' months'))) {
            if ($v['anzahl_raten'] && $i >= (int)$v['anzahl_raten']) break;
            if ($v['ende'] && $d > $v['ende']) break;
            if ($d >= $von) $out[] = ['vertragId' => $v['id'], 'vertragsnr' => $v['vertragsnr'], 'art' => $v['art'], 'automatId' => $v['automat_id'], 'partner' => $v['firma'] ?: $v['lieferant'],
                'datum' => $d, 'rate' => (float)$v['rate'], 'betrag' => round($richtung * (float)$v['rate'], 2), 'nr' => $i + 1];
        }
    }
    usort($out, fn($a, $b) => strcmp($a['datum'], $b['datum']));
    return $out;
}

route('GET', '/vertragsraten', function () {
    need('rabatte');
    return vertragsraten(valid_date($_GET['von'] ?? date('Y-m-01')), valid_date($_GET['bis'] ?? date('Y-m-t')), $_GET['automat'] ?? null);
});

route('GET', '/vertraege-auslaufend', function () {
    need('rabatte');
    return all('SELECT v.*, k.firma FROM mv_vertraege v LEFT JOIN mv_kunden k ON k.id = v.kunde_id WHERE v.ende IS NOT NULL AND v.ende BETWEEN ? AND ? ORDER BY v.ende', [date('Y-m-d'), date('Y-m-d', strtotime('+120 days'))]);
});
