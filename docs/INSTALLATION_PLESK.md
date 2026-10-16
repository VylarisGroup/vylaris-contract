# Installation auf Plesk (Shared Hosting)

Die Anwendung braucht **kein Composer, kein Node, keinen Build** auf dem Server. Das Tailwind-CSS und die Icon-Schrift liegen fertig im Paket.

## Voraussetzungen
- PHP **8.3 oder neuer** (Plesk → Websites & Domains → *PHP-Einstellungen*), Erweiterungen: `pdo_mysql`, `mbstring`, `sodium`, `zip`, `curl`, `openssl`, `dom`, `fileinfo`, `zlib`.
- MySQL 8 oder MariaDB 10.6+ (InnoDB, utf8mb4).
- Eine Domain/Subdomain mit **HTTPS** (Plesk → *SSL/TLS-Zertifikate*, z. B. Let's Encrypt). Ohne HTTPS verweigert der Assistent die Installation.
- Mindestens ein Plesk-Cronjob (siehe `CRON.md`).

## Schritte
1. **Paket entpacken.** Plesk → *Dateien* → in das Domain-Verzeichnis hochladen und entpacken (z. B. nach `/httpdocs/../vertraege/`, **nicht** nur in `httpdocs`). Es entsteht ein Ordner mit `public/`, `src/`, `templates/`, `storage/`, `bin/` …
2. **Dokumentenstamm auf `public/` setzen.** Plesk → *Hosting-Einstellungen* → *Dokumentenstamm* = `<Projektordner>/public`. **Das ist zwingend**: Alles ausser `public/` (Code, `.env`, Dokumente, Sicherungen) darf nicht über das Web erreichbar sein. Kann Ihr Tarif den Dokumentenstamm nicht ändern, installieren Sie nicht.
3. **Datenbank anlegen.** Plesk → *Datenbanken* → neue leere Datenbank + Benutzer mit **allen Rechten, insbesondere `TRIGGER`** (sonst fehlen die Datenbank-Sperren gegen nachträgliche Änderung; die App warnt dann in der Diagnose).
4. **PHP-Limits** (Plesk → PHP-Einstellungen): `upload_max_filesize` und `post_max_size` mindestens so gross wie »Maximale Dateigrösse« (Standard 20 MB), `memory_limit` ≥ 128M.
5. **Setup-Schlüssel lesen.** Im Projektordner liegt `SETUP-SCHLUESSEL.txt` (Plesk-Dateimanager). Der Schlüssel ist für **dieses** Paket einmalig erzeugt.
6. **Assistent öffnen:** `https://IHRE-DOMAIN/setup`. Voraussetzungsprüfung, Setup-Schlüssel, Datenbank, erste Systemadministration (Passwort ≥ 12 Zeichen), optional SMTP. Der Assistent legt `.env` an (Rechte 0600), spielt die Migrationen ein, löscht den Schlüssel und sperrt sich (`storage/install.lock`). Danach ist `/setup` nicht mehr erreichbar.
7. **Erste Anmeldung:** Empfohlen: unter »Mein Konto« einen Passkey oder eine Authenticator-App einrichten (freiwillig, nicht erzwungen). **Wiederherstellungscodes sofort sicher ablegen.**
8. **Cronjob einrichten** (`CRON.md`) und täglich die **Sicherung** (`BACKUP_RESTORE.md`).
9. *System* → **Diagnose** öffnen und alle Punkte mit »Handlungsbedarf« abarbeiten. *System* → *Mandanten*: ersten Mandanten anlegen (die Mandantenadministration erhält eine Einladungs-E-Mail; dafür muss SMTP funktionieren).
10. Rechts-/Datenschutzstelle einbinden: `LEGAL_CHECKLIST.md`.

## Aktualisieren
Neues Paket über den Programmordner entpacken, **`.env` und `storage/` behalten**, danach `php bin/migrate.php` (SSH) ausführen oder in Plesk als einmalige geplante Aufgabe. Vorher Sicherung erstellen.

## Fehlersuche
- Weisse Seite/500: `storage/logs/app.log` (mit Referenznummer aus der Fehlerseite) und `storage/logs/php-error.log`.
- »Installation nicht möglich«: `SETUP-SCHLUESSEL.txt` fehlt (Paket erneut entpacken) oder die Installation ist bereits gesperrt.
- Assets (CSS/Icons) fehlen: Dokumentenstamm zeigt nicht auf `public/`.

## Hinweis zum Dokumentenstamm (HTTP 403)
Empfohlen ist, in Plesk den Dokumentenstamm auf `<Projektordner>/public` zu setzen. Seit Version 1.1.1 funktioniert die Installation auch mit
dem Plesk-Standard (Dokumentenstamm = Projektordner): die `.htaccess` im Projektstamm leitet dann ausschliesslich auf `public/` um, Code, `.env`
und `storage/` bleiben unerreichbar. Frühere Versionen sperrten in diesem Fall alles (403). Voraussetzung: Apache `mod_rewrite` und in Plesk
»Apache-Direktiven aus .htaccess zulassen« (AllowOverride). Bei reinem nginx ohne Apache müssen die Regeln in »nginx-Direktiven« nachgebildet werden.
