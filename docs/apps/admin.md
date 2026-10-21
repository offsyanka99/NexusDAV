# Admin panel

The admin panel is the operator's interface, built with Filament. It manages users, collections, and server settings. It is not the user portal. The user portal is a separate personal interface and arrives in Version 2. Until then, every management task goes through the admin panel.

A person signs in to the admin panel with the admin account. CalDAV and CardDAV clients do not use that password. They use an app password created here.

The first admin is created on first start from `ADMIN_EMAIL` and `ADMIN_PASSWORD`. Remove `ADMIN_PASSWORD` from `.env` after the first login. `APP_KEY` is not in `.env`. It is generated on first start and stored on the data volume.

## After sign-in

Sign-in lands on the **Dashboard**. The Dashboard shows the server at a glance: server status, which services are running, and statistics. Later versions add detail to this page, such as storage numbers and fuller health checks. Version 1 still opens here, with the status that exists at that point.

A menu links to the other admin pages. The items include **Settings**, **Configuration**, and **Users**, plus the other pages described below as they arrive. Whether that menu sits on the left or along the top is not decided yet.

All tabs have a header and a footer. The tab name appears on the left side of the header. Footer have About link and link to Docs

### Setting

### Users

Header have a tab name - Users
At the right of the header the button '**Add user**'. And before the button search field - "Search users"

1. The Users page consists of two sections. On the left is a table listing the users.
Table columns are:
 - Username
 - Display name
 - e-mail
 - Actions (Kebab menu icon)
Table can be filtered by Username, Display name or e-mail
Menu have the following actions links/buttons:
 - Edit
 - Enable/Disable
 - Delete


2. When the administrator clicks the '**Add User**' button, a modal appears with the following fields and buttons:
 - Username
 - Display name
 - Email
 - Password (hidden)
 - Confirm password
 - Cancel and Save buttons
The 'Password' and 'Confirm password' fields have an icon (on the right) that shows the password.

3. When the Administrator selects an existing user, information about that user appears on the right. The information is divided into three sections:
 - User
 - Calendars
 - Address books

3.1 The '***User***' section contains the following information:
 - Username {test_user}
 - Display name {QA Test}
 - Email {qa_test@email.com}
 - Principal {principals/test_user}
 - Calendars {3}
 - Events/Objects {2812}
 - Address Books {1}
 - Contacts {93}

3.2 The '***Calendars***' section contains a table with available Calendars and table have the following columns:
 - URI {holidays-ca}
 - Name {Canadian Holidays}
 - Objects {38 TASKS NOTES}
 - DAV path {/dav.php/calendars/test_user/holidays-ca/}
 - Actions {Edit Delete}
At the top of the section is the '**Add Calendar**' button
When '**Add calendar**' button clicked, a modal appears with the following fields and buttons:
 - URI token id (prefiled with default)
 - Display name
 - Description
 - Color (#RRGGBB)
 - [ ] Tasks (VTODO)
 - [ ] Notes (VJOURNAL)
 - Cancel and Save buttons

3.3 The “Address books” section contains a table with available Address books and table have the following columns:
 - URI {default}
 - Name {Personal Address Book}
 - Contacts {93}
 - Actions {Edit Delete}
At the top of the section is the '**Add address book**' button
When '**Add address book**' button clicked, a modal appears with the following fields and buttons:
 - URI token id (prefiled with default)
 - Display name
 - Description
 - Cancel and Save buttons


An examples is given in {}


## What each version includes

**Version 1.**

- Sign in, and reset a lost admin password from the container shell. The reset is logged. It signs out existing sessions and can revoke app passwords. It does not send email.
- Create and disable users. Passwords are stored with Argon2id.
- Create an app password for a user, one per device. The main password is not accepted on the DAV endpoint.
- Rate limiting and brute-force protection on the login form and on DAV Basic authentication.
- Create collections: calendars and address books, including collections that hold [tasks](tasks.md) or [notes](notes.md).
- It does not edit events, tasks, notes, or contacts. Clients write those.
- Choose the database before the first start, PostgreSQL or SQLite. The panel cannot switch it later.
- Server settings that are not required to boot live in the database. A setting that is also an environment variable stays read-only in the panel, and the environment value wins.

**Version 1.x.**

- Share a calendar or an address book and set the ACL.
- Turn WebDAV-Push on or off. The implementation is the bitfire draft named in the [guide requirements](../guide.md#requirements), not a sabre/dav plugin.
- Use the panel in more than one language.
- See that a user's [Files](files.md) home exists, once file storage exists.

**Version 2.**

- The user portal exists beside the panel. People manage their own calendars, contacts, tasks, notes, and later files there. The admin panel stays the operator's tool.
- On-server scheduling is a server feature. The panel does not become a mail client. Version 2 sends no email.
- Import and export of `.ics` and `.vcf`, and search across events and contacts, are available. Day-to-day editing of a person's own data belongs in the user portal.

**Version 2.x.**

- Outbound email settings for the external SMTP server: invitations to people without an account, reminders, share notices, account notices, and reports. Login notifications and account-lockout emails can each be switched off. The panel does not read incoming mail.
- A password reset can also email a link, once SMTP is configured. The shell command from Version 1 still works without mail.
- User quotas, storage statistics, an audit log of logins and admin actions, and fuller health checks.
- Export and import of server settings as JSON. Secrets such as the SMTP password are left out, or the file is encrypted. Values that come from the environment are not exported. The file has no users, calendars, or contacts.
- Optional two-factor authentication for the panel.
- View and manage WebDAV-Push subscriptions.

## Who does what

| Action | Admin panel | User portal | DAV client |
|---|---|---|---|
| Create a user | Version 1 | No | No |
| Create a calendar or address book for any user | Version 1 | Version 2, only their own | No |
| Edit an event, contact, task, or note | No | Version 2, only their own | Version 1 |
| Create an app password | Version 1 | Version 2, for their own devices | No |
| Change server settings | Version 1 | No | No |
| Share a collection | Version 1.x | Version 2, their own collections | Version 1.x, if the client supports it |

Health for Version 1 is the unauthenticated `/health` endpoint, which reports that the app and the database are up and does not reveal details. The richer checks inside the panel are Version 2.x.

## Not in the plan yet

- A user portal before Version 2
- Email of any kind before Version 2.x
- Switching PostgreSQL and SQLite after install
- A web page that sets the admin password from an environment flag. Recovery stays the shell command, so a stranger who reaches the site first cannot claim the account
- Mobile layout and dark mode. Those are not assigned to a version
- Webhooks or automation tokens
