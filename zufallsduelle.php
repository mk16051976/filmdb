<?php
$pageTitle = 'Zufallsduelle – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$userId = (int)$_SESSION['user_id'];
$db     = getDB();

// ── Tabelle sicherstellen ─────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS duel_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    source     ENUM('db','my_ranking','community') NOT NULL DEFAULT 'my_ranking',
    range_from SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    range_to   SMALLINT UNSIGNED NOT NULL DEFAULT 9999,
    film_ids   JSON NOT NULL,
    duels_done INT UNSIGNED NOT NULL DEFAULT 0,
    status     ENUM('active','stopped') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->exec("ALTER TABLE duel_sessions ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NOT NULL DEFAULT 'movie'");
$db->exec("ALTER TABLE duel_sessions ADD COLUMN IF NOT EXISTS last_winner_id INT NULL");
$db->exec("ALTER TABLE duel_sessions ADD COLUMN IF NOT EXISTS last_loser_id  INT NULL");
$db->exec("ALTER TABLE duel_sessions ADD COLUMN IF NOT EXISTS last_winner_pos INT NULL");
$db->exec("ALTER TABLE duel_sessions ADD COLUMN IF NOT EXISTS last_loser_pos  INT NULL");

// ── Hilfsfunktionen ───────────────────────────────────────────────────────────

