<?php

declare(strict_types=1);

namespace App\Application\System;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Application\Business\BusinessAccess as A;
use App\Application\Shared\Access;
use App\Engine\Database;
use App\Engine\Locale;
use App\Engine\Ulid;

/** Effective global configuration, with browser-only credential entry. */
final class SystemSettings
{
    public const GENERAL = [
        'app_name',
        'brand_url',
        'timezone',
        'default_locale',
        'default_currency',
        'date_format',
        'number_format',
        'time_format',
        'week_start',
        'enable_applications'
    ];
    public const MAIL = [
        'mail_transport',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_encryption',
        'mail_from_address',
        'mail_from_name'
    ];
    private const ENV = [
        'app_name' => 'APP_NAME',
        'brand_url' => 'BRAND_URL',
        'timezone' => 'APP_TIMEZONE',
        'default_locale' => 'APP_LOCALE',
        'default_currency' => 'APP_CURRENCY',
        'mail_transport' => 'MAIL_TRANSPORT',
        'smtp_host' => 'MAIL_HOST',
        'smtp_port' => 'MAIL_PORT',
        'smtp_username' => 'MAIL_USERNAME',
        'smtp_encryption' => 'MAIL_ENCRYPTION',
        'mail_from_address' => 'MAIL_FROM_ADDRESS',
        'mail_from_name' => 'MAIL_FROM_NAME',
        'smtp_password' => 'MAIL_PASSWORD'
    ];
    private const DEFAULTS = [
        'app_name' => 'VoxelBooking',
        'brand_url' => '',
        'timezone' => 'UTC',
        'default_locale' => 'en',
        'default_currency' => 'EUR',
        'date_format' => 'Y-m-d',
        'number_format' => 'period',
        'time_format' => '24h',
        'week_start' => '1',
        'enable_applications' => '0',
        'mail_transport' => 'smtp',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_username' => '',
        'smtp_encryption' => 'tls',
        'mail_from_address' => '',
        'mail_from_name' => ''
    ];

    public static function get(ActorContext $actor, array $input): array
    {
        A::actor($actor, 'system:read');
        return self::project(self::section($input));
    }

    public static function preview(ActorContext $actor, array $input): array
    {
        A::actor($actor, 'system:manage', write: true);
        $section = self::section($input);
        $before = self::project($section);
        A::check($before, $input);
        $changes = self::validate($section, $input['changes'] ?? []);
        return [
            'before' => $before['values'],
            'after' => array_replace($before['values'], $changes),
            'version' => $before['version'],
            'credentials_changed' => false
        ];
    }

    public static function update(ActorContext $actor, array $input): array
    {
        return self::write($actor, $input, null);
    }

    /** Native form entry only; no operation schema accepts a credential. */
    public static function updateBrowser(ActorContext $actor, array $input, ?string $credential): array
    {
        if ($actor->authMode !== 'session') {
            throw new OperationException('forbidden', 403);
        }

        return self::write($actor, $input, $credential);
    }

