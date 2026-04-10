<?php
/**
 * admin-fix-imdb-collision.php
 * Korrigiert falsche imdb_id-Werte die durch TMDB-Namespace-Kollisionen entstanden sind.
 * Verarbeitet in Batches von 50 um Server-Timeouts zu vermeiden.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); die('Nur für Admins.'); }

set_time_limit(90);

$db     = getDB();
$mode   = $_GET['mode']   ?? 'collision';
$offset = max(0, (int)($_GET['offset'] ?? 0));
$save   = isset($_GET['save']) && $_GET['save'] === '1';
$auto   = isset($_GET['auto']) && $_GET['auto'] === '1';
$BATCH  = 20;

// ── TMDB API helpers ──────────────────────────────────────────────────────────

function tmdbFetchImdbBatch(array $entries): array {
    $mh      = curl_multi_init();
    $handles = [];
    $caBundle = ini_get('curl.cainfo') ?: '';
    foreach ($entries as $e) {
        $type = ($e['media_type'] === 'tv') ? 'tv' : 'movie';
        $url  = "https://api.themoviedb.org/3/{$type}/{$e['tmdb_id']}/external_ids?api_key=" . TMDB_API_KEY;
        $ch   = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_USERAGENT=>'MKFB/1.0'];
        if ($caBundle && file_exists($caBundle)) $opts[CURLOPT_CAINFO] = $caBundle;
        curl_setopt_array($ch, $opts);
        $handles[$e['id']] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    $running = null;
    do { curl_multi_exec($mh, $running); if ($running > 0) curl_multi_select($mh, 0.3); } while ($running > 0);
    $out = [];
    foreach ($handles as $id => $ch) {
        $body = curl_multi_getcontent($ch);
        $data = $body ? json_decode($body, true) : null;
        $imdb = $data['imdb_id'] ?? null;
        $out[$id] = (is_string($imdb) && preg_match('/^tt\d{7,}$/', $imdb)) ? $imdb : null;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// ── Einträge laden ────────────────────────────────────────────────────────────

if ($mode === 'all_tv') {
    $totalCount = (int)$db->query("SELECT COUNT(*) FROM movies WHERE media_type='tv' AND tmdb_id IS NOT NULL")->fetchColumn();
    $stmt = $db->prepare("SELECT id, title, year, tmdb_id, imdb_id, media_type FROM movies WHERE media_type='tv' AND tmdb_id IS NOT NULL ORDER BY id LIMIT ? OFFSET ?");
    $stmt->execute([$BATCH, $offset]);
} else {
    $totalCount = (int)$db->query("SELECT COUNT(*) FROM movies WHERE tmdb_id IN (SELECT tmdb_id FROM movies WHERE tmdb_id IS NOT NULL GROUP BY tmdb_id HAVING COUNT(DISTINCT COALESCE(media_type,'movie')) > 1)")->fetchColumn();
    $stmt = $db->prepare("SELECT m.id, m.title, m.year, m.tmdb_id, m.imdb_id, COALESCE(m.media_type,'movie') AS media_type FROM movies m WHERE m.tmdb_id IN (SELECT tmdb_id FROM movies WHERE tmdb_id IS NOT NULL GROUP BY tmdb_id HAVING COUNT(DISTINCT COALESCE(media_type,'movie')) > 1) ORDER BY m.tmdb_id, COALESCE(m.media_type,'movie') LIMIT ? OFFSET ?");
    $stmt->execute([$BATCH, $offset]);
}
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── TMDB anfragen ─────────────────────────────────────────────────────────────

$results  = [];
$fixed    = 0;

if (!empty($entries)) {
    $imdbMap = tmdbFetchImdbBatch($entries);

    $upd = $save ? $db->prepare("UPDATE movies SET imdb_id = ? WHERE id = ?") : null;

    foreach ($entries as $e) {
        $correct = $imdbMap[$e['id']] ?? null;
        $oldImdb = $e['imdb_id'] ?? '';
        $changed = $correct !== null && $correct !== $oldImdb;

        if ($save && $changed) {
            $upd->execute([$correct, $e['id']]);
            $fixed++;
        }

        $status = $correct === null   ? 'Keine IMDb'
                : (!$changed          ? 'OK'
                : ($save              ? 'Korrigiert'
                :                       'Würde korrigieren'));

        $results[] = ['entry'=>$e, 'old'=>$oldImdb, 'new'=>$correct, 'changed'=>$changed, 'status'=>$status];
    }
}

$changedCount = count(array_filter($results, fn($r) => $r['changed']));
$nextOffset   = $offset + $BATCH;
$hasMore      = $nextOffset < $totalCount;
$pages        = (int)ceil($totalCount / $BATCH);
$currentPage  = (int)floor($offset / $BATCH) + 1;

$collisionCount = (int)$db->query("SELECT COUNT(*) FROM movies WHERE tmdb_id IN (SELECT tmdb_id FROM movies WHERE tmdb_id IS NOT NULL GROUP BY tmdb_id HAVING COUNT(DISTINCT COALESCE(media_type,'movie')) > 1)")->fetchColumn();
$tvCount        = (int)$db->query("SELECT COUNT(*) FROM movies WHERE media_type='tv' AND tmdb_id IS NOT NULL")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>IMDb-Kollisionen korrigieren</title>
<?php if ($auto && $hasMore): ?>
<meta http-equiv="refresh" content="2;url=?mode=<?= $mode ?>&offset=<?= $nextOffset ?>&save=1&auto=1">
<?php endif; ?>
<style>
body  { font-family: monospace; background: #14325a; color: #e0e0e0; padding: 2rem; }
h1    { color: #e8b84b; margin-bottom: .25rem; }
h2    { color: #e8b84b; font-size: 1rem; margin: 1.5rem 0 .5rem; }
p     { margin: .3rem 0; }
.info { color: #a0c4e8; }
table { border-collapse: collapse; width: 100%; margin-top: 1rem; font-size: .85rem; }
th,td { border: 1px solid rgba(255,255,255,.12); padding: .35rem .7rem; text-align: left; }
th    { background: rgba(232,184,75,.15); color: #e8b84b; }
.ok   { color: #7ec87e; }
.warn { color: #f0a55a; }
.err  { color: #e07b7b; }
.chg  { color: #f0a55a; font-weight: 700; }
.tag-tv    { background: #a78bfa22; color: #c4b5fd; border: 1px solid #a78bfa55; padding: 0 .4rem; border-radius: 4px; }
.tag-movie { background: #e8b84b22; color: #e8b84b;  border: 1px solid #e8b84b55; padding: 0 .4rem; border-radius: 4px; }
.btn  { display: inline-block; margin: .4rem .3rem 0 0; padding: .45rem 1.1rem;
        border-radius: 6px; background: #e8b84b; color: #14325a;
        font-weight: 700; text-decoration: none; cursor: pointer; border: none; font-size: .95rem; }
.btn.sec  { background: rgba(255,255,255,.12); color: #e0e0e0; }
.btn.tv   { background: #a78bfa; color: #fff; }
.btn.save { background: #5cb85c; color: #fff; }
.btn.auto { background: #17a2b8; color: #fff; }
.btn.stop { background: #dc3545; color: #fff; }
nav   { margin-bottom: 1.5rem; }
.pager { margin-top: 1rem; display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
.progress-bar { background: rgba(255,255,255,.1); border-radius: 6px; height: 10px; margin: .5rem 0; }
.progress-fill { background: #e8b84b; height: 10px; border-radius: 6px; transition: width .3s; }
</style>
</head>
<body>
<h1>IMDb-Kollisionen / Korrekturen</h1>

<nav>
    <a href="?mode=collision&offset=0" class="btn <?= $mode !== 'collision' ? 'sec' : '' ?>">
        Kollisionen (<?= $collisionCount ?>)
    </a>
    <a href="?mode=all_tv&offset=0" class="btn tv <?= $mode !== 'all_tv' ? 'sec' : '' ?>">
        Alle TV-Serien (<?= $tvCount ?>)
    </a>
</nav>

<?php if ($mode === 'all_tv'): ?>
<p class="info">Prüft alle TV-Serien: holt die korrekte imdb_id vom TV-Endpunkt und meldet Abweichungen.</p>
<?php else: ?>
<p class="info">Zeigt Einträge mit TMDB-Namespace-Kollision (gleiche TMDB-ID für Film + Serie).</p>
<?php endif; ?>

<!-- Fortschritt -->
<p style="margin-top:.8rem;">
    Seite <strong><?= $currentPage ?></strong> von <strong><?= $pages ?></strong>
    &nbsp;·&nbsp; Einträge <?= $offset+1 ?>–<?= min($offset+$BATCH, $totalCount) ?> von <strong><?= $totalCount ?></strong>
    &nbsp;·&nbsp; Diese Seite: <strong class="<?= $changedCount ? 'warn' : 'ok' ?>"><?= $changedCount ?> Korrekturen</strong>
    <?php if ($save && $fixed > 0): ?>&nbsp;·&nbsp;<span class="ok">✓ <?= $fixed ?> gespeichert</span><?php endif; ?>
</p>
<div class="progress-bar"><div class="progress-fill" style="width:<?= round(min($offset+$BATCH,$totalCount)/$totalCount*100) ?>%"></div></div>

<!-- Aktions-Buttons -->
<div class="pager">
    <?php if ($changedCount > 0 && !$save): ?>
    <a href="?mode=<?= $mode ?>&offset=<?= $offset ?>&save=1" class="btn save">
        ✓ <?= $changedCount ?> Korrekturen auf dieser Seite speichern
    </a>
    <?php endif; ?>

    <?php if ($hasMore): ?>
    <a href="?mode=<?= $mode ?>&offset=<?= $nextOffset ?>" class="btn">
        Weiter → Seite <?= $currentPage+1 ?> / <?= $pages ?>
    </a>
    <?php if ($save || $changedCount === 0): ?>
    <a href="?mode=<?= $mode ?>&offset=<?= $nextOffset ?>&save=1" class="btn save">
        Speichern &amp; Weiter →
    </a>
    <?php endif; ?>
    <?php if (!$auto): ?>
    <a href="?mode=<?= $mode ?>&offset=<?= $nextOffset ?>&save=1&auto=1" class="btn auto">
        ⚡ Auto
    </a>
    <?php else: ?>
    <a href="?mode=<?= $mode ?>&offset=<?= $nextOffset ?>&save=1" class="btn stop">
        ⏹ Stop
    </a>
    <span class="info" style="padding:.45rem 0; font-size:.85rem;">Weiter in 2 Sek…</span>
    <?php endif; ?>
    <?php else: ?>
    <span class="ok" style="padding:.45rem 0;">✓ Letzte Seite erreicht</span>
    <?php endif; ?>

    <?php if ($offset > 0): ?>
    <a href="?mode=<?= $mode ?>&offset=<?= max(0,$offset-$BATCH) ?>" class="btn sec">← Zurück</a>
    <?php endif; ?>
</div>

<!-- Ergebnis-Tabelle -->
<table>
<thead>
<tr>
    <th>ID</th><th>Typ</th><th>Titel</th><th>Jahr</th><th>TMDB-ID</th>
    <th>IMDb alt</th><th>IMDb neu (TMDB)</th><th>Status</th>
</tr>
</thead>
<tbody>
<?php foreach ($results as $r):
    $cls = str_contains($r['status'], 'OK') || str_contains($r['status'], 'rrigiert') ? 'ok'
         : (str_contains($r['status'], 'Würde') ? 'warn' : 'err');
?>
<tr>
    <td><?= (int)$r['entry']['id'] ?></td>
    <td><?= $r['entry']['media_type'] === 'tv' ? '<span class="tag-tv">TV</span>' : '<span class="tag-movie">Film</span>' ?></td>
    <td><?= htmlspecialchars($r['entry']['title']) ?></td>
    <td><?= (int)$r['entry']['year'] ?></td>
    <td><?= (int)$r['entry']['tmdb_id'] ?></td>
    <td><?= htmlspecialchars($r['old'] ?: '–') ?></td>
    <td class="<?= $r['changed'] ? 'chg' : 'ok' ?>"><?= htmlspecialchars($r['new'] ?? '–') ?></td>
    <td class="<?= $cls ?>"><?= htmlspecialchars($r['status']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- Pager unten -->
<div class="pager" style="margin-top:1rem;">
    <?php if ($hasMore): ?>
    <a href="?mode=<?= $mode ?>&offset=<?= $nextOffset ?>" class="btn">Weiter →</a>
    <?php if ($save || $changedCount === 0): ?>
    <a href="?mode=<?= $mode ?>&offset=<?= $nextOffset ?>&save=1" class="btn save">Speichern &amp; Weiter →</a>
    <?php endif; ?>
    <?php if (!$auto): ?>
    <a href="?mode=<?= $mode ?>&offset=<?= $nextOffset ?>&save=1&auto=1" class="btn auto">⚡ Auto</a>
    <?php else: ?>
    <a href="?mode=<?= $mode ?>&offset=<?= $nextOffset ?>&save=1" class="btn stop">⏹ Stop</a>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($offset > 0): ?>
    <a href="?mode=<?= $mode ?>&offset=<?= max(0,$offset-$BATCH) ?>" class="btn sec">← Zurück</a>
    <?php endif; ?>
    <a href="?mode=<?= $mode ?>&offset=0" class="btn sec">↑ Anfang</a>
</div>

</body>
</html>
