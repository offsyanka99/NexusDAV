#!/bin/sh
# Smoke test for a built NexusDAV image. Used by CI and runnable locally:
#   docker build --pull -t nexusdav:local . && docker/smoke-test.sh nexusdav:local
set -eu

IMAGE=${1:?usage: docker/smoke-test.sh <image>}
PORT=${SMOKE_PORT:-18080}
NAME=nexusdav-smoke-$$
BASE="http://127.0.0.1:$PORT"
MIN_PHP=8.4.27

ok() { printf 'ok: %s\n' "$1"; }
fail() {
    printf 'FAIL: %s\n' "$1" >&2
    docker logs "$NAME" >&2 2>&1 || true
    exit 1
}

body=$(mktemp)
trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true; rm -f "$body"' EXIT

# CVE-2026-109618 and CVE-2026-109619 are fixed from PHP 8.4.27.
php_version=$(docker run --rm --entrypoint php "$IMAGE" -r 'echo PHP_VERSION;')
docker run --rm --entrypoint php "$IMAGE" -r "exit(version_compare(PHP_VERSION, '$MIN_PHP', '>=') ? 0 : 1);" \
    || fail "PHP $php_version is older than $MIN_PHP"
ok "PHP $php_version"

settings=$(docker run --rm --entrypoint php "$IMAGE" -r 'echo ini_get("expose_php") ? "on" : "off", " ", extension_loaded("Zend OPcache") && ini_get("opcache.enable") ? "on" : "off", " ", ini_get("opcache.validate_timestamps") ? "on" : "off";')
[ "$settings" = "off on off" ] || fail "expected expose_php off, OPcache on, validate_timestamps off; got: $settings"
ok "expose_php off, OPcache on, validate_timestamps off"

docker run -d --name "$NAME" -p "127.0.0.1:$PORT:8080" \
    -e APP_URL="$BASE" \
    -e ADMIN_EMAIL=smoke@example.com \
    -e ADMIN_PASSWORD=smoke-test-password \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=/data/database/nexusdav.sqlite \
    "$IMAGE" >/dev/null

i=0
until curl -fs "$BASE/health" >/dev/null 2>&1; do
    i=$((i + 1))
    [ "$i" -lt 90 ] || fail "/health did not answer within 90 seconds"
    sleep 1
done

[ "$(curl -s "$BASE/health")" = '{"status":"ok"}' ] || fail "/health body"
ok "/health is ok"

if docker logs "$NAME" 2>&1 | grep -qi 'permission denied'; then fail "a permission error was logged at start"; fi
ok "no permission errors at start"

location=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/")
[ "$location" = "302 $BASE/admin" ] || fail "/ should redirect to /admin, got: $location"
ok "/ redirects to /admin"

headers=$(curl -s -D - -o /dev/null "$BASE/admin/login" | tr -d '\r')
printf '%s\n' "$headers" | grep -q '^HTTP/[0-9.]* 200' || fail "/admin/login status"
printf '%s\n' "$headers" | grep -qi '^x-frame-options: DENY' || fail "/admin/login has no X-Frame-Options"
if printf '%s\n' "$headers" | grep -qi '^x-powered-by:'; then fail "/admin/login sends X-Powered-By"; fi
ok "/admin/login is served with frame protection and no X-Powered-By"

version=$(docker exec "$NAME" php -r 'echo getenv("NEXUSDAV_VERSION");')
[ -n "$version" ] || fail "NEXUSDAV_VERSION is not set in the image"
ok "version $version"

docker exec "$NAME" test -f /app/bootstrap/cache/config.php || fail "config is not cached"
docker exec "$NAME" test -f /app/bootstrap/cache/routes-v7.php || fail "routes are not cached"
ok "config and routes are cached"

token=$(docker exec -u nexusdav "$NAME" php artisan tinker \
    --execute="echo app(App\Services\AppPasswordService::class)->issue(App\Models\User::query()->where('username', 'smoke')->firstOrFail(), 'smoke')['token'];" \
    | tail -n 1 | tr -d '\r\n')
[ -n "$token" ] || fail "could not issue an app password"

headers=$(curl -s -D - -o /dev/null -u "smoke:$token" -X PROPFIND -H 'Depth: 0' "$BASE/dav/" | tr -d '\r')
printf '%s\n' "$headers" | grep -q '^HTTP/[0-9.]* 207' || fail "PROPFIND with an app password did not return 207"
ok "PROPFIND with an app password returns 207"

request_id=$(printf '%s\n' "$headers" | sed -n 's/^[Xx]-[Rr]equest-[Ii]d: //p')
[ -n "$request_id" ] || fail "the answer has no X-Request-Id"
sleep 1
line=$(docker logs "$NAME" 2>&1 | grep -F "\"request_id\":\"$request_id\"" | grep -F 'INFO: Request {') \
    || fail "no request log line for $request_id"
printf '%s\n' "$line" | grep -qF '"method":"PROPFIND","path":"/dav/","status":207,"user":"smoke"' \
    || fail "unexpected request log line: $line"
