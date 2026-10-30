# M4 — Goalkeeper profile and availability management

Completed 21 October 2026. The owner dashboard now persists bio, experience, languages, euro price, match formats, separate phone and WhatsApp numbers, and profile photos. Public browsing/contact remains account-free. Payments and subscriptions are outside this milestone.

## Data and access

Migration `2026_10_21_000000_add_profile_photos_and_dated_availability.php` adds nullable managed `photo_path` and `goalkeeper_availabilities` with a profile foreign key, cascade delete, date index and unique profile/date pair. Existing profile values are preserved; old mock availability labels are not converted into invented dates.

`POST /keepers/{keeper}/availability` saves a date idempotently. `DELETE /keepers/{keeper}/availability/{date}` removes that owner's date idempotently. Both require an authenticated session, CSRF and the ownership policy. Profile updates use the same policy and lock/recheck ownership within the transaction. Inputs cannot assign ownership, verification fields or arbitrary photo paths/URLs.

Publishing accepts valid dates from today through one year ahead, using `config/marketplace.php` (Europe/Belgrade). Old dates can still be removed via the endpoint. Only upcoming dates are displayed. A saved date means available for direct inquiries; an absent date means **unconfirmed**, not busy. There are no recurring schedules, time slots, reservations or automatic conflict management.

Public profile calendars and availability labels use those records. Home and browse date search, combined city/price/format filters and the available-today toggle use the same exact-date database lookup. A date selected in browse carries into the profile's public week. Home featured cards now come from the database.

## Photo handling

JPEG/PNG only; input and processed output are limited to 5 MB, input dimensions to 3000 × 3000. GD decodes and re-encodes uploads, stripping metadata and appended payloads. UUID filenames are generated server-side. GD is a required Composer platform extension; EXIF is optional for orientation correction. Public storage requires `php artisan storage:link`.

New files are cleaned up if a database transaction fails. Replaced/removed managed files are deleted after a successful commit. Deletion only accepts generated managed paths, never a path inferred from a public URL. Earlier photos without a managed `photo_path` are preserved on disk rather than risking deletion of unrelated assets; they may need a separately reviewed cleanup. Photo files are public by design.

## Checks

Final continuation reran all checks on 21 October 2026: 83 tests / 866 assertions in 38.52 seconds, TypeScript, production build (18 seconds), Pint and strict Composer validation all passed. The completed QA found no further confirmed issue requiring code changes. This final continuation changed documentation/build artifacts only, restarted the isolated preview, and did not modify any existing database or credentials. M5 was not started.

- `php artisan test`: **83 passed, 866 assertions**, isolated SQLite `:memory:` database.
- `npm run typecheck`: passed.
- `npm run build`: passed (Vite 7.3.7, 792 modules).
- `vendor/bin/pint --test`: passed.
- `composer validate --strict`: passed.
- Original `resources/css/app.css` is byte-identical to ZIP `src/index.css`; SHA-256 `b7947b500d3c8fad440f8e3a6015b82e373decd01339c032732a6112e20ea6bf`.

Tests cover all editable fields and invalid values, cross-owner/unclaimed profile rejection, guest protection, multipart method spoofing, JPEG/PNG processing, malicious/oversized images, photo replacement/removal and rollback cleanup, availability uniqueness/removal/cascade, real date filtering combined with other filters, inclusive euro-cent boundaries, date validation and local midnight behavior. Existing M2/M3 regression tests pass.

## Browser QA

Used a NEW disposable `database/m4-preview.sqlite`, with a synthetic owner and synthetic PNG. M2/M3 databases and MySQL were untouched. Preview runs at http://127.0.0.1:8003 with a dedicated session cookie and array mailer, without editing `.env`.

Verified through real browser forms:

1. Signed in and edited price to €7.25, bio, eight years of experience, three languages, two formats and a separate WhatsApp number. Public profile showed the persisted values and distinct contact links.
2. Uploaded the PNG through the file picker; saved public image loaded successfully. Removed the photo, saved and verified the placeholder; uploaded again successfully.
3. Saved 28 October 2026, outside the seeded dates. Public calendar marked it available. Home city/date search found only that keeper; opening the result preserved the selected date.
4. Removed that date through the mobile dashboard. The same date search returned zero results. Saved it again to leave a usable demo.
5. Checked 390 × 844 mobile browse/filter dialog, profile/calendar and dashboard; applied 7v7 through the mobile dialog. Checked dashboard at 768 × 1024 and public profile/calendar at 1280 × 720. No page horizontal overflow was found at the measured mobile/tablet widths; the existing week strip scrolls within its container. Restored the browser viewport afterward.
6. Browser console had no captured errors or warnings in this QA tab.
7. After restarting the isolated preview, verified the saved profile, contact links, photo and dated public calendar as a guest without an account. This final read-only browser check did not change the preview database.

Evidence: [public profile](qa/m4/public-profile.png), [public calendar](qa/m4/public-availability.png), [mobile dashboard](qa/m4/mobile-dashboard.png), [tablet dashboard](qa/m4/tablet-dashboard.png). All contact details in these QA artifacts are synthetic. Authorization/security edge cases are covered by HTTP tests; browser QA exercised the owner flow.

## MySQL status — separate limitation

Read-only connection probes still return **1045**, both with and without a schema selected. No credentials, grants, schemas or existing databases were altered. The cause cannot be narrowed beyond account authentication/host grants without administrator access. M4's MySQL migration execution and runtime behavior are **not verified**. Migration and functional coverage passed on isolated SQLite only. Obtain approval for the intended database and credentials before applying migrations there; do not use migrate:fresh or rerun demo seeders on existing work.

The existing default MySQL preview can therefore still fail until its access is resolved and the approved migration is applied. Use port 8003 for this isolated M4 preview. Production SMTP delivery remains unverified from M3. Sorting, favorites, real reviews and marketing/verification claims remain existing prototype areas, outside M4.
