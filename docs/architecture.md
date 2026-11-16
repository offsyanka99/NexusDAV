# Architecture (Version 1)

This document describes how the main components fit together for Version 1. It is intentionally short and practical. See also [data model](data-model.md), [DAV endpoints](dav-endpoints.md) and [admin panel](apps/admin.md).

## Components

| Component | Responsibility |
|-----------|----------------|
| **Laravel** | HTTP routing, authentication, session, admin panel, application settings, user management |
| **Filament** | Admin UI (Dashboard, Users, Settings, Configuration, Diagnostics) at `/admin`, and from Version 2 the user portal at `/`: two panels (`AdminPanelProvider`, `PortalPanelProvider`) with what they share in `PanelSetup` |
| **sabre/dav** | DAV protocol server (WebDAV, CalDAV, CardDAV) |
| **sabre/dav PDO backend** | Storage for calendars, address books, objects and cards |
| **File home** | Files under `/dav/files/<username>/`: sabre's `FSExt` nodes on the data volume (`/data/files`), through `FileStorage` |
| **PostgreSQL / SQLite** | Database |
| **Queue worker** (optional) | The same image in worker mode (`nexusdav-worker`, Compose profile `worker`): `php artisan queue:work` on the database queue, with a heartbeat in `worker_heartbeats`. Sends the WebDAV-Push messages |
| **WebDAV-Push** | `PushPlugin` (discovery, registration, `/dav/push/<id>`) in the sabre server; `PushChanges` records changes after commit into `push_outbox`; the worker's `SendPushMessages` and `DeliverPushMessage` jobs send them through the SSRF guard with `minishlink/web-push` |

## High-level request flow

```
Client (DAVx5, Thunderbird, Apple)
        |
        v
   Reverse proxy (optional)
        |
        v
   Laravel (routes /dav/*)
        |
        +-- Admin panel (/admin) --> Filament --> Laravel models
        |
        +-- User portal (/, Version 2, behind the Portal switch) --> Filament
        |
        +-- DAV requests (/dav/*) --> Auth (app password) --> sabre/dav Server
                                              |
                                              v
                                    PDO backend --> Database      (calendars, address books)
                                    FileStorage --> /data/files   (Files)
```

### DAV authentication

1. Client sends Basic Auth with username + **app password**.
2. Laravel looks up the user and verifies the app password hash.
3. On success, the request is passed to sabre/dav with the authenticated principal.
4. Main account password is **not** accepted on the DAV endpoint.

### Admin panel and user portal authentication

- Separate from DAV.
- Uses the main password of the account (Argon2id). `User::canAccessPanel()`: `admin` for active admins; `portal` for active users with a password while the Portal switch is on.
- Both panels use the `web` guard and one session: one sign-in for both, and signing out of one signs out of both. Each panel has its own idle lifetime, kept as its own last-activity time in the session (`PanelIdleTimeout`, a persistent panel middleware, so Livewire requests count too). `session.lifetime` is the longer of the two while the portal is on (`PanelSessions`).
- With the Portal switch off, `RequirePortal` (first portal middleware) redirects every portal address to `/admin`. Filament's default panel stays `admin`.
- On `/admin`, `AuthenticateAdmin` answers a signed-in user who may not use the panel with an Access denied page (403) and keeps them signed in; signing out still works.

### User portal shell (Version 2)

