# Anleitungen

*Diese Datei wird aus `src/Web/HelpTopics.php` erzeugt (`php bin/guides-export.php`). In der Anwendung stehen dieselben Texte unter «Hilfe & Anleitungen».*

- **Erste Schritte**
  - [Die Plattform in fünf Minuten kennenlernen](#willkommen)
  - [Mit Assistenten Schritt für Schritt eingeben](#assistenten)
- **Mit Verträgen arbeiten**
  - [Einen Vertrag erstellen – was das System tut, was Sie tun](#vertrag-erfassen)
  - [Freigabe beantragen und erteilen](#vertrag-freigabe)
  - [Einen Vertrag unterzeichnen lassen](#vertrag-signieren)
  - [Fristen, Aufgaben und Erinnerungen](#fristen)
  - [Gegenparteien und Mandanten-Code](#gegenparteien)
  - [Mit Vorlagen und Klauseln arbeiten](#vorlagen)
- **Verwaltung eines Mandanten**
  - [Single Sign-on (SSO) einrichten](#sso)
  - [Benutzer einladen und Rollen vergeben](#benutzer-rollen)
  - [Den eigenen Mandanten einrichten](#mandant-einrichten)
- **Mein Konto**
  - [Konto absichern: Passwort, zweiter Faktor, Passkeys](#konto-sicherheit)
- **Systembetrieb (Systemadministration)**
  - [Installation in sechs Schritten](#einrichtung)
  - [Cronjob in Plesk einrichten](#cronjob)
  - [E-Mail-Versand einrichten und testen](#email-einrichten)
  - [Mandanten anlegen und sperren](#mandanten-verwalten)
  - [Sicherungen erstellen und prüfen](#sicherung)
  - [Die Plattform aktualisieren](#aktualisieren)
  - [Signaturanbieter umstellen](#signaturanbieter)

## Erste Schritte

<a id="willkommen"></a>
### Die Plattform in fünf Minuten kennenlernen

_Wo finde ich was? Ein kurzer Rundgang durch Menü, Übersicht und Suche._  
Dauer: ca. 5 Min. · für Mitarbeitende

1. **Das Menü links** – Die Einträge sind in Gruppen geordnet (Hauptbereich, Verwaltung, Hilfe). Ein Klick auf den Gruppennamen klappt die Gruppe auf oder zu; die Plattform merkt sich das, und die Gruppe der aktuellen Seite bleibt offen. Im Hauptbereich finden Sie Start, Verträge, Freigaben, Vorlagen und Gegenparteien. Unter «Verwaltung» erscheinen nur die Punkte, für die Ihre Rolle berechtigt ist. Auf dem Telefon öffnen Sie das Menü mit dem Symbol oben links.
2. **Die Übersicht («Start»)** – Oben sehen Sie, wie viele Verträge in welchem Status sind; ein Klick zeigt die Liste dazu. Darunter stehen Ihre offenen Freigaben, Aufgaben, anstehende Fristen und bald auslaufende Verträge.
3. **Verträge finden** – Unter «Verträge» suchen Sie nach Titel, Nummer, Text, Gegenpartei oder Tag und filtern nach Status, Art, Abteilung und Vertragsende. Sie sehen nur Verträge, auf die Sie Zugriff haben.
4. **Benachrichtigungen** – Die Glocke im Menü zeigt neue Hinweise, etwa fällige Fristen oder Freigabeanfragen. Wenn E-Mail eingerichtet ist, erhalten Sie diese zusätzlich per Mail.
5. **Konto und Abmelden** – Ganz unten im Menü öffnet Ihr Name ein kleines Menü: «Mein Konto & Sicherheit», Mandant wechseln (falls Sie mehreren angehören) und Abmelden.

> **Gut zu wissen**
> - Die Schaltfläche «Hilfe» im Menü bringt Sie jederzeit hierher zurück.
> - Fehlt Ihnen ein Menüpunkt, fehlt meist nur die Berechtigung – fragen Sie Ihre Administration.

<a id="assistenten"></a>
### Mit Assistenten Schritt für Schritt eingeben

_Neue Einträge in kleinen Schritten erfassen – oder wie bisher alle Felder auf einmal._  
Dauer: ca. 2 Min. · für Alle

1. **Assistent starten** – Formulare zum Anlegen (Vertrag, Gegenpartei, Vorlage, Signatur, Freigabe, Frist, Aufgabe, Benutzer, Mandant u. a.) öffnen als Assistent: oben sehen Sie die Schritte und den Fortschritt, darunter jeweils nur wenige Felder.
2. **Weiter und Zurück** – Mit «Weiter» gehen Sie zum nächsten Schritt; Pflichtfelder werden dabei geprüft. Mit «Zurück» oder einem Klick auf einen früheren Schritt korrigieren Sie jederzeit. Die Eingabetaste springt ebenfalls weiter.
3. **Prüfen und speichern** – Der letzte Schritt fasst Ihre Angaben zusammen. Erst dort erscheint die Schaltfläche zum Speichern.
4. **Lieber alles auf einmal?** – Mit «Alle Felder anzeigen» wechseln Sie zur bisherigen Ansicht; mit «Schritt für Schritt» zurück. Die Wahl wird in diesem Browser gemerkt. Beim Bearbeiten bestehender Einträge ist standardmässig die bisherige Ansicht aktiv.

> **Gut zu wissen**
> - Beide Ansichten senden dasselbe Formular – es gelten dieselben Prüfungen und Rechte.
> - Ohne JavaScript zeigt die Plattform immer alle Felder.

## Mit Verträgen arbeiten

<a id="vertrag-erfassen"></a>
### Einen Vertrag erstellen – was das System tut, was Sie tun

_Der ganze Weg vom neuen Vertrag bis zur Unterzeichnung, mit klarer Aufteilung der Arbeit._  
Dauer: ca. 5 Min. · für Mitarbeitende

1. **Startweg wählen** – «Neuer Vertrag» zeigt drei Wege: aus einer Vorlage (empfohlen), mit vorhandenem Word-/PDF-Dokument oder von Grund auf. Bei jedem Weg steht dabei, was das System übernimmt und was Sie tun.
2. **Eckdaten erfassen (Sie)** – Titel und Vertragsart sind Pflicht; Parteien, Laufzeit, Kündigungsfrist und Wert sind optional und später ergänzbar. Das System schlägt Beginn (heute), anwendbares Recht und Währung aus den Mandanten-Einstellungen vor, vergibt die Vertragsnummer und berechnet daraus Kündigungs- und Ablauffristen samt Erinnerungen.
3. **Vertragstext (Sie wählen, das System übernimmt)** – Aus einer Vorlage beantworten Sie nur den Fragebogen; das System setzt Ihre Antworten in den fertigen Text ein. Bei einer Word-Datei übernimmt das System den Text, eine PDF-Datei wird unverändert abgelegt. Jede Fassung wird als Version mit Prüfsumme gesichert.
4. **Freigabe (Sie wählen, das System führt)** – Sie wählen aus der Liste, wer freigibt (auch Sie selbst sind möglich; bei nur einer möglichen Person ist sie vorgewählt). Das System sperrt die Textfassung, benachrichtigt die Personen, protokolliert jeden Entscheid und macht die Freigabe ungültig, falls der Text danach geändert wird. Nach einer Ablehnung oder einem Zurückziehen erstellt das System automatisch einen neuen Entwurf mit demselben Text, damit Sie sofort erneut anfordern können.
5. **Unterzeichnung (Sie bestätigen, das System erledigt)** – Die Unterzeichnenden werden aus den Parteien vorausgefüllt (Gegenparteien mit hinterlegter Kontaktadresse). Das System lädt per E-Mail ein, prüft die Einmalcodes, erstellt das Signaturprotokoll mit Siegel und setzt den Vertrag nach der letzten Unterschrift auf «Aktiv».
6. **Laufzeit (das System überwacht)** – Das System erinnert an Fristen, markiert abgelaufene Verträge und wendet Aufbewahrungsregeln an. Sie legen bei Änderungen einen Nachtrag an und archivieren den Vertrag am Ende.

> **Gut zu wissen**
> - Auf jeder Vertragsseite zeigt das Feld «Ablauf dieses Vertrags», wo Sie stehen und was als Nächstes dran ist.
> - Tags (mit Komma getrennt) erleichtern die spätere Suche.
> - Zusatzfelder Ihrer Organisation erscheinen ebenfalls im Formular.

<a id="vertrag-freigabe"></a>
### Freigabe beantragen und erteilen

_Wie ein Vertrag intern geprüft und freigegeben wird._  
Dauer: ca. 3 Min. · für Mitarbeitende

1. **Freigabe beantragen** – Auf der Vertragsseite die Freigabe anfordern. Der Vertrag wechselt dabei in «In Prüfung», die zuständigen Personen werden benachrichtigt.
2. **Zurückziehen, falls nötig** – Solange noch nicht entschieden wurde, können Sie den Antrag zurückziehen, korrigieren und neu einreichen.
3. **Als Prüfende Person entscheiden** – Unter «Freigaben» sehen Sie, was auf Ihre Entscheidung wartet. Öffnen Sie den Vertrag, prüfen Sie ihn und genehmigen oder lehnen Sie ab. Ihre Entscheidung können Sie kommentieren.
4. **Ergebnis** – Nach der letzten Freigabe wird der Vertrag «Freigegeben» und kann zur Unterzeichnung weitergegeben werden. Jeder Schritt ist im Protokoll des Vertrags nachvollziehbar.

> **Gut zu wissen**
> - Die Freigabe kann auch von der Person erteilt werden, die den Vertrag erstellt hat. Wer es strenger will, wählt eine andere Person.
> - Eine Freigabe gilt für eine bestimmte Textfassung; wird der Text danach geändert, ist eine neue Freigabe nötig.

<a id="vertrag-signieren"></a>
### Einen Vertrag unterzeichnen lassen

_Signaturvorgang starten und verfolgen – und was der Testmodus bedeutet._  
Dauer: ca. 3 Min. · für Mitarbeitende

1. **Signatur starten** – Ist der Vertrag freigegeben, starten Sie auf der Vertragsseite den Signaturvorgang und legen fest, wer unterzeichnet.
2. **Status verfolgen** – Der Stand wird regelmässig abgefragt (Cronjob). Mit «Status abfragen» aktualisieren Sie sofort. Bei der eigenen Signatur sehen Sie je Person, ob sie eingeladen ist, wartet oder unterzeichnet hat, und können mit «Einladung erneut senden» einen neuen Link verschicken (der alte wird ungültig). Ein laufender Vorgang lässt sich zurückziehen.
3. **Eigene Signatur (ohne externen Anbieter)** – Die Unterzeichnenden erhalten eine E-Mail mit persönlichem Link, bestätigen mit einem Einmalcode an dieselbe Adresse und unterzeichnen mit ihrem Namen. Sie wählen, ob alle gleichzeitig oder nacheinander eingeladen werden. Am Ende erhält das PDF ein Signaturprotokoll und ein Siegel der Plattform.
4. **Abschluss** – Sind alle Unterschriften da, wird der Vertrag «Aktiv» und das signierte Dokument liegt beim Vertrag.

> **Gut zu wissen**
> - Zeigt die Plattform einen Hinweis «Testmodus», sind Signaturen NICHT rechtsgültig. Die Systemadministration stellt den Anbieter bewusst um (siehe Anleitung «Signaturanbieter»).
> - Ob eine Signaturart für Ihren Vertrag rechtlich genügt, klären Sie bitte mit Ihrer Rechtsberatung.
> - Die eigene Signatur ist eine einfache elektronische Signatur (SES), keine qualifizierte (QES). Verlangt das Gesetz oder Ihr Vertrag mehr, nutzen Sie einen Anbieter mit entsprechender Stufe.

<a id="fristen"></a>
### Fristen, Aufgaben und Erinnerungen

_Kündigungsfristen im Blick behalten und Aufgaben zu Verträgen verteilen._  
Dauer: ca. 3 Min. · für Mitarbeitende

1. **Beginn, Ende und Kündigungsfrist erfassen** – Aus diesen Angaben leitet die Plattform Fristen ab, etwa den letzten Kündigungstermin.
2. **Eigene Fristen hinzufügen** – Auf der Vertragsseite unter «Frist hinzufügen» ein Datum und eine Bezeichnung eintragen, z. B. «Preisanpassung prüfen».
3. **Aufgaben vergeben** – Mit «Aufgabe hinzufügen» weisen Sie einer Person eine Aufgabe mit Fälligkeit zu. Erledigte Aufgaben haken Sie ab.
4. **Erinnerungen erhalten** – Anstehende Fristen erscheinen in der Übersicht (nächste 90 Tage) und als Benachrichtigung. Das funktioniert nur, wenn der Cronjob läuft.

> **Gut zu wissen**
> - Erinnerungen werden nicht doppelt verschickt.
> - Laufen Fristen nicht, meldet die Diagnose der Systemadministration, dass der Cronjob fehlt.

<a id="gegenparteien"></a>
### Gegenparteien und Mandanten-Code

_Gegenparteien werden nicht mehr angelegt, sondern über den Mandanten-Code verbunden._  
Dauer: ca. 3 Min. · für Mitarbeitende

1. **Eigenen Code weitergeben** – Unter «Gegenparteien» sehen Sie den Mandanten-Code Ihrer Organisation (Format XXXX-XXXX-XXXX). Geben Sie ihn an Vertragspartner weiter, die die Plattform ebenfalls nutzen. Mit dem Code allein geschieht nichts – jede Anfrage muss bestätigt werden.
2. **Mit Code verbinden** – Haben Sie den Code einer anderen Organisation erhalten, tragen Sie ihn unter «Mit einer Gegenseite verbinden» ein. Ihre Anfrage erscheint dort als ausstehend.
3. **Anfrage bestätigen** – Die Mandantenadministration der Gegenseite bestätigt oder lehnt ab. Nach der Bestätigung erscheint die andere Organisation bei beiden als Gegenpartei und kann in Verträgen gewählt werden.
4. **Verträge teilen** – Verbundene Gegenparteien sehen Verträge, in denen sie Partei sind, ab dem Status «Freigegeben» schreibgeschützt unter «Geteilte Verträge». Entwürfe, interne Notizen und Kommentare bleiben privat.
5. **Verbindung beenden** – Eine Verbindung kann jederzeit beendet werden; die Freigabe endet sofort. Bestehende Verträge bleiben unverändert.

> **Gut zu wissen**
> - Ältere, von Hand angelegte Gegenparteien bleiben als «Altbestand» sichtbar, lassen sich aber nicht mehr neu auswählen.
> - Einen neuen Code erzeugen Sie, wenn der alte weitergegeben wurde, den Sie nicht mehr verwenden möchten.

<a id="vorlagen"></a>
### Mit Vorlagen und Klauseln arbeiten

_Wiederkehrende Verträge aus geprüften Vorlagen erstellen._  
Dauer: ca. 4 Min. · für Mitarbeitende

1. **Vorlage wählen** – Unter «Vorlagen» sehen Sie die freigegebenen Vorlagen. Die Vorschau zeigt den Text vor der Verwendung.
2. **Vertrag aus Vorlage erstellen** – Auf der Vorlagenseite führt Sie ein Fragebogen Schritt für Schritt durch die Angaben (eine Frage pro Schritt, mit Hinweisen). Daraus entsteht ein Entwurf, den Sie anschliessend anpassen. Wer Vorlagen pflegt, kann bei jedem Feld eigene Fragen und Hinweise hinterlegen.
3. **Eigene Vorlagen pflegen** – Wer Vorlagen verwalten darf, legt neue an und erstellt Versionen. Neue Versionen werden geprüft und erst nach Entscheid verwendbar.
4. **Klauseln** – Wiederverwendbare Textbausteine (Klauseln) pflegen Sie in der Klauselbibliothek.

> **Gut zu wissen**
> - Vorlagen sind ein Hilfsmittel – die rechtliche Prüfung ersetzen sie nicht.

## Verwaltung eines Mandanten

<a id="sso"></a>
### Single Sign-on (SSO) einrichten

_Mitarbeitende melden sich mit dem Identitätsanbieter Ihrer Organisation an (OpenID Connect)._  
Dauer: ca. 5 Min. · für Administration

1. **Beim Anbieter vorbereiten** – Legen Sie beim Identitätsanbieter eine Web-Anwendung an. Redirect-URI und Kürzel zeigt «Verwaltung → Single Sign-on». Notieren Sie Issuer-Adresse, Client-ID und (falls vorhanden) Client-Geheimnis.
2. **Hier eintragen** – Tragen Sie die Angaben im Assistenten ein. Das Geheimnis wird verschlüsselt gespeichert.
3. **Zugang festlegen** – Mit erlaubten E-Mail-Domains finden Mitarbeitende ihren Mandanten über die E-Mail-Adresse. Neue Konten entstehen nur, wenn Sie die automatische Anlage ausdrücklich einschalten; Administratorrechte werden nie automatisch vergeben.
4. **Aktivieren und testen** – Beim Speichern mit aktiviertem SSO wird der Anbieter geprüft. Testen Sie die Anmeldung zuerst mit einem Testkonto («Mit SSO anmelden» auf der Anmeldeseite).

> **Gut zu wissen**
> - Passwort- und Passkey-Anmeldung bleiben immer möglich.
> - Eine SSO-Sitzung gilt nur für Ihren Mandanten.
> - Unterstützt wird RS256; getestet wurde gegen einen simulierten Anbieter.

<a id="benutzer-rollen"></a>
### Benutzer einladen und Rollen vergeben

_Neue Personen einladen, Rollen zuweisen, Abteilungen bilden._  
Dauer: ca. 4 Min. · für Administration

1. **Verwaltung öffnen** – Im Menü auf «Verwaltung» und dann «Benutzer & Rollen».
2. **Person einladen** – E-Mail-Adresse, Name und Rolle angeben. Die Person erhält einen Einladungslink per E-Mail und setzt ihr Passwort selbst. Dafür muss der E-Mail-Versand eingerichtet sein.
3. **Rolle ändern oder sperren** – In der Benutzerliste lassen sich Rolle und Status ändern. Gesperrte Personen können sich nicht mehr anmelden. Die Einladung kann erneut versendet werden.
4. **Abteilungen** – Unter «Abteilungen» legen Sie Abteilungen an und weisen Mitglieder zu. Der Zugriff auf Verträge richtet sich nach Rolle und Abteilung.
5. **Zweiten Faktor zurücksetzen** – Hat jemand Authenticator-App oder Gerät verloren, setzen Sie den zweiten Faktor dieser Person hier zurück. Die Anmeldung mit Passwort bleibt möglich.

> **Gut zu wissen**
> - Vergeben Sie jeweils die kleinste Rolle, die für die Arbeit genügt.
> - Welche Rolle was darf, steht in docs/ROLES_PERMISSIONS.md.

<a id="mandant-einrichten"></a>
### Den eigenen Mandanten einrichten

_Stammdaten, Vertragsarten und Zusatzfelder festlegen, bevor die Arbeit beginnt._  
Dauer: ca. 5 Min. · für Administration

1. **Stammdaten** – Unter «Verwaltung → Mandant»: Name, Zeitzone, Standard-Rechtsgebiet und Präfix der Vertragsnummern festlegen. Das Präfix erscheint vor jeder Vertragsnummer.
2. **Vertragsarten** – Unter «Vertragsarten» legen Sie die Arten an, die bei der Erfassung zur Auswahl stehen (z. B. Mietvertrag, Dienstleistung). Der Code besteht aus Kleinbuchstaben, Ziffern und Unterstrich.
3. **Zusatzfelder** – Unter «Zusatzfelder» definieren Sie eigene Angaben, die bei Verträgen zusätzlich erfasst werden: Text, Zahl, Datum, Ja/Nein oder Auswahl (Optionen durch Komma trennen). Pflichtfelder sind markierbar.
4. **Benutzer einladen** – Danach laden Sie Ihr Team ein (Anleitung «Benutzer einladen und Rollen vergeben»).

> **Gut zu wissen**
> - Wählen Sie den Code einer Vertragsart oder eines Feldes sorgfältig: kurz, sprechend, ohne Sonderzeichen.

## Mein Konto

<a id="konto-sicherheit"></a>
### Konto absichern: Passwort, zweiter Faktor, Passkeys

_Alle Möglichkeiten, Ihr Konto zu schützen – alle freiwillig._  
Dauer: ca. 4 Min. · für Alle

1. **Mein Konto öffnen** – Unten im Menü auf Ihren Namen klicken, dann «Mein Konto & Sicherheit». Oben wechseln Sie zwischen Profil, Passwort, Zweiter Faktor, Passkeys und Sitzungen.
2. **Passwort ändern** – Unter «Passwort»: aktuelles Passwort, dann zweimal das neue (mindestens 12 Zeichen; ein Satz aus mehreren Wörtern ist stark und leicht zu merken). Alle anderen Sitzungen werden beendet.
3. **Passkey einrichten (empfohlen)** – Unter «Passkeys» auf «Passkey hinzufügen» klicken und die Abfrage Ihres Geräts bestätigen (Fingerabdruck, Gesicht, Geräte-PIN oder Sicherheitsschlüssel). Danach melden Sie sich ohne Passwort an. Mehrere Passkeys sind möglich, z. B. Telefon und Laptop.
4. **Zweiter Faktor (Authenticator-App)** – Alternativ unter «Zweiter Faktor» auf «Einrichten» klicken, den angezeigten Schlüssel in eine Authenticator-App eingeben (auf dem Telefon geht auch der «otpauth-Link») und den 6-stelligen Code bestätigen. Einen QR-Code gibt es bewusst nicht, damit der Schlüssel nie an Dritte übermittelt wird. Speichern Sie die angezeigten Wiederherstellungscodes sofort an einem sicheren Ort – sie werden nur einmal angezeigt.
5. **Sitzungen beenden** – Unter «Sitzungen» melden Sie sich auf allen Geräten ab, z. B. nach Verlust eines Geräts.

> **Gut zu wissen**
> - Passkeys und zweiter Faktor sind freiwillig; die Anmeldung mit Passwort bleibt immer möglich.
> - Passkeys funktionieren nur über eine HTTPS-Adresse.

## Systembetrieb (Systemadministration)

<a id="einrichtung"></a>
### Installation in sechs Schritten

_Vom hochgeladenen Paket zur laufenden Plattform – der Assistent führt Sie durch._  
Dauer: ca. 15 Min. · für Systemadministration

1. **Vorbereiten** – Paket auf dem Server entpacken, in Plesk den Dokumentenstamm auf «public» setzen (empfohlen), ein HTTPS-Zertifikat aktivieren und eine leere Datenbank samt Benutzer anlegen. Details: docs/INSTALLATION_PLESK.md.
2. **Schritt 1 – Start** – Adresse «https://IHRE-DOMAIN/setup» öffnen. Die Systemprüfung zeigt, ob PHP und Verzeichnisse passen. Geben Sie den Setup-Schlüssel aus der Datei SETUP-SCHLUESSEL.txt ein.
3. **Schritt 2 – Adresse** – Öffentliche Adresse der Plattform und maximale Dateigrösse eintragen.
4. **Schritt 3 – Datenbank** – Host, Port, Name, Benutzer und Passwort eintragen und «Verbindung testen» drücken. Erst bei grünem Ergebnis weitergehen.
5. **Schritt 4 – Administration** – Name, E-Mail und ein langes Passwort für das erste Administrationskonto festlegen.
6. **Schritt 5 – E-Mail (optional)** – SMTP-Zugang eintragen oder überspringen und später unter «Einstellungen → E-Mail-Server» nachholen.
7. **Schritt 6 – Abschluss** – Zusammenfassung prüfen und «Installieren» drücken. Danach ist der Assistent gesperrt. Befolgen Sie die Hinweise auf der Abschlussseite (Cronjob, erster Mandant, .env sichern).

> **Gut zu wissen**
> - Der Setup-Schlüssel wird nach der Installation gelöscht.
> - Sichern Sie die Datei .env getrennt vom Rest.

<a id="cronjob"></a>
### Cronjob in Plesk einrichten

_Ohne Cronjob laufen Fristen, Erinnerungen und E-Mail-Versand nicht._  
Dauer: ca. 5 Min. · für Systemadministration

1. **Plesk öffnen** – Websites & Domains → Ihre Domain → «Geplante Aufgaben» → «Aufgabe hinzufügen».
2. **Wartung anlegen** – Typ «Shell-Befehl», Befehl «/opt/plesk/php/8.3/bin/php /PFAD/ZUM/PROJEKT/bin/cron.php», Intervall alle 5 Minuten.
3. **Sicherung anlegen** – Zweite Aufgabe mit «/PFAD/ZUM/PROJEKT/bin/backup.php» (gleicher PHP-Pfad), täglich nachts.
4. **Prüfen** – Nach ein paar Minuten unter «System → Aufgaben» den Status ansehen. Sie können Aufgaben dort auch von Hand ausführen.

> **Gut zu wissen**
> - Der PHP-Pfad muss zur gewählten PHP-Version passen.
> - Die Diagnose warnt, wenn der letzte Lauf älter als 30 Minuten ist.

<a id="email-einrichten"></a>
### E-Mail-Versand einrichten und testen

_SMTP-Zugang eintragen, Absender festlegen, Testmail senden._  
Dauer: ca. 5 Min. · für Systemadministration

1. **E-Mail-Server** – Unter «System → Einstellungen → E-Mail-Server» Server, Port, Verschlüsselung, Benutzer und Passwort eintragen. Das Passwort wird verschlüsselt gespeichert und nie angezeigt.
2. **Absender** – Unter «Absender» Adresse und Namen festlegen, die Empfangende sehen.
3. **Testmail** – Auf der Seite «E-Mail-Server» eine Testmail an sich selbst senden. Kommt sie an, funktionieren Einladungen und Erinnerungen.

> **Gut zu wissen**
> - Meist: Port 465 mit TLS oder 587 mit STARTTLS.
> - Weitere Hinweise: docs/SMTP.md.

<a id="mandanten-verwalten"></a>
### Mandanten anlegen und sperren

_Ein Mandant ist eine Firma oder Organisation mit eigenen Verträgen und Benutzern._  
Dauer: ca. 3 Min. · für Systemadministration

1. **Mandanten öffnen** – Unter «System → Mandanten» sehen Sie alle Mandanten.
2. **Neuen Mandanten anlegen** – Name und die E-Mail-Adresse der ersten Administration eintragen. Diese Person erhält eine Einladung (E-Mail-Versand muss eingerichtet sein) und verwaltet den Mandanten anschliessend selbst.
3. **Sperren und aktivieren** – Ein gesperrter Mandant ist für seine Benutzer nicht mehr zugänglich; die Daten bleiben erhalten.

> **Gut zu wissen**
> - Mandanten sind voneinander getrennt: Verträge und Benutzer eines Mandanten sind für andere nicht sichtbar.

<a id="sicherung"></a>
### Sicherungen erstellen und prüfen

_Regelmässig sichern und die Sicherung auch wirklich testen._  
Dauer: ca. 4 Min. · für Systemadministration

1. **Automatisch** – Der Cronjob «backup.php» sichert täglich. Die Aufbewahrung (Anzahl) stellen Sie unter «Betrieb → Sicherungen» ein.
2. **Von Hand** – Auf der Seite «Sicherungen» eine Sicherung erstellen; sie wird automatisch geprüft.
3. **Herunterladen und lagern** – Laden Sie Sicherungen regelmässig herunter und bewahren Sie sie an einem zweiten Ort auf – zusammen mit einer getrennten Kopie der Datei .env.
4. **Wiederherstellung üben** – Die Wiederherstellung geschieht bewusst nur auf der Kommandozeile in eine separate Datenbank. Üben Sie sie einmal (docs/BACKUP_RESTORE.md).

> **Gut zu wissen**
> - Ohne .env (Anwendungsschlüssel, Passphrase) sind Sicherungen nicht lesbar.

<a id="aktualisieren"></a>
### Die Plattform aktualisieren

_Neue Versionen aus GitHub einspielen – mit automatischer Rücksicherung bei Fehlern._  
Dauer: ca. 5 Min. · für Systemadministration

1. **Vorher sichern** – Unter «Betrieb → Sicherungen» eine Sicherung erstellen.
2. **Nach Updates suchen** – Unter «Betrieb → Aktualisierung» «Auf neue Version prüfen» drücken. Die installierte und die neueste Version werden angezeigt.
3. **Installieren** – Bei einer neueren Version «Auf … aktualisieren» wählen und bestätigen. Die Plattform ersetzt Dateien, führt Datenbankänderungen aus und prüft sich selbst; bei einem Fehler wird der alte Stand wiederhergestellt.
4. **Neuigkeiten lesen** – Nach dem Update sehen Administrationen beim ersten Login die Seite «Neuigkeiten» mit allen Änderungen seit ihrem letzten Besuch. Sie ist jederzeit im Benutzermenü erreichbar.
5. **Bei Problemen** – Dateirechte lassen sich mit «Rechte reparieren» in Ordnung bringen. Als letzte Hilfe gibt es auf der Kommandozeile «php bin/repair.php». Details: docs/UPDATE.md.

> **Gut zu wissen**
> - Updates, die den Updater selbst ändern, müssen einmal von Hand eingespielt werden.

<a id="signaturanbieter"></a>
### Signaturanbieter umstellen

_Vom Testmodus zur eigenen Signatur oder zu einem externen Anbieter – bewusst und in zwei Schritten._  
Dauer: ca. 5 Min. · für Systemadministration

1. **Variante A: Eigene Signatur** – Unter «Einstellungen → Signatur» den Anbieter auf «Eigene Signatur» stellen. Voraussetzung sind funktionierender E-Mail-Versand und eine https-Adresse. Es ist eine einfache Signatur (SES); das Siegel stammt von der Plattform selbst.
2. **Zugangsdaten eintragen** – Unter «Einstellungen → Skribble» Adresse, Benutzer, Schlüssel und Modus (zuerst Sandbox/Demo) eintragen.
3. **Anbieter aktivieren** – Unter «Einstellungen → Signatur» den Anbieter auf Skribble stellen. Fehlen Zugangsdaten, weist die Plattform darauf hin.
4. **Testen** – Mit der Demo-Umgebung einen Testvorgang durchspielen. Erst danach «Live» wählen (rechtsgültig, kostenpflichtig).

> **Gut zu wissen**
> - Im Testmodus sind Signaturen nicht rechtsgültig.
> - Mehr: docs/SIGNATURE_PROVIDER.md.
