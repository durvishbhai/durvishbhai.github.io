<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\CmsService;
use Vertex\Services\Settings;

final class HomeController
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        try {
            $page = Settings::feature('cms') ? CmsService::findLive('home') : null; // an editable front page replaces the built-in one once published
        } catch (\Throwable) {
            $page = null; // the website tables do not exist yet (not migrated)
        }
        if ($page !== null) {
            return PublicPageController::render($page);
        }

        return (new SitePageController())->home($request, $params);
    }
}
