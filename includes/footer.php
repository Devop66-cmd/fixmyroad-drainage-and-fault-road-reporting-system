<?php
$portalSettings = get_portal_settings();
?>
</main>

<footer class="site-footer">
  <div class="footer-grid">
    <div class="stack-sm">
      <div class="font-bold" style="color:#ffffff; font-size:14px;">FixMyRoad · Kansanga Pilot</div>
      <p style="color:#94a3b8;">
        Road &amp; Drainage Fault Reporting System serving Kansanga, Makindye Division, Kampala.
      </p>
    </div>

    <div class="stack-sm">
      <div class="footer-title">Works Hotline &amp; Support</div>
      <div>Toll-Free: <span class="text-mono font-bold" style="color:#34d399;"><?= e($portalSettings['support_toll_free']) ?></span></div>
      <div>City Support: <span class="text-mono"><?= e($portalSettings['support_city_line']) ?></span></div>
      <div>Dispatch Email: <span class="text-mono"><?= e($portalSettings['support_dispatch_email']) ?></span></div>
    </div>

    <div class="stack-sm">
      <div class="footer-title">Portal Quick Links</div>
      <div class="footer-links">
        <a href="public_data.php">Public Transparency</a>
        <a href="map_alerts.php">Live Kansanga Map</a>
        <a href="report_fault.php">Report a Fault</a>
      </div>
      <div style="color:#64748b; padding-top:6px;">&copy; <?= date('Y') ?> FixMyRoad Municipal Pilot</div>
    </div>
  </div>
</footer>

<script src="js/app.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</body>
</html>
