# Dashboard photo and weekly-hours fixes

Completed 22 October 2026. This is a scoped dashboard fix, not a new milestone. No database migration, reset, reseeding, subscription/bank/email change or role promotion was performed.

## Diagnosis

- Selecting a photo already generated a browser-only object URL, but there was no dedicated save action. Uploading depended on the small profile-header button and validation of every profile field. An unsaved preview naturally vanished on refresh. The retained owner's profile initially had no `photo_path`; the public storage symlink was valid. No missing/deleted saved image was established in that initial state.
- PHP's effective default was **`upload_max_filesize=2M`**, despite the advertised 5MB application allowance. This was a real transport-level upload blocker. `post_max_size` was 8M.
- Managed images also relied on a persisted absolute `photo_url`. A reproduced regression showed that an image with a valid stored path but an old preview host/port could be displayed through the stale URL after moving deployments.
- Weekly inputs used generic fieldsets and form grids without dashboard-specific row/control styling. Global flash messages could appear in unrelated cards.

## Changes

### Photo editing

- Added an independent dashboard photo card with immediate preview, explicit **“Ruaj fotografinë”**, unsaved-preview label, selected filename, loading/progress, inline success/validation/network feedback, and explicit save-to-remove control. Inputs/buttons lock during upload; blob URLs are released when replaced/unmounted.
- Photo saves no longer validate or submit unrelated profile fields. Browser QA deliberately emptied the name draft, then successfully saved a photo without changing the persisted name.
- New owner-only, CSRF-protected, throttled `POST /keepers/{keeper}/photo` route. Uses the existing JPG/PNG, 5MB, 3000×3000 validation and GD re-encoding/metadata stripping. File URLs/paths and owner IDs cannot be supplied by clients.
- Photo-only persistence locks and reauthorizes the profile, updates only photo columns, removes a new upload on database failure, and preserves the existing photo on failure. Replacements/removals clean up generated old paths only. Storage/database errors return a safe photo validation message. A failed post-commit cleanup does not falsely report that a committed photo save failed.
- Public resources resolve managed photo paths using the current public storage configuration, so dashboard, home/browse cards and public profiles agree without rewriting existing stored data. Unmanaged legacy/demo URLs keep their existing fallback behavior. New upload UUIDs avoid stale cached images.
- Photo and weekly success feedback is scoped to each editor, avoiding misleading messages in the profile/payment forms. Ineligible owners can still edit; uploading does not publish an expired profile.

### Upload limits

- `tools/php/uploads.ini`: project-scoped 5M upload / 8M POST limits.
- `bin/serve`: preserves the host's existing ini scan directory, adds the project override, and starts Laravel with `--no-reload` so the child server inherits it. `composer dev` now uses this launcher. No global PHP ini was edited.
- `public/.user.ini`: the corresponding per-site PHP-FPM/CGI settings. PHP CLI ignores this file, which is why the explicit launcher is needed.
- The isolated port-8008 server was restarted with the project ini override and the same disposable SQLite/configuration. A real **2,645,153-byte PNG (1100×800)** was uploaded, re-encoded, saved, loaded at its full natural dimensions and verified again after refresh. This confirms uploads above the former 2M limit work through HTTP, rather than only via Laravel's fake uploads.

For local development use:

```sh
sh bin/serve --host=127.0.0.1 --port=8000
# Or: composer dev
```

An already-running server must be restarted using the launcher; bare `php artisan serve` can still inherit the host's 2M default. Other running servers were not restarted. Production PHP-FPM/CGI must honor the per-site settings, or the hosting owner must apply equivalent pool/vhost settings. Apache module/proxy body limits remain deployment configuration; allow the 5MB file plus multipart overhead. Keep the public-storage link and canonical public-disk URL configured for the deployment.

### Weekly editor

- Added compact weekday rows inside the existing dashboard panel/header system: accessible checkboxes, paired labelled native time inputs, muted disabled states, overnight hint and a clear green Save button.
- At 320/390px each day occupies one label row with two time controls below, avoiding narrow/clipped native inputs. Desktop keeps the weekday and both time inputs on one row.
- Added save/loading/validation feedback. Existing 00:00, overnight, equal-time rejection, day removal and clear-all semantics remain unchanged. Public weekly schedule, dated availability, search filters and server validation rules are unchanged.
- New styling lives in `resources/css/dashboard-editors.css` and is limited to dashboard photo/weekly editor selectors plus the necessary full-width profile-fields adjustment after moving photo editing to its own card. The original Figma `resources/css/app.css` is unchanged.

