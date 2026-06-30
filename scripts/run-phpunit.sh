#!/usr/bin/env bash
# Helper to run phpunit inside the vyg-wp container without typing the long
# docker exec prefix each time. Pass any extra args to phpunit.
#
#   scripts/run-phpunit.sh                                    # full suite
#   scripts/run-phpunit.sh --testsuite=unit                   # unit only
#   scripts/run-phpunit.sh --filter VideoRendererViewCountTest
#   scripts/run-phpunit.sh tests/unit/RelativeTimeTest.php
set -euo pipefail
cd "$(dirname "$0")/.."
docker exec vyg-wp bash -lc "cd /var/www/html/wp-content/plugins/vector-youtube-gallery && vendor/bin/phpunit $*"
