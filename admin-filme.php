<?php
$pageTitle = 'Film-Verwaltung – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if (!isAdmin()) {
    header('Location: /index.php'); exit;
}

$db = getDB();

// Ensure columns + index exist
$db->exec("ALTER TABLE movies ADD COLUMN IF NOT EXISTS wikipedia TEXT NULL DEFAULT NULL");
try {
    $db->exec("ALTER TABLE movies ADD FULLTEXT INDEX ft_af_search (title, original_title, director, genre)");
} catch (\PDOException $e) { /* index already exists */ }

// ── AJAX ──────────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

// ── Search (server-side) ──────────────────────────────────────────────────────
if ($action === 'search_movies') {
    header('Content-Type: application/json');
    $q      = trim($_POST['q']      ?? '');
    $filter = trim($_POST['filter'] ?? 'all');
    $offset = max(0, (int)($_POST['offset'] ?? 0));
    $limit  = 100;

    $where  = [];
    $params = [];

    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        if (mb_strlen($q) >= 3) {
            // FULLTEXT für Ranking, LIKE als Fallback (deckt MySQL-Stopwörter wie "I", "You", "Love" ab)
            $words = array_filter(explode(' ', preg_replace('/[^\w\s]/u', '', $q)));
            $ft    = implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
            if ($ft) {
                $where[]  = "(MATCH(title, original_title, director, genre) AGAINST(? IN BOOLEAN MODE)"
                          . " OR title LIKE ? OR original_title LIKE ?)";
                $params   = array_merge($params, [$ft, $like, $like]);
            } else {
                $where[]  = "(title LIKE ? OR original_title LIKE ?)";
                $params   = array_merge($params, [$like, $like]);
            }
        } else {
            $where[] = "(title LIKE ? OR original_title LIKE ?)";
            $params  = array_merge($params, [$like, $like]);
        }
    }

    if ($filter === 'movie')  { $where[] = "COALESCE(media_type,'movie') = 'movie'"; }
    if ($filter === 'tv')     { $where[] = "media_type = 'tv'"; }
    if ($filter === 'wiki')   { $where[] = "(wikipedia IS NOT NULL AND wikipedia != '')"; }
    if ($filter === 'nowiki') { $where[] = "(wikipedia IS NULL OR wikipedia = '')"; }

    $wc = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $cStmt = $db->prepare("SELECT COUNT(*) FROM movies" . $wc);
    $cStmt->execute($params);
    $total = (int)$cStmt->fetchColumn();

    $orderBy = ($q !== '' && mb_strlen($q) >= 3 && !empty($where) &&
                str_contains($where[0], 'MATCH'))
               ? " ORDER BY MATCH(title,original_title,director,genre) AGAINST(? IN BOOLEAN MODE) DESC, title ASC"
               : " ORDER BY title ASC";
    $orderParams = ($q !== '' && mb_strlen($q) >= 3 && !empty($where) &&
                    str_contains($where[0], 'MATCH'))
                   ? [$params[0]] : [];

    $rStmt = $db->prepare(
        "SELECT id, title, year, genre, COALESCE(media_type,'movie') AS media_type,
                director, country, poster_path, imdb_id,
                CASE WHEN wikipedia IS NOT NULL AND wikipedia != '' THEN 1 ELSE 0 END AS has_wiki
         FROM movies" . $wc . $orderBy . " LIMIT ? OFFSET ?"
    );
    $rStmt->execute(array_merge($params, $orderParams, [$limit, $offset]));
    $results = $rStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['results' => $results, 'total' => $total,
                      'offset' => $offset, 'limit' => $limit]);
    exit;
}

// ── Get single movie for edit modal ──────────────────────────────────────────
if ($action === 'get_movie') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(null); exit; }
    $stmt = $db->prepare("SELECT id,title,original_title,year,genre,director,actors,country,
                                 imdb_id,tmdb_id,media_type,overview,wikipedia,poster_path
                          FROM movies WHERE id=?");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: null); exit;
}

