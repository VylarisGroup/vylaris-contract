-- Migration 004: Passkeys (WebAuthn). Rein additiv. Es werden nur öffentliche Schlüssel gespeichert.
CREATE TABLE passkeys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    credential_hash CHAR(64) NOT NULL,
    credential_id VARBINARY(1023) NOT NULL,
    public_key_pem TEXT NOT NULL,
    alg SMALLINT NOT NULL,
    sign_count INT UNSIGNED NOT NULL DEFAULT 0,
    transports VARCHAR(120) NULL,
    name VARCHAR(100) NOT NULL,
    backup_eligible TINYINT(1) NOT NULL DEFAULT 0,
    backup_state TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_used_at DATETIME(6) NULL,
    UNIQUE KEY uq_passkey_cred (credential_hash),
    KEY idx_passkey_user (user_id),
    CONSTRAINT fk_passkey_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
