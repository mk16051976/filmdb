<?php
$pageTitle = 'Turnier-Pool verwalten – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$db = getDB();

// Nur Admins
if (!isAdmin()) { http_response_code(403); die('Nur für Admins.'); }

// Tabelle anlegen
$db->exec("CREATE TABLE IF NOT EXISTS tournament_pool (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    movie_id   INT NOT NULL UNIQUE,
    sort_order INT DEFAULT 0,
    INDEX idx_sort (sort_order)
)");

// ── AJAX-Endpunkte ────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action) {
    header('Content-Type: application/json; charset=utf-8');

    // Suche
    if ($action === 'search' && isset($_GET['q'])) {
        $q    = '%' . trim($_GET['q']) . '%';
        $stmt = $db->prepare(
            "SELECT m.id, m.title, m.year, m.poster_path,
                    (SELECT 1 FROM tournament_pool tp WHERE tp.movie_id = m.id LIMIT 1) AS in_pool
             FROM movies m
             WHERE m.title LIKE ? OR m.year LIKE ?
             ORDER BY m.title ASC LIMIT 30"
        );
        $stmt->execute([$q, $q]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // Hinzufügen
    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
        $id = (int)($_POST['movie_id'] ?? 0);
        if ($id > 0) {
            try {
                $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM tournament_pool")->fetchColumn();
                $db->prepare("INSERT IGNORE INTO tournament_pool (movie_id, sort_order) VALUES (?,?)")
                   ->execute([$id, $maxOrder + 1]);
                echo json_encode(['ok' => true,
                    'count' => (int)$db->query("SELECT COUNT(*) FROM tournament_pool")->fetchColumn()]);
            } catch (\PDOException $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
        } else { echo json_encode(['ok' => false]); }
        exit;
    }

    // Entfernen
    if ($action === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
        $id = (int)($_POST['movie_id'] ?? 0);
        $db->prepare("DELETE FROM tournament_pool WHERE movie_id = ?")->execute([$id]);
        echo json_encode(['ok' => true,
            'count' => (int)$db->query("SELECT COUNT(*) FROM tournament_pool")->fetchColumn()]);
        exit;
    }

    // Reihenfolge speichern
    if ($action === 'reorder' && $_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
        $ids  = json_decode($_POST['ids'] ?? '[]', true);
        $stmt = $db->prepare("UPDATE tournament_pool SET sort_order = ? WHERE movie_id = ?");
        foreach ($ids as $i => $id) {
            $stmt->execute([$i, (int)$id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // Pool leeren
    if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
        $db->exec("TRUNCATE TABLE tournament_pool");
        echo json_encode(['ok' => true, 'count' => 0]);
        exit;
    }

    // Bulk-Import: erste N Filme aus movies übernehmen
    if ($action === 'bulk_import' && $_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
        $n    = min((int)($_POST['n'] ?? 4096), 65536);
        $stmt = $db->prepare("SELECT id FROM movies ORDER BY id ASC LIMIT ?");
        $stmt->execute([$n]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $db->beginTransaction();
        $ins = $db->prepare("INSERT IGNORE INTO tournament_pool (movie_id, sort_order) VALUES (?,?)");
        foreach ($ids as $i => $id) {
            $ins->execute([(int)$id, $i]);
        }
        $db->commit();
        $count = (int)$db->query("SELECT COUNT(*) FROM tournament_pool")->fetchColumn();
        echo json_encode(['ok' => true, 'count' => $count, 'imported' => count($ids)]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit;
}

// ── Seitenlade: Pool holen ────────────────────────────────────────────────────
$pool = $db->query(
    "SELECT m.id, m.title, m.year, m.poster_path
     FROM tournament_pool tp
     JOIN movies m ON m.id = tp.movie_id
     ORDER BY tp.sort_order ASC"
)->fetchAll();

$poolCount  = count($pool);
$totalMovies = (int)$db->query("SELECT COUNT(*) FROM movies")->fetchColumn();
$csrf = csrfToken();

require_once __DIR__ . '/includes/header.php';
?>
<main style="background:#14325a; min-height:100vh; padding:2rem 0;">
<div class="container-fluid" style="max-width:1400px;">

  <!-- Header -->
  <div class="d-flex align-items-center gap-3 mb-4">
    <a href="/turnier.php" style="color:rgba(255,255,255,.4); text-decoration:none; font-size:.9rem;">
      <i class="bi bi-arrow-left me-1"></i>Zurück zum Turnier
    </a>
  </div>
  <h1 style="color:#e8b84b; font-size:1.7rem; font-weight:800; margin-bottom:.25rem;">
    <i class="bi bi-collection-fill me-2"></i>Sichtungsturnier-Pool
  </h1>
  <p style="color:rgba(255,255,255,.4); margin-bottom:2rem;">
    Lege fest, welche Filme im Sichtungsturnier verwendet werden.
    <?= number_format($totalMovies) ?> Filme verfügbar in der Datenbank.
  </p>

  <!-- Pool-Counter -->
  <div class="d-flex align-items-center gap-3 mb-3">
    <div id="pool-counter" style="background:rgba(232,184,75,.12); border:1px solid rgba(232,184,75,.3);
         border-radius:8px; padding:.5rem 1.2rem; color:#e8b84b; font-weight:700; font-size:1rem;">
      <i class="bi bi-film me-1"></i>
      <span id="pool-count"><?= $poolCount ?></span> Filme im Pool
    </div>
    <?php foreach ([64,128,256,512,1024] as $t): ?>
    <span style="color:<?= $poolCount >= $t ? '#7ec87e' : 'rgba(255,255,255,.3)' ?>; font-size:.8rem;">
      <?= $t ?><?= $poolCount >= $t ? ' ✓' : '' ?>
    </span>
    <?php endforeach; ?>
    <div class="ms-auto d-flex gap-2">
      <button onclick="bulkImport()" class="btn btn-sm"
        style="background:rgba(232,184,75,.15); border:1px solid rgba(232,184,75,.3); color:#e8b84b;">
        <i class="bi bi-cloud-download me-1"></i>Erste 4096 aus DB importieren
      </button>
      <button onclick="clearPool()" class="btn btn-sm"
        style="background:rgba(224,123,123,.15); border:1px solid rgba(224,123,123,.3); color:#e07b7b;">
        <i class="bi bi-trash me-1"></i>Pool leeren
      </button>
    </div>
  </div>

  <div class="row g-4">

    <!-- ── LINKE SEITE: Suche ──────────────────────────────────────────── -->
    <div class="col-lg-5">
      <div style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.08);
                  border-radius:12px; overflow:hidden;">
        <div style="background:rgba(255,255,255,.05); padding:.75rem 1rem; border-bottom:1px solid rgba(255,255,255,.08);">
          <span style="color:#e8b84b; font-weight:700; font-size:.85rem;">
            <i class="bi bi-search me-1"></i>FILME SUCHEN & HINZUFÜGEN
          </span>
        </div>
        <div style="padding:1rem;">
          <input type="text" id="search-input" class="form-control"
            placeholder="Titel oder Jahr suchen…"
            style="background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12);
                   color:#e0e0e0; border-radius:8px; padding:.6rem 1rem;">
          <div id="search-results" style="margin-top:.75rem; max-height:65vh; overflow-y:auto;
               scrollbar-width:thin; scrollbar-color:rgba(232,184,75,.2) transparent;">
            <p style="color:rgba(255,255,255,.3); font-size:.85rem; text-align:center; margin-top:2rem;">
              <i class="bi bi-search me-1"></i>Suchbegriff eingeben…
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- ── RECHTE SEITE: Pool ──────────────────────────────────────────── -->
    <div class="col-lg-7">
      <div style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.08);
                  border-radius:12px; overflow:hidden;">
        <div style="background:rgba(255,255,255,.05); padding:.75rem 1rem; border-bottom:1px solid rgba(255,255,255,.08);
                    display:flex; align-items:center; gap:.75rem;">
          <span style="color:#e8b84b; font-weight:700; font-size:.85rem;">
            <i class="bi bi-list-ol me-1"></i>TURNIER-POOL
          </span>
          <span style="color:rgba(255,255,255,.35); font-size:.78rem;">
            Ziehen zum Sortieren
          </span>
          <button onclick="saveOrder()" id="save-btn" class="btn btn-sm ms-auto"
            style="background:#e8b84b; color:#14325a; font-weight:700; font-size:.8rem;
                   padding:.3rem .9rem; border-radius:6px; display:none;">
            <i class="bi bi-check2 me-1"></i>Reihenfolge speichern
          </button>
        </div>
        <div id="pool-list" style="max-height:68vh; overflow-y:auto;
             scrollbar-width:thin; scrollbar-color:rgba(232,184,75,.2) transparent;">
          <?php if (empty($pool)): ?>
          <div id="pool-empty" style="padding:3rem; text-align:center; color:rgba(255,255,255,.3);">
            <i class="bi bi-collection" style="font-size:2.5rem; display:block; margin-bottom:.75rem;"></i>
            Noch keine Filme im Pool.<br>Suche links nach Filmen und füge sie hinzu.
          </div>
          <?php else: ?>
          <?php foreach ($pool as $i => $film): ?>
          <?= poolRow($film, $i + 1) ?>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div><!-- /row -->
</div>
</main>

<?php
function poolRow(array $film, int $pos): string {
    $poster = $film['poster_path']
        ? 'https://image.tmdb.org/t/p/w92' . htmlspecialchars($film['poster_path'])
        : 'https://placehold.co/34x51/1e3a5f/e8b84b?text=?';
    $id    = (int)$film['id'];
    $title = htmlspecialchars($film['title']);
    $year  = (int)$film['year'];
    return <<<HTML
<div class="pool-row" data-id="{$id}"
     style="display:flex; align-items:center; gap:.6rem; padding:.4rem .75rem;
            border-bottom:1px solid rgba(255,255,255,.05); cursor:grab;">
  <span style="color:rgba(255,255,255,.3); font-size:.75rem; min-width:2rem; text-align:right;">{$pos}</span>
  <i class="bi bi-grip-vertical" style="color:rgba(255,255,255,.2); flex-shrink:0;"></i>
  <img src="{$poster}" width="34" height="51" style="border-radius:3px; object-fit:cover; flex-shrink:0;"
       onerror="this.src='https://placehold.co/34x51/1e3a5f/e8b84b?text=?'">
  <div style="flex:1; min-width:0;">
    <div style="color:#e0e0e0; font-size:.85rem; font-weight:600; white-space:nowrap;
                overflow:hidden; text-overflow:ellipsis;">{$title}</div>
    <div style="color:rgba(255,255,255,.35); font-size:.75rem;">{$year}</div>
  </div>
  <button onclick="removeFilm({$id}, this)"
    style="background:none; border:none; color:rgba(224,123,123,.6); cursor:pointer; padding:.25rem .4rem;
           border-radius:4px; flex-shrink:0; line-height:1;" title="Aus Pool entfernen">
    <i class="bi bi-x-lg" style="font-size:.85rem;"></i>
  </button>
</div>
HTML;
}
?>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
const CSRF   = <?= json_encode($csrf) ?>;
const BASE   = window.location.pathname;
let orderDirty = false;

// ── SortableJS Pool ──────────────────────────────────────────────────────────
const poolEl = document.getElementById('pool-list');
const sortable = Sortable.create(poolEl, {
    handle: '.bi-grip-vertical',
    animation: 150,
    ghostClass: 'pool-row-ghost',
    onEnd() {
        orderDirty = true;
        document.getElementById('save-btn').style.display = '';
        updatePositionNumbers();
    }
});

function updatePositionNumbers() {
    poolEl.querySelectorAll('.pool-row').forEach((row, i) => {
        row.querySelector('span:first-child').textContent = i + 1;
    });
}

// ── Click-Delegation für Hinzufügen-Buttons ──────────────────────────────────
document.getElementById('search-results').addEventListener('click', function(e) {
    const btn = e.target.closest('.add-film-btn');
    if (!btn || btn.disabled) return;
    addFilm(
        parseInt(btn.dataset.id),
        btn.dataset.title,
        parseInt(btn.dataset.year),
        btn.dataset.poster,
        btn
    );
});

// ── Suche ────────────────────────────────────────────────────────────────────
let searchTimer;
document.getElementById('search-input').addEventListener('input', function() {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (q.length < 1) {
        document.getElementById('search-results').innerHTML =
            '<p style="color:rgba(255,255,255,.3);font-size:.85rem;text-align:center;margin-top:2rem;"><i class="bi bi-search me-1"></i>Suchbegriff eingeben…</p>';
        return;
    }
    searchTimer = setTimeout(() => doSearch(q), 250);
});

function doSearch(q) {
    fetch(BASE + '?action=search&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(films => renderSearchResults(films));
}

function renderSearchResults(films) {
    const el = document.getElementById('search-results');
    if (!films.length) {
        el.innerHTML = '<p style="color:rgba(255,255,255,.3);font-size:.85rem;text-align:center;margin-top:1rem;">Keine Treffer</p>';
        return;
    }
    el.innerHTML = films.map(f => {
        const poster = f.poster_path
            ? 'https://image.tmdb.org/t/p/w92' + f.poster_path
            : 'https://placehold.co/34x51/1e3a5f/e8b84b?text=?';
        const inPool = f.in_pool == 1;
        return `<div style="display:flex;align-items:center;gap:.6rem;padding:.35rem .5rem;
                             border-radius:6px;margin-bottom:2px;
                             background:rgba(255,255,255,${inPool?'.06':'.0'});">
            <img src="${escHtml(poster)}" width="34" height="51"
                 style="border-radius:3px;object-fit:cover;flex-shrink:0;"
                 onerror="this.src='https://placehold.co/34x51/1e3a5f/e8b84b?text=?'">
            <div style="flex:1;min-width:0;">
                <div style="color:#e0e0e0;font-size:.85rem;font-weight:600;white-space:nowrap;
                            overflow:hidden;text-overflow:ellipsis;">${escHtml(f.title)}</div>
                <div style="color:rgba(255,255,255,.35);font-size:.75rem;">${f.year}</div>
            </div>
            ${inPool
                ? '<span style="color:#7ec87e;font-size:.75rem;flex-shrink:0;"><i class="bi bi-check-circle-fill"></i> Im Pool</span>'
                : `<button class="add-film-btn"
                           data-id="${f.id}"
                           data-title="${escHtml(f.title)}"
                           data-year="${f.year}"
                           data-poster="${escHtml(f.poster_path||'')}"
                           style="background:rgba(232,184,75,.15);border:1px solid rgba(232,184,75,.3);
                                  color:#e8b84b;border-radius:6px;padding:.25rem .6rem;cursor:pointer;
                                  font-size:.78rem;white-space:nowrap;flex-shrink:0;">
                     <i class="bi bi-plus-lg me-1"></i>Hinzufügen
                   </button>`
            }
        </div>`;
    }).join('');
}

// ── Film hinzufügen ───────────────────────────────────────────────────────────
function addFilm(id, title, year, posterPath, btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('movie_id', id);
    fd.append('csrf_token', CSRF);
    fetch(BASE, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                btn.closest('div[style]').querySelector('button')?.replaceWith(
                    Object.assign(document.createElement('span'),
                        { style: 'color:#7ec87e;font-size:.75rem;flex-shrink:0;',
                          innerHTML: '<i class="bi bi-check-circle-fill"></i> Im Pool' })
                );
                updateCounter(data.count);
                // Pool-Zeile einfügen
                const empty = document.getElementById('pool-empty');
                if (empty) empty.remove();
                const count = poolEl.querySelectorAll('.pool-row').length + 1;
                poolEl.insertAdjacentHTML('beforeend', buildPoolRow(id, title, year, posterPath, count));
                // Suchfeld leeren und fokussieren
                const inp = document.getElementById('search-input');
                inp.value = '';
                inp.focus();
                document.getElementById('search-results').innerHTML =
                    '<p style="color:rgba(255,255,255,.3);font-size:.85rem;text-align:center;margin-top:2rem;"><i class="bi bi-search me-1"></i>Suchbegriff eingeben…</p>';
            } else { btn.disabled = false; btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Hinzufügen'; }
        });
}

function buildPoolRow(id, title, year, posterPath, pos) {
    const poster = posterPath
        ? 'https://image.tmdb.org/t/p/w92' + posterPath
        : 'https://placehold.co/34x51/1e3a5f/e8b84b?text=?';
    return `<div class="pool-row" data-id="${id}"
                 style="display:flex;align-items:center;gap:.6rem;padding:.4rem .75rem;
                        border-bottom:1px solid rgba(255,255,255,.05);cursor:grab;">
        <span style="color:rgba(255,255,255,.3);font-size:.75rem;min-width:2rem;text-align:right;">${pos}</span>
        <i class="bi bi-grip-vertical" style="color:rgba(255,255,255,.2);flex-shrink:0;"></i>
        <img src="${escHtml(poster)}" width="34" height="51"
             style="border-radius:3px;object-fit:cover;flex-shrink:0;"
             onerror="this.src='https://placehold.co/34x51/1e3a5f/e8b84b?text=?'">
        <div style="flex:1;min-width:0;">
            <div style="color:#e0e0e0;font-size:.85rem;font-weight:600;white-space:nowrap;
                        overflow:hidden;text-overflow:ellipsis;">${escHtml(title)}</div>
            <div style="color:rgba(255,255,255,.35);font-size:.75rem;">${year}</div>
        </div>
        <button onclick="removeFilm(${id}, this)"
            style="background:none;border:none;color:rgba(224,123,123,.6);cursor:pointer;
                   padding:.25rem .4rem;border-radius:4px;flex-shrink:0;line-height:1;">
            <i class="bi bi-x-lg" style="font-size:.85rem;"></i>
        </button>
    </div>`;
}

// ── Film entfernen ────────────────────────────────────────────────────────────
function removeFilm(id, btn) {
    const row = btn.closest('.pool-row');
    const fd = new FormData();
    fd.append('action', 'remove');
    fd.append('movie_id', id);
    fd.append('csrf_token', CSRF);
    fetch(BASE, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                row.remove();
                updateCounter(data.count);
                updatePositionNumbers();
                if (!poolEl.querySelectorAll('.pool-row').length) {
                    poolEl.innerHTML = '<div id="pool-empty" style="padding:3rem;text-align:center;color:rgba(255,255,255,.3);">'
                        + '<i class="bi bi-collection" style="font-size:2.5rem;display:block;margin-bottom:.75rem;"></i>'
                        + 'Noch keine Filme im Pool.</div>';
                }
                // Suche neu rendern (In-Pool-Status aktualisieren)
                const q = document.getElementById('search-input').value.trim();
                if (q) doSearch(q);
            }
        });
}

// ── Bulk-Import ───────────────────────────────────────────────────────────────
function bulkImport() {
    const current = parseInt(document.getElementById('pool-count').textContent) || 0;
    const msg = current > 0
        ? `Pool hat bereits ${current} Filme. Die ersten 4096 Filme aus der DB werden zusätzlich hinzugefügt (Duplikate werden übersprungen). Fortfahren?`
        : 'Die ersten 4096 Filme (nach ID) aus der Datenbank in den Pool importieren?';
    if (!confirm(msg)) return;
    const fd = new FormData();
    fd.append('action', 'bulk_import');
    fd.append('n', '4096');
    fd.append('csrf_token', CSRF);
    fetch(BASE, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                updateCounter(data.count);
                // Seite neu laden um Pool-Liste zu aktualisieren
                window.location.reload();
            }
        });
}

// ── Pool leeren ───────────────────────────────────────────────────────────────
function clearPool() {
    if (!confirm('Alle ' + document.getElementById('pool-count').textContent + ' Filme aus dem Pool entfernen?')) return;
    const fd = new FormData();
    fd.append('action', 'clear');
    fd.append('csrf_token', CSRF);
    fetch(BASE, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                poolEl.innerHTML = '<div id="pool-empty" style="padding:3rem;text-align:center;color:rgba(255,255,255,.3);">'
                    + '<i class="bi bi-collection" style="font-size:2.5rem;display:block;margin-bottom:.75rem;"></i>'
                    + 'Noch keine Filme im Pool.</div>';
                updateCounter(0);
                const q = document.getElementById('search-input').value.trim();
                if (q) doSearch(q);
            }
        });
}

