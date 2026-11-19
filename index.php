<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'FixMyRoad - Kansanga Road & Drainage Fault Reporting System';
$activePage = 'home';

$pdo = db();
$totalReports = (int)$pdo->query('SELECT COUNT(*) FROM reports')->fetchColumn();
$resolvedReports = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'Resolved'")->fetchColumn();
$inProgressReports = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'In Progress'")->fetchColumn();
$resolutionRate = $totalReports > 0 ? (int)round(($resolvedReports / $totalReports) * 100) : 0;

$recentReports = $pdo->query('SELECT id, title, category, location, status, priority, upvotes, report_date FROM reports ORDER BY created_at DESC LIMIT 4')->fetchAll();
$liveFeedNotices = get_live_official_notices();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container stack-lg">
  <!-- Hero Section -->
  <section class="hero-card split-3-2" style="align-items: center;">
    <div class="stack-md">
      <div class="hero-eyebrow">Kansanga, Makindye Division · Pilot Platform</div>
      <h1 class="hero-title">Report road potholes &amp; blocked drains faster in Kansanga.</h1>
      <p class="hero-text">
        FixMyRoad connects Kansanga residents directly with municipal field crews along Ggaba Road, Kiwafu, Nabutiti, and KIU Junction — with live GPS pinning, SLA tracking, and 48-hour citizen verification.
      </p>
      <div class="flex-wrap-gap">
        <a href="report_fault.php" class="btn btn-primary">+ Report a Fault Now</a>
        <?php if (!current_user()): ?>
          <a href="login.php" class="btn btn-outline" style="background:#1e293b; border-color:#334155; color:#ffffff;">Sign In to Portal</a>
        <?php else: ?>
          <a href="dashboard.php" class="btn btn-outline" style="background:#1e293b; border-color:#334155; color:#ffffff;">Open My Dashboard</a>
        <?php endif; ?>
        <a href="public_data.php" class="btn" style="color:#cbd5e1;">View Public Data →</a>
      </div>
    </div>

    <div class="grid-2">
      <div class="stat-tile">
        <div class="stat-tile-label">Logged Faults</div>
        <div class="stat-tile-value tabular-nums"><?= $totalReports ?></div>
      </div>
      <div class="stat-tile">
        <div class="stat-tile-label">Resolved</div>
        <div class="stat-tile-value tabular-nums" style="color:#34d399;"><?= $resolvedReports ?></div>
      </div>
      <div class="stat-tile">
        <div class="stat-tile-label">Active Repairs</div>
        <div class="stat-tile-value tabular-nums" style="color:#38bdf8;"><?= $inProgressReports ?></div>
      </div>
      <div class="stat-tile">
        <div class="stat-tile-label">Resolution Rate</div>
        <div class="stat-tile-value tabular-nums" style="color:#fbbf24;"><?= $resolutionRate ?>%</div>
      </div>
    </div>
  </section>

  <!-- Active Official Notices Summary -->
  <?php if (!empty($liveFeedNotices)): ?>
    <section class="card stack-md">
      <div class="row-between">
        <div>
          <h2 class="section-title">Active Official Notices</h2>
          <p class="text-xs text-muted">Municipal advisories currently in effect for Kansanga residents.</p>
        </div>
        <a href="dashboard.php" class="text-xs font-bold text-primary">Filter in Feed →</a>
      </div>
      <div class="grid-3">
        <?php foreach (array_slice($liveFeedNotices, 0, 3) as $n):
          $cardCls = $n['category'] === 'Emergency' ? 'notice-card--emergency' : ($n['category'] === 'Maintenance' ? 'notice-card--maintenance' : '');
        ?>
          <article class="notice-card <?= $cardCls ?> stack-sm">
            <div class="text-xs font-bold">
              <?= e($n['category']) ?><?= !empty($n['expires_at']) ? ' · Valid until ' . e($n['expires_at']) : '' ?>
            </div>
            <h3 style="font-size:14px; font-weight:800; color:var(--text-main);"><?= e($n['title']) ?></h3>
            <p class="text-xs text-muted"><?= e($n['message']) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- Recent Kansanga Reports -->
  <section class="card stack-md">
    <div class="row-between">
      <h2 class="section-title">Recent Kansanga Fault Reports</h2>
      <a href="public_data.php" class="text-xs font-bold text-primary">View All Records →</a>
    </div>
    <div class="list-divider">
      <?php foreach ($recentReports as $r):
        $badgeCls = $r['status'] === 'Resolved' ? 'badge-resolved' : ($r['status'] === 'In Progress' ? 'badge-progress' : 'badge-pending');
      ?>
        <a href="track_report.php?id=<?= urlencode($r['id']) ?>" class="report-row">
          <div class="stack-sm" style="gap:3px;">
            <div class="text-xs text-muted tabular-nums">
              <span class="text-mono font-bold text-primary"><?= e($r['id']) ?></span> ·
              <span><?= e($r['category']) ?></span> ·
              <span><?= e($r['priority']) ?> Priority</span>
            </div>
            <div class="font-bold" style="font-size:15px; color:var(--text-main);"><?= e($r['title']) ?></div>
            <div class="text-xs text-muted"><?= e($r['location']) ?> · <?= e($r['report_date']) ?></div>
          </div>
          <div class="flex-wrap-gap tabular-nums">
            <span class="text-xs font-bold text-muted"><?= (int)$r['upvotes'] ?> Me Too</span>
            <span class="badge <?= $badgeCls ?>"><?= e($r['status']) ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
