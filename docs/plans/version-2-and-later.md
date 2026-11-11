# Plan for Version 2, 2.x and later

This document collects features planned for Version 2 and beyond. The labels are the intended target; release numbers are given when a version ships, as in the [Version 1.x plan](implementation-plan-v1x.md).

Sources: [guide.md](../guide.md), the [apps](../apps/README.md) pages, [admin.md](../apps/admin.md), the design notes under [docs/design](../design), and the items the Version 1.x plan leaves out.

## Version 2

### User portal

A basic user portal beside the admin panel. A UI draft is in [docs/design/user-portal.md](../design/user-portal.md). People manage their own data there; the admin panel stays the operator's tool.

- Their own calendars, contacts, tasks and notes. The portal is the first NexusDAV screen that edits a note
- Their own file home ([Files](../apps/files.md))
- Their own app passwords, one per device
- Sharing their own calendars and address books with other users on this server
- Their own time zone and language (the `users.timezone` and `users.locale` columns already exist)
- Session settings for the portal, and a portal log level on the Configuration page
- **User dashboard** — a landing page that shows upcoming events, overdue tasks, recently modified files and current quota usage so the user sees what needs attention without navigating into each app.

### Server features

- On-server CalDAV scheduling (invitations and free/busy between users on this server). No outbound email yet
  - A Scheduling on/off switch on the Settings page
  - Room and resource calendars that can be booked through scheduling
- Import / Export of ICS and VCF (calendars, contacts, tasks and notes)
- Search across events and contacts

### Calendar and contacts

- Holidays calendar (the country is selected first)
- Custom color for a specific event
- Duplicate contact detection and merge

## Version 2.x

### Email and notifications

- Outbound email through an external SMTP server
  - Invitations to people who are not users of this server
  - Notifications and reminders
  - Calendar and address book share notices
  - Account notices (login notifications and account-lockout emails, each can be switched off)
  - System reports
- Replies to emailed invitations are not read or processed
- Password reset can also email a link once SMTP is configured (the shell command from Version 1 still works without mail)

### Attachments and storage

- Managed Attachments, with an on/off switch on the Settings page
- User quotas
- Per-user and server storage statistics

### Files and data

- Editing office documents in the browser
- Trash and restore of deleted items
- Trash for files in the user portal
- Direct import from Radicale, Baïkal and Nextcloud
- **File versioning** — keep a limited history of previous versions of a file (for example the last N versions or versions from the last 30 days) so a user can restore an earlier copy after an accidental overwrite.
- **Public share links** — generate a link to a file, folder or calendar that can be protected with a password, an expiry date and read-only or download-only access. Useful for sharing with people who do not have an account on the server.
- **In-browser preview** — view images, PDFs, office documents and common video formats directly in the portal without downloading them first.
- **Activity / Recent** — a feed of recent changes across the user's own calendars, contacts, tasks and files.
- **Favorites / Starred** — mark files, calendars or contacts as favorites so they appear in a quick-access list.
- **Bulk operations** — select multiple files and download them as a zip, move them, or delete them in one action.

### Admin and operations

- Audit trail of successful logins and admin actions. Failed logins are already recorded on the Diagnostics page since Version 1
- Fuller health checks (database, disk, queue, SMTP). The WebDAV-Push worker status arrives in Version 1.x
- Automatic backups (local + remote: S3, Backblaze, etc.)
- Settings that become secrets (the SMTP password) are left out of the settings file, which arrives in 1.6.0
- Optional two-factor authentication for the admin panel
- Multilingual user portal
- **Multi-admin roles** — at least two roles: a full administrator and a user manager who can create and disable users but cannot change server settings, SMTP or backups.
- **Impersonation** — an administrator can open the user portal as a specific user to reproduce a problem, with a clear audit log entry and a visible banner that impersonation is active.
- **Session management** — show the active sessions of a user (device, IP, last activity) and allow an administrator or the user themselves to revoke a session.
- **Passkeys / WebAuthn** — support for passkeys on both the admin panel and the user portal as a stronger alternative or complement to TOTP-based 2FA.
- **Password policy** — optional rules for minimum length, complexity and password lifetime, applied when a user sets or changes their password.

### Accessibility

- A user portal that works well on mobile and has a dark mode
- An admin panel optimised for mobile. Filament's layout is already responsive, and its light, dark and system theme switcher is already on in the admin panel

