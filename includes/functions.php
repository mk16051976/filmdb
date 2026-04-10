<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/lang.php';

// ── Session ──────────────────────────────────────────────────────────────────
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        // CSRF-Token einmalig pro Session generieren
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
}

/** Gibt den CSRF-Token der aktuellen Session zurück. */
function csrfToken(): string {
    startSession();
    return $_SESSION['csrf_token'];
}

/**
 * Prüft den CSRF-Token aus dem POST-Request.
 * Gibt true zurück wenn gültig, false wenn ungültig.
 */
function csrfValid(): bool {
    startSession();
    $submitted = $_POST['csrf_token'] ?? '';
    return $submitted !== '' && hash_equals($_SESSION['csrf_token'], $submitted);
}

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
    // Force-logout if the user was blocked while already logged in
    if (isCurrentUserBlocked()) {
        session_destroy();
        startSession();
        header('Location: /login.php?blocked=1');
        exit;
    }
}

/**
 * Returns the user's current phase:
 *   1 = guest (not logged in)
 *   2 = logged in, Onboarding noch nicht abgeschlossen
 *   3 = logged in, Onboarding abgeschlossen → Vollzugang (Freies Spiel)
 */
function userPhase(): int {
    if (!isLoggedIn()) return 1;
    static $phase = null;
    if ($phase !== null) return $phase;
    $db = getDB();

    try {
        // Onboarding abgeschlossen (Turnier gestartet ODER "Offene Website" gewählt)
        $stmt = $db->prepare("SELECT onboarding_done FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row && (int)$row['onboarding_done'] === 1) return $phase = 3;
    } catch (\PDOException $e) {}

    return $phase = 2;
}

/**
 * Requires the logged-in user to be in at least the given phase.
 * Guests are unaffected (they are handled by requireLogin separately).
 * Phase 2 users are redirected to the tournament; Phase 3 users to the liga.
 */
function requirePhase(int $min): void {
    if (!isLoggedIn() || isAdmin()) return;
    $current = userPhase();
    if ($current >= $min) return;
    if ($current <= 2) {
        header('Location: /turnier.php?phase_blocked=1');
    } else {
        header('Location: /jgj.php?phase_blocked=1');
    }
    exit;
}

/**
 * Returns the role of the current user ('Admin', 'Bewerter', …).
 * Runs the role-column migration on first call.
 */
function userRole(): string {
    if (!isLoggedIn()) return 'Gast';
    static $role = null;
    if ($role !== null) return $role;

    $db = getDB();

    static $roleMigrated = false;
    if (!$roleMigrated) {
        $db->exec("ALTER TABLE users
            ADD COLUMN IF NOT EXISTS role    VARCHAR(50) NOT NULL DEFAULT 'Bewerter',
            ADD COLUMN IF NOT EXISTS blocked TINYINT(1)  NOT NULL DEFAULT 0");
        // Assign Admin role to the designated user
        if (defined('ADMIN_USERNAME') && ADMIN_USERNAME !== '') {
            $db->prepare("UPDATE users SET role = 'Admin' WHERE username = ? AND role NOT IN ('Admin','Superadmin')")
               ->execute([ADMIN_USERNAME]);
        }
        // Users 1 and 2 are always Superadmin
        $db->exec("UPDATE users SET role = 'Superadmin' WHERE id IN (1,2)");

        // Ensure jgj_complete tables exist
        $db->exec("CREATE TABLE IF NOT EXISTS jgj_complete_pairs (
            user_id   INT UNSIGNED NOT NULL,
            film_a_id INT UNSIGNED NOT NULL,
            film_b_id INT UNSIGNED NOT NULL,
            winner_id INT UNSIGNED NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (user_id, film_a_id, film_b_id),
            INDEX idx_unevaluated (user_id, winner_id, film_a_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS jgj_complete_scores (
            user_id  INT UNSIGNED NOT NULL,
            movie_id INT UNSIGNED NOT NULL,
            wins     INT UNSIGNED NOT NULL DEFAULT 0,
            losses   INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, movie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $roleMigrated = true;
    }

    $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $row  = $stmt->fetch();
    $role = $row ? (string)$row['role'] : 'Bewerter';
    return $role;
}

/**
 * Returns true when the current user has the 'Admin' role.
 */
function isAdmin(): bool {
    return in_array(userRole(), ['Admin', 'Superadmin']);
}

/**
 * Returns true for Superadmin users (IDs 1 and 2).
 */
function isSuperAdmin(): bool {
    return userRole() === 'Superadmin';
}

/**
 * Returns true if the current user wants series included in duels/rankings.
 * Defaults to true (show series) for new users.
 */
function userShowsSeries(): bool {
    if (!isLoggedIn()) return true;
    static $val = null;
    if ($val !== null) return $val;
    try {
        $db = getDB();
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS show_series TINYINT(1) NOT NULL DEFAULT 1");
        $s = $db->prepare("SELECT show_series FROM users WHERE id = ?");
        $s->execute([(int)$_SESSION['user_id']]);
        $raw = $s->fetchColumn();
        $val = ($raw === false || $raw === null) ? true : (bool)(int)$raw;
    } catch (\PDOException $e) {
        $val = true;
    }
    return $val;
}

/**
 * Returns 'movie' or 'tv' for use as a DB column value.
 * Maps 'all' → 'movie' for backward compatibility with existing data.
 */
function activeMtForDb(): string {
    return getActiveMtFilter() === 'tv' ? 'tv' : 'movie';
}

/**
 * Returns the active media-type navigation filter: 'movie', 'tv', or 'all'.
 * Set via ?mt= GET param (stored in session); falls back to session on AJAX calls.
 */
function getActiveMtFilter(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) return 'all';
    if (isset($_GET['mt']) && in_array($_GET['mt'], ['movie', 'tv'])) {
        $_SESSION['mt_filter'] = $_GET['mt'];
        return $_GET['mt'];
    }
    return (isset($_SESSION['mt_filter']) && in_array($_SESSION['mt_filter'], ['movie', 'tv']))
        ? $_SESSION['mt_filter'] : 'all';
}

/**
 * Returns a SQL WHERE fragment to exclude TV series when the user has disabled them.
 * The alias should match the movies table alias in the calling query.
 */
function seriesSqlFilter(string $alias = 'm'): string {
    $mt = getActiveMtFilter();
    if ($mt === 'tv')    return '';  // only series → keep all series
    if ($mt === 'movie') return " AND COALESCE({$alias}.media_type,'movie') = 'movie'";
    // Default: respect user profile setting
    if (userShowsSeries()) return '';
    return " AND COALESCE({$alias}.media_type,'movie') = 'movie'";
}

/**
 * Returns true if the current user wants movies included in duels/rankings.
 * Defaults to true (show movies) for new users.
 */
function userShowsMovies(): bool {
    if (!isLoggedIn()) return true;
    static $val = null;
    if ($val !== null) return $val;
    try {
        $db = getDB();
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS show_movies TINYINT(1) NOT NULL DEFAULT 1");
        $s = $db->prepare("SELECT show_movies FROM users WHERE id = ?");
        $s->execute([(int)$_SESSION['user_id']]);
        $raw = $s->fetchColumn();
        $val = ($raw === false || $raw === null) ? true : (bool)(int)$raw;
    } catch (\PDOException $e) {
        $val = true;
    }
    return $val;
}

/**
 * Returns a SQL WHERE fragment to exclude movies when the user has disabled them.
 */
function moviesSqlFilter(string $alias = 'm'): string {
    $mt = getActiveMtFilter();
    if ($mt === 'movie') return '';  // only movies → keep all movies
    if ($mt === 'tv')    return " AND COALESCE({$alias}.media_type,'movie') != 'movie'";
    // Default: respect user profile setting
    if (userShowsMovies()) return '';
    return " AND COALESCE({$alias}.media_type,'movie') != 'movie'";
}

/**
 * SQL-Fragment das ausgeblendete Filme des eingeloggten Users ausschließt.
 * $alias muss auf die movies-Tabelle zeigen.
 */
function hiddenFilmsSqlFilter(string $alias = 'm'): string {
    if (!isset($_SESSION['user_id'])) return '';
    $userId = (int)$_SESSION['user_id'];
    return " AND {$alias}.id NOT IN (SELECT movie_id FROM user_hidden_films WHERE user_id = {$userId})";
}

/**
 * Returns true when the current user has the 'Moderator' role.
 */
function isModerator(): bool {
    return userRole() === 'Moderator';
}

/**
 * Returns true when the current user can moderate content (Admin or Moderator).
 */
function canModerate(): bool {
    return isAdmin() || isModerator();
}

/**
 * Returns true when the current user can author content (Superadmin, Admin, Autor).
 */
function canAuthor(): bool {
    return in_array(userRole(), ['Superadmin', 'Admin', 'Autor']);
}

/**
 * Renders BBCode formatting toolbar buttons for a textarea.
 * Requires insertBB() JS function to be present on the page.
 */
function forumToolbar(string $taId): void {
    $btns = [
        ['[b]','[/b]',         'B',  'font-weight:700;',             'Fett'],
        ['[i]','[/i]',         'I',  'font-style:italic;',           'Kursiv'],
        ['[u]','[/u]',         'U',  'text-decoration:underline;',   'Unterstrichen'],
        ['[s]','[/s]',         'S',  'text-decoration:line-through;','Durchgestrichen'],
        ['[big]','[/big]',     'A+', 'font-size:1.1em;',             'Größer'],
        ['[small]','[/small]', 'A−', 'font-size:.85em;',             'Kleiner'],
    ];
    echo '<div style="display:flex;flex-wrap:wrap;gap:3px;padding:5px 8px;background:rgba(255,255,255,.06);'
       . 'border:1px solid rgba(255,255,255,.2);border-bottom:none;border-radius:8px 8px 0 0;">';
    foreach ($btns as [$open, $close, $label, $style, $title]) {
        echo '<button type="button" title="' . htmlspecialchars($title, ENT_QUOTES) . '"'
           . ' onclick="insertBB(' . json_encode($taId) . ',' . json_encode($open) . ',' . json_encode($close) . ')"'
           . ' style="background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.15);color:#e0e0e0;'
           . 'border-radius:4px;padding:2px 9px;font-size:.8rem;cursor:pointer;line-height:1.6;' . $style . '">'
           . htmlspecialchars($label) . '</button>';
    }
    echo '</div>';
}

/**
 * Returns true when the current logged-in user is blocked.
 * Uses static caching to avoid multiple DB queries per request.
 */
function isCurrentUserBlocked(): bool {
    if (!isLoggedIn()) return false;
    static $blocked = null;
    if ($blocked !== null) return $blocked;
    $db = getDB();
    try {
        $stmt = $db->prepare('SELECT COALESCE(blocked, 0) FROM users WHERE id = ?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $blocked = (bool)(int)$stmt->fetchColumn();
    } catch (\PDOException $e) {
        $blocked = false;
    }
    return $blocked;
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    $db = getDB();
    $stmt = $db->prepare('SELECT id, username, email, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

// ── Brute-Force-Schutz ───────────────────────────────────────────────────────
// Max. RATE_LIMIT_MAX Fehlversuche innerhalb von RATE_LIMIT_WINDOW Sekunden pro IP.
const RATE_LIMIT_MAX    = 5;
const RATE_LIMIT_WINDOW = 900; // 15 Minuten

function ensureLoginAttemptsTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip           VARCHAR(45) NOT NULL,
        attempted_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip (ip, attempted_at)
    )");
    $done = true;
}

function isRateLimited(string $ip): bool {
    $db = getDB();
    ensureLoginAttemptsTable($db);
    $since = date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW);
    $stmt  = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?");
    $stmt->execute([$ip, $since]);
    return (int)$stmt->fetchColumn() >= RATE_LIMIT_MAX;
}

function recordFailedLogin(string $ip): void {
    $db = getDB();
    ensureLoginAttemptsTable($db);
    $db->prepare("INSERT INTO login_attempts (ip) VALUES (?)")->execute([$ip]);
    // Alte Einträge aufräumen
    $cutoff = date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW * 4);
    $db->prepare("DELETE FROM login_attempts WHERE attempted_at < ?")->execute([$cutoff]);
}

function clearLoginAttempts(string $ip): void {
    $db = getDB();
    ensureLoginAttemptsTable($db);
    $db->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
}

// ── Auth ─────────────────────────────────────────────────────────────────────
function loginUser(string $email, string $password): bool|string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (isRateLimited($ip)) {
        return 'rate_limited';
    }

    $db = getDB();
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS blocked          TINYINT(1) NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL");

    $stmt = $db->prepare('SELECT id, password, COALESCE(blocked,0) AS blocked, email_verified_at FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        if ((int)$user['blocked'] === 1) {
            return 'blocked';
        }
        if ($user['email_verified_at'] === null) {
            return 'unverified';
        }
        clearLoginAttempts($ip);
        startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        return true;
    }

    recordFailedLogin($ip);
    return false;
}

function registerUser(
    string $username,
    string $email,
    string $password,
    string $gender,
    string $nationality,
    int    $birthYear,
    string $favoriteGenre
): bool|string {
    $db = getDB();

    // One-time migration: add profile columns if not yet present
    static $migrated = false;
    if (!$migrated) {
        $db->exec("ALTER TABLE users
            ADD COLUMN IF NOT EXISTS gender             VARCHAR(20)  NULL,
            ADD COLUMN IF NOT EXISTS nationality        VARCHAR(100) NULL,
            ADD COLUMN IF NOT EXISTS birth_year         SMALLINT     NULL,
            ADD COLUMN IF NOT EXISTS favorite_genre     VARCHAR(100) NULL,
            ADD COLUMN IF NOT EXISTS role               VARCHAR(50)  NOT NULL DEFAULT 'Bewerter',
            ADD COLUMN IF NOT EXISTS email_verified_at  DATETIME     NULL");
        $migrated = true;
    }

    // Duplikat-Check
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? OR username = ?');
    $stmt->execute([$email, $username]);
    if ($stmt->fetch()) {
        return 'E-Mail oder Benutzername bereits vergeben.';
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        'INSERT INTO users (username, email, password, gender, nationality, birth_year, favorite_genre, role)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$username, $email, $hash, $gender, $nationality, $birthYear, $favoriteGenre, 'Bewerter']);
    $newId = (int)$db->lastInsertId();

    sendVerificationEmail($newId, $email);
    return 'verify';
}

function sendVerificationEmail(int $userId, string $email): void {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS email_verifications (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        token      VARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_user  (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$userId]);

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 86400);
    $db->prepare('INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)')
       ->execute([$userId, $token, $expires]);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $link   = "{$scheme}://{$host}/verify-email.php?token={$token}";

    $body = "Hallo,\n\nbitte bestätige deine E-Mail-Adresse für MKFB, indem du auf den folgenden Link klickst:\n\n{$link}\n\nDer Link ist 24 Stunden gültig.\n\nFalls du kein Konto bei MKFB erstellt hast, kannst du diese E-Mail ignorieren.\n\nViele Grüße\nDas MKFB-Team";
    sendMail($email, 'E-Mail-Adresse bestätigen – MKFB', $body);
}

// ── ELO ──────────────────────────────────────────────────────────────────────
function calcElo(int $winnerElo, int $loserElo, int $k = 32): array {
    $expectedWinner = 1 / (1 + pow(10, ($loserElo - $winnerElo) / 400));
    $expectedLoser  = 1 - $expectedWinner;
    return [
        'winner' => (int)round($winnerElo + $k * (1 - $expectedWinner)),
        'loser'  => (int)round($loserElo  + $k * (0 - $expectedLoser)),
    ];
}

function ensureRating(int $userId, int $movieId): void {
    $db = getDB();
    $db->prepare('INSERT IGNORE INTO user_ratings (user_id, movie_id) VALUES (?, ?)')
       ->execute([$userId, $movieId]);
}

function getOrCreateRating(int $userId, int $movieId): array {
    ensureRating($userId, $movieId);
    $db = getDB();
    $stmt = $db->prepare('SELECT elo, wins, losses, comparisons, user_id, movie_id FROM user_ratings WHERE user_id = ? AND movie_id = ?');
    $stmt->execute([$userId, $movieId]);
    return $stmt->fetch();
}

/**
 * Rebuild position ranking for a user from ELO scores in a single query.
 * Much faster than incremental updates for bulk operations (tournaments).
 */
function rebuildPositionRankingFromElo(int $userId): void {
    $db = getDB();
    $db->prepare("
        INSERT INTO user_position_ranking (user_id, movie_id, position)
        SELECT ?, movie_id, ROW_NUMBER() OVER (ORDER BY elo DESC)
        FROM user_ratings WHERE user_id = ? AND comparisons > 0
        ON DUPLICATE KEY UPDATE position = VALUES(position)
    ")->execute([$userId, $userId]);
}

function recordComparison(int $userId, int $winnerId, int $loserId, bool $skipPositionUpdate = false): void {
    $db = getDB();

    // One-time migration per session: store previous ELO for undo support
    if (empty($_SESSION['cmp_migrated'])) {
        $db->exec("ALTER TABLE comparisons
            ADD COLUMN IF NOT EXISTS prev_winner_elo SMALLINT NULL,
            ADD COLUMN IF NOT EXISTS prev_loser_elo  SMALLINT NULL");
        $_SESSION['cmp_migrated'] = true;
    }

    $wRating = getOrCreateRating($userId, $winnerId);
    $lRating = getOrCreateRating($userId, $loserId);

    $prevWElo = (int)$wRating['elo'];
    $prevLElo = (int)$lRating['elo'];
    $newElo   = calcElo($prevWElo, $prevLElo);

    $db->prepare('UPDATE user_ratings SET elo=?, wins=wins+1, comparisons=comparisons+1 WHERE user_id=? AND movie_id=?')
       ->execute([$newElo['winner'], $userId, $winnerId]);
    $db->prepare('UPDATE user_ratings SET elo=?, losses=losses+1, comparisons=comparisons+1 WHERE user_id=? AND movie_id=?')
       ->execute([$newElo['loser'], $userId, $loserId]);
    $db->prepare('INSERT INTO comparisons (user_id, winner_id, loser_id, prev_winner_elo, prev_loser_elo) VALUES (?, ?, ?, ?, ?)')
       ->execute([$userId, $winnerId, $loserId, $prevWElo, $prevLElo]);

    if (!$skipPositionUpdate) {
        updatePositionRanking($userId, $winnerId, $loserId);
    }

    // Superadmin: update Jeder-gegen-Jeden-Spielplan
    if (isSuperAdmin()) {
        updateJgjCompletePair($db, $userId, $winnerId, $loserId);
    }
}

function updateJgjCompletePair(PDO $db, int $userId, int $winnerId, int $loserId): void {
    $a = min($winnerId, $loserId);
    $b = max($winnerId, $loserId);

    // Get old winner (if pair exists)
    $old = $db->prepare("SELECT winner_id FROM jgj_complete_pairs WHERE user_id=? AND film_a_id=? AND film_b_id=?");
    $old->execute([$userId, $a, $b]);
    $oldWinner = $old->fetchColumn();

    if ($oldWinner === false) {
        // Pair not in schedule yet (e.g. film added after build) → insert
        $db->prepare("INSERT IGNORE INTO jgj_complete_pairs (user_id,film_a_id,film_b_id,winner_id,updated_at) VALUES(?,?,?,?,NOW())")
           ->execute([$userId, $a, $b, $winnerId]);
        // Update scores
        $db->prepare("INSERT INTO jgj_complete_scores (user_id,movie_id,wins) VALUES(?,?,1) ON DUPLICATE KEY UPDATE wins=wins+1")
           ->execute([$userId, $winnerId]);
        $db->prepare("INSERT INTO jgj_complete_scores (user_id,movie_id,losses) VALUES(?,?,1) ON DUPLICATE KEY UPDATE losses=losses+1")
           ->execute([$userId, $loserId]);
    } else {
        $oldWinner = (int)$oldWinner;
        // Update pair result
        $db->prepare("UPDATE jgj_complete_pairs SET winner_id=?,updated_at=NOW() WHERE user_id=? AND film_a_id=? AND film_b_id=?")
           ->execute([$winnerId, $userId, $a, $b]);

        if ($oldWinner !== $winnerId) {
            // Winner changed → adjust scores
            $oldLoser = ($oldWinner === $winnerId) ? $loserId : $winnerId;
            // Decrement old winner's wins & old loser's losses
            $db->prepare("UPDATE jgj_complete_scores SET wins=GREATEST(0,wins-1) WHERE user_id=? AND movie_id=?")
               ->execute([$userId, $oldWinner]);
            $db->prepare("UPDATE jgj_complete_scores SET losses=GREATEST(0,losses-1) WHERE user_id=? AND movie_id=?")
               ->execute([$userId, $oldLoser]);
            // Increment new winner/loser
            $db->prepare("INSERT INTO jgj_complete_scores (user_id,movie_id,wins) VALUES(?,?,1) ON DUPLICATE KEY UPDATE wins=wins+1")
               ->execute([$userId, $winnerId]);
            $db->prepare("INSERT INTO jgj_complete_scores (user_id,movie_id,losses) VALUES(?,?,1) ON DUPLICATE KEY UPDATE losses=losses+1")
               ->execute([$userId, $loserId]);
        }
        // If same winner → no score change needed
    }
}

function updatePositionRanking(int $userId, int $winnerId, int $loserId): void {
    $db = getDB();

    static $tableCreated = false;
    if (!$tableCreated) {
        $db->exec("CREATE TABLE IF NOT EXISTS user_position_ranking (
            user_id  INT NOT NULL,
            movie_id INT NOT NULL,
            position INT UNSIGNED NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, movie_id),
            INDEX idx_user_pos (user_id, position),
            INDEX idx_movie_pos (movie_id, position)
        )");
        // Nachträglich Index anlegen falls Tabelle bereits existierte
        try { $db->exec("ALTER TABLE user_position_ranking ADD INDEX idx_movie_pos (movie_id, position)"); } catch (\PDOException $e) {}
        $tableCreated = true;
    }

    // Fetch current positions of both films for this user
    $stmt = $db->prepare(
        "SELECT movie_id, position FROM user_position_ranking
         WHERE user_id = ? AND movie_id IN (?, ?)"
    );
    $stmt->execute([$userId, $winnerId, $loserId]);
    $rows = $stmt->fetchAll();

    $winnerPos = null;
    $loserPos  = null;
    foreach ($rows as $row) {
        if ((int)$row['movie_id'] === $winnerId) $winnerPos = (int)$row['position'];
        if ((int)$row['movie_id'] === $loserId)  $loserPos  = (int)$row['position'];
    }

    // Case 1: Neither in ranking → append winner first, then loser
    if ($winnerPos === null && $loserPos === null) {
        $maxStmt = $db->prepare("SELECT COALESCE(MAX(position), 0) FROM user_position_ranking WHERE user_id = ?");
        $maxStmt->execute([$userId]);
        $maxPos = (int)$maxStmt->fetchColumn();
        $db->prepare("INSERT INTO user_position_ranking (user_id, movie_id, position) VALUES (?, ?, ?)")
           ->execute([$userId, $winnerId, $maxPos + 1]);
        $db->prepare("INSERT INTO user_position_ranking (user_id, movie_id, position) VALUES (?, ?, ?)")
           ->execute([$userId, $loserId, $maxPos + 2]);
        return;
    }

    // Case 2: Only loser in ranking → insert winner at loser's position, shift loser and rest back
    if ($winnerPos === null && $loserPos !== null) {
        $db->prepare("UPDATE user_position_ranking SET position = position + 1
                      WHERE user_id = ? AND position >= ?")
           ->execute([$userId, $loserPos]);
        $db->prepare("INSERT INTO user_position_ranking (user_id, movie_id, position) VALUES (?, ?, ?)")
           ->execute([$userId, $winnerId, $loserPos]);
        return;
    }

    // Case 3: Only winner in ranking → append loser at end (winner stays)
    if ($winnerPos !== null && $loserPos === null) {
        $maxStmt = $db->prepare("SELECT COALESCE(MAX(position), 0) FROM user_position_ranking WHERE user_id = ?");
        $maxStmt->execute([$userId]);
        $maxPos = (int)$maxStmt->fetchColumn();
        $db->prepare("INSERT INTO user_position_ranking (user_id, movie_id, position) VALUES (?, ?, ?)")
           ->execute([$userId, $loserId, $maxPos + 1]);
        return;
    }

    // Case 4: Both in ranking AND winner has worse rank (higher position number) → bubble up
    if ($winnerPos > $loserPos) {
        // Shift films from loser's position up to (but not including) winner's position back by 1
        $db->prepare("UPDATE user_position_ranking SET position = position + 1
                      WHERE user_id = ? AND position >= ? AND position < ?")
           ->execute([$userId, $loserPos, $winnerPos]);
        // Place winner at loser's old position
        $db->prepare("UPDATE user_position_ranking SET position = ? WHERE user_id = ? AND movie_id = ?")
           ->execute([$loserPos, $userId, $winnerId]);
    }
    // If winner already has better rank → no change needed
}

// ── Duel-Result-Helper (für Sidebar-Statistiken) ──────────────────────────────
/**
 * Fetch old positions + titles of both films BEFORE recordComparison is called.
 * Returns an array to include in the AJAX JSON response as 'duel_result'.
 */
function buildDuelResult(PDO $db, int $userId, int $winnerId, int $loserId): array {
    $s = $db->prepare(
        "SELECT movie_id, position FROM user_position_ranking WHERE user_id=? AND movie_id IN (?,?)"
    );
    $s->execute([$userId, $winnerId, $loserId]);
    $pos = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'position', 'movie_id');

    $t = $db->prepare("SELECT id, title FROM movies WHERE id IN (?,?)");
    $t->execute([$winnerId, $loserId]);
    $titles = array_column($t->fetchAll(PDO::FETCH_ASSOC), 'title', 'id');

    $oldWinnerPos = isset($pos[$winnerId]) ? (int)$pos[$winnerId] : null;
    $oldLoserPos  = isset($pos[$loserId])  ? (int)$pos[$loserId]  : null;

    return [
        'winner_title'   => $titles[$winnerId] ?? '',
        'loser_title'    => $titles[$loserId]  ?? '',
        'winner_old_pos' => $oldWinnerPos,
        'loser_old_pos'  => $oldLoserPos,
        // true = winner had worse rank (higher number) → ranking changes
        'rank_changed'   => ($oldWinnerPos !== null && $oldLoserPos !== null
                             && $oldWinnerPos > $oldLoserPos),
    ];
}

/**
 * Community-Rang für einen Film: Anzahl Filme mit besserem Ø + 1.
 * $mediaType = 'movie'|'tv'|'all' — filtert den Vergleich auf denselben Typ.
 */
function _commRankForMovie(PDO $db, int $movieId, string $mediaType = 'all'): int {
    static $cache = [];
    $key = $movieId . '_' . $mediaType;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $commUserFilter = " AND COALESCE(u.community_excluded,0) = 0 AND u.id IN (SELECT DISTINCT user_id FROM user_tournaments WHERE status = 'completed')";
        $s = $db->prepare("SELECT AVG(upr.position) FROM user_position_ranking upr JOIN users u ON u.id = upr.user_id WHERE upr.movie_id = ?{$commUserFilter}");
        $s->execute([$movieId]);
        $avg = $s->fetchColumn();
        if ($avg === false || $avg === null) { $cache[$key] = 0; return 0; }
        $typeJoin   = $mediaType !== 'all' ? " JOIN movies m ON m.id = upr.movie_id" : '';
        $typeFilter = $mediaType === 'tv'    ? " AND COALESCE(m.media_type,'movie') = 'tv'"
                    : ($mediaType === 'movie' ? " AND COALESCE(m.media_type,'movie') != 'tv'"
                    : '');
        $s2 = $db->prepare(
            "SELECT COUNT(*) FROM (
                 SELECT upr.movie_id FROM user_position_ranking upr
                 JOIN users u ON u.id = upr.user_id{$typeJoin}
                 WHERE 1=1{$commUserFilter}{$typeFilter}
                 GROUP BY upr.movie_id HAVING AVG(upr.position) < ?
             ) sub"
        );
        $s2->execute([(float)$avg]);
        $cache[$key] = (int)$s2->fetchColumn() + 1;
    } catch (\PDOException $e) { $cache[$key] = 0; }
    return $cache[$key];
}

/**
 * Liefert Community- und persönlichen Rang für zwei Filme.
 * $mediaType = 'movie'|'tv'|'all' — filtert Rankings auf denselben Typ.
 */
function buildCommRanks(PDO $db, int $idA, string $titleA, int $idB, string $titleB, int $userId = 0, string $mediaType = 'all'): ?array {
    try {
        $rankA = _commRankForMovie($db, $idA, $mediaType);
        $rankB = _commRankForMovie($db, $idB, $mediaType);

        $myPosA = null; $myPosB = null;
        if ($userId > 0) {
            if ($mediaType === 'tv' || $mediaType === 'movie') {
                // Typgefilterte Positionsnummer (1-N nur innerhalb des Typs)
                $typeFilter = $mediaType === 'tv'
                    ? " AND COALESCE(m.media_type,'movie') = 'tv'"
                    : " AND COALESCE(m.media_type,'movie') != 'tv'";
                $gp = $db->prepare("SELECT position FROM user_position_ranking WHERE user_id=? AND movie_id=?");
                $cb = $db->prepare(
                    "SELECT COUNT(*) FROM user_position_ranking upr
                     JOIN movies m ON m.id = upr.movie_id
                     WHERE upr.user_id=? AND upr.position < ?{$typeFilter}"
                );
                $gp->execute([$userId, $idA]);
                $rawA = $gp->fetchColumn();
                if ($rawA !== false) {
                    $cb->execute([$userId, (int)$rawA]);
                    $myPosA = (int)$cb->fetchColumn() + 1;
                }
                $gp->execute([$userId, $idB]);
                $rawB = $gp->fetchColumn();
                if ($rawB !== false) {
                    $cb->execute([$userId, (int)$rawB]);
                    $myPosB = (int)$cb->fetchColumn() + 1;
                }
            } else {
                $p = $db->prepare(
                    "SELECT movie_id, position FROM user_position_ranking WHERE user_id = ? AND movie_id IN (?, ?)"
                );
                $p->execute([$userId, $idA, $idB]);
                $myMap  = array_column($p->fetchAll(PDO::FETCH_ASSOC), 'position', 'movie_id');
                $myPosA = isset($myMap[$idA]) ? (int)$myMap[$idA] : null;
                $myPosB = isset($myMap[$idB]) ? (int)$myMap[$idB] : null;
            }
        }

        return [
            'a_id'      => $idA,   'a_title' => $titleA,
            'a_rank'    => $rankA, 'a_my_pos' => $myPosA,
            'b_id'      => $idB,   'b_title' => $titleB,
            'b_rank'    => $rankB, 'b_my_pos' => $myPosB,
        ];
    } catch (\PDOException $e) {
        return null;
    }
}

/**
 * Fetch 5-film context windows around the winner after a vote:
 *   - Community ranking: winner ±2 positions (filtered by $mediaType)
 *   - Personal ranking:  winner ±2 positions (filtered by $mediaType)
 * Called AFTER recordComparison so positions are already updated.
 */
function buildWinnerContext(PDO $db, int $userId, int $winnerId, string $mediaType = 'all'): array {
    $result = ['winner_id' => $winnerId, 'comm' => [], 'mine' => [],
               'comm_winner_rank' => 0, 'mine_winner_pos' => 0];

    $typeJoin   = $mediaType !== 'all' ? " JOIN movies m ON m.id = upr.movie_id" : '';
    $typeFilter = $mediaType === 'tv'    ? " AND COALESCE(m.media_type,'movie') = 'tv'"
                : ($mediaType === 'movie' ? " AND COALESCE(m.media_type,'movie') != 'tv'"
                : '');

    // ── Community context ─────────────────────────────────────────────────
    try {
        $wCr = _commRankForMovie($db, $winnerId, $mediaType);
        if ($wCr > 0) {
            $result['comm_winner_rank'] = $wCr;
            $commUF = " AND COALESCE(u.community_excluded,0) = 0 AND u.id IN (SELECT DISTINCT user_id FROM user_tournaments WHERE status = 'completed')";
        $sAvg = $db->prepare("SELECT AVG(upr.position) FROM user_position_ranking upr JOIN users u ON u.id = upr.user_id WHERE upr.movie_id = ?{$commUF}");
            $sAvg->execute([$winnerId]);
            $winnerAvg = (float)$sAvg->fetchColumn();
            // 5 Nachbarn nach Durchschnittsposition (typgefiltert)
            $sN = $db->prepare(
                "SELECT upr.movie_id AS id, m.title, AVG(upr.position) AS avg_pos
                 FROM user_position_ranking upr
                 JOIN movies m ON m.id = upr.movie_id
                 JOIN users u ON u.id = upr.user_id
                 WHERE 1=1{$commUF}{$typeFilter}
                 GROUP BY upr.movie_id, m.title
                 ORDER BY ABS(AVG(upr.position) - ?)
                 LIMIT 3"
            );
            $sN->execute([$winnerAvg]);
            foreach ($sN->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $cr = _commRankForMovie($db, (int)$n['id'], $mediaType);
                $result['comm'][] = ['pos' => $cr, 'id' => (int)$n['id'], 'title' => $n['title']];
            }
            usort($result['comm'], fn($a, $b) => $a['pos'] <=> $b['pos']);
        }
    } catch (\PDOException $e) {}

    // ── Personal context ──────────────────────────────────────────────────
    try {
        $s = $db->prepare(
            "SELECT position FROM user_position_ranking WHERE user_id = ? AND movie_id = ?"
        );
        $s->execute([$userId, $winnerId]);
        $rawPos = (int)$s->fetchColumn();
        if ($rawPos > 0) {
            if ($mediaType !== 'all') {
                // Typspezifische Position (1-N innerhalb des Typs)
                $cb = $db->prepare(
                    "SELECT COUNT(*) FROM user_position_ranking upr
                     JOIN movies m ON m.id = upr.movie_id
                     WHERE upr.user_id=? AND upr.position < ?{$typeFilter}"
                );
                $cb->execute([$userId, $rawPos]);
                $wPos = (int)$cb->fetchColumn() + 1;
                $result['mine_winner_pos'] = $wPos;
                // Nachbarn: 3 typgefilterte Filme um den Gewinner
                $offset = max(0, $wPos - 2);
                $s2 = $db->prepare(
                    "SELECT upr.position AS raw_pos, m.id, m.title
                     FROM user_position_ranking upr
                     JOIN movies m ON m.id = upr.movie_id
                     WHERE upr.user_id = ?{$typeFilter}
                     ORDER BY upr.position
                     LIMIT 3 OFFSET ?"
                );
                $s2->execute([$userId, $offset]);
                $rows = $s2->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $i => &$r) { $r['pos'] = $offset + $i + 1; }
                unset($r);
                $result['mine'] = array_values($rows);
            } else {
                $result['mine_winner_pos'] = $rawPos;
                $s2 = $db->prepare("
                    SELECT upr.position AS pos, m.id, m.title
                    FROM user_position_ranking upr
                    JOIN movies m ON m.id = upr.movie_id
                    WHERE upr.user_id = ? AND upr.position BETWEEN ? AND ?
                    ORDER BY upr.position");
                $s2->execute([$userId, max(1, $rawPos - 1), $rawPos + 1]);
                $result['mine'] = array_values($s2->fetchAll(PDO::FETCH_ASSOC));
            }
        }
    } catch (\PDOException $e) {}

    return $result;
}

// ── Mail ─────────────────────────────────────────────────────────────────────

/**
 * Send a plain-text e-mail via configured SMTP or falls back to PHP mail().
 * Uses raw PHP streams – no external libraries required.
 */
function sendMail(string $to, string $subject, string $body): bool {
    $host = defined('SMTP_HOST') ? SMTP_HOST : '';
    $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
    $enc  = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls';
    $user = defined('SMTP_USER') ? SMTP_USER : '';
    $pass = defined('SMTP_PASS') ? SMTP_PASS : '';
    $from = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@localhost';

    // ── SMTP path ─────────────────────────────────────────────────────────────
    if ($host && $user && $pass) {
        try {
            $ctx = stream_context_create([
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);

            if ($enc === 'ssl') {
                $socket = stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15,
                    STREAM_CLIENT_CONNECT, $ctx);
            } else {
                $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
            }
            if (!$socket) return false;
            stream_set_timeout($socket, 15);

            // Read one multi-line SMTP response
            $read = function () use ($socket): string {
                $out = '';
                while (!feof($socket)) {
                    $line = fgets($socket, 1024);
                    if ($line === false) break;
                    $out .= $line;
                    if (isset($line[3]) && $line[3] === ' ') break; // last line of response
                }
                return $out;
            };
            $cmd = static function (string $c) use ($socket): void {
                fwrite($socket, $c . "\r\n");
            };
            $code = static function (string $resp): int {
                return (int)substr(trim($resp), 0, 3);
            };

            $read(); // 220 greeting

            $cmd('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $read();

            if ($enc === 'tls') {
                $cmd('STARTTLS');
                $read();
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $cmd('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                $read();
            }

            $cmd('AUTH LOGIN');
            $read();
            $cmd(base64_encode($user));
            $read();
            $cmd(base64_encode($pass));
            if ($code($read()) !== 235) { fclose($socket); return false; }

            $cmd("MAIL FROM:<{$from}>");
            $read();
            $cmd("RCPT TO:<{$to}>");
            $read();
            $cmd('DATA');
            $read();

            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $msg = "From: MKFB <{$from}>\r\n"
                 . "To: {$to}\r\n"
                 . "Subject: {$encodedSubject}\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "\r\n"
                 . str_replace("\n.", "\n..", $body) // dot-stuffing
                 . "\r\n.";
            $cmd($msg);
            $ok = ($code($read()) === 250);

            $cmd('QUIT');
            fclose($socket);
            return $ok;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── Fallback: PHP mail() ──────────────────────────────────────────────────
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = implode("\r\n", [
        "From: MKFB <{$from}>",
        'Content-Type: text/plain; charset=UTF-8',
    ]);
    return @mail($to, $encodedSubject, $body, $headers);
}

// ── Passwort-Reset ────────────────────────────────────────────────────────────

/**
 * Generates a reset token and sends an e-mail.
 * Always returns true so callers cannot infer whether the address exists.
 */
function createPasswordReset(string $email): bool {
    $db = getDB();

    static $migrated = false;
    if (!$migrated) {
        $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            token      VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token)
        )");
        $migrated = true;
    }

    $stmt = $db->prepare("SELECT id, username FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) return true; // silent – don't reveal whether address exists

    // Remove any existing token for this user
    $db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);

    $token   = bin2hex(random_bytes(32)); // 64 hex chars
    $expires = date('Y-m-d H:i:s', time() + 3600); // valid for 1 hour

    $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)")
       ->execute([$user['id'], $token, $expires]);

    $resetUrl = rtrim(defined('APP_URL') ? APP_URL : 'http://localhost/filmdb', '/')
              . '/reset-password.php?token=' . $token;

    $subject = 'MKFB – Passwort zurücksetzen';
    $body    = "Hallo {$user['username']},\r\n\r\n"
             . "du hast eine Passwortrücksetzung angefordert.\r\n\r\n"
             . "Klicke auf den folgenden Link, um ein neues Passwort zu setzen:\r\n"
             . $resetUrl . "\r\n\r\n"
             . "Der Link ist 1 Stunde gültig.\r\n\r\n"
             . "Falls du diese Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren.\r\n\r\n"
             . "Markus Kogler's Filmbewertungen";

    sendMail($email, $subject, $body);
    return true;
}

/** Returns reset-record + user data if token is valid and unexpired, else null. */
function validateResetToken(string $token): ?array {
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return null;
    $db = getDB();
    try {
        $stmt = $db->prepare("
            SELECT pr.id AS reset_id, pr.user_id, u.email, u.username
            FROM password_resets pr
            JOIN users u ON u.id = pr.user_id
            WHERE pr.token = ? AND pr.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    } catch (\PDOException $e) {
        return null;
    }
}

/** Sets a new password and deletes the used token. Returns false if token invalid. */
function resetPassword(string $token, string $newPassword): bool {
    $reset = validateResetToken($token);
    if (!$reset) return false;
    $db   = getDB();
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $reset['user_id']]);
    $db->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────

function undoLastComparison(int $userId, int $winnerId, int $loserId): void {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT id, prev_winner_elo, prev_loser_elo FROM comparisons
         WHERE user_id = ? AND winner_id = ? AND loser_id = ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$userId, $winnerId, $loserId]);
    $comp = $stmt->fetch();
    if (!$comp) return;

    if ($comp['prev_winner_elo'] !== null) {
        // Restore exact previous ELO values
        $db->prepare('UPDATE user_ratings SET elo=?, wins=GREATEST(0,wins-1), comparisons=GREATEST(0,comparisons-1) WHERE user_id=? AND movie_id=?')
           ->execute([(int)$comp['prev_winner_elo'], $userId, $winnerId]);
        $db->prepare('UPDATE user_ratings SET elo=?, losses=GREATEST(0,losses-1), comparisons=GREATEST(0,comparisons-1) WHERE user_id=? AND movie_id=?')
           ->execute([(int)$comp['prev_loser_elo'], $userId, $loserId]);
    } else {
        // Older record without stored ELO – just reverse counters
        $db->prepare('UPDATE user_ratings SET wins=GREATEST(0,wins-1), comparisons=GREATEST(0,comparisons-1) WHERE user_id=? AND movie_id=?')
           ->execute([$userId, $winnerId]);
        $db->prepare('UPDATE user_ratings SET losses=GREATEST(0,losses-1), comparisons=GREATEST(0,comparisons-1) WHERE user_id=? AND movie_id=?')
           ->execute([$userId, $loserId]);
    }
    $db->prepare('DELETE FROM comparisons WHERE id = ?')->execute([$comp['id']]);
}

function getTwoDuelMovies(int $userId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.id, m.title, m.title_en, m.year, m.genre, m.poster_path, m.poster_path_en, m.overview, m.overview_en,
               COALESCE(ur.comparisons, 0) AS comp_count
        FROM movies m
        LEFT JOIN user_ratings ur ON ur.movie_id = m.id AND ur.user_id = ?
        ORDER BY comp_count ASC
        LIMIT 20
    ");
    $stmt->execute([$userId]);
    $candidates = $stmt->fetchAll();

    if (count($candidates) < 2) {
        $stmt = $db->prepare('SELECT id, title, title_en, year, genre, poster_path, poster_path_en, overview, overview_en FROM movies LIMIT 50');
        $stmt->execute();
        $candidates = $stmt->fetchAll();
        if (count($candidates) < 2) return [];
    }

    shuffle($candidates);
    return [$candidates[0], $candidates[1]];
}

function getUserRanking(int $userId, int $limit = 30): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.title, m.title_en, m.year, m.genre, m.poster_path, m.poster_path_en,
               ur.elo, ur.wins, ur.losses, ur.comparisons,
               RANK() OVER (ORDER BY ur.elo DESC) AS `rank`
        FROM user_ratings ur
        JOIN movies m ON m.id = ur.movie_id
        WHERE ur.user_id = ? AND ur.comparisons > 0
        ORDER BY ur.elo DESC
        LIMIT ?
    ");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function getGlobalRanking(int $limit = 30): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.id, m.title, m.title_en, m.year, m.genre, m.director, m.poster_path, m.poster_path_en,
               ROUND(AVG(ur.elo)) AS avg_elo,
               SUM(ur.wins) AS total_wins,
               COUNT(DISTINCT ur.user_id) AS user_count,
               RANK() OVER (ORDER BY AVG(ur.elo) DESC) AS `rank`
        FROM user_ratings ur
        JOIN movies m ON m.id = ur.movie_id
        WHERE ur.comparisons > 0
        GROUP BY m.id
        ORDER BY avg_elo DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getStats(): array {
    $db = getDB();
    $users    = $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $movies   = $db->query('SELECT COUNT(*) FROM movies')->fetchColumn();
    $comps    = $db->query('SELECT COUNT(*) FROM comparisons')->fetchColumn();
    return ['users' => (int)$users, 'movies' => (int)$movies, 'comparisons' => (int)$comps];
}

function getUserComparisonCount(int $userId): int {
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM comparisons WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function getActivityCounters(int $userId): array {
    $db = getDB();
    $s = $db->prepare('SELECT COUNT(*) FROM comparisons WHERE user_id = ?');
    $s->execute([$userId]);
    $totalDuels = (int)$s->fetchColumn();
    $s = $db->prepare('SELECT COUNT(*) FROM user_ratings WHERE user_id = ? AND comparisons > 0');
    $s->execute([$userId]);
    $uniqueFilms = (int)$s->fetchColumn();
    return ['totalDuels' => $totalDuels, 'uniqueFilms' => $uniqueFilms];
}

/**
 * Einfacher File-Cache für teure Community-Queries.
 * $fn wird nur aufgerufen wenn Cache abgelaufen oder nicht vorhanden.
 * TTL in Sekunden (Default 5 Minuten).
 */
function dbCache(string $key, callable $fn, int $ttl = 300): mixed {
    // APCu bevorzugen (kein Disk-I/O)
    if (function_exists('apcu_fetch')) {
        $hit = apcu_fetch($key, $ok);
        if ($ok) return $hit;
        $val = $fn();
        apcu_store($key, $val, $ttl);
        return $val;
    }
    // Fallback: Datei-Cache im cache/-Verzeichnis unterhalb Webroot
    $dir  = __DIR__ . '/../cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.cache';
    if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
        $data = @unserialize(file_get_contents($file));
        if ($data !== false) return $data;
    }
    $val = $fn();
    @file_put_contents($file, serialize($val), LOCK_EX);
    return $val;
}

/** Cache-Eintrag manuell invalidieren (z.B. nach Datenmutation) */
function dbCacheDelete(string $key): void {
    if (function_exists('apcu_delete')) { apcu_delete($key); return; }
    $dir  = __DIR__ . '/../cache';
    $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.cache';
    if (file_exists($file)) @unlink($file);
}

function posterUrl(?string $path, ?string $imdbId = null): string {
    // 1. Lokales Cover hat Vorrang
    if ($imdbId) {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $imdbId);
        if ($safe) {
            static $cache = [];
            if (!array_key_exists($safe, $cache)) {
                $local        = __DIR__ . '/../cover/' . $safe . '.jpg';
                $cache[$safe] = file_exists($local) ? '/cover/' . $safe . '.jpg' : null;
            }
            if ($cache[$safe]) return $cache[$safe];
        }
    }
    // 2. TMDB-Poster
    if ($path) return TMDB_IMAGE_BASE . $path;
    // 3. Platzhalter
    return '/assets/no-poster.svg';
}

