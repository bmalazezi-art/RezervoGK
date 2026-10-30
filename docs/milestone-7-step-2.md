# M7 Step 2 — Public content, Albanian interface and draft policies

Finalized 22 October 2026, including a fresh audit after weekly hours and multi-currency pricing were added. **M7 Step 3 was not started.** The original Figma CSS and responsive breakpoints are unchanged. No migrations, seeders, database edits, subscription logic changes, bank configuration changes or role changes were performed.

## Public content cleanup

- Removed fabricated aggregate ratings, review counts, match counts, verification badges, customer testimonials and platform usage statistics from the homepage, keeper cards, public profiles and authentication presentation.
- Public keeper resources no longer serialize `rating`, `reviews`, `matchesPlayed` or `verified`. Regression coverage checks home, browse and direct profiles against legacy fixtures that still contain review/verification metadata, and verifies that stored records remain unchanged. Existing database columns and values are deliberately retained.
- Kept the existing cards and page structure. The homepage's four informational boxes now describe actual service terms (€0 for organizers, 30 trial days, €7 per 30 days and no match-payment commission), rather than invented popularity metrics. The hero no longer shows fake customer avatars or social proof.
- Replaced the profile testimonial panel with practical contact guidance. Experience is explicitly self-declared. Public text explains that identity and experience are not verified. Owner-supplied biographies, photos, language values and other existing data are preserved.
- Removed the misleading rating/recommendation sorting choices. The existing select now honestly shows a disabled list-order label. Sorting and favorites remain unimplemented; no new sorting or review system was introduced.
- Pricing and public explanations now describe manual €7 / 30-day access and no automatic renewal. These are copy changes; existing trial, subscription, approval and cash match-payment behavior is unchanged.

## Working support and policy routes

All five routes are public Inertia pages and require no organizer account:

| URL | Content |
| --- | --- |
| `/how-it-works` | Search, direct contact, confirmation and payment explanation |
| `/faq` | Account-free browsing, availability, unverified profiles, payment status and recovery questions |
| `/contact` | Keeper contact guidance and an explicit notice that official platform contact details are still missing |
| `/privacy` | Draft Privacy Policy, prominently marked for owner review |
| `/terms` | Draft Terms, prominently marked for owner review |

Footer support controls now navigate to working pages. Footer and signup policy links open the drafts. Related links on the information pages allow navigation among help and policies. The pages reuse existing headings, panels, spacing, colors and responsive layout classes. No new stylesheet or redesign was added.

Privacy and Terms clearly state that they are **drafts**, have no approved effective date and await owner review. They distinguish public profile/contact information from account credentials and private receipts, explain direct match arrangements and the existing manual-transfer access workflow, and flag missing operator identity, support/privacy contacts, retention periods, providers, procedures, refund rules and applicable terms for owner completion. No invented company address, email, jurisdiction, refund promise or claim of legal compliance was supplied.

Signup now invites reading the draft documents and states that they await approval; it does not falsely claim that final policies have already been accepted. Final publication and acceptance/versioning requirements remain launch work.

The contact route is functional, but **a real support channel is not yet available**. It does not pretend to send messages or provide a fabricated contact address.

## Albanian text

- Removed the partial SQ/EN toggle and its English-only navigation branch. The existing header position now accurately identifies the interface as `SQ / Shqip`. No English-interface availability is promised. Mobile menu controls have Albanian names and expanded state.
- Added missing Albanian validation rules and field names, authentication/password/pagination translations, and framework error-message translations. Validation no longer exposes raw field labels such as `received_amount` or English conditional status names in these messages.
- Recovery email subject, body, action, expiry notice, greeting, signature, fallback-link instructions and footer are translated. Tests render the complete notification without sending email.
- Admin audit events, metadata labels, booleans and dates have Albanian presentation. Stored audit data and financial decisions are unchanged.
- Replaced the remaining application copy using “cash” with “në dorë”, and the language input example with “Shqip, Anglisht”. User-entered text and existing language values remain verbatim. Browser-native date pickers, file controls and browser validation prompts follow browser/OS language settings.

A confirmed localization test failure exposed that the default error layout still declared `lang="en"`. Its otherwise unchanged framework layout is now overridden at Laravel's actual `resources/views/errors/minimal.blade.php` lookup path and uses the application locale. The regression and browser 404 check now confirm Albanian text and `lang="sq"`. The final content-only findings after the later currency and weekly-hours work are listed below.

## Final continuation findings

Only confirmed content/localization issues were changed in this final pass:

