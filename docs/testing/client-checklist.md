# Client checklist

A hand test with real clients, run before a release on the devices in [Decision 15](../plans/implementation-plan-v1x.md#decisions): **DAVx5** with **jtx Board** on Android, and **Thunderbird** on Linux. Automated tests cover the protocol; this checklist covers what the clients actually do with it.

Run every step for each client on both databases, record one result per cell in [Results](#results), and open a GitHub issue for each failure (see [When a step fails](#when-a-step-fails)). Later milestones add their own sections ([Sharing](#11-sharing) and [WebDAV-Push](#12-webdav-push) are in; [Files](files-testing.md) and [push testing](push-testing.md) have their own checks). Before a release, run the sections the release touched; the results also go in the guide's [client compatibility](../guide.md#client-compatibility) table.

## Setup

### Servers

Run two servers from the build under test, both behind Caddy over HTTPS with a trusted certificate, so clients behave as in production:

| Server | Compose files |
|---|---|
| PostgreSQL | `compose.yaml`, `compose.postgres.yaml`, [`docs/proxy/compose.caddy.yaml`](../proxy/compose.caddy.yaml) |
| SQLite | `compose.yaml`, `compose.sqlite.yaml`, [`docs/proxy/compose.caddy.yaml`](../proxy/compose.caddy.yaml) |

To test the current `main` rather than a release, add `compose.build.yaml` last and `--build` (see [CONTRIBUTING.md](../../CONTRIBUTING.md#development-setup)). Write the image tag or commit in the run log; the Dashboard shows the version.

`/.well-known/caldav` and `/.well-known/carddav` must reach the server; see [Reverse proxy and `.well-known`](../guide.md#reverse-proxy-and-well-known).

### Accounts

On each server, in the admin panel:

1. Create a user `checklist` (not an admin).
2. Create one app password per device and client, for example `pixel-davx5` and `laptop-thunderbird`. Keep the default calendar and address book.
3. Keep the **Diagnostics** page open in a browser tab and check it after each step. A 5xx is always a failure. Write any new 4xx rows in the step's notes; clients probe a few paths during discovery, so a 4xx is a failure only when the step's result is wrong too.

### Clients

Write the version of each app in the run log.

- **DAVx5**: add an account with **Login with URL and user name**. For the task steps, use **jtx Board** as the tasks app.
- **Thunderbird**: add the calendar with **New Calendar → On the Network**, and the address book with **New Address Book → Add CardDAV Address Book**. Thunderbird does not show notes (VJOURNAL); those cells are "n/a".

Step 5 needs two devices: two Android phones, or one phone and Thunderbird.

## Steps

Each step lists what to do and what must be true for a pass. "Sync" means the client's own sync: pull to refresh in DAVx5, **Synchronize** in Thunderbird.

### 1. Discovery from the domain only

Add the account with only `https://<your domain>` (no path), the username and the app password.

**Pass:** the client finds the default calendar and the default address book without a path being typed.

### 2. Items

Make each change on one client, then check it on the other client after a sync.

| ID | Do | Pass |
|---|---|---|
| 2a | Create an event, change its title and time, then delete it | Each change shows on the other client; the event is gone everywhere after the delete |
| 2b | Create a weekly recurring event. Move one occurrence to another day (an exception), and delete another occurrence | The other client shows the series, the moved occurrence on its new day, and not the deleted one |
| 2c | Create a task with a due date, then mark it completed | The task shows on the other client, then shows as completed. DAVx5 with jtx Board |
| 2d | Create a note in jtx Board, edit it, then delete it | The note survives a sync (check with a second device, or remove and add the account again). Thunderbird: n/a |
| 2e | Create a contact with a photo, a phone number and an email, edit it, then delete it | The other client shows the photo and the fields; the delete is synced |

### 3. A calendar created from the client

In DAVx5, open the account and create a new calendar. Add an event to it.

**Pass:** the calendar shows in the user's **Calendars** table in the admin panel with one object, and in Thunderbird after it is added there. Delete it from DAVx5; it is gone from the panel. Thunderbird cannot create a calendar on the server: n/a.

### 4. Offline edits, then sync

Take a client offline (airplane mode, or **File → Offline → Work Offline** in Thunderbird). Create an event, edit another one and delete a third; do the same with three contacts. Go online and sync.

**Pass:** all six changes reach the server and the other client. Nothing is duplicated, and nothing deleted comes back.

### 5. Two devices editing the same item

Sync both devices. Take both offline. Edit the same event on both, with a different title on each (`Title A` on device A, `Title B` on device B). Bring device A online and sync, then device B.

**Expected:** the first device to reach the server wins. The server refuses the second device's write with **412 (Precondition Failed)**, because the event changed after that device last synced. The clients show no message: device B keeps showing `Title B` until its next sync, then shows `Title A`, and its own edit is lost. This is how CalDAV works (RFC 4791). Only a client sync updates what an app shows; WebDAV-Push (Version 1.x, M5) will make the refresh faster.

**Pass:**
- Diagnostics, under **Latest DAV errors**, has a `PUT` with status **412** for that event, and no 5xx.
- After both devices sync again, both show the same title, the first writer's (`Title A`).

**Fail:** no 412 and the first writer's title is gone (the second write silently replaced it), or the devices still show different titles after two syncs, or a 5xx. Record which title won and whether the 412 was there.

Run it a second time with both devices online, saving within a second of each other. The result is the same: one wins, the other gets a 412.

### 6. A calendar deleted and created again while a client is offline

This checks what a client does when the calendar it knows is replaced by a new one with the same address. Use two clients: **client A** is the one being tested and **client B** is any other client. Run it once with the phone as A and Thunderbird as B (the DAVx5 column), then once the other way round (the Thunderbird column).

1. **Create the calendar.** In the admin panel open **Users**, then the test user, and in the **Calendars** table choose **Add Calendar**. Set the URI to `recreate` and the name to `Recreate`, and save.
2. **Add two events.** On client A, add `Recreate` (DAVx5: refresh the account and tick it; Thunderbird: add the calendar from its URL, `https://<your domain>/dav/calendars/<user>/recreate/`). Create two events in it, `Old 1` and `Old 2`, and sync. Check that client B shows both (add `Recreate` there too).
3. **Take client A offline.** Android: airplane mode. Thunderbird: **File → Offline → Work Offline**. Leave the calendar on client A as it is.
4. **Replace the calendar.** In the admin panel, in the same Calendars table, **Delete** `Recreate`, then **Add Calendar** again with the same URI `recreate` and the name `Recreate`. It is now an empty, new calendar. On client B, refresh so it picks up the new calendar, and create one event, `New 1`.
5. **Bring client A back online** and sync. Wait for the sync to finish, then sync once more.

**Pass:** client A shows `Recreate` with only `New 1`. `Old 1` and `Old 2` are gone, and syncing does not keep failing. It does not matter whether the client got there by a full re-sync or in some other way.

**Fail:** `Old 1` or `Old 2` stays on client A, or client A reports a sync error, or shows no events at all after two syncs. Write down which, with the client version, and follow [When a step fails](#when-a-step-fails). This is the case PR 14 is meant to fix, so a failure here is a useful result, not a mistake in the test.

The clients keep the calendar's old display name: Thunderbird uses the name chosen when the calendar was added, and DAVx5 updates names only on **Refresh list**. The URL is what identifies the calendar, so the name does not affect the result.

Delete `Recreate` in the panel afterwards.

### 7. First sync of a large collection

1. Create the test data on the server. It goes into new collections named `load-test`; nothing else of the user changes:

   ```bash
   # Use compose.postgres.yaml or compose.sqlite.yaml, matching how the stack was started.
   docker compose -f compose.yaml -f compose.postgres.yaml exec -u nexusdav nexusdav \
     php artisan nexusdav:seed-test-data checklist --force
   ```

   The defaults are 5,000 events (spread from a year back to a year ahead; every 10th repeats weekly, every 25th is all-day) and 2,000 contacts (every 20th has a photo). `--events=` and `--contacts=` change the counts, up to 50,000 each, and `--calendar=` and `--address-book=` change the URIs. Without `--force` the command only says what it would write. An existing `load-test` collection is never changed; delete it in the panel first, or choose another URI. The command itself takes a few seconds.

2. On the client, add `load-test` (or add the account again) and sync. Note how long the first sync takes.
3. Change one event from another client and sync again.

**Pass:** the client shows 5,000 events and 2,000 contacts (check a few weeks of the calendar and a contact search). The first sync finishes without an error. The second sync fetches only the changed event and is fast. Record both times.

Delete the `load-test` calendar and address book in the panel when the step is done.

### 8. Many uploads at once (SQLite most of all)

No client is needed; this is the load that failed in [#3](https://github.com/offsyanka99/NexusDAV/issues/3). Create an app password for a test user, then upload 5,000 events with 16 parallel writers. Run it on both servers. Set the variables first:

```bash
export B=https://<your domain> U=checklist P='<app password>' RUN=$(date +%s)
curl -s -u "$U:$P" -X MKCALENDAR "$B/dav/calendars/$U/parallel/"

put() {
  n=$1; day=$(( n % 28 + 1 ))
  printf 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//checklist//EN\r\nBEGIN:VEVENT\r\nUID:p-%s-%s\r\nDTSTAMP:20261104T120000Z\r\nDTSTART:202612%02dT090000Z\r\nSUMMARY:Parallel %s\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n' "$n" "$RUN" "$day" "$n" |
    curl -s -o /dev/null -w '%{http_code}\n' -u "$U:$P" -X PUT -H 'Content-Type: text/calendar' \
      -H 'If-None-Match: *' --data-binary @- "$B/dav/calendars/$U/parallel/p-$n-$RUN.ics"
}
export -f put

seq 1 5000 | xargs -P 16 -I{} bash -c 'put {}' | sort | uniq -c
```

**Pass:** `5000 201`. Then count the events (a `PROPFIND` with `Depth: 1` on the calendar lists 5,000 `.ics` entries). Nothing is a 500. A few `503` with `Retry-After` are acceptable only if the same events are then accepted when sent again; Diagnostics shows them as 503. On 0.1.5 this printed 500 "database is locked" on SQLite.

**Fail:** any 500, a missing or duplicated event. Delete the `parallel` calendar in the panel afterwards.

### 9. Admin panel

In the admin panel on each server, with the language set to English, then Russian, then Ukrainian:

| ID | Do | Pass |
|---|---|---|
| 9a | Open **Configuration**. Set **Time format** to 24-hour and **Week starts on** to Monday. Edit a user, open **App passwords → Generate**, and open the **Expires** picker | The calendar starts on Monday and the time has hours and minutes with no AM/PM. Set 12-hour and Sunday: the picker follows |
| 9b | On a user with an event, a task and a note created from the clients, open **Calendars** | The **Events**, **Tasks** and **Notes** columns each show their own count |
| 9c | Open the container log in Dozzle (or `docker compose logs`) while a client syncs, and then request `/admin` without signing in | Request lines show `INFO`, a 4xx shows `WARNING` and a 5xx `ERROR`, so Dozzle colours them |
| 9d | **Diagnostics** after the whole run | No 5xx rows. 4xx rows are only discovery probes, the 412 from step 5 and the 403 from step 6 |
| 9e | Choose **Українська** in the user menu, then open every page and dialog: Dashboard, Users (list, create, a user with each tab, the app password, share and delete dialogs), Settings, Configuration, Diagnostics (the status codes dialog and the push and worker cards). Save a form with a wrong value, and wait until the session ends | Every text is Ukrainian, apart from names, paths and values. Notifications and validation messages are Ukrainian. Plurals read right for 1, 2, 5 and 21. Nothing is cut off, and relative times ("5 хвилин тому") are Ukrainian |
| 9f | Set **Server default language** to Українська, sign out, and open the login page. Then set `NEXUSDAV_LOCALE=uk` in the environment, restart, and open Configuration | The login page is Ukrainian. The field shows **Set by NEXUSDAV_LOCALE** and is locked |

### 10. Offline for longer than the sync history (optional)

The sync history is kept for **Keep sync history for (days)** (default 90, at least 1). A client that was offline longer than that must get one **403** `valid-sync-token` and then sync the collection from the start. Two things make a real run take long, and a run that skips them proves nothing:

- The history is trimmed only when a DAV write happens, at most once an hour. A client that comes back after only reading gets no trimming.
- Only changes older than the setting are trimmed. A client that was offline for exactly the setting, with its other device changing things a few minutes after it went offline, still finds those changes.

**Real client (about 26 hours):** set the setting to 1 day and sync the client. Take it offline, and a few minutes later change and delete some events from another device. About 25 hours after those changes, edit any event from another device (that write trims the history). Then bring the client back and sync.

**Fast check without a client (about 5 minutes per server).** It uses the same server code:

1. With a test calendar `step10` of the test user, take a sync token with a `sync-collection` REPORT (empty token, sync level 1), then add and delete a few events and note the calendar's number in the token (`.../sync/<id>-<n>`).
2. In the server container, make that calendar's change rows look old and run the pruner (replace `<id>`). Every database uses the same command:

   ```bash
   docker exec -u nexusdav <container> php artisan tinker --execute='DB::table("calendarchanges")->where("calendarid", <id>)->update(["created_at" => now()->subDays(100)]); Cache::forget("nexusdav.sync_history.prune"); app(App\Services\Dav\SyncHistoryPruner::class)->afterWrite(); echo json_encode(DB::table("calendars")->where("id", <id>)->first(["id","synctoken","synctoken_min"])), "\n";'
   ```

   `synctoken_min` must now be above the number in your token. The command also trims other history older than the saved setting, so other test clients may get one full resync.
3. A `sync-collection` with the old token answers **403** `valid-sync-token`. One without a token lists exactly the events that exist, and its new token works for the next change.

**Pass:** the 403, then a full listing that matches the server, then a working incremental sync. For a real client: one full resync, and its events match the server (the deleted ones are gone).

### 11. Sharing

Run this step for the M3 release (and whenever sharing code changes), on both servers. It needs three users and, for each sharee, a device or client of its own:

| User | Role | App passwords |
|---|---|---|
| `owner` | Shares its calendar and address book | One per client (DAVx5, Thunderbird) |
| `rw` | Sharee with **Read and write** | One per client |
| `ro` | Sharee with **Read only** | One per client |
| `other` | Not a sharee, to check isolation | One |

Give `owner` an event, a task and a note in its default calendar (note and task through DAVx5 with jtx Board), and two contacts in its default address book. Set up DAVx5 and Thunderbird for `owner`, `rw` and `ro` (Thunderbird: one profile, or three, as you prefer; DAVx5: one account per user on the phone, or one phone per user). Keep Diagnostics open.

**Setup in the panel (do this once, and it is step 11a):** open `owner`, then **Calendars → Share**: choose `rw`, set **Read and write**, **Share**. Open **Share** again: choose `ro`, set **Read only**, **Share**. Do the same on **Address books**.

| ID | Do | Pass |
|---|---|---|
| 11a | After the setup, open **Shared with** on the calendar and on the address book. Then try one **Share** with `rw` and `other` chosen together at **Read only** | The table lists `rw` (Read and write) and `ro` (Read only) with their emails; **Shared** shows 2. The two-user form adds both. Remove `other` again with **Remove** in the table |
| 11b | In each client of `rw` and `ro`, sync (DAVx5: **Refresh list**; Thunderbird: re-add or sync) | The calendar "Calendar (Owner)" and the address book appear in each home, with the owner's event, task, note and contacts. `owner`'s other collections and account are not listed |
| 11c | As `ro`, in the calendar: edit the event, add an event, add a task, edit the note | The client shows the calendar as read-only or refuses the save; after sync the owner's items are unchanged. Diagnostics shows 403 rows for the refused writes and no 5xx |
| 11d | As `rw`, in the calendar: add an event, edit the task, add a note, delete the event | The change appears in `owner`'s clients and in `ro`'s clients after sync, as exactly that change |
| 11e | As `owner`, edit the event and add a task | `rw` and `ro` see it after sync, and nothing else is re-sent |
| 11f | As `rw`, rename the shared calendar and change its color on the device | The name stays on that device; `owner`'s calendar and `ro`'s copy keep their names, and `owner`'s clients show no sync of that change |
| 11g | As `ro`, in the address book: add, edit and delete a contact | DAVx5 marks the address book read-only and refuses; Thunderbird refuses or shows an error. The owner's contacts are unchanged. Thunderbird may keep a stale Read-only flag from an earlier access level; note it if the flag and the server disagree |
| 11h | As `rw`, in the address book: add a contact, edit one, delete one | `owner`'s Thunderbird and DAVx5 show the change after sync, and `ro` too. Then change a contact as `owner`: `rw` sees it |
| 11i | In the panel, change `rw` to **Read only** in the **Shared with** table (in the row), then back to **Read and write** | After a sync, writes by `rw` are refused (403), then accepted again. Thunderbird keeps its own Read-only flag: untick it and restart (known; record it) |
| 11j | In **Shared with**, select `rw` and `ro` and use **Set access**; then select one and use **Remove** | Both rows take the chosen level; after the remove the person is gone from the table and the Shared count drops |
| 11k | In the panel, remove `ro`'s calendar share (single **Remove**, with the confirmation). Sync `ro`'s clients | The calendar leaves `ro`'s client (DAVx5: **Refresh list**; Thunderbird: delete the stale entry). The owner's calendar and events are intact; `ro`'s own calendar is intact |
| 11l | On a user page, open **Shared with this user** for `rw` | The tab lists the calendar and the address book with the owner, the access and the DAV path. **Remove** removes that one share and nothing else |
| 11m | As `rw`, in DAVx5 switch syncing off for the shared address book, and in Thunderbird remove the shared address book | Syncing stops on the device; `owner`'s address book is intact. The share is still listed in the panel (an admin removes it there) |
| 11n | **Settings → Services → Sharing** off, **Save**. Sync `rw`'s clients. Check the **Dashboard** Services card. Then on again | Off: the shared collections leave `rw`'s clients after sync, and their paths answer 404; the Services card shows Sharing off; **Shared with** and **Shared with this user** still list the shares; `owner`'s collections are unchanged. On: they return. Set `NEXUSDAV_SHARING=false` in the environment: the field is locked with "Set by NEXUSDAV_SHARING" and the card shows the lock |
| 11o | Isolation: with `rw`'s app password request `/dav/addressbooks/owner/`, `/dav/calendars/owner/` and `/dav/principals/owner/` (curl `PROPFIND`). With `other`'s app password request the share's path under `/dav/calendars/rw/` | 404 for all. `other` lists no shared collection |
| 11p | Two devices, same item: edit the same contact (or event) on `owner`'s device and on `rw`'s device while both are offline, then sync them one after the other | One version wins; the other device gets the winner after its sync (a 412 may show on Diagnostics); nothing is lost silently, and no 500 |
| 11q | Delete the shared calendar in the panel as `owner` (read the confirmation), then sync `rw` | The confirmation says its shares are removed. The calendar leaves `rw`'s client |
| 11r | Delete the user `rw` in the panel (read the confirmation), after it received shares | The confirmation lists the shares `rw` received. The shares of `owner` to `rw` are gone from **Shared with** |
| 11s | Switch the panel to Russian and open **Share**, **Shared with**, the **Set access** and **Remove** dialogs, **Shared with this user**, and **Settings** | The text is in Russian and nothing is cut off. The **Shared with** table scrolls inside its panel with many sharees |
| 11t | **Diagnostics** after the whole run | No 5xx rows. 4xx rows are only discovery probes, the refused writes in 11c, 11g and 11i, the 412 in 11p, and the 404 in 11n and 11o |

**Fail:** any 500; a write by `ro` that is saved; `rw`'s change missing for `owner`; a collection of `owner` other than the shared one visible to a sharee; a share still shown after it was removed and the client synced; the owner's data changed by a rename, a removal or leaving a share.

### 12. WebDAV-Push

Run this step for the M5 release (and whenever push code changes), on both servers. It needs the queue worker (Compose: `COMPOSE_PROFILES=worker`; TrueNAS: the worker block uncommented), the phone with DAVx⁵ 4.4.10+ and a push service picked under **DAVx⁵ → Settings → Push** (the ntfy app, or FCM), and Thunderbird as the "other device". Keep the users of step 11 (`owner` shares its calendar with `rw`; the phone has a DAVx5 account for `owner` and one for `rw`). For a self-hosted ntfy on the LAN, turn on **Allow private networks**. The server-side steps with the worker log are P1 to P12 in [push testing](push-testing.md).

| ID | Do | Pass |
|---|---|---|
| 12a | **Settings → WebDAV-Push** on, **Save**. Open the **Dashboard** | The help under the switch says the worker is running. Services shows WebDAV-Push **On** and Queue worker **Running**; Needs attention is quiet |
| 12b | On the phone, sync both DAVx5 accounts. Open **Diagnostics → Push subscriptions** | One row per synced calendar and address book of `owner` and `rw`, with the push service host (ntfy or the FCM gateway), an expiry about three days ahead and **No push yet**. No 403 for `POST` under Latest DAV errors |
| 12c | Lock the phone. In Thunderbird as `owner`, add an event to the default calendar | Within about 10 seconds the event is on the phone without a manual sync. The calendar's row on Diagnostics shows **Delivered** and a **Last push** time |
| 12d | In Thunderbird as `owner`, add a contact | The contact is on the phone within about 10 seconds |
| 12e | In Thunderbird as `owner`, edit an event of the calendar shared with `rw` | `rw`'s DAVx5 account on the phone shows the change within seconds, through the same topic |
| 12f | In Thunderbird add three events quickly | One push and one sync on the phone, not three |
| 12g | In the panel, revoke `rw`'s app password used by the phone. Change the shared calendar in Thunderbird | `rw`'s subscriptions are gone from Diagnostics and nothing reaches `rw`'s account. Sign `rw` in again with a new app password afterwards |
| 12h | Stop the worker (`docker stop <worker>`). Open the Dashboard, then add an event in Thunderbird. Wait a minute, then start the worker | Within five minutes the Dashboard shows Queue worker **Not running** and a Needs attention line; Diagnostics shows the last heartbeat and jobs waiting. Nothing reaches the phone while it is stopped; the push arrives within seconds of the start |
| 12i | **Revoke** one subscription on Diagnostics, then sync that DAVx5 account | The row disappears, then comes back after the sync (DAVx5 subscribes again) |
| 12j | **WebDAV-Push** off, **Save**. Sync the phone | The Push subscriptions table says push is off; the phone keeps syncing on its schedule. Calendars and contacts work as before |
| 12k | Switch the panel to Russian: the WebDAV-Push section of Settings, the Services card, the Needs attention line and the two Diagnostics cards | The text is in Russian and nothing is cut off |
| 12l | **Diagnostics** after the whole run | No 5xx rows |

**Fail:** a change that does not reach the phone within a minute while the worker runs; more than one push for one change or a burst; a push after the app password was revoked; a subscription row that shows a full push address or a key; any 5xx.

## When a step fails

1. Note the step ID, the client and its version, the database, and what happened.
2. Copy the request IDs of the related rows on the Diagnostics page, and the matching lines from `docker compose logs nexusdav` (search for the request ID).
3. Open a GitHub issue titled `Client pass: <step> — <client> — <short description>`, with the label **`m2-client-pass`** and the details above. Leave out app passwords and personal data.
4. Put `❌ #<issue>` in the results cell.

## Results

Legend: ✅ pass · ❌ fail (issue number) · ➖ n/a · skipped = not run in pass 1 · ⬜ not run yet.

### Pass 1 (M2, PR 13)

The first pass, on the current `main`.

| Run log | |
|---|---|
| Date | 3 November 2026 |
| Build (Dashboard version or commit) | 0.1.3 |
| Tester | Maintainer |
| PostgreSQL server | PostgreSQL behind HTTPS, v0.1.3 (nexusdav.vovchenko.org). Test data for step 7 uploaded over DAV, not with the seeder |
| SQLite server | Not run (skipped by the maintainer) |
| DAVx5 + jtx Board | DAVx5 4.5.21 + jtx Board 2.17.00. Google Pixel 7 Pro, Android 17 |
| Thunderbird | 159.0, Ubuntu Snap build (ubuntu-snap-build 1.0), on Ethernet |

| Step | DAVx5, PostgreSQL | DAVx5, SQLite | Thunderbird, PostgreSQL | Thunderbird, SQLite | Notes |
|---|---|---|---|---|---|
| 1 Discovery from the domain | ✅ | ✅ | ✅ | ✅ | |
| 2a Event | ✅ | ✅ | ✅ | ✅ | |
| 2b Recurring event with exceptions | ✅ | ✅ | ✅ | ✅ | |
| 2c Task, completed (jtx Board) | ✅ | ✅ | ✅ | ✅ | Thunderbird columns: Thunderbird's own tasks |
| 2d Note | ✅ | ✅ | ➖ | ➖ | Thunderbird has no notes |
| 2e Contact with a photo | ✅ | ✅ | ✅ | ✅ | |
| 3 Calendar created from the client | ✅ | ✅ | ➖ | ➖ | Thunderbird cannot create a calendar on the server |
| 4 Offline edits, then sync | ✅ | ✅ | ✅ | ✅ | |
| 5 Two devices, same item | ✅ | ✅ | ✅ | ✅ | |
| 6 Calendar deleted and re-created while offline | ✅ #2 | ✅ #2 | ✅ #2 | ✅ #2 | Old events stayed on both clients, with no error, until DAVx5 Refresh list or a new Thunderbird subscription. PR 14 targets this |
| 7 Large collection, first sync | PostgreSQL: ✅ 10 min 45 s for both collections (#1) | SQLite: ✅ calendar 29 s, contacts 10 min (#1) | PostgreSQL: ✅ 14 s for both | SQLite: ✅ calendar 22 s, contacts about 2 s | 5,000 events and 2,000 contacts, no errors, all items present. Thunderbird on Ethernet, Pixel 7 Pro on Wi-Fi 5. The second sync fetched only the change: about 1 s in Thunderbird, slightly longer in DAVx5. SQLite run on 0.1.4, contacts and calendar timed separately. The slow part is DAVx5 contacts on both databases (about 300 ms per contact, against 6 ms per event); the server answers its 200 requests in about 2.5 s, so it is the client (#1). An iPad (native Contacts) loaded all 2,000 contacts in 74 s, and the server log stayed quiet for 5 minutes afterwards |

**Step 7 split by collection on 0.1.4, PostgreSQL** (4 November 2026, times from the server log):

| Client | Calendar (5,000 events) | Contacts (2,000) |
|---|---|---|
| Thunderbird | 27 s | not run |
| DAVx5, Pixel 7 Pro | not run | 9 min 47 s |
| iPad, native app | 2 min 3 s | not run |

DAVx5's debug log of that contacts sync shows 153 requests to the server taking **12 s in total**, and the phone saving contacts at about **130 per minute** (about 0.45 s each). So about 98 % of the time is on the phone, writing into Android's Contacts storage; see #1.

**Re-check of step 6 on 0.1.4** (after the fix for #2, PR 14): ✅ both clients.

- **Thunderbird:** the sync with the old token got **403** `valid-sync-token`, and the next sync (207) was a full one. The old events were gone, and the calendar was the new one.
- **DAVx5:** the old events were gone. Its log has no 403 because DAVx5 does not use sync tokens when its past-events limit ("Ignore events older than") is set: it lists the whole calendar on every sync and removes local events the server no longer has. Without that setting it takes the same 403 path as Thunderbird, which `SyncTokenTest` covers.
- Both clients kept the old display name until the calendar list was refreshed; see the note in step 6.

**Second re-check on 0.1.4** (4 November 2026, PostgreSQL, sync token `14-1` in the DAVx5 log), with an iPad added:

- **Thunderbird:** ✅ again **403**, then a full sync (207). The old event was gone.
- **DAVx5:** ✅ the old event was gone ("Removed 1 local resources which are not present on the server anymore"). The past-events limit was set to **0**, which is still a limit: DAVx5 logged "Sync algorithm: full listing" and used no token. To make DAVx5 use sync tokens, turn the limit off so the field is empty; 0 is not the same.
- **iPad, native Calendar (observation, not part of the test pass):** its sync with the old token got **403** as intended, and it then refreshed the calendar list on its own. It kept the old event and showed it in the new calendar, and sent no `PUT`, so the server never received it again. This is iOS keeping its local copy after the refused token, not a server error.

### Pass 2 (M2 release candidate, PR 19)

Run on the release candidate (the tag that will be released), with both servers on that build.

| Run log | |
|---|---|
| Date | Nov 4 |
| Build (Dashboard version) | 0.1.6 |
| Tester | HummerSoft |
| PostgreSQL server | ✅ |
| SQLite server | ✅ |
| DAVx5 + jtx Board | ✅ |
| Thunderbird | ✅ |
| iPad (iOS 18.5) | ✅ |

| Step | DAVx5, PostgreSQL | DAVx5, SQLite | Thunderbird, PostgreSQL | Thunderbird, SQLite | Notes |
|---|---|---|---|---|---|
| 1 Discovery from the domain | ✅ | ✅ | ✅ | ✅ | Android phone, iPad and Thunderbird (linux) |
| 2a Event | ✅ | ✅ | ✅ | ✅ | Android phone, iPad and Thunderbird (linux) |
| 2b Recurring event with exceptions | ✅ | ✅ | ✅ | ✅ | Android phone, iPad and Thunderbird (linux) |
| 2c Task, completed | ✅ | ✅ | ✅ | ✅ | Android phone, iPad and Thunderbird (linux) |
| 2d Note | ✅ | ✅ | ➖ | ➖ | Tested on the Android phone only|
| 2e Contact with a photo | ✅ | ✅ | ✅ | ✅ | Android phone, iPad and Thunderbird (linux) |
| 3 Calendar created from the client | ✅ | ✅ | ➖ | ➖ | Android phone and iPad. The calendar created from the iPad did not create Notes or Tasks on the server. Only Calendar. |
| 4 Offline edits, then sync | ✅ | ✅ | ✅ | ✅ | Android phone and iPad. |
| 5 Two devices, same item | ✅ | ✅ | ✅ | ✅ | Android phone and iPad. |
| 6 Calendar deleted and re-created while offline | ✅ | ✅ | ✅ | ✅ | Android phone and Thunderbird (linux) |
| 7 Large collection, first sync | ✅ | ✅ | ✅ | ✅ | Android phone, iPad and Thunderbird (linux) |
| 8 Many uploads at once | ➖ | ➖ | ➖ | ➖ | PostgreSQL: ✅ 5,000 × 201 · SQLite: ✅ 5,000 × 201 (16 parallel writers, no client needed, 4 November 2026) |
| 9 Admin panel | ➖ | ➖ | ➖ | ➖ | PostgreSQL: ✅ · SQLite: ✅ |
| 10 Offline longer than the history (optional) | ➖ | ➖ | ➖ | ➖ | PostgreSQL: ✅ · SQLite: ✅ by the fast check (5 November 2026): old token 403 `valid-sync-token`, full listing of the 4 existing events, new token works. No real client was offline long enough: an iPad offline for 24 h 4 min got no 403, because nothing had been trimmed yet (see the step text) |


### Pass 3 (M3 release candidate, PR 27)

| Run log | |
|---|---|
| Date | Nov 7 |
| Build (Dashboard version) | 0.3.0+404d77b |
| Tester | HummerSoft |
| PostgreSQL server | ✅ |
| SQLite server | ✅ |
| DAVx5 + jtx Board | ✅ |
| Thunderbird | ✅ |
| iPad (optional) | ✅ |

| Step | DAVx5, PostgreSQL | DAVx5, SQLite | Thunderbird, PostgreSQL | Thunderbird, SQLite | Notes |
|---|---|---|---|---|---|
| 11a Panel: share, Shared with | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11b Shared collections appear | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11c Read-only calendar refuses writes | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11d Read-write calendar, sharee changes seen | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11e Owner changes seen by sharees | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11f Sharee's own name and color | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11g Read-only address book refuses writes | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11h Read-write address book both ways | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11i Access changed in the panel | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11j Bulk set access and remove | ➖ | ➖ | ➖ | ➖ | Panel only. PostgreSQL ✅ · SQLite ✅ |
| 11k Share removed in the panel | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11l Shared with this user, remove | ➖ | ➖ | ➖ | ➖ | Panel only. PostgreSQL ✅ · SQLite ✅ |
| 11m Leaving a share on the device | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11n Sharing off and on, the Services card, the lock | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11o Isolation | ➖ | ➖ | ➖ | ➖ | Curl. PostgreSQL ✅ · SQLite ✅ |
| 11p Two devices, same shared item | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11q Delete the shared calendar | ✅ | ✅ | ✅ | ✅ | DAVx5, iPad and Thunderbird |
| 11r Delete a user with received shares | ➖ | ➖ | ➖ | ➖ | Panel only. PostgreSQL ✅ · SQLite ✅ |
| 11s Russian text | ➖ | ➖ | ➖ | ➖ | Panel only. PostgreSQL ✅ · SQLite ✅ |
| 11t Diagnostics | ➖ | ➖ | ➖ | ➖ | PostgreSQL ✅ · SQLite ✅ |

### Pass 4 (M5 release candidate, PR 39)

| Run log | |
|---|---|
| Date | Nov 10 |
| Build (Dashboard version) | 0.5.0-rc.1+1908632 |
| Tester | HummerSoft |
| PostgreSQL server | ✅ |
| SQLite server | ✅ |
| DAVx5 and its push service (ntfy or FCM) | Google and local (private ntfy) |
| Thunderbird | ✅ |

| Step | PostgreSQL | SQLite | Notes |
|---|---|---|---|
| 12a Push on, Dashboard | ✅ | ✅ | |
| 12b DAVx5 subscribes | ✅ | ✅ | |
| 12c An event reaches the phone | ✅ | ✅ | |
| 12d A contact reaches the phone | ✅ | ✅ | |
| 12e A shared calendar reaches the sharee | ✅ | ✅ | |
| 12f One push for a burst | ✅ | ✅ | |
| 12g Revoked app password | ✅ | ✅ | |
| 12h Worker stopped, then started | ✅ | ✅ | |
| 12i Revoke a subscription | ✅ | ✅ | |
| 12j Push off | ✅ | ✅ | |
| 12k Russian text | ✅ | ✅ | |
| 12l Diagnostics | ✅ | ✅ | |
| Regression: steps 1, 2a, 2e, 4 and 11d with DAVx5 and Thunderbird | ✅ | ✅ | |

### Pass 5 (M6 release candidate, PR 40)

| Run log | |
|---|---|
| Date | Nov 10 |
| Build (Dashboard version) | dev |
| Tester | maintainer |
| PostgreSQL server | ✅ |
| SQLite server | ✅ |
| A native Ukrainian speaker reviewed the text | ✅ |

| Step | PostgreSQL | SQLite | Notes |
|---|---|---|---|
| 9a to 9d Admin panel, in English and Russian (regression) | ✅ | ✅ | |
| 9e Every page and dialog in Ukrainian | ✅ | ✅ | |
| 9f Server default language and `NEXUSDAV_LOCALE=uk` | ✅ | ✅ | |
