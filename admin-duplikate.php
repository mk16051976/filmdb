<?php
/**
 * admin-duplikate.php
 * Sucht und bereinigt doppelte Einträge in der movies-Tabelle.
 * Tab-Inhalte werden per AJAX + Pagination geladen.
 */
require_once __DIR__ . '/includes/functions.php';
startSession();
requireLogin();

$db = getDB();
$me = $db->prepare("SELECT role FROM users WHERE id = ?");
$me->execute([$_SESSION['user_id']]);
if (!in_array($me->fetchColumn(), ['Admin', 'Superadmin'])) {
    http_response_code(403); die('Nur für Admins.');
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    $deleteId = (int)($_POST['delete_id'] ?? 0);
    if ($deleteId > 0) {
        foreach (['user_ratings', 'user_position_ranking', 'comparisons'] as $tbl) {
            try { $db->prepare("DELETE FROM $tbl WHERE movie_id = ?")->execute([$deleteId]); }
            catch (\PDOException $e) {}
        }
        foreach (['tournament_matches'] as $tbl) {
            try { $db->prepare("DELETE FROM $tbl WHERE movie_id_a = ? OR movie_id_b = ?")->execute([$deleteId, $deleteId]); }
            catch (\PDOException $e) {}
        }
        foreach (['tournament_pool'] as $tbl) {
            try { $db->prepare("DELETE FROM $tbl WHERE movie_id = ?")->execute([$deleteId]); }
            catch (\PDOException $e) {}
        }
        try { $db->prepare("DELETE FROM comparisons WHERE winner_id = ? OR loser_id = ?")->execute([$deleteId, $deleteId]); }
        catch (\PDOException $e) {}
        $db->prepare("DELETE FROM movies WHERE id = ?")->execute([$deleteId]);
        // AJAX delete → JSON response
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            session_write_close();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        header('Location: /admin-duplikate.php?deleted=1');
        exit;
    }
}

