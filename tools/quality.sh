#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$root"
[ -r .env.local ] || { echo '.env.local missing: follow README bootstrap first.' >&2; exit 1; }
compose="docker compose --env-file .env.local --profile tools"

$compose run --rm ui-test
$compose run --rm test composer check
$compose run --rm test composer validate --strict
$compose run --rm test composer audit
$compose run --rm test php bin/console importmap:audit

echo 'Quality gates: OK'
