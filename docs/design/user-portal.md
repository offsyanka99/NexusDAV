# User portal — basic version (UI draft)

Design note for the first version of the user portal (Version 2). The admin panel remains the operator's tool. This portal lets a signed-in user manage their own data.

## Tech stack

The user portal uses the same stack as the rest of the application:

- **Backend**: Laravel
- **Frontend**: Livewire
- **Styling**: Tailwind CSS

It does **not** use Filament (Filament is reserved for the admin panel). The portal is a set of Livewire components and Blade views under its own routes and layout, separate from the `/admin` Filament panel.


## Scope of the basic version

### Dashboard

A landing page that shows what needs attention.

- Upcoming events
- Overdue tasks
- Recently modified files
- **Quota** — not implemented yet. Reserve a card or section for it so it can be added later without a layout change. Until quotas exist, the section can show a placeholder or be hidden.

### Calendars

Three-zone layout:

- **Header**: app icons (Dashboard, Calendar, Contacts, Tasks, Notes, Files), search across events, user menu
- **Left sidebar**:
  - New appointment button
  - Mini calendar (month view)
  - **My calendars** — list with checkboxes to show or hide each calendar
  - Each calendar has a `...` menu: Edit details (name, color, description), Share, Import/Export ICS, Delete
  - **Shared with me** — calendars shared by others, also with checkboxes
  - Holidays calendar (if enabled) appears in the list
- **Main area**:
  - Date navigation (`< Today >` + current period)
  - View switcher: Day / Week / Workweek / Month / Year
  - Calendar grid showing events in the color of their calendar

**Capabilities:**
- Create and edit calendar details: name, color, description
- Import / Export ICS
- **Share** — same capability already available to the admin (read-only or read-write with other users on this server). The user can create, change and remove shares of their own calendars.
- **Holidays calendar** — already partially implemented on the server. The user selects a country and gets a holidays calendar. Include it in the first portal version.

### Contacts

- **Left sidebar**:
  - New contact button
  - List of the user's address books with checkboxes (show / hide)
  - Each address book has a `...` menu: Edit details (name, description), Share, Import/Export VCF, Delete
  - **Shared with me** — address books shared by others
- **Main area**:
  - List of contacts from the selected address books (table or cards)
  - Search across contacts
  - Click a contact to view or edit it

**Capabilities:**
- Create and edit address book details: name, description
- Import / Export VCF
- **Share** — same as calendars (already implemented on the server). The user manages shares of their own address books.

### Tasks

- Table of tasks with subtasks
- Create and edit a task
- Add a subtask
- Mark complete

Subtasks are required in the first version because many existing tasks already use them.

### Notes

- Table of notes
- Create and edit a note

### Files

Three-zone layout (similar to the calendar view):

- **Header**: app icons, search across files, user menu
- **Left sidebar**:
  - **New** button (upload file or create folder)
  - Favorites
  - Recent files
  - **My files** — folder tree
  - My shares (files/folders shared with others — later)
  - Trash (later)
  - Storage usage card at the bottom (quota placeholder)
- **Main area**:
  - Breadcrumb (Drive > My files > …)
  - Toolbar: selected actions (rename, delete, download)
  - File/folder list with name, modified date, size
  - Sort and multi-select
- **Right sidebar** (Details panel, shown when an item is selected):
  - Preview / icon
  - General info: size, type, modified, owner
  - Shares (later)

**Capabilities (basic version):**
- List folders and files
- Upload file(s)
- Create a folder
- Rename, delete, download

**Postponed to a later portal version:**
- Copy / Move
- Upload folders
- Favorites, Recent, Trash, My shares, public links
- In-browser preview

### Shared with me

Already implemented on the server. Show calendars and address books that other users have shared with the signed-in user (read-only or read-write according to the share).

### User menu / Settings

- Theme (light / dark / system)
- Language (the `users.locale` column already exists)
- Time zone (the `users.timezone` column already exists)
- Change password
- **App passwords** — create and revoke app passwords for the user's own devices
- Show week numbers (optional)

## Server-side settings (not in the user portal)

These belong on the admin Configuration / Settings pages, not in the user menu:

- Portal log level
- Session settings for the portal (lifetime, etc.)

Add them to the server configuration when the portal is introduced.

## Out of scope for the basic version

- Public share links
- File versioning
- In-browser preview
- Bulk operations beyond simple multi-select delete (if needed)
- Copy / Move and folder upload
- Quotas (space reserved on the Dashboard only)
- On-server scheduling UI (scheduling itself is a server feature)
- Email of any kind

## Notes

- The portal is the first NexusDAV screen that lets a user edit notes and tasks directly.
- Sharing UI in the portal reuses the same rules already enforced by the server (Decision 2 and Decision 3 from the 1.x plan: individual users, read-only or read-write, no groups).
- A user can only manage their own collections and the shares they own. Collections shared with them appear under "Shared with me".
