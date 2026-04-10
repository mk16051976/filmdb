<?php
$pageTitle = 'Film einordnen – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$userId = (int)$_SESSION['user_id'];
$db     = getDB();

// ── Tabelle sicherstellen ─────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS film_insert_sessions (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED NOT NULL,
    film_id            INT UNSIGNED NOT NULL,
    probe_pos          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    step               SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    consec_losses      TINYINT  UNSIGNED NOT NULL DEFAULT 0,
    total_ranked       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_opp_id        INT UNSIGNED NULL,
    status             ENUM('active','done') NOT NULL DEFAULT 'active',
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
// Migration bestehender Tabellen
try {
    $db->exec("ALTER TABLE film_insert_sessions ADD COLUMN IF NOT EXISTS last_opp_id INT UNSIGNED NULL");
} catch (\PDOException $e) {}
$db->exec("ALTER TABLE film_insert_sessions ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NOT NULL DEFAULT 'movie'");

// Bereits verwendete Gegner pro Session
$db->exec("CREATE TABLE IF NOT EXISTS film_insert_opponents (
    session_id INT UNSIGNED NOT NULL,
    movie_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (session_id, movie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Hilfsfunktionen ───────────────────────────────────────────────────────────

/**
 * Gegner-Rang-Bereich basierend auf der aktuellen Film-Position.
 * Gibt [minRank, maxRank] zurück.
 */
function opponentRange(int $pos): array {
    if ($pos <= 2)    return [1, 1];
    if ($pos <= 10)   return [1, 2];
    if ($pos <= 50)   return [1, 10];
    if ($pos <= 100)  return [1, 50];
    if ($pos <= 500)  return [1, 100];
    if ($pos <= 2000) return [1, 500];
    if ($pos <= 5000) return [1, 1000];
    return [1, 5000];
}

/**
 * Aktuellen Rang des Films in Meine Rangliste.
 * Gibt 0 zurück wenn der Film noch nicht eingeordnet ist.
 */
function filmPosition(PDO $db, int $userId, int $filmId): int {
    $s = $db->prepare("SELECT position FROM user_position_ranking WHERE user_id = ? AND movie_id = ?");
    $s->execute([$userId, $filmId]);
    return (int)($s->fetchColumn() ?: 0);
}

/**
 * Gegner-Film holen:
 *   1. Zufallszahl im Bereich [min, max] würfeln
 *   2. Gegner-Rang = aktuelle Position − Zufallszahl
 *   3. Film holen der exakt auf diesem Rang sitzt
 *   4. Kein Film darf in derselben Session zweimal als Gegner auftreten
 *
 * Beispiel: Film auf Rang 150, Bereich [1,100] → würfle 47 → Gegner auf Rang 103
 * Gibt null zurück wenn Film auf Rang 1 ist oder kein Gegner mehr verfügbar.
 */
function nextOpponent(PDO $db, int $userId, int $filmId, int $totalRanked, int $sessionId): ?array {
    $curPos = filmPosition($db, $userId, $filmId);

    // Film auf Rang 1 → Einordnung abgeschlossen
    if ($curPos === 1) return null;

    // Wenn Film noch nicht in Rangliste → Startposition = Ende
    $effectivePos = $curPos > 0 ? $curPos : ($totalRanked + 1);

    [$minRoll, $maxRoll] = opponentRange($effectivePos);
    // Zufallszahl darf nicht größer sein als effectivePos - 1 (sonst wäre Gegner ≤ 0)
    $maxRoll = min($maxRoll, $effectivePos - 1);
    if ($maxRoll < $minRoll) return null;

    // Bereits genutzte Gegner dieser Session laden
    $usedStmt = $db->prepare("SELECT movie_id FROM film_insert_opponents WHERE session_id = ?");
    $usedStmt->execute([$sessionId]);
    $usedIds  = $usedStmt->fetchAll(PDO::FETCH_COLUMN);
    $exclude  = array_flip(array_merge([$filmId], array_map('intval', $usedIds)));

    // Zufallszahl würfeln → vom aktuellen Rang abziehen
    $roll       = rand($minRoll, $maxRoll);
    $targetRank = $effectivePos - $roll;   // immer >= 1

    // Film an der Zielposition holen.
    // Falls dort ein bereits genutzter Film sitzt,
    // spiralförmig benachbarte Positionen suchen (innerhalb [1, effectivePos-1])
    $offsets = [0];
    $spread  = $effectivePos - 1;   // gesamten erlaubten Bereich absuchen
    for ($d = 1; $d <= $spread; $d++) {
        $offsets[] =  $d;
        $offsets[] = -$d;
    }

    $s = $db->prepare("
        SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, upr.position AS rank_pos
        FROM user_position_ranking upr
        JOIN movies m ON m.id = upr.movie_id
        WHERE upr.user_id = ? AND upr.position = ?
        LIMIT 1
    ");

    foreach ($offsets as $off) {
        $pos = $targetRank + $off;
        if ($pos < 1 || $pos >= $effectivePos) continue;
        $s->execute([$userId, $pos]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row && !isset($exclude[(int)$row['id']])) {
            $row['range'] = [$minRoll, $maxRoll];
            $row['roll']  = $roll;
            return $row;
        }
    }
    return null;
}

// ── AJAX: Filmsuche ───────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }

    $stmt = $db->prepare(
        "SELECT id, title, year, poster_path, imdb_id, director
         FROM movies WHERE title LIKE ? ORDER BY year DESC LIMIT 20"
    );
    $stmt->execute(['%' . $q . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['poster_url'] = posterUrl($r['poster_path'], $r['imdb_id'] ?? null);
    }
    echo json_encode($rows);
    exit;
}

// ── AJAX: Vote ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'vote') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

        $winnerId = (int)($_POST['winner_id'] ?? 0);
        $loserId  = (int)($_POST['loser_id']  ?? 0);

        $sessStmt = $db->prepare(
            "SELECT * FROM film_insert_sessions
             WHERE user_id=? AND status='active' AND media_type=? ORDER BY created_at DESC LIMIT 1"
        );
        $sessStmt->execute([$userId, activeMtForDb()]);
        $session = $sessStmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) { echo json_encode(['ok' => false, 'error' => 'no_session']); exit; }

        $sessId       = (int)$session['id'];
        $filmId       = (int)$session['film_id'];
        $totalRanked  = (int)$session['total_ranked'];
        $consecLosses = (int)$session['consec_losses'];

        // Validierung: Einer der IDs muss der gesuchte Film sein
        if (!in_array($filmId, [$winnerId, $loserId])) {
            echo json_encode(['ok' => false, 'error' => 'invalid_ids']); exit;
        }

        $currentOppId = ($winnerId === $filmId) ? $loserId : $winnerId;
        $selectedWon  = ($winnerId === $filmId);

        // ELO + Meine Rangliste aktualisieren
        recordComparison($userId, $winnerId, $loserId);

        // Gegner als "verwendet" markieren (nie wieder in dieser Session)
        $db->prepare("INSERT IGNORE INTO film_insert_opponents (session_id, movie_id) VALUES (?, ?)")
           ->execute([$sessId, $currentOppId]);

        // Aufeinanderfolgende Niederlagen tracken
        $consecLosses = $selectedWon ? 0 : $consecLosses + 1;

        // Aktuelle Position NACH dem Duell
        $filmRank = filmPosition($db, $userId, $filmId);

        // Stop: 7 aufeinanderfolgende Niederlagen ODER Rang 1 erreicht
        $done = ($consecLosses >= 7) || ($filmRank === 1 && $filmRank > 0);

        $db->prepare(
            "UPDATE film_insert_sessions
             SET probe_pos=?, consec_losses=?, status=?
             WHERE id=?"
        )->execute([
            $filmRank ?: (int)$session['probe_pos'],
            $consecLosses,
            $done ? 'done' : 'active',
            $sessId,
        ]);

        $counters = getActivityCounters($userId);

        if ($done) {
            echo json_encode([
                'ok'         => true,
                'done'       => true,
                'finalPos'   => $filmRank,
                'totalRanked'=> $totalRanked,
                'stopReason' => $filmRank === 1 ? 'rank1' : 'losses',
                'hdrDuels'   => $counters['totalDuels'],
                'hdrFilms'   => $counters['uniqueFilms'],
            ]);
            exit;
        }

        // Nächster Gegner basierend auf aktueller Position
        $next = nextOpponent($db, $userId, $filmId, $totalRanked, $sessId);
        if (!$next) {
            $db->prepare("UPDATE film_insert_sessions SET status='done' WHERE id=?")
               ->execute([$sessId]);
            echo json_encode([
                'ok'         => true,
                'done'       => true,
                'finalPos'   => $filmRank,
                'totalRanked'=> $totalRanked,
                'stopReason' => $filmRank === 1 ? 'rank1' : 'no_opponent',
                'hdrDuels'   => $counters['totalDuels'],
                'hdrFilms'   => $counters['uniqueFilms'],
            ]);
            exit;
        }

        echo json_encode([
            'ok'           => true,
            'done'         => false,
            'consecLosses' => $consecLosses,
            'filmRank'     => $filmRank,
            'totalRanked'  => $totalRanked,
            'oppRange'     => $next['range'],
            'hdrDuels'     => $counters['totalDuels'],
            'hdrFilms'     => $counters['uniqueFilms'],
            'next' => [
                'id'    => (int)$next['id'],
                'title' => movieTitle($next),
                'year'  => (int)$next['year'],
                'poster'=> moviePosterUrl($next, 'w500'),
                'rank'  => (int)$next['rank_pos'],
            ],
        ]);
        exit;
    }

    // Session starten (film_id per POST)
    if ($action === 'start' && csrfValid()) {
        $filmId = (int)($_POST['film_id'] ?? 0);
        if (!$filmId) { header('Location: /film-einordnen.php'); exit; }

        // Anzahl gerankter Filme (typgefiltert)
        $s = $db->prepare(
            "SELECT COUNT(*) FROM user_position_ranking upr
             JOIN movies m ON m.id = upr.movie_id
             WHERE upr.user_id=?" . seriesSqlFilter('m') . moviesSqlFilter('m')
        );
        $s->execute([$userId]);
        $totalRanked = (int)$s->fetchColumn();

        if ($totalRanked < 2) {
            header('Location: /film-einordnen.php?error=too_few'); exit;
        }

        // Startposition: aktueller Rang des Films (oder Ende der Liste)
        $startPos = filmPosition($db, $userId, $filmId);
        if ($startPos === 0) $startPos = $totalRanked + 1;

        $db->prepare("UPDATE film_insert_sessions SET status='done' WHERE user_id=? AND status='active' AND media_type=?")
           ->execute([$userId, activeMtForDb()]);
        $db->prepare(
            "INSERT INTO film_insert_sessions (user_id, film_id, probe_pos, step, consec_losses, total_ranked, media_type)
             VALUES (?,?,?,1,0,?,?)"
        )->execute([$userId, $filmId, $startPos, $totalRanked, activeMtForDb()]);

        header('Location: /film-einordnen.php'); exit;
    }

    if ($action === 'stop' && csrfValid()) {
        // Session(s) beenden und zugehörige Gegner-Liste aufräumen
        $active = $db->prepare("SELECT id FROM film_insert_sessions WHERE user_id=? AND status='active' AND media_type=?");
        $active->execute([$userId, activeMtForDb()]);
        foreach ($active->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $db->prepare("DELETE FROM film_insert_opponents WHERE session_id=?")->execute([$sid]);
        }
        $db->prepare("UPDATE film_insert_sessions SET status='done' WHERE user_id=? AND status='active' AND media_type=?")
           ->execute([$userId, activeMtForDb()]);
        header('Location: /film-einordnen.php'); exit;
    }
}

