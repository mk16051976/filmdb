<?php
set_time_limit(300);
$pageTitle = 'Film-Import – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

// ── TMDB Helper ───────────────────────────────────────────────────────────────
function tmdbGet(string $endpoint, array $params = []): ?array {
    $params['api_key'] = TMDB_API_KEY;
    $url = 'https://api.themoviedb.org/3/' . ltrim($endpoint, '/') . '?' . http_build_query($params);
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 15,
    ];
    // Windows/XAMPP: CA-Bundle explizit setzen
    $caBundle = ini_get('curl.cainfo') ?: (defined('CURLOPT_CAINFO')
        ? 'C:/xampp/php/extras/ssl/cacert.pem' : '');
    if ($caBundle && file_exists($caBundle)) {
        $opts[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $opts);
    $body   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $errmsg = curl_error($ch);
    $http   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno || !$body) return ['__curl_error' => "$errno: $errmsg"];
    $data = json_decode($body, true);
    if (!is_array($data)) return null;
    if ($http >= 400) $data['__http_error'] = $http;
    return $data;
}

// Fetch full details+credits for multiple TMDB IDs in parallel (curl_multi)
function tmdbGetBatch(array $tmdbIds, array $params = []): array {
    $params['api_key'] = TMDB_API_KEY;
    $results = [];
    foreach (array_chunk($tmdbIds, 10) as $batch) {
        $mh      = curl_multi_init();
        $handles = [];
        $caBundle = ini_get('curl.cainfo') ?: 'C:/xampp/php/extras/ssl/cacert.pem';
        foreach ($batch as $id) {
            $url = 'https://api.themoviedb.org/3/movie/' . $id . '?' . http_build_query($params);
            $ch  = curl_init($url);
            $bOpts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT        => 15,
            ];
            if ($caBundle && file_exists($caBundle)) $bOpts[CURLOPT_CAINFO] = $caBundle;
            curl_setopt_array($ch, $bOpts);
            $handles[$id] = $ch;
            curl_multi_add_handle($mh, $ch);
        }
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) curl_multi_select($mh, 0.5);
        } while ($running > 0);
        foreach ($handles as $id => $ch) {
            $body = curl_multi_getcontent($ch);
            $data = $body ? json_decode($body, true) : null;
            if ($data) $results[$id] = $data;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $results;
}

$apiReady = defined('TMDB_API_KEY') && TMDB_API_KEY !== '' && TMDB_API_KEY !== 'DEIN_API_KEY_HIER';

$result             = null;
$imdbResult         = null;
$beforeCount        = 0;
$personResults      = [];
$personImportResult = null;
$personSearchQuery  = '';

// ── Person: Suche ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiReady && ($_POST['action'] ?? '') === 'person_search') {
    $personSearchQuery = trim($_POST['person_query'] ?? '');
    if ($personSearchQuery !== '') {
        $data          = tmdbGet('search/person', ['query' => $personSearchQuery, 'language' => 'de-DE', 'page' => 1]);
        $personResults = array_slice($data['results'] ?? [], 0, 8);
    }
}

