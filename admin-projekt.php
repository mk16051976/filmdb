<?php
$pageTitle = 'Projekt-Slider – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if (!isAdmin()) {
    header('Location: /index.php');
    exit;
}

$db      = getDB();
$curUser = (int)$_SESSION['user_id'];
$msg     = '';
$msgType = 'success';

// Upload-Verzeichnis sicherstellen
if (!is_dir(__DIR__ . '/uploads/slides')) {
    mkdir(__DIR__ . '/uploads/slides', 0755, true);
}

// ── Tabelle sicherstellen ─────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS project_slides (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phase_label VARCHAR(50)  NOT NULL DEFAULT '',
    title       VARCHAR(200) NOT NULL,
    description TEXT         NOT NULL,
    icon        VARCHAR(80)  NOT NULL DEFAULT 'bi-circle',
    pills       TEXT         NULL,
    image_path  VARCHAR(300) NULL,
    image_as_bg TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Standard-Slides beim ersten Aufruf anlegen
if ((int)$db->query("SELECT COUNT(*) FROM project_slides")->fetchColumn() === 0) {
    $ins = $db->prepare("INSERT INTO project_slides (phase_label, title, description, icon, pills, sort_order) VALUES (?,?,?,?,?,?)");
    $ins->execute(['Phase I', 'Anmeldung & Registrierung',
        'Erstelle dein kostenloses Konto und tauche in die Welt der Filmbewertungen ein. Im Demo-Modus kannst du die Duell-Mechanik bereits ausprobieren, bevor du richtig loslegst.',
        'bi-person-check',
        "bi-person-plus|Kostenlose Registrierung\nbi-play-circle|Demo-Modus verfügbar\nbi-collection-play|Filmdatenbank einsehen\nbi-bar-chart|Community-Ranglisten ansehen",
        1]);
    $ins->execute(['Phase II', 'Sichtungsturnier',
        'Duelliere mindestens 1024 Filme im effizienten Turnierbaumsystem. Wenige Duelle genügen, um eine grobe Reihenfolge herzustellen und deine persönlichen Vorlieben zu entdecken.',
        'bi-diagram-3',
        "bi-diagram-3|Turnierbaum-System\nbi-film|Mind. 1024 Filme\nbi-shuffle|Grobe Ersteinschätzung\nbi-arrow-right-circle|Freischaltung Phase III",
        2]);
    $ins->execute(['Phase III', 'Jeder gegen Jeden',
        'Die Top 128 Filme deines Sichtungsturniers treten in einem Rundensystem gegeneinander an. Jeder Film trifft auf jeden anderen – die Siege/Niederlagen-Quote bestimmt das Ranking.',
        'bi-people-fill',
        "bi-people-fill|Jeder-gegen-Jeden-Modus\nbi-film|Top 128 aus dem Turnier\nbi-trophy|Erste persönliche Rangliste\nbi-arrow-right-circle|Freischaltung Phase IV",
        3]);
    $ins->execute(['Phase IV', 'Kompletter Zugang',
        'Die gesamte Plattform steht dir offen. Nutze alle Bewertungsmodi inklusive Sortieren, vergleiche deine Rangliste mit der Community und entdecke gemeinsam den ultimativen Lieblingsfilm.',
        'bi-stars',
        "bi-unlock|Alle Features freigeschaltet\nbi-sort-numeric-down|Sortieren freigeschaltet\nbi-bar-chart-line|Persönliche Statistiken\nbi-people-fill|Community-Ranglisten",
        4]);
}

// Pfad-Migration: /filmdb/uploads/… → /uploads/…
try { $db->exec("UPDATE project_slides SET image_path = REPLACE(image_path, '/filmdb/uploads/', '/uploads/') WHERE image_path LIKE '/filmdb/uploads/%'"); } catch (\PDOException $e) {}

