# 04 · Role & permission matrix (DEFAULTS)

Generated from `config/permissions.php` with `php bin/console.php permissions:matrix`. Regenerate after any change; do not edit by hand.

**These are the shipped defaults.** The live grants are stored in the database and can be edited in the admin UI (*Roles & permissions*); `permissions:sync` never overwrites those edits (it only gives defaults to permissions that are new). Use `permissions:sync --reset-roles` to restore these defaults.

## Rules enforced server-side (and pinned by tests)

1. **Ten roles:** Super Admin / Owner, Administrator, Manager / Salaried Staff, Finance Staff, Support Staff, Freelancer, Video Editor, Website Developer, Referral Partner, Client.
2. **No wildcard, no implicit bypass.** Super Admin holds every permission explicitly (locked role, re-granted on every sync).
3. **Levels.** Super Admin 100 · Administrator 90 · Manager 60 · Finance/Support 50 · Freelancer/Editor/Developer 30 · Referral Partner 20 · Client 10. A user may act on another user, or edit a role, only if the target's level is **strictly lower** than their own. Therefore: nobody can modify the Owner through the UI, Administrators cannot modify each other, and nobody can modify themselves via admin screens.
4. **Assignable roles.** An actor can hand out only roles with a strictly lower level. Administrators cannot create Administrators; the Owner role can only be created with the console.
5. **Sensitive permissions** (marked in the role editor) can be granted or revoked only by someone holding `roles.manage_sensitive` (Owner). A non-owner who submits a crafted request containing a sensitive grant gets 403 and an audit entry.
6. **No self-escalation.** An actor can never grant a permission they do not hold themselves.
7. **Only Administrator and Super Admin** hold `projects.assign`, `projects.view_all`, `projects.create`, `users.*`, `audit.view`, transaction/payout approvals.
8. Scoped permissions (`*.view_assigned`, `*.view_own`) open a feature; **per-record access is enforced by `ProjectPolicy`** (assigned / own / all), returning 404 for anything else.
9. Referral Partners and Finance Staff hold no project permissions at all.

Users can hold several roles; permissions are the union.

## Default matrix