- Help/FAQ and draft Terms now explain country-permitted match currencies, currency-specific price filtering without conversion, and the unchanged €7 EUR / 30-day platform access charge.
- Draft Privacy now explicitly describes the stored amount/ISO currency and the public indicative weekly schedule. Help/FAQ and Terms distinguish indicative weekly hours, including overnight hours, from separately published dates and direct confirmation.
- Replaced the remaining homepage “reserve” wording with direct contact/confirmation guidance, and the unsupported browse “best amateur keepers” claim with neutral profile/date wording.
- Corrected the Albanian single-result label to “1 portier”; plural results remain “portierë”.

These are text/rendering changes in `Information.tsx`, `Home.tsx` and `Browse.tsx`. No currency calculations/filter rules, weekly/date availability rules, eligibility, subscriptions, private uploads, payment decisions or server code changed. Original styles and layout classes remain intact. No new dependencies or migrations were added.

## Verification

| Check | Result |
| --- | --- |
| Full PHP suite | **165 passed, 2,195 assertions**, 30.85 seconds; SQLite `:memory:` |
| New route coverage | Five help/policy routes load directly as a guest and expose the correct page key |
| Public metadata regression | Legacy demo claims absent from home/browse/profile resources; fixture record preserved |
| Translation coverage | Rendered recovery email, validation labels/messages and localized 404 HTML pass |
| Existing authentication, uploads, trials and payments | Full regression suite passed, including weekly/date separation, overnight validation, permitted currencies, exact price storage, currency-scoped filters, fixed EUR subscription charge, disabled bank submission, authorization, receipt isolation, approval idempotency and expiry boundaries |
| `npm run typecheck` | Passed |
| `npm run build` | Passed; 798 modules, 17.93 seconds (final build after all copy fixes) |
| `vendor/bin/pint --test` | Passed |
| `composer validate --strict` | Passed |
| Existing application database and roles | Read-only all-table fingerprints match the fresh baseline captured at the start of this final audit; accounts, profile data, weekly rows, payment/audit records and roles unchanged |
| Environment / bank configuration | `.env` fingerprint unchanged; live bank configuration still absent; live admin count 0 |
| Figma CSS | Byte-identical to original ZIP `src/index.css`, SHA-256 `b7947b500d3c8fad440f8e3a6015b82e373decd01339c032732a6112e20ea6bf` |

No dependencies changed. Dependency advisory audits were performed in M7 Step 1; they were not rerun for this content/localization continuation.

## Browser QA

Final pass used the existing disposable SQLite preview at **port 8008**, with synthetic keeper profiles covering the newer currencies. No database-writing form was submitted, no account was created, no receipt/payment was submitted or reviewed, and no external message/email was sent. Signup was advanced only through its local first step to inspect the draft-policy links; credentials and account submission were left untouched.

Verified desktop 1280 × 720, mobile 390 × 844 and narrow mobile 320 × 740:

- Footer controls load all five help/policy pages; related policy links work. Signup's Terms link opens the draft, with Privacy reachable from its related links. Both signup policy hrefs point to the actual public routes. Owner-review notices and missing official support details remain explicit.
- Updated help/FAQ, Privacy and Terms render in Albanian, including currencies, indicative weekly/overnight hours and the €7 EUR access distinction. Privacy (390px), Terms and FAQ (320px), browse (390px) and profile (320px) have page scroll width equal to viewport width. Mobile menu open/close labels and state remain Albanian.
- Home, browse and public profile show no fabricated rating/review totals, verification badges, testimonials or popularity statistics. The remaining service figures describe the actual trial/fees. The final homepage direct-confirmation copy and neutral browse text were verified from the final build; browse shows “1 portier” for one result.
- The ALL filter returns only the ALL-priced QA keeper; its card/profile and WhatsApp href retain **1200.75 ALL**. Contact URLs were inspected without opening WhatsApp or dialing. Dated availability and the indicative weekly empty-state remain present. Populated/overnight weekly schedules and currency/date search independence are additionally covered by automated regressions; no owner data was edited in this audit.
- Pricing still states €7 / 30 days, manual bank verification and no automatic renewal. Browser 404 is Albanian with `lang="sq"`. Recovery email translation passes rendered notification tests; real delivery is still unverified.