// ── Bild-Upload-Helper ────────────────────────────────────────────────────────
function handleSlideImageUpload(): ?string {
    if (empty($_FILES['image']['name'])) return null;
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;

    $extMap  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime    = '';
    try {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = (string)finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    } catch (\Throwable $e) {}
    // Fallback: Dateierweiterung (wenn finfo nicht verfügbar)
    if ($mime === '') {
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = array_flip(['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'])[$ext] ?? '';
    }
    if (!isset($extMap[$mime])) return null;

    $name = 'slide_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
    $dest = __DIR__ . '/uploads/slides/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    return '/uploads/slides/' . $name;
}

function deleteSlideImage(string $path): void {
    // Nur Dateien im erlaubten Upload-Verzeichnis löschen — kein path traversal möglich
    $base     = realpath(__DIR__ . '/uploads/slides');
    $filename = basename($path); // Nur Dateiname, kein Pfad
    if (!$base || $filename === '' || $filename === '.' || $filename === '..') return;
    // Dateiname muss dem erwarteten Muster entsprechen
    if (!preg_match('/^slide_[0-9]+_[a-f0-9]+\.(jpg|png|gif|webp)$/i', $filename)) return;
    $full = $base . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($full)) @unlink($full);
}

// ── POST-Handler ──────────────────────────────────────────────────────────────
// Erkennen wenn post_max_size überschritten (dann ist $_POST leer)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
    $max = (int)ini_get('post_max_size');
    $msg = 'Datei zu groß – Server-Limit: ' . $max . ' MB. Bitte kleineres Bild verwenden.';
    header('Location: /admin-projekt.php?msg=' . urlencode($msg) . '&type=danger');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $label   = trim($_POST['phase_label'] ?? '');
        $title   = trim($_POST['title']       ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $icon    = trim($_POST['icon']        ?? 'bi-circle');
        $pills   = trim($_POST['pills']       ?? '');
        $asBg    = isset($_POST['image_as_bg']) ? 1 : 0;
        $imgPath = handleSlideImageUpload();
        if ($title !== '') {
            $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) FROM project_slides")->fetchColumn();
            $db->prepare("INSERT INTO project_slides (phase_label, title, description, icon, pills, image_path, image_as_bg, sort_order)
                          VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$label, $title, $desc, $icon, $pills ?: null, $imgPath, $asBg, $maxOrder + 1]);
            $msg = 'Slide erstellt.';
        } else {
            $msg = 'Titel ist erforderlich.'; $msgType = 'danger';
        }
        header('Location: /admin-projekt.php?msg=' . urlencode($msg) . '&type=' . $msgType);
        exit;
    }

    if ($action === 'update') {
        $slideId = (int)($_POST['slide_id']    ?? 0);
        $label   = trim($_POST['phase_label']  ?? '');
        $title   = trim($_POST['title']        ?? '');
        $desc    = trim($_POST['description']  ?? '');
        $icon    = trim($_POST['icon']         ?? 'bi-circle');
        $pills   = trim($_POST['pills']        ?? '');
        $asBg    = isset($_POST['image_as_bg']) ? 1 : 0;
        if ($slideId > 0 && $title !== '') {
            $newImg = handleSlideImageUpload();
            if ($newImg !== null) {
                $old = $db->prepare("SELECT image_path FROM project_slides WHERE id=?");
                $old->execute([$slideId]);
                $oldPath = $old->fetchColumn();
                if ($oldPath) deleteSlideImage($oldPath);
                $db->prepare("UPDATE project_slides SET phase_label=?, title=?, description=?, icon=?, pills=?, image_path=?, image_as_bg=?, updated_at=NOW() WHERE id=?")
                   ->execute([$label, $title, $desc, $icon, $pills ?: null, $newImg, $asBg, $slideId]);
            } else {
                $db->prepare("UPDATE project_slides SET phase_label=?, title=?, description=?, icon=?, pills=?, image_as_bg=?, updated_at=NOW() WHERE id=?")
                   ->execute([$label, $title, $desc, $icon, $pills ?: null, $asBg, $slideId]);
            }
            $msg = 'Slide aktualisiert.';
        }
        header('Location: /admin-projekt.php?msg=' . urlencode($msg) . '&type=success');
        exit;
    }

    if ($action === 'remove_image') {
        $slideId = (int)($_POST['slide_id'] ?? 0);
        if ($slideId > 0) {
            $old = $db->prepare("SELECT image_path FROM project_slides WHERE id=?");
            $old->execute([$slideId]);
            $oldPath = $old->fetchColumn();
            if ($oldPath) deleteSlideImage($oldPath);
            $db->prepare("UPDATE project_slides SET image_path=NULL, image_as_bg=0 WHERE id=?")->execute([$slideId]);
            $msg = 'Bild entfernt.';
        }
        header('Location: /admin-projekt.php?edit=' . $slideId . '&msg=' . urlencode($msg) . '&type=success');
        exit;
    }

    if ($action === 'delete') {
        $slideId = (int)($_POST['slide_id'] ?? 0);
        if ($slideId > 0) {
            $old = $db->prepare("SELECT image_path FROM project_slides WHERE id=?");
            $old->execute([$slideId]);
            $oldPath = $old->fetchColumn();
            if ($oldPath) deleteSlideImage($oldPath);
            $db->prepare("DELETE FROM project_slides WHERE id=?")->execute([$slideId]);
            $msg = 'Slide gelöscht.';
        }
        header('Location: /admin-projekt.php?msg=' . urlencode($msg) . '&type=success');
        exit;
    }

    if ($action === 'move') {
        $slideId   = (int)($_POST['slide_id']  ?? 0);
        $direction = $_POST['direction'] ?? '';
        if ($slideId > 0 && in_array($direction, ['up', 'down'], true)) {
            $cur = $db->prepare("SELECT sort_order FROM project_slides WHERE id=?");
            $cur->execute([$slideId]);
            $curOrder = (int)$cur->fetchColumn();
            if ($direction === 'up') {
                $nb = $db->prepare("SELECT id, sort_order FROM project_slides WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1");
            } else {
                $nb = $db->prepare("SELECT id, sort_order FROM project_slides WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1");
            }
            $nb->execute([$curOrder]);
            $neighbor = $nb->fetch(PDO::FETCH_ASSOC);
            if ($neighbor) {
                $db->prepare("UPDATE project_slides SET sort_order=? WHERE id=?")->execute([$neighbor['sort_order'], $slideId]);
                $db->prepare("UPDATE project_slides SET sort_order=? WHERE id=?")->execute([$curOrder, $neighbor['id']]);
            }
        }
        header('Location: /admin-projekt.php');
        exit;
    }
}

