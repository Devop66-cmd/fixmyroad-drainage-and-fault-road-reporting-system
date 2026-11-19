<?php
require_once __DIR__ . '/includes/auth.php';
$user = current_user();
$pdo = db();

$statusFilter = trim((string)($_GET['status'] ?? 'All'));
$categoryFilter = trim((string)($_GET['category'] ?? 'All'));
$search = trim((string)($_GET['q'] ?? ''));

$allReports = $pdo->query('SELECT * FROM reports ORDER BY created_at DESC')->fetchAll();

$filtered = array_values(array_filter($allReports, function ($r) use ($statusFilter, $categoryFilter, $search) {
    if ($statusFilter !== 'All' && $r['status'] !== $statusFilter) return false;
    if ($categoryFilter !== 'All' && $r['category'] !== $categoryFilter) return false;
    if ($search !== '') {
        $hay = strtolower($r['id'] . ' ' . $r['title'] . ' ' . $r['location'] . ' ' . $r['category']);
        if (!str_contains($hay, strtolower($search))) return false;
    }
    return true;
}));

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fixmyroad_kansanga_public_ledger_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Ticket ID', 'Title', 'Category', 'Location', 'Priority', 'Status', 'Assigned Crew', 'Reported Date', 'Upvotes', 'Confirmed']);
    foreach ($filtered as $r) {
        fputcsv($out, [
            $r['id'], $r['title'], $r['category'], $r['location'], $r['priority'],
            $r['status'], $r['assigned_officer'] ?? 'Unassigned', $r['report_date'],
            $r['upvotes'], $r['confirmation_state'],
        ]);
    }
    fclose($out);
    exit;
}

$totalCount = count($allReports);
$resolvedCount = count(array_filter($allReports, fn($r) => $r['status'] === 'Resolved'));
$openCount = count(array_filter($allReports, fn($r) => in_array($r['status'], ['Pending', 'In Progress'], true)));
$resolutionRate = $totalCount > 0 ? (int)round(($resolvedCount / $totalCount) * 100) : 0;

$rated = array_filter($allReports, fn($r) => !empty($r['feedback_rating']));
$avgRating = !empty($rated)
    ? number_format(array_sum(array_map(fn($r) => (int)$r['feedback_rating'], $rated)) / count($rated), 1)
    : '—';

$citizens = $pdo->query("SELECT * FROM users WHERE account_status = 'active'")->fetchAll();
$leaderboard = [];
foreach ($citizens as $c) {
    $summary = compute_hero_summary($c);
    if ($c['role'] === 'Community Member' || $summary['reportedCount'] > 0) {
        $leaderboard[] = [
            'name' => $c['name'],
            'division' => $c['division'] ?: 'Kansanga (Makindye)',
            'points' => $summary['points'],
            'tier' => $summary['tier'],
            'reported' => $summary['reportedCount'],
            'resolved' => $summary['resolvedCount'],
        ];
    }
}
usort($leaderboard, fn($a, $b) => $b['points'] <=> $a['points']);
$leaderboard = array_slice($leaderboard, 0, 5);

$pageTitle = 'Public Transparency & Civic Ledger - FixMyRoad';
$activePage = 'public';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container stack-lg">
  <div class="page-header">
    <div>
      <div class="page-eyebrow">Open Municipal Accountability · Kansanga Pilot</div>
      <h1 class="page-title">Public Road &amp; Drainage Transparency Ledger</h1>
    </div>
    <!-- <a href="public_data.php?export=csv&status=<?= urlencode($statusFilter) ?>&category=<?= urlencode($categoryFilter) ?>&q=<?= urlencode($search) ?>"
       class="btn btn-success">
      Download Open Data CSV
    </a> -->
  </div>

  <!-- Summary Metrics -->
  <div class="grid-4">
    <div class="kpi-card">
      <div class="kpi-label">Logged Faults</div>
      <div class="kpi-value tabular-nums"><?= $totalCount ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Resolution Rate</div>
      <div class="kpi-value text-success tabular-nums"><?= $resolutionRate ?>%</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Active / Open</div>
      <div class="kpi-value text-warning tabular-nums"><?= $openCount ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Citizen Rating Avg</div>
      <div class="kpi-value text-primary tabular-nums"><?= e((string)$avgRating) ?> / 5</div>
    </div>
  </div>

  <div class="split-8-4" style="align-items: start;">
    <!-- Public Ledger Table -->
    <div class="stack-md">
      <form method="GET" class="filter-bar">
        <input name="q" type="text" value="<?= e($search) ?>" placeholder="Search street or ticket ID..." class="form-control">
        <select name="status" class="form-control">
          <option value="All">All Statuses</option>
          <?php foreach (['Pending', 'In Progress', 'Resolved'] as $st): ?>
            <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
        <select name="category" class="form-control">
          <option value="All">All Categories</option>
          <?php foreach (['Pothole', 'Blocked Drainage', 'Flooding', 'Damaged Road', 'Broken Streetlight', 'Other'] as $c): ?>
            <option value="<?= $c ?>" <?= $categoryFilter === $c ? 'selected' : '' ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-dark">Filter Ledger</button>
      </form>

      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Ticket</th>
              <th>Fault &amp; Location</th>
              <th>Status</th>
              <th style="text-align:right;">Endorsements</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($filtered as $r):
              $badgeCls = $r['status'] === 'Resolved' ? 'badge-resolved' : ($r['status'] === 'In Progress' ? 'badge-progress' : 'badge-pending');
            ?>
              <tr>
                <td class="text-mono font-bold text-primary">
                  <?php if ($user): ?>
                    <a href="track_report.php?id=<?= urlencode($r['id']) ?>"><?= e($r['id']) ?></a>
                  <?php else: ?>
                    <?= e($r['id']) ?>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="font-bold"><?= e($r['title']) ?></div>
                  <div class="text-xs text-muted"><?= e($r['location']) ?> · <?= e($r['category']) ?></div>
                </td>
                <td><span class="badge <?= $badgeCls ?>"><?= e($r['status']) ?></span></td>
                <td style="text-align:right;" class="font-bold tabular-nums"><?= (int)$r['upvotes'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Community Hero Leaderboard -->
    <div class="card stack-md">
      <div>
        <h2 class="section-title">Kansanga Community Hero Leaderboard</h2>
        <p class="text-xs text-muted">Top civic contributors verifying road &amp; drainage repairs.</p>
      </div>
      <div class="stack-sm">
        <?php foreach ($leaderboard as $idx => $entry): ?>
          <div class="card-muted row-between">
            <div>
              <div class="font-bold text-sm">#<?= $idx + 1 ?> · <?= e($entry['name']) ?></div>
              <div class="text-xs text-muted"><?= e($entry['tier']) ?> · <?= $entry['reported'] ?> reports</div>
            </div>
            <div style="text-align:right;">
              <div class="font-bold text-primary tabular-nums"><?= (int)$entry['points'] ?> pts</div>
              <div class="text-xs text-success tabular-nums"><?= $entry['resolved'] ?> resolved</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
