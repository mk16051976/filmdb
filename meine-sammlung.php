<?php
$pageTitle = 'Meine Sammlung – MKFB';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/lang.php';
requireLogin();
requirePhase(2);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Tabellen sicherstellen ────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS user_collection (
    user_id      INT UNSIGNED NOT NULL,
    movie_id     INT UNSIGNED NOT NULL,
    status       ENUM('besitz','interesse') NOT NULL DEFAULT 'interesse',
    storage_link VARCHAR(1000) NULL,
    added_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, movie_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("ALTER TABLE movies
    ADD COLUMN IF NOT EXISTS director    VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS actors      TEXT         NULL,
    ADD COLUMN IF NOT EXISTS country     VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS imdb_id     VARCHAR(20)  NULL,
    ADD COLUMN IF NOT EXISTS media_type  VARCHAR(10)  NULL");

$db->exec("CREATE TABLE IF NOT EXISTS episode_watched (
    user_id     INT UNSIGNED   NOT NULL,
    movie_id    INT UNSIGNED   NOT NULL,
    season_num  TINYINT UNSIGNED NOT NULL,
    episode_num SMALLINT UNSIGNED NOT NULL,
    watched_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, movie_id, season_num, episode_num),
    INDEX idx_series (user_id, movie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── TMDB Helper ───────────────────────────────────────────────────────────────
function samml_tmdb(string $ep, array $p = []): ?array {
    if (!defined('TMDB_API_KEY') || TMDB_API_KEY === '') return null;
    $p['api_key'] = TMDB_API_KEY;
    $url = 'https://api.themoviedb.org/3/' . ltrim($ep, '/') . '?' . http_build_query($p);
    $ch  = curl_init($url);
    $ca  = ini_get('curl.cainfo') ?: 'C:/xampp/php/extras/ssl/cacert.pem';
    $opt = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_TIMEOUT=>10];
    if ($ca && file_exists($ca)) $opt[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch); curl_close($ch);
    return $body ? (json_decode($body, true) ?: null) : null;
}

// ── Bucket-Funktion ───────────────────────────────────────────────────────────
function getBucket(string $title): string {
    $l = mb_strtolower(trim($title));
    foreach (['das ','der ','die ','the '] as $pre) {
        if (mb_substr($l, 0, mb_strlen($pre)) === $pre) return ucfirst(trim($pre));
    }
    $c = mb_strtoupper(mb_substr(trim($title), 0, 1));
    if (preg_match('/\d/', $c)) return $c;   // individual digit: '0'–'9'
    if (ctype_alpha($c)) return $c;
    return '#';                               // Sonderzeichen
}

$BUCKET_ORDER = ['#','0','1','2','3','4','5','6','7','8','9','A','B','C','D','Das','Der','Die','E','F','G','H','I',
                 'J','K','L','M','N','O','P','Q','R','S','T','The','U','V','W','X','Y','Z'];

// ── AJAX ──────────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($action === 'tmdb_search') {
    header('Content-Type: application/json');
    $q = trim($_POST['query'] ?? '');
    if (strlen($q) < 1) { echo json_encode(['results'=>[]]); exit; }

    $out = [];

    // ── 1. TMDB-Suche ─────────────────────────────────────────────────────────
    $data = samml_tmdb('search/multi', ['query'=>$q,'language'=>'de-DE','page'=>1]);
    $tmdbIds = [];
    foreach (array_slice($data['results'] ?? [], 0, 12) as $m) {
        $isM = ($m['media_type']??'') === 'movie';
        $isT = ($m['media_type']??'') === 'tv';
        if (!$isM && !$isT) continue;
        $title = $isM ? ($m['title']??'') : ($m['name']??'');
        $date  = $isM ? ($m['release_date']??'') : ($m['first_air_date']??'');
        if (!$title) continue;
        $chk = $db->prepare("SELECT uc.status FROM movies mv JOIN user_collection uc ON uc.movie_id=mv.id WHERE mv.tmdb_id=? AND COALESCE(mv.media_type,'movie')=? AND uc.user_id=?");
        $chk->execute([$m['id'], $m['media_type'], $userId]);
        $inColl = $chk->fetchColumn();
        $tmdbIds[] = $m['id'];
        $out[] = ['tmdb_id'=>$m['id'],'media_type'=>$m['media_type'],'title'=>$title,
                  'year'=>$date ? (int)substr($date,0,4) : null,
                  'poster_path'=>$m['poster_path']??null,
                  'overview'=>mb_substr($m['overview']??'',0,160),
                  'in_collection'=>$inColl ?: false];
    }

    // ── 2. Lokale DB-Fallback: greift wenn TMDB wenige/keine Treffer liefert ──
    if (count($out) < 5) {
        $like  = '%' . $q . '%';
        $local = $db->prepare("
            SELECT m.id, m.title, m.year, m.poster_path, m.overview,
                   m.tmdb_id, COALESCE(m.media_type,'movie') AS media_type,
                   uc.status AS in_collection
            FROM movies m
            LEFT JOIN user_collection uc ON uc.movie_id = m.id AND uc.user_id = ?
            WHERE m.title LIKE ? OR m.original_title LIKE ?
            ORDER BY m.year DESC
            LIMIT 12
        ");
        $local->execute([$userId, $like, $like]);
        foreach ($local->fetchAll(PDO::FETCH_ASSOC) as $lm) {
            if ($lm['tmdb_id'] && in_array((int)$lm['tmdb_id'], $tmdbIds)) continue; // Kein Duplikat
            $out[] = ['tmdb_id'=>(int)($lm['tmdb_id']??0),
                      'media_type'=>$lm['media_type'],
                      'title'=>$lm['title'],
                      'year'=>(int)($lm['year']??0) ?: null,
                      'poster_path'=>$lm['poster_path']??null,
                      'overview'=>mb_substr($lm['overview']??'',0,160),
                      'in_collection'=>$lm['in_collection'] ?: false,
                      '_local'=>true];
        }
    }

    echo json_encode(['results'=>$out]); exit;
}

if ($action === 'add_film') {
    header('Content-Type: application/json');
    $tmdbId    = (int)($_POST['tmdb_id']??0);
    $mediaType = ($_POST['media_type']??'movie') === 'tv' ? 'tv' : 'movie';
    $status    = in_array($_POST['status']??'',['besitz','interesse']) ? $_POST['status'] : 'interesse';
    if (!$tmdbId) { echo json_encode(['ok'=>false,'msg'=>'Ungültige ID']); exit; }

    // media_type mitprüfen: TMDB-Film-IDs und Serien-IDs sind getrennte Namensräume
    $sentTitle = trim($_POST['title'] ?? '');
    $chk = $db->prepare("SELECT id, title FROM movies WHERE tmdb_id=? AND COALESCE(media_type,'movie')=?");
    $chk->execute([$tmdbId, $mediaType]);
    $existing  = $chk->fetch(PDO::FETCH_ASSOC);
    $movieId   = $existing ? (int)$existing['id'] : null;

    // Titel-Abgleich: wenn gespeicherter Titel ≠ gesuchter Titel → Eintrag ist korrupt → neu laden
    $titleMismatch = $movieId && $sentTitle &&
                     mb_strtolower($existing['title'] ?? '') !== mb_strtolower($sentTitle);

    if (!$movieId || $titleMismatch) {
        $ep     = $mediaType === 'tv' ? "tv/{$tmdbId}" : "movie/{$tmdbId}";
        $detail = samml_tmdb($ep, ['language'=>'de-DE','append_to_response'=>'credits']);
        if (!$detail || !empty($detail['status_code'])) {
            echo json_encode(['ok'=>false,'msg'=>'TMDB-Fehler']); exit;
        }
        $title   = $mediaType==='tv' ? ($detail['name']??'') : ($detail['title']??'');
        $origT   = $mediaType==='tv' ? ($detail['original_name']??$title) : ($detail['original_title']??$title);
        $dateStr = $mediaType==='tv' ? ($detail['first_air_date']??'') : ($detail['release_date']??'');
        $year    = $dateStr ? (int)substr($dateStr,0,4) : null;
        $director = null;
        foreach ($detail['credits']['crew']??[] as $c) { if ($c['job']==='Director'){$director=$c['name'];break;} }
        if (!$director && !empty($detail['created_by'])) $director = $detail['created_by'][0]['name']??null;
        $actors  = implode(', ', array_slice(array_column($detail['credits']['cast']??[],'name'),0,10)) ?: null;
        $country = translateProductionCountries($detail['production_countries']??[]) ?: null;
        $genres  = implode(', ', array_column($detail['genres']??[],'name'));

        if ($titleMismatch) {
            // Vorhandenen Eintrag mit korrekten TMDB-Daten überschreiben
            $db->prepare("UPDATE movies SET title=?,original_title=?,year=?,genre=?,poster_path=?,overview=?,director=?,actors=?,country=?,imdb_id=?,media_type=? WHERE id=?")
               ->execute([$title,$origT,$year,$genres?:null,$detail['poster_path']??null,
                          $detail['overview']??null,$director,$actors,$country,$detail['imdb_id']??null,$mediaType,$movieId]);
        } else {
            $db->prepare("INSERT INTO movies (title,original_title,year,genre,tmdb_id,poster_path,overview,director,actors,country,imdb_id,media_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$title,$origT,$year,$genres?:null,$tmdbId,$detail['poster_path']??null,
                          $detail['overview']??null,$director,$actors,$country,$detail['imdb_id']??null,$mediaType]);
            $movieId = (int)$db->lastInsertId();
        }
    }

    $ins2 = $db->prepare("INSERT INTO user_collection (user_id,movie_id,status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)");
    $ins2->execute([$userId,$movieId,$status]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'update_status') {
    header('Content-Type: application/json');
    $movieId = (int)($_POST['movie_id']??0);
    $status  = in_array($_POST['status']??'',['besitz','interesse']) ? $_POST['status'] : null;
    if (!$movieId||!$status){echo json_encode(['ok'=>false]);exit;}
    $db->prepare("UPDATE user_collection SET status=? WHERE user_id=? AND movie_id=?")->execute([$status,$userId,$movieId]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'remove_film') {
    header('Content-Type: application/json');
    $movieId = (int)($_POST['movie_id']??0);
    if (!$movieId){echo json_encode(['ok'=>false]);exit;}
    $db->prepare("DELETE FROM user_collection WHERE user_id=? AND movie_id=?")->execute([$userId,$movieId]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'get_seasons') {
    header('Content-Type: application/json');
    $movieId = (int)($_POST['movie_id'] ?? 0);
    $row = $db->prepare("SELECT tmdb_id FROM movies WHERE id=? AND COALESCE(media_type,'movie')='tv'");
    $row->execute([$movieId]);
    $movie = $row->fetch(PDO::FETCH_ASSOC);
    if (!$movie) { echo json_encode(['ok'=>false,'msg'=>'Nicht gefunden']); exit; }
    $data = samml_tmdb("tv/{$movie['tmdb_id']}", ['language'=>'de-DE']);
    if (!$data) { echo json_encode(['ok'=>false,'msg'=>'TMDB-Fehler']); exit; }
    $stmt = $db->prepare("SELECT season_num, COUNT(*) cnt FROM episode_watched WHERE user_id=? AND movie_id=? GROUP BY season_num");
    $stmt->execute([$userId, $movieId]);
    $wPerSeason = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $wPerSeason[(int)$r['season_num']] = (int)$r['cnt'];
    $seasons = [];
    foreach ($data['seasons'] ?? [] as $s) {
        if ((int)$s['season_number'] === 0) continue;
        $sn = (int)$s['season_number'];
        $seasons[] = ['season_number'=>$sn, 'name'=>$s['name']??("Staffel $sn"),
                      'episode_count'=>(int)$s['episode_count'], 'watched_count'=>$wPerSeason[$sn]??0];
    }
    echo json_encode(['ok'=>true,'seasons'=>$seasons]); exit;
}

if ($action === 'get_episodes') {
    header('Content-Type: application/json');
    $movieId   = (int)($_POST['movie_id']  ?? 0);
    $seasonNum = (int)($_POST['season_num'] ?? 0);
    $row = $db->prepare("SELECT tmdb_id FROM movies WHERE id=?");
    $row->execute([$movieId]);
    $movie = $row->fetch(PDO::FETCH_ASSOC);
    if (!$movie || !$seasonNum) { echo json_encode(['ok'=>false]); exit; }
    $data = samml_tmdb("tv/{$movie['tmdb_id']}/season/{$seasonNum}", ['language'=>'de-DE']);
    if (!$data) { echo json_encode(['ok'=>false,'msg'=>'TMDB-Fehler']); exit; }
    $stmt = $db->prepare("SELECT episode_num FROM episode_watched WHERE user_id=? AND movie_id=? AND season_num=?");
    $stmt->execute([$userId, $movieId, $seasonNum]);
    $watched = array_flip(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'episode_num'));
    $episodes = [];
    foreach ($data['episodes'] ?? [] as $e) {
        $en = (int)$e['episode_number'];
        $episodes[] = ['episode_number'=>$en, 'name'=>$e['name']??("Episode $en"),
                       'air_date'=>$e['air_date']??null, 'watched'=>isset($watched[$en])];
    }
    echo json_encode(['ok'=>true,'episodes'=>$episodes]); exit;
}

if ($action === 'set_episode_watched') {
    header('Content-Type: application/json');
    $movieId    = (int)($_POST['movie_id']    ?? 0);
    $seasonNum  = (int)($_POST['season_num']  ?? 0);
    $episodeNum = (int)($_POST['episode_num'] ?? 0);
    $watched    = (int)($_POST['watched']     ?? 0);
    if (!$movieId || !$seasonNum || !$episodeNum) { echo json_encode(['ok'=>false]); exit; }
    $chk = $db->prepare("SELECT 1 FROM user_collection WHERE user_id=? AND movie_id=?");
    $chk->execute([$userId, $movieId]);
    if (!$chk->fetchColumn()) { echo json_encode(['ok'=>false]); exit; }
    if ($watched) {
        $db->prepare("INSERT IGNORE INTO episode_watched (user_id,movie_id,season_num,episode_num) VALUES (?,?,?,?)")
           ->execute([$userId,$movieId,$seasonNum,$episodeNum]);
    } else {
        $db->prepare("DELETE FROM episode_watched WHERE user_id=? AND movie_id=? AND season_num=? AND episode_num=?")
           ->execute([$userId,$movieId,$seasonNum,$episodeNum]);
    }
    $total = (int)$db->prepare("SELECT COUNT(*) FROM episode_watched WHERE user_id=? AND movie_id=?")->execute([$userId,$movieId]);
    $s2 = $db->prepare("SELECT COUNT(*) FROM episode_watched WHERE user_id=? AND movie_id=?");
    $s2->execute([$userId,$movieId]); $total = (int)$s2->fetchColumn();
    echo json_encode(['ok'=>true,'watched_total'=>$total]); exit;
}

if ($action === 'set_season_watched') {
    header('Content-Type: application/json');
    $movieId   = (int)($_POST['movie_id']  ?? 0);
    $seasonNum = (int)($_POST['season_num'] ?? 0);
    $watched   = (int)($_POST['watched']   ?? 0);
    if (!$movieId || !$seasonNum) { echo json_encode(['ok'=>false]); exit; }
    $chk = $db->prepare("SELECT 1 FROM user_collection WHERE user_id=? AND movie_id=?");
    $chk->execute([$userId, $movieId]);
    if (!$chk->fetchColumn()) { echo json_encode(['ok'=>false]); exit; }
    if ($watched) {
        $rowM = $db->prepare("SELECT tmdb_id FROM movies WHERE id=?");
        $rowM->execute([$movieId]); $movie = $rowM->fetch(PDO::FETCH_ASSOC);
        $data = $movie ? samml_tmdb("tv/{$movie['tmdb_id']}/season/{$seasonNum}", ['language'=>'de-DE']) : null;
        $episodes = array_column($data['episodes'] ?? [], 'episode_number');
        $stmt = $db->prepare("INSERT IGNORE INTO episode_watched (user_id,movie_id,season_num,episode_num) VALUES (?,?,?,?)");
        foreach ($episodes as $en) $stmt->execute([$userId,$movieId,$seasonNum,(int)$en]);
        $epCount = count($episodes);
    } else {
        $db->prepare("DELETE FROM episode_watched WHERE user_id=? AND movie_id=? AND season_num=?")
           ->execute([$userId,$movieId,$seasonNum]);
        $epCount = 0;
    }
    $s2 = $db->prepare("SELECT COUNT(*) FROM episode_watched WHERE user_id=? AND movie_id=?");
    $s2->execute([$userId,$movieId]); $total = (int)$s2->fetchColumn();
    echo json_encode(['ok'=>true,'episode_count'=>$epCount,'watched_total'=>$total]); exit;
}

// ── Sammlung laden ────────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT m.id,m.title,m.year,m.poster_path,m.genre,m.director,m.tmdb_id,
    COALESCE(m.media_type,'movie') AS media_type,
    uc.status,uc.added_at
    FROM user_collection uc JOIN movies m ON m.id=uc.movie_id
    WHERE uc.user_id=? ORDER BY m.title ASC");
$stmt->execute([$userId]);
$allFilms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Gruppieren
$grouped = ['movie'=>[], 'tv'=>[]];
foreach ($allFilms as $f) {
    $type   = $f['media_type'] === 'tv' ? 'tv' : 'movie';
    $bucket = getBucket($f['title']);
    $grouped[$type][$bucket][] = $f;
}

$totalFilms     = count($allFilms);
$countBesitz    = count(array_filter($allFilms, fn($f)=>$f['status']==='besitz'));
$countInteresse = $totalFilms - $countBesitz;

// Watched episode counts per TV series
$tvMovieIds = [];
foreach ($grouped['tv'] as $films) foreach ($films as $f) $tvMovieIds[] = $f['id'];
$watchedCounts = [];
if ($tvMovieIds) {
    $ph = implode(',', array_fill(0, count($tvMovieIds), '?'));
    $stmtW = $db->prepare("SELECT movie_id, COUNT(*) cnt FROM episode_watched WHERE user_id=? AND movie_id IN ($ph) GROUP BY movie_id");
    $stmtW->execute(array_merge([$userId], $tvMovieIds));
    foreach ($stmtW->fetchAll(PDO::FETCH_ASSOC) as $r) $watchedCounts[(int)$r['movie_id']] = (int)$r['cnt'];
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.samml-page { background:#0d1f3c; min-height:100vh; padding-bottom:4rem; }

/* Hero */
.samml-hero {
    background: linear-gradient(135deg,#0a192f 0%,#1a3a5c 100%);
    border-bottom: 1px solid rgba(232,184,75,.15);
    padding: 2.5rem 0 2rem;
}
.samml-hero h1 { font-size:clamp(1.5rem,3.5vw,2rem); font-weight:900; color:#fff; margin:0 0 .35rem; }

/* Search */
.samml-search-wrap { position:relative; max-width:580px; }
.samml-search-input {
    background:rgba(255,255,255,.07); border:1.5px solid rgba(232,184,75,.3);
    border-radius:12px; color:#fff; padding:.65rem 1rem .65rem 2.7rem;
    font-size:.95rem; width:100%; outline:none; transition:border-color .2s,background .2s;
}
.samml-search-input::placeholder { color:rgba(255,255,255,.3); }
.samml-search-input:focus { border-color:#e8b84b; background:rgba(255,255,255,.1); }
.samml-search-icon { position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:rgba(232,184,75,.6); pointer-events:none; }
.samml-search-spinner { position:absolute; right:.85rem; top:50%; transform:translateY(-50%); display:none; }

/* Search results */
.samml-results {
    position:absolute; top:calc(100% + 6px); left:0; right:0;
    background:#112240; border:1.5px solid rgba(232,184,75,.25);
    border-radius:12px; z-index:1000; max-height:520px; overflow-y:auto;
    box-shadow:0 16px 48px rgba(0,0,0,.5); display:none;
}
.samml-result-item {
    display:flex; align-items:flex-start; gap:.65rem;
    padding:.65rem .9rem; border-bottom:1px solid rgba(255,255,255,.05); cursor:pointer;
    transition:background .15s;
}
.samml-result-item:last-child { border-bottom:none; }
.samml-result-item:hover { background:rgba(232,184,75,.06); }
.samml-result-poster { width:38px; height:57px; object-fit:cover; border-radius:4px; flex-shrink:0; background:#1e3a5f; }
.samml-result-info { flex:1; min-width:0; }
.samml-result-title { font-weight:700; color:#fff; font-size:.87rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.samml-result-sub   { color:rgba(255,255,255,.4); font-size:.72rem; margin-top:1px; }
.samml-result-desc  { color:rgba(255,255,255,.35); font-size:.7rem; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.add-btns { display:flex; gap:4px; flex-shrink:0; align-self:center; }
.add-btn {
    font-size:.65rem; font-weight:700; border-radius:20px; padding:3px 9px;
    border:none; cursor:pointer; transition:all .15s; white-space:nowrap;
}
.add-btn-besitz    { background:rgba(34,197,94,.85);  color:#fff; }
.add-btn-interesse { background:rgba(232,184,75,.85); color:#0a192f; }
.add-btn-besitz:hover    { background:#22c55e; }
.add-btn-interesse:hover { background:#e8b84b; }
.add-btn:disabled { opacity:.5; cursor:default; }
.result-cb-wrap { display:flex; align-items:center; flex-shrink:0; margin-right:2px; }
.result-cb { width:15px; height:15px; accent-color:#e8b84b; cursor:pointer; }
.samml-result-item.is-checked { background:rgba(232,184,75,.09); }
.bulk-bar {
    position:sticky; bottom:0; z-index:1;
    background:#0d1b2e; border-top:1.5px solid rgba(232,184,75,.3);
    padding:.45rem .9rem; display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;
    font-size:.75rem;
}
.bulk-count { color:#e8b84b; font-weight:700; flex:1; min-width:0; }
.bulk-add-btn {
    font-size:.65rem; font-weight:700; border-radius:20px; padding:3px 11px;
    border:1px solid; cursor:pointer;
}
.bulk-add-btn-besitz    { background:rgba(232,184,75,.15); color:#e8b84b; border-color:rgba(232,184,75,.4); }
.bulk-add-btn-besitz:hover    { background:#e8b84b; color:#0d1b2e; }
.bulk-add-btn-interesse { background:rgba(96,165,250,.1); color:#60a5fa; border-color:rgba(96,165,250,.35); }
.bulk-add-btn-interesse:hover { background:#60a5fa; color:#0d1b2e; }
.bulk-add-btn:disabled  { opacity:.45; cursor:default; }
.in-coll-badge {
    font-size:.65rem; font-weight:700; border-radius:20px; padding:3px 9px;
    background:rgba(255,255,255,.08); color:rgba(255,255,255,.45); border:1px solid rgba(255,255,255,.1);
    white-space:nowrap; flex-shrink:0; align-self:center;
}

/* Type tabs */
.samml-type-tabs { display:flex; gap:6px; border-bottom:1px solid rgba(255,255,255,.08); margin-bottom:1.2rem; }
.samml-type-tab {
    background:none; border:none; color:rgba(255,255,255,.4); font-weight:700; font-size:.9rem;
    padding:.55rem 1.2rem; border-bottom:2px solid transparent; margin-bottom:-1px;
    cursor:pointer; transition:color .15s,border-color .15s;
}
.samml-type-tab.active  { color:#e8b84b; border-bottom-color:#e8b84b; }
.samml-type-tab:hover   { color:rgba(232,184,75,.7); }
.samml-type-tab .tab-count {
    background:rgba(255,255,255,.08); border-radius:20px;
    padding:1px 7px; font-size:.7rem; margin-left:6px; font-weight:700;
    color:rgba(255,255,255,.5);
}
.samml-type-tab.active .tab-count { background:rgba(232,184,75,.15); color:#e8b84b; }

/* Stats + toolbar */
.samml-stats { display:flex; align-items:center; gap:.7rem; flex-wrap:wrap; }
.samml-stat-pill {
    background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
    border-radius:20px; padding:3px 11px; font-size:.75rem; color:rgba(255,255,255,.5);
}
.samml-stat-pill strong { color:#fff; }
.samml-view-toggle { display:flex; gap:3px; }
.samml-view-btn {
    background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1);
    color:rgba(255,255,255,.45); border-radius:8px; padding:5px 11px; font-size:.8rem; cursor:pointer; transition:all .15s;
}
.samml-view-btn.active { background:rgba(232,184,75,.15); border-color:rgba(232,184,75,.4); color:#e8b84b; }
.samml-filter { display:flex; gap:5px; }
.samml-filter-btn {
    background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
    color:rgba(255,255,255,.45); border-radius:20px; padding:3px 11px; font-size:.73rem; cursor:pointer; transition:all .15s;
}
.samml-filter-btn.active { background:rgba(232,184,75,.15); border-color:rgba(232,184,75,.3); color:#e8b84b; }

/* Letter nav */
.letter-nav {
    display:flex; flex-wrap:wrap; gap:3px; margin:1rem 0 .5rem;
    padding:.5rem .6rem; background:rgba(255,255,255,.03);
    border:1px solid rgba(255,255,255,.07); border-radius:10px;
}
.letter-nav-btn {
    background:none; border:none; color:rgba(255,255,255,.55); font-size:.72rem; font-weight:700;
    padding:3px 6px; border-radius:5px; cursor:pointer; transition:all .15s; min-width:24px; text-align:center;
}
.letter-nav-btn:hover  { background:rgba(232,184,75,.1); color:#e8b84b; }
.letter-nav-btn.has    { color:#fff; }
.letter-nav-btn.empty  { color:rgba(255,255,255,.2); cursor:default; pointer-events:none; }

/* Sections */
.samml-section-titles { color:rgba(255,255,255,.32); font-size:.7rem; font-weight:400; margin-left:.6rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:60vw; vertical-align:middle; }
.samml-section { margin-bottom:2rem; }
.samml-section-hdr {
    display:flex; align-items:center; gap:.6rem; margin-bottom:.7rem;
    padding-bottom:.4rem; border-bottom:1px solid rgba(232,184,75,.15);
}
.samml-section-letter {
    font-size:1.4rem; font-weight:900; color:#e8b84b; line-height:1; min-width:32px;
}
.samml-section-count {
    font-size:.7rem; color:rgba(255,255,255,.3); font-weight:600; white-space:nowrap;
}

/* Table */
.samml-table { width:100%; border-collapse:collapse; }
.samml-table th { padding:.45rem .7rem; font-size:.67rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:rgba(232,184,75,.6); text-align:left; }
.samml-table td { padding:.5rem .7rem; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:middle; font-size:.84rem; color:#fff; }
.samml-table tr:hover td { background:rgba(255,255,255,.025); }
.samml-tbl-group-hdr {
    padding: 1.1rem .7rem .4rem !important;
    border-bottom: 1px solid rgba(232,184,75,.15) !important;
    background: transparent !important;
}
.samml-tbl-group-hdr-inner {
    display:flex; align-items:baseline; gap:.5rem; flex-wrap:nowrap;
}
.samml-table tr:hover .samml-tbl-group-hdr { background: transparent !important; }
.samml-table .poster-mini { width:28px; height:42px; object-fit:cover; border-radius:3px; }
.status-pill {
    display:inline-flex; align-items:center; gap:4px;
    border-radius:20px; padding:3px 10px; font-size:.7rem; font-weight:700; cursor:pointer; border:none; transition:all .15s;
}
.status-pill.besitz    { background:rgba(34,197,94,.15);  color:#22c55e; border:1px solid rgba(34,197,94,.3); }
.status-pill.interesse { background:rgba(232,184,75,.12); color:#e8b84b; border:1px solid rgba(232,184,75,.3); }
.btn-remove { background:none; border:none; color:rgba(255,100,100,.4); cursor:pointer; font-size:.85rem; padding:0 2px; transition:color .15s; }
.btn-remove:hover { color:#f87171; }

/* Grid */
.samml-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:.85rem; }
.samml-card { position:relative; border-radius:9px; overflow:hidden; aspect-ratio:2/3; background:#1a3a5c; transition:transform .2s,box-shadow .2s; }
.samml-card:hover { transform:translateY(-3px); box-shadow:0 10px 28px rgba(0,0,0,.5); }
.samml-card img { width:100%; height:100%; object-fit:cover; display:block; }
.samml-card-overlay {
    position:absolute; inset:0;
    background:linear-gradient(to top,rgba(0,0,0,.85) 0%,rgba(0,0,0,.05) 55%,transparent 100%);
    display:flex; flex-direction:column; justify-content:flex-end; padding:.55rem;
    opacity:0; transition:opacity .2s;
}
.samml-card:hover .samml-card-overlay { opacity:1; }
.samml-card-title { font-size:.72rem; font-weight:700; color:#fff; line-height:1.2; }
.samml-card-year  { font-size:.62rem; color:rgba(255,255,255,.5); margin-top:2px; }
.samml-card-acts  { display:flex; gap:4px; margin-top:.35rem; flex-wrap:wrap; }
.samml-card-status-pill {
    position:absolute; top:.35rem; left:.35rem; font-size:.58rem; font-weight:700; border-radius:20px; padding:2px 6px;
}
.samml-card-status-pill.besitz    { background:rgba(34,197,94,.85); color:#fff; }
.samml-card-status-pill.interesse { background:rgba(232,184,75,.85); color:#0a192f; }
.samml-card-type-pill {
    position:absolute; top:.35rem; right:.35rem; font-size:.58rem; font-weight:700;
    background:rgba(167,139,250,.8); color:#fff; border-radius:4px; padding:1px 5px;
}

/* Empty */
.samml-empty { text-align:center; padding:3.5rem 1rem; color:rgba(255,255,255,.25); }
.samml-empty i { font-size:2.5rem; display:block; margin-bottom:.8rem; }

/* Toast */
#samml-toast {
    position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999;
    background:#112240; border:1px solid rgba(232,184,75,.3);
    color:#fff; border-radius:10px; padding:.55rem 1.1rem; font-size:.82rem; font-weight:600;
    opacity:0; transform:translateY(6px); transition:opacity .2s,transform .2s; pointer-events:none;
}
#samml-toast.show { opacity:1; transform:translateY(0); }

/* Series expand button */
.series-expand-btn {
    background:none; border:none; color:rgba(167,139,250,.5); padding:0 4px 0 0;
    font-size:.72rem; cursor:pointer; transition:color .15s; vertical-align:middle; line-height:1;
}
.series-expand-btn:hover { color:#a78bfa; }

/* Series episode panel */
.series-panel-row td { padding:0 !important; }
.series-panel {
    padding:.5rem .5rem .55rem 1.2rem;
    background:rgba(10,20,40,.5);
    border-left:3px solid rgba(167,139,250,.2);
    border-bottom:1px solid rgba(255,255,255,.04);
}
.seasons-list { display:flex; flex-direction:column; gap:0; }
.season-item { border-bottom:1px solid rgba(255,255,255,.04); }
.season-item:last-child { border-bottom:none; }
.season-row { display:flex; align-items:center; gap:.5rem; padding:.3rem 0; }
.season-expand-btn {
    background:none; border:none; color:rgba(255,255,255,.25); padding:0 2px;
    font-size:.68rem; cursor:pointer; transition:color .15s; line-height:1; flex-shrink:0;
}
.season-expand-btn:hover { color:#e8b84b; }
.season-name { font-size:.78rem; font-weight:600; color:#fff; min-width:72px; }
.season-progress-wrap { flex:1; height:3px; background:rgba(255,255,255,.1); border-radius:2px; overflow:hidden; max-width:100px; }
.season-progress-bar { height:100%; background:#e8b84b; border-radius:2px; transition:width .3s; }
.season-progress-text { font-size:.67rem; color:rgba(255,255,255,.3); white-space:nowrap; min-width:38px; }
.season-cb-label { display:flex; align-items:center; gap:3px; font-size:.68rem; color:rgba(255,255,255,.35); cursor:pointer; white-space:nowrap; margin-left:auto; }
.season-cb-label input { accent-color:#e8b84b; cursor:pointer; }

/* Episodes list */
.episodes-list { padding:.1rem 0 .3rem .9rem; border-left:1px solid rgba(167,139,250,.12); margin:.1rem 0 .2rem .5rem; }
.ep-row { display:flex; align-items:center; gap:.4rem; padding:.13rem 0; }
.ep-cb { accent-color:#e8b84b; width:13px; height:13px; cursor:pointer; flex-shrink:0; }
.ep-num { color:rgba(255,255,255,.2); min-width:20px; font-size:.67rem; text-align:right; flex-shrink:0; }
.ep-name { font-size:.75rem; color:rgba(255,255,255,.7); flex:1; }
.ep-name.watched { color:rgba(255,255,255,.28); text-decoration:line-through; }
.ep-year { color:rgba(255,255,255,.2); font-size:.64rem; flex-shrink:0; }

/* Watched badge on series row */
.ep-watched-badge {
    font-size:.57rem; font-weight:700; border-radius:20px; padding:1px 6px;
    background:rgba(232,184,75,.12); color:#e8b84b; border:1px solid rgba(232,184,75,.22);
    margin-left:5px; vertical-align:middle;
}

@media(max-width:768px) {
    .samml-grid { grid-template-columns:repeat(auto-fill,minmax(100px,1fr)); gap:.55rem; }
    .col-genre { display:none; }
    .letter-nav { gap:2px; }
    .letter-nav-btn { padding:2px 4px; font-size:.65rem; min-width:20px; }
}
</style>

<div id="samml-toast"></div>

<main class="samml-page">

<!-- Hero -->
<div class="samml-hero">
<div class="container">
    <h1><i class="bi bi-collection-play me-2" style="color:#e8b84b;"></i>Meine Sammlung</h1>
    <p style="color:rgba(255,255,255,.45);margin:.25rem 0 1.3rem;font-size:.87rem;">
        Filme &amp; Serien verwalten – suchen, hinzufügen, Speicherort hinterlegen.
    </p>
    <div class="samml-search-wrap">
        <i class="bi bi-search samml-search-icon"></i>
        <input type="search" id="searchBox" class="samml-search-input"
               placeholder="Film oder Serie suchen…" autocomplete="off">
        <div class="spinner-border spinner-border-sm text-warning samml-search-spinner" id="searchSpinner"></div>
        <div class="samml-results" id="searchResults"></div>
    </div>
</div>
</div>

<!-- Hauptbereich -->
<div class="container mt-4">

    <!-- Typ-Tabs -->
    <div class="samml-type-tabs">
        <?php
        $movieCount = array_sum(array_map('count', $grouped['movie']));
        $tvCount    = array_sum(array_map('count', $grouped['tv']));
        ?>
        <button class="samml-type-tab active" data-type="movie" onclick="switchType('movie',this)">
            <i class="bi bi-camera-film me-1"></i>Filme
            <span class="tab-count"><?= $movieCount ?></span>
        </button>
        <button class="samml-type-tab" data-type="tv" onclick="switchType('tv',this)">
            <i class="bi bi-tv me-1"></i>Serien
            <span class="tab-count"><?= $tvCount ?></span>
        </button>
    </div>

    <!-- Toolbar -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
        <div class="samml-stats">
            <span class="samml-stat-pill"><strong id="statTotal"><?= $totalFilms ?></strong> gesamt</span>
            <span class="samml-stat-pill">
                <i class="bi bi-check-circle-fill me-1" style="color:#22c55e;font-size:.72em;"></i>
                <strong id="statBesitz"><?= $countBesitz ?></strong> Besitz
            </span>
            <span class="samml-stat-pill">
                <i class="bi bi-bookmark-fill me-1" style="color:#e8b84b;font-size:.72em;"></i>
                <strong id="statInteresse"><?= $countInteresse ?></strong> Wunschliste
            </span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="samml-filter">
                <button class="samml-filter-btn active" data-filter="all">Alle</button>
                <button class="samml-filter-btn" data-filter="besitz">Besitz</button>
                <button class="samml-filter-btn" data-filter="interesse">Wunschliste</button>
            </div>
            <div class="samml-view-toggle ms-1">
                <button class="samml-view-btn" id="btnTable" title="Tabelle"><i class="bi bi-table"></i></button>
                <button class="samml-view-btn" id="btnGrid"  title="Regal"><i class="bi bi-grid-3x3-gap-fill"></i></button>
            </div>
        </div>
    </div>

    <!-- Letter-Nav -->
    <?php
    $BUCKET_ORDER_ALL = ['#','0','1','2','3','4','5','6','7','8','9','A','B','C','D','Das','Der','Die','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','The','U','V','W','X','Y','Z'];
    $bucketsMovie = array_keys($grouped['movie']);
    $bucketsTv    = array_keys($grouped['tv']);
    ?>
    <div class="letter-nav" id="letterNav">
        <?php foreach ($BUCKET_ORDER_ALL as $b):
            $hasM = in_array($b, $bucketsMovie);
            $hasT = in_array($b, $bucketsTv);
        ?>
        <button class="letter-nav-btn <?= $hasM||$hasT ? 'has' : 'empty' ?>"
                data-bucket="<?= e($b) ?>"
                data-has-movie="<?= $hasM?'1':'0' ?>"
                data-has-tv="<?= $hasT?'1':'0' ?>"
                onclick="scrollToBucket('<?= e($b) ?>')">
            <?= e($b) ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ── Tabellenansicht ── -->
    <div id="viewTable">
    <?php foreach (['movie','tv'] as $type):
        $typeLabel = $type === 'movie' ? 'Filme' : 'Serien';
    ?>
    <div class="samml-type-block" data-type="<?= $type ?>" <?= $type === 'tv' ? 'style="display:none"' : '' ?>>
        <?php if (empty($grouped[$type])): ?>
        <div class="samml-empty">
            <i class="bi bi-<?= $type==='movie'?'camera-film':'tv' ?>"></i>
            Noch keine <?= $typeLabel ?> in deiner Sammlung.<br>
            <small>Suche oben und füge <?= $typeLabel ?> hinzu.</small>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="samml-table" style="table-layout:fixed;">
            <thead>
                <tr>
                    <th style="width:36px;"></th>
                    <th>Titel</th>
                    <th style="width:52px;">Jahr</th>
                    <th class="col-genre" style="width:130px;">Genre</th>
                    <th style="width:115px;">Status</th>
                    <th style="width:30px;"></th>
                </tr>
            </thead>
            <?php foreach ($BUCKET_ORDER_ALL as $bucket):
                if (empty($grouped[$type][$bucket])) continue;
                $bucketFilms = $grouped[$type][$bucket];
                $cnt = count($bucketFilms);
            ?>
            <tbody class="samml-section" data-bucket="<?= e($bucket) ?>" id="bucket-<?= $type ?>-<?= e($bucket) ?>">
                <tr id="letter-<?= $type ?>-<?= e($bucket) ?>">
                    <td colspan="6" class="samml-tbl-group-hdr">
                        <div class="samml-tbl-group-hdr-inner">
                            <span class="samml-section-letter"><?= e($bucket) ?></span>
                            <span class="samml-section-count"><?= $cnt ?> Titel</span>
                        </div>
                    </td>
                </tr>
                <?php foreach ($bucketFilms as $f):
                    $poster = $f['poster_path']
                        ? 'https://image.tmdb.org/t/p/w92' . $f['poster_path']
                        : 'https://placehold.co/28x42/1e3a5f/e8b84b?text=?';
                ?>
                <tr data-id="<?= $f['id'] ?>" data-status="<?= e($f['status']) ?>" data-type="<?= e($type) ?>">
                    <td><a href="/film.php?id=<?= $f['id'] ?>">
                        <img src="<?= e($poster) ?>" class="poster-mini" onerror="this.src='https://placehold.co/28x42/1e3a5f/e8b84b?text=?'">
                    </a></td>
                    <td>
                        <?php if ($type==='tv'): ?>
                        <button class="series-expand-btn" id="expand-btn-<?= $f['id'] ?>" onclick="toggleSeries(<?= $f['id'] ?>,<?= (int)$f['tmdb_id'] ?>)" title="Staffeln &amp; Episoden">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <?php endif; ?>
                        <a href="/film.php?id=<?= $f['id'] ?>" style="color:#fff;text-decoration:none;font-weight:600;">
                            <?= e($f['title']) ?>
                        </a>
                        <?php if ($type==='tv'): ?>
                        <span style="font-size:.6rem;color:#a78bfa;background:rgba(167,139,250,.12);border:1px solid rgba(167,139,250,.25);border-radius:4px;padding:1px 5px;margin-left:5px;">Serie</span>
                        <?php $wc = $watchedCounts[$f['id']] ?? 0; ?>
                        <span class="ep-watched-badge" id="ep-badge-<?= $f['id'] ?>" data-count="<?= $wc ?>"<?= $wc ? '' : ' style="display:none"' ?>><?= $wc ?> gesehen</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:rgba(255,255,255,.45);"><?= $f['year'] ? (int)$f['year'] : '–' ?></td>
                    <td class="col-genre" style="color:rgba(255,255,255,.35);font-size:.73rem;"><?= e(mb_substr($f['genre']??'',0,20)) ?></td>
                    <td>
                        <button class="status-pill <?= $f['status'] ?>" onclick="toggleStatus(this,<?= $f['id'] ?>)">
                            <?php if ($f['status']==='besitz'): ?>
                                <i class="bi bi-check-circle-fill"></i> Besitz
                            <?php else: ?>
                                <i class="bi bi-bookmark-fill"></i> Wunschliste
                            <?php endif; ?>
                        </button>
                    </td>
                    <td>
                        <button class="btn-remove" onclick="removeFilm(this,<?= $f['id'] ?>)" title="Entfernen">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </td>
                </tr>
                <?php if ($type==='tv'): ?>
                <tr class="series-panel-row" id="series-panel-<?= $f['id'] ?>" data-parent-id="<?= $f['id'] ?>" style="display:none;">
                    <td colspan="6" class="p-0">
                        <div class="series-panel" id="series-panel-inner-<?= $f['id'] ?>"></div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- ── Regalansicht ── -->
    <div id="viewGrid" style="display:none;">
    <?php foreach (['movie','tv'] as $type): ?>
    <div class="samml-type-block" data-type="<?= $type ?>" <?= $type === 'tv' ? 'style="display:none"' : '' ?>>
        <?php if (empty($grouped[$type])): ?>
        <div class="samml-empty">
            <i class="bi bi-<?= $type==='movie'?'camera-film':'tv' ?>"></i>
            Noch keine <?= $type==='movie'?'Filme':'Serien' ?> in deiner Sammlung.
        </div>
        <?php else:
            foreach ($BUCKET_ORDER_ALL as $bucket):
                if (empty($grouped[$type][$bucket])) continue;
        ?>
        <div class="samml-section" data-bucket="<?= e($bucket) ?>">
            <div class="samml-section-hdr">
                <span class="samml-section-letter"><?= e($bucket) ?></span>
                <span class="samml-section-count"><?= count($grouped[$type][$bucket]) ?> Titel</span>
            </div>
            <div class="samml-grid">
            <?php foreach ($grouped[$type][$bucket] as $f):
                $poster = $f['poster_path']
                    ? 'https://image.tmdb.org/t/p/w300' . $f['poster_path']
                    : 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?';
            ?>
            <div class="samml-card" data-id="<?= $f['id'] ?>" data-status="<?= e($f['status']) ?>" data-type="<?= e($type) ?>">
                <img src="<?= e($poster) ?>" alt="<?= e($f['title']) ?>"
                     onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                <span class="samml-card-status-pill <?= $f['status'] ?>"><?= $f['status']==='besitz'?'Besitz':'Wunschliste' ?></span>
                <?php if ($type==='tv'): ?>
                <span class="samml-card-type-pill">Serie</span>
                <?php endif; ?>
                <div class="samml-card-overlay">
                    <div class="samml-card-title"><?= e($f['title']) ?></div>
                    <div class="samml-card-year"><?= $f['year'] ? (int)$f['year'] : '' ?></div>
                    <div class="samml-card-acts">
                        <a href="/film.php?id=<?= $f['id'] ?>"
                           style="font-size:.65rem;color:#e8b84b;text-decoration:none;background:rgba(232,184,75,.15);border:1px solid rgba(232,184,75,.3);border-radius:4px;padding:2px 7px;">
                            <i class="bi bi-info-circle me-1"></i>Details
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

</div><!-- /container -->
</main>


<script>
const BASE = location.href.split('?')[0];
function post(d){ return fetch(BASE,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d)}).then(r=>r.json()); }
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function toast(msg,ok=true){
    const el=document.getElementById('samml-toast');
    el.textContent=msg; el.style.borderColor=ok?'rgba(34,197,94,.4)':'rgba(248,113,113,.4)';
    el.classList.add('show'); clearTimeout(el._t); el._t=setTimeout(()=>el.classList.remove('show'),2200);
}

// ── Suche ─────────────────────────────────────────────────────────────────────
const searchBox=document.getElementById('searchBox'),
      searchRes=document.getElementById('searchResults'),
      spinner  =document.getElementById('searchSpinner');
let timer;
searchBox.addEventListener('input',()=>{ clearTimeout(timer); const q=searchBox.value.trim(); if(q.length<1){searchRes.style.display='none';return;} timer=setTimeout(()=>doSearch(q),380); });
searchBox.addEventListener('keydown',e=>{ if(e.key==='Escape'){searchRes.style.display='none';} });
document.addEventListener('click',e=>{ if(!searchBox.contains(e.target)&&!searchRes.contains(e.target)) searchRes.style.display='none'; });

async function doSearch(q){
    spinner.style.display='block';
    const d=await post({action:'tmdb_search',query:q});
    spinner.style.display='none';
    if(!d.results?.length){ searchRes.innerHTML='<div style="padding:.7rem 1rem;color:rgba(255,255,255,.3);font-size:.83rem;">Keine Ergebnisse</div>'; searchRes.style.display='block'; return; }
    searchRes.innerHTML=d.results.map(m=>{
        const img=m.poster_path?`https://image.tmdb.org/t/p/w92${m.poster_path}`:'https://placehold.co/38x57/1e3a5f/e8b84b?text=?';
        const tv=m.media_type==='tv'?'<span style="font-size:.6rem;color:#a78bfa;background:rgba(167,139,250,.15);border:1px solid rgba(167,139,250,.25);border-radius:4px;padding:1px 5px;margin-left:4px;">Serie</span>':'';
        const titleEsc=esc(m.title);
        const titleAttr=m.title.replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        if(m.in_collection){
            return `<div class="samml-result-item">
                <img class="samml-result-poster" src="${img}" onerror="this.src='https://placehold.co/38x57/1e3a5f/e8b84b?text=?'">
                <div class="samml-result-info">
                    <div class="samml-result-title">${titleEsc}${tv}</div>
                    <div class="samml-result-sub">${m.year||''}</div>
                    <div class="samml-result-desc">${esc(m.overview)}</div>
                </div><span class="in-coll-badge">${m.in_collection==='besitz'?'Besitz':'Wunschliste'}</span></div>`;
        }
        return `<div class="samml-result-item" data-tmdb="${m.tmdb_id}" data-media="${m.media_type}" data-title="${titleAttr}">
            <label class="result-cb-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="result-cb" onchange="updateBulkBar()"></label>
            <img class="samml-result-poster" src="${img}" onerror="this.src='https://placehold.co/38x57/1e3a5f/e8b84b?text=?'">
            <div class="samml-result-info">
                <div class="samml-result-title">${titleEsc}${tv}</div>
                <div class="samml-result-sub">${m.year||''}</div>
                <div class="samml-result-desc">${esc(m.overview)}</div>
            </div>
            <div class="add-btns">
                <button class="add-btn add-btn-besitz"    onclick="addFilm(${m.tmdb_id},'${m.media_type}','besitz',this,'${titleAttr.replace(/'/g,"\\'")}')">+ Besitz</button>
                <button class="add-btn add-btn-interesse" onclick="addFilm(${m.tmdb_id},'${m.media_type}','interesse',this,'${titleAttr.replace(/'/g,"\\'")}')">+ Wunschliste</button>
            </div></div>`;
    }).join('');
    searchRes.insertAdjacentHTML('beforeend',`<div class="bulk-bar" id="bulkBar" style="display:none">
        <span class="bulk-count" id="bulkCount"></span>
        <button class="bulk-add-btn bulk-add-btn-besitz"    id="bulkBtnB" onclick="addAllSelected('besitz')">Alle → Besitz</button>
        <button class="bulk-add-btn bulk-add-btn-interesse" id="bulkBtnI" onclick="addAllSelected('interesse')">Alle → Wunschliste</button>
    </div>`);
    searchRes.style.display='block';
}

async function addFilm(tmdbId,media,status,btn,title=''){
    const item=btn.closest('.samml-result-item');
    const btns=btn.closest('.add-btns'); if(btns) btns.querySelectorAll('button').forEach(b=>{b.disabled=true;});
    const d=await post({action:'add_film',tmdb_id:tmdbId,media_type:media,status,title});
    if(!d.ok){ toast('Fehler'+(d.msg?': '+d.msg:''),false); if(btns) btns.querySelectorAll('button').forEach(b=>b.disabled=false); return; }
    toast('Zur Sammlung hinzugefügt!');
    if(item){ const cb=item.querySelector('.result-cb'); if(cb){ cb.checked=false; updateBulkBar(); } }
    searchRes.style.display='none'; searchBox.value='';
    sessionStorage.setItem('samml_focus_search','1');
    setTimeout(()=>location.reload(),600);
}

function updateBulkBar(){
    const checked=searchRes.querySelectorAll('.result-cb:checked');
    searchRes.querySelectorAll('.samml-result-item[data-tmdb]').forEach(el=>{
        el.classList.toggle('is-checked',el.querySelector('.result-cb')?.checked||false);
    });
    const bar=document.getElementById('bulkBar');
    if(!bar) return;
    if(checked.length===0){ bar.style.display='none'; return; }
    bar.style.display='flex';
    document.getElementById('bulkCount').textContent=checked.length+' ausgewählt';
}

async function addAllSelected(status){
    const items=[...searchRes.querySelectorAll('.samml-result-item[data-tmdb]')].filter(el=>el.querySelector('.result-cb')?.checked);
    if(!items.length) return;
    const bb=document.getElementById('bulkBtnB'), bi=document.getElementById('bulkBtnI');
    if(bb) bb.disabled=true; if(bi) bi.disabled=true;
    let added=0, failed=0;
    for(const el of items){
        el.querySelectorAll('.result-cb,.add-btn').forEach(b=>b.disabled=true);
        const d=await post({action:'add_film',tmdb_id:el.dataset.tmdb,media_type:el.dataset.media,status,title:el.dataset.title});
        if(d.ok){ added++; el.style.opacity='.45'; } else { failed++; }
    }
    toast(added+' Film'+(added===1?'':'e')+' hinzugefügt'+(failed?', '+failed+' Fehler':''), failed===0);
    sessionStorage.setItem('samml_focus_search','1');
    setTimeout(()=>location.reload(),700);
}

// ── Typ-Tabs ──────────────────────────────────────────────────────────────────
let currentType='movie', currentFilter='all';

function switchType(type,btn){
    currentType=type;
    document.querySelectorAll('.samml-type-tab').forEach(b=>b.classList.toggle('active',b.dataset.type===type));
    // Blöcke ein/ausblenden
    document.querySelectorAll('.samml-type-block').forEach(b=>b.style.display=b.dataset.type===type?'':'none');
    // Letter-nav aktualisieren
    document.querySelectorAll('.letter-nav-btn').forEach(btn=>{
        const has=btn.dataset[type==='movie'?'hasMovie':'hasTv']==='1';
        btn.className='letter-nav-btn '+(has?'has':'empty');
    });
    applyFilter(currentFilter);
}

// ── Filter ────────────────────────────────────────────────────────────────────
function applyFilter(filter){
    currentFilter=filter;
    document.querySelectorAll('[data-filter]').forEach(b=>b.classList.toggle('active',b.dataset.filter===filter));
    // Innerhalb aktiver Blöcke Zeilen/Karten filtern
    document.querySelectorAll(`.samml-type-block[data-type="${currentType}"] [data-id]`).forEach(el=>{
        el.style.display=(filter==='all'||el.dataset.status===filter)?'':'none';
    });
    // Panel-Zeilen an Elternzeile anpassen
    document.querySelectorAll(`.samml-type-block[data-type="${currentType}"] .series-panel-row`).forEach(panelRow=>{
        const parentRow=document.querySelector(`[data-id="${panelRow.dataset.parentId}"]`);
        if(parentRow&&parentRow.style.display==='none') panelRow.style.display='none';
    });
    // Leere Sektionen verstecken
    document.querySelectorAll(`.samml-type-block[data-type="${currentType}"] .samml-section`).forEach(sec=>{
        const visible=[...sec.querySelectorAll('[data-id]')].some(el=>el.style.display!=='none');
        sec.style.display=visible?'':'none';
    });
}
document.querySelectorAll('.samml-filter-btn').forEach(b=>b.addEventListener('click',()=>applyFilter(b.dataset.filter)));

// ── Ansicht ───────────────────────────────────────────────────────────────────
const viewTable=document.getElementById('viewTable'), viewGrid=document.getElementById('viewGrid');
const btnTable=document.getElementById('btnTable'),   btnGrid=document.getElementById('btnGrid');
function setView(mode){
    viewTable.style.display=mode==='table'?'':'none';
    viewGrid.style.display =mode==='grid' ?'':'none';
    btnTable.classList.toggle('active',mode==='table');
    btnGrid.classList.toggle('active',mode==='grid');
    localStorage.setItem('samml_view',mode);
}
btnTable.addEventListener('click',()=>setView('table'));
btnGrid.addEventListener('click', ()=>setView('grid'));
setView(localStorage.getItem('samml_view')||'table');

// ── Buchstaben-Navigation ─────────────────────────────────────────────────────
function scrollToBucket(b){
    const target=document.getElementById(`letter-${currentType}-${b}`);
    if(target) target.scrollIntoView({behavior:'smooth',block:'start'});
}

// ── Status ────────────────────────────────────────────────────────────────────
async function toggleStatus(btn,movieId){
    const el=btn.closest('[data-id]'), cur=el.dataset.status, next=cur==='besitz'?'interesse':'besitz';
    const d=await post({action:'update_status',movie_id:movieId,status:next});
    if(!d.ok){toast('Fehler',false);return;}
    el.dataset.status=next;
    btn.className=`status-pill ${next}`;
    btn.innerHTML=next==='besitz'?'<i class="bi bi-check-circle-fill"></i> Besitz':'<i class="bi bi-bookmark-fill"></i> Wunschliste';
    // Grid-Karte
    document.querySelectorAll(`[data-id="${movieId}"] .samml-card-status-pill`).forEach(p=>{
        p.className=`samml-card-status-pill ${next}`; p.textContent=next==='besitz'?'Besitz':'Wunschliste';
    });
    // Stat-Counter
    const d2=next==='besitz'?1:-1;
    adjStat('statBesitz',d2); adjStat('statInteresse',-d2);
    applyFilter(currentFilter);
    toast(next==='besitz'?'Als Besitz markiert':'Auf Wunschliste');
}

// ── Entfernen ────────────────────────────────────────────────────────────────
async function removeFilm(btn,movieId){
    if(!confirm('Film aus der Sammlung entfernen?')) return;
    const d=await post({action:'remove_film',movie_id:movieId});
    if(!d.ok){toast('Fehler',false);return;}
    const was=btn.closest('[data-id]')?.dataset.status;
    document.querySelectorAll(`[data-id="${movieId}"]`).forEach(el=>el.remove());
    adjStat('statTotal',-1);
    if(was==='besitz')    adjStat('statBesitz',-1);
    if(was==='interesse') adjStat('statInteresse',-1);
    // Tab-Zähler nicht live aktualisieren – kein Reload nötig
    toast('Entfernt');
    applyFilter(currentFilter);
}

function adjStat(id,d){ const e=document.getElementById(id); if(e) e.textContent=Math.max(0,(parseInt(e.textContent)||0)+d); }

// ── Serien: Staffeln & Episoden gesehen ───────────────────────────────────────
async function toggleSeries(movieId, tmdbId){
    const panelRow=document.getElementById(`series-panel-${movieId}`);
    const icon=document.getElementById(`expand-btn-${movieId}`)?.querySelector('i');
    if(panelRow.style.display!=='none'){
        panelRow.style.display='none';
        icon?.classList.replace('bi-chevron-down','bi-chevron-right');
        return;
    }
    panelRow.style.display='';
    icon?.classList.replace('bi-chevron-right','bi-chevron-down');
    const inner=document.getElementById(`series-panel-inner-${movieId}`);
    if(inner.dataset.loaded) return;
    inner.innerHTML='<div style="padding:.55rem 1rem;color:rgba(255,255,255,.3);font-size:.78rem;"><span class="spinner-border spinner-border-sm me-2" style="width:12px;height:12px;border-width:2px;vertical-align:middle;"></span>Lade Staffeln…</div>';
    const d=await post({action:'get_seasons',movie_id:movieId});
    if(!d.ok){inner.innerHTML='<div style="padding:.55rem 1rem;color:#f87171;font-size:.78rem;">Fehler beim Laden</div>';return;}
    inner.dataset.loaded='1';
    _renderSeasons(movieId,d.seasons,inner);
}

function _renderSeasons(movieId,seasons,container){
    if(!seasons||!seasons.length){container.innerHTML='<div style="padding:.5rem 1rem;color:rgba(255,255,255,.3);font-size:.78rem;">Keine Staffeln gefunden</div>';return;}
    let html='<div class="seasons-list">';
    for(const s of seasons){
        const pct=s.episode_count?Math.round(s.watched_count/s.episode_count*100):0;
        const allW=s.episode_count>0&&s.watched_count>=s.episode_count;
        html+=`<div class="season-item" id="si-${movieId}-${s.season_number}" data-movie="${movieId}" data-season="${s.season_number}" data-ep-count="${s.episode_count}" data-watched="${s.watched_count}">
            <div class="season-row">
                <button class="season-expand-btn" onclick="toggleSeason(${movieId},${s.season_number})"><i class="bi bi-chevron-right"></i></button>
                <span class="season-name">${esc(s.name)}</span>
                <div class="season-progress-wrap"><div class="season-progress-bar" style="width:${pct}%"></div></div>
                <span class="season-progress-text">${s.watched_count}/${s.episode_count}</span>
                <label class="season-cb-label"><input type="checkbox" class="season-cb" ${allW?'checked':''} onchange="setSeasonWatched(${movieId},${s.season_number},this.checked,this)"> Alle</label>
            </div>
            <div class="episodes-list" id="epl-${movieId}-${s.season_number}" style="display:none;"></div>
        </div>`;
    }
    html+='</div>';
    container.innerHTML=html;
}

async function toggleSeason(movieId,seasonNum){
    const epl=document.getElementById(`epl-${movieId}-${seasonNum}`);
    const icon=document.querySelector(`#si-${movieId}-${seasonNum} .season-expand-btn i`);
    if(epl.style.display!=='none'){epl.style.display='none';icon?.classList.replace('bi-chevron-down','bi-chevron-right');return;}
    epl.style.display='';
    icon?.classList.replace('bi-chevron-right','bi-chevron-down');
    if(epl.dataset.loaded) return;
    epl.innerHTML='<div style="padding:.2rem 0 .2rem .5rem;color:rgba(255,255,255,.25);font-size:.72rem;"><span class="spinner-border spinner-border-sm me-1" style="width:10px;height:10px;border-width:1px;vertical-align:middle;"></span>Lade Episoden…</div>';
    const d=await post({action:'get_episodes',movie_id:movieId,season_num:seasonNum});
    if(!d.ok){epl.innerHTML='<div style="padding:.2rem 0 .2rem .5rem;color:#f87171;font-size:.72rem;">Fehler</div>';return;}
    epl.dataset.loaded='1';
    _renderEpisodes(movieId,seasonNum,d.episodes,epl);
}

function _renderEpisodes(movieId,seasonNum,episodes,container){
    if(!episodes||!episodes.length){container.innerHTML='<div style="padding:.2rem 0 .2rem .5rem;color:rgba(255,255,255,.25);font-size:.72rem;">Keine Episoden</div>';return;}
    let html='';
    for(const e of episodes){
        const yr=e.air_date?e.air_date.substring(0,4):'';
        html+=`<div class="ep-row" id="er-${movieId}-${seasonNum}-${e.episode_number}">
            <input type="checkbox" class="ep-cb" ${e.watched?'checked':''} onchange="setEpisodeWatched(${movieId},${seasonNum},${e.episode_number},this.checked,this)">
            <span class="ep-num">${e.episode_number}</span>
            <span class="ep-name${e.watched?' watched':''}">${esc(e.name)}</span>
            ${yr?`<span class="ep-year">${yr}</span>`:''}
        </div>`;
    }
    container.innerHTML=html;
}

async function setEpisodeWatched(movieId,seasonNum,episodeNum,watched,cbEl){
    const d=await post({action:'set_episode_watched',movie_id:movieId,season_num:seasonNum,episode_num:episodeNum,watched:watched?1:0});
    if(!d.ok){cbEl.checked=!watched;toast('Fehler',false);return;}
    const nameEl=document.getElementById(`er-${movieId}-${seasonNum}-${episodeNum}`)?.querySelector('.ep-name');
    if(nameEl) nameEl.classList.toggle('watched',watched);
    _updateSeasonProgress(movieId,seasonNum,watched?1:-1);
    _updateSeriesBadge(movieId,d.watched_total);
}

async function setSeasonWatched(movieId,seasonNum,watched,cbEl){
    const d=await post({action:'set_season_watched',movie_id:movieId,season_num:seasonNum,watched:watched?1:0});
    if(!d.ok){cbEl.checked=!watched;toast('Fehler',false);return;}
    const epl=document.getElementById(`epl-${movieId}-${seasonNum}`);
    if(epl?.dataset.loaded){
        epl.querySelectorAll('.ep-cb').forEach(cb=>cb.checked=watched);
        epl.querySelectorAll('.ep-name').forEach(el=>el.classList.toggle('watched',watched));
    }
    const si=document.getElementById(`si-${movieId}-${seasonNum}`);
    if(si){
        const epCount=parseInt(si.dataset.epCount)||0;
        si.dataset.watched=watched?(d.episode_count??epCount):0;
        _refreshSeasonProgress(movieId,seasonNum);
    }
    _updateSeriesBadge(movieId,d.watched_total);
}

function _updateSeasonProgress(movieId,seasonNum,delta){
    const si=document.getElementById(`si-${movieId}-${seasonNum}`);
    if(!si) return;
    si.dataset.watched=Math.max(0,(parseInt(si.dataset.watched)||0)+delta);
    _refreshSeasonProgress(movieId,seasonNum);
}

function _refreshSeasonProgress(movieId,seasonNum){
    const si=document.getElementById(`si-${movieId}-${seasonNum}`);
    if(!si) return;
    const watched=parseInt(si.dataset.watched)||0, epCount=parseInt(si.dataset.epCount)||0;
    const pct=epCount?Math.round(watched/epCount*100):0;
    const bar=si.querySelector('.season-progress-bar');
    const txt=si.querySelector('.season-progress-text');
    const cb=si.querySelector('.season-cb');
    if(bar) bar.style.width=pct+'%';
    if(txt) txt.textContent=watched+'/'+epCount;
    if(cb)  cb.checked=epCount>0&&watched>=epCount;
}

function _updateSeriesBadge(movieId,total){
    const badge=document.getElementById(`ep-badge-${movieId}`);
    if(!badge) return;
    badge.dataset.count=total;
    badge.textContent=total+' gesehen';
    badge.style.display=total>0?'':'none';
}

// Init
switchType('movie', document.querySelector('[data-type="movie"].samml-type-tab'));
if (sessionStorage.getItem('samml_focus_search')) {
    sessionStorage.removeItem('samml_focus_search');
    searchBox.focus();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
