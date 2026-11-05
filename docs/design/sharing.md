# Address book sharing design

Design note for the spike in [PR 20](../plans/implementation-plan-v1x.md#pr-20--spike-address-book-sharing-design-effort-medium). The decisions below keep address book sharing consistent with the calendar model (sabre's `calendarinstances`) and with principal isolation (Decision 5).

## Decisions

| Topic | Choice | Reason |
|---|---|---|
| Storage shape | **Instance table** (`addressbookinstances`), one row per sharee. The owner's address book stays a row in `addressbooks`, with no instance row of its own | Same model as `calendarinstances`, so both kinds of share work the same way. Each row holds the sharee's own display name and access level. A plain share table would need the same columns plus a separate place for the sharee's display name, so it has no advantage |
| URI in the sharee's home | The owner's URI. Only when the sharee already uses that URI, `-<owner username>` is added; if that is taken too, `-2`, `-3` and so on | Short URIs when there is no clash. Usernames match `[a-z0-9][a-z0-9_-]*`, so the result is still a valid collection URI. The base URI is shortened when needed so the result fits the 200-character limit |
| Home listing and sync token | Shared address books are listed as children of the sharee's address book home | The home's sync token is built from its child list (`HomeSync`), so a new or removed share changes it with no extra code, and DAVx5 notices it on its next refresh |
| Sync token of a shared address book | The owner's address book token, `<owner's address book id>-<number>` | Changes are recorded once, in `addressbookchanges` of the owner's address book. A write by a read-write sharee is a change for the owner and every other sharee. A token from before the share was removed and given again gets 403 `valid-sync-token`, like any stale token |
| `DAV:owner` | The **sharee's** principal | This is what sabre's calendar sharing does (`Calendar::getOwner()` returns the instance's `principaluri`). The owner's principal stays unreachable (404) for the sharee (Decision 5), so naming it would only give clients a link that fails |
| Privileges | Built from the access level, not from `DAV:owner`: read-only gets `{DAV:}read`; read-write also gets `{DAV:}write`. Neither gets `{DAV:}share` or the right to change the address book's properties for the owner | The sharee is reported as owner, so an ACL that grants everything to the owner would let a read-only sharee write. sabre's `SharedCalendar::getACL()` follows the access level in the same way |
| Leaving a share | A `DELETE` of the shared address book by the sharee removes only their instance row. The owner's address book and cards stay | Same as calendars (Settled question 2) |
| Renaming | A `PROPPATCH` of `{DAV:}displayname` by the sharee changes their instance row only | Decision 17: the owner's name does not change |

## `addressbookinstances` table

Mirrors the parts of `calendarinstances` that apply to address books. Address books have no color or order: the `addressbooks` table has no such columns, and sabre's CardDAV backend stores only the display name and the description.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, PK | |
| `addressbookid` | bigint, foreign key to `addressbooks.id`, cascade on delete | The shared address book. Its `principaluri` is the owner |
| `principaluri` | varchar(255) | The sharee's principal, `principals/<username>` |
| `uri` | varchar(200) | The URI in the sharee's home (see Decisions) |
| `displayname` | varchar(255), nullable | Starts as "<name> (<owner's display name>)" (Decision 17). The sharee can rename it |
| `description` | text, nullable | Copied from the owner's address book when the share is created |
| `access` | smallint | `2` read-only, `3` read-write (sabre's `ACCESS_READ` and `ACCESS_READWRITE`) |
| `share_href` | varchar(100) | The sharee: `principals/<username>`, as in `calendarinstances` |
| `share_displayname` | varchar(100), nullable | The sharee's display name, as in `calendarinstances` |
| `share_invitestatus` | smallint, default `2` | `2` accepted (`INVITE_ACCEPTED`). Shares are created already accepted; there are no invitations |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

The owner's display name for the initial name is read from the owner's user record when the share is created; it is not stored on the row.

### Indexes and constraints

- Unique on (`principaluri`, `uri`).
- Unique on (`addressbookid`, `principaluri`): one share per sharee and address book.
- Index on `addressbookid`, to list the sharees of an address book.
- The two tables do not share a unique index, so the URI rule spans both in the application: a new share avoids the sharee's own address books, and a new address book of the sharee (`MKCOL` or the admin panel) is refused when one of their shares uses the URI.

### Cleanup

- Deleting the owner's address book, from the panel or over DAV: the foreign key removes its shares.
- Deleting a user: their own address books go, and with them the shares of those address books. `DavDataCleaner` also removes the rows where the user is the sharee.

### Listing every instance

PR 21's "list every instance of a collection" (M5 needs it) returns the owner's row in `addressbooks` and the rows in `addressbookinstances` for that `addressbookid`. Calendars return all their `calendarinstances` rows, because there the owner has an instance row (access `1`).

## Calendars

Calendar shares use the existing `calendarinstances` table with sabre's columns as they are: access `2` or `3`, `share_href` the sharee's principal, `share_invitestatus` `2`. Their color and order are copied from the owner's instance. sabre's sharing plugin is not loaded, and sharing requests from clients are refused (Decision 2).

## Rules (from the plan)

- Shares are created only from the admin panel (Decision 2). Clients see and use shared collections; sharing is not advertised to them.
- No share with the owner.
- A disabled owner or sharee keeps the share. Disabling stops sign-in only (Settled question 1).

## Findings from the prototype

To be filled in from the spike run:

- Read-only shared address book in DAVx5 and Thunderbird: ⬜
- Read-write shared address book in DAVx5 and Thunderbird (create, edit and delete a contact; the owner sees the change): ⬜
- Leaving the share from the client: ⬜
- Calendar side with `calendarinstances`: the sharee's instance appears, `current-user-privilege-set` matches the access level, and the owner's principal answers 404: ⬜
