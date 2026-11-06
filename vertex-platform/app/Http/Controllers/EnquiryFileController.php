<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\EnquiryExtras;
use Vertex\Services\EnquiryPolicy;
use Vertex\Services\FileStore;

/** Attachments a visitor added to a project request. Only people who may open that enquiry can download them, always as a forced download. */
final class EnquiryFileController extends Controller
{
    /** @param array<string,string> $params */
    public function download(Request $request, array $params = []): Response
    {
        $enq = EnquiryPolicy::requireView($this->actorId(), (string) ($params['enquiry'] ?? ''));
        if (!in_array(EnquiryPolicy::level($this->actorId(), $enq), ['all', 'assigned'], true)) {
            throw new HttpException(404);
        }
        $file = EnquiryExtras::file((int) $enq['id'], (string) ($params['file'] ?? '')) ?? throw new HttpException(404);

        return FileStore::send($file);
    }
}
