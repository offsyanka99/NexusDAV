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
- Admin interface for users, calendars, address books, shares and permissions
- Separate user portal for managing personal calendars, contacts and tasks
- Docker-friendly
- Standards-compliant DAV protocols

## Tech stack

- **Core**: PHP + [sabre/dav](https://github.com/sabre-io/dav)
- **Application**: [Laravel](https://laravel.com/)
- **Admin panel**: [Filament](https://filamentphp.com/) (Livewire + Tailwind CSS)
- **Frontend styling**: Tailwind CSS
- Docker (planned)

## Status

Early stage — project skeleton.

## License

MIT
