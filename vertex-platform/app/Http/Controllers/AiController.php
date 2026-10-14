<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\AiService;
use Vertex\Services\Settings;

/** Module 31. Every action shows a draft only; creating tasks is a second, explicit step through the normal task rules. */
final class AiController extends Controller
{
    private function ensureOn(): void
    {
        if (!Settings::feature('ai')) {
            throw new HttpException(404);
        }
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $this->ensureOn();
        $r = $this->page('ai/index', ['title' => 'AI assistant', 'status' => AiService::status(), 'result' => Session::pullFlash('ai_result'), 'tasks' => Session::pullFlash('ai_tasks')]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function run(Request $request, array $params = []): Response
    {
        $this->ensureOn();
        $uid = $this->actorId();
        $action = $request->string('action');
        $in = ['action' => $action, 'enquiry' => $request->string('enquiry'), 'project' => $request->string('project'), 'kind' => $request->string('kind'), 'instruction' => $request->string('instruction'), 'question' => $request->string('question')];

        return $this->guarded(function () use ($uid, $action, $in): Response {
            switch ($action) {
                case 'summarize':
                    $text = AiService::summarizeEnquiry($uid, $in['enquiry']);
                    $title = 'Enquiry summary';
                    break;
                case 'tasks':
                    $tasks = AiService::draftTasks($uid, $in['project']);
                    Session::flash('ai_tasks', ['project' => $in['project'], 'tasks' => $tasks]);

                    return $this->ok('/ai', 'Here are suggested tasks. Nothing has been created yet: tick the ones you want and press Create.');
                case 'draft':
                    $text = AiService::draftDocument($uid, $in['kind'], $in['instruction'], $in['project']);
                    $title = 'Draft';
                    break;
                case 'ask':
                    $text = AiService::ask($uid, $in['question']);
                    $title = 'Answer';
                    break;
                case 'next':
                    $text = AiService::suggestNext($uid, $in['project']);
                    $title = 'Suggested next steps';
                    break;
                default:
                    throw new HttpException(422);
            }
            Session::flash('ai_result', ['title' => $title, 'text' => $text]);

            return $this->ok('/ai', 'This is a draft written by an AI. Check it before using it.');
        }, '/ai', $in);
    }

    /** @param array<string,string> $params */
    public function createTasks(Request $request, array $params = []): Response
    {
        $this->ensureOn();
        $uid = $this->actorId();
        $project = $request->string('project');
        $chosen = [];
        foreach ((array) ($request->body['t'] ?? []) as $i => $t) {
            if (is_array($t) && !empty($t['on']) && is_string($t['title'] ?? null)) {
                $chosen[] = ['title' => $t['title'], 'description' => (string) ($t['description'] ?? ''), 'priority' => (string) ($t['priority'] ?? 'normal'), 'estimate_minutes' => $t['estimate_minutes'] ?? ''];
            }
        }

        return $this->guarded(function () use ($uid, $project, $chosen): Response {
            if ($chosen === []) {
                throw new \Vertex\Core\ValidationException(['Tick at least one task to create.']);
            }
            \Vertex\Services\Gate::authorize('ai.use', $uid);
            $n = AiService::createTasks($uid, $project, $chosen);

            return $this->ok('/projects/' . $project . '/tasks', $n . ' task(s) created.');
        }, '/ai');
    }
}
