<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\ActivityService;

/** The Owner's "Account activity" screens and a person's own activity page. */
final class ActivityHttpTest extends HttpTestCase
{
    public function testLabelsAndCategoriesAreReadable(): void
    {
        $this->assertSame('Signed in', ActivityService::label('auth.login.success'));
        $this->assertSame('Invoice issued', ActivityService::label('invoice.issued'));
        $this->assertSame('money', ActivityService::categoryOf('client_payment.verified'));
        $this->assertSame('signin', ActivityService::categoryOf('auth.logout'));
        $this->assertSame('other', ActivityService::categoryOf('zzz.unknown'));
    }

    public function testOnlyTheOwnerCanOpenAccountActivityAndItShowsEveryAccount(): void
    {
        $this->makeUser('owner@x.test', ['super_admin']);
        $this->makeUser('admin@x.test', ['administrator']);
        $this->makeUser('client@x.test', ['client']);
        $client = $this->loggedIn('client@x.test'); // a sign-in to see in the log
        $client->get('/dashboard');
        $this->assertSame(403, $client->get('/admin/activity')['status']);
        $admin = $this->loggedIn('admin@x.test');
        $this->assertSame(403, $admin->get('/admin/activity')['status'], 'an Administrator does not see everyone\'s activity');
        $this->assertNotContains('activity.view_all', array_column(Database::all("SELECT p.perm_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.slug = 'administrator'"), 'perm_key'));

        $owner = $this->loggedIn('owner@x.test');
        $page = $owner->get('/admin/activity');
        $this->assertSame(200, $page['status']);
        foreach (['Account activity', 'client@x.test', 'admin@x.test', 'owner@x.test', 'Sign-ins (30 d)'] as $needle) {
            $this->assertStringContainsString($needle, $page['body']);
        }
        $this->assertStringContainsString('client@x.test', $owner->get('/admin/activity?q=client')['body']);
        $this->assertStringNotContainsString('admin@x.test', $owner->get('/admin/activity?group=clients')['body'], 'filter by who');

        $feed = $owner->get('/admin/activity?view=feed');
        $this->assertStringContainsString('Signed in', $feed['body']);
        $this->assertStringContainsString('client@x.test', $feed['body']);
        $this->assertStringNotContainsString('Signed in', $owner->get('/admin/activity?view=feed&category=money')['body'] === '' ? 'x' : preg_replace('#<nav.*?</nav>#s', '', $owner->get('/admin/activity?view=feed&category=money')['body']) ?? '', 'category filter');
    }

    public function testTheAccountPageShowsWhatThePersonDidAndWhatHappenedToTheirAccount(): void
    {
        $this->makeUser('owner@x.test', ['super_admin']);
        $cid = $this->makeUser('client@x.test', ['client']);
        $pub = (string) Database::one('SELECT public_id FROM users WHERE id = ?', [$cid])['public_id'];
        $this->client()->login('client@x.test', 'wrong-password-123'); // a failed attempt about this account
        $this->loggedIn('client@x.test');
        $owner = $this->loggedIn('owner@x.test');
        $r = $owner->get('/admin/activity/' . $pub);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('client@x.test', $r['body']);
        $this->assertStringContainsString('Signed in', $r['body']);
        $this->assertStringContainsString('Failed sign-in attempt', $r['body']);
        $by = $owner->get('/admin/activity/' . $pub . '?direction=by')['body'];
        $this->assertStringContainsString('Signed in', $by);
        $this->assertStringNotContainsString('Failed sign-in attempt', $by, 'a failed attempt is not something the account did');
        $this->assertStringContainsString('Failed sign-in attempt', $owner->get('/admin/activity/' . $pub . '?direction=to')['body']);
        $this->assertSame(404, $owner->get('/admin/activity/' . str_repeat('0', 32))['status']);
        $this->assertSame(404, $owner->get('/admin/activity/not-an-id')['status']);
        $this->assertGreaterThanOrEqual(1, (int) Database::one("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'activity.account_viewed' AND entity_id = ?", [$cid])['c'], 'looking at an account is itself recorded');
    }

    public function testEveryoneCanSeeTheirOwnActivityAndOnlyTheirOwn(): void
    {
        $this->makeUser('a@x.test', ['client']);
        $this->makeUser('b@x.test', ['client']);
        $b = $this->loggedIn('b@x.test');
        $a = $this->loggedIn('a@x.test');
        $mine = $a->get('/account/activity');
        $this->assertSame(200, $mine['status']);
        $this->assertStringContainsString('My account activity', $mine['body']);
        $this->assertStringContainsString('Signed in', $mine['body']);
        $this->assertStringNotContainsString('b@x.test', $mine['body'], 'never anyone else\'s');
        $this->assertStringContainsString('/account/activity', $a->get('/account')['body']);
        $this->assertSame(302, $this->client()->get('/account/activity')['status'] === 303 ? 302 : $this->client()->get('/account/activity')['status'], 'signed-out visitors are sent to sign in');
        unset($b);
    }
}
