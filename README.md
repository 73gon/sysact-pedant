# Allgemein

Dies ist die Dokumentation der Systemaktivität: "Pedant"

Inhaltsverzeichnis

- [Allgemein](#allgemein)
- [Wie funktioniert die Systemaktivität (allgemein)](#wie-funktioniert-die-systemaktivität-allgemein)
  - [Grundprinzip](#grundprinzip)
  - [Der asynchrone Verarbeitungszyklus](#der-asynchrone-verarbeitungszyklus)
  - [Vorstellung von Datei: SystemActivity.php](#vorstellung-von-datei-systemactivityphp)
- [Funktion: Rechnung auslesen (pedant)](#funktion-rechnung-auslesen-pedant)
  - [Dialogfelder von Rechnung auslesen (pedant)](#dialogfelder-von-rechnung-auslesen-pedant)
- [Funktion: Rechnung abholen (fetchData)](#funktion-rechnung-abholen-fetchdata)
  - [Dialogfelder von fetchData](#dialogfelder-von-fetchdata)
- [Vorstellung von Funktion: documentClassifier](#vorstellung-von-funktion-documentclassifier)
  - [Dialogfelder von documentClassifier](#dialogfelder-von-documentclassifier)
- [Vorstellung von Funktion: readDeliveryNote](#vorstellung-von-funktion-readdeliverynote)
  - [Dialogfelder von readDeliveryNote](#dialogfelder-von-readdeliverynote)
- [Vorstellung der Import-Funktionen: importVendorCSV / importRecipientCSV / importCostCenterCSV](#vorstellung-der-import-funktionen-importvendorcsv--importrecipientcsv--importcostcentercsv)
  - [Dialogfelder der Import-Funktionen](#dialogfelder-der-import-funktionen)
- [Für Developer](#für-developer)
- [Support](#support)
- [Fußnoten](#fußnoten)

# Wie funktioniert die Systemaktivität (allgemein)

Die Systemaktivität fungiert als zentrale Schnittstelle zwischen JobRouter und den Diensten von Pedant.ai. Sie ermöglicht die automatisierte Verarbeitung von Dokumenten (insbesondere Rechnungen) sowie den Stammdatenaustausch direkt aus einem laufenden Workflow heraus.

## Grundprinzip

Die Aktivität ist als modulare PHP-Klasse implementiert, die auf der AbstractSystemActivityAPI von JobRouter basiert. Sie übernimmt die Aufgabe, Daten aus dem JobRouter-Prozess zu lesen, diese an die Pedant-API zu übermitteln und die von der KI extrahierten Ergebnisse strukturiert in den Prozess zurückzuschreiben.


## Der asynchrone Verarbeitungszyklus

Da die Analyse von Dokumenten durch eine KI Zeit in Anspruch nimmt, arbeiten viele Funktionen dieser Systemaktivität nach einem "Check-and-Wait"-Prinzip:

- Upload-Phase: Das Dokument wird an Pedant gesendet. Die Aktivität speichert eine eindeutige ID und setzt den Schritt auf Wiedervorlage.

- Wartephase: JobRouter pausiert die Aktivität für einen definierten Zeitraum.

- Abhol-Phase: Nach Ablauf der Zeit prüft die Aktivität automatisch, ob die Analyse fertiggestellt wurde. Wenn ja, werden die Daten importiert; wenn nein, erfolgt eine erneute Wartezeit.


## Vorstellung von Datei: SystemActivity.php

Ihre Hauptaufgabe ist die Strukturierung und Bereitstellung der Programmlogik gegenüber JobRouter. Während die fachliche Logik in den einzelnen Traits (z. B. InvoiceTrait) gekapselt ist, definiert SystemActivity.php die Klasse pedantSystemActivity, die von der JobRouter-Basisklasse erbt. Sie ist verantwortlich für das Initialisieren der Umgebung und das Routen der Anfragen an die korrekten Programmteile.

Hierbei findet eine strikte Aufgabenteilung statt:

- Klassen-Definition: Erstellt die Klasse pedantSystemActivity, die als Schnittstelle zum JobRouter-Server dient.

- Modul-Einbindung: Lädt alle notwendigen Abhängigkeiten und Traits via require_once (z. B. LoggerTrait.php, ApiTrait.php).

- Konstanten-Verwaltung: Definition globaler Parameter wie Erfolgs-HTTP-Codes (z. B. 200, 201), maximale Dateigrößen oder erlaubte Status-Flags.

- Einstiegspunkt-Routing: Leitet die Aufrufe aus dem JobRouter-Workflow (wie pedant, fetchData oder documentClassifier) an die entsprechenden Methoden innerhalb der Traits weiter.


# Funktion: Rechnung auslesen (pedant)

Die Funktion pedant ist das Herzstück der Systemaktivität für die Rechnungsverarbeitung. Sie steuert den gesamten Lebenszyklus eines Dokuments von der Übermittlung aus JobRouter bis hin zur Rückgabe der extrahierten Daten.

## Dialogfelder von Rechnung auslesen (pedant)

**Inputfelder**

| Parameter      | Bedeutung                                                                                                                  | Hinweis                                                                            |
| -------------- | -------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `INPUTFILE`    | Nimmt das zu verarbeitende Dokument aus dem JobRouter-Prozess entgegen.                                                    | Pflichtfeld                                                                        |
| `API_KEY`      | Authentifizierungsschlüssel für die Pedant-API.                                                                            | Pflichtfeld                                                                        |
| `DEMO`         | Schaltet zwischen Demo- und Produktivumgebung um.                                                                          | `1` = Demo                                                                         |
| `INTNUMBER`    | Übergibt eine Mandanten- oder Empfängerreferenz an Pedant.                                                                 | Unterstützt die Zuordnung                                                          |
| `MAXRETRIES`   | Legt die maximale Anzahl an Upload- oder Check-Versuchen fest.                                                             | Schutz vor Endlosschleifen                                                         |
| `FLAG`         | Steuert den Verarbeitungsmodus.                                                                                            | Erlaubt: `normal`[^1], `check_extraction`[^2], `skip_review`[^3], `force_skip`[^4] |
| `FLAGXML`      | Überschreibt `FLAG`, wenn eine XML-Datei verarbeitet wird.                                                                 | Nur für XML relevant                                                               |
| `NEWVERSION`   | Setzt die Wiedervorlage auf ca. 2 Jahre.                                                                                   | Technische Sonderlogik                                                             |
| `ZUGFERD`      | Aktiviert den ZUGFeRD-bezogenen Uploadpfad.                                                                                | Relevant für hybride E-Rechnungen                                                  |
| `INTERVAL`     | Wiedervorlage in Minuten, wenn `NEWVERSION` nicht aktiv ist.                                                               | Polling-Abstand                                                                    |
| `NOTE`         | Optionaler Kommentar, der mit dem Dokument an Pedant übertragen wird und bei der Überprüfung eines Mitarbeiters einsehbar. | Optional                                                                           |
| `INCIDENT`     | Kennzeichnet den Vorgang für das Logging.                                                                                  | Erscheint in jeder Logzeile                                                        |
| `MAXFILESIZE`  | Maximale Dateigröße in MB.                                                                                                 | Standardwert ohne Eingabe: 20 MB                                                   |
| `VENDORTABLE`  | Optionale JobRouter-Tabelle für einen Vendor-Import während `checkFile()`.                                                 | Spezialfall                                                                        |
| `IMPORTVENDOR` | Mapping-Liste für den optionalen Vendor-Import.                                                                            | Gehört zu `VENDORTABLE`                                                            |

**Outputfelder**

| Parameter           | Bedeutung                                                                                                                 |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `FILEID`            | Von Pedant zurückgegebene Dokumenten-ID; wird später als `fileId` oder bei E-Rechnungen als `documentId` weiterverwendet. |
| `INVOICEID`         | Pedant-interne Rechnungs-ID.                                                                                              |
| `TEMPJSON`          | Vollständige Rohantwort der API als JSON.                                                                                 |
| `COUNTERSUMMARY`    | Zusammenfassung der Upload- und Check-Versuche.                                                                           |
| `RECIPIENTDETAILS`  | Extrahierte Empfängerdaten.                                                                                               |
| `VENDORDETAILS`     | Extrahierte Lieferantendaten.                                                                                             |
| `INVOICEDETAILS`    | Rechnungs-Kopfdaten wie Nummer, Datum und Beträge.                                                                        |
| `POSITIONDETAILS`   | Rechnungspositionen in einer Untertabelle.                                                                                |
| `AUDITTRAILDETAILS` | Bearbeitungshistorie aus Pedant.                                                                                          |
| `REJECTIONDETAILS`  | Ablehnungsgründe und Fehlerdetails.                                                                                       |
| `ATTACHMENTS`       | Zusatzdateien wie PDF, Report-XML, weitere Anhänge oder Audit-Trail-CSV.                                                  |
| `WORKFLOWDETAILS`   | Workflow-Kennzeichen, z. B. ob der Direkt-Workflow verwendet wurde.                                                       |

# Funktion: Rechnung abholen (fetchData)

Die Funktion fetchData dient als zentraler Abhol-Dienst (Poller) für bereits verarbeitete Dokumente. Im Gegensatz zur pedant-Funktion, die ein einzelnes Dokument durch den Prozess begleitet, arbeitet fetchData im Batch-Modus. Sie prüft in regelmäßigen Abständen, welche Dokumente auf der Plattform fertig zur Abholung bereitstehen, und "weckt" die entsprechenden JobRouter-Instanzen auf.

## Dialogfelder von fetchData

**Inputfelder**

| Parameter        | Bedeutung                                                                           | Hinweis                                                                        |
| ---------------- | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| `API_KEY`        | Authentifizierungsschlüssel für die Pedant-API.                                     | Pflichtfeld                                                                    |
| `INVOICE_STATUS` | Bestimmt, welche Dokumentstatus abgefragt werden.                                   | Erlaubt: `reviewed`, `exported`, `rejected`, `archived`; kommagetrennt möglich |
| `DEMO`           | Schaltet zwischen Demo- und Produktivumgebung um.                                   | Optional                                                                       |
| `FILEID`         | Name der Spalte in der JobRouter-Kopftabelle, in der die Pedant-ID gespeichert ist. | Nicht der eigentliche ID-Wert                                                  |
| `TABLEHEAD`      | Name der JobRouter-Prozesstabelle.                                                  | Für den SQL-Join erforderlich                                                  |
| `STEPID`         | Technische ID des wartenden Prozessschritts.                                        | Ziel der Reaktivierung                                                         |
| `INTERVAL`       | Polling-Abstand in Minuten.                                                         | Steuerung der Wiedervorlage                                                    |
| `WORKTIME`       | Arbeitszeitfenster, z. B. `6,18`.                                                   | Außerhalb des Fensters wird verschoben                                         |
| `WEEKEND`        | Aktiviert oder deaktiviert die Ausführung am Wochenende.                            | Beeinflusst die Wiedervorlage                                                  |

**Outputfelder**

Für `fetchData` sind keine Outputparameter definiert. Die Funktion arbeitet direkt auf der JobRouter-Datenbank und aktualisiert dort die Wiedervorlage wartender Schritte.

# Vorstellung von Funktion: documentClassifier

Die Funktion documentClassifier dient der automatisierten Einordnung und Metadaten-Extraktion von Dokumenten, noch bevor diese in eine spezifische Fachverarbeitung (wie die Rechnungsprüfung) gehen. Sie wird genutzt, um die Art eines Dokuments zu bestimmen und grundlegende Kopfdaten zu identifizieren.

## Dialogfelder von documentClassifier

**Inputfelder**

| Parameter       | Bedeutung                                         | Hinweis                                                                                |
| --------------- | ------------------------------------------------- | -------------------------------------------------------------------------------------- |
| `API_KEY`       | Authentifizierungsschlüssel für die Pedant-API.   | Pflichtfeld                                                                            |
| `DEMO`          | Schaltet zwischen Demo- und Produktivumgebung um. | Optional                                                                               |
| `INPUTFILE`     | Zu klassifizierendes Dokument.                    | Pflichtfeld                                                                            |
| `DC_ACTION`     | Upload-Aktion für den Classifier.                 | Erlaubt: `normal`, `check_extraction`, `skip_review`, `force_skip`; Standard: `normal` |
| `DC_MAXRETRIES` | Maximale Anzahl an Check-Versuchen.               | Schutz vor Endlosschleifen                                                             |
| `DC_INTERVAL`   | Wiedervorlage in Minuten.                         | Polling-Abstand                                                                        |
| `MAXFILESIZE`   | Maximale Dateigröße in MB.                        | Standardwert ohne Eingabe: 20 MB                                                       |

**Outputfelder**

| Parameter               | Bedeutung                                                                           |
| ----------------------- | ----------------------------------------------------------------------------------- |
| `DC_DOCUMENTID`         | Dokument-ID für spätere Statusabfragen.                                             |
| `DC_TEMPJSON`           | Vollständige Rohantwort der API als JSON.                                           |
| `CLASSIFICATIONDETAILS` | Strukturierte Klassifikation wie Dokumenttyp, Firmenname und Datum.                 |
| `CONFIDENCEVALUES`      | Sammlung der von der KI zurückgegebenen selbstsicherheit in der Auslese der Felder. |

# Vorstellung von Funktion: readDeliveryNote

Die Funktion readDeliveryNote dient der automatisierten Auslesung und Metadaten-Extraktion von Lieferscheinen.

## Dialogfelder von readDeliveryNote

**Inputfelder**

| Parameter     | Bedeutung                                                                                                                  | Hinweis                                                                                |
| ------------- | -------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| `API_KEY`     | Authentifizierungsschlüssel für die Pedant-API.                                                                            | Pflichtfeld                                                                            |
| `DEMO`        | Schaltet zwischen Demo- und Produktivumgebung um.                                                                          | Optional                                                                               |
| `INPUTFILE`   | Zu klassifizierendes Dokument.                                                                                             | Pflichtfeld                                                                            |
| `FLAG`        | Upload-Aktion für den Classifier.                                                                                          | Erlaubt: `normal`, `check_extraction`, `skip_review`, `force_skip`; Standard: `normal` |
| `MAXRETRIES`  | Maximale Anzahl an Check-Versuchen.                                                                                        | Schutz vor Endlosschleifen                                                             |
| `INTERVAL`    | Wiedervorlage in Minuten.                                                                                                  | Polling-Abstand                                                                        |
| `MAXFILESIZE` | Maximale Dateigröße in MB.                                                                                                 | Standardwert ohne Eingabe: 20 MB                                                       |
| `NOTE`        | Optionaler Kommentar, der mit dem Dokument an Pedant übertragen wird und bei der Überprüfung eines Mitarbeiters einsehbar. | Optional                                                                               |
| `INCIDENT`    | Kennzeichnet den Vorgang für das Logging                                                                                   | Standardwert ohne Eingabe: 20 MB                                                       |

**Outputfelder**

| Parameter   | Bedeutung                                 |
| ----------- | ----------------------------------------- |
| `FILEID`    | Dokument-ID für spätere Statusabfragen.   |
| `INVOICEID` | Pedant-interne Rechnungs-ID.              |
| `TEMPJSON`  | Vollständige Rohantwort der API als JSON. |

# Vorstellung der Import-Funktionen: importVendorCSV / importRecipientCSV / importCostCenterCSV

Diese Funktionen dienen dem Abgleich von Stammdaten zwischen JobRouter und Pedant.ai. Da die technische Umsetzung für alle drei Entitätstypen auf derselben Grundlogik basiert, werden sie hier zusammengefasst. Trotzdem werden die fachlich relevanten Unterschiede am Ende separat aufgeführt.

## Dialogfelder der Import-Funktionen

**Inputfelder**

| Parameter                                               | Bedeutung                                                                    | Hinweis                                       |
| ------------------------------------------------------- | ---------------------------------------------------------------------------- | --------------------------------------------- |
| `API_KEY`                                               | Authentifizierungsschlüssel für die Pedant-API.                              | Pflichtfeld                                   |
| `DEMO`                                                  | Schaltet zwischen Demo- und Produktivumgebung um.                            | Optional                                      |
| `VENDORTABLE` / `RECIPIENTTABLE` / `COSTCENTERTABLE`    | Name der JobRouter-Tabelle oder View, aus der die Stammdaten gelesen werden. | Je nach Funktion unterschiedlich              |
| `IMPORTVENDOR` / `IMPORTRECIPIENT` / `IMPORTCOSTCENTER` | Mapping-Liste für die CSV-Erzeugung.                                         | Ordnet Datenbankspalten den Pedant-Feldern zu |

**Outputfelder**

Für die Import-Funktionen sind keine Outputparameter definiert. Sie dienen ausschließlich dem Export von Stammdaten nach Pedant.


# Für Developer
Für technische Informationen sieh bitte in unsere [DEV.md](docs/DEV.md)

# Support
Für Informationen im Bereich Support schau bitte in unsere [SUPPORT.md](docs/SUPPORT.md)

# Fußnoten
[^1]: normal = Ein Fallback von pedant falls keine spezifische Auswahl getroffen wurde.

[^2]: check_extraction = Die Rechnung wird erst abgeholt wenn es manuell durch einen Benutzer bestätigt worden ist.

[^3]: skip_review = Erfolgreich erkannte Rechnungen müssen nicht manuell bestätigt werden um abgeholt zu werden.

[^4]: force_skip = Unabhängig vom Erfolg können die Rechnung abgeholt werden.