# Admin panel — Settings page (UI draft)

This is a design note. The high-level description lives in [admin.md](../apps/admin.md#settings).

## Purpose

The Settings page holds system-level controls for DAV services and related options. An administrator enables or disables services and configures their parameters. Values that are also set through environment variables are read-only here, and the environment value wins (see [Configuration](../apps/admin.md#configuration)).

## Version 1

- Enable or disable CalDAV
- Enable or disable CardDAV
- Enable or disable Tasks (VTODO)
- Enable or disable Notes (VJOURNAL)
- Logging level for the server

## Version 1.x

- Enable or disable WebDAV (file storage)
  When enabled, the following fields become available:
  - Storage path
  - Max file size upload (MB)
  - Quota per user (MB)
  - Deleted user file retention (days)
  - Trash retention (days)
- Enable or disable WebDAV-Push
  When enabled, the following fields become available:
  - Include WebDAV-Push for file storage
  - Max push subscriptions per user
  - Push external URL (HTTPS) — required
  - Push log level
- Enable or disable Sharing
- File size limits and per-user disk space limits (may overlap with WebDAV quota)

See [Files](../apps/files.md) and [WebDAV-Push](../guide.md#requirements) for more context.

## Version 2

- Enable or disable on-server Scheduling

## Version 2.x

- Enable or disable Managed Attachments

## Notes

- Session, timezone, rate limiting and SMTP settings live on the [Configuration](../apps/admin.md#configuration) page, not here.
- Diagnostics related to WebDAV-Push subscriptions appear on the [Diagnostics](../apps/admin.md#diagnostics) page.
- Changes take effect without a restart unless the documentation for a specific option says otherwise.
