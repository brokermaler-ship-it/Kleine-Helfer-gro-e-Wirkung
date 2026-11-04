import sys
from docx import Document
from docx.shared import Pt, Cm, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK, WD_COLOR_INDEX
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

OUT = sys.argv[1]
ORT = "Pirna"
THEMA = "Standort Pirna"
TAET = "Eisenbahnbetriebs- und Rangierleistungen"

doc = Document()
sec = doc.sections[0]
sec.page_height, sec.page_width = Cm(29.7), Cm(21)
sec.left_margin = sec.right_margin = Cm(1.8)
sec.top_margin = sec.bottom_margin = Cm(1.8)
st = doc.styles["Normal"]; st.font.name = "Arial"; st.font.size = Pt(10)
st.element.rPr.rFonts.set(qn("w:eastAsia"), "Arial")
try:
    ti = doc.styles.add_style("Tabelleninhalt", 1); ti.base_style = st
except Exception:
    ti = doc.styles["Tabelleninhalt"]
for h in ("Heading 1", "Heading 2"):
    s = doc.styles[h]; s.font.name = "Arial"; s.font.color.rgb = RGBColor(0, 0, 0)
    s.font.size = Pt(14 if h == "Heading 1" else 12)

hdr = sec.header.paragraphs[0]
hdr.text = "Protokoll interner Audits – " + THEMA; hdr.runs[0].font.size = Pt(8)

def shade(cell, color):
    tcPr = cell._tc.get_or_add_tcPr(); s = OxmlElement("w:shd")
    s.set(qn("w:val"), "clear"); s.set(qn("w:color"), "auto"); s.set(qn("w:fill"), color); tcPr.append(s)

def put(cell, text, bold=False, size=10, align=None):
    p = cell.paragraphs[0]; p.style = ti
    r = p.add_run(text); r.bold = bold; r.font.size = Pt(size)
    if align: p.alignment = align
    return p

def table(rows, cols, widths=None):
    t = doc.add_table(rows=rows, cols=cols); t.style = "Table Grid"; t.alignment = WD_TABLE_ALIGNMENT.CENTER
    if widths:
        t.autofit = False
        tblPr = t._tbl.tblPr
        lay = OxmlElement("w:tblLayout"); lay.set(qn("w:type"), "fixed"); tblPr.append(lay)
        for gc, w in zip(t._tbl.tblGrid.findall(qn("w:gridCol")), widths):
            gc.set(qn("w:w"), str(int(w * 567)))
        for row in t.rows:
            for i, w in enumerate(widths): row.cells[i].width = Cm(w)
    return t

def repeat_header(t, n):
    for i in range(n):
        trPr = t.rows[i]._tr.get_or_add_trPr(); h = OxmlElement("w:tblHeader"); h.set(qn("w:val"), "true"); trPr.append(h)

C = WD_ALIGN_PARAGRAPH.CENTER

# Titel
p = doc.add_paragraph(); r = p.add_run("Protokoll interner Audits"); r.bold = True; r.font.size = Pt(16); p.alignment = C

# 1 Auditdurchführung
doc.add_heading("Auditdurchführung", 2)
meta = [("Datum:", "09.11.2026"), ("Beginn:", ""), ("Ende:", "(geplante Dauer: 4 Stunden)"),
        ("Ort(e) der Durchführung:", ORT), ("Auditor(en):", "Lena"), ("Teilnehmer:", "Standortleiter " + ORT)]
t = table(len(meta), 2, [5, 12.4])
for i, (k, v) in enumerate(meta):
    put(t.cell(i, 0), k, bold=True); shade(t.cell(i, 0), "D9D9D9"); put(t.cell(i, 1), v)

# 2 Auditbericht
doc.add_heading("Auditbericht", 2)
p = doc.add_paragraph(); p.add_run("Abteilung: ").bold = True; p.add_run(THEMA)
p = doc.add_paragraph(); p.add_run("Abweichungen:  ").bold = True
p.add_run("☐ keine     ☐ geringfügig     ☐ schwerwiegend")
p = doc.add_paragraph(); p.add_run("Folgende Maßnahmen wurden festgestellt:").bold = True
t = table(1, 1, [17.4]); put(t.cell(0, 0), ""); t.rows[0].height = Cm(4)