// ── Seiten-Zustand ────────────────────────────────────────────────────────────
$sessStmt = $db->prepare(
    "SELECT * FROM film_insert_sessions WHERE user_id=? AND status='active' AND media_type=? ORDER BY created_at DESC LIMIT 1"
);
$sessStmt->execute([$userId, activeMtForDb()]);
$activeSession = $sessStmt->fetch(PDO::FETCH_ASSOC);

$isActive    = (bool)$activeSession;
$selectedFilm = null;
$opponent    = null;
$sessionState = [];

if ($isActive) {
    $filmId = (int)$activeSession['film_id'];
    $fStmt  = $db->prepare("SELECT id, title, title_en, year, poster_path, poster_path_en, imdb_id FROM movies WHERE id=?");
    $fStmt->execute([$filmId]);
    $selectedFilm = $fStmt->fetch(PDO::FETCH_ASSOC);

    $sessionState = [
        'consec_losses' => (int)$activeSession['consec_losses'],
        'total_ranked'  => (int)$activeSession['total_ranked'],
    ];

    // Aktueller Rang des Films und Gegner-Bereich
    $filmRankCurrent = filmPosition($db, $userId, $filmId);
    $effectivePos    = $filmRankCurrent > 0 ? $filmRankCurrent : ((int)$activeSession['total_ranked'] + 1);
    [$rangeMin, $rangeMax] = opponentRange($effectivePos);
    $rangeMax = min($rangeMax, (int)$activeSession['total_ranked']);

    $opponent = nextOpponent($db, $userId, $filmId, (int)$activeSession['total_ranked'], (int)$activeSession['id']);
    if (!$opponent || !$selectedFilm) {
        $db->prepare("UPDATE film_insert_sessions SET status='done' WHERE id=?")
           ->execute([(int)$activeSession['id']]);
        $isActive = false;
    }
}

