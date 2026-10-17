# 17 · Phase 8 — Quotations, invoices (no tax), Google Pay settings

Migrations `0012_billing_gateway.sql`, `0013_payment_events.sql`. Code: `app/Services/{BillingItems,BusinessProfile,QuotationService,InvoiceService,GatewayConfig}.php`, extensions to `ClientPaymentService`, controllers `Billing/{Quotation,Invoice,MyBilling}Controller`, `Admin/BillingSettingsController`, views `quotations/`, `invoices/`, `my-billing/`, `admin/{payments,business}.php`, print layout `layouts/print.php`.

## 0. Read this first — what "Google Pay" can and cannot be here
**Google Pay is not a payment gateway you can connect on its own.** Google gives merchants two things, and the admin screen (*Administration → Payments (Google Pay)*) has a section for each:

| | 1. Google Pay by UPI | 2. Google Pay API (cards) |
|---|---|---|
| What it is | The client taps *Pay with Google Pay*; Google Pay (or any UPI app) opens with **your UPI ID, the amount and the invoice number** filled in; money goes straight to your bank | A Google Pay button for cards saved in Google Pay. Google encrypts the card and hands it to a **payment processor** (Razorpay, PayU, Cashfree, Stripe …) that charges it |
| Needs | A UPI ID (ideally a Google Pay for Business / merchant UPI ID) | A Google Pay & Wallet Console business profile and **merchant ID** (Google reviews your website before PRODUCTION), **plus an account with a processor** and its merchant ID and API keys |
| Fees | None | The processor's fees |
| Status here | **Works today.** Settings are validated and the link is built and tested | **Fields are stored; the integration is NOT active.** The processor-specific connection (create the charge, receive and verify its webhook) is not installed, so **no card payment is taken or confirmed**, clients are not offered it, and the screen, the System status page and this document all say so |
| Confirmation | **Manual by design.** UPI does not call back a plain UPI ID. The client enters the transaction (UTR) number; **staff check the bank statement** and verify it | (when built) the processor's signed webhook |

To finish the card mode **I need to know which processor you will use**, plus its sandbox credentials. I did not guess one: building the wrong adapter would be worse than none.

## 1. Tax
The business is **not GST-registered**. There is **no tax column** in any quotation or invoice table (a test asserts it) and no tax line anywhere. Every document prints the *tax statement* from *Business details* (default **"Tax is not applicable."**). If GST registration changes later, that is a new feature, not a setting.

## 2. Business details
*Administration → Business details* (`billing.configure`, password): business name (required to issue an invoice), legal name, address, email, phone, website, tax statement, payment instructions (bank details, UPI ID), footer. A copy is **frozen into each invoice when it is issued**: editing the profile never changes an issued invoice.

