<?php
$pageTitle = '5 Filme – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$userId = (int)$_SESSION['user_id'];
$db     = getDB();

// ── Tabelle erstellen ─────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS fuenf_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    film_count SMALLINT UNSIGNED NOT NULL,
    status     ENUM('active','completed') NOT NULL DEFAULT 'active',
    state      JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->exec("ALTER TABLE fuenf_sessions ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NOT NULL DEFAULT 'movie'");

// ── Anzahl verfügbarer Filme ──────────────────────────────────────────────────
$availStmt = $db->prepare("SELECT COUNT(*) FROM user_position_ranking upr JOIN movies m ON m.id = upr.movie_id WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m'));
$availStmt->execute([$userId]);
$availableFilms = (int)$availStmt->fetchColumn();
$maxFilms = (int)(floor($availableFilms / 5) * 5);

// ── POST-Handler ──────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($action === 'start' && csrfValid()) {
    $n = max(5, (int)($_POST['film_count'] ?? 10));
    $n = (int)(floor($n / 5) * 5); // auf 5er runden
    $n = min($n, $maxFilms);

    $filmStmt = $db->prepare(
        "SELECT upr.movie_id FROM user_position_ranking upr JOIN movies m ON m.id = upr.movie_id WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . " ORDER BY upr.position ASC LIMIT ?"
    );
    $filmStmt->execute([$userId, $n]);
    $filmIds = array_map('intval', $filmStmt->fetchAll(PDO::FETCH_COLUMN));

    // Falls DB weniger als $n liefert: auf nächste durch 5 teilbare Zahl trimmen
    $filmIds = array_slice($filmIds, 0, (int)(floor(count($filmIds) / 5) * 5));

    shuffle($filmIds);
    $rounds  = array_chunk($filmIds, 5);
    $results = [];
    foreach ($filmIds as $id) { $results[$id] = 0; }

    $state = [
        'rounds'        => $rounds,
        'current_round' => 0,
        'total'         => count($rounds),
        'results'       => $results,
    ];

    $db->prepare("UPDATE fuenf_sessions SET status='completed' WHERE user_id=? AND status='active' AND media_type=?")
       ->execute([$userId, activeMtForDb()]);
    $db->prepare("INSERT INTO fuenf_sessions (user_id, film_count, state, media_type) VALUES (?, ?, ?, ?)")
       ->execute([$userId, $n, json_encode($state), activeMtForDb()]);

    header('Location: /fuenf-filme.php');
    exit;
}

if ($action === 'cancel' && csrfValid()) {
    $db->prepare("UPDATE fuenf_sessions SET status='completed' WHERE user_id=? AND status='active' AND media_type=?")
       ->execute([$userId, activeMtForDb()]);
    header('Location: /fuenf-filme.php');
    exit;
}

if ($action === 'vote' && csrfValid()) {
    // ranking = comma-separated movie_ids, rank 1 first
    $ranking = array_map('intval', array_filter(explode(',', $_POST['ranking'] ?? '')));

    $sessStmt = $db->prepare(
        "SELECT id, state FROM fuenf_sessions WHERE user_id=? AND status='active' AND media_type=? ORDER BY created_at DESC LIMIT 1"
    );
    $sessStmt->execute([$userId, activeMtForDb()]);
    if ($row = $sessStmt->fetch(PDO::FETCH_ASSOC)) {
        $state = json_decode($row['state'], true);
        $sid   = (int)$row['id'];

        // Punkte vergeben: Rang 1 = 5 Pkt, Rang 5 = 1 Pkt
        foreach ($ranking as $rank => $movieId) {
            $pts = 5 - $rank;
            if ($pts >= 1 && array_key_exists($movieId, $state['results'])) {
                $state['results'][$movieId] += $pts;
            }
        }

        // Alle implizierten Duelle in Meine Rangliste eintragen:
        // Platz i schlägt alle Plätze j > i → 4+3+2+1 = 10 Duelle
        if (count($ranking) === 5) {
            for ($i = 0; $i < 4; $i++) {
                for ($j = $i + 1; $j < 5; $j++) {
                    recordComparison($userId, $ranking[$i], $ranking[$j]);
                }
            }
        }

        $state['current_round']++;
        $newStatus = $state['current_round'] >= $state['total'] ? 'completed' : 'active';

        $db->prepare("UPDATE fuenf_sessions SET state=?, status=? WHERE id=?")
           ->execute([json_encode($state), $newStatus, $sid]);
    }
    header('Location: /fuenf-filme.php');
    exit;
}

