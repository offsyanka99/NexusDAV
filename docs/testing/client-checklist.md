# Client checklist

A hand test with real clients, run before a release on the devices in [Decision 15](../plans/implementation-plan-v1x.md#decisions): **DAVx5** with **jtx Board** and **Tasks.org** on Android, and **Thunderbird** on Linux. Automated tests cover the protocol; this checklist covers what the clients actually do with it.

Run every step for each client on both databases, record one result per cell in [Results](#results), and open a GitHub issue for each failure (see [When a step fails](#when-a-step-fails)). Later milestones add their own sections (Sharing, Files, WebDAV-Push). Before a release, run the sections the release touched; the results also go in the guide's [client compatibility](../guide.md#client-compatibility) table.

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

- **DAVx5**: add an account with **Login with URL and user name**. For the task steps, run them once with **jtx Board** and once with **Tasks.org** as the tasks app (DAVx5 account settings), on the same account.
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
| 2c | Create a task with a due date, then mark it completed | The task shows on the other client, then shows as completed. DAVx5: once with jtx Board, once with Tasks.org |
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
| DAVx5, jtx Board, Tasks.org | DAVx5 4.5.21, jtx Board 2.17.00, Tasks.org not run. Google Pixel 7 Pro, Android 17 |
| Thunderbird | 159.0, Ubuntu Snap build (ubuntu-snap-build 1.0), on Ethernet |

| Step | DAVx5, PostgreSQL | DAVx5, SQLite | Thunderbird, PostgreSQL | Thunderbird, SQLite | Notes |
|---|---|---|---|---|---|
| 1 Discovery from the domain | ✅ | ✅ | ✅ | ✅ | |
| 2a Event | ✅ | ✅ | ✅ | ✅ | |
| 2b Recurring event with exceptions | ✅ | ✅ | ✅ | ✅ | |
| 2c Task, completed (jtx Board) | ✅ | ✅ | ✅ | ✅ | Thunderbird columns: Thunderbird's own tasks |
| 2c Task, completed (Tasks.org) | skipped | skipped | ➖ | ➖ | |
| 2d Note | ✅ | ✅ | ➖ | ➖ | Thunderbird has no notes |
| 2e Contact with a photo | ✅ | ✅ | ✅ | ✅ | |
| 3 Calendar created from the client | ✅ | ✅ | ➖ | ➖ | Thunderbird cannot create a calendar on the server |
| 4 Offline edits, then sync | ✅ | ✅ | ✅ | ✅ | |
| 5 Two devices, same item | ✅ | ✅ | ✅ | ✅ | |
| 6 Calendar deleted and re-created while offline | ❌ #2 | ❌ #2 | ❌ #2 | ❌ #2 | Old events stayed on both clients, with no error, until DAVx5 Refresh list or a new Thunderbird subscription. PR 14 targets this |
| 7 Large collection, first sync | PostgreSQL: ✅ 10 min 45 s for both collections (#1) | SQLite: ✅ calendar 29 s, contacts 10 min (#1) | PostgreSQL: ✅ 14 s for both | SQLite: ✅ calendar 22 s, contacts about 2 s | 5,000 events and 2,000 contacts, no errors, all items present. Thunderbird on Ethernet, Pixel 7 Pro on Wi-Fi 5. The second sync fetched only the change: about 1 s in Thunderbird, slightly longer in DAVx5. SQLite run on 0.1.4, contacts and calendar timed separately. The slow part is DAVx5 contacts on both databases (about 300 ms per contact, against 6 ms per event); the server answers its 200 requests in about 2.5 s, so it is the client (#1). An iPad (native Contacts) loaded all 2,000 contacts in 74 s, and the server log stayed quiet for 5 minutes afterwards |
