<?php
/**
 * admin-import-csv.php
 * Importiert Filme aus einer CSV-Datei mit IMDB-Nummern.
 * Phase 1: CSV hochladen → import_queue Tabelle befüllen  (sync, schnell)
 * Phase 2: AJAX-Batch-Verarbeitung (JS ruft /admin-import-csv.php?action=run_batch
 *          wiederholt auf, bis die Queue leer ist) → Echtzeit-Fortschrittsbalken
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

set_time_limit(120);

// ── Queue-Tabelle anlegen ─────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS import_queue (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    imdb_id  VARCHAR(20) NOT NULL UNIQUE,
    status   ENUM('pending','done','error','skip') NOT NULL DEFAULT 'pending',
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── AJAX: Batch verarbeiten ───────────────────────────────────────────────────
if ($action === 'run_batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

    $batchSize = (int)($_POST['batch'] ?? 10);
    $batchSize = max(1, min($batchSize, 20));

    $qStmt = $db->prepare("SELECT id, imdb_id FROM import_queue WHERE status = 'pending' ORDER BY id ASC LIMIT ?");
    $qStmt->execute([$batchSize]);
    $queue = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    $inserted = 0; $skipped = 0; $errors = 0;
    $results  = [];
    $updQ     = $db->prepare("UPDATE import_queue SET status = ? WHERE imdb_id = ?");
    $insMovie = $db->prepare(
        "INSERT IGNORE INTO movies (title, original_title, year, genre, director, actors, country, imdb_id, tmdb_id, poster_path, overview, media_type)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!empty($queue)) {
        // ── Schritt 1: TMDB Find (parallel) ──────────────────────────────────
        $findUrls = [];
        foreach ($queue as $item) {
            $findUrls[$item['imdb_id']] =
                'https://api.themoviedb.org/3/find/' . urlencode($item['imdb_id'])
                . '?api_key=' . TMDB_API_KEY . '&external_source=imdb_id&language=de-DE';
        }
        $findResults = _csvCurlBatch($findUrls);

        // ── Schritt 2: Volldetails (parallel) ────────────────────────────────
        $detailUrls = []; $tmdbMeta = [];
        foreach ($queue as $item) {
            $found  = $findResults[$item['imdb_id']] ?? null;
            if (!$found) continue;
            $isMovie   = !empty($found['movie_results']);
            $base      = $found['movie_results'][0] ?? $found['tv_results'][0] ?? null;
            if (!$base || empty($base['id'])) continue;
            $tmdbId    = (int)$base['id'];
            $mediaType = $isMovie ? 'movie' : 'tv';
            $detailUrls[$item['imdb_id']] =
                'https://api.themoviedb.org/3/' . $mediaType . '/' . $tmdbId
                . '?api_key=' . TMDB_API_KEY . '&language=de-DE&append_to_response=credits';
            $tmdbMeta[$item['imdb_id']] = ['tmdb_id' => $tmdbId, 'media_type' => $mediaType];
        }
        $detailResults = $detailUrls ? _csvCurlBatch($detailUrls) : [];

        // ── Schritt 3: Einfügen ───────────────────────────────────────────────
        $db->beginTransaction();
        foreach ($queue as $item) {
            $imdbId = $item['imdb_id'];
            $found  = $findResults[$imdbId] ?? null;

            if (!$found) {
                $updQ->execute(['error', $imdbId]);
                $results[] = ['imdb' => $imdbId, 'status' => '⚠ API-Fehler', 'ok' => false];
                $errors++;
                continue;
            }

            $isMovie   = !empty($found['movie_results']);
            $base      = $found['movie_results'][0] ?? $found['tv_results'][0] ?? null;
            $mediaType = $isMovie ? 'movie' : 'tv';

            if (!$base) {
                $updQ->execute(['error', $imdbId]);
                $results[] = ['imdb' => $imdbId, 'status' => '✗ Nicht bei TMDB', 'ok' => false];
                $errors++;
                continue;
            }

            $meta   = $tmdbMeta[$imdbId] ?? null;
            $tmdbId = $meta ? $meta['tmdb_id'] : (int)$base['id'];

            // Bereits vorhanden?
            $ex = $db->prepare("SELECT id FROM movies WHERE imdb_id = ? OR (tmdb_id = ? AND COALESCE(media_type,'movie') = ?)");
            $ex->execute([$imdbId, $tmdbId, $mediaType]);
            if ($ex->fetchColumn()) {
                $updQ->execute(['skip', $imdbId]);
                $results[] = ['imdb' => $imdbId, 'status' => '↷ Bereits vorhanden', 'ok' => null];
                $skipped++;
                continue;
            }

            $detail    = $detailResults[$imdbId] ?? null;
            $title     = $detail['title']          ?? $detail['name']          ?? $base['title']          ?? $base['name']          ?? '';
            $origTitle = $detail['original_title'] ?? $detail['original_name'] ?? $base['original_title'] ?? $base['original_name'] ?? null;
            $relDate   = $detail['release_date']   ?? $detail['first_air_date'] ?? $base['release_date']  ?? $base['first_air_date'] ?? '';
            $year      = $relDate ? (int)substr($relDate, 0, 4) : null;
            $poster    = $detail['poster_path'] ?? $base['poster_path'] ?? null;
            $overview  = $detail['overview']    ?? $base['overview']    ?? null;
            $genre = $director = $actors = $country = null;
            if ($detail) {
                if (!empty($detail['genres']))               $genre    = implode(', ', array_column($detail['genres'], 'name'));
                if (!empty($detail['production_countries'])) $country  = $detail['production_countries'][0]['name'] ?? null;
                if (!empty($detail['credits']['crew'])) {
                    $dirs = array_filter($detail['credits']['crew'], fn($c) => $c['job'] === 'Director');
                    if ($dirs) $director = implode(', ', array_column(array_values($dirs), 'name'));
                }
                if (!empty($detail['credits']['cast']))
                    $actors = implode(', ', array_column(array_slice($detail['credits']['cast'], 0, 10), 'name'));
            }

            $insMovie->execute([$title, $origTitle, $year, $genre, $director, $actors, $country, $imdbId, $tmdbId, $poster, $overview, $mediaType]);
            $updQ->execute(['done', $imdbId]);
            $results[] = ['imdb' => $imdbId, 'title' => $title, 'year' => $year, 'type' => $mediaType, 'status' => '✓', 'ok' => true];
            $inserted++;
        }
        $db->commit();
    }

    // Aktuelle Queue-Statistiken
    $pending = (int)$db->query("SELECT COUNT(*) FROM import_queue WHERE status='pending'")->fetchColumn();
    $done    = (int)$db->query("SELECT COUNT(*) FROM import_queue WHERE status='done'")->fetchColumn();
    $skip    = (int)$db->query("SELECT COUNT(*) FROM import_queue WHERE status='skip'")->fetchColumn();
    $err     = (int)$db->query("SELECT COUNT(*) FROM import_queue WHERE status='error'")->fetchColumn();
    $total   = $pending + $done + $skip + $err;

    echo json_encode([
        'ok'       => true,
        'inserted' => $inserted,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'results'  => $results,
        'queue'    => ['pending' => $pending, 'done' => $done, 'skip' => $skip, 'error' => $err, 'total' => $total],
    ]);
    exit;
}

// ── AJAX: Queue-Verwaltung ───────────────────────────────────────────────────
if ($action === 'reset_errors' && $_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    $db->exec("UPDATE import_queue SET status = 'pending' WHERE status = 'error'");
    header('Location: admin-import-csv.php'); exit;
}
if ($action === 'clear_queue' && $_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    $db->exec("TRUNCATE TABLE import_queue");
    header('Location: admin-import-csv.php'); exit;
}

// ── Phase 1: CSV hochladen ────────────────────────────────────────────────────
$uploadMsg = '';
if ($action === 'upload' && isset($_FILES['csvfile'])) {
    if (!csrfValid()) {
        $uploadMsg = '<div class="alert alert-danger">Ungültige Anfrage (CSRF).</div>';
    } elseif ($_FILES['csvfile']['error'] !== UPLOAD_ERR_OK) {
        $uploadMsg = '<div class="alert alert-danger">Upload-Fehler: Code ' . (int)$_FILES['csvfile']['error'] . '</div>';
    } else {
        $handle = fopen($_FILES['csvfile']['tmp_name'], 'r');
        $new = 0; $dup = 0;
        $ins = $db->prepare("INSERT IGNORE INTO import_queue (imdb_id) VALUES (?)");
        $db->beginTransaction();
        while (($row = fgetcsv($handle)) !== false) {
            foreach ($row as $cell) {
                $cell = trim($cell, " \t\n\r\0\x0B\"'");
                if (preg_match('/^tt\d{7,}$/', $cell)) {
                    $ins->execute([$cell]);
                    if ($ins->rowCount() > 0) $new++;
                    else $dup++;
                    break;
                }
            }
        }
        $db->commit();
        fclose($handle);
        $db->exec("UPDATE import_queue iq INNER JOIN movies m ON m.imdb_id = iq.imdb_id
                   SET iq.status = 'skip' WHERE iq.status = 'pending'");
        $skippedNow = (int)$db->query("SELECT ROW_COUNT()")->fetchColumn();
        $uploadMsg = "<div class='alert alert-success'>
            ✓ <strong>$new</strong> neue IMDB-IDs in die Queue aufgenommen"
            . ($dup > 0 ? " · <strong>$dup</strong> bereits in Queue" : '')
            . "</div>";
    }
}

// ── Statistiken ───────────────────────────────────────────────────────────────
$qTotal   = (int)$db->query("SELECT COUNT(*) FROM import_queue")->fetchColumn();
$qPending = (int)$db->query("SELECT COUNT(*) FROM import_queue WHERE status = 'pending'")->fetchColumn();
$qDone    = (int)$db->query("SELECT COUNT(*) FROM import_queue WHERE status = 'done'")->fetchColumn();
$qSkip    = (int)$db->query("SELECT COUNT(*) FROM import_queue WHERE status = 'skip'")->fetchColumn();
$qErr     = (int)$db->query("SELECT COUNT(*) FROM import_queue WHERE status = 'error'")->fetchColumn();
$pct      = $qTotal > 0 ? round(($qDone + $qSkip) / $qTotal * 100) : 0;
$csrf     = csrfToken();

// ── curl-Hilfsfunktion ────────────────────────────────────────────────────────
function _csvCurlBatch(array $urls, int $timeout = 12): array {
    $mh = curl_multi_init();
    $handles = [];
    $caBundle = ini_get('curl.cainfo') ?: '';
    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
                 CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'MKFB/1.0'];
        if ($caBundle && file_exists($caBundle)) $opts[CURLOPT_CAINFO] = $caBundle;
        curl_setopt_array($ch, $opts);
        $handles[$key] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    $running = null;
    do { curl_multi_exec($mh, $running); if ($running > 0) curl_multi_select($mh, 0.3); } while ($running > 0);
    $out = [];
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $out[$key] = $body ? json_decode($body, true) : null;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>CSV Import – Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
    body { background:#f8f9fa; }
    .progress { height:24px; }
    .tbl-result td { font-size:.82rem; padding:.2rem .5rem; }
    .stat-badge { font-size:.9rem; }
    #import-log { max-height:320px; overflow-y:auto; }
    .log-ok   { color:#198754; }
    .log-skip { color:#6c757d; }
    .log-err  { color:#dc3545; }
    </style>
</head>
<body class="p-4">
<div class="container" style="max-width:900px;">
    <h2 class="mb-1">📥 CSV-Import: Filme über IMDB-Nummer</h2>
    <p class="text-muted mb-4">CSV mit IMDB-Nummern (tt1234567) hochladen → TMDB-Daten werden automatisch geladen.</p>

    <?= $uploadMsg ?>

    <!-- ── Phase 1: Upload ────────────────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header fw-bold">1. CSV hochladen</div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end flex-wrap"
                  id="upload-form">
                <input type="hidden" name="action" value="upload">
                <div>
                    <label class="form-label mb-1 small">CSV-Datei (IMDB-Nummern, eine pro Zeile oder in Spalte)</label>
                    <input type="file" name="csvfile" accept=".csv,.txt" class="form-control" required id="csv-file-input">
                </div>
                <button class="btn btn-primary" id="upload-btn" type="submit">Hochladen &amp; Analysieren</button>
            </form>
            <div id="upload-spinner" class="mt-3 d-none">
                <div class="d-flex align-items-center gap-2 text-secondary">
                    <div class="spinner-border spinner-border-sm"></div>
                    <span>CSV wird eingelesen… bitte warten.</span>
                </div>
            </div>
            <p class="text-muted small mt-2 mb-0">
                Format: Eine IMDB-Nummer (tt1234567) pro Zeile. Mehrere Spalten erlaubt – IMDB-Nr wird automatisch erkannt.
                Bereits in der Filmdatenbank vorhandene Filme werden automatisch übersprungen.
            </p>
        </div>
    </div>

    <!-- ── Phase 2: Queue + Import ───────────────────────────────────────── -->
    <?php if ($qTotal > 0): ?>
    <div class="card mb-4" id="queue-card">
        <div class="card-header fw-bold">2. Import-Queue</div>
        <div class="card-body">
            <!-- Badges -->
            <div class="d-flex gap-3 flex-wrap mb-3 stat-badge" id="queue-badges">
                <span class="badge bg-secondary fs-6" id="b-total"><?= number_format($qTotal) ?> gesamt</span>
                <span class="badge bg-warning text-dark fs-6" id="b-pending"><?= number_format($qPending) ?> ausstehend</span>
                <span class="badge bg-success fs-6" id="b-done"><?= number_format($qDone) ?> importiert</span>
                <span class="badge bg-info text-dark fs-6" id="b-skip"><?= number_format($qSkip) ?> übersprungen</span>
                <?php if ($qErr > 0): ?>
                <span class="badge bg-danger fs-6" id="b-err"><?= number_format($qErr) ?> Fehler</span>
                <?php else: ?>
                <span class="badge bg-danger fs-6 d-none" id="b-err">0 Fehler</span>
                <?php endif; ?>
            </div>

            <!-- Fortschrittsbalken -->
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Fortschritt</span>
                <span id="pct-label"><?= $pct ?>% · <?= number_format($qPending) ?> ausstehend</span>
            </div>
            <div class="progress mb-3">
                <div class="progress-bar bg-success" id="prog-bar" style="width:<?= $pct ?>%"><?= $pct ?>%</div>
            </div>

            <!-- Status-Zeile während Import -->
            <div id="import-status" class="alert alert-info py-2 d-none">
                <div class="d-flex align-items-center gap-2">
                    <div class="spinner-border spinner-border-sm"></div>
                    <span id="import-status-text">Import läuft…</span>
                </div>
            </div>
            <div id="import-done-msg" class="alert alert-success py-2 d-none"></div>

            <?php if ($qPending > 0): ?>
            <!-- Steuerung -->
            <div class="d-flex gap-2 flex-wrap align-items-center mb-2" id="import-controls">
                <button class="btn btn-success btn-sm" id="btn-start">
                    <i class="bi bi-play-fill"></i> Import starten
                </button>
                <button class="btn btn-outline-danger btn-sm d-none" id="btn-stop">
                    <i class="bi bi-stop-fill"></i> Stopp
                </button>
                <select class="form-select form-select-sm" id="batch-size" style="width:auto;">
                    <option value="5">5 pro Batch</option>
                    <option value="10" selected>10 pro Batch</option>
                    <option value="15">15 pro Batch</option>
                    <option value="20">20 pro Batch</option>
                </select>
            </div>
            <p class="text-muted small mb-2">
                Parallele Requests pro Batch · Import läuft vollautomatisch bis die Queue leer ist.
            </p>
            <?php else: ?>
            <div class="alert alert-success fw-bold mb-0">🎉 Alle Filme wurden verarbeitet!</div>
            <?php endif; ?>

            <!-- Echtzeit-Log -->
            <div id="import-log-wrap" class="mt-3 d-none">
                <div class="fw-bold small mb-1">Import-Log</div>
                <div id="import-log" class="border rounded p-2 bg-white small font-monospace"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Queue verwalten ────────────────────────────────────────────────── -->
    <?php if ($qTotal > 0): ?>
    <div class="card border-danger mb-4">
        <div class="card-header text-danger fw-bold">Queue verwalten</div>
        <div class="card-body d-flex gap-2 flex-wrap">
            <?php if ($qErr > 0): ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="reset_errors">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <button class="btn btn-outline-warning btn-sm"
                        onclick="return confirm('Fehler-Einträge erneut versuchen?')">
                    ↺ Fehler erneut versuchen (<?= $qErr ?>)
                </button>
            </form>
            <?php endif; ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="clear_queue">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <button class="btn btn-outline-danger btn-sm"
                        onclick="return confirm('Gesamte Queue löschen?')">
                    🗑 Queue leeren
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <p><a href="/admin-filme.php">← Filmverwaltung</a></p>
</div>

<script>
// ── Upload-Spinner ────────────────────────────────────────────────────────────
document.getElementById('upload-form').addEventListener('submit', function() {
    document.getElementById('upload-btn').disabled = true;
    document.getElementById('upload-btn').textContent = 'Wird verarbeitet…';
    document.getElementById('upload-spinner').classList.remove('d-none');
});

// ── AJAX-Import ───────────────────────────────────────────────────────────────
<?php if ($qTotal > 0 && $qPending > 0): ?>
const CSRF = <?= json_encode($csrf) ?>;
let importing = false;
let stopRequested = false;
let totalInserted = 0, totalSkipped = 0, totalErrors = 0;

const btnStart  = document.getElementById('btn-start');
const btnStop   = document.getElementById('btn-stop');
const batchSel  = document.getElementById('batch-size');
const progBar   = document.getElementById('prog-bar');
const pctLabel  = document.getElementById('pct-label');
const statusDiv = document.getElementById('import-status');
const statusTxt = document.getElementById('import-status-text');
const doneMsg   = document.getElementById('import-done-msg');
const logWrap   = document.getElementById('import-log-wrap');
const log       = document.getElementById('import-log');

function updateBadges(q) {
    document.getElementById('b-total').textContent   = q.total.toLocaleString('de') + ' gesamt';
    document.getElementById('b-pending').textContent = q.pending.toLocaleString('de') + ' ausstehend';
    document.getElementById('b-done').textContent    = q.done.toLocaleString('de') + ' importiert';
    document.getElementById('b-skip').textContent    = q.skip.toLocaleString('de') + ' übersprungen';
    const bErr = document.getElementById('b-err');
    bErr.textContent = q.error + ' Fehler';
    bErr.classList.toggle('d-none', q.error === 0);

    const pct = q.total > 0 ? Math.round((q.done + q.skip) / q.total * 100) : 0;
    progBar.style.width = pct + '%';
    progBar.textContent = pct + '%';
    pctLabel.textContent = pct + '% · ' + q.pending.toLocaleString('de') + ' ausstehend';
}

function appendLog(results) {
    results.forEach(r => {
        const line = document.createElement('div');
        line.className = r.ok === true ? 'log-ok' : (r.ok === false ? 'log-err' : 'log-skip');
        line.textContent = r.status + '  ' + r.imdb + (r.title ? '  → ' + r.title + (r.year ? ' (' + r.year + ')' : '') + (r.type === 'tv' ? ' [Serie]' : '') : '');
        log.appendChild(line);
    });
    log.scrollTop = log.scrollHeight;
}

async function runBatch() {
    const fd = new FormData();
    fd.append('action', 'run_batch');
    fd.append('csrf_token', CSRF);
    fd.append('batch', batchSel.value);

    const resp = await fetch('admin-import-csv.php', { method: 'POST', body: fd });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    return await resp.json();
}

async function startImport() {
    if (importing) return;
    importing = true;
    stopRequested = false;

    btnStart.classList.add('d-none');
    btnStop.classList.remove('d-none');
    batchSel.disabled = true;
    statusDiv.classList.remove('d-none');
    logWrap.classList.remove('d-none');
    doneMsg.classList.add('d-none');

    while (!stopRequested) {
        try {
            const data = await runBatch();
            if (!data.ok) { appendLog([{imdb:'–', status:'⚠ Fehler: ' + (data.error || '?'), ok: false}]); break; }

            totalInserted += data.inserted;
            totalSkipped  += data.skipped;
            totalErrors   += data.errors;

            updateBadges(data.queue);
            if (data.results.length) appendLog(data.results);

            statusTxt.textContent = `Import läuft… ${totalInserted} importiert, ${totalSkipped} übersprungen, ${totalErrors} Fehler`;

            if (data.queue.pending === 0) break;   // Queue leer → fertig
        } catch (e) {
            appendLog([{imdb:'–', status:'⚠ Netzwerkfehler: ' + e.message, ok: false}]);
            break;
        }
    }

    // Abschluss
    importing = false;
    btnStart.classList.remove('d-none');
    btnStop.classList.add('d-none');
    batchSel.disabled = false;
    statusDiv.classList.add('d-none');

    const reason = stopRequested ? 'Import gestoppt.' : 'Import abgeschlossen!';
    doneMsg.classList.remove('d-none');
    doneMsg.innerHTML = `<strong>${reason}</strong> ${totalInserted} importiert · ${totalSkipped} übersprungen · ${totalErrors} Fehler`;

    if (!stopRequested) {
        // Steuerungsbereich ausblenden, Erfolgsmeldung zeigen
        document.getElementById('import-controls').classList.add('d-none');
    }
}

btnStart.addEventListener('click', startImport);
btnStop.addEventListener('click', () => { stopRequested = true; btnStop.disabled = true; });
<?php endif; ?>
</script>
</body>
</html>
