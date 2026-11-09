# WebDAV-Push design

Design for M5 of the [Version 1.x plan](../plans/implementation-plan-v1x.md#m5--webdav-push-and-the-worker-effort-extra-high), the result of the PR 33 spike. It fixes what PR 35 to PR 38 build: the library, the data stored, the registration URL, the topics and triggers, the expiry, and the merge window and retry policy.

The specification is the bitfire draft at the pinned commit `611abe2e8a0cdd7570cb936844b6588f7145b27d` (`draft-bitfire-webdav-push-00`, [bitfireAT/webdav-push](https://github.com/bitfireAT/webdav-push/tree/611abe2e8a0cdd7570cb936844b6588f7145b27d), text in `content.mkd`, grammar in `xml/webdav-push.rng`). The `main` branch is one commit ahead, and that commit changes only the README (it now calls the specification finished and lists DAVx⁵, Nextcloud, Xandikos and others as implementations). The protocol text is identical, so the pin stays.

## Spike results

**Prototype (not merged).** A script sent a VAPID-signed, `aes128gcm`-encrypted push message with `minishlink/web-push` 11.0.0 to a local ntfy server (`binwiederhier/ntfy`, the UnifiedPush distributor), fetched it back from ntfy and decrypted it the way a UnifiedPush client does (RFC 8291). The decrypted XML was identical to the sent one, in the NexusDAV image and on a host with `gmp`. A send took about 1.2 ms (median of 20) to the local ntfy, with or without `gmp`. ntfy answered 200 and kept the body as base64, unchanged. An unreachable endpoint is a failed send that is not "expired", so it is retried, not removed.

**DAVx⁵ behaviour** (read from [davx5-ose](https://github.com/bitfireAT/davx5-ose) `core/.../push/`, push since 4.4.10, May 2025):

| Question | Answer |
|---|---|
| What it subscribes to | Every CalDAV and CardDAV collection that is **synced and advertises push** (`transports` and `topic`). No homes, no principals, no file folders: DAVx⁵ has no WebDAV-mount sync to subscribe from |
| Triggers and depth | Always `content-update` with depth `1`. Never `property-update`. It does not read `supported-triggers` |
| VAPID | **Required.** Without a `vapid-public-key` in `transports` it does not register at all, and nothing shows in the server's log |
| Encryption | Sends `aes128gcm`, `subscription-public-key` (p256dh) and `auth-secret` whenever the distributor gives keys, which UnifiedPush and FCM do |
| Expiry | Asks for now + 3 days on every registration. A daily job registers again when the expiry is less than 2 days after its next run, so a subscription is renewed about every day |
| `Push-Dont-Notify` | Sent on its own `PUT` and `DELETE` with the quoted registration URL of that collection. Never `*` |
| Changing distributor | Unregisters the old push resource (`DELETE`), then registers the new one |
| Distributor on the phone | Needed: a UnifiedPush app such as ntfy, or Google's FCM through Play services. Without one, DAVx⁵ asks the user to pick one and keeps polling |

**Open: the phone test.** A push that reaches DAVx⁵ on a phone needs the server side of PR 35 (discovery and registration), so it is the first check of PR 35, not of this spike: DAVx⁵ with ntfy on Android, against a test server, subscribes to a calendar, and a change made on another device starts a sync on the phone within seconds. Its result is recorded here.

## Library

`minishlink/web-push` (`web-push-libs/web-push-php`), **MIT**, version 11 (11.0.0, July 2026), PHP 8.2 or newer. It does the message encryption (RFC 8291, `aes128gcm`) and VAPID (RFC 8292).

- **HTTP client:** version 11 sends through any PSR-18 client. Laravel already installs Guzzle 8, which is one, so no new dependency is needed. PR 37 passes its own Guzzle client with the SSRF guard, timeouts and no redirects (`new WebPush($auth, $options, $client)`).
- **Encoding:** the library's default is still the old `aesgcm`. Every subscription is created with `contentEncoding: 'aes128gcm'`, which is the only encoding the draft and DAVx⁵ use. A registration with another encoding is refused (`invalid-subscription`).
- **`gmp`:** not needed for speed, but without `gmp` or `bcmath` the library prints a PHP notice ("It is highly recommended to install the GMP or BCMath extension") every time it is set up. The image adds `gmp` in the PR that adds the library (PR 35).
- **Results:** `MessageSentReport::isSubscriptionExpired()` is true for 404 and 410 from the push service.

## What the server announces

When **WebDAV-Push** is on (Settings, `NEXUSDAV_PUSH`, default off):

- `OPTIONS` on a push-capable resource lists `webdav-push` in the `DAV` header.
- A `PROPFIND` returns three properties in the namespace `https://bitfire.at/webdav-push`:

```xml
<P:transports>
  <P:web-push><P:vapid-public-key type="p256ecdsa">BA1Hx…</P:vapid-public-key></P:web-push>
</P:transports>
<P:topic>O7M1nQ7cKkKTKsoS_j6Z3w</P:topic>
<P:supported-triggers>
  <P:content-update><D:depth>1</D:depth></P:content-update>
  <P:property-update><D:depth>0</D:depth></P:property-update>
</P:supported-triggers>
```

The `vapid-public-key` is always there, because DAVx⁵ does not subscribe without it.

### Push-capable resources, triggers and depths

| Resource | `content-update` | `property-update` |
|---|---|---|
| A calendar (owned or shared with the user) | depth 1 | depth 0 |
| An address book (owned or shared with the user) | depth 1 | depth 0 |
| The calendar home and the address book home | depth 1 (a collection added, removed or renamed) | — |
| A file folder, with **Include Files in push** on (`NEXUSDAV_PUSH_FILES`) | depth 1 | — |
| The file home, with **Include Files in push** on | depth `infinity` (any change in the user's files, Settled question 5) | — |

- A request for a depth the server does not support is lowered to the supported one, as the draft allows. A registration none of whose triggers is supported answers 403 with `no-trigger-supported` (see [The draft's two names](#the-drafts-two-names)).
- `property-update` covers the properties a client can change (`displayname`, `calendar-color`, `calendar-order`, the description). `getetag` and `sync-token` are not included: they change with every content update.
- Not push-capable in 1.x: principals, subscriptions to external calendars, and the scheduling inbox and outbox (there is no on-server scheduling before Version 2). A file in a folder is not a collection and has no topic.
- A tasks-only or notes-only change is a calendar content update like any other.

### Topics

A topic names a collection, not a URL. A shared calendar or address book has the **same topic** at the owner's path and at every sharee's path, so a change through any of them reaches every subscriber, as the draft wants ("one topic per resource").

- The topic is `base64url(HMAC-SHA256(push topic key, "<kind>:<id>"))`, cut to 22 characters (128 bits). The kind is `calendar`, `addressbook`, `calendar-home`, `addressbook-home` or `files`; the id is `calendars.id`, `addressbooks.id`, the principal URI, or the folder path in the file home.
- The **push topic key** is 32 random bytes, made when push is first switched on and stored encrypted in `settings`. It is not `APP_KEY`, so a new `APP_KEY` does not change the topics.
- A topic does not show the user name or the collection name. It is safe to send to the push service as the `Topic` header, which lets the service replace an undelivered message with the newer one.
- A renamed or moved file folder gets a new topic. Its clients find it on their next `PROPFIND`.

## Registration

A client `POST`s a `push-register` body (`Content-Type: application/xml`) to the collection. The existing `POST` handler that refuses client sharing requests runs before it; a `push-register` body does not match that check.

| Case | Answer |
|---|---|
| Registered | **201**, `Location: <registration URL>`, `Expires: <granted expiry>` |
| Same `push-resource` again for this collection | **204**: the registration is updated in place (keys, triggers, expiry); `Location` and `Expires` as above. Never a second row |
| Push off, Files push off for a folder, or a collection that is not push-capable | **403** `push-not-available` |
| More than **Maximum push subscriptions per user** (`NEXUSDAV_PUSH_MAX_SUBSCRIPTIONS`, default 100) | **403** `push-not-available`, with "Too many push subscriptions" in the message |
| A `web-push-subscription` without `push-resource`, a key or a secret, a `content-encoding` other than `aes128gcm`, a public key that is not an uncompressed P-256 point, an auth secret that is not 16 bytes, or a push resource that is not `https` (except on private networks with the setting on, PR 37) | **403** `invalid-subscription` |
| No supported trigger | **403** `no-trigger-supported` |
| Another user's collection | **404**, as for every other path in another user's home |

The **registration URL** is `https://<APP_URL host>/dav/push/<id>`, where `<id>` is 32 random URL-safe characters. It is absolute, built from `APP_URL`, and stored exactly as sent, because the client echoes it in `Push-Dont-Notify` and the server compares the strings.

- `DELETE /dav/push/<id>` by the subscription's owner removes it: **204**. An unknown or expired id, or another user's, answers **404**.
- `/dav/push/` is not listed and has no other methods (405).

### Expiry

- The server grants the requested expiry, but at most **7 days** from now. Without an `expires`, it grants **3 days**. A requested time in the past, or under one hour away, is raised to one hour.
- The draft asks servers to allow at least three days; DAVx⁵ asks for three and renews daily, so 7 days leaves room for a phone that is off for a few days.
- An expired subscription gets no message. Expired rows are removed by the worker's daily cleanup and when a message for their topic is sent.

## Data stored

Table `push_subscriptions` (PR 35):

| Column | Notes |
|---|---|
| `id` | Primary key |
| `registration` | The `<id>` of the registration URL, unique |
| `principaluri` | The subscriber, `principals/<username>`. Deleting the user deletes the rows |
| `path` | The DAV path the client registered on, for example `calendars/bob/team-ada` (shown on Diagnostics, used to remove the rows when a collection or a share goes) |
| `topic` | The topic of that path at registration time; the message fan-out looks rows up by topic |
| `push_resource` | The push service URL, text. Unique together with `path` |
| `public_key`, `auth_secret` | The client's p256dh key and auth secret, **encrypted** with Laravel's encrypter |
| `content_update_depth`, `property_update_depth` | Null when that trigger is not wanted; `0`, `1` or `infinity` |
| `properties` | The `D:prop` names a `property-update` asked for, JSON, or null for the default set |
| `expires_at` | The granted expiry |
| `created_at`, `updated_at` | |
| `last_success_at`, `last_failure_at`, `last_failure` | Delivery results, for the cleanup and for Diagnostics (status code and a short reason, no URL) |

Settings (PR 35, encrypted): the VAPID key pair, made when push is first switched on, and the push topic key. The VAPID subject (`sub`) is `APP_URL` (Settled question 4).

When a collection is deleted, a share is removed, or Sharing is switched off, the subscriptions on the paths that go away are deleted (shares) or get no messages (Sharing off), like the locks and properties of those paths today.

## Sending

A change is recorded where the sync token moves (calendar and address book backends), where a collection is created, renamed or deleted (homes), on a `PROPPATCH` of a collection, and on a file write (PR 36). It queues work for the worker; the web request never talks to a push service.

### Merge window

- A change starts a **5-second** window per topic. Further changes to that topic within the window join the same message, so an import of a thousand contacts sends one message, not a thousand.
- The message carries the **latest** sync token in `content-update`, and `property-update` when any change in the window was a property change. A property change is never dropped by merging.
- `Push-Dont-Notify` is recorded with each change. A subscription is skipped only if **every** change in the window named it (or used `*`). Values that are not this user's registration URLs are ignored.
- Message: `POST` to the push resource, `Content-Type: application/xml; charset="UTF-8"`, `Content-Encoding: aes128gcm`, `TTL: 86400`, `Urgency: normal`, `Topic: <topic>`, VAPID `Authorization`. The body:

```xml
<P:push-message xmlns:D="DAV:" xmlns:P="https://bitfire.at/webdav-push">
  <P:topic>O7M1nQ7cKkKTKsoS_j6Z3w</P:topic>
  <P:content-update><D:sync-token>http://sabre.io/ns/sync/4-17</D:sync-token></P:content-update>
</P:push-message>
```

### Retry and removal

| Push service answer | What the server does |
|---|---|
| 2xx | `last_success_at` is set |
| 404 or 410 (expired) | The subscription is removed when it has had no success for **24 hours** (or never had one and is older than 24 hours); otherwise `last_failure_at` is set. The draft asks not to remove on the first failure |
| 429, 5xx, a timeout or a connection error | Retried after 30 seconds, 2 minutes and 10 minutes. After that the message is dropped (the next change sends a new one) and `last_failure_at` is set. A subscription with no success for **3 days** is removed |
| Other 4xx (400, 401, 403, 413) | Not retried. Removed after **3 days** with no success |
| A push resource that the SSRF guard refuses (PR 37) | Not sent. Removed at once and logged |

One slow push service never holds up the others: each subscription is its own job, with a 10-second timeout.

### Rate limiting

The merge window is the rate limit per topic. A subscription gets at most one message per 5 seconds, which the draft's "rate limiting" section allows because content updates may be merged.

## The draft's two names

In the pinned draft the precondition for "no trigger is supported" is `no-trigger-supported` in the grammar (`xml/webdav-push.rng`) and `no-supported-trigger` in the text (`content.mkd`). NexusDAV uses the grammar's name, `no-trigger-supported`. DAVx⁵ never sends a trigger the server lacks, so it never sees either.

## Out of scope for 1.x

- Changing the VAPID key. The draft's key-rotation message is not sent; a new key would need every client to register again.
- Push for principals, scheduling and calendar subscriptions.
- Per-user push settings and per-collection opt-out. A client chooses what to subscribe to.
