<?php
require_once __DIR__ . '/includes/functions.php';
if (isLoggedIn()) {
    header('Location: /welcome.php');
    exit;
}
$pageTitle       = 'MKFB – Markus Kogler\'s Filmbewertungen';
$pageDescription = 'Ranke deine Lieblingsfilme im 1-vs-1-Duell. MKFB baut automatisch dein persönliches ELO-Ranking auf – ganz ohne Sterne oder Punkte. Jetzt kostenlos mitmachen!';
$pageOgImage     = 'https://filmbewertungen.markuskogler.de/img/og-image.jpg';
$pageJsonLd      = json_encode([
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'       => 'WebSite',
            '@id'         => 'https://filmbewertungen.markuskogler.de/#website',
            'url'         => 'https://filmbewertungen.markuskogler.de/',
            'name'        => "MKFB – Markus Kogler's Filmbewertungen",
            'description' => 'Persönliches Filmranking-System mit ELO-Duellen, Turnieren und Community-Ranglisten.',
            'inLanguage'  => 'de-DE',
            'author'      => ['@type' => 'Person', 'name' => 'Markus Kogler'],
        ],
        [
            '@type'               => 'WebApplication',
            '@id'                 => 'https://filmbewertungen.markuskogler.de/#app',
            'name'                => "MKFB – Markus Kogler's Filmbewertungen",
            'url'                 => 'https://filmbewertungen.markuskogler.de/',
            'applicationCategory' => 'EntertainmentApplication',
            'operatingSystem'     => 'Web',
            'offers'              => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
            'description'         => 'Ranke Lieblingsfilme im ELO-Duell. Turniere, Jeder-gegen-Jeden, Sortieren per Merge Sort und Community-Ranglisten.',
            'inLanguage'          => 'de-DE',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
require_once __DIR__ . '/includes/header.php';

$stats = getStats();
$globalTop = getGlobalRanking(5);
?>

<!-- HERO -->
<section class="hero-section d-flex align-items-center">
    <div class="container">
        <div class="row align-items-center gy-5 gx-5">
            <div class="col-lg-6">
                <span class="badge bg-gold text-dark fw-semibold mb-3 px-3 py-2">
                    <i class="bi bi-trophy-fill me-1"></i> Film-Ranking Community
                </span>
                <h1 class="hero-title mb-4">
                    Welcher Film ist<br>
                    <span class="text-gold">wirklich</span> besser?
                </h1>
                <p class="hero-subtitle mb-5">
                    Ranke deine Lieblingsfilme im spannenden 1v1-Duell. Wähle zwischen zwei Filmen,
                    und MKFB baut automatisch dein persönliches Ranking auf – ganz ohne Sterne oder Punkte.
                </p>
                <div class="d-flex flex-wrap gap-3">
                    <?php if ($loggedIn): ?>
                        <a href="/turnier.php" class="btn btn-gold btn-lg px-4">
                            <i class="bi bi-play-fill me-2"></i>Jetzt vergleichen
                        </a>
                        <a href="#features" class="btn btn-outline-light btn-lg px-4">
                            Mehr erfahren
                        </a>
                    <?php else: ?>
                        <a href="/register.php" class="btn btn-gold btn-lg px-4">
                            <i class="bi bi-person-plus-fill me-2"></i>Kostenlos beitreten
                        </a>
                        <a href="#projekt" class="btn btn-outline-light btn-lg px-4">
                            Mehr erfahren
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="hero-duel-preview">
                    <div class="duel-card-preview left">
                        <img src="https://image.tmdb.org/t/p/w300/q6y0Go1tsGEsmtFryDOJo3dEmqu.jpg" alt="Film A">
                        <div class="duel-label">Film A</div>
                    </div>
                    <div class="vs-badge">VS</div>
                    <div class="duel-card-preview right">
                        <img src="https://image.tmdb.org/t/p/w300/qUV8UUGa1596hbOWzJ8AfppjpGd.jpg" alt="Der Club der toten Dichter">
                        <div class="duel-label">Film B</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- STATS BAR -->
<section class="stats-bar py-4">
    <div class="container">
        <div class="row text-center g-3">
            <div class="col-4">
                <div class="stat-number"><?= number_format($stats['users']) ?></div>
                <div class="stat-label">Nutzer</div>
            </div>
            <div class="col-4">
                <div class="stat-number"><?= number_format($stats['movies']) ?></div>
                <div class="stat-label">Filme</div>
            </div>
            <div class="col-4">
                <div class="stat-number"><?= number_format($stats['comparisons']) ?></div>
                <div class="stat-label">Vergleiche</div>
            </div>
        </div>
    </div>
</section>

<!-- DAS PROJEKT -->
<section id="projekt" class="py-6 projekt-section">
    <div class="container">
        <div class="text-center mb-5">
            <span class="badge bg-gold text-dark fw-semibold mb-3 px-3 py-2">Das Projekt</span>
            <h2 class="section-title">Was ist MKFB?</h2>
            <p class="section-subtitle">In drei Schritten zu deinem persönlichen Filmranking</p>
        </div>
        <div class="row g-4 mb-6">
            <div class="col-md-4">
                <div class="how-card text-center h-100">
                    <div class="how-icon"><i class="bi bi-person-plus-fill"></i></div>
                    <div class="how-number">01</div>
                    <h4 class="fw-bold mb-3">Registrieren</h4>
                    <p class="text-muted">Erstelle kostenlos ein Konto und tritt der MKFB Film-Community bei.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="how-card text-center h-100 featured">
                    <div class="how-icon featured"><i class="bi bi-shuffle"></i></div>
                    <div class="how-number text-gold">02</div>
                    <h4 class="fw-bold mb-3">1v1 Duell</h4>
                    <p class="text-muted">Dir werden zwei Filme gezeigt – klick einfach auf den, den du lieber magst. Kein Nachdenken, nur Bauchgefühl.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="how-card text-center h-100">
                    <div class="how-icon"><i class="bi bi-bar-chart-steps"></i></div>
                    <div class="how-number">03</div>
                    <h4 class="fw-bold mb-3">Ranking entsteht</h4>
                    <p class="text-muted">Nach jedem Duell berechnet MKFB dein persönliches Ranking automatisch per ELO-System.</p>
                </div>
            </div>
        </div>
        <div class="text-center">
            <p class="text-muted mb-4">
                MKFB entstand als persönliches Hobbyprojekt, inspiriert von
                <a href="https://www.flickchart.com" target="_blank" class="text-gold fw-semibold text-decoration-none">Flickchart</a> –
                dem originalen Filmranking-System. Kein Sternesystem, keine langen Reviews, nur direkte Entscheidungen.
            </p>
            <a href="/register.php" class="btn btn-gold px-4">
                <i class="bi bi-person-plus-fill me-2"></i>Jetzt kostenlos mitmachen
            </a>
        </div>
    </div>
</section>

<!-- FEATURES -->
<section id="features" class="py-6 features-section">
    <div class="container">
        <div class="row align-items-center gy-5 mb-6">
            <div class="col-lg-6 order-lg-2">
                <span class="badge bg-gold text-dark fw-semibold mb-3 px-3 py-2">ELO-Ranking</span>
                <h2 class="section-title">Faires Ranking durch bewährte Logik</h2>
                <p class="text-muted mb-4">
                    MKFB nutzt das ELO-Bewertungssystem – dasselbe, das auch im Schach eingesetzt wird.
                    Jeder Film bekommt eine dynamische Punktzahl basierend auf Gewinnen und Niederlagen.
                </p>
                <ul class="feature-list">
                    <li><i class="bi bi-check-circle-fill text-gold"></i> Kein subjektives Sternesystem</li>
                    <li><i class="bi bi-check-circle-fill text-gold"></i> Jeder Sieg und jede Niederlage zählt</li>
                    <li><i class="bi bi-check-circle-fill text-gold"></i> Dein Ranking wird mit jedem Duell besser</li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div class="feature-visual elo-visual">
                    <div class="elo-bar-demo">
                        <div class="elo-item">
                            <span class="elo-rank">#1</span>
                            <div class="elo-bar-wrap"><div class="elo-bar" style="width:92%"></div></div>
                            <span class="elo-score">1842</span>
                        </div>
                        <div class="elo-item">
                            <span class="elo-rank">#2</span>
                            <div class="elo-bar-wrap"><div class="elo-bar" style="width:80%"></div></div>
                            <span class="elo-score">1720</span>
                        </div>
                        <div class="elo-item">
                            <span class="elo-rank">#3</span>
                            <div class="elo-bar-wrap"><div class="elo-bar" style="width:65%"></div></div>
                            <span class="elo-score">1610</span>
                        </div>
                        <div class="elo-item">
                            <span class="elo-rank">#4</span>
                            <div class="elo-bar-wrap"><div class="elo-bar" style="width:50%"></div></div>
                            <span class="elo-score">1540</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row align-items-center gy-5">
            <div class="col-lg-6">
                <span class="badge bg-gold text-dark fw-semibold mb-3 px-3 py-2">Community</span>
                <h2 class="section-title">Globale Charts</h2>
                <p class="text-muted mb-4">
                    Dein Ranking ist nur der Anfang. MKFB aggregiert alle Bewertungen der Community
                    und erstellt eine globale Rangliste der beliebtesten Filme.
                </p>
                <ul class="feature-list">
                    <li><i class="bi bi-check-circle-fill text-gold"></i> Echtzeit-Auswertung aller Duelle</li>
                    <li><i class="bi bi-check-circle-fill text-gold"></i> Vergleich mit anderen Nutzern</li>
                    <li><i class="bi bi-check-circle-fill text-gold"></i> Entdecke Filme, die du noch nicht kennst</li>
                </ul>
                <a href="/charts.php" class="btn btn-dark mt-2">
                    <i class="bi bi-bar-chart-fill me-2"></i>Filmdatenbank ansehen
                </a>
            </div>
            <div class="col-lg-6">
                <?php if (!empty($globalTop)): ?>
                <div class="mini-chart">
                    <?php foreach ($globalTop as $i => $movie): ?>
                    <div class="mini-chart-row">
                        <span class="mini-rank"><?= $i + 1 ?></span>
                        <img src="<?= e(posterUrl($movie['poster_path'])) ?>"
                             alt="<?= e($movie['title']) ?>" class="mini-poster">
                        <div class="mini-info">
                            <a href="/film.php?id=<?= (int)$movie['id'] ?>" class="film-link mini-title"><?= e($movie['title']) ?></a>
                            <span class="mini-year"><?= e($movie['year']) ?></span>
                        </div>
                        <span class="mini-elo"><?= e((string)$movie['avg_elo']) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div class="text-center mt-3">
                        <a href="/charts.php" class="btn btn-sm btn-outline-dark">Alle ansehen →</a>
                    </div>
                </div>
                <?php else: ?>
                <div class="mini-chart d-flex align-items-center justify-content-center" style="min-height:200px">
                    <div class="text-center text-muted">
                        <i class="bi bi-bar-chart fs-1 d-block mb-2"></i>
                        <span>Noch keine Daten – starte das erste Duell!</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

        <!-- Jeder gegen Jeden Feature -->
        <div class="row align-items-center gy-5 mt-2">
            <div class="col-lg-6 order-lg-2">
                <span class="badge bg-gold text-dark fw-semibold mb-3 px-3 py-2">Phase IV</span>
                <h2 class="section-title">Jeder gegen Jeden</h2>
                <p class="text-muted mb-4">
                    Das ultimative Ranking-Verfahren: Jeder Film tritt gegen jeden anderen an.
                    Kein Zufallsfaktor – jede Begegnung findet statt.
                </p>
                <ul class="feature-list">
                    <li><i class="bi bi-check-circle-fill text-gold"></i> Starte mit deinen Top-50 Filmen</li>
                    <li><i class="bi bi-check-circle-fill text-gold"></i> Ergebnis: Siege/Niederlagen-Quote pro Film</li>
                    <li><i class="bi bi-check-circle-fill text-gold"></i> Pool jederzeit um weitere Filme erweiterbar</li>
                </ul>
                <?php if ($loggedIn && userPhase() >= 3): ?>
                <a href="/jgj.php" class="btn btn-dark mt-2">
                    <i class="bi bi-diagram-3-fill me-2"></i>Jeder gegen Jeden starten
                </a>
                <?php endif; ?>
            </div>
            <div class="col-lg-6 order-lg-1 text-center">
                <div style="font-size:5rem; opacity:.15; line-height:1;">
                    <i class="bi bi-diagram-3-fill"></i>
                </div>
                <div class="d-flex justify-content-center gap-4 mt-2" style="font-size:.9rem; color:#666;">
                    <div class="text-center">
                        <div style="font-size:1.8rem; font-weight:800; color:#e8b84b;">50</div>
                        <div>Startgröße</div>
                    </div>
                    <div class="text-center">
                        <div style="font-size:1.8rem; font-weight:800; color:#e8b84b;">1.225</div>
                        <div>Duelle</div>
                    </div>
                    <div class="text-center">
                        <div style="font-size:1.8rem; font-weight:800; color:#e8b84b;">100%</div>
                        <div>Genauigkeit</div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

<!-- DEMO -->
<section id="demo" class="py-6 demo-section">
    <div class="container">
        <div class="text-center mb-5">
            <span class="badge bg-gold text-dark fw-semibold mb-3 px-3 py-2">Demo</span>
            <h2 class="section-title text-white">So funktioniert das 1v1-Duell</h2>
            <p class="text-light opacity-75 fs-5">Wähle einfach den Film, den du lieber magst.</p>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="demo-duel-wrap">
                    <div class="hero-duel-preview">
                        <div class="duel-card-preview left">
                            <img src="https://image.tmdb.org/t/p/w300/q6y0Go1tsGEsmtFryDOJo3dEmqu.jpg" alt="The Shawshank Redemption">
                            <div class="duel-label">The Shawshank Redemption</div>
                        </div>
                        <div class="vs-badge">VS</div>
                        <div class="duel-card-preview right">
                            <img src="https://image.tmdb.org/t/p/w300/qUV8UUGa1596hbOWzJ8AfppjpGd.jpg" alt="Der Club der toten Dichter">
                            <div class="duel-label">Der Club der toten Dichter</div>
                        </div>
                    </div>
                    <div class="text-center mt-4">
                        <p class="text-light opacity-75 small mb-3">
                            In der App wählst du zwischen echten Filmen – nach jedem Klick berechnet
                            das ELO-System dein persönliches Ranking automatisch neu.
                        </p>
                        <a href="/register.php" class="btn btn-gold px-4">
                            <i class="bi bi-play-fill me-2"></i>Jetzt ausprobieren
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- DAS TEAM -->
<?php
// ── Team-Tabelle sicherstellen & Daten laden ───────────────────────────────
$dbT = getDB();
$dbT->exec("CREATE TABLE IF NOT EXISTS team_members (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sort_order INT          NOT NULL DEFAULT 0,
    initials   VARCHAR(5)   NOT NULL DEFAULT '',
    avatar_color VARCHAR(7) NOT NULL DEFAULT '#1e3a5f',
    name       VARCHAR(100) NOT NULL DEFAULT '',
    role       VARCHAR(100) NOT NULL DEFAULT '',
    bio        TEXT,
    tags       TEXT,
    updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
// Standardmitglieder einfügen (nur wenn Tabelle leer)
$cnt = (int)$dbT->query("SELECT COUNT(*) FROM team_members")->fetchColumn();
if ($cnt === 0) {
    $ins = $dbT->prepare("INSERT INTO team_members (sort_order,initials,avatar_color,name,role,bio,tags) VALUES (?,?,?,?,?,?,?)");
    $ins->execute([1,'MK','#1e3a5f','Markus Kogler','Entwickler & Filmliebhaber','MKFB ist ein Hobbyprojekt, das aus der Leidenschaft für Filme und der Frage „Welcher Film ist wirklich besser?" entstanden ist. Entwickelt mit PHP, MySQL und viel Kaffee.','PHP,MySQL,Bootstrap 5,ELO-System,TMDB API']);
    $ins->execute([2,'JH','#2d5a27','Jonas Halmschlag','','','']);
    $ins->execute([3,'JB','#5a2d27','Joscha Burkholz','','','']);
    $ins->execute([4,'LK','#27405a','Lorna Kogler','','','']);
}
$teamMembers = $dbT->query("SELECT * FROM team_members ORDER BY sort_order ASC")->fetchAll();
?>
<section id="team" class="py-6 team-section">
    <div class="container">
        <div class="text-center mb-5">
            <span class="badge bg-gold text-dark fw-semibold mb-3 px-3 py-2">Das Team</span>
            <h2 class="section-title">Hinter MKFB</h2>
            <p class="section-subtitle"><?= count($teamMembers) === 1 ? 'Das Projekt in einer Person' : 'Die Menschen hinter dem Projekt' ?></p>
        </div>
        <div class="row g-4 justify-content-center">
            <?php foreach ($teamMembers as $tm): ?>
            <div class="col-lg-3 col-md-6">
                <div class="team-card h-100">
                    <div class="team-avatar" style="background:linear-gradient(135deg, <?= htmlspecialchars($tm['avatar_color']) ?>, #0a1f3c);">
                        <?= htmlspecialchars($tm['initials']) ?>
                    </div>
                    <h3 class="fw-bold text-center mb-1"><?= htmlspecialchars($tm['name']) ?></h3>
                    <?php if (!empty($tm['role'])): ?>
                    <p class="text-muted text-center small mb-3"><?= htmlspecialchars($tm['role']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($tm['bio'])): ?>
                    <p class="text-muted text-center mb-3" style="font-size:.9rem;"><?= htmlspecialchars($tm['bio']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($tm['tags'])): ?>
                    <div class="text-center">
                        <?php foreach (explode(',', $tm['tags']) as $tag): ?>
                        <span class="badge bg-light text-dark me-1 mb-1"><?= htmlspecialchars(trim($tag)) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (isAdmin()): ?>
        <div class="text-center mt-4">
            <a href="/admin-team.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i>Team bearbeiten
            </a>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- CTA -->
<?php if (!$loggedIn): ?>
<section class="cta-section py-6">
    <div class="container text-center">
        <h2 class="display-5 fw-bold text-white mb-3">Bereit für dein Ranking?</h2>
        <p class="text-light opacity-75 fs-5 mb-5">
            Tritt der Community bei und finde heraus, welche Filme du wirklich liebst.
        </p>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <a href="/register.php" class="btn btn-gold btn-lg px-5">
                <i class="bi bi-person-plus-fill me-2"></i>Kostenlos registrieren
            </a>
            <a href="/login.php" class="btn btn-outline-light btn-lg px-5">Anmelden</a>
        </div>
    </div>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
