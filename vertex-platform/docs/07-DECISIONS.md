# 07 · Open business decisions and external integrations

Nothing below has been assumed in code. Each blocks the phase shown. Items marked **BLOCKER** need an answer before the stated phase can start.

## Business decisions

| ID | Decision needed | Why it matters | Blocks |
|---|---|---|---|
| **D-01 BLOCKER** | **Where is the "existing invoice page"?** This repo has none. Is it in another repo, on the Hostinger account, or planned? Provide its code/URL, data, and numbering history. | The brief says it must be preserved and extended; it cannot be preserved if it cannot be seen. | Phase 4 |
| **D-02 BLOCKER** | Is this repository the intended home? It is a public-looking GitHub Pages site for `crazybooks.me` (author site). Recommend a **new private repo** for Vertex. | Source exposure via Pages; mixing two unrelated products | before Phase 1 |
| D-03 | Production domain, Hostinger plan (PHP version selectable? SSH? cron count? mail limits?) | Deployment design, email and cron limits | Phase 1 |
| D-04 | Currency/tax: INR only? GST (GSTIN, CGST/SGST/IGST, HSN/SAC codes), TDS handling, inclusive vs exclusive pricing | Invoice/quotation math and legal format | Phase 3–4 |
| D-05 | Payment gateway (e.g. Razorpay, Cashfree, Stripe, PayU), methods (UPI/cards/netbanking), settlement account, refund policy, fee handling | Phase 5 design and PCI scope | Phase 5 |
| D-06 | Advance/final payment structure: what is an "advance" (fixed %, per quote?), milestone payments allowed? Part-payments? | Defines commission triggers and invoice states | Phase 4–6 |
| D-07 | Invoice/quote/receipt number formats and series (financial-year reset, prefixes) | Gap-free numbering legal requirement | Phase 4 |
| D-08 | Priority levels: names, charge type (percentage vs fixed), and applies-to (whole quote vs specific lines) | Quotation math | Phase 3 |
| D-09 | Feature catalogue and base prices (website features, video types), and who may edit them | Estimator | Phase 3 |
| D-10 | Warranty: starts at delivery, final payment, or acceptance? What is covered vs billable change request? | Six-month warranty tracking | Phase 7 |
| D-11 | Freelancer commission: rate per freelancer or per project? Base (ex-tax? incl. priority charge? net of gateway fees?). Behaviour on refunds/chargebacks (claw-back)? Split when several workers are on one project? | Commission ledger correctness | Phase 6 |
| D-12 | Which roles may *create* milestones directly vs only *propose* (the brief says "according to configured role permissions"). Current default: only Admin and Manager create; all workers propose. | Permission matrix | Phase 2 |
| D-13 | Referral 15%: **10% after eligible advance and 5% after full payment of what base?** Is it 10% of the advance amount and 5% of the remainder, or 10%/5% of total project value paid out in two steps? Which amounts are *eligible* (exclude tax, priority charges, renewals, repeat clients?) Is there a cap/expiry on attribution? Self-referral rules | Defines the ledger formula | Phase 6 |
| D-14 | Legal/compliance: DPDP Act obligations, data retention, privacy policy, terms, refund policy, cookie categories. Requires lawyer-reviewed text | Legal pages, consent records | Phase 8 |
| D-15 | Is a Manager salaried (may request payouts as the matrix currently allows) or paid otherwise? Should Staff (salaried) payouts be commission-like at all, or reimbursements/bonuses? | Payout permissions | Phase 6 |
| D-16 | Domain/hosting renewals: which providers (GoDaddy, Hostinger, Namecheap …)? Do they offer APIs, or will Admin confirm manually with a reference/screenshot? Who pays the provider, the client or Vertex? Reminder schedule | Renewal workflow | Phase 8 |
| D-17 | Branding: logo is silver + electric blue; the brief asks for "restrained gold accents". Phase 0 uses the logo palette with gold only for small labels. Confirm or supply a brand guide / vector logo / fonts | Visual direction | Phase 1 |
| D-18 | Public website copy, services list, pricing visibility, testimonials/case studies, contact details. Current home-page text is placeholder and makes no claims beyond the brief | CMS content | Phase 8 |
| D-19 | Language(s), time zone for display (currently Asia/Kolkata), working hours for appointments | Appointments, notifications | Phase 7 |
| D-20 | Who are the first Admin(s)? Admin must have 2FA from day one? | Bootstrap & policy | Phase 1 |

