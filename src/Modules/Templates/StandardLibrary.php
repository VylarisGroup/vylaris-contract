<?php

declare(strict_types=1);

namespace App\Modules\Templates;

/**
 * Mitgelieferte Standardvorlagen und -klauseln (Schweizer Recht, deutschsprachig).
 *
 * Es sind allgemeine Ausgangstexte, keine Rechtsberatung. Sie werden im Mandanten als «ungeprüft» angelegt und müssen
 * von einer fachkundigen Person geprüft und angepasst werden, bevor sie produktiv verwendet werden.
 */
final class StandardLibrary
{
    /** @return list<array{code:string,title:string,body:string}> */
    public static function clauses(): array
    {
        return [
            ['code' => 'SALVATORISCH', 'title' => 'Salvatorische Klausel', 'body' => '<p>Sollte eine Bestimmung dieses Vertrags ganz oder teilweise unwirksam oder undurchführbar sein oder werden, bleibt die Wirksamkeit der übrigen Bestimmungen unberührt. Die Parteien ersetzen die betroffene Bestimmung durch eine wirksame Regelung, die dem wirtschaftlichen Zweck der ursprünglichen möglichst nahekommt.</p>'],
            ['code' => 'SCHRIFTFORM', 'title' => 'Änderungen und Nebenabreden', 'body' => '<p>Änderungen und Ergänzungen dieses Vertrags bedürfen der Schriftform oder einer von beiden Parteien nachweisbar bestätigten elektronischen Form. Dies gilt auch für die Aufhebung dieses Formerfordernisses. Mündliche Nebenabreden bestehen nicht.</p>'],
            ['code' => 'RECHT_CH', 'title' => 'Anwendbares Recht (Schweiz)', 'body' => '<p>Dieser Vertrag untersteht schweizerischem Recht unter Ausschluss der Kollisionsnormen des Bundesgesetzes über das Internationale Privatrecht (IPRG).</p>'],
            ['code' => 'AUSFERTIGUNG', 'title' => 'Ausfertigung und elektronische Unterzeichnung', 'body' => '<p>Dieser Vertrag kann in mehreren Exemplaren oder elektronisch unterzeichnet werden. Alle Exemplare bilden zusammen eine Urkunde. Gesetzliche Formvorschriften, die für einzelne Bestimmungen gelten, bleiben vorbehalten.</p>'],
            ['code' => 'ABTRETUNG', 'title' => 'Abtretung', 'body' => '<p>Rechte und Pflichten aus diesem Vertrag dürfen nur mit vorgängiger schriftlicher Zustimmung der anderen Partei abgetreten oder übertragen werden. Die Zustimmung darf nicht ohne sachlichen Grund verweigert werden.</p>'],
            ['code' => 'HOEHERE_GEWALT', 'title' => 'Höhere Gewalt', 'body' => '<p>Keine Partei haftet für Verzögerungen oder Ausfälle, die auf Ereignisse ausserhalb ihres zumutbaren Einflussbereichs zurückzuführen sind (z. B. Naturereignisse, behördliche Anordnungen, Krieg, Pandemie, Ausfall allgemeiner Infrastruktur). Die betroffene Partei informiert die andere unverzüglich und bemüht sich, die Auswirkungen zu begrenzen.</p>'],
        ];
    }

