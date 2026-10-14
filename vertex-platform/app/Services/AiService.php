<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;
use Vertex\Core\ValidationException;

/**
 * Module 31: AI assistance, through a configurable provider (Anthropic or any OpenAI-compatible service).
 *
 * Safety rules that are enforced here, not just promised on screen:
 *  - off until an administrator switches it on and saves an API key; needs the `ai.use` permission;
 *  - the model only ever receives records the signed-in user is allowed to open (the same policies as the rest of the platform), without contact details;
 *  - the output is a DRAFT shown to the person: nothing is saved, sent, approved or created unless they then press the normal button in the normal screen;
 *  - every request is logged (who, which feature, size, outcome: never the text), limited per person per hour and per month for the company;
 *  - provider errors are logged without the key and shown as a short generic message.
 */
final class AiService
{
    private const MAX_INPUT_CHARS = 12000;

    public static function available(): bool
    {
        return Settings::feature('ai') && (bool) Config::get('ai.enabled') && (string) Config::get('ai.api_key') !== '';
    }

    /** @return array{ready:bool,reason:string,used:int,cap:int} */
    public static function status(): array
    {
        $used = (int) Database::one("SELECT COUNT(*) AS n FROM ai_requests WHERE created_at >= ?", [gmdate('Y-m-01 00:00:00')])['n'];
        $cap = (int) Config::get('ai.monthly_cap', 500);
        $reason = !Settings::feature('ai') ? 'The AI feature is switched off.' : (!Config::get('ai.enabled') ? 'AI assistance is turned off. An administrator can turn it on in Integrations & settings.' : ((string) Config::get('ai.api_key') === '' ? 'No API key has been saved yet.' : ''));

        return ['ready' => $reason === '', 'reason' => $reason, 'used' => $used, 'cap' => $cap];
    }

