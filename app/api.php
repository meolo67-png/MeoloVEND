<?php
// MeoloVEND – JSON-API für PHP 8 + MySQL/MariaDB
// Aufruf: api.php?r=/pfad  (z. B. api.php?r=/login)
declare(strict_types=1);

require __DIR__ . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

final class HttpError extends Exception
{
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}

function out($data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : null;
if (!is_array($cfg)) out(['error' => 'Noch nicht installiert. Bitte install.php aufrufen.'], 503);
date_default_timezone_set($cfg['timezone'] ?? 'Europe/Berlin');

try {
    $pdo = mv_connect($cfg);
} catch (Throwable $e) {
    error_log('MeoloVEND DB: ' . $e->getMessage());
    out(['error' => 'Keine Verbindung zur Datenbank'], 500);
}
$GLOBALS['pdo'] = $pdo;

// Tabellen bei Bedarf auf den aktuellen Stand bringen (neue Version oder neue Felder in meta.php)
try {
    if ((int)setting('schemaVersion', 0) < MV_SCHEMA_VERSION || setting('schemaHash') !== md5(serialize(mv_resources()))) {
        mv_schema($pdo);
        setting_set('schemaHash', md5(serialize(mv_resources())));
    }
} catch (Throwable $e) {
    error_log('MeoloVEND Schema: ' . $e->getMessage());
    out(['error' => 'Datenbank konnte nicht aktualisiert werden'], 500);
}

// ---------- Routen ----------

$ROUTES = [];
function route(string $method, string $pattern, callable $fn): void
{
    $GLOBALS['ROUTES'][] = [$method, '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#', $fn];
}

function body(): array
{
    static $b = null;
    if ($b === null) {
        $b = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($b)) $b = [];
    }
    return $b;
}

// ---------- Anmeldung und Rechte ----------

$USER = null;
function current_user(): ?array
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = str_starts_with($h, 'Bearer ') ? substr($h, 7) : ($_SERVER['HTTP_X_TOKEN'] ?? ($_GET['token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $u = one('SELECT b.* FROM mv_sessions s JOIN mv_benutzer b ON b.id = s.benutzer_id WHERE s.token = ? AND b.aktiv = 1 AND s.last_at > ?', [$token, gmdate('Y-m-d H:i:s', time() - 86400 * 30)]);
    if (!$u) return null;
    q('UPDATE mv_sessions SET last_at = ? WHERE token = ?', [mv_now(), $token]);
    $u['rechte'] = json_decode((string)$u['rechte'], true) ?: [];
    $u['token'] = $token;
    return $u;
}

function user(): array
{
    if (!$GLOBALS['USER']) throw new HttpError(401, 'Bitte anmelden');
    return $GLOBALS['USER'];
}

// Recht auf ein Modul: 'r' lesen, 'w' lesen und schreiben
function can(string $modul, bool $write = false): bool
{
    $u = $GLOBALS['USER'];
    if (!$u) return false;
    if ($u['admin']) return true;
    $r = $u['rechte'][$modul] ?? '';
    return $write ? $r === 'w' : ($r === 'r' || $r === 'w');
}

function need(string $modul, bool $write = false): array
{
    $u = user();
    if (!can($modul, $write)) throw new HttpError(403, 'Dafür fehlt die Berechtigung (' . (MV_MODULE[$modul] ?? $modul) . ($write ? ', schreiben' : '') . ')');
    return $u;
}

function need_admin(): array
{
    $u = user();
    if (!$u['admin']) throw new HttpError(403, 'Nur für Administratoren');
    return $u;
}

// ---------- Abschlüsse sperren ältere Buchungen ----------

function locked_until(): ?string
{
    return val('SELECT MAX(bis) FROM mv_abschluesse');
}

function check_lock(?string $datum, string $was = 'Buchung'): void
{
    $l = locked_until();
    if ($l && $datum && $datum <= $l) {
        throw new HttpError(409, "$was vom " . date('d.m.Y', strtotime($datum)) . ' liegt in einem abgeschlossenen Zeitraum (bis ' . date('d.m.Y', strtotime($l)) . '). Bitte eine Korrekturbuchung anlegen.');
    }
}

function valid_date($d, string $feld = 'Datum'): string
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) throw new HttpError(400, "$feld fehlt oder ist ungültig");
    return $d;
}

function num($v): ?float
{
    if ($v === null || $v === '') return null;
    if (is_string($v)) $v = str_replace(',', '.', trim($v));
    if (!is_numeric($v)) throw new HttpError(400, 'Ungültige Zahl: ' . $v);
    return round((float)$v, 4);
}

// ---------- Teile der API ----------

require __DIR__ . '/lib/api/system.php';
require __DIR__ . '/lib/api/stammdaten.php';
require __DIR__ . '/lib/api/automaten.php';
require __DIR__ . '/lib/api/waren.php';
require __DIR__ . '/lib/api/touren.php';
require __DIR__ . '/lib/api/einnahmen.php';
require __DIR__ . '/lib/api/rabatte.php';
require __DIR__ . '/lib/api/auswertungen.php';

// ---------- Aufruf ----------

$path = '/' . trim((string)($_GET['r'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'];
try {
    $GLOBALS['USER'] = current_user();
    foreach ($ROUTES as [$m, $re, $fn]) {
        if ($m === $method && preg_match($re, $path, $mm)) {
            $args = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
            $res = $fn($args);
            if ($res !== null) out($res);
            exit;
        }
    }
    out(['error' => 'Unbekannter Aufruf: ' . $method . ' ' . $path], 404);
} catch (HttpError $e) {
    out(['error' => $e->getMessage()], $e->status);
} catch (PDOException $e) {
    error_log('MeoloVEND: ' . $e->getMessage());
    if (($e->errorInfo[1] ?? 0) === 1062) out(['error' => 'Eintrag gibt es schon (doppelter Wert)'], 409);
    out(['error' => 'Datenbankfehler'], 500);
} catch (Throwable $e) {
    error_log('MeoloVEND: ' . $e);
    out(['error' => 'Interner Fehler'], 500);
}
