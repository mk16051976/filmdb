<?php
$pageTitle = 'Filmdatenbank – MKFB';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/config/db.php';
startSession();
requirePhase(3);
$tmdbLang = currentLang() === 'en' ? 'en-US' : 'de-DE'; // Phase 2/3 users redirected to tournament/liga; guests unaffected

// ── TMDB Helper ───────────────────────────────────────────────────────────────
function tmdbGet(string $endpoint, array $params = []): ?array {
    $params['api_key'] = TMDB_API_KEY;
    $url = 'https://api.themoviedb.org/3/' . ltrim($endpoint, '/') . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) return null;
    return json_decode($body, true) ?: null;
}

function formatGermanDate(string $isoDate): string {
    static $months = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
                      'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    $ts = strtotime($isoDate);
    if (!$ts) return '';
    return (int)date('j', $ts) . '. ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

function insertMovieFromTmdb(array $movie): bool {
    $db     = getDB();
    $db->exec("ALTER TABLE movies ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NULL");
    $isTv   = !empty($movie['_is_tv']);
    $genres = implode(', ', array_column($movie['genres'] ?? [], 'name'));
    $year   = !empty($movie['release_date'])
        ? (int)substr($movie['release_date'], 0, 4)
        : null;

    if ($isTv) {
        $director = $movie['created_by'][0]['name'] ?? null;
    } else {
        $director = null;
        foreach ($movie['credits']['crew'] ?? [] as $crew) {
            if ($crew['job'] === 'Director') { $director = $crew['name']; break; }
        }
    }

    $actors  = implode(', ', array_slice(array_column($movie['credits']['cast'] ?? [], 'name'), 0, 10)) ?: null;
    $country = translateProductionCountries($movie['production_countries'] ?? []) ?: null;

    try {
        $db->prepare(
            'INSERT INTO movies
                 (title, original_title, year, genre, tmdb_id, poster_path, overview, director, actors, country, imdb_id, media_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $movie['title'],
            $movie['original_title'] ?? $movie['title'],
            $year,
            $genres ?: null,
            (int)$movie['id'],
            $movie['poster_path'] ?? null,
            $movie['overview'] ?? null,
            $director,
            $actors,
            $country,
            !empty($movie['imdb_id']) ? $movie['imdb_id'] : null,
            $isTv ? 'tv' : null,
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

$apiReady = defined('TMDB_API_KEY') && TMDB_API_KEY !== '' && TMDB_API_KEY !== 'DEIN_API_KEY_HIER';

// ── POST: Film zum Duell-Pool hinzufügen ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['add_tmdb_id'])
    && isLoggedIn()
    && $apiReady
) {
    $addId = (int)$_POST['add_tmdb_id'];
    $retQ  = trim($_POST['return_q'] ?? '');

    if ($addId > 0) {
        $db    = getDB();
        $db->exec("ALTER TABLE movies
            ADD COLUMN IF NOT EXISTS director VARCHAR(255) NULL,
            ADD COLUMN IF NOT EXISTS actors   TEXT         NULL,
            ADD COLUMN IF NOT EXISTS country  VARCHAR(255) NULL,
            ADD COLUMN IF NOT EXISTS imdb_id  VARCHAR(20)  NULL");
        $addType = in_array($_POST['add_type'] ?? '', ['tv','movie']) ? $_POST['add_type'] : 'movie';
        $check   = $db->prepare('SELECT id FROM movies WHERE tmdb_id = ?');
        $check->execute([$addId]);
        if (!$check->fetchColumn()) {
            if ($addType === 'tv') {
                $movieData = tmdbGet("tv/{$addId}", [
                    'language'           => 'de-DE',
                    'append_to_response' => 'credits,external_ids',
                ]);
                if ($movieData) {
                    $movieData['title']          = $movieData['name']          ?? '';
                    $movieData['original_title'] = $movieData['original_name'] ?? $movieData['title'];
                    $movieData['release_date']   = $movieData['first_air_date'] ?? '';
                    $movieData['imdb_id']        = $movieData['external_ids']['imdb_id'] ?? null;
                    $movieData['_is_tv']         = true;
                    insertMovieFromTmdb($movieData);
                }
            } else {
                $movieData = tmdbGet("movie/{$addId}", [
                    'language'           => 'de-DE',
                    'append_to_response' => 'credits',
                ]);
                if ($movieData) {
                    insertMovieFromTmdb($movieData);
                }
            }
        }
    }

    $loc = '/charts.php?id=' . $addId . '&added=1';
    if ($retQ !== '') $loc .= '&q=' . urlencode($retQ);
    header('Location: ' . $loc);
    exit;
}

$query     = trim($_GET['q'] ?? '');
$tmdbId    = (int)($_GET['id'] ?? 0);
$mediaType = in_array($_GET['type'] ?? '', ['tv','movie']) ? $_GET['type'] : 'movie';
$added     = isset($_GET['added']);
$results   = [];
$detail    = null;
$fsk       = null;
$deRelease = null;
$genres    = [];
$inDb      = false;

if ($apiReady && $tmdbId > 0) {
    if ($mediaType === 'tv') {
        $detail = tmdbGet("tv/{$tmdbId}", [
            'language'           => $tmdbLang,
            'append_to_response' => 'credits,content_ratings,external_ids',
        ]);
        if ($detail) {
            // Felder normalisieren
            $detail['title']          = $detail['name']          ?? '';
            $detail['original_title'] = $detail['original_name'] ?? $detail['title'];
            $detail['release_date']   = $detail['first_air_date'] ?? '';
            $detail['imdb_id']        = $detail['external_ids']['imdb_id'] ?? null;
            // FSK aus content_ratings (DE)
            foreach (($detail['content_ratings']['results'] ?? []) as $cr) {
                if ($cr['iso_3166_1'] === 'DE' && !empty($cr['rating'])) {
                    $fsk = $cr['rating']; break;
                }
            }
            $deRelease = $detail['first_air_date'] ?? null;
            $genres    = array_column($detail['genres'] ?? [], 'name');
            $detail['_is_tv'] = true;
        }
    } else {
        $detail = tmdbGet("movie/{$tmdbId}", [
            'language'           => $tmdbLang,
            'append_to_response' => 'release_dates,credits',
        ]);
        if ($detail) {
            $genres = array_column($detail['genres'] ?? [], 'name');
            foreach (($detail['release_dates']['results'] ?? []) as $country) {
                if ($country['iso_3166_1'] === 'DE') {
                    foreach ($country['release_dates'] as $rd) {
                        if (!empty($rd['certification']) && $fsk === null) {
                            $fsk = $rd['certification'];
                        }
                        if (!empty($rd['release_date']) && $deRelease === null) {
                            $deRelease = $rd['release_date'];
                        }
                    }
                    break;
                }
            }
        }
    }
    if ($detail) {
        // Check if already in the local DB — filter by both tmdb_id AND media_type
        // (TMDB uses separate ID namespaces for movies and TV, same number can appear for both)
        if ($mediaType === 'tv') {
            $chk = getDB()->prepare("SELECT id FROM movies WHERE tmdb_id = ? AND media_type = 'tv'");
        } else {
            $chk = getDB()->prepare("SELECT id FROM movies WHERE tmdb_id = ? AND COALESCE(media_type,'movie') != 'tv'");
        }
        $chk->execute([$tmdbId]);
        $localDbId = (int)$chk->fetchColumn();
        $inDb = $localDbId > 0;
    }
} elseif ($apiReady && $query !== '') {
    // Normalisiert TV-Felder auf Filmnamen (title, original_title, release_date)
    $normalizeResult = function (array $r): array {
        if (($r['media_type'] ?? 'movie') === 'tv') {
            $r['title']          = $r['name']          ?? $r['title'] ?? '';
            $r['original_title'] = $r['original_name'] ?? $r['title'];
            $r['release_date']   = $r['first_air_date'] ?? '';
        }
        return $r;
    };

    $data    = tmdbGet('search/multi', ['query' => $query, 'language' => $tmdbLang]);
    $results = array_values(array_filter(
        array_map($normalizeResult, $data['results'] ?? []),
        fn($r) => ($r['media_type'] ?? 'movie') !== 'person'
    ));
    // Fallback: wenn weniger als 3 Treffer, auch englisch suchen und zusammenführen
    if (count($results) < 3) {
        $dataEn    = tmdbGet('search/multi', ['query' => $query, 'language' => 'en-US']);
        $enResults = array_values(array_filter(
            array_map($normalizeResult, $dataEn['results'] ?? []),
            fn($r) => ($r['media_type'] ?? 'movie') !== 'person'
        ));
        $seenIds = array_column($results, 'id');
        foreach ($enResults as $r) {
            if (!in_array($r['id'], $seenIds, true)) {
                $results[] = $r;
                $seenIds[] = $r['id'];
            }
        }
    }
    // Lokale DB ebenfalls durchsuchen – jedes Suchwort einzeln prüfen (z.B. "Stephen Kings Es" → findet "Es")
    try {
        $db   = getDB();
        $stopwords = ['der', 'die', 'das', 'des', 'dem', 'den', 'ein', 'eine', 'einer', 'einen',
                      'und', 'oder', 'von', 'the', 'a', 'an', 'of', 'in', 'on', 'at', 'to'];
        $words = array_filter(
            preg_split('/\s+/', mb_strtolower(trim($query))),
            fn($w) => mb_strlen($w) >= 2 && !in_array($w, $stopwords, true)
        );
        if ($words) {
            $conditions = [];
            $params     = [];
            foreach ($words as $w) {
                $esc          = '%' . str_replace(['%', '_'], ['\%', '\_'], $w) . '%';
                $conditions[] = 'LOWER(title) LIKE ? OR LOWER(original_title) LIKE ?';
                $params[]     = $esc;
                $params[]     = $esc;
            }
            $sLocal = $db->prepare(
                "SELECT tmdb_id, title, original_title, title_en, year, poster_path, overview, media_type
                 FROM movies
                 WHERE tmdb_id IS NOT NULL
                   AND (" . implode(' OR ', $conditions) . "
                       OR LOWER(COALESCE(title_en,'')) LIKE ?)
                 LIMIT 20"
            );
            $likeFirst = '%' . str_replace(['%','_'], ['\%','\_'], mb_strtolower($words[array_key_first($words)])) . '%';
            $sLocal->execute(array_merge($params, [$likeFirst]));
            $tmdbIds = array_column($results, 'id');
            foreach ($sLocal->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                if (!in_array((int)$lr['tmdb_id'], $tmdbIds, true)) {
                    array_unshift($results, [
                        'id'             => (int)$lr['tmdb_id'],
                        'title'          => $lr['title'],
                        'original_title' => $lr['original_title'] ?? $lr['title'],
                        'poster_path'    => $lr['poster_path'],
                        'release_date'   => $lr['year'] ? $lr['year'] . '-01-01' : '',
                        'overview'       => $lr['overview'] ?? '',
                        'media_type'     => $lr['media_type'] ?? 'movie',
                        '_local'         => true,
                    ]);
                    $tmdbIds[] = (int)$lr['tmdb_id'];
                }
            }
        }
    } catch (\PDOException $e) {}
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="filmdb-page py-5">
    <div class="container">

        <!-- Header -->
        <div class="mb-4">
            <h1 class="fw-bold mb-1">
                <i class="bi bi-film text-gold me-2"></i>Filmdatenbank
            </h1>
            <p class="text-muted mb-0">Suche nach Filmen und entdecke Details aus der TMDB-Datenbank.</p>
        </div>
        <hr class="mb-4">

        <!-- Search Form -->
        <form method="get" action="/charts.php" class="filmdb-search-form mb-5">
            <div class="input-group input-group-lg">
                <span class="input-group-text filmdb-search-icon">
                    <i class="bi bi-search"></i>
                </span>
                <input type="text" name="q" class="form-control filmdb-search-input"
                       placeholder="Welchen Film suchst du?"
                       autofocus>
                <button type="submit" class="btn btn-gold px-4 fw-semibold">
                    Suchen
                </button>
            </div>
        </form>

        <?php if (!$apiReady): ?>
        <!-- API Key not configured -->
        <div class="alert alert-warning d-flex align-items-start gap-3">
            <i class="bi bi-key-fill fs-4 flex-shrink-0 mt-1"></i>
            <div>
                <strong>TMDB API-Schlüssel fehlt.</strong><br>
                Trage deinen Schlüssel in <code>filmdb/config/db.php</code> ein:<br>
                <code>define('TMDB_API_KEY', 'dein-schlüssel-hier');</code><br>
                <small class="text-muted">Schlüssel beantragen unter
                    <a href="https://www.themoviedb.org/settings/api" target="_blank">themoviedb.org/settings/api</a>
                </small>
            </div>
        </div>

        <?php elseif ($tmdbId > 0 && $detail): ?>
        <!-- ── Detail View ─────────────────────────────────────────────────────── -->

        <?php if ($added): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            Film wurde erfolgreich zum Duell-Pool hinzugefügt!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="filmdb-detail">
            <a href="/charts.php<?= $query ? '?q=' . urlencode($query) : '' ?>"
               class="btn btn-sm btn-outline-secondary mb-4">
                <i class="bi bi-arrow-left me-1"></i>Zurück zur Suche
            </a>
            <div class="row g-5 align-items-start">
                <!-- Poster -->
                <div class="col-md-4 col-lg-3">
                    <?php if (!empty($detail['poster_path'])): ?>
                    <img src="https://image.tmdb.org/t/p/w500<?= e($detail['poster_path']) ?>"
                         alt="<?= e($detail['title']) ?>"
                         class="filmdb-detail-poster"
                         onerror="this.onerror=null;this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=🎬'">
                    <?php else: ?>
                    <div class="filmdb-no-poster d-flex align-items-center justify-content-center">
                        <i class="bi bi-film fs-1 text-muted"></i>
                    </div>
                    <?php endif; ?>

                    <!-- Pool-Button under poster -->
                    <?php if (isLoggedIn()): ?>
                    <div class="mt-3">
                        <?php if ($inDb): ?>
                        <div class="d-flex align-items-center gap-2 text-success small fw-semibold">
                            <i class="bi bi-check-circle-fill"></i>Im Duell-Pool
                        </div>
                        <?php if (isAdmin() && $localDbId): ?>
                        <a href="/admin-filme.php?edit_id=<?= $localDbId ?>"
                           class="btn btn-sm mt-2 w-100"
                           style="background:rgba(232,184,75,.12);border:1px solid rgba(232,184,75,.25);color:#e8b84b;font-size:.75rem;">
                            <i class="bi bi-pencil me-1"></i>In Film-Verwaltung bearbeiten
                        </a>
                        <?php endif; ?>
                        <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="add_tmdb_id" value="<?= $tmdbId ?>">
                            <input type="hidden" name="add_type"    value="<?= e($mediaType) ?>">
                            <input type="hidden" name="return_q"    value="<?= e($query) ?>">
                            <button type="submit" class="btn btn-gold w-100">
                                <i class="bi bi-plus-circle me-2"></i>Zum Duell-Pool hinzufügen
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Info -->
                <div class="col-md-8 col-lg-9">
                    <h2 class="fw-bold mb-1">
                        <?= e($detail['title']) ?>
                        <?php $releaseYear = substr($detail['release_date'] ?? '', 0, 4); ?>
                        <?php if ($releaseYear): ?>
                        <span class="fw-normal fs-4" style="color:#fff">(<?= e($releaseYear) ?>)</span>
                        <?php endif; ?>
                    </h2>

                    <?php if (!empty($detail['original_title']) && $detail['original_title'] !== $detail['title']): ?>
                    <p class="mb-3 fst-italic" style="color:#fff"><?= e($detail['original_title']) ?></p>
                    <?php endif; ?>

                    <!-- Meta: FSK + Release + Runtime + Rating -->
                    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
                        <?php if ($fsk !== null && $fsk !== ''): ?>
                        <div class="filmdb-fsk-badge">
                            <span class="fsk-label">FSK</span>
                            <span class="fsk-value"><?= e($fsk) ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($deRelease): ?>
                        <div class="filmdb-meta-item">
                            <i class="bi bi-calendar3 me-1 text-gold"></i>
                            <?= e(formatGermanDate($deRelease)) ?>
                            <span class="small ms-1" style="color:#fff">(DE)</span>
                        </div>
                        <?php elseif (!empty($detail['release_date'])): ?>
                        <div class="filmdb-meta-item">
                            <i class="bi bi-calendar3 me-1 text-gold"></i>
                            <?= e(formatGermanDate($detail['release_date'])) ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($detail['runtime']) && $detail['runtime'] > 0): ?>
                        <div class="filmdb-meta-item">
                            <i class="bi bi-clock me-1 text-gold"></i>
                            <?= (int)$detail['runtime'] ?> min
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($detail['vote_average'])): ?>
                        <div class="filmdb-meta-item">
                            <i class="bi bi-star-fill me-1 text-gold"></i>
                            <strong><?= number_format($detail['vote_average'], 1) ?></strong>
                            <span class="small ms-1" style="color:#fff">(<?= number_format($detail['vote_count']) ?> TMDB)</span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($detail['imdb_id'])): ?>
                        <div class="filmdb-meta-item">
                            <i class="bi bi-film me-1 text-gold"></i>
                            <a href="https://www.imdb.com/title/<?= e($detail['imdb_id']) ?>/"
                               target="_blank" rel="noopener noreferrer"
                               class="text-decoration-none text-reset">
                                IMDB: <span style="color:#fff"><?= e($detail['imdb_id']) ?></span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Genres -->
                    <?php if (!empty($genres)): ?>
                    <div class="mb-4">
                        <?php foreach ($genres as $g): ?>
                        <span class="badge me-1 mb-1" style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2);"><?= e($g) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Overview -->
                    <?php if (!empty($detail['overview'])): ?>
                    <div class="filmdb-overview">
                        <?= nl2br(e($detail['overview'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php elseif ($tmdbId > 0 && !$detail): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>Film nicht gefunden oder API-Fehler.
        </div>

        <?php elseif ($query !== '' && empty($results)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-search fs-1 d-block mb-3"></i>
            <h5>Keine Ergebnisse für „<?= e($query) ?>"</h5>
            <p>Versuche es mit einem anderen Suchbegriff.</p>
        </div>

        <?php elseif (!empty($results)): ?>
        <!-- ── Search Results ──────────────────────────────────────────────────── -->
        <p class="text-muted small mb-3">
            <?= count($results) ?> Ergebnisse für „<strong><?= e($query) ?></strong>"
        </p>
        <div class="filmdb-results-list">
            <?php foreach ($results as $movie): ?>
            <?php if (empty($movie['id'])) continue; ?>
            <a href="/charts.php?id=<?= (int)$movie['id'] ?>&type=<?= ($movie['media_type'] ?? 'movie') === 'tv' ? 'tv' : 'movie' ?>&q=<?= urlencode($query) ?>"
               class="filmdb-result-row text-decoration-none">
                <div class="filmdb-result-poster-wrap">
                    <?php if (!empty($movie['poster_path'])): ?>
                    <img src="https://image.tmdb.org/t/p/w92<?= e($movie['poster_path']) ?>"
                         alt="<?= e($movie['title']) ?>"
                         class="filmdb-result-poster"
                         onerror="this.onerror=null;this.src='https://placehold.co/60x90/1e3a5f/e8b84b?text=🎬'">
                    <?php else: ?>
                    <div class="filmdb-result-poster filmdb-result-no-poster d-flex align-items-center justify-content-center">
                        <i class="bi bi-film text-muted"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="filmdb-result-info">
                    <div class="filmdb-result-title">
                        <?= e($movie['title']) ?>
                        <?php if (($movie['media_type'] ?? 'movie') === 'tv'): ?>
                        <span class="badge ms-1" style="font-size:.65rem;background:rgba(100,160,255,.2);color:#7ab4ff;vertical-align:middle;">Serie</span>
                        <?php endif; ?>
                    </div>
                    <div class="filmdb-result-meta">
                        <?php if (!empty($movie['release_date'])): ?>
                        <span><?= e(substr($movie['release_date'], 0, 4)) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($movie['original_title']) && $movie['original_title'] !== $movie['title']): ?>
                        <span class="ms-2 fst-italic"><?= e($movie['original_title']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($movie['overview'])): ?>
                    <div class="filmdb-result-overview">
                        <?= e(mb_substr($movie['overview'], 0, 180)) ?>…
                    </div>
                    <?php endif; ?>
                </div>
                <div class="filmdb-result-arrow">
                    <i class="bi bi-chevron-right"></i>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- Default: no search yet -->
        <div class="text-center py-5 text-muted">
            <i class="bi bi-film fs-1 d-block mb-3"></i>
            <h5 class="text-muted">Gib einen Filmtitel ein, um loszulegen</h5>
            <p class="small">Daten werden direkt aus der TMDB-Datenbank geladen.</p>
        </div>
        <?php endif; ?>

    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
