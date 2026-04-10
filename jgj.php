<?php
set_time_limit(300);
$pageTitle = 'Jeder gegen Jeden – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── DB Schema ─────────────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS jgj_pool (
    user_id  INT UNSIGNED NOT NULL,
    movie_id INT UNSIGNED NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, movie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS jgj_results (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    movie_a_id INT UNSIGNED NOT NULL,
    movie_b_id INT UNSIGNED NOT NULL,
    winner_id  INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    UNIQUE KEY uq_match (user_id, movie_a_id, movie_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Helper ────────────────────────────────────────────────────────────────────
function jgjPoolSize(PDO $db, int $userId): int {
    $s = $db->prepare("SELECT COUNT(*) FROM jgj_pool p JOIN movies m ON m.id = p.movie_id WHERE p.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m'));
    $s->execute([$userId]);
    return (int)$s->fetchColumn();
}

function jgjDoneCount(PDO $db, int $userId): int {
    $s = $db->prepare("SELECT COUNT(*) FROM jgj_results r JOIN movies m ON m.id = r.winner_id WHERE r.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m'));
    $s->execute([$userId]);
    return (int)$s->fetchColumn();
}

function jgjTotalMatches(int $n): int {
    return $n > 1 ? (int)($n * ($n - 1) / 2) : 0;
}

// Indizes sicherstellen – einmalig, idempotent
try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_jgj_res_user_winner ON jgj_results(user_id, winner_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_jgj_res_user_a      ON jgj_results(user_id, movie_a_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_jgj_res_user_b      ON jgj_results(user_id, movie_b_id)");
} catch (\PDOException $e) { /* Ältere MySQL-Versionen ohne IF NOT EXISTS */ }

function jgjEvaluatedIds(PDO $db, int $userId, int $poolSize): array {
    if ($poolSize < 2) return [];
    // Ohne OR: Siege + Niederlagen separat zählen, dann summieren
    $s = $db->prepare("
        SELECT p.movie_id
        FROM jgj_pool p
        JOIN movies m ON m.id = p.movie_id
        LEFT JOIN (SELECT winner_id   AS mid, COUNT(*) AS cnt FROM jgj_results WHERE user_id=? GROUP BY winner_id)   w  ON w.mid  = p.movie_id
        LEFT JOIN (SELECT movie_a_id  AS mid, COUNT(*) AS cnt FROM jgj_results WHERE user_id=? AND winner_id!=movie_a_id GROUP BY movie_a_id) la ON la.mid = p.movie_id
        LEFT JOIN (SELECT movie_b_id  AS mid, COUNT(*) AS cnt FROM jgj_results WHERE user_id=? AND winner_id!=movie_b_id GROUP BY movie_b_id) lb ON lb.mid = p.movie_id
        WHERE p.user_id = ?
          AND (COALESCE(w.cnt,0) + COALESCE(la.cnt,0) + COALESCE(lb.cnt,0)) >= ?" . seriesSqlFilter('m') . moviesSqlFilter('m'));
    $s->execute([$userId, $userId, $userId, $userId, $poolSize - 1]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

function jgjRanking(PDO $db, int $userId): array {
    try {
        // Ohne OR im JOIN: drei kleine Subqueries statt einem Full-Table-Scan
        $s = $db->prepare("
            SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en,
                   COALESCE(w.wins,  0)                        AS wins,
                   COALESCE(la.cnt, 0) + COALESCE(lb.cnt, 0)  AS losses,
                   COALESCE(w.wins,  0) + COALESCE(la.cnt, 0) + COALESCE(lb.cnt, 0) AS played
            FROM jgj_pool p
            JOIN movies m ON m.id = p.movie_id
            LEFT JOIN (SELECT winner_id  AS mid, COUNT(*) AS wins FROM jgj_results WHERE user_id=? GROUP BY winner_id)                            w  ON w.mid  = m.id
            LEFT JOIN (SELECT movie_a_id AS mid, COUNT(*) AS cnt  FROM jgj_results WHERE user_id=? AND winner_id!=movie_a_id GROUP BY movie_a_id) la ON la.mid = m.id
            LEFT JOIN (SELECT movie_b_id AS mid, COUNT(*) AS cnt  FROM jgj_results WHERE user_id=? AND winner_id!=movie_b_id GROUP BY movie_b_id) lb ON lb.mid = m.id
            WHERE p.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . hiddenFilmsSqlFilter('m') . "
            ORDER BY wins DESC,
                     (COALESCE(la.cnt,0) + COALESCE(lb.cnt,0)) ASC,
                     (COALESCE(w.wins,0) + COALESCE(la.cnt,0) + COALESCE(lb.cnt,0)) ASC");
        $s->execute([$userId, $userId, $userId, $userId]);
        return $s->fetchAll();
    } catch (\PDOException $e) {
        error_log('jgjRanking error: ' . $e->getMessage());
        return [];
    }
}

function jgjNextDuel(PDO $db, int $userId, array $excludeIds = []): ?array {
    // Mehr Kandidaten holen und PHP-seitig mischen für gleichmäßige Verteilung
    $s = $db->prepare("
        SELECT a.movie_id AS a_id, b.movie_id AS b_id,
               ma.title AS a_title, ma.title_en AS a_title_en, ma.year AS a_year, ma.poster_path AS a_poster, ma.poster_path_en AS a_poster_en, ma.imdb_id AS a_imdb, COALESCE(NULLIF(ma.wikipedia,''), ma.overview) AS a_overview,
               mb.title AS b_title, mb.title_en AS b_title_en, mb.year AS b_year, mb.poster_path AS b_poster, mb.poster_path_en AS b_poster_en, mb.imdb_id AS b_imdb, COALESCE(NULLIF(mb.wikipedia,''), mb.overview) AS b_overview,
               COALESCE(pa.position, 999999) AS pos_a,
               COALESCE(pb.position, 999999) AS pos_b
        FROM jgj_pool a
        JOIN jgj_pool b
            ON b.user_id = a.user_id AND b.movie_id > a.movie_id
        JOIN movies ma ON ma.id = a.movie_id
        JOIN movies mb ON mb.id = b.movie_id
        LEFT JOIN jgj_results r
            ON r.user_id = a.user_id
           AND r.movie_a_id = a.movie_id AND r.movie_b_id = b.movie_id
        LEFT JOIN user_position_ranking pa ON pa.user_id = a.user_id AND pa.movie_id = a.movie_id
        LEFT JOIN user_position_ranking pb ON pb.user_id = b.user_id AND pb.movie_id = b.movie_id
        WHERE a.user_id = ? AND r.id IS NULL"
        . seriesSqlFilter('ma') . seriesSqlFilter('mb') . moviesSqlFilter('ma') . moviesSqlFilter('mb') . hiddenFilmsSqlFilter('ma') . hiddenFilmsSqlFilter('mb') . "
        LIMIT 300");
    $s->execute([$userId]);
    $rows = $s->fetchAll();
    if (!$rows) return null;

    // Mischen für echte Zufälligkeit
    shuffle($rows);

    // Bevorzugt Matches wählen, in denen keiner der zuletzt gesehenen Filme vorkommt
    $row = null;
    if (!empty($excludeIds)) {
        foreach ($rows as $candidate) {
            if (!in_array($candidate['a_id'], $excludeIds) && !in_array($candidate['b_id'], $excludeIds)) {
                $row = $candidate;
                break;
            }
        }
        // Fallback: wenigstens einen der beiden Filme wechseln
        if ($row === null) {
            foreach ($rows as $candidate) {
                if (!in_array($candidate['a_id'], $excludeIds) || !in_array($candidate['b_id'], $excludeIds)) {
                    $row = $candidate;
                    break;
                }
            }
        }
    }
    // Letzter Fallback: irgendeinen nehmen
    if ($row === null) {
        $row = $rows[0];
    }

    // Film mit besserem Rang (kleinere Position) kommt auf die linke Seite (a)
    if ((int)$row['pos_b'] < (int)$row['pos_a']) {
        return [
            'a_id'        => $row['b_id'],          'b_id'        => $row['a_id'],
            'a_title'     => $row['b_title'],        'b_title'     => $row['a_title'],
            'a_title_en'  => $row['b_title_en'] ?? null, 'b_title_en' => $row['a_title_en'] ?? null,
            'a_year'      => $row['b_year'],         'b_year'      => $row['a_year'],
            'a_poster'    => $row['b_poster'],       'b_poster'    => $row['a_poster'],
            'a_poster_en' => $row['b_poster_en'] ?? null, 'b_poster_en' => $row['a_poster_en'] ?? null,
        ];
    }
    return [
        'a_id'        => $row['a_id'],          'b_id'        => $row['b_id'],
        'a_title'     => $row['a_title'],        'b_title'     => $row['b_title'],
        'a_title_en'  => $row['a_title_en'] ?? null, 'b_title_en' => $row['b_title_en'] ?? null,
        'a_year'      => $row['a_year'],         'b_year'      => $row['b_year'],
        'a_poster'    => $row['a_poster'],       'b_poster'    => $row['b_poster'],
        'a_poster_en' => $row['a_poster_en'] ?? null, 'b_poster_en' => $row['b_poster_en'] ?? null,
    ];
}

// ── AJAX: Vote ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

    $winnerId = (int)($_POST['winner_id'] ?? 0);
    $loserId  = (int)($_POST['loser_id']  ?? 0);

    if (!$winnerId || !$loserId || $winnerId === $loserId) {
        echo json_encode(['ok' => false, 'error' => 'invalid']); exit;
    }

    // Validate both in pool (and matching current media type)
    $chk = $db->prepare("SELECT COUNT(*) FROM jgj_pool p JOIN movies m ON m.id = p.movie_id WHERE p.user_id = ? AND p.movie_id IN (?,?)" . seriesSqlFilter('m') . moviesSqlFilter('m'));
    $chk->execute([$userId, $winnerId, $loserId]);
    if ((int)$chk->fetchColumn() !== 2) {
        echo json_encode(['ok' => false, 'error' => 'not_in_pool']); exit;
    }

    // Ensure a_id < b_id for UNIQUE constraint
    $aId = min($winnerId, $loserId);
    $bId = max($winnerId, $loserId);

    // Alte Positionen + Titel VOR recordComparison holen (für Sidebar-Statistik)
    $duelResult = buildDuelResult($db, $userId, $winnerId, $loserId);

    try {
        $ins = $db->prepare("INSERT IGNORE INTO jgj_results (user_id, movie_a_id, movie_b_id, winner_id)
                             VALUES (?, ?, ?, ?)");
        $ins->execute([$userId, $aId, $bId, $winnerId]);
        // Nur wenn tatsächlich neu eingetragen (kein Duplikat), ELO + Meine Rangliste aktualisieren
        if ($ins->rowCount() > 0) {
            recordComparison($userId, $winnerId, $loserId);
        }
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'db']); exit;
    }

    $winnerContext = buildWinnerContext($db, $userId, $winnerId, getActiveMtFilter());

    // Zuletzt gespielte Film-IDs merken, damit sie im nächsten Duell gemieden werden
    $_SESSION['jgj_last_ids'] = [$winnerId, $loserId];

    $poolSize  = jgjPoolSize($db, $userId);
    $done      = jgjDoneCount($db, $userId);
    $total     = jgjTotalMatches($poolSize);
    $nextDuel  = jgjNextDuel($db, $userId, [$winnerId, $loserId]);
    $evaluated = jgjEvaluatedIds($db, $userId, $poolSize);
    $ranking   = jgjRanking($db, $userId);

    // Community-Ränge + persönliche Positionen für das nächste Duell
    $commRanks = $nextDuel
        ? buildCommRanks($db, (int)$nextDuel['a_id'], $nextDuel['a_title'],
                              (int)$nextDuel['b_id'], $nextDuel['b_title'], $userId, getActiveMtFilter())
        : null;

    $posStmt = $db->prepare("
        SELECT upr.position AS pos, m.id, m.title, m.title_en, m.poster_path, m.poster_path_en
        FROM user_position_ranking upr
        JOIN movies m ON m.id = upr.movie_id
        WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . " ORDER BY upr.position ASC LIMIT 2000");
    $posStmt->execute([$userId]);
    $posRankRows = [];
    foreach ($posStmt->fetchAll() as $i => $r) {
        $posRankRows[] = [
            'pos'            => $i + 1,
            'id'             => (int)$r['id'],
            'title'          => movieTitle($r),
            'poster_path'    => $r['poster_path'],
            'display_poster' => moviePosterUrl($r, 'w92'),
        ];
    }

    // Build JgJ ranking rows for JS
    $rankRows = array_map(function($r) {
        $wins   = (int)$r['wins'];
        $played = (int)$r['played'];
        $ratio  = $played > 0 ? round($wins / $played * 100) : 0;
        return [
            'id'             => (int)$r['id'],
            'title'          => movieTitle($r),
            'poster_path'    => $r['poster_path'],
            'display_poster' => moviePosterUrl($r, 'w92'),
            'wins'           => $wins,
            'losses'         => (int)$r['losses'],
            'played'         => $played,
            'ratio'          => $ratio,
        ];
    }, $ranking);

    // Add display fields to next_duel
    if ($nextDuel) {
        $nextDuel['a_display_title']  = movieTitle(['title' => $nextDuel['a_title'], 'title_en' => $nextDuel['a_title_en'] ?? null]);
        $nextDuel['b_display_title']  = movieTitle(['title' => $nextDuel['b_title'], 'title_en' => $nextDuel['b_title_en'] ?? null]);
        $nextDuel['a_display_poster'] = moviePosterUrl(['poster_path' => $nextDuel['a_poster'], 'poster_path_en' => $nextDuel['a_poster_en'] ?? null, 'imdb_id' => $nextDuel['a_imdb'] ?? null]);
        $nextDuel['b_display_poster'] = moviePosterUrl(['poster_path' => $nextDuel['b_poster'], 'poster_path_en' => $nextDuel['b_poster_en'] ?? null, 'imdb_id' => $nextDuel['b_imdb'] ?? null]);
        $nextDuel['a_overview']       = $nextDuel['a_overview'] ?? '';
        $nextDuel['b_overview']       = $nextDuel['b_overview'] ?? '';
    }

    $counters = getActivityCounters($userId);
    echo json_encode([
        'ok'          => true,
        'done'        => $done,
        'total'       => $total,
        'winner_id'   => $winnerId,
        'next_duel'   => $nextDuel,
        'ranking'     => $rankRows,
        'pos_ranking' => $posRankRows,
        'evaluated'   => array_map('intval', $evaluated),
        'duel_result'    => $duelResult,
        'winner_context' => $winnerContext,
        'comm_ranks'     => $commRanks,
        'hdrDuels'    => $counters['totalDuels'],
        'hdrFilms'    => $counters['uniqueFilms'],
    ]);
    exit;
}

// ── AJAX: Einzelnen Film zum Pool hinzufügen ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_film') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

    $filmId = (int)($_POST['film_id'] ?? 0);
    if (!$filmId) { echo json_encode(['ok' => false, 'error' => 'invalid']); exit; }

    // Prüfen ob der Film existiert
    $chk = $db->prepare("SELECT 1 FROM movies WHERE id = ?");
    $chk->execute([$filmId]);
    if (!$chk->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'film_not_found']); exit;
    }

    $db->prepare("INSERT IGNORE INTO jgj_pool (user_id, movie_id) VALUES (?, ?)")
       ->execute([$userId, $filmId]);

    echo json_encode(['ok' => true, 'pool_size' => jgjPoolSize($db, $userId)]);
    exit;
}

// ── AJAX: Pool erweitern ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_films') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

    $count = min(200, max(1, (int)($_POST['count'] ?? 50)));

    // Get next N films from user_position_ranking not yet in pool (filtered by media type)
    $stmt = $db->prepare("
        SELECT upr.movie_id
        FROM user_position_ranking upr
        JOIN movies m ON m.id = upr.movie_id
        LEFT JOIN jgj_pool p ON p.user_id = upr.user_id AND p.movie_id = upr.movie_id
        WHERE upr.user_id = ? AND p.movie_id IS NULL" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
        ORDER BY upr.position ASC
        LIMIT ?");
    $stmt->execute([$userId, $count]);
    $newIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($newIds)) {
        echo json_encode(['ok' => false, 'error' => 'no_more_films']); exit;
    }

    $ph = implode(',', array_fill(0, count($newIds), '(?,?)'));
    $vals = [];
    foreach ($newIds as $id) { $vals[] = $userId; $vals[] = (int)$id; }
    $db->prepare("INSERT IGNORE INTO jgj_pool (user_id, movie_id) VALUES $ph")->execute($vals);

    $poolSize = jgjPoolSize($db, $userId);
    $done     = jgjDoneCount($db, $userId);
    $total    = jgjTotalMatches($poolSize);

    echo json_encode([
        'ok'       => true,
        'added'    => count($newIds),
        'pool'     => $poolSize,
        'done'     => $done,
        'total'    => $total,
        'pending'  => $total - $done,
    ]);
    exit;
}

// ── AJAX: Undo ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'undo') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

    // Letztes Ergebnis holen (gefiltert nach aktuellem Medientyp)
    $last = $db->prepare("SELECT r.id, r.movie_a_id, r.movie_b_id, r.winner_id
                          FROM jgj_results r JOIN movies m ON m.id = r.winner_id
                          WHERE r.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
                          ORDER BY r.id DESC LIMIT 1");
    $last->execute([$userId]);
    $row = $last->fetch();

    if (!$row) { echo json_encode(['ok' => false, 'error' => 'nothing_to_undo']); exit; }

    $db->prepare("DELETE FROM jgj_results WHERE id = ? AND user_id = ?")
       ->execute([$row['id'], $userId]);

    // Das rückgängig gemachte Duell wieder als nächstes zeigen (Filmdaten laden)
    $aId = (int)$row['movie_a_id'];
    $bId = (int)$row['movie_b_id'];
    $films = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, imdb_id, COALESCE(NULLIF(wikipedia,''), overview) AS overview FROM movies WHERE id IN (?,?)");
    $films->execute([$aId, $bId]);
    $filmMap = array_column($films->fetchAll(), null, 'id');
    $undoDuel = isset($filmMap[$aId], $filmMap[$bId]) ? [
        'a_id'             => $aId,
        'a_title'          => movieTitle($filmMap[$aId]),
        'a_year'           => $filmMap[$aId]['year'],
        'a_poster'         => $filmMap[$aId]['poster_path'],
        'a_display_poster' => moviePosterUrl($filmMap[$aId]),
        'a_overview'       => $filmMap[$aId]['overview'] ?? '',
        'b_id'             => $bId,
        'b_title'          => movieTitle($filmMap[$bId]),
        'b_year'           => $filmMap[$bId]['year'],
        'b_poster'         => $filmMap[$bId]['poster_path'],
        'b_display_poster' => moviePosterUrl($filmMap[$bId]),
        'b_overview'       => $filmMap[$bId]['overview'] ?? '',
    ] : null;

    $poolSize = jgjPoolSize($db, $userId);
    $done     = jgjDoneCount($db, $userId);
    $total    = jgjTotalMatches($poolSize);
    $ranking  = jgjRanking($db, $userId);
    $rankRows = array_map(function($r) {
        $wins = (int)$r['wins']; $played = (int)$r['played'];
        return ['id' => (int)$r['id'], 'title' => movieTitle($r), 'poster_path' => $r['poster_path'],
                'display_poster' => moviePosterUrl($r, 'w92'),
                'wins' => $wins, 'losses' => (int)$r['losses'], 'played' => $played,
                'ratio' => $played > 0 ? round($wins / $played * 100) : 0];
    }, $ranking);

    $counters = getActivityCounters($userId);
    echo json_encode(['ok' => true, 'done' => $done, 'total' => $total, 'undo_duel' => $undoDuel, 'ranking' => $rankRows,
                      'hdrDuels' => $counters['totalDuels'], 'hdrFilms' => $counters['uniqueFilms']]);
    exit;
}

// ── Pool aus Turnierdaten initialisieren ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'init_from_tournament' && csrfValid()) {
    $tId = $db->prepare("SELECT id FROM user_tournaments WHERE user_id = ? AND status = 'completed' AND media_type = ? ORDER BY id DESC LIMIT 1");
    $tId->execute([$userId, activeMtForDb()]);
    $tournamentId = (int)($tId->fetchColumn() ?: 0);
    if ($tournamentId) {
        // Try tournament_results first, then tournament_films
        $ids = [];
        $s1 = $db->prepare("SELECT movie_id FROM tournament_results WHERE tournament_id = ? AND user_id = ? ORDER BY score DESC, wins DESC LIMIT 64");
        $s1->execute([$tournamentId, $userId]);
        $ids = $s1->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) < 2) {
            $s2 = $db->prepare("SELECT movie_id FROM tournament_films WHERE tournament_id = ? ORDER BY points DESC, seed ASC LIMIT 64");
            $s2->execute([$tournamentId]);
            $ids = $s2->fetchAll(PDO::FETCH_COLUMN);
        }
        if (count($ids) >= 2) {
            $ph   = implode(',', array_fill(0, count($ids), '(?,?)'));
            $vals = [];
            foreach ($ids as $id) { $vals[] = $userId; $vals[] = (int)$id; }
            $db->prepare("INSERT IGNORE INTO jgj_pool (user_id, movie_id) VALUES $ph")->execute($vals);
        }
    }
    header('Location: /jgj.php');
    exit;
}

// ── Pool initialisieren (Form-POST) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'init' && csrfValid()) {
    $count = min(200, max(2, (int)($_POST['count'] ?? 50)));

    $stmt = $db->prepare("
        SELECT upr.movie_id FROM user_position_ranking upr
        JOIN movies m ON m.id = upr.movie_id
        WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
        ORDER BY upr.position ASC LIMIT ?");
    $stmt->execute([$userId, $count]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($ids) >= 2) {
        $ph   = implode(',', array_fill(0, count($ids), '(?,?)'));
        $vals = [];
        foreach ($ids as $id) { $vals[] = $userId; $vals[] = (int)$id; }
        $db->prepare("INSERT IGNORE INTO jgj_pool (user_id, movie_id) VALUES $ph")->execute($vals);
    }

    header('Location: /jgj.php');
    exit;
}

// ── JgJ verlassen → Phase IV freischalten ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'leave_jgj' && csrfValid()) {
    try {
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS jgj_phase_cleared TINYINT(1) NOT NULL DEFAULT 0");
    } catch (\PDOException $e) {}
    $db->prepare("UPDATE users SET jgj_phase_cleared = 1 WHERE id = ?")->execute([$userId]);
    header('Location: /sortieren.php');
    exit;
}

