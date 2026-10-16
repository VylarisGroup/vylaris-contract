<?php

declare(strict_types=1);

namespace App\Web;

/**
 * Anleitungen der Plattform (Seite «Hilfe»). Ein einziger Ort für alle Texte: dieselben Daten speisen die
 * Hilfe-Seiten und docs/ANLEITUNGEN.md (bin/guides-export.php).
 *
 * Zielgruppe (audience): all = alle Angemeldeten, internal = interne Mandantenbenutzer,
 * admin = Mandanten-Administration, system = Systemadministration.
 */
final class HelpTopics
{
    public const GROUPS = [
        'start' => 'Erste Schritte',
        'vertraege' => 'Mit Verträgen arbeiten',
        'verwaltung' => 'Verwaltung eines Mandanten',
        'konto' => 'Mein Konto',
        'system' => 'Systembetrieb (Systemadministration)',
    ];

    public const AUDIENCES = ['all' => 'Alle', 'internal' => 'Mitarbeitende', 'admin' => 'Administration', 'system' => 'Systemadministration'];

    /** @return array<string,array{title:string,group:string,audience:string,icon:string,summary:string,time:string,steps:list<array{0:string,1:string}>,tips:list<string>,related:list<string>}> */
    public static function all(): array
    {
        return [
            'willkommen' => [
                'title' => 'Die Plattform in fünf Minuten kennenlernen', 'group' => 'start', 'audience' => 'internal', 'icon' => 'bx-compass', 'time' => '5 Min.',
                'summary' => 'Wo finde ich was? Ein kurzer Rundgang durch Menü, Übersicht und Suche.',
                'steps' => [
                    ['Das Menü links', 'Die Einträge sind in Gruppen geordnet (Hauptbereich, Verwaltung, Hilfe). Ein Klick auf den Gruppennamen klappt die Gruppe auf oder zu; die Plattform merkt sich das, und die Gruppe der aktuellen Seite bleibt offen. Im Hauptbereich finden Sie Start, Verträge, Freigaben, Vorlagen und Gegenparteien. Unter «Verwaltung» erscheinen nur die Punkte, für die Ihre Rolle berechtigt ist. Auf dem Telefon öffnen Sie das Menü mit dem Symbol oben links.'],
                    ['Die Übersicht («Start»)', 'Oben sehen Sie, wie viele Verträge in welchem Status sind; ein Klick zeigt die Liste dazu. Darunter stehen Ihre offenen Freigaben, Aufgaben, anstehende Fristen und bald auslaufende Verträge.'],
                    ['Verträge finden', 'Unter «Verträge» suchen Sie nach Titel, Nummer, Text, Gegenpartei oder Tag und filtern nach Status, Art, Abteilung und Vertragsende. Sie sehen nur Verträge, auf die Sie Zugriff haben.'],
                    ['Benachrichtigungen', 'Die Glocke im Menü zeigt neue Hinweise, etwa fällige Fristen oder Freigabeanfragen. Wenn E-Mail eingerichtet ist, erhalten Sie diese zusätzlich per Mail.'],
                    ['Konto und Abmelden', 'Ganz unten im Menü öffnet Ihr Name ein kleines Menü: «Mein Konto & Sicherheit», Mandant wechseln (falls Sie mehreren angehören) und Abmelden.'],
                ],
                'tips' => ['Die Schaltfläche «Hilfe» im Menü bringt Sie jederzeit hierher zurück.', 'Fehlt Ihnen ein Menüpunkt, fehlt meist nur die Berechtigung – fragen Sie Ihre Administration.'],
                'related' => ['vertrag-erfassen', 'konto-sicherheit'],
            ],
            'assistenten' => [
                'title' => 'Mit Assistenten Schritt für Schritt eingeben', 'group' => 'start', 'audience' => 'all', 'icon' => 'bx-list-check', 'time' => '2 Min.',
                'summary' => 'Neue Einträge in kleinen Schritten erfassen – oder wie bisher alle Felder auf einmal.',
                'steps' => [
                    ['Assistent starten', 'Formulare zum Anlegen (Vertrag, Gegenpartei, Vorlage, Signatur, Freigabe, Frist, Aufgabe, Benutzer, Mandant u. a.) öffnen als Assistent: oben sehen Sie die Schritte und den Fortschritt, darunter jeweils nur wenige Felder.'],
                    ['Weiter und Zurück', 'Mit «Weiter» gehen Sie zum nächsten Schritt; Pflichtfelder werden dabei geprüft. Mit «Zurück» oder einem Klick auf einen früheren Schritt korrigieren Sie jederzeit. Die Eingabetaste springt ebenfalls weiter.'],
                    ['Prüfen und speichern', 'Der letzte Schritt fasst Ihre Angaben zusammen. Erst dort erscheint die Schaltfläche zum Speichern.'],
                    ['Lieber alles auf einmal?', 'Mit «Alle Felder anzeigen» wechseln Sie zur bisherigen Ansicht; mit «Schritt für Schritt» zurück. Die Wahl wird in diesem Browser gemerkt. Beim Bearbeiten bestehender Einträge ist standardmässig die bisherige Ansicht aktiv.'],
                ],
                'tips' => ['Beide Ansichten senden dasselbe Formular – es gelten dieselben Prüfungen und Rechte.', 'Ohne JavaScript zeigt die Plattform immer alle Felder.'],
                'related' => ['vertrag-erfassen'],
            ],
            'vertrag-erfassen' => [
                'title' => 'Einen Vertrag erfassen', 'group' => 'vertraege', 'audience' => 'internal', 'icon' => 'bx-file-plus', 'time' => '5 Min.',
                'summary' => 'Vom leeren Formular zum gespeicherten Vertrag mit Gegenpartei, Laufzeit und Dokument.',
                'steps' => [
                    ['«Neuer Vertrag» öffnen', 'Im Menü auf «Neuer Vertrag» klicken (nur sichtbar mit der Berechtigung, Verträge anzulegen).'],
                    ['Eckdaten ausfüllen', 'Pflicht sind Titel und Vertragsart. Zusätzlich lassen sich Abteilung, zuständige Person, Beginn, Ende, Kündigungsfrist, automatische Verlängerung, Vertragswert, anwendbares Recht und Zahlungsbedingungen erfassen. Je vollständiger Beginn, Ende und Kündigungsfrist sind, desto besser kann die Plattform Fristen berechnen.'],
                    ['Gegenpartei zuordnen', 'Wählen Sie die Vertragspartner aus Ihren Gegenparteien. Neue Gegenparteien legen Sie vorher unter «Gegenparteien» an.'],
                    ['Text eintragen oder Dokument hochladen', 'Sie können den Vertragstext direkt erfassen oder nach dem Speichern auf der Vertragsseite eine Datei hochladen (maximale Grösse siehe Hinweis am Upload-Feld). Dort stehen auch die Versionen.'],
                    ['Speichern', 'Der Vertrag erhält automatisch eine Nummer und startet als «Entwurf». Jede spätere Änderung erzeugt eine neue Version; mit der Änderungsnotiz halten Sie fest, was sich geändert hat.'],
                ],
                'tips' => ['Tags (mit Komma getrennt) erleichtern die spätere Suche.', 'Zusatzfelder Ihrer Organisation erscheinen ebenfalls im Formular.'],
                'related' => ['gegenparteien', 'vertrag-freigabe', 'fristen'],
            ],
            'vertrag-freigabe' => [
                'title' => 'Freigabe beantragen und erteilen', 'group' => 'vertraege', 'audience' => 'internal', 'icon' => 'bx-check-shield', 'time' => '3 Min.',
                'summary' => 'Wie ein Vertrag intern geprüft und freigegeben wird.',
                'steps' => [
                    ['Freigabe beantragen', 'Auf der Vertragsseite die Freigabe anfordern. Der Vertrag wechselt dabei in «In Prüfung», die zuständigen Personen werden benachrichtigt.'],
                    ['Zurückziehen, falls nötig', 'Solange noch nicht entschieden wurde, können Sie den Antrag zurückziehen, korrigieren und neu einreichen.'],
                    ['Als Prüfende Person entscheiden', 'Unter «Freigaben» sehen Sie, was auf Ihre Entscheidung wartet. Öffnen Sie den Vertrag, prüfen Sie ihn und genehmigen oder lehnen Sie ab. Ihre Entscheidung können Sie kommentieren.'],
                    ['Ergebnis', 'Nach der letzten Freigabe wird der Vertrag «Freigegeben» und kann zur Unterzeichnung weitergegeben werden. Jeder Schritt ist im Protokoll des Vertrags nachvollziehbar.'],
                ],
                'tips' => ['Wer die Textfassung eines Vertrags erstellt hat, darf sie nicht selbst freigeben (Vier-Augen-Prinzip).', 'Eine Freigabe gilt für eine bestimmte Textfassung; wird der Text danach geändert, ist eine neue Freigabe nötig.'],
                'related' => ['vertrag-signieren'],
            ],
            'vertrag-signieren' => [
                'title' => 'Einen Vertrag unterzeichnen lassen', 'group' => 'vertraege', 'audience' => 'internal', 'icon' => 'bx-pen', 'time' => '3 Min.',
                'summary' => 'Signaturvorgang starten und verfolgen – und was der Testmodus bedeutet.',
                'steps' => [
                    ['Signatur starten', 'Ist der Vertrag freigegeben, starten Sie auf der Vertragsseite den Signaturvorgang und legen fest, wer unterzeichnet.'],
                    ['Status verfolgen', 'Der Stand wird regelmässig abgefragt (Cronjob). Mit «Status abfragen» aktualisieren Sie sofort. Bei der eigenen Signatur sehen Sie je Person, ob sie eingeladen ist, wartet oder unterzeichnet hat, und können mit «Einladung erneut senden» einen neuen Link verschicken (der alte wird ungültig). Ein laufender Vorgang lässt sich zurückziehen.'],
                    ['Eigene Signatur (ohne externen Anbieter)', 'Die Unterzeichnenden erhalten eine E-Mail mit persönlichem Link, bestätigen mit einem Einmalcode an dieselbe Adresse und unterzeichnen mit ihrem Namen. Sie wählen, ob alle gleichzeitig oder nacheinander eingeladen werden. Am Ende erhält das PDF ein Signaturprotokoll und ein Siegel der Plattform.'],
                    ['Abschluss', 'Sind alle Unterschriften da, wird der Vertrag «Aktiv» und das signierte Dokument liegt beim Vertrag.'],
                ],
                'tips' => ['Zeigt die Plattform einen Hinweis «Testmodus», sind Signaturen NICHT rechtsgültig. Die Systemadministration stellt den Anbieter bewusst um (siehe Anleitung «Signaturanbieter»).', 'Ob eine Signaturart für Ihren Vertrag rechtlich genügt, klären Sie bitte mit Ihrer Rechtsberatung.', 'Die eigene Signatur ist eine einfache elektronische Signatur (SES), keine qualifizierte (QES). Verlangt das Gesetz oder Ihr Vertrag mehr, nutzen Sie einen Anbieter mit entsprechender Stufe.'],
                'related' => ['vertrag-freigabe'],
            ],
            'fristen' => [
                'title' => 'Fristen, Aufgaben und Erinnerungen', 'group' => 'vertraege', 'audience' => 'internal', 'icon' => 'bx-calendar-exclamation', 'time' => '3 Min.',
                'summary' => 'Kündigungsfristen im Blick behalten und Aufgaben zu Verträgen verteilen.',
                'steps' => [
                    ['Beginn, Ende und Kündigungsfrist erfassen', 'Aus diesen Angaben leitet die Plattform Fristen ab, etwa den letzten Kündigungstermin.'],
                    ['Eigene Fristen hinzufügen', 'Auf der Vertragsseite unter «Frist hinzufügen» ein Datum und eine Bezeichnung eintragen, z. B. «Preisanpassung prüfen».'],
                    ['Aufgaben vergeben', 'Mit «Aufgabe hinzufügen» weisen Sie einer Person eine Aufgabe mit Fälligkeit zu. Erledigte Aufgaben haken Sie ab.'],
                    ['Erinnerungen erhalten', 'Anstehende Fristen erscheinen in der Übersicht (nächste 90 Tage) und als Benachrichtigung. Das funktioniert nur, wenn der Cronjob läuft.'],
                ],
                'tips' => ['Erinnerungen werden nicht doppelt verschickt.', 'Laufen Fristen nicht, meldet die Diagnose der Systemadministration, dass der Cronjob fehlt.'],
                'related' => ['vertrag-erfassen'],
            ],
            'gegenparteien' => [
                'title' => 'Gegenparteien verwalten', 'group' => 'vertraege', 'audience' => 'internal', 'icon' => 'bx-buildings', 'time' => '2 Min.',
                'summary' => 'Vertragspartner einmal anlegen und in vielen Verträgen verwenden.',
                'steps' => [
                    ['Neue Gegenpartei', 'Unter «Gegenparteien» auf «Neue Gegenpartei» klicken und Name, Anschrift und Kontaktangaben eintragen.'],
                    ['In Verträgen verwenden', 'Beim Erfassen eines Vertrags wählen Sie die Gegenpartei aus der Liste.'],
                    ['Archivieren statt löschen', 'Nicht mehr benötigte Gegenparteien archivieren Sie; bestehende Verträge bleiben unverändert.'],
                ],
                'tips' => ['Prüfen Sie vor dem Anlegen kurz die Liste – Doppelte erschweren die Suche.'],
                'related' => ['vertrag-erfassen'],
            ],
            'vorlagen' => [
                'title' => 'Mit Vorlagen und Klauseln arbeiten', 'group' => 'vertraege', 'audience' => 'internal', 'icon' => 'bx-layout', 'time' => '4 Min.',
                'summary' => 'Wiederkehrende Verträge aus geprüften Vorlagen erstellen.',
                'steps' => [
                    ['Vorlage wählen', 'Unter «Vorlagen» sehen Sie die freigegebenen Vorlagen. Die Vorschau zeigt den Text vor der Verwendung.'],
                    ['Vertrag aus Vorlage erstellen', 'Auf der Vorlagenseite lässt sich daraus ein neuer Vertrags-Entwurf erstellen mit dem Vorlagentext, den Sie anschliessend anpassen.'],
                    ['Eigene Vorlagen pflegen', 'Wer Vorlagen verwalten darf, legt neue an und erstellt Versionen. Neue Versionen werden geprüft und erst nach Entscheid verwendbar.'],
                    ['Klauseln', 'Wiederverwendbare Textbausteine (Klauseln) pflegen Sie in der Klauselbibliothek.'],
                ],
                'tips' => ['Vorlagen sind ein Hilfsmittel – die rechtliche Prüfung ersetzen sie nicht.'],
                'related' => ['vertrag-erfassen'],
            ],
            'benutzer-rollen' => [
                'title' => 'Benutzer einladen und Rollen vergeben', 'group' => 'verwaltung', 'audience' => 'admin', 'icon' => 'bx-group', 'time' => '4 Min.',
                'summary' => 'Neue Personen einladen, Rollen zuweisen, Abteilungen bilden.',
                'steps' => [
                    ['Verwaltung öffnen', 'Im Menü auf «Verwaltung» und dann «Benutzer & Rollen».'],
                    ['Person einladen', 'E-Mail-Adresse, Name und Rolle angeben. Die Person erhält einen Einladungslink per E-Mail und setzt ihr Passwort selbst. Dafür muss der E-Mail-Versand eingerichtet sein.'],
                    ['Rolle ändern oder sperren', 'In der Benutzerliste lassen sich Rolle und Status ändern. Gesperrte Personen können sich nicht mehr anmelden. Die Einladung kann erneut versendet werden.'],
                    ['Abteilungen', 'Unter «Abteilungen» legen Sie Abteilungen an und weisen Mitglieder zu. Der Zugriff auf Verträge richtet sich nach Rolle und Abteilung.'],
                    ['Zweiten Faktor zurücksetzen', 'Hat jemand Authenticator-App oder Gerät verloren, setzen Sie den zweiten Faktor dieser Person hier zurück. Die Anmeldung mit Passwort bleibt möglich.'],
                ],
                'tips' => ['Vergeben Sie jeweils die kleinste Rolle, die für die Arbeit genügt.', 'Welche Rolle was darf, steht in docs/ROLES_PERMISSIONS.md.'],
                'related' => ['mandant-einrichten'],
            ],
            'mandant-einrichten' => [
                'title' => 'Den eigenen Mandanten einrichten', 'group' => 'verwaltung', 'audience' => 'admin', 'icon' => 'bx-building-house', 'time' => '5 Min.',
                'summary' => 'Stammdaten, Vertragsarten und Zusatzfelder festlegen, bevor die Arbeit beginnt.',
                'steps' => [
                    ['Stammdaten', 'Unter «Verwaltung → Mandant»: Name, Zeitzone, Standard-Rechtsgebiet und Präfix der Vertragsnummern festlegen. Das Präfix erscheint vor jeder Vertragsnummer.'],
                    ['Vertragsarten', 'Unter «Vertragsarten» legen Sie die Arten an, die bei der Erfassung zur Auswahl stehen (z. B. Mietvertrag, Dienstleistung). Der Code besteht aus Kleinbuchstaben, Ziffern und Unterstrich.'],
                    ['Zusatzfelder', 'Unter «Zusatzfelder» definieren Sie eigene Angaben, die bei Verträgen zusätzlich erfasst werden: Text, Zahl, Datum, Ja/Nein oder Auswahl (Optionen durch Komma trennen). Pflichtfelder sind markierbar.'],
                    ['Benutzer einladen', 'Danach laden Sie Ihr Team ein (Anleitung «Benutzer einladen und Rollen vergeben»).'],
                ],
                'tips' => ['Wählen Sie den Code einer Vertragsart oder eines Feldes sorgfältig: kurz, sprechend, ohne Sonderzeichen.'],
                'related' => ['benutzer-rollen'],
            ],
            'konto-sicherheit' => [
                'title' => 'Konto absichern: Passwort, zweiter Faktor, Passkeys', 'group' => 'konto', 'audience' => 'all', 'icon' => 'bx-shield-alt-2', 'time' => '4 Min.',
                'summary' => 'Alle Möglichkeiten, Ihr Konto zu schützen – alle freiwillig.',
                'steps' => [
                    ['Mein Konto öffnen', 'Unten im Menü auf Ihren Namen klicken, dann «Mein Konto & Sicherheit». Oben wechseln Sie zwischen Profil, Passwort, Zweiter Faktor, Passkeys und Sitzungen.'],
                    ['Passwort ändern', 'Unter «Passwort»: aktuelles Passwort, dann zweimal das neue (mindestens 12 Zeichen; ein Satz aus mehreren Wörtern ist stark und leicht zu merken). Alle anderen Sitzungen werden beendet.'],
                    ['Passkey einrichten (empfohlen)', 'Unter «Passkeys» auf «Passkey hinzufügen» klicken und die Abfrage Ihres Geräts bestätigen (Fingerabdruck, Gesicht, Geräte-PIN oder Sicherheitsschlüssel). Danach melden Sie sich ohne Passwort an. Mehrere Passkeys sind möglich, z. B. Telefon und Laptop.'],
                    ['Zweiter Faktor (Authenticator-App)', 'Alternativ unter «Zweiter Faktor» auf «Einrichten» klicken, den angezeigten Schlüssel in eine Authenticator-App eingeben (auf dem Telefon geht auch der «otpauth-Link») und den 6-stelligen Code bestätigen. Einen QR-Code gibt es bewusst nicht, damit der Schlüssel nie an Dritte übermittelt wird. Speichern Sie die angezeigten Wiederherstellungscodes sofort an einem sicheren Ort – sie werden nur einmal angezeigt.'],
                    ['Sitzungen beenden', 'Unter «Sitzungen» melden Sie sich auf allen Geräten ab, z. B. nach Verlust eines Geräts.'],
                ],
                'tips' => ['Passkeys und zweiter Faktor sind freiwillig; die Anmeldung mit Passwort bleibt immer möglich.', 'Passkeys funktionieren nur über eine HTTPS-Adresse.'],
                'related' => ['willkommen'],
            ],
            'einrichtung' => [
                'title' => 'Installation in sechs Schritten', 'group' => 'system', 'audience' => 'system', 'icon' => 'bx-rocket', 'time' => '15 Min.',
                'summary' => 'Vom hochgeladenen Paket zur laufenden Plattform – der Assistent führt Sie durch.',
                'steps' => [
                    ['Vorbereiten', 'Paket auf dem Server entpacken, in Plesk den Dokumentenstamm auf «public» setzen (empfohlen), ein HTTPS-Zertifikat aktivieren und eine leere Datenbank samt Benutzer anlegen. Details: docs/INSTALLATION_PLESK.md.'],
                    ['Schritt 1 – Start', 'Adresse «https://IHRE-DOMAIN/setup» öffnen. Die Systemprüfung zeigt, ob PHP und Verzeichnisse passen. Geben Sie den Setup-Schlüssel aus der Datei SETUP-SCHLUESSEL.txt ein.'],
                    ['Schritt 2 – Adresse', 'Öffentliche Adresse der Plattform und maximale Dateigrösse eintragen.'],
                    ['Schritt 3 – Datenbank', 'Host, Port, Name, Benutzer und Passwort eintragen und «Verbindung testen» drücken. Erst bei grünem Ergebnis weitergehen.'],
                    ['Schritt 4 – Administration', 'Name, E-Mail und ein langes Passwort für das erste Administrationskonto festlegen.'],
                    ['Schritt 5 – E-Mail (optional)', 'SMTP-Zugang eintragen oder überspringen und später unter «Einstellungen → E-Mail-Server» nachholen.'],
                    ['Schritt 6 – Abschluss', 'Zusammenfassung prüfen und «Installieren» drücken. Danach ist der Assistent gesperrt. Befolgen Sie die Hinweise auf der Abschlussseite (Cronjob, erster Mandant, .env sichern).'],
                ],
                'tips' => ['Der Setup-Schlüssel wird nach der Installation gelöscht.', 'Sichern Sie die Datei .env getrennt vom Rest.'],
                'related' => ['cronjob', 'email-einrichten'],
            ],
            'cronjob' => [
                'title' => 'Cronjob in Plesk einrichten', 'group' => 'system', 'audience' => 'system', 'icon' => 'bx-timer', 'time' => '5 Min.',
                'summary' => 'Ohne Cronjob laufen Fristen, Erinnerungen und E-Mail-Versand nicht.',
                'steps' => [
                    ['Plesk öffnen', 'Websites & Domains → Ihre Domain → «Geplante Aufgaben» → «Aufgabe hinzufügen».'],
                    ['Wartung anlegen', 'Typ «Shell-Befehl», Befehl «/opt/plesk/php/8.3/bin/php /PFAD/ZUM/PROJEKT/bin/cron.php», Intervall alle 5 Minuten.'],
                    ['Sicherung anlegen', 'Zweite Aufgabe mit «/PFAD/ZUM/PROJEKT/bin/backup.php» (gleicher PHP-Pfad), täglich nachts.'],
                    ['Prüfen', 'Nach ein paar Minuten unter «System → Aufgaben» den Status ansehen. Sie können Aufgaben dort auch von Hand ausführen.'],
                ],
                'tips' => ['Der PHP-Pfad muss zur gewählten PHP-Version passen.', 'Die Diagnose warnt, wenn der letzte Lauf älter als 30 Minuten ist.'],
                'related' => ['sicherung'],
            ],
            'email-einrichten' => [
                'title' => 'E-Mail-Versand einrichten und testen', 'group' => 'system', 'audience' => 'system', 'icon' => 'bx-envelope', 'time' => '5 Min.',
                'summary' => 'SMTP-Zugang eintragen, Absender festlegen, Testmail senden.',
                'steps' => [
                    ['E-Mail-Server', 'Unter «System → Einstellungen → E-Mail-Server» Server, Port, Verschlüsselung, Benutzer und Passwort eintragen. Das Passwort wird verschlüsselt gespeichert und nie angezeigt.'],
                    ['Absender', 'Unter «Absender» Adresse und Namen festlegen, die Empfangende sehen.'],
                    ['Testmail', 'Auf der Seite «E-Mail-Server» eine Testmail an sich selbst senden. Kommt sie an, funktionieren Einladungen und Erinnerungen.'],
                ],
                'tips' => ['Meist: Port 465 mit TLS oder 587 mit STARTTLS.', 'Weitere Hinweise: docs/SMTP.md.'],
                'related' => ['einrichtung'],
            ],
            'mandanten-verwalten' => [
                'title' => 'Mandanten anlegen und sperren', 'group' => 'system', 'audience' => 'system', 'icon' => 'bx-buildings', 'time' => '3 Min.',
                'summary' => 'Ein Mandant ist eine Firma oder Organisation mit eigenen Verträgen und Benutzern.',
                'steps' => [
                    ['Mandanten öffnen', 'Unter «System → Mandanten» sehen Sie alle Mandanten.'],
                    ['Neuen Mandanten anlegen', 'Name und die E-Mail-Adresse der ersten Administration eintragen. Diese Person erhält eine Einladung (E-Mail-Versand muss eingerichtet sein) und verwaltet den Mandanten anschliessend selbst.'],
                    ['Sperren und aktivieren', 'Ein gesperrter Mandant ist für seine Benutzer nicht mehr zugänglich; die Daten bleiben erhalten.'],
                ],
                'tips' => ['Mandanten sind voneinander getrennt: Verträge und Benutzer eines Mandanten sind für andere nicht sichtbar.'],
                'related' => ['benutzer-rollen'],
            ],
            'sicherung' => [
                'title' => 'Sicherungen erstellen und prüfen', 'group' => 'system', 'audience' => 'system', 'icon' => 'bx-data', 'time' => '4 Min.',
                'summary' => 'Regelmässig sichern und die Sicherung auch wirklich testen.',
                'steps' => [
                    ['Automatisch', 'Der Cronjob «backup.php» sichert täglich. Die Aufbewahrung (Anzahl) stellen Sie unter «Betrieb → Sicherungen» ein.'],
                    ['Von Hand', 'Auf der Seite «Sicherungen» eine Sicherung erstellen; sie wird automatisch geprüft.'],
                    ['Herunterladen und lagern', 'Laden Sie Sicherungen regelmässig herunter und bewahren Sie sie an einem zweiten Ort auf – zusammen mit einer getrennten Kopie der Datei .env.'],
                    ['Wiederherstellung üben', 'Die Wiederherstellung geschieht bewusst nur auf der Kommandozeile in eine separate Datenbank. Üben Sie sie einmal (docs/BACKUP_RESTORE.md).'],
                ],
                'tips' => ['Ohne .env (Anwendungsschlüssel, Passphrase) sind Sicherungen nicht lesbar.'],
                'related' => ['cronjob', 'aktualisieren'],
            ],
            'aktualisieren' => [
                'title' => 'Die Plattform aktualisieren', 'group' => 'system', 'audience' => 'system', 'icon' => 'bx-cloud-download', 'time' => '5 Min.',
                'summary' => 'Neue Versionen aus GitHub einspielen – mit automatischer Rücksicherung bei Fehlern.',
                'steps' => [
                    ['Vorher sichern', 'Unter «Betrieb → Sicherungen» eine Sicherung erstellen.'],
                    ['Nach Updates suchen', 'Unter «Betrieb → Aktualisierung» «Auf neue Version prüfen» drücken. Die installierte und die neueste Version werden angezeigt.'],
                    ['Installieren', 'Bei einer neueren Version «Auf … aktualisieren» wählen und bestätigen. Die Plattform ersetzt Dateien, führt Datenbankänderungen aus und prüft sich selbst; bei einem Fehler wird der alte Stand wiederhergestellt.'],
                    ['Neuigkeiten lesen', 'Nach dem Update sehen Administrationen beim ersten Login die Seite «Neuigkeiten» mit allen Änderungen seit ihrem letzten Besuch. Sie ist jederzeit im Benutzermenü erreichbar.'],
                    ['Bei Problemen', 'Dateirechte lassen sich mit «Rechte reparieren» in Ordnung bringen. Als letzte Hilfe gibt es auf der Kommandozeile «php bin/repair.php». Details: docs/UPDATE.md.'],
                ],
                'tips' => ['Updates, die den Updater selbst ändern, müssen einmal von Hand eingespielt werden.'],
                'related' => ['sicherung'],
            ],
            'signaturanbieter' => [
                'title' => 'Signaturanbieter umstellen', 'group' => 'system', 'audience' => 'system', 'icon' => 'bx-edit', 'time' => '5 Min.',
                'summary' => 'Vom Testmodus zur eigenen Signatur oder zu einem externen Anbieter – bewusst und in zwei Schritten.',
                'steps' => [
                    ['Variante A: Eigene Signatur', 'Unter «Einstellungen → Signatur» den Anbieter auf «Eigene Signatur» stellen. Voraussetzung sind funktionierender E-Mail-Versand und eine https-Adresse. Es ist eine einfache Signatur (SES); das Siegel stammt von der Plattform selbst.'],
                    ['Zugangsdaten eintragen', 'Unter «Einstellungen → Skribble» Adresse, Benutzer, Schlüssel und Modus (zuerst Sandbox/Demo) eintragen.'],
                    ['Anbieter aktivieren', 'Unter «Einstellungen → Signatur» den Anbieter auf Skribble stellen. Fehlen Zugangsdaten, weist die Plattform darauf hin.'],
                    ['Testen', 'Mit der Demo-Umgebung einen Testvorgang durchspielen. Erst danach «Live» wählen (rechtsgültig, kostenpflichtig).'],
                ],
                'tips' => ['Im Testmodus sind Signaturen nicht rechtsgültig.', 'Mehr: docs/SIGNATURE_PROVIDER.md.'],
                'related' => ['email-einrichten'],
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function visibleFor(bool $isSystemAdmin, bool $isAdmin, bool $isInternal): array
    {
        $out = [];
        foreach (self::all() as $slug => $t) {
            $ok = match ($t['audience']) {
                'all' => true,
                'internal' => $isInternal || $isSystemAdmin,
                'admin' => $isAdmin || $isSystemAdmin,
                'system' => $isSystemAdmin,
                default => false,
            };
            if ($ok) {
                $out[$slug] = $t;
            }
        }
        return $out;
    }
}
