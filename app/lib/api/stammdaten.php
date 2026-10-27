<?php
// Allgemeine Stammdaten: Liste, Lesen, Speichern und Löschen für alle Tabellen aus meta.php, dazu Dokumente
declare(strict_types=1);

function res_def(string $res): array
{
    $d = mv_resources()[$res] ?? null;
    if (!$d) throw new HttpError(404, 'Unbekannte Tabelle');
    return $d;
}

// SQL-Ausdruck für die Anzeige eines Datensatzes (z. B. „1001 Snackautomat“)
function res_label_sql(string $res, string $alias): string
{
    $parts = array_map(fn($f) => "NULLIF($alias.`$f`, '')", res_def($res)['label']);
    return 'CONCAT_WS(\' \', ' . implode(', ', $parts) . ')';
}

function res_select(string $res): array
{
    $sel = ['t.*'];
    $join = [];
    $i = 0;
    foreach (mv_fields($res) as $f) {
        if ($f['type'] !== 'ref') continue;
        $a = 'j' . $i++;
        $join[] = "LEFT JOIN mv_{$f['opts']} $a ON $a.id = t.`{$f['name']}`";
        $sel[] = res_label_sql($f['opts'], $a) . " AS `{$f['name']}__label`";
    }
    return [implode(', ', $sel), implode(' ', $join)];
}

function res_row(array $r, string $res): array
{
    foreach (mv_fields($res) as $f) {
        if (!array_key_exists($f['name'], $r) || $r[$f['name']] === null) continue;
        if ($f['type'] === 'dec') $r[$f['name']] = (float)$r[$f['name']];
        elseif ($f['type'] === 'int') $r[$f['name']] = (int)$r[$f['name']];
        elseif ($f['type'] === 'bool') $r[$f['name']] = (bool)$r[$f['name']];
    }
    return $r;
}

function res_list(string $res, array $filter = [], string $search = '', int $limit = 2000): array
{
    $d = res_def($res);
    [$sel, $join] = res_select($res);
    $where = ['1=1'];
    $p = [];
    $fields = mv_fields($res);
    foreach ($filter as $k => $v) {
        if (!isset($fields[$k])) continue;
        $where[] = "t.`$k` = ?";
        $p[] = $v;
    }
    if ($search !== '') {
        $or = [];
        foreach ($fields as $f) {
            if (in_array($f['type'], ['text', 'textarea', 'email', 'tel', 'select'], true)) { $or[] = "t.`{$f['name']}` LIKE ?"; $p[] = '%' . $search . '%'; }
        }
        if ($or) $where[] = '(' . implode(' OR ', $or) . ')';
    }
    $order = preg_replace('/(^|,\s*)(\w+)/', '$1t.$2', $d['order'] ?? 'created_at');
    $rows = all("SELECT $sel FROM mv_$res t $join WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT $limit", $p);
    return array_map(fn($r) => res_row($r, $res), $rows);
}

function res_get(string $res, string $id): array
{
    [$sel, $join] = res_select($res);
    $r = one("SELECT $sel FROM mv_$res t $join WHERE t.id = ?", [$id]);
    if (!$r) throw new HttpError(404, res_def($res)['single'] . ' nicht gefunden');
    return res_row($r, $res);
}

