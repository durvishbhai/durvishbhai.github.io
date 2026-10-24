# 11 · Phase 3: deliverables, secure files, revisions and client approvals

Status: **built and tested** (see "Test results"). Phase 4 has **not** been started. Phases 0-2 are unchanged except for the additive items listed under "What changed outside the new modules".

## What exists

| Area | Capability |
|---|---|
| **Deliverables** | Unique code `DLV-YYYY-NNNN`, project, title, description, category (Video, Thumbnail, Graphic Design, Website Files, Document, Source Files, Other), optional task and milestone **of the same project**, creator, timestamps, first-publication time. Edit details, archive/restore. Per-project list, global list (search, filters, pagination), QA queue. |
| **Content** | Each version is **either an uploaded file or an https link** to an external resource (never fetched by the server; no IPs, localhost, internal hosts or embedded logins; optional host allow-list). |
| **Visibility** | Version lifecycle `draft → pending_review → approved_internal → published → superseded / withdrawn` (`changes_requested` loops back). The deliverable shows the explicit states Draft, Pending Review, Approved Internally, Published to Client, Archived. **Upload never publishes.** Publishing needs an internally approved version and `deliverables.publish`. |
| **Versions** | V1, V2, V3 … plus an optional label ("Final"). A new upload is always a new row; nothing is overwritten or deleted. Publishing a version makes the earlier live one `superseded` (kept, downloadable by the client as an earlier version). Staff may re-publish an earlier approved version (designate the client-facing one) and withdraw a published version with a reason. Metadata comparison page. The current client version is clearly marked. |
| **Internal QA** | Review queue; approve internally or send back with notes (internal only). The uploader cannot approve their own upload (configurable). Internal approval is **not** client acceptance. |
| **Client portal** | "My deliverables": projects the client owns, published deliverables, descriptions, publication dates, download, version history, status (Ready for your review / Revision requested / Approved / Rejected / Archived, or "No deliverables published yet"), revision requests and their status, their own approval history. |
| **Client approval** | Approve or reject (reason required) the **current published version**, after a confirmation screen, with a one-time form token. Append-only record: client identity, exact version, action, feedback, time, revision link. Approval never carries to another version; stale pages are refused; viewing/downloading records nothing. |
| **Revisions** | Client submits against the current published version (text, up to 3 attachments). 11 statuses with an explicit transition table (`config/workflow.php`), every move recorded in `revision_status_history` and audited. Reviewer assignment, internal notes, a client-visible response, resolution text, completion timestamp, "ready for review" can point at the new version. Clients see friendly status labels and only client-visible history. |
| **Included vs chargeable** | Revision policy foundation (default / per service / per project): optional allowance, written terms, quotation or contract reference, versioned and audited. **Nothing is shipped: no allowance is assumed anywhere.** Classification always needs a reason; if a numeric allowance exists, contradicting it needs an administrator override (audited). |
| **Chargeable change** | Classifying as chargeable creates a **draft** change request (scope, client-facing reason, extra cost in paise, days/date, snapshot of the original approved quotation/price). Staff send it; the **client** approves or declines on a dedicated page. Work cannot start before approval. Nothing is charged, invoiced, or added to the approved price. |
| **Notifications** | In-app events for: internal review required, deliverable published, revised version published, client revision received, revision classified potentially chargeable, additional cost awaiting approval, client approval/rejection, cost decision, assignment and status updates. `/notifications`, unread count in the navigation, mark read. Deduplicated per user and event. **No email is sent and no row claims one was** (`email_status` stays `not_sent`). |

## File storage and configuration

