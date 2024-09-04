<?php
// Please set this helper script directory
if (!defined('ENV_HELPER_URL'))  define('ENV_HELPER_URL',  'http://127.0.0.1/currency');
if (!defined('ENV_HELPER_PATH')) define('ENV_HELPER_PATH', '/var/www/html/currency');

// Configuration for Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'database_name');
define('DB_USER', 'db_user_name');
define('DB_PASS', 'db_password');

define('SECRET_KEY', '123456789');

define('SYSURL', ENV_HELPER_URL);

// Other configurations
define('USE_CURRENCY_SERVER', true);
define('ENV_HELPER_PATH', __DIR__ . '/currency');

define('UUID_ZERO',    '00000000-0000-0000-0000-000000000000');
