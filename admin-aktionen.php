<?php
$pageTitle = 'Aktionen verwalten – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!isAdmin()) { header('Location: /index.php'); exit; }

$db = getDB();

// ── DB Schema ──────────────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS action_lists (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(200) NOT NULL,
    description TEXT NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    created_by  INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dates (start_date, end_date),
    INDEX idx_creator (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS action_list_films (
    list_id   INT UNSIGNED NOT NULL,
    movie_id  INT UNSIGNED NOT NULL,
    added_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (list_id, movie_id),
    INDEX idx_list (list_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS action_list_duels (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    movie_a_id INT UNSIGNED NOT NULL,
    movie_b_id INT UNSIGNED NOT NULL,
    winner_id  INT UNSIGNED NOT NULL,
    round      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_list_user (list_id, user_id),
    UNIQUE KEY uq_match_round (list_id, user_id, movie_a_id, movie_b_id, round)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS action_list_rankings (
    list_id      INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    movie_id     INT UNSIGNED NOT NULL,
    position     INT UNSIGNED NOT NULL,
    wins         INT UNSIGNED NOT NULL DEFAULT 0,
    losses       INT UNSIGNED NOT NULL DEFAULT 0,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (list_id, user_id, movie_id),
    INDEX idx_list (list_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── TMDB-Helper ───────────────────────────────────────────────────────────────
function aktTmdbGet(string $endpoint, array $params = []): ?array {
    if (!defined('TMDB_API_KEY') || !TMDB_API_KEY) return null;
    $params['api_key'] = TMDB_API_KEY;
    $url = 'https://api.themoviedb.org/3/' . ltrim($endpoint, '/') . '?' . http_build_query($params);
    $ch  = curl_init($url);
    $caBundle = ini_get('curl.cainfo') ?: 'C:/xampp/php/extras/ssl/cacert.pem';
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true,
             CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_TIMEOUT => 12];
    if ($caBundle && file_exists($caBundle)) $opts[CURLOPT_CAINFO] = $caBundle;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch); $err = curl_errno($ch); curl_close($ch);
    if ($err || !$body) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

// ── AJAX: Person-Suche ────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_person') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) >= 2) {
        $data = aktTmdbGet('search/person', ['query' => $q, 'language' => 'de-DE', 'page' => 1]);
        $raw  = array_slice($data['results'] ?? [], 0, 7);
        echo json_encode(array_map(fn($p) => [
            'id'         => $p['id'],
            'name'       => $p['name'],
            'profile'    => $p['profile_path'] ?? null,
            'dept'       => $p['known_for_department'] ?? '',
            'known_for'  => implode(', ', array_slice(
                array_filter(array_map(fn($k) => $k['title'] ?? $k['name'] ?? '', $p['known_for'] ?? [])), 0, 3)),
        ], $raw));
    } else {
        echo json_encode([]);
    }
    exit;
}

// ── AJAX: Film-Suche ───────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_films') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $q      = trim($_GET['q'] ?? '');
    $listId = (int)($_GET['list_id'] ?? 0);
    $films  = [];
    if (mb_strlen($q) >= 2) {
        $stmt = $db->prepare("SELECT id, title, year, poster_path FROM movies WHERE title LIKE ? ORDER BY title ASC LIMIT 20");
        $stmt->execute(['%' . $q . '%']);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($listId) {
            $ex = $db->prepare("SELECT movie_id FROM action_list_films WHERE list_id = ?");
            $ex->execute([$listId]);
            $exIds = array_flip($ex->fetchAll(PDO::FETCH_COLUMN));
            $all   = array_filter($all, fn($f) => !isset($exIds[(int)$f['id']]));
        }
        $films = array_values($all);
    }
    echo json_encode($films);
    exit;
}

// ── POST Handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) { header('Location: /admin-aktionen.php'); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $start = $_POST['start_date'] ?? '';
        $end   = $_POST['end_date']   ?? '';
        if ($name && $start && $end && $start <= $end) {
            $stmt = $db->prepare("INSERT INTO action_lists (name, description, start_date, end_date, created_by) VALUES (?,?,?,?,?)");
            $stmt->execute([$name, $desc, $start, $end, (int)$_SESSION['user_id']]);
            $newId = (int)$db->lastInsertId();
            header('Location: /admin-aktionen.php?edit=' . $newId . '&created=1');
        } else {
            header('Location: /admin-aktionen.php?error=invalid');
        }
        exit;
    }

    if ($action === 'save_edit') {
        $listId = (int)($_POST['list_id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $start  = $_POST['start_date'] ?? '';
        $end    = $_POST['end_date']   ?? '';
        if ($listId && $name && $start && $end) {
            $db->prepare("UPDATE action_lists SET name=?, description=?, start_date=?, end_date=? WHERE id=?")
               ->execute([$name, $desc, $start, $end, $listId]);
        }
        header('Location: /admin-aktionen.php?edit=' . $listId . '&saved=1');
        exit;
    }

    if ($action === 'delete') {
        $listId = (int)($_POST['list_id'] ?? 0);
        if ($listId) {
            $db->prepare("DELETE FROM action_list_rankings WHERE list_id=?")->execute([$listId]);
            $db->prepare("DELETE FROM action_list_duels    WHERE list_id=?")->execute([$listId]);
            $db->prepare("DELETE FROM action_list_films    WHERE list_id=?")->execute([$listId]);
            $db->prepare("DELETE FROM action_lists         WHERE id=?")->execute([$listId]);
        }
        header('Location: /admin-aktionen.php?deleted=1');
        exit;
    }

    if ($action === 'add_film') {
        $listId   = (int)($_POST['list_id'] ?? 0);
        $movieIds = array_filter(array_map('intval', (array)($_POST['movie_ids'] ?? [])));
        $isAjax   = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || ($_POST['ajax'] ?? '') === '1';
        $added    = [];
        if ($listId && !empty($movieIds)) {
            $stmt = $db->prepare("INSERT IGNORE INTO action_list_films (list_id, movie_id) VALUES (?,?)");
            foreach ($movieIds as $mid) { $stmt->execute([$listId, $mid]); }
            // Fetch newly added films for AJAX response
            $ph   = implode(',', array_fill(0, count($movieIds), '?'));
            $info = $db->prepare("SELECT m.id, m.title, m.year, m.poster_path FROM movies m WHERE m.id IN ($ph)");
            $info->execute(array_values($movieIds));
            $added = $info->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($isAjax) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            $cntStmt = $db->prepare("SELECT COUNT(*) FROM action_list_films WHERE list_id=?");
            $cntStmt->execute([$listId]);
            $cnt = (int)$cntStmt->fetchColumn();
            echo json_encode(['ok' => true, 'added' => $added, 'total' => $cnt]);
            exit;
        }
        header('Location: /admin-aktionen.php?edit=' . $listId . '#films');
        exit;
    }

    if ($action === 'remove_film') {
        $listId  = (int)($_POST['list_id']  ?? 0);
        $movieId = (int)($_POST['movie_id'] ?? 0);
        if ($listId && $movieId) {
            $db->prepare("DELETE FROM action_list_films WHERE list_id=? AND movie_id=?")
               ->execute([$listId, $movieId]);
            // Duels für diesen Film ebenfalls entfernen (Inkonsistenz vermeiden)
            $db->prepare("DELETE FROM action_list_duels WHERE list_id=? AND (movie_a_id=? OR movie_b_id=?)")
               ->execute([$listId, $movieId, $movieId]);
            $db->prepare("DELETE FROM action_list_rankings WHERE list_id=? AND movie_id=?")
               ->execute([$listId, $movieId]);
        }
        header('Location: /admin-aktionen.php?edit=' . $listId . '#films');
        exit;
    }

    if ($action === 'add_person_films') {
        $listId   = (int)($_POST['list_id']    ?? 0);
        $personId = (int)($_POST['person_id']  ?? 0);
        $dept     = in_array($_POST['person_dept']  ?? '', ['cast','director','producer','all']) ? $_POST['person_dept'] : 'all';
        $media    = in_array($_POST['person_media'] ?? '', ['movie','tv','both']) ? $_POST['person_media'] : 'both';

        $tmdbIds = [];
        if ($personId) {
            if (in_array($media, ['movie', 'both'])) {
                $mc = aktTmdbGet("person/{$personId}/movie_credits", ['language' => 'de-DE']) ?? [];
                if ($dept === 'cast'     || $dept === 'all') foreach ($mc['cast'] ?? [] as $m) if (!empty($m['id'])) $tmdbIds[] = (int)$m['id'];
                if ($dept === 'director' || $dept === 'all') foreach ($mc['crew'] ?? [] as $m) if (!empty($m['id']) && $m['job'] === 'Director') $tmdbIds[] = (int)$m['id'];
                if ($dept === 'producer' || $dept === 'all') foreach ($mc['crew'] ?? [] as $m) if (!empty($m['id']) && $m['department'] === 'Production') $tmdbIds[] = (int)$m['id'];
            }
            if (in_array($media, ['tv', 'both'])) {
                $tc = aktTmdbGet("person/{$personId}/tv_credits", ['language' => 'de-DE']) ?? [];
                if ($dept === 'cast'     || $dept === 'all') foreach ($tc['cast'] ?? [] as $m) if (!empty($m['id'])) $tmdbIds[] = (int)$m['id'];
                if ($dept === 'director' || $dept === 'all') foreach ($tc['crew'] ?? [] as $m) if (!empty($m['id']) && $m['job'] === 'Director') $tmdbIds[] = (int)$m['id'];
                if ($dept === 'producer' || $dept === 'all') foreach ($tc['crew'] ?? [] as $m) if (!empty($m['id']) && $m['department'] === 'Production') $tmdbIds[] = (int)$m['id'];
            }
        }
        $tmdbIds = array_values(array_unique($tmdbIds));

        $added = []; $tmdbTotal = count($tmdbIds);
        if ($listId && !empty($tmdbIds)) {
            $ph   = implode(',', array_fill(0, count($tmdbIds), '?'));
            $stmt = $db->prepare("SELECT id, title, year, poster_path FROM movies WHERE tmdb_id IN ($ph)");
            $stmt->execute($tmdbIds);
            $localFilms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ins = $db->prepare("INSERT IGNORE INTO action_list_films (list_id, movie_id) VALUES (?,?)");
            foreach ($localFilms as $f) { $ins->execute([$listId, (int)$f['id']]); $added[] = $f; }
        }
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM action_list_films WHERE list_id=?");
        $cntStmt->execute([$listId]);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'added' => $added, 'total' => (int)$cntStmt->fetchColumn(), 'tmdb_total' => $tmdbTotal]);
        exit;
    }

    if ($action === 'reset_duels') {
        $listId = (int)($_POST['list_id'] ?? 0);
        if ($listId) {
            $db->prepare("DELETE FROM action_list_duels    WHERE list_id=?")->execute([$listId]);
            $db->prepare("DELETE FROM action_list_rankings WHERE list_id=?")->execute([$listId]);
        }
        header('Location: /admin-aktionen.php?edit=' . $listId . '&reset=1');
        exit;
    }
}

