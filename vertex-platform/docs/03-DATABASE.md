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
