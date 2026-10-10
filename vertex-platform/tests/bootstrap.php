<?php

declare(strict_types=1);

// Test bootstrap: no ErrorHandler/session/web kernel; PHPUnit owns error handling.
define('VERTEX_ROOT', dirname(__DIR__));
putenv('VERTEX_ENV_FILE=.env.testing');

require VERTEX_ROOT . '/vendor/autoload.php';

use Vertex\Core\Config;
use Vertex\Core\Env;

Env::load(VERTEX_ROOT . '/.env.testing');
Config::load(VERTEX_ROOT . '/config');
date_default_timezone_set('UTC');
