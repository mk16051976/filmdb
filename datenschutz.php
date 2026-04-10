<?php
$pageTitle       = 'Datenschutzerklärung – MKFB';
$pageDescription = 'Datenschutzerklärung von Markus Kogler\'s Filmbewertungen (MKFB) gemäß DSGVO / GDPR.';
require_once __DIR__ . '/includes/functions.php';
startSession();
require_once __DIR__ . '/includes/header.php';
?>

<main class="rank-page" style="padding-top:6px; padding-bottom: 4rem;">
<div class="container" style="max-width:720px;">

    <h1 class="fw-bold mb-1" style="color:#e8b84b;">Datenschutzerklärung</h1>
    <p class="text-white opacity-50 small mb-5">Informationen gemäß Art. 13, 14 DSGVO · Stand: <?= date('F Y') ?></p>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">1. Verantwortlicher</h2>
        <p class="text-white opacity-75">
            Markus Kogler<br>
            Peter-Schwingen-Str. 6<br>
            53177 Bonn<br>
            E-Mail:
            <a href="#" class="footer-link"
               data-user="MarkusKogler" data-domain="hotmail.com"
               onclick="this.href='mailto:'+this.dataset.user+'@'+this.dataset.domain; return true;">
                [E-Mail anzeigen]
            </a>
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">2. Allgemeines zur Datenverarbeitung</h2>
        <p class="text-white opacity-75">
            Diese Website ist ein privates, nicht-kommerzielles Freizeitprojekt. Personenbezogene Daten
            werden nur in dem Umfang erhoben, der für den Betrieb der Website und die Bereitstellung
            der angebotenen Funktionen erforderlich ist. Eine Weitergabe an Dritte findet nicht statt,
            sofern keine gesetzliche Verpflichtung besteht.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">3. Hosting &amp; Server-Logdateien</h2>
        <p class="text-white opacity-75">
            Der Webserver speichert bei jedem Aufruf automatisch Informationen in Server-Logdateien.
            Dies sind: IP-Adresse des anfragenden Rechners, Datum und Uhrzeit des Abrufs, Name und URL
            der abgerufenen Datei, übertragene Datenmenge, Meldung über den Zugriffsstatus (HTTP-Statuscode)
            sowie Name und Version des Browsers (User-Agent).
        </p>
        <p class="text-white opacity-75 mb-0">
            Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an der Sicherheit
            und dem technischen Betrieb). Logdateien werden nach spätestens 7 Tagen gelöscht.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">4. Registrierung &amp; Nutzerkonto</h2>
        <p class="text-white opacity-75">
            Für die Nutzung des Bewertungssystems ist eine freiwillige Registrierung erforderlich.
            Dabei werden folgende Daten gespeichert:
        </p>
        <ul class="text-white opacity-75">
            <li>Benutzername (selbst gewählt)</li>
            <li>E-Mail-Adresse</li>
            <li>Passwort (als bcrypt-Hash, nicht im Klartext)</li>
            <li>Registrierungsdatum</li>
        </ul>
        <p class="text-white opacity-75 mb-0">
            Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO (Vertragsdurchführung / Nutzungsvertrag).
            Das Konto und alle zugehörigen Daten können jederzeit auf Anfrage vollständig gelöscht werden.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">5. Filmwertungen &amp; Ranglisten</h2>
        <p class="text-white opacity-75">
            Abgegebene Filmwertungen, Turnierergebnisse und Ranglisten werden dem jeweiligen
            Benutzerkonto zugeordnet gespeichert. Ranglisten können für andere registrierte
            Nutzer sichtbar sein (Community-Rangliste). Die Verarbeitung dient ausschließlich dem
            Betrieb der Plattformfunktionen (Art. 6 Abs. 1 lit. b DSGVO).
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">6. Privatnachrichten</h2>
        <p class="text-white opacity-75">
            Registrierte Nutzer können sich gegenseitig Privatnachrichten senden. Diese werden
            in verschlüsselter Datenbank gespeichert und sind nur für Absender und Empfänger
            einsehbar. Nachrichten können jederzeit gelöscht werden.
            Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">7. Cookies &amp; Sessions</h2>
        <p class="text-white opacity-75">
            Diese Website verwendet ausschließlich technisch notwendige Session-Cookies, die nach
            dem Schließen des Browsers gelöscht werden. Es werden keine Tracking- oder
            Marketing-Cookies eingesetzt. Eine Einwilligung ist für technisch notwendige Cookies
            gemäß § 25 Abs. 2 TTDSG nicht erforderlich.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">8. Filmdaten (TMDB)</h2>
        <p class="text-white opacity-75">
            Filmdaten, Poster und Metadaten werden über die API der
            <a href="https://www.themoviedb.org" target="_blank" rel="noopener" class="footer-link">
                The Movie Database (TMDB)
            </a> bereitgestellt. Beim Laden von Poster-Bildern wird eine Verbindung zu TMDB-Servern
            hergestellt. Dabei können technische Daten (IP-Adresse, Browser-Informationen) an TMDB
            übermittelt werden. Die Datenschutzrichtlinie von TMDB ist unter
            <a href="https://www.themoviedb.org/privacy-policy" target="_blank" rel="noopener" class="footer-link">themoviedb.org/privacy-policy</a> einsehbar.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 text-white fw-semibold mb-3">9. Ihre Rechte (DSGVO)</h2>
        <p class="text-white opacity-75 mb-2">Sie haben gegenüber dem Verantwortlichen folgende Rechte:</p>
        <ul class="text-white opacity-75">
            <li><strong class="text-white">Auskunft</strong> (Art. 15 DSGVO): Welche Daten über Sie gespeichert sind.</li>
            <li><strong class="text-white">Berichtigung</strong> (Art. 16 DSGVO): Korrektur unrichtiger Daten.</li>
            <li><strong class="text-white">Löschung</strong> (Art. 17 DSGVO): Vollständige Löschung Ihres Kontos und aller Daten.</li>
            <li><strong class="text-white">Einschränkung</strong> (Art. 18 DSGVO): Einschränkung der Verarbeitung.</li>
            <li><strong class="text-white">Widerspruch</strong> (Art. 21 DSGVO): Widerspruch gegen die Verarbeitung.</li>
            <li><strong class="text-white">Beschwerde</strong> (Art. 77 DSGVO): Bei einer Datenschutz-Aufsichtsbehörde (z. B.
                <a href="https://www.ldi.nrw.de" target="_blank" rel="noopener" class="footer-link">LDI NRW</a>).</li>
        </ul>
        <p class="text-white opacity-75 mb-0">
            Zur Ausübung Ihrer Rechte wenden Sie sich bitte über das
            <a href="/kontakt.php" class="footer-link">Kontaktformular</a> oder per E-Mail an den Verantwortlichen.
        </p>
    </section>

    <section>
        <h2 class="h5 text-white fw-semibold mb-3">10. Änderungen dieser Datenschutzerklärung</h2>
        <p class="text-white opacity-75 mb-0">
            Diese Datenschutzerklärung kann bei Änderungen der Website oder der gesetzlichen Anforderungen
            aktualisiert werden. Die jeweils aktuelle Version ist auf dieser Seite abrufbar.
            Stand: <?= date('d.m.Y') ?>.
        </p>
    </section>

</div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
