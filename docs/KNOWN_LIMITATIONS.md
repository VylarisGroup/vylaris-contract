# Bekannte Einschränkungen

Diese Liste ist vollständig im Sinne dessen, was beim Bau erkannt wurde. Sie ersetzt keine eigene Prüfung.

## Integrationen – nicht gegen reale Systeme getestet
- **Skribble**: Adapter nach Dokumentation gebaut, nur mit nachgebildeten Antworten getestet. Statuswerte und das Feld für die Signaturstufe sind unverifiziert (siehe `SIGNATURE_PROVIDER.md`). Keine Webhooks, keine Gültigkeitsprüfung.
- **SMTP über TLS/STARTTLS/AUTH** gegen einen echten Anbieter: nicht getestet (nur lokaler Server ohne TLS).
- **Plesk-Umgebung** selbst (Cron, PHP-Handler, Dateirechte): nicht getestet; getestet wurde mit PHP 8.3.6 (eingebauter Server) und MariaDB 10.11.

## Funktionen
- PDF-Import legt nur das Original ab; Text wird **nicht** ausgelesen (kein OCR/PDF-Parsing). DOCX-Import übernimmt Text mit vereinfachter Formatierung (Überschriften, Absätze, Listen, einfache Tabellen).
- PDF/DOCX-Export nutzen eigene, schlichte Renderer (keine Kopf-/Fusszeilen-Gestaltung, begrenzte Typografie, Schriftart Helvetica/Standard). Für repräsentative Dokumente Export als DOCX und in Word weiterbearbeiten.
- Vertragstext wird als bereinigtes HTML in einem Textfeld bearbeitet (kein Rich-Text-Editor).
- Fristenberechnung ohne Feiertage/Wochenenden. Automatische Verlängerung wird als Frist verfolgt, nicht selbsttätig vollzogen.
- Suche per `LIKE` (keine Volltextindex/Relevanz). Für > ca. 50 000 Verträge pro Mandant nicht ausgelegt.
- Benutzerdefinierte Felder: Validierungsfehler werden als Sammelmeldung statt am Feld angezeigt.
- Kein QR-Code für TOTP (Schlüssel/otpauth-Link als Text); kein WebAuthn; kein SSO (Datenbankfelder `oidc_*` vorbereitet, nicht verdrahtet).
- Eine Sprache (Deutsch), Zeitzone pro Mandant, Datumsformat CH.
- Revisions-Verlaufsanzeige zeigt technische Aktionscodes (z. B. `contract.created`).
- Das Upload-Formular wird auch angezeigt, wenn der Status keine Uploads mehr erlaubt (der Versuch wird mit Meldung abgewiesen).

## Sicherheit / Betrieb
- Vollzugriff auf die Datenbank erlaubt Neuberechnung der Audit-Kette (siehe `SECURITY.md`); Dokumente ruhen unverschlüsselt im Dateisystem; kein Virenscan.
- Trigger-Schutz benötigt das TRIGGER-Recht des Datenbankkontos.
- Rate-Limit nur datenbankbasiert.
- Sicherungen laufen innerhalb des gleichen Hostings; Offsite-Kopie ist Betreiberaufgabe.
- Kein externer Penetrationstest, keine Lasttests.

## Recht
- **Keine Rechtsberatung, keine Konformitätserklärung.** Weder DSG noch DSGVO, OR/ZGB, eIDAS/ZertES-Konformität sind bescheinigt. Es gibt bewusst **keine universelle Aufbewahrungsfrist**; Regeln müssen vom Mandanten mit Rechtsgrundlage erfasst und fachlich bestätigt werden (`LEGAL_CHECKLIST.md`).

## Aktualisierung aus GitHub
- Keine Integritätsprüfung des Pakets (Vorgabe des Betreibers), siehe docs/UPDATE.md. Der echte GitHub-Abruf wurde nicht live getestet.
- Rückfall stellt nur Programmdateien wieder her, keine Datenbank-Migrationen.

## Neu in 1.12.0
- SSO: nur RS256-signierte ID-Tokens; getestet gegen einen simulierten Anbieter, nicht gegen einen echten. Kein Single Logout, keine Gruppen-/Rollenübernahme vom Anbieter.
- Zeitstempel: Zertifikatskette der Zeitstempelstelle wird nicht in der Plattform geprüft.
- Auswertungen: Summen je Währung, nicht umgerechnet.
