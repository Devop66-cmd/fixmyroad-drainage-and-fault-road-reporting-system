<?php
/**
 * FixMyRoad Shared Helpers: Escaping, CSRF, Settings, Kansanga Geofence, SLA & Hero Points
 */

const KANSANGA_CENTER_LAT = 0.2835;
const KANSANGA_CENTER_LNG = 32.6095;
const KANSANGA_MIN_LAT = 0.272;
const KANSANGA_MAX_LAT = 0.298;
const KANSANGA_MIN_LNG = 32.594;
const KANSANGA_MAX_LNG = 32.624;

function e(?string $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!$submitted || !hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        flash_set('error', 'Security session token expired. Please submit the form again.');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }
}

function flash_set(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function redirect_to(string $path): void {
    header('Location: ' . $path);
    exit;
}

function get_portal_settings(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $defaults = [
        'sla_urgent_days' => '1',
        'sla_high_days' => '3',
        'sla_medium_days' => '7',
        'sla_low_days' => '14',
        'support_toll_free' => '0800 900 112',
        'support_city_line' => '+256 414 901 000',
        'support_dispatch_email' => 'works@roads.gov.ug',
    ];
    try {
        $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        foreach ($rows as $row) {
            $defaults[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        // fallback to defaults
    }
    $cache = $defaults;
    return $cache;
}

function log_audit(string $actorName, string $actorRole, string $action, ?string $targetId, string $details, string $category = 'report'): void {
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (actor_name, actor_role, action, target_id, details, category) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$actorName, $actorRole, $action, $targetId, $details, $category]);
}

function create_notification(string $title, string $message, string $type = 'info', string $targetRole = 'All', ?string $reportId = null): void {
    $stmt = db()->prepare(
        'INSERT INTO notifications (title, message, notif_type, target_role, report_id) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$title, $message, $type, $targetRole, $reportId]);
}

function haversine_meters(float $lat1, float $lng1, float $lat2, float $lng2): int {
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLng / 2) ** 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return (int)round($earthRadius * $c);
}

function validate_kansanga_boundary(?float $lat, ?float $lng, string $location): array {
    $loc = strtolower($location);
    $dist = ($lat !== null && $lng !== null)
        ? haversine_meters(KANSANGA_CENTER_LAT, KANSANGA_CENTER_LNG, $lat, $lng)
        : 0;

    $hasKansangaKeyword = str_contains($loc, 'kansanga')
        || str_contains($loc, 'kiwafu')
        || str_contains($loc, 'nabutiti')
        || str_contains($loc, 'kiu')
        || str_contains($loc, 'kampala international')
        || str_contains($loc, 'ggaba')
        || str_contains($loc, 'konge');

    $outsideKeywords = ['kololo', 'nakawa', 'ntinda', 'bugolobi', 'kawempe', 'makerere', 'mengo', 'rubaga', 'entebbe', 'wakiso'];
    foreach ($outsideKeywords as $kw) {
        if (str_contains($loc, $kw) && !$hasKansangaKeyword) {
            return [
                'valid' => false,
                'reason' => "Location \"$location\" appears to be outside the active Kansanga, Makindye Division pilot zone.",
            ];
        }
    }

    if ($lat !== null && $lng !== null) {
        $inBox = $lat >= KANSANGA_MIN_LAT && $lat <= KANSANGA_MAX_LAT
            && $lng >= KANSANGA_MIN_LNG && $lng <= KANSANGA_MAX_LNG;
        if (!$inBox) {
            return [
                'valid' => false,
                'reason' => sprintf(
                    'Selected GPS pin (%.4f, %.4f) is %dm from Kansanga center and outside the active Kansanga service boundary.',
                    $lat,
                    $lng,
                    $dist
                ),
            ];
        }
        return ['valid' => true, 'distance' => $dist];
    }

    return $hasKansangaKeyword
        ? ['valid' => true, 'distance' => $dist]
        : ['valid' => false, 'reason' => 'Please enter a street location inside Kansanga, Makindye Division.'];
}

function compute_sla_deadline(string $dateStr, string $priority): string {
    $settings = get_portal_settings();
    $daysMap = [
        'Urgent' => max(1, (int)($settings['sla_urgent_days'] ?? 1)),
        'High' => max(1, (int)($settings['sla_high_days'] ?? 3)),
        'Medium' => max(1, (int)($settings['sla_medium_days'] ?? 7)),
        'Low' => max(1, (int)($settings['sla_low_days'] ?? 14)),
    ];
    $days = $daysMap[$priority] ?? 7;
    $ts = strtotime($dateStr) ?: time();
    return date('Y-m-d H:i', $ts + $days * 86400);
}

function get_sla_status(array $report): array {
    if (($report['status'] ?? '') === 'Resolved') {
        return ['overdue' => false, 'label' => 'Met / Closed'];
    }
    if (($report['status'] ?? '') === 'Rejected') {
        return ['overdue' => false, 'label' => 'Closed'];
    }
    $deadlineTs = strtotime($report['sla_deadline'] ?? '') ?: (time() + 86400);
    $diffHours = ($deadlineTs - time()) / 3600;
    if ($diffHours < 0) {
        return ['overdue' => true, 'label' => 'Overdue by ' . abs((int)round($diffHours)) . 'h'];
    }
    if ($diffHours <= 24) {
        return ['overdue' => false, 'label' => max(1, (int)round($diffHours)) . 'h left'];
    }
    return ['overdue' => false, 'label' => (int)ceil($diffHours / 24) . 'd left'];
}

function compute_hero_summary(array $user): array {
    $pdo = db();
    $email = strtolower(trim($user['email'] ?? ''));
    $name = strtolower(trim($user['name'] ?? ''));

    $stmt = $pdo->prepare(
        'SELECT status, upvotes, feedback_rating FROM reports WHERE LOWER(reported_by_email) = ? OR LOWER(reported_by) = ?'
    );
    $stmt->execute([$email, $name]);
    $authored = $stmt->fetchAll();

    $reportedCount = count($authored);
    $resolvedCount = 0;
    $endorsementsReceived = 0;
    $feedbackCount = 0;
    foreach ($authored as $r) {
        if ($r['status'] === 'Resolved') {
            $resolvedCount++;
        }
        $endorsementsReceived += max(0, ((int)$r['upvotes']) - 1);
        if (!empty($r['feedback_rating'])) {
            $feedbackCount++;
        }
    }

    $upStmt = $pdo->prepare('SELECT COUNT(*) FROM report_upvotes WHERE user_id = ?');
    $upStmt->execute([$user['id']]);
    $endorsementsGiven = (int)$upStmt->fetchColumn();

    $verifiedBonus = 15; // Active verified local account
    $points = ($reportedCount * 10)
        + ($resolvedCount * 50)
        + ($feedbackCount * 15)
        + (($endorsementsReceived + $endorsementsGiven) * 2)
        + $verifiedBonus;

    $tiers = [
        ['name' => 'Road Watcher', 'min' => 0],
        ['name' => 'Street Guardian', 'min' => 50],
        ['name' => 'Drainage Defender', 'min' => 150],
        ['name' => 'Civic Steward', 'min' => 300],
        ['name' => 'Kampala Community Hero', 'min' => 500],
    ];
    $currentIdx = 0;
    foreach ($tiers as $idx => $t) {
        if ($points >= $t['min']) {
            $currentIdx = $idx;
        }
    }
    $currentTier = $tiers[$currentIdx];
    $nextTier = $tiers[$currentIdx + 1] ?? null;
    $progressPct = 100;
    if ($nextTier) {
        $span = max(1, $nextTier['min'] - $currentTier['min']);
        $progressPct = min(100, (int)round((($points - $currentTier['min']) / $span) * 100));
    }

    return [
        'points' => $points,
        'tier' => $currentTier['name'],
        'nextTier' => $nextTier ? $nextTier['name'] : null,
        'nextTierMin' => $nextTier ? $nextTier['min'] : $currentTier['min'],
        'progressPct' => $progressPct,
        'reportedCount' => $reportedCount,
        'resolvedCount' => $resolvedCount,
        'feedbackCount' => $feedbackCount,
        'endorsementsTotal' => $endorsementsReceived + $endorsementsGiven,
    ];
}

function get_live_official_notices(): array {
    $sql = "SELECT * FROM official_notices
            WHERE status = 'active'
              AND (expires_at IS NULL OR expires_at >= CURDATE())
            ORDER BY FIELD(category, 'Emergency', 'Maintenance', 'General Announcement'), created_at DESC";
    return db()->query($sql)->fetchAll();
}

function get_notice_lifecycle_status(array $notice): string {
    if (($notice['status'] ?? '') === 'archived') {
        return 'archived';
    }
    if (!empty($notice['expires_at']) && $notice['expires_at'] < date('Y-m-d')) {
        return 'expired';
    }
    return 'active';
}

function handle_photo_upload(string $fieldName): ?string {
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = $_FILES[$fieldName]['tmp_name'];
    $size = (int)$_FILES[$fieldName]['size'];
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmp);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return null;
    }

    $uploadDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    $filename = 'fault_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $uploadDir . '/' . $filename;
    if (move_uploaded_file($tmp, $dest)) {
        return 'uploads/' . $filename;
    }
    return null;
}
