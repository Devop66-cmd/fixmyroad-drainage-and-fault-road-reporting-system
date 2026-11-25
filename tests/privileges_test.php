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
check(array_keys($roles) === ['Community Member', 'Field Officer', 'Supervisor', 'Page Moderator', 'Administrator'], 'five roles');
check(!in_array('change_user_roles', $roles['Supervisor'], true), 'supervisor cannot change roles');
check(in_array('assign_officers', $roles['Supervisor'], true), 'supervisor can assign officers');
check(in_array('manage_notices', $roles['Page Moderator'], true), 'moderator manages notices');
check(!in_array('manage_all_reports', $roles['Page Moderator'], true), 'moderator cannot dispatch');
check(!in_array('change_user_roles', $roles['Community Member'], true), 'citizen cannot change roles');
check(!in_array('manage_all_reports', $roles['Field Officer'], true), 'officer cannot dispatch all reports');
check(in_array('change_user_roles', $roles['Administrator'], true), 'admin can change roles');
check(in_array('moderate_comments', $roles['Field Officer'], true), 'officer can moderate comments');
check(in_array('moderate_comments', $roles['Page Moderator'], true), 'page moderator can hide comments');
check(user_can(['role' => 'Community Member'], 'report_fault'), 'citizen can report');
check(!user_can(['role' => 'Community Member'], 'access_admin_dashboard'), 'citizen blocked from admin');
check(user_can(['role' => 'Supervisor'], 'access_admin_dashboard'), 'supervisor opens desk');
check(in_array('bulk_update_reports', $roles['Administrator'], true), 'admin bulk update');
check(tabs_for_role('Page Moderator') === ['notices'], 'moderator tabs');
check(!action_allowed_for_role('Supervisor', 'delete_user'), 'supervisor cannot delete users');
check(action_allowed_for_role('Page Moderator', 'create_notice'), 'moderator can publish notices');

exit($failures === 0 ? 0 : 1);
