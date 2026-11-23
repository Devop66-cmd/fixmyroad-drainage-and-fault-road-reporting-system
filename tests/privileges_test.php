<?php
require_once __DIR__ . '/../includes/helpers.php';

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
check(array_keys($roles) === ['Community Member', 'Field Officer', 'Administrator'], 'three roles');
check(!in_array('change_user_roles', $roles['Community Member'], true), 'citizen cannot change roles');
check(!in_array('manage_all_reports', $roles['Field Officer'], true), 'officer cannot dispatch all reports');
check(in_array('change_user_roles', $roles['Administrator'], true), 'admin can change roles');
check(in_array('moderate_comments', $roles['Field Officer'], true), 'officer can moderate comments');
check(user_can(['role' => 'Community Member'], 'report_fault'), 'citizen can report');
check(!user_can(['role' => 'Community Member'], 'access_admin_dashboard'), 'citizen blocked from admin');
check(in_array('bulk_update_reports', $roles['Administrator'], true), 'admin bulk update');

exit($failures === 0 ? 0 : 1);
