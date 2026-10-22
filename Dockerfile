FROM dunglas/frankenphp:1-php8.4

WORKDIR /app

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl sqlite3 \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions intl pdo_pgsql pdo_sqlite zip

COPY docker/uploads.ini ${PHP_INI_DIR}/conf.d/uploads.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd --system --gid 568 nexusdav \
    && useradd --system --uid 568 --gid nexusdav --create-home --home-dir /home/nexusdav --shell /usr/sbin/nologin nexusdav

COPY . /app

RUN APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE= \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R nexusdav:nexusdav storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh \
    && php -r '$required = ["ctype","curl","date","dom","fileinfo","filter","hash","iconv","intl","json","libxml","mbstring","openssl","pcre","pdo","pdo_pgsql","pdo_sqlite","session","simplexml","tokenizer","xml","zip"]; foreach ($required as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: {$ext}\n"); exit(1); } } if (!defined("PASSWORD_ARGON2ID")) { fwrite(STDERR, "Argon2id is not available\n"); exit(1); }' \
    && command -v curl \
    && command -v sqlite3

EXPOSE 8080

ENTRYPOINT ["/app/docker/entrypoint.sh"]
