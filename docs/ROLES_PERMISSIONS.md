# Rollen und Berechtigungen

Berechtigungen werden Rollen zugeordnet (`roles`, `permissions`, `role_permissions`); der Code prüft immer die **Berechtigung**, nie den Rollennamen. Zusätzlich wirkt der Geltungsbereich (siehe unten).

| Berechtigung | Mandantenadmin | Vertragsverwaltung | Genehmigungsstelle | Lesend | Revision* | Notariat* | Gegenpartei* | Systemadmin |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| contract.view | ✔ | ✔ | ✔ | ✔ | | | | |
| contract.create / edit / archive / export | ✔ | ✔ | | | | | | |
| document.download | ✔ | ✔ | ✔ | ✔ | | | | |
| approval.request | ✔ | ✔ | | | | | | |
| approval.decide | | | ✔ | | | | | |
| signature.request | ✔ | ✔ | | | | | | |
| notary.manage | ✔ | ✔ | | | | | | |
| deadline.manage | ✔ | ✔ | | | | | | |
| comment.internal | ✔ | ✔ | ✔ | | | | | |
| template.view | ✔ | ✔ | ✔ | | | | | |
| template.manage | ✔ | ✔ | | | | | | |
| template.approve | ✔ | | ✔ | | | | | |
| department.manage / user.manage | ✔ | | | | | | | ✔ |
| mandate.manage (externe Zugriffe) | ✔ | | | | | | | |
| legalhold.manage / retention.manage / privacy.manage | ✔ | | | | | | | |
| sensitive.access (besonders schützenswerte Daten) | ✔ | | | | | | | |
| integration.manage | ✔ | | | | | | | ✔ |
| audit.view (Mandantenprotokoll) | ✔ | | | | | | | ✔ |
| tenant.manage / system.manage | ✔ (eigener Mandant) | | | | | | | ✔ |
| audit.review / annotate / export_report | | | | | ✔ | | | |
| notary.process | | | | | | ✔ | | |
| contract.view_assigned, document.download_assigned, comment.shared, signature.sign_own | | | | | | | ✔ | |

\* Externe Rollen wirken nur innerhalb eines **Mandats** (Vertragsliste + erlaubte Aktionen + Zeitraum).

## Geltungsbereich innerhalb eines Mandanten
Sichtbar/bearbeitbar sind Verträge, bei denen die Person Eigentümer ist, in der Zugriffsliste steht, über die Abteilung des Vertrags berechtigt ist oder als Freigebende zugewiesen wurde. Die Mandantenadministration sieht alle Verträge des Mandanten. Schreibaktionen (bearbeiten, archivieren, Freigabe/Signatur/Notariat/Fristen) verlangen zusätzlich Schreibrecht in der Zugriffsliste.

## Vier-Augen-Prinzip
Freigaben, Vorlagenprüfung und Klauselprüfung können nicht von der Person entschieden werden, die sie angefordert/erstellt hat. Ein gesperrter (zur Freigabe eingereichter) Text ist unveränderlich.

Die Auswahl für Freigebende listet nur Personen mit `approval.decide`; der Dienst prüft das beim Absenden zusätzlich.