    /** Sends one prompt and returns the model's text. @throws ValidationException with a message safe to show */
    public static function complete(int $userId, string $feature, string $system, string $prompt, ?string $subjectType = null, ?int $subjectId = null): string
    {
        Gate::authorize('ai.use', $userId);
        $st = self::status();
        if (!$st['ready']) {
            throw new ValidationException([$st['reason']]);
        }
        if ($st['used'] >= $st['cap']) {
            throw new ValidationException(['The monthly AI limit for the whole company has been reached. An administrator can raise it.']);
        }
        if (!RateLimiter::hit('ai_user', (string) $userId)) {
            throw new ValidationException(['You have used the AI helper a lot this hour. Please try again later.']);
        }
        $prompt = mb_substr($prompt, 0, self::MAX_INPUT_CHARS);
        Database::run("INSERT INTO ai_requests (user_id, feature, subject_type, subject_id, prompt_chars, status, created_at) VALUES (?, ?, ?, ?, ?, 'started', UTC_TIMESTAMP())", [$userId, $feature, $subjectType, $subjectId, mb_strlen($prompt)]);
        $id = Database::insertId();
        $t0 = microtime(true);
        try {
            $text = self::call($system, $prompt);
            Database::run("UPDATE ai_requests SET status = 'ok', response_chars = ?, latency_ms = ? WHERE id = ?", [mb_strlen($text), (int) ((microtime(true) - $t0) * 1000), $id]);

            return $text;
        } catch (\Throwable $e) {
            Database::run("UPDATE ai_requests SET status = 'error', error = ?, latency_ms = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 250), (int) ((microtime(true) - $t0) * 1000), $id]);
            Logger::error('AI request failed', ['feature' => $feature, 'error' => $e->getMessage()]);
            throw new ValidationException(['The AI service could not answer right now. Nothing was changed. Please try again in a few minutes.']);
        }
    }

    private static function call(string $system, string $prompt): string
    {
        $provider = (string) Config::get('ai.provider', 'anthropic');
        $key = (string) Config::get('ai.api_key');
        $model = (string) Config::get('ai.model');
        $max = (int) Config::get('ai.max_tokens', 1200);
        $timeout = (int) Config::get('ai.timeout', 45);
        $base = rtrim((string) Config::get('ai.base_url'), '/');
        if ($provider === 'openai') {
            $url = ($base !== '' ? $base : 'https://api.openai.com') . '/v1/chat/completions';
            $res = Http::request('POST', $url, ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'],
                json_encode(['model' => $model, 'max_tokens' => $max, 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]]], JSON_THROW_ON_ERROR), $timeout);
        } else {
            $url = ($base !== '' ? $base : 'https://api.anthropic.com') . '/v1/messages';
            $res = Http::request('POST', $url, ['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'Content-Type' => 'application/json'],
                json_encode(['model' => $model, 'max_tokens' => $max, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $prompt]]], JSON_THROW_ON_ERROR), $timeout);
        }
        $data = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($data)) {
            throw new \RuntimeException('Provider answered HTTP ' . $res['status']);
        }
        if ($provider === 'openai') {
            $text = (string) ($data['choices'][0]['message']['content'] ?? '');
        } else {
            $text = '';
            foreach ((array) ($data['content'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= (string) $block['text'];
                }
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('The provider returned an empty answer.');
        }

        return mb_substr($text, 0, 20000);
    }

    // ------------------------------------------------------------------------------------------------ features

    private const BASE_RULES = "You are an assistant inside a small web-agency's management system. Use ONLY the facts you are given; if something is not given, say it is unknown. Never invent prices, dates, names or promises. Answer in plain text without markdown tables or HTML. Be concise.";

    /** Requirement summary of an enquiry the user can open (no contact details are sent). */
    public static function summarizeEnquiry(int $userId, string $enquiryPublicId): string
    {
        $e = EnquiryPolicy::requireView($userId, $enquiryPublicId);
        $services = (array) Config::get('workflow.services');
        $prompt = "Summarise this sales enquiry for the team.\nService: " . ($services[$e['service']] ?? ($e['service'] ?: 'not stated')) . "\nBudget range: " . ($e['budget_range'] ?: 'not stated') . "\nDeadline: " . ($e['expected_deadline'] ?: 'not stated') . "\nSource: {$e['source']}\n\nClient's description:\n" . $e['description'];

        return self::complete($userId, 'summarize_enquiry', self::BASE_RULES . ' Output: 1) a 2-sentence summary, 2) the requirements as a bullet list, 3) risks or unclear points, 4) three questions to ask the client.', $prompt, 'enquiry', (int) $e['id']);
    }

    /** @return list<array{title:string,description:string,estimate_minutes:?int,priority:string}> drafted tasks (nothing is created) */
    public static function draftTasks(int $userId, string $projectPublicId): array
    {
        $p = ProjectPolicy::requireView($userId, $projectPublicId);
        if (!in_array(ProjectPolicy::level($userId, $p), ['all', 'assigned'], true) || !Gate::can('tasks.create', $userId)) {
            throw new HttpException(403);
        }
        $existing = array_column(Database::all("SELECT title FROM tasks WHERE project_id = ? AND archived_at IS NULL AND status <> 'cancelled' ORDER BY id LIMIT 60", [$p['id']]), 'title');
        $prompt = "Project: {$p['title']} ({$p['code']})\nService: " . ($p['service_category'] ?: 'not stated') . "\nDescription: " . ($p['description'] ?: 'none') . "\nScope: " . ($p['scope'] ?: 'none') . "\nTarget date: " . ($p['target_date'] ?: 'not set') . "\nTasks that already exist (do not repeat): " . ($existing === [] ? 'none' : implode('; ', $existing));
        $raw = self::complete($userId, 'draft_tasks', self::BASE_RULES . ' Return ONLY a JSON object: {"tasks":[{"title":"...","description":"...","estimate_minutes":120,"priority":"low|normal|high|urgent"}]} with at most 12 tasks, each title under 120 characters.', $prompt, 'project', (int) $p['id']);

        return self::parseTasks($raw);
    }

    /** @return list<array{title:string,description:string,estimate_minutes:?int,priority:string}> */
    public static function parseTasks(string $raw): array
    {
        $a = strpos($raw, '{');
        $b = strrpos($raw, '}');
        $data = $a !== false && $b !== false && $b > $a ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
        if (!is_array($data) || !is_array($data['tasks'] ?? null)) {
            throw new ValidationException(['The AI answer was not in the expected format. Please try again.']);
        }
        $out = [];
        foreach (array_slice($data['tasks'], 0, 12) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $title = trim(strip_tags((string) ($t['title'] ?? '')));
            if (mb_strlen($title) < 2) {
                continue;
            }
            $prio = strtolower((string) ($t['priority'] ?? 'normal'));
            $est = isset($t['estimate_minutes']) && is_numeric($t['estimate_minutes']) && (int) $t['estimate_minutes'] > 0 && (int) $t['estimate_minutes'] <= 100000 ? (int) $t['estimate_minutes'] : null;
            $out[] = ['title' => mb_substr($title, 0, 190), 'description' => mb_substr(trim(strip_tags((string) ($t['description'] ?? ''))), 0, 1000), 'estimate_minutes' => $est, 'priority' => in_array($prio, ['low', 'normal', 'high', 'urgent'], true) ? $prio : 'normal'];
        }
        if ($out === []) {
            throw new ValidationException(['The AI did not suggest any usable tasks. Please try again with more detail in the project description.']);
        }

        return $out;
    }

    /** Creates the chosen drafted tasks through the normal task service (all its permission and audit rules apply). @param list<array<string,mixed>> $chosen @return int created */
    public static function createTasks(int $userId, string $projectPublicId, array $chosen): int
    {
        $p = ProjectPolicy::requireView($userId, $projectPublicId);
        $n = 0;
        Database::transaction(function () use ($userId, $p, $chosen, &$n): void {
            foreach (array_slice($chosen, 0, 12) as $t) {
                TaskService::create($userId, $p, ['title' => (string) $t['title'], 'description' => (string) ($t['description'] ?? ''), 'milestone' => '', 'assignee' => '', 'start_date' => '', 'due_date' => '', 'priority' => (string) ($t['priority'] ?? 'normal'),
                    'estimate_minutes' => ($t['estimate_minutes'] ?? '') === '' || $t['estimate_minutes'] === null ? '' : (string) $t['estimate_minutes'], 'review_required' => '', 'status' => 'backlog']);
                $n++;
            }
            if ($n > 0) {
                Audit::record('ai.tasks_created', 'project', (int) $p['id'], [], ['count' => $n], [], $userId);
            }
        });

        return $n;
    }

    /** A first draft of an email, proposal or agreement clause from an instruction and (optionally) a client or project the user can open. */
    public static function draftDocument(int $userId, string $kind, string $instruction, string $projectPublicId = ''): string
    {
        $kinds = ['email' => 'a professional email', 'proposal' => 'a short project proposal', 'agreement' => 'draft agreement wording for a lawyer to review', 'update' => 'a friendly project status update for the client'];
        if (!isset($kinds[$kind])) {
            throw new ValidationException(['Choose what to draft.']);
        }
        $instruction = (string) FieldRules::text($instruction, 'What should it say?', 10, 3000);
        $context = '';
        if (trim($projectPublicId) !== '') {
            $p = ProjectPolicy::requireView($userId, trim($projectPublicId));
            $done = (int) Database::one("SELECT COUNT(*) AS n FROM tasks WHERE project_id = ? AND status = 'completed' AND archived_at IS NULL", [$p['id']])['n'];
            $open = (int) Database::one("SELECT COUNT(*) AS n FROM tasks WHERE project_id = ? AND status NOT IN ('completed','cancelled') AND archived_at IS NULL", [$p['id']])['n'];
            $context = "\nProject: {$p['title']} ({$p['code']}), status {$p['status']}, target date " . ($p['target_date'] ?: 'not set') . ", {$done} task(s) done and {$open} open.";
        }

        return self::complete($userId, 'draft_' . $kind, self::BASE_RULES . " Write {$kinds[$kind]} as a DRAFT for a person to edit. Sign off with the placeholder [Your name]. Do not state legal conclusions.", $instruction . $context, $context === '' ? null : 'project', null);
    }

    /** Answers a question using only search results the user is allowed to see. */
    public static function ask(int $userId, string $question): string
    {
        $question = (string) FieldRules::line($question, 'Question', 5, 300);
        $stop = ['the', 'and', 'for', 'are', 'was', 'were', 'with', 'that', 'this', 'from', 'have', 'has', 'which', 'what', 'who', 'whom', 'where', 'when', 'how', 'show', 'list', 'tell', 'about', 'called', 'named', 'all', 'any', 'our', 'any', 'does', 'did', 'there', 'their', 'project', 'projects', 'client', 'clients', 'task', 'tasks', 'please', 'give', 'find'];
        $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}@._-]+/u', $question) ?: [], static fn (string $w): bool => mb_strlen($w) >= 3 && !in_array(mb_strtolower($w), $stop, true))));
        $lines = [];
        foreach (array_slice($words, 0, 5) as $w) {
            foreach (SearchService::search($userId, $w) as $g) {
                foreach ($g['items'] as $it) {
                    $lines[$g['label'] . ': ' . $it['title'] . ($it['sub'] !== '' ? ' (' . $it['sub'] . ')' : '')] = true;
                }
            }
        }
        $context = $lines === [] ? 'No matching records were found in the areas this person can open.' : implode("\n", array_slice(array_keys($lines), 0, 40));

        return self::complete($userId, 'ask', self::BASE_RULES . ' You only know the records listed below. If they do not answer the question, say so and suggest what to search for.', "Question: {$question}\n\nRecords this person may see:\n{$context}", null, null);
    }

    /** Next-step suggestions for a project from its real numbers. */
    public static function suggestNext(int $userId, string $projectPublicId): string
    {
        $p = ProjectPolicy::requireView($userId, $projectPublicId);
        if (!in_array(ProjectPolicy::level($userId, $p), ['all', 'assigned'], true)) {
            throw new HttpException(403);
        }
        $t = Database::one("SELECT COUNT(*) total, COALESCE(SUM(status='completed'),0) done, COALESCE(SUM(status NOT IN ('completed','cancelled') AND due_date < ?),0) overdue, COALESCE(SUM(status='blocked'),0) blocked, COALESCE(SUM(status='in_review'),0) in_review FROM tasks WHERE project_id = ? AND archived_at IS NULL", [AppTime::today(), $p['id']]);
        $d = Database::one("SELECT COALESCE(SUM(status='pending_review'),0) pending, COALESCE(SUM(status='approved_internal'),0) approved, COALESCE(SUM(status='published'),0) published FROM deliverables WHERE project_id = ? AND archived_at IS NULL", [$p['id']]);
        $r = (int) Database::one("SELECT COUNT(*) AS n FROM revision_requests WHERE project_id = ? AND status NOT IN ('resolved','rejected','cancelled')", [$p['id']])['n'];
        $prompt = "Project {$p['title']} ({$p['code']}): status {$p['status']}, start " . ($p['start_date'] ?: 'not set') . ', target ' . ($p['target_date'] ?: 'not set') . ".\nTasks: {$t['total']} total, {$t['done']} done, {$t['overdue']} overdue, {$t['blocked']} blocked, {$t['in_review']} in review.\nDeliverables: {$d['pending']} waiting for internal review, {$d['approved']} approved and not yet published, {$d['published']} published.\nOpen client revision requests: {$r}.";

        return self::complete($userId, 'suggest_next', self::BASE_RULES . ' Suggest the 3 to 5 most useful next actions for the project manager, most urgent first, each with the reason from the numbers.', $prompt, 'project', (int) $p['id']);
    }
}
