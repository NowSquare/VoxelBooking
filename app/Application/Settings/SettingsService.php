<?php

declare(strict_types=1);

namespace App\Application\Settings;

use App\Agent\{ActorContext, CanonicalJson, OperationException, SchemaValidator};
use App\Application\Shared\Access;
use App\Engine\{AuditLog, BrandColorHelper, Database, DemoMode, Locale};
use App\Models\Tenant;

final class SettingsService
{
    public static function read(ActorContext $actor, string $businessId, string $section): array
    {
        $tenant = Access::tenant(
            $actor,
            $businessId,
            'owner',
            scope: ['business-settings:read', 'business-settings:manage']
        );
        if ($section === 'booking' && !in_array($tenant['booking_pattern'], ['timeslot', 'resource'], true)) {
            throw new OperationException('pattern_not_supported', 422);
        }

        return self::projection($tenant, $section);
    }

    public static function projection(array $tenant, string $section): array
    {
        $values = [];
        foreach (SettingsFields::schema($section) as $field => $schema) {
            if (!empty($schema['writeOnly'])) {
                continue;
            }

            if (
                $section === 'booking'
                && $tenant['booking_pattern'] !== 'timeslot'
                && in_array($field, ['slot_duration_minutes', 'buffer_minutes'], true)
            ) {
                continue;
            }

            $value = $tenant[$field] ?? null;
            $values[$field] = match ($schema['type']) {
                'boolean' => (bool) $value,
                'integer' => (int) $value,
                default => $value
            };
        }

        if ($section === 'branding') {
            $values['logo_url'] = empty($tenant['logo_path'])
                ? null
                : \App\Agent\Endpoints::baseUrl() . '/' . $tenant['logo_path'];
        }

        if ($section === 'embed') {
            $values['embed_snippet'] = '<script src="' . \App\Agent\Endpoints::baseUrl() . '/embed/'
                . rawurlencode($tenant['slug']) . '.js" defer></script>';
        }

        return [
            'business_id' => $tenant['id'],
            'section' => $section,
            'values' => $values,
            'version' => CanonicalJson::hash($values),
            'url' => \App\Agent\Endpoints::baseUrl() . '/admin/tenants/' . $tenant['id'] . '/settings'
                . ($section === 'general' ? '' : '/' . $section)
        ];
    }

    public static function validateChanges(string $section, array $changes): array
    {
        (new SchemaValidator())->validate(
            [
                'type' => 'object',
                'minProperties' => 1,
                'additionalProperties' => false,
                'properties' => SettingsFields::schema($section)
            ],
            $changes
        );
        foreach ($changes as $field => $value) {
            if (is_string($value)) {
                $changes[$field] = trim($value);
            }
        }

        if (isset($changes['name']) && $changes['name'] === '') {
            throw new OperationException('validation_failed', 422, ['name' => ['required']]);
        }

        if (isset($changes['timezone']) && !in_array($changes['timezone'], get_supported_timezones(), true)) {
            throw new OperationException('validation_failed', 422, ['timezone' => ['unsupported']]);
        }

        if (isset($changes['locale']) && !Locale::isSupported($changes['locale'])) {
            throw new OperationException('validation_failed', 422, ['locale' => ['unsupported']]);
        }

        if (isset($changes['currency']) && !array_key_exists($changes['currency'], get_supported_currencies())) {
            throw new OperationException('validation_failed', 422, ['currency' => ['unsupported']]);
        }

        if (
            isset($changes['privacy_policy_url'])
            && !in_array(
                strtolower((string) parse_url($changes['privacy_policy_url'], PHP_URL_SCHEME)),
                ['https', 'http'],
                true
            )
        ) {
            throw new OperationException('validation_failed', 422, ['privacy_policy_url' => ['http_url_required']]);
        }

        if (isset($changes['allowed_embed_domains'])) {
            $domains = preg_split('/[\r\n,]+/', $changes['allowed_embed_domains']);
            $normalized = [];
            foreach ($domains as $domain) {
                $domain = strtolower(trim($domain));
                if ($domain === '') {
                    continue;
                }

                if (
                    preg_match(
                        '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D',
                        $domain
                    ) !== 1
                ) {
                    throw new OperationException(
                        'validation_failed',
                        422,
                        ['allowed_embed_domains' => ['hostname_required']]
                    );
                }

                $normalized[] = $domain;
            }

            $changes['allowed_embed_domains'] = $normalized ? implode(',', array_unique($normalized)) : null;
        }

        return $changes;
    }

