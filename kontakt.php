<?php
$pageTitle       = 'Kontakt – MKFB';
$pageDescription = 'Kontaktformular von Markus Kogler\'s Filmbewertungen (MKFB).';
require_once __DIR__ . '/includes/functions.php';
startSession();
require_once __DIR__ . '/includes/header.php';

$success = false;
$errors  = [];
$fields  = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) {
        $errors[] = 'Ungültiges Sicherheitstoken. Bitte Seite neu laden und erneut versuchen.';
    } else {
        // Sanitize inputs
        $fields['name']    = trim(strip_tags($_POST['name']    ?? ''));
        $fields['email']   = trim(strip_tags($_POST['email']   ?? ''));
        $fields['subject'] = trim(strip_tags($_POST['subject'] ?? ''));
        $fields['message'] = trim(strip_tags($_POST['message'] ?? ''));

        // Validate
        if ($fields['name'] === '') {
            $errors[] = 'Bitte gib deinen Namen an.';
        } elseif (mb_strlen($fields['name']) > 100) {
            $errors[] = 'Der Name ist zu lang (max. 100 Zeichen).';
        }

        if ($fields['email'] === '') {
            $errors[] = 'Bitte gib deine E-Mail-Adresse an.';
        } elseif (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Die E-Mail-Adresse ist ungültig.';
        } elseif (mb_strlen($fields['email']) > 254) {
            $errors[] = 'Die E-Mail-Adresse ist zu lang.';
        }

        if ($fields['subject'] === '') {
            $errors[] = 'Bitte gib einen Betreff an.';
        } elseif (mb_strlen($fields['subject']) > 150) {
            $errors[] = 'Der Betreff ist zu lang (max. 150 Zeichen).';
        }

        if ($fields['message'] === '') {
            $errors[] = 'Bitte gib eine Nachricht ein.';
        } elseif (mb_strlen($fields['message']) < 10) {
            $errors[] = 'Die Nachricht ist zu kurz (min. 10 Zeichen).';
        } elseif (mb_strlen($fields['message']) > 5000) {
            $errors[] = 'Die Nachricht ist zu lang (max. 5000 Zeichen).';
        }

        if (empty($errors)) {
            $to      = 'MarkusKogler@hotmail.com';
            $subject = '[MKFB Kontakt] ' . $fields['subject'];
            $body    = "Name: {$fields['name']}\r\n"
                     . "E-Mail: {$fields['email']}\r\n"
                     . "Betreff: {$fields['subject']}\r\n\r\n"
                     . $fields['message'];

            // Headers: prevent header injection by removing CR/LF from name/email
            $safeName  = preg_replace('/[\r\n]/', '', $fields['name']);
            $safeEmail = preg_replace('/[\r\n]/', '', $fields['email']);
            $headers   = "From: MKFB Kontakt <noreply@mkfb.local>\r\n"
                       . "Reply-To: {$safeName} <{$safeEmail}>\r\n"
                       . "MIME-Version: 1.0\r\n"
                       . "Content-Type: text/plain; charset=UTF-8\r\n"
                       . "Content-Transfer-Encoding: 8bit\r\n"
                       . "X-Mailer: MKFB/1.0";

            if (mail($to, $subject, $body, $headers)) {
                $success = true;
                // Reset fields after success
                $fields = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
            } else {
                $errors[] = 'Die Nachricht konnte leider nicht gesendet werden. '
                          . 'Bitte versuche es später erneut oder schreibe direkt eine E-Mail.';
            }
        }
    }
}
?>

<main class="rank-page" style="padding-top:6px; padding-bottom: 4rem;">
<div class="container" style="max-width:640px;">

    <h1 class="fw-bold mb-1" style="color:#e8b84b;">Kontakt</h1>
    <p class="text-white opacity-50 small mb-5">Fragen, Feedback oder Anfragen zur Datenlöschung</p>

    <?php if ($success): ?>
    <div class="alert" style="background:rgba(40,167,69,.15); border:1px solid rgba(40,167,69,.4); color:#7dda8b; border-radius:8px; padding:1rem 1.25rem;">
        <i class="bi bi-check-circle-fill me-2"></i>
        Deine Nachricht wurde erfolgreich gesendet. Ich melde mich so bald wie möglich bei dir.
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert" style="background:rgba(220,53,69,.15); border:1px solid rgba(220,53,69,.4); color:#f08080; border-radius:8px; padding:1rem 1.25rem;">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="/kontakt.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

        <div class="mb-3">
            <label for="kf-name" class="form-label text-white opacity-75 small fw-semibold">Name <span class="text-danger">*</span></label>
            <input type="text" id="kf-name" name="name" class="form-control mkfb-input"
                   maxlength="100" required
                   value="<?= htmlspecialchars($fields['name']) ?>">
        </div>

        <div class="mb-3">
            <label for="kf-email" class="form-label text-white opacity-75 small fw-semibold">E-Mail-Adresse <span class="text-danger">*</span></label>
            <input type="email" id="kf-email" name="email" class="form-control mkfb-input"
                   maxlength="254" required
                   value="<?= htmlspecialchars($fields['email']) ?>">
        </div>

        <div class="mb-3">
            <label for="kf-subject" class="form-label text-white opacity-75 small fw-semibold">Betreff <span class="text-danger">*</span></label>
            <input type="text" id="kf-subject" name="subject" class="form-control mkfb-input"
                   maxlength="150" required
                   value="<?= htmlspecialchars($fields['subject']) ?>">
        </div>

        <div class="mb-4">
            <label for="kf-message" class="form-label text-white opacity-75 small fw-semibold">Nachricht <span class="text-danger">*</span></label>
            <textarea id="kf-message" name="message" class="form-control mkfb-input"
                      rows="7" maxlength="5000" required><?= htmlspecialchars($fields['message']) ?></textarea>
            <div class="text-end mt-1">
                <span id="kf-charcount" class="text-white opacity-25" style="font-size:.75rem;">0 / 5000</span>
            </div>
        </div>

        <div class="mb-4">
            <p class="text-white opacity-50" style="font-size:.8rem;">
                <i class="bi bi-shield-lock me-1"></i>
                Deine Angaben werden ausschließlich zur Beantwortung deiner Anfrage verwendet und nicht an Dritte weitergegeben.
                Weitere Informationen findest du in unserer
                <a href="/datenschutz.php" class="footer-link">Datenschutzerklärung</a>.
            </p>
        </div>

        <button type="submit" class="btn btn-gold">
            <i class="bi bi-send me-2"></i>Nachricht senden
        </button>
    </form>

</div>
</main>

<script>
(function () {
    const ta    = document.getElementById('kf-message');
    const count = document.getElementById('kf-charcount');
    if (!ta || !count) return;
    function update() {
        const n = ta.value.length;
        count.textContent = n.toLocaleString('de-DE') + ' / 5\u2009000';
        count.style.opacity = n > 4500 ? '1' : '';
        count.style.color   = n > 4800 ? '#f08080' : '';
    }
    ta.addEventListener('input', update);
    update();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
