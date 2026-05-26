Inhaltsverzeichnis
- [API-Umgebungen](#api-umgebungen)
- [Support-Fälle und Maßnahmen](#support-fälle-und-maßnahmen)
  - [HTTP-Codes im Support](#http-codes-im-support)
  - [Typische Fehlermeldungen und was sie bedeuten](#typische-fehlermeldungen-und-was-sie-bedeuten)
  - [Praktische Support-Regel für Max-Counter-Fälle](#praktische-support-regel-für-max-counter-fälle)
  - [Besonderheiten von `fetchData` im Support](#besonderheiten-von-fetchdata-im-support)
- [Fußnoten](#fußnoten)


# API-Umgebungen

- Wenn `DEMO` aktiv ist, werden Rechnungs-, Fetch- und Classifier-Requests gegen `https://api.demo.pedant.ai` ausgeführt.

- In der Produktivumgebung laufen Rechnungs-, Fetch- und Classifier-Requests gegen `https://api.pedant.ai`.

- Entity-Importe laufen in der Produktivumgebung gegen `https://entity.api.pedant.ai`.


# Support-Fälle und Maßnahmen

Die folgenden Hinweise sind als praktische Erstmaßnahmen für den Support gedacht. Ziel ist nicht, jede Störung sofort zu eskalieren, sondern zunächst sauber zwischen Konfigurationsfehlern, transienten Plattformproblemen und echten Umgebungsproblemen zu unterscheiden.

Um Loginformationen zu erhalten, bearbeite die Datei `config.php` und setze den `log_level` auf den gewünschten Status. Die Logs werden unter `pedant/logs/log/` angelegt.

Für genauere Informationen siehe bitte in unserer [DEV.md](./DEV.md) unter dem Punkt 'Vorstellung von Datei: config.php'

## HTTP-Codes im Support

| HTTP-Code                       | Bedeutung in der aktuellen Implementierung                                                                                                                                      | Empfohlene Erstmaßnahme                                                                                                                                                                            |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `200` / `201`                   | Erfolgreiche Antwort.                                                                                                                                                           | Keine Maßnahme außer fachlicher Prüfung des Ergebnisses.                                                                                                                                           |
| `404` bei `checkFile()`         | In der Rechnungs-Statusprüfung gibt es eine Sonderbehandlung. Kurz nach dem Upload kann das Dokument noch nicht verfügbar sein, daher wird zunächst erneut versucht.            | Vorgang nicht sofort umbauen. Nächste Wiedervorlage abwarten oder den Prozess einmal reaktivieren. Wenn der Fehler dauerhaft bleibt, Upload-Erfolg, `FILEID` und `TYPE` prüfen.                    |
| `500`, `502`, `503`, `0`        | Diese Codes sind im Code als retrybare Zustände hinterlegt. Das spricht im Support meistens eher für ein temporäres Pedant- oder Verbindungsproblem als für ein Mappingproblem. | Erst Logs prüfen, dann einmal reaktivieren oder die nächste Wiedervorlage abwarten. Wenn mehrere Vorgänge betroffen sind oder der Fehler bestehen bleibt, an Pedant bzw. Infrastruktur eskalieren. |
| andere nicht erfolgreiche Codes | Diese Fälle sind im Support häufiger Konfigurations-, ID- oder Parameterprobleme als reine Plattformprobleme.                                                                   | Nicht blind reaktivieren. Erst Parameter, IDs, Statuswerte, Mapping und API-Key prüfen.                                                                                                            |

## Typische Fehlermeldungen und was sie bedeuten

| Meldung                                                                              | Bedeutung                                                                                                                                         | Empfohlene Maßnahme                                                                                                                                |
| ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Upload file does not exist`                                                         | Die Eingabedatei ist im erwarteten Uploadpfad nicht vorhanden.                                                                                    | Dokumentfeld, Uploadpfad und Prozesskonfiguration prüfen. Erst danach neu starten.                                                                 |
| `File size exceeds the maximum limit ...`                                            | Das Dokument ist größer als der konfigurierte oder der Default-Grenzwert.                                                                         | Datei verkleinern oder `MAXFILESIZE` bewusst erhöhen.                                                                                              |
| `Invalid input parameter value for FLAG` / `FLAGXML` / `DC_ACTION`                   | Ein Steuerparameter enthält keinen erlaubten Wert.                                                                                                | Konfiguration korrigieren, erst dann den Schritt neu ausführen.                                                                                    |
| `Invalid invoice status: ...`                                                        | `fetchData` wurde mit einem nicht unterstützten Status befüllt.                                                                                   | Zulässige Werte verwenden: `reviewed`, `exported`, `rejected`, `archived`.                                                                         |
| `cURL request failed: ...`                                                           | Die Verbindung von JobRouter zu Pedant ist technisch gescheitert.                                                                                 | Einmal erneut versuchen. Wenn der Fehler bleibt, DNS, Proxy, Firewall, Netzwerk oder Erreichbarkeit prüfen.                                        |
| `Failed to parse API response: ...`                                                  | Pedant hat keine erwartete JSON-Antwort geliefert oder es kam eine Fehlerseite bzw. leere Antwort zurück.                                         | Logauszug und falls vorhanden `TEMPJSON` prüfen. Bei Einzelfall einmal reaktivieren, bei Reproduzierbarkeit mit Incident und Logauszug eskalieren. |
| `Invalid API response: missing files data` / `missing data`                          | Die Antwort ist zwar technisch angekommen, enthält aber nicht die erwartete Struktur.                                                             | Nicht nur Mapping prüfen, sondern auch den API-Rohinhalt aus Log oder `TEMPJSON` sichern und bei Wiederholung eskalieren.                          |
| `Error occurred during upload after maximum retries (...)`                           | Wiederholte Upload-Versuche haben keinen stabilen verwertbaren Erfolg gebracht. Das ist typischerweise kein einfacher „nochmal warten“-Fall mehr. | Letzten HTTP-Code und Parameter prüfen, dann API-Key, Datei, Flags und Logs kontrollieren.                                                         |
| `Error occurred during file extraction after maximum retries (...)`                  | Die Statusprüfung hat trotz mehrerer Versuche keinen brauchbaren Endzustand erreicht.                                                             | `FILEID`, `TYPE`, Pedant-Status und Logs prüfen. Erst dann reaktivieren oder eskalieren.                                                           |
| `Error occurred during document classifier upload/check after maximum retries (...)` | Der Classifier hat den Upload oder die Abfrage nicht erfolgreich abgeschlossen.                                                                   | `DC_ACTION`, Datei, API-Key und letzten HTTP-Code prüfen.                                                                                          |
| `Error occurred during vendor/recipient/costCenter update. HTTP Error Code: X`       | Der Stammdatenimport wurde von der API nicht erfolgreich angenommen.                                                                              | Bei `500/502/503/0` einmal erneut starten, sonst zuerst Tabelle, Mapping und Dateninhalt prüfen.                                                   |
| `Unsupported database type` / `Database could not be detected`                       | Die Umgebung passt nicht zur erwarteten Datenbankerkennung.                                                                                       | Kein reiner Prozessfehler. Umgebung und Datenbankanbindung prüfen.                                                                                 |

## Praktische Support-Regel für Max-Counter-Fälle

- Wenn wirklich „maximum retries erreicht“ geworfen wird, zuerst den letzten HTTP-Code und die Konfiguration prüfen.

- Bei `500/502/503/0` ist ein erneuter Versuch sinnvoll, weil diese Codes im Modul als transient behandelt werden.

- Bei ungültigen Parametern, falschen Statuswerten oder fehlenden Dateien muss zuerst die Konfiguration korrigiert werden.

- `COUNTERSUMMARY` und die Tageslogdatei sind die schnellsten Quellen, um zu sehen, ob ein Schritt nur temporär hängt oder sauber falsch konfiguriert ist.

## Besonderheiten von `fetchData` im Support

- `fetchData` bricht bei vielen API-Problemen nicht hart ab, sondern protokolliert Warnungen und überspringt einzelne Requests oder Seiten.

- Wenn wartende Prozesse nicht „aufwachen“, immer zuerst die Logdatei auf Meldungen wie `Fetch invoices request failed`, `Failed to parse fetch invoices response` oder `Failed to update resubmission date for invoice` prüfen.

- Zusätzlich prüfen, ob `TABLEHEAD`, `STEPID` und vor allem `FILEID` als Spaltenname korrekt konfiguriert wurden.

# Fußnoten

[^info]: Protokolliert den Arbeitsablauf: welche Methode aufgerufen wurde, was abgerufen wurde, Änderungen des Upload-/Prüfstatus, Importfortschritt. Protokolliert außerdem alle Warnungen und Fehler. Gut geeignet für die tägliche Überwachung.

[^warning]: Protokolliert unerwartete Situationen, die keine Funktionsstörungen verursachen: Wiederholungsversuche, fehlgeschlagene Dateibereinigungen, fehlende optionale Daten. Protokolliert außerdem alle Fehler

[^error]: Protokolliert nur Ausnahmen und schwerwiegende Fehler. Minimale Ausgabe. Für den Einsatz in stabilen Produktionsumgebungen.

[^debug]: Protokolliert ALLES: Abfragen, Variablen, Daten nach jeder Änderung, vollständige API-Anfrage- und Antworttexte. Die Protokolldateien wachsen schnell an (GB). Nur zur Fehlerbehebung bei bestimmten Problemen verwenden.