* **Location:** `<app root>/storage/uploads/YYYY/MM/<40 random hex characters>`: outside the web root (the document root is `public/`). `storage/.htaccess` additionally denies access and disables script handlers for hosts that serve the whole folder. Directory 0750, files 0640.
* **No public or permanent links.** There is no route that takes a file id or a path. A download is `/deliverables/{deliverable}/versions/{version}/download` (or `/revisions/{request}/files/{file}`), authorised for the current user on every request.
* **Configuration:** `config/uploads.php` and `.env`: `UPLOAD_MAX_MB` (default 50), `UPLOAD_REVISION_MAX_MB` (10), `UPLOAD_SCANNER` (`none` | `command`), `UPLOAD_SCANNER_COMMAND`, `UPLOAD_SCANNER_ARGS`, `DELIVERABLE_LINK_HOSTS`, `DELIVERABLE_ALLOW_SELF_APPROVAL`. **PHP's `upload_max_filesize` and `post_max_size` must be raised to at least the application limit** (the UI shows the effective limit, the smaller of the two). Large videos beyond that need a different transfer method (resumable/chunked upload is not built, see limitations).
* **Allowed types:** mp4, mov, webm, mkv, mp3, wav, png, jpg/jpeg, gif, webp, psd, pdf, docx, xlsx, pptx, zip, txt, md, csv. Extension, **detected MIME type** and **signature (magic bytes)** must all agree. SVG, HTML, scripts and executables are never accepted, and executable-looking extensions are refused even as an inner part of a name (`logo.php.png`). Archives are accepted only as stored source files; they are never extracted or executed and are served as attachments.

## Security controls

1. Server-side permission checks on every route, service method and data query; routes' `can:` is only the first gate. Unknown and forbidden ids both give 404.
2. Draft/unpublished data is removed at the **data layer** for clients (`versionsFor`, `RevisionAccess::forClient`, `RevisionQuery`), not just hidden in templates; staff and client use different templates.
3. Downloads: authorised per version, `files.download` required, rate limit (`download_user`, 200/hour/user), audit entry per successful download, `Content-Type: application/octet-stream`, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, no storage path in any response or log.
4. Upload: size cap, allow-list, MIME + signature, random names, sanitised display names (path parts, control and bidi characters removed, length bounded), refused files leave nothing in storage, upload rate limit, CSRF on every POST.
5. Malware scanner integration point (`command` driver, fail-closed). With no scanner the file is recorded `not_scanned`; the UI and database never say "clean" unless a scanner ran and passed.
6. Transactions with row locks for every multi-step state change; stale pages are refused; idempotency tokens for client submissions; unique `(user, dedupe key)` for notifications.
7. History is never deleted: all foreign keys into projects, deliverables, versions, revisions, files and price tables are `RESTRICT`; archiving instead of deleting; the new code never writes the price tables or the approved quotation baseline (a test scans the source).
8. Audit (hash chained): uploads, version creation, submission, internal approval/changes, publication, withdrawal, archive/restore, downloads, client approval/rejection, revision submission/classification/override/assignment/status changes, cost proposals and decisions, policy changes. The log stores ids and field names, not file paths, free text or secrets.

## Migration (forward-only)

`0008_deliverables_revisions.sql`: adds `stored_files`, `deliverables`, `deliverable_versions`, `deliverable_reviews`, `client_approvals`, `revision_policies`, `revision_requests`, `revision_files`, `revision_status_history`, `revision_notes`, `change_requests`, `notifications`. **Additive only**; no existing table is altered. Rollback policy as before: restore the pre-upgrade backup (taking one before `migrate` is mandatory), do not hand-edit applied migrations.

## What changed outside the new modules

`config/permissions.php` (8 new permissions, defaults below), `config/workflow.php` (vocabularies and the revision machine), `config/auth.php` (rate limits), `CrmReference` (3 new reference scopes), layout navigation, project tabs (Deliverables tab), CSS/JS (badges, timeline, a busy state for uploads), `.env.example`, `storage/.htaccess`, test base class (new tables truncated, stored files removed).

