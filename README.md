# NexusDAV

**Self-hosted CalDAV / CardDAV / WebDAV server** with a dedicated admin panel and user portal.

Built on top of the [sabre/dav](https://sabre.io/) PHP library.

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

## Target clients

- DAVx5
- Thunderbird
- Apple Calendar / Contacts
- Other standards-compliant DAV clients

## Features (planned)

These are long-term goals for the project. Implementation will be staged. The architecture and framework should be designed to support future features without major rewrites.

- Lightweight self-hosted alternative to full groupware
- Admin interface for users, calendars, address books, shares, permissions and server settings
- Separate user portal for managing personal calendars, contacts, notes (VJOURNAL), tasks and files (WebDAV)
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

- Rate limiting
- Brute-force protection
- Optional 2FA

### Infrastructure (long-term)

- Automatic backups (local + remote: S3, Backblaze, etc.)

## Roadmap

### Version 1 (minimal viable server)

- Minimal admin panel only (no user portal)
- Create and manage users
- Authentication: Argon2id user passwords and app passwords / personal access tokens (for DAVx5, Thunderbird and other clients)
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
- Optional 2FA, rate limiting, brute-force protection
- Export of server configuration

## Configuration

Docker Compose is split into a shared base file and one override per database, so the database is already defined at install time:

```
compose.yaml            # application, shared volumes, ports
compose.postgres.yaml   # adds the postgres service and DB_* variables
compose.sqlite.yaml     # sets DB_CONNECTION=sqlite and the database file path
```

Start with PostgreSQL (recommended for production):

```bash
docker compose -f compose.yaml -f compose.postgres.yaml up -d
```

Or with SQLite (simple deployments and development):

```bash
docker compose -f compose.yaml -f compose.sqlite.yaml up -d
```

The `.env` file contains only what the application needs to boot: database connection, `APP_KEY`, `APP_URL` and the initial admin account. All other server settings (SMTP, quotas, defaults, etc.) are configured through the admin UI and stored in the database. If a setting is also defined as an environment variable, the environment value takes precedence and the field is read-only in the UI.

## Authentication

- Main user passwords: **Argon2id**
- **App passwords / personal access tokens** (for DAVx5, Thunderbird and other clients), available from Version 1

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
- Docker (planned)

**Use the latest stable versions** of all libraries and dependencies when possible. (Webdav-Push don't have a release version. Use the latest commit).

## Status

Early stage — project skeleton. Features listed above are roadmap items and will be implemented in stages.

## License

MIT
