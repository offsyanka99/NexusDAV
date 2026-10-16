# NexusDAV

![NexusDAV logo](docs/images/NexusDAV%20-%20logo.jpg)

**Self-hosted CalDAV / CardDAV / WebDAV server** with a dedicated admin panel and user portal.

Built on top of the [sabre/dav](https://sabre.io/) PHP library.

**How is it different?** Unlike Radicale, Baïkal and standalone sabre/dav, NexusDAV comes with a full admin panel built on Filament (and a user portal from Version 2); unlike Nextcloud, it stays focused on calendars, contacts, tasks, notes and files instead of being a full groupware suite.

## Quick start

> **Note:** NexusDAV is at an early stage and no Docker image has been published yet. The steps below describe the intended setup; image name, ports and variable names are placeholders and may change.

1. Get the Compose files and create your environment file:

   ```bash
   git clone https://github.com/offsyanka99/NexusDAV.git
   cd NexusDAV
   cp .env.example .env
   ```

2. Edit `.env` and set at least:

   ```dotenv
   APP_URL=https://dav.example.com
   ADMIN_EMAIL=admin@example.com
   ADMIN_PASSWORD=change-me-to-a-long-random-password
   ```

   For PostgreSQL also set `DB_PASSWORD`. `APP_KEY` is generated on first start and stored on the data volume.

3. Start the server (pick one database):

   ```bash
   # PostgreSQL (recommended for production)
   docker compose -f compose.yaml -f compose.postgres.yaml up -d

   # or SQLite (simple deployments and development)
   docker compose -f compose.yaml -f compose.sqlite.yaml up -d
   ```

   On first start the container runs database migrations and creates the first admin account from `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

4. Log in to the admin panel at `http://localhost:8080/admin` (or your `APP_URL`) with the admin credentials from step 2, then create your first user, calendar and address book.

5. Connect a client (DAVx5, Thunderbird, Apple Calendar / Contacts) using your server URL and the user's **app password**. See [Client compatibility](#client-compatibility). For automatic account discovery, set up your reverse proxy as described in [Reverse proxy and `.well-known`](#reverse-proxy-and-well-known).

Remove `ADMIN_PASSWORD` from `.env` after the first login.

## Requirements

Running with Docker needs only Docker Engine with the Compose plugin. The table below applies to development and to anyone running the code without Docker.

> Versions listed below reflect the planned target at the time of writing and will be confirmed or adjusted as development progresses.

| Component | Version | Notes |
|---|---|---|
| PHP | **8.3 – 8.5**, 8.4 recommended | Laravel 13 requires 8.3+. sabre/dav 4.7 is officially tested up to PHP 8.4, so 8.4 is the safe target until 8.5 is verified. |
| Laravel | **13.x** | Supports PHP 8.3 – 8.5. |
| Filament | **5.x** | Requires PHP 8.2+, Laravel 11.28+, Livewire 4, Tailwind CSS 4.1+. Fully compatible with Laravel 13. |
| Livewire | **4.x** (4.4.2+) | Pulled in by Filament 5. Also used by the user portal. |
| Tailwind CSS | **4.1+** | Required by Filament 5. Built with Vite. |
| sabre/dav | **4.7.x** (4.7.1) | Requires sabre/http 7, sabre/event 6, sabre/vobject 4.5+. |
| PostgreSQL | **17 or 18** | Primary database. PostgreSQL 14 reaches end of life on 12 Nov 2026. |
| SQLite | 3.x | For simple deployments and development. |
| Node.js | Current LTS | Frontend build only (Vite, Tailwind). |
| Composer | 2.x | |

**Required PHP extensions:** `ctype`, `curl`, `date`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_pgsql`, `pdo_sqlite`, `session`, `simplexml`, `tokenizer`, `xml`.

`intl` is required by Filament. PHP must also be built with Argon2 support (`password_hash` with `PASSWORD_ARGON2ID`); the official PHP Docker images include it.

**WebDAV-Push:** no stable release is available yet. Use the latest commit of the WebDAV-Push implementation compatible with sabre/dav (exact package/repository will be documented once chosen).

## Supported protocols & extensions

- WebDAV
- CalDAV
- CardDAV
- WebDAV-Push
- Principal / Home Collections
- CalDAV Scheduling (invitations, free/busy)
- Sharing (CalDAV/CardDAV Sharing + ACL)
- Managed Attachments
- Tasks (VTODO) via CalDAV
- Notes (VJOURNAL) via CalDAV

Collection sync (sync-token) is provided by sabre/dav and is enabled through configuration.

## Client compatibility

NexusDAV targets standards-compliant clients. Status below is the **target** for Version 1; nothing has been tested against these clients yet, and this table will be updated with tested client versions as the project matures.

| Client | CalDAV (events) | CardDAV (contacts) | Tasks (VTODO) | Notes (VJOURNAL) | Status |
|---|---|---|---|---|---|
| **DAVx5** (Android) | Yes | Yes | Yes, via a tasks app (jtx Board, Tasks.org, OpenTasks) | Yes, via jtx Board | Primary target |
| **Thunderbird** | Yes | Yes | Yes | No (not supported by the client) | Target |
| **Apple Calendar / Contacts** (macOS, iOS) | Yes | Yes | Yes, in Reminders (not in Calendar) | No | Target |
| Other standards-compliant clients | Expected | Expected | Client-dependent | Client-dependent | Best effort |

**About notes (VJOURNAL):** Notes are a server-side capability stored as standard CalDAV VJOURNAL entries. Client support is limited — currently mainly DAVx5 with jtx Board. Apple Notes and Thunderbird do not use VJOURNAL, so notes will not appear in those clients. Notes remain fully manageable from the NexusDAV admin panel and, later, the user portal.

**About tasks (VTODO):** on Android, DAVx5 only syncs the data; a separate tasks app is needed to display it. On Apple devices, tasks appear in Reminders.

## Features (planned)

These are long-term goals for the project. Implementation will be staged. The architecture and framework should be designed to support future features without major rewrites.

- Focused self-hosted DAV server with a modern admin panel, without the weight of a full groupware suite
- Admin interface for users, calendars, address books, shares, permissions and server settings
- Separate user portal for managing personal calendars, contacts, notes (VJOURNAL — limited client support), tasks and files (WebDAV)
- **Multilingual** admin panel and user portal
- Docker-friendly
- Standards-compliant DAV protocols
- Search across events and contacts
- Import / Export of ICS and VCF (calendars, contacts, tasks and notes)

### Admin panel (long-term)

- User quotas
- Logs / audit trail
- Health checks
- Export of server configuration (JSON)

### Security (long-term)

- Optional 2FA

Rate limiting and brute-force protection are planned for Version 1.

### Infrastructure (long-term)

- Automatic backups (local + remote: S3, Backblaze, etc.)

## Roadmap

### Version 1 (minimal viable server)

- Minimal admin panel only (no user portal)
- Create and manage users
- Authentication: Argon2id user passwords and app passwords / personal access tokens (for DAVx5, Thunderbird and other clients)
- Rate limiting and brute-force protection for login and DAV (Basic Auth) endpoints
- Reverse proxy support and service discovery (`/.well-known/caldav` and `/.well-known/carddav`), documented with ready-to-use configs
- Create calendars, address books, tasks (VTODO) and notes (VJOURNAL)
- Basic sync working with DAVx5 (and ideally other clients)
- Core DAV protocols (WebDAV, CalDAV, CardDAV)
- Docker Compose setup: base file + PostgreSQL / SQLite overrides

### Version 1.x

- Basic Sharing and ACL
- WebDAV-Push
- Improved sync reliability

### Version 2

- Basic user portal
- CalDAV Scheduling (invitations, free/busy)
- Import / Export (ICS, VCF)
- Search across events and contacts

### Version 3+

- User quotas
- Logs / audit trail and health checks
- Automatic backups (local + remote)
- Multilingual UI
- Optional 2FA
- Export of server configuration

## Configuration

Docker Compose is split into a shared base file and one override per database, so the database is already defined at install time:

```
compose.yaml            # application, shared volumes, ports
compose.postgres.yaml   # adds the postgres service and DB_* variables
compose.sqlite.yaml     # sets DB_CONNECTION=sqlite and the database file path
```

See [Quick start](#quick-start) for the exact commands. PostgreSQL is recommended for production; SQLite is intended for simple deployments and development.

The `.env` file contains only what the application needs to boot: database connection, `APP_KEY`, `APP_URL` and the initial admin account. All other server settings (SMTP, quotas, defaults, etc.) are configured through the admin UI and stored in the database. If a setting is also defined as an environment variable, the environment value takes precedence and the field is read-only in the UI.

## Reverse proxy and `.well-known`

In production NexusDAV is expected to run behind a reverse proxy (Caddy, nginx, Traefik) that terminates HTTPS. Two things matter:

1. **Service discovery.** Clients such as Apple Calendar / Contacts, Thunderbird and DAVx5 look for `/.well-known/caldav` and `/.well-known/carddav` on your domain. These URLs must answer with an HTTP redirect (301, 303 or 307) to the DAV root. With them in place, users can enter just the domain (or the root URL) instead of full calendar and address book paths. sabre/dav does not do this itself, so it is configured in the proxy.
2. **A DAV-aware proxy.** The proxy must pass through all HTTP methods used by DAV (`PROPFIND`, `PROPPATCH`, `REPORT`, `MKCOL`, `MKCALENDAR`, `COPY`, `MOVE`, `LOCK`, `UNLOCK`), forward the `Host` and `X-Forwarded-Proto` headers, and allow large request bodies for file uploads. `APP_URL` must match the public HTTPS address, and the proxy should be configured as a trusted proxy.

Example for nginx (the DAV root path `/dav/` is a placeholder until the final path is decided):

```nginx
location = /.well-known/caldav  { return 301 /dav/; }
location = /.well-known/carddav { return 301 /dav/; }
```

Example for Caddy:

```caddy
dav.example.com {
    redir /.well-known/caldav  /dav/ 301
    redir /.well-known/carddav /dav/ 301
    reverse_proxy nexusdav:8080
}
```

Ready-to-use proxy configurations will be provided in the documentation as part of Version 1.

## Authentication

- Main user passwords: **Argon2id**
- **App passwords / personal access tokens** (for DAVx5, Thunderbird and other clients), available from Version 1
- **Rate limiting and brute-force protection** on the login form and the DAV endpoints, available from Version 1

## Database

- **Primary (recommended for production)**: PostgreSQL, via `compose.postgres.yaml`
- **Also supported**: SQLite (for simple deployments and development), via `compose.sqlite.yaml`

## Email

Outbound email only (via external mail server / SMTP). Used for:

- Meeting invitations and scheduling
- Notifications and reminders
- Calendar / address book shares
- Account notices
- System reports

## Tech stack

- **Core**: PHP + [sabre/dav](https://github.com/sabre-io/dav)
- **Application**: [Laravel](https://laravel.com/)
- **Admin panel**: [Filament](https://filamentphp.com/) (Livewire + Tailwind CSS)
- **User portal**: Laravel + Livewire + Tailwind CSS
- **Frontend styling**: Tailwind CSS
- **Deployment**: Docker Compose (base file + PostgreSQL / SQLite overrides)

See [Requirements](#requirements) for the exact versions.

**Use the latest stable versions** of all libraries and dependencies when possible. For WebDAV-Push, use the latest compatible commit until a stable release is available.

## Status

Early stage — project skeleton. Features listed above are roadmap items and will be implemented in stages.

## Contributing

Bug reports, client compatibility reports, documentation fixes and code contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## Security

Please do not report security vulnerabilities through public issues. See [SECURITY.md](SECURITY.md) for how to report them privately.

## License

MIT
