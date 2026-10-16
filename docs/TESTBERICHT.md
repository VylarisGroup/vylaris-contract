# Testbericht (tatsächlich ausgeführt)

Stand Auslieferung. Umgebung: PHP 8.3.6 (CLI und eingebauter Webserver), MariaDB 10.11, Linux. **Nicht** getestet: echtes Plesk, Apache, Skribble, TLS-SMTP (siehe unten).

## 1. Automatische Tests – `php tests/run.php`
**212 Tests, 212 bestanden, 0 fehlgeschlagen, 938 Assertions.** Alle laufen gegen eine echte MariaDB mit synthetischen Daten (jeder Test in einer zurückgerollten Transaktion).

| Bereich | Tests | Inhalt (Auswahl) |
|---|---:|---|
| Statusmaschine / Vorlagen-Engine (Unit) | 17 | Hauptpfad, verbotene Abkürzungen, Platzhalter, Escaping |
| Vertragsablauf | 23 | Nummernvergabe, Versionen, Sperre, Nachträge, Archivierung, Mandantengrenzen |
| Berechtigungen / Mandantentrennung | 29 | Rollen × Aktionen, manipulierte IDs (404), Sichtbarkeit identisch in Einzelabruf, Suche, Dashboard, Export |
| Anmeldung / MFA | 21 | Argon2id, Sperre/Entsperrung, einheitliche Fehler, TOTP (RFC-6238-Vektoren), Replay, Wiederherstellungscodes, MFA-Pflicht (inkl. Revision/Notariat) |
| Dokumente | 20 | Upload-Prüfung (Magic Bytes, Endungen, ZIP-Bombe), Hash bei Download, DOCX-Import/-Export, PDF-Export |
| Freigabe / Signatur | 20 + Teile von 23 | Vier-Augen, gestufte Freigabe, nur freigegebene Version signierbar, Sandbox wird nie »aktiv«, unbekannter Status ≠ signiert, Anbieterfehler erzeugen keinen Erfolgsstatus |
| Externe Portale / Mandate | 20 | abgelaufene/widerrufene Mandate, keine internen Kommentare für Gegenpartei, Revision schreibgeschützt, Notariat nur freigegebene Version |
| Integritäts-Trigger | 9 | Änderung/Löschung gesperrter Versionen, Audit, Belege wird von der Datenbank abgewiesen |
| Compliance | 15 | Legal Hold blockiert Löschung, Aufbewahrung, Auskunft/Löschanfragen |
| Fristen / Benachrichtigungen / Cron | 12 | Fristberechnung, Idempotenz, Mailfehler verlieren die Aufgabe nicht (Wiederholung, »dead«) |
| Verwaltung / Vorlagen | 24 | Mandanten-/Benutzerverwaltung, Vorlagenprüfung (Vier-Augen) |
| Sicherung (Kryptographie) | 3 | Ver-/Entschlüsselung, falsche Passphrase, Manipulation, abgeschnittene Datei, Manifest-Prüfung |

Abdeckung der 10 geforderten Szenarien: (1) fremde ID → 404; (2) Gegenpartei sieht keine internen Kommentare; (3) Revision kann nichts ändern; (4) Notariat bekommt keine nicht freigegebene Version; (5) Signatur nur für die freigegebene Version; (6) abgeschlossener Stand nicht überschreibbar (Anwendung **und** Trigger); (7) Aufbewahrungssperre verhindert Löschung; (8) fehlgeschlagener API-Aufruf → kein falscher Erfolgsstatus; (9) fehlgeschlagener Mailversand → Aufgabe bleibt; (10) siehe 2.

## 2. Wiederherstellungstest – `php tests/restore_roundtrip.php`
Bestanden (11 Prüfungen): verschlüsselte Sicherung → Verifikation → Wiederherstellung in zweite Datenbank → Zeilenzahlen identisch, Audit-Kette intakt, Kettenkopf identisch, Dokument-Hashes stimmen, 16 Trigger vorhanden, Protokoll nach Restore weiter änderungsgeschützt, Restore in nicht leere Datenbank verweigert, falsche Passphrase abgelehnt. Die Testdaten enthalten absichtlich Sonderzeichen, Zeilenumbrüche, NUL-Bytes und SQL-Trennzeichen.

