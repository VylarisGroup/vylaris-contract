# Sicherheitskonzept (Kurzfassung) und Grenzen

## Schutzmassnahmen
**Mandantentrennung.** Jede Abfrage ist an den Mandanten des angemeldeten Kontexts gebunden; fremde Objekte liefern 404 (kein Hinweis auf Existenz). Suche, Dashboard, Zähler, Autovervollständigung und CSV-Export verwenden dieselbe zentrale Sichtbarkeitsbedingung (`AccessControl::visibleContractsSql`), ein Test vergleicht sie mit der Einzelprüfung für alle Rollen. Innerhalb eines Mandanten gilt: Eigentümer, Zugriffsliste, Abteilungsbezug, zugewiesene Freigebende; Mandantenadministration sieht alle Verträge ihres Mandanten. Die Systemadministration hat **keinen** Zugriff auf Vertragsinhalte (nur Mandanten-/Systemverwaltung).

**Externe Zugriffe** (Gegenpartei, Revision, Notariat) nur über befristete, vom Mandanten angelegte **Mandate** mit Vertragsliste und Aktionsliste, Höchstdauer (365/120/180 Tage), jederzeit widerrufbar, jeder Zugriff protokolliert. Revision und Notariat haben Pflicht-MFA. Externe sehen nur die freigegebene Version und für sie freigegebene Dokumente.

**Authentifizierung.** Argon2id (Neuhashing bei Parameteränderung), Passwort ≥ 12 Zeichen mit Prüfung gegen Trivialpasswörter, Sperre nach 5 Fehlversuchen (15 Min.), Rate-Limit pro Konto/IP (Datenbank), einheitliche Fehlermeldungen (keine Kontenabfrage), Passwort-Reset/Einladung/E-Mail-Bestätigung über einmalige, gehashte, befristete Token, TOTP (RFC 6238) mit Replay-Schutz und 10 Wiederherstellungscodes (gehasht, einmalig), MFA-Geheimnis verschlüsselt (APP_KEY). Ein zweiter Faktor ist freiwillig (nirgends Pflicht); zusätzlich sind Passkeys (WebAuthn, mit Benutzerverifizierung, gebunden an die Domain) möglich. Passwortänderung/MFA-Änderung beenden alle anderen Sitzungen (`session_epoch`).

**Sitzung/Web.** Eigener Sitzungsspeicher ausserhalb des Webroots, Cookie `HttpOnly`, `SameSite=Lax`, unter HTTPS `__Host-`-Präfix + `Secure`; Sitzungs-ID wird bei Anmeldung/Privilegienwechsel erneuert; Inaktivität 30 Min., absolut 12 Std. CSRF-Token bei jedem POST plus Origin-Prüfung. Header: strikte CSP (`default-src 'none'`, nur eigene Skripte/Stile, kein Inline-Code), `frame-ancestors 'none'`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP, HSTS unter HTTPS, `Cache-Control: no-store` für Seiten mit Inhalt. Ausgabe überall HTML-escaped; Vertragstext wird beim Speichern auf eine Positivliste von Tags bereinigt (getestet: Skripte werden entfernt). SQL ausschliesslich über PDO mit Prepared Statements.

**Dokumente.** Ablage ausserhalb des Webroots unter zufälligen Schlüsseln; Download nur über berechtigungsgeprüften Controller; Hash (SHA-256) bei Ablage gespeichert und bei **jedem** Abruf geprüft; Uploads: Endung-Positivliste, MIME-/Magic-Byte-Prüfung, Grössenlimit, ZIP-Bomben-Schutz bei DOCX, keine ausführbaren Inhalte, Dateinamen werden nie als Pfad verwendet.

**Unveränderlichkeit.** Gesperrte Versionen und Freigaben sind in der Anwendung **und** durch 16 Datenbank-Trigger gegen UPDATE/DELETE geschützt (setzt TRIGGER-Recht voraus; Diagnose prüft). Änderungen an gesperrten Verträgen nur als Nachtrag (neue Version/neuer Vertrag mit Bezug).

**Audit-Protokoll.** Jeder Eintrag enthält den Hash seines Vorgängers (SHA-256-Kette, serialisiert über eine Datenbanksperre); Geheimnisse und Inhalte werden nicht protokolliert; IPs nur als Hash mit Schlüssel. Tägliche **Anker** (Kettenkopf) werden gespeichert. Prüfung: *System → Audit-Kette prüfen*.

## Grenzen (ehrlich)
- Die Hash-Kette erkennt nachträgliche **Änderung/Löschung einzelner** Einträge und – mit externem Anker – auch Kürzung. Wer **vollen Datenbankzugriff** hat, kann die ganze Kette neu berechnen. Schutz dagegen nur durch Kopie des Kettenkopfs (Diagnose zeigt ihn) an einen Ort ausserhalb der Datenbank (z. B. regelmässig per E-Mail/Ticket an eine unabhängige Stelle).
- Dokumente liegen **nicht verschlüsselt** im Dateisystem (Schutz durch Zugriffsrechte des Hostings). Datenträgerverschlüsselung ist Sache des Hosters. Sicherungen können verschlüsselt werden (`BACKUP_PASSPHRASE`).
- Kein Virenscanner, keine OCR, keine Prüfung auf Schadinhalte in PDF/DOCX über die Formatprüfung hinaus.
- TOTP-Geheimnis wird nur als Text/Link angezeigt (kein QR-Code, bewusst); Phishing-resistente Anmeldung ist über Passkeys (WebAuthn) möglich (freiwillig, docs/PASSKEYS.md); ein zweiter Faktor ist nirgends Pflicht.
- Rate-Limits liegen in der Datenbank (kein Redis); verteilte Angriffe mit vielen IPs werden nur pro Konto gebremst (Kontosperre).
- Auf Shared Hosting hat der Hoster/Administrator des Servers prinzipiell Zugriff auf Dateien und Datenbank.
- Es wurde **kein externer Penetrationstest** durchgeführt. Eigene Prüfungen siehe `TESTBERICHT.md`.

## Single Sign-on (OIDC) und Verbindungen zwischen Mandanten
- SSO: Authorization Code mit PKCE (S256), state und nonce in der Sitzung (einmalig, 10 Minuten), ID-Token nur mit RS256 (Signatur gegen JWKS, iss, aud/azp, exp, iat, nonce). Adressen des Anbieters müssen https sein und öffentlich auflösen (Schutz vor Zugriffen auf interne Dienste; DNS-Rebinding ist damit nicht vollständig ausgeschlossen).
- Verknüpfung nur über `email_verified = true` mit einem bereits aktiven, internen Mitglied des Mandanten; Systemadministratoren sind ausgeschlossen. Eine SSO-Sitzung ist auf den Mandanten beschränkt (`sso_tenant`), damit ein fremder Identitätsanbieter keinen Zugriff auf weitere Mandanten desselben Kontos erhält. Neue Konten nur bei ausdrücklicher Freigabe und Domain-Liste, nie mit Administratorrolle. Das Client-Geheimnis wird mit APP_KEY verschlüsselt.
- Mandanten-Code: Anfrage und Bestätigung, Rate-Limit, neutrale Fehlermeldungen. Geteilte Verträge sind für die Gegenseite schreibgeschützt, erst ab «Freigegeben», ohne interne Daten; jeder Zugriff wird protokolliert.