// Prüft und speichert einen Datensatz; $intern erlaubt auch schreibgeschützte Felder
function res_save(string $res, array $b, bool $intern = false): array
{
    $d = res_def($res);
    $id = (string)($b['id'] ?? '');
    $alt = $id ? one("SELECT * FROM mv_$res WHERE id = ?", [$id]) : null;
    if ($id && !$alt) throw new HttpError(404, $d['single'] . ' nicht gefunden');
    $row = [];
    foreach (mv_fields($res) as $f) {
        $n = $f['name'];
        if ($f['ro'] && !$intern) continue;
        if (!array_key_exists($n, $b)) { if (!$alt && $f['req']) throw new HttpError(400, 'Bitte „' . $f['label'] . '“ ausfüllen'); continue; }
        $v = $b[$n];
        switch ($f['type']) {
            case 'int': $v = ($v === '' || $v === null) ? null : (int)$v; break;
            case 'dec': $v = num($v); break;
            case 'bool': $v = $v ? 1 : 0; break;
            case 'date': $v = ($v === '' || $v === null) ? null : valid_date($v, $f['label']); break;
            case 'ref':
                $v = ($v === '' || $v === null) ? null : (string)$v;
                if ($v !== null && !one("SELECT id FROM mv_{$f['opts']} WHERE id = ?", [$v])) throw new HttpError(400, '„' . $f['label'] . '“: Eintrag nicht gefunden');
                break;
            case 'select':
                $v = (string)($v ?? '');
                if ($v !== '' && !isset($f['opts'][$v])) throw new HttpError(400, '„' . $f['label'] . '“: ungültige Auswahl');
                break;
            case 'textarea': $v = mb_substr(trim((string)($v ?? '')), 0, 10000); break;
            default: $v = mb_substr(trim((string)($v ?? '')), 0, 190);
        }
        if ($f['req'] && ($v === null || $v === '')) throw new HttpError(400, 'Bitte „' . $f['label'] . '“ ausfüllen');
        $row[$n] = $v;
    }
    // gesperrte Zeiträume
    if (!empty($d['lock'])) {
        if ($alt) check_lock($alt[$d['lock']]);
        check_lock($row[$d['lock']] ?? ($alt[$d['lock']] ?? null));
    }
    // fortlaufende Nummer, wenn leer gelassen
    if (!empty($d['auto']) && !$alt && ($row[$d['auto'][0]] ?? '') === '') $row[$d['auto'][0]] = (string)mv_next_number($res, $d['auto'][1]);
    if ($res === 'komponenten' && !$alt && !$intern) $row['status'] = 'im Lager';
    if ($res === 'faecher') {
        $aid = $row['automat_id'] ?? $alt['automat_id'];
        $fach = $row['fach'] ?? $alt['fach'];
        if (one('SELECT id FROM mv_faecher WHERE automat_id = ? AND fach = ? AND id <> ?', [$aid, $fach, $id])) throw new HttpError(409, "Fach $fach ist in diesem Automaten schon belegt");
    }
    if ($res === 'rabatte' && ($row['art'] ?? $alt['art'] ?? '') === 'staffel') rabatt_staffel($row['staffel'] ?? $alt['staffel'] ?? '');

    $row['updated_at'] = mv_now();
    if ($alt) {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($row)));
        q("UPDATE mv_$res SET $set WHERE id = ?", [...array_values($row), $id]);
    } else {
        $id = mv_uid();
        $row['id'] = $id;
        $row['created_at'] = $row['updated_at'];
        $cols = array_keys($row);
        q("INSERT INTO mv_$res (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($row));
        if ($res === 'komponenten') komp_hist($id, null, date('Y-m-d'), 'angelegt', '');
    }
    return res_get($res, $id);
}

// Wo wird ein Datensatz noch verwendet? (verhindert Löschen)
function res_usage(string $res, string $id): array
{
    $used = [];
    foreach (mv_resources() as $k => $d) {
        foreach (mv_fields($k) as $f) {
            if ($f['type'] === 'ref' && $f['opts'] === $res && (int)val("SELECT COUNT(*) FROM mv_$k WHERE `{$f['name']}` = ?", [$id]) > 0) $used[] = $d['title'];
        }
    }
    $extra = [
        'automaten' => [['mv_besuche', 'automat_id', 'Besuche'], ['mv_bewegungen', 'automat_id', 'Warenbewegungen'], ['mv_komponenten_historie', 'automat_id', 'Komponenten-Historie']],
        'artikel' => [['mv_bewegungen', 'artikel_id', 'Warenbewegungen']],
        'lager' => [['mv_bewegungen', 'von_lager_id', 'Warenbewegungen'], ['mv_bewegungen', 'nach_lager_id', 'Warenbewegungen']],
        'mitarbeiter' => [['mv_besuche', 'mitarbeiter_id', 'Besuche'], ['mv_benutzer', 'mitarbeiter_id', 'Benutzer']],
        'kunden' => [['mv_rabattabrechnungen', 'kunde_id', 'Rabattabrechnungen']],
        'rabatte' => [['mv_rabattabrechnungen', 'rabatt_id', 'Rabattabrechnungen']],
        'komponenten' => [['mv_komponenten_historie', 'komponente_id', 'Historie']],
    ];
    foreach ($extra[$res] ?? [] as [$t, $c, $title]) {
        if ($res === 'komponenten') continue; // Historie wird mit gelöscht
        if ((int)val("SELECT COUNT(*) FROM $t WHERE `$c` = ?", [$id]) > 0) $used[] = $title;
    }
    return array_values(array_unique($used));
}

route('GET', '/r/{res}', function ($a) {
    $d = res_def($a['res']);
    need($d['modul']);
    $filter = [];
    foreach ($_GET as $k => $v) if (str_starts_with($k, 'f_') && is_string($v)) $filter[substr($k, 2)] = $v;
    return res_list($a['res'], $filter, trim((string)($_GET['q'] ?? '')));
});

route('GET', '/r/{res}/{id}', function ($a) {
    need(res_def($a['res'])['modul']);
    return res_get($a['res'], $a['id']);
});

route('POST', '/r/{res}', function ($a) {
    need(res_def($a['res'])['modul'], true);
    return res_save($a['res'], body());
});

