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

**Status:** M1 is done and released as **0.1.2** (PR 9 to PR 12; see the notes under [M1](#m1--multilingual-admin-panel-effort-medium)). M2 is done and released as **0.2.0** (PR 13 to PR 19; see the notes under [M2](#m2--sync-reliability-effort-high)). M3 is done and released as **0.3.0** (PR 20 to PR 27). The Sharing pass of the [client checklist](../testing/client-checklist.md#11-sharing) passed on 0.3.0+404d77b with DAVx5 + jtx Board, Thunderbird and an iPad on both databases (Pass 3 table; all of step 11 passed on both databases). M4 is done and released as **0.4.0** (PR 28 to PR 32; the release pass over HTTPS passed on both databases, see [Files testing](../testing/files-testing.md#results-of-the-release-pass)). M5 is done and released as **0.5.0** (PR 33 to PR 39; the release pass of step 12 of the [client checklist](../testing/client-checklist.md#12-webdav-push) and the [push testing](../testing/push-testing.md) passed on both databases, with DAVx⁵ on a phone). Release numbers stay 0.x until the whole plan is done; the [Version 1.x.x plan](implementation-plan-v1xx.md) continues from PR 40 and ends with 1.6.0.

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
| 7 | PR 15 | M2 | Sync history retention (done) | Medium | 14 |
| 8 | PR 16 | M2 | Atomic conditional writes (done) | High | — |
| 9 | PR 17 | M2 | Busy database: retry, then 503 (done, 0.1.6) | Medium | 16 |
| 10 | PR 18 | M2 | Fixes from the client pass (done) | Medium, re-rated after PR 13 | 13 |
| 11 | PR 19 | M2 | M2 release (done, 0.2.0) | Medium | 12, 13–18 |
| 12 | PR 20 | M3 | Spike: address book sharing design | Medium | — |
| 13 | PR 21 | M3 | Share storage and `SharingService` (done) | High | 20 |
| 14 | PR 22 | M3 | Share management in the admin panel (done) | High | 11, 21 |
| 15 | PR 23 | M3 | Shared calendars over DAV and the Sharing switch (done) | High | 14, 21 |
| 16 | PR 24 | M3 | Shared address books: reading and sync (merged; client test open) | High | 14, 21 |
| 17 | PR 25 | M3 | Shared address books: writing and leaving a share (merged; client test open) | High | 16, 24 |
| 18 | PR 26 | M3 | Received shares, the Sharing switch in the panel, and the Dashboard (done) | Medium | 22, 23 |
| 19 | PR 27 | M3 | M3 release (client pass done; publish next) | Medium | 19, 21–26 |
| 20 | PR 28 | M4 | File home: storage, isolation and basic methods (done) | High | — |
| 21 | PR 29 | M4 | COPY, MOVE, locks and properties for files (done) | High | 28 |
| 22 | PR 30 | M4 | Upload size limit, streaming and a full disk (done) | Medium | 28 |
| 23 | PR 31 | M4 | Files in the admin panel (done) | Medium | 28, 30 |
| 24 | PR 32 | M4 | M4 release (done, 0.4.0) | Medium | 27, 28–31 |
| 25 | PR 33 | M5 | Spike: Web Push library and DAVx5 push (done; phone test in PR 35) | Medium | — |
| 26 | PR 34 | M5 | Worker service and heartbeat (done) | Medium | — |
| 27 | PR 35 | M5 | Push discovery and subscription registration (implemented; DAVx5 check open) | High | 23, 24, 28, 33 |
| 28 | PR 36 | M5 | Change events and the push queue (implemented) | High | 34, 35 |
| 29 | PR 37 | M5 | Web Push delivery and the SSRF guard (implemented; DAVx5 check open) | High | 33, 36 |
| 30 | PR 38 | M5 | Push in the admin panel (implemented) | Medium | 34, 35, 37 |
| 31 | PR 39 | M5 | M5 release (released as 0.5.0) | Medium | 32, 33–38 |

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
| 2 | Who creates shares | **In Version 1.x, the admin panel only.** Clients see and use shared collections. Sharing requests sent by a client are refused, and sharing is not advertised to clients. The user portal (Version 2) will let users share their own collections through the same `SharingService`; that is the plan in [admin.md](../apps/admin.md#who-does-what) and is not ruled out here |
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

### PR 15 — Sync history retention (Effort: Medium) — ✅ done

- Migration: a `created_at` column (database default: now) on `calendarchanges` and `addressbookchanges`; existing rows get the migration time. Each calendar and address book records the oldest change number it can still answer for.
- **Keep sync history for (days)** on the Settings page: `NEXUSDAV_SYNC_HISTORY_DAYS`, default `90`.
- Pruning on write, like diagnostics pruning (Version 1 Decision 11): at most once per hour, in batches, moving the oldest change number forward in the same transaction. An index supports the pruning query.
- A token older than the kept history gets `valid-sync-token`.

**Tests (both databases):** rows at the retention boundary; a token older than the pruned history; batch pruning; no pruning while the history is shorter than the setting.

**Done when:** history older than the setting is removed on both databases, a token older than the history gets `valid-sync-token`, and a newer token still gets exactly the changes since it.

**Status:** done.

- Migration adds `created_at` (database default now) on `calendarchanges` and `addressbookchanges`, and `synctoken_min` (default 1) on `calendars` and `addressbooks`. Existing change rows take the migration time.
- **Keep sync history for** on the Settings page, `NEXUSDAV_SYNC_HISTORY_DAYS`, default 90, minimum 1, maximum 3650. The variable locks the field.
- `SyncHistoryPruner` runs from a calendar or address book write, at most once an hour, one batch of 1000 rows per table. In the same transaction it deletes the oldest expired rows and moves `synctoken_min` forward to one past the newest change number it deleted. A token below that number gets `valid-sync-token`. A token at or above it still returns only the changes since that token.
- Tests (`SyncHistoryTest`, SQLite and PostgreSQL): the retention boundary, no pruning while every row is inside the window, batch pruning, both collections' old and new tokens, pruning triggered by a write, and the Settings field.
- Fixed in review:
  - **Upgrade:** SQLite cannot add a column with a `CURRENT_TIMESTAMP` default to a table that has rows, so the migration stopped every SQLite server that already had sync history. It now adds the column empty, stamps the existing rows, then sets the default. Tested by upgrading a database with history, on both databases.
  - **Floor:** `synctoken_min` was the oldest change number still stored. On PostgreSQL `created_at` is the start of the writing transaction, so a later change number can carry an earlier time and be deleted first; a token that needed it was then accepted, and the client silently missed that change. The floor is now one past the newest deleted number.
  - **Time zone:** the PostgreSQL session is set to UTC (`config/database.php`). A PostgreSQL container started with `TZ` stamped `created_at` in its local time, so history was pruned hours early or late.
  - **Errors:** a failure while pruning is logged and no longer turns the client's write into a 500.
- Not changed: one batch of 1,000 rows per table per hour removes up to 24,000 rows a day per table. A server that writes more than that keeps more history than the setting until writes slow down.

### PR 16 — Atomic conditional writes (Effort: High) — ✅ done

- `PUT`, `DELETE`, `MOVE` and `PROPPATCH` on calendars and address books run in one database transaction. It starts in sabre's `beforeMethod` event, which runs before sabre checks `If-Match` and `If-None-Match`, and ends after the write. Writes to the same collection wait for each other: SQLite takes the write lock at the start (`BEGIN IMMEDIATE`), PostgreSQL a transaction-level advisory lock on the collection.
- Two writes with the same `If-Match`: one succeeds, the other gets 412.
- Two creates of the same new URI: with `If-None-Match: *` the second gets 412; without it, the second replaces the first, as `PUT` normally does. Neither gives a 500 from the unique index.
- The ETag returned by `PUT` matches what `GET` returns afterwards, or no ETag is returned when the server changed the data (RFC 4791 allows that). Tested for events, tasks, notes and contacts.
- Only CalDAV and CardDAV paths. File uploads (M4) are not held in a database transaction.

**Tests:** two processes writing at the same time against a real database file on SQLite (not `:memory:`) and against PostgreSQL.

**Done when:** the concurrency tests pass on both databases, and no write race gives a 500 or a silent overwrite.

Issue #3 has a first case from the client pass: one 500 "database is locked" in 5,000 parallel event uploads on SQLite (none on PostgreSQL).

**Status:** done.

- `App\Services\Dav\DavWriteLock` opens the transaction for `PUT`, `DELETE`, `MOVE`, `COPY`, `PROPPATCH`, `MKCOL` and `MKCALENDAR` on a calendar or address book path. It runs at `beforeMethod` priority 110: after authentication (10), the ACL check (20) and the service switches (100), so a refused request never takes the lock. `MOVE` and `COPY` lock the source and the destination collection, in sorted order.
- SQLite: every Laravel transaction is now `BEGIN IMMEDIATE` (`transaction_mode` in `config/database.php`), so the write lock is taken first and `busy_timeout` applies. PostgreSQL: `pg_advisory_xact_lock` per collection, with `lock_timeout` 5 s like SQLite's busy wait.
- **Found while testing:** sabre's ACL check loads the target node before the lock, and sabre's tree keeps that node with its ETag. The precondition check then used the stale ETag, so the lock alone did not stop a silent overwrite. The tree cache is cleared once the lock is held.
- The transaction ends in `DavController` after sabre answers: committed, or rolled back when sabre reported an exception. sabre answers a failed precondition (412) without `afterMethod`, so ending in a sabre event would leave it open.
- `App\Events\DavServerCreated` fires when a request's DAV server is built; the concurrency test uses it to hold a write open.
- Tests: `ConcurrentWritesTest` runs separate PHP processes that send at the same moment, against a real SQLite file and a separate PostgreSQL database: same `If-Match` (204 and 412), two creates with `If-None-Match: *` (201 and 412), two creates without (201 and 204), a delete racing an update (204 and 412), and eight parallel uploads (all 201). Without the lock four of the five fail on both databases (two 204s, a 500 from the unique index, or a delete and an update both succeeding). `PutEtagTest` checks the `PUT` ETag against `GET` for an event, a task, a note and a contact, and that no ETag is returned when the server changes the data.
- Tag `v0.1.5` (no GitHub Release page) publishes PR 15, PR 16, the readable log levels and the Calendars table columns for lab testing.
- #3 stays open: the lab error was a plain `INSERT` that waited the full 5 s for the lock, which this PR does not shorten. PR 17 (retry, then 503) covers that. The race that this PR removes, a read followed by a write in a deferred transaction, could not be reproduced locally either before or after.

### PR 17 — Busy database: retry, then 503 (Effort: Medium) — ✅ done

- An SQLite busy error after the 5-second timeout, or a PostgreSQL serialization failure (`40001`) or deadlock (`40P01`) inside a DAV write: the transaction from PR 16 is retried up to three times after a short random wait. The request body is kept so the write can be replayed; CalDAV and CardDAV bodies are small.
- After the retries, or on a busy error during a read: 503 with `Retry-After`, stored on Diagnostics like other DAV errors, with the request ID in the log.

**Tests:** a second connection holding the SQLite write lock; a forced PostgreSQL serialization failure; the retry count; the 503 and its Diagnostics row.

**Done when:** lock contention never produces a 500 on either database; the client gets its result after a retry, or a 503 with `Retry-After`.

Issue #3 again on 0.1.5, lab SQLite server: 5,000 event uploads, 8 at a time, all 201; 16 at a time, 3 and then 6 of 5,000 got 500 "database is locked" after waiting 5.2 to 9.7 s. Six of the nine were on the app password "last used" update during sign-in, not on the write itself, and every 500 body showed the SQL and the database file path.

**Status:** done.

- `DavController` keeps the request body and runs sabre again, with a fresh server and transaction, when the answer was caused by a busy database (`App\Services\Dav\BusyDatabase`: SQLite "database is locked", PostgreSQL `40001`, `40P01` and `55P03`, the `lock_timeout` from PR 16). Up to three retries, after a random wait of 50–250 ms times the attempt number. A failed commit counts as busy too: the answer sabre built is then discarded.
- After the retries, and at once for a read (only writes are replayed): 503 with `Retry-After: 5` and the request ID in the body. The 503 is stored on Diagnostics like other DAV errors when the database lets it be written. Each retry is a warning in the log, the 503 an error.
- **Found while testing:** the app password "last used" update is skipped when the database is busy instead of failing the request; the next request records it.
- **Found while testing:** a 500 from an unexpected exception no longer shows its text (SQL, file paths): the body says "Internal server error." with the request ID, and the details are in the log. sabre's own DAV errors (4xx) keep their messages.
- `busy_timeout` reads `DB_BUSY_TIMEOUT` (default 5000 ms) so tests can shorten it; it is not documented as a setting.
- Tests: `BusyDatabaseTest` (retry then success, 503 after four tries with its Diagnostics row, the three PostgreSQL states, a read answered 503 at once, a 500 without the exception text, a busy "last used" update). `ConcurrentWritesTest` holds a real SQLite write lock from a second connection: released after 0.4 s the write succeeds through a retry (and gets 503 with retries switched off), held for good it gets 503.
- Tag `v0.1.6` (no GitHub Release page), also with the expiry date picker that follows Time format and Week starts on.
- Tag `v0.1.7` (no GitHub Release page): the client pass 2 test round on 0.1.6, the **Close messages after** setting (`NEXUSDAV_NOTIFICATION_SECONDS`), the commit shown on the Dashboard (`0.1.7+<commit>`), the Calendars and Address books tables that wrap long URIs, and the PR 18 and PR 19 docs.
- Tag `v0.2.0`, the M2 release: everything from 0.1.3 to 0.1.7, plus the Language menu that shows which language is in use (and the notice when the server default changes), and the inactivity timeout that returns an idle admin to the sign-in page with a message that stays until closed.

### PR 18 — Fixes from the client pass (Effort: Medium, re-rated after PR 13) — ✅ done

- One fix and one regression test for each `m2-client-pass` issue that PR 14 to PR 17 did not already fix, or a documented limitation in the guide's Troubleshooting.
- When the list is long, split it into one PR per area (CalDAV, CardDAV, discovery).

**Done when:** every `m2-client-pass` issue is closed with a fix or a documented limitation.

**Status:** done. No `m2-client-pass` issue is open (4 November 2026).

| Issue | Outcome |
|---|---|
| #1 DAVx5 first contact sync takes about 10 min | Documented limitation: 98 % of the time is DAVx5 writing into Android's Contacts storage (guide, Troubleshooting) |
| #2 Old events stay after a calendar is deleted and created again | Fixed by PR 14 (sync tokens `<collection id>-<number>`, 403 `valid-sync-token`), with `SyncTokenTest` |
| #3 SQLite "database is locked" under parallel uploads | Fixed by PR 16 and PR 17; 16 and 32 parallel writers, 5,000 uploads each, no 500 on 0.1.6 |

- Observations from the pass that are not server bugs are now in the guide's Troubleshooting: the second device's refused write (412, no message on the client), an iPad keeping the old events of a calendar deleted and created again, DAVx5 not using sync tokens when "Time range (past days)" is set, and a full resync after a long time offline.

### PR 19 — M2 release (Effort: Medium) — ✅ done

- The full checklist again on the release candidate, with both clients and both databases.
- Docs:
  - The guide's client compatibility table (versions, Thunderbird tasks), its settings table (`NEXUSDAV_SYNC_HISTORY_DAYS`), and Troubleshooting (a full resync after a long time offline; a 503 under heavy load)
  - [data-model.md](../data-model.md) (new columns) and [architecture.md](../architecture.md)
- Release notes: the new token format, legacy tokens still accepted, and the 90-day history default.

**Status:** done, released as **0.2.0** (with a GitHub Release page and notes). The checklist (Pass 2) passed on 0.1.6 with DAVx5, Thunderbird and an iPad on both databases, with step 10 as a server-side check; 0.1.7 and 0.2.0 changed the admin panel only. The guide, [dav-endpoints.md](../dav-endpoints.md), [data-model.md](../data-model.md) and [architecture.md](../architecture.md) match.

**Done when:** the checklist passes with both clients on both databases, the docs match, and the release is published.

## M3 — Sharing and ACL (Effort: Extra High)

**Goal:** an admin can share a user's calendar or address book with another user, read-only or read-write. The other user's clients see it in their own home.

### PR 20 — Spike: address book sharing design (Effort: Medium) — ✅ done

- A throwaway prototype of a shared address book node in the sharee's address book home: read and read-write access, the owner's sync token and changes, the sharee's own display name, and leaving the share.
- A check of the calendar side with sabre's `calendarinstances`, without loading sabre's sharing plugin: the sharee's instance appears, `current-user-privilege-set` is right, and the owner's principal still answers 404.
- Decisions, written down:
  - The shape of the address book share data: a share table, or an instance table like calendars have
  - The URI of a shared collection in the sharee's home. Proposed: the owner's URI, with `-<owner username>` added when the sharee already uses that URI
  - How the homes list shared children and include them in the home sync token
  - What `DAV:owner` shows. Decided in [sharing.md](../design/sharing.md): the sharee's principal, as sabre's calendar sharing does, because the owner's principal answers 404 to the sharee (Decision 5)
- Output: [docs/design/sharing.md](../design/sharing.md) with the decisions. The prototype code is not merged.

**Done when:** the design note is merged, and the prototype synced a read-only and a read-write shared address book in DAVx5 and Thunderbird. (Met; the note is merged with this PR.)

**Status:** [sharing.md](../design/sharing.md) records the decisions (`addressbookinstances`, the share URI, the home sync token, and `DAV:owner` as the sharee's principal). **Done:** the prototype ran on a lab server and passed the scripted DAV checks for read-only and read-write shares of an address book and of a calendar, and for leaving a share. In the clients, read-only and read-write address book shares work in DAVx5 and Thunderbird; Thunderbird keeps its own Read-only flag after the access changes, and neither client can delete a shared book ([findings](../design/sharing.md#findings-from-the-prototype)). The spike found three things for later PRs: the PostgreSQL share index and the write lock keyed on the path (PR 21 and PR 23, above), and that renaming a shared calendar bumps the owner's sync token (PR 23). The prototype code was merged, and its branch, tag, and image were removed after these findings were recorded.

### PR 21 — Share storage and `SharingService` (Effort: High) — ✅ done

- The migration for address book shares, in the shape chosen in PR 20.
- Calendar shares use `calendarinstances` rows: access `2` (read) or `3` (read-write), invite status accepted, and `share_href` set to the sharee's principal.
- Address book shares use `addressbookinstances` rows linked to `addressbooks` ([sharing.md](../design/sharing.md#addressbookinstances-table)).
- **A migration on `calendarinstances` for PostgreSQL:** drop the unique (`principaluri`, `share_href`) and add unique (`calendarid`, `share_href`), the index SQLite already has. As it is, one person can receive only one share on the whole server, because `share_href` is the sharee. The unique (`principaluri`, `calendarid`) stays. The migration must be safe on a table with rows ([sharing.md](../design/sharing.md#indexes-and-constraints)).
- `SharingService`: share, change the access, unshare, list the sharees of a collection, list the shares a user received, and list every instance of a collection (M5 needs it).
- The sharee's copy:
  - Its display name starts as "<name> (<owner's display name>)" (Decision 17)
  - Its color and order are copied from the owner's (calendars; address books have neither)
  - Its URI is unique in the sharee's home
- Rules:
  - No share with the owner
  - One share per sharee and collection
  - A disabled owner or sharee keeps the share. Disabling stops sign-in only (Settled question 1)
- Cleanup: deleting a collection, from the panel or over DAV, removes all its shares; deleting a user removes the shares they own and the shares they received (`DavDataCleaner`).
- No panel and no DAV changes yet. Nothing can be shared in a build from this PR alone.

**Tests (both databases):** the rules; changing access; cleanup on collection and user deletion; listing every instance of a collection; one user receiving calendar shares from two owners (it fails on PostgreSQL before the migration), and a second share of the same calendar to the same user being refused.

**Done when:** shares can be created, changed and removed through the service on both databases, and collection and user deletion leave no share rows.

**Status:** done.

- `addressbookinstances` (migration `2026_11_06_000000`) and the PostgreSQL index change on `calendarinstances` (`2026_11_06_000100`, a no-op on SQLite). Checked on a PostgreSQL database with existing calendars, with a rollback and a second run. Without the index change, the test of one user receiving calendar shares from two owners fails on PostgreSQL with the old unique key.
- `App\Services\Dav\SharingService`: `share` (creates the share, or changes the access of the one the user already has), `changeAccess`, `unshare`, `sharees`, `receivedCalendarShares`, `receivedAddressBookShares` and `instances`. A calendar is passed as the owner's instance; a sharee's instance is refused. `AddressBookInstance` is the model for the new table, and `CalendarInstance::ownerInstance()` names the owner of a received calendar share.
- The name in the sharee's home follows the design: the owner's URI, then `-<owner username>`, `-2`, and so on, shortened to 200 characters. `App\Services\Dav\HomeNamespace` holds the names of one home across its two tables and the principal lock. Every writer that picks a name takes the lock and reads the same set: shares, `MKCOL` and `MKCALENDAR` (the backends answer 405 for a name the other table has), subscriptions, and the panel's checks and suggestions. A unique-key hit is retried up to five times, each in a new transaction.
- **Found while building it:** a received calendar share is a `calendarinstances` row of the sharee, so the user's `calendarInstances` relation (the panel's Calendars table and the user's counts) and the Dashboard count would have listed it as an owned calendar, and the panel's Edit would have changed the owner's components. They now read access `1` only, and `CollectionService` refuses a received share. Deleting an owner's collection also removes each sharee's locks and properties for it.
- **Found in review:** a share could be written for a user deleted a moment earlier, and the next user created with that username would have inherited it (and lost their own default calendar to the name clash). The principal lock now refuses a principal that no longer exists, and deleting a user takes the same lock. `MKCALENDAR` and the panel also re-read the instance table under the lock, so a share that took the name in the meantime is a 405 or the usual "That URI is already used", not a 500. Rolling the index migration back removes the calendar shares first, because sabre's index cannot hold two shares of one sharee and the earlier code would list a share as an owned calendar.
- Tests: `SharingServiceTest` (21 tests, SQLite and PostgreSQL), including a retry after another writer takes the chosen name, a share for a deleted user, and `MKCOL` of a share's name answering 405. The migrations were also run, rolled back and run again on PostgreSQL with a user holding two calendar shares.

### PR 22 — Share management in the admin panel (Effort: High) — ✅ done

- Two actions on each calendar and address book row of the user page:
  - **Share** opens a small form: choose one or more users (found by username, display name or email; the owner and current sharees are left out) and one access level, **Read only** or **Read and write**. Submit again with other users and another level to share with groups at different levels
  - **Shared with** opens a slide-over with a table of the sharees (search, filter by access, paging). Access is changed in the row, and a row can be removed with a confirmation. **Set access** and **Remove** also work on several selected rows at once
  - The help text says that **Read and write** lets the sharee change and delete the owner's items for everyone, and that a client may need the calendar or address book set or added again after the access changes (Thunderbird keeps its own Read-only flag). The text is a tooltip on an information icon next to **Access**, reachable by keyboard
- A shared indicator (number of sharees) in the Calendars and Address books tables.
- The delete confirmations: deleting a collection says its shares are removed; deleting a user lists the shares they own and received.
- English and Russian text.
- Address book shares are served to clients from PR 24, read-only until PR 25.

**Tests:** Livewire tests for add, change and remove; the excluded users; the confirmation texts.

**Done when:** an admin can add, change and remove the sharees of any calendar or address book, and the rows match what `SharingService` reads.

**Status:** done.

- A **Share** action on each owned calendar and address book. The modal lists each sharee and their access, adds a user found by username, display name or email (the owner and current sharees are not offered), and changes access on save. **Read and write** says that the sharee changes and deletes the owner's items for everyone, and that a client may need the calendar or address book set or added again (Thunderbird keeps its own Read-only flag). Remove asks for confirmation and calls `unshare`.
- The tables show how many sharees `SharingService::sharees` returns. Deleting a collection says its shares are removed. Deleting a user lists the shares they own and the shares they received.
- The panel calls `share`, `changeAccess`, `unshare` and `sharees`. It does not write the share tables. Nothing here is served over DAV (that is PR 23 and PR 24).
- Tests: `ShareAdminTest` (SQLite and PostgreSQL).

### PR 23 — Shared calendars over DAV and the Sharing switch (Effort: High) — ✅ done

**Client test (6 November 2026, pre-release `0.2.1-sharing.1`):** the maintainer shared calendars with three users at different access levels and checked calendars and notes in Thunderbird, on an iPad and in DAVx5 with jtx Board on a Pixel 7 Pro. Everything worked as specified, including read-only refusals and read-write saves into the owner's collection.

- Shared calendars appear in the sharee's calendar home, with their tasks and notes, and in the home's sync token.
- **Read-only:** writes are refused with 403 (`PUT`, `DELETE` of an item, `PROPPATCH` of the owner's properties, `MOVE` out). `current-user-privilege-set` shows read only, so DAVx5 and Thunderbird show the calendar as read-only.
- **Read-write:** the sharee can create, change and delete events, tasks and notes.
- The sharee's display name, color and order are their own. Changing them does not touch the owner's, **and does not move the owner's sync token** (sabre's `updateCalendar` records a change, which the spike showed on every rename). Test it.
- `DELETE` of the shared calendar by the sharee leaves the share; the owner's data stays (Settled question 2).
- No sharing from clients: sabre's sharing plugin is not loaded, sharing `POST` requests (`share`, `invite-reply`) get 403, and no sharing modes are advertised. The properties sabre's calendar node returns are checked so they show no other sharees.
- Isolation: the owner's principal and homes still answer 404 to the sharee; a third user sees nothing.
- The Tasks and Notes switches apply to shared calendars.
- Sync: the sharee's tokens follow the owner's calendar (PR 14). A removed share is reported as deleted in the sharee's home sync.
- **Write lock by collection id.** `DavWriteLock` (PR 16) keys its PostgreSQL advisory lock on the request path, `calendars/<user>/<uri>`. A shared collection has two paths, the owner's and the sharee's, so writes through both would not wait for each other and `If-Match` would stop protecting them. The lock is keyed on `calendars.id` or `addressbooks.id` instead, looked up from the path (the owner's row, or a share row's `calendarid` or `addressbookid`), and falls back to the path when the collection does not exist yet (`MKCOL`, `MKCALENDAR`). `MOVE` and `COPY` lock the source and the destination in one sorted order, as now ([sharing.md](../design/sharing.md#write-lock)). It covers address books too, so PR 24 and PR 25 reuse it.
- **Sharing switch:** a `sharing` entry in `SettingsService::DEFINITIONS` (`NEXUSDAV_SHARING`, default on). When off, sharee copies are hidden and their URLs answer 404; the rows are kept. The panel field comes in PR 26.

**Tests (both databases):** every method against read-only and read-write shares; isolation; the switch; the sharee's incremental sync after an owner change; a disabled owner; two processes writing the same event with the same `If-Match`, one through the owner's path and one through the sharee's, where one gets 204 and the other 412 (`ConcurrentWritesTest` style, PostgreSQL included).

**Done when:**
- A calendar shared read-only and read-write syncs correctly in DAVx5 (with tasks and notes in jtx Board) and in Thunderbird.
- Read-only writes get 403.
- Non-sharees get 404.
- Switching Sharing off hides shares at once.


**Status:** implemented and merged; the hand test in the clients (below) is still open, so not done yet.

- `CalendarHome` builds every calendar as `CalendarNode` (a `SharedCalendar` with its own ACL): an owner gets read and write plus read-free-busy for authenticated users, a read-only sharee read and `write-properties`, a read-write sharee read and write. No `{DAV:}share` and no calendar-proxy principal, for owners either.
- `CalendarBackend`: a sharee's `PROPPATCH` writes their instance and does not record a change, so the owner's token stays (the spike showed it moved on every rename). A bare change number is refused on a share. Deleting a share clears the sharee's locks and properties for it.
- `DavWriteLock` keys the PostgreSQL lock on `calendars.id` or `addressbooks.id` (owner's row or share row), and on the path for a collection that does not exist yet. The lookup runs on PostgreSQL only.
- A client sharing `POST` is 403. `sharing` in `SettingsService::DEFINITIONS` (`NEXUSDAV_SHARING`, default on) hides shared calendars and keeps the rows.
- Tests: `SharedCalendarsTest` (SQLite and PostgreSQL), and the two-path `If-Match` race in `ConcurrentWritesTest`.
- **Found in review:** PR 22 had hidden calendar shares from DAV as a stopgap; the merge keeps PR 23's switch, and the PR 22 test now checks that a read-only share is served and refuses a write. The share modal's help moved into a keyboard-reachable tooltip, with a translated field name for validation messages. The guide's settings table and `dav-endpoints.md` describe shared calendars and the switch.
- Still open: the hand test with DAVx5 (and jtx Board) and Thunderbird on a shared calendar, read only and read and write.
### PR 24 — Shared address books: reading and sync (Effort: High) — implemented

**Built:** `AddressBookNode` (the shared node: privileges from the share, never from `DAV:owner`; it refuses write, rename and `DELETE`, so sabre's stock delete cannot remove the owner's book; a card gets its own read-only ACL, because sabre's `getChild` builds one with none), `AddressBookHome` (builds the shared node for a share and sabre's for an owned book), and `AddressBookBackend::getAddressBooksForUser` (appends the sharee's shares with the owner's id, sync token and ctag, hidden when Sharing is off). The shared node refuses a bare change number as a sync token. PR 24 served every share read-only; PR 25 lifted that.

**Client test (open):** the maintainer checks a shared address book in DAVx5 and Thunderbird: it lists and syncs, it is read-only, and an owner change arrives as one incremental sync.

- The shared address book node chosen in PR 20, in the sharee's address book home, included in the home listing and its sync token.
- Read access: `PROPFIND`, `GET`, `addressbook-query`, `addressbook-multiget`, and `sync-collection` with the owner's tokens and changes (PR 14), plus the ctag.
- All shares are read-only in this PR; writes get 403. Write access comes in PR 25. `current-user-privilege-set` shows read.
- Isolation: the owner's home answers 404; a third user gets 404; the Sharing and CardDAV switches hide the address book.

**Tests (both databases):** listing; reports; incremental sync after an owner change; 403 on writes; isolation; the switches.

**Done when:** a shared address book syncs read-only in DAVx5 and Thunderbird, an incremental sync after an owner change returns only that change, and non-sharees get 404.

### PR 25 — Shared address books: writing and leaving a share (Effort: High) — implemented

**Built:** `AddressBookNode` takes its privileges from the share (read only: `read` and `write-properties` on the book; read and write: `read` and `write`; never `all` or `share`). A write goes to the owner's book, so the owner's token and change log move. `AddressBookBackend::updateShare` keeps the sharee's name and description on their own row, with no change row. `leaveShare` is the node's `delete`: it removes the sharee's row and the locks and properties under their path. The owner's book and cards are never touched by those two. Tests: reads and writes through a read-write share seen by the owner and another sharee, read-only refusals (also after the access is lowered), rename, leaving a share, and the two-path `If-Match` race for a contact (`ConcurrentWritesTest`).

**Client test (open):** DAVx5 and Thunderbird, a contact changed through a read-write share reaches the owner's client and the other way round.

- **Read-write shares:** the sharee can create, change and delete contacts. The owner's sync token and change log move, so the owner's clients and other sharees see the change.
- The sharee's own display name (`PROPPATCH`) is stored with the share, not on the owner's address book.
- `DELETE` of the shared address book by the sharee removes only the share. DAVx5 and Thunderbird never send it (DAVx5 only switches syncing off), so test it with raw requests and document the clients' behaviour in the guide.
- Read-only shares still refuse writes; `current-user-privilege-set` shows write for read-write shares.
- Locks and dead properties on shared paths are kept under the sharee's path and removed with the share.

**Tests (both databases):** writes from the sharee seen by the owner and by another sharee; read-only refusals; leaving a share; cleanup of locks and properties; the same two-path `If-Match` race as in PR 23, for a contact.

**Done when:** a contact changed in DAVx5 through a read-write share shows up in the owner's Thunderbird and the other way round, read-only shares still refuse writes, and leaving a share keeps the owner's data.

### PR 26 — Received shares, the Sharing switch in the panel, and the Dashboard (Effort: Medium) — ✅ done

**Built:** `ReceivedSharesRelationManager` (the **Shared with this user** tab: type, name, owner, access, DAV path, and **Remove** through `SharingService::unshare`; the table is built from `SharingService`'s answers because the rows live in two tables), the **Sharing** toggle on the Settings page (locked by `NEXUSDAV_SHARING`), and **Sharing** in the Dashboard's Services card. English and Russian text. Tests are in `ShareAdminTest`, `SettingsTest` and `AdminPagesTest`.

- The user page: a **Shared with this user** table (collection, owner, access) with remove.
- The Settings page: Sharing on or off, locked when `NEXUSDAV_SHARING` is set. The help text says that off hides shares and keeps them.
- The Dashboard: Sharing in the Services card.
- English and Russian text.

**Tests:** the received-shares table and remove; the switch from the panel, including the locked state; the Services card.

**Done when:** an admin sees and removes the shares a user received, switches Sharing from the panel, and the Services card shows it.

### PR 27 — M3 release (Effort: Medium) — ✅ done, 0.3.0

**Prepared:** the Sharing section (step 11) and a Pass 3 results table in the [client checklist](../testing/client-checklist.md#11-sharing); the guide's Sharing section and settings table, the apps pages, the Users, Settings and Dashboard design notes, `architecture.md` and `dav-endpoints.md`; version references moved to 0.3.0 (compose files, TrueNAS files, README, SECURITY.md, guide, Dashboard note); release notes for 0.3.0. **Client pass:** step 11 passed on 0.3.0+404d77b with DAVx5 + jtx Board, Thunderbird and an iPad, on PostgreSQL and SQLite (November 7, 2026). All of step 11 passed on both databases. **Published** as tag `v0.3.0`. NexusDAV 0.3.0 is published at https://github.com/offsyanka99/NexusDAV/releases/tag/v0.3.0. The image build is ghcr.io/offsyanka99/nexusdav:0.3.0.
The tag is on c1eee3b, the current main.

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

### PR 28 — File home: storage, isolation and basic methods (Effort: High) — ✅ done

**Built:**
- `FilesRoot` (`/dav/files/`, one home per principal through the owner-only principal backend, so another user's home answers 404), `FileHome`, `FileDirectory` and `FileResource` on sabre's `FSExt` classes, and `FileStorage` for the disk side. The owner gets `read` and `write`, like sabre's calendar home; the `ACL` method is refused.
- Storage under `nexusdav.files.path`. The image sets `NEXUSDAV_FILES_PATH=/data/files`; outside the image it defaults to `storage/app/files`. It is not a panel setting. The home is created on first use with mode `0750` by the app user. The entrypoint changes the owner of `/data/files` (with `chown -R -h`) only when `PUID` or `PGID` changed, because a file tree can be large.
- Paths: `.` and `..` (also `%2e%2e` and an encoded `/`) and a NUL byte are refused with 403. A path that resolves outside `/dav/` now answers 404 instead of sabre's 500, for every DAV path. Symbolic links, devices, sockets and pipes are never listed or opened, a write onto one is refused with 403, and a delete removes a link without following it. A home that is itself a link is not served (500, logged).
- Names: no control characters, valid UTF-8, at most 255 bytes, stored in Unicode NFC. A name in NFD (macOS) opens the same file. A name on disk that is not NFC, or breaks the rules, is not listed.
- Writes go to a temporary file in `/data/files/.uploads/` (same volume; a username cannot start with a dot) and are renamed into place, so a failed upload leaves nothing and an overwrite is never half written. Temporary files older than a day are removed by the next upload. A full disk is a 500 here; PR 30 makes it 507.
- `GET` with ranges: `DavController` now stops a stream after `Content-Length` bytes, as sabre's own SAPI does, so a range answer no longer sends the rest of the file.
- `PROPFIND` depth infinity is answered as depth 1 (sabre's setting stays off).
- **Files switch:** `files` in `SettingsService::DEFINITIONS` (`NEXUSDAV_FILES`, default off, also when the config key is missing). Off: `/dav/files/` answers 403 "Files is turned off on this server." and the files are kept. The panel field is PR 31.
- Deleting a user removes the locks and properties under `files/<username>` and, after the deletion commits, the file home (renamed out of the way first, so a user created again with the same name starts empty). A failure is logged and does not undo the deletion.
- A write of a new name into another user's home answers the same as under a username that does not exist (404 or 409, by sabre's order of checks), so the answer does not tell who exists.
- `COPY`, `MOVE`, `LOCK` and `PROPPATCH` already go through the same hardened nodes (name rules, no links, no move of a folder into itself). Their tests and the move of locks and properties are PR 29.
- A folder reports the volume's used and free space (`quota-used-bytes`, `quota-available-bytes`, from sabre's `FSExt`), since 1.x has no user quotas; rclone `about` and GNOME Files show it.

**Tests:** `FilesTest` (both databases): the switch and its default; the basic methods with ranges, `If-Match` and depth; uploads above 2 MB (sabre's body moves to a temporary file there); cross-user 404s and the root listing; path escapes with `%2e%2e`, encoded slashes and NUL; links planted on disk (to another home, outside the homes, dangling, inside a deleted folder, and as the home itself); name rules and NFC; a failed upload (a dropped stream, an unusable uploads directory) leaving nothing and keeping the old file; stale upload cleanup; user deletion with locks, properties and a user created again.

**Checked by hand** on the built image (SQLite, plain HTTP, `PUID` 568): rclone 1.76 (mkdir, upload of a tree with a 5 MB file and Unicode names, `check`, an identical download, `cat`, `deletefile`, `purge`) and GNOME Files' gvfs through `gio` (mount with an app password, list, mkdir, upload, an identical download of 3 MB, remove a file and a folder). `/data/files` and the home were created `0750` by `nexusdav`. The maintainer's local run over HTTP passed F1 to F7 on November 8 ([Files testing](../testing/files-testing.md#results)). **Left:** the same with `davs://` behind the proxy, by the maintainer ([Files testing](../testing/files-testing.md), steps F1 to F7).

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

**Tests (local database):** isolation; path-escape attempts, including a symlink planted on disk; name rules and NFC; a failed upload leaves no file; the switch; user deletion.
Results are in the [Files testing](../testing/files-testing.md#results)

**Done when:** with Files on, GNOME Files (`davs://`) and rclone can list, upload, download and delete files and create folders; the path-escape and cross-user tests pass; and Files off answers 403 with the data kept.

### PR 29 — COPY, MOVE, locks and properties for files (Effort: High) — ✅ done

**Built:**
- `COPY` and `MOVE` of files and folder trees inside the home, with `Overwrite` and `Depth` (sabre's core handling through the PR 28 nodes, so the name rules, NFC and the refusal of links apply to the destination too). A rename is a `MOVE`. Copying or moving a folder into itself answers 409, onto itself 403. The home cannot be moved or copied.
- **Another user's home, before locks:** right after authentication, a request into another user's home (`principals`, `calendars`, `addressbooks` or `files`) answers 404, and a `COPY` or `MOVE` whose destination is there answers 409, the same as for a username that does not exist. sabre checks lock tokens by path before it looks up the node, so a locked path in another home answered 423 and showed that it exists. This held for calendars and address books too.
- **Files and calendars do not mix:** a `COPY` or `MOVE` between `/dav/files/` and the other roots answers 403 "Files can only be copied or moved inside the file home."
- **Dead properties** move with `MOVE` and go with `DELETE` (sabre's plugin). `COPY` now copies them too (RFC 4918, 9.8.2; `PropertyStorageBackend::copy`, by depth), and replaces the destination's old ones, because sabre overwrites a destination on `COPY` without an unbind event. A move of properties runs in one transaction.
- **Locks** (`LOCK`, `UNLOCK`, shared and exclusive, depth 0 and infinity) are sabre's lock plugin. Writes without the token answer 423. A `LOCK` on a new name creates an empty file. **Correction to the plan:** locks do not move with `MOVE`. RFC 4918, 9.9.4 says a successful `MOVE` must not move the write lock; sabre releases the source's locks on `MOVE` and removes locks with `DELETE`. A `COPY` is not locked.
- **A busy database is not replayed for files:** `DavController` replays a write after a busy database only outside `/dav/files/`, because disk changes are not rolled back with the transaction (a replayed `MOVE` would find its source gone). Files writes answer 503 instead.
- **`TemporaryFileFilterPlugin` is not loaded.** It keeps `._*`, `.DS_Store`, `desktop.ini`, `Thumbs.db`, vim swap and similar files in PHP's session directory, outside the data volume, with no cleanup. In a file home they are ordinary files; macOS and Windows are not tested by the project (Decision 15).

**Tests:** `FilesCopyMoveLockTest` (both databases): rename, move and copy of files and trees, depth 0, NFC and name rules on the destination; `Overwrite` T, F and a folder over a file; refusals (into itself, another home or a missing user, `%2e%2e`, a link on disk, files and calendars); dead properties after `COPY` (with depth and overwrite), `MOVE` and `DELETE`; locks (exclusive, shared, depth infinity, with and without the token, `MOVE` and `DELETE`, `LOCK` on a new name) and the 404 for another user's locked file and calendar object; no replay of a file write on a busy database.

**Checked by hand** on the local server (SQLite, HTTP, a temporary user): rclone 1.60 `moveto` (rename and move into a folder), `copyto` (server-side `COPY`, identical) and `move` of a folder; GNOME Files' gvfs through `gio`: rename, move, copy and a folder move. The maintainer's local run passed F8 to F12 ([Files testing](../testing/files-testing.md#results)). GNOME Files shows "Operation not supported" after a copy and paste although the copy is complete: its WebDAV backend cannot set the copy's modification time, and no request reaches the server. **Skip** keeps the copy. The guide's client compatibility notes say so (PR 32).

- `COPY` and `MOVE` inside the home, for files and folders, with `Overwrite` and `Depth`. A rename is a `MOVE`. A `COPY` or `MOVE` into another user's home answers 404.
- `LOCK` and `UNLOCK`, shared and exclusive, for office apps and Windows clients.
- Locks and dead properties move with `MOVE` and go with `DELETE`. sabre's property storage plugin calls the backend's `move` and `delete`; locks get the same treatment.
- macOS and Windows helper files (`._*`, `.DS_Store`, `desktop.ini`): the PR decides whether to load sabre's `TemporaryFileFilterPlugin`, which keeps them out of the user's home.

**Tests (local database):** copy and move of files and of folder trees; `Overwrite` true and false; locked resources refused to other lock tokens; properties and locks after `MOVE` and `DELETE`; cross-home refusals.

**Done when:** GNOME Files and rclone can rename, move and copy files and folders; locks and properties follow `MOVE` and go with `DELETE`; and a `COPY` or `MOVE` into another home is refused.

### PR 30 — Upload size limit, streaming and a full disk (Effort: Medium) — ✅ done

**Found:**
- PHP itself does not apply `post_max_size` to a `PUT`, but Laravel's `ValidatePostSize` middleware does, for every method: any body over 128 MB got Laravel's HTML 413. It is replaced by `App\Http\Middleware\ValidatePostSize`, which skips a `PUT` under `/dav/files/` only. Calendar and contact bodies, which are read into memory, keep the PHP limit. So the image's PHP limits do not need to follow Maximum upload size; `docker/uploads.ini` says so.
- `DavController` read every body into a string, so a 1 GB upload would end at PHP's 128 MB `memory_limit`. A `PUT` to a file home now passes `php://input` to sabre as a stream.
- **Downloads took the server down.** `fpassthru()` and `stream_copy_to_stream()` map a file into memory and hand it to the output layer whole; a 1 GB `GET` asked for 512 MB, hit PHP's hard memory limit, and stopped FrankenPHP with every request in it (exit 137). Responses are now sent in 1 MB chunks. This affected any large download, also before Files.

**Built:**
- **Maximum upload size (MB):** `files_max_upload_mb` in `SettingsService::DEFINITIONS` (`NEXUSDAV_FILES_MAX_UPLOAD_MB`, default `128`, 1 to 102400). It applies to a `PUT` under `/dav/files/` only; a `COPY` is not an upload.
- **413:** at once when `Content-Length` is over the limit (before the body is read), and from a byte count while the body streams for an upload without it (chunked). The message names the limit. The temporary file goes, and an overwritten file keeps its old content.
- An upload that ends before its `Content-Length` answers 400 and leaves nothing.
- **507** "There is not enough free space on the server." when a write fails with ENOSPC or EDQUOT (the warning is read with a short-lived error handler, because Laravel's handler takes it before `error_get_last()`), also for `mkdir` and the temporary file. Nothing is left. `tempnam()` falling back to the system temp directory is refused, so an upload never lands outside the data volume.
- `max_execution_time` is lifted for writes under `/dav/files/` (`PUT`, `COPY`, `MOVE`, `DELETE`), so a long upload or a large copy is not cut off.
- Proxy files: the Caddyfile and `nginx.conf` say to raise the body limit with Maximum upload size; Traefik sets none. `.env.example` has the variable.

**Tests:** `FilesUploadLimitTest` (both databases): the setting and its range; 413 from `Content-Length`, from the byte count, and over an existing file; a short upload; the limit only for files (a `COPY` of a larger file, a calendar body, `post_max_size` still for calendars); 507 on a simulated full disk (`/dev/full`); a 24 MB upload without the body in memory.

**Checked on the built image:** a 1 GB upload with the limit at 2048 MB (201 in 3 s), a full 1 GB download, a range in the middle and a 1 GB `COPY`, all identical, with the container at 128 MiB against 76 MiB idle and no restart. With 64 MB: 413 from `Content-Length`. With 10 MB: a chunked 20 MB upload got 413 (also over an existing file), 9 MB passed. On a 30 MB tmpfs: 507 for new files and over an existing one, which kept its content; `.uploads/` empty. The entrypoint took ownership of the tmpfs `/data/files`.


- **Maximum upload size (MB)** on the Settings page: `NEXUSDAV_FILES_MAX_UPLOAD_MB`, default `128`. It applies to `/dav/files/` only.
- First check today's behaviour: PHP's `post_max_size` is meant for `POST`, so check whether the 128 MB limit applies to `PUT` at all. Either way, the app enforces the limit: an early 413 from `Content-Length`, and a byte count while streaming for uploads without it. The partial file is removed.
- Streaming: a 1 GB upload must not raise memory use. Check that FrankenPHP and `DavController` pass the body as a stream, and that PHP's time limits do not stop a long upload or a large `COPY`.
- A limit above 128 MB: the image's PHP limits follow it, and the [proxy files](../proxy/) say which body size to set (Settled question 3).
- A full disk answers 507 and leaves no partial file.

**Tests:** 413 with and without `Content-Length`; no file left behind; a streamed upload's memory use; 507 on a simulated full disk.

**Done when:** an upload over the limit gets 413 with and without `Content-Length` and leaves nothing behind, a 1 GB upload with the limit raised completes with flat memory use, and a full disk gives 507.

### PR 31 — Files in the admin panel (Effort: Medium) — ✅ done

**Built:**
- **Settings page:** a Files section with the Files switch and Maximum upload size (MB), shown while Files is on. Each is locked by its variable. Switching Files off keeps the saved limit.
- **User page:** "File home: Exists" or "Not created yet" in User Information.
- **Delete confirmation:** "Their file home and every file in it will be deleted too." when the home exists.
- **Dashboard:** Files in the Services card, with the lock when `NEXUSDAV_FILES` is set. The card is three columns by two on wide screens.
- English and Russian text.

**Tests:** `FilesAdminTest`: the fields, hidden and shown, saved, the range error, and their locked states; the file home indicator; the confirmation text on the user page and in the list, and the home deleted with the user; the Services card. `AdminPagesTest` switches Files on where it counts the "Off" badges of Sharing.

**Checked in a browser** on the local server: the Settings section (Files locked by the local `.env`), the Services card, the user page and the delete confirmation (cancelled). The maintainer's local run passed F13 to F21 of [Files testing](../testing/files-testing.md#results), covering PR 30 and PR 31 together.


- The Settings page: Files on or off, and Maximum upload size (shown when Files is on), each locked by its variable.
- The user page: "File home: exists" or "not created yet". No browsing and no sizes; storage statistics are Version 2.x.
- The delete-user confirmation says the file home is deleted.
- The Dashboard: Files in the Services card.
- English and Russian text.

**Tests:** the fields and their locked states; the file home indicator; the confirmation text; the Services card.

**Done when:** an admin can switch Files and set the limit in the panel, sees whether a user's file home exists, and the Services card shows Files.

### PR 32 — M4 release (Effort: Medium) — ✅ done, 0.4.0

**Prepared:**
- **Version:** 0.4.0 in `compose.yaml`, both TrueNAS files, the README, SECURITY.md, the guide and the Dashboard note.
- **Guide:** a Files section (switching it on, where the files are, the URL and app passwords per Decision 14, clients, what works and what does not, isolation, backup of `/data/files`, the GNOME Files copy message); a Large file uploads section (Maximum upload size, nginx, Nginx Proxy Manager, Caddy, Traefik's read timeout, Cloudflare's 100 MB limit, free space and the TrueNAS dataset, time, clients); the settings table, the TrueNAS dataset size and a second volume for the files, the backup and upgrade notes, a Files table in the client compatibility section (GNOME Files, rclone, DAVx5 not tested yet, Windows and macOS not tested), troubleshooting rows, the features table and the roadmap.
- **Other docs:** `apps/files.md`, `apps/admin.md` and `apps/README.md`, the Settings, Users and Dashboard design notes, `architecture.md` (a Files flow, the nodes and the middleware), `data-model.md` (no migration; locks and properties by path) and `dav-endpoints.md` (a Files section with every answer).
- **Client checklist:** [Files testing](../testing/files-testing.md) has the release pass: F1 to F21 over HTTPS with GNOME Files and rclone on both databases, F22 for a DAVx5 WebDAV mount, and two regression steps (R1: discovery, items and offline edits; R2: sharing, step 11 of the client checklist) because PR 29 and PR 30 changed what every DAV path answers and how responses are streamed. A results table for the pass is at its end.
- **Release notes** for 0.4.0: Files is off after the upgrade, the proxy needs the same size limit, and the lock fix for other users' paths.

**Release pass:** passed on `0.4.0+5aea720` over HTTPS behind Caddy on PostgreSQL and SQLite (November 8 and 9, 2026): F1 to F21 with GNOME Files and rclone, F22 with DAVx5 4.5.21 on Android 17 (a mount connects, lists and downloads; DAVx5 offers few functions there), and the regression steps R1 and R2 with DAVx5 and Thunderbird ([results](../testing/files-testing.md#results-of-the-release-pass)). The results are in the guide's Files client table. **Published** as tag `v0.4.0`; the image build is `ghcr.io/offsyanka99/nexusdav:0.4.0` (also `0.4` and `latest`, amd64 and arm64). The tag is on 5aea720.


**After 0.4.0 (not in 0.4.0):** two panel additions the maintainer asked for. (1) An (i) button on Diagnostics → Latest DAV errors that opens the status codes the server returns, with their meaning and what to check (`App\Support\DavStatusCodes`, English and Russian). (2) A **Files storage** card on the Dashboard (`FilesStorageWidget`, only while Files is on): total size, number of files, users with files and the volume's free space. The total is counted only when an admin clicks Refresh (`App\Services\FileUsage`: a 30-second budget, a lock so two counts do not run at once, no symbolic links, no `.uploads`), because walking every file is slow on a large library; a page load never walks the files. Tests in `FilesStorageTest`; the steps F23 to F28 are in [Files testing](../testing/files-testing.md). Per-user sizes and quotas stay Version 2.x.

- A Files section in the client checklist, run with GNOME Files (`davs://`), rclone and DAVx5 WebDAV mounts on Android: upload, download, rename, move, copy and delete files and folders, a large upload, and an upload over the limit.
- Docs:
  - The guide: a Files section (switching it on, the URL, clients, the upload limit, app passwords also reach files per Decision 14), backup of `/data/files` together with the database, the TrueNAS dataset size, the proxy body size, and client compatibility (file clients; Windows and macOS not tested)
  - [apps/files.md](../apps/files.md), the [Settings design note](../design/admin-settings.md), [architecture.md](../architecture.md), [data-model.md](../data-model.md) and [dav-endpoints.md](../dav-endpoints.md)
- Release notes: Files is off after the upgrade.

**Done when:** the Files checklist passes with GNOME Files, rclone and DAVx5, the docs match, and the release is published.
**Tests** (both databases): Regression testing on deployed builds

## M5 — WebDAV-Push and the worker (Effort: Extra High)

**Goal:** a client that subscribes to a collection gets a push message when the collection changes, as in the pinned bitfire draft. Push is switched on or off in the admin panel. Subscriptions are visible on the Diagnostics page.

### PR 33 — Spike: Web Push library and DAVx5 push (Effort: Medium) — done, phone test moved to PR 35

**Result:** the design note [webdav-push.md](../design/webdav-push.md) (library, DAVx⁵ behaviour read from its source, discovery, registration URL `/dav/push/<id>`, topics, triggers and depths, expiry at most 7 days, data stored, 5-second merge window, retry and removal policy).
- **Library:** `minishlink/web-push` 11 (MIT), through Laravel's Guzzle (PSR-18). The encoding must be set to `aes128gcm`; the library's default is still `aesgcm`. The image adds `gmp` with the library (PR 35): without it the library prints a PHP notice each time, at no speed difference.
- **Prototype (not merged):** a VAPID-signed, `aes128gcm` push message went through a local ntfy and decrypted unchanged, also in the NexusDAV image.
- **DAVx⁵:** subscribes only to synced calendars and address books, with `content-update` depth 1, asks for 3 days and renews daily, sends `Push-Dont-Notify`, and **does not register without a VAPID key**.
- **Moved:** the push that reaches DAVx⁵ through ntfy needs the server side of registration, which is PR 35. It is PR 35's first check, and its result goes into the design note.


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

### PR 34 — Worker service and heartbeat (Effort: Medium) — done

**Built:**
- **Worker mode:** `docker/entrypoint.sh` takes its role from the first argument or `NEXUSDAV_ROLE` (`app` by default, or `worker`). The worker needs the app's `/data/app.key`, runs no migrations and no web server, caches the config (`optimize`), then runs `queue:work --max-time=3600 --memory=128 --timeout=120` as `nexusdav` in a loop, so the hourly restart does not restart the container. SIGTERM lets the running job finish (stopped in 0.1 s when idle).
- **`pcntl`** added to the image: without it `queue:work` cannot stop gracefully or enforce `--timeout`.
- **Compose, deviating from the plan:** not a `compose.worker.yaml`, because Compose resolves `extends` per file before merging, so a worker file cannot inherit the database variables of `compose.sqlite.yaml` or `compose.postgres.yaml`. Instead `compose.yaml` has a `nexusdav-worker` service with the Compose **profile** `worker` (the same image, environment through a YAML anchor, `/data` volume and log rotation; no port; `depends_on` the app being healthy; a health check). Each database file adds its variables to the worker, and `compose.build.yaml` gives it the built image. Start it with `COMPOSE_PROFILES=worker` in `.env` or `--profile worker`; without the profile nothing changes.
- **Heartbeat:** table `worker_heartbeats`, one row per worker (host name), written by `RecordWorkerHeartbeat` on `WorkerStarting` and then at most once a minute on `Looping`. `WorkerStatus` answers running, stale (over five minutes) or never seen. `nexusdav:worker-status` prints it (exit 0 when running; the worker's health check) and `--probe` queues `WorkerProbe` and waits for a worker to run it.
- **TrueNAS:** both files have a commented-out worker block on the same dataset.
- **Smoke test:** starts the image as a worker on the app's volume and checks the heartbeat, no migrations and no web server, a probe job queued by the app, and a clean stop.

**Tests:** `WorkerStatusTest` (the states and the five-minute edge, several workers and old rows, the heartbeat at start and at most once a minute, a failing write, the listener never pausing the worker, the command's exit codes, the probe with and without a worker, a probe on a real database queue).

**Checked by hand** with the built image and the real Compose files: SQLite and PostgreSQL, the worker healthy after about 8 seconds, a probe queued in the app container ran in the worker container (0.8 s and 0.5 s), and a graceful stop. On PostgreSQL, `nexusdav:worker-status` said "Worker stale" (exit 1) once the stopped worker's last heartbeat was over five minutes old, and "Worker running" again a second after it was started. On TrueNAS (the custom app with the worker block enabled), W1 to W7 passed; W8 is skipped for now. The results are in [push testing](../testing/push-testing.md).


- A worker mode in the entrypoint (proposed `NEXUSDAV_ROLE=worker`): no migrations, no first admin and no web server. It caches the config like the app container, then runs `php artisan queue:work` as `nexusdav`, with a maximum run time so it restarts regularly to release memory.
- A `compose.worker.yaml` override with a `nexusdav-worker` service: the same image, environment and `/data` volume, no ports, `depends_on` the app being healthy, the same log rotation. The worker also needs the database variables from `compose.postgres.yaml` or `compose.sqlite.yaml`; the PR picks how (Compose `extends`, or one worker override per database).
- A heartbeat in the database every minute, since the file cache is per container. A `WorkerStatus` service answers: running, stale (no heartbeat for five minutes) or never seen.
- The TrueNAS files get an optional worker block, using the same dataset. With SQLite, both containers use the same local file (WAL works across processes on one host).
- The image smoke test starts the image in worker mode and checks that it runs a queued job.

**Tests:** worker mode in the entrypoint; `WorkerStatus` states; the smoke test.

**Done when:** `docker compose -f compose.yaml -f compose.<database>.yaml --profile worker up -d` starts a worker that runs queued jobs on both databases, `WorkerStatus` reports running and then stale after the worker stops, and the smoke test covers worker mode.

### PR 35 — Push discovery and subscription registration (Effort: High) — implemented

**Built:**
- **Settings** (panel fields come in PR 38): `push` (`NEXUSDAV_PUSH`, off), `push_files` (`NEXUSDAV_PUSH_FILES`, off), `push_max_subscriptions` (`NEXUSDAV_PUSH_MAX_SUBSCRIPTIONS`, 100, 1 to 10000), `push_allow_private` (`NEXUSDAV_PUSH_ALLOW_PRIVATE`, off), `push_log_level` (`NEXUSDAV_PUSH_LOG_LEVEL`, `info`).
- **`PushPlugin`**, always loaded: `webdav-push` in the `DAV` header, the three properties on calendars, address books (owned or shared), both homes, and with Include Files in push the file home (depth infinity) and its folders. Registration on `POST`, unregistration on `DELETE /dav/push/<id>`, every other method there 405. `Push-Dont-Notify` is read here for PR 36.
- **Keys:** `minishlink/web-push` 11 added, `gmp` added to the image. The VAPID key pair and the topic key are made on first use (not on switching push on) and stored encrypted in `settings`.
- **Topics** are HMACs of the collection (design note), not random values stored per resource as this plan first said: a shared collection has one topic for the owner and every sharee without a lookup table.
- **`push_subscriptions`:** `user_id` and `app_password_id` (both cascade), not a principal URI. Re-registering the same push resource on the same path updates the row (204) and keeps the URL; a new one answers 201.
- **Cleanup:** revoking or deleting the app password, disabling or deleting the user, unsharing, a sharee leaving a share, and deleting a collection. A deleted collection's rows are *retired*: they get the deletion's message (PR 36), then go. Expired rows and retired rows older than an hour are pruned by the push job and on registration, so no scheduled cleanup is needed.
- A missing or unknown depth in a trigger is read as the deepest supported one.

**Tests:** `PushRegistrationTest` (21 tests, both databases).

**Open:** the DAVx⁵ check by hand (discovery and registration on a calendar, an address book and the homes; renewal; unregistering) — steps P1 to P5 in [push testing](../testing/push-testing.md). The push that reaches the phone needs PR 37.

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

### PR 36 — Change events and the push queue (Effort: High) — implemented

**Built:**
- **`PushChanges`**, called by the calendar and address book backends (`addChange`, create and delete), `SharingService` (share and unshare change the sharee's home), user deletion, and the file events of `PushPlugin` (`afterBind`, `afterUnbind`, `afterWriteContent`). Changes are queued with `DB::afterCommit`, so a rolled-back write queues nothing, and only for topics that have subscriptions.
- **Depths:** a member change is depth 1; a change deeper in a file home reaches only depth-infinity subscribers of the home; a deleted collection is a depth 0 content update to its own subscribers. A calendar or address book rename is a property update of it and a content update of the owner's home.
- **Merge window:** table `push_outbox`, one row per topic, written in the same transaction as its `SendPushMessages` job (delayed 5 seconds); later changes merge into the row. The job takes the row, reads the current sync token, and queues one `DeliverPushMessage` per matching subscription with only the parts it asked for (content, property or both).
- **`Push-Dont-Notify`:** `*` records nothing for that request; quoted URLs skip the caller's own registrations, and only if every change in the window named them. Other users' URLs are ignored. The header is cleared after each request.
- **Delivery** is behind a `PushSender` interface (`WebPushSender` since PR 37); a removed or expired subscription gets nothing.

**Tests:** `PushChangesTest` (15 tests, both databases).

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

### PR 37 — Web Push delivery and the SSRF guard (Effort: High) — implemented

**Built:**
- **`WebPushSender`:** `minishlink/web-push` encrypts (`aes128gcm`) and signs with VAPID (subject `APP_URL`); `TTL: 86400`, `Urgency: normal`, `Topic: <topic>`, `Content-Type: application/xml; charset="UTF-8"`.
- **SSRF guard (`PushEndpointGuard`):** https only; the host is resolved once and **every** address must be public (so a name cannot mix a public and an internal address). Refused: RFC 6890 non-global IPv4 ranges, multicast and 240/4; `::/96`, `fc00::/7`, `fe80::/10`, `fec0::/10`, `ff00::/8`, Teredo, documentation and other non-global IPv6 ranges; IPv4-mapped, NAT64 and 6to4 addresses are judged by their IPv4 address. With **Allow private networks** on, plain http and private addresses are allowed. A host name that does not resolve is retried like a connection error, not refused.
- **`PushHttpClient`**, a small PSR-18 client on curl rather than Guzzle, for exact control: connects only to the checked address (`CURLOPT_RESOLVE`), the protocol of the URL only, no redirects, no proxy, 5-second connect and 10-second total timeouts, at most 64 KB of the answer, and error messages without the URL.
- **Results** (`DeliverPushMessage`), as in the design note rather than the list above: 2xx records `last_success_at`. 404 and 410 remove the subscription once it has had no success for 24 hours. 429, 5xx, timeouts and connection errors are tried again after 30 seconds, 2 minutes and 10 minutes, then dropped. **413 is not retried** (the plan listed it with the retried answers; a too-large message does not get smaller), nor are 3xx and the other 4xx. A failing subscription with no success for 3 days is removed; one the SSRF guard refuses is removed at once. Expired subscriptions are pruned by the push job (PR 36).
- **Log:** push lines use `NEXUSDAV_PUSH_LOG_LEVEL` and show the push service's host, the status and a cleaned reason phrase, never the URL, keys or secrets.

**Tests:** `PushDeliveryTest` (11 tests, both databases). A fake push service decrypts the message as a UnifiedPush client does (RFC 8291) and verifies the VAPID JWT signature; the SSRF matrix (private and reserved IPv4 and IPv6, embedded IPv4, names that resolve to loopback, link-local or a mix); a real local HTTP server for the redirect, the 64 KB limit and the pinning; removal on 404 and 410; retry and backoff; no secret in the log.

**Checked by hand** with the built image, a worker and a real ntfy server: a change sent a push that ntfy accepted (200), and the message ntfy stored decrypted to the push message with the same sync token as a `PROPFIND`.

**Open:** the phone test with DAVx⁵ through ntfy (steps P9 to P12 in [push testing](../testing/push-testing.md)).


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

### PR 38 — Push in the admin panel (Effort: Medium) — implemented

**Built:**
- **Settings:** a **WebDAV-Push** section below Files: the switch, and while it is on, Include Files in push, Push subscriptions per user, Allow private networks and Push log level, each locked by its variable. The help under the switch says whether the queue worker runs.
- **Diagnostics:** a **Queue worker** card (running or not with the last heartbeat and the worker's name, the jobs waiting, and a warning while push is on and it does not run) and a **Push subscriptions** table (user, collection path, push service host only, created, expires, last push, status Delivered / Failing with the push service's answer / No push yet / Expired / Removing, a status filter, and revoke).
- **Dashboard:** WebDAV-Push and the Queue worker in the Services card (now 2, 4 and 8 columns), and a Needs attention line when push is on and the worker is stale or never seen. `App\Support\WorkerStatusLabel` words the worker's state the same way on all three pages.
- English and Russian text.

**Tests:** `PushAdminTest` (8 tests): the fields, hidden while push is off, saving and validation, the locked states, the worker hint, the Services card, the Needs attention line, the Queue worker card, the subscriptions table (host only, statuses, filter, revoke), and the per-job settings reload below. Checked in the browser.


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

### PR 39 — M5 release (Effort: Medium) — released as 0.5.0

**Done:**
- Step 12 (WebDAV-Push) in the [client checklist](../testing/client-checklist.md#12-webdav-push) with a Pass 4 results table; the server-side steps P1 to P12 stay in [push testing](../testing/push-testing.md).
- Docs: the guide (a WebDAV-Push section, the worker in Quick start, TrueNAS and Configuration, the settings table, endpoints, upgrade, backup, logging, troubleshooting, the client table and the roadmap), [apps](../apps/README.md) and [the admin panel](../apps/admin.md), the Settings, Dashboard and push design notes, [architecture.md](../architecture.md), [data-model.md](../data-model.md) (since PR 35) and [dav-endpoints.md](../dav-endpoints.md#webdav-push).
- Version 0.5.0 in `compose.yaml`, the TrueNAS files, `SECURITY.md` and the README; the comments that said nothing needs the worker are gone.
- **Found while preparing:** the worker kept the panel settings it read at start, and its settings cache is in its own container, so switching push on in the panel did not reach it (its jobs dropped the messages as "push off") until the container was recreated. The worker now reads the saved settings again before each job (`ReloadSettingsForJob`). Also new since 0.4.0: log lines and the worker's job lines use the server time zone.

**Release pass:** passed on `0.5.0-rc.1+1908632` on PostgreSQL and SQLite (November 10, 2026): step 12 with DAVx⁵ on a phone, and the regression steps with DAVx⁵ and Thunderbird ([results](../testing/client-checklist.md#12-webdav-push)). The worker steps W1 to W7 and the server steps P1 to P12 passed on PostgreSQL, SQLite and TrueNAS ([push testing](../testing/push-testing.md)). **Published** as tag `v0.5.0`; the image build is `ghcr.io/offsyanka99/nexusdav:0.5.0` (also `0.5` and `latest`).


- A Push section in the client checklist, run with DAVx5 and ntfy on both databases:
  - A calendar and an address book
  - A shared calendar: an owner edit reaches the sharee's device
  - Revoking the app password stops the pushes

  Push on file folders is covered by the automated tests; no client in Decision 15 subscribes to them, unless PR 33 found that DAVx5 does.
- Docs:
  - The guide: a WebDAV-Push section, the `worker` Compose profile in Configuration and Quick start, the TrueNAS worker block, and the settings table
  - [apps](../apps/README.md) and the design notes
  - [architecture.md](../architecture.md) (the worker), [data-model.md](../data-model.md) (push tables) and [dav-endpoints.md](../dav-endpoints.md) (push properties and registration URLs)
- Release notes: push is off after the upgrade and needs the worker (the `worker` Compose profile).

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
| [guide.md](../guide.md) | Settings table: the new variables. Configuration: the `worker` Compose profile. Client compatibility: tested versions and the results of the checklist | Release PRs 12, 19, 27, 32, 39 |
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
