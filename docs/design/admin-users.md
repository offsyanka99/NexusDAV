# Admin panel — Users page (UI draft)

This is a design note, not part of the official documentation. The high-level description lives in [admin.md](../apps/admin.md). The landing page is described in [admin-dashboard.md](admin-dashboard.md). The page was built in PR 2 (users and app passwords) and PR 4 (calendars and address books) of the [implementation plan](../plans/implementation-plan-v1.md); this note describes it as built.

## Users page layout

The Users page uses Filament's standard resource layout: a list page, a create page and an edit page. The list header has the title **Users** and a **New user** button. The table has its own search field.

### 1. User list

Table columns:
- Username
- Display name
- Email
- Admin (icon)
- Active (icon)

Search matches username, display name or email.

Row actions:
- Edit
- Disable (shown for an active user) or Enable (shown for a disabled user), each with a confirmation
- Delete, with a confirmation that lists what is removed: the principal, calendars, events, address books, contacts and app passwords, then the shares that user owns and the shares they received (or "none")

The last active admin cannot be disabled, demoted or deleted. The confirmation says so, and the action is refused with a notification.

### 2. New user page

Opened with the **New user** button. Fields:
- Username: lowercase letters, numbers, `_` and `-`; unique; it cannot be changed later, because it is part of every DAV URL
- Display name
- Email: stored in lower case, unique without regard to case
- Password (hidden, with show/hide): at least 12 characters. Required for admins, optional for everyone else; only the admin panel accepts it, DAV clients use app passwords
- Confirm password (hidden, with show/hide): required when a password is entered
- Admin (toggle)
- Active (toggle, on by default)
- Create and Cancel buttons

Creating a user also creates the principal, the calendar and address book homes, a default calendar (`default`, "Calendar", `#1A73E8`) and a default address book (`default`, "Contacts").

### 3. Edit user page

The same fields as the new user page. Username is read-only. The password fields can stay empty to keep the current password. The header has a Delete action.

Above the fields, a **User** section shows:
- Principal (example: `principals/test_user`)
- Calendars count (example: `3`)
- Events/Objects count (example: `2812`)
- Address Books count (example: `1`)
- Contacts count (example: `93`)

Below the form are three tables.

#### Calendars
Table columns:
- URI (example: `holidays-ca`)
- Name (example: `Canadian Holidays`)
- Events, Tasks and Notes: the number of each stored in the calendar (example: `38`, `5`, `2`). `—` when the calendar does not accept that type and holds none of it. The Tasks and Notes columns are hidden while that switch is off on the Settings page.
- Shared: how many people the calendar is shared with
- DAV path (example: `/dav/calendars/test_user/holidays-ca/`)
- Actions (`->iconButton()`) (Edit, Share, Delete)

**Add Calendar** button at the top of the table.

Add Calendar modal fields:
- URI: prefilled with the first free slug (`default`, then `calendar`, `calendar-2`, …); lowercase letters, numbers, `_` and `-`; unique for the user; `inbox`, `outbox` and `notifications` are reserved
- Display name
- Description
- Color: Filament's color picker. The swatch opens a hue and saturation panel, and the field still shows the hex code. The value is stored as `#RRGGBB` (default `#1A73E8`)
- **[checkbox]** Tasks (VTODO): checked by default; unavailable when Tasks is off on the Settings page
- **[checkbox]** Notes (VJOURNAL): checked by default; unavailable when Notes is off on the Settings page
- Cancel and Save buttons

The Edit modal has the same fields with the URI read-only. Delete asks for confirmation and removes the calendar with its events, tasks, notes and shares. A calendar that a client created with a `#RRGGBBAA` color opens with its `#RRGGBB` part.

**Share** opens a form with a multi-select of users and one access level, **Read only** or **Read and write**; the owner and anyone who already has the calendar are left out, and a user is found by username, display name or email. To share with several users at different levels, submit the form once per level. **Shared with** opens a slide-over with a table of the sharees, which has search, an access filter and paging. Access is changed in the row, a row is removed with a confirmation, and **Set access** and **Remove** work on several selected rows. **Read and write** says that the sharee can change and delete the owner's items for everyone, and that a client may need the calendar or address book set or added again after the access changes (Thunderbird keeps its own Read-only flag). The text is a tooltip on an information icon next to **Access**, reachable by keyboard. Every change calls `SharingService`.

#### Address books
Table columns:
- URI (example: `default`)
- Name (example: `Personal Address Book`)
- Contacts (example: `93`)
- Shared: how many people the address book is shared with
- Actions (Edit, Share, Delete)

**Add address book** button at the top of the table.

Add address book modal fields:
- URI: prefilled with the first free slug (`default`, then `contacts`, `contacts-2`, …)
- Display name
- Description
- Cancel and Save buttons

The Edit modal has the same fields with the URI read-only. Delete asks for confirmation and removes the address book, its contacts and its shares. **Share** is the same modal as on a calendar. Address book shares are stored; clients do not see them until a later change.

#### App passwords
Table columns:
- Name (the device, for example `Pixel 7`)
- Public id
- Expires (or `Never`)
- Revoked (or `—`)
- Last used (or `Never`)
- Created

**Generate app password** button at the top of the table. Its modal asks for a name and an optional expiry. The new password (`{public id}.{secret}`) is shown once on the page, with a copy button; only a hash of the secret is stored.

Row action: **Revoke**, with a confirmation, shown while the password is not revoked. A revoked password stays in the list and can no longer sign in. The Diagnostics page lists the app passwords of every user with the same action.

## Differences from the first draft

- The list and the details are separate pages, not a list with a details panel beside it.
- Adding a user is a page, not a modal.
- Row actions are buttons, not a kebab menu.
- The edit page adds Admin and Active toggles and the App passwords table, which the first draft did not show.
