<?php
$pageTitle = 'Top 1000 Turnier – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);
set_time_limit(120);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── DB-Schema ─────────────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS top1000_tournaments (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    media_type    VARCHAR(10) NOT NULL DEFAULT 'movie',
    film_count    INT UNSIGNED NOT NULL,
    current_round TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status        ENUM('active','completed') NOT NULL DEFAULT 'active',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_mt (user_id, media_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS top1000_matches (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id  INT UNSIGNED NOT NULL,
    runde          TINYINT UNSIGNED NOT NULL,
    match_number   INT UNSIGNED NOT NULL,
    movie_a_id     INT UNSIGNED NOT NULL,
    movie_b_id     INT UNSIGNED NOT NULL,
    winner_id      INT UNSIGNED NULL,
    lucky_loser_id INT UNSIGNED NULL,
    INDEX idx_pending (tournament_id, runde, winner_id),
    UNIQUE KEY uq_match (tournament_id, runde, match_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS top1000_byes (
    tournament_id INT UNSIGNED NOT NULL,
    runde         TINYINT UNSIGNED NOT NULL,
    movie_id      INT UNSIGNED NOT NULL,
    PRIMARY KEY (tournament_id, runde, movie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Helpers ───────────────────────────────────────────────────────────────────
function t1kInsertMatches(PDO $db, int $tId, int $round, array $ids): void {
    $matchNum = 1; $pairs = [];
    for ($i = 0, $n = count($ids); $i < $n; $i += 2) {
        if ($i + 1 < $n) {
            $pairs[] = [$tId, $round, $matchNum++, $ids[$i], $ids[$i + 1]];
        } else {
            $db->prepare("INSERT IGNORE INTO top1000_byes (tournament_id,runde,movie_id) VALUES (?,?,?)")
               ->execute([$tId, $round, $ids[$i]]);
        }
    }
    foreach (array_chunk($pairs, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
        $flat = [];
        foreach ($chunk as $row) { array_push($flat, ...$row); }
        $db->prepare("INSERT IGNORE INTO top1000_matches (tournament_id,runde,match_number,movie_a_id,movie_b_id) VALUES $ph")
           ->execute($flat);
    }
}

function t1kAdvanceRound(PDO $db, int $tId, int $round): void {
    set_time_limit(120);

    // Idempotent: check current_round regardless of status
    $st = $db->prepare("SELECT current_round, status FROM top1000_tournaments WHERE id=?");
    $st->execute([$tId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    if ($row['status'] === 'completed') return;
    if ((int)$row['current_round'] !== $round) return; // already advanced

    $nr = $round + 1;
    $st = $db->prepare("SELECT COUNT(*) FROM top1000_matches WHERE tournament_id=? AND runde=?");
    $st->execute([$tId, $nr]);
    $nextExists = (int)$st->fetchColumn();
    if ($nextExists > 0) {
        // Next round already created — just bump pointer
        $db->prepare("UPDATE top1000_tournaments SET current_round=? WHERE id=?")->execute([$nr, $tId]);
        return;
    }

    $st = $db->prepare("SELECT winner_id FROM top1000_matches WHERE tournament_id=? AND runde=? AND winner_id IS NOT NULL ORDER BY match_number");
    $st->execute([$tId, $round]);
    $survivors = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    $st = $db->prepare("SELECT movie_id FROM top1000_byes WHERE tournament_id=? AND runde=?");
    $st->execute([$tId, $round]);
    $byes = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($byes as $id) { $survivors[] = (int)$id; }

    if (count($survivors) <= 1000) {
        $db->prepare("UPDATE top1000_tournaments SET status='completed' WHERE id=?")->execute([$tId]);
    } else {
        shuffle($survivors);
        t1kInsertMatches($db, $tId, $nr, $survivors);
        $db->prepare("UPDATE top1000_tournaments SET current_round=? WHERE id=?")->execute([$nr, $tId]);
    }
}

function t1kFilm(array $r, string $p): array {
    return [
        'id'             => (int)($r[$p.'id'] ?? 0),
        'title'          => $r[$p.'title'] ?? '',
        'title_en'       => $r[$p.'title_en'] ?? null,
        'year'           => $r[$p.'year'] ?? '',
        'poster_path'    => $r[$p.'poster'] ?? null,
        'poster_path_en' => $r[$p.'poster_en'] ?? null,
        'imdb_id'        => $r[$p.'imdb'] ?? null,
        'pos'            => (int)($r[$p.'pos'] ?? 999999),
    ];
}

// ── Action: Start ─────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($action === 'start' && csrfValid()) {
    $mt  = in_array($_POST['mt'] ?? '', ['movie','tv']) ? $_POST['mt'] : activeMtForDb();
    // Alle Filme der DB, geordnet: ranked zuerst (Position aufsteigend), unranked danach
    $st  = $db->prepare(
        "SELECT m.id FROM movies m
         LEFT JOIN user_position_ranking upr ON upr.movie_id = m.id AND upr.user_id = ?
         WHERE 1=1" . seriesSqlFilter('m') . moviesSqlFilter('m') .
        " ORDER BY COALESCE(upr.position, 999999) ASC, m.id ASC"
    );
    $st->execute([$userId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if (count($ids) < 1000) { header("Location: /top1000.php?mt=$mt&err=few"); exit; }

    shuffle($ids);
    $db->prepare("UPDATE top1000_tournaments SET status='completed' WHERE user_id=? AND media_type=? AND status='active'")
       ->execute([$userId, $mt]);
    $db->prepare("INSERT INTO top1000_tournaments (user_id,media_type,film_count) VALUES (?,?,?)")
       ->execute([$userId, $mt, count($ids)]);
    $tId = (int)$db->lastInsertId();
    t1kInsertMatches($db, $tId, 1, $ids);
    header("Location: /top1000.php?mt=$mt"); exit;
}

// ── Action: Vote ──────────────────────────────────────────────────────────────
if ($action === 'vote' && csrfValid()) {
    $mid = (int)($_POST['match_id']  ?? 0);
    $wid = (int)($_POST['winner_id'] ?? 0);
    if (!$mid || !$wid) { header('Location: /top1000.php'); exit; }

    $st = $db->prepare("
        SELECT tm.*, t.current_round, t.id AS t_id
        FROM top1000_matches tm
        JOIN top1000_tournaments t ON t.id = tm.tournament_id
        WHERE tm.id=? AND t.user_id=? AND t.status='active' AND tm.winner_id IS NULL
    ");
    $st->execute([$mid, $userId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);

    $curMt = activeMtForDb();
    if (!$m || !in_array($wid, [(int)$m['movie_a_id'], (int)$m['movie_b_id']])) {
        header("Location: /top1000.php?mt=$curMt"); exit;
    }
    $db->prepare("UPDATE top1000_matches SET winner_id=? WHERE id=?")->execute([$wid, $mid]);

    // Rangliste aktualisieren (wie beim Sichtungsturnier)
    $loserId = $wid === (int)$m['movie_a_id'] ? (int)$m['movie_b_id'] : (int)$m['movie_a_id'];
    recordComparison($userId, $wid, $loserId);

    $tId   = (int)$m['t_id'];
    $round = (int)$m['current_round'];
    $pending = (int)$db->query("SELECT COUNT(*) FROM top1000_matches WHERE tournament_id=$tId AND runde=$round AND winner_id IS NULL")->fetchColumn();

    if ($pending === 0) {
        try { t1kAdvanceRound($db, $tId, $round); } catch (\Throwable $e) { /* recovered on next page load */ }
    }
    header("Location: /top1000.php?mt=$curMt&last=$mid"); exit;
}

// ── Action: Lucky Loser ───────────────────────────────────────────────────────
if ($action === 'lucky_loser' && csrfValid()) {
    $mid = (int)($_POST['match_id'] ?? 0);
    $st  = $db->prepare("
        SELECT tm.movie_a_id, tm.movie_b_id, tm.winner_id
        FROM top1000_matches tm
        JOIN top1000_tournaments t ON t.id = tm.tournament_id
        WHERE tm.id=? AND t.user_id=? AND tm.winner_id IS NOT NULL AND tm.lucky_loser_id IS NULL
    ");
    $st->execute([$mid, $userId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if ($m) {
        $lid = (int)$m['winner_id'] === (int)$m['movie_a_id'] ? (int)$m['movie_b_id'] : (int)$m['movie_a_id'];
        $db->prepare("UPDATE top1000_matches SET lucky_loser_id=? WHERE id=?")->execute([$lid, $mid]);
    }
    $curMt2 = activeMtForDb();
    header("Location: /top1000.php?mt=$curMt2&last=$mid"); exit;
}

// ── Action: Undo ──────────────────────────────────────────────────────────────
if ($action === 'undo' && csrfValid()) {
    $curMtU = activeMtForDb();
    // Letztes entschiedenes Match des aktiven Turniers finden
    $uStmt = $db->prepare("
        SELECT tm.id, tm.movie_a_id, tm.winner_id, tm.tournament_id, t.current_round, t.media_type
        FROM top1000_matches tm
        JOIN top1000_tournaments t ON t.id = tm.tournament_id
        WHERE t.user_id = ? AND t.status = 'active' AND tm.winner_id IS NOT NULL
        ORDER BY tm.id DESC LIMIT 1
    ");
    $uStmt->execute([$userId]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);

    if ($uRow) {
        $curMtU   = $uRow['media_type'];
        $wId      = (int)$uRow['winner_id'];
        $lId      = $wId === (int)$uRow['movie_a_id']
                    ? (int)($db->query("SELECT movie_b_id FROM top1000_matches WHERE id=".(int)$uRow['id'])->fetchColumn())
                    : (int)$uRow['movie_a_id'];

        // Undo ELO / comparison
        undoLastComparison($userId, $wId, $lId);

        // Falls eine neue Runde bereits gestartet wurde, diese löschen
        $tId  = (int)$uRow['tournament_id'];
        $rnd  = (int)$uRow['current_round'];
        $rndPending = (int)$db->query("SELECT COUNT(*) FROM top1000_matches WHERE tournament_id=$tId AND runde=$rnd AND winner_id IS NULL")->fetchColumn();
        if ($rndPending === 0) {
            // Alle Matches der nächsten Runde entfernen und current_round zurücksetzen
            $db->prepare("DELETE FROM top1000_matches WHERE tournament_id=? AND runde=?")->execute([$tId, $rnd + 1]);
            $db->prepare("UPDATE top1000_tournaments SET current_round=? WHERE id=?")->execute([$rnd, $tId]);
        }

        // Lucky Loser zurücksetzen und Winner-Eintrag löschen
        $db->prepare("UPDATE top1000_matches SET winner_id=NULL, lucky_loser_id=NULL WHERE id=?")->execute([(int)$uRow['id']]);
    }
    header("Location: /top1000.php?mt=$curMtU"); exit;
}

// ── Action: Abandon ───────────────────────────────────────────────────────────
if ($action === 'abandon' && csrfValid()) {
    // media_type direkt aus der aktiven Turnier-Zeile lesen — unabhängig von GET/Session
    $abStmt = $db->prepare("SELECT id, media_type FROM top1000_tournaments WHERE user_id=? AND status='active' ORDER BY created_at DESC LIMIT 1");
    $abStmt->execute([$userId]);
    $abRow = $abStmt->fetch(PDO::FETCH_ASSOC);
    if ($abRow) {
        $db->prepare("UPDATE top1000_tournaments SET status='completed' WHERE id=?")->execute([$abRow['id']]);
        $curMt3 = $abRow['media_type'];
    } else {
        $curMt3 = in_array($_POST['mt'] ?? '', ['movie','tv']) ? $_POST['mt'] : 'movie';
    }
    header("Location: /top1000.php?mt=$curMt3"); exit;
}

// ── State laden ───────────────────────────────────────────────────────────────
$mt  = activeMtForDb();
$typ = ($mt === 'tv') ? 'Serien' : 'Filme';
$typSg = ($mt === 'tv') ? 'Serie' : 'Film';

$tst = $db->prepare("SELECT * FROM top1000_tournaments WHERE user_id=? AND media_type=? AND status='active' ORDER BY created_at DESC LIMIT 1");
$tst->execute([$userId, $mt]);
$tournament = $tst->fetch(PDO::FETCH_ASSOC) ?: null;

$completedT = null;
if (!$tournament) {
    $cst = $db->prepare("SELECT * FROM top1000_tournaments WHERE user_id=? AND media_type=? AND status='completed' ORDER BY created_at DESC LIMIT 1");
    $cst->execute([$userId, $mt]);
    $completedT = $cst->fetch(PDO::FETCH_ASSOC) ?: null;
}

$currentMatch = $filmLeft = $filmRight = null;
$roundStats   = ['total' => 0, 'done' => 0];
$luckyCount   = 0;

if ($tournament) {
    $tId   = (int)$tournament['id'];
    $round = (int)$tournament['current_round'];

    $mst = $db->prepare("
        SELECT tm.id, tm.match_number, tm.lucky_loser_id,
               ma.id AS a_id, ma.title AS a_title, ma.title_en AS a_title_en,
               ma.year AS a_year, ma.poster_path AS a_poster, ma.poster_path_en AS a_poster_en,
               ma.imdb_id AS a_imdb, COALESCE(upr_a.position, 999999) AS a_pos,
               mb.id AS b_id, mb.title AS b_title, mb.title_en AS b_title_en,
               mb.year AS b_year, mb.poster_path AS b_poster, mb.poster_path_en AS b_poster_en,
               mb.imdb_id AS b_imdb, COALESCE(upr_b.position, 999999) AS b_pos
        FROM top1000_matches tm
        JOIN movies ma ON ma.id = tm.movie_a_id
        JOIN movies mb ON mb.id = tm.movie_b_id
        LEFT JOIN user_position_ranking upr_a ON upr_a.movie_id = tm.movie_a_id AND upr_a.user_id = ?
        LEFT JOIN user_position_ranking upr_b ON upr_b.movie_id = tm.movie_b_id AND upr_b.user_id = ?
        WHERE tm.tournament_id = ? AND tm.runde = ? AND tm.winner_id IS NULL
        ORDER BY tm.match_number ASC LIMIT 1
    ");
    $mst->execute([$userId, $userId, $tId, $round]);
    $cm = $mst->fetch(PDO::FETCH_ASSOC);

    // Recovery: Runde komplett aber nächste Runde noch nicht erstellt
    if (!$cm) {
        $pending = (int)$db->query("SELECT COUNT(*) FROM top1000_matches WHERE tournament_id=$tId AND runde=$round AND winner_id IS NULL")->fetchColumn();
        if ($pending === 0) {
            $advanced = false;
            try {
                t1kAdvanceRound($db, $tId, $round);
                $advanced = true;
            } catch (\Throwable $e) { /* fall through to auto-restart */ }

            if ($advanced) {
                // Reload after successful advance
                $tst2 = $db->prepare("SELECT * FROM top1000_tournaments WHERE id=?");
                $tst2->execute([$tId]);
                $tournament = $tst2->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$tournament || $tournament['status'] === 'completed') {
                    header("Location: /top1000.php?mt=$mt"); exit;
                }
                $round = (int)$tournament['current_round'];
                $mst->execute([$userId, $userId, $tId, $round]);
                $cm = $mst->fetch(PDO::FETCH_ASSOC);
            } else {
                // Advance dauerhaft gescheitert — Turnier als defekt markieren und neu starten
                $db->prepare("UPDATE top1000_tournaments SET status='completed' WHERE id=?")->execute([$tId]);
                $tournament = null;
                // Neustart inline
                $rst2 = $db->prepare(
                    "SELECT m.id FROM movies m
                     LEFT JOIN user_position_ranking upr ON upr.movie_id = m.id AND upr.user_id = ?
                     WHERE 1=1" . seriesSqlFilter('m') . moviesSqlFilter('m') .
                    " ORDER BY COALESCE(upr.position, 999999) ASC, m.id ASC"
                );
                $rst2->execute([$userId]);
                $newIds = array_map('intval', $rst2->fetchAll(PDO::FETCH_COLUMN));
                if (count($newIds) >= 1000) {
                    shuffle($newIds);
                    $db->prepare("INSERT INTO top1000_tournaments (user_id,media_type,film_count) VALUES (?,?,?)")
                       ->execute([$userId, $mt, count($newIds)]);
                    $newTId = (int)$db->lastInsertId();
                    t1kInsertMatches($db, $newTId, 1, $newIds);
                }
                header("Location: /top1000.php?mt=$mt&restarted=1"); exit;
            }
        }
    }

    if ($cm) {
        $currentMatch = $cm;
        $fA = t1kFilm($cm, 'a_');
        $fB = t1kFilm($cm, 'b_');
        if ($fB['pos'] < $fA['pos']) { [$fA, $fB] = [$fB, $fA]; }
        $filmLeft = $fA; $filmRight = $fB;
    }

    $rst = $db->prepare("SELECT COUNT(*) AS total, SUM(winner_id IS NOT NULL) AS done FROM top1000_matches WHERE tournament_id=? AND runde=?");
    $rst->execute([$tId, $round]);
    $roundStats = $rst->fetch(PDO::FETCH_ASSOC);
    $luckyCount = (int)$db->query("SELECT COUNT(*) FROM top1000_matches WHERE tournament_id=$tId AND lucky_loser_id IS NOT NULL")->fetchColumn();
}

// Letztes Match für Lucky-Loser-Anzeige
$lastMatch = $lastLeft = $lastRight = null;
$lastId    = (int)($_GET['last'] ?? 0);
if ($lastId) {
    $lst = $db->prepare("
        SELECT tm.id, tm.winner_id, tm.lucky_loser_id,
               ma.id AS a_id, ma.title AS a_title, ma.title_en AS a_title_en,
               ma.year AS a_year, ma.poster_path AS a_poster, ma.poster_path_en AS a_poster_en,
               COALESCE(upr_a.position, 999999) AS a_pos,
               mb.id AS b_id, mb.title AS b_title, mb.title_en AS b_title_en,
               mb.year AS b_year, mb.poster_path AS b_poster, mb.poster_path_en AS b_poster_en,
               COALESCE(upr_b.position, 999999) AS b_pos
        FROM top1000_matches tm
        JOIN top1000_tournaments t ON t.id = tm.tournament_id
        JOIN movies ma ON ma.id = tm.movie_a_id
        JOIN movies mb ON mb.id = tm.movie_b_id
        LEFT JOIN user_position_ranking upr_a ON upr_a.movie_id = tm.movie_a_id AND upr_a.user_id = ?
        LEFT JOIN user_position_ranking upr_b ON upr_b.movie_id = tm.movie_b_id AND upr_b.user_id = ?
        WHERE tm.id = ? AND t.user_id = ?
    ");
    $lst->execute([$userId, $userId, $lastId, $userId]);
    $lm = $lst->fetch(PDO::FETCH_ASSOC);
    if ($lm) {
        $lastMatch = $lm;
        $lfA = t1kFilm($lm, 'a_'); $lfB = t1kFilm($lm, 'b_');
        if ($lfB['pos'] < $lfA['pos']) { [$lfA, $lfB] = [$lfB, $lfA]; }
        $lastLeft = $lfA; $lastRight = $lfB;
    }
}

// Verfügbare Filme
$avst = $db->prepare("SELECT COUNT(*) FROM movies m WHERE 1=1" . seriesSqlFilter('m') . moviesSqlFilter('m'));
$avst->execute([]);
$availCount = (int)$avst->fetchColumn();
$matchesNeeded = max(0, $availCount - 1000);
$weeksEst = max(1, (int)ceil($matchesNeeded / 700)); // ~100 matches/day × 7 days

$csrf = csrfToken();
require_once __DIR__ . '/includes/header.php';
?>
<style>
.t1k-card {
    background: rgba(255,255,255,.04); border: 2px solid rgba(255,255,255,.1);
    border-radius: 10px; overflow: hidden; cursor: pointer; position: relative;
    transition: border-color .18s, transform .12s; height: 100%;
    display: flex; flex-direction: column;
}
.t1k-card:hover { border-color: #e8b84b; transform: translateY(-3px); }
.t1k-cover { width: 100%; aspect-ratio: 2/3; object-fit: cover; display: block; }
.t1k-card-body { padding: .6rem .75rem .25rem; flex: 1; }
.t1k-win-btn {
    width: 100%; padding: .45rem; background: rgba(232,184,75,.1);
    border: none; border-top: 1px solid rgba(232,184,75,.15);
    color: #e8b84b; font-weight: 700; font-size: .8rem; cursor: pointer;
    transition: background .15s; flex-shrink: 0;
}
.t1k-win-btn:hover { background: rgba(232,184,75,.22); }
.t1k-rank-badge {
    position: absolute; top: 8px; left: 8px; z-index: 2;
    background: rgba(20,50,90,.85); color: #e8b84b;
    border: 1px solid rgba(232,184,75,.4); border-radius: 20px;
    padding: 1px 7px; font-size: .68rem; font-weight: 800;
}
.t1k-vs {
    display: flex; align-items: center; justify-content: center;
    align-self: stretch; position: relative; padding: 0 .75rem;
}
.t1k-vs-circle {
    width: 44px; height: 44px; border-radius: 50%;
    background: rgba(232,184,75,.12); border: 2px solid rgba(232,184,75,.3);
    display: flex; align-items: center; justify-content: center;
    color: #e8b84b; font-weight: 900; font-size: .85rem; flex-shrink: 0;
}
.t1k-lucky-btn {
    background: rgba(167,139,250,.12); border: 1px solid rgba(167,139,250,.3);
    color: #a78bfa; border-radius: 8px; padding: .3rem .85rem;
    font-size: .78rem; font-weight: 700; cursor: pointer; transition: all .15s;
    white-space: nowrap;
}
.t1k-lucky-btn:hover { background: rgba(167,139,250,.25); }
.t1k-lucky-badge {
    background: rgba(167,139,250,.15); color: #a78bfa;
    border: 1px solid rgba(167,139,250,.3); border-radius: 6px;
    padding: 2px 9px; font-size: .72rem; font-weight: 700; white-space: nowrap;
}
.t1k-prog { background: rgba(255,255,255,.08); border-radius: 6px; height: 7px; }
.t1k-prog-fill { background: #e8b84b; height: 7px; border-radius: 6px; transition: width .3s; }
</style>

<main style="background:#14325a; min-height:100vh;">

<!-- Header -->
<section style="background:linear-gradient(135deg,#0d1f3c,#1e3a6a); border-bottom:1px solid rgba(232,184,75,.15); padding:.9rem 0;">
<div class="container">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <h1 style="color:#e8b84b; font-size:1.45rem; font-weight:900; margin:0;">
                <i class="bi bi-trophy-fill me-2"></i>Top 1000 <?= e($typ) ?>-Turnier
            </h1>
            <p style="color:rgba(255,255,255,.38); font-size:.78rem; margin:.15rem 0 0;">
                Deine persönlichen Top 1000 ermitteln · Separat für <?= $mt === 'tv' ? 'Serien' : 'Filme' ?> &amp; <?= $mt === 'tv' ? 'Filme' : 'Serien' ?>
            </p>
        </div>
        <?php if ($tournament): ?>
        <div style="color:rgba(255,255,255,.45); font-size:.8rem; text-align:right;">
            Runde <strong style="color:#e8b84b;"><?= (int)$tournament['current_round'] ?></strong>
            &nbsp;·&nbsp; <?= number_format((int)$roundStats['done']) ?>&thinsp;/&thinsp;<?= number_format((int)$roundStats['total']) ?> Matches
            &nbsp;·&nbsp; <span style="color:#a78bfa;"><?= $luckyCount ?> Lucky Loser</span>
        </div>
        <?php endif; ?>
    </div>
</div>
</section>

<div class="container py-4" style="max-width:820px;">

<?php if (!$tournament && !$completedT): ?>
<!-- ══ Setup ══════════════════════════════════════════════════════════════════ -->
<div class="row justify-content-center"><div class="col-md-9 col-lg-8">

<?php if (isset($_GET['err'])): ?>
<div style="background:rgba(248,113,113,.1); border:1px solid rgba(248,113,113,.3); border-radius:10px; padding:.9rem 1.2rem; margin-bottom:1.2rem; color:#f87171;">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Die Datenbank enthält weniger als <strong>1000</strong> <?= e($typ) ?>.
    Aktuell: <strong><?= $availCount ?></strong>.
</div>
<?php endif; ?>

<!-- Erklärungs-Box -->
<div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09); border-radius:14px; padding:1.5rem; margin-bottom:1.2rem;">
    <h5 style="color:#e8b84b; font-weight:800; margin-bottom:1rem;"><i class="bi bi-info-circle me-2"></i>So funktioniert das Top 1000 Turnier</h5>
    <ul style="color:rgba(255,255,255,.65); font-size:.88rem; padding-left:1.3rem; margin:0; line-height:1.9;">
        <li>Alle deine bewerteten <?= e($typ) ?> treten in einem Knockout-Turnier an</li>
        <li>Immer 2 <?= e($typ) ?> gegeneinander — der Bessere kommt in die nächste Runde</li>
        <li>Der <?= e($typSg) ?> mit dem besseren Platz in deiner Rangliste steht immer <strong style="color:#e8b84b;">links</strong></li>
        <li>Ziel: die <strong style="color:#e8b84b;">Top 1000 <?= e($typ) ?></strong> herauszufiltern</li>
        <li>Bei knappen Duellen kannst du den Verlierer als <strong style="color:#a78bfa;"><i class="bi bi-star-fill"></i> Lucky Loser</strong> markieren — er bekommt eine zweite Chance im JgJ</li>
        <li>Das Turnier ist jederzeit unterbrechbar und setzt dort fort, wo du aufgehört hast</li>
    </ul>
</div>

<!-- Zeit-Warnung -->
<div style="background:rgba(248,113,113,.08); border:1px solid rgba(248,113,113,.22); border-radius:12px; padding:1.1rem 1.3rem; margin-bottom:1.5rem;">
    <div style="color:#f87171; font-weight:700; margin-bottom:.4rem;">
        <i class="bi bi-clock-history me-2"></i>Hinweis: Mehrwöchiges Projekt
    </div>
    <p style="color:rgba(255,255,255,.6); font-size:.85rem; margin:0;">
        <?php if ($availCount >= 1000): ?>
        Bei <?= number_format($availCount) ?> <?= e($typ) ?> sind ca.
        <strong style="color:#f87171;"><?= number_format($matchesNeeded) ?> Matches</strong> nötig —
        das entspricht bei 100 Matches/Tag ca. <strong style="color:#f87171;"><?= $weeksEst ?> Woche<?= $weeksEst > 1 ? 'n' : '' ?></strong>.
        Du kannst das Turnier jederzeit pausieren und später weitermachen.
        <?php else: ?>
        Zu wenige <?= e($typ) ?> in der Datenbank.
        <?php endif; ?>
    </p>
</div>

<!-- Lucky Loser Warnung -->
<div style="background:rgba(167,139,250,.07); border:1px solid rgba(167,139,250,.2); border-radius:12px; padding:1rem 1.3rem; margin-bottom:1.5rem; font-size:.85rem; color:rgba(255,255,255,.6);">
    <i class="bi bi-star me-2" style="color:#a78bfa;"></i>
    <strong style="color:#a78bfa;">Lucky Loser:</strong>
    Bitte sparsam verwenden! Nur für Fälle, wo zwei wirklich starke <?= e($typ) ?> unglücklich aufeinandertreffen.
    Zu viele Lucky Loser verlängern das anschließende Jeder-gegen-Jeden deutlich.
</div>

<?php if ($availCount >= 1000): ?>
<form method="post" action="?mt=<?= e($mt) ?>">
    <input type="hidden" name="action" value="start">
    <input type="hidden" name="mt" value="<?= e($mt) ?>">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <button type="submit"
            style="width:100%; background:#e8b84b; color:#14325a; border:none; border-radius:10px; padding:.85rem; font-weight:900; font-size:1rem; cursor:pointer; transition:background .15s;"
            onmouseover="this.style.background='#f5ca5e'" onmouseout="this.style.background='#e8b84b'">
        <i class="bi bi-trophy me-2"></i>Top 1000 Turnier starten
        <div style="font-size:.78rem; font-weight:400; margin-top:.15rem; opacity:.7;">
            <?= number_format($availCount) ?> <?= e($typ) ?> nehmen teil · ca. <?= number_format($matchesNeeded) ?> Matches
        </div>
    </button>
</form>
<?php else: ?>
<div style="text-align:center; color:rgba(255,255,255,.3); padding:1rem; font-size:.88rem;">
    Noch nicht genug <?= e($typ) ?> bewertet. Bewerte mehr <?= e($typ) ?> über Jeder-gegen-Jeden oder Zufallsduelle.
</div>
<?php endif; ?>

</div></div>

<?php elseif ($completedT && !$tournament): ?>
<!-- ══ Abgeschlossen ══════════════════════════════════════════════════════════ -->
<?php
$ctId  = (int)$completedT['id'];
$cRnd  = (int)$completedT['current_round'];
$survs = array_map('intval', $db->query("
    SELECT winner_id FROM top1000_matches WHERE tournament_id=$ctId AND runde=$cRnd AND winner_id IS NOT NULL
    UNION
    SELECT movie_id FROM top1000_byes WHERE tournament_id=$ctId AND runde=$cRnd
")->fetchAll(PDO::FETCH_COLUMN));
$llIds  = array_unique(array_map('intval', $db->query("SELECT lucky_loser_id FROM top1000_matches WHERE tournament_id=$ctId AND lucky_loser_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN)));
$allIds = array_unique(array_merge($survs, $llIds));
?>
<div style="text-align:center; padding:1.5rem 0 2rem;">
    <div style="font-size:3.5rem; margin-bottom:.5rem;">🏆</div>
    <h2 style="color:#e8b84b; font-weight:900; margin-bottom:.4rem;">Top <?= count($survs) ?> ermittelt!</h2>
    <p style="color:rgba(255,255,255,.5); font-size:.9rem;">
        <strong style="color:#22c55e;"><?= count($survs) ?></strong> Finalisten
        &nbsp;+&nbsp; <strong style="color:#a78bfa;"><?= count($llIds) ?></strong> Lucky Loser
        &nbsp;=&nbsp; <strong style="color:#e8b84b;"><?= count($allIds) ?></strong> <?= e($typ) ?> insgesamt
    </p>
    <p style="color:rgba(255,255,255,.4); font-size:.85rem; max-width:480px; margin:0 auto 1.5rem;">
        Starte jetzt <strong>Jeder gegen Jeden</strong> mit diesen <?= count($allIds) ?> <?= e($typ) ?>,
        um die finale Rangliste deiner Top 1000 zu ermitteln.
    </p>
    <div class="d-flex gap-2 justify-content-center flex-wrap">
        <form method="post" action="?mt=<?= e($mt) ?>" style="display:inline;" onsubmit="return confirm('Altes Turnier verwerfen und neu starten?')">
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="mt" value="<?= e($mt) ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button type="submit" style="background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); color:rgba(255,255,255,.6); border-radius:9px; padding:.5rem 1.2rem; font-size:.85rem; font-weight:700; cursor:pointer;">
                <i class="bi bi-arrow-repeat me-1"></i>Neues Turnier
            </button>
        </form>
        <a href="/jgj.php?mt=<?= e($mt) ?>" style="background:#e8b84b; color:#14325a; border-radius:9px; padding:.5rem 1.4rem; font-size:.85rem; font-weight:800; text-decoration:none;">
            <i class="bi bi-people-fill me-1"></i>Zu Jeder gegen Jeden →
        </a>
    </div>
</div>

<!-- Statistik-Box -->
<div style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.08); border-radius:12px; padding:1rem 1.3rem; margin-top:.5rem;">
    <div style="color:rgba(255,255,255,.35); font-size:.72rem; text-transform:uppercase; letter-spacing:.07em; margin-bottom:.75rem;">Turnier-Statistik</div>
    <div class="row g-3">
        <div class="col-6 col-md-3 text-center">
            <div style="color:#e8b84b; font-size:1.4rem; font-weight:800;"><?= (int)$completedT['film_count'] ?></div>
            <div style="color:rgba(255,255,255,.35); font-size:.72rem;">Startende <?= e($typ) ?></div>
        </div>
        <div class="col-6 col-md-3 text-center">
            <div style="color:#e8b84b; font-size:1.4rem; font-weight:800;"><?= $cRnd ?></div>
            <div style="color:rgba(255,255,255,.35); font-size:.72rem;">Runden gespielt</div>
        </div>
        <div class="col-6 col-md-3 text-center">
            <div style="color:#22c55e; font-size:1.4rem; font-weight:800;"><?= count($survs) ?></div>
            <div style="color:rgba(255,255,255,.35); font-size:.72rem;">Top-Finalisten</div>
        </div>
        <div class="col-6 col-md-3 text-center">
            <div style="color:#a78bfa; font-size:1.4rem; font-weight:800;"><?= count($llIds) ?></div>
            <div style="color:rgba(255,255,255,.35); font-size:.72rem;">Lucky Loser</div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ══ Voting ════════════════════════════════════════════════════════════════ -->
<?php
$pct = (int)$roundStats['total'] > 0
     ? round((int)$roundStats['done'] / (int)$roundStats['total'] * 100) : 0;
$remaining = max(0, (int)$roundStats['total'] - (int)$roundStats['done']);
?>

<?php if (isset($_GET['restarted'])): ?>
<div style="background:rgba(248,113,113,.1); border:1px solid rgba(248,113,113,.25); border-radius:10px; padding:.8rem 1.1rem; margin-bottom:1.2rem; color:#f87171; font-size:.85rem;">
    <i class="bi bi-exclamation-triangle me-2"></i>Das Turnier konnte nicht fortgesetzt werden und wurde automatisch neu gestartet.
</div>
<?php endif; ?>

<!-- Rundenfortschritt -->
<div style="margin-bottom:1.4rem;">
    <div class="d-flex justify-content-between" style="color:rgba(255,255,255,.4); font-size:.75rem; margin-bottom:.35rem;">
        <span>Runde <?= (int)$tournament['current_round'] ?> · Match <?= number_format((int)$roundStats['done'] + 1) ?> / <?= number_format((int)$roundStats['total']) ?></span>
        <span><?= $pct ?>% · noch <?= number_format($remaining) ?> in dieser Runde</span>
    </div>
    <div class="t1k-prog"><div class="t1k-prog-fill" style="width:<?= $pct ?>%;"></div></div>
</div>

<!-- Letztes Match + Lucky Loser -->
<?php if ($lastMatch && $lastLeft && $lastRight): ?>
<?php
$lWid = (int)$lastMatch['winner_id'];
$isLeftWin = $lWid === $lastLeft['id'];
$lWinner   = $isLeftWin ? $lastLeft  : $lastRight;
$lLoser    = $isLeftWin ? $lastRight : $lastLeft;
$hasLL     = !empty($lastMatch['lucky_loser_id']);
?>
<div style="background:rgba(167,139,250,.06); border:1px solid rgba(167,139,250,.18); border-radius:11px; padding:.9rem 1.1rem; margin-bottom:1.4rem;">
    <div style="color:rgba(167,139,250,.6); font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; margin-bottom:.7rem;">
        <i class="bi bi-clock-history me-1"></i>Letztes Ergebnis
    </div>
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <!-- Gewinner -->
        <div style="flex:1; min-width:120px;">
            <div style="color:rgba(255,255,255,.28); font-size:.65rem; margin-bottom:.3rem;">Gewinner</div>
            <div class="d-flex align-items-center gap-2">
                <?php $wp = $lWinner['poster_path_en'] ?: $lWinner['poster_path']; ?>
                <?php if ($wp): ?>
                <img src="https://image.tmdb.org/t/p/w92<?= e($wp) ?>" style="width:28px;height:42px;object-fit:cover;border-radius:3px;flex-shrink:0;" onerror="this.src='https://placehold.co/28x42/1e3a5f/22c55e?text=✓'">
                <?php endif; ?>
                <div>
                    <div style="color:#22c55e; font-weight:700; font-size:.84rem; line-height:1.2;"><?= e(movieTitle($lWinner)) ?></div>
                    <div style="color:rgba(255,255,255,.28); font-size:.7rem;"><?= $lWinner['year'] ?></div>
                </div>
            </div>
        </div>
        <div style="color:rgba(255,255,255,.18); font-size:.78rem; font-weight:700;">vs</div>
        <!-- Verlierer -->
        <div style="flex:1; min-width:120px;">
            <div style="color:rgba(255,255,255,.28); font-size:.65rem; margin-bottom:.3rem;">Verlierer</div>
            <div class="d-flex align-items-center gap-2">
                <?php $lp = $lLoser['poster_path_en'] ?: $lLoser['poster_path']; ?>
                <?php if ($lp): ?>
                <img src="https://image.tmdb.org/t/p/w92<?= e($lp) ?>" style="width:28px;height:42px;object-fit:cover;border-radius:3px;flex-shrink:0;opacity:.5;" onerror="this.src='https://placehold.co/28x42/1e3a5f/aaa?text=?'">
                <?php endif; ?>
                <div>
                    <div style="color:rgba(255,255,255,.45); font-weight:600; font-size:.84rem; line-height:1.2;"><?= e(movieTitle($lLoser)) ?></div>
                    <div style="color:rgba(255,255,255,.28); font-size:.7rem;"><?= $lLoser['year'] ?></div>
                </div>
            </div>
        </div>
        <!-- Lucky Loser Button -->
        <div style="flex-shrink:0;">
            <?php if ($hasLL): ?>
            <span class="t1k-lucky-badge"><i class="bi bi-star-fill me-1"></i>Lucky Loser</span>
            <?php else: ?>
            <form method="post" onsubmit="return confirmLL()">
                <input type="hidden" name="action" value="lucky_loser">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="match_id" value="<?= (int)$lastMatch['id'] ?>">
                <button type="submit" class="t1k-lucky-btn">
                    <i class="bi bi-star me-1"></i>Lucky Loser
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Aktuelles Match -->
<?php if ($currentMatch && $filmLeft && $filmRight): ?>
<form method="post" id="vote-form">
    <input type="hidden" name="action" value="vote">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="match_id" value="<?= (int)$currentMatch['id'] ?>">
    <input type="hidden" name="winner_id" id="winner-input" value="">

    <div class="d-flex align-items-stretch gap-2" style="max-width:640px; margin:0 auto;">
        <!-- Linke Karte (besser platziert) -->
        <div style="flex:1; min-width:0;">
            <?php
            $lRank = $filmLeft['pos'] < 999999 ? '#'.number_format($filmLeft['pos']) : '';
            $lSrc  = moviePosterUrl($filmLeft, 'w342');
            ?>
            <div class="t1k-card" onclick="vote(<?= (int)$filmLeft['id'] ?>)">
                <?php if ($lRank): ?>
                <div class="t1k-rank-badge"><?= e($lRank) ?></div>
                <?php endif; ?>
                <img src="<?= e($lSrc) ?>" class="t1k-cover"
                     alt="<?= e(movieTitle($filmLeft)) ?>"
                     onerror="this.src='https://placehold.co/200x300/1e3a5f/e8b84b?text=?'">
                <div class="t1k-card-body">
                    <div style="color:#fff; font-weight:700; font-size:.84rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= e(movieTitle($filmLeft)) ?></div>
                    <div style="color:rgba(255,255,255,.3); font-size:.72rem;"><?= $filmLeft['year'] ?></div>
                </div>
                <button type="button" class="t1k-win-btn" onclick="event.stopPropagation(); vote(<?= (int)$filmLeft['id'] ?>)">
                    <i class="bi bi-hand-thumbs-up me-1"></i>Besser
                </button>
            </div>
        </div>

        <!-- VS + Undo -->
        <div class="t1k-vs">
            <div class="t1k-vs-circle">VS</div>
            <form method="post" style="position:absolute; bottom:2.4rem; left:50%; transform:translateX(-50%);">
                <input type="hidden" name="action" value="undo">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit"
                        <?= !$lastMatch ? 'disabled' : '' ?>
                        title="Letztes Duell rückgängig"
                        style="background:none; border:none; padding:0; cursor:pointer;
                               color:rgba(255,255,255,.3); font-size:1.35rem; line-height:1;
                               transition:color .15s;"
                        onmouseover="if(!this.disabled)this.style.color='rgba(232,184,75,.7)'"
                        onmouseout="this.style.color='rgba(255,255,255,.3)'">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>
            </form>
        </div>

        <!-- Rechte Karte (schlechter platziert) -->
        <div style="flex:1; min-width:0;">
            <?php
            $rRank = $filmRight['pos'] < 999999 ? '#'.number_format($filmRight['pos']) : '';
            $rSrc  = moviePosterUrl($filmRight, 'w342');
            ?>
            <div class="t1k-card" onclick="vote(<?= (int)$filmRight['id'] ?>)">
                <?php if ($rRank): ?>
                <div class="t1k-rank-badge"><?= e($rRank) ?></div>
                <?php endif; ?>
                <img src="<?= e($rSrc) ?>" class="t1k-cover"
                     alt="<?= e(movieTitle($filmRight)) ?>"
                     onerror="this.src='https://placehold.co/200x300/1e3a5f/e8b84b?text=?'">
                <div class="t1k-card-body">
                    <div style="color:#fff; font-weight:700; font-size:.84rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= e(movieTitle($filmRight)) ?></div>
                    <div style="color:rgba(255,255,255,.3); font-size:.72rem;"><?= $filmRight['year'] ?></div>
                </div>
                <button type="button" class="t1k-win-btn" onclick="event.stopPropagation(); vote(<?= (int)$filmRight['id'] ?>)">
                    <i class="bi bi-hand-thumbs-up me-1"></i>Besser
                </button>
            </div>
        </div>
    </div>
</form>

<!-- Lucky Loser Hinweis (klein) -->
<p style="text-align:center; color:rgba(255,255,255,.2); font-size:.72rem; margin-top:.75rem;">
    <i class="bi bi-star me-1" style="color:rgba(167,139,250,.4);"></i>
    Lucky Loser — nach der Abstimmung beim Ergebnis des letzten Matches markieren
</p>

<?php else: ?>
<div style="text-align:center; padding:3rem;">
    <div class="spinner-border spinner-border-sm text-warning mb-3"></div>
    <div style="color:rgba(255,255,255,.4); margin-bottom:1.2rem;">Nächste Runde wird vorbereitet…</div>
    <a href="/top1000.php?mt=<?= e($mt) ?>"
       style="background:#e8b84b; color:#14325a; border-radius:8px; padding:.5rem 1.3rem; font-weight:700; text-decoration:none; font-size:.88rem;">
        <i class="bi bi-arrow-clockwise me-1"></i>Neu laden
    </a>
    <script>setTimeout(()=>location.href='/top1000.php?mt=<?= e($mt) ?>', 3000);</script>
</div>
<?php endif; ?>

<!-- Turnier abbrechen -->
<div style="text-align:center; margin-top:2.5rem;">
    <form method="post" action="?mt=<?= e($mt) ?>" style="display:inline;" onsubmit="return confirm('Turnier wirklich abbrechen?\nAlle Ergebnisse dieser Runde gehen verloren.')">
        <input type="hidden" name="action" value="abandon">
        <input type="hidden" name="mt" value="<?= e($mt) ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <button type="submit" style="background:none; border:none; color:rgba(255,255,255,.18); font-size:.75rem; cursor:pointer; text-decoration:underline;">
            Turnier abbrechen
        </button>
    </form>
</div>

<?php endif; ?>
</div><!-- /container -->
</main>

<script>
const ID_LEFT  = <?= (int)$filmLeft['id'] ?>;
const ID_RIGHT = <?= (int)$filmRight['id'] ?>;

function vote(id) {
    document.getElementById('winner-input').value = id;
    document.getElementById('vote-form').submit();
}

document.addEventListener('keydown', function(e) {
    if (e.repeat) return;
    if (['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) return;
    if (e.key === 'ArrowLeft')  vote(ID_LEFT);
    if (e.key === 'ArrowRight') vote(ID_RIGHT);
});
function confirmLL() {
    return confirm(
        'Lucky Loser markieren?\n\n' +
        'Bitte sparsam verwenden!\n' +
        'Nur wenn zwei wirklich starke <?= addslashes($typ) ?> unglücklich aufeinandertreffen.\n\n' +
        'Zu viele Lucky Loser verlängern das spätere Jeder-gegen-Jeden deutlich.'
    );
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
