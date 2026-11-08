# Architecture (Version 1)

This document describes how the main components fit together for Version 1. It is intentionally short and practical. See also [data model](data-model.md), [DAV endpoints](dav-endpoints.md) and [admin panel](apps/admin.md).

## Components

| Component | Responsibility |
|-----------|----------------|
| **Laravel** | HTTP routing, authentication, session, admin panel, application settings, user management |
| **Filament** | Admin UI (Dashboard, Users, Settings, Configuration, Diagnostics) |
| **sabre/dav** | DAV protocol server (WebDAV, CalDAV, CardDAV) |
| **sabre/dav PDO backend** | Storage for calendars, address books, objects and cards |
| **File home** | Files under `/dav/files/<username>/`: sabre's `FSExt` nodes on the data volume (`/data/files`), through `FileStorage` |
| **PostgreSQL / SQLite** | Database |

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

### Admin panel authentication

- Separate from DAV.
- Uses the admin account (Argon2id).
- Rate limiting and brute-force protection apply to both login and DAV Basic Auth. The limit is IP plus username, so failures from one address do not block that username from another. The address is the immediate peer. `X-Forwarded-For` counts only when that peer is an explicitly configured proxy, not when every address is trusted. A DAV login by email and by username for the same account share the lock once the user is known. Rejected credentials are stored in `diagnostic_events`.

## Directory structure (suggested)

```
app/
  Filament/                 # Admin resources (Users, Settings, etc.)
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
    UserService.php         # Create user + principal + default calendar and address book
    AuthThrottle.php        # IP plus username limit for admin login and DAV
    DiagnosticEventWriter.php  # stores rejected logins and DAV errors with their request ID; prunes expired rows on write
  Http/
    Controllers/
      HealthController.php  # /health
      DavController.php     # /dav/*; hands the signed-in DAV user to RequestLog
    Middleware/
      RequestLog.php        # first global middleware: request ID, X-Request-Id, one log line per request
      TrustProxies.php      # trusted proxies from config at request time
      ValidatePostSize.php  # Laravel's post_max_size check, except for an upload to /dav/files/
      SecurityHeaders.php   # admin panel headers
routes/
  web.php                   # Admin welcome. /dav and /.well-known are registered in bootstrap/app.php, outside the web group
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

## What is intentionally out of Version 1

- User portal
- Sharing by clients and a full ACL (an admin shares calendars and address books in Version 1.x; clients cannot create shares)
- WebDAV-Push
- Scheduling
- Email
- Files (WebDAV file storage; built in Version 1.x, off until an admin switches it on)
- Managed attachments

These will be added later without changing the core request flow above.

## Notes

- The architecture is designed so that sabre/dav remains the source of truth for DAV data.
- Laravel owns identity and application-level settings. sabre's PDO backends share Laravel's connection. SQLite uses WAL and a 5 second busy timeout, and the database file must be on a local filesystem.
- `DavController` runs sabre once per request, inside a write transaction for `PUT`, `DELETE`, `MOVE`, `COPY`, `PROPPATCH`, `MKCOL` and `MKCALENDAR` (`DavWriteLock`), and keeps the request body so it can run again when the database was busy (`BusyDatabase`: SQLite locked, PostgreSQL serialization failure, deadlock or lock timeout). After three retries the answer is 503 with `Retry-After`. See [DAV endpoints](dav-endpoints.md#concurrent-writes).
- `DavServerFactory` is the only place that builds the sabre tree and loads CalDAV, CardDAV, sync, authentication and owner-only `DAVACL`. Nodes that do not implement `IACL` are readable by an authenticated user and not writable, so a lock of a calendar or address-book root cannot block another user's object. Feature flags in `config/nexusdav.php` decide which protocol plugins and roots load. The HTML browser plugin is not loaded. `DavController` serves `/dav/*`.
- All Version 1 features should be implementable without rewriting the layering described here.
