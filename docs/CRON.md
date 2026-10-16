# Cronjobs (Plesk »Geplante Aufgaben«)

Es laufen **keine** Dauerprozesse. Fristen, Erinnerungen, E-Mail-Versand, Signaturstatus und Aufräumarbeiten laufen über kurze Cron-Aufrufe.

| Zweck | Befehl | Takt |
|---|---|---|
| Wartung (Pflicht) | `/opt/plesk/php/8.3/bin/php /pfad/zum/projekt/bin/cron.php` | alle 5 Minuten |
| Sicherung (Pflicht) | `/opt/plesk/php/8.3/bin/php /pfad/zum/projekt/bin/backup.php` | täglich, nachts |

Plesk: *Werkzeuge & Einstellungen* oder *Websites & Domains → Geplante Aufgaben → Aufgabe hinzufügen → Typ »PHP-Skript« oder »Shell-Befehl«*. Den PHP-Pfad passend zur gewählten Version nehmen (`/opt/plesk/php/8.3/bin/php`).

## Was `cron.php` tut
1. Abgeleitete Fristen (Kündigungs-/Ablauffristen) abgleichen
2. Fristen-Erinnerungen erzeugen (idempotent, keine Doppelmeldungen)
3. Abgelaufene Verträge in den Status »abgelaufen« setzen
4. E-Mail-Warteschlange versenden (mehrere Versuche mit Verzögerung, danach »dead«, sichtbar in der Diagnose)
5. Offene Signaturvorgänge beim Anbieter abfragen
6. Einmal täglich: alte Benachrichtigungen (>180 Tage), Rate-Limit-Einträge und abgelaufene Tokens löschen, **Audit-Anker setzen**

Doppelläufe werden durch eine Datenbanksperre verhindert (`maintenance_jobs`, Sperre läuft nach 20 Minuten ab). Ergebnis jeder Ausführung: *System → Geplante Aufgaben* und Konsolenausgabe. Exit-Code ≠ 0 bei Fehlern (Plesk kann daraufhin eine E-Mail senden).

Die Diagnose warnt, wenn der letzte erfolgreiche Lauf älter als 30 Minuten ist.
