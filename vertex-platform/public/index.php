<?php

declare(strict_types=1);

/*
 * Web front controller. Only this directory (public/ or, on shared hosting, public_html/) is web-accessible.
 * Source checkout:   <app>/public/index.php          -> application root is the parent directory.
 * Hostinger package: public_html/index.php next to   -> application root is the sibling folder "vertex".
 * See docs/08-DEPLOYMENT.md.
 */

define('VERTEX_PUBLIC', __DIR__);

// Application folder: the parent of public/ (source checkout), a "vertex" folder inside this one (single-folder upload), or a sibling "vertex" folder.
$app = is_file(dirname(__DIR__) . '/bootstrap.php') ? dirname(__DIR__) : (is_file(__DIR__ . '/vertex/bootstrap.php') ? __DIR__ . '/vertex' : dirname(__DIR__) . '/vertex');

require $app . '/bootstrap.php';

use Vertex\Core\Request;
use Vertex\Http\Kernel;

Kernel::handle(Request::capture())->send();
