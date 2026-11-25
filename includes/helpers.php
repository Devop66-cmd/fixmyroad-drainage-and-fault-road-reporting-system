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

const SYSTEM_ROLES = ['Community Member', 'Field Officer', 'Supervisor', 'Page Moderator', 'Administrator'];

function role_privileges(): array {
    return [
        'Community Member' => [
            'report_fault', 'view_own_reports', 'view_community_feed', 'upvote_reports',
            'confirm_own_resolved', 'reopen_own_resolved', 'submit_feedback',
            'view_public_data', 'view_map', 'edit_own_profile',
        ],
        'Field Officer' => [
            'report_fault', 'view_own_reports', 'view_community_feed', 'upvote_reports',
            'view_public_data', 'view_map', 'edit_own_profile',
            'update_work_orders', 'upload_after_photo', 'complete_maintenance',
            'view_assigned_tickets', 'post_official_comments', 'moderate_comments',
        ],
        'Supervisor' => [
            'report_fault', 'view_own_reports', 'view_community_feed', 'view_public_data', 'view_map',
            'edit_own_profile', 'update_work_orders', 'view_assigned_tickets',
            'manage_all_reports', 'assign_officers', 'escalate_reports', 'bulk_update_reports',
            'manage_maintenance', 'configure_sla', 'access_admin_dashboard', 'access_field_portal',
        ],
        'Page Moderator' => [
            'view_public_data', 'view_map', 'edit_own_profile',
            'moderate_comments', 'manage_notices', 'access_admin_dashboard',
        ],
        'Administrator' => [
            'report_fault', 'view_own_reports', 'view_community_feed', 'upvote_reports',
            'view_public_data', 'view_map', 'edit_own_profile',
            'update_work_orders', 'upload_after_photo', 'complete_maintenance',
            'view_assigned_tickets', 'post_official_comments', 'moderate_comments',
            'manage_all_reports', 'assign_officers', 'escalate_reports', 'bulk_update_reports',
            'delete_resolved_reports', 'manage_notices', 'manage_maintenance',
            'manage_staff', 'change_user_roles', 'block_users',
            'configure_sla', 'configure_support', 'view_audit_log', 'export_audit_csv',
            'access_admin_dashboard', 'access_field_portal',
        ],
    ];
}

function user_can(array $user, string $privilege): bool {
    $role = $user['role'] ?? 'Community Member';
    return in_array($privilege, role_privileges()[$role] ?? [], true);
}

function role_home(string $role): string {
    return match ($role) {
        'Administrator' => 'admin_dashboard.php',
        'Supervisor' => 'admin_dashboard.php?tab=reports',
        'Page Moderator' => 'admin_dashboard.php?tab=notices',
        'Field Officer' => 'field_officer.php',
        default => 'dashboard.php',
    };
}

function tabs_for_role(string $role): array {
    return match ($role) {
        'Administrator' => ['dashboard', 'reports', 'notices', 'maintenance', 'workload', 'staff', 'sla', 'support', 'audit'],
        'Supervisor' => ['dashboard', 'reports', 'maintenance', 'workload', 'sla'],
        'Page Moderator' => ['notices'],
        default => [],
    };
}

function action_allowed_for_role(string $role, string $action): bool {
    if ($role === 'Administrator') {
        return true;
    }
    $allowed = [
        'Supervisor' => ['update_report', 'bulk_update_reports', 'create_maintenance', 'update_maintenance_status', 'run_sla_sweep'],
        'Page Moderator' => ['create_notice', 'update_notice_expiry', 'toggle_notice_archive', 'delete_notice'],
    ];
    return in_array($action, $allowed[$role] ?? [], true);
}

function login_lock_message(): ?string {
    $until = (int)($_SESSION['login_lock_until'] ?? 0);
    if ($until > time()) {
        $mins = (int)ceil(($until - time()) / 60);
        return "Too many attempts. Try again in {$mins} minute(s).";
    }
    return null;
}

function register_login_failure(): void {
    $fails = (int)($_SESSION['login_fails'] ?? 0) + 1;
    $_SESSION['login_fails'] = $fails;
    if ($fails >= 5) {
        $_SESSION['login_lock_until'] = time() + 600;
        $_SESSION['login_fails'] = 0;
    }
}

function clear_login_failures(): void {
    unset($_SESSION['login_fails'], $_SESSION['login_lock_until']);
}

function register_attempt_blocked(): bool {
    $windowStart = (int)($_SESSION['reg_window'] ?? 0);
    if ($windowStart < time() - 3600) {
        $_SESSION['reg_window'] = time();
        $_SESSION['reg_count'] = 0;
    }
    return (int)($_SESSION['reg_count'] ?? 0) >= 5;
}

function register_attempt_tick(): void {
    $_SESSION['reg_count'] = (int)($_SESSION['reg_count'] ?? 0) + 1;
}

function queue_outbound(string $channel, string $destination, string $subject, string $body): void {
    if ($destination === '') {
        return;
    }
    $stmt = db()->prepare('INSERT INTO outbound_messages (channel, destination, subject, body) VALUES (?, ?, ?, ?)');
    $stmt->execute([$channel, $destination, $subject, $body]);
}

