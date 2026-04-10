<?php
$pageTitle = 'Jeder gegen Jeden Komplett';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!isSuperAdmin()) { header('Location: /'); exit; }

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── AJAX: Vote ────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'vote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrfValid()) { echo json_encode(['ok'=>false]); exit; }
    $winnerId = (int)($_POST['winner_id'] ?? 0);
    $loserId  = (int)($_POST['loser_id']  ?? 0);
    if (!$winnerId || !$loserId || $winnerId === $loserId) { echo json_encode(['ok'=>false]); exit; }

    recordComparison($userId, $winnerId, $loserId);

    // Next unevaluated pair
    $next = getNextJgjPair($db, $userId);
    $remaining = (int)$db->query("SELECT COUNT(*) FROM jgj_complete_pairs WHERE user_id=$userId AND winner_id IS NULL")->fetchColumn();

    echo json_encode(['ok'=>true, 'next'=>$next, 'remaining'=>$remaining]);
    exit;
}

// ── AJAX: Skip ────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'next') {
    header('Content-Type: application/json; charset=utf-8');
    $after = (int)($_GET['after_a'] ?? 0);
    $next  = getNextJgjPair($db, $userId, $after);
    echo json_encode($next);
    exit;
}

function getNextJgjPair(PDO $db, int $userId, int $afterFilmA = 0): ?array {
    // Pick the first unevaluated pair (optionally after a given film_a_id for skipping)
    $stmt = $db->prepare("
        SELECT p.film_a_id, p.film_b_id,
               ma.title AS a_title, ma.title_en AS a_title_en, ma.year AS a_year,
               ma.poster_path AS a_poster, ma.poster_path_en AS a_poster_en, ma.imdb_id AS a_imdb,
               mb.title AS b_title, mb.title_en AS b_title_en, mb.year AS b_year,
               mb.poster_path AS b_poster, mb.poster_path_en AS b_poster_en, mb.imdb_id AS b_imdb
        FROM jgj_complete_pairs p
        JOIN movies ma ON ma.id = p.film_a_id
        JOIN movies mb ON mb.id = p.film_b_id
        WHERE p.user_id = ? AND p.winner_id IS NULL
          AND p.film_a_id > ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $afterFilmA]);
    $r = $stmt->fetch();
    if (!$r && $afterFilmA > 0) {
        // Wrap around to beginning
        $stmt->execute([$userId, 0]);
        $r = $stmt->fetch();
    }
    if (!$r) return null;

    return [
        'a' => [
            'id'     => (int)$r['film_a_id'],
            'title'  => movieTitle(['title'=>$r['a_title'],'title_en'=>$r['a_title_en']]),
            'year'   => (int)$r['a_year'],
            'poster' => moviePosterUrl(['poster_path'=>$r['a_poster'],'poster_path_en'=>$r['a_poster_en']], 'w500'),
        ],
        'b' => [
            'id'     => (int)$r['film_b_id'],
            'title'  => movieTitle(['title'=>$r['b_title'],'title_en'=>$r['b_title_en']]),
            'year'   => (int)$r['b_year'],
            'poster' => moviePosterUrl(['poster_path'=>$r['b_poster'],'poster_path_en'=>$r['b_poster_en']], 'w500'),
        ],
    ];
}

// ── Stats + first pair ────────────────────────────────────────────────────────
$totalPairs  = (int)$db->query("SELECT COUNT(*) FROM jgj_complete_pairs WHERE user_id=$userId")->fetchColumn();
$evaluated   = (int)$db->query("SELECT COUNT(*) FROM jgj_complete_pairs WHERE user_id=$userId AND winner_id IS NOT NULL")->fetchColumn();
$remaining   = $totalPairs - $evaluated;
$pct         = $totalPairs > 0 ? round($evaluated / $totalPairs * 100, 1) : 0;
$currentPair = getNextJgjPair($db, $userId);

