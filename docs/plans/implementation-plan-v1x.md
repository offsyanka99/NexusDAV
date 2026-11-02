# Implementation plan — Version 1.x (high level)

Goal: add the Version 1.x features from the [guide roadmap](../guide.md#version-1x) on top of the built Version 1 server, without changing its core request flow.

The five features:

1. Basic Sharing and ACL
2. WebDAV-Push, with an on/off switch in the admin panel and its subscriptions on the Diagnostics page
3. Multilingual admin panel
4. Files (WebDAV file storage)
5. Improved sync reliability

This is a high-level plan. It splits the work into milestones and fixes their scope, order, key design points and the decisions behind them. Before a milestone starts, it gets a detailed PR plan with effort levels, like the [Version 1 plan](implementation-plan-v1.md). Version numbers are given to milestones when they are released, not here.

Sources: [guide](../guide.md), [apps](../apps/README.md), the [admin panel](../apps/admin.md) description, the design notes for [Settings](../design/admin-settings.md), [Configuration](../design/admin-configuration.md) and the [Dashboard](../design/admin-dashboard.md), and the current code.

Effort levels are the same as in Version 1:
- **Low** — small, mostly boilerplate or config
- **Medium** — standard feature, clear path
- **High** — integration or non-trivial logic
- **Extra High** — complex, many edge cases, or core protocol work

## Milestones and order

| # | Milestone | Effort | Depends on |
|---|---|---|---|
| M1 | [Multilingual admin panel](#m1--multilingual-admin-panel-effort-medium) | Medium | — |
| M2 | [Sync reliability](#m2--sync-reliability-effort-high) | High | — |
| M3 | [Sharing and ACL](#m3--sharing-and-acl-effort-extra-high) | Extra High | M1, M2 |
| M4 | [Files](#m4--files-effort-high) | High | M1, M2 |
| M5 | [WebDAV-Push and the worker](#m5--webdav-push-and-the-worker-effort-extra-high) | Extra High | M3, M4 |

```
M1 Languages ──┬──> M3 Sharing ──┬──> M5 WebDAV-Push
M2 Sync ───────┴──> M4 Files ────┘
```

Why this order:

- **M1 first.** Every later milestone adds admin panel pages and fields. When the translation setup exists first, their text goes into language files from the start and is not reworked later.
- **M2 early.** Its client checklist and conflict tests become the sign-off for M3 to M5. Its fixes to sync tokens also apply to shared collections.
- **M3 and M4 can run in parallel.** They touch different parts of the DAV tree.
- **M5 last.** A change to a shared calendar must notify every user who has it, and Settings has an option to include Files in push. Building push after both means it is designed once.

Each milestone ends in a release on its own.

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
| 15 | Real-client testing | The maintainer runs the M2 checklist by hand. It is a release step, and the results go in the guide's client compatibility table |
| 16 | Upgrade defaults | Files and WebDAV-Push are **off** after an upgrade. An admin switches them on. Sharing is on, but nothing is shared until an admin creates a share |

## Rules for every milestone

These carry over from Version 1 and add what 1.x needs.

- Every PR ships its own tests, run on SQLite and PostgreSQL in CI.
- New panel settings go in `SettingsService::DEFINITIONS` with an environment variable. A set variable wins and locks the field, as in Version 1.
- A feature that is switched off answers 403 with a reason on its DAV paths and keeps its data, like the CalDAV and CardDAV switches.
- New panel text is translatable from M1 on.
- Upgrading from the previous release works with migrations alone, on both databases. Upgrade notes go in the release notes.
- Cross-user tests are extended for every new path. A user who is neither the owner nor a sharee still gets 404.
- The docs change in the same milestone as the code: the guide, [apps](../apps/README.md), the design notes, [architecture.md](../architecture.md), [data-model.md](../data-model.md) and [dav-endpoints.md](../dav-endpoints.md).
- Before release, the maintainer runs the M2 client checklist again by hand for the areas the milestone touched, and records the results in the guide (Decision 15).

## M1 — Multilingual admin panel (Effort: Medium)

**Goal:** the admin panel can be shown in more than one language. English and Russian ship complete, and adding a language means adding one file.

**Scope**

- Move every panel string to Laravel translation keys. Some pages already use `__()`; the rest change. The English source strings and the Russian translation go in a new `lang/` directory. Russian plural forms use Laravel's `trans_choice`.
- Filament's own strings (buttons, tables, validation) come from its built-in translations.
- **Default interface language** on the Configuration page (proposed `NEXUSDAV_LOCALE`, default `en`), applied to the existing `nexusdav.default_locale`.
- **Each admin's own language**, chosen in the user menu and stored in the existing `users.locale` column. `User` already falls back to the default when it is empty.
- The locale is set for admin panel requests only. DAV responses, log lines and the reason codes stored in Diagnostics stay in English, because clients and log searches depend on them.
- Dates and times follow the language, together with the existing time format and week start settings.
- The language list is built from the files in `lang/`.
- [CONTRIBUTING.md](../../CONTRIBUTING.md) gets a section on adding a language.
- From M2 on, every new panel string needs an English and a Russian entry in the same PR.

**Tests**

- A test-only pseudo-locale marks every translated string. Each panel page is rendered in it, and any English text left over fails the test.
- A key check: every key used in code exists in English and in Russian, and no language file holds unknown keys.

**Done when:** every panel page passes the pseudo-locale test, the default language and each admin's language work, the environment variable locks the field, and the docs list the new setting.

## M2 — Sync reliability (Effort: High)

**Goal:** clients stay in sync after long offline periods, concurrent edits and busy databases, and the client compatibility table reflects real tests.

**Real-client pass**

- DAVx5 4.5.21 with jtx Board (calendar, contacts, tasks, notes) and Thunderbird 159.0 (calendar, contacts) were tested by hand on 0.1.1. This pass adds what is untested: Apple Calendar, Contacts and Reminders on macOS and iOS, Thunderbird tasks, and DAVx5 with Tasks.org, and repeats the full checklist below on the current release. Use a real HTTPS proxy from [docs/proxy](../proxy/) and both databases.
- A written checklist, kept in the repository and reused by later milestones:
  - Discovery from the domain only
  - Create, edit and delete an event, a recurring event with an exception, a task (completed afterwards), a note and a contact with a photo
  - A calendar created from the client
  - Two devices editing the same item
  - Offline edits, then sync
  - A first sync of a large collection
- Every bug found gets a fix and a regression test, or a documented limitation.
- Record the tested client versions in the [client compatibility](../guide.md#client-compatibility) table.

**Sync history and token validity**

- `calendarchanges` and `addressbookchanges` grow forever today. Prune entries older than a retention period (proposed setting, default 90 days). Pruning runs on write, like diagnostics pruning (Version 1 Decision 11).
- Record the oldest token each collection can still answer for. A client token the server cannot vouch for gets the `DAV:valid-sync-token` error, so the client starts a full sync instead of silently missing changes. That covers three cases:
  - A token older than the pruned history
  - A token from a collection that was deleted and created again with the same URI
  - A token that is ahead of the collection's current token

**Write conflicts**

- `If-Match` and `If-None-Match: *` are honoured on every write. The ETag returned by `PUT` matches what `GET` returns afterwards.
- Two concurrent writes with the same `If-Match`: one succeeds and the other gets 412. Today sabre checks and then writes in separate steps, so the second write can silently overwrite the first.
- Two concurrent creates of the same new URI give 412, not 500.
- SQLite busy after the 5-second timeout, or a PostgreSQL serialization error or deadlock: retry the transaction a few times, then answer 503 with `Retry-After`, not 500.

**Done when:** the checklist results are in the guide; history stays bounded; the three bad-token cases get `valid-sync-token` on both databases; and the concurrency tests above pass on both databases.

## M3 — Sharing and ACL (Effort: Extra High)

**Goal:** an admin can share a user's calendar or address book with another user, read-only or read-write. The other user's clients see it in their own home.

**Start with a spike.** sabre/dav 4.7 has calendar sharing (`calendarinstances` with access levels, already in the schema) but no address book sharing. Prove a shared address book node in the sharee's home, with its ACL and sync token, before committing to the design. Both calendar and address book sharing are required in this milestone (Decision 13). The spike chooses how to build address book sharing, and a larger effort is accepted.

**Calendars (tasks and notes included)**

- Use sabre's calendar sharing data. A share made in the panel creates the sharee's instance as accepted at once. There is no invitation flow.
- The shared calendar appears in the sharee's calendar home under its own URI. A sharee can change their own display name, color and order. They cannot change the owner's.
- `current-user-privilege-set` reports read-only access, so DAVx5, Thunderbird and Apple show the calendar as read-only.
- The sharing properties a client reads are served. Sharing requests from clients (CalendarServer `share` POST) are refused with 403, and no sharing modes are advertised.
- Tasks and notes in a shared calendar are shared with it. The Tasks and Notes switches apply to shared calendars as well.

**Address books**

- A new share table (proposed `addressbook_shares`: address book, sharee principal, access).
- A shared address book node in the sharee's address book home. It works on the owner's address book with read or read-write ACL and the same sync token.

**Rules**

- The owner's principal and homes still answer 404 to the sharee. Users who are not sharees see no change.
- Removing a share, or turning Sharing off, gives the sharee 404 at once. Turning Sharing off keeps the share rows, like the other switches.
- When the owner deletes the collection, every share of it is removed.
- Deleting a user removes the shares they own and the shares they received (`DavDataCleaner`).
- Keep a way to list all instances of a collection, because M5 notifies all of them.

**Admin panel**

- Each calendar and address book on the user page has a **Shared with** list: add a user with an access level, change the access, remove.
- The user page shows **Shared with this user**.
- A Sharing switch on the Settings page (proposed `NEXUSDAV_SHARING`, default on; nothing is shared until an admin creates a share).
- Sharing in the Dashboard Services card.

**Done when:** a calendar and an address book shared read-only and read-write sync correctly in DAVx5, Thunderbird and Apple; read-only writes get 403; non-sharees still get 404; removal and the switch hide the share at once; and user and collection deletion leave no share rows, on both databases.

## M4 — Files (Effort: High)

**Goal:** each user has a private WebDAV file home at `/dav/files/<username>/`. Clients can upload, download, rename, move and delete files and folders there ([Files](../apps/files.md)).

**Scope**

- A new `files` root in `DavServerFactory`. Files live on the data volume at `/data/files/<username>/`, owned by `PUID` / `PGID`. The home is created on first use.
- Owner-only access. Another user's file home answers 404, like other homes.
- Hardened file nodes:
  - Paths cannot leave the home (no `..`, and symlinks are not followed).
  - File names are checked (no control characters, a length limit) and normalised to Unicode NFC, so macOS and other clients see the same name.
  - Writes go to a temporary file and are renamed into place.
  - Stable ETags and last-modified dates.
- Methods: `GET` (including ranges), `HEAD`, `PUT`, `DELETE`, `MKCOL`, `COPY`, `MOVE`, `PROPFIND`, `PROPPATCH`, `LOCK`, `UNLOCK`. Locks and dead properties use the existing tables. `MOVE` and `DELETE` carry or clean them.
- Uploads stream to disk (request bodies already reach sabre as a stream).
- A full disk answers 507.
- No sync tokens on the file home (as [Files](../apps/files.md) says).
- **Settings page:**
  - Files on or off (proposed `NEXUSDAV_FILES`, default **off**, so an upgrade does not open a new endpoint without the admin choosing it).
  - Maximum upload size in MB (proposed `NEXUSDAV_FILES_MAX_UPLOAD_MB`). The app enforces it from `Content-Length` and the bytes received, and answers 413. A limit above today's 128 MB also needs the PHP limits and the [proxy files](../proxy/) raised in the same milestone (Settled question 3).
- With Files off, `/dav/files/` answers 403 "Files is turned off on this server." and the files are kept.
- Deleting a user deletes their file home. The confirmation in the panel says so.
- **Admin panel:**
  - The user page shows whether the file home exists. It does not browse files.
  - Files in the Dashboard Services card.
- The guide's backup section covers `/data/files`, backed up together with the database.

**Clients to test:** GNOME Files (`davs://`), macOS Finder, Windows Explorer, rclone, Cyberduck and DAVx5 file access on Android. Add them to the client compatibility table.

**Done when:** those clients can upload, download, rename, move and delete files and folders; uploads over the limit get 413; path-escape and cross-user tests pass; a deleted user leaves no files behind; and Files off answers 403 with the data kept.

## M5 — WebDAV-Push and the worker (Effort: Extra High)

**Goal:** a client that subscribes to a collection gets a push message when the collection changes, as in the pinned bitfire draft. Push is switched on or off in the admin panel. Subscriptions are visible on the Diagnostics page.

**Worker service (do first)**

- A new Compose override, proposed `compose.worker.yaml`, consistent with the other overrides. It adds a `nexusdav-worker` service:
  - The same image, `.env` and `/data` volume
  - No ports
  - `depends_on` the app being healthy
  - The same log rotation
- The entrypoint gets a worker mode. It skips migrations, first-admin creation and the web server, then runs `php artisan queue:work` as `nexusdav`, restarting itself regularly to release memory.
- The worker writes a heartbeat. When push is on and the heartbeat is stale, the Dashboard's Needs attention card and the Diagnostics page say that the worker is not running.
- The TrueNAS custom-app files get an optional worker block. With SQLite, both containers use the same local dataset (WAL needs a local filesystem).

**Protocol (server side of the draft)**

- Push uses the same `/dav/` URLs as sync. There is no separate push endpoint. The server adds `webdav-push` to the `DAV` header of `OPTIONS` on collections that support it, and a client reads the topic, the supported triggers and the VAPID public key with `PROPFIND` on the collection.
- Advertise push support and a stable, opaque topic per collection, in the properties the draft defines.
- Registration: a client `POST`s a Web Push subscription to the collection itself (for example `/dav/calendars/<username>/<calendar-id>/`) and gets a registration URL with an expiry. The server caps the expiry. `DELETE` on that URL unregisters.
- On any change to a collection, queue one message for each subscription. Changes within a short window are merged into one message. Subscriptions on every instance of a shared calendar or address book are notified. The client that made the change is skipped when it asks for that, as the draft allows.
- Web Push delivery with message encryption and VAPID, as the draft requires. The server's key pair is created on first use and stored encrypted. A maintained PHP Web Push library is preferred over writing the cryptography; the detailed plan picks it.
- A push service answering 404 or 410 removes the subscription. Other failures retry with backoff. Expired subscriptions are pruned.
- With **Include Files** on, changes in a file home notify subscriptions on it. A change notifies subscriptions on the changed folder and on the file home, not on the folders in between (Settled question 5).

**Security**

- Push endpoint URLs and client keys are secrets. They are stored encrypted and never logged. Diagnostics shows only the endpoint host.
- Outbound requests protect against SSRF:
  - HTTPS only, with no redirects, short timeouts, and the response body ignored.
  - Loopback, private and link-local addresses are blocked after DNS resolution, unless the admin allows private networks (for a self-hosted ntfy or UnifiedPush server on the LAN).
- Each subscription records the app password that created it. Revoking that app password, disabling the user or deleting the user removes its subscriptions.
- A per-user subscription limit.

**Admin panel**

- **Settings page:**
  - WebDAV-Push on or off (proposed `NEXUSDAV_PUSH`, default **off**, because it needs the worker)
  - Include Files
  - Maximum subscriptions per user
  - Push log level
  - Allow push endpoints on private networks
  - There is no separate push URL setting (see [Settled questions](#settled-questions))
- With push off, push is not advertised, registrations get 403, and nothing is sent. Existing subscriptions stay until they expire.
- **Diagnostics page:** subscriptions (user, collection, endpoint host, created, expires, last push, last status), with revoke.
- WebDAV-Push and the worker status in the Dashboard Services card.

**Clients to test:** DAVx5 with a UnifiedPush distributor (for example ntfy), for a calendar, an address book, a shared calendar and, with Include Files on, a file home.

**Done when:** DAVx5 receives a push for each of those after a change made from another device; unregister, expiry, 404/410 cleanup and app password revoke remove subscriptions; SSRF tests block private and loopback endpoints by default; push off stops all sending; and the Dashboard warns when push is on without a running worker.

## Data changes (summary)

| Milestone | Change |
|---|---|
| M1 | None. `users.locale` exists; new settings rows only |
| M2 | Per-collection oldest valid sync token (column or small table); pruning of `calendarchanges` and `addressbookchanges` |
| M3 | Calendar shares use `calendarinstances` (exists). New `addressbook_shares` table |
| M4 | None for file content (on disk). Locks and dead properties use the existing `locks` and `propertystorage` tables |
| M5 | New `push_subscriptions` table. VAPID keys stored encrypted. Queue rows in the existing `jobs` and `failed_jobs` tables |

## New settings (summary)

Names are proposals, to be confirmed in each detailed plan. All follow the Version 1 rule: an environment variable wins and locks the field.

| Setting | Page | Variable (proposed) | Default | Milestone |
|---|---|---|---|---|
| Default interface language | Configuration | `NEXUSDAV_LOCALE` | `en` | M1 |
| Keep sync history for (days) | Settings | `NEXUSDAV_SYNC_HISTORY_DAYS` | `90` | M2 |
| Sharing on or off | Settings | `NEXUSDAV_SHARING` | on | M3 |
| Files on or off | Settings | `NEXUSDAV_FILES` | off | M4 |
| Maximum upload size (MB) | Settings | `NEXUSDAV_FILES_MAX_UPLOAD_MB` | `128` | M4 |
| WebDAV-Push on or off | Settings | `NEXUSDAV_PUSH` | off | M5 |
| Include Files in push | Settings | `NEXUSDAV_PUSH_FILES` | off | M5 |
| Maximum push subscriptions per user | Settings | `NEXUSDAV_PUSH_MAX_SUBSCRIPTIONS` | `100` | M5 |
| Push log level | Settings | `NEXUSDAV_PUSH_LOG_LEVEL` | the server log level | M5 |
| Allow push endpoints on private networks | Settings | `NEXUSDAV_PUSH_ALLOW_PRIVATE` | off | M5 |

## Settled questions

These were open when the plan was first written. Each is now decided.

| # | Question | Decision | Milestone |
|---|---|---|---|
| 1 | Does a shared collection stay visible to sharees when its owner is disabled? | Yes. Disabling stops sign-in only. Shares go when the owner is deleted or the admin removes them | M3 |
| 2 | What happens when a sharee deletes a shared collection from their client? | The sharee leaves the share. The owner's data is untouched, and the panel shows the share as gone | M3 |
| 3 | Can the maximum upload size go above 128 MB? | Yes, but then the PHP limits and the proxy files change in the same milestone | M4 |
| 4 | Does WebDAV-Push need its own URL, or can it use the `/dav/` URL that clients use for sync? | **The same URL.** In the pinned draft a client subscribes by `POST`ing to the collection it wants to follow, and reads the topic and VAPID public key with `PROPFIND` on it. The URLs the server sends to are the clients' own push-service addresses, not a NexusDAV setting. The "Push external URL (HTTPS)" field is dropped. The VAPID contact (the `sub` claim, RFC 8292) is taken from `APP_URL`, so it needs no field | M5 |
| 5 | Does a change in a subfolder notify subscriptions on its parent folders? | Only the home and the changed folder | M5 |
| 6 | Default maximum push subscriptions per user | `100`, enough for several devices that each subscribe to every collection | M5 |

## Docs

**Fixed together with this plan.** The planning found contradictions between the docs. These were corrected so the docs now match the decisions above:

- [design/admin-settings.md](../design/admin-settings.md): Version 1.x now lists only Sharing, Files (with the maximum upload size) and WebDAV-Push settings. The storage path, per-user quota, trash and deleted-user retention fields and the "Push external URL" field are removed.
- [apps/admin.md](../apps/admin.md): sharing is done in the panel and clients do not create shares; the upload size limit replaces "per-user disk space limits"; English and Russian; Files and push are off after an upgrade; the push worker status on Diagnostics.
- [apps/calendar.md](../apps/calendar.md) and [apps/contacts.md](../apps/contacts.md): the client column for sharing.
- [apps/files.md](../apps/files.md): off after an upgrade, and app passwords also work for files.
- [design/admin-configuration.md](../design/admin-configuration.md) and [design/admin-dashboard.md](../design/admin-dashboard.md): languages, and the worker warning.
- [guide.md](../guide.md): the "Nothing is released yet" line, the sharing row in the protocols table, the 1.x roadmap lines, and the app password reach.

**Still to do, in the milestone that builds the feature**, because they describe code that does not exist yet:

| Doc | Change | Milestone |
|---|---|---|
| [guide.md](../guide.md) | Settings table: the new variables. Configuration: `compose.worker.yaml`. Client compatibility: tested versions and the results of the checklist | M1–M5 |
| [architecture.md](../architecture.md) | Titled "Version 1". Add the worker service, the Files root and storage, and the sharing nodes | M3–M5 |
| [data-model.md](../data-model.md), [dav-endpoints.md](../dav-endpoints.md) | New tables, the Files endpoint and push registration through the collection URLs | M2–M5 |
| [CONTRIBUTING.md](../../CONTRIBUTING.md) | Adding a language | M1 |

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
- More languages shipped by the project than English and Russian (contributors can add them)
- More automated client tests in CI, performance work on large collections, and conformance suites (CalDAVTester, litmus)
- `*_FILE` secrets (still not assigned to a version)
