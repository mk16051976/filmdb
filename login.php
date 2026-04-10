<?php
$pageTitle = 'Anmelden – MKFB';
require_once __DIR__ . '/includes/functions.php';
startSession();

if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$error          = '';
$unverifiedEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) { header('Location: /login.php'); exit; }
    $action   = $_POST['action'] ?? 'login';
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($action === 'resend_verification' && $email) {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND email_verified_at IS NULL');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u) {
            sendVerificationEmail((int)$u['id'], $email);
        }
        // Immer gleiche Meldung (kein Hinweis ob Adresse existiert)
        $unverifiedEmail = $email;
    } else {
        $result = loginUser($email, $password);
        if ($result === true) {
            // Neuer User (Onboarding noch nicht erledigt) → Projektseite
            $db = getDB();
            try {
                $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS onboarding_done TINYINT(1) NOT NULL DEFAULT 0");
                $ods = $db->prepare("SELECT onboarding_done FROM users WHERE id = ?");
                $ods->execute([(int)$_SESSION['user_id']]);
                $onboardingDone = (bool)$ods->fetchColumn();
            } catch (\PDOException $e) { $onboardingDone = true; }
            header('Location: ' . ($onboardingDone ? '/welcome.php' : '/das-projekt.php'));
            exit;
        } elseif ($result === 'blocked') {
            $error = 'Dein Konto wurde gesperrt. Bitte wende dich an den Administrator.';
        } elseif ($result === 'rate_limited') {
            $error = 'Zu viele fehlgeschlagene Versuche. Bitte warte 15 Minuten.';
        } elseif ($result === 'unverified') {
            $unverifiedEmail = $email;
        } else {
            $error = 'E-Mail oder Passwort falsch.';
        }
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
                        <h2 class="fw-bold mb-1">Willkommen zurück</h2>
                        <p class="text-muted small">Melde dich bei MKFB an</p>
                    </div>

                    <?php if ($unverifiedEmail): ?>
                    <div class="alert alert-warning py-3 mb-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-envelope-exclamation-fill flex-shrink-0"></i>
                            <strong>E-Mail-Adresse nicht bestätigt</strong>
                        </div>
                        <p class="small mb-2">
                            Bitte klicke auf den Link in der Bestätigungsmail für
                            <strong><?= e($unverifiedEmail) ?></strong>.
                        </p>
                        <form method="POST">
                            <input type="hidden" name="action" value="resend_verification">
                            <input type="hidden" name="email"  value="<?= e($unverifiedEmail) ?>">
                            <button type="submit" class="btn btn-sm btn-warning fw-semibold">
                                <i class="bi bi-arrow-repeat me-1"></i>Bestätigungsmail erneut senden
                            </button>
                        </form>
                    </div>
                    <?php elseif ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2 py-2">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <?= e($error) ?>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['registered'])): ?>
                    <div class="alert alert-success d-flex align-items-center gap-2 py-2">
                        <i class="bi bi-check-circle-fill"></i>
                        Registrierung erfolgreich! Jetzt anmelden.
                    </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['blocked'])): ?>
                    <div class="alert alert-warning d-flex align-items-center gap-2 py-2">
                        <i class="bi bi-slash-circle-fill"></i>
                        Dein Konto wurde gesperrt. Bitte wende dich an den Administrator.
                    </div>
                    <?php endif; ?>

                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">E-Mail</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" class="form-control"
                                       value="<?= e($_POST['email'] ?? '') ?>"
                                       placeholder="deine@email.de" required autofocus>
                            </div>
                        </div>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-baseline">
                                <label class="form-label fw-semibold">Passwort</label>
                                <a href="/forgot-password.php"
                                   class="text-decoration-none small"
                                   style="color:rgba(232,184,75,.7);">Passwort vergessen?</a>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" class="form-control"
                                       placeholder="Dein Passwort" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-gold w-100 fw-semibold py-2">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Anmelden
                        </button>
                    </form>

                    <hr class="my-4">
                    <p class="text-center text-muted small mb-0">
                        Noch kein Konto?
                        <a href="/register.php" class="text-gold fw-semibold text-decoration-none">Jetzt registrieren</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
