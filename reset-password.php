<?php
$pageTitle = 'Neues Passwort – MKFB';
require_once __DIR__ . '/includes/functions.php';
startSession();

if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$token = trim($_GET['token'] ?? '');
$reset = validateResetToken($token);

$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = trim($_POST['token'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    if (strlen($password) < 6) {
        $error = 'Passwort muss mindestens 6 Zeichen lang sein.';
    } elseif ($password !== $confirm) {
        $error = 'Passwörter stimmen nicht überein.';
    } elseif (!resetPassword($token, $password)) {
        $error = 'Der Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.';
    } else {
        $done = true;
    }

    // Re-validate token for display (might have been used)
    if (!$done) {
        $reset = validateResetToken($token);
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
                        <h2 class="fw-bold mb-1">Neues Passwort</h2>
                        <p class="text-muted small">Gib dein neues Passwort ein</p>
                    </div>

                    <?php if ($done): ?>

                    <div class="alert alert-success d-flex align-items-center gap-2 py-3">
                        <i class="bi bi-check-circle-fill flex-shrink-0"></i>
                        <div>
                            <strong>Passwort geändert!</strong><br>
                            <span class="small">Du kannst dich jetzt mit deinem neuen Passwort anmelden.</span>
                        </div>
                    </div>
                    <a href="/login.php" class="btn btn-gold w-100 fw-semibold py-2 mt-2">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Jetzt anmelden
                    </a>

                    <?php elseif (!$reset): ?>

                    <div class="alert alert-danger d-flex align-items-start gap-2 py-3">
                        <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
                        <div>
                            <strong>Link ungültig oder abgelaufen</strong><br>
                            <span class="small">Reset-Links sind 1 Stunde gültig und können nur einmal verwendet werden.</span>
                        </div>
                    </div>
                    <a href="/forgot-password.php" class="btn btn-gold w-100 fw-semibold py-2 mt-2">
                        <i class="bi bi-arrow-repeat me-2"></i>Neuen Link anfordern
                    </a>

                    <?php else: ?>

                    <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2 py-2">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <?= e($error) ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" novalidate>
                        <input type="hidden" name="token" value="<?= e($token) ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Neues Passwort</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" class="form-control"
                                       placeholder="Mindestens 6 Zeichen" required autofocus>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Passwort bestätigen</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" name="confirm" class="form-control"
                                       placeholder="Passwort wiederholen" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-gold w-100 fw-semibold py-2">
                            <i class="bi bi-shield-check me-2"></i>Passwort speichern
                        </button>
                    </form>

                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
