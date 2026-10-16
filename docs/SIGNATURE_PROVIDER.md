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

## Eigene Signatur der Plattform (`internal`)
Unterzeichnen ohne externen Anbieter. Einstellen unter «Einstellungen → Signatur» (oder `SIGNATURE_PROVIDER=internal`). Voraussetzungen: E-Mail-Versand (SMTP) eingerichtet und `APP_URL` mit https.

**Ablauf:** Beim Anfordern erhalten die Unterzeichnenden eine E-Mail mit persönlichem Link (`/unterschreiben/…`, 256-Bit-Token, nur als Hash gespeichert, 30 Tage gültig). Auf der Seite lesen sie das PDF, fordern einen 6-stelligen Einmalcode an die gleiche E-Mail-Adresse an (15 Min. gültig, 5 Versuche, Wiederholung frühestens nach 60 s, Ratenbegrenzung), geben ihren Namen ein, bestätigen die Einwilligung und unterzeichnen. Reihenfolge wahlweise gleichzeitig oder nacheinander. Ablehnen mit Begründung ist möglich; Absender können erinnern (neuer Link, alter ungültig) oder zurückziehen.

**Ergebnis:** Nach der letzten Unterschrift hängt die Plattform ein Signaturprotokoll als zusätzliche Seite an das PDF an (Original bleibt byte-identisch als Präfix; inkrementelles Update), versiegelt das Gesamtdokument mit Ed25519 (Schlüssel aus `APP_KEY` abgeleitet) und aktiviert den Vertrag. Die Prüfung rechnet Siegel und Original nach.

**Grenzen (bewusst ehrlich):**
- Es ist eine **einfache elektronische Signatur (SES)**. Keine fortgeschrittene (AES), keine qualifizierte (QES) nach ZertES. Wo das Gesetz QES verlangt oder Formvorschriften gelten, genügt sie nicht – fachlich prüfen.
- Identifikation nur über den Besitz des E-Mail-Postfachs. Wer das Postfach kontrolliert, kann unterzeichnen.
- Belege: Zeitpunkt, eingegebener Name, IP-Adresse und Browserkennung werden gespeichert und im Protokoll aufgeführt (Datenschutzhinweis an Unterzeichnende steht auf der Seite).
- Das Siegel stammt von der Plattform selbst, nicht von einer unabhängigen Zertifizierungsstelle. Wer die Plattform und den `APP_KEY` kontrolliert, könnte Siegel erzeugen. Wird `APP_KEY` gewechselt, lassen sich alte Siegel nicht mehr mit dem neu abgeleiteten Schlüssel erzeugen; der öffentliche Schlüssel wird je Vorgang mitgespeichert.
- Das Siegel schützt vor nachträglicher Veränderung innerhalb der Plattform; PDF-Programme zeigen keine «gültige Signatur» an.

**Getestet:** Dienst und Ablauf automatisch (Parallel/Nacheinander, Code-Regeln, Ablauf, Ablehnen, Zurückziehen, Siegel, Manipulation, PDF-Struktur mit qpdf) und im Browser mit einem lokalen SMTP-Empfänger. **Nicht getestet:** Zustellung über echten Mailserver/Spamfilter, Mobilgeräte, Plesk-Umgebung.

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
