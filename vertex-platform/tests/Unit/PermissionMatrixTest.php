<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vertex\Core\Config;

/** Locks the business-critical rules of the default role/permission matrix so a careless edit fails the suite. */
final class PermissionMatrixTest extends TestCase
{
    private const ROLES = ['super_admin', 'administrator', 'manager', 'finance_staff', 'support_staff', 'freelancer', 'video_editor', 'website_developer', 'referral_partner', 'client'];

    /** @return list<string> */
    private function catalogueKeys(): array
    {
        $keys = [];
        foreach (Config::get('permissions.catalogue') as $items) {
            array_push($keys, ...array_keys($items));
        }

        return $keys;
    }

    /** @return list<string> role slugs that hold $perm by default */
    private function holders(string $perm): array
    {
        $out = [];
        foreach (Config::get('permissions.roles') as $slug => $def) {
            if (in_array($perm, $def['permissions'], true)) {
                $out[] = $slug;
            }
        }
        sort($out);

        return $out;
    }

    /** @return array<string,array{0:string}> */
    public static function roleSlugs(): array
    {
        return array_map(static fn ($s) => [$s], self::ROLES);
    }

    public function testExactlyTheTenSpecifiedRolesExist(): void
    {
        $this->assertEqualsCanonicalizing(self::ROLES, array_keys(Config::get('permissions.roles')));
    }

    public function testCatalogueKeysAreUniqueAndSensitiveListIsValid(): void
    {
        $keys = $this->catalogueKeys();
        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertSame([], array_diff(Config::get('permissions.sensitive'), $keys), 'sensitive list references unknown permissions');
    }

    #[DataProvider('roleSlugs')]
    public function testEveryRoleOnlyReferencesCataloguePermissionsWithoutDuplicates(string $slug): void
    {
        $perms = Config::get("permissions.roles.{$slug}.permissions");
        $this->assertSame([], array_diff($perms, $this->catalogueKeys()), "{$slug} references unknown permissions");
        $this->assertSame(count($perms), count(array_unique($perms)), "{$slug} has duplicate grants");
    }

    public function testNoWildcardPermissionsExist(): void
    {
        foreach (Config::get('permissions.roles') as $slug => $def) {
            foreach ($def['permissions'] as $p) {
                $this->assertStringNotContainsString('*', $p, "{$slug} uses a wildcard");
            }
        }
    }

    public function testSuperAdminIsTheOnlyLockedTopLevelRoleAndHoldsEverythingExplicitly(): void
    {
        $roles = Config::get('permissions.roles');
        $this->assertEqualsCanonicalizing($this->catalogueKeys(), $roles['super_admin']['permissions']);
        $this->assertTrue($roles['super_admin']['locked']);
        $this->assertFalse($roles['super_admin']['assignable_in_ui'], 'Owner can only be created from the console');
        foreach ($roles as $slug => $def) {
            if ($slug !== 'super_admin') {
                $this->assertFalse($def['locked'], "{$slug} must not be locked");
                $this->assertTrue($def['assignable_in_ui'], "{$slug} must be assignable in the UI");
                $this->assertLessThan($roles['super_admin']['level'], $def['level']);
            }
        }
        $this->assertGreaterThan($roles['manager']['level'], $roles['administrator']['level']);
        $this->assertGreaterThan($roles['client']['level'], $roles['manager']['level']);
    }

    public function testRequestedActionCategoriesAreSeparatePermissions(): void
    {
        $keys = $this->catalogueKeys();
        foreach ([
            'projects.view_all', 'projects.create', 'projects.edit', 'projects.archive',   // view / create / edit / archive
            'transactions.approve', 'deliverables.publish', 'projects.assign',            // approve / publish / assign
            'users.create', 'users.edit', 'users.deactivate', 'users.archive',           // manage users
            'cms.edit', 'cms.publish', 'finance.view', 'payments.record', 'settings.manage', 'settings.view',
        ] as $required) {
            $this->assertContains($required, $keys);
        }
    }