### Dashboard (Version 2.x additions)

- Storage and quota summary
- Extended health, mail queue and backup status

### Diagnostics (Version 2.x additions)

- Collections without recent sync (no requests for a configurable period, for example 30 days)
- Quotas and usage per user
- Extended health check
- Mail queue (messages in failed or retrying state)
- Server log filter by username or principal. The Diagnostics tables can already be searched by username since Version 1
- Backups (last successful backup, size, errors)

### Configuration (Version 2.x)

- Backup and restore settings for configuration and data
- Health check settings
- SMTP settings and the From address used for outbound email

## Later / not yet assigned to a version

These items appear in the long-term lists of the guide and the app pages, or were left out of Version 1.x, and have not been scheduled to a specific version.

### Calendar

- Public read-only calendar links / ICS subscriptions
- Birthdays calendar generated from contacts
- **Recurrence expansion on the server** — expand recurring events on the server so clients receive individual occurrences instead of having to expand the series themselves. Improves compatibility with simpler clients.
- **Improved VALARM support** — better handling of reminders, including relative alarms and multiple alarms per event.

### Contacts

- Share a contact by email (requires the Version 2.x mailer)
- A server-wide, read-only address book listing the users on this server
- **CardDAV multi-get and large address book optimisations** — reduce the number of round-trips when a client synchronises a large address book.

### Tasks and notes

- A task editor in the admin panel
- Scheduling or invitations for tasks
- Turning a task into an event, or the other way around
- Sync with Apple Notes
- Sharing a single note with someone who does not have the whole collection

### Files

- Sharing a file or a folder with another user
- **Storage backends** — an abstraction layer so file storage can use a local filesystem or an S3-compatible object store. Allows the data volume to stay small while files live in external storage.
- **Comments on files** — users can leave comments on a shared file or folder.
- **Tags / labels** — attach free-form tags to files and events for better organisation and search.

### Sharing

- Sharing with groups
- Sharing started from a client (the client sharing protocol)

### Security and accounts

- Single sign-on with OpenID Connect (for example Authentik, Authelia or Keycloak) for the admin panel and the user portal
- Users synced from LDAP
- App password scopes (for example calendars and contacts only, without files)
- Secrets supplied as files (`*_FILE`, for Docker secrets)
- **OAuth2 / device flow** — allow third-party clients to obtain tokens through a standard OAuth2 device or authorisation code flow instead of (or in addition to) app passwords.
- **IP allowlist / geo-restriction** — optional restriction of admin panel or DAV access to specific IP ranges or countries.
- **Antivirus scanning** — scan uploaded files with ClamAV or a similar engine before they become available.
- **Audit log export and retention** — export the audit log as CSV or JSON and configure how long entries are kept.

### Integration

- Webhooks and API tokens for automation
- **Admin API** — a documented JSON API that lets external tools create users, set quotas, list collections and perform other administrative tasks without using the web interface.
- **Webhook signing and retry** — sign webhook payloads and automatically retry failed deliveries.

### Infrastructure and operations

- Prometheus metrics endpoint
- Maintenance mode and read-only mode for upgrades
- A listing in the official TrueNAS SCALE Apps catalog
- **Point-in-time recovery** — more granular backups that allow restoring the database and files to a specific moment rather than only the last full backup.
- **Feature flags** — enable or disable experimental or optional features for individual users or groups without a server restart.
- **Timezone database updates** — a way to update the timezone data used by the server without rebuilding the whole image.

### User portal (later)

- **PWA / basic offline support** — the portal can be installed as a Progressive Web App and can show a limited offline view of the calendar and tasks.
- **Keyboard shortcuts** — common actions in the portal can be triggered from the keyboard.
- **Improved accessibility** — better ARIA labels, screen-reader support and keyboard navigation beyond the basic responsive layout.

### Testing and performance

- More automated client tests in CI, and conformance suites (CalDAVTester, litmus)
- Performance work on large collections
- Hand testing by the project on Apple, macOS and Windows clients

## Notes

- The Version 1.x features (Sharing, WebDAV-Push, the multilingual admin panel, Files and sync reliability) are in the [Version 1.x plan](implementation-plan-v1x.md) and are not repeated here.
- Switching between PostgreSQL and SQLite after install is not planned.
- A web-based admin password reset triggered by an environment variable is deliberately not planned.
