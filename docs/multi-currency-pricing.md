# Country-based goalkeeper match pricing

Completed 22 October 2026. Goalkeepers can select the permitted currency for their profile country. Public cards, profiles, owner forms, WhatsApp inquiry text and price filters identify that currency. **Platform access remains €7 EUR for exactly 30 days.** No FX conversion, gateway, bank enablement or admin-role changes were introduced.

## Country rules

| Profile country | Default for new profiles | Permitted currencies |
| --- | --- | --- |
| Kosovo (`XK`) | EUR | EUR |
| Albania (`AL`) | ALL | ALL, EUR |
| North Macedonia (`MK`) | MKD | MKD, EUR |

The server derives the profile country from the validated city relationship, not a client-supplied currency policy. The same server policy supplies currency choices/defaults to country and city options in the frontend. Invalid, unsupported, lowercase or country-incompatible codes are rejected. Profile ownership, location validation and protected write routes remain in effect.

New registration without an explicit currency uses the country default. Editing a legacy EUR profile without a currency retains EUR. An existing ALL/MKD profile cannot be moved to a country that forbids that currency without an explicit valid selection. In owner forms, changing country or currency clears the price for deliberate re-entry; nothing is automatically converted or relabeled. Changing cities inside the same country preserves the current permitted currency and price.

## Exact storage and preservation

The existing schema already contains `goalkeeper_profiles.price_cents` and `currency CHAR(3)`, so **no migration or backfill was needed**. The historical `price_cents` column now represents integer minor units of the accompanying ISO code; all three supported currencies use a scale of 100. For example, `1200.50 ALL` is stored as `120050` and `ALL`. Decimal input is validated to at most two places and converted using integer/string arithmetic, without floating-point amount calculations.

Public payloads include `priceAmount` as a two-decimal string, `priceMinor` as an integer and `currency` as the ISO code. The legacy numeric `price` field remains for compatibility. Frontend price labels and editing use the exact decimal string. Whole EUR prices retain their familiar `€10` appearance; local prices display explicit ISO codes, such as `1200.50 ALL` and `900 MKD`. Fractional prices preserve two decimal places. Inquiry messages use the same currency-aware label.

Existing EUR amounts/codes, reference data, weekly hours, dated availability and subscription records were not migrated or repriced. No migration, seeder, database/user-grant change or data-write command targeted the configured application database or the earlier QA database. A new disposable SQLite database was created for this feature's browser QA. The old M6 fingerprint predates newer account/profile/weekly-hour records; a fresh current application-table fingerprint was captured during this task and remained unchanged through the remaining QA. `.env` and original Figma CSS fingerprints also remain unchanged.

## Currency-safe browse filters

Every numeric price comparison is combined with an exact `currency` predicate. Browse never ranks or compares nominal values across currencies and performs no conversion. Home can display cards in different currencies, each explicitly labeled; it does not sort by price.

- The filter includes a currency selector. A selected country/city defaults to its local currency and permits its EUR option where applicable. Without a regional selection, browse defaults to EUR, retaining the existing general EUR search behavior.
- Selecting ALL or MKD searches only that currency. An Albanian or Macedonian legacy EUR profile remains discoverable with the EUR option; it is never rewritten into local currency.
- Country-incompatible currency filters and unsupported codes return validation errors. Existing city, country, format, date and public-eligibility conditions still combine with the price filter.
- The existing slider is retained, alongside a numeric limit for precise decimal amounts. Numeric changes apply on Enter or leaving the field. Changing region/currency resets the limit to that currency's own default, without carrying a numeric threshold across currencies.
- Initial limits are `15 EUR` and `10000 ALL/MKD`. These are independent filter defaults, **not equivalent values or exchange rates**. The numeric filter supports limits up to 1,000,000 major units; the existing owner amount limit remains 10,000 major units, with two decimal places.
- Example explicit searches: `/browse?currency=ALL&max_price=1200.50` and `/browse?currency=EUR&max_price=15`. Include `currency` in saved links to make the limit's unit explicit.

