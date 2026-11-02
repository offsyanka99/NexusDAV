# Apps

NexusDAV is one server. The pages below describe the parts a person uses. The Version 1 parts are built; they pass automated tests with the vdirsyncer and python caldav clients, and 0.1.1 was tested by hand with DAVx⁵ (calendar, contacts, tasks and notes through jtx Board) and Thunderbird (calendar and contacts). Apple clients have not been tried yet. Versions match the [roadmap](../guide.md#roadmap).

| App | What it stores | First version |
|---|---|---|
| [Calendar](calendar.md) | Events (`VEVENT`) in a CalDAV collection | Version 1 |
| [Contacts](contacts.md) | Contacts in a CardDAV address book | Version 1 |
| [Tasks](tasks.md) | Tasks (`VTODO`) in a CalDAV collection | Version 1 |
| [Notes](notes.md) | Notes (`VJOURNAL`) in a CalDAV collection | Version 1 |
| [Files](files.md) | Ordinary files over WebDAV | Version 1.x |
| [Admin panel](admin.md) | Users, collections, and server settings | Version 1 |

The user portal is not a separate server. It arrives in Version 2. Each page says what the portal does for that app, and what stays in the admin panel.

Client setup for all of them is in the [guide](../guide.md#client-setup-and-compatibility).
