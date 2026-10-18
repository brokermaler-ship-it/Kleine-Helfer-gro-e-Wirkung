#!/usr/bin/env bash
# Baut dist/emc78-verwaltung-<VERSION>.zip (ohne Entwicklungsdateien, mit Produktions-vendor/).
set -euo pipefail

WURZEL="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$WURZEL"

VERSION="$(tr -d '[:space:]' < VERSION)"
ZIEL="dist/emc78-verwaltung-${VERSION}.zip"

rm -rf build
mkdir -p build dist
rm -f "$ZIEL"

# Nur versionierte Dateien aus HEAD; build/ bekommt bewusst kein .git.
git archive HEAD | tar -x -C build
rm -rf build/tests build/docs build/.github build/.superpowers build/phpunit.xml build/.gitignore build/.gitattributes
rm -f build/config.php build/bin/release.sh
find build/storage/dateien -mindepth 1 ! -name '.gitkeep' -delete 2>/dev/null || true

# Composer arbeitet nur im Verzeichnis build/ und fasst das Repository nicht an.
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --working-dir=build

(cd build && zip -qr "../${ZIEL}" . -x '.git/*')
echo "Fertig: ${ZIEL}"
