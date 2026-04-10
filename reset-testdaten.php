<?php
/**
 * reset-testdaten.php
 * Löscht alle Testdaten aus der Datenbank.
 * NACH VERWENDUNG SOFORT VOM SERVER LÖSCHEN!
 */

require_once __DIR__ . '/config/db.php';

// ── Einfacher Passwortschutz ──────────────────────────────────────────────────
define('RESET_PASSWORD', 'mkfb-reset-2026');

$error   = '';
$success = false;
$log     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['pw'] ?? '') !== RESET_PASSWORD) {
        $error = 'Falsches Passwort.';
    } else {
        $db = getDB();

        $tables = [
            'login_attempts',
            'password_resets',
            'news_comments',
            'fuenf_sessions',
            'film_insert_sessions',
            'duel_sessions',
            'sort_sessions',
            'liga_matches',
            'liga_sessions',
            'tournament_results',
            'tournament_matches',
            'tournament_films',
            'user_tournaments',
            'user_position_ranking',
            'user_ratings',
            'comparisons',
            'users',
        ];

        $db->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            try {
                $db->exec("TRUNCATE TABLE `$table`");
                $log[] = ['ok' => true,  'table' => $table];
            } catch (PDOException $e) {
                $log[] = ['ok' => false, 'table' => $table, 'msg' => $e->getMessage()];
            }
        }

        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Testdaten löschen</title>
<style>
  body { font-family: sans-serif; max-width: 600px; margin: 3rem auto; padding: 0 1rem; background:#111; color:#eee; }
  h1   { color: #e8b84b; }
  .warning { background:#5c1a1a; border:1px solid #c0392b; padding:1rem; border-radius:6px; margin-bottom:1.5rem; }
  input[type=password] { padding:.5rem; width:100%; margin:.5rem 0 1rem; border-radius:4px; border:1px solid #555; background:#222; color:#eee; font-size:1rem; }
  button { background:#e8b84b; color:#111; border:none; padding:.6rem 1.5rem; border-radius:4px; font-size:1rem; cursor:pointer; font-weight:bold; }
  button:hover { background:#f5c842; }
  .error { color:#e74c3c; margin-top:.5rem; }
  .log  { margin-top:1.5rem; }
  .log-row { padding:.3rem .5rem; border-radius:3px; margin:.2rem 0; font-family:monospace; }
  .ok  { background:#1a3a1a; color:#2ecc71; }
  .fail{ background:#3a1a1a; color:#e74c3c; }
  .done { background:#1a3550; border:1px solid #2980b9; padding:1rem; border-radius:6px; margin-top:1.5rem; color:#3498db; font-weight:bold; }
</style>
</head>
<body>

<h1>Testdaten löschen</h1>

<div class="warning">
  ⚠️ Dieses Script löscht alle Benutzer, Votes, Ranglisten und Session-Daten.<br>
  <strong>Filmdaten (movies, news, project_slides) bleiben erhalten.</strong><br><br>
  <strong>Nach der Verwendung diese Datei sofort vom Server löschen!</strong>
</div>

<?php if (!$success): ?>

<form method="post">
  <label>Passwort:</label>
  <input type="password" name="pw" autofocus>
  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <button type="submit">Testdaten löschen</button>
</form>

<?php else: ?>

<div class="log">
<?php foreach ($log as $entry): ?>
  <div class="log-row <?= $entry['ok'] ? 'ok' : 'fail' ?>">
    <?= $entry['ok'] ? '✓' : '✗' ?>
    <?= htmlspecialchars($entry['table']) ?>
    <?php if (!$entry['ok']): ?>
      — <?= htmlspecialchars($entry['msg']) ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<div class="done">
  ✓ Abgeschlossen. Bitte diese Datei jetzt vom Server löschen:<br>
  <code>reset-testdaten.php</code>
</div>

<?php endif; ?>

</body>
</html>
