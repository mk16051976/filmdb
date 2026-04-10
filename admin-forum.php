<?php
$pageTitle = 'Forum-Verwaltung – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!isAdmin()) { header('Location: /index.php'); exit; }

$db  = getDB();
$msg = '';
$msgType = 'success';

// ── POST-Aktionen ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    $action = $_POST['action'] ?? '';

    // Kategorie hinzufügen
    if ($action === 'add_cat') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['desc'] ?? '');
        if ($name === '') {
            $msg = 'Name darf nicht leer sein.'; $msgType = 'danger';
        } else {
            $maxSort = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) FROM forum_categories")->fetchColumn();
            $db->prepare("INSERT INTO forum_categories (name, description, sort_order) VALUES (?, ?, ?)")
               ->execute([$name, $desc, $maxSort + 1]);
            $msg = 'Kategorie "' . $name . '" wurde hinzugefügt.';
        }
    }

    // Kategorie bearbeiten
    if ($action === 'edit_cat') {
        $catId = (int)($_POST['cat_id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['desc'] ?? '');
        if ($catId && $name !== '') {
            $db->prepare("UPDATE forum_categories SET name = ?, description = ? WHERE id = ?")
               ->execute([$name, $desc, $catId]);
            $msg = 'Kategorie wurde gespeichert.';
        }
    }

    // Kategorie löschen
    if ($action === 'delete_cat') {
        $catId = (int)($_POST['cat_id'] ?? 0);
        if ($catId) {
            // Alle Posts und Threads dieser Kategorie löschen
            $threads = $db->prepare("SELECT id FROM forum_threads WHERE category_id = ?");
            $threads->execute([$catId]);
            foreach ($threads->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                $db->prepare("DELETE FROM forum_posts WHERE thread_id = ?")->execute([$tid]);
            }
            $db->prepare("DELETE FROM forum_threads WHERE category_id = ?")->execute([$catId]);
            $db->prepare("DELETE FROM forum_categories WHERE id = ?")->execute([$catId]);
            $msg = 'Kategorie wurde gelöscht.';
        }
    }

    // Kategorie-Reihenfolge ändern
    if ($action === 'move_cat') {
        $catId     = (int)($_POST['cat_id'] ?? 0);
        $direction = $_POST['dir'] ?? '';
        if ($catId && in_array($direction, ['up', 'down'])) {
            $all = $db->query("SELECT id, sort_order FROM forum_categories ORDER BY sort_order, id")
                      ->fetchAll(PDO::FETCH_ASSOC);
            $idx = array_search($catId, array_column($all, 'id'));
            $swapIdx = $direction === 'up' ? $idx - 1 : $idx + 1;
            if ($swapIdx >= 0 && $swapIdx < count($all)) {
                $a = $all[$idx]; $b = $all[$swapIdx];
                $db->prepare("UPDATE forum_categories SET sort_order = ? WHERE id = ?")->execute([$b['sort_order'], $a['id']]);
                $db->prepare("UPDATE forum_categories SET sort_order = ? WHERE id = ?")->execute([$a['sort_order'], $b['id']]);
            }
            $msg = 'Reihenfolge geändert.';
        }
    }

    // Post löschen
    if ($action === 'delete_post') {
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId) {
            $db->prepare("DELETE FROM forum_posts WHERE id = ?")->execute([$postId]);
            $msg = 'Beitrag gelöscht.';
        }
    }

    // Thread löschen
    if ($action === 'delete_thread') {
        $tid = (int)($_POST['thread_id'] ?? 0);
        if ($tid) {
            $db->prepare("DELETE FROM forum_posts WHERE thread_id = ?")->execute([$tid]);
            $db->prepare("DELETE FROM forum_threads WHERE id = ?")->execute([$tid]);
            $msg = 'Thread gelöscht.';
        }
    }

    // Thread sperren/entsperren
    if ($action === 'toggle_lock') {
        $tid = (int)($_POST['thread_id'] ?? 0);
        if ($tid) {
            $db->prepare("UPDATE forum_threads SET locked = NOT locked WHERE id = ?")->execute([$tid]);
            $msg = 'Thread-Status geändert.';
        }
    }
}

// ── Daten laden ───────────────────────────────────────────────────────────────
$categories = $db->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM forum_threads t WHERE t.category_id = c.id) AS thread_count,
        (SELECT COUNT(*) FROM forum_posts p JOIN forum_threads t ON t.id = p.thread_id WHERE t.category_id = c.id) AS post_count
    FROM forum_categories c
    ORDER BY c.sort_order, c.id
")->fetchAll(PDO::FETCH_ASSOC);

