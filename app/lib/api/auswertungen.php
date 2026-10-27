<?php
// Auswertungen: Startseite, Erfolgsrechnung je Automat und Monat (Grundlage für die frei einstellbare Pivot-Ansicht), DATEV-Export
declare(strict_types=1);

route('GET', '/dashboard', function () {
    user();
    $out = ['heute' => date('Y-m-d')];
    if (can('einnahmen')) {
        $m0 = date('Y-m-01');
        $vm0 = date('Y-m-01', strtotime('first day of last month'));
        $vmEnde = date('Y-m-d', min(strtotime('last day of last month'), strtotime('-1 month')));
        $out['monat'] = summe(einnahmen_summen($m0, date('Y-m-d')));
        $out['vormonatBisHeute'] = summe(einnahmen_summen($vm0, $vmEnde));
        $out['verlauf'] = all("SELECT DATE_FORMAT(datum, '%Y-%m') AS monat, ROUND(SUM(soll_bar + bargeldlos), 2) AS umsatz, ROUND(SUM(fehlgeld), 2) AS fehlgeld FROM mv_besuche WHERE datum >= ? GROUP BY monat ORDER BY monat",
            [date('Y-m-01', strtotime('-11 months'))]);
        $out['gesperrtBis'] = locked_until();
        // Automaten mit der größten Abweichung vom Planumsatz (hochgerechnet auf den Monat)
        $tag = (int)date('j');
        $tageMonat = (int)date('t');
        $plan = [];
        foreach (all("SELECT a.id, a.nummer, a.bezeichnung, a.planumsatz, COALESCE(SUM(b.soll_bar + b.bargeldlos), 0) AS ist FROM mv_automaten a LEFT JOIN mv_besuche b ON b.automat_id = a.id AND b.datum >= ?
            WHERE a.status = 'aktiv' AND a.planumsatz > 0 GROUP BY a.id, a.nummer, a.bezeichnung, a.planumsatz", [$m0]) as $r) {
            $soll = (float)$r['planumsatz'] * $tag / $tageMonat;
            $plan[] = ['id' => $r['id'], 'name' => trim($r['nummer'] . ' ' . $r['bezeichnung']), 'plan' => (float)$r['planumsatz'], 'ist' => round((float)$r['ist'], 2), 'quote' => $soll > 0 ? round((float)$r['ist'] / $soll * 100) : null];
        }
        usort($plan, fn($a, $b) => ($a['quote'] ?? 0) <=> ($b['quote'] ?? 0));
        $out['planabweichung'] = array_slice($plan, 0, 8);
    }
    if (can('automaten')) {
        $out['stoerungen'] = all("SELECT s.id, s.datum, s.art, s.beschreibung, s.prioritaet, s.status, s.automat_id, " . res_label_sql('automaten', 'a') . " AS automat FROM mv_stoerungen s JOIN mv_automaten a ON a.id = s.automat_id
            WHERE s.status <> 'erledigt' ORDER BY FIELD(s.prioritaet, 'sofort', 'hoch', 'normal'), s.datum LIMIT 20");
        $out['verschleiss'] = array_values(array_filter(verschleiss_liste(), fn($v) => $v['verbraucht'] >= 80));
    }
    if (can('touren')) {
        $out['ueberfaellig'] = all("SELECT a.id, a.nummer, a.bezeichnung, MAX(b.datum) AS letzter FROM mv_automaten a LEFT JOIN mv_besuche b ON b.automat_id = a.id AND b.korrektur_von IS NULL
            WHERE a.status = 'aktiv' GROUP BY a.id, a.nummer, a.bezeichnung HAVING letzter IS NULL OR letzter < ? ORDER BY letzter LIMIT 20", [date('Y-m-d', strtotime('-14 days'))]);
    }
    if (can('rabatte')) {
        $out['rabatteOffen'] = (int)val("SELECT COUNT(*) FROM mv_rabattabrechnungen WHERE status IN ('berechnet', 'geprueft')");
        $out['vertraegeAuslaufend'] = all('SELECT id, vertragsnr, art, ende FROM mv_vertraege WHERE ende IS NOT NULL AND ende BETWEEN ? AND ? ORDER BY ende', [date('Y-m-d'), date('Y-m-d', strtotime('+120 days'))]);
    }
    if (can('stammdaten')) {
        $out['haccp'] = all("SELECT id, vorname, nachname, haccp_schulung FROM mv_mitarbeiter WHERE (austritt IS NULL OR austritt > ?) AND einsatzbereich IN ('Fahrer', 'Techniker', 'Lager') AND (haccp_schulung IS NULL OR haccp_schulung < ?) ORDER BY nachname",
            [date('Y-m-d'), date('Y-m-d', strtotime('-1 year'))]);
    }
    return $out;
});

// Kennzahlen je Automat und Monat. Die Oberfläche gruppiert sie frei nach Kunde, Cluster, Tour, Art, Monat … (wie eine Pivot-Tabelle).
function erfolgsrechnung(string $von, string $bis): array
{
    $fakten = [];
    $key = fn($aid, $monat) => $aid . '|' . $monat;
    $leer = ['umsatz' => 0.0, 'umsatz_netto' => 0.0, 'bargeldlos' => 0.0, 'verkaeufe' => 0, 'besuche' => 0, 'fehlgeld' => 0.0, 'wareneinsatz' => 0.0, 'provision' => 0.0,
        'kosten' => 0.0, 'service' => 0.0, 'vertraege' => 0.0, 'abschreibung' => 0.0];
    $add = function ($aid, $monat, $k, $v) use (&$fakten, $key, $leer) {
        $kk = $key($aid, $monat);
        if (!isset($fakten[$kk])) $fakten[$kk] = ['automat_id' => $aid, 'monat' => $monat] + $leer;
        $fakten[$kk][$k] += $v;
    };
    foreach (all("SELECT b.automat_id, DATE_FORMAT(b.datum, '%Y-%m') AS monat, SUM(b.soll_bar + b.bargeldlos) AS u, SUM((b.soll_bar + b.bargeldlos) / (1 + COALESCE(a.mwst, 19) / 100)) AS n,
            SUM(b.bargeldlos) AS bl, SUM(b.verkaeufe) AS v, SUM(CASE WHEN b.korrektur_von IS NULL THEN 1 ELSE 0 END) AS c, SUM(b.fehlgeld) AS f
        FROM mv_besuche b JOIN mv_automaten a ON a.id = b.automat_id WHERE b.datum BETWEEN ? AND ? GROUP BY b.automat_id, monat", [$von, $bis]) as $r) {
        $add($r['automat_id'], $r['monat'], 'umsatz', (float)$r['u']);
        $add($r['automat_id'], $r['monat'], 'umsatz_netto', (float)$r['n']);
        $add($r['automat_id'], $r['monat'], 'bargeldlos', (float)$r['bl']);
        $add($r['automat_id'], $r['monat'], 'verkaeufe', (int)$r['v']);
        $add($r['automat_id'], $r['monat'], 'besuche', (int)$r['c']);
        $add($r['automat_id'], $r['monat'], 'fehlgeld', (float)$r['f']);
    }
    foreach (all("SELECT automat_id, DATE_FORMAT(datum, '%Y-%m') AS monat, SUM(CASE WHEN art = 'befuellung' THEN menge * ek WHEN art = 'ruecknahme' AND nach_lager_id IS NOT NULL THEN -menge * ek ELSE 0 END) AS w
        FROM mv_bewegungen WHERE automat_id IS NOT NULL AND datum BETWEEN ? AND ? GROUP BY automat_id, monat", [$von, $bis]) as $r) $add($r['automat_id'], $r['monat'], 'wareneinsatz', (float)$r['w']);
    foreach (all("SELECT automat_id, DATE_FORMAT(datum, '%Y-%m') AS monat, SUM(betrag) AS k FROM mv_kosten WHERE datum BETWEEN ? AND ? GROUP BY automat_id, monat", [$von, $bis]) as $r) $add($r['automat_id'], $r['monat'], 'kosten', (float)$r['k']);
    foreach (all("SELECT automat_id, DATE_FORMAT(COALESCE(erledigt_am, datum), '%Y-%m') AS monat, SUM(kosten) AS k FROM mv_stoerungen WHERE kosten > 0 AND COALESCE(erledigt_am, datum) BETWEEN ? AND ? GROUP BY automat_id, monat", [$von, $bis]) as $r) $add($r['automat_id'], $r['monat'], 'service', (float)$r['k']);
    foreach (vertragsraten($von, $bis) as $v) if ($v['automatId'] && $v['betrag'] != 0) $add($v['automatId'], substr($v['datum'], 0, 7), 'vertraege', $v['betrag']);
    // Abschreibung: Kaufpreis der eingebauten Komponenten je Monat über die Nutzungsdauer
    $offen = [];
    foreach (all("SELECT h.komponente_id, h.automat_id, h.datum, h.aktion, k.kaufpreis, k.nutzungsdauer_jahre, k.kaufdatum FROM mv_komponenten_historie h JOIN mv_komponenten k ON k.id = h.komponente_id
        WHERE h.aktion IN ('eingebaut', 'ausgebaut') AND k.kaufpreis > 0 AND k.nutzungsdauer_jahre > 0 ORDER BY h.komponente_id, h.datum, h.created_at") as $h) {
        $id = $h['komponente_id'];
        if ($h['aktion'] === 'eingebaut') { $offen[$id] = $h; continue; }
        if (isset($offen[$id])) { abschreibung_add($add, $offen[$id], $h['datum'], $von, $bis); unset($offen[$id]); }
    }
    foreach ($offen as $h) abschreibung_add($add, $h, $bis, $von, $bis);
    // Provisionen und Standplatzgebühren je Monat, auf die Automaten des Kunden nach Umsatz verteilt
    foreach (all('SELECT * FROM mv_rabatte') as $r) {
        $x = rabatt_berechnen($r, date('Y-m-01', strtotime($von)), $bis);
        foreach ($x['details'] as $d) {
            if (!$d['betrag']) continue;
            if ($r['automat_id']) { $add($r['automat_id'], $d['monat'], 'provision', $d['betrag']); continue; }
            $am = all("SELECT a.id, COALESCE(SUM(b.soll_bar + b.bargeldlos), 0) AS u FROM mv_automaten a JOIN mv_standorte s ON s.id = a.standort_id LEFT JOIN mv_besuche b ON b.automat_id = a.id AND DATE_FORMAT(b.datum, '%Y-%m') = ?
                WHERE s.kunde_id = ? GROUP BY a.id", [$d['monat'], $r['kunde_id']]);
            if (!$am) continue;
            $summe = array_sum(array_column($am, 'u'));
            foreach ($am as $a) $add($a['id'], $d['monat'], 'provision', $summe > 0 ? $d['betrag'] * $a['u'] / $summe : $d['betrag'] / count($am));
        }
    }
    // Stammdaten für die Gruppierung
    $dims = [];
    foreach (all("SELECT a.id, CONCAT_WS(' ', a.nummer, a.bezeichnung) AS automat, a.art, a.status, a.kostenstelle, s.name AS standort, k.firma AS kunde, c.name AS cluster, t.name AS tour
        FROM mv_automaten a LEFT JOIN mv_standorte s ON s.id = a.standort_id LEFT JOIN mv_kunden k ON k.id = s.kunde_id LEFT JOIN mv_cluster c ON c.id = k.cluster_id LEFT JOIN mv_touren t ON t.id = a.tour_id") as $d) $dims[$d['id']] = $d;
    $out = [];
    foreach ($fakten as $f) {
        if ($f['monat'] < substr($von, 0, 7) || $f['monat'] > substr($bis, 0, 7)) continue;
        $d = $dims[$f['automat_id']] ?? ['automat' => '?', 'art' => '', 'standort' => '', 'kunde' => '', 'cluster' => '', 'tour' => '', 'kostenstelle' => '', 'status' => ''];
        foreach ($f as $k => $v) if (is_float($v)) $f[$k] = round($v, 2);
        $f['rohertrag'] = round($f['umsatz_netto'] - $f['wareneinsatz'], 2);
        $f['deckungsbeitrag'] = round($f['rohertrag'] + $f['fehlgeld'] - $f['provision'] - $f['kosten'] - $f['service'] + $f['vertraege'] - $f['abschreibung'], 2);
        $out[] = $f + ['automat' => $d['automat'], 'art' => $d['art'] ?: '(ohne)', 'standort' => $d['standort'] ?: '(ohne)', 'kunde' => $d['kunde'] ?: '(ohne)', 'cluster' => $d['cluster'] ?: '(ohne)',
            'tour' => $d['tour'] ?: '(ohne)', 'kostenstelle' => $d['kostenstelle'] ?: '(ohne)', 'jahr' => substr($f['monat'], 0, 4)];
    }
    usort($out, fn($a, $b) => [$a['automat'], $a['monat']] <=> [$b['automat'], $b['monat']]);
    return $out;
}

function abschreibung_add(callable $add, array $ein, string $aus, string $von, string $bis): void
{
    $proMonat = (float)$ein['kaufpreis'] / ((int)$ein['nutzungsdauer_jahre'] * 12);
    $ende = date('Y-m-d', strtotime(($ein['kaufdatum'] ?: $ein['datum']) . ' +' . (int)$ein['nutzungsdauer_jahre'] . ' years'));
    for ($m = date('Y-m-01', strtotime(max($ein['datum'], $von))); $m <= min($aus, $bis, $ende); $m = date('Y-m-01', strtotime($m . ' +1 month'))) $add($ein['automat_id'], substr($m, 0, 7), 'abschreibung', $proMonat);
}

route('GET', '/erfolgsrechnung', function () {
    need('auswertungen');
    $von = valid_date($_GET['von'] ?? date('Y-01-01'), 'Von');
    $bis = valid_date($_GET['bis'] ?? date('Y-m-t'), 'Bis');
    return ['von' => $von, 'bis' => $bis, 'fakten' => erfolgsrechnung($von, $bis)];
});

// ---------- DATEV-Buchungsstapel (EXTF, Format 700) ----------

function datev_betrag(float $b): string { return number_format(abs($b), 2, ',', ''); }
function datev_text(string $s): string { return '"' . str_replace('"', '""', mb_substr($s, 0, 60)) . '"'; }

route('GET', '/datev', function () {
    need('auswertungen');
    $von = valid_date($_GET['von'] ?? date('Y-m-01', strtotime('first day of last month')), 'Von');
    $bis = valid_date($_GET['bis'] ?? date('Y-m-t', strtotime('last day of last month')), 'Bis');
    $c = setting('datev', []) + ['berater' => '', 'mandant' => '', 'sachkontenlaenge' => 4, 'kasse' => '1000', 'erloese19' => '8400', 'erloese7' => '8300', 'provision' => '4760', 'fehlgeld' => '6969'];
    $zeilen = [];
    $buch = function (float $betrag, string $konto, string $gegen, string $datum, string $beleg, string $text, string $kost = '') use (&$zeilen) {
        if (abs($betrag) < 0.005) return;
        $sh = $betrag >= 0 ? 'S' : 'H';
        $z = array_fill(0, 125, '');
        $z[0] = datev_betrag($betrag); $z[1] = '"' . $sh . '"'; $z[2] = '"EUR"';
        $z[6] = $konto; $z[7] = $gegen; $z[9] = date('dm', strtotime($datum)); $z[10] = datev_text($beleg); $z[13] = datev_text($text); $z[36] = $kost !== '' ? datev_text($kost) : '';
        $zeilen[] = implode(';', $z);
    };
    foreach (all('SELECT b.*, a.nummer, a.mwst, a.kostenstelle FROM mv_besuche b JOIN mv_automaten a ON a.id = b.automat_id WHERE b.datum BETWEEN ? AND ? ORDER BY b.datum, a.nummer', [$von, $bis]) as $b) {
        $erl = ((float)$b['mwst'] === 7.0) ? $c['erloese7'] : $c['erloese19'];
        $beleg = 'AT' . $b['nummer'] . '-' . date('ymd', strtotime($b['datum']));
        $ist = (float)$b['ist_muenzen'] + (float)$b['ist_scheine'] + (float)$b['roehren'];
        $buch((float)$b['soll_bar'], $c['kasse'], $erl, $b['datum'], $beleg, 'Automat ' . $b['nummer'] . ' Barumsatz', $b['kostenstelle'] ?: $b['nummer']);
        $buch((float)$b['bargeldlos'], '1360', $erl, $b['datum'], $beleg, 'Automat ' . $b['nummer'] . ' bargeldlos', $b['kostenstelle'] ?: $b['nummer']);
        $buch(round($ist - (float)$b['soll_bar'], 2), $c['kasse'], $c['fehlgeld'], $b['datum'], $beleg, 'Automat ' . $b['nummer'] . ' Kassendifferenz', $b['kostenstelle'] ?: $b['nummer']);
    }
    foreach (all("SELECT x.*, r.bezeichnung, k.debitor, k.firma FROM mv_rabattabrechnungen x JOIN mv_rabatte r ON r.id = x.rabatt_id JOIN mv_kunden k ON k.id = x.kunde_id WHERE x.status IN ('freigegeben', 'abgerechnet') AND x.bis BETWEEN ? AND ?", [$von, $bis]) as $x) {
        $buch((float)$x['betrag'], $c['provision'], $x['debitor'] ?: '70000', $x['bis'], 'PR-' . substr($x['von'], 0, 7), $x['bezeichnung'] . ' ' . $x['firma']);
    }
    $kopf = ['"EXTF"', '700', '21', '"Buchungsstapel"', '13', date('YmdHis') . '000', '', '"MV"', '""', '""', $c['berater'] ?: '1001', $c['mandant'] ?: '1', date('Y', strtotime($von)) . '0101',
        (string)($c['sachkontenlaenge'] ?: 4), date('Ymd', strtotime($von)), date('Ymd', strtotime($bis)), datev_text('MeoloVEND ' . date('m/Y', strtotime($von))), '""', '1', '0', '0', '"EUR"'];
    $kopf = array_pad($kopf, 31, '');
    $spalten = 'Umsatz (ohne Soll/Haben-Kz);Soll/Haben-Kennzeichen;WKZ Umsatz;Kurs;Basis-Umsatz;WKZ Basis-Umsatz;Konto;Gegenkonto (ohne BU-Schlüssel);BU-Schlüssel;Belegdatum;Belegfeld 1;Belegfeld 2;Skonto;Buchungstext';
    $spalten = implode(';', array_pad(explode(';', $spalten), 125, ''));
    $csv = implode("\r\n", array_merge([implode(';', $kopf), $spalten], $zeilen)) . "\r\n";
    header('Content-Type: text/csv; charset=windows-1252');
    header('Content-Disposition: attachment; filename="EXTF_MeoloVEND_' . $von . '_' . $bis . '.csv"');
    echo mb_convert_encoding($csv, 'Windows-1252', 'UTF-8');
    return null;
});
