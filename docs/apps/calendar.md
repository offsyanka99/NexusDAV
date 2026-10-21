# Calendar

A calendar is a CalDAV collection of events (`VEVENT`). People edit events in a calendar client. NexusDAV stores the collection and syncs it. It is not an office suite and it does not replace the client.

The collection URL looks like `/dav/calendars/<username>/<calendar-id>/`. The address uses the collection identifier, not the display name. Most clients only need the server root, `https://<your-domain>/dav/`, and discover the calendar from there.

## What each version includes

**Version 1.** The admin panel can create a calendar collection for a user. A CalDAV client can create, edit, and delete events in it, including recurring events that the client itself knows how to write. Sync uses the collection sync token. There is no sharing, no scheduling, and no email.

**Version 1.x.** A calendar can be shared with another user on this server, with access limited by ACL. WebDAV-Push can notify a subscribed client when the collection changes. Push uses the bitfire draft `draft-bitfire-webdav-push-00` at commit `611abe2e8a0cdd7570cb936844b6588f7145b27d`, until that project publishes a release. The admin panel turns push on or off for the server. Sync reliability improves in this same version line.

**Version 2.** Users on this server can invite each other and check free/busy. That scheduling stays on the server. It does not send email. The user portal can manage that person's own calendars. Import and export of `.ics` files includes events. Search can find events.

**Version 2.x.** Outbound email can invite someone who does not have an account here, and can send reminders. Mail goes through an external SMTP server. NexusDAV does not read the reply, so an emailed accept or decline does not update the event. People who have an account still respond through CalDAV. Managed attachments can store files that belong to an event.

## Who does what

| Action | CalDAV client | Admin panel | User portal |
|---|---|---|---|
| Create the calendar collection | No | Version 1 | Version 2, for that person's own calendars |
| Create and edit events | Version 1 | No | Version 2, for that person's own events |
| Share the calendar with another local user | Version 1.x, if the client supports sharing | Version 1.x | Version 2 |
| Invite another user on this server | Version 2 | No | Version 2 |
| Invite someone who has no account | Version 2.x, by email | No | Version 2.x |
| Turn WebDAV-Push on or off | No | Version 1.x | No |

Target clients for events are DAVx⁵, Thunderbird, and Apple Calendar. Nothing has been tested against them yet. See [Client compatibility](../guide.md#client-compatibility).

## Not in the plan yet

These are not assigned to a version:

- A public read-only link or an ICS subscription URL for people without an account
- A birthdays calendar built from contacts
- A holidays calendar, with the country chosen first
- A custom color on a single event
- Trash and restore of a deleted event
- A direct import from Radicale, Baïkal, or Nextcloud. Version 2 import is an `.ics` file. Until then a connected client can import the file.

NexusDAV does not run chat, and it does not edit office documents inside a calendar.
