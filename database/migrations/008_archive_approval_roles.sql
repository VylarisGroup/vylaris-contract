-- Migration 008: Mandanten archivieren; Freigaben dürfen auch Mandantenadministration und Vertragsverantwortliche erteilen. Rein additiv.
ALTER TABLE tenants MODIFY COLUMN status ENUM('active','suspended','archived') NOT NULL DEFAULT 'active', ADD COLUMN archived_at DATETIME(6) NULL DEFAULT NULL;
-- @@

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'approval.decide' WHERE r.code IN ('tenant_admin', 'contract_manager');
