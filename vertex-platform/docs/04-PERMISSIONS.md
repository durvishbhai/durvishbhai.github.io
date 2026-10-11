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

10. **Phase 2 additions.** Enquiries: `EnquiryPolicy` (all / assigned / own-submitted for referral partners) · Clients: `ClientPolicy` (all / assigned-via-project / own) · Tasks: `TaskPolicy` (staff-level access to the task's project AND `tasks.view`; clients never reach tasks) · Pricing: `PriceChangeService::canView` (staff holding a pricing permission, or the owning client).
11. **Managers cannot assign people to projects** (`projects.assign` is Administrator/Owner only), cannot create projects or record approved quotations. They *can* assign **tasks** to existing project members (`tasks.assign`). Workers can never assign tasks, even to themselves.
12. **The client decides price changes.** `pricing.respond_change` is held by the Client role only; no staff role (including Administrator) can approve a priority price change on a client's behalf.
13. **Workers see no money**: no `pricing.*`, no budget, no internal project notes (`projects.notes_internal`) by default. Grant them in the role editor if the business wants it.

14. **Phase 3 additions.** Deliverables: `DeliverablePolicy` (staff need `deliverables.view`; `deliverables.view_drafts` is what lets anyone see unpublished work; clients and staff without it see only what was published). Uploading (`deliverables.upload`), internal QA (`deliverables.review`), publishing (`deliverables.publish`) and archiving (`deliverables.archive`) are separate permissions; **workers can upload but never publish, review or archive by default.** Files: `files.download` plus the same visibility rule on every download. Revisions: `RevisionAccess` (staff: `revisions.manage` + project access; client: only requests they submitted). Classifying as chargeable and proposing cost is `revisions.classify` (manager/administrator); overriding a classification and editing revision policies are `revisions.override` / `revisions.policy` (administrator, sensitive). **Only the client** holds `deliverables.respond` (approve/reject) and `revisions.respond_cost` (approve extra cost): no staff role, including Administrator, can accept on a client's behalf. Referral partners hold none of these.

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
| `projects.edit` — Edit project details | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `projects.update_status` — Change project status | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `projects.archive` — Archive projects | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `projects.assign` — Assign and unassign team members on projects | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `projects.complete` — Confirm project completion (and reopen) | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `projects.set_priority` — Change project priority | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `projects.notes_internal` — View and add internal project notes | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `projects.notes_client` — Post client-visible project notes | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `projects.give_feedback` — Give feedback on own project (client) | ✔ |  |  |  |  |  |  |  |  | ✔ |
| `projects.record_approval` — Record the approved quotation baseline of a project | ✔ | ✔ |  |  |  |  |  |  |  |  |
| **Delivery** | | | | | | | | | | |
| `milestones.view` — View milestones on accessible projects | ✔ | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `milestones.create` — Create milestones directly | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `milestones.edit` — Edit milestones and change their status | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `milestones.propose` — Propose milestones for approval | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ |  |  |
| `milestones.approve` — Approve or reject proposed milestones | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `tasks.view` — View tasks on accessible projects | ✔ | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  |  |
| `tasks.create` — Create tasks on accessible projects | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `tasks.edit` — Edit tasks and move them between any statuses | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `tasks.assign` — Assign tasks to project team members | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `tasks.review` — Review tasks (approve or request changes) | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `tasks.archive` — Archive tasks | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `tasks.comment` — Comment on accessible tasks | ✔ | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  |  |
| `tasks.log_time` — Log time on tasks assigned to the user | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ |  |  |
| `tasks.update_assigned` — Update status of tasks assigned to the user | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ |  |  |
| `files.upload` — Upload files to accessible projects | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ |  | ✔ |
| `files.download` — Download files on accessible projects | ✔ | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `files.manage` — Delete or replace files | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `deliverables.view` — View deliverables | ✔ | ✔ | ✔ |  | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `deliverables.upload` — Upload deliverables | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ |  |  |
| `deliverables.publish` — Publish deliverables to the client and choose the client-facing version | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `deliverables.view_drafts` — View draft, in-review and approved-but-unpublished deliverables on accessible projects | ✔ | ✔ | ✔ |  |  | ✔ | ✔ | ✔ |  |  |
| `deliverables.review` — Approve internally or send back deliverables in QA review | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `deliverables.archive` — Archive and restore deliverables | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `deliverables.respond` — Approve, reject or request revisions on own published deliverables (client) | ✔ |  |  |  |  |  |  |  |  | ✔ |
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
| `pricing.request_change` — Propose a price change (e.g. from a priority change) for client approval | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `pricing.respond_change` — Approve or reject a proposed price change on own project (client) | ✔ |  |  |  |  |  |  |  |  | ✔ |
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
| `revisions.request` — Request a revision (client) | ✔ |  |  |  |  |  |  |  |  | ✔ |
| `revisions.manage` — Triage and resolve revisions | ✔ | ✔ | ✔ |  | ✔ |  |  |  |  |  |
| `revisions.classify` — Classify revisions as included or potentially chargeable and propose extra cost | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `revisions.override` — Override a revision classification (audited) | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `revisions.policy` — Manage revision policies and terms | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `revisions.respond_cost` — Approve or decline a proposed additional cost on own project (client) | ✔ |  |  |  |  |  |  |  |  | ✔ |
| `bugs.report` — Report a bug (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `bugs.manage` — Triage and resolve bugs | ✔ | ✔ | ✔ |  | ✔ |  |  |  |  |  |
| `warranty.view_own` — View own warranty status (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `warranty.manage` — Manage warranty records | ✔ | ✔ | ✔ |  | ✔ |  |  |  |  |  |
| `requirements.submit` — Submit a requirements form (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `requirements.view` — View submitted requirements | ✔ | ✔ | ✔ |  | ✔ |  |  |  |  |  |
| `appointments.book` — Book an appointment (client) | ✔ | ✔ |  |  |  |  |  |  |  | ✔ |
| `appointments.manage` — Manage appointments | ✔ | ✔ | ✔ |  | ✔ |  |  |  |  |  |
| **Crm** | | | | | | | | | | |
| `enquiries.view_all` — View every enquiry | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `enquiries.view_assigned` — View enquiries the user owns | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `enquiries.view_own` — View enquiries the user submitted (referral partner) | ✔ |  |  |  |  |  |  |  | ✔ |  |
| `enquiries.create` — Create enquiries manually | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `enquiries.edit` — Edit enquiry details and attachments | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `enquiries.assign` — Assign an internal owner to an enquiry | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `enquiries.move_stage` — Move enquiries between open pipeline stages and to Lost | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `enquiries.mark_won` — Mark an enquiry Won (explicit, confirmed action) | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `enquiries.reopen` — Reopen a Won/Lost enquiry that was not converted | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `enquiries.convert` — Convert a Won enquiry into a client and draft project | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `enquiries.notes` — Add notes, contact history and follow-ups | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `enquiries.archive` — Archive enquiries | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `enquiries.submit_referral` — Submit referral enquiries (referral partner) | ✔ |  |  |  |  |  |  |  | ✔ |  |
| `crm.configure` — Configure CRM pipeline stages | ✔ | ✔ |  |  |  |  |  |  |  |  |
| **Clients** | | | | | | | | | | |
| `clients.view_all` — View every client record | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `clients.view_assigned` — View clients of projects the user is assigned to | ✔ | ✔ | ✔ |  | ✔ |  |  |  |  |  |
| `clients.view_own` — View own client record (client portal) | ✔ |  |  |  |  |  |  |  |  | ✔ |
| `clients.create` — Create client records | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `clients.edit` — Edit client records | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `clients.archive` — Archive clients | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `clients.merge` — Merge duplicate client records | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `clients.invite` — Invite a client to the portal | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `clients.notes_view` — View restricted client notes | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `clients.notes_add` — Add restricted client notes | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| **Renewals** | | | | | | | | | | |
| `renewals.view` — View domain/hosting renewals | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `renewals.manage` — Create and edit renewal records | ✔ | ✔ | ✔ |  |  |  |  |  |  |  |
| `renewals.approve` — Record Admin approval for a renewal | ✔ | ✔ |  |  |  |  |  |  |  |  |
| `renewals.confirm_provider` — Record provider confirmation and mark Renewed | ✔ | ✔ |  |  |  |  |  |  |  |  |
