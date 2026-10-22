"""Erzeugt die STRATO-Integrationsanleitung (PDF) für den Administrator.

Aufruf:  python3 erzeuge_pdf.py [<logo.png> <ziel.pdf>]
Ohne Argumente: Logo emc78-logo.png neben diesem Skript, Ziel docs/EMC78-Lokschuppen-Integrationsanleitung-STRATO.pdf.
Benötigt: pip install reportlab. Schriften: Liberation (Linux) oder Arial/Consolas (Windows), siehe _schriften().
Version und Datum stehen im Text (Suche nach „Version 0.“) und werden je Release von Hand angepasst.
"""
import os
import sys
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_LEFT
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (BaseDocTemplate, PageTemplate, Frame, Paragraph, Spacer, Table, TableStyle,
                                PageBreak, KeepTogether, Image, ListFlowable, ListItem, CondPageBreak)

HIER = os.path.dirname(os.path.abspath(__file__))
if len(sys.argv) >= 3:
    LOGO, ZIEL = sys.argv[1], sys.argv[2]
else:
    LOGO = os.path.join(HIER, "emc78-logo.png")
    ZIEL = os.path.join(HIER, "..", "..", "docs", "EMC78-Lokschuppen-Integrationsanleitung-STRATO.pdf")


def _schriften():
    """Erster vorhandener Schriftsatz: Liberation (Linux), sonst Arial/Consolas (Windows)."""
    saetze = [
        ("/usr/share/fonts/truetype/liberation/", "LiberationSans-Regular.ttf", "LiberationSans-Bold.ttf",
         "LiberationSans-Italic.ttf", "LiberationMono-Regular.ttf"),
        (os.path.join(os.environ.get("WINDIR", r"C:\Windows"), "Fonts") + os.sep, "arial.ttf", "arialbd.ttf",
         "ariali.ttf", "consola.ttf"),
    ]
    for ordner, *dateien in saetze:
        if all(os.path.exists(ordner + d) for d in dateien):
            return [ordner + d for d in dateien]
    sys.exit("Keine passenden Schriften gefunden (Liberation oder Arial/Consolas).")


_S, _SB, _SI, _M = _schriften()
pdfmetrics.registerFont(TTFont("S", _S))
pdfmetrics.registerFont(TTFont("SB", _SB))
pdfmetrics.registerFont(TTFont("SI", _SI))
pdfmetrics.registerFont(TTFont("M", _M))
pdfmetrics.registerFontFamily("S", normal="S", bold="SB", italic="SI", boldItalic="SB")

ROT = colors.HexColor("#E30311"); ROTD = colors.HexColor("#B8020E"); SCHWARZ = colors.HexColor("#1B1B18")
GRAU = colors.HexColor("#C6C6C6"); HELL = colors.HexColor("#F4F3F2"); LEISE = colors.HexColor("#5E5C57")
WARN = colors.HexColor("#FFF3D6"); WARNT = colors.HexColor("#8A5A00")

st = {
    "t": ParagraphStyle("t", fontName="SB", fontSize=26, leading=31, textColor=SCHWARZ),
    "ut": ParagraphStyle("ut", fontName="S", fontSize=13, leading=18, textColor=LEISE),
    "h1": ParagraphStyle("h1", fontName="SB", fontSize=17, leading=21, textColor=ROTD, spaceBefore=10, spaceAfter=6),
    "h2": ParagraphStyle("h2", fontName="SB", fontSize=12.5, leading=16, textColor=SCHWARZ, spaceBefore=8, spaceAfter=4),
    "p": ParagraphStyle("p", fontName="S", fontSize=10, leading=14.2, textColor=SCHWARZ, spaceAfter=5, alignment=TA_LEFT),
    "k": ParagraphStyle("k", fontName="S", fontSize=8.8, leading=12, textColor=LEISE),
    "c": ParagraphStyle("c", fontName="M", fontSize=8.8, leading=11.5, textColor=SCHWARZ),
    "zelle": ParagraphStyle("z", fontName="S", fontSize=9, leading=12, textColor=SCHWARZ),
    "zk": ParagraphStyle("zk", fontName="SB", fontSize=9, leading=12, textColor=SCHWARZ),
}

def P(t, s="p"): return Paragraph(t, st[s])
def code(t): return f'<font name="M" size="9">{t}</font>'
def liste(punkte, nummeriert=False):
    return ListFlowable([ListItem(P(x), leftIndent=14) for i, x in enumerate(punkte)],
                        bulletType="1" if nummeriert else "bullet", start="1" if nummeriert else "•", bulletFontName="SB", bulletFontSize=9.5,
                        leftIndent=16, bulletColor=ROTD, spaceAfter=4)
def tabelle(zeilen, breiten, kopf=True):
    daten = [[Paragraph(str(c), st["zk"] if (kopf and r == 0) else st["zelle"]) for c in z] for r, z in enumerate(zeilen)]
    t = Table(daten, colWidths=breiten, repeatRows=1 if kopf else 0)
    stil = [("VALIGN", (0, 0), (-1, -1), "TOP"), ("GRID", (0, 0), (-1, -1), 0.4, GRAU),
            ("LEFTPADDING", (0, 0), (-1, -1), 5), ("RIGHTPADDING", (0, 0), (-1, -1), 5),
            ("TOPPADDING", (0, 0), (-1, -1), 3.5), ("BOTTOMPADDING", (0, 0), (-1, -1), 3.5)]
    if kopf: stil.append(("BACKGROUND", (0, 0), (-1, 0), HELL))
    t.setStyle(TableStyle(stil)); return t
