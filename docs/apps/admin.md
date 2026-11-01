# Admin panel

The admin panel is the operator's interface, built with Filament. It manages users, collections, and server settings. It is not the user portal. The user portal is a separate personal interface and arrives in Version 2. Until then, every management task goes through the admin panel.

A person signs in to the admin panel with the admin account. CalDAV and CardDAV clients do not use that password. They use an app password created here.

The first admin is created on first start from `ADMIN_EMAIL` and `ADMIN_PASSWORD`. Remove `ADMIN_PASSWORD` from `.env` after the first login. `APP_KEY` is not in `.env`. It is generated on first start and stored on the data volume.

## After sign-in

Sign-in lands on the **Dashboard**. The Dashboard shows the server at a glance: server status, which services are running, and statistics. Later versions add detail to this page, such as storage numbers and fuller health checks. Version 1 still opens here, with the status that exists at that point. A UI draft is kept in [docs/design/admin-dashboard.md](../design/admin-dashboard.md).

A menu links to the other admin pages. The items include **Settings**, **Configuration**, **Users** and **Diagnostics**, plus the other pages described below as they arrive. Whether that menu sits on the left or along the top is not decided yet.

### Settings

The Settings page holds system-level controls for services and related options. A UI draft is kept in [docs/design/admin-settings.md](../design/admin-settings.md).

