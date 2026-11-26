<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();

// Handle "Me Too" Upvote Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_upvote') {
    verify_csrf();
    $reportId = trim((string)($_POST['report_id'] ?? ''));
    if ($reportId !== '') {
        $chk = $pdo->prepare('SELECT 1 FROM report_upvotes WHERE report_id = ? AND user_id = ?');
        $chk->execute([$reportId, $user['id']]);
        if ($chk->fetch()) {
            $pdo->prepare('DELETE FROM report_upvotes WHERE report_id = ? AND user_id = ?')->execute([$reportId, $user['id']]);
            $pdo->prepare('UPDATE reports SET upvotes = GREATEST(0, upvotes - 1) WHERE id = ?')->execute([$reportId]);
        } else {
            $pdo->prepare('INSERT INTO report_upvotes (report_id, user_id) VALUES (?, ?)')->execute([$reportId, $user['id']]);
            $pdo->prepare('UPDATE reports SET upvotes = upvotes + 1 WHERE id = ?')->execute([$reportId]);
        }
    }
    redirect_to($_SERVER['REQUEST_URI'] ?? 'dashboard.php');
}

$requestedScope = (string)($_GET['scope'] ?? ($user['role'] === 'Local Council' ? 'parish' : 'mine'));
$scope = in_array($requestedScope, ['mine', 'all', 'parish'], true) ? $requestedScope : 'mine';
$statusFilter = trim((string)($_GET['status'] ?? 'All'));
$categoryFilter = trim((string)($_GET['category'] ?? 'All'));
$noticeCategory = trim((string)($_GET['notice_cat'] ?? 'All'));
$search = trim((string)($_GET['q'] ?? ''));

$hero = compute_hero_summary($user);

$allReports = $pdo->query('SELECT * FROM reports ORDER BY created_at DESC')->fetchAll();
$upvotedIds = $pdo->prepare('SELECT report_id FROM report_upvotes WHERE user_id = ?');
$upvotedIds->execute([$user['id']]);
$myUpvotes = array_flip($upvotedIds->fetchAll(PDO::FETCH_COLUMN));

$myEmail = strtolower(trim($user['email']));
$myName = strtolower(trim($user['name']));

$myReports = array_values(array_filter($allReports, function ($r) use ($myEmail, $myName) {
    return strtolower(trim($r['reported_by_email'] ?? '')) === $myEmail
        || strtolower(trim($r['reported_by'] ?? '')) === $myName;
}));

$scopedReports = $scope === 'mine' ? $myReports : $allReports;
$pendingCount = count(array_filter($scopedReports, fn($r) => $r['status'] === 'Pending'));
$inProgressCount = count(array_filter($scopedReports, fn($r) => $r['status'] === 'In Progress'));
$resolvedCount = count(array_filter($scopedReports, fn($r) => $r['status'] === 'Resolved'));

$awaitingConfirmation = array_values(array_filter($myReports, fn($r) =>
    $r['status'] === 'Resolved' && ($r['confirmation_state'] ?? '') === 'awaiting_confirmation'
));

$filteredReports = array_values(array_filter($scopedReports, function ($r) use ($statusFilter, $categoryFilter, $search) {
    if ($statusFilter !== 'All' && $r['status'] !== $statusFilter) return false;
    if ($categoryFilter !== 'All' && $r['category'] !== $categoryFilter) return false;
    if ($search !== '') {
        $hay = strtolower($r['id'] . ' ' . $r['title'] . ' ' . $r['location'] . ' ' . $r['category']);
        if (!str_contains($hay, strtolower($search))) return false;
    }
    return true;
}));

// Official Notices with Category Filter & Expiry Auto-Hide
$liveNotices = get_live_official_notices();
$noticeCounts = ['All' => count($liveNotices), 'Emergency' => 0, 'Maintenance' => 0, 'General Announcement' => 0];
foreach ($liveNotices as $ln) {
    $noticeCounts[$ln['category']] = ($noticeCounts[$ln['category']] ?? 0) + 1;
}
$filteredNotices = $noticeCategory === 'All'
    ? $liveNotices
    : array_values(array_filter($liveNotices, fn($n) => $n['category'] === $noticeCategory));

