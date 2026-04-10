<?php
$pageTitle   = 'Community-Zeiten – MKFB';
$currentPage = 'community-zeiten';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePhase(3);

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── Alle schweren Queries gecacht (10 Min TTL) ────────────────────────────────
$cz = dbCache('community_zeiten', function() {
    $db = getDB();
    $d  = [];

    // Stunden-Verteilung (Community)
    $rows = $db->query("SELECT HOUR(created_at) AS h, COUNT(*) AS cnt FROM comparisons GROUP BY h")->fetchAll();
    $d['hourData'] = array_fill(0, 24, 0);
    foreach ($rows as $r) $d['hourData'][(int)$r['h']] = (int)$r['cnt'];

    // Wochentag-Verteilung (Community)
    $rows = $db->query("SELECT DAYOFWEEK(created_at) AS dow, COUNT(*) AS cnt FROM comparisons GROUP BY dow")->fetchAll();
    $d['dowData'] = array_fill(1, 7, 0);
    foreach ($rows as $r) $d['dowData'][(int)$r['dow']] = (int)$r['cnt'];

    // User-Basisstatistiken — DATEDIFF statt COUNT(DISTINCT DATE()) für Index-Nutzung
    $d['userStats'] = $db->query("
        SELECT u.id, u.username,
               COUNT(c.id)  AS total_duels,
               MIN(DATE(c.created_at)) AS first_date,
               MAX(DATE(c.created_at)) AS last_date,
               GREATEST(1, DATEDIFF(MAX(c.created_at), MIN(c.created_at)) + 1) AS active_days
        FROM users u
        JOIN comparisons c ON c.user_id = u.id
        GROUP BY u.id, u.username
        HAVING total_duels > 0
        ORDER BY u.username ASC
    ")->fetchAll();

    // Peak-Stunde je User
    $rows = $db->query("SELECT user_id, HOUR(created_at) AS h, COUNT(*) AS cnt FROM comparisons GROUP BY user_id, h")->fetchAll();
    $d['peakHourByUser'] = [];
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        if (!isset($d['peakHourByUser'][$uid]) || (int)$r['cnt'] > $d['peakHourByUser'][$uid]['cnt'])
            $d['peakHourByUser'][$uid] = ['h' => (int)$r['h'], 'cnt' => (int)$r['cnt']];
    }

    // Peak-Wochentag je User
    $rows = $db->query("SELECT user_id, DAYOFWEEK(created_at) AS dow, COUNT(*) AS cnt FROM comparisons GROUP BY user_id, dow")->fetchAll();
    $d['peakDowByUser'] = [];
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        if (!isset($d['peakDowByUser'][$uid]) || (int)$r['cnt'] > $d['peakDowByUser'][$uid]['cnt'])
            $d['peakDowByUser'][$uid] = ['dow' => (int)$r['dow'], 'cnt' => (int)$r['cnt']];
    }

    // Zeitfenster je User (MIN/MAX Stunde)
    $rows = $db->query("SELECT user_id, MIN(HOUR(created_at)) AS earliest, MAX(HOUR(created_at)) AS latest FROM comparisons GROUP BY user_id")->fetchAll();
    $d['hRangeByUser'] = [];
    foreach ($rows as $r)
        $d['hRangeByUser'][(int)$r['user_id']] = ['earliest' => (int)$r['earliest'], 'latest' => (int)$r['latest']];

    $d['totalDuels'] = (int)$db->query("SELECT COUNT(*) FROM comparisons")->fetchColumn();
    return $d;
}, 600); // 10 Minuten

$hourData      = $cz['hourData'];
$dowData       = $cz['dowData'];
$userStats     = $cz['userStats'];
$peakHourByUser = $cz['peakHourByUser'];
$peakDowByUser  = $cz['peakDowByUser'];
$hRangeByUser   = $cz['hRangeByUser'];
$totalDuels     = $cz['totalDuels'];

$maxHour     = max($hourData) ?: 1;
$maxDow      = max($dowData)  ?: 1;
$totalUsers  = count($userStats);
$peakHourAll = array_search(max($hourData), $hourData);
$dowLabels   = [1 => 'So', 2 => 'Mo', 3 => 'Di', 4 => 'Mi', 5 => 'Do', 6 => 'Fr', 7 => 'Sa'];
$dowOrder    = [2, 3, 4, 5, 6, 7, 1];

require_once __DIR__ . '/includes/header.php';

// Hilfsfunktion: Stunde als "HH:00" formatieren
function fmtH(int $h): string { return sprintf('%02d:00', $h); }

