<?php
return array(
    'session_cookie' => (string) env('SESSION_COOKIE', 'omps_sid'),
    'csrf_cookie' => 'omps_csrf',
    'lang_cookie' => 'omps_lang',
    // Cookies get the Secure flag when the request is HTTPS, or always when forced.
    'force_secure_cookie' => (bool) env('SESSION_SECURE', false),
    // Defaults; the super admin can change these in 系统设置 (stored in system_settings).
    'defaults' => array(
        'login_max_failures' => 5,
        'login_lock_minutes' => 15,
        'session_idle_minutes' => 120,
        'session_absolute_hours' => 12,
        'password_min_length' => 8,
    ),
    // Per-IP brute-force limit: failures within the window before login is refused.
    'ip_max_failures' => 20,
    'ip_window_minutes' => 15,
);
