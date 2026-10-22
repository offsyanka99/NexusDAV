#!/bin/sh
# Starts as root so PUID/PGID can match the host dataset, then drops to that user.
set -eu

PUID="${PUID:-568}"
PGID="${PGID:-568}"

if ! printf '%s' "$PUID" | grep -Eq '^[0-9]+$'; then
    echo "PUID must be a numeric user id." >&2
    exit 1
fi

if ! printf '%s' "$PGID" | grep -Eq '^[0-9]+$'; then
    echo "PGID must be a numeric group id." >&2
    exit 1
fi

groupmod -o -g "$PGID" nexusdav
usermod -o -u "$PUID" -g "$PGID" nexusdav

mkdir -p /data
chown nexusdav:nexusdav /data

if [ ! -s /data/app.key ]; then
    key=$(php -r 'echo "base64:".base64_encode(random_bytes(32));')
    (umask 077 && printf '%s' "$key" > /data/app.key)
fi

chown nexusdav:nexusdav /data/app.key
chmod 600 /data/app.key

APP_KEY=$(cat /data/app.key)
export APP_KEY

# An empty value would override config/logging.php's debug default.
if [ -z "${LOG_LEVEL:-}" ]; then
    unset LOG_LEVEL
fi

chown -R nexusdav:nexusdav /app/storage /app/bootstrap/cache /home/nexusdav

cd /app

setpriv --reuid=nexusdav --regid=nexusdav --init-groups -- php artisan nexusdav:prepare

exec setpriv --reuid=nexusdav --regid=nexusdav --init-groups --inh-caps=-all \
    frankenphp php-server --listen :8080 --root /app/public
