<?php

namespace App\Http\Requests;

class UpdateTaskRequest extends StoreTaskRequest
{
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array', 'max:100'],
            'status' => ['prohibited'],
            'assignee_id' => ['prohibited'],
            'property_id' => ['prohibited'],
            'reservation_id' => ['prohibited'],
        ];
    }
}
