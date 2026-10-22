<?php $v->title = 'Neuer Vertrag'; ?>
<section class="stack">
  <p class="muted small"><a href="/vertraege">Verträge</a></p>
  <h1 class="h1">Neuen Vertrag starten</h1>
  <p class="muted">Wählen Sie, womit Sie beginnen. Bei jedem Weg führt Sie die Plattform anschliessend Schritt für Schritt bis zur Unterzeichnung. Sie sehen jederzeit, was das System für Sie erledigt und was Sie selbst tun.</p>

  <div class="grid-3 start-cards">
    <div class="card stack">
      <h2 class="h3"><i class="bx bx-layout" aria-hidden="true"></i> Aus einer Vorlage</h2>
      <p class="small"><span class="badge badge-ok">Empfohlen</span> Am wenigsten Aufwand.</p>
      <p class="small"><strong>Das System übernimmt:</strong> den fertigen Vertragstext, das Einsetzen Ihrer Antworten, Vertragsnummer, Version und Fristen.</p>
      <p class="small"><strong>Sie tun:</strong> Fragen des Fragebogens beantworten (Parteien, Daten, Beträge).</p>
      <?php if ($templateCount > 0): ?><a class="btn btn-primary" href="/vorlagen"><i class="bx bx-right-arrow-alt" aria-hidden="true"></i> Vorlage wählen (<?= (int) $templateCount ?> freigegeben)</a>
      <?php else: ?><p class="small muted">Es gibt noch keine fachlich freigegebene Vorlage.</p><a class="btn btn-secondary" href="/vorlagen">Zu den Vorlagen</a><?php endif; ?>
    </div>
    <div class="card stack">
      <h2 class="h3"><i class="bx bx-upload" aria-hidden="true"></i> Mit vorhandenem Dokument</h2>
      <p class="small">Sie haben den Vertragstext schon als Word- oder PDF-Datei.</p>
      <p class="small"><strong>Das System übernimmt:</strong> das Auslesen des Word-Textes, das Ablegen der Originaldatei, Vertragsnummer, Versionsverwaltung und Fristen.</p>
      <p class="small"><strong>Sie tun:</strong> Eckdaten erfassen, danach die Datei hochladen (PDF wird unverändert abgelegt, nicht ausgelesen).</p>
      <a class="btn btn-secondary" href="/vertraege/neu/formular?start=import"><i class="bx bx-right-arrow-alt" aria-hidden="true"></i> Eckdaten erfassen</a>
    </div>
    <div class="card stack">
      <h2 class="h3"><i class="bx bx-edit" aria-hidden="true"></i> Von Grund auf</h2>
      <p class="small">Sie schreiben den Vertragstext selbst.</p>
      <p class="small"><strong>Das System übernimmt:</strong> Standardwerte (Beginn, Recht, Währung), Vertragsnummer, Versionen mit Prüfsumme und alle Fristen.</p>
      <p class="small"><strong>Sie tun:</strong> Eckdaten erfassen und den Text verfassen.</p>
      <a class="btn btn-secondary" href="/vertraege/neu/formular"><i class="bx bx-right-arrow-alt" aria-hidden="true"></i> Eckdaten erfassen</a>
    </div>
  </div>

  <div class="card stack">
    <h2 class="h3">Vor dem Start prüfen</h2>
    <ul class="checks">
      <li><span class="badge <?= $counterpartyCount > 0 ? 'badge-ok' : 'badge-warn' ?>"><?= $counterpartyCount > 0 ? 'bereit' : 'optional' ?></span> Vertragsparteien: <?= $counterpartyCount > 0 ? (int) $counterpartyCount . ' verbundene Gegenpartei(en) wählbar.' : 'Noch keine Gegenpartei verbunden. Sie können den Vertrag trotzdem anlegen und die Parteien später ergänzen (<a href="/gegenparteien#verbinden">mit Mandanten-Code verbinden</a>).' ?></li>
      <li><span class="badge <?= $hasApprover ? 'badge-ok' : 'badge-warn' ?>"><?= $hasApprover ? 'bereit' : 'später' ?></span> Freigabe: <?= $hasApprover ? 'Es gibt Personen, die freigeben dürfen.' : 'Es ist noch niemand zum Freigeben eingerichtet (Verwaltung → Benutzer). Das wird erst vor der Freigabe gebraucht.' ?></li>
    </ul>
  </div>

  <div class="card">
    <h2 class="h3">So geht es danach weiter</h2>
    <ol class="todo">
      <li><span class="todo-n">1</span><div><strong>Erfassen</strong> – Eckdaten und Parteien.</div></li>
      <li><span class="todo-n">2</span><div><strong>Vertragstext</strong> – aus Vorlage, Datei oder selbst geschrieben.</div></li>
      <li><span class="todo-n">3</span><div><strong>Freigabe</strong> – die Textfassung wird gesperrt und freigegeben.</div></li>
      <li><span class="todo-n">4</span><div><strong>Unterzeichnung</strong> – per E-Mail-Link oder Anbieter; danach ist der Vertrag aktiv.</div></li>
      <li><span class="todo-n">5</span><div><strong>Laufzeit</strong> – das System überwacht Fristen und erinnert Sie.</div></li>
    </ol>
  </div>
</section>
