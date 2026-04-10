<?php
$pageTitle = 'Forum – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(2);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$isMod  = in_array(userRole(), ['Admin', 'Moderator']);

$threadId = (int)($_GET['id'] ?? 0);
if (!$threadId) { header('Location: /forum.php'); exit; }

// Thread laden
$tStmt = $db->prepare("
    SELECT t.*, c.name AS cat_name, c.id AS cat_id, u.username AS author
    FROM forum_threads t
    JOIN forum_categories c ON c.id = t.category_id
    JOIN users u ON u.id = t.user_id
    WHERE t.id = ?
");
$tStmt->execute([$threadId]);
$thread = $tStmt->fetch(PDO::FETCH_ASSOC);
if (!$thread) { header('Location: /forum.php'); exit; }

$pageTitle = e($thread['title']) . ' – Forum – MKFB';

// ── POST-Aktionen ─────────────────────────────────────────────────────────────
$error = '';
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {

    // Antwort verfassen
    if ($action === 'reply' && !$thread['locked']) {
        $body = trim($_POST['body'] ?? '');
        if (mb_strlen($body) < 2) {
            $error = 'Beitrag ist zu kurz (min. 2 Zeichen).';
        } elseif (mb_strlen($body) > 10000) {
            $error = 'Beitrag ist zu lang (max. 10.000 Zeichen).';
        } else {
            $db->prepare("INSERT INTO forum_posts (thread_id, user_id, body) VALUES (?, ?, ?)")
               ->execute([$threadId, $userId, $body]);
            $db->prepare("UPDATE forum_threads SET last_post_at = NOW() WHERE id = ?")
               ->execute([$threadId]);
            // Letzte Seite anspringen
            $cntStmt = $db->prepare("SELECT COUNT(*) FROM forum_posts WHERE thread_id = ?");
            $cntStmt->execute([$threadId]);
            $total    = (int)$cntStmt->fetchColumn();
            $lastPage = (int)ceil($total / 20);
            header("Location: /forum-thread.php?id=$threadId&page=$lastPage#posts-end");
            exit;
        }
    }

    // Beitrag bearbeiten
    if ($action === 'edit_post') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $body   = trim($_POST['body'] ?? '');
        // Prüfen ob eigener Post oder Mod
        $pStmt = $db->prepare("SELECT user_id FROM forum_posts WHERE id = ? AND thread_id = ?");
        $pStmt->execute([$postId, $threadId]);
        $post = $pStmt->fetch();
        if ($post && ($post['user_id'] === $userId || $isMod)) {
            if (mb_strlen($body) < 2) {
                $error = 'Beitrag ist zu kurz (min. 2 Zeichen).';
            } elseif (mb_strlen($body) > 10000) {
                $error = 'Beitrag ist zu lang (max. 10.000 Zeichen).';
            } else {
                $db->prepare("UPDATE forum_posts SET body = ?, edited_at = NOW() WHERE id = ?")
                   ->execute([$body, $postId]);
                header("Location: /forum-thread.php?id=$threadId#post-$postId");
                exit;
            }
        }
    }

    // Beitrag löschen
    if ($action === 'delete_post') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $pStmt = $db->prepare("SELECT user_id FROM forum_posts WHERE id = ? AND thread_id = ?");
        $pStmt->execute([$postId, $threadId]);
        $post = $pStmt->fetch();
        if ($post && ($post['user_id'] === $userId || $isMod)) {
            // Erstes Post? → ganzen Thread löschen
            $firstPost = $db->prepare("SELECT MIN(id) FROM forum_posts WHERE thread_id = ?");
            $firstPost->execute([$threadId]);
            $firstId = (int)$firstPost->fetchColumn();
            if ($postId === $firstId) {
                $db->prepare("DELETE FROM forum_posts WHERE thread_id = ?")->execute([$threadId]);
                $db->prepare("DELETE FROM forum_threads WHERE id = ?")->execute([$threadId]);
                $catId = $thread['cat_id'];
                header("Location: /forum.php?cat=$catId&deleted=1");
                exit;
            } else {
                $db->prepare("DELETE FROM forum_posts WHERE id = ?")->execute([$postId]);
                header("Location: /forum-thread.php?id=$threadId");
                exit;
            }
        }
    }

    // Thread sperren/entsperren (Mod/Admin)
    if ($action === 'toggle_lock' && $isMod) {
        $db->prepare("UPDATE forum_threads SET locked = NOT locked WHERE id = ?")
           ->execute([$threadId]);
        header("Location: /forum-thread.php?id=$threadId");
        exit;
    }

    // Thread löschen (Mod/Admin oder eigener erster Post ohne Antworten)
    if ($action === 'delete_thread') {
        $canDelete = $isMod;
        if (!$canDelete && $thread['user_id'] === $userId) {
            $pcStmt   = $db->prepare("SELECT COUNT(*) FROM forum_posts WHERE thread_id = ?");
            $pcStmt->execute([$threadId]);
            $postCount = (int)$pcStmt->fetchColumn();
            $canDelete = $postCount <= 1;
        }
        if ($canDelete) {
            $db->prepare("DELETE FROM forum_posts WHERE thread_id = ?")->execute([$threadId]);
            $db->prepare("DELETE FROM forum_threads WHERE id = ?")->execute([$threadId]);
            header("Location: /forum.php?cat={$thread['cat_id']}&deleted=1");
            exit;
        }
    }
}

