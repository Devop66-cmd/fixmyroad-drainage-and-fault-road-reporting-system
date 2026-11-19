<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $division = trim((string)($_POST['division'] ?? 'Kansanga (Makindye)'));
        $street = trim((string)($_POST['street_address'] ?? ''));

        if ($name === '') {
            flash_set('error', 'Full name cannot be empty.');
            redirect_to('profile.php');
        }

        $pdo->prepare('UPDATE users SET name = ?, phone = ?, division = ?, street_address = ? WHERE id = ?')
            ->execute([$name, $phone, $division, $street, $user['id']]);
        log_audit($name, $user['role'], 'Updated Profile Details', $user['id'], "Updated local division ($division) and contact details.", 'auth');
        flash_set('success', 'Profile details saved.');
        redirect_to('profile.php');
    }

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $next = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!verify_user_password($user, $current)) {
            flash_set('error', 'Current password is incorrect.');
            redirect_to('profile.php');
        }
        if (strlen($next) < 6) {
            flash_set('error', 'New password must be at least 6 characters.');
            redirect_to('profile.php');
        }
        if ($next !== $confirm) {
            flash_set('error', 'New password and confirmation do not match.');
            redirect_to('profile.php');
        }

        $hash = password_hash($next, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);
        log_audit($user['name'], $user['role'], 'Changed Account Password', $user['id'], 'Updated account password hash.', 'auth');
        flash_set('success', 'Password updated successfully.');
        redirect_to('profile.php');
    }
}

$hero = compute_hero_summary($user);
$zones = ['Kansanga (Makindye)', 'Kiwafu / Kansanga', 'Nabutiti / Kansanga', 'KIU / Kansanga Junction', 'Ggaba Road Corridor'];

$pageTitle = 'Profile & Hero Badges - FixMyRoad';
$activePage = 'profile';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-medium stack-lg">
  <div>
    <div class="page-eyebrow">Account &amp; Civic Rank</div>
    <h1 class="page-title"><?= e($user['name']) ?></h1>
    <p class="page-subtitle"><?= e($user['email']) ?> · <?= e($user['role']) ?></p>
  </div>

  <!-- Hero Rank Summary -->
  <div class="hero-card stack-md">
    <div class="row-between">
      <div>
        <div class="hero-eyebrow">Kansanga Community Hero Standing</div>
        <div style="font-size:24px; font-weight:800; margin-top:4px;">
          <?= e($hero['tier']) ?> · <span class="tabular-nums"><?= $hero['points'] ?> pts</span>
        </div>
      </div>
      <div class="text-xs tabular-nums" style="color:#cbd5e1;">
        <?php if ($hero['nextTier']): ?>
          Progress to <strong style="color:#ffffff;"><?= e($hero['nextTier']) ?></strong> (<?= $hero['nextTierMin'] ?> pts)
        <?php else: ?>
          Top Civic Rank Reached
        <?php endif; ?>
      </div>
    </div>

    <div class="progress-track">
      <div class="progress-fill" style="width: <?= $hero['progressPct'] ?>%"></div>
    </div>

    <div class="grid-4">
      <div class="stat-tile">
        <div class="stat-tile-label">Reports Submitted</div>
        <div class="stat-tile-value tabular-nums" style="font-size:18px;"><?= $hero['reportedCount'] ?> (×10)</div>
      </div>
      <div class="stat-tile">
        <div class="stat-tile-label">Verified Fixes</div>
        <div class="stat-tile-value tabular-nums" style="font-size:18px;"><?= $hero['resolvedCount'] ?> (×50)</div>
      </div>
      <div class="stat-tile">
        <div class="stat-tile-label">Feedback Ratings</div>
        <div class="stat-tile-value tabular-nums" style="font-size:18px;"><?= $hero['feedbackCount'] ?> (×15)</div>
      </div>
      <div class="stat-tile">
        <div class="stat-tile-label">Endorsements</div>
        <div class="stat-tile-value tabular-nums" style="font-size:18px;"><?= $hero['endorsementsTotal'] ?> (×2)</div>
      </div>
    </div>
  </div>

  <div class="grid-2" style="align-items: start;">
    <form method="POST" class="card">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_profile">
      <h2 class="section-title" style="margin-bottom:14px;">Contact &amp; Kansanga Zone Details</h2>

      <div class="form-group">
        <label class="form-label">Full Name</label>
        <input name="name" type="text" required value="<?= e($user['name']) ?>" class="form-control">
      </div>
      <div class="form-group">
        <label class="form-label">Phone Number</label>
        <input name="phone" type="text" value="<?= e($user['phone']) ?>" class="form-control">
      </div>
      <div class="form-group">
        <label class="form-label">Kansanga Zone / Division</label>
        <select name="division" class="form-control">
          <?php
          $curDiv = $user['division'] ?: 'Kansanga (Makindye)';
          $options = in_array($curDiv, $zones, true) ? $zones : array_merge([$curDiv], $zones);
          foreach ($options as $z):
          ?>
            <option value="<?= e($z) ?>" <?= $curDiv === $z ? 'selected' : '' ?>><?= e($z) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Street / Plot Address</label>
        <input name="street_address" type="text" value="<?= e($user['street_address']) ?>" class="form-control">
      </div>
      <button type="submit" class="btn btn-primary">Save Profile</button>
    </form>

    <form method="POST" class="card">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="change_password">
      <h2 class="section-title" style="margin-bottom:14px;">Change Account Password</h2>
      <div class="form-group">
        <label class="form-label">Current Password</label>
        <input name="current_password" type="password" required class="form-control">
      </div>
      <div class="form-group">
        <label class="form-label">New Password (min 6 characters)</label>
        <input name="new_password" type="password" required minlength="6" class="form-control">
      </div>
      <div class="form-group">
        <label class="form-label">Confirm New Password</label>
        <input name="confirm_password" type="password" required minlength="6" class="form-control">
      </div>
      <button type="submit" class="btn btn-dark">Update Password</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
