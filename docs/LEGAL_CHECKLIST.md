# Checkliste für die rechtliche Abnahme

Diese Plattform liefert Werkzeuge; die Verantwortung für rechtskonformen Betrieb liegt beim Betreiber. Vor Produktivstart durch Rechts-/Datenschutzstelle prüfen lassen:

## Datenschutz (DSG / DSGVO)
- [ ] Verantwortlicher, Zwecke, Rechtsgrundlagen und Kategorien betroffener Personen (Mitarbeitende, Gegenparteien, Externe) dokumentiert; Verzeichnis der Bearbeitungstätigkeiten / Datenschutz-Folgenabschätzung geprüft.
- [ ] Auftragsverarbeitung mit dem Hoster (und ggf. Skribble, SMTP-Anbieter) abgeschlossen; Standort der Daten und Auslandübermittlungen geklärt (Hosting in CH/EU?).
- [ ] Datenschutzerklärung und Information der Betroffenen (inkl. Mandate/Externe) bereitgestellt.
- [ ] Aufbewahrungsregeln je Vertragsart im Modul *Compliance* erfasst, Zweck + **Rechtsgrundlage** eingetragen und als »rechtlich geprüft« bestätigt (die Plattform liefert keine Standardfristen).
- [ ] Prozess für Auskunfts- und Löschbegehren festgelegt (*Compliance → Anfragen*); Rolle mit `sensitive.access` nur wo nötig.
- [ ] Rollen/Rechte, MFA-Pflicht, Mandatsdauer für Externe fachlich bestätigt.
- [ ] Löschkonzept für Sicherungen (Archive enthalten Personendaten bis zu ihrer Löschung; `BACKUP_KEEP`).

## Verträge & Signaturen
- [ ] Je Vertragstyp geklärt, welche **Form** gilt (Schriftform, qualifizierte elektronische Signatur nach ZertES/eIDAS, öffentliche Beurkundung) und ob SES/AES/QES genügt. Die Plattform entscheidet das nicht.
- [ ] Signaturanbieter-Vertrag, Demo-Test und Abnahme durchgeführt; Beweiswert/Archivierung der Signaturbelege geklärt.
- [ ] Nachweise zur Unveränderlichkeit (Hash-Kette, Trigger, Anker) und deren Grenzen (`SECURITY.md`) zur Kenntnis genommen; Kettenkopf wird regelmässig extern abgelegt.
- [ ] Vorlagen und Klauseln durch Juristen freigegeben (Status »freigegeben« wird erst nach Vier-Augen-Prüfung gesetzt).
- [ ] Fristen (Kündigung, Ablauf) bei wichtigen Verträgen manuell gegengeprüft (keine Feiertagslogik).

## Technik / Betrieb
- [ ] HTTPS, Backups (täglich, verschlüsselt, extern kopiert), **Wiederherstellungstest dokumentiert**, Cron aktiv, Diagnose ohne »Handlungsbedarf«.
- [ ] Notfall-Zugang: Wiederherstellungscodes der Administratoren sicher hinterlegt; `.env`/`APP_KEY` separat gesichert.
- [ ] Penetrationstest/Sicherheitsprüfung durch Dritte entsprechend Schutzbedarf.
