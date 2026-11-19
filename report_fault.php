<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $title = trim((string)($_POST['title'] ?? ''));
    $category = trim((string)($_POST['category'] ?? 'Pothole'));
    $priority = trim((string)($_POST['priority'] ?? 'Medium'));
    $location = trim((string)($_POST['location'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $lat = is_numeric($_POST['lat'] ?? null) ? (float)$_POST['lat'] : null;
    $lng = is_numeric($_POST['lng'] ?? null) ? (float)$_POST['lng'] : null;

    $validCategories = ['Pothole', 'Blocked Drainage', 'Flooding', 'Damaged Road', 'Broken Streetlight', 'Other'];
    $validPriorities = ['Urgent', 'High', 'Medium', 'Low'];
    if (!in_array($category, $validCategories, true)) $category = 'Pothole';
    if (!in_array($priority, $validPriorities, true)) $priority = 'Medium';

    if (strlen($title) < 4 || strlen($description) < 8 || strlen($location) < 4) {
        $error = 'Please fill in a clear title, location inside Kansanga, and detailed fault description.';
    } else {
        $geo = validate_kansanga_boundary($lat, $lng, $location);
        if (!$geo['valid']) {
            $error = $geo['reason'];
        } else {
            $maxNum = 123;
            foreach ($pdo->query('SELECT id FROM reports')->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                if (preg_match('/(\d+)$/', $rid, $m)) {
                    $maxNum = max($maxNum, (int)$m[1]);
                }
            }
            $newId = '#RPT-' . str_pad((string)($maxNum + 1), 5, '0', STR_PAD_LEFT);
            $nowLabel = date('Y-m-d H:i');
            $slaDeadline = compute_sla_deadline($nowLabel, $priority);
            $photoPath = handle_photo_upload('photo');

            $ins = $pdo->prepare(
                "INSERT INTO reports (id, title, category, description, location, area, lat, lng, priority, status, reported_by, reported_by_email, report_date, sla_deadline, upvotes, before_photo)
                 VALUES (?, ?, ?, ?, ?, 'Kansanga (Makindye)', ?, ?, ?, 'Pending', ?, ?, ?, ?, 1, ?)"
            );
            $ins->execute([
                $newId,
                $title,
                $category,
                $description,
                $location,
                $lat ?? KANSANGA_CENTER_LAT,
                $lng ?? KANSANGA_CENTER_LNG,
                $priority,
                $user['name'],
                $user['email'],
                $nowLabel,
                $slaDeadline,
                $photoPath,
            ]);

            $pdo->prepare('INSERT INTO report_upvotes (report_id, user_id) VALUES (?, ?)')->execute([$newId, $user['id']]);
            $pdo->prepare("INSERT INTO report_updates (report_id, timestamp_label, text, author, update_type) VALUES (?, ?, ?, ?, 'verification')")
                ->execute([$newId, $nowLabel, 'Fault report submitted and verified within Kansanga pilot boundary.', $user['name']]);

            create_notification(
                "New Fault Reported ($newId)",
                "$category reported at $location ($priority priority).",
                'info',
                'Administrator',
                $newId
            );
            log_audit($user['name'], $user['role'], 'Submitted Fault Report', $newId, "$category at $location ($priority).", 'report');

            flash_set('success', "Fault report $newId submitted! (+10 Hero Points awarded)");
            redirect_to('track_report.php?id=' . urlencode($newId));
        }
    }
}

$pageTitle = 'Report a Fault - FixMyRoad';
$activePage = 'report';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-medium stack-md">
  <div class="page-header">
    <div>
      <h1 class="page-title">Report a Road or Drainage Fault</h1>
      <p class="page-subtitle">Active pilot geofence: Kansanga, Makindye Division, Kampala only.</p>
    </div>
    <a href="dashboard.php" class="btn btn-outline">← Back to Dashboard</a>
  </div>

  <?php if ($error): ?>
    <div role="alert" class="alert alert-error"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="card stack-md">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <div class="form-group">
      <label class="form-label">Fault Title</label>
      <input name="title" type="text" required minlength="4" value="<?= e($_POST['title'] ?? '') ?>"
             placeholder="e.g., Collapsed culvert edge on Kiwafu Road" class="form-control">
    </div>

    <div class="grid-2">
      <div class="form-group">
        <label class="form-label">Fault Category</label>
        <select name="category" class="form-control">
          <?php foreach (['Pothole', 'Blocked Drainage', 'Flooding', 'Damaged Road', 'Broken Streetlight', 'Other'] as $cat): ?>
            <option value="<?= e($cat) ?>" <?= ($_POST['category'] ?? '') === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Severity / Priority</label>
        <select name="priority" class="form-control">
          <?php foreach (['Medium', 'High', 'Urgent', 'Low'] as $prio): ?>
            <option value="<?= e($prio) ?>" <?= ($_POST['priority'] ?? 'Medium') === $prio ? 'selected' : '' ?>><?= e($prio) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Interactive Kansanga Map Picker -->
    <div class="stack-sm">
      <div class="row-between">
        <label class="form-label" style="margin:0;">Pin Fault Location (Click Map or Choose Kansanga Hotspot)</label>
        <div class="flex-wrap-gap">
          <button type="button" onclick="setPin(0.2835, 32.6095, 'Kansanga Junction (Ggaba Rd), Makindye')" class="btn btn-outline btn-sm">Kansanga Junction</button>
          <button type="button" onclick="setPin(0.2869, 32.6078, 'Kiwafu Road, Kansanga, Makindye')" class="btn btn-outline btn-sm">Kiwafu Road</button>
          <button type="button" onclick="setPin(0.2848, 32.6122, 'Nabutiti Lane, Kansanga, Makindye')" class="btn btn-outline btn-sm">Nabutiti Market</button>
          <button type="button" onclick="setPin(0.2814, 32.6109, 'KIU Gate (Ggaba Rd), Kansanga, Makindye')" class="btn btn-outline btn-sm">KIU Gate</button>
        </div>
      </div>

      <div id="faultMap" class="map-box"></div>

      <div class="grid-3">
        <div>
          <label class="form-label">Street / Landmark (Kansanga)</label>
          <input id="locationInput" name="location" type="text" required
                 value="<?= e($_POST['location'] ?? 'Kansanga Junction (Ggaba Rd), Makindye Division') ?>" class="form-control">
        </div>
        <div>
          <label class="form-label">Latitude</label>
          <input id="latInput" name="lat" type="number" step="0.000001" required
                 value="<?= e($_POST['lat'] ?? '0.283500') ?>" class="form-control text-mono">
        </div>
        <div>
          <label class="form-label">Longitude</label>
          <input id="lngInput" name="lng" type="number" step="0.000001" required
                 value="<?= e($_POST['lng'] ?? '32.609500') ?>" class="form-control text-mono">
        </div>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Detailed Description</label>
      <textarea name="description" rows="4" required minlength="8"
                placeholder="Describe hazard depth, traffic obstruction, or water stagnation..."
                class="form-control"><?= e($_POST['description'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
      <label class="form-label">Evidence Photo (JPG / PNG / WebP, max 5MB)</label>
      <input name="photo" type="file" accept="image/jpeg,image/png,image/webp" class="form-control">
    </div>

    <div class="row-between" style="padding-top:12px; border-top:1px solid var(--border); justify-content:flex-end;">
      <a href="dashboard.php" class="btn btn-outline">Cancel</a>
      <button type="submit" class="btn btn-primary">Submit Fault Report</button>
    </div>
  </form>
</div>

<script>
window.addEventListener('DOMContentLoaded', () => {
  window.setPin = function(lat, lng, label) {
    document.getElementById('latInput').value = lat.toFixed(6);
    document.getElementById('lngInput').value = lng.toFixed(6);
    document.getElementById('locationInput').value = label;
    if (window._faultMarker && window._faultMap) {
      window._faultMarker.setLatLng([lat, lng]);
      window._faultMap.setView([lat, lng], 16);
    }
  };
  if (typeof L === 'undefined') return;
  const lat0 = parseFloat(document.getElementById('latInput').value) || 0.2835;
  const lng0 = parseFloat(document.getElementById('lngInput').value) || 32.6095;
  const map = L.map('faultMap').setView([lat0, lng0], 15);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(map);
  L.rectangle([[0.272, 32.594], [0.298, 32.624]], { color: '#2563eb', weight: 1.5, fillOpacity: 0.06 }).addTo(map);
  const marker = L.marker([lat0, lng0], { draggable: true }).addTo(map);
  window._faultMap = map;
  window._faultMarker = marker;
  marker.on('dragend', () => {
    const p = marker.getLatLng();
    document.getElementById('latInput').value = p.lat.toFixed(6);
    document.getElementById('lngInput').value = p.lng.toFixed(6);
  });
  map.on('click', (e) => {
    marker.setLatLng(e.latlng);
    document.getElementById('latInput').value = e.latlng.lat.toFixed(6);
    document.getElementById('lngInput').value = e.latlng.lng.toFixed(6);
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
