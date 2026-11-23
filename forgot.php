<?php
require_once __DIR__ . '/includes/auth.php';

$message = null;
$resetUrl = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $stmt = db()->prepare("SELECT id, email, account_status FROM users WHERE LOWER(email) = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $message = 'If that email is registered, a reset link has been created.';
    if ($user && $user['account_status'] !== 'blocked') {
        $token = bin2hex(random_bytes(16));
        $hash = hash('sha256', $token);
        db()->prepare('INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')
            ->execute([$user['email'], $hash]);
        $resetUrl = 'reset.php?token=' . urlencode($token);
        queue_outbound('email', $user['email'], 'FixMyRoad password reset', 'Open ' . $resetUrl);
        log_audit('System', 'Guest', 'Requested Password Reset', $user['id'], 'Reset token issued.', 'auth');
    }
}

$pageTitle = 'Forgot Password - FixMyRoad';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container-narrow">
  <div class="card stack-md">
    <h1 class="page-title" style="font-size:20px;">Reset password</h1>
    <p class="page-subtitle">XAMPP demo shows the link on this page. A copy is also queued in the email outbox.</p>
    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($resetUrl): ?>
      <p class="text-sm">Demo reset link (valid 30 minutes):</p>
      <a class="text-primary font-bold" href="<?= e($resetUrl) ?>"><?= e($resetUrl) ?></a>
    <?php endif; ?>
    <form method="POST" class="stack-sm">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label class="form-label">Email</label>
      <input name="email" type="email" required class="form-control">
      <button class="btn btn-primary" type="submit">Create reset link</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
