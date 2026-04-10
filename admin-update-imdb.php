<?php
/**
 * admin-update-imdb.php
 * Lädt fehlende imdb_id-Werte für alle Filme aus der TMDB External-IDs API nach.
 * Nur für Admins. Nach Verwendung löschen oder durch Auth schützen.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$db = getDB();

// Nur Admins
if (!isAdmin()) { http_response_code(403); die('Nur für Admins.'); }

// Schreiboperationen nur per POST+CSRF (GET = immer Dry-Run)
$dryRun = $_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValid();
$limit  = min((int)($_POST['limit'] ?? $_GET['limit'] ?? 50), 500);

// TMDB External-IDs via curl (SSL-verified, kein error-suppression)
// $type = 'movie' oder 'tv' — nutzt jeweils den korrekten TMDB-Endpunkt
function fetchTmdbExternalIds(int $tmdbId, string $type = 'movie'): ?string {
    $endpoint = $type === 'tv'
        ? 'https://api.themoviedb.org/3/tv/' . $tmdbId . '/external_ids?api_key=' . TMDB_API_KEY
        : 'https://api.themoviedb.org/3/movie/' . $tmdbId . '/external_ids?api_key=' . TMDB_API_KEY;
    $ch  = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err || !$body) return null;
    $data = json_decode($body, true);
    $id   = $data['imdb_id'] ?? null;
    return (is_string($id) && preg_match('/^tt\d{7,}$/', $id)) ? $id : null;
}

// Filme/Serien ohne imdb_id aber mit tmdb_id
$stmt = $db->prepare(
    "SELECT id, title, year, tmdb_id, COALESCE(media_type,'movie') AS media_type FROM movies
     WHERE (imdb_id IS NULL OR imdb_id = '') AND tmdb_id IS NOT NULL
     ORDER BY id LIMIT ?"
);
$stmt->execute([$limit]);
$films = $stmt->fetchAll();

$results = [];
$updated = 0;

// Prepared statement einmal außerhalb der Schleife
$upd = $dryRun ? null : $db->prepare("UPDATE movies SET imdb_id = ? WHERE id = ?");

foreach ($films as $film) {
    $imdbId = fetchTmdbExternalIds((int)$film['tmdb_id'], $film['media_type']);

    if ($imdbId === null) {
        $results[] = ['film' => $film, 'status' => 'Keine IMDb-ID in TMDB'];
        usleep(100000);
        continue;
    }

    if (!$dryRun) {
        $upd->execute([$imdbId, $film['id']]);
        $updated++;
    }
    $results[] = ['film' => $film, 'imdb_id' => $imdbId, 'status' => $dryRun ? 'Würde setzen' : 'Gesetzt'];
    usleep(100000); // 100 ms – TMDB Rate Limit
}

// Restliche Filme ohne imdb_id
$total = (int)$db->query(
    "SELECT COUNT(*) FROM movies WHERE (imdb_id IS NULL OR imdb_id = '') AND tmdb_id IS NOT NULL"
)->fetchColumn();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>IMDb-IDs aktualisieren</title>
<style>
body { font-family: monospace; background:#14325a; color:#e0e0e0; padding:2rem; }
h1 { color:#e8b84b; }
table { border-collapse:collapse; width:100%; margin-top:1rem; }
th,td { border:1px solid rgba(255,255,255,.15); padding:.4rem .8rem; text-align:left; }
th { background:rgba(232,184,75,.15); color:#e8b84b; }
.ok { color:#7ec87e; }
.warn { color:#f0a55a; }
.err { color:#e07b7b; }
.btn { display:inline-block; margin:.5rem .3rem 0; padding:.5rem 1.2rem; border-radius:6px;
       background:#e8b84b; color:#14325a; font-weight:700; text-decoration:none; }
.btn.dry { background:rgba(255,255,255,.15); color:#e0e0e0; }
</style>
</head>
<body>
<h1>IMDb-IDs aktualisieren</h1>
<p>Noch <strong><?= $total ?></strong> Filme ohne IMDb-ID (mit TMDB-ID).</p>

<?php $csrf = csrfToken(); ?>
<?php if ($dryRun): ?>
<p style="color:#f0a55a;">⚠ DRY-RUN – es werden keine Änderungen gespeichert.</p>
<form method="post" style="display:inline;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="limit" value="<?= $limit ?>">
    <button type="submit" class="btn">Jetzt speichern (<?= $limit ?> Filme)</button>
</form>
<a href="?limit=<?= $limit ?>" class="btn dry">Nochmal Dry-Run</a>
<?php else: ?>
<p style="color:#7ec87e;">✓ <?= $updated ?> IMDb-IDs gespeichert.</p>
<a href="?limit=<?= $limit ?>" class="btn dry">Dry-Run für nächste <?= $limit ?></a>
<form method="post" style="display:inline;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="limit" value="<?= $limit ?>">
    <button type="submit" class="btn">Nächste <?= $limit ?> speichern</button>
</form>
<?php endif; ?>

<a href="?limit=200" class="btn dry" style="margin-left:1rem;">Limit: 200</a>

<table>
<thead><tr><th>ID</th><th>Titel</th><th>Jahr</th><th>TMDB-ID</th><th>IMDb-ID</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($results as $r):
    $cls = str_contains($r['status'], 'esetzt') ? 'ok' : (str_contains($r['status'], 'Fehler') ? 'err' : 'warn');
?>
<tr>
    <td><?= $r['film']['id'] ?></td>
    <td><?= htmlspecialchars($r['film']['title']) ?></td>
    <td><?= $r['film']['year'] ?></td>
    <td><?= $r['film']['tmdb_id'] ?></td>
    <td class="ok"><?= htmlspecialchars($r['imdb_id'] ?? '–') ?></td>
    <td class="<?= $cls ?>"><?= htmlspecialchars($r['status']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
