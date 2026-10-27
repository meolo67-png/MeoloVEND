<?php
// MeoloVEND – Einrichtung: legt Tabellen und den ersten Administrator an und schreibt config.php
declare(strict_types=1);
require __DIR__ . '/lib/db.php';

header('X-Frame-Options: DENY');
$configFile = __DIR__ . '/config.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$installed = is_file($configFile);
$error = '';
$done = false;
$configText = '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$f = [
    'db_host' => $_POST['db_host'] ?? 'localhost', 'db_port' => $_POST['db_port'] ?? '3306', 'db_name' => $_POST['db_name'] ?? '', 'db_user' => $_POST['db_user'] ?? '', 'db_pass' => $_POST['db_pass'] ?? '',
    'firma' => $_POST['firma'] ?? '', 'admin_name' => $_POST['admin_name'] ?? 'Administrator', 'admin_login' => $_POST['admin_login'] ?? 'admin', 'admin_pass' => $_POST['admin_pass'] ?? '',
    'demo' => isset($_POST['demo']) || !$post,
];

if (!$installed && $post) {
    try {
        if (strlen($f['admin_pass']) < 8) throw new RuntimeException('Das Passwort braucht mindestens 8 Zeichen.');
        if (!preg_match('/^[\w.@-]{3,60}$/u', $f['admin_login'])) throw new RuntimeException('Der Benutzername braucht mindestens 3 Zeichen ohne Leerzeichen.');
        $cfg = ['db_host' => trim($f['db_host']), 'db_port' => (int)$f['db_port'], 'db_name' => trim($f['db_name']), 'db_user' => trim($f['db_user']), 'db_pass' => $f['db_pass'], 'timezone' => 'Europe/Berlin'];
        date_default_timezone_set($cfg['timezone']);
        try {
            $pdo = mv_connect($cfg);
        } catch (PDOException $e) {
            throw new RuntimeException('Verbindung zur Datenbank fehlgeschlagen: ' . $e->getMessage());
        }
        $GLOBALS['pdo'] = $pdo;
        if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mv_benutzer'")->fetchColumn() > 0) {
            throw new RuntimeException('In dieser Datenbank ist MeoloVEND schon eingerichtet. Bitte eine leere Datenbank nehmen.');
        }
        mv_schema($pdo);
        setting_set('schemaHash', md5(serialize(mv_resources())));
        mv_seed($pdo, trim($f['firma']), trim($f['admin_name']), trim($f['admin_login']), $f['admin_pass'], (bool)$f['demo']);
        $configText = "<?php\n// MeoloVEND – Zugangsdaten zur Datenbank (von install.php erzeugt)\nreturn " . var_export($cfg, true) . ";\n";
        if (@file_put_contents($configFile, $configText) === false) {
            $error = 'Die Tabellen wurden angelegt, aber config.php konnte nicht geschrieben werden (fehlende Schreibrechte). Bitte den folgenden Inhalt als config.php in den Ordner von MeoloVEND hochladen.';
        } else {
            $configText = '';
        }
        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MeoloVEND einrichten</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="install">
<main class="install-box">
  <div class="panel stack">
    <h2>MeoloVEND einrichten</h2>
<?php if ($installed && !$done): ?>
    <p>MeoloVEND ist bereits eingerichtet. Zum erneuten Einrichten zuerst <code>config.php</code> auf dem Server löschen.</p>
    <p><a href="./">Zur Anmeldung</a></p>
<?php elseif ($done): ?>
    <p class="ok-text">Fertig. Die Tabellen und der Administrator „<?= $h($f['admin_login']) ?>“ sind angelegt.</p>
    <?php if ($error): ?><p class="err-text"><?= $h($error) ?></p><textarea readonly rows="10" style="width:100%"><?= $h($configText) ?></textarea><?php endif; ?>
    <p><strong>Bitte <code>install.php</code> jetzt vom Server löschen.</strong></p>
    <p><a class="btn primary" href="./">Zur Anmeldung</a></p>
<?php else: ?>
    <?php if ($error): ?><p class="err-text"><?= $h($error) ?></p><?php endif; ?>
    <form method="post" class="stack">
      <h3>Datenbank (MySQL oder MariaDB)</h3>
      <div class="grid2">
        <label>Server <input name="db_host" value="<?= $h($f['db_host']) ?>" required></label>
        <label>Port <input name="db_port" value="<?= $h($f['db_port']) ?>" required></label>
        <label>Datenbank <input name="db_name" value="<?= $h($f['db_name']) ?>" required></label>
        <label>Benutzer <input name="db_user" value="<?= $h($f['db_user']) ?>" required></label>
        <label>Passwort <input name="db_pass" type="password" value="<?= $h($f['db_pass']) ?>"></label>
      </div>
      <h3>Betrieb und Administrator</h3>
      <div class="grid2">
        <label>Firma <input name="firma" value="<?= $h($f['firma']) ?>" required></label>
        <label>Name <input name="admin_name" value="<?= $h($f['admin_name']) ?>" required></label>
        <label>Benutzername <input name="admin_login" value="<?= $h($f['admin_login']) ?>" required></label>
        <label>Passwort (mind. 8 Zeichen) <input name="admin_pass" type="password" minlength="8" required></label>
      </div>
      <label class="check"><input type="checkbox" name="demo" <?= $f['demo'] ? 'checked' : '' ?>> Beispieldaten anlegen (2 Automaten, Touren, Besuche, Rabatte)</label>
      <button class="primary">Einrichten</button>
    </form>
<?php endif; ?>
  </div>
</main>
</body>
</html>
