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
meta = [("Datum:", ""), ("Beginn:", "10:00 Uhr"), ("Ende:", "14:00 Uhr (geplant)"), ("Geplante Dauer:", "4 Stunden, 10:00–14:00 Uhr (inkl. 15 min Pause) – Ablauf siehe Auditplan"), ("Ort(e) der Durchführung:", "Pirna"),
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
  "Wie werden ausgelagerte/zugekaufte Leistungen gesteuert und deren Qualität überwacht?"]),
 ("Bewertung der Leistung (Kap. 9)", [
  "Wird die Leistung überwacht und werden Kennzahlen und Ereignisse regelmäßig ausgewertet?",
  "Werden interne Audits gemäß Auditprogramm geplant, durchgeführt und dokumentiert?",
  "Fließen die Ergebnisse (Audits, Ereignisse, Behördenmeldungen, Kennzahlen) in die Managementbewertung ein?"]),
 ("Verbesserung (Kap. 10)", [
  "Wie wird mit festgestellten Nichtkonformitäten umgegangen und wie werden Korrekturmaßnahmen festgelegt und dokumentiert?",
  "Wie wird die Wirksamkeit umgesetzter Korrektur- und Verbesserungsmaßnahmen überprüft?",
  "Ist der Umgang mit Vorfällen und Meldungen im Detail in Kapitel 16 bewertet (Verweis)?"]),
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
 ("SQAS (Rail) – Sicherheit, Qualität, Umwelt, Security", [
  "Werden die SQAS-Anforderungen (Sicherheit, Qualität, Umwelt bei Flüssigbrennstoffen/Gefahrgut) eingehalten und ist das Personal damit vertraut?",
  "Sind die Feststellungen/Verbesserungsbereiche aus dem letzten SQAS-Rail-Assessment in einem Maßnahmenplan erfasst und termingerecht abgearbeitet?",
  "Liegen für Gefahrguttransporte Risikobewertungen vor (Strecken, Abstellorte, Grenzübergang CZ/DE, Übergabe an Lade-/Entladestellen) und sind die Schutzmaßnahmen umgesetzt?",
  "Ist ein Gefahrgutbeauftragter benannt, liegt der Jahresbericht vor und ist das Personal nach RID 1.3 unterwiesen (Nachweis)?",
  "Ist ein Sicherungsplan für Güter mit hohem Gefahrenpotenzial (RID 1.10) vorhanden, dem Personal bekannt und wird er umgesetzt (Security)?",
  "Werden Subunternehmer/Personaldienstleister nach SQAS-Kriterien ausgewählt, unterwiesen und regelmäßig bewertet?",
  "Sind Notfallpläne für Gefahrgutereignisse (Austritt, Brand) vorhanden, mit Kunden/EIU/Feuerwehr abgestimmt, und werden Notfallübungen durchgeführt und ausgewertet?",
  "Werden Gefahrgutereignisse erkannt und gemäß RID 1.8.5 fristgerecht gemeldet und dokumentiert?",
  "Wie werden Vorfälle, Beinaheunfälle und Beanstandungen erfasst, ausgewertet und für Verbesserungen genutzt – und werden die betroffenen Kunden informiert?",
  "Werden Kundenbeschwerden und kundenspezifische Vorgaben systematisch erfasst, beantwortet und nachverfolgt?",
  "Werden SQAS-relevante Kennzahlen (z. B. Ereignisse, Unfälle mit Ausfallzeit, Umweltereignisse, Pünktlichkeit) erhoben und an Management/Kunden berichtet?",
  "Sind Maßnahmen gegen Boden-/Gewässerverunreinigung (Ölbindemittel, Leckage-Kits, Auffangwannen) vorhanden und dem Personal bekannt?"]),
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
 ("Ereignis- und Meldemanagement / Top-Ereignisse" + A, [
  "Welche Meldewege stehen dem Personal für Vorfälle, Beinaheunfälle und Störungen zur Verfügung (Leitstelle, Meldeformular, App, anonym) und sind sie bekannt (Stichprobe: Befragung Personal CZ und DE)?",
  "Ist die Meldekette bei gefährlichen Ereignissen (Triebfahrzeugführer → Fahrdienstleiter/Notfallmanager → Leitstelle → Sicherheitsmanagement → EBA bzw. Untersuchungsstelle in DE / Drážní úřad bzw. Drážní inspekce in CZ) mit Fristen und Zuständigkeiten festgelegt und dem Personal bekannt?",
  "Wie werden eingehende Meldungen klassifiziert (meldepflichtig gegenüber EBA/Untersuchungsstelle bzw. DÚ/DI, Gefahrgut nach RID 1.8.5, nur intern) – wer entscheidet und ist die Entscheidung nachvollziehbar dokumentiert?",
  "Werden die Meldefristen eingehalten? (Stichprobe EBA-Meldungen: Zeitpunkt Ereignis → Zeitpunkt Meldung → Aktenzeichen)",
  "Werden Beweise und Daten nach einem Vorfall gesichert (Registriereinrichtung, Fotos, Zeugenaussagen, Dokumente) und ist das Vorgehen geregelt?",
  "Werden alle Vorfälle und EBA-Meldungen vollständig und nachvollziehbar im Ereignisregister erfasst (Datum, Ort, Ereignisart, Meldezeitpunkt, Aktenzeichen, Status)?",
  "Stimmen die intern erfassten Ereignisse mit den tatsächlich an die Behörden (EBA / DÚ) gemeldeten Fällen überein – gibt es nicht oder verspätet gemeldete Ereignisse?",
  "Werden zu den Vorfällen systematische Ursachenanalysen (z. B. 5-Why, Ishikawa, Human Factors) durchgeführt und die Maßnahmen – einschließlich der Stellungnahmen an das EBA – termingerecht nachverfolgt?",
  "Erhält der Melder eine Rückmeldung und werden Erkenntnisse (Lessons Learned) an das Personal in CZ und DE kommuniziert?",
  ("Werden aus dem Ereignisregister die Top-Ereignisse nach Häufigkeit und Schwere ermittelt und priorisiert?", "Anl. 3"),
  ("Wurde für die Top-Ereignisse eine Bow-Tie-Analyse durchgeführt (Ursachen, präventive/mitigierende Barrieren, Eskalationsfaktoren) und ist dokumentiert, welche Barrieren bei den Vorfällen versagt haben?", "Anl. 3"),
  ("Fließen die Ergebnisse der Bow-Tie-Analyse in Risikotabelle, Maßnahmenplan und Managementbewertung ein?", "Anl. 3")]),
]
assert len(KAP) == 16

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


