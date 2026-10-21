<?php
/**
 * Tenant Settings — General tab.
 *
 * Structure: Identity card → Booking URL card (slug + live URL) → Regional card.
 * Locale uses Locale::localeOptions() (controller-provided).
 * Slug is editable inside the booking URL card for contextual clarity.
 * No inline styles — all layout via design system classes.
 *
 * Variables: $tenant, $tenantId, $csrfToken, $activeTab, $flash,
 *            $localeOptions, $timezones, $currencyOptions
 */
$tenant          = $tenant ?? [];
$tenantId        = $tenantId ?? '';
$localeOptions   = $localeOptions ?? ['en' => 'English'];
$timezones       = $timezones ?? get_supported_timezones();
$currencyOptions = $currencyOptions ?? get_supported_currencies();

$baseUrl = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/book/';

ob_start();
?>

<?php
$pageHeader = [
    'title' => __('admin.tenant_settings.title'),
    'subtitle' => $tenant['name'] ?? '',
];
include __DIR__ . '/../../partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/settings" class="vb-animate-in" novalidate>
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="expected_version" value="<?= htmlspecialchars(\App\Application\Settings\SettingsService::projection($tenant, 'general')['version'], ENT_QUOTES, 'UTF-8') ?>">

    <!-- Section 1: Identity -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="building-2" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_identity') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_identity_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid vb-form-grid-2">
                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-name"><?= __('admin.tenant_settings.field_name') ?> <span class="vb-required">*</span></label>
                    <input type="text" class="vb-input<?= error_class('name') ?>" id="ts-name" name="name"
                           value="<?= e(old('name', $tenant['name'] ?? '')) ?>" required maxlength="255">
                    <?php if (has_error('name')): ?>
                        <div class="vb-form-error" role="alert"><?= field_error('name') ?></div>
                    <?php endif; ?>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-email"><?= __('admin.tenant_settings.field_email') ?> <span class="vb-required">*</span></label>
                    <input type="email" class="vb-input<?= error_class('email') ?>" id="ts-email" name="email"
                           value="<?= e(old('email', $tenant['email'] ?? '')) ?>" required>
                    <?php if (has_error('email')): ?>
                        <div class="vb-form-error" role="alert"><?= field_error('email') ?></div>
                    <?php endif; ?>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-phone"><?= __('admin.tenant_settings.field_phone') ?></label>
                    <input type="tel" class="vb-input" id="ts-phone" name="phone"
                           value="<?= e(old('phone', $tenant['phone'] ?? '')) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Booking URL (slug lives here for context) -->
    <?php
    // Effective slug: respects old input on validation failure, falls back to tenant row
    $effectiveSlug = old('slug', $tenant['slug'] ?? '');
    $baseUrlJs = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="vb-settings-section"
         x-data="slugEditor('<?= htmlspecialchars($effectiveSlug, ENT_QUOTES, 'UTF-8') ?>', '<?= $baseUrlJs ?>')">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="globe" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_booking_url') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_booking_url_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-card-body">
                <?php $effectiveSlugEsc = htmlspecialchars($effectiveSlug, ENT_QUOTES, 'UTF-8'); ?>
                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-slug"><?= __('admin.tenant_settings.field_slug') ?></label>
                    <input type="text" class="vb-input<?= error_class('slug') ?>" id="ts-slug" name="slug"
                           value="<?= $effectiveSlugEsc ?>"
                           x-model="slug"
                           @input="normalize()"
                           maxlength="100"
                           placeholder="my-business">
                    <?php if (has_error('slug')): ?>
                        <div class="vb-form-error" role="alert"><?= field_error('slug') ?></div>
                    <?php else: ?>
                        <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_slug_hint') ?></span>
                    <?php endif; ?>
                </div>
                <div class="vb-public-url-inline">
                    <code class="vb-public-url-text" x-text="fullUrl"><?= htmlspecialchars($baseUrl . $effectiveSlug, ENT_QUOTES, 'UTF-8') ?></code>
                    <div class="vb-public-url-actions">
                        <a href="/book/<?= $effectiveSlugEsc ?>"
                           :href="'/book/' + slug"
                           target="_blank" rel="noopener"
                           class="vb-public-url-btn" title="<?= __('admin.tenants.view_booking_page') ?>">
                            <i data-lucide="external-link"></i>
                        </a>
                        <button type="button"
                                class="vb-public-url-btn"
                                @click="copyUrl($event)"
                                title="<?= __('admin.common.copy_booking_url') ?>">
                            <span class="vb-copy-icon"><i data-lucide="copy"></i></span>
                            <span class="vb-copy-check"><i data-lucide="check"></i></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Regional -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="languages" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_regional') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_regional_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid vb-form-grid-2">
                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-timezone"><?= __('admin.tenant_settings.field_timezone') ?></label>
                    <select class="vb-input" id="ts-timezone" name="timezone">
                        <?php
                        $currentTz = old('timezone', $tenant['timezone'] ?? 'UTC');
                        foreach ($timezones as $tz):
                        ?>
                        <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>" <?= $tz === $currentTz ? 'selected' : '' ?>>
                            <?= htmlspecialchars(format_timezone($tz), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-currency"><?= __('admin.tenant_settings.field_currency') ?></label>
                    <select class="vb-input" id="ts-currency" name="currency">
                        <?php
                        $currentCurrency = old('currency', $tenant['currency'] ?? 'EUR');
                        foreach ($currencyOptions as $code => $label):
                        ?>
                        <option value="<?= $code ?>" <?= $code === $currentCurrency ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-locale"><?= __('admin.tenant_settings.field_locale') ?></label>
                    <select class="vb-input" id="ts-locale" name="locale">
                        <?php
                        $currentLocale = old('locale', $tenant['locale'] ?? 'en');
                        $effectiveOptions = $localeOptions;
                        if ($currentLocale !== '' && !isset($effectiveOptions[$currentLocale])) {
                            $effectiveOptions[$currentLocale] = $currentLocale;
                        }
                        foreach ($effectiveOptions as $code => $label):
                        ?>
                        <option value="<?= $code ?>" <?= $currentLocale === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-week-start"><?= __('admin.tenant_settings.field_week_start') ?></label>
                    <select class="vb-input" id="ts-week-start" name="week_start">
                        <?php
                        $currentWeekStart = old('week_start', $tenant['week_start'] ?? '1');
                        $weekDays = [
                            '1' => __('booking.days.1'),
                            '0' => __('booking.days.0'),
                            '6' => __('booking.days.6'),
                        ];
                        foreach ($weekDays as $wv => $wl):
                        ?>
                        <option value="<?= $wv ?>" <?= (string) $currentWeekStart === (string) $wv ? 'selected' : '' ?>><?= e($wl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-time-format"><?= __('admin.tenant_settings.field_time_format') ?></label>
                    <select class="vb-input" id="ts-time-format" name="time_format">
                        <?php
                        $currentTimeFormat = old('time_format', $tenant['time_format'] ?? '24h');
                        $timeFormats = [
                            '12h' => __('admin.tenant_settings.field_time_format_12h'),
                            '24h' => __('admin.tenant_settings.field_time_format_24h'),
                        ];
                        foreach ($timeFormats as $tv => $tl):
                        ?>
                        <option value="<?= $tv ?>" <?= $currentTimeFormat === $tv ? 'selected' : '' ?>><?= e($tl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-date-format"><?= __('admin.tenant_settings.field_date_format') ?></label>
                    <select class="vb-input" id="ts-date-format" name="date_format">
                        <?php
                        $currentDateFormat = old('date_format', $tenant['date_format'] ?? 'Y-m-d');
                        $dateFormats = [
                            'Y-m-d'  => '2026-04-21',
                            'd/m/Y'  => '21/04/2026',
                            'm/d/Y'  => '04/21/2026',
                            'd-m-Y'  => '21-04-2026',
                            'd.m.Y'  => '21.04.2026',
                        ];
                        foreach ($dateFormats as $dv => $dl):
                        ?>
                        <option value="<?= $dv ?>" <?= $currentDateFormat === $dv ? 'selected' : '' ?>><?= e($dl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-number-format"><?= __('admin.tenant_settings.field_number_format') ?></label>
                    <select class="vb-input" id="ts-number-format" name="number_format">
                        <?php
                        $currentNumberFormat = old('number_format', $tenant['number_format'] ?? 'period');
                        $numberFormats = \App\Engine\Locale::numberFormatPresets();
                        foreach ($numberFormats as $nv => $nl):
                        ?>
                        <option value="<?= $nv ?>" <?= $currentNumberFormat === $nv ? 'selected' : '' ?>><?= e($nl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="vb-form-actions">
        <button type="submit" class="vb-btn vb-btn-primary" id="save-general-btn">
            <i data-lucide="save"></i>
            <?= __('admin.settings.save_button') ?>
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
