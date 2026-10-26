# Admin panel — Dashboard (UI draft)

This is a design note, not part of the official documentation. The high-level description lives in [admin.md](../apps/admin.md#after-sign-in). The work is planned in PR 6 of the [implementation plan](../implementation-plan-v1.md).

## Purpose

The Dashboard is the page an administrator lands on after sign-in. It answers three questions at a glance:

1. Is the server healthy?
2. Which services are switched on?
3. How much is on the server, and is anything going wrong?

It is read-only. It never changes settings. Anything the administrator needs to act on links to the page that does it (Users, Settings, Diagnostics).

## Version 1 layout

The page uses the standard Filament panel layout (navigation, then page header, then widgets). The primary colour is the panel's amber. The top of the page shows the title **Dashboard**. There is no search field and no action button in the header.

```
+-----------------------------------------------------------------------+
| Dashboard                                                             |
+-----------------------------------------------------------------------+
| [ Server status ]                                                     |
|  ● Application OK  ● Database OK (PostgreSQL)  NexusDAV 0.1.0+fc8b441 |
+-----------------------------------------------------------------------+
| [ Services ]                                                          |
|  CalDAV  ● On     CardDAV  ● On     Tasks  ● On     Notes  ○ Off      |
|                                              Manage in Settings →     |
+-----------------------------------------------------------------------+
| [ Users ]    [ Calendars ]   [ Address books ]   [ App passwords ]    |
|     12            27               14                  31             |
+-----------------------------------------------------------------------+
| [ Events and tasks ]   [ Notes ]            [ Contacts ]              |
|        4 213              58                    1 904                 |
+-----------------------------------------------------------------------+
| [ Needs attention ]                                                   |
|  3 failed logins in the last 24 hours           Open Diagnostics →    |
|  2 DAV errors in the last 24 hours                                    |
+-----------------------------------------------------------------------+
```

The numbers above are examples.

On a wide screen the cards sit in the rows shown. On a narrow screen each row wraps to one card per line. The panel is not optimised for mobile in Version 1, but nothing may overflow horizontally.

## What the page contains

### 1. Server status

One card with a row of status items. Each item has a coloured dot, a label and a short value.

| Item | Source | Healthy | Problem |
|---|---|---|---|
| Application | The page rendering at all | Green, `OK` | Not shown, since the page would not load |
| Database | The same check as `/health` | Green, `OK`, with the type (`PostgreSQL` or `SQLite`) | Red, `Unavailable` |
| Version | NexusDAV release or build plus git commit | Plain text | |

Rules:

- Use the same database check as `GET /health`, so the two never disagree.
- Never show a connection string, host name, file path or error text. If the check fails, show only the word `Unavailable`. Details stay in the server log.
- Do not colour by colour alone. Pair each dot with the word.

### 2. Services

One card listing the four switches from the Settings page: **CalDAV**, **CardDAV**, **Tasks (VTODO)** and **Notes (VJOURNAL)**.

- `● On` (green) or `○ Off` (grey).
- If the value is set by an environment variable, show a small lock icon with the tooltip "Set by environment".
- The card ends with a link, **Manage in Settings →**.
- Until PR 6 adds the settings storage, every service shows as On.

Later versions add WebDAV (files), WebDAV-Push, Sharing, Scheduling and Managed Attachments to this card, each only when the feature exists.

### 3. Counts

Small stat cards, one number each, with a label. A short caption may appear under the number, for example "active" or "in total".

| Card | Counts | Link |
|---|---|---|
| Users | All users. Caption: how many are disabled, when any are | Users |
| Calendars | Calendar collections | Users |
| Address books | Address book collections | Users |
| App passwords | Active ones (not revoked, not expired) | Diagnostics |
| Events and tasks | Calendar objects that are events or tasks | |
| Notes | Calendar objects that are notes (VJOURNAL) | |
| Contacts | Cards | |

Rules:

- A Notes card is shown only when the Notes service is On. Hide a count card for a service that is Off, so the page matches what the server does.
- Counts come from cheap `COUNT` queries. If the table grows large, cache the numbers for about a minute rather than counting on every page view.
- Format large numbers with thousands separators.
- Do not show per-user or per-collection numbers here. Those live on the Users page.

### 4. Needs attention

One card that stays quiet when everything is fine.

- When there is nothing to report it shows `Nothing needs attention` with a green check.
- Otherwise it lists short lines, each linking to **Diagnostics**:
  - failed logins in the last 24 hours (admin panel and DAV together);
  - DAV errors in the last 24 hours (4xx and 5xx, as in the Diagnostics page);
  - the database check failing, shown in red at the top of the list.
- Show counts, not details. Names, IP addresses and reasons stay on Diagnostics.
- The 24-hour window is fixed in Version 1.

## What is not on the Dashboard in Version 1

- Storage size, disk space and per-user usage. These come with quotas and storage statistics in Version 2.x.
- Extended health checks (queue, SMTP, WebDAV-Push worker). Version 2.x.
- Charts or activity graphs. Version 1 shows numbers only.
- Quick-action buttons. The navigation already reaches every page.
- Filament's default welcome and "Filament info" widgets. Remove them. They advertise the framework and give the administrator nothing.
- The signed-in account card, unless the navigation already shows the user menu. Prefer the user menu in the panel header.

## Behaviour

- **Access:** administrators only, like the rest of the panel.
- **Refresh:** the widgets load with the page. A manual page reload refreshes them. Automatic polling is off in Version 1, so an idle Dashboard does not hit the database.
- **Empty server:** on a fresh install the counts show `0`, the Needs attention card is quiet, and the first administrator is the only user.
- **Errors:** if a single widget cannot load, show its card with a short "Unavailable" and keep the rest of the page working.
- **Language:** all text is translatable, as for the rest of the panel.
- **Colours:** use Filament's semantic colours: success for healthy and On, danger for Unavailable, gray for Off, warning for the attention lines. Always back a colour with text or an icon.

## Implementation notes

- Build it as widgets on the existing `Filament\Pages\Dashboard`, in `app/Filament/Widgets`:
  - a status widget (server status);
  - a services widget;
  - a stats overview widget (counts);
  - an attention widget.
- Replace the widgets currently registered in `AdminPanelProvider` (`AccountWidget`, `FilamentInfoWidget`) with these.
- Read service states from the Settings storage added in the same PR. Read the attention counts from `diagnostic_events` ([PR 5](../implementation-plan-v1.md)).
- Tests: the page opens for an administrator and not for a guest; counts match the data; a disabled service shows Off and hides its card; the database check failing shows `Unavailable` with no internal detail; the Needs attention card matches the Diagnostics numbers.

## Later versions

| Version | Addition |
|---|---|
| 1.x | WebDAV, WebDAV-Push and Sharing in the Services card |
| 2 | Scheduling in the Services card |
| 2.x | Storage and quota summary, extended health, mail queue and backup status |

Examples in this draft are placeholders, not final wording.
