<?php
/**
 * admin-fetch-en.php
 * Lädt englische Filmtitel, Beschreibungen und Poster von TMDB.
 * Verwendet curl_multi für parallele Requests (10 gleichzeitig).
 */
require_once __DIR__ . '/includes/functions.php';
startSession();
requireLogin();

$db = getDB();
$me = $db->prepare("SELECT role FROM users WHERE id = ?");
$me->execute([$_SESSION['user_id']]);
if (!in_array($me->fetchColumn(), ['Admin', 'Superadmin'])) { http_response_code(403); die('Nur für Admins.'); }

// EN-Spalten anlegen falls nicht vorhanden
$cols = $db->query("SHOW COLUMNS FROM movies")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('overview_en',    $cols)) $db->exec("ALTER TABLE movies ADD COLUMN overview_en    TEXT         NULL DEFAULT NULL AFTER overview");
if (!in_array('poster_path_en', $cols)) $db->exec("ALTER TABLE movies ADD COLUMN poster_path_en VARCHAR(255) NULL DEFAULT NULL AFTER poster_path");
if (!in_array('title_en',       $cols)) $db->exec("ALTER TABLE movies ADD COLUMN title_en       VARCHAR(255) NULL DEFAULT NULL AFTER title");
if (!in_array('en_fetched',     $cols)) $db->exec("ALTER TABLE movies ADD COLUMN en_fetched     TINYINT(1)   NOT NULL DEFAULT 0");

set_time_limit(600);

$limit      = min((int)($_GET['limit'] ?? 100), 500);
$batchSize  = 10; // parallele Requests gleichzeitig
$doRun      = isset($_GET['action']) && $_GET['action'] === 'run';
$autoNext   = isset($_GET['auto']);
$results    = [];
$updated    = 0;
$errors     = 0;

// ── Paralleles Fetchen via curl_multi ──────────────────────────────────────────
function tmdbFetchBatch(array $films): array {
    $mh      = curl_multi_init();
    $handles = [];
    $caBundle = ini_get('curl.cainfo') ?: '';

    foreach ($films as $film) {
        $endpoint = ($film['media_type'] === 'tv') ? 'tv' : 'movie';
        $url = 'https://api.themoviedb.org/3/' . $endpoint . '/' . (int)$film['tmdb_id']
             . '?api_key=' . TMDB_API_KEY . '&language=en-US';
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'MKFB/1.0',
        ];
        if ($caBundle && file_exists($caBundle)) $opts[CURLOPT_CAINFO] = $caBundle;
        curl_setopt_array($ch, $opts);
        $handles[$film['id']] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 0.3);
    } while ($running > 0);

    $responses = [];
    foreach ($handles as $movieId => $ch) {
        $body = curl_multi_getcontent($ch);
        $responses[$movieId] = $body ? json_decode($body, true) : null;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $responses;
}

if ($doRun) {
    $filmStmt = $db->prepare(
        "SELECT id, tmdb_id, title, COALESCE(media_type,'movie') AS media_type FROM movies
         WHERE (en_fetched = 0 OR title_en IS NULL) AND tmdb_id IS NOT NULL
         ORDER BY id ASC LIMIT ?"
    );
    $filmStmt->execute([$limit]);
    $filmRows = $filmStmt->fetchAll();

    $upd = $db->prepare("UPDATE movies SET title_en = ?, overview_en = ?, poster_path_en = ?, en_fetched = 1 WHERE id = ?");

    // Verarbeitung in Batches von $batchSize parallelen Requests
    foreach (array_chunk($filmRows, $batchSize) as $batch) {
        $responses = tmdbFetchBatch($batch);

        $db->beginTransaction();
        foreach ($batch as $film) {
            $data = $responses[$film['id']] ?? null;
            if (!$data || isset($data['status_code'])) {
                $results[] = ['film' => $film['title'], 'status' => '⚠ Fehler'];
                $errors++;
                // Film als fetched markieren damit er nicht endlos wiederholt wird
                $db->prepare("UPDATE movies SET en_fetched = 1 WHERE id = ?")->execute([$film['id']]);
                continue;
            }
            // TV-API liefert 'name' statt 'title'
            $titleEn    = $data['title'] ?? $data['name'] ?? null;
            $overviewEn = $data['overview']    ?? null;
            $posterEn   = $data['poster_path'] ?? null;
            $upd->execute([$titleEn, $overviewEn, $posterEn, $film['id']]);
            $updated++;
            $results[] = ['film' => $film['title'], 'en' => $titleEn ?? '–', 'ok' => true];
        }
        $db->commit();

        // Kurze Pause zwischen Batches (TMDB Rate Limit: ~40 req/s)
        usleep(300000); // 300ms pro Batch = ~33 req/s
    }
}

