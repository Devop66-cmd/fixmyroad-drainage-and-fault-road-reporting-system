<?php
function t(string $key): string {
    $lang = $_SESSION['lang'] ?? 'en';
    $lg = [
        'home' => 'Awaka',
        'report' => 'Loopa obuzibu',
        'public' => 'Ebikwata ku bantu',
        'signin' => 'Yingira',
        'register' => 'Wewandiise',
        'signout' => 'Fuluma',
    ];
    $en = [
        'home' => 'Home',
        'report' => 'Report Fault',
        'public' => 'Public Data',
        'signin' => 'Sign In',
        'register' => 'Register',
        'signout' => 'Sign Out',
    ];
    $dict = $lang === 'lg' ? $lg : $en;
    return $dict[$key] ?? $key;
}