## Verification

| Check | Result |
| --- | --- |
| Full PHP suite | **201 passed, 2,456 assertions**, 31.06 seconds; SQLite `:memory:` |
| Added photo regressions | 13 cases: independent persistence/public surfaces, stale-origin resolution, replacement/removal, invalid type/size/dimensions/conflicting inputs, ownership, DB rollback and storage failure |
| Existing coverage | Weekly/date separation and overnight validation, currencies/scoped filters, auth/eligibility, private receipts, subscriptions and transactional email regressions pass |
| TypeScript | Passed |
| Final build | Passed; 800 modules, 6.22 seconds |
| Pint / Composer | Passed; `composer validate --strict` also passed after updating the dev launcher |
| Shell / runtime settings | `sh -n bin/serve` passed; project-scoped PHP reports 5M upload / 8M POST with GD and PDO MySQL still loaded |
| CSS / environment | Original Figma stylesheet and actual `.env` fingerprints unchanged |
| Original public files | Existing file hashes remain unchanged; QA-generated replacements were managed only through the owner photo endpoint |
| Dependencies / migrations | No new dependencies or migrations |

## Browser QA

Used the existing disposable SQLite preview at **127.0.0.1:8008**, with its synthetic non-admin keeper and generated test images. No real keeper account was edited by the agent, no external messages were sent, and payments were not enabled.

Desktop 1280px (900/1100px height), 320×740 and 390×844:

- Selected image immediately appeared as a loaded `blob:` preview with an unsaved label. Save showed disabled controls and progress, followed by local success. Refresh showed a loaded stored `/storage/keeper-photos/…` image, not a blob URL.
- Photo saved independently with an empty unsaved profile-name draft; refresh restored the unchanged persisted name. The current price remained **1200.75 ALL**.
- Invalid text file was rejected before upload. A valid PNG wider than 3000px produced Albanian server validation. Refresh after rejection retained the prior saved image. Automated tests additionally cover storage/DB failure, removal, replacement and unauthorized uploads.
- Uploaded a real 2.65MB valid PNG after loading the scoped PHP limits; it persisted through refresh at natural width 1100. Restored the clearly labelled small synthetic QA image afterward.
- Logged out and checked home/browse cards and the direct public profile as a guest. They used the same stored photo URL and loaded the image. Public-profile refresh retained it. Contact links were inspected without dialing/messaging.
- Saved Monday 18:00–00:00 and Saturday 13:00–02:00; overnight hints remained visible. At 320px, equal start/end times produced the correct inline error; corrected overnight hours saved. At 390px, removing a weekday persisted through refresh, and restoring it saved correctly. The public schedule reflected both overnight rows.
- The page width equalled 320/390px, and time inputs stayed inside the card (116px per input at 320px). Existing dated-availability controls retained their own internal horizontal scroll.

Evidence: [photo success, mobile](qa/dashboard-editors/photo-saved-390.jpg), [photo validation, 320px](qa/dashboard-editors/photo-error-320.jpg), [weekly 320px](qa/dashboard-editors/weekly-320.jpg), [weekly 390px](qa/dashboard-editors/weekly-390.jpg), [weekly desktop](qa/dashboard-editors/weekly-desktop.jpg), [public photo](qa/dashboard-editors/public-photo-390.jpg), [public overnight schedule](qa/dashboard-editors/public-weekly-390.jpg). Synthetic data only. Viewport overrides were reset. Physical-device/browser-native control variation remains unverified.

## Data preservation and limitations

All agent database mutations were limited to isolated tests and the known SQLite QA preview. QA table fingerprints confirm dated availability, locations, contacts, formats, payments, subscriptions, audits and migrations were untouched; photo/weekly rows changed as tested, and logout/login rotated an authentication token. Currency, price and trial/access state were preserved.

The retained MariaDB database was accessed only with read-only diagnostic/fingerprint queries. Its fingerprint matched initially, then the retained **profile and dated-availability tables changed during the session outside the agent's SQLite QA workflow** (the retained photo URL belongs to port 8000; QA belongs to 8008). Those concurrent changes were left intact rather than restored to a stale baseline. Other retained table fingerprints, including users/roles, subscriptions and payment/audit data, still match. Therefore this report does not claim a completely unchanged retained database fingerprint.

MariaDB read access succeeded without error 1045. No database credential, grant, role, bank setting or SMTP setting was changed. Existing M7 deployment/email/policy blockers remain as documented in their reports; this fix does not start another milestone. The actual app/web server must load the scoped upload settings on restart for the advertised 5MB limit to apply.
