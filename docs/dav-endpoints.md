# sabre/dav endpoints (Version 1)

Base DAV root: `/dav/`

Most clients only need the root URL. They discover principals and collections themselves. The paths below follow the standard sabre/dav layout.

## Core endpoints

| Purpose | Method(s) | Path | Notes |
|---------|-----------|------|-------|
| DAV root | PROPFIND, OPTIONS | `/dav/` | Entry point for clients |
| Principal | PROPFIND | `/dav/principals/users/<username>/` | User principal |
| Principal home | PROPFIND | `/dav/principals/users/<username>/` | |
| Calendars home | PROPFIND, MKCALENDAR | `/dav/calendars/<username>/` | Home collection for calendars |
| Calendar collection | PROPFIND, REPORT, GET, PUT, DELETE | `/dav/calendars/<username>/<calendar-id>/` | Holds VEVENT, VTODO, VJOURNAL |
| Address books home | PROPFIND, MKCOL | `/dav/addressbooks/<username>/` | Home collection for address books |
| Address book | PROPFIND, REPORT, GET, PUT, DELETE | `/dav/addressbooks/<username>/<addressbook-id>/` | Holds VCARD |

## Sync and discovery

| Purpose | Method | Path / Header | Notes |
|---------|--------|---------------|-------|
| Collection sync | REPORT | `sync-collection` | Uses sync-token |
| Calendar query | REPORT | `calendar-query` | Filter events |
| Address book query | REPORT | `addressbook-query` | Filter contacts |
| Current user principal | PROPFIND | `current-user-principal` | Discovery |
| Calendar home set | PROPFIND | `calendar-home-set` | Discovery |
| Address book home set | PROPFIND | `addressbook-home-set` | Discovery |

## Well-known (handled by the application or proxy)

| Path | Redirects to |
|------|--------------|
| `/.well-known/caldav` | `/dav/` |
| `/.well-known/carddav` | `/dav/` |

## Version 1 scope

Included in Version 1:

- Principal and home collections
- CalDAV (VEVENT, VTODO, VJOURNAL)
- CardDAV
- Collection sync (sync-token)
- Basic REPORT queries

Not in Version 1 (later):

- Sharing / ACL
- WebDAV-Push
- Scheduling (free/busy, invitations)
- Managed attachments
- Files (WebDAV file storage)

## Notes

- Collection identifiers (`<calendar-id>`, `<addressbook-id>`) are URI tokens, not display names.
- Clients should prefer discovery over hard-coded paths.
- All DAV methods required by the protocols must be passed through by the reverse proxy (`PROPFIND`, `REPORT`, `MKCALENDAR`, `MKCOL`, `PUT`, `DELETE`, `MOVE`, `COPY`, etc.).
