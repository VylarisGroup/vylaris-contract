# Änderungsverlauf

Dieser Verlauf wird nach einem Update beim ersten Login in die Verwaltung angezeigt (Seite «Neuigkeiten»). Format je Version:
`## Version – Datum`, optional eine Kurzbeschreibung, darunter Abschnitte `### Neu`, `### Verbessert`, `### Behoben`, `### Wichtig`.

## 1.13.0 – 2026-10-22
Mandanten verwalten, einfachere Freigabe, geführte Vertragserstellung und neu gegliederte Verwaltung.

### Neu
- **Mandanten bearbeiten, archivieren, wiederherstellen** (Systemadministration): Name und Kürzel ändern, Mandanten archivieren und wieder aktivieren. Mandanten werden bewusst nie endgültig gelöscht (Nachvollziehbarkeit, Aufbewahrung); archivierte Mandanten können sich nicht anmelden.
- **Startassistent für neue Verträge:** Wahl zwischen Vorlage, vorhandenem Dokument oder von Grund auf, mit Vorab-Prüfung und Erklärung, wie es danach weitergeht.
- **«Ablauf dieses Vertrags»** auf jeder Vertragsseite: zeigt in fünf Schritten, was das System übernimmt und was Sie tun, inklusive nächstem Schritt.
- **Standardwerte:** Beginn (heute), anwendbares Recht (aus den Mandanten-Einstellungen) und Währung werden vorgeschlagen; Unterzeichnende werden aus den Vertragsparteien vorausgefüllt.
- **Verwaltung neu gegliedert:** Übersicht mit Einrichtungs-Checkliste, erklärten Kacheln und Tabelle «Wer darf was?».

### Verbessert
- **Freigabe:** Freigebende werden per Auswahlliste gewählt (statt Texteingabe). Die Vier-Augen-Sperre bei Verträgen entfällt; Mandantenadministration und Vertragsverantwortliche dürfen freigeben. Nach Ablehnung oder Rückzug lässt sich die Freigabe erneut anfordern (es entsteht automatisch ein neuer Entwurf).

### Wichtig
- Datenbankänderung: Migration 008 (automatisch beim Update, additiv). Sie erweitert den Mandantenstatus um «archiviert» und vergibt das Recht «Freigabe entscheiden» an Mandantenadministration und Vertragsverantwortliche.
- Die Vier-Augen-Regel für die Prüfung von Vorlagen bleibt unverändert.

## 1.12.0 – 2026-10-17
Verbinden statt anlegen, Single Sign-on, Zeitstempel, Auswertungen und Fragebogen für Vorlagen.

### Neu
- **Mandanten-Code:** Gegenparteien werden nicht mehr von Hand angelegt, sondern über den Code des anderen Mandanten verbunden (Anfrage, Bestätigung durch die Gegenseite). Verbundene Mandanten sehen Verträge, in denen sie Partei sind, ab «Freigegeben» schreibgeschützt unter «Geteilte Verträge». Ältere Gegenparteien bleiben als «Altbestand» erhalten.
- **Single Sign-on (OpenID Connect)** pro Mandant: Einrichtung unter «Verwaltung → Single Sign-on», Anmeldung über «Mit SSO anmelden». Passwort und Passkey bleiben möglich; eine SSO-Sitzung gilt nur für den Mandanten.
- **Zeitstempel (RFC 3161)** für die eigene Signatur: Unter «Einstellungen → Signatur» eine Zeitstempelstelle (https) eintragen; versiegelte Dokumente erhalten zusätzlich deren Zeitstempel (Hash-Abgleich und Token-Signatur werden geprüft). Fehlende Zeitstempel holt der Cron nach.
- **Auswertungen** auf der Startseite: Ablauf in 30/60/90 Tagen, Vertragswerte je Vertragsart und Währung, Dauer der Freigaben, offene Signaturen, Neuerfassungen pro Monat; Export als CSV.
- **Fragebogen** beim Erstellen eines Vertrags aus einer Vorlage: eine Frage pro Schritt, mit Hinweisen. Vorlagenfelder haben dafür die optionalen Angaben «Frage» und «Hinweis».

### Wichtig
- Datenbankänderung: Migration 007 (wird beim Update automatisch ausgeführt). Rein additiv.
- Die Zertifikatskette der Zeitstempelstelle wird in der Plattform nicht geprüft; das gespeicherte Token lässt sich extern mit `openssl ts -verify` prüfen.
- SSO wurde gegen einen simulierten Anbieter getestet, nicht gegen einen echten. Bitte zuerst mit einem Testkonto prüfen.

## 1.11.0 – 2026-10-16
Übersichtlichere Seitenleiste und Assistenten für das Anlegen.

### Neu
- Seitenleiste mit ausklappbaren Gruppen (Hauptbereich, Verwaltung, System, Einstellungen, Betrieb, Hilfe). Der Zustand wird im Browser gemerkt, die Gruppe der aktuellen Seite bleibt offen, ungelesene Hinweise bleiben auch bei eingeklappter Gruppe sichtbar.
- Assistenten (Schritt für Schritt mit Fortschrittsanzeige und Zusammenfassung) für: Vertrag, Gegenpartei, Vorlage, Freigabe, Signatur, Frist, Aufgabe, Benutzer einladen, Mandant anlegen, Vertragsart, Zusatzfeld, Mandat, Aufbewahrungsregel, Sperre und Datenschutzanfrage.
- Die bisherigen Formulare bleiben: Mit «Alle Felder anzeigen» wechseln Sie jederzeit zurück; beim Bearbeiten ist die bisherige Ansicht Standard.

### Behoben
- Beim Anfordern einer Freigabe auf der Vertragsseite fehlte die Auswahl der freigebenden Personen.

### Wichtig
- Keine Datenbankänderung in dieser Version.

## 1.10.0 – 2026-10-16
Unterzeichnen ohne externen Anbieter: die Plattform bringt eine eigene, vollständige Signatur mit.

### Neu
- Eigene Signatur («Einstellungen → Signatur → Eigene Signatur»): Unterzeichnende erhalten einen persönlichen Link per E-Mail, bestätigen mit Einmalcode und unterzeichnen mit Namen und Einwilligung – ohne Konto.
- Reihenfolge wählbar (gleichzeitig oder nacheinander), Ablehnen mit Begründung, Erinnern mit neuem Link, Zurückziehen.
- Am Ende erhält das PDF ein Signaturprotokoll (Personen, Zeitpunkte, IP-Adresse, Einwilligung) und ein Siegel der Plattform; der Vertrag wird automatisch aktiv.
- Vertragsseite zeigt je Unterzeichner den Stand.

### Wichtig
- Es handelt sich um eine **einfache elektronische Signatur (SES)**, nicht um eine qualifizierte (QES). Ob sie für einen Vertrag genügt, ist fachlich zu prüfen. Details: docs/SIGNATURE_PROVIDER.md.
- Voraussetzung: funktionierender E-Mail-Versand und https-Adresse.
- Beim Update wird eine Datenbankänderung ausgeführt (zwei neue Tabellen). Sie ist rein additiv.

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
