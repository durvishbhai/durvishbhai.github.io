# Compliance, privacy, security and accessibility: implementation report

Krazy Integrated Solutions (KIS) platform. **This is an engineering report, not legal advice.** Nothing here says the website "is compliant" with any law. The system provides the tools, the draft texts and the evidence trail; the Owner and an Indian lawyer decide what is correct for the business. Every item that needs that decision is listed in section H.

Test status (final full run) is recorded in section F at the end of this file.

---

## A. Requirement-by-requirement report

Status words: **Built** = implemented and covered by automated tests; **Built, needs your input** = works but waits for facts only you can give; **Built, needs lawyer** = texts exist as drafts and are flagged; **Process** = not something code can finish.

| # | Requirement | What was built | Status |
|---|---|---|---|
| 1 | Privacy Policy | Full draft (collection, purposes, legal basis wording, sharing, retention, rights, children, grievance), versioned, in Compliance & legal. Cannot be published while a required section is missing or a required fact is empty. | Built, needs your input and lawyer |
| 2 | Terms and Conditions | Draft that follows the KIS workflow (requirements form, quotation, 50% advance via Razorpay, delivery date chosen by the client, 14-day revision window, final agreement, IP transfer after full payment, three domain/hosting options, portfolio only with permission, 18+). | Built, needs your input and lawyer |
| 3 | Refund & Cancellation | Separate Refund and Cancellation policies. Half of the advance is refundable before work starts, none after, with the legal-rights caveat kept in the text. Each unusual clause is flagged for the lawyer (section H). `/refund-and-cancellation-policy` points to both. | Built, needs lawyer |
| 4 | Form consent | Every public form has three separate controls: privacy acknowledgement, 18+/authorised-representative confirmation, and (only while marketing email is switched on) an unticked optional marketing box. Nothing is pre-ticked. The wording, policy version and time are stored in `consent_records` for accepted submissions only (not for refused ones). | Built |
| 5 | Cookie consent | Banner (Accept all / Reject optional / Customise), settings dialog (native `<dialog>`), footer "Cookie Settings" link, per-category choice, versioned consent log, re-ask when the cookie list changes, withdrawal at any time. Optional scripts never load before consent. | Built |
| 6 | Third-party disclosure | Owner register of services (purpose, data, location, cookies, provider privacy link) feeding the public "Third-Party Services" page and the cookie list. It warns when the register and the real technical switches disagree. A service that is only a possibility is shown as "not in use". | Built, needs your input (review each entry) |
| 7 | Data minimisation | Forms audited (section B). No date of birth, ID document or payment card number is collected anywhere. | Built (audit documented) |
| 8 | Unsupported claims | Nothing invented was added. Services that are not currently offered are labelled (suspended/discontinued) or removed from request lists; only the 10 offered services can be requested. Portfolio and client logos cannot be published without a recorded permission. | Built, needs your reading of every page |
| 9 | Review authenticity | The platform has no star-rating or testimonial feature and the seed has none. Rule for the future: only a real review you can prove, with the reviewer's permission. | Process (nothing to build) |
| 10 | Data visibility | Public pages show only published content; the team and client areas are behind sign-in and permissions (the route-sweep test checks every route for every role). Public contact details come only from the compliance settings. | Built |
| 11 | Accessibility | Accessibility Statement (draft) + automated checks in the test suite (section G). | Built; a manual audit is still needed |
| 12 | Media accessibility | The site has no video or audio. The statement says captions/transcripts are required before any is added. | Process |
| 13 | Image alt text | Automated test: every `<img>` in every template has an `alt` attribute (decorative images are empty on purpose). Alt text for pictures you upload is up to you. | Built |
| 14 | Colour contrast | Measured with the real colour tokens in both themes. Failures found and fixed (section G). | Built |
| 15 | Keyboard navigation | Skip link first, cookie banner second, visible focus ring, native modal dialog (focus trap + Escape). Checked with a browser during the cookie work. | Built; manual screen-reader pass recommended |
| 16 | Business details | "Legal Business Information" and "Contact & Grievance" pages built from settings. **No fact was invented**: proprietor, representative, email, WhatsApp, registration numbers are empty until you enter them, and the legal pages cannot be published until the required ones exist. | Built, needs your input |
| 17 | Age / children | 18+ confirmation on every public form and sign-up; policy text for minors; no date of birth is collected. | Built, needs lawyer |
| 18 | Email unsubscribe | Marketing readiness only: nothing marketing is sent and no marketing box is shown while it is off. If you switch it on: only opted-in people, signed one-click and page unsubscribe links (`List-Unsubscribe`-style endpoint), unsubscribe honoured at once and not undone by old data. Transactional/security mail never has an unsubscribe link. | Built |
| 19 | Asset licensing | Asset & licence register that lists the shipped images/icons and states the font position (system fonts, nothing shipped). Every item starts "Licence not confirmed" and becomes Confirmed only when you record a source and licence/evidence. | Built, needs your input |
| 20 | Data deletion | Public request form, email verification, private status link, Owner review (verify / ask a question / approve / reject), execution that anonymises or deletes personal data and keeps records the law requires (financial records, with the reason shown), full event history, branded emails. Customer records are never deleted without a reviewed request. | Built, needs lawyer (retention years) |