// ── Aufrufe zählen (nur GET) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $db->prepare("UPDATE forum_threads SET views = views + 1 WHERE id = ?")
       ->execute([$threadId]);
}

// ── Posts laden ───────────────────────────────────────────────────────────────
$perPage    = 20;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPosts = (function() use ($db, $threadId) {
    $s = $db->prepare("SELECT COUNT(*) FROM forum_posts WHERE thread_id = ?");
    $s->execute([$threadId]);
    return (int)$s->fetchColumn();
})();
$totalPages = max(1, (int)ceil($totalPosts / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$pStmt = $db->prepare("
    SELECT p.*, u.username, u.role
    FROM forum_posts p
    JOIN users u ON u.id = p.user_id
    WHERE p.thread_id = ?
    ORDER BY p.created_at ASC
    LIMIT ? OFFSET ?
");
$pStmt->execute([$threadId, $perPage, $offset]);
$posts = $pStmt->fetchAll(PDO::FETCH_ASSOC);

// Bearbeitungs-Modus
$editPostId = (int)($_GET['edit'] ?? 0);

function fmtDate(string $ts): string {
    $d = new DateTime($ts);
    return $d->format('d.m.Y') . ' um ' . $d->format('H:i');
}

function postNumber(int $page, int $perPage, int $idx): int {
    return ($page - 1) * $perPage + $idx + 1;
}

/** Gibt die BBCode-Toolbar für ein Textarea mit der gegebenen ID aus. */
// forumToolbar() is defined in includes/functions.php
?>
<?php include __DIR__ . '/includes/header.php'; ?>
<script>
function insertBB(taId, open, close) {
    const ta = document.getElementById(taId);
    if (!ta) return;
    const s = ta.selectionStart, e = ta.selectionEnd;
    const sel = ta.value.substring(s, e) || 'Text';
    ta.value = ta.value.substring(0, s) + open + sel + close + ta.value.substring(e);
    ta.selectionStart = s + open.length;
    ta.selectionEnd   = s + open.length + (e > s ? e - s : sel.length);
    ta.focus();
}
</script>

<div class="container mt-5 pt-4" style="max-width:860px;">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb" style="background:none; padding:0; font-size:.85rem;">
            <li class="breadcrumb-item"><a href="/forum.php" style="color:#e8b84b;">Forum</a></li>
            <li class="breadcrumb-item">
                <a href="/forum.php?cat=<?= $thread['cat_id'] ?>" style="color:#e8b84b;">
                    <?= e($thread['cat_name']) ?>
                </a>
            </li>
            <li class="breadcrumb-item active" style="color:rgba(255,255,255,.5);">
                <?= e($thread['title']) ?>
            </li>
        </ol>
    </nav>

    <!-- Thread-Titel -->
    <div class="d-flex align-items-start justify-content-between gap-3 mb-4">
        <h1 class="mb-0 fw-bold text-white" style="font-size:1.5rem; line-height:1.3;">
            <?php if ($thread['locked']): ?>
            <i class="bi bi-lock-fill me-2" style="color:rgba(255,255,255,.3); font-size:1.1rem;"></i>
            <?php endif; ?>
            <?= e($thread['title']) ?>
        </h1>
        <?php if ($isMod): ?>
        <div class="d-flex gap-2 flex-shrink-0">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="toggle_lock">
                <button type="submit" class="btn btn-sm"
                        style="background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); color:rgba(255,255,255,.6); font-size:.75rem;">
                    <?= $thread['locked'] ? '<i class="bi bi-unlock me-1"></i>Entsperren' : '<i class="bi bi-lock me-1"></i>Sperren' ?>
                </button>
            </form>
            <form method="post" onsubmit="return confirm('Thread wirklich löschen?');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="delete_thread">
                <button type="submit" class="btn btn-sm"
                        style="background:rgba(220,53,69,.15); border:1px solid rgba(220,53,69,.3); color:#f87171; font-size:.75rem;">
                    <i class="bi bi-trash me-1"></i>Löschen
                </button>
            </form>
        </div>
        <?php elseif ($thread['user_id'] === $userId): ?>
        <form method="post" onsubmit="return confirm('Thread wirklich löschen?');">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="delete_thread">
            <button type="submit" class="btn btn-sm"
                    style="background:rgba(220,53,69,.15); border:1px solid rgba(220,53,69,.3); color:#f87171; font-size:.75rem;">
                <i class="bi bi-trash me-1"></i>Löschen
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
    <div class="alert" style="background:rgba(220,53,69,.15); border:1px solid rgba(220,53,69,.3); color:#f87171; border-radius:8px;">
        <i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
    <div class="alert" style="background:rgba(25,135,84,.15); border:1px solid rgba(25,135,84,.3); color:#6ee7b7; border-radius:8px;">
        <i class="bi bi-check-circle me-2"></i>Beitrag wurde gelöscht.
    </div>
    <?php endif; ?>

    <!-- ── Posts ─────────────────────────────────────────────────────────────── -->
    <div id="posts-top"></div>
    <?php foreach ($posts as $idx => $post): ?>
    <div id="post-<?= $post['id'] ?>" class="mb-3"
         style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09);
                border-radius:10px; overflow:hidden;">
        <!-- Post-Header -->
        <div class="d-flex align-items-center justify-content-between px-4 py-2"
             style="background:rgba(255,255,255,.04); border-bottom:1px solid rgba(255,255,255,.07);">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-person-circle" style="color:#e8b84b; font-size:1.1rem;"></i>
                <strong style="color:#fff;"><?= e($post['username']) ?></strong>
                <?php if (in_array($post['role'], ['Admin', 'Moderator'])): ?>
                <span style="font-size:.68rem; padding:1px 6px; border-radius:10px;
                             background:rgba(232,184,75,.2); color:#e8b84b; font-weight:600;">
                    <?= e($post['role']) ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span style="font-size:.75rem; color:rgba(255,255,255,.35);">
                    #<?= postNumber($page, $perPage, $idx) ?>
                    &middot; <?= fmtDate($post['created_at']) ?>
                    <?php if ($post['edited_at']): ?>
                    <em style="color:rgba(255,255,255,.25);">(bearbeitet)</em>
                    <?php endif; ?>
                </span>
                <!-- Aktionen -->
                <?php if ($post['user_id'] === $userId || $isMod): ?>
                <div class="d-flex gap-1">
                    <a href="/forum-thread.php?id=<?= $threadId ?>&edit=<?= $post['id'] ?>&page=<?= $page ?>#post-<?= $post['id'] ?>"
                       style="color:rgba(255,255,255,.3); font-size:.8rem; text-decoration:none;"
                       title="Bearbeiten">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form method="post" class="d-inline"
                          onsubmit="return confirm('Beitrag wirklich löschen?');">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action"  value="delete_post">
                        <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                        <button type="submit" class="btn p-0 border-0"
                                style="color:rgba(255,255,255,.3); font-size:.8rem; background:none;"
                                title="Löschen">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Post-Body oder Edit-Form -->
        <?php if ($editPostId === $post['id'] && ($post['user_id'] === $userId || $isMod)): ?>
        <?php $editTaId = 'edit-ta-' . $post['id']; ?>
        <form method="post" class="p-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action"  value="edit_post">
            <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
            <?php forumToolbar($editTaId); ?>
            <textarea id="<?= $editTaId ?>" name="body" rows="6" class="form-control mb-3"
                      style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.2);
                             color:#fff; border-radius:8px; resize:vertical; border-top-left-radius:0; border-top-right-radius:0;"
                      required><?= e($post['body']) ?></textarea>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-sm"
                        style="background:#e8b84b; color:#0a192f; font-weight:700;">
                    Speichern
                </button>
                <a href="/forum-thread.php?id=<?= $threadId ?>&page=<?= $page ?>#post-<?= $post['id'] ?>"
                   class="btn btn-sm"
                   style="background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); color:rgba(255,255,255,.6);">
                    Abbrechen
                </a>
            </div>
        </form>
        <?php else: ?>
        <div class="px-4 py-3 forum-body" style="color:rgba(255,255,255,.85); line-height:1.65; word-break:break-word;">
            <?= renderForumBody($post['body']) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div id="posts-end"></div>

    <!-- Paginierung -->
    <?php if ($totalPages > 1): ?>
    <nav class="my-4 d-flex justify-content-center">
        <ul class="pagination pagination-sm">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="/forum-thread.php?id=<?= $threadId ?>&page=<?= $p ?>"
                   style="background:<?= $p === $page ? '#e8b84b' : 'rgba(255,255,255,.07)' ?>;
                          border-color:rgba(255,255,255,.1);
                          color:<?= $p === $page ? '#0a192f' : '#fff' ?>;">
                    <?= $p ?>
                </a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>

    <!-- ── Antwort-Formular ───────────────────────────────────────────────────── -->
    <?php if ($thread['locked']): ?>
    <div class="mt-4 py-3 px-4 text-center"
         style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08);
                border-radius:10px; color:rgba(255,255,255,.35); font-size:.9rem;">
        <i class="bi bi-lock-fill me-2"></i>Dieser Thread ist gesperrt. Keine neuen Antworten möglich.
    </div>
    <?php else: ?>
    <div class="mt-4" style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09);
                              border-radius:10px; padding:1.5rem;">
        <h6 class="text-white mb-3 fw-semibold">Antwort verfassen</h6>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="reply">
            <?php forumToolbar('reply-ta'); ?>
            <textarea id="reply-ta" name="body" rows="5" class="form-control mb-3"
                      placeholder="Schreib deine Antwort hier…"
                      style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.2);
                             color:#fff; border-radius:8px; resize:vertical; border-top-left-radius:0; border-top-right-radius:0;"
                      required></textarea>
            <button type="submit" class="btn"
                    style="background:#e8b84b; color:#0a192f; font-weight:700; padding:.4rem 1.2rem;">
                <i class="bi bi-send me-1"></i>Absenden
            </button>
        </form>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
