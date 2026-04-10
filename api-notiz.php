<?php
/**
 * api-notiz.php — Notizblock AJAX-Endpunkt
 */
require_once __DIR__ . '/includes/functions.php';
startSession();
if (!isLoggedIn()) { http_response_code(403); echo json_encode(['ok' => false]); exit; }

header('Content-Type: application/json; charset=utf-8');
$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// Tabelle einmalig anlegen
$db->exec("CREATE TABLE IF NOT EXISTS user_notizen (
    user_id    INT NOT NULL PRIMARY KEY,
    content    TEXT NOT NULL DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'load') {
    $stmt = $db->prepare("SELECT content FROM user_notizen WHERE user_id = ?");
    $stmt->execute([$userId]);
    $content = $stmt->fetchColumn() ?: '';
    echo json_encode(['ok' => true, 'content' => $content]);
    exit;
}

if ($action === 'save') {
    if (!csrfValid()) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }
    $content = substr($_POST['content'] ?? '', 0, 10000);
    $db->prepare("INSERT INTO user_notizen (user_id, content) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE content = ?, updated_at = NOW()")
       ->execute([$userId, $content, $content]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_action']);
