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
| Files home | PROPFIND, MKCOL, GET, HEAD, PUT, DELETE, COPY, MOVE, LOCK, UNLOCK, PROPPATCH | `/dav/files/<username>/` | Off until an admin switches Files on; see [Files](#files) below |

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
- Files (WebDAV file storage; it arrived in Version 1.x, see [Files](#files) below)

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

## Shared calendars

An admin shares a calendar in the panel (see [sharing.md](design/sharing.md)). The sharee's client finds it in their own calendar home, under a name of its own, usually the owner's calendar name (`default-<owner>` when the sharee already has that name).

- **Read only:** the sharee reads events, tasks and notes. A `PUT`, `DELETE` or `MOVE` of an item is **403**. `current-user-privilege-set` holds `read` and `write-properties`, so DAVx5 and Thunderbird show the calendar as read-only.
- **Read and write:** the sharee creates, changes and deletes items in the owner's calendar. The owner and every other sharee see the change.
- `DAV:owner` is the sharee's own principal; the owner's principal still answers 404 to them.
- The sharee's display name, color and order are their own. Changing them does not change the owner's calendar or its sync token.
- The sync token is the owner's calendar token. On a share, a bare change number from an older token form is refused with 403 `valid-sync-token`.
- `DELETE` of the shared calendar by the sharee removes only their share. The owner's calendar stays.
- Clients cannot share: no sharing plugin is loaded, a sharing `POST` (`share-resource`, CalendarServer `share` or `invite-reply`) is **403**, and no property lists other sharees.
- With **Sharing** off (`NEXUSDAV_SHARING=false`) shared calendars are not listed and their paths answer 404. The shares are kept.

## Shared address books

An admin shares an address book in the panel. The sharee's client finds it in their own address book home, under a name of its own, usually the owner's (`default-<owner>` when the sharee already has that name), and named "<name> (<owner's display name>)".

- **Read only:** the sharee reads contacts with `PROPFIND`, `GET`, `addressbook-query`, `addressbook-multiget` and `sync-collection`. A `PUT`, `DELETE`, `MOVE`, `LOCK` or `PROPPATCH` of a contact is **403**. `current-user-privilege-set` holds `read` and `write-properties` on the address book (for the sharee's own name), so DAVx5 and Thunderbird show it as read-only.
- **Read and write:** the sharee creates, changes and deletes contacts in the owner's address book. The owner and every other sharee see the change, because the contacts and the sync token are the owner's.
- `DAV:owner` is the sharee's own principal; the owner's principal still answers 404 to them. Another user's path to the shared address book answers 404.
- The sync token and the ctag are the owner's address book token, so an owner change reaches the sharee as one incremental change. A bare change number from an older token form is refused with 403 `valid-sync-token`.
- The sharee's address book home lists the share and its sync token follows it: a new share is reported as added, and a removed one as deleted.
- The sharee's display name and description are their own. Changing them does not change the owner's address book or its sync token. Another device of the sharee sees the new name on its next `PROPFIND`, not through a sync.
- `DELETE` of the shared address book by the sharee removes only their share. The owner's address book and contacts stay. DAVx5 and Thunderbird never send it (see the guide's clients section), so a client user stops a share on the device and an admin removes it in the panel.
- Clients cannot share: a sharing `POST` is **403** (same as for calendars).
- With **Sharing** off (`NEXUSDAV_SHARING=false`) shared address books are not listed and their paths answer 404; with **CardDAV** off the whole address book tree is refused. The shares are kept.

## Files

Ordinary WebDAV at `/dav/files/<username>/` (0.4.0). It is off until an admin switches it on in **Settings → Files**.

| Request | Answer |
|---|---|
| Any request, Files off | **403** "Files is turned off on this server." The files are kept |
| Any request into another user's home | **404**, as for a username that does not exist. A new name there fails like a name under a missing user (404 or 409). This is decided right after sign-in, before lock tokens are checked |
| `GET`, `HEAD` | The file; `Range` is supported (206, or 416 when the range is past the end). `ETag` and `Last-Modified` come from the file |
| `PUT` | 201 or 204; `If-Match` and `If-None-Match` are honoured (412). The body is written to a temporary file and renamed into place. **413** when it is over Maximum upload size (checked from `Content-Length`, and by a byte count for a chunked upload); **400** when it ends before its `Content-Length`; **507** when the disk or a quota is full. A failed upload leaves nothing and keeps the old file |
| `MKCOL` | 201; 405 when the name exists; 409 when the parent is missing |
| `PROPFIND` | Depth 0 and 1. Depth `infinity` is answered as depth 1. A folder reports `quota-used-bytes` and `quota-available-bytes` of the whole volume |
| `COPY`, `MOVE` | Files and folder trees inside the home, with `Overwrite` (412 when it is `F` and the name exists) and `Depth`. A rename is a `MOVE`. A folder into itself is 409. Into another home is 409, into another root (`/dav/calendars/`, `/dav/addressbooks/`) is 403. A copy is not an upload and has no size limit. A move releases the source's locks and moves its properties |
| `LOCK`, `UNLOCK` | Shared and exclusive, depth 0 and `infinity`. A write without the lock token is **423**; a `LOCK` of a new name creates an empty file |
| `PROPPATCH` | Dead properties, kept in `propertystorage`; they follow a copy or a move and go with a delete |
| `DELETE` | A file, or a folder with everything in it. A link on disk is removed, never followed. The home itself cannot be deleted (403) |
| A path with `..` (also as `%2e%2e` or an encoded slash), or a NUL byte | **403**. A path that resolves outside `/dav/` is 404 |

- A name has at most 255 bytes, valid UTF-8 and no control characters (**400** otherwise), and is stored in Unicode NFC. A name that is not NFC, or breaks these rules, is not listed when it was put on the disk by something else.
- A symbolic link, a device, a socket or a pipe on the disk is never listed or opened, and a write onto one is refused (**403**).
- The `ACL` method is refused, and there are no sync tokens and no `REPORT` on the file home.
- A write to a file home is not run again after a busy database; it answers **503** with `Retry-After`.
- Uploads in progress are in `/data/files/.uploads/`, outside every home. A leftover older than a day is removed by the next upload.

## Concurrent writes

`PUT`, `DELETE`, `MOVE`, `COPY`, `PROPPATCH`, `MKCOL` and `MKCALENDAR` on a calendar or address book run in one database transaction, and writes to the same collection wait for each other, also when one comes through the owner's path and the other through a sharee's (the lock is the calendar or address book itself) (SQLite: the database write lock; PostgreSQL: a lock per collection). `If-Match` and `If-None-Match` are checked inside that transaction:

- Two clients sending the same `If-Match`: the first one wins, the second gets **412 Precondition Failed** and should fetch the item again. The client does not tell the user; it shows the winning version after its next sync.
- Two creates of the same new item with `If-None-Match: *`: the second gets **412**. Without the header the second replaces the first, as `PUT` normally does.
- The `ETag` returned by `PUT` is the one a later `GET` returns. When the server changes the data (for example it adds a missing `UID` to a vCard), `PUT` returns no `ETag`, and the client fetches the stored version (RFC 4791, 5.3.4).
- A writer waits at most 5 seconds for the lock. If the database is still busy, the server runs the request again, up to three more times after a short random wait. A write that is still refused, and any read that finds the database busy, is answered **503 Service Unavailable** with `Retry-After: 5`; nothing was saved, and the client sends it again later. The 503 is listed on Diagnostics and logged with the request ID. A 500 never shows the exception text, only "Internal server error." and the request ID.
