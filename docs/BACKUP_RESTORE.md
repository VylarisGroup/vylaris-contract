# Sicherung und Wiederherstellung

## Sicherung
`php bin/backup.php [--keep=14] [--dir=/pfad]` (täglich per Cron). Das Archiv liegt in `storage/backups/` (ausserhalb des Webroots) und enthält:
- `db.sql` – alle Tabellen, Daten und die 16 Integritäts-Trigger (konsistenter Schnappschuss, reines PHP – kein `mysqldump` nötig),
- `files/…` – alle abgelegten Dokumente,
- `manifest.json` – SHA-256 je Eintrag.

Der Setup-Assistent erzeugt eine zufällige `BACKUP_PASSPHRASE` in `.env`; damit wird das Archiv verschlüsselt (libsodium, XChaCha20-Poly1305 im Datenstrom, Schlüssel per Argon2id). **Ohne Passphrase enthält die Datei Klartext-Personendaten.** Die Passphrase getrennt vom Archiv aufbewahren; ohne sie ist das Archiv nicht lesbar. Nach jeder Sicherung wird das Archiv automatisch entschlüsselt und gegen das Manifest geprüft.

**Kopieren Sie die Archive regelmässig an einen anderen Ort** (anderer Server/Speicher). Eine Sicherung im selben Konto schützt nicht vor Kontoverlust. Mit `.env` (APP_KEY!) sichern Sie separat: ohne `APP_KEY` sind MFA-Geheimnisse nach einer Wiederherstellung unbrauchbar (Benutzer müssen MFA neu einrichten).

## Prüfen ohne Wiederherstellung
`php bin/restore.php <archiv> --verify` – entschlüsselt und prüft alle Hashes, ändert nichts.

## Wiederherstellung / Wiederherstellungstest
1. Leere Datenbank anlegen (Plesk), Benutzer mit allen Rechten inkl. TRIGGER.
2. `php bin/restore.php <archiv> --db=NEUE_DB [--storage=/neuer/pfad] [--user=… --pass=… --host=…]`
3. Das Skript spielt Datenbank und Dateien ein und **prüft danach die Audit-Kette und alle Dokument-Hashes**. Exit-Code 0 = bestanden.
4. Für den Echtbetrieb: `.env` auf die neue Datenbank (und `STORAGE_PATH`) umstellen, Diagnose öffnen.

Das Skript weigert sich, in die in `.env` konfigurierte Live-Datenbank oder den Live-Speicher zu schreiben und verlangt eine **leere** Zieldatenbank. Führen Sie den Wiederherstellungstest mindestens quartalsweise durch und dokumentieren Sie ihn.

**Teststand:** Sicherung → Verschlüsselung → Prüfung → Wiederherstellung in eine zweite Datenbank wurde mit realen Daten (4 Verträge, 270 Protokolleinträge, 4 Dokumente, 16 Trigger) durchgeführt; Audit-Kette und Dokument-Hashes waren danach in Ordnung. Manipulierte Archive und falsche Passphrasen werden abgelehnt (automatische Tests).
