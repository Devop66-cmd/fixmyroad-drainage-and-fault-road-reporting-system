<?php
/**
 * CLI SLA sweep. Example cron (every 6 hours):
 * 0 *\/6 * * * php /path/to/scripts/sla_sweep.php
 */
require_once __DIR__ . '/../includes/auth.php';
$count = run_sla_sweep('Cron', 'System');
echo "Escalated $count report(s)\n";
