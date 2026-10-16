# Aktualisierung aus GitHub (Version 1.4.0, neu entworfen)

Systemverwaltung → **Aktualisierung** (nur Systemadministration). Quelle fest im Code: `github.com/VylarisGroup/vylaris-contract`.
Erlaubt sind nur HTTPS-Adressen auf `api.github.com`, `github.com`, `codeload.github.com`, `raw.githubusercontent.com` und GitHubs Download-Hosts.

## Quelle
Verglichen werden die neueste **Release** und der **Hauptzweig `main`** (Datei `VERSION`). Die höhere Versionsnummer gewinnt, bei Gleichstand die Release.
Es genügt also, `VERSION` im Repository zu erhöhen und zu pushen; Releases sind optional. Das Paket muss `src/autoload.php`, `src/Web/App.php`,
`public/index.php`, `public/.htaccess`, `public/assets/app.css` und `VERSION` enthalten, und `VERSION` muss zur ermittelten Version passen.

## Ablauf
1. Voraussetzungen (Anzeige auf der Seite): zip/curl, Schreibrechte, Lesbarkeit der Dateien für den Webserver, freier Speicher, APP_URL.
2. Paket laden (max. 80 MB), SHA-256 berechnen (optional gegen eine von Ihnen angegebene Prüfsumme prüfen), in ein Staging-Verzeichnis entpacken
   (Pfad-, Symlink-, Grössen- und PHP-Syntaxprüfung).
3. Plan berechnen (nur geänderte Dateien), verschlüsselte Sicherung (Datenbank + Dokumente).
4. Wartungsmodus (503; läuft nach 15 Minuten von selbst ab), Rückfall-Journal der betroffenen Dateien anlegen.
5. **Dateien einzeln atomar ersetzen** (temporäre Datei im Zielordner, dann `rename`). Verzeichnisse – insbesondere `public/` – werden **nie** ersetzt oder umbenannt.
   Danach werden Rechte erzwungen: Ordner `0755`, Dateien `0644`, unabhängig von umask/Vorzustand. Jede Datei wird per SHA-256 gegengeprüft.
   Dateien, die ein früheres Update installiert hat und die im neuen Paket fehlen, werden entfernt (Manifest); selbst angelegte Dateien bleiben.
   **Nie angefasst:** `.env`, `storage/`, `SETUP-SCHLUESSEL.txt`, `.htaccess` im Projektstamm, `vendor/`.
6. Datenbank-Migrationen.
7. **Selbstprüfung:** Der Server ruft `APP_URL/anmelden` und `/assets/app.css` selbst ab (mit Einmal-Token, das die Wartungssperre nur für diese zwei Pfade umgeht).
   Antwortet eine davon mit 4xx/5xx oder falschem Inhalt (z. B. 403), wird **automatisch der alte Stand wiederhergestellt**. Ist die Adresse vom Server aus nicht erreichbar,
   wird das als Hinweis ausgegeben (kein Rückfall) – dann die Seite bitte sofort von Hand öffnen.
8. Bei jedem Fehler in Schritt 4–7: Rückfall aller Dateien aus dem Journal, Rechte normalisieren, Wartungsmodus aus.

## Notfallwerkzeuge
- Oberfläche: Schaltfläche **Rechte reparieren**.
- Kommandozeile (SSH/Plesk-Aufgabenplaner): `php bin/repair.php` (Rechte reparieren, Wartungsmodus beenden), `php bin/repair.php --rollback` (Stand vor dem letzten Update).
- Die letzten 3 Rückfallstände liegen in `storage/updates`.

## Grenzen
- Migrationen werden bei einem Rückfall nicht zurückgenommen (additiv halten; im Zweifel Sicherung zurückspielen, docs/BACKUP_RESTORE.md).
- Ein 403, den der Webserver **vor** PHP erzeugt (z. B. falscher Dokumentenstamm oder Besitzer/Rechte von Ordnern ausserhalb der verwalteten Verzeichnisse), wird nur erkannt, wenn der Server sich selbst erreichen kann.
  Dokumentenstamm in Plesk: `<Projekt>/public`.
- Neue PHP-Mindestversion/Abhängigkeiten werden nicht geprüft; gebautes CSS muss im Repository liegen.

## Sicherheit (bewusste Vorgabe)
Keine kryptographische Signaturprüfung (Ihre Vorgabe). Wer das GitHub-Konto/Repository kontrolliert, kann Code auf diesem Server ausführen. Gegenmassnahmen: Host-Allowlist, TLS-Prüfung,
nur Systemadministration mit MFA, Bestätigung pro Update, optionale SHA-256-Vorgabe, SHA-256/Versionen im Audit-Protokoll (`system.update_*`). Empfehlung: MFA und Branch-Schutz im Repository.

## Getestet / nicht getestet
Getestet (automatisch, 9 Tests): Erfolgspfad, Rechte-Erzwingung (auch bei umask 077 und 0700-Ordnern), unveränderter `public/`-Ordner, Rückfall bei Migrations- und Selbstprüfungsfehler,
Manifest-Löschung, SHA-Pin, Zip-Slip, Syntaxfehler, Versionskonflikt, Hostprüfung. Zusätzlich manuell Ende-zu-Ende gegen einen lokalen PHP-Server (echtes Backup, echte Selbstprüfung inkl. Wartungs-Token).
**Nicht getestet:** der echte Abruf von GitHub (in der Entwicklungsumgebung gesperrt), echtes Plesk/nginx-Verhalten.