## 3. Quotations
Draft (manager or any `quotes.create` holder) → **Send** (`quotes.send`: Administrator/Owner) → the client **accepts or declines in the portal** (`quotes.respond`: client role only; a client never sees a draft; another client's quotation is 404) or staff record a decision received elsewhere (`quotes.record_decision`, with a note). Expiry is automatic (`valid until`, also run by the scheduled task). **Accepting a quotation records agreement only: it creates no payment, changes no project price and makes no referral commission eligible** (tested). An accepted quotation becomes **one** draft invoice (lines copied); a second conversion is refused.

## 4. Invoices
Draft (`invoices.create/edit`, e.g. Finance) → **Issue** (`invoices.issue`, Administrator/Owner, password) → Partially paid → Paid; **Void** (`invoices.void`, password, reason). The **number (INV-YYYY-NNNNN) is assigned at issue**, is sequential and is never reused (a voided number stays on record). An **issued invoice is never edited**: void it and issue a new one. A draft can have a discount; the total is exact integer paise (quantity up to two decimals; quantity × price must come to whole paise). An invoice may be linked to a project (needed if a referral commission should follow its payments). *Print view* is a clean page for the browser's **Print → Save as PDF** (no PDF library).

## 5. Payments against invoices (no second payments table)
Payments are rows of the existing verified-payment table `client_payments` (now with `invoice_id`, an optional project, `source`, and a *rejected* status), so **no financial record is duplicated** and the Phase 6 commission engine reads them unchanged.
* **Staff record** a payment on the invoice page (amount ≤ what is still due, unique transaction reference); a **different person verifies** it (password).
* **Client submits payment details** (*I have paid*): amount, UPI or bank transfer, UTR number, date. This creates a **claim that counts for nothing**: the invoice still says unpaid. Limits: ≤ what is still due (other pending claims counted), a UTR can never be submitted twice (even after a rejection), 10 submissions an hour, no markup characters.
* **Staff verify against the bank**: they enter the amount that actually reached the account (a difference is recorded as an *amount corrected* event and the bank figure replaces the claim) and, for a project invoice, whether it is Advance / Balance / Other. Or **reject** it with a reason (it stays on record; the client is told in the portal).
* The invoice status follows **verified, refund-adjusted money only**: Issued → Partially paid → Paid, and back to Partially paid if part is refunded. An invoice with money on it or a payment awaiting verification **cannot be voided**.
* **Commission:** a verified payment on a project invoice drives the 10% (advance) and 5% (full payment) milestones exactly like any other verified payment (tested end to end: quotation → invoice → two claimed payments → both milestones). A claim, a quotation or an invoice alone earns nothing.

## 6. Admin fields (what each one means)
**Google Pay by UPI:** UPI ID (as shown in Google Pay), payee name shown to the payer, optional merchant category code, *show the Pay button* switch, password. A *Check these settings* button validates the format and shows the example link (it **cannot** prove the UPI ID exists: pay yourself ₹1 once).
**Google Pay API:** environment (TEST / PRODUCTION; PRODUCTION requires the approved Google Pay merchant ID), Google Pay merchant ID, merchant name, website (https, no path), processor identifier (the "gateway" value in your processor's Google Pay docs), your merchant ID at the processor ("gatewayMerchantId"), processor API key ID, **processor API secret** and **webhook signing secret** (sealed with AES-256-GCM using a key derived from `APP_KEY`; written once; the page afterwards shows only the last four characters; leaving the field empty keeps the saved secret; a checkbox deletes them), card networks, authentication methods. **Secrets never appear in HTML, in the audit log or in `config_json`** (tested). Saving needs `billing.configure` (Administrator/Owner) **and the password**. Without `APP_KEY` secrets cannot be saved.

## 7. Permissions
New: `billing.configure` (sensitive; Administrator/Owner), `quotes.respond` (client only), `quotes.record_decision` (sensitive; Administrator/Owner). Existing keys now used: `quotes.create/send/view`, `invoices.view_all/create/edit/issue/void/view_own`, `payments.pay` (client claim), `payments.record/reconcile/view_all`. Finance Staff can draft invoices and record payments but cannot issue, void, verify or reject. Managers draft quotations; they do not see invoices. Clients see only their own issued invoices and sent quotations.

## 8. Tests (actual results)
`BillingServiceTest` (22), `BillingHttpTest` (8), `RouteSweepTest` and `Phase6HttpTest` re-run; browser walk-through (Chromium, dev database): business details → Google Pay UPI settings → API fields (secret not echoed) → Finance drafts an invoice → Administrator issues → client opens it, sees the Pay button and submits the UTR → Administrator verifies with the bank amount → invoice **Paid**; no horizontal overflow at 375 px on 9 pages. Full suite after Phase 8: **695 tests, 20,982 assertions, all passing**.

## 9. Manual test checklist (development database)
1. `php bin/console.php migrate && php bin/console.php permissions:sync` (new keys are granted automatically). Sign in as the Administrator.
2. *Business details*: fill in and save (password). *Payments (Google Pay)*: enter a UPI ID and name, tick the switch, save, press *Check these settings*.
3. As Finance: *Invoices → New invoice* for a client, add lines, save the draft. As the Administrator: open it → *Issue invoice* (password). The number appears.
4. As the client: *My invoices* → the invoice → *Pay with Google Pay* (on a phone this opens the UPI app) → enter any UTR → *I have paid*. The invoice still says Unpaid.
5. As the Administrator: *Client payments* → the submission → enter the bank amount → *Verify*. The invoice is Paid; the client is notified. Try *Reject* on another submission.
6. *Print view* on the invoice; check the tax statement and that no internal notes appear.
7. Try the refusals: client opens another client's invoice (404); Finance tries to issue (403); a second UTR reuse (refused).

## 10. Limitations
* **Card payments through Google Pay are not available** until a processor integration is built (§0). **No automatic payment confirmation exists** (no webhook endpoint); UPI/bank payments are confirmed by staff.
* A personal UPI ID may be limited by Google Pay for business payments; use a merchant UPI ID and test with a real ₹1 payment.
* The `upi://` link opens a UPI app only on a phone; on a computer the page shows the UPI ID, amount and invoice number to pay manually.
* No PDF generation (browser print), no recurring invoices, no credit notes, no multi-currency, no late fees, no email delivery of invoices (the client is notified in the portal; email depends on SMTP being configured).
* GST is not supported by design (§1).
