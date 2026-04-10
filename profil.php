<?php
$pageTitle   = 'Mein Profil – MKFB';
$currentPage = 'profil';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(2);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Tabellen sicherstellen ───────────────────────────────────────────────────
$db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar VARCHAR(255) NULL DEFAULT NULL");
$db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS show_movies TINYINT(1) NOT NULL DEFAULT 1");
$db->exec("CREATE TABLE IF NOT EXISTS user_hidden_films (
    user_id  INT UNSIGNED NOT NULL,
    movie_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, movie_id),
    INDEX idx_uhf_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── AJAX: Film-Suche für Ausblenden ──────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_hide_film') {
    requireLogin();
    header('Content-Type: application/json; charset=utf-8');
    if (userPhase() < 4) { echo json_encode([]); exit; }
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode([]); exit; }
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $stmt = $db->prepare(
        "SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.imdb_id,
                (SELECT 1 FROM user_hidden_films WHERE user_id = ? AND movie_id = m.id) AS hidden
         FROM movies m
         WHERE (m.title LIKE ? OR m.title_en LIKE ?)
         ORDER BY m.title ASC LIMIT 12"
    );
    $stmt->execute([$userId, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(array_map(fn($r) => [
        'id'     => (int)$r['id'],
        'title'  => movieTitle($r),
        'year'   => (int)$r['year'],
        'poster' => moviePosterUrl($r, 'w92'),
        'hidden' => (bool)$r['hidden'],
    ], $rows));
    exit;
}

// ── AJAX: Film ausblenden / einblenden ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['hide_film','unhide_film'])) {
    requireLogin();
    header('Content-Type: application/json; charset=utf-8');
    if (userPhase() < 4) { echo json_encode(['ok' => false, 'error' => 'phase']); exit; }
    if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }
    $movieId = (int)($_POST['movie_id'] ?? 0);
    if (!$movieId) { echo json_encode(['ok' => false, 'error' => 'invalid']); exit; }
    if (($_POST['action']) === 'hide_film') {
        $db->prepare("INSERT IGNORE INTO user_hidden_films (user_id, movie_id) VALUES (?, ?)")
           ->execute([$userId, $movieId]);
    } else {
        $db->prepare("DELETE FROM user_hidden_films WHERE user_id = ? AND movie_id = ?")
           ->execute([$userId, $movieId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Avatar-Upload verarbeiten ─────────────────────────────────────────────────
$uploadError = '';
$uploadOk    = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
    if (!csrfValid()) {
        $uploadError = 'Ungültige Anfrage.';
    } else {
        $file    = $_FILES['avatar'];
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        // Serverseitige MIME-Prüfung via finfo (nicht $file['type'] — vom Client kontrollierbar)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $file['error'] === UPLOAD_ERR_OK ? finfo_file($finfo, $file['tmp_name']) : '';
        finfo_close($finfo);
        $ext = $allowed[$mime] ?? '';
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadError = 'Upload-Fehler.';
        } elseif ($ext === '') {
            $uploadError = 'Nur JPG, PNG, GIF oder WebP erlaubt.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $uploadError = 'Maximale Dateigröße: 2 MB.';
        } else {
            $dir = __DIR__ . '/uploads/avatars/';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            // Altes Avatar löschen
            $stOld = $db->prepare("SELECT avatar FROM users WHERE id = ?");
            $stOld->execute([$userId]);
            $oldAvatar = $stOld->fetchColumn();
            if ($oldAvatar && file_exists(__DIR__ . '/' . $oldAvatar)) {
                @unlink(__DIR__ . '/' . $oldAvatar);
            }
            $filename = 'avatars/' . $userId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], __DIR__ . '/uploads/' . $filename)) {
                $st = $db->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                $st->execute(['uploads/' . $filename, $userId]);
                $uploadOk = true;
                header('Location: /profil.php');
                exit;
            } else {
                $uploadError = 'Speichern fehlgeschlagen.';
            }
        }
    }
}

// ── Einstellungen speichern ───────────────────────────────────────────────────
$settingsSaved = false;
$settingsError = '';