require_once __DIR__ . '/includes/header.php';
?>
<style>
.jgj-arena { display:flex; align-items:stretch; justify-content:center; gap:1.5rem; max-width:900px; margin:0 auto; padding:1rem 0; }
.jgj-card {
    flex:1; max-width:380px; background:rgba(255,255,255,.04);
    border:2px solid rgba(255,255,255,.08); border-radius:14px;
    cursor:pointer; transition:border-color .15s, transform .1s, background .15s;
    overflow:hidden;
}
.jgj-card:hover { border-color:var(--mkfb-gold); background:rgba(232,184,75,.06); transform:translateY(-2px); }
.jgj-card.chosen { border-color:var(--mkfb-gold); background:rgba(232,184,75,.12); }
.jgj-poster { width:100%; aspect-ratio:2/3; object-fit:cover; display:block; }
.jgj-info { padding:.75rem 1rem; text-align:center; }
.jgj-title { font-weight:700; font-size:.95rem; line-height:1.3; }
.jgj-year  { font-size:.78rem; color:rgba(255,255,255,.4); margin-top:.2rem; }
.jgj-vs { display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.jgj-vs-circle { width:52px; height:52px; background:rgba(232,184,75,.15); border:2px solid rgba(232,184,75,.3); border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; color:var(--mkfb-gold); font-size:1rem; }
.jgj-progress { max-width:900px; margin:0 auto 1rem; }
</style>

<main class="container py-4">

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-grid-3x3 fs-3" style="color:var(--mkfb-gold);"></i>
        <h1 class="h4 mb-0 fw-bold">Jeder gegen Jeden Komplett</h1>
    </div>
    <div class="d-flex align-items-center gap-3">
        <span class="small opacity-60" id="remaining-label"><?= number_format($remaining) ?> offen</span>
        <a href="/jgj-complete-rangliste.php" class="btn btn-sm btn-outline-light">
            <i class="bi bi-trophy me-1"></i>Rangliste
        </a>
    </div>
</div>

<!-- Progress -->
<div class="jgj-progress">
    <div class="d-flex justify-content-between small mb-1 opacity-50">
        <span><?= number_format($evaluated) ?> bewertet</span>
        <span id="pct-label"><?= $pct ?>%</span>
    </div>
    <div style="background:rgba(255,255,255,.08);border-radius:6px;height:6px;overflow:hidden;">
        <div id="progress-bar" style="background:var(--mkfb-gold);height:100%;border-radius:6px;width:<?= $pct ?>%;transition:width .3s;"></div>
    </div>
</div>

<?php if (!$currentPair): ?>
<div class="text-center py-5 opacity-60">
    <?php if ($totalPairs === 0): ?>
        <i class="bi bi-exclamation-circle fs-1 d-block mb-3"></i>
        <p>Kein Spielplan vorhanden. <a href="/jgj-complete-build.php">Spielplan aufbauen →</a></p>
    <?php else: ?>
        <i class="bi bi-trophy-fill fs-1 d-block mb-3" style="color:var(--mkfb-gold);"></i>
        <p class="fw-bold">Alle <?= number_format($totalPairs) ?> Duelle bewertet!</p>
    <?php endif; ?>
</div>
<?php else: ?>

<div class="text-center mb-3 fs-5 fw-bold opacity-75">Welchen Film schaust du dir lieber an?</div>

<div class="jgj-arena" id="arena">
    <div class="jgj-card" id="card-a" onclick="vote(<?= $currentPair['a']['id'] ?>, <?= $currentPair['b']['id'] ?>)">
        <img class="jgj-poster" src="<?= e($currentPair['a']['poster']) ?>"
             onerror="this.onerror=null;this.src='/img/no-poster.svg'" alt="">
        <div class="jgj-info">
            <div class="jgj-title"><?= e($currentPair['a']['title']) ?></div>
            <div class="jgj-year"><?= $currentPair['a']['year'] ?></div>
        </div>
    </div>

    <div class="jgj-vs"><div class="jgj-vs-circle">VS</div></div>

    <div class="jgj-card" id="card-b" onclick="vote(<?= $currentPair['b']['id'] ?>, <?= $currentPair['a']['id'] ?>)">
        <img class="jgj-poster" src="<?= e($currentPair['b']['poster']) ?>"
             onerror="this.onerror=null;this.src='/img/no-poster.svg'" alt="">
        <div class="jgj-info">
            <div class="jgj-title"><?= e($currentPair['b']['title']) ?></div>
            <div class="jgj-year"><?= $currentPair['b']['year'] ?></div>
        </div>
    </div>
</div>

<?php endif; ?>
</main>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
let voting  = false;
let totalPairs = <?= $totalPairs ?>;
let evaluated  = <?= $evaluated ?>;

function vote(winnerId, loserId) {
    if (voting) return;
    voting = true;

    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('winner_id',  winnerId);
    fd.append('loser_id',   loserId);

    fetch('?action=vote', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { voting = false; return; }
            evaluated++;
            const remaining = d.remaining;
            const pct = totalPairs > 0 ? (evaluated / totalPairs * 100).toFixed(1) : 0;
            document.getElementById('remaining-label').textContent = remaining.toLocaleString('de') + ' offen';
            document.getElementById('pct-label').textContent       = pct + '%';
            document.getElementById('progress-bar').style.width    = pct + '%';

            if (!d.next) {
                document.getElementById('arena').innerHTML =
                    '<div class="text-center py-5 w-100"><i class="bi bi-trophy-fill fs-1 d-block mb-3" style="color:var(--mkfb-gold);"></i><p class="fw-bold">Alle Duelle bewertet!</p></div>';
                voting = false;
                return;
            }
            renderPair(d.next);
            voting = false;
        })
        .catch(() => { voting = false; });
}

function renderPair(pair) {
    const a = pair.a, b = pair.b;
    document.getElementById('card-a').outerHTML =
        `<div class="jgj-card" id="card-a" onclick="vote(${a.id},${b.id})">
            <img class="jgj-poster" src="${esc(a.poster)}" onerror="this.onerror=null;this.src='/img/no-poster.svg'" alt="">
            <div class="jgj-info"><div class="jgj-title">${esc(a.title)}</div><div class="jgj-year">${a.year}</div></div>
        </div>`;
    document.getElementById('card-b').outerHTML =
        `<div class="jgj-card" id="card-b" onclick="vote(${b.id},${a.id})">
            <img class="jgj-poster" src="${esc(b.poster)}" onerror="this.onerror=null;this.src='/img/no-poster.svg'" alt="">
            <div class="jgj-info"><div class="jgj-title">${esc(b.title)}</div><div class="jgj-year">${b.year}</div></div>
        </div>`;
    // Re-bind cards (outerHTML replaces element)
    document.getElementById('card-a').onclick = () => vote(a.id, b.id);
    document.getElementById('card-b').onclick = () => vote(b.id, a.id);
}

// Keyboard: ArrowLeft = A, ArrowRight = B
document.addEventListener('keydown', e => {
    if (voting) return;
    const ca = document.getElementById('card-a');
    const cb = document.getElementById('card-b');
    if (!ca || !cb) return;
    if (e.key === 'ArrowLeft')  { e.preventDefault(); ca.click(); }
    if (e.key === 'ArrowRight') { e.preventDefault(); cb.click(); }
});

function esc(s) {
    return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
