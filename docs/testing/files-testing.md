# Files testing (M4)

Hand checks for Files, the WebDAV file storage at `/dav/files/<username>/` ([M4 in the plan](../plans/implementation-plan-v1x.md#m4--files-effort-high)). Each M4 PR adds its steps here: PR 28 (the file home and the basic methods), PR 29 (copy, move, locks), and PR 30 and PR 31 (the upload limit, large uploads, a full disk, and the panel). The release PR 32 runs all of them with GNOME Files, rclone and DAVx5, and the results go in the guide's [client compatibility](../guide.md#client-compatibility) table, as for the [client checklist](client-checklist.md).

## Setup

### Where the files are

| Server | Path |
|---|---|
| The image | `/data/files/<username>/` on the data volume |
| A local checkout | `storage/app/files/<username>/` |

A user's home is created on the first request to it. Uploads in progress are kept in `.uploads/` beside the homes and are not visible to clients.

### Switching Files on

Files is **off** until it is switched on. Switch it on in the panel under **Settings → Files** (PR 31), with no restart. Or set the variable, which then wins and locks the panel field:

- **Local checkout** (`php artisan serve`): set `NEXUSDAV_FILES=true` in `.env`. `artisan serve` restarts itself when `.env` changes, so the next request uses it. Do not start it with `--no-reload`: it then keeps the `.env` values it started with until you restart it. A cached config also hides the change (`php artisan config:clear`).
- **Docker:** a variable in `.env` does not reach the container by itself. Add it to the `environment:` list of the `nexusdav` service in `compose.yaml` and run `docker compose up -d`:

  ```yaml
      environment:
        - NEXUSDAV_FILES=true
  ```

  Remove the line again to manage Files in the panel.

### Accounts and URLs

Use the `checklist` user from the [client checklist](client-checklist.md#accounts) and a second user (for example `checklist2`) for the isolation step. Create an **app password** for each, on the user's page; the admin password does not work for DAV.

| Server | URL | GNOME Files |
|---|---|---|
| Behind the proxy (HTTPS) | `https://your.host/dav/files/checklist/` | `davs://checklist@your.host/dav/files/checklist/` |
| Local checkout (HTTP) | `http://127.0.0.1:8000/dav/files/checklist/` | `dav://checklist@127.0.0.1:8000/dav/files/checklist/` |

Keep the **Diagnostics** page open, as in the client checklist. Do not paste app passwords into issues or chat.

## Automated tests (PR 28) — ✅ done

`FilesTest` covers the switch and its default, the basic methods (ranges, `If-Match`, depth), uploads above 2 MB, another user's home, path escapes (`%2e%2e`, encoded slashes, NUL), symbolic links planted on disk, the name rules and Unicode NFC, failed uploads, and user deletion. It uses its own temporary directory and does not touch `storage/app/files`.

```bash
php artisan test --filter=FilesTest
# PostgreSQL, with a test database:
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=nexusdav_test DB_USERNAME=postgres DB_PASSWORD=secret php artisan test --filter=FilesTest
```

Result on SQLite:

```
   PASS  Tests\Feature\FilesTest
  ✓ files is off by default answers 403 when off and keeps the files
  ✓ a missing files key counts as off
  ✓ the home is created on first use and serves the basic methods
  ✓ another users home answers 404 and the root lists only your own
  ✓ path escapes and planted symbolic links are refused
  ✓ a home that is a symbolic link is not served
  ✓ names are checked and stored in nfc
  ✓ a failed upload leaves nothing and keeps the old file
  ✓ stale uploads are removed and fresh ones kept
  ✓ deleting a user deletes the file home with its locks and properties
  ✓ deleting a user without a file home works

  Tests:    11 passed (216 assertions)
```

## Steps (PR 28)

| ID | Do | Expected |
|---|---|---|
| F1 | **rclone:** create a folder, upload, list, download, compare, delete (commands below) | Every command succeeds; the downloaded file is identical |
| F2 | **GNOME Files:** connect to the `davs://` address (**Other Locations** or, in newer GNOME, **Network** → **Connect to Server**) with the app password. List the home, create a folder, copy a file in, copy it back out, delete the file and the folder, then unmount | Each action works; the copied-out file opens and matches |
| F3 | Upload a file of a few MB and one named `Café ünï 文件.txt` (either client) | Both list with their names and download intact |
| F4 | **Isolation** (curl, below): with `checklist2`'s app password request `checklist`'s home and a file in it; request a path with `%2e%2e` | 404 for the other home and its file, 403 for `%2e%2e`. `checklist2` lists only its own home under `/dav/files/` |
| F5 | **Files off:** switch Files off (see [Setup](#switching-files-on)), request the home, then switch it on again | Off: 403 with `Files is turned off on this server.`; the files stay on disk. On: the same file downloads |
| F6 | Delete the user `checklist2` in the panel, after it uploaded a file | `/data/files/checklist2/` is gone (`docker exec nexusdav ls /data/files`); `checklist`'s files are untouched |
| F7 | **Diagnostics** after the run | No 5xx rows. 4xx rows only from F4, F5, and 404s from a client checking that a name is free before it uploads |

**Fail:** any 500; another user's file readable or writable; a file appearing outside the user's home; a partial file left after a failed upload; files lost when Files is switched off.

### F1 — rclone

```bash
rclone config create nexusdav webdav \
  url https://your.host/dav/files/checklist/ \
  vendor other user checklist pass 'APP_PASSWORD'

echo "hello from rclone" > hello.txt
rclone mkdir nexusdav:docs
rclone copyto hello.txt nexusdav:docs/hello.txt
rclone lsf -R nexusdav:
rclone copyto nexusdav:docs/hello.txt downloaded.txt
cmp hello.txt downloaded.txt && echo identical
rclone deletefile nexusdav:docs/hello.txt
rclone rmdir nexusdav:docs
```

`rclone config create` stores the password obscured in `~/.config/rclone/rclone.conf`; remove the remote with `rclone config delete nexusdav` after the run. For the local checkout, use the HTTP URL from [Accounts and URLs](#accounts-and-urls).

### F2 — GNOME Files from a terminal

The same check without the Files window:

```bash
gio mount davs://checklist@your.host/dav/files/checklist/
gio list davs://checklist@your.host/dav/files/checklist/
gio mount -u davs://checklist@your.host/dav/files/checklist/
```

`gio mount` asks for the user and the app password. In the VS Code terminal (snap) `gio` fails with "volume doesn't implement mount"; run it from a normal terminal, or as `env -u GIO_MODULE_DIR gio …`.

### F4 — isolation with curl

```bash
BASE=https://your.host/dav/files
curl -s -o /dev/null -w "%{http_code}\n" -X PROPFIND -H "Depth: 0" -u checklist2:APP_PASSWORD_2 $BASE/checklist/
curl -s -o /dev/null -w "%{http_code}\n" -u checklist2:APP_PASSWORD_2 $BASE/checklist/docs/hello.txt
curl -s -o /dev/null -w "%{http_code}\n" --path-as-is -u checklist2:APP_PASSWORD_2 $BASE/checklist2/%2e%2e/checklist/
curl -s -X PROPFIND -H "Depth: 1" -u checklist2:APP_PASSWORD_2 $BASE/ | grep -o '/dav/files/[^/<]*/' | sort -u
```

Expected: `404`, `404`, `403`, and only `/dav/files/checklist2/` from the listing. Upload `docs/hello.txt` as `checklist` first, so the second request asks for a file that exists.

### F5 — Files off

```bash
curl -s -X PROPFIND -H "Depth: 0" -u checklist:APP_PASSWORD https://your.host/dav/files/checklist/
```

The answer is 403 and its body contains `Files is turned off on this server.` The files are still in `/data/files/checklist/`.

## Automated tests (PR 29) — ✅ done

`FilesCopyMoveLockTest` covers rename, move and copy of files and folder trees (depth 0 and infinity, name rules and NFC on the destination), `Overwrite`, the refusals (into itself, another user's home, `%2e%2e`, a link on disk, between files and calendars), dead properties after `COPY`, `MOVE` and `DELETE`, locks (exclusive, shared, depth infinity, with and without the token, after `MOVE` and `DELETE`, `LOCK` on a new name), the 404 for another user's locked file, and that a file write is not replayed after a busy database.

```bash
php artisan test --filter='FilesTest|FilesCopyMoveLockTest'
```

Locks do not move with a `MOVE` (RFC 4918, 9.9.4): the moved file is unlocked at its new name. Dead properties move with it.

## Steps (PR 29)

Start from a home with a folder `docs` holding `hello.txt` and a sub-folder `inner` with a file in it (F1 and F3 leave nothing behind; the F8 section shows how to create them).

| ID | Do | Expected |
|---|---|---|
| F8 | **rclone:** rename a file, move it into a folder, copy a file, move a folder (commands below) | Every command succeeds; the copy is identical; the server log shows `MOVE` and `COPY` (201), not a download and upload |
| F9 | **GNOME Files:** rename a file (F2), drag a file into a folder, copy and paste a file, rename and move a folder, copy and paste a folder | Each action works and the contents are intact; the folder copy has its sub-folder and files. A copy may end with "Operation not supported" from GNOME Files: choose **Skip** (the copy is done) |
| F10 | **Overwrite** (curl, below): copy a file onto an existing one with `Overwrite: F`, then `T` | `412` and the destination unchanged; then `204` and the destination replaced |
| F11 | **Refusals** (curl, below): copy into `checklist2`'s home, into a username that does not exist, a folder into itself, a file into a calendar | `409`, `409`, `409`, `403` with `Files can only be copied or moved inside the file home.` Nothing is written |
| F12 | **Locks** (curl, below): lock a file, write it without and with the token, lock it as `checklist2`, rename it with the token, then write it under the new name | `423` without the token, `204` with it, `404` for `checklist2`, `201` for the rename, `204` after it (the lock stays behind) |

### F8 — rclone

`nexusdav:` is the file home on the server, not a local folder. Put the starting files there first, from a folder that has a `hello.txt`:

```bash
rclone copyto hello.txt nexusdav:docs/hello.txt
rclone copyto hello.txt nexusdav:docs/inner/other.txt
```

Then:

```bash
rclone moveto nexusdav:docs/hello.txt nexusdav:docs/renamed.txt
rclone moveto nexusdav:docs/renamed.txt nexusdav:docs/inner/renamed.txt
rclone copyto nexusdav:docs/inner/renamed.txt nexusdav:copy.txt
rclone cat nexusdav:copy.txt
rclone move nexusdav:docs/inner nexusdav:archive/inner
rclone lsf -R nexusdav:
```

`archive/inner/` holds `renamed.txt` and the other file; `docs/inner/` is gone. On the server, `docker compose logs nexusdav | grep -E '"method":"(MOVE|COPY)"'` (or `storage/logs/laravel.log` locally) shows the `MOVE` and `COPY` requests.

### F10 and F11 — curl

```bash
BASE=https://your.host/dav/files
A="-u checklist:APP_PASSWORD"
curl -s -o /dev/null -w "%{http_code}\n" $A -X PUT --data 'first' $BASE/checklist/a.txt
curl -s -o /dev/null -w "%{http_code}\n" $A -X PUT --data 'second' $BASE/checklist/b.txt
curl -s -o /dev/null -w "%{http_code}\n" $A -X COPY -H "Destination: $BASE/checklist/b.txt" -H "Overwrite: F" $BASE/checklist/a.txt
curl -s $A $BASE/checklist/b.txt; echo
curl -s -o /dev/null -w "%{http_code}\n" $A -X COPY -H "Destination: $BASE/checklist/b.txt" -H "Overwrite: T" $BASE/checklist/a.txt
curl -s $A $BASE/checklist/b.txt; echo

curl -s -o /dev/null -w "%{http_code}\n" $A -X COPY -H "Destination: $BASE/checklist2/stolen.txt" $BASE/checklist/a.txt
curl -s -o /dev/null -w "%{http_code}\n" $A -X COPY -H "Destination: $BASE/nobody/stolen.txt" $BASE/checklist/a.txt
curl -s -o /dev/null -w "%{http_code}\n" $A -X COPY -H "Destination: $BASE/checklist/archive/inner/" $BASE/checklist/archive/
curl -s -w "\n%{http_code}\n" $A -X COPY -H "Destination: https://your.host/dav/calendars/checklist/default/a.ics" $BASE/checklist/a.txt
```

F10: `201`, `201`, `412`, `second`, `204`, `first`. F11: `409`, `409`, `409`, and `403` with the message. `checklist2`'s home has no `stolen.txt`.

### F12 — locks

```bash
LOCKINFO='<?xml version="1.0"?><d:lockinfo xmlns:d="DAV:"><d:lockscope><d:exclusive/></d:lockscope><d:locktype><d:write/></d:locktype><d:owner>checklist</d:owner></d:lockinfo>'
curl -s -D - -o /dev/null $A -X LOCK -H "Content-Type: application/xml" -H "Timeout: Second-600" --data "$LOCKINFO" $BASE/checklist/a.txt | grep -i '^lock-token'
TOKEN='opaquelocktoken:...'   # paste the value between < and > from the line above
curl -s -o /dev/null -w "%{http_code}\n" $A -X PUT --data 'no token' $BASE/checklist/a.txt
curl -s -o /dev/null -w "%{http_code}\n" $A -X PUT -H "If: (<$TOKEN>)" --data 'with token' $BASE/checklist/a.txt
curl -s -o /dev/null -w "%{http_code}\n" -u checklist2:APP_PASSWORD_2 -X LOCK -H "Content-Type: application/xml" --data "$LOCKINFO" $BASE/checklist/a.txt
curl -s -o /dev/null -w "%{http_code}\n" $A -X MOVE -H "If: (<$TOKEN>)" -H "Destination: $BASE/checklist/a-moved.txt" $BASE/checklist/a.txt
curl -s -o /dev/null -w "%{http_code}\n" $A -X PUT --data 'after the move' $BASE/checklist/a-moved.txt
```

Expected: a `Lock-Token` line with `opaquelocktoken:`, then `423`, `204`, `404`, `201`, `204`.

## Automated tests (PR 30 and PR 31) — ✅ done

`FilesUploadLimitTest` covers Maximum upload size (its range, 413 from `Content-Length` and from the byte count, an existing file kept), an upload shorter than its `Content-Length`, the limit for files only (a `COPY`, a calendar body), 507 on a simulated full disk, and a 24 MB upload without the body in memory. `FilesAdminTest` covers the Settings fields and their locks, the file home on the user page, the delete confirmation and the Services card.

```bash
php artisan test --filter='FilesTest|FilesCopyMoveLockTest|FilesUploadLimitTest|FilesAdminTest'
```

## Steps (PR 30 and PR 31)

Your local `.env` sets `NEXUSDAV_FILES=true`, which locks the Files switch. For F13, comment that line out (`php artisan serve` reloads by itself). Files is then off until you switch it on in the panel. A set variable always wins over the panel, also in Docker.

| ID | Do | Expected |
|---|---|---|
| F13 | **Settings → Files:** switch Files on and off, save each time; set Maximum upload size to `1` and save | The size field is shown only while Files is on. Off: `/dav/files/` answers 403; on: it works again. The saved size stays when Files is switched off. With a variable set, its field is read-only with "Set by NEXUSDAV_…" |
| F14 | **Over the limit, with `Content-Length`** (curl, below) | `413` with "The file is larger than the upload limit of 1 MB."; no file in the home and nothing in `.uploads/` |
| F15 | **Over the limit, without `Content-Length`** (chunked, below), also over an existing file | `413`; nothing new in the home; the existing file keeps its content |
| F16 | **A large upload:** set the size to `2048`, upload a 1 GB file with rclone or curl, download it and compare; copy it on the server | `201`, identical after download, the `COPY` is `201`. In Docker, `docker stats nexusdav` stays near its idle memory |
| F17 | **The user page:** open a user who has used Files, then one who has not | User Information shows "File home: Exists" and "File home: Not created yet" |
| F18 | **Delete a user** who has a file home (create a throwaway user, upload one file as that user, then delete the user) | The confirmation ends with "Their file home and every file in it will be deleted too." After deleting, the home is gone from `storage/app/files/` (`/data/files/` in Docker) |
| F19 | **Dashboard → Services** | Files is listed with On or Off; with `NEXUSDAV_FILES` set it has the lock icon |
| F20 | Switch the panel to **Russian** and open Settings, a user page, the delete dialog and the Dashboard | The new text is in Russian and nothing is cut off |
| F21 | **Diagnostics** after the run | No 5xx rows. 4xx rows only from F13 (403), F14 and F15 (413) |

Optional, Docker only: a **full disk** answers 507 and leaves nothing. Start a second container with a small `/data/files`, for example `docker run --rm -d --name nexusdav-full -p 127.0.0.1:18092:8080 --tmpfs /data/files:size=30m -e APP_URL=http://127.0.0.1:18092 -e ADMIN_EMAIL=admin@example.com -e ADMIN_PASSWORD=a-long-test-password -e DB_CONNECTION=sqlite -e DB_DATABASE=/data/database/nexusdav.sqlite -e NEXUSDAV_FILES=true ghcr.io/offsyanka99/nexusdav:<build>`, create a user and an app password in its panel, and upload a 50 MB file: `507` with "There is not enough free space on the server.", and `/data/files/.uploads/` empty.

### F14 and F15 — curl

Set the variables as in F10 (`BASE`, and `A` from `read -rs`), and Maximum upload size to `1` in Settings.

```bash
head -c 2097152 /dev/urandom > /tmp/two-mb.bin
echo small > /tmp/small.txt
curl -s -w "\n%{http_code}\n" $A -T /tmp/two-mb.bin $BASE/yurik/too-big.bin
curl -s -o /dev/null -w "%{http_code}\n" $A -T /tmp/small.txt $BASE/yurik/kept.txt
curl -s -o /dev/null -w "%{http_code}\n" $A -H "Transfer-Encoding: chunked" -T - $BASE/yurik/too-big.bin < /tmp/two-mb.bin
curl -s -o /dev/null -w "%{http_code}\n" $A -H "Transfer-Encoding: chunked" -T - $BASE/yurik/kept.txt < /tmp/two-mb.bin
curl -s $A $BASE/yurik/kept.txt
ls storage/app/files/yurik/ storage/app/files/.uploads/
```

Expected: the 413 message and `413`; `201`; `413`; `413`; `small`; no `too-big.bin`, and `.uploads/` empty.

### F16 — a large upload

Set Maximum upload size to `2048` first. Over HTTPS, the proxy must allow the body too (`client_max_body_size` in nginx, `max_size` in Caddy).

```bash
head -c 1073741824 /dev/urandom > /tmp/one-gig.bin
curl -s -o /dev/null -w "%{http_code} %{time_total}s\n" $A -T /tmp/one-gig.bin $BASE/yurik/one-gig.bin
curl -s $A $BASE/yurik/one-gig.bin | cmp - /tmp/one-gig.bin && echo identical
curl -s -o /dev/null -w "%{http_code}\n" $A -X COPY -H "Destination: $BASE/yurik/one-gig-copy.bin" $BASE/yurik/one-gig.bin
curl -s -o /dev/null -w "%{http_code}\n" $A -X DELETE $BASE/yurik/one-gig.bin
curl -s -o /dev/null -w "%{http_code}\n" $A -X DELETE $BASE/yurik/one-gig-copy.bin
rm /tmp/one-gig.bin
```

Expected: `201`, `identical`, `201`, `204`, `204`. `php artisan serve` (PHP's built-in server) keeps a request body in its own memory, so the memory check belongs to the Docker image, where FrankenPHP streams it.

## Results

The **Local** column is a run on a local checkout over plain HTTP. The pass over HTTPS behind the proxy goes in the **Proxy pass** run log at the M4 release.

| Run log | Local | Proxy pass |
|---|---|---|
| Date | November 8, 2026 | |
| Build (Dashboard version) | Local checkout (`php artisan serve`, SQLite): `7b7414d` for F1 to F7, `892d4ab` for F8 to F12, PR 30 and PR 31 before their commit for F13 to F21 | |
| Tester | HummerSoft | |
| rclone version | v1.60.1-DEV (Ubuntu package) | |
| GNOME Files (Nautilus) version | gvfs 1.60.0 through `gio` (Nautilus 50.2.2 installed) | |

| Step | Local (HTTP, SQLite) | Notes |
|---|---|---|
| F1 rclone | ✅ | Local: the download was identical |
| F2 GNOME Files | ✅ | Local: through `gio` (mount, mkdir, copy in and out, identical, remove, unmount), not the Files window |
| F3 Large and Unicode files | ✅ | Local: a 5 MB file and a 2.2 MB `Café ünï 文件.txt`, both identical; the name is stored in NFC |
| F4 Isolation | ✅ | Local: `yurik` and `olga`. 404, 404, 404 for the upload (no file written), 403, and only `olga`'s home listed |
| F5 Files off | ✅ | Local: 403 with the message, the files kept, and the 5 MB file downloaded again after switching on |
| F6 User deleted | ✅ | Local: `filesdel`'s home removed; the other homes untouched; `.uploads/` empty |
| F7 Diagnostics | ✅ | Local: no 5xx. 4xx: name checks before uploads (404), rclone creating an existing folder (405), gvfs's first challenge (401), F4 and F5 |
| F8 rclone rename, move, copy | ✅ | Local: `moveto` twice, `copyto` and `cat`, a folder `move`; the log shows `MOVE` and `COPY` with 201. `archive/inner/` already existed, so rclone moved the files one by one and left the empty `docs/inner/` |
| F9 GNOME Files rename, move, copy | ✅ | Local, the Files window: rename, move and the folder rename and move work. Copy and paste of a file or a folder into another folder shows "Operation not supported" although the copy is complete (the server answered `COPY` and `MKCOL` with 201). **Skip** keeps the copy; **Retry** copies again under a new name. GNOME's WebDAV backend cannot set the modification time on the copy (`gio set … time::modified` fails the same way and sends no request), so this is the client |
| F10 Overwrite | ✅ | Local: 201, 201, 412, `second`, 204, `first` |
| F11 Refusals | ✅ | Local: 409 into `olga`'s home, 409 into `nobody`, 409 into itself, 403 with the message into a calendar; `olga`'s home empty |
| F12 Locks | ✅ | Local: 423 without the token, 204 with it, 404 for `olga`, 201 for the rename, 204 after it. No 5xx during the PR 29 run |
| F13 Settings: Files switch and size | ✅ | Local: The size field is shown only while Files is on. The saved size stays when Files is switched off. |
| F14 Over the limit, Content-Length | ✅ | Local, limit 1 MB: a 2 MB upload got 413; no `too-big.bin`, `.uploads/` empty |
| F15 Over the limit, chunked | ✅ | Local: 413 for a new name and over `kept.txt`, which kept `small` |
| F16 A large upload | ✅ | Local, limit 2048 MB: a 1 GB upload 201 in 3.7 s, the download identical, `COPY` 201, both deleted (204). Memory checked on the Docker image instead (see the plan, PR 30) |
| F17 User page: file home | ✅ | Local: `yurik` "Exists", `truenas` "Not created yet" |
| F18 Delete a user with a file home | ✅ | Local: `filesdel` uploaded a file (201), its page showed "Exists", and deleting it removed its home; `olga`, `yurik` and `.uploads` stayed. The confirmation sentence was checked in a browser for `yurik` (cancelled) |
| F19 Services card | ✅ | Local |
| F20 Russian text | ✅ | Local: Settings, a user page, the delete dialog and the Dashboard |
| F21 Diagnostics | ✅ | Local: no 5xx and no 4xx rows |

Before the hand pass, PR 28 was also checked on the built image over plain HTTP on SQLite (November 8, 2026): rclone 1.76 and GNOME Files' gvfs through `gio` passed F1 to F3. PR 29 was checked on the local server with a temporary user: rclone 1.60 `moveto`, `copyto` and a folder `move`, and gvfs through `gio` (rename, move, copy, a folder move) passed the client part of F8 and F9.
