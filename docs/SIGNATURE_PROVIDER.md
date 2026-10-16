# Elektronische Signatur (Anbieter-Adapter)

Die Plattform spricht Signaturanbieter über eine neutrale Schnittstelle (`SignatureProvider`) an. Vorhanden: **Sandbox** (Standard) und **Skribble**.

## Grundsätze (im Code erzwungen)
- Ein Vertrag wird **nie** durch eine reine Statusmeldung des Anbieters »aktiv«. Er wird erst aktiv, wenn der Anbieter den Status »signiert« liefert **und** das signierte Dokument abgerufen und mit Hash abgelegt werden konnte; im Sandbox-Modus passiert das **nie**. Die »erreichte Stufe« bleibt **UNBEKANNT**, solange keine Gültigkeitsprüfung »valid« gemeldet hat.
- Unbekannte Anbieter-Statuswerte werden als »unbekannt« behandelt – nie als »gültig«/»signiert«.
- Die Gültigkeitsprüfung (`validate`) liefert, solange kein geprüfter Prüfendpunkt angebunden ist, immer »nicht validiert«. Es gibt keine eigene Signaturprüfung und keine gefälschte QES.
- Die Signaturstufe (SES/AES/QES) wird vom Anbieter bestimmt; welche Stufe **rechtlich erforderlich** ist, entscheiden Sie fachlich (Formvorschriften des Vertragstyps). Die Plattform zeigt Stufe und Anbieter-Modus am Vorgang an.
- Der Testmodus ist überall sichtbar gekennzeichnet (Banner, Hinweis am Vorgang, Beleg »TESTMODUS – nicht rechtsgültig«).

## Sandbox
Simuliert Antworten (»unterzeichnet/abgelehnt simulieren« am Vorgang). Erzeugt nur einen Testbeleg. Vollständig getestet.

## Skribble einrichten
1. Skribble-Konto mit API-Zugang beschaffen (Vertrag/API-Key, Benutzername). Die Schnittstellen-Basis-URL (Demo bzw. Produktion) und die Zugangsdaten entnehmen Sie der Skribble-Dokumentation und Ihrem Konto.
2. In `.env`: `SIGNATURE_PROVIDER=skribble`, `SKRIBBLE_BASE_URL=https://…` (**muss mit https:// beginnen, kein Standardwert**), `SKRIBBLE_USER`, `SKRIBBLE_KEY`, `SKRIBBLE_MODE=sandbox` (Demo) bzw. `live`.
3. Zuerst gegen die **Demo-Umgebung** testen (voller Ablauf: Anfrage → Unterzeichnung → Status → signiertes Dokument → Beleg), erst dann `live`.

## Was geprüft ist – und was nicht
| Punkt | Stand |
|---|---|
| Adapter-Logik mit nachgebildeten Antworten (Anmeldung, Anfrage, Statusabfrage, Zurückziehen, Fehlerfälle, unbekannte Status) | automatisch getestet |
| Endpunkte/Felder laut Anbieter-Dokumentation | aus Dokumentation übernommen, **nicht gegen die echte oder die Demo-API getestet** |
| Exakte Statuswerte des Anbieters | **unverifiziert** → unbekannte Werte werden nie als »signiert« gewertet; Zuordnung ist konfigurierbar (`statusMap`), nachdem sie in der Demo bestätigt wurde |
| Feld für die gewünschte Signaturstufe | **unverifiziert** |
| Webhooks | nicht implementiert; Status per Abfrage (Knopf »Status abfragen« und Cron) |
| Signaturbericht/Prüfung der Gültigkeit | nicht angebunden (»nicht validiert«) |

**Vor dem Produktivbetrieb ist ein Test gegen die Skribble-Demo zwingend.** Passen Statuswerte oder Felder nicht, ist die Anpassung auf `src/Modules/Signatures/SkribbleProvider.php` begrenzt.
