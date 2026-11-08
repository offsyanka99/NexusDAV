# Data model (Version 1)

Decisions:

- Use the **standard sabre/dav PDO backend** for calendars, address books, objects and cards.
- Keep **users** and **app passwords** in Laravel-managed tables.
- Use a separate **principals** table.
- Store only the **hash** of app passwords.
- Include fields that will be used later (quota, timezone, locale) so the schema does not need breaking changes.

## Laravel-managed tables

### users

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| username | string | unique |
| display_name | string | |
| email | string | unique, stored in lower case. A unique index on `lower(email)` (`users_email_lower_unique`) stops two accounts whose emails differ only in case, because DAV and the admin login match the email without case |
| password | string | nullable, Argon2id. Required for admins. Optional for non-admins. Accepted only by the Filament admin login |
| is_admin | boolean | default false |
| is_active | boolean | default true |
| timezone | string | nullable, falls back to server default |
| locale | string | nullable, falls back to server default. The admin panel language since 0.1.2; an unknown code falls back too |
| quota_bytes | bigint | nullable, 0 or null = unlimited |
| collections_provisioned | boolean | default false. Set when the default calendar and address book are inserted. A later prepare does not insert those URIs again |
| created_at | timestamp | |
| updated_at | timestamp | |

### app_passwords

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | bigint | FK → users, cascade delete |
| name | string | human-readable label (e.g. "Pixel 7") |
| public_id | string | unique, short, used for lookup |
| token_hash | string | SHA-256 hex of the secret only. The plaintext token is not stored |
| revoked_at | timestamp | nullable. Set on revoke; the row is kept |
| expires_at | timestamp | nullable |
| last_used_at | timestamp | nullable. Updated at most once per 60 seconds |
| created_at | timestamp | |
| updated_at | timestamp | |

### principals

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | bigint | FK → users, unique, cascade delete |
| uri | string | unique, `principals/{username}` |
| display_name | string | |
| email | string | |
| created_at | timestamp | |
| updated_at | timestamp | |

### settings

Values saved on the admin panel's Settings and Configuration pages. Boot settings (database, `APP_URL`, the first admin) stay in the environment. `SettingsService` puts the saved values into config at every boot, after config (cached or not) is loaded. A setting whose environment variable is set ignores its row. The saved values are cached in Laravel's file cache (`NEXUSDAV_SETTINGS_CACHE_STORE`, default `file`), so a DAV request does not read this table; saving clears that cache.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| key | string(100) | unique, for example `caldav`, `files`, `files_max_upload_mb` or `session_lifetime` |
| value | text | JSON |
| created_at | timestamp | |
| updated_at | timestamp | |

### diagnostic_events

Rejected logins (`kind` `auth_failed`) and DAV answers with status 400 or higher other than 401 (`kind` `http_error`, source `dav`, reason the HTTP status text). A DAV request with no `Authorization` header is not stored. There is no foreign key: the username may not match an account.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| occurred_at | timestamp | Time of the first event in this repeat window |
| source | string | `admin` or `dav` |
| kind | string | `auth_failed` or `http_error` |
| ip | string | Throttle address. The immediate peer, unless that peer is an explicitly configured proxy, in which case it is the forwarded client. A catch-all trusted-proxy list does not use `X-Forwarded-For`. Empty when the request has no peer |
| username | string | Canonical username when the account exists, otherwise the attempted login |
| public_id | string | nullable. App password public id only. The secret is not stored |
| request_id | string(64) | nullable. Request ID of the row's first event, the same as its `X-Request-Id` header and log lines. Null when written outside a request |
| method | string | HTTP method |
| path | string | Path without the query string |
| status | smallint | 401 for a rejected DAV login, 422 for a rejected admin login, the answer's status for `http_error` |
| reason | string | DAV: unknown user, bad secret, revoked, expired, user disabled, or main password used. Admin: unknown user, bad password, or panel refused. `many usernames` on the summary row (see below). Not returned to the client |
| repeat_count | integer | How many matching failures share this row |
| bucket | integer | Epoch time divided by 60. The same source, kind, IP, username, path, reason, and bucket are one row |

One address gets at most 10 rows per bucket (`diagnostics.max_rows_per_address`). Further failures from it in that bucket are counted on one summary row with username and path `*` and reason `many usernames`, and only that row's first failure is logged. `http_error` rows have their own limit per address and their own summary row, with reason `many requests`. They get no log line of their own; the request's log line has the same request ID. Repeats in the same minute share a row. The minute is an epoch bucket, so two failures a few seconds apart can be two rows when they cross a bucket boundary. Rows older than 30 days (`config/nexusdav.php`, `diagnostics.retention_days`) are deleted when a new row is written, and at most once per hour. Nothing schedules that delete.

## sabre/dav storage tables

Laravel migrations create these tables. sabre/dav 4.7.1 only ships SQL examples and creates nothing itself. The standard PDO backends read and write them through Laravel's PDO connection.

sabre's own `principals`, `groupmembers` and `users` tables are **not** created. Identity stays in the Laravel tables above. `App\Services\Dav\PrincipalBackend` reads `principals`.

