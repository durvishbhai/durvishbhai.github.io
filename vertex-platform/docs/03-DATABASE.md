# 03 · Database design

Only the **Foundation** tables exist as migration `0001_foundation.sql` (verified against MariaDB 10.11). Everything else below is the *design* that later phases will migrate, module by module. Column lists show keys and the columns that carry business rules, not every column.

Conventions: InnoDB, utf8mb4, UTC, `BIGINT` money in minor units (+ `currency CHAR(3)`), basis points for rates, `public_id CHAR(32)` for URLs, `created_at/updated_at` everywhere, soft-archive (`status`/`archived_at`) instead of deleting anything financial.

## Entity relationship diagram (target design)

```mermaid
erDiagram
    %% ---------- Foundation (BUILT) ----------
    users ||--o{ user_roles : has
    roles ||--o{ user_roles : grants
    roles ||--o{ role_permissions : has
    permissions ||--o{ role_permissions : in
    users ||--o{ login_attempts : "email-based"
    users ||--o{ audit_logs : "actor (no FK)"

    %% ---------- Clients & intake ----------
    users ||--o| clients : "portal login"
    clients ||--o{ requirement_forms : submits
    clients ||--o{ appointments : books
    requirement_forms ||--o| quotations : "leads to"
    availability_slots ||--o{ appointments : fills

    %% ---------- Projects ----------
    clients ||--o{ projects : owns
    projects ||--o{ project_members : "assigned by Admin"
    users ||--o{ project_members : works_on
    projects ||--o{ milestones : has
    milestones ||--o{ tasks : has
    projects ||--o{ project_files : stores
    projects ||--o{ chat_messages : discusses
    projects ||--o{ revisions : requests
    projects ||--o{ bug_reports : reports
    projects ||--o| warranty_records : "6 months"

    %% ---------- Pricing, quotes, billing ----------
    priority_levels ||--o{ quotations : applies
    feature_catalogue ||--o{ estimate_items : priced_from
    estimates ||--o{ estimate_items : has
    estimates ||--o| quotations : becomes
    quotations ||--o{ quotation_items : has
    quotations ||--o| projects : "accepted into"
    projects ||--o{ invoices : bills
    renewals ||--o{ invoices : bills
    invoices ||--o{ invoice_items : has
    invoices ||--o{ payments : "paid by"
    payments ||--o| receipts : issues
    payments ||--o{ payment_events : "gateway log"

    %% ---------- Payouts & commissions (internal, separate from payments) ----------
    commission_rules ||--o{ commission_ledger : governs
    payments ||--o{ commission_ledger : "eligible amount received"
    users ||--o{ commission_ledger : earns
    users ||--o{ payout_requests : submits
    payout_requests ||--o| payouts : "Admin records manual payment"
    commission_ledger }o--o| payout_requests : settled_by

    %% ---------- Referrals ----------
    referral_applications ||--o| referral_partners : "APPROVED creates"
    referral_partners ||--o{ referrals : brings
    clients ||--o{ referrals : "attributed to"
    referrals ||--o{ commission_ledger : "10% advance / 5% final"
    referral_applications ||--o{ email_outbox : "after Admin confirm"

    %% ---------- Renewals ----------
    clients ||--o{ renewals : has
    renewals ||--o{ renewal_approvals : "Admin approval"
    renewals ||--o{ provider_confirmations : "provider proof"

    %% ---------- Platform ----------
    cms_pages ||--o{ cms_revisions : versions
    legal_documents ||--o{ consent_records : "accepted/consented"
    users ||--o{ notifications : receives

    users { bigint id PK
            char public_id UK
            varchar email UK
            enum status }
    roles { smallint id PK
            varchar slug UK }
    permissions { int id PK
                  varchar perm_key UK }
    audit_logs { bigint id PK
                 char prev_hash
                 char row_hash }
    projects { bigint id PK
               bigint client_id FK
               enum status
               bigint total_minor }
    project_members { bigint project_id FK
                      bigint user_id FK
                      varchar project_role
                      bigint assigned_by FK }
    invoices { bigint id PK
               enum kind "project|renewal"
               enum status
               varchar number UK
               bigint total_minor }
    payments { bigint id PK
               bigint invoice_id FK
               varchar gateway
               varchar gateway_payment_id UK
               varchar idempotency_key UK
               bigint amount_minor }
    payout_requests { bigint id PK
                      bigint user_id FK
                      bigint amount_minor
                      enum status }
    referral_applications { bigint id PK
                            enum status "RECEIVED|APPROVED|REJECTED"
                            text rejection_reason }
    renewals { bigint id PK
               enum type "domain|hosting"
               enum status }
```

