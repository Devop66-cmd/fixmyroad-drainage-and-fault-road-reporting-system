<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_role(['Administrator', 'Supervisor', 'Page Moderator']);
$pdo = db();

// ---------------------------------------------------------------------------
// 1. Handle CSV Export BEFORE any HTML output
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'audit_csv') {
    if ($user['role'] !== 'Administrator') {
        flash_set('error', 'Only an administrator can export the audit log.');
        redirect_to(role_home($user['role']));
    }
    $catFilter = trim((string)($_GET['audit_cat'] ?? 'All'));
    $qFilter = trim((string)($_GET['audit_q'] ?? ''));

    $logs = $pdo->query('SELECT * FROM audit_logs ORDER BY created_at DESC, id DESC')->fetchAll();
    $filtered = array_filter($logs, function ($row) use ($catFilter, $qFilter) {
        if ($catFilter !== 'All' && $row['category'] !== $catFilter) return false;
        if ($qFilter !== '') {
            $hay = strtolower($row['actor_name'] . ' ' . $row['action'] . ' ' . ($row['target_id'] ?? '') . ' ' . $row['details']);
            if (!str_contains($hay, strtolower($qFilter))) return false;
        }
        return true;
    });

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fixmyroad_audit_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Timestamp', 'Actor', 'Role', 'Category', 'Action', 'Target ID', 'Details']);
    foreach ($filtered as $row) {
        fputcsv($out, [
            $row['id'], $row['created_at'], $row['actor_name'], $row['actor_role'],
            $row['category'], $row['action'], $row['target_id'] ?? '', $row['details'],
        ]);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// 2. Handle POST Actions
// ---------------------------------------------------------------------------
$activeTab = trim((string)($_GET['tab'] ?? ''));
$validTabs = tabs_for_role($user['role']);
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = $validTabs[0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $returnTab = trim((string)($_POST['return_tab'] ?? $activeTab));
    if (!in_array($returnTab, $validTabs, true)) {
        $returnTab = $validTabs[0];
    }
    if (!action_allowed_for_role($user['role'], $action)) {
        flash_set('error', 'Your role cannot perform that action.');
        redirect_to('admin_dashboard.php?tab=' . urlencode($returnTab));
    }

    if ($action === 'update_report') {
        $reportId = trim((string)($_POST['report_id'] ?? ''));
        $nextStatus = trim((string)($_POST['status'] ?? 'Pending'));
        $assignedOfficer = trim((string)($_POST['assigned_officer'] ?? ''));
        $escalated = isset($_POST['escalated']) ? 1 : 0;
        $escReason = trim((string)($_POST['escalation_reason'] ?? ''));

        $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = ? LIMIT 1');
        $stmt->execute([$reportId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $validStatuses = ['Pending', 'In Progress', 'Resolved', 'Rejected'];
            if (!in_array($nextStatus, $validStatuses, true)) $nextStatus = $existing['status'];
            $officerVal = $assignedOfficer !== '' ? $assignedOfficer : null;
            $escReasonVal = $escalated ? ($escReason !== '' ? $escReason : ($existing['escalation_reason'] ?: 'Manually escalated by Administrator.')) : null;

            if ($nextStatus === 'Resolved' && $existing['status'] !== 'Resolved') {
                $pdo->prepare("UPDATE reports SET status = 'Resolved', assigned_officer = ?, escalated = ?, escalation_reason = ?, resolved_at = NOW(), confirmation_state = 'awaiting_confirmation', confirmation_expires_at = DATE_ADD(NOW(), INTERVAL 48 HOUR) WHERE id = ?")
                    ->execute([$officerVal, $escalated, $escReasonVal, $reportId]);
                create_notification("Repair Resolved ($reportId)", "Administrator {$user['name']} marked $reportId as Resolved. 48h citizen confirmation open.", 'resolved', 'Community Member', $reportId);
            } else {
                $pdo->prepare('UPDATE reports SET status = ?, assigned_officer = ?, escalated = ?, escalation_reason = ? WHERE id = ?')
                    ->execute([$nextStatus, $officerVal, $escalated, $escReasonVal, $reportId]);
            }

            $nowLabel = date('Y-m-d H:i');
            $summary = "Status: $nextStatus" . ($officerVal ? " | Assigned: $officerVal" : '') . ($escalated ? ' | Escalated' : '');
            $pdo->prepare('INSERT INTO report_updates (report_id, timestamp_label, text, author, update_type) VALUES (?, ?, ?, ?, ?)')
                ->execute([$reportId, $nowLabel, "Admin update — $summary", $user['name'] . ' (Admin)', $escalated && !$existing['escalated'] ? 'escalation' : 'assignment']);

            if ($officerVal && $officerVal !== ($existing['assigned_officer'] ?? '')) {
                create_notification("Work Order Assigned ($reportId)", "$reportId assigned to $officerVal.", 'assignment', 'Field Officer', $reportId);
            }

            log_audit($user['name'], $user['role'], 'Updated Report Dispatch', $reportId, $summary, 'assignment');
            flash_set('success', "Report $reportId updated successfully.");
        }
        redirect_to('admin_dashboard.php?tab=' . urlencode($returnTab));
    }

    if ($action === 'delete_report') {
        $reportId = trim((string)($_POST['report_id'] ?? ''));
        $stmt = $pdo->prepare('SELECT status, title FROM reports WHERE id = ? LIMIT 1');
        $stmt->execute([$reportId]);
        $rep = $stmt->fetch();

        if (!$rep) {
            flash_set('error', 'Report not found.');
        } elseif ($rep['status'] !== 'Resolved') {
            flash_set('error', 'Only Resolved reports can be deleted from the registry.');
        } else {
            $pdo->prepare('DELETE FROM reports WHERE id = ?')->execute([$reportId]);
            log_audit($user['name'], $user['role'], 'Deleted Resolved Report', $reportId, "Deleted resolved ticket \"{$rep['title']}\".", 'report');
            flash_set('success', "Resolved report $reportId deleted.");
        }
        redirect_to('admin_dashboard.php?tab=' . urlencode($returnTab));
    }

    if ($action === 'create_notice') {
        $title = trim((string)($_POST['title'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'General Announcement'));
        $message = trim((string)($_POST['message'] ?? ''));
        $expiresAt = trim((string)($_POST['expires_at'] ?? ''));

        $validCats = ['Emergency', 'Maintenance', 'General Announcement'];
        if (!in_array($category, $validCats, true)) $category = 'General Announcement';

        if ($title === '' || $message === '') {
            flash_set('error', 'Notice title and message are required.');
            redirect_to('admin_dashboard.php?tab=notices');
        }

        $expiresVal = null;
        if ($expiresAt !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $expiresAt);
            if (!$dt || $dt->format('Y-m-d') !== $expiresAt) {
                flash_set('error', 'Invalid expiry date format. Please pick a valid date.');
                redirect_to('admin_dashboard.php?tab=notices');
            }
            $expiresVal = $expiresAt;
        }

        $existingIds = $pdo->query('SELECT id FROM official_notices')->fetchAll(PDO::FETCH_COLUMN);
        $maxNum = 0;
        foreach ($existingIds as $nid) {
            if (preg_match('/(\d+)/', (string)$nid, $m)) {
                $maxNum = max($maxNum, (int)$m[1]);
            }
        }
        $newId = sprintf('#NTC-%03d', $maxNum + 1);

        $pdo->prepare("INSERT INTO official_notices (id, title, message, category, status, expires_at, posted_by, posted_by_id) VALUES (?, ?, ?, ?, 'active', ?, ?, ?)")
            ->execute([$newId, $title, $message, $category, $expiresVal, $user['name'], $user['id']]);

        $expiryText = $expiresVal ? "Expires: $expiresVal" : 'Ongoing';
        log_audit($user['name'], $user['role'], 'Published Official Notice', $newId, "[$category] \"$title\" ($expiryText)", 'notice');
        create_notification("Official Notice: $title", "[$category] $message", $category === 'Emergency' ? 'escalation' : 'info', 'All', null);

        flash_set('success', "Official notice $newId published ($category).");
        redirect_to('admin_dashboard.php?tab=notices');
    }

    if ($action === 'update_notice_expiry') {
        $noticeId = trim((string)($_POST['notice_id'] ?? ''));
        $expiresAt = trim((string)($_POST['expires_at'] ?? ''));
        $expiresVal = null;
        if ($expiresAt !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $expiresAt);
            if ($dt && $dt->format('Y-m-d') === $expiresAt) {
                $expiresVal = $expiresAt;
            }
        }
        $pdo->prepare('UPDATE official_notices SET expires_at = ? WHERE id = ?')->execute([$expiresVal, $noticeId]);
        log_audit($user['name'], $user['role'], 'Updated Notice Expiry', $noticeId, $expiresVal ? "Set expiry to $expiresVal" : 'Cleared expiry date (Ongoing)', 'notice');
        flash_set('success', "Updated expiry schedule for $noticeId.");
        redirect_to('admin_dashboard.php?tab=notices');
    }

    if ($action === 'toggle_notice_archive') {
        $noticeId = trim((string)($_POST['notice_id'] ?? ''));
        $targetState = ($_POST['target_state'] ?? '') === 'archived' ? 'archived' : 'active';
        if ($targetState === 'archived') {
            $pdo->prepare("UPDATE official_notices SET status = 'archived', archived_at = NOW() WHERE id = ?")->execute([$noticeId]);
            log_audit($user['name'], $user['role'], 'Archived Official Notice', $noticeId, 'Moved notice to archive.', 'notice');
            flash_set('success', "Notice $noticeId archived and hidden from citizen feed.");
        } else {
            $pdo->prepare("UPDATE official_notices SET status = 'active', archived_at = NULL WHERE id = ?")->execute([$noticeId]);
            log_audit($user['name'], $user['role'], 'Restored Official Notice', $noticeId, 'Restored notice to active status.', 'notice');
            flash_set('success', "Notice $noticeId restored.");
        }
        redirect_to('admin_dashboard.php?tab=notices');
    }

    if ($action === 'delete_notice') {
        $noticeId = trim((string)($_POST['notice_id'] ?? ''));
        $pdo->prepare('DELETE FROM official_notices WHERE id = ?')->execute([$noticeId]);
        log_audit($user['name'], $user['role'], 'Deleted Official Notice', $noticeId, 'Permanently deleted notice from history.', 'notice');
        flash_set('success', "Notice $noticeId permanently deleted.");
        redirect_to('admin_dashboard.php?tab=notices');
    }

    if ($action === 'create_maintenance') {
        $title = trim((string)($_POST['title'] ?? ''));
        $taskType = trim((string)($_POST['task_type'] ?? 'Culvert Clearing'));
        $division = trim((string)($_POST['division'] ?? 'Kansanga (Makindye)'));
        $corridor = trim((string)($_POST['corridor'] ?? ''));
        $frequency = trim((string)($_POST['frequency'] ?? 'Monthly'));
        $assigned = trim((string)($_POST['assigned_officer'] ?? 'David Mukasa'));
        $dueDate = trim((string)($_POST['next_due_date'] ?? date('Y-m-d', strtotime('+7 days'))));
        $estHours = max(0.5, (float)($_POST['estimated_hours'] ?? 4));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($title === '' || $corridor === '') {
            flash_set('error', 'Maintenance title and road/drainage corridor are required.');
            redirect_to('admin_dashboard.php?tab=maintenance');
        }

        $ids = $pdo->query('SELECT id FROM maintenance_tasks')->fetchAll(PDO::FETCH_COLUMN);
        $maxNum = 0;
        foreach ($ids as $mid) {
            if (preg_match('/(\d+)/', (string)$mid, $m)) {
                $maxNum = max($maxNum, (int)$m[1]);
            }
        }
        $pmId = sprintf('#PM-%03d', $maxNum + 1);

        $pdo->prepare("INSERT INTO maintenance_tasks (id, title, task_type, division, corridor, frequency, status, assigned_officer, next_due_date, estimated_hours, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, 'Scheduled', ?, ?, ?, ?, ?)")
            ->execute([$pmId, $title, $taskType, $division, $corridor, $frequency, $assigned, $dueDate, $estHours, $notes, $user['name']]);

        log_audit($user['name'], $user['role'], 'Created Preventive Schedule', $pmId, "$title on $corridor assigned to $assigned ($frequency).", 'maintenance');
        flash_set('success', "Preventive maintenance schedule $pmId created.");
        redirect_to('admin_dashboard.php?tab=maintenance');
    }

    if ($action === 'update_maintenance_status') {
        $pmId = trim((string)($_POST['task_id'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'Scheduled'));
        $validPm = ['Scheduled', 'Due Soon', 'Overdue', 'In Progress', 'Completed', 'Paused'];
        if (in_array($status, $validPm, true)) {
            $pdo->prepare('UPDATE maintenance_tasks SET status = ? WHERE id = ?')->execute([$status, $pmId]);
            log_audit($user['name'], $user['role'], 'Updated Preventive Task Status', $pmId, "Status changed to $status.", 'maintenance');
            flash_set('success', "Maintenance task $pmId marked $status.");
        }
        redirect_to('admin_dashboard.php?tab=maintenance');
    }

    if ($action === 'create_staff') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $requestedRole = trim((string)($_POST['role'] ?? 'Field Officer'));
        $role = in_array($requestedRole, SYSTEM_ROLES, true) ? $requestedRole : 'Field Officer';
        $division = trim((string)($_POST['division'] ?? 'Kansanga (Makindye)'));
        $specialty = trim((string)($_POST['specialty'] ?? 'Road & Drainage Maintenance'));
        $password = (string)($_POST['password'] ?? '');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            flash_set('error', 'Please enter a valid name, email, and password (min 6 characters).');
            redirect_to('admin_dashboard.php?tab=staff');
        }

        $chk = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetch()) {
            flash_set('error', 'An account with that email already exists.');
            redirect_to('admin_dashboard.php?tab=staff');
        }

        $uid = 'usr-' . bin2hex(random_bytes(4));
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (id, name, email, password_hash, phone, role, division, street_address, account_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Kansanga Works Outpost, Makindye Division', 'active')")
            ->execute([$uid, $name, $email, $hash, $phone, $role, $division]);

        if ($role === 'Field Officer') {
            $offId = 'off-' . bin2hex(random_bytes(3));
            $pdo->prepare("INSERT INTO officers (id, name, division, specialty, max_capacity, status, avg_resolution_hours) VALUES (?, ?, ?, ?, 5, 'Available', 20)")
                ->execute([$offId, $name, $division, $specialty]);
        }

        log_audit($user['name'], $user['role'], 'Provisioned Staff Account', $uid, "Created $role account for $name ($email).", 'auth');
        flash_set('success', "$role account created for $name.");
        redirect_to('admin_dashboard.php?tab=staff');
    }

    if ($action === 'toggle_user_status') {
        $targetId = trim((string)($_POST['user_id'] ?? ''));
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();

        if (!$target) {
            flash_set('error', 'Account not found.');
        } elseif ($target['id'] === $user['id'] || $target['id'] === 'usr-3' || strtolower($target['email']) === 'admin@roads.gov.ug') {
            flash_set('error', 'Protected primary Administrator or current active session cannot be blocked.');
        } else {
            $nextState = $target['account_status'] === 'active' ? 'blocked' : 'active';
            $reason = $nextState === 'blocked' ? 'Suspended by Administrator.' : null;
            $pdo->prepare('UPDATE users SET account_status = ?, blocked_reason = ? WHERE id = ?')->execute([$nextState, $reason, $targetId]);
            log_audit($user['name'], $user['role'], $nextState === 'blocked' ? 'Suspended User Account' : 'Reactivated User Account', $targetId, "{$target['name']} ({$target['email']}) set to $nextState.", 'auth');
            flash_set('success', "Account {$target['name']} is now $nextState.");
        }
        redirect_to('admin_dashboard.php?tab=staff');
    }

    if ($action === 'delete_user') {
        $targetId = trim((string)($_POST['user_id'] ?? ''));
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();

        if (!$target) {
            flash_set('error', 'Account not found.');
        } elseif ($target['id'] === $user['id'] || $target['id'] === 'usr-3' || strtolower($target['email']) === 'admin@roads.gov.ug') {
            flash_set('error', 'Protected primary Administrator or your own account cannot be deleted.');
        } else {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
            log_audit($user['name'], $user['role'], 'Deleted User Account', $targetId, "Removed {$target['name']} ({$target['email']}).", 'auth');
            flash_set('success', "Account {$target['name']} removed.");
        }
        redirect_to('admin_dashboard.php?tab=staff');
    }

    if ($action === 'change_user_role') {
        $targetId = trim((string)($_POST['user_id'] ?? ''));
        $newRole = trim((string)($_POST['new_role'] ?? ''));
        if (!in_array($newRole, SYSTEM_ROLES, true)) {
            flash_set('error', 'Invalid role selected.');
            redirect_to('admin_dashboard.php?tab=staff');
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) {
            flash_set('error', 'Account not found.');
        } elseif ($target['id'] === $user['id'] || $target['id'] === 'usr-3' || strtolower($target['email']) === 'admin@roads.gov.ug') {
            flash_set('error', 'Protected primary Administrator role cannot be changed.');
        } elseif ($target['role'] === $newRole) {
            flash_set('error', "{$target['name']} already has the role \"$newRole\".");
        } else {
            $oldRole = $target['role'];
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
            if ($newRole === 'Field Officer') {
                $exists = $pdo->prepare('SELECT id FROM officers WHERE name = ? LIMIT 1');
                $exists->execute([$target['name']]);
                if (!$exists->fetch()) {
                    $pdo->prepare("INSERT INTO officers (id, name, division, specialty, max_capacity, status, avg_resolution_hours) VALUES (?, ?, ?, 'Road & Drainage Maintenance', 5, 'Available', 20)")
                        ->execute(['off-' . bin2hex(random_bytes(3)), $target['name'], $target['division'] ?: 'Kansanga (Makindye)']);
                }
            }
            log_audit($user['name'], $user['role'], 'Changed User Role', $targetId, "{$target['name']} ({$target['email']}): \"$oldRole\" → \"$newRole\".", 'auth');
            flash_set('success', "Role of {$target['name']} updated to $newRole.");
        }
        redirect_to('admin_dashboard.php?tab=staff');
    }

    if ($action === 'bulk_update_reports') {
        $ids = $_POST['report_ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            flash_set('error', 'Select at least one report.');
            redirect_to('admin_dashboard.php?tab=reports');
        }
        $nextStatus = trim((string)($_POST['status'] ?? ''));
        $officer = trim((string)($_POST['assigned_officer'] ?? ''));
        $validStatuses = ['', 'Pending', 'In Progress', 'Resolved', 'Rejected'];
        if (!in_array($nextStatus, $validStatuses, true)) {
            $nextStatus = '';
        }
        $updated = 0;
        foreach ($ids as $reportId) {
            $reportId = trim((string)$reportId);
            $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = ? LIMIT 1');
            $stmt->execute([$reportId]);
            $existing = $stmt->fetch();
            if (!$existing) {
                continue;
            }
            $status = $nextStatus !== '' ? $nextStatus : $existing['status'];
            $officerVal = $officer !== '' ? $officer : $existing['assigned_officer'];
            if ($status === 'Resolved' && $existing['status'] !== 'Resolved') {
                $pdo->prepare("UPDATE reports SET status = 'Resolved', assigned_officer = ?, resolved_at = NOW(), confirmation_state = 'awaiting_confirmation', confirmation_expires_at = DATE_ADD(NOW(), INTERVAL 48 HOUR) WHERE id = ?")
                    ->execute([$officerVal, $reportId]);
            } else {
                $pdo->prepare('UPDATE reports SET status = ?, assigned_officer = ? WHERE id = ?')
                    ->execute([$status, $officerVal, $reportId]);
            }
            $updated++;
        }
        log_audit($user['name'], $user['role'], 'Bulk Updated Reports', null, "Updated $updated report(s). Status: " . ($nextStatus ?: 'unchanged') . '.', 'assignment');
        flash_set('success', "Bulk update applied to $updated report(s).");
        redirect_to('admin_dashboard.php?tab=reports');
    }

    if ($action === 'save_sla_settings') {
        $pairs = [
            'sla_urgent_days' => (string)max(1, (int)($_POST['sla_urgent_days'] ?? 1)),
            'sla_high_days' => (string)max(1, (int)($_POST['sla_high_days'] ?? 3)),
            'sla_medium_days' => (string)max(1, (int)($_POST['sla_medium_days'] ?? 7)),
            'sla_low_days' => (string)max(1, (int)($_POST['sla_low_days'] ?? 14)),
        ];
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($pairs as $k => $v) {
            $stmt->execute([$k, $v]);
        }
        log_audit($user['name'], $user['role'], 'Updated SLA Policy Thresholds', null, 'Urgent ' . $pairs['sla_urgent_days'] . 'd / High ' . $pairs['sla_high_days'] . 'd / Medium ' . $pairs['sla_medium_days'] . 'd / Low ' . $pairs['sla_low_days'] . 'd', 'escalation');
        flash_set('success', 'SLA response policy saved.');
        redirect_to('admin_dashboard.php?tab=sla');
    }

    if ($action === 'run_sla_sweep') {
        $count = run_sla_sweep($user['name'], $user['role']);
        flash_set('success', "SLA Sweep complete: $count overdue fault(s) escalated.");
        redirect_to('admin_dashboard.php?tab=sla');
    }

    if ($action === 'save_support_settings') {
        $pairs = [
            'support_toll_free' => trim((string)($_POST['support_toll_free'] ?? '0800 900 112')),
            'support_city_line' => trim((string)($_POST['support_city_line'] ?? '+256 414 901 000')),
            'support_dispatch_email' => trim((string)($_POST['support_dispatch_email'] ?? 'works@roads.gov.ug')),
            'notify_email' => isset($_POST['notify_email']) ? '1' : '0',
            'notify_sms' => isset($_POST['notify_sms']) ? '1' : '0',
            'luganda_enabled' => isset($_POST['luganda_enabled']) ? '1' : '0',
        ];
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($pairs as $k => $v) {
            $stmt->execute([$k, $v]);
        }
        log_audit($user['name'], $user['role'], 'Updated Hotline & Dispatch Settings', null, implode(' | ', $pairs), 'report');
        flash_set('success', 'Support & dispatch channels updated across the portal.');
        redirect_to('admin_dashboard.php?tab=support');
    }
}

// ---------------------------------------------------------------------------
// 3. Load Admin Datasets
// ---------------------------------------------------------------------------
$reports = $pdo->query('SELECT * FROM reports ORDER BY created_at DESC')->fetchAll();
$officers = $pdo->query('SELECT * FROM officers ORDER BY name ASC')->fetchAll();
$notices = $pdo->query('SELECT * FROM official_notices ORDER BY created_at DESC')->fetchAll();
$maintenanceTasks = $pdo->query('SELECT * FROM maintenance_tasks ORDER BY next_due_date ASC')->fetchAll();
$usersList = $pdo->query('SELECT * FROM users ORDER BY created_at ASC')->fetchAll();
$auditLogs = $pdo->query('SELECT * FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT 250')->fetchAll();
$settings = get_portal_settings();

$totalReports = count($reports);
$pendingCount = count(array_filter($reports, fn($r) => $r['status'] === 'Pending'));
$inProgressCount = count(array_filter($reports, fn($r) => $r['status'] === 'In Progress'));
$resolvedCount = count(array_filter($reports, fn($r) => $r['status'] === 'Resolved'));
$escalatedCount = count(array_filter($reports, fn($r) => (int)$r['escalated'] === 1 && $r['status'] !== 'Resolved'));
$overdueCount = count(array_filter($reports, fn($r) => get_sla_status($r)['overdue']));

$repStatusFilter = trim((string)($_GET['rep_status'] ?? 'All'));
$repCatFilter = trim((string)($_GET['rep_cat'] ?? 'All'));
$repQuery = trim((string)($_GET['rep_q'] ?? ''));
$filteredReports = array_values(array_filter($reports, function ($r) use ($repStatusFilter, $repCatFilter, $repQuery) {
    if ($repStatusFilter !== 'All' && $r['status'] !== $repStatusFilter) return false;
    if ($repCatFilter !== 'All' && $r['category'] !== $repCatFilter) return false;
    if ($repQuery !== '') {
        $hay = strtolower($r['id'] . ' ' . $r['title'] . ' ' . $r['location'] . ' ' . ($r['assigned_officer'] ?? ''));
        if (!str_contains($hay, strtolower($repQuery))) return false;
    }
    return true;
}));

$noticeLifecycleFilter = trim((string)($_GET['ntc_life'] ?? 'all'));
$noticeCatFilter = trim((string)($_GET['ntc_cat'] ?? 'All'));
$noticeQuery = trim((string)($_GET['ntc_q'] ?? ''));
$ntcStats = ['total' => count($notices), 'active' => 0, 'expired' => 0, 'archived' => 0];
foreach ($notices as $n) {
    $life = get_notice_lifecycle_status($n);
    $ntcStats[$life] = ($ntcStats[$life] ?? 0) + 1;
}
$filteredNotices = array_values(array_filter($notices, function ($n) use ($noticeLifecycleFilter, $noticeCatFilter, $noticeQuery) {
    $life = get_notice_lifecycle_status($n);
    if ($noticeLifecycleFilter !== 'all' && $life !== $noticeLifecycleFilter) return false;
    if ($noticeCatFilter !== 'All' && $n['category'] !== $noticeCatFilter) return false;
    if ($noticeQuery !== '') {
        $hay = strtolower($n['id'] . ' ' . $n['title'] . ' ' . $n['message']);
        if (!str_contains($hay, strtolower($noticeQuery))) return false;
    }
    return true;
}));

$auditCatFilter = trim((string)($_GET['audit_cat'] ?? 'All'));
$auditQuery = trim((string)($_GET['audit_q'] ?? ''));
$filteredAudits = array_values(array_filter($auditLogs, function ($a) use ($auditCatFilter, $auditQuery) {
    if ($auditCatFilter !== 'All' && $a['category'] !== $auditCatFilter) return false;
    if ($auditQuery !== '') {
        $hay = strtolower($a['actor_name'] . ' ' . $a['action'] . ' ' . ($a['target_id'] ?? '') . ' ' . $a['details']);
        if (!str_contains($hay, strtolower($auditQuery))) return false;
    }
    return true;
}));

$pageTitle = 'Admin Control Center - FixMyRoad';
$activePage = 'admin';
require_once __DIR__ . '/includes/header.php';
?>

<div class="portal-layout">
  <!-- Admin Sidebar -->
  <aside class="sidebar">
    <div>
      <div class="sidebar-header">
        <div class="sidebar-role sidebar-role--admin"><?= e($user['role']) ?></div>
        <div class="sidebar-user"><?= e($user['name']) ?></div>
        <div class="sidebar-sub">Kansanga · Makindye Division</div>
      </div>

      <nav class="sidebar-nav">
        <?php
        $navItems = [
            ['dashboard', 'Overview & Analytics', null],
            ['reports', 'Reports Registry', $totalReports],
            ['notices', 'Official Notices', $ntcStats['active']],
            ['maintenance', 'Preventive Schedules', count($maintenanceTasks)],
            ['workload', 'Officer Workload', count($officers)],
            ['staff', 'Staff & Users', count($usersList)],
            ['sla', 'SLA & Escalations', $overdueCount],
            ['support', 'Hotline Settings', null],
            ['audit', 'System Audit Log', count($auditLogs)],
        ];
        foreach ($navItems as [$tabKey, $label, $badge]):
          if (!in_array($tabKey, $validTabs, true)) {
              continue;
          }
        ?>
          <a href="admin_dashboard.php?tab=<?= $tabKey ?>" class="sidebar-link <?= $activeTab === $tabKey ? 'active' : '' ?>">
            <span><?= e($label) ?></span>
            <?php if ($badge !== null): ?>
              <span class="tabular-nums"><?= (int)$badge ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <div class="sidebar-footer">
      <a href="dashboard.php?scope=all" class="btn btn-outline btn-full" style="background:#1e293b; border-color:#334155; color:#ffffff;">View Citizen Feed</a>
      <?php if (in_array($user['role'], ['Administrator', 'Supervisor'], true)): ?>
      <a href="field_officer.php" class="btn btn-outline btn-full" style="background:transparent; border-color:#334155; color:#cbd5e1;">Field Officer View</a>
      <?php endif; ?>
    </div>
  </aside>

  <!-- Main Workspace -->
  <div class="portal-main stack-lg">

    <?php if ($activeTab === 'dashboard'): ?>
      <div class="page-header">
        <div>
          <h1 class="page-title">Kansanga Municipal Operations Overview</h1>
          <p class="page-subtitle">Real-time MySQL dispatch, SLA compliance, and public notices for Kansanga, Makindye Division.</p>
        </div>
        <div class="flex-wrap-gap">
          <a href="admin_dashboard.php?tab=notices" class="btn btn-dark">+ Post Official Notice</a>
          <a href="admin_dashboard.php?tab=sla" class="btn btn-danger">SLA Sweep (<?= $overdueCount ?> Overdue)</a>
        </div>
      </div>

      <div class="grid-5">
        <div class="kpi-card">
          <div class="kpi-label">Total Faults</div>
          <div class="kpi-value tabular-nums"><?= $totalReports ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label text-warning">Pending</div>
          <div class="kpi-value text-warning tabular-nums"><?= $pendingCount ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label text-primary">In Progress</div>
          <div class="kpi-value text-primary tabular-nums"><?= $inProgressCount ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label text-success">Resolved</div>
          <div class="kpi-value text-success tabular-nums"><?= $resolvedCount ?></div>
        </div>
        <div class="kpi-card" style="background:var(--danger-bg); border-color:var(--danger-border);">
          <div class="kpi-label text-danger">Escalated / Overdue</div>
          <div class="kpi-value text-danger tabular-nums"><?= $escalatedCount ?> / <?= $overdueCount ?></div>
        </div>
      </div>

      <div class="grid-2">
        <div class="card stack-md">
          <h2 class="section-title">Fault Breakdown by Category</h2>
          <?php
          $catCounts = [];
          foreach ($reports as $r) {
              $catCounts[$r['category']] = ($catCounts[$r['category']] ?? 0) + 1;
          }
          arsort($catCounts);
          foreach ($catCounts as $catName => $cnt):
              $pct = $totalReports > 0 ? (int)round(($cnt / $totalReports) * 100) : 0;
          ?>
            <div>
              <div class="row-between text-xs font-bold" style="margin-bottom:4px;">
                <span><?= e($catName) ?></span>
                <span class="tabular-nums"><?= $cnt ?> (<?= $pct ?>%)</span>
              </div>
              <div class="progress-track-light">
                <div class="progress-fill-blue" style="width: <?= $pct ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="card stack-md">
          <div class="row-between">
            <h2 class="section-title">Live Official Notices (<?= $ntcStats['active'] ?>)</h2>
            <a href="admin_dashboard.php?tab=notices" class="text-xs font-bold text-primary">Manage &amp; History →</a>
          </div>
          <div class="stack-sm">
            <?php foreach (array_slice(get_live_official_notices(), 0, 3) as $ln): ?>
              <div class="card-muted stack-sm">
                <div class="row-between text-xs font-bold">
                  <span><?= e($ln['title']) ?></span>
                  <span class="text-muted"><?= e(t_notice($ln['category'])) ?></span>
                </div>
                <p class="text-xs text-muted"><?= e($ln['message']) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card stack-md">
          <h2 class="section-title">Kansanga fault density by place</h2>
          <p class="text-xs text-muted">Parish baseline. Places come from the street text until village boundaries are mapped.</p>
          <?php
          $zoneCounts = [];
          $statusCounts = [];
          foreach ($reports as $r) {
              $zone = kansanga_zone((string)$r['location']);
              $zoneCounts[$zone] = ($zoneCounts[$zone] ?? 0) + 1;
              $statusCounts[$r['status']] = ($statusCounts[$r['status']] ?? 0) + 1;
          }
          arsort($zoneCounts);
          foreach ($zoneCounts as $zoneName => $cnt):
              $pct = $totalReports > 0 ? (int)round(($cnt / $totalReports) * 100) : 0;
          ?>
            <div>
              <div class="row-between text-xs font-bold" style="margin-bottom:4px;">
                <span><?= e($zoneName) ?></span>
                <span class="tabular-nums"><?= $cnt ?> (<?= $pct ?>%)</span>
              </div>
              <div class="progress-track-light">
                <div class="progress-fill-blue" style="width: <?= $pct ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
          <p class="text-xs text-muted">
            <?php foreach (['Pending', 'In Progress', 'Resolved', 'Rejected'] as $st): ?>
              <?= e(t_status($st)) ?>: <?= (int)($statusCounts[$st] ?? 0) ?>
            <?php endforeach; ?>
          </p>
        </div>
      </div>

    <?php elseif ($activeTab === 'reports'): ?>
      <div>
        <h1 class="page-title">Reports Registry &amp; Dispatch</h1>
        <p class="page-subtitle">Assign Kansanga engineering crews, update status, escalate SLA risks, or delete resolved records.</p>
      </div>

      <form method="GET" class="filter-bar">
        <input type="hidden" name="tab" value="reports">
        <input name="rep_q" type="text" value="<?= e($repQuery) ?>" placeholder="Search ID, street, crew..." class="form-control">
        <select name="rep_status" class="form-control">
          <option value="All">All Statuses</option>
          <?php foreach (['Pending', 'In Progress', 'Resolved', 'Rejected'] as $st): ?>
            <option value="<?= $st ?>" <?= $repStatusFilter === $st ? 'selected' : '' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
        <select name="rep_cat" class="form-control">
          <option value="All">All Categories</option>
          <?php foreach (['Pothole', 'Blocked Drainage', 'Flooding', 'Damaged Road', 'Broken Streetlight', 'Other'] as $c): ?>
            <option value="<?= $c ?>" <?= $repCatFilter === $c ? 'selected' : '' ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-dark">Filter Registry</button>
      </form>

      <div class="stack-md">
        <form id="bulkForm" method="POST" class="card" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="bulk_update_reports">
          <strong class="text-xs">Bulk action</strong>
          <select name="status" class="form-control" style="width:auto;">
            <option value="">Keep status</option>
            <?php foreach (['Pending', 'In Progress', 'Resolved', 'Rejected'] as $st): ?>
              <option value="<?= $st ?>"><?= $st ?></option>
            <?php endforeach; ?>
          </select>
          <select name="assigned_officer" class="form-control" style="width:auto;">
            <option value="">Keep officer</option>
            <?php foreach ($officers as $off): ?>
              <option value="<?= e($off['name']) ?>"><?= e($off['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-dark btn-sm">Apply to selected</button>
        </form>
        <?php foreach ($filteredReports as $r):
          $sla = get_sla_status($r);
        ?>
          <div class="card stack-md">
            <div class="row-between" style="align-items:flex-start;">
              <div>
                <div class="text-mono text-xs font-bold text-primary">
                  <input form="bulkForm" type="checkbox" name="report_ids[]" value="<?= e($r['id']) ?>" style="margin-right:6px;">
                  <?= e($r['id']) ?> · <?= e($r['category']) ?> · <?= e($r['priority']) ?>
                  <?php if ((int)$r['escalated'] === 1): ?>
                    <span class="text-danger">· ESCALATED</span>
                  <?php endif; ?>
                </div>
                <a href="track_report.php?id=<?= urlencode($r['id']) ?>" class="font-bold" style="font-size:15px; color:var(--text-main);">
                  <?= e($r['title']) ?>
                </a>
                <div class="text-xs text-muted"><?= e($r['location']) ?> · Reporter: <?= e($r['reported_by']) ?></div>
              </div>
              <div style="text-align:right;" class="text-xs">
                <div class="font-bold <?= $sla['overdue'] ? 'text-danger' : 'text-muted' ?>"><?= e($sla['label']) ?></div>
                <div class="text-muted tabular-nums">SLA: <?= e($r['sla_deadline']) ?></div>
              </div>
            </div>

            <form method="POST" class="grid-5" style="padding-top:12px; border-top:1px solid var(--border); align-items:center;">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="update_report">
              <input type="hidden" name="return_tab" value="reports">
              <input type="hidden" name="report_id" value="<?= e($r['id']) ?>">

              <select name="status" class="form-control">
                <?php foreach (['Pending', 'In Progress', 'Resolved', 'Rejected'] as $st): ?>
                  <option value="<?= $st ?>" <?= $r['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
              </select>

              <select name="assigned_officer" class="form-control">
                <option value="">Unassigned</option>
                <?php foreach ($officers as $off): ?>
                  <option value="<?= e($off['name']) ?>" <?= $r['assigned_officer'] === $off['name'] ? 'selected' : '' ?>><?= e($off['name']) ?></option>
                <?php endforeach; ?>
              </select>

              <label class="card-muted text-xs font-bold" style="padding:9px 12px; display:flex; align-items:center; gap:6px; cursor:pointer;">
                <input type="checkbox" name="escalated" value="1" <?= (int)$r['escalated'] === 1 ? 'checked' : '' ?>>
                <span>Escalated</span>
              </label>

              <input name="escalation_reason" type="text" value="<?= e($r['escalation_reason'] ?? '') ?>" placeholder="Escalation note" class="form-control">

              <button type="submit" class="btn btn-primary btn-full">Save Dispatch</button>
            </form>

            <?php if ($r['status'] === 'Resolved'): ?>
              <form method="POST" style="text-align:right;" onsubmit="return confirm('Delete resolved report <?= e($r['id']) ?> permanently?');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_report">
                <input type="hidden" name="return_tab" value="reports">
                <input type="hidden" name="report_id" value="<?= e($r['id']) ?>">
                <button type="submit" class="btn btn-danger-outline btn-sm">Delete Resolved Record</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

    <?php elseif ($activeTab === 'notices'): ?>
      <div>
        <h1 class="page-title">Official Municipal Notices &amp; History</h1>
        <p class="page-subtitle">Broadcast classified advisories to the global top banner and citizen feed, manage expiry dates, and archive past notices.</p>
      </div>

      <div class="split-3-2" style="align-items: start;">
        <form method="POST" class="card stack-md">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="create_notice">
          <h2 class="section-title">Publish New Official Notice</h2>

          <div class="grid-2">
            <div>
              <label class="form-label">Notice Title *</label>
              <input id="ntcTitleInput" name="title" type="text" required maxlength="140"
                     placeholder="e.g. Ggaba Road Culvert Desilting" class="form-control">
            </div>
            <div>
              <label class="form-label">Category *</label>
              <select id="ntcCatInput" name="category" class="form-control">
                <option value="General Announcement">General Announcement</option>
                <option value="Maintenance">Maintenance</option>
                <option value="Emergency">Emergency</option>
              </select>
            </div>
          </div>

          <div>
            <label class="form-label">Advisory Message *</label>
            <textarea id="ntcMsgInput" name="message" rows="3" required
                      placeholder="Write the official municipal advisory for Kansanga residents..." class="form-control"></textarea>
          </div>

          <div class="stack-sm">
            <label class="form-label" style="margin:0;">Expiry Date (Optional — hides notice automatically once passed)</label>
            <div class="flex-wrap-gap">
              <input id="ntcExpiryInput" name="expires_at" type="date" class="form-control" style="width:auto;">
              <button type="button" onclick="setNoticeExpiryPreset(3)" class="btn btn-outline btn-sm">+3 Days</button>
              <button type="button" onclick="setNoticeExpiryPreset(7)" class="btn btn-outline btn-sm">+7 Days</button>
              <button type="button" onclick="setNoticeExpiryPreset(30)" class="btn btn-outline btn-sm">+30 Days</button>
              <button type="button" onclick="setNoticeExpiryPreset(0)" class="btn btn-outline btn-sm">Ongoing</button>
            </div>
          </div>

          <div><button type="submit" class="btn btn-primary">Publish Official Notice</button></div>
        </form>

        <div class="card stack-sm">
          <div class="section-title" style="font-size:14px;">Live Banner Preview</div>
          <div id="ntcPreviewBox" class="notice-banner notice-banner--general" style="border-radius:12px;">
            <span id="ntcPreviewTitle" class="notice-title">NOTICE TITLE:</span>
            <span id="ntcPreviewMsg">Your advisory message will appear in the top municipal banner and citizen notices feed.</span>
          </div>
          <div class="text-xs text-muted">
            Category colors:
            <strong class="text-danger">Emergency</strong> ·
            <strong class="text-warning">Maintenance</strong> ·
            <strong class="text-primary">General Announcement</strong>.
          </div>
        </div>
      </div>

      <!-- Notice History Stats -->
      <div class="grid-4">
        <a href="admin_dashboard.php?tab=notices&ntc_life=all" class="kpi-card <?= $noticeLifecycleFilter === 'all' ? 'active' : '' ?>">
          <div class="kpi-label">Total Notices</div>
          <div class="kpi-value tabular-nums"><?= $ntcStats['total'] ?></div>
        </a>
        <a href="admin_dashboard.php?tab=notices&ntc_life=active" class="kpi-card <?= $noticeLifecycleFilter === 'active' ? 'active' : '' ?>">
          <div class="kpi-label text-success">Live Now</div>
          <div class="kpi-value text-success tabular-nums"><?= $ntcStats['active'] ?></div>
        </a>
        <a href="admin_dashboard.php?tab=notices&ntc_life=expired" class="kpi-card <?= $noticeLifecycleFilter === 'expired' ? 'active' : '' ?>">
          <div class="kpi-label text-warning">Expired (Hidden)</div>
          <div class="kpi-value text-warning tabular-nums"><?= $ntcStats['expired'] ?></div>
        </a>
        <a href="admin_dashboard.php?tab=notices&ntc_life=archived" class="kpi-card <?= $noticeLifecycleFilter === 'archived' ? 'active' : '' ?>">
          <div class="kpi-label">Archived</div>
          <div class="kpi-value text-muted tabular-nums"><?= $ntcStats['archived'] ?></div>
        </a>
      </div>

      <form method="GET" class="filter-bar">
        <input type="hidden" name="tab" value="notices">
        <input name="ntc_q" type="text" value="<?= e($noticeQuery) ?>" placeholder="Search notice ID, title, text..." class="form-control">
        <select name="ntc_life" class="form-control">
          <option value="all" <?= $noticeLifecycleFilter === 'all' ? 'selected' : '' ?>>All Lifecycle States</option>
          <option value="active" <?= $noticeLifecycleFilter === 'active' ? 'selected' : '' ?>>Live Only</option>
          <option value="expired" <?= $noticeLifecycleFilter === 'expired' ? 'selected' : '' ?>>Expired Only</option>
          <option value="archived" <?= $noticeLifecycleFilter === 'archived' ? 'selected' : '' ?>>Archived Only</option>
        </select>
        <select name="ntc_cat" class="form-control">
          <option value="All">All Categories</option>
          <?php foreach (['Emergency', 'Maintenance', 'General Announcement'] as $nc): ?>
            <option value="<?= $nc ?>" <?= $noticeCatFilter === $nc ? 'selected' : '' ?>><?= $nc ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-dark">Filter History</button>
      </form>

      <div class="stack-sm">
        <?php foreach ($filteredNotices as $n):
          $life = get_notice_lifecycle_status($n);
          $lifeBadge = match ($life) {
              'active' => 'badge-resolved',
              'expired' => 'badge-pending',
              default => 'badge-neutral',
          };
        ?>
          <div class="card stack-sm">
            <div class="row-between" style="align-items:flex-start;">
              <div>
                <div class="text-mono text-xs text-muted">
                  <?= e($n['id']) ?> · <?= e($n['category']) ?> · Posted by <?= e($n['posted_by']) ?> (<?= e($n['created_at']) ?>)
                </div>
                <h3 style="font-size:15px; font-weight:800; color:var(--text-main); margin-top:3px;"><?= e($n['title']) ?></h3>
                <p class="text-xs text-muted" style="margin-top:4px;"><?= e($n['message']) ?></p>
              </div>
              <span class="badge <?= $lifeBadge ?>" style="text-transform:uppercase;"><?= e($life) ?></span>
            </div>

            <div class="row-between" style="padding-top:10px; border-top:1px solid var(--border);">
              <form method="POST" class="flex-wrap-gap">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_notice_expiry">
                <input type="hidden" name="notice_id" value="<?= e($n['id']) ?>">
                <label class="text-xs font-bold text-muted">Expiry:</label>
                <input type="date" name="expires_at" value="<?= e($n['expires_at'] ?? '') ?>" class="form-control" style="width:auto; padding:5px 9px;">
                <button type="submit" class="btn btn-dark btn-sm">Update Date</button>
              </form>

              <div class="flex-wrap-gap">
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle_notice_archive">
                  <input type="hidden" name="notice_id" value="<?= e($n['id']) ?>">
                  <input type="hidden" name="target_state" value="<?= $n['status'] === 'archived' ? 'active' : 'archived' ?>">
                  <button type="submit" class="btn btn-outline btn-sm">
                    <?= $n['status'] === 'archived' ? 'Restore Active' : 'Archive' ?>
                  </button>
                </form>
                <form method="POST" onsubmit="return confirm('Permanently delete notice <?= e($n['id']) ?>?');">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete_notice">
                  <input type="hidden" name="notice_id" value="<?= e($n['id']) ?>">
                  <button type="submit" class="btn btn-danger-outline btn-sm">Delete</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php elseif ($activeTab === 'maintenance'): ?>
      <div>
        <h1 class="page-title">Preventive Maintenance Schedules</h1>
        <p class="page-subtitle">Schedule recurring culvert desilting, drainage flushing, and shoulder grading across Kansanga corridors.</p>
      </div>

      <form method="POST" class="card stack-md">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_maintenance">
        <h2 class="section-title">Create Preventive Task</h2>
        <div class="grid-3">
          <input name="title" type="text" required placeholder="Task Title (e.g., Kiwafu Culvert Clearing)" class="form-control">
          <input name="corridor" type="text" required placeholder="Corridor (e.g., Ggaba Rd - KIU Gate)" class="form-control">
          <select name="division" class="form-control">
            <option>Kansanga (Makindye)</option>
            <option>Kiwafu / Kansanga</option>
            <option>Nabutiti / Kansanga</option>
            <option>KIU / Kansanga Junction</option>
          </select>
          <select name="task_type" class="form-control">
            <option>Culvert Clearing</option>
            <option>Drainage Flushing</option>
            <option>Road Shoulder Grading</option>
            <option>Asphalt Crack Sealing</option>
          </select>
          <select name="frequency" class="form-control">
            <option>Weekly</option>
            <option>Bi-Weekly</option>
            <option selected>Monthly</option>
            <option>Quarterly</option>
          </select>
          <select name="assigned_officer" class="form-control">
            <?php foreach ($officers as $off): ?>
              <option value="<?= e($off['name']) ?>"><?= e($off['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input name="next_due_date" type="date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" class="form-control">
          <input name="estimated_hours" type="number" step="0.5" min="1" value="4" class="form-control" placeholder="Est. Hours">
          <input name="notes" type="text" placeholder="Engineering checklist notes..." class="form-control">
        </div>
        <div><button type="submit" class="btn btn-primary">Schedule Preventive Cycle</button></div>
      </form>

      <div class="grid-2">
        <?php foreach ($maintenanceTasks as $pm): ?>
          <div class="card stack-sm">
            <div class="row-between" style="align-items:flex-start;">
              <div>
                <div class="text-mono text-xs text-muted"><?= e($pm['id']) ?> · <?= e($pm['frequency']) ?> · <?= e($pm['task_type']) ?></div>
                <div class="font-bold" style="font-size:15px; color:var(--text-main);"><?= e($pm['title']) ?></div>
                <div class="text-xs text-muted"><?= e($pm['corridor']) ?> · Crew: <?= e($pm['assigned_officer']) ?></div>
              </div>
              <span class="badge badge-neutral"><?= e($pm['status']) ?></span>
            </div>
            <div class="text-xs text-muted tabular-nums">Next Due: <?= e($pm['next_due_date']) ?> · Total Silt Cleared: <?= (int)$pm['silt_cleared_kg'] ?> kg</div>
            <form method="POST" class="flex-wrap-gap" style="padding-top:10px; border-top:1px solid var(--border);">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="update_maintenance_status">
              <input type="hidden" name="task_id" value="<?= e($pm['id']) ?>">
              <select name="status" class="form-control" style="width:auto;">
                <?php foreach (['Scheduled', 'Due Soon', 'Overdue', 'In Progress', 'Completed', 'Paused'] as $pst): ?>
                  <option value="<?= $pst ?>" <?= $pm['status'] === $pst ? 'selected' : '' ?>><?= $pst ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-dark btn-sm">Update</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>

    <?php elseif ($activeTab === 'workload'): ?>
      <div>
        <h1 class="page-title">Kansanga Officer &amp; Crew Workload</h1>
        <p class="page-subtitle">Monitor active ticket allocations against field crew capacity before dispatching new faults.</p>
      </div>

      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Officer / Crew</th>
              <th>Zone &amp; Specialty</th>
              <th>Active Load</th>
              <th>Capacity Utilization</th>
              <th>Avg Resolution</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($officers as $off):
              $activeLoad = count(array_filter($reports, fn($r) => $r['assigned_officer'] === $off['name'] && in_array($r['status'], ['Pending', 'In Progress'], true)));
              $cap = max(1, (int)$off['max_capacity']);
              $pct = min(100, (int)round(($activeLoad / $cap) * 100));
            ?>
              <tr>
                <td class="font-bold"><?= e($off['name']) ?></td>
                <td class="text-muted"><?= e($off['division']) ?> · <?= e($off['specialty']) ?></td>
                <td class="font-bold tabular-nums"><?= $activeLoad ?> / <?= $cap ?></td>
                <td>
                  <div class="progress-track-light" style="width:160px;">
                    <div class="<?= $pct >= 80 ? 'progress-fill-danger' : 'progress-fill-blue' ?>" style="width: <?= $pct ?>%"></div>
                  </div>
                </td>
                <td class="tabular-nums text-muted"><?= (int)$off['avg_resolution_hours'] ?>h avg</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($activeTab === 'staff'): ?>
      <div>
        <h1 class="page-title">Staff Provisioning and Role Management</h1>
        <p class="page-subtitle">Six roles: Community Member, Local Council, Field Officer, Supervisor, Page Moderator, and Administrator. Only an administrator can assign or remove a role.</p>
      </div>

      <form method="POST" class="card stack-md">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_staff">
        <h2 class="section-title">Provision Staff Account</h2>
        <div class="grid-3">
          <input name="name" type="text" required placeholder="Full Name" class="form-control">
          <input name="email" type="email" required placeholder="officer@roads.gov.ug" class="form-control">
          <input name="phone" type="text" placeholder="+256 7XX XXX XXX" class="form-control">
          <select name="role" class="form-control">
            <?php foreach (SYSTEM_ROLES as $roleOpt): ?>
              <?php if ($roleOpt === 'Administrator') continue; ?>
              <option value="<?= e($roleOpt) ?>"><?= e($roleOpt) ?></option>
            <?php endforeach; ?>
            <option value="Administrator">Administrator</option>
          </select>
          <select name="division" class="form-control">
            <option>Kansanga (Makindye)</option>
            <option>Kiwafu / Kansanga</option>
            <option>Nabutiti / Kansanga</option>
            <option>KIU / Kansanga Junction</option>
          </select>
          <input name="specialty" type="text" value="Road & Drainage Maintenance" placeholder="Specialty" class="form-control">
          <input name="password" type="password" required minlength="6" placeholder="Initial password (min 6 chars)" class="form-control" style="grid-column: span 2;">
          <button type="submit" class="btn btn-primary">Create Account</button>
        </div>
      </form>

      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>User</th>
              <th>Role &amp; Zone</th>
              <th>Status</th>
              <th>Change Role</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($usersList as $u):
              $isProtected = $u['id'] === 'usr-3' || strtolower($u['email']) === 'admin@roads.gov.ug' || $u['id'] === $user['id'];
            ?>
              <tr>
                <td>
                  <div class="font-bold"><?= e($u['name']) ?></div>
                  <div class="text-xs text-muted"><?= e($u['email']) ?></div>
                </td>
                <td><?= e($u['role']) ?> · <?= e($u['division']) ?></td>
                <td class="font-bold <?= $u['account_status'] === 'active' ? 'text-success' : 'text-danger' ?>">
                  <?= strtoupper(e($u['account_status'])) ?>
                </td>
                <td>
                  <?php if ($isProtected): ?>
                    <span class="text-xs text-muted">Protected</span>
                  <?php else: ?>
                    <form method="POST" style="display:flex; gap:6px; align-items:center;">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="change_user_role">
                      <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                      <select name="new_role" class="form-control" style="width:auto; padding:4px 6px; font-size:12px;">
                        <?php foreach (SYSTEM_ROLES as $roleOpt): ?>
                          <option value="<?= e($roleOpt) ?>" <?= $u['role'] === $roleOpt ? 'selected' : '' ?>><?= e($roleOpt) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="btn btn-dark btn-sm">Assign</button>
                    </form>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <?php if ($isProtected): ?>
                    <span class="text-xs text-muted">Protected Admin</span>
                  <?php else: ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="toggle_user_status">
                      <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                      <button type="submit" class="btn btn-outline btn-sm">
                        <?= $u['account_status'] === 'active' ? 'Block' : 'Activate' ?>
                      </button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete user <?= e($u['name']) ?>?');">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete_user">
                      <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                      <button type="submit" class="btn btn-danger-outline btn-sm">Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($activeTab === 'sla'): ?>
      <div>
        <h1 class="page-title">SLA Policy &amp; Auto-Escalation Engine</h1>
        <p class="page-subtitle">Configure target response days by priority and sweep overdue reports for immediate escalation.</p>
      </div>

      <div class="grid-2">
        <form method="POST" class="card stack-md">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_sla_settings">
          <h2 class="section-title">Response Deadlines (Days)</h2>
          <div class="grid-2">
            <div>
              <label class="form-label text-danger">Urgent (Days)</label>
              <input name="sla_urgent_days" type="number" min="1" value="<?= e($settings['sla_urgent_days']) ?>" class="form-control">
            </div>
            <div>
              <label class="form-label text-warning">High (Days)</label>
              <input name="sla_high_days" type="number" min="1" value="<?= e($settings['sla_high_days']) ?>" class="form-control">
            </div>
            <div>
              <label class="form-label text-primary">Medium (Days)</label>
              <input name="sla_medium_days" type="number" min="1" value="<?= e($settings['sla_medium_days']) ?>" class="form-control">
            </div>
            <div>
              <label class="form-label">Low (Days)</label>
              <input name="sla_low_days" type="number" min="1" value="<?= e($settings['sla_low_days']) ?>" class="form-control">
            </div>
          </div>
          <div><button type="submit" class="btn btn-dark">Save SLA Thresholds</button></div>
        </form>

        <div class="card stack-md" style="border-color:var(--danger-border);">
          <h2 class="section-title">Run Auto-Escalation Sweep</h2>
          <p class="text-xs text-muted">Scans all Pending / In Progress tickets whose SLA deadline has passed and flags them for priority crew dispatch.</p>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="run_sla_sweep">
            <button type="submit" class="btn btn-danger">Execute SLA Escalation Sweep Now</button>
          </form>
        </div>
      </div>

    <?php elseif ($activeTab === 'support'): ?>
      <div>
        <h1 class="page-title">Hotline &amp; Dispatch Contact Settings</h1>
        <p class="page-subtitle">Values saved here update the global FixMyRoad footer and support channels immediately.</p>
      </div>

      <form method="POST" class="card" style="max-width:520px;">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_support_settings">
        <div class="form-group">
          <label class="form-label">Toll-Free Emergency Line</label>
          <input name="support_toll_free" type="text" required value="<?= e($settings['support_toll_free']) ?>" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Division Direct Line</label>
          <input name="support_city_line" type="text" required value="<?= e($settings['support_city_line']) ?>" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Dispatch Email</label>
          <input name="support_dispatch_email" type="email" required value="<?= e($settings['support_dispatch_email']) ?>" class="form-control">
        </div>
        <label class="text-xs font-bold" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
          <input type="checkbox" name="notify_email" value="1" <?= ($settings['notify_email'] ?? '0') === '1' ? 'checked' : '' ?>>
          Queue an email copy of each in-app notification (demo outbox, not live SMTP)
        </label>
        <label class="text-xs font-bold" style="display:flex; gap:8px; align-items:center; margin-bottom:12px;">
          <input type="checkbox" name="notify_sms" value="1" <?= ($settings['notify_sms'] ?? '0') === '1' ? 'checked' : '' ?>>
          Queue an SMS copy to the division line (demo outbox, not a live gateway)
        </label>
        <label class="text-xs font-bold" style="display:flex; gap:8px; align-items:center; margin-bottom:12px;">
          <input type="checkbox" name="luganda_enabled" value="1" <?= ($settings['luganda_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
          Show the Luganda language switch to residents (off by default)
        </label>
        <button type="submit" class="btn btn-primary">Save Support Channels</button>
      </form>

    <?php elseif ($activeTab === 'audit'): ?>
      <div class="page-header">
        <div>
          <h1 class="page-title">System Audit Trail</h1>
          <p class="page-subtitle">Immutable administrative and operational log recorded in MySQL.</p>
        </div>
        <a href="admin_dashboard.php?export=audit_csv&audit_cat=<?= urlencode($auditCatFilter) ?>&audit_q=<?= urlencode($auditQuery) ?>" class="btn btn-success">
          Export Audit CSV
        </a>
      </div>

      <form method="GET" class="filter-bar" style="grid-template-columns: 2fr 1fr 1fr;">
        <input type="hidden" name="tab" value="audit">
        <input name="audit_q" type="text" value="<?= e($auditQuery) ?>" placeholder="Search actor, action, target ID..." class="form-control">
        <select name="audit_cat" class="form-control">
          <option value="All">All Categories</option>
          <?php foreach (['report', 'assignment', 'status', 'escalation', 'feedback', 'auth', 'maintenance', 'notice'] as $ac): ?>
            <option value="<?= $ac ?>" <?= $auditCatFilter === $ac ? 'selected' : '' ?>><?= ucfirst($ac) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-dark">Filter Audit Log</button>
      </form>

      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Actor</th>
              <th>Category</th>
              <th>Action</th>
              <th>Target</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($filteredAudits as $log): ?>
              <tr>
                <td class="text-mono text-xs text-muted"><?= e($log['created_at']) ?></td>
                <td class="font-bold"><?= e($log['actor_name']) ?></td>
                <td class="text-xs font-bold" style="text-transform:uppercase;"><?= e($log['category']) ?></td>
                <td class="font-bold"><?= e($log['action']) ?></td>
                <td class="text-mono text-primary"><?= e($log['target_id'] ?: '—') ?></td>
                <td class="text-muted"><?= e($log['details']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
