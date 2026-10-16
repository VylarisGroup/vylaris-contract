# Passkeys und zweiter Faktor (ab Version 1.5.0)

## Was gilt
- **Keine Pflicht:** Weder Systemadministration noch Mandantenadministration, Revision oder Notariat müssen einen zweiten Faktor einrichten. Die frühere Mandanten-Option »MFA verlangen« wurde entfernt (ein altes Flag in der Datenbank hat keine Wirkung mehr).
- **Freiwillig verfügbar:** TOTP-App mit Wiederherstellungscodes (Konto → Zweiter Faktor) und **Passkeys** (Konto → Passkeys).

## Passkeys
- Anmeldung ohne Passwort über »Mit Passkey anmelden« auf der Anmeldeseite (kein Benutzername nötig; der Browser bietet die vorhandenen Passkeys an).
- Ein Passkey (immer mit Benutzerverifizierung: Fingerabdruck, Gesicht oder Geräte-PIN) ersetzt Passwort **und** zweiten Faktor; ein eingerichtetes TOTP wird dann nicht zusätzlich abgefragt. Die Passwort-Anmeldung bleibt daneben bestehen.
- Einrichtung nur nach erneuter Passworteingabe, bis zu 10 Passkeys pro Konto, jeder mit Bezeichnung, Zeitpunkt der letzten Benutzung und Entfernen-Schaltfläche.
- Gespeichert werden nur öffentliche Schlüssel (Tabelle `passkeys`). Signaturzähler, Ursprung, Domain (RP-ID), Challenge (5 Minuten, einmalig), Anwesenheit und Benutzerverifizierung werden geprüft. Ein Passkey kann nicht auf einer anderen Domain verwendet werden (Schutz vor Phishing).
- Unterstützte Verfahren: ES256 und RS256. Attestation »none« (Herstellernachweise werden nicht ausgewertet).
- Verlorenes Gerät: Mandantenadministration → Benutzer → »Zweiten Faktor zurücksetzen« entfernt TOTP **und** alle Passkeys und beendet die Sitzungen des Benutzers. Alle Vorgänge stehen im Audit-Protokoll (`auth.passkey_*`).

## Voraussetzungen
- `APP_URL` (System → Einstellungen) muss eine **https-Adresse mit Domainnamen** sein (kein IP, kein http; `http://localhost` nur zum Testen). Daraus werden Domain (RP-ID) und Ursprung abgeleitet. **Wird die Domain geändert, funktionieren bestehende Passkeys nicht mehr** (Anmeldung dann per Passwort, Passkeys neu einrichten).
- Aktueller Browser/Betriebssystem mit Passkey-Unterstützung (Chrome, Edge, Safari, Firefox) oder Sicherheitsschlüssel.

## Getestet / nicht getestet
Getestet: 9 automatische Tests mit simuliertem Authenticator (echte ECDSA-/RSA-Signaturen und CBOR-Strukturen; falsche Challenge/Ursprung/RP-ID, fehlende Benutzerverifizierung, fremder Schlüssel, manipulierte Signatur, Wiederholung, Zähler, gesperrtes Konto, Limit, Reset) sowie ein Durchlauf im echten Chromium mit virtuellem Authenticator (Einrichten, Abmelden, Anmelden per Passkey).
**Nicht getestet:** echte Geräte und Sicherheitsschlüssel (Windows Hello, Touch ID, YubiKey, Telefon-Passkeys), Safari und Firefox.
