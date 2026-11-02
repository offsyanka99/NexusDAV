# Tasks

A task is a CalDAV `VTODO`. Tasks are not a separate server and not a separate protocol. They live in a CalDAV collection, the same kind of collection as a [calendar](calendar.md). The admin panel creates that collection. A client that understands `VTODO` writes the tasks.

## What each version includes

**Version 1.** The admin panel can create a collection that accepts tasks, and so can a CalDAV client for its own user. A capable CalDAV client can create, edit, complete, and delete tasks. Sync uses the collection sync token. The admin panel does not edit the task.

**Version 1.x.** Sharing and WebDAV-Push follow the collection. A shared calendar shares the tasks stored in it. Push uses the same bitfire draft as calendars, and the admin panel is where the server-wide switch lives. See [Calendar](calendar.md).

**Version 2.** Import and export of `.ics` files includes tasks. The user portal can manage that person's own tasks. Search in this version covers events and contacts, not a separate task search.

**Version 2.x.** No task-specific mail. A reminder email belongs to a calendar event alarm, described in [Calendar](calendar.md). Trash and restore of a deleted task is added.

## Who does what

| Action | CalDAV client | Admin panel | User portal |
|---|---|---|---|
| Create the collection that holds tasks | Version 1, for that person's own collections | Version 1 | Version 2, for that person's own collections |
| Create and edit tasks | Version 1, if the client supports `VTODO` | No | Version 2 |
| Show the task list | The client, or a companion app | No | Version 2 |

How clients show tasks:

- **DAVx⁵** syncs the data and does not display it. A tasks app such as jtx Board, Tasks.org, or OpenTasks is required.
- **Thunderbird** is a target for tasks.
- **Apple** shows them in Reminders, not in Calendar.

Automated tests with vdirsyncer and the python caldav client pass. DAVx⁵ with jtx Board was tested by hand with 0.1.1. Thunderbird tasks, Apple Reminders, Tasks.org and OpenTasks have not been tried yet. See [Client compatibility](../guide.md#client-compatibility).

## Not in the plan yet

- A task editor in the admin panel
- Scheduling or invitations for tasks
- Turning a task into an event, or the other way around
