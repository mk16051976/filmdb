<?php
$pageTitle = 'Neues Thema – Forum – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(2);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// Kategorien laden
$categories = $db->query("SELECT id, name FROM forum_categories ORDER BY sort_order, id")
                 ->fetchAll(PDO::FETCH_ASSOC);

$preCatId = (int)($_GET['cat'] ?? 0);
$error    = '';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) {
        $error = 'Ungültige Anfrage. Bitte die Seite neu laden.';
    } else {
        $catId = (int)($_POST['cat'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body']  ?? '');

        // Validierung
        if (!$catId) {
            $error = 'Bitte eine Kategorie wählen.';
        } elseif (mb_strlen($title) < 3) {
            $error = 'Titel zu kurz (min. 3 Zeichen).';
        } elseif (mb_strlen($title) > 200) {
            $error = 'Titel zu lang (max. 200 Zeichen).';
        } elseif (mb_strlen($body) < 5) {
            $error = 'Beitrag zu kurz (min. 5 Zeichen).';
        } elseif (mb_strlen($body) > 10000) {
            $error = 'Beitrag zu lang (max. 10.000 Zeichen).';
        } else {
            // Kategorie prüfen
            $cStmt = $db->prepare("SELECT id FROM forum_categories WHERE id = ?");
            $cStmt->execute([$catId]);
            if (!$cStmt->fetch()) {
                $error = 'Ungültige Kategorie.';
            } else {
                $db->beginTransaction();
                try {
                    $db->prepare("INSERT INTO forum_threads (category_id, user_id, title, last_post_at) VALUES (?, ?, ?, NOW())")
                       ->execute([$catId, $userId, $title]);
                    $threadId = (int)$db->lastInsertId();
                    $db->prepare("INSERT INTO forum_posts (thread_id, user_id, body) VALUES (?, ?, ?)")
                       ->execute([$threadId, $userId, $body]);
                    $db->commit();
                    header("Location: /forum-thread.php?id=$threadId");
                    exit;
                } catch (PDOException $e) {
                    $db->rollBack();
                    $error = 'Fehler beim Speichern. Bitte erneut versuchen.';
                }
            }
        }
    }
}
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

<div class="container mt-5 pt-4" style="max-width:700px;">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb" style="background:none; padding:0; font-size:.85rem;">
            <li class="breadcrumb-item"><a href="/forum.php" style="color:#e8b84b;">Forum</a></li>
            <?php if ($preCatId): ?>
            <li class="breadcrumb-item">
                <a href="/forum.php?cat=<?= $preCatId ?>" style="color:#e8b84b;">Kategorie</a>
            </li>
            <?php endif; ?>
            <li class="breadcrumb-item active" style="color:rgba(255,255,255,.5);">Neues Thema</li>
        </ol>
    </nav>

    <h1 class="mb-4 fw-bold text-white" style="font-size:1.5rem;">
        <i class="bi bi-plus-square me-2" style="color:#e8b84b;"></i>Neues Thema erstellen
    </h1>

    <?php if ($error): ?>
    <div class="alert mb-4" style="background:rgba(220,53,69,.15); border:1px solid rgba(220,53,69,.3); color:#f87171; border-radius:8px;">
        <i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?>
    </div>
    <?php endif; ?>

    <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09); border-radius:12px; padding:2rem;">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <div class="mb-4">
                <label class="form-label fw-semibold" style="color:rgba(255,255,255,.7);">Kategorie</label>
                <select name="cat" class="form-select"
                        style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.2);
                               color:#fff; border-radius:8px;" required>
                    <option value="">— Bitte wählen —</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"
                            <?= (int)($_POST['cat'] ?? $preCatId) === (int)$cat['id'] ? 'selected' : '' ?>>
                        <?= e($cat['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold" style="color:rgba(255,255,255,.7);">Titel</label>
                <input type="text" name="title" class="form-control"
                       value="<?= e($_POST['title'] ?? '') ?>"
                       placeholder="Kurzer, aussagekräftiger Titel"
                       maxlength="200"
                       style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.2);
                              color:#fff; border-radius:8px;"
                       required>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold" style="color:rgba(255,255,255,.7);">Beitrag</label>
                <?php
                $ntBtns = [
                    ['[b]','[/b]','B','font-weight:700;','Fett'],
                    ['[i]','[/i]','I','font-style:italic;','Kursiv'],
                    ['[u]','[/u]','U','text-decoration:underline;','Unterstrichen'],
                    ['[s]','[/s]','S','text-decoration:line-through;','Durchgestrichen'],
                    ['[big]','[/big]','A+','font-size:1.1em;','Größer'],
                    ['[small]','[/small]','A−','font-size:.85em;','Kleiner'],
                ];
                echo '<div style="display:flex;flex-wrap:wrap;gap:3px;padding:5px 8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.2);border-bottom:none;border-radius:8px 8px 0 0;">';
                foreach ($ntBtns as [$open,$close,$label,$style,$title]) {
                    echo '<button type="button" title="' . htmlspecialchars($title,ENT_QUOTES) . '"'
                       . ' onclick="insertBB(\'new-ta\',\'' . addslashes($open) . '\',\'' . addslashes($close) . '\')"'
                       . ' style="background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.15);color:#e0e0e0;'
                       . 'border-radius:4px;padding:2px 9px;font-size:.8rem;cursor:pointer;line-height:1.6;' . $style . '">'
                       . htmlspecialchars($label) . '</button>';
                }
                echo '</div>';
                ?>
                <textarea id="new-ta" name="body" rows="8" class="form-control"
                          placeholder="Schreib deinen ersten Beitrag hier…"
                          style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.2);
                                 color:#fff; border-radius:0 0 8px 8px; resize:vertical;"
                          required><?= e($_POST['body'] ?? '') ?></textarea>
                <div style="font-size:.75rem; color:rgba(255,255,255,.3); margin-top:.3rem;">
                    Max. 10.000 Zeichen &nbsp;·&nbsp; Formatierung: [b]fett[/b] [i]kursiv[/i] [u]unterstr.[/u] [s]durch[/s] [big]groß[/big] [small]klein[/small]
                </div>
            </div>

            <div class="d-flex gap-3">
                <button type="submit" class="btn"
                        style="background:#e8b84b; color:#0a192f; font-weight:700; padding:.45rem 1.5rem;">
                    <i class="bi bi-send me-1"></i>Thema erstellen
                </button>
                <a href="/forum.php<?= $preCatId ? "?cat=$preCatId" : '' ?>"
                   class="btn"
                   style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.15);
                          color:rgba(255,255,255,.6); padding:.45rem 1.2rem;">
                    Abbrechen
                </a>
            </div>
        </form>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
