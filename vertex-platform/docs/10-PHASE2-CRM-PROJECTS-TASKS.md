# 10 · Phase 2 — CRM, project management and task tracking

Status: **built and tested** (see "Test results"). Phase 3 has **not** been started. Everything below is what the code does today; limitations and open decisions are listed explicitly.

## What exists

| Area | Capability |
|---|---|
| **CRM enquiries** | Public requirements form (`/request-quote`), contact form (`/contact`), appointment *request* (`/book-appointment`), manual staff creation, referral-partner submission (`/partner/referrals`). Unique reference `ENQ-YYYY-NNNNNN`, owner, priority, source, follow-up reminders, notes, contact history, attachments, duplicate detection (never auto-merges), configurable stages with history, assignment, conversion of a **Won** enquiry into a client and **draft** project (history preserved). |
| **Pipeline stages** | 8 default stages (New Enquiry, Contacted, Requirement Review, Quote in Preparation, Quote Sent, Negotiation, Won, Lost). Open stages can be renamed/reordered/deactivated at `/admin/crm-stages`; Won/Lost carry fixed meaning (by `kind`). **Lost needs a reason.** Won is never set automatically (a quotation cannot win a deal). |
| **Clients** | Individual/business records, contacts, related enquiries and projects, restricted staff notes, status, history, safe duplicate **linking/merge** (explicit, audited, never automatic). A client sees only their own record (`/my/company`). Quotations/invoices: **integration point only** (see below). |
| **Projects** | Code `VIS-YYYY-NNNN`, client, source enquiry, service, scope, dates, 10 statuses with an explicit transition table (`config/workflow.php`), completion never automatic and needs `projects.complete` + confirmation, progress derived from tasks (display only), internal vs client-visible notes, client feedback, archive. |
| **Assignment / access** | Only Administrator/Owner assign, reassign or remove people (`projects.assign`); every change is audited and written to the project timeline; removing a member releases their open tasks. Enforced on every URL, download, list/search and JSON endpoint by the `ProjectPolicy` / `TaskPolicy` / `EnquiryPolicy` / `ClientPolicy` classes (404 for anything not visible). |
| **Tasks** | Title, description, project, milestone, assignee, creator, dates, 8 statuses with an explicit machine, priority, estimate, **logged time**, dependencies (cycle-safe, same project only), comments, attachments, completion timestamp, review (QA) status. Workers move **their own** tasks only; completion of reviewed tasks needs a reviewer who is not the assignee. |
| **Kanban** | `/board`: eight columns, drag-and-drop **and** a "Move" control on every card (works without JavaScript). Every drag calls `POST /tasks/{id}/status` (CSRF header), which runs the same `TaskService::transition()` as the form: the browser never decides what is allowed. Filters: project, priority, assignee (me/none/person), overdue, search. Capped at 400 cards with a visible notice. |
| **Milestones & timeline** | Milestones with target date, status, completion date, related tasks, internal/client visibility; workers *propose*, managers/admins create and approve. Chronological project timeline (creation, assignments, task/status/milestone/deadline changes, client feedback, pricing events) with actor and time; clients see only client-visible entries. |
| **Deadlines** | `/deadlines`: overdue tasks and milestones, due soon (7 days, configurable), blocked tasks, tasks awaiting review, projects near/past target. Dates compare against the **application time zone**; timestamps are stored in UTC. |
| **Priority pricing foundation** | Versioned surcharge rules per priority and service (`/admin/pricing`), calculation in integer paise/basis points (half-up), approved-quotation baseline, price revisions, and **client-approved** priority changes. Details below. |

## Priority pricing — how an approved price is protected