Extra pieces required by the brief: version history, draft/schedule/publish/restore for every legal document; policy-acceptance with a computed account restriction until accepted and reminder/extension tools; an Owner checklist for the 20 items; Turnstile bot protection (off by default, fail-closed when on); integrations register; service availability manager.

## B. Files changed (summary)

104 files changed in total across the compliance work (66 added, 38 modified). Main groups:
* **Services**: `LegalDocuments`, `LegalDefaults`, `LegalLinks`, `HtmlSanitizer`, `ComplianceSettings`, `ComplianceDashboard`, `AssetRegister`, `PolicyAcceptance`, `PolicyMail`, `CookieConsent`, `CookieRegister`, `Integrations`, `ServiceAvailability`, `DataDeletion`, `PrivacyMail`, `ConsentRules`, `Marketing`, `Turnstile`.
* **Controllers**: `LegalController`, `PolicyAcceptanceController`, `CookieController`, `DataDeletionController`, `UnsubscribeController`, `Admin\ComplianceController`, `Admin\DeletionAdminController`. Existing form controllers were patched for the age confirmation, the consent record and the optional bot check.
* **Views / assets**: public legal pages, cookie banner and dialog, deletion pages, unsubscribe page, Owner pages (documents, settings, acceptance, integrations, services, deletion, checklist, assets); `legal-editor.js`, `consent.js`; CSS additions and the contrast fixes.
* **Migrations**: 0036–0042 (section C).
* **Tests**: see section F.
* **Untouched on purpose**: the root `index.html`, `CNAME`, root `README.md`, the client and team dashboards, and the appointment systems (apart from the added age confirmation on their public form).

## C. Database report

All migrations are forward-only and add tables/columns; nothing is dropped or rewritten.

| Migration | Adds |
|---|---|
| 0036 | `legal_documents`, `legal_document_versions`, `compliance_settings` |
| 0037 | `legal_acceptances`, `legal_notices`, `accept_by` on versions |
| 0038 | `cookie_consents` |
| 0039 | `compliance_integrations`; `availability`, `availability_note` on `site_services` |
| 0040 | `deletion_requests`, `deletion_request_events` |
| 0041 | `consent_records`, `email_preferences` |
| 0042 | `compliance_items`, `asset_register`; `permission_confirmed`, `permission_note` on `site_portfolio` and `site_clients` |

Existing portfolio/client rows get `permission_confirmed = 0`. Anything already published stays visible, but the checklist flags it ("N published item(s) have no recorded permission") until you tick the box on each one.
No secret is stored in any of these tables. Consent and unsubscribe records keep a hash of the visitor's IP, not the address.

## D. Owner dashboard

Administration → **Compliance & legal** (permission `compliance.manage`, Owner only, excluded from the Administrator role).
* **Compliance checklist** (`/admin/compliance/checklist`): 20 items; status set by you (Pending, Complete, Needs attention, Requires legal review, Not applicable), responsible person, next action, notes, last-reviewed date; beside each, the facts the system can check (e.g. "Privacy Policy is not published yet", "3 assets without a confirmed licence"). **There is no score and no "compliant" label by design.**
* Legal documents, policy acceptance, cookie/consent settings, integrations, service availability, deletion requests, asset register.

## E. Legal documents and their status

All eight documents are **drafts** until you publish them: Privacy Policy, Terms and Conditions, Refund Policy, Cancellation Policy, Cookie Policy, Payment Policy, Accessibility Statement, Security Disclosure. They use `{{tokens}}` for facts only you know. Publishing is blocked while required sections are missing, required facts are empty, or a material change has no summary. Published versions are immutable; a change creates a new version; older versions can be restored as a new draft.

## F. Security report

Controls that existed and remain covered by tests: CSRF on every state-changing route (a route-sweep test fails if any route opts out, except three signed/rate-limited endpoints listed in the test), prepared statements, output escaping, strict CSP without inline script or style, HSTS in production, clickjacking protection, rate limits, password hashing, step-up for sensitive actions, audit hash chain.
Added in this work:
* **Cookie endpoint** is CSRF-exempt by design (a banner must work before a session exists): it requires a same-origin `Origin`/`Referer`, is rate-limited and writes nothing but a consent record.
* **One-click unsubscribe** is CSRF-exempt because the signed token (HMAC of the address) is the proof; unknown tokens give 404.
* **Rich-text legal editor** output is passed through a DOM whitelist sanitiser; script, style, event attributes, `javascript:` links are removed (tested).
* **Deletion requests**: rate-limited per IP and per email, email-verified, answers never reveal whether an address has an account, the status link is a random token stored as a hash.
* **CSP** is extended only with the exact origins of integrations that are switched on (and Turnstile when enabled).
* Owner-only enforcement on every compliance route is checked by the route sweep for all nine roles.
Not done / not claimed: no penetration test by a third party, no dependency audit service, no WAF configuration (Cloudflare is configured outside the code).

