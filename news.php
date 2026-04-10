<?php
$pageTitle       = 'News – MKFB';
$pageDescription = 'Aktuelle News, Updates und Neuigkeiten rund um MKFB – Markus Kogler\'s Filmbewertungen. Neue Features, Filmtipps und Projektfortschritte.';
require_once __DIR__ . '/includes/functions.php';
startSession();

$db      = getDB();
$loggedIn = isLoggedIn();
$userId  = $loggedIn ? (int)$_SESSION['user_id'] : 0;
$canMod  = $loggedIn && canModerate();

// ── Tabellen erstellen ────────────────────────────────────────────────────────
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
try { $db->exec("ALTER TABLE news_posts ADD COLUMN image_path VARCHAR(500) DEFAULT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE news_posts ADD COLUMN image_position ENUM('left','right') NOT NULL DEFAULT 'right'"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE news_posts ADD COLUMN sort_order INT NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
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

// ── POST-Handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loggedIn && csrfValid()) {
    $action = $_POST['action'] ?? '';

    // Kommentar posten
    if ($action === 'comment') {
        $postId  = (int)($_POST['post_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if ($postId > 0 && $content !== '') {
            $db->prepare("INSERT INTO news_comments (post_id, user_id, content) VALUES (?, ?, ?)")
               ->execute([$postId, $userId, $content]);
        }
        header('Location: /news.php?post=' . $postId . '#kommentare');
        exit;
    }

    // Kommentar ausblenden / einblenden (Admin/Moderator)
    if ($action === 'toggle_comment' && $canMod) {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        if ($commentId > 0) {
            $db->prepare("UPDATE news_comments SET hidden = 1 - hidden WHERE id = ?")
               ->execute([$commentId]);
        }
        $postId = (int)($_POST['post_id'] ?? 0);
        header('Location: /news.php?post=' . $postId . '#kommentare');
        exit;
    }
}

// ── Einzelnen Post anzeigen oder Liste ───────────────────────────────────────
$showPostId = isset($_GET['post']) ? (int)$_GET['post'] : 0;

if ($showPostId > 0) {
    // Einzelner Post
    $pStmt = $db->prepare(
        "SELECT n.*, u.username AS author_name
         FROM news_posts n
         JOIN users u ON u.id = n.author_id
         WHERE n.id = ?"
    );
    $pStmt->execute([$showPostId]);
    $post = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$post) {
        header('Location: /news.php');
        exit;
    }

    // Kommentare laden
    $cStmt = $db->prepare(
        "SELECT c.*, u.username AS author_name
         FROM news_comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.post_id = ?
         ORDER BY c.created_at ASC"
    );
    $cStmt->execute([$showPostId]);
    $comments = $cStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Alle Posts
    $postsStmt = $db->query(
        "SELECT n.*, u.username AS author_name,
                (SELECT COUNT(*) FROM news_comments c WHERE c.post_id = n.id AND c.hidden = 0) AS comment_count
         FROM news_posts n
         JOIN users u ON u.id = n.author_id
         ORDER BY n.sort_order ASC, n.created_at DESC"
    );
    $posts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body { background:#14325a !important; }
    .news-card {
        background: rgba(255,255,255,.04);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 14px;
        transition: border-color .2s, transform .2s;
        overflow: hidden;
    }
    .news-card:hover { border-color: rgba(232,184,75,.3); transform: translateY(-2px); }
    .news-card-header {
        background: linear-gradient(135deg, rgba(232,184,75,.08) 0%, rgba(255,255,255,.02) 100%);
        border-bottom: 1px solid rgba(255,255,255,.06);
        padding: 1.2rem 1.5rem .8rem;
    }
    .news-meta { color: rgba(255,255,255,.4); font-size: .78rem; }
    .news-body { padding: 1.2rem 1.5rem; }
    .news-excerpt { color: rgba(255,255,255,.65); font-size: .9rem; line-height: 1.65; }
    .news-title a { color: #e8b84b; text-decoration: none; }
    .news-title a:hover { color: #f0ca6a; text-decoration: underline; }
    .comment-box {
        background: rgba(255,255,255,.03);
        border: 1px solid rgba(255,255,255,.06);
        border-radius: 10px;
        padding: 1rem 1.2rem;
        margin-bottom: .6rem;
    }
    .comment-box.hidden-comment {
        opacity: .4;
        border-color: rgba(244,67,54,.2);
        background: rgba(244,67,54,.03);
    }
    .comment-author { color: #e8b84b; font-weight: 600; font-size: .85rem; }
    .comment-date { color: rgba(255,255,255,.3); font-size: .75rem; }
    .comment-text { color: rgba(255,255,255,.7); font-size: .88rem; line-height: 1.55; margin-top: .3rem; }
    .btn-gold { background: #e8b84b; color: #1a1a1a; border: none; font-weight: 700; }
    .btn-gold:hover { background: #f0ca6a; color: #1a1a1a; }
    textarea.form-control {
        background: rgba(255,255,255,.04) !important;
        border: 1px solid rgba(255,255,255,.12) !important;
        color: #e0e0e0 !important;
        resize: vertical;
    }
    textarea.form-control:focus {
        border-color: rgba(232,184,75,.4) !important;
        box-shadow: 0 0 0 .2rem rgba(232,184,75,.1) !important;
    }
    .news-image-float {
        max-width: 340px;
        width: 100%;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0,0,0,.5);
        margin-bottom: 1rem;
    }
    .news-image-float.float-left  { float: left;  margin-right: 1.5rem; }
    .news-image-float.float-right { float: right; margin-left:  1.5rem; }
    @media (max-width: 600px) {
        .news-image-float { float: none !important; max-width: 100%; margin: 0 0 1rem 0; }
    }
    .news-content { overflow: hidden; }
</style>

<main style="padding-top:6px; background:#14325a; flex:1;">

    <!-- Header -->
    <section class="py-2" style="background:linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom:1px solid rgba(232,184,75,.15);">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between py-1">
                <h1 class="fw-bold mb-0" style="color:#e8b84b; font-size:1.4rem;">
                    <i class="bi bi-newspaper me-2"></i>News
                </h1>
                <?php if ($showPostId > 0): ?>
                <a href="/news.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Alle News
                </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="py-4" style="background:#14325a;">
        <div class="container">

        <?php if ($showPostId > 0 && isset($post)): ?>
        <!-- ── Einzelner Post ──────────────────────────────────────────────── -->
        <div class="row justify-content-center">
            <div class="col-lg-8">

                <!-- Post-Karte -->
                <article class="news-card mb-5">
                    <div class="news-card-header">
                        <h2 class="fw-bold mb-2" style="color:#e8b84b; font-size:1.5rem;"><?= e($post['title']) ?></h2>
                        <div class="news-meta">
                            <i class="bi bi-person me-1"></i><?= e($post['author_name']) ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-calendar3 me-1"></i><?= date('d.m.Y H:i', strtotime($post['created_at'])) ?>
                            <?php if ($post['updated_at'] !== $post['created_at']): ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-pencil me-1"></i>bearbeitet <?= date('d.m.Y', strtotime($post['updated_at'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="news-body">
                        <?php if (!empty($post['image_path'])): ?>
                        <?php $imgLeft = $post['image_position'] === 'left'; ?>
                        <div class="d-flex gap-4 flex-column flex-md-row <?= $imgLeft ? '' : 'flex-md-row-reverse' ?>">
                            <div style="flex-shrink:0; width:100%; max-width:320px;">
                                <img src="<?= e($post['image_path']) ?>"
                                     alt="<?= e($post['title']) ?>"
                                     style="width:100%; border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,.5); display:block;">
                            </div>
                            <div style="color:rgba(255,255,255,.8); line-height:1.75; font-size:.95rem; flex:1; min-width:0;">
                                <?= nl2br(e($post['content'])) ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div style="color:rgba(255,255,255,.8); line-height:1.75; font-size:.95rem;">
                            <?= nl2br(e($post['content'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </article>

                <!-- Kommentare -->
                <div id="kommentare">
                    <h4 class="fw-bold mb-4" style="color:rgba(255,255,255,.8);">
                        <i class="bi bi-chat-left-text me-2"></i>Kommentare
                        <span style="color:rgba(255,255,255,.35); font-weight:400; font-size:.9rem; margin-left:.5rem;">
                            (<?= count(array_filter($comments, fn($c) => !$c['hidden'])) ?>)
                        </span>
                    </h4>

                    <?php foreach ($comments as $c): ?>
                    <?php $isHidden = (int)$c['hidden'] === 1; ?>
                    <?php if ($isHidden && !$canMod) continue; ?>
                    <div class="comment-box <?= $isHidden ? 'hidden-comment' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="comment-author"><i class="bi bi-person-circle me-1"></i><?= e($c['author_name']) ?></span>
                                <span class="comment-date ms-2"><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></span>
                                <?php if ($isHidden): ?>
                                <span style="color:#f44336; font-size:.72rem; margin-left:.5rem;"><i class="bi bi-eye-slash me-1"></i>ausgeblendet</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($canMod): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_comment">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                                <input type="hidden" name="post_id" value="<?= $showPostId ?>">
                                <button type="submit" class="btn btn-sm"
                                        style="background:none; border:1px solid rgba(255,255,255,.1); color:rgba(255,255,255,.4); padding:.15rem .5rem; font-size:.72rem;"
                                        title="<?= $isHidden ? 'Einblenden' : 'Ausblenden' ?>">
                                    <i class="bi bi-<?= $isHidden ? 'eye' : 'eye-slash' ?>"></i>
                                    <?= $isHidden ? 'Einblenden' : 'Ausblenden' ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <div class="comment-text"><?= nl2br(e($c['content'])) ?></div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($loggedIn): ?>
                    <!-- Kommentar schreiben -->
                    <div style="background:rgba(232,184,75,.04); border:1px solid rgba(232,184,75,.1); border-radius:12px; padding:1.5rem; margin-top:1.5rem;">
                        <h6 class="fw-bold mb-3" style="color:#e8b84b;"><i class="bi bi-pencil me-1"></i>Kommentar schreiben</h6>
                        <form method="post">
                            <input type="hidden" name="action" value="comment">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="post_id" value="<?= $showPostId ?>">
                            <div class="mb-3">
                                <textarea name="content" class="form-control" rows="4"
                                          placeholder="Dein Kommentar..." required
                                          style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.12); color:#e0e0e0;"></textarea>
                            </div>
                            <button type="submit" class="btn btn-gold">
                                <i class="bi bi-send me-1"></i>Kommentar posten
                            </button>
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

        <?php else: ?>
        <!-- ── News-Liste ──────────────────────────────────────────────────── -->
        <?php if (empty($posts)): ?>
        <div style="text-align:center; padding:4rem 0;">
            <div style="font-size:3rem; margin-bottom:1rem; opacity:.3;"><i class="bi bi-newspaper"></i></div>
            <p style="color:rgba(255,255,255,.35);">Noch keine News vorhanden.</p>
        </div>
        <?php else: ?>
        <div class="row g-4">
            <?php foreach ($posts as $post):
                $layout   = $post['layout'] ?? 'small';
                $isWide   = $layout === 'wide';
                $colClass = match($layout) {
                    'wide'  => 'col-12',
                    'half'  => 'col-md-6',
                    default => 'col-md-6 col-lg-4',
                };
            ?>
            <div class="<?= $colClass ?>">
                <article class="news-card h-100 d-flex flex-column">
                    <?php if ($isWide && !empty($post['image_path'])): ?>
                    <!-- Volle Breite: Bild links/rechts neben Text -->
                    <?php $imgLeft = ($post['image_position'] ?? 'right') === 'left'; ?>
                    <div class="d-flex flex-column flex-md-row <?= $imgLeft ? '' : 'flex-md-row-reverse' ?>" style="flex:1;">
                        <a href="/news.php?post=<?= (int)$post['id'] ?>"
                           style="display:block; overflow:hidden; flex-shrink:0; width:100%; max-width:420px; min-height:240px;">
                            <img src="<?= e($post['image_path']) ?>" alt="<?= e($post['title']) ?>"
                                 style="width:100%; height:100%; object-fit:cover; transition:transform .3s;"
                                 onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform='scale(1)'">
                        </a>
                        <div class="d-flex flex-column flex-grow-1">
                            <div class="news-card-header">
                                <h3 class="news-title fw-bold mb-2" style="font-size:1.25rem;">
                                    <a href="/news.php?post=<?= (int)$post['id'] ?>"><?= e($post['title']) ?></a>
                                </h3>
                                <div class="news-meta">
                                    <i class="bi bi-person me-1"></i><?= e($post['author_name']) ?>
                                    &nbsp;·&nbsp;
                                    <i class="bi bi-calendar3 me-1"></i><?= date('d.m.Y', strtotime($post['created_at'])) ?>
                                </div>
                            </div>
                            <div class="news-body flex-grow-1 d-flex flex-column">
                                <div class="news-excerpt flex-grow-1">
                                    <?= e(mb_strimwidth(strip_tags($post['content']), 0, 320, '…')) ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-3 pt-2" style="border-top:1px solid rgba(255,255,255,.06);">
                                    <span style="color:rgba(255,255,255,.3); font-size:.78rem;">
                                        <i class="bi bi-chat me-1"></i><?= (int)$post['comment_count'] ?> Kommentar<?= $post['comment_count'] != 1 ? 'e' : '' ?>
                                    </span>
                                    <a href="/news.php?post=<?= (int)$post['id'] ?>" class="btn btn-sm btn-gold">
                                        Lesen <i class="bi bi-arrow-right ms-1"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Klein oder kein Bild -->
                    <?php if (!empty($post['image_path'])): ?>
                    <a href="/news.php?post=<?= (int)$post['id'] ?>" style="display:block; overflow:hidden; height:<?= $isWide ? '260px' : '190px' ?>; flex-shrink:0;">
                        <img src="<?= e($post['image_path']) ?>" alt="<?= e($post['title']) ?>"
                             style="width:100%; height:100%; object-fit:cover; transition:transform .3s;"
                             onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                    </a>
                    <?php endif; ?>
                    <div class="news-card-header">
                        <h3 class="news-title fw-bold mb-2" style="font-size:<?= $isWide ? '1.25rem' : '1.1rem' ?>;">
                            <a href="/news.php?post=<?= (int)$post['id'] ?>"><?= e($post['title']) ?></a>
                        </h3>
                        <div class="news-meta">
                            <i class="bi bi-person me-1"></i><?= e($post['author_name']) ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-calendar3 me-1"></i><?= date('d.m.Y', strtotime($post['created_at'])) ?>
                        </div>
                    </div>
                    <div class="news-body flex-grow-1 d-flex flex-column">
                        <div class="news-excerpt flex-grow-1">
                            <?= e(mb_strimwidth(strip_tags($post['content']), 0, $isWide ? 400 : 180, '…')) ?>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2" style="border-top:1px solid rgba(255,255,255,.06);">
                            <span style="color:rgba(255,255,255,.3); font-size:.78rem;">
                                <i class="bi bi-chat me-1"></i><?= (int)$post['comment_count'] ?> Kommentar<?= $post['comment_count'] != 1 ? 'e' : '' ?>
                            </span>
                            <a href="/news.php?post=<?= (int)$post['id'] ?>" class="btn btn-sm btn-gold">
                                Lesen <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </article>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        </div>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
