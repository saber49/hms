# Zielgruppenanalyse und Kundennutzen — Clood-X

**Projekt:** Clood-X — KI-gestützte Multi-Tenant-SaaS-Plattform für Dienstleistungsunternehmen
**Standort:** Sachsen-Anhalt
**Datum:** September 2026

---

## 1. Zielgruppe — Wer wird angesprochen?

### 1.1 Primäre Zielgruppe

Dienstleistungsunternehmen im Bereich Gebäude- und Objektbetreuung mit
1 bis 50 Mitarbeitern. Diese Unternehmen erbringen wiederkehrende
Dienstleistungen an wechselnden Einsatzorten (Kundenobjekten) und
verwalten Mitarbeiter, die nicht an einem festen Arbeitsplatz sitzen,
sondern täglich an unterschiedlichen Objekten eingesetzt werden.

### 1.2 Branchen im Detail

| Branche | Betriebe (Deutschland) | Beschäftigte | Marktvolumen |
|---|---|---|---|
| Gebäudereinigung | 34.824 | 658.325 | 27,55 Mrd. € |
| Sicherheitsdienstleistungen | 4.517 | 290.871 | 14,75 Mrd. € |
| Facility Management (extern) | — | 291.792 (Top 25) | 68,7 Mrd. € |
| Gastronomie- undObjektdienste | ~3.000 | ~80.000 | ~5 Mrd. € |
| Garten- und Landschaftsbau (Dienstleistungsteil) | ~2.500 | ~60.000 | ~4 Mrd. € |
| **Gesamter adressierbarer Markt** | **~45.000** | **~1,1 Mio.** | **~120 Mrd. €** |

*Quellen: BIV-Branchenreport 2025, BDSW 2026, Lünendonk-Studie 2025/2026*

### 1.3 Sekundäre Zielgruppe

- Nachunternehmen (Subunternehmer) größerer Dienstleister
- Objektbetreiber mit eigenem Reinigungs- und Wachpersonal
- Kommunale und kirchliche Gebäudebetreiber

### 1.4 Regionale Zielgruppe — Sachsen-Anhalt

| Branche | Betriebe in Sachsen-Anhalt | Beschäftigte |
|---|---|---|
| Gebäudereinigung | 487 | 14.862 |
| Sicherheitsdienste | ~200 (geschätzt) | ~5.000 |
| Facility Management | ~150 (geschätzt) | ~4.000 |
| **Regionaler Markt** | **~840** | **~24.000** |

*Quelle: Statistisches Landesamt Sachsen-Anhalt 2024, IAB-Betriebspanel 2023*

70 % der sachsen-anhaltischen Betriebe sind Kleinstbetriebe mit weniger
als 10 Beschäftigten. Diese Unternehmen sind die primäre Zielgruppe von
Clood-X, da sie aktuell keine branchenspezifische Softwarelösung einsetzen.

---

## 2. Was sieht der Zielnutzer heute? — Status Quo

### 2.1 Aktuelle Arbeitsweise der Zielunternehmen

Die meisten kleinen und mittleren Dienstleistungsunternehmen (1-50
Mitarbeiter) arbeiten heute mit einer Kombination aus:

| Werkzeug | Verwendung | Probleme |
|---|---|---|
| Papier-Stundenzettel | Zeiterfassung der Mitarbeiter | Fehlerhaft, unleserlich, gehen verloren, müssen manuell abgetippt werden |
| Excel-Listen | Disposition, Kundenverwaltung, Objektverwaltung | Keine Mehrnutzer-Zugriff, Versionskonflikte, keine Mobile-Nutzung |
| WhatsApp-Gruppen | Kommunikation mit Mitarbeitern vor Ort | Keine Dokumentation, DSGVO-problematisch, keine Struktur |
| Telefon | Einsatzabsprachen, Rückfragen | Keine Dokumentation, zeitaufwendig, fehleranfällig |
| Papier-Auftragsscheine | Auftragserfassung vor Ort | Gehen verloren, unleserlich, manuelle Übertragung nötig |
| Handgeschriebene Rechnungen | Monatsabrechnung | Fehlerhaft, zeitaufwendig, professionelles Erscheinungsbild fehlt |
| Google Drive / Dropbox | Dokumentenablage | Keine Struktur, keine Mandantentrennung, DSGVO-Risiko |

### 2.2 Was der Zielnutzer täglich erlebt

