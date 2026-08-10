<?php

namespace App\Services\Automation;

class AutomationConditionMatcher
{
    /**
     * @param  array<string, mixed>|null  $conditions
     * @param  array<string, mixed>  $context
     */
    public function matches(?array $conditions, array $context): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $field => $expected) {
            if (data_get($context, (string) $field) !== $expected) {
                return false;
            }
        }

        return true;
    }
}
