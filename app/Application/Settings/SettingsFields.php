<?php

declare(strict_types=1);

namespace App\Application\Settings;

/** The fields the human settings forms can edit; immutable across transports. */
final class SettingsFields
{
    public static function sections(): array
    {
        return ['general', 'branding', 'bookingpage', 'booking', 'privacy', 'notifications', 'embed'];
    }

    public static function schema(string $section): array
    {
        $short = ['type' => ['string', 'null'], 'maxLength' => 255];
        $text = ['type' => ['string', 'null'], 'maxLength' => 10000];
        $bool = ['type' => 'boolean'];
        $hours = ['type' => 'integer', 'minimum' => 0, 'maximum' => 8760];
        return match ($section) {
            'general' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
                'phone' => ['type' => ['string', 'null'], 'maxLength' => 50],
                'slug' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 100,
                    'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'
                ],
                'timezone' => ['type' => 'string', 'enum' => \DateTimeZone::listIdentifiers()],
                'locale' => ['type' => 'string', 'maxLength' => 10],
                'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                'week_start' => ['type' => 'integer', 'enum' => [0, 1, 6]],
                'time_format' => ['type' => 'string', 'enum' => ['12h', '24h']],
                'date_format' => ['type' => 'string', 'enum' => ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd.m.Y']],
                'number_format' => ['type' => 'string', 'enum' => ['period', 'comma', 'space']],
            ],
            'branding' => [
                'brand_color' => ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$'],
                'show_powered_by' => $bool,
                'booking_page_heading' => $short,
                'booking_page_description' => $text,
                'logo_media_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 26, 'writeOnly' => true],
                'remove_logo' => ['type' => 'boolean', 'writeOnly' => true]
            ],
            'bookingpage' => [
                'confirmation_message' => $text,
                'cancellation_policy' => $text,
                'require_phone' => $bool,
                'booking_requires_approval' => $bool,
                'allow_cancellation' => $bool,
                'cancellation_hours_before' => $hours,
                'allow_rescheduling' => $bool,
                'rescheduling_hours_before' => $hours
            ],
            'booking' => [
                'min_advance_hours' => $hours,
                'max_advance_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3660],
                'max_bookings_per_customer_per_day' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
                'slot_duration_minutes' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 1440],
                'buffer_minutes' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1440]
            ],
            'privacy' => [
                'requires_consent' => $bool,
                'privacy_policy_url' => ['type' => ['string', 'null'], 'format' => 'uri', 'maxLength' => 500],
                'consent_text' => ['type' => ['string', 'null'], 'maxLength' => 500],
                'data_retention_months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1200]
            ],
            'notifications' => [
                'notification_email' => ['type' => ['string', 'null'], 'format' => 'email', 'maxLength' => 255],
                'notify_on_booking' => $bool,
                'notify_on_cancellation' => $bool,
                'send_reminders' => $bool,
                'reminder_hours_before' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8760]
            ],
            'embed' => [
                'embed_button_position' => ['type' => 'string', 'enum' => ['bottom-right', 'bottom-left']],
                'embed_button_label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                'allowed_embed_domains' => ['type' => ['string', 'null'], 'maxLength' => 4000]
            ],
            default => throw new \InvalidArgumentException('Unknown settings section.'),
        };
    }

    public static function input(string $section, bool $write): array
    {
        $properties = ['business_id' => ['type' => 'string']];
        $required = ['business_id'];
        if ($write) {
            $properties += [
                'expected_version' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'changes' => [
                    'type' => 'object',
                    'minProperties' => 1,
                    'additionalProperties' => false,
                    'properties' => self::schema($section)
                ]
            ];
            $required = [...$required, 'expected_version', 'changes'];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false
        ];
    }
}