# ---------- Auditplan (4 h) ----------
p = para("Auditplan – Pirna, 10:00–14:00 Uhr (4 Stunden)", bold=True, size=12, after=2, keep=True)
p.paragraph_format.page_break_before = True
small_ = lambda txt, it=False: (lambda p_: ([setattr(r_.font, "size", Pt(9)) or setattr(r_, "italic", it) for r_ in p_.runs], p_.paragraph_format.__setattr__("space_after", Pt(4))))(doc.add_paragraph(txt))
small_("Schwerpunkt anlassbezogen: Meldewesen/EBA-Meldungen, Top-Ereignisse und Bow-Tie, SQAS. Die übrigen Kapitel werden als Kurzcheck "
       "mit Bezug zu den Vorfällen geprüft; nicht zutreffende Kapitel mit „0 = entfällt“ bewerten.", True)
PW = [2.0, 1.2, 7.3, 2.4, 3.0, 1.5]
PLAN = [
 ("10:00–10:15", "15", "Eröffnung: Anlass, Ziel, Ablauf, Vorstellung, Vertraulichkeit/Just Culture", "–", "alle"),
 ("10:15–10:30", "15", "Kurzcheck Managementsystem: Kontext, Rollen, Risikotabelle, Dokumentenlenkung", "Nr. 1–4", "Standortleitung, SMS"),
 ("10:30–11:15", "45", "Meldewesen: Meldewege, Meldekette CZ/DE, Klassifizierung, Meldefristen, Beweissicherung – Stichprobe EBA-/DÚ-Meldungen", "Nr. 16.1–16.5", "SMS, Leitstelle, Tf-Vertreter"),
 ("11:15–11:45", "30", "Ereignisregister, Abgleich mit Behördenmeldungen, Ursachenanalysen, Lessons Learned; Top-Ereignisse ziehen und ranken", "Nr. 16.6–16.10, Anl. 3.1", "SMS, Standortleitung"),
 ("11:45–12:00", "15", "Pause", "", ""),
 ("12:00–12:45", "45", "Bow-Tie-Workshop Top-Ereignis 1 (Top-Ereignis 2/3 bei Zeitreserve, sonst Nacharbeit mit Termin)", "Nr. 16.11–16.12, Anl. 3.3 ff.", "SMS, Fachverantwortliche, ECM, Gb"),
 ("12:45–13:15", "30", "SQAS: Assessment-Maßnahmen, Gefahrgut (RID 1.3/1.8.5/1.10), Notfallpläne/-übungen, Kennzahlen, Umwelt", "Nr. 11", "Gefahrgutbeauftragter, QM"),
 ("13:15–13:35", "20", "Betrieb und Operativ mit Bezug zu den Vorfällen: Betrieb, Leistung, Verbesserung, Rangieren, Fahrzeuge/ECM, Wagentechnik, Arbeitsschutz", "Nr. 5–10, 15", "Standortleitung, ECM, Wagenmeister"),
 ("13:35–13:45", "10", "Kurzcheck ISO 50001, IT-Sicherheit, EfB (ggf. „entfällt“)", "Nr. 12–14", "Standortleitung"),
 ("13:45–14:00", "15", "Abschlussgespräch: erste Feststellungen, Empfehlungen/Schwachstellen, Maßnahmen und Termine, Nachaudit?", "Auditbericht", "alle"),
]
t = table(len(PLAN) + 1, PW)
for i, h in enumerate(["Uhrzeit (Plan)", "Min.", "Inhalt", "Nr. / Fragen", "Gesprächspartner", "Uhrzeit Ist"]):
    put(t.cell(0, i), h, bold=True, align=C, size=8); shade(t.cell(0, i), GREY)
