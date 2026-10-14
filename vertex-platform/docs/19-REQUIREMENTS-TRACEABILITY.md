# 19 · Requirements traceability (REQ-20261014-78)

Every module and role in the requirements document, where it is built, and an honest status. Labels: **IT** implemented and covered by automated tests · **INT** implemented, tests exist, but depends on something not exercised for real (live service, real host, browser pass) · **PI** partly implemented (the gap is stated).

"Built with PHP, Laravel and MySQL" in the document: the platform is **plain PHP 8.2 + MySQL/MariaDB** (no Laravel), as requested in the project brief and required by the Hostinger package rules (D-01, D-84).

| # | Module | Status | Where / notes |
|---|---|---|---|
| 1 | User authentication | IT | Login throttling, sessions, invites, reset, verification; optional TOTP 2FA with recovery codes (Phase 9). |
| 2 | Dashboard analytics | IT | Role dashboards with counters and SVG charts. |
| 3 | Data tables | IT | Filters, sorting and pagination on the main lists (not every list has every control). |
| 4 | CRUD operations | IT | Per module; "delete" = archive + recycle bin (D-87). |
| 5 | Export data | IT | CSV export (permission- and policy-scoped), PDF for documents. |
| 6 | File upload | IT | Private store outside the web root; type/size checks; deliverables, ticket and revision attachments, agreements, CMS pictures. |
| 7 | Email notifications | INT | Outbox + SMTP (`Mailer`); delivery verified only against a local fake SMTP server. |
| 8 | Reports generation | IT | Revenue, receivables, project status, conversion, workload, overdue, commissions, coupons, reconciliation, renewals, support, campaigns (`/reports`). |
| 9 | Charts / graphs | IT | Server-rendered SVG (bar/line), CSP-safe. |
| 10 | Mobile responsive | INT | Responsive CSS throughout; checked in Chromium for earlier phases, **not re-checked screen by screen for Phases 9–12**. |
| 11 | Print function | IT | Print views and PDFs for quotations, invoices, receipts, credit notes. |
| 12 | Activity logs | IT | Hash-chained audit log with verification. |
| 13 | Public website & CMS | IT (code) | Block-based pages, drafts, preview, version restore, media library, menus, SEO (doc 18). Design/content must still be supplied; the block set is a pragmatic subset, not a free-form page builder. |
| 14 | Central admin dashboard | IT | Admin menu for users, clients, projects, finance, payments, agreements, referrals, forms, support, website, integrations, permissions, notifications, reports, system status. |
| 15 | CRM & client management | IT | Enquiries, stages, follow-ups, quotations, clients, documents (attachments), conversion. |
| 16 | Project & task management | IT | Projects, milestones, tasks with dependencies, priorities, checklists, templates (Phase 11), workload (team page), deadlines, board. |
| 17 | Custom roles & permissions | IT | Custom roles in the UI with granular permissions; record-level scoping by policies; audit of grants (Phase 9). |
| 18 | Freelancer workspace | IT | Developer/editor/designer roles restricted to assigned projects and files. |
| 19 | Delivery approval workflow | IT | Upload → QA → admin approval → client publication → revisions with versions and internal comments. |
| 20 | Client portal & downloads | IT | Dashboards, approved deliverables only, revisions, invoices, receipts, agreements, payment history, tickets. |
| 21 | Payment gateway & finance | **INT** | Invoices, manual and UPI-claim payments (staff-verified), outstanding balances, reconciliation report: IT. **PhonePe**: implemented with verified-status and signed-webhook handling (doc 18) but **tested only against a simulation; never run against PhonePe sandbox/production.** Advance/milestone payment requests are modelled as invoices. |
| 22 | Hosting & domain renewals | IT / PI | Schedules, prices, reminders, client payment activation, history: IT. Provider API integration: **not built** (manual confirmation by design, D-77). |
| 23 | Dynamic form builder | IT | Fields, validation, conditional logic, templates, public links, access control, submissions, export (Phase 11). |
| 24 | Referral partner & commissions | IT | Applications, approval, partner dashboard, editable rules with preserved history, splits, payouts. |
| 25 | Agreements & document vault | IT / PI | Templates, physical scan upload, in-platform signing by both parties (typed name + password + hash), status, versions, secure access: IT. **Third-party e-sign integration: not built** (D-91). |
| 26 | Coupon & campaign manager | IT | Percent/fixed, dates, limits, eligible services, minimum spend, campaigns, discount report. |
| 27 | Support ticket system | IT | Tickets, assignment, priorities, private notes, attachments, SLA timers, escalation, reopen. |
| 28 | Notifications & automation | IT / INT | In-app: IT. Workflow rules, deadline alerts, escalation (scheduled task): IT. Email/WhatsApp: email INT, WhatsApp/SMS not built. |
| 29 | Reports & analytics | IT | See 8. |
| 30 | Approval center & audit logs | IT | Pending approvals across modules; audit with old/new values, reason; recycle-bin restore. |
| 31 | AI assistance | **INT** | Summarise, draft tasks/documents, permitted search, suggestions (doc 18). **Tested against a simulated provider only.** |
| 32 | Security, backups & monitoring | INT | Auth, 2FA, server-side permissions, private storage, audit protection, tested backup/restore CLI, error log, `doctor`, route sweep and permission tests: IT. **Scheduled backups depend on the host cron; off-site copy and error-alerting are not automated** (doc 15). |
| 33 | Scope changes & project quality | IT | Scope requests with cost/deadline impact and approval, checklists, timestamp-based feedback, revision tracking. "Delivery comparison" is the version history of a deliverable, not a visual diff. |
| 34 | CMS SEO & publishing | IT | Clean URLs, metadata, sitemap, redirects, scheduled publishing, reusable blocks, version restore. |
| 35 | System settings & integration center | IT | Doc 18 §1. Storage settings are limited to the upload size (storage path stays in `.env`). |
| 36 | Invoice & document numbering | IT | Unique quotation/invoice/receipt/credit-note references, controlled corrections (void/credit note), PDFs, payment-linked. |
| 37 | Search, recovery & data management | IT | Global search (permission-scoped), recycle bin, CSV import/export with validation. |

## Roles (12)

| Requirement role | Platform role |
|---|---|
| Super Admin / Owner | `super_admin` (level 100) |
| Administrator | `administrator` (90) |
| Project Manager; Official Staff / Reviewer | `manager` (60) — department scoping is by project assignment, not by a department table |
| Finance Manager | `finance_staff` (50) |
| Support Agent | `support_staff` (50) |
| External Website Developer | `website_developer` (30) |
| External App Developer | **no separate role**: use `website_developer` or create a custom role (e.g. "App Developer") in *Roles* |
| Video Editor | `video_editor` (30) |
| Graphic Designer | **no separate role**: use `freelancer` or a custom role |
| Client | `client` (10) |
| Referral Partner | `referral_partner` (20) |

The requirement table lists "Graphic Designer: Full Access" — this is treated as a documentation inconsistency (a contractor with full access would break the permission model); designers get assigned-records access like the other external roles.

## Not built (stated plainly)

* Third-party e-signature provider; domain/hosting provider APIs; WhatsApp/SMS notifications; calendar sync.
* QR code for 2FA enrolment (the key is shown for manual entry).
* Automated off-site backups and external error monitoring (documented procedure only).
* Free-form page builder; members-only website pages.
* Laravel (the brief and hosting rules chose plain PHP).
