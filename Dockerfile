# syntax=docker/dockerfile:1

# Production image: FrankenPHP (Caddy + PHP) serving the Symfony app in worker mode.
# Local development uses DDEV; this image is for servers. See README > Deployment.

FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

RUN set -eux; \
	install-php-extensions \
		apcu \
		intl \
		opcache \
		pdo_pgsql \
		zip \
	;

COPY --from=composer/composer:2-bin --link /composer /usr/local/bin/composer

# Run as an unprivileged user; allow FrankenPHP to bind ports 80/443 without root.
ARG USER=app
RUN set -eux; \
	useradd --create-home --shell /usr/sbin/nologin ${USER}; \
	setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp; \
	chown -R ${USER}:${USER} /config /data /app


FROM base AS prod

# Worker mode (the kernel stays in memory between requests) is enabled in the Caddyfile.
ENV APP_ENV=prod \
	APP_DEBUG=0

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link docker/php/app.prod.ini $PHP_INI_DIR/conf.d/zz-app.prod.ini
COPY --link docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --link --chmod=755 docker/frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

USER ${USER}

# 1 = also install the dev dependencies (Foundry, Faker), so a staging/test environment can load
# the demo data (see RESET_DEMO_DATA in the entrypoint). Production keeps the default 0.
# The app itself still runs with APP_ENV=prod either way.
ARG INSTALL_DEV_DEPENDENCIES=0

# Dependencies first: this layer stays cached until composer.json/composer.lock change.
COPY --chown=${USER}:${USER} composer.json composer.lock symfony.lock ./
RUN set -eux; \
	if [ "$INSTALL_DEV_DEPENDENCIES" = 1 ]; then noDev=''; else noDev='--no-dev'; fi; \
	composer install $noDev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction; \
	composer clear-cache

COPY --chown=${USER}:${USER} . ./

RUN set -eux; \
	if [ "$INSTALL_DEV_DEPENDENCIES" = 1 ]; then noDev=''; else noDev='--no-dev'; fi; \
	mkdir -p var/cache var/log config/jwt; \
	composer dump-autoload --classmap-authoritative $noDev; \
	composer dump-env prod; \
	composer run-script $noDev post-install-cmd

EXPOSE 80 443 443/udp

HEALTHCHECK --start-period=60s --interval=30s --timeout=5s --retries=3 \
	CMD curl -fsS http://localhost:2019/metrics >/dev/null || exit 1

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
