FROM dunglas/frankenphp:1-php8.4

# Shown on the admin Dashboard. CI passes ci+<commit>; a release passes <version>+<commit>.
ARG NEXUSDAV_VERSION=dev

# CVE-2026-109618 and CVE-2026-109619 are fixed from PHP 8.4.27. The build
# fails on an older base image, on every platform; rebuild with --pull.
ARG MIN_PHP_VERSION=8.4.27

WORKDIR /app

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl sqlite3 \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions gmp intl pcntl pdo_pgsql pdo_sqlite zip

COPY docker/uploads.ini ${PHP_INI_DIR}/conf.d/uploads.ini
RUN cp "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini"
COPY docker/php.ini ${PHP_INI_DIR}/conf.d/zz-nexusdav.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd --system --gid 568 nexusdav \
    && useradd --system --uid 568 --gid nexusdav --create-home --home-dir /home/nexusdav --shell /usr/sbin/nologin nexusdav

COPY . /app

RUN APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE= \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R nexusdav:nexusdav storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh \
    && php -r 'if (version_compare(PHP_VERSION, $argv[1], "<")) { fwrite(STDERR, "PHP ".PHP_VERSION." is older than ".$argv[1].". Rebuild with --pull.\n"); exit(1); }' "$MIN_PHP_VERSION" \
    && php -r '$required = ["ctype","curl","date","dom","fileinfo","filter","hash","iconv","intl","json","libxml","mbstring","openssl","pcntl","pcre","pdo","pdo_pgsql","pdo_sqlite","session","simplexml","tokenizer","xml","zip"]; foreach ($required as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: {$ext}\n"); exit(1); } } if (!defined("PASSWORD_ARGON2ID")) { fwrite(STDERR, "Argon2id is not available\n"); exit(1); }' \
    && command -v curl \
    && command -v sqlite3

EXPOSE 8080

# Logs go to the container output so `docker compose logs` shows them.
# Laravel's default channel writes storage/logs/laravel.log inside the container.
ENV LOG_CHANNEL=stderr \
    NEXUSDAV_VERSION=${NEXUSDAV_VERSION} \
    NEXUSDAV_FILES_PATH=/data/files

# The base image keeps Caddy's state in /data, which is our data volume and
# not writable by nexusdav. Caddy serves plain HTTP here; keep its files in the home.
ENV XDG_CONFIG_HOME=/home/nexusdav/.config \
    XDG_DATA_HOME=/home/nexusdav/.local/share

ENTRYPOINT ["/app/docker/entrypoint.sh"]
