# NexusDAV guide

This is the operator and planning guide. The [README](../README.md) is the short project page. What each app includes is in [apps](apps/README.md).

Related documents:

- [Architecture](architecture.md) — components and request flow for Version 1
- [Data model](data-model.md) — tables and relationships
- [DAV endpoints](dav-endpoints.md) — sabre/dav paths
- [Client checklist](testing/client-checklist.md) — the hand test with real clients before a release
- [Implementation plan (v1)](plans/implementation-plan-v1.md) — step-by-step PRs with effort levels
- [Implementation plan (v1.x)](plans/implementation-plan-v1x.md) — milestones and PRs for the Version 1.x features
- [Plan for Version 2 and later](plans/version-2-and-later.md) — features for 2.0, 2.x and beyond

Version 1 is built. Releases are published as `ghcr.io/offsyanka99/nexusdav` for amd64 and arm64. The current release is `1.6.0` (first release `0.1.0`). "Version 1" and "Version 1.x" in these documents name the feature plan, not release numbers. 1.6.0 follows 0.5.0 and adds the export and import of server settings and a factory reset (Danger Zone on the Configuration page). 0.5.0 completed the five milestones of the Version 1.x plan: the admin panel in English and Russian (M1), reliable sync (M2), sharing of calendars and address books (M3), Files, WebDAV file storage (M4), and WebDAV-Push with a queue worker (M5). The server passes automated sync tests with the vdirsyncer and python caldav clients, and 0.1.1 was tested by hand with DAVx5 and Thunderbird; Apple clients have not been tried by the project yet (see [Client compatibility](#client-compatibility)).

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

1. Get the Compose files and create your environment file. The Compose files pull the published image, `ghcr.io/offsyanka99/nexusdav:1.6.0`:

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

   For [WebDAV-Push](#webdav-push), also start the queue worker: add `COMPOSE_PROFILES=worker` to `.env` before `up -d` (see [Queue worker](#queue-worker-optional)). Nothing else needs it.

   To build the image from the checkout instead, add `-f compose.build.yaml` as the last file and `--build`, for example `docker compose -f compose.yaml -f compose.sqlite.yaml -f compose.build.yaml up -d --build`.

4. Log in to the admin panel at `http://localhost:8080/admin` (or your `APP_URL`) with the admin credentials from step 2, then create your first user. The user gets a default calendar and address book; add more from the user page. The admin panel creates users and collections. Events, tasks and notes are written by a CalDAV client, not in the admin panel.

5. Connect a client (DAVx5, Thunderbird, Apple Calendar / Contacts) using your server URL and the user's **app password**. See [Client setup and compatibility](#client-setup-and-compatibility). The app redirects `/.well-known/caldav` and `/.well-known/carddav` to `/dav/`. Some proxies never forward those paths; the fallback is in [Reverse proxy and `.well-known`](#reverse-proxy-and-well-known).

Remove `ADMIN_PASSWORD` from `.env` after the first login. If you lose access to the admin account later, see [Resetting the admin password](#resetting-the-admin-password).

### Installing on TrueNAS SCALE

TrueNAS SCALE 24.10 (Electric Eel) and later run Apps on Docker, so a Docker Compose file can be installed directly as a **Custom App**:

1. Create a dataset for NexusDAV's data: **Storage > Datasets > Add Dataset**, for example `tank/apps/nexusdav`. With PostgreSQL, also create a second dataset for its data, for example `tank/apps/nexusdav/postgres`. Keep both on a pool local to this machine. SQLite needs a local filesystem; a dataset mounted from another machine over the network does not qualify (see [Database](#database)).
2. Copy [docs/truenas/custom-app.sqlite.yaml](truenas/custom-app.sqlite.yaml) (good for a single NAS) or [docs/truenas/custom-app.postgres.yaml](truenas/custom-app.postgres.yaml) (recommended for more than a couple of users).
3. Edit every line marked `CHANGE ME`: the dataset path(s) from step 1, `APP_URL`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, and the host port if `8080` is already used by another app. The file has no separate place for environment variables or a `.env` file, so values are written directly into it.
4. For [WebDAV-Push](#webdav-push), also remove the `# ` at the start of each line of the worker block at the end of the file, and give it the same dataset path, image tag and (PostgreSQL) `DB_PASSWORD` as the app. TrueNAS cannot pick Compose profiles, so the block is commented out by default.
5. **Apps > Discover Apps**, then **Custom App** (top right) **> Install via YAML**. Paste the edited file and install.
6. Continue from step 4 of [Quick start](#quick-start).

`PUID` and `PGID` default to `568`, the UID TrueNAS SCALE apps conventionally run as, so the dataset's ownership matches what the container expects. Each container is capped at 1 CPU. NexusDAV is limited to 768 MB, and PostgreSQL to 1 GB. Those are ceilings; raise them in the YAML if the app is stopped for running out of memory during a large sync. Put it behind an HTTPS reverse proxy before exposing it outside your network — Nginx Proxy Manager and Caddy are themselves TrueNAS SCALE apps, or use one of the files in [docs/proxy](proxy/). To upload large files to Files, the proxy and the dataset need the settings in [Large file uploads](#large-file-uploads-files).

With Files on, every user's files are stored in `/data/files` on the same dataset as the database (SQLite) or the app data (PostgreSQL), so size that dataset for them, and set a quota on it if the pool is shared. To keep the files on another dataset, add a second volume in the YAML that mounts it at `/data/files`; the container takes ownership of it at start.

## Requirements

Running with Docker needs only Docker Engine with the Compose plugin. The table below applies to development and to anyone running the code without Docker.

> Versions listed below reflect the planned target at the time of writing and will be confirmed or adjusted as development progresses.

| Component | Version | Notes |
|---|---|---|
| PHP | **8.3 – 8.5**, 8.4 recommended | Laravel 13 requires 8.3+. sabre/dav 4.7 is officially tested up to PHP 8.4, so 8.4 is the safe target until 8.5 is verified. Use **8.4.27+** (or equivalent patched version) to include the fixes for CVE-2026-109618 and CVE-2026-109619. |
| Laravel | **13.x** | Supports PHP 8.3 – 8.5. |
| Filament | **5.x** | Requires PHP 8.2+, Laravel 11.28+, Livewire 4, Tailwind CSS 4.1+. Fully compatible with Laravel 13. |
| Livewire | **4.x** (4.4.2+) | Pulled in by Filament 5. Also used by the user portal. |
| Tailwind CSS | **4.1+** | Required by Filament 5. Built with Vite. |
| sabre/dav | **4.7.x** (4.7.1) | Requires sabre/http ^5.0.5, sabre/event ^5.0, sabre/vobject ^4.2.1. |
| PostgreSQL | **17 or 18** | Primary database. PostgreSQL 14 reaches end of life on 12 Nov 2026. |
| SQLite | 3.x | For simple deployments and development. |
| Node.js | Current LTS | Frontend build only (Vite, Tailwind). |
| Composer | 2.x | |

**Security note (October 2026):** PHP 8.4.27, 8.3.36, 8.2.35 and 8.5.12 fix two critical unauthenticated RCE vulnerabilities (CVE-2026-109618, CVE-2026-109619). The Docker image must use a patched PHP version.

**Required PHP extensions:** `ctype`, `curl`, `date`, `dom`, `fileinfo`, `filter`, `gmp`, `hash`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcntl`, `pcre`, `pdo`, `pdo_pgsql`, `pdo_sqlite`, `session`, `simplexml`, `tokenizer`, `xml`, `zip`. `pcntl` lets the queue worker stop gracefully; `gmp` serves the Web Push library (without it the library prints a notice on every use). The image has both.

`intl` is required by Filament. PHP must also be built with Argon2 support (`password_hash` with `PASSWORD_ARGON2ID`); the official PHP Docker images include it.

**WebDAV-Push:** sabre/dav does not provide this. Version 1.x implements the bitfire draft [bitfireAT/webdav-push](https://github.com/bitfireAT/webdav-push) at commit `611abe2e8a0cdd7570cb936844b6588f7145b27d` (`draft-bitfire-webdav-push-00`). NexusDAV stays on that commit until the project publishes a release, then switches to the release. It is switched on or off in the admin panel, and its messages are sent by the queue worker (see [WebDAV-Push](#webdav-push)).

## Tech stack

- **Core**: PHP + [sabre/dav](https://github.com/sabre-io/dav)
- **Application**: [Laravel](https://laravel.com/)
- **Admin panel**: [Filament](https://filamentphp.com/) (Livewire + Tailwind CSS)
- **User portal**: Laravel + Livewire + Tailwind CSS
- **Frontend styling**: Tailwind CSS
- **Deployment**: Docker Compose (base file + PostgreSQL / SQLite overrides)

See [Requirements](#requirements) for the exact versions.

**Use the latest stable versions** of all libraries and dependencies when possible. WebDAV-Push is the exception: the bitfire draft commit named under [Requirements](#requirements), until that project publishes a release.

## Configuration

Docker Compose is split into a shared base file and one override per database, so the database is already defined at install time:

```
compose.yaml            # application (pinned image), shared volumes, ports; the optional worker
compose.postgres.yaml   # adds the postgres service and DB_* variables
compose.sqlite.yaml     # sets DB_CONNECTION=sqlite and the database file path
compose.build.yaml      # optional, last: builds the image from this checkout
```

Reverse proxy overrides (Caddy, Traefik) are in [docs/proxy](proxy/); see [Reverse proxy and `.well-known`](#reverse-proxy-and-well-known).

### Queue worker (optional)

A second container, `nexusdav-worker`, runs the same image in worker mode: it takes jobs from the database queue and runs them, so the app never waits for them. [WebDAV-Push](#webdav-push) sends its messages through it; nothing else needs it, so without push it can stay off.

- **Start it:** add `COMPOSE_PROFILES=worker` to `.env` and run `docker compose … up -d` as usual, or add `--profile worker` to the command. Without the profile it is not started.
- **What it uses:** the same environment, the same `/data` volume and the same database as the app (the database file adds its variables to the worker too). With SQLite both containers use the same file; WAL mode allows that on one host. It has no port, runs no migrations and creates no admin, and it starts once the app is healthy.
- **Is it running?** `docker compose exec nexusdav php artisan nexusdav:worker-status` prints "Worker running", "Worker stale" (no heartbeat for five minutes) or "No worker has been seen". `--probe` also sends a test job and waits for the worker to run it. The worker writes its heartbeat to the database about once a minute, and the worker container's health check uses the same command.
- **Restarts:** `queue:work` stops by itself after an hour, or above 128 MB, and starts again inside the container. On `docker compose stop` the job that is running finishes first.
- **TrueNAS:** the custom app files have a worker block that is commented out; remove the `# ` from its lines to use it. To stop only the worker, run `docker stop <worker container>` in the TrueNAS shell (`docker ps` shows its name); it starts again when the app is restarted or updated.
- **In the panel:** the Dashboard's Services card shows whether it runs, Needs attention warns when push is on and it does not, and Diagnostics shows its last heartbeat and the jobs waiting.

See [Quick start](#quick-start) for the exact commands. PostgreSQL is recommended for production; SQLite is intended for simple deployments and development.

The `.env` file contains only what the application needs to boot: database connection, `APP_URL` and the initial admin account. `APP_KEY` is not in `.env`. It is generated on first start and stored on the data volume. All other server settings are configured in the admin panel (Settings and Configuration pages) and stored in the database. If a setting's environment variable is set, that value wins and the field is read-only in the panel, with a lock and the variable's name. See [Settings in the admin panel](#settings-in-the-admin-panel).

### Environment variables

The variables used in this guide are listed in `.env.example` with safe placeholder values.

| Variable | Required | Default | Secret | Description |
|---|---|---|---|---|
| `APP_URL` | Yes | none | No | Public HTTPS address of the server. Must match what clients and the reverse proxy use. |
| `ADMIN_EMAIL` | First start | none | No | Email of the first admin account. |
| `ADMIN_PASSWORD` | First start | none | Yes | Password of the first admin account, at least 12 characters. A shorter value stops the first start. Remove it after the first login. |
| `DB_CONNECTION` | Set by the compose override | set by override | No | `pgsql` or `sqlite`. Do not change after install. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` | PostgreSQL | see `.env.example` | No | SQLite uses `DB_DATABASE` as the file path. |
| `DB_PASSWORD` | PostgreSQL only | none | Yes | Database password. |
| `APP_KEY` | No | generated | Yes | Not set in `.env`. Generated on first start and stored at `/data/app.key`. |
| `LOG_LEVEL` | No | `debug` | No | Minimum level written to the log. Compose passes it through only when set. A blank value is ignored. When set, the log level on the Settings page is locked. |
| `LOG_CHANNEL` | No | `stderr` in the image | No | Set by the Dockerfile so logs reach `docker compose logs`. Leave it unset in `.env`. |
| `TRUSTED_PROXIES` | No | private ranges | No | Peers whose `X-Forwarded-For`, `-Proto` and `-Host` are trusted. Unset or empty means the private ranges `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `127.0.0.1`, `::1` and `fc00::/7`, which covers a reverse proxy in the same Docker network or LAN. Otherwise list your proxy's address or subnet. `*` trusts every peer: the brute-force limit then sees every client as the proxy's address, the per-address limit is turned off, and `nexusdav:prepare` prints a warning. A device inside the private ranges can set `X-Forwarded-For` itself; list only your proxy if that matters. |
| `PUID`, `PGID` | No | `568` | No | User and group that own `/data` inside the container. |
| `NEXUSDAV_VERSION` | No | set at build | No | Shown on the Dashboard. The image sets it from the `NEXUSDAV_VERSION` build argument: a release uses its version and the tagged commit (for example `0.1.6+4f82db8`), CI uses `ci+<commit>`, and a local build shows `dev`. |

Secrets can also be supplied as files (for example `DB_PASSWORD_FILE`) for use with Docker secrets; this is planned. The application stops at start with a clear message if a required variable is missing.

If you lose access to the admin account, see [Resetting the admin password](#resetting-the-admin-password).

### Settings in the admin panel

These settings are changed on the **Settings** and **Configuration** pages. A change applies from the next request, with no restart. Each has an environment variable; when it is set, that value wins and the panel shows the field locked. Leave the variable unset to manage the setting in the panel. With Docker, add a variable to the `environment:` list of the `nexusdav` service in `compose.yaml`; the worker shares that list. On TrueNAS, add it to the worker block too.

| Setting | Page | Variable | Default |
|---|---|---|---|
| CalDAV on or off | Settings | `NEXUSDAV_CALDAV` | on |
| CardDAV on or off | Settings | `NEXUSDAV_CARDDAV` | on |
| Tasks (VTODO) on or off | Settings | `NEXUSDAV_TASKS` | on |
| Notes (VJOURNAL) on or off | Settings | `NEXUSDAV_NOTES` | on |
| Sharing on or off | Settings | `NEXUSDAV_SHARING` | on |
| Files on or off | Settings | `NEXUSDAV_FILES` | off |
| Maximum upload size for Files (MB, 1 to 102400) | Settings | `NEXUSDAV_FILES_MAX_UPLOAD_MB` | `128` |
| WebDAV-Push on or off | Settings | `NEXUSDAV_PUSH` | off |
| Include Files in push | Settings | `NEXUSDAV_PUSH_FILES` | off |
| Push subscriptions per user (1 to 10000) | Settings | `NEXUSDAV_PUSH_MAX_SUBSCRIPTIONS` | `100` |
| Allow private networks (push services on plain http or the LAN) | Settings | `NEXUSDAV_PUSH_ALLOW_PRIVATE` | off |
| Push log level | Settings | `NEXUSDAV_PUSH_LOG_LEVEL` | `info` |
| Log level | Settings | `LOG_LEVEL` | `debug` |
| Keep sync history for (days) | Settings | `NEXUSDAV_SYNC_HISTORY_DAYS` | `90` |
| Default panel language (`en` or `ru`) | Configuration | `NEXUSDAV_LOCALE` | `en` |
| Server time zone | Configuration | `NEXUSDAV_TIMEZONE` | `UTC` |
| Time format (`24` or `12`) | Configuration | `NEXUSDAV_TIME_FORMAT` | `24` |
| Week starts on (`0` Sunday to `6` Saturday) | Configuration | `NEXUSDAV_WEEK_START` | `1` |
| Failures per username | Configuration | `NEXUSDAV_RATE_LIMIT_MAX_ATTEMPTS` | `5` |
| Failures per address | Configuration | `NEXUSDAV_RATE_LIMIT_PER_ADDRESS` | `20` |
| Rate-limit window (seconds) | Configuration | `NEXUSDAV_RATE_LIMIT_DECAY_SECONDS` | `60` |
| Session lifetime (minutes of inactivity) | Configuration | `SESSION_LIFETIME` | `120` |
| Secure session cookie | Configuration | `SESSION_SECURE_COOKIE` | on when `APP_URL` is `https` |
| Keep diagnostics for (days) | Configuration | `NEXUSDAV_DIAGNOSTICS_RETENTION_DAYS` | `30` |
| Close messages after (seconds) | Configuration | `NEXUSDAV_NOTIFICATION_SECONDS` | `6` (0 keeps them until closed) |

**Inactivity timeout:** an admin who does nothing for the session lifetime is returned to the sign-in page by the panel itself, with a message ("You were signed out because of inactivity") that stays until it is closed. A page load, a save or any other action that reaches the server counts as activity; reading a page without clicking does not. The time is shared between browser tabs, so activity in one tab keeps the other tabs signed in. A page that finds the server session already gone (for example after a restart that lost sessions, or a server-side sign-out) takes the same path instead of showing "This page has expired".

**Admin panel messages:** success and info messages close by themselves after **Close messages after** seconds. `0` keeps them until you close them. Errors and warnings always stay until closed, and so does the one-time app password secret.

What the service switches do:

- **CalDAV or CardDAV off:** paths under `/dav/calendars/` or `/dav/addressbooks/` answer 403 with "CalDAV is turned off on this server." (or CardDAV). Discovery no longer offers the home. Stored data is kept and comes back when the service is turned on.
- **Tasks or Notes off:** calendars stop listing `VTODO` or `VJOURNAL` as supported, so no task or note can be created or changed. Existing ones stay readable and can be deleted.
- **Sharing off:** a calendar or address book shared with a user is no longer listed in that user's home, and its path answers 404. The shares are kept and come back when sharing is turned on. The owner's calendar does not change.
- **Shared address books and clients:** DAVx5 and Thunderbird cannot delete a shared address book. DAVx5 can only switch syncing off for it, and removing it in Thunderbird only removes it on the device. A sharee stops a share on the device, and an admin removes it in the panel. Thunderbird keeps its own Read-only flag for a book when the access changes: untick it in the address book's properties and restart. A sharee with read and write changes the owner's contacts for everyone.

**Language.** The admin panel ships in English and Russian. Each admin chooses a language under **Language** in the user menu; the item shows the language in use, and "Server default (…)" when it is not the admin's own choice. **Server default** clears the choice and follows the Configuration setting. A language chosen in the user menu always wins over the Configuration setting, so changing the server default does not change an admin who chose their own (Configuration says so when you save). The login page always uses the server default. A value of `NEXUSDAV_LOCALE` that is not a shipped language falls back to English. Only the panel is translated: DAV responses, `/health`, the container log and the reasons stored for Diagnostics stay in English, so log searches keep working; the Diagnostics page shows those reasons in the admin's language. To add a language, see [CONTRIBUTING.md](../CONTRIBUTING.md#translations).

The server time zone applies to users without their own time zone, becomes the `calendar-timezone` of new calendars (the owner's own zone wins when set), is the zone the panel shows times in, and the zone of the times in the log lines of the app and the worker (stored times stay in UTC). Turning on secure cookies is refused unless the panel is open over HTTPS, so an admin cannot sign themselves out by accident.

### Resetting the admin password

Run the reset command on the server. Shell access to the container is the proof of ownership; nothing is exposed on the web.

```bash
# Use compose.postgres.yaml or compose.sqlite.yaml, matching how the stack was started.
docker compose -f compose.yaml -f compose.postgres.yaml exec -u nexusdav nexusdav php artisan nexusdav:reset-admin-password --email=admin@example.com
```

Run artisan commands as `-u nexusdav`. Without it they run as root and can leave root-owned files that the server cannot write.

The command asks for the new password twice (input hidden, at least 12 characters). Options:

| Option | Effect |
|---|---|
| `--email=` | The admin account to reset. May be left out when there is only one admin. A non-admin email is refused. |
| `--generate` | Generate a 24-character password and print it once, instead of asking. Use it for `--no-interaction` runs. |
| `--revoke-app-passwords` | Also revoke every app password of that admin. Without it, an interactive run asks; a non-interactive run keeps them. |

The reset signs out the admin's existing sessions: their rows are removed from the session table, and any other session ends on its next request because the password changed. The reset is logged with the username and the counts of ended sessions and revoked app passwords, never with the password. A command run with `docker compose exec` writes that log line to its own output, not to `docker compose logs`, so keep the output if you need a record. If the admin account is disabled, the command says so; enable it from another admin account. From Version 2.x, an email is sent to the admin if SMTP is configured.

A web-based reset triggered by an environment variable is deliberately not used: anyone who reaches the page first after a restart could set the admin password.

### Danger Zone: Reset to Default

The last section of **Configuration** returns the server to a fresh install (1.6.0). It deletes every other user, all calendars, address books, contacts, events, tasks, notes and files, every app password (yours too), shares, push subscriptions and keys, diagnostics, saved settings, queued jobs and your other sessions. It keeps your account and password, the settings from the environment, `APP_KEY` and the container log. You get a new default calendar and address book, like a new user, and stay signed in. Nothing of it can be undone: take a backup first (see [Backup, restore and upgrade](#backup-restore-and-upgrade)).

1. **Reset to Default** shows what would be deleted, with today's numbers, and what is kept. **Export settings first** downloads the settings file.
2. **Continue** asks for the server's name from `APP_URL` (for example `dav.example.com`) and your password. **Reset** stays disabled until the name matches. A wrong password is refused and logged as a warning; after 5 wrong passwords in a minute, wait a minute.
3. While the reset runs (usually seconds; longer with many files), every request answers **503** with `Retry-After`, `/health` excepted, and the queue worker takes no job. Clients retry and then get **401**: set them up again with a new app password. The log gets a `WARNING Factory reset` line with the numbers.

Every admin can run it. If it fails, nothing is deleted and the files are put back. A reset that is cut off (the container stopped) leaves the server closed for at most 15 minutes; delete `/data/.nexusdav-resetting` to open it sooner.

### Authentication

- Main user passwords: **Argon2id**
- **App passwords / personal access tokens** (for DAVx5, Thunderbird and other clients), available from Version 1. An app password is not limited to one service: it works for calendars and contacts, and also for the user's [files](#files) (from 0.4.0), so give each device its own and revoke it when the device is lost
- **Rate limiting and brute-force protection** on the login form and the DAV endpoints, available from Version 1

### Database

- **Primary (recommended for production)**: PostgreSQL, via `compose.postgres.yaml`
- **Also supported**: SQLite (for simple deployments and development), via `compose.sqlite.yaml`

**Choose before the first start.** Switching between SQLite and PostgreSQL after install is not supported in Version 1. To move servers, export your data (ICS / VCF export is planned for Version 2) and import it into a fresh install.

SQLite runs in WAL mode with a busy timeout of 5 seconds, so a DAV write and the admin panel can use the same file. WAL needs a local filesystem. Keep the SQLite file on a local disk. Do not put it on an NFS or SMB share. A TrueNAS dataset that is local to the host is fine. A dataset that is mounted from another machine over the network is not.

### Email

No email in Version 1 or Version 2. Outbound email is Version 2.x, submitted to an external SMTP server. NexusDAV does not receive email.

When that version arrives, mail is used for:

- Invitations to people who are not users of this server
- Notifications and reminders
- Calendar and address book share notices
- Account notices, including login notifications and account-lockout emails (each can be switched off)
- System reports

Replies to emailed invitations are not read or processed. People who have an account respond through on-server CalDAV scheduling (Version 2).

## Reverse proxy and `.well-known`

In production NexusDAV is expected to run behind a reverse proxy (Caddy, nginx, Traefik) that terminates HTTPS. Two things matter:

1. **Service discovery.** The application serves `/.well-known/caldav` and `/.well-known/carddav` and redirects them to `/dav/`. Clients such as Apple Calendar / Contacts, Thunderbird and DAVx5 can then use the domain (or the root URL) instead of a full collection path. The proxy must forward those two paths to the app. Some proxies swallow unknown paths and never do. For those, configure the fallback redirects below in the proxy itself.
2. **A DAV-aware proxy.** The proxy must pass through all HTTP methods used by DAV (`PROPFIND`, `PROPPATCH`, `REPORT`, `MKCOL`, `MKCALENDAR`, `COPY`, `MOVE`, `LOCK`, `UNLOCK`) and the `Authorization` header, forward the `Host` and `X-Forwarded-Proto` headers, and allow request bodies up to 128 MB (vCards with photos, events with attachments), or up to Maximum upload size for Files (see [Large file uploads](#large-file-uploads-files)). `APP_URL` must match the public HTTPS address. A proxy in the same Docker network or LAN is trusted by default; otherwise set `TRUSTED_PROXIES` (see [Environment variables](#environment-variables)).

The DAV root is `/dav/`. It is fixed once chosen, because clients store full URLs.

**Ready-to-use configurations** are in [docs/proxy](proxy/). Each one forwards everything to the app, adds the `.well-known` fallback redirects, and allows 128 MB bodies. They were tested in front of the image over HTTPS with test certificates: discovery, `PROPFIND` with an app password, `MKCALENDAR`, `REPORT`, `DELETE`, a 5 MB vCard, `https` links in the admin panel, and the client address (not the proxy's) in the log.

| Proxy | Files | How to use |
|---|---|---|
| Caddy | [Caddyfile](proxy/Caddyfile), [compose.caddy.yaml](proxy/compose.caddy.yaml) | Copy both next to `compose.yaml`, set the host name in `Caddyfile`, and add `-f compose.caddy.yaml` last. Caddy gets the certificate itself; ports 80 and 443 must reach the machine. |
| nginx | [nginx.conf](proxy/nginx.conf) | A server block for nginx on the host (upstream `127.0.0.1:8080`) or in a container on the app's network (upstream `nexusdav:8080`). Set the host name and certificate paths. nginx 1.25.1 or newer. |
| Traefik | [compose.traefik.yaml](proxy/compose.traefik.yaml) | Labels for an existing Traefik v3 that watches Docker. Copy it next to `compose.yaml`, set the host name, and match the entrypoint, certificate resolver and network names to your Traefik. Add `-f compose.traefik.yaml` last. |

The Caddy and Traefik files stop publishing port 8080, so only the proxy reaches the app. With nginx on the host, publish the app on the loopback only (`127.0.0.1:8080:8080`).

The fallbacks look like this. Traefik answers `301` to `GET` and `308` to other methods; both send the client to `/dav/`.

```nginx
location = /.well-known/caldav  { return 301 https://$host/dav/; }
location = /.well-known/carddav { return 301 https://$host/dav/; }
```

```caddy
redir /.well-known/caldav https://{host}/dav/ 301
redir /.well-known/carddav https://{host}/dav/ 301
```

Every answer from the app carries an `X-Request-Id` header. A proxy can add it to its own access log (the nginx file shows how), so a proxy line can be matched with the app's log line.

### Large file uploads (Files)

An upload to Files is streamed to disk, so its size is limited by **Maximum upload size**, not by memory. The setting goes up to 102400 MB (100 GB); it is 128 MB by default. Uploads up to 1 GB have been tested; try one file of the size you need before relying on it. Everything between the client and the app must allow the same size:

1. **Maximum upload size** on **Settings → Files** (or `NEXUSDAV_FILES_MAX_UPLOAD_MB`): at least the largest file, for example `51200` for 50 GB. A larger upload gets 413 and leaves nothing behind.
2. **The reverse proxy.** It is the usual reason a large upload fails.
   - **nginx and Nginx Proxy Manager:** `client_max_body_size 0;` (no limit) or a size above the largest file, and `proxy_request_buffering off;`, or nginx first writes the whole upload to its own disk. Raise `proxy_read_timeout` and `proxy_send_timeout` for slow links. In Nginx Proxy Manager, put these lines in the host's **Advanced** tab.
   - **Caddy:** `max_size` in the `request_body` block at least the largest file.
   - **Traefik v3:** no body limit, but the entrypoint's `respondingTimeouts.readTimeout` is 60 seconds by default and cuts a long upload off. Raise it, or set it to `0`, in Traefik's static configuration (for example `--entryPoints.websecure.transport.respondingTimeouts.readTimeout=0`).
   - **Cloudflare** (proxy or tunnel): a request body is limited to 100 MB on the Free and Pro plans, so larger files cannot pass through it. Reach the server another way for those, for example over a VPN.
3. **Free space on the data volume.** An upload is written to `/data/files/.uploads/` and renamed into place on the same volume, so it needs room for the whole file. Replacing a file needs room for the old and the new copy until the upload finishes. A full disk answers 507 and leaves nothing behind. On TrueNAS, check the dataset's quota as well as the pool.
4. **Time.** 50 GB take about 7 minutes at 1 Gbit/s and more than an hour at 100 Mbit/s. The app does not stop a long upload or copy; the proxy's timeouts and the client's own limits can.
5. **The client.** rclone and curl send a file as one `PUT` and work with any size the server allows. Other clients may have limits or timeouts of their own.

Calendars and contacts are not affected: their bodies are read into memory and stay limited to 128 MB (PHP's `post_max_size`).

## Client setup and compatibility

### Client compatibility

NexusDAV targets standards-compliant clients. The table shows the Version 1 targets and what has been tested so far. Reports for other clients and versions are welcome (see [CONTRIBUTING.md](../CONTRIBUTING.md)). The steps the project runs by hand, and their results per client and database, are in the [client checklist](testing/client-checklist.md).

| Client | CalDAV (events) | CardDAV (contacts) | Tasks (VTODO) | Notes (VJOURNAL) | Status |
|---|---|---|---|---|---|
| **DAVx5** (Android) | Yes | Yes | Yes, via **jtx Board** | Yes, via **jtx Board** | Primary target. Client checklist passed on 0.1.3 and 0.1.4 with DAVx⁵ 4.5.21 + jtx Board 2.17.00: calendar, contacts, tasks and notes. Sharing passed on 0.3.0 (shared calendars, tasks, notes and address books). A first sync of 2,000 contacts takes about 10 minutes on the phone ([#1](https://github.com/offsyanka99/NexusDAV/issues/1)) |
| **Thunderbird** | Yes | Yes | Yes | No (not supported by the client) | Target. Client checklist passed on 0.1.3 and 0.1.4 with 159.0: calendar, contacts and tasks. Sharing passed on 0.3.0 (shared calendars and address books, read-only and read-write) |
| **Apple Calendar / Contacts** (macOS, iOS) | Yes | Yes | Yes, in Reminders (not in Calendar) | No | Client checklist passed on 0.1.6 with an iPad (iOS 18.5, native apps): discovery, events, recurring events, tasks, contacts, shared calendars and address books (0.3.0), offline edits, two devices editing one item, a calendar deleted and created again, and a large first sync (see Troubleshooting for what the iPad keeps after a calendar is re-created) |
| **vdirsyncer** 0.21.0 | Yes | Yes | Synced as files | Synced as files | Tested for 0.1.1 (automated) |
| **python caldav** 3.7.0 | Yes | — | Yes | Yes | Tested for 0.1.1 (automated) |
| Other standards-compliant clients | Expected | Expected | Client-dependent | Client-dependent | Best effort |

**Files (WebDAV file storage, 0.4.0).** The steps and their results are in [Files testing](testing/files-testing.md).

| Client | Status |
|---|---|
| **GNOME Files** (gvfs, `dav://` and `davs://`) | Passed on 0.4.0 over HTTPS behind Caddy, on PostgreSQL and SQLite, with Nautilus 50.2.2 and gvfs: list, create a folder, copy in and out, rename, move and delete. Copy and paste into another folder shows "Operation not supported" although the copy is complete; choose Skip ([results](testing/files-testing.md#results-of-the-release-pass)) |
| **rclone** 1.60.1 | Passed on 0.4.0 over HTTPS behind Caddy, on PostgreSQL and SQLite: mkdir, upload and download (identical), server-side rename, move and copy, folder move and delete |
| **DAVx5 WebDAV mounts** (Android) | 4.5.21 on Android 17, 0.4.0, both databases: connects, lists and downloads. DAVx5's mount offers few functions, so it is a way to read files, not to manage them |
| Windows Explorer, macOS Finder | Not tested by the project; reports are welcome |

**WebDAV-Push (0.5.0).** The steps are P1 to P12 in [push testing](testing/push-testing.md) and the Push section of the [client checklist](testing/client-checklist.md#12-webdav-push).

| Client | Status |
|---|---|
| **DAVx⁵** 4.4.10+ (Android) with ntfy or FCM | Release pass passed on 0.5.0-rc.1+1908632 on PostgreSQL and SQLite (November 10, 2026): all of step 12 and the regression steps with DAVx⁵ and Thunderbird ([results](testing/client-checklist.md#12-webdav-push)). Push is verified with ntfy and with FCM on the Android phone |
| Thunderbird, Apple Calendar and Contacts, vdirsyncer | No WebDAV-Push support in the client; they sync on their own schedule as before |

**Recommended clients for testing**

- **Android:** DAVx⁵ (Google Play) + jtx Board — events, contacts, tasks and notes
- **Desktop:** Thunderbird — events, contacts and tasks
- **iOS / macOS:** Apple Calendar, Contacts and Reminders (not part of the project's own test pass; reports welcome)
- **Automation:** vdirsyncer and python caldav (already used in CI)

**What was tested by hand** (0.1.3 and 0.1.4, PostgreSQL and SQLite behind HTTPS, November 2026; every step with its result is in the [client checklist](testing/client-checklist.md#results)):

- **DAVx⁵ 4.5.21** (Google Play) + **jtx Board 2.17.00** on a Pixel 7 Pro (Android 17): discovery, events, recurring events with exceptions, tasks, notes, contacts with a photo, a calendar created from the client, offline edits, two devices editing the same event, and a first sync of 5,000 events and 2,000 contacts. 0.1.1 was also tested on a Pixel 10 Pro.
- **Thunderbird 159.0** (Snap package on Ubuntu): the same steps except notes and creating a calendar, which Thunderbird does not do.
- **iPad, native Contacts:** a first sync of 2,000 contacts (74 s) on 0.1.4.
- **iPad (iOS 18.5):** the checklist steps 1 to 7 on 0.1.6; notes were tested on the Android phone only.
- **Files and a regression pass on 0.4.0** (`0.4.0+5aea720`, PostgreSQL and SQLite behind Caddy over HTTPS, November 8 and 9, 2026): GNOME Files, rclone and a DAVx5 WebDAV mount against the Files steps, and discovery, items and offline edits (DAVx5, Thunderbird) and sharing again; every step passed ([results](testing/files-testing.md#results-of-the-release-pass)).

0.1.2 and 0.1.3 changed only the admin panel (languages) and a test tool. On 0.1.3 everything passed except a calendar deleted and created again with the same name while a client was offline: the client kept the old events. 0.1.4 fixes that in the server's sync tokens (see [DAV endpoints](dav-endpoints.md#sync-tokens)), and both clients passed that step on 0.1.4. 0.1.5 adds sync history retention, safe concurrent writes and readable log levels; 0.1.6 retries a write when the database is busy and answers 503 instead of 500 when it stays busy. 0.1.7 adds a setting that closes admin panel messages by themselves, shows the commit next to the version on the Dashboard, and keeps the Edit and Delete buttons in view for long calendar and address book names. 0.2.0 closes admin panel messages by themselves after the configured time, returns an idle admin to the sign-in page when the session lifetime has passed, and shows which language the panel is in and why. The client checklist passed on 0.1.6 with DAVx5, Thunderbird and an iPad, on both databases (later changes are in the admin panel only), and the optional step 10 (offline longer than the sync history) passed as a server-side check on 5 November ([results](testing/client-checklist.md#pass-2-m2-release-candidate-pr-19)).

**What was tested by automated clients** (SQLite, November 2026; recorded on 0.1.0 and unchanged up to 0.1.4, whose sync-token change is covered by its own tests):

- **vdirsyncer 0.21.0** started from the server URL only, discovered the default calendar and address book, uploaded a local contact and event, synced a server-side edit down and a local delete up.
- **python caldav 3.7.0** started from `/.well-known/caldav`, found the principal and the default calendar, created a calendar with `MKCALENDAR`, saved a weekly event (expanded by the server to three occurrences), a task that it then completed, and a note, ran an incremental sync that returned only the changed event and then the deletion, and deleted the calendar.
- The image smoke test, run on every CI build, writes, reads and syncs an event, a task and a contact, and creates and deletes a calendar, over plain HTTP.

**About notes (VJOURNAL):** Notes are stored as standard CalDAV VJOURNAL entries. A CalDAV client writes them. The admin panel creates and manages the collection that holds them; it does not edit the notes. Client support is limited — currently mainly DAVx5 with jtx Board. Apple Notes and Thunderbird do not use VJOURNAL, so notes will not appear in those clients. A user portal that can edit them is planned for later. See [Notes](apps/notes.md).

**About tasks (VTODO):** on Android, DAVx5 only syncs the data; a separate tasks app is needed to display it. On Apple devices, tasks appear in Reminders. See [Tasks](apps/tasks.md).

### Connecting a client

Use the same steps for every client; only the menu names differ.

1. In the admin panel, create a user and generate an **app password** for each device. Do not use the main account password in clients.
2. Enter your server address (`APP_URL`, for example `https://dav.example.com`) and the user's email or username.
3. Paste the app password.
4. If discovery fails, enter the full DAV root URL instead of the domain: `APP_URL` followed by `/dav/` (for example `https://dav.example.com/dav/`).

- **DAVx⁵ + jtx Board (recommended Android setup):** add an account in DAVx⁵ (*Login with URL and user name*), enter the URL and app password. Install **jtx Board** to view and edit tasks (VTODO) and notes (VJOURNAL).
- **Thunderbird:** add a calendar (*New Calendar > On the Network > CalDAV*) and an address book (*New Address Book > CardDAV*).
- **Apple Calendar / Contacts:** add an account of type *Other* (CalDAV / CardDAV account).

The steps for DAVx5 and Thunderbird were checked against the versions in [Client compatibility](#client-compatibility). The Apple steps are not verified yet.

### Sharing

An admin shares a calendar or an address book with other users in the panel. Clients cannot create or change shares.

**How to share (admin panel):**

1. Open **Users**, choose the owner, and open the **Calendars** or **Address books** tab.
2. **Share** on a row: choose one or more users and one access level, **Read only** or **Read and write**, then **Share**. To give different users different levels, share once per level.
3. **Shared with** on the same row opens a table of the people it is shared with. Change access in the row, remove a share, or select several rows and use **Set access** or **Remove**.
4. The **Shared with this user** tab on a user's page lists what other users shared with them and removes it.
5. **Settings → Services → Sharing** turns sharing on or off for the whole server. Off hides every share from the people it was shared with and keeps the shares; turning it on brings them back.

**What a client shows.** The sharee's client finds the shared collection in its own calendar or address book home, usually named "<name> (<owner's name>)", and syncs it like any other. The owner's items stay in the owner's collection; the sharee never sees the owner's other collections or account.

- **Read only:** the client shows the collection as read-only, and a write is refused with 403. The sharee can still rename their own copy.
- **Read and write:** the sharee can add, change and delete items in the owner's collection. Those changes belong to the owner and to everyone the collection is shared with.
- A shared calendar carries its events, tasks and notes. Tasks and notes follow the Tasks and Notes switches.
- A sharee's own name, color and order for a shared collection stay on their side and do not change the owner's.
- Removing a share in the panel, or switching sharing off, removes the collection from the sharee's client on its next sync (DAVx5: **Refresh list**; Thunderbird: remove the old entry yourself). The owner's data is never touched.
- Clients leave a share by switching it off on the device. DAVx5 and Thunderbird do not send a `DELETE` for a shared address book.
- Nothing is shared until an admin creates a share.

Shares are stored as rows linked to the owner's collection (see [data model](data-model.md) and [sharing design](design/sharing.md)). Deleting a user or a collection deletes its shares, and the delete confirmation lists them.

### Files

Files is each user's own WebDAV storage for documents and other files. It is not a shared drive and not CalDAV. It arrived in 0.4.0 and is **off until an admin switches it on**, also after an upgrade.

**Switching it on.** **Settings → Files** in the admin panel, with no restart. Or set `NEXUSDAV_FILES=true`; the field is then locked. While Files is off, `/dav/files/` answers 403 "Files is turned off on this server." and the files stay on the data volume. **Maximum upload size** (128 MB by default) is shown when Files is on; see [Large file uploads](#large-file-uploads-files).

**Where the files are.** In `/data/files/<username>/` on the data volume, created on the user's first request. The path is not a setting. A user's page in the panel shows whether their file home exists. Deleting the user deletes the home with every file in it, and the confirmation says so.

**URL and sign-in.** `https://dav.example.com/dav/files/<username>/`. Sign in with the username and an **app password**; the admin panel password does not work for DAV. An app password is not limited to one service, so the one a phone uses for calendars and contacts also opens the user's files. Give each device its own.

**Clients.** Files has no discovery, so a client needs the full URL.

- **GNOME Files** and other gvfs file managers: `davs://<username>@dav.example.com/dav/files/<username>/` (`dav://` over plain HTTP).
- **rclone:** `rclone config create nexusdav webdav url https://dav.example.com/dav/files/<username>/ vendor other user <username> pass '<app password>'`, then `rclone copy`, `rclone ls` and the rest. `rclone moveto` and `copyto` use `MOVE` and `COPY` on the server.
- **DAVx5 on Android:** **WebDAV mounts** in its main menu, with the same URL. A mount connects, lists and downloads; DAVx5 itself offers few functions there, so use rclone or a file manager that speaks WebDAV for more.
- Windows Explorer and the macOS Finder are not tested by the project. Windows' built-in WebDAV client has a file size limit of its own that is low by default.

**What works.** Upload, download (also a part of a file), rename, move, copy and delete of files and of whole folders, `LOCK` and `UNLOCK` for office programs, and properties that follow a copy or a move. A name has up to 255 bytes and no control characters, and is stored in Unicode NFC, so macOS and Linux see the same file. The helper files of macOS and Windows (`._*`, `.DS_Store`, `desktop.ini`) are stored like any other file.

**What it does not do.** Share a file or a folder with another user (sharing is for calendars and address books). Limit the space per user: quotas, a trash and per-user storage statistics are Version 2.x, and a folder reports the free space of the whole volume. The admin panel shows one server-wide figure: a **Files storage** card on the Dashboard with the total size, the number of files, the users who have files and the free space of the volume. The total is counted when an admin clicks **Refresh** on the card (it walks every file and stops after 30 seconds on a very large library, then reads "At least"), never when the Dashboard opens, so it can be older than the files. Offer sync tokens: a client lists a folder to see what changed. Edit files in the browser: the user portal of Version 2 will manage a person's own files.

**Isolation.** A home is private. Another user's home answers 404, exactly like a username that does not exist, and a path with `..` is refused. A symbolic link put on the disk by an admin or another process is never listed, opened or followed.

**Backup.** `/data/files` is part of the data volume, so back it up together with the database (see [Backup, restore and upgrade](#backup-restore-and-upgrade)). Files can be many times larger than the database. `/data/files/.uploads/` holds uploads in progress and needs no backup.

**Known client behaviour.** GNOME Files shows "Operation not supported. Do you want to skip it?" after a copy and paste into another folder, although the copy is complete: it tries to set the copy's modification time, which its WebDAV support cannot do. Choose **Skip**. **Retry** makes a second copy.

### WebDAV-Push

With WebDAV-Push a client hears within seconds that a calendar, an address book or a folder changed, instead of finding out on its next scheduled sync. NexusDAV implements the bitfire draft (see [Requirements](#requirements)). It arrived in 0.5.0 and is **off until an admin switches it on**, also after an upgrade.

**What it needs.**

1. **The queue worker.** The app only records changes; the worker sends the messages. Start it as in [Queue worker](#queue-worker-optional). Without it, messages wait in the queue, and the Dashboard warns.
2. **Push switched on:** **Settings → WebDAV-Push**, or `NEXUSDAV_PUSH=true` on the app and the worker (the field is then locked).
3. **A client that supports it.** DAVx⁵ 4.4.10 or newer on Android, with a UnifiedPush distributor: the **ntfy** app (with ntfy.sh or your own ntfy server), or Google's **FCM** through Play services. Pick it in DAVx⁵ under **Settings → Push**, then sync the account once. Other clients keep syncing on their schedule.

**What happens.** On its next sync DAVx⁵ subscribes to every calendar and address book it syncs. A change from any other device (an event added in Thunderbird, a contact changed by a sharee) sends one message to each subscribed device, about five seconds later; changes within those five seconds are merged into one message. The phone then syncs that collection. A device is not told about its own changes. A shared calendar notifies the owner's and every sharee's devices.

- **Subscriptions** last at most seven days; DAVx⁵ renews them every day. They are listed on **Diagnostics → Push subscriptions** with the push service's host, the expiry, the last push and its status. **Revoke** stops one until the device subscribes again; revoking the device's app password stops it for good. Disabling or deleting a user, removing a share or deleting a collection removes its subscriptions.
- **Per user** at most **Push subscriptions per user** (100 by default); one device uses one per calendar and address book.
- **Include Files in push** offers push on file folders too. No client tested by the project uses it yet.
- **Privacy.** Messages go through the push service (ntfy, or Google for FCM), encrypted for the device. The service sees only an opaque topic and the timing, never names or contents.
- **Security.** The server sends only to `https` push services on public addresses: the address is checked when the message is sent and the connection is pinned to it, with no redirects. A self-hosted ntfy on the LAN needs **Allow private networks** (`NEXUSDAV_PUSH_ALLOW_PRIVATE=true`); otherwise its subscriptions are removed with a warning in the log.
- **Delivery.** A push service that is busy or unreachable is tried again after 30 seconds, 2 minutes and 10 minutes. A subscription the push service no longer knows (404 or 410) is removed after a day without success, any other failing one after three days.
- **Log.** Push lines use **Push log level** (`info` by default; `debug` logs every message sent) and show only the push service's host, never its address or keys.

The protocol details (properties, registration, the `/dav/push/<id>` URLs) are in [DAV endpoints](dav-endpoints.md#webdav-push) and the [design note](design/webdav-push.md).

### DAV endpoints

Most clients need only the root URL; they discover the rest on their own. Use the other URLs only for clients without discovery. Paths follow the sabre/dav layout.

| Purpose | URL |
|---|---|
| Root (enter this) | `https://dav.example.com/dav/` |
| Principal | `/dav/principals/<username>/` |
| Calendar home | `/dav/calendars/<username>/` |
| Calendars | `/dav/calendars/<username>/<calendar-id>/` |
| Address book home | `/dav/addressbooks/<username>/` |
| Address books | `/dav/addressbooks/<username>/<addressbook-id>/` |
| Files (WebDAV, off until an admin switches it on) | `/dav/files/<username>/` |
| WebDAV-Push registration (only `DELETE`, by the client that registered) | `/dav/push/<id>` |

Creating a user creates the two homes and, inside them, a default calendar and a default address book (both named `default`). A client signed in with that user's app password discovers them from the root (`current-user-principal`, `calendar-home-set`, `addressbook-home-set`). Another user's homes are not returned. The admin panel can add, edit, and delete calendars and address books. Calendar and address book URLs use the collection identifier, not its display name.

## Protocols and features

### Supported protocols & extensions

The table shows the version in which each item arrives (see [Roadmap](#roadmap)). Version 1 items are built; Version 1.x and later items are planned.

| Protocol / extension | Target version |
|---|---|
| WebDAV, CalDAV, CardDAV | Version 1 |
| Principal / Home Collections | Version 1 |
| Tasks (VTODO) via CalDAV | Version 1 |
| Notes (VJOURNAL) via CalDAV | Version 1 |
| Collection sync (sync-token), provided by sabre/dav | Version 1 |
| Sharing of calendars and address books (read-only or read-write, set up in the admin panel; clients do not create shares) | Version 1.x |
| WebDAV-Push (bitfire draft, on/off in the admin panel, sent by the queue worker; not a sabre/dav plugin) | Version 1.x (0.5.0) |
| CalDAV Scheduling, on-server invitations and free/busy | Version 2 |
| Outbound email (external invitations, notices, reminders, reports) | Version 2.x |
| Files (WebDAV file storage, off until an admin switches it on) | Version 1.x (0.4.0) |
| Managed Attachments | Version 2.x |

Collection sync is enabled through configuration.

### Features (planned)

These are long-term goals for the project. Implementation will be staged. The architecture and framework should be designed to support future features without major rewrites.

- Focused self-hosted DAV server with a modern admin panel, without the weight of a full groupware suite
- Admin interface for users, calendars, address books, shares, permissions and server settings
- Separate user portal for managing personal calendars, contacts, notes (VJOURNAL — limited client support), tasks and files (WebDAV)
- **Multilingual** admin panel (English and Russian since 0.1.2, more as translation files) and user portal (Version 2.x)
- Docker-friendly
- Standards-compliant DAV protocols
- Search across events and contacts
- Import / Export of ICS and VCF (calendars, contacts, tasks and notes)

What each app includes, version by version, is described in [apps](apps/README.md): [Calendar](apps/calendar.md), [Contacts](apps/contacts.md), [Tasks](apps/tasks.md), [Notes](apps/notes.md), [Files](apps/files.md), and the [admin panel](apps/admin.md).

#### Admin panel (long-term)

- User quotas
- Per-user and server storage statistics
- WebDAV-Push subscriptions (view and manage)
- Health checks

#### Security (long-term)

- Optional 2FA
- Audit log of logins and admin actions

#### Calendar (long-term)

- Public read-only calendar links / ICS subscriptions
- Birthdays calendar generated from contacts
- Holidays calendar (the country is selected first) — Version 2
- Custom color for a specific event — Version 2

#### Contacts (long-term)

- Duplicate detection and merge — Version 2
- Share a contact by email (requires the Version 2.x mailer)

#### Data (long-term)

- Trash and restore of deleted items — Version 2.x
- Trash for files in the user portal — Version 2.x
- Direct import from Radicale, Baïkal and Nextcloud — Version 2.x
- Editing office documents in the browser — Version 2.x

#### Integration (long-term)

- Webhooks and API tokens for automation

#### Infrastructure and operations (long-term)

- Automatic backups (local + remote: S3, Backblaze, etc.)
- A listing in the official TrueNAS SCALE Apps catalog (a Custom App YAML already works today, see the [guide](guide.md#installing-on-truenas-scale))
- Prometheus metrics endpoint
- Maintenance mode and read-only mode for upgrades

#### Accessibility (long-term)

- Mobile-friendly admin panel and user portal (the admin panel's layout is already responsive) — Version 2.x
- Dark mode for the user portal (the admin panel already has a light, dark and system theme switcher) — Version 2.x

Items marked with a version are scheduled in the [plan for Version 2 and later](plans/version-2-and-later.md). The others are not assigned to a version yet; they will be added to the [Roadmap](#roadmap) as they are scheduled.

## Backup, restore and upgrade

The database backup commands below were run against the image. The restore steps are not tested yet.

**What to back up**

- The database and the data volume, taken at the same time (or with the application briefly stopped) so they stay consistent. The data volume holds `APP_KEY` (`/data/app.key`) and, with Files on, every user's files in `/data/files`, which can be far larger than the database (see [Files](#files)).
- `.env`. It holds `APP_URL`, the database password and the other boot settings. `APP_KEY` is not in it, so keep a separate copy of `/data/app.key`: without it, encrypted values cannot be read after a restore. Those include the WebDAV-Push keys; without them the server makes new ones and devices subscribe again within a day. Store that copy apart from the database backup.

**Database backup**

```bash
# PostgreSQL (do not copy the live data directory)
docker compose -f compose.yaml -f compose.postgres.yaml exec postgres pg_dump -U <db-user> -Fc <db-name> > nexusdav.dump

# SQLite. Run inside the app container as nexusdav, so no root-owned file lands next to the
# database. Do not copy the live database file. The image includes the sqlite3 command-line tool.
docker compose -f compose.yaml -f compose.sqlite.yaml exec -u nexusdav nexusdav sqlite3 /data/database/nexusdav.sqlite ".backup '/tmp/nexusdav.sqlite'"
docker compose -f compose.yaml -f compose.sqlite.yaml cp nexusdav:/tmp/nexusdav.sqlite ./nexusdav.sqlite
```

Keep at least three copies on two kinds of storage, with one off-site, and encrypt remote copies. Test a restore on a clean machine regularly.

**Restore**

1. Stop the stack.
2. Restore the database and the data volume.
3. Start the stack, log in and sync one client to check.

**Server settings**

Settings changed in the admin panel are stored in the database, so a database backup already contains them. For moving settings between installs, or keeping a copy that is separate from user data, the **Settings backup** section at the bottom of **Configuration** has two buttons (1.6.0):

- **Export settings** downloads `nexusdav-settings-<host>-<date>.json` with the settings of the Settings and Configuration pages. A setting at its default is written as `null`, so importing the file elsewhere keeps that server's defaults instead of fixing today's. Settings set through environment variables stay in `.env` and are not in the file. Secrets are left out (none of today's settings is a secret; the SMTP password of Version 2.x will be), and so are the WebDAV-Push keys, users, calendars and contacts.
- **Import settings** takes such a file (at most 256 KB) and shows what would change, with the current and the new value, before anything is saved. **Apply** saves the changes in one step; the worker picks them up before its next job. Settings that cannot be imported are listed with the reason and skipped: set by the environment on this server, not valid here (for example a language this server does not have), unknown to this version, or **Secure cookies** while the panel is open over plain HTTP. A file that is not a settings file, or that comes from a newer NexusDAV, is refused as a whole.

The log gets an `INFO` line `Settings exported` and a `NOTICE` line `Settings imported` with the admin and the names of the changed settings (never their values).

Export the settings before an upgrade and keep the file with your other backups. It does not replace a database backup: it contains no users, calendars or contacts.

**Upgrade**

1. Back up first.
2. Read the release notes, then change the tag on the `image:` line in `compose.yaml` (for example from `0.5.0` to `1.6.0`). Keep an exact version rather than `latest`, so an upgrade happens only when you choose it.
3. Run `docker compose pull` and `docker compose up -d`, with the same `-f` files as at install. Database migrations run automatically on start. With `compose.build.yaml`, update the checkout instead and run `up -d --build`.
4. Upgrade one minor version at a time; 1.6.0 is the release after 0.5.0. There is no rollback other than restoring the backup.
5. **Files is off after an upgrade to 0.4.0** until an admin switches it on under **Settings → Files** (see [Files](#files)). The upgrade needs no migration and changes no setting.
6. **WebDAV-Push is off after an upgrade to 0.5.0** until an admin switches it on under **Settings → WebDAV-Push**, and it needs the queue worker (see [WebDAV-Push](#webdav-push)). Set the new image tag on the worker too. The upgrade adds tables for the worker and push; nothing else changes.
7. **Upgrading to 1.6.0** needs no migration and changes no setting. It adds **Settings backup** and **Danger Zone** at the bottom of **Configuration**. Their hand pass (export and import, and a reset with clients, the worker and WebDAV-Push afterwards) passed on 1.6.0+65ad562 on PostgreSQL and SQLite ([admin testing](testing/admin-testing.md#results)).

**PostgreSQL major version changes** (for example 17 → 18): the official Postgres images changed their data directory layout starting with 18 (a single volume mount, with data stored under a version-specific subdirectory). An existing 17 data volume cannot be reused as-is by an 18 image. `pg_dump` your database first, then either keep using the matching Postgres major version, or follow [upstream's upgrade guidance](https://github.com/docker-library/postgres/issues/37) (`pg_upgrade` or dump-and-restore into a fresh volume) before switching image tags.

**Switching databases:** not supported in Version 1 (see [Database](#database)). Choose before the first start.

Automatic backups are a long-term goal (see [Roadmap](#roadmap)).

### Health check and logging

- **Health check:** `GET /health` is unauthenticated. It returns `{"status":"ok"}` when the application and the database are up, and `{"status":"unavailable"}` with HTTP 503 otherwise. It does not include versions, paths, or SQL. Compose uses it as the container healthcheck. A healthy check is not logged, so the log does not fill with a line every 30 seconds; a failing one is. The admin Dashboard shows the same database check. Richer checks inside the admin panel are Version 2+.
- **Logs:** written to stderr, so `docker compose logs` works. The image sets `LOG_CHANNEL=stderr`. Nothing is written to `storage/logs` inside the container. `LOG_LEVEL` defaults to `debug` when unset. Use `info` or higher in production. Log lines show the time in the server time zone (Configuration).
- **What is logged:**
  - **One line per request,** with the method, the path without the query string, the status, the signed-in user (DAV or admin panel, otherwise `null`), the client address and the duration. When the DAV server refuses a request with a 4xx status (other than 401 and 404), the line also has a `reason`, for example `"reason":"Validation error in vCard: The supplied value (…) is not a correct DATE-AND-OR-TIME property"` for a contact the server cannot accept. Values from the request body are masked as `(…)` or `"…"`. The level follows the status: `ERROR` for 5xx, `WARNING` for 4xx, and `INFO` for the rest. A 401 is `INFO`, because clients get one on every first contact before they send the password; a rejected password has its own `WARNING` line. For example:

    ```text
    2026-11-01 12:49:55 INFO Request {"method":"REPORT","path":"/dav/calendars/ada/default/","status":207,"user":"ada","ip":"192.168.1.20","duration_ms":5.5} {"request_id":"1d451d2e7b720a2b"}
    ```

    Every line starts with the time and the level, so log viewers such as Dozzle show errors and warnings in their own color and can filter by level. Up to 0.1.4 the line started with `[time] production.INFO:`, which those viewers could not read. Set `LOG_STDERR_FORMATTER` to a Monolog formatter class, for example `Monolog\Formatter\JsonFormatter`, for a different format.

    `/health` and `/up` get a line only when they fail. Static files (CSS, fonts) are served without PHP and are not logged.
  - **A request ID** on every line written during the request, in the `X-Request-Id` response header, and on the request's Diagnostics row (the **Request ID** column). To see what happened around a DAV error, copy its ID from Diagnostics and search for it: `docker compose logs nexusdav | grep <id>`. A Diagnostics row that counts repeats keeps the ID of the first one.
  - **Unexpected errors** inside a DAV request, with the stack trace.
  - **Rejected logins** (admin or DAV), with source, IP, username, path, status, and reason. They are also stored in `diagnostic_events`. One address gets at most 10 rows per minute; further failures from it are counted on one `many usernames` row and logged once.
  - DAV answers with status 400 or higher (except 401) are stored the same way as `http_error` rows; their request line is the log entry. Both kinds are listed on the admin panel's Diagnostics page and summarised on the Dashboard. A DAV request that sends no credentials is not stored.
  - **Push:** subscriptions registered, renewed and removed, and messages that could not be delivered, at **Push log level**. Only the push service's host is logged.
  - **Never logged or stored:** passwords, app password secrets, the `Authorization` header, other request headers, query strings and request bodies, and push service addresses or keys.
- **Rotation:** Compose sets `max-size` `10m` and `max-file` `3`.
- **Caching:** at each start the container caches config, routes, events, views and Filament components (`php artisan optimize`) and serves PHP with OPcache. Changing an environment variable takes a restart, which happens anyway when Compose recreates the container.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Client says "401 Unauthorized" | The main password was used instead of an app password, or the app password was revoked. |
| A user is refused for a minute even with the right app password | Someone sent several bad passwords for that username, or one address sent more than 20 bad passwords for any usernames. With `TRUSTED_PROXIES=*` behind a reverse proxy, every client counts as the proxy's address; leave it unset or list the proxy (see [Environment variables](#environment-variables)). |
| Entering only the domain finds nothing | The proxy is not forwarding `/.well-known/caldav` and `/.well-known/carddav` to the app, and it has no fallback redirect. |
| Some operations fail (creating calendars, editing events) | The proxy blocks DAV methods such as `PROPFIND`, `REPORT`, `MKCALENDAR`. |
| Redirects or links use `http://` | `APP_URL` is not the public HTTPS address, or `X-Forwarded-Proto` is not forwarded. |
| The admin panel signs you out right after you sign in | Secure session cookies are on and the panel was opened over plain HTTP. Open it over HTTPS, or set `SESSION_SECURE_COOKIE=false`. Secure cookies are on by default when `APP_URL` starts with `https://`. |
| Large file upload fails | 413 from the app: the file is over Maximum upload size (Settings → Files). 413 from the proxy, a reset connection or a timeout: the proxy's body limit or timeouts are too low (nginx allows 1 MB by default; the files in [docs/proxy](proxy/) allow 128 MB). 507: the data volume is full. See [Large file uploads](#large-file-uploads-files). |
| `/dav/files/` answers 403 "Files is turned off on this server." | Files is off. Switch it on under **Settings → Files**. |
| GNOME Files shows "Operation not supported" after a copy and paste | The copy is complete. Choose **Skip**; Retry makes a second copy (see [Files](#files)). |
| PostgreSQL log shows `ERROR: relation "settings" does not exist` on the first start | Expected on a fresh install, not a fault. The app reads the saved settings at boot, before the migrations have created the table, and falls back to the defaults. The error stops appearing once the migrations have run. |
| The Dashboard says WebDAV-Push is on but the queue worker is not running | The worker is not started, or stopped. Start it (see [Queue worker](#queue-worker-optional)); waiting messages are sent then. Diagnostics shows its last heartbeat. |
| DAVx⁵ does not subscribe (no push subscriptions on Diagnostics) | Push is off, DAVx⁵ has no push service (**Settings → Push**: install ntfy or use FCM), or DAVx⁵ is older than 4.4.10. Sync the account after choosing the push service. |
| Push subscriptions show **Failing** | The push service refused the message; the status shows its answer. A subscription that keeps failing is removed after one to three days. |
| The log says a push subscription was removed because its push service address is not allowed | The push service is on a private network (a LAN ntfy). Turn on **Allow private networks**. |
| You want to know what a status code on Diagnostics means | The (i) button on **Latest DAV errors** lists the codes the server returns, what each means here and what to check. |
| A DAV error is listed on Diagnostics and you need more detail | Search the log for its Request ID: `docker compose logs nexusdav \| grep <id>`. |
| Tasks or notes are not visible | The client app does not display them (see [Client compatibility](#client-compatibility)). |
| The first contact sync in DAVx5 takes many minutes | DAVx5 saves each contact into Android's contacts storage one at a time, about 0.5 s per contact on a Pixel 7 Pro: roughly 10 minutes for 2,000 contacts. The server's part is a few seconds. Later syncs fetch only changes and are quick. Calendars and other clients (Thunderbird, iPhone, iPad) are not affected ([#1](https://github.com/offsyanka99/NexusDAV/issues/1)). |
| A client reports 503 "The server is busy" during a large import | Many writes arrived at once, and one waited for the database longer than the server retries (about 5 s per try, four tries). Nothing was saved for that item; the client sends it again later (`Retry-After: 5`). It happens mostly on SQLite with many parallel uploads; PostgreSQL handles them better. |
| A client syncs everything again after being offline for a long time | The server keeps the change history for **Keep sync history for (days)** (default 90). A client whose last sync is older gets a 403 `valid-sync-token` and takes a full sync, which ends with exactly the server's data. Raise the setting if clients stay offline longer. |
| An edit made on one device is gone after the other device syncs | Both devices changed the same item. The first to reach the server wins; the server refuses the second write (412 Precondition Failed, listed on Diagnostics) because the item changed after that device last synced. Clients show no message and take the server's version on their next sync. Edit the item again on the second device. |
| A calendar deleted and created again with the same name still shows its old events on an iPad or iPhone | Apple Calendar keeps its local copy when the server refuses its old sync token, and shows those events in the new calendar. The server never receives them again. Remove the calendar account and add it again. DAVx5 and Thunderbird drop the old events themselves ([DAV endpoints](dav-endpoints.md#sync-tokens)). |
| DAVx5 does not use sync tokens for a calendar | **Time range (past days)** is set, even to `0`: DAVx5 then lists the whole calendar on every sync instead. That is correct, only slower for big calendars. Leave the field empty to use sync tokens. |
| A calendar created from an iPad or iPhone has no tasks or notes on the server | The client chooses what a new calendar holds when it creates it, and Apple Calendar asks for events only. Open the calendar under **Edit** on the user's Calendars tab and turn on Tasks and Notes. |
| A calendar or address book deleted and created again with the same name still shows its old name in the client | Clients keep the name they first saw. Refresh the collection list (DAVx5: **Refresh list**) or add the calendar again in Thunderbird. The contents are already the new ones. |
| Imported contacts do not upload: their PUT answers 415 | The cards are not valid vCards, and the log line's `reason` says why. A common one is `UID MUST NOT appear more than once in a VCARD component` (or `PRODID`): some exports copy a card into a new one and keep both lines. Repair the file ([Migrating from another server](#migrating-from-another-server)), delete the contacts that did not upload in the client, and import the repaired file. |

### Migrating from another server

Export calendars as `.ics` and contacts as `.vcf` from your current server (Radicale, Baïkal, Nextcloud) and keep them as a backup. Import into NexusDAV is planned for Version 2; until then you can import the files through a client that is already connected to NexusDAV.

The server refuses a contact with two `UID` or two `PRODID` lines (415), as CardDAV requires. Exports that were converted by another sabre-based tool can have them: a `UID:sabre-vobject-…` line next to the card's own UID. This command keeps one `PRODID` per card and drops the `sabre-vobject-` UID only when the card has another one, so each card keeps exactly one UID:

```bash
awk '
/^BEGIN:VCARD/ { n = 0; uids = 0 }
{ line[++n] = $0 }
/^UID[;:]/ { uids++ }
/^END:VCARD/ {
  prodid = 0
  for (i = 1; i <= n; i++) {
    if (line[i] ~ /^PRODID[;:]/ && prodid++) continue
    if (uids > 1 && line[i] ~ /^UID:sabre-vobject-/) continue
    print line[i]
  }
  n = 0; next
}
n == 0 { print }
' contacts.vcf > contacts-fixed.vcf
```

## Roadmap

### Version 1 (minimal viable server)

- Minimal admin panel only (no user portal)
- Create and manage users
- Authentication: Argon2id user passwords and app passwords / personal access tokens (for DAVx5, Thunderbird and other clients)
- Rate limiting and brute-force protection for login and DAV (Basic Auth) endpoints
- A simple Diagnostics page in the admin panel: recent failed logins, latest DAV errors and an app password overview
- The app serves `/.well-known/caldav` and `/.well-known/carddav`. Documented proxy fallbacks cover proxies that swallow those paths
- Admin panel creates users and collections (calendars and address books, including collections that hold tasks and notes)
- Events, tasks (VTODO) and notes (VJOURNAL) are written by CalDAV clients
- Basic sync working with DAVx5 (and ideally other clients)
- Core DAV protocols (WebDAV, CalDAV, CardDAV)
- Docker Compose setup: base file + PostgreSQL / SQLite overrides

### Version 1.x

- Basic Sharing and ACL: an admin shares a calendar or address book with another user, read-only or read-write. **Released in 0.3.0**
- WebDAV-Push, with an on/off toggle in the admin panel settings and its subscriptions on the Diagnostics page. **Released in 0.5.0**
- Multilingual admin panel (English and Russian, more as translation files). **Released in 0.1.2**
- Files (WebDAV file storage). **Released in 0.4.0**
- Improved sync reliability
- Export and import of server settings (JSON) on the Configuration page. **Released in 1.6.0**
- Danger Zone: Reset to Default, a factory reset that keeps the admin who runs it. **Released in 1.6.0**

### Version 2

- Basic user portal
- On-server CalDAV scheduling (invitations and free/busy between users on this server). No outbound email yet
- Import / Export (ICS, VCF)
- Search across events and contacts
- Holidays calendar, custom color for a single event, and duplicate contact detection and merge

### Version 2.x

- Outbound email through an external SMTP server: invitations to people who are not users, reminders, account notices (including login and lockout mail, each with an on/off option), share notices and reports. Replies are not read
- Managed attachments
- User quotas
- Logs / audit trail and health checks
- Automatic backups (local + remote)
- Multilingual user portal
- Optional 2FA
- Trash and restore of deleted items (including files in the user portal), direct import from Radicale, Baïkal and Nextcloud, and editing office documents in the browser
- A mobile-friendly user portal with dark mode, and an admin panel optimised for mobile
