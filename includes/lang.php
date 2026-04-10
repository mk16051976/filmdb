<?php
/**
 * MKFB Internationalization (i18n)
 * Usage: t('key') or t('key', ['var' => 'val'])
 */

function currentLang(): string {
    return ($_SESSION['lang'] ?? 'de') === 'en' ? 'en' : 'de';
}

function t(string $key, array $vars = []): string {
    static $strings = null;
    if ($strings === null) {
        $lang = currentLang();
        $strings = require __DIR__ . '/lang/' . $lang . '.php';
    }
    $str = $strings[$key] ?? $key;
    foreach ($vars as $k => $v) {
        $str = str_replace('{' . $k . '}', $v, $str);
    }
    return $str;
}

// Movie helpers – returns EN or DE field based on session lang
function movieTitle(array $film): string {
    if (currentLang() === 'en') {
        // Prefer fetched English title, fallback to original_title, then German title
        return $film['title_en'] ?? $film['original_title'] ?? $film['title'] ?? '';
    }
    return $film['title'] ?? $film['original_title'] ?? '';
}

function movieOverview(array $film): string {
    if (currentLang() === 'en' && !empty($film['overview_en'])) {
        return $film['overview_en'];
    }
    return $film['overview'] ?? '';
}

/**
 * Returns the TMDB poster URL in the correct language.
 * $size = TMDB size suffix, e.g. 'w92', 'w185', 'w500'
 */
function moviePosterUrl(array $film, string $size = 'w500'): string {
    // 1. Lokales Cover hat immer Vorrang
    if (!empty($film['imdb_id'])) {
        static $coverCache = [];
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $film['imdb_id']);
        if ($safe) {
            if (!array_key_exists($safe, $coverCache)) {
                $coverCache[$safe] = file_exists(__DIR__ . '/../cover/' . $safe . '.jpg')
                    ? '/cover/' . $safe . '.jpg' : null;
            }
            if ($coverCache[$safe]) return $coverCache[$safe];
        }
    }
    // 2. TMDB-Poster (sprachabhängig)
    $base = 'https://image.tmdb.org/t/p/' . $size;
    if (currentLang() === 'en' && !empty($film['poster_path_en'])) {
        return $base . $film['poster_path_en'];
    }
    if (!empty($film['poster_path'])) {
        return $base . $film['poster_path'];
    }
    // 3. Platzhalter
    return '/assets/no-poster.svg';
}
