<?php
$pageTitle       = 'Filmperlen – MKFB';
$pageDescription = 'Filmperlen der Community – Lieblingsfilme und Serientipps von MKFB-Mitgliedern.';
require_once __DIR__ . '/includes/functions.php';
startSession();

$db         = getDB();
$loggedIn   = isLoggedIn();
$userId     = $loggedIn ? (int)$_SESSION['user_id'] : 0;
$canMod     = $loggedIn && canModerate();
$canPost    = $loggedIn && canAuthor(); // nur Superadmin, Admin, Autor dürfen Beiträge verfassen

// ── DB-Schema ─────────────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS filmperlen (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    title          VARCHAR(255) NOT NULL,
    content        TEXT NOT NULL,
    movie_id       INT UNSIGNED NULL,
    image_path     VARCHAR(500) DEFAULT NULL,
    image_position ENUM('left','right') NOT NULL DEFAULT 'right',
    hidden         TINYINT(1) NOT NULL DEFAULT 0,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_movie  (movie_id),
    INDEX idx_hidden (hidden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS filmperlen_comments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    perle_id   INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    content    TEXT NOT NULL,
    hidden     TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_perle (perle_id),
    INDEX idx_user  (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS filmperlen_likes (
    perle_id   INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (perle_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── POST-Handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loggedIn && csrfValid()) {
    $action = $_POST['action'] ?? '';

    // Neue Perle erstellen (nur Autoren)
    if ($action === 'create' && $canPost) {
        $title   = trim($_POST['title']   ?? '');
        $content = trim($_POST['content'] ?? '');
        $movieId = (int)($_POST['movie_id'] ?? 0) ?: null;
        $imgPos  = ($_POST['image_position'] ?? 'right') === 'left' ? 'left' : 'right';
        if ($title !== '' && $content !== '') {
            $db->prepare("INSERT INTO filmperlen (user_id, title, content, movie_id, image_path, image_position) VALUES (?,?,?,NULL,NULL,?)")
               ->execute([$userId, $title, $content, $imgPos]);
            $newId = (int)$db->lastInsertId();
            if ($movieId) {
                $db->prepare("UPDATE filmperlen SET movie_id=? WHERE id=?")->execute([$movieId, $newId]);
            }
            header('Location: /filmperlen.php?id=' . $newId . '#top');
            exit;
        }
    }

    // Perle bearbeiten (Eigentümer mit Autor-Recht oder Mod)
    if ($action === 'edit' && ($canPost || $canMod)) {
        $perleId = (int)($_POST['perle_id'] ?? 0);
        $chk = $db->prepare("SELECT user_id FROM filmperlen WHERE id=?");
        $chk->execute([$perleId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if ($row && ($row['user_id'] === $userId || $canMod)) {
            $title   = trim($_POST['title']   ?? '');
            $content = trim($_POST['content'] ?? '');
            $movieId = (int)($_POST['movie_id'] ?? 0) ?: null;
            $imgPos  = ($_POST['image_position'] ?? 'right') === 'left' ? 'left' : 'right';
            if ($title !== '' && $content !== '') {
                $db->prepare("UPDATE filmperlen SET title=?, content=?, movie_id=?, image_position=?, updated_at=NOW() WHERE id=?")
                   ->execute([$title, $content, $movieId, $imgPos, $perleId]);
                header('Location: /filmperlen.php?id=' . $perleId . '#top');
                exit;
            }
        }
    }

    // Perle löschen
    if ($action === 'delete') {
        $perleId = (int)($_POST['perle_id'] ?? 0);
        $chk = $db->prepare("SELECT user_id FROM filmperlen WHERE id=?");
        $chk->execute([$perleId]);
        $row = $chk->fetch();
        if ($row && ($row['user_id'] === $userId || $canMod)) {
            $db->prepare("DELETE FROM filmperlen_likes    WHERE perle_id=?")->execute([$perleId]);
            $db->prepare("DELETE FROM filmperlen_comments WHERE perle_id=?")->execute([$perleId]);
            $db->prepare("DELETE FROM filmperlen          WHERE id=?")->execute([$perleId]);
        }
        header('Location: /filmperlen.php');
        exit;
    }

    // Kommentar
    if ($action === 'comment') {
        $perleId = (int)($_POST['perle_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if ($perleId && $content !== '') {
            $db->prepare("INSERT INTO filmperlen_comments (perle_id, user_id, content) VALUES (?,?,?)")
               ->execute([$perleId, $userId, $content]);
        }
        header('Location: /filmperlen.php?id=' . $perleId . '#kommentare');
        exit;
    }

    // Kommentar ausblenden (Mod)
    if ($action === 'toggle_comment' && $canMod) {
        $cId     = (int)($_POST['comment_id'] ?? 0);
        $perleId = (int)($_POST['perle_id']   ?? 0);
        $db->prepare("UPDATE filmperlen_comments SET hidden = 1 - hidden WHERE id=?")->execute([$cId]);
        header('Location: /filmperlen.php?id=' . $perleId . '#kommentare');
        exit;
    }

    // Like / Unlike (AJAX)
    if ($action === 'like') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $perleId = (int)($_POST['perle_id'] ?? 0);
        $chk = $db->prepare("SELECT 1 FROM filmperlen_likes WHERE perle_id=? AND user_id=?");
        $chk->execute([$perleId, $userId]);
        if ($chk->fetchColumn()) {
            $db->prepare("DELETE FROM filmperlen_likes WHERE perle_id=? AND user_id=?")->execute([$perleId, $userId]);
            $liked = false;
        } else {
            $db->prepare("INSERT IGNORE INTO filmperlen_likes (perle_id, user_id) VALUES (?,?)")->execute([$perleId, $userId]);
            $liked = true;
        }
        $cnt = (int)$db->prepare("SELECT COUNT(*) FROM filmperlen_likes WHERE perle_id=?")->execute([$perleId]) ? $db->prepare("SELECT COUNT(*) FROM filmperlen_likes WHERE perle_id=?")->execute([$perleId]) : 0;
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM filmperlen_likes WHERE perle_id=?");
        $cntStmt->execute([$perleId]);
        echo json_encode(['liked' => $liked, 'count' => (int)$cntStmt->fetchColumn()]);
        exit;
    }

    // Perle ein-/ausblenden (Mod)
    if ($action === 'toggle_hidden' && $canMod) {
        $perleId = (int)($_POST['perle_id'] ?? 0);
        $db->prepare("UPDATE filmperlen SET hidden = 1 - hidden WHERE id=?")->execute([$perleId]);
        $redir = (int)($_POST['from_id'] ?? 0);
        header('Location: /filmperlen.php' . ($redir ? '?id=' . $redir : ''));
        exit;
    }
}

// ── AJAX: Film-Suche (für Filmauswahl-Feld) ───────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_film' && $loggedIn) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $q    = trim($_GET['q'] ?? '');
    $rows = [];
    if (mb_strlen($q) >= 2) {
        $s = $db->prepare("SELECT id, title, title_en, year, poster_path FROM movies WHERE title LIKE ? OR title_en LIKE ? ORDER BY year DESC LIMIT 15");
        $s->execute(['%' . $q . '%', '%' . $q . '%']);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($rows);
    exit;
}

// ── Daten laden ───────────────────────────────────────────────────────────────
$showId  = isset($_GET['id'])  ? (int)$_GET['id']  : 0;
$editId  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$newMode = isset($_GET['new']) && $canPost;
$filterUser = isset($_GET['user']) ? (int)$_GET['user'] : 0;

$perle = null; $comments = []; $editPerle = null;

if ($showId > 0) {
    $ps = $db->prepare(
        "SELECT fp.*, u.username AS author_name,
                m.title AS film_title, m.year AS film_year, m.poster_path AS film_poster, m.tmdb_id AS film_tmdb,
                (SELECT COUNT(*) FROM filmperlen_likes l WHERE l.perle_id = fp.id) AS like_count
         FROM filmperlen fp
         JOIN users u ON u.id = fp.user_id
         LEFT JOIN movies m ON m.id = fp.movie_id
         WHERE fp.id = ?"
    );
    $ps->execute([$showId]);
    $perle = $ps->fetch(PDO::FETCH_ASSOC);
    if (!$perle || ($perle['hidden'] && !$canMod)) { header('Location: /filmperlen.php'); exit; }

    $cs = $db->prepare("SELECT fc.*, u.username AS author_name FROM filmperlen_comments fc JOIN users u ON u.id = fc.user_id WHERE fc.perle_id=? ORDER BY fc.created_at ASC");
    $cs->execute([$showId]);
    $comments = $cs->fetchAll(PDO::FETCH_ASSOC);

    $userLiked = $loggedIn && (bool)$db->prepare("SELECT 1 FROM filmperlen_likes WHERE perle_id=? AND user_id=?")->execute([$showId, $userId]);
    $likeStmt  = $db->prepare("SELECT 1 FROM filmperlen_likes WHERE perle_id=? AND user_id=?");
    $likeStmt->execute([$showId, $userId]);
    $userLiked = (bool)$likeStmt->fetchColumn();

} elseif ($editId > 0 && $loggedIn) {
    $es = $db->prepare("SELECT fp.*, m.title AS film_title FROM filmperlen fp LEFT JOIN movies m ON m.id = fp.movie_id WHERE fp.id=?");
    $es->execute([$editId]);
    $editPerle = $es->fetch(PDO::FETCH_ASSOC);
    if (!$editPerle || ($editPerle['user_id'] !== $userId && !$canMod)) $editPerle = null;
} else {
    // Liste
    $where  = $canMod ? '' : 'WHERE fp.hidden = 0';
    $params = [];
    if ($filterUser > 0) {
        $where  = ($where ? $where . ' AND' : 'WHERE') . ' fp.user_id = ?';
        $params[] = $filterUser;
    }
    $listStmt = $db->prepare(
        "SELECT fp.*, u.username AS author_name,
                m.title AS film_title, m.year AS film_year, m.poster_path AS film_poster,
                (SELECT COUNT(*) FROM filmperlen_likes l WHERE l.perle_id = fp.id) AS like_count,
                (SELECT COUNT(*) FROM filmperlen_comments c WHERE c.perle_id = fp.id AND c.hidden = 0) AS comment_count
         FROM filmperlen fp
         JOIN users u ON u.id = fp.user_id
         LEFT JOIN movies m ON m.id = fp.movie_id
         $where
         ORDER BY fp.created_at DESC"
    );
    $listStmt->execute($params);
    $perlen = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Für Filter-Username
    $filterUsername = '';
    if ($filterUser > 0) {
        $un = $db->prepare("SELECT username FROM users WHERE id=?");
        $un->execute([$filterUser]);
        $filterUsername = $un->fetchColumn() ?: '';
    }
}

$totalCount = (int)$db->query("SELECT COUNT(*) FROM filmperlen WHERE hidden=0")->fetchColumn();

require_once __DIR__ . '/includes/header.php';
?>
<style>
body { background:#14325a !important; }
.perle-card {
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.08);
    border-radius:14px;
    overflow:hidden;
    transition:border-color .2s, transform .2s;
}
.perle-card:hover { border-color:rgba(232,184,75,.3); transform:translateY(-2px); }
.perle-card-header {
    background:linear-gradient(135deg,rgba(232,184,75,.08) 0%,rgba(255,255,255,.02) 100%);
    border-bottom:1px solid rgba(255,255,255,.06);
    padding:1rem 1.25rem .75rem;
}
.perle-meta { color:rgba(255,255,255,.4); font-size:.78rem; }
.perle-excerpt { color:rgba(255,255,255,.65); font-size:.88rem; line-height:1.6; }
.perle-title a { color:#e8b84b; text-decoration:none; }
.perle-title a:hover { color:#f0ca6a; text-decoration:underline; }
.film-badge {
    display:inline-flex; align-items:center; gap:.5rem;
    background:rgba(232,184,75,.1); border:1px solid rgba(232,184,75,.25);
    border-radius:8px; padding:.3rem .7rem; font-size:.82rem;
    color:#e8b84b; text-decoration:none; margin-top:.5rem;
}
.film-badge:hover { background:rgba(232,184,75,.2); color:#f0ca6a; }
.like-btn {
    background:none; border:1px solid rgba(255,255,255,.15); color:rgba(255,255,255,.5);
    border-radius:20px; padding:.2rem .75rem; font-size:.8rem; cursor:pointer;
    transition:all .18s;
}
.like-btn.liked, .like-btn:hover { background:rgba(232,184,75,.15); border-color:rgba(232,184,75,.5); color:#e8b84b; }
.comment-box { background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.06); border-radius:10px; padding:1rem 1.2rem; margin-bottom:.6rem; }
.comment-box.hidden-comment { opacity:.4; border-color:rgba(244,67,54,.2); background:rgba(244,67,54,.03); }
.comment-author { color:#e8b84b; font-weight:600; font-size:.85rem; }
.comment-date   { color:rgba(255,255,255,.3); font-size:.75rem; }
.comment-text   { color:rgba(255,255,255,.7); font-size:.88rem; line-height:1.55; margin-top:.3rem; }
.btn-gold { background:#e8b84b; color:#1a1a1a; border:none; font-weight:700; }
.btn-gold:hover { background:#f0ca6a; color:#1a1a1a; }
textarea.form-control { background:rgba(255,255,255,.04) !important; border:1px solid rgba(255,255,255,.12) !important; color:#e0e0e0 !important; resize:vertical; }
textarea.form-control:focus { border-color:rgba(232,184,75,.4) !important; box-shadow:0 0 0 .2rem rgba(232,184,75,.1) !important; }
.form-control-dark { background:rgba(255,255,255,.06) !important; border:1px solid rgba(255,255,255,.18) !important; color:#fff !important; border-radius:8px; }
.form-control-dark:focus { border-color:rgba(232,184,75,.5) !important; box-shadow:0 0 0 .2rem rgba(232,184,75,.1) !important; outline:none; }
.form-control-dark::placeholder { color:rgba(255,255,255,.3); }
#film-suggest { position:absolute; z-index:120; left:0; right:0; background:#1b3a6b; border:1px solid rgba(255,255,255,.15); border-top:none; border-radius:0 0 8px 8px; max-height:260px; overflow-y:auto; display:none; }
.film-sug-item { display:flex; align-items:center; gap:.6rem; padding:.45rem .8rem; cursor:pointer; font-size:.85rem; }
.film-sug-item:hover { background:rgba(232,184,75,.12); }
.hidden-badge { background:rgba(244,67,54,.2); color:#ef9a9a; border:1px solid rgba(244,67,54,.3); border-radius:6px; font-size:.7rem; padding:1px 6px; margin-left:.4rem; }
</style>

<main style="padding-top:6px; background:#14325a; flex:1;">

<!-- Header -->
<section class="py-2" style="background:linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom:1px solid rgba(232,184,75,.15);">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between py-1">
            <div>
                <h1 class="fw-bold mb-0" style="color:#e8b84b; font-size:1.4rem;">
                    <i class="bi bi-gem me-2"></i>Filmperlen
                </h1>
                <p class="mb-0" style="color:rgba(255,255,255,.45); font-size:.82rem;">
                    <?= number_format($totalCount) ?> Empfehlungen der Community
                </p>
            </div>
            <div class="d-flex gap-2">
                <?php if ($showId || $editId || $newMode || $filterUser): ?>
                <a href="/filmperlen.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Alle Filmperlen
                </a>
                <?php endif; ?>
                <?php if ($canPost && !$newMode && !$editId): ?>
                <a href="/filmperlen.php?new=1" class="btn btn-gold btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>Filmperle teilen
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<section class="py-4" style="background:#14325a;">
<div class="container">

<?php
// ── Neue Perle / Bearbeiten ────────────────────────────────────────────────────
if ($newMode || ($editPerle !== null && ($canPost || $canMod))):
    $isEdit = $editPerle !== null;
    $formPerle = $isEdit ? $editPerle : [];
?>
<div class="row justify-content-center mb-5">
<div class="col-lg-7">
<div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09); border-radius:14px; padding:2rem;">
    <h4 class="fw-bold mb-4" style="color:#e8b84b;">
        <i class="bi bi-<?= $isEdit ? 'pencil' : 'gem' ?> me-2"></i>
        <?= $isEdit ? 'Filmperle bearbeiten' : 'Filmperle teilen' ?>
    </h4>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="<?= $isEdit ? 'edit' : 'create' ?>">
        <?php if ($isEdit): ?><input type="hidden" name="perle_id" value="<?= (int)$editPerle['id'] ?>"><?php endif; ?>

        <!-- Titel -->
        <div class="mb-3">
            <label class="form-label" style="color:rgba(255,255,255,.7);">Titel <span style="color:#e8b84b;">*</span></label>
            <input type="text" name="title" class="form-control form-control-dark"
                   value="<?= e($formPerle['title'] ?? '') ?>"
                   placeholder="z. B. Mein absoluter Lieblingsfilm…" required maxlength="255">
        </div>

        <!-- Film verknüpfen -->
        <div class="mb-3">
            <label class="form-label" style="color:rgba(255,255,255,.7);">Film/Serie verknüpfen <span style="color:rgba(255,255,255,.35); font-weight:400;">(optional)</span></label>
            <div style="position:relative;">
                <input type="text" id="film-search" class="form-control form-control-dark"
                       placeholder="Filmtitel suchen…" autocomplete="off"
                       value="<?= e($formPerle['film_title'] ?? '') ?>">
                <div id="film-suggest"></div>
            </div>
            <input type="hidden" id="movie-id-field" name="movie_id" value="<?= (int)($formPerle['movie_id'] ?? 0) ?>">
            <div id="film-selected" style="margin-top:.4rem; <?= empty($formPerle['film_title']) ? 'display:none;' : '' ?>">
                <span style="background:rgba(232,184,75,.1); border:1px solid rgba(232,184,75,.25); border-radius:6px; padding:.2rem .6rem; font-size:.8rem; color:#e8b84b;">
                    <i class="bi bi-film me-1"></i><span id="film-selected-name"><?= e($formPerle['film_title'] ?? '') ?></span>
                    <button type="button" onclick="clearFilm()" style="background:none;border:none;color:rgba(232,184,75,.6);padding:0 0 0 4px;cursor:pointer;">&times;</button>
                </span>
            </div>
        </div>

        <!-- Text -->
        <div class="mb-3">
            <label class="form-label" style="color:rgba(255,255,255,.7);">Dein Text <span style="color:#e8b84b;">*</span></label>
            <?php forumToolbar('perle-content'); ?>
            <textarea id="perle-content" name="content" rows="7" class="form-control"
                      style="border-top-left-radius:0;border-top-right-radius:0;"
                      placeholder="Warum ist dieser Film eine Filmperle für dich?" required><?= e($formPerle['content'] ?? '') ?></textarea>
            <div style="font-size:.73rem; color:rgba(255,255,255,.3); margin-top:.25rem;">
                Formatierung: [b]fett[/b] [i]kursiv[/i] [u]unterstrichen[/u] – max. 10.000 Zeichen
            </div>
        </div>

        <!-- Film-Cover Vorschau (automatisch vom verknüpften Film) -->
        <div id="film-poster-wrap" class="mb-3" style="<?= empty($formPerle['film_title']) ? 'display:none;' : '' ?>">
            <label class="form-label" style="color:rgba(255,255,255,.7);">Film-Cover <span style="color:rgba(255,255,255,.35); font-weight:400;">(automatisch vom verknüpften Film)</span></label>
            <div class="d-flex align-items-center gap-3">
                <img id="film-poster-preview" src="" alt=""
                     style="height:90px; border-radius:6px; object-fit:cover; display:<?= empty($formPerle['film_poster']) ? 'none' : 'block' ?>;"
                     <?php if (!empty($formPerle['film_poster'])): ?>
                     src="https://image.tmdb.org/t/p/w154<?= e($formPerle['film_poster']) ?>"
                     <?php endif; ?>>
                <div style="font-size:.8rem; color:rgba(255,255,255,.4);">
                    Das Cover wird direkt vom verknüpften Film übernommen.
                </div>
            </div>
        </div>

        <!-- Cover-Position -->
        <div class="mb-4" id="img-pos-wrap" style="<?= empty($formPerle['film_title']) ? 'display:none;' : '' ?>">
            <label class="form-label" style="color:rgba(255,255,255,.7);">Cover-Position</label>
            <div class="d-flex gap-3">
                <label style="cursor:pointer; color:rgba(255,255,255,.65);">
                    <input type="radio" name="image_position" value="right"
                           <?= ($formPerle['image_position'] ?? 'right') !== 'left' ? 'checked' : '' ?>>
                    Rechts
                </label>
                <label style="cursor:pointer; color:rgba(255,255,255,.65);">
                    <input type="radio" name="image_position" value="left"
                           <?= ($formPerle['image_position'] ?? '') === 'left' ? 'checked' : '' ?>>
                    Links
                </label>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-gold">
                <i class="bi bi-<?= $isEdit ? 'check-lg' : 'send' ?> me-1"></i>
                <?= $isEdit ? 'Änderungen speichern' : 'Filmperle veröffentlichen' ?>
            </button>
            <a href="/filmperlen.php<?= $isEdit ? '?id=' . (int)$editPerle['id'] : '' ?>" class="btn"
               style="background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); color:rgba(255,255,255,.6);">
                Abbrechen
            </a>
        </div>
    </form>
</div>
</div>
</div>
<script>
// Film-Suche
const fsInput   = document.getElementById('film-search');
const fsSuggest = document.getElementById('film-suggest');
const fsHidden  = document.getElementById('movie-id-field');
const fsSel     = document.getElementById('film-selected');
const fsName    = document.getElementById('film-selected-name');
const fsPoster  = document.getElementById('film-poster-preview');
const fsPosterW = document.getElementById('film-poster-wrap');
const fsImgPos  = document.getElementById('img-pos-wrap');
let fsTimer;
function insertBB(taId,open,close){const ta=document.getElementById(taId);if(!ta)return;const s=ta.selectionStart,e=ta.selectionEnd;const sel=ta.value.substring(s,e)||'Text';ta.value=ta.value.substring(0,s)+open+sel+close+ta.value.substring(e);ta.selectionStart=s+open.length;ta.selectionEnd=s+open.length+(e>s?e-s:sel.length);ta.focus();}
window.clearFilm = function(){
    fsHidden.value='0'; fsInput.value=''; fsSel.style.display='none';
    if(fsPosterW){ fsPosterW.style.display='none'; fsImgPos.style.display='none'; }
};
function selectFilm(f){
    fsHidden.value = f.id;
    fsInput.value  = f.title;
    fsName.textContent = f.title;
    fsSel.style.display = '';
    fsSuggest.style.display = 'none';
    if(fsPoster && f.poster_path){
        fsPoster.src = 'https://image.tmdb.org/t/p/w154' + f.poster_path;
        fsPoster.style.display = 'block';
        fsPosterW.style.display = '';
        fsImgPos.style.display  = '';
    }
}
fsInput.addEventListener('input', function(){
    clearTimeout(fsTimer);
    const q = this.value.trim();
    if(q.length < 2){ fsSuggest.style.display='none'; return; }
    fsTimer = setTimeout(()=>{
        fetch('/filmperlen.php?ajax=search_film&q='+encodeURIComponent(q))
            .then(r=>r.json()).then(films=>{
                fsSuggest.innerHTML = '';
                if(!films.length){ fsSuggest.innerHTML='<div style="padding:.5rem .8rem;color:rgba(255,255,255,.3);font-size:.83rem;">Keine Ergebnisse</div>'; }
                else { films.forEach(f=>{
                    const d = document.createElement('div'); d.className='film-sug-item';
                    const poster = f.poster_path ? 'https://image.tmdb.org/t/p/w45'+f.poster_path : '';
                    const label = f.title_en && f.title_en !== f.title ? f.title+' <span style="color:rgba(255,255,255,.35);font-size:.72rem;">('+f.title_en.replace(/</g,'&lt;')+')</span>' : f.title.replace(/</g,'&lt;');
                    d.innerHTML = (poster?`<img src="${poster}" alt="" style="width:24px;height:36px;object-fit:cover;border-radius:3px;">`:'<div style="width:24px;height:36px;background:rgba(255,255,255,.08);border-radius:3px;"></div>')
                        + `<span style="color:#e8b84b;font-size:.75rem;flex-shrink:0;">${f.year||''}</span>`
                        + `<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${label}</span>`;
                    d.addEventListener('click',()=>selectFilm(f));
                    fsSuggest.appendChild(d);
                }); }
                fsSuggest.style.display='block';
            }).catch(()=>{ fsSuggest.innerHTML='<div style="padding:.5rem .8rem;color:#f44336;font-size:.83rem;">Fehler bei der Suche</div>'; fsSuggest.style.display='block'; });
    }, 220);
});
document.addEventListener('click', e=>{ if(!fsInput.contains(e.target)&&!fsSuggest.contains(e.target)) fsSuggest.style.display='none'; });
</script>

<?php elseif ($perle !== null): ?>
<!-- ── Einzelne Perle ─────────────────────────────────────────────────────────── -->
<div class="row justify-content-center" id="top">
<div class="col-lg-8">
<article class="perle-card mb-4">
    <div class="perle-card-header">
        <div class="d-flex justify-content-between align-items-start gap-3">
            <div>
                <h2 class="fw-bold mb-1" style="color:#e8b84b; font-size:1.4rem;">
                    <?= e($perle['title']) ?>
                    <?php if ($perle['hidden']): ?><span class="hidden-badge">ausgeblendet</span><?php endif; ?>
                </h2>
                <div class="perle-meta">
                    <i class="bi bi-gem me-1" style="color:#a78bfa;"></i>
                    <a href="/filmperlen.php?user=<?= (int)$perle['user_id'] ?>" style="color:#e8b84b; text-decoration:none;"><?= e($perle['author_name']) ?></a>
                    &nbsp;·&nbsp; <i class="bi bi-calendar3 me-1"></i><?= date('d.m.Y H:i', strtotime($perle['created_at'])) ?>
                    <?php if ($perle['updated_at'] !== $perle['created_at']): ?>
                    &nbsp;·&nbsp; <i class="bi bi-pencil me-1"></i>bearbeitet <?= date('d.m.Y', strtotime($perle['updated_at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <?php if ($loggedIn && ($perle['user_id'] === $userId || $canMod)): ?>
                <a href="/filmperlen.php?edit=<?= (int)$perle['id'] ?>"
                   class="btn btn-sm" style="background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); color:rgba(255,255,255,.6); font-size:.78rem;">
                    <i class="bi bi-pencil me-1"></i>Bearbeiten
                </a>
                <?php endif; ?>
                <?php if ($canMod): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="toggle_hidden">
                    <input type="hidden" name="perle_id" value="<?= (int)$perle['id'] ?>">
                    <input type="hidden" name="from_id" value="<?= (int)$perle['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); color:rgba(255,255,255,.4); font-size:.78rem;">
                        <i class="bi bi-<?= $perle['hidden'] ? 'eye' : 'eye-slash' ?>"></i>
                    </button>
                </form>
                <?php endif; ?>
                <?php if ($loggedIn && ($perle['user_id'] === $userId || $canMod)): ?>
                <form method="post" onsubmit="return confirm('Filmperle wirklich löschen?');" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="perle_id" value="<?= (int)$perle['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:none; border:1px solid rgba(244,67,54,.3); color:rgba(244,67,54,.7); font-size:.78rem; padding:.25rem .5rem;">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div style="padding:1.25rem 1.5rem;">
        <?php if (!empty($perle['film_title'])): ?>
        <a href="/charts.php?film=<?= (int)$perle['movie_id'] ?>" class="film-badge" target="_blank">
            <?php if (!empty($perle['film_poster'])): ?>
            <img src="https://image.tmdb.org/t/p/w45<?= e($perle['film_poster']) ?>" alt=""
                 style="height:28px; border-radius:3px;" onerror="this.style.display='none'">
            <?php endif; ?>
            <span><?= e($perle['film_title']) ?><?= $perle['film_year'] ? ' (' . (int)$perle['film_year'] . ')' : '' ?></span>
            <i class="bi bi-box-arrow-up-right" style="font-size:.7rem; opacity:.6;"></i>
        </a>
        <?php endif; ?>

        <div style="margin-top:1rem;">
        <?php if (!empty($perle['film_poster'])): ?>
            <?php $imgLeft = $perle['image_position'] === 'left'; ?>
            <div class="d-flex gap-4 flex-column flex-md-row <?= $imgLeft ? '' : 'flex-md-row-reverse' ?>">
                <div style="flex-shrink:0;">
                    <img src="https://image.tmdb.org/t/p/w342<?= e($perle['film_poster']) ?>"
                         alt="<?= e($perle['film_title'] ?? '') ?>"
                         style="width:180px; border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,.5); display:block;"
                         onerror="this.style.display='none'">
                </div>
                <div style="flex:1; min-width:0; color:rgba(255,255,255,.82); line-height:1.72; font-size:.93rem;">
                    <?= renderForumBody($perle['content']) ?>
                </div>
            </div>
        <?php else: ?>
            <div style="color:rgba(255,255,255,.82); line-height:1.72; font-size:.93rem;">
                <?= renderForumBody($perle['content']) ?>
            </div>
        <?php endif; ?>
        </div>

        <!-- Like -->
        <div class="d-flex align-items-center gap-3 mt-3 pt-3" style="border-top:1px solid rgba(255,255,255,.06);">
            <?php if ($loggedIn): ?>
            <button id="like-btn" class="like-btn <?= $userLiked ? 'liked' : '' ?>"
                    onclick="toggleLike(<?= (int)$perle['id'] ?>)">
                <i class="bi bi-heart<?= $userLiked ? '-fill' : '' ?> me-1"></i>
                <span id="like-count"><?= (int)$perle['like_count'] ?></span>
            </button>
            <?php else: ?>
            <span class="like-btn"><i class="bi bi-heart me-1"></i><?= (int)$perle['like_count'] ?></span>
            <?php endif; ?>
            <span style="color:rgba(255,255,255,.3); font-size:.8rem;">
                <i class="bi bi-chat me-1"></i><?= count(array_filter($comments, fn($c) => !$c['hidden'])) ?> Kommentare
            </span>
        </div>
    </div>
</article>

<!-- Kommentare -->
<div id="kommentare">
    <h5 class="fw-bold mb-3" style="color:rgba(255,255,255,.8);">
        <i class="bi bi-chat-left-text me-2"></i>Kommentare
        <span style="color:rgba(255,255,255,.35); font-weight:400; font-size:.85rem;">(<?= count(array_filter($comments, fn($c) => !$c['hidden'])) ?>)</span>
    </h5>
    <?php foreach ($comments as $c):
        $isHid = (int)$c['hidden'] === 1;
        if ($isHid && !$canMod) continue;
    ?>
    <div class="comment-box <?= $isHid ? 'hidden-comment' : '' ?>">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <span class="comment-author"><i class="bi bi-person-circle me-1"></i><?= e($c['author_name']) ?></span>
                <span class="comment-date ms-2"><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></span>
                <?php if ($isHid): ?><span style="color:#f44336;font-size:.72rem;margin-left:.5rem;"><i class="bi bi-eye-slash me-1"></i>ausgeblendet</span><?php endif; ?>
            </div>
            <?php if ($canMod): ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token"  value="<?= csrfToken() ?>">
                <input type="hidden" name="action"      value="toggle_comment">
                <input type="hidden" name="comment_id"  value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="perle_id"    value="<?= $showId ?>">
                <button type="submit" class="btn btn-sm"
                        style="background:none;border:1px solid rgba(255,255,255,.1);color:rgba(255,255,255,.4);padding:.15rem .5rem;font-size:.72rem;">
                    <i class="bi bi-<?= $isHid ? 'eye' : 'eye-slash' ?>"></i> <?= $isHid ? 'Einblenden' : 'Ausblenden' ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
        <div class="comment-text"><?= nl2br(e($c['content'])) ?></div>
    </div>
    <?php endforeach; ?>

    <?php if ($loggedIn): ?>
    <div style="background:rgba(232,184,75,.04); border:1px solid rgba(232,184,75,.1); border-radius:12px; padding:1.5rem; margin-top:1.5rem;">
        <h6 class="fw-bold mb-3" style="color:#e8b84b;"><i class="bi bi-pencil me-1"></i>Kommentar schreiben</h6>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action"     value="comment">
            <input type="hidden" name="perle_id"   value="<?= $showId ?>">
            <div class="mb-3">
                <textarea name="content" class="form-control" rows="4"
                          placeholder="Dein Kommentar…" required></textarea>
            </div>
            <button type="submit" class="btn btn-gold"><i class="bi bi-send me-1"></i>Kommentar posten</button>
        </form>
    </div>
    <?php else: ?>
    <div style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.07); border-radius:10px; padding:1.2rem; text-align:center; margin-top:1.5rem;">
        <p style="color:rgba(255,255,255,.4); margin:0; font-size:.9rem;">
            <a href="/login.php" style="color:#e8b84b;">Melde dich an</a>, um einen Kommentar zu schreiben.
        </p>
    </div>
    <?php endif; ?>
</div>
</div>
</div>
<script>
function toggleLike(id){
    const btn=document.getElementById('like-btn');
    const cnt=document.getElementById('like-count');
    fetch('/filmperlen.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=like&perle_id='+id+'&csrf_token='+encodeURIComponent(<?= json_encode(csrfToken()) ?>)})
    .then(r=>r.json()).then(d=>{
        cnt.textContent=d.count;
        btn.classList.toggle('liked',d.liked);
        btn.querySelector('i').className='bi bi-heart'+(d.liked?'-fill':'')+' me-1';
    });
}
</script>

<?php else: ?>
<!-- ── Liste ──────────────────────────────────────────────────────────────────── -->
<?php if ($filterUser > 0 && $filterUsername): ?>
<div class="mb-4 d-flex align-items-center gap-3">
    <h5 class="fw-bold mb-0" style="color:rgba(255,255,255,.8);">
        <i class="bi bi-person-circle me-2" style="color:#e8b84b;"></i>
        Filmperlen von <?= e($filterUsername) ?>
    </h5>
    <a href="/filmperlen.php" class="btn btn-sm" style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:rgba(255,255,255,.5);font-size:.78rem;">
        Alle anzeigen
    </a>
</div>
<?php endif; ?>

<?php if (empty($perlen)): ?>
<div style="text-align:center; padding:4rem 0;">
    <div style="font-size:3rem; margin-bottom:1rem; opacity:.3;"><i class="bi bi-gem"></i></div>
    <p style="color:rgba(255,255,255,.35);">
        <?= $filterUser ? 'Dieser Nutzer hat noch keine Filmperlen geteilt.' : 'Noch keine Filmperlen vorhanden.' ?>
    </p>
    <?php if ($canPost): ?>
    <a href="/filmperlen.php?new=1" class="btn btn-gold mt-2">
        <i class="bi bi-plus-lg me-1"></i>Erste Filmperle teilen
    </a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="d-flex flex-column gap-3">
<?php foreach ($perlen as $p):
    $excerpt = mb_substr(strip_tags(renderForumBody($p['content'])), 0, 300);
    if (mb_strlen($p['content']) > 300) $excerpt .= '…';
?>
<div class="perle-card" style="<?= $p['hidden'] ? 'opacity:.55;' : '' ?>">
    <div class="d-flex align-items-stretch gap-0">

        <!-- Poster -->
        <a href="/filmperlen.php?id=<?= (int)$p['id'] ?>" style="flex-shrink:0; display:block; width:130px;">
            <?php if (!empty($p['film_poster'])): ?>
            <img src="https://image.tmdb.org/t/p/w185<?= e($p['film_poster']) ?>"
                 alt="<?= e($p['film_title']) ?>"
                 style="width:130px; height:195px; object-fit:cover; border-radius:10px 0 0 10px; display:block;"
                 onerror="this.src='https://placehold.co/130x195/1e3a5f/e8b84b?text=?'">
            <?php else: ?>
            <div style="width:130px; height:195px; border-radius:10px 0 0 10px; background:rgba(255,255,255,.04); display:flex; align-items:center; justify-content:center; color:rgba(232,184,75,.3); font-size:2rem;">
                <i class="bi bi-film"></i>
            </div>
            <?php endif; ?>
        </a>

        <!-- Content -->
        <div style="flex:1; min-width:0; padding:1rem 1.25rem; display:flex; flex-direction:column; gap:.4rem;">

            <!-- Top row: title + meta + badge -->
            <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                <div>
                    <h5 class="perle-title fw-bold mb-1" style="font-size:1rem;">
                        <a href="/filmperlen.php?id=<?= (int)$p['id'] ?>"><?= e($p['title']) ?></a>
                        <?php if ($p['hidden']): ?><span class="hidden-badge">ausgeblendet</span><?php endif; ?>
                    </h5>
                    <div class="perle-meta" style="margin:0;">
                        <a href="/filmperlen.php?user=<?= (int)$p['user_id'] ?>" style="color:inherit;text-decoration:none;">
                            <i class="bi bi-gem me-1" style="color:#a78bfa;"></i><?= e($p['author_name']) ?>
                        </a>
                        &nbsp;·&nbsp; <?= date('d.m.Y', strtotime($p['created_at'])) ?>
                    </div>
                </div>
                <?php if (!empty($p['film_title'])): ?>
                <div class="film-badge" style="white-space:nowrap; flex-shrink:0;">
                    <i class="bi bi-film"></i><?= e($p['film_title']) ?><?= $p['film_year'] ? ' ('.(int)$p['film_year'].')' : '' ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Excerpt -->
            <p class="perle-excerpt mb-0" style="flex:1;"><?= e($excerpt) ?></p>

            <!-- Footer -->
            <div class="d-flex align-items-center justify-content-between"
                 style="font-size:.78rem; color:rgba(255,255,255,.35); padding-top:.4rem; border-top:1px solid rgba(255,255,255,.06);">
                <span>
                    <i class="bi bi-heart me-1"></i><?= (int)$p['like_count'] ?>
                    &nbsp; <i class="bi bi-chat me-1"></i><?= (int)$p['comment_count'] ?>
                </span>
                <a href="/filmperlen.php?id=<?= (int)$p['id'] ?>" style="color:#e8b84b; text-decoration:none; font-size:.8rem;">
                    Lesen <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

</div>
</section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
