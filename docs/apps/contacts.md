# Contacts

Contacts are a CardDAV address book. People edit contacts in a contacts client. NexusDAV stores the address book and syncs it.

The address book URL looks like `/dav/addressbooks/<username>/<addressbook-id>/`. The address uses the collection identifier, not the display name. Most clients only need `https://<your-domain>/dav/` and discover the address book from there.

## What each version includes

**Version 1.** The admin panel can create an address book for a user, and a CardDAV client can create one for its own user (extended `MKCOL`). A CardDAV client can create, edit, and delete contacts in it. Sync uses the collection sync token. There is no sharing and no email.

**Version 1.x.** An admin can share an address book with another user on this server, read-only or read-write. The other user's client shows it in their own address book home; clients do not create shares. WebDAV-Push can notify a subscribed client when the address book changes. Push uses the bitfire draft `draft-bitfire-webdav-push-00` at commit `611abe2e8a0cdd7570cb936844b6588f7145b27d`, until that project publishes a release. The admin panel turns push on or off for the server.

**Version 2.** The user portal can manage that person's own address books and contacts. Import and export of `.vcf` files is included. Search can find contacts. Duplicate contacts can be found and merged.

**Version 2.x.** When an address book is shared, the server can email the sharee through the external SMTP server. That notice is outbound only. Trash and restore of a deleted contact, and a direct import from Radicale, Baïkal or Nextcloud, are added.

## Who does what

| Action | CardDAV client | Admin panel | User portal |
|---|---|---|---|
| Create the address book | Version 1, for that person's own address books | Version 1 | Version 2, for that person's own address books |
| Create and edit contacts | Version 1 | No | Version 2 |
| Share the address book with another local user | No. A client shows an address book shared with that user | Version 1.x | Version 2 |
| Email the sharee about a new share | No | Version 2.x | Version 2.x |

Target clients are DAVx⁵, Thunderbird, and Apple Contacts. Automated tests with vdirsyncer and the python caldav client pass, and the [client checklist](../testing/client-checklist.md) passed by hand on 0.1.3 and 0.1.4 with DAVx⁵ and Thunderbird, including contacts with a photo. The native Contacts app on an iPad synced 2,000 contacts on 0.1.4. In DAVx⁵ a first sync of a few thousand contacts takes minutes, on the phone side ([#1](https://github.com/offsyanka99/NexusDAV/issues/1)). See [Client compatibility](../guide.md#client-compatibility).

## Not in the plan yet

These are not assigned to a version:

- Sending one contact to someone by email. That needs the Version 2.x mailer and is still a separate feature
