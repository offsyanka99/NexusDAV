# Admin panel — Settings page (UI draft)

This is a design note. The high-level description lives in [admin.md](../apps/admin.md#after-sign-in).

## Purpose
The Settings is the page an administrator configure server. Enable/Disable options that available for sync and in user portal.

## What the page contains
Aside from other options, this page include the following settings:

### DAV services
 - Enable CalDAV
 - Enable CardDAV
 - Enable Tasks (VTODO)
 - Enable Notes (VJOURNAL)
 - Enable WebDAV file storage
   When WebDAV enabled, the following fields become available and mandatory:
   - Storage path
   - Max file size upload (MB)
   - Quota per user (MB)
   - Deleted user file retention (days)
   - Trash retention (days)
 - WebDAV-Push
   When WebDAV-Push enabled, the following fields become available:
   - Include WebDAV-Push for file storage
   - Max push subscriptions per user
   - Push external URL (HTTPS) (Mandatory)
   - Push log level 

### Session & portal
 - Server timezone
 - Time format (12/24h)
 - Week starts on
 - Email sender
 - Session idle timeout (minutes)
 - Portal log level
