<?php

declare(strict_types=1);

// Separate from the IP request log: counters share a unique, locked budget/window.
return [
    "CREATE TABLE IF NOT EXISTS `agent_rate_budgets` (
        `budget_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `window_start` BIGINT UNSIGNED NOT NULL,
        `attempts` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `expires_at` DATETIME NOT NULL,
        PRIMARY KEY (`budget_key`, `window_start`),
        KEY `agent_rate_expiry` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
