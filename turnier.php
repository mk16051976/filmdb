<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Log außerhalb des Webroots – nicht über HTTP abrufbar
define('TURNIER_LOG', dirname(__DIR__) . '/logs/turnier_error.log');
@mkdir(dirname(TURNIER_LOG), 0755, true);
ini_set('error_log', TURNIER_LOG);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    @file_put_contents(TURNIER_LOG, date('Y-m-d H:i:s') . " [$errno] $errstr in $errfile:$errline\n", FILE_APPEND);
});
set_exception_handler(function($e) {
    @file_put_contents(TURNIER_LOG, date('Y-m-d H:i:s') . " EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n", FILE_APPEND);
});
set_time_limit(0);
$pageTitle = 'Sichtungsturnier – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── DB-Schema ─────────────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS user_tournaments (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    film_count    MEDIUMINT UNSIGNED NOT NULL,
    total_rounds  TINYINT UNSIGNED NOT NULL,
    current_round TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status        ENUM('active','completed') NOT NULL DEFAULT 'active',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS tournament_films (
    tournament_id INT UNSIGNED NOT NULL,
    movie_id      INT UNSIGNED NOT NULL,
    seed          MEDIUMINT UNSIGNED NOT NULL,
    points        MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (tournament_id, movie_id),
    INDEX idx_seed (tournament_id, seed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS tournament_matches (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT UNSIGNED NOT NULL,
    runde         TINYINT UNSIGNED NOT NULL,
    match_number  MEDIUMINT UNSIGNED NOT NULL,
    movie_a_id    INT UNSIGNED NOT NULL,
    movie_b_id    INT UNSIGNED NOT NULL,
    winner_id     INT UNSIGNED NULL,
    INDEX idx_pending (tournament_id, runde, winner_id),
    UNIQUE KEY uq_match (tournament_id, runde, match_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

// Migration: bye column for first-round byes
$db->exec("ALTER TABLE tournament_films ADD COLUMN IF NOT EXISTS bye TINYINT(1) NOT NULL DEFAULT 0");
// Migration: separate movie vs. series tournaments
$db->exec("ALTER TABLE user_tournaments ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NOT NULL DEFAULT 'movie'");

// Migration: SMALLINT → MEDIUMINT für Turniere mit >65k Filmen
try {
    $db->exec("ALTER TABLE user_tournaments    MODIFY film_count    MEDIUMINT UNSIGNED NOT NULL");
    $db->exec("ALTER TABLE tournament_films    MODIFY seed          MEDIUMINT UNSIGNED NOT NULL");
    $db->exec("ALTER TABLE tournament_films    MODIFY points        MEDIUMINT UNSIGNED NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE tournament_matches  MODIFY match_number  MEDIUMINT UNSIGNED NOT NULL");
} catch (PDOException $e) { /* Bereits migriert oder Tabelle existiert noch nicht */ }

// Migration: falls Tabelle mit falschem Schema existiert (z. B. ohne 'runde'), neu anlegen
try {
    $db->query("SELECT runde FROM tournament_matches LIMIT 0");
} catch (PDOException $e) {
    $db->exec("DROP TABLE IF EXISTS tournament_matches");
    $db->exec("CREATE TABLE tournament_matches (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tournament_id INT UNSIGNED NOT NULL,
        runde         TINYINT UNSIGNED NOT NULL,
        match_number  MEDIUMINT UNSIGNED NOT NULL,
        movie_a_id    INT UNSIGNED NOT NULL,
        movie_b_id    INT UNSIGNED NOT NULL,
        winner_id     INT UNSIGNED NULL,
        INDEX idx_pending (tournament_id, runde, winner_id),
        UNIQUE KEY uq_match (tournament_id, runde, match_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── Helpers ───────────────────────────────────────────────────────────────────
// Rounds DOWN to nearest power of 2 (used for $usable display only)
function nearestPow2(int $n): int {
    if ($n < 2) return 2;
    $p = 1;
    while ($p * 2 <= $n) $p *= 2;
    return $p;
}

// Rounds UP to next power of 2 (bracket size when byes are allowed)
function nextPow2(int $n): int {
    if ($n <= 1) return 2;
    $p = 1;
    while ($p < $n) $p *= 2;
    return $p;
}

function getTournament(PDO $db, int $userId): ?array {
    $mt = activeMtForDb();
    $stmt = $db->prepare(
        "SELECT id, user_id, film_count, total_rounds, current_round, status, created_at, media_type FROM user_tournaments WHERE user_id = ? AND media_type = ? AND status IN ('active','completed') ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$userId, $mt]);
    return $stmt->fetch() ?: null;
}

function getNextMatch(PDO $db, array $t): ?array {
    $stmt = $db->prepare("
        SELECT tm.id, tm.match_number,
               ma.id AS a_id, ma.title AS a_title, ma.title_en AS a_title_en, ma.year AS a_year, ma.poster_path AS a_poster, ma.poster_path_en AS a_poster_en, ma.imdb_id AS a_imdb, COALESCE(NULLIF(ma.wikipedia,''), ma.overview) AS a_overview,
               mb.id AS b_id, mb.title AS b_title, mb.title_en AS b_title_en, mb.year AS b_year, mb.poster_path AS b_poster, mb.poster_path_en AS b_poster_en, mb.imdb_id AS b_imdb, COALESCE(NULLIF(mb.wikipedia,''), mb.overview) AS b_overview,
               COALESCE(pa.position, 999999) AS pos_a,
               COALESCE(pb.position, 999999) AS pos_b
        FROM tournament_matches tm
        JOIN movies ma ON ma.id = tm.movie_a_id
        JOIN movies mb ON mb.id = tm.movie_b_id
        LEFT JOIN user_position_ranking pa ON pa.user_id = ? AND pa.movie_id = tm.movie_a_id
        LEFT JOIN user_position_ranking pb ON pb.user_id = ? AND pb.movie_id = tm.movie_b_id
        WHERE tm.tournament_id = ? AND tm.runde = ? AND tm.winner_id IS NULL
              AND ma.id IS NOT NULL AND mb.id IS NOT NULL
        ORDER BY tm.match_number ASC LIMIT 1
    ");
    $uid = $t['user_id'];
    $stmt->execute([$uid, $uid, $t['id'], $t['current_round']]);
    $r = $stmt->fetch();
    if (!$r) return null;

    $filmA = ['id' => (int)$r['a_id'], 'title' => $r['a_title'], 'title_en' => $r['a_title_en'] ?? null, 'year' => (int)$r['a_year'], 'poster_path' => $r['a_poster'], 'poster_path_en' => $r['a_poster_en'] ?? null, 'imdb_id' => $r['a_imdb'] ?? null, 'overview' => $r['a_overview'] ?? null];
    $filmB = ['id' => (int)$r['b_id'], 'title' => $r['b_title'], 'title_en' => $r['b_title_en'] ?? null, 'year' => (int)$r['b_year'], 'poster_path' => $r['b_poster'], 'poster_path_en' => $r['b_poster_en'] ?? null, 'imdb_id' => $r['b_imdb'] ?? null, 'overview' => $r['b_overview'] ?? null];

    // Besser platzierter Film (niedrigere Position = besser) kommt links
    if ((int)$r['pos_b'] < (int)$r['pos_a']) {
        [$filmA, $filmB] = [$filmB, $filmA];
    }

    $filmA['display_poster'] = moviePosterUrl($filmA);
    $filmB['display_poster'] = moviePosterUrl($filmB);

    return [
        'id'           => (int)$r['id'],
        'match_number' => (int)$r['match_number'],
        'a' => $filmA,
        'b' => $filmB,
    ];
}

function getRoundStats(PDO $db, int $tId, int $round): array {
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total, SUM(winner_id IS NOT NULL) AS played
         FROM tournament_matches WHERE tournament_id = ? AND runde = ?"
    );
    $stmt->execute([$tId, $round]);
    return $stmt->fetch();
}

function getTotalPlayed(PDO $db, int $tId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ? AND winner_id IS NOT NULL");
    $stmt->execute([$tId]);
    return (int)$stmt->fetchColumn();
}

function advanceRound(PDO $db, array &$t): void {
    $round = (int)$t['current_round'];

    // Collect match winners for this round (ordered by match_number → bracket order)
    $stmt = $db->prepare(
        "SELECT winner_id FROM tournament_matches
         WHERE tournament_id = ? AND runde = ? ORDER BY match_number ASC"
    );
    $stmt->execute([$t['id'], $round]);
    $winners = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Round 1 → 2: prepend bye films (top seeds, ordered by seed ASC) to winners
    if ($round === 1) {
        $byeStmt = $db->prepare(
            "SELECT movie_id FROM tournament_films
             WHERE tournament_id = ? AND bye = 1 ORDER BY seed ASC"
        );
        $byeStmt->execute([$t['id']]);
        $byeFilms = $byeStmt->fetchAll(PDO::FETCH_COLUMN);
        // Byes occupy the top bracket positions; match winners fill the bottom positions
        $winners = array_merge($byeFilms, $winners);
    }

    $cnt = count($winners);

    if ($cnt <= 1) {
        $db->prepare("UPDATE user_tournaments SET status = 'completed' WHERE id = ?")
           ->execute([$t['id']]);
        $t['status'] = 'completed';
        saveTournamentResults($db, $t);
        return;
    }

    $nextRound = $round + 1;
    // Bracket-Paarungen aufbauen (Seed 1 vs Seed N, Seed 2 vs Seed N-1, …)
    $matchRows = [];
    for ($i = 0; $i < intdiv($cnt, 2); $i++) {
        $matchRows[] = [$t['id'], $nextRound, 0, $winners[$i], $winners[$cnt - 1 - $i]];
    }
    // Spielreihenfolge mischen — verhindert, dass der Sieger des letzten Duells
    // direkt als erstes wieder auftritt (Paarungen bleiben unverändert).
    shuffle($matchRows);
    foreach ($matchRows as $k => &$row) { $row[2] = $k + 1; }
    unset($row);

    foreach (array_chunk($matchRows, 500) as $chunk) {
        $ph  = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
        $flat = array_merge(...$chunk);
        $db->prepare("INSERT INTO tournament_matches (tournament_id, runde, match_number, movie_a_id, movie_b_id) VALUES $ph")
           ->execute($flat);
    }
    $db->prepare("UPDATE user_tournaments SET current_round = ? WHERE id = ?")
       ->execute([$nextRound, $t['id']]);
    $t['current_round'] = $nextRound;
}

function getTournamentRanking(PDO $db, int $tId, int $limit = 50): array {
    $stmt = $db->prepare("
        SELECT tf.points, tf.seed, m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en
        FROM tournament_films tf
        JOIN movies m ON m.id = tf.movie_id
        WHERE tf.tournament_id = ?
        ORDER BY tf.points DESC, tf.seed ASC
        LIMIT ?
    ");
    $stmt->execute([$tId, $limit]);
    return $stmt->fetchAll();
}

function getPosRanking(PDO $db, int $userId, int $limit = 100): array {
    try {
        $stmt = $db->prepare("
            SELECT upr.position, m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en
            FROM user_position_ranking upr
            JOIN movies m ON m.id = upr.movie_id
            WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
            ORDER BY upr.position ASC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $i => &$r) { $r['position'] = $i + 1; }
        unset($r);
        return $rows;
    } catch (\PDOException $e) {
        return [];
    }
}

function saveTournamentResults(PDO $db, array $t): void {
    // Remove any previous snapshot for this tournament (idempotent)
    $db->prepare("DELETE FROM tournament_results WHERE tournament_id = ?")->execute([$t['id']]);

    // wins = points already tracked in tournament_films
    // matches_played = actual matches played (bye-rounds are not real matches)
    $stmt = $db->prepare("
        SELECT
            tf.movie_id,
            tf.points AS wins,
            COUNT(tm2.id) AS matches_played
        FROM tournament_films tf
        LEFT JOIN tournament_matches tm2
            ON  tm2.tournament_id = tf.tournament_id
            AND (tm2.movie_a_id = tf.movie_id OR tm2.movie_b_id = tf.movie_id)
            AND tm2.winner_id IS NOT NULL
        WHERE tf.tournament_id = ?
        GROUP BY tf.movie_id, tf.points
    ");
    $stmt->execute([$t['id']]);
    $films = $stmt->fetchAll();

    if (empty($films)) return;

    $ins = $db->prepare("
        INSERT INTO tournament_results (tournament_id, user_id, movie_id, wins, matches_played, score)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($films as $film) {
        $wins    = (int)$film['wins'];
        $played  = (int)$film['matches_played'];
        $score   = $played > 0 ? round($wins / $played, 4) : 0.0;
        $ins->execute([$t['id'], (int)$t['user_id'], (int)$film['movie_id'], $wins, $played, $score]);
    }
}

// ── Action: Suggest (autocomplete) ────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    $allowedFields = ['genre', 'country', 'director', 'actors'];
    $field = $_GET['field'] ?? '';
    $q     = trim($_GET['q'] ?? '');
    if (!in_array($field, $allowedFields, true) || strlen($q) < 1) { echo '[]'; exit; }
    $col = $field; // genre, country, director, actors — all direct column names
    // For comma-separated fields split and collect distinct values containing $q
    $stmt = $db->prepare("SELECT DISTINCT {$col} FROM movies WHERE {$col} LIKE ? AND {$col} != '' LIMIT 200");
    $stmt->execute(['%' . $q . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $seen = []; $results = [];
    foreach ($rows as $row) {
        foreach (array_map('trim', explode(',', $row)) as $val) {
            if ($val === '') continue;
            $lv = mb_strtolower($val);
            if (!isset($seen[$lv]) && mb_stripos($val, $q) !== false) {
                $seen[$lv] = true;
                $results[] = $val;
                if (count($results) >= 10) break 2;
            }
        }
    }
    sort($results);
    echo json_encode(array_values($results));
    exit;
}

// ── Action: Start ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start' && csrfValid()) {
    $old = getTournament($db, $userId);
    if ($old && $old['status'] === 'active') {
        // Nur laufende Turniere löschen – abgeschlossene bleiben für die Phasen-Prüfung erhalten
        $db->prepare("DELETE FROM tournament_matches WHERE tournament_id = ?")->execute([$old['id']]);
        $db->prepare("DELETE FROM tournament_films   WHERE tournament_id = ?")->execute([$old['id']]);
        $db->prepare("DELETE FROM user_tournaments   WHERE id = ?")->execute([$old['id']]);
    }

    $validPows   = [2,4,8,16,32,64,128,256,512,1024,2048,4096,8192,16384,32768,65536,131072,262144];
    // Pool nur nutzen wenn er nach Benutzer-Filtern genug Filme enthält
    $poolAvail   = (int)$db->query(
        "SELECT COUNT(*) FROM tournament_pool tp JOIN movies m ON m.id=tp.movie_id WHERE 1=1"
        . seriesSqlFilter('m') . moviesSqlFilter('m') . hiddenFilmsSqlFilter('m')
    )->fetchColumn();
    $totalMovies = (int)$db->query(
        "SELECT COUNT(*) FROM movies WHERE 1=1"
        . seriesSqlFilter('movies') . moviesSqlFilter('movies') . hiddenFilmsSqlFilter('movies')
    )->fetchColumn();
    $maxPow      = nextPow2($totalMovies);
    // Phase II: immer 1024, keine freie Wahl; Phase III+: aus POST
    $reqCount    = (userPhase() === 2) ? 1024 : (int)($_POST['film_count'] ?? $maxPow);
    if (!in_array($reqCount, $validPows, true) || $reqCount > $maxPow) $reqCount = $maxPow;
    // Parse optional filters
    $fGenre    = trim($_POST['filter_genre']    ?? '');
    $fCountry  = trim($_POST['filter_country']  ?? '');
    $fDirector = trim($_POST['filter_director'] ?? '');
    $fActor    = trim($_POST['filter_actor']    ?? '');
    $hasFilter = $fGenre !== '' || $fCountry !== '' || $fDirector !== '' || $fActor !== '';

    if ($hasFilter) {
        // Filter: gesamte DB durchsuchen.
        // Persönlich gerankte Filme des Users zuerst (nach Position), dann ungerankte — alphabetisch.
        $filterWhere  = ' WHERE 1=1';
        $filterValues = [];
        if ($fGenre    !== '') { $filterWhere .= ' AND m.genre    LIKE ?'; $filterValues[] = '%' . $fGenre    . '%'; }
        if ($fCountry  !== '') { $filterWhere .= ' AND m.country  LIKE ?'; $filterValues[] = '%' . $fCountry  . '%'; }
        if ($fDirector !== '') { $filterWhere .= ' AND m.director LIKE ?'; $filterValues[] = '%' . $fDirector . '%'; }
        if ($fActor    !== '') { $filterWhere .= ' AND m.actors   LIKE ?'; $filterValues[] = '%' . $fActor    . '%'; }
        $filterWhere .= seriesSqlFilter('m') . moviesSqlFilter('m') . hiddenFilmsSqlFilter('m');

        // Param order: userId (JOIN), filter values, reqCount (LIMIT)
        $params = array_merge([$userId], $filterValues, [$reqCount]);
        $selStmt = $db->prepare("
            SELECT m.id FROM movies m
            LEFT JOIN user_position_ranking upr ON upr.movie_id = m.id AND upr.user_id = ?
            $filterWhere
            ORDER BY CASE WHEN upr.position IS NOT NULL THEN 0 ELSE 1 END ASC,
                     upr.position ASC, m.title ASC
            LIMIT ?");
        $selStmt->execute($params);
        $films = $selStmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // Pool für ≤ 4096 verwenden (wenn genug Filme vorhanden); größere Turniere aus DB
        $usePool = $poolAvail >= $reqCount && $reqCount <= 4096 && $poolAvail >= 2;
        if ($usePool) {
            $selStmt = $db->prepare("SELECT tp.movie_id AS id FROM tournament_pool tp JOIN movies m ON m.id=tp.movie_id WHERE 1=1" . seriesSqlFilter('m') . moviesSqlFilter('m') . hiddenFilmsSqlFilter('m') . " ORDER BY tp.sort_order ASC LIMIT ?");
            $selStmt->execute([$reqCount]);
        } else {
            // Sortierung nach persönlicher Rangliste (besser platzierte Filme bekommen niedrigeren Seed → kommen links)
            $selStmt = $db->prepare(
                "SELECT m.id FROM movies m
                 LEFT JOIN user_position_ranking upr ON upr.movie_id = m.id AND upr.user_id = ?
                 WHERE 1=1" . seriesSqlFilter('m') . moviesSqlFilter('m') . hiddenFilmsSqlFilter('m') .
                " ORDER BY CASE WHEN upr.position IS NOT NULL THEN 0 ELSE 1 END ASC,
                            upr.position ASC, m.id ASC
                 LIMIT ?"
            );
            $selStmt->execute([$userId, $reqCount]);
        }
        $films = $selStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    if (($_POST['mode'] ?? '') === 'random') shuffle($films);
    $n      = count($films);
    if ($n < 2) {
        header('Location: /turnier.php?new=1&filter_error=no_films');
        exit;
    }
    $n      = count($films);                    // actual film count
    $b      = nextPow2($n);                     // bracket size (next power of 2 >= n)
    $byes   = $b - $n;                          // top seeds that get a round-1 bye
    $rounds = (int)log($b, 2);                  // total rounds

    $db->prepare("INSERT INTO user_tournaments (user_id, film_count, total_rounds, media_type) VALUES (?, ?, ?, ?)")
       ->execute([$userId, $n, $rounds, activeMtForDb()]);
    $tId = (int)$db->lastInsertId();

    // Films that actually play in round 1 (lower seeds, i.e., the last $n - $byes films)
    $playingFilms = array_slice($films, $byes);
    $playCount    = count($playingFilms);

    // Round-1 match pairs: seed($byes+1) vs seed($n), seed($byes+2) vs seed($n-1), …
    // Spielreihenfolge mischen damit die Paarungen abwechslungsreich auftreten.
    $matchPairsRaw = [];
    for ($i = 0; $i < intdiv($playCount, 2); $i++) {
        $matchPairsRaw[] = [$playingFilms[$i], $playingFilms[$playCount - 1 - $i]];
    }
    shuffle($matchPairsRaw);
    $matchPairs = [];
    foreach ($matchPairsRaw as $k => [$aId, $bId]) {
        $matchPairs[] = [$k + 1, $aId, $bId];
    }

    $db->beginTransaction();

    // Bulk-insert all films with seed and bye flag (chunks of 500)
    $seed = 1;
    foreach (array_chunk($films, 500) as $chunk) {
        $ph   = implode(',', array_fill(0, count($chunk), '(?,?,?,?)'));
        $vals = [];
        foreach ($chunk as $movieId) {
            $isBye = ($seed <= $byes) ? 1 : 0;
            $vals[] = $tId;
            $vals[] = (int)$movieId;
            $vals[] = $seed++;
            $vals[] = $isBye;
        }
        $db->prepare("INSERT INTO tournament_films (tournament_id, movie_id, seed, bye) VALUES $ph")->execute($vals);
    }

    // Bulk-insert round-1 matches (only actual matches, not byes) in chunks of 500
    foreach (array_chunk($matchPairs, 500) as $chunk) {
        $ph   = implode(',', array_fill(0, count($chunk), '(?,1,?,?,?)'));
        $vals = [];
        foreach ($chunk as [$matchNum, $aId, $bId]) {
            $vals[] = $tId;
            $vals[] = $matchNum;
            $vals[] = (int)$aId;
            $vals[] = (int)$bId;
        }
        $db->prepare(
            "INSERT INTO tournament_matches (tournament_id, runde, match_number, movie_a_id, movie_b_id) VALUES $ph"
        )->execute($vals);
    }

    $db->commit();

    header('Location: /turnier.php');
    exit;
}

// ── Action: Reset ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset' && csrfValid()) {
    $t = getTournament($db, $userId);
    if ($t && $t['status'] === 'active') {
        $db->prepare("DELETE FROM tournament_matches WHERE tournament_id = ?")->execute([$t['id']]);
        $db->prepare("DELETE FROM tournament_films   WHERE tournament_id = ?")->execute([$t['id']]);
        $db->prepare("DELETE FROM user_tournaments   WHERE id = ?")->execute([$t['id']]);
    }
    header('Location: /turnier.php');
    exit;
}

// ── Action: Vote (AJAX) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote' && csrfValid()) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $t = getTournament($db, $userId);
    if (!$t || $t['status'] !== 'active') { echo json_encode(['error' => 'no_active']); exit; }

    $matchId  = (int)($_POST['match_id']  ?? 0);
    $winnerId = (int)($_POST['winner_id'] ?? 0);
    $loserId  = (int)($_POST['loser_id']  ?? 0);

    $chk = $db->prepare(
        "SELECT id FROM tournament_matches WHERE id = ? AND tournament_id = ? AND winner_id IS NULL"
    );
    $chk->execute([$matchId, $t['id']]);
    if (!$chk->fetch()) { echo json_encode(['error' => 'invalid_match']); exit; }

    // Turnierergebnis atomar speichern
    $db->beginTransaction();
    $db->prepare("UPDATE tournament_matches SET winner_id = ? WHERE id = ?")->execute([$winnerId, $matchId]);
    $db->prepare("UPDATE tournament_films SET points = points + 1 WHERE tournament_id = ? AND movie_id = ?")
       ->execute([$t['id'], $winnerId]);
    $db->commit();
    // Alte Positionen VOR recordComparison sichern (für Sidebar-Statistik)
    $duelResult = buildDuelResult($db, $userId, $winnerId, $loserId);
    // ELO + Position-Ranking außerhalb der Transaktion
    recordComparison($userId, $winnerId, $loserId);
    $winnerContext = buildWinnerContext($db, $userId, $winnerId, getActiveMtFilter());

    // Runde abgeschlossen?
    $prevRound = (int)$t['current_round'];
    $rs = getRoundStats($db, $t['id'], $t['current_round']);
    $roundJustFinished = ((int)$rs['total'] === (int)$rs['played']);
    $lastRoundTotal    = (int)$rs['total'];
    if ($roundJustFinished) {
        advanceRound($db, $t);
    }

    $done      = $t['status'] === 'completed';
    $nextMatch = $done ? null : getNextMatch($db, $t);
    $ranking   = getTournamentRanking($db, $t['id']);
    $roundStats = $done ? ['total' => 0, 'played' => 0]
                       : getRoundStats($db, $t['id'], $t['current_round']);

    // Zwischen-Runden-Daten wenn Runde soeben abgeschlossen wurde
    $roundSummary = null;
    if ($roundJustFinished && !$done) {
        // Duelle der soeben abgeschlossenen Runde, sortiert nach niedrigster Film-ID
        $lowestStmt = $db->prepare(
            "SELECT
                ma.id AS a_id, ma.title AS a_title, ma.title_en AS a_title_en, ma.year AS a_year, ma.poster_path AS a_poster, ma.poster_path_en AS a_poster_en,
                mb.id AS b_id, mb.title AS b_title, mb.title_en AS b_title_en, mb.year AS b_year, mb.poster_path AS b_poster, mb.poster_path_en AS b_poster_en,
                tm.winner_id,
                LEAST(ma.id, mb.id) AS min_id
             FROM tournament_matches tm
             JOIN movies ma ON ma.id = tm.movie_a_id
             JOIN movies mb ON mb.id = tm.movie_b_id
             WHERE tm.tournament_id = ?
               AND tm.runde = ?
               AND tm.winner_id IS NOT NULL
             ORDER BY min_id ASC
             LIMIT 10"
        );
        $lowestStmt->execute([$t['id'], $prevRound]);
        $lowestFilms = array_map(fn($row) => [
            'a'         => ['id' => (int)$row['a_id'], 'title' => movieTitle(['title' => $row['a_title'], 'title_en' => $row['a_title_en'] ?? null]), 'year' => (int)$row['a_year'], 'poster_path' => $row['a_poster'], 'display_poster' => moviePosterUrl(['poster_path' => $row['a_poster'], 'poster_path_en' => $row['a_poster_en'] ?? null], 'w92')],
            'b'         => ['id' => (int)$row['b_id'], 'title' => movieTitle(['title' => $row['b_title'], 'title_en' => $row['b_title_en'] ?? null]), 'year' => (int)$row['b_year'], 'poster_path' => $row['b_poster'], 'display_poster' => moviePosterUrl(['poster_path' => $row['b_poster'], 'poster_path_en' => $row['b_poster_en'] ?? null], 'w92')],
            'winner_id' => (int)$row['winner_id'],
        ], $lowestStmt->fetchAll());

        $roundSummary = [
            'prevRound'      => $prevRound,
            'lastRoundDuels' => $lastRoundTotal,
            'totalDuels'     => getTotalPlayed($db, $t['id']),
            'nextRoundDuels' => (int)$roundStats['total'],
            'lowestFilms'    => $lowestFilms,
        ];
    }

    $rankOut = [];
    foreach ($ranking as $i => $r) {
        $rankOut[] = [
            'rank'           => $i + 1,
            'id'             => (int)$r['id'],
            'title'          => movieTitle($r),
            'year'           => (int)$r['year'],
            'poster_path'    => $r['poster_path'],
            'poster_path_en' => $r['poster_path_en'] ?? null,
            'display_poster' => moviePosterUrl($r, 'w92'),
            'points'         => (int)$r['points'],
        ];
    }

    $posRankOut = [];
    foreach (getPosRanking($db, $userId) as $r) {
        $posRankOut[] = ['pos' => (int)$r['position'], 'id' => (int)$r['id'], 'title' => movieTitle($r), 'poster_path' => $r['poster_path'], 'display_poster' => moviePosterUrl($r, 'w92')];
    }

    // Community- und eigene Ränge für das nächste Duell
    $commRanks = null;
    if ($nextMatch) {
        $commRanks = buildCommRanks(
            $db,
            (int)$nextMatch['a']['id'], movieTitle($nextMatch['a']),
            (int)$nextMatch['b']['id'], movieTitle($nextMatch['b']),
            $userId, getActiveMtFilter()
        );
    }

    $counters = getActivityCounters($userId);
    echo json_encode([
        'done'           => $done,
        'round'          => (int)$t['current_round'],
        'totalRounds'    => (int)$t['total_rounds'],
        'played'         => (int)$roundStats['played'],
        'total'          => (int)$roundStats['total'],
        'nextMatch'      => $nextMatch,
        'ranking'        => $rankOut,
        'posRanking'     => $posRankOut,
        'roundSummary'   => $roundSummary,
        'duel_result'    => $duelResult,
        'winner_context' => $winnerContext,
        'comm_ranks'     => $commRanks,
        'hdrDuels'       => $counters['totalDuels'],
        'hdrFilms'       => $counters['uniqueFilms'],
    ]);
    exit;
}

// ── Action: Undo (AJAX) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'undo' && csrfValid()) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $t = getTournament($db, $userId);
    if (!$t) { echo json_encode(['error' => 'no_tournament']); exit; }

    // Find the last voted match across all rounds (regardless of completed state)
    $stmt = $db->prepare("
        SELECT tm.id, tm.runde, tm.match_number, tm.winner_id,
               CASE WHEN tm.winner_id = tm.movie_a_id THEN tm.movie_b_id ELSE tm.movie_a_id END AS loser_id,
               ma.id AS a_id, ma.title AS a_title, ma.title_en AS a_title_en, ma.year AS a_year, ma.poster_path AS a_poster, ma.poster_path_en AS a_poster_en, ma.imdb_id AS a_imdb, COALESCE(NULLIF(ma.wikipedia,''), ma.overview) AS a_overview,
               mb.id AS b_id, mb.title AS b_title, mb.title_en AS b_title_en, mb.year AS b_year, mb.poster_path AS b_poster, mb.poster_path_en AS b_poster_en, mb.imdb_id AS b_imdb, COALESCE(NULLIF(mb.wikipedia,''), mb.overview) AS b_overview
        FROM tournament_matches tm
        JOIN movies ma ON ma.id = tm.movie_a_id
        JOIN movies mb ON mb.id = tm.movie_b_id
        WHERE tm.tournament_id = ? AND tm.winner_id IS NOT NULL
        ORDER BY tm.id DESC LIMIT 1
    ");
    $stmt->execute([$t['id']]);
    $lastMatch = $stmt->fetch();

    if (!$lastMatch) { echo json_encode(['error' => 'nothing_to_undo']); exit; }

    $winnerId   = (int)$lastMatch['winner_id'];
    $loserId    = (int)$lastMatch['loser_id'];
    $matchRunde = (int)$lastMatch['runde'];

    $db->beginTransaction();

    // Revert completed status back to active
    if ($t['status'] === 'completed') {
        $db->prepare("UPDATE user_tournaments SET status = 'active' WHERE id = ?")
           ->execute([$t['id']]);
        $db->prepare("DELETE FROM tournament_results WHERE tournament_id = ?")
           ->execute([$t['id']]);
    }

    // If the vote triggered a round advance, undo it (delete next round's matches)
    if ((int)$t['current_round'] > $matchRunde) {
        $db->prepare("DELETE FROM tournament_matches WHERE tournament_id = ? AND runde = ?")
           ->execute([$t['id'], (int)$t['current_round']]);
        $db->prepare("UPDATE user_tournaments SET current_round = ? WHERE id = ?")
           ->execute([$matchRunde, $t['id']]);
    }

    // Undo tournament point
    $db->prepare("UPDATE tournament_films SET points = GREATEST(0, points - 1) WHERE tournament_id = ? AND movie_id = ?")
       ->execute([$t['id'], $winnerId]);

    // Undo ELO / comparison record
    undoLastComparison($userId, $winnerId, $loserId);

    // Clear winner from the match
    $db->prepare("UPDATE tournament_matches SET winner_id = NULL WHERE id = ?")
       ->execute([(int)$lastMatch['id']]);

    $db->commit();

    // Reload fresh state
    $t           = getTournament($db, $userId);
    $ranking     = getTournamentRanking($db, $t['id']);
    $roundStats  = getRoundStats($db, $t['id'], $t['current_round']);
    $pStmt = $db->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ? AND winner_id IS NOT NULL");
    $pStmt->execute([$t['id']]);
    $playedTotal = (int)$pStmt->fetchColumn();

    $rankOut = [];
    foreach ($ranking as $i => $r) {
        $rankOut[] = [
            'rank'           => $i + 1,
            'id'             => (int)$r['id'],
            'title'          => movieTitle($r),
            'year'           => (int)$r['year'],
            'poster_path'    => $r['poster_path'],
            'poster_path_en' => $r['poster_path_en'] ?? null,
            'display_poster' => moviePosterUrl($r, 'w92'),
            'points'         => (int)$r['points'],
        ];
    }

    $posRankOut = [];
    foreach (getPosRanking($db, $userId) as $r) {
        $posRankOut[] = ['pos' => (int)$r['position'], 'id' => (int)$r['id'], 'title' => movieTitle($r), 'poster_path' => $r['poster_path'], 'display_poster' => moviePosterUrl($r, 'w92')];
    }

    echo json_encode([
        'ok'           => true,
        'round'        => (int)$t['current_round'],
        'totalRounds'  => (int)$t['total_rounds'],
        'played'       => (int)$roundStats['played'],
        'total'        => (int)$roundStats['total'],
        'playedTotal'  => $playedTotal,
        'totalMatches' => (int)$t['film_count'] - 1,
        'canUndo'      => $playedTotal > 0,
        'match'        => [
            'id'           => (int)$lastMatch['id'],
            'match_number' => (int)$lastMatch['match_number'],
            'a' => ['id' => (int)$lastMatch['a_id'], 'title' => movieTitle(['title' => $lastMatch['a_title'], 'title_en' => $lastMatch['a_title_en'] ?? null]), 'year' => (int)$lastMatch['a_year'], 'poster_path' => $lastMatch['a_poster'], 'poster_path_en' => $lastMatch['a_poster_en'] ?? null, 'imdb_id' => $lastMatch['a_imdb'] ?? null, 'display_poster' => moviePosterUrl(['poster_path' => $lastMatch['a_poster'], 'poster_path_en' => $lastMatch['a_poster_en'] ?? null, 'imdb_id' => $lastMatch['a_imdb'] ?? null]), 'overview' => $lastMatch['a_overview'] ?? null],
            'b' => ['id' => (int)$lastMatch['b_id'], 'title' => movieTitle(['title' => $lastMatch['b_title'], 'title_en' => $lastMatch['b_title_en'] ?? null]), 'year' => (int)$lastMatch['b_year'], 'poster_path' => $lastMatch['b_poster'], 'poster_path_en' => $lastMatch['b_poster_en'] ?? null, 'imdb_id' => $lastMatch['b_imdb'] ?? null, 'display_poster' => moviePosterUrl(['poster_path' => $lastMatch['b_poster'], 'poster_path_en' => $lastMatch['b_poster_en'] ?? null, 'imdb_id' => $lastMatch['b_imdb'] ?? null]), 'overview' => $lastMatch['b_overview'] ?? null],
        ],
        'ranking'      => $rankOut,
        'posRanking'   => $posRankOut,
    ]);
    exit;
}

// ── Seiten-Status laden ───────────────────────────────────────────────────────
$tournament   = getTournament($db, $userId);
$currentMatch = null;
$roundStats   = null;
$ranking      = [];
$posRanking   = [];
$playedTotal  = 0;

$poolFilmCount = 0;
try {
    $db->exec("CREATE TABLE IF NOT EXISTS tournament_pool (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        movie_id   INT NOT NULL UNIQUE,
        sort_order INT DEFAULT 0,
        INDEX idx_sort (sort_order)
    )");
    $poolFilmCount = (int)$db->query(
        "SELECT COUNT(*) FROM tournament_pool tp JOIN movies m ON m.id=tp.movie_id WHERE 1=1"
        . seriesSqlFilter('m') . moviesSqlFilter('m') . hiddenFilmsSqlFilter('m')
    )->fetchColumn();
} catch (\PDOException $e) {}
$dbFilmCount = (int)$db->query(
    "SELECT COUNT(*) FROM movies WHERE 1=1"
    . seriesSqlFilter('movies') . moviesSqlFilter('movies') . hiddenFilmsSqlFilter('movies')
)->fetchColumn();
// Pool nur nutzen wenn er für Phase II (1024 Filme) ausreicht
$usingPool = $poolFilmCount >= 1024;
$validPows     = [2,4,8,16,32,64,128,256,512,1024,2048,4096,8192,16384,32768,65536,131072,262144];
$defaultCount  = isset($_GET['new']) ? 2 : nextPow2($dbFilmCount);
$defaultActual = min($dbFilmCount, $defaultCount);
$defaultByes   = $defaultCount - $defaultActual;    // freilos in round 1

// Preload distinct genres and countries for filter comboboxes
function loadDistinctValues(PDO $db, string $col): array {
    $rows = $db->query("SELECT DISTINCT {$col} FROM movies WHERE {$col} != '' AND {$col} IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $vals = [];
    foreach ($rows as $row) {
        foreach (array_map('trim', explode(',', $row)) as $v) {
            if ($v !== '') $vals[$v] = true;
        }
    }
    $keys = array_keys($vals);
    sort($keys);
    return $keys;
}
// Nur laden wenn kein aktives Turnier läuft (Filter nur im Start-Formular benötigt)
$_needFilters     = !$tournament || $tournament['status'] !== 'active';
$_filterGenres    = $_needFilters ? loadDistinctValues($db, 'genre')   : [];
$_filterCountries = $_needFilters ? loadDistinctValues($db, 'country') : [];

$initCommRanks = null;
if ($tournament) {
    if ($tournament['status'] === 'active') {
        $currentMatch = getNextMatch($db, $tournament);

        // Recovery: Runde komplett aber nächste noch nicht erstellt
        if (!$currentMatch) {
            $tId   = (int)$tournament['id'];
            $round = (int)$tournament['current_round'];
            $ps = $db->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id=? AND runde=? AND winner_id IS NULL");
            $ps->execute([$tId, $round]);
            $pending = (int)$ps->fetchColumn();
            if ($pending === 0) {
                try {
                    // Kaputte Matches (fehlende Filme) als abgeschlossen markieren
                    $db->prepare("UPDATE tournament_matches SET winner_id = movie_a_id WHERE tournament_id=? AND runde=? AND winner_id IS NULL AND NOT EXISTS (SELECT 1 FROM movies WHERE id=movie_b_id)")->execute([$tId, $round]);
                    $db->prepare("UPDATE tournament_matches SET winner_id = movie_b_id WHERE tournament_id=? AND runde=? AND winner_id IS NULL AND NOT EXISTS (SELECT 1 FROM movies WHERE id=movie_a_id)")->execute([$tId, $round]);
                    // Nächste Runde vorbereiten (advanceRound-Logik inline)
                    $ws = $db->prepare("SELECT winner_id FROM tournament_matches WHERE tournament_id=? AND runde=? ORDER BY match_number");
                    $ws->execute([$tId, $round]);
                    $winners = array_map('intval', $ws->fetchAll(PDO::FETCH_COLUMN));
                    if ($round === 1) {
                        // Freilos-Filme (bye) nur bei Runde 1→2 einbeziehen (top seeds zuerst)
                        $bs = $db->prepare("SELECT movie_id FROM tournament_films WHERE tournament_id=? AND bye=1 ORDER BY seed ASC");
                        $bs->execute([$tId]);
                        $byes = array_map('intval', $bs->fetchAll(PDO::FETCH_COLUMN));
                        $survivors = array_unique(array_merge($byes, $winners));
                    } else {
                        $survivors = array_unique($winners);
                    }
                    $cnt = count($survivors);
                    if ($cnt <= 1) {
                        $db->prepare("UPDATE user_tournaments SET status='completed' WHERE id=?")->execute([$tId]);
                        $tournament['status'] = 'completed';
                    } else {
                        $nextRound = $round + 1;
                        $paired = [];
                        for ($i = 0; $i < intdiv($cnt, 2); $i++) {
                            $paired[] = [$tId, $nextRound, $i+1, $survivors[$i], $survivors[$cnt-1-$i]];
                        }
                        shuffle($paired);
                        foreach (array_chunk($paired, 500) as $chunk) {
                            $ph   = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
                            $flat = array_merge(...$chunk);
                            $db->prepare("INSERT IGNORE INTO tournament_matches (tournament_id,runde,match_number,movie_a_id,movie_b_id) VALUES $ph")->execute($flat);
                        }
                        $db->prepare("UPDATE user_tournaments SET current_round=? WHERE id=?")->execute([$nextRound, $tId]);
                        $tournament['current_round'] = $nextRound;
                        $currentMatch = getNextMatch($db, $tournament);
                    }
                } catch (\Throwable $e) { /* zeige leeren Zustand */ }
            }
        }

        $roundStats   = getRoundStats($db, $tournament['id'], $tournament['current_round']);
        $pStmt = $db->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ? AND winner_id IS NOT NULL");
        $pStmt->execute([$tournament['id']]);
        $playedTotal = (int)$pStmt->fetchColumn();
        // Initiale Sidebar-Stats für das erste Duell
        if ($currentMatch) {
            $initCommRanks = buildCommRanks($db,
                (int)$currentMatch['a']['id'], movieTitle($currentMatch['a']),
                (int)$currentMatch['b']['id'], movieTitle($currentMatch['b']),
                $userId, getActiveMtFilter());
        }
    }
    $ranking    = getTournamentRanking($db, $tournament['id']);
    $posRanking = getPosRanking($db, $userId);
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="turnier-page py-4">
<div class="container-xxl turnier-wrap px-3 px-lg-4">

<?php
$_phase    = userPhase();
$_showIntro = ($_phase === 2) && (!$tournament) && !isset($_GET['go']) && !isset($_GET['new']);
if (isset($_GET['phase_blocked'])): ?>
<div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
    <i class="bi bi-lock-fill me-2"></i>
    <strong>Bereich noch gesperrt.</strong>
    Schließe zuerst das Sichtungsturnier ab, um alle Funktionen freizuschalten.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($_phase === 2): ?>
<div class="mb-4 px-4 py-3 rounded-3 d-flex align-items-center gap-3"
     style="background:rgba(232,184,75,.1); border:1px solid rgba(232,184,75,.25);">
    <i class="bi bi-trophy-fill text-gold" style="font-size:1.6rem; flex-shrink:0;"></i>
    <div>
        <div class="fw-semibold text-gold mb-1">Phase 2 – Sichtungsturnier</div>
        <div class="small opacity-75">
            Führe das Sichtungsturnier zu Ende, um <strong>Meine Rangliste</strong>,
            die <strong>Filmdatenbank</strong> und alle weiteren Funktionen freizuschalten.
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($_showIntro): ?>
<!-- ── Phase-II-Intro-Screen ────────────────────────────────────────────────── -->
<div id="phase2-intro" class="row justify-content-center">
    <div class="col-lg-8">
        <div class="turnier-intro-card">
            <div class="text-center mb-4">
                <div class="text-gold mb-3" style="font-size:3rem;"><i class="bi bi-trophy-fill"></i></div>
                <h2 class="fw-bold mb-2">Phase II – Sichtungsturnier</h2>
                <p class="opacity-75" style="max-width:560px; margin:0 auto;">
                    Du bewertest <strong class="text-gold">1.024 Filme</strong> in einem Single-Elimination-Turnier –
                    wie bei einem echten Sportwettkampf. Jeder Film trifft auf einen anderen,
                    der Sieger kommt in die nächste Runde.
                </p>
            </div>
            <div class="row g-3 mb-4 text-center">
                <div class="col-md-4">
                    <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); border-radius:10px; padding:1rem;">
                        <div class="text-gold fw-bold" style="font-size:1.4rem;">1.024</div>
                        <div class="small opacity-60">Filme im Bracket</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); border-radius:10px; padding:1rem;">
                        <div class="text-gold fw-bold" style="font-size:1.4rem;">10</div>
                        <div class="small opacity-60">Runden</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); border-radius:10px; padding:1rem;">
                        <div class="text-gold fw-bold" style="font-size:1.4rem;">1.023</div>
                        <div class="small opacity-60">Duelle gesamt</div>
                    </div>
                </div>
            </div>
            <div class="mb-4" style="background:rgba(232,184,75,.06); border:1px solid rgba(232,184,75,.2); border-radius:10px; padding:1.25rem;">
                <h6 class="text-gold fw-bold mb-2"><i class="bi bi-info-circle me-1"></i>Was dich erwartet</h6>
                <ul class="mb-0 small opacity-85" style="padding-left:1.25rem;">
                    <li>Zwei Filmcover erscheinen – wähle den Film, den du lieber siehst</li>
                    <li>Nach jeder Runde siehst du deine aktuelle Top-10 und Statistiken</li>
                    <li>Der Verlierer scheidet aus, der Sieger zieht weiter</li>
                    <li>Am Ende stehen 64 Finalisten für Phase III (Jeder gegen Jeden)</li>
                    <li class="text-warning">Eventuell treffen in frühen Runden 2 Lieblingsfilme aufeinander – das ist normal und wird in Phase III verfeinert</li>
                </ul>
            </div>
            <div class="mb-4" style="background:rgba(255,100,100,.06); border:1px solid rgba(255,100,100,.2); border-radius:10px; padding:1.25rem;">
                <h6 style="color:#f07070;" class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Einschränkung</h6>
                <p class="small mb-0 opacity-85">
                    Bei 1.024 Filmen bleiben <strong>512 Verlierer-Filme</strong> aus Runde 1 ohne exakte Reihenfolge.
                    Die Verfeinerung erfolgt in Phase III &amp; IV.
                </p>
            </div>
            <div class="text-center">
                <a href="?go=1" class="btn btn-gold btn-lg px-5">
                    <i class="bi bi-play-fill me-2"></i>Turnier starten
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$_showIntro && (!$tournament || isset($_GET['new']))): ?>
<!-- ── Kein Turnier – Startscreen ──────────────────────────────────────────── -->
<div class="row justify-content-center" id="turnier-start-screen">
    <div class="col-lg-7">
        <div class="turnier-intro-card text-center">
            <div class="text-gold mb-3" style="font-size:3.5rem; line-height:1;">
                <i class="bi bi-trophy-fill"></i>
            </div>
            <h1 class="fw-bold mb-2">Sichtungsturnier</h1>
            <p class="opacity-75 mb-4" style="max-width:480px; margin:0 auto;">
                Single-Elimination-Bracket mit den ersten
                <strong id="stat-desc-n"><?= number_format($defaultActual) ?></strong> Filmen der Datenbank.
                Jeder Sieg zählt als Turnierpunkt <em>und</em> verbessert die ELO-Rangliste.
            </p>

            <div class="row g-3 mb-4 text-start">
                <div class="col-sm-4">
                    <div class="turnier-stat-box">
                        <div class="turnier-stat-val text-gold" id="stat-filme"><?= number_format($defaultActual) ?></div>
                        <div class="turnier-stat-lbl">Filme</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="turnier-stat-box">
                        <div class="turnier-stat-val text-gold" id="stat-runden"><?= (int)log($defaultCount, 2) ?></div>
                        <div class="turnier-stat-lbl">Runden</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="turnier-stat-box">
                        <div class="turnier-stat-val text-gold" id="stat-duelle"><?= number_format($defaultActual - 1) ?></div>
                        <div class="turnier-stat-lbl">Duelle gesamt</div>
                    </div>
                </div>
            </div>

            <div class="turnier-bracket-preview mb-4">
                <div class="brow" id="bp-freilos"<?= $defaultByes > 0 ? '' : ' style="display:none;"' ?>>
                    <span id="bp-freilos-range">Seed 1–<?= number_format($defaultByes) ?></span>
                    <span class="vs" style="font-size:.65rem; letter-spacing:.04em;">FREILOS</span>
                    <span class="text-muted small">Runde 2 direkt</span>
                </div>
                <div class="brow">
                    <span id="bp-seed1-label"><?= $defaultByes > 0 ? 'Seed ' . number_format($defaultByes + 1) : 'Seed 1' ?></span>
                    <span class="vs">vs</span>
                    <span id="bp-top">Seed <?= number_format($defaultActual) ?></span>
                </div>
                <div class="brow">
                    <span id="bp-seed2-label"><?= $defaultByes > 0 ? 'Seed ' . number_format($defaultByes + 2) : 'Seed 2' ?></span>
                    <span class="vs">vs</span>
                    <span id="bp-top2">Seed <?= number_format($defaultActual - 1) ?></span>
                </div>
                <div class="brow text-muted small"><span>…</span><span class="vs"></span><span></span></div>
                <div class="brow">
                    <span id="bp-mid">Seed <?= number_format(($defaultByes + $defaultActual) / 2) ?></span>
                    <span class="vs">vs</span>
                    <span id="bp-mid2">Seed <?= number_format(($defaultByes + $defaultActual) / 2 + 1) ?></span>
                </div>
            </div>

            <?php if ($dbFilmCount < 2): ?>
            <div class="alert alert-warning">Zu wenig Filme in der Datenbank. Bitte erst Filme importieren.</div>
            <?php elseif ($dbFilmCount < 64): ?>
            <div class="mb-3 px-3 py-3 rounded-3 d-flex align-items-start gap-3"
                 style="background:rgba(255,193,7,.08); border:1px solid rgba(255,193,7,.25);">
                <i class="bi bi-exclamation-triangle-fill text-warning mt-1 flex-shrink-0"></i>
                <div>
                    <div class="fw-semibold" style="color:#ffc107;">Zu wenig Filme für das Sichtungsturnier</div>
                    <div class="small opacity-75 mt-1">
                        Mindestens <strong>64 Filme</strong> werden benötigt.
                        Aktuell: <strong><?= number_format($dbFilmCount) ?></strong> Filme.
                    </div>
                    <a href="/import.php" class="btn btn-sm mt-2"
                       style="background:rgba(255,193,7,.15); border:1px solid rgba(255,193,7,.3); color:#ffc107;">
                        <i class="bi bi-cloud-download me-1"></i>Filme importieren
                    </a>
                </div>
            </div>
            <?php else: ?>

            <?php if ($usingPool && isAdmin()): ?>
            <div class="d-flex align-items-center justify-content-center gap-2 mb-3"
                 style="font-size:.8rem; background:rgba(232,184,75,.08); border:1px solid rgba(232,184,75,.2);
                        border-radius:8px; padding:.5rem 1rem; color:#e8b84b;">
                <i class="bi bi-collection-fill"></i>
                <span>Turnier-Pool aktiv: <strong><?= number_format($poolFilmCount) ?></strong> Filme</span>
                <a href="/admin-tournament-pool.php" style="color:#e8b84b; margin-left:.5rem; font-size:.75rem;">
                    Pool bearbeiten →
                </a>
            </div>
            <?php endif; ?>

            <div class="d-flex align-items-center justify-content-center gap-2 mb-4"
                 style="font-size:.8rem; color:rgba(255,255,255,.45);">
                <i class="bi bi-info-circle"></i>
                <span>
                    <strong style="color:var(--mkfb-gold);"><?= number_format($dbFilmCount) ?></strong> Filme verfügbar
                    <?php if ($usingPool): ?>
                    &nbsp;·&nbsp; Pool aktiv bis 4K
                    <?php endif; ?>
                    &nbsp;·&nbsp;
                    <a href="/import.php" class="text-gold text-decoration-none">
                        <i class="bi bi-cloud-download me-1"></i>Mehr importieren
                    </a>
                </span>
            </div>

            <div class="mb-4">
                <?php $isNewTournament = isset($_GET['new']); ?>
                <?php if ($_phase === 2): ?>
                <!-- Phase II: immer 1024 Filme, keine Auswahl -->
                <div class="d-flex align-items-center justify-content-center gap-2"
                     style="background:rgba(232,184,75,.08); border:1px solid rgba(232,184,75,.2);
                            border-radius:8px; padding:.6rem 1.2rem; color:#e8b84b; font-size:.9rem;">
                    <i class="bi bi-diagram-3-fill"></i>
                    <span>Sichtungsturnier mit <strong>1.024 Filmen</strong></span>
                </div>
                <?php else: ?>
                <!-- Phase IV+: freie Auswahl -->
                <div class="text-light small mb-2" style="opacity:.65;">
                    <i class="bi bi-collection-fill me-1"></i>Anzahl Filme im Bracket<?= $isNewTournament ? '' : ' <span style="color:rgba(255,255,255,.3);">(min. 64)</span>' ?>:
                </div>
                <div class="d-flex gap-2 justify-content-center flex-wrap">
                    <?php
                    $maxBracket = nextPow2($dbFilmCount);
                    $minBracket = $isNewTournament ? 2 : 64;
                    foreach ($validPows as $p):
                        if ($p < $minBracket) continue;
                        if ($p > $maxBracket) break;
                        $label   = $p >= 1024 ? ($p / 1024) . 'K' : (string)$p;
                        $hasByes = $p > $dbFilmCount; ?>
                    <button type="button" class="btn-count<?= $p === $defaultCount ? ' active' : '' ?>"
                            onclick="selectFilmCount(<?= $p ?>, this)"
                            title="<?= $hasByes ? number_format($dbFilmCount) . ' Filme + ' . number_format($p - $dbFilmCount) . ' Freilose' : number_format($p) . ' Filme' ?>"><?= $label ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if (($_GET['filter_error'] ?? '') === 'no_films'): ?>
            <div class="mb-3 px-1 py-2 rounded d-flex align-items-center gap-2"
                 style="background:rgba(244,67,54,.12); border:1px solid rgba(244,67,54,.3); color:#ef9a9a; font-size:.88rem;">
                <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                <span>Keine Filme für diese Filterauswahl gefunden. Bitte andere Kriterien wählen.</span>
            </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="mb-3" id="turnier-filters">
                <div class="text-light small mb-2" style="opacity:.65;">
                    <i class="bi bi-funnel-fill me-1"></i>Filter <span style="opacity:.6;">(optional – leer = alle Filme)</span>:
                </div>
                <div class="row g-2">
                    <div class="col-12 col-sm-6">
                        <select id="tf_genre" class="form-select form-select-sm turnier-filter-input">
                            <option value="">– Genre –</option>
                            <?php foreach ($_filterGenres as $g): ?>
                            <option value="<?= e($g) ?>"><?= e($g) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6">
                        <select id="tf_country" class="form-select form-select-sm turnier-filter-input">
                            <option value="">– Produktionsland –</option>
                            <?php foreach ($_filterCountries as $c): ?>
                            <option value="<?= e($c) ?>"><?= e($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 position-relative">
                        <input type="text" id="tf_director" class="form-control form-control-sm turnier-filter-input"
                               placeholder="Regie" autocomplete="off">
                        <ul class="turnier-suggest-list" id="sug_director"></ul>
                    </div>
                    <div class="col-12 col-sm-6 position-relative">
                        <input type="text" id="tf_actor" class="form-control form-control-sm turnier-filter-input"
                               placeholder="Darsteller" autocomplete="off">
                        <ul class="turnier-suggest-list" id="sug_actor"></ul>
                    </div>
                </div>
            </div>

            <form method="post" id="start-form">
                <input type="hidden" name="action"     value="start">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="mode"       value="ordered" id="mode-input">
                <input type="hidden" name="film_count" value="<?= $_phase === 2 ? 1024 : $defaultCount ?>" id="film-count-input">
                <input type="hidden" name="filter_genre"    id="filter_genre"    value="">
                <input type="hidden" name="filter_country"  id="filter_country"  value="">
                <input type="hidden" name="filter_director" id="filter_director" value="">
                <input type="hidden" name="filter_actor"    id="filter_actor"    value="">
                <div class="d-flex gap-3 justify-content-center flex-wrap">
                    <button type="submit" class="btn btn-gold btn-lg px-4 start-btn"
                            onclick="syncFilters();document.getElementById('mode-input').value='ordered'">
                        <i class="bi bi-sort-numeric-down me-2"></i>DB-Reihenfolge
                    </button>
                    <button type="submit" class="btn btn-outline-light btn-lg px-4 start-btn"
                            onclick="syncFilters();document.getElementById('mode-input').value='random'">
                        <i class="bi bi-shuffle me-2"></i>Zufällig
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php elseif ($tournament['status'] === 'completed'): ?>
<!-- ── Turnier abgeschlossen ───────────────────────────────────────────────── -->
<?php $champion = $ranking[0] ?? null; ?>
<div class="row g-4 justify-content-center mb-4">
    <div class="col-lg-7 text-center">
        <div class="turnier-intro-card">
            <div class="text-gold fw-black mb-1" style="font-size:3rem; line-height:1;">
                <i class="bi bi-trophy-fill"></i>
            </div>
            <h2 class="fw-bold mb-1">Turnier abgeschlossen!</h2>
            <?php if ($champion): ?>
            <p class="opacity-75 mb-3">Turniersieger mit <?= $champion['points'] ?> Siegen:</p>
            <div class="d-flex align-items-center justify-content-center gap-3 mb-4">
                <img src="<?= e(moviePosterUrl($champion, 'w92')) ?>"
                     width="46" height="69" alt="<?= e(movieTitle($champion)) ?>"
                     style="border-radius:4px; border:2px solid var(--mkfb-gold);">
                <div class="text-start">
                    <div class="fw-bold fs-5"><?= e(movieTitle($champion)) ?></div>
                    <div class="text-gold"><?= $champion['points'] ?> Siege</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$_jgjStarted = false;
if (userPhase() === 3) {
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM jgj_pool WHERE user_id = ?");
        $s->execute([$userId]);
        $_jgjStarted = (int)$s->fetchColumn() > 0;
    } catch (\PDOException $e) {}
}
?>
<?php if (userPhase() === 3 && !$_jgjStarted): ?>
<div class="row justify-content-center mb-4">
    <div class="col-lg-7">
        <div class="turnier-intro-card" style="border-color:rgba(232,184,75,.35);">
            <div class="fw-bold mb-2" style="color:#e8b84b; font-size:1.1rem;">
                <i class="bi bi-people-fill me-2"></i>Nächster Schritt: Jeder gegen Jeden
            </div>
            <p class="opacity-75 small mb-3">
                Die <strong style="color:#e8b84b;">64 Gewinner der Runde der 128</strong> treten jetzt gegeneinander an. Das Turnier wird vollständig zu Ende gespielt.
                Jeder Film trifft auf jeden anderen – die Siege/Niederlagen-Quote bestimmt dein pers&ouml;nliches Ranking.
            </p>
            <a href="/jgj.php" class="btn btn-gold px-4">
                <i class="bi bi-play-fill me-1"></i>Jeder gegen Jeden starten
            </a>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if (userPhase() >= 3):
    $nextMaxBracket = nextPow2($dbFilmCount);
    $nextDefault    = min($nextMaxBracket, nextPow2($dbFilmCount));
?>
<div class="row justify-content-center mb-4">
    <div class="col-lg-7">
        <div class="turnier-intro-card" style="border-color:rgba(232,184,75,.35);">
            <div class="fw-bold mb-2" style="color:#e8b84b; font-size:1.1rem;">
                <i class="bi bi-diagram-3-fill me-2"></i>Neues Turnier starten
            </div>
            <p class="opacity-75 small mb-3">
                Wähle die Turniergröße und starte ein neues Bracket.
                <span style="color:rgba(255,255,255,.4);">
                    <?= number_format($dbFilmCount) ?> Filme verfügbar.
                </span>
            </p>

            <!-- Bracket-Größe -->
            <div class="text-light small mb-2" style="opacity:.6;">
                <i class="bi bi-collection-fill me-1"></i>Anzahl Filme im Bracket:
            </div>
            <div class="d-flex gap-2 justify-content-center flex-wrap mb-3" id="next-count-btns">
                <?php foreach ($validPows as $p):
                    if ($p < 2) continue;
                    if ($p > $nextMaxBracket) break;
                    $label   = $p >= 1024 ? ($p / 1024) . 'K' : (string)$p;
                    $hasByes = $p > $dbFilmCount;
                ?>
                <button type="button"
                        class="btn-count<?= $p === $nextDefault ? ' active' : '' ?>"
                        onclick="nextSelectCount(<?= $p ?>, this)"
                        title="<?= $hasByes ? number_format($dbFilmCount) . ' Filme + ' . number_format($p - $dbFilmCount) . ' Freilose' : number_format($p) . ' Filme' ?>">
                    <?= $label ?>
                </button>
                <?php endforeach; ?>
            </div>

            <?php if (($_GET['filter_error'] ?? '') === 'no_films'): ?>
            <div class="mb-3 px-1 py-2 rounded d-flex align-items-center gap-2"
                 style="background:rgba(244,67,54,.12); border:1px solid rgba(244,67,54,.3); color:#ef9a9a; font-size:.88rem;">
                <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                <span>Keine Filme für diese Filterauswahl gefunden. Bitte andere Kriterien wählen.</span>
            </div>
            <?php endif; ?>

            <!-- Filter -->
            <div class="mb-3" id="next-turnier-filters">
                <div class="text-light small mb-2" style="opacity:.65;">
                    <i class="bi bi-funnel-fill me-1"></i>Filter <span style="opacity:.6;">(optional – leer = alle Filme)</span>:
                </div>
                <div class="row g-2">
                    <div class="col-12 col-sm-6">
                        <select id="ntf_genre" class="form-select form-select-sm turnier-filter-input">
                            <option value="">– Genre –</option>
                            <?php foreach ($_filterGenres as $g): ?>
                            <option value="<?= e($g) ?>"><?= e($g) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6">
                        <select id="ntf_country" class="form-select form-select-sm turnier-filter-input">
                            <option value="">– Produktionsland –</option>
                            <?php foreach ($_filterCountries as $c): ?>
                            <option value="<?= e($c) ?>"><?= e($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 position-relative">
                        <input type="text" id="ntf_director" class="form-control form-control-sm turnier-filter-input"
                               placeholder="Regie" autocomplete="off">
                        <ul class="turnier-suggest-list" id="nsug_director"></ul>
                    </div>
                    <div class="col-12 col-sm-6 position-relative">
                        <input type="text" id="ntf_actor" class="form-control form-control-sm turnier-filter-input"
                               placeholder="Darsteller" autocomplete="off">
                        <ul class="turnier-suggest-list" id="nsug_actor"></ul>
                    </div>
                </div>
            </div>

            <!-- Start-Formular -->
            <form method="post" id="next-turnier-form">
                <input type="hidden" name="action"     value="start">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="mode"       value="ordered" id="next-mode-input">
                <input type="hidden" name="film_count" value="<?= $nextDefault ?>" id="next-count-input">
                <input type="hidden" name="filter_genre"    id="next-filter_genre"    value="">
                <input type="hidden" name="filter_country"  id="next-filter_country"  value="">
                <input type="hidden" name="filter_director" id="next-filter_director" value="">
                <input type="hidden" name="filter_actor"    id="next-filter_actor"    value="">
                <div class="d-flex gap-2 justify-content-center flex-wrap">
                    <button type="submit" class="btn btn-gold px-4"
                            onclick="syncNextFilters();document.getElementById('next-mode-input').value='ordered'">
                        <i class="bi bi-sort-numeric-down me-2"></i>DB-Reihenfolge
                    </button>
                    <button type="submit" class="btn btn-outline-light px-4"
                            onclick="syncNextFilters();document.getElementById('next-mode-input').value='random'">
                        <i class="bi bi-shuffle me-2"></i>Zufällig
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function nextSelectCount(n, btn) {
    document.getElementById('next-count-input').value = n;
    document.querySelectorAll('#next-count-btns .btn-count')
            .forEach(b => b.classList.toggle('active', b === btn));
}
// Autocomplete for second form's text filters
(function() {
    var acFields = {
        'ntf_director': {sug:'nsug_director', hidden:'next-filter_director', api:'director'},
        'ntf_actor':    {sug:'nsug_actor',    hidden:'next-filter_actor',    api:'actors'}
    };
    var activeIdx = {};
    Object.entries(acFields).forEach(function([id, cfg]) {
        var inp = document.getElementById(id);
        var sug = document.getElementById(cfg.sug);
        if (!inp || !sug) return;
        var timer = null;
        activeIdx[id] = -1;
        inp.addEventListener('input', function() {
            clearTimeout(timer);
            var q = inp.value.trim();
            if (q.length < 1) { sug.innerHTML=''; sug.classList.remove('open'); return; }
            timer = setTimeout(function() {
                fetch('/turnier.php?action=suggest&field=' + cfg.api + '&q=' + encodeURIComponent(q))
                    .then(r => r.json()).then(function(arr) {
                        sug.innerHTML=''; activeIdx[id]=-1;
                        if (!arr.length) { sug.classList.remove('open'); return; }
                        arr.forEach(function(val) {
                            var li = document.createElement('li');
                            li.textContent = val;
                            li.addEventListener('mousedown', function(e) {
                                e.preventDefault(); inp.value=val;
                                sug.innerHTML=''; sug.classList.remove('open');
                            });
                            sug.appendChild(li);
                        });
                        sug.classList.add('open');
                    }).catch(function(){});
            }, 200);
        });
        inp.addEventListener('keydown', function(e) {
            var items = sug.querySelectorAll('li');
            if (!items.length) return;
            if (e.key==='ArrowDown') { e.preventDefault(); activeIdx[id]=Math.min(activeIdx[id]+1,items.length-1); items.forEach((li,i)=>li.classList.toggle('active',i===activeIdx[id])); }
            else if (e.key==='ArrowUp') { e.preventDefault(); activeIdx[id]=Math.max(activeIdx[id]-1,-1); items.forEach((li,i)=>li.classList.toggle('active',i===activeIdx[id])); }
            else if (e.key==='Enter' && activeIdx[id]>=0 && items[activeIdx[id]]) { e.preventDefault(); inp.value=items[activeIdx[id]].textContent; sug.innerHTML=''; sug.classList.remove('open'); }
            else if (e.key==='Escape') { sug.innerHTML=''; sug.classList.remove('open'); }
        });
        inp.addEventListener('blur', function() { setTimeout(()=>{ sug.innerHTML=''; sug.classList.remove('open'); }, 150); });
    });
    window.syncNextFilters = function() {
        document.getElementById('next-filter_genre').value   = document.getElementById('ntf_genre')?.value   || '';
        document.getElementById('next-filter_country').value = document.getElementById('ntf_country')?.value || '';
        document.getElementById('next-filter_director').value = document.getElementById('ntf_director')?.value.trim() || '';
        document.getElementById('next-filter_actor').value    = document.getElementById('ntf_actor')?.value.trim()    || '';
    };
})();
</script>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <h5 class="fw-bold text-gold mb-3"><i class="bi bi-list-ol me-2"></i>Turnierrangliste</h5>
        <div class="turnier-ranking-wrap">
            <div class="turnier-ranking-list">
                <?php foreach ($ranking as $i => $r): ?>
                <div class="turnier-rank-row">
                    <span class="turnier-rank-num <?= $i < 3 ? 'top' : '' ?>"><?= $i + 1 ?></span>
                    <img src="<?= e(moviePosterUrl($r, 'w92')) ?>"
                         alt="<?= e(movieTitle($r)) ?>" class="turnier-rank-poster" width="26" height="39"
                         loading="lazy" onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
                    <div class="turnier-rank-title">
                        <?= e(movieTitle($r)) ?>
                        <span class="opacity-50">(<?= (int)$r['year'] ?>)</span>
                    </div>
                    <span class="turnier-points"><?= (int)$r['points'] ?> <i class="bi bi-star-fill text-gold" style="font-size:.65rem"></i></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif (!$_showIntro && $tournament && $tournament['status'] === 'active'): ?>
<!-- ── Aktives Turnier – Duell ──────────────────────────────────────────────── -->
<p class="text-center mb-3" style="color:rgba(255,255,255,.4); font-size:clamp(1.4rem,2.5vw,2.5rem); white-space:nowrap; overflow:hidden;">
    <?= t('tournament.question') ?>
</p>
<div class="liga-3col">

    <!-- Links: Meine Rangliste -->
    <div class="liga-side" id="pos-ranking-col"<?= empty($posRanking) ? ' style="display:none;"' : '' ?>>
        <div class="turnier-ranking-wrap">
            <div class="turnier-ranking-header">
                <i class="bi bi-list-ol me-2"></i><?= $mtActive === 'tv' ? 'Meine Rangliste Serien' : ($mtActive === 'movie' ? 'Meine Rangliste Filme' : 'Meine Rangliste') ?>
            </div>
            <div id="pos-current-film" style="display:none; padding:.45rem .75rem; background:rgba(232,184,75,.1); border-bottom:1px solid rgba(232,184,75,.25); font-size:.78rem; color:#e8b84b; align-items:center; gap:.5rem;">
                <span style="font-weight:700; flex-shrink:0;" id="pos-current-num">#–</span>
                <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" id="pos-current-title"></span>
            </div>
            <div class="turnier-ranking-list" id="pos-ranking-list">
                <?php if (empty($posRanking)): ?>
                <div class="turnier-rank-row" style="color:rgba(255,255,255,.3); font-size:.75rem; justify-content:center;">
                    Noch keine Einträge
                </div>
                <?php else: ?>
                <?php foreach ($posRanking as $r): ?>
                <div class="turnier-rank-row" data-film-id="<?= (int)$r['id'] ?>">
                    <span class="turnier-rank-num <?= (int)$r['position'] <= 3 ? 'top' : '' ?>"><?= (int)$r['position'] ?></span>
                    <img src="<?= e(moviePosterUrl($r, 'w92')) ?>" class="turnier-rank-poster" width="26" height="39"
                         loading="lazy" decoding="async"
                         onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
                    <div class="turnier-rank-title"><a href="/film.php?id=<?= (int)$r['id'] ?>" class="film-link"><?= e(movieTitle($r)) ?></a></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-center mt-2" style="font-size:.72rem;">
            <a href="/meine-rangliste.php" style="color:rgba(232,184,75,.5); text-decoration:none;">
                Alle <?= count($posRanking) ?> Filme anzeigen →
            </a>
        </p>
    </div>

    <!-- Mitte: Duel-Arena -->
    <div class="liga-center" id="turnier-duel-col">

        <?php if ($currentMatch): ?>

        <!-- Fortschritt -->
        <div class="mb-3">
            <div class="d-flex justify-content-between text-light small mb-1" style="opacity:.75">
                <span id="round-label">
                    <i class="bi bi-diagram-3 me-1"></i>Runde <?= (int)$tournament['current_round'] ?> von <?= (int)$tournament['total_rounds'] ?>
                    &nbsp;·&nbsp; Spiel <?= (int)($roundStats['played'] ?? 0) + 1 ?> von <?= (int)($roundStats['total'] ?? 0) ?>
                </span>
                <span id="progress-text"><?= $playedTotal ?> / <?= (int)$tournament['film_count'] - 1 ?> gesamt</span>
            </div>
            <div class="progress mb-1" style="height:8px; border-radius:4px; background:rgba(255,255,255,.1);">
                <div id="progress-bar" class="progress-bar bg-gold"
                     style="width:<?= $tournament['film_count'] > 1 ? round($playedTotal / ($tournament['film_count'] - 1) * 100, 1) : 0 ?>%;
                            border-radius:4px; transition:width .4s ease;">
                </div>
            </div>
        </div>

        <!-- Duel-Arena -->
        <div class="duel-arena" id="duel-arena">
            <div class="duel-container" style="min-height:auto; max-width:none;">
                <!-- Film A -->
                <div class="duel-side" id="movie-a"
                     data-id="<?= (int)$currentMatch['a']['id'] ?>"
                     data-opponent="<?= (int)$currentMatch['b']['id'] ?>"
                     data-match="<?= (int)$currentMatch['id'] ?>"
                     data-overview="<?= e($currentMatch['a']['overview'] ?? '') ?>"
                     data-overview-title="<?= e(movieTitle($currentMatch['a'])) ?>"
                     >
                    <div class="duel-poster-wrap">
                        <img src="<?= e(moviePosterUrl($currentMatch['a'])) ?>"
                             alt="<?= e(movieTitle($currentMatch['a'])) ?>"
                             class="duel-poster" fetchpriority="high" decoding="async"
                             onerror="this.onerror=null;this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                        <div class="duel-overlay">
                            <i class="bi bi-hand-thumbs-up-fill"></i><span>Wählen</span>
                        </div>
                    </div>
                    <div class="duel-info">
                        <h3 class="duel-title"><?= e(movieTitle($currentMatch['a'])) ?></h3>
                        <div class="duel-meta">
                            <span class="badge bg-dark"><?= (int)$currentMatch['a']['year'] ?></span>
                        </div>
                    </div>
                </div>

                <div class="vs-divider">
                    <div class="vs-circle">VS</div>
                    <button id="undo-btn"
                            onclick="castUndo()"
                            <?= $playedTotal === 0 ? 'disabled' : '' ?>
                            title="Letztes Duell rückgängig"
                            style="background:none; border:none; padding:0; cursor:pointer;
                                   color:rgba(255,255,255,.3); font-size:1.35rem; line-height:1;
                                   transition:color .15s, transform .15s; margin-top:auto;"
                            onmouseover="if(!this.disabled)this.style.color='rgba(232,184,75,.7)'"
                            onmouseout="this.style.color='rgba(255,255,255,.3)'">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                </div>

                <!-- Film B -->
                <div class="duel-side" id="movie-b"
                     data-id="<?= (int)$currentMatch['b']['id'] ?>"
                     data-opponent="<?= (int)$currentMatch['a']['id'] ?>"
                     data-match="<?= (int)$currentMatch['id'] ?>"
                     data-overview="<?= e($currentMatch['b']['overview'] ?? '') ?>"
                     data-overview-title="<?= e(movieTitle($currentMatch['b'])) ?>"
                     >
                    <div class="duel-poster-wrap">
                        <img src="<?= e(moviePosterUrl($currentMatch['b'])) ?>"
                             alt="<?= e(movieTitle($currentMatch['b'])) ?>"
                             class="duel-poster" fetchpriority="high" decoding="async"
                             onerror="this.onerror=null;this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                        <div class="duel-overlay">
                            <i class="bi bi-hand-thumbs-up-fill"></i><span>Wählen</span>
                        </div>
                    </div>
                    <div class="duel-info">
                        <h3 class="duel-title"><?= e(movieTitle($currentMatch['b'])) ?></h3>
                        <div class="duel-meta">
                            <span class="badge bg-dark"><?= (int)$currentMatch['b']['year'] ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <?php else: ?>
        <div class="text-center py-5">
            <div class="text-gold mb-3" style="font-size:2.5rem;"><i class="bi bi-exclamation-triangle"></i></div>
            <p class="opacity-75 mb-4">Kein Duell verfügbar. Das Turnier muss neu gestartet werden.</p>
            <form method="post">
                <input type="hidden" name="action"     value="reset">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <button type="submit" class="btn btn-gold px-4">
                    <i class="bi bi-arrow-repeat me-2"></i>Turnier neu starten
                </button>
            </form>
        </div>
        <?php endif; ?>

    </div><!-- /col-lg-6 -->

    <!-- Rechts: Statistiken (wie JgJ-Modus) -->
    <div class="liga-side">
        <div class="turnier-ranking-wrap">
            <div class="turnier-ranking-header">
                <i class="bi bi-bar-chart-fill me-2"></i>Statistiken
            </div>
            <div class="turnier-ranking-list">
            <!-- Letztes Duell -->
            <div class="duel-stat-section" id="last-duel-stat">
                <div class="duel-stat-lbl">Letztes Duell</div>
                <div class="duel-stat-empty">Noch kein Duell bewertet</div>
            </div>
            <?php $rankSfx = $mtActive === 'tv' ? ' Serien' : ($mtActive === 'movie' ? ' Filme' : ''); ?>
            <!-- Kontext-Fenster um den Sieger -->
            <div class="duel-stat-section" id="comm-context-stat" style="display:none;">
                <div class="duel-stat-lbl">Community Rangliste<?= $rankSfx ?></div>
            </div>
            <div class="duel-stat-section" id="my-context-stat" style="display:none;">
                <div class="duel-stat-lbl">Meine Rangliste<?= $rankSfx ?></div>
            </div>
            <!-- Community-Ranking der aktuellen Duell-Filme -->
            <div class="duel-stat-section" id="comm-rank-stat">
                <div class="duel-stat-lbl">Community Ranking<?= $rankSfx ?></div>
                <?php if (!empty($initCommRanks)): ?>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank"><?= $initCommRanks['a_rank'] ? '#'.$initCommRanks['a_rank'] : '–' ?></span>
                    <span class="duel-comm-title"><?= e(movieTitle($currentMatch['a'])) ?></span>
                </div>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank"><?= $initCommRanks['b_rank'] ? '#'.$initCommRanks['b_rank'] : '–' ?></span>
                    <span class="duel-comm-title"><?= e(movieTitle($currentMatch['b'])) ?></span>
                </div>
                <?php else: ?>
                <div class="duel-stat-empty">Keine Community-Daten</div>
                <?php endif; ?>
            </div>
            <!-- Meine Rangliste: Positionen der aktuellen Duell-Filme -->
            <div class="duel-stat-section" id="my-rank-stat">
                <div class="duel-stat-lbl">Meine Rangliste<?= $rankSfx ?></div>
                <?php if (!empty($initCommRanks)): ?>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank" style="background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.15);color:rgba(255,255,255,.7);"><?= $initCommRanks['a_my_pos'] !== null ? '#'.$initCommRanks['a_my_pos'] : '–' ?></span>
                    <span class="duel-comm-title"><?= e(movieTitle($currentMatch['a'])) ?></span>
                </div>
                <div class="duel-comm-film">
                    <span class="duel-comm-rank" style="background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.15);color:rgba(255,255,255,.7);"><?= $initCommRanks['b_my_pos'] !== null ? '#'.$initCommRanks['b_my_pos'] : '–' ?></span>
                    <span class="duel-comm-title"><?= e(movieTitle($currentMatch['b'])) ?></span>
                </div>
                <?php else: ?>
                <div class="duel-stat-empty">Noch nicht bewertet</div>
                <?php endif; ?>
            </div>
            </div><!-- /.turnier-ranking-list -->
        </div>
        <p class="text-center mt-2" style="font-size:.75rem;">
            <form method="post" class="d-inline">
                <input type="hidden" name="action"     value="reset">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <button class="btn btn-link p-0 text-decoration-none"
                        style="color:rgba(255,255,255,.3); font-size:.75rem;">
                    <i class="bi bi-arrow-clockwise me-1"></i>Turnier abbrechen
                </button>
            </form>
        </p>
    </div><!-- /liga-side: Statistiken -->

</div><!-- /liga-3col -->
<?php endif; ?>

</div>
<!-- ── Zwischen-Runden-Overlay ──────────────────────────────────────────────── -->
<div id="round-summary-overlay" style="display:none; position:fixed; inset:0; z-index:1050;
     background:rgba(10,25,47,.92); backdrop-filter:blur(6px); overflow-y:auto; padding:2rem 1rem;">
    <div style="max-width:700px; margin:0 auto;">
        <div class="turnier-intro-card">
            <div class="text-center mb-4">
                <div class="text-gold mb-2" style="font-size:2.5rem;"><i class="bi bi-flag-fill"></i></div>
                <h3 class="fw-bold text-gold mb-1">Runde <span id="rs-round"></span> abgeschlossen!</h3>
                <p class="opacity-60 small mb-0">So steht es nach dieser Runde</p>
            </div>

            <!-- Statistiken -->
            <div class="row g-3 mb-4 text-center">
                <div class="col-4">
                    <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); border-radius:10px; padding:.9rem;">
                        <div class="text-gold fw-bold" style="font-size:1.3rem;" id="rs-last-duels">–</div>
                        <div class="small opacity-60">Duelle diese Runde</div>
                    </div>
                </div>
                <div class="col-4">
                    <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); border-radius:10px; padding:.9rem;">
                        <div class="text-gold fw-bold" style="font-size:1.3rem;" id="rs-total-duels">–</div>
                        <div class="small opacity-60">Duelle gesamt</div>
                    </div>
                </div>
                <div class="col-4">
                    <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); border-radius:10px; padding:.9rem;">
                        <div class="text-gold fw-bold" style="font-size:1.3rem;" id="rs-next-duels">–</div>
                        <div class="small opacity-60">Duelle nächste Runde</div>
                    </div>
                </div>
            </div>

            <!-- Top 10 Meine Rangliste -->
            <h6 class="text-gold fw-bold mb-2"><i class="bi bi-list-ol me-1"></i>Aktuelle Top 10 – Meine Rangliste</h6>
            <div id="rs-top10" class="mb-4"
                 style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.08); border-radius:10px; overflow:hidden;">
                <!-- befüllt per JS -->
            </div>

            <!-- Filme mit niedrigsten IDs -->
            <h6 class="text-gold fw-bold mb-2"><i class="bi bi-database me-1"></i>Duelle mit niedrigsten Film-IDs (diese Runde)</h6>
            <div id="rs-lowest" class="mb-4"
                 style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.08); border-radius:10px; overflow:hidden;">
                <!-- befüllt per JS -->
            </div>

            <div class="text-center">
                <button class="btn btn-gold btn-lg px-5" onclick="dismissRoundSummary()">
                    <i class="bi bi-arrow-right-circle me-2"></i>Weiter mit Runde <span id="rs-next-round-num"></span>
                </button>
            </div>
        </div>
    </div>
</div>
</main>

<?php if ($tournament && $tournament['status'] === 'active' && $currentMatch): ?>
<script>
const TMDB_W500   = 'https://image.tmdb.org/t/p/w500';
const TMDB_W92    = 'https://image.tmdb.org/t/p/w92';
const PLACEHOLDER = 'https://placehold.co/26x39/1e3a5f/e8b84b?text=?';
const CSRF_TOKEN      = <?= json_encode(csrfToken()) ?>;
const INIT_COMM_RANKS = <?= json_encode($initCommRanks ?? null) ?>;

document.addEventListener('DOMContentLoaded', () => {
    if (INIT_COMM_RANKS) updateDuelStats(null, null, INIT_COMM_RANKS);
});

function updateHdrCounters(totalDuels, uniqueFilms) {
    const dc = document.getElementById('hdr-duels-count');
    const fc = document.getElementById('hdr-films-count');
    if (dc && totalDuels !== undefined) dc.textContent = totalDuels.toLocaleString('de-DE');
    if (fc && uniqueFilms !== undefined) fc.textContent = uniqueFilms;
}

let voting       = false;
let playedTotal  = <?= $playedTotal ?>;
const totalMatches = <?= (int)$tournament['film_count'] - 1 ?>;


document.getElementById('duel-arena').addEventListener('click', function (e) {
    if (voting) return;
    const card = e.target.closest('.duel-side');
    if (!card) return;
    const winnerId = parseInt(card.dataset.id,       10);
    const loserId  = parseInt(card.dataset.opponent, 10);
    const matchId  = parseInt(card.dataset.match,    10);
    if (winnerId && loserId && matchId) castVote(winnerId, loserId, matchId, card);
});

document.addEventListener('keydown', function (e) {
    if (voting) return;
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    e.preventDefault();
    const card = document.getElementById(e.key === 'ArrowLeft' ? 'movie-a' : 'movie-b');
    if (!card) return;
    const winnerId = parseInt(card.dataset.id,       10);
    const loserId  = parseInt(card.dataset.opponent, 10);
    const matchId  = parseInt(card.dataset.match,    10);
    if (winnerId && loserId && matchId) {
        card.classList.add('kb-active');
        castVote(winnerId, loserId, matchId, card);
    }
});

function castVote(winnerId, loserId, matchId, chosenCard) {
    voting = true;
    chosenCard.classList.add('winner-flash');
    document.querySelectorAll('.duel-side').forEach(c => {
        if (c !== chosenCard) c.classList.add('loser-flash');
    });

    const fd = new FormData();
    fd.append('action',     'vote');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('match_id',   matchId);
    fd.append('winner_id',  winnerId);
    fd.append('loser_id',   loserId);

    fetch('/turnier.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(handleResponse)
        .catch(() => {
            voting = false;
            document.querySelectorAll('.duel-side').forEach(c =>
                c.classList.remove('winner-flash', 'loser-flash'));
        });
}

function handleResponse(data) {
    if (data.error) { navLocked = false; location.reload(); return; }
    if (data.done)  { navLocked = false; location.reload(); return; }

    playedTotal++;

    // Fortschrittsbalken
    const pct = Math.round(playedTotal / totalMatches * 100);
    const bar = document.getElementById('progress-bar');
    const txt = document.getElementById('progress-text');
    const lbl = document.getElementById('round-label');
    if (bar) bar.style.width = pct + '%';
    if (txt) txt.textContent = playedTotal + ' / ' + totalMatches + ' gesamt';
    if (lbl) lbl.innerHTML =
        '<i class="bi bi-diagram-3 me-1"></i>Runde ' + data.round + ' von ' + data.totalRounds +
        ' &nbsp;·&nbsp; Spiel ' + (data.played + 1) + ' von ' + data.total;

    // Statistik-Sidebar aktualisieren
    updateDuelStats(data.duel_result, data.winner_context, data.comm_ranks);

    // Nächstes Duell laden – Cover vorab in Browser-Cache laden
    if (data.nextMatch) {
        preloadPosters(data.nextMatch.a, data.nextMatch.b);
        setCard('movie-a', data.nextMatch.a, data.nextMatch.b, data.nextMatch.id);
        setCard('movie-b', data.nextMatch.b, data.nextMatch.a, data.nextMatch.id);
    }

    // Meine Rangliste aktualisieren
    updatePosRankingList(data.posRanking);
    if (data.nextMatch) setTimeout(() => scrollPosRankingToFilm(data.nextMatch.a.id), 80);
    updateHdrCounters(data.hdrDuels, data.hdrFilms);

    // Undo-Button aktivieren
    const undoBtn = document.getElementById('undo-btn');
    if (undoBtn) undoBtn.disabled = false;

    voting = false;

    // Zwischen-Runden-Overlay anzeigen wenn Runde soeben abgeschlossen
    if (data.roundSummary && !data.done) {
        showRoundSummary(data.roundSummary, data.posRanking);
    }
}

function castUndo() {
    if (voting) return;
    const btn = document.getElementById('undo-btn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:.9rem;height:.9rem;border-width:.1em;"></span>';
    }

    const fd = new FormData();
    fd.append('action',     'undo');
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('/turnier.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(function (data) {
            if (data.error) {
                if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i>'; }
                return;
            }

            playedTotal = data.playedTotal;

            // Fortschritt aktualisieren
            const pct = data.totalMatches > 0 ? Math.round(playedTotal / data.totalMatches * 100) : 0;
            const bar = document.getElementById('progress-bar');
            const txt = document.getElementById('progress-text');
            const lbl = document.getElementById('round-label');
            if (bar) bar.style.width = pct + '%';
            if (txt) txt.textContent = playedTotal + ' / ' + data.totalMatches + ' gesamt';
            if (lbl) lbl.innerHTML =
                '<i class="bi bi-diagram-3 me-1"></i>Runde ' + data.round + ' von ' + data.totalRounds +
                ' &nbsp;·&nbsp; Spiel ' + (data.played + 1) + ' von ' + data.total;

            // Ranking aktualisieren
            const list = document.getElementById('ranking-list');
            if (list && data.ranking) {
                list.innerHTML = data.ranking.map(function (f) {
                    const top    = f.rank <= 3 ? ' top' : '';
                    const imgSrc = f.display_poster || (f.poster_path ? TMDB_W92 + f.poster_path : PLACEHOLDER);
                    return '<div class="turnier-rank-row" data-film-id="' + f.id + '">'
                        + '<span class="turnier-rank-num' + top + '">' + f.rank + '</span>'
                        + '<img src="' + imgSrc + '" class="turnier-rank-poster" width="26" height="39"'
                        + ' onerror="this.src=\'' + PLACEHOLDER + '\'">'
                        + '<div class="turnier-rank-title">' + escHtml(f.title) + '</div>'
                        + '<span class="turnier-points">' + f.points
                        + '<i class="bi bi-star-fill text-gold ms-1" style="font-size:.6rem"></i></span>'
                        + '</div>';
                }).join('');
            }

            // Duell wiederherstellen
            setCard('movie-a', data.match.a, data.match.b, data.match.id);
            setCard('movie-b', data.match.b, data.match.a, data.match.id);

            // Meine Rangliste aktualisieren
            updatePosRankingList(data.posRanking);
            setTimeout(() => scrollPosRankingToFilm(data.match.a.id), 80);

            if (btn) {
                btn.disabled = !data.canUndo;
                btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i>';
            }
        })
        .catch(function () {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i>';
            }
        });
}

function scrollPosRankingToFilm(filmId) {
    const posList = document.getElementById('pos-ranking-list');
    if (!posList || !filmId) return;
    posList.querySelectorAll('.next-duel-film').forEach(r => r.classList.remove('next-duel-film'));
    const row = posList.querySelector('[data-film-id="' + filmId + '"]');
    if (row) {
        row.classList.add('next-duel-film');
        // Scroll only within the sidebar container — never scroll the page
        const listH   = posList.clientHeight;
        const rowTop  = row.offsetTop - posList.offsetTop;
        const target  = rowTop - listH / 2 + row.offsetHeight / 2;
        posList.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
    }
    // Update sticky position badge
    const badge = document.getElementById('pos-current-film');
    const numEl = document.getElementById('pos-current-num');
    const ttlEl = document.getElementById('pos-current-title');
    if (badge) {
        const pos   = row?.querySelector('.turnier-rank-num')?.textContent?.trim() || '–';
        const title = row?.querySelector('.turnier-rank-title')?.textContent?.trim() || '';
        if (numEl) numEl.textContent = '#' + pos;
        if (ttlEl) ttlEl.textContent = title;
        badge.style.display = row ? 'flex' : 'none';
    }
}

function updatePosRankingList(posRanking) {
    const posList = document.getElementById('pos-ranking-list');
    const col     = document.getElementById('pos-ranking-col');
    if (!posList || !posRanking) return;
    if (posRanking.length === 0) {
        if (col) col.style.display = 'none';
        return;
    }
    if (col) col.style.display = '';
    posList.innerHTML = posRanking.map(function (f) {
        const top    = f.pos <= 3 ? ' top' : '';
        const imgSrc = f.display_poster || (f.poster_path ? TMDB_W92 + f.poster_path : PLACEHOLDER);
        return '<div class="turnier-rank-row" data-film-id="' + f.id + '">'
            + '<span class="turnier-rank-num' + top + '">' + f.pos + '</span>'
            + '<img src="' + imgSrc + '" class="turnier-rank-poster" width="26" height="39"'
            + ' onerror="this.src=\'' + PLACEHOLDER + '\'">'
            + '<div class="turnier-rank-title">' + escHtml(f.title) + '</div>'
            + '</div>';
    }).join('');
    // Update footer link count
    const link = posList.closest('.turnier-ranking-wrap')?.nextElementSibling?.querySelector('a');
    if (link) link.textContent = 'Alle ' + posRanking.length + ' Filme anzeigen →';
}

const PLACEHOLDER_LG = 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?';

const IMDB_RE = /^tt\d{7,}$/;

function duelPosterSrc(film) {
    if (film.display_poster) return film.display_poster.replace('/w92', '/w500');
    if (film.poster_path) return TMDB_W500 + film.poster_path;
    if (film.imdb_id && IMDB_RE.test(film.imdb_id)) return '/Cover/' + film.imdb_id + '.jpg';
    return PLACEHOLDER_LG;
}

// Globales Array – verhindert Garbage Collection vor Download-Abschluss
const _preloadCache = [];
function preloadPosters(filmA, filmB) {
    [filmA, filmB].forEach(function(f) {
        if (!f) return;
        var img = new Image();
        img.src = duelPosterSrc(f);
        _preloadCache.push(img);
        if (_preloadCache.length > 20) _preloadCache.shift();
    });
}

function setCard(cardId, film, opponent, matchId) {
    const card = document.getElementById(cardId);
    if (!card) return;
    card.classList.remove('winner-flash', 'loser-flash', 'kb-active');
    card.dataset.id            = film.id;
    card.dataset.opponent      = opponent.id;
    card.dataset.match         = matchId;
    card.dataset.overview      = film.overview || '';
    card.dataset.overviewTitle = film.title || '';

    const img   = card.querySelector('.duel-poster');
    const title = card.querySelector('.duel-title');
    const meta  = card.querySelector('.duel-meta');

    if (img) {
        const newSrc    = duelPosterSrc(film);
        // img.src liefert immer absolute URL – Vergleich normalisieren
        const absNewSrc = newSrc.startsWith('http') ? newSrc : (window.location.origin + newSrc);
        if (img.src !== absNewSrc) {
            img.style.opacity = '0';
            img.onload  = function () { img.style.opacity = '1'; };
            img.onerror = function () {
                // TMDB-Fehler → validiertes lokales Cover versuchen
                const safeId = film.imdb_id && IMDB_RE.test(film.imdb_id) ? film.imdb_id : null;
                if (safeId && this.src !== window.location.origin + '/Cover/' + safeId + '.jpg') {
                    this.src = '/Cover/' + safeId + '.jpg';
                } else {
                    this.onerror = null;
                    this.src = PLACEHOLDER_LG;
                    this.style.opacity = '1';
                }
            };
            img.src = newSrc;
        }
        img.alt = film.title;
    }
    if (title) title.textContent = film.title;
    if (meta)  meta.innerHTML    = '<span class="badge bg-dark">' + escHtml(String(film.year)) + '</span>';
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Statistik-Sidebar ────────────────────────────────────────────────────────
const RANK_SFX = <?= json_encode($rankSfx) ?>;

function updateDuelStats(duelResult, winnerContext, commRanks) {
    const lastSec = document.getElementById('last-duel-stat');
    if (lastSec && duelResult) {
        const wT = duelResult.winner_title, lT = duelResult.loser_title;
        const wP = duelResult.winner_old_pos, lP = duelResult.loser_old_pos;
        const rankStr = p => p ? ' <span style="opacity:.45;font-size:.78rem;">(#' + p + ')</span>' : '';
        let html;
        if (duelResult.rank_changed) {
            html = '<div class="duel-stat-result">'
                 + '<span class="rank-up">' + escHtml(wT) + '</span>' + rankStr(wP)
                 + ' übernimmt Platz von <strong>' + escHtml(lT) + '</strong>' + rankStr(lP)
                 + '</div>';
        } else {
            html = '<div class="duel-stat-result">'
                 + '<strong>' + escHtml(wT) + '</strong>' + rankStr(wP)
                 + ' besiegt <strong>' + escHtml(lT) + '</strong>' + rankStr(lP)
                 + '<span class="no-change">Rangliste unverändert</span>'
                 + '</div>';
        }
        lastSec.innerHTML = '<div class="duel-stat-lbl">Letztes Duell</div>' + html;
    }
    if (winnerContext) {
        renderContextSection('comm-context-stat', 'Community Rangliste' + RANK_SFX, winnerContext.comm, winnerContext.winner_id);
        renderContextSection('my-context-stat',   'Meine Rangliste' + RANK_SFX,    winnerContext.mine, winnerContext.winner_id);
    }
    const commSec = document.getElementById('comm-rank-stat');
    if (commSec && commRanks) {
        const filmRow = (rank, title) =>
            '<div class="duel-comm-film"><span class="duel-comm-rank">' + (rank ? '#' + rank : '–') + '</span>'
            + '<span class="duel-comm-title">' + escHtml(title) + '</span></div>';
        commSec.innerHTML = '<div class="duel-stat-lbl">Community Ranking' + RANK_SFX + '</div>'
            + filmRow(commRanks.a_rank, commRanks.a_title)
            + filmRow(commRanks.b_rank, commRanks.b_title);
    }
    const myRankSec = document.getElementById('my-rank-stat');
    if (myRankSec && commRanks) {
        const myStyle = 'background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.15);color:rgba(255,255,255,.7);';
        const myRow = (pos, title) =>
            '<div class="duel-comm-film"><span class="duel-comm-rank" style="' + myStyle + '">' + (pos !== null && pos !== undefined ? '#' + pos : '–') + '</span>'
            + '<span class="duel-comm-title">' + escHtml(title) + '</span></div>';
        myRankSec.innerHTML = '<div class="duel-stat-lbl">Meine Rangliste' + RANK_SFX + '</div>'
            + myRow(commRanks.a_my_pos, commRanks.a_title)
            + myRow(commRanks.b_my_pos, commRanks.b_title);
    }
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
                + '<span class="ctx-title">' + escHtml(r.title) + '</span></div>';
        }).join('');
}

// ── Phase-II-Intro ──────────────────────────────────────────────────────────
window.dismissPhase2Intro = function() {
    var intro = document.getElementById('phase2-intro');
    if (intro) intro.style.display = 'none';
    var startScreen = document.getElementById('turnier-start-screen');
    if (startScreen) startScreen.style.display = '';
};

// ── Zwischen-Runden-Overlay ─────────────────────────────────────────────────
window.showRoundSummary = function(summary, posRanking) {
    var ov = document.getElementById('round-summary-overlay');
    if (!ov) return;

    document.getElementById('rs-round').textContent      = summary.prevRound;
    document.getElementById('rs-last-duels').textContent = summary.lastRoundDuels.toLocaleString('de-DE');
    document.getElementById('rs-total-duels').textContent= summary.totalDuels.toLocaleString('de-DE');
    document.getElementById('rs-next-duels').textContent = summary.nextRoundDuels.toLocaleString('de-DE');
    document.getElementById('rs-next-round-num').textContent = summary.prevRound + 1;

    // Top 10 Meine Rangliste
    var top10 = document.getElementById('rs-top10');
    var top10rows = (posRanking || []).slice(0, 10);
    top10.innerHTML = top10rows.length ? top10rows.map(function(r, i) {
        var img = r.display_poster || (r.poster_path ? 'https://image.tmdb.org/t/p/w92' + r.poster_path : 'https://placehold.co/26x39/1e3a5f/e8b84b?text=?');
        return '<div style="display:flex;align-items:center;gap:.6rem;padding:.4rem .75rem;border-bottom:1px solid rgba(255,255,255,.05);">'
            + '<span style="min-width:1.5rem;font-weight:700;color:' + (i<3?'#e8b84b':'rgba(255,255,255,.4)') + ';">' + (i+1) + '</span>'
            + '<img src="' + img + '" width="26" height="39" style="border-radius:3px;object-fit:cover;" loading="lazy">'
            + '<span style="font-size:.85rem;color:#e0e0e0;">' + escHtml(r.title) + '</span>'
            + '</div>';
    }).join('') : '<div style="padding:1rem;text-align:center;opacity:.5;">Noch keine Rangliste verfügbar</div>';

    // Duelle mit niedrigsten Film-IDs
    var lowest = document.getElementById('rs-lowest');
    lowest.innerHTML = (summary.lowestFilms || []).map(function(duel) {
        function filmHtml(f, isWinner) {
            var img = f.display_poster || (f.poster_path ? 'https://image.tmdb.org/t/p/w92' + f.poster_path : 'https://placehold.co/26x39/1e3a5f/e8b84b?text=?');
            return '<div style="display:flex;align-items:center;gap:.4rem;flex:1;min-width:0;'
                + (isWinner ? '' : 'opacity:.45;') + '">'
                + '<img src="' + img + '" width="22" height="33" style="border-radius:3px;object-fit:cover;flex-shrink:0;" loading="lazy">'
                + '<span style="font-size:.8rem;color:' + (isWinner ? '#e8b84b' : '#e0e0e0') + ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
                + (isWinner ? '<i class="bi bi-trophy-fill me-1" style="font-size:.65rem;"></i>' : '')
                + escHtml(f.title) + ' <span style="opacity:.5;font-size:.72rem;">(' + f.year + ')</span></span>'
                + '<span style="font-size:.65rem;color:rgba(255,255,255,.25);flex-shrink:0;">ID&nbsp;' + f.id + '</span>'
                + '</div>';
        }
        var aWon = duel.winner_id === duel.a.id;
        return '<div style="display:flex;align-items:center;gap:.5rem;padding:.45rem .75rem;border-bottom:1px solid rgba(255,255,255,.05);">'
            + filmHtml(duel.a, aWon)
            + '<span style="font-size:.7rem;font-weight:700;color:rgba(255,255,255,.3);flex-shrink:0;">VS</span>'
            + filmHtml(duel.b, !aWon)
            + '</div>';
    }).join('');

    ov.style.display = 'block';
    ov.scrollTop = 0;
};

window.dismissRoundSummary = function() {
    var ov = document.getElementById('round-summary-overlay');
    if (ov) ov.style.display = 'none';
};

// syncH removed – sidebar height is now controlled by CSS (position:sticky + max-height:calc(100vh-...))

// ── Initial scroll to left film in Meine Rangliste ───────────────────────────
(function () {
    var a = document.getElementById('movie-a');
    if (a) setTimeout(function () { scrollPosRankingToFilm(parseInt(a.dataset.id)); }, 150);
}());
</script>
<?php endif; ?>

<script>
const DB_FILM_COUNT = <?= $dbFilmCount ?>;

// Startbutton Ladeindikator
const sf = document.getElementById('start-form');
if (sf) sf.addEventListener('submit', function (e) {
    const clicked = e.submitter;
    document.querySelectorAll('.start-btn').forEach(b => { b.disabled = true; });
    if (clicked) clicked.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Wird erstellt…';
});

function selectFilmCount(n, btn) {
    const actual = Math.min(n, DB_FILM_COUNT);
    const byes   = n - actual;
    const rounds = Math.round(Math.log2(n));

    function fmt(x) { return x.toLocaleString('en-US'); }
    function el(id)  { return document.getElementById(id); }

    // Update hidden input
    const inp = el('film-count-input');
    if (inp) inp.value = n;

    // Stat boxes
    if (el('stat-filme'))  el('stat-filme').textContent  = fmt(actual);
    if (el('stat-runden')) el('stat-runden').textContent = rounds;
    if (el('stat-duelle')) el('stat-duelle').textContent = fmt(actual - 1);
    if (el('stat-desc-n')) el('stat-desc-n').textContent = fmt(actual);

    // Freilos row
    const freilosRow = el('bp-freilos');
    if (freilosRow) freilosRow.style.display = byes > 0 ? '' : 'none';
    if (byes > 0 && el('bp-freilos-range'))
        el('bp-freilos-range').textContent = 'Seed 1–' + fmt(byes);

    // Seed labels (left side of match rows)
    if (el('bp-seed1-label')) el('bp-seed1-label').textContent = byes > 0 ? 'Seed ' + fmt(byes + 1) : 'Seed 1';
    if (el('bp-seed2-label')) el('bp-seed2-label').textContent = byes > 0 ? 'Seed ' + fmt(byes + 2) : 'Seed 2';

    // Right-side seeds and middle match
    if (el('bp-top'))  el('bp-top').textContent  = 'Seed ' + fmt(actual);
    if (el('bp-top2')) el('bp-top2').textContent = 'Seed ' + fmt(actual - 1);
    if (el('bp-mid'))  el('bp-mid').textContent  = 'Seed ' + fmt((byes + actual) / 2);
    if (el('bp-mid2')) el('bp-mid2').textContent = 'Seed ' + fmt((byes + actual) / 2 + 1);

    // Active button highlight
    document.querySelectorAll('.btn-count').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
}

</script>

<style>
/* ── Turnier-Seite ─────────────────────────────────────────────────────────── */
.container-xxl.turnier-wrap { max-width: 1900px; margin: 0 auto; }
.turnier-page .vs-divider { display: flex; flex-direction: column; align-items: center; align-self: stretch; justify-content: center; padding-bottom: 2.5rem; }
.turnier-page .vs-divider #undo-btn { margin-top: auto; }

/* Liga-3col Layout (analog jgj.php / liga.php) */
.turnier-page .liga-3col   { display: flex; gap: 20px; align-items: flex-start; padding: 0 5%; }
.turnier-page .liga-side   { flex: 0 0 420px; min-width: 0; display: flex; flex-direction: column; overflow: hidden;
                              position: sticky; top: 80px; align-self: flex-start;
                              max-height: calc(100vh - 180px); }
.turnier-page .liga-center { flex: 1; min-width: 0; }
.turnier-page .liga-side .turnier-ranking-wrap { flex: 1; display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
.turnier-page .liga-side .turnier-ranking-list { flex: 1; min-height: 0; overflow-y: auto; }
.turnier-page .duel-poster-wrap { max-width: 600px !important; }

/* Tablet 768 – 1400 px: rechte Sidebar weg, linke schmaler */
@media (max-width: 1400px) and (min-width: 768px) {
    .turnier-page .liga-3col { padding: 0 2%; gap: 12px; }
    .turnier-page .liga-side:first-child { flex: 0 0 320px; }
    .turnier-page .liga-side:last-child  { display: none !important; }
    .turnier-page .liga-side .turnier-ranking-list { max-height: 60vh; }
    .turnier-page .duel-poster-wrap { max-width: min(600px, 27vh) !important; }
}

/* Mobile < 768 px: Sidebars ausblenden, Duel zentriert */
@media (max-width: 767px) {
    .turnier-page .liga-side   { display: none !important; }
    .turnier-page .liga-center { flex: 0 0 100%; }
    .turnier-page .duel-poster-wrap { max-width: 600px !important; }
    .turnier-page .container-xxl { padding-left: .5rem; padding-right: .5rem; }
}

/* Mobile Querformat: Cover vollständig sichtbar */
@media (max-width: 900px) and (orientation: landscape) {
    .turnier-page .liga-side { display: none !important; }
    .turnier-page .duel-poster-wrap { max-width: none !important; display: flex; justify-content: center; }
    .turnier-page .duel-poster { max-height: calc(100dvh - 180px) !important; width: auto !important; object-fit: contain !important; }
}

.turnier-page {
    background: var(--mkfb-navy);
    color: #fff;
    min-height: 85vh;
}

.turnier-page .duel-container {
    min-height: auto;
    max-width: none;
}

.turnier-intro-card {
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 16px;
    padding: 2.5rem 2rem;
    color: #fff;
}

.turnier-stat-box {
    background: rgba(232,184,75,.08);
    border: 1px solid rgba(232,184,75,.2);
    border-radius: 12px;
    padding: 1rem;
    text-align: center;
}
.turnier-stat-val { font-size: 1.75rem; font-weight: 800; line-height: 1; }
.turnier-stat-lbl { font-size: .8rem; opacity: .6; margin-top: .25rem; }

.turnier-bracket-preview {
    background: rgba(0,0,0,.2);
    border-radius: 10px;
    padding: 1rem 1.25rem;
    font-size: .875rem;
    color: rgba(255,255,255,.7);
}
.turnier-bracket-preview .brow {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: .3rem 0;
    border-bottom: 1px solid rgba(255,255,255,.06);
}
.turnier-bracket-preview .brow:last-child { border-bottom: none; }
.turnier-bracket-preview .vs {
    color: var(--mkfb-gold);
    font-weight: 700;
    font-size: .75rem;
}

/* Ranking panel */
/* ── Statistik-Sidebar (wie JgJ) ──────────────────────────────────────── */
.duel-stat-section { padding: .65rem .9rem; border-bottom: 1px solid rgba(255,255,255,.05); }
.duel-stat-section:last-child { border-bottom: none; }
.duel-stat-lbl { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: rgba(232,184,75,.65); margin-bottom: .45rem; }
.duel-stat-result { font-size: .82rem; line-height: 1.5; color: #ccc; }
.duel-stat-result .rank-up { color: #5cb85c; font-weight: 700; }
.duel-stat-result .no-change { opacity: .5; font-size: .75rem; display: block; margin-top: .15rem; }
.duel-stat-empty { font-size: .78rem; color: rgba(255,255,255,.3); }
.duel-comm-film { display: flex; align-items: center; gap: .45rem; padding: .2rem 0; font-size: .82rem; color: #ccc; overflow: hidden; }
.duel-comm-rank { background: rgba(232,184,75,.12); border: 1px solid rgba(232,184,75,.25); border-radius: 4px; padding: 0 5px; font-size: .72rem; font-weight: 700; color: #e8b84b; flex-shrink: 0; min-width: 28px; text-align: center; }
.duel-comm-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ctx-row { display: flex; align-items: center; gap: .45rem; padding: .18rem 0; font-size: .8rem; color: #bbb; overflow: hidden; }
.ctx-row.is-winner { color: #fff; font-weight: 700; background: rgba(232,184,75,.08); border-radius: 4px; padding: .18rem .35rem; margin: 0 -.35rem; }
.ctx-pos { min-width: 28px; text-align: right; font-size: .72rem; font-weight: 700; flex-shrink: 0; opacity: .5; }
.ctx-row.is-winner .ctx-pos { opacity: 1; color: #e8b84b; }
.ctx-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.turnier-ranking-wrap {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 12px;
    overflow: hidden;
}
.turnier-ranking-header {
    padding: .75rem 1rem;
    font-size: .8rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--mkfb-gold);
    border-bottom: 1px solid rgba(255,255,255,.08);
    background: rgba(232,184,75,.06);
}
.turnier-ranking-list {
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(232,184,75,.2) transparent;
}
.turnier-ranking-list::-webkit-scrollbar { width: 4px; }
.turnier-ranking-list::-webkit-scrollbar-track { background: transparent; }
.turnier-ranking-list::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 2px; }
.turnier-ranking-list::-webkit-scrollbar-thumb:hover { background: rgba(232,184,75,.4); }
.turnier-rank-row {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .4rem .75rem;
    border-bottom: 1px solid rgba(255,255,255,.05);
    transition: background .15s;
}
.turnier-rank-row:hover { background: rgba(255,255,255,.04); }
.turnier-rank-row.next-duel-film { background: rgba(232,184,75,.12); border-left: 3px solid #e8b84b; }
.turnier-rank-num {
    min-width: 1.6rem;
    font-size: .75rem;
    font-weight: 700;
    color: rgba(255,255,255,.4);
    text-align: right;
}
.turnier-rank-num.top { color: var(--mkfb-gold); }
.turnier-rank-poster {
    width: 26px;
    height: 39px;
    object-fit: cover;
    border-radius: 3px;
    flex-shrink: 0;
}
.turnier-rank-title {
    flex: 1;
    font-size: .8rem;
    color: rgba(255,255,255,.85);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.btn-count {
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.15);
    color: rgba(255,255,255,.7);
    border-radius: 8px;
    padding: .3rem .75rem;
    font-size: .85rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s, border-color .15s, color .15s;
    line-height: 1.5;
}
.btn-count:hover {
    background: rgba(232,184,75,.15);
    border-color: rgba(232,184,75,.4);
    color: #fff;
}
.btn-count.active {
    background: rgba(232,184,75,.2);
    border-color: var(--mkfb-gold);
    color: var(--mkfb-gold);
}

.turnier-points {
    font-size: .75rem;
    font-weight: 700;
    color: rgba(255,255,255,.6);
    white-space: nowrap;
}

/* ── Turnier Filter ─────────────────────────────────────────────────────────── */
#turnier-filters { max-width: 520px; margin: 0 auto 1rem; }
.turnier-filter-input {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.15);
    color: #fff;
    font-size: .85rem;
}
.turnier-filter-input::placeholder { color: rgba(255,255,255,.35); }
.turnier-filter-input:focus {
    background: rgba(255,255,255,.1);
    border-color: rgba(232,184,75,.5);
    box-shadow: none;
    color: #fff;
}
select.turnier-filter-input option { background: #1e1e2e; color: #fff; }
.turnier-suggest-list {
    position: absolute;
    z-index: 999;
    left: 0; right: 0;
    top: 100%;
    margin: 0;
    padding: 0;
    list-style: none;
    background: #1e1e2e;
    border: 1px solid rgba(232,184,75,.25);
    border-radius: 0 0 6px 6px;
    max-height: 200px;
    overflow-y: auto;
    display: none;
}
.turnier-suggest-list.open { display: block; }
.turnier-suggest-list li {
    padding: .35rem .75rem;
    font-size: .82rem;
    color: rgba(255,255,255,.85);
    cursor: pointer;
}
.turnier-suggest-list li:hover,
.turnier-suggest-list li.active { background: rgba(232,184,75,.15); color: #e8b84b; }
</style>

<script>
(function() {
    // Autocomplete text fields (director, actor)
    var acFields = {
        'tf_director': {sug:'sug_director', hidden:'filter_director', api:'director'},
        'tf_actor':    {sug:'sug_actor',    hidden:'filter_actor',    api:'actors'}
    };
    // Simple select fields (genre, country)
    var selFields = {
        'tf_genre':   'filter_genre',
        'tf_country': 'filter_country'
    };

    var activeIdx = {};

    Object.entries(acFields).forEach(function([id, cfg]) {
        var inp = document.getElementById(id);
        var sug = document.getElementById(cfg.sug);
        if (!inp || !sug) return;
        var timer = null;
        activeIdx[id] = -1;

        inp.addEventListener('input', function() {
            clearTimeout(timer);
            var q = inp.value.trim();
            if (q.length < 1) { sug.innerHTML=''; sug.classList.remove('open'); return; }
            timer = setTimeout(function() {
                fetch('/turnier.php?action=suggest&field=' + cfg.api + '&q=' + encodeURIComponent(q))
                    .then(function(r){ return r.json(); })
                    .then(function(arr) {
                        sug.innerHTML = '';
                        activeIdx[id] = -1;
                        if (!arr.length) { sug.classList.remove('open'); return; }
                        arr.forEach(function(val) {
                            var li = document.createElement('li');
                            li.textContent = val;
                            li.addEventListener('mousedown', function(e) {
                                e.preventDefault();
                                inp.value = val;
                                sug.innerHTML=''; sug.classList.remove('open');
                            });
                            sug.appendChild(li);
                        });
                        sug.classList.add('open');
                    }).catch(function(){});
            }, 200);
        });

        inp.addEventListener('keydown', function(e) {
            var items = sug.querySelectorAll('li');
            if (!items.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIdx[id] = Math.min(activeIdx[id]+1, items.length-1);
                items.forEach(function(li,i){ li.classList.toggle('active', i===activeIdx[id]); });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIdx[id] = Math.max(activeIdx[id]-1, -1);
                items.forEach(function(li,i){ li.classList.toggle('active', i===activeIdx[id]); });
            } else if (e.key === 'Enter') {
                if (activeIdx[id] >= 0 && items[activeIdx[id]]) {
                    e.preventDefault();
                    inp.value = items[activeIdx[id]].textContent;
                    sug.innerHTML=''; sug.classList.remove('open');
                }
            } else if (e.key === 'Escape') {
                sug.innerHTML=''; sug.classList.remove('open');
            }
        });

        inp.addEventListener('blur', function() {
            setTimeout(function(){ sug.innerHTML=''; sug.classList.remove('open'); }, 150);
        });
    });

    window.syncFilters = function() {
        // Select-based comboboxes
        Object.entries(selFields).forEach(function([id, hid]) {
            var sel = document.getElementById(id);
            var hel = document.getElementById(hid);
            if (sel && hel) hel.value = sel.value;
        });
        // Autocomplete text fields
        Object.entries(acFields).forEach(function([id, cfg]) {
            var inp = document.getElementById(id);
            var hel = document.getElementById(cfg.hidden);
            if (inp && hel) hel.value = inp.value.trim();
        });
    };
})();
</script>

<script>
// Suppress any navigation confirmation dialogs (beforeunload + nav-link confirms)
window.onbeforeunload = null;
document.querySelectorAll('nav a, .navbar a, .dropdown-item').forEach(function(a) {
    a.addEventListener('click', function() { window.onbeforeunload = null; }, true);
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
