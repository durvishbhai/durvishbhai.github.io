# 14 · Phase 6 — Referral Partner portal and commission management

Migration `0010_referral_partners.sql` (forward-only). Code: `app/Services/{PartnerApplicationService,PartnerService,ReferralService,ClientPaymentService,CommissionRules,CommissionService,PayoutService,ReferralMailService,SecretBox,Money}.php`, controllers `PartnerApplyController`, `Partner/PortalController`, `Referrals/*`, views under `app/Views/{partner,referrals,commissions,payouts,client-payments}`, config `config/referrals.php`.

## 0. Read this first: what exists and what does not

* **There is still no quotation, invoice or payment-gateway module** (see `13-FINAL-AUDIT.md`). Phase 6 had to decide where "a verified qualifying payment" comes from. It adds **one small table, `client_payments`**, as the *integration point*: a payment received from a client, recorded by one person and **verified by a different person**, optionally refunded/reversed later. Commission is computed **only** from verified rows of this table. When invoices/payments are built, they should write to (or replace the source of) `client_payments`; nothing else in the commission engine has to change. **No existing client, project, invoice or payment record was modified** (there are no invoices or payments to modify; `projects`, `clients`, `change_requests` are only read; `EndToEndWorkflowTest` still asserts no invoice/quotation/webhook tables exist).
* **No payment gateway, no webhook endpoint.** "Duplicate webhook prevention" is implemented at the level that exists: a payment's external transaction reference is globally unique (`client_payments.external_key`, a SHA-256 of method+reference), so replaying the same bank/gateway reference cannot create a second row, and re-verifying a verified payment is a no-op. When a gateway is added, its webhook handler must call `ClientPaymentService::record(..., idempotent: true)` with the provider's event id as `external_ref`.
* **Email is not configured** in the development environment. Referral status emails are preview-and-confirm only; with no mail driver the confirmation records "not sent (email not configured)" and no screen claims delivery.
* **Payouts are manual**: the system records that a person paid a partner by bank/UPI/etc.; it moves no money.

## 1. Commission rules: the exact formula

The agreed structure is **15% in two milestones: 10% after the verified qualifying advance payment, 5% after full payment.** Two readings of "10% + 5%" are possible, so the engine supports both as a versioned, configurable *formula mode* and ships **`cumulative`** as default. **This is a business decision that needs your confirmation (D-60).**

Definitions (all money is integer paise; `floor` is integer floor):

* **Eligible amount of a payment** = 0 unless `status = verified`; otherwise `gross − (tax if exclude_tax) − (refunded if net_of_refunds)`, proportionally: the tax share is removed from what remains after refunds. A `reversed` payment (bounced/charged back in full) is worth 0. Unverified payments are worth 0, always.
* **Contract value** = project `approved_amount_minor` + approved client change requests (`include_change_requests`, default on). If the project has no approved amount, the full-payment milestone cannot trigger.
* **Received** = Σ verified payments, `gross − refunded` (tax included: the question is "has the client paid what they owe", not "what is commissionable").
* **Milestone 1 (advance) triggers** when Σ eligible amounts of payments with role *advance* ≥ the minimum advance (`advance_min_bps` of contract value; default 0 = any verified advance), **or** the project is already paid in full. Never if the project is cancelled and the rule `advance_without_completion = reverse`.
* **Milestone 2 (full payment) triggers** when *received* ≥ contract value − `full_payment_tolerance`, the project is **not cancelled**, and milestone 1 has been reached. **Accepting a quotation, approving a project or marking it complete never triggers it**; only verified money does.

Amounts:

| Mode | Milestone 1 | Milestone 2 |
|---|---|---|
| **cumulative** (default) | `floor(base₁ × 10%)` | `max(0, floor(base₂ × 15%) − milestone 1 net)` where base₂ = all eligible amounts → total earned = **15% of the eligible money actually received** |
| **per_milestone** | `floor(base₁ × 10%)` | `floor(base₂ × 5%)`, base₂ = eligible amounts **not** counted in milestone 1 → each payment is counted in exactly one milestone |