There is no all-currencies numeric comparison mode and no FX rate storage or external exchange service.

## Subscription isolation

Match pricing uses a separate `GoalkeeperPricing` helper. Bank-transfer amounts, the EUR-specific payment conversion helper, approval/idempotency, trial/paid eligibility and exact 30-day period logic were not modified. A new regression creates an ALL-priced keeper, submits and approves a test transfer, and verifies the platform payment stays `700` cents / `EUR`, grants exactly 2,592,000 seconds and does not alter the keeper's match price.

## Checks

| Check | Result |
| --- | --- |
| Full PHP suite | **165 passed, 2,195 assertions**, 14.28 seconds; isolated SQLite `:memory:` |
| New currency/payment regressions | 20 tests: country defaults/options, forbidden codes, integer precision, legacy EUR retention, ownership, relocation validation, equal nominal amounts in different currencies, inclusive boundaries, combined dated search and fixed EUR subscription |
| TypeScript | Passed |
| Final production build | Passed, 798 modules, 5.98 seconds |
| PHP formatting | `vendor/bin/pint --test` passed |
| Composer manifest | `composer validate --strict` passed |
| Current application data | Fresh all-table fingerprint unchanged during final QA; no currency backfill, migration or main-database test writes |
| Environment / roles | `.env` unchanged; live bank configuration remains disabled/unconfigured; admin count 0 |
| Original Figma CSS | Byte-identical to original ZIP `src/index.css`; no stylesheet edit |

The existing EUR city-filter test now specifies `currency=EUR` when checking a legacy EUR fixture in Albania; separate tests verify that Albania's omitted-currency default is ALL.

## Browser and mobile QA

Created a **new isolated SQLite preview at port 8008**, containing synthetic EUR, ALL and MKD profiles, including local/EUR alternatives in Albania and North Macedonia. No existing application/QA records were used for edits, and no live payment or email was sent.

- At 390px, the mobile filter dialog defaulted Albania to ALL with EUR available, and North Macedonia to MKD with EUR available. EUR selection in Albania remained available.
- An ALL profile priced at `1200.50` was excluded by `1200.49` and included by `1200.50`; other-currency profiles were not returned as cheaper alternatives. The MKD search returned the `900 MKD` profile rather than its EUR alternative.
- Registration showed EUR-only in Kosovo, ALL/EUR with ALL selected in Albania, and MKD/EUR with MKD selected in North Macedonia. No registration was submitted.
- The Albania QA owner loaded with `1200.50 ALL`. Changing city to Shkup selected MKD and cleared the amount; Kosovo offered only EUR. Returned to Albania and saved `1200.75 ALL`, then verified the exact value after reload on the dashboard and public profile. The subscription panel still displayed `€7 / 30 ditë`.
- Verified the WhatsApp inquiry URL contained `1200.75 ALL`, without sending it. Existing dates and the indicative weekly schedule presentation remained present.
- At 320px, owner and public page content widths matched the viewport. The public match-price card wrapped cleanly with its ISO label. Original styles, colors, cards and responsive breakpoints are retained. Browser viewport overrides were reset afterward.

Evidence: [ALL mobile filters](qa/multicurrency/filters-all-mobile.jpg), [ALL public price on narrow mobile](qa/multicurrency/profile-all-mobile.jpg). Synthetic QA data only.

## Remaining limitations

No FX conversion or cross-currency price sorting is available. Browse intentionally displays one currency at a time; use the EUR option to find retained EUR profiles in Albania/North Macedonia. Physical-device/Safari QA remains outstanding.

The configured local MariaDB connection works without error 1045 on read-only verification. Currency-specific writes and HTTP regressions were exercised on isolated SQLite; no native MySQL currency write was performed against retained data. Oracle MySQL 8.4 remains unverified. Existing launch blockers—approved policies/contact details, production HTTPS/email, authorized bank/admin setup and operational verification—remain unchanged. No milestone or live financial rollout was started.
