import sys
from docx import Document
from docx.shared import Pt, Cm, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_COLOR_INDEX, WD_BREAK
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.enum.section import WD_ORIENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

OUT = sys.argv[1]
THEMA = "HSL CZ – anlassbezogenes Audit: Vorfälle und EBA-Meldungen"
ORT = "HSL CZ"
TAET = "Eisenbahnverkehrsleistungen CZ/DE inkl. grenzüberschreitender Verkehre"
GREY = "D9D9D9"; LIGHT = "F2F2F2"

doc = Document()
sec = doc.sections[0]
sec.page_width, sec.page_height = Cm(21), Cm(29.7)
for m in ("left_margin", "right_margin"): setattr(sec, m, Cm(1.8))
sec.top_margin = Cm(1.8); sec.bottom_margin = Cm(1.6)

st = doc.styles["Normal"]; st.font.name = "Arial"; st.font.size = Pt(10)
st.element.rPr.rFonts.set(qn("w:eastAsia"), "Arial")
st.paragraph_format.space_after = Pt(2)
from docx.enum.style import WD_STYLE_TYPE
tc_style = doc.styles.add_style("Tabelleninhalt", WD_STYLE_TYPE.PARAGRAPH)
tc_style.base_style = st; tc_style.font.name = "Arial"; tc_style.font.size = Pt(10)
tc_style.paragraph_format.space_after = Pt(0); tc_style.paragraph_format.space_before = Pt(0)
for lvl, size in ((1, 14), (2, 12)):
    h = doc.styles[f"Heading {lvl}"]; h.font.name = "Arial"; h.font.size = Pt(size)
    h.font.bold = True; h.font.color.rgb = RGBColor(0, 0, 0)
    h.element.rPr.rFonts.set(qn("w:asciiTheme"), "") if False else None
    rf = h.element.rPr.rFonts; [rf.attrib.pop(qn(a), None) for a in ("w:asciiTheme","w:hAnsiTheme","w:eastAsiaTheme","w:cstheme")]
    rf.set(qn("w:ascii"), "Arial"); rf.set(qn("w:hAnsi"), "Arial")
    h.paragraph_format.space_before = Pt(12); h.paragraph_format.space_after = Pt(6)
    h.paragraph_format.keep_with_next = True

# Kopf-/Fußzeile
hp = sec.header.paragraphs[0]
r = hp.add_run("HSL Logistik  |  Protokoll interner Audits  |  " + THEMA); r.font.size = Pt(8)
fp = sec.footer.paragraphs[0]; fp.alignment = WD_ALIGN_PARAGRAPH.RIGHT
def field(p, code):
    r = p.add_run(); r.font.size = Pt(8)
    for t, v in (("begin", None), ("instr", code), ("separate", None), ("end", None)):
        if t == "instr":
            e = OxmlElement("w:instrText"); e.set(qn("xml:space"), "preserve"); e.text = code
        else:
            e = OxmlElement("w:fldChar"); e.set(qn("w:fldCharType"), t)
        r._r.append(e)
    return r
r = fp.add_run("Seite "); r.font.size = Pt(8); field(fp, "PAGE")
r = fp.add_run(" von "); r.font.size = Pt(8); field(fp, "NUMPAGES")

def shade(cell, color):
    tcPr = cell._tc.get_or_add_tcPr(); s = OxmlElement("w:shd")
    s.set(qn("w:val"), "clear"); s.set(qn("w:color"), "auto"); s.set(qn("w:fill"), color); tcPr.append(s)

def put(cell, text="", bold=False, align=None, size=None):
    p = cell.paragraphs[0]; p.style = tc_style
    if text:
        r = p.add_run(text); r.bold = bold
        if size: r.font.size = Pt(size)
    if align: p.alignment = align
    cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
    return p