// Direktfix via GET (z.B. nach CSRF-Problem): ?fix_series=1
if (isset($_GET['fix_series']) && (int)$_GET['fix_series'] === 1) {
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS show_series TINYINT(1) NOT NULL DEFAULT 1");
    $db->prepare("UPDATE users SET show_series = 1 WHERE id = ?")->execute([$userId]);
    header('Location: /profil.php?tab=einstellungen&saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    if (!csrfValid()) {
        $settingsError = 'Sicherheitstoken abgelaufen. Bitte die Seite neu laden und nochmal versuchen.';
    } else {
        // form_sent=1 ist immer gesetzt; Checkbox-Felder nur wenn aktiviert
        $showSeries = (isset($_POST['form_sent']) && isset($_POST['show_series'])) ? 1 : 0;
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS show_series TINYINT(1) NOT NULL DEFAULT 1");
        if (userPhase() >= 3) {
            $showMovies = (isset($_POST['form_sent']) && isset($_POST['show_movies'])) ? 1 : 0;
            $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS show_movies TINYINT(1) NOT NULL DEFAULT 1");
            $db->prepare("UPDATE users SET show_series = ?, show_movies = ? WHERE id = ?")
               ->execute([$showSeries, $showMovies, $userId]);
        } else {
            $db->prepare("UPDATE users SET show_series = ? WHERE id = ?")->execute([$showSeries, $userId]);
        }
        $settingsSaved = true;
        header('Location: /profil.php?tab=einstellungen&saved=1');
        exit;
    }
}

