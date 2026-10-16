-- Migration 001: Kernschema. Statements werden durch Zeilen "-- @@" getrennt.
-- Alle Zeitpunkte sind UTC (DATETIME(6)). Alle Tabellen: InnoDB, utf8mb4.

CREATE TABLE tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Zurich',
    jurisdiction_default VARCHAR(16) NOT NULL DEFAULT 'CH',
    contract_number_prefix VARCHAR(16) NOT NULL DEFAULT 'V',
    mfa_required TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_departments_tenant_name (tenant_id, name),
    UNIQUE KEY uq_departments_tenant_id (tenant_id, id),
    CONSTRAINT fk_departments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NULL,
    is_system_admin TINYINT(1) NOT NULL DEFAULT 0,
    user_type ENUM('internal','external_party','auditor','notary') NOT NULL DEFAULT 'internal',
    status ENUM('active','disabled','pending') NOT NULL DEFAULT 'pending',
    email_verified_at DATETIME(6) NULL,
    mfa_secret_enc TEXT NULL,
    mfa_enabled_at DATETIME(6) NULL,
    mfa_last_counter BIGINT NULL,
    mfa_recovery_hashes TEXT NULL,
    oidc_issuer VARCHAR(255) NULL,
    oidc_subject VARCHAR(255) NULL,
    failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME(6) NULL,
    session_epoch INT UNSIGNED NOT NULL DEFAULT 1,
    last_login_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_oidc (oidc_issuer, oidc_subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(48) NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    is_external TINYINT(1) NOT NULL DEFAULT 0,
    is_system TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE tenant_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','suspended','revoked') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_membership (tenant_id, user_id, role_id),
    KEY ix_membership_user (user_id, status),
    CONSTRAINT fk_tm_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_tm_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_tm_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE department_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_dm (department_id, user_id),
    CONSTRAINT fk_dm_dept FOREIGN KEY (tenant_id, department_id) REFERENCES departments(tenant_id, id),
    CONSTRAINT fk_dm_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE counterparties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    party_type ENUM('company','association','organization','person') NOT NULL,
    name VARCHAR(255) NOT NULL,
    legal_form VARCHAR(80) NULL,
    registration_id VARCHAR(80) NULL,
    contact_email VARCHAR(190) NULL,
    address_line VARCHAR(255) NULL,
    postal_code VARCHAR(20) NULL,
    city VARCHAR(120) NULL,
    country CHAR(2) NULL,
    linked_user_id BIGINT UNSIGNED NULL,
    sensitive_data TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_cp_tenant_id (tenant_id, id),
    KEY ix_cp_name (tenant_id, name),
    CONSTRAINT fk_cp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_cp_user FOREIGN KEY (linked_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE contract_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NULL,
    code VARCHAR(48) NOT NULL,
    name VARCHAR(160) NOT NULL,
    UNIQUE KEY uq_ct (tenant_id, code),
    CONSTRAINT fk_ct_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE contract_number_sequences (
    tenant_id BIGINT UNSIGNED NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant_id, year),
    CONSTRAINT fk_cns_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE contracts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    contract_number VARCHAR(48) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    contract_type_id BIGINT UNSIGNED NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    governing_law VARCHAR(120) NULL,
    venue VARCHAR(190) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    notice_period_days INT UNSIGNED NULL,
    renewal_terms VARCHAR(500) NULL,
    auto_renew TINYINT(1) NOT NULL DEFAULT 0,
    renewal_period_days INT UNSIGNED NULL,
    value_amount DECIMAL(16,2) NULL,
    value_currency CHAR(3) NULL,
    payment_terms VARCHAR(500) NULL,
    status ENUM('draft','review','approved','signature','active','archived','rejected','withdrawn','expired','cancelled') NOT NULL DEFAULT 'draft',
    parent_contract_id BIGINT UNSIGNED NULL,
    relation_type ENUM('amendment','change_request') NULL,
    current_version_id BIGINT UNSIGNED NULL,
    approved_version_id BIGINT UNSIGNED NULL,
    archived_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_contract_number (tenant_id, contract_number),
    UNIQUE KEY uq_contract_tenant_id (tenant_id, id),
    KEY ix_contract_status (tenant_id, status),
    KEY ix_contract_end (tenant_id, end_date),
    KEY ix_contract_owner (tenant_id, owner_user_id),
    FULLTEXT KEY ft_contract_meta (title, description),
    CONSTRAINT fk_c_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_c_dept FOREIGN KEY (tenant_id, department_id) REFERENCES departments(tenant_id, id),
    CONSTRAINT fk_c_type FOREIGN KEY (contract_type_id) REFERENCES contract_types(id),
    CONSTRAINT fk_c_owner FOREIGN KEY (owner_user_id) REFERENCES users(id),
    CONSTRAINT fk_c_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_c_parent FOREIGN KEY (tenant_id, parent_contract_id) REFERENCES contracts(tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE contract_parties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    counterparty_id BIGINT UNSIGNED NOT NULL,
    role_label VARCHAR(80) NOT NULL,
    must_sign TINYINT(1) NOT NULL DEFAULT 0,
    sign_order INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_cparty (contract_id, counterparty_id, role_label),
    CONSTRAINT fk_cpa_contract FOREIGN KEY (tenant_id, contract_id) REFERENCES contracts(tenant_id, id),
    CONSTRAINT fk_cpa_cp FOREIGN KEY (tenant_id, counterparty_id) REFERENCES counterparties(tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE contract_acl (
    tenant_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    can_edit TINYINT(1) NOT NULL DEFAULT 0,
    granted_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (contract_id, user_id),
    KEY ix_acl_user (user_id, tenant_id),
    CONSTRAINT fk_acl_contract FOREIGN KEY (tenant_id, contract_id) REFERENCES contracts(tenant_id, id),
    CONSTRAINT fk_acl_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_acl_grantor FOREIGN KEY (granted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE contract_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    previous_version_id BIGINT UNSIGNED NULL,
    body_html MEDIUMTEXT NULL,
    body_text MEDIUMTEXT NULL,
    content_hash CHAR(64) NULL,
    template_version_id BIGINT UNSIGNED NULL,
    form_answers JSON NULL,
    state ENUM('draft','locked') NOT NULL DEFAULT 'draft',
    locked_at DATETIME(6) NULL,
    change_note VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_cv (contract_id, version_no),
    UNIQUE KEY uq_cv_tenant_id (tenant_id, id),
    FULLTEXT KEY ft_cv_text (body_text),
    CONSTRAINT fk_cv_contract FOREIGN KEY (tenant_id, contract_id) REFERENCES contracts(tenant_id, id),
    CONSTRAINT fk_cv_prev FOREIGN KEY (previous_version_id) REFERENCES contract_versions(id),
    CONSTRAINT fk_cv_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE contract_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    contract_type_id BIGINT UNSIGNED NOT NULL,
    jurisdiction VARCHAR(16) NOT NULL DEFAULT 'CH',
    review_status ENUM('unreviewed','in_review','approved') NOT NULL DEFAULT 'unreviewed',
    current_version_id BIGINT UNSIGNED NULL,
    archived_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_tpl_tenant_id (tenant_id, id),
    CONSTRAINT fk_tpl_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_tpl_type FOREIGN KEY (contract_type_id) REFERENCES contract_types(id),
    CONSTRAINT fk_tpl_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE template_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    body MEDIUMTEXT NOT NULL,
    field_schema JSON NOT NULL,
    review_status ENUM('unreviewed','in_review','approved') NOT NULL DEFAULT 'unreviewed',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME(6) NULL,
    content_hash CHAR(64) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_tv (template_id, version_no),
    CONSTRAINT fk_tv_tpl FOREIGN KEY (tenant_id, template_id) REFERENCES contract_templates(tenant_id, id),
    CONSTRAINT fk_tv_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE template_clauses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL,
    title VARCHAR(190) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    review_status ENUM('unreviewed','in_review','approved') NOT NULL DEFAULT 'unreviewed',
    archived_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_clause (tenant_id, code),
    CONSTRAINT fk_clause_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    contract_version_id BIGINT UNSIGNED NULL,
    kind ENUM('original','generated','signed','evidence','attachment','notary_return') NOT NULL,
    storage_key CHAR(40) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    visible_to_external TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    UNIQUE KEY uq_doc_key (storage_key),
    KEY ix_doc_contract (tenant_id, contract_id, kind),
    CONSTRAINT fk_doc_contract FOREIGN KEY (tenant_id, contract_id) REFERENCES contracts(tenant_id, id),
    CONSTRAINT fk_doc_version FOREIGN KEY (contract_version_id) REFERENCES contract_versions(id),
    CONSTRAINT fk_doc_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    contract_version_id BIGINT UNSIGNED NOT NULL,
    step_no INT UNSIGNED NOT NULL DEFAULT 1,
    approver_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected','invalidated') NOT NULL DEFAULT 'pending',
    decision_comment VARCHAR(1000) NULL,
    version_hash CHAR(64) NOT NULL,
    decided_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_approval (contract_version_id, step_no, approver_user_id),
    KEY ix_approval_user (approver_user_id, status),
    CONSTRAINT fk_ap_contract FOREIGN KEY (tenant_id, contract_id) REFERENCES contracts(tenant_id, id),
    CONSTRAINT fk_ap_version FOREIGN KEY (contract_version_id) REFERENCES contract_versions(id),
    CONSTRAINT fk_ap_user FOREIGN KEY (approver_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE contract_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    visibility ENUM('internal','shared') NOT NULL DEFAULT 'internal',
    kind ENUM('comment','audit_note') NOT NULL DEFAULT 'comment',
    body VARCHAR(4000) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_cc (tenant_id, contract_id, visibility),
    CONSTRAINT fk_cc_contract FOREIGN KEY (tenant_id, contract_id) REFERENCES contracts(tenant_id, id),
    CONSTRAINT fk_cc_user FOREIGN KEY (author_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE contract_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    assignee_user_id BIGINT UNSIGNED NULL,
    due_date DATE NULL,
    status ENUM('open','done') NOT NULL DEFAULT 'open',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_ctask_contract FOREIGN KEY (tenant_id, contract_id) REFERENCES contracts(tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE signature_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    contract_version_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    document_sha256 CHAR(64) NOT NULL,
    provider VARCHAR(40) NOT NULL,
    mode ENUM('sandbox','live') NOT NULL,
    requested_level ENUM('SES','AES','QES') NOT NULL,
    status ENUM('prepared','submitted','partially_signed','signed','declined','expired','cancelled','failed') NOT NULL DEFAULT 'prepared',
    external_transaction_id VARCHAR(190) NULL,
    signers JSON NOT NULL,
    last_error VARCHAR(500) NULL,
    last_polled_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_sig_ext (provider, external_transaction_id),
    KEY ix_sig_status (tenant_id, status),
    CONSTRAINT fk_sr_contract FOREIGN KEY (tenant_id, contract_id) REFERENCES contracts(tenant_id, id),
    CONSTRAINT fk_sr_version FOREIGN KEY (contract_version_id) REFERENCES contract_versions(id),
    CONSTRAINT fk_sr_doc FOREIGN KEY (document_id) REFERENCES documents(id),
    CONSTRAINT fk_sr_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE signature_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signature_request_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    provider_payload_hash CHAR(64) NULL,
    detail VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_se_req FOREIGN KEY (signature_request_id) REFERENCES signature_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE signature_evidence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signature_request_id BIGINT UNSIGNED NOT NULL,
    evidence_type ENUM('signed_document','certificate','timestamp','provider_receipt','validation_result') NOT NULL,
    document_id BIGINT UNSIGNED NULL,
    achieved_level ENUM('SES','AES','QES','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
    validation_status ENUM('valid','invalid','indeterminate','not_validated') NOT NULL DEFAULT 'not_validated',
    provider_reference VARCHAR(190) NULL,
    detail JSON NULL,
    is_sandbox TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_sev_req FOREIGN KEY (signature_request_id) REFERENCES signature_requests(id),
    CONSTRAINT fk_sev_doc FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE notary_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    contract_version_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NULL,
    notary_user_id BIGINT UNSIGNED NOT NULL,
    notary_office VARCHAR(190) NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    responsible_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('prepared','provided','in_progress','inquiry','certified','rejected','completed') NOT NULL DEFAULT 'prepared',
    provided_at DATETIME(6) NULL,
    provided_pdf_sha256 CHAR(64) NULL,
    returned_at DATETIME(6) NULL,
    remarks VARCHAR(2000) NULL,
    completed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    KEY ix_nc_notary (notary_user_id, status),
    CONSTRAINT fk_nc_contract FOREIGN KEY (tenant_id, contract_id) REFERENCES contracts(tenant_id, id),
    CONSTRAINT fk_nc_version FOREIGN KEY (contract_version_id) REFERENCES contract_versions(id),
    CONSTRAINT fk_nc_doc FOREIGN KEY (document_id) REFERENCES documents(id),
    CONSTRAINT fk_nc_notary FOREIGN KEY (notary_user_id) REFERENCES users(id),
    CONSTRAINT fk_nc_req FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_nc_resp FOREIGN KEY (responsible_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE audit_events (
    seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id CHAR(32) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    tenant_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_label VARCHAR(190) NULL,
    action VARCHAR(80) NOT NULL,
    object_type VARCHAR(60) NULL,
    object_id BIGINT UNSIGNED NULL,
    outcome ENUM('success','denied','failure') NOT NULL,
    correlation_id CHAR(16) NULL,
    ip_hash CHAR(64) NULL,
    metadata JSON NULL,
    prev_hash CHAR(64) NOT NULL,
    entry_hash CHAR(64) NOT NULL,
    UNIQUE KEY uq_audit_event (event_id),
    KEY ix_audit_tenant (tenant_id, seq),
    KEY ix_audit_object (object_type, object_id),
    KEY ix_audit_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE audit_anchors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    last_seq BIGINT UNSIGNED NOT NULL,
    head_hash CHAR(64) NOT NULL,
    anchored_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    external_note VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE contract_deadlines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('contract_end','notice','auto_renewal','payment','performance','custom') NOT NULL,
    due_date DATE NOT NULL,
    label VARCHAR(255) NOT NULL,
    calc_rule VARCHAR(500) NULL,
    remind_days_before JSON NOT NULL,
    status ENUM('open','done','cancelled') NOT NULL DEFAULT 'open',
    responsible_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_dl_due (tenant_id, status, due_date),
    CONSTRAINT fk_dl_contract FOREIGN KEY (tenant_id, contract_id) REFERENCES contracts(tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    dedupe_key CHAR(64) NOT NULL,
    channel ENUM('dashboard','email') NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body VARCHAR(2000) NOT NULL,
    link_path VARCHAR(255) NULL,
    status ENUM('pending','sent','failed','dead') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NULL,
    last_error VARCHAR(500) NULL,
    read_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    sent_at DATETIME(6) NULL,
    UNIQUE KEY uq_notif_dedupe (user_id, channel, dedupe_key),
    KEY ix_notif_pending (status, next_attempt_at),
    CONSTRAINT fk_n_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_n_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE retention_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    contract_type_id BIGINT UNSIGNED NULL,
    jurisdiction VARCHAR(16) NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    legal_basis VARCHAR(500) NOT NULL,
    retention_years SMALLINT UNSIGNED NOT NULL,
    start_event ENUM('contract_end','archived_at','signed_at') NOT NULL DEFAULT 'contract_end',
    on_expiry ENUM('review','delete') NOT NULL DEFAULT 'review',
    legal_review_status ENUM('to_review','reviewed') NOT NULL DEFAULT 'to_review',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_rp2_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_rp2_type FOREIGN KEY (contract_type_id) REFERENCES contract_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE legal_holds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NULL,
    counterparty_id BIGINT UNSIGNED NULL,
    reason VARCHAR(1000) NOT NULL,
    placed_by BIGINT UNSIGNED NOT NULL,
    placed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    released_by BIGINT UNSIGNED NULL,
    released_at DATETIME(6) NULL,
    release_reason VARCHAR(1000) NULL,
    KEY ix_lh_contract (tenant_id, contract_id, released_at),
    CONSTRAINT fk_lh_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_lh_placed FOREIGN KEY (placed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE deletion_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    request_type ENUM('access','erasure') NOT NULL,
    subject_description VARCHAR(500) NOT NULL,
    counterparty_id BIGINT UNSIGNED NULL,
    status ENUM('open','granted','partially_granted','refused') NOT NULL DEFAULT 'open',
    decision_reason VARCHAR(2000) NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_dr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    UNIQUE KEY uq_tag (tenant_id, name),
    CONSTRAINT fk_tag_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE contract_tags (
    contract_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (contract_id, tag_id),
    CONSTRAINT fk_ctg_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ctg_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE custom_field_definitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    field_type ENUM('text','number','date','boolean','select') NOT NULL,
    options JSON NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_cfd (tenant_id, code),
    CONSTRAINT fk_cfd_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE custom_field_values (
    contract_id BIGINT UNSIGNED NOT NULL,
    field_id BIGINT UNSIGNED NOT NULL,
    value_text VARCHAR(2000) NULL,
    PRIMARY KEY (contract_id, field_id),
    CONSTRAINT fk_cfv_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cfv_field FOREIGN KEY (field_id) REFERENCES custom_field_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE external_mandates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    mandate_type ENUM('counterparty','audit','notary') NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    organization VARCHAR(190) NULL,
    responsible_user_id BIGINT UNSIGNED NOT NULL,
    allowed_actions JSON NOT NULL,
    valid_from DATETIME(6) NOT NULL,
    valid_until DATETIME(6) NOT NULL,
    status ENUM('active','revoked') NOT NULL DEFAULT 'active',
    revoked_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_em_user (user_id, status, valid_until),
    CONSTRAINT fk_em_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_em_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_em_resp FOREIGN KEY (responsible_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE external_mandate_contracts (
    mandate_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (mandate_id, contract_id),
    CONSTRAINT fk_emc_mandate FOREIGN KEY (mandate_id) REFERENCES external_mandates(id) ON DELETE CASCADE,
    CONSTRAINT fk_emc_contract FOREIGN KEY (contract_id) REFERENCES contracts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE system_settings (
    setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE auth_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('password_reset','email_verify','invite') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_token_hash (token_hash),
    CONSTRAINT fk_at_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket CHAR(64) NOT NULL,
    attempted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_la (bucket, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE api_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(40) NOT NULL,
    operation VARCHAR(80) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    related_type VARCHAR(40) NULL,
    related_id BIGINT UNSIGNED NULL,
    http_status SMALLINT NULL,
    outcome ENUM('success','failure') NOT NULL,
    error_summary VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_apitx (provider, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE maintenance_jobs (
    job_name VARCHAR(80) NOT NULL PRIMARY KEY,
    locked_until DATETIME(6) NULL,
    lock_owner CHAR(32) NULL,
    last_started_at DATETIME(6) NULL,
    last_finished_at DATETIME(6) NULL,
    last_status ENUM('success','failure','running') NULL,
    last_message VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@

CREATE TABLE schema_migrations (
    version VARCHAR(80) NOT NULL PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
