# Plan for Version 2, 2.x and later

This document collects features planned for Version 2 and beyond. Version numbering in the project is still being aligned; treat the version labels here as the intended target, not the current release number.

Sources: [guide.md](../guide.md), [admin.md](../apps/admin.md), and design notes under [docs/design](../design).

## Version 2

- Basic user portal (people manage their own calendars, contacts, tasks, notes and later files)
- On-server CalDAV scheduling (invitations and free/busy between users on this server). No outbound email yet
- Import / Export of ICS and VCF (calendars, contacts, tasks and notes)
- Search across events and contacts

The admin panel remains the operator's tool. Day-to-day editing of a person's own data belongs in the user portal.

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

### Attachments and data

- Managed Attachments
- User quotas
- Per-user and server storage statistics

### Admin and operations

- Logs / audit trail of logins and admin actions
- Fuller health checks (database, disk, queue, SMTP, WebDAV-Push worker)
- Automatic backups (local + remote: S3, Backblaze, etc.)
- Export and import of server settings as JSON
  - Secrets such as the SMTP password are left out, or the file is encrypted
  - Values that come from the environment are not exported
  - The file has no users, calendars or contacts
- Optional two-factor authentication for the admin panel
- Multilingual user portal

### Diagnostics (Version 2.x additions)

- Collections without recent sync (no requests for a configurable period, for example 30 days)
- Quotas and usage per user
- Extended health check
- Mail queue (messages in failed or retrying state)
- User log filter (filter server logs by username or principal)
- Backups (last successful backup, size, errors)

### Configuration (Version 2.x)

- Backup and restore settings for configuration and data
- Health check settings
- SMTP settings and the From address used for outbound email
- Danger Zone: Reset to Default (full factory reset with one or two explicit confirmations)

## Later / not yet assigned to a version

These items appear in the long-term lists in the guide and have not been scheduled to a specific version.

### Calendar

- Public read-only calendar links / ICS subscriptions
- Birthdays calendar generated from contacts
- Holidays calendar (the country is selected first)
- Custom color for a specific event

### Contacts

- Duplicate detection and merge
- Share a contact by email (requires the Version 2.x mailer)

### Data

- Trash and restore of deleted items
- Trash for files in the user portal
- Direct import from Radicale, Baïkal and Nextcloud

### Integration

- Webhooks and API tokens for automation

### Infrastructure and operations

- Prometheus metrics endpoint
- Maintenance mode and read-only mode for upgrades
- A listing in the official TrueNAS SCALE Apps catalog

### Accessibility

- Mobile-friendly admin panel and user portal
- Dark mode

## Notes

- WebDAV-Push and basic Sharing are targeted at Version 1.x and are not repeated here.
- Switching between PostgreSQL and SQLite after install is not planned.
- A web-based admin password reset triggered by an environment variable is deliberately not planned.
