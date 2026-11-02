# Admin panel — Settings page (UI draft)

This is a design note. The high-level description lives in [admin.md](../apps/admin.md#settings). The Version 1 page was built in PR 6 of the [implementation plan](../plans/implementation-plan-v1.md); the environment variables and defaults are listed in [Settings in the admin panel](../guide.md#settings-in-the-admin-panel).

## Purpose

The Settings page holds system-level controls for DAV services and related options. An administrator enables or disables services and configures their parameters. Each setting has an environment variable. When it is set, the environment value wins and the field is shown read-only with a lock and "Set by <VARIABLE>" (see [Settings in the admin panel](../guide.md#settings-in-the-admin-panel)).

## Version 1

**Services** section:
- CalDAV on or off (`NEXUSDAV_CALDAV`, default on). When off, paths under `/dav/calendars/` answer 403 and discovery no longer offers the calendar home. Stored data is kept.
- CardDAV on or off (`NEXUSDAV_CARDDAV`, default on). The same for `/dav/addressbooks/`.
- Tasks (VTODO) on or off (`NEXUSDAV_TASKS`, default on). When off, calendars stop listing `VTODO` as supported: no task can be created or changed, existing tasks stay readable and can be deleted. The Tasks checkbox in the calendar form is unavailable.
- Notes (VJOURNAL) on or off (`NEXUSDAV_NOTES`, default on). The same for `VJOURNAL`.

**Logging** section:
- Log level (`LOG_LEVEL`, default `debug`): the least severe message written to the container log. It applies to every log channel except Slack. Use Info or higher in production.

A **Save** button stores the values. A change applies from the next request.

## Version 1.x

- Enable or disable Sharing (default on; nothing is shared until an admin creates a share)
- Enable or disable WebDAV (file storage), default off after an upgrade
  When enabled, the following field becomes available:
  - Max file size upload (MB)
- Enable or disable WebDAV-Push, default off after an upgrade
  When enabled, the following fields become available:
  - Include WebDAV-Push for file storage
  - Max push subscriptions per user (default 100)
  - Push log level
  - Allow push endpoints on private networks
  Push uses the same `/dav/` URLs as sync, so there is no separate push URL setting.

File storage is on the data volume, so it has no path setting. Per-user quotas, trash and deleted-user file retention are not part of Version 1.x; quotas are Version 2.x (see [Files](../apps/files.md)). See also [WebDAV-Push](../guide.md#requirements).

## Version 2

- Enable or disable on-server Scheduling

## Version 2.x

- Enable or disable Managed Attachments

## Notes

- Session, time zone, rate limiting, diagnostics retention and (later) SMTP settings live on the [Configuration](admin-configuration.md) page, not here.
- Diagnostics related to WebDAV-Push subscriptions appear on the [Diagnostics](../apps/admin.md#diagnostics) page.
- Changes take effect without a restart unless the documentation for a specific option says otherwise.
- The service switches are shown on the [Dashboard](admin-dashboard.md) Services card.
