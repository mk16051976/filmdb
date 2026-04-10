<?php
$pageTitle = 'Meine Rangliste – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$userId = (int)$_SESSION['user_id'];
$db     = getDB();

// ── AJAX: Film zu JgJ-Pool hinzufügen ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_jgj_film') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }
    $filmId = (int)($_POST['film_id'] ?? 0);
    if (!$filmId) { echo json_encode(['ok' => false, 'error' => 'invalid']); exit; }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS jgj_pool (
            user_id  INT UNSIGNED NOT NULL,
            movie_id INT UNSIGNED NOT NULL,
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, movie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->prepare("INSERT IGNORE INTO jgj_pool (user_id, movie_id) VALUES (?, ?)")
           ->execute([$userId, $filmId]);
        $ps = $db->prepare("SELECT COUNT(*) FROM jgj_pool WHERE user_id = ?");
        $ps->execute([$userId]);
        echo json_encode(['ok' => true, 'pool_size' => (int)$ps->fetchColumn()]);
    } catch (\PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Ensure table exists before querying
$db->exec("CREATE TABLE IF NOT EXISTS user_position_ranking (
    user_id  INT NOT NULL,
    movie_id INT NOT NULL,
    position INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, movie_id),
    INDEX idx_user_pos (user_id, position)
)");

