# WebDAV-Push and worker testing (M5)

Hand checks for M5 ([the plan](../plans/implementation-plan-v1x.md#m5--webdav-push-and-the-worker-effort-extra-high)). Each M5 PR adds its steps here: PR 34 (the queue worker), PR 35 and PR 36 (push discovery, registration and the queue), PR 37 (the push that reaches DAVx⁵ on a phone through ntfy) and PR 38 (the panel). The client-side release pass is step 12 of the [client checklist](client-checklist.md#12-webdav-push). The release PR 39 runs all of them on both databases.

## Setup

The worker is optional and starts only with the Compose profile `worker`. It needs nothing else: the same image, environment, `/data` volume and database as the app.

| Server | How to start the worker |
|---|---|
| Docker Compose | Add `COMPOSE_PROFILES=worker` to `.env`, then `docker compose -f compose.yaml -f compose.<database>.yaml up -d` as usual. Or add `--profile worker` to each command |
| TrueNAS custom app | Remove the leading `# ` from every line of the worker block at the end of the YAML (`custom-app.sqlite.yaml` or `custom-app.postgres.yaml`), check that its dataset path, image tag and (PostgreSQL) `DB_PASSWORD` match the app's, and save |

To test a release candidate, set the image tag of **both** services to it, for example `ghcr.io/offsyanka99/nexusdav:0.4.1-rc.1`.

Commands below use the container names of the Compose files, `nexusdav` and `nexusdav-worker`. On TrueNAS the names differ; `docker ps` shows them.

## Automated tests (PR 34) — ✅ done

`WorkerStatusTest` covers the states (running, stale after five minutes, never seen), several workers, the heartbeat at start and at most once a minute, a failing heartbeat write, the status command and the probe. The image smoke test (`docker/smoke-test.sh`, run in CI) starts the image as a worker on the app's volume and checks the heartbeat, that it runs no migrations and no web server, a job queued by the app, and a clean stop.

```bash
php artisan test --filter=WorkerStatusTest
```

## Steps (PR 34)

| ID | Do | Expected |
|---|---|---|
| W1 | Start the worker (see [Setup](#setup)) and wait a minute | `docker ps` shows the worker `healthy` and no port for it. Its log has the cached config lines and no error |
| W2 | `docker exec -u nexusdav nexusdav php artisan nexusdav:worker-status --probe` | "Worker running: <id>, last heartbeat … ago." and "Probe job ran on <id> in … s.", where `<id>` is the worker container's ID. Exit code 0 |
| W3 | `docker logs nexusdav-worker` | A line `Worker probe ran` with the same ID. No "Migrating", no first-admin message, no FrankenPHP |
| W4 | `time docker stop nexusdav-worker` | Stops within a second or two, not after 10 seconds. `docker inspect -f '{{.State.ExitCode}}' nexusdav-worker` prints `0` |
| W5 | Five minutes after W4: `docker exec -u nexusdav nexusdav php artisan nexusdav:worker-status` | "Worker stale: no heartbeat since …", exit code 1. Then `docker start nexusdav-worker`: within a few seconds "Worker running" again |
| W6 | Restart the whole stack (`docker compose … down` then `up -d`, or stop and start the TrueNAS app) | Both containers come back; the app first, then the worker. `nexusdav:worker-status --probe` passes again |
| W7 | **Without the worker:** remove `COMPOSE_PROFILES=worker` (or comment the TrueNAS block again) and `up -d` | The app runs as before. `nexusdav:worker-status` says the worker is stale (or never seen on a new install) |
| W8 | **Regression** (the image now has the `pcntl` extension): sync one calendar change and one contact change with DAVx5, and open the admin panel | Both sync; the panel works; Diagnostics shows no 5xx |

**Fail:** the worker restarts again and again (`docker ps` shows a short uptime), the probe does not run within 30 seconds, the worker answers on port 8080, migrations run in the worker, or `docker stop` takes 10 seconds.

The worker writes a log line only when it runs a job; checking the queue every few seconds while idle is normal and does not show in the log.

## Automated tests (PR 35 to PR 37) — ✅ done

`PushRegistrationTest` covers discovery on and off, the VAPID and topic keys, one topic for a shared collection, registration (201, then 204 for the same push resource), the expiry cap, depth fallback, each precondition, `DELETE` on the registration URL (including another user's), the per-user limit, the client keys stored encrypted, and each cleanup path. `PushChangesTest` covers which topics each change queues (objects, cards, renames, new and deleted collections, shares, file folders), the merge window, the sync token in the message, a shared calendar reaching every subscriber through one topic, `Push-Dont-Notify` (own URLs, another user's URL, `*`), and that a rolled-back or failing change never breaks the write. `PushDeliveryTest` decrypts a sent message as a UnifiedPush client does and verifies its VAPID signature, and covers the SSRF guard (private, loopback, link-local and reserved IPv4 and IPv6, embedded IPv4, names that resolve to them), no redirects, the 64 KB limit, removal after 404 and 410, retry and backoff, and that no URL, key or secret reaches the log.

```bash
php artisan test --filter='PushRegistrationTest|PushChangesTest|PushDeliveryTest'
```

## Steps (PR 35 to PR 37)

P1 to P8 check discovery, registration and the queue; P9 to P12 the push that reaches the phone. They need an image built from these PRs, the worker running, and on the phone DAVx⁵ 4.4.10 or newer with a UnifiedPush distributor (the ntfy app, with ntfy.sh or your own ntfy server).

Switch push on under **Settings → WebDAV-Push** (the worker takes the change with its next job), or with `NEXUSDAV_PUSH=true` in the `environment:` of **both** services (app and worker), then recreate them. Registration lines are logged at `info`; set `NEXUSDAV_PUSH_LOG_LEVEL=debug` on the worker to also see each message sent.

**A self-hosted ntfy on your LAN:** the server refuses to send to private addresses (the SSRF guard). If the ntfy host name resolves to a private address from the NexusDAV container (for example `192.168.x.x` through local DNS), set `NEXUSDAV_PUSH_ALLOW_PRIVATE=true` on both services. ntfy.sh needs nothing.

| ID | Do | Expected |
|---|---|---|
| P1 | `curl -s -X OPTIONS -u <user>:<app password> -D - -o /dev/null https://<host>/dav/calendars/<user>/` | The `DAV` header ends with `webdav-push` |
| P2 | `curl -s -X PROPFIND -H 'Depth: 1' -u <user>:<app password> https://<host>/dav/calendars/<user>/ --data '<d:propfind xmlns:d="DAV:" xmlns:P="https://bitfire.at/webdav-push"><d:prop><P:topic/><P:transports/><P:supported-triggers/></d:prop></d:propfind>'` | Every calendar and the home have a `P:topic` (22 characters) and the same `P:vapid-public-key type="p256ecdsa"`. Calendars list `content-update` depth 1 and `property-update` depth 0; the home only `content-update` depth 1 |
| P3 | On the phone: DAVx⁵ → Settings → Push, pick ntfy. Then sync the account (pull down in the account) | The app log has one `Push subscription registered` line per synced calendar and address book, with the ntfy host and an expiry three days ahead. Diagnostics shows no 403 for POST |
| P4 | Sync the account again | `Push subscription renewed` lines with the same paths; no new `registered` lines |
| P5 | Change an event from another client (Thunderbird or the web) in a calendar the phone syncs, and watch `docker logs -f nexusdav-worker` | Within about 5 seconds a `SendPushMessages` job and then one `DeliverPushMessage` job per subscription of that calendar, each `DONE`. With the push log level at `debug`: `Push message sent` with the ntfy host and `200 OK` (or `201`) |
| P6 | Change an event **on the phone** (only the phone subscribes to that calendar) | `SendPushMessages` runs, but no `DeliverPushMessage` for the phone: DAVx⁵ sent `Push-Dont-Notify` with its own registration |
| P7 | In the admin panel, revoke the app password the phone uses; then change an event from another client | `SendPushMessages` runs with no `DeliverPushMessage`. (Sign the phone in again with a new app password afterwards) |
| P8 | Switch **WebDAV-Push** off under Settings (or set `NEXUSDAV_PUSH=false` on both services and recreate them), and repeat P1 and P2 | No `webdav-push` in the `DAV` header and no `P:topic`. Calendars and contacts sync as before |
| P9 | Push on again (P3 done). Lock the phone. Add an event from Thunderbird in a calendar the phone syncs | Within about 10 seconds the event is on the phone without a manual sync (DAVx⁵ shows a short sync). The worker log has `Push message sent` |
| P10 | Add a contact from another client in an address book the phone syncs | The contact is on the phone within about 10 seconds |
| P11 | Add three events quickly from Thunderbird | One push, one sync on the phone: one `SendPushMessages` in the worker log for that calendar |
| P12 | Stop the worker, add an event from Thunderbird, wait a minute, start the worker | Nothing reaches the phone while the worker is down; within seconds of the start the push arrives and the phone syncs |

**Fail:** a push never reaches the phone (check the worker log: `Push message not delivered` shows the status; `its push service address is not allowed` means the private-network setting, above), the phone gets more than one push for one change, DAVx⁵ registers nothing (check that the image has push on, that a UnifiedPush distributor is set, and the app log for a 403 with `invalid-subscription`), a second sync adds new registrations instead of renewing, a change queues more than one `SendPushMessages` job in 5 seconds for one calendar, or any 5xx.

## Results

| Run log | |
|---|---|
| Date | November 9 |
| Build (Dashboard version) | 0.4.2-rc.1+cb62b6e |
| Tester | HummerSoft |

| Step | PostgreSQL | SQLite (Compose) | TrueNAS | Notes |
|---|---|---|---|---|
| W1 Worker starts, healthy | ✅ | ✅ | ✅ | |
| W2 Probe from the app | ✅ | ✅ | ✅ | Worker running: a3cab6b1c04d, last heartbeat 33 seconds ago. Probe job ran on a3cab6b1c04d in 0.8 s. |
| W3 Worker log | ✅ | ✅ | ✅ | |
| W4 Graceful stop | ✅ | ✅ | ✅ | stopped in 0 s |
| W5 Stale, then running | ✅ | ✅ | ✅ | Error response from daemon: container nexus-worker is not running. Then ix-nexusdav-p-nexusdav-worker-1 start |
| W6 Restart of the stack | ✅ | ✅ | ✅ | No issues |
| W7 Without the worker | ✅ | ✅ | ✅ | No issues |
| W8 Regression |  |  |  | Skipped for now |

| Step | PostgreSQL | SQLite (Compose) | TrueNAS | Notes |
|---|---|---|---|---|
| P1 `webdav-push` in OPTIONS | ✅ | ✅ | ✅ | dav: 1, 3, extended-mkcol, access-control, calendarserver-principal-property-search, webdav-push, calendar-access, calendar-proxy, addressbook, 2 |
| P2 Push properties | ✅ | ✅ | ✅ | <d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:P="https://bitfire.at/webdav-push" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:response><d:href>/dav/calendars/yurik/</d:href><d:propstat><d:prop><P:topic>_Wx9XsvMMkRvm9TjqY6GER</P:topic><P:transports><P:web-push><P:vapid-public-key type="p256ecdsa">BCOAdip5PpWGEy3BzmvCFo-Nv8ZHR-daCtHEFdb8v7bcjA-CE7N6omXKwECwT9akA8hHXhsQjQQeay2id5wL8ag</P:vapid-public-key></P:web-push></P:transports><P:supported-triggers><P:content-update><d:depth>1</d:depth></P:content-update></P:supported-triggers></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response><d:response><d:href>/dav/calendars/yurik/family/</d:href><d:propstat><d:prop><P:topic>qJhL9FbBaRwpaXP7u-r5rI</P:topic><P:transports><P:web-push><P:vapid-public-key type="p256ecdsa">BCOAdip5PpWGEy3BzmvCFo-Nv8ZHR-daCtHEFdb8v7bcjA-CE7N6omXKwECwT9akA8hHXhsQjQQeay2id5wL8ag</P:vapid-public-key></P:web-push></P:transports><P:supported-triggers><P:content-update><d:depth>1</d:depth></P:content-update><P:property-update><d:depth>0</d:depth></P:property-update></P:supported-triggers></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus> |
| P3 DAVx⁵ registers | ✅ | ✅ | ✅ | INFO Push subscription registered {"user":"yurik","path":"calendars/yurik/family","host":"fcm.googleapis.com","expires":"2026-11-12T22:57:34+00:00"} {"request_id":"3956b2e64d38d61d"} INFO Request {"method":"POST","path":"/dav/calendars/yurik/family/","status":201,"user":"yurik","ip":"10.10.20.110","duration_ms":131.4} {"request_id":"3956b2e64d38d61d"} |
| P4 Renewal | ✅ | ✅ | ✅ | INFO Push subscription renewed {"user":"yurik","path":"calendars/yurik/family","host":"fcm.googleapis.com","expires":"2026-11-12T22:59:45+00:00"} {"request_id":"19f28b8a64d7b655"} INFO Request {"method":"POST","path":"/dav/calendars/yurik/family/","status":204,"user":"yurik","ip":"10.10.20.110","duration_ms":64.5} {"request_id":"19f28b8a64d7b655"} |
| P5 A change is queued | ✅ | ✅ | ✅ | DEBUG Push message sent {"subscription":1,"host":"fcm.googleapis.com","result":"201 Created"} {"request_id":"20b45ae903659ee8"}  2026-11-09 23:01:57 App\Jobs\DeliverPushMessage .................... 1s DONE 2026-11-09 23:10:02 DEBUG Push messages queued {&#34;topic&#34;:&#34;8k8ZHnZnx9mrKrhZgFGcbA&#34;,&#34;subscriptions&#34;:0} {&#34;request_id&#34;:&#34;f804633ee77da042&#34;}   2026-11-09 23:10:02 App\Jobs\SendPushMessages ................. 32.92ms DONE |
| P6 Push-Dont-Notify | ✅ | ✅ | ✅ | done |
| P7 Revoked app password | ✅ | ✅ | ✅ | As expected |
| P8 Push off | ✅ | ✅ | ✅ | Pass |
| P9 Event reaches the phone | ✅ | ✅ | ✅ | Pass |
| P10 Contact reaches the phone | ✅ | ✅ | ✅ | |
| P11 One push for a burst | ✅ | ✅ | ✅ | Pass |
| P12 Worker down, then up | ✅ | ✅ | ✅ | Pass |

Checked before the hand pass, with the built image and the real Compose files on SQLite and PostgreSQL (November 9, 2026): W1, W2, W4 and W5 passed (healthy after about 8 seconds, probes in 0.8 s and 0.5 s, stop in 0.1 s with exit code 0, stale after five minutes and running again after a start).
