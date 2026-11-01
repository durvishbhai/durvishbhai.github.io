# Compliance, privacy, security and accessibility: implementation inventory

Prepared before any change (stack: plain PHP 8.2 + MariaDB, own router/CSRF/CSP, no framework; public site = server-rendered pages with strict CSP, `script-src 'self'`).

## What already existed (verified in the code)
* **Policies**: `site_policies` + `site_policy_versions` (privacy, terms, refund & cancellation, payment, cookie); seeded as drafts containing `[ADMIN TO COMPLETE]` notes; edited in Administration → Website; no draft/published split, no scheduling, no acceptance.
* **Cookies / third-party scripts**: no analytics, no pixels, no web fonts (system font stack), no external scripts on the public site. Only first-party cookies (session, CSRF, theme preference). Razorpay checkout.js is loaded only on the client's pay page (CSP exception). Google sign-in is a redirect flow (no embedded script). No cookie banner existed.
* **Forms**: contact, requirements wizard, appointment request, partner/job applications, sign-up. Each had one combined consent checkbox. No age confirmation.
* **Security**: CSRF on every non-GET, prepared statements everywhere, output escaping, strict CSP (no inline script/style), HSTS in production, X-Frame-Options DENY, rate limits (login, forms, OTP…), Argon/bcrypt password hashing, 2FA, audit-log hash chain, secure upload pipeline (MIME sniffing, size limits, outside web root), honeypot on public forms. No bot challenge (Turnstile) existed.
* **Business content**: services in `site_services` (published/draft only), team, partners, portfolio, FAQs; seed text deliberately contains **no testimonials, ratings, awards or client claims** (verified in `SiteDefaults`).
* **Deletion**: no data-deletion/anonymisation workflow existed (only a recycle bin for staff-archived records).
* **Email**: shared branded template (`EmailTemplate`), Gmail SMTP via settings; no marketing emails exist.

## The 20 requirements: status at the start of this work
| # | Requirement | Existing | Missing / to build |
|---|---|---|---|
| 1 | Privacy Policy | draft placeholder text | full policy, versioning, Owner editing, publish |
| 2 | Terms | draft placeholder | full terms matching the real KIS workflow |
| 3 | Refund & Cancellation | generic draft | policies using the Part 8 rules, with lawful limits, legal-review flags |
| 4 | Form consent | one combined checkbox | separate acknowledgement + age confirmation, consent records |
| 5 | Cookie consent | none | banner, categories, log, withdrawal, footer link |
| 6 | Third-party disclosure | none | Owner register + public page |
| 7 | Data minimisation | not audited | form audit + documented purposes |
| 8 | Unsupported claims | seed content already clean | audit + keep clean; service availability states |
| 9 | Review authenticity | no reviews exist | rules + portfolio permission flag; nothing fabricated |
| 10 | Data visibility | policy-based access | audit of public endpoints |
| 11 | Accessibility + review integrity | partial | statement, fixes, automated checks |
| 12 | Media accessibility | no audio/video media | policy + statement |
| 13 | Image alt text | 22 `<img>`, all have `alt` | check meaningfulness, add automated test |
| 14 | Colour contrast | not measured | measured with a script, fixed |
| 15 | Keyboard | skip link exists | focus-visible audit, modal focus trap |
| 16 | Business details | company name only | legal identity page + configurable details |
| 17 | Age / children | none | 18+ confirmation, policy text |
| 18 | Unsubscribe | no marketing mail | readiness infrastructure only (transactional mail is never given an unsubscribe) |
| 19 | Asset licensing | not audited | asset register + audit |
| 20 | Deletion requests | none | full workflow |

(Implementation status and test status are in `docs/30-COMPLIANCE-REPORT.md`, updated at the end of each phase.)

## Legal position checked (for the review list, not legal advice)
* Digital Personal Data Protection Act, 2023: DPDP Rules, 2025 were notified in mid-November 2025 with a phased start: the commencement/definition rules and the Data Protection Board rules took effect on publication; consent-manager rules after one year; the main obligations on data fiduciaries (notice, consent, rights, breach notice, deletion/retention) after 18 months (reported as 13 May 2027). Until then the IT Act and the SPDI Rules, 2011 remain the operative framework. The platform is built to the DPDP standard now; **the exact applicable provisions need confirmation by an Indian lawyer.**
* Other regimes to review for international clients (not assumed to apply): EU/UK GDPR where EU/UK individuals are targeted, local consumer-protection rules for consumers abroad, and card/payment rules handled by Razorpay.
