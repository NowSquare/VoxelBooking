<?php

declare(strict_types=1);

/**
 * English translations: authentication.
 */
return [
    'page_title'          => 'Login — :app_name',
    'meta_description'    => ':app_name Admin Login',
    'hero_name'           => 'VoxelBooking',
    'hero_sub'            => 'Scheduling infrastructure',
    'login_heading'       => 'Sign in to your account',
    'email_label'         => 'Email address',
    'email_placeholder'   => 'operator@example.com',
    'password_label'      => 'Password',
    'password_placeholder' => '••••••••',
    'login_button'        => 'Sign in',
    'logging_in'          => 'Signing in…',
    'logout_button'       => 'Sign out',
    'invalid_credentials' => 'Invalid email or password.',
    'rate_limited'        => 'Too many login attempts. Please try again later.',
    'session_expired'     => 'Your session has expired. Please sign in again.',
    'footer'              => 'Powered by :app_name',
    'remember_me'         => 'Remember me',
    'toggle_theme'        => 'Toggle theme',

    // Method selector
    'tab_password'        => 'Password',
    'tab_otp'             => 'Login code',
    'tab_magic_link'      => 'Magic link',
    'send_code_button'    => 'Send login code',
    'send_link_button'    => 'Email me a login link',
    'check_email'         => 'Check your email for a login link.',
    'enter_code_heading'  => 'Enter login code',
    'enter_code_sub'      => 'We sent a 6-digit code to :email',
    'verify_button'       => 'Verify',
    'resend_code'         => 'Resend code',
    'back_to_login'       => 'Back to login',
    'code_invalid'        => 'Invalid or expired code. Please try again.',
    'link_invalid'        => 'Invalid or expired link. Please request a new one.',
    'code_sent'           => 'Login code sent. Check your email.',
    'send_failed'         => 'Could not send login email. Please try again or use a password.',
    'passwordless_unavailable' => 'Passwordless login is not available. Email delivery is not configured.',

    // OTP email
    'otp_email_subject'   => 'Your login code -- :app_name',
    'otp_email_body'      => 'Your login code is:',
    'otp_email_expiry'    => 'This code expires in 10 minutes.',
    'otp_email_ignore'    => 'If you did not request this, you can safely ignore this email.',

    // Magic-link email
    'magic_link_email_subject' => 'Sign in to :app_name',
    'magic_link_email_body'    => 'Click below to sign in:',
    'magic_link_email_cta'     => 'Sign in to :app_name',
    'magic_link_email_expiry'  => 'This link expires in 15 minutes and can only be used once.',
    'magic_link_email_ignore'  => 'If you did not request this, you can safely ignore this email.',

    // Password reset
    'forgot_password_link'     => 'Forgot your password?',
    'forgot_password_heading'  => 'Reset your password',
    'forgot_password_sub'      => 'Enter your email and we\'ll send a reset link.',
    'forgot_password_button'   => 'Send reset link',
    'forgot_password_sent'     => 'If an account exists with that email, a reset link has been sent.',
    'forgot_password_page'     => 'Forgot Password — :app_name',
    'reset_password_heading'   => 'Set a new password',
    'reset_password_button'    => 'Reset password',
    'reset_password_success'   => 'Password reset successfully. You can now sign in.',
    'reset_token_invalid'      => 'Invalid or expired reset link. Please request a new one.',
    'reset_password_label'     => 'New password',
    'reset_confirm_label'      => 'Confirm password',
    'reset_password_mismatch'  => 'Passwords do not match.',
    'reset_password_too_short' => 'Password must be at least 8 characters.',
    'reset_password_page'      => 'Reset Password — :app_name',

    // Password reset email
    'reset_email_subject'      => 'Reset your password — :app_name',
    'reset_email_body'         => 'Click below to reset your password:',
    'reset_email_cta'          => 'Reset your password',
    'reset_email_expiry'       => 'This link expires in 60 minutes and can only be used once.',
    'reset_email_ignore'       => 'If you did not request this, you can safely ignore this email.',
];
