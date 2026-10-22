# Architecture (Version 1)

This document describes how the main components fit together for Version 1. It is intentionally short and practical. See also [data model](data-model.md), [DAV endpoints](dav-endpoints.md) and [admin panel](apps/admin.md).

## Components

| Component | Responsibility |
|-----------|----------------|
| **Laravel** | HTTP routing, authentication, session, admin panel, application settings, user management |
| **Filament** | Admin UI (Users, Settings, Configuration, Dashboard) |
| **sabre/dav** | DAV protocol server (WebDAV, CalDAV, CardDAV) |
| **sabre/dav PDO backend** | Storage for calendars, address books, objects and cards |
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
                                    PDO backend --> Database
```

### DAV authentication

1. Client sends Basic Auth with username + **app password**.
2. Laravel looks up the user and verifies the app password hash.
3. On success, the request is passed to sabre/dav with the authenticated principal.
4. Main account password is **not** accepted on the DAV endpoint.

### Admin panel authentication

- Separate from DAV.
- Uses the admin account (Argon2id).
- Rate limiting and brute-force protection apply to both login and DAV Basic Auth.

## Directory structure (suggested)

```
app/
  Filament/                 # Admin resources (Users, Settings, etc.)
  Models/                   # User, AppPassword, Principal
  Services/
    Dav/                    # sabre/dav server bootstrap, auth bridge
    UserService.php         # Create user + principal + home collections
  Http/
    Controllers/
      DavController.php     # Entry point for /dav/*
      HealthController.php  # /health
routes/
  web.php                   # Admin + health
  dav.php                   # DAV routes (or handled inside DavController)
config/
  nexusdav.php              # Feature flags, defaults
```

## Key Version 1 flows

### 1. Create user

1. Admin fills the form in Filament.
2. `UserService` creates a record in `users`.
3. Creates a record in `principals` (`uri = principals/{username}`).
4. Calls sabre/dav to create the principal and home collections (calendars + address books). Not built yet (PR 3 and PR 4). Steps 2 and 3 are in the code.
5. User can now authenticate with an app password. The DAV check is PR 3. The admin panel can already issue the app password.

### 2. Create calendar / address book

1. Admin selects a user and creates a collection.
2. Laravel writes metadata if needed and calls sabre/dav to create the collection under the user's home.
3. Collection becomes available to DAV clients.

### 3. Client sync (DAVx5)

1. Client discovers `/.well-known/caldav` → `/dav/`.
2. Authenticates with app password.
3. Discovers principal and home sets.
4. Uses `sync-collection` REPORT for incremental sync.
5. Events, tasks and notes are stored by the PDO backend.

## Integration points

| Concern | Owner |
|---------|-------|
| User accounts, app passwords, principals | Laravel |
| Calendar / address book objects | sabre/dav PDO backend |
| Feature flags (enable/disable CalDAV, etc.) | Laravel config + admin Settings |
| Health check | Laravel `/health` |
| Logging | Laravel (stdout + `/data/logs`) |

## What is intentionally out of Version 1

- User portal
- Sharing / ACL
- WebDAV-Push
- Scheduling
- Email
- Files (WebDAV file storage)
- Managed attachments

These will be added later without changing the core request flow above.

## Notes

- The architecture is designed so that sabre/dav remains the source of truth for DAV data.
- Laravel owns identity and application-level settings.
- All Version 1 features should be implementable without rewriting the layering described here.