def kasten(text, farbe=WARN, tfarbe=WARNT):
    t = Table([[Paragraph(text, ParagraphStyle("kx", parent=st["p"], textColor=tfarbe, spaceAfter=0))]], colWidths=[170 * mm])
    t.setStyle(TableStyle([("BACKGROUND", (0, 0), (-1, -1), farbe), ("LEFTPADDING", (0, 0), (-1, -1), 8),
                           ("RIGHTPADDING", (0, 0), (-1, -1), 8), ("TOPPADDING", (0, 0), (-1, -1), 6), ("BOTTOMPADDING", (0, 0), (-1, -1), 6)]))
    return t
def codeblock(zeilen):
    t = Table([[Paragraph("<br/>".join(zeilen), st["c"])]], colWidths=[170 * mm])
    t.setStyle(TableStyle([("BACKGROUND", (0, 0), (-1, -1), HELL), ("BOX", (0, 0), (-1, -1), 0.4, GRAU),
                           ("LEFTPADDING", (0, 0), (-1, -1), 8), ("TOPPADDING", (0, 0), (-1, -1), 6), ("BOTTOMPADDING", (0, 0), (-1, -1), 6)]))
    return t

def deckblatt(c, doc):
    w, h = A4
    c.saveState()
    c.setFillColor(ROT)
    p = c.beginPath(); p.moveTo(0, h); p.lineTo(w, h); p.lineTo(w, h - 32 * mm)
    p.curveTo(w * 0.6, h - 26 * mm, w * 0.3, h - 38 * mm, 0, h - 70 * mm); p.close(); c.drawPath(p, fill=1, stroke=0)
    c.drawImage(LOGO, w / 2 - 32 * mm, h - 125 * mm, 64 * mm, 64 * mm, mask="auto", preserveAspectRatio=True)
    c.setFont("S", 8.5); c.setFillColor(LEISE)
    c.drawString(20 * mm, 14 * mm, "Eisenbahn Modellbahn Club 78 e.V. Halle · Die Modellbahnfamilie")
    c.drawRightString(w - 20 * mm, 14 * mm, "Vertraulich – nur für die Administration")
    c.restoreState()

def folgeseite(c, doc):
    w, h = A4
    c.saveState()
    c.setFillColor(ROT); c.rect(0, h - 9 * mm, w, 9 * mm, fill=1, stroke=0)
    c.setFillColor(colors.white); c.setFont("SB", 9)
    c.drawString(20 * mm, h - 6 * mm, "EMC 78 Lokschuppen · Integrationsanleitung STRATO")
    c.setFont("S", 9); c.drawRightString(w - 20 * mm, h - 6 * mm, "Version 0.8.3 · Stand 22.10.2026")
    c.setStrokeColor(GRAU); c.setLineWidth(0.4); c.line(20 * mm, 17 * mm, w - 20 * mm, 17 * mm)
    c.setFillColor(LEISE); c.setFont("S", 8.5)
    c.drawString(20 * mm, 12 * mm, "Eisenbahn Modellbahn Club 78 e.V. Halle")
    c.drawRightString(w - 20 * mm, 12 * mm, f"Seite {doc.page}")
    c.restoreState()

doc = BaseDocTemplate(ZIEL, pagesize=A4, title="EMC 78 Lokschuppen – Integrationsanleitung STRATO",
                      author="EMC 78 Halle e.V.", subject="Installation und Betrieb auf STRATO Hosting Plus",
                      leftMargin=20 * mm, rightMargin=20 * mm, topMargin=20 * mm, bottomMargin=24 * mm)
rahmen_d = Frame(20 * mm, 24 * mm, 170 * mm, 150 * mm, id="d")
rahmen = Frame(20 * mm, 22 * mm, 170 * mm, A4[1] - 22 * mm - 18 * mm, id="f")
doc.addPageTemplates([PageTemplate("deck", [rahmen_d], onPage=deckblatt), PageTemplate("folge", [rahmen], onPage=folgeseite)])

from reportlab.platypus import NextPageTemplate
s = []
s += [P("EMC 78 Lokschuppen", "t"), Spacer(1, 4), P("Integrationsanleitung für den STRATO-Webspace", "ut"), Spacer(1, 14)]
s.append(tabelle([
    ["Für", "Thomas, Administrator des STRATO-Pakets"],
    ["Anwendung", "EMC 78 Lokschuppen – Fahrzeugverwaltung des Vereins (Stufen 1, 1b und 2a–2e)"],
    ["Version", "0.8.3 (Paket emc78-verwaltung-0.8.3.zip)"],
    ["Paket", "STRATO Hosting Plus, PHP 8.2 eingestellt"],
    ["Ziel", "Testumgebung test.emc78.de, danach Echtbetrieb mitglieder.emc78.de"],
    ["Stand", "22.10.2026"],
], [35 * mm, 135 * mm], kopf=False))
s.append(Spacer(1, 10))
s.append(P("Diese Anleitung beschreibt alle Schritte, die auf STRATO-Seite nötig sind: Datenbanken, Verzeichnisse, "
           "Subdomains, SSL, Konfiguration, erste Anmeldung, Betrieb, Updates und Sicherung sowie die optionalen Erweiterungen OneDrive (Abschnitt 8), E-Mail-Versand (Abschnitt 9) und öffentliche Modellbahnbörse (Abschnitt 10). Die Menünamen im "
           "STRATO-Kundenbereich ändern sich gelegentlich; sie sind deshalb <b>sinngemäß</b> angegeben.", "p"))
