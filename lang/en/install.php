<?php

declare(strict_types=1);

/**
 * English translations: installation wizard.
 */
return [
    // ── Flash Messages ──
    'flash' => [
        'db_connected'        => 'Database connected. MySQL :version. :count table(s) created.',
        'db_connected_existing' => 'Database connected. MySQL :version. All tables already exist.',
        'email_skipped'       => 'Email configuration skipped. You can set this up later in Settings.',
        'email_saved'         => 'Email configuration saved.',
        'operator_created'    => 'Operator account created.',
        'install_complete'    => 'Installation complete.',
        'migration_failed'    => 'Migration failed: :error',
        'reconnected_keep'    => 'Reconnected to existing database. Your data is intact.',
        'db_refreshed'        => 'Database refreshed. MySQL :version. :count table(s) created.',
        'passwords_mismatch'  => 'Passwords do not match.',
        'refresh_confirm_required' => 'Please confirm that you understand this will erase all existing data.',
    ],

    // ── Database Errors ──
    'db_errors' => [
        'access_denied'       => 'Access denied. Check your username and password.',
        'unknown_database'    => 'Database not found. Create it first, then try again.',
        'connection_refused'  => 'Connection refused. Is MySQL running on the specified host and port?',
        'socket_not_found'    => 'MySQL socket not found. Try using 127.0.0.1 instead of localhost as the host.',
        'timed_out'           => 'Connection timed out. Check the host address and port.',
        'generic'             => 'Database error: :message',
    ],

    // ── System Checks ──
    'checks' => [
        'php_version'         => 'PHP Version',
        'php_ok'              => 'PHP :version',
        'php_fail'            => 'PHP 8.3+ required. Current: :version',
        'pdo_mysql'           => 'PDO MySQL Extension',
        'curl'                => 'cURL Extension',
        'mbstring'            => 'mbstring Extension',
        'json'                => 'JSON Extension',
        'fileinfo'            => 'Fileinfo Extension',
        'openssl'             => 'OpenSSL Extension',
        'gd'                  => 'GD Extension',
        'zip'                 => 'Zip Extension',
        'loaded'              => 'Loaded',
        'enable_ext'          => 'Enable the :ext extension in your php.ini',
        'storage_logs'        => 'storage/logs writable',
        'public_uploads'      => 'public/uploads writable',
        'writable'            => 'Writable',
        'chmod'               => 'chmod 755 :path',
    ],

    // ── Wizard Template ──
    'wizard' => [
        'page_title'             => 'Install — :app_name',
        'complete_page_title'    => 'Installation Complete',

        // Step-bar short names (progress bar)
        'step_bar_1'             => 'System Check',
        'step_bar_2'             => 'Database',
        'step_bar_3'             => 'Email',
        'step_bar_4'             => 'Account',
        'step_bar_5'             => 'First Business',
        'step_of'                => 'Step :step of :total',
        'step_current'           => 'current',
        'step_done'              => 'done',
        'continue'               => 'Continue',
        'back'                   => 'Back',

        // Step 1: System Requirements
        'step1_title'            => 'System Requirements',
        'step1_desc'             => 'Checking PHP version, extensions, and directory permissions.',

        // Step 2: Database
        'step2_title'            => 'Database Configuration',
        'step2_desc'             => 'Enter your MySQL credentials. The wizard will test the connection and create the required tables. If the database already contains a VoxelBooking installation, it will reconnect automatically.',
        'db_host'                => 'MySQL Host',
        'db_port'                => 'Port',
        'db_name'                => 'Database Name',
        'db_username'            => 'Username',
        'db_password'            => 'Password',
        'db_password_hint'       => 'Leave empty if none required.',
        'db_submit'              => 'Test Connection & Continue',

        // Step 3: Email
        'step3_title'              => 'Email Configuration',
        'step3_desc'               => 'Choose how VoxelBooking sends emails. You can change this later in Settings.',
        'mail_transport'           => 'Transport',
        'mail_transport_smtp'      => 'SMTP (production)',
        'mail_transport_resend'    => 'Resend (HTTPS API — no SMTP ports needed)',
        'mail_transport_mailpit'   => 'Mailpit (local testing)',
        'mail_transport_log'       => 'Log to file (no sending)',
        'mail_host'                => 'SMTP Host',
        'mail_port'                => 'Port',
        'mail_username'            => 'Username',
        'mail_password'            => 'Password',
        'mail_encryption'          => 'Encryption',
        'mail_encryption_none'     => 'None',
        'resend_api_key'           => 'API Key',
        'resend_api_key_hint'      => 'Your Resend API key (starts with re_).',
        'mail_from_address'        => 'From Address',
        'mail_from_name'           => 'From Name',
        'mail_from_name_default'   => 'Booking System',
        'mail_submit'              => 'Save & Continue',
        'mail_skip'                => 'Skip for now',

        // Step 2b: Reconnect Decision
        'reconnect_title'                  => 'Existing Installation Detected',
        'reconnect_desc'                   => 'This database (MySQL :version) already contains a VoxelBooking installation. How would you like to proceed?',
        'reconnect_keep_title'             => 'Use existing data',
        'reconnect_keep_desc'              => 'Keep all existing operators, businesses, bookings, and settings. A new .env file will be created and you will be redirected to the login page.',
        'reconnect_keep_btn'               => 'Use existing data',
        'reconnect_refresh_title'          => 'Fresh install',
        'reconnect_refresh_desc'           => 'Delete all tables and start from scratch. All existing data (operators, businesses, bookings, settings) will be permanently destroyed.',
        'reconnect_refresh_confirm_label'  => 'I understand this will permanently erase all existing data',
        'reconnect_refresh_btn'            => 'Delete all data & reinstall',

        // Step 4: Operator
        'step4_title'            => 'Application & Admin Account',
        'step4_desc'             => 'Name your application and create the master administrator account.',
        'app_name'               => 'Application Name',
        'app_name_hint'          => 'Displayed in the header, emails, and browser tab. You can change this later in Settings.',
        'login_section_title'    => 'Admin Login Credentials',
        'login_section_desc'     => 'These credentials are used to sign in to the admin dashboard. Store them securely.',
        'op_name'                => 'Full Name',
        'op_name_placeholder'    => 'e.g. Admin',
        'op_email'               => 'Login Email',
        'op_email_hint'          => 'You will use this email to sign in.',
        'op_password'            => 'Password',
        'op_password_hint'       => 'Minimum 8 characters.',
        'op_password_confirm'    => 'Confirm Password',
        'op_password_show'       => 'Show password',
        'op_password_generate'   => 'Generate password',
        'op_submit'              => 'Create Account & Continue',

        // Step 4: Regional Defaults
        'regional_section_title' => 'Regional Defaults',
        'regional_section_desc'  => 'These settings apply system-wide and are inherited by new businesses.',
        'timezone'               => 'Timezone',
        'timezone_hint'          => 'Default timezone for new businesses. Auto-detected from your browser.',
        'locale'                 => 'Default language',
        'locale_hint'            => 'System-wide language. New businesses inherit this default.',
        'currency'               => 'Default currency',
        'currency_hint'          => 'Default currency for new businesses.',
        'date_format'            => 'Date notation',
        'number_format'          => 'Number notation',
        'time_format'            => 'Time format',
        'time_format_12h'        => '12-hour (2:30 PM)',
        'time_format_24h'        => '24-hour (14:30)',
        'week_start'             => 'Week starts on',

        // Step 5: First Business
        'step5_title'            => 'Create Your First Business',
        'step5_desc'             => 'Set up your first booking page. You can create more businesses later.',
        'tenant_name'            => 'Business Name',
        'tenant_pattern'         => 'Booking Pattern',
        'pattern_timeslot'       => 'Time Slots',
        'pattern_timeslot_desc'  => 'Salon, therapist, tutor',
        'pattern_resource'       => 'Resources',
        'pattern_resource_desc'  => 'B&B, hotel, meeting room',
        'pattern_capacity'       => 'Capacity',
        'pattern_capacity_desc'  => 'Restaurant, escape room',
        'pattern_event'          => 'Events',
        'pattern_event_desc'     => 'Yoga, cooking class',
        'tenant_email'           => 'Business Email',
        'tenant_brand_color'     => 'Brand Color',
        'tenant_submit'          => 'Create Business & Finish',
        'tenant_skip'            => 'I\'ll do this from the dashboard',

        // UI controls
        'toggle_theme'           => 'Toggle dark mode',
        'copy_url'               => 'Copy URL',

        // Complete step
        'complete_title'         => 'Installation Complete',
        'ready_message'          => ':app_name is ready to accept bookings.',
        'go_to_dashboard'        => 'Go to Dashboard',
        'version_label'          => 'v:version',
    ],
];
