<?php
require_once __DIR__ . '/includes/auth.php';
$user = current_user();
$pdo = db();

$reportId = trim((string)($_GET['id'] ?? ''));
if ($reportId === '') {
    $first = $pdo->query('SELECT id FROM reports ORDER BY created_at DESC LIMIT 1')->fetchColumn();
    $reportId = $first ?: '#RPT-00123';
}

$stmt = $pdo->prepare('SELECT *, (confirmation_expires_at > NOW()) AS confirmation_window_open FROM reports WHERE id = ? LIMIT 1');
$stmt->execute([$reportId]);
$report = $stmt->fetch();

if (!$report) {
    flash_set('error', "Fault report $reportId was not found.");
    redirect_to('public_data.php');
}

$isOwner = $user && strtolower(trim($user['email'])) === strtolower(trim($report['reported_by_email']));
$confirmationOpen = $report['status'] === 'Resolved'
    && $report['confirmation_state'] === 'awaiting_confirmation'
    && (bool)$report['confirmation_window_open'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $actor = require_login();
    $action = $_POST['action'] ?? '';
    $nowLabel = date('Y-m-d H:i');

    if (in_array($action, ['confirm_fix', 'reopen_fix'], true) && !$isOwner) {
        flash_set('error', 'Only the report owner can confirm or reopen this repair.');
        redirect_to('track_report.php?id=' . urlencode($reportId));
    }

    if (in_array($action, ['confirm_fix', 'reopen_fix'], true) && !$confirmationOpen) {
        flash_set('error', 'The 48-hour verification window for this repair has closed.');
        redirect_to('track_report.php?id=' . urlencode($reportId));
    }

    if ($action === 'submit_feedback' && (!$isOwner || $report['status'] !== 'Resolved')) {
        flash_set('error', 'Only the report owner can rate a resolved repair.');
        redirect_to('track_report.php?id=' . urlencode($reportId));
    }

    if ($action === 'confirm_fix') {
        $note = trim((string)($_POST['confirm_note'] ?? 'Citizen verified that repair holds cleanly.'));
        $update = $pdo->prepare(
            "UPDATE reports SET confirmation_state = 'confirmed', confirmed_by = ?
             WHERE id = ? AND LOWER(reported_by_email) = ? AND status = 'Resolved'
               AND confirmation_state = 'awaiting_confirmation' AND confirmation_expires_at > NOW()"
        );
        $update->execute([$actor['name'], $reportId, strtolower(trim($actor['email']))]);
        if ($update->rowCount() !== 1) {
            flash_set('error', 'This repair is no longer awaiting confirmation.');
            redirect_to('track_report.php?id=' . urlencode($reportId));
        }
        $pdo->prepare("INSERT INTO report_updates (report_id, timestamp_label, text, author, update_type) VALUES (?, ?, ?, ?, 'resolved')")
            ->execute([$reportId, $nowLabel, "Citizen Confirmation: $note", $actor['name']]);
        log_audit($actor['name'], $actor['role'], 'Confirmed Repair Fix', $reportId, $note, 'feedback');
        flash_set('success', 'Thank you! Repair verified and ticket permanently closed.');
        redirect_to('track_report.php?id=' . urlencode($reportId));
    }

    if ($action === 'reopen_fix') {
        $reason = trim((string)($_POST['reopen_reason'] ?? ''));
        if (strlen($reason) < 5) {
            flash_set('error', 'Please explain briefly why the repair needs follow-up.');
        } else {
            $update = $pdo->prepare(
                "UPDATE reports SET status = 'In Progress', confirmation_state = 'reopened', reopen_reason = ?
                 WHERE id = ? AND LOWER(reported_by_email) = ? AND status = 'Resolved'
                   AND confirmation_state = 'awaiting_confirmation' AND confirmation_expires_at > NOW()"
            );
            $update->execute([$reason, $reportId, strtolower(trim($actor['email']))]);
            if ($update->rowCount() !== 1) {
                flash_set('error', 'This repair is no longer awaiting confirmation.');
                redirect_to('track_report.php?id=' . urlencode($reportId));
            }
            $pdo->prepare("INSERT INTO report_updates (report_id, timestamp_label, text, author, update_type) VALUES (?, ?, ?, ?, 'work_progress')")
                ->execute([$reportId, $nowLabel, "Reopened by Citizen during 48h check: $reason", $actor['name']]);
            create_notification("Ticket Reopened ($reportId)", "$reason — reopened by {$actor['name']}.", 'escalation', 'Field Officer', $reportId);
            log_audit($actor['name'], $actor['role'], 'Reopened Resolved Ticket', $reportId, $reason, 'status');
            flash_set('success', "Ticket $reportId reopened for follow-up repair.");
        }
        redirect_to('track_report.php?id=' . urlencode($reportId));
    }

    if ($action === 'add_comment') {
        $text = trim((string)($_POST['comment_text'] ?? ''));
        $isOfficial = ($actor['role'] !== 'Community Member' && !empty($_POST['is_official_question'])) ? 1 : 0;
        if (strlen($text) >= 2) {
            $pdo->prepare('INSERT INTO report_comments (report_id, author_id, author_name, author_role, text, is_official_question) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$reportId, $actor['id'], $actor['name'], $actor['role'], $text, $isOfficial]);
            flash_set('success', 'Comment posted.');
        }
        redirect_to('track_report.php?id=' . urlencode($reportId));
    }

    if ($action === 'hide_comment' && in_array($actor['role'], ['Administrator', 'Field Officer'], true)) {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $pdo->prepare('UPDATE report_comments SET hidden = 1 WHERE id = ? AND report_id = ?')->execute([$commentId, $reportId]);
        log_audit($actor['name'], $actor['role'], 'Hid Report Comment', $reportId, "Comment #$commentId hidden.", 'report');
        flash_set('success', 'Comment hidden from the public thread.');
        redirect_to('track_report.php?id=' . urlencode($reportId));
    }

    if ($action === 'submit_feedback' && $report['status'] === 'Resolved') {
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $comment = trim((string)($_POST['feedback_comment'] ?? ''));
        $pdo->prepare(
            "UPDATE reports SET feedback_rating = ?, feedback_comment = ?, feedback_author = ?
             WHERE id = ? AND LOWER(reported_by_email) = ? AND status = 'Resolved'"
        )->execute([$rating, $comment, $actor['name'], $reportId, strtolower(trim($actor['email']))]);
        log_audit($actor['name'], $actor['role'], 'Submitted Repair Feedback', $reportId, "Rated $rating/5 stars.", 'feedback');
        flash_set('success', 'Feedback submitted! (+15 Hero Points)');
        redirect_to('track_report.php?id=' . urlencode($reportId));
    }
}

$updatesStmt = $pdo->prepare('SELECT * FROM report_updates WHERE report_id = ? ORDER BY id DESC');
$updatesStmt->execute([$reportId]);
$updates = $updatesStmt->fetchAll();

$isModerator = $user && in_array($user['role'], ['Administrator', 'Field Officer'], true);
$commentsStmt = $pdo->prepare('SELECT * FROM report_comments WHERE report_id = ? AND (hidden = 0 OR ? = 1) ORDER BY id ASC');
$commentsStmt->execute([$reportId, $isModerator ? 1 : 0]);
$comments = $commentsStmt->fetchAll();

$sla = get_sla_status($report);
$statusBadge = $report['status'] === 'Resolved' ? 'badge-resolved' : ($report['status'] === 'In Progress' ? 'badge-progress' : 'badge-pending');

$pageTitle = $report['id'] . ' - Track Fault - FixMyRoad';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container stack-lg">
  <div class="page-header">
    <div>
      <div class="text-mono text-xs font-bold text-primary"><?= e($report['id']) ?> · <?= e($report['category']) ?></div>
      <h1 class="page-title"><?= e($report['title']) ?></h1>
      <div class="page-subtitle tabular-nums">
        <?= e($report['location']) ?> · Reported by <?= e($report['reported_by']) ?> on <?= e($report['report_date']) ?>
      </div>
    </div>
    <div class="flex-wrap-gap">
      <span class="badge <?= $statusBadge ?>"><?= e($report['status']) ?></span>
      <a href="<?= $user ? 'dashboard.php' : 'index.php' ?>" class="btn btn-outline">← Back</a>
    </div>
  </div>

  <!-- Citizen 48h Confirm Fix Banner -->
  <?php if ($isOwner && $confirmationOpen): ?>
    <div class="alert alert-warning stack-md">
      <div>
        <h2 class="section-title">48-Hour Citizen Verification Window Active</h2>
        <p class="text-xs">Confirm if the road/drainage repair holds up cleanly, or reopen the ticket if the fault persists.</p>
      </div>
      <div class="grid-2">
        <form method="POST" class="card stack-sm" style="padding:16px;">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="confirm_fix">
          <label class="form-label text-success">Repair Quality Note (Optional)</label>
          <input name="confirm_note" type="text" placeholder="Surface looks solid and compacted well." class="form-control">
          <button type="submit" class="btn btn-success btn-full">Confirm Fix &amp; Close Permanently</button>
        </form>

        <form method="POST" class="card stack-sm" style="padding:16px;">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="reopen_fix">
          <label class="form-label text-danger">Issue Not Fixed? Reason to Reopen</label>
          <input name="reopen_reason" type="text" required minlength="5" placeholder="Describe what still needs work..." class="form-control">
          <button type="submit" class="btn btn-danger btn-full">Reopen Ticket for Follow-Up</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <div class="split-8-4" style="align-items: start;">
    <!-- Left Column -->
    <div class="stack-lg">
      <div class="card stack-md">
        <h2 class="section-title">Hazard Details</h2>
        <p class="text-sm"><?= e($report['description']) ?></p>

        <div class="grid-2">
          <div class="photo-box">
            <div class="photo-box-header">Before Repair Photo</div>
            <?php if (!empty($report['before_photo'])): ?>
              <img src="<?= e($report['before_photo']) ?>" alt="Before repair">
            <?php else: ?>
              <div class="photo-empty">No photo uploaded</div>
            <?php endif; ?>
          </div>
          <div class="photo-box">
            <div class="photo-box-header">After Repair Proof Photo</div>
            <?php if (!empty($report['after_photo'])): ?>
              <img src="<?= e($report['after_photo']) ?>" alt="After repair">
            <?php else: ?>
              <div class="photo-empty">Pending completion photo</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Progress Timeline -->
      <div class="card stack-md">
        <h2 class="section-title">Repair &amp; Verification Timeline</h2>
        <div class="stack-sm">
          <?php foreach ($updates as $u): ?>
            <div class="card-muted stack-sm">
              <div class="row-between text-xs text-muted tabular-nums">
                <strong style="color:var(--text-main);"><?= e($u['author']) ?></strong>
                <span><?= e($u['timestamp_label']) ?></span>
              </div>
              <p class="text-xs"><?= e($u['text']) ?></p>
              <?php if (!empty($u['photo_url'])): ?>
                <img src="<?= e($u['photo_url']) ?>" alt="Update evidence" style="width:200px; height:115px; object-fit:cover; border-radius:8px; border:1px solid var(--border);">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Public Q&A Thread -->
      <div class="card stack-md">
        <h2 class="section-title">Citizen–Officer Public Q&amp;A (<?= count($comments) ?>)</h2>
        <div class="stack-sm">
          <?php foreach ($comments as $c): ?>
            <div class="<?= $c['is_official_question'] ? 'alert alert-info' : 'card-muted' ?> stack-sm">
              <div class="row-between text-xs text-muted">
                <strong style="color:var(--text-main);"><?= e($c['author_name']) ?> · <?= e($c['author_role']) ?></strong>
                <span class="tabular-nums"><?= e($c['created_at']) ?></span>
              </div>
              <p class="text-xs"><?= !empty($c['hidden']) ? '<em>Hidden from residents. </em>' : '' ?><?= e($c['text']) ?></p>
              <?php if ($isModerator && empty($c['hidden'])): ?>
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="hide_comment">
                  <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                  <button type="submit" class="btn btn-danger-outline btn-sm">Hide comment</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($user): ?>
          <form method="POST" class="stack-sm" style="padding-top:12px; border-top:1px solid var(--border);">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_comment">
            <textarea name="comment_text" rows="2" required placeholder="Add a clarification, landmark detail, or field question..." class="form-control"></textarea>
            <div class="row-between">
              <?php if ($user['role'] !== 'Community Member'): ?>
                <label class="text-xs font-bold" style="display:flex; align-items:center; gap:6px;">
                  <input type="checkbox" name="is_official_question" value="1">
                  <span>Mark as Official Clarification Question</span>
                </label>
              <?php else: ?>
                <span></span>
              <?php endif; ?>
              <button type="submit" class="btn btn-dark">Post Comment</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right Column -->
    <div class="stack-lg">
      <div class="card stack-md">
        <h2 class="section-title">Ticket Dispatch Summary</h2>
        <div class="list-divider text-xs">
          <div class="row-between" style="padding:9px 0;"><span class="text-muted">Priority</span><strong><?= e($report['priority']) ?></strong></div>
          <div class="row-between" style="padding:9px 0;"><span class="text-muted">Assigned Crew</span><strong><?= e($report['assigned_officer'] ?: 'Unassigned') ?></strong></div>
          <div class="row-between" style="padding:9px 0;"><span class="text-muted">SLA Deadline</span><strong class="text-mono"><?= e($report['sla_deadline']) ?></strong></div>
          <div class="row-between" style="padding:9px 0;"><span class="text-muted">SLA Status</span><strong class="<?= $sla['overdue'] ? 'text-danger' : 'text-success' ?>"><?= e($sla['label']) ?></strong></div>
          <div class="row-between" style="padding:9px 0;"><span class="text-muted">Endorsements</span><strong class="tabular-nums"><?= (int)$report['upvotes'] ?> Me Too</strong></div>
          <div class="row-between" style="padding:9px 0;"><span class="text-muted">Coordinates</span><span class="text-mono"><?= e($report['lat']) ?>, <?= e($report['lng']) ?></span></div>
        </div>
      </div>

      <?php if ($report['status'] === 'Resolved'): ?>
        <div class="card stack-md">
          <h2 class="section-title">Post-Repair Citizen Rating</h2>
          <?php if (!empty($report['feedback_rating'])): ?>
            <div class="alert alert-success stack-sm">
              <div class="font-bold">Rated <?= (int)$report['feedback_rating'] ?> / 5 ★</div>
              <p class="text-xs"><?= e($report['feedback_comment']) ?></p>
              <div class="text-xs">By <?= e($report['feedback_author']) ?></div>
            </div>
          <?php elseif ($user): ?>
            <form method="POST" class="stack-sm">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="submit_feedback">
              <select name="rating" class="form-control">
                <option value="5">5 Stars — Excellent Repair</option>
                <option value="4">4 Stars — Good Repair</option>
                <option value="3">3 Stars — Acceptable</option>
                <option value="2">2 Stars — Needs Work</option>
                <option value="1">1 Star — Poor Repair</option>
              </select>
              <textarea name="feedback_comment" rows="2" required placeholder="Share feedback on repair durability..." class="form-control"></textarea>
              <button type="submit" class="btn btn-success btn-full">Submit Rating (+15 Hero Pts)</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
