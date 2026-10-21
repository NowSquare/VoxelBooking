<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Agent\Operations;
use App\Agent\SessionActor;
use App\Application\Media\MediaService;
use App\Engine\FormState;
use App\Engine\Request;
use App\Engine\Response;

/** Browser transport: form parsing, upload grants, feedback and redirects. */
final class CatalogUi
{
    public static function actor(): ActorContext
    {
        return SessionActor::resolve(Operations::registry());
    }

    public static function version(string $kind, string $businessId, string $id): string
    {
        return CatalogService::get(self::actor(), $kind, ['business_id' => $businessId, 'id' => $id])['version'];
    }

    public static function versions(string $kind, string $businessId, array $rows): array
    {
        $actor = self::actor();
        $versions = [];
        foreach ($rows as $row) {
            $versions[$row['id']] = CatalogService::get($actor, $kind, ['business_id' => $businessId, 'id' => $row['id']])['version'];
        }
        return $versions;
    }

    public static function scheduleVersion(string $businessId, ?string $staffId): string
    {
        return CatalogService::getSchedule(self::actor(), ['business_id' => $businessId, 'staff_id' => $staffId])['version'];
    }

    public static function mutate(Request $request, string $kind, string $action): Response
    {
        $businessId = (string) $request->getAttribute('tenant_id');
        $id = (string) ($request->getAttribute('id') ?? '');
        $section = str_replace('_', '-', $kind);
        $base = "/admin/tenants/{$businessId}/{$section}";
        $form = in_array($kind, ['capacity_slots', 'blocked_dates'], true) ? $base : ($action === 'create' ? $base . '/create' : $base . '/' . $id . '/edit');
        try {
            $actor = self::actor();
            $input = ['business_id' => $businessId];
            $existing = null;
            if ($action !== 'create') {
                $existing = CatalogService::get($actor, $kind, ['business_id' => $businessId, 'id' => $id]);
                $input['id'] = $id;
                $input['expected_version'] = $request->string('_record_version');
            }
            if ($action === 'create' || $action === 'update') {
                $input += self::formInput($request, $kind, $businessId, $action === 'create');
                $uploadField = $kind === 'staff' ? 'avatar' : 'cover_image';
                if (!empty($_FILES[$uploadField]['tmp_name'])) {
                    $input['media_id'] = MediaService::storeBrowser($actor, $businessId, CatalogFields::definition($kind)['entity'], $_FILES[$uploadField]);
                }
                $input['remove_image'] = !empty($_POST[$kind === 'staff' ? 'remove_avatar' : 'remove_cover_image']);
            }
            if ($action === 'activate' || $action === 'deactivate' || $action === 'toggle') {
                $input['is_active'] = $action === 'toggle' ? !$existing['is_active'] : $action === 'activate';
                $result = CatalogService::setActive($actor, $kind, $input);
                $action = $result['is_active'] ? 'activated' : 'deactivated';
            } elseif ($action === 'reorder') {
                $input['direction'] = $request->string('direction');
                CatalogService::reorder($actor, $kind, $input);
            } else {
                $result = match ($action) {
                    'create' => CatalogService::create($actor, $kind, $input),
                    'update' => CatalogService::update($actor, $kind, $input),
                    'delete' => CatalogService::delete($actor, $kind, $input),
                    default => throw new \LogicException('Unknown catalog action.'),
                };
                $action = match ($action) { 'create' => 'created', 'update' => 'updated', 'delete' => 'deleted' };
            }
            FormState::clear();
            if ($action !== 'reorder') {
                $prefix = in_array($kind, ['capacity_slots', 'events'], true) ? 'flash_' : '';
                FormState::toast('success', __('admin.' . $kind . '.' . $prefix . $action));
            }
            return Response::redirect($base);
        } catch (OperationException $exception) {
            if (in_array($exception->status, [401, 403], true)) {
                return self::forbidden($request, $exception);
            }
            if ($exception->status === 404) {
                return Response::redirect($base);
            }
            $old = array_intersect_key($_POST, array_flip(array_merge(array_keys(CatalogFields::properties($kind)), ['start_date', 'start_time', 'end_date', 'end_time', 'is_recurring', 'frequency', 'count', 'seasonal_start', 'seasonal_end', 'seasonal_price', 'seasonal_label'])));
            FormState::flashInput($old);
            FormState::toast('error', self::errorMessage($kind, $exception));
            return Response::redirect(in_array($action, ['create', 'update'], true) ? $form : $base);
        }
    }

    public static function schedule(Request $request, string $action): Response
    {
        $businessId = (string) $request->getAttribute('tenant_id');
        $staffId = $request->getAttribute('id') ?: null;
        $base = "/admin/tenants/{$businessId}/availability";
        $redirect = $staffId !== null ? $base . '/staff/' . $staffId : $base;
        try {
            $actor = self::actor();
            $input = ['business_id' => $businessId, 'staff_id' => $staffId];
            $current = CatalogService::getSchedule($actor, $input);
            $input['expected_version'] = $request->string('_record_version');
            if ($action === 'reset') {
                CatalogService::resetSchedule($actor, $input);
                FormState::toast('success', __('admin.availability.staff_reset'));
            } else {
                $schedule = $_POST['schedule'] ?? [];
                if (!is_array($schedule)) {
                    CatalogFields::invalid('windows', 'invalid_list');
                }
                $input['windows'] = [];
                foreach ($schedule as $day => $windows) {
                    if (!is_array($windows)) {
                        CatalogFields::invalid('windows', 'invalid_list');
                    }
                    foreach ($windows as $window) {
                        if (!is_array($window)) {
                            CatalogFields::invalid('windows', 'invalid_list');
                        }
                        $start = trim((string) ($window['start'] ?? ''));
                        $end = trim((string) ($window['end'] ?? ''));
                        if ($start === '' && $end === '') {
                            continue;
                        }
                        $input['windows'][] = ['day_of_week' => (int) $day, 'start_time' => $start, 'end_time' => $end];
                    }
                }
                CatalogService::saveSchedule($actor, $input);
                FormState::toast('success', __($staffId === null ? 'admin.availability.saved' : 'admin.availability.staff_saved'));
            }
        } catch (OperationException $exception) {
            if (in_array($exception->status, [401, 403], true)) {
                return self::forbidden($request, $exception);
            }
            FormState::toast('error', __($exception->problemCode === 'version_conflict' ? 'agent.errors.version_conflict' : 'admin.availability.error_invalid_time'));
        }
        return Response::redirect($redirect);
    }

