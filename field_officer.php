<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_role(['Field Officer', 'Supervisor', 'Administrator']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $nowLabel = date('Y-m-d H:i');

    if ($action === 'update_work_order') {
        $reportId = trim((string)($_POST['report_id'] ?? ''));
        $nextStatus = trim((string)($_POST['status'] ?? 'In Progress'));
        $note = trim((string)($_POST['progress_note'] ?? 'Field crew updated repair progress.'));
        $afterPhoto = handle_photo_upload('after_photo');

        $valid = ['Pending', 'In Progress', 'Resolved'];
        if (!in_array($nextStatus, $valid, true)) $nextStatus = 'In Progress';

        if ($nextStatus === 'Resolved') {
            $sql = "UPDATE reports SET status = 'Resolved', resolved_at = NOW(), confirmation_state = 'awaiting_confirmation', confirmation_expires_at = DATE_ADD(NOW(), INTERVAL 48 HOUR)"
                 . ($afterPhoto ? ', after_photo = ?' : '')
                 . ' WHERE id = ?';
            $params = $afterPhoto ? [$afterPhoto, $reportId] : [$reportId];
            $pdo->prepare($sql)->execute($params);
            create_notification("Repair Resolved ($reportId)", "{$user['name']} marked $reportId Resolved. Citizen 48h confirmation open.", 'resolved', 'Community Member', $reportId);
        } else {
            $sql = 'UPDATE reports SET status = ?' . ($afterPhoto ? ', after_photo = ?' : '') . ' WHERE id = ?';
            $params = $afterPhoto ? [$nextStatus, $afterPhoto, $reportId] : [$nextStatus, $reportId];
            $pdo->prepare($sql)->execute($params);
        }

        $pdo->prepare('INSERT INTO report_updates (report_id, timestamp_label, text, author, update_type, photo_url) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$reportId, $nowLabel, $note, $user['name'] . ' (Field Officer)', $nextStatus === 'Resolved' ? 'resolved' : 'work_progress', $afterPhoto]);

        log_audit($user['name'], $user['role'], 'Updated Field Work Order', $reportId, "Status set to $nextStatus. $note", 'status');
        flash_set('success', "Work order $reportId updated to $nextStatus.");
        redirect_to('field_officer.php');
    }

    if ($action === 'complete_maintenance') {
        $taskId = trim((string)($_POST['task_id'] ?? ''));
        $siltKg = max(0, (int)($_POST['silt_cleared_kg'] ?? 0));
        $notes = trim((string)($_POST['notes'] ?? 'Completed scheduled preventive drainage inspection.'));

        $pdo->prepare("UPDATE maintenance_tasks SET status = 'Completed', last_completed_date = CURDATE(), next_due_date = DATE_ADD(CURDATE(), INTERVAL 30 DAY), silt_cleared_kg = silt_cleared_kg + ?, notes = ? WHERE id = ?")
            ->execute([$siltKg, $notes, $taskId]);
        log_audit($user['name'], $user['role'], 'Completed Preventive Inspection', $taskId, "$notes (Cleared {$siltKg}kg silt).", 'maintenance');
        flash_set('success', "Preventive maintenance cycle $taskId logged ($siltKg kg cleared).");
        redirect_to('field_officer.php');
    }
}

$workOrders = $pdo->query("SELECT * FROM reports WHERE status IN ('Pending', 'In Progress') ORDER BY FIELD(priority, 'Urgent', 'High', 'Medium', 'Low'), created_at DESC")->fetchAll();
$maintenance = $pdo->query('SELECT * FROM maintenance_tasks ORDER BY next_due_date ASC')->fetchAll();

$pageTitle = 'Field Officer Work Orders - FixMyRoad';
$activePage = 'officer';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container stack-lg">
  <div class="page-header">
    <div>
      <div class="page-eyebrow" style="color:var(--success);">Field Engineering Dispatch · Kansanga</div>
      <h1 class="page-title">Field Officer Work Orders</h1>
    </div>
    <a href="map_alerts.php" class="btn btn-dark">Open Kansanga Fault Map →</a>
  </div>

  <!-- Active Field Work Orders -->
  <section class="stack-md">
    <h2 class="section-title">Active Road &amp; Drainage Repairs (<?= count($workOrders) ?>)</h2>
    <div class="grid-2">
      <?php foreach ($workOrders as $r):
        $sla = get_sla_status($r);
      ?>
        <div class="card stack-md">
          <div class="row-between" style="align-items:flex-start;">
            <div>
              <div class="text-mono text-xs font-bold text-primary"><?= e($r['id']) ?> · <?= e($r['category']) ?> · <?= e($r['priority']) ?></div>
              <h3 style="font-size:15px; font-weight:800; color:var(--text-main); margin-top:3px;"><?= e($r['title']) ?></h3>
              <div class="text-xs text-muted"><?= e($r['location']) ?> · Crew: <?= e($r['assigned_officer'] ?: 'Unassigned') ?></div>
            </div>
            <span class="badge <?= $sla['overdue'] ? 'badge-danger' : 'badge-neutral' ?>"><?= e($sla['label']) ?></span>
          </div>

          <form method="POST" enctype="multipart/form-data" class="stack-sm" style="padding-top:12px; border-top:1px solid var(--border);">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_work_order">
            <input type="hidden" name="report_id" value="<?= e($r['id']) ?>">

            <div class="grid-2">
              <div>
                <label class="form-label">Update Status</label>
                <select name="status" class="form-control">
                  <option value="Pending" <?= $r['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                  <option value="In Progress" <?= $r['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                  <option value="Resolved">Resolved (Opens 48h Citizen Check)</option>
                </select>
              </div>
              <div>
                <label class="form-label">Proof Photo (Optional)</label>
                <input name="after_photo" type="file" accept="image/jpeg,image/png,image/webp" class="form-control">
              </div>
            </div>

            <input name="progress_note" type="text" required placeholder="Log field progress note (e.g., compacted hot-mix asphalt)..." class="form-control">

            <div class="row-between">
              <a href="track_report.php?id=<?= urlencode($r['id']) ?>" class="text-xs font-bold text-primary">Full Ticket &amp; Q&amp;A →</a>
              <button type="submit" class="btn btn-primary">Save Field Update</button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Preventive Maintenance Schedules -->
  <section class="card stack-md">
    <h2 class="section-title">Preventive Drainage &amp; Road Maintenance Cycles</h2>
    <div class="grid-3">
      <?php foreach ($maintenance as $pm): ?>
        <div class="card-muted stack-sm">
          <div>
            <div class="text-mono text-xs font-bold text-muted"><?= e($pm['id']) ?> · <?= e($pm['frequency']) ?> · Due <?= e($pm['next_due_date']) ?></div>
            <h3 style="font-size:14px; font-weight:800; color:var(--text-main); margin-top:4px;"><?= e($pm['title']) ?></h3>
            <p class="text-xs text-muted"><?= e($pm['corridor']) ?> · Assigned: <?= e($pm['assigned_officer']) ?></p>
          </div>
          <form method="POST" class="stack-sm" style="padding-top:10px; border-top:1px solid var(--border);">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="complete_maintenance">
            <input type="hidden" name="task_id" value="<?= e($pm['id']) ?>">
            <input name="silt_cleared_kg" type="number" min="0" placeholder="Silt cleared (kg)" class="form-control">
            <input name="notes" type="text" required placeholder="Inspection notes..." class="form-control">
            <button type="submit" class="btn btn-success btn-full">Complete Inspection Cycle</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
