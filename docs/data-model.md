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
| email | string | unique |
| password | string | nullable, Argon2id. Required for admins. Optional for non-admins. Accepted only by the Filament admin login |
| is_admin | boolean | default false |
| is_active | boolean | default true |
| timezone | string | nullable, falls back to server default |
| locale | string | nullable, falls back to server default |
| quota_bytes | bigint | nullable, 0 or null = unlimited |
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

## sabre/dav PDO backend tables

Not created yet. A custom sabre/dav principal backend that reads the Laravel `principals` table is added in PR3. Calendar and address book tables are not created in this version; PR3 adds Laravel migrations for them:

- `calendars`
- `calendarobjects` (events, tasks, notes)
- `addressbooks`
- `cards`
- `locks`
- and related tables required by the PDO backend

When a user is created in the admin panel:

1. Insert into `users`.
2. Insert into `principals` (`uri = principals/{username}`).

Deleting a user deletes the principal and app passwords. Home collections are created later (PR3 / PR4).

## Relationships

```
users 1—— 1 principals
users 1—— * app_passwords
principals 1—— * calendars      (via sabre/dav)
principals 1—— * addressbooks   (via sabre/dav)
calendars 1—— * calendarobjects (via sabre/dav)
addressbooks 1—— * cards         (via sabre/dav)
```

## Notes

- App passwords are shown once, as a single token `{public_id}.{secret}`. Only the SHA-256 hash of the secret is stored. Lookup uses `public_id`, then `hash_equals`.
- Revocation sets `revoked_at` and keeps the row. `expires_at` is optional.
- Quota, timezone and locale are present from Version 1 so later features do not require schema changes.
- The exact sabre/dav table names and columns follow the version of sabre/dav that is used (currently planned 4.7.x).
