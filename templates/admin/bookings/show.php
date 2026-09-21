<?php
/**
 * Booking detail view.
 *
 * Two-column layout: left = booking info, right = actions (status + reschedule).
 * Below: event timeline from audit log.
 *
 * Variables: $user, $version, $csrfToken, $booking, $backUrl, $pageTitle,
 *            $activePage, $flash, $timeline
 */
$activePage = 'bookings';

// Timeline label helper — maps audit action keys to translated labels
if (!function_exists('bookingTimelineLabel')) {
    function bookingTimelineLabel(array $event): string {
        $details = !empty($event['details']) ? json_decode($event['details'], true) : [];

        // Map audit action keys to existing admin.audit.* translation keys
        $actionKey = 'admin.audit.action_' . str_replace('.', '_', $event['action']);
        $label = __($actionKey);

        // If translation returns the raw key (key not found), humanize the action
        if ($label === $actionKey) {
            $label = ucfirst(str_replace(['.', '_'], ' ', $event['action']));
        }

        // Append old→new for status changes, using translated status labels
        if ($event['action'] === 'booking.status_changed'
            && isset($details['old_status'], $details['new_status'])) {
            $oldLabel = __('admin.bookings.status_' . $details['old_status']);
            $newLabel = __('admin.bookings.status_' . $details['new_status']);
            $label .= ': ' . $oldLabel . ' → ' . $newLabel;
        }

        return $label;
    }
}

// Reschedule eligibility (computed once, used in both card and modal rendering)
$canReschedule = ($booking['status'] === 'confirmed')
    && !\App\Engine\DemoMode::isActive();

ob_start();
?>

<?php if ($flash ?? null): ?>
    <?php include __DIR__ . '/../../partials/alert.php'; ?>
<?php endif; ?>

<div class="vb-page-header">
    <div>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="vb-back-link">
            <i data-lucide="chevron-left"></i>
            <?= __('admin.bookings.back_to_list') ?>
        </a>
        <h2 class="vb-page-title"><?= __('admin.bookings.detail_title') ?></h2>
    </div>
</div>

