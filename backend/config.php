<?php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'moq_shipping');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_DEBUG', true);
define('APP_TIMEZONE', 'Asia/Shanghai');

date_default_timezone_set(APP_TIMEZONE);

define('API_BASE_PATH', '/api');
define('CARRIER_DEFAULT', '顺丰速运');
define('ORDER_PREFIX', 'MOQ');
define('SHIPPING_PREFIX', 'SF');
