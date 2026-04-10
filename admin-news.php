<?php
$pageTitle = 'News-Verwaltung – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if (!isAdmin()) {
    header('Location: /index.php');
    exit;
}

$db        = getDB();
$currentId = (int)$_SESSION['user_id'];
$msg       = '';
$msgType   = 'success';

// Tabellen sicherstellen (falls news.php noch nicht aufgerufen wurde)
$db->exec("CREATE TABLE IF NOT EXISTS news_posts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title          VARCHAR(255) NOT NULL,
    content        TEXT NOT NULL,
    author_id      INT UNSIGNED NOT NULL,
    image_path     VARCHAR(500) DEFAULT NULL,
    image_position ENUM('left','right') NOT NULL DEFAULT 'right',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Spalten nachrüsten falls Tabelle schon existiert (Migration)
try {
    $db->exec("ALTER TABLE news_posts ADD COLUMN image_path VARCHAR(500) DEFAULT NULL");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE news_posts ADD COLUMN image_position ENUM('left','right') NOT NULL DEFAULT 'right'");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE news_posts ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
    $existing = $db->query("SELECT id FROM news_posts ORDER BY created_at DESC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($existing as $i => $id) {
        $db->prepare("UPDATE news_posts SET sort_order=? WHERE id=?")->execute([$i + 1, $id]);
    }
} catch (PDOException $e) {}
try { $db->exec("ALTER TABLE news_posts ADD COLUMN layout ENUM('small','half','wide') NOT NULL DEFAULT 'small'"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE news_posts MODIFY COLUMN layout ENUM('small','half','wide') NOT NULL DEFAULT 'small'"); } catch (PDOException $e) {}
// Pfad-Migration: /filmdb/uploads/… → /uploads/…
try { $db->exec("UPDATE news_posts SET image_path = REPLACE(image_path, '/filmdb/uploads/', '/uploads/') WHERE image_path LIKE '/filmdb/uploads/%'"); } catch (PDOException $e) {}

$db->exec("CREATE TABLE IF NOT EXISTS news_comments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    content    TEXT NOT NULL,
    hidden     TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_post (post_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Bild-Upload-Helper ────────────────────────────────────────────────────────
function handleNewsImageUpload(): ?string {
    if (empty($_FILES['image']['name'])) return null;
    $file   = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    $mime   = '';
    try {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = (string)finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    } catch (\Throwable $e) {}
    if ($mime === '') {
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'][$ext] ?? '';
    }
    if (!isset($extMap[$mime])) return null;
    $name = 'news_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
    $dest = __DIR__ . '/uploads/news/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    return '/uploads/news/' . $name;
}

// ── POST-Handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) {
        // Session abgelaufen oder Token ungültig – Token erneuern und Meldung zeigen
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $msg     = 'Sitzung abgelaufen. Bitte nochmals speichern.';
        $msgType = 'warning';
        if (isset($_GET['edit'])) {
            header('Location: /admin-news.php?edit=' . (int)$_GET['edit'] . '&msg=' . urlencode($msg) . '&type=' . $msgType);
        } else {
            header('Location: /admin-news.php?msg=' . urlencode($msg) . '&type=' . $msgType);
        }
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title    = trim($_POST['title']            ?? '');
        $content  = trim($_POST['content']          ?? '');
        $imgPos   = $_POST['image_position'] === 'left' ? 'left' : 'right';
        $layout   = in_array($_POST['layout'] ?? '', ['small','half','wide']) ? $_POST['layout'] : 'small';
        $imgPath  = handleNewsImageUpload();
        if ($title !== '' && $content !== '') {
            $minOrder = (int)$db->query("SELECT COALESCE(MIN(sort_order), 1) FROM news_posts")->fetchColumn();
            $newOrder = $minOrder - 1;
            $db->prepare("INSERT INTO news_posts (title, content, author_id, image_path, image_position, layout, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$title, $content, $currentId, $imgPath, $imgPos, $layout, $newOrder]);
            $msg = 'Beitrag erstellt.';
        } else {
            $msg = 'Titel und Inhalt sind erforderlich.';
            $msgType = 'danger';
        }
        header('Location: /admin-news.php?msg=' . urlencode($msg) . '&type=' . $msgType);
        exit;
    }

    if ($action === 'update') {
        $postId  = (int)($_POST['post_id']  ?? 0);
        $title   = trim($_POST['title']     ?? '');
        $content = trim($_POST['content']   ?? '');
        $imgPos  = $_POST['image_position'] === 'left' ? 'left' : 'right';
        $layout  = $_POST['layout'] === 'wide' ? 'wide' : 'small';
        if ($postId > 0 && $title !== '' && $content !== '') {
            $newImg = handleNewsImageUpload();
            if ($newImg !== null) {
                $old = $db->prepare("SELECT image_path FROM news_posts WHERE id=?");
                $old->execute([$postId]);
                $oldPath = $old->fetchColumn();
                if ($oldPath) @unlink(__DIR__ . '/' . ltrim($oldPath, '/'));
                $db->prepare("UPDATE news_posts SET title=?, content=?, image_path=?, image_position=?, layout=? WHERE id=?")
                   ->execute([$title, $content, $newImg, $imgPos, $layout, $postId]);
            } else {
                $db->prepare("UPDATE news_posts SET title=?, content=?, image_position=?, layout=? WHERE id=?")
                   ->execute([$title, $content, $imgPos, $layout, $postId]);
            }
            $msg = 'Beitrag aktualisiert.';
        }
        header('Location: /admin-news.php?edit=' . $postId . '&msg=' . urlencode($msg) . '&type=success');
        exit;
    }

    if ($action === 'remove_image') {
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId > 0) {
            $old = $db->prepare("SELECT image_path FROM news_posts WHERE id=?");
            $old->execute([$postId]);
            $oldPath = $old->fetchColumn();
            if ($oldPath) @unlink(__DIR__ . '/' . ltrim($oldPath, '/'));
            $db->prepare("UPDATE news_posts SET image_path=NULL WHERE id=?")->execute([$postId]);
            $msg = 'Bild entfernt.';
        }
        header('Location: /admin-news.php?edit=' . $postId . '&msg=' . urlencode($msg) . '&type=success');
        exit;
    }

    if ($action === 'delete') {
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId > 0) {
            $old = $db->prepare("SELECT image_path FROM news_posts WHERE id=?");
            $old->execute([$postId]);
            $oldPath = $old->fetchColumn();
            if ($oldPath) @unlink(__DIR__ . '/' . ltrim($oldPath, '/'));
            $db->prepare("DELETE FROM news_comments WHERE post_id = ?")->execute([$postId]);
            $db->prepare("DELETE FROM news_posts WHERE id = ?")->execute([$postId]);
            $msg = 'Beitrag gelöscht.';
        }
        header('Location: /admin-news.php?msg=' . urlencode($msg) . '&type=success');
        exit;
    }

    if ($action === 'move') {
        $postId    = (int)($_POST['post_id'] ?? 0);
        $direction = $_POST['direction'] ?? '';
        if ($postId > 0 && in_array($direction, ['up', 'down'], true)) {
            // Aktuellen sort_order laden
            $cur = $db->prepare("SELECT sort_order FROM news_posts WHERE id=?");
            $cur->execute([$postId]);
            $curOrder = (int)$cur->fetchColumn();

            // Nachbar-Post finden
            if ($direction === 'up') {
                $nbStmt = $db->prepare("SELECT id, sort_order FROM news_posts WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1");
            } else {
                $nbStmt = $db->prepare("SELECT id, sort_order FROM news_posts WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1");
            }
            $nbStmt->execute([$curOrder]);
            $nb = $nbStmt->fetch(PDO::FETCH_ASSOC);

            if ($nb) {
                // sort_order-Werte tauschen
                $db->prepare("UPDATE news_posts SET sort_order=? WHERE id=?")->execute([$nb['sort_order'], $postId]);
                $db->prepare("UPDATE news_posts SET sort_order=? WHERE id=?")->execute([$curOrder, $nb['id']]);
            }
        }
        header('Location: /admin-news.php');
        exit;
    }

    if ($action === 'toggle_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        if ($commentId > 0) {
            $db->prepare("UPDATE news_comments SET hidden = 1 - hidden WHERE id = ?")
               ->execute([$commentId]);
        }
        $postId = (int)($_POST['post_id'] ?? 0);
        header('Location: /admin-news.php?edit=' . $postId . '#kommentare');
        exit;
    }

    if ($action === 'delete_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        if ($commentId > 0) {
            $db->prepare("DELETE FROM news_comments WHERE id = ?")->execute([$commentId]);
        }
        $postId = (int)($_POST['post_id'] ?? 0);
        header('Location: /admin-news.php?edit=' . $postId . '#kommentare');
        exit;
    }
}

