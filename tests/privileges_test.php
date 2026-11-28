<?php
session_start();
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/i18n.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    if ($ok) {
        echo "OK  $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n";
}

$roles = role_privileges();
check(array_keys($roles) === ['Community Member', 'Local Council', 'Field Officer', 'Supervisor', 'Page Moderator', 'Administrator'], 'six roles');
check(in_array('view_parish_reports', $roles['Local Council'], true), 'local council sees parish reports');
check(!in_array('manage_all_reports', $roles['Local Council'], true), 'local council cannot dispatch');
check(in_array('moderate_comments', $roles['Local Council'], true), 'local council can hide and restore comments');
check(role_home('Local Council') === 'dashboard.php?scope=parish', 'local council home');
check(!in_array('change_user_roles', $roles['Supervisor'], true), 'supervisor cannot change roles');
check(in_array('assign_officers', $roles['Supervisor'], true), 'supervisor can assign officers');
check(in_array('manage_notices', $roles['Page Moderator'], true), 'moderator manages notices');
check(!in_array('manage_all_reports', $roles['Page Moderator'], true), 'moderator cannot dispatch');
check(!in_array('change_user_roles', $roles['Community Member'], true), 'citizen cannot change roles');
check(!in_array('manage_all_reports', $roles['Field Officer'], true), 'officer cannot dispatch all reports');
check(in_array('change_user_roles', $roles['Administrator'], true), 'admin can change roles');
check(user_can(['role' => 'Community Member'], 'report_fault'), 'citizen can report');
check(!user_can(['role' => 'Community Member'], 'access_admin_dashboard'), 'citizen blocked from admin');
check(tabs_for_role('Page Moderator') === ['notices'], 'moderator tabs');
check(!action_allowed_for_role('Supervisor', 'delete_user'), 'supervisor cannot delete users');
check(kansanga_zone('Kiwafu Road, Kansanga') === 'Kiwafu', 'kiwafu zone');
check(kansanga_zone('Kansanga Junction (Ggaba Rd)') === 'Kansanga Junction', 'junction before ggaba');
check(kansanga_zone('Somewhere in Kansanga') === 'Other Kansanga', 'other zone');
check(kansanga_pin_inside(0.2835, 32.6095), 'center pin inside');
check(!kansanga_pin_inside(0.3136, 32.5825), 'kampala city centre outside');
check(demo_password_needs_reset('!'), 'broken demo hash resets');
check(demo_password_needs_reset('SEED_PLAIN:password123'), 'seed hash resets');
check(!demo_password_needs_reset('$2y$10$abcdefghijklmnopqrstuv'), 'real hash kept');

$_SESSION['lang'] = 'lg';
check(!luganda_enabled(['luganda_enabled' => '0']), 'luganda off by default');
apply_language_choice(['luganda_enabled' => '0']);
check(($_SESSION['lang'] ?? '') === 'en', 'disabled luganda forces English');
$_GET['lang'] = 'lg';
apply_language_choice(['luganda_enabled' => '1']);
check($_SESSION['lang'] === 'lg', 'admin can activate luganda');
check(t('signin_title') === 'Yingira mu FixMyRoad', 'luganda sign in');
check(t_status('In Progress') === 'Kiriko okukolebwa', 'luganda status');
check(t_category('Blocked Drainage') === 'Omugga guzibikidde', 'luganda category');
check(t_notice('Emergency') === 'Obwangu', 'luganda notice');
$_SESSION['lang'] = 'en';

$jpeg = "\xFF\xD8\xFF\xE1\x00\x0AEXIFDATA\xFF\xE0\x00\x04JF\xFF\xDAHI\xFF\xD9";
$src = tempnam(sys_get_temp_dir(), 'jpg');
$dest = tempnam(sys_get_temp_dir(), 'out');
file_put_contents($src, $jpeg);
check(strip_jpeg_metadata($src, $dest), 'jpeg metadata strip runs');
$stripped = file_get_contents($dest);
check(str_starts_with($stripped, "\xFF\xD8"), 'jpeg still starts');
check(!str_contains($stripped, 'EXIFDATA'), 'exif removed without GD');
check(str_contains($stripped, "\xFF\xD9"), 'jpeg still ends');
unlink($src);
unlink($dest);

exit($failures === 0 ? 0 : 1);