// ── Fetch media_type from TMDB ────────────────────────────────────────────────
if ($action === 'fetch_tmdb_type') {
    header('Content-Type: application/json');
    if (!csrfValid()) { echo json_encode(['ok'=>false,'msg'=>'CSRF-Fehler']); exit; }

    $tmdbId = (int)($_POST['tmdb_id'] ?? 0);
    $imdbId = trim($_POST['imdb_id'] ?? '');

    function afTmdbGet(string $url): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8,
                                CURLOPT_SSL_VERIFYPEER=>false]);
        $raw = curl_exec($ch); curl_close($ch);
        if (!$raw) return null;
        $d = json_decode($raw, true);
        return (is_array($d) && !empty($d['id'])) ? $d : null;
    }

    if ($tmdbId) {
        // Try movie first
        $d = afTmdbGet('https://api.themoviedb.org/3/movie/'.$tmdbId.'?api_key='.TMDB_API_KEY.'&language=de-DE');
        if ($d) { echo json_encode(['ok'=>true,'media_type'=>'movie','title'=>$d['title']??'']); exit; }
        // Try TV
        $d = afTmdbGet('https://api.themoviedb.org/3/tv/'.$tmdbId.'?api_key='.TMDB_API_KEY.'&language=de-DE');
        if ($d) { echo json_encode(['ok'=>true,'media_type'=>'tv','title'=>$d['name']??$d['original_name']??'']); exit; }
    }

    if ($imdbId) {
        $d = afTmdbGet('https://api.themoviedb.org/3/find/'.urlencode($imdbId).'?api_key='.TMDB_API_KEY.'&external_source=imdb_id');
        if ($d) {
            if (!empty($d['movie_results'])) { echo json_encode(['ok'=>true,'media_type'=>'movie','title'=>$d['movie_results'][0]['title']??'']); exit; }
            if (!empty($d['tv_results']))    { echo json_encode(['ok'=>true,'media_type'=>'tv',   'title'=>$d['tv_results'][0]['name']??'']); exit; }
        }
    }

    echo json_encode(['ok'=>false,'msg'=>'Nicht auf TMDB gefunden — bitte Typ manuell setzen']); exit;
}

// ── Save movie ────────────────────────────────────────────────────────────────
if ($action === 'save_movie') {
    header('Content-Type: application/json');
    if (!csrfValid()) { echo json_encode(['ok'=>false,'msg'=>'CSRF-Fehler']); exit; }
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Ungültige ID']); exit; }
    $editFields = ['title','original_title','year','genre','director','actors','country',
                   'imdb_id','tmdb_id','media_type','overview','wikipedia','poster_path'];
    $set = []; $vals = [];
    foreach ($editFields as $f) {
        $val    = isset($_POST[$f]) ? trim($_POST[$f]) : null;
        $set[]  = "`$f` = ?";
        $vals[] = ($val === '') ? null : $val;
    }
    $vals[] = $id;
    $db->prepare("UPDATE movies SET " . implode(', ', $set) . " WHERE id = ?")
       ->execute($vals);
    $stmt = $db->prepare("SELECT title, year, COALESCE(media_type,'movie') AS media_type FROM movies WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,
                      'title'      => $row['title']      ?? '',
                      'year'       => $row['year']        ?? '',
                      'media_type' => $row['media_type']  ?? 'movie']); exit;
}