// ── Alle Listen laden ──────────────────────────────────────────────────────────
$allLists = $db->query("
    SELECT al.*, u.username AS creator_name,
           COUNT(DISTINCT alf.movie_id) AS film_count,
           (CURDATE() BETWEEN al.start_date AND al.end_date) AS is_active
    FROM action_lists al
    LEFT JOIN users u ON u.id = al.created_by
    LEFT JOIN action_list_films alf ON alf.list_id = al.id
    GROUP BY al.id
    ORDER BY al.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Edit-Modus ─────────────────────────────────────────────────────────────────
$editId   = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editList = null;
$editFilms = [];

if ($editId) {
    $s = $db->prepare("SELECT * FROM action_lists WHERE id=?");
    $s->execute([$editId]);
    $editList = $s->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editList) {
        $s = $db->prepare("
            SELECT m.id, m.title, m.year, m.poster_path
            FROM action_list_films alf
            JOIN movies m ON m.id = alf.movie_id
            WHERE alf.list_id = ?
            ORDER BY m.title ASC
        ");
        $s->execute([$editId]);
        $editFilms = $s->fetchAll(PDO::FETCH_ASSOC);

        // Duel-Fortschritt
        $filmCount  = count($editFilms);
        $totalDuels = $filmCount > 1 ? $filmCount * ($filmCount - 1) : 0;
        $s2 = $db->prepare("
            SELECT u.id, u.username, COUNT(ald.id) AS done_count
            FROM users u
            JOIN action_list_duels ald ON ald.user_id = u.id AND ald.list_id = ?
            GROUP BY u.id, u.username
            ORDER BY done_count DESC
        ");
        $s2->execute([$editId]);
        $duelProgress = $s2->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
body { background: #14325a !important; }
.admin-card {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 12px;
    overflow: hidden;
}
.admin-card-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.list-row {
    padding: .75rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,.05);
    display: flex;
    align-items: center;
    gap: .75rem;
}
.list-row:last-child { border-bottom: none; }
.list-row:hover { background: rgba(255,255,255,.025); }
.form-control, .form-select {
    background: rgba(255,255,255,.07) !important;
    border: 1px solid rgba(255,255,255,.12) !important;
    color: #e0e0e0 !important;
    border-radius: 8px;
}
.form-control:focus, .form-select:focus {
    background: rgba(255,255,255,.1) !important;
    border-color: rgba(232,184,75,.4) !important;
    box-shadow: 0 0 0 .2rem rgba(232,184,75,.15) !important;
    color: #e0e0e0 !important;
}
.form-control::placeholder { color: rgba(255,255,255,.3) !important; }
.form-label { color: rgba(255,255,255,.75); font-size: .88rem; margin-bottom: .35rem; }
.btn-gold { background: linear-gradient(135deg,#e8b84b,#c4942a); color: #1a1a1a; font-weight: 700; border: none; }
.btn-gold:hover { opacity: .85; color: #1a1a1a; }
.badge-active { background: rgba(40,167,69,.2); border: 1px solid rgba(40,167,69,.4); color: #5cb85c; border-radius: 20px; padding: 2px 10px; font-size: .72rem; font-weight: 600; }
.badge-inactive { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1); color: rgba(255,255,255,.4); border-radius: 20px; padding: 2px 10px; font-size: .72rem; }
.badge-upcoming { background: rgba(232,184,75,.12); border: 1px solid rgba(232,184,75,.3); color: #e8b84b; border-radius: 20px; padding: 2px 10px; font-size: .72rem; font-weight: 600; }
.film-chip {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 8px;
    padding: .4rem .65rem;
    font-size: .82rem;
    color: #ddd;
}
.film-chip img { width: 24px; height: 36px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
.film-chip .film-chip-title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#film-search-results {
    position: absolute;
    z-index: 100;
    left: 0; right: 0;
    background: #1e3d7a;
    border: 1px solid rgba(232,184,75,.3);
    border-radius: 8px;
    max-height: 260px;
    overflow-y: auto;
    box-shadow: 0 8px 24px rgba(0,0,0,.4);
}
.search-result-item {
    padding: .5rem .9rem;
    cursor: pointer;
    font-size: .85rem;
    color: #ddd;
    border-bottom: 1px solid rgba(255,255,255,.05);
    display: flex;
    gap: .5rem;
    align-items: center;
}
.search-result-item:last-child { border-bottom: none; }
.search-result-item:hover { background: rgba(232,184,75,.12); color: #e8b84b; }
#selected-films-wrap { flex-wrap: wrap; gap: .4rem; }
#add-film-form { display: none; }
</style>

<main style="padding-top: 6px; background: #14325a; min-height: 100vh;">
<section class="py-4" style="background: linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom: 1px solid rgba(232,184,75,.15);">
    <div class="container">
        <div class="d-flex align-items-center gap-3">
            <div>
                <h1 class="fw-bold mb-1" style="color:#e8b84b; font-size:1.6rem;">
                    <i class="bi bi-trophy-fill me-2"></i>Aktionen verwalten
                </h1>
                <p class="mb-0" style="color:rgba(255,255,255,.5); font-size:.9rem;">
                    Film-Listen mit Zeitraum für 2×-Jeder-gegen-Jeden-Duelle
                </p>
            </div>
            <div class="ms-auto d-flex gap-2">
                <a href="/admin-aktionen.php" class="btn btn-sm" style="background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); color:rgba(255,255,255,.7);">
                    <i class="bi bi-list me-1"></i>Alle Listen
                </a>
            </div>
        </div>
    </div>
</section>

<section class="py-4">
<div class="container">

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-check-circle-fill"></i>Liste erstellt. Jetzt Filme hinzufügen.
</div>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-check-circle-fill"></i>Änderungen gespeichert.
</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-trash-fill"></i>Liste gelöscht.
</div>
<?php endif; ?>
<?php if (isset($_GET['reset'])): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-arrow-counterclockwise"></i>Alle Duelle wurden zurückgesetzt.
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Linke Spalte: Formular ─────────────────────────────────────────── -->
    <div class="col-lg-5">

        <?php if ($editList): ?>
        <!-- Edit-Formular -->
        <div class="admin-card mb-4">
            <div class="admin-card-header">
                <h6 class="fw-bold mb-0" style="color:#e8b84b;">
                    <i class="bi bi-pencil me-1"></i>Liste bearbeiten
                </h6>
            </div>
            <div style="padding:1.25rem;">
                <form method="POST">
                    <input type="hidden" name="action" value="save_edit">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="list_id" value="<?= $editId ?>">
                    <div class="mb-3">
                        <label class="form-label">Name der Aktion</label>
                        <input type="text" name="name" class="form-control"
                               value="<?= e($editList['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Beschreibung <span style="color:rgba(255,255,255,.3); font-weight:400;">(optional)</span></label>
                        <textarea name="description" class="form-control" rows="3"
                                  placeholder="Kurze Beschreibung der Aktion..."><?= e($editList['description'] ?? '') ?></textarea>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label">Start-Datum</label>
                            <input type="date" name="start_date" class="form-control"
                                   value="<?= e($editList['start_date']) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">End-Datum</label>
                            <input type="date" name="end_date" class="form-control"
                                   value="<?= e($editList['end_date']) ?>" required>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-gold">
                            <i class="bi bi-check-lg me-1"></i>Speichern
                        </button>
                        <a href="/admin-aktionen.php" class="btn btn-outline-secondary">Abbrechen</a>
                    </div>
                </form>

                <hr style="border-color:rgba(255,255,255,.07); margin:1.25rem 0;">

                <form method="POST" onsubmit="return confirm('Alle Duelle dieser Aktion zurücksetzen?')">
                    <input type="hidden" name="action" value="reset_duels">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="list_id" value="<?= $editId ?>">
                    <button type="submit" class="btn btn-sm w-100"
                            style="background:rgba(255,165,0,.1); border:1px solid rgba(255,165,0,.3); color:rgba(255,165,0,.8);">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Alle Duelle zurücksetzen
                    </button>
                </form>

                <form method="POST" onsubmit="return confirm('Liste und alle Daten wirklich löschen?')" class="mt-2">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="list_id" value="<?= $editId ?>">
                    <button type="submit" class="btn btn-sm w-100"
                            style="background:rgba(244,67,54,.1); border:1px solid rgba(244,67,54,.3); color:rgba(244,67,54,.8);">
                        <i class="bi bi-trash me-1"></i>Liste löschen
                    </button>
                </form>
            </div>
        </div>

        <!-- Fortschritt der Nutzer -->
        <?php if (!empty($duelProgress)): ?>
        <div class="admin-card mb-4">
            <div class="admin-card-header">
                <h6 class="fw-bold mb-0" style="color:rgba(255,255,255,.8);">
                    <i class="bi bi-people me-1"></i>Nutzer-Fortschritt
                </h6>
                <span style="color:rgba(255,255,255,.3); font-size:.78rem;"><?= $totalDuels ?> Duelle gesamt</span>
            </div>
            <?php foreach ($duelProgress as $up): ?>
            <?php $pct = $totalDuels > 0 ? round((int)$up['done_count'] / $totalDuels * 100) : 0; ?>
            <div class="list-row">
                <div style="flex:1; min-width:0;">
                    <div style="color:#ddd; font-size:.85rem; font-weight:600; margin-bottom:.25rem;">
                        <?= e($up['username']) ?>
                    </div>
                    <div style="background:rgba(255,255,255,.08); border-radius:4px; height:5px; overflow:hidden;">
                        <div style="width:<?= $pct ?>%; height:100%; background:linear-gradient(90deg,#e8b84b,#c4942a); border-radius:4px;"></div>
                    </div>
                </div>
                <div style="text-align:right; flex-shrink:0;">
                    <div style="color:#e8b84b; font-size:.85rem; font-weight:700;"><?= (int)$up['done_count'] ?></div>
                    <div style="color:rgba(255,255,255,.3); font-size:.72rem;"><?= $pct ?>%</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- Neue Liste anlegen -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h6 class="fw-bold mb-0" style="color:#e8b84b;">
                    <i class="bi bi-plus-circle me-1"></i>Neue Aktion anlegen
                </h6>
            </div>
            <div style="padding:1.25rem;">
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <div class="mb-3">
                        <label class="form-label">Name der Aktion</label>
                        <input type="text" name="name" class="form-control"
                               placeholder="z.B. Top 20 Actionfilme 2025" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Beschreibung <span style="color:rgba(255,255,255,.3); font-weight:400;">(optional)</span></label>
                        <textarea name="description" class="form-control" rows="3"
                                  placeholder="Kurze Beschreibung der Aktion..."></textarea>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label">Start-Datum</label>
                            <input type="date" name="start_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">End-Datum</label>
                            <input type="date" name="end_date" class="form-control"
                                   value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-gold w-100">
                        <i class="bi bi-plus-lg me-1"></i>Aktion anlegen
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ── Rechte Spalte: Liste + ggf. Film-Verwaltung ───────────────────── -->
    <div class="col-lg-7">

        <?php if ($editList): ?>
        <!-- Film-Verwaltung -->
        <div class="admin-card mb-4" id="films">
            <div class="admin-card-header">
                <h6 class="fw-bold mb-0" style="color:rgba(255,255,255,.8);">
                    <i class="bi bi-film me-1"></i>Filme in der Aktion
                    <span id="film-count-badge" style="color:rgba(255,255,255,.35); font-weight:400;">(<?= count($editFilms) ?>)</span>
                </h6>
            </div>

            <!-- Film hinzufügen -->
            <div style="padding:.9rem 1.25rem; border-bottom:1px solid rgba(255,255,255,.06); background:rgba(232,184,75,.03);">
                <label class="form-label" style="margin-bottom:.4rem;">Filme hinzufügen</label>
                <div style="position:relative;">
                    <input type="text" id="film-search-input" class="form-control"
                           placeholder="Filmtitel suchen…" autocomplete="off">
                    <div id="film-search-results" style="display:none;"></div>
                </div>
                <!-- Ausgewählte Filme als Chips -->
                <div id="selected-films-wrap" style="margin-top:.6rem; flex-wrap:wrap; gap:.4rem;"></div>
                <div id="add-film-btn-wrap" style="margin-top:.65rem; display:none;">
                    <button type="button" id="add-film-btn" class="btn btn-gold btn-sm">
                        <i class="bi bi-plus-lg me-1"></i>
                        <span id="add-btn-label">Filme hinzufügen</span>
                    </button>
                    <span id="add-film-spinner" style="display:none; color:rgba(255,255,255,.4); font-size:.8rem; margin-left:.6rem;">
                        <i class="bi bi-hourglass-split"></i> Speichern…
                    </span>
                </div>
            </div>

            <!-- Person-Import -->
            <div style="padding:.9rem 1.25rem; border-bottom:1px solid rgba(255,255,255,.06); background:rgba(167,139,250,.03);">
                <label class="form-label" style="margin-bottom:.4rem;">
                    <i class="bi bi-person-video3 me-1" style="color:#a78bfa;"></i>Person hinzufügen (Regie / Cast)
                </label>
                <div style="position:relative;">
                    <input type="text" id="person-search-input" class="form-control"
                           placeholder="Name suchen (z. B. Steven Spielberg)…" autocomplete="off">
                    <div id="person-search-results" style="display:none; position:absolute; z-index:110; left:0; right:0;
                         background:#1b3a6b; border:1px solid rgba(255,255,255,.15); border-top:none;
                         border-radius:0 0 8px 8px; max-height:340px; overflow-y:auto;"></div>
                </div>
                <div id="person-add-status" style="margin-top:.5rem; font-size:.8rem; display:none;"></div>
            </div>

            <!-- Film-Liste -->
            <div id="film-empty-state" style="<?= empty($editFilms) ? '' : 'display:none;' ?>padding:2.5rem; text-align:center; color:rgba(255,255,255,.3);">
                <i class="bi bi-film" style="font-size:2rem;"></i>
                <p class="mt-2 mb-0 small">Noch keine Filme hinzugefügt</p>
            </div>
            <div id="film-list-container" style="max-height:500px; overflow-y:auto; <?= empty($editFilms) ? 'display:none;' : '' ?>">
                <?php foreach ($editFilms as $f): ?>
                <div class="list-row">
                    <img src="<?= $f['poster_path'] ? 'https://image.tmdb.org/t/p/w92' . e($f['poster_path']) : 'https://placehold.co/24x36/1e3a5f/e8b84b?text=?' ?>"
                         alt="" style="width:24px; height:36px; object-fit:cover; border-radius:3px; flex-shrink:0;"
                         onerror="this.src='https://placehold.co/24x36/1e3a5f/e8b84b?text=?'">
                    <div style="flex:1; min-width:0;">
                        <div style="color:#ddd; font-size:.88rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            <?= e($f['title']) ?>
                        </div>
                        <div style="color:rgba(255,255,255,.35); font-size:.75rem;"><?= (int)$f['year'] ?></div>
                    </div>
                    <form method="POST" style="flex-shrink:0;">
                        <input type="hidden" name="action" value="remove_film">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="list_id" value="<?= $editId ?>">
                        <input type="hidden" name="movie_id" value="<?= (int)$f['id'] ?>">
                        <button type="submit" class="btn btn-sm"
                                style="background:none; border:1px solid rgba(244,67,54,.3); color:rgba(244,67,54,.7); padding:.15rem .4rem; font-size:.72rem;">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Alle Listen -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h6 class="fw-bold mb-0" style="color:rgba(255,255,255,.8);">Alle Aktionen</h6>
            </div>
            <?php if (empty($allLists)): ?>
            <div style="padding:3rem; text-align:center; color:rgba(255,255,255,.3);">
                <i class="bi bi-trophy" style="font-size:2rem;"></i>
                <p class="mt-2 mb-0">Noch keine Aktionen vorhanden</p>
            </div>
            <?php else: ?>
            <?php foreach ($allLists as $al): ?>
            <?php
                $today     = date('Y-m-d');
                $isActive  = $al['start_date'] <= $today && $al['end_date'] >= $today;
                $isUpcoming = $al['start_date'] > $today;
            ?>
            <div class="list-row">
                <div style="flex:1; min-width:0;">
                    <div style="color:#e0e0e0; font-weight:600; font-size:.9rem; margin-bottom:.2rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?= e($al['name']) ?>
                    </div>
                    <div style="color:rgba(255,255,255,.35); font-size:.75rem;">
                        <i class="bi bi-calendar3 me-1"></i><?= date('d.m.Y', strtotime($al['start_date'])) ?> – <?= date('d.m.Y', strtotime($al['end_date'])) ?>
                        &nbsp;·&nbsp;
                        <i class="bi bi-film me-1"></i><?= (int)$al['film_count'] ?> Filme
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <?php if ($isActive): ?>
                    <span class="badge-active"><i class="bi bi-circle-fill me-1" style="font-size:.5rem;"></i>Aktiv</span>
                    <?php elseif ($isUpcoming): ?>
                    <span class="badge-upcoming"><i class="bi bi-clock me-1"></i>Geplant</span>
                    <?php else: ?>
                    <span class="badge-inactive">Beendet</span>
                    <?php endif; ?>
                    <a href="/admin-aktionen.php?edit=<?= (int)$al['id'] ?>"
                       class="btn btn-sm" style="background:none; border:1px solid rgba(255,255,255,.12); color:rgba(255,255,255,.5); padding:.15rem .4rem; font-size:.72rem;">
                        <i class="bi bi-pencil"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

</div>
</section>
</main>

<script>
(function() {
    const searchInput   = document.getElementById('film-search-input');
    const searchResults = document.getElementById('film-search-results');
    const chipsWrap     = document.getElementById('selected-films-wrap');
    const btnWrap       = document.getElementById('add-film-btn-wrap');
    const addBtn        = document.getElementById('add-film-btn');
    const addBtnLabel   = document.getElementById('add-btn-label');
    const spinner       = document.getElementById('add-film-spinner');
    const filmList      = document.getElementById('film-list-container');
    const emptyState    = document.getElementById('film-empty-state');
    const countBadge    = document.getElementById('film-count-badge');
    if (!searchInput) return;

    const listId  = <?= (int)$editId ?>;
    const csrf    = <?= json_encode(csrfToken()) ?>;
    // selected: Map<id, title>
    const selected = new Map();
    let filmCount  = <?= count($editFilms) ?>;

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function renderChips() {
        chipsWrap.innerHTML = '';
        if (selected.size === 0) {
            chipsWrap.style.display = 'none';
            btnWrap.style.display   = 'none';
            return;
        }
        chipsWrap.style.display = 'flex';
        btnWrap.style.display   = '';
        selected.forEach((title, id) => {
            const chip = document.createElement('span');
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:.35rem;background:rgba(232,184,75,.15);border:1px solid rgba(232,184,75,.3);color:#e8b84b;border-radius:20px;padding:3px 10px;font-size:.78rem;font-weight:600;cursor:default;';
            chip.innerHTML = `${escHtml(title)} <button type="button" onclick="removeSelected(${id})" style="background:none;border:none;color:rgba(232,184,75,.6);padding:0;cursor:pointer;font-size:.85rem;line-height:1;" title="Entfernen">&times;</button>`;
            chipsWrap.appendChild(chip);
        });
        addBtnLabel.textContent = selected.size === 1 ? '1 Film hinzufügen' : selected.size + ' Filme hinzufügen';
    }

    window.removeSelected = function(id) {
        selected.delete(id);
        renderChips();
    };

    function markSelectedInResults() {
        searchResults.querySelectorAll('.search-result-item').forEach(el => {
            const id = parseInt(el.dataset.id);
            if (selected.has(id)) {
                el.style.background = 'rgba(232,184,75,.18)';
                el.querySelector('.sel-check').style.display = '';
            } else {
                el.style.background = '';
                el.querySelector('.sel-check').style.display = 'none';
            }
        });
    }

    function buildFilmRow(f) {
        const poster = f.poster_path
            ? 'https://image.tmdb.org/t/p/w92' + f.poster_path
            : 'https://placehold.co/24x36/1e3a5f/e8b84b?text=?';
        const row = document.createElement('div');
        row.className = 'list-row';
        row.innerHTML = `
            <img src="${escHtml(poster)}" alt="" style="width:24px;height:36px;object-fit:cover;border-radius:3px;flex-shrink:0;"
                 onerror="this.src='https://placehold.co/24x36/1e3a5f/e8b84b?text=?'">
            <div style="flex:1;min-width:0;">
                <div style="color:#ddd;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(f.title)}</div>
                <div style="color:rgba(255,255,255,.35);font-size:.75rem;">${parseInt(f.year)||''}</div>
            </div>
            <form method="POST" style="flex-shrink:0;">
                <input type="hidden" name="action" value="remove_film">
                <input type="hidden" name="csrf_token" value="${escHtml(csrf)}">
                <input type="hidden" name="list_id" value="${listId}">
                <input type="hidden" name="movie_id" value="${parseInt(f.id)}">
                <button type="submit" class="btn btn-sm" style="background:none;border:1px solid rgba(244,67,54,.3);color:rgba(244,67,54,.7);padding:.15rem .4rem;font-size:.72rem;">
                    <i class="bi bi-x-lg"></i>
                </button>
            </form>`;
        return row;
    }

    addBtn.addEventListener('click', function() {
        if (selected.size === 0) return;
        addBtn.disabled     = true;
        spinner.style.display = '';

        const body = new URLSearchParams();
        body.append('action', 'add_film');
        body.append('ajax', '1');
        body.append('csrf_token', csrf);
        body.append('list_id', listId);
        selected.forEach((title, id) => body.append('movie_ids[]', id));

        fetch('/admin-aktionen.php', { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) throw new Error('Server error');
                data.added.forEach(f => {
                    filmList.appendChild(buildFilmRow(f));
                });
                filmCount = data.total;
                countBadge.textContent = '(' + filmCount + ')';
                if (filmCount > 0) {
                    emptyState.style.display = 'none';
                    filmList.style.display   = '';
                }
                selected.clear();
                searchInput.value = '';
                searchResults.style.display = 'none';
                renderChips();
            })
            .catch(() => alert('Fehler beim Speichern. Bitte Seite neu laden.'))
            .finally(() => {
                addBtn.disabled       = false;
                spinner.style.display = 'none';
            });
    });

    let debounceTimer;
    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const q = this.value.trim();
        if (q.length < 2) { searchResults.style.display = 'none'; return; }
        debounceTimer = setTimeout(() => {
            fetch('/admin-aktionen.php?action=search_films&q=' + encodeURIComponent(q) + '&list_id=' + listId)
                .then(r => r.json())
                .then(films => {
                    if (!films.length) {
                        searchResults.innerHTML = '<div style="padding:.6rem .9rem; color:rgba(255,255,255,.3); font-size:.85rem;">Keine Ergebnisse</div>';
                    } else {
                        searchResults.innerHTML = films.map(f =>
                            `<div class="search-result-item" data-id="${f.id}" data-title="${escHtml(f.title)}">
                                <i class="bi bi-check-lg sel-check" style="display:none;color:#e8b84b;flex-shrink:0;font-size:.8rem;"></i>
                                <span style="color:#e8b84b;font-size:.75rem;font-weight:700;flex-shrink:0;">${f.year}</span>
                                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(f.title)}</span>
                            </div>`
                        ).join('');
                        markSelectedInResults();
                        searchResults.querySelectorAll('.search-result-item').forEach(el => {
                            el.addEventListener('click', function() {
                                const id    = parseInt(this.dataset.id);
                                const title = this.dataset.title;
                                if (selected.has(id)) { selected.delete(id); } else { selected.set(id, title); }
                                renderChips();
                                markSelectedInResults();
                                searchInput.focus();
                            });
                        });
                    }
                    searchResults.style.display = 'block';
                })
                .catch(() => { searchResults.style.display = 'none'; });
        }, 220);
    });

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target) && !chipsWrap.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });
})();

// ── Person-Suche ──────────────────────────────────────────────────────────────
(function() {
    const pInput   = document.getElementById('person-search-input');
    const pResults = document.getElementById('person-search-results');
    const pStatus  = document.getElementById('person-add-status');
    if (!pInput) return;

    const listId = <?= (int)$editId ?>;
    const csrf   = <?= json_encode(csrfToken()) ?>;
    const filmList    = document.getElementById('film-list-container');
    const emptyState  = document.getElementById('film-empty-state');
    const countBadge  = document.getElementById('film-count-badge');

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function deptLabel(d) {
        return d === 'Directing' ? 'Regisseur' : d === 'Acting' ? 'Darsteller'
             : d === 'Production' ? 'Produzent' : d || '';
    }

    function buildFilmRow(f) {
        const poster = f.poster_path ? 'https://image.tmdb.org/t/p/w92' + f.poster_path : 'https://placehold.co/24x36/1e3a5f/e8b84b?text=?';
        const row = document.createElement('div');
        row.className = 'list-row';
        row.innerHTML = `<img src="${escHtml(poster)}" alt="" style="width:24px;height:36px;object-fit:cover;border-radius:3px;flex-shrink:0;" onerror="this.src='https://placehold.co/24x36/1e3a5f/e8b84b?text=?'">
            <div style="flex:1;min-width:0;"><div style="color:#ddd;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(f.title)}</div>
            <div style="color:rgba(255,255,255,.35);font-size:.75rem;">${parseInt(f.year)||''}</div></div>
            <form method="POST" style="flex-shrink:0;"><input type="hidden" name="action" value="remove_film"><input type="hidden" name="csrf_token" value="${escHtml(csrf)}"><input type="hidden" name="list_id" value="${listId}"><input type="hidden" name="movie_id" value="${parseInt(f.id)}"><button type="submit" class="btn btn-sm" style="background:none;border:1px solid rgba(244,67,54,.3);color:rgba(244,67,54,.7);padding:.15rem .4rem;font-size:.72rem;"><i class="bi bi-x-lg"></i></button></form>`;
        return row;
    }

    function showStatus(msg, ok) {
        pStatus.style.display = '';
        pStatus.style.color   = ok ? '#7ec87e' : '#f0a55a';
        pStatus.innerHTML = msg;
    }

    function renderPersonResult(p) {
        const el = document.createElement('div');
        el.style.cssText = 'padding:.55rem .8rem; border-bottom:1px solid rgba(255,255,255,.07);';

        // Vorauswahl Rolle basierend auf known_for_department
        const deptOpts = [
            ['all','Alle Rollen'], ['cast','Nur Darsteller'],
            ['director','Nur Regisseur'], ['producer','Nur Produzent']
        ].map(([v, l]) => `<option value="${v}"${p.dept === 'Directing' && v === 'director' ? ' selected' : p.dept === 'Acting' && v === 'cast' ? ' selected' : p.dept === 'Production' && v === 'producer' ? ' selected' : ''}>${l}</option>`).join('');

        const mediaOpts = `<option value="both">Filme+Serien</option><option value="movie">Nur Filme</option><option value="tv">Nur Serien</option>`;

        el.innerHTML = `
          <div class="d-flex align-items-center gap-2">
            ${p.profile ? `<img src="https://image.tmdb.org/t/p/w45${escHtml(p.profile)}" alt="" style="width:32px;height:44px;object-fit:cover;border-radius:4px;flex-shrink:0;" onerror="this.style.display='none'">` : `<div style="width:32px;height:44px;background:rgba(255,255,255,.08);border-radius:4px;flex-shrink:0;"></div>`}
            <div style="flex:1;min-width:0;">
              <div style="font-weight:600;font-size:.88rem;">${escHtml(p.name)}</div>
              <div style="font-size:.73rem;color:rgba(255,255,255,.4);">${escHtml(deptLabel(p.dept))}${p.known_for ? ' · ' + escHtml(p.known_for) : ''}</div>
            </div>
            <div class="d-flex gap-1 flex-shrink-0">
              <select class="form-select form-select-sm p-dept" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.2);color:#fff;font-size:.75rem;min-width:120px;">${deptOpts}</select>
              <select class="form-select form-select-sm p-media" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.2);color:#fff;font-size:.75rem;min-width:100px;">${mediaOpts}</select>
              <button type="button" class="btn btn-sm p-add-btn" style="background:#a78bfa;color:#fff;white-space:nowrap;font-size:.75rem;padding:.25rem .6rem;" data-pid="${p.id}">
                <i class="bi bi-plus-lg"></i> Hinzufügen
              </button>
            </div>
          </div>`;

        el.querySelector('.p-add-btn').addEventListener('click', function() {
            const btn   = this;
            const dept  = el.querySelector('.p-dept').value;
            const media = el.querySelector('.p-media').value;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            showStatus('<i class="bi bi-hourglass-split me-1"></i>Lade Credits von TMDB…', true);

            const body = new URLSearchParams({ action: 'add_person_films', ajax: '1', csrf_token: csrf, list_id: listId, person_id: p.id, person_dept: dept, person_media: media });
            fetch('/admin-aktionen.php', { method: 'POST', body })
                .then(r => r.json())
                .then(data => {
                    if (!data.ok) throw new Error();
                    data.added.forEach(f => filmList.appendChild(buildFilmRow(f)));
                    if (data.total > 0) { emptyState.style.display = 'none'; filmList.style.display = ''; }
                    countBadge.textContent = '(' + data.total + ')';
                    const inDb = data.added.length;
                    showStatus(`<i class="bi bi-check-circle me-1"></i><strong>${inDb} Filme</strong> hinzugefügt (${data.tmdb_total} TMDB-Credits, ${data.tmdb_total - inDb} noch nicht in DB)`, true);
                    pInput.value = '';
                    pResults.style.display = 'none';
                    btn.innerHTML = '<i class="bi bi-check-lg"></i>';
                })
                .catch(() => { showStatus('Fehler beim Laden. TMDB erreichbar?', false); btn.disabled = false; btn.innerHTML = '<i class="bi bi-plus-lg"></i> Hinzufügen'; });
        });
        return el;
    }

    let pTimer;
    pInput.addEventListener('input', function() {
        clearTimeout(pTimer);
        const q = this.value.trim();
        if (q.length < 2) { pResults.style.display = 'none'; return; }
        pTimer = setTimeout(() => {
            fetch('/admin-aktionen.php?action=search_person&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(persons => {
                    pResults.innerHTML = '';
                    if (!persons.length) {
                        pResults.innerHTML = '<div style="padding:.5rem .8rem;color:rgba(255,255,255,.3);font-size:.83rem;">Keine Person gefunden</div>';
                    } else {
                        persons.forEach(p => pResults.appendChild(renderPersonResult(p)));
                    }
                    pResults.style.display = 'block';
                });
        }, 280);
    });

    document.addEventListener('click', function(e) {
        if (!pInput.contains(e.target) && !pResults.contains(e.target)) pResults.style.display = 'none';
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
