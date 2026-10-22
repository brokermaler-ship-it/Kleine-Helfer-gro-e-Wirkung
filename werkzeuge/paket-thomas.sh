#!/usr/bin/env bash
# Schnürt das Übergabepaket für den STRATO-Administrator:
#   dist/EMC78-Lokschuppen-Paket-STRATO-<VERSION>.zip mit Anleitung (PDF), Installationspaket,
#   config-Vorlage der Testumgebung und LIES-MICH.txt.
# Vorher: bin/release.sh (baut dist/emc78-verwaltung-<VERSION>.zip) und ggf. werkzeuge/anleitung/erzeuge_pdf.py.
# LIES-MICH.txt: Vorlage werkzeuge/anleitung/LIES-MICH-vorlage.txt je Release anpassen ("Neu in …").
set -euo pipefail
WURZEL="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$WURZEL"
VERSION="$(tr -d '[:space:]' < VERSION)"
INSTALL="dist/emc78-verwaltung-${VERSION}.zip"
[ -f "$INSTALL" ] || { echo "Fehlt: $INSTALL – zuerst bin/release.sh ausführen." >&2; exit 1; }
ORDNER="dist/paket-thomas-${VERSION}"
rm -rf "$ORDNER" && mkdir -p "$ORDNER"
cp docs/EMC78-Lokschuppen-Integrationsanleitung-STRATO.pdf "$INSTALL" werkzeuge/anleitung/config-testumgebung.php "$ORDNER"/
cp werkzeuge/anleitung/LIES-MICH-vorlage.txt "$ORDNER/LIES-MICH.txt"
ZIEL="dist/EMC78-Lokschuppen-Paket-STRATO-${VERSION}.zip"
rm -f "$ZIEL"
(cd "$ORDNER" && zip -qr "../$(basename "$ZIEL")" .)
echo "Fertig: $ZIEL"