    /** @return array<string,array{0:string,1:list<string>}> */
    public static function exclusivePermissions(): array
    {
        return [
            'only Admins assign projects' => ['projects.assign', ['administrator', 'super_admin']],
            'only owner changes sensitive grants' => ['roles.manage_sensitive', ['super_admin']],
            'only owner changes settings' => ['settings.manage', ['super_admin']],
            'only owner makes backups' => ['backups.manage', ['super_admin']],
            'approving transactions' => ['transactions.approve', ['administrator', 'super_admin']],
            'approving payouts' => ['payouts.approve', ['administrator', 'super_admin']],
            // Phase 6: Finance Staff pays approved payouts (the Phase 6 brief: Admin/Finance approve and pay); approving stays Administrator/Owner, so approval and payment are separate duties
            'recording payouts' => ['payouts.record', ['administrator', 'finance_staff', 'super_admin']],
            'deciding referrals' => ['referrals.decide', ['administrator', 'super_admin']],
            'approving renewals' => ['renewals.approve', ['administrator', 'super_admin']],
            'provider confirmation' => ['renewals.confirm_provider', ['administrator', 'super_admin']],
            'role assignment' => ['users.assign_roles', ['administrator', 'super_admin']],
            'account deactivation' => ['users.deactivate', ['administrator', 'super_admin']],
            'user creation' => ['users.create', ['administrator', 'super_admin']],
            'audit log' => ['audit.view', ['administrator', 'super_admin']],
            'viewing every project' => ['projects.view_all', ['administrator', 'super_admin']],
            'creating projects' => ['projects.create', ['administrator', 'super_admin']],
            'issuing invoices' => ['invoices.issue', ['administrator', 'super_admin']],
            'creating custom roles' => ['roles.create', ['super_admin']],
            'resetting two-factor login' => ['users.reset_2fa', ['administrator', 'super_admin']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('exclusivePermissions')]
    public function testSensitivePermissionHoldersAreExactlyAsSpecified(string $perm, array $expected): void
    {
        sort($expected);
        $this->assertSame($expected, $this->holders($perm), "Unexpected holders of {$perm}");
    }

    public function testEverythingThatMovesMoneyOrAccessIsFlaggedSensitive(): void
    {
        foreach (['users.assign_roles', 'roles.manage', 'roles.manage_sensitive', 'audit.view', 'settings.manage', 'finance.view', 'payments.record', 'transactions.approve', 'payouts.approve', 'payouts.record', 'projects.assign', 'referrals.decide', 'renewals.approve'] as $p) {
            $this->assertContains($p, Config::get('permissions.sensitive'), "{$p} must be sensitive");
        }
    }

    public function testManagerIsNotAnAdminInDisguise(): void
    {
        $manager = Config::get('permissions.roles.manager.permissions');
        foreach (['projects.view_all', 'projects.assign', 'projects.create', 'finance.view', 'payments.record', 'payments.view_all', 'invoices.view_all', 'settings.view', 'roles.view'] as $p) {
            $this->assertNotContains($p, $manager);
        }
        foreach ($manager as $p) {
            $this->assertStringStartsNotWith('users.', $p, 'manager must not manage users');
        }
        $this->assertContains('projects.view_assigned', $manager);
    }

    public function testFinanceStaffHasOnlyExplicitFinancialOperations(): void
    {
        $f = Config::get('permissions.roles.finance_staff.permissions');
        $this->assertEqualsCanonicalizing([
            'finance.view', 'invoices.view_all', 'invoices.create', 'invoices.edit',
            'payments.view_all', 'payments.record', 'payouts.view_all', 'payouts.record', 'commissions.view_all',
            'data.export', 'coupons.view', 'reports.financial', // Phase 9-10: export their lists, see coupons, financial reports
        ], $f);
        foreach (['payouts.approve', 'commissions.approve', 'commissions.adjust', 'commissions.rules.manage', 'payments.reconcile', 'referrals.decide'] as $never) {
            $this->assertNotContains($never, $f, "finance must not hold {$never}");
        }
    }

    public function testSupportStaffHasNoFinanceOrUserAdmin(): void
    {
        foreach (Config::get('permissions.roles.support_staff.permissions') as $p) {
            $this->assertDoesNotMatchRegularExpression('/^(users|roles|finance|invoices|payments|payouts|commissions|settings|audit|pricing)\./', $p);
        }
    }

    public function testWorkersProposeButCannotApproveCreateOrPublish(): void
    {
        foreach (['freelancer', 'video_editor', 'website_developer'] as $slug) {
            $perms = Config::get("permissions.roles.{$slug}.permissions");
            $this->assertContains('milestones.propose', $perms);
            $this->assertContains('projects.view_assigned', $perms);
            foreach (['milestones.approve', 'milestones.create', 'deliverables.publish', 'projects.view_all', 'projects.assign', 'finance.view'] as $p) {
                $this->assertNotContains($p, $perms, "{$slug} must not hold {$p}");
            }
        }
    }

    public function testReferralPartnerHasNoProjectFileTaskOrChatAccess(): void
    {
        foreach (Config::get('permissions.roles.referral_partner.permissions') as $p) {
            $this->assertDoesNotMatchRegularExpression('/^(projects|files|tasks|milestones|chat|deliverables|invoices|payments)\./', $p);
        }
        $this->assertContains('referrals.view_own', Config::get('permissions.roles.referral_partner.permissions'));
    }

    public function testClientIsLimitedToOwnScopedPermissions(): void
    {
        $perms = Config::get('permissions.roles.client.permissions');
        foreach (['projects.view_all', 'projects.view_assigned', 'invoices.view_all', 'payments.view_all', 'finance.view', 'payouts.request', 'users.view', 'audit.view'] as $p) {
            $this->assertNotContains($p, $perms);
        }
        $this->assertContains('projects.view_own', $perms);
        $this->assertContains('invoices.view_own', $perms);
    }

    public function testPhoneIsRequiredForExternalRolesOnly(): void
    {
        $required = [];
        foreach (Config::get('permissions.roles') as $slug => $def) {
            if ($def['phone_required']) {
                $required[] = $slug;
            }
        }
        $this->assertEqualsCanonicalizing(['freelancer', 'video_editor', 'website_developer', 'referral_partner', 'client'], $required);
    }
}
