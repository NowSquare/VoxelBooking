<?php
/**
 * Events list — admin management page for event-pattern tenants.
 *
 * Variables: $user, $version, $csrfToken, $tenant, $tenantId, $events, $flash
 */
$activePage = 'events';
ob_start();
?>

<?php if ($flash ?? null): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<div class="vb-page-header">
    <div>
        <h2 class="vb-page-title">
            <i data-lucide="ticket" class="vb-page-header-icon"></i>
            <?= __('admin.events.title') ?>
        </h2>
    </div>
    <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/events/create"
       class="vb-btn vb-btn-primary vb-btn-sm" id="btn-new-event">
        <i data-lucide="plus" class="vb-icon-sm"></i>
        <?= __('admin.events.new_event') ?>
    </a>
</div>

<?php if (empty($events)): ?>
    <div class="vb-empty vb-animate-in">
        <i data-lucide="ticket" class="vb-empty-icon"></i>
        <div class="vb-empty-title"><?= __('admin.events.empty_title') ?></div>
        <div class="vb-empty-desc"><?= __('admin.events.empty_desc') ?></div>
    </div>
<?php else: ?>
    <div class="vb-table-container">
        <div class="vb-table-wrap">
            <table class="vb-table">
                <thead>
                    <tr>
                        <th><?= __('admin.events.name_label') ?></th>
                        <th><?= __('admin.events.start_datetime_label') ?></th>
                        <th><?= __('admin.events.attendees') ?></th>
                        <th><?= __('admin.bookings.status') ?></th>
                        <th><?= __('admin.bookings.actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $i => $event): ?>
                    <tr class="vb-fade-in-up stagger-<?= min($i + 1, 6) ?>">
                        <td>
                            <div class="vb-cell-primary"><?= htmlspecialchars($event['name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if ($event['location']): ?>
                                <div class="vb-cell-secondary"><?= htmlspecialchars($event['location'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <?php if ((int) $event['is_recurring']): ?>
                                <span class="vb-badge vb-badge-info vb-badge-xs">
                                    <i data-lucide="refresh-cw" class="vb-icon-xs"></i>
                                    <?= htmlspecialchars($event['rrule'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="vb-cell-primary"><?= \App\Engine\Locale::date(new \DateTimeImmutable($event['start_datetime'])) ?></div>
                            <div class="vb-cell-secondary">
                                <?= date('H:i', strtotime($event['start_datetime'])) ?> – <?= date('H:i', strtotime($event['end_datetime'])) ?>
                            </div>
                        </td>
                        <td>
                            <div class="vb-cell-primary">
                                <?= str_replace([':count', ':max'], [(string) (int) $event['booked_count'], (string) (int) $event['max_participants']], __('admin.events.participants_label')) ?>
                            </div>
                            <?php if ((int) $event['waitlist_count'] > 0): ?>
                                <div class="vb-cell-secondary vb-text-accent">
                                    <?= str_replace(':count', (string) (int) $event['waitlist_count'], __('admin.events.waitlisted_label')) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $event['is_active']): ?>
                                <span class="vb-badge vb-badge-success"><?= __('admin.events.status_active') ?></span>
                            <?php else: ?>
                                <span class="vb-badge vb-badge-default"><?= __('admin.events.status_inactive') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="vb-action-group">
                                <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/events/<?= htmlspecialchars($event['id'], ENT_QUOTES, 'UTF-8') ?>/edit"
                                   class="vb-btn vb-btn-ghost vb-btn-sm" title="<?= __('admin.events.edit_title') ?>">
                                    <i data-lucide="pencil"></i>
                                </a>
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/events/<?= htmlspecialchars($event['id'], ENT_QUOTES, 'UTF-8') ?>/toggle"
                                      class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm"
                                            title="<?= (int) $event['is_active'] ? __('admin.events.flash_deactivated') : __('admin.events.flash_activated') ?>">
                                        <?php if ((int) $event['is_active']): ?>
                                            <i data-lucide="eye-off"></i>
                                        <?php else: ?>
                                            <i data-lucide="eye"></i>
                                        <?php endif; ?>
                                    </button>
                                </form>
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/events/<?= htmlspecialchars($event['id'], ENT_QUOTES, 'UTF-8') ?>/delete"
                                      class="vb-form-flush"
                                      data-confirm="<?= __('admin.events.confirm_delete') ?>" data-confirm-text="<?= __('admin.settings.confirm_button') ?? 'Delete' ?>">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm vb-btn-destructive"
                                            title="<?= __('admin.events.confirm_delete') ?>">
                                        <i data-lucide="trash-2"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
