#!/bin/sh
# Builds dist/pkg_mediaaudit.zip, the installable package of Media Audit.
set -e

cd "$(dirname "$0")"

EXTENSIONS="com_mediaaudit plg_console_mediaaudit plg_task_mediaaudit plg_system_mediaaudit plg_webservices_mediaaudit plg_mediaaudit_falang plg_mediaaudit_flexicontent plg_mediaaudit_easyblog plg_mcp_mediaaudit"

rm -rf dist
mkdir -p dist/pkg/packages

for extension in $EXTENSIONS; do
	(cd "$extension" && zip -q -r -X "../dist/pkg/packages/$extension.zip" . -x '.*' -x '*/.*')
done

cp -R pkg_mediaaudit/pkg_mediaaudit.xml pkg_mediaaudit/script.php pkg_mediaaudit/language dist/pkg/
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' pkg_mediaaudit/pkg_mediaaudit.xml | head -1)

(cd dist/pkg && zip -q -r -X "../pkg_mediaaudit-$VERSION.zip" . -x '.*' -x '*/.*')
rm -rf dist/pkg

ls -l "dist/pkg_mediaaudit-$VERSION.zip"
