<?php

namespace App\Http\Requests;

class UpdateTaskRequest extends StoreTaskRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['property_id', 'title'] as $field) {
            $rules[$field][0] = 'sometimes';
        }

        return $rules;
    }
}