route('DELETE', '/r/{res}/{id}', function ($a) {
    $d = res_def($a['res']);
    need($d['modul'], true);
    $alt = one("SELECT * FROM mv_{$a['res']} WHERE id = ?", [$a['id']]);
    if (!$alt) throw new HttpError(404, $d['single'] . ' nicht gefunden');
    if (!empty($d['lock'])) check_lock($alt[$d['lock']]);
    $used = res_usage($a['res'], $a['id']);
    if ($used) throw new HttpError(409, $d['single'] . ' wird noch verwendet (' . implode(', ', $used) . ') und kann nicht gelöscht werden.');
    if ($a['res'] === 'komponenten' && $alt['status'] === 'eingebaut') throw new HttpError(409, 'Die Komponente ist eingebaut. Bitte zuerst ausbauen.');
    $GLOBALS['pdo']->beginTransaction();
    q("DELETE FROM mv_{$a['res']} WHERE id = ?", [$a['id']]);
    if ($a['res'] === 'komponenten') q('DELETE FROM mv_komponenten_historie WHERE komponente_id = ?', [$a['id']]);
    foreach (all('SELECT id, datei FROM mv_dokumente WHERE res = ? AND ref_id = ?', [$a['res'], $a['id']]) as $doc) {
        @unlink(dok_dir() . '/' . $doc['datei']);
        q('DELETE FROM mv_dokumente WHERE id = ?', [$doc['id']]);
    }
    $GLOBALS['pdo']->commit();
    return ['ok' => true];
});

// Auswahlliste für Verweise (id + Anzeige)
route('GET', '/auswahl/{res}', function ($a) {
    $d = res_def($a['res']);
    if (!user()) return [];
    $order = preg_replace('/(^|,\s*)(\w+)/', '$1t.$2', $d['order'] ?? 'created_at');
    return all('SELECT t.id, ' . res_label_sql($a['res'], 't') . " AS label FROM mv_{$a['res']} t ORDER BY $order LIMIT 5000");
});

// ---------- Dokumente (Bilder, PDFs, Verträge, Gesundheitszeugnisse …) ----------

function dok_dir(): string
{
    $dir = dirname(__DIR__, 2) . '/daten/dokumente';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
        @file_put_contents(dirname($dir) . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

route('GET', '/dok/{res}/{id}', function ($a) {
    need(res_def($a['res'])['modul']);
    return all('SELECT id, name, mime, groesse, benutzer, created_at FROM mv_dokumente WHERE res = ? AND ref_id = ? ORDER BY created_at DESC', [$a['res'], $a['id']]);
});

route('POST', '/dok/{res}/{id}', function ($a) {
    $u = need(res_def($a['res'])['modul'], true);
    if (!one("SELECT id FROM mv_{$a['res']} WHERE id = ?", [$a['id']])) throw new HttpError(404, 'Datensatz nicht gefunden');
    $b = body();
    $data = base64_decode((string)($b['daten'] ?? ''), true);
    if ($data === false || $data === '') throw new HttpError(400, 'Datei fehlt');
    if (strlen($data) > 15 * 1024 * 1024) throw new HttpError(413, 'Die Datei ist größer als 15 MB');
    $name = mb_substr(preg_replace('/[\\\\\/:*?"<>|]+/', '_', (string)($b['name'] ?? 'datei')), 0, 180);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: 'application/octet-stream';
    $datei = bin2hex(random_bytes(16));
    if (file_put_contents(dok_dir() . '/' . $datei, $data) === false) throw new HttpError(500, 'Datei konnte nicht gespeichert werden');
    $id = mv_uid();
    q('INSERT INTO mv_dokumente (id, res, ref_id, name, mime, groesse, datei, benutzer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $a['res'], $a['id'], $name, $mime, strlen($data), $datei, $u['name'], mv_now()]);
    return ['id' => $id];
});

route('GET', '/datei/{id}', function ($a) {
    $d = one('SELECT * FROM mv_dokumente WHERE id = ?', [$a['id']]);
    if (!$d) throw new HttpError(404, 'Dokument nicht gefunden');
    need(res_def($d['res'])['modul']);
    $file = dok_dir() . '/' . $d['datei'];
    if (!is_file($file)) throw new HttpError(404, 'Datei fehlt auf dem Server');
    $inline = in_array($d['mime'], ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
    header('Content-Type: ' . ($inline ? $d['mime'] : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($d['name']));
    header('Content-Length: ' . filesize($file));
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    readfile($file);
    return null;
});

route('DELETE', '/datei/{id}', function ($a) {
    $d = one('SELECT * FROM mv_dokumente WHERE id = ?', [$a['id']]);
    if (!$d) throw new HttpError(404, 'Dokument nicht gefunden');
    need(res_def($d['res'])['modul'], true);
    @unlink(dok_dir() . '/' . $d['datei']);
    q('DELETE FROM mv_dokumente WHERE id = ?', [$a['id']]);
    return ['ok' => true];
});
