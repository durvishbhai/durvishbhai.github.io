# Project delivery appointments

A client books **the day their project is delivered**, after choosing the project start date: the delivery date must be after start + the team's estimated time. Everything is enforced on the server (`App\Services\DeliveryService`); the calendar only shows what the server would accept.

## How it works
1. The team sets, **per project**, the **estimated time to complete** (a number of days, weeks or months) on the project's **Delivery** tab. Optional: a **fixed start date** (if the team wants to decide the start), an *earliest* and a *latest selectable delivery date*, and a switch to open/close booking. A project with no setting cannot be booked.
2. The **client chooses the project start date** (step 1), then picks the delivery date on the calendar (step 2). The delivery date must be **after start + the estimated time**: start 1 January 2027 + 2 months → the first date they can choose is **2 March 2027**; everything up to and including 1 March is refused by the server, not only greyed out. (Start 1 Nov + 60 days → first choice 1 January.) Calendar months keep the day of the month, or the last day of a shorter month. The optional earliest date can only push the first date later.
   * The start date can be today or up to `max_days_ahead` (default 180) ahead and is stored on the appointment. Moving the appointment later uses the same start date, so it cannot be used to dodge the rule.
   * Changing the estimate never moves or deletes a confirmed appointment: the page tells the team how many now fall before their new first date, and the client gets an email that the dates changed.
   * The review panel shows the project, start date and estimated time; the chosen delivery date is on the confirm button and in the confirmation.
3. After confirming, the client gets a booking reference and a confirmation email.
4. **One active appointment per project.** Only the Owner can allow more for a project.

## Capacity
* Default **2 confirmed appointments a day**, set by the Owner/Administrator (Delivery appointments → Capacity & settings, or Integrations & settings → Delivery appointments).
* Per-date capacity, **blocked dates** ("Mark date as fully booked", with an internal reason; confirmed appointments on that date stay), and **reserved capacity** (internal planning: lowers what clients can book, is not a client appointment, and is shown separately).
* Manual/offline bookings use the same capacity. An *Admin Reserved Slot* booking uses up one reserved slot of that date (total unchanged).
* Lowering capacity never removes a confirmed appointment; the date just has no free slots.
* Cancelled appointments free their date immediately. Completed ones keep counting for their day.
* Concurrency: `delivery_date_capacity` has one row per date; bookings take `SELECT … FOR UPDATE` on it (always in the order project → dates ascending → audit chain) and re-read the counts with locking reads inside the transaction. Deadlock losers are retried. Tested with forked processes (8 clients racing for one date book exactly 2).

## Booking methods
* **Dashboard** (clients with `delivery.book`, own projects only).
* **Public page** `/delivery-appointment` (no sign-in). Project ID + client name never unlock anything alone:
  * `otp` (default): after ID + name, a 6-digit code goes to the client's **registered email**. The reply is identical whether or not the details matched, nothing about the project is shown before the code, codes expire in 10 minutes, allow 5 attempts, newer codes replace older ones, at most 3 codes per project per 15 minutes, and the pages are rate limited per IP.
  * `token`: the team creates a private link on the project's Delivery tab (shown once; only its SHA-256 is stored; valid 30 days; can be switched off). 
  * `both` / `off`. Set in Capacity & settings (or `DELIVERY_PUBLIC_MODE`). A verified visitor can book only the one project they proved, for 30 minutes, and cannot see anything else.

## Client changes
Until `client_cutoff_days` (default 3) before the date, a client can move their appointment (at most `max_reschedules`, default 2) or cancel it themselves; every new date is validated like a new booking. After that, or past the limit, they **send a request** and the team is emailed; the team answers by rescheduling/cancelling or closes the request.

## Team screens
* **Delivery appointments** (`/admin/delivery`): today/upcoming/confirmed/requests/fully booked/reserved/offline/completed/cancelled counts, a month calendar (booked+reserved / capacity per day), manual booking form, and the table with filters (project, client, dates, status, source, requests only).
* **Appointment page**: details, reschedule, complete, cancel (with reason), internal notes, close a client request, full history.
* **Capacity & settings** (`/admin/delivery/capacity`).

## Permissions
| Permission | Default roles | Allows |
|---|---|---|
| `delivery.view` | Owner, Administrator | every appointment |
| `delivery.view_assigned` | Manager, Support | appointments of assigned projects |
| `delivery.manage` | Owner, Administrator, Manager | manual booking, reschedule, cancel, complete, notes — on projects they can access |
| `delivery.configure` (sensitive) | Owner, Administrator | estimated times, links, capacity, blocks, reservations, settings, override of the estimated-time rule |
| `delivery.book` | Client | book/move/cancel/ask on own projects |

## Emails (shared KIS template)
Client: *Your KIS Project Delivery Appointment Is Confirmed*, rescheduled, cancelled, completed, dates updated, one-time code, booking link. Team address (`delivery.notify_email`, else the mail sender address): *New Project Delivery Appointment — <Project ID>*, rescheduled, cancelled, change requested, date blocked.

## Data (migration 0034)
`project_delivery_settings` (+ migration 0035: estimate unit, fixed start date), `delivery_appointments` (+ `project_start_date`), `delivery_date_capacity`, `delivery_capacity_reservations`, `delivery_appointment_logs`, `delivery_booking_tokens`, `delivery_otps`. Every admin action is also in the audit log; the appointment history shows who did what.

Business time zone: `APP_TIMEZONE` (Asia/Kolkata); all "today"/date comparisons use it.