function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Rendert Forum-Post-Text mit BBCode-Unterstützung.
 * Sicherheit: htmlspecialchars wird VOR der BBCode-Konvertierung angewendet.
 * Doppelte Leerzeilen → Absätze; einfache → <br>.
 */
function renderForumBody(string $raw): string {
    // 1. HTML escapen
    $text = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');

    // 2. BBCode → HTML (nach dem Escapen, um Injection zu verhindern)
    $text = preg_replace('/\[b\](.*?)\[\/b\]/si',         '<strong>$1</strong>',                             $text);
    $text = preg_replace('/\[i\](.*?)\[\/i\]/si',         '<em>$1</em>',                                     $text);
    $text = preg_replace('/\[u\](.*?)\[\/u\]/si',         '<span style="text-decoration:underline">$1</span>',$text);
    $text = preg_replace('/\[s\](.*?)\[\/s\]/si',         '<s>$1</s>',                                       $text);
    $text = preg_replace('/\[big\](.*?)\[\/big\]/si',     '<span style="font-size:1.2em">$1</span>',         $text);
    $text = preg_replace('/\[small\](.*?)\[\/small\]/si', '<span style="font-size:.82em;opacity:.8">$1</span>',$text);

    // 3. Doppelte+ Leerzeilen → Absatztrenner; danach Einzelzeilenumbrüche → <br>
    $parts = preg_split('/\n{2,}/', $text);
    $html  = '';
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $html .= '<p style="margin:0 0 .6rem 0;">' . nl2br($p) . '</p>';
    }
    return $html ?: '<p style="margin:0;">' . nl2br($text) . '</p>';
}