KAP = [
 ("Kap. 4 – Kontext der Organisation", [
  f"Sind die für den Standort {ORT} relevanten internen und externen Themen sowie die interessierten Parteien (Auftraggeber, Behörden, Schnittstellenpartner) bekannt und berücksichtigt?",
  f"Ist der Geltungsbereich der Tätigkeiten am Standort ({TAET}) klar abgegrenzt und sind die zugehörigen Prozesse beschrieben?"]),
 ("Kap. 5 – Führung", [
  "Sind Rollen, Verantwortlichkeiten und Befugnisse am Standort eindeutig festgelegt und im Organigramm bzw. in Funktionsbeschreibungen dokumentiert?",
  "Wie stellt die Standortleitung sicher, dass die Sicherheits- und Qualitätspolitik der HSL am Standort bekannt ist und vorgelebt wird?",
  "Wie wird eine positive Sicherheitskultur (offene Fehler-/Meldekultur, anonymes Meldewesen) gefördert?"]),
 ("Kap. 6 – Planung", [
  "Werden standortbezogene Risiken und Chancen systematisch bewertet und in der Risikotabelle dokumentiert?",
  "Wie werden Änderungen am Standort auf Risiken beurteilt und dokumentiert (CSM-RA, VO 402/2013)?",
  "Sind messbare Sicherheits- und Qualitätsziele festgelegt und wird deren Erreichung verfolgt?"]),
 ("Kap. 7 – Unterstützung", [
  "Sind die erforderlichen dokumentierten Informationen (VA, AA, DA, Checklisten) in gültiger Fassung über ONTIV verfügbar und gelenkt?",
  "Wie wird sichergestellt, dass die Mitarbeitenden die erforderlichen Qualifikationen besitzen und diese in LP One nachgewiesen sind?",
  "Sind ausreichende Ressourcen (Personal, Fahrzeuge, PSA, Funk/GSM-R) verfügbar?",
  "Wie werden sicherheitsrelevante Informationen kommuniziert (Schichtübergabe, Sicherheitsinfos, Newsletter)?"]),
 ("Kap. 8 – Betrieb", [
  "Werden die operativen Tätigkeiten gemäß festgelegten Vorgaben (örtliche Richtlinien, Betriebsanweisungen) geplant, gesteuert und dokumentiert?",
  "Wie wird im Tagesbetrieb die Einhaltung gesetzlicher/interner Vorgaben (Arbeits- und Ruhezeiten, Regelwerke) überwacht?",
  "Wie ist das Notfall- und Störungsmanagement geregelt und ist es dem Personal bekannt?",
  "Wie werden ausgelagerte/zugekaufte Leistungen gesteuert und deren Qualität überwacht?"]),
 ("Kap. 9 – Bewertung der Leistung", [
  "Wird die Leistung überwacht und werden Kennzahlen und Ereignisse regelmäßig ausgewertet?",
  "Werden interne Audits gemäß Auditprogramm geplant, durchgeführt und dokumentiert?",
  "Fließen die Ergebnisse (Audits, Ereignisse, Kennzahlen) in die Managementbewertung ein?"]),
 ("Kap. 10 – Verbesserung", [
  "Wie wird mit festgestellten Nichtkonformitäten umgegangen und wie werden Korrekturmaßnahmen festgelegt und dokumentiert?",
  "Wie wird die Wirksamkeit umgesetzter Korrektur- und Verbesserungsmaßnahmen überprüft?"]),
 ("Operativ – Rangierbetrieb & Sicherheit", [
  "Liegen gültige örtliche Richtlinien/Betriebsanweisungen vor und sind sie dem Personal bekannt?",
  "Wie wird vor jeder Rangierfahrt die Zustimmung des zuständigen EIU/Fahrdienstleiters eingeholt und dokumentiert?",
  "Werden die vorgeschriebenen Bremsproben ordnungsgemäß durchgeführt und nachgewiesen?",
  "Wie ist das Sichern abgestellter Fahrzeuge gegen Entlaufen (Gefälle, Handbremsen, Hemmschuhe) geregelt und wird es eingehalten?"]),
 ("Operativ – Fahrzeuge / ECM III", [
  "Sind die Instandhaltungsfristen (planmäßige IH) überwacht und werden Werkstattaufträge fristgerecht ausgelöst (ECM III)?",
  "Wie wird sichergestellt, dass ein Fahrzeug nach IH erst mit gültiger Wiederinbetriebnahmebescheinigung wieder eingesetzt wird?",
  "Werden Fahrzeugstörungen/Schäden zeitnah gemeldet, dokumentiert und in die außerplanmäßige IH überführt?",
  "Wie werden tägliche/abfahrtbezogene Fahrzeugkontrollen vor dem Einsatz durchgeführt und nachgewiesen? (A1/V1)"]),
 ("Operativ – Wagentechnik (Wagenmeister/WTU)", [
  "Werden wagentechnische Untersuchungen nach gültigem Regelwerk durchgeführt und dokumentiert?",
  "Wie werden Schadwagen erkannt, gekennzeichnet, behandelt und gemeldet?",
  "Wird die erforderliche Bremsstellung korrekt erstellt und geprüft?",
  "Wird bei Gefahrguttransporten (RID/GGVSEB) die wagentechnische und dokumentarische Kontrolle eingehalten und nachgewiesen?"]),
 ("Operativ – SQAS", [
  "Werden die SQAS-Anforderungen (Sicherheit, Qualität, Umwelt bei Flüssigbrennstoffen/Gefahrgut) eingehalten und ist das Personal vertraut?",
  "Wie werden Vorfälle, Beinaheunfälle und Beanstandungen erfasst, ausgewertet und für Verbesserungen genutzt?"]),
 ("Operativ – ISO 50001 Energiemanagement", [
  "Wird der Energie-/Dieselverbrauch erfasst und im Sinne des Energiemanagements (ISO 50001) ausgewertet?",
  "Welche Maßnahmen zur Senkung des Energieverbrauchs sind bekannt und umgesetzt?"]),
 ("Operativ – IT-Sicherheit (ISMS/ISO 27001)", [
  "Werden die genutzten IT-Systeme und mobilen Geräte gemäß ISMS-Vorgaben sicher betrieben (Zugänge, Gerätesperrung, Updates)?",
  "Ist dem Personal bekannt, wie IT-Sicherheitsvorfälle zu melden sind, und werden die Meldewege eingehalten?"]),
 ("Operativ – EfB (§ 56 KrWG)", [
  "Werden die EfB-Anforderungen bei der Beförderung abfallrelevanter Güter eingehalten und dokumentiert?",
  "Sind die erforderlichen Nachweise (Begleitpapiere, Nachweisverfahren) vorhanden und wird das Personal unterwiesen?"]),
 ("Operativ – Arbeitsschutz", [
  "Liegen aktuelle Gefährdungsbeurteilungen vor und sind die Schutzmaßnahmen umgesetzt?",
  "Wird die PSA bereitgestellt, getragen und regelmäßig geprüft?",
  "Werden die arbeitsschutzbezogenen Unterweisungen (mind. jährlich + anlassbezogen) durchgeführt und dokumentiert?",
  "Sind Erste-Hilfe-Organisation, Notfallausrüstung und Verhalten bei Arbeitsunfällen geregelt und bekannt?"]),
]

