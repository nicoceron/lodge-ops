<?php

namespace App\Http\Requests;

class UpdateReservationRequest extends StoreReservationRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        foreach (['property_id', 'starts_at', 'ends_at', 'adults', 'currency'] as $field) {
            $rules[$field][0] = 'sometimes';
        }

        unset($rules['allocations'], $rules['allocations.*.requested_category_id'], $rules['allocations.*.resource_id'], $rules['allocations.*.service_occurrence_id'], $rules['allocations.*.starts_at'], $rules['allocations.*.ends_at'], $rules['allocations.*.quantity']);

        return $rules;
    }

    public function after(): array
    {
        return [];
    }
}
