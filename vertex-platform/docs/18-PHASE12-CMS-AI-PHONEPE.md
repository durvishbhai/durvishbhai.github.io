# 18 · Phase 12 — website CMS, Integration Center, AI assistance, Razorpay payments

What this phase adds, how it is protected, and exactly what has **not** been proven yet.

## 1. Integration Center (module 35) — `/admin/settings`

* One screen, grouped: General, Website and company facts, Email, Notifications, Storage, AI, Razorpay, Feature switches.
* A **fixed, reviewed list** of settings (`Settings::definitions()`); anything else in the table is ignored, so a database row can never override `database.*`, `app.key` and the like.
* A saved value **replaces** the `.env` value; "Use the default again" deletes it. The screen shows where each value comes from.
* Secrets (SMTP password, AI key, Razorpay key secret and webhook secret) are **write-only**: sealed with `SecretBox` (AES-256-GCM, key derived from `APP_KEY`), never rendered back, never in the audit log (it records key names only). Leave the box empty to keep the saved secret. If `APP_KEY` changes, sealed secrets can no longer be opened and behave as unset (re-enter them).
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

## 4. Online payments through Razorpay (module 21)

**Razorpay and Google Pay were removed** (the earlier Razorpay integration and the Google Pay settings page are gone; migrations are forward-only so old tables remain unused). Online payments now go through **Razorpay** only. Clients can still tell us they paid by bank transfer or UPI with a UTR number; staff verify those against the bank as before.

**Staff-initiated, exact-amount QR.** A member of the KIS team with `payments.request` (Finance Staff, Administrator, Owner) opens an invoice → *Request payment* → **types the amount** (pre-filled with what is still due, editable from ₹1.00 up to the balance, never above it) → the server asks Razorpay for a collection of exactly that amount → one QR is drawn on the server (SVG, no external service) from Razorpay's answer. Nothing is generated before an amount is entered. The client sees the same QR on `/my/pay/{id}` (and is notified) but cannot create or change anything. The page counts down and polls `/payment-requests/{id}/status`, which asks Razorpay (never trusts the browser) and shows "Payment received" when Razorpay reports it. A request can be withdrawn (Razorpay is asked to close the QR / cancel the link; a payment already made is still recorded) and expires after 30 minutes.

How the QR is made (*Integrations & settings → Razorpay → How the payment QR is made*, or `RAZORPAY_QR_MODE`):
* `auto` (default): `POST /v1/payments/qr_codes` with `type=upi_qr`, `usage=single_use`, `fixed_amount=true`, `payment_amount=<typed paise>`, `close_by=now+30 min`. If Razorpay returns the UPI string for that QR (`image_content`) it is drawn as our own QR (its `am=` must equal the typed amount). If Razorpay returns no UPI string, the QR is **closed** (so there is never a second way to pay) and a Razorpay **Payment Link** is used instead.
* `link`: Payment Link only: `POST /v1/payment_links` with `amount=<typed paise>`, `accept_partial=false`, `reference_id`, `expire_by`. We draw a QR that opens Razorpay's hosted payment page for exactly that amount (UPI, cards, net banking, wallets).
* Razorpay's answer must repeat the amount (and, for a QR, fixed amount and single use) or the request is discarded and nothing is stored.

Guarantees (each is covered by tests against a simulated Razorpay):
1. A payment is recorded **only** after the server asks Razorpay and finds a **captured** payment of exactly the order's amount (QR: `GET /v1/payments/qr_codes/{id}/payments`; link: `GET /v1/payment_links/{id}`). An authorized-but-not-captured payment stays pending.
2. The webhook (`POST /webhooks/razorpay`) must carry `X-Razorpay-Signature` = HMAC-SHA256 of the raw body with the webhook secret saved in *Integrations & settings*, compared in constant time. No secret saved → every call is refused (401). The body is never believed: after the signature check it only names the order to ask Razorpay about. Events are logged once per `X-Razorpay-Event-Id`.
3. Idempotent: webhook + page poll + scheduler can all fire for one order; one payment row exists (unique gateway reference).
4. An amount mismatch records nothing, writes a security log line and an audit entry, and leaves the order for a person.
5. Payments land in `client_payments` (source `gateway`, already `verified`) so invoice status, commissions, receipts and reports follow.
6. Orders never returned from are re-checked by the scheduled task (older than 3 minutes, newer than 2 days).
7. A client cannot start a payment; another client's request is a 404.

**What is NOT verified:** Razorpay's documentation could not be opened from the build environment, so this was written from knowledge of the public API (Basic auth with key id/secret, QR Codes, Payment Links, HMAC-signed webhooks) and exercised only against a simulation. In particular, whether your account returns `image_content` for QR codes is unknown (hence the payment-link fallback), and QR Codes may need to be enabled on your Razorpay account. Use **test keys** first.

### Test-mode checklist (do this before going live)

1. In the Razorpay Dashboard (Test Mode) create API keys: *Account & Settings → API Keys*. Note the **Key ID** (`rzp_test_…`) and **Key Secret**.
2. *Integrations & settings → Razorpay*: paste the Key ID and Key Secret, switch on, save. The site must be served over HTTPS (the status badge says why it is not ready).
3. In the Razorpay Dashboard add a webhook: URL `https://YOUR-SITE/webhooks/razorpay`, a secret you choose, events `payment.captured`, `qr_code.credited`, `payment_link.paid` (and `qr_code.closed`). Paste the same secret into *Integrations & settings → Razorpay → Webhook secret*.
4. Issue a ₹10 invoice to a test client; as the team press *Request payment* on it, accept ₹10.00, and pay the QR with Razorpay's test method (a test payment link opens Razorpay's test checkout; use its test UPI / card details).
5. Confirm: the page flips to **Payment received**, the invoice shows **Paid**, one `Client payments` row (source *gateway*, verified), the client has a notification and `storage/logs` shows no errors.
6. Try a part payment (₹4 of ₹10), a withdrawn request, and an expired request (wait 30 minutes or lower `order_ttl_minutes` in `config/razorpay.php` temporarily).
7. If the webhook never arrives, check the Dashboard's webhook delivery log; the page poll and scheduled check still record the payment.
8. Only then switch to **live** keys (`rzp_live_…`) and make one real ₹1 payment.

## 5. New permissions and limits

* `ai.use` (new). Existing `cms.view/edit/publish`, `settings.view/manage` now have working screens.
* Rate limits (`config/auth.php`): `ai_user` 30/h, `payment_request_user` 30/h, `cms_upload_user` 60/h.
* Scheduled task additions: publish due pages (`cms_published`), re-check pending Razorpay orders (`gateway_reconciled`).

## 6. Database

Migration `0017_cms_integrations.sql`: `app_settings`, `cms_pages`, `cms_page_versions`, `cms_blocks`, `cms_menus`, `cms_redirects`, `cms_media`, `ai_requests`, `gateway_orders`, `gateway_webhook_events`.