**Ein typischer Arbeitstag eines Disponenten in einem Reinigungsunternehmen
mit 20 Mitarbeitern:**

```
06:00 Uhr  — Telefonanruf: Mitarbeiter X ist krank.
             Disponent sucht Ersatz, telefoniert 30 Minuten.

07:00 Uhr  — Mitarbeiter rufen an: "Welches Objekt heute?"
             Disponent diktiert Adressen telefonisch.

08:00 Uhr  — Fax/E-Mail: Neuer Auftrag von Kunde Y.
             Wird auf Papier notiert, später in Excel eingetragen.

10:00 Uhr  — Mitarbeiter Z meldet: Material leer.
             Disponent notiert, vergisst es später.

12:00 Uhr  — Kunde ruft an: "Wie viele Stunden waren
             letzte Woche bei uns?"
             Disponent sucht in Papier-Stundenzetteln.

14:00 Uhr  — Buchhaltung fragt: Stunden für Monatsabrechnung.
             Disponent tippt 40 Stundenzettel manuell ab.

16:00 Uhr  — Mitarbeiter kommt zurück, Stundenzettel
             unleserlich, fehlende Unterschrift.

17:00 Uhr  — Disponent arbeitet bis 19:00 Uhr an
             Excel-Nachpflege.
```

### 2.3 Konkrete Schmerzpunkte der Zielgruppe

| Schmerzpunkt | Auswirkung | Kosten |
|---|---|---|
| Manuelle Zeiterfassung | 40 Stundenzettel/Woche × 5 Min. = 3,3 Std./Woche Verschwendung | ~260 €/Monat |
| Fehlende GPS-Verifikation | Mitarbeiter können Zeiten fälschen, kein Nachweis bei Kundenreklamationen | ~500 €/Monat |
| Papierbasierte Disposition | Fehleinsätze, doppelte Beauftragung, vergessene Objekte | ~400 €/Monat |
| Manuelle Monatsabrechnung | 2-3 Tage pro Monat reine Buchhaltungsarbeit | ~600 €/Monat |
| Keine DSGVO-Dokumentation | Bußgeldrisiko bei Datenschutzverletzungen | bis 20 Mio. € |
| Keine digitale Kundenakte | Lange Suchzeiten, verlorene Verträge | ~200 €/Monat |
| WhatsApp für Geschäftskommunikation | DSGVO-verstoßend, keine Archivierung | Rechtsrisiko |
| Keine Mobile-App für Mitarbeiter | Mitarbeiter müssen ins Büro anrufen, um Einsatzdaten zu erhalten | ~300 €/Monat |
| **Gesamter monatlicher Verlust** | | **~2.260 €/Monat = ~27.000 €/Jahr** |

---

## 3. Was sieht der Zielnutzer mit Clood-X? — Die Lösung

### 3.1 Die Plattform aus Nutzersicht

Clood-X bietet fünf zentrale Nutzererlebnisse, die den Arbeitsalltag
der Zielgruppe grundlegend verändern:

---

#### 3.1.1 Der Disponent — Zentrale Steuerung

**Was der Disponent sieht:**

Eine Web-Oberfläche mit allen aktuellen Einsätzen, Mitarbeitern,
Objekten und Kunden in einer zentralen Übersicht. Der Disponent sieht
auf einen Blick:

- Welche Mitarbeiter heute wo eingesetzt sind
- Welche Aufträge noch nicht disponsiert sind
- Welche Objekte noch Personal benötigen
- Welche Mitarbeiter verfügbar sind (GPS-Position, Status)

**Was sich ändert:**

| Vor Clood-X | Mit Clood-X |
|---|---|
| 30 Minuten telefonische Ersatzsuche bei Krankheit | Drag-and-Drop-Ersatzdisposition in 30 Sekunden |
| Telefonisches Diktieren von Einsatzadressen | Automatische Push-Benachrichtigung an die Mobile-App des Mitarbeiters |
| Excel-Liste, die nur eine Person gleichzeitig bearbeiten kann | Mehrere Disponenten arbeiten gleichzeitig in derselben Datenbasis |
| Keine Übersicht über aktuelle Einsatzstände | Live-Status: "Eingestempelt", "Im Einsatz", "Ausgestempelt" |

**Warum das wichtig ist:**