s += [NextPageTemplate("folge"), PageBreak()]

# 1
s.append(P("1. Überblick", "h1"))
s.append(P("Der Lokschuppen ist eine schlanke PHP-Webanwendung ohne Framework und ohne Build-Schritt. Alle Abhängigkeiten "
           "(Ordner <font name='M'>vendor/</font>) sind im ZIP enthalten; auf dem Server muss nichts installiert werden."))
s.append(tabelle([
    ["Baustein", "Technik"],
    ["Sprache / Laufzeit", "PHP ab 8.2 (Front-Controller public/index.php, Apache mit .htaccess)"],
    ["Datenbank", "MySQL ab 8 oder MariaDB ab 10.6, Zeichensatz utf8mb4"],
    ["Dateiablage", "Ordner storage/ (Fotos, Dokumente, Sitzungen) – nicht öffentlich erreichbar; optional zusätzlich im OneDrive des Vereins"],
    ["Zugriff", "nur nach Anmeldung; Rollen Mitglied (liest alles, legt eigene Fahrzeuge an und bearbeitet selbst angelegte), Fahrzeugwart (alles rund um alle Fahrzeuge, auch private), Bearbeiter (zusätzlich Personen und Dateien), Administrator (zusätzlich Benutzer, Stammdaten, Sicherung, Update, DSGVO-Löschung)"],
    ["Clients", "Browser auf PC, Tablet, Android und iPhone; als App auf den Startbildschirm installierbar"],
], [45 * mm, 125 * mm]))
s.append(P("Es werden zwei völlig getrennte Instanzen betrieben:", "h2"))
s.append(tabelle([
    ["", "Testumgebung", "Echtbetrieb"],
    ["Adresse", "https://test.emc78.de", "https://mitglieder.emc78.de"],
    ["Verzeichnis", "/test/", "/mitglieder/"],
    ["Subdomain-Ziel", "/test/public", "/mitglieder/public"],
    ["Datenbank", "eigene Datenbank „Lokschuppen Test“", "eigene Datenbank „Lokschuppen“"],
    ["config.php", "testumgebung = true", "testumgebung = false"],
    ["Schutz", "zusätzlich STRATO-Verzeichnisschutz", "nur App-Anmeldung"],
], [32 * mm, 69 * mm, 69 * mm]))
s.append(Spacer(1, 6))
s.append(kasten("<b>Wichtig:</b> Die beiden Instanzen dürfen niemals dieselbe Datenbank oder denselben storage-Ordner "
                "verwenden. Jede Instanz liegt in ihrem eigenen Verzeichnis mit eigener config.php."))

# 2
s.append(P("2. Voraussetzungen prüfen", "h1"))
s.append(tabelle([
    ["Punkt", "Anforderung", "Wo bei STRATO (sinngemäß)"],
    ["PHP-Version", "8.2 oder höher (bereits eingestellt)", "Paketverwaltung › PHP-Einstellungen"],
    ["PHP-Erweiterungen", "pdo_mysql, mbstring, gd, fileinfo, zip, iconv, zlib, curl, openssl (exif empfohlen)", "bei STRATO standardmäßig aktiv"],
    ["Datenbanken", "2 MySQL-Datenbanken (Test und Echtbetrieb)", "Datenbanken und Webspace › Datenbanken"],
    ["SSL", "Zertifikat je Subdomain", "Domains › SSL-Verwaltung"],
    ["Zugang", "SFTP (Server meist ssh.strato.de, Port 22)", "Webspace › SFTP-Zugang"],
    ["Upload-Grenzen", "upload_max_filesize und post_max_size ≥ 64 MB empfohlen", "PHP-Einstellungen bzw. php.ini"],
    ["Arbeitsspeicher", "memory_limit ≥ 256 MB empfohlen (PDF-Import, Fotos)", "PHP-Einstellungen bzw. php.ini"],
    ["Apache", "mod_rewrite und .htaccess erlaubt", "bei STRATO gegeben"],
], [30 * mm, 70 * mm, 70 * mm]))