// ── User-Daten laden ──────────────────────────────────────────────────────────
$db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS show_series TINYINT(1) NOT NULL DEFAULT 1");
$stUser = $db->prepare("SELECT username, email, gender, nationality, birth_year,
                               favorite_genre, role, created_at, avatar, show_series, show_movies
                        FROM users WHERE id = ?");
$stUser->execute([$userId]);
$user = $stUser->fetch();

// ── Ausgeblendete Filme laden ─────────────────────────────────────────────────
$stHidden = $db->prepare(
    "SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.imdb_id
     FROM user_hidden_films h JOIN movies m ON m.id = h.movie_id
     WHERE h.user_id = ? ORDER BY m.title ASC"
);
$stHidden->execute([$userId]);
$hiddenFilms = $stHidden->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────────────────
$stDuels = $db->prepare("SELECT COUNT(*) FROM comparisons WHERE user_id = ?");
$stDuels->execute([$userId]);
$totalDuels = (int)$stDuels->fetchColumn();

$stFilms = $db->prepare("SELECT COUNT(DISTINCT movie_id) FROM user_ratings WHERE user_id = ? AND comparisons > 0");
$stFilms->execute([$userId]);
$totalFilms = (int)$stFilms->fetchColumn();

$stRanked = $db->prepare("SELECT COUNT(*) FROM user_position_ranking WHERE user_id = ?");
$stRanked->execute([$userId]);
$totalRanked = (int)$stRanked->fetchColumn();

$stActiveDays = $db->prepare("SELECT COUNT(DISTINCT DATE(created_at)) FROM comparisons WHERE user_id = ?");
$stActiveDays->execute([$userId]);
$activeDays = (int)$stActiveDays->fetchColumn();

// Community-Rang (nach Duelle)
$stRang = $db->prepare("SELECT COUNT(*) FROM (
    SELECT user_id, COUNT(*) AS cnt FROM comparisons GROUP BY user_id
) _t WHERE _t.cnt > ?");
$stRang->execute([$totalDuels]);
$communityRank = (int)$stRang->fetchColumn() + 1;

// Mitglied seit
$memberSince = $user['created_at'] ? date('d.m.Y', strtotime($user['created_at'])) : '–';
$memberYear  = $user['created_at'] ? date('Y', strtotime($user['created_at'])) : '–';

// Avatar-URL
$avatarUrl = $user['avatar'] ? ('/' . $user['avatar']) : null;

require_once __DIR__ . '/includes/header.php';
$activeTab = $_GET['tab'] ?? 'uebersicht';
?>
<style>
    body { background: #14325a !important; }

    /* ── Profil-Header ──────────────────────────────────────────── */
    .profil-header {
        background: linear-gradient(180deg, #1a4a70 0%, #14325a 100%);
        border-bottom: 1px solid rgba(255,255,255,.07);
        padding: 2.5rem 0 0;
    }
    .profil-avatar-wrap {
        position: relative;
        width: 90px; height: 90px;
        cursor: pointer;
        flex-shrink: 0;
    }
    .profil-avatar-wrap img,
    .profil-avatar-wrap .avatar-placeholder {
        width: 90px; height: 90px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid rgba(232,184,75,.4);
        display: block;
    }
    .avatar-placeholder {
        background: rgba(255,255,255,.08);
        display: flex !important;
        align-items: center;
        justify-content: center;
        font-size: 2.2rem;
        color: rgba(255,255,255,.3);
    }
    .avatar-overlay {
        position: absolute; inset: 0;
        border-radius: 50%;
        background: rgba(0,0,0,.55);
        display: flex; align-items: center; justify-content: center;
        opacity: 0; transition: opacity .2s;
        font-size: .65rem; color: #fff; font-weight: 700;
        text-align: center; line-height: 1.3;
    }
    .profil-avatar-wrap:hover .avatar-overlay { opacity: 1; }

    /* ── Stats-Badges ───────────────────────────────────────────── */
    .profil-stat {
        text-align: center;
        padding: 0 1rem;
        border-right: 1px solid rgba(255,255,255,.08);
    }
    .profil-stat:last-child { border-right: none; }
    .profil-stat-val {
        font-size: 1.4rem; font-weight: 800;
        color: #fff; line-height: 1;
    }
    .profil-stat-lbl {
        font-size: .65rem; font-weight: 700;
        letter-spacing: .07em; text-transform: uppercase;
        color: rgba(255,255,255,.35);
        margin-top: 3px;
    }

    /* ── Tab-Navigation ─────────────────────────────────────────── */
    .profil-tabs {
        display: flex;
        gap: 0;
        margin-top: 1.5rem;
        border-top: 1px solid rgba(255,255,255,.06);
        overflow-x: auto;
        scrollbar-width: none;
    }
    .profil-tabs::-webkit-scrollbar { display: none; }
    .profil-tab {
        padding: .65rem 1.25rem;
        font-size: .8rem; font-weight: 600;
        color: rgba(255,255,255,.4);
        text-decoration: none;
        white-space: nowrap;
        border-bottom: 2px solid transparent;
        transition: color .15s, border-color .15s;
        letter-spacing: .02em;
    }
    .profil-tab:hover { color: rgba(255,255,255,.75); }
    .profil-tab.active {
        color: #e8b84b;
        border-bottom-color: #e8b84b;
    }
    .profil-tab i { margin-right: .35rem; font-size: .78rem; }

    /* ── Übersicht-Karten ───────────────────────────────────────── */
    .ov-card {
        background: rgba(255,255,255,.04);
        border: 1px solid rgba(255,255,255,.07);
        border-radius: 12px;
        padding: 1.25rem 1.5rem;
    }
    .ov-card-title {
        font-size: .7rem; font-weight: 700; letter-spacing: .08em;
        text-transform: uppercase; color: rgba(255,255,255,.35);
        margin-bottom: 1rem;
        display: flex; align-items: center; gap: .45rem;
    }
    .ov-card-title i { font-size: .85rem; }

    .mode-row {
        display: flex; align-items: center;
        justify-content: space-between;
        padding: .35rem 0;
        border-bottom: 1px solid rgba(255,255,255,.04);
        font-size: .83rem;
    }
    .mode-row:last-child { border-bottom: none; }
    .mode-dot {
        width: 8px; height: 8px; border-radius: 50%;
        margin-right: .5rem; flex-shrink: 0;
    }
</style>

<!-- ── Profil-Header ─────────────────────────────────────────────────── -->
<div class="profil-header">
    <div class="container">

        <?php if ($uploadError): ?>
        <div class="alert alert-danger py-2 mb-3" style="font-size:.85rem;"><?= e($uploadError) ?></div>
        <?php endif; ?>

        <!-- Avatar + Name + Meta -->
        <div class="d-flex align-items-center gap-4 flex-wrap">

            <!-- Avatar -->
            <form method="post" enctype="multipart/form-data" id="avatarForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display:none;"
                       onchange="document.getElementById('avatarForm').submit()">
                <div class="profil-avatar-wrap" onclick="document.getElementById('avatarInput').click()" title="Profilbild ändern">
                    <?php if ($avatarUrl): ?>
                        <img src="<?= e($avatarUrl) ?>?v=<?= time() ?>" alt="Avatar">
                    <?php else: ?>
                        <div class="avatar-placeholder"><i class="bi bi-person-fill"></i></div>
                    <?php endif; ?>
                    <div class="avatar-overlay"><i class="bi bi-camera-fill d-block mb-1" style="font-size:1.1rem;"></i>Ändern</div>
                </div>
            </form>

            <!-- Name + Info -->
            <div class="flex-grow-1">
                <h1 class="fw-bold mb-1" style="font-size:1.6rem; color:#fff; line-height:1;">
                    <?= e($user['username']) ?>
                    <?php if ($user['role'] === 'Admin'): ?>
                    <span style="font-size:.7rem; background:#e8b84b; color:#0d2137; border-radius:4px; padding:2px 7px; vertical-align:middle; font-weight:700; margin-left:.4rem;">Admin</span>
                    <?php endif; ?>
                </h1>
                <div class="d-flex align-items-center gap-3 flex-wrap mt-1" style="font-size:.8rem; color:rgba(255,255,255,.4);">
                    <?php if ($user['nationality']): ?>
                    <span><i class="bi bi-globe2 me-1"></i><?= e($user['nationality']) ?></span>
                    <?php endif; ?>
                    <span><i class="bi bi-calendar3 me-1"></i>Dabei seit <?= $memberYear ?></span>
                    <?php if ($user['favorite_genre']): ?>
                    <span><i class="bi bi-heart me-1"></i><?= e($user['favorite_genre']) ?></span>
                    <?php endif; ?>
                    <span style="color:rgba(232,184,75,.6);"><i class="bi bi-trophy me-1"></i>Rang #<?= $communityRank ?></span>
                </div>
            </div>

            <!-- Stats -->
            <div class="d-flex align-items-center ms-auto flex-wrap" style="gap:0;">
                <div class="profil-stat">
                    <div class="profil-stat-val"><?= number_format($totalFilms) ?></div>
                    <div class="profil-stat-lbl">Filme</div>
                </div>
                <div class="profil-stat">
                    <div class="profil-stat-val"><?= number_format($totalDuels) ?></div>
                    <div class="profil-stat-lbl">Duelle</div>
                </div>
                <div class="profil-stat">
                    <div class="profil-stat-val"><?= number_format($totalRanked) ?></div>
                    <div class="profil-stat-lbl">Gerankt</div>
                </div>
                <div class="profil-stat">
                    <div class="profil-stat-val"><?= number_format($activeDays) ?></div>
                    <div class="profil-stat-lbl">Aktive Tage</div>
                </div>
            </div>
        </div>

        <!-- ── Tab-Navigation ──────────────────────────────────────────── -->
        <nav class="profil-tabs">
            <a href="/profil.php" class="profil-tab <?= $activeTab === 'uebersicht' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2"></i>Übersicht
            </a>
            <a href="/rangliste.php" class="profil-tab <?= $currentPage === 'rangliste' ? 'active' : '' ?>">
                <i class="bi bi-trophy"></i>Rangliste
            </a>
            <a href="/user-statistiken.php" class="profil-tab <?= $currentPage === 'user-statistiken' ? 'active' : '' ?>">
                <i class="bi bi-bar-chart"></i>Statistiken
            </a>
            <a href="/meine-sammlung.php" class="profil-tab <?= $currentPage === 'meine-sammlung' ? 'active' : '' ?>">
                <i class="bi bi-collection-play"></i>Sammlung
            </a>
            <a href="/filmtagebuch.php" class="profil-tab <?= $currentPage === 'filmtagebuch' ? 'active' : '' ?>">
                <i class="bi bi-journal-play"></i><?= t('nav.diary') ?>
            </a>
            <a href="/community-rangliste.php" class="profil-tab <?= $currentPage === 'community-rangliste' ? 'active' : '' ?>">
                <i class="bi bi-people"></i>Community
            </a>
            <a href="/community-zeiten.php" class="profil-tab <?= $currentPage === 'community-zeiten' ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i>Zeiten
            </a>
            <a href="/profil.php?tab=einstellungen" class="profil-tab <?= $activeTab === 'einstellungen' ? 'active' : '' ?>">
                <i class="bi bi-sliders"></i>Einstellungen
            </a>
        </nav>
    </div>
</div>

<!-- ── Einstellungen-Tab ─────────────────────────────────────────────── -->
<?php if ($activeTab === 'einstellungen'): ?>
<main class="py-4" style="min-height:60vh;">
<div class="container">
<div class="row justify-content-center">
<div class="col-lg-6">

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
    <i class="bi bi-check-circle me-2"></i>Einstellungen gespeichert.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card mb-3" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:12px;">
    <div class="card-body p-4">
        <h5 class="fw-bold mb-1" style="color:var(--mkfb-gold);">
            <i class="bi bi-sliders me-2"></i>Bewertungs-Einstellungen
        </h5>
        <p class="small mb-4" style="color:rgba(255,255,255,.85);">Diese Einstellungen gelten für alle Bewertungsmodi und Ranglisten.</p>

        <?php if ($settingsError): ?>
        <div class="alert alert-warning py-2 mb-3"><?= e($settingsError) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success py-2 mb-3">Einstellungen gespeichert.</div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action"      value="save_settings">
            <input type="hidden" name="csrf_token"  value="<?= csrfToken() ?>">
            <input type="hidden" name="form_sent"   value="1">

            <!-- Serien-Einstellung -->
            <div class="d-flex align-items-start justify-content-between gap-3 mb-3 pb-3"
                 style="border-bottom:1px solid rgba(255,255,255,.07);">
                <div>
                    <div class="fw-600" style="font-size:.95rem;">Serien im Duell</div>
                    <div class="small mt-1" style="color:rgba(255,255,255,.85);">
                        Wenn aktiv, werden TV-Serien in allen Bewertungsmodi (Duelle, Turnier, JgJ etc.)
                        und Ranglisten angezeigt. Wenn deaktiviert, erscheinen nur Filme.
                    </div>
                </div>
                <div class="form-check form-switch ms-3 flex-shrink-0 mt-1">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="show_series" name="show_series" value="1"
                           <?= ((int)($user['show_series'] ?? 1) !== 0) ? 'checked' : '' ?>
                           style="width:2.5em; height:1.3em; cursor:pointer;">
                    <label class="form-check-label" for="show_series"></label>
                </div>
            </div>

            <?php if (userPhase() >= 3): ?>
            <!-- Filme-Einstellung (ab Phase IV) -->
            <div class="d-flex align-items-start justify-content-between gap-3 mb-3 pb-3"
                 style="border-bottom:1px solid rgba(255,255,255,.07);">
                <div>
                    <div class="fw-600" style="font-size:.95rem;">Filme im Duell</div>
                    <div class="small mt-1" style="color:rgba(255,255,255,.85);">
                        Wenn deaktiviert, werden nur TV-Serien bewertet. Nützlich wenn du
                        ausschließlich Serien in deinen Ranglisten haben möchtest.
                    </div>
                </div>
                <div class="form-check form-switch ms-3 flex-shrink-0 mt-1">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="show_movies" name="show_movies" value="1"
                           <?= ((int)($user['show_movies'] ?? 1) !== 0) ? 'checked' : '' ?>
                           style="width:2.5em; height:1.3em; cursor:pointer;">
                    <label class="form-check-label" for="show_movies"></label>
                </div>
            </div>
            <?php endif; ?>

            <div class="text-end">
                <button type="submit" class="btn btn-gold px-4">
                    <i class="bi bi-check-lg me-1"></i>Speichern
                </button>
            </div>
        </form>

        <?php if (userPhase() >= 3): ?>
        <!-- Filme ausblenden (ab Phase IV) -->
        <hr style="border-color:rgba(255,255,255,.08); margin:1.5rem 0;">
        <h6 class="fw-bold mb-1" style="color:var(--mkfb-gold);">
            <i class="bi bi-eye-slash me-2"></i>Filme ausblenden
        </h6>
        <p class="small mb-3" style="color:rgba(255,255,255,.85);">
            Ausgeblendete Filme erscheinen in keinem Bewertungsmodus und keiner Rangliste.
        </p>

        <!-- Suchfeld -->
        <div class="position-relative mb-3">
            <input type="text" id="hide-search" class="form-control form-control-sm"
                   placeholder="Film suchen …"
                   style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.15);color:#fff;">
            <div id="hide-results" class="position-absolute w-100 mt-1"
                 style="display:none;z-index:200;background:#0d1f36;border:1px solid rgba(232,184,75,.25);border-radius:8px;max-height:260px;overflow-y:auto;"></div>
        </div>

        <!-- Liste ausgeblendeter Filme -->
        <div id="hidden-films-list">
        <?php foreach ($hiddenFilms as $hf): ?>
            <div class="d-flex align-items-center gap-2 mb-2 hidden-film-row" data-id="<?= (int)$hf['id'] ?>">
                <img src="<?= e(moviePosterUrl($hf, 'w92')) ?>" alt=""
                     style="width:32px;height:48px;object-fit:cover;border-radius:4px;flex-shrink:0;"
                     onerror="this.src='/assets/no-poster.svg'">
                <span style="flex:1;font-size:.9rem;"><?= e(movieTitle($hf)) ?>
                    <?php if ($hf['year']): ?><span style="opacity:.45;font-size:.8rem;"> (<?= (int)$hf['year'] ?>)</span><?php endif; ?>
                </span>
                <button class="btn btn-sm unhide-btn" style="border:1px solid rgba(255,80,80,.4);color:#ff5050;padding:2px 8px;"
                        data-id="<?= (int)$hf['id'] ?>">
                    <i class="bi bi-eye" style="pointer-events:none;"></i>
                </button>
            </div>
        <?php endforeach; ?>
        <?php if (empty($hiddenFilms)): ?>
            <p class="small" id="no-hidden-msg" style="color:rgba(255,255,255,.35);">Keine Filme ausgeblendet.</p>
        <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const csrf    = <?= json_encode(csrfToken()) ?>;
    const search  = document.getElementById('hide-search');
    const results = document.getElementById('hide-results');
    const list    = document.getElementById('hidden-films-list');
    let timer;

    search.addEventListener('input', function () {
        clearTimeout(timer);
        const q = this.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(() => {
            fetch('/profil.php?action=search_hide_film&q=' + encodeURIComponent(q))
                .then(r => r.json()).then(renderResults);
        }, 280);
    });

    document.addEventListener('click', function (e) {
        if (!search.contains(e.target) && !results.contains(e.target)) {
            results.style.display = 'none';
        }
    });

    function renderResults(rows) {
        if (!rows.length) { results.style.display = 'none'; return; }
        results.innerHTML = rows.map(r =>
            '<div class="d-flex align-items-center gap-2 p-2 hide-result-row" style="cursor:default;border-bottom:1px solid rgba(255,255,255,.05);" data-id="' + r.id + '">' +
            '<img src="' + escHtml(r.poster) + '" style="width:24px;height:36px;object-fit:cover;border-radius:3px;flex-shrink:0;" onerror="this.src=\'/assets/no-poster.svg\'">' +
            '<span style="flex:1;font-size:.88rem;">' + escHtml(r.title) + (r.year ? ' <span style="opacity:.4;font-size:.78rem;">(' + r.year + ')</span>' : '') + '</span>' +
            '<button class="btn btn-sm ms-auto' + (r.hidden ? ' unhide-result-btn' : ' hide-result-btn') + '" ' +
            'style="font-size:.75rem;padding:2px 8px;border:1px solid ' + (r.hidden ? 'rgba(255,80,80,.4);color:#ff5050' : 'rgba(232,184,75,.4);color:#e8b84b') + ';" data-id="' + r.id + '" data-title="' + escHtml(r.title) + '" data-year="' + r.year + '" data-poster="' + escHtml(r.poster) + '">' +
            (r.hidden ? '<i class="bi bi-eye" style="pointer-events:none;"></i>' : '<i class="bi bi-eye-slash" style="pointer-events:none;"></i> Ausblenden') +
            '</button></div>'
        ).join('');
        results.style.display = 'block';
    }

    results.addEventListener('click', function (e) {
        const hideBtn   = e.target.closest('.hide-result-btn');
        const unhideBtn = e.target.closest('.unhide-result-btn');
        const btn = hideBtn || unhideBtn;
        if (!btn) return;
        const id     = parseInt(btn.dataset.id);
        const action = hideBtn ? 'hide_film' : 'unhide_film';
        postAction(action, id).then(() => {
            if (hideBtn) {
                addToList(id, btn.dataset.title, parseInt(btn.dataset.year) || 0, btn.dataset.poster);
                btn.className = btn.className.replace('hide-result-btn', 'unhide-result-btn');
                btn.style.borderColor = 'rgba(255,80,80,.4)';
                btn.style.color = '#ff5050';
                btn.innerHTML = '<i class="bi bi-eye" style="pointer-events:none;"></i>';
            } else {
                removeFromList(id);
                btn.className = btn.className.replace('unhide-result-btn', 'hide-result-btn');
                btn.style.borderColor = 'rgba(232,184,75,.4)';
                btn.style.color = '#e8b84b';
                btn.innerHTML = '<i class="bi bi-eye-slash" style="pointer-events:none;"></i> Ausblenden';
            }
        });
    });

    list.addEventListener('click', function (e) {
        const btn = e.target.closest('.unhide-btn');
        if (!btn) return;
        const id = parseInt(btn.dataset.id);
        postAction('unhide_film', id).then(() => removeFromList(id));
    });

    function postAction(action, movieId) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('movie_id', movieId);
        fd.append('csrf_token', csrf);
        return fetch('/profil.php', { method: 'POST', body: fd }).then(r => r.json());
    }

    function addToList(id, title, year, poster) {
        document.getElementById('no-hidden-msg')?.remove();
        const row = document.createElement('div');
        row.className = 'd-flex align-items-center gap-2 mb-2 hidden-film-row';
        row.dataset.id = id;
        row.innerHTML =
            '<img src="' + escHtml(poster) + '" alt="" style="width:32px;height:48px;object-fit:cover;border-radius:4px;flex-shrink:0;" onerror="this.src=\'/assets/no-poster.svg\'">' +
            '<span style="flex:1;font-size:.9rem;">' + escHtml(title) + (year ? ' <span style="opacity:.45;font-size:.8rem;">(' + year + ')</span>' : '') + '</span>' +
            '<button class="btn btn-sm unhide-btn" style="border:1px solid rgba(255,80,80,.4);color:#ff5050;padding:2px 8px;" data-id="' + id + '"><i class="bi bi-eye" style="pointer-events:none;"></i></button>';
        list.appendChild(row);
    }

    function removeFromList(id) {
        list.querySelector('[data-id="' + id + '"]')?.remove();
        if (!list.querySelector('.hidden-film-row')) {
            const p = document.createElement('p');
            p.id = 'no-hidden-msg';
            p.className = 'small';
            p.style.color = 'rgba(255,255,255,.35)';
            p.textContent = 'Keine Filme ausgeblendet.';
            list.appendChild(p);
        }
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
}());
</script>
        <?php endif; // Phase IV ?>