// ── Person: Import ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiReady && ($_POST['action'] ?? '') === 'person_import') {
    $db        = getDB();
    $personId  = (int)($_POST['person_id'] ?? 0);
    $mediaType = in_array($_POST['person_media'] ?? '', ['movie', 'tv', 'both']) ? $_POST['person_media'] : 'both';
    $dept      = in_array($_POST['person_dept'] ?? '', ['cast', 'director', 'producer', 'all']) ? $_POST['person_dept'] : 'all';

    $db->exec("ALTER TABLE movies
        ADD COLUMN IF NOT EXISTS director   VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS actors     TEXT         NULL,
        ADD COLUMN IF NOT EXISTS country    VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS imdb_id    VARCHAR(20)  NULL,
        ADD COLUMN IF NOT EXISTS media_type VARCHAR(10)  NULL");

    $genreMap = $genreMapTv = [];
    foreach ((tmdbGet('genre/movie/list', ['language' => 'de-DE'])['genres'] ?? []) as $g) $genreMap[$g['id']] = $g['name'];
    foreach ((tmdbGet('genre/tv/list',    ['language' => 'de-DE'])['genres'] ?? []) as $g) $genreMapTv[$g['id']] = $g['name'];

    $checkMovie = $db->prepare("SELECT id FROM movies WHERE tmdb_id = ? AND COALESCE(media_type,'movie') != 'tv'");
    $checkTv    = $db->prepare("SELECT id FROM movies WHERE tmdb_id = ? AND media_type = 'tv'");
    $insStmt    = $db->prepare('INSERT INTO movies (title, original_title, year, genre, tmdb_id, poster_path, overview, director, actors, country, imdb_id, media_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    // Sammle TMDB-IDs aus Credits
    $toImportMovies = []; // tmdb_id => basic
    $toImportTv     = [];

    if (in_array($mediaType, ['movie', 'both'])) {
        $mc = tmdbGet("person/{$personId}/movie_credits", ['language' => 'de-DE']) ?? [];
        if ($dept === 'cast' || $dept === 'all') {
            foreach ($mc['cast'] ?? [] as $m) {
                if (!empty($m['id']) && !empty($m['title'])) $toImportMovies[$m['id']] = $m;
            }
        }
        if ($dept === 'director' || $dept === 'all') {
            foreach ($mc['crew'] ?? [] as $m) {
                if (!empty($m['id']) && !empty($m['title']) && $m['job'] === 'Director') $toImportMovies[$m['id']] = $m;
            }
        }
        if ($dept === 'producer' || $dept === 'all') {
            foreach ($mc['crew'] ?? [] as $m) {
                if (!empty($m['id']) && !empty($m['title']) && $m['department'] === 'Production') $toImportMovies[$m['id']] = $m;
            }
        }
    }
    if (in_array($mediaType, ['tv', 'both'])) {
        $tc = tmdbGet("person/{$personId}/tv_credits", ['language' => 'de-DE']) ?? [];
        if ($dept === 'cast' || $dept === 'all') {
            foreach ($tc['cast'] ?? [] as $m) {
                if (!empty($m['id']) && !empty($m['name'])) $toImportTv[$m['id']] = $m;
            }
        }
        if ($dept === 'director' || $dept === 'all') {
            foreach ($tc['crew'] ?? [] as $m) {
                if (!empty($m['id']) && !empty($m['name']) && $m['job'] === 'Director') $toImportTv[$m['id']] = $m;
            }
        }
        if ($dept === 'producer' || $dept === 'all') {
            foreach ($tc['crew'] ?? [] as $m) {
                if (!empty($m['id']) && !empty($m['name']) && $m['department'] === 'Production') $toImportTv[$m['id']] = $m;
            }
        }
    }

    // Bereits vorhandene herausfiltern
    $toFetchMovies = []; $toFetchTv = []; $pSkipped = 0;
    foreach (array_keys($toImportMovies) as $id) {
        $checkMovie->execute([$id]);
        if ($checkMovie->fetchColumn()) { $pSkipped++; }
        else                            { $toFetchMovies[] = $id; }
    }
    foreach (array_keys($toImportTv) as $id) {
        $checkTv->execute([$id]);
        if ($checkTv->fetchColumn()) { $pSkipped++; }
        else                         { $toFetchTv[] = $id; }
    }

    // Limit: max. 300 neue Einträge pro Aufruf
    $totalNew      = count($toFetchMovies) + count($toFetchTv);
    $maxImport     = 300;
    $pLimited      = $totalNew > $maxImport;
    $toFetchMovies = array_slice($toFetchMovies, 0, $maxImport);
    $toFetchTv     = array_slice($toFetchTv, 0, max(0, $maxImport - count($toFetchMovies)));

    $pAdded = 0; $pErrors = 0; $pAddedItems = [];

    // Filme: Batch-Fetch (parallel)
    if (!empty($toFetchMovies)) {
        $details = tmdbGetBatch($toFetchMovies, ['language' => 'de-DE', 'append_to_response' => 'credits']);
        foreach ($toFetchMovies as $tmdbId) {
            $basic  = $toImportMovies[$tmdbId];
            $detail = $details[$tmdbId] ?? [];
            $director = null;
            foreach ($detail['credits']['crew'] ?? [] as $crew) {
                if ($crew['job'] === 'Director') { $director = $crew['name']; break; }
            }
            $actors  = implode(', ', array_slice(array_column($detail['credits']['cast'] ?? [], 'name'), 0, 10)) ?: null;
            $country = translateProductionCountries($detail['production_countries'] ?? []) ?: null;
            $imdbId  = $detail['imdb_id'] ?? null;
            $genres  = implode(', ', array_filter(array_map(fn($id) => $genreMap[$id] ?? '', $basic['genre_ids'] ?? [])));
            $year    = !empty($basic['release_date']) ? (int)substr($basic['release_date'], 0, 4) : null;
            try {
                $insStmt->execute([$basic['title'], $basic['original_title'] ?? $basic['title'],
                    $year, $genres ?: null, $tmdbId, $basic['poster_path'] ?? null,
                    $basic['overview'] ?? null, $director, $actors, $country, $imdbId, null]);
                $pAdded++;
                $pAddedItems[] = ['title' => $basic['title'], 'year' => $year, 'is_tv' => false, 'poster' => $basic['poster_path'] ?? null];
            } catch (\Exception $e) { $pErrors++; }
        }
    }

    // Serien: sequentiell (TMDB hat keinen Batch-Endpunkt für TV)
    foreach ($toFetchTv as $tmdbId) {
        $basic  = $toImportTv[$tmdbId];
        $detail = tmdbGet("tv/{$tmdbId}", ['language' => 'de-DE', 'append_to_response' => 'credits,external_ids']);
        usleep(80000);
        if (!$detail || isset($detail['__curl_error'])) { $pErrors++; continue; }
        $creator = $detail['created_by'][0]['name'] ?? null;
        $actors  = implode(', ', array_slice(array_column($detail['credits']['cast'] ?? [], 'name'), 0, 10)) ?: null;
        $country = translateProductionCountries($detail['production_countries'] ?? []) ?: null;
        $imdbId  = $detail['external_ids']['imdb_id'] ?? null;
        $genres  = implode(', ', array_filter(array_map(fn($id) => $genreMapTv[$id] ?? '', $basic['genre_ids'] ?? [])));
        $year    = !empty($basic['first_air_date']) ? (int)substr($basic['first_air_date'], 0, 4) : null;
        $title   = $basic['name'] ?? $basic['original_name'] ?? '';
        try {
            $insStmt->execute([$title, $basic['original_name'] ?? $title,
                $year, $genres ?: null, $tmdbId, $basic['poster_path'] ?? null,
                $basic['overview'] ?? null, $creator, $actors, $country, $imdbId, 'tv']);
            $pAdded++;
            $pAddedItems[] = ['title' => $title, 'year' => $year, 'is_tv' => true, 'poster' => $basic['poster_path'] ?? null];
        } catch (\Exception $e) { $pErrors++; }
    }

    $personImportResult = [
        'added'    => $pAdded,
        'skipped'  => $pSkipped,
        'errors'   => $pErrors,
        'total_new'=> $totalNew,
        'limited'  => $pLimited,
        'items'    => array_slice($pAddedItems, 0, 12),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiReady && ($_POST['action'] ?? '') === 'imdb_lookup') {
    $db = getDB();

    $db->exec("ALTER TABLE movies
        ADD COLUMN IF NOT EXISTS director   VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS actors     TEXT         NULL,
        ADD COLUMN IF NOT EXISTS country    VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS imdb_id    VARCHAR(20)  NULL,
        ADD COLUMN IF NOT EXISTS media_type VARCHAR(10)  NULL");

    // Genre maps (Filme + Serien)
    $genreMap   = [];
    $genreMapTv = [];
    $genreData  = tmdbGet('genre/movie/list', ['language' => 'de-DE']);
    foreach ($genreData['genres'] ?? [] as $g) { $genreMap[(int)$g['id']] = $g['name']; }
    $genreDataTv = tmdbGet('genre/tv/list', ['language' => 'de-DE']);
    foreach ($genreDataTv['genres'] ?? [] as $g) { $genreMapTv[(int)$g['id']] = $g['name']; }

    $checkStmt = $db->prepare('SELECT id, title FROM movies WHERE tmdb_id = ?');
    $insStmt   = $db->prepare(
        'INSERT INTO movies (title, original_title, year, genre, tmdb_id, poster_path, overview, director, actors, country, imdb_id, media_type)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    // Split input on semicolons, normalize each ID
    $rawInput = trim($_POST['imdb_id'] ?? '');
    $rawIds   = array_filter(array_map('trim', explode(';', $rawInput)));

    $imdbResults = [];
    foreach ($rawIds as $rawId) {
        $rawId = preg_replace('/\s+/', '', $rawId);
        if (!preg_match('/^tt\d+$/i', $rawId)) {
            $rawId = 'tt' . ltrim($rawId, 'tT');
        }
        $rawId = strtolower($rawId);

        $findData = tmdbGet("find/{$rawId}", ['external_source' => 'imdb_id', 'language' => 'de-DE']);

        if (!$findData || isset($findData['__curl_error'])) {
            $errDetail = $findData['__curl_error'] ?? 'Verbindung fehlgeschlagen';
            $imdbResults[] = ['ok' => false, 'reason' => 'api_error', 'detail' => $errDetail, 'imdb_id' => $rawId];
            continue;
        }
        if (isset($findData['__http_error'])) {
            $imdbResults[] = ['ok' => false, 'reason' => 'api_error', 'detail' => 'HTTP ' . $findData['__http_error'], 'imdb_id' => $rawId];
            continue;
        }

        $movieRow = $findData['movie_results'][0] ?? null;
        $tvRow    = $findData['tv_results'][0]    ?? null;

        if (!$movieRow && !$tvRow) {
            $imdbResults[] = ['ok' => false, 'reason' => 'not_found', 'imdb_id' => $rawId];
            continue;
        }

        $isTv   = ($movieRow === null);
        $row    = $isTv ? $tvRow : $movieRow;
        $tmdbId = (int)$row['id'];

        $checkStmt->execute([$tmdbId]);
        if ($existing = $checkStmt->fetch()) {
            $imdbResults[] = ['ok' => false, 'reason' => 'exists', 'title' => $existing['title'], 'imdb_id' => $rawId];
            continue;
        }

        if ($isTv) {
            // ── TV-Serie ─────────────────────────────────────────────────────
            $detail = tmdbGet("tv/{$tmdbId}", [
                'language'           => 'de-DE',
                'append_to_response' => 'credits,external_ids',
            ]);
            $creator  = $detail['created_by'][0]['name'] ?? null;
            $actors   = implode(', ', array_slice(array_column($detail['credits']['cast'] ?? [], 'name'), 0, 10)) ?: null;
            $country  = translateProductionCountries($detail['production_countries'] ?? []) ?: null;
            $imdbId   = !empty($detail['external_ids']['imdb_id']) ? $detail['external_ids']['imdb_id'] : $rawId;
            $genres   = implode(', ', array_filter(
                array_map(fn($id) => $genreMapTv[(int)$id] ?? '', $row['genre_ids'] ?? [])
            ));
            $title    = $row['name']          ?? $row['original_name'] ?? '';
            $origTitle= $row['original_name'] ?? $title;
            $year     = !empty($row['first_air_date']) ? (int)substr($row['first_air_date'], 0, 4) : null;

            $insStmt->execute([$title, $origTitle, $year, $genres ?: null, $tmdbId,
                $row['poster_path'] ?? null, $row['overview'] ?? null,
                $creator, $actors, $country, $imdbId, 'tv']);
        } else {
            // ── Film ─────────────────────────────────────────────────────────
            $detail = tmdbGet("movie/{$tmdbId}", [
                'language'           => 'de-DE',
                'append_to_response' => 'credits',
            ]);
            $director = null;
            foreach ($detail['credits']['crew'] ?? [] as $crew) {
                if ($crew['job'] === 'Director') { $director = $crew['name']; break; }
            }
            $actors  = implode(', ', array_slice(array_column($detail['credits']['cast'] ?? [], 'name'), 0, 10)) ?: null;
            $country = translateProductionCountries($detail['production_countries'] ?? []) ?: null;
            $imdbId  = !empty($detail['imdb_id']) ? $detail['imdb_id'] : $rawId;
            $genres  = implode(', ', array_filter(
                array_map(fn($id) => $genreMap[(int)$id] ?? '', $row['genre_ids'] ?? [])
            ));
            $title   = $row['title'];
            $year    = !empty($row['release_date']) ? (int)substr($row['release_date'], 0, 4) : null;

            $insStmt->execute([$title, $row['original_title'] ?? $title, $year, $genres ?: null,
                $tmdbId, $row['poster_path'] ?? null, $row['overview'] ?? null,
                $director, $actors, $country, $imdbId, null]);
        }

        $imdbResults[] = [
            'ok'      => true,
            'title'   => $title,
            'year'    => $year,
            'tmdb_id' => $tmdbId,
            'imdb_id' => $imdbId,
            'poster'  => $row['poster_path'] ?? null,
            'is_tv'   => $isTv,
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiReady && ($_POST['action'] ?? '') !== 'imdb_lookup') {
    $validLists  = ['popular', 'top_rated', 'now_playing', 'upcoming'];
    $chosenLists = array_intersect($_POST['lists'] ?? ['popular', 'top_rated'], $validLists);
    if (empty($chosenLists)) $chosenLists = ['top_rated'];
    $pages = min(10, max(1, (int)($_POST['pages'] ?? 5)));

    $db = getDB();
    $beforeCount = (int)$db->query('SELECT COUNT(*) FROM movies')->fetchColumn();

    // Ensure new columns exist (idempotent – safe to run repeatedly)
    $db->exec("ALTER TABLE movies
        ADD COLUMN IF NOT EXISTS director VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS actors   TEXT         NULL,
        ADD COLUMN IF NOT EXISTS country  VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS imdb_id  VARCHAR(20)  NULL");

    // Fetch genre name map from TMDB (one call)
    $genreMap  = [];
    $genreData = tmdbGet('genre/movie/list', ['language' => 'de-DE']);
    foreach ($genreData['genres'] ?? [] as $g) {
        $genreMap[(int)$g['id']] = $g['name'];
    }

    $added   = 0;
    $skipped = 0;
    $errors  = 0;

    // ── Step 1: Collect movies not yet in DB ──────────────────────────────────
    $checkStmt = $db->prepare('SELECT id FROM movies WHERE tmdb_id = ?');
    $toImport  = []; // [tmdb_id => basic list-result data]

    foreach ($chosenLists as $list) {
        for ($page = 1; $page <= $pages; $page++) {
            $data = tmdbGet("movie/{$list}", [
                'language' => 'de-DE',
                'page'     => $page,
                'region'   => 'DE',
            ]);
            foreach ($data['results'] ?? [] as $movie) {
                if (empty($movie['id']) || empty($movie['title'])) continue;
                $tmdbId = (int)$movie['id'];
                if (isset($toImport[$tmdbId])) continue; // already queued this run
                $checkStmt->execute([$tmdbId]);
                if ($checkStmt->fetchColumn()) { $skipped++; continue; }
                $toImport[$tmdbId] = $movie;
            }
        }
    }

    // ── Step 2: Batch-fetch details + credits (parallel, 10 at a time) ────────
    $details = tmdbGetBatch(array_keys($toImport), [
        'language'           => 'de-DE',
        'append_to_response' => 'credits',
    ]);

    // ── Step 3: Insert with all fields ────────────────────────────────────────
    $insStmt = $db->prepare(
        'INSERT INTO movies
             (title, original_title, year, genre, tmdb_id, poster_path, overview, director, actors, country, imdb_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($toImport as $tmdbId => $movie) {
        $detail = $details[$tmdbId] ?? [];

        // Director (first crew member with job "Director")
        $director = null;
        foreach ($detail['credits']['crew'] ?? [] as $crew) {
            if ($crew['job'] === 'Director') { $director = $crew['name']; break; }
        }

        // Top 10 cast names
        $actors = implode(', ', array_slice(
            array_column($detail['credits']['cast'] ?? [], 'name'), 0, 10
        )) ?: null;

        // Production countries
        $country = translateProductionCountries($detail['production_countries'] ?? []) ?: null;

        // IMDB ID (e.g. "tt1234567") – included in TMDB detail response
        $imdbId = !empty($detail['imdb_id']) ? $detail['imdb_id'] : null;

        $genres = implode(', ', array_filter(
            array_map(fn($id) => $genreMap[(int)$id] ?? '', $movie['genre_ids'] ?? [])
        ));
        $year = !empty($movie['release_date'])
            ? (int)substr($movie['release_date'], 0, 4)
            : null;

        try {
            $insStmt->execute([
                $movie['title'],
                $movie['original_title'] ?? $movie['title'],
                $year,
                $genres ?: null,
                $tmdbId,
                $movie['poster_path'] ?? null,
                $movie['overview'] ?? null,
                $director,
                $actors,
                $country,
                $imdbId,
            ]);
            $added++;
        } catch (Exception $e) {
            $errors++;
        }
    }

    $afterCount = (int)$db->query('SELECT COUNT(*) FROM movies')->fetchColumn();
    $result     = compact('added', 'skipped', 'errors', 'afterCount');
}

$currentMovieCount = (int)getDB()->query('SELECT COUNT(*) FROM movies')->fetchColumn();

require_once __DIR__ . '/includes/header.php';
?>

<main class="py-5">
    <div class="container" style="max-width: 760px;">

        <div class="mb-4">
            <h1 class="fw-bold mb-1">
                <i class="bi bi-cloud-download text-gold me-2"></i>Film-Import aus TMDB
            </h1>
            <p class="text-muted mb-0">
                Importiere Filme aus der TMDB-Datenbank in den lokalen Duell-Pool.
                Aktuell: <strong><?= number_format($currentMovieCount) ?> Filme</strong> in der Datenbank.
            </p>
        </div>
        <hr class="mb-4">

        <?php if (!$apiReady): ?>
        <div class="alert alert-warning">
            <i class="bi bi-key-fill me-2"></i>
            TMDB API-Schlüssel fehlt. Bitte in <code>filmdb/config/db.php</code> eintragen.
        </div>
        <?php endif; ?>

        <?php if ($result): ?>
        <div class="alert alert-success d-flex align-items-start gap-3 mb-4">
            <i class="bi bi-check-circle-fill fs-4 flex-shrink-0 mt-1"></i>
            <div>
                <strong>Import abgeschlossen!</strong><br>
                <span class="text-success fw-semibold"><?= $result['added'] ?> neue Filme</span> importiert ·
                <?= $result['skipped'] ?> bereits vorhanden
                <?php if ($result['errors'] > 0): ?>
                · <span class="text-danger"><?= $result['errors'] ?> Fehler</span>
                <?php endif; ?>
                <br>
                <span class="small text-muted">Gesamt jetzt: <strong><?= number_format($result['afterCount']) ?> Filme</strong> im Duell-Pool</span>
            </div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="post" id="import-form">

                    <!-- Listen auswählen -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-3">
                            <i class="bi bi-list-check me-2 text-gold"></i>Welche Listen importieren?
                        </label>
                        <div class="row g-2">
                            <div class="col-6 col-md-3">
                                <div class="form-check import-list-card">
                                    <input class="form-check-input" type="checkbox" name="lists[]"
                                           value="top_rated" id="list_top" checked>
                                    <label class="form-check-label d-block" for="list_top">
                                        <i class="bi bi-star-fill text-gold d-block fs-4 mb-1"></i>
                                        <strong>Top bewertet</strong>
                                        <small class="text-muted d-block">Klassiker & Meisterwerke</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="form-check import-list-card">
                                    <input class="form-check-input" type="checkbox" name="lists[]"
                                           value="popular" id="list_pop" checked>
                                    <label class="form-check-label d-block" for="list_pop">
                                        <i class="bi bi-fire text-gold d-block fs-4 mb-1"></i>
                                        <strong>Beliebt</strong>
                                        <small class="text-muted d-block">Aktuell populär</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="form-check import-list-card">
                                    <input class="form-check-input" type="checkbox" name="lists[]"
                                           value="now_playing" id="list_now">
                                    <label class="form-check-label d-block" for="list_now">
                                        <i class="bi bi-camera-reels text-gold d-block fs-4 mb-1"></i>
                                        <strong>Im Kino</strong>
                                        <small class="text-muted d-block">Aktuell im Kino</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="form-check import-list-card">
                                    <input class="form-check-input" type="checkbox" name="lists[]"
                                           value="upcoming" id="list_soon">
                                    <label class="form-check-label d-block" for="list_soon">
                                        <i class="bi bi-calendar-event text-gold d-block fs-4 mb-1"></i>
                                        <strong>Demnächst</strong>
                                        <small class="text-muted d-block">Kommende Filme</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Seiten pro Liste -->
                    <div class="mb-4">
                        <label for="pages" class="form-label fw-semibold">
                            <i class="bi bi-layers me-2 text-gold"></i>Seiten pro Liste
                            <span class="text-muted fw-normal">(je 20 Filme)</span>
                        </label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="range" class="form-range flex-grow-1" name="pages" id="pages"
                                   min="1" max="10" value="5" oninput="document.getElementById('pages-val').textContent = this.value + ' Seiten ≈ ' + (this.value * 20) + ' Filme'">
                            <span class="text-gold fw-semibold text-nowrap" id="pages-val" style="min-width:160px">
                                5 Seiten ≈ 100 Filme
                            </span>
                        </div>
                        <small class="text-muted">
                            Bei 2 Listen und 5 Seiten werden bis zu 200 Filme verarbeitet (Duplikate werden übersprungen).
                        </small>
                    </div>

                    <!-- Submit -->
                    <div class="d-flex gap-3 align-items-center">
                        <button type="submit" class="btn btn-gold btn-lg px-4" id="import-btn">
                            <i class="bi bi-cloud-download me-2"></i>Jetzt importieren
                        </button>
                        <a href="/charts.php" class="btn btn-outline-secondary">
                            Filmdatenbank
                        </a>
                    </div>

                </form>
            </div>
        </div>

        <!-- ── IMDb-Suche ── -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body p-4">
                <h5 class="fw-semibold mb-1">
                    <i class="bi bi-search text-gold me-2"></i>Film per IMDb-ID importieren
                </h5>
                <p class="text-muted small mb-3">
                    Einen oder mehrere Filme direkt per IMDb-ID importieren. Mehrere IDs mit Semikolon trennen,
                    z.&nbsp;B. <code>tt0111161; tt0133093; tt0068646</code>
                </p>

                <?php if (!empty($imdbResults)): ?>
                    <?php
                    $countOk     = count(array_filter($imdbResults, fn($r) => $r['ok']));
                    $countFailed = count($imdbResults) - $countOk;
                    ?>
                    <?php if (count($imdbResults) > 1): ?>
                    <div class="alert alert-secondary small mb-2 py-2">
                        <?= count($imdbResults) ?> IDs verarbeitet &mdash;
                        <strong class="text-success"><?= $countOk ?> importiert</strong>
                        <?php if ($countFailed): ?>, <strong class="text-warning"><?= $countFailed ?> übersprungen</strong><?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($imdbResults as $r): ?>
                        <?php if ($r['ok']): ?>
                        <div class="alert alert-success d-flex align-items-center gap-3 py-2 mb-2">
                            <?php if ($r['poster']): ?>
                            <img src="https://image.tmdb.org/t/p/w92<?= e($r['poster']) ?>"
                                 alt="" style="height:52px; border-radius:4px;" onerror="this.style.display='none'">
                            <?php endif; ?>
                            <div>
                                <strong><?= e($r['title']) ?></strong>
                                <?php if ($r['year']): ?>(<?= $r['year'] ?>)<?php endif; ?>
                                <?php if (!empty($r['is_tv'])): ?>
                                <span class="badge bg-secondary ms-1" style="font-size:.7rem;">Serie</span>
                                <?php endif; ?>
                                erfolgreich importiert.<br>
                                <span class="small text-muted">TMDB: <?= $r['tmdb_id'] ?> · IMDb: <?= e($r['imdb_id']) ?></span>
                            </div>
                        </div>
                        <?php elseif ($r['reason'] === 'exists'): ?>
                        <div class="alert alert-warning py-2 mb-2">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong><?= e($r['title']) ?></strong> (<?= e($r['imdb_id']) ?>) ist bereits vorhanden.
                        </div>
                        <?php elseif ($r['reason'] === 'tv'): ?>
                        <div class="alert alert-warning py-2 mb-2">
                            <i class="bi bi-tv me-2"></i>
                            <strong><?= e($r['imdb_id']) ?></strong> ist eine Serie/TV-Sendung, kein Film.
                        </div>
                        <?php elseif ($r['reason'] === 'api_error'): ?>
                        <div class="alert alert-danger py-2 mb-2">
                            <i class="bi bi-wifi-off me-2"></i>
                            <strong><?= e($r['imdb_id']) ?></strong>: TMDB-API-Fehler –
                            <span class="small"><?= e($r['detail'] ?? 'unbekannt') ?></span>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-danger py-2 mb-2">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong><?= e($r['imdb_id']) ?></strong> wurde in TMDB nicht gefunden.
                            <span class="small text-muted ms-1">(Film existiert möglicherweise nicht in der TMDB-Datenbank)</span>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>

                <form method="post" class="d-flex gap-2 align-items-start flex-wrap mt-2">
                    <input type="hidden" name="action" value="imdb_lookup">
                    <div style="flex:1; min-width:220px;">
                        <input type="text" name="imdb_id" class="form-control"
                               placeholder="tt0111161; tt0133093; tt0068646"
                               required>
                    </div>
                    <button type="submit" class="btn btn-gold btn-sm px-4" style="white-space:nowrap; width:auto; padding:8px 20px;">
                        <i class="bi bi-search me-1"></i>Suchen &amp; Importieren
                    </button>
                </form>
            </div>
        </div>

        <!-- ── Person-Import ── -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body p-4">
                <h5 class="fw-semibold mb-1">
                    <i class="bi bi-person-video3 text-gold me-2"></i>Alle Filme/Serien einer Person importieren
                </h5>
                <p class="text-muted small mb-3">
                    Alle Werke eines Regisseurs, Darstellers oder Produzenten auf einmal importieren.
                    Bereits vorhandene Einträge (per TMDB-ID) werden übersprungen.
                </p>

                <?php if ($personImportResult): ?>
                <div class="alert <?= $personImportResult['added'] > 0 ? 'alert-success' : 'alert-secondary' ?> mb-3">
                    <strong><?= $personImportResult['added'] ?> neue Einträge importiert</strong>
                    · <?= $personImportResult['skipped'] ?> bereits vorhanden
                    <?php if ($personImportResult['errors']): ?> · <span class="text-danger"><?= $personImportResult['errors'] ?> Fehler</span><?php endif; ?>
                    <?php if ($personImportResult['limited']): ?>
                    <br><small class="text-warning">⚠ Limit von 300 Einträgen pro Import wurde angewendet (<?= $personImportResult['total_new'] ?> wären insgesamt neu gewesen).</small>
                    <?php endif; ?>
                    <?php if (!empty($personImportResult['items'])): ?>
                    <div class="mt-2 d-flex flex-wrap gap-2">
                        <?php foreach ($personImportResult['items'] as $it): ?>
                        <span class="badge" style="background:rgba(0,0,0,.15); color:inherit; font-weight:400;">
                            <?php if ($it['poster']): ?>
                            <img src="https://image.tmdb.org/t/p/w45<?= e($it['poster']) ?>" alt=""
                                 style="height:20px; border-radius:2px; vertical-align:middle; margin-right:4px;" onerror="this.style.display='none'">
                            <?php endif; ?>
                            <?= e($it['title']) ?><?= $it['year'] ? ' ('.$it['year'].')' : '' ?>
                            <?php if ($it['is_tv']): ?><span class="ms-1" style="font-size:.7em;opacity:.7;">Serie</span><?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                        <?php if ($personImportResult['added'] > count($personImportResult['items'])): ?>
                        <span class="badge" style="background:rgba(0,0,0,.1); color:inherit;">…+<?= $personImportResult['added'] - count($personImportResult['items']) ?> weitere</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Suchformular -->
                <form method="post" class="mb-3" id="person-search-form">
                    <input type="hidden" name="action" value="person_search">
                    <div class="d-flex gap-2">
                        <input type="text" name="person_query" class="form-control"
                               placeholder="Name der Person (z. B. Steven Spielberg)"
                               value="<?= e($personSearchQuery) ?>" required>
                        <button type="submit" class="btn btn-outline-secondary px-3" style="white-space:nowrap;">
                            <i class="bi bi-search me-1"></i>Suchen
                        </button>
                    </div>
                </form>

                <?php if (!empty($personResults)): ?>
                <div class="mt-2">
                    <?php foreach ($personResults as $p):
                        $knownFor = implode(', ', array_slice(array_map(
                            fn($k) => $k['title'] ?? $k['name'] ?? '', $p['known_for'] ?? []
                        ), 0, 3));
                        $kd = $p['known_for_department'] ?? '';
                        $deptLabel = $kd === 'Directing'  ? 'Regisseur'
                                   : ($kd === 'Acting'     ? 'Darsteller'
                                   : ($kd === 'Production' ? 'Produzent'
                                   : ($kd === 'Writing'    ? 'Drehbuch'
                                   : ($kd !== '' ? $kd : '–'))));
                    ?>
                    <div class="d-flex align-items-center gap-3 p-3 mb-2 rounded"
                         style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09);">
                        <?php if (!empty($p['profile_path'])): ?>
                        <img src="https://image.tmdb.org/t/p/w45<?= e($p['profile_path']) ?>" alt=""
                             style="width:45px; height:60px; object-fit:cover; border-radius:6px; flex-shrink:0;"
                             onerror="this.style.display='none'">
                        <?php else: ?>
                        <div style="width:45px; height:60px; background:rgba(255,255,255,.08); border-radius:6px; flex-shrink:0; display:flex; align-items:center; justify-content:center;">
                            <i class="bi bi-person" style="font-size:1.4rem; opacity:.3;"></i>
                        </div>
                        <?php endif; ?>

                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold"><?= e($p['name']) ?></div>
                            <div class="small text-muted"><?= e($deptLabel) ?><?= $knownFor ? ' · bekannt für: ' . e($knownFor) : '' ?></div>
                        </div>

                        <form method="post" class="d-flex gap-2 align-items-center flex-wrap flex-shrink-0">
                            <input type="hidden" name="action"     value="person_import">
                            <input type="hidden" name="person_id"  value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="person_query" value="<?= e($personSearchQuery) ?>">
                            <select name="person_dept" class="form-select form-select-sm"
                                    style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.2); color:#fff; min-width:130px;">
                                <option value="all">Alle Rollen</option>
                                <option value="cast" <?= ($p['known_for_department'] ?? '') === 'Acting' ? 'selected' : '' ?>>Nur als Darsteller</option>
                                <option value="director" <?= ($p['known_for_department'] ?? '') === 'Directing' ? 'selected' : '' ?>>Nur als Regisseur</option>
                                <option value="producer" <?= ($p['known_for_department'] ?? '') === 'Production' ? 'selected' : '' ?>>Nur als Produzent</option>
                            </select>
                            <select name="person_media" class="form-select form-select-sm"
                                    style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.2); color:#fff; min-width:100px;">
                                <option value="both">Filme + Serien</option>
                                <option value="movie">Nur Filme</option>
                                <option value="tv">Nur Serien</option>
                            </select>
                            <button type="submit" class="btn btn-gold btn-sm px-3">
                                <i class="bi bi-cloud-download me-1"></i>Importieren
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($personSearchQuery !== ''): ?>
                <p class="text-muted small">Keine Person gefunden für „<?= e($personSearchQuery) ?>".</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Info box -->
        <div class="alert alert-light border mt-4 small text-muted">
            <i class="bi bi-info-circle me-2"></i>
            Importierte Filme stehen sofort im <strong>1v1-Duell</strong> zur Verfügung. Bereits vorhandene Filme
            (erkannt per TMDB-ID) werden übersprungen. Cover werden direkt von TMDB geladen – keine lokale Speicherung nötig.
        </div>

    </div>
</main>

<style>
.import-list-card {
    background: #f7f8fa;
    border-radius: 12px;
    padding: 1rem;
    border: 2px solid #e5e7eb;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    margin: 0;
}
.import-list-card:hover {
    border-color: var(--mkfb-gold);
    background: #fffbf0;
}
.import-list-card .form-check-input {
    float: none;
    margin: 0 0 .5rem 0;
}
.import-list-card:has(.form-check-input:checked) {
    border-color: var(--mkfb-gold);
    background: #fffbf0;
}
</style>

<script>
document.getElementById('import-form').addEventListener('submit', function () {
    const btn = document.getElementById('import-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Wird importiert…';
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
