<?php
// MeoloVEND – Datenbankverbindung, Tabellen, Hilfsfunktionen und Beispieldaten
declare(strict_types=1);

require_once __DIR__ . '/meta.php';

const MV_VERSION = '0.1.0'; // Programmversion
const MV_SCHEMA_VERSION = 1;

function mv_connect(array $c): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['db_host'], (int)($c['db_port'] ?? 3306), $c['db_name']);
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'"); // Zeitpunkte in UTC, Belegdaten (DATE) wie eingegeben
    return $pdo;
}

function mv_uid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function mv_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

// Normalisierte Felder einer Tabelle: name, label, type, opts, req, ro
function mv_fields(string $res): array
{
    static $cache = [];
    if (isset($cache[$res])) return $cache[$res];
    $out = [];
    foreach (mv_resources()[$res]['fields'] as $f) {
        $x = ['name' => $f[0], 'label' => $f[1], 'type' => $f[2], 'opts' => null, 'req' => false, 'ro' => false];
        foreach (array_slice($f, 3) as $extra) {
            if ($extra === 'req') $x['req'] = true;
            elseif ($extra === 'ro') $x['ro'] = true;
            else $x['opts'] = $extra;
        }
        if ($x['type'] === 'select') $x['opts'] = mv_field_options($x['opts']);
        $out[$f[0]] = $x;
    }
    return $cache[$res] = $out;
}

function mv_coltype(string $type): string
{
    return match ($type) {
        'textarea' => 'TEXT NULL',
        'int' => 'INT NULL',
        'dec' => 'DECIMAL(12,2) NULL',
        'date' => 'DATE NULL',
        'bool' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'ref' => 'CHAR(36) NULL',
        default => "VARCHAR(190) NOT NULL DEFAULT ''",
    };
}