function run_sla_sweep(string $actorName = 'SLA Scheduler', string $actorRole = 'System'): int {
    $pdo = db();
    $candidates = $pdo->query("SELECT * FROM reports WHERE status NOT IN ('Resolved', 'Rejected') AND escalated = 0")->fetchAll();
    $nowTs = time();
    $count = 0;
    $nowLabel = date('Y-m-d H:i');
    foreach ($candidates as $rep) {
        $dlTs = strtotime($rep['sla_deadline'] ?? '');
        if ($dlTs && $dlTs < $nowTs) {
            $reason = "{$rep['priority']} priority fault exceeded SLA deadline ({$rep['sla_deadline']}).";
            $pdo->prepare('UPDATE reports SET escalated = 1, escalation_reason = ? WHERE id = ?')->execute([$reason, $rep['id']]);
            $pdo->prepare("INSERT INTO report_updates (report_id, timestamp_label, text, author, update_type) VALUES (?, ?, ?, 'SLA Escalation Engine', 'escalation')")
                ->execute([$rep['id'], $nowLabel, "Auto-escalated by SLA sweep: $reason"]);
            create_notification("SLA Escalated ({$rep['id']})", $reason, 'escalation', 'Administrator', $rep['id']);
            $count++;
        }
    }
    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['sla_last_sweep', date('Y-m-d H:i:s')]);
    log_audit($actorName, $actorRole, 'Executed SLA Auto-Escalation Sweep', null, "Flagged $count overdue report(s).", 'escalation');
    return $count;
}

function maybe_run_scheduled_sla(): void {
    try {
        $settings = get_portal_settings();
        $last = strtotime($settings['sla_last_sweep'] ?? '') ?: 0;
        if (time() - $last < 6 * 3600) {
            return;
        }
        run_sla_sweep();
    } catch (Throwable $e) {
        // Scheduler must never break page render.
    }
}

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
        header('Location: index.php');
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
    $settings = get_portal_settings();
    if (($settings['notify_email'] ?? '0') === '1') {
        queue_outbound('email', (string)($settings['support_dispatch_email'] ?? 'works@roads.gov.ug'), $title, $message);
    }
    if (($settings['notify_sms'] ?? '0') === '1') {
        queue_outbound('sms', (string)($settings['support_city_line'] ?? ''), $title, $message);
    }
}

function haversine_meters(float $lat1, float $lng1, float $lat2, float $lng2): int {
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLng / 2) ** 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return (int)round($earthRadius * $c);
}

function kansanga_pin_inside(?float $lat, ?float $lng): bool {
    return $lat !== null && $lng !== null
        && $lat >= KANSANGA_MIN_LAT && $lat <= KANSANGA_MAX_LAT
        && $lng >= KANSANGA_MIN_LNG && $lng <= KANSANGA_MAX_LNG;
}

function validate_kansanga_boundary(?float $lat, ?float $lng, string $location): array {
    $loc = strtolower($location);
    $dist = ($lat !== null && $lng !== null)
        ? haversine_meters(KANSANGA_CENTER_LAT, KANSANGA_CENTER_LNG, $lat, $lng)
        : 0;

    if (!kansanga_pin_inside($lat, $lng)) {
        return [
            'valid' => false,
            'reason' => 'Reports are limited to Kansanga, Makindye Division, Kampala. Place the pin inside the blue boundary on the map.',
        ];
    }

    $outsideKeywords = ['kololo', 'nakawa', 'ntinda', 'bugolobi', 'kawempe', 'makerere', 'mengo', 'rubaga', 'entebbe', 'wakiso', 'najjera', 'kira', 'bweyogerere'];
    foreach ($outsideKeywords as $kw) {
        if (str_contains($loc, $kw)) {
            return [
                'valid' => false,
                'reason' => "Location \"$location\" is outside the Kansanga, Kampala pilot. Expansion to other areas is not open yet.",
            ];
        }
    }

    return ['valid' => true, 'distance' => $dist];
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
    $filename = 'fault_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $dest = $uploadDir . '/' . $filename;
    if (compress_uploaded_image($tmp, $dest, $mime)) {
        return 'uploads/' . $filename;
    }
    $fallback = 'fault_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    if (move_uploaded_file($tmp, $uploadDir . '/' . $fallback)) {
        return 'uploads/' . $fallback;
    }
    return null;
}

function compress_uploaded_image(string $tmp, string $dest, string $mime): bool {
    $loader = match ($mime) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        default => null,
    };
    if (!$loader || !function_exists($loader) || !function_exists('imagejpeg')) {
        return false;
    }
    $src = @$loader($tmp);
    if (!$src) {
        return false;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $max = 1600;
    if ($w > $max || $h > $max) {
        $scale = $max / max($w, $h);
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $resized = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $resized;
    }
    $ok = imagejpeg($src, $dest, 75);
    imagedestroy($src);
    return $ok;
}