</div>
</div>
</div>
</main>
<?php else: ?>

<!-- ── Übersicht-Inhalt ──────────────────────────────────────────────── -->
<main class="py-4" style="min-height: 60vh;">
<div class="container">

    <div class="row g-4">

        <!-- ── Linke Spalte: Modus-Übersicht ──────────────────────── -->
        <div class="col-lg-7">
            <div class="ov-card">
                <div class="ov-card-title">
                    <i class="bi bi-lightning-charge-fill" style="color:#e8b84b;"></i>Aktivität nach Modus
                </div>
                <?php
                $modes = [
                    ['icon'=>'bi-diagram-3',         'label'=>'Turnier',          'color'=>'#e8b84b',
                     'val' => (function() use ($db,$userId) {
                         $s = $db->prepare("SELECT COUNT(tm.id) FROM user_tournaments ut LEFT JOIN tournament_matches tm ON tm.tournament_id=ut.id AND tm.winner_id IS NOT NULL WHERE ut.user_id=?");
                         $s->execute([$userId]); return (int)$s->fetchColumn();
                     })(), 'unit'=>'Matches'],
                    ['icon'=>'bi-people-fill',        'label'=>'Liga',             'color'=>'#5b9bd5',
                     'val' => (function() use ($db,$userId) {
                         $s = $db->prepare("SELECT COUNT(lm.id) FROM liga_sessions ls LEFT JOIN liga_matches lm ON lm.liga_id=ls.id AND lm.winner_id IS NOT NULL WHERE ls.user_id=?");
                         $s->execute([$userId]); return (int)$s->fetchColumn();
                     })(), 'unit'=>'Matches'],
                    ['icon'=>'bi-sort-numeric-down',  'label'=>'Sortieren',        'color'=>'#7ec87e',
                     'val' => (function() use ($db,$userId) {
                         $s = $db->prepare("SELECT COALESCE(SUM(film_count),0) FROM sort_sessions WHERE user_id=? AND status='completed'");
                         $s->execute([$userId]); return (int)$s->fetchColumn();
                     })(), 'unit'=>'Filme sortiert'],
                    ['icon'=>'bi-shuffle',            'label'=>'Zufallsduelle',    'color'=>'#e07b7b',
                     'val' => (function() use ($db,$userId) {
                         $s = $db->prepare("SELECT COALESCE(SUM(duels_done),0) FROM duel_sessions WHERE user_id=?");
                         $s->execute([$userId]); return (int)$s->fetchColumn();
                     })(), 'unit'=>'Duelle'],
                    ['icon'=>'bi-search-heart',       'label'=>'Film einordnen',   'color'=>'#c97ee0',
                     'val' => (function() use ($db,$userId) {
                         $s = $db->prepare("SELECT COUNT(*) FROM film_insert_sessions WHERE user_id=? AND status='done'");
                         $s->execute([$userId]); return (int)$s->fetchColumn();
                     })(), 'unit'=>'Einordnungen'],
                    ['icon'=>'bi-diagram-3-fill',     'label'=>'Jeder gegen Jeden','color'=>'#f0a55a',
                     'val' => (function() use ($db,$userId) {
                         $s = $db->prepare("SELECT COUNT(*) FROM jgj_results WHERE user_id=?");
                         $s->execute([$userId]); return (int)$s->fetchColumn();
                     })(), 'unit'=>'Duelle'],
                    ['icon'=>'bi-grid-3x2-gap-fill',  'label'=>'5 Filme',          'color'=>'#5bd5c9',
                     'val' => (function() use ($db,$userId) {
                         $s = $db->prepare("SELECT COALESCE(SUM(film_count),0) FROM fuenf_sessions WHERE user_id=? AND status='completed'");
                         $s->execute([$userId]); return (int)$s->fetchColumn();
                     })(), 'unit'=>'Filme'],
                ];
                $maxVal = max(array_column($modes, 'val')) ?: 1;
                foreach ($modes as $m):
                ?>
                <div class="mode-row">
                    <div class="d-flex align-items-center" style="min-width:0; flex:1;">
                        <span class="mode-dot" style="background:<?= $m['color'] ?>;"></span>
                        <i class="bi <?= $m['icon'] ?> me-2" style="color:<?= $m['color'] ?>; font-size:.9rem;"></i>
                        <span style="color:rgba(255,255,255,.65);"><?= $m['label'] ?></span>
                    </div>
                    <div style="flex:1; margin:0 1rem;">
                        <div style="height:5px; background:rgba(255,255,255,.06); border-radius:3px; overflow:hidden;">
                            <div style="height:5px; border-radius:3px; background:<?= $m['color'] ?>; opacity:.7; width:<?= $maxVal > 0 ? round($m['val']/$maxVal*100) : 0 ?>%;"></div>
                        </div>
                    </div>
                    <div style="text-align:right; white-space:nowrap;">
                        <span style="font-weight:700; color:#fff;"><?= number_format($m['val']) ?></span>
                        <span style="color:rgba(255,255,255,.3); font-size:.72rem; margin-left:.3rem;"><?= $m['unit'] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Rechte Spalte: Info + Schnellzugriff ────────────────── -->
        <div class="col-lg-5 d-flex flex-column gap-4">

            <!-- Profil-Info -->
            <div class="ov-card">
                <div class="ov-card-title">
                    <i class="bi bi-person-fill" style="color:#e8b84b;"></i>Profil-Info
                </div>
                <?php
                $infos = [
                    ['icon'=>'bi-envelope',     'label'=>'E-Mail',     'val'=>$user['email']],
                    ['icon'=>'bi-gender-ambiguous','label'=>'Geschlecht','val'=>$user['gender']],
                    ['icon'=>'bi-globe2',        'label'=>'Nationalität','val'=>$user['nationality']],
                    ['icon'=>'bi-calendar3',     'label'=>'Geb.-Jahr',  'val'=>$user['birth_year'] ?: null],
                    ['icon'=>'bi-heart',         'label'=>'Lieblingsgenre','val'=>$user['favorite_genre']],
                    ['icon'=>'bi-calendar-check','label'=>'Mitglied seit','val'=>$memberSince],
                ];
                foreach ($infos as $info):
                    if (empty($info['val'])) continue;
                ?>
                <div class="d-flex align-items-baseline gap-2 mb-2" style="font-size:.83rem;">
                    <i class="bi <?= $info['icon'] ?>" style="color:rgba(232,184,75,.6); min-width:14px;"></i>
                    <span style="color:rgba(255,255,255,.35); min-width:100px;"><?= $info['label'] ?></span>
                    <span style="color:rgba(255,255,255,.8);"><?= e((string)$info['val']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Schnellzugriff -->
            <div class="ov-card">
                <div class="ov-card-title">
                    <i class="bi bi-lightning" style="color:#e8b84b;"></i>Schnellzugriff
                </div>
                <?php
                $links = [
                    ['/jgj.php',             'bi-people-fill',       '#f0a55a', 'Jeder gegen Jeden'],
                    ['/turnier.php',          'bi-diagram-3',         '#e8b84b', 'Turnier starten'],
                    ['/zufallsduelle.php',    'bi-shuffle',           '#e07b7b', 'Zufallsduelle'],
                    ['/community-statistiken.php','bi-bar-chart-fill','#5b9bd5', 'Community-Statistiken'],
                    ['/community-zeiten.php', 'bi-clock-history',    '#7ec87e', 'Community-Zeiten'],
                ];
                foreach ($links as [$href, $icon, $color, $label]):
                ?>
                <a href="<?= $href ?>" class="d-flex align-items-center gap-2 text-decoration-none mb-2"
                   style="color:rgba(255,255,255,.65); font-size:.83rem; transition:color .15s;"
                   onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.65)'">
                    <i class="bi <?= $icon ?>" style="color:<?= $color ?>; font-size:.95rem; min-width:16px;"></i>
                    <?= $label ?>
                    <i class="bi bi-chevron-right ms-auto" style="font-size:.6rem; opacity:.3;"></i>
                </a>
                <?php endforeach; ?>
            </div>

        </div>
    </div>

</div>
</main>

<?php endif; // einstellungen tab ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