$pageTitle = 'Citizen Dashboard - FixMyRoad';
$activePage = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="portal-layout">
  <!-- Side Navigation Menu -->
  <aside class="sidebar">
    <div>
      <div class="sidebar-header">
        <div class="sidebar-role"><?= $user['role'] === 'Local Council' ? 'Local Council' : 'Citizen Portal' ?></div>
        <div class="sidebar-user"><?= e($user['name']) ?></div>
        <div class="sidebar-sub tabular-nums"><?= $hero['points'] ?> Hero Pts · <?= e($hero['tier']) ?></div>
      </div>

      <nav class="sidebar-nav">
        <a href="dashboard.php?scope=mine" class="sidebar-link <?= $scope === 'mine' ? 'active' : '' ?>">
          <span><?= e(t('my_reports')) ?></span>
          <span class="tabular-nums"><?= count($myReports) ?></span>
        </a>
        <?php if ($user['role'] === 'Local Council'): ?>
        <a href="dashboard.php?scope=parish" class="sidebar-link <?= $scope === 'parish' ? 'active' : '' ?>">
          <span><?= e(t('parish_feed')) ?></span>
          <span class="tabular-nums"><?= count($allReports) ?></span>
        </a>
        <?php endif; ?>
        <a href="dashboard.php?scope=all" class="sidebar-link <?= $scope === 'all' ? 'active' : '' ?>">
          <span>Community Feed</span>
          <span class="tabular-nums"><?= count($allReports) ?></span>
        </a>
        <a href="report_fault.php" class="sidebar-link"><span>+ Report New Fault</span></a>
        <a href="map_alerts.php" class="sidebar-link"><span>Live Map &amp; Alerts</span></a>
        <a href="public_data.php" class="sidebar-link"><span>Public Transparency</span></a>
        <a href="profile.php" class="sidebar-link"><span>Profile &amp; Hero Badges</span></a>

        <?php if (in_array($user['role'], ['Administrator', 'Supervisor', 'Page Moderator'], true)): ?>
          <a href="<?= e(role_home($user['role'])) ?>" class="sidebar-link" style="color:#fbbf24;"><span><?= e($user['role']) ?> desk →</span></a>
        <?php elseif ($user['role'] === 'Field Officer'): ?>
          <a href="field_officer.php" class="sidebar-link" style="color:#34d399;"><span>Field Officer Portal →</span></a>
        <?php endif; ?>
      </nav>
    </div>

    <div class="sidebar-footer">
      <a href="logout.php" class="sidebar-link" style="color:#fda4af;">Sign Out</a>
    </div>
  </aside>

  <!-- Main Workspace -->
  <div class="portal-main stack-lg">
    <div class="page-header">
      <div>
        <div class="page-eyebrow">Citizen Portal · Kansanga Pilot</div>
        <h1 class="page-title">Hello, <?= e($user['name']) ?></h1>
        <p class="page-subtitle">Filter fault reports, track live Kansanga repairs, and review municipal notices.</p>
      </div>
      <a href="report_fault.php" class="btn btn-primary">+ Report New Fault</a>
    </div>

    <!-- Community Hero Card -->
    <section class="hero-card row-between">
      <div class="stack-sm" style="flex:1; min-width:250px;">
        <div class="text-xs font-bold" style="color:#fde68a;">
          Rank: <?= e($hero['tier']) ?> · <span style="color:#cbd5e1;"><?= $hero['resolvedCount'] ?> resolved fault(s)</span>
        </div>
        <div class="flex-wrap-gap">
          <span class="tabular-nums" style="font-size:32px; font-weight:800;"><?= $hero['points'] ?></span>
          <span class="font-bold" style="color:#34d399;">Hero Pts</span>
          <?php if ($hero['nextTier']): ?>
            <span class="text-xs tabular-nums" style="color:#cbd5e1;">
              (<?= $hero['nextTierMin'] - $hero['points'] ?> pts → <?= e($hero['nextTier']) ?>)
            </span>
          <?php endif; ?>
        </div>
        <div class="progress-track" style="max-width:420px;">
          <div class="progress-fill" style="width: <?= $hero['progressPct'] ?>%"></div>
        </div>
      </div>

      <div class="flex-wrap-gap">
        <a href="profile.php" class="btn btn-success">Hero Points Breakdown →</a>
        <a href="public_data.php" class="btn btn-outline" style="background:#1e293b; border-color:#334155; color:#ffffff;">City Leaderboard</a>
      </div>
    </section>

    <!-- 4 Status KPI Cards -->
    <section class="grid-4">
      <a href="dashboard.php?scope=<?= e($scope) ?>&status=All" class="kpi-card <?= $statusFilter === 'All' ? 'active' : '' ?>">
        <div class="kpi-label">All Statuses</div>
        <div class="kpi-value tabular-nums"><?= count($scopedReports) ?></div>
      </a>
      <a href="dashboard.php?scope=<?= e($scope) ?>&status=Pending" class="kpi-card <?= $statusFilter === 'Pending' ? 'active' : '' ?>">
        <div class="kpi-label">Pending</div>
        <div class="kpi-value text-warning tabular-nums"><?= $pendingCount ?></div>
      </a>
      <a href="dashboard.php?scope=<?= e($scope) ?>&status=In+Progress" class="kpi-card <?= $statusFilter === 'In Progress' ? 'active' : '' ?>">
        <div class="kpi-label">In Progress</div>
        <div class="kpi-value text-primary tabular-nums"><?= $inProgressCount ?></div>
      </a>
      <a href="dashboard.php?scope=<?= e($scope) ?>&status=Resolved" class="kpi-card <?= $statusFilter === 'Resolved' ? 'active' : '' ?>">
        <div class="kpi-label">Resolved</div>
        <div class="kpi-value text-success tabular-nums"><?= $resolvedCount ?></div>
      </a>
    </section>

    <!-- 48-Hour Citizen Confirm Fix Prompt -->
    <?php if (!empty($awaitingConfirmation)): ?>
      <section class="alert alert-warning stack-md">
        <div>
          <h2 class="section-title">Confirm Fix Before Closure (<?= count($awaitingConfirmation) ?> awaiting your sign-off)</h2>
          <p class="text-xs">Verify the repair holds up or reopen the ticket before the 48-hour window closes it permanently.</p>
        </div>
        <div class="grid-2">
          <?php foreach ($awaitingConfirmation as $ar): ?>
            <a href="track_report.php?id=<?= urlencode($ar['id']) ?>" class="card row-between" style="padding:14px;">
              <div>
                <div class="text-mono text-xs font-bold text-primary"><?= e($ar['id']) ?></div>
                <div class="font-bold text-sm"><?= e($ar['title']) ?></div>
              </div>
              <span class="btn btn-success btn-sm">Confirm Fix →</span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- Official Municipal Notices Feed with Category Filter -->
    <?php if (!empty($liveNotices)): ?>
      <section class="card stack-md">
        <div class="row-between">
          <div>
            <h2 class="section-title">Official Municipal Notices</h2>
            <p class="text-xs text-muted">Verified advisories published by FixMyRoad administration for Kansanga residents.</p>
          </div>
          <div class="tab-bar">
            <?php foreach (['All', 'Emergency', 'Maintenance', 'General Announcement'] as $catOption):
              $active = $noticeCategory === $catOption;
              $url = 'dashboard.php?scope=' . urlencode($scope) . '&status=' . urlencode($statusFilter) . '&category=' . urlencode($categoryFilter) . '&notice_cat=' . urlencode($catOption);
            ?>
              <a href="<?= e($url) ?>" class="tab-item tabular-nums <?= $active ? 'active' : '' ?>">
                <?= e($catOption) ?> (<?= $noticeCounts[$catOption] ?? 0 ?>)
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (empty($filteredNotices)): ?>
          <div class="card-muted text-xs text-muted" style="text-align:center;">
            No active official notices in this category right now.
            <a href="dashboard.php?scope=<?= e($scope) ?>&notice_cat=All" class="text-primary font-bold">Show all</a>
          </div>
        <?php else: ?>
          <div class="grid-2">
            <?php foreach ($filteredNotices as $notice):
              $cardCls = match ($notice['category']) {
                  'Emergency' => 'notice-card--emergency',
                  'Maintenance' => 'notice-card--maintenance',
                  default => '',
              };
            ?>
              <article class="notice-card <?= $cardCls ?> stack-sm">
                <h3 style="font-size:14px; font-weight:800; color:var(--text-main);"><?= e($notice['title']) ?></h3>
                <p class="text-xs"><?= e($notice['message']) ?></p>
                <div class="text-xs text-muted tabular-nums" style="padding-top:8px; border-top:1px solid var(--border);">
                  <strong><?= e($notice['category']) ?></strong> ·
                  Posted by <?= e($notice['posted_by']) ?> ·
                  <?= e(substr($notice['created_at'], 0, 10)) ?> ·
                  <?= !empty($notice['expires_at']) ? 'Valid until ' . e($notice['expires_at']) : 'Ongoing notice' ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <!-- Reports Registry & Filters -->
    <section class="card stack-md">
      <div class="row-between">
        <div>
          <h2 class="section-title"><?= $scope === 'mine' ? 'My Reported Faults' : 'Kansanga Community Feed' ?></h2>
          <p class="text-xs text-muted tabular-nums"><?= count($filteredReports) ?> / <?= count($scopedReports) ?> reports</p>
        </div>

        <div class="tab-bar">
          <a href="dashboard.php?scope=mine" class="tab-item tabular-nums <?= $scope === 'mine' ? 'active' : '' ?>">
            My Reported Faults (<?= count($myReports) ?>)
          </a>
          <a href="dashboard.php?scope=all" class="tab-item tabular-nums <?= $scope === 'all' ? 'active' : '' ?>">
            Community Feed (<?= count($allReports) ?>)
          </a>
        </div>
      </div>

      <form method="GET" class="filter-bar">
        <input type="hidden" name="scope" value="<?= e($scope) ?>">
        <input type="hidden" name="notice_cat" value="<?= e($noticeCategory) ?>">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search report ID, title, or street..." class="form-control">
        <select name="status" class="form-control">
          <?php foreach (['All', 'Pending', 'In Progress', 'Resolved', 'Rejected'] as $st): ?>
            <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>>Status: <?= e($st) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="category" class="form-control">
          <?php foreach (['All', 'Pothole', 'Blocked Drainage', 'Flooding', 'Damaged Road', 'Broken Streetlight', 'Other'] as $cat): ?>
            <option value="<?= e($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>>Type: <?= e($cat) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-dark">Filter</button>
      </form>

      <?php if (empty($filteredReports)): ?>
        <div class="card-muted stack-sm" style="text-align:center; padding:28px;">
          <p class="text-xs font-bold text-muted">No fault reports match your current filters.</p>
          <div><a href="report_fault.php" class="btn btn-primary">+ Log New Fault</a></div>
        </div>
      <?php else: ?>
        <div class="list-divider">
          <?php foreach ($filteredReports as $r):
            $hasUpvoted = isset($myUpvotes[$r['id']]);
            $sla = get_sla_status($r);
            $badgeCls = $r['status'] === 'Resolved' ? 'badge-resolved' : ($r['status'] === 'In Progress' ? 'badge-progress' : 'badge-pending');
          ?>
            <div class="report-row">
              <div class="report-row-left">
                <?php if (!empty($r['before_photo'])): ?>
                  <img src="<?= e($r['before_photo']) ?>" alt="<?= e($r['title']) ?>" class="thumb-img">
                <?php else: ?>
                  <div class="thumb-placeholder"><?= e($r['category']) ?></div>
                <?php endif; ?>

                <div class="stack-sm" style="gap:3px; min-width:0;">
                  <div class="text-xs text-muted tabular-nums">
                    <span class="text-mono font-bold text-primary"><?= e($r['id']) ?></span> ·
                    <span><?= e($r['category']) ?></span> ·
                    <span><?= e($r['priority']) ?> Priority</span>
                    <?php if (!empty($r['escalated'])): ?>
                      · <span class="font-bold text-danger">Escalated</span>
                    <?php endif; ?>
                  </div>
                  <a href="track_report.php?id=<?= urlencode($r['id']) ?>" class="font-bold" style="font-size:15px; color:var(--text-main);">
                    <?= e($r['title']) ?>
                  </a>
                  <div class="text-xs text-muted tabular-nums">
                    <?= e($r['location']) ?> · <?= e($r['report_date']) ?> · SLA: <?= e($sla['label']) ?>
                  </div>
                </div>
              </div>

              <div class="flex-wrap-gap">
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle_upvote">
                  <input type="hidden" name="report_id" value="<?= e($r['id']) ?>">
                  <button type="submit" class="btn btn-sm tabular-nums <?= $hasUpvoted ? 'btn-primary' : 'btn-outline' ?>">
                    Me Too (<?= (int)$r['upvotes'] ?>)
                  </button>
                </form>
                <span class="badge <?= $badgeCls ?>"><?= e($r['status']) ?></span>
                <a href="track_report.php?id=<?= urlencode($r['id']) ?>" class="btn btn-outline btn-sm">Track →</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
