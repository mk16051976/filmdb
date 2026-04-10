<?php
$pageTitle = 'Forum – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(2);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$isMod  = in_array(userRole(), ['Admin', 'Moderator']);

// ── Tabellen sicherstellen ────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS forum_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS forum_threads (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id  INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    title        VARCHAR(200) NOT NULL,
    views        INT UNSIGNED NOT NULL DEFAULT 0,
    locked       TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_post_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cat  (category_id),
    INDEX idx_last (last_post_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS forum_posts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id  INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    body       TEXT NOT NULL,
    edited_at  DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_thread (thread_id),
    INDEX idx_user   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Standardkategorien anlegen wenn noch keine vorhanden
if ((int)$db->query("SELECT COUNT(*) FROM forum_categories")->fetchColumn() === 0) {
    $db->exec("INSERT INTO forum_categories (name, description, sort_order) VALUES
        ('Allgemein', 'Allgemeine Diskussionen rund um Filme und das Projekt', 1),
        ('Filmempfehlungen', 'Empfehlt euch gegenseitig Filme', 2),
        ('Ranglisten & Bewertungen', 'Diskutiert eure Rankings und Bewertungen', 3),
        ('Feedback & Ideen', 'Verbesserungsvorschläge und Ideen zum Projekt', 4)");
}

// ── Modus: Kategorieübersicht oder Thread-Liste ───────────────────────────────
$catId    = (int)($_GET['cat'] ?? 0);
$category = null;
$threads  = [];
$totalThreads = 0;
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

if ($catId > 0) {
    // Thread-Liste für eine Kategorie
    $stmt = $db->prepare("SELECT * FROM forum_categories WHERE id = ?");
    $stmt->execute([$catId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$category) { header('Location: /forum.php'); exit; }

    $tcStmt = $db->prepare("SELECT COUNT(*) FROM forum_threads WHERE category_id = ?");
    $tcStmt->execute([$catId]);
    $totalThreads = (int)$tcStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare("
        SELECT t.*,
               u.username AS author,
               (SELECT COUNT(*) FROM forum_posts p WHERE p.thread_id = t.id) AS post_count,
               (SELECT p2.created_at FROM forum_posts p2 WHERE p2.thread_id = t.id ORDER BY p2.created_at DESC LIMIT 1) AS last_post_at,
               (SELECT u2.username FROM forum_posts p3 JOIN users u2 ON u2.id = p3.user_id WHERE p3.thread_id = t.id ORDER BY p3.created_at DESC LIMIT 1) AS last_poster
        FROM forum_threads t
        JOIN users u ON u.id = t.user_id
        WHERE t.category_id = ?
        ORDER BY t.last_post_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$catId, $perPage, $offset]);
    $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Kategorienübersicht
    $categories = $db->query("
        SELECT c.*,
            COALESCE((SELECT COUNT(*) FROM forum_threads t WHERE t.category_id = c.id), 0) AS thread_count,
            COALESCE((SELECT COUNT(*) FROM forum_posts p JOIN forum_threads t ON t.id = p.thread_id WHERE t.category_id = c.id), 0) AS post_count
        FROM forum_categories c
        ORDER BY c.sort_order, c.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Letzter Post pro Kategorie
    $lastPosts = [];
    $lpStmt = $db->query("
        SELECT t.category_id, p.created_at, u.username, t.title AS thread_title, t.id AS thread_id
        FROM forum_posts p
        JOIN forum_threads t ON t.id = p.thread_id
        JOIN users u ON u.id = p.user_id
        WHERE p.id IN (
            SELECT MAX(p2.id) FROM forum_posts p2
            JOIN forum_threads t2 ON t2.id = p2.thread_id
            GROUP BY t2.category_id
        )
    ");
    foreach ($lpStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lastPosts[$row['category_id']] = $row;
    }
}

$totalPages = $catId > 0 ? (int)ceil($totalThreads / $perPage) : 1;

function fmtDate(string $ts): string {
    $d = new DateTime($ts);
    $now = new DateTime();
    $diff = $now->diff($d);
    if ($diff->days === 0) {
        if ($diff->h === 0) return 'vor ' . max(1, $diff->i) . ' Min.';
        return 'vor ' . $diff->h . ' Std.';
    }
    if ($diff->days === 1) return 'Gestern ' . $d->format('H:i');
    return $d->format('d.m.Y H:i');
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="container mt-5 pt-4" style="max-width:900px;">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb" style="background:none; padding:0; font-size:.85rem;">
            <li class="breadcrumb-item"><a href="/forum.php" style="color:#e8b84b;">Forum</a></li>
            <?php if ($category): ?>
            <li class="breadcrumb-item active" style="color:rgba(255,255,255,.6);"><?= e($category['name']) ?></li>
            <?php endif; ?>
        </ol>
    </nav>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0 fw-bold text-white" style="font-size:1.6rem;">
            <i class="bi bi-chat-square-text-fill me-2" style="color:#e8b84b;"></i>
            <?= $category ? e($category['name']) : 'Forum' ?>
        </h1>
        <?php if ($catId > 0): ?>
        <a href="/forum-new-thread.php?cat=<?= $catId ?>" class="btn btn-sm"
           style="background:#e8b84b; color:#0a192f; font-weight:700;">
            <i class="bi bi-plus-lg me-1"></i>Neues Thema
        </a>
        <?php endif; ?>
    </div>

    <?php if ($category): ?>
    <!-- ── Thread-Liste ──────────────────────────────────────────────────────── -->
    <?php if (empty($threads)): ?>
        <div class="text-center py-5" style="color:rgba(255,255,255,.4);">
            <i class="bi bi-chat-square-dots" style="font-size:3rem;"></i>
            <p class="mt-3">Noch keine Themen in dieser Kategorie. Starte das erste!</p>
            <a href="/forum-new-thread.php?cat=<?= $catId ?>" class="btn btn-sm mt-2"
               style="background:#e8b84b; color:#0a192f; font-weight:700;">
                <i class="bi bi-plus-lg me-1"></i>Neues Thema erstellen
            </a>
        </div>
    <?php else: ?>
        <div style="border:1px solid rgba(255,255,255,.1); border-radius:10px; overflow:hidden;">
            <?php foreach ($threads as $i => $t): ?>
            <div class="d-flex align-items-center gap-3 px-4 py-3 <?= $i % 2 === 0 ? '' : '' ?>"
                 style="background:rgba(255,255,255,<?= $i % 2 === 0 ? '.04' : '.02' ?>);
                        border-bottom:1px solid rgba(255,255,255,.07);">
                <!-- Icon -->
                <div style="flex-shrink:0; width:36px; text-align:center;">
                    <?php if ($t['locked']): ?>
                        <i class="bi bi-lock-fill" style="color:rgba(255,255,255,.3); font-size:1.1rem;"></i>
                    <?php else: ?>
                        <i class="bi bi-chat-left-text" style="color:#e8b84b; font-size:1.1rem;"></i>
                    <?php endif; ?>
                </div>
                <!-- Titel + Autor -->
                <div style="flex:1; min-width:0;">
                    <a href="/forum-thread.php?id=<?= $t['id'] ?>"
                       class="text-decoration-none fw-semibold"
                       style="color:<?= $t['locked'] ? 'rgba(255,255,255,.5)' : '#fff' ?>; display:block;
                              white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?= e($t['title']) ?>
                    </a>
                    <span style="font-size:.78rem; color:rgba(255,255,255,.35);">
                        von <?= e($t['author']) ?> &middot; <?= fmtDate($t['created_at']) ?>
                    </span>
                </div>
                <!-- Statistiken -->
                <div class="d-none d-md-flex gap-4 text-end" style="flex-shrink:0; font-size:.8rem;">
                    <div>
                        <div style="color:rgba(255,255,255,.35); font-size:.7rem;">Antworten</div>
                        <div style="color:#fff; font-weight:600;"><?= max(0, (int)$t['post_count'] - 1) ?></div>
                    </div>
                    <div>
                        <div style="color:rgba(255,255,255,.35); font-size:.7rem;">Aufrufe</div>
                        <div style="color:#fff; font-weight:600;"><?= (int)$t['views'] ?></div>
                    </div>
                </div>
                <!-- Letzter Post -->
                <div class="d-none d-lg-block text-end" style="flex-shrink:0; min-width:130px; font-size:.75rem;">
                    <?php if ($t['last_post_at']): ?>
                        <div style="color:rgba(255,255,255,.35);"><?= fmtDate($t['last_post_at']) ?></div>
                        <div style="color:rgba(255,255,255,.5);">von <?= e($t['last_poster'] ?? $t['author']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Paginierung -->
        <?php if ($totalPages > 1): ?>
        <nav class="mt-4 d-flex justify-content-center">
            <ul class="pagination pagination-sm">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="/forum.php?cat=<?= $catId ?>&page=<?= $p ?>"
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
    <?php endif; ?>

    <?php else: ?>
    <!-- ── Kategorienübersicht ──────────────────────────────────────────────── -->
    <div style="border:1px solid rgba(255,255,255,.1); border-radius:10px; overflow:hidden;">
        <?php foreach ($categories as $cat): ?>
        <div class="d-flex align-items-center gap-4 px-4 py-4"
             style="border-bottom:1px solid rgba(255,255,255,.07);
                    background:rgba(255,255,255,.03);">
            <!-- Icon -->
            <div class="d-none d-md-flex align-items-center justify-content-center"
                 style="width:44px; height:44px; flex-shrink:0;
                        background:rgba(232,184,75,.12); border-radius:10px;">
                <i class="bi bi-chat-square-text-fill" style="color:#e8b84b; font-size:1.2rem;"></i>
            </div>
            <!-- Name + Beschreibung -->
            <div style="flex:1; min-width:0;">
                <a href="/forum.php?cat=<?= $cat['id'] ?>"
                   class="text-decoration-none fw-bold"
                   style="color:#fff; font-size:1.05rem;"><?= e($cat['name']) ?></a>
                <div style="font-size:.82rem; color:rgba(255,255,255,.45); margin-top:2px;">
                    <?= e($cat['description']) ?>
                </div>
            </div>
            <!-- Zähler -->
            <div class="d-none d-md-flex gap-4 text-center" style="flex-shrink:0;">
                <div>
                    <div style="color:#fff; font-weight:700; font-size:1rem;"><?= (int)$cat['thread_count'] ?></div>
                    <div style="color:rgba(255,255,255,.35); font-size:.72rem;">Themen</div>
                </div>
                <div>
                    <div style="color:#fff; font-weight:700; font-size:1rem;"><?= (int)$cat['post_count'] ?></div>
                    <div style="color:rgba(255,255,255,.35); font-size:.72rem;">Beiträge</div>
                </div>
            </div>
            <!-- Letzter Beitrag -->
            <div class="d-none d-lg-block text-end" style="flex-shrink:0; min-width:160px; font-size:.75rem;">
                <?php if (isset($lastPosts[$cat['id']])): $lp = $lastPosts[$cat['id']]; ?>
                    <div style="color:rgba(255,255,255,.35);"><?= fmtDate($lp['created_at']) ?></div>
                    <div style="color:rgba(255,255,255,.5);">von <?= e($lp['username']) ?></div>
                    <a href="/forum-thread.php?id=<?= $lp['thread_id'] ?>"
                       class="text-decoration-none"
                       style="color:#e8b84b; font-size:.7rem; display:block;
                              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px;">
                        <?= e($lp['thread_title']) ?>
                    </a>
                <?php else: ?>
                    <span style="color:rgba(255,255,255,.2);">Noch kein Beitrag</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Gesamtstatistiken -->
    <?php
    $totalT = array_sum(array_column($categories, 'thread_count'));
    $totalP = array_sum(array_column($categories, 'post_count'));
    $totalU = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM forum_posts")->fetchColumn();
    ?>
    <div class="mt-4 d-flex gap-4 justify-content-end" style="font-size:.82rem; color:rgba(255,255,255,.4);">
        <span><strong style="color:rgba(255,255,255,.7);"><?= $totalT ?></strong> Themen</span>
        <span><strong style="color:rgba(255,255,255,.7);"><?= $totalP ?></strong> Beiträge</span>
        <span><strong style="color:rgba(255,255,255,.7);"><?= $totalU ?></strong> aktive Mitglieder</span>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
