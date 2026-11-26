<?php
function t(string $key): string {
    $lang = $_SESSION['lang'] ?? 'en';
    $en = [
        'home' => 'Home',
        'report' => 'Report Fault',
        'public' => 'Public Data',
        'signin' => 'Sign In',
        'register' => 'Register',
        'signout' => 'Sign Out',
        'signin_title' => 'Sign In to FixMyRoad',
        'signin_sub' => 'Kansanga Road and Drainage Fault Reporting Portal',
        'email' => 'Email Address',
        'password' => 'Password',
        'forgot' => 'Forgot password?',
        'new_resident' => 'New resident in Kansanga?',
        'create_account' => 'Create Citizen Account',
        'street' => 'Street / Landmark (Kansanga)',
        'street_help' => 'Pilot area only: Kansanga, Makindye Division, Kampala. Pins outside the blue box are rejected.',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'description' => 'Detailed Description',
        'photo' => 'Evidence Photo (JPG / PNG / WebP, max 5MB)',
        'pin_label' => 'Pin Fault Location (Click Map or Choose Kansanga Hotspot)',
        'submit_report' => 'Submit Fault Report',
        'title' => 'Fault Title',
        'category' => 'Category',
        'priority' => 'Priority',
        'notices' => 'Official Notices',
        'status_pending' => 'Pending',
        'status_in_progress' => 'In Progress',
        'status_resolved' => 'Resolved',
        'status_rejected' => 'Rejected',
        'cat_pothole' => 'Pothole',
        'cat_blocked_drainage' => 'Blocked Drainage',
        'cat_flooding' => 'Flooding',
        'cat_damaged_road' => 'Damaged Road',
        'cat_broken_streetlight' => 'Broken Streetlight',
        'cat_other' => 'Other',
        'notice_emergency' => 'Emergency',
        'notice_maintenance' => 'Maintenance',
        'notice_general' => 'General Announcement',
        'parish_feed' => 'Parish feed',
        'my_reports' => 'My reports',
        'hide_comment' => 'Hide comment',
        'restore_comment' => 'Restore comment',
        'hidden_note' => 'Hidden from residents.',
    ];
    $lg = [
        'home' => 'Awaka',
        'report' => 'Loopa obuzibu',
        'public' => 'Ebikwata ku bantu',
        'signin' => 'Yingira',
        'register' => 'Wewandiise',
        'signout' => 'Fuluma',
        'signin_title' => 'Yingira mu FixMyRoad',
        'signin_sub' => 'Ekisenge kya Kansanga eky\'obubaka bw\'enguudo n\'emigga',
        'email' => 'Email',
        'password' => 'Ekigambo ky\'okuyingira',
        'forgot' => 'Werabidde ekigambo ky\'okuyingira?',
        'new_resident' => 'Omuwumiriza omupya mu Kansanga?',
        'create_account' => 'Kola akawunti',
        'street' => 'Oluguudo / Ekifo (Kansanga)',
        'street_help' => 'Kati luno lukozesa Kansanga, Makindye, Kampala yokka. Ekifo ekitali mu bbokisi eya bbulu tekikkirizibwa.',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'description' => 'Ennyonyola ennyonvu',
        'photo' => 'Ekifaananyi (JPG / PNG / WebP, si kisukka 5MB)',
        'pin_label' => 'Teeka akabonero k\'ekifo ku maapu',
        'submit_report' => 'Weereza alipoota',
        'title' => 'Omutwe gw\'obuzibu',
        'category' => 'Ekika',
        'priority' => 'Obukulu',
        'notices' => 'Obubaka obw\'ekitongole',
        'status_pending' => 'Kilindiridde',
        'status_in_progress' => 'Kiriko okukolebwa',
        'status_resolved' => 'Kigoloddwa',
        'status_rejected' => 'Kiganiddwa',
        'cat_pothole' => 'Ekinnya mu luguudo',
        'cat_blocked_drainage' => 'Omugga guzibikidde',
        'cat_flooding' => 'Amazzi amangi',
        'cat_damaged_road' => 'Oluguudo lwonoonese',
        'cat_broken_streetlight' => 'Ettaala y\'oluguudo ekoonye',
        'cat_other' => 'Ekirala',
        'notice_emergency' => 'Obwangu',
        'notice_maintenance' => 'Okuddaabiriza',
        'notice_general' => 'Obubaka obwa bulijjo',
        'parish_feed' => 'Ebifa mu parish',
        'my_reports' => 'Alipoota zange',
        'hide_comment' => 'Kweka ekirowoozo',
        'restore_comment' => 'Zzaayo ekirowoozo',
        'hidden_note' => 'Kikwekeddwa abatuuze.',
    ];
    $dict = $lang === 'lg' ? $lg : $en;
    return $dict[$key] ?? ($en[$key] ?? $key);
}

function t_status(string $status): string {
    return t('status_' . strtolower(str_replace(' ', '_', $status)));
}

function t_category(string $category): string {
    $key = 'cat_' . strtolower(str_replace(' ', '_', $category));
    $label = t($key);
    return $label === $key ? $category : $label;
}

function t_notice(string $category): string {
    return match ($category) {
        'Emergency' => t('notice_emergency'),
        'Maintenance' => t('notice_maintenance'),
        'General Announcement' => t('notice_general'),
        default => $category,
    };
}