    private static function forbidden(Request $request, OperationException $exception): Response
    {
        if ($request->isJson()) {
            return Response::json($exception->problem(), $exception->status);
        }
        return \App\Engine\View::response('admin.errors.403', [
            'user' => \App\Engine\Auth::user(), 'version' => \App\Engine\Version::get(), 'pageTitle' => '403',
            'csrfToken' => \App\Middleware\CsrfMiddleware::generateToken(),
        ], $exception->status);
    }

    private static function formInput(Request $request, string $kind, string $businessId, bool $creating): array
    {
        $input = [];
        foreach (CatalogFields::properties($kind) as $field => $schema) {
            $type = (array) $schema['type'];
            if (in_array('array', $type, true) || in_array('object', $type, true)) {
                continue;
            }
            $raw = trim($request->string($field));
            if (in_array('boolean', $type, true)) {
                $input[$field] = $raw === '1';
            } elseif ($raw === '' && in_array('null', $type, true)) {
                $input[$field] = null;
            } elseif (in_array('integer', $type, true)) {
                if ($raw === '' && array_key_exists($field, CatalogFields::defaults($kind))) {
                    $input[$field] = CatalogFields::defaults($kind)[$field];
                } elseif (preg_match('/^-?\d+$/D', $raw)) {
                    $input[$field] = (int) $raw;
                } else {
                    CatalogFields::invalid($field, 'invalid_integer');
                }
            } else {
                $input[$field] = $raw;
            }
        }
        if ($kind === 'services' || $kind === 'staff') {
            $field = $kind === 'services' ? 'staff_ids' : 'service_ids';
            $input[$field] = array_values((array) ($_POST[$field] ?? []));
        }
        if ($kind === 'resources') {
            $input['amenities'] = array_values(array_filter(array_map('trim', explode(',', $request->string('amenities'))), static fn (string $value): bool => $value !== ''));
            foreach (['check_in_days', 'check_out_days'] as $field) {
                $input[$field] = array_values(array_unique(array_map('intval', (array) ($_POST[$field] ?? []))));
            }
            $input['seasonal_pricing'] = [];
            foreach ((array) ($_POST['seasonal_start'] ?? []) as $index => $start) {
                $end = trim((string) ($_POST['seasonal_end'][$index] ?? ''));
                $price = trim((string) ($_POST['seasonal_price'][$index] ?? ''));
                if (trim((string) $start) === '' && $end === '' && $price === '') {
                    continue;
                }
                $input['seasonal_pricing'][] = ['start_date' => trim((string) $start), 'end_date' => $end, 'price_per_night' => $price, 'label' => trim((string) ($_POST['seasonal_label'][$index] ?? '')) ?: null];
            }
        }
        if ($kind === 'events') {
            $tenant = \App\Engine\Database::query('SELECT timezone FROM tenants WHERE id = ?', [$businessId])[0] ?? throw new OperationException('not_found', 404);
            foreach (['start', 'end'] as $prefix) {
                $raw = $request->string($prefix . '_date') . ' ' . $request->string($prefix . '_time');
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $raw, new \DateTimeZone($tenant['timezone']));
                if (!$date || $date->format('Y-m-d H:i') !== $raw) {
                    CatalogFields::invalid($prefix . '_datetime', 'invalid_datetime');
                }
                $input[$prefix . '_datetime'] = $date->format(DATE_ATOM);
            }
            $input['recurrence'] = $request->string('is_recurring') === '1'
                ? ['frequency' => strtoupper($request->string('frequency')), 'count' => (int) $request->string('count')] : null;
            $input['exception_dates'] = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $request->string('exception_dates')))));
        }
        return $input;
    }

    private static function errorMessage(string $kind, OperationException $exception): string
    {
        $field = array_key_first($exception->fieldErrors);
        $suffix = match ($field) {
            'name' => 'error_name_required', 'duration_minutes' => 'error_duration_required',
            'price', 'price_per_night' => 'error_price_invalid', 'staff_ids' => 'error_invalid_staff',
            'email' => 'error_email_invalid', 'max_stay_nights' => 'error_stay_range',
            'seasonal_pricing' => 'error_seasonal_overlap', default => null,
        };
        if ($exception->problemCode === 'conflict' && $kind === 'staff') {
            $suffix = 'error_email_taken';
        }
        if ($exception->problemCode === 'record_in_use' && $kind === 'events') {
            $suffix = 'error_has_bookings';
        }
        return $suffix !== null ? __('admin.' . $kind . '.' . $suffix) : __('agent.errors.' . $exception->problemCode);
    }
}
