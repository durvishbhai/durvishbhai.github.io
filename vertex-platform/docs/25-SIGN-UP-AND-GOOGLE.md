# Sign up and "Continue with Google"

## Sign up tab
A **Sign up** button sits beside **Login** in the website header (and "New here? Sign up" on the sign-in page). It appears whenever client self-registration is on. Until the Owner chooses, the mode is **approval**: the client signs up, the team gets an approval notice, and the client signs in once approved. Choose Off / Email-confirmation / Approval under Integrations & settings → Feature switches. The **phone number is compulsory** (server-checked; the form marks it required) in every sign-up path, including Google.

## Continue with Google (clients only)
1. In the Google Cloud Console create an **OAuth client ID**, type *Web application*. Add the **Authorized redirect URI** shown on Integrations & settings → Google sign-in (`https://<your site>/auth/google/callback`).
2. Paste the **client ID** and **client secret** there, tick the switch, and save. The site address (APP_URL) must be `https://`.
3. The Login and Sign up pages then show **Continue with Google**.

How it behaves: server-side authorization-code flow; a one-time `state` and `nonce` are kept in the session; the secret never reaches the browser; the ID token must name this site's client ID, come from Google, be unexpired, carry our nonce and a **verified** email. An existing client with the same email is linked and signed in; a team account (staff, partners) is refused and keeps using its password; a deactivated account stays out; a new visitor is asked for a phone number, then follows the sign-up mode. Accounts with two-factor login still enter their code. A sign-in alert email is sent. No Google token is stored; only Google's stable account id (`users.google_sub`).