| Permission | Super Admin / Owner | Administrator | Manager / Salaried Staff | Finance Staff | Support Staff | Freelancer | Video Editor | Website Developer | Referral Partner | Client |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **Users** | | | | | | | | | | |
| `users.view` — View user accounts | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `users.create` — Create/invite user accounts | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `users.edit` — Edit user profile details | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `users.deactivate` — Deactivate and reactivate accounts (revokes access) | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `users.archive` — Archive accounts | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `users.assign_roles` — Change which roles a user holds | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `users.reset_password` — Send a password-reset email to a user | ✔ | ✔ |  |  |  |  |  |  |  |  |
| **Roles** | | | | | | | | | | |
| `roles.view` — View roles and permission grants | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `roles.manage` — Edit non-sensitive permission grants of lower-ranked roles | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `roles.manage_sensitive` — Grant/revoke sensitive permissions | ✔ |  |  |  |  |  |  |  |  |  |
| **Platform** | | | | | | | | | | |
| `audit.view` — View audit logs | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `settings.view` — View system settings | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `settings.manage` — Change system settings | ✔ |  |  |  |  |  |  |  |  |  |
| `reports.operational` — View operational reports | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `reports.financial` — View financial reports | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `backups.manage` — Create/download backups | ✔ |  |  |  |  |  |  |  |  |  |
| **Projects** | | | | | | | | | | |
| `projects.view_all` — View every project | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `projects.view_assigned` — View projects the user is assigned to | ✔ | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  |  |
| `projects.view_own` — View projects owned by the client | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `projects.create` — Create projects | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `projects.edit` — Edit project details | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `projects.update_status` — Change project status | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `projects.archive` — Archive projects | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `projects.assign` — Assign and unassign team members on projects | ✔ | ✔ |  |  |  |  |  |  |  |  |
| **Delivery** | | | | | | | | | | |
| `milestones.view` — View milestones on accessible projects | ✔ | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `milestones.create` — Create milestones directly | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `milestones.propose` — Propose milestones for approval | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ |  |  |
| `milestones.approve` — Approve or reject proposed milestones | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `tasks.view` — View tasks on accessible projects | ✔ | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `tasks.manage` — Create and assign tasks | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `tasks.update_assigned` — Update tasks assigned to the user | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ |  |  |
| `files.upload` — Upload files to accessible projects | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ |  | ✔ |
| `files.download` — Download files on accessible projects | ✔ | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `files.manage` — Delete or replace files | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `deliverables.view` — View deliverables | ✔ | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `deliverables.upload` — Upload deliverables | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ |  |  |
| `deliverables.publish` — Publish deliverables to the client | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| **Website** | | | | | | | | | | |
| `cms.view` — View website content | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `cms.edit` — Edit draft website content | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `cms.publish` — Publish website content | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `legal.manage` — Edit legal pages and policies | ✔ | ✔ |  |  |  |  |  |  |  |  |
| **Pricing** | | | | | | | | | | |
| `pricing.view` — View pricing rules and priority charges | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `pricing.manage` — Edit pricing rules and priority charges | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `estimates.create` — Create feature-based estimates | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `quotes.view` — View quotations | ✔ | ✔ | ✔ |  |  |  |  |  |  | ✔ |
| `quotes.create` — Draft quotations | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `quotes.send` — Send quotations to clients | ✔ | ✔ |  |  |  |  |  |  |  |  |
| **Finance** | | | | | | | | | | |
| `finance.view` — View financial data | ✔ | ✔ |  | ✔ |  |  |  |  |  |  |
| `invoices.view_all` — View all invoices | ✔ | ✔ |  | ✔ |  |  |  |  |  |  |
| `invoices.view_own` — View own invoices (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `invoices.create` — Draft invoices | ✔ | ✔ |  | ✔ |  |  |  |  |  |  |
| `invoices.edit` — Edit draft invoices | ✔ | ✔ |  | ✔ |  |  |  |  |  |  |
| `invoices.issue` — Issue invoices as payable | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `invoices.void` — Void invoices | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `receipts.view_own` — View own receipts (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `payments.view_all` — View all client payments | ✔ | ✔ |  | ✔ |  |  |  |  |  |  |
| `payments.pay` — Pay own invoices (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `payments.record` — Record payments | ✔ | ✔ |  | ✔ |  |  |  |  |  |  |
| `payments.reconcile` — Reconcile payments with the gateway | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `transactions.approve` — Approve financial transactions | ✔ | ✔ |  |  |  |  |  |  |  |  |
| **Payouts** | | | | | | | | | | |
| `payouts.request` — Submit a payout request | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ | ✔ |  |
| `payouts.view_own` — View own payout requests | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ | ✔ |  |
| `payouts.view_all` — View all payout requests | ✔ | ✔ |  | ✔ |  |  |  |  |  |  |
| `payouts.approve` — Approve or reject payout requests | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `payouts.record` — Record a manual payout as paid | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `commissions.view_own` — View own commission ledger | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ | ✔ |  |
| `commissions.view_all` — View all commission ledgers | ✔ | ✔ |  | ✔ |  |  |  |  |  |  |
| `commissions.rules.manage` — Edit commission rules | ✔ | ✔ |  |  |  |  |  |  |  |  |
| **Referrals** | | | | | | | | | | |
| `referrals.view_own` — View own referral records | ✔ | ✔ |  |  |  |  |  |  | ✔ |  |
| `referrals.review` — View referral applications | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `referrals.decide` — Approve or reject referral applications | ✔ | ✔ |  |  |  |  |  |  |  |  |
| **Support** | | | | | | | | | | |
| `chat.use` — Use project chat on accessible projects | ✔ | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `revisions.request` — Request a revision (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `revisions.manage` — Triage and resolve revisions | ✔ | ✔ | ✔ |  | ✔ |  |  |  |  |  |
| `bugs.report` — Report a bug (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `bugs.manage` — Triage and resolve bugs | ✔ | ✔ | ✔ |  | ✔ |  |  |  |  |  |
| `warranty.view_own` — View own warranty status (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `warranty.manage` — Manage warranty records | ✔ | ✔ | ✔ |  | ✔ |  |  |  |  |  |
| `requirements.submit` — Submit a requirements form (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `requirements.view` — View submitted requirements | ✔ | ✔ | ✔ |  | ✔ |  |  |  |  |  |
| `appointments.book` — Book an appointment (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `appointments.manage` — Manage appointments | ✔ | ✔ | ✔ |  | ✔ |  |  |  |  |  |
| **Renewals** | | | | | | | | | | |
| `renewals.view` — View domain/hosting renewals | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `renewals.manage` — Create and edit renewal records | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `renewals.approve` — Record Admin approval for a renewal | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `renewals.confirm_provider` — Record provider confirmation and mark Renewed | ✔ | ✔ |  |  |  |  |  |  |  |  |
