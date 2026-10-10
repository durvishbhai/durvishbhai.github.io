# 04 · Role & permission matrix

Generated from `config/permissions.php` with `php bin/console.php permissions:matrix` — regenerate after any change; do not edit by hand. The rules below are enforced by `tests/Unit/PermissionMatrixTest.php` and `tests/Integration/FoundationTest.php`.

## Rules that the matrix must always satisfy

1. **Only Admin assigns projects** (`projects.assign`), approves/records payouts, decides referral applications, approves renewals, confirms provider renewal, edits commission rules, issues/voids invoices, sends quotations, manages pricing, sees audit logs and financial reports.
2. **Manager is an explicit, smaller list**, never derived from Admin, and cannot see all projects (`projects.view_assigned` only).
3. **Workers** (staff, freelancer, video editor, website developer) can *propose* milestones but not approve them. Whether a given role may *create* milestones directly is a per-role switch (`milestones.create`), currently granted only to Admin and Manager (decision D-12).
4. **Referral Partner** has no project, file, task or chat access.
5. **Client** sees only own projects/invoices/receipts (`*.view_own`) and cannot see any internal finance.
6. No wildcard permissions; no implicit super-user.
7. `.assigned` / `.own` permissions are **scoped**: the permission opens the feature; per-row access is enforced by module policies (Phase 2+).

Roles can hold several roles at once (e.g. Freelancer + Referral Partner); permissions are the union.

## Matrix