// ── Session laden ─────────────────────────────────────────────────────────────
$sessStmt = $db->prepare(
    "SELECT * FROM fuenf_sessions WHERE user_id=? AND status='active' AND media_type=? ORDER BY created_at DESC LIMIT 1"
);
$sessStmt->execute([$userId, activeMtForDb()]);
$activeSession = $sessStmt->fetch(PDO::FETCH_ASSOC);

$sessStmt2 = $db->prepare(
    "SELECT * FROM fuenf_sessions WHERE user_id=? AND status='completed' AND media_type=? ORDER BY created_at DESC LIMIT 1"
);
$sessStmt2->execute([$userId, activeMtForDb()]);
$completedSession = $sessStmt2->fetch(PDO::FETCH_ASSOC);

// ── Meine Rangliste für Sidebar ───────────────────────────────────────────────
$prStmt = $db->prepare("SELECT upr.position AS pos, m.id, m.title, m.title_en, m.poster_path, m.poster_path_en
    FROM user_position_ranking upr JOIN movies m ON m.id = upr.movie_id
    WHERE upr.user_id = ?" . seriesSqlFilter('m') . moviesSqlFilter('m') . " ORDER BY upr.position ASC LIMIT 200");
$prStmt->execute([$userId]);
$posRanking = [];
foreach ($prStmt->fetchAll(PDO::FETCH_ASSOC) as $i => $r) { $posRanking[] = array_merge($r, ['pos' => $i + 1]); }

// ── Aktuelle Runde vorbereiten ────────────────────────────────────────────────
$currentRoundFilms = [];
$state             = null;
$isVoting          = false;
$isCompleted       = false;
$sessionRanking    = [];

if ($activeSession) {
    $state    = json_decode($activeSession['state'], true);
    $isVoting = true;
    $roundIdx = (int)$state['current_round'];

    if ($roundIdx < count($state['rounds'])) {
        $roundIds = $state['rounds'][$roundIdx];
        $ph       = implode(',', array_fill(0, count($roundIds), '?'));
        $mStmt    = $db->prepare("SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.imdb_id, COALESCE(upr.position, 999999) AS my_pos FROM movies m LEFT JOIN user_position_ranking upr ON upr.movie_id = m.id AND upr.user_id = ? WHERE m.id IN ($ph)");
        $mStmt->execute(array_merge([$userId], $roundIds));
        $mMap = array_column($mStmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        foreach ($roundIds as $id) {
            if (isset($mMap[$id])) $currentRoundFilms[] = $mMap[$id];
        }
        // Sort by user ranking position ascending (best rank = leftmost)
        usort($currentRoundFilms, fn($a, $b) => (int)$a['my_pos'] <=> (int)$b['my_pos']);
    }

} elseif ($completedSession) {
    $state       = json_decode($completedSession['state'], true);
    $isCompleted = true;
    $sessionRanking = buildFuenfRanking($state['results'], $db);
}

$showSetup = isset($_GET['new']);

function buildFuenfRanking(array $results, PDO $db): array {
    if (empty($results)) return [];
    arsort($results);
    $ids = array_keys($results);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $db->prepare("SELECT id, title, title_en, poster_path, poster_path_en, imdb_id, year FROM movies WHERE id IN ($ph)");
    $st->execute(array_map('intval', $ids));
    $mMap = array_column($st->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    $out  = [];
    foreach ($ids as $id) {
        if (isset($mMap[$id])) {
            $row = $mMap[$id];
            $out[] = array_merge($row, [
                'score'          => $results[$id],
                'display_title'  => movieTitle($row),
            ]);
        }
    }
    return $out;
}

require_once __DIR__ . '/includes/header.php';
$typ    = $mtActive === 'tv' ? 'Serien' : 'Filme';
$typSg  = $mtActive === 'tv' ? 'Serien'  : 'Filmen'; // Dativ-Plural
$pageTitle = "5 $typ – MKFB";
?>

<style>
    body { background: #14325a !important; }
    .film-card {
        background: rgba(255,255,255,.04);
        border: 2px solid rgba(255,255,255,.1);
        border-radius: 12px;
        cursor: pointer;
        transition: all .2s;
        overflow: hidden;
        position: relative;
        user-select: none;
    }
    .film-card:hover:not(.ranked) { border-color: rgba(232,184,75,.5); background: rgba(232,184,75,.07); }
    .film-card.ranked { border-color: #e8b84b; background: rgba(232,184,75,.1); cursor: pointer; }
    .film-card.ranked:hover { border-color: #f44336; background: rgba(244,67,54,.08); }
    .rank-badge {
        position: absolute; top: 8px; left: 8px;
        width: 32px; height: 32px; border-radius: 50%;
        background: #e8b84b; color: #1a1a1a;
        font-weight: 800; font-size: 1rem;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 2px 8px rgba(0,0,0,.5);
        z-index: 2;
    }
    .rank-label {
        text-align: center; font-size: .78rem; font-weight: 700;
        padding: .4rem 0; min-height: 1.8rem;
        transition: all .2s;
    }
    .rank-label.set { color: #e8b84b; }
    .rank-label.empty { color: rgba(255,255,255,.25); }
    .progress-bar-custom {
        height: 6px; background: rgba(255,255,255,.07); border-radius: 3px; overflow: hidden;
    }
    .progress-bar-custom .fill { background: #e8b84b; height: 100%; border-radius: 3px; transition: width .4s; }
    .sidebar-row {
        display: flex; align-items: center; gap: .6rem;
        padding: .45rem .8rem; border-bottom: 1px solid rgba(255,255,255,.04);
        transition: background .15s;
    }
    .sidebar-row:hover { background: rgba(232,184,75,.05); }
    .sidebar-row:last-child { border-bottom: none; }
    .score-pill {
        background: rgba(232,184,75,.15); color: #e8b84b;
        border-radius: 20px; padding: 1px 7px; font-size: .72rem; font-weight: 700;
        white-space: nowrap;
    }
    #btn-weiter {
        opacity: 0; pointer-events: none; transition: opacity .25s;
    }
    #btn-weiter.ready { opacity: 1; pointer-events: auto; }
    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 3px; }
    * { scrollbar-width: thin; scrollbar-color: rgba(232,184,75,.2) transparent; }
</style>

<main style="padding-top:6px; background:#14325a; flex:1;">

    <!-- Header-Banner -->
    <section class="py-4" style="background:linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom:1px solid rgba(232,184,75,.15);">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="fw-bold mb-1" style="color:#e8b84b;">
                        <i class="bi bi-grid-3x2-gap-fill me-2"></i>5 <?= $typ ?>
                    </h1>
                    <p class="mb-0" style="color:rgba(255,255,255,.5);">
                        Wähle die Reihenfolge von 5 <?= $typSg ?> – der Beste erhält 5 Punkte
                    </p>
                </div>
                <?php if ($isVoting && $state): ?>
                <div class="col-auto text-end d-flex align-items-center gap-3">
                    <form method="post" onsubmit="return confirm('Bewertung wirklich abbrechen? Der aktuelle Fortschritt geht verloren.')">
                        <input type="hidden" name="action"     value="cancel">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <button type="submit" class="btn btn-sm"
                                style="background:rgba(244,67,54,.15); color:#f44336; border:1px solid rgba(244,67,54,.3); border-radius:8px; padding:.3rem .8rem; font-size:.8rem;">
                            <i class="bi bi-x-circle me-1"></i>Abbrechen
                        </button>
                    </form>
                    <div>
                        <div style="color:#e8b84b; font-size:1.6rem; font-weight:800; line-height:1;">
                            <?= (int)$state['current_round'] + 1 ?> / <?= (int)$state['total'] ?>
                        </div>
                        <div style="color:rgba(255,255,255,.4); font-size:.75rem;">Runde</div>
                    </div>
                </div>
                <?php elseif ($isCompleted && $state): ?>
                <div class="col-auto text-end">
                    <div style="color:#4caf50; font-size:1.4rem; font-weight:800; line-height:1;">
                        <i class="bi bi-check-circle-fill"></i> Fertig
                    </div>
                    <div style="color:rgba(255,255,255,.4); font-size:.75rem;"><?= (int)$state['total'] ?> Runden</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="py-4" style="background:#14325a; min-height:60vh;">
        <div class="container">

        <?php if (!$isVoting && (!$isCompleted || $showSetup)): ?>
        <!-- ── Setup ──────────────────────────────────────────────────────── -->
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); border-radius:16px; padding:2rem;">
                    <h4 class="fw-bold mb-1" style="color:#e8b84b;">
                        <i class="bi bi-play-circle me-2"></i>Neue Bewertungsrunde
                    </h4>
                    <p style="color:rgba(255,255,255,.45); font-size:.88rem;">
                        Deine Top-<?= $typ ?> werden in Gruppen von 5 zufällig gezeigt.
                        Klicke die <?= $typ ?> in deiner Wunschreihenfolge an.
                    </p>

                    <?php if ($maxFilms < 5): ?>
                    <div class="alert alert-warning py-2">
                        Du benötigst mindestens 5 <?= $typ ?> in deiner Rangliste.
                    </div>
                    <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="action" value="start">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <div class="mb-3">
                            <label class="form-label" style="color:rgba(255,255,255,.7); font-size:.85rem;">
                                Anzahl <?= $typ ?> <span style="color:rgba(255,255,255,.35);">(durch 5 teilbar, max. <?= $maxFilms ?>)</span>
                            </label>
                            <input type="number" name="film_count" class="form-control"
                                   style="background:#1e3d7a; border:1px solid rgba(255,255,255,.15); color:#e0e0e0;"
                                   min="5" max="<?= $maxFilms ?>" step="5"
                                   value="<?= min(20, $maxFilms) ?>">
                            <div style="color:rgba(255,255,255,.3); font-size:.75rem; margin-top:.3rem;">
                                Ergibt <?= min(4, (int)floor(min(20,$maxFilms)/5)) ?> Runden à 5 <?= $typ ?>
                                <span id="rounds-hint"></span>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-gold w-100">
                            <i class="bi bi-play-fill me-1"></i>Starten
                        </button>
                    </form>
                    <script>
                    document.querySelector('input[name=film_count]').addEventListener('input', function() {
                        const n = Math.floor(parseInt(this.value||0)/5)*5;
                        document.getElementById('rounds-hint').textContent =
                            n >= 5 ? '→ ' + (n/5) + ' Runden' : '';
                    });
                    </script>
                    <?php endif; ?>

                    <?php if ($completedSession): ?>
                    <hr style="border-color:rgba(255,255,255,.08); margin:1.5rem 0;">
                    <p style="color:rgba(255,255,255,.4); font-size:.82rem; text-align:center; margin:0;">
                        <i class="bi bi-clock-history me-1"></i>
                        Letzte Runde: <?= date('d.m.Y', strtotime($completedSession['created_at'])) ?> ·
                        <?= $completedSession['film_count'] ?> <?= $typ ?>
                        <a href="/fuenf-filme.php?show=last" class="ms-2" style="color:#e8b84b;">Ergebnis ansehen</a>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- ── Voting / Ergebnis: zwei Spalten ───────────────────────────── -->
        <div class="row g-4">

            <!-- Linke Spalte: Rangliste -->
            <div class="col-lg-4">
                <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden; position:sticky; top:90px; max-height:calc(100vh - 110px); display:flex; flex-direction:column;">
                    <div style="background:rgba(232,184,75,.08); border-bottom:1px solid rgba(232,184,75,.15); padding:.75rem 1rem; flex-shrink:0;">
                        <span style="color:#e8b84b; font-weight:700; font-size:.82rem; text-transform:uppercase; letter-spacing:.05em;">
                            <i class="bi bi-list-ol me-1"></i><?= $mtActive === 'tv' ? 'Meine Rangliste Serien' : ($mtActive === 'movie' ? 'Meine Rangliste Filme' : 'Meine Rangliste') ?>
                        </span>
                    </div>
                    <div style="overflow-y:auto; flex:1;">
                    <?php if (empty($posRanking)): ?>
                        <div style="color:rgba(255,255,255,.3); font-size:.82rem; text-align:center; padding:2rem;">
                            Noch keine Einträge
                        </div>
                    <?php else: ?>
                        <?php $medals = [1=>'🥇',2=>'🥈',3=>'🥉']; ?>
                        <?php foreach ($posRanking as $film): ?>
                        <?php
                            $pos    = (int)$film['pos'];
                            $poster = moviePosterUrl($film, 'w92');
                        ?>
                        <div class="sidebar-row">
                            <div style="width:22px; text-align:center; flex-shrink:0; font-size:.9rem;">
                                <?= isset($medals[$pos])
                                    ? $medals[$pos]
                                    : '<span style="color:rgba(255,255,255,.35);font-size:.82rem;font-weight:700;">' . $pos . '</span>' ?>
                            </div>
                            <img src="<?= e($poster) ?>" alt="" style="width:28px;height:42px;object-fit:cover;border-radius:3px;flex-shrink:0;"
                                 onerror="this.src='https://placehold.co/28x42/1e3a5f/e8b84b?text=?'">
                            <div class="text-truncate" style="flex:1;min-width:0;color:#e0e0e0;font-size:.8rem;font-weight:600;">
                                <a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link" target="_blank"><?= e(movieTitle($film)) ?></a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Rechte Spalte: Voting oder Abschluss -->
            <div class="col-lg-8">

            <?php if ($isVoting && !empty($currentRoundFilms)): ?>
            <!-- Fortschrittsbalken -->
            <?php $progress = $state['total'] > 0 ? round($state['current_round'] / $state['total'] * 100) : 0; ?>
            <div class="d-flex align-items-center gap-3 mb-4">
                <div class="progress-bar-custom flex-grow-1">
                    <div class="fill" style="width:<?= $progress ?>%;"></div>
                </div>
                <span style="color:rgba(255,255,255,.4);font-size:.8rem;white-space:nowrap;">
                    Runde <?= (int)$state['current_round'] + 1 ?> / <?= (int)$state['total'] ?>
                </span>
            </div>

            <h5 class="mb-4 text-center" style="color:rgba(255,255,255,.6); font-weight:400; font-size:.95rem;">
                Klicke die <?= $typ ?> in deiner Reihenfolge an — Platz 1 zuerst
            </h5>

            <!-- 5 Film-Karten -->
            <div class="row g-3" id="film-grid">
                <?php foreach ($currentRoundFilms as $film): ?>
                <div class="col" style="min-width:0;">
                    <div class="film-card" data-id="<?= (int)$film['id'] ?>" onclick="rankFilm(this)">
                        <div class="rank-badge" style="display:none;"></div>
                        <img src="<?= e(moviePosterUrl($film, 'w342')) ?>"
                             alt="<?= e(movieTitle($film)) ?>"
                             style="width:100%; aspect-ratio:2/3; object-fit:cover; display:block;"
                             onerror="this.src='https://placehold.co/200x300/1e3a5f/e8b84b?text=?'">
                        <div style="padding:.5rem .5rem 0;">
                            <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.8rem;">
                                <?= e(movieTitle($film)) ?>
                            </div>
                            <div style="color:rgba(255,255,255,.35); font-size:.72rem;"><?= $film['year'] ?></div>
                        </div>
                        <a href="/film.php?id=<?= (int)$film['id'] ?>" class="duel-info-link" target="_blank" onclick="event.stopPropagation()" style="display:block;text-align:center;padding:.1rem .5rem .4rem;color:rgba(255,255,255,.2);font-size:.7rem;text-decoration:none;" onmouseover="this.style.color='#e8b84b'" onmouseout="this.style.color='rgba(255,255,255,.2)'"><i class="bi bi-info-circle me-1"></i>Details</a>
                        <div class="rank-label empty">–</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Weiter-Button -->
            <div class="text-center mt-4">
                <button id="btn-weiter" class="btn btn-gold btn-lg px-5" onclick="submitRanking()">
                    <i class="bi bi-arrow-right me-1"></i>
                    <?= $state['current_round'] + 1 >= $state['total'] ? 'Auswertung' : 'Weiter' ?>
                </button>
                <p class="mt-2" style="color:rgba(255,255,255,.25); font-size:.78rem;">
                    Klicke einen bereits gewählten Film erneut, um ihn abzuwählen
                </p>
            </div>

            <form id="vote-form" method="post" style="display:none;">
                <input type="hidden" name="action" value="vote">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="ranking" id="ranking-input" value="">
            </form>

            <?php elseif ($isCompleted): ?>
            <!-- Abschluss-Karte -->
            <div style="background:rgba(76,175,80,.07); border:1px solid rgba(76,175,80,.2); border-radius:16px; padding:2.5rem; text-align:center;">
                <div style="font-size:3rem; margin-bottom:1rem;">🏆</div>
                <h3 class="fw-bold mb-2" style="color:#4caf50;">Bewertung abgeschlossen!</h3>
                <p style="color:rgba(255,255,255,.5);">
                    <?= $state['total'] ?> Runden · <?= count($state['results']) ?> <?= $typ ?> bewertet
                </p>
                <?php if (!empty($sessionRanking)): ?>
                <div style="background:rgba(255,255,255,.04); border-radius:12px; padding:1.2rem; margin:1.5rem 0; display:inline-block; min-width:260px;">
                    <div style="color:rgba(255,255,255,.4); font-size:.78rem; margin-bottom:.6rem;">TOP 3</div>
                    <?php foreach (array_slice($sessionRanking, 0, 3) as $i => $f): ?>
                    <div style="color:#e0e0e0; font-size:.9rem; margin:.2rem 0;">
                        <?= ['🥇','🥈','🥉'][$i] ?> <a href="/film.php?id=<?= (int)$f['id'] ?>" class="film-link" target="_blank"><?= e($f['display_title'] ?? movieTitle($f)) ?></a>
                        <span style="color:#e8b84b; font-size:.8rem; margin-left:.5rem;"><?= $f['score'] ?>P</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="mt-3">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="start">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="film_count" value="<?= $completedSession['film_count'] ?>">
                        <button type="submit" class="btn btn-gold me-2">
                            <i class="bi bi-arrow-clockwise me-1"></i>Nochmal (<?= $completedSession['film_count'] ?> <?= $typ ?>)
                        </button>
                    </form>
                    <a href="/fuenf-filme.php?new=1" class="btn btn-outline-light">Neue Runde</a>
                </div>
            </div>
            <?php endif; ?>

            </div><!-- /col-lg-8 -->
        </div><!-- /row -->
        <?php endif; ?>

        </div><!-- /container -->
    </section>

</main>

<?php if ($isVoting && !empty($currentRoundFilms)): ?>
<script>
const ranked = [];      // [{id, card}, ...]
const TOTAL_FILMS = <?= count($currentRoundFilms) ?>;
const LABELS = ['', '1. Platz', '2. Platz', '3. Platz', '4. Platz', '5. Platz'];

function rankFilm(card) {
    const id = card.dataset.id;
    const existing = ranked.findIndex(r => r.id === id);

    if (existing !== -1) {
        // Abwählen: diesen und alle nachfolgenden zurücksetzen
        const removed = ranked.splice(existing);
        removed.forEach(r => {
            r.card.classList.remove('ranked');
            r.card.querySelector('.rank-badge').style.display = 'none';
            const lbl = r.card.querySelector('.rank-label');
            lbl.textContent = '–';
            lbl.className = 'rank-label empty';
        });
        // Nachfolgende neu nummerieren (alle nach dem entfernten Punkt)
        // Bereits vor dem removed-Index liegende behalten ihre Badges → neu rendern
        refreshBadges();
    } else {
        if (ranked.length >= TOTAL_FILMS) return;
        ranked.push({ id, card });
        const rank = ranked.length;
        card.classList.add('ranked');
        const badge = card.querySelector('.rank-badge');
        badge.textContent = rank;
        badge.style.display = 'flex';
        const lbl = card.querySelector('.rank-label');
        lbl.textContent = LABELS[rank];
        lbl.className = 'rank-label set';
    }

    document.getElementById('btn-weiter').classList.toggle('ready', ranked.length === TOTAL_FILMS);
}

function refreshBadges() {
    ranked.forEach((r, i) => {
        const rank = i + 1;
        const badge = r.card.querySelector('.rank-badge');
        badge.textContent = rank;
        badge.style.display = 'flex';
        const lbl = r.card.querySelector('.rank-label');
        lbl.textContent = LABELS[rank];
        lbl.className = 'rank-label set';
    });
}

function submitRanking() {
    if (ranked.length < TOTAL_FILMS) return;
    document.getElementById('ranking-input').value = ranked.map(r => r.id).join(',');
    document.getElementById('vote-form').submit();
}
</script>
<?php endif; ?>


<?php require_once __DIR__ . '/includes/footer.php'; ?>