// ── Delete movie ─────────────────────────────────────────────────────────────
if ($action === 'delete_movie') {
    header('Content-Type: application/json');
    if (!csrfValid()) { echo json_encode(['ok'=>false,'msg'=>'CSRF-Fehler']); exit; }
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Ungültige ID']); exit; }
    // Delete related data first (ignore if table/column doesn't exist)
    $deletes = [
        ["DELETE FROM user_position_ranking WHERE movie_id = ?",              [$id]],
        ["DELETE FROM user_ratings WHERE movie_id = ?",                        [$id]],
        ["DELETE FROM comparisons WHERE winner_id = ? OR loser_id = ?",        [$id, $id]],
        ["DELETE FROM episode_watched WHERE movie_id = ?",                     [$id]],
        ["DELETE FROM action_list_films WHERE movie_id = ?",                   [$id]],
        ["DELETE FROM action_list_duels WHERE movie_a_id = ? OR movie_b_id = ?", [$id, $id]],
        ["DELETE FROM action_list_rankings WHERE movie_id = ?",                [$id]],
        ["DELETE FROM tournament_films WHERE movie_id = ?",                    [$id]],
        ["DELETE FROM tournament_matches WHERE movie_a_id = ? OR movie_b_id = ?", [$id, $id]],
        ["DELETE FROM tournament_results WHERE movie_id = ?",                  [$id]],
        ["DELETE FROM filmperlen WHERE movie_id = ?",                          [$id]],
    ];
    foreach ($deletes as [$sql, $params]) {
        try { $db->prepare($sql)->execute($params); } catch (\PDOException $e) { /* table may not exist */ }
    }
    $db->prepare("DELETE FROM movies WHERE id = ?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Stats (page load only – no full table fetch) ──────────────────────────────
$editOnLoad = (int)($_GET['edit_id'] ?? 0); // auto-open modal for this ID
$total   = (int)$db->query("SELECT COUNT(*) FROM movies")->fetchColumn();
$hasWiki = (int)$db->query("SELECT COUNT(*) FROM movies WHERE wikipedia IS NOT NULL AND wikipedia != ''")->fetchColumn();
$csrf    = csrfToken();

require_once __DIR__ . '/includes/header.php';
?>

<style>
body { background:#0d1f3c !important; }

.af-hero {
    background:linear-gradient(135deg,#0a192f 0%,#1a3a5c 100%);
    border-bottom:1px solid rgba(232,184,75,.15);
    padding:2rem 0 1.6rem;
}
.af-hero h1 { font-size:clamp(1.4rem,3vw,1.9rem); font-weight:900; color:#fff; margin:0 0 .2rem; }

.af-stat { background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); border-radius:20px; padding:3px 12px; font-size:.75rem; color:rgba(255,255,255,.5); }
.af-stat strong { color:#fff; }

.af-search-wrap { position:relative; max-width:480px; }
.af-search-input {
    background:rgba(255,255,255,.07); border:1.5px solid rgba(232,184,75,.25);
    border-radius:10px; color:#fff; padding:.5rem .9rem .5rem 2.4rem;
    font-size:.88rem; width:100%; outline:none; transition:border-color .2s;
}
.af-search-input::placeholder { color:rgba(255,255,255,.3); }
.af-search-input:focus { border-color:#e8b84b; }
.af-search-icon { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:rgba(232,184,75,.55); pointer-events:none; font-size:.85rem; }
.af-search-spinner { position:absolute; right:.75rem; top:50%; transform:translateY(-50%); display:none; }

.af-filter { background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); border-radius:20px; padding:3px 11px; font-size:.72rem; color:rgba(255,255,255,.45); cursor:pointer; transition:all .15s; }
.af-filter.active { background:rgba(232,184,75,.15); border-color:rgba(232,184,75,.3); color:#e8b84b; }

.af-table-wrap { background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.07); border-radius:12px; overflow:hidden; }
.af-table { width:100%; border-collapse:collapse; }
.af-table th {
    background:rgba(232,184,75,.08); color:rgba(232,184,75,.7);
    font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em;
    padding:.55rem 1rem; text-align:left; white-space:nowrap;
    border-bottom:1px solid rgba(232,184,75,.12);
}
.af-table td { padding:.5rem 1rem; border-bottom:1px solid rgba(255,255,255,.04); font-size:.84rem; color:#fff; vertical-align:middle; }
.af-table tr:last-child td { border-bottom:none; }
.af-table tr:hover td { background:rgba(255,255,255,.025); }
.af-table .poster-mini { width:28px; height:42px; object-fit:cover; border-radius:3px; background:#1e3a5f; }
.type-badge { font-size:.58rem; font-weight:700; border-radius:4px; padding:1px 5px; background:rgba(167,139,250,.15); color:#a78bfa; border:1px solid rgba(167,139,250,.25); }
.type-badge.movie { background:rgba(232,184,75,.12); color:#e8b84b; border-color:rgba(232,184,75,.25); }
.wiki-dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:#22c55e; }
.wiki-dot.empty { background:rgba(255,255,255,.15); }
.btn-edit { background:rgba(232,184,75,.12); border:1px solid rgba(232,184,75,.25); color:#e8b84b; border-radius:7px; padding:3px 10px; font-size:.72rem; font-weight:700; cursor:pointer; transition:all .15s; }
.btn-edit:hover { background:rgba(232,184,75,.22); }
.btn-delete { background:rgba(248,113,113,.1); border:1px solid rgba(248,113,113,.25); color:#f87171; border-radius:7px; padding:3px 8px; font-size:.72rem; font-weight:700; cursor:pointer; transition:all .15s; margin-left:3px; }
.btn-delete:hover { background:rgba(248,113,113,.22); }
.btn-delete-modal { background:rgba(248,113,113,.12); border:1px solid rgba(248,113,113,.3); color:#f87171; border-radius:8px; padding:.45rem 1rem; font-size:.85rem; cursor:pointer; transition:all .15s; margin-right:auto; }
.btn-delete-modal:hover { background:rgba(248,113,113,.25); }

.af-load-more {
    display:none; width:100%; padding:.75rem; margin-top:0;
    background:rgba(255,255,255,.04); border:none; border-top:1px solid rgba(255,255,255,.06);
    color:rgba(255,255,255,.4); font-size:.8rem; cursor:pointer; transition:all .15s;
    border-radius:0 0 12px 12px;
}
.af-load-more:hover { background:rgba(232,184,75,.06); color:#e8b84b; }

.af-empty { text-align:center; padding:3rem 1rem; color:rgba(255,255,255,.25); }
.af-empty i { font-size:2rem; display:block; margin-bottom:.6rem; }

#af-toast {
    position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999;
    background:#112240; border:1px solid rgba(232,184,75,.3); color:#fff;
    border-radius:10px; padding:.5rem 1rem; font-size:.82rem; font-weight:600;
    opacity:0; transform:translateY(6px); transition:opacity .2s,transform .2s; pointer-events:none;
}
#af-toast.show { opacity:1; transform:translateY(0); }

/* Modal */
.af-modal-backdrop {
    position:fixed; inset:0; background:rgba(0,0,0,.65); z-index:1055;
    display:none; align-items:flex-start; justify-content:center; padding:2rem 1rem; overflow-y:auto;
}
.af-modal-backdrop.open { display:flex; }
.af-modal {
    background:#0f2744; border:1px solid rgba(232,184,75,.2); border-radius:14px;
    width:100%; max-width:820px; box-shadow:0 24px 64px rgba(0,0,0,.6);
    animation:af-pop .18s ease;
}
@keyframes af-pop { from { transform:translateY(-12px) scale(.98); opacity:0; } to { transform:none; opacity:1; } }
.af-modal-header { display:flex; align-items:center; justify-content:space-between; padding:1.1rem 1.4rem; border-bottom:1px solid rgba(255,255,255,.07); }
.af-modal-title { font-size:1rem; font-weight:800; color:#fff; }
.af-modal-close { background:none; border:none; color:rgba(255,255,255,.4); font-size:1.1rem; cursor:pointer; padding:0; line-height:1; transition:color .15s; }
.af-modal-close:hover { color:#fff; }
.af-modal-body { padding:1.2rem 1.4rem; }
.af-modal-footer { padding:.85rem 1.4rem; border-top:1px solid rgba(255,255,255,.07); display:flex; justify-content:flex-end; gap:.6rem; }

.af-label { display:block; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:rgba(232,184,75,.65); margin-bottom:.25rem; }
.af-input { width:100%; background:rgba(255,255,255,.06); border:1.5px solid rgba(255,255,255,.1); border-radius:8px; color:#fff; padding:.4rem .7rem; font-size:.84rem; outline:none; transition:border-color .2s; font-family:inherit; }
.af-input:focus { border-color:rgba(232,184,75,.5); background:rgba(255,255,255,.08); }
.af-textarea { resize:vertical; min-height:80px; }
.af-wiki-section { background:rgba(34,197,94,.06); border:1.5px solid rgba(34,197,94,.2); border-radius:10px; padding:1rem; margin-top:.5rem; }
.af-wiki-section .af-label { color:rgba(34,197,94,.8); }
.af-wiki-section .af-input { border-color:rgba(34,197,94,.2); min-height:120px; }
.af-wiki-section .af-input:focus { border-color:rgba(34,197,94,.5); }
.af-select { width:100%; background:rgba(255,255,255,.06); border:1.5px solid rgba(255,255,255,.1); border-radius:8px; color:#fff; padding:.4rem .7rem; font-size:.84rem; outline:none; transition:border-color .2s; }
.af-select option { background:#112240; }
.af-select:focus { border-color:rgba(232,184,75,.5); }
.btn-save { background:rgba(232,184,75,.85); border:none; color:#0a192f; font-weight:800; border-radius:8px; padding:.45rem 1.3rem; font-size:.85rem; cursor:pointer; transition:background .15s; }
.btn-save:hover { background:#e8b84b; }
.btn-cancel { background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.12); color:rgba(255,255,255,.6); border-radius:8px; padding:.45rem 1rem; font-size:.85rem; cursor:pointer; transition:all .15s; }
.btn-cancel:hover { background:rgba(255,255,255,.12); color:#fff; }
.af-spinner { display:inline-block; width:14px; height:14px; border:2px solid rgba(255,255,255,.3); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; vertical-align:middle; }
@keyframes spin { to { transform:rotate(360deg); } }
.af-poster-preview { width:60px; height:90px; object-fit:cover; border-radius:6px; background:#1e3a5f; flex-shrink:0; }
</style>

<div id="af-toast"></div>

<!-- Edit Modal -->
<div class="af-modal-backdrop" id="editModal" onclick="if(event.target===this)closeModal()">
<div class="af-modal">
    <div class="af-modal-header">
        <div class="af-modal-title"><i class="bi bi-pencil-square me-2" style="color:#e8b84b;"></i><span id="modal-title-text">Film bearbeiten</span></div>
        <button class="af-modal-close" onclick="closeModal()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="af-modal-body" id="modal-body">
        <div style="text-align:center;padding:2rem;color:rgba(255,255,255,.3);"><span class="af-spinner"></span> Lade…</div>
    </div>
    <div class="af-modal-footer">
        <button class="btn-delete-modal" id="modal-delete-btn" onclick="deleteMovieFromModal()">
            <i class="bi bi-trash me-1"></i>Löschen
        </button>
        <button class="btn-cancel" onclick="closeModal()">Abbrechen</button>
        <button class="btn-save" id="modal-save-btn" onclick="saveMovie()">
            <i class="bi bi-check2 me-1"></i>Speichern
        </button>
    </div>
</div>
</div>

<main>
<div class="af-hero">
<div class="container">
    <h1><i class="bi bi-film me-2" style="color:#e8b84b;"></i>Film-Verwaltung</h1>
    <p style="color:rgba(255,255,255,.4);font-size:.83rem;margin:.2rem 0 1rem;">Alle Felder editierbar · Wikipedia-Beschreibungen pflegen</p>
    <div class="d-flex gap-2 flex-wrap">
        <span class="af-stat"><strong><?= $total ?></strong> Filme gesamt</span>
        <span class="af-stat"><strong style="color:#22c55e;"><?= $hasWiki ?></strong> mit Wikipedia</span>
        <span class="af-stat"><strong style="color:rgba(255,100,100,.8);"><?= $total - $hasWiki ?></strong> ohne Wikipedia</span>
    </div>
</div>
</div>

<div class="container mt-4" style="padding-bottom:4rem;">

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div class="af-search-wrap">
            <i class="bi bi-search af-search-icon"></i>
            <input type="search" id="searchBox" class="af-search-input"
                   placeholder="Titel, Regisseur, Genre suchen…" autocomplete="off">
            <span class="spinner-border spinner-border-sm text-warning af-search-spinner" id="searchSpinner"></span>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <button class="af-filter active" data-filter="all">Alle</button>
            <button class="af-filter" data-filter="movie"><i class="bi bi-camera-film me-1"></i>Filme</button>
            <button class="af-filter" data-filter="tv"><i class="bi bi-tv me-1"></i>Serien</button>
            <button class="af-filter" data-filter="wiki"><i class="bi bi-wikipedia me-1"></i>Mit Wikipedia</button>
            <button class="af-filter" data-filter="nowiki"><i class="bi bi-circle me-1"></i>Ohne Wikipedia</button>
        </div>
    </div>

    <p style="color:rgba(255,255,255,.25);font-size:.75rem;margin-bottom:.5rem;" id="counterLine">
        Lade…
    </p>

    <div class="af-table-wrap">
        <div style="overflow-x:auto;">
        <table class="af-table">
            <thead>
                <tr>
                    <th style="width:36px;"></th>
                    <th>Titel</th>
                    <th style="width:46px;">Jahr</th>
                    <th style="width:64px;">Typ</th>
                    <th style="width:130px;">Genre</th>
                    <th style="width:130px;">Regisseur</th>
                    <th style="width:80px;">Land</th>
                    <th style="width:36px;" title="Wikipedia">Wiki</th>
                    <th style="width:70px;"></th>
                </tr>
            </thead>
            <tbody id="filmTableBody"></tbody>
        </table>
        </div>
        <div class="af-empty" id="emptyState" style="display:none;">
            <i class="bi bi-search"></i>Keine Filme gefunden.
        </div>
        <button class="af-load-more" id="loadMoreBtn" onclick="loadMore()">
            Weitere laden…
        </button>
    </div>

</div>
</main>

<script>
const CSRF  = <?= json_encode($csrf) ?>;
const LIMIT = 100;
let _currentId = null;
let _state = { q:'', filter:'all', offset:0, total:0, loading:false };
let _searchTimer;

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function toast(msg, ok=true){
    const el=document.getElementById('af-toast');
    el.textContent=msg; el.style.borderColor=ok?'rgba(34,197,94,.4)':'rgba(248,113,113,.4)';
    el.classList.add('show'); clearTimeout(el._t); el._t=setTimeout(()=>el.classList.remove('show'),2400);
}

function post(data){
    return fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)}).then(r=>r.json());
}

// ── Server-side search ────────────────────────────────────────────────────────
const searchBox     = document.getElementById('searchBox');
const searchSpinner = document.getElementById('searchSpinner');
const tableBody     = document.getElementById('filmTableBody');
const emptyState    = document.getElementById('emptyState');
const counterLine   = document.getElementById('counterLine');
const loadMoreBtn   = document.getElementById('loadMoreBtn');

searchBox.addEventListener('input', () => {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => doSearch(true), 280);
});
searchBox.addEventListener('keydown', e => { if(e.key==='Escape'){ searchBox.value=''; doSearch(true); } });

document.querySelectorAll('.af-filter').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.af-filter').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        _state.filter = btn.dataset.filter;
        doSearch(true);
    });
});

async function doSearch(reset=true) {
    if (_state.loading) return;
    if (reset) { _state.q = searchBox.value.trim(); _state.offset = 0; }
    _state.loading = true;
    searchSpinner.style.display = 'block';

    if (reset) {
        tableBody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:1.5rem;color:rgba(255,255,255,.3);"><span class="af-spinner"></span></td></tr>';
        emptyState.style.display = 'none';
        loadMoreBtn.style.display = 'none';
    }

    let d;
    try {
        d = await post({ action:'search_movies', q:_state.q, filter:_state.filter, offset:_state.offset });
    } catch(e) {
        searchSpinner.style.display = 'none';
        _state.loading = false;
        return;
    }

    searchSpinner.style.display = 'none';
    _state.loading  = false;
    _state.total    = d.total;
    _state.offset  += d.results.length;

    if (reset) tableBody.innerHTML = '';

    if (d.results.length === 0 && _state.offset === 0) {
        emptyState.style.display = 'block';
    } else {
        emptyState.style.display = 'none';
        tableBody.insertAdjacentHTML('beforeend', d.results.map(buildRow).join(''));
    }

    updateCounter();
    updateLoadMore();
}

async function loadMore() {
    if (_state.loading || _state.offset >= _state.total) return;
    loadMoreBtn.textContent = 'Lade…';
    await doSearch(false);
}

function posterSrc(imdbId, posterPath, size) {
    const ph = 'https://placehold.co/28x42/1e3a5f/e8b84b?text=?';
    if (imdbId) {
        const safe = imdbId.replace(/[^a-zA-Z0-9_-]/g,'');
        if (safe) return `/cover/${safe}.jpg`;
    }
    if (posterPath) return `https://image.tmdb.org/t/p/${size}${posterPath}`;
    return ph;
}
function posterFallback(img, posterPath, size) {
    const ph = 'https://placehold.co/28x42/1e3a5f/e8b84b?text=?';
    if (posterPath && img.src.includes('/cover/')) {
        img.onerror = () => { img.onerror=null; img.src=ph; };
        img.src = `https://image.tmdb.org/t/p/${size}${posterPath}`;
    } else {
        img.onerror = null;
        img.src = ph;
    }
}

function buildRow(m) {
    const poster = posterSrc(m.imdb_id, m.poster_path, 'w92');
    const isTV = m.media_type === 'tv';
    const genre    = (m.genre    || '').substring(0, 22);
    const director = (m.director || '').substring(0, 20);
    const country  = (m.country  || '').substring(0, 12);
    return `<tr data-id="${m.id}">
        <td><img src="${esc(poster)}" class="poster-mini" loading="lazy"
                 onerror="posterFallback(this,'${esc(m.poster_path||'')}','w92')"></td>
        <td><span class="row-title" style="font-weight:600;">${esc(m.title)}</span></td>
        <td style="color:rgba(255,255,255,.45);">${m.year||'–'}</td>
        <td><span class="type-badge ${isTV?'tv':'movie'}">${isTV?'Serie':'Film'}</span></td>
        <td style="color:rgba(255,255,255,.45);font-size:.78rem;">${esc(genre)}</td>
        <td style="color:rgba(255,255,255,.45);font-size:.78rem;">${esc(director)}</td>
        <td style="color:rgba(255,255,255,.35);font-size:.76rem;">${esc(country)}</td>
        <td><span class="wiki-dot${m.has_wiki?'':' empty'}" id="wiki-dot-${m.id}"
                  title="${m.has_wiki?'Wikipedia vorhanden':'Kein Wikipedia-Text'}"></span></td>
        <td style="white-space:nowrap;">
            <button class="btn-edit" onclick="openEdit(${m.id})"><i class="bi bi-pencil me-1"></i>Edit</button>
            <button class="btn-delete" data-id="${m.id}" data-title="${esc(m.title)}" onclick="deleteMovie(this.dataset.id, this.dataset.title)" title="Löschen"><i class="bi bi-trash"></i></button>
        </td>
    </tr>`;
}

function updateCounter(){
    const shown = tableBody.querySelectorAll('tr[data-id]').length;
    const total = _state.total;
    const q = _state.q;
    counterLine.textContent = q
        ? `${shown} von ${total} Treffern für „${q}"`
        : `${shown} von ${total} Einträgen`;
}

function updateLoadMore(){
    const remaining = _state.total - _state.offset;
    if (remaining > 0) {
        loadMoreBtn.style.display = 'block';
        loadMoreBtn.textContent = `Weitere ${Math.min(LIMIT, remaining)} laden (${remaining} verbleibend)`;
    } else {
        loadMoreBtn.style.display = 'none';
    }
}

// Initial load
doSearch(true);

<?php if ($editOnLoad): ?>
// Auto-open edit modal from URL parameter
document.addEventListener('DOMContentLoaded', () => openEdit(<?= $editOnLoad ?>));
<?php endif; ?>

// ── Edit Modal ────────────────────────────────────────────────────────────────
async function openEdit(id){
    _currentId = id;
    document.getElementById('editModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('modal-body').innerHTML = '<div style="text-align:center;padding:2rem;color:rgba(255,255,255,.35);"><span class="af-spinner"></span> Lade Filmdaten…</div>';
    document.getElementById('modal-title-text').textContent = 'Film bearbeiten';
    document.getElementById('modal-save-btn').disabled = false;

    const d = await post({action:'get_movie', id});
    if(!d){ document.getElementById('modal-body').innerHTML='<div style="padding:1rem;color:#f87171;">Film nicht gefunden.</div>'; return; }

    document.getElementById('modal-title-text').textContent = d.title || 'Film bearbeiten';
    const modalPoster = posterSrc(d.imdb_id, d.poster_path, 'w185');

    document.getElementById('modal-body').innerHTML = `
    <div class="d-flex gap-3 mb-3">
        <img src="${esc(modalPoster)}" class="af-poster-preview" id="posterPreview"
             onerror="posterFallback(this,'${esc(d.poster_path||'')}','w185')">
        <div style="flex:1;min-width:0;">
            <div class="row g-2">
                <div class="col-12 col-md-6">
                    <label class="af-label">Titel *</label>
                    <input class="af-input" id="f_title" value="${esc(d.title||'')}">
                </div>
                <div class="col-12 col-md-6">
                    <label class="af-label">Originaltitel</label>
                    <input class="af-input" id="f_original_title" value="${esc(d.original_title||'')}">
                </div>
                <div class="col-6 col-md-3">
                    <label class="af-label">Jahr</label>
                    <input class="af-input" id="f_year" type="number" min="1888" max="2099" value="${esc(d.year||'')}">
                </div>
                <div class="col-6 col-md-3">
                    <label class="af-label">Typ</label>
                    <div class="d-flex gap-1">
                        <select class="af-select" id="f_media_type" style="flex:1;">
                            <option value="movie" ${d.media_type==='movie'||!d.media_type?'selected':''}>Film</option>
                            <option value="tv"    ${d.media_type==='tv'?'selected':''}>Serie</option>
                        </select>
                        <button type="button" id="tmdbTypeBtn" onclick="fetchTmdbType()"
                                title="Typ von TMDB ermitteln"
                                style="background:rgba(99,179,237,.15);border:1px solid rgba(99,179,237,.3);color:#63b3ed;border-radius:8px;padding:0 8px;cursor:pointer;font-size:.8rem;white-space:nowrap;transition:all .15s;"
                                onmouseover="this.style.background='rgba(99,179,237,.25)'" onmouseout="this.style.background='rgba(99,179,237,.15)'">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="af-label">Genre</label>
                    <input class="af-input" id="f_genre" value="${esc(d.genre||'')}">
                </div>
            </div>
        </div>
    </div>
    <div class="row g-2 mb-2">
        <div class="col-12 col-md-6">
            <label class="af-label">Regisseur / Showrunner</label>
            <input class="af-input" id="f_director" value="${esc(d.director||'')}">
        </div>
        <div class="col-12 col-md-6">
            <label class="af-label">Land</label>
            <input class="af-input" id="f_country" value="${esc(d.country||'')}">
        </div>
        <div class="col-12 col-md-4">
            <label class="af-label">TMDB-ID</label>
            <input class="af-input" id="f_tmdb_id" type="number" value="${esc(d.tmdb_id||'')}">
        </div>
        <div class="col-12 col-md-4">
            <label class="af-label">IMDB-ID</label>
            <input class="af-input" id="f_imdb_id" value="${esc(d.imdb_id||'')}">
        </div>
        <div class="col-12 col-md-4">
            <label class="af-label">Poster-Pfad (TMDB)</label>
            <input class="af-input" id="f_poster_path" value="${esc(d.poster_path||'')}" oninput="updatePosterPreview(this.value)">
        </div>
        <div class="col-12">
            <label class="af-label">Besetzung (kommagetrennt)</label>
            <textarea class="af-input af-textarea" id="f_actors" style="min-height:60px;">${esc(d.actors||'')}</textarea>
        </div>
        <div class="col-12">
            <label class="af-label">Kurzbeschreibung (TMDB Overview)</label>
            <textarea class="af-input af-textarea" id="f_overview">${esc(d.overview||'')}</textarea>
        </div>
    </div>
    <div class="af-wiki-section">
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <label class="af-label mb-0"><i class="bi bi-wikipedia me-1"></i>Wikipedia – Filmbeschreibung</label>
            <button type="button" id="wikiSearchBtn"
                    onclick="fetchWikipedia('de')"
                    style="background:rgba(232,184,75,.12);border:1px solid rgba(232,184,75,.3);color:#e8b84b;border-radius:6px;padding:3px 10px;font-size:.75rem;font-weight:600;cursor:pointer;white-space:nowrap;">
                <i class="bi bi-search me-1"></i>DE suchen
            </button>
            <button type="button" id="wikiSearchBtnEn"
                    onclick="fetchWikipedia('en')"
                    style="background:rgba(91,155,213,.1);border:1px solid rgba(91,155,213,.3);color:#5b9bd5;border-radius:6px;padding:3px 10px;font-size:.75rem;font-weight:600;cursor:pointer;white-space:nowrap;">
                <i class="bi bi-search me-1"></i>EN suchen
            </button>
            <span id="wikiSearchStatus" style="font-size:.72rem;color:rgba(255,255,255,.35);"></span>
        </div>
        <p style="font-size:.72rem;color:rgba(34,197,94,.55);margin:.2rem 0 .5rem 0;">Eigener Beschreibungstext (z. B. aus Wikipedia). Wird in der Filmdetailseite angezeigt.</p>
        <textarea class="af-input af-textarea" id="f_wikipedia" style="min-height:140px;">${esc(d.wikipedia||'')}</textarea>
        <div id="wikiSourceLine" style="display:none;font-size:.7rem;color:rgba(255,255,255,.3);margin-top:.3rem;"></div>
    </div>`;
}

async function fetchWikipedia(lang) {
    const title  = document.getElementById('f_title')?.value.trim() || '';
    const year   = document.getElementById('f_year')?.value.trim()  || '';
    if (!title) { alert('Bitte zuerst einen Titel eingeben.'); return; }

    const btn    = document.getElementById(lang === 'en' ? 'wikiSearchBtnEn' : 'wikiSearchBtn');
    const status = document.getElementById('wikiSearchStatus');
    const srcLine = document.getElementById('wikiSourceLine');

    btn.disabled = true;
    status.textContent = 'Suche läuft…';

    try {
        const params = new URLSearchParams({ title, year, lang });
        const res    = await fetch('/api-wikipedia.php?' + params.toString());
        const data   = await res.json();

        if (data.ok) {
            const ta = document.getElementById('f_wikipedia');
            // Nur überschreiben wenn leer ODER User bestätigt
            if (!ta.value.trim() || confirm('Wikipedia-Text gefunden. Aktuellen Text ersetzen?')) {
                ta.value = data.text;
                status.textContent = '✓ Gefunden: ' + data.source_title;
                status.style.color = '#22c55e';
                if (data.note) status.textContent += ' (' + data.note + ')';
                srcLine.style.display = 'block';
                srcLine.innerHTML = 'Quelle: <a href="' + data.url + '" target="_blank" style="color:rgba(91,155,213,.7);">' + data.url + '</a>';
            } else {
                status.textContent = 'Abgebrochen.';
                status.style.color = 'rgba(255,255,255,.35)';
            }
        } else {
            status.textContent = '✗ ' + (data.error || 'Nicht gefunden');
            status.style.color = '#ef4444';
        }
    } catch (e) {
        status.textContent = '✗ Netzwerkfehler';
        status.style.color = '#ef4444';
    }
    btn.disabled = false;
}

function closeModal(){
    document.getElementById('editModal').classList.remove('open');
    document.body.style.overflow = '';
    _currentId = null;
}

function updatePosterPreview(val){
    const img = document.getElementById('posterPreview');
    if(img) img.src = val ? `https://image.tmdb.org/t/p/w185${val}` : 'https://placehold.co/60x90/1e3a5f/e8b84b?text=?';
}

async function saveMovie(){
    if(!_currentId) return;
    const btn = document.getElementById('modal-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="af-spinner me-1"></span>Speichern…';

    const val = id => (document.getElementById(id)?.value ?? '').trim();
    const d = await post({
        action:'save_movie', csrf_token:CSRF, id:_currentId,
        title:val('f_title'), original_title:val('f_original_title'),
        year:val('f_year'), genre:val('f_genre'), director:val('f_director'),
        actors:val('f_actors'), country:val('f_country'), imdb_id:val('f_imdb_id'),
        tmdb_id:val('f_tmdb_id'), media_type:val('f_media_type'),
        overview:val('f_overview'), wikipedia:val('f_wikipedia'), poster_path:val('f_poster_path'),
    });

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Speichern';
    if(!d?.ok){ toast(d?.msg||'Fehler beim Speichern', false); return; }

    // Update row in table if visible
    const row = document.querySelector(`tr[data-id="${_currentId}"]`);
    if(row){
        row.querySelector('.row-title').textContent = d.title;
        const badge = row.querySelector('.type-badge');
        if(badge){ badge.className='type-badge '+(d.media_type==='tv'?'tv':'movie'); badge.textContent=d.media_type==='tv'?'Serie':'Film'; }
        const dot = document.getElementById(`wiki-dot-${_currentId}`);
        if(dot){ const hw=val('f_wikipedia').length>0; dot.className='wiki-dot'+(hw?'':' empty'); dot.title=hw?'Wikipedia vorhanden':'Kein Wikipedia-Text'; }
    }

    toast('Film gespeichert!');
    closeModal();
}

async function fetchTmdbType(){
    const btn    = document.getElementById('tmdbTypeBtn');
    const tmdbId = (document.getElementById('f_tmdb_id')?.value || '').trim();
    const imdbId = (document.getElementById('f_imdb_id')?.value || '').trim();
    if (!tmdbId && !imdbId) { toast('Keine TMDB-ID oder IMDB-ID vorhanden', false); return; }
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="af-spinner"></span>';
    btn.disabled  = true;
    const d = await post({ action:'fetch_tmdb_type', csrf_token:CSRF, tmdb_id:tmdbId, imdb_id:imdbId });
    btn.innerHTML = orig; btn.disabled = false;
    if (!d?.ok) { toast(d?.msg || 'TMDB-Fehler', false); return; }
    const sel = document.getElementById('f_media_type');
    if (sel) sel.value = d.media_type;
    toast(d.media_type === 'tv' ? '✓ Als Serie erkannt' : '✓ Als Film erkannt');
}

document.addEventListener('keydown', e => { if(e.key==='Escape') closeModal(); });

// ── Delete ────────────────────────────────────────────────────────────────────
async function deleteMovie(id, title) {
    if (!confirm(`„${title}" wirklich löschen?\n\nDieser Eintrag wird dauerhaft aus der Datenbank entfernt.`)) return;
    const d = await post({ action:'delete_movie', csrf_token:CSRF, id });
    if (!d?.ok) { toast(d?.msg || 'Fehler beim Löschen', false); return; }
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (row) row.remove();
    _state.total--;
    updateCounter();
    updateLoadMore();
    toast('Film gelöscht.');
}

async function deleteMovieFromModal() {
    if (!_currentId) return;
    const titleEl = document.getElementById('f_title');
    const title = titleEl ? titleEl.value : 'dieser Film';
    closeModal();
    await deleteMovie(_currentId, title);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