def table(rows, widths):
    t = doc.add_table(rows=rows, cols=len(widths)); t.style = "Table Grid"
    t.alignment = WD_TABLE_ALIGNMENT.CENTER; t.autofit = False
    for row in t.rows:
        for i, w in enumerate(widths): row.cells[i].width = Cm(w)
    grid = t._tbl.tblGrid
    for gc, w in zip(grid.findall(qn("w:gridCol")), widths): gc.set(qn("w:w"), str(int(Cm(w).twips)))
    tblPr = t._tbl.tblPr
    lay = OxmlElement("w:tblLayout"); lay.set(qn("w:type"), "fixed"); tblPr.append(lay)
    tw = OxmlElement("w:tblW"); tw.set(qn("w:w"), str(int(Cm(sum(widths)).twips))); tw.set(qn("w:type"), "dxa")
    old = tblPr.find(qn("w:tblW"))
    if old is not None: tblPr.remove(old)
    tblPr.append(tw)
    return t

def repeat_header(row):
    trPr = row._tr.get_or_add_trPr(); e = OxmlElement("w:tblHeader"); e.set(qn("w:val"), "true"); trPr.append(e)

def cant_split(row):
    trPr = row._tr.get_or_add_trPr(); trPr.append(OxmlElement("w:cantSplit"))

def para(text="", bold=False, size=None, after=None, before=None, keep=False):
    p = doc.add_paragraph()
    if text:
        r = p.add_run(text); r.bold = bold
        if size: r.font.size = Pt(size)
    if after is not None: p.paragraph_format.space_after = Pt(after)
    if before is not None: p.paragraph_format.space_before = Pt(before)
    if keep: p.paragraph_format.keep_with_next = True
    return p

C = WD_ALIGN_PARAGRAPH.CENTER

# ---------- Titel ----------
p = para("Protokoll interner Audits", bold=True, size=16, after=2)
p = para("Anlassbezogenes Audit HSL CZ – Grund: Vorfälle und EBA-Meldungen", size=11, after=10)

# ---------- 1. Auditdurchführung ----------
para("Auditdurchführung", bold=True, size=11, after=4, keep=True)
meta = [("Datum:", ""), ("Beginn:", ""), ("Ende:", ""), ("Ort(e) der Durchführung:", ORT + " – "),
        ("Auditart / Anlass:", "Anlassbezogenes (außerplanmäßiges) internes Audit – Vorfälle und EBA-Meldungen"),
        ("Auditor(en):", "Enrico Görzen (QMB)"), ("Teilnehmer:", "")]
t = table(len(meta), [5.0, 12.4])
for i, (k, v) in enumerate(meta):
    put(t.cell(i, 0), k, bold=True); shade(t.cell(i, 0), LIGHT); put(t.cell(i, 1), v)
    t.rows[i].height = Cm(0.75)
para(after=6)

# ---------- 2. Auditbericht ----------
para("Auditbericht", bold=True, size=11, after=4, keep=True)
t = table(3, [17.4])
p = put(t.cell(0, 0)); r = p.add_run("Abteilung: "); r.bold = True; p.add_run(THEMA)
p = put(t.cell(1, 0)); r = p.add_run("Abweichungen:   "); r.bold = True
p.add_run("☐ keine     ☐ geringfügig     ☐ schwerwiegend")
p = put(t.cell(2, 0), "Folgende Maßnahmen wurden festgestellt:", bold=True)
p2 = t.cell(2, 0).add_paragraph(style=tc_style); t.rows[2].height = Cm(4.0)
t.cell(2, 0).vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.TOP
para(after=6)

