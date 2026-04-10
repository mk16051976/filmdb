<?php
$pageTitle = 'Benutzerverwaltung – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

// Admin-only page
if (!isAdmin()) {
    header('Location: /index.php');
    exit;
}

$db        = getDB();
$currentId = (int)$_SESSION['user_id'];
$msg       = '';
$msgType   = 'success';

// Migration
$db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS community_excluded TINYINT(1) NOT NULL DEFAULT 0");
$db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_seen DATETIME NULL DEFAULT NULL");

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    $action   = $_POST['action']  ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    // Safety: never act on yourself
    if ($targetId > 0 && $targetId !== $currentId) {

        if ($action === 'toggle_block') {
            $stmt = $db->prepare('SELECT blocked, username FROM users WHERE id = ?');
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();
            if ($target) {
                $newBlocked = (int)$target['blocked'] === 1 ? 0 : 1;
                $db->prepare('UPDATE users SET blocked = ? WHERE id = ?')
                   ->execute([$newBlocked, $targetId]);
                $label = $newBlocked ? 'gesperrt' : 'entsperrt';
                $msg   = 'Benutzer "' . $target['username'] . '" wurde ' . $label . '.';
            }

        } elseif ($action === 'toggle_community') {
            $stmt = $db->prepare('SELECT community_excluded, username FROM users WHERE id = ?');
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();
            if ($target) {
                $newVal = (int)$target['community_excluded'] === 1 ? 0 : 1;
                $db->prepare('UPDATE users SET community_excluded = ? WHERE id = ?')
                   ->execute([$newVal, $targetId]);
                $label = $newVal ? 'aus Community-Ranglisten ausgeschlossen' : 'wieder in Community-Ranglisten aufgenommen';
                $msg   = 'Benutzer "' . $target['username'] . '" wurde ' . $label . '.';
            }

        } elseif ($action === 'delete') {
            $stmt = $db->prepare('SELECT username FROM users WHERE id = ?');
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();
            if ($target) {
                // Cascade-delete all related data
                $tourStmt = $db->prepare('SELECT id FROM user_tournaments WHERE user_id = ?');
                $tourStmt->execute([$targetId]);
                $tourIds = $tourStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($tourIds)) {
                    $ph = implode(',', array_fill(0, count($tourIds), '?'));
                    $db->prepare("DELETE FROM tournament_matches WHERE tournament_id IN ($ph)")->execute($tourIds);
                    $db->prepare("DELETE FROM tournament_films   WHERE tournament_id IN ($ph)")->execute($tourIds);
                }
                $db->prepare('DELETE FROM tournament_results    WHERE user_id = ?')->execute([$targetId]);
                $db->prepare('DELETE FROM user_tournaments      WHERE user_id = ?')->execute([$targetId]);
                $db->prepare('DELETE FROM user_position_ranking WHERE user_id = ?')->execute([$targetId]);
                $db->prepare('DELETE FROM comparisons           WHERE user_id = ?')->execute([$targetId]);
                $db->prepare('DELETE FROM user_ratings          WHERE user_id = ?')->execute([$targetId]);
                $db->prepare('DELETE FROM password_resets       WHERE user_id = ?')->execute([$targetId]);
                $db->prepare('DELETE FROM users                 WHERE id      = ?')->execute([$targetId]);
                $msg     = 'Benutzer "' . $target['username'] . '" und alle zugehoerigen Daten wurden geloescht.';
                $msgType = 'warning';
            }

        } elseif ($action === 'change_role') {
            $newRole = $_POST['role'] ?? '';
            if (in_array($newRole, ['Admin', 'Moderator', 'Autor', 'Bewerter'], true)) {
                $db->prepare('UPDATE users SET role = ? WHERE id = ?')
                   ->execute([$newRole, $targetId]);
                $msg = 'Rolle wurde auf "' . $newRole . '" geaendert.';
            }
        }
    } elseif ($targetId === $currentId) {
        $msg     = 'Du kannst dein eigenes Konto nicht sperren oder löschen.';
        $msgType = 'danger';
    }
}

