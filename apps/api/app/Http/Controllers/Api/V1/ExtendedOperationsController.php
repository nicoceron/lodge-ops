<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\CostRecord;
use App\Models\Guest;
use App\Models\IntegrationConnection;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\RetailSale;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\FinancialReportingService;
use App\Services\GuestMergeService;
use App\Services\IntegrationConnectionService;
use App\Services\OpportunityService;
use App\Services\RetailPostingService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExtendedOperationsController extends Controller
{
    public function catalog(TenantContext $context): JsonResponse
    {
        $role = $context->membership()?->role;
        abort_unless($role?->canManageRetail() || $role?->canManageConfiguration() || $role?->canManageMoney(), 403);

        $showCost = $role->canManageMoney() || $role->canManageConfiguration();
        $data = CatalogItem::query()->orderBy('name')->get()->map(fn (CatalogItem $item): array => array_filter([
            'id' => $item->id,
            'sku' => $item->sku,
            'name' => $item->name,
            'type' => $item->type,
            'currency' => $item->currency,
            'price_minor' => $item->price_minor,
            'cost_minor' => $showCost ? $item->cost_minor : null,
            'track_stock' => $item->track_stock,
            'is_active' => $item->is_active,
        ], fn (mixed $value): bool => $value !== null));

        return response()->json(['data' => $data]);
    }

    public function storeCatalog(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless($context->membership()?->role->canManageConfiguration(), 403);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(['retail', 'extra', 'service'])],
            'currency' => ['required', 'string', 'size:3'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'cost_minor' => ['sometimes', 'integer', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
        ]);

        $item = CatalogItem::query()->create($data + ['cost_minor' => 0, 'track_stock' => false, 'is_active' => true]);

        return response()->json(['data' => $item], 201);
    }

    public function receiveStock(Request $request, TenantContext $context): JsonResponse
    {
        $role = $context->membership()?->role;
        abort_unless($role?->canManageRetail(), 403);
        $data = $request->validate([
            'catalog_item_id' => ['required', 'uuid'],
            'stock_location_id' => ['required', 'uuid'],
            'quantity_milli' => ['required', 'integer', 'min:1'],
            'unit_cost_minor' => ['sometimes', 'integer', 'min:0'],
            'reference' => ['required', 'string', 'max:160'],
        ]);
        $item = CatalogItem::query()->findOrFail($data['catalog_item_id']);
        $location = StockLocation::query()->findOrFail($data['stock_location_id']);
        $quantity = sprintf('%d.%03d', intdiv($data['quantity_milli'], 1000), $data['quantity_milli'] % 1000);
        $movement = StockMovement::query()->firstOrCreate(
            ['reference' => $data['reference']],
            [
                'catalog_item_id' => $item->id,
                'stock_location_id' => $location->id,
                'type' => 'receipt',
                'quantity' => $quantity,
                'unit_cost_minor' => $data['unit_cost_minor'] ?? $item->cost_minor,
                'occurred_at' => now(),
            ],
        );

        return response()->json(['data' => $movement], $movement->wasRecentlyCreated ? 201 : 200);
    }

    public function postSale(Request $request, TenantContext $context, RetailPostingService $service): JsonResponse
    {
        $role = $context->membership()?->role;
        abort_unless($role?->canManageRetail(), 403);
        $data = $request->validate([
            'stock_location_id' => ['required', 'uuid'],
            'reservation_id' => ['nullable', 'uuid'],
            'reference' => ['required', 'string', 'max:160'],
            'tax_minor' => ['sometimes', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_item_id' => ['required', 'uuid'],
            'lines.*.quantity_milli' => ['required', 'integer', 'min:1'],
        ]);
        $location = StockLocation::query()->findOrFail($data['stock_location_id']);
        $reservation = isset($data['reservation_id']) ? Reservation::query()->findOrFail($data['reservation_id']) : null;
        $sale = $service->post($location, $data['reference'], $data['lines'], $reservation, $data['tax_minor'] ?? 0);

        return response()->json(['data' => $this->saleData($sale)], $sale->wasRecentlyCreated ? 201 : 200);
    }

    public function finance(Request $request, TenantContext $context, FinancialReportingService $service): JsonResponse
    {
        abort_unless($context->membership()?->role->canManageMoney(), 403);
        $data = $request->validate([
            'currency' => ['sometimes', 'string', 'size:3'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
        ]);
        $timezone = $context->tenant()->timezone;
        $start = CarbonImmutable::parse($data['starts_at'] ?? 'first day of this month', $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($data['ends_at'] ?? 'first day of next month', $timezone)->startOfDay()->utc();

        return response()->json(['data' => $service->summary(strtoupper($data['currency'] ?? $context->tenant()->currency), $start, $end)]);
    }

    public function storeCost(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless($context->membership()?->role->canManageMoney(), 403);
        $data = $request->validate([
            'reservation_id' => ['nullable', 'uuid'],
            'program_id' => ['nullable', 'uuid'],
            'staff_user_id' => ['nullable', 'integer'],
            'kind' => ['required', Rule::in(['estimated', 'actual'])],
            'category' => ['required', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'amount_minor' => ['required', 'integer', 'min:0'],
            'occurred_at' => ['required', 'date'],
            'metadata' => ['sometimes', 'array'],
        ]);
        if (isset($data['reservation_id'])) {
            Reservation::query()->findOrFail($data['reservation_id']);
        }

        return response()->json(['data' => CostRecord::query()->create($data)], 201);
    }

    public function integrations(TenantContext $context): JsonResponse
    {
        abort_unless($context->membership()?->role->canManageConfiguration(), 403);

        return response()->json(['data' => IntegrationConnection::query()->orderBy('type')->get()->makeHidden('secret_reference')]);
    }

    public function configureIntegration(Request $request, TenantContext $context, IntegrationConnectionService $service): JsonResponse
    {
        abort_unless($context->membership()?->role->canManageConfiguration(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(['email', 'calendar', 'accounting', 'payment', 'signature', 'webhook'])],
            'configuration' => ['sometimes', 'array'],
            'secret_reference' => ['nullable', 'string', 'max:500'],
        ]);
        $connection = $service->configure($data['name'], $data['type'], $data['configuration'] ?? [], $data['secret_reference'] ?? null);

        return response()->json(['data' => $connection->makeHidden('secret_reference')], 200);
    }

    public function organizations(TenantContext $context): JsonResponse
    {
        abort_unless($context->membership()?->role->canManageReservations(), 403);

        return response()->json(['data' => Organization::query()->where('is_active', true)->orderBy('name')->get()]);
    }

    public function storeOrganization(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless($context->membership()?->role->canManageReservations(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(['agency', 'company', 'household'])],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'commission_basis_points' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'metadata' => ['sometimes', 'array'],
        ]);

        return response()->json(['data' => Organization::query()->create($data + ['commission_basis_points' => 0, 'is_active' => true])], 201);
    }

    public function opportunities(TenantContext $context): JsonResponse
    {
        abort_unless($context->membership()?->role->canManageReservations(), 403);
        $items = Opportunity::query()->with(['guest:id,first_name,last_name', 'organization:id,name', 'proposal:id,reference,version,status'])
            ->orderByRaw("case stage when 'inquiry' then 1 when 'qualified' then 2 when 'proposal' then 3 when 'won' then 4 else 5 end")
            ->orderBy('expected_close_on')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function storeOpportunity(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless($context->membership()?->role->canManageReservations(), 403);
        $data = $request->validate([
            'property_id' => ['required', 'uuid'],
            'guest_id' => ['nullable', 'uuid'],
            'organization_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'source' => ['nullable', 'string', 'max:50'],
            'currency' => ['required', 'string', 'size:3'],
            'value_minor' => ['sometimes', 'integer', 'min:0'],
            'expected_close_on' => ['nullable', 'date'],
        ]);
        Property::query()->findOrFail($data['property_id']);
        if (isset($data['guest_id'])) {
            Guest::query()->findOrFail($data['guest_id']);
        }
        if (isset($data['organization_id'])) {
            Organization::query()->findOrFail($data['organization_id']);
        }

        return response()->json(['data' => Opportunity::query()->create($data + [
            'owner_id' => $request->user()->id, 'stage' => 'inquiry', 'value_minor' => 0,
        ])], 201);
    }

    public function transitionOpportunity(Request $request, Opportunity $opportunity, TenantContext $context, OpportunityService $service): JsonResponse
    {
        abort_unless($context->membership()?->role->canManageReservations(), 403);
        $data = $request->validate([
            'stage' => ['required', Rule::in(['qualified', 'proposal', 'won', 'lost'])],
            'lost_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $service->transition($opportunity, $data['stage'], $data['lost_reason'] ?? null)]);
    }

    public function mergeGuest(Request $request, Guest $guest, TenantContext $context, GuestMergeService $service): JsonResponse
    {
        abort_unless($context->membership()?->role->canManageGuests(), 403);
        $data = $request->validate(['target_guest_id' => ['required', 'uuid', Rule::notIn([$guest->id])]]);
        $target = Guest::query()->findOrFail($data['target_guest_id']);

        return response()->json(['data' => $service->merge($guest, $target)]);
    }

    /** @return array<string, mixed> */
    private function saleData(RetailSale $sale): array
    {
        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'status' => $sale->status,
            'currency' => $sale->currency,
            'subtotal_minor' => $sale->subtotal_minor,
            'tax_minor' => $sale->tax_minor,
            'total_minor' => $sale->total_minor,
            'posted_at' => $sale->posted_at?->toISOString(),
            'lines' => $sale->lines->map(fn ($line): array => [
                'catalog_item_id' => $line->catalog_item_id,
                'quantity' => $line->quantity,
                'unit_amount_minor' => $line->unit_amount_minor,
                'amount_minor' => $line->amount_minor,
            ]),
        ];
    }
}
