<?php
$pageTitle       = 'Impressum – MKFB';
$pageDescription = 'Impressum von Markus Kogler\'s Filmbewertungen (MKFB) – Angaben gemäß § 5 TMG.';
require_once __DIR__ . '/includes/functions.php';
startSession();
require_once __DIR__ . '/includes/header.php';
?>

<main class="rank-page" style="padding-top:6px; padding-bottom: 4rem;">
<div class="container" style="max-width:720px;">

    <h1 class="fw-bold mb-1" style="color:#e8b84b;">Impressum</h1>
    <p class="text-white opacity-50 small mb-5">Angaben gemäß § 5 TMG</p>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">Verantwortlich für den Inhalt</h2>
        <p class="text-white opacity-75 mb-1">Markus Kogler</p>
        <p class="text-white opacity-75 mb-1">Peter-Schwingen-Str. 6</p>
        <p class="text-white opacity-75 mb-0">53177 Bonn</p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">Kontakt</h2>
        <p class="text-white opacity-75 mb-0">
            E-Mail:
            <a href="#" class="footer-link"
               data-user="MarkusKogler" data-domain="hotmail.com"
               onclick="this.href='mailto:'+this.dataset.user+'@'+this.dataset.domain; return true;">
                [E-Mail anzeigen]
            </a>
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">Hinweis zur Website</h2>
        <p class="text-white opacity-75">
            Diese Website ist ein privates, nicht-kommerzielles Freizeitprojekt.
            Es werden keine Waren oder Dienstleistungen angeboten und kein Umsatz erzielt.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">Haftung für Inhalte</h2>
        <p class="text-white opacity-75">
            Die Inhalte dieser Seite wurden mit größter Sorgfalt erstellt. Für die Richtigkeit,
            Vollständigkeit und Aktualität der Inhalte kann jedoch keine Gewähr übernommen werden.
            Als privater Diensteanbieter bin ich gemäß § 7 Abs. 1 TMG für eigene Inhalte auf diesen
            Seiten nach den allgemeinen Gesetzen verantwortlich.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">Haftung für Links</h2>
        <p class="text-white opacity-75">
            Diese Website enthält Links zu externen Websites Dritter, auf deren Inhalte kein Einfluss
            besteht. Für die Inhalte der verlinkten Seiten ist stets der jeweilige Anbieter oder
            Betreiber der Seiten verantwortlich. Zum Zeitpunkt der Verlinkung waren keine Rechtsverstöße
            erkennbar. Bei Bekanntwerden von Rechtsverletzungen werden derartige Links umgehend entfernt.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">Filmdaten</h2>
        <p class="text-white opacity-75">
            Filmdaten, Poster und Metadaten werden über die
            <a href="https://www.themoviedb.org" target="_blank" rel="noopener" class="footer-link">
                The Movie Database (TMDB)
            </a>
            API bereitgestellt. Diese Website verwendet die TMDB API, ist jedoch nicht von TMDB
            unterstützt oder zertifiziert.
        </p>
    </section>

    <section>
        <h2 class="h5 text-white fw-semibold mb-3">Urheberrecht</h2>
        <p class="text-white opacity-75 mb-0">
            Die durch den Seitenbetreiber erstellten Inhalte und Werke auf diesen Seiten unterliegen
            dem deutschen Urheberrecht. Die Vervielfältigung, Bearbeitung, Verbreitung und jede Art
            der Verwertung außerhalb der Grenzen des Urheberrechtes bedürfen der schriftlichen
            Zustimmung des jeweiligen Autors.
        </p>
    </section>

</div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
