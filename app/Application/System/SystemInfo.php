<?php

declare(strict_types=1);

namespace App\Application\System;

use App\Agent\ActorContext;
use App\Application\Business\BusinessAccess as A;
use App\Engine\Migrator;
use App\Engine\Version;

final class SystemInfo
{
    public static function status(ActorContext $actor, array $input = []): array
    {
        A::actor($actor, 'system:read');
        $migrator = new Migrator(dirname(__DIR__, 2) . '/Migrations');
        return [
            'version' => Version::get(),
            'installed' => SystemSettings::effective('installed_at') !== '',
            'schema_version' => $migrator->getCurrentVersion(),
            'pending_migrations' => $migrator->hasPending(),
            'php_supported' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'zip_updates_available' => class_exists(\ZipArchive::class),
            'update_handoff' => '/admin/updates',
            'install_handoff' => '/install',
            'update_policy' => 'operator_browser_or_host',
            'remote_update_checked' => false
        ];
    }

    public static function handoff(ActorContext $actor, array $input): array
    {
        A::actor($actor, 'system:read');
        $task = $input['task'] ?? '';
        $target = match ($task) {
            'mail_credentials' => '/admin/settings/email',
            'cron_credentials' => '/admin/settings/cron',
            'raw_logs' => '/admin/settings/logs',
            'update' => '/admin/updates',
            'installation' => '/install',
            default => null
        };
        if ($target === null) {
            A::invalid('task');
        }

        return [
            'task' => $task,
            'url' => $target,
            'authentication' => 'browser_session',
            'expires_at' => null,
            'reason' => match ($task) {
                'raw_logs' => 'Raw logs can contain sensitive runtime details.',
                'update' => 'Installing code requires the operator or hosting administrator.',
                'installation' => 'Installation and database credentials remain on the trusted host.',
                default => 'Enter credentials directly in the trusted browser.'
            }
        ];
    }

    public static function businessContext(ActorContext $actor, array $input): array
    {
        $actor = A::actor($actor, 'businesses:read');
        $business = A::tenant($actor, $input);
        return [
            'business_id' => $business['id'],
            'business_name' => $business['name'],
            'timezone' => $business['timezone'],
            'principal_type' => $actor->principalType,
            'principal_id' => $actor->principalId,
            'role' => $actor->role,
            'context_model' => 'explicit_business',
            'browser_session_changed' => false
        ];
    }
}
