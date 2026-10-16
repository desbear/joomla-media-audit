#!/bin/sh
# Builds the package and installs it into the local development site.
# Refuses to run unless the site's database is on this machine.
set -e

cd "$(dirname "$0")/.."

SITE_ROOT="${MEDIAAUDIT_SITE_ROOT:-$(cd ../.. && pwd)}"
PHP="${MEDIAAUDIT_PHP:-php}"

"$PHP" scripts/assert-local.php "$SITE_ROOT"

./build.sh
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' pkg_mediaaudit/pkg_mediaaudit.xml | head -1)
"$PHP" "$SITE_ROOT/cli/joomla.php" extension:install --path="$(pwd)/dist/pkg_mediaaudit-$VERSION.zip"