repeat_header(t.rows[0])
for n, (z, m_, inh, kap, wer) in enumerate(PLAN, 1):
    put(t.cell(n, 0), z, align=C, size=9); put(t.cell(n, 1), m_, align=C, size=9)
    put(t.cell(n, 2), inh, size=9, bold=(inh == "Pause")); put(t.cell(n, 3), kap, size=8); put(t.cell(n, 4), wer, size=8); put(t.cell(n, 5))
    if inh == "Pause":
        for c in t.rows[n].cells: shade(c, LIGHT)
    cant_split(t.rows[n])
para(after=2)
small_("SMS = Sicherheitsmanagement · Gb = Gefahrgutbeauftragter · ECM = für die Instandhaltung zuständige Stelle · Tf = Triebfahrzeugführer", True)
para("Vorab anfordern (mind. 5 Arbeitstage vor dem Audit), damit die 4 h für Stichproben und Workshop reichen:", bold=True, after=2, keep=True)
for x in ["Ereignisregister der letzten 12 Monate (inkl. Beinaheunfälle)",
          "Liste der EBA-/DÚ-Meldungen mit Ereignis- und Meldezeitpunkt sowie Aktenzeichen; Schriftverkehr/Stellungnahmen an das EBA",
          "Ursachenanalysen und Maßnahmenpläne zu den Vorfällen",
          "Melde- und Notfallregelungen (VA/AA Ereignismeldung, Meldekette CZ/DE, Notfallpläne Gefahrgut)",
          "SQAS-Assessmentbericht und zugehöriger Maßnahmenplan; Jahresbericht Gefahrgutbeauftragter; Sicherungsplan RID 1.10",
          "Aktuelle Risikotabelle sowie Unterweisungsnachweise (LP One) zu Meldewesen und Gefahrgut"]:
    p_ = doc.add_paragraph("☐  " + x); p_.runs[0].font.size = Pt(9); p_.paragraph_format.space_after = Pt(1)
    p_.paragraph_format.left_indent = Cm(0.4)