    /**
     * @return list<array{type:string,name:string,jurisdiction:string,fields:list<array<string,mixed>>,body:string}>
     */
    public static function templates(): array
    {
        $parties = [
            self::f('partei_a', 'Ihre Firma / Partei A', 'text', true, 'Wie lautet Ihre Firma bzw. Ihr Name (Partei A)?', 'Vollständige Bezeichnung laut Handelsregister, inkl. Rechtsform.'),
            self::f('partei_a_adresse', 'Adresse Partei A', 'text', true, 'Wie lautet Ihre Adresse?', 'Strasse, PLZ, Ort.'),
            self::f('partei_b', 'Vertragspartner / Partei B', 'text', true, 'Wer ist Ihr Vertragspartner (Partei B)?', 'Vollständige Bezeichnung inkl. Rechtsform.'),
            self::f('partei_b_adresse', 'Adresse Partei B', 'text', true, 'Wie lautet die Adresse Ihres Vertragspartners?', 'Strasse, PLZ, Ort.'),
        ];
        $head = '<h1>{{titel_vertrag}}</h1><p>zwischen</p><p><strong>{{partei_a}}</strong>, {{partei_a_adresse}} (nachfolgend «Partei A»)</p><p>und</p><p><strong>{{partei_b}}</strong>, {{partei_b_adresse}} (nachfolgend «Partei B»)</p>';
        $gerichtsstand = self::f('gerichtsstand', 'Gerichtsstand (Ort)', 'text', true, 'An welchem Ort sollen Streitigkeiten beurteilt werden?', 'Üblich ist der Sitz einer der Parteien. Zwingende Gerichtsstände (z. B. bei Konsumentenverträgen) gehen vor.');
        $schluss = '{{clause:RECHT_CH}}<p>Für Streitigkeiten aus oder im Zusammenhang mit diesem Vertrag sind die ordentlichen Gerichte in {{gerichtsstand}} zuständig.</p>{{clause:SCHRIFTFORM}}{{clause:SALVATORISCH}}{{clause:AUSFERTIGUNG}}';

        $withHead = static fn (string $title, string $body): string => str_replace('{{titel_vertrag}}', $title, $head) . $body;
        $fields = static fn (array ...$more): array => array_merge($parties, ...$more);

        return [
            // ---------------------------------------------------------------- Dienstleistung
            [
                'type' => 'service', 'name' => 'Dienstleistungsvertrag (Standard)', 'jurisdiction' => 'CH',
                'fields' => $fields([
                    self::f('leistung', 'Leistungsbeschreibung', 'text', true, 'Welche Leistung soll erbracht werden?', 'Möglichst konkret: Was, in welchem Umfang, bis wann?'),
                    self::f('beginn', 'Beginn', 'date', true, 'Ab wann gilt der Vertrag?', ''),
                    self::f('laufzeit', 'Laufzeit', 'select', true, 'Wie lange soll der Vertrag laufen?', 'Bei «Unbefristet» läuft der Vertrag bis zur Kündigung.', ['Unbefristet', 'Befristet']),
                    self::f('ende', 'Ende (nur bei befristeter Laufzeit)', 'date', false, 'Bis wann läuft der Vertrag?', 'Nur ausfüllen, wenn befristet.'),
                    self::f('kuendigungsfrist', 'Kündigungsfrist (Tage)', 'number', true, 'Mit welcher Frist kann gekündigt werden?', 'Üblich sind 30 bis 90 Tage.'),
                    self::f('verguetung_modell', 'Vergütung', 'select', true, 'Wie wird die Leistung vergütet?', 'Pauschal = fester Betrag; nach Aufwand = Stundensatz.', ['Pauschal', 'Nach Aufwand']),
                    self::f('betrag', 'Betrag (Pauschale bzw. Stundensatz)', 'number', true, 'Wie hoch ist der Betrag?', 'Ohne Mehrwertsteuer.'),
                    self::f('waehrung', 'Währung', 'select', true, 'In welcher Währung?', '', ['CHF', 'EUR']),
                    self::f('zahlungsfrist', 'Zahlungsfrist (Tage)', 'number', true, 'Innert wie vielen Tagen ist zu zahlen?', 'Üblich sind 30 Tage.'),
                    $gerichtsstand,
                ]),
                'body' => $withHead('Dienstleistungsvertrag', '<h2>1. Gegenstand</h2><p>Partei A erbringt für Partei B folgende Leistung: {{leistung}}</p><p>Partei A erbringt die Leistung sorgfältig und nach dem Stand der Technik. Sie ist in der Organisation der Leistungserbringung frei, soweit nichts anderes vereinbart ist.</p>'
                    . '<h2>2. Dauer und Kündigung</h2><p>Der Vertrag beginnt am {{beginn}}.</p>{{#if laufzeit == "Befristet"}}<p>Er ist befristet und endet am {{ende}}, ohne dass es einer Kündigung bedarf.</p>{{else}}<p>Er wird auf unbestimmte Zeit abgeschlossen und kann von beiden Parteien mit einer Frist von {{kuendigungsfrist}} Tagen schriftlich gekündigt werden.</p>{{/if}}<p>Das Recht zur fristlosen Kündigung aus wichtigem Grund bleibt vorbehalten.</p>'
                    . '<h2>3. Vergütung</h2>{{#if verguetung_modell == "Pauschal"}}<p>Partei B bezahlt Partei A eine Pauschale von {{waehrung}} {{betrag}} (exkl. Mehrwertsteuer).</p>{{else}}<p>Partei B bezahlt Partei A die geleisteten Stunden zu einem Stundensatz von {{waehrung}} {{betrag}} (exkl. Mehrwertsteuer). Partei A weist den Aufwand nachvollziehbar aus.</p>{{/if}}<p>Rechnungen sind innert {{zahlungsfrist}} Tagen nach Erhalt ohne Abzug zu bezahlen.</p>'
                    . '<h2>4. Mitwirkung</h2><p>Partei B stellt die für die Leistungserbringung nötigen Informationen und Zugänge rechtzeitig und vollständig zur Verfügung.</p>'
                    . '<h2>5. Vertraulichkeit und Datenschutz</h2><p>Die Parteien behandeln alle nicht öffentlichen Informationen der anderen Partei vertraulich und bearbeiten Personendaten nur im Rahmen der anwendbaren Datenschutzgesetze. Diese Pflicht gilt über das Vertragsende hinaus.</p>'
                    . '<h2>6. Haftung</h2><p>Die Parteien haften für Schäden, die sie absichtlich oder grobfahrlässig verursachen. Die Haftung für leichte Fahrlässigkeit wird, soweit gesetzlich zulässig, ausgeschlossen. Die Haftung für indirekte Schäden und entgangenen Gewinn wird, soweit gesetzlich zulässig, ausgeschlossen.</p>{{clause:ABTRETUNG}}{{clause:HOEHERE_GEWALT}}'
                    . '<h2>7. Schlussbestimmungen</h2>' . $schluss),
            ],
            // ---------------------------------------------------------------- Lizenz
            [
                'type' => 'license', 'name' => 'Software-Lizenzvertrag (Standard)', 'jurisdiction' => 'CH',
                'fields' => $fields([
                    self::f('software', 'Software / Produkt', 'text', true, 'Welche Software wird lizenziert?', 'Name und, falls bekannt, Version.'),
                    self::f('lizenzmodell', 'Lizenzmodell', 'select', true, 'Wie viele dürfen die Software nutzen?', 'Einzelplatz = eine Person; Mehrplatz = begrenzte Anzahl; Unternehmensweit = alle Mitarbeitenden.', ['Einzelplatz', 'Mehrplatz', 'Unternehmensweit']),
                    self::f('anzahl_nutzer', 'Anzahl Nutzende (bei Mehrplatz)', 'number', false, 'Wie viele Nutzende sind erlaubt?', 'Nur bei Mehrplatz.'),
                    self::f('beginn', 'Beginn', 'date', true, 'Ab wann gilt die Lizenz?', ''),
                    self::f('laufzeit_monate', 'Laufzeit (Monate)', 'number', true, 'Wie lange läuft die Lizenz (in Monaten)?', 'Sie verlängert sich um dieselbe Dauer, wenn nicht gekündigt wird.'),
                    self::f('kuendigungsfrist', 'Kündigungsfrist (Tage)', 'number', true, 'Mit welcher Frist kann auf das Laufzeitende gekündigt werden?', ''),
                    self::f('gebuehr', 'Lizenzgebühr pro Jahr', 'number', true, 'Wie hoch ist die jährliche Gebühr?', 'Ohne Mehrwertsteuer.'),
                    self::f('waehrung', 'Währung', 'select', true, 'In welcher Währung?', '', ['CHF', 'EUR']),
                    self::f('support', 'Support und Updates inbegriffen', 'boolean', false, 'Sind Support und Updates inbegriffen?', ''),
                    $gerichtsstand,
                ]),
                'body' => $withHead('Software-Lizenzvertrag', '<h2>1. Gegenstand</h2><p>Partei A (Lizenzgeberin) räumt Partei B (Lizenznehmerin) das Recht ein, die Software «{{software}}» nach Massgabe dieses Vertrags zu nutzen.</p>'
                    . '<h2>2. Umfang der Lizenz</h2><p>Die Lizenz ist nicht ausschliesslich, nicht übertragbar und nicht unterlizenzierbar.</p>{{#if lizenzmodell == "Einzelplatz"}}<p>Die Software darf von einer Person genutzt werden.</p>{{/if}}{{#if lizenzmodell == "Mehrplatz"}}<p>Die Software darf von höchstens {{anzahl_nutzer}} Personen gleichzeitig bzw. mit benannten Zugängen genutzt werden.</p>{{/if}}{{#if lizenzmodell == "Unternehmensweit"}}<p>Die Software darf von allen Mitarbeitenden von Partei B für deren interne Geschäftszwecke genutzt werden.</p>{{/if}}<p>Nicht erlaubt sind das Kopieren über die Sicherungskopie hinaus, das Rückentwickeln (soweit gesetzlich nicht zwingend zulässig) und die Überlassung an Dritte.</p>'
                    . '<h2>3. Laufzeit und Kündigung</h2><p>Die Lizenz beginnt am {{beginn}} und läuft {{laufzeit_monate}} Monate. Sie verlängert sich jeweils um dieselbe Dauer, wenn sie nicht mit einer Frist von {{kuendigungsfrist}} Tagen auf das Laufzeitende gekündigt wird.</p>'
                    . '<h2>4. Gebühr</h2><p>Die Lizenzgebühr beträgt {{waehrung}} {{gebuehr}} pro Jahr (exkl. Mehrwertsteuer), zahlbar jährlich im Voraus innert 30 Tagen nach Rechnungsstellung.</p>'
                    . '<h2>5. Support und Updates</h2>{{#if support}}<p>Support und Updates sind in der Gebühr inbegriffen. Umfang und Reaktionszeiten richten sich nach den Support-Bedingungen der Lizenzgeberin in ihrer jeweils gültigen Fassung.</p>{{else}}<p>Support und Updates sind nicht inbegriffen und können separat vereinbart werden.</p>{{/if}}'
                    . '<h2>6. Rechte und Gewährleistung</h2><p>Sämtliche Rechte an der Software bleiben bei der Lizenzgeberin. Sie gewährleistet, dass die Software im Wesentlichen der Dokumentation entspricht. Die Haftung ist auf grobfahrlässig oder absichtlich verursachte Schäden beschränkt, soweit gesetzlich zulässig. Für Datenverlust haftet die Lizenzgeberin nur, wenn die Lizenznehmerin angemessene Datensicherungen vorgenommen hat.</p>{{clause:ABTRETUNG}}'
                    . '<h2>7. Schlussbestimmungen</h2>' . $schluss),
            ],
            // ---------------------------------------------------------------- Nutzungsbedingungen
            [
                'type' => 'terms', 'name' => 'Nutzungsbedingungen Online-Dienst (Standard)', 'jurisdiction' => 'CH',
                'fields' => [
                    self::f('partei_a', 'Anbieter (Ihre Firma)', 'text', true, 'Wie lautet Ihre Firma (Anbieter)?', 'Vollständige Bezeichnung inkl. Rechtsform.'),
                    self::f('partei_a_adresse', 'Adresse des Anbieters', 'text', true, 'Wie lautet Ihre Adresse?', ''),
                    self::f('kontakt', 'Kontakt-E-Mail', 'text', true, 'Unter welcher E-Mail-Adresse erreichen Nutzende Sie?', ''),
                    self::f('dienst', 'Name des Dienstes', 'text', true, 'Wie heisst der Dienst?', 'z. B. der Name der Plattform oder App.'),
                    self::f('beschreibung', 'Kurzbeschreibung', 'text', true, 'Was leistet der Dienst in einem Satz?', ''),
                    self::f('entgeltlich', 'Kostenpflichtig', 'boolean', false, 'Ist die Nutzung kostenpflichtig?', ''),
                    self::f('preisangabe', 'Preis / Preismodell', 'text', false, 'Was kostet die Nutzung?', 'Nur bei kostenpflichtiger Nutzung, z. B. «CHF 19 pro Monat, exkl. MWST».'),
                    self::f('kuendigungsfrist', 'Kündigungsfrist (Tage)', 'number', false, 'Mit welcher Frist können Nutzende kündigen?', 'Nur bei kostenpflichtiger Nutzung.'),
                    $gerichtsstand,
                ],
                'body' => '<h1>Nutzungsbedingungen {{dienst}}</h1><p>Anbieter: <strong>{{partei_a}}</strong>, {{partei_a_adresse}}, {{kontakt}} (nachfolgend «Anbieter»).</p>'
                    . '<h2>1. Geltungsbereich</h2><p>Diese Nutzungsbedingungen regeln die Nutzung des Dienstes «{{dienst}}» ({{beschreibung}}). Mit der Registrierung oder Nutzung akzeptieren Nutzende diese Bedingungen.</p>'
                    . '<h2>2. Nutzerkonto</h2><p>Nutzende sind für die Geheimhaltung ihrer Zugangsdaten und für alle Handlungen unter ihrem Konto verantwortlich. Angaben bei der Registrierung müssen wahr und vollständig sein.</p>'
                    . '<h2>3. Zulässige Nutzung</h2><p>Der Dienst darf nur rechtmässig genutzt werden. Untersagt sind insbesondere Handlungen, die den Betrieb stören oder gefährden, das Umgehen von Sicherheitsmassnahmen, die Verbreitung rechtswidriger Inhalte sowie die missbräuchliche automatisierte Nutzung.</p>'
                    . '<h2>4. Preise und Zahlung</h2>{{#if entgeltlich}}<p>Die Nutzung ist kostenpflichtig: {{preisangabe}}. Die Kündigung ist mit einer Frist von {{kuendigungsfrist}} Tagen auf das Ende der laufenden Abrechnungsperiode möglich.</p>{{else}}<p>Die Nutzung ist kostenlos. Der Anbieter kann künftig kostenpflichtige Funktionen anbieten und informiert darüber vorgängig.</p>{{/if}}'
                    . '<h2>5. Inhalte der Nutzenden</h2><p>Nutzende bleiben Inhaber ihrer Inhalte und räumen dem Anbieter die Rechte ein, die zum Betrieb des Dienstes nötig sind. Sie sichern zu, dass sie zur Einräumung berechtigt sind.</p>'
                    . '<h2>6. Datenschutz</h2><p>Der Anbieter bearbeitet Personendaten nach der geltenden Datenschutzgesetzgebung und gemäss seiner Datenschutzerklärung.</p>'
                    . '<h2>7. Verfügbarkeit und Haftung</h2><p>Der Anbieter bemüht sich um eine hohe Verfügbarkeit, kann sie aber nicht garantieren. Die Haftung des Anbieters ist, soweit gesetzlich zulässig, auf Absicht und grobe Fahrlässigkeit beschränkt. Zwingende Haftung, insbesondere für Personenschäden, bleibt unberührt.</p>'
                    . '<h2>8. Änderungen</h2><p>Der Anbieter kann diese Bedingungen aus sachlichen Gründen ändern und informiert die Nutzenden angemessen im Voraus. Wer nicht einverstanden ist, kann die Nutzung beenden.</p>'
                    . '{{clause:RECHT_CH}}<p>Gerichtsstand ist {{gerichtsstand}}, soweit nicht zwingende Gerichtsstände (z. B. für Konsumentinnen und Konsumenten) vorgehen.</p>{{clause:SALVATORISCH}}',
            ],
            // ---------------------------------------------------------------- DPA
            [
                'type' => 'dpa', 'name' => 'Auftragsbearbeitungsvereinbarung (Standard)', 'jurisdiction' => 'CH',
                'fields' => [
                    self::f('partei_a', 'Verantwortliche Stelle (Auftraggeber)', 'text', true, 'Wer ist verantwortlich und beauftragt die Datenbearbeitung?', 'Vollständige Bezeichnung inkl. Rechtsform.'),
                    self::f('partei_a_adresse', 'Adresse Verantwortliche', 'text', true, 'Adresse der verantwortlichen Stelle?', ''),
                    self::f('partei_b', 'Auftragsbearbeiter', 'text', true, 'Wer bearbeitet die Daten im Auftrag?', ''),
                    self::f('partei_b_adresse', 'Adresse Auftragsbearbeiter', 'text', true, 'Adresse des Auftragsbearbeiters?', ''),
                    self::f('hauptvertrag', 'Hauptvertrag', 'text', true, 'Zu welchem Vertrag gehört diese Vereinbarung?', 'z. B. «Dienstleistungsvertrag vom 1. März 2026».'),
                    self::f('gegenstand', 'Gegenstand der Bearbeitung', 'text', true, 'Was wird im Auftrag bearbeitet?', 'z. B. «Hosting der Kundendatenbank».'),
                    self::f('datenkategorien', 'Kategorien von Personendaten', 'text', true, 'Welche Arten von Personendaten sind betroffen?', 'z. B. Name, Kontaktdaten, Vertragsdaten.'),
                    self::f('betroffene', 'Kategorien betroffener Personen', 'text', true, 'Wessen Daten sind betroffen?', 'z. B. Kundinnen und Kunden, Mitarbeitende.'),
                    self::f('ort', 'Ort der Bearbeitung', 'select', true, 'Wo werden die Daten bearbeitet?', 'Bei Bearbeitung ausserhalb der Schweiz/EU/EWR sind zusätzliche Garantien nötig; bitte fachlich prüfen lassen.', ['Schweiz', 'EU/EWR', 'Drittland']),
                    self::f('dsgvo', 'EU-DSGVO ist anwendbar', 'boolean', false, 'Fällt die Bearbeitung zusätzlich unter die EU-DSGVO?', 'Z. B. bei Betroffenen in der EU.'),
                    self::f('unterauftrag', 'Unterauftragsbearbeiter erlaubt', 'boolean', false, 'Darf der Auftragsbearbeiter weitere Dienstleister beiziehen?', ''),
                    self::f('meldefrist', 'Meldefrist bei Datenschutzverletzung (Stunden)', 'number', true, 'Wie schnell muss eine Datenschutzverletzung gemeldet werden?', 'Üblich sind 24 bis 72 Stunden.'),
                    $gerichtsstand,
                ],
                'body' => '<h1>Vereinbarung über die Auftragsbearbeitung von Personendaten</h1><p>zwischen <strong>{{partei_a}}</strong>, {{partei_a_adresse}} (nachfolgend «Verantwortliche») und <strong>{{partei_b}}</strong>, {{partei_b_adresse}} (nachfolgend «Auftragsbearbeiter»).</p>'
                    . '<h2>1. Gegenstand</h2><p>Der Auftragsbearbeiter bearbeitet im Rahmen des Vertrags «{{hauptvertrag}}» Personendaten im Auftrag der Verantwortlichen. Gegenstand: {{gegenstand}}. Kategorien von Personendaten: {{datenkategorien}}. Kategorien betroffener Personen: {{betroffene}}.</p>'
                    . '<h2>2. Weisungen</h2><p>Der Auftragsbearbeiter bearbeitet Personendaten ausschliesslich nach dokumentierten Weisungen der Verantwortlichen und nur für den vereinbarten Zweck. Hält er eine Weisung für rechtswidrig, informiert er die Verantwortliche unverzüglich.</p>'
                    . '<h2>3. Vertraulichkeit</h2><p>Der Auftragsbearbeiter verpflichtet alle Personen, die Zugang zu den Daten haben, zur Vertraulichkeit.</p>'
                    . '<h2>4. Datensicherheit</h2><p>Der Auftragsbearbeiter trifft angemessene technische und organisatorische Massnahmen zum Schutz der Personendaten vor unbefugter oder zufälliger Bearbeitung, Verlust und Veränderung, die dem Stand der Technik und dem Risiko entsprechen, und dokumentiert diese auf Verlangen.</p>'
                    . '<h2>5. Ort der Bearbeitung</h2>{{#if ort == "Schweiz"}}<p>Die Daten werden in der Schweiz bearbeitet.</p>{{/if}}{{#if ort == "EU/EWR"}}<p>Die Daten werden in der Schweiz oder im EU/EWR-Raum bearbeitet.</p>{{/if}}{{#if ort == "Drittland"}}<p>Die Daten werden auch in einem Drittland bearbeitet. Die Parteien stellen durch geeignete Garantien (z. B. Standardvertragsklauseln) ein angemessenes Datenschutzniveau sicher und dokumentieren diese in einer Anlage.</p>{{/if}}'
                    . '<h2>6. Unterauftragsbearbeiter</h2>{{#if unterauftrag}}<p>Der Auftragsbearbeiter darf Unterauftragsbearbeiter beiziehen, wenn er die Verantwortliche vorgängig informiert und ihr die Möglichkeit zum Einspruch gibt. Er auferlegt ihnen dieselben Pflichten und haftet für sie.</p>{{else}}<p>Der Beizug von Unterauftragsbearbeitern bedarf der vorgängigen schriftlichen Zustimmung der Verantwortlichen.</p>{{/if}}'
                    . '<h2>7. Betroffenenrechte und Unterstützung</h2><p>Der Auftragsbearbeiter unterstützt die Verantwortliche bei Auskunfts-, Berichtigungs- und Löschbegehren sowie bei Datenschutz-Folgenabschätzungen im zumutbaren Rahmen.</p>'
                    . '<h2>8. Verletzungen der Datensicherheit</h2><p>Der Auftragsbearbeiter meldet der Verantwortlichen jede Verletzung der Datensicherheit innert {{meldefrist}} Stunden nach Kenntnisnahme mit den zur Beurteilung nötigen Angaben.</p>'
                    . '<h2>9. Kontrolle</h2><p>Der Auftragsbearbeiter gewährt der Verantwortlichen auf Verlangen die zum Nachweis der Einhaltung nötigen Auskünfte und ermöglicht angemessene Überprüfungen.</p>'
                    . '<h2>10. Rückgabe und Löschung</h2><p>Nach Beendigung des Hauptvertrags gibt der Auftragsbearbeiter die Daten nach Wahl der Verantwortlichen zurück oder löscht sie, soweit keine gesetzliche Aufbewahrungspflicht entgegensteht.</p>'
                    . '{{#if dsgvo}}<h2>11. EU-DSGVO</h2><p>Soweit die EU-DSGVO anwendbar ist, gelten zusätzlich die Anforderungen von Art. 28 DSGVO. Die Parteien legen die dort verlangten Angaben (Gegenstand, Dauer, Art und Zweck, Datenarten, Kategorien Betroffener) in dieser Vereinbarung fest.</p>{{/if}}'
                    . '<h2>Schlussbestimmungen</h2>' . $schluss,
            ],
            // ---------------------------------------------------------------- NDA
            [
                'type' => 'nda', 'name' => 'Geheimhaltungsvereinbarung / NDA (Standard)', 'jurisdiction' => 'CH',
                'fields' => $fields([
                    self::f('richtung', 'Art der Vereinbarung', 'select', true, 'Geben beide Parteien Informationen preis oder nur eine?', 'Einseitig = nur Partei A gibt Informationen preis; gegenseitig = beide.', ['Gegenseitig', 'Einseitig']),
                    self::f('zweck', 'Zweck des Austauschs', 'text', true, 'Wozu werden vertrauliche Informationen ausgetauscht?', 'z. B. «Prüfung einer möglichen Zusammenarbeit im Bereich …».'),
                    self::f('dauer', 'Dauer der Geheimhaltungspflicht (Jahre)', 'number', true, 'Wie lange nach Vertragsende muss die Vertraulichkeit gelten?', 'Üblich sind 2 bis 5 Jahre.'),
                    self::f('konventionalstrafe', 'Konventionalstrafe pro Verstoss (CHF)', 'number', false, 'Soll eine Konventionalstrafe gelten?', 'Leer lassen, wenn keine gewünscht ist.'),
                    $gerichtsstand,
                ]),
                'body' => $withHead('Geheimhaltungsvereinbarung', '<h2>1. Zweck</h2><p>Die Parteien tauschen vertrauliche Informationen aus für folgenden Zweck: {{zweck}} (nachfolgend «Zweck»).</p>'
                    . '<h2>2. Vertrauliche Informationen</h2>{{#if richtung == "Gegenseitig"}}<p>Vertrauliche Informationen sind alle Informationen, die eine Partei der anderen im Zusammenhang mit dem Zweck mündlich, schriftlich oder in anderer Form offenlegt und die als vertraulich bezeichnet sind oder nach den Umständen als vertraulich gelten müssen.</p>{{else}}<p>Vertrauliche Informationen sind alle Informationen, die Partei A der Partei B im Zusammenhang mit dem Zweck mündlich, schriftlich oder in anderer Form offenlegt und die als vertraulich bezeichnet sind oder nach den Umständen als vertraulich gelten müssen.</p>{{/if}}<p>Nicht vertraulich sind Informationen, die öffentlich bekannt sind oder werden, ohne dass die empfangende Partei dies zu vertreten hat, die ihr bereits rechtmässig bekannt waren, die sie rechtmässig von Dritten erhält oder die sie selbständig entwickelt hat.</p>'
                    . '<h2>3. Pflichten</h2><p>Die empfangende Partei verwendet die vertraulichen Informationen nur für den Zweck, hält sie geheim und schützt sie mit mindestens derselben Sorgfalt wie eigene vertrauliche Informationen. Sie gibt sie nur an Mitarbeitende und Berater weiter, die sie für den Zweck kennen müssen und selbst zur Vertraulichkeit verpflichtet sind. Eine gesetzlich oder behördlich verlangte Offenlegung ist zulässig, wenn die offenlegende Partei vorher informiert wird, soweit dies erlaubt ist.</p>'
                    . '<h2>4. Rückgabe und Löschung</h2><p>Auf Verlangen und bei Beendigung gibt die empfangende Partei vertrauliche Unterlagen zurück oder vernichtet sie und bestätigt dies auf Verlangen. Gesetzliche Aufbewahrungspflichten bleiben vorbehalten.</p>'
                    . '<h2>5. Dauer</h2><p>Die Pflichten gelten ab Unterzeichnung und enden {{dauer}} Jahre nach Beendigung des Austauschs für den Zweck, für Geschäftsgeheimnisse jedoch so lange, wie sie Geschäftsgeheimnisse sind.</p>'
                    . '<h2>6. Keine Rechtseinräumung</h2><p>Durch diese Vereinbarung werden keine Rechte an den vertraulichen Informationen oder an geistigem Eigentum übertragen oder eingeräumt.</p>'
                    . '{{#if konventionalstrafe}}<h2>7. Konventionalstrafe</h2><p>Bei jedem Verstoss gegen die Geheimhaltungspflicht schuldet die fehlbare Partei der anderen eine Konventionalstrafe von CHF {{konventionalstrafe}}. Die Bezahlung befreit nicht von der Einhaltung der Pflichten; weitergehender Schaden bleibt ersatzpflichtig.</p>{{/if}}'
                    . '<h2>Schlussbestimmungen</h2>' . $schluss),
            ],
            // ---------------------------------------------------------------- Partner
            [
                'type' => 'partner', 'name' => 'Kooperationsvertrag (Standard)', 'jurisdiction' => 'CH',
                'fields' => $fields([
                    self::f('zweck', 'Ziel der Zusammenarbeit', 'text', true, 'Was wollen die Parteien gemeinsam erreichen?', ''),
                    self::f('beitrag_a', 'Beitrag Partei A', 'text', true, 'Was bringt Partei A ein?', 'z. B. Fachwissen, Infrastruktur, Vertrieb.'),
                    self::f('beitrag_b', 'Beitrag Partei B', 'text', true, 'Was bringt Partei B ein?', ''),
                    self::f('kosten', 'Kosten und Erlöse', 'select', true, 'Wie werden Kosten und Erlöse verteilt?', 'Jede Partei trägt eigene Kosten, oder Kosten und Erlöse werden nach einem Schlüssel geteilt.', ['Jede Partei trägt ihre Kosten selbst', 'Teilung nach Schlüssel']),
                    self::f('schluessel', 'Verteilschlüssel (A : B)', 'text', false, 'Nach welchem Schlüssel wird geteilt?', 'z. B. «50 : 50». Nur bei Teilung.'),
                    self::f('exklusiv', 'Exklusivität', 'boolean', false, 'Soll die Zusammenarbeit exklusiv sein?', 'Beschränkt Sie in der Zusammenarbeit mit Dritten.'),
                    self::f('beginn', 'Beginn', 'date', true, 'Ab wann gilt der Vertrag?', ''),
                    self::f('dauer_monate', 'Mindestdauer (Monate)', 'number', true, 'Wie lange läuft die Zusammenarbeit mindestens?', ''),
                    self::f('kuendigungsfrist', 'Kündigungsfrist (Tage)', 'number', true, 'Mit welcher Frist kann danach gekündigt werden?', ''),
                    $gerichtsstand,
                ]),
                'body' => $withHead('Kooperationsvertrag', '<h2>1. Ziel</h2><p>Die Parteien arbeiten zusammen mit folgendem Ziel: {{zweck}}. Es wird keine Gesellschaft gegründet; jede Partei handelt auf eigene Rechnung und im eigenen Namen, sofern nichts anderes vereinbart ist.</p>'
                    . '<h2>2. Beiträge</h2><p>Partei A bringt ein: {{beitrag_a}}</p><p>Partei B bringt ein: {{beitrag_b}}</p>'
                    . '<h2>3. Kosten und Erlöse</h2>{{#if kosten == "Teilung nach Schlüssel"}}<p>Gemeinsame Kosten und Erlöse werden im Verhältnis {{schluessel}} (A : B) geteilt. Die Parteien rechnen mindestens vierteljährlich ab.</p>{{else}}<p>Jede Partei trägt ihre eigenen Kosten selbst. Gegenseitige Vergütungen bestehen nur, wenn sie schriftlich vereinbart werden.</p>{{/if}}'
                    . '{{#if exklusiv}}<h2>4. Exklusivität</h2><p>Während der Vertragsdauer arbeiten die Parteien im Bereich des Ziels ausschliesslich miteinander zusammen und unterlassen eine vergleichbare Zusammenarbeit mit Dritten.</p>{{/if}}'
                    . '<h2>5. Geistiges Eigentum</h2><p>Rechte an bereits vorhandenem geistigem Eigentum verbleiben bei der jeweiligen Partei. Gemeinsam geschaffene Ergebnisse stehen den Parteien gemeinsam zu; ihre Nutzung wird bei Bedarf gesondert schriftlich geregelt.</p>'
                    . '<h2>6. Vertraulichkeit und Datenschutz</h2><p>Die Parteien behandeln nicht öffentliche Informationen der anderen Partei vertraulich und bearbeiten Personendaten nach den anwendbaren Datenschutzgesetzen. Die Pflicht gilt über das Vertragsende hinaus.</p>'
                    . '<h2>7. Dauer und Kündigung</h2><p>Der Vertrag beginnt am {{beginn}} und dauert mindestens {{dauer_monate}} Monate. Danach kann er mit einer Frist von {{kuendigungsfrist}} Tagen schriftlich gekündigt werden. Die Kündigung aus wichtigem Grund bleibt vorbehalten.</p>{{clause:ABTRETUNG}}{{clause:HOEHERE_GEWALT}}'
                    . '<h2>8. Schlussbestimmungen</h2>' . $schluss),
            ],
            // ---------------------------------------------------------------- Miete
            [
                'type' => 'lease', 'name' => 'Mietvertrag Geschäftsräume (Standard)', 'jurisdiction' => 'CH',
                'fields' => [
                    self::f('partei_a', 'Vermieterin / Vermieter', 'text', true, 'Wer vermietet?', ''),
                    self::f('partei_a_adresse', 'Adresse Vermietung', 'text', true, 'Adresse der Vermieterin?', ''),
                    self::f('partei_b', 'Mieterin / Mieter', 'text', true, 'Wer mietet?', ''),
                    self::f('partei_b_adresse', 'Adresse Mietende', 'text', true, 'Adresse der Mieterin?', ''),
                    self::f('objekt', 'Mietobjekt', 'text', true, 'Was wird vermietet?', 'z. B. «Büro 3. OG, 120 m², Musterstrasse 1, 8000 Zürich».'),
                    self::f('nutzung', 'Vereinbarte Nutzung', 'text', true, 'Wofür wird das Objekt genutzt?', 'z. B. Büro, Laden, Lager.'),
                    self::f('beginn', 'Mietbeginn', 'date', true, 'Ab wann beginnt die Miete?', ''),
                    self::f('dauer', 'Mietdauer', 'select', true, 'Befristet oder unbefristet?', 'Bei «Befristet» endet die Miete ohne Kündigung am Enddatum.', ['Unbefristet', 'Befristet']),
                    self::f('ende', 'Mietende (nur bei befristet)', 'date', false, 'Wann endet die Miete?', ''),
                    self::f('mietzins', 'Mietzins pro Monat', 'number', true, 'Wie hoch ist der monatliche Nettomietzins?', 'Ohne Nebenkosten.'),
                    self::f('nebenkosten', 'Nebenkosten', 'select', true, 'Wie werden die Nebenkosten abgerechnet?', 'Akontozahlung = jährliche Abrechnung nach effektivem Aufwand; Pauschal = fester Betrag.', ['Akonto mit jährlicher Abrechnung', 'Pauschal']),
                    self::f('nebenkosten_betrag', 'Nebenkostenbetrag pro Monat', 'number', true, 'Wie hoch ist der monatliche Nebenkostenbetrag?', ''),
                    self::f('kaution', 'Mietkaution', 'number', false, 'Wird eine Kaution verlangt? Wie hoch?', 'Leer lassen, wenn keine Kaution.'),
                    self::f('kuendigungsfrist', 'Kündigungsfrist (Monate)', 'number', true, 'Mit welcher Frist kann gekündigt werden?', 'Bei Geschäftsräumen üblich: 6 Monate.'),
                    self::f('kuendigungstermin', 'Kündigungstermin', 'text', true, 'Auf welchen Termin kann gekündigt werden?', 'z. B. «Ende eines Quartals».'),
                    self::f('gerichtsstand', 'Gerichtsstand / Ort der Mietsache', 'text', true, 'Wo liegt das Mietobjekt?', 'Bei Mietverträgen ist in der Regel das Gericht am Ort der Sache zuständig.'),
                ],
                'body' => '<h1>Mietvertrag für Geschäftsräume</h1><p>zwischen <strong>{{partei_a}}</strong>, {{partei_a_adresse}} (nachfolgend «Vermieterin») und <strong>{{partei_b}}</strong>, {{partei_b_adresse}} (nachfolgend «Mieterin»).</p>'
                    . '<h2>1. Mietobjekt und Nutzung</h2><p>Die Vermieterin vermietet der Mieterin: {{objekt}}. Das Objekt wird für folgende Nutzung vermietet: {{nutzung}}. Eine andere Nutzung bedarf der schriftlichen Zustimmung der Vermieterin.</p>'
                    . '<h2>2. Mietbeginn und Mietdauer</h2><p>Die Miete beginnt am {{beginn}}.</p>{{#if dauer == "Befristet"}}<p>Sie ist befristet und endet am {{ende}}, ohne dass es einer Kündigung bedarf.</p>{{else}}<p>Sie wird auf unbestimmte Zeit abgeschlossen und kann von beiden Parteien unter Einhaltung einer Frist von {{kuendigungsfrist}} Monaten auf {{kuendigungstermin}} gekündigt werden. Die Kündigung muss schriftlich erfolgen.</p>{{/if}}'
                    . '<h2>3. Mietzins und Nebenkosten</h2><p>Der Nettomietzins beträgt CHF {{mietzins}} pro Monat, zahlbar monatlich im Voraus auf den Ersten des Monats.</p>{{#if nebenkosten == "Pauschal"}}<p>Die Nebenkosten werden pauschal mit CHF {{nebenkosten_betrag}} pro Monat abgegolten.</p>{{else}}<p>Für die Nebenkosten leistet die Mieterin monatliche Akontozahlungen von CHF {{nebenkosten_betrag}}. Die Vermieterin rechnet jährlich nach effektivem Aufwand ab.</p>{{/if}}'
                    . '{{#if kaution}}<h2>4. Mietkaution</h2><p>Die Mieterin leistet eine Sicherheit von CHF {{kaution}}. Die Vermieterin hinterlegt sie auf einem auf die Mieterin lautenden Sperrkonto bei einer Bank.</p>{{/if}}'
                    . '<h2>5. Übergabe, Unterhalt und Rückgabe</h2><p>Die Übergabe erfolgt mit einem gemeinsam unterzeichneten Protokoll. Die Mieterin behandelt das Objekt sorgfältig, meldet Mängel unverzüglich und führt kleine Reinigungs- und Unterhaltsarbeiten aus. Bauliche Änderungen und Untermiete bedürfen der schriftlichen Zustimmung der Vermieterin. Bei Beendigung gibt die Mieterin das Objekt gereinigt und in ordnungsgemässem Zustand zurück.</p>'
                    . '<h2>6. Gesetzliche Bestimmungen</h2><p>Soweit dieser Vertrag nichts regelt, gelten die Bestimmungen des Obligationenrechts über die Miete. Dieser Vertrag ist für Geschäftsräume gedacht; für Wohnräume gelten zusätzliche zwingende Vorschriften (u. a. Formularpflichten), die hier nicht abgebildet sind.</p>'
                    . '{{clause:RECHT_CH}}<p>Zuständig sind die Gerichte am Ort der Mietsache ({{gerichtsstand}}).</p>{{clause:SCHRIFTFORM}}{{clause:SALVATORISCH}}{{clause:AUSFERTIGUNG}}',
            ],
            // ---------------------------------------------------------------- Kauf
            [
                'type' => 'sale', 'name' => 'Kaufvertrag bewegliche Sachen (Standard)', 'jurisdiction' => 'CH',
                'fields' => [
                    self::f('partei_a', 'Verkäuferin / Verkäufer', 'text', true, 'Wer verkauft?', ''),
                    self::f('partei_a_adresse', 'Adresse Verkaufende', 'text', true, 'Adresse der Verkäuferin?', ''),
                    self::f('partei_b', 'Käuferin / Käufer', 'text', true, 'Wer kauft?', ''),
                    self::f('partei_b_adresse', 'Adresse Kaufende', 'text', true, 'Adresse der Käuferin?', ''),
                    self::f('gegenstand', 'Kaufgegenstand', 'text', true, 'Was wird verkauft?', 'Möglichst genau: Bezeichnung, Menge, Seriennummer, Zustand.'),
                    self::f('preis', 'Kaufpreis', 'number', true, 'Wie hoch ist der Kaufpreis?', ''),
                    self::f('waehrung', 'Währung', 'select', true, 'In welcher Währung?', '', ['CHF', 'EUR']),
                    self::f('mwst', 'Preis inkl. Mehrwertsteuer', 'boolean', false, 'Ist die Mehrwertsteuer im Preis enthalten?', 'Wenn nicht angekreuzt, gilt der Preis zuzüglich Mehrwertsteuer.'),
                    self::f('zahlungsfrist', 'Zahlungsfrist (Tage)', 'number', true, 'Innert wie vielen Tagen ist zu zahlen?', ''),
                    self::f('lieferdatum', 'Lieferdatum', 'date', true, 'Bis wann wird geliefert?', ''),
                    self::f('lieferort', 'Lieferort', 'text', true, 'Wohin wird geliefert?', ''),
                    self::f('eigentumsvorbehalt', 'Eigentumsvorbehalt', 'boolean', false, 'Soll das Eigentum bis zur vollständigen Zahlung bei der Verkäuferin bleiben?', 'Wirksam erst bei Eintrag im Eigentumsvorbehaltsregister am Wohnsitz der Käuferin.'),
                    self::f('gewaehrleistung', 'Gewährleistungsfrist (Monate)', 'number', true, 'Wie lange gilt die Gewährleistung?', 'Gesetzlich 2 Jahre; bei gebrauchten Sachen oft kürzer, soweit zulässig.'),
                    $gerichtsstand,
                ],
                'body' => $withHead('Kaufvertrag', '<h2>1. Kaufgegenstand</h2><p>Partei A (Verkäuferin) verkauft Partei B (Käuferin) folgenden Gegenstand: {{gegenstand}}</p>'
                    . '<h2>2. Kaufpreis und Zahlung</h2><p>Der Kaufpreis beträgt {{waehrung}} {{preis}} {{#if mwst}}inklusive Mehrwertsteuer{{else}}zuzüglich Mehrwertsteuer{{/if}}. Er ist innert {{zahlungsfrist}} Tagen nach Rechnungsstellung ohne Abzug zu bezahlen.</p>'
                    . '<h2>3. Lieferung, Nutzen und Gefahr</h2><p>Die Verkäuferin liefert den Kaufgegenstand bis zum {{lieferdatum}} nach {{lieferort}}. Nutzen und Gefahr gehen mit der Übergabe auf die Käuferin über. Die Käuferin prüft den Kaufgegenstand unverzüglich nach Erhalt und rügt Mängel sofort schriftlich.</p>'
                    . '{{#if eigentumsvorbehalt}}<h2>4. Eigentumsvorbehalt</h2><p>Der Kaufgegenstand bleibt bis zur vollständigen Bezahlung des Kaufpreises im Eigentum der Verkäuferin. Die Käuferin ermächtigt die Verkäuferin, den Eigentumsvorbehalt im Register eintragen zu lassen.</p>{{/if}}'
                    . '<h2>5. Gewährleistung</h2><p>Die Verkäuferin gewährleistet, dass der Kaufgegenstand die zugesicherten Eigenschaften hat und frei von Rechtsmängeln ist. Die Gewährleistungsfrist beträgt {{gewaehrleistung}} Monate ab Übergabe. Bei Mängeln gelten die gesetzlichen Rechte der Käuferin; eine Beschränkung der Haftung gilt nur, soweit sie gesetzlich zulässig ist.</p>'
                    . '<h2>6. Anwendbares Recht</h2><p>Das Übereinkommen der Vereinten Nationen über Verträge über den internationalen Warenkauf (Wiener Kaufrecht, CISG) ist ausgeschlossen.</p>'
                    . '<h2>7. Schlussbestimmungen</h2>' . $schluss),
            ],
        ];
    }

    /**
     * @param list<string> $options
     * @return array<string,mixed>
     */
    private static function f(string $code, string $label, string $type, bool $required, string $question, string $help, array $options = []): array
    {
        return ['code' => $code, 'label' => $label, 'type' => $type, 'required' => $required, 'options' => $options, 'question' => $question, 'help' => $help];
    }
}
