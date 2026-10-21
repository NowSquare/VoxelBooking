<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// The runner owns configuration and schema initialization. Never load or
// create the checkout's .env and never fall back to a development database.
\Tests\Support\TestEnvironment::boot();