// ── Pool zurücksetzen ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset' && csrfValid()) {
    $db->prepare("DELETE r FROM jgj_results r JOIN movies m ON m.id = r.movie_a_id WHERE r.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m'))->execute([$userId]);
    $db->prepare("DELETE p FROM jgj_pool p JOIN movies m ON m.id = p.movie_id WHERE p.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m'))->execute([$userId]);
    header('Location: /jgj.php');
    exit;
}

// ── Seitendaten laden ─────────────────────────────────────────────────────────
try {
    $poolSize  = jgjPoolSize($db, $userId);

    // Phase-3-Auto-Init: Die 64 Gewinner der Runde der 128
    // Runde der 128 = runde (total_rounds - 6) in einem 1024er-Bracket (128→64)
    // Auch wenn Pool zu klein (< 64 und keine Duelle gespielt) → neu initialisieren
    // Admins bypassen requirePhase → daher Phase >= 3 ODER abgeschlossenes Turnier vorhanden
    $doneCount = jgjDoneCount($db, $userId);
    $tStmt = $db->prepare("SELECT id FROM user_tournaments WHERE user_id = ? AND status = 'completed' AND media_type = ? LIMIT 1");
    $tStmt->execute([$userId, activeMtForDb()]);
    $hasCompletedTournament = (bool)$tStmt->fetch();
    if (($poolSize === 0 || ($poolSize < 64 && $doneCount === 0)) && $hasCompletedTournament) {
        if ($poolSize > 0 && $doneCount === 0) {
            $db->prepare("DELETE p FROM jgj_pool p JOIN movies m ON m.id = p.movie_id WHERE p.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m'))->execute([$userId]);
            $poolSize = 0;
        }
        // Gewinner der Runde mit 128 verbleibenden Filmen
        // Für N-Film-Turnier: Runde der 128 = total_rounds - 6
        $stmt = $db->prepare("
            SELECT DISTINCT tm.winner_id AS movie_id
            FROM tournament_matches tm
            JOIN user_tournaments ut ON ut.id = tm.tournament_id
            WHERE tm.tournament_id = (
                  SELECT id FROM user_tournaments
                  WHERE user_id = ? AND status = 'completed' AND media_type = ?
                  ORDER BY id DESC LIMIT 1
              )
              AND tm.runde = GREATEST(1, ut.total_rounds - 6)
              AND tm.winner_id IS NOT NULL");
        $stmt->execute([$userId, activeMtForDb()]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Fallback 1: Top 64 aus tournament_results wenn Runde-der-128-Query zu wenig liefert
        if (count($ids) < 2) {
            $fallback = $db->prepare("
                SELECT tr.movie_id
                FROM tournament_results tr
                WHERE tr.user_id = ?
                  AND tr.tournament_id = (
                      SELECT id FROM user_tournaments
                      WHERE user_id = ? AND status = 'completed' AND media_type = ?
                      ORDER BY id DESC LIMIT 1
                  )
                ORDER BY tr.score DESC, tr.wins DESC
                LIMIT 64");
            $fallback->execute([$userId, $userId, activeMtForDb()]);
            $ids = $fallback->fetchAll(PDO::FETCH_COLUMN);
        }
        // Fallback 2: Direkt aus tournament_films (immer befüllt für abgeschlossene Turniere)
        if (count($ids) < 2) {
            $fallback2 = $db->prepare("
                SELECT tf.movie_id
                FROM tournament_films tf
                WHERE tf.tournament_id = (
                    SELECT id FROM user_tournaments
                    WHERE user_id = ? AND status = 'completed' AND media_type = ?
                    ORDER BY id DESC LIMIT 1
                )
                ORDER BY tf.points DESC, tf.seed ASC
                LIMIT 64");
            $fallback2->execute([$userId, activeMtForDb()]);
            $ids = $fallback2->fetchAll(PDO::FETCH_COLUMN);
        }
        if (count($ids) >= 2) {
            $ph   = implode(',', array_fill(0, count($ids), '(?,?)'));
            $vals = [];
            foreach ($ids as $id) { $vals[] = $userId; $vals[] = (int)$id; }
            $db->prepare("INSERT IGNORE INTO jgj_pool (user_id, movie_id) VALUES $ph")->execute($vals);
            $poolSize = jgjPoolSize($db, $userId);
        }
    }

    $done      = jgjDoneCount($db, $userId);
    $total     = jgjTotalMatches($poolSize);
    $pending   = $total - $done;
    $pct       = $total > 0 ? round($done / $total * 100, 1) : 0;
    $lastIds   = $_SESSION['jgj_last_ids'] ?? [];
    $nextDuel  = $poolSize >= 2 ? jgjNextDuel($db, $userId, $lastIds) : null;
    $ranking   = $poolSize >= 1 ? jgjRanking($db, $userId) : [];
    $evaluated = $poolSize >= 2 ? jgjEvaluatedIds($db, $userId, $poolSize) : [];
    $evaluatedSet = array_flip(array_map('intval', $evaluated));

    // Community-Ränge + persönliche Positionen für initiale Anzeige
    $initCommRanks = $nextDuel
        ? buildCommRanks($db, (int)$nextDuel['a_id'], $nextDuel['a_title'],
                              (int)$nextDuel['b_id'], $nextDuel['b_title'], $userId, getActiveMtFilter())
        : null;

    // Meine Rangliste (linke Sidebar)
    $posRankStmt = $db->prepare("
        SELECT upr.position AS pos, m.id, m.title, m.title_en, m.poster_path, m.poster_path_en
        FROM user_position_ranking upr
        JOIN movies m ON m.id = upr.movie_id
        WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
        ORDER BY upr.position ASC LIMIT 200");
    $posRankStmt->execute([$userId]);
    $posRanking = [];
    foreach ($posRankStmt->fetchAll() as $i => $r) { $posRanking[] = array_merge($r, ['pos' => $i + 1]); }

    // Wie viele weitere Filme aus Meine Rangliste können noch hinzugefügt werden? (gefiltert nach Medientyp)
    $moreAvailStmt = $db->prepare("
        SELECT COUNT(*) FROM user_position_ranking upr
        JOIN movies m ON m.id = upr.movie_id
        LEFT JOIN jgj_pool p ON p.user_id = upr.user_id AND p.movie_id = upr.movie_id
        WHERE upr.user_id = ? AND p.movie_id IS NULL" . seriesSqlFilter('m') . moviesSqlFilter('m'));
    $moreAvailStmt->execute([$userId]);
    $moreAvail = (int)$moreAvailStmt->fetchColumn();
} catch (\PDOException $e) {
    error_log('jgj.php page data error: ' . $e->getMessage());
    $poolSize = 0; $done = 0; $total = 0; $pending = 0; $pct = 0;
    $nextDuel = null; $ranking = []; $evaluated = []; $evaluatedSet = [];
    $posRanking = []; $moreAvail = 0;
}

// Add display fields to initial nextDuel
if ($nextDuel) {
    $nextDuel['a_display_title']  = movieTitle(['title' => $nextDuel['a_title'], 'title_en' => $nextDuel['a_title_en'] ?? null]);
    $nextDuel['b_display_title']  = movieTitle(['title' => $nextDuel['b_title'], 'title_en' => $nextDuel['b_title_en'] ?? null]);
    $nextDuel['a_display_poster'] = moviePosterUrl(['poster_path' => $nextDuel['a_poster'], 'poster_path_en' => $nextDuel['a_poster_en'] ?? null, 'imdb_id' => $nextDuel['a_imdb'] ?? null]);
    $nextDuel['b_display_poster'] = moviePosterUrl(['poster_path' => $nextDuel['b_poster'], 'poster_path_en' => $nextDuel['b_poster_en'] ?? null, 'imdb_id' => $nextDuel['b_imdb'] ?? null]);
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.jgj-page { background: #14325a; min-height: 100vh; color: #fff; }
.container-xxl.jgj-wrap { max-width: 2200px; margin: 0 auto; }
.duel-poster-wrap { max-width: 600px !important; }

/* Layout */
.liga-3col   { display: flex; gap: 20px; align-items: flex-start; padding: 0 5%; }
.liga-side   { flex: 0 0 420px; min-width: 0; display: flex; flex-direction: column; overflow: hidden;
               position: sticky; top: 80px; align-self: flex-start; max-height: calc(100vh - 180px); }
.liga-center { flex: 1; min-width: 0; }
.liga-side .turnier-ranking-wrap { flex: 1; display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
.liga-side .turnier-ranking-list { flex: 1; min-height: 0; overflow-y: auto; }
/* Tablet 768 – 1400 px: rechte Sidebar weg, linke Sidebar schmaler */
@media (max-width: 1400px) and (min-width: 768px) {
    .liga-3col                    { padding: 0 2%; gap: 12px; }
    .liga-3col > div:nth-child(1) { flex: 0 0 320px; }
    .liga-3col > div:nth-child(3) { display: none !important; }
    .liga-side .turnier-ranking-list { max-height: 60vh; }
    .duel-poster-wrap             { max-width: min(600px, 27vh) !important; max-height: none !important; }
}

/* Mobile < 768 px: nur Duel sichtbar, Sidebars ausgeblendet */
@media (max-width: 767px) {
    .liga-3col   { padding: 0 1%; }
    .liga-side   { display: none !important; }
    .liga-center { flex: 0 0 100%; }
    .duel-poster-wrap { max-width: 600px !important; max-height: none; }
}

/* Mobile Querformat: Cover vollständig sichtbar */
@media (max-width: 900px) and (orientation: landscape) {
    .jgj-page .liga-side { display: none !important; }
    .jgj-page .duel-poster-wrap { max-width: none !important; display: flex; justify-content: center; }
    .jgj-page .duel-poster { max-height: calc(100dvh - 180px) !important; width: auto !important; object-fit: contain !important; }
}

/* Duel Arena */
.duel-arena { display: flex; align-items: stretch; gap: 0; border-radius: 12px; overflow: hidden; border: 1px solid rgba(255,255,255,.08); }
.duel-side { flex: 1; cursor: pointer; display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,.03); padding: 20px 16px; transition: background .2s; position: relative; overflow: hidden; }
@media (hover: hover) { .duel-side:hover { background: rgba(232,184,75,.1); } .duel-side:hover .duel-overlay { opacity: 1; } }
.duel-side.kb-active { background: rgba(232,184,75,.1) !important; }
.duel-side.kb-active .duel-overlay { opacity: 1; }
.duel-side.winner { background: rgba(232,184,75,.18) !important; }
.duel-side.loser  { opacity: .4; }
.duel-overlay { position: absolute; inset: 0; background: rgba(232,184,75,.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .2s; color: #e8b84b; font-size: 2rem; }
.duel-title { color: #e0e0e0; font-weight: 600; font-size: .95rem; text-align: center; margin-top: 14px; margin-bottom: 4px; }
.duel-meta  { color: rgba(255,255,255,.4); font-size: .8rem; text-align: center; }
.duel-info-link { display: block; text-align: center; padding: .1rem .7rem .5rem; color: rgba(255,255,255,.2); font-size: .72rem; text-decoration: none; line-height: 1.6; }
.duel-info-link:hover { color: #e8b84b; }
.vs-divider { display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative; padding: 0 8px; background: rgba(0,0,0,.3); min-width: 48px; }
.vs-divider #undo-btn { position: absolute; bottom: 2.5rem; }
.vs-circle  { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a; font-weight: 800; font-size: .85rem; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.duel-poster { width: 100%; aspect-ratio: 2/3; object-fit: cover; display: block; transition: opacity .2s ease-in; }

/* Ranking panels */
.turnier-ranking-wrap { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); border-radius: 12px; overflow: hidden; }
.turnier-ranking-header { padding: .75rem 1rem; font-size: .8rem; font-weight: 700; color: #e8b84b; display: flex; align-items: center; gap: .4rem; border-bottom: 1px solid rgba(255,255,255,.08); background: rgba(232,184,75,.06); }
.turnier-ranking-list { overflow-y: auto; scrollbar-width: thin; scrollbar-color: rgba(232,184,75,.2) transparent; }
.turnier-ranking-list::-webkit-scrollbar { width: 4px; }
.turnier-ranking-list::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 2px; }
.turnier-rank-row { display: flex; align-items: center; gap: .5rem; padding: .4rem .75rem; border-bottom: 1px solid rgba(255,255,255,.05); }
.turnier-rank-row:last-child { border-bottom: none; }
.turnier-rank-row:hover { background: rgba(255,255,255,.04); }
.turnier-rank-row.next-duel-film { background: rgba(232,184,75,.12); border-left: 3px solid #e8b84b; }
.turnier-rank-num { min-width: 1.6rem; font-size: .75rem; font-weight: 700; color: rgba(255,255,255,.4); text-align: right; }
.turnier-rank-num.top { color: #e8b84b; }
.turnier-rank-poster { width: 26px; height: 39px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
.turnier-rank-title { flex: 1; font-size: .8rem; color: rgba(255,255,255,.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.turnier-points { font-size: .7rem; font-weight: 700; color: rgba(255,255,255,.55); white-space: nowrap; text-align: right; }

/* Evaluated badge */
.jgj-badge { display: inline-flex; align-items: center; justify-content: center; width: 14px; height: 14px; border-radius: 50%; background: #e8b84b; color: #1a1a1a; font-size: .55rem; flex-shrink: 0; }

/* Win ratio bar */
.ratio-bar-wrap { flex: 0 0 50px; background: rgba(255,255,255,.08); border-radius: 3px; height: 6px; overflow: hidden; }
.ratio-bar-fill { height: 100%; background: linear-gradient(90deg, #e8b84b, #c4942a); border-radius: 3px; transition: width .3s; }

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

/* Setup / Completion */
.setup-card { max-width: 540px; margin: 0 auto; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.1); border-radius: 16px; padding: 40px 32px; text-align: center; }
.completion-card { max-width: 700px; margin: 0 auto; text-align: center; padding: 48px 40px; background: rgba(255,255,255,.03); border: 1px solid rgba(232,184,75,.3); border-radius: 16px; }
.top3-row { display: flex; justify-content: center; align-items: flex-start; gap: 12px; margin: 32px 0; }
.top3-item { display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 0; max-width: 210px; }
.top3-item.rank-1 { order: 2; }
.top3-item.rank-2 { order: 1; }
.top3-item.rank-3 { order: 3; }
.top3-medal { font-size: 1.6rem; line-height: 1; height: 36px; display: flex; align-items: center; justify-content: center; }
.top3-poster-wrap { width: 100%; border-radius: 8px; overflow: hidden; box-shadow: 0 8px 32px rgba(0,0,0,.5); flex-shrink: 0; line-height: 0; }
.top3-poster-wrap a { display: block; line-height: 0; }
.top3-poster { width: 100% !important; height: auto !important; aspect-ratio: 2/3; object-fit: cover; object-position: center center; display: block !important; }
.top3-title { font-size: .8rem; color: rgba(255,255,255,.8); font-weight: 600; width: 100%; text-align: center; overflow-wrap: break-word; margin-top: 8px; }
.top3-item.rank-1 .top3-title { color: #e8b84b; }
.phase4-badge { display: inline-flex; align-items: center; gap: 8px; background: rgba(232,184,75,.12); border: 1px solid rgba(232,184,75,.4); color: #e8b84b; border-radius: 20px; padding: 8px 20px; font-size: .85rem; font-weight: 700; margin-bottom: 24px; }
.count-btn { background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15); color: #e0e0e0; border-radius: 8px; padding: 8px 18px; font-size: .9rem; cursor: pointer; transition: background .2s, border-color .2s; }
.count-btn:hover, .count-btn.active { background: rgba(232,184,75,.2); border-color: #e8b84b; color: #e8b84b; }
.btn-gold { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a; font-weight: 700; border: none; border-radius: 8px; padding: 12px 28px; font-size: 1rem; cursor: pointer; transition: opacity .2s; }
.btn-gold:hover { opacity: .85; }
.btn-gold-link { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a !important; font-weight: 700; border-radius: 8px; padding: 12px 28px; text-decoration: none !important; display: inline-block; }

/* Add more panel */
.add-more-panel { background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.08); border-radius: 8px; padding: 12px; margin-top: 8px; }
</style>

<main style="padding-top:6px; background:#14325a; min-height:100vh;">
<div class="container-xxl jgj-wrap px-3 px-lg-4">

<p class="text-center mb-3" style="color:rgba(255,255,255,.4); font-size:clamp(1.4rem,2.5vw,2.5rem); white-space:nowrap; overflow:hidden;">
    Welchen Film schaust du dir lieber an?
</p>

<?php if ($poolSize < 2): ?>
<!-- ── Setup-Screen ────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-center py-5">
<div class="setup-card">
    <div class="text-gold mb-3" style="font-size:3rem; line-height:1;">
        <i class="bi bi-diagram-3-fill"></i>
    </div>
    <h2 class="fw-bold mb-2">Jeder gegen Jeden</h2>
    <p class="opacity-75 mb-4" style="max-width:420px; margin:0 auto;">
        Alle Filme im Pool spielen gegeneinander. Für jeden Sieg gibt es 1 Punkt.
        Die Rangliste zeigt die Siege/Niederlagen-Quote.
    </p>

    <?php
    $rankCount = count($posRanking);
    if (!$hasCompletedTournament && $rankCount < 2):
    ?>
    <div class="alert alert-warning">
        Mindestens 2 Filme nötig. Schließe zuerst dein
        <a href="/turnier.php" class="text-gold">Sichtungsturnier</a> ab.
    </div>
    <?php elseif ($hasCompletedTournament && $rankCount < 2): ?>
    <div class="alert alert-info mb-3" style="background:rgba(232,184,75,.12); border-color:rgba(232,184,75,.3); color:#e8b84b;">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Pool konnte nicht automatisch initialisiert werden.
    </div>
    <form method="post">
        <input type="hidden" name="action"     value="init_from_tournament">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <button type="submit" class="btn-gold">
            <i class="bi bi-play-fill me-2"></i>Top 64 aus Turnier laden
        </button>
    </form>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="action"     value="init">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="count"      id="init-count-input" value="50">

        <div class="mb-4">
            <div class="text-light small mb-2" style="opacity:.65;">
                <i class="bi bi-collection-fill me-1"></i>Startgröße des Pools (Top N aus Meine Rangliste):
            </div>
            <div class="d-flex gap-2 justify-content-center flex-wrap">
                <?php foreach ([10,25,50,100,150,200] as $opt):
                    if ($opt > $rankCount) break; ?>
                <button type="button"
                        class="count-btn<?= $opt === 50 ? ' active' : '' ?>"
                        onclick="document.getElementById('init-count-input').value=<?= $opt ?>;
                                 document.querySelectorAll('.count-btn').forEach(b=>b.classList.remove('active'));
                                 this.classList.add('active');"><?= $opt ?></button>
                <?php endforeach; ?>
            </div>
            <div class="text-muted small mt-2" style="opacity:.5;">
                <?= number_format($rankCount) ?> Filme in Meine Rangliste verfügbar
            </div>
        </div>

        <button type="submit" class="btn-gold">
            <i class="bi bi-play-fill me-2"></i>Jeder gegen Jeden starten
        </button>
    </form>
    <?php endif; ?>
</div>
</div>

<?php elseif ($nextDuel === null && $pending <= 0): ?>
<!-- ── Phase-III-Abschluss-Screen ─────────────────────────────────────────── -->
<?php
$top3 = array_slice($ranking, 0, 3);
$medals = ['1' => '🥇', '2' => '🥈', '3' => '🥉'];
?>
<div class="d-flex justify-content-center py-4">
<div class="completion-card">

    <div class="phase4-badge">
        <i class="bi bi-stars"></i> Jeder gegen Jeden abgeschlossen
    </div>

    <div style="font-size:3.5rem; line-height:1; margin-bottom:8px;">🏆</div>
    <h2 class="fw-bold mb-1" style="color:#e8b84b; font-size:1.8rem;">Jeder gegen Jeden abgeschlossen!</h2>
    <p style="color:rgba(255,255,255,.5); font-size:.9rem; margin-bottom:6px;">
        Vielen Dank! Du hast alle
        <strong style="color:#e8b84b;"><?= number_format($done) ?></strong> Duelle
        mit deinen <?= $poolSize ?> Filmen absolviert.
    </p>
    <p style="color:rgba(255,255,255,.65); font-size:.95rem; margin-bottom:4px;">
        Deine <strong style="color:#e8b84b;"><?= $poolSize ?> Lieblingsfilme</strong> stehen jetzt in exakter Reihenfolge.
    </p>

    <!-- Top 3 Podium -->
    <?php if (count($top3) >= 1): ?>
    <div class="top3-row">
        <?php foreach ($top3 as $i => $film):
            $rank = $i + 1;
        ?>
        <div class="top3-item rank-<?= $rank ?>">
            <div class="top3-medal"><?= $medals[(string)$rank] ?? '' ?></div>
            <div class="top3-poster-wrap">
                <a href="/film.php?id=<?= (int)$film['id'] ?>" target="_blank">
                    <img src="<?= e(moviePosterUrl($film, 'w342')) ?>"
                         alt="<?= e(movieTitle($film)) ?>"
                         class="top3-poster"
                         loading="lazy" onerror="this.src='https://placehold.co/160x240/1e3a5f/e8b84b?text=?'">
                </a>
            </div>
            <div class="top3-title" title="<?= e(movieTitle($film)) ?>">
                <?= e(movieTitle($film)) ?>
            </div>
            <div style="font-size:.7rem; color:rgba(255,255,255,.35);">
                <?= (int)$film['wins'] ?>S / <?= (int)$film['losses'] ?>N
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <hr style="border-color:rgba(255,255,255,.1); margin: 24px 0;">

    <p style="color:rgba(255,255,255,.5); font-size:.85rem; margin-bottom:16px;">
        Ab jetzt kannst du alle Funktionen und Bewertungsmodi frei nutzen.
    </p>

    <!-- Weitere Filme direkt hier aktivieren -->
    <?php if ($moreAvail > 0): ?>
    <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); border-radius:10px; padding:14px 18px; margin-bottom:20px;">
        <div style="color:rgba(255,255,255,.5); font-size:.8rem; margin-bottom:10px;">
            <i class="bi bi-plus-circle me-1" style="color:#e8b84b;"></i>
            Weitere Filme zu <em>Jeder gegen Jeden</em> hinzufügen
            <span style="color:rgba(255,255,255,.3);">(<?= $moreAvail ?> verfügbar)</span>
        </div>
        <div class="d-flex gap-2 justify-content-center flex-wrap" id="completion-add-btns">
            <?php foreach ([1,3,5,10,25,50,100] as $opt):
                if ($opt > $moreAvail) break; ?>
            <button type="button" class="count-btn"
                    onclick="completionAddFilms(<?= $opt ?>, this)">+<?= $opt ?></button>
            <?php endforeach; ?>
        </div>
        <div id="completion-add-msg" style="color:#4caf50; font-size:.8rem; margin-top:8px; display:none;">
            <i class="bi bi-check-circle me-1"></i><span></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex gap-3 justify-content-center flex-wrap">
        <a href="/rangliste.php?tab=jgj" class="btn-gold-link" style="font-size:.9rem; padding:10px 24px;">
            <i class="bi bi-list-ol me-2"></i>Meine JgJ-Rangliste
        </a>
        <a href="/rangliste.php?tab=persoenlich" class="btn-gold-link" style="font-size:.9rem; padding:10px 24px; background:rgba(232,184,75,.15); border:1px solid rgba(232,184,75,.35); color:#e8b84b; text-decoration:none; border-radius:8px; background:none;">
            <i class="bi bi-collection-fill me-2"></i>Meine Rangliste
        </a>
    </div>

</div>
</div>

<?php else: ?>
<!-- ── Duel-Screen ────────────────────────────────────────────────────────── -->
<div class="liga-3col">

    <!-- ── Links: Meine Rangliste ──────────────────────────────────────────── -->
    <div class="liga-side">
        <div class="turnier-ranking-wrap">
            <div class="turnier-ranking-header">
                <i class="bi bi-list-ol"></i> <?= $mtActive === 'tv' ? 'Meine Rangliste Serien' : ($mtActive === 'movie' ? 'Meine Rangliste Filme' : 'Meine Rangliste') ?>
            </div>
            <div id="pos-current-film" style="display:none; padding:.45rem .75rem; background:rgba(232,184,75,.1); border-bottom:1px solid rgba(232,184,75,.25); font-size:.78rem; color:#e8b84b; align-items:center; gap:.5rem;">
                <span style="font-weight:700; flex-shrink:0;" id="pos-current-num">#–</span>
                <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" id="pos-current-title"></span>
            </div>
            <div class="turnier-ranking-list" id="pos-ranking-list">
                <?php foreach ($posRanking as $r): ?>
                <div class="turnier-rank-row" data-film-id="<?= (int)$r['id'] ?>">
                    <span class="turnier-rank-num <?= (int)$r['pos'] <= 3 ? 'top' : '' ?>"><?= (int)$r['pos'] ?></span>
                    <img src="<?= e(moviePosterUrl($r, 'w92')) ?>"
                         alt="<?= e(movieTitle($r)) ?>" class="turnier-rank-poster"
                         width="26" height="39"
                         loading="lazy" onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
                    <div class="turnier-rank-title"><a href="/film.php?id=<?= (int)$r['id'] ?>" class="film-link" target="_blank"><?= e(movieTitle($r)) ?></a></div>
                    <?php if (isset($evaluatedSet[(int)$r['id']])): ?>
                    <span class="jgj-badge" title="JgJ abgeschlossen"><i class="bi bi-check-lg"></i></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <p class="text-center mt-2" style="font-size:.72rem;">
            <a href="/rangliste.php?tab=persoenlich" style="color:rgba(232,184,75,.5); text-decoration:none;">
                Alle <?= count($posRanking) ?> Filme anzeigen →
            </a>
        </p>
    </div>

    <!-- ── Mitte: Fortschritt + Duell ──────────────────────────────────────── -->
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

        <!-- Duel Arena -->
        <div id="duel-section">
        <div class="duel-arena" id="duel-arena">
            <div class="duel-side" id="movie-a"
                 data-id="<?= (int)$nextDuel['a_id'] ?>"
                 data-opponent="<?= (int)$nextDuel['b_id'] ?>"
                 data-overview="<?= e($nextDuel['a_overview'] ?? '') ?>"
                 data-overview-title="<?= e($nextDuel['a_display_title']) ?>">
                <div class="duel-poster-wrap">
                    <img class="duel-poster" fetchpriority="high" decoding="async"
                         src="<?= e($nextDuel['a_display_poster']) ?>"
                         alt="<?= e($nextDuel['a_display_title']) ?>"
                         onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                    <div class="duel-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
                </div>
                <div class="duel-title"><?= e($nextDuel['a_display_title']) ?></div>
                <div class="duel-meta"><?= (int)$nextDuel['a_year'] ?></div>
                <a href="/film.php?id=<?= (int)$nextDuel['a_id'] ?>" class="duel-info-link" target="_blank" onclick="event.stopPropagation()"><i class="bi bi-info-circle me-1"></i>Details</a>
            </div>

            <div class="vs-divider">
                <div class="vs-circle">VS</div>
                <button id="undo-btn" <?= $done === 0 ? 'disabled' : '' ?>
                        title="Letztes Duell rückgängig"
                        onclick="castUndo()"
                        style="background:none; border:none; padding:0; cursor:pointer;
                               color:rgba(255,255,255,.3); font-size:1.35rem; line-height:1;
                               transition:color .15s;"
                        onmouseover="if(!this.disabled)this.style.color='rgba(232,184,75,.7)'"
                        onmouseout="this.style.color='rgba(255,255,255,.3)'">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>
            </div>

            <div class="duel-side" id="movie-b"
                 data-id="<?= (int)$nextDuel['b_id'] ?>"
                 data-opponent="<?= (int)$nextDuel['a_id'] ?>"
                 data-overview="<?= e($nextDuel['b_overview'] ?? '') ?>"
                 data-overview-title="<?= e($nextDuel['b_display_title']) ?>">
                <div class="duel-poster-wrap">
                    <img class="duel-poster" fetchpriority="high" decoding="async"
                         src="<?= e($nextDuel['b_display_poster']) ?>"
                         alt="<?= e($nextDuel['b_display_title']) ?>"
                         onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                    <div class="duel-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
                </div>
                <div class="duel-title"><?= e($nextDuel['b_display_title']) ?></div>
                <div class="duel-meta"><?= (int)$nextDuel['b_year'] ?></div>
                <a href="/film.php?id=<?= (int)$nextDuel['b_id'] ?>" class="duel-info-link" target="_blank" onclick="event.stopPropagation()"><i class="bi bi-info-circle me-1"></i>Details</a>
            </div>
        </div>
        </div>

        <!-- Weitere Filme hinzufügen -->
        <?php if ($moreAvail > 0): ?>
        <div class="add-more-panel mt-3">
            <div class="d-flex align-items-center gap-3 flex-wrap justify-content-center">
                <span style="color:rgba(255,255,255,.45); font-size:.8rem;">
                    <i class="bi bi-plus-circle me-1"></i>Filme hinzufügen:
                </span>
                <div class="d-flex gap-2" id="add-btns">
                    <?php foreach ([1,5,10,25,50,100] as $opt):
                        if ($opt > $moreAvail) break; ?>
                    <button type="button" class="count-btn" style="padding:4px 12px; font-size:.8rem;"
                            onclick="addFilms(<?= $opt ?>, this)"><?= $opt ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>


        <!-- JgJ verlassen -->
        <div class="text-center mt-2">
            <form method="post" id="leave-jgj-form" style="display:inline;">
                <input type="hidden" name="action"     value="leave_jgj">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <button type="button"
                        class="btn btn-link p-0 text-decoration-none"
                        style="color:rgba(255,255,255,.2); font-size:.75rem;"
                        onclick="if(confirm('Jeder gegen Jeden verlassen?\nDeine bisherigen Duelle bleiben erhalten.')) document.getElementById(\'leave-jgj-form\').submit();">
                    <i class="bi bi-box-arrow-right me-1"></i>JgJ verlassen
                </button>
            </form>
        </div>

    </div><!-- /.liga-center -->

    <!-- ── Rechts: Statistiken ───────────────────────────────────────────── -->
    <div class="liga-side">
        <div class="turnier-ranking-wrap">
            <div class="turnier-ranking-header">
                <i class="bi bi-bar-chart-fill"></i> Statistiken
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
                    <span class="duel-comm-title"><?= htmlspecialchars($initCommRanks['a_title']) ?></span>
                </div>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank"><?= $initCommRanks['b_rank'] ? '#'.$initCommRanks['b_rank'] : '–' ?></span>
                    <span class="duel-comm-title"><?= htmlspecialchars($initCommRanks['b_title']) ?></span>
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
                    <span class="duel-comm-title"><?= htmlspecialchars($initCommRanks['a_title']) ?></span>
                </div>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank" style="background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.15); color:rgba(255,255,255,.7);"><?= $initCommRanks['b_my_pos'] !== null ? '#'.$initCommRanks['b_my_pos'] : '–' ?></span>
                    <span class="duel-comm-title"><?= htmlspecialchars($initCommRanks['b_title']) ?></span>
                </div>
                <?php else: ?>
                <div class="duel-stat-empty">Noch nicht bewertet</div>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-center mt-2" style="font-size:.72rem; color:rgba(255,255,255,.3);">
            <?= $poolSize ?> Filme im Pool &nbsp;·&nbsp; <?= number_format($pending) ?> offen
        </p>
    </div><!-- /.liga-side -->

</div><!-- /.liga-3col -->
<?php endif; ?>

</div><!-- /.jgj-wrap -->
</main>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const TMDB_W500 = 'https://image.tmdb.org/t/p/w500';
const TMDB_W92  = 'https://image.tmdb.org/t/p/w92';
const PLACEHOLDER = 'https://placehold.co/26x39/1e3a5f/e8b84b?text=?';
const INIT_COMM_RANKS = <?= json_encode($initCommRanks) ?>;
let voting = false;

// Initiale Community-Ränge anzeigen
document.addEventListener('DOMContentLoaded', () => updateDuelStats(null, null, INIT_COMM_RANKS));

// ── Click handler ─────────────────────────────────────────────────────────────
const arena = document.getElementById('duel-arena');
if (arena) {
    arena.addEventListener('click', function (e) {
        if (voting) return;
        const card = e.target.closest('.duel-side');
        if (!card) return;
        const winnerId = parseInt(card.dataset.id, 10);
        const loserId  = parseInt(card.dataset.opponent, 10);
        if (winnerId && loserId) castVote(winnerId, loserId, card);
    });
}

// ── Keyboard ──────────────────────────────────────────────────────────────────
document.addEventListener('keydown', function (e) {
    if (voting) return;
    let card = null;
    if (e.key === 'ArrowLeft')  card = document.getElementById('movie-a');
    if (e.key === 'ArrowRight') card = document.getElementById('movie-b');
    if (card) { card.classList.add('kb-active'); card.dispatchEvent(new Event('click', {bubbles:true})); }
});

// ── Vote ──────────────────────────────────────────────────────────────────────
function castVote(winnerId, loserId, card) {
    voting = true;
    card.classList.add('winner');
    document.querySelectorAll('.duel-side').forEach(c => { if (c !== card) c.classList.add('loser'); });

    const fd = new FormData();
    fd.append('action',    'vote');
    fd.append('csrf_token', CSRF);
    fd.append('winner_id', winnerId);
    fd.append('loser_id',  loserId);

    fetch('/jgj.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(handleResponse)
        .catch(() => {
            voting = false;
            document.querySelectorAll('.duel-side').forEach(c => c.classList.remove('winner','loser'));
        });
}

function handleResponse(data) {
    if (!data.ok) { location.reload(); return; }

    // Progress
    const pct = data.total > 0 ? Math.round(data.done / data.total * 100 * 10) / 10 : 0;
    const fill = document.getElementById('progress-fill');
    const txt  = document.getElementById('progress-text');
    if (fill) fill.style.width = pct + '%';
    if (txt)  txt.textContent = data.done.toLocaleString('de') + ' / ' + data.total.toLocaleString('de') + ' Duelle (' + pct + '%)';

    // Header-Zähler aktualisieren
    updateHdrCounters(data.hdrDuels, data.hdrFilms);

    // Statistik-Sidebar (rechts)
    updateDuelStats(data.duel_result, data.winner_context, data.comm_ranks);

    // Meine Rangliste (links) — soft aktualisieren
    if (data.pos_ranking) {
        updatePosRanking(data.pos_ranking, data.evaluated || []);
        if (data.next_duel) {
            setTimeout(() => scrollRankingToFilm(data.next_duel.a_id), 80);
        }
    } else if (data.evaluated && data.evaluated.length) {
        // Fallback: nur Badges setzen
        data.evaluated.forEach(id => addEvaluatedBadge(id));
    }

    // Next duel or done
    if (!data.next_duel) {
        location.reload();
        return;
    }

    // Cover vorab in den Browser-Cache laden bevor setCard die src setzt
    preloadPosters(data.next_duel.a_display_poster, data.next_duel.b_display_poster);
    setCard('movie-a', data.next_duel.a_id, data.next_duel.a_display_title || data.next_duel.a_title, data.next_duel.a_year, data.next_duel.a_display_poster, data.next_duel.b_id, data.next_duel.a_overview);
    setCard('movie-b', data.next_duel.b_id, data.next_duel.b_display_title || data.next_duel.b_title, data.next_duel.b_year, data.next_duel.b_display_poster, data.next_duel.a_id, data.next_duel.b_overview);
    const undoBtn = document.getElementById('undo-btn');
    if (undoBtn) undoBtn.disabled = false;
    voting = false;
}

// GC-sichere Referenzen für preload (verhindert dass Browser die Anfrage abbricht)
let _preloadRefs = [];
function preloadPosters(pA, pB) {
    _preloadRefs = [];
    [pA, pB].forEach(function(p) {
        if (!p) return;
        const i = new Image();
        // If already a full URL (display_poster), use it directly; otherwise prepend TMDB base
        i.src = p.startsWith('http') ? p.replace('/w92', '/w500') : TMDB_W500 + p;
        _preloadRefs.push(i);
    });
}

function setCard(cardId, id, title, year, poster, opponentId, overview) {
    const card = document.getElementById(cardId);
    if (!card) return;
    card.classList.remove('winner','loser','kb-active');
    card.dataset.id            = id;
    card.dataset.opponent      = opponentId;
    card.dataset.overview      = overview || '';
    card.dataset.overviewTitle = title;
    const img = card.querySelector('.duel-poster');
    if (img) {
        const newSrc = poster
            ? (poster.startsWith('http') ? poster.replace('/w92', '/w500') : TMDB_W500 + poster)
            : 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?';
        const absSrc = newSrc.startsWith('http') ? newSrc : window.location.origin + newSrc;
        if (img.src !== absSrc) {
            img.style.opacity = '0';
            img.onload  = function () { img.style.opacity = '1'; };
            img.onerror = function () { this.onerror = null; this.src = 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?'; img.style.opacity = '1'; };
            img.src = newSrc;
        }
        img.alt = title;
    }
    const t = card.querySelector('.duel-title'); if (t) t.textContent = title;
    const m = card.querySelector('.duel-meta');  if (m) m.textContent = year;
    const l = card.querySelector('.duel-info-link'); if (l) l.href = '/film.php?id=' + id;
}

function addEvaluatedBadge(id) {
    const row = document.querySelector('#pos-ranking-list [data-film-id="' + id + '"]');
    if (row && !row.querySelector('.jgj-badge')) {
        const badge = document.createElement('span');
        badge.className = 'jgj-badge';
        badge.title = 'JgJ abgeschlossen';
        badge.innerHTML = '<i class="bi bi-check-lg"></i>';
        row.appendChild(badge);
    }
}

function scrollRankingToFilm(filmId) {
    if (!filmId) return;
    const list = document.getElementById('pos-ranking-list');
    if (!list) return;
    list.querySelectorAll('.next-duel-film').forEach(r => r.classList.remove('next-duel-film'));
    const row = list.querySelector('[data-film-id="' + filmId + '"]');
    if (row) {
        row.classList.add('next-duel-film');
    }
    // Position-Badge aktualisieren
    const badge = document.getElementById('pos-current-film');
    const numEl = document.getElementById('pos-current-num');
    const ttlEl = document.getElementById('pos-current-title');
    if (badge) {
        const pos   = row?.querySelector('.turnier-rank-num')?.textContent?.trim() || '–';
        const title = row?.querySelector('.turnier-rank-title a')?.textContent?.trim() || '';
        if (numEl) numEl.textContent = '#' + pos;
        if (ttlEl) ttlEl.textContent = title;
        badge.style.display = row ? 'flex' : 'none';
    }
}

function updatePosRanking(rows, evaluatedIds) {
    const list = document.getElementById('pos-ranking-list');
    if (!list || !rows) return;
    const evalSet = new Set(evaluatedIds.map(Number));
    list.innerHTML = rows.map(r => {
        const img = r.display_poster || (r.poster_path ? TMDB_W92 + r.poster_path : PLACEHOLDER);
        const badge = evalSet.has(r.id)
            ? '<span class="jgj-badge" title="JgJ abgeschlossen"><i class="bi bi-check-lg"></i></span>'
            : '';
        return '<div class="turnier-rank-row" data-film-id="' + r.id + '">'
            + '<span class="turnier-rank-num' + (r.pos <= 3 ? ' top' : '') + '">' + r.pos + '</span>'
            + '<img src="' + img + '" class="turnier-rank-poster" width="26" height="39"'
            + ' onerror="this.src=\'' + PLACEHOLDER + '\'">'
            + '<div class="turnier-rank-title"><a href="/film.php?id=' + r.id + '" class="film-link" target="_blank">' + escHtml(r.title) + '</a></div>'
            + badge
            + '</div>';
    }).join('');
}

function renderContextSection(secId, labelText, rows, winnerId) {
    const sec = document.getElementById(secId);
    if (!sec || !rows || !rows.length) { if (sec) sec.style.display = 'none'; return; }
    sec.style.display = '';
    sec.innerHTML = '<div class="duel-stat-lbl">' + labelText + '</div>'
        + rows.map(r => {
            const win = r.id === winnerId;
            return '<div class="ctx-row' + (win ? ' is-winner' : '') + '">'
                + '<span class="ctx-pos">#' + r.pos + '</span>'
                + '<span class="ctx-title">' + escHtml(r.title) + '</span>'
                + '</div>';
        }).join('');
}

const RANK_SFX = <?= json_encode($rankSfx) ?>;

function updateDuelStats(duelResult, winnerContext, commRanks) {
    // ── Letztes Duell ──────────────────────────────────────────────────────
    const lastSec = document.getElementById('last-duel-stat');
    if (lastSec && duelResult) {
        const wT = duelResult.winner_title;
        const lT = duelResult.loser_title;
        const wP = duelResult.winner_old_pos;
        const lP = duelResult.loser_old_pos;
        const changed = duelResult.rank_changed;
        const rankStr = p => p ? ' <span style="opacity:.45;font-size:.78rem;">(#' + p + ')</span>' : '';
        let html;
        if (changed) {
            html = '<div class="duel-stat-result">'
                 + '<span class="rank-up">' + escHtml(wT) + '</span>' + rankStr(wP)
                 + ' übernimmt Platz von '
                 + '<strong>' + escHtml(lT) + '</strong>' + rankStr(lP)
                 + '</div>';
        } else {
            html = '<div class="duel-stat-result">'
                 + '<strong>' + escHtml(wT) + '</strong>' + rankStr(wP)
                 + ' besiegt '
                 + '<strong>' + escHtml(lT) + '</strong>' + rankStr(lP)
                 + '<span class="no-change">Rangliste unverändert</span>'
                 + '</div>';
        }
        lastSec.innerHTML = '<div class="duel-stat-lbl">Letztes Duell</div>' + html;
    }

    // ── Kontext-Fenster um den Sieger ────────────────────────────────────────
    if (winnerContext) {
        renderContextSection('comm-context-stat', 'Community Rangliste' + RANK_SFX,
            winnerContext.comm, winnerContext.winner_id);
        renderContextSection('my-context-stat', 'Meine Rangliste' + RANK_SFX,
            winnerContext.mine, winnerContext.winner_id);
    }

    // ── Community-Ranking des aktuellen Duells ──────────────────────────────
    const commSec = document.getElementById('comm-rank-stat');
    if (commSec && commRanks) {
        const filmRow = (rank, title) =>
            '<div class="duel-comm-film">'
            + '<span class="duel-comm-rank">' + (rank ? '#' + rank : '–') + '</span>'
            + '<span class="duel-comm-title">' + escHtml(title) + '</span>'
            + '</div>';
        commSec.innerHTML = '<div class="duel-stat-lbl">Community Ranking' + RANK_SFX + '</div>'
            + filmRow(commRanks.a_rank, commRanks.a_title)
            + filmRow(commRanks.b_rank, commRanks.b_title);
    }

    // ── Meine Rangliste: Positionen der aktuellen Duell-Filme ───────────────
    const myRankSec = document.getElementById('my-rank-stat');
    if (myRankSec && commRanks) {
        const myStyle = 'background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.15); color:rgba(255,255,255,.7);';
        const myRow = (pos, title) =>
            '<div class="duel-comm-film">'
            + '<span class="duel-comm-rank" style="' + myStyle + '">' + (pos !== null && pos !== undefined ? '#' + pos : '–') + '</span>'
            + '<span class="duel-comm-title">' + escHtml(title) + '</span>'
            + '</div>';
        myRankSec.innerHTML = '<div class="duel-stat-lbl">Meine Rangliste' + RANK_SFX + '</div>'
            + myRow(commRanks.a_my_pos, commRanks.a_title)
            + myRow(commRanks.b_my_pos, commRanks.b_title);
    }
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function updateHdrCounters(totalDuels, uniqueFilms) {
    const dc = document.getElementById('hdr-duels-count');
    const fc = document.getElementById('hdr-films-count');
    if (dc && totalDuels !== undefined) dc.textContent = totalDuels.toLocaleString('de-DE');
    if (fc && uniqueFilms !== undefined) fc.textContent = uniqueFilms;
}

// ── Undo ─────────────────────────────────────────────────────────────────────
function castUndo() {
    if (voting) return;
    voting = true;
    const btn = document.getElementById('undo-btn');
    if (btn) btn.disabled = true;

    const fd = new FormData();
    fd.append('action',    'undo');
    fd.append('csrf_token', CSRF);

    fetch('/jgj.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { voting = false; if (btn) btn.disabled = false; return; }
            // Progress
            const pct = data.total > 0 ? Math.round(data.done / data.total * 100 * 10) / 10 : 0;
            const fill = document.getElementById('progress-fill');
            const txt  = document.getElementById('progress-text');
            if (fill) fill.style.width = pct + '%';
            if (txt)  txt.textContent = data.done.toLocaleString('de') + ' / ' + data.total.toLocaleString('de') + ' Duelle (' + pct + '%)';
            updateHdrCounters(data.hdrDuels, data.hdrFilms);
            // Show undone duel
            if (data.undo_duel) {
                const d = data.undo_duel;
                setCard('movie-a', d.a_id, d.a_title, d.a_year, d.a_display_poster || d.a_poster, d.b_id, d.a_overview);
                setCard('movie-b', d.b_id, d.b_title, d.b_year, d.b_display_poster || d.b_poster, d.a_id, d.b_overview);
            }
            if (btn) btn.disabled = (data.done === 0);
            voting = false;
        })
        .catch(() => { voting = false; if (btn) btn.disabled = false; });
}

// ── Add films (Duel-Screen) ───────────────────────────────────────────────────
function addFilms(count, btn) {
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action',    'add_films');
    fd.append('csrf_token', CSRF);
    fd.append('count',     count);

    fetch('/jgj.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            if (data.ok) { location.reload(); }
            else { btn.disabled = false; alert('Keine weiteren Filme verfügbar.'); }
        })
        .catch(() => { btn.disabled = false; });
}

// ── Add films (Abschluss-Screen) ──────────────────────────────────────────────
function completionAddFilms(count, btn) {
    const btns = document.querySelectorAll('#completion-add-btns button');
    btns.forEach(b => b.disabled = true);

    const fd = new FormData();
    fd.append('action',     'add_films');
    fd.append('csrf_token', CSRF);
    fd.append('count',      count);

    fetch('/jgj.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const msg = document.getElementById('completion-add-msg');
                msg.querySelector('span').textContent =
                    data.added + ' Filme hinzugefügt – insgesamt ' + data.pool + ' im Pool.';
                msg.style.display = '';
                // Buttons mit verbleibender Verfügbarkeit neu rendern
                const avail = data.pool ? (<?= $moreAvail ?> - data.added) : 0;
                btns.forEach(b => {
                    const n = parseInt(b.textContent.replace('+',''));
                    b.disabled = n > avail;
                });
                // Nach kurzer Verzögerung weiterleiten damit JgJ-Duelle starten können
                setTimeout(() => { location.href = '/jgj.php'; }, 1800);
            } else {
                btns.forEach(b => b.disabled = false);
                alert('Keine weiteren Filme verfügbar.');
            }
        })
        .catch(() => { btns.forEach(b => b.disabled = false); });
}

// syncH removed – sidebar height controlled by CSS (position:sticky + max-height:calc(100vh-...))

// ── Initial scroll to left (better-ranked) film ───────────────────────────────
(function () {
    const a = document.getElementById('movie-a');
    if (a) setTimeout(() => scrollRankingToFilm(parseInt(a.dataset.id)), 100);
}());
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
