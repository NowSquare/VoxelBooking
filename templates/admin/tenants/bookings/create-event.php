<?php
/**
 * Manual event booking creation form — tenant-scoped (event pattern).
 *
 * Variables: $tenant, $tenantId, $events, $csrfToken, $flash, $old
 */
$tenant   = $tenant ?? [];
$tenantId = $tenantId ?? '';
$events   = $events ?? [];
$baseUrl  = "/admin/tenants/" . htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8');

$activePage  = 'bookings';
ob_start();
?>

<?php if ($flash ?? null): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<div class="vb-page-header">
    <div>
        <a href="<?= $baseUrl ?>/bookings" class="vb-back-link">
            <i data-lucide="chevron-left"></i>
            <?= __('admin.bookings.title') ?>
        </a>
        <h2 class="vb-page-title"><?= __('admin.bookings.create_title') ?></h2>
        <p class="vb-page-subtitle"><?= __('admin.bookings.create_subtitle') ?></p>
    </div>
</div>

<form method="POST"
      action="<?= $baseUrl ?>/bookings/create"
      class="vb-animate-in" novalidate>
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Section 1: Event Details -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="ticket" class="vb-card-icon"></i>
                    <div class="vb-card-title"><?= __('admin.events.title') ?></div>
                </div>
            </div>

            <div class="vb-form-grid">
                <div class="vb-form-row">
                    <div class="vb-form-group">
                        <label for="create_event_id" class="vb-label"><?= __('admin.events.name_label') ?> <span class="vb-required">*</span></label>
                        <select id="create_event_id" name="event_id" class="vb-input" required>
                            <option value=""><?= __('booking.event.select_event') ?></option>
                            <?php foreach ($events as $event): ?>
                                <option value="<?= htmlspecialchars($event['id'], ENT_QUOTES, 'UTF-8') ?>"
                                        <?= old('event_id') === $event['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($event['name'], ENT_QUOTES, 'UTF-8') ?>
                                    — <?= \App\Engine\Locale::date(new \DateTimeImmutable($event['start_datetime'])) ?> <?= date('H:i', strtotime($event['start_datetime'])) ?>
                                    (<?= (int) $event['max_participants'] ?> max)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="vb-form-group">
                        <label for="create_spot_count" class="vb-label"><?= __('booking.event.spots_title') ?></label>
                        <input type="number" id="create_spot_count" name="spot_count" class="vb-input" min="1"
                               value="<?= e(old('spot_count', '1')) ?>">
                    </div>
                </div>

                <div class="vb-form-row">
                    <div class="vb-form-group">
                        <label for="create_date" class="vb-label"><?= __('booking.event.date_label') ?></label>
                        <input type="date" id="create_date" name="date" class="vb-input"
                               value="<?= e(old('date', '')) ?>">
                        <span class="vb-settings-hint"><?= __('admin.events.exception_dates_help') ?></span>
                    </div>
                    <div class="vb-form-group">
                        <!-- Intentional: reserved for future fields -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Customer -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="contact" class="vb-card-icon"></i>
                    <div class="vb-card-title"><?= __('admin.bookings.customer') ?></div>
                </div>
            </div>

            <div class="vb-form-grid">
                <div class="vb-form-row">
                    <div class="vb-form-group">
                        <label for="create_name" class="vb-label"><?= __('admin.bookings.label_customer_name') ?> <span class="vb-required">*</span></label>
                        <input type="text" id="create_name" name="customer_name" class="vb-input" required
                               value="<?= e(old('customer_name', '')) ?>"
                               autocomplete="off">
                    </div>
                    <div class="vb-form-group">
                        <label for="create_email" class="vb-label"><?= __('admin.bookings.label_customer_email') ?> <span class="vb-required">*</span></label>
                        <input type="email" id="create_email" name="customer_email" class="vb-input" required
                               value="<?= e(old('customer_email', '')) ?>"
                               autocomplete="off">
                    </div>
                </div>

                <div class="vb-form-row">
                    <div class="vb-form-group">
                        <label for="create_phone" class="vb-label"><?= __('admin.bookings.label_customer_phone') ?></label>
                        <input type="tel" id="create_phone" name="customer_phone" class="vb-input"
                               value="<?= e(old('customer_phone', '')) ?>"
                               autocomplete="off">
                    </div>
                    <div class="vb-form-group">
                        <!-- Intentional: reserved for future fields -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Internal Notes -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="notebook-pen" class="vb-card-icon"></i>
                    <div class="vb-card-title"><?= __('admin.bookings.label_notes') ?></div>
                </div>
            </div>
            <div class="vb-form-grid">
                <div class="vb-form-group">
                    <textarea id="create_notes" name="notes" class="vb-input" rows="3"
                              placeholder="<?= __('admin.bookings.label_notes') ?>…"><?= e(old('notes', '')) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="vb-form-actions">
        <a href="<?= $baseUrl ?>/bookings" class="vb-btn vb-btn-ghost">
            <?= __('admin.common.cancel') ?>
        </a>
        <button type="submit" class="vb-btn vb-btn-primary" id="btn-create-event-booking">
            <i data-lucide="check" class="vb-icon-sm"></i>
            <?= __('admin.bookings.btn_create_booking') ?>
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