$stmt = $db->prepare("
    SELECT upr.position,
           m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director,
           COALESCE(ur.elo, 1500)        AS elo,
           COALESCE(ur.wins, 0)          AS wins,
           COALESCE(ur.losses, 0)        AS losses,
           COALESCE(ur.comparisons, 0)   AS comparisons
    FROM user_position_ranking upr
    JOIN movies m ON m.id = upr.movie_id
    LEFT JOIN user_ratings ur ON ur.movie_id = m.id AND ur.user_id = upr.user_id
    WHERE upr.user_id = ?
    ORDER BY upr.position ASC
");
$stmt->execute([$userId]);
$ranking = $stmt->fetchAll();

$totalFilms = count($ranking);

// ── Liga-Daten (Tab 2) ───────────────────────────────────────────────────────
$ligaRanking  = [];
$latestLigaId = null;
$stmt = $db->prepare("SELECT id FROM liga_sessions WHERE user_id = ? AND status = 'completed' ORDER BY created_at DESC LIMIT 1");

$stmt->execute([$userId]);
if ($ligaRow = $stmt->fetch()) {
    $latestLigaId = (int)$ligaRow['id'];
    $stmt2 = $db->prepare("
        SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director,
               SUM(a.winner_id = a.movie_id)                              AS liga_wins,
               SUM(a.winner_id IS NOT NULL AND a.winner_id != a.movie_id) AS liga_losses,
               SUM(a.winner_id IS NOT NULL)                               AS matches_played
        FROM (
            SELECT movie_a_id AS movie_id, winner_id FROM liga_matches WHERE liga_id = ?
            UNION ALL
            SELECT movie_b_id AS movie_id, winner_id FROM liga_matches WHERE liga_id = ?
        ) a
        JOIN movies m ON m.id = a.movie_id
        GROUP BY m.id
        ORDER BY liga_wins DESC, liga_losses ASC
    ");
    $stmt2->execute([$latestLigaId, $latestLigaId]);
    $ligaRanking = $stmt2->fetchAll();
}

// ── Sortier-Daten (Tab 3) ────────────────────────────────────────────────────
$sortRankingFilms = [];
$latestSortId     = null;
$latestSortDate   = null;

// Bootstrap sort_sessions table (created by sortieren.php, may not exist yet)
$db->exec("CREATE TABLE IF NOT EXISTS sort_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    film_count SMALLINT UNSIGNED NOT NULL,
    status     ENUM('active','completed') NOT NULL DEFAULT 'active',
    state      JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$stmt = $db->prepare("SELECT id, film_count, state, created_at FROM sort_sessions
    WHERE user_id = ? AND status = 'completed' ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$userId]);
if ($sortRow = $stmt->fetch()) {
    $latestSortId   = (int)$sortRow['id'];
    $latestSortDate = $sortRow['created_at'];
    $sortState      = json_decode($sortRow['state'], true);
    $sortedIds = $sortState['sorted'] ?? $sortState['pending'][0] ?? [];
    if ($sortedIds) {
        $ph    = implode(',', array_fill(0, count($sortedIds), '?'));
        $stmt2 = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, imdb_id, director FROM movies WHERE id IN ($ph)");
        $stmt2->execute($sortedIds);
        $movieMap = array_column($stmt2->fetchAll(), null, 'id');
        foreach ($sortedIds as $pos => $id) {
            if (isset($movieMap[$id])) {
                $sortRankingFilms[] = array_merge($movieMap[$id], ['sort_pos' => $pos + 1]);
            }
        }
    }
}

// Sort-Position-Map für schnellen Lookup (movie_id → sort_pos)
$sortedPosMap = [];
foreach ($sortRankingFilms as $sf) {
    $sortedPosMap[(int)$sf['id']] = (int)$sf['sort_pos'];
}
$hasCompletedSort = $latestSortId !== null;

// ── JgJ-Pool-Daten ───────────────────────────────────────────────────────────
$jgjPoolSet = []; // movie_id → true
$jgjRankMap = []; // movie_id → Rang (1-basiert)
try {
    $stmt = $db->prepare("
        SELECT p.movie_id,
               RANK() OVER (
                   ORDER BY COALESCE(SUM(CASE WHEN r.winner_id = p.movie_id THEN 1 ELSE 0 END), 0) DESC,
                            COALESCE(SUM(CASE WHEN r.id IS NOT NULL AND r.winner_id != p.movie_id THEN 1 ELSE 0 END), 0) ASC
               ) AS jgj_rank
        FROM jgj_pool p
        LEFT JOIN jgj_results r ON r.user_id = p.user_id
            AND (r.movie_a_id = p.movie_id OR r.movie_b_id = p.movie_id)
        WHERE p.user_id = ?
        GROUP BY p.movie_id");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $mid = (int)$row['movie_id'];
        $jgjPoolSet[$mid] = true;
        $jgjRankMap[$mid] = (int)$row['jgj_rank'];
    }
} catch (\PDOException $e) { /* jgj_pool noch nicht vorhanden */ }

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body { background: #14325a !important; }
    .rangliste-row:hover { background: rgba(232,184,75,.07) !important; }
    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 3px; }
    ::-webkit-scrollbar-thumb:hover { background: rgba(232,184,75,.4); }
    * { scrollbar-width: thin; scrollbar-color: rgba(232,184,75,.2) transparent; }
    .rl-tabs { border-bottom: 1px solid rgba(255,255,255,.1); }
    .rl-tab { background: none; border: none; color: rgba(255,255,255,.45); font-size: .9rem; font-weight: 500; padding: 10px 20px; cursor: pointer; position: relative; transition: color .2s; }
    .rl-tab:hover { color: rgba(255,255,255,.75); }
    .rl-tab.active { color: #e8b84b; }
    .rl-tab.active::after { content: ''; position: absolute; bottom: -1px; left: 0; right: 0; height: 2px; background: #e8b84b; border-radius: 2px 2px 0 0; }

    /* Aktivierungs-UI */
    .sort-tag {
        display: inline-flex; align-items: center; gap: 4px;
        background: rgba(232,184,75,.15); border: 1px solid rgba(232,184,75,.35);
        color: #e8b84b; font-size: .72rem; font-weight: 700;
        padding: 3px 8px; border-radius: 20px; white-space: nowrap; flex-shrink: 0;
    }
    .activate-chip {
        width: 30px; height: 30px; border-radius: 50%;
        border: 1.5px solid rgba(255,255,255,.2); background: transparent;
        color: rgba(255,255,255,.4); font-size: 1.1rem; line-height: 1;
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        transition: all .18s; flex-shrink: 0;
    }
    .activate-chip:hover { border-color: #e8b84b; color: #e8b84b; background: rgba(232,184,75,.1); }
    .activate-chip.selected {
        border-color: #e8b84b; background: #e8b84b; color: #1a1a1a;
    }
    #activate-bar {
        position: fixed; bottom: 0; left: 0; right: 0; z-index: 1000;
        background: linear-gradient(135deg, #1e3d7a, #14325a);
        border-top: 1px solid rgba(232,184,75,.4);
        padding: 14px 20px;
        display: flex; align-items: center; justify-content: space-between; gap: 16px;
        box-shadow: 0 -4px 24px rgba(0,0,0,.5);
    }
    .activate-bar-info { color: rgba(255,255,255,.7); font-size: .9rem; }
    .activate-bar-info strong { color: #e8b84b; }
    .btn-activate-submit {
        background: linear-gradient(135deg,#e8b84b,#c4942a); color: #1a1a1a;
        font-weight: 700; border: none; border-radius: 8px; padding: 10px 24px;
        cursor: pointer; white-space: nowrap; font-size: .9rem;
    }
    .btn-activate-submit:disabled { opacity: .5; cursor: not-allowed; }
    .btn-activate-cancel {
        background: transparent; border: 1px solid rgba(255,255,255,.15);
        color: rgba(255,255,255,.5); border-radius: 8px; padding: 10px 16px;
        cursor: pointer; font-size: .85rem;
    }
    .btn-activate-cancel:hover { border-color: rgba(255,255,255,.35); color: rgba(255,255,255,.75); }
    .activate-chip:disabled { opacity: .4; cursor: not-allowed; }
    a.sort-tag { text-decoration: none; }
    a.sort-tag:hover { background: rgba(232,184,75,.3); color: #e8b84b; }
</style>

<main style="padding-top:6px; background:#14325a; flex:1;">

    <section class="py-4" style="background:linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom:1px solid rgba(232,184,75,.15);">
        <div class="container">
            <div class="row align-items-center mb-3">
                <div class="col">
                    <h1 class="fw-bold mb-1" style="color:#e8b84b;">
                        <i class="bi bi-trophy-fill me-2"></i>Meine Ranglisten
                    </h1>
                    <p class="mb-0" style="color:rgba(255,255,255,.55);">Deine persönliche Filmrangliste – geformt durch jedes Duell</p>
                </div>
            </div>
            <div class="rl-tabs d-flex">
                <button class="rl-tab active" id="tab-btn-rangliste" onclick="switchTab('rangliste')">
                    <i class="bi bi-list-ol me-1"></i>Meine Rangliste
                    <span style="color:rgba(255,255,255,.3); margin-left:6px; font-size:.8rem;"><?= $totalFilms ?></span>
                </button>
                <?php if ($latestLigaId): ?>
                <button class="rl-tab" id="tab-btn-jgj" onclick="switchTab('jgj')">
                    <i class="bi bi-people-fill me-1"></i>Jeder gegen Jeden
                    <span style="color:rgba(255,255,255,.3); margin-left:6px; font-size:.8rem;"><?= count($ligaRanking) ?></span>
                </button>
                <?php endif; ?>
                <?php if ($latestSortId): ?>
                <button class="rl-tab" id="tab-btn-sort" onclick="switchTab('sort')">
                    <i class="bi bi-sort-numeric-down me-1"></i>Sortieren
                    <span style="color:rgba(255,255,255,.3); margin-left:6px; font-size:.8rem;"><?= count($sortRankingFilms) ?></span>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ── TAB 1: Meine Rangliste ────────────────────────────────────────── -->
    <section id="tab-rangliste" class="py-4" style="background:#14325a;">
        <div class="container">

            <?php if ($totalFilms === 0): ?>
            <div class="text-center py-5">
                <i class="bi bi-collection-play" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Noch keine Duelle durchgeführt</h4>
                <p class="mb-4" style="color:rgba(255,255,255,.4);">Vergleiche Filme im Turnier oder per Duell, um deine Rangliste aufzubauen.</p>
                <a href="/turnier.php" class="btn btn-gold me-2">Zum Turnier</a>
            </div>

            <?php else: ?>

            <?php if ($totalFilms > 0): ?>
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <p class="mb-0" style="color:rgba(255,255,255,.4); font-size:.82rem;">
                    <i class="bi bi-sort-numeric-down me-1" style="color:#e8b84b;"></i>
                    <strong style="color:#e8b84b;"><?= count($sortedPosMap) ?></strong> von <?= $totalFilms ?> Filmen sind sortiert.
                    <?php if ($hasCompletedSort): ?>
                    Klicke <i class="bi bi-plus"></i> bei weiteren Filmen, um sie einzuordnen.
                    <?php else: ?>
                    Wähle Filme aus und klicke <strong>Einordnen</strong>, um die Sortierung zu starten.
                    <?php endif; ?>
                </p>
                <?php if ($hasCompletedSort): ?>
                <a href="/sortieren.php" class="btn btn-sm"
                   style="border:1px solid rgba(232,184,75,.3); color:#e8b84b; font-size:.8rem; background:rgba(232,184,75,.06);">
                    <i class="bi bi-sort-numeric-down me-1"></i>Zur Sortierung
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                <?php foreach ($ranking as $i => $film): ?>
                <?php
                    $pos      = (int)$film['position'];
                    $medals   = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                    $posColor = $pos === 1 ? '#e8b84b' : ($pos === 2 ? '#b0b0b0' : ($pos === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                    $poster   = moviePosterUrl($film, 'w92');
                    $rowBg    = $i % 2 === 0 ? 'rgba(255,255,255,.025)' : '#14325a';
                ?>
                <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                     style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">

                    <div class="text-center flex-shrink-0" style="width:36px;">
                        <?php if (isset($medals[$pos])): ?>
                            <span style="font-size:1.3rem; line-height:1;"><?= $medals[$pos] ?></span>
                        <?php else: ?>
                            <span style="color:<?= $posColor ?>; font-size:.95rem; font-weight:700;"><?= $pos ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="flex-shrink-0" style="width:46px; height:69px; border-radius:5px; overflow:hidden; background:#1e3d7a;">
                        <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                             style="width:100%; height:100%; object-fit:cover;"
                             loading="lazy" decoding="async" onerror="this.src='https://placehold.co/46x69/1e3a5f/e8b84b?text=?'">
                    </div>

                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.95rem;"><a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a></div>
                        <div style="color:rgba(255,255,255,.4); font-size:.8rem;">
                            <?= $film['year'] ?>
                            <?php if (!empty($film['director'])): ?>&middot; <?= e($film['director']) ?><?php endif; ?>
                        </div>
                    </div>

                    <?php
                        $filmId  = (int)$film['id'];
                        $sortPos = $sortedPosMap[$filmId] ?? null;
                        $inPool  = isset($jgjPoolSet[$filmId]);
                        $jgjRank = $jgjRankMap[$filmId] ?? null;
                    ?>
                    <div class="flex-shrink-0 d-flex align-items-center gap-1">
                        <?php if ($sortPos !== null): ?>
                        <span class="sort-tag" title="Sortiert auf Platz <?= $sortPos ?>">
                            <i class="bi bi-sort-numeric-down" style="font-size:.7rem;"></i>#<?= $sortPos ?>
                        </span>
                        <?php else: ?>
                        <button class="activate-chip" data-id="<?= $filmId ?>"
                                title="Zur Sortierung hinzufügen" onclick="toggleActivate(this)">
                            <i class="bi bi-plus" style="pointer-events:none;"></i>
                        </button>
                        <?php endif; ?>

                        <?php if ($inPool): ?>
                        <a href="/jgj.php" class="sort-tag" title="Jeder-gegen-Jeden Rang <?= $jgjRank ?>">
                            <i class="bi bi-people-fill" style="font-size:.7rem;"></i>#<?= $jgjRank ?>
                        </a>
                        <?php else: ?>
                        <button class="activate-chip" data-id="<?= $filmId ?>"
                                title="Zu Jeder-gegen-Jeden hinzufügen" onclick="addToJgj(this)">
                            <i class="bi bi-people" style="pointer-events:none;"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <p class="text-center mt-3" style="color:rgba(255,255,255,.3); font-size:.8rem;">
                Die Reihenfolge basiert auf deinen Duellergebnissen – der Sieger übernimmt den Platz des Verlierers.
            </p>

            <?php endif; ?>
        </div>
    </section>

    <!-- ── Aktivierungs-Bar (fixed bottom) ────────────────────────────────── -->
    <div id="activate-bar" style="display:none;">
        <div class="activate-bar-info">
            <strong id="activate-count">0</strong> Film<span id="activate-plural">e</span> ausgewählt
        </div>
        <div class="d-flex gap-2 align-items-center">
            <button class="btn-activate-cancel" onclick="clearActivation()">
                <i class="bi bi-x me-1"></i>Auswahl aufheben
            </button>
            <button class="btn-activate-submit" id="activate-submit-btn" onclick="submitActivation()">
                <i class="bi bi-sort-numeric-down me-1"></i>Einordnen
            </button>
        </div>
    </div>

    <!-- ── TAB 2: Jeder gegen Jeden ─────────────────────────────────────── -->
    <?php if ($latestLigaId): ?>
    <section id="tab-jgj" class="py-4" style="background:#14325a; display:none;">
        <div class="container">

            <?php if (empty($ligaRanking)): ?>
            <div class="text-center py-5">
                <i class="bi bi-people" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Keine Liga-Ergebnisse vorhanden</h4>
            </div>
            <?php else: ?>

            <p class="mb-3" style="color:rgba(255,255,255,.35); font-size:.8rem;">
                Ergebnisse der letzten abgeschlossenen Liga · <?= count($ligaRanking) ?> Filme · Sortierung nach Liga-Siegen.
            </p>

            <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                <?php foreach ($ligaRanking as $i => $film): ?>
                <?php
                    $pos      = $i + 1;
                    $medals   = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                    $posColor = $pos === 1 ? '#e8b84b' : ($pos === 2 ? '#b0b0b0' : ($pos === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                    $poster   = moviePosterUrl($film, 'w92');
                    $rowBg    = $i % 2 === 0 ? 'rgba(255,255,255,.025)' : '#14325a';
                    $wins     = (int)$film['liga_wins'];
                    $losses   = (int)$film['liga_losses'];
                    $played   = (int)$film['matches_played'];
                    $winPct   = $played > 0 ? round($wins / $played * 100) : 0;
                ?>
                <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                     style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">

                    <div class="text-center flex-shrink-0" style="width:36px;">
                        <?php if (isset($medals[$pos])): ?>
                            <span style="font-size:1.3rem; line-height:1;"><?= $medals[$pos] ?></span>
                        <?php else: ?>
                            <span style="color:<?= $posColor ?>; font-size:.95rem; font-weight:700;"><?= $pos ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="flex-shrink-0" style="width:46px; height:69px; border-radius:5px; overflow:hidden; background:#1e3d7a;">
                        <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                             style="width:100%; height:100%; object-fit:cover;"
                             loading="lazy" decoding="async" onerror="this.src='https://placehold.co/46x69/1e3a5f/e8b84b?text=?'">
                    </div>

                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.95rem;"><a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a></div>
                        <div style="color:rgba(255,255,255,.4); font-size:.8rem;">
                            <?= $film['year'] ?>
                            <?php if (!empty($film['director'])): ?>&middot; <?= e($film['director']) ?><?php endif; ?>
                        </div>
                    </div>

                    <div class="d-none d-sm-flex align-items-center gap-3 flex-shrink-0">
                        <div class="text-end">
                            <div style="font-size:.8rem;">
                                <span style="color:#4caf50;"><?= $wins ?>W</span>
                                <span style="color:rgba(255,255,255,.25);"> / </span>
                                <span style="color:#f44336;"><?= $losses ?>L</span>
                            </div>
                            <div style="color:rgba(255,255,255,.3); font-size:.7rem;"><?= $winPct ?>% Siege</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <p class="text-center mt-3" style="color:rgba(255,255,255,.3); font-size:.8rem;">
                Liga-Rangliste nach Siegen – jedes Duell wurde einmalig ausgetragen.
            </p>

            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── TAB 3: Sortieren ─────────────────────────────────────────────── -->
    <?php if ($latestSortId): ?>
    <section id="tab-sort" class="py-4" style="background:#14325a; display:none;">
        <div class="container">

            <?php if (empty($sortRankingFilms)): ?>
            <div class="text-center py-5">
                <i class="bi bi-sort-numeric-down" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Keine Sortier-Ergebnisse vorhanden</h4>
            </div>
            <?php else: ?>

            <p class="mb-3" style="color:rgba(255,255,255,.35); font-size:.8rem;">
                Ergebnisse der letzten abgeschlossenen Sortierung ·
                <?= count($sortRankingFilms) ?> Filme ·
                <?= date('d.m.Y', strtotime($latestSortDate)) ?>
            </p>

            <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                <?php foreach ($sortRankingFilms as $i => $film): ?>
                <?php
                    $pos      = (int)$film['sort_pos'];
                    $medals   = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                    $posColor = $pos === 1 ? '#e8b84b' : ($pos === 2 ? '#b0b0b0' : ($pos === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                    $poster   = moviePosterUrl($film, 'w92');
                    $rowBg    = $i % 2 === 0 ? 'rgba(255,255,255,.025)' : '#14325a';
                ?>
                <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                     style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">

                    <div class="text-center flex-shrink-0" style="width:36px;">
                        <?php if (isset($medals[$pos])): ?>
                            <span style="font-size:1.3rem; line-height:1;"><?= $medals[$pos] ?></span>
                        <?php else: ?>
                            <span style="color:<?= $posColor ?>; font-size:.95rem; font-weight:700;"><?= $pos ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="flex-shrink-0" style="width:46px; height:69px; border-radius:5px; overflow:hidden; background:#1e3d7a;">
                        <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                             style="width:100%; height:100%; object-fit:cover;"
                             loading="lazy" decoding="async" onerror="this.src='https://placehold.co/46x69/1e3a5f/e8b84b?text=?'">
                    </div>

                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.95rem;"><a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a></div>
                        <div style="color:rgba(255,255,255,.4); font-size:.8rem;">
                            <?= $film['year'] ?>
                            <?php if (!empty($film['director'])): ?>&middot; <?= e($film['director']) ?><?php endif; ?>
                        </div>
                    </div>

                </div>
                <?php endforeach; ?>
            </div>

            <p class="text-center mt-3" style="color:rgba(255,255,255,.3); font-size:.8rem;">
                Exakte Reihenfolge per Merge Sort – jeder Vergleich wurde durch dich entschieden.
            </p>

            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

</main>

<script>
function switchTab(tab) {
    document.getElementById('tab-rangliste').style.display = (tab === 'rangliste') ? '' : 'none';
    <?php if ($latestLigaId): ?>
    document.getElementById('tab-jgj').style.display = (tab === 'jgj') ? '' : 'none';
    <?php endif; ?>
    <?php if ($latestSortId): ?>
    document.getElementById('tab-sort').style.display = (tab === 'sort') ? '' : 'none';
    <?php endif; ?>
    document.querySelectorAll('.rl-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-btn-' + tab).classList.add('active');
    history.replaceState(null, '', tab === 'rangliste' ? location.pathname : '#' + tab);
}
if (location.hash === '#jgj' && document.getElementById('tab-btn-jgj')) {
    switchTab('jgj');
} else if (location.hash === '#sort' && document.getElementById('tab-btn-sort')) {
    switchTab('sort');
}

// ── Aktivierungs-System ───────────────────────────────────────────────────────
(function () {
    const CSRF             = <?= json_encode(csrfToken()) ?>;
    const HAS_COMPLETED    = <?= json_encode($hasCompletedSort) ?>;
    const selectedIds      = new Set();
    const bar              = document.getElementById('activate-bar');
    const countEl          = document.getElementById('activate-count');
    const pluralEl         = document.getElementById('activate-plural');
    const submitBtn        = document.getElementById('activate-submit-btn');

    window.toggleActivate = function (btn) {
        const id = parseInt(btn.dataset.id);
        if (selectedIds.has(id)) {
            selectedIds.delete(id);
            btn.classList.remove('selected');
            btn.innerHTML = '<i class="bi bi-plus" style="pointer-events:none;"></i>';
        } else {
            selectedIds.add(id);
            btn.classList.add('selected');
            btn.innerHTML = '<i class="bi bi-check2" style="pointer-events:none;"></i>';
        }
        updateBar();
    };

    window.clearActivation = function () {
        selectedIds.clear();
        document.querySelectorAll('.activate-chip.selected').forEach(btn => {
            btn.classList.remove('selected');
            btn.innerHTML = '<i class="bi bi-plus" style="pointer-events:none;"></i>';
        });
        updateBar();
    };

    function updateBar() {
        const n = selectedIds.size;
        if (n > 0) {
            bar.style.display = 'flex';
            countEl.textContent = n;
            pluralEl.textContent = n === 1 ? '' : 'e';
        } else {
            bar.style.display = 'none';
        }
    }

    window.addToJgj = async function (btn) {
        const id = parseInt(btn.dataset.id);
        btn.disabled = true;

        const fd = new FormData();
        fd.append('action',     'add_jgj_film');
        fd.append('csrf_token', CSRF);
        fd.append('film_id',    id);

        try {
            const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                const tag = document.createElement('a');
                tag.href      = '/jgj.php';
                tag.className = 'sort-tag';
                tag.title     = 'Jeder-gegen-Jeden – Rang noch offen';
                tag.innerHTML = '<i class="bi bi-people-fill" style="font-size:.7rem;"></i>#?';
                btn.parentNode.replaceChild(tag, btn);
            } else {
                btn.disabled = false;
                alert('Fehler: ' + (data.error ?? 'Unbekannt'));
            }
        } catch {
            btn.disabled = false;
            alert('Netzwerkfehler. Bitte erneut versuchen.');
        }
    };

    window.submitActivation = async function () {
        if (selectedIds.size === 0) return;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Wird gestartet …';

        const ids = [...selectedIds].join(',');

        if (!HAS_COMPLETED) {
            // Noch keine abgeschlossene Sortierung → regulären Start mit Auswahl
            window.location.href = '/sortieren.php?count=' + selectedIds.size;
            return;
        }

        const fd = new FormData();
        fd.append('action',     'extend');
        fd.append('csrf_token', CSRF);
        fd.append('film_ids',   ids);

        try {
            const res  = await fetch('/sortieren.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                window.location.href = '/sortieren.php';
            } else {
                alert('Fehler: ' + (data.error ?? 'Unbekannt'));
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-sort-numeric-down me-1"></i>Einordnen';
            }
        } catch {
            alert('Netzwerkfehler. Bitte erneut versuchen.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-sort-numeric-down me-1"></i>Einordnen';
        }
    };
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