# ---------- Fragenkatalog ----------
A = " (anlassbezogen)"
KAP = [
 ("Kontext der Organisation (Kap. 4)", [
  f"Sind die für den Standort {ORT} relevanten internen und externen Themen sowie die interessierten Parteien (Auftraggeber, Behörden: EBA / Drážní úřad, EIU wie SŽ und DB InfraGO, Schnittstellenpartner) bekannt und berücksichtigt?",
  f"Ist der Geltungsbereich der Tätigkeiten am Standort ({TAET}) klar abgegrenzt und sind die zugehörigen Prozesse – einschließlich Ereignismanagement und Meldewesen – beschrieben?"]),
 ("Führung (Kap. 5)", [
  "Sind Rollen, Verantwortlichkeiten und Befugnisse am Standort eindeutig festgelegt und im Organigramm bzw. in Funktionsbeschreibungen dokumentiert (inkl. Zuständigkeit für Ereignismeldungen an Behörden)?",
  "Wie stellt die Standortleitung sicher, dass die Sicherheits- und Qualitätspolitik der HSL am Standort bekannt ist und vorgelebt wird?",
  "Wie wird eine positive Sicherheitskultur (offene Fehler-/Meldekultur, anonymes Meldewesen, Just Culture nach Vorfällen) gefördert?"]),
 ("Planung (Kap. 6)", [
  "Werden standortbezogene Risiken und Chancen systematisch bewertet und in der Risikotabelle dokumentiert – und wurde die Risikotabelle nach den jüngsten Vorfällen überprüft/aktualisiert?",
  "Wie werden Änderungen am Standort auf Risiken beurteilt und dokumentiert (CSM-RA, VO 402/2013)?",
  "Sind messbare Sicherheits- und Qualitätsziele festgelegt (z. B. Anzahl meldepflichtiger Ereignisse, Fristtreue der Meldungen) und wird deren Erreichung verfolgt?"]),
 ("Unterstützung (Kap. 7)", [
  "Sind die erforderlichen dokumentierten Informationen (VA, AA, DA, Checklisten – insbesondere zu Ereignismeldung und Notfallmanagement) in gültiger Fassung über ONTIV verfügbar und gelenkt – auch in tschechischer Sprache?",
  "Wie wird sichergestellt, dass die Mitarbeitenden die erforderlichen Qualifikationen besitzen (inkl. Sprachkenntnisse für den Verkehr in DE) und diese in LP One nachgewiesen sind?",
  "Sind ausreichende Ressourcen (Personal, Fahrzeuge, PSA, Funk/GSM-R) verfügbar?",
  "Wie werden sicherheitsrelevante Informationen kommuniziert (Schichtübergabe, Sicherheitsinfos zu Vorfällen, Newsletter, Lessons Learned)?"]),
 ("Betrieb (Kap. 8)", [
  "Werden die operativen Tätigkeiten gemäß festgelegten Vorgaben (örtliche Richtlinien, Betriebsanweisungen, Netzzugangsbedingungen CZ/DE) geplant, gesteuert und dokumentiert?",
  "Wie wird im Tagesbetrieb die Einhaltung gesetzlicher/interner Vorgaben (Arbeits- und Ruhezeiten, Regelwerke DE/CZ) überwacht?",
  "Wie ist das Notfall- und Störungsmanagement geregelt und ist es dem Personal bekannt?",
  "Wie werden ausgelagerte/zugekaufte Leistungen gesteuert und deren Qualität überwacht?",
  "Ist die Meldekette bei gefährlichen Ereignissen (Triebfahrzeugführer → Fahrdienstleiter/Notfallmanager → Leitstelle → Sicherheitsmanagement → EBA bzw. Untersuchungsstelle in DE / Drážní úřad bzw. Drážní inspekce in CZ) mit Fristen und Zuständigkeiten festgelegt und dem Personal bekannt?" + A,
  "Werden Beweise und Daten nach einem Vorfall gesichert (Fahrtenschreiber/Registriereinrichtung, Fotos, Zeugenaussagen, Dokumente) und ist das Vorgehen geregelt?" + A]),
 ("Bewertung der Leistung (Kap. 9)", [
  "Wird die Leistung überwacht und werden Kennzahlen und Ereignisse regelmäßig ausgewertet?",
  "Werden interne Audits gemäß Auditprogramm geplant, durchgeführt und dokumentiert?",
  "Fließen die Ergebnisse (Audits, Ereignisse, Behördenmeldungen, Kennzahlen) in die Managementbewertung ein?",
  "Werden alle Vorfälle und EBA-Meldungen vollständig, fristgerecht und nachvollziehbar erfasst (Ereignisregister mit Datum, Meldezeitpunkt, Aktenzeichen, Status) und auf Trends/Häufungen ausgewertet?" + A,
  "Stimmen die intern erfassten Ereignisse mit den tatsächlich an die Behörden (EBA / DÚ) gemeldeten Fällen überein – gibt es nicht oder verspätet gemeldete Ereignisse?" + A]),
 ("Verbesserung (Kap. 10)", [
  "Wie wird mit festgestellten Nichtkonformitäten umgegangen und wie werden Korrekturmaßnahmen festgelegt und dokumentiert?",
  "Wie wird die Wirksamkeit umgesetzter Korrektur- und Verbesserungsmaßnahmen überprüft?",
  "Werden zu den Vorfällen systematische Ursachenanalysen (z. B. 5-Why, Ishikawa, Human Factors) durchgeführt und die abgeleiteten Maßnahmen – einschließlich der Stellungnahmen/Rückmeldungen an das EBA – termingerecht nachverfolgt?" + A]),
 ("Rangierbetrieb & Sicherheit", [
  "Liegen gültige örtliche Richtlinien/Betriebsanweisungen vor und sind sie dem Personal bekannt?",
  "Wie wird vor jeder Rangierfahrt die Zustimmung des EIU (z. B. SŽ, Anschlussbahn)/des Fahrdienstleiters eingeholt und dokumentiert?",
  "Werden die vorgeschriebenen Bremsproben ordnungsgemäß durchgeführt und nachgewiesen?",
  "Wie ist das Sichern abgestellter Fahrzeuge gegen Entlaufen (Gefälle, Handbremsen, Hemmschuhe) geregelt und wird es eingehalten?"]),
 ("Fahrzeuge / ECM III", [
  "Sind die Instandhaltungsfristen (planmäßige IH) überwacht und werden Werkstattaufträge fristgerecht ausgelöst (ECM III)?",
  "Wie wird sichergestellt, dass ein Fahrzeug nach IH erst mit gültiger Wiederinbetriebnahmebescheinigung wieder eingesetzt wird?",
  "Werden Fahrzeugstörungen/Schäden zeitnah gemeldet, dokumentiert und in die außerplanmäßige IH überführt – und werden fahrzeugbedingte Vorfälle an die ECM zurückgemeldet?",
  "Wie werden tägliche/abfahrtbezogene Fahrzeugkontrollen vor dem Einsatz durchgeführt und nachgewiesen? (A1/V1)"]),
 ("Wagentechnik (Wagenmeister/WTU)", [
  "Werden wagentechnische Untersuchungen nach gültigem Regelwerk durchgeführt und dokumentiert?",
  "Wie werden Schadwagen erkannt, gekennzeichnet, behandelt und gemeldet?",
  "Wird die erforderliche Bremsstellung korrekt erstellt und geprüft?",
  "Wird bei Gefahrguttransporten (RID/GGVSEB) die wagentechnische und dokumentarische Kontrolle eingehalten und nachgewiesen?"]),
 ("SQAS", [
  "Werden die SQAS-Anforderungen (Sicherheit, Qualität, Umwelt bei Flüssigbrennstoffen/Gefahrgut) eingehalten und ist das Personal vertraut?",
  "Wie werden Vorfälle, Beinaheunfälle und Beanstandungen erfasst, ausgewertet und für Verbesserungen genutzt?"]),
 ("ISO 50001 Energiemanagement", [
  "Wird der Energie-/Dieselverbrauch erfasst und im Sinne des Energiemanagements (ISO 50001) ausgewertet?",
  "Welche Maßnahmen zur Senkung des Energieverbrauchs sind bekannt und umgesetzt?"]),
 ("IT-Sicherheit (ISMS/ISO 27001)", [
  "Werden die genutzten IT-Systeme und mobilen Geräte gemäß ISMS-Vorgaben sicher betrieben (Zugänge, Gerätesperrung, Updates)?",
  "Ist dem Personal bekannt, wie IT-Sicherheitsvorfälle zu melden sind, und werden die Meldewege eingehalten?"]),
 ("EfB (§ 56 KrWG)", [
  "Werden die EfB-Anforderungen bei der Beförderung abfallrelevanter Güter eingehalten und dokumentiert?",
  "Sind die erforderlichen Nachweise (Begleitpapiere, Nachweisverfahren) vorhanden und wird das Personal unterwiesen?"]),
 ("Arbeitsschutz", [
  "Liegen aktuelle Gefährdungsbeurteilungen vor und sind die Schutzmaßnahmen umgesetzt?",
  "Wird die PSA bereitgestellt, getragen und regelmäßig geprüft?",
  "Werden die arbeitsschutzbezogenen Unterweisungen (mind. jährlich + anlassbezogen, z. B. nach Vorfällen) durchgeführt und dokumentiert?",
  "Sind Erste-Hilfe-Organisation, Notfallausrüstung und Verhalten bei Arbeitsunfällen geregelt und bekannt?"]),
]
assert len(KAP) == 15

