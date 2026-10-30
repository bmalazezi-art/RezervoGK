# Milestone 1 — frontend migration

## Preservation

Source: `Goalkeeper Booking Marketplace (1).zip`, supplied by the user.

- Source stylesheet imported without a byte change, including every media query and existing unused selector.
- Each of the six screen render bodies matches the export; only component signatures/imports and profile fixture selection changed.
- Shared UI extracted from the original source, with a persistent Inertia layout.
- Original PORTIERI branding and prototype behavior retained intentionally.
- Real routes replace the in-memory screen switch. All four sample profiles can be opened directly.

## Scope boundaries

Price/format filtering and direct WhatsApp/tel links retain their existing behavior. Registration still displays the frontend confirmation without creating an account. Profile saves, uploads, availability editing, billing, favorites, mobile filter actions, location/date search, review verification, and full English translation remain unimplemented as in the source.

No authentication, marketplace database schema, subscriptions, payment integration, or match payment functionality was added. The default Laravel scaffold is present; its migrations have not been executed.

## Verification

- TypeScript checking and Vite production build pass.
- Nine route/protocol tests pass (107 assertions), without database access.
- Source CSS byte equality and all six screen render-body comparisons pass.
- Mobile browser smoke check: homepage, hamburger menu, and Inertia navigation to browse render with the original responsive layout.
- Full pixel comparison across desktop/tablet/mobile has not been performed.
- MySQL is configured with a Compose service; connectivity remains unverified because neither Docker nor a MySQL server is installed on the host.

External Unsplash images and Google Fonts remain external, matching the export. Existing prototype issues are intentionally retained for future milestones.
