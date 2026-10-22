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
| password | string | Argon2id |
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
| user_id | bigint | FK → users |
| name | string | human-readable label (e.g. "Pixel 7") |
| token_hash | string | only the hash is stored |
| last_used_at | timestamp | nullable |
| created_at | timestamp | |

### principals

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | bigint | FK → users, unique |
| uri | string | unique, e.g. `principals/username` |
| display_name | string | |
| email | string | |
| created_at | timestamp | |
| updated_at | timestamp | |

## sabre/dav PDO backend tables

These tables are created and managed by sabre/dav (not by Laravel migrations):

- `calendars`
- `calendarobjects` (events, tasks, notes)
- `addressbooks`
- `cards`
- `locks`
- and related tables required by the PDO backend

When a user is created in the admin panel:

1. Insert into `users`.
2. Insert into `principals`.
3. Call sabre/dav to create the principal and home collections (calendars and address books).

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

- App passwords are shown to the user only once (at creation). Only the hash is stored.
- Quota, timezone and locale are present from Version 1 so later features do not require schema changes.
- The exact sabre/dav table names and columns follow the version of sabre/dav that is used (currently planned 4.7.x).
