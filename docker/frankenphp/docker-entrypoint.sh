#!/bin/sh
set -e

# Prepare the app before the web server starts; one-off commands (e.g. "bin/console ...") skip this.
if [ "$1" = 'frankenphp' ]; then
	# RESET_DEMO_DATA=1 (staging/test only) replaces all data with the demo fixtures on every start.
	# It needs the dev dependencies; check that before touching the database, so enabling it by
	# mistake on a production image fails without changing anything.
	if [ "$RESET_DEMO_DATA" = 1 ] && [ ! -d vendor/zenstruck/foundry ]; then
		echo 'RESET_DEMO_DATA=1 needs an image built with INSTALL_DEV_DEPENDENCIES=1. Nothing was changed.' >&2
		exit 1
	fi

	# The OAuth2 signing key pair lives in a volume: generated on the first start, reused afterwards.
	bin/console app:oauth:generate-keys --skip-if-exists --no-interaction

	echo 'Waiting for the database...'
	attempts=60
	until bin/console dbal:run-sql -q 'SELECT 1' >/dev/null 2>&1; do
		attempts=$((attempts - 1))
		if [ "$attempts" -le 0 ]; then
			echo 'The database is not reachable:' >&2
			bin/console dbal:run-sql -q 'SELECT 1' >&2
			exit 1
		fi
		sleep 1
	done

	if [ "$RESET_DEMO_DATA" = 1 ]; then
		# Drop the tables instead of the whole database: the previous container may still be
		# connected during a deployment, and PostgreSQL refuses to drop a database in use.
		echo 'RESET_DEMO_DATA=1: replacing all data with the demo fixtures...'
		bin/console doctrine:schema:drop --full-database --force --no-interaction
	fi

	bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration

	if [ "$RESET_DEMO_DATA" = 1 ]; then
		# Foundry is only enabled in the dev environment; the schema was reset above, so append.
		APP_ENV=dev APP_DEBUG=0 FOUNDRY_FAKER_SEED=1234 \
			bin/console foundry:load-fixtures --append --no-interaction
	fi
fi

exec docker-php-entrypoint "$@"
