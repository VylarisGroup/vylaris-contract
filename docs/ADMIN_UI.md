# Verwaltung über die Oberfläche (Systemadministration)

Alles Betriebliche ist unter **Systemverwaltung** erreichbar (nur Systemadministration; zweiter Faktor und Passkeys sind freiwillig, siehe PASSKEYS.md):

| Menü | Seite | Inhalt |
|---|---|---|
| System › Übersicht | /system | Kacheln mit Kurzstatus |
| System › Mandanten | /system/mandanten | Mandanten anlegen, sperren/aktivieren |
| System › Diagnose | /system/diagnose | Zustand von PHP, DB, Rechten, Schlüsseln |
| System › Audit-Kette | /system/audit-kette | Integrität des Protokolls prüfen |
| System › Aufgaben | /system/aufgaben | Cron-Aufgaben von Hand ausführen, Status |
| Einstellungen › Darstellung | /system/einstellungen/darstellung | Name, Markenfarbe |
| Einstellungen › Adresse & Netzwerk | …/adresse | APP_URL (nur lesbar), vertrauenswürdige Proxys |
| Einstellungen › Uploads | …/uploads | Upload-Limit |
| Einstellungen › E-Mail-Server | …/email-server | SMTP-Zugang inkl. Testmail |
| Einstellungen › Absender | …/absender | Absenderadresse und -name |
| Einstellungen › Signatur | …/signatur | Signaturanbieter (Sandbox/Skribble) |
| Einstellungen › Skribble | …/skribble | Zugangsdaten Skribble |
| Betrieb › Sicherungen | /system/sicherungen | Erstellen, prüfen, herunterladen, löschen; Aufbewahrung |
| Betrieb › Aktualisierung | /system/aktualisierung | Aus GitHub, siehe UPDATE.md |

Mandanten-Verwaltung: `/verwaltung` (Hub), `/verwaltung/mandant`, `/verwaltung/vertragsarten`, `/verwaltung/felder`.
Konto: `/konto` (Profil), `/konto/passwort`, `/konto/mfa`, `/konto/passkeys`, `/konto/sitzungen`.

## Speicherung
Einstellungen liegen in der Tabelle `system_settings` (Schlüssel `cfg.NAME`). Geheimnisse (SMTP-Passwort, Skribble-Schlüssel) werden mit `APP_KEY`
verschlüsselt und nie angezeigt (Feld leer lassen = unverändert). Ein gespeicherter Wert hat Vorrang vor der `.env`; »auf .env-Wert zurücksetzen«
löscht ihn. Änderungen wirken ab der nächsten Anfrage; jede Änderung steht im Audit-Protokoll (nur Schlüsselnamen, nie Werte).
Auch `bin/cron.php` und `bin/backup.php` verwenden die gespeicherten Werte.

## Bewusst nur in der .env
Datenbankzugang, `APP_KEY`, `STORAGE_PATH` und `BACKUP_PASSPHRASE`. Grund: Ohne diese Werte ist die Anwendung nicht startbar bzw. die Passphrase darf nicht in der
Datenbank liegen, die sie schützt (sie steckt sonst in jeder Sicherung). Die Wiederherstellung einer Sicherung geschieht ebenfalls bewusst nur auf der
Kommandozeile in eine separate Datenbank (BACKUP_RESTORE.md).

## Hinweise
- Wird `APP_KEY` gewechselt, sind gespeicherte Geheimnisse nicht mehr lesbar; es gilt dann der `.env`-Wert, die Felder neu setzen.
- Das Upload-Limit wird zusätzlich vom PHP-Limit des Servers begrenzt (Plesk → PHP-Einstellungen).
- Skribble: Es gibt keinen Verbindungstest in der Oberfläche; Live-Betrieb erst nach dem Test gemäss SIGNATURE_PROVIDER.md.

## Hilfe & Anleitungen
`/hilfe` zeigt Schritt-für-Schritt-Anleitungen, gefiltert nach Rolle (Mitarbeitende, Administration, Systemadministration). Auf vielen Seiten verweist «Anleitung zu dieser Seite» direkt auf das passende Thema. Die Texte stehen zentral in `src/Web/HelpTopics.php`; `php bin/guides-export.php` erzeugt daraus `docs/ANLEITUNGEN.md`. Die Übersicht der Systemadministration zeigt eine Einrichtungs-Checkliste (Mandant, E-Mail, Cronjob, Sicherung).
