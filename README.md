# NexusDAV

![NexusDAV logo](docs/images/NexusDAV%20-%20logo.jpg)

**Self-hosted CalDAV / CardDAV / WebDAV server** with a dedicated admin panel and user portal.

Built on top of the [sabre/dav](https://sabre.io/) PHP library.

**How is it different?** Unlike Radicale, Baïkal and standalone sabre/dav, NexusDAV comes with a full admin panel built on Filament (and a user portal from Version 2); unlike Nextcloud, it stays focused on calendars, contacts, tasks, notes and files instead of being a full groupware suite.

## Status

Early stage — project skeleton. No Docker image has been published, and nothing has been tested against real clients. Features are roadmap items and will be implemented in stages.

NexusDAV is built for homelabs and small teams. It is not a mail, chat or office-document suite, and it is not a hosted multi-tenant service.

A few limits matter before you install:

- No user portal before Version 2. Until then, management goes through the admin panel.
- No email before Version 2.x. Emailed replies to invitations are never read.
- The database is chosen before the first start and cannot be switched afterwards in Version 1.
- Notes (VJOURNAL) show up only in a few clients.

Details, versions and the full roadmap are in the [guide](docs/guide.md).

## Quick start

> **Note:** The steps below describe the intended setup. Image name, ports and variable names are placeholders and may change.

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

   For PostgreSQL also set `DB_PASSWORD`. `APP_KEY` is generated on first start and stored on the data volume. Remove `ADMIN_PASSWORD` after the first login.

3. Start the server (pick one database; do not change it later):

   ```bash
   # PostgreSQL (recommended for production)
   docker compose -f compose.yaml -f compose.postgres.yaml up -d

   # or SQLite (simple deployments and development)
   docker compose -f compose.yaml -f compose.sqlite.yaml up -d
   ```

4. Open the admin panel at `http://localhost:8080/admin` (or your `APP_URL`) and create a user, a calendar and an address book. Events, tasks and notes are written by a CalDAV client, not in the admin panel.

5. Connect a client with that user's **app password**, not the account password. Client steps, DAV URLs and the reverse-proxy fallback are in the [guide](docs/guide.md#client-setup-and-compatibility).

Lost the admin password? Reset it from the container shell, as described in the [guide](docs/guide.md#resetting-the-admin-password). There is no web page that sets it.

## Documentation

- [Guide](docs/guide.md) — requirements, configuration, clients, backup, troubleshooting and roadmap
- [Architecture](docs/architecture.md) — how Laravel, Filament and sabre/dav fit together (Version 1)
- [Data model](docs/data-model.md) — tables and relationships
- [DAV endpoints](docs/dav-endpoints.md) — sabre/dav paths used in Version 1
- [Apps](docs/apps/README.md) — Calendar, Contacts, Tasks, Notes, Files and the admin panel

## Contributing

Bug reports, client compatibility reports, documentation fixes and code contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## Security

Please do not report security vulnerabilities through public issues. See [SECURITY.md](SECURITY.md) for how to report them privately.

## License

MIT. Copyright (c) 2026 HummerSoft. See [LICENSE](LICENSE).
