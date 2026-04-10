<?php
$pageTitle   = 'User-Statistiken – MKFB';
$currentPage = 'user-statistiken';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Schritt 1: Alle User-Werte in wenigen gebündelten Queries ─────────────────

// Gesamt-Duelle + bewertete Filme (1 Query statt 3)
$st = $db->prepare("
    SELECT
        (SELECT COUNT(*) FROM comparisons WHERE user_id = ?) AS total_duels,
        (SELECT COUNT(DISTINCT movie_id) FROM user_ratings WHERE user_id = ? AND comparisons > 0) AS total_films
");
$st->execute([$userId, $userId]);
$totals     = $st->fetch(PDO::FETCH_ASSOC);
$totalDuels = (int)$totals['total_duels'];
$totalFilms = (int)$totals['total_films'];

// Alle einfachen Modus-Werte in einer Query (UNION über simple Tabellen)
$st = $db->prepare("
    SELECT 'sort'   AS mode, COUNT(*) AS sessions, COALESCE(SUM(film_count),0) AS val FROM sort_sessions  WHERE user_id=? AND status='completed'
    UNION ALL
    SELECT 'duel'   AS mode, COUNT(*) AS sessions, COALESCE(SUM(duels_done),0) AS val FROM duel_sessions  WHERE user_id=?
    UNION ALL
    SELECT 'fi'     AS mode, COUNT(*) AS sessions, 0                            AS val FROM film_insert_sessions WHERE user_id=? AND status='done'
    UNION ALL
    SELECT 'fi_all' AS mode, COUNT(*) AS sessions, 0                            AS val FROM film_insert_sessions WHERE user_id=?
    UNION ALL
    SELECT 'fuenf'  AS mode, COUNT(*) AS sessions, COALESCE(SUM(film_count),0) AS val FROM fuenf_sessions WHERE user_id=? AND status='completed'
    UNION ALL
    SELECT 'jgj_r'  AS mode, COUNT(*) AS sessions, 0                            AS val FROM jgj_results   WHERE user_id=?
    UNION ALL
    SELECT 'jgj_p'  AS mode, COUNT(*) AS sessions, 0                            AS val FROM jgj_pool      WHERE user_id=?
    UNION ALL
    SELECT 'turnier'AS mode, COUNT(*) AS sessions, COALESCE(SUM(film_count),0) AS val FROM user_tournaments WHERE user_id=? AND status='completed'
    UNION ALL
    SELECT 'liga'   AS mode, COUNT(*) AS sessions, 0                            AS val FROM liga_sessions  WHERE user_id=? AND status='completed'
");
$st->execute(array_fill(0, 9, $userId));
$mv = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $mv[$row['mode']] = $row; }

// Turnier- und Liga-Matches (JOIN nötig, separat)
$st = $db->prepare("SELECT COUNT(*) FROM tournament_matches tm JOIN user_tournaments ut ON ut.id=tm.tournament_id WHERE ut.user_id=? AND tm.winner_id IS NOT NULL");
$st->execute([$userId]); $matches = (int)$st->fetchColumn();
$st = $db->prepare("SELECT COUNT(*) FROM liga_matches lm JOIN liga_sessions ls ON ls.id=lm.liga_id WHERE ls.user_id=? AND lm.winner_id IS NOT NULL");
$st->execute([$userId]); $ligaMatches = (int)$st->fetchColumn();

$sortFilms  = (int)($mv['sort']['val']     ?? 0);
$zDuels     = (int)($mv['duel']['val']     ?? 0);
$fiDone     = (int)($mv['fi']['sessions']  ?? 0);
$total_fi   = (int)($mv['fi_all']['sessions'] ?? 0);
$fuenfFilms = (int)($mv['fuenf']['val']    ?? 0);
$jgjDuels   = (int)($mv['jgj_r']['sessions'] ?? 0);
$jgjFilms   = (int)($mv['jgj_p']['sessions'] ?? 0);
$jgjTotal   = $jgjFilms > 1 ? (int)($jgjFilms * ($jgjFilms - 1) / 2) : 0;

// ── Schritt 2: Community-Aggregationen gecacht (Top10 + Agg für Rang) ─────────
// Rang-Berechnung in PHP aus gecachten Daten — kein schwerer DB-Subquery
$uc = dbCache('user_stat_community', function() {
    $db = getDB();
    $d  = [];
    $d['turnier_top10'] = $db->query("SELECT u.username, COUNT(tm.id) AS cnt FROM users u JOIN user_tournaments ut ON ut.user_id=u.id LEFT JOIN tournament_matches tm ON tm.tournament_id=ut.id AND tm.winner_id IS NOT NULL GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['turnier_agg']   = $db->query("SELECT ut.user_id AS uid, COUNT(tm.id) AS cnt FROM user_tournaments ut LEFT JOIN tournament_matches tm ON tm.tournament_id=ut.id AND tm.winner_id IS NOT NULL GROUP BY ut.user_id")->fetchAll();
    $d['liga_top10']    = $db->query("SELECT u.username, COUNT(lm.id) AS cnt FROM users u JOIN liga_sessions ls ON ls.user_id=u.id LEFT JOIN liga_matches lm ON lm.liga_id=ls.id AND lm.winner_id IS NOT NULL GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['liga_agg']      = $db->query("SELECT ls.user_id AS uid, COUNT(lm.id) AS cnt FROM liga_sessions ls LEFT JOIN liga_matches lm ON lm.liga_id=ls.id AND lm.winner_id IS NOT NULL GROUP BY ls.user_id")->fetchAll();
    $d['sort_top10']    = $db->query("SELECT u.username, COALESCE(SUM(ss.film_count),0) AS cnt FROM users u JOIN sort_sessions ss ON ss.user_id=u.id AND ss.status='completed' GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['sort_agg']      = $db->query("SELECT user_id AS uid, COALESCE(SUM(film_count),0) AS cnt FROM sort_sessions WHERE status='completed' GROUP BY user_id")->fetchAll();
    $d['duel_top10']    = $db->query("SELECT u.username, COALESCE(SUM(ds.duels_done),0) AS cnt FROM users u JOIN duel_sessions ds ON ds.user_id=u.id GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['duel_agg']      = $db->query("SELECT user_id AS uid, COALESCE(SUM(duels_done),0) AS cnt FROM duel_sessions GROUP BY user_id")->fetchAll();
    $d['fi_top10']      = $db->query("SELECT u.username, COUNT(fi.id) AS cnt FROM users u JOIN film_insert_sessions fi ON fi.user_id=u.id AND fi.status='done' GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['fi_agg']        = $db->query("SELECT user_id AS uid, COUNT(*) AS cnt FROM film_insert_sessions WHERE status='done' GROUP BY user_id")->fetchAll();
    $d['jgj_top10']     = $db->query("SELECT u.username, COUNT(jr.id) AS cnt FROM users u JOIN jgj_results jr ON jr.user_id=u.id GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['jgj_agg']       = $db->query("SELECT user_id AS uid, COUNT(*) AS cnt FROM jgj_results GROUP BY user_id")->fetchAll();
    $d['fuenf_top10']   = $db->query("SELECT u.username, COALESCE(SUM(fs.film_count),0) AS cnt FROM users u JOIN fuenf_sessions fs ON fs.user_id=u.id AND fs.status='completed' GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['fuenf_agg']     = $db->query("SELECT user_id AS uid, COALESCE(SUM(film_count),0) AS cnt FROM fuenf_sessions WHERE status='completed' GROUP BY user_id")->fetchAll();
    return $d;
}, 300);

$rankFromAgg = function(array $agg, float $myVal): int {
    $n = 0;
    foreach ($agg as $r) { if ((float)$r['cnt'] > $myVal) $n++; }
    return $n + 1;
};

// ── Schritt 3: Modus-Statistiken zusammenbauen ────────────────────────────────
$modeStats = [];

$modeStats[] = ['icon'=>'bi-diagram-3','label'=>'Turnier','color'=>'#e8b84b',
    'rank'  => $rankFromAgg($uc['turnier_agg'], $matches),
    'top10' => $uc['turnier_top10'],
    'items' => [['k'=>'Abgeschl. Turniere','v'=>(int)($mv['turnier']['sessions']??0)],
                ['k'=>'Gespielte Matches','v'=>$matches],
                ['k'=>'Gerankte Filme','v'=>(int)($mv['turnier']['val']??0)]]];

$modeStats[] = ['icon'=>'bi-people-fill','label'=>'Liga','color'=>'#5b9bd5',
    'rank'  => $rankFromAgg($uc['liga_agg'], $ligaMatches),
    'top10' => $uc['liga_top10'],
    'items' => [['k'=>'Abgeschl. Sessionen','v'=>(int)($mv['liga']['sessions']??0)],
                ['k'=>'Gespielte Matches','v'=>$ligaMatches]]];

$modeStats[] = ['icon'=>'bi-sort-numeric-down','label'=>'Sortieren','color'=>'#7ec87e',
    'rank'  => $rankFromAgg($uc['sort_agg'], $sortFilms),
    'top10' => $uc['sort_top10'],
    'items' => [['k'=>'Abgeschl. Sessionen','v'=>(int)($mv['sort']['sessions']??0)],
                ['k'=>'Sortierte Filme','v'=>$sortFilms]]];

$modeStats[] = ['icon'=>'bi-shuffle','label'=>'Zufallsduelle','color'=>'#e07b7b',
    'rank'  => $rankFromAgg($uc['duel_agg'], $zDuels),
    'top10' => $uc['duel_top10'],
    'items' => [['k'=>'Sessionen','v'=>(int)($mv['duel']['sessions']??0)],
                ['k'=>'Gespielte Duelle','v'=>$zDuels]]];

$modeStats[] = ['icon'=>'bi-search-heart','label'=>'Film einordnen','color'=>'#c97ee0',
    'rank'  => $rankFromAgg($uc['fi_agg'], $fiDone),
    'top10' => $uc['fi_top10'],
    'items' => [['k'=>'Abgeschl. Einordnungen','v'=>$fiDone],
                ['k'=>'Gestartete Sessionen','v'=>$total_fi]]];

$modeStats[] = ['icon'=>'bi-diagram-3-fill','label'=>'Jeder gegen Jeden','color'=>'#f0a55a',
    'rank'  => $rankFromAgg($uc['jgj_agg'], $jgjDuels),
    'top10' => $uc['jgj_top10'],
    'items' => [['k'=>'Filme im Pool','v'=>$jgjFilms],
                ['k'=>'Absolvierte Duelle','v'=>$jgjDuels],
                ['k'=>'Mögliche Duelle','v'=>$jgjTotal]]];

$modeStats[] = ['icon'=>'bi-grid-3x2-gap-fill','label'=>'5 Filme','color'=>'#5bd5c9',
    'rank'  => $rankFromAgg($uc['fuenf_agg'], $fuenfFilms),
    'top10' => $uc['fuenf_top10'],
    'items' => [['k'=>'Abgeschl. Sessionen','v'=>(int)($mv['fuenf']['sessions']??0)],
                ['k'=>'Bewertete Filme','v'=>$fuenfFilms]]];

$username = $_SESSION['username'] ?? 'User';

// ── Genre-Top-10 — nur Top 500 Filme laden statt alle ─────────────────────────
$genreMap = [];
try {
    $stGenre = $db->prepare(
        "SELECT upr.position, m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.genre
         FROM user_position_ranking upr
         JOIN movies m ON m.id = upr.movie_id
         WHERE upr.user_id = ? AND m.genre IS NOT NULL AND m.genre != ''
         ORDER BY upr.position ASC
         LIMIT 500"
    );
    $stGenre->execute([$userId]);
    foreach ($stGenre->fetchAll() as $film) {
        foreach (array_map('trim', explode(',', $film['genre'])) as $g) {
            if ($g !== '') $genreMap[$g][] = $film;
        }
    }
    ksort($genreMap);
} catch (\PDOException $e) {}

// ── Bewertungen pro Tag ───────────────────────────────────────────────────────
$dailyStmt = $db->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM comparisons
    WHERE user_id = ?
    GROUP BY day
    ORDER BY day ASC");
$dailyStmt->execute([$userId]);
$dailyRatings = $dailyStmt->fetchAll();
$activeDays   = count($dailyRatings);
$avgPerDay    = $activeDays > 0 ? round(array_sum(array_column($dailyRatings, 'cnt')) / $activeDays, 1) : 0;
$maxPerDay    = $activeDays > 0 ? (int)max(array_column($dailyRatings, 'cnt')) : 0;
$chartDays    = array_slice($dailyRatings, -90);

require_once __DIR__ . '/includes/header.php';
?>
<style>
    body { background:#14325a !important; }
    .stat-card {
        background:rgba(255,255,255,.04);
        border:1px solid rgba(255,255,255,.08);
        border-radius:14px;
        padding:1.5rem;
    }
    .stat-card-title {
        font-size:.75rem; font-weight:700; letter-spacing:.08em;
        color:#e8b84b; text-transform:uppercase; margin-bottom:1.25rem;
        display:flex; align-items:center; gap:.5rem;
    }
    .summary-badge {
        display:inline-flex; align-items:center; gap:.4rem;
        background:rgba(232,184,75,.12); border:1px solid rgba(232,184,75,.25);
        color:#e8b84b; border-radius:20px; padding:4px 12px;
        font-size:.8rem; font-weight:600;
    }
</style>

<main class="py-5" style="min-height:80vh;">
    <div class="container">

        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h1 class="fw-bold mb-1" style="color:#e8b84b; font-size:1.8rem;">
                    <i class="bi bi-person-lines-fill me-2"></i>User-Statistiken
                </h1>
                <p class="mb-0" style="color:rgba(255,255,255,.45); font-size:.9rem;">
                    Deine persönliche Bewertungsübersicht
                </p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <span class="summary-badge">
                    <i class="bi bi-lightning-charge-fill"></i><?= number_format($totalDuels) ?> Duelle
                </span>
                <span class="summary-badge">
                    <i class="bi bi-film"></i><?= number_format($totalFilms) ?> Filme bewertet
                </span>
            </div>
        </div>

        <!-- ── Bewertungsmodi ─────────────────────────────────────────────── -->
        <div class="row g-4">
            <?php foreach ($modeStats as $mode): ?>
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="stat-card h-100">
                    <div class="stat-card-title" style="color:<?= $mode['color'] ?>;">
                        <i class="bi <?= $mode['icon'] ?>"></i><?= $mode['label'] ?>
                        <?php if (!empty($mode['rank'])): ?>
                        <span style="margin-left:auto; color:rgba(255,255,255,.35); font-size:.7rem; font-weight:400; white-space:nowrap;">Mein Rang: #<?= $mode['rank'] ?></span>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($mode['items'] as $item): ?>
                    <div class="d-flex justify-content-between align-items-baseline mb-2">
                        <span style="color:rgba(255,255,255,.5); font-size:.8rem;"><?= $item['k'] ?></span>
                        <span style="color:#e0e0e0; font-size:1rem; font-weight:700;"><?= number_format($item['v']) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!empty($mode['top10'])): ?>
                    <hr style="border-color:rgba(255,255,255,.08); margin:.75rem 0 .5rem;">
                    <div style="font-size:.68rem; color:rgba(255,255,255,.25); font-weight:700; letter-spacing:.07em; text-transform:uppercase; margin-bottom:.4rem;">Top 10</div>
                    <?php $maxT = max(array_column($mode['top10'], 'cnt')); ?>
                    <?php foreach ($mode['top10'] as $ti => $tu): ?>
                    <div style="display:flex; align-items:center; gap:.4rem; margin-bottom:.22rem;">
                        <span style="min-width:16px; font-size:.68rem; font-weight:700; color:<?= $ti < 3 ? $mode['color'] : 'rgba(255,255,255,.25)' ?>;"><?= $ti+1 ?></span>
                        <span style="flex:0 0 auto; width:85px; font-size:.75rem; color:rgba(255,255,255,.7); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= e($tu['username']) ?>"><?= e($tu['username']) ?></span>
                        <div style="flex:1; background:rgba(255,255,255,.06); border-radius:3px; height:5px; overflow:hidden;">
                            <div style="height:100%; background:<?= $mode['color'] ?>; width:<?= $maxT > 0 ? round($tu['cnt']/$maxT*100) : 0 ?>%; border-radius:3px; opacity:.6;"></div>
                        </div>
                        <span style="font-size:.7rem; color:rgba(255,255,255,.35); min-width:32px; text-align:right;"><?= number_format($tu['cnt']) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Bewertungen pro Tag ───────────────────────────────────────── -->
        <?php if ($activeDays > 0): ?>
        <h2 class="fw-bold mt-5 mb-3" style="color:#e8b84b; font-size:1.3rem;">
            <i class="bi bi-bar-chart-fill me-2"></i>Bewertungen pro Tag
        </h2>
        <div class="stat-card mb-4">
            <div class="d-flex gap-4 flex-wrap mb-3">
                <div>
                    <div style="color:rgba(255,255,255,.45); font-size:.75rem; text-transform:uppercase; letter-spacing:.07em;">Aktive Tage</div>
                    <div style="color:#e0e0e0; font-size:1.4rem; font-weight:700;"><?= number_format($activeDays) ?></div>
                </div>
                <div>
                    <div style="color:rgba(255,255,255,.45); font-size:.75rem; text-transform:uppercase; letter-spacing:.07em;">Ø pro Tag</div>
                    <div style="color:#e8b84b; font-size:1.4rem; font-weight:700;"><?= number_format($avgPerDay, 1) ?></div>
                </div>
                <div>
                    <div style="color:rgba(255,255,255,.45); font-size:.75rem; text-transform:uppercase; letter-spacing:.07em;">Bester Tag</div>
                    <div style="color:#7ec87e; font-size:1.4rem; font-weight:700;"><?= number_format($maxPerDay) ?></div>
                </div>
            </div>
            <?php if (!empty($chartDays)): ?>
            <?php
                $n       = count($chartDays);
                $barW    = 10; // px per bar
                $gap     = 2;
                $chartW  = $n * ($barW + $gap);
                // Label every ~10 bars, but always show first + last
                $labelStep = max(1, (int)ceil($n / 9));
            ?>
            <div style="font-size:.7rem; color:rgba(255,255,255,.3); margin-bottom:.5rem;">
                <?= $n < 90 ? 'Alle ' . $n . ' Tage' : 'Letzte 90 Tage' ?>
            </div>
            <div style="overflow-x:auto; overflow-y:hidden; scrollbar-width:thin; scrollbar-color:rgba(232,184,75,.15) transparent; padding-bottom:2px;">
                <div style="min-width:<?= $chartW ?>px; width:<?= $chartW ?>px;">
                    <!-- Bars -->
                    <div style="display:flex; align-items:flex-end; gap:<?= $gap ?>px; height:80px; margin-bottom:4px;">
                        <?php foreach ($chartDays as $d):
                            $pct = $maxPerDay > 0 ? max(2, round($d['cnt'] / $maxPerDay * 100)) : 2;
                        ?>
                        <div title="<?= e($d['day']) ?>: <?= (int)$d['cnt'] ?> Bewertungen"
                             style="width:<?= $barW ?>px; flex-shrink:0; height:<?= $pct ?>%; background:#e8b84b; opacity:.75; border-radius:2px 2px 0 0; cursor:default;"></div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Date labels -->
                    <div style="display:flex; gap:<?= $gap ?>px; align-items:flex-start;">
                        <?php foreach ($chartDays as $i => $d):
                            $show = ($i === 0 || $i === $n - 1 || $i % $labelStep === 0);
                            $label = $show ? date('d.m.', strtotime($d['day'])) : '';
                        ?>
                        <div style="width:<?= $barW ?>px; flex-shrink:0; display:flex; justify-content:center; <?= $show ? '' : 'visibility:hidden;' ?>">
                            <span style="font-size:.55rem; color:rgba(255,255,255,.4); white-space:nowrap; writing-mode:vertical-rl; transform:rotate(180deg); line-height:1;"><?= $label ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── Genre-Ranglisten ──────────────────────────────────────────── -->
        <?php if (!empty($genreMap)): ?>
        <h2 class="fw-bold mt-5 mb-3" style="color:#e8b84b; font-size:1.3rem;">
            <i class="bi bi-tags-fill me-2"></i>Meine Top-Filme nach Genre
        </h2>
        <div class="row g-4">
        <?php foreach ($genreMap as $genre => $films): ?>
        <?php $top = array_slice($films, 0, 10); ?>
        <div class="col-xl-3 col-lg-4 col-md-6">
            <div class="stat-card h-100">
                <div class="stat-card-title">
                    <i class="bi bi-tag-fill"></i><?= e($genre) ?>
                    <span style="margin-left:auto; color:rgba(255,255,255,.3); font-size:.7rem; font-weight:400;">
                        <?= count($films) ?> Filme
                    </span>
                </div>
                <?php foreach ($top as $i => $film):
                    $poster = moviePosterUrl($film, 'w92');
                    $medals = [0=>'🥇',1=>'🥈',2=>'🥉'];
                ?>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span style="min-width:1.4rem; font-size:.72rem; font-weight:700; color:<?= $i < 3 ? '#e8b84b' : 'rgba(255,255,255,.25)' ?>; text-align:right;">
                        <?= isset($medals[$i]) ? $medals[$i] : ($i+1) ?>
                    </span>
                    <img src="<?= e($poster) ?>" width="28" height="42"
                         style="border-radius:3px; object-fit:cover; flex-shrink:0;"
                         loading="lazy" decoding="async" onerror="this.src='https://placehold.co/28x42/1e3a5f/e8b84b?text=?'"
                         alt="<?= e(movieTitle($film)) ?>">
                    <div style="flex:1; min-width:0;">
                        <div style="color:#e0e0e0; font-size:.8rem; font-weight:600; white-space:nowrap;
                                    overflow:hidden; text-overflow:ellipsis;" title="<?= e(movieTitle($film)) ?>">
                            <a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a>
                        </div>
                        <div style="color:rgba(255,255,255,.3); font-size:.72rem;">
                            <?= (int)$film['year'] ?> &middot; Rang&nbsp;<?= number_format((int)$film['position']) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (count($films) > 10): ?>
                <div style="color:rgba(255,255,255,.25); font-size:.72rem; margin-top:.5rem; text-align:center;">
                    + <?= count($films) - 10 ?> weitere <?= e($genre) ?>-Filme
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
