#!/usr/bin/env bash
set -euo pipefail

APP_DIR=${APP_DIR:-/app}
cd "$APP_DIR"

if [ ! -f composer.json ]; then
  echo "composer.json not found; creating Symfony skeleton..."
  composer create-project symfony/skeleton . --no-interaction --no-progress
fi

if [ ! -d vendor ]; then
  echo "vendor/ missing; installing dependencies..."
  composer install --no-interaction --no-progress
fi

echo "Starting PHP built-in server on 0.0.0.0:8000 (docroot: public/)"
exec php -S 0.0.0.0:8000 -t public
