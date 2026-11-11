# Admin panel — Configuration page (UI draft)

This is a design note. The high-level description lives in [admin.md](../apps/admin.md#configuration). The Version 1 page was built in PR 6 of the [implementation plan](../plans/implementation-plan-v1.md); the environment variables and defaults are listed in [Settings in the admin panel](../guide.md#settings-in-the-admin-panel).

## Purpose

The Configuration page holds server-wide preferences that are not tied to a specific DAV service. An administrator sets defaults for time, sessions, rate limiting, language, email and operational features. Each setting has an environment variable. When it is set, the environment value wins and the field is shown read-only with a lock and "Set by <VARIABLE>".

## Version 1

**Time** section:
- Server time zone (`NEXUSDAV_TIMEZONE`, default `UTC`). Used for users without their own time zone, as the `calendar-timezone` of new calendars (the owner's own zone wins when set), to show times in the panel, and for the times in the log lines of the app and the worker. Stored times stay in UTC.
- Time format, 24-hour or 12-hour (`NEXUSDAV_TIME_FORMAT`, default `24`)
- Week starts on, Sunday to Saturday (`NEXUSDAV_WEEK_START`, default Monday)

**Rate limiting** section:
- Failures per username (`NEXUSDAV_RATE_LIMIT_MAX_ATTEMPTS`, default `5`): failed logins for one address and username before that login is refused for the window
- Failures per address (`NEXUSDAV_RATE_LIMIT_PER_ADDRESS`, default `20`): failed logins from one address across all usernames. Off when `TRUSTED_PROXIES` is `*`
- Window in seconds (`NEXUSDAV_RATE_LIMIT_DECAY_SECONDS`, default `60`)

**Sessions** section:
- Session lifetime (`SESSION_LIFETIME`, default `120`): Laravel's session lifetime, minutes of inactivity before an admin is signed out. A script on each signed-in page counts the same time from the last answer of the server (shared between tabs through the browser's local storage), signs the admin out and opens the sign-in page with `?timeout=1`, which shows a warning that stays until closed. A Livewire request refused with 419 or 401, or redirected to the sign-in page, does the same.
- Secure cookies (`SESSION_SECURE_COOKIE`): on by default when `APP_URL` is `https`. Turning it on is refused unless the panel is open over HTTPS, so an admin cannot sign themselves out by accident

**Notifications** section:
- Close messages after, in seconds (`NEXUSDAV_NOTIFICATION_SECONDS`, default `6`, 0 to 300). Success and info messages close by themselves after this time; `0` keeps them until the admin closes them. Errors and warnings never close by themselves, so a failure is not missed (a time limit the user can switch off also meets WCAG 2.2.1). A message that sets its own duration, such as the one-time app password, keeps it.

**Diagnostics** section:
- Keep failed logins and DAV errors for, in days (`NEXUSDAV_DIAGNOSTICS_RETENTION_DAYS`, default `30`)

A **Save** button stores the values. A change applies from the next request.

## Version 1.x

Built in 0.1.2 (milestone M1 of the [Version 1.x plan](../plans/implementation-plan-v1x.md#m1--multilingual-admin-panel-effort-medium)).

**Language** section, above Time:
- Server default language (`NEXUSDAV_LOCALE`, default `en`). The options are the shipped languages, each in its own name (English, Русский); a new `lang/<code>.json` adds one. Used on the login page and for every admin who has not chosen a language.

Each admin can choose their own language under **Language** in the user menu. The item shows the language in use, with "Server default (…)" when it is not the admin's own choice. **Server default** clears the choice. The page reloads in the new language. A language chosen in the user menu always wins over this setting; when the server default is saved, an admin who has their own language is told that it stays.

## Settings backup (1.6.0)

Built in PR 44 and PR 45. The last section of the page, below **Save**:

- **Export settings** downloads `nexusdav-settings-<host>-<YYYY-MM-DD>.json`:

  ```json
  {
    "format": "nexusdav-settings",
    "format_version": 1,
    "app_version": "1.6.0",
    "exported_at": "2026-12-01T18:00:00Z",
    "settings": { "files": true, "files_max_upload_mb": 512, "log_level": null }
  }
  ```

  Every setting of the Settings and Configuration pages that the environment does not set, by its key (not its variable name). A value equal to the setting's default is `null`; Secure cookies, which has no fixed default, is `null` until it is saved. Locked settings, settings marked secret, the WebDAV-Push keys and every other row of the `settings` table are never written.
- **Import settings** opens a dialog for the file (JSON, at most 256 KB), then **Show changes** opens the preview:
  - **Changes**: each setting with its label as on the page, `current → new` (`Default (128)` for a `null`)
  - "N settings are already the same."
  - **Not imported**: each skipped setting with its reason: set by the environment on this server, not valid on this server, unknown setting, secret, or Secure cookies while the panel is open over plain HTTP (it would sign the admin out)
  - **Apply** (hidden when nothing changes) saves all changes in one transaction; `null` deletes the saved value, so the default applies again, also in the running worker. The page reloads when the server default language changed.
- Refused as a whole, with the upload dialog left open: a file that is not JSON, has another `format` or no `settings` object, or a `format_version` newer than the server knows ("This file comes from a newer NexusDAV (x.y.z). Upgrade this server first.").

## Version 2

- Portal log level and portal session settings (the user portal arrives in Version 2)

## Version 2.x

- Email sender (From address)
- SMTP settings for outbound email
- Backup and restore settings for configuration and data
- Health check settings

## Danger Zone (1.6.0)

Built in PR 46 and PR 47. The last section of the page, below **Settings backup**, with a red outline and a warning icon, and a red **Reset to Default** button.

- **Step 1**, a warning: what is deleted, with today's numbers (other users, calendars, address books, events, tasks, notes, contacts, and the file storage total as on the Dashboard, "Not counted yet" before a count), and what is kept (your account and password, settings from the environment, `APP_KEY`, the container log). Buttons: **Export settings first** (downloads the settings file), **Cancel**, **Continue**.
- **Step 2**: "Type `<host>`" (the host of `APP_URL`, lower case) and **Your password**. **Reset** is disabled until the name matches; the server checks it again. A wrong password is refused with "The password is not correct.", logged as a `WARNING`, and limited to 5 a minute per admin ("Too many wrong passwords. Try again in a minute.").
- **After the reset**: the Dashboard, with the notification "The server was reset to its defaults.", which stays until closed. The admin stays signed in; their other sessions end.
- What the reset deletes and keeps, and how it closes the server while it runs, is in `FactoryReset` and the guide's [Danger Zone](../guide.md#danger-zone-reset-to-default). There is no "install lock": the first admin comes from the environment at the first start, and the reset keeps the admin who runs it, so the next start needs no `ADMIN_EMAIL` or `ADMIN_PASSWORD`.

## Notes

- DAV service toggles and their options live on the [Settings](admin-settings.md) page.
- Diagnostics, quotas and operational status appear on the [Diagnostics](../apps/admin.md#diagnostics) page.
- Changes take effect without a restart unless the documentation for a specific option says otherwise.
