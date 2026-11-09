# WebDAV-Push and worker testing (M5)

Hand checks for M5 ([the plan](../plans/implementation-plan-v1x.md#m5--webdav-push-and-the-worker-effort-extra-high)). Each M5 PR adds its steps here: PR 34 (the queue worker) is below; PR 35 to PR 38 (push discovery, registration, delivery and the panel) follow, starting with a push that reaches DAVx5 on a phone through ntfy. The release PR 39 runs all of them on both databases.

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

## Results

| Run log | |
|---|---|
| Date | |
| Build (Dashboard version) | |
| Tester | |

| Step | PostgreSQL | SQLite (Compose) | TrueNAS | Notes |
|---|---|---|---|---|
| W1 Worker starts, healthy | ⬜ | ⬜ | ⬜ | |
| W2 Probe from the app | ⬜ | ⬜ | ⬜ | |
| W3 Worker log | ⬜ | ⬜ | ⬜ | |
| W4 Graceful stop | ⬜ | ⬜ | ⬜ | |
| W5 Stale, then running | ⬜ | ⬜ | ⬜ | |
| W6 Restart of the stack | ⬜ | ⬜ | ⬜ | |
| W7 Without the worker | ⬜ | ⬜ | ⬜ | |
| W8 Regression | ⬜ | ⬜ | ⬜ | |

Checked before the hand pass, with the built image and the real Compose files on SQLite and PostgreSQL (November 9, 2026): W1, W2, W4 and W5 passed (healthy after about 8 seconds, probes in 0.8 s and 0.5 s, stop in 0.1 s with exit code 0, stale after five minutes and running again after a start).
