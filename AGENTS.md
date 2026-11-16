# AGENTS.md

Rules for AI coding agents working on NexusDAV. Procedures (setup, PostgreSQL tests, Docker, smoke test, translations in detail, releasing) are in [CONTRIBUTING.md](CONTRIBUTING.md); read it when a task needs one. Ask before making architectural decisions.

## Project

Self-hosted CalDAV / CardDAV / WebDAV server with a Filament admin panel, MIT licensed. PHP 8.4, Laravel 13, Filament 5, sabre/dav 4.7, minishlink/web-push. PostgreSQL and SQLite are both fully supported. Target clients: DAVx5 + jtx Board, Thunderbird, Apple Calendar / Contacts / Reminders.

Laravel 13 and Filament 5 are newer than most training data: when unsure about an API, read the installed source in `vendor/` instead of guessing.

## Commands

`composer test` (one file: `php artisan test --compact tests/Feature/SomeTest.php`), `composer lint` (fix with `vendor/bin/pint`), `composer analyse`. Run all three before calling a task done. CI runs the tests on SQLite and PostgreSQL; how to run PostgreSQL locally is in CONTRIBUTING.md.

## Where things are

- DAV: `app/Http/Controllers/DavController.php` (entry), `app/Services/Dav/` (`DavServerFactory`, backends, nodes, `SharingService`, `CollectionService`, `FileStorage`, `DavWriteLock`, `DavDataCleaner`)
- WebDAV-Push: `app/Services/Push/`, `app/Services/Dav/Push/`
- Admin panel: `app/Filament/`; app logic: `app/Services/`
- User portal (Version 2, off by default): a second Filament panel at `/`. The framework (PR 48–50b) is in this tree. See `docs/design/user-portal.md` and `docs/architecture.md`

## Rules

1. **Data isolation.** Every query on calendars, address books, files or app passwords is scoped to the owner or an explicit share, enforced in backends, services and queries, never by hiding UI.
2. **Other users' paths answer 404, never 403** (`OwnerOnlyPrincipalBackend`): a 403 would show the user exists.
3. **One permission layer.** Sharing and ACL go through `SharingService` and the DAV node ACLs (`CalendarNode`, address book nodes); never reimplement them in Filament, Livewire or a controller.
4. **App passwords only on `/dav`.** The main password is refused there. App passwords are stored hashed. Keep `AuthThrottle` in every auth path.
5. **Never log or store** passwords, app password secrets, push keys or push service addresses, request headers (`Authorization` included), query strings or request bodies. What a log line may carry about a request: method, path without the query string, status, username, client address, duration, request ID, and for a refused DAV request a `reason` — the error message only, with every value from the request replaced by `(…)` or `"…"` (`DavController::reason()`). Example: `"reason":"Validation error in vCard: The supplied value (…) is not a correct DATE-AND-OR-TIME property"`. Never put a field value, a file name or item content in a log line or a Diagnostics row.
6. **Outbound calls** to user-supplied URLs go through `PushEndpointGuard` / `PushHttpClient` (SSRF).
7. **Every DAV write reports WebDAV-Push** through `PushChanges` (the backends do; server-side writes must go through them). Deleting a collection clears its push subscriptions (`DavDataCleaner::deletePath()`).
8. **Server-side writes** to a calendar or address book run in a transaction with the DAV write lock (`DavWriteLock`).
9. **Both databases.** No database-specific SQL without both branches tested. New migrations, never edits to released ones.
10. **DAV behavior changes** (ETags, sync tokens, CTags, multistatus, status codes 401/403/404/412/415/423/503/507) need a test, a PR note, and asking first.
11. **New panel setting:** add it to `SettingsService::DEFINITIONS` (config key, env var, type), read the env var in the config file, and label it in `SettingsTransfer::label()`.
12. **New table:** list it in `FactoryReset::RESET_TABLES` or `KEPT_TABLES` (a guard test fails otherwise). Do not bypass `RefuseWhileResetting`, `PauseWorkerWhileResetting` or `ReloadSettingsForJob`.
13. **No vCard / iCalendar conversion or normalization** beyond what sabre already does (validate and repair on PUT, 415 when it cannot), unless asked.

## Code and tests

- Pint (PSR-12) and Larastan must pass. Full type hints; no `declare(strict_types=1)` (the codebase does not use it).
- Filament pages stay thin: closures call services. No Form Requests or policies.
- Panel text: `__('English text')` with entries in `lang/en.json` and `lang/ru.json` in the same change; `LocalizationTest` enforces it (details in CONTRIBUTING.md, Translations).
- PHPUnit, not Pest. DAV behavior is tested with real requests (`$this->call()` with Basic auth and an app password). Every bug fix gets a regression test.
- Comment only code that needs clarification.

## Working agreements

- Smallest change that solves the task; no unrelated refactoring.
- Ask before adding a dependency, a breaking schema change, or a DAV protocol change.
- Do not implement planned features (`docs/plans/`) unless asked.
- Update the README and `docs/` when behavior or setup changes (`docs/guide.md` for users, `docs/design/` and `docs/plans/` for plans). `docs/plans/*.md` and `docs/testing/*` are gitignored except files listed in `.gitignore`.
- Do not commit, push or tag unless asked. Releases follow CONTRIBUTING.md (Releasing).
