<?php
/**
 * FixMyRoad (XAMPP / MySQL PDO Configuration)
 * Default XAMPP credentials: host = localhost, user = root, password = ""
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'fixmyroad_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Database Setup Required</title>'
            . '<link rel="stylesheet" href="css/style.css"></head><body>'
            . '<div class="container-narrow"><div class="card stack-md" style="border-color:var(--danger-border);">'
            . '<h1 class="section-title text-danger">MySQL Database Connection Error</h1>'
            . '<p class="text-sm">Could not connect to database <strong>' . htmlspecialchars(DB_NAME) . '</strong>.</p>'
            . '<ol class="text-xs stack-sm" style="padding-left:20px; margin:0;">'
            . '<li>Open <strong>XAMPP Control Panel</strong> and make sure both <strong>Apache</strong> and <strong>MySQL</strong> are running.</li>'
            . '<li>Open <a class="text-primary font-bold" href="http://localhost/phpmyadmin" target="_blank">phpMyAdmin</a> and click <strong>Import</strong>.</li>'
            . '<li>Select <code>database/fixmyroad.sql</code> from this project folder and click <strong>Go / Import</strong>.</li>'
            . '</ol>'
            . '<p class="text-xs text-mono text-muted">Details: ' . htmlspecialchars($e->getMessage()) . '</p>'
            . '</div></div></body></html>';
        exit;
    }
}