# ---------- 5. Kapitel-Tabellen ----------
CW = [1.35, 7.15, 0.8, 0.8, 0.8, 0.8, 0.8, 3.4, 1.5]  # = 17.4
for k, (name, qs) in enumerate(KAP, 1):
    p = doc.add_paragraph()
    if k == 1: p.paragraph_format.page_break_before = True
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
        anl = ""
        if isinstance(q, tuple): q, anl = q
        if q.endswith(A):
            p = put(t.cell(r_, 1), q[:-len(A)]); rr = p.add_run(A); rr.italic = True; rr.bold = True
        else:
            put(t.cell(r_, 1), q)
        for j in range(2, 8): put(t.cell(r_, j))
        put(t.cell(r_, 8), anl, align=C, size=8)
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
# ---------- Anlage 3: Top-Ereignisse und Bow-Tie-Analyse ----------
import os
from docx.shared import RGBColor as _RGB
BLUE_L, RED_L, DARKRED, GOLD_L = "DCE8F7", "F9D9D2", "C00000", "FFF4CC"
def small(text, bold=False, italic=False, after=2):
    p = doc.add_paragraph(); r = p.add_run(text); r.font.size = Pt(9); r.bold = bold; r.italic = italic
    p.paragraph_format.space_after = Pt(after); return p

p = doc.add_paragraph(); p.add_run().add_break(WD_BREAK.PAGE)
doc.add_heading("Anlage 3. Top-Ereignisse und Bow-Tie-Analyse", level=1)
para("Vorgehen im Audit:", bold=True, after=2)
for st_ in ["1. Ereignisregister und EBA-/DÚ-Meldungen des Auswertungszeitraums sichten (Stichprobe gegen Meldeliste).",
            "2. Ereignisse nach Ereignisart zusammenfassen, Schwere (S) und Häufigkeit (H) bewerten, Risiko = S × H.",
            "3. Die Top-Ereignisse (höchstes Risiko, mind. die Top 3) in Tabelle 3.1 eintragen.",
            "4. Für jedes Top-Ereignis gemeinsam mit den Fachverantwortlichen eine Bow-Tie-Analyse (3.3 ff.) erstellen.",
            "5. Bei den realen Vorfällen prüfen, welche Barrieren versagt haben, und Maßnahmen ableiten (→ Kap. 16, Maßnahmenplan, Risikotabelle)."]:
    small(st_, after=1)
para(after=4)

doc.add_heading("3.1 Top-Ereignisse aus dem Ereignisregister", level=2)
t = table(1, [17.4]); p = put(t.cell(0, 0)); r = p.add_run("Auswertungszeitraum:  "); r.bold = True
p.add_run("von ____________  bis ____________      Quelle: Ereignisregister / Meldeliste EBA / DÚ")
para(after=3)
W3 = [1.3, 5.3, 1.5, 2.2, 1.8, 1.8, 1.7, 1.8]
t = table(7, W3)
for i, h in enumerate(["Rang", "Ereignis / Ereignisart", "Anzahl", "davon gemeldet (EBA / DÚ)",
                       "Schwere S (1–5)", "Häufigk. H (1–5)", "Risiko S × H", "Bow-Tie"]):
    put(t.cell(0, i), h, bold=True, align=C, size=8); shade(t.cell(0, i), GREY)
