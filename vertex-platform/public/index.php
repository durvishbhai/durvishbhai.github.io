<?php

declare(strict_types=1);

/*
 * Web front controller. Only this directory (public/ or, on shared hosting, public_html/) is web-accessible.
 * Source checkout:   <app>/public/index.php          -> application root is the parent directory.
 * Hostinger package: public_html/index.php next to   -> application root is the sibling folder "vertex".
 * See docs/08-DEPLOYMENT.md.
 */

define('VERTEX_PUBLIC', __DIR__);

require (is_file(dirname(__DIR__) . '/bootstrap.php') ? dirname(__DIR__) : dirname(__DIR__) . '/vertex') . '/bootstrap.php';

use Vertex\Core\Request;
use Vertex\Http\Kernel;

Kernel::handle(Request::capture())->send();
