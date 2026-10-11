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
