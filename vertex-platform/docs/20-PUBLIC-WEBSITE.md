# 20 - Public corporate website

The public website (home, about, services, team, partners, portfolio, careers, contact, project requirements, FAQ, policies, sitemap) is part of the same
application as the dashboard. **Everything a visitor reads is editable in Administration -> Website**; nothing needs a developer after launch.

## Brand wording

The brief that produced this module was written for "Vertex Integrated Solutions / VIS OS". The company is now **Krazy Integrated Solutions**, so all shipped
copy uses the tokens `{company}` and `{abbr}`. They are replaced with the company name configured in *Administration -> Integrations & settings -> Website -> Company name* (falling back to
`APP_NAME`). Change the name there and every page changes. Editors can type the tokens in their own text too.

## Pages and addresses

| Page | Address | Content comes from |
|---|---|---|
| Home | `/` | Home sections (Website -> Page wording -> Home); a published CMS page with the address `home` still replaces it |
| About Us | `/about` | About sections |
| Services | `/services`, `/services/{slug}` | Website -> Services |
| Our Team | `/our-team` | Website -> Team (page and menu link appear only when someone is published) |
| Our Clients | `/our-clients` | Website -> Clients (name, country, logo, description, website); shown grouped by country; page wording under Page wording -> Our Clients page |
| Our Partners | `/our-partners` | Website -> Partners + the existing referral-partner application (`/partners/apply`) |
| Portfolio | `/portfolio`, `/portfolio/{slug}` | Website -> Portfolio (hidden until a project is published) |
| Careers (jobs and hiring: one page) | `/careers`, `/careers/apply`, `/careers/{opening}` (the old `/hiring…` addresses forward here) | Administration -> Website -> Careers page (status switch), Page wording -> Careers page (roles, salary note, steps, form) and Openings |
| Careers | `/careers`, `/careers/{slug}`, `/careers/apply` | Website -> Openings; applications in Administration -> Job applications |
| Contact Us | `/contact` | Contact form; staff see it in CRM -> Enquiries |
| Project Requirements | `/request-quote` (alias `/project-requirements`) | Requirements form; staff see it in CRM -> Enquiries |
| FAQ | `/faq` (client-side search) | Website -> FAQs |
| Policies | `/privacy-policy`, `/terms-and-conditions`, `/refund-and-cancellation-policy`, `/payment-policy`, `/cookie-policy` | Website -> Policies |
| Sitemap | `/sitemap` (people), `/sitemap.xml` and `/robots.txt` (search engines) | Generated from published content only |
| 404 / error | any unknown address | `errors/error.php` (designed page, links back to useful places) |

## What an editor controls

* **Page wording and section order** for Home, About, Services, Team, Partners, Portfolio, Careers, Contact, Project Requirements and FAQ: each section can be shown/hidden, re-ordered, reworded, or reset to the shipped text.
* **Lists** (Services, Team, Clients, Partners, Portfolio, Openings, FAQs, Policies): draft -> published -> archived (openings can also be *closed*), manual order, SEO title/description, pictures from the Media library.
* **Policies** keep every saved wording as a numbered version with an effective date; an older version can be restored.
* **Menus**: header (with an automatic *Services* dropdown) and footer. A saved menu overrides the built-in one; links to unpublished policies are never shown.
* **Contact details and social links**: Integrations & settings -> Website. Empty values are not displayed anywhere.
* **Form settings**: wording of both forms; inquiry types, project categories and timelines are fixed lists in code (`EnquiryExtras`), services offered come from published Services.

## Careers page (one page for jobs and hiring)

Careers and Hiring used to be two pages; there is now **one**: `/careers`. It carries the role cards, the published openings (Website -> Openings, each with its own detail page), the salary note, the steps and the application form. `/hiring`, `/hiring/received` and `/hiring/apply` forward to it (301/308) so old links, bookmarks and search results keep working. The old separate Careers wording (heading, "areas we work in" list, openings text, form note) is gone; the page wording is the one under *Page wording → Careers page*. If you saved your own header menu, remove the duplicate entry yourself: the built-in menu now has a single "We're Hiring" / "Careers" link.

`/careers` lists the open roles (shipped: Full Stack Developer, Frontend Developer, Backend Developer, Video Editor), states that **salary is decided after the meeting**, explains the steps and has an interactive application form (click a role card to select it, live progress bar, character counters, drag-and-drop file box, hints that change for developer vs video roles). Submissions are ordinary job applications (Administration -> Job applications) with the role, experience and availability recorded.

**Admin switch** (Website -> Hiring, needs `cms.publish`): *Open* shows roles and form and a small "We're hiring" bar on the other pages; *Closed* keeps the page but shows "Hiring is currently closed" (editable) with no form and refuses applications; *Hidden* makes the page return 404 and removes it from the header, footer and sitemaps. The state is stored separately from the wording, so "reset wording" never changes it. A header menu saved by hand under Menus overrides the built-in links; add or remove the Hiring link there yourself (a link to `/hiring` is dropped automatically while the page is hidden). Roles, intro, salary note, steps and closed message are edited under Page wording -> Hiring page (one role per line: `Title | Short description`).

**Application form (Hiring and Careers):** every field is required: role, full name, email, phone, experience, availability, skills, portfolio/GitHub link, LinkedIn profile link (must be an `https://…linkedin.com/in|pub|company/…` address), résumé or work sample (file) and the privacy consent. The server enforces this; the page also checks before sending and lists what is missing inside the form (server-side errors are shown inside the form too, so they are never off-screen). Field names, hints and the Experience / Availability lists are editable under Page wording → Hiring page → *Application form*; the "required" part is not switchable. Stored columns: `linkedin_url`, `experience`, `availability` (migration 0021).

