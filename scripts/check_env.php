<?php
/**
 * Environment self-check (same as "php omps env:check"), e.g. for Windows Task Scheduler health checks.
 */
require dirname(__DIR__) . '/app/bootstrap.php';
exit(Omps\Console\EnvCheck::run());
