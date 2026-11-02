# Review of implementation-plan-v1.md

This is a review of [docs/plans/implementation-plan-v1.md](implementation-plan-v1.md), cross-checked against the README, [guide](../guide.md), [architecture](../architecture.md), [data model](../data-model.md), [DAV endpoints](../dav-endpoints.md), the [apps](../apps/README.md) pages, the [admin-users design draft](../design/admin-users.md), the Compose files, `SECURITY.md` and `CONTRIBUTING.md`.

Overall the PR order and scope are sound. The gaps below are either things the plan omits that the other docs already promise for Version 1, or sabre/dav integration details that aren't explicit anywhere yet.

## Status (after PR 7)

This review is historical. It was written before PR 1, and the text below is kept as it was. Almost every point is now handled; the [implementation plan](implementation-plan-v1.md) is the source of truth. Its "Critical gaps from the review" table maps section 1 to PRs, and its Decisions table records section 3.

| Review point | Status |
|---|---|
| 1. Critical gaps 1–6 | Done in PR 2, PR 3a and PR 3b. Changed: another user's paths answer **404**, like an unknown user, not 403 (decision 14) |
| 2. Dashboard | Done in PR 6 ([admin-dashboard.md](../design/admin-dashboard.md)) |
| 2. Delete user, last-admin guard | Done in PR 2 |
| 2. List and revoke app passwords | Done in PR 2 (Users page) and PR 6 (Diagnostics page) |
| 2. Edit and delete collections, counts | Done in PR 4 |
| 2. `APP_KEY` on first start, migrations on start, required-variable check | Done in PR 1 |
| 2. Trusted proxies | Done: `TRUSTED_PROXIES`, private ranges by default since PR 5a (decision 17) |
| 2. Ready-to-use proxy configurations | Done in PR 8 ([docs/proxy](../proxy/)) |
| 2. Log rotation | Done (`max-size` and `max-file` in Compose). Request ID and request fields in the log: done in PR 8 (decision 24) |
| 2. `curl` and `sqlite3` in the image | Done in PR 1 |
| 2. `composer test`, `lint`, `analyse`; CI on PostgreSQL and SQLite | Done |
| 2. Build and publish the image | Done in PR 8: the release workflow publishes `ghcr.io/offsyanka99/nexusdav` |
| 2. DAV login with email or username | Done in PR 3b |
| 3. Client-created collections | Decided: allowed (decision 7). [calendar.md](../apps/calendar.md) now says clients can create a calendar (done in PR 8 doc sync) |
| 3. Service switches | Decided (decisions 8 and 22) and built in PR 6 |
| 3. Username rules | Decision 10; collection URIs are validated slugs, unique per user (PR 4) |
| 3. Admin as a DAV user | Decision 5; the first admin is created only when no admin exists, and an existing admin is never changed |
| 3. Main password | Decision 4: optional for non-admins, never accepted on `/dav/` |
| 3. Default collections | Decision 6; done in PR 4 |
| 3. Web server | Decision 1: FrankenPHP, classic mode, non-root |
| 3. Settings at runtime | Decision 21; done in PR 6 |
| 3. Sign-out on admin reset | Done in PR 7 (database sessions plus `AuthenticateSession`) |
| 4. Order and structure | Done: rate limiting is one PR (PR 5) before Settings (PR 6); every PR ships tests; DAVx5 smoke tests from PR 3c; the full `users` schema in PR 1; Thunderbird, Apple and `litmus` listed in PR 8 |
| 5. `LOG_LEVEL` forced by Compose | Fixed: Compose passes it only when set (decision 9), so the panel field is editable |
| 5. Logs | Fixed: stderr only, `LOG_CHANNEL=stderr` in the image (decision 16); no `/data/logs` |
| 5. Backups and `APP_KEY` | Fixed in the guide |
| 5. Missing variables | Fixed in the guide's variable table |
| 5. DAV paths | Fixed: one principal row, `/dav/principals/<username>/`; `/dav/` in the design note; `/dav/files/` marked Version 1.x |
| 5. Data model | Fixed: `app_passwords` has `public_id`, `revoked_at` and `expires_at` (PR 2); `UserService` keeps the principal's display name and email in sync |

## 1. Critical gaps (would cause bugs or security problems)

1. **Users could read each other's data.** Without sabre's `DAVACL` plugin, any logged-in user can open `/dav/calendars/otheruser/`. Putting "Sharing / ACL" under out of scope makes it easy to skip that plugin entirely. PR3 should add `DAVACL` with owner-only access, plus a test showing user A gets 403 or 404 on user B's data.
2. **Table name clash on `principals`.** sabre's PDO principal backend expects its own `principals` table with columns `uri`, `email`, `displayname`. [data-model.md](../data-model.md) defines a Laravel `principals` table with different columns under the same name. You need to decide between a custom `PrincipalBackend` reading Laravel tables and matching sabre's schema. You also need a custom Basic auth backend (`AbstractBasic`). Neither is listed in PR3.
3. **sabre/dav does not create its own tables.** It only ships SQL files in `examples/sql`. PR3 needs Laravel migrations that port them for both pgsql and sqlite: `calendars`, `calendarinstances`, `calendarchanges`, `calendarobjects`, `calendarsubscriptions`, `schedulingobjects`, `addressbooks`, `addressbookchanges`, `cards`, `propertystorage` and `locks`. data-model.md currently says the opposite and should be corrected.
4. **App password lookup will be slow.** Basic auth runs on every DAV request. Checking an Argon2 hash against every app password the user has means N slow hash checks per request. Two options:
   - Give each token an ID prefix and look it up directly.
   - Use SHA-256, which is enough for long random tokens.

   Also throttle the `last_used_at` writes.
