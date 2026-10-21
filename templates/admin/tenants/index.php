<?php
/**
 * Tenant list — operator-only view.
 *
 * Variables: $user, $version, $csrfToken, $tenants, $counts, $flash, $pageTitle, $activePage
 */
$activePage = 'tenants';

ob_start();
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../partials/alert.php'; ?>
<?php endif; ?>

<?php
ob_start();
?>
<?php
        $exportParams = array_filter([
            'search' => $filters['search'] ?? '',
            'status' => ($filters['status'] ?? '') !== 'all' ? ($filters['status'] ?? '') : '',
        ], fn($v) => $v !== '');
        $exportUrl = '/admin/tenants/export' . ($exportParams ? '?' . http_build_query($exportParams) : '');
        ?>
        <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>"
           class="vb-btn vb-btn-ghost vb-btn-sm" id="btn-export-csv"
           <?php if (\App\Engine\DemoMode::isActive()): ?>onclick="event.preventDefault(); showDemoToast()"<?php endif; ?>>
            <i data-lucide="download" class="vb-icon-sm"></i>
            <?= __('admin.common.export_csv') ?>
        </a>
        <a href="/admin/tenants/create" class="vb-btn vb-btn-primary">
            <i data-lucide="plus"></i>
            <?= __('admin.tenants.create') ?>
        </a>
<?php
$pageHeader = [
    'title' => __('admin.tenants.title'),
    'subtitle' => __('admin.tenants.subtitle') . ' · ' . __p('admin.tenants.showing_count', (int) ($counts['total'] ?? 0)),
    'actions' => ob_get_clean(),
];
include __DIR__ . '/../partials/page-header.php';
?>

<?php
$hasActiveFilters = !empty($filters['search'] ?? '') || (!empty($filters['status'] ?? '') && ($filters['status'] ?? '') !== 'all');
$currentStatus = ($filters['status'] ?? '') ?: 'all';
$currentSearch = $filters['search'] ?? '';
?>

<?php if (empty($tenants) && !$hasActiveFilters): ?>
    <div class="vb-empty vb-animate-in">
        <i data-lucide="building-2" class="vb-empty-icon"></i>
        <div class="vb-empty-title"><?= __('admin.tenants.empty_title') ?></div>
        <div class="vb-empty-desc"><?= __('admin.tenants.empty_desc') ?></div>
        <a href="/admin/tenants/create" class="vb-btn vb-btn-primary vb-btn-lg">
            <i data-lucide="plus"></i>
            <?= __('admin.tenants.create') ?>
        </a>
    </div>
