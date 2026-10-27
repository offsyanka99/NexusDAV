# Admin panel — Configuration page (UI draft)

This is a design note. The high-level description lives in [admin.md](../apps/admin.md#configuration).

## Purpose

The Configuration page holds server-wide preferences that are not tied to a specific DAV service. An administrator sets defaults for time, sessions, rate limiting, language, email and operational features. Values that are also set through environment variables are read-only here, and the environment value wins.

## Version 1

- Server time zone
- Time format (12-hour / 24-hour)
- Week starts on
- Rate limiting settings
- Session settings (lifetime, secure cookies, etc.)
- Session idle timeout (minutes)

## Version 1.x

- Default interface language
- Portal log level (once the user portal exists)

## Version 2.x

- Email sender (From address)
- SMTP settings for outbound email
- Backup and restore settings for configuration and data
- Health check settings

## Danger Zone (Version 2.x)

**Reset to Default** performs a full factory reset: configuration, database (all users and data), WebDAV files and the install lock. The action requires one or two explicit confirmations before it runs.

## Notes

- DAV service toggles and their options live on the [Settings](../design/admin-settings.md) page.
- Diagnostics, quotas and operational status appear on the [Diagnostics](../apps/admin.md#diagnostics) page.
- Changes take effect without a restart unless the documentation for a specific option says otherwise.
