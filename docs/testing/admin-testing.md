# Admin panel testing (1.6.0)

Hand checks for the settings backup (PR 44 and PR 45) and the Danger Zone (PR 46 and PR 47). Run them on a PostgreSQL server and a SQLite server, in the browser, with the panel in English and then Russian. The automated tests cover the same rules (`SettingsTransferTest`, `FactoryResetTest`, `DangerZoneTest`); these steps check what the admin sees.

## Setup

- Two servers: one on PostgreSQL, one on SQLite, both on the build under test. A few settings changed on the first: for example **Files** on, **Maximum upload size** 512, **Log level** Warning, **Server time zone** your own, **Week starts on** Sunday.
- On the second server, leave the settings at their defaults, and set one of the exported settings in the environment, for example `LOG_LEVEL=info`.
- Open the panel over HTTPS on one server and over plain HTTP on the other (or note which one you use for step S7).

## Settings backup (PR 44 and PR 45)

| ID | Do | Pass |
|---|---|---|
| S1 | On the first server, **Configuration → Settings backup → Export settings** | A file `nexusdav-settings-<host>-<date>.json` downloads. It lists the settings by key; the ones you changed have their value, the others `null`. Nothing locked by the environment, no `push.` keys, no users. The log has `INFO Settings exported` with your username |
| S2 | Open the file in a text editor | Pretty-printed JSON with `"format": "nexusdav-settings"`, `"format_version": 1` and the build's version |
| S3 | On the second server, **Import settings**, choose the file, **Show changes** | The preview lists each changed setting with its label and `current → new`, says how many are already the same, and lists **Log level** under **Not imported** with "Set by the environment on this server". Nothing is saved yet: the form above still shows the old values |
| S4 | **Apply** | "Settings imported" with the number of changed settings. The form shows the new values, and the Settings page too. The log has `NOTICE Settings imported` with the names of the settings, without values. With a worker running, it uses the new values for its next job (for example the push log level) |
| S5 | Import the same file again | "Nothing to change: this server already has these settings." and no **Apply** button |
| S6 | Edit the file: set `"files_max_upload_mb": null`, import it | The preview shows `512 → Default (128)`. After **Apply** the field shows 128 |
| S7 | Over plain HTTP, import a file with `"session_secure_cookie": true` | Listed under **Not imported** with "Open the panel over HTTPS before you turn this on."; you stay signed in |
| S8 | Edit the file: `"default_locale": "fr"`, and add `"something_new": 1`; import it | Both are under **Not imported**: "Not valid on this server" and "Unknown setting". The other settings can still be applied |
| S9 | Import a file with `"format_version": 2`, then a text file that is not JSON, then a JSON file without `"format"` | Each is refused with a message ("This file comes from a newer NexusDAV (…)", "This is not a NexusDAV settings file."), and the upload dialog stays open |
| S10 | Import a file that changes **Server default language** to Русский (as an admin without an own language) | After **Apply** the page reloads in Russian |
| S11 | Repeat S3 and S4 with the panel in Russian | Every text of the section, the dialogs, the preview and the notifications is Russian |

## Danger Zone (PR 46 and PR 47)

Before you start: take a database backup of each server, and run the queue worker (Compose profile `worker`). Have Files on, at least two more users with calendars, contacts and files, a calendar shared between them, and DAVx⁵ and Thunderbird signed in with app passwords. Export the settings (S1) to compare later.

| ID | Do | Pass |
|---|---|---|
| D1 | **Configuration → Danger Zone → Reset to Default** | The warning lists other users, calendars, address books, events, tasks, notes and contacts with today's numbers, and the file storage total as on the Dashboard ("Not counted yet" before **Refresh** there). It says what is kept |
| D2 | **Export settings first** | The settings file downloads; the dialog stays open |
| D3 | **Continue**. Type the server name with a mistake | **Reset** stays disabled |
| D4 | Type the name right and a wrong password, **Reset**, five times | "The password is not correct." each time, and a `WARNING Factory reset refused: wrong password` line each time. The sixth try, with the right password, says "Too many wrong passwords. Try again in a minute." Nothing is deleted |
| D5 | After a minute, the right name and password, **Reset** | The Dashboard opens with "The server was reset to its defaults.", and you are still signed in. The counts show one user. The log has `WARNING Factory reset` with the numbers |
| D6 | In another browser where you were signed in as the same admin, reload | The sign-in page: your other sessions ended |
| D7 | Sync DAVx5 and Thunderbird | **401**: their app passwords are gone. No 5xx on Diagnostics, apart from **503** during the reset if a client synced right then |
| D8 | **Users → you** | A new **default** calendar and address book, no app passwords. The other users are gone. **File home** of the others is gone from the data volume (`/data/files`), and your own is empty |
| D9 | **Settings** and **Configuration** | Every value is back to its default, apart from the ones set in the environment (locked). Import the file from D2 to get them back (S3, S4) |
| D10 | **Diagnostics** | The worker card still shows the worker running (its heartbeat is kept). The other tables are empty |
| D11 | Set up DAVx⁵ again with a new app password, with WebDAV-Push on (step 12b of the [client checklist](client-checklist.md#12-webdav-push)), then set Thunderbird up again and change an event in the same calendar | DAVx⁵ subscribes again (new push keys) and gets the change by push |
| D12 | Repeat D1 to D5 with the panel in Russian | Every text of the section, the dialogs, the errors and the notification is Russian |

## Results

| Run log | |
|---|---|
| Date | Nov 11 |
| Build (Dashboard version) | Dev |
| Tester | Maintainer |

| Step | PostgreSQL | SQLite | Notes |
|---|---|---|---|
| S1 Export | ✅ | ✅ | |
| S2 The file | ✅ | ✅ | |
| S3 Preview | ✅ | ✅ | |
| S4 Apply | ✅ | ✅ | |
| S5 Nothing to change | ✅ | ✅ | |
| S6 Back to the default | ✅ | ✅ | |
| S7 Secure cookies over HTTP | | | |
| S8 Not valid and unknown | | | |
| S9 Refused files | | | |
| S10 Server language | ✅ | ✅ | |
| S11 Russian | ✅ | ✅ | |
| D1 Warning and counts | | | |
| D2 Export settings first | | | |
| D3 Wrong name | | | |
| D4 Wrong password and the limit | | | |
| D5 Reset | | | |
| D6 Other sessions end | | | |
| D7 Clients get 401 | | | |
| D8 Your default collections | | | |
| D9 Settings back to defaults | | | |
| D10 The worker keeps running | | | |
| D11 Push after the reset | | | |
| D12 Russian | ✅ | ✅ | |
