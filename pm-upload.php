<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValid()) {
    echo '{"error":"csrf"}'; exit;
}
if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo '{"error":"no_file"}'; exit;
}

$file    = $_FILES['image'];
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

// Verify MIME via finfo (not trusting $_FILES['type'])
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
if (!isset($allowed[$mime])) {
    echo '{"error":"invalid_type"}'; exit;
}
if ($file['size'] > 5 * 1024 * 1024) {
    echo '{"error":"too_large"}'; exit;
}

$dir = __DIR__ . '/uploads/pm/';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    echo '{"error":"mkdir_failed"}'; exit;
}

$ext  = $allowed[$mime];
$name = bin2hex(random_bytes(16)) . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
    echo '{"error":"move_failed"}'; exit;
}

echo json_encode(['url' => '/uploads/pm/' . $name]);
