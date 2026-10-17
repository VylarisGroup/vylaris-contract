-- Migration 007: Mandanten-Code und Verbindungen, mandantenfähiges SSO (OIDC), Zeitstempel für eigene Signaturen. Rein additiv.
ALTER TABLE tenants ADD COLUMN connect_code CHAR(14) NULL DEFAULT NULL, ADD UNIQUE KEY uq_tenants_connect_code (connect_code);
-- @@

ALTER TABLE counterparties ADD COLUMN linked_tenant_id BIGINT UNSIGNED NULL DEFAULT NULL, ADD KEY ix_cp_linked (tenant_id, linked_tenant_id), ADD CONSTRAINT fk_cp_linked_tenant FOREIGN KEY (linked_tenant_id) REFERENCES tenants(id);
-- @@

CREATE TABLE tenant_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_tenant_id BIGINT UNSIGNED NOT NULL,
    target_tenant_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','active','rejected','revoked') NOT NULL DEFAULT 'pending',
    requested_by BIGINT UNSIGNED NOT NULL,
    decided_by BIGINT UNSIGNED NULL,
    message VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    decided_at DATETIME(6) NULL,
    ended_at DATETIME(6) NULL,
    UNIQUE KEY uq_conn_pair (requester_tenant_id, target_tenant_id),
    KEY ix_conn_target (target_tenant_id, status),
    CONSTRAINT fk_conn_req FOREIGN KEY (requester_tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_conn_tgt FOREIGN KEY (target_tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_conn_by FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_conn_dec FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE tenant_sso (
    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    issuer VARCHAR(255) NOT NULL,
    client_id VARCHAR(255) NOT NULL,
    client_secret_enc TEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    auto_provision TINYINT(1) NOT NULL DEFAULT 0,
    default_role VARCHAR(40) NOT NULL DEFAULT 'reader',
    allowed_domains VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_sso_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE user_identities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    issuer VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    email_at_link VARCHAR(190) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_login_at DATETIME(6) NULL,
    UNIQUE KEY uq_identity (tenant_id, issuer, subject),
    KEY ix_identity_user (user_id),
    CONSTRAINT fk_ident_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_ident_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

ALTER TABLE signing_sessions ADD COLUMN tsa_token LONGBLOB NULL, ADD COLUMN tsa_gen_time DATETIME(6) NULL, ADD COLUMN tsa_url VARCHAR(255) NULL, ADD COLUMN tsa_serial VARCHAR(80) NULL;