# 3
s.append(CondPageBreak(90 * mm))
s.append(P("3. Testumgebung einrichten (test.emc78.de)", "h1"))
schritte = [
    "<b>Datenbank anlegen:</b> neue MySQL-Datenbank, Beschreibung „Lokschuppen Test“. Notieren: Datenbank-Server (Host), "
    "Datenbankname, Benutzername, Passwort. Bei STRATO ist der Host ein eigener Servername, nicht localhost.",
    "<b>Paket entpacken:</b> emc78-verwaltung-0.8.3.zip lokal entpacken. Im Hauptordner liegen u. a. "
    + code("public/ src/ vendor/ storage/ migrations/ .htaccess README.md config.example.php") + ".",
    "<b>config.php ausfüllen:</b> die vorbereitete config.php (bzw. eine Kopie von config.example.php) mit den Werten aus "
    "Abschnitt 5 befüllen und in denselben Hauptordner legen (neben README.md, <b>nicht</b> in public/).",
    "<b>Hochladen:</b> per SFTP den Ordner <font name='M'>/test/</font> anlegen und den <b>gesamten Inhalt</b> des entpackten "
    "Ordners hineinladen – inklusive der versteckten .htaccess-Dateien (in FileZilla: Server › Anzeige versteckter Dateien erzwingen).",
    "<b>Schreibrechte:</b> sicherstellen, dass PHP in <font name='M'>/test/storage/</font> (mit Unterordnern dateien/ und sitzungen/) "
    "schreiben darf. Falls nötig per SFTP die Rechte 775 setzen.",
    "<b>Subdomain anlegen:</b> test.emc78.de mit Ziel-Verzeichnis <font name='M'>/test/public</font>. Dadurch liegt nur public/ im Netz.",
    "<b>SSL aktivieren:</b> für test.emc78.de ein Zertifikat aktivieren und zuweisen. Weiter erst, wenn https://test.emc78.de ohne Warnung öffnet.",
    "<b>Verzeichnisschutz:</b> für das Verzeichnis /test einen STRATO-Verzeichnisschutz (htaccess-Passwort) einrichten. "
    "Danach prüfen, dass die mitgelieferte .htaccess in /test/ und /test/public/ noch ihren ursprünglichen Inhalt hat.",
    "<b>Installieren:</b> https://test.emc78.de/install aufrufen. Die Datenbanktabellen werden angelegt, danach den ersten "
    "Administrator (eigener Name, E-Mail, Passwort mit mindestens 10 Zeichen) anlegen.",
    "<b>Beispieldaten laden:</b> als Administrator <i>Verwaltung › Testdaten › Beispieldaten neu laden</i>. Das löscht alle Daten der "
    "Testinstanz und legt 7 erfundene Personen, 12 Fahrzeuge und eine absichtlich doppelte Adresse an. Danach wird man abgemeldet.",
    "<b>Testen:</b> Anmeldung mit mitglied@test.emc78.de, fahrzeugwart@test.emc78.de bzw. bearbeiter@test.emc78.de (Passwort Test-EMC78!) oder admin@test.emc78.de "
    "mit dem Passwort aus testumgebung_admin_passwort.",
]
s.append(liste(schritte, nummeriert=True))

# 4
s.append(CondPageBreak(60 * mm))
s.append(P("4. Echtbetrieb einrichten (mitglieder.emc78.de)", "h1"))
s.append(P("Gleiche Schritte wie in Abschnitt 3, mit diesen Unterschieden:"))
s.append(liste([
    "eigene, zweite Datenbank (Beschreibung „Lokschuppen“), Verzeichnis <font name='M'>/mitglieder/</font>, Subdomain-Ziel <font name='M'>/mitglieder/public</font>",
    "in der config.php <font name='M'>'testumgebung' =&gt; false</font>; testumgebung_admin_passwort bleibt leer",
    "kein Verzeichnisschutz nötig (alle Inhalte nur nach Anmeldung); keine Beispieldaten laden",
    "nach /install den Vorstand als Administrator anlegen und unter <i>Verwaltung › Benutzer</i> weitere Zugänge vergeben",
    "auf der Vereinswebseite einen Link setzen: <font name='M'>&lt;a href=\"https://mitglieder.emc78.de\"&gt;Mitgliederbereich&lt;/a&gt;</font>",
]))