// Tages-Block-Label für eine Stunde
function blockLabel(int $h): string {
    if ($h < 6)  return 'Nacht';
    if ($h < 12) return 'Morgen';
    if ($h < 18) return 'Tag';
    return 'Abend';
}
function blockColor(int $h): string {
    if ($h < 6)  return '#7b8ee0'; // Nacht – blauviolett
    if ($h < 12) return '#f0c060'; // Morgen – gelb
    if ($h < 18) return '#5bd5c9'; // Tag – cyan
    return '#e8b84b';              // Abend – gold
}
?>
<style>
    body { background: #14325a !important; }

    .stat-card {
        background: rgba(255,255,255,.04);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 14px;
        padding: 1.5rem;
    }
    .stat-card-title {
        font-size: .75rem; font-weight: 700; letter-spacing: .08em;
        color: #e8b84b; text-transform: uppercase; margin-bottom: 1.25rem;
        display: flex; align-items: center; gap: .5rem;
    }
    .summary-badge {
        display: inline-flex; align-items: center; gap: .4rem;
        background: rgba(232,184,75,.12); border: 1px solid rgba(232,184,75,.25);
        color: #e8b84b; border-radius: 20px; padding: 4px 12px;
        font-size: .8rem; font-weight: 600;
    }

    /* ── Stunden-Heatmap ──────────────────────────────────── */
    .hour-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 4px;
    }
    @media (max-width: 576px) {
        .hour-grid { grid-template-columns: repeat(4, 1fr); }
    }
    .hour-cell {
        border-radius: 6px;
        padding: 5px 3px;
        text-align: center;
        font-size: .68rem;
        font-weight: 700;
        transition: opacity .2s;
    }
    .hour-cell .hc-label { color: rgba(255,255,255,.5); font-weight: 400; font-size: .6rem; }
    .hour-cell .hc-cnt   { font-size: .75rem; margin-top: 1px; }

    /* ── Wochentag-Bars ───────────────────────────────────── */
    .dow-bar-row { display: flex; align-items: center; gap: .75rem; margin-bottom: .55rem; }
    .dow-label   { min-width: 28px; font-size: .85rem; font-weight: 700; color: rgba(255,255,255,.7); }
    .dow-track   { flex: 1; background: rgba(255,255,255,.06); border-radius: 4px; height: 12px; overflow: hidden; }
    .dow-fill    { height: 100%; border-radius: 4px; background: #e8b84b; transition: width .5s ease; }
    .dow-count   { min-width: 55px; text-align: right; font-size: .8rem; color: rgba(255,255,255,.5); }

    /* ── User-Tabelle ─────────────────────────────────────── */
    .zeit-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: .82rem;
    }
    .zeit-table th {
        background: rgba(255,255,255,.04);
        color: rgba(255,255,255,.4);
        font-size: .7rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        padding: .5rem .75rem;
        border-bottom: 1px solid rgba(255,255,255,.07);
        white-space: nowrap;
    }
    .zeit-table td {
        padding: .5rem .75rem;
        border-bottom: 1px solid rgba(255,255,255,.04);
        vertical-align: middle;
        color: rgba(255,255,255,.8);
    }
    .zeit-table tr:last-child td { border-bottom: none; }
    .zeit-table tr:hover td { background: rgba(255,255,255,.03); }
    .zeit-table tr.my-row td { background: rgba(232,184,75,.07); }

    .tag-badge {
        display: inline-block;
        border-radius: 4px;
        padding: 1px 7px;
        font-size: .72rem;
        font-weight: 700;
    }
    .time-range-bar {
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .trb-track {
        width: 80px;
        height: 6px;
        background: rgba(255,255,255,.07);
        border-radius: 3px;
        position: relative;
        overflow: hidden;
    }
    .trb-fill {
        position: absolute;
        top: 0; bottom: 0;
        border-radius: 3px;
    }
</style>

<main class="py-5" style="min-height: 80vh;">
    <div class="container">

        <!-- ── Kopfzeile ─────────────────────────────────────────────────── -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h1 class="fw-bold mb-1" style="color:#e8b84b; font-size:1.8rem;">
                    <i class="bi bi-clock-history me-2"></i>Community-Zeiten
                </h1>
                <p class="mb-0" style="color:rgba(255,255,255,.45); font-size:.9rem;">
                    Wann sind die Community-Mitglieder aktiv?
                </p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <span class="summary-badge">
                    <i class="bi bi-people-fill"></i><?= $totalUsers ?> aktive Bewerter
                </span>
                <span class="summary-badge" style="background:rgba(91,157,213,.12); border-color:rgba(91,157,213,.25); color:#5b9bd5;">
                    <i class="bi bi-lightning-charge-fill"></i><?= number_format($totalDuels) ?> Duelle gesamt
                </span>
                <?php if ($maxHour > 0): ?>
                <span class="summary-badge" style="background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.12); color:rgba(255,255,255,.6);">
                    <i class="bi bi-star-fill" style="color:#e8b84b;"></i>Peak: <?= fmtH($peakHourAll) ?> Uhr
                </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">

            <!-- ── Stunden-Heatmap ──────────────────────────────────────── -->
            <div class="col-lg-8">
                <div class="stat-card h-100">
                    <div class="stat-card-title">
                        <i class="bi bi-clock"></i>Aktivität nach Tageszeit
                        <span style="margin-left:auto; color:rgba(255,255,255,.3); font-weight:400; font-size:.68rem; text-transform:none; letter-spacing:0;">
                            Uhrzeit beim Abschicken jeder Bewertung
                        </span>
                    </div>

                    <!-- Legende -->
                    <div class="d-flex gap-3 mb-3 flex-wrap" style="font-size:.7rem; color:rgba(255,255,255,.45);">
                        <?php foreach ([
                            [0,  'Nacht (00–06)',  '#7b8ee0'],
                            [6,  'Morgen (06–12)', '#f0c060'],
                            [12, 'Tag (12–18)',    '#5bd5c9'],
                            [18, 'Abend (18–24)',  '#e8b84b'],
                        ] as [$_, $lbl, $col]): ?>
                        <span><span style="display:inline-block; width:10px; height:10px; border-radius:2px; background:<?= $col ?>; margin-right:4px; vertical-align:middle;"></span><?= $lbl ?></span>
                        <?php endforeach; ?>
                    </div>

                    <div class="hour-grid">
                        <?php foreach (range(0, 23) as $h):
                            $cnt  = $hourData[$h];
                            $col  = blockColor($h);
                            $pct  = round($cnt / $maxHour * 100);
                            $alpha = max(0.08, $pct / 100 * 0.85);
                        ?>
                        <div class="hour-cell" style="background:<?= $col ?>; opacity:<?= sprintf('%.2f', $alpha + 0.15) ?>;"
                             title="<?= fmtH($h) ?> Uhr: <?= number_format($cnt) ?> Bewertungen">
                            <div class="hc-label"><?= fmtH($h) ?></div>
                            <div class="hc-cnt" style="color:rgba(255,255,255,.9);"><?= $cnt > 0 ? number_format($cnt) : '–' ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Intensitätsskala -->
                    <div class="d-flex align-items-center gap-2 mt-3" style="font-size:.65rem; color:rgba(255,255,255,.3);">
                        <span>wenig</span>
                        <div style="flex:1; height:5px; border-radius:3px; background:linear-gradient(to right, rgba(232,184,75,.08), rgba(232,184,75,.9));"></div>
                        <span>viel</span>
                    </div>
                </div>
            </div>

            <!-- ── Wochentag-Verteilung ─────────────────────────────────── -->
            <div class="col-lg-4">
                <div class="stat-card h-100">
                    <div class="stat-card-title">
                        <i class="bi bi-calendar-week"></i>Aktivität nach Wochentag
                    </div>
                    <?php
                    $dowColors = [2=>'#5b9bd5',3=>'#5b9bd5',4=>'#5b9bd5',5=>'#5b9bd5',6=>'#5b9bd5',7=>'#7ec87e',1=>'#e07b7b'];
                    foreach ($dowOrder as $dow):
                        $cnt = $dowData[$dow];
                        $pct = $maxDow > 0 ? round($cnt / $maxDow * 100) : 0;
                        $col = $dowColors[$dow];
                    ?>
                    <div class="dow-bar-row">
                        <div class="dow-label"><?= $dowLabels[$dow] ?></div>
                        <div class="dow-track">
                            <div class="dow-fill" style="width:<?= $pct ?>%; background:<?= $col ?>;"></div>
                        </div>
                        <div class="dow-count"><?= number_format($cnt) ?></div>
                    </div>
                    <?php endforeach; ?>

                    <?php
                    // Aktivster Wochentag
                    $peakDow = array_search(max($dowData), $dowData);
                    if ($peakDow):
                    ?>
                    <p class="mt-3 mb-0" style="color:rgba(255,255,255,.25); font-size:.75rem;">
                        Aktivster Tag: <strong style="color:rgba(255,255,255,.55);"><?= $dowLabels[$peakDow] ?></strong>
                    </p>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── User-Zeiten-Tabelle ───────────────────────────────────────── -->
        <?php if (!empty($userStats)): ?>
        <div class="row g-4 mt-1">
            <div class="col-12">
                <div class="stat-card">
                    <div class="stat-card-title">
                        <i class="bi bi-table"></i>Bewertungszeiten je Bewerter
                        <span style="margin-left:auto; color:rgba(255,255,255,.3); font-weight:400; font-size:.68rem; text-transform:none; letter-spacing:0;">
                            Aktivste Stunde = Stunde mit den meisten Duellen
                        </span>
                    </div>

                    <div style="overflow-x:auto; scrollbar-width:thin; scrollbar-color:rgba(232,184,75,.2) transparent;">
                    <table class="zeit-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Bewerter</th>
                                <th style="text-align:right;">Duelle</th>
                                <th style="text-align:right;">Aktive<br>Tage</th>
                                <th>Erste<br>Bewertung</th>
                                <th>Letzte<br>Bewertung</th>
                                <th>Aktivste<br>Stunde</th>
                                <th>Aktivster<br>Tag</th>
                                <th>Zeitfenster<br>Früh – Spät</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($userStats as $i => $u):
                            $uid      = (int)$u['id'];
                            $isMe     = ($uid === $userId);
                            $ph       = $peakHourByUser[$uid] ?? null;
                            $pd       = $peakDowByUser[$uid]  ?? null;
                            $hr       = $hRangeByUser[$uid]   ?? null;

                            // Zeitfenster-Bar (0-23 → Prozent)
                            $barLeft  = $hr ? round($hr['earliest'] / 23 * 100) : 0;
                            $barWidth = $hr ? max(4, round(($hr['latest'] - $hr['earliest']) / 23 * 100)) : 0;

                            // Peak-Stunde Farbe
                            $phCol = $ph ? blockColor($ph['h']) : '#999';
                        ?>
                        <tr class="<?= $isMe ? 'my-row' : '' ?>">
                            <td style="color:rgba(255,255,255,.3); font-size:.75rem;"><?= $i + 1 ?></td>
                            <td>
                                <span style="font-weight:<?= $isMe ? '700' : '400' ?>; color:<?= $isMe ? '#e8b84b' : 'rgba(255,255,255,.85)' ?>;">
                                    <?= e($u['username']) ?>
                                    <?php if ($isMe): ?><span style="font-size:.65rem; opacity:.6;"> ← ich</span><?php endif; ?>
                                </span>
                            </td>
                            <td style="text-align:right; font-weight:600;"><?= number_format((int)$u['total_duels']) ?></td>
                            <td style="text-align:right; color:rgba(255,255,255,.5);"><?= (int)$u['active_days'] ?></td>
                            <td style="color:rgba(255,255,255,.5); font-size:.78rem; white-space:nowrap;">
                                <?= $u['first_date'] ? date('d.m.Y', strtotime($u['first_date'])) : '–' ?>
                            </td>
                            <td style="color:rgba(255,255,255,.5); font-size:.78rem; white-space:nowrap;">
                                <?= $u['last_date'] ? date('d.m.Y', strtotime($u['last_date'])) : '–' ?>
                            </td>
                            <td>
                                <?php if ($ph): ?>
                                <span class="tag-badge" style="background:<?= $phCol ?>22; color:<?= $phCol ?>; border:1px solid <?= $phCol ?>44;">
                                    <?= fmtH($ph['h']) ?>
                                </span>
                                <?php else: ?>
                                <span style="color:rgba(255,255,255,.2);">–</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($pd): ?>
                                <span class="tag-badge" style="background:rgba(232,184,75,.1); color:rgba(255,255,255,.65); border:1px solid rgba(255,255,255,.1);">
                                    <?= $dowLabels[$pd['dow']] ?>
                                </span>
                                <?php else: ?>
                                <span style="color:rgba(255,255,255,.2);">–</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hr): ?>
                                <div class="time-range-bar">
                                    <span style="font-size:.7rem; color:rgba(255,255,255,.4); min-width:28px;"><?= fmtH($hr['earliest']) ?></span>
                                    <div class="trb-track">
                                        <div class="trb-fill" style="left:<?= $barLeft ?>%; width:<?= $barWidth ?>%; background:#e8b84b; opacity:.6;"></div>
                                    </div>
                                    <span style="font-size:.7rem; color:rgba(255,255,255,.4); min-width:28px;"><?= fmtH($hr['latest']) ?></span>
                                </div>
                                <?php else: ?>
                                <span style="color:rgba(255,255,255,.2);">–</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
