# Apps

NexusDAV is one server. The pages below describe the parts a person uses. The Version 1 parts, Sharing (0.3.0), Files (0.4.0) and WebDAV-Push (0.5.0) are built; they pass automated tests with the vdirsyncer and python caldav clients, and the [client checklist](../testing/client-checklist.md) passed by hand on 0.1.3 and 0.1.4 with DAVx⁵ (calendar, contacts, tasks and notes through jtx Board) and Thunderbird (calendar, contacts and tasks). Of the Apple clients, only Contacts on an iPad has been tried. Versions match the [roadmap](../guide.md#roadmap).

| App | What it stores | First version |
|---|---|---|
| [Calendar](calendar.md) | Events (`VEVENT`) in a CalDAV collection | Version 1 |
| [Contacts](contacts.md) | Contacts in a CardDAV address book | Version 1 |
| [Tasks](tasks.md) | Tasks (`VTODO`) in a CalDAV collection | Version 1 |
| [Notes](notes.md) | Notes (`VJOURNAL`) in a CalDAV collection | Version 1 |
| [Files](files.md) | Ordinary files over WebDAV | Version 1.x (0.4.0) |
| [Admin panel](admin.md) | Users, collections, and server settings | Version 1 |

The user portal is not a separate server. It arrives in Version 2. Each page says what the portal does for that app, and what stays in the admin panel.

Client setup for all of them is in the [guide](../guide.md#client-setup-and-compatibility).
