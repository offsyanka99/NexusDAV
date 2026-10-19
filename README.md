# NexusDAV

![NexusDAV logo](docs/images/NexusDAV%20-%20logo.jpg)

**Self-hosted CalDAV / CardDAV / WebDAV server** with a dedicated admin panel and user portal.

Built on top of the [sabre/dav](https://sabre.io/) PHP library.

**How is it different?** Unlike Radicale, Baïkal and standalone sabre/dav, NexusDAV comes with a full admin panel built on Filament (and a user portal from Version 2); unlike Nextcloud, it stays focused on calendars, contacts, tasks, notes and files instead of being a full groupware suite.

## Status and scope

Early stage — project skeleton. Features listed in this README are roadmap items and will be implemented in stages.

NexusDAV is built for homelabs and small teams that want calendars, contacts, tasks, notes and files without running a full groupware suite.

What it does not aim to be:

- A mail, chat or office-document suite (use Nextcloud for that).
- A hosted multi-tenant service.

Current limitations:

- No published Docker image, and nothing is tested against real clients yet.
- No user portal before Version 2; all management goes through the admin panel.
- Notes (VJOURNAL) show up only in a few clients (see [Client compatibility](#client-compatibility)).
- Email is outbound only (see [Email](#email)). Emailed replies to invitations are not read or processed in Version 1.
- The database cannot be switched after install in Version 1 (see [Database](#database)).

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

5. Connect a client (DAVx5, Thunderbird, Apple Calendar / Contacts) using your server URL and the user's **app password**. See [Client setup and compatibility](#client-setup-and-compatibility). For automatic account discovery, set up your reverse proxy as described in [Reverse proxy and `.well-known`](#reverse-proxy-and-well-known).

Remove `ADMIN_PASSWORD` from `.env` after the first login. If you lose access to the admin account later, see [Resetting the admin password](#resetting-the-admin-password).

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

**WebDAV-Push:** no stable release is available yet. Use the latest commit of the WebDAV-Push implementation compatible with sabre/dav (exact package/repository will be documented once chosen). Once available, it is switched on or off in the admin panel settings.

## Tech stack

- **Core**: PHP + [sabre/dav](https://github.com/sabre-io/dav)
- **Application**: [Laravel](https://laravel.com/)
- **Admin panel**: [Filament](https://filamentphp.com/) (Livewire + Tailwind CSS)
- **User portal**: Laravel + Livewire + Tailwind CSS
- **Frontend styling**: Tailwind CSS
- **Deployment**: Docker Compose (base file + PostgreSQL / SQLite overrides)

See [Requirements](#requirements) for the exact versions.

**Use the latest stable versions** of all libraries and dependencies when possible. For WebDAV-Push, use the latest compatible commit until a stable release is available.

## Configuration

Docker Compose is split into a shared base file and one override per database, so the database is already defined at install time:

```
compose.yaml            # application, shared volumes, ports
compose.postgres.yaml   # adds the postgres service and DB_* variables
compose.sqlite.yaml     # sets DB_CONNECTION=sqlite and the database file path
```

See [Quick start](#quick-start) for the exact commands. PostgreSQL is recommended for production; SQLite is intended for simple deployments and development.

The `.env` file contains only what the application needs to boot: database connection, `APP_KEY`, `APP_URL` and the initial admin account. All other server settings (SMTP, quotas, defaults, etc.) are configured through the admin UI and stored in the database. If a setting is also defined as an environment variable, the environment value takes precedence and the field is read-only in the UI.

### Environment variables

The variables used in this README (names and defaults may change before the first release). `.env.example` will list the same variables with safe placeholder values.

| Variable | Required | Default | Secret | Description |
|---|---|---|---|---|
| `APP_URL` | Yes | none | No | Public HTTPS address of the server. Must match what clients and the reverse proxy use. |
| `ADMIN_EMAIL` | First start | none | No | Email of the first admin account. |
| `ADMIN_PASSWORD` | First start | none | Yes | Password of the first admin account. Remove it after the first login. |
| `DB_CONNECTION` | Set by the compose override | set by override | No | `pgsql` or `sqlite`. Do not change after install. |
| `DB_PASSWORD` | PostgreSQL only | none | Yes | Database password. |
| `APP_KEY` | No | generated | Yes | Generated on first start and stored on the data volume. |
| `LOG_LEVEL` | No | `debug` | No | Minimum level written to the log (see [Health check and logging](#health-check-and-logging)). |

Secrets can also be supplied as files (for example `DB_PASSWORD_FILE`) for use with Docker secrets; this is planned. The application stops at start with a clear message if a required variable is missing.

If you lose access to the admin account, see [Resetting the admin password](#resetting-the-admin-password).

### Resetting the admin password

Run the reset command on the server. Shell access to the container is the proof of ownership; nothing is exposed on the web.

```bash
docker compose exec nexusdav php artisan nexusdav:reset-admin-password --email=admin@example.com
```

The command asks for the new password (input hidden), or can generate one and print it once. It also signs out existing sessions and can optionally revoke app passwords. The reset is logged, and an email is sent to the admin if SMTP is configured.

The command name is planned and may change before the first release. A web-based reset triggered by an environment variable is deliberately not used: anyone who reaches the page first after a restart could set the admin password.

### Authentication

- Main user passwords: **Argon2id**
- **App passwords / personal access tokens** (for DAVx5, Thunderbird and other clients), available from Version 1
- **Rate limiting and brute-force protection** on the login form and the DAV endpoints, available from Version 1

### Database

- **Primary (recommended for production)**: PostgreSQL, via `compose.postgres.yaml`
- **Also supported**: SQLite (for simple deployments and development), via `compose.sqlite.yaml`

**Choose before the first start.** Switching between SQLite and PostgreSQL after install is not supported in Version 1. To move servers, export your data (ICS / VCF export is planned for Version 2) and import it into a fresh install.

### Email

Outbound email only (via external mail server / SMTP). Used for:

- Meeting invitations and scheduling
- Notifications and reminders
- Calendar / address book shares
- Account notices
- System reports

NexusDAV does not receive email. Replies to emailed invitations are not processed; local attendees respond through CalDAV scheduling (Version 2).

## Reverse proxy and `.well-known`

In production NexusDAV is expected to run behind a reverse proxy (Caddy, nginx, Traefik) that terminates HTTPS. Two things matter:

1. **Service discovery.** Clients such as Apple Calendar / Contacts, Thunderbird and DAVx5 look for `/.well-known/caldav` and `/.well-known/carddav` on your domain. These URLs must answer with an HTTP redirect (301, 303 or 307) to the DAV root. With them in place, users can enter just the domain (or the root URL) instead of full calendar and address book paths. sabre/dav does not do this itself, so it is configured in the proxy.
2. **A DAV-aware proxy.** The proxy must pass through all HTTP methods used by DAV (`PROPFIND`, `PROPPATCH`, `REPORT`, `MKCOL`, `MKCALENDAR`, `COPY`, `MOVE`, `LOCK`, `UNLOCK`), forward the `Host` and `X-Forwarded-Proto` headers, and allow large request bodies for file uploads. `APP_URL` must match the public HTTPS address, and the proxy should be configured as a trusted proxy.

The DAV root is `/dav/`. It is fixed once chosen, because clients store full URLs. Example for nginx:

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

## Client setup and compatibility

### Client compatibility

NexusDAV targets standards-compliant clients. Status below is the **target** for Version 1; nothing has been tested against these clients yet, and this table will be updated with tested client versions as the project matures.

| Client | CalDAV (events) | CardDAV (contacts) | Tasks (VTODO) | Notes (VJOURNAL) | Status |
|---|---|---|---|---|---|
| **DAVx5** (Android) | Yes | Yes | Yes, via a tasks app (jtx Board, Tasks.org, OpenTasks) | Yes, via jtx Board | Primary target |
| **Thunderbird** | Yes | Yes | Yes | No (not supported by the client) | Target |
| **Apple Calendar / Contacts** (macOS, iOS) | Yes | Yes | Yes, in Reminders (not in Calendar) | No | Target |
| Other standards-compliant clients | Expected | Expected | Client-dependent | Client-dependent | Best effort |

**About notes (VJOURNAL):** Notes are a server-side capability stored as standard CalDAV VJOURNAL entries. Client support is limited — currently mainly DAVx5 with jtx Board. Apple Notes and Thunderbird do not use VJOURNAL, so notes will not appear in those clients. Notes remain fully manageable from the NexusDAV admin panel and, later, the user portal.

**About tasks (VTODO):** on Android, DAVx5 only syncs the data; a separate tasks app is needed to display it. On Apple devices, tasks appear in Reminders.

### Connecting a client

Use the same steps for every client; only the menu names differ.

1. In the admin panel, create a user and generate an **app password** for each device. Do not use the main account password in clients.
2. Enter your server address (`APP_URL`, for example `https://dav.example.com`) and the user's email or username.
3. Paste the app password.
4. If discovery fails, enter the full DAV root URL instead of the domain: `APP_URL` followed by `/dav/` (for example `https://dav.example.com/dav/`).

- **DAVx5:** add an account, choose *Login with URL and user name*, enter the URL and credentials. Install a tasks app (jtx Board, Tasks.org) to see tasks and notes.
- **Thunderbird:** add a calendar (*New Calendar > On the Network > CalDAV*) and an address book (*New Address Book > CardDAV*).
- **Apple Calendar / Contacts:** add an account of type *Other* (CalDAV / CardDAV account).

The exact steps will be verified against tested client versions as the project matures.

### DAV endpoints

Most clients need only the root URL; they discover the rest on their own. Use the other URLs only for clients without discovery. Paths follow the sabre/dav layout.

| Purpose | URL |
|---|---|
| Root (enter this) | `https://dav.example.com/dav/` |
| Principal | `/dav/principals/users/<username>/` |
| Calendars | `/dav/calendars/<username>/<calendar-id>/` |
| Address books | `/dav/addressbooks/<username>/<addressbook-id>/` |
| Files (WebDAV) | `/dav/files/<username>/` |

Calendar and address book URLs use the collection identifier, not its display name.

## Protocols and features

### Supported protocols & extensions

Nothing is released yet; the table shows the target version for each item (see [Roadmap](#roadmap)).

| Protocol / extension | Target version |
|---|---|
| WebDAV, CalDAV, CardDAV | Version 1 |
| Principal / Home Collections | Version 1 |
| Tasks (VTODO) via CalDAV | Version 1 |
| Notes (VJOURNAL) via CalDAV | Version 1 |
| Collection sync (sync-token), provided by sabre/dav | Version 1 |
| Sharing (CalDAV/CardDAV Sharing + ACL) | Version 1.x |
| WebDAV-Push (can be switched on or off in the admin panel settings) | Version 1.x |
| CalDAV Scheduling (invitations, free/busy) | Version 2 |
| Files (WebDAV file storage) | Version 1.x |
| Managed Attachments | Version 2.x |

Collection sync is enabled through configuration.

### Features (planned)

These are long-term goals for the project. Implementation will be staged. The architecture and framework should be designed to support future features without major rewrites.

- Focused self-hosted DAV server with a modern admin panel, without the weight of a full groupware suite
- Admin interface for users, calendars, address books, shares, permissions and server settings
- Separate user portal for managing personal calendars, contacts, notes (VJOURNAL — limited client support), tasks and files (WebDAV)
- **Multilingual** admin panel (Version 1.x) and user portal (Version 2+)
- Docker-friendly
- Standards-compliant DAV protocols
- Search across events and contacts
- Import / Export of ICS and VCF (calendars, contacts, tasks and notes)

#### Admin panel (long-term)

- User quotas
- Logs / audit trail
- Health checks
- Export of server configuration (JSON)

#### Security (long-term)

- Optional 2FA

#### Infrastructure (long-term)

- Automatic backups (local + remote: S3, Backblaze, etc.)

## Backup, restore and upgrade

These are the intended procedures; commands will be confirmed once a release exists.

**What to back up**

- The database and the data volume, taken at the same time (or with the application briefly stopped) so they stay consistent.
- `.env` and `APP_KEY` (also on the data volume). Without the key, encrypted values cannot be read after a restore. Store it separately from the database backup.

**Database backup**

```bash
# PostgreSQL (do not copy the live data directory)
docker compose exec postgres pg_dump -U <db-user> -Fc <db-name> > nexusdav.dump

# SQLite (do not copy the live file; use the online backup)
sqlite3 <path-to-database> ".backup 'nexusdav.sqlite'"
```

Keep at least three copies on two kinds of storage, with one off-site, and encrypt remote copies. Test a restore on a clean machine regularly.

**Restore**

1. Stop the stack.
2. Restore the database and the data volume.
3. Start the stack, log in and sync one client to check.

**Upgrade**

1. Back up first.
2. Pin an exact image tag (for example `1.2.3`) instead of `latest`, and read the release notes.
3. Run `docker compose pull` and `docker compose up -d`. Database migrations run automatically on start.
4. Upgrade one minor version at a time. There is no rollback other than restoring the backup.

**Switching databases:** not supported in Version 1 (see [Database](#database)). Choose before the first start.

Automatic backups are a long-term goal (see [Roadmap](#roadmap)).

### Health check and logging

Intended behaviour; not implemented yet.

- **Health check:** an unauthenticated endpoint at `/health` that confirms the application is running and the database is reachable, without exposing details. The Compose file will use it as a `healthcheck`. A basic version is planned for Version 1; the fuller health checks in the admin panel are Version 3+.
- **Logs:** written to stdout / stderr, so `docker compose logs` works and Docker log drivers can collect them. Level is set with `LOG_LEVEL` (default `debug`; use `info` or higher in production).
- **What is logged:** request ID, method, path, status and user, plus failed logins (needed for rate limiting and brute-force protection). Passwords, app passwords and tokens are never logged.
- **Rotation:** the Compose file will set log rotation (`max-size`, `max-file`) so logs do not fill the disk.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Client says "401 Unauthorized" | The main password was used instead of an app password, or the app password was revoked. |
| Entering only the domain finds nothing | `/.well-known/caldav` and `/.well-known/carddav` redirects are missing in the reverse proxy. |
| Some operations fail (creating calendars, editing events) | The proxy blocks DAV methods such as `PROPFIND`, `REPORT`, `MKCALENDAR`. |
| Redirects or links use `http://` | `APP_URL` is not the public HTTPS address, or `X-Forwarded-Proto` is not forwarded. |
| Large file upload fails | The proxy request-body limit is too low. |
| Tasks or notes are not visible | The client app does not display them (see [Client compatibility](#client-compatibility)). |

### Migrating from another server

Export calendars as `.ics` and contacts as `.vcf` from your current server (Radicale, Baïkal, Nextcloud) and keep them as a backup. Import into NexusDAV is planned for Version 2; until then you can import the files through a client that is already connected to NexusDAV.

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
- WebDAV-Push, with an on/off toggle in the admin panel settings
- Multilingual admin panel
- Files (WebDAV file storage)
- Improved sync reliability

### Version 2

- Basic user portal
- CalDAV Scheduling (invitations, free/busy)
- Import / Export (ICS, VCF)
- Search across events and contacts

### Version 2.x

- Managed attachments

### Version 3+

- User quotas
- Logs / audit trail and health checks
- Automatic backups (local + remote)
- Multilingual user portal
- Optional 2FA
- Export of server configuration

## Contributing

Bug reports, client compatibility reports, documentation fixes and code contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## Security

Please do not report security vulnerabilities through public issues. See [SECURITY.md](SECURITY.md) for how to report them privately.

## License

MIT. Copyright (c) 2026 HummerSoft. See [LICENSE](LICENSE).