## Decisions taken in Phase 1 (please confirm or change)

| ID | Decision I made | Why / how to change |
|---|---|---|
| D-21 | The brief's "Manager / Salaried Staff" is **one** role (Phase 0 had separate Manager and Staff; merged by migration). | Matches the 10-role list. Splitting again is a config + migration change. |
| D-22 | Phase 0 `admin` became **Super Admin / Owner**; **Administrator** is a new, lower role that cannot change sensitive grants, settings, other Administrators or the Owner. | "Only explicitly authorized administrators may ... change sensitive permissions." Adjust defaults in `config/permissions.php` or in the role editor. |
| D-23 | The Owner role can only be created from the console; Administrators can only be created by the Owner. | Prevents privilege minting via the UI. |
| D-24 | Public registration creates **clients only**, is **off by default**, and requires working email. | Who may self-register is a business decision (D-18). |
| D-25 | Staff accounts are invited by email (they set their own password); admins never choose or see passwords. If email is not configured, use `user:set-password`. | Security; needs SMTP (D-03). |
| D-26 | Finance Staff default = view finance, view all invoices/payments/payouts/commissions, draft/edit invoices, **record** payments; **no** approvals, issuing/voiding, or project access. | Edit in the role editor (sensitive items need the Owner). |
| D-27 | Roles that require a phone number: Client, Freelancer, Video Editor, Website Developer, Referral Partner. | `phone_required` in config. |
| D-28 | Step-up: re-entering your own password is required for role changes, permission changes, deactivation/reactivation and archiving. | Server-enforced. |

## Decisions taken in Phase 2 (please confirm or change)

| ID | Decision I made | Why / how to change |
|---|---|---|
| D-29 | CRM stage **names/order** are configurable in the database; **Won/Lost meaning is fixed by `kind`**. Lost needs a reason; nothing sets Won automatically. | `/admin/crm-stages`. |
| D-30 | Managers see enquiries/clients **assigned to them or linked to their projects**, not the whole pipeline; Administrators/Owner see all. Managers can work enquiries (notes, stages, mark Won) but only Administrators convert/archive. | `enquiries.view_all` / `convert` in the role editor. |
| D-31 | Budget ranges (under ₹25k … above ₹5L) and service list (website, video, domain/hosting, other) are **form labels only**, not prices. | `config/workflow.php`. Please supply the real ranges/services. |
| D-32 | A project gets its code `VIS-YYYY-NNNN`; legacy Phase 1 projects got `VIS-YYYY-Lnnnn`. Project status machine and "awaiting payment → ready to start" as a **manual, audited override** until payment records exist (Phase 5). | `config/workflow.php`. |
| D-33 | **No priority surcharge rates are invented.** All four priorities ship as "no surcharge"; an authorized Administrator sets rates (percentage of base or fixed ₹) per priority, optionally per service, with versions + audit. | `/admin/pricing`. Related to D-08. |
| D-34 | Workers do not see budget, pricing or **internal** project notes by default; managers do. Workers see all tasks of the projects they are assigned to, and may change only their own. | Role editor. |
| D-35 | The **client** approves price changes in the portal; staff cannot approve on their behalf, and a priority change that alters the priority charge never takes effect before approval. Reductions also need approval. | Needs your policy for verbal/e-mail approvals. |
| D-36 | Clients no longer hold `tasks.view`: tasks are internal working items. Clients see client-visible milestones, notes and the timeline. | Migration `0006` removes the grant from the Client role. |
| D-37 | A reviewer cannot approve their own task; tasks default to "needs review". Reasons are mandatory for Blocked/Changes Requested. | `config/workflow.php` (`task_transitions`). |
| D-38 | Public forms use CSRF + honeypot + per-IP rate limit + explicit consent; no CAPTCHA. If spam becomes a problem add one (needs a provider choice). | Open. |

## Decisions taken in Phase 3 (please confirm or change)