if docker logs "$NAME" 2>&1 | grep -qF "${token#*.}"; then fail "the app password secret is in the log"; fi
ok "the request is logged with its ID, method, path, status and user, and no secret"

# dav METHOD PATH [CONTENT_TYPE BODY]: prints the status and keeps the answer in $body.
dav() {
    if [ $# -ge 4 ]; then
        printf '%b' "$4" | curl -s -o "$body" -w '%{http_code}' -u "smoke:$token" -X "$1" \
            -H "Content-Type: $3" --data-binary @- "$BASE$2"
    else
        curl -s -o "$body" -w '%{http_code}' -u "smoke:$token" -X "$1" "$BASE$2"
    fi
}

calendar=/dav/calendars/smoke/default
event='BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NexusDAV//smoke//EN\r\nBEGIN:VEVENT\r\nUID:smoke-event\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nDTEND:20260101T110000Z\r\nSUMMARY:Smoke event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
task='BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NexusDAV//smoke//EN\r\nBEGIN:VTODO\r\nUID:smoke-task\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:Smoke task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n'
card='BEGIN:VCARD\r\nVERSION:3.0\r\nUID:smoke-card\r\nFN:Smoke Test\r\nN:Test;Smoke;;;\r\nEND:VCARD\r\n'
sync='<?xml version="1.0" encoding="utf-8"?><d:sync-collection xmlns:d="DAV:"><d:sync-token/><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>'

[ "$(dav PUT "$calendar/smoke-event.ics" text/calendar "$event")" = "201" ] || fail "PUT event"
[ "$(dav PUT "$calendar/smoke-task.ics" text/calendar "$task")" = "201" ] || fail "PUT task"
[ "$(dav GET "$calendar/smoke-event.ics")" = "200" ] && grep -q 'UID:smoke-event' "$body" || fail "GET event"
[ "$(dav REPORT "$calendar/" application/xml "$sync")" = "207" ] || fail "sync-collection REPORT"
grep -q 'smoke-event.ics' "$body" && grep -q 'smoke-task.ics' "$body" && grep -q 'sync-token' "$body" \
    || fail "sync-collection did not list the event and the task"
[ "$(dav PUT /dav/addressbooks/smoke/default/smoke-card.vcf 'text/vcard; charset=utf-8' "$card")" = "201" ] || fail "PUT contact"
[ "$(dav GET /dav/addressbooks/smoke/default/smoke-card.vcf)" = "200" ] && grep -q 'FN:Smoke Test' "$body" || fail "GET contact"
[ "$(dav MKCALENDAR /dav/calendars/smoke/from-client/)" = "201" ] || fail "MKCALENDAR"
[ "$(dav DELETE /dav/calendars/smoke/from-client/)" = "204" ] || fail "DELETE calendar"
[ "$(dav DELETE "$calendar/smoke-event.ics")" = "204" ] || fail "DELETE event"
ok "a client can write, read and sync events, tasks and contacts, and create and delete a calendar"

location=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X PROPFIND "$BASE/.well-known/caldav")
[ "$location" = "301 $BASE/dav/" ] || fail "/.well-known/caldav should redirect to /dav/, got: $location"
ok "/.well-known/caldav redirects to /dav/"

status=$(curl -s -o /dev/null -w '%{http_code}' -u "smoke:not-a-token" -X PROPFIND -H 'Depth: 0' "$BASE/dav/")
[ "$status" = "401" ] || fail "bad DAV login returned $status"
sleep 1
docker logs "$NAME" 2>&1 | grep -q 'Authentication failed' || fail "the failed login is not in docker logs"
ok "a failed login is in docker logs"

# A command run with docker exec logs to its own stderr, not to docker logs.
reset_err=$(mktemp)
reset=$(docker exec -u nexusdav "$NAME" php artisan nexusdav:reset-admin-password --generate --no-interaction 2>"$reset_err") \
    || fail "nexusdav:reset-admin-password failed"
new_password=$(printf '%s\n' "$reset" | tail -n 1)
[ "${#new_password}" -eq 24 ] || fail "the generated admin password was not printed"
matched=$(docker exec -u nexusdav "$NAME" php artisan tinker \
    --execute="echo Illuminate\Support\Facades\Hash::check('$new_password', (string) App\Models\User::query()->where('username', 'smoke')->value('password')) ? 'match' : 'mismatch';" | tail -n 1)
[ "$matched" = "match" ] || fail "the reset password does not match"
grep -q 'Admin password reset from the command line' "$reset_err" || fail "the reset was not logged"
if grep -qF "$new_password" "$reset_err"; then fail "the new password was logged"; fi
rm -f "$reset_err"
ok "nexusdav:reset-admin-password works as nexusdav and is logged without the password"

if docker exec "$NAME" sh -c 'ls /app/storage/logs/*.log >/dev/null 2>&1'; then
    fail "a log file was written under storage/logs"
fi
ok "nothing is written under storage/logs"

echo "Smoke test passed for $IMAGE"
