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

## Features (planned)

- Lightweight self-hosted alternative to full groupware
- Admin interface for statistics, server settings, configurations, users, calendars, address books, shares and permissions
- Separate user portal for managing personal calendars, contacts, notes, tasks and files
- **Multilingual** admin panel and user portal
- Docker-friendly
- Standards-compliant DAV protocols

## Authentication

- Main user passwords: **Argon2id**
- **App passwords / personal access tokens** (for DAVx5, Thunderbird and other clients)

## Database

- **Primary**: PostgreSQL
- **Also supported**: SQLite (for simple deployments and development)

## Email

Outbound email only (via external mail server / SMTP). Used for:

- Invitations
 - Notifications
 - Reminders
- Meeting invitations and scheduling
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

Early stage — project skeleton.

## License

MIT
