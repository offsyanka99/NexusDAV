# Implementation plan — Version 1

Goal: a working minimal server that allows creating users, calendars and address books in the admin panel, and syncing with DAVx5 (CalDAV + CardDAV).

This plan was revised after the [review](plans/implementation-plan-v1-review.md). Items marked **(proposed)** are recommended defaults for open decisions listed under [Decisions](#decisions-to-confirm); confirm or change them before the PR that uses them starts.

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

## PR sequence

### PR 1 — Project skeleton and container (Effort: High)

- `composer create-project laravel/laravel`
- Install Filament 5, Livewire, Tailwind
- Basic `config/nexusdav.php`
- `.env.example` with the variables from the guide (including `DB_DATABASE`, `DB_USERNAME`, trusted proxy setting)
- **Full `users` schema from the start**: `username` (unique, immutable, URL-safe), `display_name`, `email` (unique), `password`, `is_admin`, `is_active`, `timezone`, `locale`, `quota_bytes`
- Dockerfile (PHP 8.4 + required extensions, non-root user, `curl` and `sqlite3` included, raised `post_max_size` / `upload_max_filesize`)
- Web server inside the image: FrankenPHP in classic (non-worker) mode, port 8080, non-root with `PUID` / `PGID` support for TrueNAS SCALE
- Entrypoint script: validate required variables and stop with a clear message if one is missing; generate `APP_KEY` on first start and store it on `/data`; run migrations
- Trusted proxy handling (`X-Forwarded-Proto`, `Host`) so `APP_URL` and generated links stay HTTPS
- Update `compose.yaml` to use the local build; add log rotation (`max-size`, `max-file`); default `LOG_LEVEL` so the Settings field can be editable later **(proposed: do not set it in Compose unless the user sets it)**
- `/health` endpoint (app + DB up, no details exposed)
- First admin creation on boot from `ADMIN_EMAIL` / `ADMIN_PASSWORD`: **idempotent** (only when no admin exists, never overwrites); derive `username` from the email **(proposed)**
- CI (GitHub Actions): `composer test`, `composer lint`, `composer analyse` on SQLite and PostgreSQL
- Tests: health endpoint, first-admin creation is idempotent, missing variable stops start

**Done when:** container starts, `/health` returns OK, admin can log in, CI is green on both databases, restart does not recreate or change the admin.

### PR 2 — Users, app passwords and principals (Effort: High)

- Migrations and models: `AppPassword`, `Principal` (see [Principal strategy](#principal-strategy))
- Argon2id for main passwords; main password is **optional for non-admin users (proposed)** because DAV accepts only app passwords and there is no portal in Version 1
- App password generation: random token with a **short public ID prefix**, shown once. The ID is used for direct lookup, so DAV Basic auth never checks every hash of a user. Store only the SHA-256 hash of the secret (Argon2id is unnecessary for high-entropy tokens and too slow per request)
- Columns for revocation and optional expiry (`revoked_at`, `expires_at`); `last_used_at` is written at most once per interval, not on every request
- Filament User resource: list, search, create, edit, enable/disable, **delete**
- App password list and **revoke** in the user page
- Guard: the last active admin cannot be deleted or disabled
- `UserService`: create user + principal in one transaction; keeps `users` and `principals` `display_name` / `email` in sync on edit
- Basic rate limiting on admin login (Laravel default), replaced by the full feature in PR 5
- Tests: user creation, app password generation/hash/revoke/expiry, last-admin guard, disabled user cannot log in

**Done when:** admin can create a user, generate and revoke an app password, and delete a user.

### PR 3 — sabre/dav integration (Effort: Extra High)

Start with a short spike (throwaway branch) to prove Laravel → sabre/dav → DAVx5 login works before writing the full PR.

- Install sabre/dav 4.7.x
- **Laravel migrations for the sabre/dav schema** on both SQLite and PostgreSQL: `calendars`, `calendarinstances`, `calendarchanges`, `calendarobjects`, `calendarsubscriptions`, `schedulingobjects`, `addressbooks`, `addressbookchanges`, `cards`, `propertystorage`, `locks`
- PDO backends reusing Laravel's connection (SQLite: WAL mode and busy timeout)
- Custom **principal backend** reading the Laravel `principals` table (or schema-compatible table, see [Principal strategy](#principal-strategy))
- Custom **Basic auth backend** (`AbstractBasic`): accepts username **or** email plus an app password; rejects disabled users, revoked and expired tokens; main password is never accepted
- `DavController` bootstraps the sabre/dav Server for `/dav/*`:
  - exclude `/dav/*` from CSRF and session middleware
  - allow all DAV methods (`PROPFIND`, `PROPPATCH`, `REPORT`, `MKCOL`, `MKCALENDAR`, `COPY`, `MOVE`, `LOCK`, `UNLOCK`)
  - make sure the `Authorization` header reaches PHP
  - pass the request body through and stream the response back
  - `setBaseUri('/dav/')`
- **`DAVACL` plugin with owner-only access**, so one user can never read another user's data
- Plugins: CalDAV, CardDAV, sync-collection, and the properties DAVx5 needs
- Principal + home collections created when a user is created (`UserService`); home collections removed on delete
- `.well-known/caldav` and `.well-known/carddav` redirects
- Tests: raw HTTP tests for auth success/failure, disabled user, revoked token, **user A gets 403/404 on user B's collections**, well-known redirects
- Manual smoke test with DAVx5: login and discovery

**Done when:** a DAV client can authenticate with an app password, see only its own principal and home collections, and DAVx5 completes discovery.

### PR 4 — Collections in admin panel (Effort: High)

- Create calendar (URI slug, display name, description, color, VTODO / VJOURNAL flags). URI slugs are validated, URL-safe and unique per user
- Create address book (URI slug, display name, description)
- **Edit and delete** calendars and address books (matches the [Users page design](design/admin-users.md))
- Show a user's calendars and address books in the Users page, with object / contact counts and the DAV path
- **Default calendar and address book on user creation (proposed)**
- Call sabre/dav backends to create the collections
- Tests: create/edit/delete collections, slug validation, collections visible via DAV
- Manual smoke test with DAVx5: sync a calendar and an address book end to end

**Done when:** admin can create a calendar and address book for a user, they appear via DAV, and DAVx5 can sync them.

### PR 5 — Rate limiting and brute-force protection (Effort: Medium)

Moved before Settings so there is something to configure.

- Apply to admin login and DAV Basic Auth
- Limit by **IP plus username**, so an attacker cannot lock a real user out from elsewhere
- Defaults in `config/nexusdav.php`; thresholds become editable on the Configuration page in PR 6
- Logging of failed attempts (no secrets)
- Tests: throttling on both entry points, successful login from another IP is not blocked by someone else's failures

**Done when:** repeated bad passwords are throttled on both the admin login and `/dav/`.

### PR 6 — Dashboard, Settings and Configuration pages (Effort: Medium)

- **Dashboard** (landing page after sign-in): server status, enabled services, basic counts
- Settings: enable/disable CalDAV, CardDAV, Tasks, Notes; logging level
- Configuration: timezone, time format, week start day; rate limiting thresholds; session settings
- Store non-boot settings in the database; env values win and are read-only
- Settings are loaded into config at boot and **cached**, so DAV requests do not query them each time
- Define what each switch does: which sabre plugins load, the response when a feature is off (**proposed: 403 with a clear message / collection not advertised**), and what happens to existing collections
- Tests: setting precedence (env wins and is read-only), each switch changes DAV behaviour

**Done when:** admin can change these settings, and each one has a test or manual check showing the effect (for example, disabling CardDAV makes address book requests fail; a new rate limit applies without restart).

### PR 7 — Admin password reset command (Effort: Low)

- Artisan command `nexusdav:reset-admin-password`
- Signs out sessions (requires `AuthenticateSession` and a session store that can be invalidated), optional revoke of app passwords
- Logged
- Tests: password changes, old sessions are invalid, optional revoke works

**Done when:** can reset admin password from the container shell and the old session is signed out.

### PR 8 — Release polish and documentation (Effort: High)

- Logging: stdout, with request ID, method, path, status and user; no secrets. Resolve stdout vs `/data/logs` **(proposed: stdout only, as in the guide)**
- Build and publish the image (GitHub Container Registry, amd64 + arm64); pin the image tag in the Compose examples
- Ready-to-use reverse proxy configs (nginx, Caddy, Traefik) including the `.well-known` fallbacks
- Manual test with DAVx5 (calendar, contacts, tasks via a tasks app); also try Thunderbird and Apple Calendar / Contacts; optionally run `litmus` / CalDAVTester
- Update the client compatibility table with the tested versions
- **Documentation sync** — fix the inconsistencies found in the review:
  - `LOG_LEVEL` default and read-only behaviour
  - `/data/logs` vs stdout
  - `APP_KEY` backup instructions (it lives on the data volume, not `.env`)
  - add `DB_DATABASE` and `DB_USERNAME` to the guide
  - [dav-endpoints.md](dav-endpoints.md): duplicate principal rows
  - [design/admin-users.md](design/admin-users.md): `/dav.php/` → `/dav/`
  - guide endpoint table: mark `/dav/files/` as Version 1.x
  - [data-model.md](data-model.md): sabre tables are created by Laravel migrations
  - [apps/calendar.md](apps/calendar.md) vs [dav-endpoints.md](dav-endpoints.md): whether clients can create collections
- Update the docs with any other deviations from the plan

**Done when:** DAVx5 can sync a calendar and address book created in the admin panel on a published, pinned image, and the docs match what was built.

## Suggested order and dependencies

```
PR1 → PR2 → PR3 → PR4 → PR5 → PR6 → PR7 → PR8
```

PR3 is the critical path. Everything after it depends on a working DAV server. PR5 comes before PR6 because the Configuration page edits the rate-limit settings. PR7 only needs PR2 and can be done earlier if needed.

## Principal strategy

The sabre/dav PDO principal backend expects its own `principals` table (`uri`, `email`, `displayname`), while [data-model.md](data-model.md) defines a Laravel `principals` table with different columns. **Proposed:** keep the Laravel table and write a small custom principal backend that reads it, so there is one source of truth. `UserService` keeps `users.display_name` / `users.email` and `principals` in sync. Decide this at the start of PR 2 and update the data model.

## Decisions to confirm

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

TrueNAS SCALE notes: run the app as a Docker Compose custom app, keep `/data` (and the PostgreSQL volume) on a dataset rather than an ephemeral volume, and publish only port 8080 behind the TrueNAS or a separate reverse proxy.

## Out of scope for Version 1

- User portal
- Sharing / ACL management (note: the `DAVACL` plugin is still loaded in PR 3 to enforce **owner-only access**)
- WebDAV-Push
- Scheduling
- Email
- Files storage
- Managed attachments
- Quotas enforcement
- Multilingual UI

## Notes

- Keep each PR small enough to review. PR 1, PR 2 and PR 8 are the likeliest to need splitting.
- Update `docs/architecture.md` and `docs/data-model.md` if implementation forces a change.
