<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vertex\Core\Config;

/** Locks the business-critical rules of the role/permission matrix so a careless edit fails CI. */
final class PermissionMatrixTest extends TestCase
{
    /** Permissions only Admin may ever hold. */
    private const ADMIN_ONLY = [
        'projects.assign', 'payouts.approve', 'payouts.record', 'commissions.rules.manage',
        'referrals.decide', 'renewals.approve', 'renewals.confirm_provider',
        'reports.financial', 'audit.view', 'backups.manage', 'settings.manage',
        'users.assign_roles', 'roles.manage', 'invoices.issue', 'invoices.void', 'quotes.send',
        'pricing.manage', 'payments.reconcile', 'cms.publish', 'legal.manage',
    ];

    /** @return list<string> */
    private function catalogueKeys(): array
    {
        $keys = [];
        foreach (Config::get('permissions.catalogue') as $items) {
            array_push($keys, ...array_keys($items));
        }

        return $keys;
    }

    /** @return array<string,array{0:string}> */
    public static function roleSlugs(): array
    {
        return array_map(fn ($s) => [$s], array_keys(Config::get('permissions.roles')));
    }

    public function testEightExpectedRolesExist(): void
    {
        $this->assertEqualsCanonicalizing(
            ['admin', 'manager', 'staff', 'freelancer', 'video_editor', 'website_developer', 'referral_partner', 'client'],
            array_keys(Config::get('permissions.roles')),
        );
    }

    public function testCatalogueKeysAreUnique(): void
    {
        $keys = $this->catalogueKeys();
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    #[DataProvider('roleSlugs')]
    public function testEveryRoleOnlyReferencesCataloguePermissions(string $slug): void
    {
        $perms = Config::get("permissions.roles.{$slug}.permissions");
        $this->assertSame([], array_diff($perms, $this->catalogueKeys()), "{$slug} references unknown permissions");
        $this->assertSame(count($perms), count(array_unique($perms)), "{$slug} has duplicate grants");
    }

    public function testAdminHoldsEveryPermissionExplicitly(): void
    {
        $this->assertEqualsCanonicalizing($this->catalogueKeys(), Config::get('permissions.roles.admin.permissions'));
    }

    public function testNoWildcardPermissionsExist(): void
    {
        foreach (Config::get('permissions.roles') as $slug => $def) {
            foreach ($def['permissions'] as $p) {
                $this->assertStringNotContainsString('*', $p, "{$slug} uses a wildcard");
            }
        }
    }

    #[DataProvider('roleSlugs')]
    public function testAdminOnlyPermissionsAreNeverGrantedToOtherRoles(string $slug): void
    {
        if ($slug === 'admin') {
            $this->assertSame([], array_diff(self::ADMIN_ONLY, Config::get('permissions.roles.admin.permissions')));

            return;
        }
        $leaked = array_intersect(self::ADMIN_ONLY, Config::get("permissions.roles.{$slug}.permissions"));
        $this->assertSame([], array_values($leaked), "{$slug} must not hold: " . implode(', ', $leaked));
    }

    public function testManagerIsStrictlySmallerThanAdmin(): void
    {
        $manager = Config::get('permissions.roles.manager.permissions');
        $admin = Config::get('permissions.roles.admin.permissions');
        $this->assertLessThan(count($admin) * 0.6, count($manager));
        $this->assertNotContains('projects.view_all', $manager, 'Manager must not see every project');
        $this->assertNotContains('payments.view_all', $manager);
    }

    public function testOnlyAdminHasAllProjectVisibility(): void
    {
        foreach (Config::get('permissions.roles') as $slug => $def) {
            if ($slug !== 'admin') {
                $this->assertNotContains('projects.view_all', $def['permissions'], "{$slug} must not view all projects");
                $this->assertNotContains('projects.create', $def['permissions'], "{$slug} must not create projects");
            }
        }
    }

    public function testReferralPartnerHasNoProjectOrFileAccess(): void
    {
        $perms = Config::get('permissions.roles.referral_partner.permissions');
        foreach ($perms as $p) {
            $this->assertDoesNotMatchRegularExpression('/^(projects|files|tasks|milestones|chat)\./', $p);
        }
    }

    public function testWorkersCanProposeButNotApproveMilestones(): void
    {
        foreach (['staff', 'freelancer', 'video_editor', 'website_developer'] as $slug) {
            $perms = Config::get("permissions.roles.{$slug}.permissions");
            $this->assertContains('milestones.propose', $perms);
            $this->assertNotContains('milestones.approve', $perms);
        }
    }

    public function testClientsCannotSeeInternalFinanceOrOthersData(): void
    {
        $perms = Config::get('permissions.roles.client.permissions');
        foreach (['invoices.view_all', 'payments.view_all', 'payouts.request', 'commissions.view_all', 'projects.view_all', 'projects.view_assigned'] as $p) {
            $this->assertNotContains($p, $perms);
        }
    }
}
