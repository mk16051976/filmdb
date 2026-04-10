<?php
set_time_limit(300);
$pageTitle = 'Sortieren – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Tabelle sicherstellen ─────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS sort_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    film_count SMALLINT UNSIGNED NOT NULL,
    status     ENUM('active','completed') NOT NULL DEFAULT 'active',
    state      JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Migration: Undo-Spalten + media_type hinzufügen (idempotent)
try {
    $db->exec("ALTER TABLE sort_sessions
        ADD COLUMN IF NOT EXISTS prev_state     JSON         NULL,
        ADD COLUMN IF NOT EXISTS last_winner_id INT UNSIGNED NULL,
        ADD COLUMN IF NOT EXISTS last_loser_id  INT UNSIGNED NULL");
} catch (\PDOException $e) {}
$db->exec("ALTER TABLE sort_sessions ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NOT NULL DEFAULT 'movie'");

// ── Hilfsfunktionen ───────────────────────────────────────────────────────────

function advanceSortState(array $state, int $winnerId): array {
    $m = $state['merging'];
    $winnerIsLeft = ((int)($m['left'][0] ?? -1)) === $winnerId;
    if ($winnerIsLeft) array_shift($m['left']);
    else               array_shift($m['right']);
    $m['merged'][] = $winnerId;
    $state['done']++;

    // Auto-drain erschöpfte Seite (kein recordComparison)
    if (empty($m['left'])) {
        $m['merged'] = array_merge($m['merged'], $m['right']);
        $m['right']  = [];
    } elseif (empty($m['right'])) {
        $m['merged'] = array_merge($m['merged'], $m['left']);
        $m['left']   = [];
    }
    $state['merging'] = $m;

    // Merge fertig?
    if (empty($state['merging']['left']) && empty($state['merging']['right'])) {
        $state['pending'][] = $state['merging']['merged'];
        $state['merging']   = null;

        if (count($state['lists']) >= 2) {
            $state['merging'] = [
                'left'   => array_shift($state['lists']),
                'right'  => array_shift($state['lists']),
                'merged' => [],
            ];
        } elseif (count($state['lists']) === 1) {
            $state['pending'][] = array_shift($state['lists']);
        }

        // Neuer Pass nötig?
        if ($state['merging'] === null && count($state['pending']) >= 2) {
            $state['lists']   = $state['pending'];
            $state['pending'] = [];
            $state['merging'] = [
                'left'   => array_shift($state['lists']),
                'right'  => array_shift($state['lists']),
                'merged' => [],
            ];
        }
    }
    return $state;
}

function buildSortRanking(array $state, PDO $db): array {
    $ids = [];
    foreach ($state['pending'] as $l) foreach ($l as $id) $ids[] = (int)$id;
    $m = $state['merging'];
    if ($m) {
        foreach ($m['merged'] as $id) $ids[] = (int)$id;
        foreach ($m['left']   as $id) $ids[] = (int)$id;
        foreach ($m['right']  as $id) $ids[] = (int)$id;
    }
    foreach ($state['lists'] as $l) foreach ($l as $id) $ids[] = (int)$id;
    if (!$ids) return [];

    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, title, title_en, poster_path, poster_path_en, imdb_id FROM movies WHERE id IN ($ph)");
    $stmt->execute($ids);
    $map  = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');

    return array_map(fn($pos, $id) => [
        'pos'          => $pos + 1,
        'id'           => $id,
        'title'        => movieTitle($map[$id] ?? []),
        'display_poster' => moviePosterUrl($map[$id] ?? [], 'w92'),
    ], array_keys($ids), $ids);
}

function buildInsertRanking(array $state, PDO $db): array {
    $ids = array_map('intval', $state['sorted'] ?? []);
    if (!$ids) return [];
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, title, title_en, poster_path, poster_path_en, imdb_id FROM movies WHERE id IN ($ph)");
    $stmt->execute($ids);
    $map  = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    return array_map(fn($pos, $id) => [
        'pos'          => $pos + 1,
        'id'           => $id,
        'title'        => movieTitle($map[$id] ?? []),
        'display_poster' => moviePosterUrl($map[$id] ?? [], 'w92'),
    ], array_keys($ids), $ids);
}

function buildPosRanking(PDO $db, int $userId): array {
    $sr = $db->prepare("SELECT upr.position AS pos, m.id, m.title, m.title_en, m.poster_path, m.poster_path_en
        FROM user_position_ranking upr JOIN movies m ON m.id = upr.movie_id
        WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . " ORDER BY upr.position ASC LIMIT 200");
    $sr->execute([$userId]);
    $out = [];
    foreach ($sr->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $out[] = [
            'pos'            => $i + 1,
            'id'             => (int)$r['id'],
            'title'          => movieTitle($r),
            'display_poster' => moviePosterUrl($r, 'w92'),
        ];
    }
    return $out;
}

/** Gibt nur id→position zurück, ohne LIMIT, für den Rang-Vergleich beim Duell. */
function getPosMap(PDO $db, int $userId): array {
    $sr = $db->prepare("SELECT movie_id, position FROM user_position_ranking WHERE user_id = ?");
    $sr->execute([$userId]);
    return array_column($sr->fetchAll(PDO::FETCH_ASSOC), 'position', 'movie_id');
}

function finalizeSortPositions(PDO $db, int $userId, array $sortedIds): void {
    $n = count($sortedIds);
    if ($n === 0) return;

    $upsert = $db->prepare("INSERT INTO user_position_ranking (user_id, movie_id, position)
        VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE position = VALUES(position)");

    $db->beginTransaction();
    foreach ($sortedIds as $pos => $id) {
        $upsert->execute([$userId, (int)$id, $pos + 1]);
    }

    // Nicht-sortierte Filme: N+1, N+2, ... (Reihenfolge erhalten)
    $ph   = implode(',', array_fill(0, $n, '?'));
    $stmt = $db->prepare("SELECT movie_id FROM user_position_ranking
        WHERE user_id = ? AND movie_id NOT IN ($ph) ORDER BY position ASC");
    $stmt->execute(array_merge([$userId], array_map('intval', $sortedIds)));
    $offset = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $upsert->execute([$userId, (int)$id, $n + 1 + $offset++]);
    }
    $db->commit();
}

// ── AJAX / Form-Handler ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Abbrechen (Form-POST → Redirect) ─────────────────────────────────────
    if ($action === 'abort' && csrfValid()) {
        $db->prepare("DELETE FROM sort_sessions WHERE user_id = ? AND status = 'active' AND media_type = ?")
           ->execute([$userId, activeMtForDb()]);
        header('Location: /sortieren.php');
        exit;
    }

    // ── Ab hier: JSON-Antworten ───────────────────────────────────────────────
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    // ── Extend (Insertion Sort: neue Filme einordnen) ─────────────────────────
    if ($action === 'extend') {
        if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

        $extStmt = $db->prepare("SELECT state FROM sort_sessions
            WHERE user_id = ? AND status = 'completed' AND media_type = ? ORDER BY created_at DESC LIMIT 1");
        $extStmt->execute([$userId, activeMtForDb()]);
        $compRow = $extStmt->fetch(PDO::FETCH_ASSOC);
        if (!$compRow) { echo json_encode(['ok' => false, 'error' => 'no_completed']); exit; }

        $compState = json_decode($compRow['state'], true);
        $sortedIds = array_map('intval', $compState['sorted'] ?? $compState['pending'][0] ?? []);
        if (empty($sortedIds)) { echo json_encode(['ok' => false, 'error' => 'empty_sorted']); exit; }

        $sortedSet = array_flip($sortedIds);

        // Spezifische IDs (von Meine Rangliste) oder Top-N
        if (!empty($_POST['film_ids'])) {
            $rawIds   = array_map('intval', array_filter(explode(',', $_POST['film_ids'])));
            $newFilms = array_values(array_filter($rawIds, fn($id) => $id > 0 && !isset($sortedSet[$id])));
        } else {
            $n = max(1, (int)($_POST['film_count'] ?? 1));
            $allStmt = $db->prepare("SELECT movie_id FROM user_position_ranking
                WHERE user_id = ? ORDER BY position ASC");
            $allStmt->execute([$userId]);
            $allRanked = array_map('intval', $allStmt->fetchAll(PDO::FETCH_COLUMN));
            $newFilms  = array_values(array_filter($allRanked, fn($id) => !isset($sortedSet[$id])));
            $newFilms  = array_slice($newFilms, 0, $n);
        }

        if (empty($newFilms)) { echo json_encode(['ok' => false, 'error' => 'no_new_films']); exit; }

        $sortedLen  = count($sortedIds);
        $totalComps = 0;
        foreach (range(0, count($newFilms) - 1) as $i) {
            $totalComps += max(1, (int)ceil(log(max($sortedLen + $i, 2), 2)));
        }

        $firstFilm = array_shift($newFilms);
        $state = [
            'mode'      => 'insert',
            'sorted'    => $sortedIds,
            'to_insert' => $newFilms,
            'current'   => ['film_id' => $firstFilm, 'lo' => 0, 'hi' => $sortedLen],
            'total'     => $totalComps,
            'done'      => 0,
        ];

        $db->prepare("UPDATE sort_sessions SET status = 'completed' WHERE user_id = ? AND status = 'active' AND media_type = ?")
           ->execute([$userId, activeMtForDb()]);
        $db->prepare("INSERT INTO sort_sessions (user_id, film_count, state, media_type) VALUES (?, ?, ?, ?)")
           ->execute([$userId, $sortedLen + count($newFilms) + 1, json_encode($state), activeMtForDb()]);

        header('Location: /sortieren.php');
        exit;
    }

    // ── Start ─────────────────────────────────────────────────────────────────
    if ($action === 'start') {
        if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

        $n = (int)($_POST['film_count'] ?? 0);

        $stmt = $db->prepare("SELECT movie_id FROM user_position_ranking
            WHERE user_id = ? ORDER BY position ASC LIMIT ?");
        $stmt->execute([$userId, $n]);
        $filmIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (count($filmIds) < 10) {
            echo json_encode(['ok' => false, 'error' => 'not_enough_films']); exit;
        }

        shuffle($filmIds);

        // Jeder Film als Singleton-Liste
        $lists = array_map(fn($id) => [$id], $filmIds);

        // Ersten Merge sofort starten
        $leftList  = array_shift($lists);
        $rightList = array_shift($lists);

        $total = (int)ceil(count($filmIds) * log(count($filmIds), 2));

        $state = [
            'lists'   => $lists,
            'merging' => ['left' => $leftList, 'right' => $rightList, 'merged' => []],
            'pending' => [],
            'total'   => $total,
            'done'    => 0,
        ];

        // Vorherige aktive Sessions deaktivieren
        $db->prepare("UPDATE sort_sessions SET status = 'completed'
                      WHERE user_id = ? AND status = 'active' AND media_type = ?")
           ->execute([$userId, activeMtForDb()]);

        $db->prepare("INSERT INTO sort_sessions (user_id, film_count, state, media_type) VALUES (?, ?, ?, ?)")
           ->execute([$userId, count($filmIds), json_encode($state), activeMtForDb()]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Vote ──────────────────────────────────────────────────────────────────
    if ($action === 'vote') {
        if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

        $winnerId = (int)($_POST['winner_id'] ?? 0);
        $loserId  = (int)($_POST['loser_id']  ?? 0);

        // Aktive Session laden
        $stmt = $db->prepare("SELECT id, state FROM sort_sessions
            WHERE user_id = ? AND status = 'active' AND media_type = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$userId, activeMtForDb()]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) { echo json_encode(['ok' => false, 'error' => 'no_session']); exit; }

        $sessId = (int)$session['id'];
        $state  = json_decode($session['state'], true);

        // ── Insert-Modus ──────────────────────────────────────────────────────
        if (($state['mode'] ?? '') === 'insert') {
            $cur = $state['current'];
            if (!$cur) { echo json_encode(['ok' => false, 'error' => 'no_current']); exit; }

            $mid          = (int)floor(($cur['lo'] + $cur['hi']) / 2);
            $newFilmId    = (int)$cur['film_id'];
            $sortedFilmId = (int)$state['sorted'][$mid];

            $valid = ($winnerId === $newFilmId    && $loserId === $sortedFilmId)
                  || ($winnerId === $sortedFilmId && $loserId === $newFilmId);
            if (!$valid) { echo json_encode(['ok' => false, 'error' => 'stale']); exit; }

            $duelResult = buildDuelResult($db, $userId, $winnerId, $loserId);
            recordComparison($userId, $winnerId, $loserId);
            $winnerContext = buildWinnerContext($db, $userId, $winnerId, getActiveMtFilter());
            $state['done']++;

            if ($winnerId === $newFilmId) {
                $cur['hi'] = $mid;        // neuer Film besser → links suchen
            } else {
                $cur['lo'] = $mid + 1;    // bestehender Film besser → rechts suchen
            }

            if ($cur['lo'] >= $cur['hi']) {
                array_splice($state['sorted'], $cur['lo'], 0, [$newFilmId]);
                if (!empty($state['to_insert'])) {
                    $next = array_shift($state['to_insert']);
                    $state['current'] = ['film_id' => $next, 'lo' => 0, 'hi' => count($state['sorted'])];
                } else {
                    $state['current'] = null;
                }
            } else {
                $state['current'] = $cur;
            }

            $completed = ($state['current'] === null);
            $counters  = getActivityCounters($userId);

            if ($completed) {
                finalizeSortPositions($db, $userId, $state['sorted']);
                $db->prepare("UPDATE sort_sessions SET status = 'completed', state = ? WHERE id = ?")
                   ->execute([json_encode($state), $sessId]);
                echo json_encode(['ok' => true, 'completed' => true,
                    'progress' => ['done' => $state['done'], 'total' => $state['total']],
                    'hdrDuels' => $counters['totalDuels'], 'hdrFilms' => $counters['uniqueFilms']]);
                exit;
            }

            $db->prepare("UPDATE sort_sessions SET state = ?, prev_state = NULL WHERE id = ?")
               ->execute([json_encode($state), $sessId]);

            $newCur       = $state['current'];
            $nextMid      = (int)floor(($newCur['lo'] + $newCur['hi']) / 2);
            $nextNewFilm  = (int)$newCur['film_id'];
            $nextSorted   = (int)$state['sorted'][$nextMid];

            $stmt2 = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, imdb_id FROM movies WHERE id IN (?, ?)");
            $stmt2->execute([$nextNewFilm, $nextSorted]);
            $mv2 = array_column($stmt2->fetchAll(PDO::FETCH_ASSOC), null, 'id');

            $insertRanking = buildInsertRanking($state, $db);
            $posRanking2   = buildPosRanking($db, $userId);

            // Besser gerankte Film (niedrigere Position) links anzeigen
            $posMap2  = getPosMap($db, $userId);
            $posInsA  = $posMap2[$nextNewFilm] ?? PHP_INT_MAX;
            $posInsB  = $posMap2[$nextSorted]  ?? PHP_INT_MAX;
            if ($posInsB < $posInsA) {
                [$nextNewFilm, $nextSorted] = [$nextSorted, $nextNewFilm];
            }

            $nxtATitle2 = movieTitle($mv2[$nextNewFilm] ?? []);
            $nxtBTitle2 = movieTitle($mv2[$nextSorted]  ?? []);
            $commRanks2 = buildCommRanks($db, $nextNewFilm, $nxtATitle2, $nextSorted, $nxtBTitle2, $userId, getActiveMtFilter());
            echo json_encode([
                'ok'          => true,
                'completed'   => false,
                'progress'    => ['done' => $state['done'], 'total' => $state['total']],
                'duel_result'    => $duelResult,
                'winner_context' => $winnerContext,
                'comm_ranks'     => $commRanks2,
                'hdrDuels'    => $counters['totalDuels'],
                'hdrFilms'    => $counters['uniqueFilms'],
                'next'        => [
                    'a_id'    => $nextNewFilm,
                    'a_title' => $nxtATitle2,
                    'a_year'  => (int)($mv2[$nextNewFilm]['year']  ?? 0),
                    'a_poster'=> moviePosterUrl($mv2[$nextNewFilm] ?? [], 'w500'),
                    'b_id'    => $nextSorted,
                    'b_title' => $nxtBTitle2,
                    'b_year'  => (int)($mv2[$nextSorted]['year']   ?? 0),
                    'b_poster'=> moviePosterUrl($mv2[$nextSorted]  ?? [], 'w500'),
                ],
            ]);
            exit;
        }

        // ── Merge-Sort-Modus ──────────────────────────────────────────────────
        $m = $state['merging'] ?? null;

        if (!$m) { echo json_encode(['ok' => false, 'error' => 'no_merging']); exit; }

        // Validierung: winner muss left[0] oder right[0] sein
        $leftHead  = (int)($m['left'][0]  ?? -1);
        $rightHead = (int)($m['right'][0] ?? -1);

        if ($winnerId !== $leftHead && $winnerId !== $rightHead) {
            echo json_encode(['ok' => false, 'error' => 'stale']); exit;
        }
        $expectedLoser = ($winnerId === $leftHead) ? $rightHead : $leftHead;
        if ($loserId !== $expectedLoser) {
            echo json_encode(['ok' => false, 'error' => 'stale']); exit;
        }

        // ELO + Positions-Rangliste aktualisieren
        $duelResult = buildDuelResult($db, $userId, $winnerId, $loserId);
        recordComparison($userId, $winnerId, $loserId);
        $winnerContext = buildWinnerContext($db, $userId, $winnerId);

        // State vorrücken
        $state = advanceSortState($state, $winnerId);

        // Abgeschlossen?
        $completed = ($state['merging'] === null && count($state['pending']) === 1);

        if ($completed) {
            $finalOrder = $state['pending'][0];
            finalizeSortPositions($db, $userId, $finalOrder);

            $db->prepare("UPDATE sort_sessions SET status = 'completed', state = ? WHERE id = ?")
               ->execute([json_encode($state), $sessId]);

            $counters = getActivityCounters($userId);
            echo json_encode([
                'ok'        => true,
                'completed' => true,
                'progress'  => ['done' => $state['done'], 'total' => $state['total']],
                'hdrDuels'  => $counters['totalDuels'],
                'hdrFilms'  => $counters['uniqueFilms'],
            ]);
            exit;
        }

        // State speichern (aktuellen State als prev_state für Undo sichern)
        $db->prepare("UPDATE sort_sessions SET state = ?, prev_state = ?, last_winner_id = ?, last_loser_id = ? WHERE id = ?")
           ->execute([json_encode($state), $session['state'], $winnerId, $loserId, $sessId]);

        // Nächstes Duell
        $nextA = (int)$state['merging']['left'][0];
        $nextB = (int)$state['merging']['right'][0];

        $stmt = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, imdb_id FROM movies WHERE id IN (?, ?)");
        $stmt->execute([$nextA, $nextB]);
        $movies = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        $mA = $movies[$nextA] ?? [];
        $mB = $movies[$nextB] ?? [];

        $sortRanking = buildSortRanking($state, $db);
        $posRanking  = buildPosRanking($db, $userId);

        // Besser gerankte Film immer links (A-Seite) anzeigen
        $posMap   = getPosMap($db, $userId);
        $posNextA = $posMap[$nextA] ?? PHP_INT_MAX;
        $posNextB = $posMap[$nextB] ?? PHP_INT_MAX;
        if ($posNextB < $posNextA) {
            [$nextA, $nextB, $mA, $mB] = [$nextB, $nextA, $mB, $mA];
        }

        $counters  = getActivityCounters($userId);
        $nxtATitle = movieTitle($mA);
        $nxtBTitle = movieTitle($mB);
        $commRanks = buildCommRanks($db, $nextA, $nxtATitle, $nextB, $nxtBTitle, $userId, getActiveMtFilter());
        echo json_encode([
            'ok'          => true,
            'completed'   => false,
            'progress'    => ['done' => $state['done'], 'total' => $state['total']],
            'duel_result'    => $duelResult,
            'winner_context' => $winnerContext,
            'comm_ranks'     => $commRanks,
            'hdrDuels'       => $counters['totalDuels'],
            'hdrFilms'       => $counters['uniqueFilms'],
            'next'           => [
                'a_id'    => $nextA,
                'a_title' => $nxtATitle,
                'a_year'  => (int)($mA['year']  ?? 0),
                'a_poster'=> moviePosterUrl($mA, 'w500'),
                'b_id'    => $nextB,
                'b_title' => $nxtBTitle,
                'b_year'  => (int)($mB['year']  ?? 0),
                'b_poster'=> moviePosterUrl($mB, 'w500'),
            ],
        ]);
        exit;
    }

    // ── Undo ──────────────────────────────────────────────────────────────────
    if ($action === 'undo') {
        if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

        $stmt = $db->prepare("SELECT id, prev_state, last_winner_id, last_loser_id FROM sort_sessions
            WHERE user_id = ? AND status = 'active' AND media_type = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$userId, activeMtForDb()]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session || !$session['prev_state']) {
            echo json_encode(['ok' => false, 'error' => 'nothing_to_undo']); exit;
        }

        $prevState = json_decode($session['prev_state'], true);
        $sessId    = (int)$session['id'];
        $winnerId  = (int)$session['last_winner_id'];
        $loserId   = (int)$session['last_loser_id'];

        // ELO-Änderung rückgängig machen
        undoLastComparison($userId, $winnerId, $loserId);

        // State auf prev_state zurücksetzen, Undo-Felder leeren
        $db->prepare("UPDATE sort_sessions SET state = ?, prev_state = NULL, last_winner_id = NULL, last_loser_id = NULL WHERE id = ?")
           ->execute([json_encode($prevState), $sessId]);

        // Wiederherzustellendes Duell (left[0] vs right[0] im prev_state)
        $m     = $prevState['merging'];
        $restA = (int)$m['left'][0];
        $restB = (int)$m['right'][0];

        $sortRanking = buildSortRanking($prevState, $db);
        $posRanking  = buildPosRanking($db, $userId);

        // Besser gerankte Film wieder links anzeigen
        $posMap  = getPosMap($db, $userId);
        $posRestA = $posMap[$restA] ?? PHP_INT_MAX;
        $posRestB = $posMap[$restB] ?? PHP_INT_MAX;
        if ($posRestB < $posRestA) {
            [$restA, $restB] = [$restB, $restA];
        }

        $stmt = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, imdb_id FROM movies WHERE id IN (?, ?)");
        $stmt->execute([$restA, $restB]);
        $movies = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        $mA = $movies[$restA] ?? [];
        $mB = $movies[$restB] ?? [];

        echo json_encode([
            'ok'          => true,
            'progress'    => ['done' => $prevState['done'], 'total' => $prevState['total']],
            'canUndo'     => false,
            'sortRanking' => $sortRanking,
            'posRanking'  => $posRanking,
            'restored'    => [
                'a_id'    => $restA,
                'a_title' => movieTitle($mA),
                'a_year'  => (int)($mA['year']  ?? 0),
                'a_poster'=> moviePosterUrl($mA, 'w500'),
                'b_id'    => $restB,
                'b_title' => movieTitle($mB),
                'b_year'  => (int)($mB['year']  ?? 0),
                'b_poster'=> moviePosterUrl($mB, 'w500'),
            ],
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
    exit;
}

// ── Seiten-Zustands-Erkennung ─────────────────────────────────────────────────
$stmt = $db->prepare("SELECT id, film_count, state, (prev_state IS NOT NULL) AS can_undo FROM sort_sessions
    WHERE user_id = ? AND status = 'active' AND media_type = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$userId, activeMtForDb()]);
$activeSession = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT id, state FROM sort_sessions
    WHERE user_id = ? AND status = 'completed' AND media_type = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$userId, activeMtForDb()]);
$completedRow = $stmt->fetch(PDO::FETCH_ASSOC);
$hasCompleted = (bool)$completedRow;

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM user_position_ranking upr
     JOIN movies m ON m.id = upr.movie_id
     WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m')
);
$stmt->execute([$userId]);
$rankedCount = (int)$stmt->fetchColumn();

// Extend-Verfügbarkeit berechnen (Phase 3+ + abgeschlossene Session)
$canExtend        = false;
$alreadySortedCnt = 0;
$availableToAdd   = 0;
if ($hasCompleted && userPhase() >= 3) {
    $extState      = json_decode($completedRow['state'], true);
    $alreadySorted = array_map('intval', $extState['sorted'] ?? $extState['pending'][0] ?? []);
    $alreadySortedCnt = count($alreadySorted);
    $availableToAdd   = max(0, $rankedCount - $alreadySortedCnt);
    $canExtend        = $availableToAdd > 0;
}

$pageState    = 'setup';
$sortState    = null;
$sessId       = 0;
$filmCount    = 0;
$currentA     = null;
$currentB     = null;
$progress     = null;
$pct          = 0;
$canUndo      = false;
$isInsertMode = false;
$sortRanking  = [];
$posRanking   = [];

if ($activeSession) {
    $pageState    = 'active';
    $sessId       = (int)$activeSession['id'];
    $filmCount    = (int)$activeSession['film_count'];
    $sortState    = json_decode($activeSession['state'], true);
    $isInsertMode = ($sortState['mode'] ?? '') === 'insert';

    if ($isInsertMode) {
        $cur = $sortState['current'];
        if ($cur) {
            $mid   = (int)floor(($cur['lo'] + $cur['hi']) / 2);
            $nextA = (int)$cur['film_id'];
            $nextB = (int)$sortState['sorted'][$mid];
            $stmt  = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, imdb_id FROM movies WHERE id IN (?, ?)");
            $stmt->execute([$nextA, $nextB]);
            $movies   = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
            $currentA = $movies[$nextA] ?? null;
            $currentB = $movies[$nextB] ?? null;
        } else {
            $pageState = 'setup'; // Sollte nicht vorkommen
        }
        $sortRanking = buildInsertRanking($sortState, $db);
        $posRanking  = buildPosRanking($db, $userId);
        $canUndo     = false;

        // Besser gerankte Film immer links (A-Seite) anzeigen
        if ($currentA && $currentB) {
            $posMap = getPosMap($db, $userId);
            $posA   = $posMap[(int)$currentA['id']] ?? PHP_INT_MAX;
            $posB   = $posMap[(int)$currentB['id']] ?? PHP_INT_MAX;
            if ($posB < $posA) {
                [$currentA, $currentB] = [$currentB, $currentA];
            }
        }
    } else {
        $m = $sortState['merging'] ?? null;
        if ($m) {
            $nextA = (int)$m['left'][0];
            $nextB = (int)$m['right'][0];
            $stmt  = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, imdb_id FROM movies WHERE id IN (?, ?)");
            $stmt->execute([$nextA, $nextB]);
            $movies   = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
            $currentA = $movies[$nextA] ?? null;
            $currentB = $movies[$nextB] ?? null;
        }
        $sortRanking = buildSortRanking($sortState, $db);
        $posRanking  = buildPosRanking($db, $userId);
        $canUndo     = (bool)$activeSession['can_undo'];

        // Besser gerankte Film immer links (A-Seite) anzeigen
        if ($currentA && $currentB) {
            $posMap = getPosMap($db, $userId);
            $posA   = $posMap[(int)$currentA['id']] ?? PHP_INT_MAX;
            $posB   = $posMap[(int)$currentB['id']] ?? PHP_INT_MAX;
            if ($posB < $posA) {
                [$currentA, $currentB] = [$currentB, $currentA];
            }
        }
    }

    $done     = $sortState['done'];
    $total    = $sortState['total'];
    $pct      = $total > 0 ? round($done / $total * 100, 1) : 0;
    $progress = ['done' => $done, 'total' => $total];
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
body { background: #14325a !important; }
.sort-hero { background: linear-gradient(135deg, #14325a 0%, #1e3d7a 100%); border-bottom: 1px solid rgba(232,184,75,.15); }
.container-xxl.sort-wrap { max-width: 2200px; margin: 0 auto; }
.duel-poster-wrap { max-width: 600px !important; }

/* 3-Spalten-Layout */
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
.duel-side:hover { background: rgba(232,184,75,.1); }
.duel-side:hover .duel-overlay { opacity: 1; }
.duel-side.winner { background: rgba(232,184,75,.18) !important; transform: scale(1.01); }
.duel-side.loser  { opacity: .4; }
.duel-overlay {
    position: absolute; inset: 0;
    background: rgba(232,184,75,.2); border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .2s;
    color: #e8b84b; font-size: 2rem;
}
.duel-title { color: #e0e0e0; font-weight: 600; font-size: .95rem; text-align: center; margin-top: 14px; margin-bottom: 4px; }
.duel-meta  { color: rgba(255,255,255,.4); font-size: .8rem; text-align: center; }
.vs-divider { display: flex; align-items: center; justify-content: center; padding: 0 8px; background: rgba(0,0,0,.3); min-width: 48px; }
.vs-circle  { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a; font-weight: 800; font-size: .85rem; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }

/* Ranking-Panels */
.turnier-ranking-wrap   { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); border-radius: 12px; overflow: hidden; }
.turnier-ranking-header { padding: .75rem 1rem; font-size: .8rem; font-weight: 700; color: #e8b84b; display: flex; align-items: center; gap: .4rem; border-bottom: 1px solid rgba(255,255,255,.08); background: rgba(232,184,75,.06); }
.turnier-ranking-list   { overflow-y: auto; scrollbar-width: thin; scrollbar-color: rgba(232,184,75,.2) transparent; }
.turnier-ranking-list::-webkit-scrollbar       { width: 4px; }
.turnier-ranking-list::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 2px; }
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
.turnier-rank-row { display: flex; align-items: center; gap: .5rem; padding: .4rem .75rem; border-bottom: 1px solid rgba(255,255,255,.05); transition: background .15s; }
.turnier-rank-row:last-child { border-bottom: none; }
.turnier-rank-row:hover      { background: rgba(255,255,255,.04); }
.turnier-rank-row.active-film { background: rgba(232,184,75,.12) !important; }
.turnier-rank-num  { min-width: 1.6rem; font-size: .75rem; font-weight: 700; color: rgba(255,255,255,.4); text-align: right; }
.turnier-rank-num.top { color: #e8b84b; }
.turnier-rank-poster { width: 26px; height: 39px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
.turnier-rank-title  { flex: 1; font-size: .8rem; color: rgba(255,255,255,.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Fortschritt */
.liga-progress  { background: rgba(255,255,255,.05); border-radius: 8px; padding: 16px 20px; }
.progress-track { background: rgba(255,255,255,.08); border-radius: 6px; height: 8px; overflow: hidden; }
.progress-fill  { background: linear-gradient(90deg, #e8b84b, #c4942a); height: 100%; border-radius: 6px; transition: width .4s ease; }

/* Setup */
.setup-card { max-width: 560px; margin: 0 auto; background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 32px; }
.form-ctrl  { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); color: #e0e0e0; border-radius: 8px; padding: 10px 14px; font-size: 1rem; width: 100%; }
.form-ctrl:focus { outline: none; border-color: #e8b84b; background: rgba(255,255,255,.09); }
.btn-gold { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a; font-weight: 700; border: none; border-radius: 8px; padding: 12px 28px; font-size: 1rem; cursor: pointer; transition: opacity .2s; display: inline-flex; align-items: center; justify-content: center; width: 100%; }
.btn-gold:hover:not(:disabled) { opacity: .85; }
.btn-gold:disabled { opacity: .45; cursor: not-allowed; }

/* Abschluss */
.completion-card { max-width: 480px; margin: 0 auto; text-align: center; padding: 40px; background: rgba(255,255,255,.03); border: 1px solid rgba(232,184,75,.3); border-radius: 16px; }
.btn-gold-link { background: linear-gradient(135deg, #e8b84b, #c4942a); color: #1a1a1a !important; font-weight: 700; border-radius: 8px; padding: 12px 28px; text-decoration: none !important; display: inline-block; }
.btn-gold-link:hover { opacity: .85; }
</style>

<main style="padding-top:6px; background:#14325a; min-height:100vh;">

    <!-- Hero -->
    <section class="sort-hero py-4">
        <div class="container-xxl sort-wrap">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1 class="fw-bold mb-1" style="color:#e8b84b; font-size:1.6rem;">
                        <i class="bi bi-sort-numeric-down me-2"></i>Sortieren
                    </h1>
                    <p class="mb-0" style="color:rgba(255,255,255,.5); font-size:.9rem;">
                        <?= $isInsertMode
                            ? 'Insertion Sort – neue Filme werden eingeordnet'
                            : 'Merge Sort – bringe Filme in eine exakte Reihenfolge' ?>
                    </p>
                </div>
                <?php if ($pageState === 'active'): ?>
                <div class="text-end">
                    <div id="hero-done" style="color:#e8b84b; font-size:1.5rem; font-weight:800; line-height:1;"><?= $progress['done'] ?></div>
                    <div style="color:rgba(255,255,255,.4); font-size:.75rem;">von ca. <?= number_format($progress['total'], 0, ',', '.') ?> Duellen</div>
                    <div id="hero-pct" style="color:rgba(232,184,75,.55); font-size:.72rem;"><?= $pct ?>%</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="py-4">
        <div class="container-xxl sort-wrap">

<?php if ($pageState === 'setup'): ?>
<!-- ── ZUSTAND A: Setup ───────────────────────────────────────────────────── -->

            <?php if ($hasCompleted): ?>
            <div class="d-flex align-items-center gap-2 mb-4"
                 style="background:rgba(76,175,80,.12); border:1px solid rgba(76,175,80,.3); color:#81c784; border-radius:8px; padding:14px 18px; font-size:.9rem;">
                <i class="bi bi-check-circle-fill flex-shrink-0"></i>
                <span>Du hast bereits eine Sortierung abgeschlossen! Du kannst jederzeit eine neue starten.</span>
            </div>
            <?php endif; ?>

            <div class="setup-card">
                <h3 class="fw-bold mb-1" style="color:#e0e0e0;">Merge Sort starten</h3>
                <p style="color:rgba(255,255,255,.5); font-size:.9rem; margin-bottom:24px;">
                    Deine Rangliste enthält aktuell
                    <strong style="color:#e8b84b;"><?= $rankedCount ?></strong> Filme.
                    Wähle, wie viele davon in eine exakte Reihenfolge sortiert werden sollen (Top-N nach aktuellem Rang).
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
                        Anzahl Filme <span style="color:rgba(255,255,255,.4);">(10 – <?= $rankedCount ?>)</span>
                    </label>
                    <?php $prefillCount = isset($_GET['count']) ? max(10, min($rankedCount, (int)$_GET['count'])) : min($rankedCount, 10); ?>
                <input type="number" id="film-count" class="form-ctrl"
                           value="<?= $prefillCount ?>" min="10" max="<?= $rankedCount ?>" step="1">
                </div>

                <div class="mb-4 px-1" style="color:rgba(255,255,255,.45); font-size:.85rem; line-height:2;">
                    <div>
                        <i class="bi bi-lightning-charge-fill me-1" style="color:#e8b84b;"></i>
                        Merge Sort: ca. <strong id="sort-duels-display" style="color:#e8b84b;">664</strong> Duelle
                    </div>
                    <div>
                        <i class="bi bi-people-fill me-1" style="color:rgba(255,255,255,.3);"></i>
                        Jeder-gegen-Jeden: <strong id="liga-duels-display" style="color:rgba(255,255,255,.3);">4.950</strong> Duelle
                    </div>
                    <div style="color:rgba(232,184,75,.6); margin-top:2px;">
                        <i class="bi bi-info-circle me-1"></i>
                        Jedes Ergebnis fließt in deine ELO- und Positions-Rangliste ein.
                    </div>
                </div>

                <button id="start-btn" class="btn-gold" type="button">
                    <span id="start-label"><i class="bi bi-play-fill me-2"></i>Sortierung starten</span>
                    <span id="start-spinner" class="d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Wird gestartet …
                    </span>
                </button>
                <?php endif; ?>
            </div>

            <?php if ($canExtend): ?>
            <div class="setup-card mt-4" style="border-color:rgba(232,184,75,.25);">
                <h3 class="fw-bold mb-1" style="color:#e8b84b;">
                    <i class="bi bi-plus-circle me-2"></i>Neue Filme einordnen
                </h3>
                <p style="color:rgba(255,255,255,.5); font-size:.9rem; margin-bottom:20px;">
                    Bereits sortiert: <strong style="color:#e8b84b;"><?= number_format($alreadySortedCnt) ?></strong> Filme &nbsp;·&nbsp;
                    <strong style="color:#e8b84b;"><?= number_format($availableToAdd) ?></strong> weitere verfügbar.<br>
                    Nur die neuen Filme werden per Binärsuche eingeordnet – die bestehende Reihenfolge bleibt erhalten.
                </p>

                <div id="extend-error" class="d-none mb-3"
                     style="background:rgba(244,67,54,.1); border:1px solid rgba(244,67,54,.25); color:#ef9a9a; border-radius:8px; padding:12px 16px; font-size:.9rem;"></div>

                <div class="mb-3">
                    <label style="color:rgba(255,255,255,.7); font-size:.85rem; font-weight:600; display:block; margin-bottom:8px;">
                        Anzahl neue Filme <span style="color:rgba(255,255,255,.4);">(1 – <?= $availableToAdd ?>)</span>
                    </label>
                    <input type="number" id="extend-count" class="form-ctrl"
                           value="<?= min($availableToAdd, 10) ?>" min="1" max="<?= $availableToAdd ?>" step="1">
                </div>

                <button id="extend-btn" class="btn-gold" type="button">
                    <span id="extend-label"><i class="bi bi-plus-circle me-2"></i>Einordnen starten</span>
                    <span id="extend-spinner" class="d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Wird gestartet …
                    </span>
                </button>
            </div>
            <?php endif; ?>

<?php elseif ($pageState === 'active' && $currentA && $currentB): ?>
<!-- ── ZUSTAND B: Aktives Duell ───────────────────────────────────────────── -->
<?php
    $aUrl          = moviePosterUrl($currentA, 'w500');
    $bUrl          = moviePosterUrl($currentB, 'w500');
    $aTitle        = movieTitle($currentA);
    $bTitle        = movieTitle($currentB);
    $initCommRanks = buildCommRanks($db, (int)$currentA['id'], $aTitle,
                                        (int)$currentB['id'], $bTitle, $userId, $mtActive);
?>
            <p class="text-center mb-3" style="color:rgba(255,255,255,.4); font-size:clamp(1.4rem,2.5vw,2.5rem); white-space:nowrap; overflow:hidden;">
                Welchen Film schaust du dir lieber an?
            </p>

            <div class="liga-3col">

                <!-- ── Links: Meine Rangliste ───────────────────────────── -->
                <div class="liga-side">
                    <div class="turnier-ranking-wrap">
                        <div class="turnier-ranking-header">
                            <i class="bi bi-list-ol"></i> <?= $mtActive === 'tv' ? 'Meine Rangliste Serien' : ($mtActive === 'movie' ? 'Meine Rangliste Filme' : 'Meine Rangliste') ?>
                        </div>
                        <div class="turnier-ranking-list" id="pos-ranking-list">
                            <?php if (empty($posRanking)): ?>
                            <div class="turnier-rank-row" style="color:rgba(255,255,255,.3); font-size:.75rem; justify-content:center;">
                                Noch keine Einträge
                            </div>
                            <?php else: ?>
                            <?php foreach ($posRanking as $r): ?>
                            <div class="turnier-rank-row" data-film-id="<?= (int)$r['id'] ?>">
                                <span class="turnier-rank-num <?= $r['pos'] <= 3 ? 'top' : '' ?>"><?= (int)$r['pos'] ?></span>
                                <img src="<?= e($r['display_poster']) ?>" class="turnier-rank-poster"
                                     width="26" height="39" loading="lazy"
                                     onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
                                <div class="turnier-rank-title"><a href="/film.php?id=<?= (int)$r['id'] ?>" class="film-link"><?= e($r['title']) ?></a></div>
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

                <!-- ── Mitte: Fortschritt + Duell ───────────────────────── -->
                <div class="liga-center">

                    <!-- Fortschrittsbalken -->
                    <div class="liga-progress mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span style="color:rgba(255,255,255,.6); font-size:.85rem;">Fortschritt</span>
                            <span id="progress-text" style="color:#e8b84b; font-size:.85rem; font-weight:600;">
                                <?= number_format($progress['done'], 0, ',', '.') ?> /
                                ca. <?= number_format($progress['total'], 0, ',', '.') ?> Duelle
                                (<?= $pct ?>%)
                            </span>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill" id="progress-fill" style="width:<?= min(100, $pct) ?>%"></div>
                        </div>
                    </div>

                    <!-- Duel-Bereich -->
                    <div id="duel-section">
                        <div class="duel-arena" id="duel-arena">
                            <div class="duel-side" id="movie-a"
                                 data-id="<?= (int)$currentA['id'] ?>">
                                <div class="duel-poster-wrap">
                                    <img class="duel-poster" fetchpriority="high" decoding="async" src="<?= e($aUrl) ?>"
                                         alt="<?= e($aTitle) ?>"
                                         loading="lazy" onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                                    <div class="duel-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
                                </div>
                                <div class="duel-title"><?= e($aTitle) ?></div>
                                <div class="duel-meta"><?= (int)$currentA['year'] ?></div>
                            </div>

                            <div class="vs-divider"><div class="vs-circle">VS</div></div>

                            <div class="duel-side" id="movie-b"
                                 data-id="<?= (int)$currentB['id'] ?>">
                                <div class="duel-poster-wrap">
                                    <img class="duel-poster" fetchpriority="high" decoding="async" src="<?= e($bUrl) ?>"
                                         alt="<?= e($bTitle) ?>"
                                         loading="lazy" onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                                    <div class="duel-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
                                </div>
                                <div class="duel-title"><?= e($bTitle) ?></div>
                                <div class="duel-meta"><?= (int)$currentB['year'] ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Undo-Button / Insert-Info -->
                    <div class="text-center mt-3">
                        <?php if ($isInsertMode): ?>
                        <span style="color:rgba(255,255,255,.3); font-size:.75rem;">
                            <i class="bi bi-info-circle me-1"></i>
                            <?= count($sortState['to_insert'] ?? []) + 1 ?> neue Filme werden eingeordnet
                        </span>
                        <?php else: ?>
                        <button id="undo-btn" <?= $canUndo ? '' : 'disabled' ?>
                                class="btn btn-link p-0 text-decoration-none"
                                style="color:rgba(255,255,255,.35); font-size:.8rem;">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Letztes Duell rückgängig
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Abschluss (JS-gesteuert) -->
                    <div id="completion-overlay" style="display:none; padding-top:24px;">
                        <div class="completion-card">
                            <div style="font-size:3.5rem; margin-bottom:16px;">🎯</div>
                            <?php if ($isInsertMode): ?>
                            <h2 class="fw-bold mb-2" style="color:#e8b84b;">Einordnung abgeschlossen!</h2>
                            <p style="color:rgba(255,255,255,.6); margin-bottom:24px;">
                                Die neuen Filme wurden in deine Sortier-Rangliste eingeordnet.<br>
                                Du kannst jederzeit weitere Filme hinzufügen.
                            </p>
                            <?php else: ?>
                            <h2 class="fw-bold mb-2" style="color:#e8b84b;">Sortierung abgeschlossen!</h2>
                            <p style="color:rgba(255,255,255,.6); margin-bottom:24px;">
                                <strong style="color:#e8b84b;"><?= $filmCount ?></strong> Filme wurden
                                in exakter Reihenfolge sortiert. Deine Rangliste wurde aktualisiert.
                            </p>
                            <?php endif; ?>
                            <a href="/rangliste.php?tab=sort" class="btn-gold-link">
                                <i class="bi bi-list-ol me-2"></i>Zur Rangliste
                            </a>
                        </div>
                    </div>

                </div><!-- /.liga-center -->

                <!-- ── Rechts: Statistiken ──────────────────────────────── -->
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
                        <!-- Community-Ranking -->
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
                        <!-- Meine Rangliste -->
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
                    <p class="text-center mt-2" style="font-size:.75rem;">
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Sortierung wirklich abbrechen? Der bisherige Fortschritt geht verloren.');">
                            <input type="hidden" name="action"     value="abort">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <button class="btn btn-link p-0 text-decoration-none"
                                    style="color:rgba(255,255,255,.3); font-size:.75rem;">
                                <i class="bi bi-x-circle me-1"></i>Sortierung abbrechen
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
const CSRF_TOKEN     = <?= json_encode(csrfToken()) ?>;
const IMG_BASE       = <?= json_encode(rtrim(TMDB_IMAGE_BASE, '/')) ?>;
const IS_INSERT_MODE = <?= json_encode($isInsertMode) ?>;
const INIT_COMM_RANKS = <?= json_encode($initCommRanks ?? null) ?>;

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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
if (INIT_COMM_RANKS) document.addEventListener('DOMContentLoaded', () => updateDuelStats(null, null, INIT_COMM_RANKS));

function updateHdrCounters(totalDuels, uniqueFilms) {
    const dc = document.getElementById('hdr-duels-count');
    const fc = document.getElementById('hdr-films-count');
    if (dc && totalDuels !== undefined) dc.textContent = totalDuels.toLocaleString('de-DE');
    if (fc && uniqueFilms !== undefined) fc.textContent = uniqueFilms;
}

<?php if ($pageState === 'setup' && $rankedCount >= 10): ?>
// ── Setup-Logik ───────────────────────────────────────────────────────────────
(function () {
    const fcInput     = document.getElementById('film-count');
    const sortDisp    = document.getElementById('sort-duels-display');
    const ligaDisp    = document.getElementById('liga-duels-display');
    const startBtn    = document.getElementById('start-btn');
    const startLbl    = document.getElementById('start-label');
    const startSpin   = document.getElementById('start-spinner');
    const errorMsg    = document.getElementById('error-msg');
    const maxFilms    = <?= $rankedCount ?>;

    function fmt(n) { return n.toLocaleString('de-DE'); }

    function updateHint() {
        const n = Math.max(0, parseInt(fcInput.value) || 0);
        sortDisp.textContent = fmt(n < 2 ? 0 : Math.ceil(n * Math.log2(n)));
        ligaDisp.textContent = fmt(Math.floor(n * (n - 1) / 2));
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
        fd.append('action',     'start');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('film_count', n);

        try {
            const res  = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                window.location.reload();
            } else {
                errorMsg.textContent = 'Fehler: ' + (data.error ?? 'unbekannt');
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
})();
<?php endif; ?>

<?php if ($pageState === 'active'): ?>
// ── Duell-Logik ───────────────────────────────────────────────────────────────
(function () {
    const arena      = document.getElementById('duel-arena');
    const duelSec    = document.getElementById('duel-section');
    const completion = document.getElementById('completion-overlay');
    const progFill   = document.getElementById('progress-fill');
    const progText   = document.getElementById('progress-text');
    const heroDone   = document.getElementById('hero-done');
    const heroPct    = document.getElementById('hero-pct');
    const sortList   = document.getElementById('sort-ranking-list');
    const posList    = document.getElementById('pos-ranking-list');
    const undoBtn    = document.getElementById('undo-btn'); // null in insert mode

    let voting = false;
    let votingTimer = null;

    function setVoting(v) {
        voting = v;
        clearTimeout(votingTimer);
        if (v) votingTimer = setTimeout(() => { voting = false; }, 6000);
    }

    function fmt(n) { return n.toLocaleString('de-DE'); }

    function posterSrc(path) {
        if (!path) return 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?';
        return path.startsWith('http') ? path : IMG_BASE + path;
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function setCard(side, m) {
        side.querySelector('.duel-poster').src = posterSrc(m.poster);
        side.querySelector('.duel-poster').alt = m.title;
        side.querySelector('.duel-title').textContent = m.title;
        side.querySelector('.duel-meta').textContent  = m.year || '';
        side.dataset.id = m.id;
        side.classList.remove('winner', 'loser');
    }

    function updateProgress(done, total) {
        const pct = total > 0 ? Math.min(100, done / total * 100).toFixed(1) : 0;
        progFill.style.width = pct + '%';
        progText.textContent = `${fmt(done)} / ca. ${fmt(total)} Duelle (${pct}%)`;
        if (heroDone) heroDone.textContent = fmt(done);
        if (heroPct)  heroPct.textContent  = pct + '%';
    }

    function highlightRankings(aId, bId) {
        [sortList, posList].forEach(list => {
            if (!list) return;
            list.querySelectorAll('[data-film-id]').forEach(r => {
                const id = parseInt(r.dataset.filmId);
                r.classList.toggle('active-film', id === aId || id === bId);
            });
            const first = list.querySelector('.active-film');
            if (first) first.scrollIntoView({ block: 'nearest', behavior: 'instant' });
        });
    }

    const PH = 'https://placehold.co/26x39/1e3a5f/e8b84b?text=?';

    function updateRankingList(list, ranking) {
        if (!list || !ranking) return;
        const activeIds = new Set(
            [...list.querySelectorAll('.active-film')].map(r => parseInt(r.dataset.filmId))
        );
        list.innerHTML = ranking.map(r => {
            const top = r.pos <= 3 ? ' top' : '';
            const src = r.display_poster || (r.poster_path ? IMG_BASE + r.poster_path : PH);
            const act = activeIds.has(r.id) ? ' active-film' : '';
            return `<div class="turnier-rank-row${act}" data-film-id="${r.id}">
                <span class="turnier-rank-num${top}">${r.pos}</span>
                <img src="${escHtml(src)}" class="turnier-rank-poster" width="26" height="39" loading="lazy" onerror="this.src='${PH}'">
                <div class="turnier-rank-title">${escHtml(r.title)}</div>
            </div>`;
        }).join('');
        const first = list.querySelector('.active-film');
        if (first) first.scrollIntoView({ block: 'nearest', behavior: 'instant' });
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
        castVote(parseInt(side.dataset.id), parseInt(other.dataset.id));
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();
        if (voting) return;
        const winSide  = document.getElementById(e.key === 'ArrowLeft' ? 'movie-a' : 'movie-b');
        const loseSide = document.getElementById(e.key === 'ArrowLeft' ? 'movie-b' : 'movie-a');
        if (winSide && loseSide) castVote(parseInt(winSide.dataset.id), parseInt(loseSide.dataset.id));
    });

    // ── Abstimmung ────────────────────────────────────────────────────────────
    async function castVote(winnerId, loserId) {
        setVoting(true);
        const sideA   = document.getElementById('movie-a');
        const sideB   = document.getElementById('movie-b');
        const winSide  = parseInt(sideA.dataset.id) === winnerId ? sideA : sideB;
        const loseSide = winSide === sideA ? sideB : sideA;
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
            if (!data.ok) { setVoting(false); location.reload(); return; }

            updateProgress(data.progress.done, data.progress.total);
            updateHdrCounters(data.hdrDuels, data.hdrFilms);
            if (undoBtn && !IS_INSERT_MODE) undoBtn.disabled = false;

            if (data.completed) {
                setVoting(false);
                setTimeout(() => { duelSec.style.display = 'none'; completion.style.display = ''; }, 600);
                return;
            }

            updateDuelStats(data.duel_result, data.winner_context, data.comm_ranks);
            updateRankingList(posList, data.posRanking);

            setTimeout(() => {
                try {
                    const n = data.next;
                    setCard(sideA, { id: n.a_id, title: n.a_title, year: n.a_year, poster: n.a_poster });
                    setCard(sideB, { id: n.b_id, title: n.b_title, year: n.b_year, poster: n.b_poster });
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
    if (undoBtn) undoBtn.addEventListener('click', async () => {
        if (voting || undoBtn.disabled) return;
        setVoting(true);
        undoBtn.disabled = true;

        const fd = new FormData();
        fd.append('action',     'undo');
        fd.append('csrf_token', CSRF_TOKEN);

        try {
            const res  = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) { location.reload(); return; }

            updateProgress(data.progress.done, data.progress.total);
            undoBtn.disabled = !data.canUndo;

            updateRankingList(posList, data.posRanking);

            const r = data.restored;
            const sideA = document.getElementById('movie-a');
            const sideB = document.getElementById('movie-b');
            setCard(sideA, { id: r.a_id, title: r.a_title, year: r.a_year, poster: r.a_poster });
            setCard(sideB, { id: r.b_id, title: r.b_title, year: r.b_year, poster: r.b_poster });
            highlightRankings(r.a_id, r.b_id);
            setVoting(false);
        } catch {
            location.reload();
        }
    });
})();
<?php endif; ?>

<?php if ($pageState === 'setup' && $canExtend): ?>
// ── Extend-Logik ──────────────────────────────────────────────────────────────
(function () {
    const extCount  = document.getElementById('extend-count');
    const extBtn    = document.getElementById('extend-btn');
    const extLbl    = document.getElementById('extend-label');
    const extSpin   = document.getElementById('extend-spinner');
    const extErr    = document.getElementById('extend-error');
    const maxAdd    = <?= (int)$availableToAdd ?>;

    extBtn.addEventListener('click', async () => {
        const n = parseInt(extCount.value) || 0;
        if (n < 1 || n > maxAdd) {
            extErr.textContent = `Bitte eine Zahl zwischen 1 und ${maxAdd} eingeben.`;
            extErr.classList.remove('d-none');
            return;
        }
        extErr.classList.add('d-none');
        extLbl.classList.add('d-none');
        extSpin.classList.remove('d-none');
        extBtn.disabled = true;

        const fd = new FormData();
        fd.append('action',     'extend');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('film_count', n);

        try {
            const res  = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                window.location.reload();
            } else {
                extErr.textContent = 'Fehler: ' + (data.error ?? 'unbekannt');
                extErr.classList.remove('d-none');
                extLbl.classList.remove('d-none');
                extSpin.classList.add('d-none');
                extBtn.disabled = false;
            }
        } catch {
            extErr.textContent = 'Netzwerkfehler. Bitte nochmals versuchen.';
            extErr.classList.remove('d-none');
            extLbl.classList.remove('d-none');
            extSpin.classList.add('d-none');
            extBtn.disabled = false;
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
