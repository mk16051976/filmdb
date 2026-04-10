<?php
$pageTitle = 'Nachrichten – MKFB';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// ── DB Schema ──────────────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS pm_conversations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_a          INT UNSIGNED NOT NULL,
    user_b          INT UNSIGNED NOT NULL,
    last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pair (user_a, user_b),
    INDEX idx_user_a (user_a),
    INDEX idx_user_b (user_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS pm_messages (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conv_id    INT UNSIGNED NOT NULL,
    sender_id  INT UNSIGNED NOT NULL,
    body       TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at    TIMESTAMP NULL,
    INDEX idx_conv (conv_id, created_at),
    INDEX idx_unread (conv_id, sender_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Helpers ────────────────────────────────────────────────────────────────────
function sanitizePmHtml(string $html): string {
    if (trim(strip_tags($html)) === '') return '';

    // Erlaubte Tags und ihre erlaubten Attribute (Whitelist)
    $allowed = [
        'p'          => [],
        'br'         => [],
        'strong'     => [],
        'b'          => [],
        'em'         => [],
        'i'          => [],
        'u'          => [],
        's'          => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'blockquote' => [],
        'pre'        => [],
        'code'       => [],
        'h1'         => [], 'h2' => [], 'h3' => [],
        'span'       => ['style'],
        'a'          => ['href', 'target', 'rel'],
        'img'        => ['src', 'alt', 'width', 'height'],
    ];

    // Alle Tags entfernen und nur Whitelist-Tags wieder einsetzen
    $out = preg_replace_callback('/<\/?([a-z][a-z0-9]*)\b([^>]*)>/i', function($m) use ($allowed) {
        $tag   = strtolower($m[1]);
        $isClose = $m[0][1] === '/';
        if (!isset($allowed[$tag])) return '';
        if ($isClose) return "</$tag>";

        $permittedAttrs = $allowed[$tag];
        if (empty($permittedAttrs)) return "<$tag>";

        // Parse erlaubte Attribute
        $attrStr = '';
        foreach ($permittedAttrs as $attr) {
            if (preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*"([^"]*)"/i', $m[2], $av)) {
                $val = $av[1];
                // href/src: nur http/https und eigene uploads erlaubt
                if ($attr === 'href') {
                    if (!preg_match('/^https?:\/\//i', $val) && !preg_match('/^\//i', $val)) continue;
                }
                if ($attr === 'src') {
                    if (!preg_match('/^\/uploads\/pm\/[a-f0-9]+\.(jpg|jpeg|png|gif|webp)$/i', $val)) continue;
                }
                // style: nur sichere CSS-Properties
                if ($attr === 'style') {
                    $val = preg_replace('/expression\s*\(|javascript:|vbscript:|url\s*\(/i', '', $val);
                }
                $attrStr .= ' ' . $attr . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
            }
        }
        // target="_blank" immer mit rel="noopener"
        if ($tag === 'a' && strpos($attrStr, 'target=') !== false) {
            $attrStr .= ' rel="noopener noreferrer"';
        }
        return "<$tag$attrStr>";
    }, $html);

    return $out;
}

function getOrCreateConv(PDO $db, int $a, int $b): int {
    $lo = min($a, $b); $hi = max($a, $b);
    $s = $db->prepare("SELECT id FROM pm_conversations WHERE user_a=? AND user_b=?");
    $s->execute([$lo, $hi]);
    if ($id = $s->fetchColumn()) return (int)$id;
    $db->prepare("INSERT INTO pm_conversations (user_a, user_b) VALUES (?,?)")->execute([$lo, $hi]);
    return (int)$db->lastInsertId();
}

// ── AJAX: user search ──────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'users') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 1) { echo '[]'; exit; }
    $s = $db->prepare("SELECT id, username FROM users WHERE username LIKE ? AND id != ? AND COALESCE(blocked,0)=0 LIMIT 10");
    $s->execute(['%'.$q.'%', $userId]);
    echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── AJAX: messages JSON ────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $convId = (int)($_GET['conv'] ?? 0);
    $since  = (int)($_GET['since'] ?? 0);
    $c = $db->prepare("SELECT id FROM pm_conversations WHERE id=? AND (user_a=? OR user_b=?)");
    $c->execute([$convId, $userId, $userId]);
    if (!$c->fetch()) { echo '{"error":"forbidden"}'; exit; }
    $db->prepare("UPDATE pm_messages SET read_at=NOW() WHERE conv_id=? AND sender_id!=? AND read_at IS NULL")
       ->execute([$convId, $userId]);
    $s = $db->prepare("SELECT m.id, m.sender_id, m.body, m.created_at, u.username
                       FROM pm_messages m JOIN users u ON u.id=m.sender_id
                       WHERE m.conv_id=? AND m.id > ? ORDER BY m.created_at ASC");
    $s->execute([$convId, $since]);
    echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── AJAX: unread count ─────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'unread') {
    header('Content-Type: application/json; charset=utf-8');
    $s = $db->prepare("SELECT COUNT(*) FROM pm_messages m
                       JOIN pm_conversations c ON c.id=m.conv_id
                       WHERE (c.user_a=? OR c.user_b=?) AND m.sender_id!=? AND m.read_at IS NULL");
    $s->execute([$userId, $userId, $userId]);
    echo json_encode(['count' => (int)$s->fetchColumn()]);
    exit;
}

// ── POST: send ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send' && csrfValid()) {
    $convId = (int)($_POST['conv_id'] ?? 0);
    $body   = sanitizePmHtml(trim($_POST['body'] ?? ''));
    if ($convId && $body !== '') {
        $c = $db->prepare("SELECT id FROM pm_conversations WHERE id=? AND (user_a=? OR user_b=?)");
        $c->execute([$convId, $userId, $userId]);
        if ($c->fetch()) {
            $db->prepare("INSERT INTO pm_messages (conv_id, sender_id, body) VALUES (?,?,?)")
               ->execute([$convId, $userId, $body]);
            $db->prepare("UPDATE pm_conversations SET last_message_at=NOW() WHERE id=?")
               ->execute([$convId]);
        }
    }
    header('Location: /nachrichten.php?conv='.$convId);
    exit;
}

// ── GET: open/create conversation with a user ──────────────────────────────────
if (isset($_GET['with'])) {
    $partnerId = (int)$_GET['with'];
    if ($partnerId && $partnerId !== $userId) {
        $pu = $db->prepare("SELECT id FROM users WHERE id=? AND COALESCE(blocked,0)=0");
        $pu->execute([$partnerId]);
        if ($pu->fetch()) {
            $cid = getOrCreateConv($db, $userId, $partnerId);
            header("Location: /nachrichten.php?conv=$cid");
            exit;
        }
    }
    header('Location: /nachrichten.php');
    exit;
}

// ── Load page data ─────────────────────────────────────────────────────────────
$openConvId = (int)($_GET['conv'] ?? 0);
$openConv   = null;

if ($openConvId) {
    $c = $db->prepare("
        SELECT c.id, c.last_message_at, c.user_a, c.user_b,
               u.id AS partner_id, u.username AS partner_name
        FROM pm_conversations c
        JOIN users u ON u.id = IF(c.user_a=?,c.user_b,c.user_a)
        WHERE c.id=? AND (c.user_a=? OR c.user_b=?)
    ");
    $c->execute([$userId, $openConvId, $userId, $userId]);
    $openConv = $c->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($openConv) {
        // Mark as read
        $db->prepare("UPDATE pm_messages SET read_at=NOW() WHERE conv_id=? AND sender_id!=? AND read_at IS NULL")
           ->execute([$openConvId, $userId]);
        // Load messages
        $msgStmt = $db->prepare("
            SELECT m.id, m.sender_id, m.body, m.created_at, u.username
            FROM pm_messages m JOIN users u ON u.id=m.sender_id
            WHERE m.conv_id=? ORDER BY m.created_at ASC
        ");
        $msgStmt->execute([$openConvId]);
        $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Load conversation list
$convStmt = $db->prepare("
    SELECT c.id, c.last_message_at,
           u.id AS partner_id, u.username AS partner_name,
           lm.body AS last_body,
           COALESCE(ur.unread, 0) AS unread
    FROM pm_conversations c
    JOIN users u ON u.id = IF(c.user_a=?,c.user_b,c.user_a)
    LEFT JOIN (
        SELECT m1.conv_id, m1.body
        FROM pm_messages m1
        JOIN (SELECT conv_id, MAX(id) AS max_id FROM pm_messages GROUP BY conv_id) m2
          ON m1.conv_id = m2.conv_id AND m1.id = m2.max_id
    ) lm ON lm.conv_id = c.id
    LEFT JOIN (
        SELECT conv_id, COUNT(*) AS unread
        FROM pm_messages
        WHERE sender_id != ? AND read_at IS NULL
        GROUP BY conv_id
    ) ur ON ur.conv_id = c.id
    WHERE c.user_a=? OR c.user_b=?
    ORDER BY c.last_message_at DESC
");
$convStmt->execute([$userId, $userId, $userId, $userId]);
$conversations = $convStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Alle User für Modal ────────────────────────────────────────────────────────
$allUsersStmt = $db->prepare("SELECT id, username FROM users WHERE id != ? AND COALESCE(blocked,0)=0 ORDER BY username ASC");
$allUsersStmt->execute([$userId]);
$allUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.quilljs.com/1.3.7/quill.snow.css">
<style>
body { background:#0f2744 !important; }

.pm-layout-wrap {
    display: flex;
    justify-content: center;
    padding: 1.25rem 1rem 0;
    height: calc(100vh - 62px - 1rem);
    box-sizing: border-box;
}
.pm-layout {
    display: grid;
    grid-template-columns: 260px 1fr;
    width: 100%;
    max-width: 1000px;
    height: 100%;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 14px;
}
@media(max-width:767px){
    .pm-layout-wrap { padding: 0; }
    .pm-layout { grid-template-columns: 1fr; border-radius: 0; border: none; }
    .pm-sidebar { display: <?= $openConvId ? 'none' : 'flex' ?>; }
    .pm-main    { display: <?= $openConvId ? 'flex' : 'none' ?>; }
}

/* Sidebar */
.pm-sidebar {
    background: #14325a;
    border-right: 1px solid rgba(255,255,255,.08);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.pm-sidebar-head {
    padding: .85rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,.08);
    flex-shrink: 0;
}
.pm-new-btn {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: rgba(232,184,75,.15);
    border: 1px solid rgba(232,184,75,.3);
    color: #e8b84b;
    border-radius: 8px;
    padding: .45rem .85rem;
    font-size: .85rem;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
    transition: background .15s;
}
.pm-new-btn:hover { background: rgba(232,184,75,.25); }
.pm-search {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    color: #e0e0e0;
    border-radius: 8px;
    padding: .4rem .75rem;
    font-size: .82rem;
    width: 100%;
    margin-top: .6rem;
}
.pm-search::placeholder { color: rgba(255,255,255,.3); }
.pm-search:focus { outline: none; border-color: rgba(232,184,75,.4); }
.pm-conv-list { flex: 1; overflow-y: auto; }
.pm-conv-item {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .75rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid rgba(255,255,255,.04);
    transition: background .12s;
    text-decoration: none;
}
.pm-conv-item:hover { background: rgba(255,255,255,.05); }
.pm-conv-item.active { background: rgba(232,184,75,.1); border-left: 3px solid #e8b84b; }
.pm-conv-avatar {
    width: 40px; height: 40px;
    background: linear-gradient(135deg,#1e4a8a,#2a5fa0);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem; font-weight: 700; color: #e8b84b;
    flex-shrink: 0;
}
.pm-conv-info { flex: 1; min-width: 0; }
.pm-conv-name { font-size: .85rem; font-weight: 600; color: #e0e0e0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-conv-preview { font-size: .73rem; color: rgba(255,255,255,.35); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.pm-conv-meta { text-align: right; flex-shrink: 0; }
.pm-conv-time { font-size: .68rem; color: rgba(255,255,255,.3); white-space: nowrap; }
.pm-unread-badge {
    background: #e8b84b; color: #0a192f;
    border-radius: 20px; font-size: .65rem; font-weight: 800;
    padding: 1px 6px; margin-top: 3px; display: inline-block;
}

/* Main */
.pm-main {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: #0f2744;
}
.pm-main-head {
    padding: .85rem 1.25rem;
    background: #14325a;
    border-bottom: 1px solid rgba(255,255,255,.08);
    display: flex; align-items: center; gap: .75rem;
    flex-shrink: 0;
}
.pm-main-head-name { font-size: 1rem; font-weight: 700; color: #e0e0e0; }
.pm-messages {
    flex: 1;
    overflow-y: auto;
    padding: .75rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: .75rem;
    min-height: 0;
}
.pm-msg {
    display: flex;
    gap: .6rem;
    max-width: 85%;
}
.pm-msg.mine { align-self: flex-end; flex-direction: row-reverse; }
.pm-msg-avatar {
    width: 32px; height: 32px;
    background: linear-gradient(135deg,#1e4a8a,#2a5fa0);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; font-weight: 700; color: #e8b84b;
    flex-shrink: 0; align-self: flex-end;
}
.pm-msg-bubble {
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px 14px 14px 4px;
    padding: .65rem .9rem;
    font-size: .88rem;
    color: #e0e0e0;
    line-height: 1.5;
    word-break: break-word;
}
.pm-msg.mine .pm-msg-bubble {
    background: rgba(232,184,75,.18);
    border-color: rgba(232,184,75,.3);
    border-radius: 14px 14px 4px 14px;
    color: #f0e0a0;
}
.pm-msg-time { font-size: .65rem; color: rgba(255,255,255,.25); margin-top: 3px; text-align: right; }
.pm-msg.mine .pm-msg-time { text-align: left; }
.pm-msg-bubble img { max-width: 100%; max-height: 300px; border-radius: 8px; display: block; margin-top: .4rem; }
.pm-msg-bubble a { color: #7cb4f4; }
.pm-msg-bubble p { margin: 0 0 .3rem; }
.pm-msg-bubble p:last-child { margin-bottom: 0; }
.pm-msg-bubble ul, .pm-msg-bubble ol { margin: .2rem 0 .2rem 1.2rem; padding: 0; }
.pm-msg-bubble blockquote { border-left: 3px solid rgba(255,255,255,.2); margin: .3rem 0; padding-left: .7rem; color: rgba(255,255,255,.5); }

/* Date separator */
.pm-date-sep {
    text-align: center; font-size: .7rem; color: rgba(255,255,255,.25);
    margin: .5rem 0;
    display: flex; align-items: center; gap: .5rem;
}
.pm-date-sep::before, .pm-date-sep::after {
    content: ''; flex: 1; height: 1px; background: rgba(255,255,255,.08);
}

/* Composer */
.pm-composer {
    border-top: 1px solid rgba(255,255,255,.08);
    background: #14325a;
    padding: .6rem 1rem;
    flex-shrink: 0;
}
.pm-composer .ql-toolbar.ql-snow {
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.1) !important;
    border-bottom: none !important;
    border-radius: 10px 10px 0 0;
}
.pm-composer .ql-container.ql-snow {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.1) !important;
    border-radius: 0 0 10px 10px;
    color: #e0e0e0;
    font-size: .9rem;
    min-height: 60px;
    max-height: 160px;
    overflow-y: auto;
}
.pm-composer .ql-editor.ql-blank::before { color: rgba(255,255,255,.25); font-style: normal; }
.pm-composer .ql-snow .ql-stroke { stroke: rgba(255,255,255,.5); }
.pm-composer .ql-snow .ql-fill { fill: rgba(255,255,255,.5); }
.pm-composer .ql-snow .ql-picker { color: rgba(255,255,255,.5); }
.pm-composer .ql-snow .ql-picker-options { background: #1e3d7a; border-color: rgba(255,255,255,.1); }
.pm-composer .ql-snow.ql-toolbar button:hover .ql-stroke,
.pm-composer .ql-snow.ql-toolbar button.ql-active .ql-stroke { stroke: #e8b84b; }
.pm-composer .ql-snow.ql-toolbar button:hover .ql-fill,
.pm-composer .ql-snow.ql-toolbar button.ql-active .ql-fill { fill: #e8b84b; }
.pm-composer-row {
    display: flex;
    align-items: flex-end;
    gap: .5rem;
}
.pm-composer-row .ql-wrapper { flex: 1; min-width: 0; }
.pm-send-btn {
    background: #e8b84b; color: #0a192f;
    border: none; border-radius: 8px;
    padding: .55rem .9rem; font-size: 1.1rem;
    cursor: pointer; display: flex; align-items: center;
    transition: background .15s; flex-shrink: 0;
    align-self: flex-end;
}
.pm-send-btn:hover { background: #f0c860; }
.pm-send-btn:disabled { opacity: .5; cursor: default; }

/* Empty state */
.pm-empty {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    color: rgba(255,255,255,.25); gap: .75rem; text-align: center; padding: 2rem;
}
.pm-empty i { font-size: 3.5rem; }

/* New conversation modal */
.pm-new-modal {
    display: none; position: fixed; inset: 0; z-index: 9000;
    background: rgba(0,0,0,.6); align-items: center; justify-content: center;
}
.pm-new-modal.open { display: flex; }
.pm-new-modal-box {
    background: #14325a;
    border: 1px solid rgba(232,184,75,.25);
    border-radius: 14px;
    padding: 1.75rem;
    width: 100%; max-width: 420px;
    box-shadow: 0 20px 60px rgba(0,0,0,.5);
}
.pm-modal-user-item {
    display: flex; align-items: center; gap: .7rem;
    padding: .5rem .6rem; border-radius: 8px;
    text-decoration: none; cursor: pointer;
    transition: background .12s;
}
.pm-modal-user-item:hover { background: rgba(232,184,75,.12); }
.pm-modal-user-item.hidden { display: none; }

::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(232,184,75,.2); border-radius: 3px; }
* { scrollbar-width: thin; scrollbar-color: rgba(232,184,75,.2) transparent; }
</style>

<main style="padding-top:62px; padding-bottom:1rem; background:#0f2744;">
<div class="pm-layout-wrap">
<div class="pm-layout">

    <!-- ── Sidebar ────────────────────────────────────────────────────── -->
    <aside class="pm-sidebar">
        <div class="pm-sidebar-head">
            <button class="pm-new-btn" onclick="openNewModal()">
                <i class="bi bi-pencil-square"></i> Neue Nachricht
            </button>
            <input type="text" class="pm-search" placeholder="Konversationen suchen…" id="conv-search" oninput="filterConvs(this.value)">
        </div>
        <div class="pm-conv-list" id="conv-list">
            <?php if (empty($conversations)): ?>
            <div style="padding:1.5rem 1rem; text-align:center; color:rgba(255,255,255,.25); font-size:.82rem;">
                Noch keine Nachrichten.<br>Starte eine Unterhaltung!
            </div>
            <?php else: ?>
            <?php foreach ($conversations as $cv): ?>
            <a href="/nachrichten.php?conv=<?= $cv['id'] ?>"
               class="pm-conv-item <?= (int)$cv['id'] === $openConvId ? 'active' : '' ?>"
               data-name="<?= strtolower(e($cv['partner_name'])) ?>">
                <div class="pm-conv-avatar"><?= strtoupper(mb_substr($cv['partner_name'], 0, 1)) ?></div>
                <div class="pm-conv-info">
                    <div class="pm-conv-name"><?= e($cv['partner_name']) ?></div>
                    <div class="pm-conv-preview"><?= e(mb_substr(strip_tags($cv['last_body'] ?? ''), 0, 55)) ?></div>
                </div>
                <div class="pm-conv-meta">
                    <div class="pm-conv-time"><?= date('d.m.', strtotime($cv['last_message_at'])) ?></div>
                    <?php if ((int)$cv['unread'] > 0): ?>
                    <div class="pm-unread-badge"><?= (int)$cv['unread'] ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <!-- ── Main ──────────────────────────────────────────────────────── -->
    <div class="pm-main">
        <?php if ($openConv): ?>

        <!-- Header -->
        <div class="pm-main-head">
            <a href="/nachrichten.php" class="d-md-none" style="color:rgba(255,255,255,.4); margin-right:.25rem;">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div class="pm-conv-avatar" style="width:36px;height:36px;font-size:.8rem;">
                <?= strtoupper(mb_substr($openConv['partner_name'], 0, 1)) ?>
            </div>
            <div>
                <div class="pm-main-head-name"><?= e($openConv['partner_name']) ?></div>
            </div>
            <a href="/profil.php?user=<?= (int)$openConv['partner_id'] ?>"
               style="margin-left:auto; color:rgba(255,255,255,.3); font-size:.78rem; text-decoration:none;">
                <i class="bi bi-person-circle me-1"></i>Profil
            </a>
        </div>

        <!-- Messages -->
        <div class="pm-messages" id="pm-messages">
            <?php
            $lastDate = '';
            foreach ($messages as $msg):
                $msgDate = date('d.m.Y', strtotime($msg['created_at']));
                $isMine  = (int)$msg['sender_id'] === $userId;
                if ($msgDate !== $lastDate):
                    $lastDate = $msgDate;
            ?>
            <div class="pm-date-sep"><?= $msgDate === date('d.m.Y') ? 'Heute' : $msgDate ?></div>
            <?php endif; ?>
            <div class="pm-msg <?= $isMine ? 'mine' : '' ?>" data-id="<?= (int)$msg['id'] ?>">
                <?php if (!$isMine): ?>
                <div class="pm-msg-avatar"><?= strtoupper(mb_substr($msg['username'], 0, 1)) ?></div>
                <?php endif; ?>
                <div>
                    <div class="pm-msg-bubble"><?= $msg['body'] ?></div>
                    <div class="pm-msg-time"><?= date('H:i', strtotime($msg['created_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Composer -->
        <div class="pm-composer">
            <form id="send-form" method="post" onsubmit="return sendMessage(event)">
                <input type="hidden" name="action"     value="send">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="conv_id"    value="<?= $openConvId ?>">
                <input type="hidden" name="body"       id="body-input">
                <div class="pm-composer-row">
                    <div class="ql-wrapper">
                        <div id="pm-editor"><p><br></p></div>
                    </div>
                    <button type="submit" class="pm-send-btn" id="send-btn" title="Senden (Strg+Enter)">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
            </form>
        </div>

        <?php else: ?>
        <div class="pm-empty">
            <i class="bi bi-chat-heart"></i>
            <div style="font-size:1rem; font-weight:600; color:rgba(255,255,255,.4);">Kein Gespräch geöffnet</div>
            <div style="font-size:.82rem;">Wähle eine Unterhaltung oder starte eine neue.</div>
            <button class="pm-new-btn" style="max-width:220px; justify-content:center;" onclick="openNewModal()">
                <i class="bi bi-pencil-square"></i> Neue Nachricht
            </button>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.pm-layout -->
</div><!-- /.pm-layout-wrap -->
</main>

<!-- ── Neue Nachricht Modal ────────────────────────────────────────────── -->
<div class="pm-new-modal" id="new-modal" onclick="if(event.target===this)closeNewModal()">
    <div class="pm-new-modal-box">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 fw-bold" style="color:#e8b84b;"><i class="bi bi-pencil-square me-2"></i>Neue Nachricht</h6>
            <button onclick="closeNewModal()" style="background:none;border:none;color:rgba(255,255,255,.4);font-size:1.2rem;cursor:pointer;"><i class="bi bi-x-lg"></i></button>
        </div>
        <input type="text" id="modal-user-search" autocomplete="off"
               style="background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); color:#e0e0e0;
                      border-radius:8px; padding:.45rem .8rem; font-size:.85rem; width:100%; margin-bottom:.65rem; outline:none;"
               placeholder="Suchen…" oninput="filterUsers(this.value)">
        <div id="modal-user-list" style="max-height:320px; overflow-y:auto; margin:0 -.25rem;">
            <?php foreach ($allUsers as $u): ?>
            <a href="/nachrichten.php?with=<?= (int)$u['id'] ?>"
               class="pm-modal-user-item"
               data-name="<?= strtolower(e($u['username'])) ?>">
                <div class="pm-conv-avatar" style="width:34px;height:34px;font-size:.8rem;flex-shrink:0;">
                    <?= strtoupper(mb_substr($u['username'], 0, 1)) ?>
                </div>
                <span style="font-size:.9rem; color:#e0e0e0;"><?= e($u['username']) ?></span>
            </a>
            <?php endforeach; ?>
            <?php if (empty($allUsers)): ?>
            <div style="text-align:center; color:rgba(255,255,255,.3); font-size:.82rem; padding:1rem;">Keine Benutzer vorhanden</div>
            <?php endif; ?>
        </div>
        <div class="mt-3 text-end">
            <button onclick="closeNewModal()" style="background:none;border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.4);border-radius:8px;padding:.35rem .9rem;font-size:.82rem;cursor:pointer;">Abbrechen</button>
        </div>
    </div>
</div>

<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
<?php if ($openConv): ?>
// ── Quill Editor ───────────────────────────────────────────────────────────────
const quill = new Quill('#pm-editor', {
    theme: 'snow',
    placeholder: 'Schreibe eine Nachricht…',
    modules: {
        history: { delay: 1000, maxStack: 100, userOnly: true },
        toolbar: {
            container: [
                ['bold', 'italic', 'underline', 'strike'],
                [{ size: ['small', false, 'large'] }],
                [{ color: [] }],
                [{ list: 'ordered' }, { list: 'bullet' }],
                [{ align: [] }],
                ['link', 'image', 'blockquote'],
                ['clean']
            ],
            handlers: { image: imageUploadHandler }
        }
    }
});

function imageUploadHandler() {
    const input = document.createElement('input');
    input.setAttribute('type', 'file');
    input.setAttribute('accept', 'image/jpeg,image/png,image/gif,image/webp');
    input.click();
    input.onchange = async () => {
        const file = input.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) { alert('Bild zu groß (max 5 MB)'); return; }
        const fd = new FormData();
        fd.append('image', file);
        fd.append('csrf_token', '<?= csrfToken() ?>');
        try {
            const res  = await fetch('/pm-upload.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.url) {
                const range = quill.getSelection(true);
                quill.insertEmbed(range ? range.index : 0, 'image', data.url);
            }
        } catch(e) { alert('Upload fehlgeschlagen'); }
    };
}

function sendMessage(e) {
    e.preventDefault();
    const html = quill.root.innerHTML;
    if (quill.getText().trim() === '') return false;
    document.getElementById('body-input').value = html;
    document.getElementById('send-btn').disabled = true;
    e.target.submit();
    return false;
}

// Ctrl+Enter to send
quill.keyboard.addBinding({ key: 13, ctrlKey: true }, () => {
    document.getElementById('send-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
});

// Auto-scroll to bottom
(function() {
    const msgs = document.getElementById('pm-messages');
    if (msgs) msgs.scrollTop = msgs.scrollHeight;
})();

// ── Poll for new messages ──────────────────────────────────────────────────────
let lastMsgId = <?= !empty($messages) ? (int)end($messages)['id'] : 0 ?>;
const convId  = <?= $openConvId ?>;
const myId    = <?= $userId ?>;

function formatTime(ts) {
    const d = new Date(ts.replace(' ', 'T'));
    return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
}

async function pollMessages() {
    try {
        const res  = await fetch(`/nachrichten.php?action=json&conv=${convId}&since=${lastMsgId}`);
        const msgs = await res.json();
        if (!Array.isArray(msgs) || !msgs.length) return;
        const container = document.getElementById('pm-messages');
        msgs.forEach(m => {
            const isMine = parseInt(m.sender_id) === myId;
            const div    = document.createElement('div');
            div.className = 'pm-msg' + (isMine ? ' mine' : '');
            div.dataset.id = m.id;
            div.innerHTML = (isMine ? '' : `<div class="pm-msg-avatar">${m.username.charAt(0).toUpperCase()}</div>`)
                + `<div><div class="pm-msg-bubble">${m.body}</div><div class="pm-msg-time">${formatTime(m.created_at)}</div></div>`;
            container.appendChild(div);
            lastMsgId = Math.max(lastMsgId, parseInt(m.id));
        });
        container.scrollTop = container.scrollHeight;
    } catch(e) {}
}
setInterval(pollMessages, 8000);
<?php endif; ?>

// ── Conversation filter ────────────────────────────────────────────────────────
function filterConvs(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.pm-conv-item').forEach(el => {
        el.style.display = (!q || el.dataset.name.includes(q)) ? '' : 'none';
    });
}

// ── New message modal ──────────────────────────────────────────────────────────
function openNewModal() {
    document.getElementById('new-modal').classList.add('open');
    setTimeout(() => document.getElementById('modal-user-search').focus(), 80);
}
function closeNewModal() {
    document.getElementById('new-modal').classList.remove('open');
    document.getElementById('modal-user-search').value = '';
    filterUsers('');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNewModal(); });

function filterUsers(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.pm-modal-user-item').forEach(el => {
        el.classList.toggle('hidden', q !== '' && !el.dataset.name.includes(q));
    });
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
