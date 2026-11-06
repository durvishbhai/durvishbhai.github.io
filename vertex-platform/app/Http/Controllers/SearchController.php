<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\RateLimiter;
use Vertex\Services\SearchService;

final class SearchController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $q = trim(mb_substr($request->string('q'), 0, 100));
        $groups = [];
        if (mb_strlen($q) >= SearchService::MIN_LENGTH && !RateLimiter::tooMany('search_user', (string) $this->actorId())) {
            RateLimiter::hit('search_user', (string) $this->actorId());
            $groups = SearchService::search($this->actorId(), $q);
        }

        return $this->page('pages/search', ['title' => 'Search', 'q' => $q, 'groups' => $groups, 'tooShort' => $q !== '' && mb_strlen($q) < SearchService::MIN_LENGTH]);
    }
}
