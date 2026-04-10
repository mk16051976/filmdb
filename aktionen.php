<?php
set_time_limit(300);
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── DB Schema sicherstellen ────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS action_lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL, description TEXT NULL,
    start_date DATE NOT NULL, end_date DATE NOT NULL,
    created_by INT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS action_list_films (
    list_id INT UNSIGNED NOT NULL, movie_id INT UNSIGNED NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (list_id, movie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS action_list_duels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
    movie_a_id INT UNSIGNED NOT NULL, movie_b_id INT UNSIGNED NOT NULL,
    winner_id INT UNSIGNED NOT NULL, round TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_list_user (list_id, user_id),
    UNIQUE KEY uq_match_round (list_id, user_id, movie_a_id, movie_b_id, round)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS action_list_rankings (
    list_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
    movie_id INT UNSIGNED NOT NULL, position INT UNSIGNED NOT NULL,
    wins INT UNSIGNED NOT NULL DEFAULT 0, losses INT UNSIGNED NOT NULL DEFAULT 0,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (list_id, user_id, movie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Liste laden + prüfen ───────────────────────────────────────────────────────
$listId = (int)($_GET['list'] ?? 0);
if (!$listId) { header('Location: /index.php'); exit; }

$lstStmt = $db->prepare("SELECT * FROM action_lists WHERE id = ?");
$lstStmt->execute([$listId]);
$actionList = $lstStmt->fetch(PDO::FETCH_ASSOC);

if (!$actionList) { header('Location: /index.php'); exit; }

// Zeitraum-Check (nur Admins dürfen außerhalb des Zeitraums spielen)
$today = date('Y-m-d');
if (!isAdmin() && ($today < $actionList['start_date'] || $today > $actionList['end_date'])) {
    header('Location: /index.php?aktion_inaktiv=1');
    exit;
}

$pageTitle = e($actionList['name']) . ' – MKFB';

// ── Helper-Funktionen ──────────────────────────────────────────────────────────
function aktFilmCount(PDO $db, int $listId): int {
    $s = $db->prepare("SELECT COUNT(*) FROM action_list_films WHERE list_id = ?");
    $s->execute([$listId]);
    return (int)$s->fetchColumn();
}

function aktTotalMatches(int $n): int {
    return $n > 1 ? (int)($n * ($n - 1) / 2) : 0; // 1x JgJ: jedes Paar spielt genau 1x
}

function aktDoneCount(PDO $db, int $listId, int $userId): int {
    $s = $db->prepare("
        SELECT COUNT(*) FROM (
            SELECT DISTINCT d.movie_a_id, d.movie_b_id
            FROM action_list_duels d
            INNER JOIN action_list_films fa ON fa.list_id = d.list_id AND fa.movie_id = d.movie_a_id
            INNER JOIN action_list_films fb ON fb.list_id = d.list_id AND fb.movie_id = d.movie_b_id
            WHERE d.list_id = ? AND d.user_id = ?
        ) t");
    $s->execute([$listId, $userId]);
    return (int)$s->fetchColumn();
}

function aktNextDuel(PDO $db, int $listId, int $userId, array $excludeIds = []): ?array {
    // Alle Paare und wie oft sie gespielt wurden
    $s = $db->prepare("
        SELECT
            a.movie_id AS a_id, b.movie_id AS b_id,
            ma.title AS a_title, ma.title_en AS a_title_en, ma.year AS a_year, ma.poster_path AS a_poster, ma.poster_path_en AS a_poster_en,
            mb.title AS b_title, mb.title_en AS b_title_en, mb.year AS b_year, mb.poster_path AS b_poster, mb.poster_path_en AS b_poster_en,
            COALESCE(d_count.played, 0) AS played,
            COALESCE(pa.position, 999999) AS pos_a,
            COALESCE(pb.position, 999999) AS pos_b
        FROM action_list_films a
        JOIN action_list_films b
            ON b.list_id = a.list_id AND b.movie_id > a.movie_id
        JOIN movies ma ON ma.id = a.movie_id
        JOIN movies mb ON mb.id = b.movie_id
        LEFT JOIN (
            SELECT movie_a_id, movie_b_id, COUNT(*) AS played
            FROM action_list_duels
            WHERE list_id = ? AND user_id = ?
            GROUP BY movie_a_id, movie_b_id
        ) d_count ON d_count.movie_a_id = a.movie_id AND d_count.movie_b_id = b.movie_id
        LEFT JOIN user_position_ranking pa ON pa.user_id = ? AND pa.movie_id = a.movie_id
        LEFT JOIN user_position_ranking pb ON pb.user_id = ? AND pb.movie_id = b.movie_id
        WHERE a.list_id = ? AND COALESCE(d_count.played, 0) < 1
        LIMIT 300
    ");
    $s->execute([$listId, $userId, $userId, $userId, $listId]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;

    shuffle($rows);

    $row = null;
    if (!empty($excludeIds)) {
        foreach ($rows as $c) {
            if (!in_array((int)$c['a_id'], $excludeIds) && !in_array((int)$c['b_id'], $excludeIds)) {
                $row = $c; break;
            }
        }
        if ($row === null) {
            foreach ($rows as $c) {
                if (!in_array((int)$c['a_id'], $excludeIds) || !in_array((int)$c['b_id'], $excludeIds)) {
                    $row = $c; break;
                }
            }
        }
    }
    if ($row === null) $row = $rows[0];

    // Besserer Rang (kleinere Position) kommt links
    $mA = ['title' => $row['a_title'], 'title_en' => $row['a_title_en'], 'poster_path' => $row['a_poster'], 'poster_path_en' => $row['a_poster_en']];
    $mB = ['title' => $row['b_title'], 'title_en' => $row['b_title_en'], 'poster_path' => $row['b_poster'], 'poster_path_en' => $row['b_poster_en']];
    if ((int)$row['pos_b'] < (int)$row['pos_a']) {
        return [
            'a_id' => (int)$row['b_id'], 'b_id' => (int)$row['a_id'],
            'a_title' => movieTitle($mB), 'b_title' => movieTitle($mA),
            'a_year'  => $row['b_year'],  'b_year'  => $row['a_year'],
            'a_poster'=> moviePosterUrl($mB, 'w500'), 'b_poster'=> moviePosterUrl($mA, 'w500'),
        ];
    }
    return [
        'a_id' => (int)$row['a_id'], 'b_id' => (int)$row['b_id'],
        'a_title' => movieTitle($mA), 'b_title' => movieTitle($mB),
        'a_year'  => $row['a_year'],  'b_year'  => $row['b_year'],
        'a_poster'=> moviePosterUrl($mA, 'w500'), 'b_poster'=> moviePosterUrl($mB, 'w500'),
    ];
}

function aktRanking(PDO $db, int $listId, int $userId): array {
    $s = $db->prepare("
        SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en,
               COALESCE(wins.cnt, 0) AS wins,
               COALESCE(loss_a.cnt, 0) + COALESCE(loss_b.cnt, 0) AS losses,
               COALESCE(wins.cnt, 0) + COALESCE(loss_a.cnt, 0) + COALESCE(loss_b.cnt, 0) AS played
        FROM action_list_films alf
        JOIN movies m ON m.id = alf.movie_id
        LEFT JOIN (
            SELECT winner_id AS mid, COUNT(*) AS cnt
            FROM action_list_duels WHERE list_id = ? AND user_id = ? GROUP BY winner_id
        ) wins ON wins.mid = m.id
        LEFT JOIN (
            SELECT movie_a_id AS mid, COUNT(*) AS cnt
            FROM action_list_duels WHERE list_id = ? AND user_id = ? AND winner_id != movie_a_id GROUP BY movie_a_id
        ) loss_a ON loss_a.mid = m.id
        LEFT JOIN (
            SELECT movie_b_id AS mid, COUNT(*) AS cnt
            FROM action_list_duels WHERE list_id = ? AND user_id = ? AND winner_id != movie_b_id GROUP BY movie_b_id
        ) loss_b ON loss_b.mid = m.id
        WHERE alf.list_id = ?
        ORDER BY wins DESC, losses ASC, m.title ASC
    ");
    $s->execute([$listId, $userId, $listId, $userId, $listId, $userId, $listId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function aktStoreRanking(PDO $db, int $listId, int $userId, array $ranking): void {
    $db->prepare("DELETE FROM action_list_rankings WHERE list_id = ? AND user_id = ?")
       ->execute([$listId, $userId]);
    if (empty($ranking)) return;
    $ph   = implode(',', array_fill(0, count($ranking), '(?,?,?,?,?,?)'));
    $vals = [];
    foreach ($ranking as $pos => $r) {
        $vals[] = $listId;
        $vals[] = $userId;
        $vals[] = (int)$r['id'];
        $vals[] = $pos + 1;
        $vals[] = (int)$r['wins'];
        $vals[] = (int)$r['losses'];
    }
    $db->prepare("INSERT INTO action_list_rankings (list_id, user_id, movie_id, position, wins, losses) VALUES $ph")
       ->execute($vals);
}

function posterUrlAkt(?string $path): string {
    return $path ? 'https://image.tmdb.org/t/p/w500' . $path
                 : 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?';
}

// ── AJAX: Vote ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

    $winnerId = (int)($_POST['winner_id'] ?? 0);
    $loserId  = (int)($_POST['loser_id']  ?? 0);
    $postListId = (int)($_POST['list_id'] ?? 0);

    if (!$winnerId || !$loserId || $winnerId === $loserId || $postListId !== $listId) {
        echo json_encode(['ok' => false, 'error' => 'invalid']); exit;
    }

    // Beide Filme in der Liste?
    $chk = $db->prepare("SELECT COUNT(*) FROM action_list_films WHERE list_id = ? AND movie_id IN (?,?)");
    $chk->execute([$listId, $winnerId, $loserId]);
    if ((int)$chk->fetchColumn() !== 2) {
        echo json_encode(['ok' => false, 'error' => 'not_in_list']); exit;
    }

    $aId = min($winnerId, $loserId);
    $bId = max($winnerId, $loserId);

    // Runde bestimmen (wie oft dieses Paar bisher gespielt hat)
    $rnd = $db->prepare("SELECT COUNT(*) FROM action_list_duels WHERE list_id=? AND user_id=? AND movie_a_id=? AND movie_b_id=?");
    $rnd->execute([$listId, $userId, $aId, $bId]);
    $round = (int)$rnd->fetchColumn() + 1;

    if ($round > 1) {
        echo json_encode(['ok' => false, 'error' => 'pair_complete']); exit;
    }

    // Sidebar-Stats VOR der Eintragung
    $duelResult = buildDuelResult($db, $userId, $winnerId, $loserId);

    try {
        $ins = $db->prepare("INSERT IGNORE INTO action_list_duels (list_id, user_id, movie_a_id, movie_b_id, winner_id, round) VALUES (?,?,?,?,?,?)");
        $ins->execute([$listId, $userId, $aId, $bId, $winnerId, $round]);
        if ($ins->rowCount() > 0) {
            recordComparison($userId, $winnerId, $loserId);
        }
    } catch (\PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'db']); exit;
    }

    $winnerContext = buildWinnerContext($db, $userId, $winnerId, getActiveMtFilter());

    $_SESSION['akt_last_ids_' . $listId] = [$winnerId, $loserId];

    $filmCount = aktFilmCount($db, $listId);
    $total     = aktTotalMatches($filmCount);
    $done      = aktDoneCount($db, $listId, $userId);
    $nextDuel  = $done < $total ? aktNextDuel($db, $listId, $userId, [$winnerId, $loserId]) : null;

    $commRanks = $nextDuel
        ? buildCommRanks($db, (int)$nextDuel['a_id'], $nextDuel['a_title'],
                              (int)$nextDuel['b_id'], $nextDuel['b_title'], $userId, getActiveMtFilter())
        : null;

    $ranking  = aktRanking($db, $listId, $userId);
    $complete = $done >= $total;

    // Abschlussspeicherung
    if ($complete) {
        aktStoreRanking($db, $listId, $userId, $ranking);
    }

    $rankRows = array_map(function($r) {
        $wins = (int)$r['wins']; $played = (int)$r['played'];
        return [
            'id'            => (int)$r['id'],
            'title'         => $r['title'],
            'poster_path'   => $r['poster_path'],
            'display_title' => movieTitle($r),
            'display_poster'=> moviePosterUrl($r, 'w92'),
            'wins'          => $wins,
            'losses'        => (int)$r['losses'],
            'played'        => $played,
            'ratio'         => $played > 0 ? round($wins / $played * 100) : 0,
        ];
    }, $ranking);

    $counters = getActivityCounters($userId);

    echo json_encode([
        'ok'             => true,
        'done'           => $done,
        'total'          => $total,
        'complete'       => $complete,
        'winner_id'      => $winnerId,
        'next_duel'      => $nextDuel,
        'ranking'        => $rankRows,
        'duel_result'    => $duelResult,
        'winner_context' => $winnerContext,
        'comm_ranks'     => $commRanks,
        'hdrDuels'       => $counters['totalDuels'],
        'hdrFilms'       => $counters['uniqueFilms'],
    ]);
    exit;
}

// ── AJAX: Undo ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'undo') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

    $last = $db->prepare("SELECT id, movie_a_id, movie_b_id, winner_id
                          FROM action_list_duels WHERE list_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
    $last->execute([$listId, $userId]);
    $row = $last->fetch();
    if (!$row) { echo json_encode(['ok' => false, 'error' => 'nothing_to_undo']); exit; }

    $db->prepare("DELETE FROM action_list_duels WHERE id = ? AND user_id = ?")
       ->execute([$row['id'], $userId]);

    $aId = (int)$row['movie_a_id'];
    $bId = (int)$row['movie_b_id'];
    $films = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, imdb_id FROM movies WHERE id IN (?,?)");
    $films->execute([$aId, $bId]);
    $fm = array_column($films->fetchAll(), null, 'id');
    $undoDuel = isset($fm[$aId], $fm[$bId]) ? [
        'a_id' => $aId, 'a_title' => movieTitle($fm[$aId]),
        'a_year' => $fm[$aId]['year'], 'a_poster' => moviePosterUrl($fm[$aId], 'w500'),
        'b_id' => $bId, 'b_title' => movieTitle($fm[$bId]),
        'b_year' => $fm[$bId]['year'], 'b_poster' => moviePosterUrl($fm[$bId], 'w500'),
    ] : null;

    $filmCount = aktFilmCount($db, $listId);
    $total     = aktTotalMatches($filmCount);
    $done      = aktDoneCount($db, $listId, $userId);
    $ranking   = aktRanking($db, $listId, $userId);
    $rankRows  = array_map(function($r) {
        $wins = (int)$r['wins']; $played = (int)$r['played'];
        return ['id' => (int)$r['id'], 'title' => $r['title'], 'poster_path' => $r['poster_path'],
                'display_title' => movieTitle($r), 'display_poster' => moviePosterUrl($r, 'w92'),
                'wins' => $wins, 'losses' => (int)$r['losses'], 'played' => $played,
                'ratio' => $played > 0 ? round($wins / $played * 100) : 0];
    }, $ranking);

    $counters = getActivityCounters($userId);
    echo json_encode(['ok' => true, 'done' => $done, 'total' => $total, 'complete' => false,
                      'undo_duel' => $undoDuel, 'ranking' => $rankRows,
                      'hdrDuels' => $counters['totalDuels'], 'hdrFilms' => $counters['uniqueFilms']]);
    exit;
}

// ── Seitendaten laden ──────────────────────────────────────────────────────────
$filmCount = aktFilmCount($db, $listId);
$total     = aktTotalMatches($filmCount);
$done      = aktDoneCount($db, $listId, $userId);
$pending   = $total - $done;
$pct       = $total > 0 ? round($done / $total * 100, 1) : 0;
$lastIds   = $_SESSION['akt_last_ids_' . $listId] ?? [];
$complete  = $done >= $total && $total > 0;

$ranking  = $filmCount >= 1 ? aktRanking($db, $listId, $userId) : [];

// Abschluss speichern falls noch nicht geschehen
if ($complete) {
    $rkCheck = $db->prepare("SELECT COUNT(*) FROM action_list_rankings WHERE list_id=? AND user_id=?");
    $rkCheck->execute([$listId, $userId]);
    if ((int)$rkCheck->fetchColumn() === 0) {
        aktStoreRanking($db, $listId, $userId, $ranking);
    }
}

$nextDuel = (!$complete && $filmCount >= 2) ? aktNextDuel($db, $listId, $userId, $lastIds) : null;

// Wurden neue Filme hinzugefügt, seit der User zuletzt abgeschlossen hat?
$hasNewFilms = false;
if (!$complete && $total > 0) {
    $rkCheck2 = $db->prepare("SELECT COUNT(*) FROM action_list_rankings WHERE list_id=? AND user_id=?");
    $rkCheck2->execute([$listId, $userId]);
    $hasNewFilms = (int)$rkCheck2->fetchColumn() > 0;
}

$initCommRanks = $nextDuel
    ? buildCommRanks($db, (int)$nextDuel['a_id'], $nextDuel['a_title'],
                          (int)$nextDuel['b_id'], $nextDuel['b_title'], $userId, getActiveMtFilter())
    : null;

// Linke Sidebar: persönliche Rangliste
$posRankStmt = $db->prepare("
    SELECT upr.position AS pos, m.id, m.title, m.title_en, m.poster_path, m.poster_path_en
    FROM user_position_ranking upr
    JOIN movies m ON m.id = upr.movie_id
    WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . " ORDER BY upr.position ASC LIMIT 200");
$posRankStmt->execute([$userId]);
$posRanking = [];
foreach ($posRankStmt->fetchAll() as $i => $r) { $posRanking[] = array_merge($r, ['pos' => $i + 1]); }

require_once __DIR__ . '/includes/header.php';
?>
<style>
.akt-page { background: #14325a; min-height: 100vh; color: #fff; }

/* Layout */
.liga-3col   { display: flex; gap: 20px; align-items: flex-start; padding: 0 5%; }
.liga-side   { flex: 0 0 420px; min-width: 0; display: flex; flex-direction: column; overflow: hidden;
               position: sticky; top: 80px; align-self: flex-start; max-height: calc(100vh - 180px); }
.liga-center { flex: 1; min-width: 0; }
.liga-side .turnier-ranking-wrap { flex: 1; display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
.liga-side .turnier-ranking-list { flex: 1; min-height: 0; overflow-y: auto; }

@media (max-width: 1400px) and (min-width: 768px) {
    .liga-3col                    { padding: 0 2%; gap: 12px; }
    .liga-3col > div:nth-child(1) { flex: 0 0 320px; }
    .liga-3col > div:nth-child(3) { display: none !important; }
    .liga-side .turnier-ranking-list { max-height: 60vh; }
    .duel-poster-wrap             { max-width: min(600px, 27vh) !important; }
}
@media (max-width: 767px) {
    .liga-3col   { padding: 0 1%; }
    .liga-side   { display: none !important; }
    .liga-center { flex: 0 0 100%; }
}
@media (max-width: 900px) and (orientation: landscape) {
    .akt-page .liga-side { display: none !important; }
    .akt-page .duel-poster-wrap { max-width: none !important; }
    .akt-page .duel-poster { max-height: calc(100dvh - 180px) !important; width: auto !important; object-fit: contain !important; }
}

/* Duel Arena */
.duel-arena { display: flex; align-items: stretch; gap: 0; border-radius: 12px; overflow: hidden; border: 1px solid rgba(255,255,255,.08); }
.duel-side  { flex: 1; cursor: pointer; display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,.03); padding: 20px 16px; transition: background .2s; position: relative; overflow: hidden; }
@media (hover: hover) { .duel-side:hover { background: rgba(232,184,75,.1); } .duel-side:hover .duel-overlay { opacity: 1; } }
.duel-side.winner { background: rgba(232,184,75,.18) !important; }
.duel-side.loser  { opacity: .4; }
.duel-overlay { position: absolute; inset: 0; background: rgba(232,184,75,.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .2s; color: #e8b84b; font-size: 2rem; }
.duel-title { color: #e0e0e0; font-weight: 600; font-size: .95rem; text-align: center; margin-top: 14px; margin-bottom: 4px; }
.duel-meta  { color: rgba(255,255,255,.4); font-size: .8rem; text-align: center; }
.duel-info-link { display: block; text-align: center; padding: .1rem .7rem .5rem; color: rgba(255,255,255,.2); font-size: .72rem; text-decoration: none; }
.duel-info-link:hover { color: #e8b84b; }
.vs-divider { display: flex; align-items: center; justify-content: center; padding: 0 8px; background: rgba(0,0,0,.3); min-width: 48px; }
.vs-circle  { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a; font-weight: 800; font-size: .85rem; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.duel-poster { width: 100%; aspect-ratio: 2/3; object-fit: cover; display: block; transition: opacity .2s ease-in; }
.duel-poster-wrap { max-width: 600px !important; }

/* Ranking panels */
.turnier-ranking-wrap { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); border-radius: 12px; overflow: hidden; }
.turnier-ranking-header { padding: .75rem 1rem; font-size: .8rem; font-weight: 700; color: #e8b84b; display: flex; align-items: center; gap: .4rem; border-bottom: 1px solid rgba(255,255,255,.08); background: rgba(232,184,75,.06); }
.turnier-ranking-list { overflow-y: auto; scrollbar-width: thin; scrollbar-color: rgba(232,184,75,.2) transparent; }
.turnier-ranking-list::-webkit-scrollbar { width: 4px; }
.turnier-ranking-list::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 2px; }
.turnier-rank-row { display: flex; align-items: center; gap: .5rem; padding: .4rem .75rem; border-bottom: 1px solid rgba(255,255,255,.05); }
.turnier-rank-row:last-child { border-bottom: none; }
.turnier-rank-row:hover { background: rgba(255,255,255,.04); }
.turnier-rank-num { min-width: 1.6rem; font-size: .75rem; font-weight: 700; color: rgba(255,255,255,.4); text-align: right; }
.turnier-rank-num.top { color: #e8b84b; }
.turnier-rank-poster { width: 26px; height: 39px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
.turnier-rank-title { flex: 1; font-size: .8rem; color: rgba(255,255,255,.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.turnier-points { font-size: .7rem; font-weight: 700; color: rgba(255,255,255,.55); white-space: nowrap; text-align: right; }

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

/* Progress */
.liga-progress { background: rgba(255,255,255,.05); border-radius: 8px; padding: 16px 20px; }
.progress-track { background: rgba(255,255,255,.08); border-radius: 6px; height: 8px; overflow: hidden; }
.progress-fill  { background: linear-gradient(90deg, #e8b84b, #c4942a); height: 100%; border-radius: 6px; transition: width .4s ease; }

/* Win ratio bar */
.ratio-bar-wrap { flex: 0 0 50px; background: rgba(255,255,255,.08); border-radius: 3px; height: 6px; overflow: hidden; }
.ratio-bar-fill { height: 100%; background: linear-gradient(90deg, #e8b84b, #c4942a); border-radius: 3px; transition: width .3s; }

/* Abschluss-Screen */
.completion-card { max-width: 800px; margin: 0 auto; text-align: center; padding: 48px 40px; background: rgba(255,255,255,.03); border: 1px solid rgba(232,184,75,.3); border-radius: 16px; }
.top3-row { display: flex; justify-content: center; align-items: flex-end; gap: 12px; margin: 32px 0; }
.top3-item { display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 0; max-width: 210px; }
.top3-item.rank-1 { order: 2; }
.top3-item.rank-2 { order: 1; }
.top3-item.rank-3 { order: 3; }
.top3-medal { font-size: 1.6rem; line-height: 1; height: 36px; display: flex; align-items: center; justify-content: center; }
.top3-poster-wrap { width: 100%; border-radius: 8px; overflow: hidden; box-shadow: 0 8px 32px rgba(0,0,0,.5); }
.top3-poster { width: 100%; aspect-ratio: 2/3; object-fit: cover; display: block; }
.top3-title { font-size: .8rem; color: rgba(255,255,255,.8); font-weight: 600; width: 100%; text-align: center; margin-top: 8px; overflow-wrap: break-word; }
.top3-item.rank-1 .top3-title { color: #e8b84b; }
.btn-gold-link { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a !important; font-weight: 700; border-radius: 8px; padding: 12px 28px; text-decoration: none !important; display: inline-block; }
</style>

<main class="akt-page" style="padding-top:6px;">
<div class="container-xxl px-3 px-lg-4" style="max-width:2200px; margin:0 auto;">

<p class="text-center mb-3" style="color:rgba(255,255,255,.4); font-size:clamp(1.2rem,2.2vw,2rem); white-space:nowrap; overflow:hidden;">
    <?= e($actionList['name']) ?>
</p>

<?php if ($hasNewFilms): ?>
<div style="max-width:700px; margin:0 auto 16px; background:rgba(232,184,75,.1); border:1px solid rgba(232,184,75,.4); border-radius:10px; padding:10px 18px; display:flex; align-items:center; gap:12px; font-size:.88rem; color:#e8b84b;">
    <i class="bi bi-plus-circle-fill" style="font-size:1.2rem; flex-shrink:0;"></i>
    <span>Neue Filme wurden hinzugefügt &mdash; noch <strong><?= $pending ?></strong> Duelle ausstehend.</span>
</div>
<?php endif; ?>

<?php if ($filmCount < 2): ?>
<!-- Keine/zu wenige Filme -->
<div style="max-width:540px; margin:0 auto; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); border-radius:16px; padding:40px 32px; text-align:center;">
    <i class="bi bi-trophy" style="font-size:3rem; color:rgba(232,184,75,.4);"></i>
    <h3 class="mt-3 fw-bold" style="color:#e8b84b;">Aktion nicht verfügbar</h3>
    <p style="color:rgba(255,255,255,.5);">Diese Aktion enthält noch nicht genug Filme.</p>
    <a href="/index.php" class="btn btn-gold">Zur Startseite</a>
</div>

<?php elseif ($complete): ?>
<!-- ── Abschluss-Screen ──────────────────────────────────────────────────── -->
<div id="duel-section" style="display:none;"></div>
<div id="completion-screen">
<div class="liga-3col">
    <!-- Links: Rangliste -->
    <div class="liga-side">
        <div class="turnier-ranking-wrap" style="max-height:calc(100vh - 120px);">
            <div class="turnier-ranking-header">
                <i class="bi bi-trophy-fill"></i> Aktions-Rangliste
            </div>
            <div class="turnier-ranking-list" id="akt-ranking-list-left">
                <?php foreach ($ranking as $i => $r): ?>
                <div class="turnier-rank-row">
                    <span class="turnier-rank-num <?= $i < 3 ? 'top' : '' ?>"><?= $i+1 ?></span>
                    <img src="<?= e(moviePosterUrl($r, 'w92')) ?>"
                         alt="" class="turnier-rank-poster" loading="lazy"
                         onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
                    <div class="turnier-rank-title"><a href="/film.php?id=<?= (int)$r['id'] ?>" class="film-link" target="_blank"><?= e(movieTitle($r)) ?></a></div>
                    <span class="turnier-points"><?= (int)$r['wins'] ?>S / <?= (int)$r['losses'] ?>N</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Mitte: Abschluss-Karte -->
    <div class="liga-center">
        <div class="completion-card">
            <div style="display:inline-flex; align-items:center; gap:8px; background:rgba(232,184,75,.12); border:1px solid rgba(232,184,75,.4); color:#e8b84b; border-radius:20px; padding:8px 20px; font-size:.85rem; font-weight:700; margin-bottom:24px;">
                <i class="bi bi-trophy-fill"></i> Aktion abgeschlossen!
            </div>
            <h2 class="fw-bold mb-2" style="color:#e8b84b;"><?= e($actionList['name']) ?></h2>
            <p style="color:rgba(255,255,255,.5); margin-bottom:0;"><?= $total ?> Duelle · <?= $filmCount ?> Filme</p>

            <?php if (count($ranking) >= 3): ?>
            <div class="top3-row">
                <?php foreach (array_slice($ranking, 0, 3) as $i => $f): ?>
                <div class="top3-item rank-<?= $i+1 ?>">
                    <div class="top3-medal"><?= ['🥇','🥈','🥉'][$i] ?></div>
                    <div class="top3-poster-wrap">
                        <a href="/film.php?id=<?= (int)$f['id'] ?>" target="_blank">
                            <img class="top3-poster"
                                 src="<?= e(moviePosterUrl($f, 'w500')) ?>"
                                 alt="<?= e(movieTitle($f)) ?>"
                                 onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                        </a>
                    </div>
                    <div class="top3-title"><?= e(movieTitle($f)) ?></div>
                    <div style="font-size:.75rem; color:rgba(255,255,255,.4); margin-top:3px;"><?= (int)$f['wins'] ?>S / <?= (int)$f['losses'] ?>N</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="d-flex justify-content-center gap-3 flex-wrap mt-3">
                <a href="/rangliste.php?tab=aktionen" class="btn-gold-link">
                    <i class="bi bi-trophy me-1"></i>Zu Meine Ranglisten
                </a>
                <button onclick="window.location.href='/aktionen.php?list=<?= $listId ?>&_='+Date.now()" style="padding:12px 28px; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); color:#ddd; border-radius:8px; cursor:pointer; font-size:1rem;">
                    <i class="bi bi-arrow-clockwise me-1"></i>Neue Duelle prüfen
                </button>
                <a href="/index.php" style="display:inline-block; padding:12px 28px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:rgba(255,255,255,.5); border-radius:8px; text-decoration:none; font-size:.88rem;">
                    Startseite
                </a>
            </div>

            <!-- Vollständige Rangliste -->
            <div style="margin-top:32px; text-align:left; background:rgba(255,255,255,.03); border-radius:12px; overflow:hidden; border:1px solid rgba(255,255,255,.07);">
                <div style="padding:.75rem 1rem; border-bottom:1px solid rgba(255,255,255,.07); font-size:.82rem; font-weight:700; color:rgba(255,255,255,.6);">
                    Vollständige Rangliste
                </div>
                <?php foreach ($ranking as $i => $f): ?>
                <div style="display:flex; align-items:center; gap:.6rem; padding:.45rem .9rem; border-bottom:1px solid rgba(255,255,255,.04); <?= $i % 2 === 0 ? 'background:rgba(255,255,255,.02);' : '' ?>">
                    <span style="min-width:1.8rem; font-size:.78rem; font-weight:700; color:<?= $i < 3 ? '#e8b84b' : 'rgba(255,255,255,.35)' ?>; text-align:right;"><?= $i+1 ?></span>
                    <img src="<?= e(moviePosterUrl($f, 'w92')) ?>"
                         alt="" style="width:24px; height:36px; object-fit:cover; border-radius:3px; flex-shrink:0;"
                         onerror="this.src='https://placehold.co/24x36/1e3a5f/e8b84b?text=?'">
                    <a href="/film.php?id=<?= (int)$f['id'] ?>" target="_blank" style="flex:1; color:#ddd; text-decoration:none; font-size:.85rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= e(movieTitle($f)) ?></a>
                    <span style="font-size:.75rem; color:rgba(255,255,255,.4);"><?= (int)$f['wins'] ?>S / <?= (int)$f['losses'] ?>N</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Rechts: leer (symmetrie) -->
    <div class="liga-side" style="visibility:hidden;"></div>
</div>
</div>

<?php else: ?>
<!-- ── Duel-Screen ─────────────────────────────────────────────────────────── -->
<div id="completion-screen" style="display:none;"></div>
<div id="duel-section">
<div class="liga-3col">

    <!-- ── Links: Persönliche Rangliste ──────────────────────────────────── -->
    <div class="liga-side">
        <div class="turnier-ranking-wrap">
            <div class="turnier-ranking-header">
                <i class="bi bi-list-ol"></i> <?= $mtActive === 'tv' ? 'Meine Rangliste Serien' : ($mtActive === 'movie' ? 'Meine Rangliste Filme' : 'Meine Rangliste') ?>
            </div>
            <div class="turnier-ranking-list" id="pos-ranking-list">
                <?php foreach ($posRanking as $r): ?>
                <div class="turnier-rank-row" data-film-id="<?= (int)$r['id'] ?>">
                    <span class="turnier-rank-num <?= (int)$r['pos'] <= 3 ? 'top' : '' ?>"><?= (int)$r['pos'] ?></span>
                    <img src="<?= e(moviePosterUrl($r, 'w92')) ?>"
                         alt="<?= e(movieTitle($r)) ?>" class="turnier-rank-poster" loading="lazy"
                         onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
                    <div class="turnier-rank-title"><a href="/film.php?id=<?= (int)$r['id'] ?>" class="film-link" target="_blank"><?= e(movieTitle($r)) ?></a></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <p class="text-center mt-2" style="font-size:.72rem;">
            <a href="/rangliste.php?tab=persoenlich" style="color:rgba(232,184,75,.5); text-decoration:none;">
                Alle <?= count($posRanking) ?> Filme →
            </a>
        </p>
    </div>

    <!-- ── Mitte: Fortschritt + Duell ─────────────────────────────────────── -->
    <div class="liga-center">

        <!-- Fortschritt -->
        <div class="liga-progress mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span style="color:rgba(255,255,255,.6); font-size:.85rem;">Fortschritt</span>
                <span id="progress-text" style="color:#e8b84b; font-size:.85rem; font-weight:600;">
                    <?= number_format($done) ?> / <?= number_format($total) ?> Duelle (<?= $pct ?>%)
                </span>
            </div>
            <div class="progress-track">
                <div class="progress-fill" id="progress-fill" style="width:<?= $pct ?>%"></div>
            </div>
        </div>

        <?php if ($nextDuel): ?>
        <!-- Duel Arena -->
        <div id="duel-section-inner">
        <div class="duel-arena" id="duel-arena">
            <div class="duel-side" id="movie-a"
                 data-id="<?= (int)$nextDuel['a_id'] ?>"
                 data-opponent="<?= (int)$nextDuel['b_id'] ?>">
                <div class="duel-poster-wrap">
                    <img class="duel-poster" fetchpriority="high" decoding="async"
                         src="<?= e($nextDuel['a_poster'] ?? '') ?>"
                         alt="<?= e($nextDuel['a_title']) ?>"
                         onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                    <div class="duel-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
                </div>
                <div class="duel-title"><?= e($nextDuel['a_title']) ?></div>
                <div class="duel-meta"><?= (int)$nextDuel['a_year'] ?></div>
                <a href="/film.php?id=<?= (int)$nextDuel['a_id'] ?>" class="duel-info-link" target="_blank" onclick="event.stopPropagation()"><i class="bi bi-info-circle me-1"></i>Details</a>
            </div>
            <div class="vs-divider"><div class="vs-circle">VS</div></div>
            <div class="duel-side" id="movie-b"
                 data-id="<?= (int)$nextDuel['b_id'] ?>"
                 data-opponent="<?= (int)$nextDuel['a_id'] ?>">
                <div class="duel-poster-wrap">
                    <img class="duel-poster" fetchpriority="high" decoding="async"
                         src="<?= e($nextDuel['b_poster'] ?? '') ?>"
                         alt="<?= e($nextDuel['b_title']) ?>"
                         onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                    <div class="duel-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
                </div>
                <div class="duel-title"><?= e($nextDuel['b_title']) ?></div>
                <div class="duel-meta"><?= (int)$nextDuel['b_year'] ?></div>
                <a href="/film.php?id=<?= (int)$nextDuel['b_id'] ?>" class="duel-info-link" target="_blank" onclick="event.stopPropagation()"><i class="bi bi-info-circle me-1"></i>Details</a>
            </div>
        </div>
        </div>

        <!-- Undo-Button -->
        <div class="d-flex justify-content-center mt-3">
            <button id="undo-btn" class="btn btn-sm" style="background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); color:rgba(255,255,255,.5); font-size:.8rem;" disabled>
                <i class="bi bi-arrow-counterclockwise me-1"></i>Letztes Duell rückgängig
            </button>
        </div>
        <?php endif; ?>

        <!-- Aktuelle Rangliste unter dem Duell -->
        <?php if (!empty($ranking)): ?>
        <div class="mt-4" style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.07); border-radius:12px; overflow:hidden;">
            <div style="padding:.6rem 1rem; border-bottom:1px solid rgba(255,255,255,.07); font-size:.78rem; font-weight:700; color:rgba(255,255,255,.5);">
                <i class="bi bi-bar-chart-steps me-1"></i>Aktuelle Aktions-Rangliste
            </div>
            <div style="max-height:320px; overflow-y:auto;" id="akt-ranking-list">
                <?php foreach ($ranking as $i => $r): ?>
                <div class="turnier-rank-row" id="akt-rank-<?= (int)$r['id'] ?>">
                    <span class="turnier-rank-num <?= $i < 3 ? 'top' : '' ?>"><?= $i+1 ?></span>
                    <img src="<?= e(moviePosterUrl($r, 'w92')) ?>"
                         alt="" class="turnier-rank-poster" loading="lazy"
                         onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
                    <div class="turnier-rank-title"><?= e(movieTitle($r)) ?></div>
                    <div class="ratio-bar-wrap">
                        <div class="ratio-bar-fill" style="width:<?= (int)$r['played'] > 0 ? round((int)$r['wins']/(int)$r['played']*100) : 0 ?>%"></div>
                    </div>
                    <span class="turnier-points"><?= (int)$r['wins'] ?>S/<?= (int)$r['losses'] ?>N</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.liga-center -->

    <!-- ── Rechts: Statistiken ─────────────────────────────────────────────── -->
    <div class="liga-side">
        <div class="turnier-ranking-wrap">
            <div class="turnier-ranking-header">
                <i class="bi bi-bar-chart-fill"></i> Statistiken
            </div>
            <div class="duel-stat-section" id="last-duel-stat">
                <div class="duel-stat-lbl">Letztes Duell</div>
                <div class="duel-stat-empty">Noch kein Duell bewertet</div>
            </div>
            <?php $rankSfx = $mtActive === 'tv' ? ' Serien' : ($mtActive === 'movie' ? ' Filme' : ''); ?>
            <div class="duel-stat-section" id="comm-context-stat" style="display:none;">
                <div class="duel-stat-lbl">Community Rangliste<?= $rankSfx ?></div>
            </div>
            <div class="duel-stat-section" id="my-context-stat" style="display:none;">
                <div class="duel-stat-lbl">Meine Rangliste<?= $rankSfx ?></div>
            </div>
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
            <?= $filmCount ?> Filme &nbsp;·&nbsp; <?= number_format($pending) ?> Duelle offen
        </p>
    </div><!-- /.liga-side -->

</div><!-- /.liga-3col -->
</div><!-- #duel-section -->
<?php endif; ?>

</div><!-- /.container-xxl -->
</main>

<script>
const CSRF_TOKEN   = <?= json_encode(csrfToken()) ?>;
const LIST_ID      = <?= $listId ?>;
const INIT_COMM_RANKS = <?= json_encode($initCommRanks) ?>;

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function posterUrl(p) {
    if (!p) return 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?';
    return p.startsWith('http') ? p : 'https://image.tmdb.org/t/p/w500' + p;
}
function posterUrlSm(p) {
    if (!p) return 'https://placehold.co/26x39/1e3a5f/e8b84b?text=?';
    return p.startsWith('http') ? p : 'https://image.tmdb.org/t/p/w92' + p;
}

// ── Statistik-Sidebar ──────────────────────────────────────────────────────────
function renderContextSection(secId, labelText, rows, winnerId) {
    const sec = document.getElementById(secId);
    if (!sec) return;
    if (!rows || !rows.length) { sec.style.display = 'none'; return; }
    let html = `<div class="duel-stat-lbl">${escHtml(labelText)}</div>`;
    rows.forEach(r => {
        const isW = (r.id === winnerId);
        html += `<div class="ctx-row${isW ? ' is-winner' : ''}">
            <span class="ctx-pos">#${r.rank ?? r.pos ?? '?'}</span>
            <span class="ctx-title">${escHtml(r.title)}</span>
        </div>`;
    });
    sec.innerHTML = html;
    sec.style.display = '';
}

const RANK_SFX = <?= json_encode($rankSfx) ?>;

function updateDuelStats(duelResult, winnerContext, commRanks) {
    // Letztes Duell
    const lastSec = document.getElementById('last-duel-stat');
    if (lastSec && duelResult) {
        const wr = duelResult;
        let html = `<div class="duel-stat-lbl">Letztes Duell</div><div class="duel-stat-result">`;
        if (wr.rank_changed) {
            html += `<span class="rank-up"><i class="bi bi-arrow-up-circle-fill me-1"></i>${escHtml(wr.winner_title)}</span>`;
            html += ` <span style="opacity:.6;font-size:.75rem;">(#${wr.winner_old_pos ?? '?'})</span>`;
            html += ` übernimmt Platz von `;
            html += `<strong>${escHtml(wr.loser_title)}</strong>`;
            html += ` <span style="opacity:.6;font-size:.75rem;">(#${wr.loser_old_pos ?? '?'})</span>`;
        } else {
            html += `<strong>${escHtml(wr.winner_title)}</strong>`;
            html += ` <span style="opacity:.6;font-size:.75rem;">(#${wr.winner_old_pos ?? '?'})</span>`;
            html += ` besiegt <strong>${escHtml(wr.loser_title)}</strong>`;
            html += ` <span style="opacity:.6;font-size:.75rem;">(#${wr.loser_old_pos ?? '?'})</span>`;
            html += `<span class="no-change">Rangliste unverändert</span>`;
        }
        html += `</div>`;
        lastSec.innerHTML = html;
    }

    // Community-Kontext (±2 um Sieger)
    if (winnerContext) {
        renderContextSection('comm-context-stat', 'Community Rangliste' + RANK_SFX, winnerContext.comm, winnerContext.winner_id);
        renderContextSection('my-context-stat',   'Meine Rangliste' + RANK_SFX,    winnerContext.mine, winnerContext.winner_id);
    }

    // Community + Meine Rangliste für aktuelles Duell
    if (commRanks) {
        const cr = document.getElementById('comm-rank-stat');
        if (cr) {
            cr.innerHTML = `<div class="duel-stat-lbl">Community Ranking${RANK_SFX}</div>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank">${commRanks.a_rank ? '#'+commRanks.a_rank : '–'}</span>
                    <span class="duel-comm-title">${escHtml(commRanks.a_title)}</span>
                </div>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank">${commRanks.b_rank ? '#'+commRanks.b_rank : '–'}</span>
                    <span class="duel-comm-title">${escHtml(commRanks.b_title)}</span>
                </div>`;
        }
        const mr = document.getElementById('my-rank-stat');
        if (mr) {
            const styleW = 'background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.15);color:rgba(255,255,255,.7);';
            mr.innerHTML = `<div class="duel-stat-lbl">Meine Rangliste${RANK_SFX}</div>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank" style="${styleW}">${commRanks.a_my_pos !== null ? '#'+commRanks.a_my_pos : '–'}</span>
                    <span class="duel-comm-title">${escHtml(commRanks.a_title)}</span>
                </div>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank" style="${styleW}">${commRanks.b_my_pos !== null ? '#'+commRanks.b_my_pos : '–'}</span>
                    <span class="duel-comm-title">${escHtml(commRanks.b_title)}</span>
                </div>`;
        }
    }
}

// ── Rangliste aktualisieren ────────────────────────────────────────────────────
function updateRanking(rows) {
    const list = document.getElementById('akt-ranking-list');
    if (!list || !rows) return;
    list.innerHTML = rows.map((r, i) => `
        <div class="turnier-rank-row">
            <span class="turnier-rank-num${i < 3 ? ' top' : ''}">${i+1}</span>
            <img src="${escHtml(r.display_poster || posterUrlSm(r.poster_path))}" alt="" class="turnier-rank-poster" loading="lazy"
                 onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
            <div class="turnier-rank-title">${escHtml(r.display_title || r.title || '')}</div>
            <div class="ratio-bar-wrap"><div class="ratio-bar-fill" style="width:${r.ratio}%"></div></div>
            <span class="turnier-points">${r.wins}S/${r.losses}N</span>
        </div>`
    ).join('');
}

// ── Duel laden / anzeigen ──────────────────────────────────────────────────────
function loadDuel(duel) {
    if (!duel) return;
    const arena = document.getElementById('duel-arena');
    if (!arena) return;
    const ma = document.getElementById('movie-a');
    const mb = document.getElementById('movie-b');
    if (ma) {
        ma.dataset.id       = duel.a_id;
        ma.dataset.opponent = duel.b_id;
        ma.classList.remove('winner','loser');
        const img = ma.querySelector('.duel-poster');
        if (img) { img.src = posterUrl(duel.a_poster); img.alt = duel.a_title; }
        const t = ma.querySelector('.duel-title'); if (t) t.textContent = duel.a_title;
        const m = ma.querySelector('.duel-meta');  if (m) m.textContent = duel.a_year;
        const l = ma.querySelector('.duel-info-link'); if (l) l.href = '/film.php?id='+duel.a_id;
    }
    if (mb) {
        mb.dataset.id       = duel.b_id;
        mb.dataset.opponent = duel.a_id;
        mb.classList.remove('winner','loser');
        const img = mb.querySelector('.duel-poster');
        if (img) { img.src = posterUrl(duel.b_poster); img.alt = duel.b_title; }
        const t = mb.querySelector('.duel-title'); if (t) t.textContent = duel.b_title;
        const m = mb.querySelector('.duel-meta');  if (m) m.textContent = duel.b_year;
        const l = mb.querySelector('.duel-info-link'); if (l) l.href = '/film.php?id='+duel.b_id;
    }
}

// ── Abschluss-Screen anzeigen ──────────────────────────────────────────────────
function showCompletion(ranking) {
    document.getElementById('duel-section').style.display = 'none';
    const cs = document.getElementById('completion-screen');
    const medals = ['🥇','🥈','🥉'];
    const top3 = ranking.slice(0,3).map((f,i) => `
        <div class="top3-item rank-${i+1}" style="order:${[2,1,3][i]}">
            <div class="top3-medal">${medals[i]}</div>
            <div class="top3-poster-wrap">
                <a href="/film.php?id=${f.id}" target="_blank">
                    <img class="top3-poster" src="${escHtml(f.display_poster || posterUrl(f.poster_path))}" alt="${escHtml(f.display_title || f.title || '')}"
                         onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                </a>
            </div>
            <div class="top3-title">${escHtml(f.display_title || f.title || '')}</div>
            <div style="font-size:.75rem;color:rgba(255,255,255,.4);margin-top:3px;">${f.wins}S / ${f.losses}N</div>
        </div>`).join('');
    const fullList = ranking.map((f,i) => `
        <div style="display:flex;align-items:center;gap:.6rem;padding:.45rem .9rem;border-bottom:1px solid rgba(255,255,255,.04);${i%2===0?'background:rgba(255,255,255,.02)':''}">
            <span style="min-width:1.8rem;font-size:.78rem;font-weight:700;color:${i<3?'#e8b84b':'rgba(255,255,255,.35)'};text-align:right;">${i+1}</span>
            <img src="${escHtml(f.display_poster || posterUrlSm(f.poster_path))}" alt="" style="width:24px;height:36px;object-fit:cover;border-radius:3px;flex-shrink:0;"
                 onerror="this.src='https://placehold.co/24x36/1e3a5f/e8b84b?text=?'">
            <a href="/film.php?id=${f.id}" target="_blank" style="flex:1;color:#ddd;text-decoration:none;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(f.display_title || f.title || '')}</a>
            <span style="font-size:.75rem;color:rgba(255,255,255,.4);">${f.wins}S / ${f.losses}N</span>
        </div>`).join('');

    cs.innerHTML = `
        <div class="completion-card" style="max-width:700px;margin:0 auto;text-align:center;padding:48px 40px;background:rgba(255,255,255,.03);border:1px solid rgba(232,184,75,.3);border-radius:16px;">
            <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(232,184,75,.12);border:1px solid rgba(232,184,75,.4);color:#e8b84b;border-radius:20px;padding:8px 20px;font-size:.85rem;font-weight:700;margin-bottom:24px;">
                <i class="bi bi-trophy-fill"></i> Aktion abgeschlossen!
            </div>
            <div class="top3-row">${top3}</div>
            <div class="d-flex justify-content-center gap-3 flex-wrap mt-3">
                <a href="/rangliste.php?tab=aktionen" class="btn-gold-link"><i class="bi bi-trophy me-1"></i>Zu Meine Ranglisten</a>
                <a href="/index.php" style="display:inline-block;padding:12px 28px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:#ddd;border-radius:8px;text-decoration:none;">Startseite</a>
            </div>
            <div style="margin-top:32px;text-align:left;background:rgba(255,255,255,.03);border-radius:12px;overflow:hidden;border:1px solid rgba(255,255,255,.07);">
                <div style="padding:.75rem 1rem;border-bottom:1px solid rgba(255,255,255,.07);font-size:.82rem;font-weight:700;color:rgba(255,255,255,.6);">Vollständige Rangliste</div>
                ${fullList}
            </div>
        </div>`;
    cs.style.display = '';
}

// ── Vote-Handler ───────────────────────────────────────────────────────────────
let voting = false;

function vote(winnerId, loserId) {
    if (voting) return;
    voting = true;

    const ma = document.getElementById('movie-a');
    const mb = document.getElementById('movie-b');
    if (ma) { ma.classList.toggle('winner', parseInt(ma.dataset.id) === winnerId); ma.classList.toggle('loser', parseInt(ma.dataset.id) !== winnerId); }
    if (mb) { mb.classList.toggle('winner', mb.dataset.id == winnerId); mb.classList.toggle('loser', mb.dataset.id != winnerId); }

    const body = new FormData();
    body.append('action', 'vote');
    body.append('csrf_token', CSRF_TOKEN);
    body.append('winner_id', winnerId);
    body.append('loser_id', loserId);
    body.append('list_id', LIST_ID);

    fetch(location.href, { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { console.error('Vote error:', data.error); voting = false; return; }

            // Header-Counter
            const dc = document.getElementById('hdr-duels-count');
            const fc = document.getElementById('hdr-films-count');
            if (dc && data.hdrDuels != null) dc.textContent = data.hdrDuels.toLocaleString('de');
            if (fc && data.hdrFilms != null) fc.textContent = data.hdrFilms;

            // Fortschritt
            const total = data.total, done = data.done;
            const pct = total > 0 ? Math.round(done / total * 1000) / 10 : 0;
            const pt = document.getElementById('progress-text');
            const pf = document.getElementById('progress-fill');
            if (pt) pt.textContent = done.toLocaleString('de') + ' / ' + total.toLocaleString('de') + ' Duelle (' + pct + '%)';
            if (pf) pf.style.width = pct + '%';

            document.getElementById('undo-btn').disabled = false;

            updateDuelStats(data.duel_result, data.winner_context, data.comm_ranks);
            if (data.ranking) updateRanking(data.ranking);

            if (data.complete) {
                showCompletion(data.ranking);
            } else if (data.next_duel) {
                setTimeout(() => {
                    loadDuel(data.next_duel);
                    voting = false;
                }, 280);
            } else {
                voting = false;
            }
        })
        .catch(() => { voting = false; });
}

// ── Undo-Handler ───────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    updateDuelStats(null, null, INIT_COMM_RANKS);

    const undoBtn = document.getElementById('undo-btn');
    if (undoBtn) {
        undoBtn.addEventListener('click', function() {
            const body = new FormData();
            body.append('action', 'undo');
            body.append('csrf_token', CSRF_TOKEN);
            fetch(location.href, { method: 'POST', body })
                .then(r => r.json())
                .then(data => {
                    if (!data.ok) return;
                    const total = data.total, done = data.done;
                    const pct = total > 0 ? Math.round(done / total * 1000) / 10 : 0;
                    const pt = document.getElementById('progress-text');
                    const pf = document.getElementById('progress-fill');
                    if (pt) pt.textContent = done.toLocaleString('de') + ' / ' + total.toLocaleString('de') + ' Duelle (' + pct + '%)';
                    if (pf) pf.style.width = pct + '%';
                    if (data.ranking) updateRanking(data.ranking);
                    if (data.undo_duel) loadDuel(data.undo_duel);
                    undoBtn.disabled = done === 0;
                    // Abschluss-Screen ausblenden falls nötig
                    const cs = document.getElementById('completion-screen');
                    const ds = document.getElementById('duel-section');
                    if (cs && ds && !data.complete) {
                        cs.style.display = 'none'; ds.style.display = '';
                    }
                })
                .catch(console.error);
        });
    }

    // Klick auf Duell-Seite
    document.querySelectorAll('.duel-side').forEach(el => {
        el.addEventListener('click', function() {
            if (voting) return;
            const winnerId = parseInt(this.dataset.id);
            const loserId  = parseInt(this.dataset.opponent);
            if (winnerId && loserId) vote(winnerId, loserId);
        });
    });

    // Tastatur-Steuerung (← / →)
    document.addEventListener('keydown', function(e) {
        if (voting) return;
        if (e.key === 'ArrowLeft') {
            const ma = document.getElementById('movie-a');
            if (ma) { const w = parseInt(ma.dataset.id), l = parseInt(ma.dataset.opponent); if (w && l) vote(w, l); }
        } else if (e.key === 'ArrowRight') {
            const mb = document.getElementById('movie-b');
            if (mb) { const w = parseInt(mb.dataset.id), l = parseInt(mb.dataset.opponent); if (w && l) vote(w, l); }
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
