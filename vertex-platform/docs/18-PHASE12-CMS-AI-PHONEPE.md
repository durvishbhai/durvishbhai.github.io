# 18 · Phase 12 — website CMS, Integration Center, AI assistance, PhonePe

What this phase adds, how it is protected, and exactly what has **not** been proven yet.

## 1. Integration Center (module 35) — `/admin/settings`

* One screen, grouped: General, Website and company facts, Email, Notifications, Storage, AI, PhonePe, Feature switches.
* A **fixed, reviewed list** of settings (`Settings::definitions()`); anything else in the table is ignored, so a database row can never override `database.*`, `app.key` and the like.
* A saved value **replaces** the `.env` value; "Use the default again" deletes it. The screen shows where each value comes from.
* Secrets (SMTP password, AI key, PhonePe client secret and webhook password) are **write-only**: sealed with `SecretBox` (AES-256-GCM, key derived from `APP_KEY`), never rendered back, never in the audit log (it records key names only). Leave the box empty to keep the saved secret. If `APP_KEY` changes, sealed secrets can no longer be opened and behave as unset (re-enter them).
* Viewing needs `settings.view` (Administrator + Owner); saving needs `settings.manage` (**Owner only**) and the actor's password.
* Bootstrap applies the values once per request (one small query; swallowed if the table is missing, so a half-installed site still starts).

## 2. Website CMS and SEO (modules 13, 34) — `/admin/cms`

| Capability | How |
|---|---|
| Pages | Title, address (`about`, `services/web-design`), status `draft → published / scheduled → archived`. The page with address `home` replaces the built-in front page once published. |
| Content | An ordered list of typed **blocks** (hero, text, features, services, portfolio, call to action, FAQ, picture, contact details, form link, reusable). Max 40 per page. |
| Safe by construction | There is **no raw HTML**. Text is escaped first and only `**bold**`, `*italic*`, `[text](address)`, `## headings` and `- lists` are turned into tags. Addresses must be `https://`, `http://`, `mailto:`, `tel:`, or a path (not `//…`, never `javascript:`/`data:`). Tested with hostile input, including rows altered directly in the database. |
| Versions | Every change that alters something creates a version with a note; the history lists who/when; **restore** writes the old content as a *new* version, nothing is lost. |
| Preview | `/admin/cms/{page}/preview` shows a draft exactly as visitors will see it, `noindex`, for staff only. |
| Publishing | `cms.edit` writes drafts; `cms.publish` publishes, schedules, unpublishes, archives and edits **live** pages. Scheduled pages go live at their time (a due page is served immediately; the scheduled task also marks it published). |
| SEO | Search title and description, canonical address, `noindex`, sharing picture (Open Graph), `/sitemap.xml` (published, indexable pages + the public forms), `/robots.txt` (blocks `/admin/`, `/my/`, sign-in and search pages). |
| Redirects | Changing the address of a live page leaves a 301 behind; manual 301/302 redirects (to a path or `https://`). Redirects cannot shadow a platform address (`/login`, `/admin/…`, …). |
| Reusable blocks | Edit once, shown on many pages; cannot nest; cannot be deleted while used. |
| Menus | Header and footer menus (max 12 links each); an empty menu falls back to the built-in links. |
| Media library | JPEG/PNG/WebP/GIF only, ≤ 5 MB, ≤ 6000 px, real image check, JPEG/PNG/WebP are **re-encoded** (drops metadata and anything appended), files containing `<?php`/`<script` refused, stored under random names in `public/uploads/cms/YYYY/` with a `.htaccess` that denies script execution; a picture in use cannot be deleted. |
| Company facts | Name, tagline, email, phone, WhatsApp, address, social links and footer text come from *Integrations & settings → Website* and appear in the footer and the contact block. |
| Reserved addresses | A page may not use an address the platform itself routes (checked against the live route table) and the catch-all page routes are registered **last**, so a database row can never override a real route (tested with a planted `login` page). |

**Honest limits.** The block set is a pragmatic subset (no drag-and-drop builder, no free layout); page *design* is the shipped stylesheet. The existing static site at the repository root is untouched. Page-level access control (members-only pages) is not built. Website pictures live in `public_html/uploads/cms/` and **are included in `backup:create`** under `uploads/website-media/` (copy them back to that folder after a restore).

## 3. AI assistance (module 31) — `/ai`

