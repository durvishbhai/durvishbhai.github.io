# Project delivery appointments

A client books **the day their project is delivered**, once the project's own preparation time is over. Everything is enforced on the server (`App\Services\DeliveryService`); the calendar only shows what the server would accept.

## How it works
1. The team sets, **per project**, a preparation time in days (7, 15, 30, 60 … any number up to 730) on the project's **Delivery** tab. The *eligibility date* is `preparation start + days`; the start defaults to the day the setting is first saved. Optional: a later **earliest selectable date**, a **latest selectable date**, and a switch to open/close booking. A project with no setting cannot be booked.
   * Example: start 1 November, 60 days → eligible from 31 December. If you want 1 January, set *Earliest selectable delivery date* to 1 January (the later of the two always wins). Dates before it are refused by the server, not only greyed out.
   * Changing the duration recalculates the eligibility date from the same start. **Confirmed appointments are never moved or deleted**: the page tells the team how many now fall before the new first date, and the client gets an email that the dates changed.
2. The client opens the project (Delivery tab, or **Delivery appointments** in the menu), sees the first date they can choose, picks a day on the calendar, reviews it and confirms. They get a booking reference and a confirmation email.
3. **One active appointment per project.** Only the Owner can allow more for a project.

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
| `delivery.configure` (sensitive) | Owner, Administrator | preparation times, links, capacity, blocks, reservations, settings, override of the preparation time |
| `delivery.book` | Client | book/move/cancel/ask on own projects |

## Emails (shared KIS template)
Client: *Your KIS Project Delivery Appointment Is Confirmed*, rescheduled, cancelled, completed, dates updated, one-time code, booking link. Team address (`delivery.notify_email`, else the mail sender address): *New Project Delivery Appointment — <Project ID>*, rescheduled, cancelled, change requested, date blocked.

## Data (migration 0034)
`project_delivery_settings`, `delivery_appointments`, `delivery_date_capacity`, `delivery_capacity_reservations`, `delivery_appointment_logs`, `delivery_booking_tokens`, `delivery_otps`. Every admin action is also in the audit log; the appointment history shows who did what.

Business time zone: `APP_TIMEZONE` (Asia/Kolkata); all "today"/date comparisons use it.
