<?php
/**
 * General Settings — Visual Design §16.
 *
 * Section cards: Application + System information.
 * Uses design system classes exclusively — no inline style blocks.
 *
 * Variables: $user, $version, $csrfToken, $settings, $flash, $pageTitle
 */
$activePage = 'settings.general';
$activeTab = 'general';
$timezones = get_supported_timezones();

ob_start();

include dirname(__DIR__, 2) . '/partials/settings-tabs.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../partials/alert.php'; ?>
<?php endif; ?>

<div class="vb-grid vb-grid-2">
    <!-- Application Settings -->
    <div class="vb-card vb-fade-in-up stagger-1">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="settings" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.settings.app_title') ?></div>
                    <div class="vb-card-desc"><?= __('admin.settings.app_desc') ?></div>
                </div>
            </div>
        </div>
        <form method="POST" action="/admin/settings">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="_record_version" value="<?= htmlspecialchars($recordVersion, ENT_QUOTES, 'UTF-8') ?>">

            <div class="vb-form-group">
                <label for="app_name" class="vb-label"><?= __('admin.settings.app_name_label') ?></label>
                <input type="text" id="app_name" name="app_name" class="vb-input" value="<?= htmlspecialchars($settings['app_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="VoxelBooking">
            </div>
            <div class="vb-form-group">
                <label for="brand_url" class="vb-label"><?= __('admin.settings.brand_url_label') ?></label>
                <input type="url" id="brand_url" name="brand_url" class="vb-input" value="<?= htmlspecialchars($settings['brand_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="https://voxelbooking.com">
                <span class="vb-hint"><?= __('admin.settings.brand_url_hint') ?></span>
            </div>
            <div class="vb-form-row">
                <div class="vb-form-group">
                    <label for="timezone" class="vb-label"><?= __('admin.settings.timezone_label') ?></label>
                    <select id="timezone" name="timezone" class="vb-select">
                        <?php foreach ($timezones as $tz): ?>
                            <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>" <?= ($settings['timezone'] ?? 'UTC') === $tz ? 'selected' : '' ?>>
                                <?= htmlspecialchars(format_timezone($tz), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="vb-form-group">
                    <label for="default_locale" class="vb-label"><?= __('admin.settings.locale_label') ?></label>
                    <select id="default_locale" name="default_locale" class="vb-select">
                        <?php
                        $currentLocale = $settings['default_locale'] ?? 'en';
                        foreach ($localeOptions as $code => $label):
                        ?>
                        <option value="<?= $code ?>" <?= $currentLocale === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="vb-hint"><?= __('admin.settings.locale_hint') ?></span>
                </div>
            </div>
            <div class="vb-form-row">
                <div class="vb-form-group">
                    <label for="default_currency" class="vb-label"><?= __('admin.settings.currency_label') ?></label>
                    <select id="default_currency" name="default_currency" class="vb-select">
                        <?php
                        $currentCurrency = $settings['default_currency'] ?? 'EUR';
                        foreach ($currencyOptions as $code => $label):
                        ?>
                        <option value="<?= $code ?>" <?= $currentCurrency === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="vb-hint"><?= __('admin.settings.currency_hint') ?></span>
                </div>
                <div class="vb-form-group">
                    <label for="date_format" class="vb-label"><?= __('admin.settings.date_format_label') ?></label>
                    <select id="date_format" name="date_format" class="vb-select">
                        <?php
                        $formats = ['Y-m-d' => '2026-03-27', 'd/m/Y' => '27/03/2026', 'm/d/Y' => '03/27/2026', 'd-m-Y' => '27-03-2026', 'd.m.Y' => '27.03.2026'];
                        foreach ($formats as $fmt => $example):
                        ?>
                            <option value="<?= $fmt ?>" <?= ($settings['date_format'] ?? 'Y-m-d') === $fmt ? 'selected' : '' ?>><?= $example ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="vb-form-row">
                <div class="vb-form-group">
                    <label for="number_format" class="vb-label"><?= __('admin.settings.number_format_label') ?></label>
                    <select id="number_format" name="number_format" class="vb-select">
                        <?php
                        $numberPresets = \App\Engine\Locale::numberFormatPresets();
                        $currentNumberFormat = $settings['number_format'] ?? 'period';
                        foreach ($numberPresets as $key => $example):
                        ?>
                            <option value="<?= $key ?>" <?= $currentNumberFormat === $key ? 'selected' : '' ?>><?= $example ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="vb-form-group">
                    <label for="time_format" class="vb-label"><?= __('admin.settings.time_format_label') ?></label>
                    <select id="time_format" name="time_format" class="vb-select">
                        <?php
                        $currentTimeFormat = $settings['time_format'] ?? '24h';
                        $timeFormats = [
                            '12h' => __('admin.settings.time_format_12h'),
                            '24h' => __('admin.settings.time_format_24h'),
                        ];
                        foreach ($timeFormats as $tv => $tl):
                        ?>
                        <option value="<?= $tv ?>" <?= $currentTimeFormat === $tv ? 'selected' : '' ?>><?= e($tl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="vb-form-row">
                <div class="vb-form-group">
                    <label for="week_start" class="vb-label"><?= __('admin.settings.week_start_label') ?></label>
                    <select id="week_start" name="week_start" class="vb-select">
                        <?php
                        $currentWeekStart = $settings['week_start'] ?? '1';
                        $weekDays = [
                            '1' => __('booking.days.1'),
                            '0' => __('booking.days.0'),
                            '6' => __('booking.days.6'),
                        ];
                        foreach ($weekDays as $wv => $wl):
                        ?>
                        <option value="<?= $wv ?>" <?= $currentWeekStart === $wv ? 'selected' : '' ?>><?= e($wl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="vb-form-group">
                    <!-- Reserved for future regional field -->
                </div>
            </div>

            <div class="vb-form-group">
                <label class="vb-toggle">
                    <input type="hidden" name="enable_applications" value="0">
                    <input type="checkbox" class="vb-toggle-input" name="enable_applications" value="1"
                           <?= ($settings['enable_applications'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <span class="vb-toggle-track"></span>
                    <span class="vb-toggle-label"><?= __('admin.settings.enable_applications_label') ?></span>
                </label>
                <span class="vb-hint"><?= __('admin.settings.enable_applications_hint') ?></span>
            </div>

            <div class="vb-form-actions">
                <button type="submit" class="vb-btn vb-btn-primary">
                    <i data-lucide="check"></i>
                    <?= __('admin.settings.save_button') ?>
                </button>
            </div>
        </form>
    </div>

    <!-- System Information -->
    <div class="vb-card vb-fade-in-up stagger-2">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="server" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.settings.system_title') ?></div>
                    <div class="vb-card-desc"><?= __('admin.settings.system_desc') ?></div>
                </div>
            </div>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="vb-info-value"><code>v<?= htmlspecialchars($version ?? '0.0.0', ENT_QUOTES, 'UTF-8') ?></code></span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label">PHP</span>
            <span class="vb-info-value"><code><?= PHP_VERSION ?></code></span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.settings.server') ?></span>
            <span class="vb-info-value"><code><?= htmlspecialchars(php_uname('s') . ' ' . php_uname('r'), ENT_QUOTES, 'UTF-8') ?></code></span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.settings.database') ?></span>
            <span class="vb-info-value"><code>MySQL</code></span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.settings.memory_limit') ?></span>
            <span class="vb-info-value"><code><?= ini_get('memory_limit') ?></code></span>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 2) . '/admin/layout.php';
