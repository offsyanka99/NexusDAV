# User portal — basic version (UI draft)

Design note for the first version of the user portal (Version 2). The admin panel remains the operator's tool. This portal lets a signed-in user manage their own data.

## Tech stack

The user portal uses the same stack as the admin panel:

- **Backend**: Laravel
- **Frontend**: Filament (on Livewire)
- **Styling**: Tailwind CSS, through a Filament theme for the portal

The portal is a **second Filament panel** (id `portal`), beside the `admin` panel, with its own path, sign-in page, navigation, pages and theme. Its pages are Filament pages, its lists are Filament tables, and its dialogs and forms are Filament actions and schemas, so date pickers, tables, dialogs, notifications, the theme switcher and the user menu come from Filament, as in the admin panel.

- **Calendar grid and mini calendar:** Filament has no calendar view. The Day, Week, Workweek and Month grid and the mini calendar are a custom Livewire component on a Filament page (or a vetted FullCalendar plugin for Filament, if one fits the licence and Filament 5).
- **Access:** both panels use the same `users` table and guard. `User::canAccessPanel()` today allows only the `admin` panel, for active admins. It changes to: the `portal` panel for every active user with a password, and the `admin` panel for active admins only. A user signed in to the portal never reaches `/admin` unless they are an admin: a signed-in user who is not an admin and opens `/admin` sees an **Access denied** page ("Access denied. The admin panel is for administrators.", status 403) with a link back to the portal, and stays signed in (Decision 20 of the [Version 2 plan](../plans/implementation-plan-v2.md), PR 49).
- **Shared code:** what both panels need (the language action, the session timeout script, the locale middleware, notification duration) moves out of `AdminPanelProvider` so both panel providers use it.
- **Translations:** portal text uses `__()` with entries in `lang/en.json` and `lang/ru.json`, and `LocalizationTest` covers the portal pages too.

## Where it lives and who uses it

