<?php
$pageTitle = 'E-Mail bestätigen – MKFB';
require_once __DIR__ . '/includes/functions.php';
startSession();

if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$db    = getDB();
$token = trim($_GET['token'] ?? '');
$error = '';

// Sicherstellen, dass Tabellen existieren
$db->exec("CREATE TABLE IF NOT EXISTS email_verifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_user  (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL");

if (!$token) {
    $error = 'Kein Bestätigungstoken angegeben.';
} else {
    $stmt = $db->prepare(
        'SELECT ev.user_id, ev.expires_at, u.email_verified_at
         FROM email_verifications ev
         JOIN users u ON u.id = ev.user_id
         WHERE ev.token = ?'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        $error = 'Ungültiger oder bereits verwendeter Bestätigungslink.';
    } elseif ($row['email_verified_at'] !== null) {
        // Bereits verifiziert – einfach einloggen
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['user_id'];
        $db->prepare('DELETE FROM email_verifications WHERE token = ?')->execute([$token]);
        header('Location: /index.php?welcome=1');
        exit;
    } elseif (new DateTime() > new DateTime($row['expires_at'])) {
        $db->prepare('DELETE FROM email_verifications WHERE token = ?')->execute([$token]);
        $error = 'Dieser Bestätigungslink ist abgelaufen (gültig 24 Stunden). Bitte registriere dich erneut.';
    } else {
        $db->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([$row['user_id']]);
        $db->prepare('DELETE FROM email_verifications WHERE token = ?')->execute([$token]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['user_id'];
        header('Location: /index.php?welcome=1');
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="auth-page d-flex align-items-center">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="auth-card text-center">

                    <div class="mb-4">
                        <i class="bi bi-x-circle-fill" style="font-size:3rem; color:#f44336;"></i>
                        <h2 class="fw-bold mt-3 mb-1">Bestätigung fehlgeschlagen</h2>
                    </div>

                    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-4">
                        <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
                        <?= e($error) ?>
                    </div>

                    <a href="/register.php" class="btn btn-gold w-100 fw-semibold py-2 mb-3">
                        <i class="bi bi-person-plus me-2"></i>Neu registrieren
                    </a>
                    <a href="/login.php" class="text-gold fw-semibold text-decoration-none small">
                        <i class="bi bi-arrow-left me-1"></i>Zur Anmeldung
                    </a>

                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