## G. Accessibility report

Method: the real design tokens of both themes are measured by an automated test (WCAG contrast formula), templates are scanned for images without `alt`, and rendered public pages are checked for language, a single `h1`, a skip link, labelled form controls and the banner's position in the tab order.
**Problems found and fixed**
* White text on the bright-cyan end of the brand gradient (1.8:1) in primary buttons, step numbers, service icons and avatars → dark navy text on the gradient (4.6:1 or better at every stop).
* Gradient headings were illegible in the light theme (cyan ≈ 1.6:1) → a darker gradient (`--grad-text`) used only in the light theme.
* Light-theme error red 4.48:1 → 5.7:1. Skip link white on blue 4.17:1 → 6.7:1. Notification bell badge 3.9:1 → 5.6:1. Receipt total row 3.9:1 → 5.6:1. Wizard "done/current" step numbers.
* Repeated requirement-wizard controls had visible but unassociated labels → `aria-label` added.
**Verified in place**: focus ring ≥ 3:1 in both themes; every outline removal is paired with a visible replacement; `prefers-reduced-motion` stops animation; native `<dialog>` for the cookie settings (focus trap, Escape); errors use `role="alert"`, confirmations `role="status"`.
**Not verified**: no run with a real screen reader (NVDA/JAWS/VoiceOver), no zoom/reflow test at 400%, no independent audit. The statement does not claim conformance; it says what was checked.

## H. Items that need a lawyer or a decision from you

Facts only you can supply (all empty today): legal proprietor's full name, operational representative, official business email, official WhatsApp number, grievance contact, jurisdiction, optional Udyam / Shop Act / GST numbers, retention years.
Clauses flagged for an Indian lawyer:
* Privacy Policy: legal-basis and DPDP Act/Rules 2025 wording and phasing; processing for clients abroad (GDPR/UK GDPR if targeted); retention period.
* Terms: limitation of liability cap; governing law, courts, dispute resolution; suspension for non-payment of renewals; electronic records and signatures (IT Act 2000, Contract Act 1872).
* Refund Policy: keeping half of the advance before the project starts (Contract Act s.74, "reasonable compensation not penalty"); no refund after the project starts (Consumer Protection Act 2019, unfair terms, service failure); refunds for international clients.
* Cancellation Policy: effect on delivery appointments and a signed multi-order agreement.
* Cookie Policy: categories and consent model for your visitors' jurisdictions.
* Payment Policy: tax wording while not registered for GST.
* Accessibility Statement: any obligations under the Rights of Persons with Disabilities Act 2016; update after an independent audit.
Other decisions: whether to turn on Turnstile; whether marketing email will ever be used; confirming the licence/source of the logo, icons and any picture you upload; reading every public page for claims you cannot prove; recording permission for every portfolio item and client logo.

## I. Deployment guide

1. **Back up** the database and files (Administration → Data → Backup).
2. **Upload** the package files over the existing ones, then open `upgrade.php` once as the Owner: it applies migrations 0036–0042 and syncs permissions. Delete or protect `upgrade.php` afterwards as before.
3. **Sign in as Owner** → Compliance & legal:
   1. fill *Business identity* and *Legal contact & grievance* (the legal pages cannot be published until the required ones exist);
   2. read each legal document, adjust the wording, have it reviewed (section H), then publish;
   3. review the *Integrations* register and mark each entry reviewed;
   4. record source and licence in the *Asset & licence register*;
   5. tick the permission box on every published portfolio item and client logo;
   6. work through the *Compliance checklist* and set statuses honestly.
4. **Policy acceptance**: after publishing a *material* update, choose the acceptance period; clients are asked to accept and are restricted after the deadline (you can extend or disable the restriction in settings).
5. **Optional Turnstile**: create a Cloudflare Turnstile site, put the site key and secret in the server `vertex/.env` (never in the repository or in the admin screens), switch it on in *Settings*. If the secret is wrong the forms refuse everyone (fail-closed), so test a form after enabling.
6. **Email**: the existing SMTP settings are used for all compliance emails. Send the test email to your own address only.
7. **Cloudflare / DNS**: nothing in this release changes DNS. If Cloudflare proxies the domain, keep SSL mode "Full (strict)", do not cache `/admin`, `/cookie-settings`, `/unsubscribe/*` or `/data-deletion*`, and allow `POST` to them. Add SPF/DKIM/DMARC for the sending domain at your DNS host so that deletion and policy emails reach inboxes.
8. **Rotate** any password or key that was ever pasted into a chat or document.
