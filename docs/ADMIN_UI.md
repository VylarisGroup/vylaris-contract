# Verwaltung über die Oberfläche (Systemadministration)

Alles Betriebliche ist unter **Systemverwaltung** erreichbar (nur Systemadministration; zweiter Faktor und Passkeys sind freiwillig, siehe PASSKEYS.md):

| Bereich | Seite | Inhalt |
|---|---|---|
| Allgemein | /system/einstellungen/allgemein | Name, Markenfarbe, URL, Upload-Limit, Proxy-IPs |
| E-Mail | /system/einstellungen/email | SMTP-Zugang inkl. Testmail |
| Signatur | /system/einstellungen/signatur | Signaturanbieter (Sandbox/Skribble) |
| Sicherungen | /system/sicherungen | Sicherungen erstellen/prüfen/herunterladen; Aufbewahrung (Anzahl) |
| Sicherungen | /system/sicherungen | Erstellen (mit automatischer Prüfung und Aufräumen), Prüfen, Herunterladen, Löschen |
| Aktualisierung | /system/aktualisierung | Aus GitHub, siehe UPDATE.md |
| Cron | /system | Aufgaben von Hand ausführen; Status je Aufgabe |
| Mandanten, Diagnose, Audit-Kette | /system | wie bisher |

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