| ID | Decision I made | Why / how to change |
|---|---|---|
| D-39 | **No revision allowance is assumed.** Revision policies ship empty; staff classify manually with a reason. A numeric allowance, when entered, is enforced only as "contradicting it needs an administrator override". | The brief forbids inventing a universal allowance. `/admin/revision-policies`. |
| D-40 | The uploader **cannot approve their own upload internally** (QA needs a second person). Switch with `DELIVERABLE_ALLOW_SELF_APPROVAL=true` for very small teams. | Separation of duties. |
| D-41 | Workers may upload drafts and see drafts on their projects, but **cannot** review, publish, withdraw or archive. Managers review/archive/classify; administrators publish too (managers also hold publish from Phase 0 defaults). | Role editor. |
| D-42 | Clients see **only published** versions; an earlier published version stays visible/downloadable as history; withdrawn versions disappear. "Awaiting internal review" is **not** shown to clients (it would reveal unpublished work). | Brief item 6 vs item 3 conflict resolved in favour of not leaking drafts. |
| D-43 | A client approval is per exact version, **final** once given; after a rejection they may still approve later. A client can still file a revision request on an approved version. | Say if approved versions should be locked against revision requests. |
| D-44 | **Change requests do not modify `price_revisions` or the approved baseline.** An approved change request is consent only; Phase 4 turns it into a quotation revision/invoice. | Keeps the original approved price untouched and avoids inventing invoicing rules. |
| D-45 | Chargeable proposals can only be sent by classifiers (`revisions.classify`); only the client decides; declining returns the revision to Potentially Chargeable; reductions/credits are not modelled. | `config/workflow.php`. |
| D-46 | Files are **download-only** (`attachment`); no inline preview or streaming. 50 MB default limit (configurable), zip allowed as "source files" and never extracted. | Security first; add previews later behind a sandbox/CDN decision. |
| D-47 | Notifications are in-app only; the dedupe key makes retries safe. Email delivery needs D-03 (SMTP). | |

## Decisions taken in Phase 5 (please confirm or change)

| ID | Decision I made | Why / how to change |
|---|---|---|
| D-48 | **The warranty start date is never inferred.** It is a recorded date plus a recorded basis; project status, approvals and publications are shown to administrators as hints only. No record on a website project = Pending Confirmation. | The brief forbids guessing the start date. |
| D-49 | **End date = same calendar day N months later, clamped to month end, and that day is covered.** `WARRANTY_END_DAY_BEFORE=true` flips it to "the day before". | Contracts differ; confirm the wording of your agreement. |
| D-50 | Warranty status is **derived on every read** from the recorded facts and today's date in the app time zone: no cron, nothing to go stale. | Expiry *reminders* need the Phase 7 scheduler. |
| D-51 | `warranty.manage` and `warranty.override` are **Administrator-only and sensitive**; Managers/Support lost the Phase 0 default `warranty.manage` (migration 0009). Managers can view warranties and decide per-issue eligibility. | Role editor. |
| D-52 | **Covering an issue the warranty did not cover, and changing an earlier decision, are overrides** (administrator, flagged, audited). Not covered / potentially chargeable are ordinary reviewer decisions. | The brief: only authorised admins override sensitive eligibility decisions. |
| D-53 | One `tickets` table with `kind` (bug or support) and two workflows; client-visible messages and internal notes are separate tables; staff names are replaced by "Vertex support team" for clients. | Keeps internal data physically separate from client queries. |
| D-54 | **Workers** see only technical categories (bug report, technical support, project query, domain/hosting) on projects they are assigned to; they cannot reply to clients or see cost proposals. | `config/workflow.php -> ticket_technical_categories`. |
| D-55 | A client may reopen a closed ticket within **30 days** (`CLIENT_REOPEN_DAYS`); later they open a new ticket. Staff may always reopen. | Prevents resurrecting old threads while never blocking staff. |
| D-56 | **No SLA / response-time promise.** `support.sla` ships empty; "overdue" exists only if a first-response target is configured. | The brief: no SLA promises unless configured. |
| D-57 | A *potentially chargeable* bug **cannot start work** until a cost proposal is approved by the client. The proposal reuses `change_requests`; there is still no quotation/invoice integration (consent record only). | Keeps the client-approval rule without inventing billing. |
| D-58 | Nothing closes automatically: replies and uploads never change a status except returning "Need more information"/"Awaiting client response" to the team. | The brief. |
| D-59 | Assignment is allowed to anyone who can work tickets (`tickets.reply/note/bugs.fix/update_status`) and either can already open the ticket or holds `tickets.view_assigned` (the assignment grants access). Clients, partners and workers on other projects cannot be assigned. | `TicketAccess::canBeAssigned`. |

## Decisions taken in Phase 6 (please confirm or change)

