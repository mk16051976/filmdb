<?php
$pageTitle = 'Registrieren – MKFB';
require_once __DIR__ . '/includes/functions.php';
startSession();

if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$error = '';

$validGenders = ['männlich', 'weiblich', 'divers'];
$validGenres  = [
    'Action', 'Abenteuer', 'Animation', 'Biopic', 'Drama', 'Fantasy',
    'Horror', 'Komödie', 'Krimi', 'Liebesfilm', 'Musical',
    'Science-Fiction', 'Thriller', 'Western', 'Dokumentarfilm',
];
$validNationalities = [
    'Österreich','Deutschland','Schweiz',
    'Afghanistan','Ägypten','Albanien','Algerien','Angola','Argentinien',
    'Armenien','Aserbaidschan','Äthiopien','Australien','Bangladesch',
    'Belarus','Belgien','Bolivien','Bosnien und Herzegowina','Brasilien',
    'Bulgarien','Chile','China','Costa Rica','Dänemark','Ecuador',
    'Elfenbeinküste','El Salvador','Eritrea','Estland','Finnland',
    'Frankreich','Georgien','Ghana','Griechenland','Guatemala','Honduras',
    'Indien','Indonesien','Irak','Iran','Irland','Island','Israel',
    'Italien','Japan','Jordanien','Kambodscha','Kamerun','Kanada',
    'Kasachstan','Kenia','Kolumbien','Kosovo','Kroatien','Kuba',
    'Lettland','Libanon','Libyen','Litauen','Luxemburg','Malaysia',
    'Mali','Malta','Marokko','Mexiko','Moldawien','Mongolei',
    'Montenegro','Mosambik','Myanmar','Namibia','Nepal','Neuseeland',
    'Nicaragua','Niederlande','Nigeria','Nordkorea','Nordmazedonien',
    'Norwegen','Pakistan','Panama','Paraguay','Peru','Philippinen',
    'Polen','Portugal','Rumänien','Russland','Saudi-Arabien','Schweden',
    'Serbien','Singapur','Slowakei','Slowenien','Somalia','Spanien',
    'Sri Lanka','Südafrika','Südkorea','Sudan','Syrien','Tansania',
    'Thailand','Tschechien','Tunesien','Türkei','Turkmenistan','Uganda',
    'Ukraine','Ungarn','Uruguay','USA','Usbekistan','Venezuela',
    'Vereinigte Arabische Emirate','Vereinigtes Königreich','Vietnam',
    'Weißrussland','Zypern',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) { header('Location: /register.php'); exit; }
    $username      = trim($_POST['username']       ?? '');
    $email         = trim($_POST['email']          ?? '');
    $password      = $_POST['password']            ?? '';
    $confirm       = $_POST['confirm']             ?? '';
    $gender        = trim($_POST['gender']         ?? '');
    $nationality   = trim($_POST['nationality']    ?? '');
    $birthYear     = (int)($_POST['birth_year']    ?? 0);
    $favoriteGenre = trim($_POST['favorite_genre'] ?? '');

    if (strlen($username) < 3) {
        $error = 'Benutzername muss mindestens 3 Zeichen lang sein.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    } elseif (strlen($password) < 8) {
        $error = 'Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($password !== $confirm) {
        $error = 'Passwörter stimmen nicht überein.';
    } elseif (!$gender || !in_array($gender, $validGenders, true)) {
        $error = 'Bitte wähle ein gültiges Geschlecht aus.';
    } elseif (!$nationality || !in_array($nationality, $validNationalities, true)) {
        $error = 'Bitte wähle eine gültige Nationalität aus.';
    } elseif (!$birthYear || $birthYear < 1920 || $birthYear > (int)date('Y') - 5) {
        $error = 'Bitte wähle ein gültiges Geburtsjahr aus.';
    } elseif (!$favoriteGenre || !in_array($favoriteGenre, $validGenres, true)) {
        $error = 'Bitte wähle ein gültiges Lieblingsgenre aus.';
    } else {
        $result = registerUser(
            $username, $email, $password,
            $gender, $nationality, $birthYear, $favoriteGenre
        );
        if ($result === 'verify') {
            $verifyEmailSent = $email;
        } else {
            $error = $result;
        }
    }
}

$genres        = $validGenres;
$nationalities = array_merge(['–'], $validNationalities);

