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
- Sync-token / Collection Sync
- Sharing (CalDAV/CardDAV Sharing + ACL)
- Managed Attachments
- Tasks (VTODO) via CalDAV
- Notes (VJOURNAL) via CalDAV

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
- Create calendars, address books, tasks and notes
- Basic sync working with DAVx5 (and ideally other clients)
- Core DAV protocols (WebDAV, CalDAV, CardDAV)

### Later versions (ideas)

- User portal
- Sharing and ACL
- WebDAV-Push
- CalDAV Scheduling
- App passwords / tokens
- Import/Export
- Search
- Quotas, logs, health checks
- Automatic backups (local + remote)
- Multilingual UI
- Optional 2FA, rate limiting, etc.

## Configuration

Initial `docker-compose.yml` / `.env` contains only the minimal required variables. All other server settings are configured through the admin UI on first launch / first admin login.

## Authentication

- Main user passwords: **Argon2id**
- **App passwords / personal access tokens** (for DAVx5, Thunderbird and other clients)

## Database

- **Primary**: PostgreSQL
- **Also supported**: SQLite (for simple deployments and development)

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

**Use the latest stable versions** of all libraries and dependencies.

## Status

Early stage — project skeleton. Features listed above are roadmap items and will be implemented in stages.

## License

MIT