| # | Decision | Why |
|---|---|---|
| D-60 | **Commission formula mode defaults to `cumulative`** (milestone 1 = 10% of eligible money at the advance; milestone 2 = 15% of all eligible money minus milestone 1, so the total is 15%). `per_milestone` (10% of advance-stage payments + 5% of later payments) is one setting away. **Needs your confirmation of which one the partner agreement means.** | "10% + 5%" is ambiguous; both are implemented, versioned and tested. |
| D-61 | Commission base = verified payments, net of refunds, excluding tax; contract value includes approved change requests; full payment tolerance 0; cancelled project: milestone 1 stays, milestone 2 never. All configurable and versioned. | Conservative defaults; nothing is ever paid on unpaid or refunded money. |
| D-62 | `client_payments` is the single source of verified payments (recorded by one person, verified by another). It is the integration point for the future invoice/payment module and gateway. | No payment module exists; a commission must never rest on an unverified claim. |
| D-63 | Attribution is a separate, reasoned decision by `referrals.decide`; one credited referral per contact/project; locked once any commission entry is eligible; no UI override. | Prevents commission disputes being rewritten after money moved. |
| D-64 | Separation of duties is ON by default: the verifier/recorder of a payment cannot approve the commission; the approver of a payout cannot record its payment. Switch with the rules screen or `COMMISSION_SEPARATION_OF_DUTIES`. | Fraud control with two or more people; a one-person business can turn it off and the audit log still records everything. |
| D-65 | Payout details are stored AES-256-GCM encrypted with a key derived from `APP_KEY`; masked hint only; reveal is limited to the payer and audited. Losing/changing `APP_KEY` loses them. | No plaintext bank details in the database, backups or logs. |
| D-66 | The old Phase 2 form that let a partner create a CRM enquiry directly (`/partner/referrals/create`) is retired (redirects to `/partner/submit`); old enquiries stay readable. | It bypassed duplicate detection and attribution. |
| D-67 | Approving a partner application never activates an account: new applicants get a pending account + invitation; signed-in applicants get the role on their own account; never linked by email match. | Secure activation, no account takeover via a guessed email. |
| D-68 | **Finance Staff holds `payouts.record`** (records the manual transfer) but not `payouts.approve`, `payments.reconcile` or any commission approval. Earlier phases had reserved `payouts.record` for Administrator/Owner; `PermissionMatrixTest` was updated deliberately. | The Phase 6 brief: Admin/Finance approve and pay; separation of duties comes from two different permissions. |

## Decisions taken in Phase 7 (please confirm or change)

