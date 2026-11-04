# Implementation plan — Version 1.x

Goal: add the Version 1.x features from the [guide roadmap](../guide.md#version-1x) on top of the built Version 1 server, without changing its core request flow.

The five features:

1. Basic Sharing and ACL
2. WebDAV-Push, with an on/off switch in the admin panel and its subscriptions on the Diagnostics page
3. Multilingual admin panel
4. Files (WebDAV file storage)
5. Improved sync reliability

The work is split into five milestones and 31 PRs, listed in the order they are to be implemented. Each PR has an effort level, its scope, its tests and a **Done when** check, like the [Version 1 plan](implementation-plan-v1.md). PR numbers continue from Version 1, which ended at PR 8. Version numbers are given to milestones when they are released, not here.

Sources: [guide](../guide.md), [apps](../apps/README.md), the [admin panel](../apps/admin.md) description, the design notes for [Settings](../design/admin-settings.md), [Configuration](../design/admin-configuration.md) and the [Dashboard](../design/admin-dashboard.md), the pinned WebDAV-Push draft, and the current code.

**Status:** M1 is done and released as **0.1.2** (PR 9 to PR 12; see the notes under [M1](#m1--multilingual-admin-panel-effort-medium)). M2 is in progress: PR 13 and PR 14 are done (PR 14 is in 0.1.4); PR 15 to PR 19 are next. M3 to M5 are not started.

Effort levels are the same as in Version 1:
- **Low** — small, mostly boilerplate or config
- **Medium** — standard feature, clear path
- **High** — integration or non-trivial logic
- **Extra High** — complex, many edge cases, or core protocol work

No single PR is rated Extra High. The two Extra High milestones are split into High PRs.

## PR order

Implement the PRs in this order. "Needs" lists the PRs that must be merged first.

| Order | PR | Milestone | Title | Effort | Needs |
|---|---|---|---|---|---|
| 1 | PR 9 | M1 | Language foundation and switcher (done) | Medium | — |
| 2 | PR 10 | M1 | Translate the Users pages and the Dashboard (done) | Medium | 9 |
| 3 | PR 11 | M1 | Translate Settings, Configuration and Diagnostics; pseudo-locale test (done) | Medium | 9 |
| 4 | PR 12 | M1 | M1 release (done, 0.1.2) | Low | 10, 11 |
| 5 | PR 13 | M2 | Client checklist and the first hand-test pass (done) | Medium | — |
| 6 | PR 14 | M2 | Sync tokens that cannot be trusted (done, 0.1.4) | High | — |
| 7 | PR 15 | M2 | Sync history retention | Medium | 14 |
| 8 | PR 16 | M2 | Atomic conditional writes | High | — |
| 9 | PR 17 | M2 | Busy database: retry, then 503 | Medium | 16 |
| 10 | PR 18 | M2 | Fixes from the client pass | Medium, re-rated after PR 13 | 13 |
| 11 | PR 19 | M2 | M2 release | Medium | 12, 13–18 |
| 12 | PR 20 | M3 | Spike: address book sharing design | Medium | — |
| 13 | PR 21 | M3 | Share storage and `SharingService` | High | 20 |
| 14 | PR 22 | M3 | Share management in the admin panel | High | 11, 21 |
| 15 | PR 23 | M3 | Shared calendars over DAV and the Sharing switch | High | 14, 21 |
| 16 | PR 24 | M3 | Shared address books: reading and sync | High | 14, 21 |
| 17 | PR 25 | M3 | Shared address books: writing and leaving a share | High | 16, 24 |
| 18 | PR 26 | M3 | Received shares, the Sharing switch in the panel, and the Dashboard | Medium | 22, 23 |
| 19 | PR 27 | M3 | M3 release | Medium | 19, 21–26 |
| 20 | PR 28 | M4 | File home: storage, isolation and basic methods | High | — |
| 21 | PR 29 | M4 | COPY, MOVE, locks and properties for files | High | 28 |
| 22 | PR 30 | M4 | Upload size limit, streaming and a full disk | Medium | 28 |
| 23 | PR 31 | M4 | Files in the admin panel | Medium | 28, 30 |
| 24 | PR 32 | M4 | M4 release | Medium | 27, 28–31 |
| 25 | PR 33 | M5 | Spike: Web Push library and DAVx5 push | Medium | — |
| 26 | PR 34 | M5 | Worker service and heartbeat | Medium | — |
| 27 | PR 35 | M5 | Push discovery and subscription registration | High | 23, 24, 28, 33 |
| 28 | PR 36 | M5 | Change events and the push queue | High | 34, 35 |
| 29 | PR 37 | M5 | Web Push delivery and the SSRF guard | High | 33, 36 |
| 30 | PR 38 | M5 | Push in the admin panel | Medium | 34, 35, 37 |
| 31 | PR 39 | M5 | M5 release | Medium | 32, 33–38 |

Notes on the order:

- **Releases are in order.** Each milestone ends with a release PR, and each release PR needs the previous one.
- **Spikes and independent PRs can start early** when someone is free: PR 13 (client pass), PR 20 (sharing spike), PR 33 (push spike) and PR 34 (worker) need no code from earlier milestones. Their results are used later.
- **M3 and M4 can overlap** with a second contributor. M4's code (PR 28–31) does not depend on M3. Only the releases stay in order: Sharing first, then Files.
- **Within M2**, PR 14–17 come before PR 18, so problems from the client pass that those PRs already fix are not fixed twice.
- **Within M3**, the panel (PR 22) comes before the DAV PRs, so every DAV PR can be tested by hand by creating shares in the panel.

## Milestones

| # | Milestone | Effort | PRs | Depends on |
|---|---|---|---|---|
| M1 | [Multilingual admin panel](#m1--multilingual-admin-panel-effort-medium) | Medium | PR 9–12 | — |
| M2 | [Sync reliability](#m2--sync-reliability-effort-high) | High | PR 13–19 | — |
| M3 | [Sharing and ACL](#m3--sharing-and-acl-effort-extra-high) | Extra High | PR 20–27 | M1, M2 |
| M4 | [Files](#m4--files-effort-high) | High | PR 28–32 | M1, M2 |
| M5 | [WebDAV-Push and the worker](#m5--webdav-push-and-the-worker-effort-extra-high) | Extra High | PR 33–39 | M3, M4 |

```
M1 Languages ──┬──> M3 Sharing ──┬──> M5 WebDAV-Push
M2 Sync ───────┴──> M4 Files ────┘
```

Why this order:

- **M1 first.** Every later milestone adds admin panel pages and fields. When the translation setup exists first, their text goes into language files from the start and is not reworked later.
- **M2 early.** Its client checklist and conflict tests become the sign-off for M3 to M5. Its fixes to sync tokens also apply to shared collections.
- **Sharing before Files.** They do not depend on each other; Sharing is released first.
- **M5 last.** A change to a shared calendar must notify every user who has it, and Settings has an option to include Files in push. Building push after both means it is designed once.

## Decisions

| # | Decision | Choice |
|---|---|---|
| 1 | Release slicing | Milestones in dependency order; a version is given at release time |
| 2 | Who creates shares | **The admin panel only.** Clients see and use shared collections. Sharing requests sent by a client are refused, and sharing is not advertised to clients |
| 3 | Share with whom | Individual users on this server. Access is **read-only** or **read-write**. No groups |
| 4 | Meaning of "ACL" in 1.x | The access level of each share, enforced through sabre's `DAVACL`. No general ACL editing (no `ACL` method, no custom privileges) |
| 5 | Isolation | Version 1 Decision 14 stays: another user's principal and homes answer 404. A sharee reaches a shared collection only through their own home |
| 6 | Files storage limits | Follow the guide: **maximum upload size only**. User quotas stay in Version 2.x. No file trash and no "deleted user file retention" in 1.x |
| 7 | Files storage location | Fixed on the data volume (`/data/files/<username>/`), not a panel setting |
| 8 | Push delivery | A **queue worker as an optional second Compose service**, using the same image and Laravel's database queue (the `jobs` table already exists). Version 1 Decision 1 (one process per container) stays true |
| 9 | Panel languages | **English and Russian** ship with the project. Translation files are structured so contributors can add more. Filament's own strings use its built-in translations (it includes Russian) |
| 10 | Sync reliability scope | A pass with real clients and fixes for what it finds; sync history retention and correct answers to sync tokens that are no longer valid; write conflicts (ETag / `If-Match`, SQLite busy) |
| 11 | WebDAV-Push draft | `draft-bitfire-webdav-push-00` at commit `611abe2e8a0cdd7570cb936844b6588f7145b27d`, as in the [guide](../guide.md#requirements), until bitfire publishes a release |
| 12 | WebDAV-Push URL | The same `/dav/` URLs as sync, for calendars, address books and files. No separate push URL or setting |
| 13 | Address book sharing | Required in M3, together with calendar sharing. sabre/dav has no address book sharing, so the spike picks the design, not whether to build it. M3 is not released without both |
| 14 | App password scope | No scoping in 1.x. An app password reaches calendars, contacts and, from M4, the user's files. The docs say so. Scopes per app password can be planned later |
| 15 | Real-client testing | The maintainer runs the client checklist by hand on **Android** (DAVx5 with jtx Board) and **Linux** (Thunderbird, GNOME Files, rclone). It is a release step, and the results go in the guide's client compatibility table. Apple, macOS and Windows clients are not tested by the project; the guide marks them "not tested", and user reports are welcome |
| 16 | Upgrade defaults | Files and WebDAV-Push are **off** after an upgrade. An admin switches them on. Sharing is on, but nothing is shared until an admin creates a share |
| 17 | Name of a shared collection | The sharee's copy starts as "<name> (<owner's display name>)", for example "Calendar (Ada)". The sharee can rename their copy; the owner's name does not change |
| 18 | PR numbering | Continues from Version 1: PR 9 onward |
| 19 | Russian text | Drafted in the PR that adds the English text. The maintainer reviews it before the milestone's release |
| 20 | Sync tokens issued before M2 | Accepted while they are not ahead of the collection's current token, so an upgrade does not force every client into a full sync |

## Rules for every PR

These carry over from Version 1 and add what 1.x needs.

- Every PR ships its own tests, run on SQLite and PostgreSQL in CI.
- New panel settings go in `SettingsService::DEFINITIONS` with an environment variable, and the config file reads that variable. A set variable wins and locks the field, as in Version 1.
- A feature that is switched off answers 403 with a reason on its DAV paths and keeps its data, like the CalDAV and CardDAV switches.
- New panel text is translatable from PR 9 on, with an English and a Russian entry in the same PR (Decision 19).
- Upgrading from the previous release works with migrations alone, on both databases. Upgrade notes go in the release notes.
- Cross-user tests are extended for every new path. A user who is neither the owner nor a sharee still gets 404.
- A PR updates the docs it makes wrong. The milestone's release PR does the rest of the docs: the guide, [apps](../apps/README.md), the design notes, [architecture.md](../architecture.md), [data-model.md](../data-model.md) and [dav-endpoints.md](../dav-endpoints.md).
- Each release PR changes the version on the `image:` line of `compose.yaml`, in the TrueNAS files, the README and the guide, as in [CONTRIBUTING.md](../../CONTRIBUTING.md#releasing).
- Before a release, the maintainer runs the client checklist (PR 13) by hand for the areas the milestone touched, on the devices in Decision 15, and records the results in the guide.

## M1 — Multilingual admin panel (Effort: Medium)

**Goal:** the admin panel can be shown in more than one language. English and Russian ship complete, and adding a language means adding one file.

**Status:** done, released as **0.1.2**. What shipped, beyond the PR text below:

- `App\Support\LocaleCatalog` lists the languages from `lang/*.json` (`_language_name`). It is read once per request; a file that is not valid JSON or has no name is skipped and logged, so a bad file cannot stop DAV, and the translation test fails on it in CI.
- `SetAdminLocale` runs only in the panel middleware and restores the previous locale after the request. `User::resolvedLocale()` ignores a stored code that is no longer shipped.
- The **Language** user-menu item validates the choice against the catalog and reloads the current panel page, so the whole panel switches at once.
- The Configuration page has its own **Language** section above Time.
- Diagnostics shows translated labels for every stored reason, on both the Failed logins and the DAV errors tables (`App\Support\DiagnosticReason`), including the HTTP status texts of DAV errors. The stored value stays English.
- Messages from services (for example "The email has already been taken.") are translated when shown in the panel (`App\Support\PanelErrorTranslator`); the exception text stays English for logs and the CLI.
- Laravel's Russian validation, auth, password and pagination messages are in `lang/ru/`, adapted from Laravel-Lang (MIT, license in `lang/ru/LICENSE.Laravel-Lang`). Field names in Russian messages come from `validation.attributes`.
- Filament's own Russian files lack some keys (skip to content, table loading and result count, Yes/No on boolean icons, select and date picker labels, and others). The ones the panel uses are added in `lang/vendor/<namespace>/ru/`, which Laravel merges over Filament's files.
- The pseudo-locale test uses `en_XA`: app strings are wrapped in `⟦…⟧`, everything else falls back to Russian, and any Latin text left outside the markers fails, except an allowlist of product and protocol names, sample data and technical values. It renders the login page, Dashboard, Users list, create and edit, Settings, Configuration and Diagnostics over HTTP, plus the three relation tables, the widgets and the language modal.
- The maintainer review (Decision 19) changed some wording in `ru.json`.

### PR 9 — Language foundation and switcher (Effort: Medium) — ✅ done

- Translation files: `lang/en.json` and `lang/ru.json`, keyed by the English text that `__()` already uses in the code. Adding a language means adding `lang/<code>.json`. Each file carries the language's own name (for example "Русский") for the picker.
- The list of languages is built from the files in `lang/`.
- A locale middleware on the admin panel only: the signed-in admin's `users.locale`, else the server default, else `en`. The login page uses the server default. `/dav`, `/health`, the log and the reason codes stored in `diagnostic_events` stay in English, because clients and log searches depend on them.
- **Server default language** on the Configuration page: a `default_locale` entry in `SettingsService::DEFINITIONS`, variable `NEXUSDAV_LOCALE`, read by the existing `nexusdav.default_locale`. Its options come from the language list. `APP_LOCALE` and `APP_FALLBACK_LOCALE` leave `.env.example`, so there is one language variable; the fallback stays `en`.
- **Each admin's own language:** a **Language** item in the user menu, saved in the existing `users.locale` column. "Server default" clears it.
- Dates: Carbon follows the locale, so month and day names are translated. The existing time format and week start settings still apply.
- A translation check, run as a test: it scans `app/` and `resources/views/` for `__()`, `trans()`, `trans_choice()` and `@lang` keys and fails when a key in the code is missing from `en.json`, or a key in any JSON file is not used. From PR 11 it also requires `ru.json` to be complete.
- [CONTRIBUTING.md](../../CONTRIBUTING.md): a section on adding a language, and the rule that new panel text needs English and Russian entries.

**Tests:** the order of locale resolution; the user menu saves and clears the language; the variable locks the Configuration field; a DAV answer and a stored Diagnostics reason are unchanged while an admin uses Russian; the translation check.

**Done when:** an admin can switch the panel between English and Russian from the user menu (pages not yet translated stay in English), the server default applies to admins without their own choice and to the login page, `NEXUSDAV_LOCALE` locks the field, and the translation check runs in CI.

### PR 10 — Translate the Users pages and the Dashboard (Effort: Medium) — ✅ done

- Every string on the Users list, create and edit pages: columns, filters, row actions, confirmations (including the list of what a delete removes) and notifications.
- The three tables on the edit page: Calendars, Address books and App passwords, including the one-time app password message.
- Field and column labels set explicitly with `__()`. Filament builds a label from a field name (`display_name` becomes "Display name") and does not translate it.
- The Dashboard widgets: Server status, Services, Counts and Needs attention. Counts use `trans_choice`, because Russian has three plural forms.
- The login page and the user menu.
- The Russian text, drafted in this PR (Decision 19).

**Tests:** each Users page and the Dashboard render in Russian with sample data, and selected strings are checked; Russian plural forms for 1, 2, 5 and 21.

**Done when:** the Users pages and the Dashboard show no English text in Russian, apart from names, user data and technical terms (CalDAV, URI), and the translation check passes.

### PR 11 — Translate Settings, Configuration and Diagnostics; pseudo-locale test (Effort: Medium) — ✅ done

- The Settings, Configuration and Diagnostics pages, including the "Set by <VARIABLE>" text on locked fields.
- Diagnostics reasons: the stored value stays English (`bad secret`, `revoked`); the page shows a translated label for each known reason.
- Laravel's own messages in Russian: validation, authentication throttling and passwords. Publish the English files with `php artisan lang:publish`, then add Russian, translated in the PR or taken from `laravel-lang/lang` (MIT) with attribution; the PR decides.
- **Pseudo-locale test:** a test-only locale wraps every translated string in markers. Every panel page (login, Dashboard, Users list, create and edit, Settings, Configuration, Diagnostics) is rendered with sample data. Visible text outside the markers fails the test, except an allowlist: product and protocol names, user data and numbers.
- The translation check now also requires every key in `ru.json`.

**Tests:** the pseudo-locale test; validation errors in Russian on the user form and the Configuration page.

**Done when:** every panel page passes the pseudo-locale test, the translation check passes for English and Russian, and validation errors show in Russian.

### PR 12 — M1 release (Effort: Low) — ✅ done

- The maintainer reviews the Russian text (Decision 19); corrections go into `ru.json`.
- Docs: the guide's settings table (`NEXUSDAV_LOCALE`), the [Configuration design note](../design/admin-configuration.md), [apps/admin.md](../apps/admin.md), and the README status line.
- The version change and the release notes.
- No client checklist: M1 does not change DAV.

**Done when:** the Russian review is done, the docs match the panel, and the release is published.

**Status:** done. The maintainer reviewed `ru.json`. The guide (status, settings table with `NEXUSDAV_LOCALE`, a Language section, compatibility note), the Configuration design note, `apps/admin.md`, the README, SECURITY.md, `data-model.md` and the TrueNAS files are updated. `compose.yaml` pins `0.1.2`, and `v0.1.2` is published.

## M2 — Sync reliability (Effort: High)

**Goal:** clients stay in sync after long offline periods, concurrent edits and busy databases, and the client compatibility table reflects real tests.

### PR 13 — Client checklist and the first hand-test pass (Effort: Medium) — ✅ done

- A written checklist in `docs/testing/client-checklist.md`, reused by every later release:
  - Discovery from the domain only
  - Create, edit and delete an event, a recurring event with an exception, a task (completed afterwards), a note and a contact with a photo
  - A calendar created from the client
  - Two devices editing the same item
  - Offline edits, then sync
  - A calendar deleted and created again with the same name while a client is offline
  - A first sync of a large collection
- A test-data seeder for the large-collection step (for example 5,000 events and 2,000 contacts for one user). It refuses to run without a named user and `--force`.
- The pass, on the devices in Decision 15: DAVx5 with jtx Board on Android; Thunderbird on Linux, including tasks. PostgreSQL behind Caddy over HTTPS, and SQLite.
- The results: one row per step, client and database, kept in the checklist file. Each failure becomes a GitHub issue with the label `m2-client-pass`.

**Done when:** the checklist is merged, the pass is run on the current `main` for both clients and both databases, the results are recorded, and every failure has an issue.

**Status:** done. Delivered:

- The checklist, [docs/testing/client-checklist.md](../testing/client-checklist.md): setup, steps 1 to 7 with pass criteria, what to put in an `m2-client-pass` issue, and an empty results table for pass 1.
- The seeder, `php artisan nexusdav:seed-test-data <username> --force` (`App\Console\Commands\SeedTestData`). It creates new `load-test` collections (5,000 events and 2,000 contacts by default, up to 50,000 each) through the sabre backends, so sync tokens and the change log match a client upload. It refuses without `--force`, for an unknown user, or when the collection already exists. The default size takes about 1.3 seconds on SQLite. Tests: `SeedTestDataTest`, which also syncs the result over DAV.

Tag `v0.1.3` publishes the image with the seeder and the Configuration reload fix (no GitHub Release page). Pass 1 runs against that image.

Pass 1 results (3 November 2026, v0.1.3, PostgreSQL; DAVx5 4.5.21 and jtx Board 2.17.00 on a Pixel 7 Pro with Android 17, Thunderbird 159.0 from the Ubuntu Snap), recorded in the checklist:

- **Pass on PostgreSQL, both clients:** discovery, events, recurring events with exceptions, tasks (jtx Board), contacts with a photo, offline edits, two devices editing the same item, and the large collection (5,000 events and 2,000 contacts: 14 s in Thunderbird, 10 min 45 s in DAVx5, no errors, and the second sync fetched only the change). Notes and calendar creation passed in DAVx5.
- **SQLite:** the maintainer ran the same steps with the same results as PostgreSQL, including step 7 (on 0.1.4): calendar 29 s in DAVx5 and 22 s in Thunderbird, contacts about 10 min in DAVx5 and about 2 s in Thunderbird. The DAVx5 contacts time is the same on both databases and the server answers the requests in about 2.5 s in total, so it is client-side (#1, medium priority; an iPad loaded the same 2,000 contacts in 74 s). The load test uploaded in parallel and got one 500 "database is locked" on SQLite (#3, for PR 16).
- **Failure:** step 6 on both clients and both databases (#2): after a calendar is deleted and re-created with the same URI, the old events stay on the client with no error until DAVx5 Refresh list or a new Thunderbird subscription. PR 14 targets exactly this, so it now has a before-fix result.
- **Observation:** the DAVx5 first contact sync takes about 10 minutes for 2,000 contacts. Proven client-side from the DAVx5 log, documented in the guide's Troubleshooting, and #1 is closed.

The Done when is met: both clients and both databases are covered, and every failure has an issue. Tasks.org was dropped from the test devices (the maintainer uses jtx Board).

### PR 14 — Sync tokens that cannot be trusted (Effort: High) — ✅ done

- Calendar and address book sync tokens carry the collection's identity as well as its change number (for example `<collection id>-<number>` after sabre's `http://sabre.io/ns/sync/` prefix). `getctag` follows the same rule, so a client that compares ctags also sees a re-created collection as changed.
- `sync-collection` answers 403 with `DAV:valid-sync-token` (sabre's `InvalidSyncToken`) when the token:
  - belongs to another collection, for example one deleted and created again with the same URI;
  - is ahead of the collection's current token;
  - is malformed.
- Tokens issued before this PR (plain numbers) are accepted while they are not ahead of the current token (Decision 20). The next sync gives the client a new-format token.
- The calendar and address book homes already use a snapshot of their children as the token. With the new format, a re-created child shows up as changed in the home.

**Tests (both databases):** the three bad-token cases; a legacy token; the ctag change on re-create; a home reporting a re-created child.

**Done when:** the three bad-token cases get `valid-sync-token` on both databases, legacy tokens keep working, and DAVx5 and Thunderbird recover with a full sync after a calendar is deleted and created again while they are offline.

**Status:** done, released for testing as 0.1.4. Fixes issue #2 (client pass 1, step 6).

- `App\Services\Dav\SyncToken` holds the rules. `CalendarBackend` and `AddressBookBackend` put `<id>-<number>` in the sync token and the ctag, and check every incoming token before sabre reads the change log, so a malformed token never reaches SQL. The calendar id is `calendars.id`, so a shared calendar (PR 23) gets the owner's tokens, as planned.
- Ids are never reused: `autoincrement` on SQLite and a sequence on PostgreSQL. A database restored from an older backup goes back in change numbers, so client tokens are ahead and get 403, which is the right answer.
- Token `0` is refused as malformed: sabre would read it as "no token" and send every item without deletions. No collection issues it, since change numbers start at 1.
- The homes needed no change: their snapshot holds each child's token, so a re-created child is reported as modified, and the client then gets the 403 on that child.
- Tests (`SyncTokenTest`, both databases): re-create from the panel and from a client (including the case where the old and new change numbers and ctags were equal), the three bad-token cases with 16 malformed forms, legacy tokens, incremental sync with deletions, PROPFIND values, and both homes. Seven of the eight fail on the code before this PR. [dav-endpoints.md](../dav-endpoints.md#sync-tokens) describes the rules.
- For the M2 release notes: after the upgrade every collection's ctag and every home's token change form once. Clients sync each collection once more, using their old token, which is still accepted, so this is a small sync and not a full one.

Tag `v0.1.4` publishes the image with this change (no GitHub Release page).

Done on 0.1.4: Thunderbird got a 403 and then synced in full; DAVx5 dropped the old events too (it was listing the calendar in full because of its past-events limit). #2 is closed.

### PR 15 — Sync history retention (Effort: Medium)

- Migration: a `created_at` column (database default: now) on `calendarchanges` and `addressbookchanges`; existing rows get the migration time. Each calendar and address book records the oldest change number it can still answer for.
- **Keep sync history for (days)** on the Settings page: `NEXUSDAV_SYNC_HISTORY_DAYS`, default `90`.
- Pruning on write, like diagnostics pruning (Version 1 Decision 11): at most once per hour, in batches, moving the oldest change number forward in the same transaction. An index supports the pruning query.
- A token older than the kept history gets `valid-sync-token`.

**Tests (both databases):** rows at the retention boundary; a token older than the pruned history; batch pruning; no pruning while the history is shorter than the setting.

**Done when:** history older than the setting is removed on both databases, a token older than the history gets `valid-sync-token`, and a newer token still gets exactly the changes since it.

### PR 16 — Atomic conditional writes (Effort: High)

- `PUT`, `DELETE`, `MOVE` and `PROPPATCH` on calendars and address books run in one database transaction. It starts in sabre's `beforeMethod` event, which runs before sabre checks `If-Match` and `If-None-Match`, and ends after the write. Writes to the same collection wait for each other: SQLite takes the write lock at the start (`BEGIN IMMEDIATE`), PostgreSQL a transaction-level advisory lock on the collection.
- Two writes with the same `If-Match`: one succeeds, the other gets 412.
- Two creates of the same new URI: with `If-None-Match: *` the second gets 412; without it, the second replaces the first, as `PUT` normally does. Neither gives a 500 from the unique index.
- The ETag returned by `PUT` matches what `GET` returns afterwards, or no ETag is returned when the server changed the data (RFC 4791 allows that). Tested for events, tasks, notes and contacts.
- Only CalDAV and CardDAV paths. File uploads (M4) are not held in a database transaction.

**Tests:** two processes writing at the same time against a real database file on SQLite (not `:memory:`) and against PostgreSQL.

**Done when:** the concurrency tests pass on both databases, and no write race gives a 500 or a silent overwrite.

Issue #3 has a first case from the client pass: one 500 "database is locked" in 5,000 parallel event uploads on SQLite (none on PostgreSQL).

### PR 17 — Busy database: retry, then 503 (Effort: Medium)

- An SQLite busy error after the 5-second timeout, or a PostgreSQL serialization failure (`40001`) or deadlock (`40P01`) inside a DAV write: the transaction from PR 16 is retried up to three times after a short random wait. The request body is kept so the write can be replayed; CalDAV and CardDAV bodies are small.
- After the retries, or on a busy error during a read: 503 with `Retry-After`, stored on Diagnostics like other DAV errors, with the request ID in the log.

**Tests:** a second connection holding the SQLite write lock; a forced PostgreSQL serialization failure; the retry count; the 503 and its Diagnostics row.

**Done when:** lock contention never produces a 500 on either database; the client gets its result after a retry, or a 503 with `Retry-After`.

### PR 18 — Fixes from the client pass (Effort: Medium, re-rated after PR 13)

- One fix and one regression test for each `m2-client-pass` issue that PR 14 to PR 17 did not already fix, or a documented limitation in the guide's Troubleshooting.
- When the list is long, split it into one PR per area (CalDAV, CardDAV, discovery).

**Done when:** every `m2-client-pass` issue is closed with a fix or a documented limitation.

### PR 19 — M2 release (Effort: Medium)

- The full checklist again on the release candidate, with both clients and both databases.
- Docs:
  - The guide's client compatibility table (versions, Thunderbird tasks), its settings table (`NEXUSDAV_SYNC_HISTORY_DAYS`), and Troubleshooting (a full resync after a long time offline; a 503 under heavy load)
  - [data-model.md](../data-model.md) (new columns) and [architecture.md](../architecture.md)
- Release notes: the new token format, legacy tokens still accepted, and the 90-day history default.

**Done when:** the checklist passes with both clients on both databases, the docs match, and the release is published.

## M3 — Sharing and ACL (Effort: Extra High)

**Goal:** an admin can share a user's calendar or address book with another user, read-only or read-write. The other user's clients see it in their own home.

### PR 20 — Spike: address book sharing design (Effort: Medium)

- A throwaway prototype of a shared address book node in the sharee's address book home: read and read-write access, the owner's sync token and changes, the sharee's own display name, and leaving the share.
- A check of the calendar side with sabre's `calendarinstances`, without loading sabre's sharing plugin: the sharee's instance appears, `current-user-privilege-set` is right, and the owner's principal still answers 404.
- Decisions, written down:
  - The shape of the address book share data: a share table, or an instance table like calendars have
  - The URI of a shared collection in the sharee's home. Proposed: the owner's URI, with `-<owner username>` added when the sharee already uses that URI
  - How the homes list shared children and include them in the home sync token
  - What `DAV:owner` shows. It names the owner's principal, which still answers 404 to the sharee (Decision 5)
- Output: `docs/design/sharing.md` with the decisions. The prototype code is not merged.

**Done when:** the design note is merged, and the prototype synced a read-only and a read-write shared address book in DAVx5 and Thunderbird.

### PR 21 — Share storage and `SharingService` (Effort: High)

- The migration for address book shares, in the shape chosen in PR 20.
- Calendar shares use `calendarinstances` rows: access `2` (read) or `3` (read-write), invite status accepted, and `share_href` set to the sharee's principal.
- `SharingService`: share, change the access, unshare, list the sharees of a collection, list the shares a user received, and list every instance of a collection (M5 needs it).
- The sharee's copy:
  - Its display name starts as "<name> (<owner's display name>)" (Decision 17)
  - Its color and order are copied from the owner's
  - Its URI is unique in the sharee's home
- Rules:
  - No share with the owner
  - One share per sharee and collection
  - A disabled owner or sharee keeps the share. Disabling stops sign-in only (Settled question 1)
- Cleanup: deleting a collection, from the panel or over DAV, removes all its shares; deleting a user removes the shares they own and the shares they received (`DavDataCleaner`).
- No panel and no DAV changes yet. Nothing can be shared in a build from this PR alone.

**Tests (both databases):** the rules; changing access; cleanup on collection and user deletion; listing every instance of a collection.

**Done when:** shares can be created, changed and removed through the service on both databases, and collection and user deletion leave no share rows.

### PR 22 — Share management in the admin panel (Effort: High)

- A **Share** action on each calendar and address book row of the user page. It opens a **Shared with** list:
  - Each sharee with their access level
  - Add a user, found by username, display name or email; the owner and current sharees are left out. Choose **Read only** or **Read and write**
  - Change the access
  - Remove, with a confirmation
- A shared indicator (number of sharees) in the Calendars and Address books tables.
- The delete confirmations: deleting a collection says its shares are removed; deleting a user lists the shares they own and received.
- English and Russian text.
- Address book shares are stored but not shown to clients until PR 24. Nothing is released in between.

**Tests:** Livewire tests for add, change and remove; the excluded users; the confirmation texts.

**Done when:** an admin can add, change and remove the sharees of any calendar or address book, and the rows match what `SharingService` reads.

### PR 23 — Shared calendars over DAV and the Sharing switch (Effort: High)

- Shared calendars appear in the sharee's calendar home, with their tasks and notes, and in the home's sync token.
- **Read-only:** writes are refused with 403 (`PUT`, `DELETE` of an item, `PROPPATCH` of the owner's properties, `MOVE` out). `current-user-privilege-set` shows read only, so DAVx5 and Thunderbird show the calendar as read-only.
- **Read-write:** the sharee can create, change and delete events, tasks and notes.
- The sharee's display name, color and order are their own. Changing them does not touch the owner's.
- `DELETE` of the shared calendar by the sharee leaves the share; the owner's data stays (Settled question 2).
- No sharing from clients: sabre's sharing plugin is not loaded, sharing `POST` requests (`share`, `invite-reply`) get 403, and no sharing modes are advertised. The properties sabre's calendar node returns are checked so they show no other sharees.
- Isolation: the owner's principal and homes still answer 404 to the sharee; a third user sees nothing.
- The Tasks and Notes switches apply to shared calendars.
- Sync: the sharee's tokens follow the owner's calendar (PR 14). A removed share is reported as deleted in the sharee's home sync.
- **Sharing switch:** a `sharing` entry in `SettingsService::DEFINITIONS` (`NEXUSDAV_SHARING`, default on). When off, sharee copies are hidden and their URLs answer 404; the rows are kept. The panel field comes in PR 26.

**Tests (both databases):** every method against read-only and read-write shares; isolation; the switch; the sharee's incremental sync after an owner change; a disabled owner.

**Done when:**
- A calendar shared read-only and read-write syncs correctly in DAVx5 (with tasks and notes in jtx Board) and in Thunderbird.
- Read-only writes get 403.
- Non-sharees get 404.
- Switching Sharing off hides shares at once.

### PR 24 — Shared address books: reading and sync (Effort: High)

- The shared address book node chosen in PR 20, in the sharee's address book home, included in the home listing and its sync token.
- Read access: `PROPFIND`, `GET`, `addressbook-query`, `addressbook-multiget`, and `sync-collection` with the owner's tokens and changes (PR 14), plus the ctag.
- All shares are read-only in this PR; writes get 403. Write access comes in PR 25. `current-user-privilege-set` shows read.
- Isolation: the owner's home answers 404; a third user gets 404; the Sharing and CardDAV switches hide the address book.

**Tests (both databases):** listing; reports; incremental sync after an owner change; 403 on writes; isolation; the switches.

**Done when:** a shared address book syncs read-only in DAVx5 and Thunderbird, an incremental sync after an owner change returns only that change, and non-sharees get 404.

### PR 25 — Shared address books: writing and leaving a share (Effort: High)

- **Read-write shares:** the sharee can create, change and delete contacts. The owner's sync token and change log move, so the owner's clients and other sharees see the change.
- The sharee's own display name (`PROPPATCH`) is stored with the share, not on the owner's address book.
- `DELETE` of the shared address book by the sharee removes only the share.
- Read-only shares still refuse writes; `current-user-privilege-set` shows write for read-write shares.
- Locks and dead properties on shared paths are kept under the sharee's path and removed with the share.

**Tests (both databases):** writes from the sharee seen by the owner and by another sharee; read-only refusals; leaving a share; cleanup of locks and properties.

**Done when:** a contact changed in DAVx5 through a read-write share shows up in the owner's Thunderbird and the other way round, read-only shares still refuse writes, and leaving a share keeps the owner's data.

### PR 26 — Received shares, the Sharing switch in the panel, and the Dashboard (Effort: Medium)

- The user page: a **Shared with this user** table (collection, owner, access) with remove.
- The Settings page: Sharing on or off, locked when `NEXUSDAV_SHARING` is set. The help text says that off hides shares and keeps them.
- The Dashboard: Sharing in the Services card.
- English and Russian text.

**Tests:** the received-shares table and remove; the switch from the panel, including the locked state; the Services card.

**Done when:** an admin sees and removes the shares a user received, switches Sharing from the panel, and the Services card shows it.

### PR 27 — M3 release (Effort: Medium)

- A Sharing section in the client checklist, run on DAVx5 (with jtx Board) and Thunderbird on both databases:
  - Read-only and read-write calendars, with tasks and notes
  - Read-only and read-write address books
  - Owner edits seen by the sharee, and the other way round
  - Removing a share and switching Sharing off
- Docs:
  - The guide: a Sharing section (how to share, what clients show, no sharing from clients) and the settings table
  - [apps](../apps/README.md): calendar, contacts, tasks, notes and admin pages
  - The [Users](../design/admin-users.md) and [Settings](../design/admin-settings.md) design notes
  - [architecture.md](../architecture.md) (sharing nodes), [data-model.md](../data-model.md) (share data), [dav-endpoints.md](../dav-endpoints.md) (shared collections in homes, 403 on client sharing requests)
- Release notes: Sharing is on, and nothing is shared until an admin creates a share.

**Done when:** the Sharing checklist passes in DAVx5 and Thunderbird on both databases, the docs match, and the release is published.

## M4 — Files (Effort: High)

**Goal:** each user has a private WebDAV file home at `/dav/files/<username>/`. Clients can upload, download, rename, move and delete files and folders there ([Files](../apps/files.md)).

### PR 28 — File home: storage, isolation and basic methods (Effort: High)

- A `files` root in `DavServerFactory`, with one home per principal. Another user's home answers 404.
- Storage at `/data/files/<username>/` (config `nexusdav.files.path`, not a panel setting; Decision 7), created on first use by the app user (`PUID` / `PGID`).
- File and folder nodes built on sabre's `FSExt` classes and hardened:
  - No path outside the home: `..`, encoded forms of it and absolute paths are refused, and symlinks are never followed
  - Names are checked (no control characters, at most 255 bytes) and normalised to Unicode NFC, so macOS and other clients see the same name
  - Writes go to a temporary file and are renamed into place, so a failed upload leaves nothing behind
  - ETags and last-modified dates from sabre's file nodes
- Methods: `GET` (with ranges), `HEAD`, `PUT`, `DELETE`, `MKCOL`, `PROPFIND` (depth 0 and 1; depth infinity stays off).
- **Files switch:** a `files` entry in `SettingsService::DEFINITIONS` (`NEXUSDAV_FILES`, default **off**; Decision 16). When off, `/dav/files/` answers 403 "Files is turned off on this server." and the files are kept.
- Deleting a user deletes their file home, with its locks and properties.

**Tests (both databases):** isolation; path-escape attempts, including a symlink planted on disk; name rules and NFC; a failed upload leaves no file; the switch; user deletion.

**Done when:** with Files on, GNOME Files (`davs://`) and rclone can list, upload, download and delete files and create folders; the path-escape and cross-user tests pass; and Files off answers 403 with the data kept.

### PR 29 — COPY, MOVE, locks and properties for files (Effort: High)

- `COPY` and `MOVE` inside the home, for files and folders, with `Overwrite` and `Depth`. A rename is a `MOVE`. A `COPY` or `MOVE` into another user's home answers 404.
- `LOCK` and `UNLOCK`, shared and exclusive, for office apps and Windows clients.
- Locks and dead properties move with `MOVE` and go with `DELETE`. sabre's property storage plugin calls the backend's `move` and `delete`; locks get the same treatment.
- macOS and Windows helper files (`._*`, `.DS_Store`, `desktop.ini`): the PR decides whether to load sabre's `TemporaryFileFilterPlugin`, which keeps them out of the user's home.

**Tests (both databases):** copy and move of files and of folder trees; `Overwrite` true and false; locked resources refused to other lock tokens; properties and locks after `MOVE` and `DELETE`; cross-home refusals.

**Done when:** GNOME Files and rclone can rename, move and copy files and folders; locks and properties follow `MOVE` and go with `DELETE`; and a `COPY` or `MOVE` into another home is refused.

### PR 30 — Upload size limit, streaming and a full disk (Effort: Medium)

- **Maximum upload size (MB)** on the Settings page: `NEXUSDAV_FILES_MAX_UPLOAD_MB`, default `128`. It applies to `/dav/files/` only.
- First check today's behaviour: PHP's `post_max_size` is meant for `POST`, so check whether the 128 MB limit applies to `PUT` at all. Either way, the app enforces the limit: an early 413 from `Content-Length`, and a byte count while streaming for uploads without it. The partial file is removed.
- Streaming: a 1 GB upload must not raise memory use. Check that FrankenPHP and `DavController` pass the body as a stream, and that PHP's time limits do not stop a long upload or a large `COPY`.
- A limit above 128 MB: the image's PHP limits follow it, and the [proxy files](../proxy/) say which body size to set (Settled question 3).
- A full disk answers 507 and leaves no partial file.

**Tests:** 413 with and without `Content-Length`; no file left behind; a streamed upload's memory use; 507 on a simulated full disk.

**Done when:** an upload over the limit gets 413 with and without `Content-Length` and leaves nothing behind, a 1 GB upload with the limit raised completes with flat memory use, and a full disk gives 507.

### PR 31 — Files in the admin panel (Effort: Medium)

- The Settings page: Files on or off, and Maximum upload size (shown when Files is on), each locked by its variable.
- The user page: "File home: exists" or "not created yet". No browsing and no sizes; storage statistics are Version 2.x.
- The delete-user confirmation says the file home is deleted.
- The Dashboard: Files in the Services card.
- English and Russian text.

**Tests:** the fields and their locked states; the file home indicator; the confirmation text; the Services card.

**Done when:** an admin can switch Files and set the limit in the panel, sees whether a user's file home exists, and the Services card shows Files.

### PR 32 — M4 release (Effort: Medium)

- A Files section in the client checklist, run with GNOME Files (`davs://`), rclone and DAVx5 WebDAV mounts on Android: upload, download, rename, move, copy and delete files and folders, a large upload, and an upload over the limit.
- Docs:
  - The guide: a Files section (switching it on, the URL, clients, the upload limit, app passwords also reach files per Decision 14), backup of `/data/files` together with the database, the TrueNAS dataset size, the proxy body size, and client compatibility (file clients; Windows and macOS not tested)
  - [apps/files.md](../apps/files.md), the [Settings design note](../design/admin-settings.md), [architecture.md](../architecture.md), [data-model.md](../data-model.md) and [dav-endpoints.md](../dav-endpoints.md)
- Release notes: Files is off after the upgrade.

**Done when:** the Files checklist passes with GNOME Files, rclone and DAVx5, the docs match, and the release is published.

## M5 — WebDAV-Push and the worker (Effort: Extra High)

**Goal:** a client that subscribes to a collection gets a push message when the collection changes, as in the pinned bitfire draft. Push is switched on or off in the admin panel. Subscriptions are visible on the Diagnostics page.

### PR 33 — Spike: Web Push library and DAVx5 push (Effort: Medium)

- Pick the Web Push library. The candidate is `minishlink/web-push` (MIT), which does message encryption (RFC 8291) and VAPID (RFC 8292). It sends through a PSR-18 HTTP client, so PR 37 can give it a client with the SSRF guard. Check whether the image should add the `gmp` extension, which the library suggests for speed.
- A prototype against a local ntfy server with DAVx5 on Android (UnifiedPush). Find out:
  - Which collections DAVx5 subscribes to, and with which triggers and depths
  - How it uses `Push-Dont-Notify`
  - What expiry it asks for, and when it renews
  - Whether it subscribes to WebDAV-mounted file folders
- Output: `docs/design/webdav-push.md`, covering:
  - The library
  - The data stored
  - The form of the registration URL (under `/dav/`)
  - Topics, and the triggers and depths supported
  - The longest expiry the server grants
  - The merge window and the retry policy

  The prototype is not merged.

**Done when:** the design note is merged, and a prototype push reached DAVx5 through ntfy.

### PR 34 — Worker service and heartbeat (Effort: Medium)

- A worker mode in the entrypoint (proposed `NEXUSDAV_ROLE=worker`): no migrations, no first admin and no web server. It caches the config like the app container, then runs `php artisan queue:work` as `nexusdav`, with a maximum run time so it restarts regularly to release memory.
- A `compose.worker.yaml` override with a `nexusdav-worker` service: the same image, environment and `/data` volume, no ports, `depends_on` the app being healthy, the same log rotation. The worker also needs the database variables from `compose.postgres.yaml` or `compose.sqlite.yaml`; the PR picks how (Compose `extends`, or one worker override per database).
- A heartbeat in the database every minute, since the file cache is per container. A `WorkerStatus` service answers: running, stale (no heartbeat for five minutes) or never seen.
- The TrueNAS files get an optional worker block, using the same dataset. With SQLite, both containers use the same local file (WAL works across processes on one host).
- The image smoke test starts the image in worker mode and checks that it runs a queued job.

**Tests:** worker mode in the entrypoint; `WorkerStatus` states; the smoke test.

**Done when:** `docker compose -f compose.yaml -f compose.<database>.yaml -f compose.worker.yaml up -d` starts a worker that runs queued jobs on both databases, `WorkerStatus` reports running and then stale after the worker stops, and the smoke test covers worker mode.

### PR 35 — Push discovery and subscription registration (Effort: High)

- Settings definitions (panel fields in PR 38):
  - `push` (`NEXUSDAV_PUSH`, default off)
  - `push_files` (`NEXUSDAV_PUSH_FILES`, default off)
  - `push_max_subscriptions` (`NEXUSDAV_PUSH_MAX_SUBSCRIPTIONS`, default `100`)
  - `push_allow_private` (`NEXUSDAV_PUSH_ALLOW_PRIVATE`, default off)
  - `push_log_level` (`NEXUSDAV_PUSH_LOG_LEVEL`)
- Discovery, when push is on:
  - `webdav-push` in the `DAV` header of `OPTIONS`.
  - The `transports` property (`web-push`, with the `vapid-public-key`), `topic` and `supported-triggers`.
  - Collections that offer push:
    - The calendar and address book homes: content updates, depth 1
    - Calendars and address books: content updates, depth 1; property updates, depth 0
    - With Include Files on: the file home and its folders, content updates, depth 1

  These follow the draft's CalDAV/CardDAV recommendations.
- Topics are random, opaque and stored per resource. A shared calendar or address book has one topic for the owner and every sharee, as the draft requires.
- The VAPID key pair is created on first use; the private key is stored encrypted with `APP_KEY`.
- **Registration:** `POST` with `push-register` to a collection.
  - The subscription is checked: an HTTPS push resource, plus the client's public key and authentication secret.
  - An unsupported depth falls back to the supported one.
  - Refusals are 403 with the draft's preconditions: `invalid-subscription`, `push-not-available` and `no-supported-trigger`.
  - The expiry is capped (PR 33 sets the cap).
  - The answer is 204, or 201 when there are details, with an absolute `Location` registration URL under `/dav/` and an `Expires` header.
  - Registering the same push resource again updates it and returns the same URL.
- **Unregister:** `DELETE` on the registration URL answers 204. Another user's URL or an unknown one answers 404.
- A `push_subscriptions` table:
  - user and app password
  - topic, trigger and depth
  - endpoint and client keys, encrypted
  - expiry, created, last push, last status and failure count
- Limits: over the per-user maximum, registration gets 403.
- Cleanup: subscriptions go when their app password is revoked, the user is disabled or deleted, the collection is deleted, or the share they came through is removed.
- Push off: nothing is advertised, registration gets 403 `push-not-available`, and existing subscriptions stay until they expire.

**Tests (both databases):** discovery on and off; registration, re-registration and expiry capping; each precondition; `DELETE` including another user's URL; the per-user limit; each cleanup path; secrets stored encrypted.

**Done when:** DAVx5 discovers push and registers on calendars, address books and their homes, re-registration and `DELETE` work, and the limits, preconditions and cleanup paths pass their tests on both databases.

### PR 36 — Change events and the push queue (Effort: High)

- Change detection in one place. A change is any successful write that moves a collection's sync token: calendar objects, cards, collection properties, and collections created or deleted in a home. With Include Files on, file and folder changes count too. The affected topics are recorded after the transaction commits.
- Matching subscriptions:
  - Content updates on the collection (depth 1) and on its home
  - Property updates on the collection (depth 0)
  - For files, the changed folder and the file home (Settled question 5)
  - Deleting a subscribed collection counts as a content update
- A merge window: one queued job per topic, delayed a few seconds (proposed 5). Later changes inside the window join it.
- `Push-Dont-Notify`: the caller's own registration URLs, or `*`, are skipped for that change. URLs that belong to another user are ignored, as the draft says.
- A message carries the topic and, for content updates, the new `DAV:sync-token`, as the draft recommends.
- Without a running worker, jobs wait in the queue. Jobs for removed subscriptions are dropped.

**Tests:** which topics each kind of change queues; a shared calendar change reaching sharee subscriptions through one topic; the merge window; `Push-Dont-Notify`, including another user's URL; no job while push is off.

**Done when:** every write path queues exactly one merged job per affected topic, shared collections reach all their subscribers, and `Push-Dont-Notify` is honoured.

### PR 37 — Web Push delivery and the SSRF guard (Effort: High)

- The job builds the encrypted message with VAPID (the subject comes from `APP_URL`), sets `TTL` and `Urgency`, and sends it to each matching subscription.
- **SSRF guard:**
  - HTTPS only.
  - The host is resolved once, and the connection is pinned to that address.
  - Loopback, private, link-local, multicast and reserved addresses, IPv4 and IPv6, are refused unless private networks are allowed.
  - No redirects; short connect and total timeouts; the response body is not read beyond a small limit.
- Results:
  - 2xx saves the last push and its status.
  - 404 or 410 removes the subscription.
  - 413, 429, 5xx and network errors retry with backoff; after the retries the subscription is marked as failing.
  - Expired subscriptions are pruned.
- Push log lines use their own level (`NEXUSDAV_PUSH_LOG_LEVEL`) and show only the endpoint host, never the full URL or keys.

**Tests:**
- A fake push service checks the encryption and the VAPID header.
- The SSRF matrix: private IPv4 and IPv6, a name that resolves to `127.0.0.1`, and a redirect.
- Removal on 404 and 410.
- Retry and backoff.
- No secret in the log.

**Done when:** DAVx5 receives a push through ntfy after a change made from another device, the SSRF tests pass, dead subscriptions are removed, and no secret appears in the log.

### PR 38 — Push in the admin panel (Effort: Medium)

- The Settings page: WebDAV-Push on or off, and its options (shown when on), each locked by its variable. The help text says that push needs the worker.
- The Diagnostics page:
  - A subscriptions table: user, collection, endpoint host, created, expires, last push and last status, with a revoke action
  - A worker status line
- The Dashboard:
  - WebDAV-Push and the worker status in the Services card
  - A Needs attention line when push is on and the worker is stale or never seen
- English and Russian text.

**Tests:** the fields and their locked states; the subscriptions table and revoke; the worker warning on the Dashboard and Diagnostics.

**Done when:** an admin can switch push on and set its options, sees and revokes subscriptions, and the Dashboard warns when push is on without a running worker.

### PR 39 — M5 release (Effort: Medium)

- A Push section in the client checklist, run with DAVx5 and ntfy on both databases:
  - A calendar and an address book
  - A shared calendar: an owner edit reaches the sharee's device
  - Revoking the app password stops the pushes

  Push on file folders is covered by the automated tests; no client in Decision 15 subscribes to them, unless PR 33 found that DAVx5 does.
- Docs:
  - The guide: a WebDAV-Push section, `compose.worker.yaml` in Configuration and Quick start, the TrueNAS worker block, and the settings table
  - [apps](../apps/README.md) and the design notes
  - [architecture.md](../architecture.md) (the worker), [data-model.md](../data-model.md) (push tables) and [dav-endpoints.md](../dav-endpoints.md) (push properties and registration URLs)
- Release notes: push is off after the upgrade and needs the worker override.

**Done when:** the Push checklist passes with DAVx5 on both databases, the docs match, and the release is published.

## Data changes (summary)

| PR | Change |
|---|---|
| PR 9 | None. `users.locale` exists; new settings rows only |
| PR 15 | `created_at` on `calendarchanges` and `addressbookchanges`; the oldest usable change number on `calendars` and `addressbooks` |
| PR 21 | Calendar shares use `calendarinstances` (exists). A new table for address book shares, in the shape chosen in PR 20 |
| PR 28, PR 29 | None for file content (on disk). Locks and dead properties use the existing `locks` and `propertystorage` tables |
| PR 34 | Worker heartbeat (a small table or row). Queue rows in the existing `jobs` and `failed_jobs` tables |
| PR 35 | New `push_subscriptions` table and stored push topics. VAPID keys stored encrypted |

## New settings (summary)

Names are proposals, confirmed in the PR that adds them. All follow the Version 1 rule: an environment variable wins and locks the field.

| Setting | Page | Variable (proposed) | Default | Defined in | Panel field in |
|---|---|---|---|---|---|
| Default interface language | Configuration | `NEXUSDAV_LOCALE` | `en` | PR 9 | PR 9 |
| Keep sync history for (days) | Settings | `NEXUSDAV_SYNC_HISTORY_DAYS` | `90` | PR 15 | PR 15 |
| Sharing on or off | Settings | `NEXUSDAV_SHARING` | on | PR 23 | PR 26 |
| Files on or off | Settings | `NEXUSDAV_FILES` | off | PR 28 | PR 31 |
| Maximum upload size (MB) | Settings | `NEXUSDAV_FILES_MAX_UPLOAD_MB` | `128` | PR 30 | PR 31 |
| WebDAV-Push on or off | Settings | `NEXUSDAV_PUSH` | off | PR 35 | PR 38 |
| Include Files in push | Settings | `NEXUSDAV_PUSH_FILES` | off | PR 35 | PR 38 |
| Maximum push subscriptions per user | Settings | `NEXUSDAV_PUSH_MAX_SUBSCRIPTIONS` | `100` | PR 35 | PR 38 |
| Push log level | Settings | `NEXUSDAV_PUSH_LOG_LEVEL` | the server log level | PR 35 | PR 38 |
| Allow push endpoints on private networks | Settings | `NEXUSDAV_PUSH_ALLOW_PRIVATE` | off | PR 35 | PR 38 |

## Settled questions

These were open when the plan was first written. Each is now decided.

| # | Question | Decision | PR |
|---|---|---|---|
| 1 | Does a shared collection stay visible to sharees when its owner is disabled? | Yes. Disabling stops sign-in only. Shares go when the owner is deleted or the admin removes them | PR 21, PR 23 |
| 2 | What happens when a sharee deletes a shared collection from their client? | The sharee leaves the share. The owner's data is untouched, and the panel shows the share as gone | PR 23, PR 25 |
| 3 | Can the maximum upload size go above 128 MB? | Yes, but then the PHP limits and the proxy files change in the same milestone | PR 30 |
| 4 | Does WebDAV-Push need its own URL, or can it use the `/dav/` URL that clients use for sync? | **The same URL.** In the pinned draft a client subscribes by `POST`ing to the collection it wants to follow, and reads the topic and VAPID public key with `PROPFIND` on it. The URLs the server sends to are the clients' own push-service addresses, not a NexusDAV setting. The "Push external URL (HTTPS)" field is dropped. The VAPID contact (the `sub` claim, RFC 8292) is taken from `APP_URL`, so it needs no field | PR 35, PR 37 |
| 5 | Does a change in a subfolder notify subscriptions on its parent folders? | Only the home and the changed folder | PR 36 |
| 6 | Default maximum push subscriptions per user | `100`, enough for several devices that each subscribe to every collection | PR 35 |

## Docs

**Fixed together with this plan.** The planning found contradictions between the docs. These were corrected so the docs now match the decisions above:

- [design/admin-settings.md](../design/admin-settings.md): Version 1.x now lists only Sharing, Files (with the maximum upload size) and WebDAV-Push settings. The storage path, per-user quota, trash and deleted-user retention fields and the "Push external URL" field are removed.
- [apps/admin.md](../apps/admin.md): sharing is done in the panel and clients do not create shares; the upload size limit replaces "per-user disk space limits"; English and Russian; Files and push are off after an upgrade; the push worker status on Diagnostics.
- [apps/calendar.md](../apps/calendar.md) and [apps/contacts.md](../apps/contacts.md): the client column for sharing.
- [apps/files.md](../apps/files.md): off after an upgrade, and app passwords also work for files.
- [design/admin-configuration.md](../design/admin-configuration.md) and [design/admin-dashboard.md](../design/admin-dashboard.md): languages, and the worker warning.
- [guide.md](../guide.md): the "Nothing is released yet" line, the sharing row in the protocols table, the 1.x roadmap lines, and the app password reach.

**Still to do, in the PR named**, because they describe code that does not exist yet:

| Doc | Change | PR |
|---|---|---|
| [guide.md](../guide.md) | Settings table: the new variables. Configuration: `compose.worker.yaml`. Client compatibility: tested versions and the results of the checklist | Release PRs 12, 19, 27, 32, 39 |
| [architecture.md](../architecture.md) | Titled "Version 1". Add the worker service, the Files root and storage, and the sharing nodes | PR 27, 32, 39 |
| [data-model.md](../data-model.md), [dav-endpoints.md](../dav-endpoints.md) | New tables, the Files endpoint and push registration through the collection URLs | PR 19, 27, 32, 39 |
| [CONTRIBUTING.md](../../CONTRIBUTING.md) | Adding a language | PR 9 |

## Out of scope for Version 1.x

- User portal (Version 2)
- Sharing created from a client, sharing with groups, and sharing files or folders
- Public read-only links and ICS subscription URLs
- On-server scheduling (Version 2), email (Version 2.x)
- Import and export, search (Version 2)
- User quotas and storage statistics (Version 2.x), file trash, retention of a deleted user's files
- Sync tokens on the file home
- Managed attachments (Version 2.x)
- App password scopes (Decision 14)
- Hand testing by the project on Apple, macOS and Windows clients (Decision 15)
- More languages shipped by the project than English and Russian (contributors can add them)
- More automated client tests in CI, performance work on large collections, and conformance suites (CalDAVTester, litmus)
- `*_FILE` secrets (still not assigned to a version)