Worked example (contract ₹1,00,000, no tax, advance ₹50,000 then balance ₹50,000):

* cumulative: M1 = 10% × 50,000 = **₹5,000**; after full payment M2 = 15% × 1,00,000 − 5,000 = **₹10,000**; total **₹15,000**.
* per_milestone: M1 = **₹5,000**; M2 = 5% × 50,000 = **₹2,500**; total ₹7,500.

If the partner's contract means "10% of the advance, then 5% of the final payment" use `per_milestone`; if it means "15% of the project value in total, 10% of which is released at advance", keep `cumulative`. Choose in *Commissions → Rules* (needs `commissions.rules.manage` + password). Every change creates a new rules **version** with a reason; **an existing entry keeps the rules (and a full snapshot) it was calculated under**, so history is never rewritten.

Other configurable rules (defaults in `config/referrals.php`): `advance_rate_bps` 1000, `full_rate_bps` 500, `advance_min_bps` 0, `exclude_tax` on, `net_of_refunds` on, `include_change_requests` on, `full_payment_tolerance_minor` 0, `advance_without_completion` keep, `payable_hold_days` 0, `min_payout_minor` 0, `separation_of_duties` on.

Behaviours that are pinned by tests:

* **Never on unpaid amounts:** an unverified, reversed or refunded payment contributes 0; an unpaid invoice has no row at all.
* **Never counted twice:** one payment is one row; the same transaction reference cannot be recorded twice; re-verifying or re-evaluating is idempotent (`evaluateProject` can run any number of times and produces the same ledger); `commission_entry_payments` stores which payment contributed how much to which entry; per_milestone never counts a payment in both milestones.
* **Partial payments / multiple payments:** the base is the sum of eligible amounts; the advance milestone waits for the minimum advance; the full milestone waits for the full contract value.
* **Advance without completion:** a cancelled project never earns milestone 2. Milestone 1 stays earned (`keep`, default) or is reversed (`reverse`; if already paid, a negative adjustment carried against future payouts).
* **Refund/chargeback after approval:** the original entry is never edited. A negative *adjustment line* (status Payable, child of the entry) is added; before any payout touched the entry with a full loss the entry itself becomes *Reversed*. If the project is paid again, a new generation of the milestone is created (`seq + 1`).
* **Before approval** an entry (Pending Eligibility / Eligible for Review) is recalculated in place when payments change; the history keeps before/after.
* **Stored with each entry:** milestone, rate, base, amount, the contributing payments and amounts, the rules version and snapshot, the contract value, advance threshold, whether the project was fully paid/cancelled, and the formula as text (visible to staff on the entry page).

### Ledger statuses
`pending_eligibility` → `eligible_for_review` → `approved` → `payable` → `payout_requested` → `paid`; side states `on_hold` (with the status it was held from; a reserved entry cannot be held), `adjusted`, `reversed`. Nothing is deleted; every transition writes `commission_history` and the audit log. Approving needs `commissions.approve` **and** the password, and (separation of duties) cannot be done by the person who recorded or verified a payment behind the entry. Approved entries become Payable after `payable_hold_days`.

## 2. Partner applications
Public form `/partners/apply` (name, email, phone, organisation, professional background, referral source, optional photo/document, mandatory terms agreement; honeypot, per-IP rate limit, CSRF). Terms are versioned (`partner_terms`, recorded by an authorised admin under *Partner terms*); **applications are closed until terms exist**, and the agreed version + timestamp are stored. A unique reference `PAP-YYYY-NNNNN`; statuses Received → Under Review → Approved/Rejected. **Only `referrals.decide` (Administrator/Owner) can approve or reject**, approval needs the admin's password and rejection needs a reason (≥10 characters). Approval **never activates an account**: it creates a *pending* user and sends the normal secure invitation (`UserService::adminCreate`), or, if the applicant was signed in when applying, adds the partner role to **that same account** (the application is never linked to an account by email match). A second open application from the same email is a silent no-op (same response, so the form cannot be used to discover who applied). The optional file is stored in the private file store and downloadable only by reviewers.

