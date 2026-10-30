# M5 — Local database access and isolated migration verification

21 October 2026. **Local error 1045 is resolved.** Laravel now authenticates with a new restricted account against a new isolated database. The installed server is **10.4.28-MariaDB (XAMPP)**; Oracle MySQL 8.4 verification remains open. No subscriptions, UI changes or application feature changes were made.

## Diagnosis

- XAMPP's `mysqld` process is running under the local database service account. TCP 3306 responds on IPv4 and IPv6; its handshake identifies MariaDB 10.4.28. XAMPP also exposes its Unix socket. This is not the MySQL 8.4 instance described by `compose.yaml`.
- Laravel uses the MySQL PDO driver at 127.0.0.1:3306. Configuration was uncached; no inherited DB environment overrides or DB_URL override were present. Effective host, port, schema, account, password and socket settings matched `.env`; the database settings also matched `.env.example`. Comparison output contained booleans only.
- The original configured application credentials failed with **1045**, both without a schema, with the configured schema, and via XAMPP's Unix socket. Switching host/socket or running migrations could not fix this authentication failure.
- The existing local phpMyAdmin configuration provided an authenticated connection for read-only inspection. Its credentials and authentication hashes were never printed or copied into reports. No passwords were guessed or reset.
- That inspection established **zero account entries** for Laravel's original configured username and **no schema matching the originally configured database**. The example connection settings had never been provisioned on the running XAMPP server. Existing application-user grants therefore did not exist to inspect.
- MySQL/MariaDB were not installed through Homebrew here. The Docker CLI is unavailable, so the supplied MySQL 8.4 Compose service cannot currently run through that workflow.

## Safe resolution

Created only the following new resources:

- Isolated schema: `rezervogk_m5_20261021_2a70389a87c5` (utf8mb4 / utf8mb4_unicode_ci).
- A uniquely named, dedicated application account restricted to host `127.0.0.1`, with a randomly generated password stored only in local `.env`.
- Schema-only privileges: SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX and REFERENCES. No global administrative privileges or GRANT OPTION. Underscores in the schema grant are escaped so they cannot act as wildcard matches; access to a similarly named schema was rejected with native 1044. A direct attempt by the new account to read `mysql.user` was denied.

Provisioning checked for name collisions first and used CREATE without IF NOT EXISTS, so it could not adopt or overwrite an existing schema/account. All migration, seed and constraint operations were explicitly bound to the newly created empty schema. Existing schema and user inventories were compared before and afterward; only the new resources were added. No statements altered a pre-existing schema, user, password or grants. Existing XAMPP/phpMyAdmin administrator access was left unchanged.

Local `.env` connection fields were updated **only after the new account, migrations, seeders and constraints passed**. Other environment settings were preserved, the write was atomic, file permissions are 0600, and no credential-bearing backup/report was created. The application keeps `DB_CONNECTION=mysql` (PDO's MySQL-compatible driver), using TCP. The configured schema is an isolated demo/verification environment, not production data. Earlier SQLite preview files were not changed.

## Verification results

| Check | Result |
| --- | --- |
| Normal Laravel connection, without per-command DB overrides | Authenticated |
| New target empty before migrations | Verified |
| Migrations | All 6 passed; `php artisan migrate:status` reports all Ran |
| Seeders | Passed: 4 demo profiles, 5 dated entries, 16 tables total |
| JSON languages and eager-loaded relationships | Passed |
| Actual availability date lookup | Passed |
| Unique profile/date pair | Duplicate rejected with native 1062 |
| Availability foreign key | Orphan rejected with native 1452 |
| Cascade deletion | Availability removed with its test profile |
| Test cleanup | Transaction rolled back; seeded record counts restored |
| Account privilege isolation | No global administrative grants; server user table inaccessible; exact schema grant with escaped wildcards; lookalike schema denied (1044) |
| Pre-existing schema/account inventories | Preserved |
| PHP regression suite | 83 tests passed, 866 assertions; SQLite :memory:, 6.83 seconds |
| Browser on port 8000 | Home, guest city/date search and public profile/calendar load from new database |
| UI/CSS | Unchanged; original Figma stylesheet byte-identical |

The browser searched Prishtinë for 23 October 2026, found the seeded available keeper and opened the matching dated profile. No external contact message or phone call was initiated. The local Laravel server was started on http://127.0.0.1:8000 after connection verification. Browser reads do not create user accounts.

Machine-readable evidence: [m5-database-verification.json](m5-database-verification.json).

## Remaining limitations / blockers

1. **Oracle MySQL 8.4 is not verified.** The live engine is MariaDB 10.4.28. Successful PDO/mysql-driver migration checks establish compatibility with this installed server, not proof on Oracle MySQL. Exact engine verification requires a separate MySQL 8.4 instance on an unused port or an approved external target; Docker is currently unavailable. No existing service was stopped, replaced or upgraded.
2. The 83 HTTP regression tests deliberately remain forced to SQLite in `phpunit.xml`. They were not silently redirected to a persistent database. Live MariaDB migration, seed, native-constraint and public-browser checks above provide separate database evidence; the full auth/upload HTTP suite has not been rerun against MariaDB/Oracle MySQL.
3. Only the new isolated demo schema was migrated and seeded. No existing production data was inspected, imported or modified. No production deployment, SMTP delivery, payments or subscriptions were added.

To recheck safely, run `php artisan migrate:status` and `php artisan test`. Do not use `migrate:fresh` or rerun demo seeders against a database containing work to preserve. Database credentials remain local and must not be pasted into a shared report or command line.
