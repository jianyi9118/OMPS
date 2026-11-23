<?php
return array(
    'name' => 'OMPS',
    // production | development | testing
    'env' => (string) env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'timezone' => (string) env('APP_TIMEZONE', 'Asia/Shanghai'),
    // Base path when the app is not at the web root, e.g. /omps. Empty = auto-detect.
    'base_path' => (string) env('APP_BASE_PATH', ''),
    // auto | on | off. auto = use pretty URLs when .htaccess reports mod_rewrite.
    'rewrite' => (string) env('APP_REWRITE', 'auto'),
    'storage_path' => (string) env('APP_STORAGE_PATH', OMPS_ROOT . '/storage'),
    // Uploaded files, in folders that mirror the platform (design §11). Production: e.g. D:/OMPS/files
    'files_path' => (string) env('FILES_PATH', OMPS_ROOT . '/storage/files'),
    // backups (database + files, phase 9); outside the web root, ideally on another disk or a NAS
    'backup_path' => (string) env('BACKUP_PATH', OMPS_ROOT . '/storage/backups'),
    // Largest upload in MB (php.ini upload_max_filesize / post_max_size must allow it too)
    'upload_max_mb' => (int) env('UPLOAD_MAX_MB', 50),
    'org_id' => 1,
    'version' => '0.9.0',
    'base_language' => 'zh-CN',
);
