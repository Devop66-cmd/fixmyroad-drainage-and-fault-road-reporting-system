<?php
/**
 * FixMyRoad Session & Role-Based Authentication Helpers
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $cachedUser = null;
    if ($cachedUser !== null && $cachedUser['id'] === $_SESSION['user_id']) {
        return $cachedUser;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['account_status'] === 'blocked') {
        unset($_SESSION['user_id']);
        return null;
    }

    $cachedUser = $user;
    return $user;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        flash_set('error', 'Please sign in to access FixMyRoad.');
        redirect_to('login.php');
    }
    return $user;
}

function require_role(array $allowedRoles): array {
    $user = require_login();
    if (!in_array($user['role'], $allowedRoles, true)) {
        flash_set('error', 'Access denied: your account role cannot access that area.');
        $target = match ($user['role']) {
            'Administrator' => 'admin_dashboard.php',
            'Field Officer' => 'field_officer.php',
            default => 'dashboard.php',
        };
        redirect_to($target);
    }
    return $user;
}

function verify_user_password(array $user, string $plainPassword): bool {
    $stored = $user['password_hash'] ?? '';
    if (str_starts_with($stored, 'SEED_PLAIN:')) {
        $expected = substr($stored, strlen('SEED_PLAIN:'));
        if (hash_equals($expected, $plainPassword)) {
            $upgradedHash = password_hash($plainPassword, PASSWORD_DEFAULT);
            $upd = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $upd->execute([$upgradedHash, $user['id']]);
            return true;
        }
        return false;
    }
    return password_verify($plainPassword, $stored);
}