Der Disponent ist das Herzstück eines Dienstleistungsunternehmens. Wenn
der Disponent 50 % seiner Zeit mit Suchen, Telefonieren und
Papierverwaltung verbringt, fehlt diese Zeit für Vertrieb und
Kundenbetreuung. Clood-X reduziert den Dispositionsaufwand um 60-70 %.

---

#### 3.1.2 Der Mitarbeiter — Mobile App

**Was der Mitarbeiter sieht:**

Eine Mobile-App (Android und iOS) mit:

- Tagesaufträge: Wo muss ich heute sein, um wie viel Uhr?
- Objekt-Details: Adresse, Ansprechpartner, Reinigungsplan, Materialbedarf
- Zeiterfassung: GPS-gestütztes Ein- und Ausstempeln mit einem Tipp
- Auftragsdokumentation: Foto-Upload, Mängelberichte, Unterschrift vor Ort
- Push-Benachrichtigungen: Neue Aufträge, Änderungen, Nachrichten
- Offline-Funktionalität: Alle Daten sind auch ohne Internetverbindung verfügbar

**Was sich ändert:**

| Vor Clood-X | Mit Clood-X |
|---|---|
| Morgens Anruf im Büro, um Einsatz zu erfahren | Push-Benachrichtigung um 6:00 Uhr mit Tagesauftrag |
| Papier-Stundenzettel, der im Büro abgegeben werden muss | GPS-Stempel mit einem Tipp, automatisch synchronisiert |
| Mängel werden auf Papier notiert und gehen verloren | Foto + Beschreibung in der App, sofort im System |
| Keine Ahnung, wo der nächste Einsatz ist | Navigation direkt aus der App |
| Bei Fragen muss das Büro angerufen werden | Chat-Funktion mit dem Disponenten |

**Warum das wichtig ist:**

Mitarbeiter in der Gebäudereinigung und Sicherheit arbeiten früh morgens,
spät abends und am Wochenende. Sie haben keine Zeit, ins Büro zu fahren,
um Stundenzettel abzugeben. Eine Mobile-App, die alle Informationen
vor Ort liefert und die Zeiterfassung automatisiert, spart pro
Mitarbeiter 15-30 Minuten pro Tag — bei 20 Mitarbeitern sind das
5-10 Stunden pro Tag eingesparte Arbeitszeit.

---

#### 3.1.3 Der Geschäftsführer — Controlling und Auswertung

**Was der Geschäftsführer sieht:**

Ein Dashboard mit:

- Umsatz pro Kunde, pro Objekt, pro Mitarbeiter
- Auslastungsgrad der Mitarbeiter
- Auftragsdurchlaufzeiten
- KPIs: Produktivität, Rentabilität, Kundenbindung
- Export-Funktion für Steuerberater (Excel, CSV, PDF)

**Was sich ändert:**

| Vor Clood-X | Mit Clood-X |
|---|---|
| Zahlen liegen in Excel verteilt, monatliche Zusammenstellung dauert 2 Tage | Live-Dashboard, jederzeit aktuelle Zahlen verfügbar |
| Keine Auskunft über Rentabilität einzelner Kunden | Kunden-Rentabilitätsanalyse auf Knopfdruck |
| Keine Frühwarnung bei unrentablen Aufträgen | Automatische Warnung bei Kostenüberschreitung |

**Warum das wichtig ist:**

Viele kleine Dienstleistungsunternehmen kennen ihre eigene
Rentabilität nicht genau. Sie wissen nicht, welcher Kunde Gewinn
bringt und welcher Verlust. Ohne diese Kenntnis können sie nicht
strategisch agieren. Clood-X macht diese Transparenz mit einem Klick
verfügbar — ohne Buchhaltungskenntnisse.

---

#### 3.1.4 Der KI-Assistent — Natürliche Sprache

**Was der Nutzer sieht:**

Ein Chat-Fenster, in dem er in natürlicher Sprache Fragen stellen
und Anweisungen geben kann:

```
Nutzer:   "Wie viele Einsätze hatten wir im letzten Monat bei Kunde Müller GmbH?"
KI:       "Im September 2026 wurden bei Müller GmbH 47 Einsätze mit
           insgesamt 312 Stunden durchgeführt. Das entspricht einem
           Umsatz von 4.680 €. Im Vergleich zum Vormonat ist das
           ein Rückgang von 8 %."

Nutzer:   "Erstelle einen neuen Kunden mit dem Namen Schmidt & Söhne."
KI:       "Ich habe folgenden Vorschlag vorbereitet:
           - Name: Schmidt & Söhne
           - Bitte bestätigen Sie die Erstellung."

Nutzer:   "Bestätigt."
KI:       "Kunde Schmidt & Söhne wurde erfolgreich angelegt
           (Kundennummer 36)."
```

