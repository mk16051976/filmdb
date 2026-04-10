<?php
$pageTitle = 'JgJ Komplett – Spielplan aufbauen';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!isSuperAdmin()) { header('Location: /'); exit; }

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── AJAX: Build chunk ─────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'build_chunk') {
    header('Content-Type: application/json; charset=utf-8');

    $fromId = (int)($_GET['from_id'] ?? 0);
    $limit  = 50; // films per chunk

    // Get next 50 film IDs starting at from_id
    $stmt = $db->prepare("SELECT id FROM movies WHERE id > ? ORDER BY id ASC LIMIT ?");
    $stmt->execute([$fromId, $limit]);
    $filmIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($filmIds)) {
        echo json_encode(['done' => true]);
        exit;
    }

    // For each film_a in this chunk, insert all pairs with film_b where film_b > film_a
    foreach ($filmIds as $filmA) {
        $stmt = $db->prepare("
            INSERT IGNORE INTO jgj_complete_pairs (user_id, film_a_id, film_b_id)
            SELECT ?, ?, id FROM movies WHERE id > ?
        ");
        $stmt->execute([$userId, $filmA, $filmA]);
    }

    $nextId    = (int)end($filmIds);
    $totalPairs = (int)$db->prepare("SELECT COUNT(*) FROM jgj_complete_pairs WHERE user_id=?")
                           ->execute([$userId]) ? $db->query("SELECT COUNT(*) FROM jgj_complete_pairs WHERE user_id=$userId")->fetchColumn() : 0;
    $totalMovies = (int)$db->query("SELECT COUNT(*) FROM movies")->fetchColumn();
    $pct = $totalMovies > 0 ? round($nextId / $totalMovies * 100, 1) : 0;

    echo json_encode([
        'done'        => false,
        'next_id'     => $nextId,
        'total_pairs' => $totalPairs,
        'pct'         => $pct,
    ]);
    exit;
}

// ── AJAX: Stats ───────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'stats') {
    header('Content-Type: application/json; charset=utf-8');
    $totalMovies = (int)$db->query("SELECT COUNT(*) FROM movies")->fetchColumn();
    $totalPairs  = (int)$db->query("SELECT COUNT(*) FROM jgj_complete_pairs WHERE user_id=$userId")->fetchColumn();
    $evaluated   = (int)$db->query("SELECT COUNT(*) FROM jgj_complete_pairs WHERE user_id=$userId AND winner_id IS NOT NULL")->fetchColumn();
    $expected    = $totalMovies * ($totalMovies - 1) / 2;
    echo json_encode([
        'total_movies' => $totalMovies,
        'total_pairs'  => $totalPairs,
        'expected'     => $expected,
        'evaluated'    => $evaluated,
        'missing'      => max(0, $expected - $totalPairs),
    ]);
    exit;
}

require_once __DIR__ . '/includes/header.php';
$totalMovies = (int)$db->query("SELECT COUNT(*) FROM movies")->fetchColumn();
$totalPairs  = (int)$db->query("SELECT COUNT(*) FROM jgj_complete_pairs WHERE user_id=$userId")->fetchColumn();
$evaluated   = (int)$db->query("SELECT COUNT(*) FROM jgj_complete_pairs WHERE user_id=$userId AND winner_id IS NOT NULL")->fetchColumn();
$expected    = $totalMovies * ($totalMovies - 1) / 2;
?>
<main class="container py-4" style="max-width:700px;">

<div class="d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-grid-3x3 fs-3" style="color:var(--mkfb-gold);"></i>
    <h1 class="h3 mb-0 fw-bold">JgJ Komplett – Spielplan</h1>
</div>

