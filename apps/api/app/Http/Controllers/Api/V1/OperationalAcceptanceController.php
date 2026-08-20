<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\TaskResource;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateVersion;
use App\Models\Guest;
use App\Models\OperationalTask;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationChecklistException;
use App\Services\ChecklistWorkflowService;
use App\Services\OperationalKpiService;
use App\Services\ReservationCompanionService;
use App\Services\TaskLifecycleService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationalAcceptanceController extends Controller
{
    public function duplicateGuests(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Guest::class);
        $data = $request->validate(['email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:40'], 'name' => ['nullable', 'string', 'max:200']]);
        abort_if(blank($data['email'] ?? null) && blank($data['phone'] ?? null) && blank($data['name'] ?? null), 422, 'Provide an email, phone, or name.');
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? ''));
        $name = mb_strtolower(trim((string) ($data['name'] ?? '')));
        $matches = Guest::query()->whereNull('merged_into_id')->where(function ($query) use ($email, $phone, $name): void {
            if ($email !== '') {
                $query->orWhereRaw('lower(email) = ?', [$email]);
            }
            if ($phone !== '') {
                $query->orWhere('phone', 'like', '%'.substr($phone, -8));
            }
            if ($name !== '') {
                $query->orWhereRaw("lower(trim(first_name || ' ' || coalesce(last_name, ''))) = ?", [$name]);
            }
        })->withCount(['reservations', 'companionReservations'])->limit(10)->get();

        return response()->json(['data' => $matches->map(fn (Guest $guest): array => [
            'id' => $guest->id, 'name' => trim("{$guest->first_name} {$guest->last_name}"),
            'email_hint' => $guest->email ? preg_replace('/(^.).*(@.*$)/', '$1***$2', $guest->email) : null,
            'phone_hint' => $guest->phone ? '***'.substr(preg_replace('/\D+/', '', $guest->phone), -4) : null,
            'stay_count' => $guest->reservations_count + $guest->companion_reservations_count,
        ])]);
    }

    public function companions(Request $request, Reservation $reservation, ReservationCompanionService $service): ReservationResource
    {
        $this->authorize('update', $reservation);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'companions' => ['present', 'array', 'max:50'],
            'companions.*.guest_id' => ['required', 'uuid'],
            'companions.*.dietary' => ['nullable', 'string', 'max:500'],
            'companions.*.allergies' => ['nullable', 'string', 'max:500'],
            'companions.*.meal_notes' => ['nullable', 'string', 'max:500'],
        ]);

        return new ReservationResource($service->replace($reservation, $data['companions'], $data['expected_revision'], $request->user()?->id));
    }

    public function storeChecklistTemplate(Request $request): JsonResponse
    {
        abort_unless(app(TenantContext::class)->membership()?->role?->canManageConfiguration(), 403);
        $data = $request->validate([
            'property_id' => ['required', 'uuid'], 'program_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'role' => ['required', Rule::in(['operations', 'guide', 'kitchen', 'housekeeping'])],
        ]);
        abort_unless(app(TenantContext::class)->canAccessProperty($data['property_id']), 403);

        return response()->json(['data' => ChecklistTemplate::query()->create($data)], 201);
    }

    public function publishChecklist(Request $request, ChecklistTemplate $checklistTemplate, ChecklistWorkflowService $service): JsonResponse
    {
        abort_unless(app(TenantContext::class)->membership()?->role?->canManageConfiguration(), 403);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.title' => ['required', 'string', 'max:200'], 'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'items.*.due_offset_minutes' => ['nullable', 'integer', 'between:-525600,525600'],
        ]);

        return response()->json(['data' => $service->publish($checklistTemplate, $data['items'], $request->user()?->id)], 201);
    }

    public function retireChecklist(ChecklistTemplate $checklistTemplate, ChecklistWorkflowService $service): JsonResponse
    {
        abort_unless(app(TenantContext::class)->membership()?->role?->canManageConfiguration(), 403);
        $service->retire($checklistTemplate);

        return response()->json(['data' => ['state' => 'retired']]);
    }

    public function storeChecklistException(Request $request, Reservation $reservation, ChecklistWorkflowService $service): JsonResponse
    {
        $this->authorize('update', $reservation);
        $data = $request->validate([
            'checklist_template_item_id' => ['nullable', 'uuid'],
            'operation' => ['required', Rule::in(['add', 'edit', 'remove', 'reorder'])],
            'title' => ['nullable', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_offset_minutes' => ['nullable', 'integer', 'between:-525600,525600'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
        $exception = $service->saveException($reservation, $data, $request->user()?->id);

        return response()->json(['data' => $exception], 201);
    }

    public function updateChecklistException(Request $request, Reservation $reservation, ReservationChecklistException $exception, ChecklistWorkflowService $service): JsonResponse
    {
        $this->authorize('update', $reservation);
        $data = $request->validate([
            'checklist_template_item_id' => ['nullable', 'uuid'],
            'operation' => ['required', Rule::in(['add', 'edit', 'remove', 'reorder'])],
            'title' => ['nullable', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_offset_minutes' => ['nullable', 'integer', 'between:-525600,525600'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        return response()->json(['data' => $service->saveException($reservation, $data, $request->user()?->id, $exception)]);
    }

    public function destroyChecklistException(Reservation $reservation, ReservationChecklistException $exception, ChecklistWorkflowService $service): JsonResponse
    {
        $this->authorize('update', $reservation);
        $service->deleteException($reservation, $exception);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function generateChecklist(Request $request, Reservation $reservation, ChecklistWorkflowService $service): JsonResponse
    {
        $this->authorize('update', $reservation);
        $data = $request->validate(['version_id' => ['required', 'uuid']]);
        $version = ChecklistTemplateVersion::query()->findOrFail($data['version_id']);

        return response()->json(['data' => $service->generate($reservation, $version, $request->user()?->id)]);
    }

    public function taskTransition(Request $request, OperationalTask $task, TaskLifecycleService $service): TaskResource
    {
        $this->authorize('update', $task);
        $data = $request->validate([
            'action' => ['required', Rule::in(['assign', 'start', 'complete', 'fail', 'reopen', 'escalate', 'cancel'])],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'assignee_id' => ['required_if:action,assign', 'nullable', 'integer'],
            'reason' => ['required_if:action,fail,escalate,cancel', 'nullable', 'string', 'max:2000'],
            'reservation_reopen_authorized' => ['sometimes', 'boolean'],
        ]);

        return new TaskResource($service->transition($task, $data['action'], $data, $request->user()?->id));
    }

    public function calendarPreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'range_days' => ['sometimes', Rule::in([7, 14, 30, 90])],
            'lens' => ['sometimes', Rule::in(['all', 'stays', 'activities', 'tasks', 'blocks'])],
            'kind' => ['sometimes', Rule::in(['all', 'place', 'asset', 'crew'])],
            'status' => ['sometimes', 'array', 'max:20'], 'status.*' => ['string', 'max:32'],
            'attention' => ['sometimes', Rule::in(['all', 'conflicted', 'unassigned'])],
        ]);
        $membership = app(TenantContext::class)->membership();
        abort_unless($membership !== null, 403);
        $membership->update(['calendar_preferences' => $data]);

        return response()->json(['data' => $data]);
    }

    public function kpis(Request $request, OperationalKpiService $service): JsonResponse
    {
        abort_unless(app(TenantContext::class)->membership()?->role?->canViewFinance(), 403);
        $data = $request->validate(['start' => ['required', 'date_format:Y-m-d'], 'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'], 'property_id' => ['nullable', 'uuid']]);
        $context = app(TenantContext::class);
        $propertyId = $context->propertyScopeId() ?? ($data['property_id'] ?? null);
        if ($propertyId !== null) {
            abort_unless($context->canAccessProperty($propertyId), 403);
        }
        $timezone = $propertyId === null
            ? $context->tenant()->timezone
            : (Property::query()->whereKey($propertyId)->value('timezone') ?? $context->tenant()->timezone);

        return response()->json(['data' => $service->reconcile(
            CarbonImmutable::createFromFormat('!Y-m-d', $data['start'], $timezone),
            CarbonImmutable::createFromFormat('!Y-m-d', $data['end'], $timezone),
            $timezone,
            $propertyId,
        )]);
    }
}
