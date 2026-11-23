<?php
require_once __DIR__ . '/includes/auth.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = null;
$done = false;
if ($token === '') {
    $error = 'Missing reset token.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = (string)($_POST['password'] ?? '');
    $hash = hash('sha256', $token);
    $stmt = db()->prepare('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at >= NOW() ORDER BY id DESC LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        $error = 'This reset link is invalid or expired.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE LOWER(email) = ?')->execute([password_hash($password, PASSWORD_DEFAULT), strtolower($row['email'])]);
        db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        log_audit($row['email'], 'Guest', 'Reset Password', null, 'Password updated via reset token.', 'auth');
        $done = true;
    }
}

$pageTitle = 'Set New Password - FixMyRoad';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container-narrow">
  <div class="card stack-md">
    <h1 class="page-title" style="font-size:20px;">Choose a new password</h1>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($done): ?>
      <div class="alert alert-success">Password updated. You can sign in now.</div>
      <a href="login.php" class="btn btn-primary">Sign In</a>
    <?php elseif (!$error || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
      <form method="POST" class="stack-sm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label class="form-label">New password</label>
        <input name="password" type="password" minlength="6" required class="form-control">
        <button class="btn btn-primary" type="submit">Update password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
