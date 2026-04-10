<?php
$pageTitle = 'Das Team – MKFB';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

// Team-Tabelle sicherstellen & Daten laden
$db->exec("CREATE TABLE IF NOT EXISTS team_members (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sort_order   INT          NOT NULL DEFAULT 0,
    initials     VARCHAR(5)   NOT NULL DEFAULT '',
    avatar_color VARCHAR(7)   NOT NULL DEFAULT '#1e3a5f',
    name         VARCHAR(100) NOT NULL DEFAULT '',
    role         VARCHAR(100) NOT NULL DEFAULT '',
    bio          TEXT,
    tags         TEXT,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$cnt = (int)$db->query("SELECT COUNT(*) FROM team_members")->fetchColumn();
if ($cnt === 0) {
    $ins = $db->prepare("INSERT INTO team_members (sort_order,initials,avatar_color,name,role,bio,tags) VALUES (?,?,?,?,?,?,?)");
    $ins->execute([1,'MK','#1e3a5f','Markus Kogler','Entwickler & Filmliebhaber','MKFB ist ein Hobbyprojekt, das aus der Leidenschaft für Filme und der Frage „Welcher Film ist wirklich besser?" entstanden ist. Entwickelt mit PHP, MySQL und viel Kaffee.','PHP,MySQL,Bootstrap 5,ELO-System,TMDB API']);
    $ins->execute([2,'JH','#2d5a27','Jonas Halmschlag','','','']);
    $ins->execute([3,'JB','#5a2d27','Joscha Burkholz','','','']);
    $ins->execute([4,'LK','#27405a','Lorna Kogler','','','']);
}
$teamMembers = $db->query("SELECT * FROM team_members ORDER BY sort_order ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<main style="background:#0e2240; min-height:100vh; padding: 4rem 0;">
    <div class="container">
        <div class="text-center mb-5">
            <span class="badge bg-gold text-dark fw-semibold mb-3 px-3 py-2">Das Team</span>
            <h2 class="fw-bold text-white mb-2" style="font-size:2.2rem;">Hinter MKFB</h2>
            <p class="text-light opacity-50">
                <?= count($teamMembers) === 1 ? 'Das Projekt in einer Person' : 'Die Menschen hinter dem Projekt' ?>
            </p>
        </div>

        <div class="row g-4 justify-content-center">
            <?php foreach ($teamMembers as $tm): ?>
            <div class="col-lg-3 col-md-6">
                <div class="team-card h-100">
                    <div class="team-avatar" style="background:linear-gradient(135deg, <?= htmlspecialchars($tm['avatar_color']) ?>, #0a1f3c);">
                        <?= htmlspecialchars($tm['initials']) ?>
                    </div>
                    <h3 class="fw-bold text-center mb-1"><?= htmlspecialchars($tm['name']) ?></h3>
                    <?php if (!empty($tm['role'])): ?>
                    <p class="text-muted text-center small mb-3"><?= htmlspecialchars($tm['role']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($tm['bio'])): ?>
                    <p class="text-muted text-center mb-3" style="font-size:.9rem;"><?= htmlspecialchars($tm['bio']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($tm['tags'])): ?>
                    <div class="text-center">
                        <?php foreach (explode(',', $tm['tags']) as $tag): ?>
                        <span class="badge bg-light text-dark me-1 mb-1"><?= htmlspecialchars(trim($tag)) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (isAdmin()): ?>
        <div class="text-center mt-4">
            <a href="/admin-team.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i>Team bearbeiten
            </a>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
