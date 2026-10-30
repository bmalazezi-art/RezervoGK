# M7 Step 3 — Transactional email delivery

Implemented 22 October 2026. **Production sending remains disabled.** No SMTP credentials were supplied or invented, no live email was sent, and the retained `.env`, bank settings, roles and database records are unchanged. The new additive outbox migration was exercised only in isolated tests, not applied to existing databases.

## Implemented behavior

### Password recovery

- Uses Laravel's existing broker, hashed tokens, 60-minute expiry, single use, throttling and canonical `APP_URL`; an incoming Host header cannot redirect recovery links.
- Sends an Albanian HTML email and a plain-text alternative. Includes an expiry explanation, clear reset button, fallback link and warning against sharing the link. Templates use inline styles, a responsive table, local fonts and no tracking/external assets.
- Transactional delivery is explicitly opt-in. Disabled or unsafe configuration produces the same generic response as an unknown address. The log mailer and failover-to-log cannot receive recovery messages through this application path.
- SMTP/configuration failures are caught. Logs contain only a fixed event kind, never exception text/objects, SMTP responses, recipients, credentials, message bodies or tokens. An undelivered token is invalidated so another request can be made; no reset token is put into a queue/outbox or failed-job payload.
- Reset pages use encrypted Inertia history and recovery responses carry `no-store`, `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex, nofollow`. Validation excludes tokens from flashed input. Existing auth rules and website styling remain intact.
- Sending is synchronous and bounded by `MAIL_TIMEOUT` (1–30 seconds). Unknown accounts and failures share the same response text; this is not a claim of constant-time SMTP behavior. Users retry recovery after an outage; old reset emails are not automatically retried after expiry.

### Bank-transfer decisions

- Only the existing authorized bank review flow creates a notification. Approval remains subject to actual bank verification, exact €7 EUR, unique reconciliation reference and existing admin/ownership checks. A draft/pending transfer creates no decision email.
- The existing payment decision, subscription/audit writes and one `payment_email_deliveries` row commit atomically. A repeated same decision returns without another outbox entry or subscription grant. No SMTP connection occurs inside the financial transaction or HTTP decision request.
- Approval email includes the payment reference and the **approved period's** start/end dates, in the configured marketplace timezone. It explains preserved trial/subscription time and possible future start; delayed delivery does not incorrectly promise that access is active now.
- Rejection email explains that no new period was added, directs the keeper to the authenticated dashboard for the reason, and preserves the meaning of any other active trial/period.
- Emails omit receipts, bank-account details, reconciliation IDs, sender notes and internal review notes. They contain only a canonical dashboard link, not a login token or private receipt URL. No attachments are sent.
- Outbox stores a payment ID, decision and delivery bookkeeping, not SMTP credentials, email bodies, reset tokens or bank snapshots. It does not backfill old decisions or change existing records.

## Delivery and retry operations

`php artisan mail:deliver-transfers` processes up to 50 due entries (configurable with `--limit=1..500`). A database row lock and 10-minute reservation prevent two workers from claiming a live delivery. Stale reservations are recoverable. Five attempts are allowed; retries after failures wait 1, 5, 15 and 60 minutes before the next attempt. Exhausted entries remain `failed` for operator investigation.

`php artisan mail:deliver-transfers --retry-failed` explicitly requeues exhausted entries and tries delivery. It never reruns approval, changes subscription dates or alters payment status. Do not repeatedly run this against a permanently failing recipient/provider.

`php artisan mail:status` reports only a configuration issue code and aggregate pending/processing/sent/failed counts; it prints no configuration values or recipient data. The deployment scheduler has a once-per-minute `mail:deliver-transfers` task with overlap prevention. **No system scheduler or production worker was started here.** SMTP outages return failure exit codes and fixed sanitized warning events for monitoring.

SMTP cannot provide a universal exactly-once delivery guarantee: if the provider accepts a message and the process dies before marking the row sent, a later retry can duplicate the email. A timeout after DATA can also be ambiguous. Subscription activation remains exactly once; email delivery is retryable/at least once. A `sent` status means SMTP accepted the message, not proof of inbox placement or reading. Bounces, spam placement and provider complaints require external operational monitoring; there is no new inbound webhook here.

## Configuration and deployment

The actual `.env` was not edited. `.env.example` documents disabled-by-default configuration. Supply these values privately through the deployment environment/secret manager:

| Key | Required setting |
| --- | --- |
| `TRANSACTIONAL_MAIL_ENABLED` | Keep `false` until SMTP, sender and deployment checks are complete; explicitly enable later |
| `MAIL_MAILER` | `smtp`; log, array, failover and other transports are refused outside isolated unit tests |
| `MAIL_HOST`, `MAIL_PORT` | Actual provider endpoint and port |
| `MAIL_SCHEME` | Provider's `smtp` with required STARTTLS, or `smtps` for implicit TLS |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Actual provider credentials, never committed or shared in reports |
| `MAIL_REQUIRE_TLS` | `true`; TLS certificate verification stays enabled |
| `MAIL_TIMEOUT` | 1–30 seconds; default 15 |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Provider-authorized sender/address and display name; placeholder sender addresses are blocked |
| `MAIL_EHLO_DOMAIN` | Optional provider-appropriate domain; defaults from `APP_URL` |
| `APP_URL` | Canonical public HTTPS origin, without credentials/query/fragment |
| `TRANSACTIONAL_MAIL_QA_LOCAL_SMTP` | `false` in deployment; local/testing-only bypass for an explicit loopback sink |

