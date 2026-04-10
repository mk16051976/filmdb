<?php
/**
 * tierliste.php
 * Filme in Tier-Kategorien einordnen (S/A/B/C/D/F).
 */
$pageTitle = 'Tier-Liste – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── DB-Tabelle ─────────────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS user_tier_ratings (
    user_id  INT          NOT NULL,
    movie_id INT          NOT NULL,
    tier     ENUM('S','A','B','C','D','F') NOT NULL,
    rated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, movie_id),
    INDEX idx_user_tier (user_id, tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$tiers = ['S','A','B','C','D','F'];
$tierColors = [
    'S' => '#f4c430', 'A' => '#4caf50', 'B' => '#2196f3',
    'C' => '#9c27b0', 'D' => '#ff9800', 'F' => '#f44336',
];
$tierLabels = [
    'S' => 'Meisterwerk', 'A' => 'Sehr gut', 'B' => 'Gut',
    'C' => 'Mittelmaß',   'D' => 'Schwach',  'F' => 'Schlecht',
];

// ── AJAX: Tier speichern ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    while (ob_get_level()) ob_end_clean();

    $action  = $_POST['action'] ?? '';
    $movieId = (int)($_POST['movie_id'] ?? 0);

    if ($action === 'rate' && $movieId && csrfValid()) {
        $tier = strtoupper(trim($_POST['tier'] ?? ''));
        if (!in_array($tier, $tiers)) { echo json_encode(['ok'=>false]); exit; }
        $db->prepare("INSERT INTO user_tier_ratings (user_id, movie_id, tier) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE tier=VALUES(tier), rated_at=NOW()")
           ->execute([$userId, $movieId, $tier]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'remove' && $movieId && csrfValid()) {
        $db->prepare("DELETE FROM user_tier_ratings WHERE user_id=? AND movie_id=?")
           ->execute([$userId, $movieId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'next') {
        // Nächsten unbewerteten Film liefern (mit optionalem Filter)
        $genre   = trim($_POST['genre']   ?? '');
        $country = trim($_POST['country'] ?? '');
        $offset  = max(0, (int)($_POST['offset'] ?? 0));

        $where = ["m.id NOT IN (SELECT movie_id FROM user_tier_ratings WHERE user_id = $userId)"];
        $params = [];
        if ($genre)   { $where[] = 'FIND_IN_SET(?, REPLACE(m.genre,   \', \', \',\'))'; $params[] = $genre;   }
        if ($country) { $where[] = 'm.country LIKE ?'; $params[] = '%'.$country.'%'; }
        $wSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = $db->prepare("SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.imdb_id, m.director, m.genre, m.country
                               FROM movies m $wSql ORDER BY m.title ASC LIMIT 1 OFFSET ?");
        $params[] = $offset;
        $stmt->execute($params);
        $film = $stmt->fetch(PDO::FETCH_ASSOC);

        // Gesamtzahl unbewerteter Filme
        $cStmt = $db->prepare("SELECT COUNT(*) FROM movies m $wSql");
        $cStmt->execute(array_slice($params, 0, -1));
        $remaining = (int)$cStmt->fetchColumn();

        if (!$film) { echo json_encode(['film' => null, 'remaining' => 0]); exit; }

        $film['display_poster'] = moviePosterUrl($film, 'w342');
        echo json_encode(['film' => $film, 'remaining' => $remaining, 'offset' => $offset]);
        exit;
    }

    echo json_encode(['ok' => false]);
    exit;
}

// ── Statistiken ────────────────────────────────────────────────────────────────
$totalFilms = (int)$db->query("SELECT COUNT(*) FROM movies")->fetchColumn();
$ratedStmt  = $db->prepare("SELECT COUNT(*) FROM user_tier_ratings WHERE user_id=?");
$ratedStmt->execute([$userId]);
$totalRated = (int)$ratedStmt->fetchColumn();

$tierCounts = [];
$tcStmt = $db->prepare("SELECT tier, COUNT(*) AS cnt FROM user_tier_ratings WHERE user_id=? GROUP BY tier");
$tcStmt->execute([$userId]);
foreach ($tcStmt->fetchAll() as $r) $tierCounts[$r['tier']] = (int)$r['cnt'];

$view = $_GET['view'] ?? 'rate';

// ── Übersicht: Filme pro Tier laden ───────────────────────────────────────────
$tierFilms = [];
if ($view === 'overview') {
    foreach ($tiers as $t) {
        $stmt = $db->prepare(
            "SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.imdb_id
             FROM user_tier_ratings utr
             JOIN movies m ON m.id = utr.movie_id
             WHERE utr.user_id = ? AND utr.tier = ?
             ORDER BY m.title ASC"
        );
        $stmt->execute([$userId, $t]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['display_poster'] = moviePosterUrl($r, 'w92');
        $tierFilms[$t] = $rows;
    }
}

// Distinct-Werte für Filter
$genres    = [];
$countries = [];
$gRows = $db->query("SELECT DISTINCT genre FROM movies WHERE genre != '' AND genre IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
foreach ($gRows as $row) foreach (array_map('trim', explode(',', $row)) as $v) if ($v) $genres[$v] = true;
ksort($genres);
$cRows = $db->query("SELECT DISTINCT country FROM movies WHERE country != '' AND country IS NOT NULL ORDER BY country")->fetchAll(PDO::FETCH_COLUMN);
foreach ($cRows as $c) $countries[$c] = true;

$csrfToken = csrfToken();

require_once __DIR__ . '/includes/header.php';
?>
<style>
:root {
    --tier-s: #f4c430; --tier-a: #4caf50; --tier-b: #2196f3;
    --tier-c: #9c27b0; --tier-d: #ff9800; --tier-f: #f44336;
}

/* ── Layout ── */
.tl-wrap       { max-width: 680px; margin: 0 auto; padding: 1.5rem 1rem; }
.tl-progress   { height: 8px; border-radius: 4px; background: #333; overflow: hidden; margin-bottom: 1.5rem; }
.tl-progress-bar { height: 100%; background: var(--mkfb-gold); transition: width .4s; }

/* ── Film-Karte ── */
.tl-card       { text-align: center; }
.tl-cover      { width: 200px; aspect-ratio: 2/3; object-fit: cover;
                 border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,.5);
                 margin: 0 auto 1rem; display: block; }
.tl-title      { font-size: 1.25rem; font-weight: 700; color: #fff; margin-bottom: .25rem; }
.tl-year       { color: var(--mkfb-gold); font-size: .9rem; margin-bottom: 1.5rem; }
.tl-meta       { font-size: .8rem; color: #aaa; margin-bottom: 1.5rem; }

/* ── Tier-Buttons ── */
.tl-tiers      { display: flex; justify-content: center; gap: .6rem; flex-wrap: wrap; margin-bottom: 1rem; }
.tl-btn        { width: 72px; height: 72px; border-radius: 10px; border: 3px solid transparent;
                 font-size: 1.5rem; font-weight: 900; cursor: pointer; transition: transform .1s, box-shadow .1s;
                 display: flex; flex-direction: column; align-items: center; justify-content: center;
                 line-height: 1; color: #fff; }
.tl-btn:hover  { transform: scale(1.1); box-shadow: 0 4px 16px rgba(0,0,0,.5); }
.tl-btn:active { transform: scale(.95); }
.tl-btn small  { font-size: .55rem; font-weight: 400; margin-top: 3px; opacity: .85; }
.tl-btn.S      { background: var(--tier-s); color: #000; }
.tl-btn.A      { background: var(--tier-a); }
.tl-btn.B      { background: var(--tier-b); }
.tl-btn.C      { background: var(--tier-c); }
.tl-btn.D      { background: var(--tier-d); color: #000; }
.tl-btn.F      { background: var(--tier-f); }
.tl-btn-skip   { background: transparent; border: 2px solid #555; color: #aaa;
                 padding: .35rem .9rem; border-radius: 6px; font-size: .85rem; cursor: pointer; }
.tl-btn-skip:hover { border-color: #888; color: #fff; }

/* ── Keyboard-Hint ── */
.tl-keys       { display: flex; justify-content: center; gap: .6rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.tl-key        { width: 72px; text-align: center; font-size: .7rem; color: #666; }
kbd            { background: #333; border: 1px solid #555; border-radius: 4px;
                 padding: 1px 5px; font-size: .75rem; color: #ccc; }

/* ── Übersicht ── */
.tl-overview-row  { margin-bottom: 1.5rem; }
.tl-tier-label    { display: flex; align-items: center; gap: .6rem;
                    font-size: 1.1rem; font-weight: 700; margin-bottom: .6rem; }
.tl-tier-badge    { width: 42px; height: 42px; border-radius: 6px; display: inline-flex;
                    align-items: center; justify-content: center; font-size: 1.2rem;
                    font-weight: 900; color: #fff; flex-shrink: 0; }
.tl-tier-badge.S  { background: var(--tier-s); color: #000; }
.tl-tier-badge.A  { background: var(--tier-a); }
.tl-tier-badge.B  { background: var(--tier-b); }
.tl-tier-badge.C  { background: var(--tier-c); }
.tl-tier-badge.D  { background: var(--tier-d); color: #000; }
.tl-tier-badge.F  { background: var(--tier-f); }
.tl-tier-films    { display: flex; flex-wrap: wrap; gap: .4rem; }
.tl-ov-cover      { position: relative; cursor: pointer; }
.tl-ov-cover img  { width: 70px; aspect-ratio: 2/3; object-fit: cover; border-radius: 5px;
                    display: block; transition: opacity .15s; }
.tl-ov-cover:hover img { opacity: .6; }
.tl-ov-del        { position: absolute; inset: 0; display: flex; align-items: center;
                    justify-content: center; opacity: 0; transition: opacity .15s;
                    font-size: .75rem; color: #fff; }
.tl-ov-cover:hover .tl-ov-del { opacity: 1; }
.tl-empty         { color: #555; font-style: italic; font-size: .9rem; padding: .5rem 0; }

/* ── Stats ── */
.tl-stats         { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1.5rem; }
.tl-stat          { border-radius: 6px; padding: .3rem .7rem; font-size: .82rem; font-weight: 600; color: #fff; }
.tl-stat.S        { background: var(--tier-s); color: #000; }
.tl-stat.A        { background: var(--tier-a); }
.tl-stat.B        { background: var(--tier-b); }
.tl-stat.C        { background: var(--tier-c); }
.tl-stat.D        { background: var(--tier-d); color: #000; }
.tl-stat.F        { background: var(--tier-f); }
.tl-stat.total    { background: #333; }

/* ── Filter ── */
.tl-filter        { background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
                    padding: .8rem 1rem; margin-bottom: 1.5rem; }
.tl-filter select, .tl-filter input { background: #0d0d1a; border: 1px solid #444;
                    color: #fff; border-radius: 5px; padding: .3rem .6rem; font-size: .85rem; }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="tl-wrap">

    <!-- Tabs -->
    <ul class="nav nav-pills mb-3 gap-1">
        <li class="nav-item">
            <a class="nav-link <?= $view === 'rate'     ? 'active' : '' ?>" href="?view=rate">
                <i class="bi bi-star-fill me-1"></i>Bewerten
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $view === 'overview' ? 'active' : '' ?>" href="?view=overview">
                <i class="bi bi-grid-3x2-gap-fill me-1"></i>Übersicht
            </a>
        </li>
    </ul>

    <!-- Statistiken -->
    <div class="tl-stats">
        <span class="tl-stat total"><?= number_format($totalRated) ?> / <?= number_format($totalFilms) ?> bewertet</span>
        <?php foreach ($tiers as $t): ?>
        <?php if (!empty($tierCounts[$t])): ?>
        <span class="tl-stat <?= $t ?>"><?= $t ?>: <?= $tierCounts[$t] ?></span>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($view === 'rate'): ?>
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <!-- BEWERTEN                                                           -->
    <!-- ══════════════════════════════════════════════════════════════════ -->

    <!-- Filter -->
    <div class="tl-filter d-flex flex-wrap gap-2 align-items-center">
        <label class="text-muted small mb-0">Filter:</label>
        <select id="filterGenre">
            <option value="">Alle Genres</option>
            <?php foreach (array_keys($genres) as $g): ?>
            <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filterCountry">
            <option value="">Alle Länder</option>
            <?php foreach (array_keys($countries) as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-secondary" id="btnResetFilter">Zurücksetzen</button>
    </div>

    <!-- Fortschrittsbalken -->
    <div class="tl-progress">
        <div class="tl-progress-bar" id="progressBar" style="width:<?= $totalFilms > 0 ? round($totalRated/$totalFilms*100) : 0 ?>%"></div>
    </div>
    <p class="text-muted small text-center mb-3" id="progressText">
        <span id="remainingCount">…</span> Filme noch nicht bewertet
    </p>

    <!-- Film-Karte -->
    <div class="tl-card" id="filmCard">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-warning" role="status"></div>
        </div>
        <div id="filmContent" style="display:none">
            <img id="filmCover" src="" alt="" class="tl-cover">
            <div class="tl-title" id="filmTitle"></div>
            <div class="tl-year"  id="filmYear"></div>
            <div class="tl-meta"  id="filmMeta"></div>

            <!-- Tier-Buttons -->
            <div class="tl-tiers">
                <?php foreach ($tiers as $t): ?>
                <button class="tl-btn <?= $t ?>" data-tier="<?= $t ?>" title="<?= $tierLabels[$t] ?>">
                    <?= $t ?>
                    <small><?= $tierLabels[$t] ?></small>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- Tastatur-Hinweis -->
            <div class="tl-keys">
                <?php foreach ($tiers as $t): ?>
                <div class="tl-key"><kbd><?= strtolower($t) ?></kbd></div>
                <?php endforeach; ?>
            </div>

            <button class="tl-btn-skip" id="btnSkip">
                <i class="bi bi-skip-forward me-1"></i>Überspringen
            </button>
        </div>
        <div id="doneMsg" style="display:none" class="text-center py-5">
            <div style="font-size:3rem">🎉</div>
            <h4 class="text-white mt-2">Alle Filme bewertet!</h4>
            <p class="text-muted">Filtere nach Genre oder Land um weitere Filme zu finden.</p>
            <a href="?view=overview" class="btn btn-warning mt-2">Tier-Liste ansehen</a>
        </div>
    </div>

    <?php else: ?>
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <!-- ÜBERSICHT                                                          -->
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <p class="text-muted small">Klicke auf ein Cover um die Tier-Zuordnung zu entfernen.</p>

    <?php foreach ($tiers as $t): ?>
    <div class="tl-overview-row" id="tierRow<?= $t ?>">
        <div class="tl-tier-label">
            <span class="tl-tier-badge <?= $t ?>"><?= $t ?></span>
            <span class="text-white"><?= $tierLabels[$t] ?></span>
            <span class="text-muted small ms-1">(<?= count($tierFilms[$t]) ?>)</span>
        </div>
        <div class="tl-tier-films" id="tierFilms<?= $t ?>">
            <?php if (empty($tierFilms[$t])): ?>
            <span class="tl-empty">Noch keine Filme</span>
            <?php else: ?>
            <?php foreach ($tierFilms[$t] as $film): ?>
            <div class="tl-ov-cover" data-movie-id="<?= $film['id'] ?>"
                 data-tier="<?= $t ?>" title="<?= htmlspecialchars(movieTitle($film)) ?> (<?= $film['year'] ?>)">
                <img src="<?= htmlspecialchars($film['display_poster']) ?>"
                     alt="<?= htmlspecialchars(movieTitle($film)) ?>"
                     loading="lazy">
                <div class="tl-ov-del"><i class="bi bi-x-circle-fill text-danger" style="font-size:1.5rem"></i></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<script>
const CSRF   = <?= json_encode($csrfToken) ?>;
const TIERS  = <?= json_encode($tiers) ?>;
const COLORS = <?= json_encode($tierColors) ?>;

<?php if ($view === 'rate'): ?>
// ── Bewerten ──────────────────────────────────────────────────────────────────
let currentFilm = null;
let currentOffset = 0;
const totalFilms = <?= $totalFilms ?>;
let totalRated   = <?= $totalRated ?>;

function getFilter() {
    return {
        genre:   document.getElementById('filterGenre').value,
        country: document.getElementById('filterCountry').value,
    };
}

async function loadNext(offset = 0) {
    document.getElementById('loadingSpinner').style.display = '';
    document.getElementById('filmContent').style.display    = 'none';
    document.getElementById('doneMsg').style.display        = 'none';

    const f = getFilter();
    const fd = new FormData();
    fd.append('action',  'next');
    fd.append('offset',  offset);
    fd.append('genre',   f.genre);
    fd.append('country', f.country);

    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();

    document.getElementById('loadingSpinner').style.display = 'none';

    if (!data.film) {
        document.getElementById('doneMsg').style.display = '';
        document.getElementById('remainingCount').textContent = '0';
        return;
    }

    currentFilm   = data.film;
    currentOffset = offset;

    document.getElementById('filmCover').src  = data.film.display_poster;
    document.getElementById('filmCover').alt  = data.film.title;
    document.getElementById('filmTitle').textContent = data.film.title_en || data.film.title;
    document.getElementById('filmYear').textContent  = data.film.year || '';
    const meta = [data.film.director, data.film.genre, data.film.country].filter(Boolean).join(' · ');
    document.getElementById('filmMeta').textContent  = meta;
    document.getElementById('remainingCount').textContent = data.remaining.toLocaleString('de');

    // Fortschritt
    const pct = totalFilms > 0 ? Math.round(totalRated / totalFilms * 100) : 0;
    document.getElementById('progressBar').style.width = pct + '%';

    document.getElementById('filmContent').style.display = '';
}

async function rateCurrent(tier) {
    if (!currentFilm) return;
    const fd = new FormData();
    fd.append('action',   'rate');
    fd.append('movie_id', currentFilm.id);
    fd.append('tier',     tier);
    fd.append('csrf_token', CSRF);
    await fetch('', { method: 'POST', body: fd });
    totalRated++;
    loadNext(0);
}

// Tier-Buttons
document.querySelectorAll('.tl-btn[data-tier]').forEach(btn => {
    btn.addEventListener('click', () => rateCurrent(btn.dataset.tier));
});

// Überspringen
document.getElementById('btnSkip').addEventListener('click', () => {
    loadNext(currentOffset + 1);
});

// Filter zurücksetzen
document.getElementById('btnResetFilter').addEventListener('click', () => {
    document.getElementById('filterGenre').value   = '';
    document.getElementById('filterCountry').value = '';
    loadNext(0);
});

// Filter-Änderung
['filterGenre','filterCountry'].forEach(id => {
    document.getElementById(id).addEventListener('change', () => loadNext(0));
});

// Tastatur-Shortcuts (s/a/b/c/d/f)
document.addEventListener('keydown', e => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
    const key = e.key.toUpperCase();
    if (TIERS.includes(key)) rateCurrent(key);
    if (e.key === ' ' || e.key === 'ArrowRight') {
        e.preventDefault();
        loadNext(currentOffset + 1);
    }
});

// Start
loadNext(0);
<?php endif; ?>

<?php if ($view === 'overview'): ?>
// ── Übersicht: Cover entfernen ────────────────────────────────────────────────
document.querySelectorAll('.tl-ov-cover').forEach(el => {
    el.addEventListener('click', async () => {
        const movieId = el.dataset.movieId;
        const tier    = el.dataset.tier;
        if (!confirm('Bewertung entfernen?')) return;

        const fd = new FormData();
        fd.append('action',     'remove');
        fd.append('movie_id',   movieId);
        fd.append('csrf_token', CSRF);
        const res  = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            el.remove();
            // Leere-Hinweis einblenden wenn Tier jetzt leer
            const container = document.getElementById('tierFilms' + tier);
            if (!container.querySelector('.tl-ov-cover')) {
                container.innerHTML = '<span class="tl-empty">Noch keine Filme</span>';
            }
        }
    });
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