# 5
s.append(CondPageBreak(80 * mm))
s.append(P("5. Konfiguration (config.php)", "h1"))
s.append(P("Die Datei liegt im Hauptordner der Instanz und ist durch die .htaccess gegen Abruf gesperrt. Rechte möglichst 640."))
s.append(tabelle([
    ["Schlüssel", "Bedeutung", "Wert Test / Echtbetrieb"],
    ["db_dsn", "Datenbankverbindung", "mysql:host=&lt;Host&gt;;dbname=&lt;Name&gt;;charset=utf8mb4"],
    ["db_benutzer / db_passwort", "Zugangsdaten der Datenbank", "aus der STRATO-Datenbankverwaltung"],
    ["basis_url", "Unterpfad der Anwendung", "leer (Subdomain zeigt direkt auf public/)"],
    ["speicher_pfad", "Ablage für Dateien und Sitzungen", "__DIR__ . '/storage' (unverändert lassen)"],
    ["upload_max_mb", "max. Größe je Datei", "64 (höchstens so groß wie die PHP-Grenzen)"],
    ["app_name", "Anzeigename", "EMC 78 Lokschuppen"],
    ["debug", "technische Fehlerdetails anzeigen", "false (immer)"],
    ["cookie_secure", "Sitzungs-Cookie nur über HTTPS", "true (immer)"],
    ["sitzung_minuten", "Anmeldung verfällt nach Inaktivität", "480 (8 Stunden)"],
    ["testumgebung", "Testleiste, Testzugänge, Beispieldaten", "true / false – genau so, ohne Anführungszeichen"],
    ["testumgebung_admin_passwort", "Passwort für admin@test.emc78.de", "eigenes Passwort (≥ 10 Zeichen) / leer"],
    ["oeffentliche_url", "vollständige Adresse der Instanz (für OneDrive)", "https://test.emc78.de / https://mitglieder.emc78.de"],
    ["onedrive_client_id, onedrive_client_secret", "App-Registrierung bei Microsoft", "leer lassen, bis OneDrive eingerichtet wird (Abschnitt 8)"],
    ["app_schluessel", "verschlüsselt die OneDrive-Anmeldung", "je Instanz eigener Wert, später nie mehr ändern"],
    ["onedrive_originale_lokal_behalten", "Originale zusätzlich auf dem Webspace", "true (empfohlen)"],
    ["onedrive_zeitlimit_s", "Wartezeit je OneDrive-Anfrage", "20"],
    ["onedrive_zeitbudget_s", "max. Übertragungszeit je Seitenaufruf", "25"],
    ["mail_smtp_host … mail_absender_name", "E-Mail-Versand für „Kennwort vergessen“", "leer lassen oder Abschnitt 9"],
    ["boerse_oeffentlich", "öffentliche Modellbahnbörse /boerse", "false (Einschalten: Abschnitt 10)"],
    ["datenschutz_url", "Datenschutzerklärung des Vereins", "https://www.emc78.de/datenschutz (Pflicht für /boerse)"],
], [45 * mm, 55 * mm, 70 * mm]))
s.append(Spacer(1, 6))
s.append(CondPageBreak(110 * mm))
s.append(P("Beispiel (Testumgebung, Werte in spitzen Klammern ersetzen):", "h2"))
s.append(codeblock([
    "&lt;?php",
    "return [",
    "&nbsp;&nbsp;'db_dsn' =&gt; 'mysql:host=&lt;DATENBANK-HOST&gt;;dbname=&lt;DATENBANK-NAME&gt;;charset=utf8mb4',",
    "&nbsp;&nbsp;'db_benutzer' =&gt; '&lt;DATENBANK-BENUTZER&gt;',",
    "&nbsp;&nbsp;'db_passwort' =&gt; '&lt;DATENBANK-PASSWORT&gt;',",
    "&nbsp;&nbsp;'basis_url' =&gt; '',",
    "&nbsp;&nbsp;'speicher_pfad' =&gt; __DIR__ . '/storage',",
    "&nbsp;&nbsp;'upload_max_mb' =&gt; 64,",
    "&nbsp;&nbsp;'app_name' =&gt; 'EMC 78 Lokschuppen',",
    "&nbsp;&nbsp;'debug' =&gt; false,",
    "&nbsp;&nbsp;'cookie_secure' =&gt; true,",
    "&nbsp;&nbsp;'sitzung_minuten' =&gt; 480,",
    "&nbsp;&nbsp;'testumgebung' =&gt; true,",
    "&nbsp;&nbsp;'testumgebung_admin_passwort' =&gt; '&lt;EIGENES-ADMIN-PASSWORT&gt;',",
    "&nbsp;&nbsp;'oeffentliche_url' =&gt; 'https://test.emc78.de',",
    "&nbsp;&nbsp;'onedrive_client_id' =&gt; '',&nbsp;&nbsp;&nbsp;// Abschnitt 8",
    "&nbsp;&nbsp;'onedrive_client_secret' =&gt; '',",
    "&nbsp;&nbsp;'app_schluessel' =&gt; '',",
    "&nbsp;&nbsp;'onedrive_originale_lokal_behalten' =&gt; true,",
    "&nbsp;&nbsp;'onedrive_zeitlimit_s' =&gt; 20,",
    "&nbsp;&nbsp;'onedrive_zeitbudget_s' =&gt; 25,",
    "&nbsp;&nbsp;'boerse_oeffentlich' =&gt; false,&nbsp;&nbsp;// Abschnitt 10",
    "&nbsp;&nbsp;'datenschutz_url' =&gt; '',",
    "&nbsp;&nbsp;'mail_smtp_host' =&gt; '',&nbsp;&nbsp;// Abschnitt 9",
    "];",
]))

# 6
s.append(P("6. Sicherheit", "h1"))
s.append(liste([
    "<b>Getrennte Instanzen:</b> eigene Datenbank, eigenes Verzeichnis, eigener storage-Ordner. Sitzungen werden je Instanz "
    "getrennt gespeichert und an die Instanz gebunden – eine Anmeldung in der Testumgebung gilt nicht im Echtbetrieb.",
    "<b>Nur public/ ist öffentlich:</b> src/, vendor/, migrations/, storage/ und config.php sind über das Subdomain-Ziel und die "
    ".htaccess gesperrt. Kontrolle nach dem Hochladen: https://test.emc78.de/config.php und https://test.emc78.de/storage/ liefern „nicht gefunden“; Dateien werden nur über die App ausgeliefert.",
    "<b>HTTPS ist Pflicht:</b> ohne SSL funktioniert die Anmeldung nicht (cookie_secure = true).",
    "<b>Uploads:</b> nur erlaubte Dateitypen (Bilder, PDF, Office, CSV), Inhalt wird geprüft, gespeicherte Dateien haben keine Endung und werden nie ausgeführt.",
    "<b>Anmeldung:</b> Sperre nach 5 Fehlversuchen in 15 Minuten, Passwörter nur als Hash gespeichert, CSRF-Schutz für alle Formulare.",
    "<b>Datenschutz:</b> personenbezogene Daten der Mitglieder (Name, Kontakt, Eigentum). Zugänge nur an Mitglieder vergeben, Sicherungen sicher aufbewahren.",
]))