**Was sich ändert:**

| Vor Clood-X | Mit Clood-X |
|---|---|
| Nutzer muss Menüs durchsuchen, um Daten zu finden | Natürlichsprachige Anfrage, sofortige Antwort |
| Anlegen von Kunden erfordert 8-10 Formularfelder | KI schlägt vor, Nutzer bestätigt |
| Keine Auswertungen ohne Excel-Kenntnisse | "Zeige mir die Top-5 Kunden nach Umsatz" — fertig |
| Dokumente müssen manuell ausgelesen werden | OCR erkennt Rechnungsdaten automatisch |

**Warum das wichtig ist:**

84 % der Facility-Service-Unternehmen nennen Künstliche Intelligenz
als den wichtigsten Digitalisierungs-Trend (Lünendonk-Studie 2025 —
höchster Wert seit Beginn der Erhebung). Die Zielgruppe (kleine
Dienstleister) hat keine Zeit, komplexe Software zu erlernen. Eine
KI, die natürliche Sprache versteht, senkt die Einstiegshürde drastisch
— der Nutzer kann sofort arbeiten, ohne Schulung.

---

#### 3.1.5 Der Nachunternehmer — Eigenes Portal

**Was der Nachunternehmer sieht:**

Ein eigenes Portal mit:

- Zugewiesenen Aufträgen vom Hauptunternehmer
- Eigener Zeiterfassung für seine Mitarbeiter
- Dokumentenupload (Nachweise, Zertifikate)
- Abrechnungsfunktion

**Warum das wichtig ist:**

Dienstleistungsunternehmen arbeiten häufig mit Nachunternehmen
(Subunternehmern), die ihrerseits Mitarbeiter einsetzen. Bisher
werden diese Strukturen per E-Mail und Excel verwaltet. Clood-X
bietet ein integriertes Nachunternehmer-Portal, das die
Auftragsweitergabe, Dokumentation und Abrechnung in einem System
abbildet — DSGVO-konform und revisionssicher.

---

## 4. Warum der Zielnutzer Clood-X braucht — Kundennutzen

### 4.1 Messbarer wirtschaftlicher Nutzen

| Nutzen | Ersparnis pro Monat | Begründung |
|---|---|---|
| Automatisierte Zeiterfassung | 260 € | 40 Stundenzettel/Woche entfallen, GPS-Stempel statt Papier |
| GPS-verifizierte Zeiten | 500 € | Vermeidung von Zeitbetrug, Nachweis bei Kundenreklamationen |
| Digitale Disposition | 400 € | Vermeidung von Fehleinsätzen, Doppelbeauftragung, vergessenen Objekten |
| Automatisierte Monatsabrechnung | 600 € | 2-3 Tage Buchhaltungsaufwand entfallen |
| Digitale Kundenakte | 200 € | Keine Suchzeiten, keine verlorenen Verträge |
| KI-Assistent | 300 € | Schnellere Datenerfassung und -abfrage |
| **Gesamte monatliche Ersparnis** | **~2.260 €** | **~27.000 €/Jahr** |

### 4.2 ROI für den Kunden

```
Clood-X Professional:     199 €/Monat = 2.388 €/Jahr
Ersparnis:             ~2.260 €/Monat = 27.000 €/Jahr
ROI:                                 1.130 %
Jeder investierte Euro in Clood-X
bringt dem Kunden 11 € Ersparnis.
```

### 4.3 Nicht-monetärer Nutzen

| Nutzen | Bedeutung |
|---|---|
| DSGVO-Konformität | Keine Bußgeldrisiken mehr, Daten sicher in Deutschland gehostet |
| Professionelles Auftreten | Digitale Rechnungen, Auftragsbestätigungen mit Firmenlogo — wirkt seriös gegenüber Großkunden |
| Mitarbeiterzufriedenheit | Klare Aufträge, keine Anrufe, faire Zeiterfassung — geringere Fluktuation |
| Skalierbarkeit | Wachstum von 10 auf 100 Mitarbeiter ohne zusätzliche Verwaltungskraft möglich |
| Wettbewerbsfähigkeit | Kann bei Ausschreibungen digitale Prozesse nachweisen — Vorteil gegenüber analogen Mitbewerbern |
| Mobilität | Geschäftsführer kann von überall auf seine Zahlen zugreifen — auch vom Handy |