## 3. End-to-End-Test der Weboberfläche über HTTP (manuell skriptgesteuert)
Frisch entpacktes Auslieferungs-ZIP, leere Datenbank, PHP-Entwicklungsserver, Skript-Client mit Cookies:
- **Installations-Assistent**: Voraussetzungsprüfung, Setup-Schlüssel, Datenbank, `.env` (0600), Migrationen, Sperre; Schlüsseldatei wird gelöscht; `/setup` danach gesperrt.
- **Anmeldung**: Pflicht-MFA-Einrichtung, TOTP-Login, Wiederherstellungscode (einmalig), Replay-Schutz, Passwortänderung (beendet andere Sitzungen), Sitzungs-ID-Wechsel, Kontosperre nach Fehlversuchen, identische Fehlermeldungen (Konto vorhanden/nicht vorhanden), Passwort-Reset ohne Konto-Enumeration.
- **Ablauf**: Mandant anlegen → Einladung per E-Mail (echter SMTP-Client gegen lokalen Testserver) → Benutzer einladen/annehmen → Vertrag anlegen → Text speichern (Skript-Tag wird entfernt) → Freigabe anfordern (Version gesperrt) → Freigabe durch zweite Person → Signaturvorgang (Sandbox) → »unterzeichnet« simulieren: Vertrag bleibt **»In Unterzeichnung«** (wird nicht aktiv, Testbeleg klar gekennzeichnet).
- **Weitere Formulare**: Gegenpartei, Vorlage (anlegen, Prüfung, Vier-Augen-Freigabe, Vertrag daraus), Upload (gültiges PDF ok; Fälschung, .exe und zu grosse Datei abgewiesen), DOCX-Import, Downloads (PDF/DOCX/CSV), Fristen, Aufgaben, Kommentare, Nachtrag, rechtliche Sperre, Aufbewahrungsregel, Datenschutzanfrage, Mandanteneinstellungen (MFA-Pflicht wirkt sofort), Vertragsarten/Felder/Abteilungen, Mandate für Revision und Notariat, Notariatsfall (anlegen, bereitstellen, Status), Revisionsansicht/-bericht.
- **Sicherheit**: POST ohne/mit falschem CSRF-Token und mit fremdem Origin → 419; GET auf Abmelden → 405; anonyme Zugriffe → Weiterleitung zur Anmeldung; `.env`, `src/`, `storage/`, Migrationen, `..`-Pfade über das Web → 404; offene Weiterleitung über `weiter=` unterbunden; **Mandantentrennung** über zwei Mandanten (Ansehen, Bearbeiten, Export, Kommentar, Archivieren fremder Verträge → 404; Suche/Vorschläge ohne Treffer); Revisions-/Notariatsnutzer sehen nur ihre Mandatsverträge und keine Verwaltung; Seitenrundgang (alle GET-Routen × 5 Rollen) ohne einen einzigen Serverfehler.
- **Betrieb**: `bin/cron.php` (alle Teilaufgaben erfolgreich), `bin/backup.php` (45 Tabellen, Dokumente, verschlüsselt, automatisch verifiziert), `bin/restore.php --verify` und Restore in zweite Datenbank (Audit-Kette und Hashes in Ordnung); manipuliertes Archiv und falsche Passphrase werden abgelehnt.

Beim End-to-End-Test gefundene und behobene Fehler: **Icon-Schrift fehlte im ZIP** (Build-Ausschluss zu breit), Menü-Knopf auf Desktop sichtbar, zu enge Formularabstände, nicht vorhandene Methode beim Dokumentenlisten, falsche Spaltennamen in der Diagnose (Audit-Anker), falscher Migrationsvergleich in der Diagnose, Datenschutzanfrage ohne Gegenpartei führte zur Fehlerseite, fehlender Standard-Zuständiger beim Anlegen, Auswahl der Freigebenden zeigte Personen ohne Freigaberecht, `getopt`-Parsing in den Skripten, zu grosse Uploads zeigten »Sitzung abgelaufen«, Fehlermeldungen bei Eingabefehlern waren zu unspezifisch, Revision/Notariat hatten keine MFA-Pflicht.

## 4. Nicht getestet / offen
- **Skribble** gegen die Demo- oder Produktions-API (nur nachgebildete Antworten) – zwingend vor Produktivbetrieb.
- **SMTP mit TLS/STARTTLS/AUTH** gegen einen echten Anbieter.
- **Plesk/Apache**: `.htaccess`-Regeln, PHP-Handler, Cron-Ausführung, Dateirechte; nur mit dem PHP-Entwicklungsserver geprüft.
- Browser: Nur **Chromium** (Playwright) wurde verwendet: Anmeldeseite, Übersicht, Vertragsseite in Hell/Dunkel, Mobilansicht (390 px, kein horizontales Scrollen, Menü-Knopf funktioniert) wurden per Screenshot geprüft. Firefox/Safari, Screenreader und ein Barrierefreiheitsaudit fehlen; die übrigen Seiten wurden nicht einzeln visuell geprüft.
- Lasttests, externer Penetrationstest, Barrierefreiheitsaudit, juristische Prüfung.

## Nachtrag: Aktualisierung aus GitHub (Version 1.1.0)
Automatische Tests (`tests/Integration/UpdateTest.php`, 9 Tests, Version 1.4.0): Erfolgspfad, erzwungene Rechte, unveränderter public/-Ordner, Rückfall bei Migrations- und Selbstprüfungsfehler, Manifest-Löschung, SHA-Pin, Reparatur, Zugriffsschutz, Zip-Slip/Syntax/Versionskonflikt, Host-Allowlist. Manuell Ende-zu-Ende gegen lokalen PHP-Server.
Gesamtstand: 217 Tests grün. GitHub wurde dabei durch Attrappen ersetzt; der echte Netzabruf und Dateirechte unter Plesk sind ungetestet.

## Nachtrag: Verwaltung über die Oberfläche (Version 1.2.0)
Automatische Tests (SettingsTest, 2 Tests): Überschreiben/Zurücksetzen, Verschlüsselung der Geheimnisse, Validierung, Zugriffsschutz, Ignorieren fremder Schlüssel.
Zusätzlich manueller HTTP-Durchlauf gegen eine frische Instanz (PHP-Server, MariaDB): Einstellungen speichern/ablehnen, Wirkung auf den Mailversand,
Sicherung erstellen/prüfen/herunterladen/löschen, Pfadmanipulation (404), Cron von Hand. Gesamtstand 219 Tests grün. Nicht getestet: Optik in Browsern, TLS-Versand.

## Passkeys / 2FA freiwillig (1.5.0)
Tests: `tests/Security/PasskeyTest.php` (9 Tests, simulierter Authenticator, ES256 und RS256) und angepasste `AuthTest` (2FA nirgends Pflicht). Zusätzlich Browserdurchlauf mit virtuellem Authenticator in Chromium. Nicht getestet: echte Geräte/Sicherheitsschlüssel, Safari, Firefox. Siehe docs/PASSKEYS.md.
