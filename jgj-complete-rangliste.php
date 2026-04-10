<?php
$pageTitle = 'Jeder gegen Jeden Komplett – Rangliste';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!isSuperAdmin()) { header('Location: /'); exit; }

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// Stats
$totalPairs = (int)$db->query("SELECT COUNT(*) FROM jgj_complete_pairs WHERE user_id=$userId")->fetchColumn();
$evaluated  = (int)$db->query("SELECT COUNT(*) FROM jgj_complete_pairs WHERE user_id=$userId AND winner_id IS NOT NULL")->fetchColumn();
$pct        = $totalPairs > 0 ? round($evaluated / $totalPairs * 100, 1) : 0;

// Ranking: films with at least 1 duel, ordered by wins DESC then losses ASC
$stmt = $db->prepare("
    SELECT s.movie_id, s.wins, s.losses,
           (s.wins + s.losses) AS total,
           m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.imdb_id
    FROM jgj_complete_scores s
    JOIN movies m ON m.id = s.movie_id
    WHERE s.user_id = ?
      AND (s.wins + s.losses) > 0" . seriesSqlFilter('m') . moviesSqlFilter('m') . "
    ORDER BY s.wins DESC, s.losses ASC, m.title ASC
");
$stmt->execute([$userId]);
$films = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>
<style>
.jgj-rank-table { width:100%; border-collapse:separate; border-spacing:0; table-layout:fixed; }
.jgj-rank-table col.col-rank   { width:52px; }
.jgj-rank-table col.col-poster { width:48px; }
.jgj-rank-table col.col-title  { }  /* flex */
.jgj-rank-table col.col-wins   { width:90px; }
.jgj-rank-table col.col-losses { width:110px; }
.jgj-rank-table col.col-total  { width:80px; }
.jgj-rank-table col.col-bar    { width:170px; }
.jgj-rank-table thead th {
    position:sticky; top:0; z-index:2;
    background:#0d1b2a; border-bottom:2px solid rgba(255,255,255,.1);
    padding:.6rem .75rem; font-size:.78rem; text-transform:uppercase;
    letter-spacing:.05em; color:rgba(255,255,255,.5); font-weight:600;
    white-space:nowrap;
}
.jgj-rank-table tbody tr {
    border-bottom:1px solid rgba(255,255,255,.05);
    transition:background .12s;
}
.jgj-rank-table tbody tr:hover { background:rgba(255,255,255,.03); }
.jgj-rank-table td { padding:.55rem .75rem; vertical-align:middle; overflow:hidden; }
.rank-num { font-weight:800; font-size:.9rem; color:rgba(255,255,255,.35); text-align:center; }
.rank-num.top1 { color:#e8b84b; }
.rank-num.top2 { color:#c0c0c0; }
.rank-num.top3 { color:#cd7f32; }
.film-poster-sm { width:32px; height:48px; object-fit:cover; border-radius:3px; }
.win-bar-wrap { background:rgba(255,255,255,.08); border-radius:4px; height:6px; overflow:hidden; width:100%; max-width:90px; }
.win-bar      { height:100%; background:var(--mkfb-gold); border-radius:4px; }
.badge-wins   { background:rgba(46,213,115,.15); color:#2ed573; border:1px solid rgba(46,213,115,.25); border-radius:20px; padding:1px 8px; font-size:.72rem; font-weight:700; }
.badge-losses { background:rgba(255,71,87,.12); color:#ff4757; border:1px solid rgba(255,71,87,.2); border-radius:20px; padding:1px 8px; font-size:.72rem; font-weight:700; }
</style>

<main class="container py-4">

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-trophy-fill fs-3" style="color:var(--mkfb-gold);"></i>
        <h1 class="h4 mb-0 fw-bold">JgJ Komplett – Rangliste</h1>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="/jgj-complete.php" class="btn btn-sm btn-gold">
            <i class="bi bi-play-fill me-1"></i>Bewerten
        </a>
        <a href="/jgj-complete-build.php" class="btn btn-sm btn-outline-light">
            <i class="bi bi-grid-3x3 me-1"></i>Spielplan
        </a>
    </div>
</div>

<!-- Progress -->
<div class="mb-4" style="max-width:700px;">
    <div class="d-flex justify-content-between small mb-1 opacity-50">
        <span><?= number_format($evaluated) ?> / <?= number_format($totalPairs) ?> Duelle bewertet</span>
        <span><?= $pct ?>%</span>
    </div>
    <div style="background:rgba(255,255,255,.08);border-radius:6px;height:6px;overflow:hidden;">
        <div style="background:var(--mkfb-gold);height:100%;border-radius:6px;width:<?= $pct ?>%;"></div>
    </div>
</div>

<?php if (empty($films)): ?>
<div class="text-center py-5 opacity-50">
    <i class="bi bi-hourglass fs-1 d-block mb-3"></i>
    <p>Noch keine Duelle bewertet. <a href="/jgj-complete.php">Jetzt starten →</a></p>
</div>
<?php else: ?>

<div class="small opacity-50 mb-2"><?= number_format(count($films)) ?> Filme mit mindestens 1 Duell</div>

<div style="overflow-x:auto; overflow-y:auto; max-height:calc(100vh - 230px); border:1px solid rgba(255,255,255,.08); border-radius:8px;">
<table class="jgj-rank-table">
    <colgroup>
        <col class="col-rank">
        <col class="col-poster">
        <col class="col-title">
        <col class="col-wins">
        <col class="col-losses">
        <col class="col-total">
        <col class="col-bar">
    </colgroup>
    <thead>
        <tr>
            <th class="text-center">#</th>
            <th></th>
            <th>Film</th>
            <th class="text-center">Siege</th>
            <th class="text-center">Niederlagen</th>
            <th class="text-center">Duelle</th>
            <th>Siegquote</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($films as $i => $f):
        $rank    = $i + 1;
        $total   = (int)$f['total'];
        $wins    = (int)$f['wins'];
        $losses  = (int)$f['losses'];
        $rate    = $total > 0 ? round($wins / $total * 100, 1) : 0;
        $title   = movieTitle(['title'=>$f['title'],'title_en'=>$f['title_en']]);
        $poster  = moviePosterUrl(['poster_path'=>$f['poster_path'],'poster_path_en'=>$f['poster_path_en']], 'w92');
        $rankClass = $rank === 1 ? 'top1' : ($rank === 2 ? 'top2' : ($rank === 3 ? 'top3' : ''));
    ?>
    <tr>
        <td class="rank-num <?= $rankClass ?>"><?= $rank ?></td>
        <td style="width:40px;">
            <img src="<?= e($poster) ?>" class="film-poster-sm"
                 onerror="this.onerror=null;this.src='/img/no-poster.svg'" alt="">
        </td>
        <td>
            <div class="fw-600" style="font-size:.88rem; line-height:1.3;"><?= e($title) ?></div>
            <div style="font-size:.73rem; color:rgba(255,255,255,.35);"><?= (int)$f['year'] ?></div>
        </td>
        <td class="text-center"><span class="badge-wins"><?= number_format($wins) ?></span></td>
        <td class="text-center"><span class="badge-losses"><?= number_format($losses) ?></span></td>
        <td class="text-center" style="font-size:.8rem; color:rgba(255,255,255,.45);"><?= number_format($total) ?></td>
        <td>
            <div class="d-flex align-items-center gap-2">
                <div class="win-bar-wrap">
                    <div class="win-bar" style="width:<?= $rate ?>%;"></div>
                </div>
                <span style="font-size:.75rem; color:rgba(255,255,255,.55); white-space:nowrap; min-width:34px;"><?= $rate ?>%</span>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php endif; ?>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
