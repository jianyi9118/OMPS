<?php
return array(
    'host' => (string) env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => (string) env('DB_DATABASE', 'omps'),
    // Day-to-day account: SELECT / INSERT / UPDATE / DELETE only (design §03).
    'username' => (string) env('DB_USERNAME', 'omps_app'),
    'password' => (string) env('DB_PASSWORD', ''),
    // Migration account: may create and alter tables; used only by `php omps migrate`.
    'migrate_username' => (string) env('DB_MIGRATE_USERNAME', (string) env('DB_USERNAME', 'omps_app')),
    'migrate_password' => (string) env('DB_MIGRATE_PASSWORD', (string) env('DB_PASSWORD', '')),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    // Fixed on every connection so MariaDB 10.1 and 10.2+ / MySQL behave the same.
    'sql_mode' => 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
);
