# Files

Files is ordinary WebDAV storage for a user's own documents and other files. It is not CalDAV, and it is not a shared drive. The name in the product is Files. The home URL is `/dav/files/<username>/`.

There is no Files feature in Version 1. Calendars, contacts, tasks, and notes do not need it. Files was added in 0.4.0.

## What each version includes

**Version 1.** Nothing. A client that looks for `/dav/files/` will not find a file home.

**Version 1.x.** Each user has a private file home. A WebDAV client can upload, download, rename, move, and delete files and folders there. The admin panel shows that a user's home exists, and turns Files on or off and sets the maximum upload size (Files is off after an upgrade until an admin turns it on). Released in 0.4.0. See the [guide](../guide.md#files) for the URL, the clients and the backup. The user's app passwords also work for the file home; there are no per-service app password scopes. This version does not include sharing a file or a folder with another user.

**Version 2.** The user portal can manage that person's own files. The admin panel remains the operator's view, not a file editor.

**Version 2.x.** User quotas can limit how much a person stores. Quotas are a server setting, described in the [admin panel](admin.md). A trash for files in the user portal, and editing office documents in the browser, are added.

## Who does what

| Action | WebDAV client | Admin panel | User portal |
|---|---|---|---|
| Upload and download files | Version 1.x | No | Version 2 |
| Create folders, rename, move, delete | Version 1.x | No | Version 2 |
| See that a user's file home exists | No | Version 1.x | Version 2, their own home only |
| Set a storage quota | No | Version 2.x | No |

One upload can be as large as **Maximum upload size** on the Settings page: 128 MB by default, up to 102400 MB (100 GB). Uploads are streamed to disk, so a large file does not need memory. The reverse proxy must allow the same size and long enough timeouts, Cloudflare passes at most 100 MB on its Free and Pro plans, and the data volume needs free space for the whole file. See [Large file uploads](../guide.md#large-file-uploads-files).

## Not in the plan yet

- Sharing a file or a folder with another user. Sharing in Version 1.x is for calendars and address books only
- Calendar managed attachments. Those belong to an event and are [Version 2.x of Calendar](calendar.md), not the file home
- Sync tokens on the file home. Collection sync in Version 1 is for CalDAV and CardDAV collections
