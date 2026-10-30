# RezervoGk — SQ/EN localization

Completed 23 October 2026. Albanian remains the default. English is available throughout the application without changing profile content, pricing rules, availability/search rules or subscription eligibility.

## Language selection

The implementation follows the actual BizNis project in `/Users/maxtontech/Desktop/Sales`: a validated preference endpoint, an encrypted persistent cookie and a reactive frontend translation store. The navbar's SQ/EN buttons update text immediately without an Inertia navigation or component remount. Unsaved fields, form steps, selected photos/receipts and review notes remain intact.

`POST /locale` accepts only `sq` or `en`, requires CSRF, is throttled and returns an HttpOnly, SameSite=Lax cookie lasting one year (Secure over HTTPS). It is independent of authentication sessions, so login/session rotation, logout, navigation and refresh preserve the selection. Missing/invalid cookies select SQ. Recovery links include a whitelisted language on the existing canonical reset URL. Invalid locale parameters cannot choose a different language or origin.

Laravel receives the same locale before validation, rendering and notifications. A small global middleware authenticates only the language cookie for errors occurring before the web route middleware, preserving 404/405/CSRF behavior. No locale routes bypass authentication, ownership or payment authorization.

## Coverage and content boundaries

- Public home, browse, cards, profiles, pricing, navigation/footer, help, FAQ, contact, draft Privacy Policy and Terms.
- Registration steps, login/logout, password recovery/reset, validation errors and flash messages.
- Dashboard/profile/photo controls, weekly hours (including overnight notices), dated availability and calendar labels.
- Trial/subscription expiry, manual bank instructions/submission/history, status messages, admin review and audit labels.
- Password reset and approval/rejection emails, HTML and plain text, including subjects and actions.

The English catalog contains 612 source messages, with matching SQ source keys plus existing Laravel framework translations. Server validation dictionaries cover the same rules and field names in both languages. Error/status text already on screen also changes language without resubmitting a form; interpolation uses specific templates and numeric placeholder constraints.

Names, biographies, profile language entries, city names, uploaded filenames, payment/review notes, references and actual bank values are rendered unchanged. Only curated country labels and application text are translated. The draft privacy notice now accurately describes the preference cookie and bank-notification language audit entry; both policy versions retain explicit owner-review warnings.

## Dates, money and email language

Application date labels use `DD.MM.YYYY` in SQ and `DD/MM/YYYY` in EN; weekday labels follow the selected language while retaining the calendar's uppercase presentation. Timestamp displays retain the `Europe/Belgrade` timezone. Native date/time input controls retain ISO values and the browser/operating system's own picker presentation; the application does not replace these native controls.

Profile prices and price-filter labels use the selected locale with the stored ISO currency. EUR remains EUR, ALL remains ALL and MKD remains MKD. Fractional amounts retain two decimal places; integer amounts may omit `.00`. No conversion, cross-currency comparison, currency permissions or price parsing rules were introduced. The subscription remains exactly EUR 7 for 2,592,000 seconds (30 days).

Browser QA uncovered missing Albanian Intl locale data in the browser. Explicit SQ dates/weekdays and a tested currency fallback prevent English weekday names or US dates/numbers. The fallback follows [Unicode CLDR Albanian formatting](https://www.unicode.org/cldr/charts/47/summary/sq.html) and the existing Carbon SQ weekday data: decimal comma, currency suffix and nonbreaking-space grouping.

Password-reset notifications use the request language. New bank submissions capture the goalkeeper's language in the existing immutable `submitted` audit metadata; the outbox worker uses that language rather than the administrator's/worker's language. Older submissions without that metadata default to SQ. Existing audits/accounts are not rewritten and no schema migration is added. SMTP safety gates, canonical URLs, token handling, private receipt isolation and delivery idempotency remain intact.

## Verification

| Check | Result |
| --- | --- |
| `php artisan test --compact` | **217 passed, 2,980 assertions**, isolated in-memory SQLite |
| `npm run test:localization` | **7 passed**; catalog/placeholder coverage, dynamic validation, dates/currency, unchanged user content, missing-SQ-Intl simulation |
| `npm run typecheck` | Passed |
| `npm run build` | Passed; nonblocking main-chunk size warning, about 508 kB before gzip |
| Laravel Pint on changed PHP files | Passed |
| Local SMTP delivery | SQ and EN reset + approval + rejection delivered as HTML/plain text to an isolated loopback sink; usable canonical reset token, no private bank/receipt/review-note disclosure; repeated decision delivery skipped |
| Public/auth mobile browser matrix | **52 checks, no overflow/language failures**: 13 routes × SQ/EN × 320/390px |
| Additional browser QA | Desktop navigation, refresh, login/logout, registration steps, localized errors/flash messages, disabled submissions, currency-scoped search, keeper/admin screens and authorization, audit text and QA-only rejection |
| Form-state preservation | Unsaved Albanian bio, selected photo/preview, registration draft and admin note preserved on switching |
| Weekly availability | Saved an additional weekday in isolated QA and verified after refresh; overnight labels retained and published dates unchanged |

Browser fixtures used a new private SQLite database and synthetic owner/admin accounts. One QA process kept bank submissions disabled; a second loopback-only process used explicitly dummy bank details to inspect/submit the form. Both temporary QA servers were stopped after verification. No actual transfer or external email was sent. Admin decisions/delivery/extension/idempotency remain covered by the regression suite. Raw email tokens and private SMTP captures were not copied into this report or screenshots.

Evidence: [browser matrix](/Users/maxtontech/Desktop/RezervoGk/docs/qa/localization/browser-matrix.json), [English desktop home](/Users/maxtontech/Desktop/RezervoGk/docs/qa/localization/home-en-desktop.png), [SQ public schedule at 390px](/Users/maxtontech/Desktop/RezervoGk/docs/qa/localization/profile-sq-390.png), [EN dashboard at 390px](/Users/maxtontech/Desktop/RezervoGk/docs/qa/localization/dashboard-en-390.png), [SQ dashboard at 320px](/Users/maxtontech/Desktop/RezervoGk/docs/qa/localization/dashboard-sq-320.png), [EN admin](/Users/maxtontech/Desktop/RezervoGk/docs/qa/localization/admin-en-desktop.png), [SQ admin at 390px](/Users/maxtontech/Desktop/RezervoGk/docs/qa/localization/admin-sq-390.png).

## Preservation and remaining launch work

The retained database is reachable for read-only verification and its full table fingerprints match the pre-change baseline. It still contains one account, five profiles, zero admins, zero payments and zero subscriptions. No migrations or seeders ran against it. Existing public files from the earlier private asset baseline also match their hashes.

`.env`, the original Figma `app.css` and dashboard-editor CSS are unchanged. Only scoped language-button styles were added to the existing header stylesheet. No dependencies, database schema, bank settings or account roles were changed. Live bank submissions and transactional sending remain disabled.

Remaining work is independent of localization: configure/verify real SMTP and the canonical HTTPS origin; approve the draft policies/support/operator details; perform the already pending email-outbox migration through the approved backup/deployment process; assign an admin securely and configure real bank details only when authorized. The previously recorded MariaDB 1558 system-catalog/version mismatch and production PHP/database patch requirements remain as documented in the deployment reports; no repair, upgrade or deployment was attempted here.