# 7
s.append(CondPageBreak(70 * mm))
s.append(P("7. Betrieb: Update, Sicherung, Wiederherstellung", "h1"))
s.append(P("Update auf eine neue Version", "h2"))
s.append(liste([
    "Als Administrator eine Sicherung erstellen (<i>Verwaltung › Sicherung</i>).",
    "Neues ZIP entpacken und per SFTP über die bestehenden Dateien laden. <b>config.php und storage/ nicht löschen</b> "
    "(das ZIP enthält keine config.php und keine hochgeladenen Dateien).",
    "Als Administrator anmelden. Erscheint „Es steht ein Datenbank-Update an.“, unter <i>Verwaltung › Update</i> ausführen. "
    "Bis dahin sehen andere Benutzer eine Wartungsseite.",
], nummeriert=True))
s.append(kasten("<b>Version 0.8.3</b> (Korrektur „Zugang anlegen“, App-Beschreibung für Smartphones mit Dateityp, zusätzliche Sicherheits-Header in public/.htaccess; ohne Datenbank-Änderung) setzt auf 0.8.1 auf. 0.8.1 bringt eine Datenbank-Änderung (Migration 020: Fahrzeug-Import aus CSV und Kennzeichen „ungeprüft“; zuvor bis 019 u. a. Kennwort vergessen, Anlagen, Wartung, Reparaturen, Etiketten, Magazin, Modellbahnbörse). Der Import legt vorübergehend Dateien in storage/import/ ab (wird automatisch angelegt, durch storage/.htaccess gesperrt). Wer bereits eine ältere Version installiert hat, führt nach dem Hochladen einmal <i>Verwaltung › Update</i> aus. Bei einer Neuinstallation geschieht das automatisch über /install. Ohne OneDrive-Einträge in der config.php arbeitet die Anwendung wie bisher."))
s.append(P("Sicherung", "h2"))
s.append(P("<i>Verwaltung › Sicherung › Sicherung herunterladen</i> liefert ein ZIP mit datenbank.sql, dem Ordner dateien/ und info.txt. "
           "Die App erinnert, wenn die letzte Sicherung älter als 30 Tage ist. Zusätzlich die Datensicherung von STRATO nutzen."))
s.append(P("Wiederherstellung", "h2"))
s.append(liste([
    "Instanz wie in Abschnitt 3 bereitstellen, /install aber <b>nicht</b> aufrufen.",
    "datenbank.sql in phpMyAdmin (Importieren) in die leere Datenbank einspielen.",
    "Ordner dateien aus dem ZIP <b>in den Ordner storage/ hinein</b> laden, sodass storage/dateien/2026/… entsteht.",
    "Anwendung aufrufen und anmelden.",
], nummeriert=True))

# 8 OneDrive
s.append(CondPageBreak(70 * mm))
s.append(P("8. OneDrive-Ablage einrichten (optional)", "h1"))
s.append(P("Ab Version 0.3.0 kann die Anwendung Fotos und Dokumente zusätzlich im OneDrive des Vereins ablegen, sortiert nach "
           "Fahrzeug (<font name='M'>Fahrzeuge/&lt;Inventarnummer&gt; &lt;Bezeichnung&gt;/</font>, sonst <font name='M'>Sonstiges/</font>). "
           "Die Anbindung ist freiwillig; ohne sie ändert sich nichts. Die ausführliche Anleitung steht im README.md des Pakets "
           "(Abschnitt „OneDrive anbinden“). Beteiligt sind der Vorstand und der Administrator. Dauer etwa eine Stunde."))
s.append(liste([
    "<b>Vereinskonto (Vorstand):</b> ein eigenes, kostenloses Microsoft-Konto für den Verein anlegen (signup.live.com), "
    "kein privates Konto. Zugangsdaten beim Vorstand hinterlegen, Zwei-Faktor-Anmeldung einschalten.",
    "<b>Ordner freigeben (Vorstand):</b> die Eigentümerin bzw. der Eigentümer des bisherigen EMC-Ordners teilt ihn mit dem "
    "Vereinskonto mit <b>„Kann bearbeiten“</b>. Im Vereinskonto unter „Geteilt“ beim Ordner <b>„Zu meinen Dateien hinzufügen“</b> wählen.",
    "<b>Rückruf-URL ablesen:</b> oeffentliche_url in der config.php eintragen, dann in der Anwendung <i>Verwaltung › OneDrive</i> öffnen. "
    "Dort steht z. B. " + code("https://mitglieder.emc78.de/admin/onedrive/rueckruf") + ".",
    "<b>App registrieren:</b> mit dem Vereinskonto im Microsoft Entra Admin Center (entra.microsoft.com) bzw. Azure-Portal "
    "<i>App-Registrierungen › Neue Registrierung</i>: Kontotyp <b>„Nur persönliche Microsoft-Konten“</b>, Umleitungs-URI Plattform "
    "<b>„Web“</b> = exakt die Rückruf-URL. Die <b>Anwendungs-ID (Client)</b> kopieren (onedrive_client_id).",
    "<b>Clientschlüssel:</b> <i>Zertifikate &amp; Geheimnisse › Neuer geheimer Clientschlüssel</i>, den <b>Wert</b> sofort kopieren "
    "(onedrive_client_secret). <b>Ablaufdatum notieren</b> und vorher einen neuen Schlüssel anlegen.",
    "<b>Berechtigungen:</b> <i>API-Berechtigungen › Microsoft Graph › Delegierte Berechtigungen</i>: "
    + code("Files.ReadWrite.All") + ", " + code("offline_access") + ", " + code("User.Read") + ".",
    "<b>config.php ergänzen:</b> onedrive_client_id, onedrive_client_secret und app_schluessel eintragen. Den app_schluessel als "
    "Vorschlag von der OneDrive-Seite übernehmen oder per SSH erzeugen: " + code('php -r "echo base64_encode(random_bytes(32));"') + ".",
    "<b>Verbinden:</b> <i>Verwaltung › OneDrive › Mit OneDrive verbinden</i>, mit dem <b>Vereinskonto</b> anmelden, Zugriff erlauben. "
    "Danach den EMC-Ordner als <b>Zielordner</b> wählen.",
    "<b>Umzug:</b> „Vorhandene Dateien nach OneDrive umziehen“ merkt alle bisherigen Dateien vor; pro Klick werden einige übertragen.",
    "<b>Cronjob (empfohlen):</b> in der STRATO-Cronjob-Verwaltung (sinngemäß, nicht in jedem Paket) alle 15 Minuten "
    + code("/mitglieder/bin/onedrive-sync.php") + " mit PHP ab 8.2 ausführen; für die Testumgebung " + code("/test/bin/onedrive-sync.php") + ".",
], nummeriert=True))
s.append(Spacer(1, 4))
s.append(kasten("<b>Wichtig:</b> Testumgebung und Echtbetrieb bekommen je einen eigenen app_schluessel und nie denselben Zielordner "
                "(Testumgebung: eigene App-Registrierung oder zweite Umleitungs-URI und ein eigener Testordner). Der app_schluessel "
                "wird später nicht mehr geändert, sonst muss neu verbunden werden. Clientschlüssel und app_schluessel stehen nur in der config.php. "
                "<b>Nie eine Sicherung des Echtbetriebs in die Testumgebung einspielen</b>, solange dort OneDrive mit dem Vereinskonto verbunden ist; "
                "nach einem solchen Einspielen sofort „Trennen“ und einen eigenen Testordner wählen."))
