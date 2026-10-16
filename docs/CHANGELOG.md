# Änderungsverlauf

Dieser Verlauf wird nach einem Update beim ersten Login in die Verwaltung angezeigt (Seite «Neuigkeiten»). Format je Version:
`## Version – Datum`, optional eine Kurzbeschreibung, darunter Abschnitte `### Neu`, `### Verbessert`, `### Behoben`, `### Wichtig`.

## 1.9.0 – 2026-10-16
Nach einem Update zeigt die Plattform den Administrationen beim ersten Login, was sich geändert hat.

### Neu
- Seite «Neuigkeiten» mit dem Änderungsverlauf: erscheint einmalig nach der Anmeldung, sobald eine neue Version installiert wurde, und ist jederzeit über das Benutzermenü erreichbar.
- Hinweisband für Administrationen, die bei laufender Sitzung aktualisiert wurden.

### Wichtig
- Beim Update wird eine kleine Datenbankänderung ausgeführt (Merker «zuletzt gesehene Version» je Benutzer). Sie ist rein additiv.

## 1.8.0 – 2026-10-16
Einfacher installieren, besser zurechtfinden.

### Neu
- Installationsassistent in sechs Schritten (Start, Adresse, Datenbank, Administration, E-Mail, Abschluss) mit Fortschrittsanzeige und Verbindungstest für die Datenbank.
- «Hilfe & Anleitungen»: 16 Schritt-für-Schritt-Anleitungen mit Suche, nach Rolle gefiltert; auf vielen Seiten ein Link «Anleitung zu dieser Seite». Dieselben Texte stehen in docs/ANLEITUNGEN.md.
- Einrichtungs-Checkliste in der Systemübersicht (Mandant, E-Mail, Cronjob, Sicherung).
- Willkommens-Karte für neue Konten und Erste-Schritte-Karte in der Verwaltung.

### Verbessert
- Passwortfelder lassen sich anzeigen und verbergen.
- Leere Vertragslisten erklären, was zu tun ist.

## 1.7.0 – 2026-10-16
Menüs feiner aufgeteilt.

### Verbessert
- System: Übersicht, Mandanten, Diagnose, Audit-Kette und Aufgaben sind eigene Seiten; Einstellungen sind in Darstellung, Adresse & Netzwerk, Uploads, E-Mail-Server, Absender, Signatur und Skribble getrennt; Betrieb enthält Sicherungen und Aktualisierung.
- Mandanten-Verwaltung: Mandant, Vertragsarten und Zusatzfelder sind eigene Menüpunkte.
- Konto: Profil, Passwort, Zweiter Faktor, Passkeys und Sitzungen sind eigene Seiten mit Reiter-Leiste.

## 1.6.0 – 2026-10-16
### Neu
- Einstellungen der Systemverwaltung in mehrere Bereiche aufgeteilt.

## 1.5.0 – 2026-10-16
Anmelden ohne Passwort.

### Neu
- Passkeys (WebAuthn): Anmeldung per Fingerabdruck, Gesicht, Geräte-PIN oder Sicherheitsschlüssel. Freiwillig; die Anmeldung mit Passwort bleibt möglich.

### Verbessert
- Ein zweiter Faktor (Authenticator-App) ist nicht mehr verpflichtend.
- Neues Design mit Hover- und Scroll-Animationen.

## 1.4.0 – 2026-10-16
Das Update-Verfahren wurde von Grund auf neu gebaut.

### Neu
- Aktualisierung aus GitHub mit Ersetzen auf Dateiebene, Selbsttest, automatischer Rücksicherung bei Fehlern und Wartungsmodus.
- Kommandozeilen-Werkzeug bin/repair.php für Notfälle.
- Aktion «Rechte reparieren» in der Systemverwaltung.

### Behoben
- Updates scheiterten teils mit HTTP 403 wegen Dateirechten.

### Wichtig
- Updates, die den Updater selbst ändern, müssen einmal von Hand eingespielt werden.
