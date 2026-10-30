# M2 — Database and public goalkeeper profiles

## Delivered

- MySQL-compatible schema with foreign keys, unique country/city/format identifiers, contact ownership and a unique profile/format pivot.
- Five Eloquent models: Country, City, GoalkeeperProfile, GoalkeeperContact, MatchFormat.
- EUR prices stored as integer cents on GoalkeeperProfile. Telephone and WhatsApp numbers stored separately as strings.
- Repeatable, transactional demo seeder for the four original keepers, with location-specific biographies. No seeded accounts.
- `/browse` and `/keepers/{id}` now read Eloquent records through an explicit Inertia payload, with eager-loaded relationships.
- City, country, maximum-price and format filters run in SQL, combine with AND and persist in query parameters.
- Database-backed filter options, clear/reset and empty results. Existing mobile filter button opens a native dialog using the same fields and existing classes.
- Profile biography, languages, experience, price, formats, location, contact numbers and demo display metadata come from the selected database record. Any existing numeric profile ID is supported; missing profiles return 404.
- Source CSS and 900px/640px breakpoints are byte-identical to the Figma export (SHA-256 `b7947b500d3c8fad440f8e3a6015b82e373decd01339c032732a6112e20ea6bf`). Existing resting page structure is preserved; dialog and empty-result content are new interaction states.

## Verification

- TypeScript check and production Vite build pass.
- 26 PHP tests pass with 322 assertions on forced, in-memory SQLite.
- Tests cover schema creation, model relationships, contact/pivot deletion cascades, seeder repeatability, no seeded users, independent and combined filters, inclusive cent boundaries, invalid query rejection, database edits reflected in page props, empty results, profiles beyond the four fixture IDs, Inertia navigation and 404s.
- A separate ignored SQLite database was migrated and seeded for browser verification.
- Desktop browser checks confirmed city selection, price filtering to zero results, clearing filters, format filtering and profile-card navigation. Original styles render correctly. Screenshot: `screenshots/m2-browse-filtered.png`.
- M2 has not had a full desktop/tablet/mobile pixel comparison or mobile-dialog browser check.

## Limitations

The configured MySQL connection responds with access-denied error 1045. MySQL credentials, migrations, seed execution and engine-specific behavior remain unverified. The main `.env` remains configured for MySQL. SQLite preview runs at http://127.0.0.1:8001/browse with process-level environment overrides only; no runtime fallback or mock substitution was added.

The home cards/dashboard remain fixtures. Availability dates, the available-today toggle, sorting, favorites and review/verification workflows are not implemented. Calendar and testimonial content remain prototype presentation, while aggregate review/verification fields are demo database metadata. No authentication, payments or subscriptions were added.