s.append(P("Bei Störungen geht nichts verloren: Dateien sind zuerst auf dem Webspace gespeichert und warten in einer Warteschlange; "
           "neue Versuche folgen automatisch. Die Anwendung erzeugt keine öffentlichen Freigabelinks, Dateien gibt es weiterhin nur nach Anmeldung.", "p"))

# 9 Mail
s.append(CondPageBreak(70 * mm))
s.append(P("9. E-Mail-Versand für „Kennwort vergessen“ (optional)", "h1"))
s.append(P("Ohne Postfach funktioniert „Kennwort vergessen?“ trotzdem: Die Anfrage erscheint bei den Administratoren, die ein Startpasswort vergeben. "
           "Mit einem Vereinspostfach bekommt das Mitglied automatisch einen Link (60 Minuten gültig, nur einmal nutzbar)."))
s.append(liste([
    "Im STRATO-Kundenbereich (sinngemäß <i>E-Mail › Postfach anlegen</i>) ein Postfach anlegen, z. B. " + code("lokschuppen@emc78.de") + ".",
    "In der config.php eintragen: " + code("'mail_smtp_host' => 'smtp.strato.de'") + ", " + code("'mail_smtp_verschluesselung' => 'ssl'") + ", "
    + code("'mail_smtp_port' => 465") + ", " + code("'mail_smtp_benutzer'") + " und " + code("'mail_absender'") + " = Postfachadresse, "
    + code("'mail_smtp_passwort'") + " = Kennwort des Postfachs. " + code("oeffentliche_url") + " muss gesetzt sein.",
    "Testen: Abmelden, „Kennwort vergessen?“ mit der eigenen Adresse. Kommt keine E-Mail, erscheint die Anfrage unter <i>Verwaltung › Benutzer</i>.",
], nummeriert=True))
s.append(kasten("Das Kennwort des Postfachs steht nur in der config.php. In der Testumgebung zeigt die App den Link ohne Postfach direkt an – dort darum nie echte Mitgliederdaten verwenden."))

# 10 Börse
s.append(CondPageBreak(70 * mm))
s.append(P("10. Öffentliche Modellbahnbörse und Cronjobs", "h1"))
s.append(P("Unter " + code("https://mitglieder.emc78.de/boerse") + " können Besucher ohne Anmeldung freigegebene Angebote sehen, Kaufanfragen stellen "
           "und Sammlungen anbieten. Der Bereich ist ausgeschaltet, bis beides gesetzt ist:"))
s.append(liste([
    code("'boerse_oeffentlich' => true") + " (genau so, ohne Anführungszeichen) – Entscheidung des Vorstands.",
    code("'datenschutz_url' => 'https://www.emc78.de/datenschutz'") + " – Adresse der Datenschutzerklärung (nur https).",
]))
s.append(P("Für die Datenschutzerklärung: Seiten mit Formular setzen ein technisch notwendiges Sitzungscookie; IP-Adressen werden nur als Prüfsumme "
           "24 Stunden für den Spamschutz gespeichert; Fotos werden ohne Aufnahmeort und Kameradaten neu gespeichert; Anfragen werden nach 12 Monaten gelöscht."))
