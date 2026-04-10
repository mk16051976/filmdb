<?php
$pageTitle = 'Filmtagebuch – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$db     = getDB();

// ── Table + migrations (only once per session) ────────────────────────────────
if (empty($_SESSION['diary_migrated'])) {
    $db->exec("CREATE TABLE IF NOT EXISTS diary_entries (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        movie_id   INT UNSIGNED NOT NULL,
        watch_date DATE NOT NULL,
        sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_entry (user_id, movie_id, watch_date),
        INDEX idx_user_date (user_id, watch_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $cols = $db->query("SHOW COLUMNS FROM diary_entries")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('rating',  $cols)) $db->exec("ALTER TABLE diary_entries ADD COLUMN rating  TINYINT UNSIGNED DEFAULT NULL AFTER sort_order");
        if (!in_array('liked',   $cols)) $db->exec("ALTER TABLE diary_entries ADD COLUMN liked   TINYINT(1) NOT NULL DEFAULT 0");
        if (!in_array('rewatch', $cols)) $db->exec("ALTER TABLE diary_entries ADD COLUMN rewatch TINYINT(1) NOT NULL DEFAULT 0");
        if (!in_array('review',  $cols)) $db->exec("ALTER TABLE diary_entries ADD COLUMN review  TEXT DEFAULT NULL");
    } catch (\PDOException $e) {}
    $_SESSION['diary_migrated'] = true;
}

// ── Star helper ───────────────────────────────────────────────────────────────
function renderStars(?int $r): string {
    if ($r === null) return '<span class="diary-no-rating">Bewerten</span>';
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $v = $i * 2;
        if ($r >= $v)      $out .= '<i class="bi bi-star-fill"></i>';
        elseif ($r >= $v-1) $out .= '<i class="bi bi-star-half"></i>';
        else               $out .= '<i class="bi bi-star"></i>';
    }
    return $out;
}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['action'] === 'search') {
        $q = trim($_GET['q'] ?? '');
        if (!$q) { echo json_encode([]); exit; }
        $stmt = $db->prepare("SELECT id,title,title_en,original_title,year,poster_path,poster_path_en,imdb_id FROM movies WHERE title LIKE ? OR original_title LIKE ? ORDER BY year DESC LIMIT 20");
        $like = '%'.$q.'%';
        $stmt->execute([$like,$like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['display_title']=movieTitle($r); $r['display_poster']=moviePosterUrl($r,'w92'); }
        echo json_encode($rows); exit;
    }

    if ($_GET['action'] === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $movieId   = (int)($_POST['movie_id'] ?? 0);
        $watchDate = $_POST['watch_date'] ?? '';
        $rating    = ($_POST['rating'] ?? '') !== '' ? max(1,min(10,(int)$_POST['rating'])) : null;
        $liked     = (int)(bool)($_POST['liked']   ?? 0);
        $rewatch   = (int)(bool)($_POST['rewatch'] ?? 0);
        $review    = trim($_POST['review'] ?? '') ?: null;
        if (!$movieId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $watchDate)) {
            echo json_encode(['ok'=>false,'msg'=>'Ungültige Eingabe']); exit;
        }
        $ord = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM diary_entries WHERE user_id=? AND watch_date=?");
        $ord->execute([$userId,$watchDate]);
        $so = (int)$ord->fetchColumn();
        try {
            $ins = $db->prepare("INSERT INTO diary_entries (user_id,movie_id,watch_date,sort_order,rating,liked,rewatch,review) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),liked=VALUES(liked),rewatch=VALUES(rewatch),review=VALUES(review)");
            $ins->execute([$userId,$movieId,$watchDate,$so,$rating,$liked,$rewatch,$review]);
            echo json_encode(['ok'=>true]); exit;
        } catch(\PDOException $e) { echo json_encode(['ok'=>false,'msg'=>'Fehler']); exit; }
    }

    if ($_GET['action'] === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id    = (int)($_POST['id']    ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        if (!$id) { echo json_encode(['ok'=>false]); exit; }
        $chk = $db->prepare("SELECT id FROM diary_entries WHERE id=? AND user_id=?");
        $chk->execute([$id,$userId]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false]); exit; }
        if ($field === 'rating') {
            $v = ($value==='') ? null : max(1,min(10,(int)$value));
            $db->prepare("UPDATE diary_entries SET rating=? WHERE id=?")->execute([$v,$id]);
        } elseif ($field === 'liked' || $field === 'rewatch') {
            $db->prepare("UPDATE diary_entries SET `$field`=? WHERE id=?")->execute([(int)(bool)$value,$id]);
        } elseif ($field === 'review') {
            $db->prepare("UPDATE diary_entries SET review=? WHERE id=?")->execute([trim($value)?:null,$id]);
        } elseif ($field === 'watch_date') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { echo json_encode(['ok'=>false]); exit; }
            $db->prepare("UPDATE diary_entries SET watch_date=? WHERE id=?")->execute([$value,$id]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($_GET['action'] === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM diary_entries WHERE id=? AND user_id=?")->execute([$id,$userId]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($_GET['action'] === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT de.*,m.title,m.title_en,m.original_title,m.year AS release_year,m.poster_path,m.poster_path_en FROM diary_entries de JOIN movies m ON m.id=de.movie_id WHERE de.id=? AND de.user_id=?");
        $stmt->execute([$id,$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) { $row['display_title']=movieTitle($row); $row['display_poster']=moviePosterUrl($row,'w185'); }
        echo json_encode($row?:null); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unbekannte Aktion']); exit;
}

// ── Year + data ───────────────────────────────────────────────────────────────
$selYear = max(2000, min(2099, (int)($_GET['year'] ?? date('Y'))));

$yStmt = $db->prepare("SELECT DISTINCT YEAR(watch_date) as y FROM diary_entries WHERE user_id=? ORDER BY y DESC");
$yStmt->execute([$userId]);
$years = $yStmt->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($selYear,$years)) { $years[]=$selYear; rsort($years); }

