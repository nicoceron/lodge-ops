<?php

namespace App\Services;

use App\Enums\BookingQuoteStatus;
use App\Models\BookingQuote;
use App\Models\CancellationPolicy;
use App\Models\DepositPolicy;
use App\Models\Program;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\TaxRule;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BookingQuoteService
{
    public function __construct(private readonly AvailabilityQuery $availability) {}

    /** @param array<string, mixed> $input */
    public function create(array $input): BookingQuote
    {
        $calculation = $this->preview($input);

        return DB::transaction(function () use ($calculation): BookingQuote {
            $quote = BookingQuote::query()->create(Arr::except($calculation, ['lines']));
            $quote->lines()->createMany($calculation['lines']);
            $quote->load('lines');
            BookingQuote::query()->whereKey($quote->id)->update(['checksum' => $this->checksumFor($quote)]);

            return $quote->fresh(['lines', 'ratePlan', 'resourceCategory', 'resource']);
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function preview(array $input): array
    {
        $propertyId = (string) ($input['property_id'] ?? '');
        $categoryId = (string) ($input['resource_category_id'] ?? '');
        $ratePlanId = (string) ($input['rate_plan_id'] ?? '');
        $starts = CarbonImmutable::parse($input['starts_at'] ?? throw ValidationException::withMessages(['starts_at' => 'Arrival is required.']));
        $ends = CarbonImmutable::parse($input['ends_at'] ?? throw ValidationException::withMessages(['ends_at' => 'Departure is required.']));
        $adults = (int) ($input['adults'] ?? 1);
        $children = (int) ($input['children'] ?? 0);
        $occupancy = $adults + $children;
        if ($propertyId !== '' && ! app(TenantContext::class)->canAccessProperty($propertyId)) {
            throw ValidationException::withMessages(['property_id' => 'The selected property is outside your workspace.']);
        }
        if ($propertyId === '' || $categoryId === '' || $ratePlanId === '') {
            throw ValidationException::withMessages(['rate_plan_id' => 'Property, accommodation category, and rate plan are required.']);
        }
        if ($starts >= $ends) {
            throw ValidationException::withMessages(['ends_at' => 'Departure must be after arrival.']);
        }

        $category = ResourceCategory::query()->whereKey($categoryId)->where('property_id', $propertyId)
            ->where('counts_as_stay', true)->where('is_active', true)->firstOrFail();
        $resource = empty($input['resource_id']) ? null : Resource::query()
            ->whereKey($input['resource_id'])->where('property_id', $propertyId)
            ->where('category_id', $category->id)->where('is_active', true)->firstOrFail();
        if ($resource !== null && $resource->capacity < $occupancy) {
            throw ValidationException::withMessages(['resource_id' => 'The selected accommodation does not fit the requested occupancy.']);
        }
        $availability = $this->availability->forStay($propertyId, $starts, $ends, $occupancy, $category->id);
        if ($resource !== null && ! collect($availability['resources'])->firstWhere('id', $resource->id)['available']) {
            throw ValidationException::withMessages(['resource_id' => 'The selected accommodation is no longer available.']);
        }
        if ($resource === null && ! collect($availability['categories'])->firstWhere('id', $category->id)['available']) {
            throw ValidationException::withMessages(['resource_category_id' => 'This accommodation category is no longer available.']);
        }

        $plan = RatePlan::query()->with(['rules', 'depositPolicy', 'cancellationPolicy.tiers'])
            ->whereKey($ratePlanId)->where('property_id', $propertyId)->where('is_active', true)->firstOrFail();
        if ($plan->active_from?->isAfter($starts) || $plan->active_until?->isBefore($ends->subDay())) {
            throw ValidationException::withMessages(['rate_plan_id' => 'The rate plan is not active for the complete stay.']);
        }
        if ($occupancy < $plan->minimum_occupancy || ($plan->maximum_occupancy !== null && $occupancy > $plan->maximum_occupancy)) {
            throw ValidationException::withMessages(['adults' => 'The requested occupancy is outside this rate plan.']);
        }

        $nightCount = (int) $starts->startOfDay()->diffInDays($ends->startOfDay());
        if ($nightCount < 1) {
            throw ValidationException::withMessages(['ends_at' => 'A stay must include at least one night.']);
        }
        $lines = [];
        for ($night = 0; $night < $nightCount; $night++) {
            $serviceOn = $starts->startOfDay()->addDays($night);
            $rule = $this->ruleFor($plan, $category->id, $serviceOn, $nightCount, $night === 0, $night === $nightCount - 1);
            $quantity = $rule->price_type === 'per_person' ? $occupancy : 1;
            $amount = $rule->amount_minor * $quantity;
            $lines[] = [
                'type' => 'nightly_rate',
                'description' => $category->name.' · '.$serviceOn->format('M j, Y'),
                'service_on' => $serviceOn->toDateString(),
                'quantity_thousandths' => $quantity * 1000,
                'unit_amount_minor' => $rule->amount_minor,
                'net_amount_minor' => $amount,
                'tax_amount_minor' => 0,
                'gross_amount_minor' => $amount,
                'metadata' => ['rate_rule_id' => $rule->id, 'price_type' => $rule->price_type],
            ];
        }

        $program = empty($input['program_id']) ? null : Program::query()
            ->whereKey($input['program_id'])->where('property_id', $propertyId)->where('is_active', true)->firstOrFail();
        if ($program !== null && $program->price_minor > 0) {
            if ($program->currency !== $plan->currency) {
                throw ValidationException::withMessages(['program_id' => 'The selected program currency does not match the rate plan.']);
            }
            $lines[] = [
                'type' => 'service',
                'description' => $program->name,
                'service_on' => $starts->toDateString(),
                'quantity_thousandths' => 1000,
                'unit_amount_minor' => $program->price_minor,
                'net_amount_minor' => $program->price_minor,
                'tax_amount_minor' => 0,
                'gross_amount_minor' => $program->price_minor,
                'metadata' => ['program_id' => $program->id],
            ];
        }

        $baseTotal = (int) collect($lines)->sum('gross_amount_minor');
        $taxTotal = 0;
        $exclusiveTax = 0;
        $taxRules = TaxRule::query()->where('property_id', $propertyId)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('active_from')->orWhere('active_from', '<=', $starts->toDateString()))
            ->where(fn ($query) => $query->whereNull('active_until')->orWhere('active_until', '>=', $ends->subDay()->toDateString()))
            ->orderByDesc('priority')->get();
        foreach ($taxRules as $taxRule) {
            $tax = $taxRule->calculation_type === 'fixed'
                ? (int) $taxRule->fixed_amount_minor
                : ($taxRule->is_inclusive
                    ? intdiv(($baseTotal * (int) $taxRule->percentage_basis_points) + (10000 + (int) $taxRule->percentage_basis_points) - 1, 10000 + (int) $taxRule->percentage_basis_points)
                    : intdiv(($baseTotal * (int) $taxRule->percentage_basis_points) + 5000, 10000));
            $taxTotal += $tax;
            if (! $taxRule->is_inclusive) {
                $exclusiveTax += $tax;
            }
            $lines[] = [
                'type' => 'tax',
                'description' => $taxRule->name.($taxRule->is_inclusive ? ' · included' : ''),
                'service_on' => null,
                'quantity_thousandths' => 1000,
                'unit_amount_minor' => $taxRule->is_inclusive ? -$tax : 0,
                'net_amount_minor' => $taxRule->is_inclusive ? -$tax : 0,
                'tax_amount_minor' => $tax,
                'gross_amount_minor' => $taxRule->is_inclusive ? 0 : $tax,
                'metadata' => ['tax_rule_id' => $taxRule->id, 'inclusive' => $taxRule->is_inclusive],
            ];
        }

        $depositPolicy = $plan->depositPolicy;
        if (! $depositPolicy instanceof DepositPolicy) {
            $depositPolicy = DepositPolicy::query()
                ->where('property_id', $propertyId)->where('is_active', true)->where('is_default', true)->first();
        }
        $cancellationPolicy = $plan->cancellationPolicy;
        if (! $cancellationPolicy instanceof CancellationPolicy) {
            $cancellationPolicy = CancellationPolicy::query()
                ->where('property_id', $propertyId)->where('is_active', true)->where('is_default', true)->with('tiers')->first();
        }
        $inputs = [
            'property_id' => $propertyId,
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'resource_id' => $resource?->id,
            'program_id' => $program?->id,
            'starts_at' => $starts->toIso8601String(),
            'ends_at' => $ends->toIso8601String(),
            'adults' => $adults,
            'children' => $children,
        ];
        $calculation = [
            ...$inputs,
            'currency' => strtoupper($plan->currency),
            'subtotal_minor' => $baseTotal - ($taxTotal - $exclusiveTax),
            'tax_minor' => $taxTotal,
            'total_minor' => $baseTotal + $exclusiveTax,
            'inputs' => $inputs,
            'deposit_policy_snapshot' => $depositPolicy?->snapshot(),
            'cancellation_policy_snapshot' => $cancellationPolicy?->snapshot(),
            'status' => BookingQuoteStatus::Pending,
            'expires_at' => now()->addMinutes((int) config('reservations.quote_ttl_minutes', 20)),
            'lines' => $lines,
        ];
        $calculation['checksum'] = $this->checksum($calculation);

        return $calculation;
    }

    public function checksumFor(BookingQuote $quote): string
    {
        $quote->loadMissing('lines');

        return $this->checksum([
            'property_id' => $quote->property_id,
            'rate_plan_id' => $quote->rate_plan_id,
            'resource_category_id' => $quote->resource_category_id,
            'resource_id' => $quote->resource_id,
            'program_id' => $quote->program_id,
            'currency' => $quote->currency,
            'subtotal_minor' => $quote->subtotal_minor,
            'tax_minor' => $quote->tax_minor,
            'total_minor' => $quote->total_minor,
            'inputs' => $quote->inputs,
            'deposit_policy_snapshot' => $quote->deposit_policy_snapshot,
            'cancellation_policy_snapshot' => $quote->cancellation_policy_snapshot,
            'lines' => $quote->lines->map(fn ($line): array => [
                'type' => $line->type,
                'description' => $line->description,
                'service_on' => $line->service_on?->toDateString(),
                'quantity_thousandths' => $line->quantity_thousandths,
                'unit_amount_minor' => $line->unit_amount_minor,
                'net_amount_minor' => $line->net_amount_minor,
                'tax_amount_minor' => $line->tax_amount_minor,
                'gross_amount_minor' => $line->gross_amount_minor,
                'metadata' => $line->metadata,
            ])->all(),
        ]);
    }

    private function ruleFor(RatePlan $plan, string $categoryId, CarbonImmutable $date, int $nights, bool $arrival, bool $departure): RateRule
    {
        $rule = $plan->rules->first(function (RateRule $rule) use ($categoryId, $date, $nights, $arrival, $departure): bool {
            $weekdays = $rule->weekdays ?? [];

            return ($rule->resource_category_id === null || $rule->resource_category_id === $categoryId)
                && ($rule->starts_on === null || ! $rule->starts_on->isAfter($date))
                && ($rule->ends_on === null || ! $rule->ends_on->isBefore($date))
                && ($weekdays === [] || in_array($date->dayOfWeekIso, array_map('intval', $weekdays), true))
                && $nights >= $rule->minimum_stay
                && ($rule->maximum_stay === null || $nights <= $rule->maximum_stay)
                && ! ($arrival && $rule->closed_to_arrival)
                && ! ($departure && $rule->closed_to_departure)
                && ! $rule->stop_sell;
        });
        if ($rule === null) {
            throw ValidationException::withMessages(['rate_plan_id' => 'No sellable rate is available for every requested night.']);
        }

        return $rule;
    }

    /** @param array<string, mixed> $calculation */
    private function checksum(array $calculation): string
    {
        $payload = Arr::only($calculation, [
            'property_id', 'rate_plan_id', 'resource_category_id', 'resource_id', 'program_id',
            'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'inputs',
            'deposit_policy_snapshot', 'cancellation_policy_snapshot', 'lines',
        ]);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