s.append(P("Cronjobs (STRATO-Cronjob-Verwaltung, PHP ab 8.2, sinngemäß):", "h2"))
s.append(tabelle([
    ["Skript", "Intervall", "Zweck"],
    ["/mitglieder/bin/onedrive-sync.php", "alle 15 Minuten", "OneDrive-Übertragung nachholen (nur mit OneDrive)"],
    ["/mitglieder/bin/boerse-aufraeumen.php", "täglich", "abgelaufene Börsenanfragen löschen"],
], [70 * mm, 30 * mm, 70 * mm]))
s.append(kasten("Höchstens 5 Anfragen je Stunde und Absender und 60 je Stunde insgesamt; darüber sehen Besucher „Bitte später erneut versuchen“. "
                "Auf www.emc78.de genügt ein Link: " + code('&lt;a href="https://mitglieder.emc78.de/boerse"&gt;Modellbahnbörse&lt;/a&gt;')))

# 8
s.append(CondPageBreak(60 * mm))
s.append(P("11. Fehlerbehebung", "h1"))
s.append(tabelle([
    ["Symptom", "Ursache und Lösung"],
    ["Fehler 500 direkt nach dem Hochladen", "Der Server erlaubt „Options“ in .htaccess nicht: in public/.htaccess die Zeile Options -Indexes entfernen."],
    ["Seite „config.php fehlt“", "config.php liegt nicht im Hauptordner der Instanz (neben README.md) oder heißt anders."],
    ["Technischer Fehler beim ersten Aufruf", "Datenbankzugang falsch (Host ist bei STRATO nicht localhost) oder PHP-Version unter 8.2."],
    ["Anmeldung klappt nicht, „Sitzung abgelaufen“", "Seite ohne HTTPS aufgerufen oder storage/sitzungen/ nicht beschreibbar (Rechte 775)."],
    ["Foto-Upload schlägt fehl", "storage/dateien/ nicht beschreibbar oder Datei größer als upload_max_filesize / post_max_size."],
    ["Alle sehen eine Wartungsseite", "Datenbank-Update steht aus: als Administrator Verwaltung › Update ausführen."],
    ["Verzeichnisschutz führt zu Fehler", "STRATO hat die mitgelieferte .htaccess ersetzt: deren Zeilen aus dem ZIP wieder ergänzen."],
    ["OneDrive-Seite zeigt keine Rückruf-URL", "oeffentliche_url fehlt oder ist ungültig (nur https://, ohne Pfad, ohne Schrägstrich am Ende)."],
    ["Microsoft meldet Fehler bei der Umleitungs-URI", "Die Umleitungs-URI der App-Registrierung weicht von der Rückruf-URL auf der OneDrive-Seite ab: exakt übernehmen."],
    ["OneDrive „abgelaufen – bitte neu verbinden“", "Anmeldung bei Microsoft beendet oder app_schluessel geändert: auf der OneDrive-Seite neu verbinden. Dateien warten solange."],
    ["Anmeldefehler invalid_client", "Clientschlüssel abgelaufen oder falsch kopiert: neuen Schlüssel anlegen und in config.php eintragen."],
    ["/boerse zeigt „nicht gefunden“", "boerse_oeffentlich nicht genau true oder datenschutz_url fehlt / nicht https."],
    ["„Kennwort vergessen“ schickt keine E-Mail", "mail_smtp_host, mail_absender oder oeffentliche_url fehlt bzw. Postfach-Kennwort falsch; die Anfrage landet dann bei den Administratoren."],
    ["PDF-Import meldet „zu groß für diesen Webspace“", "memory_limit erhöhen (≥ 256 MB) oder kleineres PDF verwenden."],
], [55 * mm, 115 * mm]))

# 9
s.append(P("12. Abschluss-Checkliste", "h1"))
s.append(tabelle([
    ["", "Prüfpunkt"],
    ["[  ]", "https://test.emc78.de öffnet mit gültigem Zertifikat und fragt nach dem Verzeichnisschutz"],
    ["[  ]", "/install durchlaufen, Administrator angelegt, Beispieldaten geladen"],
    ["[  ]", "Anmeldung als Mitglied und als Bearbeiter funktioniert; Foto-Upload funktioniert"],
    ["[  ]", "Sicherung lässt sich herunterladen"],
    ["[  ]", "config.php und storage/ sind von außen nicht abrufbar"],
    ["[  ]", "Echtbetrieb: eigene Datenbank, testumgebung = false, Link auf emc78.de gesetzt"],
    ["[  ]", "Nach dem Update auf 0.3.0: Verwaltung › Update ausgeführt, Verwaltung › OneDrive öffnet ohne Fehler"],
    ["[  ]", "Optional: OneDrive verbunden, Zielordner gewählt, Testfoto erscheint im Vereinsordner"],
    ["[  ]", "Nach dem Update auf 0.8.3: Verwaltung › Update ausgeführt (bis Migration 020)"],
    ["[  ]", "Optional: Postfach eingetragen, „Kennwort vergessen“ liefert eine E-Mail"],
    ["[  ]", "Optional: Börse eingeschaltet, /boerse zeigt die Angebotsliste, Cronjob boerse-aufraeumen täglich"],
], [10 * mm, 160 * mm]))
s.append(Spacer(1, 8))
s.append(P("Neu seit 0.4.0: Kennwort vergessen, Anlagen, Wartung mit Ampel, Defekt melden und Reparaturen, Codes und Etiketten, Funktionstasten aus PDF, Magazin und Modellbahnbörse mit Kasse und öffentlichem Bereich. Einzelheiten im README.md des Pakets.", "k"))

doc.build(s)
print("ok")