`MAIL_URL` overrides are intentionally refused for transactional delivery; use the discrete environment keys so URL options cannot silently replace the reviewed TLS/auth settings. The local plaintext QA exception is accepted only for loopback SMTP in `local`/`testing`; it is ignored in production. The in-memory array transport exception exists only while running unit tests. Readiness checks validate settings, not the existence or ownership of provider credentials/domains.

Before enabling delivery in a separately approved deployment:

1. Back up the target database and apply the additive `2026_10_22_000001_create_payment_email_deliveries.php` migration. It must accompany the updated review code; older schemas cannot run that new outbox write. Do not roll back/drop an outbox containing needed delivery history.
2. Configure canonical HTTPS, production/debug-off/secure-cookie settings and SMTP/sender secrets. Protect `.env`, cached config, private storage and logs; use PHP's exception argument suppression and redact reset-token paths/query values in reverse-proxy/access/APM logs. Application mail errors are sanitized, but hosting/provider logs are outside this code's control. Historical log-mailer output may contain earlier reset links; restrict and rotate it under the owner's retention policy rather than sharing it.
3. Refresh deployment config caches after setting secrets, verify provider TLS/authentication and sender-domain ownership, and test an owner-authorized mailbox. Configure/verify SPF, DKIM and DMARC with the provider and DNS owner. No DNS/provider changes were performed here.
4. Install the Laravel scheduler invocation in the production process/hosting setup; monitor `mail:status`, command exit codes and fixed warning events. Restore-test the outbox/database backup and define delivery-log retention and incident procedures.
5. Explicitly enable transactional mail only after those checks. **Enabling mail does not enable bank payments or grant admin roles.** Those settings and approvals remain separate.

## Verification

| Check | Result |
| --- | --- |
| Full PHP suite | **188 passed, 2,342 assertions**, 25.75 seconds; SQLite `:memory:` |
| Transactional-email coverage | 23 tests: actual local SMTP receipt, HTML/text, usable reset token, host poisoning, disabled/log refusal, sanitized SMTP rejection, TLS refusal, safe recovery headers/history/flash, decision worker success, private-data omission, idempotency, rollback, additive migration, retry exhaustion/manual retry, lease recovery, production configuration guard and sanitized status |
| Existing regressions | Auth/ownership, private receipt isolation, bank approval/expiry, €7 EUR subscription, country currencies, weekly/date availability and public translations all pass |
| TypeScript | `npm run typecheck` passed |
| Frontend build | Passed; 798 modules, 10.89 seconds |
| PHP formatting | Pint passed |
| Composer metadata | `composer validate --strict` passed |
| Schedule registration | `schedule:list` shows the once-per-minute transfer email task; task not run against retained data |
| Retained DB / `.env` / roles | Read-only all-table fingerprints unchanged against the existing final-audit baseline; `.env` unchanged; live admin count 0 |
| Bank configuration | Still unconfigured/disabled; no bank settings changed |
| Figma stylesheet | Unchanged original CSS fingerprint (`b7947b500d3c8fad440f8e3a6015b82e373decd01339c032732a6112e20ea6bf`) |
| New dependencies | None |

SMTP integration uses a test-only receiver bound to `127.0.0.1` on an ephemeral port. It cannot relay, authenticate to external services or send outbound mail. Raw captured MIME is stored only in a private temporary directory and deleted after each test; real generated reset tokens are not copied to reports/screenshots. Tests use isolated SQLite and test-only users/admins and never modify retained roles/data. Success proves local SMTP acceptance, not real-provider delivery. A TLS-required connection to the plaintext sink is rejected before message delivery, as intended.

Rendered synthetic templates were inspected in the browser at desktop 1280×720 and mobile 390×844. The desktop table measures 600px; mobile content fits the 390px viewport without horizontal overflow. Albanian text, clear action/fallback links, dates and rejection explanation are visible. Only the literal nonfunctional `QA-PREVIEW-NOT-A-VALID-TOKEN` appears in preview evidence; no email links were clicked. Temporary preview server/tab and files were removed and viewport overrides reset. These are browser rendering checks, not Gmail/Outlook/Apple Mail client certification.

Evidence: [reset mobile](qa/m7-step3/reset-mobile.jpg), [reset desktop](qa/m7-step3/reset-desktop.jpg), [approval mobile](qa/m7-step3/approval-mobile.jpg), [rejection mobile](qa/m7-step3/rejection-mobile.jpg).

## Remaining blockers

- **Real provider setup:** credentials, verified sending domain/address, DNS authentication, provider TLS/auth test, actual mailbox receipt/spam/bounce checks and supported email-client/device testing remain unverified. Sending remains disabled until configured.
- **Deployment:** apply the additive outbox migration to the intended database as part of deployment; install/monitor the scheduler, configure production HTTPS/debug/secrets/access-log redaction and verify backup/restore/retention. Existing databases were intentionally not migrated.
- **Earlier launch requirements:** owner-approved policies/operator/support contacts, privacy/refund processes and general production operations remain pending. Live bank details and secure admin provisioning are still absent, as requested. None was invented or enabled.

**MySQL status, separately:** existing MariaDB 10.4.28 read-only access succeeded; no error 1045 recurred. No credentials, users, grants or existing schemas/data were modified. The new migration and delivery workflows were verified on isolated SQLite only; MariaDB/Oracle MySQL migration verification and production deployment remain outstanding.
