# E-Mail-Versand (SMTP)

Einstellungen in `.env` (oder im Setup-Assistenten): `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` (`tls` = implizit/465, `starttls` = 587, `none` = nur lokale Tests), `SMTP_USER`, `SMTP_PASS`, `MAIL_FROM`, `MAIL_FROM_NAME`. Verwenden Sie ein Postfach der eigenen Domain (Plesk → *Mail*) und tragen Sie SPF/DKIM für die Absenderdomain ein, sonst landen Einladungen im Spam.

- Der eingebaute SMTP-Client (kein Fremdpaket) unterstützt TLS/STARTTLS, AUTH LOGIN/PLAIN, UTF-8-Betreffs, Base64-Text. Er prüft Zertifikate (kein »unsichere Verbindung zulassen«).
- **Sofort versendet** werden sicherheitsrelevante Mails (Einladung, Passwort-Reset, E-Mail-Bestätigung). Schlägt das fehl, steht `mail.send_failed` im Protokoll, der Link ist nicht anderweitig abrufbar → *Verwaltung → Benutzer → Einladung erneut senden*.
- **Über die Warteschlange (Cron)** gehen Benachrichtigungen (Fristen, Aufgaben, Freigaben). Fehlgeschlagene Zustellungen werden mit Verzögerung wiederholt, danach als »dead« markiert (Diagnose zeigt die Anzahl).
- Ohne `SMTP_HOST` bleibt der Versand ausser Betrieb; die Diagnose meldet »Handlungsbedarf«.

**Teststand:** Der Client wurde gegen einen lokalen SMTP-Testserver (ohne TLS) geprüft: Verbindungsaufbau, Nachrichtenaufbau (Header, Base64, UTF-8) und Zustellung. **Nicht getestet:** TLS/STARTTLS und AUTH gegen einen echten Anbieter. Senden Sie nach der Konfiguration eine Testeinladung an eine eigene Adresse.
