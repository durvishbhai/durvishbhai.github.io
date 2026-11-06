# Mobile app (PWA) and phone notifications

## What it is
The website is also an **installable app**. Once installed it opens full-screen from the phone's Home Screen with the KIS name and logo, no browser address bar, and shows an "You are offline" page when there is no internet. It is the same site (one code, one login), not a separate store app.

* **App name / icon**: the business name from Integrations & settings → General (short name "KIS" is made from its initials), and the KIS logo mark on a dark background (`public/assets/img/pwa-192.png`, `pwa-512.png`, `pwa-maskable-512.png`, `apple-touch-icon.png`). The manifest is `/manifest.webmanifest`; it opens `/dashboard`.
* **Install**:
  * **Android (Chrome)**: an "Install" button appears in the banner at the top of the portal and under **My account → Mobile app & notifications**; or the browser menu → *Install app*.
  * **iPhone (Safari)**: Share → **Add to Home Screen** (the site shows these steps). iPhone notifications work only after this step, from iOS 16.4.
  * **Computer (Chrome/Edge)**: the install icon in the address bar.

## Notifications on the phone
Every notification the portal creates for a person (payment confirmed or failed, payment request, invoice, receipt, project and request updates, approvals for the team…) is also **pushed to their phone or browser** if they allowed it. They turn it on with the banner "Turn on notifications" or under **My account → Mobile app & notifications** (with a "Send me a test notification" button), and off in the same place. Tapping a notification opens the right page. A device that stops receiving (uninstalled, permission removed) is forgotten automatically; up to 10 devices per person.

* Messages are **encrypted** to the device (Web Push, RFC 8291) and the server identifies itself with its own key pair (VAPID, RFC 8292), created automatically the first time and stored sealed in the database. Google, Mozilla, Apple and Microsoft's push services carry the message but cannot read it. Only those services' addresses are ever called.
* Owner switch: Integrations & settings → **Notifications** → "Send phone and browser notifications…" (also `NOTIFY_PUSH` in `.env`).
* Needs **HTTPS** (the live site has it), and the scheduled task for the retry job as for emails. Nothing else to configure and no paid service.
* Offline: the service worker (`/sw.js`) stores only the offline page and its styles. No pages, forms or personal data are kept on the device.

## Good to know
* Push needs the person to allow it once per device; browsers never allow it silently.
* If the site address (APP_URL) or `APP_KEY` changes, people may need to turn notifications off and on again.
