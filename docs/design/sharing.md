# Address book sharing design

Design note for the spike in [PR 20](../plans/implementation-plan-v1x.md#pr-20--spike-address-book-sharing-design-effort-medium). The decisions below keep address book sharing consistent with the calendar model (sabre's `calendarinstances`) and with principal isolation (Decision 5). sabre/dav 4.7.1 has calendar sharing and no address book sharing plugin. CardDAV stays on sabre's `addressbooks` table. Shares are a separate instance row, not a second ACL store.

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

`PROPPATCH` of `{DAV:}displayname` and `{urn:ietf:params:xml:ns:carddav}addressbook-description` writes the instance. Any other property is refused. `DELETE` deletes the instance row only.

The collection's `sync-collection` on a share accepts only a token whose id is this `addressbooks.id`. A plain number is 403 `valid-sync-token`. Decision 20 still accepts a plain number on an owned book: `SyncToken::resolve` treats `^[1-9][0-9]{0,9}$` as a change number with no collection id. A share can reuse a URI the sharee's client already synced. `HomeSync` then reports that name as modified, and the client sends the old plain token. Reading the owner's change log from that number skips deletes the client still has and returns the other book's history. An id-scoped token for a different book is already rejected. A token from before the share was removed and given again is still the owner's id-scoped token: it is accepted while it is inside the kept history. Unshare does not bump the owner's token. The sharee's client drops the book because the home sync reports the child deleted, then added if it is shared again. The same plain-number refusal applies to a shared calendar, whose id is `calendars.id`. Owned calendars keep Decision 20.

A rename does not change the owner's token, so `HomeSync` does not report the child as modified. Another device of the sharee sees the new name on its next `PROPFIND`. That is not a reason to give the sharee a separate collection token.

When Sharing is off, instance rows stay in the table and are left out of `getAddressBooksForUser`. Their URL answers 404. That switch is PR 23; this note does not add it.

### Cleanup

- Deleting the owner's address book, from the panel or over DAV, deletes the `addressbooks` row. The foreign key removes its shares.
- Deleting a user: their own address books go, and with them the shares of those books. `DavDataCleaner` also deletes `addressbookinstances` where `principaluri` is the deleted user, the same way it already deletes `calendarinstances` the user received.
- Locks and dead properties for the share live under the sharee's path `addressbooks/<sharee username>/<instance uri>`. Removing the instance removes that path. It does not remove the owner's cards.

### Listing every instance

PR 21's "list every instance of a collection" (M5 needs it) returns the owner's row in `addressbooks` and the rows in `addressbookinstances` for that `addressbookid`. Calendars return all their `calendarinstances` rows, because there the owner has an instance row (access `1`).

## Privileges

Decision 4: sabre's `DAVACL` enforces the access level. There is no `ACL` method, no `setACL`, and no custom privilege. `getSupportedPrivilegeSet()` stays null so sabre's default set is used.

The only principal in the ACL is the sharee's principal, the same URL as `DAV:owner`. Nothing is granted to `{DAV:}owner`, `{DAV:}authenticated`, `{DAV:}all`, or a calendar-proxy principal.

- Access `2`: `{DAV:}read` on the collection and on each card. `{DAV:}write-properties` on the collection only, so the sharee can change their own display name and description. Not `{DAV:}write`.
- Access `3`: `{DAV:}read` and `{DAV:}write` on the collection and on each card. `{DAV:}write-properties` is already part of `{DAV:}write`.

The ACL entries are not `{DAV:}share`, `{DAV:}bind`, or `{DAV:}unbind`. sabre expands `{DAV:}write` on a collection to include bind and unbind, and creating or deleting a card checks those. Granting `{DAV:}write` is how a read-write sharee saves a contact. It does not let them share the book on. A read-only sharee does not get `{DAV:}write`, so a `PUT` or a `DELETE` of a card is 403.

`{DAV:}all` is never granted. On an address book that grant is the bug: `ACLTrait::getACL()` gives `{DAV:}all` to `{DAV:}owner`, and the sharee is the owner property.

`CalendarBackend` extends sabre's PDO backend, which implements `SharingSupport`, so `CalendarHome` already builds a `SharedCalendar` for every calendar. That node's `getACL()` grants `{DAV:}share` to an owner and names `calendar-proxy-read` and `calendar-proxy-write`. Do not leave those ACEs in place. Do not stop implementing `SharingSupport` to hide them: the home would then build a `Calendar`, and `Calendar::getACL()` grants write unless `{http://sabredav.org/ns}read-only` is set. The PDO backend sets a different key, `read-only`, so a read-only sharee would be given write. The node used for a share reads `share-access` (the instance's `access`) and applies the list above. It does not grant read-free-busy to `{DAV:}authenticated`. An owner's calendar (access `1`) keeps read and write for their own principal, and the read-free-busy ACE it already has. It does not get `{DAV:}share` or a calendar-proxy ACE.

### What is not advertised

Decision 2. Shares are created only in the admin panel.

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

- Shares are created only from the admin panel (Decision 2). Clients see and use shared collections. Sharing is not advertised to them.
- No share with the owner. The sharee's principal and the book's `principaluri` are compared as strings.
- Individual users on this server only. No groups, no `mailto:` href (Decision 3).
- A disabled owner or sharee keeps the row. Disabling stops sign-in only (Settled question 1). Enabling does not recreate a share. The other party still syncs while the owner is disabled.

## Findings from the prototype

The hand test is the maintainer's (Decision 15) and was not run in this spike. Nothing below was tried with a client.

- Read-only shared address book in DAVx5 and Thunderbird: ⬜
- Read-write shared address book in DAVx5 and Thunderbird (create, edit and delete a contact; the owner sees the change): ⬜
- Leaving the share from the client: ⬜
- Calendar side with `calendarinstances`: the sharee's instance appears, `current-user-privilege-set` matches the access level, and the owner's principal answers 404: ⬜
