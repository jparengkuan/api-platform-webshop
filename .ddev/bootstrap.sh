#!/usr/bin/env bash
# Runs inside the web container on every `ddev start` (see hooks in config.yaml).
# Idempotent: only creates what is missing, so a fresh clone works after one `ddev start`.
set -euo pipefail

# 1. JWT passphrase: kept out of the committed .env, in git-ignored per-env files.
#    (.env.local is not loaded in the test env, and DDEV manages .env.local itself.)
passphrase=$(grep -hs '^JWT_PASSPHRASE=' .env.dev.local .env.test.local | head -1 | cut -d= -f2- || true)
if [ -z "$passphrase" ]; then
    passphrase=$(openssl rand -hex 32)
    rm -f config/jwt/private.pem config/jwt/public.pem # old keys can't be opened with a new passphrase
    echo "Generated a new JWT passphrase"
fi
for f in .env.dev.local .env.test.local; do
    grep -qs '^JWT_PASSPHRASE=' "$f" || echo "JWT_PASSPHRASE=$passphrase" >> "$f"
done

# 2. JWT keypair (git-ignored)
bin/console lexik:jwt:generate-keypair --skip-if-exists

# 3. Dev database schema
bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# 4. Test database (db_test) for bin/phpunit
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration
