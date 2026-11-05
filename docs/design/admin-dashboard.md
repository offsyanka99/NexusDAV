# Admin panel — Dashboard (UI draft)

This is a design note, not part of the official documentation. The high-level description lives in [admin.md](../apps/admin.md#after-sign-in). It was built in PR 6 of the [implementation plan](../plans/implementation-plan-v1.md); this note describes it as built.

## Purpose

The Dashboard is the page an administrator lands on after sign-in. It answers three questions at a glance:

1. Is the server healthy?
2. Which services are switched on?
3. How much is on the server, and is anything going wrong?

It is read-only. It never changes settings. Anything the administrator needs to act on links to the page that does it (Users, Settings, Diagnostics).

## Version 1 layout

The page uses the standard Filament panel layout (navigation, then page header, then widgets). The primary colour is the panel's blue. The top of the page shows the title **Dashboard**. There is no search field and no action button in the header.

```
+-----------------------------------------------------------------------+
| Dashboard                                                             |
+-----------------------------------------------------------------------+
| [ Server status ]                                                     |
|  ● Application OK  ● Database OK (PostgreSQL)  NexusDAV 0.1.7+4f82db8  |
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
|  ⚠ 3 failed logins in the last 24 hours  (links to Diagnostics)      |
|  ⚠ 2 DAV errors in the last 24 hours     (links to Diagnostics)      |
+-----------------------------------------------------------------------+
```

The numbers above are examples.

On a wide screen the count cards sit four to a row, so the second row of counts starts with whatever card follows App passwords. On a narrow screen each row wraps to one card per line. The panel is not optimised for mobile in Version 1, but nothing may overflow horizontally.

## What the page contains

### 1. Server status

One card with a row of status items. Each item has a coloured dot, a label and a short value.

| Item | Source | Healthy | Problem |
|---|---|---|---|
| Application | The page rendering at all | Green, `OK` | Not shown, since the page would not load |
| Database | The same check as `/health` | Green, `OK`, with the type (`PostgreSQL` or `SQLite`) | Red, `Unavailable` |
| Version | `NexusDAV` plus `NEXUSDAV_VERSION`, set at image build time (a release shows its version and the tagged commit, for example `0.1.6+4f82db8`; CI builds use `ci+<commit>`; a local build without it shows `dev`) | Plain text | |

Rules:

- Use the same database check as `GET /health`, so the two never disagree.
- Never show a connection string, host name, file path or error text. If the check fails, show only the word `Unavailable`. Details stay in the server log.
- Do not colour by colour alone. Pair each dot with the word.

### 2. Services

One card listing the four switches from the Settings page: **CalDAV**, **CardDAV**, **Tasks (VTODO)** and **Notes (VJOURNAL)**.

- `● On` (green) or `○ Off` (grey).
- If the value is set by an environment variable, show a small lock icon with the tooltip "Set by environment".
- The card ends with a link, **Manage in Settings →**.
- The states come from the Settings page (PR 6), or from the environment when a variable is set.

Later versions add WebDAV (files), WebDAV-Push, Sharing, Scheduling and Managed Attachments to this card, each only when the feature exists.

### 3. Counts

Small stat cards, one number each, with a label. A short caption may appear under the number, for example "active" or "in total".

| Card | Counts | Link |
|---|---|---|
| Users | All users. Caption: how many are disabled, when any are | Users |
| Calendars | Calendar collections | Users |
| Address books | Address book collections | Users |
| App passwords | Active ones (not revoked, not expired) | Diagnostics |
| Events and tasks | Calendar objects that are events or tasks. Named **Events** and counting events only when Tasks is Off | |
| Notes | Calendar objects that are notes (VJOURNAL) | |
| Contacts | Cards | |

App passwords is the only card with a caption besides Users; it reads "active".

Rules:

- Hide a count card for a service that is Off, so the page matches what the server does. CalDAV Off hides Calendars, Events and Notes; CardDAV Off hides Address books and Contacts; Notes Off hides Notes.
- Counts come from cheap `COUNT` queries, cached for 60 seconds, so a new user or event can take up to a minute to show.
- Format large numbers with thousands separators.
- Do not show per-user or per-collection numbers here. Those live on the Users page.

### 4. Needs attention

One card that stays quiet when everything is fine.

- When there is nothing to report it shows `Nothing needs attention` with a green check.
- Otherwise it lists short lines with a warning icon, each linking to **Diagnostics**:
  - failed logins in the last 24 hours (admin panel and DAV together);
  - DAV errors in the last 24 hours: rejected DAV logins (401) and other 4xx and 5xx answers, as on the Diagnostics page. A rejected DAV login therefore appears in both lines;
  - when the database check fails, the card shows only `The database is unavailable` in red, because the other counts cannot be read.
- Show counts, not details. Names, IP addresses and reasons stay on Diagnostics.
- The 24-hour window is fixed in Version 1.

## What is not on the Dashboard in Version 1

- Storage size, disk space and per-user usage. These come with quotas and storage statistics in Version 2.x.
- Extended health checks (queue, SMTP, mail). Version 2.x. A WebDAV-Push worker warning arrives with WebDAV-Push in Version 1.x.
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

- The page is the stock `Filament\Pages\Dashboard` with four widgets in `app/Filament/Widgets`, in this order:
  - `ServerStatusWidget` (server status);
  - `ServicesWidget` (services, with the **Manage in Settings** link);
  - `CountsWidget`, a `StatsOverviewWidget` with four columns (counts);
  - `AttentionWidget` (needs attention).
- `ServerStatusWidget`, `ServicesWidget` and `AttentionWidget` extend `SchemaWidget`, which builds the card from schema components and shows "Unavailable" when the card fails to load. `CountsWidget` does the same with a single "Counts — Unavailable" stat. The failure is written to the log.
- Filament's default `AccountWidget` and `FilamentInfoWidget` are removed with `->widgets([])` in `AdminPanelProvider`; the account stays in the user menu.
- Shared services: `App\Support\DatabaseHealth` (the same check as `GET /health`), `App\Services\ServerCounts` (the counts, cached for 60 seconds) and `App\Services\DiagnosticsSummary` (the 24-hour numbers, also used by the Diagnostics page heading). Service states come from `SettingsService`.
- Polling is off and the widgets are not lazy-loaded.
- Tests in `tests/Feature/AdminPagesTest.php` cover access, counts, a disabled service, the database check failing without internal detail, and agreement with the Diagnostics numbers.

## Later versions

| Version | Addition |
|---|---|
| 1.x | WebDAV, WebDAV-Push and Sharing in the Services card; a warning in Needs attention when push is on and the worker is not running |
| 2 | Scheduling in the Services card |
| 2.x | Storage and quota summary, extended health, mail queue and backup status |

Examples in this draft are placeholders, not final wording.
