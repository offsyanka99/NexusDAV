# Implementation plan — Version 1

Goal: a working minimal server that allows creating users, calendars and address books in the admin panel, and syncing with DAVx5 (CalDAV + CardDAV).

This plan was revised after the [review](implementation-plan-v1-review.md). The open decisions are settled in [Decisions](#decisions).

**Status:** all PRs are done, PR 1 to PR 8 (see the notes under each). Version 1 is released: **0.1.0** and **0.1.1** are published. One maintainer step remains, a check with Apple clients (DAVx5 and Thunderbird were tested on 0.1.1) (see [PR 8](#pr-8--release-polish-and-documentation-effort-high---done-one-maintainer-step-left)). A [code review of PR 1 to PR 5](#code-review-of-pr-1-to-pr-5-31-october-2026) on 31 October 2026 fixed ten issues in place and added two PRs, **PR 5a** (security hardening) and **PR 5b** (container, CI and performance), both now done. The requirements docs changed after PR 1 and PR 2 shipped (Diagnostics, Dashboard, Settings and Configuration design notes, PHP security fix). The gaps that creates are listed under [Follow-ups on shipped work](#follow-ups-on-shipped-work) and folded into PR 3b to PR 8.

Effort levels:
- **Low** — small, mostly boilerplate or config
- **Medium** — standard feature, clear path
- **High** — integration or non-trivial logic
- **Extra High** — complex, many edge cases, or core protocol work

## Working rules

- Every PR ships its own tests. Tests are not left for the last PR.
- Tests run on **both SQLite and PostgreSQL** in CI from PR1.
- A PR is "done" only when its **Done when** checks pass. Where a DAV client is named, a real client (DAVx5) or a raw HTTP request is used.
- Prefer working end-to-end slices over finishing one layer completely.

## Follow-ups on shipped work

PR 1 and PR 2 are done, but the requirements moved afterwards. These items are not covered by their "Shipped" notes.

1. **Patched PHP (security, do first).** The [guide](../guide.md#requirements) now requires a PHP that fixes CVE-2026-109618 and CVE-2026-109619 (**8.4.27+**, or 8.3.36 / 8.5.12). The Dockerfile uses the floating tag `dunglas/frankenphp:1-php8.4`, so the version depends on when it was last built.
   - Rebuild with `--pull` and confirm `php -v` shows 8.4.27 or newer.
   - Add a CI check that fails the build when the image's PHP is older than that.
   - Re-check before publishing the image (PR 8).
   - **31 October 2026:** the image built with `--pull` reports PHP 8.4.27. **Done in PR 5b:** CI builds the image and `docker/smoke-test.sh` fails on a PHP older than 8.4.27.
2. **Principal path mismatch.** PR 2 stores principals as `principals/{username}` (matches [data-model.md](../data-model.md) and sabre's default layout), but [dav-endpoints.md](../dav-endpoints.md) and the guide endpoint table say `/dav/principals/users/<username>/`. Keep the shipped form and correct those two docs (done in the guide and [dav-endpoints.md](../dav-endpoints.md)). Settle this before PR 3a builds the sabre backend, since clients store these URLs.
3. **Guide text that was rolled back (fixed).** The guide commit `fa8a441` (the CVE note) had reverted earlier edits: the variable table, the health and logging section, and the Diagnostics lines in the roadmap. These are restored in [guide.md](../guide.md), together with the principal path, the `/dav/files/` version mark and the `APP_KEY` backup wording. The remaining doc fixes are in the PR 8 sync.
4. **No scheduler in the container.** The image runs one FrankenPHP process, with no cron and no `schedule:work`. Anything planned as a "scheduled command" would never run. See PR 5 (pruning).

## Critical gaps from the review: where each is handled

The [review](implementation-plan-v1-review.md#1-critical-gaps-would-cause-bugs-or-security-problems) listed six gaps that could cause bugs or security problems. Each one has an owner and a test, so none can be skipped.

| # | Gap | Handled in | Proof |
|---|-----|------------|-------|
| 1 | Users could read each other's data (no `DAVACL`) | **PR 3b:** owner-only `DAVACL`, no admin principals, principal listing and search restricted | Cross-user tests for each method; `/dav/principals/` lists only the caller |
| 2 | `principals` table clash; no custom principal or auth backend | Decided in PR 2 (Laravel table); **3a** builds the principal backend, **3b** the `AbstractBasic` backend | Login by username and by email both resolve to `principals/{username}` |
| 3 | sabre does not create its tables | **PR 3a:** Laravel migrations for both databases, without sabre's own `principals` / `users` / `groupmembers`; [data-model.md](../data-model.md) corrected in the same PR | PUT then GET round trip and sync-collection on SQLite and PostgreSQL |
| 4 | App password lookup would check many hashes per request | **Done in PR 2** (public ID, SHA-256, `last_used_at` written at most once per 60 seconds); **3b** must reuse it | Test: Basic auth does one token lookup and no per-user hash scan |
| 5 | Laravel / sabre bridge details | **PR 3b:** routes outside `web` with explicit DAV methods, `Authorization` passthrough, body and response passthrough, `setBaseUri('/dav/')`. **3a:** shared PDO, SQLite WAL and busy timeout | Raw HTTP tests through the real container; `Destination` correct behind a proxy |
| 6 | Disabled users must be refused on DAV | **PR 3b:** `is_active` checked on every request, no caching | Disabled user with a valid token gets 401 (recorded as "user disabled" in PR 5); works again when re-enabled |

## Code review of PR 1 to PR 5 (31 October 2026)

All code from PR 1 to PR 5 was reviewed for bugs, broken logic and security problems. Suspected issues were reproduced with raw HTTP tests on SQLite and PostgreSQL 17 before anything was changed, and the container fixes were checked in a freshly built image.

**Checked and found sound:**
- Owner-only `DAVACL`, including free-busy and `calendar-query` through the REPORT read check.
- LOCK on shared roots, PROPPATCH on missing paths, and COPY/MOVE into another home.
- App-password checks: one lookup by public id, `hash_equals`, and revoked or expired reported only after the secret matches.
- `X-Forwarded-For` trusted only from an explicitly listed proxy.
- No secrets in `diagnostic_events`, `DavErrorNote` or the log. (PR 8 replaced `DavErrorNote` with the request log line.)
- `/dav` outside the `web` middleware group.
- The last-admin lock.

### Fixed in this review

Small fixes, each with a regression test in `tests/Feature/CodeReviewFixesTest.php`. The suite passes on both databases, and `composer lint` and `composer analyse` are clean.

| # | Finding | Severity | Fix |
|---|---------|----------|-----|
| R1 | On PostgreSQL, saving any event after 19 January 2038 failed with HTTP 500. `firstoccurence` and `lastoccurence` were 32-bit `integer`, copied from sabre's script | High | Migration `2026_10_31_000000_widen_dav_time_columns` makes the Unix-time columns `bigint` on PostgreSQL |
| R2 | Logs never reached `docker compose logs`. The image did not set `LOG_CHANNEL`, so Laravel wrote an unrotated `storage/logs/laravel.log` inside the container, which was lost on recreate | High | `ENV LOG_CHANNEL=stderr` in the Dockerfile. In a built container, a failed DAV login appears in `docker logs` |
| R3 | Any signed-in user could find out which usernames exist: another user's home answered 403, a missing one 404 | Medium | `OwnerOnlyPrincipalBackend::getPrincipalByPath` reports other principals as missing, so both answer 404 (a write answers 409) |
| R4 | Two accounts could have emails that differ only in case. The second one could not sign in to DAV with its email | Medium | `UserService` and the user form refuse an email that matches another account without regard to case |
| R5 | A malformed home `sync-token` caused HTTP 500 | Low | `HomeSync` accepts only its own list-shaped token; anything else is `valid-sync-token` (403) |
| R6 | PROPPATCH of a principal display name over 255 characters caused HTTP 500 on PostgreSQL | Low | The value is refused (403 for that property) |
| R7 | A calendar that DAVx5 or Apple created with a `#RRGGBBAA` color could not be saved from the edit form | Low | The form starts from the `#RRGGBB` part |
| R8 | `/` served Laravel's welcome page, which prints the exact framework version | Low | `/` redirects to `/admin` |
| R9 | The Docker build context could ship `bootstrap/cache/*.php` (a cached config holds `.env` values), local logs and sessions | Low | `.dockerignore` excludes them |
| R10 | `diagnostics.collapse_seconds = 0` would divide by zero | Low | Minimum of 1 |

### Planned in new PRs (all done)

R11 to R17 shipped in PR 5a, R18 to R22 in PR 5b, R23 and R24 in PR 6.

| # | Finding | Severity | Where |
|---|---------|----------|-------|
| R11 | Each unauthenticated request with a new username writes one `diagnostic_events` row and one log line, with no cap. 30 random usernames from one address made 30 rows | Medium | PR 5a |
| R12 | With the default `TRUSTED_PROXIES=*` behind a reverse proxy, the limiter keys on the proxy's address, so five bad passwords lock that username out for everyone for a minute | Medium | Documented now in the guide and `SECURITY.md`; fix in PR 5a |
| R13 | Overlong client values (collection name over 255, color over 10, resource name over 200 characters) cause HTTP 500 on PostgreSQL and are stored silently on SQLite | Medium | PR 5a |
| R14 | No minimum password length for the panel or `ADMIN_PASSWORD`, and no confirmation field, although the Users design asks for one | Medium | PR 5a |
| R15 | Admin login matches the email case-sensitively, and the database index is case-sensitive | Low | PR 5a |
| R16 | The admin panel sends no frame protection header (clickjacking). `.well-known` redirects to the forwarded host instead of `APP_URL` | Low | PR 5a |
| R17 | A DAV login for an unknown username returns before the token lookup, so response time hints whether a username exists | Low | PR 5a |
| R18 | Property storage runs one query per resource: a depth-1 PROPFIND on 50 events made 62 queries, 51 of them on `propertystorage` | Medium | PR 5b |
| R19 | Each request boots Laravel and discovers Filament from scratch (no config, route, view or Filament cache). There is no production `php.ini` (`expose_php`, `display_errors`) | Medium | PR 5b |
| R20 | CI does not build the image, so the PHP security check (Follow-up 1) is still manual | Medium | PR 5b |
| R21 | The boot backfill visits every user on every start | Low | PR 5b |
| R22 | `docker compose exec` runs as root, so artisan commands can leave root-owned files in `storage`. Changing `PUID` leaves the SQLite files owned by the old user | Low | PR 5b |
| R23 | The session cookie is not marked `Secure` by default | Low | PR 6 |
| R24 | With CalDAV or CardDAV off, the root is not loaded and requests return 404, while decision 8 says 403 | Low | PR 6 |

## PR sequence

### PR 1 — Project skeleton and container (Effort: High) — ✅ done

- `composer create-project laravel/laravel`
- Install Filament 5, Livewire, Tailwind
- Basic `config/nexusdav.php`
- `.env.example` with the variables from the guide (including `DB_DATABASE`, `DB_USERNAME`, trusted proxy setting)
- **Full `users` schema from the start**: `username` (unique, immutable, URL-safe), `display_name`, `email` (unique), `password`, `is_admin`, `is_active`, `timezone`, `locale`, `quota_bytes`
- Dockerfile (PHP 8.4 + required extensions, non-root user, `curl` and `sqlite3` included, raised `post_max_size` / `upload_max_filesize`)
- Web server inside the image: FrankenPHP in classic (non-worker) mode, port 8080, non-root with `PUID` / `PGID` support for TrueNAS SCALE
- Entrypoint script: validate required variables and stop with a clear message if one is missing; generate `APP_KEY` on first start and store it on `/data`; run migrations
- Trusted proxy handling (`X-Forwarded-Proto`, `Host`) so `APP_URL` and generated links stay HTTPS
- Update `compose.yaml` to use the local build; add log rotation (`max-size`, `max-file`); do not set `LOG_LEVEL` in Compose unless the user sets it
- `/health` endpoint (app + DB up, no details exposed)
- First admin creation on boot from `ADMIN_EMAIL` / `ADMIN_PASSWORD`: **idempotent** (only when no admin exists, never overwrites); derive `username` from the email
- CI (GitHub Actions): `composer test`, `composer lint`, `composer analyse` on SQLite and PostgreSQL
- Tests: health endpoint, first-admin creation is idempotent, missing variable stops start

**Done when:** container starts, `/health` returns OK, admin can log in, CI is green on both databases, restart does not recreate or change the admin.

**Shipped** on `main`. Laravel 13, Filament 5, Livewire 4, and Tailwind 4.1 were already present, including unused `sabre/dav` 4.7.1. This work added the users table (Argon2id, URL-safe immutable username), `config/nexusdav.php`, trusted proxies, `GET /health` (`{"status":"ok"}` or 503 with no details), and idempotent first-admin boot. The image is FrankenPHP PHP 8.4 in classic `php-server` mode on port 8080, uid/gid from `PUID`/`PGID` (default 568). `APP_KEY` is stored at `/data/app.key`. Compose builds the local Dockerfile, rotates logs, and does not force `LOG_LEVEL` (a blank value is treated as unset). CI runs lint and analyse once, then the suite on SQLite and PostgreSQL 17. Checked: container `/health` was 200, the admin authenticated, and a second start did not change that admin.

### PR 2 — Users, app passwords and principals (Effort: High) — ✅ done

- Migrations and models: `AppPassword`, `Principal` (see [Principal strategy](#principal-strategy))
- Argon2id for main passwords; main password is optional for non-admin users because DAV accepts only app passwords and there is no portal in Version 1
- App password generation: random token with a **short public ID prefix**, shown once. The ID is used for direct lookup, so DAV Basic auth never checks every hash of a user. Store only the SHA-256 hash of the secret (Argon2id is unnecessary for high-entropy tokens and too slow per request)
- Columns for revocation and optional expiry (`revoked_at`, `expires_at`); `last_used_at` is written at most once per interval, not on every request
- Filament User resource: list, search, create, edit, enable/disable, **delete**
- App password list and **revoke** in the user page
- Guard: the last active admin cannot be deleted or disabled
- `UserService`: create user + principal in one transaction; keeps `users` and `principals` `display_name` / `email` in sync on edit
- Basic rate limiting on admin login (Laravel default), replaced by the full feature in PR 5
- Tests: user creation, app password generation/hash/revoke/expiry, last-admin guard, disabled user cannot log in

**Done when:** admin can create a user, generate and revoke an app password, and delete a user.

**Shipped** on `main`. `UserService` creates a user and one principal (`principals/{username}`) in one transaction and keeps `display_name` and `email` in sync. The first-admin boot uses that path and does not change an admin that already exists. `users.password` is nullable Argon2id and required only for admins. An app password is shown once as `{public_id}.{secret}` (12-character id, 32-byte secret). Only the SHA-256 of the secret is stored. Revoke sets `revoked_at`. `expires_at` is optional. `last_used_at` is written at most once per 60 seconds. The Filament Users page lists, searches, creates, edits, enables, disables, and deletes users, and can issue and revoke app passwords. The last active admin cannot be deleted, disabled, or demoted. Admin login used Filament's built-in 5-attempt throttle until PR 5. `docs/data-model.md` matches these columns. At the time of PR 2 the sabre principal backend, DAV routes, and calendars or address books were not built. The backend and the storage tables arrived in PR 3a. DAV routes arrived in PR 3b.

### PR 3a — DAV foundation: schema, backends and server factory (Effort: Medium) — ✅ done

PR 3 is split into 3a, 3b and 3c so that each part is reviewable and nothing unprotected is ever merged. **3a exposes no HTTP route.** The order is fixed: 3a → 3b → 3c.

Start with a short spike (throwaway branch) to prove Laravel → sabre/dav → DAVx5 login works before writing 3a.

- Use the already-installed sabre/dav 4.7.1 (no new dependency)
- **Laravel migrations for the sabre/dav schema** (sabre ships only SQL files; it creates nothing itself) on both SQLite and PostgreSQL: `calendars`, `calendarinstances`, `calendarchanges`, `calendarobjects`, `calendarsubscriptions`, `schedulingobjects`, `addressbooks`, `addressbookchanges`, `cards`, `propertystorage`, `locks`
  - **Do not create** sabre's own `principals`, `groupmembers` or `users` tables. Laravel owns identity (see [Principal strategy](#principal-strategy))
  - Port column types per database (sabre's `mediumblob` data columns are `bytea` on PostgreSQL, where PDO returns streams), keep sabre's unique indexes, and add foreign keys or an explicit cleanup so deleting a user leaves no sabre rows behind
  - Update [data-model.md](../data-model.md) in the same PR: it currently says sabre manages these tables itself
  - Test on both databases: PUT an event and a contact, GET them back unchanged, and sync-collection reports the change
- PDO backends reusing Laravel's connection. SQLite: WAL mode and a busy timeout through the connection options. WAL needs a **local filesystem**, so note in the guide that the SQLite file must not sit on an NFS or SMB share (relevant to TrueNAS datasets)
- Custom **principal backend** reading the existing Laravel `principals` table (built in PR 2; see [Principal strategy](#principal-strategy)). Implement the whole sabre interface, not only the lookups: principals by prefix and path, `findByUri` (`mailto:` address), `searchPrincipals`, group membership (always empty in Version 1), and `updatePrincipal` (refuse or limit to display name)
- Plugins and node tree: **register them in one place** (a `DavServerFactory` or similar) that builds the sabre tree (principals, calendars and address books roots) and loads the CalDAV, CardDAV and sync-collection plugins, reading `config('nexusdav.features.caldav' | 'carddav' | 'tasks' | 'notes')`, all On by default, so PR 6 only has to supply the stored values. The factory is built here and used by 3b
- Principal path: use `principals/{username}` as shipped, and make calendars and address books `calendars/{username}` and `addressbooks/{username}`; fix [dav-endpoints.md](../dav-endpoints.md) and the guide to match (done)
- Deleting a user removes the user's sabre rows (calendars, instances, objects, changes, address books, cards, locks, property storage)
- Tests: both databases; the schema round trip above; principal backend methods (lookups, `mailto:` search, empty groups); user deletion leaves no sabre rows; the factory honours the feature flags

**Done when:** an in-process test can create a calendar and an address book through the backends, PUT and GET an event and a contact, and sync them, on SQLite and PostgreSQL, with no HTTP route added.

**Done.** No `/dav` route. A Laravel migration creates `calendars`, `calendarinstances`, `calendarchanges`, `calendarobjects`, `calendarsubscriptions`, `schedulingobjects`, `addressbooks`, `addressbookchanges`, `cards`, `propertystorage` and `locks` on both databases. sabre's `principals`, `groupmembers` and `users` tables are not created. Blob columns are `bytea` on PostgreSQL and are turned back into strings. `calendars.components` is `varchar(255)` so `VEVENT,VTODO,VJOURNAL` fits. Foreign keys cascade from a collection to its objects and changes. `DavDataCleaner` deletes the user's sabre rows, locks and property storage, and escapes `_` in the username. `PrincipalBackend` reads the Laravel `principals` table: prefix and path lookup, `mailto:` via `findByUri`, `searchPrincipals`, empty groups, display-name updates written through the user, email changes refused. `CalendarBackend` and `AddressBookBackend` use Laravel's PDO. New calendars include `VTODO` and `VJOURNAL` only while those flags are on. `DavServerFactory` builds the `principals`, `calendars` and `addressbooks` roots, loads CalDAV, CardDAV, sync, locks and property storage from `config('nexusdav.features.*')`, sets the base URI to `/dav/`, and does not load auth, `DAVACL` or the HTML browser. SQLite uses WAL and a 5 second busy timeout. The guide says the SQLite file must not sit on NFS or SMB. Checked in-process on SQLite and PostgreSQL: event and contact round trip, sync-collection, principal methods, user deletion, and the feature flags.

### PR 3b — HTTP bridge, authentication and isolation (Effort: Extra High) — ✅ done

This is the security core and the critical path. **Authentication and isolation ship together**: no merged state may authenticate users without separating them. Collections needed by the tests are created through the backends as fixtures (home-collection creation for real users is 3c).

- Custom **Basic auth backend** (`AbstractBasic`):
  - accepts username **or** email plus an app password, reusing `AppPasswordService` (lookup by public ID, SHA-256 compare, `revoked_at` / `expires_at` / `last_used_at` rules). Do not add a second lookup path
  - the token must belong to the user who is logging in; a valid token with someone else's username fails
  - **always return the canonical username**, so a login with an email still resolves to `principals/{username}`, not `principals/{email}`
  - the main password is never accepted; the failure reasons feed Diagnostics (PR 5)
  - **checks the user's `is_active` flag on every request**, with no cached result, so disabling a user locks them out on the next request. Admins get no extra DAV rights: an admin reaches only their own data
- `DavController` bootstraps the sabre/dav Server for `/dav/*`:
  - register the routes with the DAV methods listed explicitly (`Route::any` covers only the standard verbs, so it would not match `PROPFIND`, `REPORT`, `MKCALENDAR` and the rest) and outside the `web` middleware group, so there is no CSRF, no session, no cookie and no Laravel-added headers
  - allow all DAV methods (`PROPFIND`, `PROPPATCH`, `REPORT`, `MKCOL`, `MKCALENDAR`, `COPY`, `MOVE`, `LOCK`, `UNLOCK`)
  - make sure the `Authorization` header reaches PHP; test it through the real container and a proxy, not only Laravel's test client, which skips the web server
  - pass the request body through and stream the response back (status 207, `ETag`, `Content-Type` and `DAV` headers untouched)
  - `setBaseUri('/dav/')`; with the trusted-proxy settings from PR 1, the absolute URLs in `Destination` headers and in responses must match the public HTTPS address
- **Tenant isolation (`DAVACL`, owner-only).** Load `DAVACL` with: no unauthenticated access; no admin principals; nodes hidden from listings; and the principal collection restricted. By default sabre lets any authenticated user read every principal and search users by name or email, which would leak the user list. Do not load sabre's HTML browser plugin
- **Record DAV errors for Diagnostics:** the bridge notes every 4xx/5xx response on `/dav/*` (method, path, status, user) so PR 6 can store them. Put the hook here, since the bridge is built here; PR 6 adds the table writes and the page
- `.well-known/caldav` and `.well-known/carddav` redirects, with no authentication required
- Tests (raw HTTP, against both databases):
  - auth: success; wrong password; unknown user; revoked and expired token; main password refused; login by username and by email both land on the same principal; token used with another user's name fails; **disabled user is refused with a valid token and works again when re-enabled**; Basic auth does exactly one token lookup and no per-user hash scan
  - isolation: **user A gets 403/404 on user B's collections** for every method that matters (`PROPFIND`, `GET`, `PUT`, `DELETE`, `REPORT`, `MKCALENDAR`, and `COPY`/`MOVE` into B's home); `/dav/principals/` lists only the caller; principal search returns no other users; an admin cannot read another user's calendar
  - protocol: `Authorization` passes through the container; well-known redirects; `Destination` URLs correct behind `X-Forwarded-Proto`
**Done when:** a client can authenticate with an app password, sees only its own principal, and another user's data is unreachable by every method tested above. Nothing is reachable without authentication except the `.well-known` redirects.

**Done.** `/dav/{path}` is registered outside the `web` group with the DAV methods listed explicitly. `DavController` passes the body through and returns the sabre response, including status 207, `ETag`, `Content-Type` and `DAV`. `DavAuthBackend` accepts a username or email plus one app-password token, looks that token up once by public id, and returns `principals/{username}`. The account password is refused. Failure reasons stay on the backend; the client gets a plain 401. `is_active` is read from the database on every request. `DavServerFactory` loads auth and owner-only `DAVACL` (no unauthenticated access, no admin principals, hidden listings, principal search and listing limited to the caller) and does not load the HTML browser. Another user's collections are 403 or 404 for `PROPFIND`, `GET`, `PUT`, `DELETE`, `REPORT`, `MKCALENDAR`, and `COPY`/`MOVE`. `/.well-known/caldav` and `/.well-known/carddav` redirect to `/dav/` with no authentication. `DavErrorNote` keeps method, path, status and user for 4xx/5xx on `/dav/*` and writes nothing. Checked on SQLite and PostgreSQL, and through the FrankenPHP container plus a proxy: `Authorization` reaches PHP, and a `Destination` of the public HTTPS address is accepted behind `X-Forwarded-Proto`. Home collections on user create remain PR 3c.

### PR 3c — Home collections and client discovery (Effort: High) — ✅ done

- Principal + home collections: `UserService` already creates the principal; extend it to create the home collections (`calendars/{username}`, `addressbooks/{username}`) in the same transaction, and remove them on delete. Back-fill the first admin and any existing users
- Check that the properties DAVx5 needs for discovery are served (`current-user-principal`, `calendar-home-set`, `addressbook-home-set`, supported components, `sync-collection`), and fix what is missing
- Tests: creating a user creates both homes; back-fill is idempotent; deleting a user removes them; discovery PROPFINDs return the expected properties for the owner and nothing for anyone else
- Manual smoke test with DAVx5: login and discovery

**Done when:** DAVx5 authenticates, completes discovery, and shows that user's own (empty) calendar and address book homes.

**Done.** Creating a user creates the calendar home and the address book home in the same transaction as the principal, and deleting the user removes them. `nexusdav:prepare` back-fills homes for existing users, and a second run does not insert another principal. Discovery PROPFIND returns `current-user-principal`, `calendar-home-set`, `addressbook-home-set`, supported components and `sync-collection` to the owner only. `inbox`, `outbox` and `notifications` are not calendars. Checked with raw HTTP on SQLite and PostgreSQL. DAVx5 was not run. The default calendar and address book inside those homes are PR 4.

### PR 4 — Collections in admin panel (Effort: High) — ✅ done

- Create calendar (URI slug **prefilled with a default**, display name, description, color validated as `#RRGGBB`, VTODO / VJOURNAL flags). URI slugs are validated, URL-safe and unique per user
- Create address book (URI slug, display name, description)
- **Edit and delete** calendars and address books (matches the [Users page design](../design/admin-users.md))
- Show a user's calendars and address books in the Users page ([design](../design/admin-users.md)): the user details (principal, counts of calendars, objects, address books, contacts), a calendars table (URI, name, objects with task and note indicators, DAV path, edit/delete) and an address books table (URI, name, contacts, edit/delete). Use the real `/dav/...` path, not `/dav.php/`
- **Default calendar and address book on user creation** (extend `UserService`; existing users, including the first admin, get theirs from a one-off migration or command)
- Call sabre/dav backends to create the collections
- Tests: create/edit/delete collections, slug validation, collections visible via DAV
- Manual smoke test with DAVx5: sync a calendar and an address book end to end

**Done when:** admin can create a calendar and address book for a user, they appear via DAV, and DAVx5 can sync them.

**Done.** The user edit page shows the principal and the counts of calendars, objects, address books and contacts. The calendars table shows URI, name, object count with TASKS and NOTES when those components are enabled, the `/dav/calendars/{username}/{uri}/` path, and edit and delete. The address books table shows URI, name, contact count, and edit and delete. A new calendar takes a URL-safe URI (prefilled with the first free slug), display name, description, an `#RRGGBB` color, and tasks and notes. `inbox`, `outbox` and `notifications` are rejected. `2024` is a valid calendar. `VTODO` and `VJOURNAL` are stored on a new calendar only while that flag is on. A new address book takes a URI, display name and description. Create, edit and delete go through `CalendarBackend` and `AddressBookBackend`. Delete then uses `DavDataCleaner` so property storage and locks are removed with the objects and changes. `UserService` creates a default calendar (`default`, `Calendar`, `#1A73E8`) and address book (`default`, `Contacts`) in the same transaction as the principal, and sets `users.collections_provisioned`. `nexusdav:prepare` claims that flag once for users who have never been provisioned, then `insertOrIgnore`s the default rows. A later prepare does not insert them again, so deleting a default stays deleted, and a second run does not abort a PostgreSQL transaction. The panel can manage another user's collections. A DAV client still cannot read them. Checked on SQLite and PostgreSQL with Filament create, edit and delete, slug and color validation, and raw HTTP `PROPFIND`, `GET` and `PUT` as the owner (403 or 404 for another user). No admin server was running, so the page was not clicked in a browser. No device was attached, so DAVx5 was not run.

### PR 5 — Rate limiting and brute-force protection (Effort: Medium) — ✅ done

Moved before Settings so there is something to configure.

- Apply to admin login and DAV Basic Auth
- Limit by **IP plus username**, so an attacker cannot lock a real user out from elsewhere
- Defaults in `config/nexusdav.php`; thresholds become editable on the Configuration page in PR 6
- Log failed attempts **and store them in one `diagnostic_events` table** (name may change), because logs are stdout only and the Diagnostics page (PR 6) could not read them otherwise:
  - Columns: time, `source` (`admin` or `dav`), `kind` (`auth_failed` or `http_error`), IP, username attempted, method, path, status, reason, repeat count
  - Record a failure only when credentials were actually sent and rejected. A DAV request with no `Authorization` header (the normal first 401 challenge) is not recorded
  - Reason for DAV auth failures: unknown user, bad secret, revoked, expired, user disabled, or main password used. The client always gets a plain 401; the specific reason is for the admin only
  - Never store passwords, token secrets or request bodies. For app passwords store only the public ID
  - Collapse repeats (same source, IP, username, path and reason within a minute) into one row with a counter
  - Prune old rows **without a scheduler** (there is none in the container): delete expired rows opportunistically when a new row is written, at most once per interval. The retention period arrives in PR 6
- Tests: throttling on both entry points, successful login from another IP is not blocked by someone else's failures, a bad DAV password is recorded with the right reason, a request with no credentials is not recorded, repeated failures collapse into one row

**Done when:** repeated bad passwords are throttled on both the admin login and `/dav/`.

**Done.** Admin login and DAV Basic auth are limited by IP plus the attempted username. Defaults in `config/nexusdav.php` are 5 attempts and a 60 second window. Filament's IP-only limiter is not the lock, so one address cannot lock every account, and failures from one address do not block that username from another. A rejected admin login and a rejected DAV login are written to `diagnostic_events` as `auth_failed` and to the log (source, IP, username, path, status, reason). The DAV reason is the one `DavAuthBackend` already set. The client still gets a plain 401, with the reason left out of the body and headers. A DAV request with no credentials is not stored. An app password row stores the public id only, never the secret, the account password, or the body. Repeats of the same source, kind, IP, username, path, and reason in the same epoch minute share one row and a `repeat_count`. Rows older than 30 days are deleted when a new row is written, at most once an hour. There is no scheduled command. `kind` accepts `http_error`, but this PR does not write those rows, so a DAV 401 is stored once. Checked on SQLite and PostgreSQL.

### PR 5a — Security hardening from the code review (Effort: Medium) — ✅ done

Fixes R11 to R17 from the [code review](#code-review-of-pr-1-to-pr-5-31-october-2026). It comes before PR 6 because PR 6 builds the Configuration and Diagnostics pages on the limiter settings and the `diagnostic_events` rows that this PR changes.

- **Cap unauthenticated writes (R11):** once one client address has more than a set number of failures in the window, collapse further failures from it into one summary row with a counter (reason `many usernames`), and stop writing one log line per attempt
- **Per-address limit and the proxy default (R12):**
  - add a per-address limit on top of the IP-plus-username limit, applied only when the client address is real: a direct connection, or a peer listed explicitly in `TRUSTED_PROXIES`
  - with `*`, keep the per-address limit off and have `nexusdav:prepare` print a warning that the limiter sees the proxy's address
  - decide whether the default stays `*` or becomes the private ranges (`10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1`), and update the guide
- **Input limits (R13):** check client values before the insert on both databases. Calendar and address book display names are at most 255 characters, `calendar-color` at most 10, collection and resource names at most 200. MKCALENDAR, MKCOL, PROPPATCH and PUT then answer 400 or 403 instead of 500, and SQLite no longer stores what PostgreSQL would refuse
- **Passwords (R14):** at least 12 characters for any password set in the panel and for `ADMIN_PASSWORD`. A shorter `ADMIN_PASSWORD` stops the first start with a clear message. Add the confirmation field from the [Users design](../design/admin-users.md)
- **Email (R15):**
  - store emails in lower case, and lower-case the typed email at admin login
  - a migration lower-cases existing emails, stops with a clear message if two accounts would collide, and adds a unique index on `lower(email)`
  - the case-insensitive check added in the review then becomes a backstop
- **Panel headers and redirects (R16):**
  - admin responses send `X-Frame-Options: DENY` (or CSP `frame-ancestors 'none'`), `Referrer-Policy: same-origin` and `X-Content-Type-Options: nosniff`; `/dav` responses are left alone
  - `.well-known` redirects are built from `APP_URL`
- **Login timing (R17):** a DAV login for an unknown username still performs the public-id lookup, so the response time does not show whether the username exists
- **Tests on both databases:**
  - many usernames from one address make one summary row
  - the per-address limit applies to a direct client and is off behind `*`
  - each overlong field gets a 4xx, never a 500
  - a short or unconfirmed password is refused in the panel and at first start
  - admin login works with the email in any case
  - the migration lower-cases and indexes emails
  - panel responses carry the headers and `/dav` responses do not
  - `.well-known` uses `APP_URL`

**Done when:**
- A flood of random usernames from one address cannot grow `diagnostic_events` without bound.
- No client-supplied field can cause a 500 on PostgreSQL.
- Panel passwords meet the minimum.
- The admin can sign in with the email in any case.
- The guide states how to set `TRUSTED_PROXIES` behind a proxy.

**Done.** Tests are in `tests/Feature/SecurityHardeningTest.php`; the suite passes on SQLite and PostgreSQL 17.
- **Proxy default (R12):** `TRUSTED_PROXIES` defaults to the private ranges (decision 17).
  - The value is read from `config('nexusdav.trusted_proxies')` at request time by `App\Http\Middleware\TrustProxies`, so it survives a cached config. Compose passes an empty value when the variable is unset.
  - With `*`, `nexusdav:prepare` prints a warning.
- **Per-address limit (R12):** at most 20 failures per address in the window (`rate_limit.max_attempts_per_address`) across admin and DAV logins, on top of the IP-plus-username limit. It is off when every peer is trusted.
- **Diagnostics cap (R11):** one address gets at most 10 rows per minute (`diagnostics.max_rows_per_address`). Further failures from it go on one `many usernames` row, which is logged once.
- **Input limits (R13):** `DavLimits` checks client values in `CalendarBackend`, `AddressBookBackend` and `PropertyStorageBackend` before writing.
  - It covers collection, resource, subscription and UID names, display names, `calendar-color`, `calendar-order`, `refreshrate` and dead property names.
  - MKCALENDAR, MKCOL and PUT answer 400. PROPPATCH answers 403 for the property, 424 for the rest, and writes nothing.
  - `locks.owner` is `text` on PostgreSQL. sabre already forces the lock timeout to 30 minutes.
- **Passwords (R14):** at least 12 characters in `UserService`, the user form (with a confirmation field) and for `ADMIN_PASSWORD` at first start.
- **Email (R15):** stored in lower case by a model mutator. The admin login lower-cases the typed email. Migration `2026_11_01_000000_lowercase_user_emails` lower-cases existing users and principals, stops with the colliding addresses if any, and adds `users_email_lower_unique` on `lower(email)`.
- **Headers (R16):** `SecurityHeaders` adds `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `Referrer-Policy: same-origin` and `nosniff` to the panel and the `web` group. `/dav` and `/health` are left alone. `.well-known` redirects to `APP_URL`.
- **Login timing (R17):** an unknown DAV username still runs the public-id lookup.

### PR 5b — Container, CI and performance (Effort: Medium) — ✅ done

Fixes R18 to R22. It carries the PHP security check that Follow-up 1 marked "do first", so it should not wait for PR 8. It can run in parallel with PR 5a.

- **CI image job (R20, Follow-up 1):** build the image with `--pull` and fail if PHP is older than 8.4.27. Start it with SQLite, then check:
  - `/health` returns 200;
  - `/` redirects to `/admin`;
  - a DAV PROPFIND with an app password returns 207;
  - a bad login appears in `docker logs` and no file appears under `storage/logs`
- **Production PHP (R19):** copy `php.ini-production` into the image, set `expose_php=Off`, and enable and verify OPcache. `validate_timestamps` can be off, since the code in the image never changes
- **Caches at start (R19):** after `nexusdav:prepare`, run `php artisan optimize` (config, routes, events, views) and `php artisan filament:optimize`
  - `bootstrap/app.php` reads `TRUSTED_PROXIES` with `env()` outside a config file, and a cached config does not see that value
  - move it into `config/nexusdav.php` first
  - PR 6 must apply settings stored in the database at runtime, not through `env()`
- **Property storage (R18):** set `PropertyStorage\Plugin::$pathFilter` so dead properties are stored only on collections (homes, calendars, address books), not on each event or card
  - before that, check that DAVx5, Thunderbird and Apple do not keep dead properties on single objects
  - if one does, keep them and replace the per-resource query with one batched query instead
- **Boot backfill (R21):** only users with `collections_provisioned = false` or no principal
- **File ownership (R22):**
  - the entrypoint re-owns `/data/database` when `PUID` or `PGID` changed
  - the guide runs artisan commands with `docker compose exec -u nexusdav ...`, including the PR 7 reset command
- **Measure before and after:** time and query count for a depth-1 PROPFIND on a calendar with 500 events, and response time of `/admin/login`

**Done when:**
- CI builds and smoke-tests the image and fails on an old PHP.
- A depth-1 PROPFIND no longer makes one `propertystorage` query per event.
- The container serves requests from cached config and OPcache.
- The measurements are written into this section.

**Done.** Tests are in `tests/Feature/DavPerformanceTest.php`; the smoke test passes against a freshly built image.
- **CI image job (R20):** a new `image` CI job builds with `--pull` and runs `docker/smoke-test.sh`. The script fails on a PHP older than 8.4.27 and checks:
  - `expose_php` off, OPcache on, `validate_timestamps` off;
  - `/health`, no permission errors at start, and the `/` redirect;
  - panel headers with no `X-Powered-By`;
  - cached config and routes;
  - an app-password PROPFIND (207);
  - a failed login in `docker logs`, and nothing under `storage/logs`.
- **Production PHP and caches (R19):**
  - The image uses `php.ini-production` plus `docker/php.ini`: `expose_php=Off`, `variables_order=EGPCS` (so `$_ENV` keeps the container variables), and OPcache with `validate_timestamps=0`.
  - The entrypoint runs `php artisan optimize` after `nexusdav:prepare`; that already includes `filament:optimize`.
  - `TRUSTED_PROXIES` moved to config in PR 5a.
- **Property storage (R18):** batched rather than filtered with `pathFilter`, because the client check could not be run here.
  - `PropertyStorageBackend` loads the dead properties of all children of a collection in one query and keeps exact-case paths. SQLite's `LIKE` ignores case.
  - Dead properties on single events and cards still work.
- **Boot backfill (R21):** `backfillHomeCollections()` visits only users with `collections_provisioned = false` or no principal.
- **File ownership (R22):**
  - The entrypoint re-owns `/data/database`.
  - The guide runs artisan commands with `docker compose exec -u nexusdav`.
  - Caddy's state moved from `/data` (the data volume, not writable by `nexusdav`) to the user's home, which removes a `permission denied` error at every start.

**Measurements** (SQLite; the container figures are averages of 20 requests on the same machine):

| | Before | After |
|---|---|---|
| Depth-1 PROPFIND on 500 events, database statements | 512 (501 on `propertystorage`) | 13 (2 on `propertystorage`) |
| Depth-1 PROPFIND on 500 events, container | 43.1 ms | 25.0 ms |
| `GET /admin/login`, container | 24.5 ms | 17.2 ms |

### PR 6 — Dashboard, Settings, Configuration and Diagnostics pages (Effort: High) — ✅ done

- **Dashboard** (landing page after sign-in): the default Filament `Dashboard` is already registered; replace the default `AccountWidget` and `FilamentInfoWidget` with four widgets, as described in the [Dashboard design](../design/admin-dashboard.md):
  - **Server status:** application, database (type shown, never a host, path or error text) and the NexusDAV version. Move the database check out of the `/health` controller into one shared class so the Dashboard and `/health` cannot disagree. Add a version value to `config/nexusdav.php`, filled at image build time (PR 8)
  - **Services:** On/Off for the four switches, with a lock icon and tooltip "Set by environment" when an env variable decides
  - **Counts:** users (caption: disabled count), calendars, address books, active app passwords, events and tasks, notes, contacts. Events, tasks and notes come from the sabre `calendarobjects` component type. Hide a card whose service is Off; cache the numbers for about a minute; format with thousands separators
  - **Needs attention:** failed logins and DAV errors in the last 24 hours from `diagnostic_events`, plus a failing database check; quiet ("Nothing needs attention") when there is nothing; links to Diagnostics
  - No polling, no charts. One widget failing shows "Unavailable" in its card without breaking the page
- **Diagnostics** (simple, read-only), a menu item next to Users, Settings and Configuration:
  - Recent failed logins: `auth_failed` rows from `diagnostic_events` (PR 5), admin panel and DAV, with a source filter
  - Latest DAV errors: `auth_failed` rows from DAV (so a 401 shows its reason) plus `http_error` rows. Record 4xx/5xx responses on `/dav/*` as `http_error` (method, path, status, user, time; no bodies or credentials) from the DAV bridge in PR 3b. Skip 401 here, since PR 5 already records it with a reason. Since the code review, a request for another user's resources is a 404 (R3), so it shows as 404, not 403
  - Apply the per-address cap from PR 5a to `http_error` rows too, so a flood of 404s cannot grow the table without bound
  - App passwords across all users (user, device, created, last used, revoked or expired), with revoke
  - Retention: expired rows are removed by the opportunistic pruning from PR 5 (no scheduled command); the period is a Configuration setting
  - WebDAV-Push subscriptions come in Version 1.x
- **Settings** page ([design](../design/admin-settings.md)): enable/disable CalDAV, CardDAV, Tasks, Notes; logging level
- **Configuration** page ([design](../design/admin-configuration.md)): server time zone, time format (12/24-hour), week start day; rate limiting thresholds; session settings (lifetime = Laravel's inactivity timeout, secure cookies, **on by default when `APP_URL` is `https`**, R23); diagnostics retention
- Server time zone is the fallback when `users.timezone` is null (`config/nexusdav.php` has a fixed `UTC` today), the default zone for new calendars, and the display zone in the panel. Time format and week start apply to the panel
- Settings and Configuration are separate pages with separate menu items; the Dashboard's "Manage in Settings" link points to the first
- Store non-boot settings in the database; env values win and are read-only (show the same lock marker as the Dashboard). Changes take effect **without a restart** unless a setting's documentation says otherwise
- Settings are loaded into config at boot and **cached**, so DAV requests do not query them each time; saving a setting clears the cache. Apply them at runtime, after PR 5b's cached config is loaded, not through `env()`
- Define what each switch does: which sabre plugins load (through the single registration point from PR 3a), the response when a feature is off (plugin not loaded; requests return 403 with a clear message), and what happens to existing collections. Today a switched-off CalDAV or CardDAV root is simply not loaded, so its paths answer 404. Add the 403 that decision 8 asks for (R24)
- Tests: setting precedence (env wins and is read-only), each switch changes DAV behaviour, a setting change applies without restart, a failed login and a DAV 404 appear on Diagnostics, a DAV 401 with a bad password appears in both lists with its reason, pruning removes old rows, only admins can open any of these pages, the Dashboard counts and Needs attention match Diagnostics, a disabled service hides its count card, the database check failing shows only `Unavailable`

**Done when:** admin can change these settings, and each one has a test or manual check showing the effect (for example, disabling CardDAV makes address book requests fail; a new rate limit applies without restart). Diagnostics shows a bad login, a failing DAV request and every app password, and an app password can be revoked from it. The Dashboard shows the status, services, counts and attention summary described in its design, with none of Filament's default widgets.

If this gets too large, split it: Dashboard + Settings + Configuration as PR 6, Diagnostics as PR 6b.

**Decided:** use Laravel's session lifetime (minutes of inactivity before sign-out). There is no separate idle timeout or absolute limit in Version 1.

**Done.** Not split into 6 and 6b. Tests are in `tests/Feature/SettingsTest.php` and `tests/Feature/AdminPagesTest.php`. The suite passes on SQLite and PostgreSQL 17, and the smoke test passes against a freshly built image. All four pages were also opened in a browser against that container.
- **Settings storage:**
  - A `settings` table and `SettingsService`, which defines each setting's config key, environment variable, type and limits.
  - The config files read every variable, and `config('nexusdav.env_set')` records which are set. Those fields are locked: shown read-only with a lock and "Set by <variable>", and never written on save.
  - At every boot `SettingsService::apply()` puts saved values into config, after the cached config is loaded. Values that are not valid fall back to the default.
  - Saved values are cached in the file cache, so a DAV request does not read the table; saving clears that cache.
  - In the container, a setting saved from a separate process changed the next DAV answer with no restart.
  - `.env.example` no longer sets `LOG_LEVEL` and `SESSION_LIFETIME`, which would have locked them.
- **Settings page:** CalDAV, CardDAV, Tasks, Notes, log level.
  - CalDAV or CardDAV off now answers 403 "CalDAV is turned off on this server." (or CardDAV) instead of 404 (R24, decision 8). Data is kept.
  - Tasks or Notes off drops `VTODO` or `VJOURNAL` from each calendar's `supported-calendar-component-set`, so sabre refuses new or changed items with 403. Existing ones stay readable and deletable.
  - The log level applies to every log channel except Slack.
- **Configuration page:** time zone, time format, week start, the three rate-limit values, session lifetime, secure cookies, diagnostics retention.
  - The time zone is the fallback for users without one, the panel's display zone, and the `calendar-timezone` of new calendars. `CalendarTimezone` builds a VTIMEZONE from PHP's transitions, and the owner's own zone wins when set.
  - Time format and week start apply to tables, infolists and date pickers through `configureUsing`.
  - Secure cookies are on by default when `APP_URL` is https (R23) and can be turned on only over HTTPS.
- **Dashboard:** Filament's default widgets are gone. Four schema-based widgets are discovered: server status, services, counts and needs attention.
  - Server status and `/health` use `App\Support\DatabaseHealth`. A failure shows only "Unavailable".
  - The version comes from `NEXUSDAV_VERSION`. It is an image build argument, which CI sets to `ci+<commit>`; the release workflow (PR 8) sets the release version.
  - Counts are cached for 60 seconds, and a card for a service that is off is hidden.
  - Needs attention and the Diagnostics heading share `DiagnosticsSummary`.
  - There is no polling. A card that fails to load shows "Unavailable" and the page keeps working.
- **Diagnostics page:** three table widgets.
  - Recent failed logins, with a source filter.
  - Latest DAV errors: DAV `auth_failed` rows plus `http_error` rows. `DavController` now stores 4xx and 5xx answers other than 401, under the same per-address cap with a `many requests` summary row.
  - App passwords of all users, with a status filter and revoke.
  - Unexpected exceptions inside DAV requests are now logged with their stack trace.

### PR 7 — Admin password reset command (Effort: Low) — ✅ done

- Artisan command `nexusdav:reset-admin-password`, as the [guide](../guide.md#resetting-the-admin-password) describes it: `--email=` picks the admin; the new password is asked with hidden input, or generated and printed once; no web page and no email
- The new password follows the minimum length from PR 5a. The guide runs the command as `docker compose exec -u nexusdav ...` (PR 5b), so it does not leave root-owned files
- Signs out sessions (the panel already uses `AuthenticateSession`; the command changes the password hash or clears the sessions table), optional revoke of app passwords (`AppPasswordService` already supports revoke)
- Logged (no password in the log)
- Tests: password changes, old sessions are invalid, optional revoke works, unknown email fails cleanly, generated password is printed once

**Done when:** can reset admin password from the container shell and the old session is signed out.

**Done.** `App\Console\Commands\ResetAdminPassword`. Tests are in `tests/Feature/ResetAdminPasswordTest.php`; the suite passes on SQLite and PostgreSQL 17, and the image smoke test now runs the command.
- **Choosing the admin:** `--email=` (any case) picks the admin. It may be left out when there is one admin; with more than one, or with a non-admin or unknown email, the command fails and changes nothing.
- **The new password:**
  - It is asked twice with hidden input and must be at least 12 characters (PR 5a). Mismatched or short input changes nothing.
  - `--generate` prints a 24-character password once; a non-interactive run needs it.
  - The password is saved through `UserService`, under the admin lock.
- **Sessions:**
  - The admin's rows in the `sessions` table are deleted, which takes effect with the database driver that the container uses.
  - Any other session is logged out by `AuthenticateSession` on its next request, because the password hash changed. Both behaviours are tested.
- **App passwords:** `--revoke-app-passwords` revokes them all. Without it, an interactive run asks (default no) and a non-interactive run keeps them.
- **Logging:**
  - It logs "Admin password reset from the command line" with the username and the counts, never the password.
  - A disabled admin gets a warning.
  - The smoke test runs the command with `docker exec -u nexusdav --generate`, checks the new password, and checks that the log line has no password.
- **Log line placement:** a command run with `docker exec` cannot write to the container's main output. The server process drops privileges and is not dumpable, so `/proc/1/fd/2` is closed to it. The log line therefore goes to the command's own output, and the guide says so. A persistent audit trail is the Version 2.x audit log.

### PR 8 — Release polish and documentation (Effort: High) — ✅ done (one maintainer step left)

- Logging: stdout, with request ID, method, path, status and user; no secrets. The DAV error capture for Diagnostics (PR 6) reuses the same fields
- Build and publish the image (GitHub Container Registry, amd64 + arm64); pin the image tag in the Compose examples. Build from a freshly pulled base and **fail the release if PHP is older than 8.4.27** (CVE-2026-109618 / CVE-2026-109619). Pass the release tag as the `NEXUSDAV_VERSION` build argument (added in PR 6; CI already passes `ci+<commit>`) so the Dashboard shows it
- Ready-to-use reverse proxy configs (nginx, Caddy, Traefik) including the `.well-known` fallbacks
- Manual test with DAVx5 (calendar, contacts, tasks via a tasks app); also try Thunderbird and Apple Calendar / Contacts; optionally run `litmus` / CalDAVTester
- Update the client compatibility table with the tested versions
- **Documentation sync** — the guide fixes (variable table, health and logging, roadmap Diagnostics, `APP_KEY` backup wording, principal path, `/dav/files/` marked Version 1.x) are already done. What remains:
  - [architecture.md](../architecture.md): list Diagnostics next to Filament in the components table (done after PR 7; the logging row was corrected to stderr in the code review)
  - [design/admin-users.md](../design/admin-users.md): `/dav.php/` → `/dav/` (done in PR 4)
  - [data-model.md](../data-model.md): add `diagnostic_events` and the settings table (done in PR 5 and PR 6; the sabre tables and the `principals` ownership were updated in PR 3a)
  - [design/admin-configuration.md](../design/admin-configuration.md): add the diagnostics retention setting (done in PR 6)
  - [apps/calendar.md](../apps/calendar.md) vs [dav-endpoints.md](../dav-endpoints.md): whether clients can create collections (done: they can, decision 7)
  - guide "What is logged" and Health check text: update once request ID, method, path, status and user are logged (done)
  - [apps/admin.md](../apps/admin.md) and the [design notes](../design/): re-check Diagnostics, Dashboard, Settings, Configuration and Users against what was built (done after PR 7: the design notes now describe the pages as built; re-check after any later change)
- Update the docs with any other deviations from the plan

**Done.** Version **0.1.0**. Tests pass on SQLite (154) and on PostgreSQL 17 (153, 1 skipped); Pint, PHPStan and `actionlint` are clean; the image smoke test passes.

Left for the maintainer, because it needs devices outside the repository:
1. **Publish:** done. `v0.1.0` and `v0.1.1` are published (see [CONTRIBUTING.md](../../CONTRIBUTING.md#releasing)).
2. **Real clients:** DAVx5 4.5.21 (calendar, contacts, tasks and notes through jtx Board) and Thunderbird 159.0 (calendar and contacts) were tested by hand on 0.1.1 with PostgreSQL 18 behind Caddy, and the guide's compatibility table has the versions. Still open: Apple clients, and Thunderbird tasks.

What was built:
- **Request log (`App\Http\Middleware\RequestLog`, first global middleware):**
  - Every request gets a 16-hex-character ID, generated by the app. An incoming `X-Request-Id` is not trusted. The ID goes into Laravel's `Context`, so every log line of the request carries it.
  - The response sends it back as `X-Request-Id`, and `DiagnosticEventWriter` stores it in the new `diagnostic_events.request_id` column (first occurrence). It shows on both Diagnostics tables: visible for DAV errors, toggleable for failed logins, and searchable.
  - One `info` line per request: method, path without the query string, status, user, client address (the same `AuthThrottle::clientIp()` as Diagnostics) and duration. A healthy `/health` or `/up` is not logged.
  - The DAV user comes from `DavController` through a request attribute. The admin user comes from the session guard only when it is already resolved, so logging never queries the database.
  - `DavErrorNote`, which only held DAV errors in memory, is removed; its no-secrets tests now check the log lines. New tests are in `tests/Feature/RequestLogTest.php`.
- **Release image:**
  - `.github/workflows/release.yml` runs on a `v*` tag. It calls `ci.yml` (now reusable, image job skipped), validates the tag, builds amd64 from a pulled base and runs `docker/smoke-test.sh`, then builds and pushes `ghcr.io/<owner>/nexusdav` for amd64 and arm64 with `NEXUSDAV_VERSION` set to the version. Last, it runs the published image on both platforms and prints their PHP version.
  - The Dockerfile now fails the build when PHP is older than `MIN_PHP_VERSION` (8.4.27), so a stale base stops the release on every platform, not only in the amd64 smoke test.
  - Tags: exact version, `<major>.<minor>`, `<major>` from 1.0, and `latest` for stable versions only.
  - The arm64 build was not run locally (no emulation on the dev machine); the release workflow is its first run.
- **Compose:**
  - `compose.yaml` pinned `ghcr.io/offsyanka99/nexusdav:0.1.0` at 0.1.0; later releases move it to the newest version (`0.1.2` at the time of writing).
  - `compose.build.yaml` (added last) builds from the checkout as `nexusdav:local`.
  - CI branch pushes no longer run on tags; the release workflow runs the checks instead.
- **Reverse proxy files in [docs/proxy](../proxy/):**
  - nginx server block, `Caddyfile` with `compose.caddy.yaml`, and Traefik labels in `compose.traefik.yaml`.
  - All of them have the `.well-known` fallbacks and 128 MB bodies (the app's `post_max_size`). The Caddy and Traefik overrides unpublish port 8080 with `!reset`.
  - Each was run in front of the image over HTTPS with test certificates. Checks:
    - `.well-known` answered by the proxy itself, not the app;
    - `PROPFIND` with the email login;
    - `MKCALENDAR`, `REPORT` and `DELETE`;
    - a 5 MB vCard;
    - `https` URLs in the admin panel;
    - the client address in the log, not the proxy's.
  - Traefik's `redirectregex` answers 308 to non-GET methods, which keeps a `PROPFIND` a `PROPFIND`.
- **Client tests:**
  - The smoke test now also checks the request log line and the request ID, and that no secret is in the log. It runs a client round trip: PUT and GET an event, PUT a task, `sync-collection`, PUT and GET a contact, `MKCALENDAR` and `DELETE`. It also checks the `.well-known` redirect. This runs on every CI build.
  - Against the image, **vdirsyncer 0.21.0** (two-way CalDAV and CardDAV sync from the server URL) passed.
  - **python caldav 3.7.0** also passed: discovery from `/.well-known/caldav`, `MKCALENDAR`, a recurring event expanded by the server, a task completed, a note, and incremental sync of a change and a deletion.
  - `litmus` was not run: it tests a generic WebDAV file server, which Version 1 is not (Files is Version 1.x).
- **Docs:**
  - Guide: status, Quick start and the build override, the proxy section with the tested files, the client compatibility table with what was tested, upgrade by changing the pinned tag, health and "What is logged", and troubleshooting by request ID.
  - The app pages and [dav-endpoints.md](../dav-endpoints.md) now say clients may create their own collections (decision 7).
  - Also updated: README, CONTRIBUTING (release steps), SECURITY, [architecture.md](../architecture.md), [data-model.md](../data-model.md) (`request_id`), [apps/admin.md](../apps/admin.md) and the Dashboard design note.

**Done when:** DAVx5 can sync a calendar and address book created in the admin panel on a published, pinned image, and the docs match what was built.

## Suggested order and dependencies

```
PR1 → PR2 → PR3a → PR3b → PR3c → PR4 → PR5 → { PR5a, PR5b } → PR6 → PR7 → PR8
```

PR 3b is the critical path. Everything after it depends on a working, isolated DAV server. Possible overlap: PR 4's admin forms only need the 3a backends, so work can start after 3a, but PR 4 merges after 3c because its "appears via DAV" check needs the bridge and the home collections. PR 5 (DAV rate limiting) needs the 3b auth backend. PR 5 comes before PR 6 because the Configuration page edits the rate-limit settings.

The code review added two PRs and changed the order after PR 5:
- **PR 5a before PR 6.** PR 6's Configuration and Diagnostics pages edit and read the limiter settings and `diagnostic_events` rows that PR 5a changes. PR 7's reset command uses PR 5a's password minimum.
- **PR 5b runs in parallel with PR 5a.** Both land before PR 6, because PR 6 must apply database settings on top of the cached config that PR 5b introduces. PR 5b must also land before PR 8, which publishes the image. Its PHP check is the "do first" Follow-up 1.
- **PR 7** only needs PR 2 and PR 5a.

PR 3 was split into three after review because it held about a dozen deliverables, including the security core. Effort scale for the split: **3a Medium** (schema and plumbing, nothing exposed), **3b Extra High** (core protocol work and tenant isolation), **3c High** (client behaviour and discovery).

## Principal strategy

The sabre/dav PDO principal backend expects its own `principals` table (`uri`, `email`, `displayname`), while [data-model.md](../data-model.md) defines a Laravel `principals` table with different columns. **Decided (and built in PR 2):** keep the Laravel table (`principals/{username}`) and write a small custom principal backend that reads it, so there is one source of truth. `UserService` already keeps `users.display_name` / `users.email` and `principals` in sync. PR 3a added the sabre side.

## Decisions

All decisions below are confirmed.

| # | Decision | Choice |
|---|----------|--------|
| 1 | Web server in the image | **FrankenPHP in classic (non-worker) mode**: one process, one container, official image, no supervisor. Listens on unprivileged port 8080, runs as non-root with configurable `PUID` / `PGID` (TrueNAS SCALE apps typically use 568) so `/data` permissions match the host dataset |
| 2 | App password storage | 32+ random bytes, shown once, with a short public ID. Only the SHA-256 hash of the secret is stored; lookup by ID, constant-time compare. Argon2id is for human-chosen passwords, not high-entropy tokens |
| 3 | Principal table | Laravel table plus custom principal backend |
| 4 | Password used by DAVx5 | **App passwords only.** The main password is optional for non-admin users and is never accepted on `/dav/` |
| 5 | Admin username | Derived from `ADMIN_EMAIL`; admin also gets a principal |
| 6 | Default collections on user creation | Yes: one calendar, one address book |
| 7 | Client-created collections (`MKCALENDAR` / `MKCOL`) | Allowed (DAVx5 and Thunderbird use them); docs updated to match |
| 8 | Feature switch off behaviour | Plugin not loaded; requests return 403 |
| 9 | Logging | stdout only; `LOG_LEVEL` not forced by Compose |
| 10 | Username rules | URL-safe, lowercase, unique, immutable after creation |
| 11 | Pruning old diagnostic rows | Opportunistic, on write (no cron or scheduler in the single-process container) |
| 12 | Principal / home paths | `principals/{username}`, `calendars/{username}`, `addressbooks/{username}`; docs corrected to match what PR 2 shipped |
| 13 | PHP version | 8.4.27 or newer, checked in CI and before release |
| 14 | Another user's DAV resources | Answer 404, exactly like a username that does not exist (code review R3) |
| 15 | Email uniqueness | Case-insensitive, because DAV accepts the email as a login (code review R4; database index in PR 5a) |
| 16 | Container log channel | `LOG_CHANNEL=stderr` set in the image, so logs reach `docker compose logs` (code review R2) |
| 17 | `TRUSTED_PROXIES` default | The private ranges (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `127.0.0.1`, `::1`, `fc00::/7`). `*` stays possible, with a startup warning, and turns the per-address limit off (PR 5a) |
| 18 | Password minimum | 12 characters for panel passwords and `ADMIN_PASSWORD` (PR 5a) |
| 19 | Email case | Stored in lower case, unique on `lower(email)` (PR 5a) |
| 20 | Dead property storage | Batched per collection instead of limited to collections, so clients that keep properties on single objects still work (PR 5b) |
| 21 | Panel settings | Stored in `settings`, applied to config at boot, cached outside the database. An environment variable wins and locks the field (PR 6) |
| 22 | Tasks or Notes switched off | Calendars stop offering the component: no new or changed item, existing ones readable and deletable (PR 6) |
| 23 | Secure session cookie | On by default when `APP_URL` is https; can be turned on in the panel only over HTTPS (PR 6) |
| 24 | Request log and request ID | One `info` line per request (method, path without query, status, user, address, duration). The app generates the ID and never trusts an incoming one; it is returned as `X-Request-Id` and stored on Diagnostics rows. A healthy healthcheck is not logged (PR 8) |
| 25 | Releases | Tag `v<major>.<minor>.<patch>` publishes `ghcr.io/offsyanka99/nexusdav` for amd64 and arm64. First release `0.1.0`. `latest` only for stable versions (PR 8) |
| 26 | Image in Compose | `compose.yaml` pins the published image by exact version; `compose.build.yaml` builds from the checkout (PR 8) |

TrueNAS SCALE notes: run the app as a Docker Compose custom app, keep `/data` (and the PostgreSQL volume) on a dataset rather than an ephemeral volume, and publish only port 8080 behind the TrueNAS or a separate reverse proxy. Ready-to-edit files are [docs/truenas/custom-app.sqlite.yaml](../truenas/custom-app.sqlite.yaml) and [docs/truenas/custom-app.postgres.yaml](../truenas/custom-app.postgres.yaml), explained in the [guide](../guide.md#installing-on-truenas-scale).

## Out of scope for Version 1

- User portal
- Diagnostics beyond the simple Version 1 page: WebDAV-Push subscriptions (Version 1.x) and extended health, quotas, mail queue and backups (Version 2.x)
- Sharing / ACL management (note: the `DAVACL` plugin is still loaded in PR 3b to enforce **owner-only access**)
- WebDAV-Push
- Scheduling
- Email
- Files storage
- Managed attachments
- Quotas enforcement
- Multilingual UI
- `*_FILE` secrets (for example `DB_PASSWORD_FILE`). The guide calls them planned but gives no version; environment variables are enough for Version 1

## Notes

- Keep each PR small enough to review. PR 1, PR 2 and PR 8 are the likeliest to need splitting.
- Update `docs/architecture.md` and `docs/data-model.md` if implementation forces a change.
