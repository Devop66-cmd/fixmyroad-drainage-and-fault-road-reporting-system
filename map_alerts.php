<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $nid = (int)($_POST['notif_id'] ?? 0);
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?')->execute([$nid]);
        redirect_to('map_alerts.php');
    }
    if ($action === 'mark_all_read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE target_role = 'All' OR target_role = ?")
            ->execute([$user['role']]);
        flash_set('success', 'All alerts marked as read.');
        redirect_to('map_alerts.php');
    }
}

$statusFilter = trim((string)($_GET['status'] ?? 'All'));
$reports = $pdo->query('SELECT * FROM reports ORDER BY created_at DESC')->fetchAll();
$visibleReports = $statusFilter === 'All'
    ? $reports
    : array_values(array_filter($reports, fn($r) => $r['status'] === $statusFilter));

$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE target_role = 'All' OR target_role = ? ORDER BY created_at DESC LIMIT 30");
$notifStmt->execute([$user['role']]);
$notifications = $notifStmt->fetchAll();

$mapMarkers = array_map(fn($r) => [
    'id' => $r['id'],
    'title' => $r['title'],
    'category' => $r['category'],
    'status' => $r['status'],
    'priority' => $r['priority'],
    'location' => $r['location'],
    'lat' => (float)$r['lat'],
    'lng' => (float)$r['lng'],
    'escalated' => (int)$r['escalated'] === 1,
], $visibleReports);

$pageTitle = 'Live Kansanga Map & Alerts - FixMyRoad';
$activePage = 'map';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container stack-lg">
  <div class="page-header">
    <div>
      <div class="page-eyebrow">Kansanga Geofence · Makindye Division</div>
      <h1 class="page-title">Live Fault Map &amp; Dispatch Alerts</h1>
    </div>
    <div class="flex-wrap-gap">
      <?php foreach (['All', 'Pending', 'In Progress', 'Resolved'] as $st): ?>
        <a href="map_alerts.php?status=<?= urlencode($st) ?>"
           class="btn <?= $statusFilter === $st ? 'btn-dark' : 'btn-outline' ?>">
          <?= e($st) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="split-8-4" style="align-items: start;">
    <!-- Leaflet Map -->
    <div class="card stack-sm">
      <div id="kansangaLiveMap" class="map-box-tall"></div>
      <div class="row-between text-xs text-muted">
        <div class="flex-wrap-gap font-bold">
          <span><span class="map-dot" style="background:#d97706;"></span>Pending</span>
          <span><span class="map-dot" style="background:#2563eb;"></span>In Progress</span>
          <span><span class="map-dot" style="background:#059669;"></span>Resolved</span>
          <span><span class="map-dot" style="background:#e11d48;"></span>Escalated</span>
        </div>
        <div class="tabular-nums font-bold">Showing <?= count($visibleReports) ?> Kansanga pins</div>
      </div>
    </div>

    <!-- Alert & Notification Inbox -->
    <div class="card stack-md">
      <div class="row-between">
        <h2 class="section-title">Dispatch &amp; Citizen Alerts</h2>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="mark_all_read">
          <button type="submit" class="text-xs font-bold text-primary" style="background:none; border:none; cursor:pointer;">Mark All Read</button>
        </form>
      </div>

      <div id="livePollNote" class="alert alert-info hidden text-xs">
        New municipal updates detected — <a href="map_alerts.php" class="font-bold">refresh view</a>.
      </div>

      <?php if (empty($notifications)): ?>
        <p class="text-xs text-muted" style="text-align:center; padding:24px 0;">No alerts in your inbox.</p>
      <?php else: ?>
        <div class="stack-sm" style="max-height:400px; overflow-y:auto;">
          <?php foreach ($notifications as $n): ?>
            <div class="<?= (int)$n['is_read'] === 0 ? 'alert alert-info' : 'card-muted' ?> stack-sm">
              <div class="row-between" style="align-items:flex-start;">
                <strong class="text-xs" style="color:var(--text-main);"><?= e($n['title']) ?></strong>
                <?php if ((int)$n['is_read'] === 0): ?>
                  <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="mark_read">
                    <input type="hidden" name="notif_id" value="<?= (int)$n['id'] ?>">
                    <button type="submit" class="text-xs font-bold text-primary" style="background:none; border:none; cursor:pointer;">Done</button>
                  </form>
                <?php endif; ?>
              </div>
              <p class="text-xs"><?= e($n['message']) ?></p>
              <div class="row-between text-xs text-muted">
                <span><?= e($n['created_at']) ?></span>
                <?php if (!empty($n['report_id'])): ?>
                  <a href="track_report.php?id=<?= urlencode($n['report_id']) ?>" class="font-bold text-primary">View Ticket →</a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', () => {
  if (typeof L !== 'undefined') {
    const map = L.map('kansangaLiveMap').setView([<?= KANSANGA_CENTER_LAT ?>, <?= KANSANGA_CENTER_LNG ?>], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
    L.rectangle([[<?= KANSANGA_MIN_LAT ?>, <?= KANSANGA_MIN_LNG ?>], [<?= KANSANGA_MAX_LAT ?>, <?= KANSANGA_MAX_LNG ?>]], {
      color: '#2563eb', weight: 1.5, fillOpacity: 0.04, dashArray: '5,5'
    }).addTo(map);

    const markers = <?= json_encode($mapMarkers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    markers.forEach((m) => {
      const color = m.escalated && m.status !== 'Resolved' ? '#e11d48'
        : m.status === 'Resolved' ? '#059669'
        : m.status === 'In Progress' ? '#2563eb' : '#d97706';
      const dot = L.circleMarker([m.lat, m.lng], {
        radius: 9, color: '#ffffff', weight: 2, fillColor: color, fillOpacity: 0.92
      }).addTo(map);
      const safeTitle = m.title.replace(/</g, '&lt;');
      const safeLoc = m.location.replace(/</g, '&lt;');
      dot.bindPopup(
        `<div style="font-size:12px;min-width:180px;">` +
        `<div style="font-weight:800;color:#0f172a;">${m.id} · ${m.status}</div>` +
        `<div style="font-weight:700;margin-top:2px;">${safeTitle}</div>` +
        `<div style="color:#64748b;margin-top:2px;">${safeLoc}</div>` +
        `<a href="track_report.php?id=${encodeURIComponent(m.id)}" style="display:inline-block;margin-top:6px;color:#2563eb;font-weight:700;">Open Ticket →</a>` +
        `</div>`
      );
    });
  }

  const initialUnread = <?= (int)$unreadCount ?>;
  setInterval(async () => {
    try {
      const res = await fetch('api/poll_updates.php');
      if (!res.ok) return;
      const data = await res.json();
      if (typeof data.unread_notifications_count === 'number' && data.unread_notifications_count > initialUnread) {
        document.getElementById('livePollNote').classList.remove('hidden');
      }
    } catch (_) {}
  }, 15000);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