// Anzahl gerankter Filme/Serien für Hinweis im Setup (typgefiltert)
$totalRankedCount = 0;
try {
    $s = $db->prepare(
        "SELECT COUNT(*) FROM user_position_ranking upr
         JOIN movies m ON m.id = upr.movie_id
         WHERE upr.user_id=?" . seriesSqlFilter('m') . moviesSqlFilter('m')
    );
    $s->execute([$userId]);
    $totalRankedCount = (int)$s->fetchColumn();
} catch (\PDOException $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body { background: #14325a !important; }

    .fe-wrap       { max-width: 900px;  margin: 0 auto; padding: 0 1rem; }
    .fe-wrap-wide  { max-width: 1100px; margin: 0 auto; padding: 0 1rem; }

    /* Suchfeld */
    .search-box {
        background: rgba(255,255,255,.07);
        border: 1.5px solid rgba(255,255,255,.18);
        border-radius: 10px;
        color: #e0e0e0;
        padding: .65rem 1rem .65rem 2.8rem;
        font-size: 1rem;
        width: 100%;
        transition: border-color .18s;
    }
    .search-box:focus { outline: none; border-color: #e8b84b; }
    .search-icon {
        position: absolute; left: .9rem; top: 50%; transform: translateY(-50%);
        color: rgba(255,255,255,.35); font-size: 1rem; pointer-events: none;
    }

    /* Suchergebnisse */
    .result-card {
        display: flex; align-items: center; gap: .9rem;
        padding: .65rem 1rem;
        border-bottom: 1px solid rgba(255,255,255,.05);
        cursor: pointer; transition: background .15s;
    }
    .result-card:hover { background: rgba(232,184,75,.08); }
    .result-card:last-child { border-bottom: none; }
    .result-poster {
        width: 54px; height: 81px; object-fit: cover;
        border-radius: 5px; flex-shrink: 0;
    }
    .result-title { color: #e0e0e0; font-weight: 600; font-size: .9rem; }
    .result-meta  { color: rgba(255,255,255,.35); font-size: .78rem; }
    .result-select-btn {
        margin-left: auto; flex-shrink: 0;
        background: rgba(232,184,75,.15); border: 1px solid rgba(232,184,75,.3);
        color: #e8b84b; border-radius: 6px; padding: 3px 12px; font-size: .78rem;
        cursor: pointer; transition: all .15s;
    }
    .result-select-btn:hover { background: rgba(232,184,75,.3); }

    /* Duel-Bereich */
    .duel-2col {
        display: grid;
        grid-template-columns: 1fr 60px 1fr;
        gap: 1rem; align-items: stretch;
    }
    @media (max-width: 600px) { .duel-2col { grid-template-columns: 1fr; } }

    .duel-card {
        background: rgba(255,255,255,.04);
        border: 2px solid rgba(255,255,255,.1);
        border-radius: 14px; overflow: hidden;
        cursor: pointer; transition: all .2s; position: relative;
    }
    .duel-card:hover  { border-color: rgba(232,184,75,.6); background: rgba(232,184,75,.07); transform: translateY(-3px); }
    .duel-card.winner { border-color: #4caf50; background: rgba(76,175,80,.12); }
    .duel-card.loser  { border-color: rgba(244,67,54,.35); opacity: .5; }
    .duel-card.fixed  { border-color: rgba(232,184,75,.35); } /* gesuchter Film */

    .duel-card .card-poster { width: 100%; aspect-ratio: 2/3; object-fit: cover; display: block; }
    .duel-card .card-overlay {
        position: absolute; inset: 0;
        background: linear-gradient(to bottom, transparent 55%, rgba(0,0,0,.65));
        display: flex; align-items: center; justify-content: center;
        opacity: 0; transition: opacity .2s; font-size: 2.5rem; color: #e8b84b;
    }
    .duel-card:hover .card-overlay { opacity: 1; }
    .duel-card .card-label {
        position: absolute; top: 8px; left: 8px;
        background: rgba(232,184,75,.9); color: #1a1a1a;
        font-size: .65rem; font-weight: 800; padding: 2px 7px; border-radius: 4px;
        text-transform: uppercase; letter-spacing: .04em;
    }
    .duel-card .card-title { padding: .6rem .7rem .15rem; color: #e0e0e0; font-size: .88rem; font-weight: 700; line-height: 1.3; }
    .duel-card .card-meta  { padding: 0 .7rem .7rem; color: rgba(255,255,255,.35); font-size: .78rem; }

    .vs-col { display: flex; align-items: center; justify-content: center; }
    .vs-circle {
        width: 44px; height: 44px; border-radius: 50%;
        background: rgba(232,184,75,.12); border: 2px solid rgba(232,184,75,.3);
        color: #e8b84b; font-weight: 900; font-size: .9rem;
        display: flex; align-items: center; justify-content: center;
    }

    /* Verlust-Anzeige */
    .loss-bar {
        display: flex; gap: 5px; justify-content: center; align-items: center;
    }
    .loss-dot {
        width: 12px; height: 12px; border-radius: 50%;
        background: rgba(255,255,255,.12); border: 1.5px solid rgba(255,255,255,.2);
        transition: all .3s;
    }
    .loss-dot.filled { background: #ef5350; border-color: #ef5350; }

    /* Abschluss */
    .done-card {
        background: rgba(76,175,80,.07); border: 1px solid rgba(76,175,80,.2);
        border-radius: 16px; padding: 2.5rem; text-align: center;
    }

    .btn-gold {
        background: linear-gradient(135deg,#e8b84b,#d4a030);
        color: #1a1a1a; font-weight: 700; border: none;
        border-radius: 10px; padding: .65rem 2rem;
        font-size: .95rem; cursor: pointer; transition: all .18s;
    }
    .btn-gold:hover { background: linear-gradient(135deg,#f0c660,#e8b84b); }

    ::-webkit-scrollbar { width: 4px; }
    ::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 2px; }
</style>

<main style="padding-top:6px; background:#14325a; min-height:100vh;">

    <!-- Hero -->
    <section style="background:linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom:1px solid rgba(232,184,75,.15); padding:1.5rem 0;">
        <div class="container-xxl fe-wrap">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1 class="fw-bold mb-1" style="color:#e8b84b; font-size:1.6rem;">
                        <i class="bi bi-search-heart me-2"></i>Film einordnen
                    </h1>
                    <p class="mb-0" style="color:rgba(255,255,255,.45); font-size:.9rem;">
                        Suche einen Film – er wird per Duell in Meine Rangliste eingeordnet
                    </p>
                </div>
                <?php if ($isActive): ?>
                <div class="text-end d-flex gap-3">
                    <?php if ($filmRankCurrent > 0): ?>
                    <div>
                        <div style="color:#e8b84b; font-size:1.5rem; font-weight:800; line-height:1;">
                            #<?= $filmRankCurrent ?>
                        </div>
                        <div style="color:rgba(255,255,255,.4); font-size:.75rem;">Aktueller Rang</div>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div style="color:#ef5350; font-size:1.5rem; font-weight:800; line-height:1;" id="hero-losses">
                            <?= $sessionState['consec_losses'] ?>/7
                        </div>
                        <div style="color:rgba(255,255,255,.4); font-size:.75rem;">Niederlagen</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="py-4">
        <div class="container-xxl fe-wrap-wide">

<?php if (!$isActive): ?>
<!-- ── SETUP: Filmsuche ──────────────────────────────────────────────────────── -->

<?php if (isset($_GET['error'])): ?>
<div class="alert alert-danger py-2 mb-4">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Du benötigst mindestens 2 Filme in Meine Rangliste für diesen Modus.
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-8">

    <?php if ($totalRankedCount < 2): ?>
    <div style="background:rgba(244,67,54,.08); border:1px solid rgba(244,67,54,.2); border-radius:12px; padding:2rem; text-align:center;">
        <i class="bi bi-exclamation-triangle" style="font-size:2.5rem; color:#ef9a9a;"></i>
        <h4 class="mt-3" style="color:#ef9a9a;">Zu wenig gerankte Filme</h4>
        <p style="color:rgba(255,255,255,.4);">
            Du benötigst mindestens 2 Filme in Meine Rangliste.
            Absolviere zunächst das Turnier oder andere Duelle.
        </p>
    </div>
    <?php else: ?>

    <div style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.1); border-radius:16px; padding:2rem;">
        <h4 class="fw-bold mb-1" style="color:#e0e0e0;">Film suchen</h4>
        <p style="color:rgba(255,255,255,.35); font-size:.85rem; margin-bottom:1.5rem;">
            Suche nach einem Film aus der Datenbank. Er wird gegen Filme aus den Top-Rängen
            deiner <strong style="color:#e8b84b;"><?= $totalRankedCount ?> gerankten Filme</strong> duelliert
            und klettert so nach oben – bis er 7 Duelle in Folge verliert oder Rang 1 erreicht.
        </p>

        <!-- Sucheingabe -->
        <div class="position-relative mb-3">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="search-input" class="search-box"
                   placeholder="Filmtitel eingeben …" autocomplete="off">
        </div>

        <!-- Ergebnisliste -->
        <div id="search-results"
             style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.08);
                    border-radius:10px; max-height:380px; overflow-y:auto; display:none;">
        </div>

        <div id="search-hint" style="color:rgba(255,255,255,.25); font-size:.8rem; text-align:center; margin-top:1rem;">
            Mindestens 2 Zeichen eingeben
        </div>
    </div>

    <!-- Hidden Start-Form -->
    <form method="post" id="start-form" style="display:none;">
        <input type="hidden" name="action"     value="start">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="film_id"    id="start-film-id" value="">
    </form>

    <?php endif; ?>
</div>
</div>

<?php elseif ($isActive && $selectedFilm && $opponent): ?>
<!-- ── AKTIVE SESSION: Duel ──────────────────────────────────────────────────── -->
<?php
    $selUrl    = moviePosterUrl($selectedFilm ?? [], 'w500');
    $oppUrl    = moviePosterUrl($opponent    ?? [], 'w500');
    $selTitle  = movieTitle($selectedFilm ?? []);
    $oppTitle  = movieTitle($opponent    ?? []);
    $lossCount = $sessionState['consec_losses'];
    $totalR    = $sessionState['total_ranked'];
    $oppRank   = (int)($opponent['rank_pos'] ?? 0);
?>

<!-- Info-Leiste -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <!-- Verlust-Punkte -->
        <div>
            <div style="color:rgba(255,255,255,.35); font-size:.72rem; margin-bottom:.35rem; text-transform:uppercase; letter-spacing:.04em;">
                Aufeinanderfolgende Niederlagen
            </div>
            <div class="loss-bar" id="loss-bar">
                <?php for ($i = 0; $i < 7; $i++): ?>
                <div class="loss-dot<?= $i < $lossCount ? ' filled' : '' ?>"
                     id="loss-dot-<?= $i ?>"></div>
                <?php endfor; ?>
            </div>
        </div>
        <!-- Aktueller Rang des Films -->
        <div style="background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); border-radius:8px; padding:.4rem .9rem; font-size:.8rem;">
            <span style="color:rgba(255,255,255,.4);">Aktueller Rang </span>
            <strong id="film-rank" style="color:#e8b84b;"><?= $filmRankCurrent > 0 ? $filmRankCurrent : '–' ?></strong>
            <span style="color:rgba(255,255,255,.25);"> / <?= $totalR ?></span>
        </div>
        <!-- Gegner-Bereich -->
        <div style="background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); border-radius:8px; padding:.4rem .9rem; font-size:.8rem;">
            <span style="color:rgba(255,255,255,.4);">Gegner-Bereich </span>
            <strong id="opp-range" style="color:#e8b84b;"><?= $rangeMin ?>–<?= $rangeMax ?></strong>
        </div>
    </div>
    <form method="post" onsubmit="return confirm('Session abbrechen?');" style="margin:0;">
        <input type="hidden" name="action"     value="stop">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <button class="btn btn-link p-0 text-decoration-none"
                style="color:rgba(255,255,255,.3); font-size:.8rem;">
            <i class="bi bi-x-circle me-1"></i>Abbrechen
        </button>
    </form>
</div>

<!-- Hinweis -->
<p class="text-center mb-3" style="color:rgba(255,255,255,.4); font-size:clamp(1.4rem,2.5vw,2.5rem); white-space:nowrap; overflow:hidden;">
    Welchen Film schaust du dir lieber an?
</p>

<!-- Duel-Karten -->
<div class="duel-2col" id="duel-area">

    <!-- Gesuchter Film (links, immer fest) -->
    <div class="duel-card fixed" id="card-selected"
         data-id="<?= (int)$selectedFilm['id'] ?>">
        <div class="card-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
        <div class="card-label">Dein Film</div>
        <img class="card-poster" src="<?= e($selUrl) ?>" alt="<?= e($selTitle) ?>"
             onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
        <a href="/film.php?id=<?= (int)$selectedFilm['id'] ?>" class="film-link card-title" target="_blank" onclick="event.stopPropagation()"><?= e($selTitle) ?></a>
        <div class="card-meta"><?= (int)$selectedFilm['year'] ?></div>
    </div>

    <div class="vs-col"><div class="vs-circle">VS</div></div>

    <!-- Gegner (rechts, wechselt) -->
    <div class="duel-card" id="card-opponent"
         data-id="<?= (int)$opponent['id'] ?>">
        <div class="card-overlay"><i class="bi bi-hand-thumbs-up-fill"></i></div>
        <div class="card-label" style="background:rgba(255,255,255,.15); color:#e0e0e0;" id="opp-rank-label">
            Rang <?= $oppRank > 0 ? $oppRank : '?' ?>
        </div>
        <img class="card-poster" id="opp-poster" src="<?= e($oppUrl) ?>" alt="<?= e($oppTitle) ?>"
             onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
        <a id="opp-title" href="/film.php?id=<?= (int)$opponent['id'] ?>" class="film-link card-title" target="_blank" onclick="event.stopPropagation()"><?= e($oppTitle) ?></a>
        <div class="card-meta"  id="opp-meta"><?= (int)$opponent['year'] ?></div>
    </div>

</div>

<!-- Tastatur-Hinweis -->
<p class="text-center" style="color:rgba(255,255,255,.2); font-size:.75rem; margin-top:-.5rem;">
    ← Pfeiltaste = Dein Film gewinnt &nbsp;·&nbsp; → Pfeiltaste = Gegner gewinnt
</p>

<!-- Abschluss-Overlay (JS-gesteuert) -->
<div id="done-overlay" style="display:none; padding-top:1rem;">
    <div class="done-card">
        <div style="font-size:3rem; margin-bottom:1rem;">📍</div>
        <h3 class="fw-bold mb-2" style="color:#4caf50;">Einordnung abgeschlossen!</h3>
        <p style="color:rgba(255,255,255,.5); margin-bottom:1.5rem;" id="done-text">
            Der Film wurde in deine Rangliste eingeordnet.
        </p>
        <div class="d-flex gap-2 justify-content-center flex-wrap">
            <a href="/film-einordnen.php" class="btn-gold" style="text-decoration:none;">
                <i class="bi bi-search-heart me-2"></i>Neuen Film einordnen
            </a>
            <a href="/rangliste.php" style="background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); color:#e0e0e0; border-radius:10px; padding:.65rem 1.5rem; text-decoration:none; font-size:.9rem;">
                <i class="bi bi-list-ol me-1"></i>Zur Rangliste
            </a>
        </div>
    </div>
</div>

<?php endif; ?>

        </div>
    </section>
</main>

<!-- ── JavaScript ─────────────────────────────────────────────────────────────── -->
<?php if (!$isActive): ?>
<script>
(function () {
    const input   = document.getElementById('search-input');
    const results = document.getElementById('search-results');
    const hint    = document.getElementById('search-hint');
    const form    = document.getElementById('start-form');
    const filmId  = document.getElementById('start-film-id');

    if (!input) return;

    const PH = 'https://placehold.co/36x54/1e3a5f/e8b84b?text=?';
    let timer = null;

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    async function doSearch(q) {
        if (q.length < 2) {
            results.style.display = 'none';
            hint.style.display = '';
            return;
        }
        hint.style.display = 'none';

        try {
            const res  = await fetch(`/film-einordnen.php?action=search&q=${encodeURIComponent(q)}`);
            const data = await res.json();

            if (!data.length) {
                results.innerHTML = '<div style="color:rgba(255,255,255,.3);font-size:.85rem;text-align:center;padding:1.5rem;">Keine Filme gefunden</div>';
                results.style.display = '';
                return;
            }

            results.innerHTML = data.map(m => `
                <div class="result-card" data-id="${m.id}" onclick="selectFilm(${m.id})">
                    <img src="${escHtml(m.poster_url)}"
                         class="result-poster"
                         onerror="this.src='${PH}'">
                    <div style="flex:1;min-width:0;">
                        <div class="result-title text-truncate">${escHtml(m.title)}</div>
                        <div class="result-meta">${m.year || ''}${m.director ? ' · ' + escHtml(m.director) : ''}</div>
                    </div>
                    <button class="result-select-btn" type="button">
                        <i class="bi bi-plus-circle me-1"></i>Auswählen
                    </button>
                </div>
            `).join('');
            results.style.display = '';
        } catch { /* ignore */ }
    }

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => doSearch(input.value.trim()), 280);
    });

    window.selectFilm = function(id) {
        filmId.value = id;
        form.submit();
    };
})();
</script>

<?php elseif ($isActive && $selectedFilm && $opponent): ?>
<script>
const CSRF_TOKEN  = <?= json_encode(csrfToken()) ?>;
const IMG_BASE    = <?= json_encode(rtrim(TMDB_IMAGE_BASE, '/')) ?>;
const SELECTED_ID = <?= (int)$selectedFilm['id'] ?>;

function updateHdrCounters(totalDuels, uniqueFilms) {
    const dc = document.getElementById('hdr-duels-count');
    const fc = document.getElementById('hdr-films-count');
    if (dc && totalDuels !== undefined) dc.textContent = totalDuels.toLocaleString('de-DE');
    if (fc && uniqueFilms !== undefined) fc.textContent = uniqueFilms;
}

(function () {
    const cardSel  = document.getElementById('card-selected');
    const cardOpp  = document.getElementById('card-opponent');
    const duelArea = document.getElementById('duel-area');
    const doneDiv  = document.getElementById('done-overlay');
    const heroLoss = document.getElementById('hero-losses');
    const filmRankEl = document.getElementById('film-rank');
    const oppRangeEl = document.getElementById('opp-range');
    const doneText = document.getElementById('done-text');

    let voting = false;
    let timer  = null;

    function setVoting(v) {
        voting = v;
        clearTimeout(timer);
        if (v) timer = setTimeout(() => { voting = false; }, 6000);
    }

    function posterSrc(p) {
        if (!p) return 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?';
        return p.startsWith('http') ? p : IMG_BASE + p;
    }

    function updateLossBar(n) {
        for (let i = 0; i < 7; i++) {
            document.getElementById('loss-dot-' + i)
                ?.classList.toggle('filled', i < n);
        }
        if (heroLoss) heroLoss.textContent = n + '/7';
    }

    function updateOpponent(next, filmRank, oppRange) {
        cardOpp.dataset.id = next.id;
        const poster = cardOpp.querySelector('.card-poster');
        poster.src = posterSrc(next.poster);
        poster.alt = next.title;
        const titleEl = cardOpp.querySelector('#opp-title');
        if (titleEl) { titleEl.textContent = next.title; titleEl.href = '/film.php?id=' + next.id; }
        cardOpp.querySelector('.card-meta').textContent = next.year || '';
        const rankLabel = document.getElementById('opp-rank-label');
        if (rankLabel) rankLabel.textContent = 'Rang ' + (next.rank || '?');
        if (filmRankEl) filmRankEl.textContent = filmRank > 0 ? filmRank : '–';
        if (oppRangeEl && oppRange) oppRangeEl.textContent = oppRange[0] + '–' + oppRange[1];
        cardSel.classList.remove('winner', 'loser');
        cardOpp.classList.remove('winner', 'loser');
    }

    // Klick
    duelArea.addEventListener('click', e => {
        const card = e.target.closest('.duel-card');
        if (!card || voting) return;
        const clickedId = parseInt(card.dataset.id);
        const winnerId  = clickedId;
        const loserId   = clickedId === SELECTED_ID
            ? parseInt(cardOpp.dataset.id)
            : SELECTED_ID;
        castVote(winnerId, loserId);
    });

    // Pfeiltasten: ← = gesuchter Film gewinnt, → = Gegner gewinnt
    document.addEventListener('keydown', e => {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();
        if (voting) return;
        const winnerId = e.key === 'ArrowLeft' ? SELECTED_ID : parseInt(cardOpp.dataset.id);
        const loserId  = e.key === 'ArrowLeft' ? parseInt(cardOpp.dataset.id) : SELECTED_ID;
        castVote(winnerId, loserId);
    });

    async function castVote(winnerId, loserId) {
        setVoting(true);
        const winCard  = parseInt(cardSel.dataset.id) === winnerId ? cardSel : cardOpp;
        const loseCard = winCard === cardSel ? cardOpp : cardSel;
        winCard.classList.add('winner');
        loseCard.classList.add('loser');

        const fd = new FormData();
        fd.append('action',     'vote');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('winner_id',  winnerId);
        fd.append('loser_id',   loserId);

        try {
            const res  = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();

            if (!data.ok) { location.reload(); return; }

            updateHdrCounters(data.hdrDuels, data.hdrFilms);

            if (data.done) {
                setVoting(false);
                duelArea.style.display = 'none';
                const filmTitle = cardSel.querySelector('.card-title')?.textContent || '';
                if (data.stopReason === 'rank1') {
                    doneText.textContent = `"${filmTitle}" hat Rang 1 erreicht!`;
                } else if (data.finalPos > 0) {
                    doneText.textContent = `"${filmTitle}" wurde auf Rang ${data.finalPos} von ${data.totalRanked} eingeordnet.`;
                } else {
                    doneText.textContent = 'Der Film wurde in deine Rangliste eingeordnet.';
                }
                doneDiv.style.display = '';
                return;
            }

            updateLossBar(data.consecLosses);

            setTimeout(() => {
                updateOpponent(data.next, data.filmRank, data.oppRange);
                setVoting(false);
            }, 300);

        } catch {
            location.reload();
        }
    }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
