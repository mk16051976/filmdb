<?php
$pageTitle       = 'Das Projekt – MKFB';
$pageDescription = 'MKFB ist ein Hobbyprojekt von Markus Kogler: Ein ELO-basiertes Filmranking-System mit Turnieren, Sortieralgorithmen und Community-Ranglisten. Entwickelt mit PHP, MySQL und viel Kaffee.';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Migration ─────────────────────────────────────────────────────────────────
$db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS onboarding_done TINYINT(1) NOT NULL DEFAULT 0");

// ── Onboarding-Status ────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT onboarding_done FROM users WHERE id = ?");
$stmt->execute([$userId]);
$isFirstLogin = !(bool)($stmt->fetchColumn());

// ── POST: Onboarding-Abschluss ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'open_website') {
        $db->prepare("UPDATE users SET onboarding_done = 1 WHERE id = ?")->execute([$userId]);
        header('Location: /welcome.php'); exit;
    }

    if ($action === 'start_tournament') {
        // Filme aus dem Turnier-Pool laden (in zufälliger Reihenfolge, max 4096)
        $films = $db->query("
            SELECT tp.movie_id FROM tournament_pool tp
            JOIN movies m ON m.id = tp.movie_id
            ORDER BY RAND()
            LIMIT 4096
        ")->fetchAll(PDO::FETCH_COLUMN);

        // Fallback: Zufällige Filme aus DB falls Pool leer
        if (empty($films)) {
            $films = $db->query("
                SELECT id FROM movies
                WHERE COALESCE(media_type,'movie') != 'tv'
                ORDER BY RAND()
                LIMIT 4096
            ")->fetchAll(PDO::FETCH_COLUMN);
        }

        $n = count($films);
        if ($n >= 2) {
            // nextPow2 inline
            $b = 2; while ($b < $n) $b *= 2;
            $byes   = $b - $n;
            $rounds = (int)log($b, 2);

            // Altes aktives Turnier löschen
            $old = $db->prepare("SELECT id FROM user_tournaments WHERE user_id=? AND status='active' LIMIT 1");
            $old->execute([$userId]);
            if ($oldRow = $old->fetch(PDO::FETCH_ASSOC)) {
                $db->prepare("DELETE FROM tournament_matches WHERE tournament_id=?")->execute([$oldRow['id']]);
                $db->prepare("DELETE FROM tournament_films   WHERE tournament_id=?")->execute([$oldRow['id']]);
                $db->prepare("DELETE FROM user_tournaments   WHERE id=?")->execute([$oldRow['id']]);
            }

            $db->prepare("INSERT INTO user_tournaments (user_id, film_count, total_rounds, media_type) VALUES (?,?,?,'movie')")
               ->execute([$userId, $n, $rounds]);
            $tId = (int)$db->lastInsertId();

            $seed = 1;
            foreach (array_chunk($films, 500) as $chunk) {
                $ph   = implode(',', array_fill(0, count($chunk), '(?,?,?,?)'));
                $vals = [];
                foreach ($chunk as $movieId) {
                    $isBye = ($seed <= $byes) ? 1 : 0;
                    $vals  = array_merge($vals, [$tId, (int)$movieId, $seed++, $isBye]);
                }
                $db->prepare("INSERT INTO tournament_films (tournament_id, movie_id, seed, bye) VALUES $ph")->execute($vals);
            }

            // Runde-1-Matches
            $playingFilms = array_slice($films, $byes);
            $playCount    = count($playingFilms);
            $pairs = [];
            for ($i = 0; $i < intdiv($playCount, 2); $i++) {
                $pairs[] = [$i + 1, $playingFilms[$i], $playingFilms[$playCount - 1 - $i]];
            }
            shuffle($pairs);
            foreach (array_chunk($pairs, 500) as $chunk) {
                $ph   = implode(',', array_fill(0, count($chunk), '(?,1,?,?,?)'));
                $vals = [];
                foreach ($chunk as [$matchNum, $aId, $bId]) {
                    $vals = array_merge($vals, [$tId, $matchNum, (int)$aId, (int)$bId]);
                }
                $db->prepare("INSERT INTO tournament_matches (tournament_id, runde, match_number, movie_a_id, movie_b_id) VALUES $ph")->execute($vals);
            }
        }

        $db->prepare("UPDATE users SET onboarding_done = 1 WHERE id = ?")->execute([$userId]);
        header('Location: /turnier.php'); exit;
    }
}

require_once __DIR__ . '/includes/header.php';

// Pfad-Migration: /filmdb/uploads/… → /uploads/…
try { $db->exec("UPDATE project_slides SET image_path = REPLACE(image_path, '/filmdb/uploads/', '/uploads/') WHERE image_path LIKE '/filmdb/uploads/%'"); } catch (\PDOException $e) {}

// Slides aus DB laden (Tabelle wird in admin-projekt.php erstellt)
$slides = [];
try {
    $slides = $db->query("SELECT * FROM project_slides ORDER BY sort_order ASC, id ASC")
                 ->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {}

// Pill-Text parsen: "bi-icon|Text" oder "Text"
function parsePills(string $raw): array {
    $lines = array_filter(array_map('trim', explode("\n", $raw)));
    $out   = [];
    foreach ($lines as $line) {
        $parts = explode('|', $line, 2);
        $out[] = count($parts) === 2
            ? ['icon' => trim($parts[0]), 'text' => trim($parts[1])]
            : ['icon' => '',              'text' => trim($parts[0])];
    }
    return $out;
}
?>

<main>
    <!-- INTRO -->
    <section style="background:#f7f8fa; height:2em;"></section>

    <?php if ($isFirstLogin && !empty($slides)): ?>
    <!-- ── Onboarding-Banner ─────────────────────────────────────────────── -->
    <div id="onboarding-banner" style="
        background: linear-gradient(135deg, rgba(232,184,75,.12), rgba(232,184,75,.06));
        border-bottom: 1px solid rgba(232,184,75,.3);
        padding: .75rem 1.5rem;
        display: flex; align-items: center; justify-content: center; gap: .75rem;
        font-size: .85rem; color: rgba(255,255,255,.75); text-align: center;">
        <i class="bi bi-lock-fill" style="color:#e8b84b;"></i>
        <span id="onboarding-msg">Bitte lies alle <strong style="color:#e8b84b;"><?= count($slides) ?> Slides</strong> durch, um fortzufahren.</span>
    </div>
    <?php endif; ?>

    <!-- PHASE SLIDESHOW -->
    <?php if (!empty($slides)): ?>
    <section class="phase-slider-section py-3">
        <div class="container">
            <div class="text-center mb-3">
                <h2 class="fw-bold text-white mb-1">Deine Reise auf MKFB</h2>
                <p class="text-light opacity-50 small">Von der Registrierung zum Vollzugang</p>
            </div>

            <div id="phaseCarousel" class="carousel slide" data-bs-ride="false" data-bs-wrap="false">
                <div class="carousel-inner">
                    <?php foreach ($slides as $idx => $s):
                        $isFirst  = $idx === 0;
                        $hasImg   = !empty($s['image_path']);
                        $asBg     = $hasImg && !empty($s['image_as_bg']);
                        $isLastPh = $idx === count($slides) - 1;

                        $cardClass = 'phase-card';
                        if ($isLastPh) $cardClass .= ' phase-card--iv';
                        if ($asBg)     $cardClass .= ' phase-card--img';

                        $bgStyle = $asBg ? 'style="background-image:url(\'' . e($s['image_path']) . '\');"' : '';
                        $pills   = $s['pills'] ? parsePills($s['pills']) : [];
                    ?>
                    <div class="carousel-item <?= $isFirst ? 'active' : '' ?>">
                        <div class="<?= $cardClass ?>" <?= $bgStyle ?>>
                            <?php if ($asBg): ?>
                            <div class="phase-img-overlay"></div>
                            <?php endif; ?>
                            <div class="phase-bg-num"><?= e($s['phase_label'] ?: ($idx + 1)) ?></div>
                            <div class="phase-card-inner">
                                <?php if ($s['phase_label']): ?>
                                <div class="phase-label <?= $isLastPh ? 'phase-label--gold' : '' ?>">
                                    <?= e($s['phase_label']) ?>
                                </div>
                                <?php endif; ?>

                                <h3 class="phase-title"><?= e($s['title']) ?></h3>

                                <?php if ($hasImg && !$asBg): ?>
                                <div class="phase-thumb phase-thumb--nav">
                                    <div class="phase-thumb-prev" onclick="phaseNav('prev')" title="Zurück"><i class="bi bi-chevron-left"></i></div>
                                    <img src="<?= e($s['image_path']) ?>" alt="">
                                    <div class="phase-thumb-next" onclick="phaseNav('next')" title="Weiter"><i class="bi bi-chevron-right"></i></div>
                                </div>
                                <?php else: ?>
                                <div class="phase-icon-ring <?= $isLastPh ? 'phase-icon-ring--gold' : '' ?>">
                                    <i class="<?= e($s['icon']) ?>"></i>
                                </div>
                                <?php endif; ?>

                                <?php if ($s['description']): ?>
                                <p class="phase-desc"><?= e($s['description']) ?></p>
                                <?php endif; ?>

                                <?php if ($pills): ?>
                                <div class="phase-pills">
                                    <?php foreach ($pills as $pill): ?>
                                    <span class="phase-pill <?= $isLastPh ? 'phase-pill--gold' : '' ?>">
                                        <?php if ($pill['icon']): ?>
                                        <i class="<?= e($pill['icon']) ?>"></i>
                                        <?php endif; ?>
                                        <?= e($pill['text']) ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($isLastPh && $isFirstLogin): ?>
                                <!-- ── Onboarding-Entscheidung ──────────────── -->
                                <div id="onboarding-choice" class="mt-4 flex-column flex-sm-row gap-3 justify-content-center">
                                    <form method="post">
                                        <input type="hidden" name="action"     value="start_tournament">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <button type="submit" class="btn btn-gold px-4 py-2 fw-bold" style="font-size:.95rem; min-width:220px;">
                                            <i class="bi bi-trophy-fill me-2"></i>Sichtungsturnier starten
                                        </button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action"     value="open_website">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <button type="submit" class="btn btn-outline-light px-4 py-2 fw-bold" style="font-size:.95rem; min-width:220px;">
                                            <i class="bi bi-globe me-2"></i>Offene Website
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Zentrierte Navigation -->
                <div class="phase-nav">
                    <button class="phase-nav-btn" id="phasePrev" type="button"
                            data-bs-target="#phaseCarousel" data-bs-slide="prev" disabled>
                        <i class="bi bi-arrow-left"></i>
                    </button>
                    <div class="phase-dots">
                        <?php foreach ($slides as $idx => $s): ?>
                        <button class="phase-dot <?= $idx === 0 ? 'active' : '' ?>"
                                data-slide="<?= $idx ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <button class="phase-nav-btn" id="phaseNext" type="button"
                            data-bs-target="#phaseCarousel" data-bs-slide="next"
                            <?= count($slides) <= 1 ? 'disabled' : '' ?>>
                        <i class="bi bi-arrow-right"></i>
                    </button>
                </div>
            </div>

            <?php if ($loggedIn && isAdmin()): ?>
            <div class="text-center mt-4">
                <a href="/admin-projekt.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-pencil me-1"></i>Slides bearbeiten
                </a>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

</main>

<style>
.phase-thumb--nav { position: relative; }
.phase-thumb-prev,
.phase-thumb-next {
    position: absolute; top: 0; bottom: 0; width: 22%;
    display: flex; align-items: center;
    cursor: pointer; z-index: 2;
    color: #fff; font-size: 2rem;
    opacity: 0; transition: opacity .2s;
}
.phase-thumb-prev { left: 0; justify-content: flex-start; padding-left: .75rem;
    background: linear-gradient(to right, rgba(0,0,0,.35), transparent); }
.phase-thumb-next { right: 0; justify-content: flex-end; padding-right: .75rem;
    background: linear-gradient(to left, rgba(0,0,0,.35), transparent); }
.phase-thumb--nav:hover .phase-thumb-prev,
.phase-thumb--nav:hover .phase-thumb-next { opacity: 1; }
.phase-thumb-prev:hover, .phase-thumb-next:hover { opacity: 1 !important; }

/* ── Onboarding-Lock ─────────────────────────────────────────────────────── */
body.onboarding-lock .navbar-nav .nav-link,
body.onboarding-lock .navbar-nav a,
body.onboarding-lock .dropdown-item,
body.onboarding-lock #hdr-pm-link {
    pointer-events: none !important;
    opacity: .35 !important;
}
body.onboarding-lock .navbar-brand {
    pointer-events: none !important;
}
#onboarding-choice { display: none !important; }
#onboarding-choice.visible { display: flex !important; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const carouselEl  = document.getElementById('phaseCarousel');
    if (!carouselEl) return;
    const total       = <?= count($slides) ?>;
    const isFirst     = <?= $isFirstLogin ? 'true' : 'false' ?>;
    const carousel    = bootstrap.Carousel.getOrCreateInstance(carouselEl, { wrap: false });
    const prevBtn     = document.getElementById('phasePrev');
    const nextBtn     = document.getElementById('phaseNext');
    const dots        = document.querySelectorAll('.phase-dot');
    const banner      = document.getElementById('onboarding-banner');
    const bannerMsg   = document.getElementById('onboarding-msg');
    const choice      = document.getElementById('onboarding-choice');

    // Navigation sperren beim ersten Login
    if (isFirst) {
        document.body.classList.add('onboarding-lock');
    }

    function showButtons() {
        if (choice) choice.classList.add('visible');
        if (banner) {
            banner.style.background = 'linear-gradient(135deg, rgba(232,184,75,.15), rgba(232,184,75,.07))';
            bannerMsg.innerHTML = '<i class="bi bi-hand-index-thumb-fill me-1" style="color:#e8b84b;"></i>'
                + ' Wähle jetzt, wie du starten möchtest.';
        }
        // Weiter-Button verstecken (letzter Slide)
        if (nextBtn) nextBtn.style.visibility = 'hidden';
    }

    // Globale Funktion für Bild-Klick-Zonen
    window.phaseNav = function(dir) {
        dir === 'prev' ? carousel.prev() : carousel.next();
    };

    dots.forEach(dot => {
        dot.addEventListener('click', () => {
            const target = parseInt(dot.dataset.slide, 10);
            // Im Onboarding Dot-Klick nur rückwärts blockieren
            if (isFirst && target > parseInt(dot.closest('.phase-dots')?.querySelector('.phase-dot.active')?.dataset?.slide ?? '0', 10)) return;
            if (isFirst) return; // Im Onboarding gar nicht per Dot navigieren
            carousel.to(target);
        });
    });

    carouselEl.addEventListener('slid.bs.carousel', e => {
        const to = e.to;
        dots.forEach((d, i) => d.classList.toggle('active', i === to));
        prevBtn.disabled = (to === 0);
        nextBtn.disabled = (to === total - 1);

        // Banner-Fortschritt aktualisieren
        if (isFirst && bannerMsg) {
            const remaining = total - 1 - to;
            if (remaining > 0) {
                bannerMsg.innerHTML = 'Noch <strong style="color:#e8b84b;">' + remaining + '</strong> Slide'
                    + (remaining > 1 ? 's' : '') + ' bis zur Auswahl.';
            }
        }

        // Letzten Slide erreicht → Buttons einblenden (Navigation bleibt gesperrt!)
        if (to === total - 1 && isFirst) showButtons();
    });

    // Wenn nur 1 Slide → sofort Buttons zeigen
    if (total <= 1 && isFirst) showButtons();

    // Pfeiltasten
    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft')  carousel.prev();
        if (e.key === 'ArrowRight') carousel.next();
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