for n in range(1, 7):
    put(t.cell(n, 0), str(n), align=C)
    for j in range(1, 7): put(t.cell(n, j), align=C)
    put(t.cell(n, 7), "☐", align=C); t.rows[n].height = Cm(0.9)
para(after=2)
small("Bewertung (Vorschlag – an die HSL-Risikomatrix anpassen):", bold=True, after=1)
small("S: 1 = unbedeutend (kein Schaden) · 2 = gering · 3 = mittel (Sachschaden, Betriebsstörung) · 4 = schwer (Verletzte, Gefahrgutaustritt) · 5 = katastrophal (Tote, großer Umweltschaden)", after=1)
small("H: 1 = einmalig · 2 = selten (1×/Jahr) · 3 = gelegentlich (mehrmals/Jahr) · 4 = häufig (monatlich) · 5 = sehr häufig (mehrmals/Monat)", after=1)
small("Risiko ≥ 15: Bow-Tie zwingend · 8–14: Bow-Tie empfohlen · < 8: Beobachtung über Ereignisregister", after=3)
small("Auswahlhilfe typische Top-Ereignisse (nicht abschließend): unzulässige Vorbeifahrt an Halt zeigendem Signal (SPAD) · "
      "Entgleisung · Zusammenprall/Flankenfahrt · Entlaufen abgestellter Fahrzeuge · Austritt von Gefahrgut · "
      "Überschreitung der zulässigen Geschwindigkeit · fehlerhafte/unterlassene Bremsprobe · Personenunfall im Gleisbereich · Brand am Fahrzeug.",
      italic=True, after=6)

doc.add_heading("3.2 Methode Bow-Tie", level=2)
img = os.path.join(os.path.dirname(os.path.abspath(__file__)), "bowtie.png")
doc.add_picture(img, width=Cm(15.5)); doc.paragraphs[-1].alignment = C
for term, expl in [("Gefahr:", "Tätigkeit oder Zustand mit Schadenspotenzial (z. B. Zugfahrt mit Gefahrgut)."),
                   ("Top-Ereignis:", "Der Moment, in dem die Kontrolle über die Gefahr verloren geht (z. B. SPAD, Entgleisung)."),
                   ("Ursache / Bedrohung:", "Was das Top-Ereignis auslösen kann (z. B. Ablenkung, fehlerhafte Fahrplanunterlagen)."),
                   ("Präventive Barriere:", "Verhindert, dass eine Ursache zum Top-Ereignis führt (z. B. PZB/LZB, Vier-Augen-Prinzip)."),
                   ("Konsequenz:", "Mögliche Folge des Top-Ereignisses (z. B. Zusammenprall, Verletzte, Umweltschaden)."),
                   ("Mitigierende Barriere:", "Begrenzt die Folgen (z. B. Notfallmanagement, Gefahrgut-Notfallplan, Erste Hilfe)."),
                   ("Eskalationsfaktor:", "Schwächt eine Barriere (z. B. Übermüdung, Sprachbarriere CZ/DE, fehlende Unterweisung).")]:
    p = doc.add_paragraph(); r = p.add_run(term + " "); r.bold = True; r.font.size = Pt(9)
    r = p.add_run(expl); r.font.size = Pt(9); p.paragraph_format.space_after = Pt(1)

