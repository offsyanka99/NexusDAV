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

Sync both devices. Take both offline. Edit the same event on both, with a different title on each. Bring device A online and sync, then device B.

**Pass:** no server error. The second write is refused with 412 (Precondition Failed), which shows on Diagnostics, and the client then fetches the server's version. Both devices end with the same event, and no edit is lost without the client saying so. Record which title won and what the client showed.

### 6. A calendar deleted and created again while a client is offline

1. Create a calendar with the URI `recreate` in the admin panel and add two events to it from a client. Sync every client.
2. Take one client offline.
3. In the admin panel, delete `recreate`, then create a new calendar with the same URI. Add one different event from the other client.
4. Bring the offline client back and sync.

**Pass:** the returning client shows only the new event in `recreate`, and it does not keep failing to sync. A client that falls back to a full sync passes. Old events left on the client are a failure; PR 14 targets this case.

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
| 1 Discovery from the domain | ✅ | skipped | ✅ | skipped | |
| 2a Event | ✅ | skipped | ✅ | skipped | |
| 2b Recurring event with exceptions | ✅ | skipped | ✅ | skipped | |
| 2c Task, completed (jtx Board) | ✅ | skipped | ✅ | skipped | Thunderbird columns: Thunderbird's own tasks |
| 2c Task, completed (Tasks.org) | skipped | skipped | ➖ | ➖ | |
| 2d Note | ✅ | ✅ | ➖ | ➖ | Thunderbird has no notes |
| 2e Contact with a photo | ✅ | skipped | ✅ | skipped | |
| 3 Calendar created from the client | ✅ | ✅ | ➖ | ➖ | Thunderbird cannot create a calendar on the server |
| 4 Offline edits, then sync | ✅ | skipped | ✅ | skipped | |
| 5 Two devices, same item | skipped | skipped | skipped | skipped | |
| 6 Calendar deleted and re-created while offline | skipped | skipped | skipped | skipped | |
| 7 Large collection, first sync | ✅ 10 min 45 s (#1, low priority) | ➖ | ✅ 14 s | ➖ | PostgreSQL only; SQLite skipped by the maintainer. 5,000 events and 2,000 contacts, no errors, all items present. Thunderbird on Ethernet (local network), Pixel 7 Pro on Wi-Fi 5. The second sync fetched only the change: about 1 s in Thunderbird, slightly longer in DAVx5 |
