# 21 · Payment receipts and receipt verification

Receipts follow the KIS template ("KIS System Generated Payment Receipt"): dark brand header with the logo, **PAYMENT RECEIPT**, receipt number `KIS-PR-YYYY-NNNN`, client details (client ID, name, email, phone, address), payment details (method, transaction ID, date, time, status), one line per item, subtotal / discount / tax / payment received / **TOTAL PAID**, amount in words (Indian numbering: "Rupees One Lakh Twenty Thousand Only"), the payment acknowledgement, "Thank You!", and the contact footer. The same layout is used on screen (print or save from the browser) and as a PDF (`Download PDF`). Each receipt also carries a **verification QR and code**.

## How receipts are made

| How | When | Who |
|-----|------|-----|
| **Automatically** | the moment a client payment is verified (including payments confirmed by Razorpay) or a payment is counted on a domain/hosting renewal | the system |
| **By hand** | for a payment made outside the system (cash, bank transfer, a card machine...): *Receipts → Issue a receipt* | a person with `receipts.issue` (Finance Staff, Administrator, Owner), who must re-type their password |

* One payment gets **one** receipt: asking again never makes a second one. A hand-made receipt needs a transaction ID / reference and the same transaction cannot get two receipts.
* A hand-made receipt records that money was received and numbers it; it does **not** change any invoice balance. To apply a payment to an invoice, record it on the invoice: its receipt is then made automatically.
* A registered client is notified and finds the receipt under *My receipts*; a receipt for someone who is not a registered client can still be made (name, email, phone, address typed in).
* The client's details and the amounts are **frozen on the receipt**, so later edits to the client record never change an issued receipt.
* A receipt is **never edited or deleted**. A mistake is corrected by **voiding** it (`receipts.void`: Administrator/Owner, with the password and a reason of at least 10 characters), and issuing a new one. The void is audited and the verification page then says "voided".
* If the payment behind a receipt is later reversed (chargeback) the receipt shows **PAYMENT REVERSED**; after a partial refund it shows the refunded amount.
* Older payments get their receipt the first time anyone opens it.

## The public verification page

* `https://YOUR-SITE/verify` (a form) and `https://YOUR-SITE/verify/{code}` (what the QR opens). No sign-in needed. Reached through the *Verify receipt* link.
* The QR/URL code alone is enough; typed in by hand, the receipt **number and code must both match**. A wrong guess says only "No matching receipt found". Attempts are rate limited per IP address (40 per 10 minutes) so codes cannot be guessed; codes are 12 random characters from a 31-letter alphabet (about 59 bits).
* The page says one of: **Verified: this payment was received**; **voided**; **the payment was reversed**; or **could not be verified** (the record no longer matches its seal). It shows the receipt number, amount, payment date and time, method, a **masked** client name (`P*** F***`), what it was against, the **last four characters** of the transaction ID and the issue date. It never shows email, phone, address or the full transaction ID, and it is not indexed by search engines.
* **Seal:** each receipt stores a keyed hash (HMAC-SHA256, key derived from `APP_KEY`) of its number, code, payment time, transaction, method, amount and client name. The page recomputes it. A receipt whose row was altered directly in the database does not show "Verified". (This protects against tampering, not against someone who holds both the database and `APP_KEY`.) **Do not change `APP_KEY`** on a live site: it would make every existing receipt fail verification.

## Where things are

* Staff: *Money → Receipts* (`/receipts`), *Issue a receipt* (`/receipts/new`). Clients: *My receipts* (`/my/receipts`). The older per-payment links (`/my/receipts/{payment}`, `/client-payments/{payment}/receipt`) still work and show the same receipt.
* Permissions: `receipts.view_all` (Finance Staff, Administrator, Owner), `receipts.issue` (same), `receipts.void` (Administrator, Owner), `receipts.view_own` (clients).
* Database: migration `0024_receipts.sql` (table `receipts`). Numbers come from the existing atomic reference counters (`KIS-PR`).
* The footer contact line uses the business profile (email, phone, website); if no website is set the site's domain is shown. Set them under *Administration → Business details*.
