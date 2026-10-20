# Notes

A note is a CalDAV `VJOURNAL`. Notes are not a separate server. They live in a CalDAV collection, the same kind of collection as a [calendar](calendar.md). The admin panel creates that collection. A client that understands `VJOURNAL` writes the notes.

Few clients do. Apple Notes and Thunderbird do not use `VJOURNAL`, so notes stored here will not appear in those apps.

## What each version includes

**Version 1.** The admin panel can create a collection that accepts notes. A capable CalDAV client can create, edit, and delete notes. Sync uses the collection sync token. The admin panel does not edit the note.

**Version 1.x.** Sharing and WebDAV-Push follow the collection, as they do for [calendars](calendar.md). There is no separate note-sharing model.

**Version 2.** Import and export of `.ics` files includes notes. The user portal can edit that person's own notes. This is the first NexusDAV screen that edits a note. The admin panel still does not.

**Version 2.x.** No note-specific mail.

## Who does what

| Action | CalDAV client | Admin panel | User portal |
|---|---|---|---|
| Create the collection that holds notes | No | Version 1 | Version 2, for that person's own collections |
| Create and edit notes | Version 1, only if the client supports `VJOURNAL` | No | Version 2 |
| Show the note | The client, when it supports `VJOURNAL` | No | Version 2 |

The client that is expected to show notes is DAVx⁵ together with jtx Board. Other clients are best effort. Nothing has been tested yet. See [Client compatibility](../../README.md#client-compatibility).

## Not in the plan yet

- Sync with Apple Notes or Thunderbird
- A note editor in the admin panel
- Sharing one note with someone who is not already shared on the whole collection
- Trash and restore of a deleted note
