<?php
/**
 * The only web entry point. Apache's document root must point to this public/ folder.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

Omps\Core\App::instance()->handleHttp();
