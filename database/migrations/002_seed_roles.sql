-- Migration 002: Systemrollen, Berechtigungen und Vertragsarten (Stammdaten, keine Entwicklungsdaten).

INSERT INTO permissions (code, description) VALUES
 ('system.manage', 'Systemkonfiguration, Wartung, globale Benutzerverwaltung'),
 ('tenant.manage', 'Einstellungen des eigenen Mandanten'),
 ('user.manage', 'Benutzer, Rollen und Mitgliedschaften des Mandanten verwalten'),
 ('department.manage', 'Abteilungen verwalten'),
 ('contract.view', 'Verträge im Mandanten lesen'),
 ('contract.create', 'Verträge anlegen und importieren'),
 ('contract.edit', 'Entwürfe bearbeiten, neue Versionen erstellen'),
 ('contract.archive', 'Verträge archivieren'),
 ('contract.export', 'Verträge exportieren'),
 ('contract.view_assigned', 'Nur ausdrücklich zugewiesene Verträge lesen (extern)'),
 ('document.download', 'Dokumente herunterladen'),
 ('document.download_assigned', 'Zugewiesene, freigegebene Dokumente herunterladen (extern)'),
 ('template.view', 'Vorlagen ansehen'),
 ('template.manage', 'Vorlagen und Klauseln verwalten'),
 ('template.approve', 'Vorlagen fachlich freigeben'),
 ('approval.request', 'Prüfung/Freigabe anfordern'),
 ('approval.decide', 'Verträge genehmigen oder zurückweisen'),
 ('signature.request', 'Signaturanfragen erstellen'),
 ('signature.sign_own', 'Eigene Signaturvorgänge ausführen (extern)'),
 ('comment.internal', 'Interne Kommentare lesen und schreiben'),
 ('comment.shared', 'Geteilte Kommentare lesen und schreiben'),
 ('deadline.manage', 'Fristen verwalten'),
 ('notary.manage', 'Notariatsfälle anlegen und verwalten'),
 ('notary.process', 'Zugewiesene Notariatsfälle bearbeiten (Notariat)'),
 ('audit.view', 'Audit-Protokoll des Mandanten einsehen'),
 ('audit.review', 'Prüfungsmandat: freigegebene Verträge und Nachweise prüfen'),
 ('audit.annotate', 'Prüfvermerke erfassen'),
 ('audit.export_report', 'Prüfbericht exportieren'),
 ('retention.manage', 'Aufbewahrungsregeln verwalten'),
 ('legalhold.manage', 'Rechtliche Sperren setzen und aufheben'),
 ('privacy.manage', 'Auskunfts- und Löschanfragen bearbeiten'),
 ('mandate.manage', 'Externe Mandate vergeben und widerrufen'),
 ('sensitive.access', 'Zugriff auf besonders sensible Personendaten'),
 ('integration.manage', 'Integrationen (Signatur, SMTP) konfigurieren');
-- @@

INSERT INTO roles (code, name, description, is_external) VALUES
 ('system_admin', 'Systemadministrator', 'Systemkonfiguration, technische Wartung, globale Benutzerverwaltung', 0),
 ('tenant_admin', 'Mandantenadministrator', 'Benutzer, Abteilungen, Rollen und Einstellungen des eigenen Mandanten', 0),
 ('contract_manager', 'Vertragsverwaltung', 'Verträge, Vorlagen, Metadaten und Entwürfe verwalten', 0),
 ('reader', 'Lesender Zugriff', 'Zugewiesene Dokumente lesen und gegebenenfalls herunterladen', 0),
 ('approver', 'Genehmigungsstelle', 'Verträge prüfen, genehmigen oder zurückweisen', 0),
 ('external_party', 'Externer Vertragspartner', 'Ausschliesslich zugewiesene Verträge, Dokumente und eigene Signaturvorgänge', 1),
 ('auditor', 'Externe Revisionsstelle', 'Zeitlich begrenzte Prüfung freigegebener Verträge und Nachweise', 1),
 ('notary', 'Notariat', 'Zugewiesene, freigegebene Dokumentversionen und Beglaubigungsprozess', 1);
-- @@

-- Zuordnung Rolle -> Berechtigungen (explizit, keine impliziten Rechte).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON (
  (r.code = 'system_admin' AND p.code IN ('system.manage','tenant.manage','user.manage','department.manage','integration.manage','audit.view')) OR
  (r.code = 'tenant_admin' AND p.code IN ('tenant.manage','user.manage','department.manage','contract.view','contract.create','contract.edit','contract.archive','contract.export','document.download','template.view','template.manage','template.approve','approval.request','signature.request','comment.internal','deadline.manage','notary.manage','audit.view','retention.manage','legalhold.manage','privacy.manage','mandate.manage','integration.manage','sensitive.access')) OR
  (r.code = 'contract_manager' AND p.code IN ('contract.view','contract.create','contract.edit','contract.archive','contract.export','document.download','template.view','template.manage','approval.request','signature.request','comment.internal','deadline.manage','notary.manage')) OR
  (r.code = 'reader' AND p.code IN ('contract.view','document.download')) OR
  (r.code = 'approver' AND p.code IN ('contract.view','document.download','template.view','template.approve','approval.decide','comment.internal')) OR
  (r.code = 'external_party' AND p.code IN ('contract.view_assigned','document.download_assigned','signature.sign_own','comment.shared')) OR
  (r.code = 'auditor' AND p.code IN ('audit.review','audit.annotate','audit.export_report')) OR
  (r.code = 'notary' AND p.code IN ('notary.process'))
);
-- @@

INSERT INTO contract_types (tenant_id, code, name) VALUES
 (NULL, 'service', 'Dienstleistungs- und Kundenvertrag'),
 (NULL, 'license', 'Lizenz- und Softwarevertrag'),
 (NULL, 'terms', 'Nutzungsbedingungen'),
 (NULL, 'dpa', 'Datenschutzvereinbarung'),
 (NULL, 'nda', 'Geheimhaltungsvereinbarung (NDA)'),
 (NULL, 'partner', 'Partner- und Kooperationsvertrag'),
 (NULL, 'lease', 'Mietvertrag'),
 (NULL, 'sale', 'Kaufvertrag');