| # | Decision | Why |
|---|---|---|
| D-69 | A service becomes **Renewed only through the provider confirmation** (reference + date + the provider's new expiry), by `renewals.confirm_provider` with the password. Payment, approval and invoicing never renew anything. | The brief: payment must never imply provider renewal. |
| D-70 | Renewal payments live in their own table (`renewal_payments`), **not** in `client_payments`: they are not project payments and must never trigger referral commission. | Keeps the commission engine's input pure. |
| D-71 | Quotation/invoice numbers on a renewal are **references** typed by staff; the amount must equal the approved price; to change a price an approver voids the invoice and the renewal returns to Admin review. | There is no quotation/invoice module; an invoice must never silently differ from what was approved. |
| D-72 | Lead times: Renewal Approaching at 30 days, Admin review due at 10 days (`RENEWAL_APPROACH_DAYS`, `RENEWAL_REVIEW_DAYS`). Reminders at 30/10/3/1/0 days, only the smallest unsent offset fires. Clients are reminded in the portal; client **email is opt-in** (`RENEWAL_CLIENT_EMAIL`). | Predictable, no bursts after a missed cron day, no email to clients without a conscious switch. |
| D-73 | Paid or in-progress services past their expiry are flagged **overdue**, not marked Expired. | They are mid-workflow; Expired would be misleading and would stop the workflow. |
| D-74 | Backups are a pure-PHP zip (no mysqldump/tar), exclude `.env`, are verified at creation, and restore **only into an empty database**. Encryption and off-site copy are the operator's job. | Works on shared hosting; a restore can never overwrite live data by accident; secrets are kept apart from the data. |
| D-75 | `backups.manage` is reserved: there is **no web UI or download for backups**. | A downloadable full-database file in the web app would be the highest-value attack target. |
| D-76 | Not built and stated as such on the System status page: quotations, invoices (GST-compliant numbering needs your legal details), online payment gateway and webhooks, WhatsApp, a CMS, chat, calendar-slot appointment booking, worker/freelancer payouts, financial reports, two-factor authentication. | Each needs business input, credentials or a legal decision. |

## Decisions taken in Phase 8 (please confirm or change)

| # | Decision | Why |
|---|---|---|
| D-77 | **No tax anywhere**: no tax column in quotations/invoices; every document prints the business's tax statement ("Tax is not applicable."), frozen into the invoice at issue. | You stated GST is not applicable. |
| D-78 | **Google Pay by UPI is the working payment path; Google Pay API (cards) stores its fields but is inactive** until a processor integration exists. The admin screen, System status and docs state this. | Google Pay is not a stand-alone gateway; a processor must charge the card, and I will not guess which one. |
| D-79 | UPI payments are **confirmed by staff against the bank statement**; a client's "I have paid" is only a claim that counts for nothing until verified, and the verifier enters the amount that really arrived. | UPI gives a plain UPI ID no callback; a client's word must never mark an invoice paid. |
| D-80 | Invoice payments are rows of the existing `client_payments` (no second table); the commission engine is unchanged. | "Do not duplicate financial records"; one source of verified payments. |
| D-81 | Invoice numbers are assigned at issue, sequential, never reused; an issued invoice is never edited (void and reissue). | Audit and accounting integrity. |
| D-82 | Gateway secrets are sealed with `SecretBox` (key derived from `APP_KEY`), written once, never shown again, never audited in clear; edits need `billing.configure` and the password. | Secrets must not live in the page, the log or the config text. |
| D-83 | Finance Staff may draft invoices and record payments but not issue, void, verify or reject; Administrator/Owner issue and verify; the verifier of a payment is never its recorder. | Separation of duties. |
| D-84 | The upload package contains only .php, .js, .css and .sql files: images are embedded in `assets/img.php`; `.env`, `.htaccess` and `storage/` are written by the key-gated web installer (it deletes itself and never touches a non-empty foreign database); the database ships as one generated SQL file tested equal to the migrated schema. | The hosting migrator rejects other file types. |
| D-85 | Two-factor login is TOTP (RFC 6238) with one-time recovery codes; secrets are sealed with `SecretBox`; roles at or above `TWO_FACTOR_REQUIRED_LEVEL` (default 0 = off) must enrol before reaching the app; a lost device is reset by a higher-ranked admin (audited). | Optional-but-enforceable 2FA without any third-party service. No QR code is drawn (manual key entry) to avoid shipping an unvetted QR library. |
| D-86 | Custom roles are ordinary `roles` rows (`is_system = 0`) edited with the same hierarchy and sensitive-grant rules as system roles; permissions are never wildcard. | Requirement 17 without weakening the permission model. |
| D-87 | "Delete" for eligible records is archive + recycle bin with a reason and password to restore; nothing is physically deleted. | Recoverability and audit. |
| D-88 | Exports and global search apply the same policies as the screens; CSV import validates every row and applies all-or-nothing. | No side door around permissions. |
| D-89 | Coupons are redeemed when an invoice is issued and released when it is voided; invoices keep a snapshot of the discount. Credit notes are separate numbered documents that reduce what is payable; invoices are never edited. | Accounting integrity. |
| D-90 | PDFs are generated by an in-house minimal writer (no library), text only, Helvetica; charts are server-rendered SVG with CSS classes (no inline script/style, CSP stays strict). | Hosting restricts uploads to php/js/css/sql and CSP forbids inline code. |
| D-91 | Agreements are signed in-platform by typed name + password re-entry, recorded with IP/time/document hash. This is **not** a qualified e-signature and no third-party e-sign service is integrated; physical signed scans can be uploaded instead. | Honest scope; a provider can be added later. |
| D-92 | Website content is a list of typed blocks with no raw-HTML escape hatch; text is escaped before light formatting. | Even a trusted editor cannot inject script. |
| D-93 | Settings overlay: a fixed list of settings may be saved in the database and override `.env`; secrets are write-only and sealed; only the Owner may save. | Module 35 without exposing secrets or letting the database override security-critical config. |
| D-94 | AI assistance only drafts; it sees only records the user may open; every call is logged without content; limits per user and per month. | Safe AI use. |
| D-95 | PhonePe payments are recorded only after a server-side order-status check says COMPLETED for the exact amount; the webhook is authenticated by the dashboard-defined credentials and only triggers that check. | Never trust the browser or an unverified callback with money. |
| D-96 | Public copy uses `{company}`/`{abbr}` tokens resolved from the configured company name; the brief's "Vertex/VIS" wording is not hard-coded. | The brand changed to Krazy Integrated Solutions; one setting renames the whole site. |
| D-97 | Website lists (services, team, partners, portfolio, openings, FAQs, policies) are definition-driven (`SiteEntities`) with draft/published/archived states; page wording lives in `site_sections` overriding shipped defaults. | One audited editing path instead of seven hand-written CRUD modules; the site works before anything is saved. |
| D-98 | Nothing is invented: Team, Partners, Portfolio and Openings ship empty and public sections hide or say so; prices, address and contact details show only when entered. | Honesty rule from the brief. |
| D-99 | Policies ship as DRAFT, versioned, and cannot be published while an `[ADMIN TO COMPLETE]` note remains. | Legal text must be completed and reviewed by the business. |
| D-100 | Contact and Project Requirements reuse CRM enquiries (extra answers in `enquiry_details`, attachments in `enquiry_files`); job applications get their own table. | No duplicate lead pipeline; careers data is not a sales lead. |
| D-101 | Visitor uploads (enquiry attachments, résumés) go to the private store and are served only to authorised staff as forced downloads. | Private uploads; no public file URLs. |
| D-102 | An already-installed site is upgraded by a key-gated `upgrade.php` that runs the forward-only migrations and adds new permissions, then deletes itself. | Hostinger customers without SSH can still apply database changes. |
| D-103 | The Owner (super admin) may approve items they recorded or uploaded themselves (`Gate::canSelfApprove`); every other role stays bound by separation of duties. | Requested by the business owner; the action is still audited. |
| D-104 | Our Clients is its own editable list (`site_clients`, grouped by country) beside Our Partners; it ships empty and states only that the business works with clients from various countries. The founder's profile is seeded because the business supplied it. | The business asked for both pages; no client names or logos are invented. |
| D-105 | The Hiring page has a three-state switch (open / closed / hidden) kept outside the editable wording; applications reuse the careers pipeline and are refused unless the page is open. Salary is never stated beyond "decided after the meeting". | The business must be able to stop hiring instantly without losing its wording or past applications. |
| D-106 | PhonePe payments are requested only by the team (`payments.request`) for an amount they type (≤ balance); one QR is drawn per request from PhonePe's answer, the client can only view it. The old client self-start route was removed. | The business asked that staff initiate payments and that the QR carry exactly the entered amount. |
| D-107 | The QR is drawn server-side by an in-house encoder (SVG); a UPI link's `am=` must equal the typed amount or the request is discarded; a payment is still recorded only after PhonePe's status API says COMPLETED for that exact amount. | No third-party QR service sees payment data; two independent amount checks. |
| D-108 | The database backup is a streamed `.sql` produced from one consistent snapshot, Owner only with password re-entry, deleted from the server right after sending. | Standard restore through phpMyAdmin; minimal exposure of a file that holds everything. |
| D-109 | Every field of the job application is required and validated on the server; the form's labels and drop-down lists are editable, the requirement is not. | The business asked that nothing be optional. |
| D-110 | Colour theme is a per-browser choice (light/dark, default from the device) applied by an external script before paint. | Works with the strict CSP; no account setting needed. |

## External integrations and credentials

| Integration | Needed from you | Phase |
|---|---|---|
| Hostinger/cPanel | Plan, PHP version, DB credentials, SSH/Git deploy availability, cron limits | 1 |
| Transactional email / SMTP | Sender domain with SPF/DKIM/DMARC, SMTP credentials | 1, 6 |
| Payment gateway | Account, test + live keys, webhook secret, allowed webhook IP/URL | 5 |
| PDF generation | Composer package (e.g. Dompdf); fonts including ₹ glyph | 4 |
| Domain/hosting providers | Provider names; API keys if APIs exist | 8 |
| Calendar (optional) | Google/Outlook sync for appointments if wanted | 7 |
| Off-server backup target | Storage location (S3-compatible/Drive) and encryption passphrase custody | 9 |
| Legal text | Lawyer-reviewed policies | 8 |
| Monitoring (optional) | Uptime check on `/health`, error alerting | 9 |
