<?php

namespace App\Services;

use App\Models\RatePlan;
use App\Models\RateRule;
use Illuminate\Validation\ValidationException;

final class RatePlanPublicationValidator
{
    public function validate(RatePlan $plan): void
    {
        $rules = $plan->rules()->get();
        if ($rules->isEmpty()) {
            throw ValidationException::withMessages(['rules' => 'Add at least one rate rule before publishing.']);
        }

        foreach ($rules as $rule) {
            if (($rule->maximum_stay !== null && $rule->minimum_stay > $rule->maximum_stay)
                || ($rule->minimum_advance_days !== null && $rule->maximum_advance_days !== null
                    && $rule->minimum_advance_days > $rule->maximum_advance_days)
                || ($rule->minimum_occupancy !== null && $rule->maximum_occupancy !== null
                    && $rule->minimum_occupancy > $rule->maximum_occupancy)) {
                throw ValidationException::withMessages(['rules' => "Rule {$rule->name} has an inverted minimum/maximum boundary."]);
            }
            foreach ([$rule->weekdays ?? [], $rule->allowed_arrival_days ?? []] as $days) {
                if (array_diff(array_map('intval', $days), range(1, 7)) !== []) {
                    throw ValidationException::withMessages(['rules' => "Rule {$rule->name} contains a weekday outside 1–7."]);
                }
            }
        }

        foreach ($rules->values() as $leftIndex => $left) {
            foreach ($rules->values()->slice($leftIndex + 1) as $right) {
                if ($this->ambiguous($left, $right)) {
                    throw ValidationException::withMessages([
                        'rules' => "Rules {$left->name} and {$right->name} overlap at the same priority and applicability. Change priority or scope.",
                    ]);
                }
            }
        }
    }

    private function ambiguous(RateRule $left, RateRule $right): bool
    {
        if ($left->priority !== $right->priority
            || $left->resource_category_id !== $right->resource_category_id
            || $left->program_id !== $right->program_id
            || $left->buyout_only !== $right->buyout_only) {
            return false;
        }

        $datesOverlap = ($left->ends_on === null || $right->starts_on === null || ! $left->ends_on->isBefore($right->starts_on))
            && ($right->ends_on === null || $left->starts_on === null || ! $right->ends_on->isBefore($left->starts_on));
        $weekdaysOverlap = $this->setsOverlap($left->weekdays ?? [], $right->weekdays ?? []);
        $stayOverlap = $this->rangesOverlap($left->minimum_stay, $left->maximum_stay, $right->minimum_stay, $right->maximum_stay);
        $occupancyOverlap = $this->rangesOverlap($left->minimum_occupancy ?? 0, $left->maximum_occupancy, $right->minimum_occupancy ?? 0, $right->maximum_occupancy);
        $advanceOverlap = $this->rangesOverlap($left->minimum_advance_days ?? 0, $left->maximum_advance_days, $right->minimum_advance_days ?? 0, $right->maximum_advance_days);

        return $datesOverlap && $weekdaysOverlap && $stayOverlap && $occupancyOverlap && $advanceOverlap;
    }

    /** @param list<mixed> $left @param list<mixed> $right */
    private function setsOverlap(array $left, array $right): bool
    {
        return $left === [] || $right === [] || array_intersect(array_map('intval', $left), array_map('intval', $right)) !== [];
    }

    private function rangesOverlap(int $leftMin, ?int $leftMax, int $rightMin, ?int $rightMax): bool
    {
        return ($leftMax === null || $leftMax >= $rightMin) && ($rightMax === null || $rightMax >= $leftMin);
    }
}
