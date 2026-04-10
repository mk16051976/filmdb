<?php
$pageTitle = 'Meine Ranglisten – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$db     = getDB();

// ── Ensure tables exist ────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS user_position_ranking (
    user_id  INT NOT NULL,
    movie_id INT NOT NULL,
    position INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, movie_id),
    INDEX idx_user_pos (user_id, position)
)");

$db->exec("CREATE TABLE IF NOT EXISTS tournament_results (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id  INT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED NOT NULL,
    movie_id       INT UNSIGNED NOT NULL,
    wins           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    score          FLOAT NOT NULL DEFAULT 0,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_tournament (tournament_id),
    UNIQUE KEY uq_tm (tournament_id, movie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Aktiver Tab (früh setzen, steuert welche Queries laufen) ──────────────────
$activeTab = $_GET['tab'] ?? 'persoenlich';
$isCsvExport = isset($_GET['export']) && $_GET['export'] === 'csv';

// ── Cheap counts für Tab-Badges (immer) ───────────────────────────────────────
$s = $db->prepare(
    "SELECT COUNT(*) FROM user_position_ranking upr
     JOIN movies m ON m.id = upr.movie_id
     WHERE upr.user_id=?" . seriesSqlFilter('m') . moviesSqlFilter('m')
);
$s->execute([$userId]); $totalFilms = (int)$s->fetchColumn();

// ── Persönliche Rangliste (nur wenn aktiver Tab oder CSV) ─────────────────────
$personalRanking = [];
if ($activeTab === 'persoenlich') {
    $stmt = $db->prepare("
        SELECT upr.position,
               m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director,
               COALESCE(ur.elo, 1500)      AS elo,
               COALESCE(ur.wins, 0)        AS wins,
               COALESCE(ur.losses, 0)      AS losses,
               COALESCE(ur.comparisons, 0) AS comparisons
        FROM user_position_ranking upr
        JOIN movies m ON m.id = upr.movie_id
        LEFT JOIN user_ratings ur ON ur.movie_id = m.id AND ur.user_id = upr.user_id
        WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . hiddenFilmsSqlFilter('m') . "
        ORDER BY upr.position ASC
    ");
    $stmt->execute([$userId]);
    $personalRanking = $stmt->fetchAll();
}

// ── Turnierranglisten ──────────────────────────────────────────────────────────
// Cheap badge-count always
$s = $db->prepare("SELECT COUNT(DISTINCT tournament_id) FROM tournament_results WHERE user_id=?");
$s->execute([$userId]); $tournamentCount = (int)$s->fetchColumn();

$tournamentRankings = [];
if ($activeTab === 'turnier' || ($isCsvExport && ($_GET['tab'] ?? '') === 'turnier')) {
    $stmt = $db->prepare("
        SELECT tournament_id, MIN(created_at) AS tournament_date, COUNT(*) AS film_count
        FROM tournament_results WHERE user_id = ?
        GROUP BY tournament_id ORDER BY tournament_id DESC
    ");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $ts) {
        $tid   = (int)$ts['tournament_id'];
        $stmt2 = $db->prepare("
            SELECT tr.wins, tr.matches_played, tr.score,
                   m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director
            FROM tournament_results tr
            JOIN movies m ON m.id = tr.movie_id
            WHERE tr.tournament_id = ? AND tr.user_id = ?
            ORDER BY tr.score DESC, tr.wins DESC, m.title ASC
        ");
        $stmt2->execute([$tid, $userId]);
        $tournamentRankings[$tid] = [
            'date'       => $ts['tournament_date'],
            'film_count' => (int)$ts['film_count'],
            'films'      => $stmt2->fetchAll(),
        ];
    }
}

// ── Liga-Daten (Tab 3) ────────────────────────────────────────────────────────
$ligaRanking  = [];
$latestLigaId = null;

$db->exec("CREATE TABLE IF NOT EXISTS liga_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    film_count SMALLINT UNSIGNED NOT NULL,
    status     ENUM('active','completed') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->exec("ALTER TABLE liga_sessions ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NOT NULL DEFAULT 'movie'");
$db->exec("CREATE TABLE IF NOT EXISTS liga_matches (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    liga_id    INT UNSIGNED NOT NULL,
    movie_a_id INT UNSIGNED NOT NULL,
    movie_b_id INT UNSIGNED NOT NULL,
    winner_id  INT UNSIGNED NULL,
    voted_at   TIMESTAMP NULL,
    INDEX idx_liga_pending (liga_id, winner_id),
    UNIQUE KEY uq_pair (liga_id, movie_a_id, movie_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Badge: check if a completed liga session exists (cheap)
$stmt = $db->prepare("SELECT id, film_count FROM liga_sessions WHERE user_id = ? AND status = 'completed' AND media_type = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$userId, activeMtForDb()]);
$ligaSessionRow  = $stmt->fetch();
$latestLigaId    = $ligaSessionRow ? (int)$ligaSessionRow['id'] : null;
$ligaFilmCount   = $ligaSessionRow ? (int)$ligaSessionRow['film_count'] : 0;

// Full ranking only when needed
if ($latestLigaId && ($activeTab === 'liga' || ($isCsvExport && ($_GET['tab'] ?? '') === 'liga'))) {
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
        WHERE 1=1" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
        GROUP BY m.id
        ORDER BY liga_wins DESC, liga_losses ASC
    ");
    $stmt2->execute([$latestLigaId, $latestLigaId]);
    $ligaRanking = $stmt2->fetchAll();
}

// ── Sortier-Daten (Tab 4) ────────────────────────────────────────────────────
$sortRankingFilms = [];
$latestSortId     = null;
$latestSortDate   = null;

$db->exec("CREATE TABLE IF NOT EXISTS sort_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    film_count SMALLINT UNSIGNED NOT NULL,
    status     ENUM('active','completed') NOT NULL DEFAULT 'active',
    state      JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->exec("ALTER TABLE sort_sessions ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NOT NULL DEFAULT 'movie'");

// Sort session: always fetch header row (cheap, 1 row, no JSON decode yet)
$stmt = $db->prepare("SELECT id, film_count, created_at FROM sort_sessions
    WHERE user_id = ? AND status = 'completed' AND media_type = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$userId, activeMtForDb()]);
$sortHeaderRow  = $stmt->fetch();
$latestSortId   = $sortHeaderRow ? (int)$sortHeaderRow['id'] : null;
$latestSortDate = $sortHeaderRow ? $sortHeaderRow['created_at'] : null;
$latestSortFilmCount = $sortHeaderRow ? (int)$sortHeaderRow['film_count'] : 0;

$sortRankingFilms = [];
$sortedPosMap     = [];
$hasCompletedSort = false;
$sortedIds        = [];

// Only decode JSON + load film data when needed
if ($latestSortId && ($activeTab === 'persoenlich' || $activeTab === 'sort' || ($isCsvExport && ($_GET['tab'] ?? '') === 'sort'))) {
    $stmt = $db->prepare("SELECT state FROM sort_sessions WHERE id = ? LIMIT 1");
    $stmt->execute([$latestSortId]);
    $sortState = json_decode($stmt->fetchColumn(), true);
    $sortedIds = $sortState['sorted'] ?? $sortState['pending'][0] ?? [];
    if ($sortedIds) {
        $hasCompletedSort = true;
        foreach ($sortedIds as $pos => $id) {
            $sortedPosMap[(int)$id] = $pos + 1;
        }
        if ($activeTab === 'sort' || ($isCsvExport && ($_GET['tab'] ?? '') === 'sort')) {
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
} elseif ($latestSortId) {
    // Just need to know if sort is done (for activate-bar); skip JSON decode
    $hasCompletedSort = true;
}

// ── Letzte Duelle (Sidebar – nur auf persoenlich Tab) ─────────────────────────
$lastDuels = [];
if ($activeTab === 'persoenlich') {
    try {
        $duelStmt = $db->prepare("
            SELECT c.winner_id, c.loser_id, c.prev_winner_elo, c.prev_loser_elo, c.created_at,
                   mw.title AS winner_title, ml.title AS loser_title,
                   mw.title_en AS winner_title_en, ml.title_en AS loser_title_en,
                   mw.poster_path AS winner_poster, ml.poster_path AS loser_poster,
                   upr_w.position AS winner_pos, upr_l.position AS loser_pos
            FROM comparisons c
            JOIN movies mw ON mw.id = c.winner_id
            JOIN movies ml ON ml.id = c.loser_id
            LEFT JOIN user_position_ranking upr_w ON upr_w.movie_id = c.winner_id AND upr_w.user_id = c.user_id
            LEFT JOIN user_position_ranking upr_l ON upr_l.movie_id = c.loser_id  AND upr_l.user_id = c.user_id
            WHERE c.user_id = ?
            ORDER BY c.created_at DESC
            LIMIT 50
        ");
        $duelStmt->execute([$userId]);
        $lastDuels = $duelStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) { /* comparisons not available */ }
}

// ── Jeder-gegen-Jeden-Daten ───────────────────────────────────────────────────
$jgjRanking  = [];
$jgjPoolSize = 0;
$jgjDone     = 0;
$jgjTotal    = 0;
$jgjPoolSet  = [];
$jgjRankMap  = [];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS jgj_pool (
        user_id  INT UNSIGNED NOT NULL, movie_id INT UNSIGNED NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, movie_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS jgj_results (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL, movie_a_id INT UNSIGNED NOT NULL,
        movie_b_id INT UNSIGNED NOT NULL, winner_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id), UNIQUE KEY uq_match (user_id, movie_a_id, movie_b_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Badge-Count immer (cheap, gefiltert nach Medientyp)
    $s = $db->prepare("SELECT COUNT(*) FROM jgj_pool p JOIN movies m ON m.id = p.movie_id WHERE p.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m'));
    $s->execute([$userId]); $jgjPoolSize = (int)$s->fetchColumn();

    if ($activeTab === 'jgj' || $activeTab === 'persoenlich') {
        $s = $db->prepare("SELECT COUNT(*) FROM jgj_results r JOIN movies m ON m.id = r.winner_id WHERE r.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m'));
        $s->execute([$userId]); $jgjDone = (int)$s->fetchColumn();
        $jgjTotal = $jgjPoolSize > 1 ? (int)($jgjPoolSize * ($jgjPoolSize - 1) / 2) : 0;
    }

    if ($jgjPoolSize > 0 && ($activeTab === 'jgj' || $activeTab === 'persoenlich')) {
        $s = $db->prepare("
            SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director,
                SUM(CASE WHEN r.winner_id = m.id THEN 1 ELSE 0 END)                       AS wins,
                SUM(CASE WHEN r.id IS NOT NULL AND r.winner_id != m.id THEN 1 ELSE 0 END) AS losses,
                COUNT(r.id)                                                                 AS played
            FROM jgj_pool p
            JOIN movies m ON m.id = p.movie_id
            LEFT JOIN jgj_results r
                ON r.user_id = p.user_id AND (r.movie_a_id = m.id OR r.movie_b_id = m.id)
            WHERE p.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
            GROUP BY m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director
            ORDER BY
                SUM(CASE WHEN r.winner_id = m.id THEN 1 ELSE 0 END) DESC,
                SUM(CASE WHEN r.id IS NOT NULL AND r.winner_id != m.id THEN 1 ELSE 0 END) ASC");
        $s->execute([$userId]);
        $jgjRanking = $s->fetchAll();
        foreach ($jgjRanking as $i => $r) {
            $mid = (int)$r['id'];
            $jgjPoolSet[$mid] = true;
            $jgjRankMap[$mid] = $i + 1;
        }
    }
} catch (\PDOException $e) { /* jgj tables not yet available */ }

// ── Aktions-Ranglisten (Tab) ───────────────────────────────────────────────────
$myActionLists      = [];
$myActionListCount  = 0;
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

    // Badge-Count immer (cheap)
    $s = $db->prepare("SELECT COUNT(DISTINCT list_id) FROM action_list_rankings WHERE user_id=?");
    $s->execute([$userId]); $myActionListCount = (int)$s->fetchColumn();

    if ($activeTab === 'aktionen') {
        $aktStmt = $db->prepare("
            SELECT al.id, al.name, al.start_date, al.end_date,
                   COUNT(DISTINCT alr.movie_id) AS film_count,
                   (SELECT COUNT(*) FROM action_list_films alf2 WHERE alf2.list_id = al.id) AS list_film_count,
                   (SELECT COUNT(*) FROM action_list_duels ald2 WHERE ald2.list_id = al.id AND ald2.user_id = ?) AS done_count
            FROM action_list_rankings alr
            JOIN action_lists al ON al.id = alr.list_id
            WHERE alr.user_id = ?
            GROUP BY al.id, al.name, al.start_date, al.end_date
            ORDER BY al.start_date DESC
        ");
        $aktStmt->execute([$userId, $userId]);
        foreach ($aktStmt->fetchAll(PDO::FETCH_ASSOC) as $alh) {
            $lid   = (int)$alh['id'];
            $rStmt = $db->prepare("
                SELECT alr.position, alr.wins, alr.losses, m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en
                FROM action_list_rankings alr
                JOIN movies m ON m.id = alr.movie_id
                WHERE alr.list_id = ? AND alr.user_id = ?
                ORDER BY alr.position ASC
            ");
            $rStmt->execute([$lid, $userId]);
            $myActionLists[$lid] = array_merge($alh, ['films' => $rStmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
    }
} catch (\PDOException $e) { $myActionLists = []; $myActionListCount = 0; }

// ── CSV-Export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $tab = $_GET['tab'] ?? 'persoenlich';

    function csvRow(array $fields): string {
        return implode(';', array_map(function($v) {
            $v = str_replace('"', '""', (string)$v);
            return '"' . $v . '"';
        }, $fields)) . "\r\n";
    }

    switch ($tab) {
        case 'persoenlich':
            $filename = 'meine-rangliste.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "\xEF\xBB\xBF"; // BOM für Excel
            echo csvRow(['Platz', 'Titel', 'Jahr', 'Regisseur', 'ELO', 'Siege', 'Niederlagen', 'Duelle']);
            foreach ($personalRanking as $f) {
                echo csvRow([$f['position'], $f['title'], $f['year'], $f['director'] ?? '',
                    $f['elo'], $f['wins'], $f['losses'], $f['comparisons']]);
            }
            exit;

        case 'turnier':
            $filename = 'turnierrangliste.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "\xEF\xBB\xBF";
            echo csvRow(['Turnier', 'Datum', 'Platz', 'Titel', 'Jahr', 'Regisseur', 'Score', 'Siege', 'Runden']);
            $nr = count($tournamentRankings);
            foreach ($tournamentRankings as $tid => $t) {
                $date = date('d.m.Y', strtotime($t['date']));
                foreach ($t['films'] as $rank => $f) {
                    echo csvRow([$nr, $date, $rank + 1, $f['title'], $f['year'], $f['director'] ?? '',
                        number_format((float)$f['score'], 4, '.', ''), $f['wins'], $f['matches_played']]);
                }
                $nr--;
            }
            exit;

        case 'liga':
            $filename = 'liga-rangliste.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "\xEF\xBB\xBF";
            echo csvRow(['Platz', 'Titel', 'Jahr', 'Regisseur', 'Siege', 'Niederlagen', 'Duelle', '% Siege']);
            foreach ($ligaRanking as $i => $f) {
                $played = (int)$f['matches_played'];
                $pct    = $played > 0 ? round((int)$f['liga_wins'] / $played * 100, 1) : 0;
                echo csvRow([$i + 1, $f['title'], $f['year'], $f['director'] ?? '',
                    $f['liga_wins'], $f['liga_losses'], $played, $pct]);
            }
            exit;

        case 'sort':
            $filename = 'sortier-rangliste.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "\xEF\xBB\xBF";
            echo csvRow(['Platz', 'Titel', 'Jahr', 'Regisseur', 'Sortiert am']);
            $date = $latestSortDate ? date('d.m.Y', strtotime($latestSortDate)) : '';
            foreach ($sortRankingFilms as $f) {
                echo csvRow([$f['sort_pos'], $f['title'], $f['year'], $f['director'] ?? '', $date]);
            }
            exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body { background: #14325a !important; }
    .rangliste-row:hover { background: rgba(232,184,75,.07) !important; }
    .nav-tabs { border-bottom: 1px solid rgba(232,184,75,.2); }
    .nav-tabs .nav-link {
        color: rgba(255,255,255,.55);
        border: 1px solid transparent;
        border-bottom: none;
        border-radius: 8px 8px 0 0;
        padding: .6rem 1.4rem;
        font-weight: 500;
        transition: color .2s;
    }
    .nav-tabs .nav-link:hover { color: #e8b84b; }
    .nav-tabs .nav-link.active {
        background: rgba(232,184,75,.1);
        color: #e8b84b;
        border-color: rgba(232,184,75,.2) rgba(232,184,75,.2) #14325a;
    }
    .turnier-section { border: 1px solid rgba(232,184,75,.15); border-radius: 12px; overflow: hidden; }
    .turnier-header { background: rgba(232,184,75,.08); padding: 1rem 1.25rem; border-bottom: 1px solid rgba(232,184,75,.15); }
    .csv-btn { color: rgba(232,184,75,.6); font-size: .78rem; text-decoration: none; border: 1px solid rgba(232,184,75,.25); border-radius: 6px; padding: 4px 10px; transition: all .2s; white-space: nowrap; }
    .csv-btn:hover { color: #e8b84b; border-color: rgba(232,184,75,.6); background: rgba(232,184,75,.07); }
    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 3px; }
    ::-webkit-scrollbar-thumb:hover { background: rgba(232,184,75,.4); }
    * { scrollbar-width: thin; scrollbar-color: rgba(232,184,75,.2) transparent; }
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
    .activate-chip:disabled { opacity: .4; cursor: not-allowed; }
    a.sort-tag { text-decoration: none; }
    a.sort-tag:hover { background: rgba(232,184,75,.3); color: #e8b84b; }
    #activate-bar {
        position: fixed; bottom: 0; left: 0; right: 0; z-index: 1055;
        background: #1e3d7a; border-top: 1px solid rgba(232,184,75,.3);
        padding: .9rem 1.5rem; display: none;
        align-items: center; gap: 1rem; justify-content: flex-end;
    }
    .duel-sidebar {
        position: sticky; top: 90px;
        border: 1px solid rgba(255,255,255,.08); border-radius: 12px; overflow: hidden;
        max-height: calc(100vh - 110px); display: flex; flex-direction: column;
    }
    .duel-sidebar-header {
        background: rgba(232,184,75,.08); border-bottom: 1px solid rgba(232,184,75,.15);
        padding: .75rem 1rem; flex-shrink: 0;
        color: #e8b84b; font-weight: 700; font-size: .82rem; text-transform: uppercase; letter-spacing: .05em;
    }
    .duel-feed { overflow-y: auto; flex: 1; }
    .duel-item {
        padding: .6rem 1rem; border-bottom: 1px solid rgba(255,255,255,.04);
        display: flex; flex-direction: column; gap: .25rem;
    }
    .duel-item:last-child { border-bottom: none; }
    .duel-item:hover { background: rgba(232,184,75,.04); }
    .duel-film { display: flex; align-items: center; gap: .5rem; min-width: 0; }
    .duel-film-title { font-size: .82rem; color: rgba(255,255,255,.8); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
    .duel-film-rank { font-size: .72rem; color: rgba(255,255,255,.35); white-space: nowrap; }
    .duel-vs { font-size: .65rem; color: rgba(255,255,255,.2); padding-left: 1.2rem; }
    .duel-win-badge  { width: 18px; height: 18px; border-radius: 50%; background: rgba(76,175,80,.2); color: #4caf50; display: inline-flex; align-items: center; justify-content: center; font-size: .6rem; flex-shrink: 0; }
    .duel-loss-badge { width: 18px; height: 18px; border-radius: 50%; background: rgba(244,67,54,.12); color: #f44336; display: inline-flex; align-items: center; justify-content: center; font-size: .6rem; flex-shrink: 0; }
    .duel-time { font-size: .68rem; color: rgba(255,255,255,.2); text-align: right; }
</style>

<main style="padding-top:6px; background:#14325a; flex:1;">

    <section class="py-5" style="background:linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom:1px solid rgba(232,184,75,.15);">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="fw-bold mb-1" style="color:#e8b84b;">
                        <i class="bi bi-trophy-fill me-2"></i>Meine Ranglisten
                    </h1>
                    <p class="mb-0" style="color:rgba(255,255,255,.55);">Deine persönliche Filmrangliste & Turnierranglisten</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-4" style="background:#14325a;">
        <div class="container">
        <div class="row g-4">
        <div class="col-lg-8">

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'persoenlich' ? 'active' : '' ?>"
                       href="?tab=persoenlich">
                        <i class="bi bi-person-fill me-1"></i>Meine Rangliste
                        <?php if ($totalFilms > 0): ?>
                            <span class="badge ms-1" style="background:rgba(232,184,75,.25); color:#e8b84b; font-size:.7rem;"><?= $totalFilms ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'turnier' ? 'active' : '' ?>"
                       href="?tab=turnier">
                        <i class="bi bi-diagram-3-fill me-1"></i>Turnierrangliste
                        <?php if ($tournamentCount > 0): ?>
                            <span class="badge ms-1" style="background:rgba(232,184,75,.25); color:#e8b84b; font-size:.7rem;"><?= $tournamentCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php if ($latestLigaId): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'liga' ? 'active' : '' ?>"
                       href="?tab=liga">
                        <i class="bi bi-people-fill me-1"></i>Jeder gegen Jeden
                        <span class="badge ms-1" style="background:rgba(232,184,75,.25); color:#e8b84b; font-size:.7rem;"><?= $ligaFilmCount ?></span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($latestSortId): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'sort' ? 'active' : '' ?>"
                       href="?tab=sort">
                        <i class="bi bi-sort-numeric-down me-1"></i>Sortieren
                        <span class="badge ms-1" style="background:rgba(232,184,75,.25); color:#e8b84b; font-size:.7rem;"><?= $latestSortFilmCount ?></span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($jgjPoolSize > 0): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'jgj' ? 'active' : '' ?>"
                       href="?tab=jgj">
                        <i class="bi bi-diagram-3-fill me-1"></i>Jeder gegen Jeden
                        <span class="badge ms-1" style="background:rgba(232,184,75,.25); color:#e8b84b; font-size:.7rem;"><?= $jgjPoolSize ?></span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($myActionListCount > 0): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'aktionen' ? 'active' : '' ?>"
                       href="?tab=aktionen">
                        <i class="bi bi-trophy-fill me-1"></i>Aktions-Ranglisten
                        <span class="badge ms-1" style="background:rgba(232,184,75,.25); color:#e8b84b; font-size:.7rem;"><?= $myActionListCount ?></span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <!-- ── Tab: Persönliche Rangliste ── -->
            <?php if ($activeTab === 'persoenlich'): ?>

                <?php if ($totalFilms === 0): ?>
                <div class="text-center py-5">
                    <i class="bi bi-collection-play" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                    <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Noch keine Duelle durchgeführt</h4>
                    <p class="mb-4" style="color:rgba(255,255,255,.4);">Starte ein Turnier, um deine persönliche Rangliste aufzubauen.</p>
                    <a href="/turnier.php" class="btn btn-gold">Zum Turnier</a>
                </div>
                <?php else: ?>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span style="color:rgba(255,255,255,.35); font-size:.8rem;"><?= $totalFilms ?> Filme</span>
                    <a href="?tab=persoenlich&export=csv" class="csv-btn">
                        <i class="bi bi-download me-1"></i>CSV herunterladen
                    </a>
                </div>

                <?php if ($hasCompletedSort): ?>
                <?php $unsortedCount = $totalFilms - count($sortedPosMap); ?>
                <div class="mb-3 px-3 py-2" style="background:rgba(232,184,75,.07); border:1px solid rgba(232,184,75,.2); border-radius:10px; font-size:.82rem; color:rgba(255,255,255,.55);">
                    <i class="bi bi-sort-numeric-down me-1" style="color:#e8b84b;"></i>
                    <strong style="color:#e8b84b;"><?= count($sortedPosMap) ?></strong> von <?= $totalFilms ?> Filmen sortiert.
                    <?php if ($unsortedCount > 0): ?>
                    Klicke <i class="bi bi-plus-circle" style="color:rgba(232,184,75,.7);"></i> bei weiteren Filmen, um sie einzuordnen.
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                    <?php foreach ($personalRanking as $i => $film): ?>
                    <?php
                        $pos      = (int)$film['position'];
                        $medals   = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                        $posColor = $pos === 1 ? '#e8b84b' : ($pos === 2 ? '#b0b0b0' : ($pos === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                        $poster   = moviePosterUrl($film);
                        $rowBg    = $i % 2 === 0 ? 'rgba(255,255,255,.025)' : '#14325a';
                    ?>
                    <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                         style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">

                        <!-- Position -->
                        <div class="text-center flex-shrink-0" style="width:36px;">
                            <?php if (isset($medals[$pos])): ?>
                                <span style="font-size:1.3rem; line-height:1;"><?= $medals[$pos] ?></span>
                            <?php else: ?>
                                <span style="color:<?= $posColor ?>; font-size:.95rem; font-weight:700;"><?= $pos ?></span>
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
                                <a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a>
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
                            <?php if ($film['comparisons'] > 0): ?>
                            <div class="text-end">
                                <div style="color:#e8b84b; font-size:.85rem; font-weight:600;"><?= $film['elo'] ?></div>
                                <div style="color:rgba(255,255,255,.35); font-size:.7rem;">ELO</div>
                            </div>
                            <div class="text-end">
                                <div style="font-size:.8rem;">
                                    <span style="color:#4caf50;"><?= $film['wins'] ?>W</span>
                                    <span style="color:rgba(255,255,255,.25);"> / </span>
                                    <span style="color:#f44336;"><?= $film['losses'] ?>L</span>
                                </div>
                            </div>
                            <?php else: ?>
                            <div style="color:rgba(255,255,255,.25); font-size:.8rem;">–</div>
                            <?php endif; ?>
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
                    Die Reihenfolge basiert auf deinen Duellergebnissen –
                    der Sieger übernimmt den Platz des Verlierers.
                </p>

                <?php endif; ?>

            <?php elseif ($activeTab === 'liga'): /* Tab: Jeder gegen Jeden */ ?>

                <?php if (empty($ligaRanking)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-people" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                    <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Keine Liga-Ergebnisse vorhanden</h4>
                </div>
                <?php else: ?>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span style="color:rgba(255,255,255,.35); font-size:.8rem;">
                        Ergebnisse der letzten Liga · <?= count($ligaRanking) ?> Filme · Sortierung nach Siegen
                    </span>
                    <a href="?tab=liga&export=csv" class="csv-btn">
                        <i class="bi bi-download me-1"></i>CSV herunterladen
                    </a>
                </div>

                <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                    <?php foreach ($ligaRanking as $i => $film): ?>
                    <?php
                        $pos      = $i + 1;
                        $medals   = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                        $posColor = $pos === 1 ? '#e8b84b' : ($pos === 2 ? '#b0b0b0' : ($pos === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                        $poster   = moviePosterUrl($film);
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
                            <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.95rem;"><?= e(movieTitle($film)) ?></div>
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

            <?php elseif ($activeTab === 'sort'): /* Tab: Sortieren */ ?>

                <?php if (empty($sortRankingFilms)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-sort-numeric-down" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                    <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Keine Sortier-Ergebnisse vorhanden</h4>
                </div>
                <?php else: ?>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span style="color:rgba(255,255,255,.35); font-size:.8rem;">
                        <?= count($sortRankingFilms) ?> Filme · Sortiert am <?= date('d.m.Y', strtotime($latestSortDate)) ?>
                    </span>
                    <a href="?tab=sort&export=csv" class="csv-btn">
                        <i class="bi bi-download me-1"></i>CSV herunterladen
                    </a>
                </div>

                <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                    <?php foreach ($sortRankingFilms as $i => $film): ?>
                    <?php
                        $pos      = (int)$film['sort_pos'];
                        $medals   = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                        $posColor = $pos === 1 ? '#e8b84b' : ($pos === 2 ? '#b0b0b0' : ($pos === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                        $poster   = moviePosterUrl($film);
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
                            <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.95rem;"><?= e(movieTitle($film)) ?></div>
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

            <?php elseif ($activeTab === 'jgj'): /* Tab: Jeder gegen Jeden */ ?>

                <?php if (empty($jgjRanking)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-diagram-3-fill" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                    <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Noch keine JgJ-Duelle</h4>
                    <p class="mb-4" style="color:rgba(255,255,255,.4);">Starte den Jeder-gegen-Jeden-Modus, um Ergebnisse zu sehen.</p>
                    <a href="/jgj.php" class="btn btn-gold">Jeder gegen Jeden starten</a>
                </div>
                <?php else: ?>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span style="color:rgba(255,255,255,.35); font-size:.8rem;">
                        <?= $jgjPoolSize ?> Filme im Pool &nbsp;·&nbsp;
                        <?= number_format($jgjDone) ?> / <?= number_format($jgjTotal) ?> Duelle
                        (<?= $jgjTotal > 0 ? round($jgjDone / $jgjTotal * 100) : 0 ?>%)
                    </span>
                    <a href="/jgj.php" class="btn btn-sm" style="background:rgba(232,184,75,.15); border:1px solid rgba(232,184,75,.3); color:#e8b84b; font-size:.8rem;">
                        <i class="bi bi-play-fill me-1"></i>Weiterspielen
                    </a>
                </div>

                <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                <?php foreach ($jgjRanking as $i => $r):
                    $wins   = (int)$r['wins'];
                    $losses = (int)$r['losses'];
                    $played = (int)$r['played'];
                    $ratio  = $played > 0 ? round($wins / $played * 100) : 0;
                ?>
                <div class="rangliste-row" style="display:flex; align-items:center; gap:12px; padding:10px 16px; border-bottom:1px solid rgba(255,255,255,.05);">
                    <span style="min-width:2rem; font-size:.8rem; font-weight:700; color:<?= $i < 3 ? '#e8b84b' : 'rgba(255,255,255,.35)' ?>; text-align:right;"><?= $i + 1 ?></span>
                    <img loading="lazy" src="<?= e(moviePosterUrl($r, 'w92')) ?>"
                         width="30" height="45" style="border-radius:3px; object-fit:cover; flex-shrink:0;"
                         loading="lazy" decoding="async" onerror="this.src='https://placehold.co/30x45/1e3a5f/e8b84b?text=?'">
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:.9rem; color:#e0e0e0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= e(movieTitle($r)) ?></div>
                        <div style="font-size:.75rem; color:rgba(255,255,255,.35);"><?= (int)$r['year'] ?></div>
                    </div>
                    <div style="text-align:right; flex-shrink:0;">
                        <div style="font-size:.8rem; font-weight:700; color:rgba(255,255,255,.7);">
                            <?= $wins ?>S <span style="color:rgba(255,255,255,.3);"><?= $losses ?>N</span>
                        </div>
                        <div style="margin-top:4px; width:60px; height:5px; background:rgba(255,255,255,.08); border-radius:3px; overflow:hidden;">
                            <div style="width:<?= $ratio ?>%; height:100%; background:linear-gradient(90deg,#e8b84b,#c4942a); border-radius:3px;"></div>
                        </div>
                        <div style="font-size:.7rem; color:rgba(255,255,255,.3); margin-top:2px;"><?= $ratio ?>%</div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>

                <p class="text-center mt-3" style="color:rgba(255,255,255,.3); font-size:.8rem;">
                    Sortiert nach Siegen ↓ · Niederlagen ↑ · Win-Quote als Balken
                </p>

                <?php endif; ?>

            <?php elseif ($activeTab === 'aktionen'): /* Tab: Aktions-Ranglisten */ ?>

                <?php if (empty($myActionLists)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-trophy" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                    <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Noch keine Aktions-Ranglisten vorhanden</h4>
                    <p style="color:rgba(255,255,255,.4);">Schließe eine Aktion ab, um das Ergebnis hier zu sehen.</p>
                </div>
                <?php else: ?>

                <?php foreach ($myActionLists as $al): ?>
                <div class="mb-5">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div>
                            <h5 class="fw-bold mb-0" style="color:#e8b84b;">
                                <i class="bi bi-trophy-fill me-2"></i><?= htmlspecialchars($al['name']) ?>
                            </h5>
                            <div style="color:rgba(255,255,255,.4); font-size:.78rem; margin-top:.2rem;">
                                <?= date('d.m.Y', strtotime($al['start_date'])) ?> – <?= date('d.m.Y', strtotime($al['end_date'])) ?>
                                &nbsp;·&nbsp; <?= (int)$al['film_count'] ?> Filme
                            </div>
                        </div>
                        <?php
                            $listN      = (int)$al['list_film_count'];
                            $totalPairs = $listN > 1 ? (int)($listN * ($listN - 1) / 2) : 0;
                            $pending    = max(0, $totalPairs - (int)$al['done_count']);
                            $hasPending = $pending > 0;
                        ?>
                        <?php if ($hasPending): ?>
                        <span style="background:rgba(232,184,75,.18); border:1px solid rgba(232,184,75,.5); color:#e8b84b; border-radius:20px; padding:3px 10px; font-size:.72rem; font-weight:700;">
                            <i class="bi bi-play-circle-fill me-1"></i><?= $pending ?> Duelle offen
                        </span>
                        <?php endif; ?>
                        <a href="/aktionen.php?list=<?= (int)$al['id'] ?>" class="ms-auto btn btn-sm"
                           style="background:<?= $hasPending ? 'rgba(232,184,75,.3)' : 'rgba(232,184,75,.12)' ?>; border:1px solid rgba(232,184,75,.3); color:#e8b84b; font-size:.78rem;">
                            <i class="bi bi-<?= $hasPending ? 'play-circle-fill' : 'play-fill' ?> me-1"></i><?= $hasPending ? 'Weiter bewerten' : 'Zur Aktion' ?>
                        </a>
                    </div>
                    <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                        <?php foreach ($al['films'] as $i => $f): ?>
                        <?php
                            $pos    = (int)$f['position'];
                            $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                            $posColor = $pos === 1 ? '#e8b84b' : ($pos === 2 ? '#b0b0b0' : ($pos === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                            $poster = moviePosterUrl($f, 'w92');
                            $rowBg  = $i % 2 === 0 ? 'rgba(255,255,255,.025)' : '#14325a';
                        ?>
                        <div style="display:flex; align-items:center; gap:.75rem; padding:.55rem 1rem; border-bottom:1px solid rgba(255,255,255,.05); background:<?= $rowBg ?>;">
                            <div style="min-width:2rem; text-align:right; font-size:.85rem; font-weight:700; color:<?= $posColor ?>; flex-shrink:0;">
                                <?= isset($medals[$pos]) ? $medals[$pos] : $pos ?>
                            </div>
                            <img src="<?= htmlspecialchars($poster) ?>" alt=""
                                 style="width:26px; height:39px; object-fit:cover; border-radius:3px; flex-shrink:0;"
                                 loading="lazy" onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:.9rem; color:#e0e0e0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <a href="/film.php?id=<?= (int)$f['id'] ?>" style="color:inherit; text-decoration:none;" target="_blank"><?= e(movieTitle($f)) ?></a>
                                </div>
                                <div style="font-size:.75rem; color:rgba(255,255,255,.35);"><?= (int)$f['year'] ?></div>
                            </div>
                            <div style="text-align:right; flex-shrink:0;">
                                <div style="font-size:.8rem; font-weight:700; color:rgba(255,255,255,.7);">
                                    <?= (int)$f['wins'] ?>S <span style="color:rgba(255,255,255,.3);"><?= (int)$f['losses'] ?>N</span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php endif; ?>

            <?php else: /* Tab: Turnierrangliste */ ?>

                <?php if (empty($tournamentRankings)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-diagram-3" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                    <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Noch kein Turnier abgeschlossen</h4>
                    <p class="mb-4" style="color:rgba(255,255,255,.4);">
                        Schließe ein Sichtungsturnier ab, um deine Turnierrangliste zu sehen.
                    </p>
                    <a href="/turnier.php" class="btn btn-gold">Zum Turnier</a>
                </div>
                <?php else: ?>

                <div class="d-flex justify-content-end mb-3">
                    <a href="?tab=turnier&export=csv" class="csv-btn">
                        <i class="bi bi-download me-1"></i>CSV herunterladen
                    </a>
                </div>

                <?php $tournierNr = count($tournamentRankings); ?>
                <?php foreach ($tournamentRankings as $tid => $t): ?>
                <div class="turnier-section mb-4">
                    <div class="turnier-header d-flex align-items-center justify-content-between">
                        <div>
                            <span class="fw-bold" style="color:#e8b84b;">
                                <i class="bi bi-diagram-3-fill me-1"></i>
                                Turnier <?= $tournierNr-- ?>
                            </span>
                            <span class="ms-2" style="color:rgba(255,255,255,.4); font-size:.8rem;">
                                <?= date('d.m.Y', strtotime($t['date'])) ?>
                                &middot; <?= $t['film_count'] ?> Filme
                            </span>
                        </div>
                        <div style="color:rgba(255,255,255,.35); font-size:.78rem;">
                            Score = Siege / gespielte Runden
                        </div>
                    </div>

                    <?php foreach ($t['films'] as $rank => $film): ?>
                    <?php
                        $rankPos  = $rank + 1;
                        $medals   = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                        $posColor = $rankPos === 1 ? '#e8b84b' : ($rankPos === 2 ? '#b0b0b0' : ($rankPos === 3 ? '#cd7f32' : 'rgba(255,255,255,.4)'));
                        $poster   = moviePosterUrl($film);
                        $rowBg    = $rank % 2 === 0 ? 'rgba(255,255,255,.025)' : 'transparent';
                        $score    = number_format((float)$film['score'], 2);
                        $played   = (int)$film['matches_played'];
                        $wins     = (int)$film['wins'];
                    ?>
                    <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                         style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.04);">

                        <!-- Rang -->
                        <div class="text-center flex-shrink-0" style="width:36px;">
                            <?php if (isset($medals[$rankPos])): ?>
                                <span style="font-size:1.3rem; line-height:1;"><?= $medals[$rankPos] ?></span>
                            <?php else: ?>
                                <span style="color:<?= $posColor ?>; font-size:.95rem; font-weight:700;"><?= $rankPos ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Poster -->
                        <div class="flex-shrink-0" style="width:46px; height:69px; border-radius:5px; overflow:hidden; background:#1e3d7a;">
                            <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                                 style="width:100%; height:100%; object-fit:cover;"
                                 loading="lazy" decoding="async" onerror="this.src='https://placehold.co/46x69/1e3a5f/e8b84b?text=?'">
                        </div>

                        <!-- Title -->
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

                        <!-- Score -->
                        <div class="d-none d-sm-flex align-items-center gap-3 flex-shrink-0">
                            <?php if ($played > 0): ?>
                            <div class="text-end">
                                <div style="color:#e8b84b; font-size:.85rem; font-weight:600;"><?= $score ?></div>
                                <div style="color:rgba(255,255,255,.35); font-size:.7rem;">Score</div>
                            </div>
                            <div class="text-end">
                                <div style="font-size:.8rem;">
                                    <span style="color:#4caf50;"><?= $wins ?>S</span>
                                    <span style="color:rgba(255,255,255,.25);"> / </span>
                                    <span style="color:rgba(255,255,255,.5);"><?= $played ?>R</span>
                                </div>
                                <div style="color:rgba(255,255,255,.35); font-size:.7rem;">Siege / Runden</div>
                            </div>
                            <?php else: ?>
                            <div style="color:rgba(255,255,255,.25); font-size:.8rem;">–</div>
                            <?php endif; ?>
                        </div>

                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <?php endif; ?>

            <?php endif; ?>

        </div><!-- /col-lg-8 -->

        <!-- ── Rechte Spalte: Letzte Duelle ── -->
        <div class="col-lg-4">
            <div class="duel-sidebar">
                <div class="duel-sidebar-header">
                    <i class="bi bi-clock-history me-1"></i>Letzte Duelle
                    <?php if (!empty($lastDuels)): ?>
                    <span style="font-weight:400; color:rgba(255,255,255,.3); font-size:.75rem; margin-left:.4rem;"><?= count($lastDuels) ?> Einträge</span>
                    <?php endif; ?>
                </div>
                <div class="duel-feed">
                <?php if (empty($lastDuels)): ?>
                    <div class="text-center py-5" style="color:rgba(255,255,255,.3); font-size:.85rem;">
                        <i class="bi bi-hourglass" style="font-size:2rem; display:block; margin-bottom:.5rem;"></i>
                        Noch keine Duelle
                    </div>
                <?php else: ?>
                    <?php foreach ($lastDuels as $d): ?>
                    <?php
                        $wPos = $d['winner_pos'] ? '#' . $d['winner_pos'] : 'ELO ' . $d['prev_winner_elo'];
                        $lPos = $d['loser_pos']  ? '#' . $d['loser_pos']  : 'ELO ' . $d['prev_loser_elo'];
                        $ago  = time() - strtotime($d['created_at']);
                        if      ($ago < 60)     $timeStr = 'gerade eben';
                        elseif  ($ago < 3600)   $timeStr = round($ago / 60) . ' Min.';
                        elseif  ($ago < 86400)  $timeStr = round($ago / 3600) . ' Std.';
                        elseif  ($ago < 604800) $timeStr = round($ago / 86400) . ' Tagen';
                        else                    $timeStr = date('d.m.Y', strtotime($d['created_at']));
                    ?>
                    <div class="duel-item">
                        <div class="duel-film">
                            <span class="duel-win-badge"><i class="bi bi-trophy-fill"></i></span>
                            <span class="duel-film-title"><?= e(movieTitle(['title' => $d['winner_title'], 'title_en' => $d['winner_title_en'] ?? null])) ?></span>
                            <span class="duel-film-rank">(<?= $wPos ?>)</span>
                        </div>
                        <div class="duel-vs">vs.</div>
                        <div class="duel-film">
                            <span class="duel-loss-badge"><i class="bi bi-x"></i></span>
                            <span class="duel-film-title"><?= e(movieTitle(['title' => $d['loser_title'], 'title_en' => $d['loser_title_en'] ?? null])) ?></span>
                            <span class="duel-film-rank">(<?= $lPos ?>)</span>
                        </div>
                        <div class="duel-time"><?= $timeStr ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>
        </div><!-- /col-lg-4 -->

        </div><!-- /row -->
        </div><!-- /container -->
    </section>

<?php if ($hasCompletedSort): ?>
<!-- Aktivierungs-Leiste -->
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

</main>

<script>
async function addToJgj(btn) {
    const id = btn.dataset.id;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action',     'add_film');
    fd.append('csrf_token', '<?= e($_SESSION['csrf_token'] ?? csrfToken()) ?>');
    fd.append('film_id',    id);
    try {
        const res  = await fetch('/jgj.php', { method: 'POST', body: fd });
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
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
