# Files

Files is ordinary WebDAV storage for a user's own documents and other files. It is not CalDAV, and it is not a shared drive. The name in the product is Files. The home URL is `/dav/files/<username>/`.

There is no Files feature in Version 1. Calendars, contacts, tasks, and notes do not need it.

## What each version includes

**Version 1.** Nothing. A client that looks for `/dav/files/` will not find a file home.

**Version 1.x.** Each user has a private file home. A WebDAV client can upload, download, rename, move, and delete files and folders there. The admin panel can see that the home exists, and turns Files on or off (off after an upgrade until an admin turns it on). The user's app passwords also work for the file home; there are no per-service app password scopes. This version does not include sharing a file or a folder with another user.

**Version 2.** The user portal can manage that person's own files. The admin panel remains the operator's view, not a file editor.

**Version 2.x.** User quotas can limit how much a person stores. Quotas are a server setting, described in the [admin panel](admin.md). A trash for files in the user portal, and editing office documents in the browser, are added.

## Who does what

| Action | WebDAV client | Admin panel | User portal |
|---|---|---|---|
| Upload and download files | Version 1.x | No | Version 2 |
| Create folders, rename, move, delete | Version 1.x | No | Version 2 |
| See that a user's file home exists | No | Version 1.x | Version 2, their own home only |
| Set a storage quota | No | Version 2.x | No |

Large uploads depend on the reverse proxy allowing a large request body. See [Troubleshooting](../guide.md#troubleshooting).

## Not in the plan yet

- Sharing a file or a folder with another user. Sharing in Version 1.x is for calendars and address books only
- Calendar managed attachments. Those belong to an event and are [Version 2.x of Calendar](calendar.md), not the file home
- Sync tokens on the file home. Collection sync in Version 1 is for CalDAV and CardDAV collections
