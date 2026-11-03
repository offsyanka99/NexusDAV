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

Version 1 is built. Releases are published as `ghcr.io/offsyanka99/nexusdav` for amd64 and arm64. The current release is `0.1.4` (first release `0.1.0`). 0.1.2 added the first Version 1.x milestone, an admin panel in English and Russian. The server passes automated sync tests with the vdirsyncer and python caldav clients, and 0.1.1 was tested by hand with DAVx5 and Thunderbird; Apple clients have not been tried by the project yet (see [Client compatibility](#client-compatibility)).

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

1. Get the Compose files and create your environment file. The Compose files pull the published image, `ghcr.io/offsyanka99/nexusdav:0.1.4`:

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

   To build the image from the checkout instead, add `-f compose.build.yaml` as the last file and `--build`, for example `docker compose -f compose.yaml -f compose.sqlite.yaml -f compose.build.yaml up -d --build`.

4. Log in to the admin panel at `http://localhost:8080/admin` (or your `APP_URL`) with the admin credentials from step 2, then create your first user. The user gets a default calendar and address book; add more from the user page. The admin panel creates users and collections. Events, tasks and notes are written by a CalDAV client, not in the admin panel.

5. Connect a client (DAVx5, Thunderbird, Apple Calendar / Contacts) using your server URL and the user's **app password**. See [Client setup and compatibility](#client-setup-and-compatibility). The app redirects `/.well-known/caldav` and `/.well-known/carddav` to `/dav/`. Some proxies never forward those paths; the fallback is in [Reverse proxy and `.well-known`](#reverse-proxy-and-well-known).

Remove `ADMIN_PASSWORD` from `.env` after the first login. If you lose access to the admin account later, see [Resetting the admin password](#resetting-the-admin-password).

### Installing on TrueNAS SCALE

TrueNAS SCALE 24.10 (Electric Eel) and later run Apps on Docker, so a Docker Compose file can be installed directly as a **Custom App**:

1. Create a dataset for NexusDAV's data: **Storage > Datasets > Add Dataset**, for example `tank/apps/nexusdav`. With PostgreSQL, also create a second dataset for its data, for example `tank/apps/nexusdav/postgres`. Keep both on a pool local to this machine. SQLite needs a local filesystem; a dataset mounted from another machine over the network does not qualify (see [Database](#database)).
2. Copy [docs/truenas/custom-app.sqlite.yaml](truenas/custom-app.sqlite.yaml) (good for a single NAS) or [docs/truenas/custom-app.postgres.yaml](truenas/custom-app.postgres.yaml) (recommended for more than a couple of users).
3. Edit every line marked `CHANGE ME`: the dataset path(s) from step 1, `APP_URL`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, and the host port if `8080` is already used by another app. The file has no separate place for environment variables or a `.env` file, so values are written directly into it.
4. **Apps > Discover Apps**, then **Custom App** (top right) **> Install via YAML**. Paste the edited file and install.
5. Continue from step 4 of [Quick start](#quick-start).

`PUID` and `PGID` default to `568`, the UID TrueNAS SCALE apps conventionally run as, so the dataset's ownership matches what the container expects. Put it behind an HTTPS reverse proxy before exposing it outside your network — Nginx Proxy Manager and Caddy are themselves TrueNAS SCALE apps, or use one of the files in [docs/proxy](proxy/).

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

**Required PHP extensions:** `ctype`, `curl`, `date`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_pgsql`, `pdo_sqlite`, `session`, `simplexml`, `tokenizer`, `xml`, `zip`.

`intl` is required by Filament. PHP must also be built with Argon2 support (`password_hash` with `PASSWORD_ARGON2ID`); the official PHP Docker images include it.

**WebDAV-Push:** sabre/dav does not provide this. Version 1.x implements the bitfire draft [bitfireAT/webdav-push](https://github.com/bitfireAT/webdav-push) at commit `611abe2e8a0cdd7570cb936844b6588f7145b27d` (`draft-bitfire-webdav-push-00`). NexusDAV stays on that commit until the project publishes a release, then switches to the release. It is switched on or off in the admin panel.

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
compose.yaml            # application (pinned image), shared volumes, ports
compose.postgres.yaml   # adds the postgres service and DB_* variables
compose.sqlite.yaml     # sets DB_CONNECTION=sqlite and the database file path
compose.build.yaml      # optional, last: builds the image from this checkout
```

Reverse proxy overrides (Caddy, Traefik) are in [docs/proxy](proxy/); see [Reverse proxy and `.well-known`](#reverse-proxy-and-well-known).

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
| `NEXUSDAV_VERSION` | No | set at build | No | Shown on the Dashboard. The image sets it from the `NEXUSDAV_VERSION` build argument: a release uses its version (for example `0.1.4`), CI uses `ci+<commit>`, and a local build shows `dev`. |

Secrets can also be supplied as files (for example `DB_PASSWORD_FILE`) for use with Docker secrets; this is planned. The application stops at start with a clear message if a required variable is missing.

If you lose access to the admin account, see [Resetting the admin password](#resetting-the-admin-password).

### Settings in the admin panel

These settings are changed on the **Settings** and **Configuration** pages. A change applies from the next request, with no restart. Each has an environment variable; when it is set, that value wins and the panel shows the field locked. Leave the variable unset to manage the setting in the panel. With Docker, add a variable to the `environment:` list of the `nexusdav` service in `compose.yaml`.

| Setting | Page | Variable | Default |
|---|---|---|---|
| CalDAV on or off | Settings | `NEXUSDAV_CALDAV` | on |
| CardDAV on or off | Settings | `NEXUSDAV_CARDDAV` | on |
| Tasks (VTODO) on or off | Settings | `NEXUSDAV_TASKS` | on |
| Notes (VJOURNAL) on or off | Settings | `NEXUSDAV_NOTES` | on |
| Log level | Settings | `LOG_LEVEL` | `debug` |
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

What the service switches do:

- **CalDAV or CardDAV off:** paths under `/dav/calendars/` or `/dav/addressbooks/` answer 403 with "CalDAV is turned off on this server." (or CardDAV). Discovery no longer offers the home. Stored data is kept and comes back when the service is turned on.
- **Tasks or Notes off:** calendars stop listing `VTODO` or `VJOURNAL` as supported, so no task or note can be created or changed. Existing ones stay readable and can be deleted.

**Language.** The admin panel ships in English and Russian. Each admin chooses a language under **Language** in the user menu; **Server default** clears the choice and follows the Configuration setting. The login page always uses the server default. A value of `NEXUSDAV_LOCALE` that is not a shipped language falls back to English. Only the panel is translated: DAV responses, `/health`, the container log and the reasons stored for Diagnostics stay in English, so log searches keep working; the Diagnostics page shows those reasons in the admin's language. To add a language, see [CONTRIBUTING.md](../CONTRIBUTING.md#translations).

The server time zone applies to users without their own time zone, becomes the `calendar-timezone` of new calendars (the owner's own zone wins when set), and is the zone the panel shows times in. Turning on secure cookies is refused unless the panel is open over HTTPS, so an admin cannot sign themselves out by accident.

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

### Authentication

- Main user passwords: **Argon2id**
- **App passwords / personal access tokens** (for DAVx5, Thunderbird and other clients), available from Version 1. An app password is not limited to one service: it works for calendars and contacts, and from Version 1.x also for the user's files, so give each device its own and revoke it when the device is lost
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
2. **A DAV-aware proxy.** The proxy must pass through all HTTP methods used by DAV (`PROPFIND`, `PROPPATCH`, `REPORT`, `MKCOL`, `MKCALENDAR`, `COPY`, `MOVE`, `LOCK`, `UNLOCK`) and the `Authorization` header, forward the `Host` and `X-Forwarded-Proto` headers, and allow request bodies up to 128 MB, the app's own limit (vCards with photos, events with attachments). `APP_URL` must match the public HTTPS address. A proxy in the same Docker network or LAN is trusted by default; otherwise set `TRUSTED_PROXIES` (see [Environment variables](#environment-variables)).

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

## Client setup and compatibility

### Client compatibility

NexusDAV targets standards-compliant clients. The table shows the Version 1 targets and what has been tested so far. Reports for other clients and versions are welcome (see [CONTRIBUTING.md](../CONTRIBUTING.md)). The steps the project runs by hand, and their results per client and database, are in the [client checklist](testing/client-checklist.md).

| Client | CalDAV (events) | CardDAV (contacts) | Tasks (VTODO) | Notes (VJOURNAL) | Status |
|---|---|---|---|---|---|
| **DAVx5** (Android) | Yes | Yes | Yes, via a tasks app (jtx Board, Tasks.org, OpenTasks) | Yes, via jtx Board | Primary target; tested for 0.1.1 (4.5.21): calendar, contacts, tasks and notes via jtx Board |
| **Thunderbird** | Yes | Yes | Yes | No (not supported by the client) | Target; tested for 0.1.1 (159.0): calendar and contacts. Tasks not tested |
| **Apple Calendar / Contacts** (macOS, iOS) | Yes | Yes | Yes, in Reminders (not in Calendar) | No | Target; not tested yet |
| **vdirsyncer** 0.21.0 | Yes | Yes | Synced as files | Synced as files | Tested for 0.1.1 (automated) |
| **python caldav** 3.7.0 | Yes | — | Yes | Yes | Tested for 0.1.1 (automated) |
| Other standards-compliant clients | Expected | Expected | Client-dependent | Client-dependent | Best effort |

**What was tested by hand** (0.1.1, PostgreSQL 18 behind Caddy over HTTPS, November 2026):

- **DAVx5 4.5.21** on a Pixel 7 Pro and a Pixel 10 Pro, both on Android 17: calendar, contacts, tasks and notes, the last two through jtx Board.
- **Thunderbird 159.0** (Snap package on Ubuntu): calendar and contacts. Tasks were not tested.
- **Not tested yet:** Apple Calendar, Contacts and Reminders, and Tasks.org or OpenTasks with DAVx5.

0.1.2 and 0.1.3 changed only the admin panel (languages) and a test tool. The full [client checklist](testing/client-checklist.md) was run on 0.1.3 with the same clients on PostgreSQL and SQLite: everything passed except a calendar deleted and created again with the same name while a client was offline (the client kept the old events). 0.1.4 fixes that in the server's sync tokens (see [DAV endpoints](dav-endpoints.md#sync-tokens)); the re-check with the clients is pending.

**What was tested by automated clients** (SQLite, November 2026; recorded on 0.1.0 and unchanged for 0.1.1 to 0.1.3):

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

- **DAVx5:** add an account, choose *Login with URL and user name*, enter the URL and credentials. Install a tasks app (jtx Board, Tasks.org) to see tasks and notes.
- **Thunderbird:** add a calendar (*New Calendar > On the Network > CalDAV*) and an address book (*New Address Book > CardDAV*).
- **Apple Calendar / Contacts:** add an account of type *Other* (CalDAV / CardDAV account).

The steps for DAVx5 and Thunderbird were checked against the versions in [Client compatibility](#client-compatibility). The Apple steps are not verified yet.

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
| Files (WebDAV, Version 1.x) | `/dav/files/<username>/` |

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
| WebDAV-Push (bitfire draft, on/off in the admin panel; not a sabre/dav plugin) | Version 1.x |
| CalDAV Scheduling, on-server invitations and free/busy | Version 2 |
| Outbound email (external invitations, notices, reminders, reports) | Version 2.x |
| Files (WebDAV file storage) | Version 1.x |
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
- Export and import of server settings (JSON)

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

The database backup commands below were run against the image. The restore steps are not tested yet, and the settings export does not exist yet.

**What to back up**

- The database and the data volume, taken at the same time (or with the application briefly stopped) so they stay consistent. The data volume holds `APP_KEY` (`/data/app.key`).
- `.env`. It holds `APP_URL`, the database password and the other boot settings. `APP_KEY` is not in it, so keep a separate copy of `/data/app.key`: without it, encrypted values cannot be read after a restore. Store that copy apart from the database backup.

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

Settings changed in the admin panel (SMTP, quotas, defaults, etc.) are stored in the database, so a database backup already contains them. For moving settings between installs, or keeping a copy that is separate from user data, the admin panel will offer:

- **Export settings:** downloads a JSON file with the server settings. Values set through environment variables stay in `.env` and are not exported. Secrets such as the SMTP password are left out, or the file is encrypted; they must be re-entered or the file passphrase supplied on import.
- **Import settings:** uploads that file. The admin sees a summary of the changes and confirms before they are applied. Import is refused when the file comes from an incompatible version.

Export the settings before an upgrade and keep the file with your other backups. It does not replace a database backup: it contains no users, calendars or contacts.

**Upgrade**

1. Back up first.
2. Read the release notes, then change the tag on the `image:` line in `compose.yaml` (for example from `0.1.3` to `0.1.4`). Keep an exact version rather than `latest`, so an upgrade happens only when you choose it.
3. Run `docker compose pull` and `docker compose up -d`, with the same `-f` files as at install. Database migrations run automatically on start. With `compose.build.yaml`, update the checkout instead and run `up -d --build`.
4. Upgrade one minor version at a time. There is no rollback other than restoring the backup.

**PostgreSQL major version changes** (for example 17 → 18): the official Postgres images changed their data directory layout starting with 18 (a single volume mount, with data stored under a version-specific subdirectory). An existing 17 data volume cannot be reused as-is by an 18 image. `pg_dump` your database first, then either keep using the matching Postgres major version, or follow [upstream's upgrade guidance](https://github.com/docker-library/postgres/issues/37) (`pg_upgrade` or dump-and-restore into a fresh volume) before switching image tags.

**Switching databases:** not supported in Version 1 (see [Database](#database)). Choose before the first start.

Automatic backups are a long-term goal (see [Roadmap](#roadmap)).

### Health check and logging

- **Health check:** `GET /health` is unauthenticated. It returns `{"status":"ok"}` when the application and the database are up, and `{"status":"unavailable"}` with HTTP 503 otherwise. It does not include versions, paths, or SQL. Compose uses it as the container healthcheck. A healthy check is not logged, so the log does not fill with a line every 30 seconds; a failing one is. The admin Dashboard shows the same database check. Richer checks inside the admin panel are Version 2+.
- **Logs:** written to stderr, so `docker compose logs` works. The image sets `LOG_CHANNEL=stderr`. Nothing is written to `storage/logs` inside the container. `LOG_LEVEL` defaults to `debug` when unset. Use `info` or higher in production.
- **What is logged:**
  - **One line per request,** at `info`, with the method, the path without the query string, the status, the signed-in user (DAV or admin panel, otherwise `null`), the client address and the duration. For example:

    ```text
    [2026-11-01 12:49:55] production.INFO: Request {"method":"REPORT","path":"/dav/calendars/ada/default/","status":207,"user":"ada","ip":"192.168.1.20","duration_ms":5.5} {"request_id":"1d451d2e7b720a2b"}
    ```

    `/health` and `/up` get a line only when they fail. Static files (CSS, fonts) are served without PHP and are not logged.
  - **A request ID** on every line written during the request, in the `X-Request-Id` response header, and on the request's Diagnostics row (the **Request ID** column). To see what happened around a DAV error, copy its ID from Diagnostics and search for it: `docker compose logs nexusdav | grep <id>`. A Diagnostics row that counts repeats keeps the ID of the first one.
  - **Unexpected errors** inside a DAV request, with the stack trace.
  - **Rejected logins** (admin or DAV), with source, IP, username, path, status, and reason. They are also stored in `diagnostic_events`. One address gets at most 10 rows per minute; further failures from it are counted on one `many usernames` row and logged once.
  - DAV answers with status 400 or higher (except 401) are stored the same way as `http_error` rows; their request line is the log entry. Both kinds are listed on the admin panel's Diagnostics page and summarised on the Dashboard. A DAV request that sends no credentials is not stored.
  - **Never logged or stored:** passwords, app password secrets, the `Authorization` header, other request headers, query strings and request bodies.
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
| Large file upload fails | The proxy request-body limit is too low. nginx allows 1 MB by default; the files in [docs/proxy](proxy/) allow 128 MB, the app's limit. |
| A DAV error is listed on Diagnostics and you need more detail | Search the log for its Request ID: `docker compose logs nexusdav \| grep <id>`. |
| Tasks or notes are not visible | The client app does not display them (see [Client compatibility](#client-compatibility)). |

### Migrating from another server

Export calendars as `.ics` and contacts as `.vcf` from your current server (Radicale, Baïkal, Nextcloud) and keep them as a backup. Import into NexusDAV is planned for Version 2; until then you can import the files through a client that is already connected to NexusDAV.

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

- Basic Sharing and ACL: an admin shares a calendar or address book with another user, read-only or read-write
- WebDAV-Push, with an on/off toggle in the admin panel settings and its subscriptions on the Diagnostics page
- Multilingual admin panel (English and Russian, more as translation files). **Released in 0.1.2**
- Files (WebDAV file storage)
- Improved sync reliability

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
- Export and import of server settings (JSON)
- Trash and restore of deleted items (including files in the user portal), direct import from Radicale, Baïkal and Nextcloud, and editing office documents in the browser
- A mobile-friendly user portal with dark mode, and an admin panel optimised for mobile
