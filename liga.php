<?php
set_time_limit(300);
$pageTitle = 'Jeder gegen Jeden – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// Stellt sicher, dass der besser-platzierte Film immer auf der linken (A-)Seite steht.
// $posMap: [movie_id => position, ...] (kleinere Position = besser)
function sortMatchSideByPos(array &$match, array $posMap): void {
    $posA = $posMap[(int)$match['movie_a_id']] ?? PHP_INT_MAX;
    $posB = $posMap[(int)$match['movie_b_id']] ?? PHP_INT_MAX;
    if ($posB < $posA) {
        foreach (['title', 'title_en', 'year', 'poster', 'poster_en'] as $k) {
            [$match["a_$k"], $match["b_$k"]] = [$match["b_$k"], $match["a_$k"]];
        }
        [$match['movie_a_id'], $match['movie_b_id']] = [$match['movie_b_id'], $match['movie_a_id']];
    }
}

// ── Tabellen sicherstellen ────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS liga_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    film_count SMALLINT UNSIGNED NOT NULL,
    status     ENUM('active','completed') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
// Migration: separate movie vs. series liga sessions
$db->exec("ALTER TABLE liga_sessions ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NOT NULL DEFAULT 'movie'");

$db->exec("CREATE TABLE IF NOT EXISTS liga_matches (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    liga_id    INT UNSIGNED NOT NULL,
    movie_a_id INT UNSIGNED NOT NULL,
    movie_b_id INT UNSIGNED NOT NULL,
    winner_id  INT UNSIGNED NULL,
    voted_at   TIMESTAMP NULL,
    INDEX      idx_liga_pending (liga_id, winner_id),
    UNIQUE KEY uq_pair          (liga_id, movie_a_id, movie_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── AJAX-Handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Liga abbrechen (Form-POST → Redirect) ─────────────────────────────────
    if ($action === 'abort' && csrfValid()) {
        $stmt = $db->prepare("SELECT id FROM liga_sessions WHERE user_id = ? AND status = 'active' AND media_type = ? LIMIT 1");
        $stmt->execute([$userId, activeMtForDb()]);
        $liga = $stmt->fetch();
        if ($liga) {
            $lid = (int)$liga['id'];
            $db->prepare("DELETE FROM liga_matches  WHERE liga_id = ?")->execute([$lid]);
            $db->prepare("DELETE FROM liga_sessions WHERE id = ?")->execute([$lid]);
        }
        header('Location: /liga.php');
        exit;
    }

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── Liga starten ─────────────────────────────────────────────────────────
    if ($action === 'start') {
        if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

        $filmCount = (int)($_POST['film_count'] ?? 0);

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM user_position_ranking upr
             JOIN movies m ON m.id = upr.movie_id
             WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m')
        );
        $stmt->execute([$userId]);
        $rankedCount = (int)$stmt->fetchColumn();

        if ($filmCount < 10 || $filmCount > $rankedCount) {
            echo json_encode(['ok' => false, 'error' => 'invalid_count']); exit;
        }

        // Filter auslesen
        $fGenre    = trim($_POST['filter_genre']    ?? '');
        $fCountry  = trim($_POST['filter_country']  ?? '');
        $fDirector = trim($_POST['filter_director'] ?? '');
        $fActor    = trim($_POST['filter_actor']    ?? '');
        $hasFilter = $fGenre !== '' || $fCountry !== '' || $fDirector !== '' || $fActor !== '';

        // Filme VOR Session-Erstellung holen — damit kein leere Session entsteht
        if ($hasFilter) {
            $filterWhere  = ' WHERE 1=1';
            $filterValues = [];
            if ($fGenre    !== '') { $filterWhere .= ' AND m.genre    LIKE ?'; $filterValues[] = '%' . $fGenre    . '%'; }
            if ($fCountry  !== '') { $filterWhere .= ' AND m.country  LIKE ?'; $filterValues[] = '%' . $fCountry  . '%'; }
            if ($fDirector !== '') { $filterWhere .= ' AND m.director LIKE ?'; $filterValues[] = '%' . $fDirector . '%'; }
            if ($fActor    !== '') { $filterWhere .= ' AND m.actors   LIKE ?'; $filterValues[] = '%' . $fActor    . '%'; }
            $filterWhere .= seriesSqlFilter('m') . moviesSqlFilter('m');
            $params = array_merge([$userId], $filterValues, [$filmCount]);
            $stmt = $db->prepare("
                SELECT m.id FROM movies m
                LEFT JOIN user_position_ranking upr ON upr.movie_id = m.id AND upr.user_id = ?
                $filterWhere
                ORDER BY CASE WHEN upr.position IS NOT NULL THEN 0 ELSE 1 END ASC,
                         upr.position ASC, m.title ASC
                LIMIT ?");
            $stmt->execute($params);
        } else {
            $stmt = $db->prepare(
                "SELECT upr.movie_id FROM user_position_ranking upr
                 JOIN movies m ON m.id = upr.movie_id
                 WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') .
                " ORDER BY upr.position ASC LIMIT ?"
            );
            $stmt->execute([$userId, $filmCount]);
        }
        $films = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Abbruch VOR Session-Erstellung — keine verwaiste Session
        if (count($films) < 2) {
            echo json_encode(['ok' => false, 'error' => 'no_films']); exit;
        }

        // Bestehende aktive Session abschließen
        $db->prepare("UPDATE liga_sessions SET status = 'completed' WHERE user_id = ? AND status = 'active' AND media_type = ?")
           ->execute([$userId, activeMtForDb()]);

        // Neue Session anlegen
        $db->prepare("INSERT INTO liga_sessions (user_id, film_count, media_type) VALUES (?, ?, ?)")
           ->execute([$userId, $filmCount, activeMtForDb()]);
        $ligaId = (int)$db->lastInsertId();

        // Alle N*(N-1)/2 Paare in Chunks à 500 batch-einfügen
        $db->beginTransaction();
        $chunk     = [];
        $chunkSize = 500;
        $n         = count($films);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $chunk[] = $ligaId;
                $chunk[] = (int)$films[$i];
                $chunk[] = (int)$films[$j];
                if (count($chunk) >= $chunkSize * 3) {
                    $cnt = count($chunk) / 3;
                    $ph  = implode(',', array_fill(0, $cnt, '(?,?,?)'));
                    $db->prepare("INSERT INTO liga_matches (liga_id, movie_a_id, movie_b_id) VALUES $ph")->execute($chunk);
                    $chunk = [];
                }
            }
        }
        if (!empty($chunk)) {
            $cnt = count($chunk) / 3;
            $ph  = implode(',', array_fill(0, $cnt, '(?,?,?)'));
            $db->prepare("INSERT INTO liga_matches (liga_id, movie_a_id, movie_b_id) VALUES $ph")->execute($chunk);
        }
        $db->commit();

        echo json_encode(['ok' => true, 'liga_id' => $ligaId]);
        exit;
    }

    // ── Duell abstimmen ──────────────────────────────────────────────────────
    if ($action === 'vote') {
        if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

        $ligaId   = (int)($_POST['liga_id']   ?? 0);
        $matchId  = (int)($_POST['match_id']  ?? 0);
        $winnerId = (int)($_POST['winner_id'] ?? 0);
        $loserId  = (int)($_POST['loser_id']  ?? 0);

        // Session-Eigentümerschaft + aktiver Status
        $stmt = $db->prepare("SELECT id FROM liga_sessions WHERE id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$ligaId, $userId]);
        if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'invalid']); exit; }

        // Match-Validierung (Paar in beliebiger Reihenfolge)
        $stmt = $db->prepare("
            SELECT id FROM liga_matches
            WHERE id = ? AND liga_id = ? AND winner_id IS NULL
              AND ((movie_a_id = ? AND movie_b_id = ?) OR (movie_a_id = ? AND movie_b_id = ?))
        ");
        $stmt->execute([$matchId, $ligaId, $winnerId, $loserId, $loserId, $winnerId]);
        if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'invalid_match']); exit; }

        // Abstimmung speichern
        $db->prepare("UPDATE liga_matches SET winner_id = ?, voted_at = NOW() WHERE id = ?")
           ->execute([$winnerId, $matchId]);

        // ELO + Positionsrangliste aktualisieren
        recordComparison($userId, $winnerId, $loserId);

        // Fortschritt prüfen
        $stmt = $db->prepare("SELECT COUNT(*) FROM liga_matches WHERE liga_id = ?");
        $stmt->execute([$ligaId]);
        $total = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM liga_matches WHERE liga_id = ? AND winner_id IS NULL");
        $stmt->execute([$ligaId]);
        $remaining = (int)$stmt->fetchColumn();
        $done      = $total - $remaining;

        $counters = getActivityCounters($userId);

        if ($remaining === 0) {
            $db->prepare("UPDATE liga_sessions SET status = 'completed' WHERE id = ?")
               ->execute([$ligaId]);
            echo json_encode([
                'ok'        => true,
                'completed' => true,
                'progress'  => ['done' => $done, 'total' => $total],
                'hdrDuels'  => $counters['totalDuels'],
                'hdrFilms'  => $counters['uniqueFilms'],
            ]);
            exit;
        }

        // Nächstes Match – zufällig aus bis zu 50 offenen Matches (kein ORDER BY RAND())
        $stmt = $db->prepare("
            SELECT lm.id, lm.movie_a_id, lm.movie_b_id,
                   ma.title AS a_title, ma.title_en AS a_title_en, ma.year AS a_year, ma.poster_path AS a_poster, ma.poster_path_en AS a_poster_en,
                   mb.title AS b_title, mb.title_en AS b_title_en, mb.year AS b_year, mb.poster_path AS b_poster, mb.poster_path_en AS b_poster_en
            FROM liga_matches lm
            JOIN movies ma ON ma.id = lm.movie_a_id
            JOIN movies mb ON mb.id = lm.movie_b_id
            WHERE lm.liga_id = ? AND lm.winner_id IS NULL
            ORDER BY lm.id ASC
            LIMIT 50
        ");
        $stmt->execute([$ligaId]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $next = $candidates ? $candidates[array_rand($candidates)] : null;

        // Aktualisierte Positions-Rangliste (max. 200 Einträge für Performance)
        $sr = $db->prepare("SELECT upr.position, m.id, m.title, m.title_en, m.poster_path, m.poster_path_en
            FROM user_position_ranking upr JOIN movies m ON m.id = upr.movie_id
            WHERE upr.user_id = ? ORDER BY upr.position ASC LIMIT 200");
        $sr->execute([$userId]);
        $posRankingUpd = array_map(fn($r) => [
            'id' => (int)$r['id'], 'pos' => (int)$r['position'],
            'title' => movieTitle($r), 'poster_path' => $r['poster_path'],
            'display_poster' => moviePosterUrl($r, 'w92'),
        ], $sr->fetchAll(PDO::FETCH_ASSOC));

        // Besser-platzierter Film links anzeigen
        $posMap = array_column($posRankingUpd, 'pos', 'id');
        sortMatchSideByPos($next, $posMap);

        echo json_encode([
            'ok'        => true,
            'completed' => false,
            'progress'  => ['done' => $done, 'total' => $total],
            'posRanking'=> $posRankingUpd,
            'hdrDuels'  => $counters['totalDuels'],
            'hdrFilms'  => $counters['uniqueFilms'],
            'next'      => [
                'id'      => (int)$next['id'],
                'a_id'    => (int)$next['movie_a_id'],
                'a_title' => movieTitle(['title' => $next['a_title'], 'title_en' => $next['a_title_en'] ?? null]),
                'a_year'  => (int)$next['a_year'],
                'a_poster'=> moviePosterUrl(['poster_path' => $next['a_poster'], 'poster_path_en' => $next['a_poster_en'] ?? null]),
                'b_id'    => (int)$next['movie_b_id'],
                'b_title' => movieTitle(['title' => $next['b_title'], 'title_en' => $next['b_title_en'] ?? null]),
                'b_year'  => (int)$next['b_year'],
                'b_poster'=> moviePosterUrl(['poster_path' => $next['b_poster'], 'poster_path_en' => $next['b_poster_en'] ?? null]),
            ],
        ]);
        exit;
    }

    // ── Letztes Duell rückgängig ─────────────────────────────────────────────
    if ($action === 'undo') {
        if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

        $ligaId = (int)($_POST['liga_id'] ?? 0);

        $stmt = $db->prepare("SELECT id FROM liga_sessions WHERE id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$ligaId, $userId]);
        if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'invalid']); exit; }

        $stmt = $db->prepare("
            SELECT lm.id, lm.winner_id,
                   CASE WHEN lm.winner_id = lm.movie_a_id THEN lm.movie_b_id ELSE lm.movie_a_id END AS loser_id,
                   lm.movie_a_id, lm.movie_b_id,
                   ma.title AS a_title, ma.title_en AS a_title_en, ma.year AS a_year, ma.poster_path AS a_poster, ma.poster_path_en AS a_poster_en,
                   mb.title AS b_title, mb.title_en AS b_title_en, mb.year AS b_year, mb.poster_path AS b_poster, mb.poster_path_en AS b_poster_en
            FROM liga_matches lm
            JOIN movies ma ON ma.id = lm.movie_a_id
            JOIN movies mb ON mb.id = lm.movie_b_id
            WHERE lm.liga_id = ? AND lm.winner_id IS NOT NULL
            ORDER BY lm.voted_at DESC, lm.id DESC
            LIMIT 1
        ");
        $stmt->execute([$ligaId]);
        $last = $stmt->fetch();

        if (!$last) { echo json_encode(['ok' => false, 'error' => 'nothing_to_undo']); exit; }

        undoLastComparison($userId, (int)$last['winner_id'], (int)$last['loser_id']);

        $db->prepare("UPDATE liga_matches SET winner_id = NULL, voted_at = NULL WHERE id = ?")
           ->execute([(int)$last['id']]);

        $stmt = $db->prepare("SELECT COUNT(*) FROM liga_matches WHERE liga_id = ?");
        $stmt->execute([$ligaId]);
        $total = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM liga_matches WHERE liga_id = ? AND winner_id IS NULL");
        $stmt->execute([$ligaId]);
        $remaining = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM liga_matches WHERE liga_id = ? AND winner_id IS NOT NULL");
        $stmt->execute([$ligaId]);
        $canUndoMore = (int)$stmt->fetchColumn() > 0;

        // Aktualisierte Positions-Rangliste nach Undo (max. 200 Einträge für Performance)
        $sr = $db->prepare("SELECT upr.position, m.id, m.title, m.title_en, m.poster_path, m.poster_path_en
            FROM user_position_ranking upr JOIN movies m ON m.id = upr.movie_id
            WHERE upr.user_id = ? ORDER BY upr.position ASC LIMIT 200");
        $sr->execute([$userId]);
        $posRankingUpd = array_map(fn($r) => [
            'id' => (int)$r['id'], 'pos' => (int)$r['position'],
            'title' => movieTitle($r), 'poster_path' => $r['poster_path'],
            'display_poster' => moviePosterUrl($r, 'w92'),
        ], $sr->fetchAll(PDO::FETCH_ASSOC));

        // Besser-platzierter Film links anzeigen
        $posMap = array_column($posRankingUpd, 'pos', 'id');
        sortMatchSideByPos($last, $posMap);

        echo json_encode([
            'ok'        => true,
            'progress'  => ['done' => $total - $remaining, 'total' => $total],
            'canUndo'   => $canUndoMore,
            'posRanking'=> $posRankingUpd,
            'restored'  => [
                'id'       => (int)$last['id'],
                'a_id'     => (int)$last['movie_a_id'],
                'a_title'  => movieTitle(['title' => $last['a_title'], 'title_en' => $last['a_title_en'] ?? null]),
                'a_year'   => (int)$last['a_year'],
                'a_poster' => moviePosterUrl(['poster_path' => $last['a_poster'], 'poster_path_en' => $last['a_poster_en'] ?? null]),
                'b_id'     => (int)$last['movie_b_id'],
                'b_title'  => movieTitle(['title' => $last['b_title'], 'title_en' => $last['b_title_en'] ?? null]),
                'b_year'   => (int)$last['b_year'],
                'b_poster' => moviePosterUrl(['poster_path' => $last['b_poster'], 'poster_path_en' => $last['b_poster_en'] ?? null]),
                'winner_id'=> (int)$last['winner_id'],
            ],
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
    exit;
}

// ── Seiten-Zustand ────────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT id, film_count FROM liga_sessions WHERE user_id = ? AND status = 'active' AND media_type = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$userId, activeMtForDb()]);
$activeSession = $stmt->fetch();

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM user_position_ranking upr
     JOIN movies m ON m.id = upr.movie_id
     WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m')
);
$stmt->execute([$userId]);
$rankedCount = (int)$stmt->fetchColumn();

// Preload genres/countries for filter dropdowns
function ligaLoadDistinct(PDO $db, string $col): array {
    $rows = $db->query("SELECT DISTINCT {$col} FROM movies WHERE {$col} != '' AND {$col} IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $vals = [];
    foreach ($rows as $row) {
        foreach (array_map('trim', explode(',', $row)) as $v) {
            if ($v !== '') $vals[$v] = true;
        }
    }
    $keys = array_keys($vals); sort($keys); return $keys;
}
$_ligaGenres    = ligaLoadDistinct($db, 'genre');
$_ligaCountries = ligaLoadDistinct($db, 'country');

$state        = 'setup';
$currentMatch = null;
$progress     = null;
$pct          = 0;
$ligaId       = 0;
$filmCount    = 0;

if ($activeSession) {
    $state     = 'active';
    $ligaId    = (int)$activeSession['id'];
    $filmCount = (int)$activeSession['film_count'];

    $stmt = $db->prepare("SELECT COUNT(*) FROM liga_matches WHERE liga_id = ?");
    $stmt->execute([$ligaId]);
    $total = (int)$stmt->fetchColumn();

    // Verwaiste Session (0 Matches) sofort abschließen → zurück zum Setup
    if ($total === 0) {
        $db->prepare("UPDATE liga_sessions SET status = 'completed' WHERE id = ?")->execute([$ligaId]);
        header('Location: /liga.php'); exit;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM liga_matches WHERE liga_id = ? AND winner_id IS NULL");
    $stmt->execute([$ligaId]);
    $remaining = (int)$stmt->fetchColumn();

    $progress = ['done' => $total - $remaining, 'total' => $total, 'remaining' => $remaining];
    $pct      = $total > 0 ? round(($total - $remaining) / $total * 100, 1) : 0;

    $stmt = $db->prepare("
        SELECT lm.id, lm.movie_a_id, lm.movie_b_id,
               ma.title AS a_title, ma.year AS a_year, ma.poster_path AS a_poster,
               mb.title AS b_title, mb.year AS b_year, mb.poster_path AS b_poster
        FROM liga_matches lm
        JOIN movies ma ON ma.id = lm.movie_a_id
        JOIN movies mb ON mb.id = lm.movie_b_id
        WHERE lm.liga_id = ? AND lm.winner_id IS NULL
        ORDER BY lm.id ASC
        LIMIT 50
    ");
    $stmt->execute([$ligaId]);
    $pool = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $currentMatch = $pool ? $pool[array_rand($pool)] : null;

    // Ligatabelle für die Sidebar
    $standings = [];
    if ($currentMatch) {
        $s = $db->prepare("
            SELECT m.id, m.title, m.title_en, m.poster_path, m.poster_path_en,
                   COALESCE(w.wins, 0) AS wins
            FROM (
                SELECT movie_a_id AS film_id FROM liga_matches WHERE liga_id = ?
                UNION
                SELECT movie_b_id            FROM liga_matches WHERE liga_id = ?
            ) f
            JOIN movies m ON m.id = f.film_id
            LEFT JOIN (
                SELECT winner_id, COUNT(*) AS wins
                FROM liga_matches
                WHERE liga_id = ? AND winner_id IS NOT NULL
                GROUP BY winner_id
            ) w ON w.winner_id = m.id
            ORDER BY wins DESC, m.title ASC
        ");
        $s->execute([$ligaId, $ligaId, $ligaId]);
        $standings = $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // Meine Rangliste für die rechte Sidebar
    $posRanking = [];
    try {
        $s = $db->prepare("
            SELECT upr.position, m.id, m.title, m.title_en, m.poster_path, m.poster_path_en
            FROM user_position_ranking upr
            JOIN movies m ON m.id = upr.movie_id
            WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
            ORDER BY upr.position ASC
        ");
        $s->execute([$userId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
            $posRanking[] = array_merge($r, ['position' => $i + 1]);
        }
    } catch (\PDOException $e) { $posRanking = []; }

    // Besser-platzierter Film immer links (A-Seite)
    if ($currentMatch && !empty($posRanking)) {
        $posMap = array_column($posRanking, 'position', 'id');
        sortMatchSideByPos($currentMatch, $posMap);
    }
}

$stmt = $db->prepare("SELECT id FROM liga_sessions WHERE user_id = ? AND status = 'completed' LIMIT 1");
$stmt->execute([$userId]);
$hasCompleted = (bool)$stmt->fetch();

require_once __DIR__ . '/includes/header.php';
?>

<style>
body { background: #14325a !important; }
.liga-hero { background: linear-gradient(135deg, #14325a 0%, #1e3d7a 100%); border-bottom: 1px solid rgba(232,184,75,.15); }
.container-xxl.liga-wrap { max-width: 2200px; margin: 0 auto; }
/* Larger posters inside the center col */
.duel-poster-wrap { max-width: 600px !important; }

/* 3-column flex layout (sidebars fixed-width, center fluid) */
.liga-3col   { display: flex; gap: 20px; align-items: flex-start; padding: 0 5%; }
.liga-side   { flex: 0 0 420px; min-width: 0; display: flex; flex-direction: column; overflow: hidden; }
.liga-center { flex: 1; min-width: 0; }
.liga-side .turnier-ranking-wrap { flex: 1; display: flex; flex-direction: column; min-height: 0; }
.liga-side .turnier-ranking-list { flex: 1; min-height: 0; overflow-y: auto; }
/* Tablet 768 – 1400 px: rechte Sidebar weg, linke Sidebar schmaler */
@media (max-width: 1400px) and (min-width: 768px) {
    .liga-3col                    { padding: 0 2%; gap: 12px; }
    .liga-3col > div:nth-child(1) { flex: 0 0 320px; }
    .liga-3col > div:nth-child(3) { display: none !important; }
    .liga-side .turnier-ranking-list { max-height: 60vh; }
    .duel-poster-wrap             { max-width: min(600px, 27vh) !important; max-height: none !important; }
}

/* Mobile < 768 px: alle Sidebars ausblenden */
@media (max-width: 767px) {
    .liga-3col   { padding: 0 1%; }
    .liga-side   { display: none !important; }
    .liga-center { flex: 0 0 100%; }
    .duel-poster-wrap { max-width: 600px !important; max-height: none; }
}

/* Mobile Querformat: Cover vollständig sichtbar */
@media (max-width: 900px) and (orientation: landscape) {
    .liga-side { display: none !important; }
    .duel-poster-wrap { max-width: none !important; display: flex; justify-content: center; }
    .duel-poster { max-height: calc(100dvh - 180px) !important; width: auto !important; object-fit: contain !important; }
}

/* Duel Arena */
.duel-arena { display: flex; align-items: stretch; gap: 0; border-radius: 12px; overflow: hidden; border: 1px solid rgba(255,255,255,.08); }
.duel-side {
    flex: 1; cursor: pointer;
    display: flex; flex-direction: column; align-items: center;
    background: rgba(255,255,255,.03);
    padding: 20px 16px;
    transition: background .2s, transform .15s;
    position: relative; overflow: hidden;
}
@media (hover: hover) { .duel-side:hover { background: rgba(232,184,75,.1); } .duel-side:hover .duel-overlay { opacity: 1; } }
.duel-side.kb-active { background: rgba(232,184,75,.1) !important; }
.duel-side.kb-active .duel-overlay { opacity: 1; }
.duel-side.winner { background: rgba(232,184,75,.18) !important; transform: scale(1.01); }
.duel-side.loser  { opacity: .4; }
.duel-overlay {
    position: absolute; inset: 0;
    background: rgba(232,184,75,.2); border-radius: 10px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .2s;
    color: #e8b84b; font-size: 2rem;
}
.duel-title { color: #e0e0e0; font-weight: 600; font-size: .95rem; text-align: center; margin-top: 14px; margin-bottom: 4px; }
.duel-meta  { color: rgba(255,255,255,.4); font-size: .8rem; text-align: center; }
.vs-divider { display: flex; align-items: center; justify-content: center; padding: 0 8px; background: rgba(0,0,0,.3); min-width: 48px; }
.vs-circle  { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a; font-weight: 800; font-size: .85rem; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }

/* Ranking panels (matches turnier.php style) */
.turnier-ranking-wrap { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); border-radius: 12px; overflow: hidden; }
.turnier-ranking-header { padding: .75rem 1rem; font-size: .8rem; font-weight: 700; color: #e8b84b; display: flex; align-items: center; gap: .4rem; border-bottom: 1px solid rgba(255,255,255,.08); background: rgba(232,184,75,.06); }
.turnier-ranking-list { overflow-y: auto; scrollbar-width: thin; scrollbar-color: rgba(232,184,75,.2) transparent; }
.turnier-ranking-list::-webkit-scrollbar { width: 4px; }
.turnier-ranking-list::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 2px; }
.turnier-rank-row { display: flex; align-items: center; gap: .5rem; padding: .4rem .75rem; border-bottom: 1px solid rgba(255,255,255,.05); transition: background .15s; }
.turnier-rank-row:last-child { border-bottom: none; }
.turnier-rank-row:hover { background: rgba(255,255,255,.04); }
.turnier-rank-row.active-film { background: rgba(232,184,75,.12) !important; }
.turnier-rank-num { min-width: 1.6rem; font-size: .75rem; font-weight: 700; color: rgba(255,255,255,.4); text-align: right; }
.turnier-rank-num.top { color: #e8b84b; }
.turnier-rank-poster { width: 26px; height: 39px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
.turnier-rank-title { flex: 1; font-size: .8rem; color: rgba(255,255,255,.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.turnier-points { font-size: .75rem; font-weight: 700; color: rgba(255,255,255,.6); white-space: nowrap; }

/* Progress */
.liga-progress { background: rgba(255,255,255,.05); border-radius: 8px; padding: 16px 20px; }
.progress-track { background: rgba(255,255,255,.08); border-radius: 6px; height: 8px; overflow: hidden; }
.progress-fill  { background: linear-gradient(90deg, #e8b84b, #c4942a); height: 100%; border-radius: 6px; transition: width .4s ease; }

/* Setup */
.setup-card { max-width: 560px; margin: 0 auto; background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 32px; }
.form-ctrl { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); color: #e0e0e0; border-radius: 8px; padding: 10px 14px; font-size: 1rem; width: 100%; }
.form-ctrl:focus { outline: none; border-color: #e8b84b; background: rgba(255,255,255,.09); }
.btn-gold { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a; font-weight: 700; border: none; border-radius: 8px; padding: 12px 28px; font-size: 1rem; cursor: pointer; transition: opacity .2s; display: inline-flex; align-items: center; justify-content: center; width: 100%; }
.btn-gold:hover:not(:disabled) { opacity: .85; }
.btn-gold:disabled { opacity: .45; cursor: not-allowed; }

/* Completion */
.completion-card { max-width: 480px; margin: 0 auto; text-align: center; padding: 40px; background: rgba(255,255,255,.03); border: 1px solid rgba(232,184,75,.3); border-radius: 16px; }
.btn-gold-link { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a !important; font-weight: 700; border-radius: 8px; padding: 12px 28px; text-decoration: none !important; display: inline-block; }
.btn-gold-link:hover { opacity: .85; }
</style>

<main style="padding-top:6px; background:#14325a; min-height:100vh;">

    <!-- Hero -->
    <section class="liga-hero py-4">
        <div class="container-xxl liga-wrap">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1 class="fw-bold mb-1" style="color:#e8b84b; font-size:1.6rem;">
                        <i class="bi bi-people-fill me-2"></i>Jeder gegen Jeden
                    </h1>
                    <p class="mb-0" style="color:rgba(255,255,255,.5); font-size:.9rem;">
                        Round-Robin-Liga – jeder Film trifft jeden anderen einmal
                    </p>
                </div>
                <?php if ($state === 'active'): ?>
                <div class="text-end">
                    <div id="hero-done" style="color:#e8b84b; font-size:1.5rem; font-weight:800; line-height:1;"><?= $progress['done'] ?></div>
                    <div style="color:rgba(255,255,255,.4); font-size:.75rem;">von <?= number_format($progress['total'], 0, ',', '.') ?> Duellen</div>
                    <div id="hero-pct" style="color:rgba(232,184,75,.55); font-size:.72rem;"><?= $pct ?>%</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="py-4">
        <div class="container-xxl liga-wrap">

<?php if ($state === 'setup'): ?>
<!-- ── ZUSTAND A: Setup ──────────────────────────────────────────────────── -->

            <?php if ($hasCompleted): ?>
            <div class="d-flex align-items-center gap-2 mb-4"
                 style="background:rgba(76,175,80,.12); border:1px solid rgba(76,175,80,.3); color:#81c784; border-radius:8px; padding:14px 18px; font-size:.9rem;">
                <i class="bi bi-check-circle-fill flex-shrink-0"></i>
                <span>Du hast bereits eine Liga abgeschlossen! Du kannst jederzeit eine neue starten.</span>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['phase_blocked'])): ?>
            <div class="d-flex align-items-center gap-2 mb-4"
                 style="background:rgba(232,184,75,.1); border:1px solid rgba(232,184,75,.3); color:#e8b84b; border-radius:8px; padding:14px 18px; font-size:.9rem;">
                <i class="bi bi-info-circle-fill flex-shrink-0"></i>
                <span>Schließe die Liga ab, um den vollen Zugang freizuschalten.</span>
            </div>
            <?php endif; ?>

            <div class="setup-card">
                <h3 class="fw-bold mb-1" style="color:#e0e0e0;">Liga einrichten</h3>
                <p style="color:rgba(255,255,255,.5); font-size:.9rem; margin-bottom:24px;">
                    Deine Rangliste enthält aktuell
                    <strong style="color:#e8b84b;"><?= $rankedCount ?></strong> <?= $mtActive === 'tv' ? 'Serien' : 'Filme' ?>.
                    Wähle, wie viele davon in die Liga aufgenommen werden sollen (Top-N nach Ranglistenposition).
                </p>

                <?php if ($rankedCount < 10): ?>
                <div class="d-flex align-items-center gap-2"
                     style="background:rgba(244,67,54,.1); border:1px solid rgba(244,67,54,.25); color:#ef9a9a; border-radius:8px; padding:14px 18px; font-size:.9rem;">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                    <span>Du benötigst mindestens <strong>10 gerankte Filme</strong>. Führe zunächst mehr Duelle im Turnier durch.</span>
                </div>
                <?php else: ?>

                <div id="error-msg" class="d-none mb-3"
                     style="background:rgba(244,67,54,.1); border:1px solid rgba(244,67,54,.25); color:#ef9a9a; border-radius:8px; padding:12px 16px; font-size:.9rem;"></div>

                <div class="mb-3">
                    <label style="color:rgba(255,255,255,.7); font-size:.85rem; font-weight:600; display:block; margin-bottom:8px;">
                        Anzahl <?= $mtActive === 'tv' ? 'Serien' : 'Filme' ?> <span style="color:rgba(255,255,255,.4);">(10 – <?= $rankedCount ?>)</span>
                    </label>
                    <input type="number" id="film-count" class="form-ctrl"
                           value="10" min="10" max="<?= $rankedCount ?>" step="1">
                </div>

                <div class="mb-4 px-1" style="color:rgba(255,255,255,.45); font-size:.85rem; line-height:1.7;">
                    <i class="bi bi-info-circle me-1"></i>
                    Mit <strong id="fc-display" style="color:#e8b84b;">100</strong> Filmen entstehen
                    <strong id="duels-display" style="color:#e8b84b;">4.950</strong> Duelle.
                    Jedes Ergebnis fließt in deine ELO- und Positions-Rangliste ein.
                </div>

                <!-- Filter -->
                <div class="mb-4">
                    <div style="color:rgba(255,255,255,.5); font-size:.82rem; margin-bottom:8px;">
                        <i class="bi bi-funnel-fill me-1"></i>Filter <span style="opacity:.6;">(optional – leer = alle Filme)</span>
                    </div>
                    <div class="row g-2">
                        <div class="col-12 col-sm-6">
                            <select id="liga-tf-genre" class="form-select form-select-sm turnier-filter-input">
                                <option value="">– Genre –</option>
                                <?php foreach ($_ligaGenres as $g): ?>
                                <option value="<?= e($g) ?>"><?= e($g) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <select id="liga-tf-country" class="form-select form-select-sm turnier-filter-input">
                                <option value="">– Produktionsland –</option>
                                <?php foreach ($_ligaCountries as $c): ?>
                                <option value="<?= e($c) ?>"><?= e($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 position-relative">
                            <input type="text" id="liga-tf-director" class="form-control form-control-sm turnier-filter-input"
                                   placeholder="Regie" autocomplete="off">
                            <ul class="turnier-suggest-list" id="liga-sug-director"></ul>
                        </div>
                        <div class="col-12 col-sm-6 position-relative">
                            <input type="text" id="liga-tf-actor" class="form-control form-control-sm turnier-filter-input"
                                   placeholder="Darsteller" autocomplete="off">
                            <ul class="turnier-suggest-list" id="liga-sug-actor"></ul>
                        </div>
                    </div>
                </div>

                <button id="start-btn" class="btn-gold" type="button">
                    <span id="start-label"><i class="bi bi-play-fill me-2"></i>Liga starten</span>
                    <span id="start-spinner" class="d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Wird generiert …
                    </span>
                </button>
                <?php endif; ?>
            </div>

<?php elseif ($state === 'active' && $currentMatch): ?>
<!-- ── ZUSTAND B: Aktives Duell ────────────────────────────────────────────── -->
<?php
    $aUrl    = moviePosterUrl(['poster_path' => $currentMatch['a_poster'], 'poster_path_en' => $currentMatch['a_poster_en'] ?? null]);
    $bUrl    = moviePosterUrl(['poster_path' => $currentMatch['b_poster'], 'poster_path_en' => $currentMatch['b_poster_en'] ?? null]);
    $aTitle  = movieTitle(['title' => $currentMatch['a_title'], 'title_en' => $currentMatch['a_title_en'] ?? null]);
    $bTitle  = movieTitle(['title' => $currentMatch['b_title'], 'title_en' => $currentMatch['b_title_en'] ?? null]);
    $canUndo = $progress['done'] > 0;
?>
            <p class="text-center mb-3" style="color:rgba(255,255,255,.4); font-size:clamp(1.4rem,2.5vw,2.5rem); white-space:nowrap; overflow:hidden;">
                Welchen Film schaust du dir lieber an?
            </p>

            <div class="liga-3col">

                <!-- ── Links: Meine Rangliste ────────────────────────────────── -->
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
                            <?php if (empty($posRanking)): ?>
                            <div class="turnier-rank-row" style="color:rgba(255,255,255,.3); font-size:.75rem; justify-content:center;">
                                Noch keine Einträge
                            </div>
                            <?php else: ?>
                            <?php foreach ($posRanking as $r): ?>
                            <div class="turnier-rank-row" data-film-id="<?= (int)$r['id'] ?>">
                                <span class="turnier-rank-num <?= (int)$r['position'] <= 3 ? 'top' : '' ?>"><?= (int)$r['position'] ?></span>
                                <img src="<?= e(moviePosterUrl($r, 'w92')) ?>" class="turnier-rank-poster"
                                     width="26" height="39" loading="lazy"
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

                <!-- ── Mitte: Fortschritt + Duell ────────────────────────────── -->
                <div class="liga-center">

                    <!-- Fortschrittsbalken -->
                    <div class="liga-progress mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span style="color:rgba(255,255,255,.6); font-size:.85rem;">Fortschritt</span>
                            <span id="progress-text" style="color:#e8b84b; font-size:.85rem; font-weight:600;">
                                <?= number_format($progress['done'], 0, ',', '.') ?> /
                                <?= number_format($progress['total'], 0, ',', '.') ?> Duelle
                                (<?= $pct ?>%)
                            </span>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill" id="progress-fill" style="width:<?= $pct ?>%"></div>
                        </div>
                    </div>

                    <!-- Duel-Bereich -->
                    <div id="duel-section">
                        <div class="duel-arena" id="duel-arena">
                            <div class="duel-side" id="movie-a"
                                 data-id="<?= $currentMatch['movie_a_id'] ?>"
                                 data-match="<?= $currentMatch['id'] ?>">
                                <div class="duel-poster-wrap">
                                    <img class="duel-poster" fetchpriority="high" decoding="async" src="<?= e($aUrl) ?>"
                                         alt="<?= e($aTitle) ?>"
                                         loading="lazy" onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                                    <div class="duel-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
                                </div>
                                <div class="duel-title"><?= e($aTitle) ?></div>
                                <div class="duel-meta"><?= (int)$currentMatch['a_year'] ?></div>
                            </div>

                            <div class="vs-divider"><div class="vs-circle">VS</div></div>

                            <div class="duel-side" id="movie-b"
                                 data-id="<?= $currentMatch['movie_b_id'] ?>"
                                 data-match="<?= $currentMatch['id'] ?>">
                                <div class="duel-poster-wrap">
                                    <img class="duel-poster" fetchpriority="high" decoding="async" src="<?= e($bUrl) ?>"
                                         alt="<?= e($bTitle) ?>"
                                         loading="lazy" onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                                    <div class="duel-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
                                </div>
                                <div class="duel-title"><?= e($bTitle) ?></div>
                                <div class="duel-meta"><?= (int)$currentMatch['b_year'] ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Undo-Button -->
                    <div class="text-center mt-3">
                        <button id="undo-btn" <?= $canUndo ? '' : 'disabled' ?>
                                class="btn btn-link p-0 text-decoration-none"
                                style="color:rgba(255,255,255,.35); font-size:.8rem;">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Letztes Duell rückgängig
                        </button>
                    </div>

                    <!-- Abschluss (JS-gesteuert) -->
                    <div id="completion-overlay" style="display:none; padding-top:24px;">
                        <div class="completion-card">
                            <div style="font-size:3.5rem; margin-bottom:16px;">🏆</div>
                            <h2 class="fw-bold mb-2" style="color:#e8b84b;">Liga abgeschlossen!</h2>
                            <p style="color:rgba(255,255,255,.6); margin-bottom:24px;">
                                Du hast alle Duelle ausgetragen. Deine Ranglisten wurden aktualisiert.
                            </p>
                            <a href="/rangliste.php?tab=liga" class="btn-gold-link">
                                <i class="bi bi-trophy-fill me-2"></i>Zur Rangliste
                            </a>
                        </div>
                    </div>

                </div><!-- /.liga-center -->

                <!-- ── Rechts: Ligatabelle ───────────────────────────────────── -->
                <div class="liga-side">
                    <div class="turnier-ranking-wrap">
                        <div class="turnier-ranking-header">
                            <i class="bi bi-bar-chart-fill"></i> Ligatabelle
                        </div>
                        <div class="turnier-ranking-list" id="standings-list">
                            <?php foreach ($standings as $i => $film): ?>
                            <div class="turnier-rank-row" data-film-id="<?= (int)$film['id'] ?>" data-pts="<?= (int)$film['wins'] ?>">
                                <span class="turnier-rank-num"><?= $i + 1 ?></span>
                                <img src="<?= e(moviePosterUrl($film, 'w92')) ?>" class="turnier-rank-poster"
                                     width="26" height="39" loading="lazy"
                                     onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
                                <div class="turnier-rank-title" title="<?= e(movieTitle($film)) ?>"><a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a></div>
                                <span class="turnier-points"><?= (int)$film['wins'] ?><i class="bi bi-trophy-fill ms-1" style="font-size:.6rem;color:#e8b84b;"></i></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="text-center mt-2" style="font-size:.75rem;">
                        <form method="post" class="d-inline" onsubmit="return confirm('Liga wirklich abbrechen? Alle bisherigen Duelle dieser Session gehen verloren.');">
                            <input type="hidden" name="action"     value="abort">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <button class="btn btn-link p-0 text-decoration-none"
                                    style="color:rgba(255,255,255,.3); font-size:.75rem;">
                                <i class="bi bi-x-circle me-1"></i>Liga abbrechen
                            </button>
                        </form>
                    </p>
                </div><!-- /.liga-side -->

            </div><!-- /.liga-3col -->

<?php endif; ?>

        </div>
    </section>
</main>

<script>
const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
const LIGA_ID    = <?= $ligaId ?>;
const IMG_BASE   = <?= json_encode(rtrim(TMDB_IMAGE_BASE, '/')) ?>;

function updateHdrCounters(totalDuels, uniqueFilms) {
    const dc = document.getElementById('hdr-duels-count');
    const fc = document.getElementById('hdr-films-count');
    if (dc && totalDuels !== undefined) dc.textContent = totalDuels.toLocaleString('de-DE');
    if (fc && uniqueFilms !== undefined) fc.textContent = uniqueFilms;
}

<?php if ($state === 'setup' && $rankedCount >= 10): ?>
// ── Setup-Logik ──────────────────────────────────────────────────────────────
(function () {
    const fcInput   = document.getElementById('film-count');
    const fcDisplay = document.getElementById('fc-display');
    const duelsDisp = document.getElementById('duels-display');
    const startBtn  = document.getElementById('start-btn');
    const startLbl  = document.getElementById('start-label');
    const startSpin = document.getElementById('start-spinner');
    const errorMsg  = document.getElementById('error-msg');
    const maxFilms  = <?= $rankedCount ?>;

    function fmt(n) { return n.toLocaleString('de-DE'); }

    function updateHint() {
        const n = Math.max(0, parseInt(fcInput.value) || 0);
        fcDisplay.textContent = fmt(n);
        duelsDisp.textContent = fmt(Math.floor(n * (n - 1) / 2));
    }

    fcInput.addEventListener('input', updateHint);
    updateHint();

    startBtn.addEventListener('click', async () => {
        const n = parseInt(fcInput.value) || 0;
        if (n < 10 || n > maxFilms) {
            errorMsg.textContent = `Bitte eine Zahl zwischen 10 und ${maxFilms} eingeben.`;
            errorMsg.classList.remove('d-none');
            return;
        }
        errorMsg.classList.add('d-none');
        startLbl.classList.add('d-none');
        startSpin.classList.remove('d-none');
        startBtn.disabled = true;

        const fd = new FormData();
        fd.append('action',          'start');
        fd.append('csrf_token',      CSRF_TOKEN);
        fd.append('film_count',      n);
        fd.append('filter_genre',    document.getElementById('liga-tf-genre')?.value    || '');
        fd.append('filter_country',  document.getElementById('liga-tf-country')?.value  || '');
        fd.append('filter_director', document.getElementById('liga-tf-director')?.value.trim() || '');
        fd.append('filter_actor',    document.getElementById('liga-tf-actor')?.value.trim()    || '');

        try {
            const res  = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                window.location.reload();
            } else {
                errorMsg.textContent = data.error === 'no_films'
                    ? 'Keine Filme für diese Filterauswahl gefunden. Bitte andere Kriterien wählen.'
                    : 'Fehler beim Starten: ' + (data.error ?? 'unbekannt');
                errorMsg.classList.remove('d-none');
                startLbl.classList.remove('d-none');
                startSpin.classList.add('d-none');
                startBtn.disabled = false;
            }
        } catch {
            errorMsg.textContent = 'Netzwerkfehler. Bitte nochmals versuchen.';
            errorMsg.classList.remove('d-none');
            startLbl.classList.remove('d-none');
            startSpin.classList.add('d-none');
            startBtn.disabled = false;
        }
    });

    // Autocomplete für Regie / Darsteller
    [['liga-tf-director','liga-sug-director','director'],
     ['liga-tf-actor',   'liga-sug-actor',   'actors']].forEach(([inpId, sugId, api]) => {
        const inp = document.getElementById(inpId);
        const sug = document.getElementById(sugId);
        if (!inp || !sug) return;
        let timer = null, idx = -1;
        inp.addEventListener('input', () => {
            clearTimeout(timer);
            const q = inp.value.trim();
            if (!q) { sug.innerHTML=''; sug.classList.remove('open'); return; }
            timer = setTimeout(() => {
                fetch('/turnier.php?action=suggest&field=' + api + '&q=' + encodeURIComponent(q))
                    .then(r => r.json()).then(arr => {
                        sug.innerHTML=''; idx=-1;
                        if (!arr.length) { sug.classList.remove('open'); return; }
                        arr.forEach(val => {
                            const li = document.createElement('li');
                            li.textContent = val;
                            li.addEventListener('mousedown', e => { e.preventDefault(); inp.value=val; sug.innerHTML=''; sug.classList.remove('open'); });
                            sug.appendChild(li);
                        });
                        sug.classList.add('open');
                    }).catch(()=>{});
            }, 200);
        });
        inp.addEventListener('keydown', e => {
            const items = sug.querySelectorAll('li');
            if (!items.length) return;
            if (e.key==='ArrowDown') { e.preventDefault(); idx=Math.min(idx+1,items.length-1); items.forEach((li,i)=>li.classList.toggle('active',i===idx)); }
            else if (e.key==='ArrowUp') { e.preventDefault(); idx=Math.max(idx-1,-1); items.forEach((li,i)=>li.classList.toggle('active',i===idx)); }
            else if (e.key==='Enter' && idx>=0 && items[idx]) { e.preventDefault(); inp.value=items[idx].textContent; sug.innerHTML=''; sug.classList.remove('open'); }
            else if (e.key==='Escape') { sug.innerHTML=''; sug.classList.remove('open'); }
        });
        inp.addEventListener('blur', () => setTimeout(()=>{ sug.innerHTML=''; sug.classList.remove('open'); }, 150));
    });
})();
<?php endif; ?>

<?php if ($state === 'active'): ?>
// ── Duell-Logik ──────────────────────────────────────────────────────────────
(function () {
    const arena      = document.getElementById('duel-arena');
    const duelSec    = document.getElementById('duel-section');
    const completion = document.getElementById('completion-overlay');
    const progFill   = document.getElementById('progress-fill');
    const progText   = document.getElementById('progress-text');
    const heroDone   = document.getElementById('hero-done');
    const heroPct    = document.getElementById('hero-pct');
    const standList  = document.getElementById('standings-list');
    const posList    = document.getElementById('pos-ranking-list');
    const undoBtn    = document.getElementById('undo-btn');
    let voting = false;
    let votingTimer = null;

    function setVoting(v) {
        voting = v;
        clearTimeout(votingTimer);
        if (v) votingTimer = setTimeout(() => { voting = false; }, 6000);
    }

    function fmt(n) { return n.toLocaleString('de-DE'); }

    function posterSrc(path) {
        // If already a full URL (from display_poster), use directly; otherwise prepend base
        if (!path) return 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?';
        return path.startsWith('http') ? path : IMG_BASE + path;
    }

    function setCard(side, m) {
        side.querySelector('.duel-poster').src = posterSrc(m.poster);
        side.querySelector('.duel-poster').alt = m.title;
        side.querySelector('.duel-title').textContent = m.title;
        side.querySelector('.duel-meta').textContent  = m.year || '';
        side.dataset.id    = m.id;
        side.dataset.match = m.matchId;
        side.classList.remove('winner', 'loser', 'kb-active');
    }

    function updateProgress(done, total) {
        const pct = total > 0 ? (done / total * 100).toFixed(1) : 0;
        progFill.style.width  = pct + '%';
        progText.textContent  = `${fmt(done)} / ${fmt(total)} Duelle (${pct}%)`;
        if (heroDone) heroDone.textContent = fmt(done);
        if (heroPct)  heroPct.textContent  = pct + '%';
        undoBtn.disabled = done === 0;
    }

    // ── Position-Badge + Scroll in Meine Rangliste ───────────────────────────
    function scrollPosRankingToFilm(filmId) {
        if (!posList || !filmId) return;
        const row = posList.querySelector(`[data-film-id="${filmId}"]`);
        if (row) row.scrollIntoView({ block: 'center', behavior: 'smooth' });
        const badge = document.getElementById('pos-current-film');
        const numEl = document.getElementById('pos-current-num');
        const ttlEl = document.getElementById('pos-current-title');
        if (badge) {
            const pos   = row?.querySelector('.turnier-rank-num')?.textContent?.trim() || '–';
            const title = row?.querySelector('.turnier-rank-title a')?.textContent?.trim()
                       || row?.querySelector('.turnier-rank-title')?.textContent?.trim() || '';
            if (numEl) numEl.textContent = '#' + pos;
            if (ttlEl) ttlEl.textContent = title;
            badge.style.display = row ? 'flex' : 'none';
        }
    }

    // ── Ligatabelle aktualisieren ─────────────────────────────────────────────
    function highlightRankings(aId, bId) {
        // Standings: highlight both, scroll to first
        if (standList) {
            standList.querySelectorAll('[data-film-id]').forEach(r => {
                const id = parseInt(r.dataset.filmId);
                r.classList.toggle('active-film', id === aId || id === bId);
            });
            const first = standList.querySelector('.active-film');
            if (first) first.scrollIntoView({ block: 'nearest', behavior: 'instant' });
        }
        // Meine Rangliste: highlight both, scroll to left (better-ranked) film
        if (posList) {
            posList.querySelectorAll('[data-film-id]').forEach(r => {
                const id = parseInt(r.dataset.filmId);
                r.classList.toggle('active-film', id === aId || id === bId);
            });
        }
        scrollPosRankingToFilm(aId);
    }

    function getPoints(row) {
        return parseInt(row.dataset.pts ?? 0);
    }

    function adjustWin(winnerId, delta) {
        if (!standList) return;
        const row = standList.querySelector(`[data-film-id="${winnerId}"]`);
        if (!row) return;
        const newPts = Math.max(0, getPoints(row) + delta);
        row.dataset.pts = newPts;
        const ptsEl = row.querySelector('.turnier-points');
        ptsEl.innerHTML = newPts + '<i class="bi bi-trophy-fill ms-1" style="font-size:.6rem;color:#e8b84b;"></i>';

        // Re-sort rows
        const rows = Array.from(standList.querySelectorAll('[data-film-id]'));
        rows.sort((a, b) => {
            const diff = getPoints(b) - getPoints(a);
            if (diff !== 0) return diff;
            return a.querySelector('.turnier-rank-title').textContent
                    .localeCompare(b.querySelector('.turnier-rank-title').textContent, 'de');
        });
        rows.forEach((r, i) => {
            r.querySelector('.turnier-rank-num').textContent = i + 1;
            standList.appendChild(r);
        });
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function updatePosRanking(ranking) {
        if (!posList || !ranking) return;
        const PH = 'https://placehold.co/26x39/1e3a5f/e8b84b?text=?';
        const activeIds = new Set(
            [...posList.querySelectorAll('.active-film')].map(r => parseInt(r.dataset.filmId))
        );
        posList.innerHTML = ranking.map(r => {
            const top = r.pos <= 3 ? ' top' : '';
            const src = r.display_poster || (r.poster_path ? IMG_BASE + r.poster_path : PH);
            const act = activeIds.has(r.id) ? ' active-film' : '';
            return `<div class="turnier-rank-row${act}" data-film-id="${r.id}">
                <span class="turnier-rank-num${top}">${r.pos}</span>
                <img src="${escHtml(src)}" class="turnier-rank-poster" width="26" height="39" loading="lazy" onerror="this.src='${PH}'">
                <div class="turnier-rank-title">${escHtml(r.title)}</div>
            </div>`;
        }).join('');
    }

    // Initial highlight
    highlightRankings(
        parseInt(document.getElementById('movie-a').dataset.id),
        parseInt(document.getElementById('movie-b').dataset.id)
    );

    // ── Klick + Tastatur ──────────────────────────────────────────────────────
    arena.addEventListener('click', (e) => {
        const side = e.target.closest('.duel-side');
        if (!side || voting) return;
        const other = side.id === 'movie-a'
            ? document.getElementById('movie-b')
            : document.getElementById('movie-a');
        castVote(side, other);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();
        if (voting) return;
        const winner = document.getElementById(e.key === 'ArrowLeft' ? 'movie-a' : 'movie-b');
        const loser  = document.getElementById(e.key === 'ArrowLeft' ? 'movie-b' : 'movie-a');
        if (winner && loser) { winner.classList.add('kb-active'); castVote(winner, loser); }
    });

    // ── Abstimmung ────────────────────────────────────────────────────────────
    async function castVote(winner, loser) {
        setVoting(true);
        winner.classList.add('winner');
        loser.classList.add('loser');

        const fd = new FormData();
        fd.append('action',     'vote');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('liga_id',    LIGA_ID);
        fd.append('match_id',   winner.dataset.match);
        fd.append('winner_id',  winner.dataset.id);
        fd.append('loser_id',   loser.dataset.id);

        try {
            const res  = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) { setVoting(false); location.reload(); return; }

            updateProgress(data.progress.done, data.progress.total);
            adjustWin(parseInt(winner.dataset.id), +1);
            updatePosRanking(data.posRanking);
            updateHdrCounters(data.hdrDuels, data.hdrFilms);

            if (data.completed) {
                setVoting(false);
                setTimeout(() => { duelSec.style.display = 'none'; completion.style.display = ''; }, 600);
                return;
            }

            setTimeout(() => {
                try {
                    const n = data.next;
                    setCard(document.getElementById('movie-a'), { id: n.a_id, title: n.a_title, year: n.a_year, poster: n.a_poster, matchId: n.id });
                    setCard(document.getElementById('movie-b'), { id: n.b_id, title: n.b_title, year: n.b_year, poster: n.b_poster, matchId: n.id });
                    highlightRankings(n.a_id, n.b_id);
                } finally {
                    setVoting(false);
                }
            }, 350);

        } catch {
            location.reload();
        }
    }

    // ── Undo ──────────────────────────────────────────────────────────────────
    undoBtn.addEventListener('click', async () => {
        if (voting || undoBtn.disabled) return;
        setVoting(true);
        undoBtn.disabled = true;

        const fd = new FormData();
        fd.append('action',     'undo');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('liga_id',    LIGA_ID);

        try {
            const res  = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) { location.reload(); return; }

            const r = data.restored;
            adjustWin(r.winner_id, -1);
            updatePosRanking(data.posRanking);
            updateProgress(data.progress.done, data.progress.total);
            if (!data.canUndo) undoBtn.disabled = true;

            setCard(document.getElementById('movie-a'), { id: r.a_id, title: r.a_title, year: r.a_year, poster: r.a_poster, matchId: r.id });
            setCard(document.getElementById('movie-b'), { id: r.b_id, title: r.b_title, year: r.b_year, poster: r.b_poster, matchId: r.id });
            highlightRankings(r.a_id, r.b_id);
            setVoting(false);
        } catch {
            location.reload();
        }
    });
})();
<?php endif; ?>

// ── Sidebar-Höhe = Mittelspalte ───────────────────────────────────────────────
(function () {
    const center = document.querySelector('.liga-center');
    const sides  = document.querySelectorAll('.liga-side');
    if (!center || !sides.length) return;

    function syncH() {
        const h = center.getBoundingClientRect().height;
        if (h < 50) return;
        sides.forEach(s => s.style.height = h + 'px');
    }

    syncH();
    window.addEventListener('resize', syncH);

    if (window.ResizeObserver) {
        new ResizeObserver(syncH).observe(center);
    } else {
        window.addEventListener('load', syncH);
    }
}());
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
