# Transactional emails

All emails use **one design** (`app/Services/EmailTemplate.php`) and **one sender** (`Mailer`, SMTP from Integrations & settings → Email). Nothing else sends mail.

## The design
600px wide, table-based inline CSS (works in Gmail, Outlook, Apple Mail and on phones), UTF-8, with a plain-text twin. Header: the KIS logo (served from the site's own `/assets/img`) and "KRAZY INTEGRATED SOLUTIONS". Then a small category label (coloured by tone: success, info, warning, danger), the heading, "Hello {name},", short explanation, a details card (reference, amount, method, date, status…), **one** action button (only when a real page exists) with the full address printed under it, optional notes, and the footer: "This is an automated notification from Krazy Integrated Solutions", why the person got it, the support email (from Business profile) and the site address. Dynamic text is escaped; empty details are left out; very long names are shortened; the button needs an absolute https address.

## Email types (inventory)
| Group | Emails | Sent by |
|---|---|---|
| Account & security | Confirm email address · Account approved · Team invitation · Password reset · Password changed ("Secure My Account") · "Account already exists" notice · New sign-in alert · Account deactivated / reactivated | `AccountMail`, `UserService`, `Auth` |
| Payments & invoices | Payment confirmed · Payment not confirmed · **Payment failed** (Razorpay `payment.failed` webhook) · Payment request ("Pay now") · Invoice issued · Invoice paid · Receipt issued · Credit note | `EmailChannel` from the existing notifications |
| Projects | Project confirmed after advance · Deliverable ready/revised · Revision updates · Scope-change decisions · Additional-cost approval · Agreements to sign · Quotation sent | `EmailChannel` |
| Onboarding | "We received your requirement / message / appointment request" · Client project request received · Account approved | `IntakeMail`, `AccountMail` |
| Renewals | Expiry reminders to the client · Price raised · Payment received · Renewed | `RenewalScheduler`, `EmailChannel` |
| Team / hiring / partners | Job application received (applicant) · New application (team) · Partner application received · approved · rejected · Referral status (previewed and confirmed by a person first) · Commission/payout notices · Approval requests (new client account, payments to verify, payouts, commissions) | `IntakeMail`, `EmailChannel`, `ReferralMailService` |
| System | "Test email" button | `SettingsController::testEmail` |

Old-style plain messages passed to `Mailer::send()` are automatically wrapped in the same design.

## Rules that are enforced
* A payment email is created only after the server has verified the payment (it is a consequence of the existing verified code path). A "failed" email never changes the order.
* No passwords, hashes, reset tokens (other than the single-use link the person asked for), gateway secrets or card numbers are ever in an email. Internal notes are never copied to clients.
* One email per event: the notification row is unique per event; the email status (`sent`/`failed`) and the attempt count are stored on it; failed ones are retried by the scheduler up to 3 times within 3 days.
* Mail problems are logged and never stop the workflow.
* Switches (Integrations & settings → Notifications): client/partner copies, team copies, sign-in alerts; plus the existing renewal-reminder switch.

## Manual actions (not done by the software)
* **DNS** (at your domain host): publish SPF for the sending mailbox's provider (Hostinger: include the value shown in hPanel → Emails → DNS), DKIM (hPanel → Emails → enable DKIM) and a DMARC record (`_dmarc` TXT, start with `p=none`). Without them some inboxes put emails in spam. The software never edits DNS.
* **Sending address**: set From address to a mailbox that exists (or an alias of it) and keep "Send a test email to me" green.
* **Logo**: nothing to upload; the header uses `/assets/img/krazy-mark.png`. Some mail apps hide remote images until the reader allows them; the company name beside it stays readable.
* Set **Business profile → Email** so the footer shows a support address (it is left out when empty; nothing is invented).
