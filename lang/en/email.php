<?php

declare(strict_types=1);

/**
 * English translations: email subjects and content.
 */
return [
    'booking_confirmation' => [
        'subject'  => 'Booking confirmed – :service on :date',
        'heading'  => 'Booking Confirmed',
        'greeting' => 'Hi :name,',
        'body'     => 'Your booking has been confirmed.',
        'details'  => 'Booking details',
        'footer'   => 'If you need to make changes, please contact us.',
    ],

    'booking_reminder' => [
        'subject'  => 'Reminder: :service tomorrow at :time',
        'heading'  => 'Appointment Reminder',
        'greeting' => 'Hi :name,',
        'body'     => 'This is a reminder for your upcoming appointment.',
        'footer'   => 'If you need to make changes, please contact us.',
    ],

    'operator_notification' => [
        'subject' => 'New booking: :service – :customer',
        'body'    => 'A new booking has been made.',
    ],

    'operator_cancellation' => [
        'subject' => 'Booking cancelled: :service – :customer',
        'body'    => 'A booking has been cancelled.',
    ],

    'privacy_acknowledgment' => [
        'subject'  => 'Your privacy request has been received',
        'greeting' => 'Hi :name,',
        'body'     => 'We have received your data request and will process it within 30 days.',
    ],

    'deletion_completed' => [
        'subject' => 'Your data has been deleted',
        'body'    => 'Your personal data has been removed from our systems.',
    ],

    'export_acknowledgment' => [
        'subject' => 'Your data export from :tenant',
        'title'   => 'Data Export Completed',
        'body'    => 'Your personal data has been exported from :tenant. The export file was downloaded to your device during your session.',
        'footer'  => 'If you did not request this export, please contact the business directly.',
    ],

    'deletion_acknowledgment' => [
        'subject' => 'Deletion request received — :tenant',
        'title'   => 'Deletion Request Received',
        'body'    => 'Your data deletion request has been submitted to :tenant. The business will review your request and process it in accordance with data protection regulations.',
        'footer'  => 'Under GDPR, the business must respond within 30 days. Your personal data will be anonymized once the request is confirmed.',
    ],

    'operator_deletion' => [
        'subject'          => 'New deletion request — :customer',
        'title'            => 'New Deletion Request',
        'body'             => 'A customer has requested data deletion.',
        'detail_customer'  => 'Customer:',
        'detail_email'     => 'Email:',
        'detail_hashed'    => '(hashed)',
        'detail_tenant'    => 'Business:',
        'footer'           => 'Log in to :app_name and navigate to the Deletion Queue to process this request.',
    ],

    'business_user_welcome' => [
        'subject'           => "You've been invited to manage :tenant",
        'title'             => "You've been invited",
        'greeting'          => 'Hi :name,',
        'body'              => 'An account has been created for you to manage bookings at :tenant.',
        'detail_email'      => 'Email',
        'detail_password'   => 'Temporary password',
        'detail_login_url'  => 'Login URL',
        'cta_label'         => 'Log in to :tenant',
        'change_password'   => 'Change your password after your first login.',
        'booking_page_hint' => 'Your booking page is live at:',
        'footer'            => 'Sent by :app_name on behalf of :tenant.',
    ],

    'waitlist_confirmation' => [
        'subject'  => 'Waitlisted — :event',
        'heading'  => "You're on the waitlist",
        'greeting' => 'Hi :name,',
        'body'     => "The event is currently full, but you've been added to the waitlist. We'll notify you if a spot opens up.",
        'footer'   => 'If you have any questions, please contact us.',
    ],

    'cancellation' => [
        'subject'    => 'Booking cancelled — :business',
        'heading'    => 'Booking Cancelled',
        'greeting'   => 'Hi :name,',
        'body'       => 'Your booking has been cancelled as requested.',
        'footer'     => 'If this was a mistake, you can make a new booking at any time.',
        'book_again' => 'Book Again',
    ],

    'approval_request' => [
        'subject'  => 'Your booking request has been received — :business',
        'heading'  => 'Request Received',
        'greeting' => 'Hi :name,',
        'body'     => 'Your booking is pending approval. We will notify you once it has been confirmed.',
        'footer'   => 'If you have any questions, please contact us.',
    ],

    'approval_confirmed' => [
        'subject'  => 'Your booking has been approved — :business',
        'heading'  => 'Booking Approved',
        'greeting' => 'Hi :name,',
        'body'     => 'Your booking has been approved and is now confirmed.',
        'footer'   => 'If you need to make changes, please contact us.',
    ],

    'reschedule_confirmation' => [
        'subject'  => 'Booking rescheduled — :business',
        'heading'  => 'Booking Rescheduled',
        'greeting' => 'Hi :name,',
        'body'     => 'Your booking has been rescheduled to a new time.',
        'footer'   => 'If you need to make further changes, please contact us.',
    ],

    'test' => [
        'subject' => ':app_name — SMTP Test',
        'title'   => 'SMTP Configuration Verified',
        'body'    => 'This test email confirms your SMTP settings are working correctly.',
    ],

    'common' => [
        'date'       => 'Date',
        'time'       => 'Time',
        'service'    => 'Service',
        'staff'      => 'Staff',
        'room'       => 'Room',
        'check_in'   => 'Check-in',
        'check_out'  => 'Check-out',
        'guests'     => 'Guests',
        'total'      => 'Total',
        'regards'        => 'Best regards,',
        'customer'       => 'Customer',
        'manage_booking' => 'View or Manage Booking',
        'powered_by'     => 'Powered by :app_name',
    ],
];
