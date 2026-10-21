<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tests/bootstrap.php';

\App\Engine\Logger::init(dirname(__DIR__) . '/storage');
$migrator = new \App\Engine\Migrator(dirname(__DIR__) . '/app/Migrations');
$count = $migrator->migrate();
\App\Engine\Database::upsertSetting('installed_at', gmdate('Y-m-d H:i:s'));
\App\Engine\Database::upsertSetting('mail_transport', 'log');
\App\Engine\Database::upsertSetting('mail_from_address', 'test@example.test');
\Tests\Integration\TestFixtures::provision();
fwrite(STDOUT, "Applied {$count} migrations and synthetic fixtures.\n");
