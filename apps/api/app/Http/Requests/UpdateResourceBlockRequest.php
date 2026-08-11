<?php

namespace App\Http\Requests;

class UpdateResourceBlockRequest extends StoreResourceBlockRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (array_keys($rules) as $field) {
            if ($field !== 'notes') {
                $rules[$field][0] = 'sometimes';
            }
        }

        return $rules;
    }
}