    public static function preview(ActorContext $actor, array $input, string $section): array
    {
        $before = self::read($actor, $input['business_id'], $section);
        $changes = self::validateChanges($section, $input['changes']);
        if (!hash_equals($before['version'], $input['expected_version'])) {
            throw new OperationException('version_conflict', 409, nextAction: 'reread_record');
        }

        return [
            'business_id' => $input['business_id'],
            'section' => $section,
            'before' => $before['values'],
            'after' => array_replace($before['values'], $changes),
            'version' => $before['version'],
            'existing_bookings_preserved' => true
        ];
    }

    public static function update(ActorContext $actor, array $input, string $section): array
    {
        if (DemoMode::isActive()) {
            throw new OperationException('demo_restricted', 403);
        }

        return Access::transaction(static function () use ($actor, $input, $section): array {
            $tenant = Access::tenant($actor, $input['business_id'], 'owner', true, 'business-settings:manage');
            if ($section === 'booking' && !in_array($tenant['booking_pattern'], ['timeslot', 'resource'], true)) {
                throw new OperationException('pattern_not_supported', 422);
            }

            $before = self::projection($tenant, $section);
            if (!hash_equals($before['version'], $input['expected_version'])) {
                throw new OperationException('version_conflict', 409, nextAction: 'reread_record');
            }

            $changes = self::validateChanges($section, $input['changes']);
            if (
                $section === 'booking'
                && $tenant['booking_pattern'] !== 'timeslot'
                && (isset($changes['slot_duration_minutes']) || isset($changes['buffer_minutes']))
            ) {
                throw new OperationException('pattern_not_supported', 422);
            }

            if (isset($changes['slug']) && Tenant::slugExists($changes['slug'], excludeId: $tenant['id'])) {
                throw new OperationException('slug_in_use', 409, ['slug' => ['already_used']]);
            }

            if (isset($changes['brand_color'])) {
                $changes['brand_color_text'] = BrandColorHelper::derive($changes['brand_color'])['brand_text'];
            }

            if ($section === 'branding') {
                if (isset($changes['logo_media_id']) && !empty($changes['remove_logo'])) {
                    throw new OperationException(
                        'validation_failed',
                        422,
                        ['logo_media_id' => ['choose_replace_or_remove']]
                    );
                }

                if (isset($changes['logo_media_id'])) {
                    $changes['logo_path'] = \App\Application\Media\MediaService::consume(
                        $actor,
                        $tenant['id'],
                        $changes['logo_media_id'],
                        'logo'
                    );
                } elseif (!empty($changes['remove_logo'])) {
                    $changes['logo_path'] = null;
                }

                unset($changes['logo_media_id'], $changes['remove_logo']);
                if (
                    array_key_exists('logo_path', $changes)
                    && !empty($tenant['logo_path'])
                    && $changes['logo_path'] !== $tenant['logo_path']
                ) {
                    \App\Application\Media\MediaService::scheduleDeletion(
                        $tenant['logo_path'],
                        $tenant['id'],
                        'logo:' . $tenant['id'] . ':' . $before['version']
                    );
                }
            }

            if ($changes === []) {
                return $before;
            }

            $sets = array_map(static fn($field) => '`' . $field . '` = ?', array_keys($changes));
            Database::execute(
                'UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = ?',
                [...array_map(static fn($v) => is_bool($v) ? (int) $v : $v, array_values($changes)), $tenant['id']]
            );
            AuditLog::log(
                'tenant.settings_updated',
                'tenant',
                $tenant['id'],
                ['section' => $section, 'tab' => $section, 'fields' => array_keys($changes)],
                $tenant['id'],
                $actor->auditType(),
                $actor->principalId
            );
            return self::projection(array_replace($tenant, $changes), $section);
        });
    }
}
