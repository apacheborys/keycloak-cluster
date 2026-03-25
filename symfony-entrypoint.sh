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

KEYCLOAK_LOCALHOST_PROXY=${KEYCLOAK_LOCALHOST_PROXY:-1}
KEYCLOAK_LOCALHOST_PROXY_PORT=${KEYCLOAK_LOCALHOST_PROXY_PORT:-8080}
KEYCLOAK_LOCALHOST_PROXY_TARGET=${KEYCLOAK_LOCALHOST_PROXY_TARGET:-host.docker.internal:8080}

if [ "$KEYCLOAK_LOCALHOST_PROXY" = "1" ]; then
  if ! nc -z 127.0.0.1 "$KEYCLOAK_LOCALHOST_PROXY_PORT" >/dev/null 2>&1; then
    echo "Starting localhost proxy on 127.0.0.1:${KEYCLOAK_LOCALHOST_PROXY_PORT} -> ${KEYCLOAK_LOCALHOST_PROXY_TARGET}"
    socat \
      "TCP-LISTEN:${KEYCLOAK_LOCALHOST_PROXY_PORT},fork,reuseaddr,bind=127.0.0.1" \
      "TCP:${KEYCLOAK_LOCALHOST_PROXY_TARGET}" \
      >/tmp/keycloak-localhost-proxy.log 2>&1 &
  fi
fi

echo "Starting PHP built-in server on 0.0.0.0:8000 (docroot: public/)"
exec php -S 0.0.0.0:8000 -t public
