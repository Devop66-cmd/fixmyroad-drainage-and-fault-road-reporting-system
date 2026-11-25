<?php
/**
 * FixMyRoad (XAMPP / MySQL PDO Configuration)
 * Default XAMPP credentials: host = localhost, user = root, password = ""
 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'fixmyroad_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

function ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(160) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_reset_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS outbound_messages (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        channel ENUM('email','sms') NOT NULL,
        destination VARCHAR(180) NOT NULL,
        subject VARCHAR(180) NOT NULL,
        body TEXT NOT NULL,
        status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $col = $pdo->query("SHOW COLUMNS FROM report_comments LIKE 'hidden'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE report_comments ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER is_official_question");
    }

    $seed = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ([
        'sla_last_sweep' => '2000-01-01 00:00:00',
        'notify_email' => '0',
        'notify_sms' => '0',
    ] as $k => $v) {
        $seed->execute([$k, $v]);
    }

    $roleEnum = "ENUM('Community Member','Field Officer','Supervisor','Page Moderator','Administrator')";
    $pdo->exec("ALTER TABLE users MODIFY role $roleEnum NOT NULL DEFAULT 'Community Member'");
    $pdo->exec("ALTER TABLE report_comments MODIFY author_role $roleEnum NOT NULL DEFAULT 'Community Member'");
    $pdo->exec("ALTER TABLE notifications MODIFY target_role ENUM('All','Community Member','Field Officer','Supervisor','Page Moderator','Administrator') NOT NULL DEFAULT 'All'");

    $hash = password_hash('password123', PASSWORD_DEFAULT);
    $add = $pdo->prepare("INSERT IGNORE INTO users (id, name, email, password_hash, phone, role, division, street_address, account_status) VALUES (?, ?, ?, ?, ?, ?, 'Kansanga (Makindye)', ?, 'active')");
    $add->execute(['usr-4', 'James Okello', 'supervisor@roads.gov.ug', $hash, '+256 700 100 004', 'Supervisor', 'Makindye Works Office']);
    $add->execute(['usr-5', 'Amina Nabirye', 'moderator@roads.gov.ug', $hash, '+256 700 100 005', 'Page Moderator', 'Kansanga Parish']);
}

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
        ensure_schema($pdo);
        $pdo->exec("UPDATE users SET password_hash = '!' WHERE password_hash = 'SEED_PLAIN:password123'");
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
