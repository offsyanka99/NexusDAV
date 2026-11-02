# Plan for Version 2, 2.x and later

This document collects features planned for Version 2 and beyond. The labels are the intended target; release numbers are given when a version ships, as in the [Version 1.x plan](implementation-plan-v1x.md).

Sources: [guide.md](../guide.md), the [apps](../apps/README.md) pages, [admin.md](../apps/admin.md), the design notes under [docs/design](../design), and the items the Version 1.x plan leaves out.

## Version 2

### User portal

A basic user portal beside the admin panel. People manage their own data there; the admin panel stays the operator's tool.

- Their own calendars, contacts, tasks and notes. The portal is the first NexusDAV screen that edits a note
- Their own file home ([Files](../apps/files.md))
- Their own app passwords, one per device
- Sharing their own calendars and address books with other users on this server
- Their own time zone and language (the `users.timezone` and `users.locale` columns already exist)
- Session settings for the portal, and a portal log level on the Configuration page

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

### Admin and operations

- Audit trail of successful logins and admin actions. Failed logins are already recorded on the Diagnostics page since Version 1
- Fuller health checks (database, disk, queue, SMTP). The WebDAV-Push worker status arrives in Version 1.x
- Automatic backups (local + remote: S3, Backblaze, etc.)
- Export and import of server settings as JSON
  - Secrets such as the SMTP password are left out, or the file is encrypted
  - Values that come from the environment are not exported
  - The file has no users, calendars or contacts
- Optional two-factor authentication for the admin panel
- Multilingual user portal

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
- Danger Zone: Reset to Default (full factory reset with one or two explicit confirmations)

## Later / not yet assigned to a version

These items appear in the long-term lists of the guide and the app pages, or were left out of Version 1.x, and have not been scheduled to a specific version.

### Calendar

- Public read-only calendar links / ICS subscriptions
- Birthdays calendar generated from contacts

### Contacts

- Share a contact by email (requires the Version 2.x mailer)
- A server-wide, read-only address book listing the users on this server

### Tasks and notes

- A task editor in the admin panel
- Scheduling or invitations for tasks
- Turning a task into an event, or the other way around
- Sync with Apple Notes
- Sharing a single note with someone who does not have the whole collection

### Files

- Sharing a file or a folder with another user

### Sharing

- Sharing with groups
- Sharing started from a client (the client sharing protocol)

### Security and accounts

- Single sign-on with OpenID Connect (for example Authentik, Authelia or Keycloak) for the admin panel and the user portal
- Users synced from LDAP
- App password scopes (for example calendars and contacts only, without files)
- Secrets supplied as files (`*_FILE`, for Docker secrets)

### Integration

- Webhooks and API tokens for automation

### Infrastructure and operations

- Prometheus metrics endpoint
- Maintenance mode and read-only mode for upgrades
- A listing in the official TrueNAS SCALE Apps catalog

### Testing and performance

- More automated client tests in CI, and conformance suites (CalDAVTester, litmus)
- Performance work on large collections
- Hand testing by the project on Apple, macOS and Windows clients

## Notes

- The Version 1.x features (Sharing, WebDAV-Push, the multilingual admin panel, Files and sync reliability) are in the [Version 1.x plan](implementation-plan-v1x.md) and are not repeated here.
- Switching between PostgreSQL and SQLite after install is not planned.
- A web-based admin password reset triggered by an environment variable is deliberately not planned.
