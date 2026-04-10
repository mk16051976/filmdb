<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';
startSession();
$loggedIn = isLoggedIn();
$_lang = currentLang();
$user = $loggedIn ? currentUser() : null;

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$phase    = userPhase(); // 1 = guest, 2 = pre-tournament, 3 = post-tournament → full access
$userRole = $loggedIn ? userRole() : 'Gast';
$mtActive = getActiveMtFilter(); // 'movie', 'tv', or 'all' — also updates session from ?mt=

// ── Aktivitäts-Counter (nur wenn eingeloggt + Phase ≥ 2) ─────────────────────
$hdrTotalDuels  = 0;
$hdrUniqueFilms = 0;
$hdrTotalFilms  = 0;
// ── last_seen aktualisieren ───────────────────────────────────────────────────
if ($loggedIn) {
    try {
        $_lsDb = getDB();
        // Nur alle 60 Sekunden schreiben (Session-Cache)
        if (empty($_SESSION['last_seen_written']) || (time() - $_SESSION['last_seen_written']) >= 60) {
            $_lsDb->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")
                  ->execute([(int)$_SESSION['user_id']]);
            $_SESSION['last_seen_written'] = time();
        }
    } catch (\PDOException $e) {}
}

// ── DB-Migrationen: nur einmal pro Server ausführen (Flag-Datei) ──────────────
$_hdrMigFlag = __DIR__ . '/../cache/db_migration_v5.flag';
if (!file_exists($_hdrMigFlag)) {
    try {
        $_hdrMigDb = getDB();
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS comparisons (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL, winner_id INT UNSIGNED NOT NULL, loser_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS user_ratings (
            user_id INT UNSIGNED NOT NULL, movie_id INT UNSIGNED NOT NULL,
            elo SMALLINT NOT NULL DEFAULT 1200, wins INT UNSIGNED NOT NULL DEFAULT 0,
            losses INT UNSIGNED NOT NULL DEFAULT 0, comparisons INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, movie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS pm_conversations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_a INT UNSIGNED NOT NULL, user_b INT UNSIGNED NOT NULL,
            last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pair (user_a, user_b), INDEX idx_a (user_a), INDEX idx_b (user_b)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS pm_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, conv_id INT UNSIGNED NOT NULL, sender_id INT UNSIGNED NOT NULL,
            body TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, read_at TIMESTAMP NULL,
            INDEX idx_conv (conv_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Ranglisten-Tabellen
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS user_position_ranking (
            user_id INT NOT NULL, movie_id INT NOT NULL, position INT UNSIGNED NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, movie_id), INDEX idx_user_pos (user_id, position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS tournament_results (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tournament_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL, movie_id INT UNSIGNED NOT NULL,
            wins SMALLINT UNSIGNED NOT NULL DEFAULT 0, matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            score FLOAT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id), INDEX idx_tournament (tournament_id),
            UNIQUE KEY uq_tm (tournament_id, movie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS liga_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
            film_count SMALLINT UNSIGNED NOT NULL, status ENUM('active','completed') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $_hdrMigDb->exec("ALTER TABLE liga_sessions ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NOT NULL DEFAULT 'movie'"); } catch (\PDOException $e) {}
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS liga_matches (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, liga_id INT UNSIGNED NOT NULL,
            movie_a_id INT UNSIGNED NOT NULL, movie_b_id INT UNSIGNED NOT NULL,
            winner_id INT UNSIGNED NULL, voted_at TIMESTAMP NULL,
            INDEX idx_liga_pending (liga_id, winner_id),
            UNIQUE KEY uq_pair (liga_id, movie_a_id, movie_b_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS sort_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
            film_count SMALLINT UNSIGNED NOT NULL, status ENUM('active','completed') NOT NULL DEFAULT 'active',
            state JSON NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $_hdrMigDb->exec("ALTER TABLE sort_sessions ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NOT NULL DEFAULT 'movie'"); } catch (\PDOException $e) {}
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS jgj_pool (
            user_id INT UNSIGNED NOT NULL, movie_id INT UNSIGNED NOT NULL,
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, movie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS jgj_results (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
            movie_a_id INT UNSIGNED NOT NULL, movie_b_id INT UNSIGNED NOT NULL,
            winner_id INT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id), UNIQUE KEY uq_match (user_id, movie_a_id, movie_b_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS action_lists (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL,
            description TEXT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
            created_by INT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS action_list_films (
            list_id INT UNSIGNED NOT NULL, movie_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (list_id, movie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_hdrMigDb->exec("CREATE TABLE IF NOT EXISTS action_list_rankings (
            list_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            movie_id INT UNSIGNED NOT NULL, position INT UNSIGNED NOT NULL,
            wins INT UNSIGNED NOT NULL DEFAULT 0, losses INT UNSIGNED NOT NULL DEFAULT 0,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (list_id, user_id, movie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_hdrMigDb->exec("ALTER TABLE users   ADD COLUMN IF NOT EXISTS last_seen DATETIME NULL DEFAULT NULL");
        $_hdrMigDb->exec("ALTER TABLE movies ADD COLUMN IF NOT EXISTS title_en       VARCHAR(255) NULL DEFAULT NULL");
        $_hdrMigDb->exec("ALTER TABLE movies ADD COLUMN IF NOT EXISTS poster_path_en VARCHAR(255) NULL DEFAULT NULL");
        $_hdrMigDb->exec("ALTER TABLE movies ADD COLUMN IF NOT EXISTS overview_en    TEXT         NULL DEFAULT NULL");
        $_hdrMigDb->exec("ALTER TABLE movies ADD COLUMN IF NOT EXISTS en_fetched     TINYINT(1)   NOT NULL DEFAULT 0");
        // Performance-Indexes
        try { $_hdrMigDb->exec("ALTER TABLE user_position_ranking ADD INDEX idx_movie_pos (movie_id, position)"); } catch (\PDOException $e) {}
        try { $_hdrMigDb->exec("ALTER TABLE user_ratings ADD INDEX idx_user_comp (user_id, comparisons)"); } catch (\PDOException $e) {}
        try { $_hdrMigDb->exec("ALTER TABLE comparisons ADD INDEX idx_user_date (user_id, created_at)"); } catch (\PDOException $e) {}
        try { $_hdrMigDb->exec("ALTER TABLE jgj_results ADD INDEX idx_user_winner (user_id, winner_id)"); } catch (\PDOException $e) {}
        try { $_hdrMigDb->exec("ALTER TABLE tournament_matches ADD INDEX idx_tid_round (tournament_id, runde, winner_id)"); } catch (\PDOException $e) {}
        try { $_hdrMigDb->exec("ALTER TABLE top1000_matches ADD INDEX idx_tid_round (tournament_id, runde, winner_id)"); } catch (\PDOException $e) {}
        try { $_hdrMigDb->exec("ALTER TABLE movies ADD INDEX idx_media_type (media_type)"); } catch (\PDOException $e) {}
        try { $_hdrMigDb->exec("ALTER TABLE user_tournaments ADD INDEX idx_status_user (status, user_id)"); } catch (\PDOException $e) {}
        try { $_hdrMigDb->exec("ALTER TABLE users ADD INDEX idx_community_excluded (community_excluded)"); } catch (\PDOException $e) {}
        if (!is_dir(dirname($_hdrMigFlag))) @mkdir(dirname($_hdrMigFlag), 0755, true);
        @file_put_contents($_hdrMigFlag, date('c'));
    } catch (\PDOException $e) { /* Spalten evtl. schon vorhanden */ }
}

if ($loggedIn && $phase >= 2) {
    try {
        $hdrDb      = getDB();
        $userId_hdr = (int)($_SESSION['user_id'] ?? 0);

        $s = $hdrDb->prepare("SELECT COUNT(*) FROM comparisons WHERE user_id = ?");
        $s->execute([$userId_hdr]);
        $hdrTotalDuels = (int)$s->fetchColumn();

        $s = $hdrDb->prepare("SELECT COUNT(*) FROM user_ratings WHERE user_id = ? AND comparisons > 0");
        $s->execute([$userId_hdr]);
        $hdrUniqueFilms = (int)$s->fetchColumn();

        $hdrTotalFilms = dbCache('hdr_total_films', fn() =>
            (int)getDB()->query("SELECT COUNT(*) FROM movies")->fetchColumn(), 600);
    } catch (\PDOException $e) {}
}

$hdrUnreadMsgs = 0;
if ($loggedIn) {
    try {
        $_uid_pm = (int)($_SESSION['user_id'] ?? 0);
        $s = getDB()->prepare("SELECT COUNT(*) FROM pm_messages m JOIN pm_conversations c ON c.id=m.conv_id
                               WHERE (c.user_a=? OR c.user_b=?) AND m.sender_id!=? AND m.read_at IS NULL");
        $s->execute([$_uid_pm, $_uid_pm, $_uid_pm]);
        $hdrUnreadMsgs = (int)$s->fetchColumn();
    } catch (\PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="<?= $_lang === 'en' ? 'en' : 'de' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
$_ogTitle = $pageTitle       ?? 'MKFB – Markus Kogler\'s Filmbewertungen';
$_ogDesc  = $pageDescription ?? 'Ranke deine Lieblingsfilme im 1-vs-1-Duell. Entdecke dein persönliches Filmranking mit ELO-System, Turnieren und Sortieralgorithmen.';
$_ogUrl   = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'filmbewertungen.markuskogler.de') . ($_SERVER['REQUEST_URI'] ?? '/');
$_ogImage = $pageOgImage ?? 'https://filmbewertungen.markuskogler.de/img/og-image.jpg';
?>
    <title><?= htmlspecialchars($_ogTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Meta Description & Canonical -->
    <meta name="description" content="<?= htmlspecialchars($_ogDesc, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="author"      content="Markus Kogler">
    <meta name="robots"      content="<?= $loggedIn ? 'noindex,nofollow' : 'index,follow' ?>">
    <link rel="canonical"    href="<?= htmlspecialchars(strtok($_ogUrl, '?'), ENT_QUOTES, 'UTF-8') ?>">

    <!-- Open Graph (Facebook, WhatsApp, LinkedIn) -->
    <meta property="og:type"        content="website">
    <meta property="og:site_name"   content="MKFB – Markus Kogler's Filmbewertungen">
    <meta property="og:title"       content="<?= htmlspecialchars($_ogTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($_ogDesc, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url"         content="<?= htmlspecialchars($_ogUrl,  ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:locale"      content="de_DE">
    <?php if ($pageOgImage ?? false): ?>
    <meta property="og:image"       content="<?= htmlspecialchars($_ogImage, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image:width"  content="1200">
    <meta property="og:image:height" content="630">
    <?php endif; ?>

    <!-- Twitter / X Card -->
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="<?= htmlspecialchars($_ogTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($_ogDesc, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($pageOgImage ?? false): ?>
    <meta name="twitter:image"       content="<?= htmlspecialchars($_ogImage, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect"  href="https://image.tmdb.org" crossorigin>
    <link rel="dns-prefetch" href="https://image.tmdb.org">
    <link rel="preconnect"  href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon.svg">
    <?php if (!empty($pageJsonLd)): ?>
    <script type="application/ld+json"><?= $pageJsonLd ?></script>
    <?php endif; ?>
</head>
<body class="<?= $currentPage === 'index' ? 'homepage' : '' ?>">

<nav class="navbar navbar-expand-lg navbar-dark mkfb-nav fixed-top" id="mainNav">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $loggedIn ? '/das-projekt.php' : '/index.php' ?>">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="16" cy="16" r="15" stroke="#e8b84b" stroke-width="2"/>
                <circle cx="16" cy="16" r="6" fill="#e8b84b"/>
                <line x1="16" y1="1" x2="16" y2="7" stroke="#e8b84b" stroke-width="2"/>
                <line x1="16" y1="25" x2="16" y2="31" stroke="#e8b84b" stroke-width="2"/>
                <line x1="1" y1="16" x2="7" y2="16" stroke="#e8b84b" stroke-width="2"/>
                <line x1="25" y1="16" x2="31" y2="16" stroke="#e8b84b" stroke-width="2"/>
                <line x1="4.7" y1="4.7" x2="9" y2="9" stroke="#e8b84b" stroke-width="2"/>
                <line x1="23" y1="23" x2="27.3" y2="27.3" stroke="#e8b84b" stroke-width="2"/>
                <line x1="27.3" y1="4.7" x2="23" y2="9" stroke="#e8b84b" stroke-width="2"/>
                <line x1="9" y1="23" x2="4.7" y2="27.3" stroke="#e8b84b" stroke-width="2"/>
            </svg>
            <span class="fw-bold d-none d-md-inline">Markus Kogler's Filmbewertungen</span>
            <span class="fw-bold d-inline d-md-none">MKFB</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu"
                aria-controls="navMenu" aria-expanded="false" aria-label="Navigation öffnen">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav mx-auto gap-1">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'news' ? 'active' : '' ?>" href="/news.php">News</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['das-projekt', 'features']) ? 'active' : '' ?>"
                       href="#" id="projektDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Projekt
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="projektDropdown">
                        <li><a class="dropdown-item <?= $currentPage === 'das-projekt' ? 'active' : '' ?>" href="/das-projekt.php">
                            <i class="bi bi-map me-1"></i>Ablauf
                        </a></li>
                        <li><a class="dropdown-item <?= $currentPage === 'features' ? 'active' : '' ?>" href="/features.php">
                            <i class="bi bi-stars me-1"></i>Features
                        </a></li>
                    </ul>
                </li>

                <!-- Filmperlen -->
                <?php if ($phase >= 2): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'filmperlen' ? 'active' : '' ?>" href="/filmperlen.php">
                        Filmperlen
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($phase === 1): ?>
                <!-- Gast: Demo -->
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'demo' ? 'active' : '' ?>" href="/demo.php">Demo</a>
                </li>

                <?php elseif ($phase === 2): ?>
                <!-- Phase 2: Turnier + Forum -->
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'turnier' ? 'active' : '' ?>" href="/turnier.php">
                        <i class="bi bi-diagram-3 me-1"></i>Turnier
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= in_array($currentPage, ['forum', 'forum-thread', 'forum-new-thread']) ? 'active' : '' ?>" href="/forum.php">
                        Forum
                    </a>
                </li>

                <?php elseif ($phase >= 3): ?>
                <!-- Phase 3+: Vollzugang (Phase 3 mit JgJ-Highlight) -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['rangliste', 'community-rangliste', 'community-mitglieder', 'jgj-complete-rangliste']) ? 'active' : '' ?>"
                       href="#" id="ranglisteDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= t('nav.ranking') ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-dark p-0" aria-labelledby="ranglisteDropdown" style="min-width:380px;">
                    <div class="d-flex">

                    <!-- ── FILME ────────────────────────────────────────── -->
                    <div style="flex:1;min-width:0;">
                        <div style="padding:.45rem .9rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#e8b84b;border-bottom:1px solid rgba(232,184,75,.15);">
                            <i class="bi bi-camera-film me-1"></i>Filme
                        </div>
                        <a class="dropdown-item <?= ($currentPage==='rangliste' && $mtActive==='movie') ? 'active' : '' ?>" href="/rangliste.php?mt=movie">
                            <i class="bi bi-trophy me-1"></i><?= t('nav.my_rankings') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='community-rangliste' && $mtActive==='movie') ? 'active' : '' ?>" href="/community-rangliste.php?mt=movie">
                            <i class="bi bi-people me-1"></i><?= t('nav.community') ?>
                        </a>
                        <?php if (isSuperAdmin()): ?>
                        <a class="dropdown-item <?= ($currentPage==='jgj-complete-rangliste' && $mtActive==='movie') ? 'active' : '' ?>" href="/jgj-complete-rangliste.php?mt=movie">
                            <i class="bi bi-grid-3x3 me-1" style="color:#e8b84b;"></i>JgJ Komplett
                        </a>
                        <?php endif; ?>
                    </div>

                    <!-- ── vertical divider ────────────────────────────── -->
                    <div style="width:1px;background:rgba(255,255,255,.08);flex-shrink:0;align-self:stretch;"></div>

                    <!-- ── SERIEN ───────────────────────────────────────── -->
                    <div style="flex:1;min-width:0;">
                        <div style="padding:.45rem .9rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#a78bfa;border-bottom:1px solid rgba(167,139,250,.15);">
                            <i class="bi bi-tv me-1"></i>Serien
                        </div>
                        <a class="dropdown-item <?= ($currentPage==='rangliste' && $mtActive==='tv') ? 'active' : '' ?>" href="/rangliste.php?mt=tv">
                            <i class="bi bi-trophy me-1"></i><?= t('nav.my_rankings') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='community-rangliste' && $mtActive==='tv') ? 'active' : '' ?>" href="/community-rangliste.php?mt=tv">
                            <i class="bi bi-people me-1"></i><?= t('nav.community') ?>
                        </a>
                        <?php if (isSuperAdmin()): ?>
                        <a class="dropdown-item <?= ($currentPage==='jgj-complete-rangliste' && $mtActive==='tv') ? 'active' : '' ?>" href="/jgj-complete-rangliste.php?mt=tv">
                            <i class="bi bi-grid-3x3 me-1" style="color:#e8b84b;"></i>JgJ Komplett
                        </a>
                        <?php endif; ?>
                    </div>

                    </div><!-- /.d-flex -->

                    <!-- ── Globale Links ────────────────────────────────── -->
                    <div style="border-top:1px solid rgba(255,255,255,.08);">
                        <a class="dropdown-item <?= $currentPage === 'community-mitglieder' ? 'active' : '' ?>" href="/community-mitglieder.php">
                            <i class="bi bi-person-lines-fill me-1"></i>Community Mitglieder
                        </a>
                    </div>

                    </div><!-- /.dropdown-menu -->
                </li>
                <?php
                // Aktive Aktions-Listen für Navigation
                $hdrActiveLists = [];
                try {
                    $hdrAktDb = getDB();
                    $hdrAktRes = $hdrAktDb->query("SELECT id, name FROM action_lists WHERE CURDATE() BETWEEN start_date AND end_date ORDER BY name ASC LIMIT 10");
                    $hdrActiveLists = $hdrAktRes ? $hdrAktRes->fetchAll(PDO::FETCH_ASSOC) : [];
                } catch (\Throwable $e) { $hdrActiveLists = []; }
                ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['turnier', 'liga', 'sortieren', 'fuenf-filme', 'zufallsduelle', 'film-einordnen', 'jgj', 'aktionen', 'jgj-complete', 'jgj-complete-build', 'jgj-complete-rangliste', 'tierliste']) ? 'active' : '' ?>"
                       href="#" id="bewertungenDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= t('nav.ratings') ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-dark p-0" aria-labelledby="bewertungenDropdown" style="min-width:460px;">
                    <div class="d-flex">

                    <!-- ── FILME ────────────────────────────────────────── -->
                    <div style="flex:1;min-width:0;">
                        <div style="padding:.45rem .9rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#e8b84b;border-bottom:1px solid rgba(232,184,75,.15);">
                            <i class="bi bi-camera-film me-1"></i>Filme
                        </div>
                        <?php if ($phase >= 3): ?>
                        <a class="dropdown-item <?= ($currentPage==='jgj' && $mtActive==='movie') ? 'active' : '' ?>" href="/jgj.php?mt=movie">
                            <i class="bi bi-people-fill me-1"></i>Jeder gegen Jeden
                        </a>
                        <?php if (isSuperAdmin()): ?>
                        <a class="dropdown-item <?= ($currentPage==='jgj-complete' && $mtActive==='movie') ? 'active' : '' ?>" href="/jgj-complete.php?mt=movie">
                            <i class="bi bi-grid-3x3 me-1" style="color:#e8b84b;"></i>JgJ Komplett
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($hdrActiveLists)): ?>
                        <div style="height:1px;background:rgba(255,255,255,.06);margin:.25rem 0;"></div>
                        <?php foreach ($hdrActiveLists as $hal): ?>
                        <a class="dropdown-item <?= ($currentPage==='aktionen' && (int)($_GET['list']??0)===(int)$hal['id'] && $mtActive==='movie') ? 'active' : '' ?>" href="/aktionen.php?list=<?= (int)$hal['id'] ?>&mt=movie">
                            <i class="bi bi-trophy me-1" style="color:#e8b84b;"></i><?= htmlspecialchars($hal['name'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        <div style="height:1px;background:rgba(255,255,255,.06);margin:.25rem 0;"></div>
                        <?php endif; ?>
                        <a class="dropdown-item <?= ($currentPage==='turnier' && $mtActive==='movie') ? 'active' : '' ?>" href="/turnier.php?mt=movie">
                            <i class="bi bi-diagram-3 me-1"></i><?= t('nav.tournament') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='liga' && $mtActive==='movie') ? 'active' : '' ?>" href="/liga.php?mt=movie">
                            <i class="bi bi-people-fill me-1"></i><?= t('nav.liga') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='sortieren' && $mtActive==='movie') ? 'active' : '' ?>" href="/sortieren.php?mt=movie">
                            <i class="bi bi-sort-numeric-down me-1"></i><?= t('nav.sorting') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='zufallsduelle' && $mtActive==='movie') ? 'active' : '' ?>" href="/zufallsduelle.php?mt=movie">
                            <i class="bi bi-shuffle me-1"></i><?= t('nav.random') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='film-einordnen' && $mtActive==='movie') ? 'active' : '' ?>" href="/film-einordnen.php?mt=movie">
                            <i class="bi bi-search-heart me-1"></i><?= t('nav.insert') ?>
                        </a>
                        <div style="height:1px;background:rgba(255,255,255,.06);margin:.25rem 0;"></div>
                        <a class="dropdown-item <?= ($currentPage==='fuenf-filme' && $mtActive==='movie') ? 'active' : '' ?>" href="/fuenf-filme.php?mt=movie">
                            <i class="bi bi-grid-3x2-gap-fill me-1"></i><?= t('nav.five') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='top1000' && $mtActive==='movie') ? 'active' : '' ?>" href="/top1000.php?mt=movie">
                            <i class="bi bi-trophy me-1" style="color:#e8b84b;"></i>Top 1000 Turnier
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='tierliste' && $mtActive==='movie') ? 'active' : '' ?>" href="/tierliste.php?mt=movie">
                            <i class="bi bi-layers-fill me-1"></i>Tier-Liste
                        </a>
                    </div>

                    <!-- ── vertical divider ────────────────────────────── -->
                    <div style="width:1px;background:rgba(255,255,255,.08);flex-shrink:0;align-self:stretch;"></div>

                    <!-- ── SERIEN ───────────────────────────────────────── -->
                    <div style="flex:1;min-width:0;">
                        <div style="padding:.45rem .9rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#a78bfa;border-bottom:1px solid rgba(167,139,250,.15);">
                            <i class="bi bi-tv me-1"></i>Serien
                        </div>
                        <?php if ($phase >= 3): ?>
                        <a class="dropdown-item <?= ($currentPage==='jgj' && $mtActive==='tv') ? 'active' : '' ?>" href="/jgj.php?mt=tv">
                            <i class="bi bi-people-fill me-1"></i>Jeder gegen Jeden
                        </a>
                        <?php if (isSuperAdmin()): ?>
                        <a class="dropdown-item <?= ($currentPage==='jgj-complete' && $mtActive==='tv') ? 'active' : '' ?>" href="/jgj-complete.php?mt=tv">
                            <i class="bi bi-grid-3x3 me-1" style="color:#e8b84b;"></i>JgJ Komplett
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($hdrActiveLists)): ?>
                        <div style="height:1px;background:rgba(255,255,255,.06);margin:.25rem 0;"></div>
                        <?php foreach ($hdrActiveLists as $hal): ?>
                        <a class="dropdown-item <?= ($currentPage==='aktionen' && (int)($_GET['list']??0)===(int)$hal['id'] && $mtActive==='tv') ? 'active' : '' ?>" href="/aktionen.php?list=<?= (int)$hal['id'] ?>&mt=tv">
                            <i class="bi bi-trophy me-1" style="color:#e8b84b;"></i><?= htmlspecialchars($hal['name'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        <div style="height:1px;background:rgba(255,255,255,.06);margin:.25rem 0;"></div>
                        <?php endif; ?>
                        <a class="dropdown-item <?= ($currentPage==='turnier' && $mtActive==='tv') ? 'active' : '' ?>" href="/turnier.php?mt=tv">
                            <i class="bi bi-diagram-3 me-1"></i><?= t('nav.tournament') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='liga' && $mtActive==='tv') ? 'active' : '' ?>" href="/liga.php?mt=tv">
                            <i class="bi bi-people-fill me-1"></i><?= t('nav.liga') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='sortieren' && $mtActive==='tv') ? 'active' : '' ?>" href="/sortieren.php?mt=tv">
                            <i class="bi bi-sort-numeric-down me-1"></i><?= t('nav.sorting') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='zufallsduelle' && $mtActive==='tv') ? 'active' : '' ?>" href="/zufallsduelle.php?mt=tv">
                            <i class="bi bi-shuffle me-1"></i><?= t('nav.random') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='film-einordnen' && $mtActive==='tv') ? 'active' : '' ?>" href="/film-einordnen.php?mt=tv">
                            <i class="bi bi-search-heart me-1"></i><?= t('nav.insert') ?>
                        </a>
                        <div style="height:1px;background:rgba(255,255,255,.06);margin:.25rem 0;"></div>
                        <a class="dropdown-item <?= ($currentPage==='fuenf-filme' && $mtActive==='tv') ? 'active' : '' ?>" href="/fuenf-filme.php?mt=tv">
                            <i class="bi bi-grid-3x2-gap-fill me-1"></i><?= t('nav.five') ?>
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='top1000' && $mtActive==='tv') ? 'active' : '' ?>" href="/top1000.php?mt=tv">
                            <i class="bi bi-trophy me-1" style="color:#a78bfa;"></i>Top 1000 Turnier
                        </a>
                        <a class="dropdown-item <?= ($currentPage==='tierliste' && $mtActive==='tv') ? 'active' : '' ?>" href="/tierliste.php?mt=tv">
                            <i class="bi bi-layers-fill me-1"></i>Tier-Liste
                        </a>
                    </div>

                    </div><!-- /.d-flex -->
                    </div><!-- /.dropdown-menu -->
                </li>
                <?php endif; ?>

                <?php if ($phase >= 3): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['user-statistiken', 'community-statistiken', 'community-zeiten']) ? 'active' : '' ?>"
                       href="#" id="statistikenDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= t('nav.statistics') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="statistikenDropdown">
                        <li><a class="dropdown-item <?= $currentPage === 'user-statistiken' ? 'active' : '' ?>" href="/user-statistiken.php">
                            <i class="bi bi-person-lines-fill me-1"></i><?= t('nav.user_stats') ?>
                        </a></li>
                        <li><a class="dropdown-item <?= $currentPage === 'community-statistiken' ? 'active' : '' ?>" href="/community-statistiken.php">
                            <i class="bi bi-bar-chart-fill me-1"></i><?= t('nav.community_stats') ?>
                        </a></li>
                        <li><a class="dropdown-item <?= $currentPage === 'community-zeiten' ? 'active' : '' ?>" href="/community-zeiten.php">
                            <i class="bi bi-clock-history me-1"></i>Community-Zeiten
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if ($phase >= 3): ?>
                <li class="nav-item">
                    <a class="nav-link <?= in_array($currentPage, ['forum', 'forum-thread', 'forum-new-thread']) ? 'active' : '' ?>" href="/forum.php">
                        Forum
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'filmtagebuch' ? 'active' : '' ?>" href="/filmtagebuch.php">
                        <i class="bi bi-journal-play me-1"></i><?= t('nav.diary') ?>
                    </a>
                </li>
                <?php endif; ?>

                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'team' ? 'active' : '' ?>" href="/team.php"><?= t('nav.team') ?></a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <?php if ($loggedIn): ?>
                <a href="/nachrichten.php" title="Nachrichten" style="position:relative; color:rgba(255,255,255,.6); text-decoration:none; font-size:1.15rem; line-height:1; padding:2px 4px;">
                    <i class="bi bi-envelope<?= $currentPage === 'nachrichten' ? '-fill' : '' ?>"></i>
                    <?php if ($hdrUnreadMsgs > 0): ?>
                    <span style="position:absolute; top:-4px; right:-4px; background:#e8b84b; color:#0a192f; font-size:.55rem; font-weight:800; border-radius:20px; padding:1px 4px; line-height:1.4; min-width:14px; text-align:center;">
                        <?= $hdrUnreadMsgs > 9 ? '9+' : $hdrUnreadMsgs ?>
                    </span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <div class="d-none d-lg-flex align-items-center" style="background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); border-radius:20px; overflow:hidden; font-size:.72rem; font-weight:700;">
                    <a href="/lang-switch.php?lang=de" style="padding:3px 9px; color:<?= $_lang==='de' ? '#0a192f' : 'rgba(255,255,255,.45)' ?>; background:<?= $_lang==='de' ? '#e8b84b' : 'transparent' ?>; text-decoration:none;">DE</a>
                    <a href="/lang-switch.php?lang=en" style="padding:3px 9px; color:<?= $_lang==='en' ? '#0a192f' : 'rgba(255,255,255,.45)' ?>; background:<?= $_lang==='en' ? '#e8b84b' : 'transparent' ?>; text-decoration:none;">EN</a>
                </div>

                <?php if ($loggedIn && $phase >= 2): ?>
                <!-- Aktivitäts-Counter -->
                <div class="d-none d-lg-flex align-items-center gap-1" style="font-size:.72rem;">
                    <span id="hdr-badge-duels" title="Duelle gesamt"
                          style="background:rgba(232,184,75,.12); border:1px solid rgba(232,184,75,.25); color:#e8b84b;
                                 border-radius:20px; padding:2px 9px; white-space:nowrap; font-weight:700; cursor:default;">
                        <i class="bi bi-lightning-charge-fill me-1"></i><span id="hdr-duels-count"><?= number_format($hdrTotalDuels, 0, ',', '.') ?></span>
                    </span>
                    <span id="hdr-badge-films" title="Duellierte Filme / Gesamtfilme"
                          style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.12); color:rgba(255,255,255,.6);
                                 border-radius:20px; padding:2px 9px; white-space:nowrap; font-weight:600; cursor:default;">
                        <i class="bi bi-film me-1" style="color:rgba(232,184,75,.7);"></i><span id="hdr-films-count"><?= $hdrUniqueFilms ?></span>&nbsp;<span style="color:rgba(255,255,255,.3);">/</span>&nbsp;<?= $hdrTotalFilms ?>
                    </span>
                </div>
                <?php endif; ?>

                <?php if ($loggedIn): ?>
                    <?php if ($phase >= 2 && in_array($userRole, ['Admin', 'Superadmin'])): ?>
                    <!-- Admin/Superadmin: Profillink + Dropdown-Pfeil getrennt -->
                    <div class="d-flex align-items-center gap-1">
                        <a href="/profil.php" class="d-flex align-items-center gap-1 text-decoration-none"
                           style="color:rgba(255,255,255,.75); font-size:.875rem;">
                            <i class="bi bi-person-circle"></i>
                            <span class="d-none d-lg-inline"><?= e($user['username'] ?? '') ?></span>
                            <?php if ($userRole === 'Superadmin'): ?>
                            <i class="bi bi-shield-fill" style="font-size:.65rem; color:#e8b84b;" title="Superadmin"></i>
                            <i class="bi bi-star-fill" style="font-size:.55rem; color:#e8b84b;" title="Superadmin"></i>
                            <?php else: ?>
                            <i class="bi bi-shield-fill" style="font-size:.65rem; color:#e8b84b;" title="Administrator"></i>
                            <?php endif; ?>
                        </a>
                        <div class="dropdown">
                        <a href="#" class="dropdown-toggle text-decoration-none"
                           id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false"
                           style="color:rgba(255,255,255,.4); font-size:.75rem;">
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end" aria-labelledby="userDropdown">
                            <li>
                                <span class="dropdown-item-text" style="color:rgba(255,255,255,.35); font-size:.72rem; text-transform:uppercase; letter-spacing:.05em;">
                                    <i class="bi bi-shield-fill me-1" style="color:#e8b84b;"></i><?= $userRole === 'Superadmin' ? 'Superadmin' : 'Administrator' ?>
                                </span>
                            </li>
                            <?php if ($userRole === 'Superadmin'): ?>
                            <li><hr class="dropdown-divider" style="border-color:rgba(255,255,255,.1);"></li>
                            <li>
                                <span class="dropdown-item-text" style="color:rgba(255,255,255,.25); font-size:.68rem; text-transform:uppercase; letter-spacing:.05em;">
                                    <i class="bi bi-grid-3x3 me-1" style="color:#e8b84b;"></i>JgJ Komplett
                                </span>
                            </li>
                            <li><a class="dropdown-item <?= $currentPage === 'jgj-complete' ? 'active' : '' ?>" href="/jgj-complete.php">
                                <i class="bi bi-play-circle me-1"></i>Bewerten
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'jgj-complete-rangliste' ? 'active' : '' ?>" href="/jgj-complete-rangliste.php">
                                <i class="bi bi-trophy me-1"></i>Rangliste
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'jgj-complete-build' ? 'active' : '' ?>" href="/jgj-complete-build.php">
                                <i class="bi bi-tools me-1"></i>Spielplan aufbauen
                            </a></li>
                            <li><hr class="dropdown-divider" style="border-color:rgba(255,255,255,.1);"></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item <?= $currentPage === 'profil' ? 'active' : '' ?>" href="/profil.php">
                                <i class="bi bi-person-circle me-1"></i>Mein Profil
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'meine-sammlung' ? 'active' : '' ?>" href="/meine-sammlung.php">
                                <i class="bi bi-collection-play me-1"></i>Meine Sammlung
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'charts' ? 'active' : '' ?>" href="/charts.php">
                                <i class="bi bi-collection-play me-1"></i>Filmdatenbank
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-filme' ? 'active' : '' ?>" href="/admin-filme.php">
                                <i class="bi bi-film me-1"></i>Film-Verwaltung
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-duplikate' ? 'active' : '' ?>" href="/admin-duplikate.php">
                                <i class="bi bi-copy me-1"></i>Duplikate bereinigen
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-benutzer' ? 'active' : '' ?>" href="/admin-benutzer.php">
                                <i class="bi bi-people me-1"></i>Benutzer
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-statistiken' ? 'active' : '' ?>" href="/admin-statistiken.php">
                                <i class="bi bi-bar-chart me-1"></i>Statistiken
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-aktionen' ? 'active' : '' ?>" href="/admin-aktionen.php">
                                <i class="bi bi-trophy me-1"></i>Aktionen
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-news' ? 'active' : '' ?>" href="/admin-news.php">
                                <i class="bi bi-newspaper me-1"></i>News
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-forum' ? 'active' : '' ?>" href="/admin-forum.php">
                                <i class="bi bi-chat-square-text me-1"></i>Forum
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-projekt' ? 'active' : '' ?>" href="/admin-projekt.php">
                                <i class="bi bi-layers me-1"></i>Projekt
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-team' ? 'active' : '' ?>" href="/admin-team.php">
                                <i class="bi bi-people-fill me-1"></i>Team
                            </a></li>
                            <li><a class="dropdown-item" href="/import.php">
                                <i class="bi bi-cloud-download me-1"></i>Filme importieren
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-import-csv' ? 'active' : '' ?>" href="/admin-import-csv.php">
                                <i class="bi bi-filetype-csv me-1"></i>CSV-Import (IMDB)
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-fetch-en' ? 'active' : '' ?>" href="/admin-fetch-en.php">
                                <i class="bi bi-globe me-1"></i>EN-Daten laden
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-fetch-credits' ? 'active' : '' ?>" href="/admin-fetch-credits.php">
                                <i class="bi bi-person-video3 me-1"></i>Credits &amp; Besetzung laden
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'admin-tournament-pool' ? 'active' : '' ?>" href="/admin-tournament-pool.php">
                                <i class="bi bi-collection-fill me-1"></i>Turnier-Pool
                            </a></li>
                            <li><hr class="dropdown-divider" style="border-color:rgba(255,255,255,.1);"></li>
                            <li><a class="dropdown-item" href="/logout.php">
                                <i class="bi bi-box-arrow-right me-1"></i><?= t('nav.logout') ?>
                            </a></li>
                        </ul>
                        </div><!-- /.dropdown -->
                    </div><!-- /.d-flex -->
                    <?php else: ?>
                    <!-- Normaler User – Dropdown -->
                    <div class="dropdown">
                        <a href="#" id="userDropdownUser" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false"
                           class="d-flex align-items-center gap-1 text-decoration-none"
                           style="color:rgba(255,255,255,.75); font-size:.875rem;">
                            <i class="bi bi-person-circle"></i>
                            <span class="d-none d-lg-inline"><?= e($user['username'] ?? '') ?></span>
                            <i class="bi bi-chevron-down" style="font-size:.65rem; opacity:.5;"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end" aria-labelledby="userDropdownUser">
                            <li><a class="dropdown-item <?= $currentPage === 'profil' ? 'active' : '' ?>" href="/profil.php">
                                <i class="bi bi-person-circle me-1"></i>Mein Profil
                            </a></li>
                            <li><a class="dropdown-item <?= $currentPage === 'meine-sammlung' ? 'active' : '' ?>" href="/meine-sammlung.php">
                                <i class="bi bi-collection-play me-1"></i>Meine Sammlung
                            </a></li>
                            <li><hr class="dropdown-divider" style="border-color:rgba(255,255,255,.1);"></li>
                            <li><a class="dropdown-item" href="/logout.php">
                                <i class="bi bi-box-arrow-right me-1"></i><?= t('nav.logout') ?>
                            </a></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="/login.php" class="btn btn-outline-light btn-sm"><?= t('nav.login') ?></a>
                    <a href="/register.php" class="btn btn-gold btn-sm"><?= $_lang === 'en' ? 'Join MKFB' : 'MKFB beitreten' ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
