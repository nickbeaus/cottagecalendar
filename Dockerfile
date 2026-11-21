# Cottage Calendar: PHP + Apache + SQLite in one container, plus a small background
# scheduler that emails the daily digest. Everything runs as www-data (Apache listens
# on 8080), so files in /data are always owned by the web server.

# --- PHPMailer, installed from app/composer.lock ---
# Pure PHP, so this stage runs natively on the build machine even for the arm64 image.
FROM --platform=$BUILDPLATFORM composer:2 AS vendor
WORKDIR /app
COPY app/composer.json app/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# --- The app ---
FROM php:8.3-apache-bookworm

# Links the published image to its GitHub repository (shown on the ghcr.io package page).
LABEL org.opencontainers.image.source="https://github.com/nickbeaus/cottagecalendar" \
      org.opencontainers.image.description="Family cottage booking calendar with iCal feed and daily email digest"

RUN set -eux; \
    a2enmod headers alias; \
    sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    # "zz-" so it loads after the image's security.conf, which would otherwise override it.
    printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/zz-hardening.conf; \
    a2enconf zz-hardening; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    for ext in pdo_sqlite openssl mbstring; do php -m | grep -qx "$ext"; done

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-cottagecalendar.ini"
COPY docker/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/cottagecalendar-entrypoint
COPY app/ /app/
COPY --from=vendor /app/vendor/ /app/vendor/

RUN mkdir -p /data && chown www-data:www-data /data && chmod 750 /data

WORKDIR /app
USER www-data
EXPOSE 8080

HEALTHCHECK --interval=60s --timeout=5s --start-period=20s \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/health.php") === "ok" ? 0 : 1);'

ENTRYPOINT ["cottagecalendar-entrypoint"]
CMD ["apache2-foreground"]