// ── AUTO-MERGE: gleiche TMDB-ID, einer mit imdb_id, einer ohne ───────────────
if (isset($_GET['auto_merge'])) {
    // CSRF manuell prüfen (GET-Request)
    $submittedCsrf = $_GET['csrf'] ?? '';
    if ($submittedCsrf === '' || !hash_equals($_SESSION['csrf_token'], $submittedCsrf)) {
        header('Content-Type: application/json');
        echo json_encode(['merged' => 0, 'errors' => 1, 'msg' => 'CSRF ungültig']);
        exit;
    }
    session_write_close(); // Session freigeben – blockiert keine weiteren Requests
    header('Content-Type: application/json');
    set_time_limit(120);
    try {
        // Schritt 1: Mapping ermitteln — welche Original-ID bekommt welche imdb_id?
        // Subquery-Ansatz vermeidet MySQL Self-JOIN-Beschränkungen
        $batchSize = 100;
        $offset    = max(0, (int)($_GET['offset'] ?? 0));

        $pairs = $db->prepare(
            "SELECT a.id AS orig_id, MIN(b.id) AS dup_id, MIN(b.imdb_id) AS imdb_id,
                    MIN(b.poster_path) AS poster
             FROM movies a
             JOIN movies b ON b.tmdb_id = a.tmdb_id
                          AND b.id > a.id
                          AND b.imdb_id IS NOT NULL AND b.imdb_id != ''
             WHERE a.tmdb_id IS NOT NULL
               AND (a.imdb_id IS NULL OR a.imdb_id = '')
             GROUP BY a.id
             LIMIT ? OFFSET ?"
        );
        $pairs->execute([$batchSize, $offset]);
        $data = $pairs->fetchAll(PDO::FETCH_ASSOC);

        $deleted = 0;
        $upImdb  = $db->prepare("UPDATE movies SET imdb_id     = ? WHERE id = ? AND (imdb_id     IS NULL OR imdb_id     = '')");
        $upPost  = $db->prepare("UPDATE movies SET poster_path = ? WHERE id = ? AND (poster_path IS NULL OR poster_path = '')");
        $delDup  = $db->prepare(
            "DELETE FROM movies WHERE id = ?
             AND NOT EXISTS (SELECT 1 FROM user_ratings          WHERE movie_id = ?)
             AND NOT EXISTS (SELECT 1 FROM user_position_ranking WHERE movie_id = ?)
             AND NOT EXISTS (SELECT 1 FROM comparisons WHERE winner_id = ? OR loser_id = ?)"
        );

        foreach ($data as $p) {
            $origId = (int)$p['orig_id'];
            $dupId  = (int)$p['dup_id'];
            $upImdb->execute([$p['imdb_id'], $origId]);
            if ($p['poster']) $upPost->execute([$p['poster'], $origId]);
            $delDup->execute([$dupId, $dupId, $dupId, $dupId, $dupId]);
            if ($delDup->rowCount() > 0) $deleted++;
        }

        // Gibt zurück ob noch mehr Batches folgen
        $hasMore = count($data) === $batchSize;
        echo json_encode([
            'merged'  => $deleted,
            'errors'  => 0,
            'hasMore' => $hasMore,
            'next'    => $offset + $batchSize,
            'batch'   => count($data),
        ]);
    } catch (\PDOException $e) {
        echo json_encode(['merged' => 0, 'errors' => 1, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Tab-Inhalt laden ─────────────────────────────────────────────────────
if (isset($_GET['ajax_tab'])) {
    session_write_close(); // Session freigeben
    header('Content-Type: text/html; charset=utf-8');
    $tab   = $_GET['ajax_tab'];
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;

    if ($tab === 'imdb') {
        $total = (int)$db->query(
            "SELECT COUNT(*) FROM (SELECT imdb_id FROM movies WHERE imdb_id IS NOT NULL AND imdb_id != ''
             GROUP BY imdb_id HAVING COUNT(*) > 1) sub"
        )->fetchColumn();
        $rows = $db->prepare(
            "SELECT imdb_id, COUNT(*) AS cnt,
                    GROUP_CONCAT(id            ORDER BY id SEPARATOR ',') AS ids,
                    GROUP_CONCAT(title         ORDER BY id SEPARATOR '|||') AS titles,
                    GROUP_CONCAT(IFNULL(year,'?') ORDER BY id SEPARATOR ',') AS years,
                    GROUP_CONCAT(IFNULL(tmdb_id,'') ORDER BY id SEPARATOR ',') AS extra
             FROM movies WHERE imdb_id IS NOT NULL AND imdb_id != ''
             GROUP BY imdb_id HAVING cnt > 1
             ORDER BY cnt DESC, imdb_id LIMIT ? OFFSET ?"
        );
        $rows->execute([$limit, $offset]);
        $keyLabel = 'IMDB-ID'; $keyField = 'imdb_id';

    } elseif ($tab === 'tmdb') {
        $total = (int)$db->query(
            "SELECT COUNT(*) FROM (SELECT tmdb_id FROM movies WHERE tmdb_id IS NOT NULL
             GROUP BY tmdb_id HAVING COUNT(*) > 1) sub"
        )->fetchColumn();
        $rows = $db->prepare(
            "SELECT tmdb_id, COUNT(*) AS cnt,
                    GROUP_CONCAT(id            ORDER BY id SEPARATOR ',') AS ids,
                    GROUP_CONCAT(title         ORDER BY id SEPARATOR '|||') AS titles,
                    GROUP_CONCAT(IFNULL(year,'?') ORDER BY id SEPARATOR ',') AS years,
                    GROUP_CONCAT(IFNULL(imdb_id,'') ORDER BY id SEPARATOR ',') AS extra
             FROM movies WHERE tmdb_id IS NOT NULL
             GROUP BY tmdb_id HAVING cnt > 1
             ORDER BY cnt DESC, tmdb_id LIMIT ? OFFSET ?"
        );
        $rows->execute([$limit, $offset]);
        $keyLabel = 'TMDB-ID'; $keyField = 'tmdb_id';

    } elseif ($tab === 'title') {
        $total = (int)$db->query(
            "SELECT COUNT(*) FROM (SELECT LOWER(title), year FROM movies WHERE year IS NOT NULL
             GROUP BY LOWER(title), year HAVING COUNT(*) > 1) sub"
        )->fetchColumn();
        $rows = $db->prepare(
            "SELECT LOWER(title) AS norm_title, year, COUNT(*) AS cnt,
                    GROUP_CONCAT(id            ORDER BY id SEPARATOR ',') AS ids,
                    GROUP_CONCAT(title         ORDER BY id SEPARATOR '|||') AS titles,
                    GROUP_CONCAT(IFNULL(imdb_id,'') ORDER BY id SEPARATOR ',') AS extra
             FROM movies WHERE year IS NOT NULL
             GROUP BY LOWER(title), year HAVING cnt > 1
             ORDER BY cnt DESC, norm_title LIMIT ? OFFSET ?"
        );
        $rows->execute([$limit, $offset]);
        $keyLabel = 'Titel+Jahr'; $keyField = 'norm_title';

    } else {
        exit;
    }

    $data = $rows->fetchAll(PDO::FETCH_ASSOC);

    // Alle betroffenen movie-IDs auf einmal laden (kein N+1)
    $allIds = [];
    foreach ($data as $row) {
        foreach (explode(',', $row['ids']) as $id) {
            $allIds[] = (int)$id;
        }
    }
    $metaMap = [];
    if ($allIds) {
        $ph = implode(',', array_fill(0, count($allIds), '?'));
        $sm = $db->prepare(
            "SELECT m.id, m.poster_path, m.imdb_id,
                    (SELECT COUNT(*) FROM user_ratings          WHERE movie_id  = m.id) AS ratings,
                    (SELECT COUNT(*) FROM user_position_ranking WHERE movie_id  = m.id) AS positions,
                    (SELECT COUNT(*) FROM comparisons           WHERE winner_id = m.id OR loser_id = m.id) AS comps
             FROM movies m WHERE m.id IN ($ph)"
        );
        $sm->execute($allIds);
        foreach ($sm->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $metaMap[(int)$r['id']] = $r;
        }
    }

    if (empty($data)) {
        echo '<div class="text-center py-5" style="color:rgba(255,255,255,.3);">';
        echo '<i class="bi bi-check-circle fs-1 d-block mb-2" style="color:#4caf50;"></i>Keine Duplikate gefunden.</div>';
        exit;
    }

    $pages = (int)ceil($total / $limit);

    echo '<p class="small mb-2" style="color:rgba(255,255,255,.75);">' . $total . ' Duplikat-Gruppen gefunden – Seite ' . $page . ' von ' . $pages . '</p>';
    echo '<div class="table-responsive">';
    echo '<table class="table table-dark table-hover table-sm" style="font-size:.875rem;">';
    echo '<thead style="background:rgba(255,255,255,.05);">
            <tr>
                <th>' . $keyLabel . '</th>
                <th>Anzahl</th>
                <th>Einträge</th>
                <th>Löschen</th>
            </tr>
          </thead><tbody>';

    foreach ($data as $row) {
        $ids    = array_map('intval', explode(',', $row['ids']));
        $titles = explode('|||', $row['titles']);
        $years  = explode(',', $row['years']);
        $extras = explode(',', $row['extra'] ?? '');

        // Welcher Eintrag hat die wenigsten Daten?
        $minScore = PHP_INT_MAX; $minId = $ids[0];
        foreach ($ids as $mid) {
            $m = $metaMap[$mid] ?? [];
            $score = ($m['ratings'] ?? 0) + ($m['positions'] ?? 0) + ($m['comps'] ?? 0);
            if ($score < $minScore) { $minScore = $score; $minId = $mid; }
        }

        $keyVal = $tab === 'imdb'  ? e($row['imdb_id'])
                : ($tab === 'tmdb' ? (int)$row['tmdb_id']
                                   : e($row['norm_title']) . ' (' . e($row['year']) . ')');

        echo '<tr>';

        // Key
        echo '<td style="color:#e8b84b;font-family:monospace;vertical-align:top;">';
        if ($tab === 'imdb') echo '<a href="https://www.imdb.com/title/' . e($row['imdb_id']) . '/" target="_blank" style="color:#e8b84b;">' . e($row['imdb_id']) . '</a>';
        else echo $keyVal;
        echo '</td>';

        // Count
        echo '<td style="vertical-align:top;"><span class="badge" style="background:rgba(220,53,69,.7);">' . (int)$row['cnt'] . '×</span></td>';

        // Entries
        echo '<td style="vertical-align:top;">';
        foreach ($ids as $k => $mid) {
            $m        = $metaMap[$mid] ?? [];
            $t        = $titles[$k] ?? '?';
            $y        = $years[$k]  ?? '';
            $ext      = $extras[$k] ?? '';
            $posterPath = $m['poster_path'] ?? null;
            $imdbId     = $m['imdb_id']     ?? null;
            $imgUrl     = posterUrl($posterPath, $imdbId);
            echo '<div class="mb-2 d-flex gap-2 align-items-start">';
            // Poster
            echo '<img src="' . e($imgUrl) . '" alt="" '
               . 'style="width:38px;height:56px;object-fit:cover;border-radius:3px;flex-shrink:0;background:#1a2a3a;" '
               . 'onerror="this.src=\'/assets/no-poster.svg\'">';
            // Info
            echo '<div>';
            echo '<div class="d-flex flex-wrap gap-2 align-items-baseline">';
            echo '<span style="color:rgba(255,255,255,.35);font-size:.72rem;">#' . $mid . '</span>';
            echo '<span style="font-size:.875rem;">' . e($t) . '</span>';
            if ($y && $y !== '?') echo '<span style="color:rgba(255,255,255,.4);font-size:.8rem;">' . e($y) . '</span>';
            if ($ext) echo '<span style="color:rgba(255,255,255,.25);font-size:.7rem;font-family:monospace;">' . e($ext) . '</span>';
            echo '</div>';
            if ($m) echo '<div style="color:rgba(255,255,255,.25);font-size:.7rem;margin-top:2px;">'
                . ($m['ratings'] ?? 0) . ' Ratings &middot; '
                . ($m['positions'] ?? 0) . ' Positionen &middot; '
                . ($m['comps'] ?? 0) . ' Vergleiche</div>';
            echo '</div>';
            echo '</div>';
        }
        echo '</td>';

        // Delete buttons
        echo '<td style="vertical-align:top;">';
        $csrf = csrfToken();
        foreach ($ids as $mid) {
            $highlight = ($mid === $minId) ? 'rgba(220,53,69,.85)' : 'rgba(220,53,69,.3)';
            $star      = ($mid === $minId) ? ' ★' : '';
            echo '<button onclick="dupDelete(' . $mid . ', this, \'' . $csrf . '\')"
                          class="btn btn-sm mb-1 d-block"
                          style="background:' . $highlight . ';color:#fff;border:none;font-size:.75rem;padding:2px 8px;">
                      <i class="bi bi-trash me-1"></i>#' . $mid . $star . '
                  </button>';
        }
        echo '</td>';

        echo '</tr>';
    }

    echo '</tbody></table></div>';

    // Pagination
    if ($pages > 1) {
        echo '<div class="d-flex gap-2 mt-3 flex-wrap">';
        for ($p = 1; $p <= $pages; $p++) {
            $active = $p === $page ? 'background:#e8b84b;color:#0a192f;' : 'background:rgba(255,255,255,.08);color:#fff;';
            echo '<button onclick="loadTab(\'' . $tab . '\',' . $p . ')" class="btn btn-sm" style="' . $active . '">' . $p . '</button>';
        }
        echo '</div>';
    }

    exit;
}

// ── Zählungen für Tabs (schnell) ──────────────────────────────────────────────
$countImdb = (int)$db->query(
    "SELECT COUNT(*) FROM (SELECT imdb_id FROM movies WHERE imdb_id IS NOT NULL AND imdb_id!=''
     GROUP BY imdb_id HAVING COUNT(*)>1) s"
)->fetchColumn();
$countTmdb = (int)$db->query(
    "SELECT COUNT(*) FROM (SELECT tmdb_id FROM movies WHERE tmdb_id IS NOT NULL
     GROUP BY tmdb_id HAVING COUNT(*)>1) s"
)->fetchColumn();
$countTitle = (int)$db->query(
    "SELECT COUNT(*) FROM (SELECT LOWER(title),year FROM movies WHERE year IS NOT NULL
     GROUP BY LOWER(title),year HAVING COUNT(*)>1) s"
)->fetchColumn();

