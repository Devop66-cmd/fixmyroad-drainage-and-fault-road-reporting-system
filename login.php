<?php
require_once __DIR__ . '/includes/auth.php';

if ($existing = current_user()) {
    $target = match ($existing['role']) {
        'Administrator' => 'admin_dashboard.php',
        'Field Officer' => 'field_officer.php',
        default => 'dashboard.php',
    };
    redirect_to($target);
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $locked = login_lock_message();

    if ($locked) {
        $error = $locked;
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter both your email address and password.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !verify_user_password($user, $password)) {
            register_login_failure();
            log_audit('Unknown', 'Guest', 'Failed Sign In', null, "Failed login for $email.", 'auth');
            $error = login_lock_message() ?? 'Invalid email or password.';
        } elseif ($user['account_status'] === 'blocked') {
            $error = 'This account has been blocked by an Administrator.'
                . ($user['blocked_reason'] ? ' Reason: ' . $user['blocked_reason'] : '');
        } else {
            clear_login_failures();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            log_audit($user['name'], $user['role'], 'Signed In (XAMPP Session)', $user['id'], 'Authenticated via PHP MySQL login.', 'auth');

            $dest = match ($user['role']) {
                'Administrator' => 'admin_dashboard.php',
                'Field Officer' => 'field_officer.php',
                default => 'dashboard.php',
            };
            redirect_to($dest);
        }
    }
}

$pageTitle = 'Sign In - FixMyRoad';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-narrow">
  <div class="card stack-md">
    <div>
      <h1 class="page-title" style="font-size:20px;">Sign In to FixMyRoad</h1>
      <p class="page-subtitle">Kansanga Road &amp; Drainage Fault Reporting Portal</p>
    </div>

    <?php if ($error): ?>
      <div role="alert" class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="form-group">
        <label for="login-email" class="form-label">Email Address</label>
        <input id="login-email" name="email" type="email" required value="<?= e($_POST['email'] ?? '') ?>"
               placeholder="name@example.com" class="form-control">
      </div>

      <div class="form-group">
        <label for="login-password" class="form-label">Password</label>
        <input id="login-password" name="password" type="password" required
               placeholder="Enter password" class="form-control">
      </div>

      <button type="submit" class="btn btn-primary btn-full">Sign In</button>
    </form>
    <p class="text-xs" style="text-align:right; margin-top:-8px;">
      <a href="forgot.php" class="text-primary">Forgot password?</a>
    </p>

    Quick Demo Credentials Helper
    <div class="stack-sm" style="padding-top:16px; border-top:1px solid var(--border);">
      <!-- <div class="kpi-label">Quick Demo Accounts (Password: password123)</div>
      <button type="button" onclick="fillDemo('alaruroy@gmail.com')" class="btn btn-outline btn-full row-between">
        <span>Community Member (Ipavu Roy)</span>
        <span class="text-mono text-muted">alaruroy@gmail.com</span>
      </button>
      <button type="button" onclick="fillDemo('dmukasa@roads.gov.ug')" class="btn btn-outline btn-full row-between">
        <span>Field Officer (David Mukasa)</span>
        <span class="text-mono text-muted">dmukasa@roads.gov.ug</span>
      </button>
      <button type="button" onclick="fillDemo('admin@roads.gov.ug')" class="btn btn-outline btn-full row-between">
        <span>Administrator (Sarah Nsubuga)</span>
        <span class="text-mono text-muted">admin@roads.gov.ug</span>
      </button>
    </div> -->

    <p class="text-xs text-muted" style="text-align:center;">
      New resident in Kansanga?
      <a href="register.php" class="text-primary font-bold">Create Citizen Account</a>
    </p>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