$recentThreads = $db->query("
    SELECT t.*, c.name AS cat_name, u.username AS author,
           (SELECT COUNT(*) FROM forum_posts p WHERE p.thread_id = t.id) AS post_count
    FROM forum_threads t
    JOIN forum_categories c ON c.id = t.category_id
    JOIN users u ON u.id = t.user_id
    ORDER BY t.created_at DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

$recentPosts = $db->query("
    SELECT p.*, u.username, t.title AS thread_title, t.id AS thread_id
    FROM forum_posts p
    JOIN users u ON u.id = p.user_id
    JOIN forum_threads t ON t.id = p.thread_id
    ORDER BY p.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

function fmtDate(string $ts): string {
    return (new DateTime($ts))->format('d.m.Y H:i');
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="container mt-5 pt-4" style="max-width:1000px;">

    <div class="d-flex align-items-center gap-3 mb-4">
        <h1 class="mb-0 fw-bold text-white" style="font-size:1.5rem;">
            <i class="bi bi-chat-square-text-fill me-2" style="color:#e8b84b;"></i>Forum-Verwaltung
        </h1>
        <a href="/forum.php" class="btn btn-sm ms-auto"
           style="background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); color:rgba(255,255,255,.7);">
            <i class="bi bi-box-arrow-up-right me-1"></i>Forum öffnen
        </a>
    </div>

    <?php if ($msg): ?>
    <div class="alert mb-4" style="background:rgba(<?= $msgType === 'success' ? '25,135,84' : '220,53,69' ?>,.15);
         border:1px solid rgba(<?= $msgType === 'success' ? '25,135,84' : '220,53,69' ?>,.3);
         color:<?= $msgType === 'success' ? '#6ee7b7' : '#f87171' ?>; border-radius:8px;">
        <i class="bi bi-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i><?= e($msg) ?>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- ── Kategorien ─────────────────────────────────────────────────── -->
        <div class="col-lg-5">
            <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09); border-radius:12px; padding:1.5rem;">
                <h5 class="text-white fw-semibold mb-3">Kategorien</h5>

                <?php foreach ($categories as $i => $cat): ?>
                <div class="d-flex align-items-start gap-2 mb-3 pb-3"
                     style="border-bottom:1px solid rgba(255,255,255,.07);">
                    <div style="flex:1;">
                        <div style="color:#fff; font-weight:600;"><?= e($cat['name']) ?></div>
                        <div style="font-size:.75rem; color:rgba(255,255,255,.35);">
                            <?= (int)$cat['thread_count'] ?> Themen &middot; <?= (int)$cat['post_count'] ?> Beiträge
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        <!-- Reihenfolge -->
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action"  value="move_cat">
                            <input type="hidden" name="cat_id"  value="<?= $cat['id'] ?>">
                            <input type="hidden" name="dir"     value="up">
                            <button type="submit" class="btn btn-sm p-1 border-0"
                                    style="background:none; color:rgba(255,255,255,.3); font-size:.8rem;"
                                    <?= $i === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-chevron-up"></i>
                            </button>
                        </form>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action"  value="move_cat">
                            <input type="hidden" name="cat_id"  value="<?= $cat['id'] ?>">
                            <input type="hidden" name="dir"     value="down">
                            <button type="submit" class="btn btn-sm p-1 border-0"
                                    style="background:none; color:rgba(255,255,255,.3); font-size:.8rem;"
                                    <?= $i === count($categories) - 1 ? 'disabled' : '' ?>>
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </form>
                        <!-- Bearbeiten (Modal) -->
                        <button class="btn btn-sm p-1 border-0"
                                style="background:none; color:rgba(255,255,255,.4); font-size:.8rem;"
                                data-bs-toggle="modal" data-bs-target="#editCatModal"
                                data-cat-id="<?= $cat['id'] ?>"
                                data-cat-name="<?= e($cat['name']) ?>"
                                data-cat-desc="<?= e($cat['description']) ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <!-- Löschen -->
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Kategorie „<?= e($cat['name']) ?>" und alle Threads darin löschen?');">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action"  value="delete_cat">
                            <input type="hidden" name="cat_id"  value="<?= $cat['id'] ?>">
                            <button type="submit" class="btn btn-sm p-1 border-0"
                                    style="background:none; color:rgba(220,53,69,.5); font-size:.8rem;">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Neue Kategorie -->
                <h6 class="text-white fw-semibold mt-3 mb-2">Neue Kategorie</h6>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="add_cat">
                    <input type="text" name="name" class="form-control form-control-sm mb-2"
                           placeholder="Name"
                           style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.2); color:#fff; border-radius:6px;">
                    <input type="text" name="desc" class="form-control form-control-sm mb-2"
                           placeholder="Kurzbeschreibung"
                           style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.2); color:#fff; border-radius:6px;">
                    <button type="submit" class="btn btn-sm"
                            style="background:#e8b84b; color:#0a192f; font-weight:700;">
                        <i class="bi bi-plus me-1"></i>Hinzufügen
                    </button>
                </form>
            </div>
        </div>

        <!-- ── Letzte Threads ─────────────────────────────────────────────── -->
        <div class="col-lg-7">
            <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09); border-radius:12px; padding:1.5rem; margin-bottom:1.5rem;">
                <h5 class="text-white fw-semibold mb-3">Letzte Threads</h5>
                <?php if (empty($recentThreads)): ?>
                <p style="color:rgba(255,255,255,.3); font-size:.85rem;">Noch keine Threads.</p>
                <?php else: ?>
                <div style="max-height:320px; overflow-y:auto;">
                    <?php foreach ($recentThreads as $t): ?>
                    <div class="d-flex align-items-center gap-2 mb-2 pb-2"
                         style="border-bottom:1px solid rgba(255,255,255,.05); font-size:.82rem;">
                        <div style="flex:1; min-width:0;">
                            <a href="/forum-thread.php?id=<?= $t['id'] ?>" target="_blank"
                               class="text-decoration-none"
                               style="color:#fff; font-weight:500; display:block;
                                      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <?= $t['locked'] ? '<i class="bi bi-lock-fill me-1" style="color:rgba(255,255,255,.3);"></i>' : '' ?>
                                <?= e($t['title']) ?>
                            </a>
                            <span style="color:rgba(255,255,255,.3);">
                                <?= e($t['cat_name']) ?> &middot; <?= e($t['author']) ?>
                                &middot; <?= (int)$t['post_count'] ?> Posts
                                &middot; <?= fmtDate($t['created_at']) ?>
                            </span>
                        </div>
                        <div class="d-flex gap-1 flex-shrink-0">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action"    value="toggle_lock">
                                <input type="hidden" name="thread_id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-sm p-0 border-0"
                                        style="background:none; color:rgba(255,255,255,.3); font-size:.8rem;"
                                        title="<?= $t['locked'] ? 'Entsperren' : 'Sperren' ?>">
                                    <i class="bi bi-<?= $t['locked'] ? 'unlock' : 'lock' ?>"></i>
                                </button>
                            </form>
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Thread löschen?');">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action"    value="delete_thread">
                                <input type="hidden" name="thread_id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-sm p-0 border-0"
                                        style="background:none; color:rgba(220,53,69,.5); font-size:.8rem;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Letzte Posts -->
            <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09); border-radius:12px; padding:1.5rem;">
                <h5 class="text-white fw-semibold mb-3">Letzte Beiträge</h5>
                <?php if (empty($recentPosts)): ?>
                <p style="color:rgba(255,255,255,.3); font-size:.85rem;">Noch keine Beiträge.</p>
                <?php else: ?>
                <div style="max-height:320px; overflow-y:auto;">
                    <?php foreach ($recentPosts as $p): ?>
                    <div class="d-flex align-items-start gap-2 mb-2 pb-2"
                         style="border-bottom:1px solid rgba(255,255,255,.05); font-size:.82rem;">
                        <div style="flex:1; min-width:0;">
                            <div style="color:rgba(255,255,255,.5);">
                                <strong style="color:#fff;"><?= e($p['username']) ?></strong>
                                in <a href="/forum-thread.php?id=<?= $p['thread_id'] ?>" target="_blank"
                                      style="color:#e8b84b; text-decoration:none;"><?= e($p['thread_title']) ?></a>
                                &middot; <?= fmtDate($p['created_at']) ?>
                            </div>
                            <div style="color:rgba(255,255,255,.35); white-space:nowrap; overflow:hidden;
                                        text-overflow:ellipsis; max-width:400px;">
                                <?= e(mb_substr($p['body'], 0, 120)) ?>
                            </div>
                        </div>
                        <form method="post" class="d-inline flex-shrink-0"
                              onsubmit="return confirm('Beitrag löschen?');">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action"  value="delete_post">
                            <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-sm p-0 border-0"
                                    style="background:none; color:rgba(220,53,69,.5); font-size:.8rem;">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Edit-Kategorie Modal -->
