#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
exec php scripts/package-update.php "$@"
