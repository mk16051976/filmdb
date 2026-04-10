<?php
$pageTitle = 'Filminfo – MKFB';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/lang.php';
requireLogin();

$db     = getDB();
$filmId = (int)($_GET['id'] ?? 0);

if ($filmId < 1) {
    header('Location: /charts.php'); exit;
}

// ── Lokale DB-Daten ───────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM movies WHERE id = ?");
$stmt->execute([$filmId]);
$movie = $stmt->fetch();

if (!$movie) {
    header('Location: /charts.php'); exit;
}

$pageTitle = e(movieTitle($movie)) . ' – MKFB';

// ── TMDB-Daten laden (wenn tmdb_id vorhanden + API-Key konfiguriert) ──────────
$tmdb       = null;
$cast       = [];
$crew       = [];
$trailerKey = null;
$seasons    = [];   // nur bei TV-Serien
$tmdbId     = (int)($movie['tmdb_id'] ?? 0);
$apiReady   = defined('TMDB_API_KEY') && TMDB_API_KEY !== '' && TMDB_API_KEY !== 'DEIN_API_KEY_HIER';
$isTv       = ($movie['media_type'] ?? '') === 'tv';

$_filmLang  = currentLang() === 'en' ? 'en-US' : 'de-DE';

// AJAX: Staffel-Episoden laden
if (isset($_GET['action']) && $_GET['action'] === 'get_season' && $tmdbId && $apiReady) {
    header('Content-Type: application/json');
    $sNr  = (int)($_GET['season'] ?? 1);
    $sUrl = 'https://api.themoviedb.org/3/tv/' . $tmdbId . '/season/' . $sNr
          . '?api_key=' . TMDB_API_KEY . '&language=' . $_filmLang;
    $ctx  = stream_context_create(['http' => ['timeout' => 8]]);
    $raw  = @file_get_contents($sUrl, false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;
    $eps  = [];
    foreach ($data['episodes'] ?? [] as $ep) {
        $eps[] = [
            'nr'       => $ep['episode_number'],
            'name'     => $ep['name'] ?? '',
            'overview' => mb_substr($ep['overview'] ?? '', 0, 200),
            'date'     => $ep['air_date'] ?? '',
            'runtime'  => $ep['runtime'] ?? null,
            'still'    => $ep['still_path'] ?? null,
        ];
    }
    echo json_encode(['episodes' => $eps, 'name' => $data['name'] ?? '']);
    exit;
}

if ($tmdbId && $apiReady) {
    $ep  = $isTv ? 'tv/' . $tmdbId : 'movie/' . $tmdbId;
    $url = 'https://api.themoviedb.org/3/' . $ep
         . '?api_key=' . TMDB_API_KEY
         . '&language=' . $_filmLang
         . '&append_to_response=credits,videos';
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw) {
        $tmdb = json_decode($raw, true);
        // TV: Feldnamen angleichen
        if ($isTv) {
            $tmdb['title']        = $tmdb['name']             ?? ($movie['title'] ?? '');
            $tmdb['release_date'] = $tmdb['first_air_date']   ?? '';
            $tmdb['runtime']      = !empty($tmdb['episode_run_time']) ? $tmdb['episode_run_time'][0] : 0;
            $tmdb['tagline']      = $tmdb['tagline']           ?? '';
        }
        // Cast (max. 12)
        $cast = array_slice($tmdb['credits']['cast'] ?? [], 0, 12);
        // Crew
        foreach ($tmdb['credits']['crew'] ?? [] as $c) {
            if (in_array($c['job'], ['Director','Producer','Screenplay','Writer','Original Music Composer','Creator'])) {
                $crew[] = $c;
            }
        }
        // TV: Creator als Crew hinzufügen
        if ($isTv) {
            foreach ($tmdb['created_by'] ?? [] as $c) {
                array_unshift($crew, ['job' => 'Schöpfer', 'name' => $c['name']]);
            }
        }
        // Trailer – bis zu 3 sammeln (Trailer vor Teaser)
        $trailers = [];
        foreach ($tmdb['videos']['results'] ?? [] as $v) {
            if ($v['site'] === 'YouTube' && in_array($v['type'], ['Trailer','Teaser']) && !empty($v['key'])) {
                $trailers[] = ['key' => $v['key'], 'name' => $v['name'] ?? '', 'type' => $v['type']];
                if (count($trailers) >= 3) break;
            }
        }
        // Fallback: englische Trailer nachladen falls keine gefunden
        if (empty($trailers) && $_filmLang !== 'en-US') {
            $vidUrl = 'https://api.themoviedb.org/3/' . $ep
                    . '?api_key=' . TMDB_API_KEY . '&language=en-US&append_to_response=videos';
            $vidRaw = @file_get_contents($vidUrl, false, $ctx);
            $vidData = $vidRaw ? json_decode($vidRaw, true) : null;
            foreach ($vidData['videos']['results'] ?? [] as $v) {
                if ($v['site'] === 'YouTube' && in_array($v['type'], ['Trailer','Teaser']) && !empty($v['key'])) {
                    $trailers[] = ['key' => $v['key'], 'name' => $v['name'] ?? '', 'type' => $v['type']];
                    if (count($trailers) >= 3) break;
                }
            }
        }
        $trailerKey = $trailers[0]['key'] ?? null; // Rückwärtskompatibilität
        // Staffeln (TV)
        if ($isTv) {
            foreach ($tmdb['seasons'] ?? [] as $s) {
                if (($s['season_number'] ?? 0) < 1) continue; // Specials überspringen (season 0)
                $seasons[] = $s;
            }
        }
    }
}

