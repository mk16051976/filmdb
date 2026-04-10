<?php
/**
 * admin-fetch-credits.php
 * Lädt vollständige Credits von TMDB:
 *   Drehbuch, Produktion, Musik, Kamera, Schnitt, Filmstudio, alle Darsteller
 * Parallele Requests via curl_multi (10 gleichzeitig).
 */
require_once __DIR__ . '/includes/functions.php';
startSession();
requireLogin();

$db = getDB();
$me = $db->prepare("SELECT role FROM users WHERE id = ?");
$me->execute([$_SESSION['user_id']]);
if (!in_array($me->fetchColumn(), ['Admin', 'Superadmin'])) {
    http_response_code(403); die('Nur für Admins.');
}

// ── Neue Spalten anlegen ───────────────────────────────────────────────────────
$cols = $db->query("SHOW COLUMNS FROM movies")->fetchAll(PDO::FETCH_COLUMN);
$add  = [
    'writer'          => "TEXT NULL DEFAULT NULL",
    'producer'        => "TEXT NULL DEFAULT NULL",
    'composer'        => "TEXT NULL DEFAULT NULL",
    'cinematographer' => "VARCHAR(255) NULL DEFAULT NULL",
    'editor'          => "TEXT NULL DEFAULT NULL",
    'studio'          => "TEXT NULL DEFAULT NULL",
    'credits_fetched' => "TINYINT(1) NOT NULL DEFAULT 0",
];
foreach ($add as $col => $def) {
    if (!in_array($col, $cols)) {
        $db->exec("ALTER TABLE movies ADD COLUMN {$col} {$def}");
    }
}

set_time_limit(600);

$limit     = min((int)($_GET['limit'] ?? 100), 500);
$batchSize = 10;
$doRun     = isset($_GET['action']) && $_GET['action'] === 'run';
$autoNext  = isset($_GET['auto']);
$results   = [];
$updated   = 0;
$errors    = 0;