## Look and feel

* **Light and dark theme:** a switch in the website header and the dashboard top bar. The first visit follows the device setting (dark if unknown); the choice is saved in the browser (`localStorage`, key `kis-theme`) and applied by `assets/js/theme.js` before the page paints (no flash, CSP-safe: it is a separate file, not inline script). The light palette only changes CSS variables.
* **Motion:** entrance animations on headings, scroll-reveals (zoom / slide / fade with staggered delays), a drifting aurora behind pages, card lift with a pointer-following light, button light-sweep, menu underline, a scroll progress bar. All of it is switched off for visitors who ask their device for reduced motion, and content stays visible if scripts do not run.

## Content rules the code enforces

* Nothing is invented. The only content shipped is the services/FAQ/principles text supplied by the business and five **draft** policies. **Partners, Clients, Portfolio and Openings start empty**; the Team starts with only the founder profile supplied by the business (Durvish Jawale, Founder & CEO), which can be edited or replaced, and the pages say so plainly (or hide the section) instead of showing fake people, logos, clients, testimonials, awards, statistics or prices.
* A **price** is only shown when the editor ticks "Show a starting price".
* Contact details, address and social links appear only when entered; the address needs an extra "Show address" tick.
* **Policies cannot be published while they contain `[ADMIN TO COMPLETE ...]`.** They are starting drafts that need review by the business (and a lawyer); they are not legal advice. Until published they do not exist for visitors, are not linked, and are not in the sitemap.
* Structured data (JSON-LD `Organization`) is emitted only on the home page and only with facts that exist (name, URL, logo, e-mail and `sameAs` links that were entered).

## Permissions

| Permission | Allows |
|---|---|
| `cms.view` | See the Website admin (all roles that can edit content) |
| `cms.edit` | Create and edit **drafts** and page wording (Manager and above) |
| `cms.publish` | Publish, unpublish, archive, delete, edit menus, reset wording (Administrator, Owner) |
| `legal.manage` | Edit and restore policies (Administrator, Owner); publishing a policy needs this **and** `cms.publish` |
| `careers.view` | Open applications and download the résumé |
| `careers.manage` | Change an application's status and internal notes |

Customers, partners and other staff roles get 403 on every `/admin/site*` and `/admin/careers*` address; visitors are sent to sign in.

## Security

* **No raw HTML.** All text is escaped first, then lightly formatted (paragraphs, `##` headings, lists, `**bold**`, `[text](https://...)`). Links are limited to `http(s)`, `mailto:`, `tel:` and site-relative addresses; names/titles containing `<` or `>` are refused.
* **Strict CSP is unchanged** (no inline script or style). The FAQ search, menus and animations live in `public/assets/js/app.js`; JSON-LD is a non-executable data block.
* **Forms**: CSRF token, per-IP rate limit (429), hidden honeypot field, explicit consent checkbox (required), server-side validation of every field, uploads validated by size, extension, real MIME type and file signature. Spam and failed submissions store nothing and accepted files are removed when the database step fails.
* **Uploads are private.** Attachments and résumés are stored under `storage/uploads` with a random name (never in `public_html`) and are served only by an authorised, logged-in staff request as a forced download. The public site never links to them.
* **Public pages that have no form do not start a session** (no cookie for anonymous readers) and are cacheable for a minute.
* Reserved addresses: a CMS page can never shadow `/login`, `/services`, `/privacy-policy`, ... (the router wins; the editor refuses the address).

## Database (migration `0018_public_website.sql`)

New tables: (0019: `site_clients`) `site_sections`, `site_services`, `site_team`, `site_partners`, `site_portfolio`, `site_jobs`, `site_job_applications`, `site_faqs`, `site_policies`, `site_policy_versions`, `enquiry_details`, `enquiry_files`. Migrations `0017` and `0019` (clients) must also be applied. A fresh install already contains everything (`vertex-database.sql`). An existing site is upgraded with `upgrade.php` (see 08-DEPLOYMENT).

## Environment variables

Two optional ones: `UPLOAD_ENQUIRY_MAX_MB` (default 10) and `UPLOAD_CAREER_MAX_MB` (default 5) for visitor attachments and résumés (always capped by PHP's own `upload_max_filesize`/`post_max_size`). `APP_URL` must be the public https address (canonical links, Open Graph and the sitemap are built from it).

## Tests

`tests/Integration/PublicWebsiteTest.php` (23 tests) covers: every public page renders with one `h1`, title, description, canonical and Open Graph tags; starting content and the "nothing invented" rule; drafts/archived items are invisible and absent from the sitemap; markup is escaped; prices stay hidden; policy gating and versioning; admin permissions (visitor, customer, partner, developer, manager, administrator); CSRF; menu and wording edits and reset; contact and requirements forms with extra answers and private attachments; rejected attachments store nothing; job applications, consent, honeypot, executable résumé refused, staff-only download; FAQ search markup, `robots.txt`, `sitemap.xml`; reserved addresses. Run `vendor/bin/phpunit`.

## Known limits

* Policies are drafts for the business and its lawyer to complete; the code cannot know your legal terms.
* Public pages are server-rendered; there is no page cache beyond a 60-second `Cache-Control`.
* No e-mail is sent to visitors who submit a form (the thank-you page says so); staff are shown new enquiries/applications in the admin only unless you configure notifications.
* Images need to be uploaded to the Media library by an editor; no stock pictures are shipped.
