<?php
header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';

$base  = 'https://filmbewertungen.markuskogler.de';
$today = date('Y-m-d');

$pages = [
    ['loc' => '/',                'priority' => '1.0', 'changefreq' => 'weekly',  'lastmod' => $today],
    ['loc' => '/das-projekt.php', 'priority' => '0.8', 'changefreq' => 'monthly', 'lastmod' => $today],
    ['loc' => '/features.php',    'priority' => '0.8', 'changefreq' => 'monthly', 'lastmod' => $today],
    ['loc' => '/news.php',        'priority' => '0.7', 'changefreq' => 'weekly',  'lastmod' => $today],
    ['loc' => '/team.php',        'priority' => '0.6', 'changefreq' => 'monthly', 'lastmod' => $today],
    ['loc' => '/demo.php',        'priority' => '0.6', 'changefreq' => 'monthly', 'lastmod' => $today],
    ['loc' => '/register.php',    'priority' => '0.5', 'changefreq' => 'yearly',  'lastmod' => $today],
    ['loc' => '/impressum.php',   'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $today],
];
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as $p): ?>
    <url>
        <loc><?= htmlspecialchars($base . $p['loc']) ?></loc>
        <lastmod><?= $p['lastmod'] ?></lastmod>
        <changefreq><?= $p['changefreq'] ?></changefreq>
        <priority><?= $p['priority'] ?></priority>
    </url>
<?php endforeach; ?>
</urlset>