function mv_schema(PDO $pdo): void
{
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    foreach (mv_resources() as $res => $def) {
        $cols = ['id CHAR(36) PRIMARY KEY'];
        $idx = [];
        foreach (mv_fields($res) as $f) {
            $cols[] = "`{$f['name']}` " . mv_coltype($f['type']);
            if ($f['type'] === 'ref' || $f['type'] === 'date') $idx[] = "INDEX (`{$f['name']}`)";
        }
        $cols[] = 'created_at DATETIME NOT NULL';
        $cols[] = 'updated_at DATETIME NOT NULL';
        $pdo->exec("CREATE TABLE IF NOT EXISTS mv_$res (" . implode(', ', array_merge($cols, $idx)) . ") $opts");
        // neue Felder aus meta.php in bestehende Tabellen übernehmen
        $have = array_column($pdo->query("SHOW COLUMNS FROM mv_$res")->fetchAll(), 'Field');
        foreach (mv_fields($res) as $f) {
            if (!in_array($f['name'], $have, true)) $pdo->exec("ALTER TABLE mv_$res ADD `{$f['name']}` " . mv_coltype($f['type']));
        }
    }
    $sql = [
        "CREATE TABLE IF NOT EXISTS mv_settings (k VARCHAR(50) PRIMARY KEY, v MEDIUMTEXT NOT NULL) $opts",
        "CREATE TABLE IF NOT EXISTS mv_nummern (k VARCHAR(30) PRIMARY KEY, v INT NOT NULL) $opts",
        "CREATE TABLE IF NOT EXISTS mv_benutzer (
            id CHAR(36) PRIMARY KEY, name VARCHAR(100) NOT NULL, login VARCHAR(60) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL,
            admin TINYINT(1) NOT NULL DEFAULT 0, rechte TEXT NULL, mitarbeiter_id CHAR(36) NULL, aktiv TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL
        ) $opts",
        "CREATE TABLE IF NOT EXISTS mv_sessions (token CHAR(64) PRIMARY KEY, benutzer_id CHAR(36) NOT NULL, created_at DATETIME NOT NULL, last_at DATETIME NOT NULL, INDEX (benutzer_id)) $opts",
        "CREATE TABLE IF NOT EXISTS mv_login_fehler (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL, INDEX (ip, created_at)) $opts",
        "CREATE TABLE IF NOT EXISTS mv_dokumente (
            id CHAR(36) PRIMARY KEY, res VARCHAR(30) NOT NULL, ref_id CHAR(36) NOT NULL, name VARCHAR(190) NOT NULL, mime VARCHAR(100) NOT NULL,
            groesse INT NOT NULL, datei VARCHAR(80) NOT NULL, benutzer VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, INDEX (res, ref_id)
        ) $opts",
        "CREATE TABLE IF NOT EXISTS mv_komponenten_historie (
            id CHAR(36) PRIMARY KEY, komponente_id CHAR(36) NOT NULL, automat_id CHAR(36) NULL, datum DATE NOT NULL, aktion VARCHAR(40) NOT NULL,
            text VARCHAR(255) NOT NULL DEFAULT '', verkaeufe INT NULL, benutzer VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, INDEX (komponente_id), INDEX (automat_id)
        ) $opts",
        "CREATE TABLE IF NOT EXISTS mv_besuche (
            id CHAR(36) PRIMARY KEY, datum DATE NOT NULL, automat_id CHAR(36) NOT NULL, tour_id CHAR(36) NULL, mitarbeiter_id CHAR(36) NULL, fahrzeug_id CHAR(36) NULL,
            zaehler_verkaeufe INT NULL, zaehler_bar DECIMAL(12,2) NULL, zaehler_bargeldlos DECIMAL(12,2) NULL,
            verkaeufe INT NULL, soll_bar DECIMAL(12,2) NOT NULL DEFAULT 0, ist_muenzen DECIMAL(12,2) NOT NULL DEFAULT 0, ist_scheine DECIMAL(12,2) NOT NULL DEFAULT 0,
            roehren DECIMAL(12,2) NOT NULL DEFAULT 0, bargeldlos DECIMAL(12,2) NOT NULL DEFAULT 0, fehlgeld DECIMAL(12,2) NOT NULL DEFAULT 0,
            gereinigt TINYINT(1) NOT NULL DEFAULT 0, korrektur_von CHAR(36) NULL, audit_id CHAR(36) NULL, bemerkung TEXT NULL,
            benutzer VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, INDEX (automat_id, datum), INDEX (datum)
        ) $opts",
        "CREATE TABLE IF NOT EXISTS mv_bewegungen (
            id CHAR(36) PRIMARY KEY, datum DATE NOT NULL, art VARCHAR(20) NOT NULL, artikel_id CHAR(36) NOT NULL, menge DECIMAL(12,3) NOT NULL,
            von_lager_id CHAR(36) NULL, nach_lager_id CHAR(36) NULL, automat_id CHAR(36) NULL, besuch_id CHAR(36) NULL, fach VARCHAR(20) NOT NULL DEFAULT '',
            ek DECIMAL(12,4) NOT NULL DEFAULT 0, beleg VARCHAR(60) NOT NULL DEFAULT '', text VARCHAR(255) NOT NULL DEFAULT '', mhd DATE NULL,
            benutzer VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL,
            INDEX (artikel_id), INDEX (von_lager_id), INDEX (nach_lager_id), INDEX (automat_id, datum), INDEX (besuch_id), INDEX (datum)
        ) $opts",
        "CREATE TABLE IF NOT EXISTS mv_audits (
            id CHAR(36) PRIMARY KEY, automat_id CHAR(36) NULL, geraet_id VARCHAR(60) NOT NULL DEFAULT '', datum DATETIME NOT NULL, quelle VARCHAR(30) NOT NULL,
            verkaeufe_gesamt INT NULL, umsatz_gesamt DECIMAL(12,2) NULL, bar_gesamt DECIMAL(12,2) NULL, bargeldlos_gesamt DECIMAL(12,2) NULL,
            faecher TEXT NULL, rohdaten MEDIUMTEXT NULL, besuch_id CHAR(36) NULL, benutzer VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, INDEX (automat_id, datum)
        ) $opts",
        "CREATE TABLE IF NOT EXISTS mv_abschluesse (
            id CHAR(36) PRIMARY KEY, nummer INT NOT NULL, art VARCHAR(10) NOT NULL, von DATE NOT NULL, bis DATE NOT NULL, summen TEXT NOT NULL,
            benutzer VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, INDEX (bis)
        ) $opts",
        "CREATE TABLE IF NOT EXISTS mv_rabattabrechnungen (
            id CHAR(36) PRIMARY KEY, rabatt_id CHAR(36) NOT NULL, kunde_id CHAR(36) NOT NULL, von DATE NOT NULL, bis DATE NOT NULL,
            umsatz DECIMAL(12,2) NOT NULL, betrag DECIMAL(12,2) NOT NULL, details TEXT NULL, status VARCHAR(20) NOT NULL DEFAULT 'berechnet',
            geprueft_von VARCHAR(100) NULL, geprueft_am DATETIME NULL, created_at DATETIME NOT NULL, UNIQUE KEY (rabatt_id, von, bis), INDEX (kunde_id)
        ) $opts",
    ];
    foreach ($sql as $s) $pdo->exec($s);
    $pdo->prepare('INSERT INTO mv_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute(['schemaVersion', json_encode(MV_SCHEMA_VERSION)]);
}