<div class="vb-grid vb-grid-2 vb-fade-in-up">
    <!-- Booking Info -->
    <div class="vb-card vb-card-flush">
        <div class="vb-card-section-header">
            <div class="vb-card-title-row">
                <i data-lucide="calendar-check" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.bookings.detail_title') ?></div>
                    <div class="vb-card-desc"><?= __('admin.bookings.detail_desc') ?></div>
                </div>
            </div>
        </div>
        <div class="vb-card-body">
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.bookings.customer') ?></span>
            <span class="vb-info-value">
                <?php if (!empty($booking['customer_id'])): ?>
                    <div class="vb-cell-primary">
                        <a href="/admin/tenants/<?= htmlspecialchars($booking['tenant_id'], ENT_QUOTES, 'UTF-8') ?>/customers/<?= htmlspecialchars($booking['customer_id'], ENT_QUOTES, 'UTF-8') ?>"
                           class="vb-link">
                            <?= htmlspecialchars($booking['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="vb-cell-primary"><?= htmlspecialchars($booking['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="vb-cell-secondary"><?= htmlspecialchars($booking['customer_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            </span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.bookings.service') ?></span>
            <span class="vb-info-value">
                <?= htmlspecialchars(booking_display_label($booking), ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
        <?php if (!empty($booking['staff_name'])): ?>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.bookings.label_staff') ?></span>
            <span class="vb-info-value"><?= htmlspecialchars($booking['staff_name'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.bookings.date_time') ?></span>
            <span class="vb-info-value">
                <?= \App\Engine\Locale::dateLong(new \DateTimeImmutable($booking['start_datetime'])) ?><br>
                <span class="vb-cell-secondary">
                    <?= date('H:i', strtotime($booking['start_datetime'])) ?> – <?= date('H:i', strtotime($booking['end_datetime'])) ?>
                </span>
            </span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.bookings.status') ?></span>
            <span class="vb-info-value">
                <span class="vb-status vb-status-<?= htmlspecialchars($booking['status'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= __('admin.bookings.status_' . $booking['status']) ?>
                </span>
            </span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.bookings.tenant') ?></span>
            <span class="vb-info-value"><?= htmlspecialchars($booking['tenant_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.bookings.source') ?></span>
            <span class="vb-info-value">
                <span class="vb-badge vb-badge-default"><?= htmlspecialchars(ucfirst($booking['source'] ?? 'widget'), ENT_QUOTES, 'UTF-8') ?></span>
            </span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.bookings.created') ?></span>
            <span class="vb-info-value"><?= \App\Engine\Locale::datetime(new \DateTimeImmutable($booking['created_at'])) ?></span>
        </div>
        <?php if (!empty($booking['notes'])): ?>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.bookings.label_notes') ?></span>
            <span class="vb-info-value"><?= nl2br(htmlspecialchars($booking['notes'], ENT_QUOTES, 'UTF-8')) ?></span>
        </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Actions -->
    <div class="vb-card vb-card-flush">
        <div class="vb-card-section-header">
            <div class="vb-card-title-row">
                <i data-lucide="layers" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.bookings.actions_title') ?></div>
                    <div class="vb-card-desc"><?= $canReschedule ? __('admin.bookings.actions_desc') : __('admin.bookings.actions_desc_status_only') ?></div>
                </div>
            </div>
        </div>

        <?php if ($booking['status'] === 'rescheduled'): ?>
        <!-- Terminal state: rescheduled booking — historical record -->
        <div class="vb-card-body">
            <div class="vb-notice vb-notice-muted">
                <i data-lucide="archive" class="vb-notice-icon"></i>
                <div>
                    <div class="vb-notice-text"><?= __('admin.bookings.rescheduled_notice') ?></div>
                </div>
            </div>

            <?php if (!empty($booking['rescheduled_to_id'])): ?>
            <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>/<?= htmlspecialchars($booking['rescheduled_to_id'], ENT_QUOTES, 'UTF-8') ?>"
               class="vb-btn vb-btn-primary vb-mt-sm" id="btn-view-active-booking">
                <i data-lucide="arrow-right"></i>
                <?= __('admin.bookings.view_active_booking') ?>
            </a>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- Active booking: status form + reschedule -->
        <div class="vb-card-body">
            <?php if (!empty($rescheduledFrom ?? null)): ?>
            <div class="vb-notice vb-notice-info vb-mb-sm">
                <i data-lucide="rotate-ccw" class="vb-notice-icon"></i>
                <div>
                    <div class="vb-notice-text">
                        <?= __('admin.bookings.rescheduled_from') ?>
                        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>/<?= htmlspecialchars($rescheduledFrom, ENT_QUOTES, 'UTF-8') ?>" class="vb-link">
                            <?= __('admin.bookings.view_original_booking') ?>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" action="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>/<?= htmlspecialchars($booking['id'], ENT_QUOTES, 'UTF-8') ?>/status">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="vb-form-group">
                    <label for="booking_status" class="vb-label"><?= __('admin.bookings.status') ?></label>
                    <select id="booking_status" name="status" class="vb-select">
                        <?php
                        // Allowed transitions per current status. The current status is
                        // always included so the dropdown has a valid default selection.
                        $transitions = [
                            'pending'    => ['pending', 'confirmed', 'cancelled'],
                            'confirmed'  => ['confirmed', 'cancelled', 'completed', 'no_show'],
                            'waitlisted' => ['waitlisted', 'confirmed', 'cancelled'],
                            'cancelled'  => ['cancelled', 'confirmed'],
                            'completed'  => ['completed', 'confirmed'],
                            'no_show'    => ['no_show', 'confirmed'],
                            'rescheduled' => ['rescheduled'],
                        ];
                        $allowed = $transitions[$booking['status']] ?? [$booking['status']];
                        ?>
                        <?php foreach ($allowed as $s): ?>
                            <option value="<?= $s ?>" <?= $booking['status'] === $s ? 'selected' : '' ?>>
                                <?= __('admin.bookings.status_' . $s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="vb-form-actions">
                    <button type="submit" class="vb-btn vb-btn-primary">
                        <i data-lucide="check"></i>
                        <?= __('admin.bookings.change_status') ?>
                    </button>
                </div>
            </form>
        </div>

        <?php if ($canReschedule): ?>
        <div class="vb-card-section-header vb-card-section-divider">
            <div class="vb-card-title-row">
                <i data-lucide="calendar-clock" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.bookings.reschedule') ?></div>
                    <div class="vb-card-desc"><?= __('admin.bookings.reschedule_hint') ?></div>
                </div>
            </div>
        </div>
        <div class="vb-card-body">
            <button type="button" class="vb-btn vb-btn-secondary" id="btn-reschedule-open">
                <i data-lucide="calendar-clock"></i>
                <?= __('admin.bookings.reschedule') ?>
            </button>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>



<!-- Event Timeline -->
<?php if (!empty($timeline)): ?>
<div class="vb-card vb-card-flush vb-fade-in-up stagger-3 vb-mt-lg">
    <div class="vb-card-section-header">
        <div class="vb-card-title-row">
            <i data-lucide="list" class="vb-card-icon"></i>
            <div>
                <div class="vb-card-title"><?= __('admin.bookings.activity') ?></div>
                <div class="vb-card-desc"><?= __('admin.bookings.activity_desc') ?></div>
            </div>
        </div>
    </div>
    <div class="vb-card-body">
        <div class="vb-timeline">
            <?php foreach ($timeline as $event): ?>
            <div class="vb-timeline-entry <?= $event['action'] === 'booking.created' ? 'is-created' : (str_contains($event['action'], 'status') ? 'is-status' : '') ?>">
                <div class="vb-timeline-action"><?= htmlspecialchars(bookingTimelineLabel($event), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="vb-timeline-meta">
                    <?= \App\Engine\Locale::datetime(new \DateTimeImmutable($event['created_at'])) ?>
                    · <?= htmlspecialchars($event['actor_type'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();

// Capture modal HTML into $modals — rendered at body level by layout.php,
// outside .vb-content (which creates a containing block via its entrance animation).
$modals = '';
if ($canReschedule ?? false):
    ob_start();
?>
<!-- Reschedule Modal -->
<div id="reschedule-modal" class="vb-modal-overlay" style="display: none;">
    <div class="vb-modal vb-modal-sm">
        <div class="vb-modal-header">
            <h3 class="vb-modal-title"><?= __('admin.bookings.reschedule_title') ?></h3>
            <button type="button" class="vb-modal-close" id="btn-reschedule-close" aria-label="<?= __('admin.common.close') ?>">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="vb-modal-body">
            <p class="vb-modal-desc"><?= __('admin.bookings.reschedule_desc') ?></p>
            <form method="POST" action="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>/<?= htmlspecialchars($booking['id'], ENT_QUOTES, 'UTF-8') ?>/reschedule" id="reschedule-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <?php $bookingPattern = $booking['booking_pattern'] ?? 'timeslot'; ?>

                <?php if ($bookingPattern === 'timeslot'): ?>
                <div class="vb-modal-form-row">
                    <div class="vb-form-group">
                        <label for="reschedule_date" class="vb-label"><?= __('admin.bookings.reschedule_new_date') ?></label>
                        <input type="date" id="reschedule_date" name="new_date" class="vb-input"
                               value="<?= date('Y-m-d', strtotime($booking['start_datetime'])) ?>"
                               min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="vb-form-group">
                        <label for="reschedule_time" class="vb-label"><?= __('admin.bookings.reschedule_new_time') ?></label>
                        <input type="time" id="reschedule_time" name="new_time" class="vb-input"
                               value="<?= date('H:i', strtotime($booking['start_datetime'])) ?>" required>
                    </div>
                </div>

                <?php elseif ($bookingPattern === 'resource'): ?>
                <div class="vb-modal-form-row">
                    <div class="vb-form-group">
                        <label for="reschedule_checkin" class="vb-label"><?= __('admin.bookings.reschedule_check_in') ?></label>
                        <input type="date" id="reschedule_checkin" name="check_in" class="vb-input"
                               value="<?= date('Y-m-d', strtotime($booking['start_datetime'])) ?>"
                               min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="vb-form-group">
                        <label for="reschedule_checkout" class="vb-label"><?= __('admin.bookings.reschedule_check_out') ?></label>
                        <input type="date" id="reschedule_checkout" name="check_out" class="vb-input"
                               value="<?= date('Y-m-d', strtotime($booking['end_datetime'])) ?>"
                               min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                    </div>
                </div>

                <?php elseif ($bookingPattern === 'capacity'): ?>
                <div class="vb-modal-form-row">
                    <div class="vb-form-group">
                        <label for="reschedule_cap_date" class="vb-label"><?= __('admin.bookings.reschedule_new_date') ?></label>
                        <input type="date" id="reschedule_cap_date" name="date" class="vb-input"
                               value="<?= date('Y-m-d', strtotime($booking['start_datetime'])) ?>"
                               min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="vb-form-group">
                        <label for="reschedule_slot" class="vb-label"><?= __('admin.bookings.reschedule_slot') ?></label>
                        <select id="reschedule_slot" name="slot_id" class="vb-select" required>
                            <?php
                            $capSlots = \App\Engine\Database::query(
                                'SELECT `id`, `start_time`, `end_time`, `label`, `day_of_week` FROM `capacity_slots` WHERE `tenant_id` = ? AND `is_active` = 1 ORDER BY `day_of_week`, `start_time`',
                                [$booking['tenant_id']]
                            );
                            foreach ($capSlots as $cs):
                                $slotLabel = $cs['label'] ?: (substr($cs['start_time'], 0, 5) . ' – ' . substr($cs['end_time'], 0, 5));
                                $dayNames = [__('admin.availability.day_mon'), __('admin.availability.day_tue'), __('admin.availability.day_wed'), __('admin.availability.day_thu'), __('admin.availability.day_fri'), __('admin.availability.day_sat'), __('admin.availability.day_sun')];
                                $dayLabel = $dayNames[(int) $cs['day_of_week']] ?? '';
                            ?>
                                <option value="<?= htmlspecialchars($cs['id'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-dow="<?= (int) $cs['day_of_week'] ?>">
                                    <?= htmlspecialchars("{$dayLabel} {$slotLabel}", ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php elseif ($bookingPattern === 'event'): ?>
                <input type="hidden" name="event_id" value="<?= htmlspecialchars($booking['event_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <div class="vb-modal-form-row">
                    <div class="vb-form-group">
                        <label for="reschedule_evt_date" class="vb-label"><?= __('admin.bookings.reschedule_new_date') ?></label>
                        <input type="date" id="reschedule_evt_date" name="date" class="vb-input"
                               value="<?= date('Y-m-d', strtotime($booking['start_datetime'])) ?>"
                               min="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <?php endif; ?>
            </form>
        </div>
        <div class="vb-modal-footer">
            <button type="button" class="vb-btn vb-btn-secondary" id="btn-reschedule-cancel">
                <?= __('admin.common.cancel') ?? 'Cancel' ?>
            </button>
            <button type="submit" form="reschedule-form" class="vb-btn vb-btn-primary">
                <i data-lucide="calendar-clock"></i>
                <?= __('admin.bookings.btn_reschedule') ?>
            </button>
        </div>
    </div>
</div>
<script>
(function() {
    var openBtn = document.getElementById('btn-reschedule-open');
    var closeBtn = document.getElementById('btn-reschedule-close');
    var cancelBtn = document.getElementById('btn-reschedule-cancel');
    var modal = document.getElementById('reschedule-modal');
    if (!openBtn || !modal) return;

    function openModal() {
        modal.style.display = 'flex';
        // Re-trigger entrance animations
        var dialog = modal.querySelector('.vb-modal');
        if (dialog) {
            dialog.style.animation = 'none';
            dialog.offsetHeight; // force reflow
            dialog.style.animation = '';
        }
        modal.style.animation = 'none';
        modal.offsetHeight;
        modal.style.animation = '';
        // Render Lucide icons inside the modal (they are outside .vb-content)
        if (window.lucide) window.lucide.createIcons({ nodes: [modal] });
    }
    function closeModal() { modal.style.display = 'none'; }

    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
    });
})();
</script>
<?php
    $modals = ob_get_clean();
endif;

include dirname(__DIR__, 2) . '/admin/layout.php';
