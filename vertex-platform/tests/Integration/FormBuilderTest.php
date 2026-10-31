<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\FormService;

/** Module 23: the form builder: validation, conditional questions, access control, history snapshots, CRM leads, export. */
final class FormBuilderTest extends HttpTestCase
{
    private int $owner;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
    }

    private function form(array $over = [], ?string $template = null): array
    {
        $r = FormService::create($this->owner, $over + ['title' => 'Survey', 'slug' => 'survey', 'access' => 'public'], $template);

        return FormService::find($r['public_id']);
    }

    private function field(array $f, array $in): void
    {
        FormService::addField($this->owner, $f, $in + ['required' => '', 'options' => '', 'min' => '', 'max' => '']);
    }

    private function invalid(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('A ValidationException was expected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
        }
    }

    public function testFieldTypesAreValidatedOnTheServer(): void
    {
        $f = $this->form();
        $this->field($f, ['label' => 'Your name', 'type' => 'text', 'required' => '1', 'min' => '2', 'max' => '10']);
        $this->field($f, ['label' => 'Email', 'type' => 'email', 'required' => '1']);
        $this->field($f, ['label' => 'Age', 'type' => 'number', 'min' => '18', 'max' => '99']);
        $this->field($f, ['label' => 'Colour', 'type' => 'select', 'required' => '1', 'options' => "Red\nGreen"]);
        $this->field($f, ['label' => 'Toppings', 'type' => 'multiselect', 'options' => "Cheese\nOlives\nChilli", 'min' => '1', 'max' => '2']);
        $this->field($f, ['label' => 'Agree', 'type' => 'checkbox', 'required' => '1']);
        FormService::setStatus($this->owner, $f, 'published');
        $f = FormService::find($f['public_id']);
        $ok = ['your_name' => 'Asha', 'email' => 'asha@x.test', 'age' => '30', 'colour' => 'Red', 'toppings' => ['Cheese'], 'agree' => '1'];
        $r = FormService::submit($f, $ok, null, null, '203.0.113.5', false);
        $this->assertMatchesRegularExpression('/^FS[A-Z0-9]{4}-\d{2}-\d{5}$/', $r['reference']);
        foreach ([
            ['your_name', '', 'required'], ['your_name', 'A', 'at least 2'], ['your_name', 'Abcdefghijkl', 'at most 10'], ['email', 'nope', 'email'], ['age', '17', 'between'], ['age', 'abc', 'number'],
            ['colour', 'Blue', 'listed options'], ['toppings', ['Cheese', 'Olives', 'Chilli'], 'between'], ['toppings', ['Pineapple'], 'listed options'], ['agree', '', 'ticked'],
        ] as [$key, $bad, $needle]) {
            $this->invalid(fn () => FormService::submit($f, [$key => $bad] + $ok, null, null, '203.0.113.5', false), $needle);
        }
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM form_submissions')['c'], 'invalid answers store nothing');
    }

    public function testConditionalQuestionsAreEnforcedByTheServerNotJustTheBrowser(): void
    {
        $f = $this->form();
        $this->field($f, ['label' => 'Rating', 'type' => 'radio', 'required' => '1', 'options' => "Good\nBad"]);
        $this->field($f, ['label' => 'What went wrong', 'type' => 'textarea', 'required' => '1', 'show_if_field' => 'rating', 'show_if_op' => 'equals', 'show_if_value' => 'Bad']);
        FormService::setStatus($this->owner, $f, 'published');
        $f = FormService::find($f['public_id']);
        FormService::submit($f, ['rating' => 'Good'], null, null, '203.0.113.5', false); // hidden + required: not demanded
        $this->invalid(fn () => FormService::submit($f, ['rating' => 'Bad'], null, null, '203.0.113.5', false), 'required');
        FormService::submit($f, ['rating' => 'Good', 'what_went_wrong' => 'A crafted answer to a hidden question'], null, null, '203.0.113.5', false);
        $stored = Database::all('SELECT data_json FROM form_submissions ORDER BY id');
        $this->assertStringNotContainsString('crafted', $stored[1]['data_json'], 'answers to hidden questions are discarded');
        // a rule can only point at another question, and rules cannot loop
        $this->invalid(fn () => FormService::addField($this->owner, $f, ['label' => 'Bad rule', 'type' => 'text', 'show_if_field' => 'nonexistent', 'show_if_op' => 'filled', 'required' => '', 'options' => '', 'min' => '', 'max' => '']), 'another question');
        $fields = FormService::fields($f);
        $this->invalid(fn () => FormService::updateField($this->owner, $f, (int) $fields[0]['id'], ['label' => 'Rating', 'type' => 'radio', 'options' => "Good\nBad", 'show_if_field' => 'what_went_wrong', 'show_if_op' => 'filled', 'min' => '', 'max' => '']), 'circle');
    }

    public function testAccessControlAndLimits(): void
    {
        $f = $this->form();
        $this->field($f, ['label' => 'Comment', 'type' => 'text']);
        $this->assertSame('not_found', FormService::blocker($f, null, null), 'a draft is invisible');
        FormService::setStatus($this->owner, $f, 'published');
        $f = FormService::find($f['public_id']);
        $this->assertNull(FormService::blocker($f, null, null));
        Database::run("UPDATE forms SET access = 'login' WHERE id = ?", [$f['id']]);
        $this->assertSame('login', FormService::blocker(FormService::find($f['public_id']), null, null));
        $this->assertNull(FormService::blocker(FormService::find($f['public_id']), $this->owner, null));
        Database::run("UPDATE forms SET access = 'client' WHERE id = ?", [$f['id']]);
        $this->assertSame('forbidden', FormService::blocker(FormService::find($f['public_id']), $this->owner, null), 'staff are not clients');
        Database::run("UPDATE forms SET access = 'private_link', access_token = 'abc123abc123abc123abc123abc123ab' WHERE id = ?", [$f['id']]);
        $pl = FormService::find($f['public_id']);
        $this->assertSame('not_found', FormService::blocker($pl, null, null));
        $this->assertSame('not_found', FormService::blocker($pl, null, 'wrong'));
        $this->assertNull(FormService::blocker($pl, null, 'abc123abc123abc123abc123abc123ab'));
        Database::run("UPDATE forms SET access = 'public', max_submissions = 1 WHERE id = ?", [$f['id']]);
        FormService::submit(FormService::find($f['public_id']), ['comment' => 'one'], null, null, '203.0.113.5', false);
        $this->assertSame('closed', FormService::blocker(FormService::find($f['public_id']), null, null), 'the limit closes the form');
        Database::run("UPDATE forms SET max_submissions = NULL, closes_on = ? WHERE id = ?", [gmdate('Y-m-d', strtotime('-1 day')), $f['id']]);
        $this->assertSame('closed', FormService::blocker(FormService::find($f['public_id']), null, null));
        $this->expectException(HttpException::class);
        FormService::submit(FormService::find($f['public_id']), ['comment' => 'late'], null, null, '203.0.113.5', false);
    }

    public function testAnswersKeepTheQuestionWordingTheyWereGivenAndExportIsCsvSafe(): void
    {
        $f = $this->form();
        $this->field($f, ['label' => 'Favourite colour', 'type' => 'text']);
        FormService::setStatus($this->owner, $f, 'published');
        $f = FormService::find($f['public_id']);
        FormService::submit($f, ['favourite_colour' => '=HYPERLINK("http://evil")'], null, null, '203.0.113.5', false);
        $fid = (int) FormService::fields($f)[0]['id'];
        FormService::updateField($this->owner, $f, $fid, ['label' => 'Colour you like most', 'type' => 'text', 'min' => '', 'max' => '']);
        $sub = Database::one('SELECT data_json FROM form_submissions');
        $this->assertStringContainsString('Favourite colour', $sub['data_json'], 'the stored answer keeps its original question');
        $csv = FormService::exportCsv($this->owner, $f);
        $this->assertSame(1, $csv['count']);
        $this->assertStringContainsString("'=HYPERLINK", $csv['csv'], 'formulas are neutralised');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'data.exported'")['c']);
    }

    public function testATemplateFormCreatesACrmLeadAndNotifiesStaff(): void
    {
        $f = $this->form(['title' => '', 'slug' => 'contact-us'], 'contact');
        $this->assertSame('Contact us', $f['title']);
        FormService::setStatus($this->owner, $f, 'published');
        $f = FormService::find($f['public_id']);
        $this->invalid(fn () => FormService::submit($f, ['name' => 'Ravi Kumar', 'email' => 'ravi@x.test', 'message' => 'I need a website for my shop please.'], null, null, '203.0.113.5', false), 'confirm');
        $r = FormService::submit($f, ['name' => 'Ravi Kumar', 'email' => 'ravi@x.test', 'message' => 'I need a website for my shop please.'], null, null, '203.0.113.5', true);
        $this->assertNotNull($r['enquiry_ref']);
        $e = Database::one("SELECT contact_name, email, description FROM enquiries WHERE reference = ?", [$r['enquiry_ref']]);
        $this->assertSame(['Ravi Kumar', 'ravi@x.test'], [$e['contact_name'], $e['email']]);
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'form.submitted'")['c']);
    }

    public function testPublicHttpFlowHoneypotDuplicatesAndStaffGates(): void
    {
        $f = $this->form(['slug' => 'public-survey']);
        $this->field($f, ['label' => 'Comment', 'type' => 'text', 'required' => '1']);
        $o = $this->loggedIn('owner@x.test');
        $o->post('/forms/' . $f['public_id'], ['action' => 'publish'], '/forms');
        $c = $this->client();
        $page = $c->get('/f/public-survey');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('name="a[comment]"', $page['body']);
        preg_match('/name="submit_token" value="([a-f0-9]{32})"/', $page['body'], $m);
        $c->formPage = '/f/public-survey';
        $r = $c->post('/f/public-survey', ['a' => ['comment' => 'Hello'], 'submit_token' => $m[1]]);
        $this->assertSame('/f/public-survey/thanks', $r['location']);
        $c->post('/f/public-survey', ['a' => ['comment' => 'Hello'], 'submit_token' => $m[1]]);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM form_submissions')['c'], 'a double submit stores one answer');
        $c->post('/f/public-survey', ['a' => ['comment' => 'bot'], 'website_url' => 'http://spam']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM form_submissions')['c'], 'the honeypot stores nothing');
        $bad = $c->post('/f/public-survey', ['a' => ['comment' => '']]);
        $this->assertSame('/f/public-survey', $bad['location']);
        $this->assertSame(404, $c->get('/f/does-not-exist')['status']);
        // staff screens
        $this->assertStringContainsString('Hello', $o->get('/forms/' . $f['public_id'] . '/submissions')['body']);
        $this->assertSame(200, $o->get('/forms/' . $f['public_id'] . '/export')['status']);
        $this->makeUser('cl@x.test', ['client']);
        $cl = $this->loggedIn('cl@x.test');
        $this->assertSame(403, $cl->get('/forms')['status']);
        $this->assertSame(403, $cl->get('/forms/' . $f['public_id'] . '/submissions')['status']);
        $this->assertSame(403, $cl->post('/forms', ['title' => 'x', 'slug' => 'xxx'], '/account')['status']);
    }
}
