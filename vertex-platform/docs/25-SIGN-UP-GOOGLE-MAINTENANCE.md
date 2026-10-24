# Sign up and "Continue with Google"

## Sign up tab
A **Sign up** button sits beside **Login** in the website header (and "New here? Sign up" on the sign-in page). It appears whenever client self-registration is on. Until the Owner chooses, the mode is **approval**: the client signs up, the team gets an approval notice, and the client signs in once approved. Choose Off / Email-confirmation / Approval under Integrations & settings → Feature switches. The **phone number is compulsory** (server-checked; the form marks it required) in every sign-up path, including Google.

## Continue with Google (clients only)
1. In the Google Cloud Console create an **OAuth client ID**, type *Web application*. Add the **Authorized redirect URI** shown on Integrations & settings → Google sign-in (`https://<your site>/auth/google/callback`).
2. Paste the **client ID** and **client secret** there, tick the switch, and save. The site address (APP_URL) must be `https://`.
3. The Login and Sign up pages then show **Continue with Google**.

How it behaves: server-side authorization-code flow; a one-time `state` and `nonce` are kept in the session; the secret never reaches the browser; the ID token must name this site's client ID, come from Google, be unexpired, carry our nonce and a **verified** email. An existing client with the same email is linked and signed in; a team account (staff, partners) is refused and keeps using its password; a deactivated account stays out; a new visitor is asked for a phone number, then follows the sign-up mode. Accounts with two-factor login still enter their code. A sign-in alert email is sent. No Google token is stored; only Google's stable account id (`users.google_sub`).

## Maintenance mode
Integrations & settings → Feature switches → **Maintenance mode**. While it is on, visitors and clients see a branded "We'll be back soon" page (HTTP 503 with `Retry-After`, not indexed by search engines); an optional message can be shown on it. Signed-in **team members keep full access** (so you can finish the work and check the site), and the sign-in pages, `/health` and the payment webhooks (`/webhooks/…`) keep working so no payment is lost. If the admin screen cannot be reached, set `MAINTENANCE_MODE=true` (and optionally `MAINTENANCE_MESSAGE=...`) in `.env`; remove it to reopen the site. Switch it off after the work, because clients cannot use the portal while it is on.

## Error pages and the offline page
Every error uses one branded page (`app/Views/errors/error.php`): 400, 401, 403 (Access denied), 404 (Page not found, with helpful links), 405, 413, 419 (Your session expired), 429 (Too many attempts), 500 and 502–504. Each has a plain explanation, a button back (dashboard when signed in, home otherwise), "Try again" for server errors, and a **Reference** code to quote to the team; technical details are never shown (only when `APP_DEBUG` is on). Server errors use a page that needs no database, so they still display when the database is down. Error pages are never indexed by search engines.

**Offline page**: `/offline`, shown by a small service worker (`/sw.js`, registered by `app.js`) when a device has no internet and a page cannot load. The worker stores only that page and its styles: no pages, forms or personal data are ever saved on the device. The first visit installs it; it works from the second page view onward.

## Account activity (Owner)
**Administration → Account activity** (`/admin/activity`) is for the Owner only (permission `activity.view_all`; no other role has it, not even Administrator). It shows:
* **Accounts**: every account (team, clients, partners) with role, status, last sign-in time and address, sign-ins and actions in the last 30 days, and whether two-step login is on. Filter by name or email, who (team / clients / partners) and status.
* **Live feed**: the latest activity of all accounts, readable ("Signed in", "Invoice issued"…), filterable by kind (sign-ins and security, accounts and roles, money, projects and delivery, clients and partners, website and forms, system), dates, name, email or IP.
* **One account** (`See activity`): the profile summary plus what that account did, what was done to it (including failed sign-in attempts), with device and IP and the full before/after details.
Everything comes from the tamper-proof audit log; nothing here can edit or delete it. Opening an account's activity is itself recorded. Every signed-in person can also see **their own** sign-ins and account actions at **My account → Account activity** (`/account/activity`).
