<?php
/**
 * Wikipedia Handlung-Suche
 * GET ?title=Filmtitel&year=1994&lang=de
 * Liefert: { ok, text, source_title, url } oder { ok:false, error }
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit; }

header('Content-Type: application/json; charset=utf-8');

$title = trim($_GET['title'] ?? '');
$year  = (int)($_GET['year']  ?? 0);
$lang  = in_array($_GET['lang'] ?? 'de', ['de','en'], true) ? ($_GET['lang'] ?? 'de') : 'de';

if ($title === '') {
    echo json_encode(['ok' => false, 'error' => 'Kein Titel angegeben']); exit;
}

// ── Schritt 1: Wikipedia-Suche ────────────────────────────────────────────────
$searchQuery = $title . ($year ? ' ' . $year : '');
$searchUrl   = "https://{$lang}.wikipedia.org/w/api.php?" . http_build_query([
    'action'   => 'query',
    'list'     => 'search',
    'srsearch' => $searchQuery,
    'srlimit'  => 5,
    'format'   => 'json',
    'origin'   => '*',
]);

$ctx = stream_context_create(['http' => [
    'timeout' => 8,
    'header'  => "User-Agent: MKFB-FilmDB/1.0 (markuskogler.de)\r\n",
]]);

$raw = @file_get_contents($searchUrl, false, $ctx);
if ($raw === false) {
    echo json_encode(['ok' => false, 'error' => 'Wikipedia-Suche nicht erreichbar']); exit;
}
$search = json_decode($raw, true);
$hits   = $search['query']['search'] ?? [];
if (empty($hits)) {
    echo json_encode(['ok' => false, 'error' => 'Kein Wikipedia-Artikel gefunden']); exit;
}

// ── Schritt 2: Besten Treffer wählen ─────────────────────────────────────────
// Bevorzuge Treffer deren Titel den Filmtitel enthält
$pageId    = null;
$pageTitle = null;
foreach ($hits as $hit) {
    if (stripos($hit['title'], $title) !== false || stripos($title, $hit['title']) !== false) {
        $pageId    = $hit['pageid'];
        $pageTitle = $hit['title'];
        break;
    }
}
// Fallback: erster Treffer
if (!$pageId) {
    $pageId    = $hits[0]['pageid'];
    $pageTitle = $hits[0]['title'];
}

// ── Schritt 3: Seiteninhalt laden (Sections) ─────────────────────────────────
$contentUrl = "https://{$lang}.wikipedia.org/w/api.php?" . http_build_query([
    'action'   => 'parse',
    'pageid'   => $pageId,
    'prop'     => 'sections|wikitext',
    'format'   => 'json',
    'origin'   => '*',
]);

$raw2 = @file_get_contents($contentUrl, false, $ctx);
if ($raw2 === false) {
    echo json_encode(['ok' => false, 'error' => 'Seiteninhalt nicht ladbar']); exit;
}
$parsed   = json_decode($raw2, true);
$sections = $parsed['parse']['sections'] ?? [];
$wikitext = $parsed['parse']['wikitext']['*'] ?? '';

// ── Schritt 4: "Handlung"-Abschnitt finden ────────────────────────────────────
$handlungKeywords = ['Handlung', 'Plot', 'Inhalt', 'Story', 'Synopsis', 'Zusammenfassung'];
$handlungIndex    = null;
$nextIndex        = null;

foreach ($sections as $i => $sec) {
    foreach ($handlungKeywords as $kw) {
        if (stripos($sec['line'], $kw) !== false && (int)$sec['level'] <= 2) {
            $handlungIndex = $i;
            // Nächste Section gleicher oder höherer Ebene suchen
            for ($j = $i + 1; $j < count($sections); $j++) {
                if ((int)$sections[$j]['level'] <= (int)$sec['level']) {
                    $nextIndex = $j;
                    break;
                }
            }
            break 2;
        }
    }
}

if ($handlungIndex === null) {
    // Kein Handlungs-Abschnitt → Intro (erste paar Absätze) zurückgeben
    $introUrl = "https://{$lang}.wikipedia.org/w/api.php?" . http_build_query([
        'action'    => 'query',
        'pageids'   => $pageId,
        'prop'      => 'extracts',
        'exintro'   => 1,
        'explaintext' => 1,
        'format'    => 'json',
        'origin'    => '*',
    ]);
    $raw3   = @file_get_contents($introUrl, false, $ctx);
    $intro  = json_decode($raw3, true);
    $text   = $intro['query']['pages'][$pageId]['extract'] ?? '';
    if (trim($text) === '') {
        echo json_encode(['ok' => false, 'error' => 'Kein Handlungsabschnitt gefunden']); exit;
    }
    echo json_encode([
        'ok'           => true,
        'text'         => trim($text),
        'source_title' => $pageTitle,
        'url'          => "https://{$lang}.wikipedia.org/wiki/" . urlencode(str_replace(' ', '_', $pageTitle)),
        'note'         => 'Kein Handlungsabschnitt gefunden – Einleitung eingefügt',
    ]); exit;
}

// ── Schritt 5: Handlungstext aus Wikitext extrahieren ─────────────────────────
$secAnchor = $sections[$handlungIndex]['anchor'] ?? '';

// Wikitext-Bereich zwischen den Sections herausschneiden
$secStart = strpos($wikitext, '== ');
$lines    = explode("\n", $wikitext);

$inSection = false;
$depth     = 0;
$textLines = [];
$targetHeading = $sections[$handlungIndex]['line'];

foreach ($lines as $line) {
    // Heading-Erkennung
    if (preg_match('/^(={2,})\s*(.+?)\s*\1\s*$/', $line, $m)) {
        $lvl     = strlen($m[1]);
        $heading = strip_tags(html_entity_decode($m[2]));
        if ($inSection) {
            if ($lvl <= $depth) break; // Nächste gleichrangige/höhere Section
        }
        if (stripos($heading, $targetHeading) !== false || stripos($targetHeading, $heading) !== false) {
            $inSection = true;
            $depth     = $lvl;
        }
        continue;
    }
    if (!$inSection) continue;
    $textLines[] = $line;
}

$handlungRaw = implode("\n", $textLines);

// ── Schritt 6: Wikitext → Klartext ───────────────────────────────────────────
function wikitextToPlain(string $wt): string {
    // Entferne Kommentare
    $wt = preg_replace('/<!--.*?-->/s', '', $wt);
    // Templates: {{...}} (nicht verschachtelt-safe, aber reicht für Fließtext)
    $prev = '';
    while ($prev !== $wt) {
        $prev = $wt;
        $wt   = preg_replace('/\{\{[^{}]*\}\}/', '', $wt);
    }
    // [[Datei:...|...]], [[File:...|...]]
    $wt = preg_replace('/\[\[(?:Datei|File|Bild|Image):[^\]]+\]\]/i', '', $wt);
    // [[Link|Text]] → Text, [[Link]] → Link
    $wt = preg_replace('/\[\[(?:[^|\]]*\|)?([^\]]+)\]\]/', '$1', $wt);
    // [http://... Text] → Text
    $wt = preg_replace('/\[https?:\/\/[^\s\]]+\s+([^\]]+)\]/', '$1', $wt);
    // Fett/kursiv
    $wt = preg_replace("/'{2,3}/", '', $wt);
    // HTML-Tags
    $wt = strip_tags($wt);
    // Mehrere Leerzeilen → eine
    $wt = preg_replace('/\n{3,}/', "\n\n", $wt);
    return trim($wt);
}

$handlungText = wikitextToPlain($handlungRaw);

if (strlen(trim($handlungText)) < 30) {
    // Fallback: Plaintext-API
    $plainUrl = "https://{$lang}.wikipedia.org/w/api.php?" . http_build_query([
        'action'      => 'query',
        'pageids'     => $pageId,
        'prop'        => 'extracts',
        'exsectionformat' => 'plain',
        'explaintext' => 1,
        'exintro'     => 0,
        'format'      => 'json',
        'origin'      => '*',
    ]);
    $rawP   = @file_get_contents($plainUrl, false, $ctx);
    $parsed2 = json_decode($rawP, true);
    $fullText = $parsed2['query']['pages'][$pageId]['extract'] ?? '';
    // "Handlung"-Abschnitt suchen
    foreach ($handlungKeywords as $kw) {
        if (preg_match('/\n(?:={1,3}\s*)?' . preg_quote($kw, '/') . '(?:\s*={1,3})?\n+([\s\S]+?)(?=\n={1,3}[^=]|\z)/i', $fullText, $m)) {
            $handlungText = trim($m[1]);
            break;
        }
    }
    if (strlen(trim($handlungText)) < 30) {
        echo json_encode(['ok' => false, 'error' => 'Handlungstext konnte nicht extrahiert werden']); exit;
    }
}

echo json_encode([
    'ok'           => true,
    'text'         => $handlungText,
    'source_title' => $pageTitle,
    'url'          => "https://{$lang}.wikipedia.org/wiki/" . urlencode(str_replace(' ', '_', $pageTitle)),
]);