* Off until an Owner enables it **and** saves an API key. Needs the `ai.use` permission (Managers, Administrators, Owner by default; not clients, support, finance or workers).
* Providers: Anthropic Messages API, or any OpenAI-compatible chat-completions service (set the API address).
* Features: summarise an enquiry, draft tasks for a project (shown as a list; **nothing is created** until the user ticks tasks and presses Create, which goes through `TaskService::create` with all its permission and audit rules), draft an email / proposal / client update / agreement wording, "ask about my records" and "suggest next steps".
* **The model only receives what the person may already open**: search results come from `SearchService` with the user's own permissions, enquiries and projects go through the same policies as the screens; contact details are not sent.
* Output is a draft, shown as text, never saved or sent automatically.
* Every request is logged (user, feature, sizes, outcome — **not the text**), limited per user per hour (`ai_user`) and per month for the company (`ai.monthly_cap`). Provider errors are logged without the key and shown as a generic message.
* **Not verified against a live provider** — the tests use a simulated provider. Try it with a real key and a harmless enquiry before relying on it. It can produce wrong text; that is why it only drafts.

## 4. PhonePe payments (module 21) — Standard Checkout

Flow: client opens an invoice → *Pay online with PhonePe* → server creates a PhonePe order for what is **still due on the invoice** (the browser supplies no amount) → client pays on PhonePe → returns to `/my/invoices/{id}/phonepe-return` → PhonePe also calls `POST /webhooks/phonepe`.

Guarantees (each is covered by tests against a simulated PhonePe):

1. A payment is recorded **only** after the server has asked PhonePe's order-status API and received `COMPLETED` for exactly the amount of our order. The browser return and the webhook body are never believed; the webhook merely triggers that check.
2. The webhook must carry `Authorization: SHA256(username:password)` computed from the credentials saved in PhonePe's dashboard and in *Integrations & settings → PhonePe*; compared in constant time. No credentials saved → every call is refused (401).
3. Idempotent: browser return + webhook + scheduler can all fire for one order; only one payment row exists (`external_key` unique; the order row is locked). Identical webhook bodies are stored once.
4. An amount mismatch records nothing, writes a security log line and an audit entry, and leaves the order pending for a person.
5. Payments land in the same `client_payments` table (source `gateway`, already `verified`), so invoice status, commissions, receipts, reports and the approval centre keep working unchanged. The client gets an in-app notification; staff with `payments.reconcile` are told.
6. Orders that were never returned from are re-checked by the scheduled task (older than 3 minutes, newer than 2 days); orders still pending after 6 hours are marked expired.
7. Staff cannot "pay" as the client; another client's invoice is a 404.

**What is NOT verified:** this was written from PhonePe's published Standard Checkout documentation and exercised only against a simulation. No call has been made to PhonePe's sandbox or production. Field names, the webhook header format and the redirect behaviour must be confirmed with the **sandbox checklist** below before any real money moves.

### Sandbox checklist (do this before going live)

1. Get merchant credentials from the PhonePe Business dashboard (sandbox first): Client ID, Client Secret, Client Version, and set a webhook username/password there.
2. *Integrations & settings → PhonePe*: environment **Sandbox**, paste the credentials, switch on, save. The site must be served over HTTPS (`APP_URL=https://…`); the status badge must read **Ready**.
3. In PhonePe's dashboard set the webhook URL to `https://YOUR-SITE/webhooks/phonepe` with the same username/password.
4. Issue a ₹10 invoice to a test client; as that client press *Pay online with PhonePe*; complete the sandbox payment.
5. Confirm: the invoice shows **Paid**, one `Client payments` row (source *gateway*, verified), the client has a notification, `storage/logs/app-*.log` has no PhonePe errors, and *Admin → Audit* shows `client_payment.gateway_recorded`.
6. Repeat with a failed/cancelled payment (order shows failed, nothing recorded) and by closing the PhonePe tab right after paying (the webhook or the scheduler must still record it).
7. If the webhook never arrives: check the `Authorization` header reaches PHP (the installer adds the `.htaccess` rule `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`), and compare the header PhonePe sends with `sha256("username:password")`.
8. Only then switch the environment to **Production** with production credentials and make one real ₹1 payment.

## 5. New permissions and limits

* `ai.use` (new). Existing `cms.view/edit/publish`, `settings.view/manage` now have working screens.
* Rate limits (`config/auth.php`): `ai_user` 30/h, `phonepe_start_user` 10/h, `cms_upload_user` 60/h.
* Scheduled task additions: publish due pages (`cms_published`), re-check pending PhonePe orders (`gateway_reconciled`).

## 6. Database

Migration `0017_cms_integrations.sql`: `app_settings`, `cms_pages`, `cms_page_versions`, `cms_blocks`, `cms_menus`, `cms_redirects`, `cms_media`, `ai_requests`, `gateway_orders`, `gateway_webhook_events`.
