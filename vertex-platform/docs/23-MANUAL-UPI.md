# 23 · Manual UPI payments

An alternative to Razorpay for businesses that take UPI straight into their own bank account.

## Set it up (Owner / Administrator with `billing.configure`)
*Administration → **Manual UPI payments*** (`/admin/manual-upi`): switch it on, enter your **UPI ID** (`name@bank`), optionally the name shown to the client, an instruction line, and upload your **bank QR picture** (PNG, JPG or WEBP, up to 5 MB). Saving asks for your password. You can choose whether the client **must** attach proof (default: yes).

## What the client does
On an unpaid invoice (*Pay this invoice*) the client sees your QR, your UPI ID with a *Copy* button, an *Open my UPI app* link for phones, the amount and your instructions. They pay in their UPI app, then enter the **transaction ID (UTR)**, the amount, the date and attach a **screenshot (or PDF) as proof**, and press **Submit for approval**. The invoice still shows as unpaid; their list shows "Sent to our team: awaiting approval".

## What your team does
The submission appears under **Client payments** (and notifies everyone who can verify payments). The page shows the amount, transaction ID and a link to the proof. The verifier checks the **bank statement**, enters the amount that really arrived and confirms with their password (or rejects it with a reason). Only then is the payment **successful**: the invoice becomes paid or part paid, the receipt is made automatically, the client is told ("Approved: payment successful"), and an advance payment fixes the project as usual. The person who recorded a payment cannot verify it, and the same transaction ID cannot be submitted twice.

## It never runs next to Razorpay
Manual UPI and Razorpay are **alternatives**:
* While the Razorpay switch (Integrations & settings → Razorpay → *Accept online payments through Razorpay*) is **on**, manual UPI is not shown to clients and claims are refused, even if it is saved as "on".
* You cannot switch manual UPI on while Razorpay is on (the page tells you to switch Razorpay off first), and you cannot switch Razorpay on while manual UPI is on.
* With **neither** on, clients see no payment form; they are asked to contact you or use the payment details printed on the invoice, and the team can still record payments by hand.

Technical: migration `0027_manual_upi.sql` (table `manual_upi_settings`, column `client_payments.proof_file_id`). The QR and the proofs are stored privately (outside the web root); the QR is shown to signed-in clients through `/my/upi-qr`, proofs only to the team through `/client-payments/{id}/proof`.
