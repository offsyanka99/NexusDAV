# Apps

NexusDAV is one server. The pages below describe the parts a person uses. Nothing on these pages is implemented yet, and nothing has been tested against a real client. Versions match the [roadmap](../../README.md#roadmap).

| App | What it stores | First version |
|---|---|---|
| [Calendar](calendar.md) | Events (`VEVENT`) in a CalDAV collection | Version 1 |
| [Contacts](contacts.md) | Contacts in a CardDAV address book | Version 1 |
| [Tasks](tasks.md) | Tasks (`VTODO`) in a CalDAV collection | Version 1 |
| [Notes](notes.md) | Notes (`VJOURNAL`) in a CalDAV collection | Version 1 |
| [Files](files.md) | Ordinary files over WebDAV | Version 1.x |
| [Admin panel](admin.md) | Users, collections, and server settings | Version 1 |

The user portal is not a separate server. It arrives in Version 2. Each page says what the portal does for that app, and what stays in the admin panel.

Client setup for all of them is in the [README](../../README.md#client-setup-and-compatibility).
