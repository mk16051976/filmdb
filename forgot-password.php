<?php
$pageTitle = 'Passwort vergessen – MKFB';
require_once __DIR__ . '/includes/functions.php';
startSession();

if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) { $sent = true; return; } // silently ignore (no token reveal)
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $email = trim($_POST['email'] ?? '');
    if (isRateLimited($ip)) {
        $sent = true; // Rate-Limit nicht verraten
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    } else {
        createPasswordReset($email);
        $sent = true; // always show success (don't reveal if address exists)
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="auth-page d-flex align-items-center">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="auth-card">

                    <div class="text-center mb-4">
                        <a href="/index.php" class="text-decoration-none">
                            <svg width="48" height="48" viewBox="0 0 32 32" fill="none" class="mb-3">
                                <circle cx="16" cy="16" r="15" stroke="#e8b84b" stroke-width="2"/>
                                <circle cx="16" cy="16" r="6" fill="#e8b84b"/>
                                <line x1="16" y1="1" x2="16" y2="7" stroke="#e8b84b" stroke-width="2"/>
                                <line x1="16" y1="25" x2="16" y2="31" stroke="#e8b84b" stroke-width="2"/>
                                <line x1="1" y1="16" x2="7" y2="16" stroke="#e8b84b" stroke-width="2"/>
                                <line x1="25" y1="16" x2="31" y2="16" stroke="#e8b84b" stroke-width="2"/>
                            </svg>
                        </a>
                        <h2 class="fw-bold mb-1">Passwort zurücksetzen</h2>
                        <p class="text-muted small">Wir senden dir einen Reset-Link per E-Mail</p>
                    </div>

                    <?php if ($sent): ?>

                    <div class="alert alert-success d-flex align-items-start gap-2 py-3">
                        <i class="bi bi-envelope-check-fill mt-1 flex-shrink-0"></i>
                        <div>
                            <strong>E-Mail gesendet</strong><br>
                            <span class="small">Falls ein Konto mit dieser Adresse existiert, hast du in Kürze eine E-Mail mit dem Reset-Link erhalten. Bitte prüfe auch deinen Spam-Ordner.</span>
                        </div>
                    </div>
                    <div class="text-center mt-3">
                        <a href="/login.php" class="text-gold fw-semibold text-decoration-none small">
                            <i class="bi bi-arrow-left me-1"></i>Zurück zur Anmeldung
                        </a>
                    </div>

                    <?php else: ?>

                    <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2 py-2">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <?= e($error) ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <div class="mb-4">
                            <label class="form-label fw-semibold">E-Mail-Adresse</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" class="form-control"
                                       value="<?= e($_POST['email'] ?? '') ?>"
                                       placeholder="deine@email.de" required autofocus>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-gold w-100 fw-semibold py-2">
                            <i class="bi bi-send me-2"></i>Reset-Link senden
                        </button>
                    </form>

                    <hr class="my-4">
                    <p class="text-center text-muted small mb-0">
                        <a href="/login.php" class="text-gold fw-semibold text-decoration-none">
                            <i class="bi bi-arrow-left me-1"></i>Zurück zur Anmeldung
                        </a>
                    </p>

                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