// ── Flash-Message ─────────────────────────────────────────────────────────────
if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

// ── Daten laden ───────────────────────────────────────────────────────────────
$slides = $db->query("SELECT * FROM project_slides ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$totalSlides = count($slides);

// Edit-Modus
$editSlide = null;
if (isset($_GET['edit']) && (int)$_GET['edit'] > 0) {
    $stmt = $db->prepare("SELECT * FROM project_slides WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editSlide = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$isNew = isset($_GET['new']);

// ID des letzten Slides (höchste sort_order) – bekommt Onboarding-Buttons
$lastSlideId = !empty($slides) ? (int)end($slides)['id'] : 0;

require_once __DIR__ . '/includes/header.php';
?>

<main style="padding-top:6px; background:#14325a; min-height:100vh;">

    <!-- Sub-Nav -->
    <div style="background:#182f5a; border-bottom:1px solid rgba(255,255,255,.06);">
        <div class="container">
            <div class="d-flex gap-4 align-items-center" style="height:44px;">
                <a href="/admin-benutzer.php"
                   style="color:rgba(255,255,255,.5); text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid transparent;">
                    <i class="bi bi-people me-1"></i>Benutzer
                </a>
                <a href="/admin-statistiken.php"
                   style="color:rgba(255,255,255,.5); text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid transparent;">
                    <i class="bi bi-bar-chart me-1"></i>Statistiken
                </a>
                <a href="/admin-news.php"
                   style="color:rgba(255,255,255,.5); text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid transparent;">
                    <i class="bi bi-newspaper me-1"></i>News
                </a>
                <a href="/admin-projekt.php"
                   style="color:#e8b84b; text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid #e8b84b; font-weight:600;">
                    <i class="bi bi-layers me-1"></i>Projekt
                </a>
            </div>
        </div>
    </div>

    <div class="container py-5">

        <?php if ($msg): ?>
        <div class="alert alert-<?= e($msgType) ?> alert-dismissible fade show mb-4" role="alert">
            <?= e($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($editSlide || $isNew): ?>
        <!-- ── EDIT / CREATE FORM ───────────────────────────────────────────── -->
        <?php $s = $editSlide ?? []; ?>
        <div class="d-flex align-items-center gap-3 mb-4">
            <a href="/admin-projekt.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Zurück
            </a>
            <h4 class="text-white fw-bold mb-0">
                <?= $isNew ? 'Neuer Slide' : 'Slide bearbeiten' ?>
            </h4>
            <?php if (!$isNew && $editSlide && (int)$editSlide['id'] === $lastSlideId): ?>
            <span class="badge ms-1" style="background:rgba(232,184,75,.18); color:#e8b84b; border:1px solid rgba(232,184,75,.35); font-size:.75rem; font-weight:600; padding:.35em .7em;">
                <i class="bi bi-box-arrow-in-right me-1"></i>Onboarding-Abschluss-Slide
            </span>
            <?php endif; ?>
        </div>

        <?php if (!$isNew && $editSlide && (int)$editSlide['id'] === $lastSlideId): ?>
        <div class="alert d-flex gap-2 align-items-start mb-4 py-2 px-3"
             style="background:rgba(232,184,75,.08); border:1px solid rgba(232,184,75,.25); border-radius:10px; color:rgba(255,255,255,.8);">
            <i class="bi bi-info-circle-fill flex-shrink-0 mt-1" style="color:#e8b84b;"></i>
            <div class="small">
                <strong style="color:#e8b84b;">Dieser Slide ist der letzte im Slider.</strong><br>
                Beim ersten Login werden darunter automatisch die Buttons
                <em>„Sichtungsturnier starten"</em> und <em>„Offene Website"</em> eingeblendet.
                Titel, Beschreibung, Pills und Bild kannst du hier wie gewohnt bearbeiten.
            </div>
        </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div style="background:#1a3566; border:1px solid rgba(255,255,255,.07); border-radius:16px; padding:2rem;">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="<?= $isNew ? 'create' : 'update' ?>">
                        <?php if (!$isNew): ?>
                        <input type="hidden" name="slide_id" value="<?= (int)$s['id'] ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label text-light small">Phase-Label</label>
                                <input type="text" name="phase_label" class="form-control form-control-sm bg-dark text-white border-secondary"
                                       placeholder="z.B. Phase I"
                                       value="<?= e($s['phase_label'] ?? '') ?>">
                                <div class="form-text text-secondary">Wird klein über dem Titel angezeigt</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-light small">Icon <span class="text-danger">*</span></label>
                                <input type="text" name="icon" class="form-control form-control-sm bg-dark text-white border-secondary"
                                       placeholder="bi-person-check"
                                       value="<?= e($s['icon'] ?? 'bi-circle') ?>">
                                <div class="form-text text-secondary">
                                    Bootstrap-Icon-Klasse –
                                    <a href="https://icons.getbootstrap.com" target="_blank" class="text-warning">icons.getbootstrap.com</a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-light small" style="visibility:hidden;">Preview</label>
                                <div class="d-flex align-items-center gap-2 p-2" style="background:rgba(232,184,75,.08); border:1px solid rgba(232,184,75,.2); border-radius:8px; height:38px;">
                                    <i id="iconPreview" class="<?= e($s['icon'] ?? 'bi-circle') ?> text-warning fs-5"></i>
                                    <span class="text-secondary small">Vorschau</span>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label text-light small">Titel <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control bg-dark text-white border-secondary"
                                       placeholder="Slide-Titel"
                                       value="<?= e($s['title'] ?? '') ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label text-light small">Beschreibung</label>
                                <textarea name="description" rows="4"
                                          class="form-control bg-dark text-white border-secondary"
                                          placeholder="Kurze Beschreibung dieser Phase..."><?= e($s['description'] ?? '') ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="form-label text-light small">Feature-Pills</label>
                                <textarea name="pills" rows="5"
                                          class="form-control form-control-sm bg-dark text-white border-secondary"
                                          placeholder="bi-check-circle|Pill Text&#10;bi-star|Weiteres Feature&#10;Pill ohne Icon"><?= e($s['pills'] ?? '') ?></textarea>
                                <div class="form-text text-secondary">
                                    Eine Zeile pro Pill – Format: <code class="text-warning">bi-icon-klasse|Text</code> oder nur <code class="text-warning">Text</code>
                                </div>
                            </div>

                            <!-- Bild -->
                            <div class="col-12">
                                <label class="form-label text-light small">Bild</label>
                                <?php if (!empty($s['image_path'])): ?>
                                <div class="mb-2 d-flex align-items-center gap-3 p-2"
                                     style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:8px;">
                                    <img src="<?= e($s['image_path']) ?>" alt=""
                                         style="height:60px; width:100px; object-fit:cover; border-radius:6px;">
                                    <div class="flex-grow-1 small text-secondary">
                                        <?= e(basename($s['image_path'])) ?>
                                    </div>
                                    <form method="post" class="mb-0">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="action" value="remove_image">
                                        <input type="hidden" name="slide_id" value="<?= (int)$s['id'] ?>">
                                        <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Bild entfernen?')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                                <input type="file" name="image" accept="image/*"
                                       class="form-control form-control-sm bg-dark text-white border-secondary">
                                <div class="form-text text-secondary">JPG, PNG, WebP, GIF – max. 10 MB</div>
                            </div>

                            <?php if (!empty($s['image_path'])): ?>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="imgAsBg"
                                           name="image_as_bg" value="1"
                                           <?= !empty($s['image_as_bg']) ? 'checked' : '' ?>>
                                    <label class="form-check-label text-light small" for="imgAsBg">
                                        Bild als Slide-Hintergrund verwenden
                                    </label>
                                </div>
                                <div class="form-text text-secondary">
                                    Wenn aktiviert, wird das Bild als Vollbild-Hintergrund des Slides angezeigt (mit dunklem Overlay).
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="imgAsBg"
                                           name="image_as_bg" value="1" disabled>
                                    <label class="form-check-label text-secondary small" for="imgAsBg">
                                        Bild als Slide-Hintergrund (erst nach Bild-Upload verfügbar)
                                    </label>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-gold px-4">
                                <i class="bi bi-check-lg me-1"></i><?= $isNew ? 'Erstellen' : 'Speichern' ?>
                            </button>
                            <a href="/admin-projekt.php" class="btn btn-outline-secondary">Abbrechen</a>
                            <?php if (!$isNew): ?>
                            <a href="/das-projekt.php" target="_blank"
                               class="btn btn-outline-light btn-sm ms-auto" style="align-self:center;">
                                <i class="bi bi-eye me-1"></i>Vorschau
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- ── LISTE ──────────────────────────────────────────────────────────── -->
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h4 class="text-white fw-bold mb-0">Projekt-Slider</h4>
            <a href="/admin-projekt.php?new=1" class="btn btn-gold btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Neuer Slide
            </a>
        </div>

        <?php if (empty($slides)): ?>
        <div class="text-center py-5 text-secondary">
            <i class="bi bi-layers fs-1 d-block mb-3 opacity-25"></i>
            Noch keine Slides vorhanden.
        </div>
        <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($slides as $i => $s): ?>
            <div style="background:#1a3566; border:1px solid rgba(255,255,255,.07); border-radius:14px; overflow:hidden;"
                 class="d-flex align-items-stretch">

                <!-- Bild-Vorschau oder Icon -->
                <div style="width:100px; flex-shrink:0; background:rgba(232,184,75,.06);"
                     class="d-flex align-items-center justify-content-center">
                    <?php if (!empty($s['image_path'])): ?>
                    <img src="<?= e($s['image_path']) ?>" alt=""
                         style="width:100px; height:100%; object-fit:cover;">
                    <?php else: ?>
                    <i class="<?= e($s['icon']) ?> text-warning" style="font-size:1.8rem;"></i>
                    <?php endif; ?>
                </div>

                <!-- Inhalt -->
                <div class="flex-grow-1 p-3">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <?php if ($s['phase_label']): ?>
                        <span style="font-size:.7rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:rgba(232,184,75,.7);">
                            <?= e($s['phase_label']) ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($s['image_as_bg']) && !empty($s['image_path'])): ?>
                        <span class="badge" style="background:rgba(255,255,255,.08); color:rgba(255,255,255,.5); font-size:.65rem;">
                            <i class="bi bi-image me-1"></i>Bild-BG
                        </span>
                        <?php endif; ?>
                        <?php if ((int)$s['id'] === $lastSlideId): ?>
                        <span class="badge" style="background:rgba(232,184,75,.15); color:#e8b84b; border:1px solid rgba(232,184,75,.3); font-size:.65rem;">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Onboarding-Buttons
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="text-white fw-semibold"><?= e($s['title']) ?></div>
                    <?php if ($s['description']): ?>
                    <div class="text-secondary small mt-1" style="overflow:hidden; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical;">
                        <?= e($s['description']) ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Aktionen -->
                <div class="d-flex flex-column align-items-center justify-content-center gap-1 p-2" style="border-left:1px solid rgba(255,255,255,.05);">
                    <!-- Reihenfolge -->
                    <form method="post" class="mb-0">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action"    value="move">
                        <input type="hidden" name="slide_id"  value="<?= (int)$s['id'] ?>">
                        <input type="hidden" name="direction" value="up">
                        <button class="btn btn-sm p-1 lh-1" style="background:none; border:none; color:rgba(255,255,255,.3);"
                                title="Nach oben" <?= $i === 0 ? 'disabled' : '' ?>>
                            <i class="bi bi-chevron-up"></i>
                        </button>
                    </form>
                    <form method="post" class="mb-0">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action"    value="move">
                        <input type="hidden" name="slide_id"  value="<?= (int)$s['id'] ?>">
                        <input type="hidden" name="direction" value="down">
                        <button class="btn btn-sm p-1 lh-1" style="background:none; border:none; color:rgba(255,255,255,.3);"
                                title="Nach unten" <?= $i === $totalSlides - 1 ? 'disabled' : '' ?>>
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </form>
                </div>
                <div class="d-flex flex-column align-items-center justify-content-center gap-1 px-3"
                     style="border-left:1px solid rgba(255,255,255,.05);">
                    <a href="/admin-projekt.php?edit=<?= (int)$s['id'] ?>"
                       class="btn btn-sm"
                       style="background:rgba(232,184,75,.1); border:1px solid rgba(232,184,75,.2); color:#e8b84b; padding:.25rem .6rem; font-size:.78rem;">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form method="post" class="mb-0">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action"   value="delete">
                        <input type="hidden" name="slide_id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn-sm"
                                style="background:rgba(220,38,38,.08); border:1px solid rgba(220,38,38,.2); color:#ef4444; padding:.25rem .6rem; font-size:.78rem;"
                                onclick="return confirm('Slide wirklich löschen?')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </div>
</main>

<script>
// Icon-Vorschau
const iconInput   = document.querySelector('input[name="icon"]');
const iconPreview = document.getElementById('iconPreview');
if (iconInput && iconPreview) {
    iconInput.addEventListener('input', () => {
        iconPreview.className = iconInput.value.trim() + ' text-warning fs-5';
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