function fetchDuelFilmIds(PDO $db, int $userId, string $source, int $from, int $to): array {
    $limit  = max(2, $to - $from + 1);
    $offset = max(0, $from - 1);

    if ($source === 'my_ranking') {
        $stmt = $db->prepare("SELECT upr.movie_id FROM user_position_ranking upr
            JOIN movies m ON m.id = upr.movie_id
            WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . hiddenFilmsSqlFilter('m') . "
            ORDER BY upr.position ASC LIMIT ? OFFSET ?");
        $stmt->execute([$userId, $limit, $offset]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    if ($source === 'community') {
        $stmt = $db->prepare("SELECT m.id FROM user_position_ranking upr
            JOIN movies m ON m.id = upr.movie_id
            WHERE 1=1" . seriesSqlFilter('m') . moviesSqlFilter('m') . hiddenFilmsSqlFilter('m') . "
            GROUP BY m.id
            ORDER BY AVG(upr.position) ASC, COUNT(DISTINCT upr.user_id) DESC
            LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // source = 'db': alle Filme alphabetisch
    $stmt = $db->prepare("SELECT id FROM movies WHERE 1=1" . seriesSqlFilter('movies') . moviesSqlFilter('movies') . hiddenFilmsSqlFilter('movies') . " ORDER BY title ASC LIMIT ? OFFSET ?");
    $stmt->execute([$limit, $offset]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function pickDuelPair(array $filmIds, array $exclude = []): array {
    $pool = array_values(array_diff($filmIds, $exclude));
    if (count($pool) < 2) $pool = array_values($filmIds);
    if (count($pool) < 2) return [];
    $keys = (array)array_rand($pool, 2);
    return [$pool[$keys[0]], $pool[$keys[1]]];
}

/** Stellt sicher: der besser gerankte Film (kleinere Position) kommt zuerst (links). */
function sortPairByRank(PDO $db, int $userId, int $idA, int $idB): array {
    $stmt = $db->prepare(
        "SELECT movie_id, position FROM user_position_ranking WHERE user_id = ? AND movie_id IN (?, ?)"
    );
    $stmt->execute([$userId, $idA, $idB]);
    $positions = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'position', 'movie_id');
    $posA = $positions[$idA] ?? PHP_INT_MAX;
    $posB = $positions[$idB] ?? PHP_INT_MAX;
    return $posA <= $posB ? [$idA, $idB] : [$idB, $idA];
}

function buildDuelSidebar(PDO $db, int $userId): array {
    $stmt = $db->prepare("SELECT upr.position AS pos, m.id, m.title, m.title_en, m.poster_path, m.poster_path_en, m.imdb_id,
        COALESCE(NULLIF(m.wikipedia,''), m.overview) AS overview
        FROM user_position_ranking upr JOIN movies m ON m.id = upr.movie_id
        WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . hiddenFilmsSqlFilter('m') . " ORDER BY upr.position ASC");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $i => &$r) { $r['pos'] = $i + 1; }
    unset($r);
    return $rows;
}

// ── AJAX-Handler ──────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['vote', 'stop_ajax', 'undo'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

    if ($action === 'stop_ajax') {
        $db->prepare("UPDATE duel_sessions SET status='stopped' WHERE user_id=? AND status='active' AND media_type=?")
           ->execute([$userId, activeMtForDb()]);
        echo json_encode(['ok' => true, 'stopped' => true]);
        exit;
    }

    if ($action === 'undo') {
        $sessStmt = $db->prepare("SELECT id, duels_done, last_winner_id, last_loser_id, last_winner_pos, last_loser_pos
            FROM duel_sessions WHERE user_id=? AND status='active' AND media_type=? ORDER BY created_at DESC LIMIT 1");
        $sessStmt->execute([$userId, activeMtForDb()]);
        $sess = $sessStmt->fetch(PDO::FETCH_ASSOC);
        if (!$sess || !$sess['last_winner_id'] || !$sess['last_loser_id']) {
            echo json_encode(['ok' => false, 'error' => 'nothing_to_undo']); exit;
        }
        $wId = (int)$sess['last_winner_id'];
        $lId = (int)$sess['last_loser_id'];
        $wPos = $sess['last_winner_pos'] !== null ? (int)$sess['last_winner_pos'] : null;
        $lPos = $sess['last_loser_pos']  !== null ? (int)$sess['last_loser_pos']  : null;

        // Revert ELO + comparison record
        undoLastComparison($userId, $wId, $lId);

        // Restore positions
        if ($wPos !== null) {
            $db->prepare("UPDATE user_position_ranking SET position=? WHERE user_id=? AND movie_id=?")
               ->execute([$wPos, $userId, $wId]);
        } else {
            $db->prepare("DELETE FROM user_position_ranking WHERE user_id=? AND movie_id=?")
               ->execute([$userId, $wId]);
        }
        if ($lPos !== null) {
            $db->prepare("UPDATE user_position_ranking SET position=? WHERE user_id=? AND movie_id=?")
               ->execute([$lPos, $userId, $lId]);
        } else {
            $db->prepare("DELETE FROM user_position_ranking WHERE user_id=? AND movie_id=?")
               ->execute([$userId, $lId]);
        }

        $newDone = max(0, (int)$sess['duels_done'] - 1);
        $db->prepare("UPDATE duel_sessions SET duels_done=?, last_winner_id=NULL, last_loser_id=NULL, last_winner_pos=NULL, last_loser_pos=NULL WHERE id=?")
           ->execute([$newDone, (int)$sess['id']]);

        $counters = getActivityCounters($userId);
        echo json_encode([
            'ok'        => true,
            'duelsDone' => $newDone,
            'hdrDuels'  => $counters['totalDuels'],
            'hdrFilms'  => $counters['uniqueFilms'],
        ]);
        exit;
    }

    // vote
    $winnerId = (int)($_POST['winner_id'] ?? 0);
    $loserId  = (int)($_POST['loser_id']  ?? 0);

    $sessStmt = $db->prepare("SELECT id, film_ids FROM duel_sessions
        WHERE user_id=? AND status='active' AND media_type=? ORDER BY created_at DESC LIMIT 1");
    $sessStmt->execute([$userId, activeMtForDb()]);
    $session = $sessStmt->fetch(PDO::FETCH_ASSOC);

    if (!$session || !$winnerId || !$loserId) {
        echo json_encode(['ok' => false, 'error' => 'invalid']); exit;
    }

    $filmIds = json_decode($session['film_ids'], true);

    if (!in_array($winnerId, $filmIds) || !in_array($loserId, $filmIds)) {
        echo json_encode(['ok' => false, 'error' => 'invalid_ids']); exit;
    }

    // Alte Positionen + Titel VOR recordComparison holen
    $duelResult = buildDuelResult($db, $userId, $winnerId, $loserId);

    // Positionen vor dem Vote merken (für Undo)
    $posStmt = $db->prepare("SELECT movie_id, position FROM user_position_ranking WHERE user_id=? AND movie_id IN (?,?)");
    $posStmt->execute([$userId, $winnerId, $loserId]);
    $prePosMap = array_column($posStmt->fetchAll(PDO::FETCH_ASSOC), 'position', 'movie_id');
    $preWPos = isset($prePosMap[$winnerId]) ? (int)$prePosMap[$winnerId] : null;
    $preLPos = isset($prePosMap[$loserId])  ? (int)$prePosMap[$loserId]  : null;

    recordComparison($userId, $winnerId, $loserId);

    $winnerContext = buildWinnerContext($db, $userId, $winnerId, getActiveMtFilter());

    $db->prepare("UPDATE duel_sessions SET duels_done=duels_done+1,
        last_winner_id=?, last_loser_id=?, last_winner_pos=?, last_loser_pos=? WHERE id=?")
       ->execute([$winnerId, $loserId, $preWPos, $preLPos, (int)$session['id']]);

    $nextPair = pickDuelPair($filmIds, [$winnerId, $loserId]);
    if (count($nextPair) < 2) {
        echo json_encode(['ok' => false, 'error' => 'pool_too_small']); exit;
    }

    // Besserer Rang immer links (A-Seite)
    [$nextA, $nextB] = sortPairByRank($db, $userId, $nextPair[0], $nextPair[1]);
    $mStmt = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, imdb_id, COALESCE(NULLIF(wikipedia,''), overview) AS overview FROM movies WHERE id IN (?, ?)");
    $mStmt->execute([$nextA, $nextB]);
    $mMap = array_column($mStmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');

    $nxtATitle = movieTitle($mMap[$nextA] ?? []);
    $nxtBTitle = movieTitle($mMap[$nextB] ?? []);
    $commRanks = buildCommRanks($db, $nextA, $nxtATitle, $nextB, $nxtBTitle, $userId, getActiveMtFilter());

    $counters = getActivityCounters($userId);
    echo json_encode([
        'ok'          => true,
        'duelsDone'   => (int)$session['duels_done'] + 1,
        'duel_result'    => $duelResult,
        'winner_context' => $winnerContext,
        'comm_ranks'     => $commRanks,
        'hdrDuels'    => $counters['totalDuels'],
        'hdrFilms'    => $counters['uniqueFilms'],
        'next'        => [
            'a_id'       => $nextA,
            'a_title'    => $nxtATitle,
            'a_year'     => (int)($mMap[$nextA]['year']     ?? 0),
            'a_poster'   => moviePosterUrl($mMap[$nextA] ?? [], 'w342'),
            'a_overview' => $mMap[$nextA]['overview'] ?? '',
            'b_id'       => $nextB,
            'b_title'    => $nxtBTitle,
            'b_year'     => (int)($mMap[$nextB]['year']     ?? 0),
            'b_poster'   => moviePosterUrl($mMap[$nextB] ?? [], 'w342'),
            'b_overview' => $mMap[$nextB]['overview'] ?? '',
        ],
    ]);
    exit;
}

// ── Form-POST-Handler ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    if ($action === 'start') {
        $source = $_POST['source'] ?? 'my_ranking';
        if (!in_array($source, ['db', 'my_ranking', 'community'])) $source = 'my_ranking';

        $from = max(1, (int)($_POST['range_from'] ?? 1));
        $to   = max($from + 1, (int)($_POST['range_to'] ?? 50));

        $filmIds = fetchDuelFilmIds($db, $userId, $source, $from, $to);

        if (count($filmIds) < 2) {
            header('Location: /zufallsduelle.php?error=too_few'); exit;
        }

        $db->prepare("UPDATE duel_sessions SET status='stopped' WHERE user_id=? AND status='active' AND media_type=?")
           ->execute([$userId, activeMtForDb()]);
        $db->prepare("INSERT INTO duel_sessions (user_id, source, range_from, range_to, film_ids, media_type) VALUES (?,?,?,?,?,?)")
           ->execute([$userId, $source, $from, $to, json_encode(array_values($filmIds)), activeMtForDb()]);

        header('Location: /zufallsduelle.php'); exit;
    }

    if ($action === 'stop') {
        $db->prepare("UPDATE duel_sessions SET status='stopped' WHERE user_id=? AND status='active' AND media_type=?")
           ->execute([$userId, activeMtForDb()]);
        header('Location: /zufallsduelle.php'); exit;
    }
}

// ── Seiten-Zustand ────────────────────────────────────────────────────────────
$sessStmt = $db->prepare("SELECT * FROM duel_sessions WHERE user_id=? AND status='active' AND media_type=? ORDER BY created_at DESC LIMIT 1");
$sessStmt->execute([$userId, activeMtForDb()]);
$activeSession = $sessStmt->fetch(PDO::FETCH_ASSOC);

$isActive  = (bool)$activeSession;
$currentA  = null;
$currentB  = null;
$duelsDone = 0;

$initLastDuel = null; // für JS-seitiges Undo nach Page-Reload

if ($isActive) {
    $filmIds   = json_decode($activeSession['film_ids'], true);
    $duelsDone = (int)$activeSession['duels_done'];

    $pair = pickDuelPair($filmIds);
    if (count($pair) < 2) {
        $db->prepare("UPDATE duel_sessions SET status='stopped' WHERE id=?")
           ->execute([(int)$activeSession['id']]);
        $isActive = false;
    } else {
        [$aId, $bId] = sortPairByRank($db, $userId, $pair[0], $pair[1]);
        $mStmt = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, imdb_id, COALESCE(NULLIF(wikipedia,''), overview) AS overview FROM movies WHERE id IN (?, ?)");
        $mStmt->execute([$aId, $bId]);
        $mMap     = array_column($mStmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        $currentA = $mMap[$aId] ?? null;
        $currentB = $mMap[$bId] ?? null;

        // Undo-Daten für JS voraufladen (falls nach Page-Reload noch vorhanden)
        $lwId = (int)($activeSession['last_winner_id'] ?? 0);
        $llId = (int)($activeSession['last_loser_id']  ?? 0);
        if ($lwId && $llId) {
            $undoMStmt = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, COALESCE(NULLIF(wikipedia,''), overview) AS overview FROM movies WHERE id IN (?,?)");
            $undoMStmt->execute([$lwId, $llId]);
            $undoMap = array_column($undoMStmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
            if (isset($undoMap[$lwId]) && isset($undoMap[$llId])) {
                // Wir wissen nicht mehr welcher der linke/rechte war — Winner links
                $mW = $undoMap[$lwId]; $mL = $undoMap[$llId];
                $initLastDuel = [
                    'aId'      => $lwId,
                    'aTitle'   => movieTitle($mW),
                    'aYear'    => (int)$mW['year'],
                    'aPoster'  => moviePosterUrl($mW, 'w342'),
                    'aOverview'=> $mW['overview'] ?? '',
                    'bId'      => $llId,
                    'bTitle'   => movieTitle($mL),
                    'bYear'    => (int)$mL['year'],
                    'bPoster'  => moviePosterUrl($mL, 'w342'),
                    'bOverview'=> $mL['overview'] ?? '',
                ];
            }
        }
    }
}

// Quellengrößen für Setup-Formular
$_sfDb   = seriesSqlFilter('movies') . moviesSqlFilter('movies') . hiddenFilmsSqlFilter('movies');
$_sfM    = seriesSqlFilter('m') . moviesSqlFilter('m') . hiddenFilmsSqlFilter('m');
$countDb = (int)$db->query("SELECT COUNT(*) FROM movies WHERE 1=1{$_sfDb}")->fetchColumn();
$countMyRanking = 0;
$countCommunity = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM user_position_ranking upr JOIN movies m ON m.id=upr.movie_id WHERE upr.user_id=?" . $_sfM);
    $s->execute([$userId]);
    $countMyRanking = (int)$s->fetchColumn();
    $countCommunity = (int)$db->query("SELECT COUNT(DISTINCT m.id) FROM user_position_ranking upr JOIN movies m ON m.id=upr.movie_id WHERE 1=1{$_sfM}")->fetchColumn();
} catch (\PDOException $e) {}

$sourceCounts = ['db' => $countDb, 'my_ranking' => $countMyRanking, 'community' => $countCommunity];

// Sidebar
$posRanking = buildDuelSidebar($db, $userId);

$sourceInfo = [
    'db'        => ['label' => 'Alle Filme (DB)',      'icon' => 'bi-database-fill'],
    'my_ranking'=> ['label' => 'Meine Rangliste',      'icon' => 'bi-list-ol'],
    'community' => ['label' => 'Community Rangliste',  'icon' => 'bi-people-fill'],
];
$activeSource = $activeSession['source'] ?? 'my_ranking';
$activeFrom   = (int)($activeSession['range_from'] ?? 1);
$activeTo     = (int)($activeSession['range_to']   ?? 0);
$activePool   = count($filmIds ?? []);

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body { background: #14325a !important; }

    .container-xxl.zd-wrap { max-width: 2200px; margin: 0 auto; padding: 0 1rem; }
    .duel-poster-wrap { display: flex; justify-content: center; }
    .duel-poster-wrap .duel-poster { max-height: calc(100dvh - 220px) !important; width: auto !important; object-fit: contain !important; }

    /* Setup */
    .source-card {
        background: rgba(255,255,255,.04);
        border: 2px solid rgba(255,255,255,.1);
        border-radius: 12px;
        padding: 1rem 1.2rem;
        cursor: pointer;
        transition: all .18s;
        display: flex; align-items: center; gap: .85rem;
    }
    .source-card:hover { border-color: rgba(232,184,75,.45); background: rgba(232,184,75,.06); }
    .source-card.selected { border-color: #e8b84b; background: rgba(232,184,75,.1); }
    .source-card .src-icon {
        width: 40px; height: 40px; border-radius: 50%;
        background: rgba(232,184,75,.12); color: #e8b84b;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem; flex-shrink: 0;
    }
    .source-card.selected .src-icon { background: rgba(232,184,75,.25); }

    .form-ctrl {
        background: rgba(255,255,255,.07);
        border: 1px solid rgba(255,255,255,.15);
        border-radius: 8px;
        color: #e0e0e0;
        padding: .5rem .9rem;
        font-size: .95rem;
        width: 100%;
        transition: border-color .15s;
    }
    .form-ctrl:focus { outline: none; border-color: #e8b84b; }

    .btn-gold {
        background: linear-gradient(135deg,#e8b84b,#d4a030);
        color: #1a1a1a; font-weight: 700; border: none;
        border-radius: 10px; padding: .65rem 2rem;
        font-size: .95rem; cursor: pointer; transition: all .18s;
    }
    .btn-gold:hover { background: linear-gradient(135deg,#f0c660,#e8b84b); transform: translateY(-1px); }
    .btn-gold:disabled { opacity: .45; pointer-events: none; }

    /* 3-Spalten-Layout (wie JgJ) */
    .zd-3col   { display: flex; gap: 20px; align-items: flex-start; padding: 0 5%; }
    .zd-side   { flex: 0 0 380px; min-width: 0; display: flex; flex-direction: column; overflow: hidden; }
    .zd-center { flex: 1; min-width: 0; }
    @media (max-width:1200px) {
        .zd-3col { padding: 0 2%; gap: 12px; }
        .zd-3col > div:nth-child(1) { flex: 0 0 300px; }
        .zd-3col > div:nth-child(3) { display: none !important; }
    }
    @media (max-width:800px) {
        .zd-side { display: none !important; }
        .zd-center { flex: 0 0 100%; }
    }

    /* Sidebar-Wrapper (wie turnier-ranking-wrap in JgJ) */
    .zd-ranking-wrap {
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 12px; overflow: hidden;
        position: sticky; top: 90px;
        max-height: calc(100vh - 110px);
        display: flex; flex-direction: column;
    }
    .zd-ranking-header {
        background: rgba(232,184,75,.08);
        border-bottom: 1px solid rgba(232,184,75,.15);
        padding: .7rem 1rem; flex-shrink: 0;
        color: #e8b84b; font-weight: 700;
        font-size: .82rem; text-transform: uppercase; letter-spacing: .05em;
    }
    .zd-ranking-list { overflow-y: auto; flex: 1; }

    /* Ranking-Zeilen */
    .zd-rank-row { display: flex; align-items: center; gap: .55rem; padding: .4rem .8rem; border-bottom: 1px solid rgba(255,255,255,.04); transition: background .12s; }
    .zd-rank-row:last-child { border-bottom: none; }
    .zd-rank-row:hover { background: rgba(232,184,75,.05); }
    .zd-rank-row.active-film { background: rgba(232,184,75,.1); }
    .zd-rank-num { width: 22px; text-align: center; font-size: .8rem; font-weight: 700; color: rgba(255,255,255,.4); flex-shrink: 0; }
    .zd-rank-num.top { color: #e8b84b; }
    .zd-rank-poster { width: 26px; height: 39px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
    .zd-rank-title { font-size: .78rem; color: #d0d0d0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }

    /* Statistik-Sidebar */
    .duel-stat-section { padding: .65rem .9rem; border-bottom: 1px solid rgba(255,255,255,.05); }
    .duel-stat-section:last-child { border-bottom: none; }
    .duel-stat-lbl { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: rgba(232,184,75,.65); margin-bottom: .45rem; }
    .duel-stat-result { font-size: .82rem; line-height: 1.5; color: #ccc; }
    .duel-stat-result .rank-up { color: #5cb85c; font-weight: 700; }
    .duel-stat-result .no-change { opacity: .5; font-size: .75rem; display: block; margin-top: .15rem; }
    .duel-comm-film { display: flex; align-items: center; gap: .45rem; padding: .2rem 0; font-size: .82rem; color: #ccc; overflow: hidden; }
    .duel-comm-rank { background: rgba(232,184,75,.12); border: 1px solid rgba(232,184,75,.25); border-radius: 4px; padding: 0 5px; font-size: .72rem; font-weight: 700; color: #e8b84b; flex-shrink: 0; min-width: 28px; text-align: center; }
    .duel-comm-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .duel-stat-empty { font-size: .78rem; color: rgba(255,255,255,.3); }
    .ctx-row { display: flex; align-items: center; gap: .45rem; padding: .18rem 0; font-size: .8rem; color: #bbb; overflow: hidden; }
    .ctx-row.is-winner { color: #fff; font-weight: 700; background: rgba(232,184,75,.08); border-radius: 4px; padding: .18rem .35rem; margin: 0 -.35rem; }
    .ctx-pos { min-width: 28px; text-align: right; font-size: .72rem; font-weight: 700; flex-shrink: 0; opacity: .5; }
    .ctx-row.is-winner .ctx-pos { opacity: 1; color: #e8b84b; }
    .ctx-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .duel-info-link { display: block; text-align: center; padding: .1rem .7rem .5rem; color: rgba(255,255,255,.2); font-size: .72rem; text-decoration: none; line-height: 1.6; }
    .duel-info-link:hover { color: #e8b84b; }

    /* Duel-Arena — identisch JgJ */
    .duel-arena { display: flex; align-items: stretch; margin-bottom: 1.5rem; }
    @media (max-width:600px) { .duel-arena { flex-direction: column; } }

    .duel-side { flex: 1; cursor: pointer; display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,.03); padding: 20px 16px; transition: background .2s; position: relative; overflow: hidden; }
    @media (hover: hover) { .duel-side:hover { background: rgba(232,184,75,.1); } .duel-side:hover .duel-overlay { opacity: 1; } }
    .duel-side.winner { background: rgba(232,184,75,.18) !important; }
    .duel-side.loser  { opacity: .4; }

    .duel-poster-wrap { position: relative; }
    .duel-poster { width: 100%; aspect-ratio: 2/3; object-fit: cover; display: block; transition: opacity .2s ease-in; }
    .duel-overlay { position: absolute; inset: 0; background: rgba(232,184,75,.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .2s; color: #e8b84b; font-size: 2rem; }
    .duel-title { color: #e0e0e0; font-weight: 600; font-size: .95rem; text-align: center; margin-top: 14px; margin-bottom: 4px; }
    .duel-meta  { color: rgba(255,255,255,.4); font-size: .8rem; text-align: center; }

    .vs-divider { display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative; padding: 0 8px; background: rgba(0,0,0,.3); min-width: 48px; }
    .vs-divider #undo-btn { position: absolute; bottom: 2.5rem; }
    .vs-circle  { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a; font-weight: 800; font-size: .85rem; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }

    /* Counter */
    .duel-counter {
        background: rgba(255,255,255,.05);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 10px; padding: .6rem 1.2rem;
        display: inline-flex; align-items: center; gap: .5rem;
        color: rgba(255,255,255,.5); font-size: .85rem;
    }
    .duel-counter strong { color: #e8b84b; font-size: 1.1rem; }

    /* Session-Info-Badge */
    .session-badge {
        background: rgba(232,184,75,.08);
        border: 1px solid rgba(232,184,75,.2);
        border-radius: 8px; padding: .4rem .9rem;
        font-size: .8rem; color: rgba(255,255,255,.5);
        display: inline-flex; align-items: center; gap: .4rem;
    }
    .session-badge span { color: #e8b84b; font-weight: 700; }

    ::-webkit-scrollbar { width: 4px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 2px; }
</style>

<main style="padding-top:6px; background:#14325a; min-height:100vh;">

    <!-- Hero -->
    <section style="background:linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom:1px solid rgba(232,184,75,.15); padding:1.5rem 0;">
        <div class="container-xxl zd-wrap">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1 class="fw-bold mb-1" style="color:#e8b84b; font-size:1.6rem;">
                        <i class="bi bi-shuffle me-2"></i>Zufallsduelle
                    </h1>
                    <p class="mb-0" style="color:rgba(255,255,255,.45); font-size:.9rem;">
                        Zufällige <?= $mtActive === 'tv' ? 'Serienpaare' : 'Filmpaare' ?> bewerten – jedes Duell fließt in Meine Rangliste ein
                    </p>
                </div>
                <?php if ($isActive): ?>
                <div class="text-end">
                    <div id="hero-count" style="color:#e8b84b; font-size:1.5rem; font-weight:800; line-height:1;"><?= $duelsDone ?></div>
                    <div style="color:rgba(255,255,255,.4); font-size:.75rem;">Duelle absolviert</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="py-4">
        <div class="container-xxl zd-wrap">

<?php if (!$isActive): ?>
<!-- ── SETUP ──────────────────────────────────────────────────────────────────── -->

<?php if (isset($_GET['error'])): ?>
<div class="alert alert-danger py-2 mb-4">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Zu wenig Filme im gewählten Bereich. Bitte einen größeren Bereich wählen.
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-7">
<div style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.1); border-radius:16px; padding:2rem;">

    <h4 class="fw-bold mb-3" style="color:#e0e0e0;">
        <i class="bi bi-play-circle me-2" style="color:#e8b84b;"></i>Neue Duel-Session starten
    </h4>

    <form method="post" id="setup-form">
        <input type="hidden" name="action"     value="start">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

        <!-- Quelle wählen -->
        <div class="mb-4">
            <label style="color:rgba(255,255,255,.6); font-size:.85rem; font-weight:600; display:block; margin-bottom:.75rem;">
                Filmquelle wählen
            </label>
            <div class="d-flex flex-column gap-2" id="source-list">

                <?php foreach ($sourceInfo as $srcKey => $src): ?>
                <?php $cnt = $sourceCounts[$srcKey]; ?>
                <label class="source-card <?= $srcKey === 'my_ranking' ? 'selected' : '' ?>"
                       data-source="<?= $srcKey ?>"
                       data-count="<?= $cnt ?>">
                    <input type="radio" name="source" value="<?= $srcKey ?>"
                           <?= $srcKey === 'my_ranking' ? 'checked' : '' ?>
                           style="display:none;">
                    <div class="src-icon"><i class="bi <?= $src['icon'] ?>"></i></div>
                    <div style="flex:1; min-width:0;">
                        <div style="color:#e0e0e0; font-weight:700; font-size:.92rem;"><?= $src['label'] ?></div>
                        <div style="color:rgba(255,255,255,.35); font-size:.78rem;">
                            <?= $cnt ?> <?php
                                if ($mtActive === 'tv') echo $cnt === 1 ? 'Serie' : 'Serien';
                                else echo $cnt === 1 ? 'Film' : 'Filme';
                            ?> verfügbar
                        </div>
                    </div>
                    <?php if ($srcKey === 'my_ranking'): ?>
                    <div style="color:rgba(232,184,75,.6); font-size:.75rem; white-space:nowrap;">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <?php endif; ?>
                </label>
                <?php endforeach; ?>

            </div>
        </div>

        <!-- Von/Bis -->
        <div class="mb-4">
            <label style="color:rgba(255,255,255,.6); font-size:.85rem; font-weight:600; display:block; margin-bottom:.75rem;">
                Bereich <span id="range-hint" style="color:rgba(255,255,255,.3); font-weight:400;">
                    (Plätze 1 – <?= $countMyRanking ?>)
                </span>
            </label>
            <div class="d-flex gap-3 align-items-center">
                <div style="flex:1;">
                    <div style="color:rgba(255,255,255,.4); font-size:.75rem; margin-bottom:.3rem;">Von Platz</div>
                    <input type="number" name="range_from" id="range-from" class="form-ctrl"
                           value="1" min="1" max="<?= $countMyRanking ?>" step="1">
                </div>
                <div style="color:rgba(255,255,255,.3); font-size:1.2rem; padding-top:1.4rem;">–</div>
                <div style="flex:1;">
                    <div style="color:rgba(255,255,255,.4); font-size:.75rem; margin-bottom:.3rem;">Bis Platz</div>
                    <input type="number" name="range_to" id="range-to" class="form-ctrl"
                           value="<?= min(50, $countMyRanking) ?>" min="2" max="<?= $countMyRanking ?>" step="1">
                </div>
            </div>
            <div id="pool-hint" style="color:rgba(232,184,75,.55); font-size:.8rem; margin-top:.5rem;">
                <i class="bi bi-info-circle me-1"></i>
                <span id="pool-count"><?= min(50, $countMyRanking) ?></span> Filme im Pool
            </div>
        </div>

        <div id="setup-error" class="d-none mb-3"
             style="background:rgba(244,67,54,.1); border:1px solid rgba(244,67,54,.25); color:#ef9a9a; border-radius:8px; padding:12px 16px; font-size:.88rem;"></div>

        <button type="button" id="start-btn" class="btn-gold w-100">
            <span id="start-label"><i class="bi bi-shuffle me-2"></i>Duel-Session starten</span>
            <span id="start-spinner" class="d-none">
                <span class="spinner-border spinner-border-sm me-2"></span>Wird gestartet …
            </span>
        </button>
    </form>

</div>
</div>
</div>

<?php else: ?>
<!-- ── AKTIVE SESSION ─────────────────────────────────────────────────────────── -->
<?php
    $aUrl   = moviePosterUrl($currentA ?? [], 'w342');
    $bUrl   = moviePosterUrl($currentB ?? [], 'w342');
    $aTitle = movieTitle($currentA ?? []);
    $bTitle = movieTitle($currentB ?? []);
    $initCommRanks = buildCommRanks($db, (int)$currentA['id'], $aTitle,
                                        (int)$currentB['id'], $bTitle, $userId, $mtActive);
?>

<p class="text-center mb-3" style="color:rgba(255,255,255,.4); font-size:clamp(1.4rem,2.5vw,2.5rem); white-space:nowrap; overflow:hidden;">
    <?= $mtActive === 'tv' ? 'Welche Serie schaust du dir lieber an?' : 'Welchen Film schaust du dir lieber an?' ?>
</p>

<div class="zd-3col">

    <!-- ── Links: Meine Rangliste ───────────────────────────────────────────── -->
    <div class="zd-side">
        <div class="zd-ranking-wrap">
            <div class="zd-ranking-header">
                <i class="bi bi-list-ol me-1"></i><?= $mtActive === 'tv' ? 'Meine Rangliste Serien' : ($mtActive === 'movie' ? 'Meine Rangliste Filme' : 'Meine Rangliste') ?>
            </div>
            <div class="zd-ranking-list" id="pos-ranking-list">
                <?php foreach ($posRanking as $r): ?>
                <div class="zd-rank-row" data-film-id="<?= (int)$r['id'] ?>"
                     data-overview="<?= e($r['overview'] ?? '') ?>"
                     data-overview-title="<?= e(movieTitle($r)) ?>">
                    <span class="zd-rank-num <?= (int)$r['pos'] <= 3 ? 'top' : '' ?>"><?= (int)$r['pos'] ?></span>
                    <img src="<?= e(moviePosterUrl($r, 'w92')) ?>" alt="" class="zd-rank-poster" width="26" height="39" loading="lazy">
                    <span class="zd-rank-title">
                        <a href="/film.php?id=<?= (int)$r['id'] ?>" style="color:inherit; text-decoration:none;" target="_blank"><?= e(movieTitle($r)) ?></a>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($posRanking)): ?>
                <div style="padding:.8rem; font-size:.78rem; color:rgba(255,255,255,.3);">Noch keine Rangliste</div>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-center mt-2" style="font-size:.72rem;">
            <a href="/rangliste.php?tab=persoenlich" style="color:rgba(232,184,75,.5); text-decoration:none;">
                Alle <?= count($posRanking) ?> <?= $mtActive === 'tv' ? 'Serien' : 'Filme' ?> →
            </a>
        </p>
    </div>

    <!-- ── Mitte: Duel-Arena ────────────────────────────────────────────────── -->
    <div class="zd-center">

        <!-- Session-Info + Stop -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="session-badge">
                    <i class="bi bi-<?= $sourceInfo[$activeSource]['icon'] ?>"></i>
                    <?= $sourceInfo[$activeSource]['label'] ?>
                    &nbsp;·&nbsp; Platz <span><?= $activeFrom ?> – <?= $activeTo ?></span>
                    &nbsp;·&nbsp; <span><?= $activePool ?></span> <?= $mtActive === 'tv' ? 'Serien' : 'Filme' ?>
                </span>
                <div class="duel-counter">
                    <i class="bi bi-lightning-charge-fill" style="color:#e8b84b;"></i>
                    <strong id="duels-done"><?= $duelsDone ?></strong> Duelle
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <form method="post" onsubmit="return confirm('Session beenden?');" style="margin:0;">
                    <input type="hidden" name="action"     value="stop">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <button class="btn btn-link p-0 text-decoration-none"
                            style="color:rgba(255,255,255,.3); font-size:.8rem;">
                        <i class="bi bi-x-circle me-1"></i>Session beenden
                    </button>
                </form>
            </div>
        </div>

        <!-- Duel-Arena -->
        <div id="duel-arena" class="duel-arena">
            <div class="duel-side" id="movie-a" data-id="<?= (int)$currentA['id'] ?>"
                 data-overview="<?= e($currentA['overview'] ?? '') ?>"
                 data-overview-title="<?= e($aTitle) ?>">
                <div class="duel-poster-wrap">
                    <img class="duel-poster" fetchpriority="high" decoding="async" src="<?= e($aUrl) ?>" alt="<?= e($aTitle) ?>"
                         onerror="this.src='/assets/no-poster.svg'">
                    <div class="duel-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
                </div>
                <div class="duel-title"><?= e($aTitle) ?></div>
                <div class="duel-meta"><?= (int)$currentA['year'] ?></div>
                <a href="/film.php?id=<?= (int)$currentA['id'] ?>" class="duel-info-link" target="_blank" onclick="event.stopPropagation()"><i class="bi bi-info-circle me-1"></i>Details</a>
            </div>

            <div class="vs-divider">
                <div class="vs-circle">VS</div>
                <button id="undo-btn"
                        title="Letztes Duell rückgängig"
                        style="background:none; border:none; padding:0; cursor:pointer;
                               color:rgba(255,255,255,.3); font-size:1.35rem; line-height:1;
                               transition:color .15s;"
                        onmouseover="if(!this.disabled)this.style.color='rgba(232,184,75,.7)'"
                        onmouseout="this.style.color='rgba(255,255,255,.3)'">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>
            </div>

            <div class="duel-side" id="movie-b" data-id="<?= (int)$currentB['id'] ?>"
                 data-overview="<?= e($currentB['overview'] ?? '') ?>"
                 data-overview-title="<?= e($bTitle) ?>">
                <div class="duel-poster-wrap">
                    <img class="duel-poster" fetchpriority="high" decoding="async" src="<?= e($bUrl) ?>" alt="<?= e($bTitle) ?>"
                         onerror="this.src='/assets/no-poster.svg'">
                    <div class="duel-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
                </div>
                <div class="duel-title"><?= e($bTitle) ?></div>
                <div class="duel-meta"><?= (int)$currentB['year'] ?></div>
                <a href="/film.php?id=<?= (int)$currentB['id'] ?>" class="duel-info-link" target="_blank" onclick="event.stopPropagation()"><i class="bi bi-info-circle me-1"></i>Details</a>
            </div>
        </div>

    </div><!-- /.zd-center -->

    <!-- ── Rechts: Statistiken ──────────────────────────────────────────────── -->
    <div class="zd-side">
        <div class="zd-ranking-wrap">
            <div class="zd-ranking-header">
                <i class="bi bi-bar-chart-fill me-1"></i>Statistiken
            </div>
            <!-- Letztes Duell -->
            <div class="duel-stat-section" id="last-duel-stat">
                <div class="duel-stat-lbl">Letztes Duell</div>
                <div class="duel-stat-empty">Noch kein Duell bewertet</div>
            </div>
            <?php $rankSfx = $mtActive === 'tv' ? ' Serien' : ($mtActive === 'movie' ? ' Filme' : ''); ?>
            <!-- Community-Kontext um den Sieger (±2 Plätze) -->
            <div class="duel-stat-section" id="comm-context-stat" style="display:none;">
                <div class="duel-stat-lbl">Community Rangliste<?= $rankSfx ?></div>
            </div>
            <!-- Meine-Rangliste-Kontext um den Sieger (±2 Plätze) -->
            <div class="duel-stat-section" id="my-context-stat" style="display:none;">
                <div class="duel-stat-lbl">Meine Rangliste<?= $rankSfx ?></div>
            </div>
            <!-- Community-Ranking der aktuellen Duell-Filme -->
            <div class="duel-stat-section" id="comm-rank-stat">
                <div class="duel-stat-lbl">Community Ranking<?= $rankSfx ?></div>
                <?php if ($initCommRanks): ?>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank"><?= $initCommRanks['a_rank'] ? '#'.$initCommRanks['a_rank'] : '–' ?></span>
                    <span class="duel-comm-title"><?= e($initCommRanks['a_title']) ?></span>
                </div>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank"><?= $initCommRanks['b_rank'] ? '#'.$initCommRanks['b_rank'] : '–' ?></span>
                    <span class="duel-comm-title"><?= e($initCommRanks['b_title']) ?></span>
                </div>
                <?php else: ?>
                <div class="duel-stat-empty">Keine Community-Daten</div>
                <?php endif; ?>
            </div>
            <!-- Meine Rangliste: aktuelle Positionen der Duell-Filme -->
            <div class="duel-stat-section" id="my-rank-stat">
                <div class="duel-stat-lbl">Meine Rangliste<?= $rankSfx ?></div>
                <?php if ($initCommRanks): ?>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank" style="background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.15); color:rgba(255,255,255,.7);"><?= $initCommRanks['a_my_pos'] !== null ? '#'.$initCommRanks['a_my_pos'] : '–' ?></span>
                    <span class="duel-comm-title"><?= e($initCommRanks['a_title']) ?></span>
                </div>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank" style="background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.15); color:rgba(255,255,255,.7);"><?= $initCommRanks['b_my_pos'] !== null ? '#'.$initCommRanks['b_my_pos'] : '–' ?></span>
                    <span class="duel-comm-title"><?= e($initCommRanks['b_title']) ?></span>
                </div>
                <?php else: ?>
                <div class="duel-stat-empty">Noch nicht bewertet</div>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-center mt-2" style="font-size:.72rem; color:rgba(255,255,255,.3);">
            <?= $activePool ?> <?= $mtActive === 'tv' ? 'Serien' : 'Filme' ?> im Pool
        </p>
    </div>

</div><!-- /.zd-3col -->
<?php endif; ?>

        </div>
    </section>
</main>

<!-- ── JavaScript ─────────────────────────────────────────────────────────────── -->
<?php if (!$isActive): ?>
<script>
(function () {
    const sourceCounts = <?= json_encode($sourceCounts) ?>;
    const cards  = document.querySelectorAll('.source-card');
    const fromEl = document.getElementById('range-from');
    const toEl   = document.getElementById('range-to');
    const hint   = document.getElementById('range-hint');
    const poolCt = document.getElementById('pool-count');
    const errEl  = document.getElementById('setup-error');
    const startB = document.getElementById('start-btn');
    const startL = document.getElementById('start-label');
    const startS = document.getElementById('start-spinner');
    const form   = document.getElementById('setup-form');

    let currentMax = sourceCounts['my_ranking'];

    function updateRange(src) {
        currentMax = sourceCounts[src] || 0;
        fromEl.max = currentMax;
        toEl.max   = currentMax;
        hint.textContent = `(Plätze 1\u2009–\u2009${currentMax})`;
        fromEl.value = 1;
        toEl.value   = Math.min(50, currentMax);
        updatePoolHint();
    }

    function updatePoolHint() {
        const f = Math.max(1, parseInt(fromEl.value) || 1);
        const t = Math.max(f, parseInt(toEl.value)   || f);
        const n = Math.min(t, currentMax) - Math.min(f, currentMax) + 1;
        poolCt.textContent = Math.max(0, n);
    }

    cards.forEach(card => {
        card.addEventListener('click', () => {
            cards.forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            card.querySelector('input[type=radio]').checked = true;
            updateRange(card.dataset.source);
        });
    });

    fromEl.addEventListener('input', updatePoolHint);
    toEl.addEventListener('input', updatePoolHint);

    startB.addEventListener('click', () => {
        const src = document.querySelector('input[name=source]:checked')?.value;
        const max = sourceCounts[src] || 0;
        const f   = parseInt(fromEl.value) || 1;
        const t   = parseInt(toEl.value)   || 2;

        errEl.classList.add('d-none');

        if (max < 2) {
            errEl.textContent = 'Diese Quelle enthält zu wenig Filme.';
            errEl.classList.remove('d-none'); return;
        }
        if (f < 1 || t < f + 1) {
            errEl.textContent = '"Von" muss kleiner als "Bis" sein.';
            errEl.classList.remove('d-none'); return;
        }
        if (t - f < 1) {
            errEl.textContent = 'Bitte mindestens 2 Filme im Bereich.';
            errEl.classList.remove('d-none'); return;
        }

        startL.classList.add('d-none');
        startS.classList.remove('d-none');
        startB.disabled = true;
        form.submit();
    });
})();
</script>
<?php else: ?>
<script>
const CSRF_TOKEN      = <?= json_encode(csrfToken()) ?>;
const IMG_BASE        = <?= json_encode(rtrim(TMDB_IMAGE_BASE, '/')) ?>;
const INIT_COMM_RANKS = <?= json_encode($initCommRanks ?? null) ?>;
const INIT_LAST_DUEL  = <?= json_encode($initLastDuel) ?>;

function updateHdrCounters(totalDuels, uniqueFilms) {
    const dc = document.getElementById('hdr-duels-count');
    const fc = document.getElementById('hdr-films-count');
    if (dc && totalDuels !== undefined) dc.textContent = totalDuels.toLocaleString('de-DE');
    if (fc && uniqueFilms !== undefined) fc.textContent = uniqueFilms;
}

(function () {
    const arena    = document.getElementById('duel-arena');
    const posList  = document.getElementById('pos-ranking-list');
    const heroCnt  = document.getElementById('hero-count');
    const duelsCnt = document.getElementById('duels-done');
    const undoBtn  = document.getElementById('undo-btn');

    let voting = false;
    let timer  = null;

    // Letztes Duell für Undo merken (beim Laden aus DB vorbelegen)
    let lastDuel = INIT_LAST_DUEL;
    if (undoBtn) undoBtn.disabled = !lastDuel;

    function setVoting(v) {
        voting = v;
        clearTimeout(timer);
        if (v) timer = setTimeout(() => { voting = false; }, 6000);
    }

    function posterSrc(p) {
        if (!p) return '/assets/no-poster.svg';
        if (p.startsWith('http') || p.startsWith('/')) return p;
        return IMG_BASE + p;
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function setCard(side, m) {
        side.querySelector('.duel-poster').src = posterSrc(m.poster);
        side.querySelector('.duel-poster').alt = m.title;
        side.querySelector('.duel-title').textContent = m.title;
        side.querySelector('.duel-meta').textContent  = m.year || '';
        side.dataset.id            = m.id;
        side.dataset.overview      = m.overview || '';
        side.dataset.overviewTitle = m.title;
        side.classList.remove('winner', 'loser');
        const infoLink = side.querySelector('.duel-info-link');
        if (infoLink) infoLink.href = '/film.php?id=' + m.id;
    }

    function highlightRankingRows(idA, idB) {
        const posList = document.getElementById('pos-ranking-list');
        let firstMatch = null;
        document.querySelectorAll('#pos-ranking-list .zd-rank-row').forEach(row => {
            const fid = parseInt(row.dataset.filmId);
            row.classList.toggle('active-film', fid === idA || fid === idB);
            if ((fid === idA || fid === idB) && !firstMatch) firstMatch = row;
        });
        // Scroll only within the sidebar container — never scroll the page
        if (posList && firstMatch) {
            const listH  = posList.clientHeight;
            const rowTop = firstMatch.offsetTop - posList.offsetTop;
            const target = rowTop - listH / 2 + firstMatch.offsetHeight / 2;
            posList.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
        }
    }

    const PH = 'https://placehold.co/26x39/1e3a5f/e8b84b?text=?';

    function renderContextSection(secId, labelText, rows, winnerId) {
        const sec = document.getElementById(secId);
        if (!sec || !rows || !rows.length) { if (sec) sec.style.display = 'none'; return; }
        sec.style.display = '';
        sec.innerHTML = '<div class="duel-stat-lbl">' + labelText + '</div>'
            + rows.map(r => {
                const win = r.id === winnerId;
                return '<div class="ctx-row' + (win ? ' is-winner' : '') + '">'
                    + '<span class="ctx-pos">#' + r.pos + '</span>'
                    + '<span class="ctx-title">' + escHtml(r.title) + '</span></div>';
            }).join('');
    }

    const RANK_SFX = <?= json_encode($rankSfx) ?>;

    function updateDuelStats(duelResult, winnerContext, commRanks) {
        const lastSec = document.getElementById('last-duel-stat');
        if (lastSec && duelResult) {
            const wT = duelResult.winner_title, lT = duelResult.loser_title;
            const wP = duelResult.winner_old_pos, lP = duelResult.loser_old_pos;
            const rankStr = p => p ? ' <span style="opacity:.45;font-size:.78rem;">(#' + p + ')</span>' : '';
            let html;
            if (duelResult.rank_changed) {
                html = '<div class="duel-stat-result"><span class="rank-up">' + escHtml(wT) + '</span>' + rankStr(wP)
                     + ' übernimmt Platz von <strong>' + escHtml(lT) + '</strong>' + rankStr(lP) + '</div>';
            } else {
                html = '<div class="duel-stat-result"><strong>' + escHtml(wT) + '</strong>' + rankStr(wP)
                     + ' besiegt <strong>' + escHtml(lT) + '</strong>' + rankStr(lP)
                     + '<span class="no-change">Rangliste unverändert</span></div>';
            }
            lastSec.innerHTML = '<div class="duel-stat-lbl">Letztes Duell</div>' + html;
        }
        if (winnerContext) {
            renderContextSection('comm-context-stat', 'Community Rangliste' + RANK_SFX,
                winnerContext.comm, winnerContext.winner_id);
            renderContextSection('my-context-stat', 'Meine Rangliste' + RANK_SFX,
                winnerContext.mine, winnerContext.winner_id);
        }
        const commSec = document.getElementById('comm-rank-stat');
        if (commSec && commRanks) {
            const row = (rank, title) => '<div class="duel-comm-film">'
                + '<span class="duel-comm-rank">' + (rank ? '#' + rank : '–') + '</span>'
                + '<span class="duel-comm-title">' + escHtml(title) + '</span></div>';
            commSec.innerHTML = '<div class="duel-stat-lbl">Community Ranking' + RANK_SFX + '</div>'
                + row(commRanks.a_rank, commRanks.a_title)
                + row(commRanks.b_rank, commRanks.b_title);
        }
        const myRankSec = document.getElementById('my-rank-stat');
        if (myRankSec && commRanks) {
            const myStyle = 'background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.15);color:rgba(255,255,255,.7);';
            const myRow = (pos, title) => '<div class="duel-comm-film">'
                + '<span class="duel-comm-rank" style="' + myStyle + '">' + (pos !== null && pos !== undefined ? '#' + pos : '–') + '</span>'
                + '<span class="duel-comm-title">' + escHtml(title) + '</span></div>';
            myRankSec.innerHTML = '<div class="duel-stat-lbl">Meine Rangliste' + RANK_SFX + '</div>'
                + myRow(commRanks.a_my_pos, commRanks.a_title)
                + myRow(commRanks.b_my_pos, commRanks.b_title);
        }
    }

    // Initiale Community-Ränge anzeigen
    if (INIT_COMM_RANKS) updateDuelStats(null, null, INIT_COMM_RANKS);

    // Klick
    arena.addEventListener('click', e => {
        const side = e.target.closest('.duel-side');
        if (!side || voting) return;
        const other = side.id === 'movie-a'
            ? document.getElementById('movie-b')
            : document.getElementById('movie-a');
        castVote(parseInt(side.dataset.id), parseInt(other.dataset.id));
    });

    // Pfeiltasten
    document.addEventListener('keydown', e => {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();
        if (voting) return;
        const wId = parseInt(document.getElementById(e.key === 'ArrowLeft' ? 'movie-a' : 'movie-b').dataset.id);
        const lId = parseInt(document.getElementById(e.key === 'ArrowLeft' ? 'movie-b' : 'movie-a').dataset.id);
        castVote(wId, lId);
    });

    async function castVote(winnerId, loserId) {
        setVoting(true);
        if (undoBtn) undoBtn.disabled = true;
        const sideA = document.getElementById('movie-a');
        const sideB = document.getElementById('movie-b');
        const winSide  = parseInt(sideA.dataset.id) === winnerId ? sideA : sideB;
        const loseSide = winSide === sideA ? sideB : sideA;

        // Aktuelles Paar merken bevor Karten getauscht werden
        lastDuel = {
            aId: parseInt(sideA.dataset.id), aTitle: sideA.querySelector('.duel-title').textContent,
            aYear: sideA.querySelector('.duel-meta').textContent,
            aPoster: sideA.querySelector('.duel-poster').src,
            aOverview: sideA.dataset.overview,
            bId: parseInt(sideB.dataset.id), bTitle: sideB.querySelector('.duel-title').textContent,
            bYear: sideB.querySelector('.duel-meta').textContent,
            bPoster: sideB.querySelector('.duel-poster').src,
            bOverview: sideB.dataset.overview,
        };

        winSide.classList.add('winner');
        loseSide.classList.add('loser');

        const fd = new FormData();
        fd.append('action',     'vote');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('winner_id',  winnerId);
        fd.append('loser_id',   loserId);

        try {
            const res  = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();

            if (!data.ok) { location.reload(); return; }

            // Counter aktualisieren
            if (heroCnt)  heroCnt.textContent  = data.duelsDone;
            if (duelsCnt) duelsCnt.textContent = data.duelsDone;
            updateHdrCounters(data.hdrDuels, data.hdrFilms);

            // Statistik-Sidebar aktualisieren
            updateDuelStats(data.duel_result, data.winner_context, data.comm_ranks);

            // Nächstes Duel einblenden
            setTimeout(() => {
                try {
                    const n = data.next;
                    setCard(sideA, { id: n.a_id, title: n.a_title, year: n.a_year, poster: n.a_poster, overview: n.a_overview });
                    setCard(sideB, { id: n.b_id, title: n.b_title, year: n.b_year, poster: n.b_poster, overview: n.b_overview });
                    highlightRankingRows(n.a_id, n.b_id);
                    if (undoBtn) undoBtn.disabled = false;
                } finally {
                    setVoting(false);
                }
            }, 320);

        } catch {
            location.reload();
        }
    }

    if (undoBtn) {
        undoBtn.addEventListener('click', async () => {
            if (voting || !lastDuel) return;
            setVoting(true);
            undoBtn.disabled = true;

            const fd = new FormData();
            fd.append('action',     'undo');
            fd.append('csrf_token', CSRF_TOKEN);

            try {
                const res  = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.ok) { setVoting(false); return; }

                // Karten auf letztes Duell zurücksetzen
                const sideA = document.getElementById('movie-a');
                const sideB = document.getElementById('movie-b');
                setCard(sideA, { id: lastDuel.aId, title: lastDuel.aTitle, year: lastDuel.aYear, poster: lastDuel.aPoster, overview: lastDuel.aOverview });
                setCard(sideB, { id: lastDuel.bId, title: lastDuel.bTitle, year: lastDuel.bYear, poster: lastDuel.bPoster, overview: lastDuel.bOverview });
                highlightRankingRows(lastDuel.aId, lastDuel.bId);

                if (heroCnt)  heroCnt.textContent  = data.duelsDone;
                if (duelsCnt) duelsCnt.textContent = data.duelsDone;
                updateHdrCounters(data.hdrDuels, data.hdrFilms);

                lastDuel = null;
            } catch {
                location.reload();
            } finally {
                setVoting(false);
            }
        });
    }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
