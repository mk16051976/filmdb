<?php
$pageTitle  = 'Community Mitglieder – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$db         = getDB();
$myUserId   = (int)$_SESSION['user_id'];
$viewUserId = isset($_GET['user']) ? max(0, (int)$_GET['user']) : 0;
$activeTab  = in_array($_GET['tab'] ?? '', ['sammlung', 'aktionen', 'tagebuch']) ? $_GET['tab'] : 'ranglisten';

// ── Bucket helper (same rules as meine-sammlung.php) ──────────────────────────
function cmGetBucket(string $title): string {
    $l = mb_strtolower(trim($title));
    foreach (['das ','der ','die ','the '] as $pre) {
        if (mb_substr($l, 0, mb_strlen($pre)) === $pre) return ucfirst(trim($pre));
    }
    $c = mb_strtoupper(mb_substr(trim($title), 0, 1));
    if (preg_match('/\d/', $c)) return $c;
    if (ctype_alpha($c)) return $c;
    return '#';
}
$CM_BUCKET_ORDER = ['#','0','1','2','3','4','5','6','7','8','9','A','B','C','D','Das','Der','Die',
                    'E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','The','U','V','W','X','Y','Z'];
$groupedFilme  = [];
$groupedSerien = [];

// ── All members ───────────────────────────────────────────────────────────────
$members = $db->query(
    "SELECT id, username FROM users WHERE blocked = 0 ORDER BY username ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Selected user ─────────────────────────────────────────────────────────────
$viewUser          = null;
$userRanking       = [];
$userJgjRanking    = [];
$userTourneyMovies = [];
$userTourneyTV     = [];
$userFilme         = [];
$userSerien        = [];
$userActionLists   = [];
$diaryEntriesByDay = [];
$diaryYear  = max(2000, min(2099, (int)($_GET['cal_year']  ?? date('Y'))));
$diaryMonth = max(1,    min(12,   (int)($_GET['cal_month'] ?? date('n'))));

if ($viewUserId > 0) {
    $s = $db->prepare("SELECT id, username FROM users WHERE id = ? AND blocked = 0");
    $s->execute([$viewUserId]);
    $viewUser = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($viewUser) {

    if ($activeTab === 'ranglisten') {

        // ── Meine Rangliste ──────────────────────────────────────────────────
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS user_position_ranking (
                user_id  INT NOT NULL,
                movie_id INT NOT NULL,
                position INT UNSIGNED NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, movie_id),
                INDEX idx_user_pos (user_id, position)
            )");
            $s = $db->prepare("
                SELECT upr.position,
                       m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director,
                       COALESCE(m.media_type,'movie') AS media_type,
                       COALESCE(ur.elo, 1500)      AS elo,
                       COALESCE(ur.wins, 0)        AS wins,
                       COALESCE(ur.losses, 0)      AS losses
                FROM user_position_ranking upr
                JOIN movies m ON m.id = upr.movie_id
                LEFT JOIN user_ratings ur
                    ON ur.user_id  = upr.user_id
                   AND ur.movie_id = upr.movie_id
                WHERE upr.user_id = ?
                ORDER BY upr.position ASC
                LIMIT 500
            ");
            $s->execute([$viewUserId]);
            $userRanking = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) { $userRanking = []; }

        // Aufteilen in Filme / Serien (Position neu von 1 zählen)
        $userRankingMovies = []; $userRankingTV = [];
        $mPos = 1; $tvPos = 1;
        foreach ($userRanking as $r) {
            if (($r['media_type'] ?? 'movie') === 'tv') {
                $userRankingTV[]     = array_merge($r, ['position' => $tvPos++]);
            } else {
                $userRankingMovies[] = array_merge($r, ['position' => $mPos++]);
            }
        }

        // ── JgJ-Rangliste ────────────────────────────────────────────────────
        try {
            $s = $db->prepare("
                SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director,
                       COALESCE(m.media_type,'movie') AS media_type,
                       RANK() OVER (ORDER BY wins DESC, losses ASC) AS jgj_rank,
                       wins, losses
                FROM (
                    SELECT p.movie_id,
                        SUM(CASE WHEN r.winner_id = p.movie_id THEN 1 ELSE 0 END)                       AS wins,
                        SUM(CASE WHEN r.id IS NOT NULL AND r.winner_id != p.movie_id THEN 1 ELSE 0 END) AS losses
                    FROM jgj_pool p
                    LEFT JOIN jgj_results r
                        ON r.user_id = p.user_id
                       AND (r.movie_a_id = p.movie_id OR r.movie_b_id = p.movie_id)
                    WHERE p.user_id = ?
                    GROUP BY p.movie_id
                ) AS scores
                JOIN movies m ON m.id = scores.movie_id
                ORDER BY jgj_rank ASC
                LIMIT 500
            ");
            $s->execute([$viewUserId]);
            $userJgjRanking = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) { $userJgjRanking = []; }

        // JgJ aufteilen + neu ranken
        $userJgjMovies = []; $userJgjTV = [];
        foreach ($userJgjRanking as $r) {
            if (($r['media_type'] ?? 'movie') === 'tv') $userJgjTV[] = $r;
            else $userJgjMovies[] = $r;
        }
        foreach ($userJgjMovies as $i => &$r) { $r['jgj_rank'] = $i + 1; } unset($r);
        foreach ($userJgjTV    as $i => &$r) { $r['jgj_rank'] = $i + 1; } unset($r);

        // ── Turnier-Ranglisten ───────────────────────────────────────────────
        // Typ aus den tatsächlichen Filmen im Turnier ableiten (zuverlässiger als gespeicherter media_type,
        // da ältere Turniere media_type='movie' als Default haben können)
        $userTourneyMovies = []; $userTourneyTV = [];
        try {
            // Alle abgeschlossenen Turniere holen, Typ per Mehrheit der Filminhalte bestimmen
            $allT = $db->prepare("SELECT id FROM user_tournaments
                WHERE user_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 20");
            $allT->execute([$viewUserId]);
            $tIds = $allT->fetchAll(PDO::FETCH_COLUMN);

            $lastMovieId = 0; $lastTvId = 0;
            foreach ($tIds as $tId) {
                $tId = (int)$tId;
                // Mehrheit der Filme bestimmen
                $typeStmt = $db->prepare("
                    SELECT SUM(COALESCE(m.media_type,'movie') = 'tv') AS tv_count, COUNT(*) AS total
                    FROM tournament_results tr JOIN movies m ON m.id = tr.movie_id
                    WHERE tr.tournament_id = ? AND tr.user_id = ?");
                $typeStmt->execute([$tId, $viewUserId]);
                $tc = $typeStmt->fetch(PDO::FETCH_ASSOC);
                $isTV = $tc && $tc['total'] > 0 && ($tc['tv_count'] / $tc['total']) > 0.5;

                if ($isTV && !$lastTvId)    $lastTvId    = $tId;
                if (!$isTV && !$lastMovieId) $lastMovieId = $tId;
                if ($lastMovieId && $lastTvId) break;
            }

            foreach (['movie' => $lastMovieId, 'tv' => $lastTvId] as $mtKey => $tId) {
                if (!$tId) continue;
                $rStmt = $db->prepare("
                    SELECT tr.score, tr.wins, tr.matches_played,
                           m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director
                    FROM tournament_results tr
                    JOIN movies m ON m.id = tr.movie_id
                    WHERE tr.tournament_id = ? AND tr.user_id = ?
                    ORDER BY tr.score DESC, tr.wins DESC
                ");
                $rStmt->execute([$tId, $viewUserId]);
                $rows = $rStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $i => &$r) { $r['tourney_rank'] = $i + 1; } unset($r);
                if ($mtKey === 'tv') $userTourneyTV = $rows;
                else                 $userTourneyMovies = $rows;
            }
        } catch (\PDOException $e) {}

    } elseif ($activeTab === 'aktionen') {

        $userActionLists = [];
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS action_list_rankings (
                list_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
                movie_id INT UNSIGNED NOT NULL, position INT UNSIGNED NOT NULL,
                wins INT UNSIGNED NOT NULL DEFAULT 0, losses INT UNSIGNED NOT NULL DEFAULT 0,
                completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (list_id, user_id, movie_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $alListStmt = $db->prepare("
                SELECT al.id, al.name, al.start_date, al.end_date
                FROM action_list_rankings alr
                JOIN action_lists al ON al.id = alr.list_id
                WHERE alr.user_id = ?
                GROUP BY al.id, al.name, al.start_date, al.end_date
                ORDER BY al.start_date DESC
            ");
            $alListStmt->execute([$viewUserId]);
            $alHeaders = $alListStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($alHeaders as $alh) {
                $lid = (int)$alh['id'];
                $alrStmt = $db->prepare("
                    SELECT alr.position, alr.wins, alr.losses, m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en
                    FROM action_list_rankings alr
                    JOIN movies m ON m.id = alr.movie_id
                    WHERE alr.list_id = ? AND alr.user_id = ?
                    ORDER BY alr.position ASC
                ");
                $alrStmt->execute([$lid, $viewUserId]);
                $userActionLists[$lid] = array_merge($alh, ['films' => $alrStmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
        } catch (\PDOException $e) { $userActionLists = []; }

    } elseif ($activeTab === 'tagebuch') {

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS diary_entries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                movie_id INT UNSIGNED NOT NULL,
                watch_date DATE NOT NULL,
                sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_entry (user_id, movie_id, watch_date),
                INDEX idx_user_date (user_id, watch_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $diaryFirst = sprintf('%04d-%02d-01', $diaryYear, $diaryMonth);
            $diaryLast  = sprintf('%04d-%02d-%02d', $diaryYear, $diaryMonth,
                          cal_days_in_month(CAL_GREGORIAN, $diaryMonth, $diaryYear));
            $ds = $db->prepare("
                SELECT de.watch_date, de.movie_id, de.sort_order,
                       m.title, m.title_en, m.poster_path, m.poster_path_en
                FROM diary_entries de
                JOIN movies m ON m.id = de.movie_id
                WHERE de.user_id = ? AND de.watch_date BETWEEN ? AND ?
                ORDER BY de.watch_date ASC, de.sort_order ASC
            ");
            $ds->execute([$viewUserId, $diaryFirst, $diaryLast]);
            foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $diaryEntriesByDay[$row['watch_date']][] = $row;
            }
        } catch (\PDOException $e) { $diaryEntriesByDay = []; }

    } elseif ($activeTab === 'sammlung') {

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS user_collection (
                user_id      INT UNSIGNED NOT NULL,
                movie_id     INT UNSIGNED NOT NULL,
                status       ENUM('besitz','interesse') NOT NULL DEFAULT 'interesse',
                storage_link VARCHAR(1000) NULL,
                added_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, movie_id),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("ALTER TABLE movies ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NULL");

            $s = $db->prepare("
                SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director, m.genre, uc.status
                FROM user_collection uc
                JOIN movies m ON m.id = uc.movie_id
                WHERE uc.user_id = ?
                  AND (m.media_type IS NULL OR m.media_type = '' OR m.media_type = 'movie')
                ORDER BY uc.status ASC, m.title ASC
            ");
            $s->execute([$viewUserId]);
            $userFilme = $s->fetchAll(PDO::FETCH_ASSOC);

            $s = $db->prepare("
                SELECT m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en, m.director, m.genre, uc.status
                FROM user_collection uc
                JOIN movies m ON m.id = uc.movie_id
                WHERE uc.user_id = ? AND m.media_type = 'tv'
                ORDER BY uc.status ASC, m.title ASC
            ");
            $s->execute([$viewUserId]);
            $userSerien = $s->fetchAll(PDO::FETCH_ASSOC);

            // Group by bucket
            foreach ($userFilme  as $f) { $groupedFilme[cmGetBucket($f['title'])][]  = $f; }
            foreach ($userSerien as $f) { $groupedSerien[cmGetBucket($f['title'])][] = $f; }

        } catch (\PDOException $e) { $userFilme = []; $userSerien = []; }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
body { background: #14325a !important; }

/* ── Layout ──────────────────────────────────────────────────────────────── */
.cm-layout  { display: flex; gap: 16px; align-items: flex-start; padding: 1.5rem 0; }
.cm-sidebar { flex: 0 0 220px; position: sticky; top: 80px;
              max-height: calc(100vh - 100px); overflow-y: auto; }
.cm-main    { flex: 1 1 auto; min-width: 0; }
@media (max-width: 640px) {
    .cm-layout  { flex-direction: column; }
    .cm-sidebar { flex: none; width: 100%; position: static; max-height: 220px; }
}

/* ── Member list ─────────────────────────────────────────────────────────── */
.member-search {
    width: 100%; background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.1); border-radius: 8px;
    color: #fff; padding: .45rem .75rem; font-size: .85rem;
    margin-bottom: .65rem; outline: none; box-sizing: border-box;
}
.member-search:focus  { border-color: rgba(232,184,75,.4); background: rgba(255,255,255,.07); }
.member-search::placeholder { color: rgba(255,255,255,.3); }
.member-list  { background: rgba(255,255,255,.03);
                border: 1px solid rgba(255,255,255,.08);
                border-radius: 12px; overflow: hidden; }
.member-item  {
    display: flex; align-items: center; gap: 10px;
    padding: .5rem .85rem;
    border-bottom: 1px solid rgba(255,255,255,.05);
    text-decoration: none; color: rgba(255,255,255,.7);
    transition: background .15s;
}
.member-item:last-child { border-bottom: none; }
.member-item:hover      { background: rgba(232,184,75,.07); color: #e0e0e0; }
.member-item.active     { background: rgba(232,184,75,.13); color: #e8b84b; }
.member-avatar {
    width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 700;
    background: rgba(232,184,75,.18); color: #e8b84b;
}
.member-item.active .member-avatar { background: rgba(232,184,75,.32); }
.member-name { font-size: .85rem; font-weight: 500; flex: 1; min-width: 0;
               white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* ── Main tabs ───────────────────────────────────────────────────────────── */
.cm-tabs {
    display: flex; gap: 4px; padding-top: 8px;
    border-bottom: 1px solid rgba(255,255,255,.08); margin-bottom: 1.1rem;
}
.cm-tab {
    display: inline-flex; align-items: center; gap: 4px;
    padding: .5rem 1.1rem; font-size: .9rem; font-weight: 600;
    border-bottom: 2px solid transparent; border-radius: 0;
    color: rgba(255,255,255,.45); text-decoration: none;
    transition: color .15s;
}
.cm-tab.active      { color: #e8b84b; border-bottom-color: #e8b84b; }
.cm-tab:hover:not(.active) { color: rgba(255,255,255,.75); }

/* ── Sub-tabs ────────────────────────────────────────────────────────────── */
.cm-subtabs { display: flex; gap: 6px; margin-bottom: 1rem; flex-wrap: wrap; }
.cm-subtab {
    padding: .32rem .85rem; font-size: .82rem; font-weight: 600;
    border-radius: 20px; cursor: pointer;
    border: 1px solid rgba(255,255,255,.12);
    background: rgba(255,255,255,.04); color: rgba(255,255,255,.5);
    transition: all .15s;
}
.cm-subtab.active       { background: rgba(232,184,75,.18); border-color: rgba(232,184,75,.4); color: #e8b84b; }
.cm-subtab:hover:not(.active) { background: rgba(255,255,255,.07); color: rgba(255,255,255,.8); }

/* ── Ranking rows ────────────────────────────────────────────────────────── */
.rangliste-row:hover { background: rgba(232,184,75,.07) !important; }
.film-link { color: inherit; text-decoration: none; }
.film-link:hover { color: #e8b84b; }

/* ── Status badges ───────────────────────────────────────────────────────── */
.status-besitz    { background: rgba(72,199,142,.15); color: #48c78e;
                    border: 1px solid rgba(72,199,142,.25); }
.status-interesse { background: rgba(100,149,237,.15); color: #6495ed;
                    border: 1px solid rgba(100,149,237,.25); }
.status-besitz, .status-interesse {
    border-radius: 10px; padding: 1px 7px; font-size: .7rem; font-weight: 600;
    white-space: nowrap;
}

/* ── Tagebuch-Kalender (read-only, gleiche Basis wie filmtagebuch.php) ───── */
.diary-calendar { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); border-radius:12px; overflow:hidden; }
.diary-weekdays { display:grid; grid-template-columns:repeat(7,1fr); background:rgba(232,184,75,.12); border-bottom:1px solid rgba(255,255,255,.1); }
.diary-wd       { text-align:center; padding:8px 4px; font-size:.78rem; font-weight:700; color:var(--mkfb-gold); letter-spacing:.04em; text-transform:uppercase; }
.diary-grid     { display:grid; grid-template-columns:repeat(7,1fr); }
.diary-cell     { min-height:90px; border-right:1px solid rgba(255,255,255,.06); border-bottom:1px solid rgba(255,255,255,.06); padding:5px; position:relative; }
.diary-cell-ro  { cursor:default; }
.diary-cell-ro[onclick] { cursor:pointer; transition:background .15s; }
.diary-cell-ro[onclick]:hover { background:rgba(255,255,255,.06); }
.diary-cell:nth-child(7n) { border-right:none; }
.diary-empty    { background:rgba(0,0,0,.1); }
.diary-today    { background:rgba(232,184,75,.07) !important; }
.diary-today-num { color:var(--mkfb-gold) !important; font-weight:800; }
.diary-day-num  { font-size:.72rem; font-weight:600; color:rgba(255,255,255,.45); margin-bottom:3px; line-height:1; }
.diary-covers   { display:flex; flex-wrap:wrap; gap:2px; }
.diary-thumb    { width:calc(33% - 2px); aspect-ratio:2/3; overflow:hidden; border-radius:3px; background:rgba(255,255,255,.08); flex-shrink:0; }
.diary-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
@media (max-width:576px) { .diary-cell { min-height:70px; padding:3px 2px; } .diary-thumb { width:calc(50% - 1px); } }

/* ── Letter nav (Sammlung) ───────────────────────────────────────────────── */
.cm-letter-nav {
    display: flex; flex-wrap: wrap; gap: 3px; margin: .75rem 0 1rem;
    padding: .45rem .55rem; background: rgba(255,255,255,.03);
    border: 1px solid rgba(255,255,255,.07); border-radius: 10px;
}
.cm-letter-btn {
    background: none; border: none; font-size: .72rem; font-weight: 700;
    padding: 3px 6px; border-radius: 5px; cursor: pointer;
    transition: all .15s; min-width: 22px; text-align: center;
}
.cm-letter-btn.has   { color: #fff; }
.cm-letter-btn.empty { color: rgba(255,255,255,.2); cursor: default; pointer-events: none; }
.cm-letter-btn:hover.has { background: rgba(232,184,75,.12); color: #e8b84b; }

/* ── Sammlung letter sections ────────────────────────────────────────────── */
.cm-letter-section { margin-bottom: 1.5rem; }
.cm-letter-hdr {
    display: flex; align-items: center; gap: .5rem;
    padding-bottom: .35rem; margin-bottom: .5rem;
    border-bottom: 1px solid rgba(232,184,75,.15);
}
.cm-letter-char { font-size: 1.3rem; font-weight: 900; color: #e8b84b; line-height: 1; min-width: 28px; }
.cm-letter-cnt  { font-size: .7rem; color: rgba(255,255,255,.3); font-weight: 600; }

/* ── Scrollbar ───────────────────────────────────────────────────────────── */
::-webkit-scrollbar       { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 3px; }
* { scrollbar-width: thin; scrollbar-color: rgba(232,184,75,.2) transparent; }
</style>

<main style="padding-top:6px; background:#14325a; flex:1;">

    <!-- ── Hero ──────────────────────────────────────────────────────────── -->
    <section class="py-4" style="background:linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom:1px solid rgba(232,184,75,.15);">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="fw-bold mb-1" style="color:#e8b84b;">
                        <i class="bi bi-person-lines-fill me-2"></i>Community Mitglieder
                    </h1>
                    <p class="mb-0" style="color:rgba(255,255,255,.55);">
                        Ranglisten und Sammlungen der Community
                    </p>
                </div>
                <div class="col-auto text-end">
                    <div style="color:#e8b84b; font-size:2rem; font-weight:800; line-height:1;"><?= count($members) ?></div>
                    <div style="color:rgba(255,255,255,.45); font-size:.8rem;">Mitglieder</div>
                </div>
            </div>
        </div>
    </section>

    <div class="container">
        <div class="cm-layout">

            <!-- ── Sidebar: Mitgliederliste ─────────────────────────────── -->
            <div class="cm-sidebar">
                <input class="member-search" type="text" id="memberSearch"
                       placeholder="Mitglied suchen…" autocomplete="off">
                <div class="member-list" id="memberList">
                    <?php foreach ($members as $m): ?>
                    <a href="?user=<?= (int)$m['id'] ?>&tab=<?= $activeTab ?>"
                       class="member-item <?= $viewUserId === (int)$m['id'] ? 'active' : '' ?>"
                       data-name="<?= e(mb_strtolower($m['username'])) ?>">
                        <div class="member-avatar"><?= e(mb_strtoupper(mb_substr($m['username'], 0, 1))) ?></div>
                        <span class="member-name"><?= e($m['username']) ?></span>
                        <?php if ((int)$m['id'] === $myUserId): ?>
                            <span style="font-size:.68rem; color:rgba(232,184,75,.55); flex-shrink:0;">Du</span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── Main content ─────────────────────────────────────────── -->
            <div class="cm-main">

                <?php if (!$viewUser): ?>
                <!-- No user selected -->
                <div class="text-center py-5" style="color:rgba(255,255,255,.4);">
                    <i class="bi bi-person-circle" style="font-size:3.5rem; color:rgba(232,184,75,.2);"></i>
                    <h4 class="mt-3" style="color:rgba(255,255,255,.45);">Mitglied auswählen</h4>
                    <p class="mb-0" style="font-size:.9rem;">
                        Klicke links auf ein Mitglied, um seine Ranglisten und Sammlung anzusehen.
                    </p>
                </div>

                <?php else: ?>

                <!-- User header -->
                <div class="d-flex align-items-center gap-3 mb-3 p-3"
                     style="background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.08); border-radius:12px;">
                    <div style="width:46px; height:46px; border-radius:50%; flex-shrink:0;
                                background:rgba(232,184,75,.2); color:#e8b84b;
                                display:flex; align-items:center; justify-content:center;
                                font-size:1.25rem; font-weight:700;">
                        <?= e(mb_strtoupper(mb_substr($viewUser['username'], 0, 1))) ?>
                    </div>
                    <div>
                        <div style="color:#e8b84b; font-size:1.1rem; font-weight:700;"><?= e($viewUser['username']) ?></div>
                        <div style="color:rgba(255,255,255,.35); font-size:.78rem;">Community-Mitglied</div>
                    </div>
                </div>

                <!-- Main tabs -->
                <div class="cm-tabs">
                    <a class="cm-tab <?= $activeTab === 'ranglisten' ? 'active' : '' ?>"
                       href="?user=<?= $viewUserId ?>&tab=ranglisten">
                        <i class="bi bi-trophy"></i>Ranglisten
                    </a>
                    <a class="cm-tab <?= $activeTab === 'aktionen' ? 'active' : '' ?>"
                       href="?user=<?= $viewUserId ?>&tab=aktionen">
                        <i class="bi bi-trophy-fill"></i>Aktionen
                    </a>
                    <a class="cm-tab <?= $activeTab === 'sammlung' ? 'active' : '' ?>"
                       href="?user=<?= $viewUserId ?>&tab=sammlung">
                        <i class="bi bi-collection"></i>Sammlung
                    </a>
                    <a class="cm-tab <?= $activeTab === 'tagebuch' ? 'active' : '' ?>"
                       href="?user=<?= $viewUserId ?>&tab=tagebuch">
                        <i class="bi bi-journal-play"></i>Tagebuch
                    </a>
                </div>

                <?php if ($activeTab === 'ranglisten'): ?>
                <!-- ══ Tab: Ranglisten ══════════════════════════════════════ -->

                <div class="cm-subtabs" style="flex-wrap:wrap;">
                    <button class="cm-subtab active" id="btn-meine-movie" onclick="switchSub('meine-movie')">
                        <i class="bi bi-camera-film me-1" style="color:#e8b84b;"></i>Rangliste Filme
                        <span style="opacity:.55;">(<?= count($userRankingMovies) ?>)</span>
                    </button>
                    <button class="cm-subtab" id="btn-meine-tv" onclick="switchSub('meine-tv')">
                        <i class="bi bi-tv me-1" style="color:#a78bfa;"></i>Rangliste Serien
                        <span style="opacity:.55;">(<?= count($userRankingTV) ?>)</span>
                    </button>
                    <button class="cm-subtab" id="btn-jgj-movie" onclick="switchSub('jgj-movie')">
                        <i class="bi bi-camera-film me-1" style="color:#e8b84b;"></i>JgJ Filme
                        <span style="opacity:.55;">(<?= count($userJgjMovies) ?>)</span>
                    </button>
                    <button class="cm-subtab" id="btn-jgj-tv" onclick="switchSub('jgj-tv')">
                        <i class="bi bi-tv me-1" style="color:#a78bfa;"></i>JgJ Serien
                        <span style="opacity:.55;">(<?= count($userJgjTV) ?>)</span>
                    </button>
                    <button class="cm-subtab" id="btn-turnier-movie" onclick="switchSub('turnier-movie')">
                        <i class="bi bi-diagram-3 me-1" style="color:#e8b84b;"></i>Turnier Filme
                        <span style="opacity:.55;">(<?= count($userTourneyMovies) ?>)</span>
                    </button>
                    <button class="cm-subtab" id="btn-turnier-tv" onclick="switchSub('turnier-tv')">
                        <i class="bi bi-diagram-3 me-1" style="color:#a78bfa;"></i>Turnier Serien
                        <span style="opacity:.55;">(<?= count($userTourneyTV) ?>)</span>
                    </button>
                </div>

                <?php
                // Hilfsfunktion: rendert eine Ranglisten-Tabelle (Meine Rangliste)
                function cmRenderRanking(array $list, string $emptyIcon, string $emptyText): void {
                    if (empty($list)): ?>
                    <div class="text-center py-5">
                        <i class="<?= $emptyIcon ?>" style="font-size:2.5rem; color:rgba(232,184,75,.2);"></i>
                        <p class="mt-3 mb-0" style="color:rgba(255,255,255,.4); font-size:.9rem;"><?= $emptyText ?></p>
                    </div>
                    <?php else: ?>
                    <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                        <?php $medals = [1=>'🥇',2=>'🥈',3=>'🥉'];
                        foreach ($list as $i => $film):
                            $rank   = (int)$film['position'];
                            $posClr = $rank===1?'#e8b84b':($rank===2?'#b0b0b0':($rank===3?'#cd7f32':'rgba(255,255,255,.4)'));
                            $poster = moviePosterUrl($film,'w92');
                            $rowBg  = $i%2===0?'rgba(255,255,255,.025)':'#14325a'; ?>
                        <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                             style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">
                            <div class="text-center flex-shrink-0" style="width:32px;">
                                <?php if (isset($medals[$rank])): ?>
                                    <span style="font-size:1.15rem; line-height:1;"><?= $medals[$rank] ?></span>
                                <?php else: ?>
                                    <span style="color:<?= $posClr ?>; font-size:.88rem; font-weight:700;"><?= $rank ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-shrink-0" style="width:38px; height:57px; border-radius:4px; overflow:hidden; background:#1e3d7a;">
                                <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                                     style="width:100%; height:100%; object-fit:cover;" loading="lazy" decoding="async"
                                     onerror="this.src='https://placehold.co/38x57/1e3a5f/e8b84b?text=?'">
                            </div>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.9rem;">
                                    <a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a>
                                </div>
                                <div style="color:rgba(255,255,255,.38); font-size:.76rem;">
                                    <?= (int)$film['year'] ?><?php if (!empty($film['director'])): ?> &middot; <?= e($film['director']) ?><?php endif; ?>
                                </div>
                            </div>
                            <div class="d-none d-sm-flex align-items-center gap-3 flex-shrink-0 text-end">
                                <div>
                                    <div style="color:#e8b84b; font-size:.8rem; font-weight:600;"><?= (int)$film['elo'] ?></div>
                                    <div style="color:rgba(255,255,255,.28); font-size:.65rem;">ELO</div>
                                </div>
                                <div>
                                    <div style="color:rgba(255,255,255,.6); font-size:.8rem; font-weight:600;"><?= (int)$film['wins'] ?><span style="opacity:.4;">/</span><?= (int)$film['losses'] ?></div>
                                    <div style="color:rgba(255,255,255,.28); font-size:.65rem;">S/N</div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif;
                }

                // Hilfsfunktion: rendert eine JgJ-Tabelle
                function cmRenderJgj(array $list, string $emptyText): void {
                    if (empty($list)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-diagram-3" style="font-size:2.5rem; color:rgba(232,184,75,.2);"></i>
                        <p class="mt-3 mb-0" style="color:rgba(255,255,255,.4); font-size:.9rem;"><?= $emptyText ?></p>
                    </div>
                    <?php else: ?>
                    <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                        <?php $medals = [1=>'🥇',2=>'🥈',3=>'🥉'];
                        foreach ($list as $i => $film):
                            $rank   = (int)$film['jgj_rank'];
                            $posClr = $rank===1?'#e8b84b':($rank===2?'#b0b0b0':($rank===3?'#cd7f32':'rgba(255,255,255,.4)'));
                            $poster = moviePosterUrl($film,'w92');
                            $rowBg  = $i%2===0?'rgba(255,255,255,.025)':'#14325a'; ?>
                        <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                             style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">
                            <div class="text-center flex-shrink-0" style="width:32px;">
                                <?php if (isset($medals[$rank])): ?>
                                    <span style="font-size:1.15rem; line-height:1;"><?= $medals[$rank] ?></span>
                                <?php else: ?>
                                    <span style="color:<?= $posClr ?>; font-size:.88rem; font-weight:700;"><?= $rank ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-shrink-0" style="width:38px; height:57px; border-radius:4px; overflow:hidden; background:#1e3d7a;">
                                <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                                     style="width:100%; height:100%; object-fit:cover;" loading="lazy" decoding="async"
                                     onerror="this.src='https://placehold.co/38x57/1e3a5f/e8b84b?text=?'">
                            </div>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.9rem;">
                                    <a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a>
                                </div>
                                <div style="color:rgba(255,255,255,.38); font-size:.76rem;">
                                    <?= (int)$film['year'] ?><?php if (!empty($film['director'])): ?> &middot; <?= e($film['director']) ?><?php endif; ?>
                                </div>
                            </div>
                            <div class="d-none d-sm-flex align-items-center gap-3 flex-shrink-0 text-end">
                                <div>
                                    <div style="color:#48c78e; font-size:.8rem; font-weight:600;"><?= (int)$film['wins'] ?></div>
                                    <div style="color:rgba(255,255,255,.28); font-size:.65rem;">Siege</div>
                                </div>
                                <div>
                                    <div style="color:rgba(255,80,80,.75); font-size:.8rem; font-weight:600;"><?= (int)$film['losses'] ?></div>
                                    <div style="color:rgba(255,255,255,.28); font-size:.65rem;">Niederl.</div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif;
                }
                ?>

                <!-- Sub-tab: Meine Rangliste Filme -->
                <div id="sub-meine-movie">
                    <?php cmRenderRanking($userRankingMovies, 'bi bi-camera-film', 'Noch keine Filme in der persönlichen Rangliste.'); ?>
                </div>

                <!-- Sub-tab: Meine Rangliste Serien -->
                <div id="sub-meine-tv" style="display:none;">
                    <?php cmRenderRanking($userRankingTV, 'bi bi-tv', 'Noch keine Serien in der persönlichen Rangliste.'); ?>
                </div>

                <!-- Sub-tab: JgJ Filme -->
                <div id="sub-jgj-movie" style="display:none;">
                    <?php cmRenderJgj($userJgjMovies, 'Noch keine JgJ-Rangliste für Filme vorhanden.'); ?>
                </div>

                <!-- Sub-tab: JgJ Serien -->
                <div id="sub-jgj-tv" style="display:none;">
                    <?php cmRenderJgj($userJgjTV, 'Noch keine JgJ-Rangliste für Serien vorhanden.'); ?>
                </div>

                <!-- Sub-tab: Turnier Filme -->
                <div id="sub-turnier-movie" style="display:none;">
                    <?php if (empty($userTourneyMovies)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-diagram-3" style="font-size:2.5rem; color:rgba(232,184,75,.2);"></i>
                        <p class="mt-3 mb-0" style="color:rgba(255,255,255,.4); font-size:.9rem;">Noch kein abgeschlossenes Filmturnier vorhanden.</p>
                    </div>
                    <?php else: ?>
                    <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                        <?php $medals = [1=>'🥇',2=>'🥈',3=>'🥉'];
                        foreach ($userTourneyMovies as $i => $film):
                            $rank   = (int)$film['tourney_rank'];
                            $posClr = $rank===1?'#e8b84b':($rank===2?'#b0b0b0':($rank===3?'#cd7f32':'rgba(255,255,255,.4)'));
                            $poster = moviePosterUrl($film,'w92');
                            $rowBg  = $i%2===0?'rgba(255,255,255,.025)':'#14325a'; ?>
                        <div class="d-flex align-items-center gap-3 px-3 py-2"
                             style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">
                            <div class="text-center flex-shrink-0" style="width:32px;">
                                <?php if (isset($medals[$rank])): ?><span style="font-size:1.15rem; line-height:1;"><?= $medals[$rank] ?></span>
                                <?php else: ?><span style="color:<?= $posClr ?>; font-size:.88rem; font-weight:700;"><?= $rank ?></span><?php endif; ?>
                            </div>
                            <div class="flex-shrink-0" style="width:38px; height:57px; border-radius:4px; overflow:hidden; background:#1e3d7a;">
                                <img src="<?= e($poster) ?>" alt="" style="width:100%; height:100%; object-fit:cover;" loading="lazy"
                                     onerror="this.src='https://placehold.co/38x57/1e3a5f/e8b84b?text=?'">
                            </div>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.9rem;">
                                    <a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a>
                                </div>
                                <div style="color:rgba(255,255,255,.38); font-size:.76rem;">
                                    <?= (int)$film['year'] ?><?php if (!empty($film['director'])): ?> &middot; <?= e($film['director']) ?><?php endif; ?>
                                </div>
                            </div>
                            <div class="d-none d-sm-flex align-items-center gap-3 flex-shrink-0 text-end">
                                <div>
                                    <div style="color:#48c78e; font-size:.8rem; font-weight:600;"><?= (int)$film['wins'] ?></div>
                                    <div style="color:rgba(255,255,255,.28); font-size:.65rem;">Siege</div>
                                </div>
                                <div>
                                    <div style="color:rgba(255,255,255,.5); font-size:.8rem; font-weight:600;"><?= (int)$film['matches_played'] ?></div>
                                    <div style="color:rgba(255,255,255,.28); font-size:.65rem;">Spiele</div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sub-tab: Turnier Serien -->
                <div id="sub-turnier-tv" style="display:none;">
                    <?php if (empty($userTourneyTV)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-diagram-3" style="font-size:2.5rem; color:rgba(167,139,250,.2);"></i>
                        <p class="mt-3 mb-0" style="color:rgba(255,255,255,.4); font-size:.9rem;">Noch kein abgeschlossenes Serienturnier vorhanden.</p>
                    </div>
                    <?php else: ?>
                    <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden;">
                        <?php $medals = [1=>'🥇',2=>'🥈',3=>'🥉'];
                        foreach ($userTourneyTV as $i => $film):
                            $rank   = (int)$film['tourney_rank'];
                            $posClr = $rank===1?'#e8b84b':($rank===2?'#b0b0b0':($rank===3?'#cd7f32':'rgba(255,255,255,.4)'));
                            $poster = moviePosterUrl($film,'w92');
                            $rowBg  = $i%2===0?'rgba(255,255,255,.025)':'#14325a'; ?>
                        <div class="d-flex align-items-center gap-3 px-3 py-2"
                             style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">
                            <div class="text-center flex-shrink-0" style="width:32px;">
                                <?php if (isset($medals[$rank])): ?><span style="font-size:1.15rem; line-height:1;"><?= $medals[$rank] ?></span>
                                <?php else: ?><span style="color:<?= $posClr ?>; font-size:.88rem; font-weight:700;"><?= $rank ?></span><?php endif; ?>
                            </div>
                            <div class="flex-shrink-0" style="width:38px; height:57px; border-radius:4px; overflow:hidden; background:#1e3d7a;">
                                <img src="<?= e($poster) ?>" alt="" style="width:100%; height:100%; object-fit:cover;" loading="lazy"
                                     onerror="this.src='https://placehold.co/38x57/1e3a5f/e8b84b?text=?'">
                            </div>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.9rem;">
                                    <a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a>
                                </div>
                                <div style="color:rgba(255,255,255,.38); font-size:.76rem;">
                                    <?= (int)$film['year'] ?><?php if (!empty($film['director'])): ?> &middot; <?= e($film['director']) ?><?php endif; ?>
                                </div>
                            </div>
                            <div class="d-none d-sm-flex align-items-center gap-3 flex-shrink-0 text-end">
                                <div>
                                    <div style="color:#48c78e; font-size:.8rem; font-weight:600;"><?= (int)$film['wins'] ?></div>
                                    <div style="color:rgba(255,255,255,.28); font-size:.65rem;">Siege</div>
                                </div>
                                <div>
                                    <div style="color:rgba(255,255,255,.5); font-size:.8rem; font-weight:600;"><?= (int)$film['matches_played'] ?></div>
                                    <div style="color:rgba(255,255,255,.28); font-size:.65rem;">Spiele</div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php elseif ($activeTab === 'aktionen'): ?>
                <!-- ══ Tab: Aktions-Ranglisten ══════════════════════════════ -->

                <?php if (empty($userActionLists)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-trophy" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                    <h5 class="mt-3" style="color:rgba(255,255,255,.5);">Noch keine Aktionen abgeschlossen</h5>
                </div>
                <?php else: ?>
                <?php foreach ($userActionLists as $lid => $al): ?>
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <h6 class="fw-bold mb-0" style="color:#e8b84b;">
                            <i class="bi bi-trophy-fill me-1"></i><?= e($al['name']) ?>
                        </h6>
                        <span style="color:rgba(255,255,255,.35); font-size:.75rem;">
                            <?= date('d.m.Y', strtotime($al['start_date'])) ?> – <?= date('d.m.Y', strtotime($al['end_date'])) ?>
                        </span>
                    </div>
                    <div style="border:1px solid rgba(255,255,255,.07); border-radius:10px; overflow:hidden;">
                        <?php foreach ($al['films'] as $i => $f): ?>
                        <?php
                            $pos    = (int)$f['position'];
                            $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                            $poster = moviePosterUrl($f, 'w92');
                            $rowBg  = $i % 2 === 0 ? 'rgba(255,255,255,.02)' : '#14325a';
                        ?>
                        <div style="display:flex;align-items:center;gap:.6rem;padding:.4rem .8rem;border-bottom:1px solid rgba(255,255,255,.04);background:<?= $rowBg ?>;">
                            <span style="min-width:1.6rem;font-size:.8rem;font-weight:700;color:<?= $pos<=3?'#e8b84b':'rgba(255,255,255,.35)' ?>;text-align:right;"><?= isset($medals[$pos]) ? $medals[$pos] : $pos ?></span>
                            <img src="<?= $poster ?>" alt="" style="width:22px;height:33px;object-fit:cover;border-radius:3px;flex-shrink:0;" loading="lazy"
                                 onerror="this.src='https://placehold.co/22x33/1e3a5f/e8b84b?text=?'">
                            <a href="/film.php?id=<?= (int)$f['id'] ?>" target="_blank" style="flex:1;color:#ddd;text-decoration:none;font-size:.83rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e(movieTitle($f)) ?></a>
                            <span style="font-size:.73rem;color:rgba(255,255,255,.35);"><?= (int)$f['wins'] ?>S/<?= (int)$f['losses'] ?>N</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php elseif ($activeTab === 'tagebuch'): ?>
                <!-- ══ Tab: Tagebuch ══════════════════════════════════════ -->
                <?php
                $_dMonthNames = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
                $_dDaysInMonth  = cal_days_in_month(CAL_GREGORIAN, $diaryMonth, $diaryYear);
                $_dFirstWeekday = (int)date('N', mktime(0,0,0,$diaryMonth,1,$diaryYear));
                $_dPrevMonth = $diaryMonth === 1 ? 12 : $diaryMonth - 1;
                $_dPrevYear  = $diaryMonth === 1 ? $diaryYear - 1 : $diaryYear;
                $_dNextMonth = $diaryMonth === 12 ? 1 : $diaryMonth + 1;
                $_dNextYear  = $diaryMonth === 12 ? $diaryYear + 1 : $diaryYear;
                $_dTmdbImg   = 'https://image.tmdb.org/t/p/w92';
                $_dToday     = date('Y-m-d');
                ?>

                <!-- Kalender-Navigation -->
                <div class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
                    <a href="?user=<?= $viewUserId ?>&tab=tagebuch&cal_year=<?= $_dPrevYear ?>&cal_month=<?= $_dPrevMonth ?>"
                       class="btn btn-sm" style="background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.15);">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                    <span class="fw-bold" style="color:var(--mkfb-gold);">
                        <?= $_dMonthNames[$diaryMonth-1] ?> <?= $diaryYear ?>
                    </span>
                    <a href="?user=<?= $viewUserId ?>&tab=tagebuch&cal_year=<?= $_dNextYear ?>&cal_month=<?= $_dNextMonth ?>"
                       class="btn btn-sm" style="background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.15);">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </div>

                <!-- Kalender-Raster (read-only) -->
                <div class="diary-calendar">
                    <div class="diary-weekdays">
                        <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $_dWd): ?>
                        <div class="diary-wd"><?= $_dWd ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="diary-grid">
                        <?php for ($i = 1; $i < $_dFirstWeekday; $i++): ?>
                        <div class="diary-cell diary-empty"></div>
                        <?php endfor; ?>

                        <?php for ($d = 1; $d <= $_dDaysInMonth; $d++):
                            $_dDateStr = sprintf('%04d-%02d-%02d', $diaryYear, $diaryMonth, $d);
                            $_dDayEntries = $diaryEntriesByDay[$_dDateStr] ?? [];
                            $_dIsToday = ($_dDateStr === $_dToday);
                        ?>
                        <div class="diary-cell diary-cell-ro <?= $_dIsToday ? 'diary-today' : '' ?>"
                             <?= !empty($_dDayEntries) ? 'onclick="cmOpenDay(this)"' : '' ?>
                             data-date="<?= $_dDateStr ?>">
                            <div class="diary-day-num <?= $_dIsToday ? 'diary-today-num' : '' ?>"><?= $d ?></div>
                            <div class="diary-covers">
                                <?php foreach (array_slice($_dDayEntries, 0, 6) as $_dEntry):
                                    $_dPoster = moviePosterUrl($_dEntry, 'w92');
                                    $_dTitle  = movieTitle($_dEntry);
                                ?>
                                <div class="diary-thumb" title="<?= htmlspecialchars($_dTitle, ENT_QUOTES) ?>">
                                    <img src="<?= htmlspecialchars($_dPoster, ENT_QUOTES) ?>"
                                         alt="<?= htmlspecialchars($_dTitle, ENT_QUOTES) ?>"
                                         loading="lazy" onerror="this.onerror=null;this.src='/img/no-poster.svg'">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Modal: Tages-Detail (read-only) -->
                <div class="modal fade" id="cmDayModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content" style="background:#0d2748;border:1px solid rgba(255,255,255,.15);color:#e0e0e0;">
                            <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,.1);">
                                <h5 class="modal-title fw-bold">
                                    <i class="bi bi-journal-play me-2" style="color:var(--mkfb-gold);"></i>
                                    <span id="cmDayLabel"></span>
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div id="cmDayCovers" class="d-flex flex-wrap gap-3"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                <!-- ══ Tab: Sammlung ═══════════════════════════════════════ -->

                <?php
                // Build bucket key sets for letter nav
                $bucketsFilme  = array_keys($groupedFilme);
                $bucketsSerien = array_keys($groupedSerien);
                ?>

                <div class="cm-subtabs">
                    <button class="cm-subtab active" id="btn-filme" onclick="switchSubSammlung('filme')">
                        <i class="bi bi-film me-1"></i>Filme
                        <span style="opacity:.55;">(<?= count($userFilme) ?>)</span>
                    </button>
                    <button class="cm-subtab" id="btn-serien" onclick="switchSubSammlung('serien')">
                        <i class="bi bi-tv me-1"></i>Serien
                        <span style="opacity:.55;">(<?= count($userSerien) ?>)</span>
                    </button>
                </div>

                <!-- Letter nav: Filme -->
                <div id="nav-filme" class="cm-letter-nav">
                    <?php foreach ($CM_BUCKET_ORDER as $b):
                        $has = in_array($b, $bucketsFilme);
                    ?>
                    <button class="cm-letter-btn <?= $has ? 'has' : 'empty' ?>"
                            <?= $has ? "onclick=\"scrollToCmBucket('filme','".e($b)."')\"" : '' ?>>
                        <?= e($b) ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Letter nav: Serien (hidden initially) -->
                <div id="nav-serien" class="cm-letter-nav" style="display:none;">
                    <?php foreach ($CM_BUCKET_ORDER as $b):
                        $has = in_array($b, $bucketsSerien);
                    ?>
                    <button class="cm-letter-btn <?= $has ? 'has' : 'empty' ?>"
                            <?= $has ? "onclick=\"scrollToCmBucket('serien','".e($b)."')\"" : '' ?>>
                        <?= e($b) ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Sub-tab: Filme -->
                <div id="sub-filme">
                    <?php if (empty($groupedFilme)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-film" style="font-size:2.5rem; color:rgba(232,184,75,.2);"></i>
                        <p class="mt-3 mb-0" style="color:rgba(255,255,255,.4); font-size:.9rem;">
                            Noch keine Filme in der Sammlung.
                        </p>
                    </div>
                    <?php else:
                        foreach ($CM_BUCKET_ORDER as $bucket):
                            if (empty($groupedFilme[$bucket])) continue;
                    ?>
                    <div class="cm-letter-section" id="cm-filme-<?= e($bucket) ?>">
                        <div class="cm-letter-hdr">
                            <span class="cm-letter-char"><?= e($bucket) ?></span>
                            <span class="cm-letter-cnt"><?= count($groupedFilme[$bucket]) ?> Titel</span>
                        </div>
                        <div style="border:1px solid rgba(255,255,255,.08); border-radius:10px; overflow:hidden;">
                        <?php foreach ($groupedFilme[$bucket] as $i => $film):
                            $poster = moviePosterUrl($film, 'w92');
                            $rowBg  = $i % 2 === 0 ? 'rgba(255,255,255,.025)' : '#14325a';
                        ?>
                        <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                             style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">
                            <div class="flex-shrink-0" style="width:38px; height:57px; border-radius:4px; overflow:hidden; background:#1e3d7a;">
                                <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                                     style="width:100%; height:100%; object-fit:cover;"
                                     loading="lazy" decoding="async"
                                     onerror="this.src='https://placehold.co/38x57/1e3a5f/e8b84b?text=?'">
                            </div>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.9rem;">
                                    <a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a>
                                </div>
                                <div style="color:rgba(255,255,255,.38); font-size:.76rem;">
                                    <?= (int)$film['year'] ?>
                                    <?php if (!empty($film['genre'])): ?>&middot; <?= e($film['genre']) ?><?php endif; ?>
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <span class="status-<?= e($film['status']) ?>">
                                    <?= $film['status'] === 'besitz' ? 'Besitz' : 'Interesse' ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Sub-tab: Serien -->
                <div id="sub-serien" style="display:none;">
                    <?php if (empty($groupedSerien)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-tv" style="font-size:2.5rem; color:rgba(232,184,75,.2);"></i>
                        <p class="mt-3 mb-0" style="color:rgba(255,255,255,.4); font-size:.9rem;">
                            Noch keine Serien in der Sammlung.
                        </p>
                    </div>
                    <?php else:
                        foreach ($CM_BUCKET_ORDER as $bucket):
                            if (empty($groupedSerien[$bucket])) continue;
                    ?>
                    <div class="cm-letter-section" id="cm-serien-<?= e($bucket) ?>">
                        <div class="cm-letter-hdr">
                            <span class="cm-letter-char"><?= e($bucket) ?></span>
                            <span class="cm-letter-cnt"><?= count($groupedSerien[$bucket]) ?> Titel</span>
                        </div>
                        <div style="border:1px solid rgba(255,255,255,.08); border-radius:10px; overflow:hidden;">
                        <?php foreach ($groupedSerien[$bucket] as $i => $film):
                            $poster = moviePosterUrl($film, 'w92');
                            $rowBg  = $i % 2 === 0 ? 'rgba(255,255,255,.025)' : '#14325a';
                        ?>
                        <div class="d-flex align-items-center gap-3 px-3 py-2 rangliste-row"
                             style="background:<?= $rowBg ?>; border-bottom:1px solid rgba(255,255,255,.05);">
                            <div class="flex-shrink-0" style="width:38px; height:57px; border-radius:4px; overflow:hidden; background:#1e3d7a;">
                                <img src="<?= e($poster) ?>" alt="<?= e(movieTitle($film)) ?>"
                                     style="width:100%; height:100%; object-fit:cover;"
                                     loading="lazy" decoding="async"
                                     onerror="this.src='https://placehold.co/38x57/1e3a5f/e8b84b?text=?'">
                            </div>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="text-truncate fw-semibold" style="color:#e0e0e0; font-size:.9rem;">
                                    <a href="/film.php?id=<?= (int)$film['id'] ?>" class="film-link"><?= e(movieTitle($film)) ?></a>
                                </div>
                                <div style="color:rgba(255,255,255,.38); font-size:.76rem;">
                                    <?= (int)$film['year'] ?>
                                    <?php if (!empty($film['genre'])): ?>&middot; <?= e($film['genre']) ?><?php endif; ?>
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <span class="status-<?= e($film['status']) ?>">
                                    <?= $film['status'] === 'besitz' ? 'Besitz' : 'Interesse' ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

                <?php endif; // activeTab ?>

                <?php endif; // viewUser ?>

            </div><!-- .cm-main -->
        </div><!-- .cm-layout -->
    </div><!-- .container -->

</main>

<script>
// ── Mitglied-Suche ────────────────────────────────────────────────────────────
document.getElementById('memberSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.member-item').forEach(function (el) {
        el.style.display = el.dataset.name.includes(q) ? '' : 'none';
    });
});

// ── Sub-tabs: Ranglisten ──────────────────────────────────────────────────────
function switchSub(name) {
    ['meine-movie','meine-tv','jgj-movie','jgj-tv','turnier-movie','turnier-tv'].forEach(function(id) {
        var el = document.getElementById('sub-' + id);
        var btn = document.getElementById('btn-' + id);
        if (el)  el.style.display = id === name ? '' : 'none';
        if (btn) btn.classList.toggle('active', id === name);
    });
}

// ── Sub-tabs: Sammlung ────────────────────────────────────────────────────────
function switchSubSammlung(name) {
    document.getElementById('sub-filme').style.display  = name === 'filme'  ? '' : 'none';
    document.getElementById('sub-serien').style.display = name === 'serien' ? '' : 'none';
    document.getElementById('btn-filme').classList.toggle('active',  name === 'filme');
    document.getElementById('btn-serien').classList.toggle('active', name === 'serien');
    const nf = document.getElementById('nav-filme');
    const ns = document.getElementById('nav-serien');
    if (nf) nf.style.display = name === 'filme'  ? '' : 'none';
    if (ns) ns.style.display = name === 'serien' ? '' : 'none';
}

// ── Buchstaben-Scroll (Sammlung) ──────────────────────────────────────────────
function scrollToCmBucket(type, bucket) {
    const el = document.getElementById('cm-' + type + '-' + bucket);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Scroll selected member into view on page load ─────────────────────────────
(function () {
    const active = document.querySelector('.member-item.active');
    if (active) active.scrollIntoView({ block: 'nearest' });
})();

// ── Tagebuch: Tages-Modal (read-only) ────────────────────────────────────────
<?php if ($activeTab === 'tagebuch' && $viewUser): ?>
const CM_DIARY_DATA = <?= json_encode(array_map(function($entries) {
    return array_map(function($e) {
        return ['title' => $e['title'], 'poster_path' => $e['poster_path']];
    }, $entries);
}, $diaryEntriesByDay), JSON_UNESCAPED_UNICODE) ?>;
const CM_TMDB = '<?= 'https://image.tmdb.org/t/p/w185' ?>';
const CM_MONTH_NAMES = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
let cmDayModal = null;

document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('cmDayModal');
    if (modalEl) cmDayModal = new bootstrap.Modal(modalEl);
});

function cmOpenDay(cell) {
    const dateStr = cell.dataset.date;
    const entries = CM_DIARY_DATA[dateStr] || [];
    if (!entries.length) return;

    const parts = dateStr.split('-');
    document.getElementById('cmDayLabel').textContent =
        parseInt(parts[2]) + '. ' + CM_MONTH_NAMES[parseInt(parts[1])-1] + ' ' + parts[0];

    const box = document.getElementById('cmDayCovers');
    box.innerHTML = '';
    entries.forEach(function (e) {
        const poster = e.poster_path ? CM_TMDB + e.poster_path : '/img/no-poster.svg';
        const wrap   = document.createElement('div');
        wrap.style.cssText = 'text-align:center;width:90px;';
        wrap.innerHTML = `<img src="${escCm(poster)}" alt="${escCm(e.title)}" loading="lazy"
            style="width:90px;aspect-ratio:2/3;object-fit:cover;border-radius:7px;background:rgba(255,255,255,.08);"
            onerror="this.onerror=null;this.src='/img/no-poster.svg'">
            <div style="font-size:.7rem;color:rgba(255,255,255,.6);margin-top:4px;line-height:1.2;
                overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                ${escCm(e.title)}</div>`;
        box.appendChild(wrap);
    });

    if (cmDayModal) cmDayModal.show();
}

function escCm(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