---

## 5. Warum Clood-X und nicht eine andere Lösung?

### 5.1 Wettbewerbsvergleich

| Kriterium | SAP Business One | Microsoft Dynamics | Excel | Clood-X |
|---|---|---|---|---|
| Preis/Monat | 200-500 € | 150-400 € | 0 € | 99-399 € |
| Einführungsdauer | 3-6 Monate | 2-4 Monate | sofort | 1 Tag |
| Schulungsbedarf | hoch | hoch | gering | sehr gering (KI) |
| Branchenfokus | allgemein | allgemein | allgemein | Dienstleistung |
| Mobile App | teures Add-on | teures Add-on | nein | inklusive |
| KI-Integration | nein | nein | nein | Kernfunktion |
| Multi-Tenant | nein | nein | nein | ja (physisch) |
| DSGVO-konform | ja (komplex) | ja (komplex) | nein | ja (einfach) |
| Zielgruppe | >100 Mitarbeiter | >50 Mitarbeiter | alle | 1-50 Mitarbeiter |

### 5.2 Alleinstellungsmerkmale von Clood-X

1. **Branchenspezifisch** — Clood-X ist keine Allround-Software, sondern
   wurde von Grund auf für Dienstleistungsunternehmen entwickelt.
   Die Datenstruktur (Kunde → Objekt → Leistung → Bestelleingang →
   Bestellung → Disposition → Einsatz → Zeiterfassung → Controlling →
   Abrechnung) spiegelt den tatsächlichen Geschäftsprozess wider.

2. **KI als Kernfunktion** — 84 % der Branche wünscht sich KI. Clood-X
   hat KI nicht nachgerüstet, sondern von Anfang an integriert. Der
   KI-Assistent versteht natürliche Sprache, kann Daten lesen und
   schreiben (mit Bestätigung), und erkennt Dokumente per OCR.

3. **Physische Mandantentrennung** — Jeder Kunde (Mandant) erhält eine
   eigene Datenbank. Bei DSGVO-Audits ist der Nachweis der
   Datenisolation trivial. Wettbewerber nutzen meist nur eine
   tenant_id-Spalte in einer gemeinsamen Datenbank — ein
   Sicherheitsrisiko.

4. **Fail-Closed-Sicherheit** — Eine Sicherheitsmiddleware vergleicht
   bei jedem API-Aufruf das Mandanten-Token mit dem aktiven Mandanten.
   Bei Abweichung wird der Zugriff verweigert und der Zustand
   zurückgesetzt. Dies verhindert mandantenübergreifenden
   Datenzugriff selbst bei Programmierfehlern.

5. **Mobile-App inklusive** — Android und iOS, ohne Aufpreis. Keine
   teuren Add-ons wie bei Wettbewerbern.

6. **Desktop-App für Windows** — Ein Windows-Installer mit
   Auto-Update-Funktion für Geschäftsführer, die eine native
   Anwendung bevorzugen.

7. **Preisstruktur für kleine Unternehmen** — Starter-Tarif ab 99 €/Monat
   für Unternehmen mit bis zu 10 Mitarbeitern. Wettbewerber beginnen
   bei 200-500 €/Monat.

---

## 6. Was der Zielnutzer sieht — Zusammenfassung

### 6.1 Vor Clood-X

```
Der Geschäftsführer:
├── Weiß nicht, ob seine Aufträge rentabel sind
├── Verbringt Wochenenden mit Excel-Auswertungen
├── Hat keine aktuelle Übersicht über laufende Einsätze
└── Kann bei Kundenreklamationen keine GPS-Daten vorlegen

Der Disponent:
├── Verbringt 50 % der Zeit am Telefon
├── Sucht Ersatzpersonal manuell bei Krankheit
├── Tippt Stundenzettel ab
└── Pflegt Excel-Listen nach

Der Mitarbeiter:
├── Weiß morgens nicht, wo er eingesetzt ist
├── Füllt Papier-Stundenzettel aus (unleserlich)
├── Meldet Mängel per Telefon (werden vergessen)
└── Hat keine Dokumentation seiner geleisteten Arbeit
```

### 6.2 Mit Clood-X