$pageTitle = 'Duplikate – MKFB';
require_once __DIR__ . '/includes/header.php';
?>

<main class="container py-4">

<div class="d-flex align-items-center gap-3 mb-4">
    <h1 class="fw-bold mb-0" style="color:#e8b84b;"><i class="bi bi-copy me-2"></i>Duplikate in der Filmdatenbank</h1>
    <a href="/admin-filme.php" class="btn btn-sm ms-auto"
       style="background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.15);">
        <i class="bi bi-arrow-left me-1"></i>Film-Verwaltung
    </a>
</div>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success py-2">Film gelöscht.</div>
<?php endif; ?>

<div class="d-flex gap-3 mb-4 flex-wrap">
    <?php foreach ([
        ['Duplikate per IMDB-ID',   $countImdb],
        ['Duplikate per TMDB-ID',   $countTmdb],
        ['Duplikate per Titel+Jahr', $countTitle],
    ] as [$label, $cnt]): ?>
    <div class="p-3 rounded text-center"
         style="background:rgba(232,184,75,.12);border:1px solid rgba(232,184,75,.3);min-width:140px;">
        <div class="fw-bold fs-4" style="color:#e8b84b;"><?= $cnt ?></div>
        <div class="small" style="color:rgba(255,255,255,.75);"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
