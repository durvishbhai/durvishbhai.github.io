# Appointments with the founder

## For visitors and clients
`/book-appointment` (signed in or not; clients also find **Book an appointment** in their menu, with their details already filled in). Fields: full name, email, phone, WhatsApp number (tick "same as my phone number" and the field is not needed), company/organization (if applicable), occupation, purpose, **date**, **available timing**, **meeting type** (Online / In-person) and, for in-person meetings, **the place**. The date must be between tomorrow and 60 days ahead, and the timing must run on that weekday.

**After submitting** the person sees "Thank you. Your appointment is not booked yet": it is a request, not a confirmed appointment, and the team will contact them to check that the request is genuine and to confirm the date, time and place. They also get an email that says the same. Nothing is held or confirmed by the system itself.

## For the team (Administration → Appointments)
Needs the `appointments.configure` permission (Owner and Administrator by default).
* **Available timings**: add, edit, hide ("Offered" off) and delete times (from, to, an optional name, the weekdays each runs on, display order). The form offers only the times you add; with none, visitors are told no times are open.
* **Places for in-person meetings**: add, edit, hide and delete places (name, optional details, order). **"We will discuss it later"** is always offered as the last option and cannot be edited or removed.
* **Latest requests**: the most recent appointment requests with date, time, type and place; open one in the CRM to call the person, then move or close it. Each request is an ordinary CRM enquiry (source "Appointment request") with the meeting details in "Submitted with the form"; "Meeting with" is `APPOINTMENT_HOST_NAME` / `APPOINTMENT_HOST_TITLE` (default Durvish Jawale, Founder & CEO).

Deleting a time or place never changes earlier requests: they keep their own text.

## Requirements form lists
The requirements form's **Project Type / Category** and **Expected Primary Outcome** lists are the 16 and 12 options the business supplied (the last of each is "Other (mention in special notes)"); the notes field is now called **Special notes**.
