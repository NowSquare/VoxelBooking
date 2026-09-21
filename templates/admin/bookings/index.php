<?php
/**
 * Bookings list — shared template for operator and tenant-context views.
 *
 * Variables: $user, $version, $csrfToken, $bookings, $total, $page, $perPage,
 *            $showTenantColumn, $filters, $flash, $pageTitle, $activePage, $backUrl,
 *            $sort, $direction
 *            Optional: $tenantId
 */
$activePage = 'bookings';
$totalPages = max(1, (int) ceil($total / $perPage));

$filterParams = http_build_query(array_filter($filters ?? [], fn($v) => $v !== null && $v !== ''));
$baseUrl = $backUrl ?? '/admin/bookings';

// Shared sort header helper
include __DIR__ . '/../../partials/table-sort-header.php';

ob_start();
?>

<?php if ($flash ?? null): ?>
    <?php include __DIR__ . '/../../partials/alert.php'; ?>
<?php endif; ?>

<div class="vb-page-header">
    <div>
        <h2 class="vb-page-title"><?= __('admin.bookings.title') ?></h2>
        <p class="vb-page-subtitle">
            <?= $showTenantColumn ? __('admin.bookings.subtitle') : __('admin.bookings.subtitle_tenant') ?>
            · <?= __p('admin.bookings.showing_count', (int) $total) ?>
        </p>
    </div>
    <div class="vb-page-actions">
        <?php
        $exportUrl = $baseUrl . '/export' . ($filterParams ? '?' . $filterParams : '');
        ?>
        <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>"
           class="vb-btn vb-btn-ghost vb-btn-sm" id="btn-export-csv"
           <?php if (\App\Engine\DemoMode::isActive()): ?>onclick="event.preventDefault(); showDemoToast()"<?php endif; ?>>
            <i data-lucide="download" class="vb-icon-sm"></i>
            <?= __('admin.bookings.export_csv') ?>
        </a>
        <?php if (isset($tenantId) && $tenantId): ?>
        <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/bookings/create"
           class="vb-btn vb-btn-primary vb-btn-sm" id="btn-new-booking">
            <i data-lucide="plus" class="vb-icon-sm"></i>
            <?= __('admin.bookings.new_booking') ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
// Detect whether any filter is active (status, date range, or search term).
// When filters are active and return zero rows, we must still render the
// toolbar so the user can clear or change their filters.
$hasActiveFilters = !empty($filters['status'] ?? '') || !empty($filters['from'] ?? '') || !empty($filters['to'] ?? '') || !empty($filters['search'] ?? '');
?>

<?php if (empty($bookings) && !$hasActiveFilters): ?>
    <div class="vb-empty vb-animate-in">
        <i data-lucide="calendar" class="vb-empty-icon"></i>
        <div class="vb-empty-title"><?= __('admin.bookings.empty_title') ?></div>
        <div class="vb-empty-desc"><?= __('admin.bookings.empty_desc') ?></div>
    </div>
