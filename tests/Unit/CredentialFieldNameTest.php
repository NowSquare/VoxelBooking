<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Form field name collision guard for the mail credential surfaces.
 *
 * A browser submits every named control inside a form, including controls the
 * transport toggle has hidden with `display: none`. When two controls share a
 * name, PHP keeps the last one in $_POST and the earlier value disappears
 * before any controller sees it. For the mail forms that meant the SMTP
 * password lost a race against the empty Resend key field, so operators could
 * never store an SMTP password through the UI.
 *
 * These tests lock the invariant for every form in both templates, not only
 * the two fields that broke, because the next credential someone adds will
 * follow whatever pattern it finds.
 */
final class CredentialFieldNameTest extends TestCase
{
    private const ADMIN_EMAIL_TEMPLATE = 'templates/admin/settings/email.php';
    private const INSTALL_WIZARD_TEMPLATE = 'templates/install/wizard.php';

    public function test_admin_email_form_has_no_duplicate_control_names(): void
    {
        $this->assertNoDuplicateNames(self::ADMIN_EMAIL_TEMPLATE);
    }

    public function test_install_wizard_forms_have_no_duplicate_control_names(): void
    {
        $this->assertNoDuplicateNames(self::INSTALL_WIZARD_TEMPLATE);
    }

    public function test_admin_email_form_separates_smtp_password_from_resend_key(): void
    {
        $names = $this->controlNames(self::ADMIN_EMAIL_TEMPLATE);

        $this->assertContains('smtp_password', $names, 'SMTP password field is missing');
        $this->assertContains('resend_api_key', $names, 'Resend key must post under its own name');
    }

    public function test_install_wizard_separates_smtp_password_from_resend_key(): void
    {
        $names = $this->controlNames(self::INSTALL_WIZARD_TEMPLATE);

        $this->assertContains('mail_password', $names, 'SMTP password field is missing');
        $this->assertContains('resend_api_key', $names, 'Resend key must post under its own name');
    }

    /**
     * Hidden inputs still post. A disabled fieldset keeps the inactive
     * transport's credentials out of the request body, and it works before the
     * toggle script runs because the server renders the attribute too.
     */
    public function test_transport_blocks_are_fieldsets_the_toggle_disables(): void
    {
        foreach ([self::ADMIN_EMAIL_TEMPLATE, self::INSTALL_WIZARD_TEMPLATE] as $template) {
            $source = $this->source($template);

            $this->assertStringContainsString(
                '<fieldset',
                $source,
                $template . ' must group each transport in a fieldset so it can be disabled as a unit'
            );
            $this->assertMatchesRegularExpression(
                '/\.disabled\s*=/',
                $source,
                $template . ' must disable the controls of the inactive transport'
            );
        }
    }

    /**
     * @return list<string> Every control name in the template, in document order.
     */
    private function controlNames(string $relativePath): array
    {
        $names = [];

        foreach ($this->forms($relativePath) as $form) {
            foreach ($this->namesInForm($form) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function assertNoDuplicateNames(string $relativePath): void
    {
        foreach ($this->forms($relativePath) as $index => $form) {
            $names = $this->namesInForm($form);
            $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1));

            $this->assertSame(
                [],
                $duplicates,
                sprintf(
                    'Form #%d in %s posts these names more than once: %s. '
                    . 'PHP keeps only the last value, so the earlier control is silently discarded.',
                    $index + 1,
                    $relativePath,
                    implode(', ', $duplicates)
                )
            );
        }
    }

    /**
     * @return list<string> Raw `<form>…</form>` blocks.
     */
    private function forms(string $relativePath): array
    {
        preg_match_all('/<form\b.*?<\/form>/is', $this->source($relativePath), $matches);

        $this->assertNotEmpty($matches[0], 'No form found in ' . $relativePath);

        return $matches[0];
    }

    /**
     * Radio groups legitimately repeat a name, and `foo[]` collects an array,
     * so both stay out of the collision check.
     *
     * @return list<string>
     */
    private function namesInForm(string $form): array
    {
        preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', $form, $controls);

        $names = [];
        foreach ($controls[0] as $control) {
            if (!preg_match('/\bname="([^"]+)"/i', $control, $name)) {
                continue;
            }
            if (str_ends_with($name[1], '[]')) {
                continue;
            }
            if (preg_match('/\btype="(radio|submit|button)"/i', $control)) {
                continue;
            }
            $names[] = $name[1];
        }

        return $names;
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