# ---------- 3. Übersichtstabelle ----------
W = [1.0, 7.4, 1.2, 1.2, 1.65, 1.65, 1.65, 1.65]
t = table(len(KAP) + 2, W)
for i, h in enumerate(["Nr.", "Kapitel/Themen", "ja", "nein", "0-50%", "50-70%", "70-85%", "85-100%"]):
    put(t.cell(0, i), h, bold=True, align=C); shade(t.cell(0, i), GREY)
repeat_header(t.rows[0])
for n, (name, _) in enumerate(KAP, 1):
    put(t.cell(n, 0), str(n), align=C); put(t.cell(n, 1), name)
    put(t.cell(n, 2), "☐", align=C); put(t.cell(n, 3), "☐", align=C)
    for j in range(4, 8): put(t.cell(n, j))
last = len(KAP) + 1
m = t.cell(last, 0).merge(t.cell(last, 3)); put(m, "Durchschnitt:", bold=True, align=WD_ALIGN_PARAGRAPH.RIGHT)
shade(m, LIGHT)
for j in range(4, 8): put(t.cell(last, j))
para(after=4)

# ---------- 4. Bewertungsskala ----------
para("Bewertungsskala:", bold=True, after=2, keep=True)
for color, txt in ((WD_COLOR_INDEX.YELLOW, "= Empfehlung – ohne zwingenden Charakter"),
                   (WD_COLOR_INDEX.RED, "= Schwachstelle – mit zwingendem Charakter")):
    p = para(after=1); r = p.add_run(" " * 8); r.font.highlight_color = color
    p.add_run("  " + txt)

