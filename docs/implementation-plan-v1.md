# Implementation plan — Version 1

Goal: a working minimal server that allows creating users, calendars and address books in the admin panel, and syncing with DAVx5 (CalDAV + CardDAV).

Effort levels:
- **Low** — small, mostly boilerplate or config
- **Medium** — standard feature, clear path
- **High** — integration or non-trivial logic
- **Extra High** — complex, many edge cases, or core protocol work

## PR sequence

### PR 1 — Project skeleton (Effort: Medium)

- `composer create-project laravel/laravel`
- Install Filament 5, Livewire, Tailwind
- Basic `config/nexusdav.php`
- `.env.example` with the variables from the guide
- Dockerfile (PHP 8.4 + required extensions)
- Update `compose.yaml` to use the local build
- `/health` endpoint (simple check that app + DB are up)
- First admin creation on boot from `ADMIN_EMAIL` / `ADMIN_PASSWORD`

**Done when:** container starts, `/health` returns OK, admin can log in.

### PR 2 — User model and authentication (Effort: Medium)

- Migrations and models: `User`, `AppPassword`, `Principal`
- Argon2id for main passwords
- App password generation (show once, store only hash)
- Filament User resource (list, create, edit, enable/disable)
- Basic rate limiting on admin login

**Done when:** admin can create a user and generate an app password.

### PR 3 — sabre/dav integration (Effort: Extra High)

- Install sabre/dav 4.7.x
- PDO backend setup (calendars, addressbooks, objects, cards)
- `DavController` that bootstraps the sabre/dav Server for `/dav/*`
- Auth bridge: verify app password → set authenticated principal
- Principal + home collections created when a user is created (`UserService`)
- `.well-known/caldav` and `.well-known/carddav` redirects

**Done when:** a DAV client can authenticate with an app password and see the principal and home collections.

### PR 4 — Collections in admin panel (Effort: High)

- Create calendar (URI, display name, description, color, VTODO / VJOURNAL flags)
- Create address book (URI, display name, description)
- Show user's calendars and address books in the Users page
- Call sabre/dav to actually create the collections

**Done when:** admin can create a calendar and address book for a user, and they appear via DAV.

### PR 5 — Settings and Configuration pages (Effort: Medium)

- Settings: enable/disable CalDAV, CardDAV, Tasks, Notes; logging level
- Configuration: timezone, time format, week start day; rate limiting; session settings
- Store non-boot settings in the database; env values win and are read-only

**Done when:** admin can change these settings and they take effect.

### PR 6 — Rate limiting and brute-force protection (Effort: Medium)

- Apply to admin login and DAV Basic Auth
- Configurable thresholds from Configuration page
- Logging of failed attempts (no secrets)

**Done when:** repeated bad passwords are throttled.

### PR 7 — Admin password reset command (Effort: Low)

- Artisan command `nexusdav:reset-admin-password`
- Signs out sessions, optional revoke of app passwords
- Logged

**Done when:** can reset admin password from the container shell.

### PR 8 — Polish and testing (Effort: High)

- Logging to stdout + `/data/logs`
- Basic tests (user creation, app password auth, collection creation)
- Manual test with DAVx5 (calendar + contacts sync)
- Update docs with any deviations from the plan
- Pin image tag in compose examples

**Done when:** DAVx5 can sync a calendar and address book created in the admin panel.

## Suggested order and dependencies

```
PR1 → PR2 → PR3 → PR4 → PR5 → PR6 → PR7 → PR8
```

PR3 is the critical path. Everything after it depends on a working DAV server.

## Out of scope for Version 1

- User portal
- Sharing / ACL
- WebDAV-Push
- Scheduling
- Email
- Files storage
- Managed attachments
- Quotas enforcement
- Multilingual UI

## Notes

- Keep each PR small enough to review.
- Prefer working end-to-end slices over finishing one layer completely.
- Update `docs/architecture.md` and `docs/data-model.md` if implementation forces a change.
