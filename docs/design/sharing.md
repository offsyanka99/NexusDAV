# Address book sharing design

Design note for the spike in [PR 20](../plans/implementation-plan-v1x.md#pr-20--spike-address-book-sharing-design-effort-medium). The decisions below follow the recommendations that keep address book sharing consistent with the existing calendar model and with principal isolation (Decision 5).

## Decisions

| Topic | Choice | Reason |
|---|---|---|
| Storage shape | **Instance table** (`addressbookinstances`) | Same model as `calendarinstances`. Stores the sharee's own display name, color, order and access level. Easier home sync token handling. |
| URI in the sharee's home | Owner's URI. Append `-<owner username>` only when the sharee already has a collection with that URI. | Avoids long URIs when there is no conflict. Matches common practice (Nextcloud, Baïkal). |
| Home listing and sync token | Shared collections appear as children of the sharee's address book home and affect the home's sync token. | Clients (especially DAVx5) otherwise miss new shares or do not notice when a share is removed. |
| `DAV:owner` | Reports the **sharee's** principal. The owner's principal stays unreachable (404) for the sharee. | Decision 5 isolation. Most clients do not require the real owner's principal URL to work with a shared collection. |

## `addressbookinstances` table

Mirrors the relevant parts of `calendarinstances` so the two sharing implementations stay similar.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, PK | |
| `principaluri` | varchar | The sharee's principal (`principals/users/<username>`). |
| `uri` | varchar | Unique within the sharee's home. Owner's URI, or owner's URI + `-<owner username>` on conflict. |
| `displayname` | varchar, nullable | Starts as "<name> (<owner's display name>)" (Decision 17). The sharee can rename it. |
| `description` | text, nullable | Copied from the owner at share time. |
| `access` | smallint | `2` = read-only, `3` = read-write. |
| `share_href` | varchar, nullable | The owner's principal URI. |
| `share_displayname` | varchar, nullable | Owner's display name at the time of sharing (for the initial display name). |
| `share_invitestatus` | smallint | `2` = accepted (shares are created already accepted). |
| `color` | varchar, nullable | Copied from the owner. |
| `order` | integer, nullable | Copied from the owner. |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### Indexes and constraints

- Unique on (`principaluri`, `uri`)
- Index on `share_href` (to find all shares of a given owner's collection)
- Foreign key or application-level cleanup: deleting the owner's address book or either user removes the instance rows (`DavDataCleaner`).

### Relation to the owner's address book

The owner's own address book continues to live in the existing address book table (or equivalent). An `addressbookinstances` row is created only for sharees. The owner does not get an extra instance row for their own collection.

## Notes

- Calendar shares continue to use the existing `calendarinstances` table (access `2` or `3`, `share_href` set).
- No client-initiated sharing. Shares are created only from the admin panel (Decision 2).
- A disabled owner or sharee keeps the share. Disabling only stops sign-in.