BW = [3.4, 3.6, 3.4, 3.6, 3.4]
EW = [0.8, 3.6, 1.0, 1.3, 1.9, 2.6, 1.6, 4.6]
for n in range(1, 4):
    p = doc.add_paragraph(); p.add_run().add_break(WD_BREAK.PAGE)
    doc.add_heading(f"3.{n+2} Bow-Tie-Analyse – Top-Ereignis {n}", level=2)
    t = table(4, [5.0, 12.4])
    for i, k in enumerate(["Top-Ereignis (Bezeichnung):", "Gefahr:", "Bezug Vorfälle / EBA-/DÚ-Aktenzeichen:", "Datum / Analyseteam:"]):
        put(t.cell(i, 0), k, bold=True); shade(t.cell(i, 0), LIGHT); put(t.cell(i, 1)); t.rows[i].height = Cm(0.8)
    shade(t.cell(1, 0), GOLD_L)
    para(after=4)
    t = table(7, BW)
    heads = [("Ursachen / Bedrohungen", BLUE_L), ("Präventive Barrieren  ►", BLUE_L), ("TOP-EREIGNIS", DARKRED),
             ("►  Mitigierende Barrieren", RED_L), ("Konsequenzen", RED_L)]
    for i, (h, col) in enumerate(heads):
        pp = put(t.cell(0, i), h, bold=True, align=C, size=9); shade(t.cell(0, i), col)
        if col == DARKRED: pp.runs[0].font.color.rgb = _RGB(255, 255, 255)
    repeat_header(t.rows[0])
    mid = t.cell(1, 2).merge(t.cell(5, 2)); shade(mid, DARKRED)
    pp = put(mid, "Top-Ereignis " + str(n), bold=True, align=C); pp.runs[0].font.color.rgb = _RGB(255, 255, 255)
    for rr in range(1, 6):
        for j in (0, 1, 3, 4):
            put(t.cell(rr, j), f"{rr}." if j in (0, 4) else "", size=8)
            t.cell(rr, j).vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.TOP
        t.rows[rr].height = Cm(1.7); cant_split(t.rows[rr])
    a = t.cell(6, 0).merge(t.cell(6, 1)); put(a, "Eskalationsfaktoren (präventiv):", bold=True, size=8)
    a.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.TOP; shade(a, LIGHT)
    put(t.cell(6, 2)); shade(t.cell(6, 2), DARKRED)
    a = t.cell(6, 3).merge(t.cell(6, 4)); put(a, "Eskalationsfaktoren (mitigierend):", bold=True, size=8)
    a.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.TOP; shade(a, LIGHT)
    t.rows[6].height = Cm(1.5)
    para(after=4)
    para("Barrierenbewertung", bold=True, after=2, keep=True)
    t = table(8, EW)
    for i, h in enumerate(["Nr.", "Barriere", "P / M", "Typ T/O/V", "Verantw.",
                           "Wirksamkeit", "Bei Vorfall versagt?", "Maßnahme / Termin"]):
        put(t.cell(0, i), h, bold=True, align=C, size=8); shade(t.cell(0, i), GREY)
    for rr in range(1, 7):
        put(t.cell(rr, 0), str(rr), align=C)
        for j in (1, 2, 3, 4, 7): put(t.cell(rr, j))
        put(t.cell(rr, 5), "☐ wirksam", size=7)
        for x in ("☐ eingeschränkt", "☐ unwirksam"):
            t.cell(rr, 5).add_paragraph(style=tc_style).add_run(x).font.size = Pt(7)
        put(t.cell(rr, 6), "☐ ja ☐ nein", align=C, size=8)
        t.rows[rr].height = Cm(0.95); cant_split(t.rows[rr])
    a = t.cell(7, 0).merge(t.cell(7, 7)); p = put(a); r = p.add_run("Restrisiko akzeptabel?  "); r.bold = True
    p.add_run("☐ ja   ☐ nein – zusätzliche Barrieren erforderlich (→ Maßnahmenplan)")
    small("P = präventiv, M = mitigierend · T = technisch, O = organisatorisch, V = verhaltensbezogen", after=0)

doc.core_properties.title = "Protokoll interner Audits – " + THEMA
doc.core_properties.author = "Enrico Görzen (QMB)"
doc.save(OUT)
print("ok", OUT)