// Flash-Message aus Redirect
if (isset($_GET['msg'])) {
    $msg     = e($_GET['msg']);
    $msgType = in_array($_GET['type'] ?? '', ['success','danger','warning']) ? $_GET['type'] : 'success';
}

// ── Modus ─────────────────────────────────────────────────────────────────────
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editPost = null;
$editComments = [];

if ($editId > 0) {
    $eStmt = $db->prepare("SELECT * FROM news_posts WHERE id = ?");
    $eStmt->execute([$editId]);
    $editPost = $eStmt->fetch(PDO::FETCH_ASSOC);

    if ($editPost) {
        $cStmt = $db->prepare(
            "SELECT c.*, u.username FROM news_comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.post_id = ? ORDER BY c.created_at ASC"
        );
        $cStmt->execute([$editId]);
        $editComments = $cStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Posts laden ───────────────────────────────────────────────────────────────
$postsStmt = $db->query(
    "SELECT n.*, u.username AS author_name,
            (SELECT COUNT(*) FROM news_comments c WHERE c.post_id = n.id) AS total_comments,
            (SELECT COUNT(*) FROM news_comments c WHERE c.post_id = n.id AND c.hidden = 0) AS visible_comments
     FROM news_posts n
     JOIN users u ON u.id = n.author_id
     ORDER BY n.sort_order ASC, n.created_at DESC"
);
$posts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body { background:#14325a !important; }
    .admin-card {
        background: rgba(255,255,255,.04);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 12px;
        overflow: hidden;
    }
    .post-row {
        padding: .9rem 1.2rem;
        border-bottom: 1px solid rgba(255,255,255,.05);
        transition: background .15s;
    }
    .post-row:hover { background: rgba(255,255,255,.03); }
    .post-row:last-child { border-bottom: none; }
    .comment-row {
        background: rgba(255,255,255,.03);
        border: 1px solid rgba(255,255,255,.06);
        border-radius: 8px;
        padding: .75rem 1rem;
        margin-bottom: .5rem;
    }
    .comment-row.hidden-comment { opacity:.5; border-color:rgba(244,67,54,.2); background:rgba(244,67,54,.03); }
    .form-control, .form-control:focus {
        background: rgba(255,255,255,.05) !important;
        border: 1px solid rgba(255,255,255,.12) !important;
        color: #e0e0e0 !important;
    }
    .form-control:focus {
        border-color: rgba(232,184,75,.4) !important;
        box-shadow: 0 0 0 .2rem rgba(232,184,75,.1) !important;
    }
    .btn-gold { background:#e8b84b; color:#1a1a1a; border:none; font-weight:700; }
    .btn-gold:hover { background:#f0ca6a; color:#1a1a1a; }
    label { color: rgba(255,255,255,.6); font-size: .85rem; }
</style>

<main style="padding-top:6px; background:#14325a; flex:1;">

    <!-- Header -->
    <section class="py-4" style="background:linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom:1px solid rgba(232,184,75,.15);">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="fw-bold mb-0" style="color:#e8b84b; font-size:1.8rem;">
                        <i class="bi bi-newspaper me-2"></i>News-Verwaltung
                    </h1>
                </div>
                <div class="col-auto">
                    <span style="color:rgba(255,255,255,.4); font-size:.85rem;"><?= count($posts) ?> Beitrag<?= count($posts) !== 1 ? 'e' : '' ?></span>
                </div>
            </div>
        </div>
    </section>

    <!-- Admin-Sub-Nav -->
    <div style="background:#14325a; border-bottom:1px solid rgba(255,255,255,.06);">
        <div class="container">
            <div class="d-flex gap-4 py-2">
                <a href="/admin-benutzer.php"
                   style="color:rgba(255,255,255,.5); text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid transparent;">
                    <i class="bi bi-people me-1"></i>Benutzer
                </a>
                <a href="/admin-statistiken.php"
                   style="color:rgba(255,255,255,.5); text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid transparent;">
                    <i class="bi bi-bar-chart me-1"></i>Statistiken
                </a>
                <a href="/admin-news.php"
                   style="color:#e8b84b; text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid #e8b84b; font-weight:600;">
                    <i class="bi bi-newspaper me-1"></i>News
                </a>
                <a href="/admin-projekt.php"
                   style="color:rgba(255,255,255,.5); text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid transparent;">
                    <i class="bi bi-layers me-1"></i>Projekt
                </a>
            </div>
        </div>
    </div>

    <section class="py-4" style="background:#14325a;">
        <div class="container">

            <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible py-2 mb-4" role="alert">
                <?= $msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($editPost): ?>
            <!-- ── Bearbeitungs-Ansicht ─────────────────────────────────────── -->
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="admin-card">
                        <div style="padding:1.2rem 1.5rem; border-bottom:1px solid rgba(255,255,255,.07);">
                            <h5 class="fw-bold mb-0" style="color:#e8b84b;">
                                <i class="bi bi-pencil me-1"></i>Beitrag bearbeiten
                            </h5>
                        </div>
                        <div style="padding:1.5rem;">
                            <!-- Bild-entfernen-Formular außerhalb des Haupt-Formulars (kein Nesting erlaubt) -->
                            <form id="remove-img-form" method="post"
                                  onsubmit="return confirm('Bild entfernen?')">
                                <input type="hidden" name="action"     value="remove_image">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="post_id"    value="<?= (int)$editPost['id'] ?>">
                            </form>

                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="post_id" value="<?= (int)$editPost['id'] ?>">
                                <div class="mb-3">
                                    <label class="form-label">Titel</label>
                                    <input type="text" name="title" class="form-control"
                                           value="<?= e($editPost['title']) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Inhalt</label>
                                    <textarea name="content" class="form-control" rows="9" required><?= e($editPost['content']) ?></textarea>
                                </div>

                                <!-- Aktuelles Bild -->
                                <?php if (!empty($editPost['image_path'])): ?>
                                <div class="mb-3" style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.08); border-radius:8px; padding:.8rem;">
                                    <label class="form-label">Aktuelles Bild</label>
                                    <div class="d-flex align-items-center gap-3">
                                        <img src="<?= e($editPost['image_path']) ?>" alt=""
                                             style="height:70px; width:auto; border-radius:5px; object-fit:cover;">
                                        <div class="flex-grow-1">
                                            <div style="color:rgba(255,255,255,.4); font-size:.75rem; word-break:break-all;"><?= e(basename($editPost['image_path'])) ?></div>
                                        </div>
                                        <button type="submit" form="remove-img-form" class="btn btn-sm"
                                                style="background:rgba(244,67,54,.1); border:1px solid rgba(244,67,54,.25); color:rgba(244,67,54,.8); font-size:.75rem; padding:.2rem .6rem;">
                                            <i class="bi bi-x-lg"></i> Entfernen
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label">
                                        <?= !empty($editPost['image_path']) ? 'Bild ersetzen' : 'Bild hinzufügen' ?>
                                        <span style="color:rgba(255,255,255,.3); font-weight:400;">(JPG/PNG/GIF/WebP)</span>
                                    </label>
                                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Bild-Position</label>
                                    <div class="d-flex gap-3">
                                        <label style="color:rgba(255,255,255,.65); font-size:.88rem; cursor:pointer;">
                                            <input type="radio" name="image_position" value="left"
                                                   <?= ($editPost['image_position'] ?? 'right') === 'left' ? 'checked' : '' ?>
                                                   style="accent-color:#e8b84b;"> Links
                                        </label>
                                        <label style="color:rgba(255,255,255,.65); font-size:.88rem; cursor:pointer;">
                                            <input type="radio" name="image_position" value="right"
                                                   <?= ($editPost['image_position'] ?? 'right') === 'right' ? 'checked' : '' ?>
                                                   style="accent-color:#e8b84b;"> Rechts
                                        </label>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label">Darstellung</label>
                                    <div class="d-flex gap-3 flex-wrap">
                                        <label style="color:rgba(255,255,255,.65); font-size:.88rem; cursor:pointer;">
                                            <input type="radio" name="layout" value="small"
                                                   <?= ($editPost['layout'] ?? 'small') === 'small' ? 'checked' : '' ?>
                                                   style="accent-color:#e8b84b;">
                                            <i class="bi bi-grid-3x2-gap ms-1 me-1" style="color:rgba(255,255,255,.4);"></i>Klein (3 pro Zeile)
                                        </label>
                                        <label style="color:rgba(255,255,255,.65); font-size:.88rem; cursor:pointer;">
                                            <input type="radio" name="layout" value="half"
                                                   <?= ($editPost['layout'] ?? 'small') === 'half' ? 'checked' : '' ?>
                                                   style="accent-color:#e8b84b;">
                                            <i class="bi bi-layout-split ms-1 me-1" style="color:#5b9bd5;"></i>Mittel (2 pro Zeile)
                                        </label>
                                        <label style="color:rgba(255,255,255,.65); font-size:.88rem; cursor:pointer;">
                                            <input type="radio" name="layout" value="wide"
                                                   <?= ($editPost['layout'] ?? 'small') === 'wide' ? 'checked' : '' ?>
                                                   style="accent-color:#e8b84b;">
                                            <i class="bi bi-layout-text-window ms-1 me-1" style="color:#e8b84b;"></i>Volle Breite
                                        </label>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-gold">
                                        <i class="bi bi-check-lg me-1"></i>Speichern
                                    </button>
                                    <a href="/admin-news.php" class="btn btn-outline-secondary">Abbrechen</a>
                                    <a href="/news.php?post=<?= (int)$editPost['id'] ?>" target="_blank"
                                       class="btn btn-outline-light btn-sm ms-auto" style="align-self:center;">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Vorschau
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="admin-card" id="kommentare">
                        <div style="padding:1rem 1.2rem; border-bottom:1px solid rgba(255,255,255,.07); display:flex; justify-content:space-between; align-items:center;">
                            <h6 class="fw-bold mb-0" style="color:rgba(255,255,255,.8);">
                                <i class="bi bi-chat-left-text me-1"></i>Kommentare
                                <span style="color:rgba(255,255,255,.4); font-weight:400;">(<?= count($editComments) ?>)</span>
                            </h6>
                        </div>
                        <div style="padding:1rem; max-height:550px; overflow-y:auto;">
                        <?php if (empty($editComments)): ?>
                            <div style="color:rgba(255,255,255,.3); font-size:.85rem; text-align:center; padding:2rem 0;">
                                Noch keine Kommentare
                            </div>
                        <?php else: ?>
                            <?php foreach ($editComments as $c): ?>
                            <?php $hidden = (int)$c['hidden'] === 1; ?>
                            <div class="comment-row <?= $hidden ? 'hidden-comment' : '' ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <div>
                                        <span style="color:#e8b84b; font-size:.82rem; font-weight:600;"><?= e($c['username']) ?></span>
                                        <span style="color:rgba(255,255,255,.3); font-size:.72rem; margin-left:.5rem;">
                                            <?= date('d.m.Y H:i', strtotime($c['created_at'])) ?>
                                        </span>
                                        <?php if ($hidden): ?>
                                        <span style="color:#f44336; font-size:.7rem; margin-left:.4rem;"><i class="bi bi-eye-slash"></i> ausgeblendet</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <!-- Ausblenden/Einblenden -->
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_comment">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                                            <input type="hidden" name="post_id" value="<?= $editId ?>">
                                            <button type="submit" class="btn btn-sm"
                                                    style="background:none; border:1px solid rgba(255,255,255,.12); color:rgba(255,255,255,.5); padding:.1rem .4rem; font-size:.7rem;"
                                                    title="<?= $hidden ? 'Einblenden' : 'Ausblenden' ?>">
                                                <i class="bi bi-<?= $hidden ? 'eye' : 'eye-slash' ?>"></i>
                                            </button>
                                        </form>
                                        <!-- Löschen -->
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Kommentar löschen?')">
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                                            <input type="hidden" name="post_id" value="<?= $editId ?>">
                                            <button type="submit" class="btn btn-sm"
                                                    style="background:none; border:1px solid rgba(244,67,54,.3); color:rgba(244,67,54,.7); padding:.1rem .4rem; font-size:.7rem;"
                                                    title="Löschen">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div style="color:rgba(255,255,255,.65); font-size:.82rem; line-height:1.5;">
                                    <?= nl2br(e($c['content'])) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- ── Übersicht + Neuer Beitrag ───────────────────────────────── -->
            <div class="row g-4">

                <!-- Neuer Beitrag -->
                <div class="col-lg-5">
                    <div class="admin-card">
                        <div style="padding:1.2rem 1.5rem; border-bottom:1px solid rgba(255,255,255,.07);">
                            <h5 class="fw-bold mb-0" style="color:#e8b84b;">
                                <i class="bi bi-plus-circle me-1"></i>Neuer Beitrag
                            </h5>
                        </div>
                        <div style="padding:1.5rem;">
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="create">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <div class="mb-3">
                                    <label class="form-label">Titel</label>
                                    <input type="text" name="title" class="form-control" placeholder="Titel des Beitrags" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Inhalt</label>
                                    <textarea name="content" class="form-control" rows="8"
                                              placeholder="Inhalt des Beitrags..." required></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Bild <span style="color:rgba(255,255,255,.3); font-weight:400;">(optional · JPG/PNG/GIF/WebP)</span></label>
                                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Bild-Position</label>
                                    <div class="d-flex gap-3">
                                        <label style="color:rgba(255,255,255,.65); font-size:.88rem; cursor:pointer;">
                                            <input type="radio" name="image_position" value="left" style="accent-color:#e8b84b;"> Links
                                        </label>
                                        <label style="color:rgba(255,255,255,.65); font-size:.88rem; cursor:pointer;">
                                            <input type="radio" name="image_position" value="right" checked style="accent-color:#e8b84b;"> Rechts
                                        </label>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label">Darstellung</label>
                                    <div class="d-flex gap-3 flex-wrap">
                                        <label style="color:rgba(255,255,255,.65); font-size:.88rem; cursor:pointer;">
                                            <input type="radio" name="layout" value="small" checked style="accent-color:#e8b84b;">
                                            <i class="bi bi-grid-3x2-gap ms-1 me-1" style="color:rgba(255,255,255,.4);"></i>Klein (3 pro Zeile)
                                        </label>
                                        <label style="color:rgba(255,255,255,.65); font-size:.88rem; cursor:pointer;">
                                            <input type="radio" name="layout" value="half" style="accent-color:#e8b84b;">
                                            <i class="bi bi-layout-split ms-1 me-1" style="color:#5b9bd5;"></i>Mittel (2 pro Zeile)
                                        </label>
                                        <label style="color:rgba(255,255,255,.65); font-size:.88rem; cursor:pointer;">
                                            <input type="radio" name="layout" value="wide" style="accent-color:#e8b84b;">
                                            <i class="bi bi-layout-text-window ms-1 me-1" style="color:#e8b84b;"></i>Volle Breite
                                        </label>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-gold w-100">
                                    <i class="bi bi-send me-1"></i>Beitrag veröffentlichen
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Beitrags-Liste -->
                <div class="col-lg-7">
                    <div class="admin-card">
                        <div style="padding:1rem 1.2rem; border-bottom:1px solid rgba(255,255,255,.07);">
                            <h5 class="fw-bold mb-0" style="color:rgba(255,255,255,.8); font-size:1rem;">
                                Alle Beiträge
                            </h5>
                        </div>
                        <?php if (empty($posts)): ?>
                        <div style="padding:3rem; text-align:center; color:rgba(255,255,255,.3);">
                            <i class="bi bi-newspaper" style="font-size:2rem;"></i>
                            <p class="mt-2 mb-0">Noch keine Beiträge vorhanden</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($posts as $i => $p): ?>
                        <div class="post-row">
                            <div class="d-flex align-items-center gap-2">
                                <!-- Sortier-Buttons -->
                                <div class="d-flex flex-column gap-1" style="flex-shrink:0;">
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="move">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="btn btn-sm"
                                                style="background:none; border:1px solid rgba(255,255,255,.1); color:rgba(255,255,255,<?= $i === 0 ? '.2' : '.5' ?>); padding:.1rem .35rem; font-size:.7rem; line-height:1;"
                                                <?= $i === 0 ? 'disabled' : '' ?>>
                                            <i class="bi bi-chevron-up"></i>
                                        </button>
                                    </form>
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="move">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="btn btn-sm"
                                                style="background:none; border:1px solid rgba(255,255,255,.1); color:rgba(255,255,255,<?= $i === count($posts)-1 ? '.2' : '.5' ?>); padding:.1rem .35rem; font-size:.7rem; line-height:1;"
                                                <?= $i === count($posts)-1 ? 'disabled' : '' ?>>
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </form>
                                </div>
                                <!-- Info -->
                                <div style="flex:1; min-width:0;">
                                    <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.92rem;">
                                        <?= e($p['title']) ?>
                                    </div>
                                    <div style="color:rgba(255,255,255,.35); font-size:.75rem; margin-top:.15rem;">
                                        <i class="bi bi-person me-1"></i><?= e($p['author_name']) ?>
                                        &nbsp;·&nbsp;
                                        <i class="bi bi-calendar3 me-1"></i><?= date('d.m.Y H:i', strtotime($p['created_at'])) ?>
                                        &nbsp;·&nbsp;
                                        <i class="bi bi-chat me-1"></i><?= (int)$p['visible_comments'] ?><?php if ($p['total_comments'] > $p['visible_comments']): ?> <span style="color:rgba(244,67,54,.6);">(+<?= (int)$p['total_comments'] - (int)$p['visible_comments'] ?> ausgeblendet)</span><?php endif; ?>
                                    </div>
                                </div>
                                <!-- Aktionen -->
                                <div class="d-flex gap-1 flex-shrink-0">
                                    <a href="/admin-news.php?edit=<?= (int)$p['id'] ?>"
                                       class="btn btn-sm"
                                       style="background:rgba(232,184,75,.1); border:1px solid rgba(232,184,75,.2); color:#e8b84b; padding:.25rem .6rem; font-size:.78rem;">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="/news.php?post=<?= (int)$p['id'] ?>" target="_blank"
                                       class="btn btn-sm"
                                       style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:rgba(255,255,255,.5); padding:.25rem .6rem; font-size:.78rem;">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Beitrag und alle Kommentare löschen?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm"
                                                style="background:rgba(244,67,54,.1); border:1px solid rgba(244,67,54,.2); color:rgba(244,67,54,.8); padding:.25rem .6rem; font-size:.78rem;">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
            <?php endif; ?>

        </div>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