$stmt = $db->prepare("
    SELECT de.id,de.watch_date,de.movie_id,de.rating,de.liked,de.rewatch,de.review,
           m.title,m.title_en,m.original_title,m.year AS release_year,m.poster_path,m.poster_path_en,m.imdb_id
    FROM diary_entries de
    JOIN movies m ON m.id=de.movie_id
    WHERE de.user_id=? AND YEAR(de.watch_date)=?
    ORDER BY de.watch_date DESC, de.sort_order ASC
");
$stmt->execute([$userId,$selYear]);
$allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byMonth = [];
foreach ($allRows as $r) {
    $mk = substr($r['watch_date'], 0, 7);
    $dk = $r['watch_date'];
    $byMonth[$mk][$dk][] = $r;
}

$MDE  = ['','Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
$MSHORT = ['','JAN','FEB','MÄR','APR','MAI','JUN','JUL','AUG','SEP','OKT','NOV','DEZ'];

require_once __DIR__ . '/includes/header.php';
?>
<main class="container py-4" style="max-width:960px;">

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-journal-play fs-3" style="color:var(--mkfb-gold);"></i>
        <h1 class="h3 mb-0 fw-bold">Filmtagebuch</h1>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="d-flex align-items-center gap-1">
            <a href="?year=<?= $selYear-1 ?>" class="btn btn-sm dy-year-btn"><i class="bi bi-chevron-left"></i></a>
            <span class="fw-bold px-2"><?= $selYear ?></span>
            <a href="?year=<?= $selYear+1 ?>" class="btn btn-sm dy-year-btn <?= $selYear>=(int)date('Y')?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
        </div>
        <button class="btn btn-gold btn-sm" onclick="openAddModal()">
            <i class="bi bi-plus-lg me-1"></i>Eintrag
        </button>
    </div>
</div>

<?php if (!empty($allRows)): ?>
<div class="dy-stats mb-4">
    <span><strong><?= count($allRows) ?></strong> Filme</span>
    <span class="mx-2 opacity-25">|</span>
    <span><strong><?= count($byMonth) ?></strong> Monate</span>
    <?php $lc = count(array_filter($allRows,fn($r)=>$r['liked'])); if($lc): ?>
    <span class="mx-2 opacity-25">|</span>
    <span><i class="bi bi-heart-fill me-1" style="color:#e74c3c;"></i><strong><?= $lc ?></strong></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($byMonth)): ?>
<div class="text-center py-5" style="color:rgba(255,255,255,.4);">
    <i class="bi bi-journal fs-1 d-block mb-3 opacity-50"></i>
    <p class="mb-3">Noch keine Einträge für <?= $selYear ?>.</p>
    <button class="btn btn-gold" onclick="openAddModal()"><i class="bi bi-plus-lg me-1"></i>Ersten Eintrag hinzufügen</button>
</div>
<?php else: ?>

<div id="diaryList">
<?php foreach ($byMonth as $mk => $entries):
    [$mY,$mN] = explode('-',$mk); $mN=(int)$mN;
?>
<div class="dy-month-block" data-month="<?= $mk ?>">
    <div class="dy-month-header">
        <span><?= $MDE[$mN] ?> <?= $mY ?></span>
        <span class="dy-month-count"><?= array_sum(array_map('count', $entries)) ?> Film<?= array_sum(array_map('count', $entries))!==1?'e':'' ?></span>
    </div>
    <?php foreach ($entries as $date => $dayEntries):
        [$eY,$eM,$eD] = explode('-', $date);
        $day = (int)$eD; $mon = (int)$eM;
    ?>
    <div class="dy-day-row">
        <div class="dy-date">
            <span class="dy-date-mon"><?= $MSHORT[$mon] ?></span>
            <span class="dy-date-day"><?= $day ?></span>
        </div>
        <div class="dy-covers">
        <?php foreach ($dayEntries as $e):
            $poster = moviePosterUrl($e, 'w185');
            $title  = movieTitle($e);
        ?>
            <div class="dy-cover-item dy-row" data-id="<?= $e['id'] ?>">
                <a href="/film.php?id=<?= $e['movie_id'] ?>" class="dy-cover-link" title="<?= e($title) ?>">
                    <img src="<?= e($poster) ?>" alt="<?= e($title) ?>" loading="lazy"
                         onerror="this.onerror=null;this.src='/assets/no-poster.svg'">
                </a>
                <div class="dy-cover-stars" data-id="<?= $e['id'] ?>" data-rating="<?= $e['rating']??'' ?>" onclick="openRatingPicker(this)" title="Bewerten">
                    <?= renderStars($e['rating']!==null?(int)$e['rating']:null) ?>
                </div>
                <div class="dy-cover-overlay">
                    <button class="dy-obtn <?= $e['liked']?'dy-btn--liked':'' ?>"
                            onclick="toggleField(<?= $e['id'] ?>,'liked',this)" title="Gefällt mir">
                        <i class="bi bi-heart<?= $e['liked']?'-fill':'' ?>"></i>
                    </button>
                    <button class="dy-obtn <?= $e['rewatch']?'dy-btn--rewatch':'' ?>"
                            onclick="toggleField(<?= $e['id'] ?>,'rewatch',this)" title="Nochmal gesehen">
                        <i class="bi bi-arrow-repeat"></i>
                    </button>
                    <button class="dy-obtn <?= $e['review']?'dy-btn--review':'' ?>"
                            onclick="openReviewModal(<?= $e['id'] ?>,<?= json_encode($e['review']??'') ?>)" title="Rezension">
                        <i class="bi bi-chat-square-text<?= $e['review']?'-fill':'' ?>"></i>
                    </button>
                    <button class="dy-obtn" onclick="editEntry(<?= $e['id'] ?>)" title="Bearbeiten">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="dy-obtn dy-obtn--del" onclick="deleteEntry(<?= $e['id'] ?>)" title="Löschen">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>
</main>

<!-- ══ ADD MODAL ══ -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content dy-modal">
      <div class="modal-header dy-modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2" style="color:var(--mkfb-gold);"></i>Eintrag hinzufügen</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="dy-label">Datum</label>
          <input type="date" id="addDate" class="form-control dy-input" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="mb-3">
          <label class="dy-label">Film / Serie</label>
          <div class="input-group mb-2">
            <input type="text" id="addSearch" class="form-control dy-input" placeholder="Titel suchen …" autocomplete="off">
            <button class="btn btn-gold" onclick="runAddSearch()"><i class="bi bi-search"></i></button>
          </div>
          <div id="addSearchResults"></div>
          <div id="addSelectedFilm"></div>
          <input type="hidden" id="addMovieId">
        </div>
        <div class="mb-3">
          <label class="dy-label">Bewertung</label>
          <div class="dy-star-picker" id="addStarPicker" data-value="">
            <?php for($s=1;$s<=10;$s++): ?>
            <span class="sp-star" data-val="<?= $s ?>"><?= $s%2===1?'<i class="bi bi-star-half"></i>':'<i class="bi bi-star-fill"></i>' ?></span>
            <?php endfor; ?>
            <span class="sp-clear" onclick="clearPicker('addStarPicker','addRating')">✕</span>
          </div>
          <input type="hidden" id="addRating">
        </div>
        <div class="mb-3 d-flex gap-4">
          <label class="dy-check-label"><input type="checkbox" id="addLiked"> <i class="bi bi-heart-fill me-1" style="color:#e74c3c;"></i>Gefällt mir</label>
          <label class="dy-check-label"><input type="checkbox" id="addRewatch"> <i class="bi bi-arrow-repeat me-1" style="color:#3498db;"></i>Nochmal gesehen</label>
        </div>
        <div class="mb-3">
          <label class="dy-label">Rezension (optional)</label>
          <textarea id="addReview" class="form-control dy-input" rows="3" placeholder="Deine Gedanken …"></textarea>
        </div>
      </div>
      <div class="modal-footer dy-modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
        <button class="btn btn-gold" onclick="submitAdd()"><i class="bi bi-check2 me-1"></i>Speichern</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ EDIT MODAL ══ -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content dy-modal">
      <div class="modal-header dy-modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil me-2" style="color:var(--mkfb-gold);"></i>Eintrag bearbeiten</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editId">
        <div id="editFilmInfo" class="d-flex align-items-center gap-3 mb-3 p-3 rounded" style="background:rgba(255,255,255,.05);">
          <img id="editPoster" src="" style="width:52px;aspect-ratio:2/3;object-fit:cover;border-radius:4px;" onerror="this.onerror=null;this.src='/img/no-poster.svg'">
          <div><div id="editTitle" class="fw-bold"></div><div id="editYear" class="small opacity-50"></div></div>
        </div>
        <div class="mb-3">
          <label class="dy-label">Datum</label>
          <input type="date" id="editDate" class="form-control dy-input">
        </div>
        <div class="mb-3">
          <label class="dy-label">Bewertung</label>
          <div class="dy-star-picker" id="editStarPicker" data-value="">
            <?php for($s=1;$s<=10;$s++): ?>
            <span class="sp-star" data-val="<?= $s ?>"><?= $s%2===1?'<i class="bi bi-star-half"></i>':'<i class="bi bi-star-fill"></i>' ?></span>
            <?php endfor; ?>
            <span class="sp-clear" onclick="clearPicker('editStarPicker','editRating')">✕</span>
          </div>
          <input type="hidden" id="editRating">
        </div>
        <div class="mb-3 d-flex gap-4">
          <label class="dy-check-label"><input type="checkbox" id="editLiked"> <i class="bi bi-heart-fill me-1" style="color:#e74c3c;"></i>Gefällt mir</label>
          <label class="dy-check-label"><input type="checkbox" id="editRewatch"> <i class="bi bi-arrow-repeat me-1" style="color:#3498db;"></i>Nochmal gesehen</label>
        </div>
        <div class="mb-3">
          <label class="dy-label">Rezension</label>
          <textarea id="editReview" class="form-control dy-input" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-footer dy-modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
        <button class="btn btn-gold" onclick="submitEdit()"><i class="bi bi-check2 me-1"></i>Speichern</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ REVIEW MODAL ══ -->
<div class="modal fade" id="reviewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content dy-modal">
      <div class="modal-header dy-modal-header">
        <h5 class="modal-title"><i class="bi bi-chat-square-text me-2" style="color:var(--mkfb-gold);"></i>Rezension</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="reviewId">
        <textarea id="reviewText" class="form-control dy-input" rows="5" placeholder="Deine Gedanken zum Film …"></textarea>
      </div>
      <div class="modal-footer dy-modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
        <button class="btn btn-gold" onclick="saveReview()"><i class="bi bi-check2 me-1"></i>Speichern</button>
      </div>
    </div>
  </div>
</div>

<!-- Inline rating popover -->
<div id="ratingPop" class="dy-rating-pop d-none">
    <div id="ratingPopStars" class="dy-pop-stars"></div>
    <button class="dy-pop-clear" onclick="clearInlineRating()">✕ Löschen</button>
</div>

<style>
/* ── Layout ── */
.dy-year-btn { background:rgba(255,255,255,.08); color:#fff; border:1px solid rgba(255,255,255,.15); }
.dy-year-btn:hover { background:rgba(255,255,255,.15); color:#fff; }
.dy-stats { color:rgba(255,255,255,.6); font-size:.9rem; }

.dy-month-block { margin-bottom:2rem; }
.dy-month-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:.4rem 0; margin-bottom:.25rem;
    border-bottom:1px solid rgba(232,184,75,.3);
    color:var(--mkfb-gold); font-weight:700; font-size:.95rem; letter-spacing:.04em;
}
.dy-month-count { font-size:.78rem; opacity:.6; font-weight:400; }

/* Day row: date + horizontal cover strip */
.dy-day-row {
    display:flex; align-items:flex-start; gap:.75rem;
    padding:.6rem .2rem;
    border-bottom:1px solid rgba(255,255,255,.05);
}
.dy-date { display:flex; flex-direction:column; align-items:center; min-width:42px; text-align:center; flex-shrink:0; padding-top:4px; }
.dy-date-mon { font-size:.58rem; font-weight:700; color:var(--mkfb-gold); letter-spacing:.08em; line-height:1; text-transform:uppercase; }
.dy-date-day { font-size:1.45rem; font-weight:800; color:#fff; line-height:1.1; }

/* Horizontal cover strip */
.dy-covers { display:flex; flex-wrap:wrap; gap:.5rem; align-items:flex-start; }

/* Individual cover item */
.dy-cover-item { position:relative; flex-shrink:0; }
.dy-cover-link { display:block; }
.dy-cover-link img {
    width:143px; aspect-ratio:2/3; object-fit:cover;
    border-radius:5px; display:block; background:rgba(255,255,255,.08);
    transition:opacity .2s;
}
.dy-cover-item:hover .dy-cover-link img { opacity:.45; }

/* Overlay actions – appear on hover */
.dy-cover-overlay {
    position:absolute; inset:0;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:3px; opacity:0; transition:opacity .2s; pointer-events:none;
    border-radius:5px;
}
.dy-cover-item:hover .dy-cover-overlay { opacity:1; pointer-events:all; }
.dy-obtn {
    background:rgba(0,0,0,.65); border:none; color:rgba(255,255,255,.8);
    width:26px; height:26px; border-radius:50%; cursor:pointer;
    font-size:.78rem; display:flex; align-items:center; justify-content:center;
    transition:background .15s, color .15s; padding:0;
}
.dy-obtn:hover { background:rgba(255,255,255,.2); color:#fff; }
.dy-obtn--del:hover { background:rgba(220,53,69,.7); color:#fff; }

/* Stars below cover */
.dy-cover-stars {
    display:flex; justify-content:center; gap:1px;
    margin-top:4px; cursor:pointer;
    color:var(--mkfb-gold); font-size:.85rem;
    line-height:1;
}
.dy-cover-stars .dy-no-rating {
    font-size:.6rem; color:rgba(255,255,255,.2); white-space:nowrap;
}
.dy-cover-stars:hover { opacity:.75; }

.dy-stars {
    display:flex; align-items:center; gap:1px;
    cursor:pointer; color:var(--mkfb-gold); font-size:.82rem;
    padding:.25rem .35rem; border-radius:4px;
    transition:background .15s; min-width:84px;
}
.dy-stars:hover { background:rgba(255,255,255,.08); }
.dy-no-rating { font-size:.7rem; color:rgba(255,255,255,.25); white-space:nowrap; }

.dy-btn {
    background:none; border:none; color:rgba(255,255,255,.3);
    padding:.3rem .35rem; border-radius:5px; cursor:pointer;
    font-size:.95rem; transition:color .15s,background .15s; line-height:1;
}
.dy-btn:hover { color:rgba(255,255,255,.75); background:rgba(255,255,255,.08); }
.dy-btn--liked   { color:#e74c3c !important; }
.dy-btn--rewatch { color:#3498db !important; }
.dy-btn--review  { color:var(--mkfb-gold) !important; }

.dy-dropdown { background:#1a3a6b; border:1px solid rgba(255,255,255,.15); }
.dy-dropdown .dropdown-item { color:#ddd; font-size:.88rem; }
.dy-dropdown .dropdown-item:hover { background:rgba(255,255,255,.1); color:#fff; }
.dy-dropdown .dropdown-divider { border-color:rgba(255,255,255,.1); }

/* ── Star picker (modal) ── */
.dy-star-picker { display:flex; align-items:center; gap:2px; flex-wrap:nowrap; padding:.25rem 0; }
.sp-star { cursor:pointer; font-size:1.15rem; color:rgba(255,255,255,.2); padding:2px; border-radius:3px; transition:color .1s; line-height:1; }
.sp-star:hover,.sp-star.on { color:var(--mkfb-gold); }
.sp-clear { font-size:.78rem; color:rgba(255,255,255,.25); cursor:pointer; margin-left:.5rem; padding:2px 6px; border-radius:3px; }
.sp-clear:hover { color:#e74c3c; background:rgba(231,76,60,.1); }

/* ── Inline rating popover ── */
.dy-rating-pop {
    position:fixed; z-index:9999;
    background:#0d2748; border:1px solid rgba(255,255,255,.2);
    border-radius:8px; padding:.5rem .75rem;
    box-shadow:0 8px 24px rgba(0,0,0,.55); min-width:190px;
}
.dy-pop-stars { display:flex; gap:2px; margin-bottom:.4rem; }
.dy-pop-stars .sp-star { font-size:1.05rem; }
.dy-pop-clear { background:none; border:none; color:rgba(255,255,255,.3); font-size:.75rem; cursor:pointer; padding:0; width:100%; text-align:left; }
.dy-pop-clear:hover { color:#e74c3c; }

/* ── Modals ── */
.dy-modal { background:#0d2748; border:1px solid rgba(255,255,255,.15); color:#e0e0e0; }
.dy-modal-header { border-bottom:1px solid rgba(255,255,255,.1); }
.dy-modal-footer { border-top:1px solid rgba(255,255,255,.1); }
.dy-label { display:block; color:rgba(255,255,255,.65); font-size:.82rem; font-weight:600; margin-bottom:.3rem; }
.dy-input { background:#1a3a6b !important; color:#fff !important; border-color:rgba(255,255,255,.2) !important; }
.dy-input::placeholder { color:rgba(255,255,255,.3) !important; }
.dy-check-label { display:flex; align-items:center; gap:.4rem; cursor:pointer; color:rgba(255,255,255,.8); font-size:.9rem; }

/* Search results in modal */
.asr-card {
    display:flex; align-items:center; gap:.6rem;
    padding:.45rem .65rem; border-radius:6px; cursor:pointer;
    transition:background .15s; border:1px solid transparent;
}
.asr-card:hover { background:rgba(232,184,75,.1); border-color:rgba(232,184,75,.2); }
.asr-card img { width:30px; aspect-ratio:2/3; object-fit:cover; border-radius:3px; flex-shrink:0; }
.asr-card .asr-t { font-size:.87rem; font-weight:600; }
.asr-card .asr-y { font-size:.73rem; color:rgba(255,255,255,.4); }

.asr-selected {
    display:flex; align-items:center; gap:.75rem; margin-top:.5rem;
    padding:.5rem .75rem; background:rgba(232,184,75,.08);
    border:1px solid rgba(232,184,75,.2); border-radius:6px;
}
.asr-selected img { width:34px; aspect-ratio:2/3; object-fit:cover; border-radius:3px; }

@media (max-width:576px) {
    .dy-film-name { font-size:.82rem; }
    .dy-stars { min-width:70px; font-size:.75rem; }
    .dy-date-day { font-size:1.1rem; }
    .dy-actions { gap:.05rem; }
}
</style>

<script>
let addModal=null, editModal=null, reviewModal=null, ratingTarget=null;

document.addEventListener('DOMContentLoaded', function() {
    addModal    = new bootstrap.Modal(document.getElementById('addModal'));
    editModal   = new bootstrap.Modal(document.getElementById('editModal'));
    reviewModal = new bootstrap.Modal(document.getElementById('reviewModal'));

    initPicker('addStarPicker','addRating');
    initPicker('editStarPicker','editRating');

    document.getElementById('addSearch').addEventListener('keydown', e => {
        if (e.key==='Enter') { e.preventDefault(); runAddSearch(); }
    });

    document.addEventListener('click', e => {
        const pop = document.getElementById('ratingPop');
        if (!pop.classList.contains('d-none') && !pop.contains(e.target) && !e.target.closest('.dy-stars'))
            hidePop();
    });
});

// ── Star pickers (modal) ──────────────────────────────────────────────────────
function initPicker(pickerId, hiddenId) {
    const picker = document.getElementById(pickerId);
    const hidden = document.getElementById(hiddenId);
    if (!picker) return;
    picker.querySelectorAll('.sp-star').forEach(s => {
        s.addEventListener('mouseenter', () => hilite(picker, +s.dataset.val));
        s.addEventListener('mouseleave', () => hilite(picker, +hidden.value || 0));
        s.addEventListener('click', () => {
            hidden.value = s.dataset.val;
            picker.dataset.value = s.dataset.val;
            hilite(picker, +s.dataset.val);
        });
    });
}
function hilite(picker, val) {
    picker.querySelectorAll('.sp-star').forEach(s => s.classList.toggle('on', +s.dataset.val <= val));
}
function clearPicker(pickerId, hiddenId) {
    const p = document.getElementById(pickerId), h = document.getElementById(hiddenId);
    if (h) h.value=''; if (p) { p.dataset.value=''; hilite(p,0); }
}

// ── Add modal ─────────────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('addDate').value    = new Date().toISOString().slice(0,10);
    document.getElementById('addMovieId').value = '';
    document.getElementById('addSearch').value  = '';
    document.getElementById('addSearchResults').innerHTML = '';
    document.getElementById('addSelectedFilm').innerHTML  = '';
    document.getElementById('addReview').value  = '';
    document.getElementById('addLiked').checked = false;
    document.getElementById('addRewatch').checked = false;
    clearPicker('addStarPicker','addRating');
    addModal.show();
    setTimeout(() => document.getElementById('addSearch').focus(), 300);
}

function runAddSearch() {
    const q = document.getElementById('addSearch').value.trim();
    if (!q) return;
    const box = document.getElementById('addSearchResults');
    box.innerHTML = '<span class="text-muted small">Suche …</span>';
    fetch('?action=search&q='+encodeURIComponent(q))
        .then(r=>r.json()).then(res => {
            box.innerHTML='';
            if (!res.length) { box.innerHTML='<span class="text-muted small">Keine Ergebnisse.</span>'; return; }
            res.forEach(f => {
                const d=document.createElement('div');
                d.className='asr-card';
                d.innerHTML=`<img src="${esc(f.display_poster)}" onerror="this.onerror=null;this.src='/img/no-poster.svg'" alt=""><div><div class="asr-t">${esc(f.display_title)}</div>${f.year?`<div class="asr-y">${f.year}</div>`:''}</div>`;
                d.onclick=()=>selectFilm(f);
                box.appendChild(d);
            });
        });
}

function selectFilm(f) {
    document.getElementById('addMovieId').value = f.id;
    document.getElementById('addSearchResults').innerHTML = '';
    document.getElementById('addSearch').value = '';
    document.getElementById('addSelectedFilm').innerHTML = `
        <div class="asr-selected">
            <img src="${esc(f.display_poster)}" onerror="this.onerror=null;this.src='/img/no-poster.svg'" alt="">
            <div class="flex-grow-1"><strong>${esc(f.display_title)}</strong>${f.year?` <span class="opacity-50 small">${f.year}</span>`:''}</div>
            <button type="button" class="btn-close btn-close-white" style="font-size:.65rem"
                onclick="document.getElementById('addMovieId').value='';document.getElementById('addSelectedFilm').innerHTML=''"></button>
        </div>`;
}

async function submitAdd() {
    if (!document.getElementById('addMovieId').value) { alert('Bitte einen Film auswählen.'); return; }
    const fd = new FormData();
    fd.append('movie_id',   document.getElementById('addMovieId').value);
    fd.append('watch_date', document.getElementById('addDate').value);
    fd.append('rating',     document.getElementById('addRating').value);
    fd.append('liked',      document.getElementById('addLiked').checked?1:0);
    fd.append('rewatch',    document.getElementById('addRewatch').checked?1:0);
    fd.append('review',     document.getElementById('addReview').value);
    const res = await fetch('?action=add',{method:'POST',body:fd}).then(r=>r.json());
    if (res.ok) { addModal.hide(); location.reload(); }
    else alert(res.msg||'Fehler beim Speichern.');
}

// ── Toggle like / rewatch ─────────────────────────────────────────────────────
async function toggleField(id, field, btn) {
    const wasActive = btn.classList.contains('dy-btn--liked') || btn.classList.contains('dy-btn--rewatch');
    const val = wasActive?0:1;
    const fd = new FormData(); fd.append('id',id); fd.append('field',field); fd.append('value',val);
    const res = await fetch('?action=update',{method:'POST',body:fd}).then(r=>r.json());
    if (!res.ok) return;
    if (field==='liked') {
        btn.classList.toggle('dy-btn--liked', !!val);
        btn.querySelector('i').className = val?'bi bi-heart-fill':'bi bi-heart';
    } else {
        btn.classList.toggle('dy-btn--rewatch', !!val);
    }
}

// ── Inline rating popover ─────────────────────────────────────────────────────
function openRatingPicker(el) {
    ratingTarget = el;
    const cur = +el.dataset.rating || 0;
    const starsDiv = document.getElementById('ratingPopStars');
    starsDiv.innerHTML = '';
    for (let i=1;i<=10;i++) {
        const s = document.createElement('span');
        s.className = 'sp-star'+(i<=cur?' on':'');
        s.dataset.val = i;
        s.innerHTML = i%2===1?'<i class="bi bi-star-half"></i>':'<i class="bi bi-star-fill"></i>';
        s.addEventListener('mouseover', ()=>{
            starsDiv.querySelectorAll('.sp-star').forEach((x,idx)=>x.classList.toggle('on',idx<i));
        });
        s.addEventListener('click', ()=>setInlineRating(i));
        starsDiv.appendChild(s);
    }
    starsDiv.addEventListener('mouseleave', ()=>{
        const c=+ratingTarget?.dataset.rating||0;
        starsDiv.querySelectorAll('.sp-star').forEach((x,idx)=>x.classList.toggle('on',idx<c));
    });
    const pop = document.getElementById('ratingPop');
    pop.classList.remove('d-none');
    const rect = el.getBoundingClientRect();
    pop.style.top  = (rect.bottom+window.scrollY+4)+'px';
    pop.style.left = Math.max(8, rect.left+window.scrollX-10)+'px';
}

async function setInlineRating(val) {
    if (!ratingTarget) return;
    const id = ratingTarget.dataset.id;
    const fd = new FormData(); fd.append('id',id); fd.append('field','rating'); fd.append('value',val);
    const res = await fetch('?action=update',{method:'POST',body:fd}).then(r=>r.json());
    if (!res.ok) return;
    ratingTarget.dataset.rating = val;
    ratingTarget.innerHTML = starsHTML(val);
    hidePop();
}

async function clearInlineRating() {
    if (!ratingTarget) return;
    const id = ratingTarget.dataset.id;
    const fd = new FormData(); fd.append('id',id); fd.append('field','rating'); fd.append('value','');
    await fetch('?action=update',{method:'POST',body:fd});
    ratingTarget.dataset.rating='';
    ratingTarget.innerHTML='<span class="dy-no-rating">Bewerten</span>';
    hidePop();
}

function hidePop() { document.getElementById('ratingPop').classList.add('d-none'); ratingTarget=null; }

function starsHTML(r) {
    if (!r) return '<span class="dy-no-rating">Bewerten</span>';
    let out='';
    for (let i=1;i<=5;i++){
        const v=i*2;
        if(r>=v) out+='<i class="bi bi-star-fill"></i>';
        else if(r>=v-1) out+='<i class="bi bi-star-half"></i>';
        else out+='<i class="bi bi-star"></i>';
    }
    return out;
}

// ── Review modal ──────────────────────────────────────────────────────────────
function openReviewModal(id, text) {
    document.getElementById('reviewId').value   = id;
    document.getElementById('reviewText').value = text||'';
    reviewModal.show();
}

async function saveReview() {
    const id   = document.getElementById('reviewId').value;
    const text = document.getElementById('reviewText').value;
    const fd = new FormData(); fd.append('id',id); fd.append('field','review'); fd.append('value',text);
    const res = await fetch('?action=update',{method:'POST',body:fd}).then(r=>r.json());
    if (!res.ok) { alert('Fehler'); return; }
    const item = document.querySelector('.dy-cover-item[data-id="'+id+'"]');
    if (item) {
        const btn = item.querySelector('[onclick^="openReviewModal"]');
        if (btn) {
            const has = !!text.trim();
            btn.classList.toggle('dy-btn--review', has);
            btn.querySelector('i').className = has?'bi bi-chat-square-text-fill':'bi bi-chat-square-text';
        }
    }
    reviewModal.hide();
}

// ── Edit modal ────────────────────────────────────────────────────────────────
async function editEntry(id) {
    const data = await fetch('?action=get&id='+id).then(r=>r.json());
    if (!data) { alert('Nicht gefunden'); return; }
    document.getElementById('editId').value         = id;
    document.getElementById('editDate').value        = data.watch_date;
    document.getElementById('editPoster').src        = data.display_poster||'/img/no-poster.svg';
    document.getElementById('editTitle').textContent = data.display_title||data.title||'';
    document.getElementById('editYear').textContent  = data.release_year||'';
    document.getElementById('editLiked').checked     = !!+data.liked;
    document.getElementById('editRewatch').checked   = !!+data.rewatch;
    document.getElementById('editReview').value      = data.review||'';
    const r = +data.rating||0;
    document.getElementById('editRating').value = r||'';
    hilite(document.getElementById('editStarPicker'), r);
    editModal.show();
}

async function submitEdit() {
    const id = document.getElementById('editId').value;
    const updates = [
        ['watch_date', document.getElementById('editDate').value],
        ['rating',     document.getElementById('editRating').value],
        ['liked',      document.getElementById('editLiked').checked?1:0],
        ['rewatch',    document.getElementById('editRewatch').checked?1:0],
        ['review',     document.getElementById('editReview').value],
    ];
    for (const [field,value] of updates) {
        const fd = new FormData(); fd.append('id',id); fd.append('field',field); fd.append('value',value);
        await fetch('?action=update',{method:'POST',body:fd});
    }
    editModal.hide(); location.reload();
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function deleteEntry(id) {
    if (!confirm('Eintrag löschen?')) return;
    const fd = new FormData(); fd.append('id',id);
    const res = await fetch('?action=remove',{method:'POST',body:fd}).then(r=>r.json());
    if (!res.ok) return;
    const item = document.querySelector('.dy-cover-item[data-id="'+id+'"]');
    if (item) {
        item.style.transition='opacity .25s';
        item.style.opacity='0';
        setTimeout(()=>{
            const dayRow = item.closest('.dy-day-row');
            const block  = item.closest('.dy-month-block');
            item.remove();
            if (dayRow && !dayRow.querySelectorAll('.dy-cover-item').length) dayRow.remove();
            if (block  && !block.querySelectorAll('.dy-cover-item').length)  block.remove();
        },260);
    }
}

function esc(s) {
    return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