<?php else: ?>
    <div class="vb-table-container">
        <div class="vb-table-toolbar">
            <div class="vb-filter-tabs">
                <?php
                $pillFilters = array_diff_key($filters ?? [], ['status' => '', 'page' => '']);
                $pillBase = array_filter($pillFilters, fn($v) => $v !== null && $v !== '');
                $statusTabs = ['', 'confirmed', 'pending', 'waitlisted', 'cancelled', 'completed', 'no_show', 'rescheduled'];
                $statusLabels = [
                    ''            => __('admin.bookings.filter_all'),
                    'confirmed'   => __('admin.bookings.status_confirmed'),
                    'pending'     => __('admin.bookings.status_pending'),
                    'waitlisted'  => __('admin.bookings.status_waitlisted'),
                    'cancelled'   => __('admin.bookings.status_cancelled'),
                    'completed'   => __('admin.bookings.status_completed'),
                    'no_show'     => __('admin.bookings.status_no_show'),
                    'rescheduled' => __('admin.bookings.status_rescheduled'),
                ];
                foreach ($statusTabs as $s):
                    $tabQs = $s ? http_build_query(array_filter(array_merge($pillBase, ['status' => $s, 'page' => 1]), fn($v) => $v !== null && $v !== '')) : ($pillBase ? http_build_query($pillBase) : '');
                ?>
                <a href="<?= htmlspecialchars($baseUrl . ($tabQs ? '?' . $tabQs : ''), ENT_QUOTES, 'UTF-8') ?>"
                   class="vb-filter-tab <?= ($filters['status'] ?? '') === $s ? 'active' : '' ?>">
                    <?= $statusLabels[$s] ?>
                </a>
                <?php endforeach; ?>
            </div>
            <div class="vb-table-toolbar-right">
                <form method="GET" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>" class="vb-table-toolbar-filters">
                    <?php if (!empty($filters['status'])): ?>
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <input type="date" name="from" class="vb-input vb-input-sm"
                           value="<?= htmlspecialchars($filters['from'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           title="<?= __('admin.bookings.filter_from') ?>">
                    <input type="date" name="to" class="vb-input vb-input-sm"
                           value="<?= htmlspecialchars($filters['to'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           title="<?= __('admin.bookings.filter_to') ?>">
                    <?php if ($showTenantColumn): ?>
                    <input type="text" name="search" class="vb-input vb-input-sm"
                           placeholder="<?= __('admin.bookings.filter_search') ?>"
                           value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <button type="submit" class="vb-btn vb-btn-primary vb-btn-sm">
                        <i data-lucide="filter"></i>
                    </button>
                </form>
            </div>
        </div>
        <?php
        $resultCountKey = 'admin.bookings.showing_count';
        $resultCountValue = count($bookings);
        $resultCountActive = $hasActiveFilters;
        include __DIR__ . '/../../partials/table-result-count.php';
        ?>
        <?php if (empty($bookings)): ?>
        <div class="vb-empty vb-animate-in">
            <i data-lucide="search-x" class="vb-empty-icon"></i>
            <div class="vb-empty-title"><?= __('admin.bookings.empty_filter_title') ?></div>
            <div class="vb-empty-desc"><?= __('admin.bookings.empty_filter_desc') ?></div>
        </div>
        <?php else: ?>
        <div class="vb-table-wrap">
            <table class="vb-table">
                <thead>
                    <tr>
                        <th><?= tableSortHeader('customer', __('admin.bookings.customer'), $sort, $direction, $baseUrl, $filters) ?></th>
                        <th><?= tableSortHeader('service', __('admin.bookings.service'), $sort, $direction, $baseUrl, $filters) ?></th>
                        <th><?= tableSortHeader('start_datetime', __('admin.bookings.date_time'), $sort, $direction, $baseUrl, $filters) ?></th>
                        <th><?= tableSortHeader('status', __('admin.bookings.status'), $sort, $direction, $baseUrl, $filters) ?></th>
                        <?php if ($showTenantColumn): ?>
                        <th><?= tableSortHeader('tenant', __('admin.bookings.tenant'), $sort, $direction, $baseUrl, $filters) ?></th>
                        <?php endif; ?>
                        <th><?= __('admin.bookings.actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $i => $b): ?>
                    <tr class="vb-fade-in-up stagger-<?= min($i + 1, 6) ?>">
                        <td data-label="<?= __('admin.bookings.customer') ?>">
                            <?php if (!empty($b['customer_id']) && !empty($b['tenant_id']) && ($showTenantColumn ?? false)): ?>
                            <?php // Operator context: start impersonation before navigating to customer ?>
                            <form method="POST" action="/admin/tenants/<?= htmlspecialchars($b['tenant_id'], ENT_QUOTES, 'UTF-8') ?>/impersonate" class="vb-inline-form">
                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="redirect_to" value="/admin/tenants/<?= htmlspecialchars($b['tenant_id'], ENT_QUOTES, 'UTF-8') ?>/customers/<?= htmlspecialchars($b['customer_id'], ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="vb-cell-link vb-btn-reset">
                                    <div class="vb-cell-primary"><?= htmlspecialchars($b['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="vb-cell-secondary"><?= htmlspecialchars($b['customer_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                </button>
                            </form>
                            <?php elseif (!empty($b['customer_id']) && !empty($b['tenant_id'])): ?>
                            <?php // Tenant-scoped: direct link (already within tenant context) ?>
                            <a href="/admin/tenants/<?= htmlspecialchars($b['tenant_id'], ENT_QUOTES, 'UTF-8') ?>/customers/<?= htmlspecialchars($b['customer_id'], ENT_QUOTES, 'UTF-8') ?>" class="vb-cell-link">
                                <div class="vb-cell-primary"><?= htmlspecialchars($b['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="vb-cell-secondary"><?= htmlspecialchars($b['customer_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                            </a>
                            <?php else: ?>
                            <div class="vb-cell-primary"><?= htmlspecialchars($b['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="vb-cell-secondary"><?= htmlspecialchars($b['customer_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="<?= __('admin.bookings.service') ?>">
                            <div class="vb-cell-primary"><?= htmlspecialchars(booking_display_label($b), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php
                            $rowPattern = $b['booking_pattern'] ?? 'timeslot';
                            $patternLabel = __('admin.bookings.type_' . $rowPattern);
                            $patternIcon = match ($rowPattern) {
                                'resource' => 'bed',
                                'capacity' => 'users',
                                'event'    => 'ticket',
                                default    => 'clock',
                            };
                            ?>
                            <div class="vb-cell-secondary vb-pattern-label">
                                <i data-lucide="<?= $patternIcon ?>" class="vb-pattern-icon"></i>
                                <?= htmlspecialchars($patternLabel, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </td>
                        <td data-label="<?= __('admin.bookings.date_time') ?>">
                            <div class="vb-cell-primary"><?= \App\Engine\Locale::date(new \DateTimeImmutable($b['start_datetime'])) ?></div>
                            <div class="vb-cell-secondary">
                                <?= date('H:i', strtotime($b['start_datetime'])) ?> – <?= date('H:i', strtotime($b['end_datetime'])) ?>
                            </div>
                        </td>
                        <td data-label="<?= __('admin.bookings.status') ?>">
                            <span class="vb-status vb-status-<?= htmlspecialchars($b['status'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= __('admin.bookings.status_' . $b['status']) ?>
                            </span>
                        </td>
                        <?php if ($showTenantColumn): ?>
                        <td data-label="<?= __('admin.bookings.tenant') ?>">
                            <span class="vb-text-muted"><?= htmlspecialchars($b['tenant_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <?php endif; ?>
                        <td data-label="<?= __('admin.bookings.actions') ?>">
                            <div class="vb-action-group">
                                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= htmlspecialchars($b['id'], ENT_QUOTES, 'UTF-8') ?>"
                                   class="vb-btn vb-btn-ghost vb-btn-sm"
                                   title="<?= __('admin.bookings.view') ?>">
                                    <i data-lucide="eye"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        $paginationPage = $page;
        $paginationTotalPages = $totalPages;
        $paginationBaseUrl = $baseUrl;
        $paginationParams = $filterParams ? '&' . $filterParams : '';
        $paginationI18nPrefix = 'admin.bookings';
        include __DIR__ . '/../../partials/table-pagination.php';
        ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 2) . '/admin/layout.php';
