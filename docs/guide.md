# NexusDAV guide

This is the operator and planning guide. The [README](../README.md) is the short project page. What each app includes is in [apps](apps/README.md).

Related documents:

- [Architecture](architecture.md) — components and request flow for Version 1
- [Data model](data-model.md) — tables and relationships
- [DAV endpoints](dav-endpoints.md) — sabre/dav paths

Early stage — project skeleton. No Docker image has been published, and nothing has been tested against real clients. Image name, ports and variable names below are placeholders and may change.

NexusDAV is built for homelabs and small teams that want calendars, contacts, tasks, notes and files without running a full groupware suite.

What it does not aim to be:

- A mail, chat or office-document suite (use Nextcloud for that).
- A hosted multi-tenant service.

Current limitations:

- No user portal before Version 2; all management goes through the admin panel.
- Notes (VJOURNAL) show up only in a few clients (see [Client compatibility](#client-compatibility)).
- No email before Version 2.x (see [Email](#email)). Emailed replies to invitations are not read or processed.
- The database cannot be switched after install in Version 1 (see [Database](#database)).

## Quick start

1. Get the Compose files and create your environment file:

   ```bash
   git clone https://github.com/offsyanka99/NexusDAV.git
   cd NexusDAV
   cp .env.example .env
   ```

2. Edit `.env` and set at least:

   ```dotenv
   APP_URL=https://dav.example.com
   ADMIN_EMAIL=admin@example.com
   ADMIN_PASSWORD=change-me-to-a-long-random-password
   ```

   For PostgreSQL also set `DB_PASSWORD`. `APP_KEY` is generated on first start and stored on the data volume.

3. Start the server (pick one database):

   ```bash
   # PostgreSQL (recommended for production)
   docker compose -f compose.yaml -f compose.postgres.yaml up -d

   # or SQLite (simple deployments and development)
   docker compose -f compose.yaml -f compose.sqlite.yaml up -d
   ```

   On first start the container runs database migrations and creates the first admin account from `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

4. Log in to the admin panel at `http://localhost:8080/admin` (or your `APP_URL`) with the admin credentials from step 2, then create your first user, calendar and address book. The admin panel creates users and collections. Events, tasks and notes are written by a CalDAV client, not in the admin panel.

5. Connect a client (DAVx5, Thunderbird, Apple Calendar / Contacts) using your server URL and the user's **app password**. See [Client setup and compatibility](#client-setup-and-compatibility). The app redirects `/.well-known/caldav` and `/.well-known/carddav` to `/dav/`. Some proxies never forward those paths; the fallback is in [Reverse proxy and `.well-known`](#reverse-proxy-and-well-known).

Remove `ADMIN_PASSWORD` from `.env` after the first login. If you lose access to the admin account later, see [Resetting the admin password](#resetting-the-admin-password).