// Backdrop-URL
$backdropUrl = null;
if (!empty($tmdb['backdrop_path'])) {
    $backdropUrl = 'https://image.tmdb.org/t/p/w1280' . $tmdb['backdrop_path'];
}
$_posterPath = (currentLang() === 'en' && !empty($movie['poster_path_en']))
    ? $movie['poster_path_en']
    : ($movie['poster_path'] ?? null);
$posterUrl = $_posterPath
    ? 'https://image.tmdb.org/t/p/w500' . $_posterPath
    : 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?';

// Laufzeit formatieren
$runtime = (int)($tmdb['runtime'] ?? 0);
$runtimeStr = $runtime > 0
    ? floor($runtime / 60) . 'h ' . ($runtime % 60) . 'min'
    : null;

// Genres
$genres = array_column($tmdb['genres'] ?? [], 'name');

// User-Bewertung + Sammlung aus DB
$userRating   = null;
$userPosition = null;
$userColl     = null;   // ['status'=>…, 'storage_link'=>…]

if (isLoggedIn()) {
    $userId = (int)$_SESSION['user_id'];
    $rs = $db->prepare("SELECT elo, wins, losses, comparisons FROM user_ratings WHERE user_id = ? AND movie_id = ?");
    $rs->execute([$userId, $filmId]);
    $userRating = $rs->fetch();

    $rp = $db->prepare("SELECT position FROM user_position_ranking WHERE user_id = ? AND movie_id = ?");
    $rp->execute([$userId, $filmId]);
    $userPosition = $rp->fetchColumn();

    // Sammlung (Tabelle ggf. anlegen)
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
        $rc = $db->prepare("SELECT status, storage_link FROM user_collection WHERE user_id = ? AND movie_id = ?");
        $rc->execute([$userId, $filmId]);
        $userColl = $rc->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\PDOException $e) {}

    // AJAX: Sammlung-Aktionen
    $ajaxAction = $_POST['film_action'] ?? '';
    if ($ajaxAction) {
        header('Content-Type: application/json');
        if ($ajaxAction === 'coll_add') {
            $status = in_array($_POST['status']??'',['besitz','interesse']) ? $_POST['status'] : 'interesse';
            $db->prepare("INSERT INTO user_collection (user_id,movie_id,status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)")
               ->execute([$userId,$filmId,$status]);
            echo json_encode(['ok'=>true,'status'=>$status]);
        } elseif ($ajaxAction === 'coll_status') {
            $status = in_array($_POST['status']??'',['besitz','interesse']) ? $_POST['status'] : null;
            if ($status) $db->prepare("UPDATE user_collection SET status=? WHERE user_id=? AND movie_id=?")->execute([$status,$userId,$filmId]);
            echo json_encode(['ok'=>true]);
        } elseif ($ajaxAction === 'coll_link') {
            $link = mb_substr(trim($_POST['link']??''),0,1000);
            $db->prepare("UPDATE user_collection SET storage_link=? WHERE user_id=? AND movie_id=?")->execute([$link?:null,$userId,$filmId]);
            echo json_encode(['ok'=>true]);
        } elseif ($ajaxAction === 'coll_remove') {
            $db->prepare("DELETE FROM user_collection WHERE user_id=? AND movie_id=?")->execute([$userId,$filmId]);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false]);
        }
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.film-page { background: #0d1f3c; min-height: 100vh; color: #fff; }

/* Backdrop */
.film-backdrop {
    position: relative;
    height: 380px;
    overflow: hidden;
    background: #0d1f3c;
}
.film-backdrop img {
    width: 100%; height: 100%; object-fit: cover;
    opacity: .35;
}
.film-backdrop-gradient {
    position: absolute; inset: 0;
    background: linear-gradient(to bottom, rgba(13,31,60,.3) 0%, #0d1f3c 100%);
}

/* Hero */
.film-hero {
    display: flex; gap: 32px; align-items: flex-start;
    margin-top: -160px; position: relative; z-index: 2;
    padding: 0 0 32px;
}
.film-poster {
    flex-shrink: 0; width: 220px;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0,0,0,.6);
    border: 2px solid rgba(232,184,75,.3);
}
.film-info { flex: 1; min-width: 0; padding-top: 160px; }

/* Meta badges */
.film-meta { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
.film-badge {
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
    border-radius: 6px; padding: 4px 10px;
    font-size: .8rem; color: #fff;
}
.film-badge.gold { background: rgba(232,184,75,.15); border-color: rgba(232,184,75,.3); color: #e8b84b; }

/* Bewertungsbox */
.rating-box {
    background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1);
    border-radius: 12px; padding: 20px 24px;
    display: flex; gap: 24px; flex-wrap: wrap; align-items: center;
}
.rating-stat { text-align: center; }
.rating-val { font-size: 1.6rem; font-weight: 800; color: #e8b84b; line-height: 1; }
.rating-lbl { font-size: .7rem; color: rgba(255,255,255,.4); margin-top: 4px; }

/* Sektion */
.film-section { margin-top: 36px; }
.film-section-title {
    font-size: .7rem; font-weight: 700; letter-spacing: .1em;
    text-transform: uppercase; color: rgba(232,184,75,.7);
    margin-bottom: 14px; padding-bottom: 6px;
    border-bottom: 1px solid rgba(232,184,75,.15);
}

/* Cast */
.cast-grid { display: flex; gap: 12px; flex-wrap: wrap; }
.cast-card {
    width: 90px; text-align: center;
    background: rgba(255,255,255,.04); border-radius: 10px;
    overflow: hidden; flex-shrink: 0;
}
.cast-card img { width: 90px; height: 120px; object-fit: cover; display: block; }
.cast-card-body { padding: 6px 4px; }
.cast-name { font-size: .7rem; font-weight: 600; color: #e0e0e0; }
.cast-char { font-size: .62rem; color: rgba(255,255,255,.4); margin-top: 2px; }

/* Trailer */
.trailer-wrap {
    position: relative; padding-bottom: 56.25%;
    border-radius: 12px; overflow: hidden;
}
.trailer-wrap iframe {
    position: absolute; inset: 0;
    width: 100%; height: 100%; border: none;
}

/* Staffeln */
.season-item { border-bottom: 1px solid rgba(255,255,255,.06); }
.season-item:last-child { border-bottom: none; }
.season-header {
    display: flex; align-items: center; gap: .85rem;
    padding: .65rem .4rem; cursor: pointer;
    border-radius: 8px; transition: background .15s;
}
.season-header:hover { background: rgba(255,255,255,.04); }
.season-poster { width: 52px; height: 78px; object-fit: cover; border-radius: 5px; flex-shrink: 0; box-shadow: 0 4px 12px rgba(0,0,0,.4); }
.season-info { flex: 1; min-width: 0; }
.season-name { font-size: .92rem; font-weight: 700; color: #fff; }
.season-meta { font-size: .75rem; color: rgba(255,255,255,.4); margin-top: 3px; }
.season-chevron { font-size: .85rem; color: rgba(255,255,255,.3); margin-left: auto; transition: transform .2s; flex-shrink: 0; }
.season-item.open .season-chevron { transform: rotate(180deg); }
.season-loading { padding: .6rem .4rem; font-size: .8rem; color: rgba(255,255,255,.35); }
.season-episodes { padding: .25rem .4rem .75rem; }
.ep-row {
    display: flex; align-items: flex-start; gap: .75rem;
    padding: .6rem 0; border-bottom: 1px solid rgba(255,255,255,.04);
}
.ep-row:last-child { border-bottom: none; }
.ep-still { width: 120px; height: 68px; object-fit: cover; border-radius: 5px; flex-shrink: 0; background: #1e3a5f; }
.ep-nr { font-size: .7rem; font-weight: 800; color: #e8b84b; min-width: 22px; padding-top: 3px; flex-shrink: 0; }
.ep-info { flex: 1; min-width: 0; }
.ep-name { font-size: .85rem; font-weight: 700; color: #fff; }
.ep-meta { font-size: .72rem; color: rgba(255,255,255,.35); margin-top: 2px; }
.ep-desc { font-size: .78rem; color: rgba(255,255,255,.45); margin-top: 4px; line-height: 1.55; }
@media (max-width: 576px) {
    .ep-still { width: 90px; height: 51px; }
    .season-poster { width: 42px; height: 63px; }
}

/* TMDB + IMDB links */
.ext-links { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
.ext-link {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 8px; font-size: .8rem; font-weight: 600;
    text-decoration: none !important;
}
.ext-link-tmdb { background: rgba(1,180,228,.15); color: #01b4e4; border: 1px solid rgba(1,180,228,.3); }
.ext-link-imdb { background: rgba(245,197,24,.15); color: #f5c518; border: 1px solid rgba(245,197,24,.3); }

@media (max-width: 768px) {
    .film-hero { flex-direction: column; margin-top: 0; }
    .film-poster { width: 160px; margin: 0 auto; }
    .film-info { padding-top: 0; }
    .film-backdrop { height: 200px; }
}
</style>

<main class="film-page">

    <!-- Zurück-Button -->
    <div style="position:absolute; top:72px; left:0; right:0; z-index:10; pointer-events:none;">
        <div class="container" style="pointer-events:none;">
            <button onclick="if(history.length>1 && document.referrer && new URL(document.referrer).host===location.host){history.back()}else{location.href='/meine-rangliste.php'}"
                    style="pointer-events:all; background:rgba(0,0,0,.45); border:1px solid rgba(255,255,255,.15);
                           color:rgba(255,255,255,.75); border-radius:8px; padding:5px 14px;
                           font-size:.8rem; font-weight:600; cursor:pointer; backdrop-filter:blur(6px);
                           transition:background .15s, color .15s;"
                    onmouseover="this.style.background='rgba(232,184,75,.2)';this.style.color='#e8b84b';"
                    onmouseout="this.style.background='rgba(0,0,0,.45)';this.style.color='rgba(255,255,255,.75)';">
                <i class="bi bi-arrow-left me-1"></i>Zurück
            </button>
        </div>
    </div>

    <!-- Backdrop -->
    <?php if ($backdropUrl): ?>
    <div class="film-backdrop">
        <img src="<?= e($backdropUrl) ?>" alt="">
        <div class="film-backdrop-gradient"></div>
    </div>
    <?php else: ?>
    <div style="height:160px; background:#0d1f3c;"></div>
    <?php endif; ?>

    <div class="container" style="padding-bottom: 4rem;">

        <div class="film-hero">
            <!-- Poster -->
            <img src="<?= e($posterUrl) ?>"
                 alt="<?= e(movieTitle($movie)) ?>"
                 class="film-poster"
                 onerror="this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">

            <!-- Info -->
            <div class="film-info">
                <h1 class="fw-black mb-1" style="font-size:clamp(1.5rem,4vw,2.6rem); line-height:1.15;">
                    <?= e(movieTitle($movie)) ?>
                </h1>
                <?php if (!empty($movie['original_title']) && $movie['original_title'] !== $movie['title']): ?>
                <div style="color:rgba(255,255,255,.75); font-size:.9rem; margin-bottom:4px;">
                    <?= e($movie['original_title']) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($tmdb['tagline'])): ?>
                <div style="color:#e8b84b; font-style:italic; font-size:.9rem; margin-bottom:8px;">
                    „<?= e($tmdb['tagline']) ?>"
                </div>
                <?php endif; ?>

                <!-- Meta -->
                <div class="film-meta">
                    <?php if ($movie['year']): ?>
                    <span class="film-badge gold"><?= (int)$movie['year'] ?></span>
                    <?php endif; ?>
                    <?php if ($runtimeStr): ?>
                    <span class="film-badge"><i class="bi bi-clock me-1"></i><?= $runtimeStr ?></span>
                    <?php endif; ?>
                    <?php foreach ($genres as $g): ?>
                    <span class="film-badge"><?= e($g) ?></span>
                    <?php endforeach; ?>
                    <?php if (!empty($movie['country'])): ?>
                    <span class="film-badge"><?= e($movie['country']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($tmdb['vote_average'])): ?>
                    <span class="film-badge gold">
                        <i class="bi bi-star-fill me-1"></i>
                        <?= number_format((float)$tmdb['vote_average'], 1) ?>/10
                        <span style="opacity:.75; font-size:.75em;">(<?= number_format((int)$tmdb['vote_count']) ?>)</span>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- Externe Links -->
                <div class="ext-links">
                    <?php if ($tmdbId): ?>
                    <a href="https://www.themoviedb.org/movie/<?= $tmdbId ?>" target="_blank" class="ext-link ext-link-tmdb">
                        <i class="bi bi-box-arrow-up-right"></i> TMDB
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($movie['imdb_id'])): ?>
                    <a href="https://www.imdb.com/title/<?= e($movie['imdb_id']) ?>/" target="_blank" class="ext-link ext-link-imdb">
                        <i class="bi bi-box-arrow-up-right"></i> IMDb
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row g-5">
            <!-- Hauptinhalt -->
            <div class="col-lg-8">

                <?php if (!empty($movie['overview']) || !empty($tmdb['overview'])): ?>
                <div class="film-section">
                    <div class="film-section-title"><?= t('film.plot') ?></div>
                    <p style="color:#fff; line-height:1.8; font-size:.95rem;">
                        <?= nl2br(e($tmdb['overview'] ?? movieOverview($movie) ?? '')) ?>
                    </p>
                </div>
                <?php endif; ?>

                <?php if (!empty($movie['wikipedia'])): ?>
                <div class="film-section">
                    <div class="film-section-title">
                        <i class="bi bi-wikipedia me-1"></i>Wikipedia
                    </div>
                    <div style="color:rgba(255,255,255,.85); line-height:1.85; font-size:.93rem;
                                background:rgba(255,255,255,.03); border-left:3px solid rgba(232,184,75,.25);
                                border-radius:0 8px 8px 0; padding:.85rem 1rem;">
                        <?= nl2br(e($movie['wikipedia'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Trailer -->
                <?php if (!empty($trailers)): ?>
                <div class="film-section">
                    <div class="film-section-title"><?= t('film.trailer') ?></div>

                    <?php if (count($trailers) > 1): ?>
                    <!-- Tab-Auswahl bei mehreren Trailern -->
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:.75rem;">
                        <?php foreach ($trailers as $i => $tr): ?>
                        <button onclick="switchTrailer(<?= $i ?>, this)"
                                class="trailer-tab <?= $i===0 ? 'active' : '' ?>"
                                style="background:<?= $i===0 ? 'rgba(232,184,75,.2)' : 'rgba(255,255,255,.07)' ?>;
                                       border:1px solid <?= $i===0 ? 'rgba(232,184,75,.4)' : 'rgba(255,255,255,.12)' ?>;
                                       color:<?= $i===0 ? '#e8b84b' : 'rgba(255,255,255,.55)' ?>;
                                       border-radius:8px; padding:4px 12px; font-size:.75rem; font-weight:600; cursor:pointer; transition:all .15s;">
                            <i class="bi bi-play-fill me-1"></i><?= e($tr['name'] ?: $tr['type'] . ' ' . ($i+1)) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="trailer-wrap" id="trailerFrame">
                        <iframe id="trailerIframe"
                                src="https://www.youtube.com/embed/<?= e($trailers[0]['key']) ?>?rel=0"
                                allowfullscreen loading="lazy"></iframe>
                    </div>
                </div>
                <script>
                const _trailerKeys = <?= json_encode(array_column($trailers, 'key')) ?>;
                function switchTrailer(idx, btn) {
                    document.getElementById('trailerIframe').src =
                        'https://www.youtube.com/embed/' + _trailerKeys[idx] + '?rel=0&autoplay=1';
                    document.querySelectorAll('.trailer-tab').forEach((b, i) => {
                        const active = i === idx;
                        b.style.background = active ? 'rgba(232,184,75,.2)' : 'rgba(255,255,255,.07)';
                        b.style.borderColor = active ? 'rgba(232,184,75,.4)' : 'rgba(255,255,255,.12)';
                        b.style.color       = active ? '#e8b84b'              : 'rgba(255,255,255,.55)';
                    });
                }
                </script>
                <?php endif; ?>

                <!-- Cast -->
                <?php if (!empty($cast)): ?>
                <div class="film-section">
                    <div class="film-section-title"><?= t('film.cast') ?></div>
                    <div class="cast-grid">
                        <?php foreach ($cast as $actor):
                            $pic = $actor['profile_path']
                                ? 'https://image.tmdb.org/t/p/w185' . $actor['profile_path']
                                : 'https://placehold.co/90x120/1e3a5f/e8b84b?text=?';
                        ?>
                        <div class="cast-card">
                            <img src="<?= e($pic) ?>" alt="<?= e($actor['name']) ?>"
                                 onerror="this.src='https://placehold.co/90x120/1e3a5f/e8b84b?text=?'">
                            <div class="cast-card-body">
                                <div class="cast-name"><?= e($actor['name']) ?></div>
                                <div class="cast-char"><?= e($actor['character'] ?? '') ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- TV: Staffeln -->
                <?php if ($isTv && !empty($seasons)): ?>
                <div class="film-section" id="seasons-section">
                    <div class="film-section-title">
                        <i class="bi bi-collection-play me-1"></i>
                        Staffeln
                        <span style="font-size:.75em; opacity:.5; margin-left:6px;"><?= count($seasons) ?> Staffel<?= count($seasons)!==1?'n':'' ?></span>
                    </div>
                    <div id="seasons-list">
                    <?php foreach ($seasons as $s):
                        $sPoster = !empty($s['poster_path'])
                            ? 'https://image.tmdb.org/t/p/w185' . $s['poster_path']
                            : 'https://placehold.co/60x90/1e3a5f/e8b84b?text=?';
                    ?>
                        <div class="season-item" data-season="<?= (int)$s['season_number'] ?>">
                            <div class="season-header" onclick="toggleSeason(this, <?= (int)$s['season_number'] ?>, <?= $tmdbId ?>)">
                                <img src="<?= e($sPoster) ?>" class="season-poster"
                                     onerror="this.src='https://placehold.co/60x90/1e3a5f/e8b84b?text=?'">
                                <div class="season-info">
                                    <div class="season-name"><?= e($s['name'] ?? 'Staffel ' . $s['season_number']) ?></div>
                                    <div class="season-meta">
                                        <?= (int)$s['episode_count'] ?> Episoden
                                        <?php if (!empty($s['air_date'])): ?>· <?= date('Y', strtotime($s['air_date'])) ?><?php endif; ?>
                                    </div>
                                </div>
                                <i class="bi bi-chevron-down season-chevron"></i>
                            </div>
                            <div class="season-episodes" style="display:none;"></div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Meine Sammlung -->
                <?php if (isLoggedIn()): ?>
                <div class="film-section" id="film-coll-section">
                    <div class="film-section-title">
                        <i class="bi bi-collection-play me-1"></i>Meine Sammlung
                    </div>

                    <?php if ($userColl): ?>
                    <!-- In Sammlung -->
                    <div id="collInBox">
                        <div style="display:flex; align-items:center; gap:.75rem; flex-wrap:wrap;">
                            <button id="collStatusBtn"
                                    class="coll-status-pill <?= $userColl['status'] ?>"
                                    onclick="collToggleStatus()">
                                <?php if ($userColl['status']==='besitz'): ?>
                                    <i class="bi bi-check-circle-fill"></i> In Besitz
                                <?php else: ?>
                                    <i class="bi bi-bookmark-fill"></i> Auf Wunschliste
                                <?php endif; ?>
                            </button>
                            <a href="/meine-sammlung.php" style="font-size:.75rem; color:rgba(255,255,255,.35); text-decoration:none;">
                                <i class="bi bi-arrow-right me-1"></i>Zur Sammlung
                            </a>
                            <button onclick="collRemove()" style="background:none;border:none;color:rgba(248,113,113,.5);font-size:.75rem;cursor:pointer;margin-left:auto;padding:0;"
                                    title="Aus Sammlung entfernen">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </div>

                        <!-- Speicherort -->
                        <div style="margin-top:.85rem;">
                            <div style="font-size:.7rem; color:rgba(255,255,255,.35); margin-bottom:.35rem; text-transform:uppercase; letter-spacing:.06em; font-weight:700;">
                                Speicherort
                            </div>
                            <div style="display:flex; align-items:center; gap:.5rem;">
                                <input type="text" id="collLinkInput"
                                       value="<?= e($userColl['storage_link'] ?? '') ?>"
                                       placeholder="Pfad oder file:///…"
                                       onblur="collSaveLink()"
                                       onkeydown="if(event.key==='Enter')this.blur()"
                                       style="flex:1; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12);
                                              border-radius:8px; color:#fff; padding:.4rem .7rem; font-size:.82rem; outline:none;
                                              transition:border-color .2s; min-width:0;">
                                <button onclick="collOpenFileBrowser()" title="Datei auswählen"
                                        style="background:none;border:none;color:rgba(232,184,75,.6);font-size:1.1rem;cursor:pointer;transition:color .15s;flex-shrink:0;"
                                        onmouseover="this.style.color='#e8b84b'" onmouseout="this.style.color='rgba(232,184,75,.6)'">
                                    <i class="bi bi-folder2-open"></i>
                                </button>
                                <?php if ($userColl['storage_link']): ?>
                                <a id="collPlayBtn" href="<?= e($userColl['storage_link']) ?>" target="_blank"
                                   title="Abspielen" style="color:#22c55e; font-size:1.2rem; flex-shrink:0;">
                                    <i class="bi bi-play-circle-fill"></i>
                                </a>
                                <?php else: ?>
                                <span id="collPlayBtn" style="color:rgba(255,255,255,.15); font-size:1.2rem; flex-shrink:0;">
                                    <i class="bi bi-play-circle-fill"></i>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php else: ?>
                    <!-- Noch nicht in Sammlung -->
                    <div id="collAddBox">
                        <p style="color:rgba(255,255,255,.35); font-size:.82rem; margin:0 0 .75rem;">
                            Dieser Titel ist noch nicht in deiner Sammlung.
                        </p>
                        <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
                            <button onclick="collAdd('besitz')"
                                    style="display:inline-flex;align-items:center;gap:.4rem;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#22c55e;border-radius:20px;padding:.35rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .15s;">
                                <i class="bi bi-check-circle-fill"></i> In Besitz
                            </button>
                            <button onclick="collAdd('interesse')"
                                    style="display:inline-flex;align-items:center;gap:.4rem;background:rgba(232,184,75,.12);border:1px solid rgba(232,184,75,.3);color:#e8b84b;border-radius:20px;padding:.35rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .15s;">
                                <i class="bi bi-bookmark-fill"></i> Wunschliste
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <style>
                .coll-status-pill {
                    display:inline-flex; align-items:center; gap:.4rem;
                    border-radius:20px; padding:.35rem .9rem; font-size:.78rem; font-weight:700;
                    cursor:pointer; border:none; transition:all .15s;
                }
                .coll-status-pill.besitz    { background:rgba(34,197,94,.15);  color:#22c55e; border:1px solid rgba(34,197,94,.3); }
                .coll-status-pill.interesse { background:rgba(232,184,75,.12); color:#e8b84b; border:1px solid rgba(232,184,75,.3); }
                #collLinkInput:focus { border-color:rgba(232,184,75,.5) !important; }
                </style>

                <script>
                const FILM_AJAX = '/film.php?id=<?= $filmId ?>';
                function filmPost(d){ return fetch(FILM_AJAX,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d)}).then(r=>r.json()); }

                async function collAdd(status) {
                    const d = await filmPost({film_action:'coll_add', status});
                    if (!d.ok) return;
                    // Seite neu laden für vollständige Ansicht
                    location.reload();
                }

                async function collToggleStatus() {
                    const btn = document.getElementById('collStatusBtn');
                    const cur = btn.classList.contains('besitz') ? 'besitz' : 'interesse';
                    const next = cur === 'besitz' ? 'interesse' : 'besitz';
                    await filmPost({film_action:'coll_status', status:next});
                    btn.classList.remove(cur); btn.classList.add(next);
                    btn.innerHTML = next === 'besitz'
                        ? '<i class="bi bi-check-circle-fill"></i> In Besitz'
                        : '<i class="bi bi-bookmark-fill"></i> Auf Wunschliste';
                }

                async function collSaveLink() {
                    const input = document.getElementById('collLinkInput');
                    const link  = input.value.trim();
                    await filmPost({film_action:'coll_link', link});
                    const pb = document.getElementById('collPlayBtn');
                    if (pb && link) {
                        pb.outerHTML = `<a id="collPlayBtn" href="${link}" target="_blank" title="Abspielen" style="color:#22c55e;font-size:1.2rem;flex-shrink:0;"><i class="bi bi-play-circle-fill"></i></a>`;
                    } else if (pb) {
                        pb.outerHTML = `<span id="collPlayBtn" style="color:rgba(255,255,255,.15);font-size:1.2rem;flex-shrink:0;"><i class="bi bi-play-circle-fill"></i></span>`;
                    }
                }

                async function collRemove() {
                    if (!confirm('Aus der Sammlung entfernen?')) return;
                    await filmPost({film_action:'coll_remove'});
                    document.getElementById('collInBox').outerHTML =
                        `<div id="collAddBox">
                            <p style="color:rgba(255,255,255,.35);font-size:.82rem;margin:0 0 .75rem;">Dieser Titel ist noch nicht in deiner Sammlung.</p>
                            <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                                <button onclick="collAdd('besitz')" style="display:inline-flex;align-items:center;gap:.4rem;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#22c55e;border-radius:20px;padding:.35rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;">
                                    <i class="bi bi-check-circle-fill"></i> In Besitz</button>
                                <button onclick="collAdd('interesse')" style="display:inline-flex;align-items:center;gap:.4rem;background:rgba(232,184,75,.12);border:1px solid rgba(232,184,75,.3);color:#e8b84b;border-radius:20px;padding:.35rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;">
                                    <i class="bi bi-bookmark-fill"></i> Wunschliste</button>
                            </div>
                        </div>`;
                }

                function collOpenFileBrowser() {
                    // Datei-Browser aus meine-sammlung.php steht hier nicht zur Verfügung –
                    // daher nativer Browser-Dialog als Fallback
                    const input = document.createElement('input');
                    input.type = 'file';
                    input.accept = 'video/*,audio/*,.mkv,.avi,.mp4,.m4v,.mov,.wmv,.ts,.iso,.flac';
                    input.onchange = () => {
                        const f = input.files[0];
                        if (!f) return;
                        // Dateiname in Eingabefeld; Benutzer kann Pfad manuell ergänzen
                        const li = document.getElementById('collLinkInput');
                        if (li.value === '') li.value = f.name;
                    };
                    input.click();
                }
                </script>
                <?php endif; ?>

            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">

                <!-- Meine Bewertung -->
                <?php if (isLoggedIn()): ?>
                <div class="film-section">
                    <div class="film-section-title">Meine Bewertung</div>
                    <?php if ($userRating): ?>
                    <div class="rating-box">
                        <div class="rating-stat">
                            <div class="rating-val"><?= (int)$userRating['elo'] ?></div>
                            <div class="rating-lbl">ELO</div>
                        </div>
                        <div class="rating-stat">
                            <div class="rating-val"><?= (int)$userRating['wins'] ?></div>
                            <div class="rating-lbl">Siege</div>
                        </div>
                        <div class="rating-stat">
                            <div class="rating-val"><?= (int)$userRating['losses'] ?></div>
                            <div class="rating-lbl">Niederlagen</div>
                        </div>
                        <div class="rating-stat">
                            <div class="rating-val"><?= (int)$userRating['comparisons'] ?></div>
                            <div class="rating-lbl">Duelle</div>
                        </div>
                        <?php if ($userPosition): ?>
                        <div class="rating-stat">
                            <div class="rating-val">#<?= (int)$userPosition ?></div>
                            <div class="rating-lbl">Platz</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <p style="color:rgba(255,255,255,.35); font-size:.85rem;">
                        Noch nicht bewertet.
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Details -->
                <div class="film-section">
                    <div class="film-section-title"><?= t('film.details') ?></div>
                    <table style="width:100%; font-size:.85rem; border-collapse:collapse;">
                    <?php
                    $details = [];
                    if (!empty($movie['director']))  $details[t('film.director')]  = $movie['director'];
                    if (!empty($movie['actors']))    $details[t('film.actors')]     = $movie['actors'];
                    if (!empty($movie['genre']))     $details[t('film.genre_db')]   = $movie['genre'];
                    if ($runtimeStr)                 $details[t('film.runtime')]    = $runtimeStr;
                    if (!empty($movie['country']))   $details[t('film.country')]    = $movie['country'];
                    if ($movie['year'])              $details[t('film.year')]       = (int)$movie['year'];
                    if (!empty($tmdb['release_date'])) $details[t('film.released')] = date('d.m.Y', strtotime($tmdb['release_date']));
                    if (!empty($tmdb['original_language'])) $details[t('film.language')] = strtoupper($tmdb['original_language']);
                    if ($isTv && !empty($tmdb['number_of_seasons']))  $details['Staffeln']  = (int)$tmdb['number_of_seasons'];
                    if ($isTv && !empty($tmdb['number_of_episodes'])) $details['Episoden'] = (int)$tmdb['number_of_episodes'];
                    if (!empty($tmdb['status']))     $details[t('film.status')]     = $tmdb['status'];
                    if (!empty($tmdb['budget']) && $tmdb['budget'] > 0)
                        $details[t('film.budget')]  = '$' . number_format($tmdb['budget']);
                    if (!empty($tmdb['revenue']) && $tmdb['revenue'] > 0)
                        $details[t('film.revenue')] = '$' . number_format($tmdb['revenue']);
                    foreach ($details as $label => $val): ?>
                    <tr>
                        <td style="padding:5px 0; color:rgba(255,255,255,.4); width:45%; vertical-align:top;"><?= e($label) ?></td>
                        <td style="padding:5px 0; color:rgba(255,255,255,.8);"><?= e((string)$val) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </table>
                </div>

                <!-- Produktionsfirmen -->
                <?php if (!empty($tmdb['production_companies'])): ?>
                <div class="film-section">
                    <div class="film-section-title"><?= t('film.production') ?></div>
                    <div style="font-size:.82rem; color:rgba(255,255,255,.55); line-height:1.8;">
                        <?= e(implode(', ', array_column(array_slice($tmdb['production_companies'], 0, 4), 'name'))) ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Crew -->
                <?php if (!empty($crew)): ?>
                <div class="film-section">
                    <div class="film-section-title"><?= t('film.crew') ?></div>
                    <?php foreach ($crew as $c): ?>
                    <div style="display:flex; justify-content:space-between; font-size:.82rem; padding:4px 0; border-bottom:1px solid rgba(255,255,255,.05);">
                        <span style="color:rgba(255,255,255,.4);"><?= e($c['job']) ?></span>
                        <span style="color:rgba(255,255,255,.8);"><?= e($c['name']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</main>

<?php if ($isTv && !empty($seasons)): ?>
<script>
const FILM_ID   = <?= $filmId ?>;
const TMDB_ID   = <?= $tmdbId ?>;
const PAGE_URL  = '/film.php?id=' + FILM_ID;
const _openSeasons = {};

async function toggleSeason(hdr, seasonNr, tmdbId) {
    const item = hdr.closest('.season-item');
    const body = item.querySelector('.season-episodes');
    const isOpen = item.classList.contains('open');

    if (isOpen) {
        item.classList.remove('open');
        body.style.display = 'none';
        return;
    }

    item.classList.add('open');
    body.style.display = 'block';

    if (_openSeasons[seasonNr]) return; // bereits geladen
    _openSeasons[seasonNr] = true;

    body.innerHTML = '<div class="season-loading"><span class="spinner-border spinner-border-sm me-2"></span>Episoden laden…</div>';

    const r    = await fetch(`${PAGE_URL}&action=get_season&season=${seasonNr}`);
    const data = await r.json();

    if (!data.episodes?.length) {
        body.innerHTML = '<div class="season-loading" style="color:rgba(255,255,255,.25);">Keine Episoden verfügbar.</div>';
        return;
    }

    body.innerHTML = data.episodes.map(ep => {
        const still = ep.still
            ? `https://image.tmdb.org/t/p/w300${ep.still}`
            : 'https://placehold.co/80x45/1e3a5f/e8b84b?text=?';
        const runtime = ep.runtime ? ` · ${ep.runtime} Min.` : '';
        const date    = ep.date    ? ` · ${ep.date}` : '';
        const ov      = ep.overview ? `<div class="ep-desc">${esc(ep.overview)}</div>` : '';
        return `<div class="ep-row">
            <img class="ep-still" src="${still}" loading="lazy"
                 onerror="this.src='https://placehold.co/80x45/1e3a5f/e8b84b?text=?'">
            <span class="ep-nr">${ep.nr}</span>
            <div class="ep-info">
                <div class="ep-name">${esc(ep.name)}</div>
                <div class="ep-meta">${ep.date||''}${runtime}</div>
                ${ov}
            </div>
        </div>`;
    }).join('');
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