## 3. Referrals and attribution
Partners submit prospects (name, email, phone, business, service, description, budget, notes) at `/partner/submit`; a hidden token makes double-submit idempotent. A partner may submit the same prospect only once while it is open. Statuses: Received, Under Review, Approved, Contacted, Qualified, Quote Sent, **Converted**, Not Converted, Rejected. Staff move between them along `config/referrals.php → referral_transitions`; **Converted is not reachable by a status change**: it needs a real, active project (`markConverted`), so approval or a sent quotation never converts a referral. Rejected/Not Converted need a reason the partner can read. Internal notes are staff-only.

Attribution (who is credited) is a separate decision with a mandatory reason, made only by `referrals.decide`: **credited / not credited (denied) / duplicate**.

* **Duplicate detection** on submit: same partner + same email/phone/company → blocked. A different partner (or an existing CRM enquiry/client) matching on normalised email, phone digits or company → the new referral is flagged `suspected`, attribution `conflict`; **nothing is merged and no client is created or linked automatically**. Staff see the competing claims side by side; partners never see each other.
* **One credited referral per contact and per project** (`UNIQUE credit_project_id`; a second credit is refused by the database as well as the service).
* **Locked once commission eligibility exists:** when any entry of the referral reaches Eligible for Review, `attribution_locked` is set; the project link and credit decision can no longer change (corrections are commission adjustments, never edits). *There is deliberately no override path in the UI.*
* Linking to CRM: an enquiry can be created from an approved referral (never merging), existing enquiry/project can be linked; converting a CRM enquiry to a project propagates to the credited referral. Every decision and link is in `referral_history` and the audit log.
* Partners see only status, the reason given to them, and a plain credit line ("credited / not credited / under review"); never the CRM record, price, invoice, payment or internal notes.

## 4. Payout workflow
1. Partner saves payout details (UPI/bank) with their password; stored **encrypted (AES-256-GCM, key derived from `APP_KEY`)**, only a masked hint is shown back. Needs `APP_KEY` set; changing it makes old details unreadable (re-enter them).
2. Partner requests an amount ≤ their free balance (Payable − reserved). In one transaction the partner row is locked `FOR UPDATE`, the balance recomputed, entries reserved **FIFO** (negative adjustments netted first) via `payout_allocations`. Concurrent requests cannot over-reserve (proved by subprocess race tests: 6×₹6,000 against ₹15,000 → exactly 2 succeed; 10×₹1,700 → exactly 8). A hidden token makes double-submit idempotent.
3. Staff (`payouts.approve`) move it to Under Review, then **Approve** (password; ledger and balance re-verified) or **Reject with a reason** (reserved amount released). The partner can cancel while Requested/Under Review (released).
4. **Approval is not payment.** Someone with `payouts.record` marks Processing and then **records the manual payment** (method, transaction reference, date, amount = approved amount, note; password). The reference is globally unique. With separation of duties on (default) the approver cannot record the payment. Approver and payer are stored. Payout details are shown to the payer only after pressing "Reveal", and each reveal is audited.
5. On Paid the reserved entries become Paid. History is kept for every state; the partner sees status, amount, payment method/date/reference and the message from the team.

## 5. Permissions (new rule 16 in `04-PERMISSIONS.md`)
Partner: `referrals.view_own`, `referrals.submit`, `commissions.view_own`, `payouts.request`, `payouts.view_own` (and the access also needs an **active** `referral_partners` row; a worker or staff member holding a key without a partner record gets 404). Staff: `referrals.review/manage/decide`, `commissions.view_all/approve/adjust/rules.manage`, `payouts.view_all/approve/record`, `payments.view_all/record/reconcile`. **Finance Staff** records payments and payouts but cannot approve commissions or payouts, verify payments or decide applications. **Administrator/Owner** do all, but never the same step twice on the same item (separation of duties). Clients and workers have none of these.
**Existing databases:** run `php bin/console.php migrate` and then `php bin/console.php permissions:sync` (adds new permissions). New default grants to *existing* roles (for example Finance `payouts.record`) only apply with `permissions:sync --reset-roles` (which discards role edits made in the UI) or by editing the role in *Admin → Roles*.