| Table | Purpose |
|-------|---------|
| `calendars` | One row per calendar. `components` lists `VEVENT`, `VTODO` and `VJOURNAL` |
| `calendarinstances` | A user's view of a calendar. `principaluri` is `principals/{username}`. `access` 1 is the owner; 2 (read) and 3 (read and write) are a calendar shared with that user, with `share_href` the same principal and `share_invitestatus` 2 (accepted) |
| `calendarobjects` | Events, tasks and notes. `calendardata` is the iCalendar body |
| `calendarchanges` | Sync-collection change log for a calendar |
| `calendarsubscriptions` | Subscribed calendar feeds |
| `schedulingobjects` | Scheduling inbox objects. Unused in Version 1, present so the PDO backend can load |
| `addressbooks` | One row per address book. `principaluri` is `principals/{username}`, the owner |
| `addressbookinstances` | An address book shared with a user, one row per sharee (not a sabre table). `addressbookid` is the owner's book, `principaluri` and `share_href` the sharee, `access` 2 or 3, `uri` and `displayname` the sharee's own. Cards and the change log stay on the book. See [sharing.md](design/sharing.md#addressbookinstances-table) |
| `cards` | Contacts. `carddata` is the vCard body |
| `addressbookchanges` | Sync-collection change log for an address book |
| `propertystorage` | Dead WebDAV properties, keyed by `path` and `name` |
| `locks` | WebDAV locks, keyed by `uri` |

Files (0.4.0) use `propertystorage` and `locks` too, with the path `files/<username>/<path>`; deleting a user removes them. The file content is not in the database: it is on disk, at `/data/files/<username>/` (config `nexusdav.files.path`). Files needed no migration.

Column types follow sabre's SQLite and PostgreSQL scripts for the installed 4.7.1 schema, with four deliberate differences:

- `calendardata`, `carddata` and `propertystorage.value` are `blob` on SQLite and `bytea` on PostgreSQL. PDO may return `bytea` as a stream or a `\x` hex literal. The backends turn that back into the original string before a client reads it.
- `calendars.components` is `varchar(255)`. sabre's PostgreSQL script uses `varchar(21)`, which cannot store `VEVENT,VTODO,VJOURNAL`.
- `calendarchanges` and `addressbookchanges` have `created_at`, filled by the database when sabre inserts the row. `calendars.synctoken_min` and `addressbooks.synctoken_min` are the oldest token number that collection can still answer: one past the newest change number that was deleted. Rows older than the sync-history setting are deleted on a later DAV write, at most once an hour, and a token below that number gets `valid-sync-token`.
- Unix-time columns are `bigint` on PostgreSQL: `calendarobjects.firstoccurence`, `lastoccurence` and `lastmodified`, `lastmodified` on `cards`, `calendarsubscriptions` and `schedulingobjects`, and `locks.created`. sabre's script uses 32-bit `integer`, which rejects any event after 19 January 2038. SQLite integers are already 64-bit. `locks.owner` is `text` on PostgreSQL, because sabre stores the client's LOCK owner element as sent.
- `calendarinstances` is unique on (`calendarid`, `share_href`) on both engines: one share per sharee and calendar. sabre's PostgreSQL script has (`principaluri`, `share_href`) instead, which allowed one share per person on the whole server; a migration replaced it.
- Child rows have foreign keys. Deleting a calendar deletes its instances, objects and changes. Deleting an address book deletes its cards, changes and shares. A principal is stored as a URI string, so those rows are not a foreign key. `DavDataCleaner` deletes them when the user is deleted, including locks and property storage under `principals/{username}`, `calendars/{username}` and `addressbooks/{username}`.

When a user is created in the admin panel:

1. Insert into `users`.
2. Insert into `principals` (`uri = principals/{username}`).
3. Insert a default calendar and a default address book, both with URI `default`, and set `users.collections_provisioned`. The calendar display name is `Calendar` and its color is `#1A73E8`. The address book display name is `Contacts`. `calendars.components` is `VEVENT`, plus `VTODO` when tasks are on and `VJOURNAL` when notes are on. The rows use `insertOrIgnore` on `(principaluri, uri)`.

The calendar home (`calendars/{username}`) and the address book home (`addressbooks/{username}`) are not rows. sabre serves them from the principal. Each home's names come from two tables, which must not share a name: `calendarinstances` and `calendarsubscriptions`, and `addressbooks` and `addressbookinstances` (`HomeNamespace`). The admin panel lists collections through Eloquent models and creates, updates, and deletes them through the sabre PDO backends. Deleting a collection also removes its property storage and locks, the same path `DavDataCleaner` uses for the user's home. Deleting the user deletes the principal, which removes the homes, and also deletes the app passwords and the user's sabre rows: their collections with every share of them, and the shares they received. Deleting a collection removes its shares too, with the locks and properties each sharee kept under their own path. The panel lists only owned calendars (`access` 1). `nexusdav:prepare` claims `collections_provisioned` once for users who have never had the defaults, including the first admin. Deleting the default calendar or address book stays deleted across a later prepare.

## Relationships

```
users 1—— 1 principals
users 1—— * app_passwords
principals 1—— * calendarinstances   (principaluri)
principals 1—— * addressbooks        (principaluri, the owner)
principals 1—— * addressbookinstances (principaluri, the sharee)
calendars 1—— * calendarinstances
calendars 1—— * calendarobjects
calendars 1—— * calendarchanges
addressbooks 1—— * cards
addressbooks 1—— * addressbookchanges
addressbooks 1—— * addressbookinstances
```

## Notes

- App passwords are shown once, as a single token `{public_id}.{secret}`. Only the SHA-256 hash of the secret is stored. Lookup uses `public_id`, then `hash_equals`.
- Revocation sets `revoked_at` and keeps the row. `expires_at` is optional.
- Quota, timezone and locale are present from Version 1 so later features do not require schema changes.
- The sabre table names and columns match sabre/dav 4.7.1 except for the differences listed above.
- New calendars include `VTODO` when tasks are on and `VJOURNAL` when notes are on (`config/nexusdav.php`). Both are on by default. `VEVENT` is always included.