# ---------- 5. Kapitel-Tabellen ----------
CW = [1.2, 7.3, 0.8, 0.8, 0.8, 0.8, 0.8, 3.4, 1.5]  # = 17.4
for k, (name, qs) in enumerate(KAP, 1):
    p = doc.add_paragraph(); p.add_run().add_break(WD_BREAK.PAGE) if k == 1 else None
    nrows = 3 + len(qs) + 5
    t = table(nrows, CW)
    # Kopf (2 Zeilen)
    a = t.cell(0, 0).merge(t.cell(1, 0)); put(a, "Nr.", bold=True, align=C)
    a = t.cell(0, 1).merge(t.cell(1, 1)); put(a, "Anforderung/Frage", bold=True)
    a = t.cell(0, 2).merge(t.cell(0, 6)); put(a, "Wirksamkeit (0/A/B/C/D)", bold=True, align=C, size=8)
    for j, l in enumerate("0ABCD"): put(t.cell(1, 2 + j), l, bold=True, align=C)
    a = t.cell(0, 7).merge(t.cell(1, 7)); put(a, "Bemerkungen", bold=True)
    a = t.cell(0, 8).merge(t.cell(1, 8)); put(a, "Anlage", bold=True, align=C, size=9)
    for rr in (0, 1):
        for c in t.rows[rr].cells: shade(c, GREY)
        repeat_header(t.rows[rr])
    a = t.cell(2, 0).merge(t.cell(2, 8)); put(a, f"Kapitel: {name}", bold=True); shade(a, LIGHT)
    for rr in (0, 1, 2):
        for c in t.rows[rr].cells:
            for pp in c.paragraphs: pp.paragraph_format.keep_with_next = True
    for i, q in enumerate(qs):
        r_ = 3 + i
        put(t.cell(r_, 0), f"{k}.{i+1}", align=C)
        if q.endswith(A):
            p = put(t.cell(r_, 1), q[:-len(A)]); rr = p.add_run(A); rr.italic = True; rr.bold = True
        else:
            put(t.cell(r_, 1), q)
        for j in range(2, 9): put(t.cell(r_, j))
        t.rows[r_].height = Cm(1.6); cant_split(t.rows[r_])
    g = 3 + len(qs)
    a = t.cell(g, 0).merge(t.cell(g, 8))
    put(a, "0 = entfällt   A = voll erfüllt   B = überwiegend erfüllt   C = teilweise erfüllt   D = nicht erfüllt", size=8)
    shade(a, LIGHT)
    labels = ["Summe der Antworten", "Gewichtung", "Erreichte Punktzahl", "Erreichter Prozentwert"]
    for i, lab in enumerate(labels):
        rr = g + 1 + i
        a = t.cell(rr, 0).merge(t.cell(rr, 1)); put(a, lab, bold=True)
        for j in range(2, 9): put(t.cell(rr, j), align=C)
        if lab == "Summe der Antworten":
            put(t.cell(rr, 7), "Gesamt:", size=8)
        if lab == "Gewichtung":
            put(t.cell(rr, 2), "–", align=C, size=8)
            for j, wv in enumerate(["1,0", "0,8", "0,5", "0,0"]): put(t.cell(rr, 3 + j), wv, align=C, size=8)
        if lab == "Erreichte Punktzahl":
            put(t.cell(rr, 7), "Summe:", size=8)
        if lab == "Erreichter Prozentwert":
            m = t.cell(rr, 2).merge(t.cell(rr, 6)); put(m)
            put(t.cell(rr, 7), "%:", size=8)
    for rr in range(g, nrows):
        cant_split(t.rows[rr])
        # Raster zusammenhalten
        for p in t.rows[rr].cells[0].paragraphs: p.paragraph_format.keep_with_next = True
    para(after=4)

