<?php

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS oauth_clients (
        id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        redirect_uris JSON NOT NULL,
        secret_hash VARCHAR(255) NULL,
        auth_method VARCHAR(30) NOT NULL,
        registration_type VARCHAR(20) NOT NULL,
        metadata_expires_at DATETIME NULL,
        revoked_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        KEY oauth_client_expiry (metadata_expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS oauth_grants (
        connection_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        audience VARCHAR(512) NOT NULL,
        CONSTRAINT oauth_grant_connection_fk FOREIGN KEY (connection_id) REFERENCES agent_connections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS oauth_auth_codes (
        id_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        connection_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        KEY oauth_code_expiry (expires_at),
        CONSTRAINT oauth_code_connection_fk FOREIGN KEY (connection_id) REFERENCES agent_connections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS oauth_access_tokens (
        id_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        connection_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        credential_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        KEY oauth_access_expiry (expires_at),
        CONSTRAINT oauth_access_connection_fk FOREIGN KEY (connection_id) REFERENCES agent_connections(id) ON DELETE CASCADE,
        CONSTRAINT oauth_access_credential_fk FOREIGN KEY (credential_id) REFERENCES agent_credentials(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
        id_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        connection_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        access_id_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        KEY oauth_refresh_expiry (expires_at),
        CONSTRAINT oauth_refresh_connection_fk FOREIGN KEY (connection_id) REFERENCES agent_connections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
