<?php

declare(strict_types=1);

/**
 * English translations: public booking flow.
 *
 * Key convention: section.element
 * Placeholders: :name (replaced at runtime)
 * Plurals: {0} None|{1} One|[2,*] :count items
 */
return [
    // ── Step Titles ──
    'steps' => [
        'service_title'    => 'Choose a service',
        'staff_title'      => 'Who would you like?',
        'staff_subtitle'   => 'Pick a team member, or let us assign whoever is available first.',
        'date_title'       => 'Pick a date',
        'time_title'       => 'Pick a time',
        'details_title'    => 'Your details',
        'details_subtitle' => 'We\'ll send a confirmation to your email.',
        'confirm_title'    => 'Confirm your booking',
        'confirm_subtitle' => 'Please review the details below.',
        // Resource-pattern
        'resource_title'    => 'Choose a room',
        'dates_title'       => 'Select dates',
        'dates_subtitle'    => 'Pick your check-in and check-out dates.',
        'guests_title'      => 'Number of guests',
    ],

    // ── Staff ──
    'staff' => [
        'any_available' => 'Any available',
    ],

    // ── Back Navigation ──
    'back' => [
        'change_service'     => '← Change service',
        'change_staff'       => '← Change team member',
        'change_date'        => '← Change date or time',
        'generic'            => '← Back',
        'change_guests'      => '← Change guests',
        'change_spots'       => '← Change spots',
        'edit_details'       => '← Edit details',
        'change_party_size'  => '← Change party size',
        'change_date_cap'    => '← Change date',
        'change_event'       => '← Change event',
    ],

    // ── Form Labels ──
    'form' => [
        'name_label'        => 'Name',
        'name_placeholder'  => 'Your name',
        'email_label'       => 'Email',
        'email_placeholder' => 'you@example.com',
        'phone_label'       => 'Phone',
        'phone_placeholder' => 'Your phone number',
        'notes_label'       => 'Notes',
        'notes_placeholder' => 'Any special requests?',
        'consent_default'   => 'I agree to the processing of my personal data for this booking.',
        'privacy_link'      => 'Privacy policy',
    ],

    // ── Buttons ──
    'buttons' => [
        'review'           => 'Review booking',
        'confirm'          => 'Confirm booking',
        'book_another'     => 'Book another appointment',
        'add_to_calendar'  => 'Add to Google Calendar',
        'download_ics'     => 'Download for Calendar',
        'reschedule'       => 'Reschedule',
        'cancel_booking'   => 'Cancel booking',
        'pick_another_time'=> 'Pick another time',
        'continue'         => 'Continue',
        'selected_time'    => 'Selected',
    ],

    // ── Confirmation ──
    'confirmed' => [
        'heading'           => 'Booking confirmed',
        'message'           => 'A confirmation has been sent to :email.',
        'email_sent'        => 'A confirmation has been sent to :email.',
        'reference_label'   => 'Reference',
    ],

    // ── Pending Approval ──
    'pending' => [
        'heading'           => 'Booking received',
        'message'           => 'Your booking is awaiting approval. We will notify you once it is confirmed.',
        'reference_label'   => 'Reference',
    ],

    // ── Review ──
    'review' => [
        'cancellation_policy_label' => 'Cancellation policy',
    ],

    // ── Summary ──
    'summary' => [
        'service_label'    => 'Service',
        'with_label'       => 'With',
        'date_label'       => 'Date',
        'time_label'       => 'Time',
        'duration_label'   => 'Duration',
        'price_label'      => 'Price',
        // Resource-pattern
        'resource_label'   => 'Room',
        'check_in_label'   => 'Check-in',
        'check_out_label'  => 'Check-out',
        'nights_label'     => 'Nights',
        'guests_label'     => 'Guests',
        'total_label'      => 'Total',
        'per_night'        => '/night',
        // Contact details (review step)
        'contact_name'     => 'Name',
        'contact_email'    => 'Email',
        'contact_phone'    => 'Phone',
    ],

    // ── Resource Pattern ──
    'resource' => [
        'summary_resource'  => 'Room',
        'check_in_label'    => 'Check-in',
        'check_out_label'   => 'Check-out',
        'nights_label'      => 'Nights',
        'guests_label'      => 'Guests',
        'total_label'       => 'Total',
        'per_night'         => '/night',
        'select_check_in'   => 'Select check-in date',
        'select_check_out'  => 'Now select your check-out date',
        'amenities_label'   => 'Amenities',
        'capacity_label'    => 'Up to :count guests',
        'stay_range'        => ':min–:max nights',
        'max_guests_reached'         => 'Maximum :count guests for this room',
        'error_resource_not_found'    => 'Room not found.',
        'error_invalid_date_range'    => 'Check-out must be after check-in.',
        'error_min_stay_violation'    => 'Minimum stay not met.',
        'error_max_stay_violation'    => 'Maximum stay exceeded.',
        'error_capacity_exceeded'     => 'Too many guests for this room.',
        'error_too_soon'              => 'Check-in date is too soon.',
        'error_too_far'               => 'Check-in date is too far ahead.',
        'error_date_blocked'          => 'One or more dates are blocked.',
        'error_already_booked'        => 'This room is already booked for those dates.',
        'error_invalid_check_in_day'  => 'Check-in is not available on this day of the week.',
        'error_invalid_check_out_day' => 'Check-out is not available on this day of the week.',
        'select_dates'                => 'Select your new check-in and check-out dates.',
    ],

    // ── Empty States ──
    'empty' => [
        'no_services'       => 'No services available',
        'no_services_desc'  => 'This business has not configured any services yet.',
        'no_availability'   => 'No available times this month.',
        'no_times'          => 'No available times on this day.',
        'coming_soon'       => 'Coming soon',
        'coming_soon_desc'  => 'This booking pattern is not yet available.',
        '404_title'         => 'Page not found',
        '404_desc'          => 'This booking page doesn\'t exist or is no longer active.',
        '404_help'          => 'If you followed a link here, please contact the business directly.',
        // Resource-pattern
        'no_resources'      => 'No rooms available',
        'no_resources_desc' => 'This business has not configured any rooms yet.',
        'no_dates'          => 'No available dates this month.',
        // Capacity-pattern
        'no_slots'          => 'No available time slots',
        'no_slots_desc'     => 'This business has not configured any time slots yet.',
        'no_slots_date'     => 'No available slots on this day.',
        // Event-pattern
        'no_events'         => 'No upcoming events',
        'no_events_desc'    => 'This business has no upcoming events scheduled.',
    ],

    // ── Capacity-Pattern Booking Page ──
    'capacity' => [
        'party_size_title'    => 'Party Size',
        'party_size_label'    => 'How many guests?',
        'party_size_hint'     => 'Select the number of guests in your party.',
        'guest'               => 'guest',
        'guests'              => 'guests',
        'date_title'          => 'Select Date',
        'time_title'          => 'Select Time',
        'spots_remaining'     => ':count spots left',
        'slot_full'           => 'Full',
        'min_guests_hint'     => 'Minimum :count guests',
        'max_party_size_reached' => 'Maximum :count guests per booking',
    ],

    // ── Event-Pattern Booking Page ──
    'event' => [
        'events_title'        => 'Upcoming Events',
        'event_detail_title'  => 'Event Details',
        'spots_title'         => 'How Many Spots?',
        'spot'                => 'spot',
        'spots'               => 'spots',
        'spots_remaining'     => ':count spots left',
        'event_full'          => 'This event is full.',
        'max_spots_reached'   => 'Maximum :count spots per booking',
        'max_reached'         => 'Maximum spots reached',
        'join_waitlist'       => 'Join Waitlist',
        'waitlist_notice'     => 'You will be added to the waitlist.',
        'waitlisted_title'    => 'You\'re on the Waitlist',
        'waitlisted_message'  => 'We\'ll notify you when a spot opens up.',
        'location_label'      => 'Location',
        'price_label'         => 'Price',
        'date_label'          => 'Date',
        'time_label'          => 'Time',
        'per_person'          => 'per person',
        'free'                => 'Free',
        'select_event'        => 'Select an event…',
        'full_badge'          => 'Full',
        'waitlist_badge'      => 'Waitlist',
        'no_upcoming'         => 'No upcoming events available for rescheduling.',
        'spots_left'          => 'spots left',
    ],

    // ── Errors & Toasts ──
    'errors' => [
        'slot_taken'        => 'This time slot was just taken. Please choose another.',
        'generic'           => 'Something went wrong. Please try again.',
        'connection'        => 'A connection error occurred. Please try again.',
        'spam_detected'     => 'Your request could not be processed. Please try again.',
        'required_name'     => 'Please enter your name.',
        'required_email'    => 'Please enter a valid email address.',
        'required_consent'  => 'You must accept the consent checkbox to continue.',
    ],

    // ── Common / UI ──
    'common' => [
        'dismiss' => 'Dismiss',
    ],

    // ── Timezone ──
    'timezone' => [
        'label'            => 'Timezone',
        'same_as_business' => 'same as business',
        'notice'           => 'Times shown in your timezone (:tz)',
        'search'           => 'Search timezone…',
        'group_americas'   => 'Americas',
        'group_europe'     => 'Europe',
        'group_asia'       => 'Asia & Pacific',
        'group_africa'     => 'Africa',
        'group_other'      => 'Other',
    ],

    // ── Duration Formatting ──
    'duration' => [
        'hours'        => 'h',
        'minutes'      => 'min',
        'hours_long'   => ':h h :m min',
        'minutes_only' => ':m min',
    ],

    // ── Time Period Labels (slot grouping) ──
    'time_periods' => [
        'morning'   => 'Morning',
        'afternoon' => 'Afternoon',
        'evening'   => 'Evening',
    ],

    // ── API Error Messages (server-side, returned as JSON) ──
    'api' => [
        'invalid_date'        => 'Date parameter required (YYYY-MM-DD)',
        'name_email_required' => 'Name and email are required.',
        'invalid_email'       => 'Invalid email address.',
        'phone_required'      => 'Phone number is required.',
        'start_time_required' => 'Start time is required.',
        'slot_unavailable'    => 'This time slot was just taken.',
        'booking_failed'      => 'An error occurred while creating your booking. Please try again.',
        'invalid_json'        => 'Invalid request body.',
        'spam_detected'       => 'Invalid request.',
        'spam_retry'          => 'Please try again.',
        'max_bookings_exceeded' => 'You have reached the maximum number of bookings for this day.',
        'csrf_mismatch'       => 'Invalid security token. Please refresh and try again.',
        // Resource-pattern
        'resource_required'     => 'Please select a resource.',
        'check_in_required'     => 'Check-in date is required.',
        'check_out_required'    => 'Check-out date is required.',
        'guest_count_invalid'   => 'Guest count must be at least 1.',
        'resource_unavailable'  => 'This resource is not available for the selected dates.',
        // Capacity-pattern
        'slot_required'         => 'Please select a time slot.',
        'date_required'         => 'Please select a date.',
        'party_size_invalid'    => 'Party size must be at least 1.',
        'party_too_small'       => 'Party size is below the minimum for this slot.',
        'capacity_exceeded'     => 'Not enough spots remaining for your party size.',
        // Event-pattern
        'event_required'        => 'Please select an event.',
        'spot_count_invalid'    => 'Spot count must be at least 1.',
        'spot_count_too_few'    => 'Minimum spots per booking is :min.',
        'spot_count_too_many'   => 'Maximum spots per booking is :max.',
        'event_full'            => 'This event is full.',
        'event_cancelled'       => 'This event has been cancelled.',
        'waitlist_full'         => 'The waitlist for this event is full.',
    ],

    // ── Recovery ──
    'recovery' => [
        'slot_taken' => 'That time was just booked. Try one of these instead:',
    ],

    // ── Calendar ──
    'calendar' => [
        'today'      => 'Today',
        'label'      => 'Calendar',
        'prev_month' => 'Previous month',
        'next_month' => 'Next month',
    ],

    // ── Day Names (0=Sunday) ──
    'days' => [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ],

    // ── Short Day Names ──
    'days_short' => [
        0 => 'SU',
        1 => 'MO',
        2 => 'TU',
        3 => 'WE',
        4 => 'TH',
        5 => 'FR',
        6 => 'SA',
    ],

    // ── Month Names (1-12) ──
    'months' => [
        1  => 'January',
        2  => 'February',
        3  => 'March',
        4  => 'April',
        5  => 'May',
        6  => 'June',
        7  => 'July',
        8  => 'August',
        9  => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ],

    // ── Footer ──
    'footer' => [
        'powered_by' => 'Powered by',
    ],

    // ── Theme Toggle ──
    'theme' => [
        'switch_to_light' => 'Switch to light mode',
        'switch_to_dark'  => 'Switch to dark mode',
        'toggle'          => 'Toggle theme',
    ],

    // ── Privacy Pages ──
    'privacy' => [
        'page_title'              => 'Your Data',
        'meta_description'        => 'Review your data held by :business',
        'not_found'               => 'Page not found',
        'export_failed'           => 'Export failed. Please try again later.',
        'subtitle'                => 'Your data held by this business',
        'personal_info'           => 'Personal Information',
        'name_label'              => 'Name',
        'email_label'             => 'Email',
        'phone_label'             => 'Phone',
        'customer_since'          => 'Customer since',
        'booking_history'         => 'Booking History',
        'no_bookings'             => 'No bookings found.',
        'party_size'              => 'Party size',
        'source'                  => 'Source',
        'consent_records'         => 'Consent Records',
        'consented'               => 'Consented',
        'actions_title'           => 'Actions',
        'gdpr_rights'             => 'Under GDPR, you have the right to export your data or request its deletion.',
        'export_data'             => 'Export Data (JSON)',
        'request_deletion'        => 'Request Deletion',
        'confirm_warning_title'   => 'Are you sure?',
        'confirm_warning_body'    => 'This will request permanent removal of your personal data. This cannot be undone once processed by the business.',
        'cancel'                  => 'Cancel',
        'confirm_deletion'        => 'Confirm Deletion',
        'footer_server'           => 'Your data is stored on :business\'s server',
        'anonymized_page_title'   => 'Data Removed',
        'anonymized_title'        => 'Your Data Has Been Removed',
        'anonymized_message'      => 'Your personal information has been anonymized as requested. Booking records are retained for operational purposes, but your name, email, phone number, and personal notes have been permanently removed.',
        'deletion_req_page_title' => 'Deletion Requested',
        'deletion_req_title'      => 'Deletion Requested',
        'deletion_req_message'    => 'Your data deletion request has been logged. The business operating this service has been notified and will process your request.',
        'deletion_req_next_title' => 'What happens next:',
        'deletion_req_next_body'  => 'The business will review your request and remove your personal data. Under GDPR, they must respond within 30 days. Booking records may be retained in anonymized form for operational history, but all personal identifiers will be removed.',
    ],

    // ── Self-Service Manage Page ──
    'manage' => [
        'page_title'             => 'Manage Booking',
        'heading'                => 'Your Booking',
        'cancel_heading'         => 'Cancel Booking',
        'cancel_confirm'         => 'Are you sure you want to cancel this booking?',
        'cancel_reason_label'    => 'Reason (optional)',
        'cancel_reason_placeholder' => 'Let us know why you are cancelling…',
        'cancel_button'          => 'Yes, cancel booking',
        'cancel_nevermind'       => 'Keep my booking',
        'cancelled_heading'      => 'Booking Cancelled',
        'cancelled_message'      => 'Your booking has been cancelled.',
        'book_again'             => 'Book again',
        'time_gate_cancel'       => 'This booking can no longer be cancelled.',
        'time_gate_reschedule'   => 'This booking can no longer be rescheduled.',
        'not_found'              => 'Booking not found.',
        'already_cancelled'      => 'This booking has already been cancelled.',
        'cancellation_disabled'  => 'Cancellation is not allowed for this booking.',
        'rescheduling_disabled'  => 'Rescheduling is not available for this booking.',
        'same_slot'              => 'You selected the same time as your current booking.',
        'status_confirmed'       => 'Confirmed',
        'status_pending'         => 'Awaiting Approval',
        'status_cancelled'       => 'Cancelled',
        'status_rescheduled'     => 'Rescheduled',
        'status_completed'       => 'Completed',
        'loading'                => 'Loading booking details…',
        // Reschedule flow
        'reschedule_heading'         => 'Reschedule Booking',
        'reschedule_pick_date'       => 'Choose a new date and time',
        'reschedule_review_heading'  => 'Confirm Reschedule',
        'reschedule_review_subtitle' => 'Your booking will be moved to the new time.',
        'reschedule_original_label'  => 'Current',
        'reschedule_new_label'       => 'New time',
        'reschedule_confirm_button'  => 'Confirm reschedule',
        'reschedule_cancel'          => '← Back to booking',
        'reschedule_success_heading' => 'Booking Rescheduled',
        'reschedule_success_message' => 'Your booking has been moved to the new time.',
        'reschedule_back_to_date'    => '← Change date or time',
        'reschedule_reason_disabled' => 'Rescheduling is not available for this booking.',
        'reschedule_reason_too_late' => 'The rescheduling window for this booking has passed.',
        'reschedule_reason_not_confirmed' => 'Only confirmed bookings can be rescheduled.',
        'privacy_link'                    => 'Your data & privacy',
    ],

    // ── Demo Mode ──
    'demo_notice' => 'Demo mode — changes are reset daily.',
];
