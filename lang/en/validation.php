<?php

declare(strict_types=1);

/**
 * English translations: validation messages.
 *
 * Replacements: :field → field label, :param → rule parameter value.
 */
return [
    'required'   => ':field is required.',
    'string'     => ':field must be a string.',
    'email'      => ':field must be a valid email address.',
    'integer'    => ':field must be an integer.',
    'min'        => ':field must be at least :param characters.',
    'max'        => ':field must not exceed :param.',
    'max_length' => ':field must not exceed :param characters.',
    'in'         => ':field must be one of: :param.',
    'date'       => ':field must be a valid date.',
    'url'        => ':field must be a valid URL.',
    'numeric'    => ':field must be a number.',
    'unique'     => ':field has already been taken.',
    'confirmed'  => ':field confirmation does not match.',
    'phone'      => ':field must be a valid phone number.',
    'slug'       => ':field must contain only lowercase letters, numbers, and hyphens.',
    'hex_color'  => ':field must be a valid hex color.',
    'timezone'   => ':field must be a valid timezone.',
    'file'       => ':field must be an uploaded file.',
    'image'      => ':field must be an image.',
    'max_size'   => ':field must not be larger than :param KB.',

    // ── Translated Attribute Labels ──
    // Used by Validator to produce human-readable field names.
    'attributes' => [
        'name'                 => 'Name',
        'email'                => 'Email address',
        'password'             => 'Password',
        'password_confirmation' => 'Password confirmation',
        'current_password'     => 'Current password',
        'new_password'         => 'New password',
        'confirm_password'     => 'Password confirmation',
        'phone'                => 'Phone number',
        'notes'                => 'Notes',
        'date'                 => 'Date',
        'time'                 => 'Time',
        'timezone'             => 'Timezone',
        'app_name'             => 'Application name',
        'app_url'              => 'Application URL',
        'slug'                 => 'Slug',
        'brand_color'          => 'Brand color',
        'booking_pattern'      => 'Booking pattern',
        'db_host'              => 'Database host',
        'db_port'              => 'Database port',
        'db_database'          => 'Database name',
        'db_username'          => 'Database username',
        'db_password'          => 'Database password',
        'mail_host'            => 'Mail host',
        'mail_port'            => 'Mail port',
        'mail_password'        => 'Mail password',
        'resend_api_key'       => 'Resend API key',
        'mail_from_address'    => 'From address',
        'mail_from_name'       => 'From name',
        'smtp_host'            => 'SMTP host',
        'smtp_port'            => 'SMTP port',
        'smtp_username'        => 'SMTP username',
        'smtp_password'        => 'SMTP password',
        'smtp_encryption'      => 'SMTP encryption',
        'mail_transport'       => 'Mail transport',
        'date_format'          => 'Date format',
    ],
];