**Version 1.**
- Enable or disable CalDAV, CardDAV, Tasks (VTODO) and Notes (VJOURNAL). A service that is off answers 403 on DAV and keeps its data. With Tasks or Notes off, calendars stop offering that component: existing items stay readable and can be deleted, new ones are refused.
- Logging level for the server.
- A field whose environment variable is set (for example `NEXUSDAV_CALDAV` or `LOG_LEVEL`) is read-only, with a lock. The [guide](../guide.md#settings-in-the-admin-panel) lists every variable.

**Version 1.x.**
- Enable or disable WebDAV (files) and WebDAV-Push, and configure their options.
- Logging for WebDAV-Push.
- Enable or disable Sharing.
- File size limits and per-user disk space limits.
- Session and portal-related settings appear once the user portal exists (Version 2).

**Version 2.**
- Enable or disable on-server Scheduling.

**Version 2.x.**
- Enable or disable Managed Attachments.

### Configuration

The Configuration page holds server-wide preferences. A UI draft is kept in [docs/design/admin-configuration.md](../design/admin-configuration.md).

**Version 1.**
- Server time zone, time format and week start day. The time zone applies to users without their own, to new calendars, and to times shown in the panel.
- Rate limiting settings: failures per username, failures per address, and the window.
- Session settings: lifetime (minutes of inactivity) and secure cookies. Secure cookies are on by default when `APP_URL` is `https`, and can be turned on only over HTTPS.
- How long failed logins and DAV errors are kept for Diagnostics.
- Changes apply from the next request, with no restart. Locked fields work as on Settings.

**Version 1.x.**
- Default interface language.

**Version 2.x.**
- Backup and restore settings for configuration and data.
- Health check settings.
- SMTP settings and the From address used for outbound email.

On the same page, a **Danger Zone** section (Version 2.x) contains **Reset to Default**. This performs a full factory reset: configuration, database (all users and data), WebDAV files and the install lock. The action requires one or two explicit confirmations before it runs.

### Users

The Users page lists all accounts. An administrator can search the list by username, display name or email, create a new user, and edit, enable, disable or delete an existing one.

Selecting a user shows their basic information together with the calendars and address books that belong to them. From there the administrator can create a new calendar or address book for that user.

Creating a calendar includes choosing a URI, display name, description, color, and whether the collection holds tasks (VTODO) or notes (VJOURNAL). Creating an address book includes a URI, display name and description.

A more detailed UI draft is kept in [docs/design/admin-users.md](../design/admin-users.md).

### Diagnostics

The Diagnostics page shows operational status and recent problems. Its heading gives the last 24 hours' failed logins and DAV errors, the same numbers as the Dashboard's Needs attention card. It helps an administrator see why a client cannot sync or why a notification did not go out. A simple version arrives in Version 1 and grows in later versions.

**Version 1**
- Recent failed login attempts to the admin panel and the DAV endpoint (time, IP, user, reason). Passwords and app password secrets are never stored or shown
- Latest DAV errors (4xx/5xx responses on `/dav/*`, with method, path, status, user and, for rejected logins, the reason such as revoked app password or main password used). A request without credentials is not an error and is not listed
- A Request ID on each row (shown for DAV errors, optional for failed logins). Searching the container log for it shows that request's log lines, including any error detail
- App passwords across all users (user, device, created, last used, revoked or expired; can be revoked)

**Version 1.x**
- WebDAV-Push subscriptions (user, collection, endpoint, last push, status)

**Version 2.x**
- Collections without recent sync (no requests for a configurable period, for example 30 days)
- Quotas and usage per user (size of calendars, contacts and files; remaining space)
- Extended health check (database, disk, queue, SMTP, WebDAV-Push worker)
- Mail queue (messages in failed or retrying state)
- User log filter (filter server logs by username or principal)
- Backups (last successful backup, size, errors)

## What each version includes

**Version 1.**

- Sign in, and reset a lost admin password from the container shell. The reset is logged. It signs out existing sessions and can revoke app passwords. It does not send email.
- Create and disable users. Passwords are stored with Argon2id.
- Create an app password for a user, one per device. The main password is not accepted on the DAV endpoint.
- Rate limiting and brute-force protection on the login form and on DAV Basic authentication.
- A simple Diagnostics page: recent failed logins, latest DAV errors and an overview of app passwords. Failed logins and DAV errors are kept for a limited time and then pruned.
- Create collections: calendars and address books, including collections that hold [tasks](tasks.md) or [notes](notes.md).
- It does not edit events, tasks, notes, or contacts. Clients write those.
- Choose the database before the first start, PostgreSQL or SQLite. The panel cannot switch it later.
- Server settings that are not required to boot live in the database. A setting that is also an environment variable stays read-only in the panel, and the environment value wins.

**Version 1.x.**

- Share a calendar or an address book and set the ACL.
- Turn WebDAV-Push on or off, and view its subscriptions on the Diagnostics page. The implementation is the bitfire draft named in the [guide requirements](../guide.md#requirements), not a sabre/dav plugin.
- Use the panel in more than one language.
- See that a user's [Files](files.md) home exists, once file storage exists.

**Version 2.**

- The user portal exists beside the panel. People manage their own calendars, contacts, tasks, notes, and later files there. The admin panel stays the operator's tool.
- On-server scheduling is a server feature. The panel does not become a mail client. Version 2 sends no email.
- Import and export of `.ics` and `.vcf`, and search across events and contacts, are available. Day-to-day editing of a person's own data belongs in the user portal.

**Version 2.x.**

- Outbound email settings for the external SMTP server: invitations to people without an account, reminders, share notices, account notices, and reports. Login notifications and account-lockout emails can each be switched off. The panel does not read incoming mail.
- A password reset can also email a link, once SMTP is configured. The shell command from Version 1 still works without mail.
- User quotas, storage statistics, an audit log of logins and admin actions, and fuller health checks.
- Export and import of server settings as JSON. Secrets such as the SMTP password are left out, or the file is encrypted. Values that come from the environment are not exported. The file has no users, calendars, or contacts.
- Optional two-factor authentication for the panel.

## Who does what

| Action | Admin panel | User portal | DAV client |
|---|---|---|---|
| Create a user | Version 1 | No | No |
| Create a calendar or address book for any user | Version 1 | Version 2, only their own | No |
| Edit an event, contact, task, or note | No | Version 2, only their own | Version 1 |
| Create an app password | Version 1 | Version 2, for their own devices | No |
| Change server settings | Version 1 | No | No |
| Share a collection | Version 1.x | Version 2, their own collections | Version 1.x, if the client supports it |

Health for Version 1 is the unauthenticated `/health` endpoint, which reports that the app and the database are up and does not reveal details. The richer checks inside the panel are Version 2.x.

## Not in the plan yet

- A user portal before Version 2
- Email of any kind before Version 2.x
- Switching PostgreSQL and SQLite after install
- A web page that sets the admin password from an environment flag. Recovery stays the shell command, so a stranger who reaches the site first cannot claim the account
- Mobile layout and dark mode. Those are not assigned to a version
- Webhooks or automation tokens