## Integrity rules enforced in schema or transaction (to be covered by tests when built)

| Rule | Mechanism |
|---|---|
| Only Admin assigns projects | `project_members.assigned_by` NOT NULL, service checks `projects.assign`; only Admin holds it (tested in matrix tests) |
| Access to project data limited to assigned users | Every project-scoped query joins `project_members` (or client ownership); one policy class per module; files streamed through a controller only |
| No duplicate client payment | `UNIQUE(gateway, gateway_payment_id)`, `UNIQUE(idempotency_key)`, `UNIQUE(gateway, event_id)` on `payment_events`; payment insert inside a transaction that first does `SELECT … FOR UPDATE` on the invoice and rejects over-payment |
| Payments are immutable | No UPDATE/DELETE path; refunds are new negative rows; invoice balance is **derived** from payments, not stored as a mutable truth |
| Payment only confirmed by gateway | `captured_at` set only from signature-verified webhook/callback handler |
| Freelancer commission from amounts actually received | `commission_ledger` rows are created **by payment capture events** (`payment_id` FK), never from invoice totals |
| Referral 10% / 5% | Two ledger entries per referred project, `trigger` = `ADVANCE_RECEIVED` / `FULL_PAYMENT_RECEIVED`, unique per `(referral_id, trigger)` so they cannot double-pay |
| Rejection requires reason | `CHECK`-equivalent in service + DB `NOT NULL` via status-dependent validation; test per transition |
| Referral emails only after Admin confirm | Emails are rows in `email_outbox` created only by the confirm action (`confirmed_by`, `confirmed_at` NOT NULL) |
| Renewal invoice cannot be payable without approval | `invoices.kind='renewal'` → `ISSUED` transition requires an existing `renewal_approvals` row (service + test) |
| Renewed only with provider confirmation | `RENEWED` transition requires a `provider_confirmations` row (reference, recorded_by) |
| Priority charge reflected in quotation | `quotation_items` includes a `priority_charge` line computed from `priority_levels` at quote time and **snapshotted** (later config edits don't change issued quotes) |
| Invoice numbering | Gap-free per financial year via a `number_sequences` row locked `FOR UPDATE`; format is decision D-07 |
| Financial audit history | `Audit::record()` in the same transaction for every money state change; chain verified by `audit:verify` |

## Foundation tables (built)

`roles`, `permissions`, `role_permissions`, `users`, `user_roles`, `login_attempts`, `audit_logs`, plus the migrator's own `schema_migrations`.


## Phase 2 tables (BUILT: migrations 0005–0007)

```mermaid
erDiagram
    crm_stages ||--o{ enquiries : "current stage"
    enquiries ||--o{ enquiry_activity : "timeline / stage history"
    enquiries ||--o{ enquiry_followups : reminders
    enquiries ||--o{ attachments : "parent_type=enquiry"
    enquiries }o--o| clients : "linked / converted"
    enquiries }o--o| projects : "converted_project_id"
    users ||--o| clients : "portal login (optional)"
    clients ||--o{ client_notes : "restricted notes"
    clients ||--o{ projects : owns
    projects ||--o{ project_assignments : "Admin-made"
    projects ||--o{ project_activity : timeline
    projects ||--o{ project_notes : "internal / client_visible / feedback"
    projects ||--o{ milestones : has
    projects ||--o{ tasks : has
    milestones ||--o{ tasks : groups
    tasks ||--o{ task_dependencies : "waits on (same project)"
    tasks ||--o{ task_comments : has
    tasks ||--o{ task_time_entries : logs
    tasks ||--o{ attachments : "parent_type=task"
    priority_pricing_rules ||--o{ price_change_requests : "snapshotted in JSON"
    projects ||--o{ price_revisions : "append-only"
    projects ||--o{ price_change_requests : proposals
    price_change_requests ||--o| price_revisions : "applied_revision_id"
```

Integrity rules: the FKs from pricing/financial and history tables (`price_revisions`, `price_change_requests`, `project_activity`, `milestones`, `tasks`, notes, comments, time entries) to `projects`/`clients` are `RESTRICT`, so deleting a project can never cascade through financial history (the app never deletes projects; it archives). Non-financial exceptions: Phase 1 `project_assignments` cascades, `enquiries.converted_project_id` is `SET NULL`; `task_dependencies` has `CHECK (task_id <> depends_on_task_id)` and the service rejects cycles under a project row lock; unique keys on `enquiries.reference`, `projects.code`, `public_id` columns, `(project_id, revision_no)`, one *current* pricing rule per (service, priority) via a nullable `current_flag`; money is `BIGINT` paise, rates are basis points; everything is archived (`archived_at`) rather than deleted; `price_revisions` is append-only (no code path updates or deletes it, enforced by a source-scanning test).


## Phase 3 tables (BUILT: migration 0008)

```mermaid
erDiagram
    projects ||--o{ deliverables : has
    tasks |o--o{ deliverables : "optional"
    milestones |o--o{ deliverables : "optional"
    deliverables ||--o{ deliverable_versions : "V1, V2, ... (append-only)"
    deliverables |o--o| deliverable_versions : "published_version_id"
    stored_files ||--o{ deliverable_versions : "file_id (or external_url)"
    deliverable_versions ||--o{ deliverable_reviews : "internal QA"
    deliverable_versions ||--o{ client_approvals : "exact version"
    deliverables ||--o{ revision_requests : receives
    deliverable_versions ||--o{ revision_requests : "version reviewed"
    revision_requests ||--o{ revision_status_history : logs
    revision_requests ||--o{ revision_notes : "internal"
    revision_requests ||--o{ revision_files : attaches
    stored_files ||--o{ revision_files : stores
    revision_requests ||--o{ change_requests : "extra-cost proposals"
    revision_requests |o--o{ client_approvals : "revision_requested"
    projects ||--o{ change_requests : has
    projects ||--o{ revision_policies : "optional project terms"
    users ||--o{ notifications : receives
```

Rules: every foreign key above is `RESTRICT` (or `SET NULL` for optional user/task/milestone links); `deliverable_versions` has `UNIQUE (deliverable_id, version_no)` and `CHECK (file_id IS NOT NULL OR external_url IS NOT NULL)`; one current policy per scope via `current_flag`; `UNIQUE (user_id, dedupe_key)` on notifications and `UNIQUE (user_id, submit_token)` on client approvals/`(requested_by, submit_token)` on revisions make retries idempotent; stored paths are unique random strings.


## Phase 5: support tickets and warranty (migration 0009)

```mermaid
erDiagram
    clients ||--o{ tickets : owns
    projects |o--o{ tickets : "optional"
    users |o--o{ tickets : "requester / assignee"
    tickets ||--o| bug_reports : "kind = bug"
    tickets ||--o{ ticket_messages : "client-visible conversation"
    tickets ||--o{ ticket_notes : "staff-only notes"
    tickets ||--o{ ticket_files : attaches
    stored_files ||--o{ ticket_files : stores
    ticket_messages |o--o{ ticket_files : "optional"
    tickets ||--o{ ticket_activity : "history"
    tickets ||--o{ ticket_reads : "unread markers"
    tickets |o--o{ change_requests : "chargeable bug proposal"
    projects ||--o| project_warranties : "recorded warranty"
    warranty_terms ||--o{ project_warranties : "terms version"
    projects ||--o{ warranty_history : "append-only"
```

Rules: every foreign key is `RESTRICT` (or `SET NULL` for optional user links; `ticket_reads.user_id` cascades with the user); `UNIQUE (requester_id, submit_token)` and `UNIQUE (author_id, submit_token)` make retried form posts idempotent; one warranty row per project (`UNIQUE project_id`); one current terms version (`current_flag`); warranty status is not stored, it is derived from the recorded dates and today's date; `ticket_notes` and `ticket_messages` are different tables so no conversation query can return an internal note.

## Phase 6: referral partners, commissions, payouts (migration 0010)

```mermaid
erDiagram
    users ||--o| referral_partners : "is a partner"
    partner_terms ||--o{ partner_applications : "agreed version"
    partner_applications |o--o| referral_partners : "approved into"
    referral_partners ||--o{ partner_referrals : submits
    partner_referrals ||--o{ referral_history : "history"
    partner_referrals ||--o{ referral_email_log : "previewed emails"
    partner_referrals |o--o| enquiries : "linked CRM enquiry"
    partner_referrals |o--o| projects : "converted / credited project"
    projects ||--o{ client_payments : "verified payments"
    client_payments ||--o{ client_payment_events : "append-only"
    partner_referrals ||--o{ commission_entries : earns
    commission_entries ||--o{ commission_entry_payments : "payments counted"
    client_payments ||--o{ commission_entry_payments : "counted in"
    commission_entries |o--o{ commission_entries : "adjustment lines (parent)"
    commission_entries ||--o{ commission_history : "append-only"
    referral_partners ||--o{ payout_requests : requests
    payout_requests ||--o{ payout_allocations : reserves
    commission_entries ||--o{ payout_allocations : "reserved by"
    payout_requests ||--o{ payout_history : "append-only"
    commission_rules ||--o{ commission_entries : "version used"
```

Integrity rules in the schema: `UNIQUE partner_applications.active_key` (one open application per email), `UNIQUE (partner_id, submit_token)` on referrals and payouts (double submit), `UNIQUE partner_referrals.credit_project_id` (one credited referral per project), `UNIQUE client_payments.external_key` (a transaction reference is recorded once), `UNIQUE commission_entries (referral_id, milestone, seq)`, `UNIQUE payout_requests.payment_reference`, `UNIQUE referral_email_log.sent_key` (set only on a successful send), `UNIQUE commission_rules.current_flag` (one current version). Money is `BIGINT` paise. Foreign keys are `RESTRICT` (or `SET NULL` for optional user/CRM links); history tables are append-only by convention and by the absence of any update/delete code path.

## Phase 7: domain / hosting / SSL renewals and the scheduler (migration 0011)

```mermaid
erDiagram
    clients ||--o{ renewal_services : owns
    projects |o--o{ renewal_services : "optional"
    renewal_services ||--o{ renewal_cycles : "one per renewal attempt"
    renewal_cycles ||--o{ renewal_payments : "money received (unique transaction)"
    renewal_services ||--o{ renewal_history : "append-only"
    renewal_services ||--o{ renewal_reminders : "UNIQUE per reminder slot"
    renewal_prices }o..o{ renewal_cycles : "snapshotted at approval"
    users |o--o{ renewal_cycles : "approver / invoice / provider confirmation"
```

`renewal_services` UNIQUE (client_id, service_type, identifier); `renewal_cycles` UNIQUE (service_id, cycle_no) and holds the approved price snapshot, the quotation/invoice references, `paid_minor`, and the provider confirmation (`provider_confirmation_ref`, `provider_confirmed_by/at`, `new_expiry`); `renewal_payments.payment_key` is UNIQUE (SHA-256 of method and reference) so a transaction cannot be counted twice; `renewal_reminders` UNIQUE (service_id, expires_on, kind, offset_days, audience, channel) is the claim that makes reminders idempotent; `renewal_prices` is append-only with UNIQUE (service_type, period_months, effective_from); `scheduler_runs` records every run of `schedule:run`. There is **no column for a provider password or credential** and a test asserts it. Foreign keys are `RESTRICT` (or `SET NULL` for optional user/project links). Money is `BIGINT` paise.

