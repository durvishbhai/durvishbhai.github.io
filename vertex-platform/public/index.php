<?php

declare(strict_types=1);

/*
 * Web front controller. Only this directory (public/) should be web-accessible.
 * On cPanel/Hostinger either point the domain's document root at public/, or keep the app one level above
 * public_html and copy public/ into it (adjust the path below). See docs/DEPLOYMENT.md.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Vertex\Core\Request;
use Vertex\Http\Kernel;

Kernel::handle(Request::capture())->send();
