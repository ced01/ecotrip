#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"

fail() { printf 'delivery configuration: %s\n' "$*" >&2; exit 1; }

! grep -R "TASK-0001\|task0001" Dockerfile compose.yaml README.md >/dev/null 2>&1 \
    || fail "obsolete task-specific names remain"
grep -Eq '^USER +[^0]' Dockerfile || fail "the delivered image must select a non-root user"
grep -q '^HEALTHCHECK ' Dockerfile || fail "the delivered image must define a healthcheck"
grep -q 'APP_ENV: prod' compose.yaml || fail "the delivered service must force prod"
grep -q 'APP_DEBUG: "0"' compose.yaml || fail "the delivered service must disable debug"
[ -x tools/smoke-http.py ] || fail "the HTTP artifact smoke test is missing or not executable"

if command -v docker >/dev/null 2>&1; then
    APP_SECRET=*** POSTGRES_PASSWORD=*** \
        docker compose config --quiet
fi

printf 'delivery configuration: OK\n'
