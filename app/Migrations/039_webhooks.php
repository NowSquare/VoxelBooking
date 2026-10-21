<?php

declare(strict_types=1);
return [
    "CREATE TABLE IF NOT EXISTS webhook_subscriptions (
        id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        tenant_id CHAR(26) NOT NULL, name VARCHAR(100) NOT NULL, endpoint VARCHAR(2048) NOT NULL,
        event_types JSON NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
        secret_cipher TEXT NOT NULL, previous_secret_cipher TEXT NULL, previous_secret_until DATETIME NULL,
        secret_revealed_at DATETIME NULL, generation INT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        KEY webhook_business (tenant_id,is_active,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS webhook_deliveries (
        id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        tenant_id CHAR(26) NOT NULL, subscription_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        event_id CHAR(26) NOT NULL, event_type VARCHAR(80) NOT NULL,
        payload_cipher MEDIUMTEXT NULL, outbox_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
        last_http_status SMALLINT UNSIGNED NULL, last_error_code VARCHAR(80) NULL,
        last_attempt_at DATETIME NULL, manual_retries SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        UNIQUE KEY webhook_event (subscription_id,event_id),
        KEY webhook_delivery_business (tenant_id,id), KEY webhook_delivery_subscription (subscription_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
