<?php
$pageTitle = 'Willkommen – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$user   = currentUser();

// ── Gesamtzahl Filme in der DB (gecacht, 10 Min) ──────────────────────────
$totalFilms = dbCache('welcome_total_films', fn() =>
    (int)getDB()->query("SELECT COUNT(*) FROM movies")->fetchColumn()
, 600);

// ── Alle Community-Rankings gecacht (5 Min TTL) ───────────────────────────
// Die teuren Aggregations-Queries laufen nur alle 5 Min neu,
// nicht bei jedem Request.
$communityData = dbCache('welcome_community_data', function() {
    $db = getDB();
    $result = ['films' => [], 'series' => [], 'rankingLeaders' => [],
               'activeFilmLeaders' => [], 'ratingLeaders' => []];
    try {
        // Gemeinsame WHERE-Bedingung für "aktive Community-User"
        // Mit Temp-Tabelle per IN-Subquery für saubere Wiederverwendung
        $communityBase = "
            SELECT
                m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en,
                AVG(upr.position)           AS avg_rank,
                COUNT(DISTINCT upr.user_id) AS user_count
            FROM user_position_ranking upr
            JOIN movies m ON m.id = upr.movie_id
            JOIN users u ON u.id = upr.user_id
                AND COALESCE(u.community_excluded, 0) = 0
            WHERE upr.user_id IN (
                SELECT user_id FROM user_tournaments WHERE status = 'completed'
            )
            AND %s
            GROUP BY m.id, m.title, m.title_en, m.year, m.poster_path, m.poster_path_en
            ORDER BY avg_rank ASC, user_count DESC
            LIMIT 20
        ";
        $result['films']  = $db->query(sprintf($communityBase, "COALESCE(m.media_type,'movie') != 'tv'"))->fetchAll();
        $result['series'] = $db->query(sprintf($communityBase, "COALESCE(m.media_type,'movie')  = 'tv'"))->fetchAll();

        $result['rankingLeaders'] = $db->query("
            SELECT u.username, COUNT(upr.movie_id) AS cnt
            FROM users u
            JOIN user_position_ranking upr ON upr.user_id = u.id
            WHERE COALESCE(u.community_excluded, 0) = 0
              AND u.id IN (SELECT user_id FROM user_tournaments WHERE status = 'completed')
            GROUP BY u.id, u.username
            ORDER BY cnt DESC LIMIT 10
        ")->fetchAll();

        $result['activeFilmLeaders'] = $db->query("
            SELECT u.username, COUNT(DISTINCT ur.movie_id) AS cnt
            FROM users u
            JOIN user_ratings ur ON ur.user_id = u.id AND ur.comparisons > 0
            WHERE COALESCE(u.community_excluded, 0) = 0
              AND u.id IN (SELECT user_id FROM user_tournaments WHERE status = 'completed')
            GROUP BY u.id, u.username
            HAVING cnt > 0
            ORDER BY cnt DESC LIMIT 10
        ")->fetchAll();

        $result['ratingLeaders'] = $db->query("
            SELECT u.username, COUNT(c.id) AS cnt
            FROM users u
            JOIN comparisons c ON c.user_id = u.id
            WHERE COALESCE(u.community_excluded, 0) = 0
              AND u.id IN (SELECT user_id FROM user_tournaments WHERE status = 'completed')
            GROUP BY u.id, u.username
            HAVING cnt > 0
            ORDER BY cnt DESC LIMIT 10
        ")->fetchAll();
    } catch (\PDOException $e) {}
    return $result;
}, 300);

$communityTop20Films  = $communityData['films'];
$communityTop20Series = $communityData['series'];
$rankingLeaders       = $communityData['rankingLeaders'];
$activeFilmLeaders    = $communityData['activeFilmLeaders'];
$ratingLeaders        = $communityData['ratingLeaders'];

// ── Laufende Aktionen ──────────────────────────────────────────────────────
$activeActions = [];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS action_lists (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL, description TEXT NULL,
        start_date DATE NOT NULL, end_date DATE NOT NULL,
        created_by INT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS action_list_films (
        list_id INT UNSIGNED NOT NULL, movie_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (list_id, movie_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $db->query("
        SELECT al.id, al.name, al.description, al.start_date, al.end_date,
               COUNT(DISTINCT alf.movie_id) AS film_count
        FROM action_lists al
        LEFT JOIN action_list_films alf ON alf.list_id = al.id
        WHERE CURDATE() BETWEEN al.start_date AND al.end_date
        GROUP BY al.id, al.name, al.description, al.start_date, al.end_date
        ORDER BY al.end_date ASC
    ");
    $activeActions = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (\PDOException $e) { $activeActions = []; }

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body { background: #14325a !important; }
    .welcome-hero {
        background: linear-gradient(135deg, rgba(20,50,90,.95) 0%, rgba(14,30,58,1) 100%);
        border: 1px solid rgba(232,184,75,.25);
        border-radius: 16px;
        padding: 2.5rem 2rem;
    }
    .welcome-stat {
        background: rgba(255,255,255,.04);
        border: 1px solid rgba(232,184,75,.18);
        border-radius: 12px;
        padding: 1.2rem 1.5rem;
        text-align: center;
    }
    .welcome-stat .stat-num {
        font-size: 2.2rem;
        font-weight: 900;
        color: #e8b84b;
        line-height: 1;
    }
    .welcome-stat .stat-label {
        font-size: .8rem;
        opacity: .65;
        margin-top: .3rem;
    }
    .section-card {
        background: rgba(255,255,255,.03);
        border: 1px solid rgba(232,184,75,.15);
        border-radius: 14px;
        overflow: hidden;
    }
    .section-card .section-head {
        background: rgba(232,184,75,.08);
        border-bottom: 1px solid rgba(232,184,75,.15);
        padding: .75rem 1.25rem;
        font-weight: 700;
        font-size: .95rem;
        color: #e8b84b;
    }
    .rank-row {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .55rem 1.25rem;
        border-bottom: 1px solid rgba(255,255,255,.04);
        font-size: .88rem;
    }
    .rank-row:last-child { border-bottom: none; }
    .rank-row:hover { background: rgba(232,184,75,.05); }
    .rank-num {
        width: 24px;
        text-align: right;
        font-weight: 700;
        opacity: .5;
        font-size: .8rem;
        flex-shrink: 0;
    }
    .rank-num.gold { opacity: 1; color: #e8b84b; }
    .rank-num.silver { opacity: 1; color: #b0bec5; }
    .rank-num.bronze { opacity: 1; color: #cd7f32; }
    .rank-bar-wrap { flex: 1; }
    .rank-bar-track { background: rgba(255,255,255,.07); border-radius: 4px; height: 4px; margin-top: 4px; }
    .rank-bar-fill  { background: linear-gradient(90deg, #e8b84b, #f5d07a); border-radius: 4px; height: 4px; }
    .poster-mini {
        width: 32px;
        height: 48px;
        object-fit: cover;
        border-radius: 3px;
        border: 1px solid rgba(255,255,255,.1);
        flex-shrink: 0;
    }
    .aktion-banner {
        background: linear-gradient(135deg, rgba(232,184,75,.12) 0%, rgba(232,184,75,.05) 100%);
        border: 1px solid rgba(232,184,75,.35);
        border-radius: 14px;
        padding: 1.1rem 1.4rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        text-decoration: none;
        color: inherit;
        transition: background .2s, border-color .2s;
    }
    .aktion-banner:hover {
        background: linear-gradient(135deg, rgba(232,184,75,.2) 0%, rgba(232,184,75,.1) 100%);
        border-color: rgba(232,184,75,.6);
        color: inherit;
    }
    .aktion-pulse {
        width: 10px; height: 10px;
        border-radius: 50%;
        background: #4caf50;
        box-shadow: 0 0 0 0 rgba(76,175,80,.6);
        animation: pulse 1.8s infinite;
        flex-shrink: 0;
    }
    @keyframes pulse {
        0%   { box-shadow: 0 0 0 0 rgba(76,175,80,.6); }
        70%  { box-shadow: 0 0 0 8px rgba(76,175,80,0); }
        100% { box-shadow: 0 0 0 0 rgba(76,175,80,0); }
    }
</style>

<main class="container py-4">

    <!-- ── Begrüßung ──────────────────────────────────────────────────────── -->
    <div class="welcome-hero text-center mb-4">
        <div class="text-gold mb-2" style="font-size:2.8rem; line-height:1;">
            <i class="bi bi-film"></i>
        </div>
        <h1 class="fw-black mb-1" style="font-size:2rem;">
            Willkommen zurück, <span style="color:#e8b84b;"><?= e($user['username'] ?? 'User') ?></span>!
        </h1>
        <p class="opacity-60 mb-4 small">Hier ist der aktuelle Stand der Community.</p>
        <div class="row g-3 justify-content-center">
            <div class="col-6 col-md-3">
                <div class="welcome-stat">
                    <div class="stat-num"><?= number_format($totalFilms) ?></div>
                    <div class="stat-label"><i class="bi bi-collection-fill me-1"></i>Filme in der DB</div>
                </div>
            </div>
            <?php
            $myRankCount = 0;
            try {
                $s = $db->prepare("SELECT COUNT(*) FROM user_position_ranking WHERE user_id = ?");
                $s->execute([$userId]);
                $myRankCount = (int)$s->fetchColumn();
            } catch (\PDOException $e) {}
            $myDuels = 0;
            try {
                $s = $db->prepare("SELECT COUNT(*) FROM comparisons WHERE user_id = ?");
                $s->execute([$userId]);
                $myDuels = (int)$s->fetchColumn();
            } catch (\PDOException $e) {}
            ?>
            <div class="col-6 col-md-3">
                <div class="welcome-stat">
                    <div class="stat-num"><?= number_format($myRankCount) ?></div>
                    <div class="stat-label"><i class="bi bi-list-ol me-1"></i>Deine gerankten Filme</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="welcome-stat">
                    <div class="stat-num"><?= number_format($myDuels) ?></div>
                    <div class="stat-label"><i class="bi bi-lightning-fill me-1"></i>Deine Bewertungen</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="welcome-stat">
                    <div class="stat-num"><?= count($communityTop20Films) + count($communityTop20Series) ?></div>
                    <div class="stat-label"><i class="bi bi-people-fill me-1"></i>Community-Ranking Titel</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($activeActions)): ?>
    <!-- ── Laufende Aktionen ──────────────────────────────────────────────── -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-2 mb-2" style="color:rgba(255,255,255,.45); font-size:.78rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em;">
            <span class="aktion-pulse"></span>
            Laufende Aktionen
        </div>
        <div class="d-flex flex-column gap-2">
        <?php foreach ($activeActions as $act):
            $daysLeft = (int)ceil((strtotime($act['end_date']) - time()) / 86400);
        ?>
        <a href="/aktionen.php?list=<?= (int)$act['id'] ?>" class="aktion-banner">
            <i class="bi bi-trophy-fill" style="color:#e8b84b; font-size:1.4rem; flex-shrink:0;"></i>
            <div style="flex:1; min-width:0;">
                <div class="fw-bold" style="color:#e8b84b; font-size:1rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    <?= htmlspecialchars($act['name']) ?>
                </div>
                <div style="font-size:.78rem; color:rgba(255,255,255,.45); margin-top:.15rem;">
                    <?php if (!empty($act['description'])): ?>
                        <?= htmlspecialchars($act['description']) ?> &nbsp;&middot;&nbsp;
                    <?php endif; ?>
                    <?= (int)$act['film_count'] ?> Filme
                    &nbsp;&middot;&nbsp;
                    endet <?= date('d.m.Y', strtotime($act['end_date'])) ?>
                </div>
            </div>
            <div class="text-end flex-shrink-0">
                <div style="color:#4caf50; font-size:.78rem; font-weight:700;">
                    <?= $daysLeft <= 1 ? 'Heute letzter Tag!' : 'Noch ' . $daysLeft . ' Tag' . ($daysLeft !== 1 ? 'e' : '') ?>
                </div>
                <div style="color:rgba(232,184,75,.7); font-size:.75rem; margin-top:.2rem;">
                    Jetzt mitmachen <i class="bi bi-arrow-right"></i>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── Top 20 Community Rangliste (Filme / Serien) ────────────────── -->
        <div class="col-lg-6">
            <div class="section-card h-100 d-flex flex-column">
                <div class="section-head d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <span><i class="bi bi-bar-chart-fill me-2"></i>Top 20 Community Rangliste</span>
                    <div class="d-flex gap-1">
                        <button class="comm-tab-btn active" data-tab="comm-filme" style="font-size:.75rem; padding:.2rem .6rem; border-radius:6px; border:1px solid rgba(232,184,75,.4); background:rgba(232,184,75,.15); color:#e8b84b; cursor:pointer; font-weight:700;">
                            <i class="bi bi-camera-reels me-1"></i>Filme
                        </button>
                        <button class="comm-tab-btn" data-tab="comm-serien" style="font-size:.75rem; padding:.2rem .6rem; border-radius:6px; border:1px solid rgba(255,255,255,.15); background:transparent; color:rgba(255,255,255,.45); cursor:pointer; font-weight:600;">
                            <i class="bi bi-tv me-1"></i>Serien
                        </button>
                    </div>
                </div>

                <!-- Filme -->
                <div id="comm-filme" class="comm-tab-pane" style="flex:1; overflow-y:auto;">
                <?php if (empty($communityTop20Films)): ?>
                    <div class="rank-row opacity-50">Noch keine Filmdaten vorhanden.</div>
                <?php else:
                    $maxRankF = (float)($communityTop20Films[count($communityTop20Films)-1]['avg_rank'] ?: 1);
                    foreach ($communityTop20Films as $i => $film):
                        $rank = $i + 1;
                        $numClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                        $barPct = $maxRankF > 0 ? round((1 - ($film['avg_rank'] - 1) / max($maxRankF - 1, 1)) * 100) : 100;
                ?>
                <div class="rank-row">
                    <div class="rank-num <?= $numClass ?>"><?= $rank ?></div>
                    <img src="<?= e(moviePosterUrl($film, 'w92')) ?>"
                         class="poster-mini" alt="<?= e(movieTitle($film)) ?>"
                         onerror="this.style.visibility='hidden'">
                    <div class="rank-bar-wrap">
                        <div class="fw-semibold" style="font-size:.85rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:200px;">
                            <?= e(movieTitle($film)) ?>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div class="rank-bar-track" style="width:70px;">
                                <div class="rank-bar-fill" style="width:<?= $barPct ?>%;"></div>
                            </div>
                            <span style="font-size:.72rem; opacity:.5;">
                                Ø <?= number_format((float)$film['avg_rank'], 1) ?>
                                &middot; <?= $film['user_count'] ?> User
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
                </div>

                <!-- Serien -->
                <div id="comm-serien" class="comm-tab-pane" style="flex:1; overflow-y:auto; display:none;">
                <?php if (empty($communityTop20Series)): ?>
                    <div class="rank-row opacity-50">Noch keine Seriendaten vorhanden.</div>
                <?php else:
                    $maxRankS = (float)($communityTop20Series[count($communityTop20Series)-1]['avg_rank'] ?: 1);
                    foreach ($communityTop20Series as $i => $film):
                        $rank = $i + 1;
                        $numClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                        $barPct = $maxRankS > 0 ? round((1 - ($film['avg_rank'] - 1) / max($maxRankS - 1, 1)) * 100) : 100;
                ?>
                <div class="rank-row">
                    <div class="rank-num <?= $numClass ?>"><?= $rank ?></div>
                    <img src="<?= e(moviePosterUrl($film, 'w92')) ?>"
                         class="poster-mini" alt="<?= e(movieTitle($film)) ?>"
                         onerror="this.style.visibility='hidden'">
                    <div class="rank-bar-wrap">
                        <div class="fw-semibold" style="font-size:.85rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:200px;">
                            <?= e(movieTitle($film)) ?>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div class="rank-bar-track" style="width:70px;">
                                <div class="rank-bar-fill" style="width:<?= $barPct ?>%;"></div>
                            </div>
                            <span style="font-size:.72rem; opacity:.5;">
                                Ø <?= number_format((float)$film['avg_rank'], 1) ?>
                                &middot; <?= $film['user_count'] ?> User
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
                </div>

            </div>
        </div>

        <!-- ── Rechte Spalte: 3 Leaderboards ──────────────────────────────── -->
        <div class="col-lg-6 d-flex flex-column gap-4">

            <!-- Top 10: Rangliste vollständigste User -->
            <div class="section-card">
                <div class="section-head">
                    <i class="bi bi-list-stars me-2"></i>User Rangliste – Meiste Filme gerankt
                </div>
                <?php if (empty($rankingLeaders)): ?>
                    <div class="rank-row opacity-50">Noch keine Daten.</div>
                <?php else:
                    $maxR = (int)($rankingLeaders[0]['cnt'] ?: 1);
                    foreach ($rankingLeaders as $i => $row):
                        $rank = $i + 1;
                        $numClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                        $barPct = $maxR > 0 ? round($row['cnt'] / $maxR * 100) : 0;
                ?>
                <div class="rank-row">
                    <div class="rank-num <?= $numClass ?>"><?= $rank ?></div>
                    <div class="rank-bar-wrap">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold"><?= e($row['username']) ?></span>
                            <span class="text-gold fw-bold"><?= number_format((int)$row['cnt']) ?></span>
                        </div>
                        <div class="rank-bar-track">
                            <div class="rank-bar-fill" style="width:<?= $barPct ?>%;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- Top 10: Meiste aktivierte Filme -->
            <div class="section-card">
                <div class="section-head">
                    <i class="bi bi-collection-play-fill me-2"></i>Meiste aktivierte Filme
                </div>
                <?php if (empty($activeFilmLeaders)): ?>
                    <div class="rank-row opacity-50">Noch keine Daten.</div>
                <?php else:
                    $maxA = (int)($activeFilmLeaders[0]['cnt'] ?: 1);
                    foreach ($activeFilmLeaders as $i => $row):
                        $rank = $i + 1;
                        $numClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                        $barPct = $maxA > 0 ? round($row['cnt'] / $maxA * 100) : 0;
                ?>
                <div class="rank-row">
                    <div class="rank-num <?= $numClass ?>"><?= $rank ?></div>
                    <div class="rank-bar-wrap">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold"><?= e($row['username']) ?></span>
                            <span class="text-gold fw-bold"><?= number_format((int)$row['cnt']) ?></span>
                        </div>
                        <div class="rank-bar-track">
                            <div class="rank-bar-fill" style="width:<?= $barPct ?>%;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- Top 10: Meiste Bewertungen insgesamt -->
            <div class="section-card">
                <div class="section-head">
                    <i class="bi bi-lightning-charge-fill me-2"></i>Meiste Bewertungen insgesamt
                </div>
                <?php if (empty($ratingLeaders)): ?>
                    <div class="rank-row opacity-50">Noch keine Daten.</div>
                <?php else:
                    $maxB = (int)($ratingLeaders[0]['cnt'] ?: 1);
                    foreach ($ratingLeaders as $i => $row):
                        $rank = $i + 1;
                        $numClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                        $barPct = $maxB > 0 ? round($row['cnt'] / $maxB * 100) : 0;
                ?>
                <div class="rank-row">
                    <div class="rank-num <?= $numClass ?>"><?= $rank ?></div>
                    <div class="rank-bar-wrap">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold"><?= e($row['username']) ?></span>
                            <span class="text-gold fw-bold"><?= number_format((int)$row['cnt']) ?></span>
                        </div>
                        <div class="rank-bar-track">
                            <div class="rank-bar-fill" style="width:<?= $barPct ?>%;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>

        </div><!-- /col-lg-6 rechts -->
    </div><!-- /row -->

    <!-- ── Navigation ──────────────────────────────────────────────────────── -->
    <div class="text-center mt-4">
        <a href="/index.php" class="btn btn-gold px-4 fw-semibold me-2">
            <i class="bi bi-house-fill me-1"></i>Zur Startseite
        </a>
        <a href="/meine-rangliste.php" class="btn btn-outline-light px-4 fw-semibold">
            <i class="bi bi-list-ol me-1"></i>Meine Rangliste
        </a>
    </div>


<script>
(function () {
    const btns  = document.querySelectorAll('.comm-tab-btn');
    const panes = document.querySelectorAll('.comm-tab-pane');

    btns.forEach(btn => {
        btn.addEventListener('click', function () {
            const target = this.dataset.tab;

            // Buttons
            btns.forEach(b => {
                const active = b.dataset.tab === target;
                b.style.background    = active ? 'rgba(232,184,75,.15)' : 'transparent';
                b.style.borderColor   = active ? 'rgba(232,184,75,.4)'  : 'rgba(255,255,255,.15)';
                b.style.color         = active ? '#e8b84b'              : 'rgba(255,255,255,.45)';
                b.style.fontWeight    = active ? '700'                  : '600';
                b.classList.toggle('active', active);
            });

            // Panes
            panes.forEach(p => {
                p.style.display = p.id === target ? '' : 'none';
            });
        });
    });
})();
</script>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
