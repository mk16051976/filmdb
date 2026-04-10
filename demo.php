<?php
$pageTitle = 'Demo – 100 Filmvergleiche – MKFB';
require_once __DIR__ . '/includes/functions.php';
startSession();

// Guests only – logged-in users have the real app
if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

// Reset demo state
if (isset($_GET['reset'])) {
    unset($_SESSION['demo_state']);
    header('Location: /demo.php');
    exit;
}

define('DEMO_VERSION', 5); // bump to force session re-init when source changes

// ── Build initial session state from DB films ─────────────────────────────────
function initDemoState(array $films): array
{
    $filmData = [];
    foreach ($films as $f) {
        $filmData[(int)$f['id']] = [
            'id'          => (int)$f['id'],
            'title'       => $f['title'],
            'year'        => (int)($f['year'] ?? 0),
            'poster_path' => $f['poster_path'] ?? null,
        ];
    }

    // Random initial ranking
    $ids = array_keys($filmData);
    shuffle($ids);

    // Generate 100 random unique duel pairs from all possible combinations
    $allPairs = [];
    $n = count($ids);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $allPairs[] = [$ids[$i], $ids[$j]];
        }
    }
    shuffle($allPairs);
    $duels = array_slice($allPairs, 0, 100);

    return [
        'films'   => $filmData,
        'ranking' => $ids,
        'duels'   => $duels,
        'step'    => 0,
        'done'    => false,
    ];
}

// ── AJAX vote handler (must come before any output) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote') {
    while (ob_get_level()) ob_end_clean(); // remove any stray output (PHP notices etc.)
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['demo_state'])) {
        echo json_encode(['error' => 'session_missing']);
        exit;
    }

    $state    = &$_SESSION['demo_state'];
    $winnerId = (int)($_POST['winner_id'] ?? 0);
    $loserId  = (int)($_POST['loser_id']  ?? 0);
    $step     = (int)($_POST['step']      ?? -1);

    $movedTo = null;

    if ($winnerId > 0 && $loserId > 0 && $step === $state['step'] && !$state['done']) {
        // Bubble ranking: winner climbs to loser's position if ranked worse
        $posW = array_search($winnerId, $state['ranking']);
        $posL = array_search($loserId,  $state['ranking']);

        if ($posW !== false && $posL !== false && $posW > $posL) {
            // Shift films between posL and posW down by one
            for ($i = $posW; $i > $posL; $i--) {
                $state['ranking'][$i] = $state['ranking'][$i - 1];
            }
            $state['ranking'][$posL] = $winnerId;
            $movedTo = $posL + 1; // 1-based rank
        }

        $state['step']++;
        if ($state['step'] >= 100) {
            $state['done'] = true;
        }
    }

    // Build ranking snapshot for response
    $rankList = [];
    foreach ($state['ranking'] as $pos => $id) {
        $f = $state['films'][$id];
        $rankList[] = [
            'rank'        => $pos + 1,
            'id'          => $id,
            'title'       => $f['title'],
            'year'        => $f['year'],
            'poster_path' => $f['poster_path'],
        ];
    }

    // Next duel
    $nextDuel = null;
    if (!$state['done'] && isset($state['duels'][$state['step']])) {
        [$aId, $bId] = $state['duels'][$state['step']];
        $nextDuel = [
            'a' => $state['films'][$aId],
            'b' => $state['films'][$bId],
        ];
    }

    echo json_encode([
        'step'     => $state['step'],
        'total'    => 100,
        'done'     => $state['done'],
        'winnerId' => $winnerId,
        'movedTo'  => $movedTo,
        'ranking'  => $rankList,
        'nextDuel' => $nextDuel,
    ]);
    exit;
}

// ── First 32 films from DB ────────────────────────────────────────────────────
$DEMO_FILMS = getDB()
    ->query('SELECT id, title, year, poster_path FROM movies ORDER BY id ASC LIMIT 32')
    ->fetchAll();

// ── Initialize session state if not present or version outdated ───────────────
if (!isset($_SESSION['demo_state']) || ($_SESSION['demo_state']['version'] ?? 0) < DEMO_VERSION) {
    $_SESSION['demo_state']            = initDemoState($DEMO_FILMS);
    $_SESSION['demo_state']['version'] = DEMO_VERSION;
}

$state = $_SESSION['demo_state'];
$step  = $state['step'];
$done  = $state['done'];