1. An Administrator **records** the approved quotation baseline once (`projects.record_approval`, sensitive, password re-entry): reference, base price, priority charge already included. This becomes revision 1.
2. Changing the **project** priority (never a task's):
   * no baseline yet → priority changes immediately, nothing priced is affected;
   * baseline exists and the rules give the same priority charge → priority changes, price unchanged;
   * otherwise the adjustment is **calculated from the current rules**, stored (with a snapshot of the rule used) as a `pending_client` proposal, and **nothing else changes**: priority and approved price stay as they were.
3. Only the **owning client** can approve (`pricing.respond_change`). Approval appends revision N+1 and applies the priority; rejection changes nothing. Staff may withdraw a pending proposal. A stale proposal (the project changed since) cannot be approved; it is marked *superseded*.
4. No code path updates or deletes `price_revisions`, edits `approved_*` after it is set, or deletes pricing history (a test scans the source). Foreign keys to `projects` are `RESTRICT`.
5. **Nothing collects money or issues an invoice.** Collecting the difference, a revised quotation document, and updating invoices are Phase 3–5 work and will read `price_revisions` / `price_change_requests` (the integration point).

Shipped default rules are **"no surcharge"** for every priority; the business's real rates are not invented (decision D-33). Task priority and project priority are separate columns with separate code paths.

## Migrations (forward-only, applied in order)

| File | What it does | Existing data |
|---|---|---|
| `0005_crm_clients.sql` | `crm_stages`, `reference_sequences`, `enquiries` (+ activity, follow-ups, referrals link), `attachments`, `client_notes`; extends `clients` in place | Existing `clients` rows kept; contact fields **back-filled from the linked user**; `user_id` made nullable (CRM-only clients) |
| `0006_projects_tasks.sql` | Extends `projects`; `project_activity`, `project_notes`, `milestones`, `tasks`, `task_dependencies`, `task_comments`, `task_time_entries` | Legacy status mapping: `active`→`in_progress`, `archived`→`completed` **+ `archived_at` kept**; each legacy project gets a unique code `VIS-YYYY-Lnnnn`; `tasks.view` removed from the Client role (a tightening) |
| `0007_priority_pricing.sql` | `priority_pricing_rules` (+ neutral defaults), approved-baseline columns on `projects`, `price_revisions`, `price_change_requests` | No price is invented for legacy projects |

**Rollback policy** (same as Phase 1, deliberately): migrations are forward-only because this system will hold financial records. To roll back, **restore the pre-upgrade database backup** and redeploy the previous code; do not hand-edit applied migrations (drift is detected and blocks migrating). Take a backup *before* `php bin/console.php migrate`. A migration that fails part-way is **not recorded as applied** (it stays "pending"); MySQL DDL cannot be rolled back, so the operator restores the backup or ships a new forward migration. Both behaviours are tested (`Phase2UpgradeTest`).

## Setup / upgrade steps

```bash
git pull
composer install --no-dev          # or upload vendor/
php bin/console.php doctor         # also checks storage/uploads is writable
# BACK UP THE DATABASE FIRST
php bin/console.php migrate        # applies 0005-0007
php bin/console.php permissions:sync   # adds new permissions; keeps edits made in the role editor
php bin/console.php permissions:matrix > docs/04-PERMISSIONS.md   # optional regeneration
```

* New permissions are given to roles **by default only if the permission is new** (live edits are never overwritten). Existing installs therefore pick up the Phase 2 defaults automatically; to see exactly what changed, compare `docs/04-PERMISSIONS.md`.
* `storage/uploads/` must exist and be writable by PHP but **not** web-served (the shipped `.htaccess` protects `storage/`). Uploaded files are served only through `/attachments/{id}`.
* Public forms are rate limited per IP (`config/auth.php` → `public_form_ip`, 5/hour) using the existing `rate_limits` table. Behind a proxy set `TRUSTED_PROXIES` so the real client IP is used.
* No new environment variables. `APP_TIMEZONE` (default Asia/Kolkata) decides what "today" means for deadlines.
* `php bin/console.php db:seed-dev` (local only) now also creates three **fictional** enquiries, two milestones and six tasks on "Fictional Brand Website".

## Manual testing checklist

1. Submit `/request-quote` anonymously (valid + invalid, honeypot filled, 6 quick submissions) → enquiry appears in `/crm` as *New Enquiry*; the 6th is rate limited.
2. As Manager: open the enquiry, add a note, a follow-up, move to *Contacted*; move to *Lost* without a reason (refused) and with one.
3. As Administrator: assign an owner, mark *Won*, **Convert** → client + draft project created; the enquiry becomes read-only and links to both.
4. As Administrator: open the project, assign Developer + Manager. As Manager: confirm there is no "Add team member" control and `POST /projects/{id}/assignments` returns 403.
5. As Manager: add tasks, a dependency (A waits on B), try to start A (refused), complete B, start A. Try to add the reverse dependency (refused as circular).
6. As Developer (assigned): `/board` shows the project's cards; drag your own card; try to drag someone else's (not draggable; forced request returns 403/422). `/projects/{other}` → 404.
7. As Client: `/projects/{own}` shows client-visible milestones/notes only; `/tasks`, `/board` → 403; another client's project → 404.
8. As Administrator: `/admin/pricing` → set Urgent = 20 % (password). Project → Pricing → record approved quotation (₹1,00,000); as Manager raise priority to Urgent → proposal created, priority unchanged. As Client → approve → new revision, priority changes. Try approving as Manager/Admin (403).
9. `/deadlines` shows overdue/blocked/review items only for projects you can access.

## Test results

Run on PHP 8.3.6 + MariaDB 10.11 (throwaway `vertex_phpunit` database). See the final report in the pull request for the exact counts of the last full run; the suites added in Phase 2 are:

`CrmServiceTest`, `CrmHttpTest`, `ClientHttpTest`, `ProjectServiceTest`, `ProjectTaskHttpTest`, `TaskServiceTest`, `PricingTest`, `PricingHttpTest`, `Phase2UpgradeTest` (+ updated `MigrationUpgradeTest`, `ProjectAccessTest`, `FoundationTest`, `ClosureScopeTest`). Browser checks (Chromium via Playwright): real drag-and-drop persisted to MySQL, dependency-blocked and disallowed drops refused, no-JS fallback works, mobile overflow, no CSP/console errors apart from the expected 4xx refusals.

## Known limitations and unresolved dependencies

* **Quotations / invoices / payments do not exist yet** (D-01: the "existing invoice page" is not in this repository). `projects.approved_quotation_ref` is a typed reference, not a foreign key; client "related quotations/invoices" shows nothing until Phase 3/4. No money is collected anywhere.
* **Email/WhatsApp reminders are NOT implemented.** Follow-up and deadline reminders appear **on screen only** (dashboards say so). Outbound email exists (Phase 1 `Mailer`) but no reminder job is wired; do not describe reminders as active.
* Staff cannot record a client's approval on the client's behalf (e.g. a verbal/e-mailed approval). Offline approvals need an evidence-recording design (open question D-35).
* Archived dependency prerequisites are ignored when starting a task (otherwise archiving would be impossible); anyone with `tasks.archive` can therefore lift a dependency block by archiving the prerequisite. Cancelled prerequisites still block until the dependency is removed.
* Internal project notes and budget are hidden from workers by default (decision D-34); change in the role editor if the business wants workers to see them.
* The board loads at most 400 cards; there is no per-column pagination yet.
* Reports/exports, bulk actions, calendar view, task templates, @mentions and notifications are not built.
* PHPStan could not be installed in the build environment (package host blocked); static checks are `php -l`, the closure-scope guard and the tests.