// ── Paralleles Fetchen ─────────────────────────────────────────────────────────
function fetchCreditsBatch(array $films): array {
    $mh      = curl_multi_init();
    $handles = [];
    $caBundle = ini_get('curl.cainfo') ?: '';
    foreach ($films as $film) {
        $url = 'https://api.themoviedb.org/3/movie/' . (int)$film['tmdb_id']
             . '?api_key=' . TMDB_API_KEY . '&append_to_response=credits';
        $ch  = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
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

// ── Credits aus TMDB-Response extrahieren ─────────────────────────────────────
function extractCredits(array $data): array {
    $crew  = $data['credits']['crew']  ?? [];
    $cast  = $data['credits']['cast']  ?? [];

    // Alle Darsteller (Name)
    $actors = implode(', ', array_column($cast, 'name')) ?: null;

    // Crew nach Job filtern – mehrere Namen pro Rolle möglich
    $writers         = [];
    $producers       = [];
    $composers       = [];
    $cinematographers = [];
    $editors         = [];

    foreach ($crew as $c) {
        $name = trim($c['name'] ?? '');
        if (!$name) continue;
        $job  = $c['job'] ?? '';
        $dept = $c['department'] ?? '';

        if (in_array($job, ['Screenplay', 'Writer', 'Story', 'Novel', 'Comic Book']))
            $writers[] = $name;
        elseif (in_array($job, ['Producer', 'Executive Producer', 'Co-Producer', 'Associate Producer']))
            $producers[] = $name;
        elseif (in_array($job, ['Original Music Composer', 'Music', 'Songs']))
            $composers[] = $name;
        elseif (in_array($job, ['Director of Photography', 'Cinematography']))
            $cinematographers[] = $name;
        elseif (in_array($job, ['Editor', 'Film Editor', 'Co-Editor']))
            $editors[] = $name;
    }

    // Produktionsfirmen
    $studios = implode(', ', array_column($data['production_companies'] ?? [], 'name')) ?: null;

    return [
        'actors'          => $actors,
        'writer'          => $writers          ? implode(', ', array_unique($writers))          : null,
        'producer'        => $producers        ? implode(', ', array_unique($producers))        : null,
        'composer'        => $composers        ? implode(', ', array_unique($composers))        : null,
        'cinematographer' => $cinematographers ? implode(', ', array_unique($cinematographers)) : null,
        'editor'          => $editors          ? implode(', ', array_unique($editors))          : null,
        'studio'          => $studios,
    ];
}

// ── Hauptlauf ──────────────────────────────────────────────────────────────────
if ($doRun) {
    $filmStmt = $db->prepare(
        "SELECT id, tmdb_id, title FROM movies
         WHERE credits_fetched = 0 AND tmdb_id IS NOT NULL
         ORDER BY id ASC LIMIT ?"
    );
    $filmStmt->execute([$limit]);
    $filmRows = $filmStmt->fetchAll();

    $upd = $db->prepare("
        UPDATE movies SET
            actors = ?, writer = ?, producer = ?, composer = ?,
            cinematographer = ?, editor = ?, studio = ?, credits_fetched = 1
        WHERE id = ?
    ");

    foreach (array_chunk($filmRows, $batchSize) as $batch) {
        $responses = fetchCreditsBatch($batch);

        $db->beginTransaction();
        foreach ($batch as $film) {
            $data = $responses[$film['id']] ?? null;
            if (!$data || isset($data['status_code'])) {
                $db->prepare("UPDATE movies SET credits_fetched = 1 WHERE id = ?")->execute([$film['id']]);
                $results[] = ['film' => $film['title'], 'ok' => false];
                $errors++;
                continue;
            }
            $c = extractCredits($data);
            $upd->execute([
                $c['actors'], $c['writer'], $c['producer'], $c['composer'],
                $c['cinematographer'], $c['editor'], $c['studio'],
                $film['id'],
            ]);
            $updated++;
            $results[] = [
                'film'    => $film['title'],
                'ok'      => true,
                'actors'  => mb_strimwidth($c['actors']  ?? '–', 0, 60, '…'),
                'writer'  => mb_strimwidth($c['writer']  ?? '–', 0, 40, '…'),
                'studio'  => mb_strimwidth($c['studio']  ?? '–', 0, 40, '…'),
            ];
        }
        $db->commit();
        usleep(300000); // 300ms zwischen Batches
    }
}

$total     = (int)$db->query("SELECT COUNT(*) FROM movies WHERE tmdb_id IS NOT NULL")->fetchColumn();
$remaining = (int)$db->query("SELECT COUNT(*) FROM movies WHERE credits_fetched = 0 AND tmdb_id IS NOT NULL")->fetchColumn();
$done      = $total - $remaining;
$pct       = $total > 0 ? round($done / $total * 100) : 0;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Credits laden – Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <?php if ($doRun && $autoNext && $remaining > 0): ?>
    <meta http-equiv="refresh" content="2;url=?action=run&limit=<?= $limit ?>&auto=1">
    <?php endif; ?>
    <style>
    body { background: #f8f9fa; }
    .progress { height: 24px; }
    .tbl td { font-size: .8rem; padding: .2rem .5rem; vertical-align: middle; }
    .badge-field { font-size: .7rem; background: #dee2e6; color: #333;
                   border-radius: 3px; padding: 1px 5px; margin-right: 2px; }
    </style>
</head>
<body class="p-4">
<div class="container" style="max-width:1000px;">
    <h2 class="mb-1">🎬 Credits &amp; Besetzung laden</h2>
    <p class="text-muted small mb-3">
        Lädt von TMDB: alle Darsteller, Drehbuch, Produktion, Musik, Kamera, Schnitt, Filmstudio
    </p>

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
    <div class="alert <?= $errors > 0 ? 'alert-warning' : 'alert-success' ?> py-2">
        ✓ <strong><?= $updated ?> Filme</strong> aktualisiert
        <?= $errors > 0 ? " · ⚠ <strong>$errors Fehler</strong>" : '' ?>
        <?php if ($autoNext && $remaining > 0): ?>
        &nbsp;·&nbsp; <span class="text-muted">Weiter in 2 Sek…</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($remaining > 0): ?>
    <div class="d-flex gap-2 flex-wrap align-items-center mb-3">
        <a href="?action=run&limit=100" class="btn btn-primary">▶ 100 laden</a>
        <a href="?action=run&limit=200" class="btn btn-outline-primary">200 laden</a>
        <a href="?action=run&limit=500" class="btn btn-outline-secondary">500 laden</a>
        <span class="text-muted mx-2">|</span>
        <?php if (!($doRun && $autoNext)): ?>
        <a href="?action=run&limit=100&auto=1" class="btn btn-success">⚡ Auto (100er Batches)</a>
        <?php else: ?>
        <a href="?" class="btn btn-outline-danger btn-sm">⏹ Stop</a>
        <?php endif; ?>
    </div>
    <p class="text-muted small">
        Parallele Requests: <strong>10 gleichzeitig</strong> · Batch-Pause: 300ms · Max pro Durchlauf: 500
    </p>
    <?php else: ?>
    <div class="alert alert-success fw-bold">🎉 Alle Credits wurden geladen!</div>
    <?php endif; ?>

    <!-- Ergebnis-Tabelle -->
    <?php if (!empty($results)): ?>
    <table class="table table-sm table-striped tbl mt-3">
        <thead class="table-dark">
            <tr><th>#</th><th>Film</th><th>Darsteller (Vorschau)</th><th>Drehbuch</th><th>Studio</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($results as $i => $r): ?>
        <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($r['film']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($r['actors'] ?? '') ?></td>
            <td class="text-muted"><?= htmlspecialchars($r['writer'] ?? '') ?></td>
            <td class="text-muted"><?= htmlspecialchars($r['studio'] ?? '') ?></td>
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