// Current duel
$currentDuel = null;
if (!$done && isset($state['duels'][$step])) {
    [$aId, $bId] = $state['duels'][$step];
    $currentDuel = [
        'a' => $state['films'][$aId],
        'b' => $state['films'][$bId],
    ];
}

// Build initial ranking array for template
$initialRanking = [];
foreach ($state['ranking'] as $pos => $id) {
    $initialRanking[] = array_merge(['rank' => $pos + 1], $state['films'][$id]);
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="demo-page py-4">
    <div class="container-xl px-3 px-lg-4">

        <?php if ($done): ?>
        <!-- ── Done screen ──────────────────────────────────────────────────── -->
        <div class="row g-4 justify-content-center mb-4">
            <div class="col-lg-6 text-center">
                <div class="demo-cta-card">
                    <div class="text-gold fw-black" style="font-size:3rem; line-height:1;">100</div>
                    <h2 class="fw-bold mb-2 mt-1">Duelle abgeschlossen!</h2>
                    <p class="text-light opacity-75 mb-4">
                        Das ist dein persönliches Ranking nach dem Positions-System.
                        Registriere dich, um dein eigenes Filmarchiv aufzubauen und mit anderen zu vergleichen.
                    </p>
                    <div class="d-flex gap-3 justify-content-center flex-wrap">
                        <a href="/register.php" class="btn btn-gold btn-lg px-4">
                            <i class="bi bi-person-plus-fill me-2"></i>Jetzt registrieren
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-lg-7">
                <h5 class="fw-bold mb-3 text-gold text-center">
                    <i class="bi bi-trophy-fill me-2"></i>Dein persönliches Ranking
                </h5>
                <div class="demo-ranking-wrap demo-final-ranking">
                    <div class="demo-ranking-header">
                        <i class="bi bi-list-ol me-2"></i>Endstand · <?= count($initialRanking) ?> Filme
                    </div>
                    <div class="demo-ranking-list">
                        <?php foreach ($initialRanking as $row): ?>
                        <div class="demo-rank-row">
                            <span class="demo-rank-num <?= $row['rank'] <= 3 ? 'top' : '' ?>"><?= $row['rank'] ?></span>
                            <img src="<?= $row['poster_path'] ? 'https://image.tmdb.org/t/p/w92' . e($row['poster_path']) : 'https://placehold.co/26x39/1e3a5f/e8b84b?text=?' ?>"
                                 alt="<?= e($row['title']) ?>" class="demo-rank-poster"
                                 width="26" height="39"
                                 onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
                            <div class="demo-rank-title">
                                <?= e($row['title']) ?>
                                <span class="opacity-50">(<?= (int)$row['year'] ?>)</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- ── Active duel screen ───────────────────────────────────────────── -->
        <div class="row g-4 align-items-start">

            <!-- Left: Live ranking -->
            <div class="col-lg-4">
                <div class="demo-ranking-wrap">
                    <div class="demo-ranking-header">
                        <i class="bi bi-list-ol me-2"></i>Live-Ranking
                        <span class="text-light fw-normal small ms-1" style="opacity:.45">
                            <?= count($initialRanking) ?> Filme
                        </span>
                    </div>
                    <div class="demo-ranking-list" id="ranking-list">
                        <?php foreach ($initialRanking as $row): ?>
                        <div class="demo-rank-row" data-film-id="<?= (int)$row['id'] ?>">
                            <span class="demo-rank-num <?= $row['rank'] <= 3 ? 'top' : '' ?>"><?= $row['rank'] ?></span>
                            <img src="<?= $row['poster_path'] ? 'https://image.tmdb.org/t/p/w92' . e($row['poster_path']) : 'https://placehold.co/26x39/1e3a5f/e8b84b?text=?' ?>"
                                 alt="<?= e($row['title']) ?>" class="demo-rank-poster"
                                 width="26" height="39"
                                 onerror="this.src='https://placehold.co/26x39/1e3a5f/e8b84b?text=?'">
                            <div class="demo-rank-title"><?= e($row['title']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="text-center mt-2" style="font-size:.75rem;">
                    <a href="/demo.php?reset=1"
                       class="text-decoration-none"
                       style="color:rgba(255,255,255,.35);">
                        <i class="bi bi-arrow-clockwise me-1"></i>Demo neu starten
                    </a>
                </p>
            </div><!-- /col-lg-4 -->

            <!-- Right: Duel arena -->
            <div class="col-lg-8">

                <!-- Progress bar -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between text-light small mb-1" style="opacity:.75">
                        <span id="step-label">
                            <i class="bi bi-play-circle me-1"></i>Duell <?= $step + 1 ?> von 100
                        </span>
                        <span id="progress-text"><?= $step ?> / 100</span>
                    </div>
                    <div class="progress" style="height:8px; border-radius:4px; background:rgba(255,255,255,.1);">
                        <div id="progress-bar" class="progress-bar bg-gold"
                             data-step="<?= $step ?>"
                             style="width:<?= ($step / 100 * 100) ?>%; border-radius:4px; transition:width .4s ease;">
                        </div>
                    </div>
                    <p class="text-center mt-1 mb-0" style="color:rgba(255,255,255,.4); font-size:clamp(1.4rem,2.5vw,2.5rem); white-space:nowrap; overflow:hidden;">
                        Welchen Film schaust du dir lieber an?
                    </p>
                </div>

                <!-- Duel – identical structure to rank.php -->
                <div class="duel-arena" id="duel-arena">
                    <div class="duel-container" style="min-height:auto; max-width:none;">
                        <!-- Film A -->
                        <div class="duel-side" id="movie-a"
                             data-id="<?= (int)$currentDuel['a']['id'] ?>"
                             data-opponent="<?= (int)$currentDuel['b']['id'] ?>">
                            <div class="duel-poster-wrap">
                                <img src="<?= e(posterUrl($currentDuel['a']['poster_path'])) ?>"
                                     alt="<?= e($currentDuel['a']['title']) ?>"
                                     class="duel-poster" fetchpriority="high" decoding="async"
                                     onerror="this.onerror=null;this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                                <div class="duel-overlay">
                                    <i class="bi bi-hand-thumbs-up-fill"></i>
                                    <span>Wählen</span>
                                </div>
                            </div>
                            <div class="duel-info">
                                <h3 class="duel-title"><?= e($currentDuel['a']['title']) ?></h3>
                                <div class="duel-meta">
                                    <span class="badge bg-dark"><?= (int)$currentDuel['a']['year'] ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- VS -->
                        <div class="vs-divider">
                            <div class="vs-circle">VS</div>
                        </div>

                        <!-- Film B -->
                        <div class="duel-side" id="movie-b"
                             data-id="<?= (int)$currentDuel['b']['id'] ?>"
                             data-opponent="<?= (int)$currentDuel['a']['id'] ?>">
                            <div class="duel-poster-wrap">
                                <img src="<?= e(posterUrl($currentDuel['b']['poster_path'])) ?>"
                                     alt="<?= e($currentDuel['b']['title']) ?>"
                                     class="duel-poster" fetchpriority="high" decoding="async"
                                     onerror="this.onerror=null;this.src='https://placehold.co/300x450/1e3a5f/e8b84b?text=?'">
                                <div class="duel-overlay">
                                    <i class="bi bi-hand-thumbs-up-fill"></i>
                                    <span>Wählen</span>
                                </div>
                            </div>
                            <div class="duel-info">
                                <h3 class="duel-title"><?= e($currentDuel['b']['title']) ?></h3>
                                <div class="duel-meta">
                                    <span class="badge bg-dark"><?= (int)$currentDuel['b']['year'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /col-lg-8 -->

        </div><!-- /row -->
        <?php endif; ?>

    </div>
</main>

<?php if (!$done): ?>
<script>
const TMDB_IMG_W500 = 'https://image.tmdb.org/t/p/w500';
const TMDB_IMG_W92  = 'https://image.tmdb.org/t/p/w92';
const PLACEHOLDER   = 'https://placehold.co/26x39/1e3a5f/e8b84b?text=?';

let currentStep = <?= (int)$step ?>;
let voting      = false;

// ── Event delegation for duel cards ──────────────────────────────────────────
document.getElementById('duel-arena').addEventListener('click', function (e) {
    if (voting) return;
    const card = e.target.closest('.duel-side');
    if (!card) return;
    const winnerId = parseInt(card.dataset.id,       10);
    const loserId  = parseInt(card.dataset.opponent, 10);
    if (winnerId && loserId) castVote(winnerId, loserId, card);
});

// ── Keyboard shortcuts ────────────────────────────────────────────────────────
document.addEventListener('keydown', function (e) {
    if (voting) return;
    if (e.key === 'ArrowLeft')  document.getElementById('movie-a').dispatchEvent(new Event('click', {bubbles:true}));
    if (e.key === 'ArrowRight') document.getElementById('movie-b').dispatchEvent(new Event('click', {bubbles:true}));
});

// ── Vote ─────────────────────────────────────────────────────────────────────
function castVote(winnerId, loserId, chosenCard) {
    voting = true;

    // Visual feedback – winner flash, loser dim
    chosenCard.classList.add('winner-flash');
    document.querySelectorAll('.duel-side').forEach(function (c) {
        if (c !== chosenCard) c.classList.add('loser-flash');
    });

    const fd = new FormData();
    fd.append('action',    'vote');
    fd.append('winner_id', winnerId);
    fd.append('loser_id',  loserId);
    fd.append('step',      currentStep);

    fetch('/demo.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(handleResponse)
        .catch(function () {
            voting = false;
            document.querySelectorAll('.duel-side').forEach(c => {
                c.classList.remove('winner-flash', 'loser-flash');
            });
        });
}

function handleResponse(data) {
    if (data.error) { location.reload(); return; }

    currentStep = data.step;

    if (data.done) {
        // Reload – server will render done screen from session
        location.reload();
        return;
    }

    // Update progress
    const pct = Math.round(data.step / data.total * 100);
    const bar  = document.getElementById('progress-bar');
    const txt  = document.getElementById('progress-text');
    const lbl  = document.getElementById('step-label');
    if (bar) { bar.style.width = pct + '%'; bar.dataset.step = data.step; }
    if (txt) txt.textContent = data.step + ' / 100';
    if (lbl) lbl.innerHTML   = '<i class="bi bi-play-circle me-1"></i>Duell ' + (data.step + 1) + ' von 100';

    // Rebuild ranking list
    const list = document.getElementById('ranking-list');
    if (list && data.ranking) {
        const rows = data.ranking.map(function (f) {
            const isTop  = f.rank <= 3 ? ' top' : '';
            const isMvd  = (f.id === data.winnerId && data.movedTo !== null) ? ' rank-moved' : '';
            const imgSrc = f.poster_path ? TMDB_IMG_W92 + f.poster_path : PLACEHOLDER;
            const title  = escHtml(f.title);
            return '<div class="demo-rank-row' + isMvd + '" data-film-id="' + f.id + '">'
                + '<span class="demo-rank-num' + isTop + '">' + f.rank + '</span>'
                + '<img src="' + imgSrc + '" alt="' + title + '" class="demo-rank-poster"'
                + ' onerror="this.src=\'' + PLACEHOLDER + '\'">'
                + '<div class="demo-rank-title">' + title + '</div>'
                + '</div>';
        }).join('');
        list.innerHTML = rows;

        // Scroll winner row into view
        if (data.movedTo !== null) {
            const movedRow = list.querySelector('[data-film-id="' + data.winnerId + '"]');
            if (movedRow) {
                movedRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                setTimeout(function () { movedRow.classList.remove('rank-moved'); }, 1200);
            }
        }
    }

    // Update duel cards with next match
    if (data.nextDuel) {
        setCard('movie-a', data.nextDuel.a, data.nextDuel.b);
        setCard('movie-b', data.nextDuel.b, data.nextDuel.a);
    }

    voting = false;
}

function setCard(cardId, film, opponent) {
    const card = document.getElementById(cardId);
    if (!card) return;
    card.classList.remove('winner-flash', 'loser-flash');
    card.dataset.id       = film.id;
    card.dataset.opponent = opponent.id;

    const img   = card.querySelector('.duel-poster');
    const title = card.querySelector('.duel-title');
    const meta  = card.querySelector('.duel-meta');

    const imgSrc = film.poster_path ? TMDB_IMG_W500 + film.poster_path
                                     : 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?';
    if (img) {
        img.src = imgSrc;
        img.alt = film.title;
        img.onerror = function () { this.onerror = null; this.src = 'https://placehold.co/300x450/1e3a5f/e8b84b?text=?'; };
    }
    if (title) title.textContent = film.title;
    if (meta)  meta.innerHTML    = '<span class="badge bg-dark">' + escHtml(String(film.year)) + '</span>';
}

function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