5. **Laravel / sabre bridge details for PR3:**
   - Exclude `/dav/*` from CSRF and session middleware.
   - Allow custom HTTP methods (`PROPFIND`, `REPORT`, `MKCALENDAR`, and so on).
   - Make sure the `Authorization` header reaches PHP (php-fpm and Apache can drop it).
   - Pass request bodies through to sabre and stream its responses back.
   - Call `setBaseUri('/dav/')`.
   - Reuse Laravel's PDO connection.
   - For SQLite, turn on WAL mode and a busy timeout.
6. **Disabled users must be rejected on the DAV endpoint,** not only in the admin panel.

## 2. Promised in the docs but missing from the plan

| Promised in | Missing |
|---|---|
| [admin.md](../apps/admin.md) | Dashboard (landing page with status and statistics) |
| admin.md, design draft | **Delete user**, which must also remove the user's sabre rows. Guard against deleting or disabling the last admin |
| Guide troubleshooting ("app password was revoked") | List and **revoke** app passwords |
| [admin-users.md](../design/admin-users.md) | Edit and delete calendars and address books; object and contact counts |
| Guide | `APP_KEY` generated on first start and stored in `/data`; migrations run on start; startup stops with a clear message if a required variable is missing |
| Guide | Trusted proxy and `X-Forwarded-Proto` support (no matching env variable exists yet) |
| Guide | "Ready-to-use proxy configurations … as part of Version 1" (nginx, Caddy, Traefik) |
| Guide | Log rotation (`max-size`, `max-file`) in Compose; the request-ID and request fields to be logged |
| Guide | `curl` (used by the healthcheck) and `sqlite3` (used in backup steps) inside the image |
| CONTRIBUTING | `composer test`, `lint` and `analyse` scripts, plus **CI running tests on both PostgreSQL and SQLite** |
| PR8 "pin image tag" | No PR builds or publishes an image (for example, ghcr.io for amd64 and arm64) |
| Guide | DAV login with "email or username"; the auth bridge has to accept both |

## 3. Decisions to write down

- **Can clients create or delete collections?** [dav-endpoints.md](../dav-endpoints.md) lists `MKCALENDAR` and `MKCOL`, but [calendar.md](../apps/calendar.md) says clients cannot create collections. DAVx5 and Thunderbird can do both.
- **What the CalDAV / CardDAV / Tasks / Notes switches actually do:** which plugins are loaded, what response is returned when a feature is off, and what happens to existing VTODO collections.
- **Username can't change after creation and must be URL-safe,** because it is part of the URLs clients store. Collection URIs need validated slugs that are unique per user.
- **Is the admin also a DAV user** with a principal? The admin also needs a username, which the env variables don't provide. Creating the admin on boot must be idempotent: create only if none exists, never overwrite.
- **The user's main password has no use in Version 1,** because DAV accepts only app passwords and there is no portal yet. Make it optional, or state that it is kept for Version 2.
- **Create a default calendar and address book on user creation?** This is simpler for users.
- **Web server choice:** FrankenPHP, nginx + php-fpm, or Apache. Avoid Octane-style persistent workers with sabre. Run as a non-root user and raise `post_max_size` for vCards with photos.
- **Settings at runtime:** session and rate-limit settings stored in the database must be loaded into config at boot, and settings must be cached so DAV requests don't query them every time.
- **Session sign-out on admin reset:** PR7 promises to sign out sessions, which needs the `AuthenticateSession` middleware and a session store under `/data` or in the database.

## 4. Order and structure

- **Rate limiting is split across three PRs.** PR2 adds "basic rate limiting", PR5 configures thresholds and PR6 builds the feature, so PR5 configures something that doesn't exist yet. Merge it all into PR6, or move PR6 before PR5. Rate-limit by **IP plus username** so an attacker can't lock a real user out.
- **Tests are all left for PR8.** Each PR should ship its own tests.
- **The first DAVx5 test is in PR8.** That conflicts with the plan's own note about end-to-end slices. Do a smoke test with DAVx5 at the end of PR3 and PR4, and consider a short throwaway sabre spike first, since PR3 is the critical path.
- **PR1's done criterion says "admin can log in",** so the full `users` schema (username, is_admin, is_active) belongs in PR1, not PR2.
- **Vague done criteria:** PR5's "take effect" should name concrete checks. PR8 could also try Thunderbird and Apple, since the roadmap says "ideally other clients", and optionally run `litmus` or CalDAVTester.

## 5. Inconsistencies in the existing docs

- **`LOG_LEVEL`:** [compose.yaml](../../compose.yaml) always sets `LOG_LEVEL=${LOG_LEVEL:-debug}`. Because environment values win, the Settings logging-level field will **always be read-only**. A default of `debug` in production is also risky.
- **Logs:** [architecture.md](../architecture.md) and the plan say stdout plus `/data/logs`; the guide says stdout and stderr only.
- **Backups:** the guide says to back up `.env` for `APP_KEY`, but `APP_KEY` lives on the data volume, not in `.env`.
- **Missing variables:** `DB_DATABASE` and `DB_USERNAME` are used in [compose.postgres.yaml](../../compose.postgres.yaml) but missing from the guide's variable table.
- **DAV paths:**
  - [dav-endpoints.md](../dav-endpoints.md) lists "Principal" and "Principal home" as two rows with the same path.
  - The design draft uses `/dav.php/…` instead of `/dav/…`.
  - The guide's endpoint table lists `/dav/files/` without marking it as Version 1.x.
- **Data model:**
  - `app_passwords` has no column for revocation or expiry, and no token prefix.
  - `display_name` and `email` are stored in both `principals` and `users`, so they must be kept in sync when edited.
