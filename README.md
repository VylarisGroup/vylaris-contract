# Vertragsplattform

Mandantenfähige Vertragsverwaltung für die Schweiz und die EU – PHP 8.3 ohne Framework, MySQL/MariaDB, Tailwind (vorgebaut), Boxicons, Vanilla-JS, ausschliesslich Deutsch. Läuft auf Plesk-Shared-Hosting **ohne Composer/Node/Build** und ohne Dauerprozesse.

> **Wichtig:** Die Plattform ist ein Werkzeug. Sie ist **nicht** »DSG/DSGVO-konform« oder »rechtssicher« – das hängt von Konfiguration, Prozessen und fachlicher Prüfung ab (`docs/LEGAL_CHECKLIST.md`). Signaturen laufen standardmässig im **Testmodus** ohne Rechtswirkung. Lesen Sie `docs/KNOWN_LIMITATIONS.md`.

## Schnellstart (Plesk)
1. Paket im Domain-Ordner entpacken, **Dokumentenstamm auf `public/` setzen**, leere Datenbank (Benutzer mit TRIGGER-Recht) anlegen, HTTPS aktivieren.
2. `https://IHRE-DOMAIN/setup` öffnen; den Setup-Schlüssel aus `SETUP-SCHLUESSEL.txt` eingeben.
3. Cronjob `php bin/cron.php` (alle 5 Min.) und täglich `php bin/backup.php` einrichten.
4. *System → Diagnose* abarbeiten.

Ausführlich: `docs/INSTALLATION_PLESK.md`.

## Funktionsumfang
Mandanten, 8 Rollen mit Berechtigungen und Geltungsbereich · Anmeldung mit Argon2id, freiwillige Zwei-Faktor-Anmeldung (TOTP + Wiederherstellungscodes) und Passkeys (WebAuthn), Sperren, Rate-Limit, Sitzungshärtung, CSRF, CSP · Verträge mit unveränderlichen Versionen, Nachträgen, Archivierung statt Löschen, Fristen, Aufgaben, Kommentaren, Tags, benutzerdefinierten Feldern, Gegenparteien · Vorlagen/Klauseln mit Platzhaltern und Vier-Augen-Prüfung · DOCX-Import, PDF-/DOCX-Export, Uploads mit Prüfung und Hash · gestufte Freigabe (Vier-Augen) · Signatur über Anbieter-Adapter (Sandbox, Skribble) · Portale für Revision und Notariat über befristete Mandate · Hash-verkettetes Audit-Protokoll mit Ankern · rechtliche Sperren, Aufbewahrungsregeln mit Rechtsgrundlage, Auskunfts-/Löschanfragen · Suche/Dashboard/CSV-Export ohne Datenlecks · Verwaltung, Systemdiagnose, Installationsassistent · verschlüsselte Sicherung mit Wiederherstellungstest.

## Verzeichnisse
| Pfad | Inhalt |
|---|---|
| `public/` | **einziges Webroot**: Front Controller, Assets (fertiges CSS, Icons, JS) |
| `src/` | PHP-Code (Controller, Services, Repositories, Module); eigener Autoloader |
| `templates/` | HTML-Vorlagen (kein SQL, keine Geschäftslogik) |
| `database/migrations/` | Schema, Rollen/Berechtigungen, Trigger |
| `bin/` | `cron.php`, `backup.php`, `restore.php`, `migrate.php` |
| `storage/` | Dokumente, Sitzungen, Logs, Sicherungen (nicht im Web erreichbar) |
| `docs/` | Dokumentation |
| `tests/` | Automatische Tests (`php tests/run.php`, `php tests/restore_roundtrip.php`) |

## Dokumentation
`ANLEITUNGEN` (Schritt-für-Schritt, auch in der App unter «Hilfe») · `INSTALLATION_PLESK` · `CRON` · `SMTP` · `SIGNATURE_PROVIDER` · `BACKUP_RESTORE` · `SECURITY` · `ROLES_PERMISSIONS` · `DATA_MODEL` · `KNOWN_LIMITATIONS` · `LEGAL_CHECKLIST` · `DEPENDENCIES` · `TESTBERICHT` (alle in `docs/`), Konfigurationsvorlage `.env.example`.
