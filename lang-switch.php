<?php
require_once __DIR__ . '/includes/functions.php';
startSession();

$lang = ($_GET['lang'] ?? 'de') === 'en' ? 'en' : 'de';
$_SESSION['lang'] = $lang;

$back = $_SERVER['HTTP_REFERER'] ?? '/index.php';
// Security: only allow same-origin redirects
$parsed = parse_url($back);
if (!empty($parsed['host']) && $parsed['host'] !== $_SERVER['HTTP_HOST']) {
    $back = '/index.php';
}
header('Location: ' . $back);
exit;
