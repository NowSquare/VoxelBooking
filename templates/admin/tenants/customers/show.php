<?php
/**
 * Customer detail — premium contact workspace.
 *
 * Architecture:
 *   Back-link (slim breadcrumb, no duplicate title)
 *   Contact hero (.vb-contact-hero):
 *     Left:  avatar + name + meta (email, phone)
 *     Right: inline metrics (bookings, last visit, joined)
 *   Notes card (if present)
 *   Booking history section + table
 *
 * The name appears once — inside the hero. The page header is reduced to
 * a back-link only, eliminating the identity duplication.
 *
 * Variables: $tenant, $customer, $bookings, $liveBookingCount, $liveLastBookingAt, $tenantId, $csrfToken
 */
$tenant = $tenant ?? [];
$customer = $customer ?? [];
$bookings = $bookings ?? [];
$liveBookingCount = $liveBookingCount ?? 0;
$liveLastBookingAt = $liveLastBookingAt ?? null;
$tenantId = $tenantId ?? '';
$baseUrl = "/admin/tenants/" . htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8');

ob_start();
?>

<!-- Back-link only — no duplicate title -->
<div class="vb-page-header vb-page-header--slim">
    <a href="<?= $baseUrl ?>/customers" class="vb-back-link">
        <i data-lucide="chevron-left"></i>
        <?= __('admin.customers.page_title') ?>
    </a>
</div>

<!-- Contact summary: unified CRM hero — identity + integrated metrics -->
<div class="vb-contact-summary vb-animate-in">
    <div class="vb-contact-summary-identity">
        <span class="vb-avatar vb-avatar-xl"><?= mb_strtoupper(mb_substr($customer['name'], 0, 1)) ?></span>
        <div class="vb-contact-summary-info">
            <h2 class="vb-contact-summary-name"><?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="vb-profile-meta-row">
                <span class="vb-profile-meta-item">
                    <i data-lucide="mail"></i>
                    <?= htmlspecialchars($customer['email'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php if ($customer['phone']): ?>
                <span class="vb-profile-meta-item">
                    <i data-lucide="phone"></i>
                    <?= htmlspecialchars($customer['phone'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="vb-contact-summary-stats">
        <div class="vb-contact-stat vb-contact-stat--primary">
            <div class="vb-contact-stat-value"><?= $liveBookingCount ?></div>
            <div class="vb-contact-stat-label"><?= __('admin.customers.stat_total_bookings') ?></div>
        </div>
        <div class="vb-contact-stat">
            <div class="vb-contact-stat-label"><?= __('admin.customers.stat_last_booking') ?></div>
            <div class="vb-contact-stat-value vb-contact-stat-value--date">
                <?php if ($liveLastBookingAt): ?>
                    <?= htmlspecialchars(\App\Engine\Locale::dateLong(new DateTimeImmutable($liveLastBookingAt)), ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                    <span class="vb-text-tertiary">—</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="vb-contact-stat">
            <div class="vb-contact-stat-label"><?= __('admin.customers.stat_joined') ?></div>
            <div class="vb-contact-stat-value vb-contact-stat-value--date">
                <?= htmlspecialchars(\App\Engine\Locale::dateLong(new DateTimeImmutable($customer['created_at'])), ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
    </div>
</div>

<!-- Notes -->
<?php if ($customer['notes']): ?>
<div class="vb-card vb-mb-lg vb-animate-in">
    <div class="vb-card-header">
        <div class="vb-card-title-row">
            <i data-lucide="notebook-pen" class="vb-card-icon"></i>
            <div>
                <div class="vb-card-title"><?= __('admin.customers.notes_title') ?></div>
                <div class="vb-card-desc"><?= __('admin.customers.notes_desc') ?></div>
            </div>
        </div>
    </div>
    <div class="vb-card-body">
        <p class="vb-text-secondary vb-pre-wrap"><?= htmlspecialchars($customer['notes'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</div>
<?php endif; ?>

<!-- Booking history -->
<div class="vb-section-header">
    <h3 class="vb-section-title"><?= __('admin.customers.booking_history') ?></h3>
    <?php if (!empty($bookings)): ?>
        <span class="vb-badge vb-badge-neutral"><?= count($bookings) ?></span>
    <?php endif; ?>
</div>

<?php if (empty($bookings)): ?>
    <div class="vb-card vb-animate-in">
        <div class="vb-empty-state vb-empty-state--compact">
            <i data-lucide="calendar-x" class="vb-empty-icon"></i>
            <h3><?= __('admin.customers.no_bookings_title') ?></h3>
            <p><?= __('admin.customers.no_bookings_desc') ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="vb-table-container vb-animate-in">
        <div class="vb-table-wrap">
            <table class="vb-table" id="customer-bookings-table">
                <thead>
                    <tr>
                        <th><?= __('admin.customers.bh_date') ?></th>
                        <th><?= __('admin.customers.bh_time') ?></th>
                        <th><?= __('admin.customers.bh_service') ?></th>
                        <th><?= __('admin.customers.bh_status') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $i => $b): ?>
                    <?php
                    $startDt = (new DateTimeImmutable($b['start_datetime'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tenant['timezone']??'UTC'));
                    $endDt   = (new DateTimeImmutable($b['end_datetime'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tenant['timezone']??'UTC'));
                    $detailUrl = $baseUrl . '/bookings/' . htmlspecialchars($b['id'], ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr class="vb-fade-in-up vb-clickable-row stagger-<?= min($i + 1, 6) ?>"
                        data-href="<?= $detailUrl ?>">
                        <td>
                            <?= htmlspecialchars(\App\Engine\Locale::dateLong($startDt), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="vb-text-secondary">
                            <?= htmlspecialchars(\App\Engine\Locale::time($startDt), ENT_QUOTES, 'UTF-8') ?>
                            –
                            <?= htmlspecialchars(\App\Engine\Locale::time($endDt), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <?php $histLabel = booking_display_label($b); ?>
                            <?php if ($histLabel !== '—'): ?>
                                <?= htmlspecialchars($histLabel, ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                <span class="vb-text-tertiary">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="vb-status vb-status-<?= htmlspecialchars($b['status'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= __('admin.bookings.status_' . $b['status']) ?>
                            </span>
                        </td>
                        <td class="vb-text-right">
                            <a href="<?= $detailUrl ?>" class="vb-btn vb-btn-ghost vb-btn-sm" title="<?= __('admin.bookings.view') ?>">
                                <i data-lucide="eye" class="vb-icon-sm"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php if(!empty($nextHistoryCursor)): ?>
<a class="vb-btn vb-btn-secondary" href="?cursor=<?= e(rawurlencode($nextHistoryCursor)) ?>"><?= e(__('agent.pagination.next')) ?></a>
<?php endif; ?>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
