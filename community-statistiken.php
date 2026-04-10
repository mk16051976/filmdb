<?php
$pageTitle    = 'Community-Statistiken – MKFB';
$currentPage  = 'community-statistiken';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Alle nicht-user-spezifischen Daten gecacht (5 Min TTL) ───────────────────
// Cache-Miss: ~25 Queries einmalig; Cache-Hit: 0 Queries für Community-Daten
$cc = dbCache('comm_stat_all', function() {
    $db = getDB();
    $d  = [];

    // Demographics
    $d['gender'] = $db->query("SELECT COALESCE(NULLIF(gender,''),'Keine Angabe') AS label, COUNT(*) AS cnt FROM users GROUP BY label ORDER BY cnt DESC")->fetchAll();
    $d['nat']    = $db->query("SELECT COALESCE(NULLIF(nationality,''),'Keine Angabe') AS label, COUNT(*) AS cnt FROM users GROUP BY label ORDER BY cnt DESC")->fetchAll();
    $d['birth']  = $db->query("SELECT birth_year AS label, COUNT(*) AS cnt FROM users WHERE birth_year IS NOT NULL AND birth_year > 0 GROUP BY birth_year ORDER BY birth_year ASC")->fetchAll();
    $d['total_users'] = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

    // Turnier
    $d['turnier_r']       = $db->query("SELECT COUNT(*) AS sessions, COALESCE(SUM(film_count),0) AS films FROM user_tournaments WHERE status='completed'")->fetch();
    $d['turnier_matches'] = (int)$db->query("SELECT COUNT(*) FROM tournament_matches WHERE winner_id IS NOT NULL")->fetchColumn();
    $d['turnier_top10']   = $db->query("SELECT u.username, COUNT(tm.id) AS cnt FROM users u JOIN user_tournaments ut ON ut.user_id=u.id LEFT JOIN tournament_matches tm ON tm.tournament_id=ut.id AND tm.winner_id IS NOT NULL GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['turnier_agg']     = $db->query("SELECT ut.user_id AS uid, COUNT(tm.id) AS cnt FROM user_tournaments ut LEFT JOIN tournament_matches tm ON tm.tournament_id=ut.id AND tm.winner_id IS NOT NULL GROUP BY ut.user_id")->fetchAll();

    // Liga
    $d['liga_r']       = $db->query("SELECT COUNT(*) AS sessions FROM liga_sessions WHERE status='completed'")->fetch();
    $d['liga_matches'] = (int)$db->query("SELECT COUNT(*) FROM liga_matches WHERE winner_id IS NOT NULL")->fetchColumn();
    $d['liga_top10']   = $db->query("SELECT u.username, COUNT(lm.id) AS cnt FROM users u JOIN liga_sessions ls ON ls.user_id=u.id LEFT JOIN liga_matches lm ON lm.liga_id=ls.id AND lm.winner_id IS NOT NULL GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['liga_agg']     = $db->query("SELECT ls.user_id AS uid, COUNT(lm.id) AS cnt FROM liga_sessions ls LEFT JOIN liga_matches lm ON lm.liga_id=ls.id AND lm.winner_id IS NOT NULL GROUP BY ls.user_id")->fetchAll();

    // Sortieren
    $d['sort_r']     = $db->query("SELECT COUNT(*) AS sessions, COALESCE(SUM(film_count),0) AS films FROM sort_sessions WHERE status='completed'")->fetch();
    $d['sort_top10'] = $db->query("SELECT u.username, COALESCE(SUM(ss.film_count),0) AS cnt FROM users u JOIN sort_sessions ss ON ss.user_id=u.id AND ss.status='completed' GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['sort_agg']   = $db->query("SELECT user_id AS uid, COALESCE(SUM(film_count),0) AS cnt FROM sort_sessions WHERE status='completed' GROUP BY user_id")->fetchAll();

    // Zufallsduelle
    $d['duel_r']     = $db->query("SELECT COUNT(*) AS sessions, COALESCE(SUM(duels_done),0) AS duels FROM duel_sessions")->fetch();
    $d['duel_top10'] = $db->query("SELECT u.username, COALESCE(SUM(ds.duels_done),0) AS cnt FROM users u JOIN duel_sessions ds ON ds.user_id=u.id GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['duel_agg']   = $db->query("SELECT user_id AS uid, COALESCE(SUM(duels_done),0) AS cnt FROM duel_sessions GROUP BY user_id")->fetchAll();

    // Film einordnen
    $d['fi_done']  = (int)$db->query("SELECT COUNT(*) FROM film_insert_sessions WHERE status='done'")->fetchColumn();
    $d['fi_total'] = (int)$db->query("SELECT COUNT(*) FROM film_insert_sessions")->fetchColumn();
    $d['fi_top10'] = $db->query("SELECT u.username, COUNT(fi.id) AS cnt FROM users u JOIN film_insert_sessions fi ON fi.user_id=u.id AND fi.status='done' GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['fi_agg']   = $db->query("SELECT user_id AS uid, COUNT(*) AS cnt FROM film_insert_sessions WHERE status='done' GROUP BY user_id")->fetchAll();

    // Jeder gegen Jeden
    $d['jgj_duels'] = (int)$db->query("SELECT COUNT(*) FROM jgj_results")->fetchColumn();
    $d['jgj_users'] = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM jgj_pool")->fetchColumn();
    $d['jgj_films'] = (int)$db->query("SELECT COUNT(*) FROM jgj_pool")->fetchColumn();
    $d['jgj_top10'] = $db->query("SELECT u.username, COUNT(jr.id) AS cnt FROM users u JOIN jgj_results jr ON jr.user_id=u.id GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['jgj_agg']   = $db->query("SELECT user_id AS uid, COUNT(*) AS cnt FROM jgj_results GROUP BY user_id")->fetchAll();

    // 5 Filme
    $d['fuenf_r']     = $db->query("SELECT COUNT(*) AS sessions, COALESCE(SUM(film_count),0) AS films FROM fuenf_sessions WHERE status='completed'")->fetch();
    $d['fuenf_top10'] = $db->query("SELECT u.username, COALESCE(SUM(fs.film_count),0) AS cnt FROM users u JOIN fuenf_sessions fs ON fs.user_id=u.id AND fs.status='completed' GROUP BY u.id,u.username HAVING cnt>0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $d['fuenf_agg']   = $db->query("SELECT user_id AS uid, COALESCE(SUM(film_count),0) AS cnt FROM fuenf_sessions WHERE status='completed' GROUP BY user_id")->fetchAll();

    // Top-50 Rankings
    $d['duels_ranking']  = $db->query("SELECT u.username, COUNT(c.id) AS cnt FROM users u JOIN comparisons c ON c.user_id=u.id GROUP BY u.id,u.username ORDER BY cnt DESC LIMIT 50")->fetchAll();
    $d['films_ranking']  = $db->query("SELECT u.username, COUNT(DISTINCT ur.movie_id) AS cnt FROM users u JOIN user_ratings ur ON ur.user_id=u.id AND ur.comparisons>0 GROUP BY u.id,u.username ORDER BY cnt DESC LIMIT 50")->fetchAll();
    $d['perday_ranking'] = $db->query("SELECT u.username, COUNT(c.id) AS total_cnt, GREATEST(1,DATEDIFF(MAX(c.created_at),MIN(c.created_at))+1) AS active_days, ROUND(COUNT(c.id)/GREATEST(1,DATEDIFF(MAX(c.created_at),MIN(c.created_at))+1),1) AS avg_per_day FROM users u JOIN comparisons c ON c.user_id=u.id GROUP BY u.id,u.username HAVING total_cnt>0 ORDER BY avg_per_day DESC LIMIT 50")->fetchAll();
    $d['perday_agg']     = $db->query("SELECT user_id AS uid, ROUND(COUNT(id)/GREATEST(1,DATEDIFF(MAX(created_at),MIN(created_at))+1),1) AS apd FROM comparisons GROUP BY user_id")->fetchAll();

    // Beste Einzeltage Top 50
    $d['best_days'] = $db->query("SELECT u.username, DATE(c.created_at) AS day, COUNT(c.id) AS cnt FROM comparisons c JOIN users u ON u.id=c.user_id GROUP BY c.user_id,day ORDER BY cnt DESC LIMIT 50")->fetchAll();

    // Community-Tages-Chart
    $d['daily'] = $db->query("SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM comparisons GROUP BY day ORDER BY day ASC")->fetchAll();

    return $d;
}, 300);

// ── Demographics entpacken ────────────────────────────────────────────────────
$genderRows  = $cc['gender'];
$totalGender = array_sum(array_column($genderRows, 'cnt'));
$natRows     = $cc['nat'];
$totalNat    = array_sum(array_column($natRows, 'cnt'));
$birthRows   = $cc['birth'];
$totalBirth  = array_sum(array_column($birthRows, 'cnt'));
$totalUsers  = $cc['total_users'];

// ── Rankings entpacken ────────────────────────────────────────────────────────
$duelsRanking    = $cc['duels_ranking'];
$filmsRanking    = $cc['films_ranking'];
$perDayRanking   = $cc['perday_ranking'];
$bestDaysRanking = $cc['best_days'];

// ── Tages-Chart entpacken ─────────────────────────────────────────────────────
$commDailyRatings = $cc['daily'];
$commActiveDays   = count($commDailyRatings);
$commTotal        = $commActiveDays > 0 ? array_sum(array_column($commDailyRatings, 'cnt')) : 0;
$commAvgPerDay    = $commActiveDays > 0 ? round($commTotal / $commActiveDays, 1) : 0;
$commMaxPerDay    = $commActiveDays > 0 ? (int)max(array_column($commDailyRatings, 'cnt')) : 0;
$commChartDays    = array_slice($commDailyRatings, -90);

// ── Rang-Berechnung in PHP aus gecachten Aggregationsdaten ────────────────────
// Kein weiterer DB-Roundtrip nötig — O(n) in PHP statt schwerer Subquery
$rankFromAgg = function(array $agg, float $myVal): int {
    $n = 0;
    foreach ($agg as $r) { if ((float)$r['cnt'] > $myVal) $n++; }
    return $n + 1;
};

// ── User-eigene Werte (schnell: einzelne user_id-Queries mit Index) ───────────
$st = $db->prepare("SELECT COUNT(tm.id) FROM user_tournaments ut LEFT JOIN tournament_matches tm ON tm.tournament_id=ut.id AND tm.winner_id IS NOT NULL WHERE ut.user_id=?");
$st->execute([$userId]); $myTurnierMatches = (int)$st->fetchColumn();

$st = $db->prepare("SELECT COUNT(lm.id) FROM liga_sessions ls LEFT JOIN liga_matches lm ON lm.liga_id=ls.id AND lm.winner_id IS NOT NULL WHERE ls.user_id=?");
$st->execute([$userId]); $myLigaMatches = (int)$st->fetchColumn();

$st = $db->prepare("SELECT COALESCE(SUM(film_count),0) FROM sort_sessions WHERE user_id=? AND status='completed'");
$st->execute([$userId]); $mySortFilms = (int)$st->fetchColumn();

$st = $db->prepare("SELECT COALESCE(SUM(duels_done),0) FROM duel_sessions WHERE user_id=?");
$st->execute([$userId]); $myZDuels = (int)$st->fetchColumn();

$st = $db->prepare("SELECT COUNT(*) FROM film_insert_sessions WHERE user_id=? AND status='done'");
$st->execute([$userId]); $myFiDone = (int)$st->fetchColumn();

$st = $db->prepare("SELECT COUNT(*) FROM jgj_results WHERE user_id=?");
$st->execute([$userId]); $myJgjDuels = (int)$st->fetchColumn();

$st = $db->prepare("SELECT COALESCE(SUM(film_count),0) FROM fuenf_sessions WHERE user_id=? AND status='completed'");
$st->execute([$userId]); $myFuenfFilms = (int)$st->fetchColumn();

// Per-day Durchschnitt + Rang
$stMyPd = $db->prepare("SELECT COUNT(id) AS total_cnt, GREATEST(1,DATEDIFF(MAX(created_at),MIN(created_at))+1) AS active_days FROM comparisons WHERE user_id=?");
$stMyPd->execute([$userId]);
$myPdRow     = $stMyPd->fetch();
$myAvgPerDay = ($myPdRow && (float)$myPdRow['active_days'] > 0)
    ? round((float)$myPdRow['total_cnt'] / (float)$myPdRow['active_days'], 1) : 0;
$myPerDayRank = count(array_filter($cc['perday_agg'], fn($r) => (float)$r['apd'] > (float)$myAvgPerDay)) + 1;

// ── Mode-Stats zusammenbauen ──────────────────────────────────────────────────
$modeStats = [];
$modeStats[] = ['icon'=>'bi-diagram-3','label'=>'Turnier','color'=>'#e8b84b',
    'rank'  => $rankFromAgg($cc['turnier_agg'], $myTurnierMatches),
    'top10' => $cc['turnier_top10'],
    'items' => [['k'=>'Abgeschl. Turniere','v'=>(int)$cc['turnier_r']['sessions']],
                ['k'=>'Gespielte Matches','v'=>$cc['turnier_matches']],
                ['k'=>'Gerankte Filme','v'=>(int)$cc['turnier_r']['films']]]];
$modeStats[] = ['icon'=>'bi-people-fill','label'=>'Liga','color'=>'#5b9bd5',
    'rank'  => $rankFromAgg($cc['liga_agg'], $myLigaMatches),
    'top10' => $cc['liga_top10'],
    'items' => [['k'=>'Abgeschl. Sessionen','v'=>(int)$cc['liga_r']['sessions']],
                ['k'=>'Gespielte Matches','v'=>$cc['liga_matches']]]];
$modeStats[] = ['icon'=>'bi-sort-numeric-down','label'=>'Sortieren','color'=>'#7ec87e',
    'rank'  => $rankFromAgg($cc['sort_agg'], $mySortFilms),
    'top10' => $cc['sort_top10'],
    'items' => [['k'=>'Abgeschl. Sessionen','v'=>(int)$cc['sort_r']['sessions']],
                ['k'=>'Sortierte Filme','v'=>(int)$cc['sort_r']['films']]]];
$modeStats[] = ['icon'=>'bi-shuffle','label'=>'Zufallsduelle','color'=>'#e07b7b',
    'rank'  => $rankFromAgg($cc['duel_agg'], $myZDuels),
    'top10' => $cc['duel_top10'],
    'items' => [['k'=>'Sessionen','v'=>(int)$cc['duel_r']['sessions']],
                ['k'=>'Gespielte Duelle','v'=>(int)$cc['duel_r']['duels']]]];
$modeStats[] = ['icon'=>'bi-search-heart','label'=>'Film einordnen','color'=>'#c97ee0',
    'rank'  => $rankFromAgg($cc['fi_agg'], $myFiDone),
    'top10' => $cc['fi_top10'],
    'items' => [['k'=>'Abgeschl. Einordnungen','v'=>$cc['fi_done']],
                ['k'=>'Gestartete Sessionen','v'=>$cc['fi_total']]]];
$modeStats[] = ['icon'=>'bi-diagram-3-fill','label'=>'Jeder gegen Jeden','color'=>'#f0a55a',
    'rank'  => $rankFromAgg($cc['jgj_agg'], $myJgjDuels),
    'top10' => $cc['jgj_top10'],
    'items' => [['k'=>'Aktive User','v'=>$cc['jgj_users']],
                ['k'=>'Filme im Pool','v'=>$cc['jgj_films']],
                ['k'=>'Absolvierte Duelle','v'=>$cc['jgj_duels']]]];
$modeStats[] = ['icon'=>'bi-grid-3x2-gap-fill','label'=>'5 Filme','color'=>'#5bd5c9',
    'rank'  => $rankFromAgg($cc['fuenf_agg'], $myFuenfFilms),
    'top10' => $cc['fuenf_top10'],
    'items' => [['k'=>'Abgeschl. Sessionen','v'=>(int)$cc['fuenf_r']['sessions']],
                ['k'=>'Bewertete Filme','v'=>(int)$cc['fuenf_r']['films']]]];

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
    .bar-row { display:flex; align-items:center; gap:.75rem; margin-bottom:.6rem; }
    .bar-label { min-width:130px; font-size:.85rem; color:rgba(255,255,255,.75); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .bar-track { flex:1; background:rgba(255,255,255,.06); border-radius:4px; height:10px; overflow:hidden; }
    .bar-fill  { height:100%; border-radius:4px; background:#e8b84b; transition:width .5s ease; }
    .bar-fill.alt { background:#5b9bd5; }
    .bar-fill.alt2 { background:#7ec87e; }
    .bar-count { min-width:40px; text-align:right; font-size:.8rem; color:rgba(255,255,255,.5); }
    .bar-pct   { min-width:38px; text-align:right; font-size:.8rem; font-weight:600; color:#e8b84b; }
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
                    <i class="bi bi-bar-chart-fill me-2"></i>Community-Statistiken
                </h1>
                <p class="mb-0" style="color:rgba(255,255,255,.45); font-size:.9rem;">
                    Demografische Übersicht aller registrierten Nutzer
                </p>
            </div>
            <span class="summary-badge">
                <i class="bi bi-people-fill"></i><?= number_format($totalUsers) ?> Nutzer gesamt
            </span>
        </div>

        <div class="row g-4">

            <!-- ── Geschlechtsverteilung ──────────────────────────────────── -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card h-100">
                    <div class="stat-card-title">
                        <i class="bi bi-gender-ambiguous"></i>Geschlecht
                    </div>
                    <?php if (empty($genderRows)): ?>
                        <p style="color:rgba(255,255,255,.3); font-size:.85rem;">Keine Daten</p>
                    <?php else: ?>
                        <?php $maxG = max(array_column($genderRows, 'cnt')); ?>
                        <?php $colors = ['#e8b84b','#5b9bd5','#7ec87e','#e07b7b']; ?>
                        <?php foreach ($genderRows as $i => $row): ?>
                        <?php $pct = $totalGender > 0 ? round($row['cnt'] / $totalGender * 100, 1) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-label"><?= e($row['label']) ?></div>
                            <div class="bar-track">
                                <div class="bar-fill" style="width:<?= $maxG > 0 ? round($row['cnt']/$maxG*100) : 0 ?>%; background:<?= $colors[$i % count($colors)] ?>;"></div>
                            </div>
                            <div class="bar-count"><?= $row['cnt'] ?></div>
                            <div class="bar-pct" style="color:<?= $colors[$i % count($colors)] ?>;"><?= $pct ?>%</div>
                        </div>
                        <?php endforeach; ?>
                        <p class="mt-3 mb-0" style="color:rgba(255,255,255,.25); font-size:.75rem;">
                            Basis: <?= $totalGender ?> Nutzer mit Angabe
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Geburtsjahr ────────────────────────────────────────────── -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card h-100">
                    <div class="stat-card-title">
                        <i class="bi bi-calendar3"></i>Geburtsjahr
                    </div>
                    <?php if (empty($birthRows)): ?>
                        <p style="color:rgba(255,255,255,.3); font-size:.85rem;">Keine Daten</p>
                    <?php else: ?>
                        <?php $maxB = max(array_column($birthRows, 'cnt')); ?>
                        <?php foreach ($birthRows as $row): ?>
                        <?php $pct = $totalBirth > 0 ? round($row['cnt'] / $totalBirth * 100, 1) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-label"><?= (int)$row['label'] ?></div>
                            <div class="bar-track">
                                <div class="bar-fill alt2" style="width:<?= $maxB > 0 ? round($row['cnt']/$maxB*100) : 0 ?>%;"></div>
                            </div>
                            <div class="bar-count"><?= $row['cnt'] ?></div>
                            <div class="bar-pct" style="color:#7ec87e;"><?= $pct ?>%</div>
                        </div>
                        <?php endforeach; ?>
                        <p class="mt-3 mb-0" style="color:rgba(255,255,255,.25); font-size:.75rem;">
                            Basis: <?= $totalBirth ?> Nutzer mit Angabe
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Nationalitätsverteilung ────────────────────────────────── -->
            <div class="col-lg-4 col-md-12">
                <div class="stat-card h-100">
                    <div class="stat-card-title">
                        <i class="bi bi-globe2"></i>Nationalität
                    </div>
                    <?php if (empty($natRows)): ?>
                        <p style="color:rgba(255,255,255,.3); font-size:.85rem;">Keine Daten</p>
                    <?php else: ?>
                        <?php $maxN = max(array_column($natRows, 'cnt')); ?>
                        <?php foreach ($natRows as $row): ?>
                        <?php $pct = $totalNat > 0 ? round($row['cnt'] / $totalNat * 100, 1) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-label" title="<?= e($row['label']) ?>"><?= e($row['label']) ?></div>
                            <div class="bar-track">
                                <div class="bar-fill alt" style="width:<?= $maxN > 0 ? round($row['cnt']/$maxN*100) : 0 ?>%;"></div>
                            </div>
                            <div class="bar-count"><?= $row['cnt'] ?></div>
                            <div class="bar-pct" style="color:#5b9bd5;"><?= $pct ?>%</div>
                        </div>
                        <?php endforeach; ?>
                        <p class="mt-3 mb-0" style="color:rgba(255,255,255,.25); font-size:.75rem;">
                            Basis: <?= $totalNat ?> Nutzer mit Angabe
                        </p>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── Community: Bewertungen pro Tag ──────────────────────────── -->
        <?php if ($commActiveDays > 0): ?>
        <h2 class="fw-bold mt-5 mb-3" style="color:#e8b84b; font-size:1.3rem;">
            <i class="bi bi-bar-chart-fill me-2"></i>Bewertungen pro Tag – Community
        </h2>
        <div class="stat-card mb-2">
            <div class="d-flex gap-4 flex-wrap mb-3">
                <div>
                    <div style="color:rgba(255,255,255,.45); font-size:.75rem; text-transform:uppercase; letter-spacing:.07em;">Aktive Tage</div>
                    <div style="color:#e0e0e0; font-size:1.4rem; font-weight:700;"><?= number_format($commActiveDays) ?></div>
                </div>
                <div>
                    <div style="color:rgba(255,255,255,.45); font-size:.75rem; text-transform:uppercase; letter-spacing:.07em;">Ø pro Tag</div>
                    <div style="color:#e8b84b; font-size:1.4rem; font-weight:700;"><?= number_format($commAvgPerDay, 1) ?></div>
                </div>
                <div>
                    <div style="color:rgba(255,255,255,.45); font-size:.75rem; text-transform:uppercase; letter-spacing:.07em;">Bester Tag</div>
                    <div style="color:#7ec87e; font-size:1.4rem; font-weight:700;"><?= number_format($commMaxPerDay) ?></div>
                </div>
                <div>
                    <div style="color:rgba(255,255,255,.45); font-size:.75rem; text-transform:uppercase; letter-spacing:.07em;">Bewertungen gesamt</div>
                    <div style="color:#5b9bd5; font-size:1.4rem; font-weight:700;"><?= number_format($commTotal) ?></div>
                </div>
            </div>
            <?php if (!empty($commChartDays)):
                $n       = count($commChartDays);
                $barW    = 10;
                $gap     = 2;
                $chartW  = $n * ($barW + $gap);
                $labelStep = max(1, (int)ceil($n / 9));
            ?>
            <div style="font-size:.7rem; color:rgba(255,255,255,.3); margin-bottom:.5rem;">
                <?= $n < 90 ? 'Alle ' . $n . ' Tage' : 'Letzte 90 Tage' ?>
            </div>
            <div style="overflow-x:auto; overflow-y:hidden; scrollbar-width:thin; scrollbar-color:rgba(232,184,75,.15) transparent; padding-bottom:2px;">
                <div style="min-width:<?= $chartW ?>px; width:<?= $chartW ?>px;">
                    <div style="display:flex; align-items:flex-end; gap:<?= $gap ?>px; height:80px; margin-bottom:4px;">
                        <?php foreach ($commChartDays as $d):
                            $pct = $commMaxPerDay > 0 ? max(2, round($d['cnt'] / $commMaxPerDay * 100)) : 2;
                        ?>
                        <div title="<?= e($d['day']) ?>: <?= number_format((int)$d['cnt']) ?> Bewertungen"
                             style="width:<?= $barW ?>px; flex-shrink:0; height:<?= $pct ?>%; background:#e8b84b; opacity:.75; border-radius:2px 2px 0 0; cursor:default;"></div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex; gap:<?= $gap ?>px; align-items:flex-start;">
                        <?php foreach ($commChartDays as $i => $d):
                            $show  = ($i === 0 || $i === $n - 1 || $i % $labelStep === 0);
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

        <!-- ── Top 50 beste Einzeltage ─────────────────────────────────── -->
        <?php if (!empty($bestDaysRanking)): ?>
        <h2 class="fw-bold mt-5 mb-3" style="color:#e8b84b; font-size:1.3rem;">
            <i class="bi bi-trophy-fill me-2"></i>Beste Bewertungstage – Top 50
        </h2>
        <div class="stat-card mb-2">
            <?php $maxBD = (int)$bestDaysRanking[0]['cnt']; ?>
            <div style="max-height:900px; overflow-y:auto; scrollbar-width:thin; scrollbar-color:rgba(232,184,75,.2) transparent;">
            <?php foreach ($bestDaysRanking as $i => $row):
                $barPct  = $maxBD > 0 ? round($row['cnt'] / $maxBD * 100) : 0;
                $isTop3  = $i < 3;
                $medals  = ['🥇','🥈','🥉'];
                $rankColor = $isTop3 ? '#e8b84b' : 'rgba(255,255,255,.3)';
                $date    = date('d.m.Y', strtotime($row['day']));
            ?>
            <div class="bar-row" style="margin-bottom:.5rem;">
                <div style="min-width:28px; font-size:.78rem; font-weight:700; color:<?= $rankColor ?>; text-align:right; flex-shrink:0;">
                    <?= $isTop3 ? $medals[$i] : ($i + 1) ?>
                </div>
                <div style="min-width:110px; flex-shrink:0; font-size:.82rem; color:rgba(255,255,255,.8); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= e($row['username']) ?>">
                    <?= e($row['username']) ?>
                </div>
                <div style="min-width:72px; flex-shrink:0; font-size:.75rem; color:rgba(255,255,255,.35);">
                    <?= $date ?>
                </div>
                <div class="bar-track" style="flex:1;">
                    <div class="bar-fill" style="width:<?= $barPct ?>%; background:<?= $isTop3 ? '#e8b84b' : 'rgba(232,184,75,.45)' ?>;"></div>
                </div>
                <div style="min-width:55px; text-align:right; font-size:.82rem; font-weight:700; color:<?= $isTop3 ? '#e8b84b' : 'rgba(255,255,255,.6)' ?>;">
                    <?= number_format($row['cnt']) ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Zweite Zeile: User-Rankings ──────────────────────────────── -->
        <div class="row g-4 mt-1">

            <!-- Ranking: Bewertungen (Duelle) -->
            <div class="col-lg-6">
                <div class="stat-card">
                    <div class="stat-card-title">
                        <i class="bi bi-lightning-charge-fill"></i>Meiste Bewertungen (Duelle)
                    </div>
                    <?php if (empty($duelsRanking)): ?>
                        <p style="color:rgba(255,255,255,.3); font-size:.85rem;">Keine Daten</p>
                    <?php else: ?>
                        <?php $maxDuels = max(array_column($duelsRanking, 'cnt')); ?>
                        <div style="max-height:340px; overflow-y:auto; scrollbar-width:thin; scrollbar-color:rgba(232,184,75,.2) transparent;">
                        <?php foreach ($duelsRanking as $i => $row): ?>
                        <div class="bar-row">
                            <div style="min-width:22px; font-size:.75rem; font-weight:700; color:<?= $i < 3 ? '#e8b84b' : 'rgba(255,255,255,.3)' ?>;">
                                <?= $i + 1 ?>
                            </div>
                            <div class="bar-label" style="min-width:110px;" title="<?= e($row['username']) ?>"><?= e($row['username']) ?></div>
                            <div class="bar-track">
                                <div class="bar-fill" style="width:<?= $maxDuels > 0 ? round($row['cnt']/$maxDuels*100) : 0 ?>%;"></div>
                            </div>
                            <div class="bar-count" style="min-width:55px;"><?= number_format($row['cnt']) ?></div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ranking: Bewertete Filme -->
            <div class="col-lg-6">
                <div class="stat-card">
                    <div class="stat-card-title">
                        <i class="bi bi-film"></i>Meiste bewertete Filme
                    </div>
                    <?php if (empty($filmsRanking)): ?>
                        <p style="color:rgba(255,255,255,.3); font-size:.85rem;">Keine Daten</p>
                    <?php else: ?>
                        <?php $maxFilms = max(array_column($filmsRanking, 'cnt')); ?>
                        <div style="max-height:340px; overflow-y:auto; scrollbar-width:thin; scrollbar-color:rgba(232,184,75,.2) transparent;">
                        <?php foreach ($filmsRanking as $i => $row): ?>
                        <div class="bar-row">
                            <div style="min-width:22px; font-size:.75rem; font-weight:700; color:<?= $i < 3 ? '#e8b84b' : 'rgba(255,255,255,.3)' ?>;">
                                <?= $i + 1 ?>
                            </div>
                            <div class="bar-label" style="min-width:110px;" title="<?= e($row['username']) ?>"><?= e($row['username']) ?></div>
                            <div class="bar-track">
                                <div class="bar-fill alt" style="width:<?= $maxFilms > 0 ? round($row['cnt']/$maxFilms*100) : 0 ?>%;"></div>
                            </div>
                            <div class="bar-count" style="min-width:55px;"><?= number_format($row['cnt']) ?></div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── Ranking: Bewertungen pro Tag ─────────────────────────────── -->
        <?php if (!empty($perDayRanking)): ?>
        <div class="row g-4 mt-1">
            <div class="col-12">
                <div class="stat-card">
                    <div class="stat-card-title">
                        <i class="bi bi-bar-chart-fill"></i>Meiste Bewertungen pro Tag (Ø)
                        <span style="margin-left:auto; color:rgba(255,255,255,.35); font-size:.7rem; font-weight:400; white-space:nowrap;">Mein Rang: #<?= $myPerDayRank ?> &nbsp;·&nbsp; Ø <?= number_format($myAvgPerDay, 1) ?>/Tag</span>
                    </div>
                    <?php $maxApd = max(array_column($perDayRanking, 'avg_per_day')); ?>
                    <div style="max-height:340px; overflow-y:auto; scrollbar-width:thin; scrollbar-color:rgba(232,184,75,.2) transparent;">
                    <?php foreach ($perDayRanking as $i => $row): ?>
                    <div class="bar-row">
                        <div style="min-width:22px; font-size:.75rem; font-weight:700; color:<?= $i < 3 ? '#e8b84b' : 'rgba(255,255,255,.3)' ?>;">
                            <?= $i + 1 ?>
                        </div>
                        <div class="bar-label" style="min-width:110px;" title="<?= e($row['username']) ?>"><?= e($row['username']) ?></div>
                        <div class="bar-track">
                            <div class="bar-fill" style="width:<?= $maxApd > 0 ? round($row['avg_per_day']/$maxApd*100) : 0 ?>%; background:#7ec87e;"></div>
                        </div>
                        <div class="bar-count" style="min-width:75px;">
                            <?= number_format($row['avg_per_day'], 1) ?>/Tag
                            <span style="color:rgba(255,255,255,.3); font-size:.68rem;">(<?= $row['active_days'] ?>d)</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Dritte Zeile: Bewertungsmodi ─────────────────────────────── -->
        <div class="row g-4 mt-1">
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
                    <div style="margin-top:.75rem; border-top:1px solid rgba(255,255,255,.07); padding-top:.6rem;">
                        <div style="color:rgba(255,255,255,.3); font-size:.65rem; font-weight:700; letter-spacing:.06em; margin-bottom:.4rem;">TOP 10</div>
                        <?php $maxVal = max(array_column($mode['top10'], 'cnt')) ?: 1; ?>
                        <?php foreach ($mode['top10'] as $ti => $tr): ?>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span style="min-width:1.2rem; font-size:.68rem; font-weight:700; color:<?= $ti < 3 ? $mode['color'] : 'rgba(255,255,255,.3)' ?>; text-align:right;"><?= $ti+1 ?></span>
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:.72rem; color:rgba(255,255,255,.7); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= e($tr['username']) ?></div>
                                <div style="height:3px; border-radius:2px; background:rgba(255,255,255,.07); margin-top:2px;">
                                    <div style="height:3px; border-radius:2px; background:<?= $mode['color'] ?>; width:<?= round($tr['cnt']/$maxVal*100) ?>%; opacity:.7;"></div>
                                </div>
                            </div>
                            <span style="font-size:.72rem; color:#e0e0e0; font-weight:600; white-space:nowrap;"><?= number_format($tr['cnt']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
