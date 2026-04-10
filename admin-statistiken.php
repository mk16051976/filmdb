<?php
$pageTitle = 'Statistiken – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if (!isAdmin()) {
    header('Location: /index.php');
    exit;
}

$db = getDB();

// ── Statistiken pro User ──────────────────────────────────────────────────────
// Aktivierte Filme = Einträge in user_position_ranking
// Exakt gerankt   = Länge des sorted-Arrays im letzten completed sort_sessions

$users = $db->query("
    SELECT u.id, u.username, u.email, u.role,
           COALESCE(upr.film_count, 0) AS activated_films
    FROM users u
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS film_count
        FROM user_position_ranking
        GROUP BY user_id
    ) upr ON upr.user_id = u.id
    ORDER BY u.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Für jeden User: Anzahl exakt sortierter Filme aus dem letzten completed sort_sessions
$sortStmt = $db->prepare("
    SELECT state FROM sort_sessions
    WHERE user_id = ? AND status = 'completed'
    ORDER BY created_at DESC LIMIT 1
");

$userStats = [];
foreach ($users as $u) {
    $sortStmt->execute([(int)$u['id']]);
    $row = $sortStmt->fetch(PDO::FETCH_ASSOC);
    $sortedCount = 0;
    $lastSortDate = null;
    if ($row) {
        $state = json_decode($row['state'], true);
        $sortedIds   = $state['sorted'] ?? $state['pending'][0] ?? [];
        $sortedCount = count($sortedIds);
    }

    // Datum der letzten Sort-Session
    $dateStmt = $db->prepare("
        SELECT created_at FROM sort_sessions
        WHERE user_id = ? AND status = 'completed'
        ORDER BY created_at DESC LIMIT 1
    ");
    $dateStmt->execute([(int)$u['id']]);
    $dateRow = $dateStmt->fetch(PDO::FETCH_ASSOC);
    $lastSortDate = $dateRow ? date('d.m.Y', strtotime($dateRow['created_at'])) : null;

    $userStats[] = [
        'id'              => $u['id'],
        'username'        => $u['username'],
        'email'           => $u['email'],
        'role'            => $u['role'],
        'activated_films' => (int)$u['activated_films'],
        'sorted_films'    => $sortedCount,
        'last_sort_date'  => $lastSortDate,
        'pct'             => $u['activated_films'] > 0
                             ? round($sortedCount / $u['activated_films'] * 100)
                             : 0,
    ];
}

// Gesamtzahlen
$totalActivated = array_sum(array_column($userStats, 'activated_films'));
$totalSorted    = array_sum(array_column($userStats, 'sorted_films'));

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body { background: #14325a !important; }
    .stat-table { border-collapse: separate; border-spacing: 0; width: 100%; }
    .stat-table th {
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
    .stat-table td {
        padding: .65rem 1rem;
        font-size: .88rem;
        color: rgba(255,255,255,.8);
        border-bottom: 1px solid rgba(255,255,255,.05);
        vertical-align: middle;
    }
    .stat-table tr:last-child td { border-bottom: none; }
    .stat-table tr:hover td { background: rgba(232,184,75,.04); }
    .role-badge {
        display: inline-block; padding: .15rem .55rem;
        border-radius: 20px; font-size: .72rem; font-weight: 700;
    }
    .role-admin    { background: rgba(232,184,75,.2); color: #e8b84b; }
    .role-bewerter { background: rgba(255,255,255,.08); color: rgba(255,255,255,.55); }
    .prog-track { background: rgba(255,255,255,.07); border-radius: 4px; height: 6px; min-width: 80px; }
    .prog-fill  { background: #e8b84b; border-radius: 4px; height: 6px; transition: width .3s; }
    .stat-card {
        background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
        border-radius: 12px; padding: 1.2rem 1.5rem;
    }
    .admin-nav a { color: rgba(255,255,255,.45); font-size:.85rem; text-decoration:none; transition:color .2s; }
    .admin-nav a:hover, .admin-nav a.active { color: #e8b84b; }
    .admin-nav a.active { border-bottom: 2px solid #e8b84b; padding-bottom: 2px; }
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
                        <i class="bi bi-bar-chart-fill me-2"></i>Statistiken
                    </h1>
                    <p class="mb-0" style="color:rgba(255,255,255,.5);">Aktivierte & exakt gerankte Filme je Benutzer</p>
                </div>
                <div class="col-auto d-flex gap-4 text-end">
                    <div>
                        <div style="color:#e8b84b; font-size:1.8rem; font-weight:800; line-height:1;"><?= $totalActivated ?></div>
                        <div style="color:rgba(255,255,255,.4); font-size:.75rem;">Aktiviert gesamt</div>
                    </div>
                    <div>
                        <div style="color:#4caf50; font-size:1.8rem; font-weight:800; line-height:1;"><?= $totalSorted ?></div>
                        <div style="color:rgba(255,255,255,.4); font-size:.75rem;">Exakt gerankt gesamt</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Admin-Sub-Nav -->
    <div style="background:#14325a; border-bottom:1px solid rgba(255,255,255,.06);">
        <div class="container">
            <div class="d-flex gap-4 py-2">
                <a href="/admin-benutzer.php"
                   style="color:rgba(255,255,255,.5); text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid transparent;">
                    <i class="bi bi-people me-1"></i>Benutzer
                </a>
                <a href="/admin-statistiken.php"
                   style="color:#e8b84b; text-decoration:none; font-size:.85rem; padding:.35rem 0; border-bottom:2px solid #e8b84b; font-weight:600;">
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

            <?php if (empty($userStats)): ?>
            <div class="text-center py-5">
                <i class="bi bi-people" style="font-size:3rem; color:rgba(232,184,75,.3);"></i>
                <h4 class="mt-3" style="color:rgba(255,255,255,.6);">Keine Benutzer vorhanden</h4>
            </div>
            <?php else: ?>

            <div style="border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden; overflow-x:auto;">
                <table class="stat-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">ID</th>
                            <th>Benutzer</th>
                            <th>Rolle</th>
                            <th style="text-align:right;">Aktivierte Filme</th>
                            <th style="text-align:right;">Exakt gerankt</th>
                            <th style="min-width:120px;">Fortschritt</th>
                            <th style="text-align:right;">Letzter Sort</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($userStats as $s): ?>
                    <tr>
                        <td style="color:rgba(255,255,255,.3);"><?= $s['id'] ?></td>

                        <td>
                            <div class="fw-semibold" style="color:#e0e0e0;"><?= e($s['username']) ?></div>
                            <div style="color:rgba(255,255,255,.35); font-size:.75rem;"><?= e($s['email']) ?></div>
                        </td>

                        <td>
                            <span class="role-badge role-<?= strtolower(e($s['role'])) ?>"><?= e($s['role']) ?></span>
                        </td>

                        <td style="text-align:right;">
                            <?php if ($s['activated_films'] > 0): ?>
                                <span style="color:#e8b84b; font-weight:600;"><?= $s['activated_films'] ?></span>
                            <?php else: ?>
                                <span style="color:rgba(255,255,255,.25);">–</span>
                            <?php endif; ?>
                        </td>

                        <td style="text-align:right;">
                            <?php if ($s['sorted_films'] > 0): ?>
                                <span style="color:#4caf50; font-weight:600;"><?= $s['sorted_films'] ?></span>
                            <?php else: ?>
                                <span style="color:rgba(255,255,255,.25);">–</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($s['activated_films'] > 0): ?>
                            <div class="d-flex align-items-center gap-2">
                                <div class="prog-track flex-grow-1">
                                    <div class="prog-fill" style="width:<?= $s['pct'] ?>%;"></div>
                                </div>
                                <span style="color:rgba(255,255,255,.4); font-size:.75rem; white-space:nowrap;">
                                    <?= $s['pct'] ?>%
                                </span>
                            </div>
                            <?php else: ?>
                                <span style="color:rgba(255,255,255,.2); font-size:.8rem;">–</span>
                            <?php endif; ?>
                        </td>

                        <td style="text-align:right; color:rgba(255,255,255,.4); font-size:.82rem; white-space:nowrap;">
                            <?= $s['last_sort_date'] ?? '–' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="mt-3" style="color:rgba(255,255,255,.25); font-size:.75rem;">
                <i class="bi bi-info-circle me-1"></i>
                <strong style="color:rgba(255,255,255,.35);">Aktivierte Filme</strong> = Einträge in der persönlichen ELO-Rangliste (user_position_ranking).
                <strong style="color:rgba(255,255,255,.35);">Exakt gerankt</strong> = Filme in der letzten abgeschlossenen Sortier-Session (Merge/Insert Sort).
                <strong style="color:rgba(255,255,255,.35);">Fortschritt</strong> = Anteil der exakt gerankten an den aktivierten Filmen.
            </p>

            <?php endif; ?>

        </div>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
