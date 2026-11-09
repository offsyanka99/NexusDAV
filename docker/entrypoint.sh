#!/bin/sh
# Starts as root so PUID/PGID can match the host dataset, then drops to that user.
#
# Two roles, from the first argument or NEXUSDAV_ROLE:
#   app     (default) migrations, the first admin, then the web server.
#   worker  the queue worker: no migrations, no first admin, no web server.
#           It needs the app container's /data (for APP_KEY) and database.
set -eu

ROLE="${1:-${NEXUSDAV_ROLE:-app}}"

case "$ROLE" in
    app|worker) ;;
    *)
        echo "Unknown role \"$ROLE\". Use app or worker." >&2
        exit 1
        ;;
esac

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

as_nexusdav() {
    setpriv --reuid=nexusdav --regid=nexusdav --init-groups -- "$@"
}

mkdir -p /data

if [ "$ROLE" = "worker" ]; then
    # The app container owns /data and creates the key; the worker starts
    # after it is healthy (compose.yaml) and only reads the key.
    if [ ! -s /data/app.key ]; then
        echo "The worker needs /data/app.key from the app container. Start the app first, with the same /data volume." >&2
        exit 1
    fi

    APP_KEY=$(cat /data/app.key)
    export APP_KEY

    if [ -z "${LOG_LEVEL:-}" ]; then
        unset LOG_LEVEL
    fi

    chown -R nexusdav:nexusdav /app/storage /app/bootstrap/cache /home/nexusdav

    cd /app

    # Cached once per start, like the app container.
    as_nexusdav php artisan optimize

    # queue:work stops by itself after an hour (or above 128 MB) to release
    # memory, and this loop starts it again without restarting the
    # container. On SIGTERM the running job finishes, then the worker exits.
    child=""
    stopping=""
    trap 'stopping=1; [ -n "$child" ] && kill -TERM "$child" 2>/dev/null' TERM INT

    while [ -z "$stopping" ]; do
        setpriv --reuid=nexusdav --regid=nexusdav --init-groups --inh-caps=-all -- php artisan queue:work \
            --sleep=3 --tries=3 --backoff=10 --timeout=120 \
            --max-time=3600 --memory=128 --no-interaction &
        child=$!
        status=0
        wait "$child" || status=$?

        # A signal interrupts the first wait; wait again for the worker to finish its job.
        if [ -n "$stopping" ]; then
            wait "$child" 2>/dev/null || true
            break
        fi

        child=""

        # 0 after --max-time, 12 above --memory: both are a planned restart.
        if [ "$status" -ne 0 ] && [ "$status" -ne 12 ]; then
            echo "The queue worker stopped with status $status; starting it again in 10 seconds." >&2
            sleep 10
        fi
    done

    exit 0
fi

chown nexusdav:nexusdav /data

# A changed PUID or PGID would leave the SQLite files owned by the old user.
if [ -d /data/database ]; then
    chown -R nexusdav:nexusdav /data/database
fi

# The same for file homes, which can be large: only when the owner changed.
# -h changes a symbolic link itself, never what it points to.
if [ -d /data/files ] && [ "$(stat -c '%u:%g' /data/files)" != "$PUID:$PGID" ]; then
    chown -R -h nexusdav:nexusdav /data/files
fi

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

as_nexusdav php artisan nexusdav:prepare

# Cache config, routes, events, views and Filament components (optimize runs
# filament:optimize) once per start, so each request does not rebuild them.
# Environment changes need a restart.
as_nexusdav php artisan optimize

exec setpriv --reuid=nexusdav --regid=nexusdav --init-groups --inh-caps=-all \
    frankenphp php-server --listen :8080 --root /app/public