<?php else: ?>
    <div class="vb-table-container">
        <div class="vb-table-toolbar">
            <?php
            $searchAction = '/admin/tenants';
            $searchValue = $currentSearch;
            $searchPlaceholder = __('admin.tenants.search_placeholder');
            $searchHiddenFields = ($currentStatus && $currentStatus !== 'all') ? ['status' => $currentStatus] : [];
            include dirname(__DIR__, 2) . '/partials/table-search.php';
            ?>
            <div class="vb-table-toolbar-right">
                <div class="vb-filter-tabs">
                    <?php
                    $statusFilters = [
                        'all'      => ['label' => __('admin.common.all'),     'count' => $counts['total'] ?? 0],
                        'active'   => ['label' => __('admin.tenants.status_active'),   'count' => $counts['active'] ?? 0],
                        'paused'   => ['label' => __('admin.tenants.status_paused'),   'count' => $counts['paused'] ?? 0],
                        'archived' => ['label' => __('admin.tenants.status_archived'), 'count' => $counts['archived'] ?? 0],
                    ];
                    foreach ($statusFilters as $key => $filter):
                        $tabParams = [];
                        if ($key !== 'all') $tabParams['status'] = $key;
                        if ($currentSearch !== '') $tabParams['search'] = $currentSearch;
                        $tabQs = $tabParams ? '?' . http_build_query($tabParams) : '';
                    ?>
                    <a href="/admin/tenants<?= htmlspecialchars($tabQs, ENT_QUOTES, 'UTF-8') ?>"
                       class="vb-filter-tab <?= $currentStatus === $key ? 'active' : '' ?>">
                        <?= $filter['label'] ?>
                        <span class="vb-filter-tab-count"><?= $filter['count'] ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        $resultCountKey = 'admin.tenants.showing_count';
        $resultCountValue = count($tenants);
        $resultCountActive = $hasActiveFilters;
        include dirname(__DIR__, 2) . '/partials/table-result-count.php';
        ?>
        <?php if (empty($tenants)): ?>
        <div class="vb-empty vb-animate-in">
            <i data-lucide="search-x" class="vb-empty-icon"></i>
            <div class="vb-empty-title"><?= __('admin.tenants.empty_filter_title') ?></div>
            <div class="vb-empty-desc"><?= __('admin.tenants.empty_filter_desc') ?></div>
        </div>
        <?php else: ?>
        <div class="vb-table-wrap">
            <table class="vb-table">
                <thead>
                    <tr>
                        <th><?= __('admin.tenants.name') ?></th>
                        <th><?= __('admin.tenants.pattern') ?></th>
                        <th><?= __('admin.tenants.status') ?></th>
                        <th class="vb-text-center"><?= __('admin.tenants.bookings') ?></th>
                        <th class="vb-text-center"><?= __('admin.tenants.services') ?></th>
                        <th class="vb-text-right"><?= __('admin.tenants.actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tenants as $i => $tenant): ?>
                    <tr class="vb-fade-in-up stagger-<?= min($i + 1, 6) ?>">
                        <td data-label="<?= __('admin.tenants.name') ?>">
                            <a class="vb-cell-name vb-business-name" href="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="vb-color-dot" style="background: <?= htmlspecialchars($tenant['brand_color'] ?? '#2563EB', ENT_QUOTES, 'UTF-8') ?>"></span>
                                <?= htmlspecialchars($tenant['name'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <div class="vb-cell-detail">/book/<?= htmlspecialchars($tenant['slug'], ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td data-label="<?= __('admin.tenants.pattern') ?>">
                            <span class="vb-badge vb-badge-default"><?= htmlspecialchars(ucfirst($tenant['booking_pattern'] ?? 'timeslot'), ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td data-label="<?= __('admin.tenants.status') ?>">
                            <span class="vb-status vb-status-<?= htmlspecialchars($tenant['status'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= __('admin.tenants.status_' . $tenant['status']) ?>
                            </span>
                        </td>
                        <td class="vb-text-center" data-label="<?= __('admin.tenants.bookings') ?>"><?= (int) $tenant['booking_count'] ?></td>
                        <td class="vb-text-center" data-label="<?= __('admin.tenants.services') ?>"><?= (int) $tenant['service_count'] ?></td>
                        <td class="vb-text-right" data-label="<?= __('admin.tenants.actions') ?>">
                            <div class="vb-business-links">
                                <a href="/book/<?= htmlspecialchars($tenant['slug'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="vb-btn vb-btn-ghost vb-btn-sm">
                                    <?= __('admin.tenants.view_booking_page') ?><i data-lucide="external-link"></i>
                                </a>
                                <details class="vb-row-actions" data-row-actions>
                                    <summary class="vb-btn vb-btn-ghost vb-btn-sm" id="tenant-actions-<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>"
                                             aria-haspopup="menu" aria-expanded="false" aria-controls="tenant-actions-menu-<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <span aria-hidden="true"><?= __('admin.common.actions') ?></span>
                                        <span class="sr-only"><?= htmlspecialchars(__('admin.tenants.more_actions', ['name' => $tenant['name']]), ENT_QUOTES, 'UTF-8') ?></span>
                                        <i data-lucide="chevron-down" aria-hidden="true"></i>
                                    </summary>
                                    <div class="vb-row-actions-menu" id="tenant-actions-menu-<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>"
                                         role="menu" aria-labelledby="tenant-actions-<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>" hidden>
                                        <a href="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/edit"><i data-lucide="pencil" aria-hidden="true"></i><?= __('admin.common.edit') ?></a>
                                        <button type="button" class="vb-copy-btn" @click="copyBookingUrl" data-copy-url="<?= htmlspecialchars(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/book/' . $tenant['slug'], ENT_QUOTES, 'UTF-8') ?>">
                                            <span class="vb-copy-icon"><i data-lucide="copy" aria-hidden="true"></i></span><span class="vb-copy-check"><i data-lucide="check" aria-hidden="true"></i></span><?= __('admin.common.copy_booking_url') ?>
                                        </button>
                                        <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/impersonate">
                                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_record_version" value="<?= e($tenant['version'] ?? '') ?>">
                                            <button type="submit"><i data-lucide="eye" aria-hidden="true"></i><?= __('admin.impersonation.start') ?></button>
                                        </form>
                                        <?php if ($tenant['status'] !== 'archived'): ?>
                                        <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/archive" class="vb-business-archive"
                                              data-confirm="<?= htmlspecialchars(__('admin.tenants.archive_confirm', ['name' => $tenant['name']]), ENT_QUOTES, 'UTF-8') ?>" data-confirm-text="<?= __('admin.tenants.archive') ?>">
                                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_record_version" value="<?= e($tenant['version'] ?? '') ?>">
                                            <button type="submit"><i data-lucide="archive" aria-hidden="true"></i><?= __('admin.tenants.archive') ?></button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/activate">
                                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_record_version" value="<?= e($tenant['version'] ?? '') ?>">
                                            <button type="submit"><i data-lucide="rotate-ccw" aria-hidden="true"></i><?= __('admin.tenants.activate') ?></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 2) . '/admin/layout.php';