// ── Länder-Übersetzung (ISO 3166-1 → Deutsch) ────────────────────────────────

/**
 * Übersetzt ein Array von TMDB-production_countries-Einträgen
 * (jeder mit 'iso_3166_1' und 'name') in eine kommagetrennte deutsche Länderliste.
 */
function translateProductionCountries(array $countries): string {
    static $map = [
        'AD' => 'Andorra',         'AE' => 'Vereinigte Arabische Emirate', 'AF' => 'Afghanistan',
        'AG' => 'Antigua und Barbuda', 'AL' => 'Albanien',                 'AM' => 'Armenien',
        'AO' => 'Angola',          'AR' => 'Argentinien',                  'AT' => 'Österreich',
        'AU' => 'Australien',      'AZ' => 'Aserbaidschan',
        'BA' => 'Bosnien und Herzegowina', 'BB' => 'Barbados',             'BD' => 'Bangladesch',
        'BE' => 'Belgien',         'BF' => 'Burkina Faso',                 'BG' => 'Bulgarien',
        'BH' => 'Bahrain',         'BI' => 'Burundi',                      'BJ' => 'Benin',
        'BN' => 'Brunei',          'BO' => 'Bolivien',                     'BR' => 'Brasilien',
        'BS' => 'Bahamas',         'BT' => 'Bhutan',                       'BW' => 'Botswana',
        'BY' => 'Belarus',         'BZ' => 'Belize',
        'CA' => 'Kanada',          'CD' => 'Demokratische Republik Kongo', 'CF' => 'Zentralafrikanische Republik',
        'CG' => 'Kongo',           'CH' => 'Schweiz',                      'CI' => 'Elfenbeinküste',
        'CL' => 'Chile',           'CM' => 'Kamerun',                      'CN' => 'China',
        'CO' => 'Kolumbien',       'CR' => 'Costa Rica',                   'CU' => 'Kuba',
        'CV' => 'Kap Verde',       'CY' => 'Zypern',                       'CZ' => 'Tschechien',
        'DE' => 'Deutschland',     'DJ' => 'Dschibuti',                    'DK' => 'Dänemark',
        'DM' => 'Dominica',        'DO' => 'Dominikanische Republik',      'DZ' => 'Algerien',
        'EC' => 'Ecuador',         'EE' => 'Estland',                      'EG' => 'Ägypten',
        'ER' => 'Eritrea',         'ES' => 'Spanien',                      'ET' => 'Äthiopien',
        'FI' => 'Finnland',        'FJ' => 'Fidschi',                      'FR' => 'Frankreich',
        'GA' => 'Gabun',           'GB' => 'Vereinigtes Königreich',       'GD' => 'Grenada',
        'GE' => 'Georgien',        'GH' => 'Ghana',                        'GM' => 'Gambia',
        'GN' => 'Guinea',          'GQ' => 'Äquatorialguinea',             'GR' => 'Griechenland',
        'GT' => 'Guatemala',       'GW' => 'Guinea-Bissau',                'GY' => 'Guyana',
        'HK' => 'Hongkong',        'HN' => 'Honduras',                     'HR' => 'Kroatien',
        'HT' => 'Haiti',           'HU' => 'Ungarn',
        'ID' => 'Indonesien',      'IE' => 'Irland',                       'IL' => 'Israel',
        'IN' => 'Indien',          'IQ' => 'Irak',                         'IR' => 'Iran',
        'IS' => 'Island',          'IT' => 'Italien',
        'JM' => 'Jamaika',         'JO' => 'Jordanien',                    'JP' => 'Japan',
        'KE' => 'Kenia',           'KG' => 'Kirgisistan',                  'KH' => 'Kambodscha',
        'KI' => 'Kiribati',        'KM' => 'Komoren',                      'KN' => 'St. Kitts und Nevis',
        'KP' => 'Nordkorea',       'KR' => 'Südkorea',                     'KW' => 'Kuwait',
        'KZ' => 'Kasachstan',
        'LA' => 'Laos',            'LB' => 'Libanon',                      'LC' => 'St. Lucia',
        'LI' => 'Liechtenstein',   'LK' => 'Sri Lanka',                    'LR' => 'Liberia',
        'LS' => 'Lesotho',         'LT' => 'Litauen',                      'LU' => 'Luxemburg',
        'LV' => 'Lettland',        'LY' => 'Libyen',
        'MA' => 'Marokko',         'MC' => 'Monaco',                       'MD' => 'Moldau',
        'ME' => 'Montenegro',      'MG' => 'Madagaskar',                   'MK' => 'Nordmazedonien',
        'ML' => 'Mali',            'MM' => 'Myanmar',                      'MN' => 'Mongolei',
        'MO' => 'Macao',           'MR' => 'Mauretanien',                  'MT' => 'Malta',
        'MU' => 'Mauritius',       'MV' => 'Malediven',                    'MW' => 'Malawi',
        'MX' => 'Mexiko',          'MY' => 'Malaysia',                     'MZ' => 'Mosambik',
        'NA' => 'Namibia',         'NE' => 'Niger',                        'NG' => 'Nigeria',
        'NI' => 'Nicaragua',       'NL' => 'Niederlande',                  'NO' => 'Norwegen',
        'NP' => 'Nepal',           'NR' => 'Nauru',                        'NZ' => 'Neuseeland',
        'OM' => 'Oman',
        'PA' => 'Panama',          'PE' => 'Peru',                         'PG' => 'Papua-Neuguinea',
        'PH' => 'Philippinen',     'PK' => 'Pakistan',                     'PL' => 'Polen',
        'PT' => 'Portugal',        'PW' => 'Palau',                        'PY' => 'Paraguay',
        'QA' => 'Katar',
        'RO' => 'Rumänien',        'RS' => 'Serbien',                      'RU' => 'Russland',
        'RW' => 'Ruanda',
        'SA' => 'Saudi-Arabien',   'SB' => 'Salomonen',                    'SC' => 'Seychellen',
        'SD' => 'Sudan',           'SE' => 'Schweden',                     'SG' => 'Singapur',
        'SI' => 'Slowenien',       'SK' => 'Slowakei',                     'SL' => 'Sierra Leone',
        'SM' => 'San Marino',      'SN' => 'Senegal',                      'SO' => 'Somalia',
        'SR' => 'Suriname',        'SS' => 'Südsudan',                     'ST' => 'São Tomé und Príncipe',
        'SV' => 'El Salvador',     'SY' => 'Syrien',                       'SZ' => 'Eswatini',
        'TD' => 'Tschad',          'TG' => 'Togo',                         'TH' => 'Thailand',
        'TJ' => 'Tadschikistan',   'TL' => 'Osttimor',                     'TM' => 'Turkmenistan',
        'TN' => 'Tunesien',        'TO' => 'Tonga',                        'TR' => 'Türkei',
        'TT' => 'Trinidad und Tobago', 'TV' => 'Tuvalu',                   'TW' => 'Taiwan',
        'TZ' => 'Tansania',
        'UA' => 'Ukraine',         'UG' => 'Uganda',                       'US' => 'USA',
        'UY' => 'Uruguay',         'UZ' => 'Usbekistan',
        'VA' => 'Vatikanstadt',    'VC' => 'St. Vincent und die Grenadinen', 'VE' => 'Venezuela',
        'VN' => 'Vietnam',         'VU' => 'Vanuatu',
        'WS' => 'Samoa',
        'XK' => 'Kosovo',
        'YE' => 'Jemen',
        'ZA' => 'Südafrika',       'ZM' => 'Sambia',                       'ZW' => 'Simbabwe',
    ];

    $names = [];
    foreach ($countries as $c) {
        $code = strtoupper((string)($c['iso_3166_1'] ?? ''));
        $names[] = $map[$code] ?? ($c['name'] ?? $code);
    }
    return implode(', ', $names);
}
