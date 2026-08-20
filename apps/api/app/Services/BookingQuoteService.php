<?php

namespace App\Services;

use App\Enums\BookingQuoteStatus;
use App\Models\BookingQuote;
use App\Models\CancellationPolicy;
use App\Models\DepositPolicy;
use App\Models\Program;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
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
    public function __construct(
        private readonly AvailabilityQuery $availability,
        private readonly CommercialPromotionService $promotions,
    ) {}

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

    /** @param array<string, mixed> $input */
    public function createAmendment(Reservation $reservation, array $input): BookingQuote
    {
        return $this->create([
            ...$input,
            'property_id' => $reservation->property_id,
            'amendment_of_reservation_id' => $reservation->id,
            'voucher_id' => $reservation->voucherRedemptions()->value('voucher_id'),
        ]);
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
        $infants = (int) ($input['infants'] ?? 0);
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
        if ($adults < 1 || $children < 0 || $infants < 0 || $occupancy > 1000) {
            throw ValidationException::withMessages(['adults' => 'Guest quantities must be positive and within supported capacity.']);
        }

        $property = Property::query()->whereKey($propertyId)->where('is_active', true)->firstOrFail();
        $timezone = $property->timezone ?: 'UTC';
        $arrivalDate = $starts->setTimezone($timezone)->startOfDay();
        $departureDate = $ends->setTimezone($timezone)->startOfDay();

        $category = ResourceCategory::query()->whereKey($categoryId)->where('property_id', $propertyId)
            ->where('counts_as_stay', true)->where('is_active', true)->firstOrFail();
        $resource = empty($input['resource_id']) ? null : Resource::query()
            ->whereKey($input['resource_id'])->where('property_id', $propertyId)
            ->where('category_id', $category->id)->where('is_active', true)->firstOrFail();
        if ($resource !== null && $resource->capacity < $occupancy) {
            throw ValidationException::withMessages(['resource_id' => 'The selected accommodation does not fit the requested occupancy.']);
        }
        $amendmentReservationId = (string) ($input['amendment_of_reservation_id'] ?? '');
        if ($amendmentReservationId !== '' && ! Reservation::query()
            ->whereKey($amendmentReservationId)->where('property_id', $propertyId)->exists()) {
            throw ValidationException::withMessages(['reservation_id' => 'The amendment reservation is outside this property.']);
        }
        $availability = $this->availability->forStay(
            $propertyId,
            $starts,
            $ends,
            $occupancy,
            $category->id,
            $amendmentReservationId ?: null,
        );
        if ($resource !== null && ! collect($availability['resources'])->firstWhere('id', $resource->id)['available']) {
            throw ValidationException::withMessages(['resource_id' => 'The selected accommodation is no longer available.']);
        }
        if ($resource === null && ! collect($availability['categories'])->firstWhere('id', $category->id)['available']) {
            throw ValidationException::withMessages(['resource_category_id' => 'This accommodation category is no longer available.']);
        }

        $plan = RatePlan::query()->with(['rules', 'services.catalogItem', 'depositPolicy', 'cancellationPolicy.tiers'])
            ->whereKey($ratePlanId)->where('property_id', $propertyId)->where('is_active', true)
            ->where('state', 'published')->firstOrFail();
        if (($plan->active_from !== null && $plan->active_from->toDateString() > $arrivalDate->toDateString())
            || ($plan->active_until !== null && $plan->active_until->toDateString() < $departureDate->subDay()->toDateString())) {
            throw ValidationException::withMessages(['rate_plan_id' => 'The rate plan is not active for the complete stay.']);
        }
        if ($occupancy < $plan->minimum_occupancy || ($plan->maximum_occupancy !== null && $occupancy > $plan->maximum_occupancy)) {
            throw ValidationException::withMessages(['adults' => 'The requested occupancy is outside this rate plan.']);
        }

        $nightCount = (int) $arrivalDate->diffInDays($departureDate);
        if ($nightCount < 1) {
            throw ValidationException::withMessages(['ends_at' => 'A stay must include at least one night.']);
        }
        $lines = [];
        for ($night = 0; $night < $nightCount; $night++) {
            $serviceOn = $arrivalDate->addDays($night);
            $rule = $this->ruleFor(
                $plan, $category->id, $programId = ($input['program_id'] ?? null), $serviceOn,
                $arrivalDate, $nightCount, $occupancy, (bool) ($input['is_buyout'] ?? false),
                $night === 0, false, $timezone,
            );
            [$quantity, $unitAmount, $amount, $basis, $priceExplanation] = $this->nightlyPrice($rule, $adults, $children, $infants, $nightCount);
            $lines[] = [
                'type' => 'nightly_rate',
                'description' => $category->name.' · '.$serviceOn->format('M j, Y'),
                'service_on' => $serviceOn->toDateString(),
                'basis' => $basis,
                'calculation_order' => 100 + $night,
                'quantity_thousandths' => $quantity * 1000,
                'unit_amount_minor' => $unitAmount,
                'pre_total_minor' => (int) collect($lines)->sum('gross_amount_minor'),
                'net_amount_minor' => $amount,
                'tax_amount_minor' => 0,
                'gross_amount_minor' => $amount,
                'post_total_minor' => (int) collect($lines)->sum('gross_amount_minor') + $amount,
                'rounding_mode' => 'half_up',
                'explanation' => $priceExplanation,
                'metadata' => [
                    'rate_plan_id' => $plan->id, 'rate_plan_version' => $plan->version,
                    'rate_rule_id' => $rule->id, 'rate_rule_version' => $rule->version,
                    'price_type' => $rule->price_type, 'property_timezone' => $timezone,
                    'business_date' => $serviceOn->toDateString(),
                ],
            ];
        }

        // Departure restrictions govern the property's local departure business date,
        // not the final charged night. The selected top-priority rule may not fall back.
        $this->ruleFor(
            $plan, $category->id, $programId, $departureDate, $arrivalDate,
            $nightCount, $occupancy, (bool) ($input['is_buyout'] ?? false), false, true, $timezone,
        );

        $program = empty($input['program_id']) ? null : Program::query()
            ->whereKey($input['program_id'])->where('property_id', $propertyId)->where('is_active', true)->firstOrFail();
        if ($program !== null && $program->price_minor > 0) {
            if ($program->currency !== $plan->currency) {
                throw ValidationException::withMessages(['program_id' => 'The selected program currency does not match the rate plan.']);
            }
            $lines[] = [
                'type' => 'service',
                'description' => $program->name,
                'service_on' => $arrivalDate->toDateString(),
                'basis' => 'per_program',
                'calculation_order' => 200,
                'quantity_thousandths' => 1000,
                'unit_amount_minor' => $program->price_minor,
                'pre_total_minor' => (int) collect($lines)->sum('gross_amount_minor'),
                'net_amount_minor' => $program->price_minor,
                'tax_amount_minor' => 0,
                'gross_amount_minor' => $program->price_minor,
                'post_total_minor' => (int) collect($lines)->sum('gross_amount_minor') + $program->price_minor,
                'rounding_mode' => 'none',
                'explanation' => 'Program price frozen from the selected program.',
                'metadata' => ['program_id' => $program->id, 'program_updated_at' => $program->updated_at?->toIso8601String()],
            ];
        }

        $selectedServices = collect((array) ($input['optional_services'] ?? []))->keyBy('id');
        foreach ($plan->services->where('is_active', true) as $service) {
            if (! $service->catalogItem->is_active || $service->catalogItem->currency !== $plan->currency) {
                throw ValidationException::withMessages(['optional_services' => 'A configured service is unavailable or uses a different currency.']);
            }
            $selected = $selectedServices->get($service->catalog_item_id);
            if ($service->selection_type === 'optional' && $selected === null) {
                continue;
            }
            $requestedQuantity = (int) ($selected['quantity'] ?? $service->default_quantity);
            if ($requestedQuantity < 1 || $requestedQuantity > $service->maximum_quantity) {
                throw ValidationException::withMessages(['optional_services' => 'A selected service quantity is outside its configured limit.']);
            }
            $multiplier = match ($service->quantity_basis) {
                'per_night' => $nightCount,
                'per_person' => $occupancy,
                'per_person_night' => $this->checkedMultiply($occupancy, $nightCount, 'optional_services'),
                default => 1,
            };
            $quantity = $this->checkedMultiply($requestedQuantity, $multiplier, 'optional_services');
            $unit = (int) ($service->amount_minor ?? $service->catalogItem->price_minor);
            $amount = $service->selection_type === 'included' ? 0 : $this->checkedMultiply($unit, $quantity, 'optional_services');
            $running = (int) collect($lines)->sum('gross_amount_minor');
            $lines[] = [
                'type' => $service->selection_type === 'included' ? 'included_service' : 'optional_service',
                'description' => $service->catalogItem->name.($service->selection_type === 'included' ? ' · included' : ''),
                'service_on' => null, 'basis' => $service->quantity_basis, 'calculation_order' => 220 + $service->priority,
                'quantity_thousandths' => $quantity * 1000, 'unit_amount_minor' => $service->selection_type === 'included' ? 0 : $unit,
                'pre_total_minor' => $running, 'net_amount_minor' => $amount, 'tax_amount_minor' => 0,
                'gross_amount_minor' => $amount, 'post_total_minor' => $running + $amount, 'rounding_mode' => 'none',
                'explanation' => $service->selection_type === 'included'
                    ? 'Included in the selected rate plan; shown separately with no additional charge.'
                    : "Optional service priced {$service->quantity_basis}.",
                'metadata' => [
                    'rate_plan_service_id' => $service->id, 'service_version' => $service->version,
                    'catalog_item_id' => $service->catalog_item_id, 'selection_type' => $service->selection_type,
                ],
            ];
        }

        $baseTotal = (int) collect($lines)->sum('gross_amount_minor');
        $promotionInput = $input + ['night_count' => $nightCount];
        $promotionResult = $this->promotions->calculate(
            $this->promotions->eligible($promotionInput, $plan->currency, $arrivalDate),
            $baseTotal,
        );
        $taxableLines = $lines;
        $lines = [...$lines, ...$promotionResult['lines']];
        $discountTotal = $promotionResult['discount_minor'];
        $taxableBase = $baseTotal - $discountTotal;
        $taxTotal = 0;
        $exclusiveTax = 0;
        $taxRules = TaxRule::query()->where('property_id', $propertyId)->where('is_active', true)->where('state', 'published')
            ->where(fn ($query) => $query->whereNull('currency')->orWhere('currency', strtoupper($plan->currency)))
            ->where(fn ($query) => $query->whereNull('active_from')->orWhere('active_from', '<=', $arrivalDate->toDateString()))
            ->where(fn ($query) => $query->whereNull('active_until')->orWhere('active_until', '>=', $departureDate->subDay()->toDateString()))
            ->orderByDesc('priority')->get();
        foreach ($taxRules as $taxRule) {
            $ruleTaxableBase = $taxRule->taxable_discount_allocation === 'after_tax' ? $baseTotal : $taxableBase;
            $tax = $this->taxForRule($taxRule, $taxableLines, $ruleTaxableBase, $baseTotal - $ruleTaxableBase);
            $taxTotal = $this->checkedAdd([$taxTotal, $tax], 'tax');
            if (! $taxRule->is_inclusive) {
                $exclusiveTax = $this->checkedAdd([$exclusiveTax, $tax], 'tax');
            }
            $lines[] = [
                'type' => 'tax',
                'description' => $taxRule->name.($taxRule->is_inclusive ? ' · included' : ''),
                'service_on' => null,
                'basis' => $taxRule->taxable_discount_allocation === 'after_tax' ? 'pre_discount_total' : 'discounted_total',
                'calculation_order' => 400 + $taxRule->priority,
                'quantity_thousandths' => 1000,
                'unit_amount_minor' => $taxRule->is_inclusive ? -$tax : 0,
                'pre_total_minor' => $taxableBase + $exclusiveTax,
                'net_amount_minor' => $taxRule->is_inclusive ? -$tax : 0,
                'tax_amount_minor' => $tax,
                'gross_amount_minor' => $taxRule->is_inclusive ? 0 : $tax,
                'post_total_minor' => $taxableBase + $exclusiveTax,
                'rounding_mode' => $taxRule->rounding_mode,
                'explanation' => ($taxRule->is_inclusive ? 'Tax extracted from' : 'Tax added to').' the '.
                    ($taxRule->taxable_discount_allocation === 'after_tax' ? 'pre-discount' : 'post-discount').
                    " taxable amount using {$taxRule->rounding_scope} rounding.",
                'metadata' => [
                    'tax_rule_id' => $taxRule->id, 'tax_rule_version' => $taxRule->version,
                    'currency' => $taxRule->currency,
                    'inclusive' => $taxRule->is_inclusive, 'rounding_scope' => $taxRule->rounding_scope,
                    'taxable_discount_allocation' => $taxRule->taxable_discount_allocation,
                    'jurisdiction_inputs' => $taxRule->jurisdiction_inputs,
                ],
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
            'infants' => $infants,
            'optional_services' => $selectedServices->values()->all(),
            'voucher_id' => $promotionResult['voucher_id'],
            'promotion_session_hash' => $promotionResult['session_hash'],
            'amendment_of_reservation_id' => $amendmentReservationId ?: null,
        ];
        $calculation = [
            ...$inputs,
            'currency' => strtoupper($plan->currency),
            'subtotal_minor' => $baseTotal - $discountTotal - ($taxTotal - $exclusiveTax),
            'discount_minor' => $discountTotal,
            'tax_minor' => $taxTotal,
            'total_minor' => $baseTotal - $discountTotal + $exclusiveTax,
            'inputs' => $inputs,
            'deposit_policy_snapshot' => $depositPolicy?->snapshot(),
            'cancellation_policy_snapshot' => $cancellationPolicy?->snapshot(),
            'calculation_snapshot' => [
                'calculation_order' => ['eligibility_restrictions', 'base_occupancy_program', 'included_optional_services', 'promotions', 'fees_taxes', 'deposit'],
                'rate_plan' => ['id' => $plan->id, 'version' => $plan->version, 'state' => $plan->state],
                'promotion_versions' => $promotionResult['promotion_snapshot'],
                'voucher_id' => $promotionResult['voucher_id'],
                'promotion_session_hash' => $promotionResult['session_hash'],
                'business_dates' => ['arrival' => $arrivalDate->toDateString(), 'departure' => $departureDate->toDateString(), 'timezone' => $timezone],
                'fiscal_boundary' => 'pricing_inputs_only_non_fiscal',
            ],
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
            'infants' => $quote->infants,
            'subtotal_minor' => $quote->subtotal_minor,
            'discount_minor' => $quote->discount_minor,
            'tax_minor' => $quote->tax_minor,
            'total_minor' => $quote->total_minor,
            'inputs' => $quote->inputs,
            'deposit_policy_snapshot' => $quote->deposit_policy_snapshot,
            'cancellation_policy_snapshot' => $quote->cancellation_policy_snapshot,
            'calculation_snapshot' => $quote->calculation_snapshot,
            'lines' => $quote->lines->sortBy(fn ($line): string => sprintf('%010d:%s', $line->calculation_order, $line->id))->values()->map(fn ($line): array => [
                'type' => $line->type,
                'description' => $line->description,
                'service_on' => $line->service_on?->toDateString(),
                'basis' => $line->basis,
                'calculation_order' => $line->calculation_order,
                'quantity_thousandths' => $line->quantity_thousandths,
                'unit_amount_minor' => $line->unit_amount_minor,
                'pre_total_minor' => $line->pre_total_minor,
                'net_amount_minor' => $line->net_amount_minor,
                'tax_amount_minor' => $line->tax_amount_minor,
                'gross_amount_minor' => $line->gross_amount_minor,
                'post_total_minor' => $line->post_total_minor,
                'rounding_mode' => $line->rounding_mode,
                'explanation' => $line->explanation,
                'metadata' => $line->metadata,
            ])->all(),
        ]);
    }

    private function ruleFor(
        RatePlan $plan,
        string $categoryId,
        ?string $programId,
        CarbonImmutable $date,
        CarbonImmutable $arrivalDate,
        int $nights,
        int $occupancy,
        bool $buyout,
        bool $arrival,
        bool $departure,
        string $timezone,
    ): RateRule {
        $advanceDays = (int) CarbonImmutable::now($timezone)->startOfDay()->diffInDays($arrivalDate, false);
        $rule = $plan->rules->first(function (RateRule $rule) use ($categoryId, $programId, $date, $buyout): bool {
            $weekdays = $rule->weekdays ?? [];

            return ($rule->resource_category_id === null || $rule->resource_category_id === $categoryId)
                && ($rule->program_id === null || $rule->program_id === $programId)
                && ($rule->starts_on === null || $rule->starts_on->toDateString() <= $date->toDateString())
                && ($rule->ends_on === null || $rule->ends_on->toDateString() >= $date->toDateString())
                && ($weekdays === [] || in_array($date->dayOfWeekIso, array_map('intval', $weekdays), true))
                && (! $rule->buyout_only || $buyout);
        });
        if ($rule === null) {
            throw ValidationException::withMessages(['rate_plan_id' => 'No sellable rate is available for every requested night.']);
        }

        $arrivalDays = array_map('intval', $rule->allowed_arrival_days ?? []);
        $blocked = $rule->blackout || $rule->stop_sell
            || $nights < $rule->minimum_stay
            || ($rule->maximum_stay !== null && $nights > $rule->maximum_stay)
            || ($rule->minimum_advance_days !== null && $advanceDays < $rule->minimum_advance_days)
            || ($rule->maximum_advance_days !== null && $advanceDays > $rule->maximum_advance_days)
            || ($rule->minimum_occupancy !== null && $occupancy < $rule->minimum_occupancy)
            || ($rule->maximum_occupancy !== null && $occupancy > $rule->maximum_occupancy)
            || ($arrival && $arrivalDays !== [] && ! in_array($arrivalDate->dayOfWeekIso, $arrivalDays, true))
            || ($arrival && $rule->closed_to_arrival)
            || ($departure && $rule->closed_to_departure);
        if ($blocked) {
            throw ValidationException::withMessages(['rate_plan_id' => 'The governing rate rule does not permit this stay.']);
        }

        return $rule;
    }

    /** @return array{int,int,int,string,string} */
    private function nightlyPrice(RateRule $rule, int $adults, int $children, int $infants, int $nights): array
    {
        if ($rule->adult_amount_minor !== null || $rule->child_amount_minor !== null || $rule->infant_amount_minor !== null) {
            $adultUnit = (int) ($rule->adult_amount_minor ?? $rule->amount_minor);
            $amount = $this->checkedAdd([
                $this->checkedMultiply($adultUnit, $adults, 'adults'),
                $this->checkedMultiply((int) ($rule->child_amount_minor ?? $adultUnit), $children, 'children'),
                $this->checkedMultiply((int) ($rule->infant_amount_minor ?? 0), $infants, 'infants'),
                $adults === 1 ? (int) $rule->single_supplement_minor : 0,
            ], 'guest pricing');
            $quantity = $adults + $children + $infants;
            $unit = $quantity > 0 ? intdiv($amount, $quantity) : 0;
            $basis = 'guest_categories';
            $explanation = "Adults {$adults}, children {$children}, infants {$infants}".($adults === 1 && $rule->single_supplement_minor > 0 ? ', including single supplement' : '').'.';
        } else {
            $quantity = $rule->price_type === 'per_person' ? $adults + $children : 1;
            $unit = (int) $rule->amount_minor;
            $amount = $this->checkedMultiply($unit, $quantity, 'guest pricing');
            $basis = $rule->price_type === 'per_person' ? 'per_person_night' : 'per_night';
            $explanation = $rule->price_type === 'per_person' ? 'Nightly rate multiplied by chargeable guests.' : 'One accommodation-night.';
        }

        $tier = collect($rule->group_tiers ?? [])->sortByDesc('minimum_guests')
            ->first(fn (array $candidate): bool => ($adults + $children) >= (int) ($candidate['minimum_guests'] ?? PHP_INT_MAX));
        if (is_array($tier)) {
            $adjustment = (int) ($tier['adjustment_basis_points'] ?? 0);
            $amount = $this->checkedAdd([$amount, $this->basisPointAdjustment($amount, $adjustment, 'group tier')], 'group tier');
            $explanation .= " Group tier {$adjustment} basis-point adjustment.";
        }
        if ($rule->length_of_stay_adjustment_basis_points !== 0) {
            $amount = $this->checkedAdd([$amount, $this->basisPointAdjustment($amount, (int) $rule->length_of_stay_adjustment_basis_points, 'length_of_stay')], 'length_of_stay');
            $explanation .= " Length-of-stay {$rule->length_of_stay_adjustment_basis_points} basis-point adjustment for {$nights} nights.";
        }
        if ($amount < 0 || $amount > PHP_INT_MAX) {
            throw ValidationException::withMessages(['rate_plan_id' => 'The calculated nightly amount is outside supported limits.']);
        }

        return [$quantity, $unit, $amount, $basis, $explanation];
    }

    private function roundRatio(int $numerator, int $denominator, string $mode): int
    {
        if ($denominator <= 0) {
            throw new \LogicException('A positive rounding denominator is required.');
        }
        $sign = $numerator < 0 ? -1 : 1;
        $absolute = abs($numerator);

        return $sign * match ($mode) {
            'down' => intdiv($absolute, $denominator),
            'up' => intdiv($absolute + $denominator - 1, $denominator),
            'half_even' => (int) round($absolute / $denominator, 0, PHP_ROUND_HALF_EVEN),
            default => intdiv($absolute + intdiv($denominator, 2), $denominator),
        };
    }

    /** @param list<array<string, mixed>> $taxableLines */
    private function taxForRule(TaxRule $rule, array $taxableLines, int $taxableBase, int $discountToAllocate): int
    {
        if ($rule->calculation_type === 'fixed') {
            return (int) $rule->fixed_amount_minor;
        }

        $percentage = (int) $rule->percentage_basis_points;
        $denominator = $rule->is_inclusive ? 10000 + $percentage : 10000;
        if ($rule->rounding_scope !== 'line') {
            return $this->roundRatio(
                $this->checkedMultiply($taxableBase, $percentage, 'tax'),
                $denominator,
                $rule->rounding_mode,
            );
        }

        $positiveLines = array_values(array_filter(
            $taxableLines,
            fn (array $line): bool => (int) ($line['gross_amount_minor'] ?? 0) > 0,
        ));
        if ($positiveLines === [] || $taxableBase === 0) {
            return 0;
        }

        $grossTotal = array_sum(array_map(fn (array $line): int => (int) $line['gross_amount_minor'], $positiveLines));
        $remainingDiscount = $discountToAllocate;
        $taxes = [];
        foreach ($positiveLines as $index => $line) {
            $gross = (int) $line['gross_amount_minor'];
            $allocatedDiscount = $index === array_key_last($positiveLines)
                ? $remainingDiscount
                : intdiv($this->checkedMultiply($discountToAllocate, $gross, 'tax'), $grossTotal);
            $remainingDiscount -= $allocatedDiscount;
            $lineBase = $gross - $allocatedDiscount;
            $taxes[] = $this->roundRatio(
                $this->checkedMultiply($lineBase, $percentage, 'tax'),
                $denominator,
                $rule->rounding_mode,
            );
        }

        return $this->checkedAdd($taxes, 'tax');
    }

    private function checkedMultiply(int $left, int $right, string $field): int
    {
        if ($left < 0 || $right < 0 || ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left))) {
            throw ValidationException::withMessages([$field => 'The calculated amount is outside supported integer-money limits.']);
        }

        return $left * $right;
    }

    private function basisPointAdjustment(int $amount, int $basisPoints, string $field): int
    {
        $absolute = abs($basisPoints);
        if ($amount < 0 || ($amount !== 0 && $absolute > intdiv(PHP_INT_MAX, $amount))) {
            throw ValidationException::withMessages([$field => 'The calculated adjustment is outside supported integer-money limits.']);
        }

        return $this->roundRatio($amount * $basisPoints, 10000, 'half_up');
    }

    /** @param list<int> $values */
    private function checkedAdd(array $values, string $field): int
    {
        $total = 0;
        foreach ($values as $value) {
            if ($value > 0 && $total > PHP_INT_MAX - $value) {
                throw ValidationException::withMessages([$field => 'The calculated amount is outside supported integer-money limits.']);
            }
            $total += $value;
        }

        return $total;
    }

    /** @param array<string, mixed> $calculation */
    private function checksum(array $calculation): string
    {
        $payload = Arr::only($calculation, [
            'property_id', 'rate_plan_id', 'resource_category_id', 'resource_id', 'program_id', 'infants',
            'currency', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor', 'inputs',
            'deposit_policy_snapshot', 'cancellation_policy_snapshot', 'calculation_snapshot', 'lines',
        ]);

        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
