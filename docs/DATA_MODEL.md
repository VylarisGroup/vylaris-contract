# Datenmodell (MySQL/MariaDB, InnoDB, utf8mb4)

Schema: `database/migrations/001_schema.sql` (Tabellen), `002_seed_roles.sql` (Rollen/Berechtigungen), `003_integrity_triggers.sql` (16 Trigger). Migrationen sind mit Prüfsumme versioniert (`schema_migrations`); `php bin/migrate.php --verify` erkennt nachträglich veränderte Migrationsdateien. Fast jede fachliche Tabelle trägt `tenant_id`; Fremdschlüssel verwenden (`tenant_id`, `id`), sodass Verweise über Mandantengrenzen hinweg datenbankseitig unmöglich sind.

| Bereich | Tabellen |
|---|---|
| Mandanten & Benutzer | `tenants`, `users`, `tenant_memberships`, `roles`, `permissions`, `role_permissions`, `departments`, `department_memberships`, `contract_acl` |
| Anmeldung | `auth_tokens` (gehashte Einmal-Token), `login_attempts` (Rate-Limit), `users.mfa_*` |
| Verträge | `contracts`, `contract_versions` (unveränderlich nach Sperre), `contract_types`, `contract_parties`, `contract_tags`/`tags`, `contract_number_sequences`, `custom_field_definitions`, `custom_field_values` |
| Gegenparteien | `counterparties` |
| Dokumente | `documents` (Speicherschlüssel, SHA-256, Art, Sichtbarkeit extern) |
| Freigabe | `approvals` (gestufte Genehmigung, Vier-Augen) |
| Signatur | `signature_requests`, `signature_events`, `signature_evidence` (inkl. `is_sandbox`), `api_transactions` |
| Notariat | `notary_cases` |
| Externe Zugriffe | `external_mandates`, `external_mandate_contracts` |
| Vorlagen | `contract_templates`, `template_versions`, `template_clauses` |
| Fristen/Aufgaben/Kommentare | `contract_deadlines`, `contract_tasks`, `contract_comments`, `notifications` |
| Compliance | `legal_holds`, `retention_policies`, `deletion_requests` (Auskunft/Löschung) |
| Protokoll | `audit_events` (Hash-Kette), `audit_anchors` |
| Betrieb | `maintenance_jobs` (Cron-Sperren/Status), `system_settings` |

## Status eines Vertrags
`draft → review → approved → signature → active → archived` (zusätzlich `rejected`, `withdrawn`, `expired`, `cancelled`). Übergänge erlaubt nur `StatusMachine`; ausgeführt nur von `WorkflowService::move` (Anwendung) – Sprünge wie »Entwurf → aktiv« sind in Code und Tests ausgeschlossen.

## Unveränderlichkeit (Trigger, Auswahl)
`contract_versions` (gesperrte), `audit_events`, `signature_events`, `signature_evidence` dürfen weder geändert noch gelöscht werden; `documents` sind in Hash-, Inhalts- und Zuordnungsfeldern unveränderlich und löschbar nur über das dokumentierte Löschverfahren; `signature_requests`, `approvals` und `notary_cases` sind an ihre Version/ihr Dokument gebunden und nicht löschbar (Trigger `trg_*`). Löschen von Verträgen ist nicht vorgesehen (Archivierung; endgültige Löschung nur über den Aufbewahrungs-/Löschprozess und nie bei rechtlicher Sperre).
