<?php
declare(strict_types=1);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Bangkok');

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
if (!empty($_SERVER['HTTPS'])) {
    ini_set('session.cookie_secure', '1');
}

session_start();

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/totp.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/scoring.php';
