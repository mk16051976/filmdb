<?php
// Zugangsdaten aus separater Datei laden (nicht in Versionskontrolle)
require_once __DIR__ . '/secrets.php';

// Auf Produktionsserver PHP-Fehlerausgabe deaktivieren
if (!in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'])) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

define('TMDB_IMAGE_BASE', 'https://image.tmdb.org/t/p/w500');
define('TMDB_IMAGE_ORIGINAL', 'https://image.tmdb.org/t/p/original');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('DB-Verbindungsfehler: ' . $e->getMessage());
            die('<div style="font-family:sans-serif;padding:2rem;color:#c0392b;">
                <h2>Datenbankfehler</h2>
                <p>Die Datenbankverbindung konnte nicht hergestellt werden.</p>
                <small>Weitere Details im Server-Log.</small>
            </div>');
        }
    }
    return $pdo;
}
