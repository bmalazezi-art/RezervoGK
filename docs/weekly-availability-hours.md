# Weekly goalkeeper availability hours

Completed 22 October 2026. Owners can publish indicative weekly hours alongside their existing dated availability. The public Albanian schedule card is inspired by the supplied service-hours reference and reuses the existing profile panels, typography, colors and responsive layout. M7 Step 3 was not started.

## Behavior

- The dashboard has a separate **Orari javor orientues** form. Select weekdays, enter start/end times and save. Unselect a weekday and save to remove it; saving with no selected days clears the weekly schedule.
- Each selected weekday has one interval, in local wall-clock time. Weekdays use ISO ordering, Monday through Sunday. The supported markets currently share the existing marketplace local-time convention; no UTC conversion is applied to these recurring, indicative times.
- An end time earlier than the start means the interval ends on the next day, including Sunday into Monday. The public card explicitly labels it **Përfundon ditën tjetër**.
- Midnight is entered as `00:00`, so `18:00–00:00` means midnight after that evening. `13:00–02:00` runs into the following day. `24:00`, seconds and malformed times are rejected. Equal start/end times are rejected rather than ambiguously meaning either zero or 24 hours. Multiple intervals per weekday and all-day schedules are outside this implementation.
- Public profiles show all seven weekdays once any weekly hours are published. Missing days say **Pa orar të publikuar**, without overriding date-specific availability. Profiles with no weekly hours show a clear empty state.
- The card says the schedule is **orientues**, uses local hours, does not confirm a date/booking and requires direct confirmation with the keeper.

**Dated availability, date search, available-today indicators and eligibility are unchanged.** Weekly hours do not generate dated rows, match a date filter, create a booking, activate an expired profile or extend any trial/subscription. An expired owner can still edit their weekly hours through the protected dashboard.

## Storage and security

The additive migration `2026_10_22_000000_create_goalkeeper_weekly_hours.php` creates a separate table with a profile foreign key, weekday, start/end TIME columns and timestamps. A unique profile/weekday key prevents duplicate days; deleting a profile cascades only its associated weekly records. Existing profile columns, dated rows and demo values are retained. No existing keeper receives a fabricated default schedule.

`PUT /keepers/{keeper}/weekly-hours` uses the existing web CSRF, authentication and session protections, plus an owner-authorized form request. Guests, other keepers and administrators who do not own that profile cannot edit it. Payloads must contain a list of up to seven unique weekdays with required `HH:MM` times. Nested keys are allowlisted; ownership overrides and invalid dates/times are rejected before writes. A transaction locks and reauthorizes the profile, then replaces its weekly schedule atomically; insertion failure restores the previous schedule.

The existing profile resource exposes ordered weekday/time values and a derived overnight flag. Controllers eager-load the schedule with the existing relationships. Subscription, bank configuration, admin role and eligibility code were not changed.

## Verification

| Check | Result |
| --- | --- |
| Full PHP regression suite | **145 passed, 1,904 assertions**, 19.10 seconds; isolated SQLite `:memory:` |
| Weekly-specific coverage | **21 tests**, covering save/replace/clear, midnight and Sunday overnight, invalid/equal times, duplicate/out-of-range days, malformed lists, ownership, injected fields, rollback, expired owners and dated-search independence |
| TypeScript | Passed |
| Production build | Passed, 797 modules; final build after responsive correction: 6.25 seconds |
| PHP formatting | `vendor/bin/pint --test` passed |
| Composer manifest | `composer validate --strict` passed |
| Native local database | Additive migration applied successfully to configured MariaDB and the separate existing QA schema; browser saves verified actual MariaDB TIME persistence |
| Pre-existing rows / roles | All pre-existing application-table fingerprints preserved; existing migration rows retained, with only the new migration record added |
| Bank / admin configuration | `.env` fingerprint unchanged; live bank remains unconfigured/disabled; live admin count remains 0 |
| Original Figma CSS | Byte-identical to ZIP `src/index.css`; no stylesheet edit |

The local database gained only the new table and migration record. Its weekly table remains empty until real owners choose hours. Synthetic browser schedules were saved only in the separate QA schema at port 8007. No existing database/user grants or credentials were changed, and no seeder was rerun.

## Browser QA

Used the existing test-only keeper in the isolated MariaDB preview:

- Selected Monday and Saturday and saved them through the new owner form.
- Edited time fields with native keyboard controls. Reloaded the public profile and verified Monday `18:00–00:00` and Saturday `13:00–02:00`, each with the following-day label.
- Unselected Monday, saved, and verified it became “Pa orar të publikuar” while Saturday remained unchanged. Restored the illustrative two-day QA schedule afterward.
- Confirmed the existing published date remained present. Automated tests additionally prove that weekly hours alone never satisfy a date query, while a dated row continues to match even with no weekly hours.
- Checked the public card and editor at **390 × 844** and **320 × 740**. A confirmed intrinsic-width overflow in the lower profile grid was corrected by allowing its existing child wrappers to shrink (`min-w-0`). The calendar retains its existing internal horizontal scrolling, and the weekly card now fits the available width. Original CSS and breakpoints remain untouched.
- Original owner form, dated calendar, contact actions and profile eligibility remain available. No payments, role changes or bank configuration changes were performed during QA.

Evidence: [mobile public schedule](qa/weekly-hours/public-mobile.jpg). The image shows synthetic QA information. Physical-device/Safari testing remains unverified. Browser-native time-picker appearance follows the browser/OS language.

## Deployment and remaining limits

Run the additive migration on each further deployment before serving this version. Do not use `migrate:fresh` or rerun demo seeders against retained data. Only one time interval per weekday is supported; equal times do not represent all-day availability. These weekly hours are indicative and are deliberately separate from date-based search.

**MySQL status:** no 1045 error occurred on local MariaDB 10.4.28. Oracle MySQL 8.4 remains unverified. The general launch blockers documented in [M7 Step 2](milestone-7-step-2.md)—owner-approved policies/contact details, production HTTPS/email, authorized bank/admin setup and operational verification—remain outstanding. The draft policies should be reviewed to include indicative weekly hours before launch.
