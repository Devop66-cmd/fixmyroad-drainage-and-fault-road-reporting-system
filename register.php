<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    redirect_to('dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (register_attempt_blocked()) {
        $error = 'Too many registration attempts from this browser. Please wait an hour.';
    } else {
    register_attempt_tick();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $division = trim((string)($_POST['division'] ?? 'Kansanga (Makindye)'));
    $street = trim((string)($_POST['street_address'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        $error = 'Please enter a valid name, email address, and password (at least 6 characters).';
    } else {
        $check = db()->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'An account with this email address already exists.';
        } else {
            $newId = 'usr-' . bin2hex(random_bytes(5));
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = db()->prepare(
                "INSERT INTO users (id, name, email, password_hash, phone, role, division, street_address) VALUES (?, ?, ?, ?, ?, 'Community Member', ?, ?)"
            );
            $ins->execute([$newId, $name, $email, $hash, $phone, $division, $street]);

            log_audit($name, 'Community Member', 'Registered New Citizen Account', $newId, "Registered $email in $division.", 'auth');
            session_regenerate_id(true);
            $_SESSION['user_id'] = $newId;
            flash_set('success', 'Welcome to FixMyRoad! Your citizen account is ready.');
            redirect_to('dashboard.php');
        }
    }
    }
}

$pageTitle = 'Register Citizen Account - FixMyRoad';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-narrow">
  <div class="card stack-md">
    <div>
      <h1 class="page-title" style="font-size:20px;">Create Citizen Account</h1>
      <p class="page-subtitle">Register to report faults in Kansanga and earn Community Hero points.</p>
    </div>

    <?php if ($error): ?>
      <div role="alert" class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="form-group">
        <label class="form-label">Full Name</label>
        <input name="name" type="text" required minlength="2" value="<?= e($_POST['name'] ?? '') ?>" placeholder="e.g., Grace Nakato" class="form-control">
      </div>

      <div class="form-group">
        <label class="form-label">Email Address</label>
        <input name="email" type="email" required value="<?= e($_POST['email'] ?? '') ?>" placeholder="resident@example.ug" class="form-control">
      </div>

      <div class="form-group">
        <label class="form-label">Phone Number</label>
        <input name="phone" type="text" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="+256 7XX XXX XXX" class="form-control">
      </div>

      <div class="form-group">
        <label class="form-label">Kansanga Zone / Parish</label>
        <select name="division" class="form-control">
          <option value="Kansanga (Makindye)">Kansanga (Makindye Division)</option>
          <option value="Kiwafu / Kansanga">Kiwafu / Kansanga</option>
          <option value="Nabutiti / Kansanga">Nabutiti / Kansanga</option>
          <option value="KIU / Ggaba Rd Corridor">KIU / Ggaba Rd Corridor</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Street / Landmark</label>
        <input name="street_address" type="text" value="<?= e($_POST['street_address'] ?? '') ?>" placeholder="e.g., Near Kansanga Junction, Ggaba Rd" class="form-control">
      </div>

      <div class="form-group">
        <label class="form-label">Password (min 6 characters)</label>
        <input name="password" type="password" required minlength="6" placeholder="Create password" class="form-control">
      </div>

      <button type="submit" class="btn btn-primary btn-full">Create Citizen Account</button>
    </form>

    <p class="text-xs text-muted" style="text-align:center;">
      Already have an account?
      <a href="login.php" class="text-primary font-bold">Sign In</a>
    </p>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
