<?php
/**
 * Shared Header + Dynamic Official Notice Top Alert Banner + Flash Messages
 */
$pageTitle = $pageTitle ?? 'FixMyRoad - Road & Drainage Fault Reporting System';
$activePage = $activePage ?? '';
$authUser = current_user();
$portalSettings = get_portal_settings();

if (!isset($_SESSION['dismissed_notices'])) {
    $_SESSION['dismissed_notices'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_top_notice') {
    verify_csrf();
    $dismissId = trim((string)($_POST['notice_id'] ?? ''));
    if ($dismissId !== '' && !in_array($dismissId, $_SESSION['dismissed_notices'], true)) {
        $_SESSION['dismissed_notices'][] = $dismissId;
    }
    redirect_to($_SERVER['REQUEST_URI'] ?? 'index.php');
}

$liveNotices = get_live_official_notices();
$bannerNotices = array_values(array_filter(
    $liveNotices,
    fn($n) => !in_array($n['id'], $_SESSION['dismissed_notices'], true)
));
$bannerIdx = isset($_GET['notice_idx']) ? max(0, (int)$_GET['notice_idx']) : 0;
$activeBanner = !empty($bannerNotices) ? $bannerNotices[$bannerIdx % count($bannerNotices)] : null;

$unreadCount = 0;
if ($authUser) {
    $notifStmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND (target_role = 'All' OR target_role = ?)");
    $notifStmt->execute([$authUser['role']]);
    $unreadCount = (int)$notifStmt->fetchColumn();
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?></title>
   <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>
<body>

<header class="site-header">
  <div class="header-inner">
    <a href="index.php" class="brand-logo">
      <div class="brand-badge">FR</div>
      <div>
        <div class="brand-title">FixMyRoad</div>
        <div class="brand-sub">Kansanga Pilot Portal</div>
      </div>
    </a>

    <nav class="main-nav">
      <a href="index.php" class="nav-link <?= $activePage === 'home' ? 'active' : '' ?>">Home</a>
      <?php if ($authUser): ?>
        <?php if ($authUser['role'] === 'Administrator'): ?>
          <a href="admin_dashboard.php" class="nav-link <?= $activePage === 'admin' ? 'active' : '' ?>">Admin Center</a>
        <?php elseif ($authUser['role'] === 'Field Officer'): ?>
          <a href="field_officer.php" class="nav-link <?= $activePage === 'officer' ? 'active' : '' ?>">Work Orders</a>
        <?php else: ?>
          <a href="dashboard.php" class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <?php endif; ?>
        <a href="report_fault.php" class="nav-link <?= $activePage === 'report' ? 'active' : '' ?>">Report Fault</a>
        <a href="map_alerts.php" class="nav-link <?= $activePage === 'map' ? 'active' : '' ?>">
          Live Map &amp; Alerts<?= $unreadCount > 0 ? ' (' . $unreadCount . ')' : '' ?>
        </a>
      <?php endif; ?>
      <a href="public_data.php" class="nav-link <?= $activePage === 'public' ? 'active' : '' ?>">Public Data</a>
    </nav>

    <div class="header-actions">
      <?php if ($authUser): ?>
        <a href="profile.php" class="user-chip">
          <span><?= e($authUser['name']) ?></span>
          <span class="text-muted">· <?= e($authUser['role']) ?></span>
        </a>
        <a href="logout.php" class="btn btn-dark">Sign Out</a>
      <?php else: ?>
        <a href="login.php" class="btn btn-outline">Sign In</a>
        <a href="register.php" class="btn btn-primary">Register</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php if ($activeBanner):
  $bannerClass = match ($activeBanner['category']) {
      'Emergency' => 'notice-banner--emergency',
      'Maintenance' => 'notice-banner--maintenance',
      default => 'notice-banner--general',
  };
?>
<div role="region" aria-label="Official Notice" class="notice-banner <?= $bannerClass ?>">
  <div class="notice-banner-inner">
    <p>
      <span class="notice-title"><?= e($activeBanner['title']) ?>:</span>
      <span><?= e($activeBanner['message']) ?></span>
      <?php if (!empty($activeBanner['expires_at'])): ?>
        <span class="tabular-nums" style="opacity:0.8; margin-left:6px;">(Valid until <?= e($activeBanner['expires_at']) ?>)</span>
      <?php endif; ?>
    </p>
    <div class="notice-actions">
      <?php if (count($bannerNotices) > 1):
        $nextIdx = ($bannerIdx + 1) % count($bannerNotices);
        $nextQuery = $_GET;
        $nextQuery['notice_idx'] = $nextIdx;
      ?>
        <a href="?<?= e(http_build_query($nextQuery)) ?>" class="notice-btn tabular-nums">
          Next (<?= ($bannerIdx % count($bannerNotices)) + 1 ?>/<?= count($bannerNotices) ?>)
        </a>
      <?php endif; ?>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="dismiss_top_notice">
        <input type="hidden" name="notice_id" value="<?= e($activeBanner['id']) ?>">
        <button type="submit" class="notice-btn">Dismiss</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($flash): ?>
  <div class="container" style="padding-bottom: 0;">
    <div role="alert" class="alert <?= $flash['type'] === 'error' ? 'alert-error' : 'alert-success' ?>">
      <?= e($flash['message']) ?>
    </div>
  </div>
<?php endif; ?>

<main class="main-content">