| Permission | Administrator | Manager | Staff (salaried) | Freelancer | Video Editor | Website Developer | Referral Partner | Client |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **Users** | | | | | | | | |
| `users.view` — View user accounts | ✔ | ✔ |  |  |  |  |  |  |
| `users.manage` — Create, edit, suspend user accounts | ✔ |  |  |  |  |  |  |  |
| `users.assign_roles` — Change which roles a user holds | ✔ |  |  |  |  |  |  |  |
| `roles.manage` — Edit the role/permission matrix | ✔ |  |  |  |  |  |  |  |
| **Cms** | | | | | | | | |
| `cms.view` — View website CMS content | ✔ | ✔ |  |  |  |  |  |  |
| `cms.edit` — Edit draft website content | ✔ | ✔ |  |  |  |  |  |  |
| `cms.publish` — Publish website content | ✔ |  |  |  |  |  |  |  |
| `legal.manage` — Edit legal pages and policies | ✔ |  |  |  |  |  |  |  |
| **Projects** | | | | | | | | |
| `projects.view_all` — View every project | ✔ |  |  |  |  |  |  |  |
| `projects.view_assigned` — View projects the user is assigned to | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  |  |
| `projects.view_own` — View projects the client owns | ✔ |  |  |  |  |  |  | ✔ |
| `projects.create` — Create a project | ✔ |  |  |  |  |  |  |  |
| `projects.assign` — Assign users to projects (Admin only) | ✔ |  |  |  |  |  |  |  |
| `projects.update_status` — Change project status | ✔ | ✔ |  |  |  |  |  |  |
| **Milestones** | | | | | | | | |
| `milestones.view` — View milestones on accessible projects | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `milestones.create` — Create milestones directly | ✔ | ✔ |  |  |  |  |  |  |
| `milestones.propose` — Propose milestones for approval | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  |  |
| `milestones.approve` — Approve or reject proposed milestones | ✔ | ✔ |  |  |  |  |  |  |
| `tasks.view` — View tasks on accessible projects | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `tasks.manage` — Create and assign tasks | ✔ | ✔ |  |  |  |  |  |  |
| `tasks.update_assigned` — Update tasks assigned to the user | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  |  |
| **Files** | | | | | | | | |
| `files.upload` — Upload files/deliverables to accessible projects | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `files.download` — Download files on accessible projects | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `files.manage` — Delete or replace files | ✔ |  |  |  |  |  |  |  |
| **Pricing** | | | | | | | | |
| `pricing.view` — View pricing rules and priority charges | ✔ | ✔ |  |  |  |  |  |  |
| `pricing.manage` — Edit pricing rules and priority charges | ✔ |  |  |  |  |  |  |  |
| `estimates.create` — Create feature-based price estimates | ✔ | ✔ |  |  |  |  |  |  |
| `quotes.view` — View quotations | ✔ | ✔ |  |  |  |  |  | ✔ |
| `quotes.create` — Draft quotations | ✔ | ✔ |  |  |  |  |  |  |
| `quotes.send` — Send quotations to clients | ✔ |  |  |  |  |  |  |  |
| **Billing** | | | | | | | | |
| `invoices.view_all` — View all invoices | ✔ |  |  |  |  |  |  |  |
| `invoices.view_own` — View own invoices (client) | ✔ |  |  |  |  |  |  | ✔ |
| `invoices.create` — Draft invoices | ✔ |  |  |  |  |  |  |  |
| `invoices.issue` — Issue invoices as payable | ✔ |  |  |  |  |  |  |  |
| `invoices.void` — Void invoices | ✔ |  |  |  |  |  |  |  |
| `receipts.view_own` — View own receipts (client) | ✔ |  |  |  |  |  |  | ✔ |
| `payments.view_all` — View all client payments | ✔ |  |  |  |  |  |  |  |
| `payments.pay` — Pay own invoices through the gateway (client) | ✔ |  |  |  |  |  |  | ✔ |
| `payments.reconcile` — Reconcile or manually record client payments | ✔ |  |  |  |  |  |  |  |
| **Payouts** | | | | | | | | |
| `payouts.request` — Submit a payout request (staff/freelancer) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  |
| `payouts.view_own` — View own payout requests and history | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  |
| `payouts.view_all` — View all payout requests | ✔ |  |  |  |  |  |  |  |
| `payouts.approve` — Approve or reject payout requests (Admin only) | ✔ |  |  |  |  |  |  |  |
| `payouts.record` — Record a manual payout as paid (Admin only) | ✔ |  |  |  |  |  |  |  |
| `commissions.view_own` — View own commission ledger | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  |
| `commissions.view_all` — View all commission ledgers | ✔ |  |  |  |  |  |  |  |
| `commissions.rules.manage` — Edit commission rules (Admin only) | ✔ |  |  |  |  |  |  |  |
| **Referrals** | | | | | | | | |
| `referrals.view_own` — View own referral dashboard | ✔ |  |  |  |  |  | ✔ |  |
| `referrals.review` — View referral applications | ✔ |  |  |  |  |  |  |  |
| `referrals.decide` — Approve or reject referral applications (Admin only) | ✔ |  |  |  |  |  |  |  |
| **Support** | | | | | | | | |
| `chat.use` — Use project chat on accessible projects | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `revisions.request` — Request a revision (client) | ✔ |  |  |  |  |  |  | ✔ |
| `revisions.manage` — Triage and resolve revisions | ✔ | ✔ |  |  |  |  |  |  |
| `bugs.report` — Report a bug (client) | ✔ |  |  |  |  |  |  | ✔ |
| `bugs.manage` — Triage and resolve bugs | ✔ | ✔ |  |  |  |  |  |  |
| `warranty.view_own` — View own warranty status (client) | ✔ |  |  |  |  |  |  | ✔ |
| `warranty.manage` — Manage warranty records | ✔ | ✔ |  |  |  |  |  |  |
| **Intake** | | | | | | | | |
| `requirements.submit` — Submit a requirements form (client) | ✔ |  |  |  |  |  |  | ✔ |
| `requirements.view` — View submitted requirements | ✔ | ✔ |  |  |  |  |  |  |
| `appointments.book` — Book an appointment (client) | ✔ |  |  |  |  |  |  | ✔ |
| `appointments.manage` — Manage appointments and availability | ✔ | ✔ |  |  |  |  |  |  |
| **Renewals** | | | | | | | | |
| `renewals.view` — View domain/hosting renewals | ✔ | ✔ |  |  |  |  |  |  |
| `renewals.manage` — Create and edit renewal records | ✔ | ✔ |  |  |  |  |  |  |
| `renewals.approve` — Record Admin approval for a renewal (Admin only) | ✔ |  |  |  |  |  |  |  |
| `renewals.confirm_provider` — Record provider confirmation and mark Renewed (Admin only) | ✔ |  |  |  |  |  |  |  |
| **Platform** | | | | | | | | |
| `reports.operational` — View operational reports | ✔ | ✔ |  |  |  |  |  |  |
| `reports.financial` — View financial reports (Admin only) | ✔ |  |  |  |  |  |  |  |
| `audit.view` — View audit logs (Admin only) | ✔ |  |  |  |  |  |  |  |
| `backups.manage` — Create/download backups (Admin only) | ✔ |  |  |  |  |  |  |  |
| `settings.manage` — Edit platform settings (Admin only) | ✔ |  |  |  |  |  |  |  |
