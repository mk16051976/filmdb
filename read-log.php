<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!isAdmin()) die('Nur Admins.');
$log = __DIR__ . '/turnier_error.log';
if (!file_exists($log)) { echo 'Keine Logdatei gefunden.'; exit; }
echo '<pre style="background:#111;color:#0f0;padding:1rem;font-size:.8rem;">';
echo htmlspecialchars(file_get_contents($log));
echo '</pre>';
echo '<a href="javascript:void(0)" onclick="fetch(location.href+\'?clear=1\').then(()=>location.reload())">Log löschen</a>';
if (isset($_GET['clear'])) { file_put_contents($log, ''); echo 'Geleert.'; }