New permissions: `deliverables.view_drafts`, `.review`, `.archive`, `.respond`; `revisions.classify`, `.override` (sensitive), `.policy` (sensitive), `.respond_cost`. Defaults: workers get `view_drafts` only (so they can upload and see their drafts but never publish); managers get `view_drafts`, `review`, `archive`, `classify`; administrators get everything except the client-only `respond`, `respond_cost`, `revisions.request`; clients get `respond` and `respond_cost`; referral partners and finance staff get nothing. Existing installs receive new permissions by `permissions:sync` without losing role edits.

## Setup / upgrade

```bash
git pull && composer install --no-dev
# BACK UP THE DATABASE FIRST
php bin/console.php migrate            # 0008
php bin/console.php permissions:sync
php bin/console.php doctor             # storage/uploads must be writable, NOT web-served
# php.ini / .user.ini: upload_max_filesize and post_max_size >= UPLOAD_MAX_MB
```

## Manual testing

1. Admin: Projects → a project → **Deliverables** → upload a PNG as **draft**. Confirm the client account sees nothing (`/my/deliverables`: "No deliverables published yet").
2. As the uploader: **Submit for internal review**. As an Administrator/Manager (not the uploader): **QA queue** → approve internally. Still invisible to the client.
3. **Publish to client**. The client gets a notification ("ready for your review") and sees it; download works; approving has **not** happened.
4. Client: open the deliverable → **Approve this version** → confirmation screen → approve. Staff see the response. Reload/resubmit: still one record.
5. Upload **V2** (new version, draft) → submit/approve/publish. The client sees V2 as current, V1 as an earlier version, and V2 is **awaiting approval** (V1's approval does not carry over). Try the old confirmation page: refused.
6. Try forbidden uploads (`.php`, `.exe` renamed to `.png`, `logo.php.png`, an oversized file): refused with a message and nothing stored. Try `/storage/uploads/...` URLs: 404.
7. Client: **Request a revision** (add an attachment). Staff (support/manager): `/revisions` → open → **Under review**.
8. Admin: `/admin/revision-policies` → record "Default: 2 included revisions, QT-…" (password). Manager classifies a third request as *included* → refused; as *chargeable* → draft proposal (amount, scope, reason). The client sees nothing yet.
9. Manager: open the proposal → **Send to client**. Client: `/changes/…` → read scope/price/timeline → **Approve** (or Decline). Staff cannot approve; the revision can only start after approval. Nothing is invoiced.
10. Check `/notifications` for each role, and the audit log for every step.

## Test results

See the pull request / final report for the exact counts of the last full run. Phase 3 suites: `FileStoreTest`, `DeliverableServiceTest`, `ClientApprovalTest`, `RevisionServiceTest`, `DeliverableHttpTest`, `RevisionHttpTest` (+ updated upgrade tests and test base class). Key guards were mutation-checked; browser checks were run in Chromium.

## Known limitations and unresolved dependencies

* **Quotations/invoices do not exist yet (D-01).** A change request is a *pending record*; an approved one is the client's recorded consent. Turning it into a quotation revision, invoice and payment is Phase 4/5 work and must go through those modules' own approval rules (`change_requests.quotation_ref` is the pointer). The original approved price is never edited.
* **No email/WhatsApp.** Notifications are in-app only.
* **No virus scanner is installed in the build/hosting environment**: files are recorded as *not scanned*; configure `UPLOAD_SCANNER=command` (e.g. ClamAV) to enable scanning.
* No resumable/chunked uploads, no in-browser preview/streaming of video (files are downloads), no thumbnails, no per-file expiry links, no bulk download, no file deletion UI (files are retained; archive the deliverable instead).
* The client can request revisions on an already approved version (the classification policy then decides); a client cannot withdraw an approval.
* Staff cannot record a client's offline approval of a deliverable or an extra cost on their behalf (open question D-35).
* Revision "Awaiting internal review" is a staff-side state; clients see only "No deliverables published yet" or published work, so drafts never leak.
* PHPStan is still not installable here; static checks are `php -l`, the closure-scope guard and the tests.