<div class="modal fade" id="editCatModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:#112240; border:1px solid rgba(255,255,255,.15);">
            <div class="modal-header" style="border-color:rgba(255,255,255,.1);">
                <h5 class="modal-title text-white">Kategorie bearbeiten</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action"  value="edit_cat">
                    <input type="hidden" name="cat_id"  id="editCatId">
                    <div class="mb-3">
                        <label class="form-label" style="color:rgba(255,255,255,.7);">Name</label>
                        <input type="text" name="name" id="editCatName" class="form-control"
                               style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.2); color:#fff;" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="color:rgba(255,255,255,.7);">Beschreibung</label>
                        <input type="text" name="desc" id="editCatDesc" class="form-control"
                               style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.2); color:#fff;">
                    </div>
                </div>
                <div class="modal-footer" style="border-color:rgba(255,255,255,.1);">
                    <button type="button" class="btn btn-sm" data-bs-dismiss="modal"
                            style="background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); color:rgba(255,255,255,.6);">
                        Abbrechen
                    </button>
                    <button type="submit" class="btn btn-sm"
                            style="background:#e8b84b; color:#0a192f; font-weight:700;">
                        Speichern
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('editCatModal').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('editCatId').value   = btn.dataset.catId;
    document.getElementById('editCatName').value = btn.dataset.catName;
    document.getElementById('editCatDesc').value = btn.dataset.catDesc;
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
