# Address book sharing design

Design note for the spike in [PR 20](../plans/implementation-plan-v1x.md#pr-20--spike-address-book-sharing-design-effort-medium). The decisions below keep address book sharing consistent with the calendar model (sabre's `calendarinstances`) and with principal isolation (Decision 5). sabre/dav 4.7.1 has calendar sharing and no address book sharing plugin. CardDAV stays on sabre's `addressbooks` table. Shares are a separate instance row, not a second ACL store.

## In short

- A share is a row in `addressbookinstances`, linked to the owner's `addressbooks` row. Calendars keep using `calendarinstances`.
- The sharee sees the share in their own home. The URI is the owner's, with `-<owner username>` added on a clash.
- Cards and the change log stay on the owner's book. The sharee's sync token is the owner's, so one change log serves everyone.
- `DAV:owner` is the sharee. What they may do comes from the access level (read-only or read-write), never from being the owner.
- The sharee can rename their copy and leave the share; neither touches the owner's data or token.
- Two things found while checking the existing code: `calendarinstances` allows one share per person on PostgreSQL ([indexes](#indexes-and-constraints)), and the PR 16 write lock is keyed on the path, which a share has two of ([write lock](#write-lock)).

## Decisions

| Topic | Choice | Reason |
|---|---|---|
| Storage shape | **Instance table** (`addressbookinstances`), one row per sharee. The owner's address book stays a row in `addressbooks`, with no instance row of its own | sabre's calendar sharing is one `calendarinstances` row per (calendar, principal): access `1` owner, `2` read, `3` read-write. CardDAV has no instance table, so the owner stays the `addressbooks` row (that row is access `1`). A sharee is one `addressbookinstances` row, access `2` or `3`. An owner row in the instance table would copy `addressbooks` and fight the PDO backend. A share table with its own privilege rows would be a second ACL, which Decision 4 rules out |
| URI in the sharee's home | The owner's URI. Only when the sharee already uses that URI, `-<owner username>` is added; if that is taken too, `-<owner username>-2`, `-3`, and so on | Short URIs when there is no clash. Usernames match `[a-z0-9][a-z0-9_-]{0,62}` and are not renamed, so the result stays a valid collection URI. sabre's own share insert uses a UUID; this project does not, so the URL stays readable. The base URI is shortened when needed so the result fits the 200-character limit |
| Home listing and sync token | Shared address books are children of the sharee's address book home. The home sync token is unchanged `HomeSync`: the child names and each child's sync token | `AddressBookHome` lists whatever `getAddressBooksForUser` returns. That query today reads only `addressbooks`. The shared listing adds the sharee's instance rows. `HomeSync` then counts a new or removed share with no change of its own, and a removed share is reported deleted. Do not give the home a second changelog |
| Sync token of a shared address book | The owner's address book token, `<owner's address book id>-<number>`, and the same value for the ctag | `cards` and `addressbookchanges` stay on the owner's `addressbooks.id`. One change log serves the owner and every sharee. The id in the token is that book id, never the instance id. A read-write sharee's create, change, or delete bumps the owner's token |
| `DAV:owner` | The **sharee's** principal | sabre's `Calendar::getOwner()` returns the instance `principaluri`. RFC 3744's owner is the resource owner instead, and Apple clients look that principal up to name the person who shared the calendar. This server answers 404 for another user's principal (Decision 5), so that href would be a link that fails. The property follows sabre, not RFC 3744 |
| Privileges | From the access level, not from `DAV:owner`. Read-only is `{DAV:}read`, plus `{DAV:}write-properties` on the collection only. Read-write also gets `{DAV:}write`. No `{DAV:}all`, `{DAV:}share`, `{DAV:}bind`, or `{DAV:}unbind` entry | The sharee is reported as owner. sabre's `AddressBook` uses `ACLTrait`, which grants `{DAV:}all` to `{DAV:}owner`, and `getChildACL()` grants `{DAV:}all` to `getOwner()`. Either one lets a read-only sharee write. `SharedCalendar::getACL()` follows the access level; a shared node does the same, without the extra ACEs listed under Privileges |
| Leaving a share | A `DELETE` of the shared address book by the sharee removes only their instance row. The owner's address book and cards stay | Same as sabre's `deleteCalendar` when access is not `1`, and the same as calendars (Settled question 2). The sharee can delete the child because it sits in their own home |
| Renaming | A `PROPPATCH` of `{DAV:}displayname` by the sharee changes their instance row only. It does not bump the owner's sync token and does not write `addressbookchanges` | Decision 17. sabre's `updateCalendar` does write a change row; do not copy that. The owner's clients must not see the sharee's rename |

## `addressbookinstances` table

One row per (address book, sharee). Access is only `2` or `3`. There is no access `1` row, and no row whose `principaluri` is the owner. Shares are created already accepted. There is no invite list and no group.

Mirrors the parts of `calendarinstances` that apply to address books. Address books have no color, order, or timezone. Those three stay on `calendarinstances` only.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, PK | The instance. Not the id passed to card or change queries |
| `addressbookid` | bigint, foreign key to `addressbooks.id`, cascade on delete | The owner's book. `cards` and `addressbookchanges` use this id |
| `principaluri` | varchar(255) | The sharee, `principals/<username>`. Never the owner |
| `uri` | varchar(200) | The name in the sharee's home |
| `displayname` | varchar(255), nullable | Starts as "<name> (<owner's display name>)" (Decision 17), cut to 255 characters. The sharee can rename it |
| `description` | text, nullable | Copied from the owner's address book when the share is created. A later change by the sharee stays on this row |
| `access` | smallint | `2` read-only or `3` read-write. sabre's `ACCESS_READ` and `ACCESS_READWRITE`. No other value |
| `share_href` | varchar(100), not null | The sharee again, `principals/<username>`, the same string as `principaluri`. sabre stores the invite href here. This server has no invites; the column is kept so calendar and address book shares match |
| `share_displayname` | varchar(100), nullable | The sharee's display name at the time of the share, cut to 100 characters, as on `calendarinstances`. Not the collection name. A later change of the user's display name does not rewrite it |
| `share_invitestatus` | smallint, default `2` | `2` accepted (`INVITE_ACCEPTED`). No `1`, `3`, or `4` |

The owner's display name for the initial collection name is read from the owner's user record when the share is created. It is not a column of its own. That initial name is the same "<name> (<owner's display name>)" string on an address book and on a calendar (Decision 17), not a copy of the owner's display name. `users.display_name` and the collection name are each up to 255 characters, so the combined form can be 513. Cut it to 255 before insert: shorten the owner's display name inside the parentheses first. If that name is shortened away, omit the parentheses and cut the collection name to 255. PostgreSQL rejects a longer `varchar(255)` and SQLite stores it, so the cut is what makes both engines keep the same name. `share_displayname` is only the sharee's user display name, cut to 100. A later `PROPPATCH` longer than 255 is refused (`DavLimits`), not cut.

A second share for the same sharee and address book updates `access` on the existing row. It does not insert another row.

### Indexes and constraints

`calendarinstances` already copies sabre's scripts, and the two engines are not the same:

- Both: unique (`principaluri`, `uri`).
- PostgreSQL: unique (`principaluri`, `calendarid`) and unique (`principaluri`, `share_href`).
- SQLite: unique (`calendarid`, `principaluri`) and unique (`calendarid`, `share_href`).

(`principaluri`, `calendarid`) and (`calendarid`, `principaluri`) are the same pairs. sabre only swaps the column order. Both mean one row per principal per calendar. `addressbookinstances` uses that same order: unique (`principaluri`, `addressbookid`) on PostgreSQL, unique (`addressbookid`, `principaluri`) on SQLite.

(`principaluri`, `share_href`) and (`calendarid`, `share_href`) are not the same. `share_href` is the sharee's principal, so the PostgreSQL index is one non-null `share_href` per principal on the whole server. A second share to the same person fails. SQLite's index is one `share_href` per collection, which is the rule this project wants. NULL does not save it: PostgreSQL unique allows many nulls, but a share row's `share_href` is not null. Owner rows on `calendarinstances` keep a null `share_href`, and there are no shares yet.

`addressbookinstances` therefore uses unique (`addressbookid`, `share_href`) on both engines. That index also lists the sharees of one book. PR 21 drops `calendarinstances`'s PostgreSQL unique (`principaluri`, `share_href`) and adds unique (`calendarid`, `share_href`), the SQLite index, before a user can receive two calendar shares. The (`principaluri`, `calendarid`) index stays.

Also unique (`principaluri`, `uri`) on `addressbookinstances`. That key and the one on `addressbooks` are separate, so one principal still needs a single URI namespace: both tables together. `AddressBookBackend::createAddressBook` (what `MKCOL` calls) and share creation both refuse a URI the other table already has for that principal. `suggestedAddressBookUri` and `addressBookUriTaken` use the same check, or the panel offers a share's name.

The check and the insert run in one transaction. Before the read, both writers `UPDATE` the sharee's `principals` row through the query builder, setting `uri` to itself. That is a real write lock. `SELECT … FOR UPDATE` is not: SQLite compiles the lock clause to nothing, so two transactions can both see the URI free, and `getChild` and `HomeSync` then keep one child of that name and drop the other. If the insert hits the unique key, roll back and try the next candidate in a new transaction. PostgreSQL aborts the transaction on that error, so the retry cannot continue in the same one. `insertOrIgnore` stays the bootstrap path, not a share: a skipped insert is not a successful share.

### URI

Candidates for the sharee's home, in order, using the first that is free in both of the sharee's tables:

1. The owner's URI.
2. `<owner uri>-<owner username>`.
3. `<owner uri>-<owner username>-2`, then `-3`, and so on.

Each candidate matches `CollectionService::SLUG_PATTERN` (at most 200 characters). When a suffix would pass 200, shorten the owner URI from the end until it fits. The owner's username is not shortened. Removing a share does not reserve the URI. A later share of the same book picks again by this rule.

### What the sharee is served

`getAddressBooksForUser` keeps the owned books it already returns and appends one entry per instance row for that principal. The panel's address book relation stays the `addressbooks` table; received shares are not owned books.

`AddressBookHome::getChild` and `getChildren` build a shared node for an instance row, and sabre's `AddressBook` for an owned book. The stock node grants `{DAV:}all` to `{DAV:}owner` and its `delete` removes the `addressbooks` row. With `principaluri` set to the sharee, that would let a read-only sharee write cards and delete the owner's book. The shared node overrides `getACL`, `getChildACL`, `propPatch`, and `delete`, and it reads the instance `access`, not `DAV:owner`.

The DAV entry:

- `id` is `addressbooks.id`. Card and change calls use that id. The instance id is remembered beside it for `PROPPATCH` and `DELETE`, the way a calendar id is `[calendar id, instance id]`. Passing the instance id to the card backend reads or writes the wrong book.
- `uri`, `{DAV:}displayname`, and the description come from the instance.
- `principaluri` is the sharee's, because `AddressBook::getOwner()` returns it.
- The sync token and the ctag are `SyncToken::format(addressbooks.id, addressbooks.synctoken)`, then the ctag prefix `AddressBookBackend` already adds. Not a token of the sharee's own.

`PROPPATCH` of `{DAV:}displayname` and `{urn:ietf:params:xml:ns:carddav}addressbook-description` writes the instance. Any other property is a dead property: sabre's property storage keeps it under the sharee's own path, where the owner cannot read it (found in the spike; the share node does not refuse it, and the sharee holds `{DAV:}write-properties`). `DELETE` deletes the instance row only.

The collection's `sync-collection` on a share accepts only a token whose id is this `addressbooks.id`. A plain number is 403 `valid-sync-token`. Decision 20 still accepts a plain number on an owned book: `SyncToken::resolve` treats `^[1-9][0-9]{0,9}$` as a change number with no collection id. A share can reuse a URI the sharee's client already synced. `HomeSync` then reports that name as modified, and the client sends the old plain token. Reading the owner's change log from that number skips deletes the client still has and returns the other book's history. An id-scoped token for a different book is already rejected. A token from before the share was removed and given again is still the owner's id-scoped token: it is accepted while it is inside the kept history. Unshare does not bump the owner's token. The sharee's client drops the book because the home sync reports the child deleted, then added if it is shared again. The same plain-number refusal applies to a shared calendar, whose id is `calendars.id`. Owned calendars keep Decision 20.

A rename does not change the owner's token, so `HomeSync` does not report the child as modified. Another device of the sharee sees the new name on its next `PROPFIND`. That is not a reason to give the sharee a separate collection token.

When Sharing is off, instance rows stay in the table and are left out of `getAddressBooksForUser`. Their URL answers 404. That switch is PR 23; this note does not add it.

**As built (PR 24).** The entry also carries `share-access` and `share-instance-id`; the home builds an `AddressBookNode` for an entry that has `share-access`, and sabre's node for the rest. The plain-number refusal is in the node's `getChanges`, not in the backend: the backend gets only `addressbooks.id`, the same for the owner and a sharee, so it cannot tell them apart (a calendar's id is `[calendar id, instance id]`). Until PR 25 the node grants `{DAV:}read` only, so every share is read-only and `PROPPATCH`, `PUT`, `DELETE` and a `DELETE` of the book are refused (the book's `delete` and `propPatch` also throw, so a path that skips the ACL check cannot reach the owner's book). Sabre's `AddressBook::getChild` builds a card with no ACL, which falls back to everything for the book's principal, so the node builds its cards with the read-only ACL.

### Write lock

`DavWriteLock` (PR 16) makes a write to a collection wait for other writes to it. SQLite takes its one database write lock, so every writer already waits. PostgreSQL takes `pg_advisory_xact_lock`, keyed on the collection taken from the request path: `calendars/<user>/<uri>` or `addressbooks/<user>/<uri>`.

A shared collection has two paths, the owner's and the sharee's. A write through each would take a different lock on PostgreSQL, and two clients holding the same `If-Match` could both succeed: the silent overwrite PR 16 removed. So the key is the underlying collection, not the path:

- `addressbooks/<user>/<uri>`: the `addressbooks` row with that `principaluri` and `uri` (the owner), else the `addressbookinstances` row with that `principaluri` and `uri`, and its `addressbookid`. The key is built from that `addressbooks.id`.
- `calendars/<user>/<uri>`: the `calendarinstances` row with that `principaluri` and `uri`, and its `calendarid`. The owner's row and every sharee's row carry the same `calendarid`. The key is built from `calendars.id`.
- No row: a `MKCOL` or `MKCALENDAR` of a new collection, or a path that does not exist. The key stays the path. A collection that does not exist yet has one path, and the URI rule above stops a share from taking the same name.
- `MOVE` and `COPY` resolve the source and the destination in the same way and take the keys in sorted order, as now.

The lookup runs before the lock, after the ACL check. If the share is removed in the milliseconds between the check and the write, the write lands on the owner's book as it was allowed a moment earlier; that gap is accepted. An id never changes, so a rename cannot move a collection to another key.

WebDAV locks (`LOCK`) are stored per path, so a lock taken through one path does not stop a write through the other. DAVx5 and Thunderbird do not lock events or contacts, so this stays a documented limit of shares.

PR 23 changes `DavWriteLock` for both kinds of collection. Its tests, and PR 25's, run two processes writing the same item with the same `If-Match`, one through each path, on PostgreSQL as well as SQLite: one gets 204 and the other 412.

### Cleanup

- Deleting the owner's address book, from the panel or over DAV, deletes the `addressbooks` row. The foreign key removes its shares.
- Deleting a user: their own address books go, and with them the shares of those books. `DavDataCleaner` also deletes `addressbookinstances` where `principaluri` is the deleted user, the same way it already deletes `calendarinstances` the user received.
- Locks and dead properties for the share live under the sharee's path `addressbooks/<sharee username>/<instance uri>`. Removing the instance removes that path. It does not remove the owner's cards.
- Deleting the owner's calendar or address book also removes each sharee's locks and dead properties for it (`CalendarBackend::deleteCalendar`, `AddressBookBackend::deleteAddressBook`), the same as an unshare.
- Received calendar shares are rows of the sharee in `calendarinstances`. Everything that means "the user's calendars" reads access `1` only: the user's `calendarInstances` relation (the panel's Calendars table and counts), the Dashboard count, and `CollectionService`, which refuses to edit or delete a received share as if it were owned.

### Listing every instance

PR 21's "list every instance of a collection" (M5 needs it) returns the owner's row in `addressbooks` and the rows in `addressbookinstances` for that `addressbookid`. Calendars return all their `calendarinstances` rows, because there the owner has an instance row (access `1`).

## Privileges

Decision 4: sabre's `DAVACL` enforces the access level. There is no `ACL` method, no `setACL`, and no custom privilege. `getSupportedPrivilegeSet()` stays null so sabre's default set is used.

The only principal in the ACL is the sharee's principal, the same URL as `DAV:owner`. Nothing is granted to `{DAV:}owner`, `{DAV:}authenticated`, `{DAV:}all`, or a calendar-proxy principal.

- Access `2`: `{DAV:}read` on the collection and on each card. `{DAV:}write-properties` on the collection only, so the sharee can change their own display name and description. Not `{DAV:}write`.
- Access `3`: `{DAV:}read` and `{DAV:}write` on the collection and on each card. `{DAV:}write-properties` is already part of `{DAV:}write`.

A read-only sharee holds `{DAV:}write-properties` on the collection, so they can also store dead properties there. Those live under the sharee's own path and nobody else reads them, so this is harmless. Cards stay read-only.

The ACL entries are not `{DAV:}share`, `{DAV:}bind`, or `{DAV:}unbind`. sabre expands `{DAV:}write` on a collection to include bind and unbind, and creating or deleting a card checks those. Granting `{DAV:}write` is how a read-write sharee saves a contact. It does not let them share the book on. A read-only sharee does not get `{DAV:}write`, so a `PUT` or a `DELETE` of a card is 403.

`{DAV:}all` is never granted. On an address book that grant is the bug: `ACLTrait::getACL()` gives `{DAV:}all` to `{DAV:}owner`, and the sharee is the owner property.

`CalendarBackend` extends sabre's PDO backend, which implements `SharingSupport`, so `CalendarHome` already builds a `SharedCalendar` for every calendar. That node's `getACL()` grants `{DAV:}share` to an owner and names `calendar-proxy-read` and `calendar-proxy-write`. Do not leave those ACEs in place. Do not stop implementing `SharingSupport` to hide them: the home would then build a `Calendar`, and `Calendar::getACL()` grants write unless `{http://sabredav.org/ns}read-only` is set. The PDO backend sets a different key, `read-only`, so a read-only sharee would be given write. The node used for a share reads `share-access` (the instance's `access`) and applies the list above. It does not grant read-free-busy to `{DAV:}authenticated`. An owner's calendar (access `1`) keeps read and write for their own principal, and the read-free-busy ACE it already has. It does not get `{DAV:}share` or a calendar-proxy ACE.

### What is not advertised

Decision 2. In Version 1.x shares are created only in the admin panel, and never by a client.

- Do not register `Sabre\DAV\Sharing\Plugin` or `Sabre\CalDAV\SharingPlugin`. The first advertises `resource-sharing` and handles `POST` of `application/davsharing+xml`. The second advertises `{http://calendarserver.org/ns/}invite` and handles a calendarserver share or invite-reply `POST`.
- `OPTIONS` does not list `resource-sharing`. No `{DAV:}invite`, `{DAV:}share-access`, `{DAV:}share-resource-uri`, or `shared` / `shared-owner` resource type.
- A sharing `POST` gets 403.
- The principal collection stays `Sabre\DAVACL\PrincipalCollection`. `calendar-proxy-read` and `calendar-proxy-write` are not children of a principal.
- `MKCOL` creates an address book the user owns. It never inserts an instance row. A client cannot bind an existing book into their home.

The instance properties are not a sharee list. A `PROPFIND` does not show other sharees.

## Calendars

Calendar shares use the existing `calendarinstances` table. The owner already has the access `1` row. A sharee gets access `2` or `3`, `share_invitestatus` `2`, and `share_href` the sharee's principal. No share with the owner, and one row per sharee per calendar, once the PostgreSQL `share_href` index above is replaced.

The sharee's display name starts as the same "<name> (<owner's display name>)" string as an address book, cut to 255. Color, order, and timezone are copied from the owner's instance. Those values then live on the sharee's row. Changing them does not change the owner's row and does not write `calendarchanges` or bump `calendars.synctoken`. Objects and the change log stay on the owner's `calendars.id`. The sync token the sharee sees is that calendar's token (`SyncToken` already uses `calendars.id`, the first element of sabre's `[calendar id, instance id]`). `getCalendarsForUser` already returns every instance for the principal, so the calendar home lists a share as soon as the row exists. `HomeSync` includes it with no further change.

sabre's share insert forces `transparent` to `1` and sets the URI to a UUID. This project does neither. Transparency stays the column default. The URI candidates are the same as for address books. `calendarinstances` and `calendarsubscriptions` are one namespace for that principal: each has its own unique (`principaluri`, `uri`), and neither covers the other. The share-URI loop, `createCalendar`, and `createSubscription` all reject a name the other table already has. `suggestedCalendarUri` and `calendarUriTaken` use that same check. `CalendarHome::getChild` returns the calendar and never a subscription of the same name, and `HomeSync` keeps one child per name. Take the same `principals` row `UPDATE` before the read, and roll back and retry the next candidate when the unique key fails.

`DELETE` by the sharee removes only their instance. sabre's `deleteCalendar` already does that when access is not `1`. The owner's `DELETE` removes the calendar and, by the foreign key, every instance.

## Rules (from the plan)

- In Version 1.x shares are created only from the admin panel (Decision 2). Clients see and use shared collections. Sharing is not advertised to them.
- The user portal (Version 2) will let users share their own collections through the same `SharingService`, as the [Who does what](../apps/admin.md#who-does-what) table says. Nothing in the storage or the service ties a share to an admin, so the portal needs no new tables. It will need its own rules: a user may share only a collection they own (a received share cannot be shared on, which `SharingService` already refuses), the list of users offered as sharees must not leak other users' principals (Decision 5), the access choice must say that a read-write sharee changes the owner's items for everyone, and a record of who shared what may be wanted.
- No share with the owner. The sharee's principal and the book's `principaluri` are compared as strings.
- Individual users on this server only. No groups, no `mailto:` href (Decision 3).
- A disabled owner or sharee keeps the row. Disabling stops sign-in only (Settled question 1). Enabling does not recreate a share. The other party still syncs while the owner is disabled.

## Findings from the prototype

The prototype ran as a separate app on the lab server (SQLite, behind HTTPS). It was not merged. Its branch, tag, and image were removed after the findings below were recorded.

### Over DAV (6 November 2026, SQLite, scripted requests)

An owner with two contacts shared the default address book with a second user, first read-only and then read-write.

| Check | Result |
|---|---|
| The share is listed in the sharee's home as "Contacts (owner)", with the owner's contacts | Pass |
| Read-only: `current-user-privilege-set` | `read`, `read-acl`, `read-current-user-privilege-set`, `write-properties` only. No `write`, `bind`, `unbind` or `write-content` |
| Read-only: `PUT` new, `PUT` update (with and without `If-Match`), `DELETE`, `MOVE` out, `LOCK` | All **403**. The owner's contacts and sync token are unchanged |
| Read-only: `COPY` of a contact into the sharee's own book | 201. Copying needs only read on the source; the copy is the sharee's |
| Sync token of the share and of the owner's book | The same value (`4-9`). One change log serves both |
| Owner's principal and book path for the sharee | **404** |
| Rename the sharee's copy | Changes the sharee's name only. The owner's name and sync token stay the same |
| `PROPPATCH` of an unknown property | Accepted and kept under the sharee's own path; the owner reads 404. Not refused as the first version of this note said (the text above is corrected) |
| Read-write: privileges | `read`, `write` (with `bind`, `unbind`, `write-content`, `write-properties`). No `share` |
| Read-write: sharee creates a contact | 201. The owner reads it with the same `ETag`, and the owner's sync from before shows it |
| Read-write: owner creates a contact | The sharee's sync from its earlier token shows exactly that contact |
| Read-write: sharee updates with `If-Match`; the same stale `If-Match` again | 204, then **412**. The owner reads the new name |
| Read-write: the same `If-Match` sent through the owner's path and the sharee's path at the same moment (12 runs) | Every run: one **204** and one **412**. This is SQLite, which has one write lock for the whole database; the PostgreSQL case described under [Write lock](#write-lock) is not covered by this run |
| Read-write: delete by the sharee and by the owner | 204. Each side's sync reports both contacts as deleted |
| Read-write: `MOVE` out of the share into the sharee's own book | 201: the contact leaves the owner's book. That is `unbind`, which write includes |
| Leaving the share (`DELETE` of the shared book by the sharee) | 204. The owner's book, its 2 contacts and its sync token are unchanged. The path is 404 for the sharee, and the home lists only the sharee's own book |

The same owner and sharee, with the owner's default **calendar** shared through `calendarinstances` (sabre's own table and node, nothing overridden):

| Check | Result |
|---|---|
| The share is listed as "Owner Calendar (owner)"; `DAV:owner` is the sharee | Pass |
| Read-only: privileges | `read`, `read-free-busy`, `read-acl`, `read-current-user-privilege-set`, `write-properties`. No `write`, no `share` |
| Read-only: `PUT` new, `PUT` update, `DELETE`, `MOVE` out | All **403**. The owner's event is unchanged |
| Properties that would advertise sharing (`invite`, `share-access`, `shared-url`, `allowed-sharing-modes`, `share-resource-uri`), the resource type, and `OPTIONS` | None shown: 404 for each, a plain calendar resource type, no `resource-sharing` |
| Sync token of the share and of the owner's calendar | The same value |
| Owner's principal and calendar path for the sharee | **404** |
| Read-write: privileges | `read`, `write` (with `bind`, `unbind`, `write-content`, `write-properties`). No `share` |
| Read-write: the sharee creates an event; the owner reads it with the same `ETag`; the owner's sync from before shows it | Pass |
| Read-write: the owner creates an event; the sharee's sync from its earlier token shows exactly that event | Pass |
| Read-write: update with `If-Match`, then the same stale `If-Match` again | 204, then **412** |
| Read-write: the same `If-Match` through the owner's path and the sharee's path at the same moment (12 runs) | Every run: one **204** and one **412** (SQLite only, as for address books) |
| Read-write: delete by the sharee and by the owner | 204. Each side's sync reports both as deleted |
| **The sharee renames their copy** | The sharee's name changes and the owner's name does not, but **the owner's sync token moves by one** (`5-4` to `5-5`, and again on every rename). sabre's `updateCalendar` records a change. The design requires that a rename by the sharee does not touch the owner's token, so PR 23 must override it. Address books do not have the problem, because the prototype stores their rename itself |

The prototype's automated checks (`SharingSpikeTest`, 7 tests) pass on SQLite and PostgreSQL.

### With the clients (maintainer, 6 November 2026)

DAVx5 and Thunderbird on the same lab server (SQLite, over HTTPS), the sharee's account in each.

| Check | DAVx5 (Android) | Thunderbird |
|---|---|---|
| Read-only share: the book appears with the owner's contacts | Pass | Pass |
| Read-only share: the client treats it as read-only | Pass: marked read-only, a contact cannot be saved | Pass: a contact cannot be added |
| Read-write share, after the access was changed on the server: create a contact | Pass: created and synced after a refresh | **Needed a manual step**: the book stayed read-only until **Read-only** was unticked in its properties and the application restarted |
| Read-write share: a contact created by the sharee reaches the other client | Pass: Thunderbird shows the contact DAVx5 created | Pass once the book is editable |
| Read-write share: deleting a contact | Pass: the sharee's `DELETE` (204) removes it from the owner's book too | n/a |
| Leaving the share | **No Delete option for an address book**: DAVx5 can only switch syncing off for it | Removing a book only removes it on the device and sends nothing |
| The sharee's own name for the copy | n/a: no rename in DAVx5 | A rename stays on the device and changes nothing on the server or in DAVx5 |
| A shared calendar (events, tasks and notes) at read-only and read-write access, with three users | Pass in DAVx5 with jtx Board (Pixel 7 Pro) | Pass; an iPad also passed |

What this means for the design:

- **Thunderbird keeps its own Read-only flag for the address book.** The server answered correctly after the access changed (the sharee's privilege set held `write`, `bind`, `unbind` and `write-content`, and the contact DAVx5 created was in the owner's book), but Thunderbird did not take it over. The panel (PR 22) should say next to the access choice that a client may need the book set or added again after the access changes, and the guide's Troubleshooting should explain the Thunderbird step. Going from read-write to read-only is safe whatever the client remembers: the server refuses the write (403) and the client takes the server's version on its next sync.
- **Leaving a share from a client is syncing it off, not a `DELETE`.** Neither client sends a `DELETE` for the shared book, so the owner's data is never touched. The server side works for any client that does send it (found over DAV above). PR 25's rule stays as written, and the guide should say that a sharee stops a share on the device and an admin removes it in the panel.
- **A read-write sharee changes the owner's contacts for everyone.** Deleting or changing a contact in the share changes the owner's book and every other sharee's. The panel's access choice (**Read and write**) should say so.
- **The sharee's own name cannot be set from DAVx5 or Thunderbird,** so the rename is only reachable from other clients. The design keeps it, because it is cheap and sabre does the same for calendars.