- **Apps:** each app (Dashboard, Calendar, Tasks, Notes, Contacts, Files) is one `App\Portal\PortalApp` class in `app/Portal/Apps`, with its id, label, icon, order, the settings it needs and its pages. `PortalApps` finds them; the panel registers their pages, the top navigation shows each visible app's first page, and a page of an app whose setting is off answers 404. A new app is a registry class and its pages, with no change to the provider.
- **Pages** extend `App\Portal\PortalPage` (access and navigation from the registry). Dates use `PortalTime` (the user's time zone, the server's time format and week start, ISO week numbers); Filament's own date columns follow the user's zone through `FilamentTimezone`.
- **Dashboard cards** extend `App\Portal\DashboardCard`; an app lists them, and the Dashboard shows those of the visible apps in order.
- **UI state** goes into `users.portal_state` through `PortalState` (at most 64 KB, keys such as `calendar.view`). `put()` and `forget()` re-read the column under a row lock inside a transaction and write that one key back, so two overlapping requests of the same user keep each other's keys. On PostgreSQL the row lock serializes them. On SQLite `lockForUpdate()` is ignored and the IMMEDIATE transaction is the lock. The write updates only this column, so the account's save hooks are not run. `get()` reads the user it is given.
- **Data layer** (`app/Services/Portal`, PR 50a): the portal reads and writes the same data as a DAV client, through the same backends (Decision 8).
  - `PortalCollections` lists the calendars and address books a user sees as `CollectionRef` values (the collection's id, the user's instance, name, color, components, access) from `getCalendarsForUser()` and `getAddressBooksForUser()`, so the Sharing, CalDAV, CardDAV, Tasks and Notes switches apply as on DAV. `readOnlyReasons()` is the one rule for "read-only": a read-only share, or a holiday calendar (the `nexusdav-holiday` key of the calendar info, from PR 51). Every lookup by id checks the user again; another user's id is `NotFound`, like a missing one.
  - `CalendarItemStore` and `ContactStore` share one write pipeline: look the collection up for the user, refuse a read-only one, then one transaction holding the lock a DAV write takes (`DavWriteLock::lockCalendar()` / `lockAddressBook()`; on SQLite the transaction's own write lock), the ETag check (`ChangedElsewhere`), CalDAV/CardDAV validation with sabre's repair (`ItemValidator`; a component the calendar does not take, a second UID in the collection), and the write through `CalendarBackend` or `AddressBookBackend`, which moves the sync token and records WebDAV-Push. `batch()` writes many items under one lock, matched by UID (skip, replace or fail), and reports refused items one by one; push merges into one message per topic.
  - `CalendarItemReader` and `ContactReader` return one item with its ETag, or a cursor over a collection read in chunks. `ItemEditor` changes the properties a form edits and keeps the rest.
  - `FileStore` works in the user's file home through `FileStorage` (name rules, links, upload limit), refuses a path a WebDAV client locked (`LockedItem`) or one that leaves the home, and pushes as the DAV plugin does.
  - Refusals are `PortalException`s; `PortalErrors` turns them into translated notifications (`PortalPage::notifyFailure()`).
- **Item index** (PR 50b, Decision 9): `item_index` holds one row per calendar object and contact for lists and search (`App\Models\ItemIndex` and its scopes: `visibleTo()`, `ofKind()`, `search()`, `dueBetween()`, `overlapping()`). `CalendarBackend` and `AddressBookBackend` call `ItemIndexer` on every create, update and delete of an object, inside the write's transaction and lock, in a savepoint, so a failure is logged and never fails the write; an object that cannot be parsed keeps a version-0 row. `nexusdav:index-items` (run by `nexusdav:prepare`, `IndexBackfill`) indexes what is missing or older, re-reading each object's ETag under the collection lock before it writes, and removes rows of objects deleted outside the backends.
- **Calendar reader** (PR 50b): `CalendarReader` returns the `Occurrence`s of the visible calendars in a time range, from sabre's calendar-query and vobject's `expand()` in the user's time zone. A range longer than a year (`MAX_RANGE_DAYS`, 366 calendar days) is refused. At most `MAX_OCCURRENCES` (2,000) are returned, the earliest; once that many are kept, later objects are expanded only up to that last start. `truncated` is set when some were left out by that cap, or when a series passed `RECURRENCE_LIMIT` (100,000). vobject counts instances from the series' DTSTART and its own ceiling is 3,500, so a daily event older than about 9.6 years, or an hourly one older than about 146 days, would otherwise vanish from every later range. The reader raises `Settings::$maxRecurrences` to 100,000 for the candidate query and for `expand()` only, then restores it (CalDAV still uses 3,500). A series that still passes 100,000 contributes no occurrences and is reported through `truncated`; the query is finished from the stored first and last occurrence so the other events in that calendar remain. The candidate query is one day wider on each side, because sabre stores floating and all-day times as if they were UTC.
- **Assets:** the portal has its own Filament theme, built by Vite. The image's `assets` stage (Node) builds it from the `vendor` directory of the PHP stage and the final image copies `public/build`. The admin panel keeps Filament's prebuilt styles.
- Rate limiting and brute-force protection apply to both login and DAV Basic Auth. The limit is IP plus username, so failures from one address do not block that username from another. The address is the immediate peer. `X-Forwarded-For` counts only when that peer is an explicitly configured proxy, not when every address is trusted. A DAV login by email and by username for the same account share the lock once the user is known. Rejected credentials are stored in `diagnostic_events`.

## Directory structure (suggested)

```
app/
  Filament/                 # Admin resources (Users, Settings, etc.)
    Auth/                   # Login (admin) and PortalLogin (email or username)
    Portal/                 # user portal pages (Version 2): Dashboard, the app pages, Account, AppPasswords
  Portal/                   # the portal shell: PortalApp, PortalApps, PortalPage, DashboardCard, PortalTime, PortalState
    Apps/                   # one registry class per app
  Models/                   # User, AppPassword, Principal, DiagnosticEvent
  Services/
    Dav/
      DavServerFactory.php  # sabre tree, auth, and owner-only DAVACL
      DavAuthBackend.php    # app-password Basic auth; the principal is principals/{username}
      OwnerOnlyPrincipalBackend.php  # listing and search show only the caller
      PrincipalBackend.php  # reads the Laravel principals table
      CalendarBackend.php   # sabre PDO calendars on Laravel's connection
      AddressBookBackend.php
      DavDataCleaner.php    # removes a user's sabre rows on delete, and one collection's properties and locks
      CollectionService.php # admin create, edit, and delete of calendars and address books
      SharingService.php    # share, change access, unshare; lists sharees, received shares and every instance
      CalendarNode.php      # a calendar in a home, owned or shared: privileges from the share's access
      AddressBookNode.php   # a shared address book: privileges from the share, name and leaving on the sharee's row
      AddressBookHome.php   # builds the shared node for a share and sabre's node for an owned book
      CardDavPlugin.php     # sabre's CardDAV plugin without the HEAD crash
      HomeNamespace.php     # one set of names per home across its two tables, and the lock writers take
      FilesRoot.php         # /dav/files/: one FileHome per principal, only the signed-in user's
      FileHome.php          # the top folder of a user's files, created on first use
      FileDirectory.php     # a folder: name rules, no links, moves and renames inside the home
      FileResource.php      # a file: uploads written to a temporary file, then renamed into place
      FileStorage.php       # the disk: names in NFC, uploads and the upload limit, 507, deletes that never follow a link
      UploadTooLarge.php    # 413
      Push/
        PushPlugin.php      # webdav-push in OPTIONS, the push properties, push-register POST, DELETE /dav/push/<id>, Push-Dont-Notify, file events
        PushRegisterParser.php  # the push-register body: the Web Push subscription, triggers, expiry
    Push/
      PushConfig.php        # the push settings and the push log level
      PushKeys.php          # VAPID key pair and topic key, made on first use, encrypted in settings
      PushTopics.php        # topics (HMAC of the collection) and which nodes offer push
      PushSubscriptions.php # register, renew, unregister, limits, expiry, cleanup paths
      PushChanges.php       # the one place write paths report changes; queued after commit
      PushQueue.php         # the 5-second merge window in push_outbox
      PushMessage.php       # the push-message body with the current sync token
      PushEndpointGuard.php # SSRF guard: https, public addresses only, resolved once
      PushHttpClient.php    # PSR-18 client on curl, pinned to the checked address, no redirects
      WebPushSender.php     # encrypts (aes128gcm) and signs (VAPID), returns a PushResult
    Portal/
      PortalConfig.php      # the Portal switch
      PortalLog.php         # portal log lines at the Portal log level
      PortalCollections.php # the user's calendars and address books (CollectionRef), read-only reasons
      WritePipeline.php     # lookup, read-only check, transaction and collection lock for every item write
      ItemValidator.php     # CalDAV / CardDAV validation and repair, components, UIDs
      CalendarItemStore.php # events, tasks and notes through CalendarBackend; batches
      ContactStore.php      # vCards through AddressBookBackend; batches
      CalendarItemReader.php, ContactReader.php  # one item with its ETag, or a chunked cursor
      ItemEditor.php        # edits a parsed item, keeping what a form does not edit
      FileStore.php         # the file home: list, folders, write, read, rename, move, delete
      PortalErrors.php      # refusals (Exceptions/) as translated notifications
      CalendarReader.php    # occurrences of a time range, recurring events expanded (Occurrence, OccurrenceList)
      Index/                # item_index: ItemIndexer (backend hooks), one indexer per kind, IndexBackfill
    WorkerStatus.php        # the queue worker's heartbeat: running, stale or never seen
    UserService.php         # Create user + principal + default calendar and address book
    AuthThrottle.php        # IP plus username limit for admin login and DAV
    DiagnosticEventWriter.php  # stores rejected logins and DAV errors with their request ID; prunes expired rows on write
  Jobs/
    SendPushMessages.php    # closes a topic's merge window, one delivery per subscription
    DeliverPushMessage.php  # sends one message; retry, backoff and removal
  Http/
    Controllers/
      HealthController.php  # /health
      DavController.php     # /dav/*; hands the signed-in DAV user to RequestLog
    Middleware/
      RequestLog.php        # first global middleware: request ID, X-Request-Id, one log line per request
      TrustProxies.php      # trusted proxies from config at request time
      ValidatePostSize.php  # Laravel's post_max_size check, except for an upload to /dav/files/
      SecurityHeaders.php   # admin panel headers
      SetPanelLocale.php    # the signed-in user's language in both panels
      PanelIdleTimeout.php  # each panel's own idle lifetime in the shared session
      RequirePortal.php     # the Portal switch: off, portal addresses redirect to /admin
      AuthenticateAdmin.php # /admin: Access denied for a signed-in user who is not an admin
  Providers/Filament/
    PanelSetup.php          # colors, middleware, language action, session timeout script, notification duration
    AdminPanelProvider.php  # /admin, Filament's default panel
    PortalPanelProvider.php # / (Version 2), and the reserved slugs
routes/
  web.php                   # No routes: / is the portal panel. /dav and /.well-known are registered in bootstrap/app.php, outside the web group
config/
  nexusdav.php              # Feature flags, defaults
```

## Key Version 1 flows

### 1. Create user

1. Admin fills the form in Filament.
2. `UserService` creates a record in `users`.
3. Creates a record in `principals` (`uri = principals/{username}`).
4. That principal is the calendar home `calendars/{username}` and the address book home `addressbooks/{username}`. sabre builds both from the principal, so there is no home row. The same transaction adds a default calendar and a default address book (`default`). `PrincipalBackend` does not insert a second principal.
5. The admin panel can already issue an app password, and can add, edit, and delete that user's calendars and address books. Writes go through the sabre backends. `/dav/` accepts an app password with the username or the email and resolves it to `principals/{username}`. The account password is not accepted. An admin using the panel can manage another user's collections. A DAV client, including an admin's, still cannot read another user's data.

### 2. Create calendar / address book

1. Admin opens a user and creates, edits, or deletes a calendar or address book.
2. `CollectionService` calls the sabre backend. A new calendar's components follow the tasks and notes flags. Delete uses that backend, then `DavDataCleaner` for the collection's property storage and locks.
3. The collection is available to that user's DAV clients only.

### 3. Client sync (DAVx5)

1. Client discovers `/.well-known/caldav` → `/dav/`.
2. Authenticates with app password.
3. Discovers principal and home sets.
4. Uses `sync-collection` REPORT for incremental sync.
5. Events, tasks and notes are stored by the PDO backend.

### 4. Share a calendar or address book (Version 1.x)

1. An admin shares in the panel; the panel calls `SharingService`, which writes one share row per sharee (`calendarinstances` for a calendar, `addressbookinstances` for an address book) under a lock on the sharee's home so names do not clash. Items and the change log stay on the owner's collection.
2. The sharee's client lists its home. `CalendarBackend` and `AddressBookBackend` add the shares to the home listing (left out when Sharing is off), and `CalendarHome` and `AddressBookHome` build `CalendarNode` and `AddressBookNode` for them.
3. A share's node takes its privileges from the share's access, never from `DAV:owner` (the sharee's own principal); sabre's `DAVACL` enforces them. The sharee's name, color and order are on their own row, and a `DELETE` of the shared collection removes only that row and its locks and properties.
4. A sharee's write goes to the owner's collection, so the owner's sync token moves and every other sharee gets the change in their next `sync-collection`. `DavWriteLock` keys the write lock on the collection, so a write through the owner's path and one through a sharee's path wait for each other.
5. A sync token is the owner's collection token, and a bare change number is refused on a share.

### 5. Upload and manage files (Version 1.x)

1. `DavServerFactory` loads `FilesRoot` only when Files is on. When it is off, a request under `/dav/files/` is refused with 403 before anything else, and the files stay on disk.
2. After sign-in, a request into another user's home answers 404 before sabre checks lock tokens, which are stored by path, and a `COPY` or `MOVE` into one answers 409. The same check covers calendars and address books. A `COPY` or `MOVE` between `/dav/files/` and the other roots is refused with 403.
3. For a `PUT` to a file home, the factory sets the limits (Maximum upload size from `Content-Length`, a byte count for a chunked upload) and `DavController` passes `php://input` to sabre as a stream, so a large file is never held in memory. A file write is not run again after a busy database, because disk changes are not rolled back with a transaction.
4. `FileResource` writes the body to a temporary file in `/data/files/.uploads/` and renames it into place, so a failed upload leaves nothing and an overwritten file keeps its content. A full disk is a 507.
5. A download is sent in 1 MB chunks, and a range answer stops after its `Content-Length`.
6. Locks and dead properties are sabre's `locks` and `propertystorage` rows, keyed by the path `files/<username>/...`. A move moves the properties and releases the locks; deleting a file or a user removes both. Deleting a user also deletes the home, after the deletion commits.

### 6. The queue worker (Version 1.x)

1. `docker/entrypoint.sh` takes a role from its first argument or `NEXUSDAV_ROLE`: `app` (the default: migrations, the first admin, FrankenPHP) or `worker`. The worker needs the app's `/data/app.key` and starts after the app is healthy; it caches the config and runs `queue:work` as `nexusdav` in a loop, so the hourly restart (`--max-time`) and the memory limit do not restart the container. SIGTERM lets the running job finish.
2. `RecordWorkerHeartbeat` listens to the worker's `WorkerStarting` and `Looping` events (found by Laravel's listener discovery) and writes the heartbeat at most once a minute; an idle worker loops every three seconds. A failure to write is logged and never stops the worker.
3. `WorkerStatus` reads the newest heartbeat. `nexusdav:worker-status` prints it and is the worker container's health check; `--probe` queues `WorkerProbe` and waits for a worker to run it (the image smoke test uses it).

### 7. WebDAV-Push (Version 1.x)

1. With push on, `PushPlugin` adds `webdav-push` to `OPTIONS` and the `transports`, `topic` and `supported-triggers` properties to calendars, address books, their homes and (optionally) file folders. A topic is an HMAC of the collection, so a shared collection has one topic for the owner and every sharee.
2. DAVx5 `POST`s a `push-register` body. `PushSubscriptions` stores the push resource and the client's keys (encrypted), grants at most seven days, and answers 201 (or 204 when renewed) with the registration URL `/dav/push/<id>`.
3. A write calls `PushChanges` from the backend (`addChange`, creating or deleting a collection), from `SharingService`, from user deletion, or from the file events. After the transaction commits, `PushQueue` opens or joins the topic's five-second window in `push_outbox` and queues `SendPushMessages`.
4. In the worker, `SendPushMessages` takes the window, reads the current sync token and queues one `DeliverPushMessage` per matching subscription, skipping those named in `Push-Dont-Notify`.
5. `DeliverPushMessage` sends through `WebPushSender`: `PushEndpointGuard` checks the push resource and resolves its host once, and `PushHttpClient` connects only to that address. The answer records a success, retries (429, 5xx, network errors: after 30 s, 2 min, 10 min) or removes a dead subscription.

## Integration points

| Concern | Owner |
|---------|-------|
| User accounts, app passwords, principals | Laravel |
| Calendar / address book objects | sabre/dav PDO backend |
| Files | `FileStorage` and the `FSExt` nodes, on the data volume at `/data/files` (`NEXUSDAV_FILES_PATH`); only locks and properties are in the database |
| Feature flags (enable/disable CalDAV, etc.) | `settings` table, applied to config at boot by `SettingsService`; the environment wins. `DavServerFactory` reads them |
| Health check | `/health` and the Dashboard share `App\Support\DatabaseHealth` |
| Logging | Laravel, to stderr (`LOG_CHANNEL=stderr` in the image). `RequestLog` writes one line per request (method, path, status, user, address, duration) and adds the request ID to every line through Laravel's `Context`. Rejected logins and DAV errors are also stored in `diagnostic_events` with that ID |
| Container image | `ghcr.io/offsyanka99/nexusdav`, amd64 and arm64, built by `.github/workflows/release.yml` from a `v*` tag. `compose.yaml` pins the version; `compose.build.yaml` builds locally |
| Reverse proxy | Tested files for Caddy, nginx and Traefik in [docs/proxy](proxy/) |
| Push services | Any Web Push service (ntfy, FCM through a UnifiedPush gateway) chosen by the client; reached only by the worker, through the SSRF guard |

## What is intentionally out of Version 1

- User portal
- Sharing by clients and a full ACL (an admin shares calendars and address books in Version 1.x; clients cannot create shares)
- Scheduling
- Email
- Files (WebDAV file storage; built in Version 1.x, off until an admin switches it on)
- WebDAV-Push (built in Version 1.x, off until an admin switches it on)
- Managed attachments

These will be added later without changing the core request flow above.

## Notes

- The architecture is designed so that sabre/dav remains the source of truth for DAV data.
- Laravel owns identity and application-level settings. sabre's PDO backends share Laravel's connection. SQLite uses WAL and a 5 second busy timeout, and the database file must be on a local filesystem.
- `DavController` runs sabre once per request, inside a write transaction for `PUT`, `DELETE`, `MOVE`, `COPY`, `PROPPATCH`, `MKCOL` and `MKCALENDAR` (`DavWriteLock`), and keeps the request body so it can run again when the database was busy (`BusyDatabase`: SQLite locked, PostgreSQL serialization failure, deadlock or lock timeout). After three retries the answer is 503 with `Retry-After`. See [DAV endpoints](dav-endpoints.md#concurrent-writes).
- `DavServerFactory` is the only place that builds the sabre tree and loads CalDAV, CardDAV, sync, authentication and owner-only `DAVACL`. Nodes that do not implement `IACL` are readable by an authenticated user and not writable, so a lock of a calendar or address-book root cannot block another user's object. Feature flags in `config/nexusdav.php` decide which protocol plugins and roots load. The HTML browser plugin is not loaded. `DavController` serves `/dav/*`.
- All Version 1 features should be implementable without rewriting the layering described here.