- The portal is at the root, `/` (the `portal` panel's path is empty). With the **User portal** switch on (Settings → User portal), `/` opens the portal's sign-in page or Dashboard; with it off, every portal address redirects to `/admin`, as `/` did before. `admin` keeps `/admin` and stays **Filament's default panel** (Filament uses it when no panel is current: console, queued notifications, tests); "the portal is the default" means only that `/` serves it.
- Portal addresses are top-level slugs: `/` (Dashboard), `/calendar`, `/tasks`, `/notes`, `/contacts`, `/files`, `/account`, `/app-passwords`, `/login`, `/logout`. These are reserved for the server and are never a portal slug: `admin`, `dav`, `health`, `up`, `.well-known`, `livewire*`, `filament`, `build`, `css`, `js`, `fonts`, `storage` (`PortalPanelProvider::RESERVED_SLUGS`, checked by a test).
- `/admin` stays the admin panel. An admin is also a user with their own calendars, contacts and files, so admins use the portal too. The user menu of the portal links to the panel for admins, and the panel's user menu links to the portal.
- `/dav/` and `/.well-known/*` do not change.

## Sign-in and accounts

- Every user signs in to the portal with their **main password** (username or email, and password). The admin sets the first password when creating or editing the user. A user without a password cannot use the portal until the admin sets one; the user page in the panel shows whether a password is set.
- **App passwords stay for DAV only.** The main password still does not work on `/dav/` ("main password used" on Diagnostics).
- A **disabled** user cannot sign in.
- **Rate limiting** is the same as for the panel and DAV (Configuration → Rate limiting). Failed portal logins appear on Diagnostics with the source **portal**.
- **One sign-in for both panels:** both use the `web` guard and one session, so an admin signed in to the portal is signed in to `/admin` too, and signing out of one signs out of both. No "remember me".
- **Session timeout** works as in the admin panel (the same script), but each panel has its own idle lifetime: Session lifetime for `/admin`, Portal session lifetime for the portal. Each panel keeps its own last-activity time in the session (`PanelIdleTimeout`); a request to a panel whose lifetime has passed signs out of both and opens that panel's sign-in page. The session itself (its row and cookie) lives for the longer of the two while the portal is on.
- **Forgotten password:** until outbound email exists (Version 2.x), the admin sets a new one in the panel, or the shell command resets it.
- The panel's password help text ("Optional for everyone else. Only the admin panel accepts this password") changes to say that the password signs in to the portal and the panel, never to DAV.

## How the portal writes data

- Every change goes through the same sabre backends as a DAV client (the calendar, address book and file storage backends), never straight into the tables. That way:
  - the sync token moves, so clients pick up the change on their next sync;
  - WebDAV-Push notifies the user's devices;
  - iCalendar and vCard validation, name rules and size limits apply as for a client;
  - the write takes the same lock as a DAV write.
- **Edited on another device first:** the portal sends the item's ETag with each change. When a phone changed the item in between, the server refuses the change (412) and the portal says "This item was changed on another device. Reload to see the new version." Nothing is overwritten.
- A user only reaches their own collections and the shares they received; another user's paths are not found, as on DAV.

## What the admin's switches do in the portal

- **CalDAV off:** the Calendar, Tasks and Notes apps are hidden. **CardDAV off:** Contacts is hidden. **Files off:** Files is hidden.
- **Tasks off / Notes off:** the Tasks or Notes app is hidden, and a new calendar cannot hold them.
- **Sharing off:** the Share action and "Shared with me" are hidden.
- **Maximum upload size** limits portal uploads too.
- A **read-only** share and a **holiday calendar** are read-only in the portal as well.

## Scope of the basic version

### Dashboard

A landing page that shows what needs attention.

- Upcoming events
- Overdue tasks (tasks with a due date in the past that are not completed)
- **Storage** — the space the user's files take. No quota yet (Version 2.x); the card has room for one later.

### Calendars

Three-zone layout:

- **Header**: app icons (Dashboard, Calendar, Contacts, Tasks, Notes, Files), search across events, user menu
- **Left sidebar**:
  - New event button
  - Mini calendar (month view)
  - **My calendars** — list with checkboxes to show or hide each calendar
  - Each calendar has a `...` menu: Edit details (name, color, description), Share, Import/Export ICS, Delete
  - **Shared with me** — calendars shared by others, also with checkboxes
  - The user's holiday calendars, if they have any, appear in **My calendars** with a holiday mark
- **Main area**:
  - Date navigation (`< Today >` + current period)
  - View switcher: Day / Week / Workweek / Month
  - Calendar grid showing events in the color of their calendar, in the user's own time zone. The week start and the time format (24-hour or 12-hour) come from the server-wide settings on Configuration; per-user values come later

**Events (basic version):**
- Create, edit and delete a **single** event: title, start and end, or all-day, location, description, and the calendar it is in.
- **Recurring events** are shown, with every occurrence, but edited only in a client. Opening one says so.
- No reminders (`VALARM`) and no invitations in the first version. An event that has them keeps them when the portal edits it.

**Capabilities:**
- Create a calendar and edit its details: name, color, description, and what it holds (events, tasks, notes), as in the admin panel
- Import / Export ICS (see [Import and export](#import-and-export))
- **Share** — same capability already available to the admin (read-only or read-write with other users on this server). The user can create, change and remove shares of their own calendars.
- **Holidays calendar** — the user selects a country, then a region where it has one, the language of the names and the holiday types, and gets a read-only calendar. The server part is built on the branch `pr-41-holiday-calendars` (holiday data, the read-only calendar, refresh without needless writes). It is moved onto `main` without the Ukrainian translation it is stacked on, and the user-facing part (the country picker, the yearly refresh of the range) is built in the portal instead of the admin panel.

### Contacts

- **Left sidebar**:
  - New contact button
  - List of the user's address books with checkboxes (show / hide)
  - Each address book has a `...` menu: Edit details (name, description), Share, Import/Export VCF, Delete
  - **Shared with me** — address books shared by others
- **Main area**:
  - List of contacts from the selected address books (table or cards)
  - Search across contacts
  - No duplicate detection or merge (Version 2.x)
  - Click a contact to view, edit or delete it

**Contact fields (basic version):** name, organisation and title, emails, phones, postal addresses, birthday, notes. A field the portal does not edit (a photo, a custom field from a client) is kept when the contact is saved.

**Capabilities:**
- Create an address book and edit its details: name, description
- Import / Export VCF (see [Import and export](#import-and-export))
- **Share** — same as calendars (already implemented on the server). The user manages shares of their own address books.

### Tasks

- Table of tasks with subtasks, from every calendar that holds tasks
- Create, edit and delete a task: title, due date, description, and the task list (calendar) it is in
- Add a subtask
- Mark complete; completed tasks are hidden by default and can be shown

Subtasks are required in the first version because many existing tasks already use them.

### Notes

- Table of notes, from every calendar that holds notes
- Create, edit and delete a note: title, text, and the calendar it is in

Only a few clients show notes (DAVx⁵ with jtx Board), so the portal may be where most users see them.

### Files

Three-zone layout (similar to the calendar view):

- **Header**: app icons, user menu
- **Left sidebar**:
  - **New** button (upload file or create folder)
  - **My files** — folder tree
  - Storage card at the bottom (used space; quota later)
- **Main area**:
  - Breadcrumb (Files > …)
  - Toolbar for the selected item: rename, delete, download. One item is selected at a time; there is no multi-select
  - File/folder list with name, modified date, size
  - Sort
- **Right sidebar** (Details panel, shown when an item is selected):
  - Icon
  - General info: size, type, modified

**Capabilities (basic version):**
- List folders and files
- Upload file(s). Uploads larger than the request limit (128 MB) are sent in chunks, up to **Maximum upload size**.
- Create a folder
- Rename, delete, download

File and folder names follow the server's rules (Unicode NFC, refused names), as for a WebDAV client.

**Postponed to a later portal version:**
- Copy / Move
- Upload folders
- Favorites, Recent, Trash, public links
- In-browser preview

### Shared with me

Already implemented on the server. Show calendars and address books that other users have shared with the signed-in user, with their access (read-only or read-write). The user can **leave** a share; the owner's data stays, as when a client deletes a shared collection.

### Import and export

- **Export** downloads the whole collection: one `.ics` for a calendar (events, tasks and notes), one `.vcf` for an address book.
- **Import** reads a `.ics` or `.vcf` file into a collection the user owns (or a read-write share). An item whose UID already exists is **skipped** by default; the user can choose **Replace** instead.
- Each item is checked like a client's upload. Items that are refused (for example a contact with two `UID` lines) are listed with the reason, and the others are still imported.
- The file size is limited (proposed: the same 128 MB as a calendar or contact body).

### User menu / Settings

- Theme (light / dark / system), Filament's theme switcher
- Language (the `users.locale` column already exists)
- Time zone (the `users.timezone` column already exists)
- Change password
- **App passwords** — create and revoke app passwords for the user's own devices
- **Show week numbers** — on or off, per user, stored in a new column `users.show_week_numbers` (boolean, default off). The calendar grid and the mini calendar show ISO week numbers when it is on.

Language and theme are in the basic portal. The [later features plan](../plans/version-2-and-later.md) lists "Multilingual user portal" and "dark mode" under Version 2.x; they move to Version 2 with this design.

## Data changes

| Change | Why |
|---|---|
| New column `users.show_week_numbers` (boolean, default `false`) | The user's **Show week numbers** setting |
| New column `users.portal_state` (JSON, nullable) | Per-user UI state (hidden collections, the last calendar view), so later apps add keys without a migration |
| New table `item_index` | One row per event, task, note and contact, kept current by the calendar and address book backends on every write, from DAV clients too. Lists, search and the Dashboard query it; the calendar grid reads occurrences from the DAV data, with recurring events expanded ([data model](../data-model.md#item_index)) |

The `users.locale` and `users.timezone` columns already exist. A user's settings stay in `users`; they are not panel settings and are not in the settings export.

## Server-side settings (not in the user portal)

These belong on the admin Configuration / Settings pages, not in the user menu:

- **Settings → User portal:** User portal (`NEXUSDAV_PORTAL`, off until 2.0.0) and Portal log level (`NEXUSDAV_PORTAL_LOG_LEVEL`, default Info)
- **Configuration → Sessions:** Portal session lifetime (`NEXUSDAV_PORTAL_SESSION_LIFETIME`, 5 to 10080 minutes, default 480)

As panel settings, they are in `SettingsService::DEFINITIONS`, so the environment can lock them and the settings export picks them up.

## Out of scope for the basic version

- Public share links
- File versioning
- In-browser preview
- Bulk operations and multi-select in Files
- Search across files
- Recently modified files on the Dashboard (like Recent files in Files)
- Duplicate contact detection and merge (Version 2.x)
- Per-user week start and time format (the server-wide settings apply)
- Copy / Move and folder upload
- Quotas (the storage card shows used space only)
- Editing recurring events, reminders and invitations (on-server scheduling UI; scheduling itself is a server feature)
- Email of any kind, including a password reset by email

## Settled questions

| # | Question | Decision |
|---|---|---|
| 1 | Week start and time format | Server-wide, from Configuration, in Version 2.0. Per-user values (beside the time zone) are a later version |
| 2 | Multi-select in Files | None in the first version: one item is selected at a time |
| 3 | Search across files | Not in the first version. Search covers events and contacts |
| 4 | Duplicate contact detection and merge | Not in the first version; Version 2.x |
| 5 | Recently modified files on the Dashboard | Left out, like Recent files in Files |

## Docs to update with the portal

- [guide.md](../guide.md): the portal, sign-in, and the changed meaning of the main password
- [architecture.md](../architecture.md): the second Filament panel, `canAccessPanel()`, and `/` serving the portal
- [data-model.md](../data-model.md): `users.show_week_numbers`
- [apps/admin.md](../apps/admin.md): the password field, the portal settings, the portal source on Diagnostics
- The action tables in [calendar](../apps/calendar.md), [contacts](../apps/contacts.md), [tasks](../apps/tasks.md), [notes](../apps/notes.md) and [files](../apps/files.md)
- [plans/version-2-and-later.md](../plans/version-2-and-later.md): multilingual portal and dark mode move to Version 2; holidays come with the portal; duplicate contact detection and merge moves to Version 2.x; per-user week start and time format added as a later item
- [apps/contacts.md](../apps/contacts.md): duplicate detection and merge move from Version 2 to Version 2.x

## Notes

- The portal is the first NexusDAV screen that lets a user edit notes and tasks directly.
- Sharing UI in the portal reuses the same rules already enforced by the server (Decision 2 and Decision 3 from the 1.x plan: individual users, read-only or read-write, no groups).
- A user can only manage their own collections and the shares they own. Collections shared with them appear under "Shared with me".