// ---------- Kurzbefehle für Abfragen ----------

function q(string $sql, array $p = []): PDOStatement
{
    $st = $GLOBALS['pdo']->prepare($sql);
    $st->execute($p);
    return $st;
}
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r === false ? null : $r; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

function setting(string $k, $default = null)
{
    $v = val('SELECT v FROM mv_settings WHERE k = ?', [$k]);
    return $v === null ? $default : json_decode($v, true);
}
function setting_set(string $k, $v): void
{
    q('INSERT INTO mv_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}

// Fortlaufende Nummer (Kundennummer, Automatennummer, Abschlussnummer)
function mv_next_number(string $k, int $start): int
{
    q('INSERT INTO mv_nummern (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = v + 1', [$k, $start]);
    return (int)val('SELECT v FROM mv_nummern WHERE k = ?', [$k]);
}

// Ein Datensatz in eine generische Tabelle (für Beispieldaten und Import)
function mv_insert(string $res, array $row): string
{
    $id = $row['id'] ?? mv_uid();
    $row['id'] = $id;
    $row['created_at'] = $row['updated_at'] = mv_now();
    $cols = array_keys($row);
    q("INSERT INTO mv_$res (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($row));
    return $id;
}

function mv_hash(string $pass): string
{
    return password_hash($pass, PASSWORD_DEFAULT);
}

// Grunddaten nach der Installation; mit $demo ein kleiner Betrieb zum Ausprobieren
function mv_seed(PDO $pdo, string $firma, string $adminName, string $adminLogin, string $adminPass, bool $demo): void
{
    $GLOBALS['pdo'] = $pdo;
    setting_set('firma', $firma);
    setting_set('waehrung', '€');
    setting_set('mwst', 19);
    setting_set('datev', ['berater' => '', 'mandant' => '', 'sachkontenlaenge' => 4, 'kasse' => '1000', 'erloese19' => '8400', 'erloese7' => '8300', 'provision' => '4760', 'fehlgeld' => '6969']);
    q('INSERT INTO mv_benutzer (id, name, login, pass_hash, admin, rechte, aktiv, created_at) VALUES (?, ?, ?, ?, 1, ?, 1, ?)',
        [mv_uid(), $adminName, $adminLogin, mv_hash($adminPass), '{}', mv_now()]);
    if ($demo) mv_demo();
}

function mv_demo(): void
{
    $o1 = mv_insert('orte', ['plz' => '80331', 'ort' => 'München', 'land' => 'Deutschland']);
    $o2 = mv_insert('orte', ['plz' => '85748', 'ort' => 'Garching', 'land' => 'Deutschland']);
    $m1 = mv_insert('mitarbeiter', ['personalnr' => 'P001', 'vorname' => 'Max', 'nachname' => 'Fahrer', 'einsatzbereich' => 'Fahrer', 'ort_id' => $o1, 'eintritt' => '2023-01-01', 'haccp_schulung' => '2026-03-01']);
    $m2 = mv_insert('mitarbeiter', ['personalnr' => 'P002', 'vorname' => 'Tina', 'nachname' => 'Technik', 'einsatzbereich' => 'Techniker', 'ort_id' => $o2, 'eintritt' => '2024-05-01']);
    $cl = mv_insert('cluster', ['name' => 'Industrie']);
    $cl2 = mv_insert('cluster', ['name' => 'Schulen und Behörden']);
    $k1 = mv_insert('kunden', ['kundennr' => (string)mv_next_number('kunden', 10001), 'firma' => 'Muster Maschinenbau GmbH', 'ansprechpartner' => 'Frau Huber', 'ort_id' => $o2, 'cluster_id' => $cl]);
    $k2 = mv_insert('kunden', ['kundennr' => (string)mv_next_number('kunden', 10001), 'firma' => 'Gymnasium am See', 'ansprechpartner' => 'Herr Berger', 'ort_id' => $o1, 'cluster_id' => $cl2]);
    $s1 = mv_insert('standorte', ['kunde_id' => $k1, 'name' => 'Muster – Kantine Halle 2', 'ort_id' => $o2, 'gebaeude' => 'Halle 2, EG']);
    $s2 = mv_insert('standorte', ['kunde_id' => $k2, 'name' => 'Gymnasium – Aula', 'ort_id' => $o1, 'zugang' => 'Hausmeister, Mo–Fr 7–16 Uhr']);
    $h1 = mv_insert('lieferanten', ['art' => 'Hersteller', 'name' => 'Vendomat AG']);
    $l1 = mv_insert('lieferanten', ['art' => 'Lieferant', 'name' => 'Großhandel Süd']);
    $lager = mv_insert('lager', ['name' => 'Hauptlager', 'art' => 'Lager']);
    $fz = mv_insert('lager', ['name' => 'Sprinter M-VD 100', 'art' => 'Fahrzeug', 'kennzeichen' => 'M-VD 100', 'mitarbeiter_id' => $m1]);
    $t1 = mv_insert('touren', ['name' => 'Tour Nord', 'mitarbeiter_id' => $m1, 'fahrzeug_id' => $fz, 'wochentage' => 'Mo, Mi, Fr']);
    $wg1 = mv_insert('warengruppen', ['name' => 'Snacks', 'erloeskonto' => '8300']);
    $wg2 = mv_insert('warengruppen', ['name' => 'Getränke', 'erloeskonto' => '8400']);
    $art = [];
    foreach ([['Schokoriegel', $wg1, 0.45, 1.50, 7], ['Chips 75 g', $wg1, 0.60, 1.80, 7], ['Wasser 0,5 l', $wg2, 0.25, 1.50, 19], ['Cola 0,5 l', $wg2, 0.55, 2.20, 19]] as $i => $a) {
        $art[] = mv_insert('artikel', ['artikelnr' => (string)mv_next_number('artikel', 1001), 'bezeichnung' => $a[0], 'warengruppe_id' => $a[1], 'einheit' => 'Stück', 'ek' => $a[2], 'vk' => $a[3], 'mwst' => $a[4], 'lieferant_id' => $l1, 'aktiv' => 1]);
    }
    $a1 = mv_insert('automaten', ['nummer' => (string)mv_next_number('automaten', 1001), 'bezeichnung' => 'Snackautomat Halle 2', 'art' => 'Snacks', 'status' => 'aktiv', 'standort_id' => $s1, 'tour_id' => $t1, 'aufgestellt_am' => '2025-02-01', 'planumsatz' => 900, 'mwst' => 7, 'telemetrie_id' => 'SN-1001']);
    $a2 = mv_insert('automaten', ['nummer' => (string)mv_next_number('automaten', 1001), 'bezeichnung' => 'Kaltgetränke Aula', 'art' => 'Kaltgetränke', 'status' => 'aktiv', 'standort_id' => $s2, 'tour_id' => $t1, 'aufgestellt_am' => '2025-09-01', 'planumsatz' => 600, 'mwst' => 19, 'telemetrie_id' => 'SN-1002']);
    foreach ([[$a1, 'A1', $art[0], 1.50], [$a1, 'A2', $art[1], 1.80], [$a2, '1', $art[2], 1.50], [$a2, '2', $art[3], 2.20]] as $f) {
        mv_insert('faecher', ['automat_id' => $f[0], 'fach' => $f[1], 'artikel_id' => $f[2], 'preis' => $f[3], 'kapazitaet' => 40, 'meldebestand' => 8]);
    }
    foreach ([[$a1, 'grundautomat', 'Snack 6000', 'SA-2231', 4800], [$a1, 'muenzer', 'Münzer CF 7000', 'MZ-8812', 420], [$a1, 'bezahlsystem', 'Kartenleser NFC', 'KL-117', 350],
              [$a2, 'grundautomat', 'Cool 400', 'CA-1197', 5200], [$a1, 'verschleissteil', 'Spiralmotor', 'SM-01', 35]] as $k) {
        $kid = mv_insert('komponenten', ['typ' => $k[1], 'bezeichnung' => $k[2], 'seriennr' => $k[3], 'hersteller_id' => $h1, 'kaufpreis' => $k[4], 'kaufdatum' => '2025-01-15',
            'nutzungsdauer_jahre' => $k[1] === 'grundautomat' ? 7 : 5, 'lebensdauer_tage' => $k[1] === 'verschleissteil' ? 365 : null, 'status' => 'eingebaut', 'automat_id' => $k[0], 'eingebaut_am' => '2025-02-01']);
        q('INSERT INTO mv_komponenten_historie (id, komponente_id, automat_id, datum, aktion, text, benutzer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [mv_uid(), $kid, $k[0], '2025-02-01', 'eingebaut', 'Erstausstattung', 'Beispieldaten', mv_now()]);
    }
    mv_insert('rabatte', ['bezeichnung' => 'Umsatzprovision Muster', 'kunde_id' => $k1, 'art' => 'staffel', 'staffel' => '0:5; 400:10', 'basis' => 'brutto', 'intervall' => 'halbjahr', 'gueltig_ab' => '2025-01-01']);
    mv_insert('rabatte', ['bezeichnung' => 'Standplatzgebühr Gymnasium', 'kunde_id' => $k2, 'art' => 'standplatz', 'wert' => 5, 'intervall' => 'monat', 'gueltig_ab' => '2025-09-01']);
    mv_insert('vertraege', ['vertragsnr' => 'V-2025-01', 'art' => 'Aufstellvertrag', 'kunde_id' => $k1, 'automat_id' => $a1, 'beginn' => '2025-02-01', 'kuendigungsfrist' => '3 Monate zum Quartalsende']);
    mv_insert('vertraege', ['vertragsnr' => 'L-2025-07', 'art' => 'Ratenkauf / Leasing (wir zahlen)', 'lieferant_id' => $h1, 'automat_id' => $a2, 'beginn' => '2025-09-01', 'rate' => 89, 'intervall' => 'monat', 'anzahl_raten' => 48]);
    // Wareneingang ins Lager und eine Umlagerung ins Fahrzeug
    foreach ($art as $i => $aid) {
        $ek = (float)val('SELECT ek FROM mv_artikel WHERE id = ?', [$aid]);
        q('INSERT INTO mv_bewegungen (id, datum, art, artikel_id, menge, nach_lager_id, ek, beleg, benutzer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [mv_uid(), date('Y-m-01', strtotime('-2 months')), 'wareneingang', $aid, 500, $lager, $ek, 'LS-1', 'Beispieldaten', mv_now()]);
        q('INSERT INTO mv_bewegungen (id, datum, art, artikel_id, menge, von_lager_id, nach_lager_id, ek, benutzer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [mv_uid(), date('Y-m-02', strtotime('-2 months')), 'umlagerung', $aid, 200, $lager, $fz, $ek, 'Beispieldaten', mv_now()]);
    }
    // Besuche der letzten Wochen mit Zählerständen, Befüllung und Kassierung
    $zb = [$a1 => 12000.0, $a2 => 8000.0]; $zl = [$a1 => 3000.0, $a2 => 1500.0]; $zv = [$a1 => 9000, $a2 => 5000];
    $fa = [$a1 => [['A1', $art[0], 0.45, 1.5], ['A2', $art[1], 0.60, 1.8]], $a2 => [['1', $art[2], 0.25, 1.5], ['2', $art[3], 0.55, 2.2]]];
    mt_srand(7);
    for ($w = 8; $w >= 1; $w--) {
        foreach ([$a1, $a2] as $aid) {
            $datum = date('Y-m-d', strtotime("-$w weeks"));
            $bid = mv_uid();
            $verk = 0; $bar = 0.0;
            foreach ($fa[$aid] as $f) {
                $n = mt_rand(30, 70);
                $verk += $n; $bar += $n * $f[3];
                q('INSERT INTO mv_bewegungen (id, datum, art, artikel_id, menge, von_lager_id, automat_id, besuch_id, fach, ek, benutzer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [mv_uid(), $datum, 'befuellung', $f[1], $n, $fz, $aid, $bid, $f[0], $f[2], 'Beispieldaten', mv_now()]);
            }
            $los = round($bar * 0.25, 2); $bar = round($bar - $los, 2);
            $zb[$aid] += $bar; $zl[$aid] += $los; $zv[$aid] += $verk;
            $fehl = $w === 3 && $aid === $a1 ? -4.50 : 0.0;
            $scheine = floor($bar / 2 / 5) * 5;
            q('INSERT INTO mv_besuche (id, datum, automat_id, tour_id, mitarbeiter_id, fahrzeug_id, zaehler_verkaeufe, zaehler_bar, zaehler_bargeldlos, verkaeufe, soll_bar, ist_muenzen, ist_scheine, roehren, bargeldlos, fehlgeld, gereinigt, benutzer, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 1, ?, ?)',
                [$bid, $datum, $aid, $t1, $m1, $fz, $zv[$aid], $zb[$aid], $zl[$aid], $verk, $bar, round($bar - $scheine + $fehl, 2), $scheine, $los, $fehl, 'Beispieldaten', mv_now()]);
        }
    }
    mv_insert('stoerungen', ['automat_id' => $a1, 'datum' => date('Y-m-d', strtotime('-3 days')), 'art' => 'Störung', 'quelle' => 'Kunde', 'beschreibung' => 'Fach A2 gibt nicht aus', 'prioritaet' => 'hoch', 'status' => 'offen', 'mitarbeiter_id' => $m2]);
    mv_insert('kosten', ['automat_id' => $a1, 'datum' => date('Y-m-d', strtotime('-1 month')), 'art' => 'Ersatzteile', 'betrag' => 35, 'text' => 'Spiralmotor']);
}
