# Backup & restore, and bug-support plans

## 1. Administration → Backup & restore (Owner only)

Two tabs. Every action asks for the Owner's password; every restore also needs the word RESTORE typed; everything is written to the audit log.

| | Backup (download) | Restore (upload) |
|---|---|---|
| **Database (.sql)** | One consistent snapshot: structure and all data (users, clients, projects, invoices, quotations, receipts, agreements, payments, website content, settings, audit log). | Replaces **all** data with the file. Checked line by line first (header, end marker, only SET / DROP TABLE IF EXISTS / CREATE TABLE / INSERT … VALUES; stacked statements and anything else refused). A **safety copy of the current data** is saved in `vertex/storage/backups` first (last 5 kept); if the restore fails half way it is put back automatically. Tables that exist now but not in the backup are removed so the result is exactly the backup; missing migrations are then applied. The Owner is signed out afterwards. |
| **Files (.zip)** | Every uploaded file (deliverables, payment proofs, enquiry attachments, website pictures) with a SHA-256 per file in `manifest.json`. | Puts files back. Paths are validated (no `..`, no hidden files, no script/HTML/SVG extensions; website pictures must be real images), each file is verified against its checksum before it replaces anything, identical files are skipped, files not in the backup are never deleted. |

Invoices, quotations, receipts and agreements are not stored as files: they are produced from the database, so they travel with the **.sql** backup. Take both backups together.
Secrets (Razorpay key secret, SMTP password) are stored encrypted with `APP_KEY` (in `vertex/.env`): keep a copy of that key; without it restored secrets cannot be opened.
Upload size is limited by the server (`upload_max_filesize`, `post_max_size`; shown on the page). For a bigger database use phpMyAdmin → Import.

## 2. Bug support after free support

* **Free support** = the project's warranty period (6 months from final delivery). A recorded warranty decides; with none recorded, 6 months from the day the project was completed. A warranty recorded as "not applicable" or suspended means no prompt.
* When it ends, and until the client chooses, the client portal shows a message (modal) on every page except the payment and support pages. Choices, per project:
  1. **Subscription**: ₹350 per month or ₹2,000 per year (saves ₹2,200). Every bug, major or minor. The client may enter a coupon. It is bought through an ordinary **invoice** (so Razorpay or UPI, receipts, coupons and the audit trail all work as usual) and starts when the invoice is paid; a refund or reversal stops it. Renewing while active continues from the day after the current period ends. Nothing is charged automatically: the client renews by paying again. If a subscription ends and nothing else is chosen, the message returns.
  2. **Pay per bug**: no invoice is made. Each bug is reviewed as before (covered / not covered / potentially chargeable) and a cost proposal is sent for the client's approval.
* Prices and the on/off switch: Administration → Integrations & settings → *Bug support plans*. New purchases use the new price; issued invoices keep theirs.
* The team sees the position of every finished project at **Support → Bug-support plans**.

## 3. Coupons

Coupons are created at **Sales → Coupons** (percentage or fixed, limits, validity, services) and now have **Where the coupon can be used**: project payments, bug-support subscriptions, or both.
Clients can enter a code themselves: when buying a subscription, and on their own unpaid invoice (**Have a coupon code?**, before any payment, once per invoice). On an already-issued invoice the discount is recorded as a credit note named "Coupon CODE": the issued invoice itself is never edited.
Clients also have a **Pay online** button on their invoice page when Razorpay is switched on (one checkout request for the whole balance).