Final screenshots: [Privacy 390px](qa/m7-step2/final-privacy-mobile.jpg), [Terms 320px](qa/m7-step2/final-terms-mobile.jpg), [FAQ 320px](qa/m7-step2/final-faq-mobile.jpg), [ALL browse 390px](qa/m7-step2/final-browse-all-mobile.jpg), [ALL profile 320px](qa/m7-step2/final-profile-all-mobile.jpg), [desktop home/footer](qa/m7-step2/final-home-desktop.jpg). Synthetic data only. Viewport overrides were reset after QA. Physical iOS/Android and Safari remain unverified.

The retained application DB was read only. The older currency-task fingerprint differed before this audit (the weekly rows had newer timestamps), so a fresh audit-start snapshot was used rather than claiming preservation against a stale historical baseline. The fresh snapshot matches after QA. `.env` and the original CSS also match their prior fingerprints; bank configuration is absent and live admin count is still zero.

### Earlier Step 2 browser evidence


The earlier Step 2 pass used the existing separate MariaDB QA preview at port 8007 (before weekly hours and multi-currency were added). Its existing synthetic keeper and dummy bank configuration remain separate from the configured application database. No account was created, no form was saved to the database, no receipt was uploaded, no payment was submitted/reviewed and no email or WhatsApp message was sent.

Checked desktop 1280 × 720, mobile 390 × 844 and narrow mobile 320 × 740:

- Homepage has factual service information and no fabricated popularity/review claims. Browse cards have formats instead of ratings. Public profile has no verification badge, aggregate review/match claims or fake testimonial; contact links and availability remain present.
- Footer links opened How it works, FAQ, Contact, Privacy and Terms. Draft notices and owner-review requirements were visible. Information-page related links remain available.
- Privacy at 390px and Terms at 320px measured page content width equal to viewport width. Long headings, review requirements and body text wrap without widening those pages. Existing mobile menu and stacked browse/profile layouts remain intact.
- Pricing displays €7 per 30 days and no automatic renewal. Registration's shared introduction contains factual guidance rather than a testimonial. Advanced through its local first step without creating an account; the second step contains real draft-policy links and a pending-owner-approval notice. Opened Terms from signup successfully.
- Browser 404 displays “Faqja nuk u gjet”. Recovery-email translation was verified by rendered automated tests; actual email delivery remains unverified.

Screenshots: [desktop home](qa/m7-step2/home-desktop.jpg), [mobile profile](qa/m7-step2/profile-mobile.jpg), [mobile Privacy](qa/m7-step2/privacy-mobile.jpg), [mobile Terms](qa/m7-step2/terms-mobile.jpg), [desktop Terms](qa/m7-step2/terms-desktop.jpg). Synthetic QA data only. Viewport overrides were reset after QA. Physical iOS/Android and Safari testing remain outstanding.

## Remaining launch blockers

1. **Owner-approved policies and business details:** review the drafts; provide legal operator identity/address, official support/privacy contacts, retention/deletion rules, processing/provider details, payment/refund/dispute procedures, age rules, effective dates and final acceptance/versioning requirements. Draft pages are not final launch policies or a legal-compliance assessment.
2. **Real support operations:** publish and test the contact channel, abuse/reporting workflow, privacy requests and response process. The new contact page openly marks these as pending.
3. **Production configuration and email:** deploy with a canonical HTTPS `APP_URL`, production environment, debug off, secure cookies, public-only web root, correct host/proxy handling and protected secrets/logs. Configure and verify real recovery-email delivery and sender/domain settings. The local configuration remains unchanged.
4. **Paid operation:** bank submissions remain disabled and no live admin exists, as requested. Verified bank details, authorized secure admin provisioning and reconciliation procedures are required before separately enabling live transfers. Admin MFA/reconfirmation policy remains an operational decision; no admin access was granted here.
5. **Production operations:** verify deployment/rollback, DB and private-receipt backup/restore, monitoring, retention, storage permissions and the intended production database engine. Real keeper onboarding is needed; existing unclaimed demos remain stored and hidden publicly. Original PORTIERI branding is preserved pending any separately approved brand decision.

Known non-blocking prototype limitations include inactive favorites, unavailable sorting/review submission, no identity/phone verification workflow and browser-dependent native controls. The interface now avoids advertising ratings or verification as implemented services.

**MySQL status, separately:** local MariaDB 10.4.28 access succeeded during read-only checks; error 1045 did not recur. No database/user/grant changes occurred. Oracle MySQL 8.4 and production deployment remain unverified. Automated HTTP tests used isolated SQLite; final browser QA used the existing isolated SQLite preview; earlier Step 2 browser QA used the separate MariaDB preview. M7 Step 3 was not started.
