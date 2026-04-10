<?php
$pageTitle       = 'Features – MKFB';
$pageDescription = 'Alle Funktionen von MKFB: ELO-Duell-System, Sichtungsturnier, Jeder-gegen-Jeden, Sortieren per Merge Sort, Liga, Zufallsduelle und Community-Ranglisten – für dein perfektes Filmranking.';
require_once __DIR__ . '/includes/header.php';
?>

<main>
    <!-- PAGE HEADER -->
    <section class="features-page-header py-3">
        <div class="container">
            <div class="text-center">
<h1 class="section-title mb-3">Was MKFB zu bieten hat</h1>
                <p class="section-subtitle mx-auto" style="max-width:560px;">
                    Alles, was du brauchst, um deine Lieblingsfilme zu entdecken, zu vergleichen und zu bewerten.
                </p>
            </div>
        </div>
    </section>

    <!-- FEATURES GRID -->
    <section class="features-grid py-3">
        <div class="container">

            <!-- Row 1 -->
            <div class="row feature-row g-0 align-items-center">
                <div class="col-md-6 feature-cell border-end-md">
                    <div class="feature-item d-flex align-items-start gap-4 p-4">
                        <div class="feature-icon-wrap">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-2">Head2Head</h4>
                            <p class="text-muted mb-0">
                                Duelliere die besten Filme aller Zeiten gegeneinander. Jedes Mal, wenn ein
                                niedriger eingestufter Film das Duell gewinnt, übernimmt er den Rang des
                                höher eingestuften Films. So wird mit jedem Duell deine Rangliste etwas genauer.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="feature-item d-flex align-items-start gap-4 p-4">
                        <div class="feature-icon-wrap">
                            <i class="bi bi-trophy-fill"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-2">Ranglisten</h4>
                            <p class="text-muted mb-0">
                                Je mehr Duelle du durchführst, desto genauer wird dein persönliches Ranking.
                                Dein Einfluss auf die Gesamtrangliste nimmt zu, je mehr Filme du
                                „Jeder gegen Jeden" bewertest.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="feature-divider">

            <!-- Row 2 -->
            <div class="row feature-row g-0 align-items-center">
                <div class="col-md-6 border-end-md">
                    <div class="feature-item d-flex align-items-start gap-4 p-4">
                        <div class="feature-icon-wrap">
                            <i class="bi bi-shuffle"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-2">Jeder gegen Jeden</h4>
                            <p class="text-muted mb-0">
                                Wähle eine beliebige Anzahl Filme, die du „Jeder gegen Jeden" duellierst.
                                Nur so entsteht eine genaue Rangliste. Sobald du mindestens 100 Filme auf
                                diese Weise duelliert hast, geht dein Ergebnis in die Rangliste der
                                Community ein.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="feature-item d-flex align-items-start gap-4 p-4">
                        <div class="feature-icon-wrap">
                            <i class="bi bi-bar-chart-fill"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-2">Statistiken</h4>
                            <p class="text-muted mb-0">
                                Welcher ist der beste Fantasy-Film? Haben Europäer andere Lieblingsfilme
                                als Amerikaner? Was ist mit Frauen und Männern? Wer ist der User mit den
                                meisten Duellen? Wer ist der älteste Bewerter? u.v.m.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="feature-divider">

            <!-- Row 3 -->
            <div class="row feature-row g-0 align-items-center">
                <div class="col-md-6 border-end-md">
                    <div class="feature-item d-flex align-items-start gap-4 p-4">
                        <div class="feature-icon-wrap">
                            <i class="bi bi-award-fill"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-2">Filmturnier</h4>
                            <p class="text-muted mb-0">
                                Das Filmturnier ist eine schnelle Möglichkeit, den gesamten Filmbestand
                                zu sichten und die Filme in eine grobe Rangliste zu bringen. Perfekt für
                                den schnellen Einstieg.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="feature-item d-flex align-items-start gap-4 p-4">
                        <div class="feature-icon-wrap">
                            <i class="bi bi-clipboard-check-fill"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-2">Aktionen</h4>
                            <p class="text-muted mb-0">
                                Es finden laufend Aktionen statt. Hier werden besondere Filmgruppen
                                gebildet – wir suchen z.&nbsp;B. das beste Drama oder den besten
                                James-Bond-Film. Unter den Teilnehmern werden Preise verlost.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="feature-divider">

            <!-- Row 4 -->
            <div class="row feature-row g-0 align-items-center">
                <div class="col-md-6 border-end-md">
                    <div class="feature-item d-flex align-items-start gap-4 p-4">
                        <div class="feature-icon-wrap">
                            <i class="bi bi-person-fill-up"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-2">Einer gegen Alle</h4>
                            <p class="text-muted mb-0">
                                Du hast beim Sichten der Filme einen Film entdeckt oder vermisst einen
                                Film in deiner Rangliste? Mit „Einer gegen Alle" kannst du einen Film
                                solange gegen höherplatzierte Filme duellieren, bis er 3 aufeinander­folgende
                                Duelle verliert.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="feature-item d-flex align-items-start gap-4 p-4">
                        <div class="feature-icon-wrap gold">
                            <i class="bi bi-patch-check-fill"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-2">Ehrungen</h4>
                            <p class="text-muted mb-0">
                                Wer ist der aktivste User des Monats? Wer hat die meisten fehlenden
                                Filme gemeldet? Die aktivsten Mitglieder werden monatlich ausgezeichnet
                                und in der Community hervorgehoben.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- CTA -->
    <?php if (!$loggedIn): ?>
    <section class="cta-section py-6">
        <div class="container text-center">
            <h2 class="display-5 fw-bold text-white mb-3">Bereit, alle Features zu nutzen?</h2>
            <p class="text-light opacity-75 fs-5 mb-5">
                Erstelle kostenlos ein Konto und leg sofort los.
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
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