# 3 Übersicht
doc.add_heading("Übersicht", 2)
heads = ["Nr.", "Kapitel/Themen", "ja", "nein", "0-50%", "50-70%", "70-85%", "85-100%"]
W = [1.0, 7.4, 1.2, 1.2, 1.65, 1.65, 1.65, 1.65]
t = table(len(KAP) + 2, 8, W)
repeat_header(t, 1)
for i, h in enumerate(heads):
    put(t.cell(0, i), h, bold=True, align=C); shade(t.cell(0, i), "D9D9D9")
for n, (name, _) in enumerate(KAP, 1):
    put(t.cell(n, 0), str(n), align=C); put(t.cell(n, 1), name)
    put(t.cell(n, 2), "☐", align=C); put(t.cell(n, 3), "☐", align=C)
last = len(KAP) + 1
m = t.cell(last, 0).merge(t.cell(last, 3)); put(m, "Durchschnitt:", bold=True)
shade(m, "F2F2F2")

# 4 Bewertungsskala
p = doc.add_paragraph(); p.paragraph_format.space_before = Pt(10); p.add_run("Bewertungsskala:").bold = True
for col, txt in ((WD_COLOR_INDEX.YELLOW, "= Empfehlung – ohne zwingenden Charakter"),
                 (WD_COLOR_INDEX.RED, "= Schwachstelle – mit zwingendem Charakter")):
    p = doc.add_paragraph(); r = p.add_run(" " * 8); r.font.highlight_color = col; p.add_run("  " + txt)