## 6. Dashboards, search, notifications
Partner `/partner`: counts by referral status, commission pending/approved/payable/paid, waiting-for-payment milestones, pending payouts, histories, plain-language explanations of Pending/On hold/Adjusted. Staff `/referrals/dashboard`: applications, new/duplicate/in-progress/converted referrals, commissions awaiting review, eligible balance, payout requests, payments to verify, adjustments/reversals, with date range; every list has search, filters, pagination. In-app notifications (existing `Notifier`, de-duplicated) for application decisions, referral status, commission approval/hold/adjustment and payout decisions. Emails: see §0.

## 7. Security summary
Server-side permission check on every route **and** re-checked in the service; 404 for records the user may not know exist; partner queries are scoped by `partner_id` in SQL; CSRF on all POSTs (route sweep proves none opt out); prepared statements only; output escaped; step-up password for every financial decision; `Audit::record` for applications, terms, referral status/attribution/link/convert, payments (recorded/verified/refunded), commission calculate/approve/hold/release/adjust/reverse/rule changes, payout request/review/approve/reject/pay/detail-reveal; **no payout detail, password or token is ever written to the audit log** (tested).

## 8. Manual testing (development database, APP_ENV=local)
1. `php bin/console.php migrate && php bin/console.php permissions:sync --reset-roles`, `php bin/console.php db:seed-dev`; start `php -S 127.0.0.1:8099 -t public bin/dev-router.php`.
2. As the Administrator: *Referral partners → Partner terms*, record terms (password).
3. In a private window open `/partners/apply` and apply (or sign in as the seeded referral partner first). As Administrator open the application, Approve (password). A signed-in applicant gets the role on their own account; an anonymous applicant gets a *pending* account and an invitation (see `storage/logs/mail-*.log` with `MAIL_DRIVER=log`).
4. As the partner: *Partner portal → Payout details* (password), *Submit a referral*.
5. As Administrator: *Referrals →* open it → Under Review → Approved; record **Credit this partner** (reason); open a project's id and **Mark converted** (project must have `approved_amount_minor`, e.g. `UPDATE projects SET approved_amount_minor = 10000000 WHERE code = 'VIS-2026-L0001'` in the dev DB).
6. As Finance: *Client payments → Record a payment* (advance ₹50,000). As the Owner open it and **Verify**. Commission *Advance milestone* ₹5,000 appears as Eligible for Review. As the *Administrator* (not the verifier) approve it. Record the second payment ₹50,000, verify as Owner: the Full-payment milestone ₹10,000 (cumulative) appears.
7. As the partner: *My commissions* (Payable), *Payouts → Request* ₹5,000. As Administrator: Approve (nothing is paid yet). As Finance: *Reveal payout details*, **Record the payment**. The partner sees Paid with the reference.
8. Try the negative cases: partner opens another partner's `/partner/r/<id>` (404); finance tries to approve a payout (403); approver tries to record the payment (refused); refund a verified payment and watch the commission adjust.

## 9. Known limitations
* No invoice/quotation/gateway module; `client_payments` is a manual, two-person entry screen and the future integration point. No webhook endpoint (see §0).
* Commission formula mode (cumulative vs per-milestone) awaits business confirmation (D-60).
* Payouts are manual and single-currency (INR); no bank file export, no tax (TDS/GST) handling on payouts: **ask an accountant** whether commission payouts need TDS or GST invoices.
* No scheduler: "Payable after N days" is evaluated when the partner or staff open the relevant page (`releaseDue`), not by a cron job.
* The attribution lock has no override screen; a wrong credit after eligibility must be corrected with commission adjustments.
* Referral emails are never sent automatically and need working mail configuration.
* Terms text is a placeholder to be written/approved by the business; this is not legal advice.
