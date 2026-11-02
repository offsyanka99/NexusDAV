# Admin panel — Configuration page (UI draft)

This is a design note. The high-level description lives in [admin.md](../apps/admin.md#configuration). The Version 1 page was built in PR 6 of the [implementation plan](../plans/implementation-plan-v1.md); the environment variables and defaults are listed in [Settings in the admin panel](../guide.md#settings-in-the-admin-panel).

## Purpose

The Configuration page holds server-wide preferences that are not tied to a specific DAV service. An administrator sets defaults for time, sessions, rate limiting, language, email and operational features. Each setting has an environment variable. When it is set, the environment value wins and the field is shown read-only with a lock and "Set by <VARIABLE>".

## Version 1

**Time** section:
- Server time zone (`NEXUSDAV_TIMEZONE`, default `UTC`). Used for users without their own time zone, as the `calendar-timezone` of new calendars (the owner's own zone wins when set), and to show times in the panel.
- Time format, 24-hour or 12-hour (`NEXUSDAV_TIME_FORMAT`, default `24`)
- Week starts on, Sunday to Saturday (`NEXUSDAV_WEEK_START`, default Monday)

**Rate limiting** section:
- Failures per username (`NEXUSDAV_RATE_LIMIT_MAX_ATTEMPTS`, default `5`): failed logins for one address and username before that login is refused for the window
- Failures per address (`NEXUSDAV_RATE_LIMIT_PER_ADDRESS`, default `20`): failed logins from one address across all usernames. Off when `TRUSTED_PROXIES` is `*`
- Window in seconds (`NEXUSDAV_RATE_LIMIT_DECAY_SECONDS`, default `60`)

**Sessions** section:
- Session lifetime (`SESSION_LIFETIME`, default `120`): Laravel's session lifetime, minutes of inactivity before an admin is signed out
- Secure cookies (`SESSION_SECURE_COOKIE`): on by default when `APP_URL` is `https`. Turning it on is refused unless the panel is open over HTTPS, so an admin cannot sign themselves out by accident

**Diagnostics** section:
- Keep failed logins and DAV errors for, in days (`NEXUSDAV_DIAGNOSTICS_RETENTION_DAYS`, default `30`)

A **Save** button stores the values. A change applies from the next request.

## Version 1.x

- Default interface language (English or Russian; each admin can choose their own in the user menu)
- Portal log level (once the user portal exists)

## Version 2.x

- Email sender (From address)
- SMTP settings for outbound email
- Backup and restore settings for configuration and data
- Health check settings

## Danger Zone (Version 2.x)

**Reset to Default** performs a full factory reset: configuration, database (all users and data), WebDAV files and the install lock. The action requires one or two explicit confirmations before it runs.

## Notes

- DAV service toggles and their options live on the [Settings](admin-settings.md) page.
- Diagnostics, quotas and operational status appear on the [Diagnostics](../apps/admin.md#diagnostics) page.
- Changes take effect without a restart unless the documentation for a specific option says otherwise.
