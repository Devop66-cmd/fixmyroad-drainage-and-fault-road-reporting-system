<?php
/**
 * Lightweight AJAX polling endpoint for live notices and unread notification badge counts.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

try {
    $notices = get_live_official_notices();
    $topNotice = $notices[0] ?? null;
    $user = current_user();
    $unread = 0;

    if ($user) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND (target_role = 'All' OR target_role = ?)");
        $stmt->execute([$user['role']]);
        $unread = (int)$stmt->fetchColumn();
    }

    echo json_encode([
        'ok' => true,
        'live_notices_count' => count($notices),
        'top_notice' => $topNotice ? [
            'id' => $topNotice['id'],
            'title' => $topNotice['title'],
            'category' => $topNotice['category'],
            'expires_at' => $topNotice['expires_at'],
        ] : null,
        'unread_notifications_count' => $unread,
        'server_time' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database poll failed']);
}
