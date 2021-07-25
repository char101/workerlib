<?php

// maximum time between inactivity (redis expiration value)
// ini_set('session.gc_maxlifetime', 30 * 60);

if (!getenv('APP_ENV')) {
    echo "APP_ENV is not set\n";
    exit(1);
}

define('ENV', getenv('APP_ENV'));

define('DEVELOPMENT', ENV === 'development');
define('PRODUCTION', ENV === 'production');
define('UPSTREAM', ENV === 'upstream');
define('TESTING', ENV === 'testing');