$total     = (int)$db->query("SELECT COUNT(*) FROM movies WHERE tmdb_id IS NOT NULL")->fetchColumn();
$remaining = (int)$db->query("SELECT COUNT(*) FROM movies WHERE (en_fetched = 0 OR title_en IS NULL) AND tmdb_id IS NOT NULL")->fetchColumn();
$done      = $total - $remaining;
$pct       = $total > 0 ? round($done / $total * 100) : 0;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>EN-Daten laden – Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <?php if ($doRun && $autoNext && $remaining > 0): ?>
    <meta http-equiv="refresh" content="2;url=?action=run&limit=<?= $limit ?>&auto=1">
    <?php endif; ?>
    <style>
    body { background:#f8f9fa; }
    .progress { height: 24px; }
    .tbl-result td { font-size: .82rem; padding: .2rem .5rem; }
    </style>
</head>
<body class="p-4">
<div class="container" style="max-width:860px;">
    <h2 class="mb-3">🌍 Englische Filmtitel &amp; Beschreibungen laden</h2>

    <!-- Fortschritt -->
    <div class="mb-3">
        <div class="d-flex justify-content-between small text-muted mb-1">
            <span><strong><?= number_format($done) ?></strong> / <?= number_format($total) ?> Filme</span>
            <span><?= $pct ?>% · <strong><?= number_format($remaining) ?> ausstehend</strong></span>
        </div>
        <div class="progress">
            <div class="progress-bar bg-success" style="width:<?= $pct ?>%"><?= $pct ?>%</div>
        </div>
    </div>

    <?php if ($doRun): ?>
    <!-- Ergebnis dieser Runde -->
    <div class="alert <?= $errors > 0 ? 'alert-warning' : 'alert-success' ?> py-2">
        ✓ <strong><?= $updated ?> Filme</strong> aktualisiert
        <?= $errors > 0 ? " · ⚠ <strong>$errors Fehler</strong>" : '' ?>
        <?php if ($autoNext && $remaining > 0): ?>
        &nbsp;·&nbsp; <span class="text-muted">Weiter in 2 Sek…</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($remaining > 0): ?>
    <!-- Steuerung -->
    <div class="d-flex gap-2 flex-wrap align-items-center mb-3">
        <a href="?action=run&limit=100" class="btn btn-primary">▶ 100 laden</a>
        <a href="?action=run&limit=200" class="btn btn-outline-primary">200 laden</a>
        <a href="?action=run&limit=500" class="btn btn-outline-secondary">500 laden</a>
        <span class="text-muted mx-2">|</span>
        <?php if (!($doRun && $autoNext)): ?>
        <a href="?action=run&limit=100&auto=1" class="btn btn-success">
            ⚡ Auto (100er Batches)
        </a>
        <?php else: ?>
        <a href="?" class="btn btn-outline-danger btn-sm">⏹ Stop</a>
        <?php endif; ?>
    </div>
    <p class="text-muted small">
        Parallele Requests: <strong><?= $batchSize ?> gleichzeitig</strong> ·
        Batch-Pause: 300ms · Max pro Durchlauf: 500
    </p>
    <?php else: ?>
    <div class="alert alert-success fw-bold">🎉 Alle EN-Daten wurden geladen!</div>
    <?php endif; ?>

    <!-- Ergebnis-Tabelle -->
    <?php if (!empty($results)): ?>
    <table class="table table-sm table-striped tbl-result mt-3">
        <thead class="table-dark">
            <tr><th>#</th><th>Originaltitel</th><th>EN-Titel</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($results as $i => $r): ?>
        <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($r['film']) ?></td>
            <td><?= htmlspecialchars($r['en'] ?? '') ?></td>
            <td><?= ($r['ok'] ?? false) ? '✓' : '<span class="text-warning">⚠</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <p class="mt-3"><a href="/turnier.php">← Zurück</a></p>
</div>
</body>
</html>