// ── Reihenfolge speichern ─────────────────────────────────────────────────────
function saveOrder() {
    const ids = [...poolEl.querySelectorAll('.pool-row')].map(r => r.dataset.id);
    const fd  = new FormData();
    fd.append('action', 'reorder');
    fd.append('ids', JSON.stringify(ids));
    fd.append('csrf_token', CSRF);
    fetch(BASE, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                orderDirty = false;
                const btn = document.getElementById('save-btn');
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Gespeichert';
                setTimeout(() => {
                    btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Reihenfolge speichern';
                    btn.style.display = 'none';
                }, 2000);
            }
        });
}

// ── Hilfsfunktionen ───────────────────────────────────────────────────────────
function updateCounter(count) {
    document.getElementById('pool-count').textContent = count;
    // Brackets-Indikatoren aktualisieren
    document.querySelectorAll('[data-bracket]').forEach(el => {
        const t = parseInt(el.dataset.bracket);
        el.style.color = count >= t ? '#7ec87e' : 'rgba(255,255,255,.3)';
        el.textContent = t + (count >= t ? ' ✓' : '');
    });
}

function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

window.addEventListener('beforeunload', e => {
    if (orderDirty) { e.preventDefault(); e.returnValue = ''; }
});
</script>

<style>
.pool-row-ghost { opacity:.4; background:rgba(232,184,75,.1) !important; }
.pool-row:hover { background:rgba(255,255,255,.03); }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
