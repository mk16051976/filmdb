<?php
require_once __DIR__ . '/includes/functions.php';
startSession();
session_regenerate_id(true);
$_SESSION = [];
session_destroy();
header('Location: /index.php');
exit;