// ── Load users ────────────────────────────────────────────────────────────────
$users = $db->query("
    SELECT id, username, email, role,
           COALESCE(blocked, 0) AS blocked,
           COALESCE(community_excluded, 0) AS community_excluded,
           COALESCE(gender, '–') AS gender,
           COALESCE(nationality, '–') AS nationality,
           COALESCE(birth_year, '–') AS birth_year,
           COALESCE(favorite_genre, '–') AS favorite_genre,
           created_at, last_seen
    FROM users
    ORDER BY created_at ASC
")->fetchAll();


require_once __DIR__ . '/includes/header.php';
?>

<style>
    body { background: #14325a !important; }
    .admin-table { border-collapse: separate; border-spacing: 0; width: 100%; }
    .admin-table th {
        background: rgba(232,184,75,.1);
        color: #e8b84b;
        font-size: .78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        padding: .65rem 1rem;
        border-bottom: 1px solid rgba(232,184,75,.2);
        white-space: nowrap;
    }
    .admin-table td {
        padding: .6rem 1rem;
        font-size: .88rem;
        color: rgba(255,255,255,.8);
        border-bottom: 1px solid rgba(255,255,255,.05);
        vertical-align: middle;
    }
    .admin-table tr:last-child td { border-bottom: none; }
    .admin-table tr:hover td { background: rgba(232,184,75,.04); }
    .admin-table tr.is-blocked td { opacity: .55; }
    .role-badge {
        display: inline-block;
        padding: .15rem .55rem;
        border-radius: 20px;
        font-size: .72rem;
        font-weight: 700;
    }
    .role-admin    { background: rgba(232,184,75,.2); color: #e8b84b; }
    .role-bewerter { background: rgba(255,255,255,.08); color: rgba(255,255,255,.55); }
    .blocked-badge { background: rgba(244,67,54,.2); color: #f44336; }
    .detail-row td { background: rgba(255,255,255,.02) !important; font-size: .8rem; color: rgba(255,255,255,.45); }
    .online-dot {
        display: inline-block; width: 8px; height: 8px; border-radius: 50%;
        flex-shrink: 0;
    }
    .online-dot.is-online  { background: #4caf50; box-shadow: 0 0 0 2px rgba(76,175,80,.25); }
    .online-dot.is-away    { background: #ff9800; box-shadow: 0 0 0 2px rgba(255,152,0,.25); }
    .online-dot.is-offline { background: rgba(255,255,255,.18); }
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 3px; }
    * { scrollbar-width: thin; scrollbar-color: rgba(232,184,75,.2) transparent; }
</style>

<main style="padding-top:6px; background:#14325a; flex:1;">

    <!-- Header -->
    <section class="py-5" style="background:linear-gradient(135deg,#14325a 0%,#1e3d7a 100%); border-bottom:1px solid rgba(232,184,75,.15);">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="fw-bold mb-1" style="color:#e8b84b;">
                        <i class="bi bi-people-fill me-2"></i>Benutzerverwaltung
                    </h1>
                    <p class="mb-0" style="color:rgba(255,255,255,.5);">Alle registrierten Benutzer verwalten</p>
                </div>
                <div class="col-auto text-end">
                    <div style="color:#e8b84b; font-size:2rem; font-weight:800; line-height:1;"><?= count($users) ?></div>
                    <div style="color:rgba(255,255,255,.45); font-size:.8rem;">Benutzer gesamt</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Admin-Sub-Nav -->
    <div style="background:#14325a; border-bottom:1px solid rgba(255,255,255,.06);">
        <div class="container">
            <div class="d-flex gap-4 py-2">
                <a href="/admin-benutzer.php"
                   style="color:#e8b84b; text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid #e8b84b; font-weight:600;">
                    <i class="bi bi-people me-1"></i>Benutzer
                </a>
                <a href="/admin-statistiken.php"
                   style="color:rgba(255,255,255,.5); text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid transparent;">
                    <i class="bi bi-bar-chart me-1"></i>Statistiken
                </a>
                <a href="/admin-news.php"
                   style="color:rgba(255,255,255,.5); text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid transparent;">
                    <i class="bi bi-newspaper me-1"></i>News
                </a>
                <a href="/admin-projekt.php"
                   style="color:rgba(255,255,255,.5); text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid transparent;">
                    <i class="bi bi-layers me-1"></i>Projekt
                </a>
            </div>
        </div>
    </div>

    <section class="py-4" style="background:#14325a;">
        <div class="container">

            <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> d-flex align-items-center gap-2 py-2 mb-4" style="max-width:700px;">
                <i class="bi bi-<?= $msgType === 'success' ? 'check-circle-fill' : ($msgType === 'warning' ? 'exclamation-triangle-fill' : 'x-circle-fill') ?>"></i>
                <?= e($msg) ?>
            </div>
            <?php endif; ?>

            <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden; overflow-x:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">ID</th>
                            <th>Benutzername</th>
                            <th>E-Mail</th>
                            <th>Rolle</th>
                            <th>Registriert</th>
                            <th style="width:70px; text-align:center;" title="Zuletzt aktiv (grün = < 5 Min., orange = < 30 Min.)">Online</th>
                            <th style="width:90px;">Status</th>
                            <th style="width:80px; text-align:center;">Gesperrt</th>
                            <th style="width:80px; text-align:center;" title="Aus Community-Ranglisten ausschließen">Community</th>
                            <th style="width:120px; text-align:center;">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                    <?php $isSelf = ((int)$u['id'] === $currentId); ?>
                    <tr class="<?= (int)$u['blocked'] ? 'is-blocked' : '' ?>">

                        <!-- ID -->
                        <td style="color:rgba(255,255,255,.3);"><?= $u['id'] ?></td>

                        <!-- Username -->
                        <td>
                            <div class="fw-semibold" style="color:#e0e0e0;">
                                <?= e($u['username']) ?>
                                <?php if ($isSelf): ?>
                                    <span class="ms-1" style="color:#e8b84b; font-size:.7rem;">(du)</span>
                                <?php endif; ?>
                            </div>
                            <!-- Profile details -->
                            <div style="color:rgba(255,255,255,.35); font-size:.75rem; margin-top:.15rem;">
                                <?= e($u['gender']) ?> &middot;
                                <?= e($u['nationality']) ?> &middot;
                                <?= e($u['birth_year']) ?> &middot;
                                <?= e($u['favorite_genre']) ?>
                            </div>
                        </td>

                        <!-- Email -->
                        <td style="color:rgba(255,255,255,.55);"><?= e($u['email']) ?></td>

                        <!-- Role (editable) -->
                        <td>
                            <?php if (!$isSelf): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action"     value="change_role">
                                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <select name="role" onchange="this.form.submit()"
                                        style="background:#1e3d7a; color:<?= $u['role'] === 'Admin' ? '#e8b84b' : 'rgba(255,255,255,.6)' ?>;
                                               border:1px solid rgba(255,255,255,.12); border-radius:6px;
                                               padding:.2rem .5rem; font-size:.78rem; cursor:pointer;">
                                    <option value="Bewerter"  <?= $u['role'] === 'Bewerter'  ? 'selected' : '' ?>>Bewerter</option>
                                    <option value="Autor"     <?= $u['role'] === 'Autor'     ? 'selected' : '' ?>>Autor</option>
                                    <option value="Moderator" <?= $u['role'] === 'Moderator' ? 'selected' : '' ?>>Moderator</option>
                                    <option value="Admin"     <?= $u['role'] === 'Admin'     ? 'selected' : '' ?>>Admin</option>
                                </select>
                            </form>
                            <?php else: ?>
                                <span class="role-badge role-<?= strtolower(e($u['role'])) ?>"><?= e($u['role']) ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- Registered -->
                        <td style="color:rgba(255,255,255,.45); white-space:nowrap;">
                            <?= date('d.m.Y', strtotime($u['created_at'])) ?>
                        </td>

                        <!-- Online status -->
                        <td style="text-align:center;">
                        <?php
                            $lastSeen = $u['last_seen'] ?? null;
                            if ($lastSeen) {
                                $diffMin = (int)round((time() - strtotime($lastSeen)) / 60);
                                if ($diffMin < 5) {
                                    $dotClass = 'is-online';
                                    $dotTitle = 'Online (vor ' . $diffMin . ' Min.)';
                                } elseif ($diffMin < 30) {
                                    $dotClass = 'is-away';
                                    $dotTitle = 'Kürzlich aktiv (vor ' . $diffMin . ' Min.)';
                                } else {
                                    $dotClass = 'is-offline';
                                    $hrs = $diffMin < 1440 ? $diffMin . ' Min.' : date('d.m.Y', strtotime($lastSeen));
                                    $dotTitle = 'Offline (vor ' . $hrs . ')';
                                }
                            } else {
                                $dotClass = 'is-offline';
                                $dotTitle = 'Noch nie gesehen';
                            }
                        ?>
                            <span class="online-dot <?= $dotClass ?>" title="<?= htmlspecialchars($dotTitle) ?>"></span>
                            <?php if (isset($diffMin) && $diffMin < 30): ?>
                            <span style="font-size:.68rem; color:rgba(255,255,255,.35); display:block; margin-top:2px;">
                                <?= $diffMin < 1 ? 'jetzt' : $diffMin . ' Min.' ?>
                            </span>
                            <?php endif; ?>
                        </td>

                        <!-- Status badge -->
                        <td>
                            <?php if ((int)$u['blocked']): ?>
                                <span class="role-badge blocked-badge"><i class="bi bi-slash-circle me-1"></i>Gesperrt</span>
                            <?php else: ?>
                                <span style="color:#4caf50; font-size:.78rem; font-weight:600;">
                                    <i class="bi bi-check-circle me-1"></i>Aktiv
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Block toggle -->
                        <td style="text-align:center;">
                            <?php if (!$isSelf): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action"     value="toggle_block">
                                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <div class="form-check form-switch d-flex justify-content-center mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           style="cursor:pointer; width:2.2em; height:1.2em;"
                                           onchange="this.form.submit()"
                                           <?= (int)$u['blocked'] ? 'checked' : '' ?>>
                                </div>
                            </form>
                            <?php else: ?>
                                <span style="color:rgba(255,255,255,.2); font-size:.8rem;">–</span>
                            <?php endif; ?>
                        </td>

                        <!-- Community exclude toggle -->
                        <td style="text-align:center;">
                            <?php if (!$isSelf): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action"     value="toggle_community">
                                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <div class="form-check form-switch d-flex justify-content-center mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           style="cursor:pointer; width:2.2em; height:1.2em;"
                                           title="<?= (int)$u['community_excluded'] ? 'Aus Community-Ranglisten ausgeschlossen – klicken zum Aufnehmen' : 'In Community-Ranglisten – klicken zum Ausschließen' ?>"
                                           onchange="this.form.submit()"
                                           <?= (int)$u['community_excluded'] ? 'checked' : '' ?>>
                                </div>
                            </form>
                            <?php else: ?>
                                <span style="color:rgba(255,255,255,.2); font-size:.8rem;">–</span>
                            <?php endif; ?>
                        </td>

                        <!-- Delete -->
                        <td style="text-align:center;">
                            <?php if (!$isSelf): ?>
                            <form method="post" style="display:inline;"
                                  onsubmit="return confirm('Benutzer »' + <?= json_encode($u['username']) ?> + '« wirklich löschen?\nAlle Daten werden unwiderruflich entfernt.')">
                                <input type="hidden" name="action"     value="delete">
                                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <button type="submit" class="btn btn-sm"
                                        style="background:rgba(244,67,54,.15); color:#f44336; border:1px solid rgba(244,67,54,.25); border-radius:6px; padding:.25rem .6rem;"
                                        title="Benutzer löschen">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php else: ?>
                                <span style="color:rgba(255,255,255,.2); font-size:.8rem;">–</span>
                            <?php endif; ?>
                        </td>

                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="mt-3" style="color:rgba(255,255,255,.25); font-size:.75rem;">
                <i class="bi bi-info-circle me-1"></i>
                Gesperrte Benutzer können sich nicht mehr anmelden und werden bei aktivem Login sofort abgemeldet.
                Aus der Community ausgeschlossene Benutzer fließen nicht in Community-Ranglisten ein.
                Du kannst dein eigenes Konto nicht sperren oder löschen.
            </p>


        </div>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
