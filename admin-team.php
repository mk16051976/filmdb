<?php
$pageTitle = 'Team bearbeiten – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if (!isAdmin()) {
    header('Location: /index.php');
    exit;
}

$db  = getDB();
$msg = '';
$msgType = 'success';

// ── Tabelle sicherstellen ─────────────────────────────────────────────────
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

// ── POST-Aktionen ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $data  = [
            'sort_order'   => (int)($_POST['sort_order'] ?? 0),
            'initials'     => mb_substr(trim($_POST['initials'] ?? ''), 0, 5),
            'avatar_color' => preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['avatar_color'] ?? '') ? $_POST['avatar_color'] : '#1e3a5f',
            'name'         => mb_substr(trim($_POST['name'] ?? ''), 0, 100),
            'role'         => mb_substr(trim($_POST['role'] ?? ''), 0, 100),
            'bio'          => trim($_POST['bio'] ?? ''),
            'tags'         => trim($_POST['tags'] ?? ''),
        ];
        if ($id > 0) {
            $st = $db->prepare("UPDATE team_members SET sort_order=?,initials=?,avatar_color=?,name=?,role=?,bio=?,tags=? WHERE id=?");
            $st->execute([...$data, $id]);
            $msg = 'Mitglied aktualisiert.';
        } else {
            $st = $db->prepare("INSERT INTO team_members (sort_order,initials,avatar_color,name,role,bio,tags) VALUES (?,?,?,?,?,?,?)");
            $st->execute(array_values($data));
            $msg = 'Mitglied hinzugefügt.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM team_members WHERE id=?")->execute([$id]);
            $msg = 'Mitglied gelöscht.';
        }
    }
}

$members = $db->query("SELECT * FROM team_members ORDER BY sort_order ASC")->fetchAll();
$editId  = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $st = $db->prepare("SELECT * FROM team_members WHERE id=?");
    $st->execute([$editId]);
    $editRow = $st->fetch();
}

require_once __DIR__ . '/includes/header.php';
?>
<main class="container py-5" style="max-width:860px;">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="/team.php" class="btn btn-sm btn-outline-secondary">← Zurück</a>
        <h1 class="mb-0" style="font-size:1.6rem; font-weight:800; color:#e8b84b;">
            <i class="bi bi-people-fill me-2"></i>Team bearbeiten
        </h1>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'danger' ?> py-2 mb-4"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- ── Formular: Neu / Bearbeiten ───────────────────────────────────── -->
    <div class="card mb-5" style="background:#1a3a6e; border:1px solid rgba(255,255,255,.1); border-radius:12px;">
        <div class="card-body p-4">
            <h5 class="mb-3" style="color:#e8b84b;"><?= $editRow ? 'Mitglied bearbeiten' : 'Neues Mitglied' ?></h5>
            <form method="post">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : 0 ?>">
                <div class="row g-3">
                    <div class="col-md-1">
                        <label class="form-label text-white-50 small">Reihenfolge</label>
                        <input type="number" name="sort_order" class="form-control form-control-sm bg-dark text-white border-secondary"
                               value="<?= $editRow ? (int)$editRow['sort_order'] : (count($members)+1) ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label text-white-50 small">Kürzel</label>
                        <input type="text" name="initials" class="form-control form-control-sm bg-dark text-white border-secondary" maxlength="5"
                               value="<?= htmlspecialchars($editRow['initials'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label text-white-50 small">Farbe</label>
                        <input type="color" name="avatar_color" class="form-control form-control-color form-control-sm border-secondary"
                               value="<?= htmlspecialchars($editRow['avatar_color'] ?? '#1e3a5f') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-white-50 small">Name *</label>
                        <input type="text" name="name" class="form-control form-control-sm bg-dark text-white border-secondary"
                               value="<?= htmlspecialchars($editRow['name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-white-50 small">Rolle</label>
                        <input type="text" name="role" class="form-control form-control-sm bg-dark text-white border-secondary"
                               value="<?= htmlspecialchars($editRow['role'] ?? '') ?>"
                               placeholder="z.B. Entwickler & Filmliebhaber">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-white-50 small">Tags (kommagetrennt)</label>
                        <input type="text" name="tags" class="form-control form-control-sm bg-dark text-white border-secondary"
                               value="<?= htmlspecialchars($editRow['tags'] ?? '') ?>"
                               placeholder="PHP,MySQL,Bootstrap 5">
                    </div>
                    <div class="col-12">
                        <label class="form-label text-white-50 small">Bio / Beschreibung</label>
                        <textarea name="bio" class="form-control form-control-sm bg-dark text-white border-secondary" rows="3"
                                  placeholder="Kurze Beschreibung…"><?= htmlspecialchars($editRow['bio'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm" style="background:#e8b84b; color:#0a1a2e; font-weight:700;">
                        <?= $editRow ? 'Speichern' : 'Hinzufügen' ?>
                    </button>
                    <?php if ($editRow): ?>
                    <a href="/admin-team.php" class="btn btn-sm btn-outline-secondary">Abbrechen</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Übersicht ────────────────────────────────────────────────────── -->
    <h5 class="mb-3" style="color:#e8b84b;">Aktuelle Mitglieder</h5>
    <div class="row g-3">
        <?php foreach ($members as $m): ?>
        <div class="col-md-6">
            <div class="d-flex align-items-start gap-3 p-3" style="background:#1a3a6e; border:1px solid rgba(255,255,255,.1); border-radius:10px;">
                <div style="width:48px; height:48px; border-radius:50%; background:linear-gradient(135deg,<?= htmlspecialchars($m['avatar_color']) ?>,#0a1f3c); display:flex; align-items:center; justify-content:center; font-weight:900; color:#e8b84b; font-size:1rem; flex-shrink:0;">
                    <?= htmlspecialchars($m['initials']) ?>
                </div>
                <div class="flex-grow-1" style="min-width:0;">
                    <div class="fw-bold text-white"><?= htmlspecialchars($m['name']) ?></div>
                    <div class="text-white-50 small"><?= htmlspecialchars($m['role']) ?></div>
                    <?php if ($m['tags']): ?>
                    <div class="mt-1">
                        <?php foreach (explode(',', $m['tags']) as $t): ?>
                        <span class="badge bg-secondary me-1" style="font-size:.65rem;"><?= htmlspecialchars(trim($t)) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2 flex-shrink-0">
                    <a href="?edit=<?= $m['id'] ?>" class="btn btn-sm btn-outline-warning py-1 px-2" style="font-size:.75rem;">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form method="post" onsubmit="return confirm('Mitglied löschen?');" class="d-inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $m['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" style="font-size:.75rem;">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
