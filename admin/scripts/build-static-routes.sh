#!/usr/bin/env sh
set -eu

php "$(dirname "$0")/sync-static-routes.php"
