# Abhängigkeiten

## Laufzeit (auf dem Server)
**Keine Fremdpakete.** Kein Composer, kein Node, kein Build. Nur PHP-Erweiterungen: `pdo_mysql`, `mbstring`, `sodium`, `zip`, `curl`, `openssl`, `dom`, `fileinfo`, `zlib`. PDF-Erzeugung, DOCX-Erzeugung/-Import, SMTP-Client, TOTP und HTTP-Transport sind eigener Code (`src/`).

## Mitgelieferte Frontend-Dateien (fertig gebaut, keine Installation nötig)
| Datei | Herkunft | Lizenz |
|---|---|---|
| `public/assets/app.css` | mit Tailwind CSS 3.4.17 aus `templates/` erzeugt (minifiziert) | MIT |
| `public/assets/vendor/boxicons/*` | Boxicons 2.1.4 (CSS + Schrift woff/woff2) | MIT laut beiliegender Datei `LICENSE`. Das Projekt weist zusätzlich auf eine Attributionspflicht für die Icon-Grafiken hin (CC BY 4.0) – bitte auf boxicons.com prüfen und Namensnennung ggf. ergänzen |
| `public/assets/app.js`, `theme.js` | eigener Code (kein Framework) | – |

Schriften: System-Schriftstapel (keine Webfonts, keine externen Anfragen). Es werden keine CDNs, Tracker oder externen Skripte geladen (CSP erlaubt nur eigene Ressourcen).

## Nur zum Entwickeln
Tailwind CSS (CSS neu bauen), Composer ist optional (`composer.json` enthält nur die PHP-Anforderungen und den Autoloader; die App nutzt `src/autoload.php`). Tests: `php tests/run.php` (benötigt eine leere Test-Datenbank, siehe `TESTBERICHT.md`).

## Externe Dienste (optional, über Adapter)
Skribble (Signatur), beliebiger SMTP-Server.