# ---------- 6. Abschließende Auswertung ----------
para("Abschließende Auswertung über die Erfüllung der Norm:", bold=True, size=11, before=8, after=4, keep=True)
t = table(1, [17.4]); put(t.cell(0, 0)); t.rows[0].height = Cm(6)
t.cell(0, 0).vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.TOP

# ---------- 7. Empfehlung (Seitenumbruch davor) ----------
p = doc.add_paragraph(); p.add_run().add_break(WD_BREAK.PAGE)
p.paragraph_format.space_after = Pt(0)
para("Empfehlung aus der Auswertung zur Norm Erfüllung", bold=True, size=11, after=4, keep=True)
t = table(3, [17.4])
put(t.cell(0, 0)); t.rows[0].height = Cm(6); t.cell(0, 0).vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.TOP
p = put(t.cell(1, 0)); r = p.add_run("Termin für Nachprüfung: "); r.bold = True; p.add_run("nächster Auditzyklus")
p = put(t.cell(2, 0)); r = p.add_run("Nachaudit erforderlich?   "); r.bold = True; p.add_run("☐ ja     ☐ nein")
for row in t.rows: cant_split(row)
para(after=10)

# ---------- 8. Unterschriften ----------
para("Überprüfung / zur Kenntnis genommen:", bold=True, size=11, after=4, keep=True)
sig = ["Datum / Unterschrift Auditor", "Datum / Unterschrift QMB (falls abweichend)",
       "Datum / Unterschrift EBL", "Kenntnisnahme des Auditierten (Datum / Unterschrift)"]
t = table(len(sig), [7.0, 10.4])
for i, s in enumerate(sig):
    put(t.cell(i, 0), s, bold=True); shade(t.cell(i, 0), LIGHT); put(t.cell(i, 1))
    t.rows[i].height = Cm(1.4); cant_split(t.rows[i])

# ---------- 9. Anlagen ----------
p = doc.add_paragraph(); p.add_run().add_break(WD_BREAK.PAGE)
doc.add_heading("Anlage 1. Management Summary", level=1)
doc.add_paragraph()
p = doc.add_paragraph(); p.add_run().add_break(WD_BREAK.PAGE)
doc.add_heading("Anlage 2. Team Summary", level=1)
doc.add_paragraph()

doc.core_properties.title = "Protokoll interner Audits – " + THEMA
doc.core_properties.author = "Enrico Görzen (QMB)"
doc.save(OUT)
print("ok", OUT)
