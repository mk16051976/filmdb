<?php
$pageTitle = 'Community Ranglisten – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$db        = getDB();
$userId    = (int)$_SESSION['user_id'];
$activeTab = in_array($_GET['tab'] ?? '', ['jgj', 'jgj_weighted', 'aktionen']) ? $_GET['tab'] : 'ranking';

// ── Ensure table exists ────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS user_position_ranking (
    user_id  INT NOT NULL,
    movie_id INT NOT NULL,
    position INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, movie_id),
    INDEX idx_user_pos (user_id, position)
)");

// ── Tab: Community Meine-Rangliste ─────────────────────────────────────────────
$communityRanking = [];
$sortedPosMap     = [];
$hasCompletedSort = false;

if ($activeTab === 'ranking') {
    try {
        $stmt = $db->query("
            SELECT
                m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director,
                AVG(upr.position)           AS avg_rank,
                COUNT(DISTINCT upr.user_id) AS user_count,
                MIN(upr.position)           AS best_rank,
                MAX(upr.position)           AS worst_rank
            FROM user_position_ranking upr
            JOIN movies m ON m.id = upr.movie_id
            JOIN users u ON u.id = upr.user_id
            JOIN (SELECT DISTINCT user_id FROM user_tournaments WHERE status = 'completed') ut ON ut.user_id = u.id
            WHERE COALESCE(u.community_excluded,0) = 0" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
            GROUP BY m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director
            ORDER BY avg_rank ASC, user_count DESC
            LIMIT 200
        ");
        $communityRanking = $stmt->fetchAll();
    } catch (\PDOException $e) {
        $communityRanking = [];
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS sort_sessions (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            film_count SMALLINT UNSIGNED NOT NULL,
            status     ENUM('active','completed') NOT NULL DEFAULT 'active',
            state      JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $ssStmt = $db->prepare("SELECT state FROM sort_sessions
            WHERE user_id = ? AND status = 'completed' ORDER BY created_at DESC LIMIT 1");
        $ssStmt->execute([$userId]);
        if ($ssRow = $ssStmt->fetch(PDO::FETCH_ASSOC)) {
            $ssState   = json_decode($ssRow['state'], true);
            $sortedIds = $ssState['sorted'] ?? $ssState['pending'][0] ?? [];
            if ($sortedIds) {
                $hasCompletedSort = true;
                foreach ($sortedIds as $pos => $id) {
                    $sortedPosMap[(int)$id] = $pos + 1;
                }
            }
        }
    } catch (\PDOException $e) { /* sort_sessions noch nicht vorhanden */ }
}

// ── Tab: Community JgJ-Rangliste ───────────────────────────────────────────────
$jgjCommunityRanking = [];
$jgjRankMap          = [];
$hasJgjRanking       = false;

if ($activeTab === 'jgj') {
    try {
        $stmt = $db->query("
            SELECT
                m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director,
                AVG(jgj.jgj_rank)               AS avg_rank,
                COUNT(DISTINCT jgj.user_id)      AS user_count,
                MIN(jgj.jgj_rank)               AS best_rank,
                MAX(jgj.jgj_rank)               AS worst_rank
            FROM (
                SELECT movie_id, user_id,
                    RANK() OVER (
                        PARTITION BY user_id
                        ORDER BY wins DESC, losses ASC
                    ) AS jgj_rank
                FROM (
                    SELECT p.movie_id, p.user_id,
                        SUM(CASE WHEN r.winner_id = p.movie_id THEN 1 ELSE 0 END)                       AS wins,
                        SUM(CASE WHEN r.id IS NOT NULL AND r.winner_id != p.movie_id THEN 1 ELSE 0 END) AS losses
                    FROM jgj_pool p
                    JOIN users u ON u.id = p.user_id
                    JOIN (SELECT DISTINCT user_id FROM user_tournaments WHERE status = 'completed') ut ON ut.user_id = u.id
                    LEFT JOIN jgj_results r
                        ON r.user_id = p.user_id
                       AND (r.movie_a_id = p.movie_id OR r.movie_b_id = p.movie_id)
                    WHERE COALESCE(u.community_excluded,0) = 0
                    GROUP BY p.movie_id, p.user_id
                ) AS film_scores
            ) AS jgj
            JOIN movies m ON m.id = jgj.movie_id
            WHERE 1=1" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
            GROUP BY m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director
            ORDER BY avg_rank ASC, user_count DESC
            LIMIT 200
        ");
        $jgjCommunityRanking = $stmt->fetchAll();
    } catch (\PDOException $e) {
        $jgjCommunityRanking = [];
    }

    try {
        $myRankStmt = $db->prepare("
            SELECT movie_id, jgj_rank FROM (
                SELECT movie_id,
                    RANK() OVER (ORDER BY wins DESC, losses ASC) AS jgj_rank
                FROM (
                    SELECT p.movie_id,
                        SUM(CASE WHEN r.winner_id = p.movie_id THEN 1 ELSE 0 END)                       AS wins,
                        SUM(CASE WHEN r.id IS NOT NULL AND r.winner_id != p.movie_id THEN 1 ELSE 0 END) AS losses
                    FROM jgj_pool p
                    LEFT JOIN jgj_results r
                        ON r.user_id = p.user_id
                       AND (r.movie_a_id = p.movie_id OR r.movie_b_id = p.movie_id)
                    WHERE p.user_id = ?
                    GROUP BY p.movie_id
                ) AS my_scores
            ) AS ranked
        ");
        $myRankStmt->execute([$userId]);
        $myRows = $myRankStmt->fetchAll();
        if (!empty($myRows)) {
            $hasJgjRanking = true;
            foreach ($myRows as $row) {
                $jgjRankMap[(int)$row['movie_id']] = (int)$row['jgj_rank'];
            }
        }
    } catch (\PDOException $e) { /* jgj-Tabellen noch nicht vorhanden */ }

    // Eigener JgJ-Pool für Aktivierungsbutton
    $myJgjPoolSet = [];
    try {
        $ps = $db->prepare("SELECT movie_id FROM jgj_pool WHERE user_id = ?");
        $ps->execute([$userId]);
        foreach ($ps->fetchAll() as $row) $myJgjPoolSet[(int)$row['movie_id']] = true;
    } catch (\PDOException $e) {}
}

// ── Tab: Community JgJ-Rangliste (gewichtet) ──────────────────────────────────
// Nur User mit vollständig abgeschlossenen JgJ-Duellen.
// Punkte: Platz 1 = pool_size, Platz 2 = pool_size-1, …, letzter = 1.
// Summe aller User ergibt die gewichtete Gesamtpunktzahl je Film.
$jgjWeightedRanking = [];
$jgjWeightedRankMap = []; // eigener JgJ-Rang des eingeloggten Users

if ($activeTab === 'jgj_weighted') {
    try {
        $stmt = $db->query("
            SELECT
                m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director,
                SUM(w.points)               AS total_points,
                COUNT(DISTINCT w.user_id)   AS user_count
            FROM (
                SELECT
                    movie_id,
                    user_id,
                    pool_size - jgj_rank + 1 AS points
                FROM (
                    SELECT
                        movie_id, user_id, pool_size,
                        RANK() OVER (
                            PARTITION BY user_id
                            ORDER BY wins DESC, losses ASC
                        ) AS jgj_rank
                    FROM (
                        SELECT
                            p.movie_id,
                            p.user_id,
                            SUM(CASE WHEN r.winner_id = p.movie_id THEN 1 ELSE 0 END)                       AS wins,
                            SUM(CASE WHEN r.id IS NOT NULL AND r.winner_id != p.movie_id THEN 1 ELSE 0 END) AS losses,
                            ps.pool_size
                        FROM jgj_pool p
                        JOIN (
                            SELECT user_id, COUNT(*) AS pool_size
                            FROM jgj_pool
                            GROUP BY user_id
                        ) ps ON ps.user_id = p.user_id
                        LEFT JOIN jgj_results r
                            ON r.user_id = p.user_id
                           AND (r.movie_a_id = p.movie_id OR r.movie_b_id = p.movie_id)
                        JOIN (
                            SELECT jp.user_id
                            FROM jgj_pool jp
                            GROUP BY jp.user_id
                            HAVING (COUNT(*) * (COUNT(*) - 1) / 2) <= (
                                SELECT COUNT(*) FROM jgj_results jr WHERE jr.user_id = jp.user_id
                            )
                        ) done_users ON done_users.user_id = p.user_id
                        GROUP BY p.movie_id, p.user_id, ps.pool_size
                    ) AS film_scores
                ) AS ranked
            ) AS w
            JOIN movies m ON m.id = w.movie_id
            WHERE 1=1" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
            GROUP BY m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director
            ORDER BY total_points DESC, user_count DESC
            LIMIT 200
        ");
        $jgjWeightedRanking = $stmt->fetchAll();
    } catch (\PDOException $e) {
        $jgjWeightedRanking = [];
    }

    // Eigener JgJ-Pool des eingeloggten Users
    $myJgjPoolSet = [];
    try {
        $ps = $db->prepare("SELECT movie_id FROM jgj_pool WHERE user_id = ?");
        $ps->execute([$userId]);
        foreach ($ps->fetchAll() as $row) $myJgjPoolSet[(int)$row['movie_id']] = true;
    } catch (\PDOException $e) {}

    // Eigener JgJ-Rang des eingeloggten Users (für Badge)
    try {
        $myWStmt = $db->prepare("
            SELECT movie_id, jgj_rank FROM (
                SELECT movie_id,
                    RANK() OVER (ORDER BY wins DESC, losses ASC) AS jgj_rank
                FROM (
                    SELECT p.movie_id,
                        SUM(CASE WHEN r.winner_id = p.movie_id THEN 1 ELSE 0 END)                       AS wins,
                        SUM(CASE WHEN r.id IS NOT NULL AND r.winner_id != p.movie_id THEN 1 ELSE 0 END) AS losses
                    FROM jgj_pool p
                    LEFT JOIN jgj_results r
                        ON r.user_id = p.user_id
                       AND (r.movie_a_id = p.movie_id OR r.movie_b_id = p.movie_id)
                    WHERE p.user_id = ?
                    GROUP BY p.movie_id
                ) AS my_scores
            ) AS ranked
        ");
        $myWStmt->execute([$userId]);
        foreach ($myWStmt->fetchAll() as $row) {
            $jgjWeightedRankMap[(int)$row['movie_id']] = (int)$row['jgj_rank'];
        }
    } catch (\PDOException $e) {}
}

// Total users
try {
    $totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (\PDOException $e) {
    $totalUsers = 0;
}

// ── Tab: Community Aktions-Ranglisten ──────────────────────────────────────────
$communityActionLists = []; // list_id => ['list' => [...], 'ranking' => [...]]
if ($activeTab === 'aktionen') {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS action_lists (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL, description TEXT NULL,
            start_date DATE NOT NULL, end_date DATE NOT NULL,
            created_by INT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS action_list_rankings (
            list_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            movie_id INT UNSIGNED NOT NULL, position INT UNSIGNED NOT NULL,
            wins INT UNSIGNED NOT NULL DEFAULT 0, losses INT UNSIGNED NOT NULL DEFAULT 0,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (list_id, user_id, movie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Alle Aktionen mit mindestens einem abgeschlossenen Nutzer
        $listsStmt = $db->query("
            SELECT al.id, al.name, al.start_date, al.end_date,
                   COUNT(DISTINCT alr.user_id) AS user_count
            FROM action_lists al
            JOIN action_list_rankings alr ON alr.list_id = al.id
            GROUP BY al.id, al.name, al.start_date, al.end_date
            ORDER BY al.start_date DESC
        ");
        $aktLists = $listsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($aktLists as $al) {
            $lid = (int)$al['id'];
            // Community-Rangdurchschnitt: durchschnittliche Position über alle Nutzer
            $rankStmt = $db->prepare("
                SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en,
                       AVG(alr.position)           AS avg_pos,
                       COUNT(DISTINCT alr.user_id) AS user_count
                FROM action_list_rankings alr
                JOIN movies m ON m.id = alr.movie_id
                WHERE alr.list_id = ?
                GROUP BY m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en
                ORDER BY avg_pos ASC
            ");
            $rankStmt->execute([$lid]);
            $communityRankRows = $rankStmt->fetchAll(PDO::FETCH_ASSOC);

            // Eigener Rang in dieser Aktion
            $myRankStmt = $db->prepare("SELECT movie_id, position FROM action_list_rankings WHERE list_id=? AND user_id=?");
            $myRankStmt->execute([$lid, $userId]);
            $myRankMap = array_column($myRankStmt->fetchAll(PDO::FETCH_ASSOC), 'position', 'movie_id');

            $communityActionLists[$lid] = [
                'list'      => $al,
                'ranking'   => $communityRankRows,
                'my_ranks'  => $myRankMap,
            ];
        }
    } catch (\PDOException $e) { $communityActionLists = []; }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body { background: #14325a !important; }
    .rangliste-row:hover { background: rgba(232,184,75,.07) !important; }
    .avg-bar-track { background: rgba(255,255,255,.07); border-radius: 4px; height: 4px; width: 80px; }
    .avg-bar-fill  { background: #e8b84b; border-radius: 4px; height: 4px; }
    .sort-tag {
        display: inline-flex; align-items: center; gap: 3px;
        background: rgba(232,184,75,.18); color: #e8b84b;
        border: 1px solid rgba(232,184,75,.35);
        border-radius: 20px; padding: 2px 8px;
        font-size: .72rem; font-weight: 600; white-space: nowrap;
    }
    .activate-chip {
        width: 30px; height: 30px; border-radius: 50%;
        background: rgba(255,255,255,.07); border: 1.5px solid rgba(255,255,255,.2);
        color: rgba(255,255,255,.5); cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: .9rem; padding: 0; transition: all .2s;
    }
    .activate-chip:hover { background: rgba(232,184,75,.15); border-color: rgba(232,184,75,.5); color: #e8b84b; }
    .activate-chip.selected { background: rgba(232,184,75,.25); border-color: #e8b84b; color: #e8b84b; }
    #activate-bar {
        position: fixed; bottom: 0; left: 0; right: 0; z-index: 1055;
        background: #1e3d7a; border-top: 1px solid rgba(232,184,75,.3);
        padding: .9rem 1.5rem; display: none;
        align-items: center; gap: 1rem; justify-content: flex-end;
    }
    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 3px; }
    ::-webkit-scrollbar-thumb:hover { background: rgba(232,184,75,.4); }
    * { scrollbar-width: thin; scrollbar-color: rgba(232,184,75,.2) transparent; }
</style>

<main style="padding-top:6px; background:#14325a; flex:1;">

    <section class="py-5" style="background:linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom:1px solid rgba(232,184,75,.15);">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="fw-bold mb-1" style="color:#e8b84b;">
                        <i class="bi bi-people-fill me-2"></i>Community Ranglisten
                    </h1>
                    <p class="mb-0" style="color:rgba(255,255,255,.55);">
                        Durchschnittsrang jedes Films über alle Nutzer-Ranglisten
                    </p>
                </div>
                <div class="col-auto text-end">
                    <?php
                    if ($activeTab === 'jgj') $displayCount = count($jgjCommunityRanking);
                    elseif ($activeTab === 'jgj_weighted') $displayCount = count($jgjWeightedRanking);
                    else $displayCount = count($communityRanking);
                    ?>
                    <div style="color:#e8b84b; font-size:2rem; font-weight:800; line-height:1;"><?= $displayCount ?></div>
                    <div style="color:rgba(255,255,255,.45); font-size:.8rem;">Film<?= $displayCount !== 1 ? 'e' : '' ?> bewertet</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Tab-Navigation -->
    <div style="background:#14325a; border-bottom:1px solid rgba(255,255,255,.08);">
        <div class="container">
            <ul class="nav" style="gap:4px; padding-top:8px;">
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'ranking' ? 'active' : '' ?>"
                       href="?tab=ranking"
                       style="<?= $activeTab === 'ranking' ? 'color:#e8b84b; border-bottom:2px solid #e8b84b;' : 'color:rgba(255,255,255,.5);' ?> padding:.6rem 1rem; font-size:.9rem; font-weight:600; border-bottom:2px solid transparent; border-radius:0;">
                        <i class="bi bi-people me-1"></i>Meine Rangliste
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'jgj' ? 'active' : '' ?>"
                       href="?tab=jgj"
                       style="<?= $activeTab === 'jgj' ? 'color:#e8b84b; border-bottom:2px solid #e8b84b;' : 'color:rgba(255,255,255,.5);' ?> padding:.6rem 1rem; font-size:.9rem; font-weight:600; border-bottom:2px solid transparent; border-radius:0;">
                        <i class="bi bi-diagram-3-fill me-1"></i>JgJ-Rangliste
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'jgj_weighted' ? 'active' : '' ?>"
                       href="?tab=jgj_weighted"
                       style="<?= $activeTab === 'jgj_weighted' ? 'color:#e8b84b; border-bottom:2px solid #e8b84b;' : 'color:rgba(255,255,255,.5);' ?> padding:.6rem 1rem; font-size:.9rem; font-weight:600; border-bottom:2px solid transparent; border-radius:0;">
                        <i class="bi bi-bar-chart-fill me-1"></i>JgJ-Rangliste (gewichtet)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'aktionen' ? 'active' : '' ?>"
                       href="?tab=aktionen"
                       style="<?= $activeTab === 'aktionen' ? 'color:#e8b84b; border-bottom:2px solid #e8b84b;' : 'color:rgba(255,255,255,.5);' ?> padding:.6rem 1rem; font-size:.9rem; font-weight:600; border-bottom:2px solid transparent; border-radius:0;">
                        <i class="bi bi-trophy-fill me-1"></i>Aktions-Ranglisten
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <section class="py-4" style="background:#14325a;">
        <div class="container">

        <?php if ($activeTab === 'jgj'): ?>
        <!-- ── JgJ-Tab ──────────────────────────────────────────────────────── -->
        <?php if (empty($jgjCommunityRanking)): ?>
            <div class="text-center py-5">
                <i class="bi bi-diagram-3" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Noch keine JgJ-Daten vorhanden</h4>
                <p class="mb-0" style="color:rgba(255,255,255,.4);">
                    Sobald Nutzer Jeder-gegen-Jeden-Duelle durchf&uuml;hren, erscheint hier die Community-JgJ-Rangliste.
                </p>
            </div>
        <?php else: ?>
            <div class="mb-3 px-1" style="color:rgba(255,255,255,.35); font-size:.8rem;">
                <i class="bi bi-info-circle me-1"></i>
                Durchschnittlicher JgJ-Rang = mittlere Platzierung in den Jeder-gegen-Jeden-Ranglisten aller Nutzer. Niedrigerer Wert = besser.
            </div>
            <?php $maxAvgJgj = max(array_column($jgjCommunityRanking, 'avg_rank')); ?>
            <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                <?php foreach ($jgjCommunityRanking as $i => $film):
                    $rank     = $i + 1;
                    $medals   = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                    $posColor = $rank === 1 ? '#e8b84b' : ($rank === 2 ? '#b0b0b0' : ($rank === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                    $poster   = moviePosterUrl($film, 'w92');
                    $rowBg    = $i % 2 === 0 ? 'rgba(255,255,255,.025)' : '#14325a';
                    $avgRank  = round((float)$film['avg_rank'], 2);
                    $uCount   = (int)$film['user_count'];
                    $barPct   = $maxAvgJgj > 0 ? min(100, round((1 - ($avgRank - 1) / $maxAvgJgj) * 100)) : 0;
                    $myRank   = $jgjRankMap[(int)$film['id']] ?? null;
                ?>
                <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                     style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">
                    <div class="text-center flex-shrink-0" style="width:36px;">
                        <?php if (isset($medals[$rank])): ?>
                            <span style="font-size:1.3rem; line-height:1;"><?= $medals[$rank] ?></span>
                        <?php else: ?>
                            <span style="color:<?= $posColor ?>; font-size:.95rem; font-weight:700;"><?= $rank ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="flex-shrink-0" style="width:46px; height:69px; border-radius:5px; overflow:hidden; background:#1e3d7a;">
                        <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                             style="width:100%; height:100%; object-fit:cover;"
                             loading="lazy" decoding="async" onerror="this.src='https://placehold.co/46x69/1e3a5f/e8b84b?text=?'">
                    </div>
                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.95rem;">
                            <a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a>
                        </div>
                        <div style="color:rgba(255,255,255,.4); font-size:.8rem;">
                            <?= $film['year'] ?><?php if (!empty($film['director'])): ?> &middot; <?= e($film['director']) ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="d-none d-sm-flex align-items-center gap-3 flex-shrink-0">
                        <div class="text-end">
                            <div style="color:#e8b84b; font-size:.85rem; font-weight:600;"><?= number_format($avgRank, 2) ?></div>
                            <div style="color:rgba(255,255,255,.35); font-size:.7rem;">&Oslash; Rang</div>
                            <div class="avg-bar-track mt-1"><div class="avg-bar-fill" style="width:<?= $barPct ?>%;"></div></div>
                        </div>
                        <div class="text-end">
                            <div style="color:rgba(255,255,255,.7); font-size:.85rem; font-weight:600;"><?= $uCount ?></div>
                            <div style="color:rgba(255,255,255,.35); font-size:.7rem;">Nutzer</div>
                        </div>
                    </div>
                    <div class="flex-shrink-0" style="min-width:44px; display:flex; justify-content:flex-end;">
                        <?php if ($myRank !== null): ?>
                            <span class="sort-tag" title="Dein JgJ-Rang: #<?= $myRank ?>">
                                <i class="bi bi-diagram-3-fill" style="font-size:.7rem;"></i>#<?= $myRank ?>
                            </span>
                        <?php elseif (isset($myJgjPoolSet[(int)$film['id']])): ?>
                            <span class="sort-tag" title="Im JgJ-Pool, noch kein Rang">
                                <i class="bi bi-people-fill" style="font-size:.7rem;"></i>#?
                            </span>
                        <?php else: ?>
                            <button class="activate-chip" data-id="<?= (int)$film['id'] ?>"
                                    title="Zu Jeder-gegen-Jeden hinzufügen" onclick="addToJgj(this)">
                                <i class="bi bi-people" style="pointer-events:none;"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="text-center mt-3" style="color:rgba(255,255,255,.3); font-size:.8rem;">
                Sortiert nach dem niedrigsten durchschnittlichen JgJ-Rang &uuml;ber alle Nutzer.
            </p>
        <?php endif; ?>

        <?php elseif ($activeTab === 'jgj_weighted'): ?>
        <!-- ── JgJ-gewichtet-Tab ───────────────────────────────────────────── -->
        <?php if (empty($jgjWeightedRanking)): ?>
            <div class="text-center py-5">
                <i class="bi bi-bar-chart" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Noch keine abgeschlossenen JgJ-Bewertungen</h4>
                <p class="mb-0" style="color:rgba(255,255,255,.4);">
                    Nur Nutzer mit vollst&auml;ndig abgeschlossenen Jeder-gegen-Jeden-Duellen flie&szlig;en in diese Rangliste ein.
                </p>
            </div>
        <?php else: ?>
            <div class="mb-3 px-1" style="color:rgba(255,255,255,.35); font-size:.8rem;">
                <i class="bi bi-info-circle me-1"></i>
                Gewichtete Punkte: Platz&nbsp;1&nbsp;=&nbsp;N&nbsp;Punkte, Platz&nbsp;2&nbsp;=&nbsp;N&minus;1, &hellip;, letzter Platz&nbsp;=&nbsp;1&nbsp;Punkt (N&nbsp;=&nbsp;Poolgr&ouml;&szlig;e des jeweiligen Nutzers).
                Nur vollst&auml;ndig abgeschlossene Bewertungen. Mehr bewertete Filme = mehr Einfluss.
            </div>
            <?php $maxPts = max(array_column($jgjWeightedRanking, 'total_points')); ?>
            <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                <?php foreach ($jgjWeightedRanking as $i => $film):
                    $rank     = $i + 1;
                    $medals   = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                    $posColor = $rank === 1 ? '#e8b84b' : ($rank === 2 ? '#b0b0b0' : ($rank === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                    $poster   = moviePosterUrl($film, 'w92');
                    $rowBg    = $i % 2 === 0 ? 'rgba(255,255,255,.025)' : '#14325a';
                    $pts      = (int)$film['total_points'];
                    $uCount   = (int)$film['user_count'];
                    $barPct   = $maxPts > 0 ? min(100, round($pts / $maxPts * 100)) : 0;
                    $myRank   = $jgjWeightedRankMap[(int)$film['id']] ?? null;
                ?>
                <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                     style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">
                    <div class="text-center flex-shrink-0" style="width:36px;">
                        <?php if (isset($medals[$rank])): ?>
                            <span style="font-size:1.3rem; line-height:1;"><?= $medals[$rank] ?></span>
                        <?php else: ?>
                            <span style="color:<?= $posColor ?>; font-size:.95rem; font-weight:700;"><?= $rank ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="flex-shrink-0" style="width:46px; height:69px; border-radius:5px; overflow:hidden; background:#1e3d7a;">
                        <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                             style="width:100%; height:100%; object-fit:cover;"
                             loading="lazy" decoding="async" onerror="this.src='https://placehold.co/46x69/1e3a5f/e8b84b?text=?'">
                    </div>
                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.95rem;">
                            <a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a>
                        </div>
                        <div style="color:rgba(255,255,255,.4); font-size:.8rem;">
                            <?= $film['year'] ?><?php if (!empty($film['director'])): ?> &middot; <?= e($film['director']) ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="d-none d-sm-flex align-items-center gap-3 flex-shrink-0">
                        <div class="text-end">
                            <div style="color:#e8b84b; font-size:.85rem; font-weight:600;"><?= number_format($pts) ?></div>
                            <div style="color:rgba(255,255,255,.35); font-size:.7rem;">Punkte</div>
                            <div class="avg-bar-track mt-1"><div class="avg-bar-fill" style="width:<?= $barPct ?>%;"></div></div>
                        </div>
                        <div class="text-end">
                            <div style="color:rgba(255,255,255,.7); font-size:.85rem; font-weight:600;"><?= $uCount ?></div>
                            <div style="color:rgba(255,255,255,.35); font-size:.7rem;">Nutzer</div>
                        </div>
                    </div>
                    <div class="flex-shrink-0" style="min-width:44px; display:flex; justify-content:flex-end;">
                        <?php if ($myRank !== null): ?>
                            <span class="sort-tag" title="Dein JgJ-Rang: #<?= $myRank ?>">
                                <i class="bi bi-diagram-3-fill" style="font-size:.7rem;"></i>#<?= $myRank ?>
                            </span>
                        <?php elseif (isset($myJgjPoolSet[(int)$film['id']])): ?>
                            <span class="sort-tag" title="Im JgJ-Pool, noch kein Rang">
                                <i class="bi bi-people-fill" style="font-size:.7rem;"></i>#?
                            </span>
                        <?php else: ?>
                            <button class="activate-chip" data-id="<?= (int)$film['id'] ?>"
                                    title="Zu Jeder-gegen-Jeden hinzufügen" onclick="addToJgj(this)">
                                <i class="bi bi-people" style="pointer-events:none;"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="text-center mt-3" style="color:rgba(255,255,255,.3); font-size:.8rem;">
                Absteigende Punktesumme. Nur Nutzer mit vollst&auml;ndig abgeschlossenen JgJ-Duellen.
            </p>
        <?php endif; ?>

        <?php elseif ($activeTab === 'ranking'): ?>
        <!-- ── Meine-Rangliste-Tab ──────────────────────────────────────────── -->
            <?php if (empty($communityRanking)): ?>
            <div class="text-center py-5">
                <i class="bi bi-people" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Noch keine Community-Daten vorhanden</h4>
                <p class="mb-0" style="color:rgba(255,255,255,.4);">
                    Sobald Nutzer ihre Turniere abschließen und Duelle durchführen,
                    erscheint hier die Community-Rangliste.
                </p>
            </div>
            <?php else: ?>

            <!-- Info-Hinweis -->
            <div class="mb-3 px-1" style="color:rgba(255,255,255,.35); font-size:.8rem;">
                <i class="bi bi-info-circle me-1"></i>
                Durchschnittsrang = Summe der Platzierungen aller Nutzer &divide; Anzahl der Nutzer, die den Film bewertet haben.
                Niedrigerer Wert = besser.
            </div>

            <?php
                // Determine max avg_rank for the progress bar scaling
                $maxAvg = max(array_column($communityRanking, 'avg_rank'));
            ?>

            <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                <?php foreach ($communityRanking as $i => $film): ?>
                <?php
                    $rank     = $i + 1;
                    $medals   = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                    $posColor = $rank === 1 ? '#e8b84b' : ($rank === 2 ? '#b0b0b0' : ($rank === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                    $poster   = moviePosterUrl($film, 'w92');
                    $rowBg    = $i % 2 === 0 ? 'rgba(255,255,255,.025)' : '#14325a';
                    $avgRank  = round((float)$film['avg_rank'], 2);
                    $uCount   = (int)$film['user_count'];
                    $barPct   = $maxAvg > 0 ? min(100, round((1 - ($avgRank - 1) / $maxAvg) * 100)) : 0;
                ?>
                <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                     style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">

                    <!-- Rang -->
                    <div class="text-center flex-shrink-0" style="width:36px;">
                        <?php if (isset($medals[$rank])): ?>
                            <span style="font-size:1.3rem; line-height:1;"><?= $medals[$rank] ?></span>
                        <?php else: ?>
                            <span style="color:<?= $posColor ?>; font-size:.95rem; font-weight:700;"><?= $rank ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Poster -->
                    <div class="flex-shrink-0" style="width:46px; height:69px; border-radius:5px; overflow:hidden; background:#1e3d7a;">
                        <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                             style="width:100%; height:100%; object-fit:cover;"
                             loading="lazy" decoding="async" onerror="this.src='https://placehold.co/46x69/1e3a5f/e8b84b?text=?'">
                    </div>

                    <!-- Title & info -->
                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.95rem;">
                            <?= e(movieTitle($film)) ?>
                        </div>
                        <div style="color:rgba(255,255,255,.4); font-size:.8rem;">
                            <?= $film['year'] ?>
                            <?php if (!empty($film['director'])): ?>
                                &middot; <?= e($film['director']) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="d-none d-sm-flex align-items-center gap-3 flex-shrink-0">
                        <!-- Avg rank -->
                        <div class="text-end">
                            <div style="color:#e8b84b; font-size:.85rem; font-weight:600;"><?= number_format($avgRank, 2) ?></div>
                            <div style="color:rgba(255,255,255,.35); font-size:.7rem;">Ø Rang</div>
                            <div class="avg-bar-track mt-1">
                                <div class="avg-bar-fill" style="width:<?= $barPct ?>%;"></div>
                            </div>
                        </div>
                        <!-- User count -->
                        <div class="text-end">
                            <div style="color:rgba(255,255,255,.7); font-size:.85rem; font-weight:600;"><?= $uCount ?></div>
                            <div style="color:rgba(255,255,255,.35); font-size:.7rem;">Nutzer</div>
                        </div>
                    </div>

                    <?php if ($hasCompletedSort):
                        $sortPos = $sortedPosMap[(int)$film['id']] ?? null;
                    ?>
                    <div class="flex-shrink-0" style="min-width:44px; display:flex; justify-content:flex-end;">
                        <?php if ($sortPos !== null): ?>
                            <span class="sort-tag" title="Sortiert auf Platz <?= $sortPos ?>">
                                <i class="bi bi-sort-numeric-down" style="font-size:.7rem;"></i>#<?= $sortPos ?>
                            </span>
                        <?php else: ?>
                            <button class="activate-chip" data-id="<?= (int)$film['id'] ?>"
                                    title="Zur Sortierung hinzufügen" onclick="toggleActivate(this)">
                                <i class="bi bi-plus" style="pointer-events:none;"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div>
                <?php endforeach; ?>
            </div>

            <p class="text-center mt-3" style="color:rgba(255,255,255,.3); font-size:.8rem;">
                Sortiert nach dem niedrigsten Durchschnittsrang (aufsteigend) &uuml;ber alle Nutzer-Ranglisten.
            </p>

            <?php endif; ?>

        <?php else: ?>
        <!-- ── Aktions-Tab ─────────────────────────────────────────────────── -->
        <?php if (empty($communityActionLists)): ?>
            <div class="text-center py-5">
                <i class="bi bi-trophy" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Noch keine Aktions-Daten vorhanden</h4>
                <p class="mb-0" style="color:rgba(255,255,255,.4);">
                    Sobald Nutzer Aktionen abschließen, erscheint hier die Community-Rangliste.
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($communityActionLists as $lid => $alData): ?>
            <?php $al = $alData['list']; $alRanking = $alData['ranking']; $myRanks = $alData['my_ranks']; ?>
            <div class="mb-5">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold mb-0" style="color:#e8b84b;">
                            <i class="bi bi-trophy-fill me-2"></i><?= e($al['name']) ?>
                        </h5>
                        <div style="color:rgba(255,255,255,.4); font-size:.78rem; margin-top:.2rem;">
                            <?= date('d.m.Y', strtotime($al['start_date'])) ?> – <?= date('d.m.Y', strtotime($al['end_date'])) ?>
                            &nbsp;·&nbsp; <?= (int)$al['user_count'] ?> Teilnehmer
                        </div>
                    </div>
                    <a href="/aktionen.php?list=<?= (int)$lid ?>" class="ms-auto btn btn-sm"
                       style="background:rgba(232,184,75,.12); border:1px solid rgba(232,184,75,.3); color:#e8b84b; font-size:.78rem;">
                        <i class="bi bi-play-fill me-1"></i>Zur Aktion
                    </a>
                </div>

                <?php if (empty($alRanking)): ?>
                <div style="color:rgba(255,255,255,.3); font-size:.85rem;">Keine Daten verfügbar.</div>
                <?php else: ?>
                <?php $maxAvg = (float)($alRanking[count($alRanking)-1]['avg_pos'] ?? 1); ?>
                <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                    <?php foreach ($alRanking as $i => $film): ?>
                    <?php
                        $rank     = $i + 1;
                        $medals   = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                        $posColor = $rank === 1 ? '#e8b84b' : ($rank === 2 ? '#b0b0b0' : ($rank === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                        $poster   = moviePosterUrl($film, 'w92');
                        $rowBg    = $i % 2 === 0 ? 'rgba(255,255,255,.025)' : '#14325a';
                        $myPos    = $myRanks[(int)$film['id']] ?? null;
                        $barPct   = $maxAvg > 0 ? round((float)$film['avg_pos'] / $maxAvg * 100) : 0;
                    ?>
                    <div class="rangliste-row" style="display:flex; align-items:center; gap:.75rem; padding:.6rem 1rem; border-bottom:1px solid rgba(255,255,255,.05); background:<?= $rowBg ?>;">
                        <div style="min-width:2rem; text-align:right; font-size:.85rem; font-weight:700; color:<?= $posColor ?>; flex-shrink:0;">
                            <?= isset($medals[$rank]) ? $medals[$rank] : $rank ?>
                        </div>
                        <div style="width:46px; height:69px; border-radius:5px; overflow:hidden; background:#1e3d7a; flex-shrink:0;">
                            <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                                 style="width:100%; height:100%; object-fit:cover;" loading="lazy"
                                 onerror="this.src='https://placehold.co/46x69/1e3a5f/e8b84b?text=?'">
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:.9rem; font-weight:600; color:#e0e0e0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <a href="/film.php?id=<?= (int)$film['id'] ?>" style="color:inherit; text-decoration:none;" target="_blank"><?= e(movieTitle($film)) ?></a>
                            </div>
                            <div style="font-size:.75rem; color:rgba(255,255,255,.35); margin-top:.1rem;"><?= (int)$film['year'] ?></div>
                        </div>
                        <div style="text-align:right; flex-shrink:0;">
                            <div class="avg-bar-track" style="margin-left:auto; margin-bottom:3px;">
                                <div class="avg-bar-fill" style="width:<?= $barPct ?>%;"></div>
                            </div>
                            <div style="font-size:.75rem; color:#e8b84b; font-weight:700;">Ø <?= number_format((float)$film['avg_pos'], 1) ?></div>
                            <?php if ($film['user_count'] > 1): ?>
                            <div style="font-size:.7rem; color:rgba(255,255,255,.3);"><?= (int)$film['user_count'] ?> Nutzer</div>
                            <?php endif; ?>
                            <?php if ($myPos !== null): ?>
                            <div style="font-size:.7rem; color:rgba(232,184,75,.6); margin-top:2px;">Mein Platz: #<?= $myPos ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-center mt-2" style="color:rgba(255,255,255,.3); font-size:.8rem;">
                    Community-Rangliste · Sortiert nach durchschnittlicher Position (aufsteigend)
                </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php endif; // end tab ranking ?>

        </div>
    </section>

<?php if ($hasCompletedSort): ?>
<div id="activate-bar">
    <span style="color:rgba(255,255,255,.6); font-size:.9rem;">
        <span id="activate-count">0</span> Film(e) ausgewählt
    </span>
    <button onclick="clearActivation()" class="btn btn-sm"
            style="background:rgba(255,255,255,.08); color:rgba(255,255,255,.6); border:none;">
        Auswahl aufheben
    </button>
    <button onclick="submitActivation()" class="btn btn-sm btn-gold">
        <i class="bi bi-sort-numeric-down me-1"></i>Einordnen
    </button>
</div>

<script>
const selectedIds = new Set();

function toggleActivate(btn) {
    const id = btn.dataset.id;
    if (selectedIds.has(id)) {
        selectedIds.delete(id);
        btn.classList.remove('selected');
        btn.innerHTML = '<i class="bi bi-plus" style="pointer-events:none;"></i>';
    } else {
        selectedIds.add(id);
        btn.classList.add('selected');
        btn.innerHTML = '<i class="bi bi-check" style="pointer-events:none;"></i>';
    }
    updateBar();
}

function clearActivation() {
    selectedIds.clear();
    document.querySelectorAll('.activate-chip.selected').forEach(btn => {
        btn.classList.remove('selected');
        btn.innerHTML = '<i class="bi bi-plus" style="pointer-events:none;"></i>';
    });
    updateBar();
}

function updateBar() {
    const bar = document.getElementById('activate-bar');
    document.getElementById('activate-count').textContent = selectedIds.size;
    bar.style.display = selectedIds.size > 0 ? 'flex' : 'none';
}

function submitActivation() {
    if (selectedIds.size === 0) return;
    const ids = Array.from(selectedIds).join(',');
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/sortieren.php';
    const fields = { action: 'extend', film_ids: ids,
                     csrf_token: '<?= e($_SESSION['csrf_token'] ?? '') ?>' };
    for (const [k, v] of Object.entries(fields)) {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = k; inp.value = v;
        form.appendChild(inp);
    }
    document.body.appendChild(form);
    form.submit();
}

</script>
<?php endif; ?>

<script>
// JgJ-Aktivierung (immer verfügbar)
window.addToJgj = async function (btn) {
    const id = parseInt(btn.dataset.id);
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action',     'add_film');
    fd.append('csrf_token', '<?= e(csrfToken()) ?>');
    fd.append('film_id',    id);
    try {
        const res  = await fetch('/jgj.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            const tag = document.createElement('span');
            tag.className = 'sort-tag';
            tag.title     = 'Im JgJ-Pool, noch kein Rang';
            tag.innerHTML = '<i class="bi bi-people-fill" style="font-size:.7rem;"></i>#?';
            btn.parentNode.replaceChild(tag, btn);
        } else {
            btn.disabled = false;
            alert('Fehler: ' + (data.error ?? 'Unbekannt'));
        }
    } catch {
        btn.disabled = false;
        alert('Netzwerkfehler.');
    }
};
</script>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
