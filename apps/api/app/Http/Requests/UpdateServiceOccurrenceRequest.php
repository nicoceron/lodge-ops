<?php

namespace App\Http\Requests;

class UpdateServiceOccurrenceRequest extends StoreServiceOccurrenceRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (array_keys($rules) as $field) {
            if ($field !== 'meeting_point') {
                $rules[$field][0] = 'sometimes';
            }
        }

        return $rules;
    }
}