<div class="card mb-4" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:10px;">
    <div class="card-body p-4">
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3 text-center">
                <div class="fw-bold fs-4" style="color:var(--mkfb-gold);"><?= number_format($totalMovies) ?></div>
                <div class="small opacity-50">Filme in DB</div>
            </div>
            <div class="col-6 col-md-3 text-center">
                <div class="fw-bold fs-4" style="color:var(--mkfb-gold);" id="stat-expected"><?= number_format($expected) ?></div>
                <div class="small opacity-50">Paare gesamt</div>
            </div>
            <div class="col-6 col-md-3 text-center">
                <div class="fw-bold fs-4 text-success" id="stat-built"><?= number_format($totalPairs) ?></div>
                <div class="small opacity-50">Angelegt</div>
            </div>
            <div class="col-6 col-md-3 text-center">
                <div class="fw-bold fs-4" style="color:#3498db;" id="stat-evaluated"><?= number_format($evaluated) ?></div>
                <div class="small opacity-50">Bewertet</div>
            </div>
        </div>

        <div class="mb-3">
            <div class="d-flex justify-content-between small mb-1">
                <span>Spielplan Fortschritt</span>
                <span id="build-pct"><?= $totalPairs > 0 ? round($totalPairs / $expected * 100, 1) : 0 ?>%</span>
            </div>
            <div style="background:rgba(255,255,255,.1);border-radius:6px;height:10px;overflow:hidden;">
                <div id="build-bar" style="background:var(--mkfb-gold);height:100%;border-radius:6px;transition:width .3s;width:<?= $totalPairs > 0 ? round($totalPairs / $expected * 100, 1) : 0 ?>%;"></div>
            </div>
        </div>

        <div id="build-status" class="small mb-3 text-muted"></div>

        <div class="d-flex gap-2 flex-wrap">
            <button id="btn-build" class="btn btn-gold" onclick="startBuild()">
                <i class="bi bi-play-fill me-1"></i>
                <?= $totalPairs === 0 ? 'Spielplan aufbauen' : ($totalPairs < $expected ? 'Fortsetzen / Aktualisieren' : 'Aktualisieren (neue Filme)') ?>
            </button>
            <button id="btn-stop" class="btn btn-outline-secondary d-none" onclick="stopBuild()">
                <i class="bi bi-stop-fill me-1"></i>Stoppen
            </button>
            <?php if ($totalPairs > 0): ?>
            <a href="/jgj-complete.php" class="btn btn-outline-light">
                <i class="bi bi-play-circle me-1"></i>Bewerten starten
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.07);border-radius:10px;">
    <div class="card-body p-4">
        <h6 class="fw-bold mb-2" style="color:var(--mkfb-gold);">Hinweise</h6>
        <ul class="small opacity-75 mb-0">
            <li>Der Aufbau kann bei ~<?= number_format($totalMovies) ?> Filmen mehrere Minuten dauern.</li>
            <li>Du kannst den Aufbau jederzeit stoppen und später fortsetzen.</li>
            <li>Bereits bewertete Paare werden beim Aktualisieren <strong>nicht überschrieben</strong>.</li>
            <li>Neue Filme in der DB werden automatisch mit <em>Aktualisieren</em> ergänzt.</li>
        </ul>
    </div>
</div>

</main>

<script>
let building = false;
let nextId   = 0;

function startBuild() {
    building = true;
    nextId   = 0;
    document.getElementById('btn-build').classList.add('d-none');
    document.getElementById('btn-stop').classList.remove('d-none');
    buildChunk();
}

function stopBuild() {
    building = false;
    document.getElementById('btn-build').classList.remove('d-none');
    document.getElementById('btn-stop').classList.add('d-none');
    document.getElementById('build-status').textContent = 'Gestoppt.';
}

function buildChunk() {
    if (!building) return;
    document.getElementById('build-status').textContent = `Verarbeite ab Film-ID ${nextId} …`;

    fetch(`?action=build_chunk&from_id=${nextId}`)
        .then(r => r.json())
        .then(d => {
            if (d.done) {
                building = false;
                document.getElementById('btn-build').classList.remove('d-none');
                document.getElementById('btn-stop').classList.add('d-none');
                document.getElementById('build-status').textContent = '✓ Spielplan vollständig aufgebaut!';
                document.getElementById('btn-build').innerHTML = '<i class="bi bi-check-circle me-1"></i>Aktualisieren (neue Filme)';
                refreshStats();
                return;
            }
            nextId = d.next_id;
            document.getElementById('stat-built').textContent = d.total_pairs.toLocaleString('de');
            document.getElementById('build-pct').textContent  = d.pct + '%';
            document.getElementById('build-bar').style.width  = d.pct + '%';
            setTimeout(buildChunk, 10);
        })
        .catch(() => {
            building = false;
            document.getElementById('build-status').textContent = 'Fehler – bitte Seite neu laden.';
            document.getElementById('btn-build').classList.remove('d-none');
            document.getElementById('btn-stop').classList.add('d-none');
        });
}

function refreshStats() {
    fetch('?action=stats').then(r=>r.json()).then(d => {
        document.getElementById('stat-expected').textContent   = d.expected.toLocaleString('de');
        document.getElementById('stat-built').textContent      = d.total_pairs.toLocaleString('de');
        document.getElementById('stat-evaluated').textContent  = d.evaluated.toLocaleString('de');
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
