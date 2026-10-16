# Aktualisierung aus GitHub

Systemverwaltung → **Aktualisierung** (nur Systemadministration). Quelle ist fest im Code hinterlegt:
`github.com/VylarisGroup/vylaris-contract`, Veröffentlichungen (Releases). Es gibt keine Konfigurationsmöglichkeit für Repository oder URL;
erlaubt sind ausschliesslich HTTPS-Adressen auf `api.github.com`, `github.com`, `codeload.github.com` und GitHubs Download-Hosts.

## Release-Anforderungen
- Release-Tag = Versionsnummer, z. B. `v1.2.0`; die Datei `VERSION` im Paket muss exakt `1.2.0` enthalten.
- Bevorzugt ein angehängtes `.zip`-Asset (z. B. das Installationspaket), sonst das automatische Quellarchiv des Tags.
- Das Paket muss `src/autoload.php`, `src/Web/App.php`, `public/index.php` und `VERSION` enthalten (optional in einem Unterordner).
- Entwürfe und Vorabversionen liefert die GitHub-Schnittstelle für »neueste Release« nicht aus.

## Ablauf
1. Prüfen (Button) → Versionsvergleich, Release-Notizen.
2. Bestätigen → Paket laden (max. 80 MB), SHA-256 berechnen, entpacken in ein Staging-Verzeichnis (Pfad-, Symlink-, Grössen- und PHP-Syntaxprüfung).
3. Verschlüsselte Sicherung (Datenbank + Dokumente) in `storage/backups`.
4. Wartungsmodus (HTTP 503 für alle; Sperre läuft nach 15 Minuten selbst ab).
5. Austausch von `src/ templates/ public/ bin/ database/ docs/ tests/ VERSION README.md composer.json .env.example`.
   **Nie angefasst:** `.env`, `storage/`, `SETUP-SCHLUESSEL.txt`, `.htaccess` im Projektstamm, `vendor/`.
6. Datenbank-Migrationen. Bei jedem Fehler wird der alte Programmstand zurückgespielt (die letzten zwei Rückfallstände liegen in `storage/updates`).

## Voraussetzungen / Grenzen
- PHP-Erweiterungen `zip` und `curl`; Projektordner und die Verzeichnisse oben müssen für den Webserver-Benutzer beschreibbar sein (Plesk: Dateirechte), mind. 300 MB frei, Projektordner und `storage/` auf demselben Datenträger.
- Bereits angewendete Migrationen werden bei einem Rückfall **nicht** zurückgenommen (MySQL-DDL ist nicht transaktional). Migrationen sollen deshalb additiv sein; im Zweifel die Sicherung zurückspielen (docs/BACKUP_RESTORE.md).
- Neue PHP-Mindestversion oder neue Abhängigkeiten eines Releases werden nicht automatisch geprüft.
- Neu gebaute CSS-Dateien müssen im Release enthalten sein (der Server kompiliert nichts).

## Sicherheit (bewusste Vorgabe)
Es erfolgt **keine kryptographische Integritätsprüfung** (Signatur/Prüfsumme gegen eine unabhängige Quelle). Wer das GitHub-Konto bzw. Repository
kontrolliert oder dessen Veröffentlichung manipuliert, kann Code auf diesem Server ausführen. Gegenmassnahmen im Rahmen der Vorgabe: Host-Allowlist,
TLS-Prüfung, nur Systemadministration mit MFA, Bestätigung pro Update, SHA-256 und Versionen im Audit-Protokoll (`system.update_*`).
Empfehlung: Schutz des GitHub-Repositorys (MFA, Branch-/Tag-Schutz, Release nur durch wenige Personen).

## Getestet / nicht getestet
Getestet (automatisch, mit Attrappen für GitHub): Erfolgspfad inkl. Schutz von `.env`/`storage`, Rückfall bei Migrationsfehler, Zugriffsschutz,
Zip-Slip, Syntaxfehler, Versionskonflikt, Hostprüfung. **Nicht getestet:** der echte Abruf von GitHub (in der Entwicklungsumgebung gesperrt),
echte Dateirechte unter Plesk.
