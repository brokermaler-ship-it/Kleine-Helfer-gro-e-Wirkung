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
rm -rf build/tests build/docs build/.github build/.superpowers build/werkzeuge build/CLAUDE.md build/phpunit.xml build/.gitignore build/.gitattributes
# Die Beispielfotos der Testumgebung gehören ins Paket (Tests selbst nicht).
mkdir -p build/tests/fixtures
git archive HEAD tests/fixtures/demo | tar -x -C build
rm -f build/config.php build/bin/release.sh
find build/storage/dateien build/storage/sitzungen -mindepth 1 ! -name '.gitkeep' -delete 2>/dev/null || true
# Leerer Sitzungsordner je Instanz (eigener session.save_path, durch storage/.htaccess gesperrt).
mkdir -p build/storage/sitzungen
[ -f build/storage/sitzungen/.gitkeep ] || : > build/storage/sitzungen/.gitkeep

# Composer arbeitet nur im Verzeichnis build/ und fasst das Repository nicht an.
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --working-dir=build
# Ohne GitHub-Zugang lädt Composer Pakete per git clone (source) statt als ZIP. Dann jedes geklonte Paket durch
# „git archive“ seines installierten Stands ersetzen: wie beim ZIP-Download ohne .git und ohne die per export-ignore
# ausgeschlossenen Teile (Tests, Beispiele, Doku des Pakets). Das Repository der App wird dabei nicht angefasst.
find build/vendor -name .git -prune -print | while IFS= read -r gitordner; do
    paket="$(dirname "$gitordner")"
    sauber="${paket}.archiv"
    rm -rf "$sauber"
    mkdir -p "$sauber"
    git -C "$paket" archive HEAD | tar -x -C "$sauber"
    rm -rf "$paket"
    mv "$sauber" "$paket"
done
# Sicherheitsnetz: keine .git-Ordner im Paket.
find build -name .git -prune -exec rm -rf {} +

(cd build && zip -qr "../${ZIEL}" . -x '.git/*' '*/.git/*')
echo "Fertig: ${ZIEL}"