</div>

<style>
.dup-tab-btn { background:none; border:none; padding:8px 18px; color:rgba(255,255,255,.45);
               cursor:pointer; border-bottom:2px solid transparent; font-size:.9rem; transition:color .15s; }
.dup-tab-btn:hover  { color:rgba(255,255,255,.8); }
.dup-tab-btn.active { color:#e8b84b; border-bottom-color:#e8b84b; }
</style>

<?php if ($countTmdb > 0): ?>
<div class="mb-4 p-3 rounded" style="background:rgba(232,184,75,.08);border:1px solid rgba(232,184,75,.25);">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <div>
            <div class="fw-semibold" style="color:#e8b84b;">
                <i class="bi bi-magic me-1"></i>Auto-Bereinigung (TMDB-Duplikate)
            </div>
            <div class="small text-muted mt-1">
                Findet Einträge mit gleicher TMDB-ID, überträgt die IMDB-ID auf den Originaleintrag
                und löscht das leere Duplikat. Bewertungsdaten bleiben erhalten.
            </div>
        </div>
        <button onclick="autoMerge(this)" class="btn btn-sm ms-auto flex-shrink-0"
                style="background:#e8b84b;color:#0a192f;font-weight:700;white-space:nowrap;">
            <i class="bi bi-magic me-1"></i><?= $countTmdb ?> Duplikate zusammenführen
        </button>
    </div>
    <div id="merge-result" class="mt-2 small" style="display:none;"></div>
</div>
<?php endif; ?>

<div style="border-bottom:1px solid rgba(255,255,255,.1); margin-bottom:1rem;">
    <button class="dup-tab-btn active" onclick="loadTab('imdb',1,this)">
        Nach IMDB-ID (<?= $countImdb ?>)
    </button>
    <button class="dup-tab-btn" onclick="loadTab('tmdb',1,this)">
        Nach TMDB-ID (<?= $countTmdb ?>)
    </button>
    <button class="dup-tab-btn" onclick="loadTab('title',1,this)">
        Nach Titel+Jahr (<?= $countTitle ?>)
    </button>
</div>

<div id="dup-content" style="min-height:200px;">
    <div class="text-center py-5" style="color:rgba(255,255,255,.4);">
        <span class="spinner-border spinner-border-sm me-2"></span>Lade…
    </div>
</div>

<script>
let _currentTab = 'imdb';

function loadTab(tab, page, btn) {
    _currentTab = tab;
    if (btn) {
        document.querySelectorAll('.dup-tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }
    const box = document.getElementById('dup-content');
    box.innerHTML = '<div class="text-center py-5" style="color:rgba(255,255,255,.4);"><span class="spinner-border spinner-border-sm me-2"></span>Lade…</div>';
    fetch('/admin-duplikate.php?ajax_tab=' + tab + '&page=' + (page||1))
        .then(r => r.text())
        .then(html => { box.innerHTML = html || '<div class="py-4 text-center" style="color:rgba(255,255,255,.75);">Keine Daten.</div>'; })
        .catch(err => { box.innerHTML = '<div class="alert alert-danger">Fehler: ' + err + '</div>'; });
}

function dupDelete(id, btn, csrf) {
    if (!confirm('Film #' + id + ' löschen?')) return;
    btn.disabled = true;
    btn.textContent = '…';
    const fd = new FormData();
    fd.append('delete_id', id);
    fd.append('csrf_token', csrf);
    fetch('/admin-duplikate.php', { method:'POST', body:fd,
        headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                const row = btn.closest('tr');
                if (row) row.style.opacity = '.3';
            }
        });
}

function autoMerge(btn) {
    if (!confirm('Alle TMDB-Duplikate automatisch zusammenführen?\nDie IMDB-ID wird auf den Originaleintrag übertragen, das Duplikat gelöscht.')) return;
    btn.disabled = true;
    const res = document.getElementById('merge-result');
    res.style.display = '';
    res.style.color = 'rgba(255,255,255,.75)';
    let totalMerged = 0;
    const csrf = '<?= urlencode(csrfToken()) ?>';

    function runBatch(offset) {
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + totalMerged + ' zusammengeführt…';
        fetch('/admin-duplikate.php?auto_merge=1&csrf=' + csrf + '&offset=' + offset)
            .then(r => r.json())
            .then(d => {
                if (d.errors) {
                    res.style.color = '#f44';
                    res.textContent = 'Fehler' + (d.msg ? ': ' + d.msg : '');
                    btn.innerHTML = 'Fehler';
                    return;
                }
                totalMerged += d.merged;
                res.textContent = totalMerged + ' Duplikate zusammengeführt…';
                if (d.hasMore) {
                    runBatch(d.next);
                } else {
                    btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Fertig';
                    res.style.color = '#4caf50';
                    res.textContent = totalMerged + ' Duplikate zusammengeführt.';
                    loadTab('tmdb', 1);
                }
            })
            .catch(err => {
                res.style.color = '#f44';
                res.textContent = 'Netzwerkfehler: ' + err;
                btn.disabled = false;
                btn.innerHTML = 'Erneut versuchen';
            });
    }
    runBatch(0);
}

// Ersten Tab automatisch laden
loadTab('imdb', 1, document.querySelector('.dup-tab-btn'));
</script>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