```
Der Geschäftsführer:
├── Sieht live Umsatz, Auslastung und Rentabilität im Dashboard
├── Exportiert Monatszahlen mit einem Klick für den Steuerberater
├── Hat jederzeit Zugriff vom Handy aus
└── Kann bei Kundenreklamationen GPS-verifizierte Zeiten nachweisen

Der Disponent:
├── Sieht alle Einsätze in einer zentralen Übersicht
├── Disponiert per Drag-and-Drop in 30 Sekunden
├── Lässt die KI Kunden und Objekte anlegen
└── Hat Zeit für Vertrieb und Kundenbetreuung

Der Mitarbeiter:
├── Erhält Tagesauftrag per Push-Benachrichtigung
├── Stempelt per GPS mit einem Tipp ein und aus
├── Dokumentiert Mängel mit Foto in der App
└── Hat alle Objektinformationen offline verfügbar
```

---

## 7. Kundenaussagen und Proof of Concept

### 7.1 Erstkunde — HMS Gruppe GmbH & Co. KG

| Kennzahl | Wert |
|---|---|
| Unternehmensform | GmbH & Co. KG |
| Status | Produktiv im Live-Betrieb |
| Verwaltete Einsätze | 1.242 |
| Aktive Mitarbeiter in der App | 39 |
| Verwaltete Kunden | 35 |
| Verwaltete Objekte | 73 |
| Bestellungen | 92 |
| Nachunternehmen | 3 |

Der Erstkunde nutzt die Plattform im produktiven Tagesgeschäft. Die
39 Mitarbeiter verwenden die Mobile-App täglich für Zeiterfassung
und Auftragsdokumentation. Der Disponent verwaltet 35 Kunden und
73 Objekte über die Web-Oberfläche.

### 7.2 Technische Leistungsmessung

| Metrik | Wert | Messmethode |
|---|---|---|
| API-Antwortzeit | 59 ms | Lasttest auf Produktionssystem |
| Gleichzeitige Nutzer (getestet) | 200 (0 Fehler) | Apache Bench, 200 Verbindungen |
| Durchsatz | 648 Anfragen/Sekunde | Lasttest bei 100 gleichzeitigen Verbindungen |
| Verfügbarkeit | 99,9 % (54 Tage Uptime) | Produktionsmessung |
| Codebasis | ~91.000 Zeilen | Lokale Messung |
| API-Endpunkte | 310 | routes/api.php |
| Datenbank-Migrationen | 76 (18 zentral + 58 mandantenspezifisch) | Laravel migrations |

---

## 8. Fazit

Clood-X spricht eine Zielgruppe an, die heute von der Digitalisierung
weitgehend ausgeschlossen ist: kleine und mittlere
Dienstleistungsunternehmen mit 1-50 Mitarbeitern. Diese Unternehmen
verwalten heute ihre Prozesse mit Papier, Excel und WhatsApp — mit
allen damit verbundenen Risiken und Ineffizienzen.

Die Plattform bietet dieser Zielgruppe:

1. **Einen messbaren wirtschaftlichen Nutzen** von ~27.000 €/Jahr
   bei einem Preis von ~2.400 €/Jahr — ein ROI von über 1.100 %.

2. **Einen KI-Assistenten**, der die Einstiegshürde drastisch senkt.
   84 % der Branche wünscht sich KI — Clood-X hat sie bereits
   integriert.

3. **Eine Mobile-App**, die den Mitarbeitern im Feld alle
   Informationen liefert und die Zeiterfassung automatisiert.

4. **DSGVO-Konformität** durch Hosting in Deutschland, KI-Verarbeitung
   in der EU und physische Mandantentrennung.

5. **Einen Proof of Concept** mit einem zahlenden Erstkunden, der
   die Plattform im produktiven Tagesgeschäft mit 39 Mitarbeitern
   und 1.242 Einsätzen nutzt.

Der Markt ist groß (45.000 potenzielle Kunden in Deutschland, 840 in
Sachsen-Anhalt), fragmentiert (70 % Kleinunternehmen) und
unterversorgt (keine branchenspezifische Software für kleine
Dienstleister). Clood-X schließt genau diese Lücke.

---

*Dieses Dokument basiert auf Marktdaten von Lünendonk-Studie 2025/2026,
BIV-Branchenreport 2025, BDSW 2026, Statistischem Landesamt
Sachsen-Anhalt 2024 und IAB-Betriebspanel 2023.*