# 5 Kapiteltabellen
KW = [1.2, 6.8, 0.8, 0.8, 0.8, 0.8, 0.8, 3.8, 1.6]
for n, (name, qs) in enumerate(KAP, 1):
    doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE) if n == 1 else doc.add_paragraph()
    rows = 3 + len(qs) + 5
    t = table(rows, 9, KW); repeat_header(t, 2)
    # Kopf
    put(t.cell(0, 0), "Nr.", True, align=C); put(t.cell(0, 1), "Anforderung/Frage", True)
    w = t.cell(0, 2).merge(t.cell(0, 6)); put(w, "Wirksamkeit", True, align=C)
    put(t.cell(0, 7), "Bemerkungen", True); put(t.cell(0, 8), "Anlage", True)
    put(t.cell(1, 0), ""); put(t.cell(1, 1), "")
    for i, s in enumerate("0ABCD"): put(t.cell(1, 2 + i), s, True, align=C)
    put(t.cell(1, 7), ""); put(t.cell(1, 8), "")
    for c in range(9):
        shade(t.cell(0, c), "D9D9D9"); shade(t.cell(1, c), "D9D9D9")
    k = t.cell(2, 0).merge(t.cell(2, 8)); put(k, "Kapitel: " + name, True); shade(k, "F2F2F2")
    for j, q in enumerate(qs):
        r = 3 + j
        put(t.cell(r, 0), f"{n}.{j+1}", align=C); put(t.cell(r, 1), q)
        for c in range(2, 9): put(t.cell(r, c), "")
    b = 3 + len(qs)
    lg = t.cell(b, 0).merge(t.cell(b, 8))
    put(lg, "0 = entfällt   A = voll erfüllt   B = überwiegend erfüllt   C = teilweise erfüllt   D = nicht erfüllt", size=8)
    shade(lg, "F2F2F2")
    labels = ["Summe der Antworten", "Gewichtung", "Erreichte Punktzahl", "Erreichter Prozentwert"]
    for i, lab in enumerate(labels):
        r = b + 1 + i
        l = t.cell(r, 0).merge(t.cell(r, 1)); put(l, lab, True)
        for c in range(2, 9): put(t.cell(r, c), "")
        if lab == "Gewichtung":
            for c, v in zip(range(3, 7), ("1,0", "0,8", "0,5", "0,0")):
                t.cell(r, c).paragraphs[0].runs[0].text = v
                t.cell(r, c).paragraphs[0].alignment = C
                t.cell(r, c).paragraphs[0].runs[0].font.size = Pt(8)
    for row in t.rows[:-1]:
        trPr = row._tr.get_or_add_trPr(); cs = OxmlElement("w:cantSplit"); trPr.append(cs)
        for cell in row.cells:
            for p in cell.paragraphs: p.paragraph_format.keep_with_next = True

# 6 Abschließende Auswertung
doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)
doc.add_heading("Abschließende Auswertung über die Erfüllung der Norm:", 2)
t = table(1, 1, [17.4]); put(t.cell(0, 0), ""); t.rows[0].height = Cm(6)

# 7 Empfehlung (Seitenumbruch davor -> gemeinsam auf einer Seite)
doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)
doc.add_heading("Empfehlung aus der Auswertung zur Norm Erfüllung", 2)
t = table(1, 1, [17.4]); put(t.cell(0, 0), ""); t.rows[0].height = Cm(6)
p = doc.add_paragraph(); p.paragraph_format.space_before = Pt(10)
p.add_run("Termin für Nachprüfung: ").bold = True; p.add_run("nächster Auditzyklus")
p = doc.add_paragraph(); p.add_run("Nachaudit erforderlich?  ").bold = True; p.add_run("☐ ja     ☐ nein")

# 8 Unterschriften
p = doc.add_paragraph(); p.paragraph_format.space_before = Pt(18)
p.add_run("Überprüfung / zur Kenntnis genommen:").bold = True
sig = ["Datum / Unterschrift Auditor", "Datum / Unterschrift QMB (falls abweichend)",
       "Datum / Unterschrift EBL", "Kenntnisnahme des Auditierten (Datum / Unterschrift)"]
t = table(len(sig), 2, [7.4, 10])
for i, s in enumerate(sig):
    put(t.cell(i, 0), s, True); put(t.cell(i, 1), ""); t.rows[i].height = Cm(1.4)

# 9 Anlagen
doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)
doc.add_heading("Anlage 1. Management Summary", 2)
doc.add_paragraph()
doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)
doc.add_heading("Anlage 2. Team Summary", 2)
doc.add_paragraph()
doc.save(OUT)
print("ok", OUT)