require_once __DIR__ . '/includes/header.php';
?>
<style>.auth-card .form-label, .auth-card .form-check-label { color: #1a1a1a !important; }</style>

<?php if (!empty($verifyEmailSent)): ?>
<main class="auth-page d-flex align-items-center py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="auth-card text-center">
                    <i class="bi bi-envelope-check-fill" style="font-size:3rem; color:#e8b84b;"></i>
                    <h2 class="fw-bold mt-3 mb-2">Bestätigungsmail gesendet</h2>
                    <p class="text-muted mb-4">
                        Wir haben eine E-Mail an <strong><?= e($verifyEmailSent) ?></strong> gesendet.
                        Bitte klicke auf den Link in der Mail, um dein Konto zu aktivieren.
                    </p>
                    <p class="small text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Der Link ist 24 Stunden gültig. Prüfe auch deinen Spam-Ordner.
                    </p>
                    <hr class="my-4">
                    <a href="/login.php" class="text-gold fw-semibold text-decoration-none small">
                        <i class="bi bi-arrow-left me-1"></i>Zur Anmeldung
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php exit; endif; ?>

<main class="auth-page d-flex align-items-center py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-9">
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
                        <h2 class="fw-bold mb-1">Konto erstellen</h2>
                        <p class="text-muted small">Tritt der MKFB Film-Community bei</p>
                    </div>

                    <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-4">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <?= e($error) ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <div class="row g-4">

                            <!-- ── Linke Spalte: Anmeldedaten ── -->
                            <div class="col-md-6">
                                <h6 class="fw-bold mb-3" style="color:#1a1a1a; font-size:.8rem; text-transform:uppercase; letter-spacing:.08em;">
                                    <i class="bi bi-person-lock me-2"></i>Anmeldedaten
                                </h6>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Benutzername</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                                        <input type="text" name="username" class="form-control"
                                               value="<?= e($_POST['username'] ?? '') ?>"
                                               placeholder="dein_name" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">E-Mail-Adresse</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                        <input type="email" name="email" class="form-control"
                                               value="<?= e($_POST['email'] ?? '') ?>"
                                               placeholder="deine@email.de" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Kennwort</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                        <input type="password" name="password" class="form-control"
                                               placeholder="Mindestens 8 Zeichen" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Kennwort wiederholen</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                        <input type="password" name="confirm" class="form-control"
                                               placeholder="Kennwort bestätigen" required>
                                    </div>
                                </div>
                            </div>

                            <!-- ── Rechte Spalte: Filmstatistik-Daten ── -->
                            <div class="col-md-6">
                                <h6 class="fw-bold mb-1" style="color:#1a1a1a; font-size:.8rem; text-transform:uppercase; letter-spacing:.08em;">
                                    <i class="bi bi-bar-chart me-2"></i>Filmstatistiken
                                </h6>
                                <p class="text-muted mb-3" style="font-size:.75rem;">
                                    Daten werden nur für anonyme Filmstatistiken verwendet.
                                    Felder mit <span class="text-danger">*</span> sind Pflichtfelder.
                                </p>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Geschlecht <span class="text-danger">*</span></label>
                                    <div class="d-flex gap-3 pt-1">
                                        <?php foreach (['männlich', 'weiblich', 'divers'] as $g): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="gender"
                                                   id="gender_<?= $g ?>" value="<?= $g ?>" required
                                                   <?= (($_POST['gender'] ?? '') === $g) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="gender_<?= $g ?>"><?= ucfirst($g) ?></label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Nationalität <span class="text-danger">*</span></label>
                                    <select name="nationality" class="form-select" required>
                                        <option value="">– bitte wählen –</option>
                                        <?php foreach ($nationalities as $n): ?>
                                            <?php if ($n === '–'): ?>
                                            <option disabled>──────────────</option>
                                            <?php else: ?>
                                            <option value="<?= e($n) ?>" <?= (($_POST['nationality'] ?? '') === $n) ? 'selected' : '' ?>><?= e($n) ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Geburtsjahr <span class="text-danger">*</span></label>
                                    <select name="birth_year" class="form-select" required>
                                        <option value="">– bitte wählen –</option>
                                        <?php for ($y = date('Y') - 5; $y >= 1920; $y--): ?>
                                        <option value="<?= $y ?>" <?= (((int)($_POST['birth_year'] ?? 0)) === $y) ? 'selected' : '' ?>><?= $y ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Lieblingsgenre <span class="text-danger">*</span></label>
                                    <select name="favorite_genre" class="form-select" required>
                                        <option value="">– bitte wählen –</option>
                                        <?php foreach ($genres as $genre): ?>
                                        <option value="<?= e($genre) ?>" <?= (($_POST['favorite_genre'] ?? '') === $genre) ? 'selected' : '' ?>><?= e($genre) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                        </div><!-- /row -->

                        <hr class="my-4" style="border-color:rgba(255,255,255,.1);">

                        <button type="submit" class="btn btn-gold w-100 fw-semibold py-2">
                            <i class="bi bi-person-plus-fill me-2"></i>Registrieren
                        </button>
                    </form>

                    <hr class="my-4">
                    <p class="text-center text-muted small mb-0">
                        Bereits ein Konto?
                        <a href="/login.php" class="text-gold fw-semibold text-decoration-none">Anmelden</a>
                    </p>

                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