    private static function write(ActorContext $actor, array $input, ?string $credential): array
    {
        $actor = A::actor($actor, 'system:manage', write: true);
        return Access::transaction(function () use ($actor, $input, $credential): array {
            Database::query("SELECT value FROM settings WHERE `key`='system_settings_revision' FOR UPDATE");
            $actor = A::actor($actor, 'system:manage', write: true);
            $section = self::section($input);
            $before = self::project($section);
            A::check($before, $input);
            $changes = self::validate($section, $input['changes'] ?? []);
            foreach ($changes as $key => $value) {
                Database::upsertSetting($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }

            if ($credential !== null && $credential !== '') {
                if ($section !== 'mail' || strlen($credential) > 4096) {
                    A::invalid('credential');
                }

                Database::upsertSetting('smtp_password', $credential);
            }

            Database::upsertSetting('system_settings_revision', Ulid::generate());
            \App\Engine\AuditLog::log(
                'settings.updated',
                'settings',
                null,
                [
                    'section' => $section,
                    'changed_fields' => array_keys($changes),
                    'credentials_changed' => $credential !== null && $credential !== '',
                    'connection_id' => $actor->connectionId
                ],
                actorType: $actor->auditType(),
                actorId: $actor->principalId
            );
            return self::project($section);
        });
    }

    private static function section(array $input): string
    {
        $section = $input['section'] ?? 'general';
        if (!in_array($section, ['general', 'mail'], true)) {
            A::invalid('section');
        }

        return $section;
    }

    private static function project(string $section): array
    {
        $values = [];
        foreach ($section === 'general' ? self::GENERAL : self::MAIL as $key) {
            $values[$key] = self::effective($key);
        }

        if ($section === 'general') {
            $values['enable_applications'] = $values['enable_applications'] === '1';
        }

        $result = [
            'section' => $section,
            'values' => $values,
            'credentials_configured' => $section === 'mail' && self::effective('smtp_password') !== '',
            'credential_handoff' => $section === 'mail' ? '/admin/settings/email' : null
        ];
        $result['version'] = hash(
            'sha256',
            json_encode([$result, self::effective('system_settings_revision')], JSON_THROW_ON_ERROR)
        );
        return $result;
    }

    public static function effective(string $key): string
    {
        $row = Database::query('SELECT value FROM settings WHERE `key`=?', [$key])[0] ?? null;
        if ($row !== null) {
            return (string) $row['value'];
        }

        $env = self::ENV[$key] ?? null;
        $value = $env !== null ? ($_ENV[$env] ?? $_SERVER[$env] ?? getenv($env)) : false;
        return $value !== false && $value !== '' ? (string) $value : (self::DEFAULTS[$key] ?? '');
    }

    private static function validate(string $section, mixed $changes): array
    {
        if (!is_array($changes) || $changes === []) {
            A::invalid('changes');
        }

        $allowed = $section === 'general' ? self::GENERAL : self::MAIL;
        foreach ($changes as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                A::invalid((string) $key, 'unsupported_field');
            }

            if ($key === 'enable_applications') {
                if (!is_bool($value)) {
                    A::invalid($key);
                }

                continue;
            }

            if (
                !is_string($value)
                || strlen($value) > 2048
                || str_contains($value, "\0")
                || str_contains($value, "\r")
                || str_contains($value, "\n")
            ) {
                A::invalid($key);
            }

            $value = trim($value);
            $changes[$key] = $value;
            $valid = match ($key) {
                'app_name' => $value !== '' && mb_strlen($value) <= 255,
                'brand_url' => $value === ''
                    || (
                        filter_var($value, FILTER_VALIDATE_URL)
                        && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
                    ),
                'timezone' => in_array($value, get_supported_timezones(), true),
                'default_locale' => Locale::isSupported($value),
                'default_currency' => array_key_exists($value, get_supported_currencies()),
                'date_format' => in_array($value, ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd.m.Y'], true),
                'number_format' => in_array($value, ['period', 'comma', 'space'], true),
                'time_format' => in_array($value, ['12h', '24h'], true),
                'week_start' => in_array($value, ['0', '1', '6'], true),
                'mail_transport' => in_array($value, ['smtp', 'resend', 'mailpit', 'log'], true),
                'smtp_port' => $value === '' || (ctype_digit($value) && (int) $value >= 1 && (int) $value <= 65535),
                'smtp_encryption' => in_array($value, ['', 'tls', 'ssl', 'none'], true),
                'mail_from_address' => $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                default => mb_strlen($value) <= 255,
            };
            if (!$valid) {
                A::invalid($key);
            }
        }

        if (($changes['mail_transport'] ?? null) === 'resend') {
            foreach (['smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption'] as $key) {
                $changes[$key] = '';
            }
        }

        return $changes;
    }
}
