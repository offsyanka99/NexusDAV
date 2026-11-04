# sabre/dav endpoints (Version 1)

Base DAV root: `/dav/`

Most clients only need the root URL. They discover principals and collections themselves. The paths below follow the standard sabre/dav layout.

## Core endpoints

| Purpose | Method(s) | Path | Notes |
|---------|-----------|------|-------|
| DAV root | PROPFIND, OPTIONS | `/dav/` | Entry point for clients |
| Principal and principal home | PROPFIND | `/dav/principals/<username>/` | User principal |
| Calendars home | PROPFIND, MKCALENDAR | `/dav/calendars/<username>/` | Home collection for calendars |
| Calendar collection | PROPFIND, REPORT, GET, PUT, DELETE | `/dav/calendars/<username>/<calendar-id>/` | Holds VEVENT, VTODO, VJOURNAL |
| Address books home | PROPFIND, MKCOL | `/dav/addressbooks/<username>/` | Home collection for address books |
| Address book | PROPFIND, REPORT, GET, PUT, DELETE | `/dav/addressbooks/<username>/<addressbook-id>/` | Holds VCARD |

## Sync and discovery

| Purpose | Method | Path / Header | Notes |
|---------|--------|---------------|-------|
| Collection sync | REPORT | `sync-collection` | Uses sync-token; see [Sync tokens](#sync-tokens) |
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

- Creating a user creates that user's calendar home and address book home, and a default calendar and address book inside them (`default`). There is no separate home row. The admin panel can add, edit, and delete further collections.
- A client can create collections in its own homes (`MKCALENDAR` on the calendar home, extended `MKCOL` on the address book home) and delete them. A new calendar offers the components that are switched on; a requested `supported-calendar-component-set` is reduced to those, and refused (403) when none is left.
- Discovery PROPFIND on `/dav/` returns `current-user-principal`. PROPFIND on that principal returns `calendar-home-set` and `addressbook-home-set` for the signed-in user only.
- `sync-collection` works on the homes and on each collection inside them.
- `supported-calendar-component-set` on a calendar is `VEVENT` when CalDAV is on, plus `VTODO` when tasks are on and `VJOURNAL` when notes are on.
- Collection identifiers (`<calendar-id>`, `<addressbook-id>`) are URI tokens, not display names.
- Another user's principal, homes and collections answer **404**, exactly like a username that does not exist, so a signed-in user cannot find out which usernames exist. A write into them fails the same way (404, or 409 when the parent is missing).
- **Service switches** (admin panel Settings page): with CalDAV off, every path under `/dav/calendars/` answers 403 "CalDAV is turned off on this server.", and the principal no longer offers `calendar-home-set`. CardDAV off does the same for `/dav/addressbooks/`. With Tasks or Notes off, `supported-calendar-component-set` leaves out `VTODO` or `VJOURNAL`, so a PUT of that component is refused (403) while existing items stay readable and deletable.
- A new calendar gets a `calendar-timezone` for its owner's time zone, or the server time zone from Configuration when the owner has none.
- Client values are checked against the column sizes on both databases: collection and resource names and UIDs at most 200 characters, display names at most 255, `calendar-color` at most 10, `calendar-order` a 32-bit integer, and dead property names at most 255. MKCALENDAR, MKCOL and PUT answer 400 when a value is too long. PROPPATCH answers 207 with 403 for that property and 424 for the others, and changes nothing.
- Clients should prefer discovery over hard-coded paths.
- All DAV methods required by the protocols must be passed through by the reverse proxy (`PROPFIND`, `REPORT`, `MKCALENDAR`, `MKCOL`, `PUT`, `DELETE`, `MOVE`, `COPY`, etc.). Tested configurations for nginx, Caddy and Traefik are in [docs/proxy](proxy/).
- Every answer carries an `X-Request-Id` header. The same ID is on the request's log lines and on its Diagnostics row.

## Sync tokens

A calendar or address book token is `http://sabre.io/ns/sync/<collection id>-<change number>`, and `getctag` has the same value. The id is the database id of the collection, which is never reused, so a collection that is deleted and created again with the same URI has a different token and ctag even when the change numbers match. Added in PR 14 of the [Version 1.x plan](plans/implementation-plan-v1x.md).

`sync-collection` answers **403** with `DAV:valid-sync-token` when the token:

- belongs to another collection, for example one deleted and created again with the same URI;
- is ahead of the collection's current change number;
- is older than the change history the collection still keeps (`NEXUSDAV_SYNC_HISTORY_DAYS`, default 90 days);
- is malformed, or has a prefix other than `http://sabre.io/ns/sync/`.

The client then syncs the collection from the start (an empty `sync-token`), as RFC 6578 describes. A token issued by 0.1.3 or earlier is the bare change number. It is accepted while it is not ahead, and the reply carries a token in the new form.

A home's token is a snapshot of its children and their tokens (`home-1.` and base64 JSON). A child that was created again under the same name is reported as changed, so the client goes on to sync it and gets the 403 above.

## Concurrent writes

`PUT`, `DELETE`, `MOVE`, `COPY`, `PROPPATCH`, `MKCOL` and `MKCALENDAR` on a calendar or address book run in one database transaction, and writes to the same collection wait for each other (SQLite: the database write lock; PostgreSQL: a lock per collection). `If-Match` and `If-None-Match` are checked inside that transaction:

- Two clients sending the same `If-Match`: the first one wins, the second gets **412 Precondition Failed** and should fetch the item again. The client does not tell the user; it shows the winning version after its next sync.
- Two creates of the same new item with `If-None-Match: *`: the second gets **412**. Without the header the second replaces the first, as `PUT` normally does.
- The `ETag` returned by `PUT` is the one a later `GET` returns. When the server changes the data (for example it adds a missing `UID` to a vCard), `PUT` returns no `ETag`, and the client fetches the stored version (RFC 4791, 5.3.4).
- A writer waits at most 5 seconds for the lock; after that the request fails.
